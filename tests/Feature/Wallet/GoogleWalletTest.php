<?php

use App\Integrations\GoogleWallet\GoogleWallet;
use App\Integrations\GoogleWallet\WalletIssuanceException;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Support\CustomerPassCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($this->key, $pem);
    $this->credentialsPath = tempnam(sys_get_temp_dir(), 'wallet-test-');
    $this->serviceAccount = [
        'type' => 'service_account',
        'client_email' => 'wallet@platform.iam.gserviceaccount.com',
        'private_key' => $pem,
    ];
    file_put_contents($this->credentialsPath, json_encode($this->serviceAccount));
    app()->instance('env', 'production');
    config([
        'app.url' => 'https://passes.example.test',
        'services.google_wallet' => [
            'issuer_id' => '123456',
            'credentials_path' => $this->credentialsPath,
            'logo_url' => null,
            'connect_timeout' => 3,
            'timeout' => 10,
        ],
    ]);
    $this->business = Business::factory()->create(['name' => 'Panadería real', 'pass_background_color' => '#123456']);
    $this->credentials = CustomerPassCredentials::prepare($this->business);
    $this->classId = '123456.business_'.$this->business->public_id;
    $this->objectId = '123456.pass_'.$this->credentials->publicId();
    $this->class = [
        'id' => $this->classId,
        'issuerName' => 'FidelitoPass',
        'programName' => $this->business->name,
        'programLogo' => ['sourceUri' => ['uri' => 'https://passes.example.test/logo.png']],
        'hexBackgroundColor' => '#123456',
        'classTemplateInfo' => ['cardTemplateOverride' => ['cardRowTemplateInfos' => [
            ['oneItem' => ['item' => ['firstValue' => ['fields' => [['fieldPath' => "object.textModulesData['promotion']"]]]]]],
            ['oneItem' => ['item' => ['firstValue' => ['fields' => [['fieldPath' => 'object.loyaltyPoints.balance']]]]]],
            ['oneItem' => ['item' => ['firstValue' => ['fields' => [['fieldPath' => "object.textModulesData['reward']"]]]]]],
        ]]],
        'reviewStatus' => 'approved',
    ];
    $this->object = [
        'id' => $this->objectId,
        'classId' => $this->classId,
        'barcode' => ['type' => 'QR_CODE', 'value' => $this->credentials->validationToken()],
        'accountId' => $this->credentials->manualCode(),
    ];
});

afterEach(function () {
    unlink($this->credentialsPath);
});

it('signs verifiable OAuth and private existing-object handoff and creates no local pass', function () {
    Http::fake(walletResponses($this->class, $this->object));

    $result = app(GoogleWallet::class)->provision($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
    $saveJwt = substr($result['save_url'], strlen('https://pay.google.com/gp/v/save/'));
    $save = walletVerifiedClaims($saveJwt, $this->key);
    expect($save)->toMatchArray([
        'iss' => 'wallet@platform.iam.gserviceaccount.com', 'aud' => 'google', 'typ' => 'savetowallet',
        'origins' => ['https://passes.example.test'],
        'payload' => ['loyaltyObjects' => [['id' => $this->objectId, 'classId' => $this->classId]]],
    ])->and(json_encode($save))->not->toContain($this->credentials->validationToken(), $this->credentials->manualCode());
    Http::assertSent(function (Request $request) {
        if ($request->url() !== 'https://oauth2.googleapis.com/token') {
            return false;
        }
        $claims = walletVerifiedClaims($request['assertion'], $this->key);
        expect($claims)->toMatchArray([
            'iss' => 'wallet@platform.iam.gserviceaccount.com',
            'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
            'aud' => 'https://oauth2.googleapis.com/token',
        ])->and($claims['exp'] - $claims['iat'])->toBeLessThanOrEqual(3600);

        return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    });
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/loyaltyObject')
        && $request['state'] === 'ACTIVE'
        && $request['accountId'] === $this->credentials->manualCode()
        && $request['barcode']['value'] === $this->credentials->validationToken());
    Http::assertSentCount(3);
    expect(CustomerPass::count())->toBe(0);
});

it('creates a missing deterministic class once with the shared layout before the object', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/'.$this->classId] = Http::response([], 404);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass'] = Http::response($this->class, 201);
    Http::fake($responses);

    app(GoogleWallet::class)->provision($this->business, $this->credentials);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/loyaltyClass')
        && $request['id'] === $this->classId && $request['programName'] === 'Panadería real'
        && $request['reviewStatus'] === 'underReview'
        && $request['classTemplateInfo'] === $this->class['classTemplateInfo']);
    $requests = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
    expect(array_search('https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass', $requests))
        ->toBeLessThan(array_search('https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject', $requests));
});

it('maps real current promotion content and zero new-pass progress into the provider object', function () {
    $this->business->promotions()->make()->forceFill([
        'status' => 'published', 'target_points' => 7, 'reward_title' => 'Una hogaza artesanal',
        'timezone_snapshot' => 'America/La_Paz', 'starts_at' => '2020-01-01 04:00:00+00', 'ends_at' => '2099-01-01 04:00:00+00',
    ])->save();
    Http::fake(walletResponses($this->class, $this->object));

    app(GoogleWallet::class)->provision($this->business, $this->credentials);

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/loyaltyObject')) {
            return false;
        }
        expect($request['loyaltyPoints'])->toBe(['label' => 'Puntos', 'balance' => ['string' => '0 / 7 puntos']])
            ->and(collect($request['textModulesData'])->firstWhere('id', 'reward')['body'])->toBe('🎁 Una hogaza artesanal')
            ->and(collect($request['textModulesData'])->firstWhere('id', 'manual_code'))
            ->toBe(['id' => 'manual_code', 'header' => 'Código del Pase', 'body' => $this->credentials->manualCode()]);

        return true;
    });
});

it('reuses a business class for different private objects and isolates another business', function () {
    $seenClasses = [];
    $seenObjects = [];
    Http::fake(function (Request $request) use (&$seenClasses, &$seenObjects) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            return Http::response(['access_token' => 'test-access-token', 'token_type' => 'Bearer']);
        }
        if ($request->method() === 'GET') {
            return isset($seenClasses[basename($request->url())])
                ? Http::response($seenClasses[basename($request->url())]) : Http::response([], 404);
        }
        if (str_ends_with($request->url(), '/loyaltyClass')) {
            $seenClasses[$request['id']] = [...$request->data(), 'reviewStatus' => 'approved'];

            return Http::response($seenClasses[$request['id']], 201);
        }
        $seenObjects[] = $request->data();

        return Http::response($request->data(), 201);
    });
    $wallet = app(GoogleWallet::class);
    $wallet->provision($this->business, $this->credentials);
    $wallet->provision($this->business, CustomerPassCredentials::prepare($this->business));
    $other = Business::factory()->create(['pass_background_color' => '#654321']);
    $wallet->provision($other, CustomerPassCredentials::prepare($other));

    expect($seenClasses)->toHaveCount(2)->and($seenObjects)->toHaveCount(3)
        ->and($seenObjects[0]['classId'])->toBe($seenObjects[1]['classId'])
        ->and($seenObjects[2]['classId'])->not->toBe($seenObjects[0]['classId'])
        ->and($seenObjects[0]['id'])->not->toBe($seenObjects[1]['id'])
        ->and($seenObjects[0]['barcode']['value'])->not->toBe($seenObjects[1]['barcode']['value']);
});

it('reconciles concurrent class creation rather than trusting a conflict', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/'.$this->classId] = Http::sequence()->push([], 404)->push($this->class);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass'] = Http::response(['private' => 'unsafe-provider-body'], 409);
    Http::fake($responses);

    expect(app(GoogleWallet::class)->provision($this->business, $this->credentials)['object_id'])->toBe($this->objectId);
    Http::assertSentCount(5);
});

it('patches only application-owned class appearance and preserves unrelated fields', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/'.$this->classId] = Http::sequence()
        ->push([...$this->class, 'programName' => 'Old name', 'homepageUri' => ['uri' => 'https://unrelated.example.test']])
        ->push($this->class);
    Http::fake($responses);

    app(GoogleWallet::class)->provision($this->business, $this->credentials);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request['programName'] === 'Panadería real' && $request['reviewStatus'] === 'underReview'
        && ! array_key_exists('homepageUri', $request->data()));
});

it('rejects unusable or conflicting classes before creating an object', function (array $overrides) {
    $responses = walletResponses($this->class, $this->object);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/'.$this->classId] = Http::response([...$this->class, ...$overrides]);
    Http::fake($responses);

    expect(fn () => app(GoogleWallet::class)->provision($this->business, $this->credentials))
        ->toThrow(WalletIssuanceException::class);
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject'));
})->with([
    'draft' => [['reviewStatus' => 'draft']],
    'pending' => [['reviewStatus' => 'underReview']],
    'foreign id' => [['id' => '999.business_foreign']],
]);

it('reconciles uncertain object creation using all private immutable binding fields', function (int $status) {
    $responses = walletResponses($this->class, $this->object);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject'] = Http::response(['private' => 'unsafe-provider-body'], $status);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/'.$this->objectId] = Http::response($this->object);
    Http::fake($responses);

    expect(app(GoogleWallet::class)->provision($this->business, $this->credentials)['object_id'])->toBe($this->objectId);
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_ends_with($request->url(), $this->objectId));
})->with([202, 204, 408, 409, 429, 500, 503]);

it('reconciles a timeout without blindly repeating object creation', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject'] = Http::failedConnection('unsafe-token-and-request-body');
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/'.$this->objectId] = Http::response($this->object);
    Http::fake($responses);

    expect(app(GoogleWallet::class)->provision($this->business, $this->credentials)['object_id'])->toBe($this->objectId);
});

it('keeps absence or failed reconciliation unknown after a possibly accepted object post', function (int $status) {
    $responses = walletResponses($this->class, $this->object);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject'] = Http::response([], 503);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/'.$this->objectId] = Http::response(['unsafe' => 'unsafe-provider-body'], $status);
    Http::fake($responses);

    try {
        app(GoogleWallet::class)->provision($this->business, $this->credentials);
        test()->fail('Unknown issuance must not return a handoff.');
    } catch (WalletIssuanceException $exception) {
        expect($exception->outcome)->toBe('unknown')->and($exception->stage)->toBe('object');
    }
    Http::assertSentCount(4);
})->with([404, 403, 500]);

it('rejects a mismatched reconciled private binding', function (string $field) {
    $object = $this->object;
    if ($field === 'barcode') {
        $object['barcode']['value'] = 'foreign-private-token';
    } else {
        $object[$field] = 'foreign-value';
    }
    $responses = walletResponses($this->class, $this->object);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject'] = Http::response([], 409);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/'.$this->objectId] = Http::response($object);
    Http::fake($responses);

    try {
        app(GoogleWallet::class)->provision($this->business, $this->credentials);
        test()->fail('Conflicting binding must fail.');
    } catch (WalletIssuanceException $exception) {
        expect($exception->outcome)->toBe('definitive')->and($exception->reason)->toBe('binding_conflict');
    }
})->with(['id', 'classId', 'accountId', 'barcode']);

it('sanitizes definitive provider rejection without retaining unsafe exception context', function (string $stage, int $status) {
    $responses = walletResponses($this->class, $this->object);
    $url = match ($stage) {
        'oauth' => 'https://oauth2.googleapis.com/token',
        'class' => 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/'.$this->classId,
        'object' => 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject',
    };
    $responses[$url] = Http::response(['error' => 'unsafe-provider-body '.$this->credentials->validationToken()], $status);
    Http::fake($responses);

    try {
        app(GoogleWallet::class)->provision($this->business, $this->credentials);
        test()->fail('Provider rejection must fail.');
    } catch (WalletIssuanceException $exception) {
        expect($exception->outcome)->toBe('definitive')->and($exception->stage)->toBe($stage)
            ->and($exception->getPrevious())->toBeNull()
            ->and((string) $exception)->not->toContain('unsafe-provider-body', $this->credentials->validationToken(), 'test-access-token');
    }
})->with([['oauth', 400], ['oauth', 401], ['class', 403], ['object', 400], ['object', 403]]);

it('does not treat malformed object success as confirmed and safely reconciles it', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject'] = Http::response(['id' => $this->objectId], 201);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/'.$this->objectId] = Http::response([], 404);
    Http::fake($responses);

    try {
        app(GoogleWallet::class)->provision($this->business, $this->credentials);
        test()->fail('Incomplete confirmation must stay unknown.');
    } catch (WalletIssuanceException $exception) {
        expect($exception->outcome)->toBe('unknown');
    }
});

it('fails closed before HTTP for invalid configuration or signing material', function (string $case) {
    match ($case) {
        'issuer' => config(['services.google_wallet.issuer_id' => '']),
        'email' => file_put_contents($this->credentialsPath, json_encode([...$this->serviceAccount, 'client_email' => 'not-an-account'])),
        'origin' => config(['app.url' => 'http://unsafe.example.test']),
        'origin userinfo' => config(['app.url' => 'https://user:password@example.test']),
        'logo' => config(['services.google_wallet.logo_url' => 'http://unsafe.example.test/logo.png']),
        'timeout' => config(['services.google_wallet.timeout' => 0]),
        'missing key' => config(['services.google_wallet.credentials_path' => '/nonexistent/unsafe-private-path']),
        'invalid key' => file_put_contents($this->credentialsPath, json_encode([...$this->serviceAccount, 'private_key' => 'unsafe-invalid-private-key'])),
        'weak key' => openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 1024]), $pem) && file_put_contents($this->credentialsPath, json_encode([...$this->serviceAccount, 'private_key' => $pem])),
        'non-RSA key' => openssl_pkey_export(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']), $pem) && file_put_contents($this->credentialsPath, json_encode([...$this->serviceAccount, 'private_key' => $pem])),
        'foreign business' => $this->business = Business::factory()->create(),
    };

    expect(fn () => app(GoogleWallet::class)->provision($this->business, $this->credentials))->toThrow(WalletIssuanceException::class);
    Http::assertNothingSent();
})->with(['issuer', 'email', 'origin', 'origin userinfo', 'logo', 'timeout', 'missing key', 'invalid key', 'weak key', 'non-RSA key', 'foreign business']);

it('loads repository-relative service-account JSON afresh for each operation', function () {
    $relativePath = 'storage/framework/wallet-test-'.bin2hex(random_bytes(8)).'.json';
    $absolutePath = base_path($relativePath);
    file_put_contents($absolutePath, json_encode($this->serviceAccount));
    config(['services.google_wallet.credentials_path' => $relativePath]);
    Http::fake(walletResponses($this->class, $this->object));
    $wallet = app(GoogleWallet::class);

    try {
        $wallet->provision($this->business, $this->credentials);
        file_put_contents($absolutePath, json_encode([
            ...$this->serviceAccount,
            'client_email' => 'rotated@another-project.iam.gserviceaccount.com',
        ]));
        $result = $wallet->provision($this->business, $this->credentials);

        $claims = walletVerifiedClaims(
            substr($result['save_url'], strlen('https://pay.google.com/gp/v/save/')),
            $this->key,
        );
        expect($claims['iss'])->toBe('rotated@another-project.iam.gserviceaccount.com');
        Http::assertSentCount(6);
    } finally {
        unlink($absolutePath);
    }
});

it('sanitizes unusable service-account JSON before HTTP', function (string $case) {
    match ($case) {
        'missing path' => config(['services.google_wallet.credentials_path' => null]),
        'wrong path type' => config(['services.google_wallet.credentials_path' => ['unsafe-private-path']]),
        'empty path' => config(['services.google_wallet.credentials_path' => '']),
        'directory path' => config(['services.google_wallet.credentials_path' => sys_get_temp_dir()]),
        'NUL path' => config(['services.google_wallet.credentials_path' => "unsafe\0private-path"]),
        'malformed JSON' => file_put_contents($this->credentialsPath, '{unsafe-secret-marker'),
        'scalar JSON' => file_put_contents($this->credentialsPath, '"unsafe-secret-marker"'),
        'wrong account type' => file_put_contents($this->credentialsPath, json_encode([...$this->serviceAccount, 'type' => 'authorized_user'])),
        'missing account type' => file_put_contents($this->credentialsPath, json_encode(array_diff_key($this->serviceAccount, ['type' => true]))),
        'wrong email type' => file_put_contents($this->credentialsPath, json_encode([...$this->serviceAccount, 'client_email' => ['unsafe-secret-marker']])),
        'invalid email syntax' => file_put_contents($this->credentialsPath, json_encode([...$this->serviceAccount, 'client_email' => 'wallet@invalid..iam.gserviceaccount.com'])),
        'invalid PEM' => file_put_contents($this->credentialsPath, json_encode([...$this->serviceAccount, 'private_key' => 'unsafe-secret-marker'])),
        'missing email' => file_put_contents($this->credentialsPath, json_encode(array_diff_key($this->serviceAccount, ['client_email' => true]))),
        'wrong key type' => file_put_contents($this->credentialsPath, json_encode([...$this->serviceAccount, 'private_key' => ['unsafe-secret-marker']])),
        'missing key' => file_put_contents($this->credentialsPath, json_encode(array_diff_key($this->serviceAccount, ['private_key' => true]))),
        'empty key' => file_put_contents($this->credentialsPath, json_encode([...$this->serviceAccount, 'private_key' => ''])),
    };
    $previousTraceSetting = ini_set('zend.exception_ignore_args', '0');

    try {
        app(GoogleWallet::class)->provision($this->business, $this->credentials);
        test()->fail('Invalid credentials must stop issuance.');
    } catch (WalletIssuanceException $exception) {
        expect($exception->stage)->toBe('configuration')
            ->and($exception->outcome)->toBe('definitive')
            ->and($exception->getPrevious())->toBeNull()
            ->and((string) $exception)->not->toContain('unsafe-secret-marker', 'unsafe-private-path', $this->serviceAccount['private_key']);
    } finally {
        ini_set('zend.exception_ignore_args', $previousTraceSetting);
    }
    Http::assertNothingSent();
})->with([
    'missing path', 'wrong path type', 'empty path', 'directory path', 'NUL path',
    'malformed JSON', 'scalar JSON', 'wrong account type', 'missing account type',
    'wrong email type', 'invalid email syntax', 'invalid PEM', 'missing email', 'wrong key type', 'missing key', 'empty key',
]);

it('accepts only exact loopback app origins locally with an independent public logo', function (string $origin, string $expected) {
    app()->instance('env', 'local');
    config([
        'app.url' => $origin,
        'services.google_wallet.logo_url' => 'https://assets.example.test/platform.png',
    ]);
    $class = $this->class;
    $class['programLogo']['sourceUri']['uri'] = 'https://assets.example.test/platform.png';
    Http::fake(walletResponses($class, $this->object));

    $result = app(GoogleWallet::class)->provision($this->business, $this->credentials);

    $claims = walletVerifiedClaims(
        substr($result['save_url'], strlen('https://pay.google.com/gp/v/save/')),
        $this->key,
    );
    expect($claims['origins'])->toBe([$expected]);
    Http::assertSentCount(3);
})->with([
    ['http://localhost', 'http://localhost'],
    ['http://localhost:8080/', 'http://localhost:8080'],
    ['https://localhost:8443', 'https://localhost:8443'],
    ['http://127.0.0.1:8000', 'http://127.0.0.1:8000'],
    ['http://[::1]:8080', 'http://[::1]:8080'],
    ['https://[::1]', 'https://[::1]'],
]);

it('rejects unsafe local origins and loopback origins outside local development', function (string $environment, string $origin) {
    app()->instance('env', $environment);
    config(['app.url' => $origin, 'services.google_wallet.logo_url' => 'https://assets.example.test/platform.png']);

    expect(fn () => app(GoogleWallet::class)->provision($this->business, $this->credentials))
        ->toThrow(WalletIssuanceException::class);
    Http::assertNothingSent();
})->with([
    ['production', 'http://localhost:8080'],
    ['production', 'https://localhost'],
    ['production', 'https://127.0.0.1'],
    ['production', 'https://[::1]'],
    ['testing', 'http://localhost'],
    ['local', 'http://192.168.1.5'],
    ['local', 'http://127.0.0.2'],
    ['local', 'http://passes.test'],
    ['local', 'http://localhost.example.test'],
    ['local', 'http://localhost:0'],
    ['local', 'http://localhost:65536'],
    ['local', 'http://user:secret@localhost'],
    ['local', 'http://localhost/path'],
    ['local', 'http://localhost?query=secret'],
    ['local', 'http://localhost#secret'],
]);

it('requires a public HTTPS logo independently of the permitted local origin', function (mixed $logo) {
    app()->instance('env', 'local');
    config(['app.url' => 'http://localhost:8080', 'services.google_wallet.logo_url' => $logo]);

    try {
        app(GoogleWallet::class)->provision($this->business, $this->credentials);
        test()->fail('A local app must not send a local or missing program logo.');
    } catch (WalletIssuanceException $exception) {
        expect($exception->stage)->toBe('configuration')
            ->and($exception->reason)->toBe('logo_url_invalid')
            ->and($exception->getPrevious())->toBeNull();
    }
    Http::assertNothingSent();
})->with([null, '', 'http://localhost/logo.png', 'https://localhost/logo.png', 'https://127.0.0.1/logo.png', 'http://assets.example.test/logo.png', ['unsafe-logo']]);

it('preserves nondefault HTTPS production ports and the public logo fallback', function () {
    config(['app.url' => 'https://passes.example.test:8443/']);
    $class = $this->class;
    $class['programLogo']['sourceUri']['uri'] = 'https://passes.example.test:8443/logo.png';
    Http::fake(walletResponses($class, $this->object));

    $result = app(GoogleWallet::class)->provision($this->business, $this->credentials);

    $claims = walletVerifiedClaims(substr($result['save_url'], strlen('https://pay.google.com/gp/v/save/')), $this->key);
    expect($claims['origins'])->toBe(['https://passes.example.test:8443']);
});

it('rejects malformed OAuth responses before any Wallet request', function (array $body) {
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response($body)]);

    try {
        app(GoogleWallet::class)->provision($this->business, $this->credentials);
        test()->fail('Malformed authentication must stop issuance.');
    } catch (WalletIssuanceException $exception) {
        expect($exception->outcome)->toBe('definitive')->and($exception->stage)->toBe('oauth')
            ->and($exception->reason)->toBe('malformed_response');
    }
    Http::assertSentCount(1);
})->with([
    'missing token' => [[]],
    'invalid token' => [['access_token' => "unsafe\nheader", 'token_type' => 'Bearer']],
    'invalid type' => [['access_token' => 'test-token', 'token_type' => ['unsafe-provider-body']]],
]);

it('keeps pre-object transport failure definitive and strips unsafe transport details', function (string $stage) {
    $responses = walletResponses($this->class, $this->object);
    $responses[$stage === 'oauth' ? 'https://oauth2.googleapis.com/token' : 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/'.$this->classId]
        = Http::failedConnection('unsafe-provider-body '.$this->credentials->validationToken());
    Http::fake($responses);
    $previousTraceSetting = ini_set('zend.exception_ignore_args', '0');

    try {
        app(GoogleWallet::class)->provision($this->business, $this->credentials);
        test()->fail('Transport failure must stop issuance.');
    } catch (WalletIssuanceException $exception) {
        expect($exception->outcome)->toBe('definitive')->and($exception->stage)->toBe($stage)
            ->and($exception->getPrevious())->toBeNull()
            ->and((string) $exception)->not->toContain('unsafe-provider-body', $this->credentials->validationToken(), 'test-access-token');
    } finally {
        ini_set('zend.exception_ignore_args', $previousTraceSetting);
    }
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject'));
})->with(['oauth', 'class']);

it('rejects unapproved or conflicting class conflict readback without posting an object', function (array $overrides) {
    $responses = walletResponses($this->class, $this->object);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/'.$this->classId]
        = Http::sequence()->push([], 404)->push([...$this->class, ...$overrides]);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass'] = Http::response([], 409);
    Http::fake($responses);

    expect(fn () => app(GoogleWallet::class)->provision($this->business, $this->credentials))->toThrow(WalletIssuanceException::class);
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject'));
})->with([
    'unapproved' => [['reviewStatus' => 'underReview']],
    'foreign issuer' => [['id' => '999.business_foreign']],
]);

it('requires a matching approved partial patch response before posting an object', function (array $overrides) {
    $responses = walletResponses($this->class, $this->object);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/'.$this->classId]
        = Http::sequence()->push([...$this->class, 'hexBackgroundColor' => '#000000'])->push([...$this->class, ...$overrides]);
    Http::fake($responses);

    expect(fn () => app(GoogleWallet::class)->provision($this->business, $this->credentials))->toThrow(WalletIssuanceException::class);
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject'));
})->with([
    'still stale' => [['hexBackgroundColor' => '#000000']],
    'not approved' => [['reviewStatus' => 'underReview']],
]);

it('retains provider progress on matching replay instead of patching the object', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject'] = Http::response([], 409);
    $responses['https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/'.$this->objectId]
        = Http::response([...$this->object, 'loyaltyPoints' => ['balance' => ['int' => 5]]]);
    Http::fake($responses);

    app(GoogleWallet::class)->provision($this->business, $this->credentials);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH' && str_contains($request->url(), '/loyaltyObject/'));
});

/**
 * Supplies only the pinned OAuth/Class/Object routes for one synthetic issuance.
 *
 * @param  array<string, mixed>  $class  Expected approved Business Class.
 * @param  array<string, mixed>  $object  Expected immutable private Object binding.
 * @return array<string, mixed> Exact-route HTTP fakes; unlisted routes remain forbidden.
 */
function walletResponses(array $class, array $object): array
{
    return [
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access-token', 'token_type' => 'Bearer']),
        'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/'.$class['id'] => Http::response($class),
        'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject' => Http::response($object, 201),
    ];
}

/**
 * Verifies a native RS256 signature before inspecting generated claim semantics.
 *
 * @param  string  $jwt  Synthetic signed OAuth or Save assertion.
 * @param  OpenSSLAsymmetricKey  $key  Ephemeral test signing key, never provider credentials.
 * @return array<string, mixed> Cryptographically verified claim set.
 */
function walletVerifiedClaims(string $jwt, OpenSSLAsymmetricKey $key): array
{
    [$header, $payload, $signature] = explode('.', $jwt);
    expect(json_decode(base64_decode(strtr($header, '-_', '+/')), true))->toMatchArray(['alg' => 'RS256', 'typ' => 'JWT']);
    expect(openssl_verify($header.'.'.$payload, base64_decode(strtr($signature, '-_', '+/')), openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256))->toBe(1);

    return json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
}
