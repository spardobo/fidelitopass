# FidelitoPass Delivery Plan

This document groups requirement IDs into rolling delivery waves. It does not redefine behaviour.

Load only the current wave into detailed work. Keep later waves at this outcome level until dependencies are ready.

## Wave 1 — Product entry and Business foundation

**Outcome:** FidelitoPass has a polished public entry point and an authenticated Business with correct local-time configuration.

Requirements:

- REQ-PUB-001.
- REQ-BIZ-001.
- REQ-BIZ-002.
- REQ-UX-001.
- REQ-UX-003.
- REQ-SEC-002.

Demonstration:

- Anonymous visitor understands FidelitoPass from the landing page.
- Owner registers/signs in.
- Business profile/timezone is configured.
- Public, authentication, and application pages demonstrate the dark-only shell without a light-theme switch.

## Wave 2 — Challenge authoring and publication

**Outcome:** the Business can configure recurring point multipliers and author, preview, schedule, publish, and cancel instances of one points-based Challenge mechanic.

Requirements:

- REQ-CHL-001.
- REQ-CHL-002.
- REQ-CHL-003.
- REQ-CHL-004.
- REQ-CHL-005.
- REQ-DAT-001.

Demonstration:

- Fixed one-point regular Visit and multiple disjoint weekly xN multiplier windows (whole-day or timed).
- Challenge dates + target points + Reward.
- Deterministic preview.
- Publication to immutable UTC windows with timezone snapshots; multiple drafts/future instances, at most one effective Active.
- Serialized overlap/edit/cancel rules; cancellation releases remaining occupancy without changing publication history.

Wave 2 does not validate Visits, issue Wallet passes, award points, or redeem Rewards. Its preview is conceptual and deterministic.

## Wave 3 — Acquisition and persistent Google Wallet pass

**Outcome:** a customer can join anonymously and keep one persistent Business pass.

Requirements:

- REQ-PAS-001.
- REQ-PAS-002.
- REQ-PAS-003.
- REQ-PUB-002.
- REQ-WAL-001.
- REQ-SEC-001.

Demonstration:

- Permanent Business QR.
- Join page.
- Anonymous Customer pass.
- Google Wallet issuance.
- Deterministic waiting/active Wallet state.

## Wave 4 — Visit validation and points calculation

**Outcome:** the Business can validate fast counter Visits, award the correct points, and update Challenge progress.

Requirements:

- REQ-VIS-001.
- REQ-VIS-002.
- REQ-VIS-003.
- REQ-VIS-004.
- REQ-EVL-001.
- REQ-EVL-002.
- REQ-EVL-003.
- REQ-EVL-004.
- REQ-UX-002.
- REQ-UX-004.

Demonstration:

- Scanner.
- Manual code immediately below scanner.
- Database-time Visit.
- Legitimate same-day repeat Visits.
- Idempotent validation retries.
- Every accepted regular Visit awards exactly one point.
- Applicable Business-local weekday/time xN multiplier awards N immutable points per accepted Visit, with no stacking; outside windows x1 applies.
- Challenge completion from target points.

## Wave 5 — Reward and Wallet synchronization

**Outcome:** Challenge completion unlocks a Reward that is redeemed once before expiry and reflected in Wallet.

Requirements:

- REQ-REW-001.
- REQ-REW-002.
- REQ-REW-003.
- REQ-WAL-002.
- REQ-REL-001.

Demonstration:

- Reward unlock.
- Wallet Reward state.
- One final Redemption.
- Expired/cancelled rejection.
- Provider failure does not corrupt local facts.

## Wave 6 — Security, logging, and release hardening

**Outcome:** the complete MVP is safe, diagnosable, responsive, and ready for production use.

Requirements:

- REQ-SEC-003.
- REQ-QLT-001.

Also verify all prior Must requirements as one complete journey.

Demonstration:

- Structured JSON logs with request correlation.
- No secrets in representative logs.
- Required automated tests pass at 100% with zero accepted flaky tests.
- Every identified CORE rule has direct automated evidence; instrumented IMPORTANT coverage is reviewed against the 80% diagnostic reference for meaningful gaps, not gated on the number; INFRASTRUCTURE receives applicable runtime checks without a coverage quota.
- Zero unresolved project-owned static-analysis errors.
- Critical/high dependency findings are resolved or explicitly reviewed.
- Responsive and accessible core flows.
- Real-device Wallet issuance/update.
- Full end-to-end Business/customer loop.
