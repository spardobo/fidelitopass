<?php

use App\Actions\CustomerPasses\AcquireCustomerPass;
use App\Integrations\GoogleWallet\GoogleWallet;
use App\Models\Business;
use App\Models\CustomerPass;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

beforeEach(function () {
    Http::preventStrayRequests();

    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $pem);

    $this->credentialsPath = tempnam(sys_get_temp_dir(), 'wallet-acquisition-');
    file_put_contents($this->credentialsPath, json_encode([
        'type' => 'service_account',
        'client_email' => 'wallet@platform.iam.gserviceaccount.com',
        'private_key' => $pem,
    ]));

    config([
        'app.url' => 'https://passes.example.test',
        'app.debug' => true,
        'services.google_wallet' => [
            'issuer_id' => '123456',
            'credentials_path' => $this->credentialsPath,
            'logo_url' => 'https://passes.example.test/logo.png',
            'connect_timeout' => 3,
            'timeout' => 10,
        ],
    ]);
});

afterEach(function () {
    unlink($this->credentialsPath);
    $this->truncateTablesForAllConnections();
});

// -----------------------------------------------------------------------------
// confirmed issuance and private handoff
// -----------------------------------------------------------------------------

it('returns an anonymous private handoff only after the provider and outer PostgreSQL commit', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    $foreignBusiness = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business);
    Queue::fake();
    Log::spy();

    $committed = false;
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $observingDispatcher = clone $dispatcher;
    $observingDispatcher->listen(TransactionCommitted::class, function () use (&$committed) {
        expect(DB::transactionLevel())->toBe(0);
        expect(CustomerPass::count())->toBe(1);
        $committed = true;
    });
    $connection->setEventDispatcher($observingDispatcher);

    try {
        $response = $this->postJson(route('public.join.save', $business->public_id), [
            'business_id' => $foreignBusiness->id,
            'owner_id' => $foreignBusiness->user_id,
            'public_id' => $foreignBusiness->public_id,
            'wallet_object_id' => 'forged.object',
            'validation_token' => str_repeat('a', 64),
            'manual_code' => 'FORGED123456',
            'save_url' => 'https://attacker.example.test/',
        ]);
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }

    $response->assertCreated()->assertExactJson([
        'save_url' => $response->json('save_url'),
        'manual_code' => $response->json('manual_code'),
    ])->assertHeader('Cache-Control', 'no-store, private');
    expect($committed)->toBeTrue();
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $object = acquisitionObjectRequest();
    $pass = CustomerPass::query()->sole();
    expect($pass->business_id)->toBe($business->id);
    expect($pass->wallet_object_id)->toBe($object['id']);
    expect($pass->manual_code)->toBe($object['accountId']);
    expect($pass->validation_token_hash)->toBe(hash('sha256', $object['barcode']['value']));
    expect($pass->manual_code)->not->toBe('FORGED123456');
    expect($response->json('manual_code'))->toBe($pass->manual_code);
    expect($response->json('save_url'))->toStartWith('https://pay.google.com/gp/v/save/');

    $jwt = substr($response->json('save_url'), strlen('https://pay.google.com/gp/v/save/'));
    $claims = json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
    expect($claims['payload']['loyaltyObjects'])->toBe([[
        'id' => $object['id'],
        'classId' => '123456.business_'.$business->public_id,
    ]]);
    expect(json_encode($claims))->not->toContain($object['barcode']['value'], $pass->manual_code);
    expect(json_encode(session()->all()))->not->toContain(
        $object['barcode']['value'],
        $pass->manual_code,
        $response->json('save_url'),
    );

    foreach (['promotion_participations', 'visits', 'reward_entitlements'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }

    Http::assertSentCount(4);
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('info')->with('customer_pass.issued', Mockery::on(
        fn (array $context) => $context === [
            'business_id' => $business->id,
            'customer_pass_id' => $pass->id,
            'operation_id' => $pass->public_id,
            'outcome' => 'issued',
        ],
    ))->once();
});

// -----------------------------------------------------------------------------
// failed and uncertain acquisition
// -----------------------------------------------------------------------------

it('returns a sanitized 502 without local issuance for definitive Google rejection', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business, 400);
    Log::spy();

    $response = $this->postJson(route('public.join.save', $business->public_id));

    $response->assertStatus(502)->assertExactJson([
        'outcome' => 'definitive',
        'stage' => 'provider',
        'retry_allowed' => true,
    ])->assertHeader('Cache-Control', 'no-store, private');
    $this->assertDatabaseCount('customer_passes', 0);
    Http::assertSentCount(4);
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
    expect(json_encode(session()->all()))->not->toContain(acquisitionObjectRequest()['accountId']);
});

it('returns 503 and disables retry without allocating a second identity for unknown Google creation', function (bool $connectionFailure) {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business, 503, $connectionFailure);

    $response = $this->postJson(route('public.join.save', $business->public_id));

    $response->assertStatus(503)->assertExactJson([
        'outcome' => 'unknown',
        'stage' => 'provider',
        'retry_allowed' => false,
    ])->assertHeader('Cache-Control', 'no-store, private');
    $this->assertDatabaseCount('customer_passes', 0);
    $objectRequests = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject'));
    expect($objectRequests)->toHaveCount(1);
    Http::assertSentCount(4);
})->with(['provider 503' => false, 'lost connection' => true]);

it('returns 503 without handoff after confirmed local rollback and accepts the Google orphan', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business);
    Log::spy();
    CustomerPass::creating(function (CustomerPass $pass) {
        throw new RuntimeException('Private local failure: '.$pass->manual_code);
    });

    try {
        $response = $this->postJson(route('public.join.save', $business->public_id));
    } finally {
        CustomerPass::flushEventListeners();
        CustomerPass::clearBootedModels();
    }

    $response->assertStatus(503)->assertExactJson([
        'outcome' => 'unknown',
        'stage' => 'local',
        'retry_allowed' => false,
    ])->assertHeader('Cache-Control', 'no-store, private');
    expect(DB::transactionLevel())->toBe(0);
    $this->assertDatabaseCount('customer_passes', 0);
    Http::assertSentCount(4);
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('info');
});

it('returns unknown 503 without handoff when commit acknowledgement is lost despite a persisted pass', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business);
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $faultDispatcher = clone $dispatcher;
    $faultDispatcher->listen(TransactionCommitted::class, function () {
        throw new PDOException('Private failure after the real PostgreSQL commit.');
    });
    $connection->setEventDispatcher($faultDispatcher);

    try {
        $response = $this->postJson(route('public.join.save', $business->public_id));
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }

    $response->assertStatus(503)->assertExactJson([
        'outcome' => 'unknown',
        'stage' => 'local',
        'retry_allowed' => false,
    ])->assertHeader('Cache-Control', 'no-store, private');
    $this->assertDatabaseCount('customer_passes', 1);
    expect(CustomerPass::query()->sole()->wallet_object_id)->toBe(acquisitionObjectRequest()['id']);
    expect(DB::transactionLevel())->toBe(0);
    Http::assertSentCount(4);
});

it('refuses an ambient transaction before credentials or Google are contacted', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    DB::beginTransaction();

    try {
        $result = app(AcquireCustomerPass::class)->handle($business);
    } finally {
        DB::rollBack();
    }

    expect($result)->toBe([
        'outcome' => 'definitive',
        'stage' => 'preparation',
        'retry_allowed' => false,
    ]);
    $this->assertDatabaseCount('customer_passes', 0);
    Http::assertNothingSent();
});

it('returns definitive preparation 503 rather than claiming a provider attempt after allocation fails', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    CustomerPass::factory()->for($business)->create(['manual_code' => 'PRIVATE12345']);
    Str::createRandomStringsUsing(fn () => 'private12345');

    try {
        $response = $this->postJson(route('public.join.save', $business->public_id));
    } finally {
        Str::createRandomStringsNormally();
    }

    $response->assertStatus(503)->assertExactJson([
        'outcome' => 'definitive',
        'stage' => 'preparation',
        'retry_allowed' => true,
    ])->assertHeader('Cache-Control', 'no-store, private');
    $this->assertDatabaseCount('customer_passes', 1);
    Http::assertNothingSent();
});

it('returns preparation 503 without exposing a failed public Business lookup', function () {
    $business = Business::factory()->create();
    DB::connection()->beforeExecuting(function (string $query, array $bindings) {
        if (str_contains($query, '"businesses"') && str_contains($query, '"public_id"')) {
            throw new QueryException('pgsql', $query, $bindings, new PDOException('Private lookup failure.'));
        }
    });

    $this->postJson(route('public.join.save', $business->public_id))
        ->assertServiceUnavailable()
        ->assertExactJson([
            'outcome' => 'definitive',
            'stage' => 'preparation',
            'retry_allowed' => true,
        ])
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->assertDatabaseCount('customer_passes', 0);
    Http::assertNothingSent();
});

it('returns sanitized unknown 503 for an unexpected provider-boundary exception without a second attempt', function () {
    $business = Business::factory()->create();
    $this->mock(GoogleWallet::class)
        ->shouldReceive('provision')
        ->once()
        ->andThrow(new RuntimeException('Private unexpected provider failure.'));

    $this->postJson(route('public.join.save', $business->public_id))
        ->assertServiceUnavailable()
        ->assertExactJson([
            'outcome' => 'unknown',
            'stage' => 'provider',
            'retry_allowed' => false,
        ])
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->assertDatabaseCount('customer_passes', 0);
    Http::assertNothingSent();
});

// -----------------------------------------------------------------------------
// public routing and request forgery protection
// -----------------------------------------------------------------------------

it('keeps the join GET read-only and refuses issuance through GET save', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);

    $this->get(route('public.join', $business->public_id))->assertOk();
    $this->getJson('/join/'.$business->public_id.'/save')->assertStatus(405);

    $this->assertDatabaseCount('customer_passes', 0);
    Http::assertNothingSent();
});

it('returns 404 without provider effects for missing or malformed public business identity', function (string $publicId) {
    $this->postJson('/join/'.$publicId.'/save', [
        'business_id' => Business::factory()->create()->id,
    ])->assertNotFound();

    $this->assertDatabaseCount('customer_passes', 0);
    Http::assertNothingSent();
})->with([
    'unknown UUID' => '018f1111-2222-7333-8444-555555555555',
    'malformed UUID' => 'not-a-business-uuid',
]);

it('enforces real CSRF with 419 before acquisition for missing or mismatched tokens', function (?string $token) {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    app()->instance('env', 'production');
    $this->withSession(['_token' => 'expected-csrf-token']);

    $this->postJson(route('public.join.save', $business->public_id), [
        '_token' => $token,
    ])->assertStatus(419)->assertJsonMissingPath('save_url')->assertJsonMissingPath('manual_code');

    $this->assertDatabaseCount('customer_passes', 0);
    Http::assertNothingSent();
})->with(['missing' => null, 'mismatched' => 'forged-csrf-token']);

it('accepts a valid session CSRF token under the real web middleware', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business);
    app()->instance('env', 'production');
    $this->withSession(['_token' => 'expected-csrf-token']);

    $this->postJson(route('public.join.save', $business->public_id), [], [
        'X-CSRF-TOKEN' => 'expected-csrf-token',
    ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private');

    $this->assertDatabaseCount('customer_passes', 1);
    Http::assertSentCount(4);
});

// -----------------------------------------------------------------------------
// isolated provider fixtures
// -----------------------------------------------------------------------------

/**
 * Fakes approved Class creation and one Object attempt outside every local transaction.
 *
 * @param  Business  $business  Authoritative Business expected in the Class binding.
 * @param  int  $objectStatus  HTTP status returned for the single Object POST.
 * @param  bool  $connectionFailure  Whether the Object response is lost in transport.
 */
function fakeAcquisitionProvider(Business $business, int $objectStatus = 201, bool $connectionFailure = false): void
{
    $classUrl = 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass';
    $objectUrl = 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject';

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'ephemeral-test-access',
            'token_type' => 'Bearer',
        ]),
        $classUrl.'/123456.business_'.$business->public_id => Http::response([], 404),
        $classUrl => function (Request $request) {
            expect(DB::transactionLevel())->toBe(0);
            expect(CustomerPass::count())->toBe(0);

            return Http::response([
                ...$request->data(),
                'reviewStatus' => 'approved',
            ], 201);
        },
        $objectUrl => $connectionFailure
            ? Http::failedConnection('Private provider connection failure.')
            : function (Request $request) use ($objectStatus, $business) {
                expect(DB::transactionLevel())->toBe(0);
                expect(CustomerPass::count())->toBe(0);
                expect($request['classId'])->toBe('123456.business_'.$business->public_id);

                return Http::response($request->data(), $objectStatus);
            },
    ]);
}

/**
 * Returns the single recorded Object POST for persistence and private-state assertions.
 *
 * @return Request Provider-bound request containing test-only generated credentials.
 */
function acquisitionObjectRequest(): Request
{
    return Http::recorded(
        fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject'),
    )->sole()[0];
}
