# ADR-007: Synchronous updates for existing Wallet Objects

## Status

Accepted.

Supersedes the Visit/Redemption post-commit assumption retained by [ADR-006](006-google-first-initial-wallet-issuance.md), not its initial-issuance decision or the persistent identity in [ADR-002](002-persistent-google-wallet-pass.md).

## Context

The MVP counter interaction needs a bounded confirmation of updated Wallet content before accepting a new Visit or Redemption. PostgreSQL remains authoritative. Google and PostgreSQL cannot participate in one atomic transaction.

## Options Considered

1. Commit locally, then update Google synchronously. Provider failure leaves an accepted operation with stale Wallet content.
2. Commit locally, then dispatch retryable background synchronization. This requires worker operation and recovery semantics beyond the selected MVP boundary.
3. Write tentative local facts, confirm Google synchronously, then commit locally. This holds database locks during bounded provider work and accepts exceptional remote/local divergence.

## Decision

Use option 3 for new Visit and Redemption operations on existing Customer passes. The domain caller owns authorization, locks, validation and one non-retrying transaction. The integration reads tentative state, updates only the existing Object's owned presentation and confirms the result before the caller commits.

Authentication and all provider requests share a monotonic ten-second budget. Each request uses the remaining budget. Do not automatically retry authentication, reads, writes or the owning transaction. One same-Object read may confirm an incomplete write response; it is not a write retry.

Propagate failures so the caller rolls back tentative writes. Preserve Object identity, Class binding, barcode and manual code. Log classified provider outcomes without credentials or raw provider failures; present nontechnical failure copy to the cashier. Provider confirmation alone is not a successful local operation.

Initial issuance retains its separate Google-first, then local-transaction boundary. No Wallet job, worker, outbox or durable synchronization registry is introduced. Publication, cancellation and time-driven refresh remain separate work.

## Rationale

The selected boundary keeps the counter interaction synchronous and avoids background recovery infrastructure for the MVP. Absolute content updates preserve the existing Object without incrementing remote values.

## Consequences

- Provider rejection or unavailability prevents a new local operation from committing.
- Locks remain held during provider work; the ten-second provider budget does not bound database waits or the entire HTTP request.
- A lost response or failed local commit can leave Google ahead of PostgreSQL. Local rollback does not undo a remote update.
- A later authorized update sends the resulting authoritative content; no automatic reconciliation guarantee is introduced.
- Google confirmation does not prove immediate rendering on the customer's device.
- Integration capability and transactional tests do not establish that cashier Visit/Redemption commands or other refresh triggers are implemented.
