<?php

use App\Enums\CustomerPassCreationFailure;
use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Enums\GoogleWalletSynchronizationFailure;
use App\Exceptions\CustomerPassCreationException;
use App\Exceptions\GoogleWalletSynchronizationException;
use App\Integrations\GoogleWallet\GoogleWalletClient;
use App\Integrations\GoogleWallet\GoogleWalletConfiguration;
use App\Integrations\GoogleWallet\GoogleWalletPassUpdater;
use App\Models\Business;
use App\Support\CustomerPassCredentials;
use Google\Service\Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Support\GoogleWalletHttpFake;

/** Guards native test cleanup before any isolated PostgreSQL reset. */
trait GuardsSharedWalletAppearanceDatabase
{
    use RefreshDatabase;

    /** Refuses configured or actual databases outside the testing runtime. */
    protected function beforeRefreshingDatabase(): void
    {
        if (! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'testing'
            || DB::scalar('SELECT current_database()') !== 'testing') {
            throw new LogicException('Refusing to reset a non-testing database.');
        }
    }
}

uses(GuardsSharedWalletAppearanceDatabase::class);

beforeEach(function () {
    app()->setLocale('es');

    $this->http = new GoogleWalletHttpFake;
    app()->instance(GoogleWalletClient::class, new GoogleWalletClient($this->http->client));

    // generate isolated credentials without reading deployment secrets
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $pem);

    File::ensureDirectoryExists(base_path('tmp/pass-appearance-wallet-sync'), 0700);
    $this->credentialsPath = tempnam(base_path('tmp/pass-appearance-wallet-sync'), 'wallet-update-');
    $account = [
        'type' => 'service_account',
        'client_email' => 'wallet@platform.iam.gserviceaccount.com',
        'private_key' => $pem,
    ];
    file_put_contents($this->credentialsPath, json_encode($account));

    config([
        'app.url' => 'https://passes.example.test',
        'services.google_wallet.issuer_id' => '123456',
        'services.google_wallet.credentials_path' => $this->credentialsPath,
        'services.google_wallet.connect_timeout' => 3,
        'services.google_wallet.timeout' => 10,
    ]);

    // retain one issued pass and its matching remote identity
    $this->business = Business::factory()->create([
        'pass_background_color' => '#123456',
    ]);
    $this->credentials = CustomerPassCredentials::prepare($this->business);
    $this->objectId = '123456.pass_'.$this->credentials->publicPassId;

    $this->pass = $this->business->customerPasses()->make([
        'wallet_object_id' => $this->objectId,
        'manual_code' => $this->credentials->manualCode,
        'validation_token_hash' => hash('sha256', $this->credentials->validationToken),
    ]);
    $this->pass->public_id = $this->credentials->publicPassId;
    $this->pass->save();

    $this->remote = [
        'id' => $this->objectId,
        'classId' => '123456.business_'.$this->business->public_id,
        'accountId' => $this->credentials->manualCode,
        'barcode' => [
            'type' => 'QR_CODE',
            'value' => $this->credentials->validationToken,
        ],
        'textModulesData' => [
            ['id' => 'stale', 'header' => '', 'body' => 'Old content'],
        ],
    ];
    $this->url = 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/'.$this->objectId;
    $this->classId = '123456.business_'.$this->business->public_id;
    $this->classUrl = 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/'.$this->classId;
    $this->remoteClass = [
        'id' => $this->classId,
        'reviewStatus' => 'APPROVED',
        'hexBackgroundColor' => '#000000',
    ];

    // exercise the native SDK against a stateful provider fixture
    $this->http->respondWith([
        'https://oauth2.googleapis.com/token' => GoogleWalletHttpFake::json([
            'access_token' => 'synthetic-access',
            'expires_in' => 3600,
        ]),
        $this->url => function (Request $request) {
            if ($request->method() === 'PATCH') {
                $this->remote = array_replace($this->remote, $request->data());
            }

            return GoogleWalletHttpFake::json($this->remote);
        },
        $this->classUrl => function (Request $request) {
            if ($request->method() === 'PATCH') {
                $this->remoteClass['hexBackgroundColor'] = $request->data()['hexBackgroundColor'];
            }

            return GoogleWalletHttpFake::json($this->remoteClass);
        },
    ]);

    Log::spy();
});

afterEach(function () {
    unlink($this->credentialsPath);
});

it('updates only shared appearance and accepts provider shorthand without touching pass credentials', function () {
    $passAttributesBefore = $this->pass->fresh()->getAttributes();
    app(GoogleWalletPassUpdater::class)->updateAppearance($this->business);
    expect($this->http->requests(fn (Request $request) => $request->method() === 'PATCH')->sole()[0]->data())
        ->toBe(['hexBackgroundColor' => '#123456', 'reviewStatus' => 'UNDER_REVIEW']);
    expect($this->pass->fresh()->getAttributes())->toBe($passAttributesBefore);
    expect($this->http->requests()->map(fn (array $pair) => $pair[0]->url())->all())
        ->toBe(['https://oauth2.googleapis.com/token', $this->classUrl, $this->classUrl]);
    $this->business->update(['pass_background_color' => '#FFCC00']);
    $this->http->respondWith([$this->classUrl => fn () => GoogleWalletHttpFake::json([...$this->remoteClass, 'hexBackgroundColor' => '#fc0'])]);
    app(GoogleWalletPassUpdater::class)->updateAppearance($this->business);
    expect($this->pass->fresh()->getAttributes())->toBe($passAttributesBefore);
});

it('requires the callers transaction before loading configuration or contacting Google', function () {
    DB::rollBack();
    try {
        expect(fn () => app(GoogleWalletPassUpdater::class)->updateAppearance($this->business))
            ->toThrow(GoogleWalletSynchronizationException::class, 'transaction_required');
        expect($this->http->history)->toBeEmpty();
    } finally {
        DB::beginTransaction();
    }
});

it('rejects an absent foreign or unapproved Class before any write', function (array $classChanges) {
    $this->remoteClass = array_replace($this->remoteClass, $classChanges);
    expect(fn () => app(GoogleWalletPassUpdater::class)->updateAppearance($this->business))
        ->toThrow(GoogleWalletSynchronizationException::class, 'class_not_usable');
    expect($this->http->requests(fn (Request $request) => $request->method() === 'PATCH'))->toBeEmpty();
})->with([[['id' => 'foreign']], [['id' => null]], [['reviewStatus' => 'UNDER_REVIEW']], [['reviewStatus' => 'REJECTED']]]);

it('lets the owning transaction roll back tentative appearance when Google rejects or loses the write', function (int $providerStatus) {
    $backgroundColorBefore = $this->business->fresh()->pass_background_color;
    $this->http->respondWith([$this->classUrl => fn (Request $request) => GoogleWalletHttpFake::json(
        $request->method() === 'PATCH' ? ['error' => 'secret-provider-body'] : $this->remoteClass,
        $request->method() === 'PATCH' ? $providerStatus : 200,
    )]);
    try {
        DB::transaction(function () {
            $business = $this->business->fresh();
            $business->update(['pass_background_color' => '#E53935']);
            app(GoogleWalletPassUpdater::class)->updateAppearance($business);
        });
        test()->fail('Unconfirmed Class appearance must not commit locally.');
    } catch (GoogleWalletSynchronizationException $failure) {
        expect($failure->stage)->toBe(GoogleWalletStage::ClassUpdate);
        expect($failure->outcome)->toBe($providerStatus === 400 ? FailureOutcome::Definitive : FailureOutcome::Unknown);
        expect((string) $failure)->not->toContain('secret-provider-body');
    }
    expect($this->business->fresh()->pass_background_color)->toBe($backgroundColorBefore);
    expect($this->http->requests(fn (Request $request) => $request->method() === 'PATCH'))->toHaveCount(1);
})->with([400, 429, 503]);

it('reads the same Class once for incomplete confirmation and keeps bounded TLS transport', function () {
    $this->http->respondWith([
        'https://oauth2.googleapis.com/token' => function () {
            usleep(100_000);

            return GoogleWalletHttpFake::json(['access_token' => 'synthetic-access', 'expires_in' => 3600]);
        },
        $this->classUrl => function (Request $request) {
            if ($request->method() === 'PATCH') {
                $this->remoteClass['hexBackgroundColor'] = $request['hexBackgroundColor'];

                return GoogleWalletHttpFake::json(['id' => $this->classId]);
            }

            return GoogleWalletHttpFake::json($this->remoteClass);
        },
    ]);
    app(GoogleWalletPassUpdater::class)->updateAppearance($this->business);
    expect($this->http->requests()->map(fn (array $pair) => $pair[0]->method())->all())->toBe(['POST', 'GET', 'PATCH', 'GET']);
    $remainingTimeout = 10;
    foreach ($this->http->history as $index => $transportEntry) {
        $transportOptions = $transportEntry['options'];
        expect($transportOptions['timeout'])->toBeGreaterThan(0)->toBeLessThan($remainingTimeout);
        expect($transportOptions['connect_timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(min(3, $transportOptions['timeout']));
        expect($transportOptions['allow_redirects'])->toBeFalse();
        expect($transportOptions['verify'])->toBeTrue();
        if ($index > 0) {
            expect($transportOptions['timeout'])->toBeLessThan(9.95);
        }
        $remainingTimeout = $transportOptions['timeout'];
    }
});

it('rejects a complete but mismatched Class confirmation without retrying', function (array $classChanges) {
    $this->http->respondWith([$this->classUrl => fn (Request $request) => GoogleWalletHttpFake::json(
        $request->method() === 'PATCH' ? array_replace($this->remoteClass, ['hexBackgroundColor' => '#123456'], $classChanges) : $this->remoteClass,
    )]);
    try {
        app(GoogleWalletPassUpdater::class)->updateAppearance($this->business);
        test()->fail('A mismatched Class must remain unconfirmed.');
    } catch (GoogleWalletSynchronizationException $failure) {
        expect($failure->failure)->toBe(GoogleWalletSynchronizationFailure::ConfirmationMismatch);
        expect($failure->outcome)->toBe(FailureOutcome::Unknown);
    }
    expect($this->http->history)->toHaveCount(3);
})->with([[['id' => 'foreign']], [['reviewStatus' => 'UNDER_REVIEW']], [['hexBackgroundColor' => '#000000']]]);

it('preserves an unknown Class outcome when the response is lost without retaining its native cause', function () {
    $this->http->respondWith([$this->classUrl => fn (Request $request) => $request->method() === 'PATCH'
        ? new ConnectException('secret-transport-detail', new PsrRequest('PATCH', $this->classUrl))
        : GoogleWalletHttpFake::json($this->remoteClass)]);
    try {
        app(GoogleWalletPassUpdater::class)->updateAppearance($this->business);
        test()->fail('A lost write response cannot confirm appearance.');
    } catch (GoogleWalletSynchronizationException $failure) {
        expect($failure->failure)->toBe(GoogleWalletSynchronizationFailure::RequestFailed);
        expect($failure->stage)->toBe(GoogleWalletStage::ClassUpdate);
        expect($failure->outcome)->toBe(FailureOutcome::Unknown);
        expect($failure->getPrevious())->toBeNull();
        expect((string) $failure)->not->toContain('secret-transport-detail');
    }
    expect($this->http->history)->toHaveCount(3);
});

it('rejects a Class write response after the shared deadline', function () {
    $this->http->respondWith([$this->classUrl => function (Request $request) {
        if ($request->method() === 'PATCH') {
            usleep(10_050_000);
            $this->remoteClass['hexBackgroundColor'] = $request['hexBackgroundColor'];
        }

        return GoogleWalletHttpFake::json($this->remoteClass);
    }]);
    try {
        app(GoogleWalletPassUpdater::class)->updateAppearance($this->business);
        test()->fail('Late Class confirmation must not be accepted.');
    } catch (GoogleWalletSynchronizationException $failure) {
        expect($failure->failure)->toBe(GoogleWalletSynchronizationFailure::DeadlineExceeded);
        expect($failure->stage)->toBe(GoogleWalletStage::ClassUpdate);
        expect($failure->outcome)->toBe(FailureOutcome::Unknown);
    }
    expect($this->http->history)->toHaveCount(3);
});

it('classifies shared configuration at its original flow boundary without leaking invocation mode', function (string $setting, mixed $value, GoogleWalletSynchronizationFailure $sync, CustomerPassCreationFailure $creation) {
    config([$setting => $value]);
    $configuration = app(GoogleWalletConfiguration::class);

    try {
        app(GoogleWalletPassUpdater::class)->updateAppearance($this->business);
        $this->fail('Invalid synchronization configuration must fail before OAuth.');
    } catch (GoogleWalletSynchronizationException $exception) {
        expect($exception->failure)->toBe($sync);
        expect($exception->getPrevious())->toBeNull();
    }
    try {
        $configuration->load();
        $this->fail('Default configuration invocation must remain creation-owned.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->failure)->toBe($creation);
        expect($exception->getPrevious())->toBeNull();
    }

    expect($this->http->history)->toBeEmpty();
    Log::shouldNotHaveReceived('log');
})->with([
    ['services.google_wallet.issuer_id', '', GoogleWalletSynchronizationFailure::ConfigurationInvalid, CustomerPassCreationFailure::ConfigurationInvalid],
    ['app.url', 'http://private.invalid', GoogleWalletSynchronizationFailure::OriginInvalid, CustomerPassCreationFailure::OriginInvalid],
    'unsafe logo' => ['services.google_wallet.logo_url', 'http://unsafe.example.test/icon-logo-wallet.png', GoogleWalletSynchronizationFailure::LogoUrlInvalid, CustomerPassCreationFailure::LogoUrlInvalid],
    ['services.google_wallet.credentials_path', null, GoogleWalletSynchronizationFailure::CredentialsInvalid, CustomerPassCreationFailure::CredentialsInvalid],
    ['services.google_wallet.credentials_path', '/nonexistent/test-only', GoogleWalletSynchronizationFailure::CredentialsInvalid, CustomerPassCreationFailure::CredentialsInvalid],
]);

it('preserves shared OAuth status and certainty without translating creation exceptions', function (int $status) {
    $this->http->respondWith([
        'https://oauth2.googleapis.com/token' => GoogleWalletHttpFake::json(['error' => 'private OAuth body'], $status),
    ]);

    try {
        app(GoogleWalletPassUpdater::class)->updateAppearance($this->business);
        $this->fail('Authentication must fail before reading or writing the Object.');
    } catch (GoogleWalletSynchronizationException $exception) {
        expect($exception->failure)->toBe(GoogleWalletSynchronizationFailure::ProviderRejected);
        expect($exception->stage)->toBe(GoogleWalletStage::OAuth);
        expect($exception->outcome)->toBe(FailureOutcome::Definitive);
        expect($exception->providerStatus)->toBe($status);
        expect($exception->context)->toMatchArray([
            'http_method' => 'POST',
            'previous_class' => $status < 400 ? Exception::class
                : ($status < 500 ? ClientException::class : ServerException::class),
        ]);
        expect($exception->getPrevious())->toBeNull();
        expect(json_encode($exception->context))->not->toContain('private OAuth body');
    }

    expect($this->http->history)->toHaveCount(1);
    Log::shouldNotHaveReceived('log');
})->with([302, 400, 408, 409, 429, 500, 503]);

it('rejects malformed shared OAuth responses without starting Wallet requests', function (array $body) {
    $this->http->respondWith([
        'https://oauth2.googleapis.com/token' => GoogleWalletHttpFake::json($body),
    ]);

    try {
        app(GoogleWalletPassUpdater::class)->updateAppearance($this->business);
        $this->fail('Malformed OAuth must not reach Wallet resources.');
    } catch (GoogleWalletSynchronizationException $exception) {
        expect($exception->failure)->toBe(GoogleWalletSynchronizationFailure::MalformedResponse);
        expect($exception->getPrevious())->toBeNull();
    }

    expect($this->http->history)->toHaveCount(1);
    Log::shouldNotHaveReceived('log');
})->with(['missing' => [[]], 'empty' => [['access_token' => '']], 'null' => [['access_token' => null]]]);

it('keeps credential parsing and signing validation flow-local and secret-free', function (string $case, GoogleWalletSynchronizationFailure $expected) {
    $account = json_decode(file_get_contents($this->credentialsPath), true);
    $contents = match ($case) {
        'malformed JSON' => '{private-account-marker',
        'scalar JSON' => '"private-account-marker"',
        'wrong account type' => json_encode([...$account, 'type' => 'authorized_user']),
        'invalid email' => json_encode([...$account, 'client_email' => 'private-account-marker']),
        'missing key' => json_encode(array_diff_key($account, ['private_key' => true])),
        'invalid signing key' => json_encode([...$account, 'private_key' => 'private-account-marker']),
    };
    file_put_contents($this->credentialsPath, $contents);

    try {
        app(GoogleWalletPassUpdater::class)->updateAppearance($this->business);
        $this->fail('Invalid credentials must stop the synchronization at configuration.');
    } catch (GoogleWalletSynchronizationException $exception) {
        expect($exception->failure)->toBe($expected);
        expect($exception->getPrevious())->toBeNull();
        expect(json_encode($exception->context))->not->toContain('private-account-marker', $account['private_key']);
    }

    expect($this->http->history)->toBeEmpty();
    Log::shouldNotHaveReceived('log');
})->with([
    ['malformed JSON', GoogleWalletSynchronizationFailure::CredentialsInvalid],
    ['scalar JSON', GoogleWalletSynchronizationFailure::CredentialsInvalid],
    ['wrong account type', GoogleWalletSynchronizationFailure::CredentialsInvalid],
    ['invalid email', GoogleWalletSynchronizationFailure::CredentialsInvalid],
    ['missing key', GoogleWalletSynchronizationFailure::CredentialsInvalid],
    ['invalid signing key', GoogleWalletSynchronizationFailure::SigningKeyInvalid],
]);

it('preserves definitive read failures and unknown confirmation failures without write retries', function (bool $confirmation, int $status) {
    $this->http->respondWith([
        $this->classUrl => function (Request $request) use ($confirmation, $status) {
            if ($request->method() === 'PATCH') {
                return GoogleWalletHttpFake::json(['id' => $this->classId]);
            }
            if (! $confirmation || count($this->http->history) >= 3) {
                return GoogleWalletHttpFake::json(['error' => 'private provider body'], $status);
            }

            return GoogleWalletHttpFake::json($this->remoteClass);
        },
    ]);

    try {
        app(GoogleWalletPassUpdater::class)->updateAppearance($this->business);
        $this->fail('An unavailable read must not confirm an update.');
    } catch (GoogleWalletSynchronizationException $exception) {
        expect($exception->failure)->toBe(GoogleWalletSynchronizationFailure::ProviderRejected);
        expect($exception->stage)->toBe($confirmation ? GoogleWalletStage::Confirmation : GoogleWalletStage::ClassRead);
        expect($exception->outcome)->toBe($confirmation ? FailureOutcome::Unknown : FailureOutcome::Definitive);
        expect($exception->providerStatus)->toBe($status);
        expect($exception->getPrevious())->toBeNull();
        expect(json_encode($exception->context))->not->toContain('private provider body');
    }

    expect($this->http->history)->toHaveCount($confirmation ? 4 : 2);
    expect($this->http->requests(fn (Request $request) => $request->method() === 'PATCH'))->toHaveCount($confirmation ? 1 : 0);
    Log::shouldNotHaveReceived('log');
})->with([[false, 400], [false, 503], [true, 400], [true, 503]]);

it('preserves deadline precedence over a late malformed OAuth response before any Object request', function () {
    $this->http->respondWith([
        'https://oauth2.googleapis.com/token' => function () {
            // the native fake does not enforce timeouts, so exercise a late decoded response
            usleep(10_050_000);

            return GoogleWalletHttpFake::json([]);
        },
    ]);

    try {
        app(GoogleWalletPassUpdater::class)->updateAppearance($this->business);
        $this->fail('Late malformed OAuth must retain the pre-refactor deadline classification.');
    } catch (GoogleWalletSynchronizationException $exception) {
        expect($exception->failure)->toBe(GoogleWalletSynchronizationFailure::DeadlineExceeded);
        expect($exception->stage)->toBe(GoogleWalletStage::OAuth);
        expect($exception->outcome)->toBe(FailureOutcome::Definitive);
        expect($exception->providerStatus)->toBeNull();
        expect($exception->getPrevious())->toBeNull();
    }

    expect($this->http->history)->toHaveCount(1);
    Log::shouldNotHaveReceived('log');
});

it('preserves baseline OAuth transport classification without retaining native secrets', function (string $nativeClass) {
    $secret = 'private-oauth-transport-marker';
    $request = new PsrRequest('POST', 'https://oauth2.googleapis.com/token', [
        'Authorization' => 'Bearer '.$secret,
    ]);
    $previous = new RuntimeException($secret.' nested previous');
    $native = $nativeClass === ConnectException::class
        ? new ConnectException($secret.' message', $request, $previous)
        : new RequestException($secret.' message', $request, previous: $previous);
    $this->http->respondWith(['https://oauth2.googleapis.com/token' => $native]);

    try {
        app(GoogleWalletPassUpdater::class)->updateAppearance($this->business);
        $this->fail('OAuth transport failure must abort before any Object request.');
    } catch (GoogleWalletSynchronizationException $exception) {
        expect($exception->failure->value)->toBe('transport_failed');
        expect($exception->getMessage())->toBe('transport_failed');
        expect($exception->stage)->toBe(GoogleWalletStage::OAuth);
        expect($exception->outcome)->toBe(FailureOutcome::Definitive);
        expect($exception->providerStatus)->toBeNull();
        expect($exception->getPrevious())->toBeNull();
        expect($exception->context)->toBe([
            'previous_class' => $nativeClass,
            'http_method' => 'POST',
        ]);
        expect(json_encode($exception->context, JSON_THROW_ON_ERROR))->not->toContain($secret, 'Authorization', 'Bearer');
        expect((string) $exception)->not->toContain($secret);
    }

    $requests = $this->http->requests();
    expect($requests)->toHaveCount(1);
    $sent = $requests->sole()[0];
    expect($sent->method())->toBe('POST');
    expect($sent->url())->toBe('https://oauth2.googleapis.com/token');
    foreach (['log', 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $method) {
        Log::shouldNotHaveReceived($method);
    }
})->with([
    'connection failure' => [ConnectException::class],
    'request without response' => [RequestException::class],
]);
