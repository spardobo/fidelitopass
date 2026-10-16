# JavaScript readability calibration
Read before editing browser code. Examples are patterns, not templates.

## Canonical: initializer with owned cleanup
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

Notes: an absent optional disclosure is handled with an early return that returns a no-op release. Owned resource (AbortController) created once. Delegated listener. Returned contract makes cleanup discoverable; the verified owner must register that disposer. A returned function alone is NOT a lifecycle hook.

## Canonical: async intent
```js
let intent = 0;

async function search(query) {
    const current = ++intent;

    try {
        const result = await fetchSearch(query);

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

Notes: intent incremented before starting. Checked after the await and in finally. Stale finally does not clear successor state.

## Canonical: Playwright named fields
```js
const roles = [
    {
        role: "neutral benefit",
        selector: "#benefits article:last-child",
        expectedBackground: "rgb(46, 46, 46)",
        textSelectors: ["h3", "p"],
    },
];

const matrix = await page.evaluate((roles) => {
    const surfaces = [];

    for (const { role, selector, expectedBackground, textSelectors } of roles) {
        // browser-local work
    }

    return surfaces;
}, roles);
```

Notes: named object per role. Named destructuring in the callback. Named array for results. Arguments cross explicitly.

## Anti-patterns
| Anti-pattern | Why it's wrong |
| --- | --- |
| if (event.target && event.target.closest) without instance check | event.target may be a Node, not an Element. |
| return; in a finally block | Swallows the return value of the try. |
| Wrapping every field access in ?. | Hides broken markup rather than surfacing it. |
| page.evaluate(() => { return window.helper(); }) | Runner-local helpers are not serialized. A browser global is valid only when explicitly installed in that context. |
| roles.flatMap(([role, selector, bg]) => ...) | Positional tuples lose meaning at call sites. |
## Final calibration
Root resolved once, cleanup owned.

Async work: intent invalidated before replacement, checked after every await.

JSDoc with @param/@returns on named callables.

Playwright callbacks use named objects; serialization boundary respected.

No TypeScript syntax in .js/.mjs.
