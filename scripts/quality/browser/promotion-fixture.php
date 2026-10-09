<?php

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$operation = $argv[1] ?? '';
if ($operation !== 'development-fingerprint'
    && (getenv('APP_ENV') !== 'testing'
        || preg_match('~^/tmp/fidelitopass-browser-\d+$~', getenv('LARAVEL_STORAGE_PATH') ?: '') !== 1)) {
    fwrite(STDERR, "Refusing browser fixtures without an isolated testing runtime.\n");
    exit(1);
}

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::selectOne('select current_database() as name')->name;

if ($operation === 'development-fingerprint') {
    if ($database === 'testing' || app()->environment('testing')) {
        throw new RuntimeException('Development fingerprint requires the development connection.');
    }

    $snapshot = [];
    foreach (['users', 'businesses', 'promotions', 'promotion_multiplier_windows'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    echo json_encode(['promotions' => count($snapshot['promotions']), 'rules' => count($snapshot['promotion_multiplier_windows']),
        'sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}

// every mutation and server process requires both configured and actual isolation.
if (! app()->environment('testing') || config('database.connections.pgsql.database') !== 'testing'
    || $database !== 'testing' || config('database.default') !== 'pgsql') {
    throw new RuntimeException('Refusing browser fixture operation outside PostgreSQL testing.');
}

if ($operation === 'guard') {
    echo "Verified APP_ENV=testing; configured and actual PostgreSQL database=testing.\n";
} elseif ($operation === 'reset') {
    if (Artisan::call('migrate:fresh', ['--force' => true]) !== 0) {
        throw new RuntimeException('Testing migrations failed.');
    }
    if (User::count() !== 0 || Promotion::count() !== 0) {
        throw new RuntimeException('Testing reset did not remove browser fixtures.');
    }
    echo "Rebuilt testing only; users=0, promotions=0.\n";
} elseif ($operation === 'serve') {
    if (file_put_contents(storage_path('server.pid'), (string) getmypid()) === false) {
        throw new RuntimeException('Unable to record the owned listener PID.');
    }
    // use Laravel's native router directly so the recorded PID owns the listener.
    chdir(public_path());
    pcntl_exec(PHP_BINARY, ['-S', '0.0.0.0:8017', base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')]);
    throw new RuntimeException('Temporary server could not start.');
} elseif ($operation === 'stop') {
    $recordedPid = file_get_contents(storage_path('server.pid'));
    if ($recordedPid === false || ! ctype_digit($recordedPid) || (int) $recordedPid < 2) {
        throw new RuntimeException('Owned listener PID is unavailable or invalid.');
    }
    $pid = (int) $recordedPid;
    $environment = file_get_contents('/proc/'.$pid.'/environ');
    $command = file_get_contents('/proc/'.$pid.'/cmdline');
    if ($environment === false || $command === false
        || ! in_array('LARAVEL_STORAGE_PATH='.storage_path(), explode("\0", $environment), true)
        || ! in_array('0.0.0.0:8017', explode("\0", $command), true)) {
        throw new RuntimeException('Refusing to stop a process outside this browser runtime.');
    }
    if (! posix_kill($pid, SIGTERM)) {
        throw new RuntimeException('Unable to stop the owned browser listener.');
    }
    echo "Stopped this run's temporary app server.\n";
} elseif ($operation === 'ready') {
    $deadline = microtime(true) + 15;
    do {
        $response = @file_get_contents(config('app.url').'/up');
        if ($response !== false) {
            echo "Temporary testing server is ready.\n";
            exit;
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Temporary testing server did not become ready within 15 seconds.');
} elseif ($operation === 'seed') {
    $fixture = DB::transaction(function (): array {
        $owner = User::factory()->create(['email' => 'promotion-detail@example.test', 'password' => 'ValidPassword84!strong']);
        $business = Business::factory()->for($owner)->create(['name' => 'Browser Promotion Fixture', 'timezone' => 'America/La_Paz', 'pass_background_color' => '#A77BFF']);
        $today = CarbonImmutable::parse(DB::selectOne('select clock_timestamp() as instant')->instant)->setTimezone($business->timezone)->startOfDay();
        $promotions = [];
        $cases = ['Activa' => -1, 'Programada 1' => 3, 'Programada 2' => 5, 'Programada 3' => 7, 'Programada 4' => 9,
            'Finalizada 1' => -12, 'Finalizada 2' => -10, 'Cancelada 1' => 12, 'Cancelada 2' => 12];

        foreach ($cases as $title => $offset) {
            $start = $today->addDays($offset);
            $promotion = $business->promotions()->make(['reward_title' => $title, 'reward_description' => 'Condiciones originales del premio', 'target_points' => 8]);
            $promotion->forceFill(['status' => str_starts_with($title, 'Cancelada') ? PromotionStatus::Cancelled : PromotionStatus::Published,
                'starts_at' => $start->utc(), 'ends_at' => $start->addDays(2)->utc(), 'timezone_snapshot' => 'America/La_Paz',
                'cancelled_at' => str_starts_with($title, 'Cancelada') ? $today->utc() : null])->save();
            foreach ([1 => 2, 3 => 3, 5 => 5] as $weekday => $multiplier) {
                $promotion->extraPoints()->create(['weekday' => $weekday, 'multiplier' => $multiplier]);
            }
            $promotions[$title] = $promotion->public_id;
        }
        for ($index = 1; $index <= 4; $index++) {
            $business->promotions()->create(['reward_title' => 'Borrador '.$index, 'target_points' => 8,
                'local_start_date' => $today->addDays(20 + $index), 'local_end_date' => $today->addDays(22 + $index)]);
        }
        // a different current Business timezone must not reinterpret the published dates.
        $business->update(['timezone' => 'Pacific/Auckland']);

        return ['email' => $owner->email, 'promotions' => $promotions,
            'startDate' => $today->subDay()->format('d/m/Y'), 'endDate' => $today->format('d/m/Y')];
    });
    $snapshot = Promotion::with('extraPoints')->whereNotNull('starts_at')->orderBy('id')->get()
        ->map(fn (Promotion $promotion): array => $promotion->only(['public_id', 'reward_title', 'reward_description', 'target_points', 'starts_at', 'ends_at', 'timezone_snapshot']) + ['rules' => $promotion->extraPoints->toArray()])->toArray();
    if (file_put_contents(storage_path('fixture.json'), json_encode($snapshot, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Unable to record original fixture terms.');
    }
    echo json_encode($fixture, JSON_THROW_ON_ERROR).PHP_EOL;
} elseif ($operation === 'verify') {
    $recordedSnapshot = file_get_contents(storage_path('fixture.json'));
    if ($recordedSnapshot === false) {
        throw new RuntimeException('Original fixture snapshot is unavailable.');
    }
    $snapshot = json_decode($recordedSnapshot, true, flags: JSON_THROW_ON_ERROR);
    foreach ($snapshot as $terms) {
        $promotion = Promotion::with('extraPoints')->where('public_id', $terms['public_id'])->firstOrFail();
        $actual = $promotion->only(['public_id', 'reward_title', 'reward_description', 'target_points', 'starts_at', 'ends_at', 'timezone_snapshot']) + ['rules' => $promotion->extraPoints->toArray()];
        if (json_encode($actual, JSON_THROW_ON_ERROR) !== json_encode($terms, JSON_THROW_ON_ERROR)) {
            throw new RuntimeException('Published snapshot changed during the browser journey.');
        }
    }
    foreach (['Activa', 'Programada 1'] as $title) {
        if (Promotion::where('reward_title', $title)->firstOrFail()->status !== PromotionStatus::Cancelled) {
            throw new RuntimeException('Expected browser cancellation was not persisted.');
        }
    }
    echo "Persisted cancellation and original terms/rules verified.\n";
} else {
    throw new InvalidArgumentException('Unknown browser fixture operation.');
}
