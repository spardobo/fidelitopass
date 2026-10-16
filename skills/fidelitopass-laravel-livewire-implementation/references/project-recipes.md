# Project recipes
Source-sensitive examples. Inspect current migrations and callers before applying. Recipes illustrate; current schema and installed APIs own truth.

## Consequential command names
```text
PublishPromotion
CancelPromotion
ValidateVisit
RedeemReward
InspectCustomerPass
```

No Action suffix. No per-step Actions.

## Visit validation transaction
```text
begin transaction
  lock Business
  lock Customer pass and Promotion rows
  validate pass and Promotion belong to Business
  validate credential or Business-scoped manual lookup
  verify operation identity is scoped to pass and Promotion
  if operation already committed, return prior result without mutation
  capture clock_timestamp() once as operation_at
  check Promotion active and not cancelled at operation_at
  resolve one frozen multiplier at operation_at in Business timezone
  insert Visit with confirmed_at = operation_at and awarded_points
  sum awarded points for the active Promotion
  create Reward entitlement if target reached
  confirm the same Wallet Object synchronously inside this non-retrying transaction
  propagate provider failure so tentative local facts roll back
commit only after provider confirmation
```

## Redemption transaction
```text
begin transaction
  lock Business
  lock entitlement and Promotion rows
  validate ownership
  if already redeemed, return final state without mutation
  capture clock_timestamp() once as operation_at
  validate Promotion active and not cancelled
  set redeemed_at = operation_at, redeemed_by_user_id
  confirm the same Wallet Object synchronously inside this non-retrying transaction
  propagate provider failure so tentative local facts roll back
commit only after provider confirmation
```

Existing-pass authentication and all provider requests share ten seconds with no automatic retries. Initial issuance remains the separate Google-first flow; provider confirmation does not prove local commit or device rendering.

## Clock capture
The only authorized source of domain time. Capture it after required locks for a mutation; a read-only hint does not authorize a later write:

```php
$operation = $this->databaseClock->captureForBusinessTimezone($business->timezone);
// $operation['instant']       — UTC string for timestamps
// $operation['business_date'] — local date for comparisons
```

Never now(), Carbon::now(), today(). Never the PHP default timezone.

## Local date from an instant
```sql
(operation_at AT TIME ZONE promotion_timezone)::date
```

## Window conversion (publication)
```sql
SELECT (?::date::timestamp AT TIME ZONE ?) AS starts_at,
       ((?::date + 1)::timestamp AT TIME ZONE ?) AS ends_at
```

## Physical shape (reference)
customer_passes: id, public_id, business_id, wallet_object_id nullable, validation_token_hash nullable, manual_code nullable, timestamps.

promotions: id, public_id, business_id, local_start_date nullable, local_end_date nullable, target_points, reward_title, reward_description nullable, timezone_snapshot nullable, starts_at nullable, ends_at nullable, status, cancelled_at nullable, timestamps.

visits: id, business_id, customer_pass_id, promotion_id, confirmed_by_user_id, operation_id, awarded_points, confirmed_at, timestamps.

reward_entitlements: id, business_id, customer_pass_id, promotion_id, unlocked_at, redeemed_at nullable, redeemed_by_user_id nullable, timestamps.

## Logging event names
visit.accepted, visit.replayed, visit.rejected, reward.unlocked, reward.redeemed, promotion.cancelled, wallet.sync_failed, auth.throttled, customer_pass.lookup_rejected, customer_pass.lookup_throttled, customer_pass.lookup_unavailable.

Stable context keys: request_id, actor_type, actor_id, business_id, promotion_id, customer_pass_id, outcome, reason.

Never pass raw Throwable as context. Never include SQL, bindings, credentials, tokens.

## Google Wallet mapping
One Business loyalty class per Business.

One loyalty object per Customer pass.

Text modules carry the current project-owned progress, description, Visit value, Reward and validity presentation. Follow the Wallet presentation owner instead of assuming provider fields define domain rules.

Barcode carries the validation token; alternate manual code as text.

No dynamic progress images in MVP.