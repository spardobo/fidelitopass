# FidelitoPass Requirements

This document defines observable MVP behaviour.

## Summary

- Total requirements: **35**.
- Must: **34**.
- Should: **1**.
- Functional: **24**.
- Quality/technical: **11**.

## Requirement register

| # | ID | Priority | Module | Type | Description |
|---:|---|---|---|---|---|
| 1 | REQ-BIZ-001 | Must | Business | Functional | Authenticate a Business owner. |
| 2 | REQ-BIZ-002 | Must | Business | Functional | Configure Business profile and IANA timezone. |
| 3 | REQ-PUB-001 | Must | Public | Functional | Present a product landing page. |
| 4 | REQ-PUB-002 | Must | Public | Functional | Present the Business join page from the permanent QR. |
| 5 | REQ-CHL-001 | Must | Challenge | Functional | Create one points-based Challenge. |
| 6 | REQ-CHL-002 | Must | Challenge | Functional | Configure deterministic point earning. |
| 7 | REQ-CHL-003 | Must | Challenge | Functional | Publish local dates as an immutable UTC window. |
| 8 | REQ-CHL-004 | Must | Challenge | Functional | Reject overlapping published Challenge windows. |
| 9 | REQ-CHL-005 | Must | Challenge | Functional | Lock active Challenge terms and support cancellation. |
| 10 | REQ-PAS-001 | Must | Pass | Functional | Provide one permanent acquisition QR per Business. |
| 11 | REQ-PAS-002 | Must | Pass | Functional | Create an anonymous Customer pass without registration. |
| 12 | REQ-PAS-003 | Must | Wallet | Functional | Reuse one persistent Google Wallet pass across Challenges. |
| 13 | REQ-VIS-001 | Must | Validation | Functional | Identify a Customer pass by scanner or manual code. |
| 14 | REQ-VIS-002 | Must | Validation | Functional | Restrict validation and redemption to the owning Business. |
| 15 | REQ-VIS-003 | Must | Visit | Quality/technical | Timestamp accepted Visits from PostgreSQL time. |
| 16 | REQ-VIS-004 | Must | Visit | Functional | Accept legitimate repeat Visits while remaining retry-safe. |
| 17 | REQ-EVL-001 | Must | Evaluation | Functional | Award points for every accepted Visit. |
| 18 | REQ-EVL-002 | Must | Evaluation | Functional | Apply the optional special point rule. |
| 19 | REQ-EVL-003 | Must | Evaluation | Functional | Evaluate Challenge completion from earned points. |
| 20 | REQ-EVL-004 | Must | Evaluation | Quality/technical | Preserve awarded points as immutable Visit outcomes. |
| 21 | REQ-REW-001 | Must | Reward | Functional | Unlock one Reward entitlement on completion. |
| 22 | REQ-REW-002 | Must | Reward | Functional | Redeem one available Reward exactly once. |
| 23 | REQ-REW-003 | Must | Reward | Functional | Reject Reward use after Challenge expiry or cancellation. |
| 24 | REQ-WAL-001 | Must | Wallet | Functional | Present deterministic Wallet content for every customer state. |
| 25 | REQ-WAL-002 | Must | Wallet | Quality/technical | Synchronize Wallet after commit and recover safely from provider failure. |
| 26 | REQ-UX-001 | Must | UX | Quality/technical | Use a warm light theme by default with optional dark mode. |
| 27 | REQ-UX-002 | Must | UX | Functional | Keep the validation workflow fast and scanner-first. |
| 28 | REQ-UX-003 | Should | UX | Quality/technical | Meet core responsive and WCAG AA interaction expectations. |
| 29 | REQ-UX-004 | Must | UX | Functional | Present explicit success, point-award, reward, expiry, and error states. |
| 30 | REQ-SEC-001 | Must | Security | Quality/technical | Separate public acquisition identity from private validation authority. |
| 31 | REQ-SEC-002 | Must | Security | Quality/technical | Enforce server-side authorization, validation, and abuse controls. |
| 32 | REQ-SEC-003 | Must | Security | Quality/technical | Produce structured security-relevant logs without secrets. |
| 33 | REQ-DAT-001 | Must | Data | Quality/technical | Enforce critical integrity in PostgreSQL. |
| 34 | REQ-REL-001 | Must | Reliability | Quality/technical | Preserve committed domain facts when external synchronization fails. |
| 35 | REQ-QLT-001 | Must | Quality | Quality/technical | Verify critical rules and complete journeys proportionately. |

## Detailed requirements

### Business

#### REQ-BIZ-001 — Authenticate a Business owner

**Priority:** Must
**Type:** Functional
**Module:** Business

As a Business owner, I want a protected account so that only I can configure my loyalty Challenges and validate customer activity.

**Acceptance Criteria**

**Scenario: Valid owner session**

- **Given** a registered Business owner.
- **When** valid credentials are submitted.
- **Then** the owner can access protected Business pages.

**Scenario: Anonymous protected access**

- **Given** no authenticated Business session.
- **When** a protected Business route is requested.
- **Then** authentication is required.

**Verification:** Laravel authentication and route-boundary feature tests.

#### REQ-BIZ-002 — Configure Business profile and timezone

**Priority:** Must
**Type:** Functional
**Module:** Business

As a Business owner, I want FidelitoPass to know my local timezone so that Challenge dates and special point windows match the calendar my customers understand.

**Acceptance Criteria**

**Scenario: Save valid profile**

- **Given** an authenticated owner.
- **When** a valid Business name and IANA timezone are saved.
- **Then** the profile becomes the source for public branding and future Challenge publication.

**Scenario: Change timezone**

- **Given** existing historical Challenges.
- **When** the Business timezone changes.
- **Then** previously published Challenges keep their stored timezone and only future publication uses the new Business timezone.

**Verification:** Feature and persistence tests.

### Public product

#### REQ-PUB-001 — Product landing page

**Priority:** Must
**Type:** Functional
**Module:** Public

As a Business owner discovering FidelitoPass, I want to understand the product in a few moments so that I can decide whether to create my first Challenge.

**Acceptance Criteria**

**Scenario: Understand the product without authentication**

- **Given** an anonymous visitor.
- **When** the landing page is opened.
- **Then** the page explains what FidelitoPass does, how the customer flow works, the points-based Challenge flow, and how to start as a Business.

**Verification:** Public-page feature test and browser review.

#### REQ-PUB-002 — Business join page

**Priority:** Must
**Type:** Functional
**Module:** Public

As a customer in a Business, I want a clear joining page so that I can understand the current Challenge before saving the pass.

**Acceptance Criteria**

**Scenario: Active Challenge**

- **Given** a Business with an Active Challenge.
- **When** its acquisition QR page is opened.
- **Then** the customer sees the generated Challenge description, Reward, validity, and Add to Google Wallet action.

**Scenario: No Active Challenge**

- **Given** a Business with no Active Challenge.
- **When** its acquisition QR page is opened.
- **Then** the customer can still save the persistent Business pass and sees a waiting-for-next-Challenge message.

**Verification:** Public Livewire/feature tests.

### Challenge

#### REQ-CHL-001 — Create one points-based Challenge

**Priority:** Must
**Type:** Functional
**Module:** Challenge

As a Business owner, I want to create a simple points target so that customers always understand what they need to achieve for the Reward.

**Acceptance Criteria**

**Scenario: Create a Challenge**

- **Given** the Challenge creation page.
- **When** the owner provides the start date, end date, target points, and Reward.
- **Then** FidelitoPass presents one deterministic Challenge preview using points as the progress unit.

**Verification:** Livewire component tests.

#### REQ-CHL-002 — Configure deterministic point earning

**Priority:** Must
**Type:** Functional
**Module:** Challenge

As a Business owner, I want a small point configuration so that I can make selected visits more valuable without designing custom rules.

**Acceptance Criteria**

**Scenario: Regular Visit value**

- **Given** the Business loyalty configuration.
- **When** the owner defines the regular Visit point value.
- **Then** accepted Visits outside a special rule use that value.

**Scenario: Optional special rule**

- **Given** the Business loyalty configuration.
- **When** the owner configures one weekday and either the full day or one time range with a special point value.
- **Then** only Visits accepted in that Business-local window use the special value.

**Scenario: Unsupported complexity**

- **Given** the Business loyalty configuration.
- **When** the owner attempts to combine several special rules or arbitrary conditions.
- **Then** the application does not offer that configuration in MVP.

**Verification:** Livewire/validation tests plus local-time boundary tests.

#### REQ-CHL-003 — Publish local dates as an immutable UTC window

**Priority:** Must
**Type:** Functional
**Module:** Challenge

As a Business owner, I want Challenge dates to behave according to my local calendar so that customers are never affected by application-server timezone differences.

**Acceptance Criteria**

**Scenario: Publish local date window**

- **Given** valid local start and end dates and a Business timezone.
- **When** the Challenge is published.
- **Then** `starts_at`, exclusive `ends_at`, and the Challenge timezone snapshot are persisted consistently.

**Scenario: Evaluate current phase**

- **Given** a published Challenge.
- **When** its phase is queried.
- **Then** phase decisions use an explicitly current PostgreSQL wall-clock instant against the stored UTC window, not transaction-start `CURRENT_TIMESTAMP`.

**Verification:** PostgreSQL-backed timezone tests including a UTC/local-date boundary.

#### REQ-CHL-004 — Reject overlapping published windows

**Priority:** Must
**Type:** Functional
**Module:** Challenge

As a Business owner, I want only one Challenge to be active at a time so that customers always have one clear current goal.

**Acceptance Criteria**

**Scenario: Overlapping window**

- **Given** an existing published Challenge window.
- **When** another overlapping draft is published.
- **Then** publication is rejected without partial state change.

**Verification:** Transactional feature tests.

#### REQ-CHL-005 — Lock active Challenge terms and support cancellation

**Priority:** Must
**Type:** Functional
**Module:** Challenge

As a customer, I want the Challenge rules to stay stable after play begins so that my progress cannot be redefined unexpectedly.

**Acceptance Criteria**

**Scenario: Edit active semantics**

- **Given** an Active Challenge.
- **When** the owner attempts to change its target points, Reward, timezone, or window.
- **Then** the operation is rejected.

**Scenario: Cancel active Challenge**

- **Given** an Active Challenge.
- **When** the owner confirms cancellation.
- **Then** new progress and Reward redemption stop immediately and Wallet state becomes cancelled/waiting.

**Verification:** Feature tests for transition and authorization.

### Customer pass and Wallet identity

#### REQ-PAS-001 — Permanent acquisition QR

**Priority:** Must
**Type:** Functional
**Module:** Pass

As a Business owner, I want to print one QR once so that I do not replace physical material for every Challenge.

**Acceptance Criteria**

**Scenario: Reuse public QR**

- **Given** a Business with an existing acquisition QR.
- **When** one Challenge ends and another begins.
- **Then** the same QR opens the Business join page with the new current state.

**Verification:** Route and persistence tests.

#### REQ-PAS-002 — Anonymous Customer pass

**Priority:** Must
**Type:** Functional
**Module:** Pass

As a customer, I want to save the loyalty pass immediately so that registration does not interrupt my visit.

**Acceptance Criteria**

**Scenario: Join without personal profile**

- **Given** the public Business join page.
- **When** the customer starts the Wallet flow.
- **Then** FidelitoPass creates a Customer pass without customer contact fields.

**Verification:** Persistence and public-flow tests.

#### REQ-PAS-003 — Persistent Wallet pass

**Priority:** Must
**Type:** Functional
**Module:** Wallet

As a customer, I want one card that changes with the Business Challenges so that my Wallet does not fill with expired cards.

**Acceptance Criteria**

**Scenario: New Challenge on existing pass**

- **Given** an existing Customer pass from a previous Challenge.
- **When** a new Challenge becomes relevant.
- **Then** FidelitoPass updates the same Wallet object instead of issuing another pass.

**Verification:** Wallet integration state-transition tests.

### Visit validation

#### REQ-VIS-001 — Scanner with immediate manual fallback

**Priority:** Must
**Type:** Functional
**Module:** Validation

As a Business owner serving a customer, I want scan and manual lookup in the same place so that camera problems do not slow the counter.

**Acceptance Criteria**

**Scenario: Scanner available**

- **Given** camera permission and a readable Wallet barcode.
- **When** the code is scanned.
- **Then** the Customer pass is loaded for confirmation.

**Scenario: Scanner unavailable**

- **Given** denied/unavailable camera access.
- **When** the page is shown.
- **Then** the manual-code field remains immediately below the scanner area and can load the same Customer pass flow.

**Verification:** Feature tests plus representative browser coverage for scanner fallback layout.

#### REQ-VIS-002 — Restrict operations to the owning Business

**Priority:** Must
**Type:** Functional
**Module:** Validation

As a Business owner, I want operations scoped to my Business so that another Business cannot alter my customers' progress.

**Acceptance Criteria**

**Scenario: Cross-Business pass**

- **Given** an authenticated owner for Business A.
- **When** a pass belonging to Business B is submitted.
- **Then** the operation is denied without exposing private state.

**Verification:** Authorization feature tests.

#### REQ-VIS-003 — Timestamp Visits from PostgreSQL time

**Priority:** Must
**Type:** Quality/technical
**Module:** Visit

As a system operator, I want one authoritative clock for Visit facts so that challenge evaluation is consistent across environments.

**Acceptance Criteria**

**Scenario: Create accepted Visit**

- **Given** a valid Visit confirmation.
- **When** the Visit is inserted.
- **Then** relevant rows are locked before PostgreSQL `clock_timestamp()` is captured exactly once as `operation_at`; `visited_at` uses that instant, and no duplicated local-date Visit field is persisted.

**Verification:** PostgreSQL-backed persistence test.

#### REQ-VIS-004 — Accept legitimate repeat Visits retry-safely

**Priority:** Must
**Type:** Functional
**Module:** Visit

As a Business owner, I want genuine repeat customer visits to count while technical retries remain harmless so that loyalty reflects real activity without duplicate processing.

**Acceptance Criteria**

**Scenario: Legitimate second Visit**

- **Given** a Customer pass with an earlier accepted Visit on the same Business-local day.
- **When** the Business intentionally validates a new customer Visit.
- **Then** a new Visit may be accepted and awarded points.

**Scenario: Retry the same validation operation**

- **Given** an already accepted validation operation.
- **When** the same idempotency key is submitted again.
- **Then** no duplicate Visit or points are created and the prior accepted result is returned.

**Verification:** Transaction/idempotency tests including same-day repeat Visits.

#### REQ-EVL-001 — Award points for every accepted Visit

**Priority:** Must
**Type:** Functional
**Module:** Evaluation

As a customer, I want every accepted Visit to tell me how many points I earned so that progress always feels immediate and understandable.

**Acceptance Criteria**

**Scenario: Regular Visit**

- **Given** an Active Challenge and no applicable special point rule.
- **When** a Visit is accepted.
- **Then** the Visit stores the regular awarded point value and Challenge progress increases by that amount.

**Verification:** Domain/feature tests.

#### REQ-EVL-002 — Apply the optional special point rule

**Priority:** Must
**Type:** Functional
**Module:** Evaluation

As a customer, I want FidelitoPass to show when my Visit is worth more points so that returning at that moment feels more rewarding.

**Acceptance Criteria**

**Scenario: Special window applies**

- **Given** one configured special point rule.
- **When** the Visit is accepted during its Business-local weekday and time window.
- **Then** the Visit stores the special awarded point value.

**Scenario: Special window does not apply**

- **Given** one configured special point rule.
- **When** the Visit is accepted outside its Business-local window.
- **Then** the Visit stores the regular awarded point value.

**Verification:** PostgreSQL-backed local-time boundary tests.

#### REQ-EVL-003 — Evaluate Challenge completion from earned points

**Priority:** Must
**Type:** Functional
**Module:** Evaluation

As a customer, I want one points target for the current Challenge so that I always know how close I am to the Reward.

**Acceptance Criteria**

**Scenario: Reach target points**

- **Given** an Active Challenge with target `N` points.
- **When** accepted Visits bring the Customer pass progress to at least `N` points.
- **Then** the Challenge is completed and one Reward entitlement becomes available.

**Verification:** Domain/feature tests.

#### REQ-EVL-004 — Preserve awarded points as immutable Visit outcomes

**Priority:** Must
**Type:** Quality/technical
**Module:** Evaluation

As a system operator, I want each accepted Visit to preserve the points awarded at that moment so that later configuration changes never rewrite historical Challenge progress.

**Acceptance Criteria**

**Scenario: Point configuration changes later**

- **Given** an accepted Visit with a stored awarded point value.
- **When** the Business changes its point configuration afterward.
- **Then** the historical Visit keeps its original awarded points and completed progress is not recalculated using the new configuration.

**Verification:** Persistence/domain tests.

#### REQ-REW-001 — Unlock one Reward entitlement

**Priority:** Must
**Type:** Functional
**Module:** Reward

As a customer, I want the Reward to unlock immediately when I complete the Challenge so that the accomplishment feels clear.

**Acceptance Criteria**

**Scenario: First completion**

- **Given** progress immediately below completion.
- **When** the completing Visit commits.
- **Then** one entitlement is created and Wallet state becomes Reward available.

**Verification:** Transaction and uniqueness tests.

#### REQ-REW-002 — Redeem exactly once

**Priority:** Must
**Type:** Functional
**Module:** Reward

As a Business owner, I want one explicit final redemption action so that the Reward cannot be used twice.

**Acceptance Criteria**

**Scenario: Successful redemption**

- **Given** an available entitlement before `ends_at`.
- **When** the owner confirms **Redeem reward**.
- **Then** `redeemed_at` and the redeeming owner are recorded once.

**Scenario: Repeated redemption**

- **Given** an already redeemed entitlement.
- **When** redemption is submitted again.
- **Then** no second redemption occurs and the final redeemed state is returned.

**Verification:** Transaction/idempotency tests.

#### REQ-REW-003 — Reject expired or cancelled Reward use

**Priority:** Must
**Type:** Functional
**Module:** Reward

As a Business owner, I want Reward validity to match the published Challenge deadline so that terms are predictable.

**Acceptance Criteria**

**Scenario: Redemption after expiry**

- **Given** an unredeemed entitlement.
- **When** the relevant rows are locked and the operation's single PostgreSQL `clock_timestamp()` value is `>= challenge.ends_at`.
- **Then** redemption is rejected as expired.

**Verification:** Database-time feature tests.

### Google Wallet

#### REQ-WAL-001 — Deterministic Wallet presentation

**Priority:** Must
**Type:** Functional
**Module:** Wallet

As a customer, I want the card to explain itself every time I open it so that I never have to remember Challenge rules.

**Acceptance Criteria**

**Scenario: Current Challenge state**

- **Given** a Customer pass with a current Challenge state.
- **When** its Wallet model is built.
- **Then** the structural fields stay fixed while points, current Visit value, Reward, deadline, and state values update.

**Verification:** Presentation-mapping tests and real-device review.

#### REQ-WAL-002 — Synchronize after commit and recover from provider failure

**Priority:** Must
**Type:** Quality/technical
**Module:** Wallet

As a Business owner, I want accepted Visits and Redemptions to remain valid even if Google Wallet is temporarily unavailable.

**Acceptance Criteria**

**Scenario: Provider fails after Visit commit**

- **Given** a committed Visit.
- **When** Wallet synchronization fails.
- **Then** the Visit remains committed, the failure is logged with correlation context, and synchronization can be retried.

**Scenario: Initial issuance retry**

- **Given** a Customer pass exists but Wallet provisioning failed.
- **When** provisioning is retried.
- **Then** the same Customer pass is reused instead of creating a duplicate local identity.

**Verification:** Integration/job failure tests.

### UX

#### REQ-UX-001 — Warm light-first theme with optional dark mode

**Priority:** Must
**Type:** Quality/technical
**Module:** UX

As a user, I want a calm interface with a clear accent so that long or fast operational use does not feel visually harsh.

**Acceptance Criteria**

**Scenario: Default theme**

- **Given** no saved theme preference.
- **When** the application opens.
- **Then** the warm light theme is used.

**Scenario: Dark preference**

- **Given** the user selects dark mode.
- **When** navigation continues.
- **Then** the preference persists and uses the same semantic design tokens.

**Verification:** Browser/UI review.

#### REQ-UX-002 — Fast scanner-first validation flow

**Priority:** Must
**Type:** Functional
**Module:** UX

As a Business owner serving customers quickly, I want the scanner and fallback code in one continuous flow so that validation takes as few interactions as possible.

**Acceptance Criteria**

**Scenario: Validation page**

- **Given** an authenticated owner.
- **When** **Validate visit** opens.
- **Then** the scanner is the primary content, the manual-code input is immediately below it, and the result/action card appears directly after those controls.

**Scenario: Reward available**

- **Given** the identified pass has an available Reward.
- **When** the result loads.
- **Then** **Redeem reward** is the single primary action and visit-registration controls do not compete for attention.

**Verification:** Browser review and component tests.

#### REQ-UX-003 — Responsive and accessible core flows

**Priority:** Should
**Type:** Quality/technical
**Module:** UX

As a user, I want controls to be readable, keyboard accessible, and touch friendly so that the application works in varied environments.

**Acceptance Criteria**

**Scenario: Keyboard and touch interaction**

- **Given** a core form or validation flow.
- **When** it is used by keyboard or on a narrow touch viewport.
- **Then** controls remain reachable, visibly focused, labelled, and free of horizontal page scrolling.

**Verification:** Semantic browser review plus automated accessibility checks where practical.

#### REQ-UX-004 — Explicit operational feedback

**Priority:** Must
**Type:** Functional
**Module:** UX

As a Business owner or customer, I want immediate plain-language feedback so that I know what happened and what to do next.

**Acceptance Criteria**

**Scenario: Business operation result**

- **Given** a validation or redemption attempt.
- **When** the server returns an expected domain state.
- **Then** the interface explains the outcome with text and iconography, not colour alone.

**Verification:** State-mapping/component tests.

### Security

#### REQ-SEC-001 — Separate public acquisition and private validation authority

**Priority:** Must
**Type:** Quality/technical
**Module:** Security

As a Business owner, I want public joining material to be safe to display openly so that it cannot be reused to forge customer progress.

**Acceptance Criteria**

**Scenario: Acquisition identifier used as validation**

- **Given** a public acquisition identifier.
- **When** it is submitted to a private validation operation.
- **Then** the server rejects it.

**Verification:** Security route tests.

#### REQ-SEC-002 — Server-side authorization, validation, and abuse controls

**Priority:** Must
**Type:** Quality/technical
**Module:** Security

As a system operator, I want security controls enforced on the server so that modified browser requests cannot bypass Business rules.

**Acceptance Criteria**

**Scenario: Tampered Business/pass input**

- **Given** an authenticated owner.
- **When** request input references another Business or an invalid pass.
- **Then** authorization/validation fails regardless of client state.

**Scenario: Repeated invalid manual codes**

- **Given** repeated invalid lookup attempts.
- **When** the configured threshold is exceeded.
- **Then** further attempts are temporarily throttled and the event is logged without the submitted secret.

**Verification:** Authorization, validation, and rate-limit feature tests.

#### REQ-SEC-003 — Structured security-relevant logging

**Priority:** Must
**Type:** Quality/technical
**Module:** Security

As a system operator, I want consistent structured logs so that failures and suspicious activity can be traced without changing application code later.

**Acceptance Criteria**

**Scenario: Logged domain/security event**

- **Given** a significant validation, redemption, authentication anomaly, cancellation, or Wallet synchronization failure.
- **When** the event is logged.
- **Then** the JSON record contains event name, level, timestamp, request ID, relevant non-secret domain identifiers, and outcome.

**Scenario: Secret safety**

- **Given** any log record.
- **When** its context is inspected.
- **Then** it contains no passwords, session cookies, authorization headers, Wallet private keys, raw validation tokens, or full sensitive provider payloads.

**Verification:** Logging configuration test/review and focused sanitization tests where custom logic exists.

### Data, reliability, and quality

#### REQ-DAT-001 — PostgreSQL integrity

**Priority:** Must
**Type:** Quality/technical
**Module:** Data

As a system operator, I want invalid states rejected by PostgreSQL so that one missed application check cannot corrupt loyalty data.

**Acceptance Criteria**

**Scenario: Invalid direct persistence**

- **Given** data that violates a critical relational or unique invariant.
- **When** persistence bypasses the normal UI.
- **Then** PostgreSQL rejects the invalid state.

**Verification:** PostgreSQL-backed constraint tests.

#### REQ-REL-001 — Preserve committed domain facts

**Priority:** Must
**Type:** Quality/technical
**Module:** Reliability

As a Business owner, I want counter operations to remain correct even when an external provider is unavailable.

**Acceptance Criteria**

**Scenario: External failure**

- **Given** a committed domain transaction.
- **When** a later external synchronization fails.
- **Then** local state remains committed and only the external effect is retried.

**Verification:** Transaction/provider failure tests.

#### REQ-QLT-001 — Verify critical behaviour proportionately

**Priority:** Must
**Type:** Quality/technical
**Module:** Quality

As a maintainer, I want measurable verification concentrated on high-risk behaviour so that the project remains trustworthy without an unnecessarily slow test suite.

**Acceptance Criteria**

**Scenario: Critical behaviour changes**

- **Given** a change to a CORE rule.
- **When** the change is considered complete.
- **Then** the rule has direct automated evidence and the required test suite passes without accepted flaky tests.

**Scenario: Strategic coverage**

- **Given** coverage instrumentation is executed for project-owned code.
- **When** quality evidence is evaluated.
- **Then** CORE rules/functions meet the 100% direct-coverage target, IMPORTANT code meets at least 80% line/function coverage, and INFRASTRUCTURE has no percentage target.

**Scenario: Static and dependency quality**

- **Given** the canonical project quality checks run.
- **When** the work is considered complete.
- **Then** project-owned static-analysis errors are zero and critical/high dependency findings are resolved or explicitly reviewed as an exception.

**Verification:** Canonical project quality checks and coverage report when instrumentation applies.
