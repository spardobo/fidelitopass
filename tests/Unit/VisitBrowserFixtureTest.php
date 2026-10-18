<?php

use App\Integrations\GoogleWallet\GoogleWalletClient;
use App\Integrations\GoogleWallet\GoogleWalletConfiguration;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

it('prints visits help before any runner side effect', function () {
    $process = new Process(['bash', base_path('scripts/quality/browser/run-isolated-promotions.sh'), '--help']);
    $process->setTimeout(5)->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('--visits', '--public-join', 'Usage:')
        ->and($process->getErrorOutput())->toBe('');
});

it('refuses invalid visit contexts before changing stored rows or provider effects', function (string $script, string $operation, string $environment, string $runtime, string $database, string $message) {
    expect(app()->environment('testing'))->toBeTrue();
    expect(DB::connection()->getDriverName())->toBe('pgsql');
    expect(DB::selectOne('select current_database() as name')->name)->toBe('testing');
    $before = visitBrowserStoredFingerprint();
    $effectsPath = storage_path('visit-effects.json');
    $effects = is_file($effectsPath) ? file_get_contents($effectsPath) : null;
    $process = new Process([PHP_BINARY, base_path('scripts/quality/browser/'.$script), $operation], base_path(), [
        'APP_ENV' => $environment,
        'DB_DATABASE' => $database,
        'DB_URL' => '',
        'LARAVEL_STORAGE_PATH' => $runtime,
        'APP_CONFIG_CACHE' => $runtime.'/cache/config.php',
        'APP_SERVICES_CACHE' => $runtime.'/cache/services.php',
        'APP_PACKAGES_CACHE' => $runtime.'/cache/packages.php',
    ]);
    $process->setTimeout(5)->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toBe($message."\n")
        ->and(visitBrowserStoredFingerprint())->toBe($before)
        ->and(is_file($effectsPath) ? file_get_contents($effectsPath) : null)->toBe($effects);
})->with([
    'seed non-testing environment' => ['visit-registration-fixture.php', 'seed', 'production', '/tmp/fidelitopass-browser-999999', 'testing', 'Visit fixtures require an isolated testing runtime.'],
    'verify foreign runtime' => ['visit-registration-fixture.php', 'verify', 'testing', '/tmp/not-owned', 'testing', 'Visit fixtures require an isolated testing runtime.'],
    'router non-testing environment' => ['visit-registration-router.php', '', 'local', '/tmp/fidelitopass-browser-999999', 'testing', 'Visit fixtures require an isolated testing runtime.'],
    'seed configured development database' => ['visit-registration-fixture.php', 'seed', 'testing', '/tmp/fidelitopass-browser-999999', 'fidelitopass', 'Visit fixtures require configured and actual PostgreSQL testing.'],
]);

it('refuses a mismatched actual database before installing transport or writing fixture files', function (bool $bindTransport) {
    require_once base_path('scripts/quality/browser/visit-registration-fixture.php');
    $environment = getenv('APP_ENV');
    $runtime = getenv('LARAVEL_STORAGE_PATH');
    $original = DB::getFacadeRoot();
    $before = visitBrowserStoredFingerprint();
    $bindings = array_map(fn (string $type) => [app()->bound($type), app()->resolved($type)],
        [GoogleWalletClient::class, GoogleWalletConfiguration::class]);
    putenv('APP_ENV=testing');
    putenv('LARAVEL_STORAGE_PATH=/tmp/fidelitopass-browser-999999');
    DB::shouldReceive('selectOne')->once()->with('select current_database() as name')
        ->andReturn((object) ['name' => 'not_testing']);

    try {
        expect(fn () => $bindTransport ? bindVisitBrowserWallet(app()) : guardVisitBrowser(app()))
            ->toThrow(RuntimeException::class, 'Visit fixtures require configured and actual PostgreSQL testing.');
    } finally {
        DB::swap($original);
        putenv($environment === false ? 'APP_ENV' : 'APP_ENV='.$environment);
        putenv($runtime === false ? 'LARAVEL_STORAGE_PATH' : 'LARAVEL_STORAGE_PATH='.$runtime);
    }
    expect(visitBrowserStoredFingerprint())->toBe($before);
    expect(array_map(fn (string $type) => [app()->bound($type), app()->resolved($type)],
        [GoogleWalletClient::class, GoogleWalletConfiguration::class]))->toBe($bindings);
})->with([false, true]);

it('refuses Laravel isolation mismatches without changing domain facts', function (string $mismatch) {
    require_once base_path('scripts/quality/browser/visit-registration-fixture.php');
    $before = visitBrowserStoredFingerprint();
    $environment = getenv('APP_ENV');
    $runtime = getenv('LARAVEL_STORAGE_PATH');
    $configuration = config('database');
    putenv('APP_ENV=testing');
    putenv('LARAVEL_STORAGE_PATH=/tmp/fidelitopass-browser-999999');
    $application = app();
    if ($mismatch === 'environment') {
        $application = Mockery::mock(Application::class);
        $application->shouldReceive('environment')->once()->with('testing')->andReturnFalse();
    } elseif ($mismatch === 'driver') {
        config(['database.default' => 'sqlite']);
    } else {
        config(['database.connections.pgsql.database' => 'not_testing']);
    }

    try {
        expect(fn () => guardVisitBrowser($application))->toThrow(RuntimeException::class,
            'Visit fixtures require configured and actual PostgreSQL testing.');
    } finally {
        config(['database' => $configuration]);
        putenv($environment === false ? 'APP_ENV' : 'APP_ENV='.$environment);
        putenv($runtime === false ? 'LARAVEL_STORAGE_PATH' : 'LARAVEL_STORAGE_PATH='.$runtime);
    }
    expect(visitBrowserStoredFingerprint())->toBe($before);
})->with(['environment', 'driver', 'database']);

it('rejects the traced null Promotion argument before accepting a Visit', function () {
    expect(app()->environment('testing'))->toBeTrue();
    expect(DB::connection()->getDriverName())->toBe('pgsql');
    expect(DB::selectOne('select current_database() as name')->name)->toBe('testing');
    $before = visitBrowserStoredFingerprint();

    expect(fn () => Livewire::test('visit-registration')->call(
        'acceptVisit', 'manual', '01234', '01936380-0000-7000-8000-000000000001', null,
    ))->toThrow(TypeError::class, '$expectedPromotionId');

    expect(visitBrowserStoredFingerprint())->toBe($before);
});

/**
 * Fingerprints domain rows without reserving, resetting or seeding any database.
 *
 * @return string Digest of existing domain facts.
 */
function visitBrowserStoredFingerprint(): string
{
    $snapshot = [];
    foreach (['users', 'businesses', 'promotions', 'customer_passes', 'promotion_participations', 'visits', 'reward_entitlements'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->all();
    }

    return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
}
