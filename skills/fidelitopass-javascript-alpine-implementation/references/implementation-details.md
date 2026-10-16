# JavaScript / Alpine implementation
Apply shared standards first. This file refines them for browser and Node code.

## Contents

- [Source boundary](#source-boundary)
- [Capability order](#capability-order)
- [Component-local scripts](#component-local-scripts)
- [DOM ownership and lifecycle](#dom-ownership-and-lifecycle)
- [Async intent](#async-intent)
- [JSDoc](#jsdoc)
- [Playwright](#playwright)
- [Node filesystem scripts](#node-filesystem-scripts)
- [Anti-patterns](#anti-patterns)

## Source boundary
Before editing: establish extension (.js/.mjs), module system (ESM by package.json#type or .mjs), runtime (browser/Node/Playwright), and formatter ownership. Inspect the nearest package.

Preserve:

Extension and module system.

Import/export shapes and specifier extensions.

Executable vs import-only (import.meta.url guard).

Shebangs on CLI scripts.

## Capability order
Native HTML/browser semantics (form validation, <details>, <dialog>, focus).

Flux components.

Livewire bindings (wire:model, wire:click).

Local Alpine state.

Custom JS for a concrete gap.

Never replace native validation with JS. Never duplicate server rules client-side.

## Component-local scripts
For Livewire 4 class-based components:

```blade
@script
<script>
    const root = $wire.$el;
    // ...
</script>
@endscript
```

For multi-file components, use the documented script structure. Reserve the shared Vite entry for genuinely shared code, not a long local script.

## DOM ownership and lifecycle
Resolve the root once at init.

Query within the root. Scope selectors to distinguish instances.

Stable IDs and data-* hooks in English.

Re-resolve nodes replaced by Livewire morph; do not retain stale references.

Cleanup tied to the verified owner lifetime, not an assumed page event. Returning a disposer alone does NOT register cleanup. AbortController when it simplifies.

Duplicate-init protection only when real entry paths overlap. Scope to the instance.

## Async intent
```js
let intent = 0;

async function search(query) {
    const current = ++intent;

    try {
        const result = await fetch(`/search?q=${encodeURIComponent(query)}`);

        if (current !== intent) {
            return;
        }

        publish(result);
    } finally {
        if (current === intent) {
            setLoading(false);
        }
    }
}
```

Rules:

Increment before starting work.

Check after every await, including in finally.

Stale finally must not clear a successor's loading state.

Abort alone does not prove current intent.

## JSDoc
```js
/**
 * Reads the current value of a scoped input without mutating it.
 *
 * @param {HTMLElement} root Component root containing the input.
 * @param {string} name Input name attribute.
 * @returns {string} Current trimmed value, or an empty string when absent.
 */
function readField(root, name) {
    return root.querySelector(`[name="${name}"]`)?.value.trim() ?? "";
}
```

Rules:

One paragraph per idea, blank line between.

@param {Type} name Description.

@returns {Type} Description. (returns, not return).

No TS syntax in executable source.

## Playwright
Named objects over positional arrays:

```js
const roles = [
    { role: "neutral benefit", selector: "#benefits article:last-child", expectedBackground: "rgb(46, 46, 46)" },
];

const matrix = await page.evaluate((roles) => {
    const surfaces = [];

    for (const { role, selector, expectedBackground } of roles) {
        // ...
    }

    return surfaces;
}, roles);
```

Rules:

page.evaluate / locator.evaluate callbacks run in the browser context. No Node imports, no closures over runner variables.

Serialize only. Named objects.

No forEach(async ...) when awaiting. Use for...of (sequential) or Promise.all (parallel by dependency).

Assert observable outcomes, not implementation names.

## Node filesystem scripts
For quality scripts that accept paths:

Reject absolute paths, .. segments and forbidden separators. Reject anchors unless the selected command explicitly accepts and validates them.

Resolve root and target with realpathSync.

Check path.relative by segments: reject .. or an absolute result.

Accept internal links and linked roots.

Validate file/directory role before reading.

Use lstatSync for optional files; existsSync follows links and hides dangling entries. For missing targets, resolve the nearest existing ancestor and reject dangling link components. NEVER change an existing command's accepted path contract as a style edit.

Use temporary fixtures for traversal and link cases.

## Anti-patterns
| Anti-pattern | Why it's wrong |
| --- | --- |
| document.addEventListener('click', ...) in a component script | Breaks instance isolation and cleanup. |
| forEach(async () => await ...) | Completion is not awaited. |
| try { ... } catch { return null; } for readability | Masks failure; changes semantics. |
| const CONFIG = { COLORS: ..., SELECTORS: ... } | Centralizes literals without meaning. |
| page.evaluate(() => helper()) referencing a Node function | Crosses the serialization boundary. |
| Storing server state in Alpine without $wire | Diverges from server authority. |