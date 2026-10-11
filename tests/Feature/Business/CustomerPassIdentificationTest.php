<?php

use App\Actions\CustomerPasses\IdentifyCustomerPass;
use App\Models\Business;
use App\Models\CustomerPass;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['auth.customer_pass_lookup.max_attempts' => 3, 'auth.customer_pass_lookup.decay_seconds' => 60]);
});

it('identifies an owned pass read-only by a private token or normalized manual fallback', function (bool $manual) {
    $business = Business::factory()->create();
    $this->actingAs($business->user);
    $token = str_repeat('a', 64);
    $pass = CustomerPass::factory()->for($business)->create([
        'validation_token_hash' => hash('sha256', $token),
        'manual_code' => 'ABCDEF123456',
        'wallet_object_id' => 'synthetic-issuer.lookup',
    ]);
    $facts = $pass->fresh()->getRawOriginal();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $found = app(IdentifyCustomerPass::class)->handle(
        $business,
        validationToken: $manual ? null : $token,
        manualCode: $manual ? ' abcd-ef12 3456 ' : null,
    );

    expect($found?->is($pass))->toBeTrue();
    expect($pass->fresh()->getRawOriginal())->toBe($facts);
    expect($writes)->toBeEmpty();
    foreach (['promotion_participations', 'visits', 'reward_entitlements'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
})->with(['private token' => false, 'manual fallback' => true]);

it('refuses unauthenticated private lookup even with valid credentials', function () {
    $business = Business::factory()->create();
    CustomerPass::factory()->for($business)->create(['manual_code' => 'ABCDEF123456']);

    expect(app(IdentifyCustomerPass::class)->handle($business, manualCode: 'ABCDEF123456'))->toBeNull();
});

it('refuses a foreign Business even when the caller supplies its valid credentials', function () {
    $business = Business::factory()->create();
    $otherBusiness = Business::factory()->create();
    $this->actingAs($business->user);
    CustomerPass::factory()->for($otherBusiness)->create(['manual_code' => 'ABCDEF123456']);

    expect(app(IdentifyCustomerPass::class)->handle($otherBusiness, manualCode: 'ABCDEF123456'))->toBeNull();
});

it('checks persisted ownership rather than tampered model attributes', function () {
    $business = Business::factory()->create();
    $foreignBusiness = Business::factory()->create();
    $this->actingAs($business->user);
    CustomerPass::factory()->for($foreignBusiness)->create(['manual_code' => 'ABCDEF123456']);
    $foreignBusiness->user_id = $business->user_id;

    expect(app(IdentifyCustomerPass::class)->handle($foreignBusiness, manualCode: 'ABCDEF123456'))->toBeNull();
});

it('does not identify a foreign pass inside an owned Business', function (bool $manual) {
    $business = Business::factory()->create();
    $this->actingAs($business->user);
    $token = str_repeat('b', 64);
    CustomerPass::factory()->create(['validation_token_hash' => hash('sha256', $token), 'manual_code' => 'ABCDEF123456']);

    expect(app(IdentifyCustomerPass::class)->handle(
        $business,
        validationToken: $manual ? null : $token,
        manualCode: $manual ? 'ABCDEF123456' : null,
    ))->toBeNull();
})->with(['private token' => false, 'manual fallback' => true]);

it('rejects malformed credentials before pass lookup', function (?string $token, ?string $manual) {
    $business = Business::factory()->create();
    $this->actingAs($business->user);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        $queries[] = $query->sql;
    });

    expect(app(IdentifyCustomerPass::class)->handle($business, $token, $manual))->toBeNull();

    expect(collect($queries)->filter(fn (string $query) => str_contains($query, 'customer_passes')))->toBeEmpty();
})->with([
    'missing' => [null, null],
    'ambiguous factors' => [str_repeat('a', 64), 'ABCDEF123456'],
    'empty token' => ['', null],
    'short token' => ['not-private', null],
    'acquisition UUID' => ['019b76da-a800-7000-8000-000000000001', null],
    'public link' => ['https://example.test/join/019b76da-a800-7000-8000-000000000001', null],
    'oversized token' => [str_repeat('a', 65), null],
    'token injection' => ["' OR 1=1 --", null],
    'empty manual' => [null, ''],
    'manual punctuation' => [null, "ABCDEF12345'"],
    'short manual' => [null, 'ABCDEF'],
    'oversized manual' => [null, str_repeat('a', 5000)],
]);

it('does not accept public pass or Business identity as either credential', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);
    $pass = CustomerPass::factory()->for($business)->create();
    $identify = app(IdentifyCustomerPass::class);

    expect($identify->handle($business, validationToken: $business->public_id))->toBeNull();
    expect($identify->handle($business, manualCode: $pass->public_id))->toBeNull();
});

it('resolves identical manual codes only within the authenticated Business', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);
    $ownPass = CustomerPass::factory()->for($business)->create(['manual_code' => 'ABCDEF123456']);
    CustomerPass::factory()->create(['manual_code' => 'ABCDEF123456']);

    expect(app(IdentifyCustomerPass::class)->handle($business, manualCode: 'abcdef123456')?->is($ownPass))->toBeTrue();
});

it('throttles repeated invalid credentials and logs only non-secret context', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);
    CustomerPass::factory()->for($business)->create(['manual_code' => 'ABCDEF123456']);
    Log::spy();
    $identify = app(IdentifyCustomerPass::class);
    $this->freezeTime();

    for ($attempt = 0; $attempt < 3; $attempt++) {
        expect($identify->handle($business, manualCode: 'DOESNOTEXIST'))->toBeNull();
    }
    expect($identify->handle($business, manualCode: 'ABCDEF123456'))->toBeNull();

    Log::shouldHaveReceived('warning')->with('customer_pass.lookup_throttled', [
        'actor_id' => $business->user_id,
        'business_id' => $business->id,
        'outcome' => 'throttled',
    ])->once();
    $this->travel(61)->seconds();
    expect($identify->handle($business, manualCode: 'ABCDEF123456'))->not->toBeNull();
});

it('does not let another owners failed lookups throttle this Business', function () {
    $first = Business::factory()->create();
    $second = Business::factory()->create();
    $this->actingAs($first->user);
    $identify = app(IdentifyCustomerPass::class);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $identify->handle($first, manualCode: 'DOESNOTEXIST');
    }
    $this->actingAs($second->user);
    $pass = CustomerPass::factory()->for($second)->create(['manual_code' => 'ABCDEF123456']);

    expect($identify->handle($second, manualCode: 'ABCDEF123456')?->is($pass))->toBeTrue();
});

it('sanitizes manual lookup query failures without returning ordinary absence', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);
    $manualCode = 'PRIVATE12345';
    DB::connection()->beforeExecuting(function (string $query, array $bindings) {
        if (str_contains($query, '"customer_passes"') && str_contains($query, '"manual_code"')) {
            throw new QueryException('pgsql', $query, $bindings, new PDOException('Synthetic lookup database failure.'));
        }
    });

    try {
        app(IdentifyCustomerPass::class)->handle($business, manualCode: $manualCode);
        $this->fail('Lookup database failures must remain exceptions.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Customer pass lookup failed.');
        expect($exception->getMessage())->not->toContain($manualCode, 'SQL:', 'select ', 'bindings');
        expect($exception->getPrevious())->toBeNull();
        expect(print_r($exception->getTrace(), true))->not->toContain($manualCode, 'select ', 'bindings');
    }
});

it('sanitizes database failures from ownership and lookup limiter boundaries', function (string $phase) {
    $business = Business::factory()->create();
    $this->actingAs($business->user);
    $failure = new QueryException('pgsql', 'select payload from synthetic_store where key = ?', ['synthetic-binding'], new PDOException('Synthetic database failure.'));
    if ($phase === 'ownership') {
        DB::connection()->beforeExecuting(function (string $query) use ($failure) {
            if (str_contains($query, '"businesses"') && str_contains($query, '"user_id"')) {
                throw $failure;
            }
        });
    } elseif ($phase === 'limiter read') {
        RateLimiter::shouldReceive('tooManyAttempts')->once()->andThrow($failure);
    } else {
        RateLimiter::shouldReceive('tooManyAttempts')->once()->andReturnFalse();
        RateLimiter::shouldReceive('hit')->once()->andThrow($failure);
    }

    try {
        app(IdentifyCustomerPass::class)->handle($business, manualCode: 'PRIVATE12345');
        $this->fail('Infrastructure failure must not become ordinary absence.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Customer pass lookup failed.');
        expect($exception->getPrevious())->toBeNull();
        expect(print_r($exception->getTrace(), true))->not->toContain('PRIVATE12345', 'synthetic-binding', 'synthetic_store');
    }
})->with(['ownership', 'limiter read', 'limiter write']);

it('does not hide unrelated cache programming failures as database lookup failures', function () {
    $business = Business::factory()->create();
    $this->actingAs($business->user);
    RateLimiter::shouldReceive('tooManyAttempts')->once()->andThrow(new LogicException('Synthetic unrelated cache defect.'));

    expect(fn () => app(IdentifyCustomerPass::class)->handle($business, manualCode: 'PRIVATE12345'))
        ->toThrow(LogicException::class, 'Synthetic unrelated cache defect.');
});
