<?php

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Support\DatabaseClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// fixture creation is restricted to the existing isolated browser runtime, never development.
if (getenv('APP_ENV') !== 'testing'
    || preg_match('~^/tmp/fidelitopass-browser-\d+$~', getenv('LARAVEL_STORAGE_PATH') ?: '') !== 1) {
    throw new RuntimeException('Public join fixtures require an isolated testing runtime.');
}

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'testing'
    || DB::selectOne('select current_database() as name')->name !== 'testing') {
    throw new RuntimeException('Public join fixtures require configured and actual PostgreSQL testing.');
}

$operation = $argv[1] ?? '';
if (! in_array($operation, ['seed', 'verify'], true)) {
    throw new InvalidArgumentException('Expected public join fixture seed or verify.');
}

if ($operation === 'seed') {
    if (Business::query()->exists()) {
        throw new RuntimeException('Public join fixtures require an empty isolated testing database.');
    }

    $fixture = DB::transaction(function (): array {
        $today = CarbonImmutable::parse(app(DatabaseClock::class)->captureForBusinessTimezone('America/La_Paz')['instant'])
            ->setTimezone('America/La_Paz')->startOfDay();
        $businesses = [];
        foreach (['active' => '#A77BFF', 'scheduled' => '#000000', 'waiting' => null] as $state => $color) {
            $business = Business::factory()->create([
                'name' => $state === 'active' ? 'Jaqaku' : 'Café del Barrio — Panadería y pastelería artesanal',
                'timezone' => 'Pacific/Auckland',
                'pass_background_color' => $color,
            ]);
            $businesses[$state] = ['publicId' => $business->public_id, 'name' => $business->name];
            if ($state === 'waiting') {
                continue;
            }

            $start = $today->addDays($state === 'active' ? -1 : 3);
            $end = $start->addDays(3);
            $promotion = $business->promotions()->make([
                'target_points' => $state === 'active' ? 6 : 12,
                'reward_title' => $state === 'active' ? 'Capuchino de la casa (200 ml)' : 'Un desayuno artesanal para compartir',
                'reward_description' => $state === 'active'
                    ? 'Una taza de 200 ml preparada con espresso y leche espumada.'
                    : 'Incluye café, pan de la casa y una selección de productos de temporada.',
            ]);
            $promotion->forceFill([
                'status' => PromotionStatus::Published, 'timezone_snapshot' => 'America/La_Paz',
                'starts_at' => $start->utc(), 'ends_at' => $end->utc(),
            ])->save();
            if ($state === 'active') {
                $promotion->extraPoints()->create(['weekday' => 2, 'multiplier' => 3, 'start_time' => '09:00', 'end_time' => '12:00']);
            }
            $businesses[$state] += [
                'startDate' => $start->format('d/m/Y'), 'endDate' => $end->subDay()->format('d/m/Y'),
                'startIso' => $start->format('Y-m-d'), 'endIso' => $end->subDay()->format('Y-m-d'),
            ];
        }

        $owner = Business::query()->where('public_id', $businesses['active']['publicId'])->firstOrFail()->user;
        $owner->forceFill(['email' => 'public-preview-owner@example.test', 'email_verified_at' => now(),
            'password' => Hash::make('LocalPreviewPass84!')])->save();

        return ['kind' => 'public-join', 'businesses' => $businesses,
            'owner' => ['email' => $owner->email, 'password' => 'LocalPreviewPass84!']];
    });
}

// exclude session/cache transport; public reads must preserve every domain table below.
$snapshot = [];
foreach (['users', 'businesses', 'promotions', 'promotion_multiplier_windows', 'customer_passes',
    'promotion_participations', 'visits', 'reward_entitlements'] as $table) {
    $snapshot[$table] = DB::table($table)->orderBy('id')->get()->all();
}
$fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
$snapshotPath = storage_path('public-join-fingerprint.txt');
if ($operation === 'seed') {
    if (file_put_contents($snapshotPath, $fingerprint) === false) {
        throw new RuntimeException('Unable to record public join domain fingerprint.');
    }
    echo json_encode($fixture, JSON_THROW_ON_ERROR).PHP_EOL;
} else {
    $expected = file_get_contents($snapshotPath);
    if ($expected === false || ! hash_equals($expected, $fingerprint)) {
        throw new RuntimeException('Public join browser requests changed persisted domain facts.');
    }
    echo "Public join reads preserved all domain facts.\n";
}
