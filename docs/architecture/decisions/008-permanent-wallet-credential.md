# ADR-008: Permanent Wallet credential and live personal information

## Status

Accepted by the product owner. This records agreed intent, not implemented or deployed capability.

Partially supersedes [ADR-002](002-persistent-google-wallet-pass.md) only for variable Promotion content and its exclusion of an informational customer page. Keep one persistent Wallet Object and credential; the page is not another scannable pass.

Partially supersedes [ADR-007](007-synchronous-wallet-updates.md) only for Visit/Redemption Object updates and their provider-before-commit gate. Preserve local authorization, locks, PostgreSQL operation time, idempotency, points, Reward finality and tenant isolation. [ADR-006](006-google-first-initial-wallet-issuance.md) initial issuance and shared appearance provider contracts remain unchanged.

## Context

A Promotion can start, end or be cancelled without any customer or cashier interaction. Variable Wallet content therefore needs update triggers and can become stale. Shared Business terms and each customer's progress are different kinds of data. PostgreSQL already determines the current offer, awarded points and Reward facts.

The MVP deadline favors one request-bound application without a synchronization operation to maintain. It does not require offline progress, proactive notifications, customer accounts or compatibility with previously issued development cards.

## Options Considered

| Option | Benefit | Cost and limit |
|---|---|---|
| Periodic per-Object sweep | Keeps variable personal content in Wallet and can cover time-driven transitions without a customer visit. | Requires scheduling, enumerating passes, bounded provider work and failure/retry operations. Device display can still lag. |
| Event/shared Class updates plus on-demand personal refresh | Shared terms can propagate through a Class; event updates and personal reads can refresh individual progress. | Still needs separate shared/personal update paths and read-versus-refresh UX. Per-customer progress cannot be made shared merely by placing Promotion terms on a Class. |
| Permanent credential plus live personal page | Removes Promotion and commercial synchronization work; the customer reads authoritative facts on demand. | Progress is not available offline in Wallet; the page needs internet and an available application. Customers who never open it receive no proactive news. |

Google supports both [Class/Object updates](https://developers.google.com/wallet/retail/loyalty-cards/use-cases/updates). [Class changes propagate to referencing Objects and appear on the device at its next sync](https://developers.google.com/wallet/retail/loyalty-cards/overview/how-classes-objects-work). The alternative is therefore possible, not blocked by a claim that shared changes cannot propagate. The [API FAQ](https://developers.google.com/wallet/retail/loyalty-cards/resources/faq) documents a 20-request-per-second limit and no official SLA. Those constraints require operational design for a sweep; they do not justify speculative volume or completion-time estimates.

## Decision

Choose the permanent credential and live personal page.

- Wallet contains Business branding, the private QR, manual code and a distinct private informational link. It contains no variable Promotion, progress, Reward, redemption or deadline data.
- The signed personal page reads current PostgreSQL offer, pass-specific progress, relevant redeemable/redeemed facts and next step. Use native signing and minimum pass/Business-scoped read authorization, without nonce/revocation systems, custom auth sessions, customer accounts or extra PII. It is not another scannable pass.
- The effectively Active Promotion applies automatically. There is no enrollment action and no precreation of one participation row per pass. A successor uses its own facts, without point carryover, revived Rewards or grace periods.
- GET is informational: no commercial writes, Google request, initial sync, spinner, auxiliary refresh or customer Visit/Redemption action. No jobs, cron, bulk sweep, synchronization registry or automatic provider retry is introduced.
- New Visits and Redemptions commit through their existing local transaction discipline without Google calls. Combined Visit/unlock/redemption remains atomic; authorized replay creates no new effects.
- Keep Google-first initial issuance and provider-backed shared appearance changes. Preserve the permanent Object, Class binding and QR/manual credentials. Their provider confirmation does not promise immediate device display.
- Proactive notifications are outside MVP. The customer must open the informational link to discover current facts. Final page composition and sample copy require owner review.
- Apply a fresh development rollout only. Add no compatibility, migration, repair or replacement workflow for issued cards. This decision authorizes no database reset, provider mutation or production deployment.

## Rationale

This removes the cause of Promotion-content drift instead of adding more triggers to repair it. The counter needs PostgreSQL and existing credentials, not an additional Google confirmation to accept an otherwise valid Visit or Redemption. One informational page can explain current facts without copying commercial authority into Wallet or adding another action flow.

Periodic and event-driven synchronization remain credible alternatives when offline variable content or proactive engagement becomes a product requirement. They are not necessary to the approved MVP deadline and operational scope.

## Consequences

- Customers retain one familiar identification card across Promotions, but must open the page for progress, Reward and validity information. Wallet alone does not explain the current offer.
- The page requires internet and server availability. Failed reads must not fabricate progress or eligibility. The retained QR/manual credential does not itself guarantee an online counter operation will succeed.
- A signed link is a sensitive bearer credential, with no targeted revocation lifecycle in MVP. Scope checks and private-response/logging hygiene still apply.
- Google unavailability can prevent issuance or appearance changes, but must not be contacted by or gate Visit/Redemption commits or personal reads.
- Stored history remains intact, but the page is not a history dashboard. The current active offer takes precedence; earlier facts appear only when useful to explain relevant personal state.
- Existing source and tests must be reconciled separately. Acceptance of this decision is not implementation, rollout or runtime verification evidence.
