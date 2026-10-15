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

Existing-pass Wallet updates execute inside that transaction under the synchronous MVP exception: provider confirmation gates commit, and failure escapes to roll back tentative writes. Do not retry a transaction that may already have written remotely. Authentication and all provider requests share ten seconds total with no automatic retries. A lost response or failed local commit can leave remote content ahead of PostgreSQL; no distributed rollback is guaranteed. Initial issuance instead uses the architecture's [initial issuance boundary](../architecture/overview.md#initial-issuance-boundary): confirmed Google creation precedes the local transaction; no cross-system rollback guarantee exists.

## Authorization

Business ownership is explicit. The server verifies ownership at the protected operation boundary; a hydrated identifier or hidden input is not authority.

Customer passes are not `User` accounts. Every protected pass operation is scoped through the authenticated Business before progress or Reward details are returned. Apply the [security authorization contract](../architecture/security.md#authorization) and [credential classes](../architecture/security.md#credential-classes); public acquisition and private validation are not interchangeable.

## Validation

Server-side validation is authoritative; client validation is UX only. Promotion configuration and lookup input must satisfy the [security input contract](../architecture/security.md#input-validation), including allowed multipliers, disjoint windows, whole-day/timed exclusivity, valid timezones and Business-scoped manual codes.

## Google Wallet integration

The provider boundary owns Class/Object payload mapping, object creation/update, Save-to-Wallet issuance payloads and provider error classification.

Official Google client, authentication and signing dependencies own authentication, request serialization and response models. The application retains issuance policy and classifies native failures by available HTTP status. Authenticate before object creation; authentication failure must not imply an attempted object write. Authentication and Wallet requests use bounded timeouts, TLS verification, no redirects and no automatic retries. Provider-library logging must not emit raw requests or secret-bearing errors.

Require the deterministic Class identity and provider approval before individual issuance. Update only owned appearance when business names, logo, color or primary layout references differ; submit Class creation and appearance updates as `UNDER_REVIEW`, as required by Google. Google alone decides approval. Require the returned Class identity and approval again after each write before creating an Object. A successful update need not echo every nested visual field. Accept documented approval and barcode enum aliases, but compare Object identity, Class binding, manual code and barcode value exactly. Incomplete Object confirmation requires the same-identity read, never a second creation request. Redirects cannot establish success.

Initial issuance distinguishes confirmed provider success, definitive failure and unknown outcome. Do not publish success after an unknown provider or commit result, infer rollback, or blindly repeat creation. Reuse the current operation identifier where available; do not add a durable attempt table or persistent browser identity. A confirmed local rollback may leave the accepted provider orphan, with no cleanup/recovery guarantee. A model event that cancels the local save is a failed issuance, not a confirmed commit. The private HTTP handoff selects only the Save-to-Wallet URL and manual code; internal operation metadata must not enter the response.

It does not own Promotion eligibility, Visit acceptance, Reward completion or Redemption authorization. PostgreSQL remains domain truth. Synchronization reads the caller's tentative state and sends an absolute replacement of owned text modules to the same Object. Verify identity and credentials before writing, then confirm the returned content; one same-Object read may complete an incomplete write response. Preserve existing committed outcomes under the architecture's [synchronization boundary](../architecture/overview.md#existing-pass-synchronization-boundary). The owning caller logs the final operation outcome using classified provider failures, without credentials or raw provider errors. The integration itself does not log; its return confirms only the provider, not local commit or device display.

## Structured logging

### Production format

Configured application channels use one native Monolog JSON object per record at all eight PSR severities, with explicit safe callsite context. Production defaults to `stderr`; local development defaults to rotating files. Explicit deployment severity/channel settings remain authoritative. The [operational logging contract](operational-logging.md) defines the envelope and supported collection boundaries.

Logging uses native failure behavior without protective wrappers. A logging failure may change the HTTP result or interrupt reporting, but cannot undo an already committed domain fact or authorize an automatic provider retry. Last-resort native emergency output and pre-bootstrap diagnostics are outside the configured JSON guarantee. Logging is not a durable audit guarantee.

### Correlation

Request middleware alone generates and shares one UUID correlation identifier while its context is active. Individual log calls do not supply request identifiers. Standalone logs and reports after middleware cleanup may have no request correlation. Apply the [security logging contract](../architecture/security.md#structured-security-logging). Request correlation is internal logging metadata, not a response header.

Stable context includes the event, middleware-owned request correlation when in scope, relevant actor and domain identities, outcome and reason. Initial-issuance technical records use sanitized correlation/operation identifiers in application logs, not a separate database attempt registry. Include only relevant fields; source owns exact field names.

Use stable machine-readable names for acceptance, replay, rejection, unlock, redemption, cancellation, provider failure and throttling events. Their exact names belong to implementation.

### Secret hygiene

Never log passwords, session/cookie values, authorization headers, raw validation tokens, manual fallback codes, Google Wallet private keys, full Save-to-Wallet JWTs or sensitive provider payloads. The security owner retains the broader sensitive-field policy.

## Error handling

Expected domain rejections become clear UI states. Unexpected exceptions return a safe generic message without a request correlation header. Configured reports retain the actual exception class, source location and complete native caller chain with only file, line, class and function metadata. Native server-side paths and symbols remain unchanged; reports exclude raw exception messages, arguments, frame objects, SQL, bindings and causes. Production responses never expose stack traces or source paths. Apply the [security error contract](../architecture/security.md#error-handling), including enumeration protection.

## Security headers

The [security header baseline](../architecture/security.md#security-headers) governs deployment. A strict CSP requires evidence that it preserves Livewire/Alpine/Vite behavior; do not introduce an untested policy that breaks the application.

## Retired two-factor persistence

The retirement migration removes only existing two-factor secrets, recovery codes and confirmation storage. It also succeeds on fresh no-2FA schemas where all three are absent. Rollback is intentionally a no-op: deleted credential values cannot be recovered, and rollback must not recreate retired columns. Compatible starter behavior is preserved; two-factor authentication stays disabled and passkeys remain independently configured.

Future two-factor re-enablement requires explicit opt-in, a new forward credential migration, compatible setup/challenge UI and tests, and enrollment with new credentials. Rollback is not credential recovery.
