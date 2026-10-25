/**
 * @typedef {{stop: () => void}} CameraControls
 * @typedef {{stopped: boolean, controls: CameraControls|null, released: boolean}} CameraSession
 * @typedef {{DENIED: string, BUSY: string, UNAVAILABLE: string, INITIALIZATION: string}} CameraFailureCodes
 * @typedef {{active: boolean, starting: boolean, error: string, startCamera: () => Promise<void>, stopCamera: () => void, destroy: () => void}} CameraScanner
 */

/**
 * Creates one Alpine camera owner with private SDK resources and serialized startup cleanup.
 *
 * @param {(token: string) => void} onResult Called with the exact QR token after capture stops.
 * @param {CameraFailureCodes} cameraFailures Existing application codes for camera errors.
 * @returns {CameraScanner} Public Alpine state and lifecycle methods for this camera instance.
 */
export function qrCameraScanner(onResult, cameraFailures) {
    // track the current capture and serialize startup cleanup across sessions
    let disposed = false;
    /** @type {CameraSession|null} */
    let currentSession = null;
    let pendingStartup = Promise.resolve();

    return {
        active: false,
        starting: false,
        error: "",

        /**
         * Starts one session after any previous SDK startup has released its video resources.
         * Reports unsupported environments and startup failures through Alpine state and events.
         *
         * @returns {Promise<void>} Resolves after capture starts or its startup is cancelled/handled.
         */
        async startCamera() {
            // ignore duplicate starts and requests after Alpine disposal
            if (disposed || this.starting || this.active) {
                return;
            }

            this.starting = true;
            this.error = "";

            // reject unsupported or insecure camera environments before loading ZXing
            if (!window.isSecureContext || !window.navigator.mediaDevices?.getUserMedia) {
                this.starting = false;
                this.error = cameraFailures.UNAVAILABLE;
                this.$dispatch("qr-camera-fatal-error", {
                    code: this.error,
                    type: "capability",
                });
                return;
            }

            // reserve this session and wait for the previous startup to release the video
            const session = { stopped: false, controls: null, released: false };
            currentSession = session;
            const previousStartup = pendingStartup;
            let finishStartup;
            pendingStartup = new Promise((resolve) => {
                finishStartup = resolve;
            });

            // start capture; only failures from the still-active session reach the UI
            try {
                await startSdkCapture(this, session, previousStartup);
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
                // unblock the next startup even when this one failed or was cancelled
                if (currentSession === session) {
                    this.starting = false;
                }

                finishStartup();
            }
        },

        /**
         * Stops the current session and releases SDK controls at most once.
         * Does not disable a later explicit start.
         *
         * @returns {void}
         */
        stopCamera() {
            // invalidate the session before releasing its controls
            this.active = false;
            this.starting = false;

            if (currentSession) {
                currentSession.stopped = true;
                releaseCameraSession(currentSession, currentSession.controls);
                currentSession = null;
            }
        },

        /**
         * Permanently disposes the Alpine camera owner and releases its current session.
         *
         * @returns {void}
         */
        destroy() {
            disposed = true;
            this.stopCamera();
        },
    };

    /**
     * Starts ZXing capture after prior startup cleanup, then owns any returned controls.
     * Late SDK results cannot reactivate a stopped or disposed session.
     *
     * @param {CameraScanner & {$el: Element, $dispatch: Function}} scanner Alpine owner and video container.
     * @param {CameraSession} session Identity and controls belonging to this startup only.
     * @param {Promise<void>} previousStartup Completion of the preceding SDK startup.
     * @returns {Promise<void>} Resolves when startup or cancellation cleanup finishes.
     * @throws {Error} Propagates SDK import, startup, or synchronous callback errors for classification.
     */
    async function startSdkCapture(scanner, session, previousStartup) {
        // previous SDK startup must finish before the same video element is reused
        await previousStartup;

        if (disposed || session.stopped) {
            return;
        }

        // load ZXing only when the session is still valid
        const [{ BrowserQRCodeReader }, { NotFoundException, ChecksumException, FormatException }] =
            await Promise.all([import("@zxing/browser"), import("@zxing/library")]);

        if (disposed || session.stopped) {
            return;
        }

        // bind the decoder to the visible, center-cropped camera preview
        const video = scanner.$el.querySelector("video");
        const reader = createPreviewReader(BrowserQRCodeReader, NotFoundException, video);
        const controls = await reader.decodeFromConstraints(
            {
                video: { facingMode: { ideal: "environment" } },
                audio: false,
            },
            video,
            handleDecodedFrame,
        );

        // a late permission or SDK response must not revive a stopped session
        if (disposed || session.stopped) {
            releaseCameraSession(session, controls);
            return;
        }

        session.controls = controls;
        scanner.active = true;

        /**
         * Handles one ZXing callback while discarding stale frames and ordinary QR misses.
         * Stops capture before forwarding a token or notifying an unexpected decode failure.
         *
         * @param {{getText: () => string}|null} result Decoded QR result, if present.
         * @param {unknown} error ZXing decoding failure; normal misses are ignored.
         * @param {CameraControls} callbackControls Controls supplied with this SDK callback.
         * @returns {void}
         */
        function handleDecodedFrame(result, error, callbackControls) {
            // obsolete callbacks can only release their own SDK controls
            if (disposed || session.stopped) {
                releaseCameraSession(session, callbackControls);
                return;
            }

            // a valid QR stops capture before passing its exact token to the caller
            if (result) {
                session.controls ??= callbackControls;
                scanner.stopCamera();
                onResult(result.getText());
                return;
            }

            // normal decoding misses do not interrupt the camera
            if (
                !error ||
                error instanceof NotFoundException ||
                error instanceof ChecksumException ||
                error instanceof FormatException
            ) {
                return;
            }

            // unexpected decoding errors stop capture and notify the modal
            session.controls ??= callbackControls;
            scanner.stopCamera();
            scanner.error = cameraFailures.INITIALIZATION;
            scanner.$dispatch("qr-camera-fatal-error", {
                code: scanner.error,
                type: "decode",
            });
        }
    }
}

/**
 * Creates a ZXing reader that decodes the same centered cover crop shown in the preview.
 * Owns one reusable canvas for this reader instance.
 *
 * @param {typeof import("@zxing/browser").BrowserQRCodeReader} BrowserQRCodeReader ZXing reader constructor.
 * @param {typeof import("@zxing/library").NotFoundException} NotFoundException Expected missing-frame exception.
 * @param {HTMLVideoElement} video Video element used for the preview and decoded frames.
 * @returns {import("@zxing/browser").BrowserQRCodeReader} Reader restricted to visible preview pixels.
 */
function createPreviewReader(BrowserQRCodeReader, NotFoundException, video) {
    let previewCanvas;

    class PreviewQRCodeReader extends BrowserQRCodeReader {
        /**
         * Extracts the current visible video crop and decodes that canvas with ZXing.
         * Recomputes the crop for each frame so orientation and layout changes are respected.
         *
         * @returns {import("@zxing/library").Result} Decoded QR result from the visible area.
         * @throws {import("@zxing/library").NotFoundException} When video or preview dimensions are unavailable.
         * @throws {Error} When a drawing context is unavailable or ZXing cannot decode the frame.
         */
        decodeFromCanvas() {
            const bounds = video.getBoundingClientRect();
            const { videoWidth, videoHeight } = video;

            if (!bounds.width || !bounds.height || !videoWidth || !videoHeight) {
                throw new NotFoundException();
            }

            // translate the visible CSS cover crop into source-video coordinates
            const scale = Math.max(bounds.width / videoWidth, bounds.height / videoHeight);
            const width = bounds.width / scale;
            const height = bounds.height / scale;
            // reuse one canvas and refresh its pixels for each frame
            const canvas = (previewCanvas ??= video.ownerDocument.createElement("canvas"));
            canvas.width = Math.round(width);
            canvas.height = Math.round(height);
            const context = canvas.getContext("2d", { willReadFrequently: true });

            if (!context) {
                throw new Error("Camera preview canvas is unavailable");
            }

            context.drawImage(
                video,
                (videoWidth - width) / 2,
                (videoHeight - height) / 2,
                width,
                height,
                0,
                0,
                canvas.width,
                canvas.height,
            );

            return super.decodeFromCanvas(canvas);
        }
    }

    return new PreviewQRCodeReader();
}

/**
 * Releases the first available SDK controls exactly once for their owning session.
 * Handles controls returned after cancellation without stopping a later session.
 *
 * @param {CameraSession} session Session that owns the SDK controls.
 * @param {CameraControls|null} controls Controls returned by startup or delivered in a callback.
 * @returns {void}
 */
function releaseCameraSession(session, controls) {
    if (!controls || session.released) {
        return;
    }

    // either the callback or the startup return may release the camera, never both
    session.released = true;
    controls.stop();
}

/**
 * Classifies browser camera failures without exposing native messages or error details.
 *
 * @param {unknown} error Native browser or SDK startup failure.
 * @param {CameraFailureCodes} cameraFailures Application codes supplied by the caller.
 * @returns {string} Existing public camera failure code for the UI.
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
