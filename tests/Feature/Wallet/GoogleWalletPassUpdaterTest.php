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
use Illuminate\Support\Facades\Log;
use Tests\Support\GoogleWalletHttpFake;

uses(RefreshDatabase::class);

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

    $this->credentialsPath = tempnam(sys_get_temp_dir(), 'wallet-update-');
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
    ]);

    Log::spy();
});

afterEach(function () {
    unlink($this->credentialsPath);
});

it('replaces absolute content on the same object without changing credentials or the class', function () {
    $before = $this->pass->fresh()->getAttributes();

    app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);

    expect($this->pass->fresh()->getAttributes())
        ->toBe($before);

    $writes = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');

    expect($writes)
        ->toHaveCount(1);

    $payload = $writes->first()[0]->data();
    $moduleIds = array_column($payload['textModulesData'], 'id');

    expect(array_keys($payload))
        ->toBe(['textModulesData']);

    expect($moduleIds)
        ->not->toContain('stale')
        ->toContain('details_code', 'card_primary', 'card_secondary');

    expect($this->remote['id'])
        ->toBe($this->objectId);

    expect($this->remote['barcode']['value'])
        ->toBe($this->credentials->validationToken);

    expect($this->http->history)
        ->toHaveCount(3);

    $options = array_column($this->http->history, 'options');
    $timeouts = array_column($options, 'timeout');

    expect($timeouts[0])
        ->toBeLessThanOrEqual(10)
        ->toBeGreaterThan(0);

    expect($timeouts[2])
        ->toBeLessThan($timeouts[0]);

    foreach ($options as $requestOptions) {
        expect($requestOptions['allow_redirects'])
            ->toBeFalse();

        expect($requestOptions['verify'])
            ->toBeTrue();
    }

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('error');
});

it('confirms an incomplete patch response with one same-object read', function () {
    $this->http->respondWith([
        $this->url => function (Request $request) {
            if ($request->method() === 'PATCH') {
                $this->remote = array_replace($this->remote, $request->data());

                return GoogleWalletHttpFake::json(['id' => $this->objectId]);
            }

            return GoogleWalletHttpFake::json($this->remote);
        },
    ]);

    app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);

    $reads = $this->http->requests(fn (Request $request) => $request->method() === 'GET');
    $writes = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');

    expect($reads)
        ->toHaveCount(2);

    expect($writes)
        ->toHaveCount(1);
});

it('rejects a mismatched remote identity before any write', function (string $field) {
    if ($field === 'barcode') {
        $this->remote['barcode']['value'] = 'foreign-token';
    } else {
        $this->remote[$field] = 'foreign';
    }

    expect(fn () => app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass))
        ->toThrow(GoogleWalletSynchronizationException::class);

    $writes = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');

    expect($writes)
        ->toBeEmpty();
})->with(['id', 'classId', 'accountId', 'barcode']);

it('lets the owning transaction roll back tentative writes when Google fails without retrying', function (int $status) {
    $original = $this->business->name;

    $this->http->respondWith([
        $this->url => function (Request $request) use ($status) {
            if ($request->method() === 'PATCH') {
                return GoogleWalletHttpFake::json([
                    'error' => ['message' => 'secret-provider-body'],
                ], $status);
            }

            return GoogleWalletHttpFake::json($this->remote);
        },
    ]);

    try {
        DB::transaction(function () {
            $this->business->update(['name' => 'Tentative transaction fixture']);

            app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);
        });

        $this->fail('Provider failure must escape the caller transaction.');
    } catch (GoogleWalletSynchronizationException $failure) {
        expect($failure->getPrevious())
            ->toBeNull();

        expect($failure->getMessage())
            ->not->toContain('secret-provider-body');

        expect($failure->failure)->toBe(GoogleWalletSynchronizationFailure::ProviderRejected);
        expect($failure->stage)->toBe(GoogleWalletStage::ObjectUpdate);
        expect($failure->outcome)->toBe($status === 400 ? FailureOutcome::Definitive : FailureOutcome::Unknown);

        expect($failure->providerStatus)
            ->toBe($status);
    }

    expect($this->business->fresh()->name)
        ->toBe($original);

    $writes = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');

    expect($writes)
        ->toHaveCount(1);

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('error');
})->with([400, 429, 503]);

it('permits the caller transaction to retain its write after provider confirmation', function () {
    DB::transaction(function () {
        $this->business->update(['name' => 'Confirmed transaction fixture']);

        app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);
    });

    expect($this->business->fresh()->name)
        ->toBe('Confirmed transaction fixture');
});

it('rejects a pass owned by another business before contacting Google', function () {
    $otherBusiness = Business::factory()->create();

    expect(fn () => app(GoogleWalletPassUpdater::class)->update($otherBusiness, $this->pass))
        ->toThrow(GoogleWalletSynchronizationException::class);

    expect($this->http->history)
        ->toBeEmpty();
});

it('rejects an unconfirmed replacement without retrying the write', function () {
    $this->http->respondWith([
        $this->url => fn () => GoogleWalletHttpFake::json($this->remote),
    ]);

    try {
        app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);

        $this->fail('Stale content must not count as provider confirmation.');
    } catch (GoogleWalletSynchronizationException $failure) {
        expect($failure->failure)->toBe(GoogleWalletSynchronizationFailure::ConfirmationMismatch);
        expect($failure->stage)->toBe(GoogleWalletStage::Confirmation);
        expect($failure->outcome)->toBe(FailureOutcome::Unknown);
    }

    $writes = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');

    expect($writes)
        ->toHaveCount(1);

    Log::shouldNotHaveReceived('info');
});

it('preserves uncertainty when the update response is lost', function () {
    $this->http->respondWith([
        $this->url => function (Request $request) {
            if ($request->method() === 'PATCH') {
                return new ConnectException(
                    'secret-transport-detail',
                    new PsrRequest('PATCH', $this->url),
                );
            }

            return GoogleWalletHttpFake::json($this->remote);
        },
    ]);

    try {
        app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);

        $this->fail('A lost response cannot confirm the update.');
    } catch (GoogleWalletSynchronizationException $failure) {
        expect($failure->failure)->toBe(GoogleWalletSynchronizationFailure::RequestFailed);
        expect($failure->stage)->toBe(GoogleWalletStage::ObjectUpdate);
        expect($failure->outcome)->toBe(FailureOutcome::Unknown);

        expect($failure->getPrevious())
            ->toBeNull();

        expect($failure->getMessage())
            ->not->toContain('secret-transport-detail');
    }

    expect($this->http->history)
        ->toHaveCount(3);

    Log::shouldNotHaveReceived('info');
});

it('shares the ten second deadline across authentication and all provider requests', function () {
    $this->http->respondWith([
        'https://oauth2.googleapis.com/token' => function () {
            // the fake does not enforce Guzzle timeouts; consume part of the real monotonic budget
            usleep(100_000);

            return GoogleWalletHttpFake::json([
                'access_token' => 'synthetic-access',
                'expires_in' => 3600,
            ]);
        },
        $this->url => function (Request $request) {
            if ($request->method() === 'PATCH') {
                $this->remote = array_replace($this->remote, $request->data());

                return GoogleWalletHttpFake::json(['id' => $this->objectId]);
            }

            return GoogleWalletHttpFake::json($this->remote);
        },
    ]);

    app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);

    expect($this->http->history)
        ->toHaveCount(4);

    $remaining = 10;

    foreach ($this->http->history as $index => $request) {
        $options = $request['options'];

        expect($options['timeout'])
            ->toBeGreaterThan(0)
            ->toBeLessThan($remaining);

        expect($options['connect_timeout'])
            ->toBeGreaterThan(0)
            ->toBeLessThanOrEqual(min(3, $options['timeout']));

        if ($index > 0) {
            expect($options['timeout'])
                ->toBeLessThan(9.95);
        }

        $remaining = $options['timeout'];
    }
});

it('rejects a provider response received after the total deadline', function () {
    $this->http->respondWith([
        $this->url => function (Request $request) {
            if ($request->method() === 'PATCH') {
                $this->remote = array_replace($this->remote, $request->data());

                // a deliberately late mock response must not be accepted as a timely confirmation
                usleep(10_050_000);
            }

            return GoogleWalletHttpFake::json($this->remote);
        },
    ]);

    try {
        app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);

        $this->fail('A late provider response must fail the synchronous operation.');
    } catch (GoogleWalletSynchronizationException $failure) {
        expect($failure->failure)->toBe(GoogleWalletSynchronizationFailure::DeadlineExceeded);
        expect($failure->stage)->toBe(GoogleWalletStage::ObjectUpdate);
        expect($failure->outcome)->toBe(FailureOutcome::Unknown);
    }

    expect($this->http->history)
        ->toHaveCount(3);

    Log::shouldNotHaveReceived('info');
});

it('classifies shared configuration at its original flow boundary without leaking invocation mode', function (string $setting, mixed $value, GoogleWalletSynchronizationFailure $sync, CustomerPassCreationFailure $creation) {
    config([$setting => $value]);
    $configuration = app(GoogleWalletConfiguration::class);

    try {
        app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);
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
    ['services.google_wallet.logo_black_url', '', GoogleWalletSynchronizationFailure::LogoUrlInvalid, CustomerPassCreationFailure::LogoUrlInvalid],
    ['services.google_wallet.credentials_path', null, GoogleWalletSynchronizationFailure::CredentialsInvalid, CustomerPassCreationFailure::CredentialsInvalid],
    ['services.google_wallet.credentials_path', '/nonexistent/test-only', GoogleWalletSynchronizationFailure::CredentialsInvalid, CustomerPassCreationFailure::CredentialsInvalid],
]);

it('preserves shared OAuth status and certainty without translating creation exceptions', function (int $status) {
    $this->http->respondWith([
        'https://oauth2.googleapis.com/token' => GoogleWalletHttpFake::json(['error' => 'private OAuth body'], $status),
    ]);

    try {
        app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);
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
        app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);
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
        app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);
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
        $this->url => function (Request $request) use ($confirmation, $status) {
            if (! $confirmation || count($this->http->history) >= 3) {
                return GoogleWalletHttpFake::json(['error' => 'private provider body'], $status);
            }

            return GoogleWalletHttpFake::json($this->remote);
        },
    ]);

    try {
        app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);
        $this->fail('An unavailable read must not confirm an update.');
    } catch (GoogleWalletSynchronizationException $exception) {
        expect($exception->failure)->toBe(GoogleWalletSynchronizationFailure::ProviderRejected);
        expect($exception->stage)->toBe($confirmation ? GoogleWalletStage::Confirmation : GoogleWalletStage::ObjectRead);
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
        app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);
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
        app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);
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
