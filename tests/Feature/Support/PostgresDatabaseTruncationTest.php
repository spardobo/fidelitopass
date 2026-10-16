<?php

use App\Models\CustomerPass;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PostgresDatabaseTruncation;

uses(PostgresDatabaseTruncation::class);

afterEach(function () {
    $this->truncateTablesForAllConnections();
});

it('clears related fixtures with constraint changes inside a transaction and leaves foreign keys enforced', function () {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('pgsql');
    expect($connection->selectOne('select current_database() as name')->name)->toBe('testing');
    expect($connection->transactionLevel())->toBe(0);
    $pass = CustomerPass::factory()->create();
    $orphan = $pass->getAttributes();
    unset($orphan['id']);
    $constraintLevels = [];
    $dispatcher = $connection->getEventDispatcher();
    DB::listen(function (QueryExecuted $query) use (&$constraintLevels): void {
        if (str_starts_with(strtoupper($query->sql), 'SET CONSTRAINTS')) {
            $constraintLevels[] = $query->connection->transactionLevel();
        }
    });

    $this->truncateTablesForAllConnections();

    expect($constraintLevels)->toBe([1, 1]);
    expect($connection->transactionLevel())->toBe(0);
    expect($connection->getEventDispatcher())->toBe($dispatcher);
    $this->assertDatabaseCount('customer_passes', 0);
    $this->assertDatabaseCount('businesses', 0);
    $this->assertDatabaseCount('users', 0);
    expect(DB::table('migrations')->count())->toBeGreaterThan(0);
    $failure = null;
    try {
        DB::table('customer_passes')->insert($orphan);
    } catch (QueryException $exception) {
        $failure = $exception;
    }
    expect($failure)->toBeInstanceOf(QueryException::class);
    expect($failure->errorInfo[0])->toBe('23503');
    $this->assertDatabaseCount('customer_passes', 0);
});

it('rolls back partial cleanup and restores the dispatcher when a later truncation fails', function () {
    $connection = DB::connection();
    expect($connection->selectOne('select current_database() as name')->name)->toBe('testing');
    CustomerPass::factory()->create();
    $before = [];
    foreach (['businesses', 'customer_passes', 'users'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    $dispatcher = $connection->getEventDispatcher();
    $sentinel = new RuntimeException('Synthetic cleanup failure.');
    $armed = true;
    $truncations = 0;
    $connection->beforeExecuting(function (string $query) use (&$armed, &$truncations, $sentinel): void {
        if ($armed && str_starts_with(strtolower($query), 'truncate ')) {
            $truncations++;
            if ($truncations === 2) {
                throw $sentinel;
            }
        }
    });
    $failure = null;

    try {
        $this->truncateTablesForAllConnections();
    } catch (RuntimeException $exception) {
        $failure = $exception;
    } finally {
        $armed = false;
    }

    expect($failure)->toBe($sentinel);
    expect($truncations)->toBe(2);
    expect($connection->transactionLevel())->toBe(0);
    expect($connection->getEventDispatcher())->toBe($dispatcher);
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($rows);
    }
});
