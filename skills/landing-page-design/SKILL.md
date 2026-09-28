---
name: landing-page-design
description: "Trigger: landing page design, marketing page copy, landing redesign, landing conversion review. Design truthful, accessible product landing pages within project constraints."
license: MIT
metadata:
  author: Elaya
  version: "local-adaptation-1"
---

## Activation Contract

Use for landing-page structure, visuals, copy and conversion reviews. Do not activate for arbitrary dashboards, web UI or unrelated components. This local adaptation retains Elaya's original MIT license and attribution; project constraints override historical examples.

## Hard Rules

- Read current `docs/ui-ux-guidelines.md`, `docs/product-scope.md` and existing `lang/es/landing.php` before changing FidelitoPass landing content. Use approved dark-only Onest, lavender `#B7ABE4` (hover `#D8CEF5`), black/charcoal surfaces and public `#242424`/`#303030` layers; reuse approved logo/favicon assets and layouts.
- Product-facing copy must label unavailable illustrated flows as **future**. Registration, login, email verification, Business setup/profile and the public landing are currently available; Promotion creation, acquisition QR, Wallet issuance, Visit scanning/validation and Redemption are not. The sample QR/code is fictional, not a credential or working control. Do not imply otherwise.
- Never invent metrics, testimonials, pricing/free offers, guarantee terms, operational controls or evidence. Omit unsupported proof and risk reversal. Keep Spanish merchant-facing copy in consistent tuteo, neutral Spanish elsewhere; use grouped `lang/es/landing.php` keys. Hero/final registration CTA use the same existing `route('register')`; sign-in is subordinate via `route('login')`.
- Respect semantic controls, visible focus, contrast, keyboard navigation, ~44px touch targets and `prefers-reduced-motion`; avoid dead links and motion-dependent readability. Explicit user/project rules win over optional reference values.
- Reuse Laravel 13 / Livewire 4 full-page MFCs, `Route::livewire`, shared layouts, Flux 2, Tailwind 4 `@theme` and Vite; do not install a parallel pipeline. Browser behavior, when in scope and authorized, uses `./scripts/quality/browser/run-playwright.sh`.

## Decision Gates

| Situation | Action |
| --- | --- |
| Missing audience or primary action | Ask together for essential unknowns; state non-product assumptions only. |
| Evidence or offer unverified | Omit proof, trial, price or guarantee; never invent a plausible substitute. |
| Illustration shows undelivered capability | Explicitly label it future in customer-facing copy; disable nonfunctional controls. |
| Visual recipe conflicts with brand or accessibility | Follow current docs and user intent, not reference defaults. |
| Existing landing structure/copy | Preserve approved section/navigation order and `lang/es/landing.php` copy unless change is requested. |

## Execution Steps

1. Establish one audience, offer and real primary action. Check available assets and implementation status.
2. Choose a fitting page structure; draft factual hero, benefits, steps, FAQs and metadata only where relevant. For FidelitoPass keep header order Beneficios → Cómo funciona → Promociones → El pase → Preguntas frecuentes → Empezar; no header registration button or theme toggle.
3. Build/revise section by section with responsive semantics, honest conceptual Pase/Promoción visuals, clear availability labels and functional destinations. Avoid landing configurators or operational-looking fake QR controls.
4. Verify copy claims, keyboard/focus, contrast, reduced motion, mobile layout, actual states and links; run only authorized checks.

## Output Contract

Return the chosen structure and rationale, actual CTA/copy and availability wording, files changed, checks run and unresolved claims/risks. Before new-page code, provide a compact outline, hero, benefits, steps, FAQ, SEO recommendation and layout rationale where supported; never fill an unsupported proof slot.

## References

- `references/strategy.md` — original A1–A8, original output format and strategy checklist; optional planning patterns.
- `references/visual-system.md` — original B1–B11 values, motion, states and ship/checklist, preserved as optional inspiration subordinate to current brand and accessibility.
- Existing `LICENSE` — MIT, Copyright (c) 2026 Elaya. Historical optional companion: `redesign-existing-projects` was mentioned in the original; do not assume it is installed or fetch it.
