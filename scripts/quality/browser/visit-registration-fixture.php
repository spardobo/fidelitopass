<?php

use App\Integrations\GoogleWallet\GoogleWalletClient;
use App\Integrations\GoogleWallet\GoogleWalletConfiguration;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Models\RewardEntitlement;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GoogleWalletHttpFake;

/**
 * Guards runtime, Laravel configuration and actual PostgreSQL before any fixture effect.
 *
 * @param  Application|null  $application  Already booted application for focused guard tests.
 * @return Application Verified isolated application.
 *
 * @throws RuntimeException When any isolation fact disagrees.
 */
function guardVisitBrowser(?Application $application = null): Application
{
    if (
        getenv('APP_ENV') !== 'testing'
        || preg_match('~^/tmp/fidelitopass-browser-\d+$~', getenv('LARAVEL_STORAGE_PATH') ?: '') !== 1
    ) {
        throw new RuntimeException('Visit fixtures require an isolated testing runtime.');
    }

    if (getenv('DB_DATABASE') !== 'testing') {
        throw new RuntimeException('Visit fixtures require configured and actual PostgreSQL testing.');
    }

    if ($application === null) {
        require_once dirname(__DIR__, 3).'/vendor/autoload.php';
        $application = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $application->make(Kernel::class)->bootstrap();
    }

    if (
        ! $application->environment('testing')
        || config('database.default') !== 'pgsql'
        || config('database.connections.pgsql.database') !== 'testing'
        || DB::selectOne('select current_database() as name')->name !== 'testing'
    ) {
        throw new RuntimeException('Visit fixtures require configured and actual PostgreSQL testing.');
    }

    return $application;
}

/**
 * Installs the same native SDK transport boundary as the Visit command tests after guarding.
 *
 * @param  Application  $application  Verified testing application.
 */
function bindVisitBrowserWallet(Application $application): void
{
    guardVisitBrowser($application);
    $http = new GoogleWalletHttpFake;
    $http->respondWith(function (Request $request) {
        $effects = json_decode(file_get_contents(storage_path('visit-effects.json')), true, flags: JSON_THROW_ON_ERROR);
        if ($request->url() === 'https://oauth2.googleapis.com/token' && $request->method() === 'POST') {
            $effects['oauth']++;
            file_put_contents(storage_path('visit-effects.json'), json_encode($effects, JSON_THROW_ON_ERROR), LOCK_EX);

            return GoogleWalletHttpFake::json(['access_token' => 'synthetic', 'expires_in' => 3600]);
        }

        $prefix = 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/';
        if (
            ! str_starts_with($request->url(), $prefix)
            || ! in_array($request->method(), ['GET', 'PATCH'], true)
            || $request->header('Authorization') !== ['Bearer synthetic']
        ) {
            throw new LogicException('Unexpected Visit browser SDK request.');
        }
        $pass = CustomerPass::query()->where('wallet_object_id', substr($request->url(), strlen($prefix)))->sole();
        $fixture = json_decode(file_get_contents(storage_path('visits.json')), true, flags: JSON_THROW_ON_ERROR);
        $case = collect($fixture['passes'])->firstWhere('id', $pass->id);
        if ($case === null) {
            throw new LogicException('Unexpected Visit browser pass.');
        }
        $effects['passes'][$pass->id][$request->method()]++;
        file_put_contents(storage_path('visit-effects.json'), json_encode($effects, JSON_THROW_ON_ERROR), LOCK_EX);
        if ($request->method() === 'PATCH' && $pass->manual_code === $fixture['passes']['failure']['code']) {
            return GoogleWalletHttpFake::json(['error' => ['code' => 400, 'message' => 'Synthetic rejection']], 400);
        }

        $modules = [];
        if ($request->method() === 'PATCH') {
            if (
                array_keys($request->data()) !== ['textModulesData']
                || DB::transactionLevel() !== 1
                || ! Visit::query()->where('customer_pass_id', $pass->id)->exists()
            ) {
                throw new LogicException('Expected tentative Visit and absolute native Wallet replacement.');
            }
            $modules = $request->data()['textModulesData'];
            if ($pass->manual_code === $fixture['passes']['compound']['code']) {
                $reward = RewardEntitlement::query()->where('customer_pass_id', $pass->id)->sole();
                if ($reward->redeemed_at === null || $reward->redeemed_by_user_id !== $pass->business->user_id
                    || collect($modules)->keyBy('id')['card_primary']['header'] !== '✅ RECOMPENSA CANJEADA') {
                    throw new LogicException('Expected one compound final Wallet projection.');
                }
            }
        }

        return GoogleWalletHttpFake::json([
            'id' => $pass->wallet_object_id,
            'classId' => '123456.business_'.$pass->business->public_id,
            'accountId' => $pass->manual_code,
            'barcode' => ['type' => 'QR_CODE', 'value' => $case['token']],
            'textModulesData' => $modules,
        ]);
    });
    $application->instance(GoogleWalletClient::class, new GoogleWalletClient($http->client));
    $application->instance(GoogleWalletConfiguration::class, new class extends GoogleWalletConfiguration
    {
        /**
         * Supplies synthetic in-memory signing material without reading deployment credentials.
         *
         * @param  bool  $synchronizing  Native synchronization flag.
         * @return array<string, mixed> Operation-local synthetic SDK settings.
         */
        public function load(bool $synchronizing = false): array
        {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);

            return [
                'issuer_id' => '123456',
                'account' => [
                    'type' => 'service_account',
                    'client_id' => 'synthetic-client',
                    'client_email' => 'wallet@platform.iam.gserviceaccount.com',
                    'private_key' => $pem,
                ],
                'origin' => 'https://passes.example.test',
                'logos' => ['black' => 'https://passes.example.test/logo.png'],
                'connect_timeout' => 3,
                'timeout' => 10,
            ];
        }
    });
}

/**
 * Reads nonsecret committed facts and safe provider counters for one allowlisted journey.
 *
 * @param  string  $case  Manifest case name, never a path or SQL identifier.
 * @return array<string, mixed> Persisted Visit facts and native transport counts.
 */
function readVisitBrowserFacts(string $case): array
{
    $fixture = json_decode(file_get_contents(storage_path('visits.json')), true, flags: JSON_THROW_ON_ERROR);
    if (! isset($fixture['passes'][$case])) {
        throw new InvalidArgumentException('Unknown Visit browser case.');
    }
    $id = $fixture['passes'][$case]['id'];
    $effects = json_decode(file_get_contents(storage_path('visit-effects.json')), true, flags: JSON_THROW_ON_ERROR);

    return [
        'visits' => Visit::query()->where('customer_pass_id', $id)->orderBy('id')
            ->get(['operation_id', 'awarded_points'])->toArray(),
        'effects' => $effects['passes'][$id],
    ];
}

// importing the concrete fixture exposes only guarded helpers, never its CLI operations
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        guardVisitBrowser();
        $operation = $argv[1] ?? '';
        if ($operation === 'seed') {
            if (User::query()->exists() || Business::query()->exists()) {
                throw new RuntimeException('Visit fixtures require an empty testing database.');
            }
            $fixture = DB::transaction(function (): array {
                $owner = User::factory()->create([
                    'email' => 'visits@example.test',
                    'password' => 'VisitBrowser84!strong',
                ]);
                $business = Business::factory()->for($owner)->create(['timezone' => 'America/La_Paz']);
                $instant = CarbonImmutable::parse(DB::selectOne('select clock_timestamp() as instant')->instant);
                $promotion = $business->promotions()->make([
                    'reward_title' => 'Café de la casa',
                    'reward_description' => 'Una taza de café.',
                    'target_points' => 8,
                ]);
                $promotion->forceFill([
                    'status' => 'published',
                    'timezone_snapshot' => $business->timezone,
                    'starts_at' => $instant->subDay(),
                    'ends_at' => $instant->addDays(3),
                ])->save();
                $passes = [];
                $effects = ['oauth' => 0, 'passes' => []];
                $cases = [
                    'manual', 'failure', 'lost', 'details', 'reward',
                    'readonly-edit', 'readonly-close', 'readonly-back',
                    'layout-1280', 'layout-375',
                    'camera-chromium', 'camera-firefox', 'camera-webkit', 'unlock', 'compound',
                ];
                foreach ($cases as $index => $case) {
                    $token = $case === 'camera-chromium'
                        ? '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'
                        : hash('sha256', 'visit-browser-'.$case);
                    $pass = $business->customerPasses()->create([
                        'manual_code' => str_pad((string) (1234 + $index), 5, '0', STR_PAD_LEFT),
                        'validation_token_hash' => hash('sha256', $token),
                    ]);
                    $pass->update(['wallet_object_id' => '123456.pass_'.$pass->public_id]);
                    $passes[$case] = ['id' => $pass->id, 'code' => $pass->manual_code, 'token' => $token];
                    $effects['passes'][$pass->id] = ['GET' => 0, 'PATCH' => 0];
                }
                $rewardPass = $passes['reward']['id'];
                foreach (['reward' => 8, 'unlock' => 7, 'compound' => 7] as $case => $count) {
                    DB::table('promotion_participations')->insert([
                        'business_id' => $business->id,
                        'customer_pass_id' => $passes[$case]['id'],
                        'promotion_id' => $promotion->id,
                    ]);
                    for ($index = 0; $index < $count; $index++) {
                        DB::table('visits')->insert([
                            'business_id' => $business->id,
                            'customer_pass_id' => $passes[$case]['id'],
                            'promotion_id' => $promotion->id,
                            'operation_id' => (string) Str::uuid(),
                            'awarded_points' => 1,
                            'confirmed_by_user_id' => $owner->id,
                            'confirmed_at' => $instant,
                        ]);
                    }
                }
                DB::table('reward_entitlements')->insert([
                    'business_id' => $business->id,
                    'customer_pass_id' => $rewardPass,
                    'promotion_id' => $promotion->id,
                    'unlocked_at' => $instant,
                ]);
                file_put_contents(storage_path('visit-effects.json'), json_encode($effects, JSON_THROW_ON_ERROR), LOCK_EX);

                return [
                    'kind' => 'visits',
                    'owner' => ['email' => $owner->email, 'password' => 'VisitBrowser84!strong'],
                    'passes' => $passes,
                    'promotion' => $promotion->fresh()->getAttributes(),
                ];
            });
            file_put_contents(storage_path('visits.json'), json_encode($fixture, JSON_THROW_ON_ERROR), LOCK_EX);
            echo json_encode($fixture, JSON_THROW_ON_ERROR).PHP_EOL;
        } elseif ($operation === 'verify') {
            $fixture = json_decode(file_get_contents(storage_path('visits.json')), true, flags: JSON_THROW_ON_ERROR);
            $expectedOauth = 0;
            foreach ($fixture['passes'] as $case => $pass) {
                $facts = readVisitBrowserFacts($case);
                $accepted = in_array($case, ['manual', 'lost', 'details', 'camera-chromium', 'camera-firefox', 'camera-webkit', 'unlock'], true);
                $expectedVisits = in_array($case, ['reward', 'unlock'], true) ? 8 : (int) $accepted;
                $expectedWrites = match ($case) {
                    'reward' => 1,
                    'unlock' => 2,
                    default => (int) ($accepted || $case === 'failure'),
                };
                if (in_array($case, ['reward', 'unlock'], true)) {
                    $reward = DB::table('reward_entitlements')->where('customer_pass_id', $pass['id'])->sole();
                    if ($reward->redeemed_at === null || $reward->redeemed_by_user_id === null) {
                        throw new RuntimeException('Expected one final Reward redemption for '.$case.'.');
                    }
                }
                $expectedOauth += $expectedWrites;
                if (
                    count($facts['visits']) !== $expectedVisits
                    || $facts['effects'] !== ['GET' => $expectedWrites, 'PATCH' => $expectedWrites]
                ) {
                    throw new RuntimeException('Unexpected persisted Visit/provider counts for '.$case.'.');
                }
            }
            $effects = json_decode(file_get_contents(storage_path('visit-effects.json')), true, flags: JSON_THROW_ON_ERROR);
            if ($effects['oauth'] !== $expectedOauth) {
                throw new RuntimeException('Unexpected native OAuth request count.');
            }
            $promotion = Promotion::query()->findOrFail($fixture['promotion']['id']);
            if (
                $promotion->getAttributes() !== $fixture['promotion']
                || DB::table('reward_entitlements')->count() !== 2
                || ! DB::table('reward_entitlements')->where('customer_pass_id', $fixture['passes']['unlock']['id'])->where('promotion_id', $promotion->id)->exists()
            ) {
                throw new RuntimeException('Visit journeys changed frozen terms or Reward facts.');
            }
            echo "Committed Visits, once-only native provider effects and frozen terms verified.\n";
        } else {
            throw new InvalidArgumentException('Expected Visit fixture seed or verify.');
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");
        exit(1);
    }
}
