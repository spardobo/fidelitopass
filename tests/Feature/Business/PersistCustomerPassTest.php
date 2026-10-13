<?php

use App\Actions\CustomerPasses\CreateCustomerPass;
use App\Exceptions\CustomerPassCreationException;
use App\Integrations\GoogleWallet\GoogleWalletPassCreator;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Support\CustomerPassCredentials;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Random\Engine;
use Random\Randomizer;

uses(DatabaseTruncation::class);

afterEach(function () {
    $this->truncateTablesForAllConnections();
});

it('prepares private operation material without issuing or contacting a provider', function () {
    $business = Business::factory()->create();
    $this->mock(GoogleWalletPassCreator::class)
        ->shouldReceive('create')
        ->never();
    Queue::fake();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $credentials = CustomerPassCredentials::prepare($business);
    $second = CustomerPassCredentials::prepare($business);

    expect($credentials->businessId)->toBe($business->id);
    expect(Str::isUuid($credentials->publicPassId, 7))->toBeTrue();
    expect($credentials->validationToken)->toMatch('/^[a-f0-9]{64}$/D');
    expect($credentials->manualCode)->toMatch('/^[0-9]{5}$/D');
    expect($second->validationToken)->not->toBe($credentials->validationToken);
    expect($second->publicPassId)->not->toBe($credentials->publicPassId);
    expect($credentials->hashValidationToken())->toBe(hash('sha256', $credentials->validationToken));
    expect($writes)->toBeEmpty();
    $this->assertDatabaseCount('customer_passes', 0);
    Queue::assertNothingPushed();
});

it('keeps private prepared material out of serialization and ordinary debug output', function () {
    $credentials = CustomerPassCredentials::prepare(Business::factory()->create());

    expect(json_decode(json_encode($credentials), true))->toBe([
        'businessId' => $credentials->businessId,
        'publicPassId' => $credentials->publicPassId,
    ]);
    ob_start();
    var_dump($credentials);
    $debug = ob_get_clean();

    expect($debug)->not->toContain($credentials->validationToken, $credentials->manualCode);
    expect(fn () => serialize($credentials))->toThrow(LogicException::class);
    expect(fn () => $credentials->businessId = 0)->toThrow(Error::class);
    expect(fn () => $credentials->publicPassId = 'changed')->toThrow(Error::class);
    expect(fn () => $credentials->validationToken = 'changed')->toThrow(Error::class);
    expect(fn () => $credentials->manualCode = '00000')->toThrow(Error::class);
});

it('preserves all five digits at both allocation boundaries', function (int $candidate, string $expected) {
    $credentials = CustomerPassCredentials::prepare(
        Business::factory()->create(),
        manualCodeRandomizer($candidate),
    );

    expect($credentials->manualCode)->toBe($expected);
})->with([
    'zero' => [0, '00000'],
    'leading zeros' => [7, '00007'],
    'upper bound' => [99999, '99999'],
]);

it('reselects an occupied manual code before provider orchestration can start', function () {
    $business = Business::factory()->create();
    CustomerPass::factory()->for($business)->create(['manual_code' => '00000']);

    $credentials = CustomerPassCredentials::prepare($business, manualCodeRandomizer(0, 7));

    expect($credentials->manualCode)->toBe('00007');
    $this->assertDatabaseCount('customer_passes', 1);
});

it('fails preparation safely after exactly ten occupied candidates', function () {
    $business = Business::factory()->create();
    CustomerPass::factory()->for($business)->create(['manual_code' => '00000']);
    $lookups = 0;
    DB::listen(function (QueryExecuted $query) use (&$lookups): void {
        if (str_contains($query->sql, '"manual_code"')) {
            $lookups++;
        }
    });

    try {
        CustomerPassCredentials::prepare($business, manualCodeRandomizer(...array_fill(0, 10, 0)));
        $this->fail('Ten collisions must exhaust the bounded allocation.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->reason)->toBe('manual_code_exhausted');
        expect($exception->stage->value)->toBe('preparation');
        expect($exception->outcome->value)->toBe('definitive');
        expect($exception->getPrevious())->toBeNull();
    }

    expect($lookups)->toBe(10);
    $this->assertDatabaseCount('customer_passes', 1);
});

it('can allocate an identical numeric code in a different Business', function () {
    CustomerPass::factory()->create(['manual_code' => '00007']);
    $credentials = CustomerPassCredentials::prepare(Business::factory()->create(), manualCodeRandomizer(7));

    expect($credentials->manualCode)->toBe('00007');
    $this->assertDatabaseCount('customer_passes', 1);
});

it('sanitizes preparation query failures without retaining private codes or SQL', function () {
    $business = Business::factory()->create();
    $manualCode = '00007';
    DB::connection()->beforeExecuting(function (string $query, array $bindings) {
        if (str_contains($query, '"customer_passes"') && str_contains($query, '"manual_code"')) {
            throw new QueryException('pgsql', $query, $bindings, new PDOException('Synthetic preparation database failure.'));
        }
    });

    try {
        CustomerPassCredentials::prepare($business, manualCodeRandomizer(7));
        $this->fail('Preparation database failures must remain exceptions.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Customer pass creation failed.');
        expect($exception->getMessage())->not->toContain($manualCode, 'SQL:', 'select ', 'bindings');
        expect($exception->getPrevious())->toBeNull();
        expect(print_r($exception->getTrace(), true))->not->toContain($manualCode, 'select ', 'bindings');
    }

    $this->assertDatabaseCount('customer_passes', 0);
});

it('commits only the confirmed identity and verifier after the provider returns', function () {
    $business = Business::factory()->create();
    $prepared = null;
    fakeConfirmedWalletPass(function (Business $owner, CustomerPassCredentials $credentials) use (&$prepared, $business): void {
        expect($owner->is($business))->toBeTrue();
        expect(DB::transactionLevel())->toBe(0);
        expect(CustomerPass::count())->toBe(0);
        $prepared = $credentials;
    });
    Queue::fake();
    Log::spy();

    $result = app(CreateCustomerPass::class)->handle($business);
    $pass = CustomerPass::query()->sole();

    $this->assertDatabaseHas('customer_passes', [
        'id' => $pass->id,
        'business_id' => $business->id,
        'public_id' => $prepared->publicPassId,
        'wallet_object_id' => 'synthetic-issuer.confirmed-object',
        'validation_token_hash' => hash('sha256', $prepared->validationToken),
        'manual_code' => $prepared->manualCode,
    ]);
    expect($result)->toBe([
        'save_url' => 'https://pay.google.com/gp/v/save/test-only-reference',
        'manual_code' => $pass->manual_code,
    ]);
    expect(DB::transactionLevel())->toBe(0);
    expect($pass->toArray())->not->toHaveKey('validation_token_hash');
    expect(json_encode(DB::table('customer_passes')->first()))->not->toContain($prepared->validationToken);

    foreach (['promotion_participations', 'visits', 'reward_entitlements'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }

    Queue::assertNothingPushed();
    Log::shouldHaveReceived('log')
        ->with('info', 'customer_pass.creation.completed', Mockery::on(
            fn (array $context) => $context['outcome'] === 'issued' && $context['customer_pass_id'] === $pass->id,
        ))
        ->once();
});

it('reuses an identical confirmed identity without rewriting existing facts', function () {
    $business = Business::factory()->create();
    $facts = [];
    fakeConfirmedWalletPass(function (Business $owner, CustomerPassCredentials $credentials) use (&$facts): void {
        $pass = seedConfirmedPass($owner, $credentials);
        $facts = $pass->fresh()->getRawOriginal();
    });
    Log::spy();

    $result = app(CreateCustomerPass::class)->handle($business);
    $pass = CustomerPass::query()->sole();

    expect($pass->getRawOriginal())->toBe($facts);
    expect($result['manual_code'])->toBe($pass->manual_code);
    $this->assertDatabaseCount('customer_passes', 1);
    Log::shouldHaveReceived('log')
        ->with('info', 'customer_pass.creation.completed', Mockery::on(
            fn (array $context) => $context['outcome'] === 'replayed' && $context['customer_pass_id'] === $pass->id,
        ))
        ->once();
});

it('rejects conflicting confirmed identity facts without mutation or private exception context', function (string $column) {
    $business = Business::factory()->create();
    $foreignBusiness = Business::factory()->create();
    $facts = [];
    $prepared = null;
    fakeConfirmedWalletPass(function (Business $owner, CustomerPassCredentials $credentials) use (
        $column, $foreignBusiness, &$facts, &$prepared,
    ): void {
        $prepared = $credentials;
        $replacement = match ($column) {
            'business_id' => $foreignBusiness->id,
            'validation_token_hash' => hash('sha256', 'synthetic-different-token'),
            'manual_code' => 'DIFFERENT123',
            'wallet_object_id' => 'synthetic-issuer.changed',
        };
        $pass = seedConfirmedPass($owner, $credentials);
        DB::table('customer_passes')->where('id', $pass->id)->update([$column => $replacement]);
        $facts = $pass->fresh()->getRawOriginal();
    });

    try {
        app(CreateCustomerPass::class)->handle($business);
        $this->fail('Conflicting confirmed identities must be rejected.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->reason)->toBe('identity_conflict');
        expect($exception->outcome->value)->toBe('unknown');
        expect($exception->canRetry())->toBeFalse();
        expect($exception->getPrevious())->toBeNull();
        expect(json_encode($exception->context()))->not->toContain(
            $prepared->validationToken,
            $prepared->manualCode,
        );
    }

    expect(CustomerPass::query()->sole()->getRawOriginal())->toBe($facts);
})->with(['business_id', 'validation_token_hash', 'manual_code', 'wallet_object_id']);

it('rejects a provider object already attached to another operation or Business', function (bool $foreign) {
    $business = Business::factory()->create();
    $existingBusiness = $foreign ? Business::factory()->create() : $business;
    $existing = CustomerPass::factory()->for($existingBusiness)->create([
        'wallet_object_id' => 'synthetic-issuer.confirmed-object',
    ]);
    $facts = $existing->fresh()->getRawOriginal();
    fakeConfirmedWalletPass();

    expect(fn () => app(CreateCustomerPass::class)->handle($business))
        ->toThrow(CustomerPassCreationException::class);

    expect($existing->fresh()->getRawOriginal())->toBe($facts);
    $this->assertDatabaseCount('customer_passes', 1);
})->with(['same tenant' => false, 'foreign tenant' => true]);

it('rejects a credential collision after confirmation without allocating replacements', function (string $column) {
    $business = Business::factory()->create();
    fakeConfirmedWalletPass(function (Business $owner, CustomerPassCredentials $credentials) use ($column): void {
        CustomerPass::factory()->for($owner)->create([
            $column => $column === 'manual_code'
                ? $credentials->manualCode
                : $credentials->hashValidationToken(),
        ]);
    });

    expect(fn () => app(CreateCustomerPass::class)->handle($business))
        ->toThrow(CustomerPassCreationException::class);

    $this->assertDatabaseCount('customer_passes', 1);
})->with(['manual_code', 'validation_token_hash']);

it('permits the same manual code in another Business without sharing the private verifier', function () {
    $business = Business::factory()->create();
    $foreignBusiness = Business::factory()->create();
    fakeConfirmedWalletPass(function (Business $owner, CustomerPassCredentials $credentials) use ($foreignBusiness): void {
        CustomerPass::factory()->for($foreignBusiness)->create([
            'manual_code' => $credentials->manualCode,
        ]);
    });

    $result = app(CreateCustomerPass::class)->handle($business);
    $passes = CustomerPass::query()->where('manual_code', $result['manual_code'])->get();

    expect($passes)->toHaveCount(2);
    expect($passes->pluck('business_id')->all())->toContain($business->id, $foreignBusiness->id);
    expect($passes->pluck('validation_token_hash')->unique())->toHaveCount(2);
});

it('rejects an unpersisted Business before provider work', function () {
    $this->mock(GoogleWalletPassCreator::class)->shouldNotReceive('create');

    expect(fn () => app(CreateCustomerPass::class)->handle(Business::factory()->make()))
        ->toThrow(CustomerPassCreationException::class);

    $this->assertDatabaseCount('customer_passes', 0);
});

it('refuses nested issuance before contacting Google or allocating credentials', function () {
    $business = Business::factory()->create();
    $this->mock(GoogleWalletPassCreator::class)->shouldNotReceive('create');
    DB::beginTransaction();

    try {
        app(CreateCustomerPass::class)->handle($business);
        $this->fail('A caller-owned transaction cannot establish the issuance commit boundary.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->reason)->toBe('transaction_active');
        expect($exception->canRetry())->toBeFalse();
    } finally {
        DB::rollBack();
    }

    $this->assertDatabaseCount('customer_passes', 0);
});

it('rolls back an unexpected local failure without claiming provider rollback', function () {
    $business = Business::factory()->create();
    fakeConfirmedWalletPass();
    CustomerPass::creating(static function (): void {
        throw new RuntimeException('Synthetic private local failure.');
    });

    try {
        app(CreateCustomerPass::class)->handle($business);
        $this->fail('A failed write must not release the handoff.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->reason)->toBe('unexpected_failure');
        expect($exception->outcome->value)->toBe('unknown');
        expect($exception->canRetry())->toBeFalse();
        expect($exception->getPrevious())->toBeNull();
        expect($exception->getMessage())->not->toContain('Synthetic private');
    } finally {
        CustomerPass::flushEventListeners();
        CustomerPass::clearBootedModels();
    }

    expect(DB::transactionLevel())->toBe(0);
    $this->assertDatabaseCount('customer_passes', 0);
});

it('preserves the provider orphan when the Business disappears before local persistence', function () {
    $business = Business::factory()->create();
    fakeConfirmedWalletPass(static function (Business $owner): void {
        $owner->delete();
    });

    try {
        app(CreateCustomerPass::class)->handle($business);
        $this->fail('The owner must still exist at the local commit boundary.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->reason)->toBe('business_not_found');
        expect($exception->outcome->value)->toBe('unknown');
        expect($exception->canRetry())->toBeFalse();
    }

    $this->assertDatabaseCount('customer_passes', 0);
});

it('sanitizes the final database uniqueness barrier without retaining SQL or private bindings', function () {
    $business = Business::factory()->create();
    CustomerPass::factory()->create(['wallet_object_id' => 'synthetic-issuer.occupied']);
    fakeConfirmedWalletPass();
    CustomerPass::creating(static function (CustomerPass $pass): void {
        $pass->wallet_object_id = 'synthetic-issuer.occupied';
    });

    try {
        app(CreateCustomerPass::class)->handle($business);
        $this->fail('PostgreSQL must reject the conflicting provider binding.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->reason)->toBe('identity_conflict');
        expect($exception->outcome->value)->toBe('unknown');
        expect($exception->getPrevious())->toBeNull();
        expect($exception->context())->toMatchArray([
            'exception_class' => UniqueConstraintViolationException::class,
            'sqlstate' => '23505',
        ]);
        expect(json_encode($exception->context()))->not->toContain('insert into', 'bindings');
    } finally {
        CustomerPass::flushEventListeners();
        CustomerPass::clearBootedModels();
    }

    $this->assertDatabaseCount('customer_passes', 1);
});

it('sanitizes unexpected database failure without claiming a known commit outcome', function () {
    $business = Business::factory()->create();
    fakeConfirmedWalletPass();
    CustomerPass::creating(static function (CustomerPass $pass): void {
        $pass->wallet_object_id = str_repeat('a', 256);
    });

    try {
        app(CreateCustomerPass::class)->handle($business);
        $this->fail('PostgreSQL must reject the oversized provider identity.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->reason)->toBe('database_failed');
        expect($exception->outcome->value)->toBe('unknown');
        expect($exception->getPrevious())->toBeNull();
        expect($exception->context())->toMatchArray([
            'exception_class' => QueryException::class,
            'sqlstate' => '22001',
        ]);
    } finally {
        CustomerPass::flushEventListeners();
        CustomerPass::clearBootedModels();
    }

    $this->assertDatabaseCount('customer_passes', 0);
});

it('does not equate a lost commit response with rollback or allocate another pass', function () {
    $business = Business::factory()->create();
    fakeConfirmedWalletPass();
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $faultDispatcher = clone $dispatcher;
    $faultDispatcher->listen(TransactionCommitted::class, static function (): void {
        throw new PDOException('Synthetic loss after the actual database commit.');
    });
    $connection->setEventDispatcher($faultDispatcher);

    try {
        app(CreateCustomerPass::class)->handle($business);
        $this->fail('A lost acknowledgement cannot establish a confirmed commit.');
    } catch (CustomerPassCreationException $exception) {
        expect($exception->outcome->value)->toBe('unknown');
        expect($exception->canRetry())->toBeFalse();
        expect($exception->getPrevious())->toBeNull();
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }

    expect(DB::transactionLevel())->toBe(0);
    $this->assertDatabaseCount('customer_passes', 1);
});

/**
 * Confirms one synthetic provider result while exposing its real generated credentials to the fixture.
 *
 * @param  Closure|null  $onConfirmation  Optional competing write or assertion before the local transaction.
 */
function fakeConfirmedWalletPass(?Closure $onConfirmation = null): void
{
    test()->mock(GoogleWalletPassCreator::class)
        ->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (Business $business, CustomerPassCredentials $credentials) use ($onConfirmation): array {
            expect(DB::transactionLevel())->toBe(0);
            $onConfirmation?->__invoke($business, $credentials);

            return [
                'object_id' => 'synthetic-issuer.confirmed-object',
                'save_url' => 'https://pay.google.com/gp/v/save/test-only-reference',
                'confirmed_by_readback' => false,
            ];
        });
}

/**
 * Seeds a competing committed identity for the same already-confirmed provider object.
 *
 * @param  Business  $business  Owner of the generated credentials.
 * @param  CustomerPassCredentials  $credentials  Real operation credentials selected by the action.
 * @return CustomerPass Existing identity used to exercise replay and conflict outcomes.
 */
function seedConfirmedPass(Business $business, CustomerPassCredentials $credentials): CustomerPass
{
    return CustomerPass::factory()->for($business)->create([
        'public_id' => $credentials->publicPassId,
        'wallet_object_id' => 'synthetic-issuer.confirmed-object',
        'validation_token_hash' => $credentials->hashValidationToken(),
        'manual_code' => $credentials->manualCode,
    ]);
}

it('waits for a contended PostgreSQL Business lock before replaying the matching identity', function () {
    $business = Business::factory()->create();
    $socket = null;
    $pid = null;
    fakeConfirmedWalletPass(function (Business $owner, CustomerPassCredentials $credentials) use (&$socket, &$pid): void {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Could not create replay synchronization sockets.');
        }

        foreach ($sockets as $connection) {
            stream_set_timeout($connection, 10);
        }

        // fork without sharing an active PostgreSQL connection
        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($sockets[0]);
            fclose($sockets[1]);
            throw new RuntimeException('Could not start the competing writer.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            DB::purge();

            try {
                DB::beginTransaction();
                Business::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
                $pass = seedConfirmedPass($owner, $credentials);
                fwrite($sockets[1], "locked\n");
                $parentBackend = trim((string) fgets($sockets[1]));

                if (! ctype_digit($parentBackend)) {
                    throw new RuntimeException('The issuer did not identify its database connection.');
                }

                // release the competing write only after the real action waits for this lock
                $deadline = hrtime(true) + 5_000_000_000;
                $blocked = false;

                do {
                    $blocked = (bool) DB::selectOne(
                        'select pg_backend_pid() = any(pg_blocking_pids(?::integer)) as blocked',
                        [(int) $parentBackend],
                    )->blocked;

                    if (! $blocked) {
                        usleep(10_000);
                    }
                } while (! $blocked && hrtime(true) < $deadline);

                if (! $blocked) {
                    throw new RuntimeException('Issuance did not wait for the held Business lock.');
                }

                DB::commit();
                fwrite($sockets[1], (string) $pass->id);
                fclose($sockets[1]);
                exit(0);
            } catch (Throwable) {
                DB::rollBack();
                fwrite($sockets[1], 'failed');
                fclose($sockets[1]);
                exit(1);
            }
        }

        fclose($sockets[1]);
        $socket = $sockets[0];
        DB::purge();
        DB::statement("SET lock_timeout = '8s'");

        if (fgets($socket) !== "locked\n") {
            throw new RuntimeException('The competing writer did not reach its lock barrier.');
        }

        $backendId = DB::selectOne('select pg_backend_pid() as id')->id;
        fwrite($socket, $backendId."\n");
    });
    Log::spy();

    try {
        $result = app(CreateCustomerPass::class)->handle($business);
        $childId = stream_get_contents($socket);
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }

        if (is_int($pid) && $pid > 0) {
            pcntl_waitpid($pid, $status);
        }
    }

    $pass = CustomerPass::query()->sole();
    expect($childId)->toBe((string) $pass->id);
    expect(pcntl_wexitstatus($status))->toBe(0);
    expect($result['manual_code'])->toBe($pass->manual_code);
    expect(DB::transactionLevel())->toBe(0);
    Log::shouldHaveReceived('log')
        ->with('info', 'customer_pass.creation.completed', Mockery::on(
            fn (array $context) => $context['outcome'] === 'replayed',
        ))
        ->once();
});

/**
 * Supplies exact native random-engine values without changing production randomness.
 *
 * @param  int  ...$candidates  In-range numeric candidates consumed in order.
 * @return Randomizer Native randomizer backed by the finite test sequence.
 */
function manualCodeRandomizer(int ...$candidates): Randomizer
{
    return new Randomizer(new class($candidates) implements Engine
    {
        /**
         * Stores the finite candidate sequence.
         *
         * @param  list<int>  $candidates  Values returned by successive draws.
         */
        public function __construct(private array $candidates) {}

        /**
         * Emits one unsigned little-endian candidate for the native interval mapping.
         *
         * @return string Four deterministic bytes.
         *
         * @throws LogicException If allocation exceeds the supplied sequence.
         */
        public function generate(): string
        {
            $candidate = array_shift($this->candidates);

            if ($candidate === null) {
                throw new LogicException('Unexpected additional manual-code draw.');
            }

            return pack('V', $candidate);
        }
    });
}
