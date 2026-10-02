# Readable PHP calibration

Read only when paragraph structure, naming or useful contracts need an example. This pure example consumes bounded, already-authorized fixture facts; production database aggregates remain the preferred way to obtain totals. It does not accept Visits, check validity, persist progress or unlock Rewards.

```php
<?php

declare(strict_types=1);

/**
 * Summarizes bounded awarded-point facts without changing them.
 *
 * Values and their total must fit the native integer range.
 *
 * @param list<positive-int> $awardedPoints Accepted values for one pass and Promotion.
 * @param positive-int $targetPoints The published target, validated by the caller.
 * @return array{earned: int, remaining: int, completed: bool}
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

The paragraph separates calculation from the returned meaning. The shape adds information the native array signature cannot express. One cohesive function needs neither helper fragmentation nor a decorative box. Annotations express caller preconditions, not runtime validation. This example does not choose a production integer bound or replace domain authorization.

For a transactional command, keep authorization, serialized reads, replay, new eligibility and writes visible in one owned boundary. Add short rationale only where order or an invariant is not self-evident. Never copy the inventory-domain schema or application-host clock from an unrelated generic example into this project.
