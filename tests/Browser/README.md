# Browser journeys

Playwright specifications cover the public landing and business onboarding journeys. Add specifications here only when an owned, complete user journey exists.

Run the suite through the pinned browser container:

```bash
./scripts/quality/browser/run-playwright.sh test:e2e
```

Sail must be running because browser tests use the `fidelitopass-network` Docker network and the application service name as their default base URL.

Promotion picker coverage uses isolated fixtures. The focused picker journey checks selection-only fields, application-locale display despite a different browser locale, canonical Livewire values, client-only form summary updates, edit initialization (including a saved start date earlier than the current minimum) and widget cleanup. Persisted date labels are prepared by PHP components through `App\Support\RegionalDate`; the live form summary only mirrors the picker's visible values. The main Promotion workflows drive the widget's public API where the assertion concerns server behaviour rather than calendar interaction. Weekday, multiplier and time edits are deferred until the next action; the focused journey verifies that they do not send standalone updates. The schedule-mode binding remains live because PHP renders the time fields conditionally. Pass colour bindings remain live for the server-rendered preview, contrast and unsaved-change state.

Verify the pinned browser runtime without inventing a product journey:

```bash
./scripts/quality/browser/run-playwright.sh check:playwright-runtime
```
