// closed set of local visit states
const VisitPhase = Object.freeze({
    IDLE: "idle",
    READY: "ready",
    PENDING: "pending",
    UNKNOWN: "unknown",
    SETTLED: "settled",
});

/** Registers root-owned camera, private credentials and uncertainty.
 *
 * @param {object} copy Safe translated transport feedback.
 * @returns {object} Browser lifecycle and native Livewire action handlers.
 */
Alpine.data("visitRegistration", (copy = {}) => {
    let disposed = false;
    let root;
    let wire;
    let removeListeners = () => {};
    let removeInterceptor = () => {};
    let currentRead = null;
    let operation = null;
    let deferredToast = null;

    return {
        // state
        opened: false,
        cameraEnabled: false,
        busy: false,
        transportError: "",

        visitPhase: VisitPhase.IDLE,

        /** Enables automatic text entry only for an ordinary desktop pointer. */
        get manualAutofocus() {
            return Boolean(window.matchMedia?.("(hover: hover) and (pointer: fine)")?.matches);
        },

        // ------------------------------------------------------------------------
        // lifecycle
        // ------------------------------------------------------------------------

        /** Binds native lifecycle events and Livewire interceptors. */
        init() {
            root = this.$el;
            wire = this.$wire;
            this.setupLivewireInterceptors();
            this.setupModalListeners();
        },

        /** Binds Flux modal events and page navigation to the component lifecycle. */
        setupModalListeners() {
            const dialog = root.querySelector("dialog");
            const document = root.ownerDocument;

            // triggers when Flux opens the modal to resume or reset the ui
            const onShow = (event) => {
                if (event.detail.name === "visit-registration") this.handleModalShow();
            };

            // triggers when the modal closes to clear sensitive data and focus
            const onClose = () => {
                this.handleModalClose();
                this.restoreFocusToTrigger();
            };

            // triggers on spa navigation to release memory and stop the camera
            const onNavigate = () => this.destroy();

            // bind global modal and navigation events
            document.addEventListener("modal-show", onShow);
            document.addEventListener("livewire:navigating", onNavigate);
            dialog.addEventListener("cancel", onClose);
            dialog.addEventListener("close", onClose);

            // package the cleanup so it can be called on destroy
            removeListeners = () => {
                document.removeEventListener("modal-show", onShow);
                document.removeEventListener("livewire:navigating", onNavigate);
                dialog.removeEventListener("cancel", onClose);
                dialog.removeEventListener("close", onClose);
            };
        },

        /** Returns focus to the button that opened the modal for accessibility. */
        restoreFocusToTrigger() {
            const trigger = root.ownerDocument.getElementById("register-visit-trigger");
            if (trigger?.getClientRects().length) {
                trigger.focus({ preventScroll: true });
            }
        },

        /** Runs when the modal opens (NOT the camera). */
        handleModalShow() {
            if (disposed || this.opened) return;
            this.opened = true;

            // native facts may have merged just before a receipt was cancelled
            if (operation && wire.facts && this.visitPhase !== VisitPhase.PENDING) {
                this.visitPhase = VisitPhase.SETTLED;
            }

            if (deferredToast) {
                this.announceFailure(deferredToast);
                deferredToast = null;
            }

            if (this.visitPhase === VisitPhase.SETTLED && !wire.details) {
                void this.findVisitReceipt();
            }

            // sync local state with backend if the modal was force-closed
            if (this.visitPhase === VisitPhase.IDLE && wire.stage !== "identify") {
                void this.reset();
            }

            this.focusStage(true);
        },

        /** Runs when the modal closes. */
        handleModalClose() {
            this.opened = false;
            this.cancelRead();
            this.stopCamera();

            // clear the transient credential input
            const input = root.querySelector("#visit-manual-code");
            if (input) input.value = "";

            if (this.visitPhase === VisitPhase.READY) {
                operation = null;
                this.visitPhase = VisitPhase.IDLE;
            }
        },

        /** Cleans up on root destruction. */
        destroy() {
            if (disposed) return;

            this.handleModalClose();
            disposed = true;
            operation = null;
            deferredToast = null;
            this.busy = false;
            this.visitPhase = VisitPhase.IDLE;
            removeListeners();
            removeInterceptor();
        },

        // ------------------------------------------------------------------------
        // camera controls
        // ------------------------------------------------------------------------

        /** Turns on the camera. */
        requestCameraStart() {
            if (disposed || this.busy || !this.opened || wire.stage !== "identify") return;

            this.cameraEnabled = true;

            // notify the scanner component to start capture
            this.$nextTick(() => {
                if (
                    !disposed &&
                    this.opened &&
                    this.cameraEnabled &&
                    !this.busy &&
                    wire.stage === "identify"
                ) {
                    root.querySelector("[data-qr-camera]")?.dispatchEvent(
                        new Event("qr-camera-start"),
                    );
                }
            });
        },

        /** Releases capture without revealing manual entry during a camera lookup.
         *
         * @param {boolean} [preserveMode=false] Retains the selected camera presentation until explicit return.
         */
        stopCamera(preserveMode = false) {
            if (!preserveMode) {
                const input = root.querySelector("#visit-manual-code");
                if (this.cameraEnabled && input) input.value = "";
                this.cameraEnabled = false;
            }
            // notify the scanner component to stop capture
            root.querySelector("[data-qr-camera]")?.dispatchEvent(new Event("qr-camera-stop"));
        },

        // ------------------------------------------------------------------------
        // identification flow
        // ------------------------------------------------------------------------

        /** Cancels any read if the user types a new code. */
        manualChanged() {
            if (disposed || this.visitPhase !== VisitPhase.IDLE) return;

            if (currentRead?.name === "findPass") this.cancelRead();
        },

        /** Submits the manual input.
         *
         * @returns {Promise<void>} Settles the read without exposing its credential to reactive state.
         */
        async findPassManually() {
            await this.findPass("manual", root.querySelector("#visit-manual-code")?.value ?? "");
        },

        /** Calls Livewire to find the pass.
         *
         * @param {string} method Private credential method revalidated by the server.
         * @param {string} credential Exact private action argument, never Alpine state.
         * @returns {Promise<void>} Settles only the current identification read.
         */
        async findPass(method, credential) {
            if (
                disposed ||
                this.busy ||
                !this.opened ||
                wire.stage !== "identify" ||
                this.visitPhase !== VisitPhase.IDLE
            )
                return;

            this.transportError = "";
            const read = { name: "findPass", cancel: null };
            currentRead = read;
            this.busy = true;
            this.stopCamera(method === "token");

            try {
                const operationId = await wire.findPass(method, credential);
                if (disposed || currentRead !== read) return;

                if (operationId) {
                    operation = {
                        uuid: operationId,
                        method,
                        credential,
                        promotionId: wire.preview.promotion?.id,
                    };
                    this.visitPhase = VisitPhase.READY;
                }
            } catch {
                if (!disposed && currentRead === read) this.announceFailure(copy.read_unavailable);
            } finally {
                if (currentRead === read) {
                    currentRead = null;
                    this.busy = false;
                    this.focusStage();
                }
            }
        },

        /** Resets the UI back to identify.
         *
         * @param {boolean} [preserveCameraMode=false] Keeps deactivation visible while its native reset is pending.
         * @returns {Promise<void>} Clears a resolved intent through the native rendered stage owner.
         */
        async reset(preserveCameraMode = false) {
            if (
                disposed ||
                this.busy ||
                [VisitPhase.PENDING, VisitPhase.UNKNOWN].includes(this.visitPhase)
            )
                return;

            this.transportError = "";
            const read = { name: "resetIdentification", cancel: null };
            currentRead = read;
            operation = null;
            deferredToast = null;
            this.busy = true;
            this.stopCamera(preserveCameraMode);
            const input = root.querySelector("#visit-manual-code");
            if (input) input.value = "";
            this.visitPhase = VisitPhase.IDLE;

            try {
                await wire.resetIdentification();
            } catch {
                if (!disposed && currentRead === read) this.announceFailure(copy.read_unavailable);
            } finally {
                if (currentRead === read) {
                    currentRead = null;
                    this.busy = false;
                    if (preserveCameraMode) this.cameraEnabled = false;
                    this.focusStage(true);
                }
            }
        },

        // ------------------------------------------------------------------------
        // mutation flow
        // ------------------------------------------------------------------------

        /** Sends one explicit visit, or resolves the same unknown operation read-only.
         *
         * @param {number} [renderedPromotionId] Server-rendered offer when the reactive hint was unavailable.
         * @returns {Promise<void>} Settles the captured operation even while the modal is closed.
         */
        async acceptVisit(renderedPromotionId) {
            if (disposed || this.busy || !this.opened || !operation) return;
            if (this.visitPhase === VisitPhase.UNKNOWN) {
                await this.findVisitReceipt();
                return;
            }
            if (this.visitPhase !== VisitPhase.READY || !wire.preview?.can_accept_visit) return;

            this.transportError = "";
            const submitted = operation;
            submitted.promotionId ??= renderedPromotionId;
            this.busy = true;
            this.visitPhase = VisitPhase.PENDING;
            this.stopCamera();

            try {
                const outcome = await wire.acceptVisit(
                    submitted.method,
                    submitted.credential,
                    submitted.uuid,
                    submitted.promotionId,
                );
                if (disposed || operation !== submitted) return;

                this.visitPhase =
                    outcome === "confirmed"
                        ? VisitPhase.SETTLED
                        : outcome === "rejected"
                          ? VisitPhase.READY
                          : VisitPhase.UNKNOWN;
            } catch {
                if (disposed || operation !== submitted) return;

                this.visitPhase = VisitPhase.UNKNOWN;
                this.announceFailure(copy.unknown);
            } finally {
                if (!disposed && operation === submitted) this.busy = false;
            }

            if (disposed || operation !== submitted || !this.opened) return;

            this.focusStage();
            if (this.visitPhase === VisitPhase.SETTLED) await this.findVisitReceipt();
        },

        /** Reads the same operation for uncertainty or optional committed details.
         *
         * @returns {Promise<void>} Refreshes native facts without repeating acceptance or inferring rollback.
         */
        async findVisitReceipt() {
            if (
                disposed ||
                this.busy ||
                !this.opened ||
                !operation ||
                ![VisitPhase.UNKNOWN, VisitPhase.SETTLED].includes(this.visitPhase)
            )
                return;

            this.transportError = "";
            const submitted = operation;
            const previousStage = wire.stage;
            const read = { name: "findVisitReceipt", cancel: null };
            currentRead = read;
            this.busy = true;

            try {
                await wire.findVisitReceipt(submitted.method, submitted.credential, submitted.uuid);
                if (disposed || currentRead !== read || operation !== submitted) return;

                if (wire.facts) this.visitPhase = VisitPhase.SETTLED;
                if (wire.stage !== previousStage || !wire.facts) this.focusStage();
            } catch {
                if (!disposed && currentRead === read && operation === submitted) {
                    this.announceFailure(
                        this.visitPhase === VisitPhase.SETTLED
                            ? copy.details_unavailable
                            : copy.unknown,
                    );
                    if (!wire.facts) this.focusStage();
                }
            } finally {
                if (currentRead === read) {
                    currentRead = null;
                    this.busy = false;
                }
            }
        },

        // ------------------------------------------------------------------------
        // helpers
        // ------------------------------------------------------------------------

        /** Binds native snapshot cancellation to the owning read without cancelling mutations. */
        setupLivewireInterceptors() {
            removeInterceptor = wire.intercept(({ action, onError, onFinish }) => {
                const intercepted = [
                    "findPass",
                    "findVisitReceipt",
                    "resetIdentification",
                    "acceptVisit",
                ];
                if (!intercepted.includes(action.name)) return;

                onError(({ preventDefault }) => preventDefault());
                if (action.name === "acceptVisit") return;

                // bind cancellation to the exact read, not a successor's busy flag
                const read = currentRead;
                if (disposed || !read || read.name !== action.name) {
                    action.cancel();
                    return;
                }

                read.cancel = () => action.cancel();
                onFinish(() => {
                    read.cancel = null;
                });
            });
        },

        /** Invalidates replaceable reads before cancelling their native snapshot/render effects. */
        cancelRead() {
            const read = currentRead;
            if (!read) return;

            currentRead = null;
            this.busy = false;
            read.cancel?.();
        },

        /** Presents safe transport feedback now or on reopening the same operation.
         *
         * @param {string} text Translated feedback without private action arguments.
         */
        announceFailure(text) {
            if (disposed) return;

            this.transportError = text;
            if (this.opened) {
                this.$flux.toast({
                    variant: "danger",
                    text: wire.facts ? copy.details_notice : copy.error_notice,
                });
            } else deferredToast = text;
        },

        /** Focuses a contextual action, avoiding automatic touch-device text entry.
         *
         * @param {boolean} [entering=false] Marks modal opening or return to manual identification.
         */
        focusStage(entering = false) {
            // defer focus to the next render cycle
            this.$nextTick(() => {
                if (!disposed && this.opened) {
                    const selector =
                        wire.stage === "identify"
                            ? this.cameraEnabled
                                ? "[data-visit-camera-control]"
                                : entering && !this.manualAutofocus
                                  ? "[data-visit-close]"
                                  : "#visit-manual-code"
                            : wire.stage === "confirm"
                              ? wire.preview?.can_accept_visit
                                  ? "[data-visit-confirm]"
                                  : "[data-visit-back]"
                              : "[data-visit-close]";
                    root.querySelector(selector)?.focus({ preventScroll: true });
                }
            });
        },
    };
});
