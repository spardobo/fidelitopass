# ADR-006: Confirm Google Object creation before initial local pass issuance

## Status

Accepted design. Provider integration is not implied by this decision.

## Context

Public acquisition has no customer account or persistent browser identity. The customer explicitly requests saving; opening the join page alone must not create a Customer pass. Definitive Google creation failure must not leave a newly issued local pass.

Initial issuance spans Google and PostgreSQL. They cannot share one atomic transaction. Existing Visit and Redemption synchronization already follows local commit and must retain that safety.

## Options Considered

1. Create the local pass first and retry Google provisioning. This leaves local passes after definitive provider failure.
2. Create Google through REST first, then commit the local pass and return an existing-Object save handoff. This accepts provider orphans after confirmed local rollback.
3. Defer local issuance until after a user Wallet-save signal, with durable attempts or browser identity. This adds reservation/recovery state and does not fit the accepted explicit request flow.

## Decision

Use option 2 for initial issuance only. Explicit Save shows loading while the backend requests Google Loyalty Object creation. Only confirmed provider success permits the local Customer pass transaction. Only confirmed local commit permits private delivery of the Save-to-Wallet JWT referencing that existing Object and the manual fallback code.

Definitive Google failure creates no local Customer pass. Confirmed local rollback after successful Google creation may leave a provider orphan. No automatic orphan cleanup or recovery is guaranteed. Unknown provider or local commit outcomes are neither success nor proof of rollback; do not blindly create another pair to mask uncertainty.

Use sanitized correlation/operation identifiers in application logs. Do not add a durable attempt/reservation table or persistent browser identity. Reuse the current operation identity where available to prevent double-click duplication, without promising durable or cross-visit deduplication.

This decision replaces only the initial-issuance timing/retry obligations identified by [REQ-WAL-003](../../requirements.md#req-wal-003--google-first-initial-customer-pass-issuance). [ADR-002](002-persistent-google-wallet-pass.md) remains applicable: update each issued Object across Promotions. Its persistent-pass decision and all Visit/Redemption post-commit safety remain unchanged.

## Rationale

The selected order meets the explicit saving flow without local issuance after definitive Google rejection. It avoids an additional identity or durable recovery subsystem. The product accepts the remaining provider-orphan cost rather than claiming cross-system atomicity.

## Consequences

- Issuance success confirms the provider/local pair, not that the customer saved or downloaded the pass in Wallet.
- The backend must distinguish definitive failures from uncertain provider/commit outcomes.
- Abandonment after pair creation leaves an issued pair. A new acquisition visit may create another pair, including on the same browser; this accepted duplication does not alter in-place updates of an existing pass.
- Sanitized application logs support diagnosis without storing tokens, JWTs, manual codes or sensitive provider payloads.
- Existing Visit and Redemption facts still commit before asynchronous Wallet updates; provider failure cannot undo them.
