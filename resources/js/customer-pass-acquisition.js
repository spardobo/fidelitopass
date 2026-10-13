/** Creates one transient Alpine interaction around the private acquisition endpoint.
 *
 * Credentials stay on the current page, never in Livewire state or browser storage.
 * A lost response cannot prove rollback, so only an explicitly safe server failure
 * permits another POST. Opening the confirmed link never requests another issuance.
 *
 * @param {string} endpoint Server-selected acquisition POST URL.
 * @param {string} csrfToken Current page's CSRF token.
 * @param {typeof fetch} request Native HTTP boundary, replaceable in deterministic tests.
 * @returns {object} Alpine presentation state, explicit saving action and disposal hook.
 */
export function customerPassAcquisition(endpoint, csrfToken, request = globalThis.fetch) {
    let disposed = false;

    return {
        state: "idle",
        saveUrl: "",
        manualCode: "",

        /** Requests one issuance and announces its safe result once while the page is live.
         * @param {(outcome: string) => void} [announce] Synchronous presentation callback receiving only the safe outcome.
         * @returns {Promise<void>} Resolves after presenting the result; rejects if the presentation callback throws.
         */
        async save(announce) {
            if (disposed || !["idle", "retryable"].includes(this.state)) return;

            this.state = "loading";

            try {
                const response = await request(endpoint, {
                    method: "POST",
                    credentials: "same-origin",
                    cache: "no-store",
                    redirect: "error",
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": csrfToken,
                    },
                });
                const result = await response.json();
                if (disposed) return;

                if (response.redirected) {
                    this.state = "blocked";
                } else if (response.status === 201 && isPrivateHandoff(result)) {
                    this.saveUrl = result.save_url;
                    this.manualCode = result.manual_code;
                    this.state = "ready";
                } else if (
                    response.status === 409 &&
                    result?.code === "business_unconfigured" &&
                    result.outcome === "definitive" &&
                    result.stage === "preparation" &&
                    result.retry_allowed === false
                ) {
                    this.state = "unconfigured";
                } else {
                    const retrySafe =
                        [502, 503].includes(response.status) &&
                        result?.outcome === "definitive" &&
                        ["preparation", "provider"].includes(result.stage) &&
                        result.retry_allowed === true;

                    this.state = retrySafe ? "retryable" : "blocked";
                }
            } catch {
                // a transport or decoding failure cannot establish whether issuance committed.
                if (!disposed) this.state = "blocked";
            }

            if (!disposed) announce?.(this.state);
        },

        /** Clears private page state and ignores late responses after Alpine disposal. */
        destroy() {
            disposed = true;
            this.saveUrl = "";
            this.manualCode = "";
            this.state = "blocked";
        },
    };
}

/** Checks the private server handoff before exposing a navigable Google URL.
 * @param {unknown} result Decoded private response, never trusted as HTML or a redirect.
 * @returns {boolean} Whether the response has a Google existing-Object JWT URL and canonical manual code.
 */
function isPrivateHandoff(result) {
    if (
        typeof result?.save_url !== "string" ||
        typeof result?.manual_code !== "string" ||
        result.manual_code.length !== 5 ||
        !/^[0-9]{5}$/.test(result.manual_code)
    ) {
        return false;
    }

    // exact spelling excludes credentials, ports, query strings and lookalike hosts.
    return /^https:\/\/pay\.google\.com\/gp\/v\/save\/[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(
        result.save_url,
    );
}
