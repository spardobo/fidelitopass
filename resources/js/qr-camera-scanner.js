/** Creates one local Alpine camera capability; the SDK owns capture and continuous decoding.
 *
 * @param {(token: string) => void} onResult Receives one exact token in transient component memory.
 * @returns {object} Safe camera feedback and start/stop/disposal lifecycle, without vendor types.
 */
export function qrCameraScanner(onResult) {
    let disposed = false;
    let intent = 0;
    let session = null;
    let startup = Promise.resolve();

    return {
        active: false,
        starting: false,
        error: "",

        /** Lazily starts one SDK session against the current local video.
         * @returns {Promise<void>} Settled startup, including safe failure feedback.
         */
        async start() {
            if (disposed || this.starting || this.active) return;
            const current = ++intent;
            const previous = startup;
            this.starting = true;
            this.error = "";

            // serialize permission requests so obsolete controls never clear a successor's video
            startup = (async () => {
                await previous;
                if (disposed || current !== intent) return;
                if (!window.isSecureContext || !window.navigator.mediaDevices?.getUserMedia) {
                    this.starting = false;
                    this.error = "unavailable";
                    return;
                }

                let owned;
                try {
                    const [
                        { BrowserQRCodeReader },
                        { NotFoundException, ChecksumException, FormatException },
                    ] = await Promise.all([import("@zxing/browser"), import("@zxing/library")]);
                    if (disposed || current !== intent) return;
                    const video = this.$el.querySelector("video");
                    owned = { controls: null, released: false };
                    session = owned;
                    const reader = new BrowserQRCodeReader();
                    const controls = await reader.decodeFromConstraints(
                        { video: { facingMode: { ideal: "environment" } }, audio: false },
                        video,
                        (result, error, callbackControls) => {
                            if (disposed || current !== intent) {
                                release(owned, callbackControls);
                                return;
                            }
                            if (result) {
                                owned.controls = callbackControls;
                                this.stop();
                                onResult(result.getText());
                                return;
                            }
                            if (
                                !error ||
                                error instanceof NotFoundException ||
                                error instanceof ChecksumException ||
                                error instanceof FormatException
                            )
                                return;
                            owned.controls = callbackControls;
                            this.stop();
                            this.error = "initialization";
                        },
                    );
                    if (disposed || current !== intent) {
                        release(owned, controls);
                        return;
                    }
                    owned.controls = controls;
                    this.starting = false;
                    this.active = true;
                } catch (error) {
                    if (disposed || current !== intent) return;
                    this.stop();
                    this.error = cameraFailure(error);
                }
            })();
            await startup;
        },

        /** Invalidates callbacks immediately and releases only this session's controls. */
        stop() {
            intent++;
            this.active = false;
            this.starting = false;
            if (session) release(session, session.controls);
            session = null;
        },

        /** Releases resources when Alpine removes the actual camera root. */
        destroy() {
            disposed = true;
            this.stop();
        },
    };
}

/** Releases scoped SDK controls once, including a callback before startup resolves.
 * @param {object} session Private ownership record for one startup.
 * @param {object|null} controls SDK-provided controls, absent while permission is pending.
 */
function release(session, controls) {
    if (!controls || session.released) return;
    session.released = true;
    controls.stop();
}

/** Translates native startup failures without retaining private messages or vendor errors.
 * @param {unknown} error Native startup failure.
 * @returns {string} Closed camera feedback reason.
 */
function cameraFailure(error) {
    switch (error?.name) {
        case "NotAllowedError":
        case "SecurityError":
            return "denied";
        case "NotReadableError":
        case "AbortError":
            return "busy";
        case "NotFoundError":
        case "OverconstrainedError":
            return "unavailable";
        default:
            return "initialization";
    }
}
