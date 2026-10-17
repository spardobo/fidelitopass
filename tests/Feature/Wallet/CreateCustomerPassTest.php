<?php

use App\Actions\CustomerPasses\CreateCustomerPass;
use App\Enums\CustomerPassCreationFailure;
use App\Enums\FailureOutcome;
use App\Enums\PassCreationStage;
use App\Exceptions\CustomerPassCreationException;
use App\Integrations\GoogleWallet\GoogleWalletClient;
use App\Integrations\GoogleWallet\GoogleWalletPassCreator;
use App\Models\Business;
use App\Models\CustomerPass;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\GoogleWalletHttpFake;
use Tests\Support\PostgresDatabaseTruncation;

uses(PostgresDatabaseTruncation::class);

beforeEach(function () {
    $this->http = new GoogleWalletHttpFake;
    app()->instance(GoogleWalletClient::class, new GoogleWalletClient($this->http->client));

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

it('returns a safe 409 before credential preparation or Google when appearance is unsaved or reset', function (bool $stalePage) {
    $business = Business::factory()->create(['pass_background_color' => $stalePage ? '#123456' : null]);
    if ($stalePage) {
        $this->get(route('public.join', $business->public_id))->assertSee('Unirme y añadir a Google Wallet');
        $business->update(['pass_background_color' => null]);
    }
    $this->mock(GoogleWalletPassCreator::class)->shouldNotReceive('create');
    $preparationQueries = [];
    DB::listen(function ($query) use (&$preparationQueries): void {
        if (str_contains($query->sql, '"customer_passes"')) {
            $preparationQueries[] = $query->sql;
        }
    });

    $this->postJson(route('public.join.save', $business->public_id))
        ->assertConflict()
        ->assertExactJson([
            'outcome' => 'definitive',
            'stage' => 'preparation',
            'retry_allowed' => false,
            'code' => 'business_unconfigured',
        ])
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($preparationQueries)->toBeEmpty();
    expect($this->http->requests())->toBeEmpty();
    $this->assertDatabaseCount('customer_passes', 0);
    $this->assertDatabaseCount('promotion_participations', 0);
})->with(['direct POST' => false, 'configured GET followed by reset' => true]);

it('returns an anonymous private handoff only after the provider and outer PostgreSQL commit', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    $foreignBusiness = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business);
    Queue::fake();
    Log::partialMock()->shouldReceive('log')->byDefault();

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
    expect($pass->manual_code)->toMatch('/^[0-9]{5}$/D')->toBe($object['accountId']);
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

    expect(test()->http->requests())->toHaveCount(4);
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('log')->with('info', 'customer_pass.creation.completed', Mockery::on(
        fn (array $context) => $context['business_id'] === $business->id
            && ! array_key_exists('request_id', $context)
            && $context['customer_pass_id'] === $pass->id
            && $context['operation_id'] === $pass->public_id
            && $context['outcome'] === 'issued',
    ))->once();
});

it('returns a sanitized 502 without local issuance for definitive Google rejection', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business, 400);
    Log::partialMock()->shouldReceive('log')->byDefault();

    $response = $this->postJson(route('public.join.save', $business->public_id));

    $response->assertStatus(502)->assertExactJson([
        'outcome' => 'definitive',
        'stage' => 'provider',
        'retry_allowed' => true,
    ])->assertHeader('Cache-Control', 'no-store, private');
    $this->assertDatabaseCount('customer_passes', 0);
    expect(test()->http->requests())->toHaveCount(4);
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
    $objectRequests = test()->http->requests(fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject'));
    expect($objectRequests)->toHaveCount(1);
    expect(test()->http->requests(fn (Request $request) => $request->method() === 'GET'
        && str_contains($request->url(), '/loyaltyObject/')))->toHaveCount(1);
    expect(test()->http->requests())->toHaveCount(5);
})->with(['provider 503' => false, 'lost connection' => true]);

it('returns 503 without handoff after confirmed local rollback and accepts the Google orphan', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business);
    Log::partialMock()->shouldReceive('log')->byDefault();
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
    expect(test()->http->requests())->toHaveCount(4);
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('log', ['info', 'customer_pass.creation.completed', Mockery::any()]);
    Log::shouldHaveReceived('log')->with('error', 'customer_pass.creation.failed', Mockery::on(
        fn (array $context) => $context['stage'] === 'local'
            && ! array_key_exists('request_id', $context)
            && $context['outcome'] === 'unknown'
            && $context['reason'] === 'unexpected_failure' && $context['retry_allowed'] === false
            && Str::isUuid($context['operation_id']),
    ))->once();
});

it('returns unknown 503 without handoff when commit acknowledgement is lost despite a persisted pass', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business);
    Log::partialMock()->shouldReceive('log')->byDefault();
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
    Log::shouldHaveReceived('log')->with('error', 'customer_pass.creation.failed', Mockery::on(
        fn (array $context) => $context['stage'] === 'local'
            && $context['outcome'] === 'unknown'
            && $context['operation_id'] === CustomerPass::query()->sole()->public_id,
    ))->once();
    Log::shouldNotHaveReceived('log', ['info', 'customer_pass.handoff.ready', Mockery::on(
        fn (array $context) => $context['stage'] === 'handoff',
    )]);
    expect(DB::transactionLevel())->toBe(0);
    expect(test()->http->requests())->toHaveCount(4);
});

it('refuses an ambient transaction before credentials or Google are contacted', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    Log::partialMock()->shouldReceive('log')->byDefault();
    DB::beginTransaction();

    try {
        try {
            app(CreateCustomerPass::class)->handle($business);
            test()->fail('Ambient transaction must be rejected.');
        } catch (CustomerPassCreationException $result) {
            expect($result->failure)->toBe(CustomerPassCreationFailure::TransactionActive);
        }
    } finally {
        DB::rollBack();
    }

    expect([
        'outcome' => $result->outcome->value,
        'stage' => $result->stage->value,
        'retry_allowed' => $result->isRetryable(),
    ])->toBe([
        'outcome' => 'definitive',
        'stage' => 'preparation',
        'retry_allowed' => false,
    ]);
    $this->assertDatabaseCount('customer_passes', 0);
    expect(test()->http->requests())->toBeEmpty();
    Log::shouldHaveReceived('log')->with('warning', 'customer_pass.creation.failed', Mockery::on(
        fn (array $context) => $context['stage'] === 'preparation'
            && $context['outcome'] === 'definitive'
            && ($context['operation_id'] ?? null) === null
            && ! array_key_exists('request_id', $context),
    ))->once();
});

it('returns definitive preparation 503 rather than claiming a provider attempt after allocation fails', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    $lookups = 0;
    $occupiedCodes = [];
    DB::connection()->beforeExecuting(function (string $query, array $bindings) use ($business, &$lookups, &$occupiedCodes): void {
        if (str_starts_with($query, 'select exists') && str_contains($query, '"manual_code"')) {
            $lookups++;

            if (! in_array($bindings[1], $occupiedCodes, true)) {
                $occupiedCodes[] = $bindings[1];
                CustomerPass::factory()->for($business)->create(['manual_code' => $bindings[1]]);
            }
        }
    });

    $response = $this->postJson(route('public.join.save', $business->public_id));

    $response->assertStatus(503)->assertExactJson([
        'outcome' => 'definitive',
        'stage' => 'preparation',
        'retry_allowed' => true,
    ])->assertHeader('Cache-Control', 'no-store, private');
    expect($lookups)->toBe(10);
    $this->assertDatabaseCount('customer_passes', count($occupiedCodes));
    expect(test()->http->requests())->toBeEmpty();
});

it('returns preparation 503 without exposing a failed public Business lookup', function (bool $inspectExplicitContext) {
    $business = Business::factory()->create();
    $records = [];
    if ($inspectExplicitContext) {
        Log::partialMock()->shouldReceive('log')->once()->with('error', 'customer_pass.business_lookup.failed', Mockery::any())->andReturnUsing(
            function (string $level, string $event, array $context) use (&$records): void {
                $records[] = ['event' => $event, ...$context];
            },
        );
    } else {
        Log::listen(function (MessageLogged $record) use (&$records): void {
            $records[] = ['event' => $record->message, ...$record->context];
        });
    }
    DB::connection()->beforeExecuting(function (string $query, array $bindings) {
        if (str_contains($query, '"businesses"') && str_contains($query, '"public_id"')) {
            throw new QueryException('pgsql', $query, $bindings, new PDOException('Private lookup failure.'));
        }
    });

    $response = $this->postJson(route('public.join.save', $business->public_id));
    $response
        ->assertServiceUnavailable()
        ->assertExactJson([
            'outcome' => 'definitive',
            'stage' => 'preparation',
            'retry_allowed' => true,
        ])
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->assertDatabaseCount('customer_passes', 0);
    expect(test()->http->requests())->toBeEmpty();
    expect($records)->toHaveCount(1);
    expect($records[0]['event'])->toBe('customer_pass.business_lookup.failed');
    expect($records[0]['exception_class'])->toBe(QueryException::class);
    expect($records[0])->toHaveKey('sqlstate');
    $response->assertHeaderMissing('X-Request-ID');
    if ($inspectExplicitContext) {
        expect($records[0])->not->toHaveKey('request_id');
    } else {
        expect(Str::isUuid($records[0]['request_id'], 7))->toBeTrue();
    }
})->with(['explicit callsite context' => true, 'native emitted context' => false]);

it('returns sanitized unknown 503 for an unexpected provider-boundary exception without a second attempt', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    $records = [];
    Log::listen(function (MessageLogged $record) use (&$records): void {
        $records[] = $record->context;
    });
    $this->mock(GoogleWalletPassCreator::class)
        ->shouldReceive('create')
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
    expect(test()->http->requests())->toBeEmpty();
    expect($records)->toHaveCount(1);
    expect($records[0]['exception_class'])->toBe(RuntimeException::class);
    expect($records[0])->toHaveKeys(['source_file', 'source_line', 'exception_frames']);
    expect(json_encode($records))->not->toContain('Private unexpected provider failure.');
});

it('keeps the join GET read-only and refuses issuance through GET save', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);

    $this->get(route('public.join', $business->public_id))->assertOk();
    $this->getJson('/join/'.$business->public_id.'/save')->assertStatus(405);

    $this->assertDatabaseCount('customer_passes', 0);
    expect(test()->http->requests())->toBeEmpty();
});

it('returns 404 without provider effects for missing or malformed public business identity', function (string $publicId) {
    $this->postJson('/join/'.$publicId.'/save', [
        'business_id' => Business::factory()->create()->id,
    ])->assertNotFound();

    $this->assertDatabaseCount('customer_passes', 0);
    expect(test()->http->requests())->toBeEmpty();
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
    expect(test()->http->requests())->toBeEmpty();
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
    expect(test()->http->requests())->toHaveCount(4);
});

it('logs correlated sanitized stage outcomes without credential or exception contents', function (int $status, string $outcome) {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business, $status);
    $records = [];
    Log::listen(function (MessageLogged $record) use (&$records): void {
        $records[] = ['event' => $record->message, ...$record->context];
    });

    $response = $this->postJson(route('public.join.save', $business->public_id));

    $stages = collect($records)->values();
    expect($stages->pluck('stage')->all())->toBe($status === 201
        ? ['local']
        : ['provider']);
    expect($stages->last()['outcome'])->toBe($outcome);
    expect($stages->pluck('request_id')->unique())->toHaveCount(1);
    expect(Str::isUuid($stages->first()['request_id']))->toBeTrue();
    $response->assertHeaderMissing('X-Request-ID');
    expect(app('log')->sharedContext())->toBe([]);
    expect($stages->pluck('operation_id')->unique()->all())->toBe([
        substr(acquisitionObjectRequest()['id'], strlen('123456.pass_')),
    ]);
    foreach ($stages as $record) {
        expect($record)->toHaveKeys(['event', 'business_id', 'request_id', 'operation_id', 'stage', 'outcome']);
        expect($record['business_id'])->toBe($business->id);
    }
    expect(json_encode($records))->not->toContain(
        acquisitionObjectRequest()['accountId'],
        acquisitionObjectRequest()['barcode']['value'],
        'private_key',
        'ephemeral-test-access',
        'Private',
    );
    if ($status === 201) {
        expect(json_encode($records))->not->toContain($response->json('save_url'));
    }
})->with(['confirmed' => [201, 'issued'], 'rejected' => [400, 'definitive'], 'uncertain' => [503, 'unknown']]);

it('logs standalone action outcomes with operation identity but no request correlation', function (int $status) {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business, $status);
    $records = [];
    Log::listen(function (MessageLogged $record) use (&$records): void {
        $records[] = $record;
    });

    if ($status === 201) {
        app(CreateCustomerPass::class)->handle($business);
    } else {
        expect(fn () => app(CreateCustomerPass::class)->handle($business))
            ->toThrow(CustomerPassCreationException::class);
    }

    expect($records)->toHaveCount(1);
    expect($records[0]->message)->toBe($status === 201
        ? 'customer_pass.creation.completed'
        : 'customer_pass.creation.failed');
    expect($records[0]->context)->not->toHaveKey('request_id');
    expect($records[0]->context['operation_id'])->toBe(
        substr(acquisitionObjectRequest()['id'], strlen('123456.pass_')),
    );
    expect(app('log')->sharedContext())->toBe([]);
    $this->assertDatabaseCount('customer_passes', $status === 201 ? 1 : 0);

    Log::info('standalone.probe');

    expect($records[1]->context)->toBe([]);
    expect(test()->http->requests())->toHaveCount(4);
})->with(['completed' => 201, 'failed' => 400]);

it('surfaces a completion sink failure without undoing the confirmed commit or repeating Google creation', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business);
    Log::partialMock()->shouldReceive('log')->andThrow(new RuntimeException('Unavailable operational log sink.'));

    expect(fn () => app(CreateCustomerPass::class)->handle($business))
        ->toThrow(RuntimeException::class, 'Unavailable operational log sink.');

    $this->assertDatabaseCount('customer_passes', 1);
    expect(test()->http->requests(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/loyaltyObject')))->toHaveCount(1);
    expect(test()->http->requests())->toHaveCount(4);
});

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

    test()->http->respondWith([
        'https://oauth2.googleapis.com/token' => GoogleWalletHttpFake::json([
            'access_token' => 'ephemeral-test-access',
            'token_type' => 'Bearer',
        ]),
        $classUrl.'/123456.business_'.$business->public_id => GoogleWalletHttpFake::json([], 404),
        $classUrl => function (Request $request) {
            expect(DB::transactionLevel())->toBe(0);
            expect(CustomerPass::count())->toBe(0);

            return GoogleWalletHttpFake::json([
                ...$request->data(),
                'reviewStatus' => 'approved',
            ], 201);
        },
        $objectUrl.'/*' => GoogleWalletHttpFake::json([], 404),
        $objectUrl => $connectionFailure
            ? new ConnectException(
                'Private provider connection failure.',
                new PsrRequest('POST', 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject'),
            )
            : function (Request $request) use ($objectStatus, $business) {
                expect(DB::transactionLevel())->toBe(0);
                expect(CustomerPass::count())->toBe(0);
                expect($request['classId'])->toBe('123456.business_'.$business->public_id);

                return GoogleWalletHttpFake::json($request->data(), $objectStatus);
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
    return test()->http->requests(
        fn (Request $request) => str_ends_with($request->url(), '/loyaltyObject'),
    )->sole()[0];
}

it('keeps the committed pass but returns 500 when a native completion listener throws', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business);
    config(['app.debug' => false]);
    $loggedAfterCommit = false;
    $events = [];
    Log::listen(function (MessageLogged $record) use (&$loggedAfterCommit, &$events): void {
        $events[] = $record->message;
        if ($record->message === 'customer_pass.creation.completed') {
            $loggedAfterCommit = DB::transactionLevel() === 0 && CustomerPass::count() === 1;
            throw new RuntimeException('Private logger failure after commit.');
        }
    });

    $response = $this->postJson(route('public.join.save', $business->public_id));
    $response->assertStatus(500);

    expect($response->getContent())->not->toContain('Private logger failure');
    expect($events)->not->toContain('customer_pass.creation.failed');
    expect($loggedAfterCommit)->toBeTrue();
    $this->assertDatabaseCount('customer_passes', 1);
    expect(test()->http->requests(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/loyaltyObject')))->toHaveCount(1);
    expect(test()->http->requests())->toHaveCount(4);
});

it('returns 500 without retrying the provider when a native failure listener throws', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business, 400);
    config(['app.debug' => false]);
    $failuresLogged = 0;
    Log::listen(function (MessageLogged $record) use (&$failuresLogged): void {
        if ($record->message === 'customer_pass.creation.failed') {
            $failuresLogged++;
            throw new RuntimeException('test-only-listener-failure');
        }
    });

    $response = $this->postJson(route('public.join.save', $business->public_id));
    $response->assertStatus(500);

    expect($response->getContent())->not->toContain('test-only-listener-failure');
    expect($failuresLogged)->toBe(1);
    $this->assertDatabaseCount('customer_passes', 0);
    expect(test()->http->requests())->toHaveCount(4);
});

it('records readback-confirmed committed success as a warning without repeating creation', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business, 503);
    test()->http->respondWith([
        'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/*' => fn () => GoogleWalletHttpFake::json(acquisitionObjectRequest()->data()),
    ]);
    $records = [];
    Log::listen(function (MessageLogged $record) use (&$records): void {
        $records[] = $record;
    });

    $this->postJson(route('public.join.save', $business->public_id))->assertCreated();

    expect($records)->toHaveCount(1);
    expect($records[0]->level)->toBe('warning');
    expect($records[0]->message)->toBe('customer_pass.creation.completed');
    expect($records[0]->context['confirmed_by_readback'])->toBeTrue();
    expect($records[0]->context['outcome'])->toBe('issued');
    $this->assertDatabaseCount('customer_passes', 1);
    expect(test()->http->requests(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/loyaltyObject')))->toHaveCount(1);
    expect(test()->http->requests())->toHaveCount(5);
});

it('emits one actionable provider failure with safe structured details through the real HTTP boundary', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    $records = [];
    Log::listen(function (MessageLogged $record) use (&$records): void {
        $records[] = [
            'event' => $record->message,
            ...$record->context,
        ];
    });
    test()->http->respondWith([
        'https://oauth2.googleapis.com/token' => GoogleWalletHttpFake::json([
            'access_token' => 'test-access',
            'token_type' => 'Bearer',
        ]),
        'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/*' => GoogleWalletHttpFake::json([], 404),
        'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass' => fn (Request $request) => GoogleWalletHttpFake::json([
            ...$request->data(),
            'reviewStatus' => 'approved',
        ], 201),
        'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject' => function (Request $request) {
            return GoogleWalletHttpFake::json([
                'error' => [
                    'status' => 'INVALID_ARGUMENT',
                    'message' => $request['barcode']['value'],
                    'details' => [
                        [
                            '@type' => 'type.googleapis.com/google.rpc.BadRequest',
                            'fieldViolations' => [
                                [
                                    'field' => 'barcode.value',
                                    'description' => $request['barcode']['value'],
                                ],
                                ['field' => $request['accountId']],
                            ],
                        ],
                    ],
                ],
            ], 400);
        },
    ]);

    $this->postJson(route('public.join.save', $business->public_id))
        ->assertStatus(502)
        ->assertExactJson([
            'outcome' => 'definitive',
            'stage' => 'provider',
            'retry_allowed' => true,
        ]);

    expect($records)->toHaveCount(1);
    expect($records[0])->toMatchArray([
        'event' => 'customer_pass.creation.failed',
        'exception_class' => GoogleServiceException::class,
        'http_method' => 'POST',
        'provider_status' => 400,
    ]);
    expect(json_encode($records))->not->toContain(acquisitionObjectRequest()['barcode']['value'], acquisitionObjectRequest()['accountId']);
    $this->assertDatabaseCount('customer_passes', 0);
    expect(test()->http->requests())->toHaveCount(4);
});

it('retains source database class and SQLSTATE through credential preparation without SQL or bindings', function () {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    $records = [];
    Log::listen(function (MessageLogged $record) use (&$records): void {
        $records[] = $record->context;
    });
    DB::connection()->beforeExecuting(function (string $query, array $bindings) {
        if (str_contains($query, '"customer_passes"') && str_contains($query, '"manual_code"')) {
            $cause = new PDOException('private database failure');
            $cause->errorInfo = [
                '08006',
                7,
                'private database detail',
            ];
            throw new QueryException('pgsql', $query, $bindings, $cause);
        }
    });

    $this->postJson(route('public.join.save', $business->public_id))
        ->assertServiceUnavailable()
        ->assertExactJson([
            'outcome' => 'definitive',
            'stage' => 'preparation',
            'retry_allowed' => true,
        ]);

    expect($records)->toHaveCount(1);
    expect($records[0])->toMatchArray([
        'exception_class' => QueryException::class,
        'sqlstate' => '08006',
    ]);
    expect(json_encode($records))->not->toContain('private database', 'select ', 'bindings');
    $this->assertDatabaseCount('customer_passes', 0);
    expect(test()->http->requests())->toBeEmpty();
});

it('writes one native JSON binding failure with an authored field but no private values', function () {
    $business = Business::factory()->create([
        'name' => 'private-business-marker',
        'pass_background_color' => '#123456',
    ]);
    fakeAcquisitionProvider($business);
    $this->http->respondWith([
        'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject' => function (Request $request) {
            return GoogleWalletHttpFake::json([
                ...$request->data(),
                'accountId' => 'private-provider-marker',
            ]);
        },
    ]);

    $path = tempnam(sys_get_temp_dir(), 'wallet-binding-log-');
    config([
        'logging.default' => 'binding-probe',
        'logging.channels.binding-probe' => [
            ...config('logging.channels.single'),
            'path' => $path,
            'level' => 'debug',
        ],
    ]);
    app('log')->forgetChannel('binding-probe');

    try {
        $this->postJson(route('public.join.save', $business->public_id))
            ->assertStatus(502)
            ->assertExactJson([
                'outcome' => 'definitive',
                'stage' => 'provider',
                'retry_allowed' => true,
            ]);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($lines)->toHaveCount(1);
        $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        expect($record['message'])->toBe('customer_pass.creation.failed');
        expect($record['context'])->toMatchArray([
            'reason' => 'binding_conflict',
            'http_method' => 'POST',
            'provider_status' => null,
            'binding_field' => 'accountId',
            'binding_category' => 'different',
        ]);
        expect($lines[0])->not->toContain(
            'private-business-marker',
            'private-provider-marker',
            'ephemeral-test-access',
            'BEGIN PRIVATE KEY',
            'https://passes.example.test/logo.png',
            '123456.business_'.$business->public_id,
            acquisitionObjectRequest()['barcode']['value'],
            acquisitionObjectRequest()['accountId'],
            'Service Call',
        );
    } finally {
        app('log')->forgetChannel('binding-probe');
        unlink($path);
    }

    expect($this->http->requests())->toHaveCount(4);
    expect($this->http->requests(
        fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/loyaltyObject/'),
    ))->toBeEmpty();
    $this->assertDatabaseCount('customer_passes', 0);
});

it('withholds the handoff when a model event vetoes the local save after Google confirmation', function (string $event) {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    fakeAcquisitionProvider($business);
    $records = [];
    Log::listen(function (MessageLogged $record) use (&$records): void {
        $records[] = $record;
    });
    CustomerPass::{$event}(static fn (): bool => false);

    try {
        $response = $this->postJson(route('public.join.save', $business->public_id));
    } finally {
        CustomerPass::flushEventListeners();
        CustomerPass::clearBootedModels();
    }

    $response->assertStatus(503)
        ->assertExactJson([
            'outcome' => 'unknown',
            'stage' => 'local',
            'retry_allowed' => false,
        ])
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->assertDatabaseCount('customer_passes', 0);
    expect(DB::transactionLevel())->toBe(0);
    expect($records)->toHaveCount(1);
    expect($records[0]->message)->toBe('customer_pass.creation.failed');
    expect($records[0]->context['reason'])->toBe('save_rejected');
    expect(test()->http->requests())->toHaveCount(4);
    expect(test()->http->requests(
        fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/loyaltyObject'),
    ))->toHaveCount(1);
})->with(['saving', 'creating']);

it('preserves the acquisition JavaScript failure contract independently of cause identity', function (
    string $cause, PassCreationStage $stage, FailureOutcome $outcome, int $status, bool $retry,
) {
    $business = Business::factory()->create(['pass_background_color' => '#123456']);
    $this->mock(GoogleWalletPassCreator::class)->shouldReceive('create')->once()->andThrow(
        new CustomerPassCreationException(CustomerPassCreationFailure::from($cause), stage: $stage, outcome: $outcome),
    );

    $this->postJson(route('public.join.save', $business->public_id))
        ->assertStatus($status)
        ->assertExactJson(['outcome' => $outcome->value, 'stage' => $stage->value, 'retry_allowed' => $retry])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeaderMissing('X-Request-ID');

    $this->assertDatabaseCount('customer_passes', 0);
    expect($this->http->requests())->toBeEmpty();
})->with([
    'safe preparation' => ['database_failed', PassCreationStage::Preparation, FailureOutcome::Definitive, 503, true],
    'safe provider' => ['provider_rejected', PassCreationStage::Provider, FailureOutcome::Definitive, 502, true],
    'unknown provider' => ['provider_rejected', PassCreationStage::Provider, FailureOutcome::Unknown, 503, false],
    'unknown local' => ['database_failed', PassCreationStage::Local, FailureOutcome::Unknown, 503, false],
    'definitive nonretryable' => ['transaction_active', PassCreationStage::Preparation, FailureOutcome::Definitive, 503, false],
]);
