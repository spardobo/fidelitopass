# Browser journeys

Playwright specifications cover the public landing and business onboarding journeys. Add specifications here only when an owned, complete user journey exists.

Run the suite through the pinned browser container:

```bash
./scripts/quality/browser/run-playwright.sh test:e2e
```

Sail must be running because browser tests use the `fidelitopass-network` Docker network and the application service name as their default base URL.

Verify the pinned browser runtime without inventing a product journey:

```bash
./scripts/quality/browser/run-playwright.sh check:playwright-runtime
```
