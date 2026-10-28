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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\GoogleWalletHttpFake;

/** Guards native test cleanup before any isolated PostgreSQL reset. */
trait GuardsPermanentWalletCreationDatabase
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

uses(GuardsPermanentWalletCreationDatabase::class);

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
        'issuerName' => "\u{200B}",
        'programName' => $this->business->name,
        'programLogo' => [
            'sourceUri' => ['uri' => 'https://passes.example.test/icon-logo-wallet.png'],
        ],
        'hexBackgroundColor' => '#123456',
        'classTemplateInfo' => [
            'cardTemplateOverride' => null,
            'cardBarcodeSectionDetails' => ['firstTopDetail' => null, 'firstBottomDetail' => null],
            'detailsTemplateOverride' => null,
            'listTemplateOverride' => [
                'firstRowOption' => ['fieldOption' => ['fields' => [['fieldPath' => 'class.programName']]]],
                'secondRowOption' => ['fields' => [['fieldPath' => 'object.accountId']]],
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
    $this->assertDatabaseCount('customer_passes', 0);
})->with(['exact' => false, 'manual-style separator rejected' => true]);

it('clears shadowing localized names with real nulls without changing unrelated translations', function (bool $newClass) {
    $localized = ['defaultValue' => ['language' => 'es', 'value' => 'Nombre anterior']];
    $actual = [
        ...$this->class,
        'localizedIssuerName' => $localized,
        'localizedProgramName' => $localized,
        'localizedRewardsTier' => $localized,
    ];
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        $newClass ? GoogleWalletHttpFake::json([], 404) : GoogleWalletHttpFake::json($actual),
        GoogleWalletHttpFake::json($this->class),
    ]);
    $responses[$this->classUrl] = GoogleWalletHttpFake::json($actual, 201);
    $this->http->respondWith($responses);

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $patches = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');
    expect($patches)->toHaveCount(1);
    $wire = $patches[0][0];
    expect($wire->body())->toContain('"localizedIssuerName":null', '"localizedProgramName":null');
    expect(json_decode($wire->body(), true))
        ->toHaveKey('localizedIssuerName', null)
        ->toHaveKey('localizedProgramName', null)
        ->not->toHaveKeys(['localizedRewardsTier', 'valueAddedModuleData']);
    expect(bin2hex($wire['issuerName']))->toBe('e2808b');
    expect($wire['programName'])->toBe('Panadería real');
})->with(['existing overrides' => false, 'initial response overrides' => true]);

it('repairs legacy names or list-only differences without resetting Objects or unowned template fields', function (string $difference) {
    $actual = $this->class;
    if ($difference === 'legacy') {
        $actual['issuerName'] = $this->business->name;
        $actual['programName'] = 'Tarjeta de Puntos';
    } elseif ($difference === 'missing list') {
        unset($actual['classTemplateInfo']['listTemplateOverride']);
    } else {
        $actual['classTemplateInfo']['listTemplateOverride']['secondRowOption'] = [
            'fieldOption' => ['fields' => [['fieldPath' => 'object.accountId']]],
        ];
    }
    $actual['homepageUri'] = ['uri' => 'https://unrelated.example.test'];
    $actual['classTemplateInfo']['futureTemplate'] = ['keep' => true];
    $actual['classTemplateInfo']['listTemplateOverride']['thirdRowOption'] = [
        'fields' => [['fieldPath' => 'object.accountName']],
    ];
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = new MockHandler([
        GoogleWalletHttpFake::json($actual),
        // Google, not the submitted UNDER_REVIEW payload, owns the approval response
        GoogleWalletHttpFake::json($this->class),
    ]);
    $responses[$this->objectUrl] = GoogleWalletHttpFake::json([], 409);
    $responses[$this->objectUrl.'/'.$this->objectId] = GoogleWalletHttpFake::json([
        ...$this->object,
        'textModulesData' => [['id' => 'barcode_top', 'body' => '5 / 7 puntos']],
    ]);
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['confirmed_by_readback'])->toBeTrue();
    $patches = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');
    expect($patches)->toHaveCount(1);
    expect($patches[0][0]->url())->toBe($this->classUrl.'/'.$this->classId);
    $wire = $patches[0][0]->data();
    expect($wire)->not->toHaveKeys(['homepageUri', 'accountId', 'barcode', 'textModulesData', 'loyaltyPoints', 'valueAddedModuleData']);
    expect($wire['classTemplateInfo']['futureTemplate'])->toBe(['keep' => true]);
    expect($wire['classTemplateInfo']['listTemplateOverride'])->toEqual([
        ...$this->class['classTemplateInfo']['listTemplateOverride'],
        'thirdRowOption' => $actual['classTemplateInfo']['listTemplateOverride']['thirdRowOption'],
    ]);
    foreach (['cardTemplateOverride', 'detailsTemplateOverride', 'cardBarcodeSectionDetails'] as $field) {
        expect($wire['classTemplateInfo'][$field])->toBe($this->class['classTemplateInfo'][$field]);
    }
    $objectWrites = $this->http->requests(fn (Request $request) => $request->url() === $this->objectUrl);
    expect($objectWrites)->toHaveCount(1);
    expect($objectWrites[0][0]['barcode'])->toBe($this->object['barcode']);
    expect($objectWrites[0][0]['accountId'])->toBe($this->credentials->manualCode);
})->with(['legacy', 'missing list', 'nested second row']);

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
    $this->class['classTemplateInfo']['listTemplateOverride']['thirdRowOption'] = [
        'fields' => [['fieldPath' => 'object.accountName']],
    ];
    $this->class['localizedIssuerName'] = null;
    $this->class['localizedProgramName'] = null;
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
    expect($this->http->requests(fn (Request $request) => $request->method() === 'PATCH'))->toBeEmpty();
});

it('serves an opaque native Wallet PNG on the original canvas in the approved brand colors', function () {
    $path = public_path('icon-logo-wallet.png');
    $originalPath = public_path('icon-logo-black.png');
    expect(is_file($path))->toBeTrue();
    expect(is_file($originalPath))->toBeTrue();
    $size = getimagesize($path);
    $originalSize = getimagesize($originalPath);
    expect([$size[0], $size[1], $size['mime']])->toBe([$originalSize[0], $originalSize[1], 'image/png']);

    if (function_exists('imagecreatefrompng')) {
        $image = imagecreatefrompng($path);
        $opaque = true;
        $hasAccent = false;

        for ($y = 0; $y < $size[1]; $y++) {
            for ($x = 0; $x < $size[0]; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $opaque = $opaque && ($pixel & 0x7F000000) === 0;
                $color = $pixel & 0xFFFFFF;
                $hasAccent = $hasAccent || $color === 0xA77BFF;
            }
        }

        expect($opaque)->toBeTrue();
        expect($hasAccent)->toBeTrue();
        expect(imagecolorat($image, 0, 0))->toBe(0x1C1C1C);
    }
});

it('creates a missing deterministic class once with the shared layout before the object', function () {
    $responses = walletResponses($this->class, $this->object);
    $responses[$this->classUrl.'/'.$this->classId] = GoogleWalletHttpFake::json([], 404);
    $responses[$this->classUrl] = GoogleWalletHttpFake::json($this->class, 201);
    $this->http->respondWith($responses);

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($this->http->requests(fn (Request $request) => str_ends_with($request->url(), '/loyaltyClass')
        && $request['id'] === $this->classId
        && $request['issuerName'] === "\u{200B}"
        && $request['programName'] === $this->business->name
        && $request['programLogo']['sourceUri']['uri'] === 'https://passes.example.test/icon-logo-wallet.png'
        && $request['reviewStatus'] === 'UNDER_REVIEW'
        && $request['classTemplateInfo'] == $this->class['classTemplateInfo']))->not->toBeEmpty();
    $wire = $this->http->requests(fn (Request $request) => $request->url() === $this->classUrl)[0][0];
    expect(bin2hex($wire['issuerName']))->toBe('e2808b');
    expect($wire['classTemplateInfo']['listTemplateOverride'])->toBe([
        'firstRowOption' => ['fieldOption' => ['fields' => [['fieldPath' => 'class.programName']]]],
        'secondRowOption' => ['fields' => [['fieldPath' => 'object.accountId']]],
    ]);
    expect($wire->data())->not->toHaveKeys(['wideProgramLogo', 'localizedIssuerName', 'localizedProgramName']);
    $requests = $this->http->requests()->map(fn ($pair) => $pair[0]->url())->all();
    expect($requests)->toHaveCount(4);
    expect(array_search($this->classUrl, $requests))
        ->toBeLessThan(array_search($this->objectUrl, $requests));
});

it('uses the single configured logo in the native holder regardless of card background', function (string $background) {
    config([
        'services.google_wallet.logo_url' => 'https://assets.example.test/black.png',
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

it('reconciles legacy native names and logo without resetting a matching object', function () {
    $class = $this->class;
    $previousClass = $this->class;
    $previousClass['issuerName'] = 'FidelitoPass';
    $previousClass['programName'] = $this->business->name;
    $previousClass['programLogo']['sourceUri']['uri'] = 'https://passes.example.test/logo.png';
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
            'textModulesData' => [
                ['id' => 'barcode_top', 'body' => '5 / 7 puntos'],
                ['id' => 'details_primary', 'body' => 'Recompensa existente'],
            ],
        ]);
    $this->http->respondWith($responses);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    expect($result['object_id'])->toBe($this->objectId);
    expect($result['confirmed_by_readback'])->toBeTrue();
    $patches = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH');
    expect($patches)->toHaveCount(1);
    expect($patches[0][0]->url())->toBe($this->classUrl.'/'.$this->classId);
    expect($patches[0][0]->data())->toMatchArray([
        'id' => $this->classId,
        'issuerName' => "\u{200B}",
        'programName' => $this->business->name,
        'programLogo' => ['sourceUri' => ['uri' => 'https://passes.example.test/icon-logo-wallet.png']],
        'reviewStatus' => 'UNDER_REVIEW',
    ])->not->toHaveKeys(['homepageUri', 'barcode', 'accountId', 'textModulesData', 'loyaltyPoints']);
    expect($this->http->requests()->map(fn ($pair) => [$pair[0]->method(), $pair[0]->url()])->all())->toBe([
        ['POST', 'https://oauth2.googleapis.com/token'],
        ['GET', $this->classUrl.'/'.$this->classId],
        ['PATCH', $this->classUrl.'/'.$this->classId],
        ['POST', $this->objectUrl],
        ['GET', $this->objectUrl.'/'.$this->objectId],
    ]);
    $this->assertDatabaseCount('customer_passes', 0);
});

it('selects one native logo URL by environment and optional override', function (
    string $environment,
    ?string $logoUrl,
    string $expectedLogo,
) {
    app()->instance('env', $environment);
    config([
        'app.url' => 'https://PASSES.example.test:443/',
        'services.google_wallet.logo_url' => $logoUrl,
        'services.google_wallet.logo_black_url' => 'http://retired.example.test/black.png',
        'services.google_wallet.logo_white_url' => 'http://retired.example.test/white.png',
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
    'local unset' => ['local', null, 'https://passes.example.test/icon-logo-wallet.png'],
    'local empty' => ['local', '', 'https://passes.example.test/icon-logo-wallet.png'],
    'production unset' => ['production', null, 'https://passes.example.test/icon-logo-wallet.png'],
    'production empty' => ['production', '', 'https://passes.example.test/icon-logo-wallet.png'],
    'local override' => ['local', 'https://cjmsliamizbxzuyntoud.supabase.co/storage/v1/object/public/wallet-branding/icon-logo-wallet.png', 'https://cjmsliamizbxzuyntoud.supabase.co/storage/v1/object/public/wallet-branding/icon-logo-wallet.png'],
    'production override' => ['production', 'https://assets.example.test/platform.png', 'https://assets.example.test/platform.png'],
]);

it('rejects an unsafe selected logo before credential loading or HTTP', function (mixed $invalid) {
    config([
        'services.google_wallet.logo_url' => $invalid,
        'services.google_wallet.credentials_path' => '/nonexistent/unsafe-logo-marker',
    ]);

    try {
        app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);
        test()->fail('An unsafe selected logo must stop issuance.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStage->value)->toBe('configuration');
        expect($exception->isRetryable())->toBeTrue();
        expect($exception->failure)->toBe(CustomerPassCreationFailure::LogoUrlInvalid);
        expect($exception->getPrevious())->toBeNull();
        expect((string) $exception)->not->toContain('unsafe-logo-marker');
    }

    expect($this->http->requests())->toBeEmpty();
})->with([
    'relative path' => ['/icon-logo-wallet.png'],
    'wrong type' => [['unsafe-logo-marker']],
    'HTTP black' => ['http://assets.example.test/black.png'],
    'local black' => ['https://localhost/black.png'],
    'IP black' => ['https://127.0.0.1/black.png'],
    'internal black' => ['https://assets.internal/black.png'],
    'credential-bearing black' => ['https://unsafe-logo-marker@assets.example.test/black.png'],
    'query-bearing black' => ['https://assets.example.test/black.png?unsafe-logo-marker'],
    'fragment-bearing black' => ['https://assets.example.test/black.png#unsafe-logo-marker'],
]);

it('localizes credential labels through Laravel without changing their values', function () {
    app()->setLocale('en');
    app('translator')->addLines([
        'wallet.labels.personal_link' => 'View my promotion and progress',
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
    expect($object['linksModuleData']['uris'][0]['description'])->toBe('View my promotion and progress');
    expect(app()->getLocale())->toBe('en');
    $this->assertDatabaseCount('customer_passes', 0);
});

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
    expect($seenClasses[$this->classId]['issuerName'])->toBe("\u{200B}");
    expect($seenClasses[$this->classId]['programName'])->toBe('Panadería real');
    expect($seenClasses['123456.business_'.$otherBusiness->public_id]['programName'])->toBe($otherBusiness->name);
    expect($this->http->requests(fn (Request $request) => $request->method() === 'PATCH'))->toBeEmpty();
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
            'issuerName' => "\u{200B}",
            'programName' => $this->business->name,
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

it('accepts only exact loopback app origins locally with an independent public logo', function (string $applicationUrl, string $expectedOrigin) {
    app()->instance('env', 'local');
    config([
        'app.url' => $applicationUrl,
        'services.google_wallet.logo_url' => 'https://assets.example.test/platform.png',
    ]);
    $class = $this->class;
    $class['programLogo']['sourceUri']['uri'] = 'https://assets.example.test/platform.png';
    $this->http->respondWith(walletResponses($class, $this->object));
    $sharedUrlBefore = URL::route('public.pass', [
        'businessPublicId' => $this->business->public_id,
        'passPublicId' => $this->credentials->publicPassId,
    ]);

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $claims = walletVerifiedClaims(
        substr($result['save_url'], strlen('https://pay.google.com/gp/v/save/')),
        $this->key,
    );
    expect($claims['origins'])->toBe([$expectedOrigin]);
    $objectPayload = $this->http->requests(fn (Request $providerRequest) => $providerRequest->method() === 'POST'
        && $providerRequest->url() === $this->objectUrl)->sole()[0]->data();
    $personalLinkUrl = $objectPayload['linksModuleData']['uris'][0]['uri'];
    $signedRequest = Illuminate\Http\Request::create($personalLinkUrl);
    expect($signedRequest->getSchemeAndHttpHost())->toBe($expectedOrigin);
    expect($personalLinkUrl)->toStartWith($expectedOrigin.'/personal/');
    expect(URL::hasValidSignature($signedRequest, absolute: false))->toBeTrue();
    expect(URL::route('public.pass', [
        'businessPublicId' => $this->business->public_id,
        'passPublicId' => $this->credentials->publicPassId,
    ]))->toBe($sharedUrlBefore);
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
        test()->fail('A local app must not send an unsafe program logo.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->providerStage->value)->toBe('configuration');
        expect($exception->failure)->toBe(CustomerPassCreationFailure::LogoUrlInvalid);
        expect($exception->getPrevious())->toBeNull();
    }

    expect($this->http->requests())->toBeEmpty();
})->with([
    '/icon-logo-wallet.png',
    '@public/icon-logo-wallet.png',
    'http://localhost/logo.png',
    'https://localhost/logo.png',
    'https://127.0.0.1/logo.png',
    'http://assets.example.test/logo.png',
    ['unsafe-logo'],
]);

it('preserves nondefault HTTPS production ports and the public logo fallback', function () {
    config(['app.url' => 'https://passes.example.test:8443/']);
    $class = $this->class;
    $class['programLogo']['sourceUri']['uri'] = 'https://passes.example.test:8443/icon-logo-wallet.png';
    $this->http->respondWith(walletResponses($class, $this->object));

    $result = app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $claims = walletVerifiedClaims(
        substr($result['save_url'], strlen('https://pay.google.com/gp/v/save/')),
        $this->key,
    );
    expect($claims['origins'])->toBe(['https://passes.example.test:8443']);
    $objectPayload = $this->http->requests(fn (Request $providerRequest) => $providerRequest->method() === 'POST'
        && $providerRequest->url() === $this->objectUrl)->sole()[0]->data();
    $personalLinkUrl = $objectPayload['linksModuleData']['uris'][0]['uri'];
    $signedRequest = Illuminate\Http\Request::create($personalLinkUrl);
    expect($signedRequest->getSchemeAndHttpHost())->toBe('https://passes.example.test:8443');
    expect($personalLinkUrl)->toStartWith('https://passes.example.test:8443/personal/');
    expect(URL::hasValidSignature($signedRequest, absolute: false))->toBeTrue();
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
        'issuerName' => "\u{200B}",
        'programName' => $this->business->name,
        'programLogo' => $this->class['programLogo'],
        'classTemplateInfo' => $this->class['classTemplateInfo'],
        'reviewStatus' => 'UNDER_REVIEW',
    ]);
    expect($this->http->requests())->toHaveCount(4);
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

it('issues only permanent identification and a native signed private consultation link before local persistence', function () {
    $this->http->respondWith(walletResponses($this->class, $this->object));

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $objectPayload = $this->http->requests(fn (Request $providerRequest) => $providerRequest->method() === 'POST'
        && $providerRequest->url() === $this->objectUrl)->sole()[0]->data();
    expect($objectPayload)->not->toHaveKeys(['textModulesData', 'loyaltyPoints']);
    $personalLink = $objectPayload['linksModuleData']['uris'][0];
    expect($personalLink['description'])->toBe('Quiero ver el detalle de mi tarjeta');
    $signedRequest = Illuminate\Http\Request::create($personalLink['uri']);
    expect(URL::hasValidSignature($signedRequest, absolute: false))->toBeTrue();
    expect($personalLink['uri'])->toStartWith('https://passes.example.test/personal/');
    expect($personalLink['uri'])->toContain('/personal/'.$this->business->public_id.'/'.$this->credentials->publicPassId)
        ->not->toContain($this->credentials->validationToken, $this->credentials->manualCode);
    $this->assertDatabaseCount('customer_passes', 0);
});

it('clears retired variable Class slots while keeping permanent identity and unrelated provider fields', function (string $slot) {
    $classPayload = $this->class;
    if ($slot === 'barcode') {
        $classPayload['classTemplateInfo']['cardBarcodeSectionDetails']['firstTopDetail'] = ['fieldSelector' => ['fields' => [['fieldPath' => "object.textModulesData['barcode_top']"]]]];
    } elseif ($slot === 'card') {
        $classPayload['classTemplateInfo']['cardTemplateOverride'] = ['cardRowTemplateInfos' => [['oneItem' => ['item' => ['firstValue' => ['fields' => [['fieldPath' => "object.textModulesData['card_primary']"]]]]]]]];
    } else {
        $classPayload['classTemplateInfo']['detailsTemplateOverride'] = ['detailsItemInfos' => [['item' => ['firstValue' => ['fields' => [['fieldPath' => "object.textModulesData['details_history']"]]]]]]];
    }
    $classPayload['classTemplateInfo']['futureTemplate'] = ['keep' => true];
    $providerResponses = walletResponses($classPayload, $this->object);
    $providerResponses[$this->classUrl.'/'.$this->classId] = new MockHandler([GoogleWalletHttpFake::json($classPayload), GoogleWalletHttpFake::json($this->class)]);
    $this->http->respondWith($providerResponses);

    app(GoogleWalletPassCreator::class)->create($this->business, $this->credentials);

    $classPatch = $this->http->requests(fn (Request $request) => $request->method() === 'PATCH')->sole()[0];
    expect($classPatch['classTemplateInfo'])->toEqual([...$this->class['classTemplateInfo'], 'futureTemplate' => ['keep' => true]]);
    expect($classPatch->url())->toBe($this->classUrl.'/'.$this->classId);
    expect($classPatch->data())->not->toHaveKeys(['barcode', 'accountId', 'textModulesData']);
})->with(['card', 'details', 'barcode']);
