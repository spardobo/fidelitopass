/**
 * @typedef {{stop: () => void}} CameraControls
 * @typedef {{stopped: boolean, controls: CameraControls|null, released: boolean}} CameraSession
 * @typedef {{DENIED: string, BUSY: string, UNAVAILABLE: string, INITIALIZATION: string}} CameraFailureCodes
 */

// -----------------------------------------------------------------------------
// scanner lifecycle
// -----------------------------------------------------------------------------

/**
 * Creates one Alpine camera owner with private SDK resources and serialized startup cleanup.
 *
 * @param {(token: string) => void} onResult Receives the exact token after owned capture stops.
 * @param {CameraFailureCodes} cameraFailures Backend-generated camera feedback codes.
 * @returns {object} Local camera controls; Alpine calls destroy when its root is removed.
 */
export function qrCameraScanner(onResult, cameraFailures) {
    let disposed = false;
    /** @type {CameraSession|null} */
    let currentSession = null;
    let pendingStartup = Promise.resolve();

    /**
     * Opens the SDK decoder after an obsolete startup has finished cleaning the shared video.
     *
     * @param {object} scanner Alpine camera instance owning presentation and DOM access.
     * @param {CameraSession} session Resources belonging to this startup only.
     * @param {Promise<void>} previousStartup Completion of the preceding startup's cleanup.
     * @returns {Promise<void>} Resolves after startup or disposal releases late SDK controls.
     * @throws {Error} Propagates SDK loading and startup failures to startCamera for classification.
     */
    async function decodeCameraSession(scanner, session, previousStartup) {
        await previousStartup;

        if (disposed || session.stopped) {
            return;
        }

        const [{ BrowserQRCodeReader }, { NotFoundException, ChecksumException, FormatException }] =
            await Promise.all([import("@zxing/browser"), import("@zxing/library")]);

        if (disposed || session.stopped) {
            return;
        }

        const video = scanner.$el.querySelector("video");
        const reader = new BrowserQRCodeReader();

        /**
         * Handles an owned frame while ignoring normal scan misses and obsolete callbacks.
         *
         * @param {{getText: () => string}|null} result Decoded token, or no result for this frame.
         * @param {unknown} error Native decoding failure used only for expected-miss classification.
         * @param {CameraControls} callbackControls SDK controls available before startup may return.
         * @returns {void} Stops capture before delivering a token or presenting a fatal decode error.
         */
        function handleDecodedFrame(result, error, callbackControls) {
            if (disposed || session.stopped) {
                releaseCameraSession(session, callbackControls);
                return;
            }

            if (result) {
                session.controls ??= callbackControls;
                scanner.stopCamera();
                onResult(result.getText());
                return;
            }

            if (
                !error ||
                error instanceof NotFoundException ||
                error instanceof ChecksumException ||
                error instanceof FormatException
            ) {
                return;
            }

            session.controls ??= callbackControls;
            scanner.stopCamera();
            scanner.error = cameraFailures.INITIALIZATION;
            scanner.$dispatch("qr-camera-fatal-error", {
                code: scanner.error,
                type: "decode",
            });
        }

        const controls = await reader.decodeFromConstraints(
            {
                video: { facingMode: { ideal: "environment" } },
                audio: false,
            },
            video,
            handleDecodedFrame,
        );

        // permission or decoding may finish after this session has already been stopped
        if (disposed || session.stopped) {
            releaseCameraSession(session, controls);
            return;
        }

        session.controls = controls;
        scanner.active = true;
    }

    return {
        active: false,
        starting: false,
        error: "",

        /**
         * Starts one local SDK session and presents only classified capability or startup failures.
         *
         * @returns {Promise<void>} Settles after startup or cancellation releases late controls.
         */
        async startCamera() {
            if (disposed || this.starting || this.active) {
                return;
            }

            this.starting = true;
            this.error = "";

            if (!window.isSecureContext || !window.navigator.mediaDevices?.getUserMedia) {
                this.starting = false;
                this.error = cameraFailures.UNAVAILABLE;
                this.$dispatch("qr-camera-fatal-error", {
                    code: this.error,
                    type: "capability",
                });
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
                await decodeCameraSession(this, session, previousStartup);
            } catch (error) {
                if (disposed || session.stopped) {
                    return;
                }

                this.stopCamera();
                this.error = mapCameraFailure(error, cameraFailures);
                this.$dispatch("qr-camera-fatal-error", {
                    code: this.error,
                    type: "startup",
                });
            } finally {
                if (currentSession === session) {
                    this.starting = false;
                }

                finishStartup();
            }
        },

        /**
         * Stops capture and releases controls once while permitting a later explicit startup.
         *
         * @returns {void}
         */
        stopCamera() {
            this.active = false;
            this.starting = false;

            if (currentSession) {
                currentSession.stopped = true;
                releaseCameraSession(currentSession, currentSession.controls);
                currentSession = null;
            }
        },

        /**
         * Releases capture and makes future startup requests inert when Alpine removes the root.
         *
         * @returns {void}
         */
        destroy() {
            disposed = true;
            this.stopCamera();
        },
    };
}

// -----------------------------------------------------------------------------
// SDK resources and failure classification
// -----------------------------------------------------------------------------

/**
 * Releases callback controls or returned startup controls for a session, never both.
 *
 * @param {CameraSession} session Private ownership shared by startup and frame callbacks.
 * @param {CameraControls|null} controls SDK controls, or null while permission is unresolved.
 * @returns {void}
 */
function releaseCameraSession(session, controls) {
    if (!controls || session.released) {
        return;
    }

    session.released = true;
    controls.stop();
}

/**
 * Maps native startup failures without retaining messages or vendor payloads.
 *
 * @param {unknown} error Native failure inspected only for its safe name.
 * @param {CameraFailureCodes} cameraFailures Backend-generated camera feedback codes.
 * @returns {string} Existing camera feedback identity.
 */
function mapCameraFailure(error, cameraFailures) {
    switch (error?.name) {
        case "NotAllowedError":
        case "SecurityError":
            return cameraFailures.DENIED;
        case "NotReadableError":
        case "AbortError":
            return cameraFailures.BUSY;
        case "NotFoundError":
        case "OverconstrainedError":
            return cameraFailures.UNAVAILABLE;
        default:
            return cameraFailures.INITIALIZATION;
    }
}
