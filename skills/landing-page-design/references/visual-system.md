# Original Part B visual system (optional inspiration)

Historical values below are preserved as design reference, **not hard rules**. Current project/user brand, semantic HTML, accessibility, reduced motion and real operational states override them. Do not apply this reference to dashboards or unrelated web UI.

## B1. Typography and copy

Original approved fonts: Geist, Manrope, Geist Mono, Poppins; forbade Inter, Roboto, Arial, Open Sans, Helvetica, italics and weights above bold; one face except functional monospace. FidelitoPass instead uses **Onest Variable**. Original banned hyphens in copy, advised `text-wrap: balance` for headings and `pretty` for body, and no single-word last lines. Use natural Spanish rather than mechanically banning punctuation. Original Tailwind size table (size / line-height): `text-xs` 12/16px; `text-sm` 14/20; `text-base` 16/24; `text-lg` 18/28; `text-xl` 20/28; `text-2xl` 24/32; `text-3xl` 30/36; `text-4xl` 36/40; `text-5xl` 48px/1; `text-6xl` 60px/1; `text-7xl` 72px/1; `text-8xl` 96px/1; `text-9xl` 128px/1. Original snapped arbitrary sizes down with paired line height and disallowed independent custom line heights. Button examples: main `text-base` semibold, header `text-sm` semibold. Adapt to approved design and contrast.

## B2. Spacing

Original exclusive scale: Spacing-0=0, -25=2px, -50=4px, -75=8px, -100=12px, -200=16px, -300=24px, -400=32px, -500=40px, -600=48px, -700=64px, -800=80px, -900=96px. Main button padding 8px vertical/12px horizontal. Not exclusive in this project; preserve accessible touch targets.

## B3. Radius

Original Tailwind-only radii; for nested shapes with gap <32px, inner radius = outer radius − gap when result >2px, otherwise square/unchanged. Example: 16px outer with 8px padding gives 8px inner. Current project cards are typically 16–24px and controls 10–14px.

## B4. Borders/backgrounds

Original: no one-sided card borders or background gradients; dark colors only `#000000`, `#181818`, `#1F1F1F`, `#272727`, `#313131`, `#131209`. Project instead defines `#414141` border and public `#242424`/`#303030` layers; follow current docs.

## B5. Hero

Original dark text gradient white→`#9B9B9B`, light black→`#666666`; no background gradients; heading/subheading max width 680px and meaningful line breaks. Project is dark-only; gradients and width are optional only when legible and appropriate.

## B6. Icons

Original suggested Phosphor, Solar, Iconamoon; banned Material Icons/Symbols. Reuse installed project iconography rather than adding dependencies.

## B7. Motion

Original bespoke easing `transition-all duration-700 ease-[cubic-bezier(0.32,0.72,0,1)]`; floating glass nav `mt-6 mx-auto w-max rounded-full`, hamburger morph `rotate-45`/`-rotate-45`, overlay `backdrop-blur-3xl bg-black/80` or `bg-white/80`, staggered links `translate-y-12 opacity-0` → `translate-y-0 opacity-100` with `delay-100/150/200`. Scroll reveal `translate-y-16 blur-md opacity-0` → `translate-y-0 blur-0 opacity-100` over ≥800ms, using IntersectionObserver or Framer Motion `whileInView` rather than unthrottled scroll. **None is mandatory**: avoid hiding content pending JS; honor `prefers-reduced-motion`, keyboard and mobile performance; no light theme in FidelitoPass.

## B8. Content realism

Original: no Lorem Ipsum, “John Doe”, Acme/Nexus/SmartFlow, round fake numbers (`99.99%`, `50%`, `$100.00`); suggested organic-looking figures (`47.2%`, `$99.00`, a sample phone number), varied names, unique avatars and blog dates. **Never invent plausible-looking numbers, names, testimonials, brands, avatars, prices, phone numbers or dates as real evidence.** Avoid AI clichés (“Elevate”, “Seamless”, “Unleash”, “Next Gen”, “Game changer”, “Delve”, “Tapestry”, “In the world of”), use sentence case and active voice; direct success/error copy without exclamation or “Oops!”.

## B9. States

Original: hover shift/scale/translation, active `scale(0.98)` or `translateY(1px)`, visible focus, layout-shaped loading skeleton, composed empty state, specific inline error (not `window.alert()`), no dead `#` links, current nav indicated. Implement states only for actually interactive, functional elements. Keep focus and semantic disabled states, respect motion preferences; don't fabricate operations.

## B10. Ship checklist

Footer privacy/terms links **when available**, branded 404 if in scope, client-side form hints with authoritative server validation, skip link, jurisdiction-appropriate cookie consent, favicon, title/meta description/og:image/social tags when assets exist, meaningful alt text, semantic nav/main/article/aside/section and a working way back from every page. Never add dead legal links or fictitious policies.

## B11. Tagline reveal

Original mandated a separate mid-page ≥2-line benefit statement, `text-4xl`–`text-6xl`, hero-like max width; words started around 25–35% opacity and activated individually as each crossed a trigger line in reading order, rather than the whole block flipping at once; use custom B7 easing, IntersectionObserver per word or requestAnimationFrame-throttled scroll, not unthrottled scroll. **Optional only**. Do not suppress readable content without JS or reduced-motion support; skip if it hurts clarity.

## Original quick visual/content checklist

Single approved typeface; legible balanced copy; coherent scale/spacing/radii; complete borders; compatible background; meaningful hero breaks; existing icons; motion only if accessible; optional tagline; no placeholder claims; functional hover/active/focus/loading/empty/error states; no dead links; 404/legal/form/favicon/meta/alt only where applicable. Project rules override every fixed original value.
