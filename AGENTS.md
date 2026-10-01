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

For documentation work, load `docs/documentation-standard.md`. Use `docs/README.md` as the sole canonical global documentation map; select the relevant knowledge owner and section, not every document or linked reference. Skills route decisions without becoming independent domain specifications.

## Intent, source and readability

- Owner-approved requirements and the relevant domain/presentation owners define intended behaviour. Accepted ADRs retain decision authority. Source, migrations, installed dependencies and observed checks establish what is implemented and which APIs exist; neither plans nor documentation prove rollout.
- Global domain and visual rules apply to new scoped work; they do not expand the authorized rollout or establish application-wide compliance. Preserve unrelated screens and historical evidence.
- Reconcile apparent contradictions against the relevant owner and current evidence. Correct a clear scoped implementation mismatch without inventing a product decision. If intent is genuinely unresolved or reconciliation needs an unauthorized policy change, stop and ask with the exact conflict. Never rewrite policy to justify incidental code.
- Treat readable source as a separate acceptance concern from passing behaviour tests. Apply the relevant source-style, PHPDoc/comment and component-local JavaScript sections of `docs/development/laravel-application-standard.md`, routed by the implementation skill. Preserve lowercase English internal comments, useful three-line JavaScript responsibility groups, Livewire listeners last, owned lifecycle cleanup and server authority; avoid empty sections or reports per helper.
- Keep technical artifacts in English and visible UI copy in translated professional Spanish. Documentation changes follow their owner's standard rather than narrating implementation sessions.

## Delegated context and evidence

The parent passes exact skill paths under `## Skills to load before work`, plus selected sources/sections, scope, exclusions and acceptance criteria. Workers read every injected path before work; context is not assumed inherited. Return actual `skill_resolution` and loading evidence, including unreadable paths, selected sources, observed checks and unresolved limits. These repository obligations remain applicable regardless of harness initialization.

## Verification

Choose checks for the authorized change from the existing scripts and `docs/quality-strategy.md`; do not claim unrun checks. Documentation validation uses:

```sh
./vendor/bin/sail npm run test:documentation-validator
./vendor/bin/sail npm run check:documentation
git diff --check
```

The documentation validator covers root README, docs and skills, not this file or Markdown anchors. Manually verify all paths and heading targets in this file and changed skills, including local references resolved relative to each skill. Report failed, skipped and pending checks separately from verified results.
