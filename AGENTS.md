# FidelitoPass project contract

Apply these project obligations alongside the active agent harness. This file supplies repository context; it is not an enforcement mechanism or a replacement for runtime authorization.

## Stack and execution

FidelitoPass uses Laravel, Livewire, Flux UI, Tailwind CSS and PostgreSQL, with Laravel Sail for application development. Read dependency constraints in `composer.json` and `package.json`, and resolved versions in `composer.lock` and `package-lock.json`. Verify installed source/configuration when API behaviour matters; lockfiles alone do not prove the running environment.

Run application-development commands through Sail from the repository root:

| Tool | Command form |
| --- | --- |
| PHP | `./vendor/bin/sail php <arguments>` |
| Composer | `./vendor/bin/sail composer <arguments>` |
| Artisan | `./vendor/bin/sail artisan <command> <arguments>` |
| npm | `./vendor/bin/sail npm <command> <arguments>` |

Do not substitute host PHP, Composer, Artisan or npm when Sail is unavailable; report the blocked command. Preserve existing native Docker/browser runners such as `scripts/quality/browser/run-playwright.sh`, which owns its browser container. Inspect their execution boundary before use; do not blindly wrap them in Sail or create nested containers. Command forms do not authorize installation, migrations, server startup or broader checks.

## Intent routing

Check only the skill matching the request. Paths below are exact repository-relative paths, not a global skill inventory.

| User intent | Skill to check |
| --- | --- |
| Implement project Laravel, PHP, Livewire, database, Wallet or UI behaviour | `skills/fidelitopass-laravel-implementation/SKILL.md` |
| Integrate a page, layout or navigation into the Livewire starter | `skills/laravel-livewire-starter-integration/SKILL.md` |
| Select or scope delivery work, change board state, or establish completion | `skills/fidelitopass-delivery-planning/SKILL.md` |

Skills route decisions without becoming independent domain specifications. Select documentation through the routing table below; `docs/README.md` explains the design order and each owner's role.

## Documentation routing

Paths below are repository-relative. Before implementation, select and read the owner sections applicable to the request; cross-cutting work may require multiple sources. Do not load the full set or follow every reference automatically. For documentation changes, read `docs/documentation-standard.md` first. Consult the root `README.md` only when the request concerns the human-facing public introduction.

| Need | Read |
| --- | --- |
| Understand the product or a domain term | `docs/conceptual-design.md` |
| Decide whether something belongs in MVP | `docs/product-scope.md` |
| Configure/evaluate Promotion-owned x2/x3/x5 points or progress | relevant section of `docs/promotion-model.md` |
| Confirm user-visible behaviour | relevant requirement in `docs/requirements.md` |
| Map a customer state to Google Wallet | relevant section of `docs/wallet-presentation.md` |
| Design a page or interaction | relevant section of `docs/ui-ux-guidelines.md` |
| Change system boundaries or integration ownership | relevant section of `docs/architecture/overview.md` |
| Change authentication, authorization, tokens, rate limits, or security logging | relevant section of `docs/architecture/security.md` |
| Add or alter persistent data | relevant section of `docs/development/database-standard.md` |
| Implement Laravel, Livewire, Actions, jobs, or integrations | relevant section of `docs/development/laravel-application-standard.md` |
| Decide what verification is sufficient | relevant section of `docs/quality-strategy.md` |
| Select or load delivery work | relevant section of `docs/delivery-plan.md` |
| Interpret the project delivery flow | relevant section of `docs/development/workflow.md` |
| Revisit a costly cross-cutting decision | only the relevant ADR in `docs/architecture/decisions/`; for a qualifying new ADR, use the ADR policy in `docs/documentation-standard.md` and `docs/architecture/decisions/template.md` |
| Write or maintain documentation | `docs/documentation-standard.md` |
| Write or revise the human-facing public introduction | root `README.md` |

This table routes knowledge; it does not redefine domain rules or require reading every ADR.

## Intent, source and readability

- Owner-approved requirements and the relevant domain/presentation owners define intended behaviour. Accepted ADRs retain decision authority. Source, migrations, installed dependencies and observed checks establish what is implemented and which APIs exist; neither plans nor documentation prove rollout.
- Global domain and visual rules apply to new scoped work; they do not expand the authorized rollout or establish application-wide compliance. Preserve unrelated screens and historical evidence.
- Reconcile apparent contradictions against the relevant owner and current evidence. Correct a clear scoped implementation mismatch without inventing a product decision. If intent is genuinely unresolved or reconciliation needs an unauthorized policy change, stop and ask with the exact conflict. Never rewrite policy to justify incidental code.
- Treat readable source as a separate acceptance concern from passing behaviour tests. Apply the relevant source-style, PHPDoc/comment and component-local JavaScript sections of `docs/development/laravel-application-standard.md`, routed by the implementation skill. Preserve lowercase English internal comments, useful three-line JavaScript responsibility groups, Livewire listeners last, owned lifecycle cleanup and server authority; avoid empty sections or reports per helper.
- Keep technical artifacts in English and visible UI copy in translated professional Spanish. Documentation changes follow their owner's standard rather than narrating implementation sessions.

## Delegated context and evidence

The parent selects applicable owner documents and sections from the request and the routing table, including multiple sources when needed. It passes those selections alongside exact skill paths under `## Skills to load before work`, scope, exclusions and acceptance criteria.

Workers read every injected skill before work and actually consult the selected documentation sections before implementation; reading skills alone is not sufficient. Identify the governing constraints and any unresolved conflicts before the affected edit. If a necessary source is missing or unreadable, or material intent remains unresolved, stop before that edit and report the required source or exact conflict rather than guessing. A worker may identify another necessary owner from the table or consulted sections; report the additional load needed without automatically traversing all references or widening product or edit scope. Do not ask the owner to repeat resolved decisions.

Return concise actual paths/headings, relevant constraints, output, observed checks and unresolved limits, with actual `skill_resolution`; do not quote full documents or produce a report per file/helper. These obligations apply to inline and delegated implementation without adding a separate ceremony to every tiny edit, and remain applicable regardless of harness initialization.

## Verification

Choose checks for the authorized change from the existing scripts and `docs/quality-strategy.md`; do not claim unrun checks. Documentation validation uses:

```sh
./vendor/bin/sail npm run test:documentation-validator
./vendor/bin/sail npm run check:documentation
git diff --check
```

The documentation validator covers root README, docs and skills, not this file or Markdown anchors. Manually verify all paths and heading targets in this file and changed skills, including local references resolved relative to each skill. Report failed, skipped and pending checks separately from verified results.
