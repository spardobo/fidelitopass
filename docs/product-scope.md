# FidelitoPass Product Scope

This document defines the MVP boundary.

## Product objective

FidelitoPass gives a small Business one simple loyalty loop:

```text
Create challenge
    -> Customer scans permanent QR
    -> Customer saves Google Wallet pass
    -> Business validates repeat visits
    -> Challenge progress changes
    -> Reward unlocks
    -> Business redeems reward
    -> Same pass waits for the next challenge
```

The product wins through clarity and playful mechanics, not feature volume.

Current implementation includes registration, login, email verification, Business setup/profile, and a public landing page with a conceptual sample pass and static fictional QR/code; Challenge creation, customer acquisition, Wallet issuance, visit validation, and Redemption remain target MVP requirements, not delivered flows. The sample QR/code does not validate a visit.

## Must-have capabilities

### Public product

- Product landing page that explains the value proposition and how challenges work.
- Clear registration/sign-in entry point for Businesses.
- Public Business join page reached from the permanent acquisition QR.

### Business

- Register and authenticate.
- Configure Business name, logo/branding basics, and IANA timezone.
- Create multiple draft and future scheduled instances of one points-based Challenge mechanic, each with start date, end date, target points, and one Reward.
- Configure a fixed one-point regular Visit and any number of nonoverlapping weekly local-weekday xN multiplier windows (whole-day or timed).
- Preview customer Wallet copy before publication.
- Publish non-overlapping effective Challenge windows and cancel scheduled or active Challenges without erasing their history.
- View the active/scheduled Challenges and minimal operational counters.
- Display/download the permanent acquisition QR.
- Validate a customer by scanner or manual code.
- Redeem an available Reward.

### Customer

- Join without a FidelitoPass account.
- Save one persistent Google Wallet pass per Business.
- Understand the active Challenge directly from the pass.
- Present the pass on each visit.
- See numeric progress, next action, Reward, and deadline.
- Keep the same pass for future Challenges.

### Platform

- Record accepted Visits as timestamped facts.
- Accept legitimate repeat Visits while keeping validation retry-safe.
- Award points deterministically for every accepted Visit.
- Evaluate one points-based Challenge mechanic.
- Unlock at most one Reward entitlement per Customer pass and Challenge.
- Redeem exactly once before Challenge expiry.
- Keep PostgreSQL authoritative.
- Synchronize Google Wallet after committed domain changes.
- Produce structured application/security logs without secrets.

## Points and Challenge model

MVP uses one customer mental model:

```text
Visit -> Points -> Challenge progress -> Reward.
```

Every Challenge asks the customer to reach a target number of points before its end date.

Point earning remains intentionally small:

- Every accepted regular Visit awards exactly one point.
- Any number of weekly recurring rules may multiply that base by integer xN (N >= 2) on a Business-local weekday, either all day or in several disjoint half-open intraday windows.
- A whole-day rule excludes timed windows on that weekday; rules cannot cross midnight or stack. Outside windows x1 applies.
- Future Visits use the current rules and the active published Challenge timezone snapshot; stored awarded points are immutable.

There is no Challenge-type catalog and no generic rule builder in MVP.

## Explicit non-goals

MVP does not include:

- CRM or customer profile database.
- Customer email, telephone, or account registration.
- Advanced analytics or cohort analysis.
- Marketing automation.
- Email/SMS campaigns.
- Referrals.
- POS integration.
- Payments or billing.
- Multiple branches per Business.
- Staff roles beyond the owner account.
- Multiple simultaneous effective Active Challenges (draft and future scheduled instances are allowed).
- Multiple Rewards per Challenge.
- Reward inventory/capacity.
- Custom Challenge formulas.
- Overlapping multiplier windows or rule stacking.
- Apple Wallet.
- Native mobile applications.
- Customer discovery marketplace.
- Geofencing.
- Push-campaign management.

## Scope controls

### One-pass rule

A Customer keeps one persistent Wallet pass for one Business. Challenge changes update the pass instead of creating a new card.

### Database-clock rule

Domain validity uses PostgreSQL time. Browser clocks and application-host clocks do not decide whether a Challenge or Reward is valid.

### UTC-instant rule

Persistent instants use `timestamptz`. Business-local calendar meaning is derived using the Challenge timezone.

### Lean-dashboard rule

The Business dashboard shows operational information only:

- Current Challenge.
- Passes issued.
- Points earned in the current Challenge.
- Rewards unlocked.
- Rewards redeemed.

No advanced analytics are required.

### Deterministic-UI rule

The web application and Wallet use fixed layouts. Challenge state changes values and copy, not the structural UI.

## MVP completion

MVP is complete when one Business can demonstrate:

1. Registration and Business setup.
2. Permanent acquisition QR.
3. Publication of sequential Challenge instances without effective overlap.
4. Anonymous customer Wallet issuance.
5. Scan and manual-code validation.
6. Correct Challenge progress.
7. Retry-safe validation with legitimate repeat Visits supported.
8. Reward unlock.
9. One successful Redemption.
10. Challenge expiry behaviour.
11. Persistent Wallet reuse for a following Challenge.
12. Clean responsive dark-only web experience.
