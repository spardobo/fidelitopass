# ADR-001: Use a conventional Laravel monolith with focused Actions

## Status

Accepted.

## Context

FidelitoPass has one small product domain, one team, one transactional database, and one external customer-pass provider. The MVP does not require independently deployable services.

## Options Considered

1. Conventional Laravel monolith with Eloquent and focused Actions.
2. Formal layered/hexagonal architecture with mandatory ports/repositories.
3. Microservices split by loyalty capabilities.

## Decision

Use one Laravel application and one PostgreSQL database.

Use Eloquent and framework conventions by default. Use focused Actions for consequential transactions. Keep the single points-based Challenge calculation direct instead of introducing a Strategy hierarchy.

## Rationale

This keeps product logic explicit while minimizing custom architecture and context. The main risks are transactional integrity, timezone rules, and provider synchronization, not service-scale boundaries.

## Consequences

- Fewer project-specific abstractions.
- Faster onboarding and implementation.
- Critical commands still have named transaction boundaries.
- Future extraction remains possible if measured scale or team boundaries justify it.
