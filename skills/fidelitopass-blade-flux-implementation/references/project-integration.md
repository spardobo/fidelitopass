# Existing presentation integration

Read only when changing shared styling, assets or the landing fit. These are implementation locations moved from UI policy, not independent visual authority or proof of installed source. Inspect current files before editing; filenames can evolve without changing UI contracts.

The single editable source for canvas, neutral surface, emphasis and accent is the **Global application palette** block (`@theme static`) in `resources/css/app.css`: `--color-app-canvas`, `--color-app-surface`, `--color-app-emphasis` and `--color-app-accent`, one definition per role. Static emission keeps all four properties available on the global root even before every utility is used. New screens must consume these app tokens (for example, `bg-app-canvas`, `bg-app-surface`, `bg-app-emphasis`, `bg-app-accent`), not unmigrated generic utilities. Landing may use local role aliases but owns no canonical palette literals.

## Approved asset inventory

- `public/logo.png`: source artwork; `public/logo-header.webp`: optimized header/footer wordmark.
- `public/logo_icon.svg`: standalone isotipo, including the decorative mark in the landing pass visual.
- `resources/views/partials/head.blade.php`: favicon links to `public/favicon.ico`, `public/favicon.svg`, PNG favicon sizes, and Apple touch icon; `public/site.webmanifest` lists Android icons. Keep the shared head partial as the single integration point.
## Existing landing fit

Match the header-to-hero opening gap with the last-CTA-to-footer closing gap; do not substitute a bottom-equals-sides rule. Reuse the hero's calculated base plus residual, capped at the existing `80px` rhythm, for end-of-main padding without reducing hero viewport availability. Without JavaScript or after teardown, closing padding falls back to the natural base; dynamic capped fitting requires JavaScript. Preserve widths, hero margins and independent section spacing. The UI owner retains the visual values and fallback outcomes.

The approved marketing catalog was located at `lang/es/landing.php`. Preserve its complete phrases and reuse the hero registration label in the final CTA.
