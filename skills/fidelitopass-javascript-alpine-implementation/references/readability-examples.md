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
