# ADR-005: Keep the interface dark-only

## Status

Accepted.

## Context

The shipped public, authentication, and application experience uses one dark presentation. A durable policy is needed so future work does not reintroduce light mode or a theme switch. REQ-UX-001 owns the detailed visual and acceptance criteria.

## Options Considered

1. Keep one dark-only interface and ignore saved or system light preferences.
2. Offer selectable light and dark themes, including a system-preference option.

## Decision

Keep the public, authentication, and application interface dark-only. Serve Onest locally and use the lavender accent specified by REQ-UX-001. Apply dark styling before content appears even when a saved preference or the system requests light. Do not offer a light-theme switch.

## Rationale

This records the previously accepted and shipped product experience. One theme preserves a consistent presentation across entry and operational flows and avoids maintaining a second visual system. Respecting a saved or system light preference would contradict the accepted dark-only contract.

## Consequences

- Public, authentication, and application pages must remain dark without a light-theme flash, including when a saved or system light preference exists.
- There is no light-mode choice; users who prefer light presentation cannot select it.
- Responsive and accessibility behavior still require verification under REQ-UX-001; this decision does not define individual colors, layouts, or components.
