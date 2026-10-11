<?php

use App\Actions\CustomerPasses\IssueCustomerPass;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Support\CustomerPassCredentials;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

afterEach(function () {
    $this->truncateTablesForAllConnections();
});

it('prepares private operation material without issuing or contacting a provider', function () {
    $business = Business::factory()->create();
    Http::preventStrayRequests();
    Queue::fake();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $credentials = CustomerPassCredentials::prepare($business);
    $second = CustomerPassCredentials::prepare($business);

    expect($credentials->businessId())->toBe($business->id);
    expect(Str::isUuid($credentials->publicId(), 7))->toBeTrue();
    expect($credentials->validationToken())->toMatch('/^[a-f0-9]{64}$/D');
    expect($credentials->manualCode())->toMatch('/^[A-Z0-9]{12}$/D');
    expect($second->validationToken())->not->toBe($credentials->validationToken());
    expect($second->publicId())->not->toBe($credentials->publicId());
    expect($credentials->validationTokenHash())->toBe(hash('sha256', $credentials->validationToken()));
    expect($writes)->toBeEmpty();
    $this->assertDatabaseCount('customer_passes', 0);
    Queue::assertNothingPushed();
});

it('keeps private prepared material out of serialization and ordinary debug output', function () {
    $credentials = CustomerPassCredentials::prepare(Business::factory()->create());

    expect(json_encode($credentials))->toBe('{}');
    ob_start();
    var_dump($credentials);
    $debug = ob_get_clean();

    expect($debug)->not->toContain($credentials->validationToken(), $credentials->manualCode());
    expect(fn () => serialize($credentials))->toThrow(LogicException::class);
});

it('reselects an occupied manual code before provider orchestration can start', function () {
    $business = Business::factory()->create();
    CustomerPass::factory()->for($business)->create(['manual_code' => 'ABCDEF123456']);
    $codes = ['abcdef123456', 'abcdef654321'];
    Str::createRandomStringsUsing(function () use (&$codes) {
        return array_shift($codes);
    });

    try {
        $credentials = CustomerPassCredentials::prepare($business);
    } finally {
        Str::createRandomStringsNormally();
    }

    expect($credentials->manualCode())->toBe('ABCDEF654321');
    $this->assertDatabaseCount('customer_passes', 1);
});

it('fails preparation safely when a bounded code search is exhausted', function () {
    $business = Business::factory()->create();
    CustomerPass::factory()->for($business)->create(['manual_code' => 'ABCDEF123456']);
    Str::createRandomStringsUsing(fn () => 'abcdef123456');

    try {
        expect(fn () => CustomerPassCredentials::prepare($business))->toThrow(RuntimeException::class);
    } finally {
        Str::createRandomStringsNormally();
    }

    $this->assertDatabaseCount('customer_passes', 1);
});

it('commits only the Business identity and verifier for a trusted confirmed provider object', function () {
    $business = Business::factory()->create();
    $credentials = CustomerPassCredentials::prepare($business);
    Http::preventStrayRequests();
    Queue::fake();
    Log::spy();

    $pass = app(IssueCustomerPass::class)->handle($business, $credentials, 'synthetic-issuer.confirmed-object');

    $this->assertDatabaseHas('customer_passes', [
        'id' => $pass->id,
        'business_id' => $business->id,
        'public_id' => $credentials->publicId(),
        'wallet_object_id' => 'synthetic-issuer.confirmed-object',
        'validation_token_hash' => hash('sha256', $credentials->validationToken()),
        'manual_code' => $credentials->manualCode(),
    ]);
    expect(DB::transactionLevel())->toBe(0);
    expect($pass->toArray())->not->toHaveKey('validation_token_hash');
    expect(json_encode(DB::table('customer_passes')->first()))->not->toContain($credentials->validationToken());
    foreach (['promotion_participations', 'visits', 'reward_entitlements'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Queue::assertNothingPushed();
    Log::shouldHaveReceived('info')->with('customer_pass.issued', Mockery::on(
        fn (array $context) => $context === [
            'business_id' => $business->id,
            'customer_pass_id' => $pass->id,
            'operation_id' => $credentials->publicId(),
            'outcome' => 'issued',
        ]
    ))->once();
});

it('returns the same pass for an exact current-operation replay without rewriting facts', function () {
    $business = Business::factory()->create();
    $credentials = CustomerPassCredentials::prepare($business);
    $issue = app(IssueCustomerPass::class);
    $original = $issue->handle($business, $credentials, 'synthetic-issuer.replay');
    $facts = $original->fresh()->getRawOriginal();

    $replayed = $issue->handle($business->fresh(), $credentials, 'synthetic-issuer.replay');

    expect($replayed->is($original))->toBeTrue();
    expect($replayed->getRawOriginal())->toBe($facts);
    $this->assertDatabaseCount('customer_passes', 1);
});

it('rejects a prepared operation used for another Business', function () {
    $business = Business::factory()->create();
    $credentials = CustomerPassCredentials::prepare($business);
    $foreignBusiness = Business::factory()->create();

    expect(fn () => app(IssueCustomerPass::class)->handle($foreignBusiness, $credentials, 'synthetic-issuer.foreign'))
        ->toThrow(DomainException::class);

    $this->assertDatabaseCount('customer_passes', 0);
});

it('rejects conflicting replay facts without mutation or private exception context', function (string $column) {
    $business = Business::factory()->create();
    $credentials = CustomerPassCredentials::prepare($business);
    $issue = app(IssueCustomerPass::class);
    $pass = $issue->handle($business, $credentials, 'synthetic-issuer.original');
    $replacement = match ($column) {
        'business_id' => Business::factory()->create()->id,
        'validation_token_hash' => hash('sha256', 'synthetic-different-token'),
        'manual_code' => 'DIFFERENT123',
        'wallet_object_id' => 'synthetic-issuer.changed',
    };
    DB::table('customer_passes')->where('id', $pass->id)->update([$column => $replacement]);
    $facts = $pass->fresh()->getRawOriginal();

    try {
        $issue->handle($business, $credentials, 'synthetic-issuer.original');
        $this->fail('Conflicting replay must be rejected.');
    } catch (DomainException $exception) {
        expect($exception->getMessage())->not->toContain($credentials->validationToken(), $credentials->manualCode());
        expect($exception->getPrevious())->toBeNull();
    }

    expect($pass->fresh()->getRawOriginal())->toBe($facts);
    $this->assertDatabaseCount('customer_passes', 1);
})->with(['business_id', 'validation_token_hash', 'manual_code', 'wallet_object_id']);

it('rejects a provider object already bound to another operation or Business', function (bool $foreign) {
    $business = Business::factory()->create();
    $existingBusiness = $foreign ? Business::factory()->create() : $business;
    $issue = app(IssueCustomerPass::class);
    $existing = $issue->handle($existingBusiness, CustomerPassCredentials::prepare($existingBusiness), 'synthetic-issuer.shared');
    $credentials = CustomerPassCredentials::prepare($business);

    expect(fn () => $issue->handle($business, $credentials, 'synthetic-issuer.shared'))->toThrow(DomainException::class);

    $this->assertModelExists($existing);
    $this->assertDatabaseCount('customer_passes', 1);
})->with(['same tenant' => false, 'foreign tenant' => true]);

it('does not silently reselect a manual code that collided after preparation', function () {
    $business = Business::factory()->create();
    $credentials = CustomerPassCredentials::prepare($business);
    CustomerPass::factory()->for($business)->create(['manual_code' => $credentials->manualCode()]);

    expect(fn () => app(IssueCustomerPass::class)->handle($business, $credentials, 'synthetic-issuer.collision'))
        ->toThrow(DomainException::class);

    $this->assertDatabaseCount('customer_passes', 1);
});

it('rejects malformed provider identity before any local write', function (string $objectId) {
    $business = Business::factory()->create();
    $credentials = CustomerPassCredentials::prepare($business);

    expect(fn () => app(IssueCustomerPass::class)->handle($business, $credentials, $objectId))
        ->toThrow(InvalidArgumentException::class);

    $this->assertDatabaseCount('customer_passes', 0);
})->with(['', '   ', 'not-an-object', 'issuer.object with spaces', str_repeat('a', 256).'.object']);

it('refuses nested issuance rather than reporting a savepoint as confirmed commit', function () {
    $business = Business::factory()->create();
    $credentials = CustomerPassCredentials::prepare($business);
    DB::beginTransaction();

    try {
        expect(fn () => app(IssueCustomerPass::class)->handle($business, $credentials, 'synthetic-issuer.nested'))
            ->toThrow(LogicException::class);
    } finally {
        DB::rollBack();
    }

    $this->assertDatabaseCount('customer_passes', 0);
});

it('rolls back a failed local write without claiming provider rollback', function () {
    $business = Business::factory()->create();
    $credentials = CustomerPassCredentials::prepare($business);
    CustomerPass::creating(function () {
        throw new RuntimeException('Synthetic local write failure.');
    });

    try {
        expect(fn () => app(IssueCustomerPass::class)->handle($business, $credentials, 'synthetic-issuer.orphan'))
            ->toThrow(RuntimeException::class, 'Synthetic local write failure.');
    } finally {
        CustomerPass::flushEventListeners();
        CustomerPass::clearBootedModels();
    }

    expect(DB::transactionLevel())->toBe(0);
    $this->assertDatabaseCount('customer_passes', 0);
});

it('serializes overlapping exact replays through a contended PostgreSQL Business lock', function () {
    $business = Business::factory()->create();
    $credentials = CustomerPassCredentials::prepare($business);
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        throw new RuntimeException('Could not create replay synchronization sockets.');
    }
    foreach ($sockets as $socket) {
        stream_set_timeout($socket, 10);
    }
    DB::disconnect();
    $pid = pcntl_fork();
    if ($pid === -1) {
        fclose($sockets[0]);
        fclose($sockets[1]);
        throw new RuntimeException('Could not start an isolated replay process.');
    }
    if ($pid === 0) {
        fclose($sockets[0]);
        DB::purge();
        try {
            DB::statement("SET lock_timeout = '5s'");
            $backendId = DB::selectOne('select pg_backend_pid() as id')->id;
            fwrite($sockets[1], $backendId."\n");
            if (fgets($sockets[1]) !== "start\n") {
                throw new RuntimeException('Replay start barrier was not released.');
            }
            $pass = app(IssueCustomerPass::class)->handle($business, $credentials, 'synthetic-issuer.concurrent');
            fwrite($sockets[1], (string) $pass->id);
        } catch (Throwable) {
            fwrite($sockets[1], 'failed');
        }
        fclose($sockets[1]);
        exit(0);
    }
    fclose($sockets[1]);
    $overlapped = false;

    try {
        $childBackendId = trim((string) fgets($sockets[0]));
        if (! ctype_digit($childBackendId)) {
            throw new RuntimeException('Replay process did not reach its readiness barrier.');
        }
        DB::listen(function (QueryExecuted $query) use ($sockets, $childBackendId, &$overlapped) {
            if ($overlapped || ! str_contains($query->sql, '"businesses"') || ! str_contains($query->sql, 'for update')) {
                return;
            }

            // Release the ready child only once this issuance owns the Business lock.
            fwrite($sockets[0], "start\n");
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $overlapped = (bool) DB::selectOne(
                    'select pg_backend_pid() = any(pg_blocking_pids(?::integer)) as blocked',
                    [(int) $childBackendId],
                )->blocked;
                if ($overlapped) {
                    return;
                }
                usleep(10_000);
            } while (hrtime(true) < $deadline);

            throw new RuntimeException('Replay did not contend for the held Business lock.');
        });

        $pass = app(IssueCustomerPass::class)->handle($business, $credentials, 'synthetic-issuer.concurrent');
        $childId = stream_get_contents($sockets[0]);
    } finally {
        fclose($sockets[0]);
        pcntl_waitpid($pid, $status);
    }

    expect($overlapped)->toBeTrue();
    expect($childId)->toBe((string) $pass->id);
    expect(pcntl_wexitstatus($status))->toBe(0);
    $this->assertDatabaseCount('customer_passes', 1);
});

it('rejects preparation for an unpersisted Business without creating identity', function () {
    expect(fn () => CustomerPassCredentials::prepare(Business::factory()->make()))->toThrow(LogicException::class);

    $this->assertDatabaseCount('customer_passes', 0);
});

it('rejects a verifier already bound to a different operation', function () {
    $business = Business::factory()->create();
    $credentials = CustomerPassCredentials::prepare($business);
    CustomerPass::factory()->create(['validation_token_hash' => $credentials->validationTokenHash()]);

    expect(fn () => app(IssueCustomerPass::class)->handle($business, $credentials, 'synthetic-issuer.verifier-conflict'))
        ->toThrow(DomainException::class);

    $this->assertDatabaseCount('customer_passes', 1);
});

it('sanitizes final database uniqueness failures without exposing SQL bindings', function () {
    $business = Business::factory()->create();
    $credentials = CustomerPassCredentials::prepare($business);
    CustomerPass::factory()->create(['wallet_object_id' => 'synthetic-issuer.occupied']);
    CustomerPass::creating(function (CustomerPass $pass) {
        $pass->wallet_object_id = 'synthetic-issuer.occupied';
    });

    try {
        app(IssueCustomerPass::class)->handle($business, $credentials, 'synthetic-issuer.preflight-free');
        $this->fail('The database must reject the conflicting provider binding.');
    } catch (DomainException $exception) {
        expect($exception->getMessage())->not->toContain($credentials->manualCode(), $credentials->validationToken(), 'insert into');
        expect($exception->getPrevious())->toBeNull();
    } finally {
        CustomerPass::flushEventListeners();
        CustomerPass::clearBootedModels();
    }

    $this->assertDatabaseCount('customer_passes', 1);
});

it('sanitizes unexpected database failures without claiming a known commit outcome', function () {
    $business = Business::factory()->create();
    $credentials = CustomerPassCredentials::prepare($business);
    CustomerPass::creating(function (CustomerPass $pass) {
        $pass->wallet_object_id = str_repeat('a', 256);
    });

    try {
        app(IssueCustomerPass::class)->handle($business, $credentials, 'synthetic-issuer.invalid-local-write');
        $this->fail('The database must reject the oversized provider identity.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Customer pass persistence failed; local commit outcome is not confirmed.');
        expect($exception->getPrevious())->toBeNull();
    } finally {
        CustomerPass::flushEventListeners();
        CustomerPass::clearBootedModels();
    }

    $this->assertDatabaseCount('customer_passes', 0);
});

it('does not equate a lost commit response with rollback or allocate another pass', function () {
    $business = Business::factory()->create();
    $credentials = CustomerPassCredentials::prepare($business);
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $faultDispatcher = clone $dispatcher;
    $faultDispatcher->listen(TransactionCommitted::class, function () {
        throw new PDOException('Synthetic loss after the actual database commit.');
    });
    $connection->setEventDispatcher($faultDispatcher);

    try {
        expect(fn () => app(IssueCustomerPass::class)->handle($business, $credentials, 'synthetic-issuer.uncertain-response'))
            ->toThrow(RuntimeException::class, 'Customer pass persistence failed; local commit outcome is not confirmed.');
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }

    $existing = CustomerPass::query()->sole();
    $replayed = app(IssueCustomerPass::class)->handle($business, $credentials, 'synthetic-issuer.uncertain-response');
    expect($replayed->is($existing))->toBeTrue();
    $this->assertDatabaseCount('customer_passes', 1);
});

it('sanitizes preparation query failures without retaining private codes or SQL', function () {
    $business = Business::factory()->create();
    $manualCode = 'PRIVATE12345';
    Str::createRandomStringsUsing(fn () => strtolower($manualCode));
    DB::connection()->beforeExecuting(function (string $query, array $bindings) {
        if (str_contains($query, '"customer_passes"') && str_contains($query, '"manual_code"')) {
            throw new QueryException('pgsql', $query, $bindings, new PDOException('Synthetic preparation database failure.'));
        }
    });

    try {
        CustomerPassCredentials::prepare($business);
        $this->fail('Preparation database failures must remain exceptions.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Customer pass credential preparation failed.');
        expect($exception->getMessage())->not->toContain($manualCode, 'SQL:', 'select ', 'bindings');
        expect($exception->getPrevious())->toBeNull();
        expect(print_r($exception->getTrace(), true))->not->toContain($manualCode, 'select ', 'bindings');
    } finally {
        Str::createRandomStringsNormally();
    }

    $this->assertDatabaseCount('customer_passes', 0);
});
