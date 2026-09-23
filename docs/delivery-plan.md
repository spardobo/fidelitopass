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
- Light/dark shell is available.

## Wave 2 — Challenge authoring and publication

**Outcome:** the Business can configure point earning and create/publish one deterministic points-based Challenge.

Requirements:

- REQ-CHL-001.
- REQ-CHL-002.
- REQ-CHL-003.
- REQ-CHL-004.
- REQ-CHL-005.
- REQ-DAT-001.

Demonstration:

- Regular Visit point value.
- Optional special weekday/time point rule.
- Challenge dates + target points + Reward.
- Deterministic preview.
- Publication to UTC window.
- Overlap/edit/cancel rules.

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
- Regular point awarding.
- Special weekday/time point awarding.
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
- 100/80/0 Strategic coverage is satisfied where coverage instrumentation applies.
- Zero unresolved project-owned static-analysis errors.
- Critical/high dependency findings are resolved or explicitly reviewed.
- Responsive and accessible core flows.
- Real-device Wallet issuance/update.
- Full end-to-end Business/customer loop.
