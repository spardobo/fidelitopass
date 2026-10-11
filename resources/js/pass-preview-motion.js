/** Calculates the bounded landing-card tilt and sheen from relative pointer coordinates.
 * @param {number} horizontalPosition Pointer position across the untransformed card, from zero to one.
 * @param {number} verticalPosition Pointer position down the untransformed card, from zero to one.
 * @returns {{angleDegrees: number, sheenX: number, sheenY: number}} Bounded angle and sheen percentages.
 */
export function calculatePassPresentation(horizontalPosition, verticalPosition) {
    const horizontal = Math.max(0, Math.min(1, horizontalPosition));
    const vertical = Math.max(0, Math.min(1, verticalPosition));
    const displacement = 2 * horizontal - 1;

    return {
        angleDegrees: Math.abs(displacement) < 0.08 ? 0 : 8 * displacement,
        sheenX: 10 + 80 * horizontal,
        sheenY: 10 + 80 * vertical,
    };
}

/** Creates one Alpine-owned card interaction, with media-listener and frame cleanup.
 * @returns {{init: Function, move: Function, reset: Function, destroy: Function}} Local Alpine lifecycle and pointer handlers.
 */
export function passPreviewMotion() {
    let root;
    let reducedMotion;
    let listeners;
    let frame = null;

    /** Cancels pending writes and returns the current card to its static appearance. */
    function reset() {
        if (frame !== null) {
            cancelAnimationFrame(frame);
            frame = null;
        }

        const card = root.querySelector("article");
        card.style.removeProperty("transform");
        card.style.removeProperty("--sheen-x");
        card.style.removeProperty("--sheen-y");
    }

    /** Captures current pointer geometry and coalesces presentation writes into one owned frame.
     * @param {PointerEvent} event Mouse or touch pointer movement on the semantic card.
     */
    function move(event) {
        if (listeners.signal.aborted) return;
        if (event.pointerType !== "mouse" || reducedMotion.matches) {
            reset();
            return;
        }

        if (frame !== null) cancelAnimationFrame(frame);

        const card = root.querySelector("article");
        const bounds = root.getBoundingClientRect();
        const presentation = calculatePassPresentation(
            (event.clientX - bounds.left) / bounds.width,
            (event.clientY - bounds.top) / bounds.height,
        );
        // Capture the untransformed geometry before queuing only the style writes.
        const width = card.offsetWidth;
        const height = card.offsetHeight;
        frame = requestAnimationFrame(() => {
            frame = null;
            if (
                listeners.signal.aborted ||
                !root.isConnected ||
                reducedMotion.matches ||
                root.querySelector("article") !== card
            )
                return;

            card.style.setProperty("--sheen-x", `${presentation.sheenX}%`);
            card.style.setProperty("--sheen-y", `${presentation.sheenY}%`);
            card.style.transform = presentation.angleDegrees
                ? `perspective(900px) rotate3d(-${width}, ${height}, 0, ${presentation.angleDegrees}deg)`
                : "none";
        });
    }

    return {
        /** Acquires the local root and preference listener through Alpine's native init hook. */
        init() {
            root = this.$el;
            reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
            listeners = new AbortController();
            reducedMotion.addEventListener(
                "change",
                () => {
                    if (reducedMotion.matches) reset();
                },
                { signal: listeners.signal },
            );
            reset();
        },
        move,
        reset,
        /** Releases pending motion and the preference listener when Alpine removes this root. */
        destroy() {
            listeners.abort();
            reset();
        },
    };
}
