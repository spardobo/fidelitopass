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

Keep source selection and complete output construction discoverable. Extract a state builder only when it owns a meaningful output, not a helper for each field or section. The pure example below consumes prepared localized rows; it does not query, choose a domain state or define product copy.

```php
<?php

/** Contains one prepared report section without changing its content. */
final readonly class ReportSection
{
    /**
     * Stores the localized heading and body.
     *
     * @param string $heading Prepared section heading.
     * @param string $body Prepared section content.
     */
    public function __construct(
        public string $heading,
        public string $body,
    ) {}
}

/**
 * Builds ordered sections from prepared report rows.
 *
 * @param list<array{heading: string, body: string}> $rows Localized rows selected by the caller.
 * @return list<ReportSection> Immutable sections in the supplied order.
 */
function buildReportSections(array $rows): array
{
    $sections = [];

    foreach ($rows as $row) {
        $sections[] = new ReportSection(
            heading: $row['heading'],
            body: $row['body'],
        );
    }

    return $sections;
}
```

The carrier exposes directly stored immutable data without trivial getters. This example does not require a carrier for every row or result: keep a shaped array when it is equally clear. Use the same property convention for sensitive stored values. Select fields explicitly at serialization and transport boundaries; readonly is not secrecy. Calculated predicates and safe projections remain methods because they perform behavior.

The construction loop is one responsibility and needs no additional helpers. A larger builder can separate source selection from complete state outputs when that reduces caller context. Preserve actual clock capture, timezone conversion, localization, selection and output order during a refactor; this generic example replaces none of those contracts. Add relevant `@throws` only when actual collaborator contracts establish failures.

## Transaction and integration preservation

Keep preconditions, transaction ownership, serialized lookup, exact replay, conflicts, writes and post-commit logging visible. A persistence helper can own a complete local decision while its caller owns the transaction and exception translation. It must remain inside the caller's transaction and must not call the provider.

- Preserve a specific uniqueness catch before the generic database catch. Never attach secret-bearing SQL/bindings as an exception cause merely to satisfy generic exception-chaining guidance.
- Preserve the stable Business lock, query order, tenant scope, association and model events. Do not replace `save()` with a direct insert or move logging before commit.
- Keep provider calls outside retryable local transactions. The [initial issuance boundary](../../../docs/architecture/overview.md#initial-issuance-boundary) and [ADR-006](../../../docs/architecture/decisions/006-google-first-initial-wallet-issuance.md) own orchestration.
- Distinguish confirmed provider creation, confirmed local commit and customer Wallet save. An unknown provider or commit outcome does not prove rollback or authorize another POST.
- Preserve loose comparisons where the existing contract uses them. Formatting a provider comparison from `!=` to `!==` can reject previously accepted values.

This pure fixture comparison selects owned leaf values explicitly. Unrelated metadata can occur inside a nested object, not only at the top level. It does not validate a live provider response or establish an identity-binding policy:

```php
<?php

/**
 * Compares selected fixture appearance fields without nested provider metadata.
 *
 * @param array{name: string, logo: array{url: string}} $actual Coherent fixture with possible extra metadata.
 * @param array{name: string, logo: array{url: string}} $expected Owned fixture values.
 * @return bool Whether the selected leaf values match exactly.
 */
function matchesOwnedAppearance(array $actual, array $expected): bool
{
    return $actual['name'] === $expected['name']
        && $actual['logo']['url'] === $expected['logo']['url'];
}
```

Comparing the entire `logo` map would also compare unrelated nested keys. Actual adapters must define their owned projection and required binding checks from the relevant contract; do not infer them from this fixture. Its strict string comparison is not permission to replace an existing loose comparison, normalize a credential or ignore an owned nested field.

In a larger Integration, keep configuration, signing, Class confirmation, Object reconciliation and transport responsibilities discoverable through names and adjacent methods. Use only useful phase/rationale comments under the [shared guidance](../../shared/coding-standards.md#comments-and-documentation). Preserve secret parameter attributes. Do not invent interfaces, extra Services, retries, token caching or new response correction.

## Readable Pest tests

This fixture test exercises the pure calculation above. It illustrates direct setup and expanded assertions, not production persistence or a provider deployment. Preserve broader scenarios, datasets, native transport mocks and side-effect checks when refactoring; this small example does not replace them.

```php
<?php

it('summarizes accepted points without changing the fixture facts', function () {
    $awardedPoints = [2, 3];
    $targetPoints = 8;

    $result = summarizePromotionProgress($awardedPoints, $targetPoints);

    expect($result)
        ->toBe([
            'earned' => 5,
            'remaining' => 3,
            'completed' => false,
        ]);

    expect($awardedPoints)
        ->toBe([2, 3]);
});
```

Setup and teardown names already communicate their role. Keep related scenarios adjacent and separate responsibilities with whitespace; do not manufacture groups to justify headings.

Keep a short scalar dataset row inline when clear; expand long rows and associative fixtures. Preserve array spread position because later entries override earlier ones. Document each named fixture helper with parameters and returned meaning; anonymous callbacks need no ceremonial boilerplate. Add no style-only tests for helper names or separator text.

## Final calibration

- Generate this readable structure on the first pass; tests and Pint do not substitute for the delivery gate.
- Review every name and block under [shared readability](../../shared/coding-standards.md#readability-and-cohesion), and each useful contract under [PHPDoc syntax](implementation-details.md#phpdoc-syntax). Keep names concise in scope and guarantees truthful; neither brevity nor documentation volume is the goal.
- Keep queries, conditions, payloads and assertions visible without a helper per line.
- Preserve comparisons, normalization timing, query order, clock usage, exception handling and private material.
- Distinguish structural review, syntax checks, runtime tests and independent generation checks when reporting verification.
