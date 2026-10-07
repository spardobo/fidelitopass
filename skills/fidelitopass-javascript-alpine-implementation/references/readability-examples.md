# Readable JavaScript calibration

Read only when a browser interaction needs a comparison. This initializer requires a real owning root and an explicit cleanup caller. Prefer a native or small Alpine interaction where it already meets the requirement; do not add this to every component.

```js
/**
 * Closes a root's native disclosure when one of its navigation links is activated.
 *
 * @param {HTMLElement} root The existing owner that may contain details[data-navigation-menu].
 * @returns {() => void} Releases only this initializer's listener.
 */
function bindMenuDismissal(root) {
    const navigationMenu = root.querySelector('details[data-navigation-menu]');

    if (!navigationMenu) {
        return () => {};
    }

    const controller = new AbortController();

    function dismissMenu(event) {
        if (!(event.target instanceof Element)) {
            return;
        }

        const activatedLink = event.target.closest('a[href]');

        if (activatedLink && navigationMenu.contains(activatedLink)) {
            navigationMenu.open = false;
        }
    }

    navigationMenu.addEventListener('click', dismissMenu, { signal: controller.signal });

    return () => controller.abort();
}
```

Names show the responsibility; paragraphs separate optional-root selection, owned resource, behavior and binding. One delegated listener covers current links. The returned contract makes release discoverable without a global registry or observer. If morph replaces the disclosure, dispose/rebind using the verified owner's actual lifecycle; this initializer alone does not handle Livewire navigation. No box belongs inside the function or its nested handler.

For replaceable asynchronous work, invalidate the preceding intent as soon as the user changes it; check current intent before publishing data and in finally before clearing loading. Add this machinery only where such a race exists. Do not import the complete standalone catalog example, its limits, endpoint or second Alpine bootstrap into FidelitoPass.

## Playwright measurement

Named fields make the role's selector, independent expected background and text targets recognizable. These before/after excerpts come from the contextual-ink test in [landing.spec.js](../../../tests/Browser/landing.spec.js). They are non-contiguous fragments, not standalone algorithms or a new test template; ellipses mark omitted context.

Before:

```js
const roles = [
    ["neutral benefit", "#benefits article:last-child", "rgb(46, 46, 46)", ["h3", "p"]],
    // ... omitted roles
];

const matrix = await page.evaluate((roles) => {
    // ... omitted browser-local calculations
    return roles.flatMap(([role, selector, background, textSelectors]) =>
        [...document.querySelectorAll(selector)].map((element) => ({
            role,
            expectedBackground: background,
            // ... omitted background and text measurements
        })),
    );
}, roles);
```

After:

```js
const roles = [
    {
        role: "neutral benefit",
        selector: "#benefits article:last-child",
        expectedBackground: "rgb(46, 46, 46)",
        textSelectors: ["h3", "p"],
    },
    // ... omitted roles
];

const matrix = await page.evaluate((roles) => {
    // ... omitted browser-local calculations
    const surfaces = [];

    for (const { role, selector, expectedBackground, textSelectors } of roles) {
        for (const element of document.querySelectorAll(selector)) {
            const surfaceBackground = effectiveBackground(element);
            const background = `rgb(${surfaceBackground.map(Math.round).join(", ")})`;
            const textMeasurements = [];
            // ... omitted text measurement and surface accumulation
        }
    }

    return surfaces;
}, roles);
```

The reading path separates backdrop composition, text ink and contrast before accumulating each result. Role data crosses explicitly through `page.evaluate`'s second argument. Calculations stay in the browser; Node assertions consume the returned matrix. Direct loops retain query and traversal work while avoiding spread, mapped and flattened intermediate arrays. Opaque backgrounds return before ancestor traversal; transparent backgrounds return the ancestor result without blending. Composition results, text alpha, fractional-alpha formulas and rounding remain unchanged. This alpha-aware measurement is not interchangeable with the separate opaque-RGB contrast tests.

## Node validation

Logical paragraphs expose three phases in `validateRequirementIds` in [validate-documentation.mjs](../../../scripts/quality/documentation/validate-documentation.mjs): register rows must have headings, headings must have rows, then both sequences must match. These excerpts omit surrounding extraction and validation; they are not standalone validators or a new control-flow style template.

Before:

```js
for (const id of rows)
    if (!definitions.has(id))
        errors.push(`docs/requirements.md register row ${id} has no heading`);
for (const id of headings)
    if (!rowIds.has(id)) errors.push(`docs/requirements.md heading ${id} has no register row`);
if (rows.length !== headings.length || rows.some((id, index) => id !== headings[index]))
    errors.push("docs/requirements.md register rows and headings must match in order");
```

After:

```js
for (const id of rows) {
    if (!definitions.has(id)) {
        errors.push(`docs/requirements.md register row ${id} has no heading`);
    }
}

for (const id of headings) {
    if (!rowIds.has(id)) {
        errors.push(`docs/requirements.md heading ${id} has no register row`);
    }
}

if (rows.length !== headings.length || rows.some((id, index) => id !== headings[index])) {
    errors.push("docs/requirements.md register rows and headings must match in order");
}
```

Braces make each loop and conditional extent explicit. Blank lines separate the missing-heading pass, missing-register-row pass and order check; the condition and diagnostics retain the source order. The validator still scans each role of the requirement register separately, then checks alignment. Valid candidates need no source-location extraction; malformed or unknown candidates still compute the diagnostic path and line by splitting the matching content prefix. The validator keeps its existing independent passes and adds no cache or indexing pass.
