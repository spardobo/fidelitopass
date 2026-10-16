---
name: fidelitopass-javascript-alpine-implementation
description: "Trigger: FidelitoPass JavaScript, .js/.mjs, Alpine, component-local browser behavior, JS tests or scripts. Apply shared standards plus capability order, component-local scripts, async intent, cleanup contract, ESM discipline and JSDoc format."
license: Apache-2.0
metadata:
  author: FidelitoPass
  version: "4.0"
---

## Activation Contract
Use for JS/Alpine, browser behavior, JS tests and scripts. Read ../shared/coding-standards.md first. Exclude PHP-only and markup-only work.

## Hard Rules
Capability order: native HTML/browser → Flux → Livewire → local Alpine → custom JS. Never custom JS for a gap the platform covers.

Preserve the file extension (.js vs .mjs), module system (ESM/CJS), import specifiers, and runtime context. No incidental migration.

No TypeScript-only syntax in .js/.mjs. JSDoc annotations live in comments, never in executable source.

Component-local scripts via the native Livewire 4 format (@script for class-based; documented structure for multi-file). No second bundle, no second Alpine runtime.

Server authority preserved. Never duplicate validation/authorization in hydrated state, Alpine, or localStorage.

Keep the configured Vite asset pipeline. No package installations for a single behavior.

### Component-local pattern
```blade
@script
<script>
    const root = $wire.$el;

    function bind() { /* ... */ }

    const controller = new AbortController();
    // listeners with { signal: controller.signal }

    return () => controller.abort();
</script>
@endscript
```

Rules: resolve the root once. Cleanup ties to root removal, not the page. The returned disposer shows the cleanup contract; returning it alone does NOT register it with Livewire. Bind it through the installed version's verified lifecycle. Do not install a global document observer.

### Async intent
Before replaceable async work:

```js
let intent = 0;

async function load(query) {
    const current = ++intent;
    const result = await fetchSearch(query);

    if (current !== intent) {
        return; // stale
    }

    publish(result);
}
```

Rules: invalidate the old intent immediately when the user changes input. Re-check after every await, including finally. Cleanup does not prove current intent.

### Cleanup contract
Owned resources (listeners, timers, observers, controllers) release in one discoverable routine.

Tied to the real lifetime: root removal for component scripts, not pagehide.

AbortController when it simplifies teardown; otherwise keep the removal handle.

Never leave a listener attached to a replaced node.

### JSDoc format
```js
/**
 * Closes a root's disclosure when a navigation link is activated.
 *
 * @param {HTMLElement} root Owning root that may contain details[data-navigation-menu].
 * @returns {() => void} Releases only this initializer's listener.
 */
function bindMenuDismissal(root) { /* ... */ }
```

Description sentences in third person.

@param {Type} name Description.

@returns {Type} Description. — note returns, not return.

Blank line before tags.

No TypeScript syntax.

### Playwright
Callbacks are serialization boundaries. Only serializable args cross.

Named objects for role data:

```js
const roles = [
    { role: "neutral benefit", selector: "#benefits article:last-child", expectedBackground: "rgb(46, 46, 46)" },
];
await page.evaluate((roles) => { /* ... */ }, roles);
```

No forEach(async ...) when completion must be awaited. Use sequential for...of or Promise.all by dependency.

Assert observable outcomes (focus, URL, geometry, DOM state), not initializer names.

## Decision Gates

| Situation | Required action |
| --- | --- |
| A supplied pattern conflicts with an accepted owner | Preserve the owner's contract; correct only the conflicting rule. |
| A required runtime or API is unknown | Stop before dependent edits. NEVER invent the missing contract. |
| Work also changes server logic or markup | Load the [PHP skill](../fidelitopass-laravel-livewire-implementation/SKILL.md) and/or [Blade skill](../fidelitopass-blade-flux-implementation/SKILL.md) for the affected responsibilities. |
| Work crosses browser, Node or Playwright execution contexts | Follow [Source boundary](references/implementation-details.md#source-boundary) and preserve each runtime's contract. |

## Execution Steps

1. Read shared standards and the applicable references before the first edit.
2. Establish the real boundary and preserve the approved behavior.
3. Apply the canonical shape and report only evidence actually observed.

## Output Contract

Report changed paths, applied owners, observed checks, unavailable evidence and unresolved limits. NEVER claim unrun verification.

## References

- [Shared coding standards](../shared/coding-standards.md) — cross-language gates.
- [Implementation details](references/implementation-details.md) — browser/Node/Playwright details.
- [Readability examples](references/readability-examples.md) — calibration examples.
