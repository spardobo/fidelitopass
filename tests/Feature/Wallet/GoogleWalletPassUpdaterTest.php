<?php

use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Exceptions\GoogleWalletSynchronizationException;
use App\Integrations\GoogleWallet\GoogleWalletClient;
use App\Integrations\GoogleWallet\GoogleWalletPassUpdater;
use App\Models\Business;
use App\Support\CustomerPassCredentials;
use GuzzleHttp\Exception\ConnectException;
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

        expect($failure->userMessage())
            ->toBe('No pudimos confirmar la actualización de la tarjeta. Inténtalo de nuevo en unos momentos.');

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
        expect($failure->stage)
            ->toBe(GoogleWalletStage::Confirmation);

        expect($failure->outcome)
            ->toBe(FailureOutcome::Unknown);
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
                    new GuzzleHttp\Psr7\Request('PATCH', $this->url),
                );
            }

            return GoogleWalletHttpFake::json($this->remote);
        },
    ]);

    try {
        app(GoogleWalletPassUpdater::class)->update($this->business, $this->pass);

        $this->fail('A lost response cannot confirm the update.');
    } catch (GoogleWalletSynchronizationException $failure) {
        expect($failure->outcome)
            ->toBe(FailureOutcome::Unknown);

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
        expect($failure->reason)
            ->toBe('deadline_exceeded');

        expect($failure->outcome)
            ->toBe(FailureOutcome::Unknown);
    }

    expect($this->http->history)
        ->toHaveCount(3);

    Log::shouldNotHaveReceived('info');
});
