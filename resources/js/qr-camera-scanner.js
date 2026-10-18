// closed set of camera feedback reasons
const CameraError = Object.freeze({
    DENIED: "denied",
    BUSY: "busy",
    UNAVAILABLE: "unavailable",
    INITIALIZATION: "initialization",
});

/** Creates one local Alpine camera capability; the SDK owns capture and continuous decoding.
 *
 * @param {(token: string) => void} onResult Receives the exact token after owned capture stops.
 * @returns {object} Local camera controls and safe presentation state.
 */
export function qrCameraScanner(onResult) {
    let disposed = false;
    let currentSession = null;
    let pendingStartup = Promise.resolve();

    return {
        active: false,
        starting: false,
        error: "",

        /** Lazily starts one SDK session against the current local video.
         *
         * @returns {Promise<void>} Settles after startup or cancellation releases late controls.
         */
        async startCamera() {
            if (disposed || this.starting || this.active) return;

            this.starting = true;
            this.error = "";

            // reject unsupported or insecure contexts before dynamic import
            if (!window.isSecureContext || !window.navigator.mediaDevices?.getUserMedia) {
                this.starting = false;
                this.error = CameraError.UNAVAILABLE;
                return;
            }

            const session = { stopped: false, controls: null, released: false };
            currentSession = session;
            const previousStartup = pendingStartup;
            let finishStartup;
            pendingStartup = new Promise((resolve) => {
                finishStartup = resolve;
            });

            try {
                // wait for obsolete SDK startup to finish cleaning the shared video
                await previousStartup;
                if (disposed || session.stopped) return;

                // load vendor libraries lazily to keep initial page load fast
                const [
                    { BrowserQRCodeReader },
                    { NotFoundException, ChecksumException, FormatException },
                ] = await Promise.all([import("@zxing/browser"), import("@zxing/library")]);

                if (disposed || session.stopped) return;

                const video = this.$el.querySelector("video");
                const reader = new BrowserQRCodeReader();

                const controls = await reader.decodeFromConstraints(
                    { video: { facingMode: { ideal: "environment" } }, audio: false },
                    video,
                    (result, error, callbackControls) => {
                        // clean up and ignore callbacks from an aborted session
                        if (disposed || session.stopped) {
                            releaseCameraSession(session, callbackControls);
                            return;
                        }

                        // stop callback controls even when the SDK has not returned its wrapper yet
                        if (result) {
                            session.controls ??= callbackControls;
                            this.stopCamera();
                            onResult(result.getText());
                            return;
                        }

                        // ignore normal scan misses (no qr in frame yet)
                        if (
                            !error ||
                            error instanceof NotFoundException ||
                            error instanceof ChecksumException ||
                            error instanceof FormatException
                        ) {
                            return;
                        }

                        // handle fatal SDK errors
                        session.controls ??= callbackControls;
                        this.stopCamera();
                        this.error = CameraError.INITIALIZATION;
                    },
                );

                // abort if the user stopped the camera while waiting for permission
                if (disposed || session.stopped) {
                    releaseCameraSession(session, controls);
                    return;
                }

                session.controls = controls;
                this.active = true;
            } catch (error) {
                if (disposed || session.stopped) return;
                this.stopCamera();
                this.error = mapCameraFailure(error);
            } finally {
                if (currentSession === session) this.starting = false;
                finishStartup();
            }
        },

        /** Stops the camera and clears the session. */
        stopCamera() {
            this.active = false;
            this.starting = false;

            if (currentSession) {
                currentSession.stopped = true;
                releaseCameraSession(currentSession, currentSession.controls);
                currentSession = null;
            }
        },

        /** Releases resources when Alpine removes the actual camera root. */
        destroy() {
            disposed = true;
            this.stopCamera();
        },
    };
}

/** Releases one SDK session through either its callback controls or its returned wrapper, never both.
 *
 * @param {object} session Private resource ownership shared by startup and callbacks.
 * @param {object|null} controls SDK controls when permission or the first callback has completed.
 */
function releaseCameraSession(session, controls) {
    if (!controls || session.released) return;

    session.released = true;
    controls.stop();
}

/** Translates native startup failures without retaining private messages or vendor errors.
 *
 * @param {unknown} error Native failure used only for its safe classification.
 * @returns {string} Closed camera feedback reason.
 */
function mapCameraFailure(error) {
    switch (error?.name) {
        case "NotAllowedError":
        case "SecurityError":
            return CameraError.DENIED;
        case "NotReadableError":
        case "AbortError":
            return CameraError.BUSY;
        case "NotFoundError":
        case "OverconstrainedError":
            return CameraError.UNAVAILABLE;
        default:
            return CameraError.INITIALIZATION;
    }
}
