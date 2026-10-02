# Project implementation recipes

Read the relevant section only when implementing data, authoritative operations, Wallet mapping or credential retirement. These recipes preserve useful implementation material moved from normative documents. They are illustrative and source-sensitive: inspect current migrations, installed APIs and callers before applying them. Documents own the guarantees; current code owns exact names and schema. Do not copy the whole reference or create all listed classes/tables as a scaffold.

## Contents

- [Command and transaction recipes](#consequential-command-names)
- [Calendar conversion](#business-local-conversion)
- [Physical shape examples](#physical-schema-examples)
- [Model date handling](#laravel-model-date-handling)
- [Credential retirement](#retired-credential-migration)
- [Logging](#operational-logging-implementation)
- [Wallet payload mapping](#google-wallet-payload-mapping)

## Consequential command names

Possible Action names; use existing naming contracts when already established:

```text
PublishPromotionAction
CancelPromotionAction
IssueCustomerPassAction
ValidateVisitAction
RedeemRewardAction
```

## Visit validation transaction recipe

```text
begin transaction
  resolve authenticated Business
  lock relevant Customer pass and Promotion rows
  validate pass and Promotion belong to authenticated Business
  validate credential or authorized Business-scoped manual lookup
  verify validation operation identity is scoped to this pass and Promotion
  if this operation already committed, return its prior accepted result without mutation
  capture clock_timestamp() exactly once as operation_at for a new mutation
  check published Promotion is active and not cancelled using operation_at
  resolve one frozen Promotion-owned multiplier using operation_at in its timezone
  insert Visit with visited_at = operation_at and points_awarded
  sum awarded points for the active Promotion
  create Reward entitlement if target points are reached
commit
dispatch Wallet synchronization after commit
```

## Redemption transaction recipe

```text
begin transaction
  resolve authenticated Business
  lock relevant entitlement and Promotion rows
  validate entitlement, pass and Promotion belong to authenticated Business
  validate credential or authorized Business-scoped manual lookup
  verify requested redemption identifies this entitlement
  if already redeemed, return its final redeemed state without mutation
  capture clock_timestamp() exactly once as operation_at for a new mutation
  validate Promotion active and not cancelled using operation_at
  set redeemed_at = operation_at
  set redeemed_by_user_id
commit
dispatch Wallet synchronization after commit
```

## Business-local conversion

```sql
operation_at
AT TIME ZONE promotion_timezone
```

## Current database time

```sql
clock_timestamp()
```

## Web route example

```text
/
  Product landing

/join/{business}
  Business join / Add to Google Wallet

Authenticated Business
  Dashboard
  Promotion draft/edit/preview/publish/cancel
  Acquisition QR
  Validate visit (one scanner/manual fallback dialog)
  Business settings
```

## Current local date for a read

```sql
(clock_timestamp() AT TIME ZONE promotions.timezone)::date
```

## Local date from the mutation instant

```sql
(operation_at AT TIME ZONE promotions.timezone)::date
```

## Local Visit date

```sql
(visited_at AT TIME ZONE promotions.timezone)::date
```

## Final included local date

```sql
(ends_at AT TIME ZONE promotions.timezone)::date - 1
```

## Local start date

```sql
(starts_at AT TIME ZONE promotions.timezone)::date
```

## Internal key migration syntax

```php
$table->id();
```

## Physical schema examples

The following examples were moved from the data standard. They illustrate its guarantees and are not a second schema authority. Check current migrations before use; adapt names and structure to the existing application. Preserve owner-approved integrity, including same-Business relationships, even where a suggested shape does not show the complete constraint mechanism.

### reward_entitlements shape example

Suggested shape:

```text
id
customer_pass_id
promotion_id
unlocked_at
redeemed_at nullable
redeemed_by_user_id nullable
created_at
updated_at
```

### visits shape example

Suggested shape:

```text
id
customer_pass_id
promotion_id
validated_by_user_id
idempotency_key
points_awarded
visited_at
created_at
updated_at
```

### customer_passes shape example

Suggested shape:

```text
id
public_id
business_id
wallet_object_id nullable
validation_token_hash
manual_code
issued_at
created_at
updated_at
```

### promotions shape example

Suggested shape:

```text
id
public_id
business_id
target_points
reward_title
reward_description nullable
timezone
starts_at
ends_at
status
published_at nullable
cancelled_at nullable
created_at
updated_at
```

### Promotion-owned multiplier windows shape example

Suggested shape:

```text
id
promotion_id
weekday
multiplier
start_time nullable
end_time nullable
created_at
updated_at
```

### businesses shape example

Suggested shape:

```text
id
public_id
user_id
name
timezone
logo_path nullable
created_at
updated_at
```

## Laravel model date handling

Use `immutable_datetime` casts for project-owned rule-relevant timestamps when mutation would be surprising.

Keep Laravel application timezone at UTC.

Carbon/CarbonImmutable may be used to:

- Parse Business-local date input.
- Render local dates to the UI.
- Build deterministic publication boundaries.

Once the Promotion window is persisted, live Promotion/Reward validity uses PostgreSQL time.

Generic Eloquent `created_at` / `updated_at` remain framework timestamps and are not used as domain validity clocks.

## Retired credential migration

The retirement migration drops only existing columns among `users.two_factor_secret`, `users.two_factor_recovery_codes` and `users.two_factor_confirmed_at`. It also succeeds when all three columns are absent. Rollback is a no-op; use a new forward migration and new enrollment for re-enablement. Preserve original starter views/schema migration and independently configured passkeys.

## Operational logging implementation

Use Laravel's Monolog integration and configured JSON output. Keep stable context keys such as `event`, `request_id`, `actor_type`, `actor_id`, `business_id`, `promotion_id`, `customer_pass_id`, `outcome` and `reason` where relevant. Example event names: `visit.accepted`, `visit.replayed`, `visit.rejected`, `reward.unlocked`, `reward.redeemed`, `promotion.cancelled`, `wallet.sync_failed`, `auth.throttled`. These are source-owned conventions, not claims about already installed logging. Keep emission timestamp distinct from authoritative domain time when delayed work needs both.

## Google Wallet payload mapping

Use the Google Wallet loyalty Class/Object model:

- One shared Business loyalty class for Business-level identity/presentation.
- One loyalty object per Customer pass.
- Field `loyaltyPoints` or equivalent structured field for numeric Promotion progress where useful.
- Text modules for Promotion description, current Visit point value, Reward, and validity.
- Barcode for the validation token.
- Alternate barcode text/manual code for quick fallback where suitable.

Avoid custom dynamic progress images in MVP. Numeric progress keeps the presentation stable and easy to synchronize.
