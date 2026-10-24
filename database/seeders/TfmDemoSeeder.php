<?php

namespace Database\Seeders;

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Models\User;
use App\Support\DatabaseClock;
use Carbon\CarbonImmutable;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/** Creates a small fictional café history and an untouched reviewer Business without external effects. */
class TfmDemoSeeder extends Seeder
{
    /**
     * Creates the complete demo atomically only when no existing owner or Business can be overwritten.
     *
     * @param  DatabaseClock  $clock  PostgreSQL clock captured once after both new Businesses exist.
     *
     * @throws RuntimeException When an empty target lacks the private review password.
     */
    public function run(DatabaseClock $clock): void
    {
        DB::transaction(function () use ($clock): void {
            if (User::query()->exists() || Business::query()->exists()) {
                $this->command->info('TFM review seed skipped: existing User or Business.');

                return;
            }

            $reviewPassword = config('tfm.review_password');
            if (! is_string($reviewPassword) || $reviewPassword === '') {
                throw new RuntimeException('Provide TFM_REVIEW_PASSWORD privately before loading review data.');
            }

            fake()->seed(2026);
            $password = Hash::make($reviewPassword);
            $owner = User::factory()->createOne([
                'name' => 'MoureDev',
                'email' => 'mouredev@fidelito.com',
                'password' => $password,
                'email_verified_at' => now(),
            ]);
            $zeroOwner = User::factory()->createOne([
                'name' => 'MoureDev Zero',
                'email' => 'mouredev-zero@fidelito.com',
                'password' => $password,
                'email_verified_at' => now(),
            ]);
            $business = Business::factory()->for($owner)->createOne([
                'name' => 'MoureDev Café',
                'timezone' => 'Europe/Madrid',
                'pass_background_color' => '#6B4226',
            ]);
            Business::factory()->for($zeroOwner)->createOne([
                'name' => 'MoureDev Café Zero',
                'timezone' => 'Europe/Madrid',
            ]);

            $time = $clock->capture($business->timezone);
            $today = CarbonImmutable::parse($time->localDate, $business->timezone)->startOfDay();
            $promotions = $this->seedPromotions($business, $today);
            $this->seedHistory($business, $promotions, $today);
        });
    }

    /**
     * Creates eleven distinct café offers with disjoint effective published windows and weekly bonuses.
     *
     * @param  Business  $business  Newly created main Business.
     * @param  CarbonImmutable  $today  Midnight of the one captured Madrid-local date.
     * @return array<string, Promotion> Promotions keyed by their in-memory fixture roles.
     */
    private function seedPromotions(Business $business, CarbonImmutable $today): array
    {
        // offsets describe local half-open intervals; cancellation retains the original terms
        $periods = [
            'past_1' => [PromotionStatus::Published, -126, -98, null],
            'past_2' => [PromotionStatus::Published, -91, -63, null],
            'past_3' => [PromotionStatus::Published, -56, -28, null],
            'cancelled_past' => [PromotionStatus::Cancelled, -27, -15, -20],
            'active' => [PromotionStatus::Published, -14, 15, null],
            'scheduled_1' => [PromotionStatus::Published, 15, 29, null],
            'scheduled_2' => [PromotionStatus::Published, 29, 43, null],
            'draft_1' => [PromotionStatus::Draft, 3, 17, null],
            'draft_2' => [PromotionStatus::Draft, 10, 25, null],
            'draft_3' => [PromotionStatus::Draft, 30, 45, null],
            'cancelled_future' => [PromotionStatus::Cancelled, 15, 29, -2],
        ];
        $rewards = fake()->shuffleArray(PromotionFactory::REWARDS);
        $targets = fake()->shuffleArray(PromotionFactory::TARGETS);
        $promotions = [];
        $index = 0;
        foreach ($periods as $role => [$status, $startOffset, $endOffset, $cancelOffset]) {
            $start = $today->addDays($startOffset);
            $end = $today->addDays($endOffset);
            if ($status === PromotionStatus::Draft) {
                $dates = [
                    'local_start_date' => $start->toDateString(),
                    'local_end_date' => $end->subDay()->toDateString(),
                    'timezone_snapshot' => null,
                    'starts_at' => null,
                    'ends_at' => null,
                ];
            } else {
                $dates = [
                    'local_start_date' => null,
                    'local_end_date' => null,
                    'timezone_snapshot' => $business->timezone,
                    'starts_at' => $start->utc(),
                    'ends_at' => $end->utc(),
                ];
            }
            $promotion = PromotionFactory::new()->for($business)->createOne([
                ...$dates,
                'target_points' => $targets[$index % count($targets)],
                'reward_title' => $rewards[$index][0],
                'reward_description' => $rewards[$index][1],
                'status' => $status,
                'cancelled_at' => $cancelOffset === null ? null : $today->addDays($cancelOffset)->setTime(12, 0)->utc(),
            ]);

            $weekdays = fake()->shuffleArray(range(1, 7));
            $ruleCount = $role === 'past_1' ? 3 : fake()->numberBetween(1, 3);
            $windows = [];
            foreach (array_slice($weekdays, 0, $ruleCount) as $windowIndex => $weekday) {
                $timed = ($index + $windowIndex) % 2 === 1;
                [$from, $until] = fake()->randomElement([
                    ['08:00', '11:00'],
                    ['12:00', '15:00'],
                    ['16:00', '19:00'],
                ]);
                $windows[] = [
                    'weekday' => $weekday,
                    'multiplier' => [2, 3, 5][($index + $windowIndex) % 3],
                    'start_time' => $timed ? $from : null,
                    'end_time' => $timed ? $until : null,
                ];
            }
            $promotion->extraPoints()->createMany($windows);
            $promotions[$role] = $promotion->load('extraPoints');
            $index++;
        }

        return $promotions;
    }

    /**
     * Creates four unprovisioned local identities and bounded examples of progress, completion and cancellation.
     *
     * @param  Business  $business  Main Business that owns every historical fact.
     * @param  array<string, Promotion>  $promotions  Seeded Promotions with their frozen weekly rules loaded.
     * @param  CarbonImmutable  $today  Captured Business-local midnight; every historical Visit precedes this date.
     */
    private function seedHistory(Business $business, array $promotions, CarbonImmutable $today): void
    {
        $passes = CustomerPass::factory()->for($business)->count(4)->create()->all();
        $past = $promotions['past_1'];
        $rules = $past->extraPoints->sortBy('multiplier')->values()->all();
        $ordinaryWeekday = array_values(array_diff(range(1, 7), $past->extraPoints->pluck('weekday')->all()))[0];
        $plans = [
            [$past, $passes[0], $rules[0]->weekday, $rules[0]->start_time ?? '10:00', null, true],
            [$past, $passes[1], $rules[1]->weekday, $rules[1]->start_time ?? '10:00', null, false],
            [$past, $passes[2], $rules[2]->weekday, $rules[2]->start_time ?? '10:00', 1, false],
            [$past, $passes[3], $ordinaryWeekday, '10:00', 1, false],
            [$promotions['past_2'], $passes[0], null, '10:00', null, true],
            [$promotions['cancelled_past'], $passes[1], null, '10:00', 1, false],
            [$promotions['active'], $passes[2], null, '10:00', 1, false],
            [$promotions['active'], $passes[3], null, '10:00', null, false],
        ];

        foreach ($plans as [$promotion, $pass, $weekday, $timeOfDay, $limit, $redeem]) {
            /** @var CarbonImmutable $startsAt */
            $startsAt = $promotion->starts_at;
            $firstVisit = $startsAt->setTimezone($business->timezone);
            if ($weekday !== null) {
                $firstVisit = $firstVisit->addDays(($weekday - (int) $firstVisit->format('N') + 7) % 7);
            }
            $firstVisit = $firstVisit->setTimeFromTimeString($timeOfDay);
            $this->seedVisits($business, $pass, $promotion, $firstVisit, $today, $limit, $redeem);
        }
    }

    /**
     * Inserts illustrative daily Visit facts, exact first-threshold unlocks and optional later redemptions.
     *
     * @param  Business  $business  Owning Business whose owner confirms these fictional historical facts.
     * @param  CustomerPass  $pass  Unprovisioned identity belonging to that Business.
     * @param  Promotion  $promotion  Published or cancelled Promotion with complete frozen terms and loaded rules.
     * @param  CarbonImmutable  $firstVisit  First planned café-hour Visit in the published Business timezone.
     * @param  CarbonImmutable  $today  Captured Business-local midnight that excludes today's and future Visits.
     * @param  int|null  $limit  Optional illustrative Visit count for partial-progress examples.
     * @param  bool  $redeem  Whether a completed example includes redemption twenty minutes after unlock.
     */
    private function seedVisits(
        Business $business,
        CustomerPass $pass,
        Promotion $promotion,
        CarbonImmutable $firstVisit,
        CarbonImmutable $today,
        ?int $limit,
        bool $redeem,
    ): void {
        $cutoff = $today->min($promotion->ends_at);
        if ($promotion->cancelled_at !== null) {
            $cutoff = $cutoff->min($promotion->cancelled_at);
        }
        DB::table('promotion_participations')->insert([
            'business_id' => $business->id,
            'customer_pass_id' => $pass->id,
            'promotion_id' => $promotion->id,
            'created_at' => $firstVisit->utc()->format('Y-m-d H:i:s.uP'),
            'updated_at' => $firstVisit->utc()->format('Y-m-d H:i:s.uP'),
        ]);

        $progress = 0;
        $visitCount = 0;
        $visitAt = $firstVisit;
        while (
            $visitAt->lessThan($cutoff)
            && $progress < $promotion->target_points
            && ($limit === null || $visitCount < $limit)
        ) {
            $points = 1;
            $localTime = $visitAt->format('H:i:s');
            foreach ($promotion->extraPoints as $window) {
                if ($window->weekday !== (int) $visitAt->format('N')) {
                    continue;
                }
                if (
                    $window->start_time !== null
                    && ($localTime < $window->start_time || $localTime >= $window->end_time)
                ) {
                    continue;
                }
                $points = $window->multiplier;
                break;
            }

            $confirmedAt = $visitAt->utc()->format('Y-m-d H:i:s.uP');
            DB::table('visits')->insert([
                'business_id' => $business->id,
                'customer_pass_id' => $pass->id,
                'promotion_id' => $promotion->id,
                'confirmed_by_user_id' => $business->user_id,
                'operation_id' => (string) Str::uuid7(),
                'awarded_points' => $points,
                'confirmed_at' => $confirmedAt,
                'created_at' => $confirmedAt,
                'updated_at' => $confirmedAt,
            ]);
            $progress += $points;
            $visitCount++;
            if ($progress >= $promotion->target_points) {
                DB::table('reward_entitlements')->insert([
                    'business_id' => $business->id,
                    'customer_pass_id' => $pass->id,
                    'promotion_id' => $promotion->id,
                    'unlocked_at' => $confirmedAt,
                    'redeemed_at' => $redeem ? $visitAt->addMinutes(20)->utc()->format('Y-m-d H:i:s.uP') : null,
                    'redeemed_by_user_id' => $redeem ? $business->user_id : null,
                    'created_at' => $confirmedAt,
                    'updated_at' => $confirmedAt,
                ]);
            }
            $visitAt = $visitAt->addDay();
        }
    }
}
