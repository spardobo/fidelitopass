# FidelitoPass Application Boundary Contracts

This document defines application-level integrity, integration and operational outcomes. Coding HOW belongs to the implementation skills selected through the [project contract](../../AGENTS.md#intent-routing), not this document. Framework documentation and installed source own API syntax.

Earlier domain, architecture, security, persistence and presentation documents retain their authority. The sections below apply those contracts rather than redefining them; this document owns application-level operational guarantees. Read only the owner sections needed for the current decision; these contracts do not authorize application-wide rollout.

## Points and Promotion calculation

Accepted Visits preserve the awarded value from the active published Promotion's frozen terms. Progress and Reward entitlement follow the [Promotion model](../promotion-model.md#point-earning), including its progress and completion rules; later drafts or Business changes cannot rewrite awarded outcomes.

## Time and date handling

Application timezone remains UTC. Domain validity uses PostgreSQL time, not an application or browser clock. Lock-sensitive mutations capture one post-lock operation instant and reuse it for validity, local calendar evaluation and domain timestamps, as defined by the [database time model](database-standard.md#timestamps-and-timezone-model).

Rule-relevant model dates remain immutable under [Application date handling](database-standard.md#application-date-handling). Published Promotion timezone snapshots govern active and historical calendar meaning; drafts use the current Business timezone.

## Transactions and external effects

Each consequential operation has one complete authoritative transaction boundary. The [architecture transaction contracts](../architecture/overview.md#visit-validation-transaction) define locking, operation time, idempotency and finality for Visit validation and Redemption.

Network/provider calls never execute inside that transaction. Wallet synchronization occurs after the authoritative commit. If Google Wallet synchronization fails, domain state remains committed.

## Authorization

Business ownership is explicit. The server verifies ownership at the protected operation boundary; a hydrated identifier or hidden input is not authority.

Customer passes are not `User` accounts. Every protected pass operation is scoped through the authenticated Business before progress or Reward details are returned. Apply the [security authorization contract](../architecture/security.md#authorization) and [credential classes](../architecture/security.md#credential-classes); public acquisition and private validation are not interchangeable.

## Validation

Server-side validation is authoritative; client validation is UX only. Promotion configuration and lookup input must satisfy the [security input contract](../architecture/security.md#input-validation), including allowed multipliers, disjoint windows, whole-day/timed exclusivity, valid timezones and Business-scoped manual codes.

## Google Wallet integration

The provider boundary owns Class/Object payload mapping, object creation/update, Save-to-Wallet issuance payloads and provider error classification.

It does not own Promotion eligibility, Visit acceptance, Reward completion or Redemption authorization. PostgreSQL remains domain truth. Synchronization reloads current authoritative state, tolerates duplicate execution and preserves committed outcomes under the architecture's [background-work contract](../architecture/overview.md#background-work).

## Structured logging

### Production format

Production writes one JSON object per log record to `stderr` so container/platform collectors can ingest it. Local development may use a human-readable channel, but event semantics remain the same.

### Correlation

Each request receives one UUID correlation identifier shared by its log records. Apply the [security logging contract](../architecture/security.md#structured-security-logging), including response correlation where appropriate.

Stable context includes the event, request correlation, relevant actor and domain identities, outcome and reason. Include only relevant fields; source owns exact field names.

Use stable machine-readable names for acceptance, replay, rejection, unlock, redemption, cancellation, provider failure and throttling events. Their exact names belong to implementation.

### Secret hygiene

Never log passwords, session/cookie values, authorization headers, raw validation tokens, Google Wallet private keys, full Save-to-Wallet JWTs or sensitive provider payloads. The security owner retains the broader sensitive-field policy.

## Error handling

Expected domain rejections become clear UI states. Unexpected exceptions return a safe generic message, include the request ID when useful for support and retain detailed server-side context without secrets. Production never exposes stack traces. Apply the [security error contract](../architecture/security.md#error-handling), including enumeration protection.

## Security headers

The [security header baseline](../architecture/security.md#security-headers) governs deployment. A strict CSP requires evidence that it preserves Livewire/Alpine/Vite behavior; do not introduce an untested policy that breaks the application.

## Retired two-factor persistence

The retirement migration removes only existing two-factor secrets, recovery codes and confirmation storage. It also succeeds on fresh no-2FA schemas where all three are absent. Rollback is intentionally a no-op: deleted credential values cannot be recovered, and rollback must not recreate retired columns. Compatible starter behavior is preserved; two-factor authentication stays disabled and passkeys remain independently configured.

Future two-factor re-enablement requires explicit opt-in, a new forward credential migration, compatible setup/challenge UI and tests, and enrollment with new credentials. Rollback is not credential recovery.
