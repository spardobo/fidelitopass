# FidelitoPass Product Scope

This document defines the MVP boundary.

## Product objective

FidelitoPass gives a small Business one simple loyalty loop:

```text
Create Promotion
    -> Customer scans permanent acquisition QR
    -> Customer saves Google Wallet pass
    -> Business validates repeat Visits
    -> Promotion progress changes
    -> Reward unlocks
    -> Business redeems Reward
    -> Same pass waits for the next Promotion
```

The product wins through clarity and playful mechanics, not feature volume.

Implementation status (separate from this product design): registration, login, email verification, Business setup/profile, and a public landing page with a conceptual sample pass and static fictional QR/code exist. Promotion creation, customer acquisition, Wallet issuance, visit validation, and Redemption are not delivered flows. The sample QR/code does not validate a Visit.

## Must-have capabilities

### Public product

- Product landing page that explains the value proposition and how Promotions work.
- Clear registration/sign-in entry point for Businesses.
- Public Business join page reached from the permanent acquisition QR.

### Business

- Register and authenticate.
- Configure Business name, logo/branding basics, and IANA timezone.
- Use Summary as the ordinary authenticated application home, without requiring it as a first stop after registration or sign-in; its setup action and navigation lead to the same Pase route, without a forced wizard.
- Create multiple editable drafts and future scheduled instances of one points-based Promotion mechanic, each with Business-local start and end dates, target points, one Reward and optional description.
- Configure a fixed one-point regular Visit and Promotion-owned extra points: any number of nonoverlapping weekly local-weekday x2, x3 or x5 windows (whole-day or timed), never inherited from Business-global settings or another Promotion.
- Preview deterministic customer Wallet copy before publication and reconfirm if the Business timezone changes after review.
- Publish non-overlapping effective Promotion windows, freezing goal, Reward title and description, dates, timezone, and entire multiplier schedule even when scheduled; cancel scheduled or active Promotions without erasing their original snapshot or history.
- View draft, active, scheduled, ended and cancelled Promotions and minimal operational counters on Pase and Summary.
- Display/download the permanent acquisition QR.
- Validate a Customer pass in one scanner-first dialog with manual-code fallback immediately below the scanner, followed by explicit confirmation and result in the same dialog.
- Redeem an available Reward.

### Customer

- Join without a FidelitoPass account.
- Save one persistent Google Wallet pass per Business.
- Understand the active Promotion directly from the pass.
- Present the pass on each visit.
- See numeric progress, next action, Reward, and deadline.
- Keep the same pass for future Promotions.

### Platform

- Record accepted Visits as timestamped facts.
- Accept legitimate repeat Visits while keeping validation retry-safe.
- Award points deterministically for every accepted Visit.
- Evaluate one points-based Promotion mechanic using its published timezone and multiplier schedule snapshot.
- Unlock at most one Reward entitlement per Customer pass and Promotion.
- Redeem exactly once before Promotion expiry or cancellation.
- Keep PostgreSQL authoritative.
- Synchronize Google Wallet after committed domain changes.
- Produce structured application/security logs without secrets.

## Points and Promotion model

MVP uses one customer mental model:

```text
Visit -> Points -> Promotion progress -> Reward.
```

Every Promotion asks the customer to reach a target number of points before its end date.

Point earning remains intentionally small:

- Every accepted regular Visit awards exactly one point.
- Each Promotion independently configures weekly recurring multiplier windows at x2, x3 or x5 on a local weekday, either all day or in several disjoint half-open `[start, end)` intraday windows; touching endpoints are allowed.
- A whole-day entry excludes timed windows on that weekday; intervals cannot cross midnight or stack (split overnight periods across weekdays). Outside windows x1 applies.
- Future Visits use the active published Promotion's frozen schedule and timezone snapshot; stored awarded points are immutable. No Business-global or previous-Promotion schedule is inherited.

There is no Promotion-type catalog and no generic rule builder in MVP.

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
- Multiple simultaneous effective Active Promotions (draft and future scheduled instances are allowed).
- Multiple Rewards per Promotion.
- Reward inventory/capacity.
- Custom Promotion formulas.
- Overlapping multiplier windows or rule stacking.
- Apple Wallet.
- Native mobile applications.
- Customer discovery marketplace.
- Geofencing.
- Push-campaign management.

## Scope controls

### One-pass rule

A Customer keeps one persistent Wallet pass for one Business. Promotion changes update the pass instead of creating a new card.

### Database-clock rule

Domain validity uses PostgreSQL time. Browser clocks and application-host clocks do not decide whether a Promotion or Reward is valid.

### UTC-instant rule

Persistent instants use `timestamptz`. Business-local calendar meaning is derived using the published Promotion timezone snapshot. Local start midnight is inclusive; midnight after the local end date is exclusive.

### Lean-dashboard rule

The Business dashboard shows operational information only:

- Current active Promotion, or an honest waiting/scheduled state when none is active.
- Distinct Customer passes with an accepted Visit in that active Promotion (not passes issued).
- Distinct Customer passes with at least two accepted Visits in that active Promotion, each counted once. Same-day repeat Visits count; extra points do not count as additional Visits.
- Reward entitlements unlocked in that Promotion, including redeemed ones.
- Reward entitlements definitively redeemed in that Promotion.

Known empty results for an active Promotion show zero; unavailable statistics show an unknown value with a local error, not zero, and do not by themselves gate authorized operations. Without an active Promotion show waiting rather than a grid of zeroes. No advanced analytics are required.

### Deterministic-UI rule

The web application and Wallet use fixed layouts. Promotion state changes values and copy, not the structural UI.

## MVP completion

MVP is complete when one Business can demonstrate:

1. Registration and Business setup.
2. Permanent acquisition QR.
3. Publication of sequential Promotion instances without effective overlap, including immutable scheduled terms and cancellation.
4. Anonymous customer Wallet issuance through the permanent public acquisition QR.
5. Scanner-first and manual-code validation in one confirmation dialog.
6. Correct Promotion progress from its own frozen multiplier schedule.
7. Retry-safe validation with legitimate repeat Visits supported.
8. Reward unlock.
9. One successful Redemption.
10. Promotion expiry and cancellation behaviour.
11. Persistent Wallet reuse for a following Promotion.
12. Clean responsive dark-only web experience.
