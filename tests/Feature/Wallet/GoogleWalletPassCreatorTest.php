<?php

use App\Enums\CustomerPassCreationFailure;
use App\Enums\FailureOutcome;
use App\Exceptions\CustomerPassCreationException;
use App\Integrations\GoogleWallet\GoogleWalletClient;
use App\Integrations\GoogleWallet\GoogleWalletPassCreator;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Support\CustomerPassCredentials;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\GoogleWalletHttpFake;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->setLocale('es');

    $this->http = new GoogleWalletHttpFake;
    app()->instance(GoogleWalletClient::class, new GoogleWalletClient($this->http->client));

    $this->key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($this->key, $pem);

    // use generated signing material, never deployment credentials
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

    $this->business = Business::factory()->create([
        'name' => 'Panadería real',
        'pass_background_color' => '#123456',
    ]);
    $this->credentials = CustomerPassCredentials::prepare($this->business);
    $this->classId = '123456.business_'.$this->business->public_id;
    $this->objectId = '123456.pass_'.$this->credentials->publicPassId;
    $this->classUrl = 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass';
    $this->objectUrl = 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject';

    $this->class = [
        'id' => $this->classId,
        'issuerName' => 'FidelitoPass',
        'programName' => $this->business->name,
        'programLogo' => [
            'sourceUri' => ['uri' => 'https://passes.example.test/logo.png'],
        ],
        'hexBackgroundColor' => '#123456',
        'classTemplateInfo' => [
            'cardTemplateOverride' => [
                'cardRowTemplateInfos' => [
                    [
                        'oneItem' => [
                            'item' => [
                                'firstValue' => [
                                    'fields' => [
                                        ['fieldPath' => "object.textModulesData['card_primary']"],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'oneItem' => ['item' => ['firstValue' => ['fields' => [
                            ['fieldPath' => "object.textModulesData['card_secondary']"],
                        ]]]],
                    ],
                ],
            ],
            'cardBarcodeSectionDetails' => [
                'firstBottomDetail' => null,
                'firstTopDetail' => [
                    'fieldSelector' => [
                        'fields' => [
                            ['fieldPath' => "object.textModulesData['barcode_top'].body"],
                        ],
                    ],
                ],
            ],
            'detailsTemplateOverride' => [
                'detailsItemInfos' => array_map(static fn (array $fields): array => [
                    'item' => ['firstValue' => ['fields' => $fields]],
                ], [
                    [
                        ['fieldPath' => "object.textModulesData['barcode_top']"],
                    ],
                    [
                        ['fieldPath' => "object.textModulesData['details_code']"],
                    ],
                    [
                        ['fieldPath' => "object.textModulesData['details_primary']"],
                        ['fieldPath' => "object.textModulesData['card_primary']"],
                    ],
                    [['fieldPath' => "object.textModulesData['details_primary_description']"]],
                    [['fieldPath' => "object.textModulesData['details_primary_date']"]],
                    [['fieldPath' => "object.textModulesData['details_context']"]],
                    [['fieldPath' => "object.textModulesData['details_secondary']"], ['fieldPath' => "object.textModulesData['card_secondary']"]],
                    [['fieldPath' => "object.textModulesData['details_secondary_description']"]],
                    [['fieldPath' => "object.textModulesData['details_secondary_date']"]],
                    [['fieldPath' => "object.textModulesData['details_history']"]],
                ]),
            ],
        ],
        'reviewStatus' => 'approved',
    ];

    $this->object = [
        'id' => $this->objectId,
        'classId' => $this->classId,
        'barcode' => [
            'alternateText' => 'Código manual: '.$this->credentials->manualCode,
            'type' => 'QR_CODE',
            'value' => $this->credentials->validationToken,
        ],
        'accountId' => $this->credentials->manualCode,
    ];
});

afterEach(function () {
    unlink($this->credentialsPath);
});

it('signs a private existing-object handoff without creating a local pass', function () {
    $this->http->respondWith(walletResponses($this->class, $this->object));

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
    expect(CustomerPass::count())->toBe(0);

    $saveToWalletJwt = substr($result['save_url'], strlen('https://pay.google.com/gp/v/save/'));
    $saveClaims = walletVerifiedClaims($saveToWalletJwt, $this->key);

    expect($saveClaims)->toMatchArray([
        'iss' => 'wallet@platform.iam.gserviceaccount.com',
        'aud' => 'google',
        'typ' => 'savetowallet',
        'origins' => ['https://passes.example.test'],
        'payload' => [
            'loyaltyObjects' => [
                [
                    'id' => $this->objectId,
                    'classId' => $this->classId,
                ],
            ],
        ],
    ]);

    // the handoff references the confirmed object without copying its private credentials
    expect(json_encode($saveClaims))->not->toContain(
        $this->credentials->validationToken,
        $this->credentials->manualCode,
    );
});

it('preserves a leading-zero manual code through SDK payload and exact confirmation', function (bool $normalizeProviderCode) {
    // this native deterministic seed draws 5130; allocation must retain the leading zero
    $credentials = CustomerPassCredentials::prepare(
        $this->business,
        new Randomizer(new Mt19937(6)),
    );
    expect($credentials->manualCode)->toBe('05130');
    $object = [
        'id' => '123456.pass_'.$credentials->publicPassId,
        'classId' => $this->classId,
        'accountId' => $normalizeProviderCode ? '05-130' : '05130',
        'barcode' => [
            'type' => 'QR_CODE',
            'value' => $credentials->validationToken,
        ],
    ];
    $this->http->respondWith(walletResponses($this->class, $object));

    if ($normalizeProviderCode) {
        try {
            app(GoogleWalletPassCreator::class)->create($this->business, $credentials);
            $this->fail('Provider credentials must match exactly, not normalize like manual lookup.');
        } catch (CustomerPassCreationException $exception) {
            expect($exception->failure)->toBe(CustomerPassCreationFailure::BindingConflict);
            expect($exception->context['binding_field'])->toBe('accountId');
        }
    } else {
        $result = app(GoogleWalletPassCreator::class)->create($this->business, $credentials);
        expect($result['object_id'])->toBe($object['id']);
        expect($result['confirmed_by_readback'])->toBeFalse();
    }

    $requests = $this->http->requests(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject'));
    expect($requests)->toHaveCount(1);
    expect($requests[0][0]['accountId'])->toBe('05130');
    expect($requests[0][0]['barcode']['alternateText'])->toBe('Código manual: 05130');
    expect(collect($requests[0][0]['textModulesData'])->firstWhere('id', 'details_code')['body'])->toBe('05130');
    $this->assertDatabaseCount('customer_passes', 0);
})->with(['exact' => false, 'manual-style separator rejected' => true]);

it('authenticates with a verifiable scoped OAuth assertion and form body', function () {
    $this->http->respondWith(walletResponses($this->class, $this->object));

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $authenticationRequests = $this->http->requests(
        fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token',
    );
    expect($authenticationRequests)->not->toBeEmpty();

    foreach ($authenticationRequests as [$request]) {
        $claims = walletVerifiedClaims($request['assertion'], $this->key);

        expect($claims)->toMatchArray([
            'iss' => 'wallet@platform.iam.gserviceaccount.com',
            'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
            'aud' => 'https://oauth2.googleapis.com/token',
        ]);

        expect($request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'))->toBeTrue();
        expect($request['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer');
    }
});

it('sends an authenticated empty class GET and a private JSON object POST', function () {
    $this->http->respondWith(walletResponses($this->class, $this->object));

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $classReads = $this->http->requests(
        fn (Request $request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/loyaltyClass/'.$this->classId),
    );
    expect($classReads)->not->toBeEmpty();

    foreach ($classReads as [$request]) {
        expect($request->body())->toBe('');
        expect($request->hasHeader('Authorization', 'Bearer test-access-token'))->toBeTrue();
        expect($request->hasHeader('Accept', 'application/json'))->toBeTrue();
    }

    $objectWrites = $this->http->requests(
        fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/loyaltyObject'),
    );
    expect($objectWrites)->not->toBeEmpty();

    foreach ($objectWrites as [$request]) {
        expect($request->hasHeader('Content-Type', 'application/json'))->toBeTrue();
        expect(json_decode($request->body(), true))->toMatchArray([
            'state' => 'ACTIVE',
            'accountId' => $this->credentials->manualCode,
            'barcode' => [
                'alternateText' => 'Código manual: '.$this->credentials->manualCode,
                'type' => 'QR_CODE',
                'value' => $this->credentials->validationToken,
            ],
        ]);
    }

    expect($this->http->requests())->toHaveCount(3);
});

it('creates a missing deterministic class once with the shared layout before the object', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = GoogleWalletHttpFake::json([], 404);
    $responses[$this->classUrl] = GoogleWalletHttpFake::json($this->class, 201);
    $this->http->respondWith($responses);

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($this->http->requests(fn (Request $request) => str_ends_with($request->url(), '/loyaltyClass')
        && $request['id'] === $this->classId
        && $request['programName'] === 'Panadería real'
        && $request['reviewStatus'] === 'UNDER_REVIEW'
        && $request['classTemplateInfo'] === $this->class['classTemplateInfo']))->not->toBeEmpty();
    $requests = $this->http->requests()->map(fn ($pair) => $pair[0]->url())->all();
    expect(array_search($this->classUrl, $requests))
        ->toBeLessThan(array_search($this->objectUrl, $requests));
});

it('uses the configured black logo in the native holder regardless of card background', function (string $background) {
    config([
        'services.google_wallet.logo_black_url' => 'https://assets.example.test/black.png',
    ]);
    $this->business->update(['pass_background_color' => $background]);
    $class = $this->class;
    $class['hexBackgroundColor'] = $background;
    $class['programLogo']['sourceUri']['uri'] = 'https://assets.example.test/black.png';
    $responses = walletResponses($class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = GoogleWalletHttpFake::json([], 404);
    $responses[$this->classUrl] = GoogleWalletHttpFake::json($class, 201);
    $this->http->respondWith($responses);

    expect(CustomerPass::count())->toBe(0);
    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($this->http->requests(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/loyaltyClass')
        && $request['programLogo']['sourceUri']['uri'] === 'https://assets.example.test/black.png'
        && $request['hexBackgroundColor'] === $background))->not->toBeEmpty();
    expect($this->http->requests(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject')
        && $request['barcode'] === $this->object['barcode']
        && $request['accountId'] === $this->object['accountId']))->not->toBeEmpty();
    expect(CustomerPass::count())->toBe(0);
})->with([
    'dark background' => ['#000000'],
    'light background' => ['#FFFFFF'],
    'lavender background' => ['#A77BFF'],
]);

it('replaces a native white logo with black without resetting a matching object', function () {
    config([
        'services.google_wallet.logo_black_url' => 'https://assets.example.test/black.png',
    ]);
    $class = $this->class;
    $class['programLogo']['sourceUri']['uri'] = 'https://assets.example.test/black.png';
    $previousClass = $this->class;
    $previousClass['programLogo']['sourceUri']['uri'] = 'https://assets.example.test/white.png';
    $responses = walletResponses($class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        GoogleWalletHttpFake::json([
            ...$previousClass,
            'homepageUri' => ['uri' => 'https://unrelated.example.test'],
        ]),
        GoogleWalletHttpFake::json($class),
    ]);
    $responses[$this->objectUrl] = GoogleWalletHttpFake::json([], 409);
    $responses[$this->objectUrl.'/'.$this->objectId]
        = GoogleWalletHttpFake::json([
            ...$this->object,
            'loyaltyPoints' => [
                'balance' => ['int' => 5],
            ],
        ]);
    $this->http->respondWith($responses);

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($this->http->requests(fn (Request $request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/loyaltyClass/')
        && $request['programLogo']['sourceUri']['uri'] === 'https://assets.example.test/black.png'
        && ! array_key_exists('homepageUri', $request->data())))->not->toBeEmpty();
    expect($this->http->requests(fn (Request $request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/loyaltyObject/')))->toBeEmpty();
});

it('ignores unused white logo configuration when selecting the black logo or its fallback', function (
    ?string $blackLogo,
    ?string $fallbackLogo,
    string $expectedLogo,
) {
    config([
        'services.google_wallet.logo_black_url' => $blackLogo,
        'services.google_wallet.logo_url' => $fallbackLogo,
        'services.google_wallet.logo_white_url' => 'http://unused.example.test/white.png',
    ]);
    $class = $this->class;
    $class['programLogo']['sourceUri']['uri'] = $expectedLogo;
    $responses = walletResponses($class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = GoogleWalletHttpFake::json([], 404);
    $responses[$this->classUrl] = GoogleWalletHttpFake::json($class, 201);
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
    expect($this->http->requests(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/loyaltyClass')
        && $request['programLogo']['sourceUri']['uri'] === $expectedLogo))->toHaveCount(1);
    expect($this->http->requests(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/loyaltyObject')))->toHaveCount(1);
    expect(CustomerPass::count())->toBe(0);
})->with([
    'black override' => [
        'https://assets.example.test/black.png',
        'http://unused.example.test/fallback.png',
        'https://assets.example.test/black.png',
    ],
    'configured fallback' => [
        null,
        'https://assets.example.test/platform.png',
        'https://assets.example.test/platform.png',
    ],
    'origin fallback' => [null, null, 'https://passes.example.test/logo.png'],
]);

it('rejects an unsafe selected black logo before credential loading or HTTP', function (mixed $invalid) {
    config([
        'services.google_wallet.logo_black_url' => $invalid,
        'services.google_wallet.credentials_path' => '/nonexistent/unsafe-logo-marker',
    ]);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('An unsafe selected black logo must stop issuance.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStage->value)->toBe('configuration');
        expect($exception->isRetryable())->toBeTrue();
        expect($exception->failure)->toBe(CustomerPassCreationFailure::LogoUrlInvalid);
        expect($exception->getPrevious())->toBeNull();
        expect((string) $exception)->not->toContain('unsafe-logo-marker');
    }

    expect($this->http->requests())->toBeEmpty();
})->with([
    'empty black' => [''],
    'wrong type' => [['unsafe-logo-marker']],
    'HTTP black' => ['http://assets.example.test/black.png'],
    'local black' => ['https://localhost/black.png'],
    'IP black' => ['https://127.0.0.1/black.png'],
    'internal black' => ['https://assets.internal/black.png'],
    'credential-bearing black' => ['https://unsafe-logo-marker@assets.example.test/black.png'],
    'query-bearing black' => ['https://assets.example.test/black.png?unsafe-logo-marker'],
    'fragment-bearing black' => ['https://assets.example.test/black.png#unsafe-logo-marker'],
]);

it('maps real current promotion content and zero new-pass progress into the provider object', function () {
    $this->business->promotions()
        ->make()
        ->forceFill([
            'status' => 'published',
            'target_points' => 7,
            'reward_title' => 'Una hogaza artesanal',
            'reward_description' => 'Pan integral de masa madre.',
            'timezone_snapshot' => 'America/La_Paz',
            'starts_at' => '2020-01-01 04:00:00+00',
            'ends_at' => '2099-01-01 04:00:00+00',
        ])
        ->save();
    $this->http->respondWith(walletResponses($this->class, $this->object));

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($this->http->requests(function (Request $request) {
        if (! str_ends_with($request->url(), '/loyaltyObject')) {
            return false;
        }

        expect($request->data())->not->toHaveKey('loyaltyPoints');
        expect(collect($request['textModulesData'])->firstWhere('id', 'barcode_top'))->toBe([
            'body' => '0 / 7 puntos',
            'header' => 'TU PROGRESO',
            'id' => 'barcode_top',
        ]);
        expect(collect($request['textModulesData'])->firstWhere('id', 'details_primary')['body'])->toBe('Una hogaza artesanal');
        expect(collect($request['textModulesData'])->firstWhere('id', 'details_primary_description')['body'])->toBe('Pan integral de masa madre.');
        expect(collect($request['textModulesData'])->firstWhere('id', 'details_context'))->toBe([
            'body' => 'Tu visita ahora vale 1 punto.', 'header' => '', 'id' => 'details_context',
        ]);
        expect(collect($request['textModulesData'])->firstWhere('id', 'card_primary'))->toBe([
            'body' => "Una hogaza artesanal\nTienes hasta el 31 dic 2098.\n\nTu visita ahora vale 1 punto.",
            'header' => 'TU PRÓXIMA RECOMPENSA',
            'id' => 'card_primary',
        ]);
        expect(collect($request['textModulesData'])->firstWhere('id', 'promotion'))->toBeNull();
        expect(json_encode($request->data(), JSON_UNESCAPED_UNICODE))->not->toContain('Consigue', '"Puntos"');
        expect(collect($request['textModulesData'])->firstWhere('id', 'details_primary_date')['body'])
            ->toBe('Tienes hasta el 31 dic 2098.');
        expect(collect($request['textModulesData'])->firstWhere('id', 'details_code'))
            ->toHaveCount(3)
            ->toMatchArray([
                'id' => 'details_code',
                'header' => 'CÓDIGO MANUAL',
                'body' => $this->credentials->manualCode,
            ]);

        return true;
    }))->not->toBeEmpty();
});

it('places progress above the barcode and compact content below it with separate full details', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        GoogleWalletHttpFake::json([], 404),
        GoogleWalletHttpFake::json($this->class),
    ]);
    $responses[$this->classUrl] = GoogleWalletHttpFake::json($this->class, 201);
    $this->http->respondWith($responses);

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $writes = $this->http->requests(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === $this->classUrl);
    expect($writes)->toHaveCount(1);
    $template = $writes[0][0]['classTemplateInfo'];
    expect(array_map(
        fn (array $row) => array_column($row['oneItem']['item']['firstValue']['fields'], 'fieldPath'),
        $template['cardTemplateOverride']['cardRowTemplateInfos'],
    ))->toBe([
        [
            "object.textModulesData['card_primary']",
        ],
        ["object.textModulesData['card_secondary']"],
    ]);
    expect($template['cardBarcodeSectionDetails'])->toBe([
        'firstBottomDetail' => null,
        'firstTopDetail' => [
            'fieldSelector' => [
                'fields' => [
                    ['fieldPath' => "object.textModulesData['barcode_top'].body"],
                ],
            ],
        ],
    ]);
    expect(array_map(
        fn (array $detail) => array_column($detail['item']['firstValue']['fields'], 'fieldPath'),
        $template['detailsTemplateOverride']['detailsItemInfos'],
    ))->toBe([
        ["object.textModulesData['barcode_top']"],
        ["object.textModulesData['details_code']"],
        ["object.textModulesData['details_primary']", "object.textModulesData['card_primary']"],
        ["object.textModulesData['details_primary_description']"],
        ["object.textModulesData['details_primary_date']"],
        ["object.textModulesData['details_context']"],
        ["object.textModulesData['details_secondary']", "object.textModulesData['card_secondary']"],
        ["object.textModulesData['details_secondary_description']"],
        ["object.textModulesData['details_secondary_date']"],
        ["object.textModulesData['details_history']"],
    ]);
});

it('localizes credential labels through Laravel without changing their values', function () {
    app()->setLocale('en');
    app('translator')->addLines([
        'wallet.labels.manual_code' => 'Manual lookup code',
        'wallet.labels.barcode_alternate' => 'Lookup code: :code',
    ], 'en');
    $this->http->respondWith(walletResponses($this->class, $this->object));

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $writes = $this->http->requests(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === $this->objectUrl);
    expect($writes)->toHaveCount(1);
    $object = $writes[0][0]->data();

    expect($object['barcode'])->toBe([
        'alternateText' => 'Lookup code: '.$this->credentials->manualCode,
        'type' => 'QR_CODE',
        'value' => $this->credentials->validationToken,
    ]);
    expect($object['accountId'])->toBe($this->credentials->manualCode);
    expect(collect($object['textModulesData'])->firstWhere('id', 'details_code'))->toBe([
        'body' => $this->credentials->manualCode,
        'header' => 'Manual lookup code',
        'id' => 'details_code',
    ]);
    expect(app()->getLocale())->toBe('en');
    $this->assertDatabaseCount('customer_passes', 0);
});

it('keeps business overview and labelled manual digits without inventing progress or active sections', function () {
    $this->http->respondWith(walletResponses($this->class, $this->object));

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $writes = $this->http->requests(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === $this->objectUrl);
    expect($writes)->toHaveCount(1);
    $object = $writes[0][0]->data();
    expect($object)->not->toHaveKey('loyaltyPoints');
    expect(array_column($object['textModulesData'], 'id'))->toBe(['card_primary', 'card_secondary', 'details_code']);
    expect(collect($object['textModulesData'])->firstWhere('id', 'card_primary'))->toBe([
        'body' => "¡Qué bueno tenerte por aquí!\nAún no hay promociones anteriores.",
        'header' => 'NUESTRA ÚLTIMA PROMOCIÓN',
        'id' => 'card_primary',
    ]);
    expect($object['barcode'])->toBe([
        'alternateText' => 'Código manual: '.$this->credentials->manualCode,
        'type' => 'QR_CODE',
        'value' => $this->credentials->validationToken,
    ]);
    expect($this->http->requests(fn (Request $request) => $request->method() === 'PATCH'))->toBeEmpty();
});

it('patches a barcode-only template difference without changing any object progress', function (string $detail) {
    $class = $this->class;
    $class['classTemplateInfo']['cardBarcodeSectionDetails'][$detail]['fieldSelector']['fields'][0]['fieldPath'] = 'object.accountId';
    $responses = walletResponses($class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        GoogleWalletHttpFake::json($class),
        GoogleWalletHttpFake::json($this->class),
    ]);
    $this->http->respondWith($responses);

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $patches = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');
    expect($patches)->toHaveCount(1);
    expect($patches[0][0]->url())->toBe($this->classUrl.'/'.$this->classId);
    expect($patches[0][0]['classTemplateInfo'])->toBe($this->class['classTemplateInfo']);
    expect($patches[0][0]['reviewStatus'])->toBe('UNDER_REVIEW');
    expect($patches[0][0]->data()['classTemplateInfo']['cardBarcodeSectionDetails'])
        ->toHaveKey('firstBottomDetail', null);
})->with(['firstTopDetail', 'firstBottomDetail']);

it('repairs the current barcode progress reference without resetting existing nonzero object progress', function (string $layout) {
    $class = $this->class;
    $fields = &$class['classTemplateInfo']['cardBarcodeSectionDetails']['firstTopDetail']['fieldSelector']['fields'];
    $fields = match ($layout) {
        'missing reference' => [],
        'wrong reference' => [['fieldPath' => 'object.accountId']],
        'extra reference' => [...$fields, ['fieldPath' => 'object.accountId']],
    };
    unset($fields);

    $object = $this->object;
    $object['textModulesData'] = [
        ['id' => 'barcode_top', 'body' => '5 / 7 puntos'],
    ];
    $responses = walletResponses($class, $object);
    $responses[$this->objectUrl] = GoogleWalletHttpFake::json([], 409);
    $responses[$this->objectUrl.'/'.$this->objectId] = GoogleWalletHttpFake::json($object);
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        GoogleWalletHttpFake::json($class),
        GoogleWalletHttpFake::json($this->class),
    ]);
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
    $patches = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');
    expect($patches)->toHaveCount(1);
    expect($patches[0][0]->url())->toBe($this->classUrl.'/'.$this->classId);
    expect($patches[0][0]['classTemplateInfo'])->toBe($this->class['classTemplateInfo']);
    expect($patches[0][0]['reviewStatus'])->toBe('UNDER_REVIEW');
    expect($patches[0][0]->data())
        ->not->toHaveKey('loyaltyPoints')
        ->not->toHaveKey('textModulesData');
    $barcode = $patches[0][0]['classTemplateInfo']['cardBarcodeSectionDetails'];
    expect($barcode['firstTopDetail']['fieldSelector']['fields'])
        ->toBe([
            ['fieldPath' => "object.textModulesData['barcode_top'].body"],
        ]);
    expect($this->http->requests(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === $this->objectUrl.'/'.$this->objectId))->toHaveCount(1);
    expect($this->http->requests(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === $this->objectUrl))->toHaveCount(1);
})->with(['missing reference', 'wrong reference', 'extra reference']);

it('repairs an unexpected trailing barcode reference without updating objects', function (string $detail) {
    $class = $this->class;
    $class['classTemplateInfo']['cardBarcodeSectionDetails'][$detail]['fieldSelector']['fields'][]
        = ['fieldPath' => 'object.accountId'];
    $responses = walletResponses($class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        GoogleWalletHttpFake::json($class),
        GoogleWalletHttpFake::json($this->class),
    ]);
    $this->http->respondWith($responses);

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $patches = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');
    expect($patches)->toHaveCount(1);
    expect($patches[0][0]->url())->toBe($this->classUrl.'/'.$this->classId);
    expect($patches[0][0]['classTemplateInfo'])->toBe($this->class['classTemplateInfo']);
    expect($patches[0][0]['reviewStatus'])->toBe('UNDER_REVIEW');
})->with(['firstTopDetail', 'firstBottomDetail']);

it('reuses a business class for different private objects and isolates another business', function () {
    $seenClasses = [];
    $seenObjects = [];
    $this->http->respondWith(function (Request $request) use (&$seenClasses, &$seenObjects) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            return GoogleWalletHttpFake::json([
                'access_token' => 'test-access-token',
                'token_type' => 'Bearer',
            ]);
        }

        if ($request->method() === 'GET') {
            return isset($seenClasses[basename($request->url())])
                ? GoogleWalletHttpFake::json($seenClasses[basename($request->url())])
                : GoogleWalletHttpFake::json([], 404);
        }

        if (str_ends_with($request->url(), '/loyaltyClass')) {
            $seenClasses[$request['id']] = [
                ...$request->data(),
                'reviewStatus' => 'approved',
            ];

            return GoogleWalletHttpFake::json($seenClasses[$request['id']], 201);
        }

        $seenObjects[] = $request->data();

        return GoogleWalletHttpFake::json($request->data(), 201);
    });

    $googlePassCreator = app(GoogleWalletPassCreator::class);
    $googlePassCreator->create($this->business, $this->credentials);
    $googlePassCreator->create($this->business, CustomerPassCredentials::prepare($this->business));
    $otherBusiness = Business::factory()->create(['pass_background_color' => '#654321']);
    $googlePassCreator->create($otherBusiness, CustomerPassCredentials::prepare($otherBusiness));

    expect($seenClasses)->toHaveCount(2);
    expect($seenObjects)->toHaveCount(3);
    expect($seenObjects[0]['classId'])->toBe($seenObjects[1]['classId']);
    expect($seenObjects[2]['classId'])->not->toBe($seenObjects[0]['classId']);
    expect($seenObjects[0]['id'])->not->toBe($seenObjects[1]['id']);
    expect($seenObjects[0]['barcode']['value'])->not->toBe($seenObjects[1]['barcode']['value']);
});

it('reconciles concurrent class creation rather than trusting a conflict', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        GoogleWalletHttpFake::json([], 404),
        GoogleWalletHttpFake::json($this->class),
    ]);
    $responses[$this->classUrl] =
        GoogleWalletHttpFake::json(['private' => 'unsafe-provider-body'], 409);
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
    expect($this->http->requests())->toHaveCount(5);

    $classReads = $this->http->requests(fn (Request $request) => $request->method() === 'GET'
        && str_ends_with($request->url(), '/loyaltyClass/'.$this->classId));

    expect($classReads)->toHaveCount(2);

    foreach ($classReads as [$request]) {
        expect($request->body())->toBe('');
    }
});

it('patches only application-owned class appearance and preserves unrelated fields', function (bool $newClass) {
    $responses = walletResponses($this->class, $this->object);
    $outdatedClass = [
        ...$this->class,
        'programName' => 'Old name',
        'homepageUri' => ['uri' => 'https://unrelated.example.test'],
    ];
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        $newClass ? GoogleWalletHttpFake::json([], 404) : GoogleWalletHttpFake::json($outdatedClass),
        GoogleWalletHttpFake::json($this->class),
    ]);
    $responses[$this->classUrl] = GoogleWalletHttpFake::json($outdatedClass, 201);
    $this->http->respondWith($responses);

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($this->http->requests(function (Request $request) {
        if ($request->method() !== 'PATCH') {
            return false;
        }

        expect($request->hasHeader('Content-Type', 'application/json'))->toBeTrue();
        expect(json_decode($request->body(), true))->toMatchArray([
            'programName' => 'Panadería real',
            'reviewStatus' => 'UNDER_REVIEW',
        ])->not->toHaveKey('homepageUri');

        return true;
    }))->not->toBeEmpty();
})->with(['existing class' => false, 'new class' => true]);

it('submits an approved class update for provider review before object creation', function () {
    $outdatedClass = [...$this->class, 'programName' => 'Previous name'];
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = function (Request $request) use ($outdatedClass) {
        if ($request->method() === 'GET') {
            return GoogleWalletHttpFake::json($outdatedClass);
        }

        if ($request['reviewStatus'] !== 'UNDER_REVIEW') {
            return GoogleWalletHttpFake::json(['error' => ['code' => 400]], 400);
        }

        return GoogleWalletHttpFake::json($this->class);
    };
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
    expect($this->http->requests(fn (Request $request) => $request->method() === 'PATCH'))->toHaveCount(1);
    expect($this->http->requests(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject')))->toHaveCount(1);
});

it('rejects unusable or conflicting classes before creating an object', function (array $overrides) {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = GoogleWalletHttpFake::json([
        ...$this->class,
        ...$overrides,
    ]);
    $this->http->respondWith($responses);

    expect(fn () => app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials))
        ->toThrow(CustomerPassCreationException::class);
    expect($this->http->requests(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject')))->toBeEmpty();
})->with([
    'draft' => [['reviewStatus' => 'draft']],
    'pending' => [['reviewStatus' => 'underReview']],
    'foreign id' => [['id' => '999.business_foreign']],
]);

it('reconciles uncertain object creation using all private immutable binding fields', function (int $status) {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->objectUrl] =
        GoogleWalletHttpFake::json(['private' => 'unsafe-provider-body'], $status);
    $responses[$this->objectUrl.'/'.$this->objectId] =
        GoogleWalletHttpFake::json($this->object);
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
    expect($this->http->requests())->toHaveCount(4);
    expect($this->http->requests(function (Request $request) {
        if ($request->method() !== 'GET' || ! str_ends_with($request->url(), $this->objectId)) {
            return false;
        }

        expect($request->body())->toBe('');

        return true;
    }))->not->toBeEmpty();
})->with([202, 204, 408, 409, 429, 500, 503]);

it('reconciles a timeout without blindly repeating object creation', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->objectUrl] = new ConnectException(
        'unsafe-token-and-request-body',
        new PsrRequest('POST', $this->objectUrl),
    );
    $responses[$this->objectUrl.'/'.$this->objectId] =
        GoogleWalletHttpFake::json($this->object);
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
});

it('keeps absence or failed reconciliation unknown after a possibly accepted object post', function (int $status) {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->objectUrl] = GoogleWalletHttpFake::json([], 503);
    $responses[$this->objectUrl.'/'.$this->objectId] =
        GoogleWalletHttpFake::json(['unsafe' => 'unsafe-provider-body'], $status);
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Unknown issuance must not return a handoff.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->failure)->toBe(CustomerPassCreationFailure::ConfirmationUnavailable);
        expect($exception->outcome)->toBe(FailureOutcome::Unknown);
        expect($exception->providerStage->value)->toBe('object');
    }

    expect($this->http->requests())->toHaveCount(4);
})->with([404, 403, 500]);

it('rejects a mismatched reconciled private binding', function (string $field) {
    $object = $this->object;

    if ($field === 'barcode') {
        $object['barcode']['value'] = 'foreign-private-token';
    } else {
        $object[$field] = 'foreign-value';
    }

    $responses = walletResponses($this->class, $this->object);
    $responses[$this->objectUrl] = GoogleWalletHttpFake::json([], 409);
    $responses[$this->objectUrl.'/'.$this->objectId] =
        GoogleWalletHttpFake::json($object);
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Conflicting binding must fail.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->isRetryable())->toBeTrue();
        expect($exception->failure)->toBe(CustomerPassCreationFailure::BindingConflict);
    }
})->with(['id', 'classId', 'accountId', 'barcode']);

it('sanitizes definitive provider rejection without retaining unsafe exception context', function (string $stage, int $status) {
    $responses = walletResponses($this->class, $this->object);
    $url = match ($stage) {
        'oauth' => 'https://oauth2.googleapis.com/token',
        'class' => $this->classUrl.'/'.$this->classId,
        'object' => $this->objectUrl,
    };
    $responses[$url] = GoogleWalletHttpFake::json([
        'error' => 'unsafe-provider-body '.$this->credentials->validationToken,
    ], $status);
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Provider rejection must fail.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->outcome)->toBe(FailureOutcome::Definitive);
        expect($exception->isRetryable())->toBeTrue();
        expect($exception->providerStage->value)->toBe($stage);
        expect($exception->getPrevious())->toBeNull();
        expect((string) $exception)->not->toContain(
            'unsafe-provider-body',
            $this->credentials->validationToken,
            'test-access-token',
        );
    }
})->with([
    ['oauth', 400],
    ['oauth', 401],
    ['class', 403],
    ['object', 400],
    ['object', 403],
]);

it('does not treat malformed object success as confirmed and safely reconciles it', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->objectUrl] =
        GoogleWalletHttpFake::json(['id' => $this->objectId], 201);
    $responses[$this->objectUrl.'/'.$this->objectId] =
        GoogleWalletHttpFake::json([], 404);
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Incomplete confirmation must stay unknown.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->failure)->toBe(CustomerPassCreationFailure::ConfirmationUnavailable);
        expect($exception->outcome)->toBe(FailureOutcome::Unknown);
    }
});

it('fails closed before HTTP for invalid configuration or signing material', function (string $case) {
    match ($case) {
        'issuer' => config(['services.google_wallet.issuer_id' => '']),
        'email' => file_put_contents($this->credentialsPath, json_encode([
            ...$this->serviceAccount,
            'client_email' => 'not-an-account',
        ])),
        'origin' => config(['app.url' => 'http://unsafe.example.test']),
        'origin userinfo' => config(['app.url' => 'https://user:password@example.test']),
        'logo' => config(['services.google_wallet.logo_url' => 'http://unsafe.example.test/logo.png']),
        'timeout' => config(['services.google_wallet.timeout' => 0]),
        'missing key' => config(['services.google_wallet.credentials_path' => '/nonexistent/unsafe-private-path']),
        'invalid key' => file_put_contents($this->credentialsPath, json_encode([
            ...$this->serviceAccount,
            'private_key' => 'unsafe-invalid-private-key',
        ])),
        'weak key' => openssl_pkey_export(
            openssl_pkey_new(['private_key_bits' => 1024]),
            $pem,
        ) && file_put_contents($this->credentialsPath, json_encode([
            ...$this->serviceAccount,
            'private_key' => $pem,
        ])),
        'non-RSA key' => openssl_pkey_export(openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]), $pem) && file_put_contents($this->credentialsPath, json_encode([
            ...$this->serviceAccount,
            'private_key' => $pem,
        ])),
        'foreign business' => $this->business = Business::factory()->create(),
    };

    expect(fn () => app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials))
        ->toThrow(CustomerPassCreationException::class);
    expect($this->http->requests())->toBeEmpty();
})->with([
    'issuer',
    'email',
    'origin',
    'origin userinfo',
    'logo',
    'timeout',
    'missing key',
    'invalid key',
    'weak key',
    'non-RSA key',
    'foreign business',
]);

it('loads repository-relative service-account JSON afresh for each operation', function () {
    $relativePath = 'storage/framework/wallet-test-'.bin2hex(random_bytes(8)).'.json';
    $absolutePath = base_path($relativePath);
    file_put_contents($absolutePath, json_encode($this->serviceAccount));
    config(['services.google_wallet.credentials_path' => $relativePath]);
    $this->http->respondWith(walletResponses($this->class, $this->object));
    $googlePassCreator = app(GoogleWalletPassCreator::class);

    try {
        $googlePassCreator->create($this->business, $this->credentials);
        file_put_contents($absolutePath, json_encode([
            ...$this->serviceAccount,
            'client_email' => 'rotated@another-project.iam.gserviceaccount.com',
        ]));
        $result = $googlePassCreator->create($this->business, $this->credentials);

        $claims = walletVerifiedClaims(
            substr($result['save_url'], strlen('https://pay.google.com/gp/v/save/')),
            $this->key,
        );
        expect($claims['iss'])->toBe('rotated@another-project.iam.gserviceaccount.com');
        expect($this->http->requests())->toHaveCount(6);
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
        'wrong account type' => file_put_contents($this->credentialsPath, json_encode([
            ...$this->serviceAccount,
            'type' => 'authorized_user',
        ])),
        'missing account type' => file_put_contents($this->credentialsPath, json_encode(
            array_diff_key($this->serviceAccount, ['type' => true]),
        )),
        'wrong email type' => file_put_contents($this->credentialsPath, json_encode([
            ...$this->serviceAccount,
            'client_email' => ['unsafe-secret-marker'],
        ])),
        'invalid email syntax' => file_put_contents($this->credentialsPath, json_encode([
            ...$this->serviceAccount,
            'client_email' => 'wallet@invalid..iam.gserviceaccount.com',
        ])),
        'invalid PEM' => file_put_contents($this->credentialsPath, json_encode([
            ...$this->serviceAccount,
            'private_key' => 'unsafe-secret-marker',
        ])),
        'missing email' => file_put_contents($this->credentialsPath, json_encode(
            array_diff_key($this->serviceAccount, ['client_email' => true]),
        )),
        'wrong key type' => file_put_contents($this->credentialsPath, json_encode([
            ...$this->serviceAccount,
            'private_key' => ['unsafe-secret-marker'],
        ])),
        'missing key' => file_put_contents($this->credentialsPath, json_encode(
            array_diff_key($this->serviceAccount, ['private_key' => true]),
        )),
        'empty key' => file_put_contents($this->credentialsPath, json_encode([
            ...$this->serviceAccount,
            'private_key' => '',
        ])),
    };
    $previousTraceSetting = ini_set('zend.exception_ignore_args', '0');

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Invalid credentials must stop issuance.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStage->value)->toBe('configuration');
        expect($exception->isRetryable())->toBeTrue();
        expect($exception->getPrevious())->toBeNull();
        expect((string) $exception)->not->toContain(
            'unsafe-secret-marker',
            'unsafe-private-path',
            $this->serviceAccount['private_key'],
        );
    } finally {
        ini_set('zend.exception_ignore_args', $previousTraceSetting);
    }

    expect($this->http->requests())->toBeEmpty();
})->with([
    'missing path',
    'wrong path type',
    'empty path',
    'directory path',
    'NUL path',
    'malformed JSON',
    'scalar JSON',
    'wrong account type',
    'missing account type',
    'wrong email type',
    'invalid email syntax',
    'invalid PEM',
    'missing email',
    'wrong key type',
    'missing key',
    'empty key',
]);

it('accepts only exact loopback app origins locally with an independent public logo', function (string $origin, string $expected) {
    app()->instance('env', 'local');
    config([
        'app.url' => $origin,
        'services.google_wallet.logo_url' => 'https://assets.example.test/platform.png',
    ]);
    $class = $this->class;
    $class['programLogo']['sourceUri']['uri'] = 'https://assets.example.test/platform.png';
    $this->http->respondWith(walletResponses($class, $this->object));

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $claims = walletVerifiedClaims(
        substr($result['save_url'], strlen('https://pay.google.com/gp/v/save/')),
        $this->key,
    );
    expect($claims['origins'])->toBe([$expected]);
    expect($this->http->requests())->toHaveCount(3);
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
    config([
        'app.url' => $origin,
        'services.google_wallet.logo_url' => 'https://assets.example.test/platform.png',
    ]);

    expect(fn () => app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials))
        ->toThrow(CustomerPassCreationException::class);
    expect($this->http->requests())->toBeEmpty();
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
    config([
        'app.url' => 'http://localhost:8080',
        'services.google_wallet.logo_url' => $logo,
    ]);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('A local app must not send a local or missing program logo.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStage->value)->toBe('configuration');
        expect($exception->failure)->toBe(CustomerPassCreationFailure::LogoUrlInvalid);
        expect($exception->getPrevious())->toBeNull();
    }

    expect($this->http->requests())->toBeEmpty();
})->with([
    null,
    '',
    'http://localhost/logo.png',
    'https://localhost/logo.png',
    'https://127.0.0.1/logo.png',
    'http://assets.example.test/logo.png',
    ['unsafe-logo'],
]);

it('preserves nondefault HTTPS production ports and the public logo fallback', function () {
    config(['app.url' => 'https://passes.example.test:8443/']);
    $class = $this->class;
    $class['programLogo']['sourceUri']['uri'] = 'https://passes.example.test:8443/logo.png';
    $this->http->respondWith(walletResponses($class, $this->object));

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $claims = walletVerifiedClaims(
        substr($result['save_url'], strlen('https://pay.google.com/gp/v/save/')),
        $this->key,
    );
    expect($claims['origins'])->toBe(['https://passes.example.test:8443']);
});

it('rejects malformed OAuth responses before any Wallet request', function (array $body) {
    $this->http->respondWith(['https://oauth2.googleapis.com/token' => GoogleWalletHttpFake::json($body)]);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Malformed authentication must stop issuance.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->isRetryable())->toBeTrue();
        expect($exception->providerStage->value)->toBe('oauth');
        expect($exception->failure)->toBe(CustomerPassCreationFailure::MalformedResponse);
    }

    expect($this->http->requests())->toHaveCount(1);
})->with([
    'missing token' => [[]],
    'empty token' => [['access_token' => '']],
    'null token' => [['access_token' => null]],
]);

it('keeps pre-object transport failure definitive and strips unsafe transport details', function (string $stage) {
    $responses = walletResponses($this->class, $this->object);
    $failedEndpoint = $stage === 'oauth'
        ? 'https://oauth2.googleapis.com/token'
        : $this->classUrl.'/'.$this->classId;
    $responses[$failedEndpoint] = new ConnectException(
        'unsafe-provider-body '.$this->credentials->validationToken,
        new PsrRequest('POST', $this->objectUrl),
    );
    $this->http->respondWith($responses);
    $previousTraceSetting = ini_set('zend.exception_ignore_args', '0');
    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Transport failure must stop issuance.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->context)->toMatchArray([
            'http_method' => $stage === 'class' ? 'GET' : 'POST',
            'exception_class' => ConnectException::class,
        ]);
        expect($exception->failure)->toBe(CustomerPassCreationFailure::TransportFailed);
        expect($exception->outcome)->toBe(FailureOutcome::Definitive);
        expect($exception->isRetryable())->toBeTrue();
        expect($exception->providerStage->value)->toBe($stage);
        expect($exception->getPrevious())->toBeNull();
        expect((string) $exception)->not->toContain(
            'unsafe-provider-body',
            $this->credentials->validationToken,
            'test-access-token',
        );
    } finally {
        ini_set('zend.exception_ignore_args', $previousTraceSetting);
    }

    expect($this->http->requests(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject')))->toBeEmpty();
})->with(['oauth', 'class']);

it('rejects unapproved or conflicting class conflict readback without posting an object', function (array $overrides) {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId]
        = new MockHandler([
            GoogleWalletHttpFake::json([], 404),
            GoogleWalletHttpFake::json([
                ...$this->class,
                ...$overrides,
            ]),
        ]);
    $responses[$this->classUrl] = GoogleWalletHttpFake::json([], 409);
    $this->http->respondWith($responses);

    expect(fn () => app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials))
        ->toThrow(CustomerPassCreationException::class);
    expect($this->http->requests(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject')))->toBeEmpty();
})->with([
    'unapproved' => [['reviewStatus' => 'underReview']],
    'foreign issuer' => [['id' => '999.business_foreign']],
]);

it('requires the same approved class identity after patching appearance', function (array $overrides) {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId]
        = new MockHandler([
            GoogleWalletHttpFake::json([
                ...$this->class,
                'hexBackgroundColor' => '#000000',
            ]),
            GoogleWalletHttpFake::json([
                ...$this->class,
                ...$overrides,
            ]),
        ]);
    $this->http->respondWith($responses);

    expect(fn () => app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials))
        ->toThrow(CustomerPassCreationException::class);
    expect($this->http->requests(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject')))->toBeEmpty();
})->with([
    'foreign identity' => [['id' => 'foreign-class']],
    'not approved' => [['reviewStatus' => 'underReview']],
]);

it('retains provider progress on matching replay instead of patching the object', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->objectUrl] = GoogleWalletHttpFake::json([], 409);
    $responses[$this->objectUrl.'/'.$this->objectId]
        = GoogleWalletHttpFake::json([
            ...$this->object,
            'loyaltyPoints' => [
                'balance' => ['int' => 5],
            ],
        ]);
    $this->http->respondWith($responses);

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($this->http->requests(fn (Request $request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/loyaltyObject/')))->toBeEmpty();
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
        'https://oauth2.googleapis.com/token' => GoogleWalletHttpFake::json([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
        ]),
        'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/'.$class['id'] => GoogleWalletHttpFake::json($class),
        'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject' => GoogleWalletHttpFake::json($object, 201),
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
    $decodedHeader = json_decode(base64_decode(strtr($header, '-_', '+/')), true);
    $signatureBytes = base64_decode(strtr($signature, '-_', '+/'));
    $publicKey = openssl_pkey_get_details($key)['key'];

    expect($decodedHeader)->toMatchArray([
        'alg' => 'RS256',
        'typ' => 'JWT',
    ]);
    expect(openssl_verify($header.'.'.$payload, $signatureBytes, $publicKey, OPENSSL_ALGO_SHA256))->toBe(1);

    return json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
}

it('retains native failure status and class method without retaining provider payloads', function (string $method) {
    $secret = $this->credentials->validationToken;
    $body = [
        'error' => [
            'status' => 'INVALID_ARGUMENT',
            'message' => $secret,
            'details' => [
                [
                    '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                    'domain' => 'googleapis.com',
                    'reason' => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT',
                    'metadata' => ['private_key' => $secret],
                ],
                [
                    '@type' => 'type.googleapis.com/google.rpc.BadRequest',
                    'fieldViolations' => [
                        [
                            'field' => 'classTemplateInfo.cardTemplateOverride.cardRowTemplateInfos[0].oneItem.item.firstValue.fields[0].fieldPath',
                            'description' => $secret,
                        ],
                        [
                            'field' => $secret,
                            'description' => $secret,
                        ],
                    ],
                ],
            ],
        ],
    ];
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = match ($method) {
        'GET' => GoogleWalletHttpFake::json($body, 400),
        'POST' => GoogleWalletHttpFake::json([], 404),
        'PATCH' => new MockHandler([
            GoogleWalletHttpFake::json([
                ...$this->class,
                'hexBackgroundColor' => '#000000',
            ]),
            GoogleWalletHttpFake::json($body, 400),
        ]),
    };
    $responses[$this->classUrl] = GoogleWalletHttpFake::json($body, 400);
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Rejected classes must stop issuance.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStatus)->toBe(400);
        expect($exception->context)->toMatchArray([
            'http_method' => $method,
            'exception_class' => GoogleServiceException::class,
        ]);
        expect(json_encode($exception->context))->not->toContain($secret, 'description', 'metadata', 'private_key');
        expect($exception->getPrevious())->toBeNull();
    }

    expect($this->http->requests(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject')))->toBeEmpty();
})->with([
    'GET',
    'POST',
    'PATCH',
]);

it('rejects private echoes in every candidate provider diagnostic field', function (string $privateValue) {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = GoogleWalletHttpFake::json([
        'error' => [
            'status' => $privateValue,
            'message' => $privateValue,
            'details' => [
                [
                    '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                    'domain' => 'googleapis.com',
                    'reason' => $privateValue,
                ],
                [
                    '@type' => 'type.googleapis.com/google.rpc.BadRequest',
                    'fieldViolations' => [
                        [
                            'field' => $privateValue,
                            'reason' => $privateValue,
                            'description' => $privateValue,
                        ],
                    ],
                ],
                [
                    '@type' => $privateValue,
                    'domain' => $privateValue,
                    'reason' => $privateValue,
                    'metadata' => [$privateValue => $privateValue],
                ],
            ],
            'errors' => [
                [
                    'reason' => $privateValue,
                    'location' => $privateValue,
                    'message' => $privateValue,
                ],
            ],
        ],
    ], 400);
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Rejected class must fail.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStatus)->toBe(400);
        expect($exception->context)->toMatchArray(['http_method' => 'GET']);
        expect(json_encode($exception->context))->not->toContain($privateValue);
        expect($exception->context)->not->toHaveKeys([
            'provider_error_status',
            'provider_error_reasons',
            'provider_error_fields',
        ]);
    }

    expect($this->http->requests())->toHaveCount(2);
})->with([
    str_repeat('a', 64),
    'PRIVATE12345',
    'eyJhbGciOiJSUzI1NiJ9.private.signature',
    'https://pay.google.com/gp/v/save/private',
    '-----BEGIN PRIVATE KEY-----',
    '123456.pass_private',
]);

it('retains a write attempt when object confirmation fails without repeating the post', function (bool $transport) {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->objectUrl] = $transport
        ? new ConnectException(
            'private transport credentials',
            new PsrRequest('POST', $this->objectUrl),
        )
        : GoogleWalletHttpFake::json([], 503);
    $responses[$this->objectUrl.'/'.$this->objectId] =
        GoogleWalletHttpFake::json([], 404);
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Missing confirmation must remain unknown.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStatus)->toBe(404);
        expect($exception->context)->toMatchArray([
            'http_method' => 'GET',
            'confirmation_read' => true,
            'provider_attempt' => $transport
                ? [
                    'http_method' => 'POST',
                    'exception_class' => ConnectException::class,
                    'http_status' => null,
                ]
                : [
                    'http_method' => 'POST',
                    'http_status' => 503,
                    'exception_class' => GoogleServiceException::class,
                ],
        ]);
        expect($exception->failure)->toBe(CustomerPassCreationFailure::ConfirmationUnavailable);
        expect($exception->outcome)->toBe(FailureOutcome::Unknown);
        expect(json_encode($exception->context))->not->toContain('private transport credentials');
    }

    expect($this->http->requests())->toHaveCount(4);
    expect($this->http->requests(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/loyaltyObject')))->toHaveCount(1);
})->with([
    false,
    true,
]);

it('retains write evidence when the confirmation read itself fails in transport', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->objectUrl] = GoogleWalletHttpFake::json([], 409);
    $responses[$this->objectUrl.'/'.$this->objectId] = new ConnectException(
        'private confirmation error',
        new PsrRequest('POST', $this->objectUrl),
    );
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Unconfirmed object must remain unknown.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStatus)->toBeNull();
        expect($exception->context)->toMatchArray([
            'http_method' => 'GET',
            'confirmation_read' => true,
            'exception_class' => ConnectException::class,
            'provider_attempt' => [
                'http_method' => 'POST',
                'http_status' => 409,
                'exception_class' => GoogleServiceException::class,
            ],
        ]);
        expect($exception->failure)->toBe(CustomerPassCreationFailure::ConfirmationUnavailable);
        expect($exception->outcome)->toBe(FailureOutcome::Unknown);
        expect(json_encode($exception->context))->not->toContain('private confirmation error');
    }

    expect($this->http->requests())->toHaveCount(4);
});

it('keeps failed class conflict readback distinct from the prior class insert', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        GoogleWalletHttpFake::json([], 404),
        GoogleWalletHttpFake::json('not-json-private', 400),
    ]);
    $responses[$this->classUrl] = GoogleWalletHttpFake::json([], 409);
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Rejected class confirmation must fail.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStatus)->toBe(400);
        expect($exception->context)->toMatchArray([
            'http_method' => 'GET',
            'confirmation_read' => true,
            'provider_attempt' => [
                'http_method' => 'POST',
                'http_status' => 409,
            ],
        ]);
        expect($exception->failure)->toBe(CustomerPassCreationFailure::ProviderRejected);
        expect(json_encode($exception->context))->not->toContain('not-json-private');
    }

    expect($this->http->requests())->toHaveCount(4);
});

it('accepts owned appearance with documented image metadata and neutral template defaults', function (bool $patch) {
    $this->business->update(['pass_background_color' => '#AABBCC']);
    $class = $this->class;
    $class['hexBackgroundColor'] = '#abc';
    $class['programLogo']['kind'] = 'walletobjects#image';
    $class['programLogo']['sourceUri']['description'] = 'Provider metadata';
    $class['programLogo']['sourceUri']['localizedDescription'] = [
        'defaultValue' => ['value' => 'Metadata'],
    ];

    // Google may enrich the owned appearance with neutral response metadata
    foreach ($class['classTemplateInfo']['cardTemplateOverride']['cardRowTemplateInfos'] as &$row) {
        $row['oneItem']['item']['predefinedItem'] = 'PREDEFINED_ITEM_UNSPECIFIED';
        $row['oneItem']['item']['secondValue'] = ['fields' => []];
        $row['oneItem']['item']['firstValue']['fields'][0]['dateFormat'] = 'DATE_FORMAT_UNSPECIFIED';
    }
    unset($row);

    $responses = walletResponses($class, $this->object);

    if ($patch) {
        $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
            GoogleWalletHttpFake::json([
                ...$class,
                'programName' => 'Previous name',
            ]),
            GoogleWalletHttpFake::json($class),
        ]);
    }

    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
    expect($this->http->requests(fn (Request $request) => $request->method() === 'PATCH'))->toHaveCount($patch ? 1 : 0);
    expect($this->http->requests())->toHaveCount($patch ? 4 : 3);
})->with([
    'initial GET' => false,
    'single PATCH' => true,
]);

it('submits owned appearance without requiring a complete visual echo in the PATCH response', function () {
    $class = $this->class;
    $class['programName'] = 'Previous program name';
    unset($class['programLogo']);

    $responses = walletResponses($class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        GoogleWalletHttpFake::json($class),
        GoogleWalletHttpFake::json([
            'id' => $this->classId,
            'reviewStatus' => 'APPROVED',
        ]),
    ]);
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
    $patches = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');
    expect($patches)->toHaveCount(1);
    expect($patches[0][0]->data())->toMatchArray([
        'programName' => $this->business->name,
        'programLogo' => $this->class['programLogo'],
        'classTemplateInfo' => $this->class['classTemplateInfo'],
        'reviewStatus' => 'UNDER_REVIEW',
    ]);
    expect($this->http->requests())->toHaveCount(4);
});

it('repairs missing or changed primary layout references with one owned PATCH', function (string $change) {
    $class = $this->class;
    $rows = &$class['classTemplateInfo']['cardTemplateOverride']['cardRowTemplateInfos'];

    match ($change) {
        'row order' => $rows = array_reverse($rows),
        'missing row' => array_pop($rows),
        'extra row' => $rows[] = $rows[0],
        'missing reference' => $rows[0]['oneItem']['item']['firstValue']['fields'] = [],
        'different reference' => $rows[0]['oneItem']['item']['firstValue']['fields'][0]['fieldPath'] = 'other-field',
    };
    unset($rows);

    $responses = walletResponses($class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        GoogleWalletHttpFake::json($class),
        GoogleWalletHttpFake::json($this->class),
    ]);
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
    $patches = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');
    expect($patches)->toHaveCount(1);
    expect($patches[0][0]['classTemplateInfo'])->toBe($this->class['classTemplateInfo']);
    expect($this->http->requests())->toHaveCount(4);
})->with([
    'row order',
    'missing row',
    'extra row',
    'missing reference',
    'different reference',
]);

it('repairs changed full-detail selectors without exposing the compact summary twice', function (string $change) {
    $class = $this->class;
    $details = &$class['classTemplateInfo']['detailsTemplateOverride']['detailsItemInfos'];

    match ($change) {
        'order' => $details = array_reverse($details),
        'missing item' => array_pop($details),
        'summary reference' => $details[2]['item']['firstValue']['fields'][0]['fieldPath'] = "object.textModulesData['card_primary']",
        'state priority' => $details[2]['item']['firstValue']['fields'] = array_reverse($details[2]['item']['firstValue']['fields']),
        'extra reference' => $details[2]['item']['firstValue']['fields'][] = ['fieldPath' => 'object.accountId'],
    };
    unset($details);

    $responses = walletResponses($class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        GoogleWalletHttpFake::json($class),
        GoogleWalletHttpFake::json($this->class),
    ]);
    $this->http->respondWith($responses);

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $patches = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');
    expect($patches)->toHaveCount(1);
    expect($patches[0][0]->url())->toBe($this->classUrl.'/'.$this->classId);
    expect($patches[0][0]['classTemplateInfo'])->toBe($this->class['classTemplateInfo']);
})->with(['order', 'missing item', 'summary reference', 'state priority', 'extra reference']);

it('repairs reversed swipe-up detail order while preserving current object content and credentials', function () {
    $class = $this->class;
    $class['classTemplateInfo']['detailsTemplateOverride']['detailsItemInfos'] = array_reverse(
        $class['classTemplateInfo']['detailsTemplateOverride']['detailsItemInfos'],
    );
    $object = [
        ...$this->object,
        'textModulesData' => [
            ['id' => 'barcode_top', 'body' => '5 / 7 puntos'],
            ['id' => 'details_primary', 'header' => 'TU PRÓXIMA RECOMPENSA', 'body' => "Una hogaza artesanal\nPan integral de masa madre."],
        ],
    ];
    $responses = walletResponses($class, $object);
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        GoogleWalletHttpFake::json($class),
        GoogleWalletHttpFake::json($this->class),
    ]);
    $responses[$this->objectUrl] = GoogleWalletHttpFake::json([], 409);
    $responses[$this->objectUrl.'/'.$this->objectId] = GoogleWalletHttpFake::json($object);
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
    expect($result['confirmed_by_readback'])->toBeTrue();
    $patches = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');
    expect($patches)->toHaveCount(1);
    expect($patches[0][0]->url())->toBe($this->classUrl.'/'.$this->classId);
    expect($patches[0][0]['classTemplateInfo'])->toBe($this->class['classTemplateInfo']);
    expect($patches[0][0]->data())->not->toHaveKeys(['loyaltyPoints', 'textModulesData', 'barcode', 'accountId']);
    expect($this->http->requests(fn (Request $request) => in_array($request->method(), ['PATCH', 'PUT'], true)
        && str_contains($request->url(), '/loyaltyObject/')))->toBeEmpty();
    expect($this->http->requests(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === $this->objectUrl))->toHaveCount(1);
    expect($this->http->requests(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === $this->objectUrl.'/'.$this->objectId))->toHaveCount(1);
});

it('applies bounded TLS transport settings to official OAuth and Wallet requests', function () {
    $this->http->respondWith(walletResponses($this->class, $this->object));

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($this->http->history)->toHaveCount(3);
    foreach ($this->http->history as $entry) {
        expect($entry['options'])->toMatchArray([
            'connect_timeout' => 3,
            'timeout' => 10,
            'allow_redirects' => false,
            'http_errors' => true,
            'verify' => true,
        ]);
    }
});

it('discards secret-bearing transport exception responses without retrying the request', function () {
    $secret = $this->credentials->validationToken.' '.$this->credentials->manualCode;
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId]
        = new RequestException(
            'Private SDK failure '.$secret,
            new PsrRequest('GET', $this->classUrl.'/'.$this->classId),
            GoogleWalletHttpFake::json(['error' => $secret], 400),
        );
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('A transport failure must stop issuance.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->failure)->toBe(CustomerPassCreationFailure::ProviderRejected);
        expect($exception->providerStatus)->toBe(400);
        expect($exception->context)->toMatchArray([
            'http_method' => 'GET',
            'exception_class' => GoogleServiceException::class,
        ]);
        expect($exception->getPrevious())->toBeNull();
        expect((string) $exception)->not->toContain($secret, 'Private SDK failure');
    }

    expect($this->http->requests())->toHaveCount(2);
});

it('accepts documented approval and QR barcode spellings without changing private bindings', function (string $approval, string $barcode) {
    $class = [
        ...$this->class,
        'reviewStatus' => $approval,
    ];
    $object = $this->object;
    $object['barcode']['type'] = $barcode;
    $this->http->respondWith(walletResponses($class, $object));

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
    expect($result['confirmed_by_readback'])->toBeFalse();
    expect($this->http->requests())->toHaveCount(3);
})->with([
    'canonical approval' => ['APPROVED', 'QR_CODE'],
    'camel-case QR alias' => ['approved', 'qrCode'],
    'lowercase QR alias' => ['approved', 'qrcode'],
]);

it('accepts documented QR aliases during the single confirmation read', function (string $type) {
    $object = $this->object;
    $object['barcode']['type'] = $type;
    $object['loyaltyPoints'] = [
        'balance' => ['int' => 5],
    ];

    $responses = walletResponses($this->class, $this->object);
    $responses[$this->objectUrl] = GoogleWalletHttpFake::json([], 409);
    $responses[$this->objectUrl.'/'.$this->objectId] = GoogleWalletHttpFake::json($object);
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['confirmed_by_readback'])->toBeTrue();
    expect($result['object_id'])->toBe($this->objectId);
    expect($this->http->requests())->toHaveCount(4);
    expect($this->http->requests(
        fn (Request $request) => $request->method() === 'PATCH',
    ))->toBeEmpty();
})->with(['QR_CODE', 'qrCode', 'qrcode']);

it('rejects a present immutable binding mismatch without a fallback read', function (string $field, mixed $value) {
    $object = $this->object;
    data_set($object, $field, $value);
    $this->http->respondWith(walletResponses($this->class, $object));

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Conflicting private bindings must not produce a handoff.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->failure)->toBe(CustomerPassCreationFailure::BindingConflict);
        expect($exception->isRetryable())->toBeTrue();
        expect($exception->providerStatus)->toBeNull();
        expect($exception->context)->toMatchArray([
            'http_method' => 'POST',
            'binding_field' => $field,
            'binding_category' => is_string($value) ? 'different' : 'invalid',
        ]);
        expect($exception->getPrevious())->toBeNull();
        expect(json_encode($exception->context))->not->toContain(
            'private-conflict-marker',
            $this->credentials->validationToken,
            $this->credentials->manualCode,
        );
    }

    expect($this->http->requests())->toHaveCount(3);
    expect($this->http->requests(
        fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/loyaltyObject/'),
    ))->toBeEmpty();
})->with([
    'object identity' => ['id', 'private-conflict-marker'],
    'class identity' => ['classId', 'private-conflict-marker'],
    'manual code' => ['accountId', 'private-conflict-marker'],
    'manual code type' => ['accountId', 123],
    'QR credential' => ['barcode.value', 'private-conflict-marker'],
    'other barcode' => ['barcode.type', 'CODE_128'],
    'undocumented mixed case' => ['barcode.type', 'QrCode'],
]);

it('does not trim identity or credential values when normalizing provider enums', function (string $field) {
    $object = $this->object;
    data_set($object, $field, data_get($object, $field).' ');
    $this->http->respondWith(walletResponses($this->class, $object));

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Credential normalization must not hide a mismatch.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->failure)->toBe(CustomerPassCreationFailure::BindingConflict);
        expect($exception->context['binding_field'])->toBe($field);
    }

    expect($this->http->requests())->toHaveCount(3);
})->with(['id', 'classId', 'accountId', 'barcode.value']);

it('confirms malformed or incomplete insert models only through one same-ID read', function (string $body) {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->objectUrl] = GoogleWalletHttpFake::json($body, 200);
    $responses[$this->objectUrl.'/'.$this->objectId] = GoogleWalletHttpFake::json($this->object);
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['confirmed_by_readback'])->toBeTrue();
    expect($this->http->requests())->toHaveCount(4);
    expect($this->http->requests(
        fn (Request $request) => $request->method() === 'POST'
            && $request->url() === $this->objectUrl,
    ))->toHaveCount(1);
})->with([
    'invalid JSON' => '{invalid-json',
    'null model' => 'null',
    'list model' => '[]',
    'malformed barcode' => '{"barcode":"not-a-barcode"}',
]);

it('requires every immutable field in a confirmation model', function (string $field) {
    $object = $this->object;
    data_forget($object, $field);

    $responses = walletResponses($this->class, $this->object);
    $responses[$this->objectUrl] = GoogleWalletHttpFake::json([], 409);
    $responses[$this->objectUrl.'/'.$this->objectId] = GoogleWalletHttpFake::json($object);
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Missing confirmation must remain unknown.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->failure)->toBe(CustomerPassCreationFailure::ConfirmationUnavailable);
        expect($exception->outcome)->toBe(FailureOutcome::Unknown);
        expect($exception->isRetryable())->toBeFalse();
        expect($exception->context['confirmation_read'])->toBeTrue();
    }

    expect($this->http->requests())->toHaveCount(4);
})->with(['id', 'classId', 'accountId', 'barcode.type', 'barcode.value']);

it('accepts a complete native success model without inventing an HTTP success status', function (int $status) {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->objectUrl] = GoogleWalletHttpFake::json($this->object, $status);
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['confirmed_by_readback'])->toBeFalse();
    expect($result['object_id'])->toBe($this->objectId);
    expect($this->http->requests())->toHaveCount(3);
})->with([200, 201, 202]);

it('rejects redirects even when their body resembles a successful native model', function (string $stage) {
    $responses = walletResponses($this->class, $this->object);
    [$endpoint, $body, $requests] = match ($stage) {
        'oauth' => ['https://oauth2.googleapis.com/token', ['access_token' => 'private-token'], 1],
        'class' => [$this->classUrl.'/'.$this->classId, $this->class, 2],
        'object' => [$this->objectUrl, $this->object, 4],
    };
    $responses[$this->objectUrl.'/'.$this->objectId] = GoogleWalletHttpFake::json([], 404);
    $responses[$endpoint] = GoogleWalletHttpFake::json($body, 307)
        ->withHeader('Location', 'https://untrusted.example.test/private');
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('A redirect must not establish provider success.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStage->value)->toBe($stage);
        expect($exception->providerStatus)->toBe($stage === 'object' ? 404 : 307);

        if ($stage === 'object') {
            expect($exception->failure)->toBe(CustomerPassCreationFailure::ConfirmationUnavailable);
            expect($exception->outcome)->toBe(FailureOutcome::Unknown);
            expect($exception->isRetryable())->toBeFalse();
            expect($exception->context['provider_attempt'])->toBe([
                'http_method' => 'POST',
                'http_status' => 307,
                'exception_class' => GoogleServiceException::class,
            ]);
        }

        expect($exception->getPrevious())->toBeNull();
    }

    expect($this->http->requests())->toHaveCount($requests);
})->with(['oauth', 'class', 'object']);

it('fails malformed class models before creating an individual object', function (string $body) {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = GoogleWalletHttpFake::json($body);
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Malformed classes must not authorize an object write.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStage->value)->toBe('class');
        expect($exception->isRetryable())->toBeTrue();
        expect($exception->failure)->toBe(CustomerPassCreationFailure::ClassNotUsable);
    }

    expect($this->http->requests())->toHaveCount(2);
})->with(['{invalid-json', 'null', '[]']);

it('does not retry failed authentication or proceed to Wallet resources', function (int $status) {
    $this->http->respondWith([
        'https://oauth2.googleapis.com/token' => GoogleWalletHttpFake::json([
            'error' => 'private-provider-marker',
        ], $status),
    ]);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Authentication failure must stop provider work.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStage->value)->toBe('oauth');
        expect($exception->outcome)->toBe(FailureOutcome::Definitive);
        expect($exception->isRetryable())->toBeTrue();
        expect($exception->providerStatus)->toBe($status);
        expect((string) $exception)->not->toContain('private-provider-marker');
    }

    expect($this->http->requests())->toHaveCount(1);
})->with([400, 429, 500, 503]);

it('refuses unsafe SDK stdout logging before loading credentials or authenticating', function () {
    $previous = getenv('GOOGLE_SDK_PHP_LOGGING');
    putenv('GOOGLE_SDK_PHP_LOGGING=true');
    config(['services.google_wallet.credentials_path' => '/unread-private-credentials']);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('Unsafe SDK logging must prevent private provider traffic.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStage->value)->toBe('configuration');
        expect($exception->failure)->toBe(CustomerPassCreationFailure::UnsafeSdkLogging);
        expect($exception->getPrevious())->toBeNull();
    } finally {
        putenv($previous === false ? 'GOOGLE_SDK_PHP_LOGGING' : 'GOOGLE_SDK_PHP_LOGGING='.$previous);
    }

    expect($this->http->requests())->toBeEmpty();
});

it('classifies native status failures through the creation boundary without changing request counts', function (
    string $stage, string $method, int $status, CustomerPassCreationFailure $expected, FailureOutcome $outcome,
) {
    $responses = walletResponses($this->class, $this->object);
    if ($stage === 'class') {
        $responses[$this->classUrl.'/'.$this->classId] = GoogleWalletHttpFake::json([], 404);
        $responses[$this->classUrl] = GoogleWalletHttpFake::json(['error' => 'private status fixture'], $status);
    } else {
        $responses[$this->objectUrl] = GoogleWalletHttpFake::json([], $method === 'GET' ? 409 : $status);
        $responses[$this->objectUrl.'/'.$this->objectId] = GoogleWalletHttpFake::json(['error' => 'private status fixture'], $method === 'GET' ? $status : 404);
    }
    $this->http->respondWith($responses);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        $this->fail('Rejected or unconfirmed creation must not return a handoff.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->failure)->toBe($expected);
        expect($exception->outcome)->toBe($outcome);
        expect($exception->providerStage->value)->toBe($stage);
        expect($exception->providerStatus)->toBe($stage === 'object' && $method === 'POST' && $outcome === FailureOutcome::Unknown ? 404 : $status);
        expect($exception->getPrevious())->toBeNull();
        expect(json_encode($exception->context))->not->toContain('private status fixture');
    }

    expect($this->http->requests())->toHaveCount($stage === 'object' && ($method === 'GET' || $outcome === FailureOutcome::Unknown) ? 4 : 3);
})->with(function (): iterable {
    foreach ([302, 400, 408, 429, 500, 503] as $status) {
        yield 'class-'.$status => ['class', 'POST', $status, CustomerPassCreationFailure::ProviderRejected, FailureOutcome::Definitive];
    }
    foreach ([400, 401, 403, 404, 422] as $status) {
        yield 'object-rejected-'.$status => ['object', 'POST', $status, CustomerPassCreationFailure::ProviderRejected, FailureOutcome::Definitive];
    }
    foreach ([302, 408, 409, 429, 500, 503] as $status) {
        yield 'object-unknown-'.$status => ['object', 'POST', $status, CustomerPassCreationFailure::ConfirmationUnavailable, FailureOutcome::Unknown];
    }
    foreach ([400, 401, 403, 404, 503] as $status) {
        yield 'confirmation-'.$status => ['object', 'GET', $status, CustomerPassCreationFailure::ConfirmationUnavailable, FailureOutcome::Unknown];
    }
});
