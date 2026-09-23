# FidelitoPass Quality Strategy

This document defines proportionate verification for the MVP.

Quality work follows product risk. It must protect the loyalty loop without turning every UI detail into a large test matrix.

## Quality priorities

| Priority | Main risk | Primary evidence |
|---|---|---|
| Challenge correctness | Wrong awarded points or progress damages trust. | Domain/feature tests. |
| Visit integrity | Retried/concurrent validation can duplicate points if idempotency fails. | PostgreSQL-backed transaction/concurrency tests. |
| Reward finality | Double redemption creates direct Business loss. | Transaction/idempotency tests. |
| Authorization | One Business could alter another Business's passes. | Policy/feature abuse tests. |
| Time correctness | UTC/local-day boundaries can change progress or expiry. | PostgreSQL timezone boundary tests. |
| Wallet boundary | Provider failure can leave customer presentation stale. | Integration/job failure tests. |
| Counter usability | Slow/unclear validation disrupts real service. | Focused browser and responsive evidence. |

## Test pyramid

Use the testing pyramid as a planning guide, not a percentage gate:

| Layer | Direction |
|---|---:|
| Unit/domain | 60–70% |
| Feature/integration | 20–30% |
| Browser | 5–10% |

Prefer the cheapest test that proves the behaviour.

Do not build a large browser suite for logic that can be proven deterministically in an evaluator or PostgreSQL-backed feature test.


## Honest coverage — risk-based 100/80/0 guidance

Ask which failure could materially harm the product. Classify code by risk instead of chasing one global percentage. These numbers guide test effort; they are not automatic per-file CI thresholds.

| Tier | Scope | Guidance |
|---|---|---|
| CORE | Point awarding, Challenge progress, Visit integrity, Reward unlock/redemption, authorization, time boundaries, idempotency/concurrency. | **100% of identified critical rules have direct automated evidence.** |
| IMPORTANT | Application Actions, Livewire workflows, Wallet mapping/integration boundaries, reusable project-owned services. | **80% line/function coverage is a diagnostic reference, not a release gate.** |
| INFRASTRUCTURE | Framework bootstrap, configuration, generated code, trivial migrations, vendor code. | **0% coverage quota; verify applicable behaviour by other means.** |

Rules:

- CORE 100% is rule-level evidence, not 100% of statements, branches, functions, and lines. Tests must assert real outcomes; do not mock away the critical calculation or security boundary under test.
- Where coverage instrumentation applies, use 80% for IMPORTANT code to spot untested flows. Below that reference, inspect meaningful behaviour gaps; do not block release on the number alone or add tests only to raise it.
- INFRASTRUCTURE 0% means no coverage quota, not no testing. Static analysis cannot prove runtime configuration, migrations, or integrations work; use integration, build, migration, or deployment checks where applicable.
- Do not use one global repository coverage percentage as the primary quality signal.

When a work item adds or changes project-owned CORE or IMPORTANT behaviour, run `./vendor/bin/sail pest --coverage` and inspect the affected files, not only the global total. For documentation, copy, or configuration changes, choose checks based on the actual risk instead. Every identified CORE rule still needs an outcome-asserting test; do not claim tier coverage without a measured report and that rule-level evidence.

## Quality metrics

These MVP metrics distinguish required evidence from diagnostic references and feedback budgets:

| Metric | Target |
|---|---:|
| Required automated test success | **100%** |
| Accepted flaky required tests | **0** |
| CORE critical rules with direct test evidence | **100% of identified rules** |
| IMPORTANT line/function coverage | **80% diagnostic reference; not a gate** |
| INFRASTRUCTURE coverage quota | **None (0% guidance)** |
| Static-analysis errors in project-owned code | **0** |
| Formatting drift after canonical formatter | **0** |
| Unresolved critical/high dependency vulnerabilities | **0, or an explicit reviewed exception** |
| Pre-commit feedback budget | **<= 90 seconds target** |
| Pre-push feedback budget | **<= 3 minutes target** |

The hook time values are feedback budgets, not correctness gates. If a local gate becomes consistently slower, move the expensive check later instead of encouraging bypass.

Core-flow baseline requires responsive layouts, keyboard-visible focus, semantic controls, and no status communicated by colour alone. Progressive WCAG AA goals include:

- Contrast of at least 4.5:1 for normal text and 3:1 for large text.
- Touch targets near or above 44x44px.

## Test design

- Structure focused tests with Arrange, Act, Assert.
- Test observable behaviour and domain outcomes, not private methods.
- Use real PostgreSQL when behaviour depends on transactions, constraints, `timestamptz`, `AT TIME ZONE`, or row locks.
- Fake Google Wallet at the provider boundary for deterministic automated tests.
- Keep one real-device Wallet verification checklist for release confidence.
- Fix or remove flaky required tests; do not normalize retry-until-green.
- Keep fixtures small and explicit.

## Core direct-test coverage

Every identified core rule must have direct automated evidence.

Core rules:

- Challenge target/Reward configuration ranges.
- Challenge active/scheduled/ended state from database time.
- Non-overlapping publication.
- Regular Visit point awarding.
- Optional special weekday/time point awarding.
- Legitimate repeat Visits on the same day.
- Idempotent/concurrent validation of one operation.
- Challenge progress from immutable awarded points.
- One Reward entitlement.
- One Redemption.
- Challenge expiry/cancellation.
- Cross-Business authorization.
- Wallet provider failure after commit.

This list defines the CORE tier of the risk-based 100/80/0 guidance. Each identified rule requires direct automated evidence.

## Feature/integration coverage

Use Laravel feature tests for:

- Authentication boundary.
- Business ownership.
- Challenge forms/publication.
- Public join flow.
- Pass lookup.
- Rate limiting.
- Structured log context when custom logic exists.
- Wallet job dispatch after commit.

Use database constraints directly in tests when the invariant should survive application bypass.

## Browser coverage

Keep browser coverage representative.

Required journeys:

1. Public landing -> Business registration entry.
2. Owner creates/publishes a Challenge.
3. Customer join page -> Add to Google Wallet handoff (provider boundary may be stubbed).
4. Business validation page with scanner path represented where practical.
5. Manual-code fallback directly below scanner.
6. Successful Visit -> progress result.
7. Reward available -> Redemption.
8. Responsive/theme smoke coverage.

Browser rules:

- Prefer semantic role/label/text selectors.
- Use test IDs only when a semantic selector is not stable.
- Rely on Playwright auto-wait.
- Never use fixed sleeps to hide timing problems.
- Retain trace/screenshot evidence on failure according to existing project tooling.

## Accessibility and usability checks

Required core-flow checks:

- Semantic HTML.
- Visible focus and keyboard-reachable controls.
- Associated form labels.
- Basic touch interaction.
- No meaning communicated by colour alone.
- No horizontal scrolling at common narrow viewports.
- Scanner error leaves manual fallback immediately accessible.

Progressive WCAG AA targets: contrast and controls usable at touch sizes near 44×44px. Automated accessibility checks cover only part of the problem. Perform a short manual keyboard/contrast review of the core flows.

## Static analysis and formatting

Keep the existing project tools as canonical:

- Laravel Pint for PHP formatting.
- Larastan/PHPStan for static analysis.
- Frontend format/lint tooling already configured in the repository.

Do not add a new quality platform solely to obtain another score.

Project-owned code targets zero unresolved static-analysis errors at the configured project level.

## Security checks

Keep lightweight security evidence in the delivery pipeline:

- Dependency vulnerability audit.
- Secret scanning.
- Production build.
- Authorization/rate-limit feature tests.
- Production configuration checks such as debug disabled.

Security tooling detects patterns; it does not replace review of Challenge/Visit/Reward business rules.

## Local and CI feedback

Local feedback should remain fast enough to run habitually.

Use:

- Fast formatting/static/focused checks before frequent local integration points.
- The complete PHP/test/static/build/security suite before shared integration.
- CI as independent required evidence.

If a local gate becomes consistently too slow, move the expensive check later rather than encouraging bypass.

## Logging verification

Structured logging is part of quality because it supports diagnosis.

Verify:

- Request IDs are present where expected.
- JSON production formatter is valid.
- Domain/security event names are stable.
- No raw validation token or credential appears in representative log output.

No external log-management service is required for MVP.

## Release confidence checklist

Before a production release:

- Complete required automated suite passes at 100%.
- No accepted flaky required test exists.
- Every identified CORE rule has direct automated evidence; IMPORTANT coverage below the 80% reference has been checked for meaningful gaps where instrumented. Infrastructure is verified through applicable integration, build, migration, or deployment checks.
- Static analysis reports zero unresolved project-owned errors.
- Dependency/secret scans pass; any critical/high dependency finding is resolved or has an explicit reviewed exception.
- Production build succeeds.
- Database migrations are tested.
- Challenge UTC/local boundary cases pass.
- Scan/manual validation works.
- Reward can be unlocked and redeemed once.
- Google Wallet can be issued and updated on a real supported device.
- Light/dark responsive core pages and basic keyboard/touch interaction are reviewed; full WCAG AA conformance is a progressive goal, not a release certification gate.
- Production debug is disabled.
- Structured production logs can be parsed as JSON.
