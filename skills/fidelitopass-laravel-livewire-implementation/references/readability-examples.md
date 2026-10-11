# Readable PHP calibration

Read the relevant sections before the first code change in a task. Apply the same structure to new code and refactoring; do not copy every example or force every section into every file. Current callers, schema, installed APIs and domain documents still own behavior.

## Contents

- [Small cohesive calculations](#small-cohesive-calculations)
- [Complete state responsibility](#conditions-and-complete-state-responsibility)
- [Presentation builders](#cohesive-presentation-builders)
- [Transaction and integration preservation](#transaction-and-integration-preservation)
- [Pest tests](#readable-pest-tests)
- [Final calibration](#final-calibration)

## Small cohesive calculations

This pure example consumes a bounded batch of already-authorized fixture facts for one Promotion; production database aggregates remain the preferred way to obtain totals. The batch illustrates composition, not a recommendation to load passes or Visits or loop over database calls. It does not accept Visits, check validity, persist progress or unlock Rewards.

```php
<?php

/**
 * Summarizes each pass independently from bounded fixture facts.
 *
 * @param array<string, list<positive-int>> $awardedPointsByPass Accepted values keyed by pass reference.
 * @param positive-int $targetPoints The shared published target, validated by the caller.
 * @return array<string, array{earned: int, remaining: int, completed: bool}> Progress keyed by pass reference.
 */
function summarizePromotionProgressByPass(array $awardedPointsByPass, int $targetPoints): array
{
    $progressByPass = [];

    foreach ($awardedPointsByPass as $passReference => $awardedPoints) {
        $progressByPass[$passReference] = summarizePromotionProgress($awardedPoints, $targetPoints);
    }

    return $progressByPass;
}

/**
 * Summarizes bounded awarded-point facts without changing them.
 *
 * Values and their total must fit the native integer range.
 *
 * @param list<positive-int> $awardedPoints Accepted values for one pass and Promotion.
 * @param positive-int $targetPoints The published target, validated by the caller.
 * @return array{earned: int, remaining: int, completed: bool} Earned points and target comparison for one pass.
 */
function summarizePromotionProgress(array $awardedPoints, int $targetPoints): array
{
    $earnedPoints = array_sum($awardedPoints);
    $remainingPoints = max(0, $targetPoints - $earnedPoints);

    return [
        'earned' => $earnedPoints,
        'remaining' => $remainingPoints,
        'completed' => $earnedPoints >= $targetPoints,
    ];
}
```

Read the batch entry first: initialize its result, summarize each pass, then return the keyed result. The extracted function owns the complete one-pass calculation; the caller does not manage its intermediate totals. Inside it, a paragraph separates calculation from the returned meaning. The shapes add information native array signatures cannot express. Neither responsibility needs further helper fragmentation or a decorative box.

Annotations express caller preconditions, not runtime validation. Each pass's values and total must fit the native integer range. This example does not choose a production integer bound or replace domain authorization.

For a transactional command, keep authorization, serialized reads, replay, new eligibility and writes visible in one owned boundary. Add short rationale only where order or an invariant is not self-evident. Never copy the inventory-domain schema or application-host clock from an unrelated generic example into this project.

## Conditions and complete state responsibility

This pure fixture example classifies already-known issuance outcomes. It does not infer provider or database outcomes from exceptions. The caller supplies coherent facts; the local outcome matters only after confirmed provider creation. The returned values are illustrative internal labels, not UI copy or persistent statuses.

```php
/**
 * Classifies an issuance attempt without performing or retrying it.
 *
 * @param 'confirmed'|'failed'|'unknown' $providerOutcome Known Google creation outcome; failed means definitive failure.
 * @param 'committed'|'rolled_back'|'unknown' $localOutcome Known local outcome after confirmed Google creation.
 * @return 'provider_failed'|'provider_unknown'|'save_ready'|'provider_orphan'|'commit_unknown' Internal outcome classification.
 */
function classifyIssuanceOutcome(string $providerOutcome, string $localOutcome): string
{
    if ($providerOutcome === 'failed') {
        return 'provider_failed';
    }

    if ($providerOutcome === 'unknown') {
        return 'provider_unknown';
    }

    return match ($localOutcome) {
        'committed' => 'save_ready',
        'rolled_back' => 'provider_orphan',
        'unknown' => 'commit_unknown',
    };
}
```

Read the provider guards first, then the local result. This function owns the complete classification; its caller does not reconstruct it from separate flags. Unknown is distinct from failure or rollback. The literal types describe preconditions, not runtime input validation. No additional helper makes this small decision easier to read.

## Cohesive presentation builders

Keep clock capture and Promotion selection visible in the public method. Extract complete state builders that own their output, rather than a helper for each text module. This class uses existing project models and collaborators; it is not a standalone application or a request to add another presentation subsystem. The constructor stays unboxed at the top. Two optional separators distinguish the public resolution method from the private state builders, each with a blank line above and below. If this distinction does not help a particular class, omit both separators; never group the constructor merely to create a second block.

```php
<?php

namespace App\Support;

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use Carbon\CarbonImmutable;

/**
 * Resolves real initial content for a new pass without prior Promotion participation.
 */
class InitialWalletPresentation
{
    /**
     * Uses the authoritative database clock without writing loyalty facts.
     *
     * @param DatabaseClock $clock Shared source of the operation instant.
     */
    public function __construct(private DatabaseClock $clock) {}

    // -----------------------------------------------------------------------------
    // presentation resolution
    // -----------------------------------------------------------------------------

    /**
     * Selects active published Promotion terms or waiting content using one database instant.
     *
     * @param Business $business Persisted owner whose current Promotion is presented.
     * @return array{
     *     state: 'waiting'|'active',
     *     progress: ?string,
     *     text_modules: list<array{id: string, header: string, body: string}>
     * } Spanish initial content, without credentials or provider fields.
     */
    public function resolve(Business $business): array
    {
        $reading = $this->clock->captureForBusinessTimezone($business->timezone);

        $promotion = $business->promotions()
            ->with('extraPoints')
            ->where('status', PromotionStatus::Published)
            ->whereNull('cancelled_at')
            ->where('starts_at', '<=', $reading['instant'])
            ->where('ends_at', '>', $reading['instant'])
            ->first();

        if ($promotion === null) {
            return $this->waitingPresentation();
        }

        $localInstant = CarbonImmutable::parse($reading['instant'])
            ->setTimezone($promotion->timezone_snapshot);

        return $this->activePresentation($promotion, $localInstant);
    }

    // -----------------------------------------------------------------------------
    // initial presentation states
    // -----------------------------------------------------------------------------

    /**
     * Builds waiting content without progress or Promotion terms.
     *
     * @return array{
     *     state: 'waiting',
     *     progress: null,
     *     text_modules: list<array{id: string, header: string, body: string}>
     * } Spanish waiting content for a new pass.
     */
    private function waitingPresentation(): array
    {
        return [
            'state' => 'waiting',
            'progress' => null,
            'text_modules' => [
                [
                    'id' => 'promotion',
                    'header' => __('👀 PRÓXIMA PROMOCIÓN'),
                    'body' => __('business.pass.waiting_state_heading')
                        .' '.__('business.pass.waiting_state_description'),
                ],
            ],
        ];
    }

    /**
     * Builds zero-progress content from the active Promotion and its local operation instant.
     *
     * Uses the first matching multiplier window and the inclusive local deadline.
     * Does not create participation, visits or reward entitlements.
     *
     * @param Promotion $promotion Published Promotion with extraPoints already loaded.
     * @param CarbonImmutable $localInstant Operation instant in the frozen Promotion timezone.
     * @return array{
     *     state: 'active',
     *     progress: string,
     *     text_modules: list<array{id: string, header: string, body: string}>
     * } Spanish initial content reflecting the active Promotion terms.
     */
    private function activePresentation(
        Promotion $promotion,
        CarbonImmutable $localInstant,
    ): array {
        $localTime = $localInstant->format('H:i:s.u');

        $window = $promotion->extraPoints->first(
            fn ($window) => $window->weekday === $localInstant->isoWeekday()
                && (
                    $window->start_time === null
                    || (
                        $localTime >= $window->start_time
                        && $localTime < $window->end_time
                    )
                ),
        );

        $points = $window->multiplier ?? 1;

        $deadline = CarbonImmutable::parse($promotion->ends_at)
            ->setTimezone($promotion->timezone_snapshot)
            ->subDay();

        $deadline->locale('es');

        $endDate = $deadline->translatedFormat('d M Y');
        $endDate = mb_strtoupper(str_replace('.', '', $endDate));

        $target = $promotion->target_points;

        return [
            'state' => 'active',
            'progress' => __(':progress / :target puntos', [
                'progress' => 0,
                'target' => $target,
            ]),
            'text_modules' => [
                [
                    'id' => 'promotion',
                    'header' => __('🎯 PROMOCIÓN ACTUAL'),
                    'body' => __('Consigue :target puntos hasta el :date.', [
                        'target' => $target,
                        'date' => $endDate,
                    ]),
                ],
                [
                    'id' => 'next_action',
                    'header' => __('Tu progreso'),
                    'body' => __('Te faltan :points puntos.', [
                        'points' => $target,
                    ]),
                ],
                [
                    'id' => 'visit',
                    'header' => __('Valor de tu visita'),
                    'body' => $points > 1
                        ? __('⚡ Ahora tu visita vale :points puntos.', [
                            'points' => $points,
                        ])
                        : __('Tu visita ahora vale 1 punto.'),
                ],
                [
                    'id' => 'reward',
                    'header' => __('Tu recompensa'),
                    'body' => __('🎁 :reward', [
                        'reward' => $promotion->reward_title,
                    ]),
                ],
                [
                    'id' => 'deadline',
                    'header' => __('Fecha límite'),
                    'body' => __('Válido hasta el :date', [
                        'date' => $endDate,
                    ]),
                ],
            ],
        ];
    }
}
```

Keep the supplied date conversion and `locale()` call intact during refactoring. Do not reinterpret this example as permission to change clocks, select a different Promotion, sort windows, alter copy or reset existing progress. Add relevant `@throws` when actual collaborator contracts are available rather than inventing them.

## Transaction and integration preservation

Keep preconditions, transaction ownership, serialized lookup, exact replay, conflicts, writes and post-commit logging visible. A persistence helper can own a complete local decision while its caller owns the transaction and exception translation. It must remain inside the caller's transaction and must not call the provider.

- Preserve a specific uniqueness catch before the generic database catch. Never attach secret-bearing SQL/bindings as an exception cause merely to satisfy generic exception-chaining guidance.
- Preserve the stable Business lock, query order, tenant scope, association and model events. Do not replace `save()` with a direct insert or move logging before commit.
- Keep provider calls outside retryable local transactions. The [initial issuance boundary](../../../docs/architecture/overview.md#initial-issuance-boundary) and [ADR-006](../../../docs/architecture/decisions/006-google-first-initial-wallet-issuance.md) own orchestration.
- Distinguish confirmed provider creation, confirmed local commit and customer Wallet save. An unknown provider or commit outcome does not prove rollback or authorize another POST.
- Preserve loose comparisons where the existing contract uses them. Formatting a provider comparison from `!=` to `!==` can reject previously accepted values.

This pure fixture function illustrates an existing Integration comparison; it does not validate provider approval or make HTTP requests:

```php
<?php

/**
 * Compares owned Class fields while ignoring unrelated provider metadata.
 *
 * @param array<string, mixed> $actual Approved provider Class supplied by the caller.
 * @param array{
 *     issuerName: mixed,
 *     programName: mixed,
 *     programLogo: mixed,
 *     hexBackgroundColor: mixed,
 *     classTemplateInfo: mixed
 * } $expected Current platform-owned fields required for the comparison.
 * @return bool Whether the owned fields match using the existing comparison semantics.
 */
function matchesOwnedClassFields(array $actual, array $expected): bool
{
    foreach ([
        'issuerName',
        'programName',
        'programLogo',
        'hexBackgroundColor',
        'classTemplateInfo',
    ] as $field) {
        if (($actual[$field] ?? null) != $expected[$field]) {
            return false;
        }
    }

    return true;
}
```

For a larger Integration, distinguish at least two actual method groups under titles such as `deployment configuration`, `JWT signing and encoding`, `class preparation and confirmation`, `object issuance and binding reconciliation`, and `provider HTTP transport`. Each group may contain one to N methods. If only one grouping is useful, use no boxes. Preserve blank lines around separators and secret parameter attributes. Do not invent interfaces, extra Services, retries, token caching or new response correction.

## Readable Pest tests

This test uses existing project collaborators to verify waiting content without local issuance or provider calls. It illustrates unboxed configuration/setup, expanded mock chains and assertions. Only one actual test responsibility is present, so the entire example uses no separator boxes. Preserve all existing tests, datasets and side-effect checks when refactoring; do not replace broader coverage with this small example.

```php
<?php

use App\Models\Business;
use App\Support\DatabaseClock;
use App\Support\InitialWalletPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    $this->business = Business::factory()->create([
        'timezone' => 'America/La_Paz',
    ]);

    $this->mock(DatabaseClock::class)
        ->shouldReceive('captureForBusinessTimezone')
        ->once()
        ->with('America/La_Paz')
        ->andReturn([
            'instant' => '2030-01-02 12:00:00+00',
            'business_date' => '2030-01-02',
        ]);
});

it('renders waiting content when no promotion exists', function () {
    $presentation = app(InitialWalletPresentation::class)->resolve($this->business);

    expect($presentation['state'])
        ->toBe('waiting')
        ->and($presentation['progress'])
        ->toBeNull()
        ->and($presentation['text_modules'][0])
        ->toMatchArray([
            'id' => 'promotion',
            'header' => '👀 PRÓXIMA PROMOCIÓN',
        ]);

    expect(DB::table('customer_passes')->count())
        ->toBe(0)
        ->and(DB::table('promotion_participations')->count())
        ->toBe(0);

    Http::assertNothingSent();
});
```

Setup and teardown never receive banners and do not count as test groups. The example has only one actual test responsibility, so it has no boxes. In a larger file, distinct groups such as `waiting presentation`, `active presentation` and `promotion fixtures` may justify separators; each can contain one to N tests/helpers. Do not invent distinctions or count hooks to reach a minimum group count.

Keep a short scalar dataset row inline when clear; expand long rows and associative fixtures. Preserve array spread position because later entries override earlier ones. Document named fixture helpers, not every test callback. Add no style-only tests for helper names or separator text.

## Final calibration

- Generate this readable structure on the first pass; tests and Pint do not substitute for the delivery gate.
- Use third-person English documentation, concrete names and truthful contracts. Names remain in the established callable verb form.
- Use zero boxes or at least two meaningful method/function groups in the same class/procedural file. Each group contains one to N callables, with a blank line above and below its banner. Reject isolated banners and artificial splits.
- Keep constructors, framework/Livewire lifecycle hooks, test configuration and test setup/teardown unboxed at the top and exclude them from the group count. Preserve normal documentation where applicable.
- Keep queries, conditions, payloads and assertions visible without a helper per line.
- Preserve comparisons, normalization timing, query order, clock usage, exception handling and private material.
- Distinguish structural review, syntax checks, runtime tests and independent generation checks when reporting verification.
