# ADR-002: Use one persistent Google Wallet pass per Customer and Business

## Status

Accepted. Partially superseded by [ADR-008](008-permanent-wallet-credential.md): keep one persistent Google Wallet Object and credential per Customer pass/Business, but replace variable Promotion content and the exclusion of a separate informational customer page. The page is not another scannable pass. Original decision and rationale below are retained.

## Context

FidelitoPass publishes repeated Challenges. Creating a new pass for every Challenge would clutter customer Wallets and weaken the long-lived loyalty relationship.

Customers also do not need a separate FidelitoPass profile/pass UI in MVP.

## Options Considered

1. One persistent Google Wallet pass per Customer and Business.
2. One Google Wallet pass per Challenge.
3. Google Wallet plus a parallel customer web pass.

## Decision

Use one persistent Customer pass mapped to one Google Wallet Loyalty Object for one Business.

Update the same Wallet Object as Challenges change.

Google Wallet is the only customer-facing loyalty pass in MVP. PostgreSQL remains authoritative.

## Rationale

The customer learns one object: the Business card. New Challenges become new reasons to reopen the same card.

## Consequences

- Permanent Business acquisition QR can remain useful.
- Pass state must support waiting/active/reward/redeemed transitions.
- Provider synchronization must update in place.
- Duplicate anonymous passes across different devices cannot be perfectly prevented without customer identity and are accepted for MVP.
