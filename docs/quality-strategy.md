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


## Strategic coverage model — 100/80/0

Coverage is applied by risk tier instead of chasing one global percentage.

| Tier | Scope | Target |
|---|---|---:|
| CORE | Point awarding, Challenge progress, Visit integrity, Reward unlock/redemption, authorization, time boundaries, idempotency/concurrency. | **100% of identified rules/functions directly covered** |
| IMPORTANT | Application Actions, Livewire workflows, Wallet mapping/integration boundaries, reusable project-owned services. | **80%+ line/function coverage** |
| INFRASTRUCTURE | Framework bootstrap, configuration, generated code, trivial migrations, vendor code. | **0% coverage target** |

Rules:

- CORE 100% means every identified business/security rule has direct automated evidence; it is not permission to write meaningless tests only to increase a number.
- IMPORTANT code should maintain at least 80% line/function coverage where coverage instrumentation applies.
- INFRASTRUCTURE has no percentage target because framework/configuration correctness is better proven by integration, build, migration, or deployment checks.
- Do not use one global repository coverage percentage as the primary quality signal.

## Quality metrics

These are the deterministic quality targets for the MVP:

| Metric | Target |
|---|---:|
| Required automated test success | **100%** |
| Accepted flaky required tests | **0** |
| CORE rule/function coverage | **100%** |
| IMPORTANT line/function coverage | **>= 80%** |
| INFRASTRUCTURE coverage target | **0%** |
| Static-analysis errors in project-owned code | **0** |
| Formatting drift after canonical formatter | **0** |
| Unresolved critical/high dependency vulnerabilities | **0, or an explicit reviewed exception** |
| Pre-commit feedback budget | **<= 90 seconds target** |
| Pre-push feedback budget | **<= 3 minutes target** |

The hook time values are feedback budgets, not correctness gates. If a local gate becomes consistently slower, move the expensive check later instead of encouraging bypass.

Accessibility targets for core flows:

- WCAG AA contrast: at least 4.5:1 for normal text and 3:1 for large text.
- Touch targets near or above 44x44px.
- Keyboard-visible focus and semantic controls.
- No status communicated by colour alone.

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

This list defines the CORE tier for the 100/80/0 strategy. Each identified rule requires direct automated evidence.

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

For core flows:

- Semantic HTML.
- Visible focus.
- Associated form labels.
- Contrast targeting WCAG AA.
- Controls usable at touch sizes near 44×44px.
- No meaning communicated by colour alone.
- No horizontal scrolling at common narrow viewports.
- Scanner error leaves manual fallback immediately accessible.

Automated accessibility checks cover only part of the problem. Perform a short manual keyboard/contrast review of the core flows.

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
- 100/80/0 Coverage targets are satisfied for instrumented project-owned code.
- Static analysis reports zero unresolved project-owned errors.
- Dependency/secret scans pass; any critical/high dependency finding is resolved or has an explicit reviewed exception.
- Production build succeeds.
- Database migrations are tested.
- Challenge UTC/local boundary cases pass.
- Scan/manual validation works.
- Reward can be unlocked and redeemed once.
- Google Wallet can be issued and updated on a real supported device.
- Light/dark responsive core pages are reviewed.
- Production debug is disabled.
- Structured production logs can be parsed as JSON.
