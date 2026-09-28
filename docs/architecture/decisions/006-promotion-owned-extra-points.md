# ADR-006: Own extra-point rules on frozen published Promotions

## Status

Accepted as a target design. Implementation, forward migration, and tests remain pending.

## Context

[ADR-003](003-single-points-challenge.md) established one points-based mechanic and immutable awarded Visits, but assigned recurring multiplier rules to mutable Business configuration applied at Visit validation. That ownership would allow a later Business setting to change the terms of an already published Promotion. The accepted product model instead gives each Promotion its own optional extra-point schedule and freezes the entire published aggregate, including scheduled Promotions. [ADR-004](004-database-time-and-business-calendar.md) remains authoritative for PostgreSQL clock and local-day semantics.

Existing applied `business_point_windows` migrations and rows are historical data, not evidence that Promotion ownership or publication freeze is already implemented.

## Options Considered

1. Retain mutable Business-global rules: simple shared configuration, but future Visits under existing published Promotions can change value without changing those Promotions.
2. Own rules per Promotion but permit edits after publication: isolates Promotions from Business changes, but scheduled and active customers still face mutable terms.
3. Own rules per Promotion and freeze the full aggregate on publication: requires draft editing and a forward data migration, but keeps published terms stable.

## Decision

Choose option 3. A Promotion owns optional weekly recurring x2, x3, or x5 extra-point windows; new Promotions inherit neither Business-global nor previous-Promotion rules. A regular accepted Visit awards exactly one point. For each local weekday, use either one whole-day rule or distinct, nonoverlapping half-open `[start, end)` intraday windows. Touching endpoints are allowed; split overnight periods into separate weekday rules. Exactly one multiplier applies, never stacked; outside all windows the value is x1.

Drafts remain editable. Save each draft and publish the complete Promotion aggregate atomically. Publication freezes its original local dates and UTC start/exclusive-end window, timezone snapshot, point goal, Reward title and optional description, and all extra-point weekdays, times, and multipliers. A scheduled Promotion is already published and frozen before it starts. Business profile and pass appearance are separate from this aggregate.

Cancellation is a lifecycle transition, not a configuration edit: retain the original published terms, dates, UTC window, awarded Visits, and history; stop progress and redemption immediately and release only remaining effective occupancy. Published effective windows for one Business must not overlap, though endpoints may touch. A cancelled interval occupies `[starts_at, min(ends_at, cancelled_at))`, empty if cancelled before its start. Date-only replacement after intraday cancellation cannot start before the next Business-local midnight.

This decision supersedes **only** ADR-003's Business-global mutable point-rule ownership and change policy. Its single points-based mechanic and immutable `points_awarded` on each accepted Visit remain authoritative. ADR-004's database clock, timezone snapshot, and local-day evaluation remain authoritative.

## Rationale

Promotion ownership makes the applicable award rules explicit at publication. Freezing every published term protects scheduled and active customers from later configuration changes without introducing another loyalty mechanic or recalculating historical Visits.

## Consequences

- Future Visit awards use the active published Promotion's frozen schedule and timezone snapshot; recorded Visit points never change when a later Promotion or Business setting changes.
- Draft editing, publication, and cancellation need atomic, serialized handling. Publication and cancellation lock the Business row, recompute effective occupancy, and use one post-lock PostgreSQL `clock_timestamp()` operation instant for mutating decisions, consistent with ADR-004.
- No published configuration edit route is allowed, including for scheduled Promotions; cancellation preserves the snapshot while releasing future occupancy.
- Implementation needs a **forward migration** that preserves already-applied `business_point_windows` migrations and existing rows. Do not reset or rewrite applied migrations, silently delete rows, or automatically reassign legacy Business rules to Promotions. Resolve compatibility and historical traceability explicitly before migration.
- Promotion-owned persistence and behavior tests, including time boundaries, overlap/cancellation, immutable published terms, legacy-row preservation, and immutable awarded points, are pending. This ADR does not claim a schema migration or behavior has shipped.
