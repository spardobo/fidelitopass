/**
 * @typedef {object} VisitFailureCatalog
 * @property {Object<string, {message: string, notice: string}>} errors Server-translated feedback by enum value.
 * @property {{network: string, transport: string, read: string, unknown: string, details: string, rewardDetails: string}} codes Server-owned aliases.
 */

/**
 * Classifies bounded transport evidence without retaining native payloads or deciding the visit outcome.
 *
 * @param {unknown} error Native rejection inspected only for its HTTP status.
 * @param {VisitFailureCatalog['codes']} codes Server-owned feedback aliases.
 * @param {string} fallbackCode Contextual feedback for a failed read, receipt or submission.
 * @param {boolean} isOffline Whether the browser currently reports no network connection.
 * @returns {{feedbackCode: string, code: string, status: number|null, type: string}} Safe copy selection and telemetry.
 */
function classifyVisitTransportFailure(error, codes, fallbackCode, isOffline) {
    // a null status can also mean cancellation; the caller excludes cancelled requests first
    const isNetworkFailure = error?.status === null;
    const status =
        Number.isInteger(error?.status) && error.status >= 100 && error.status <= 599
            ? error.status
            : null;

    let type = "http";

    if (isNetworkFailure) {
        type = "network";
    } else if (status === null) {
        type = "runtime";
    }

    return {
        feedbackCode: isOffline || isNetworkFailure ? codes.network : fallbackCode,
        code: isNetworkFailure ? codes.network : codes.transport,
        status,
        type,
    };
}

/**
 * Binds the modal's DOM events to its owning Alpine screen and returns their matching cleanup.
 *
 * @param {HTMLElement} root Fieldset owning the Flux dialog and its bubbling events.
 * @param {object} screen Visit screen exposing its modal handlers and reactive presentation flags.
 * @returns {() => void} Cleanup for these listeners, called when the Alpine owner is destroyed.
 */
function bindVisitRegistrationModal(root, screen) {
    const document = root.ownerDocument;

    /**
     * Opens this screen only when Flux announces its own modal.
     *
     * @param {CustomEvent} event Flux modal-show event.
     * @returns {void}
     */
    function onShow(event) {
        if (event.detail.name === "visit-registration") {
            screen.handleModalShow();
        }
    }

    /**
     * Prevents native cancellation while an owned request is pending.
     *
     * @param {Event} event Dialog cancel event captured by the fieldset.
     * @returns {void}
     */
    function onCancel(event) {
        if (
            event.target === root.querySelector("dialog") &&
            (screen.busy || screen.cameraStarting)
        ) {
            event.preventDefault();
        }
    }

    /**
     * Blocks Flux close controls, including nested icons, while the screen is busy.
     *
     * @param {MouseEvent} event Click captured before the close control receives it.
     * @returns {void}
     */
    function onDismissClick(event) {
        if (
            (screen.busy || screen.cameraStarting) &&
            event.target?.closest?.("[data-flux-modal-close]")
        ) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }

    /**
     * Tracks keyboard focus and prevents Escape from dismissing an active request.
     *
     * @param {KeyboardEvent} event Document keydown event captured before Flux handles it.
     * @returns {void}
     */
    function onKeyDown(event) {
        if (screen.opened || event.target?.closest?.("#register-visit-trigger")) {
            screen.focusModality = "keyboard";
        }

        if (screen.opened && (screen.busy || screen.cameraStarting) && event.key === "Escape") {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }

    /**
     * Tracks pointer focus only for this modal or its trigger.
     *
     * @param {PointerEvent} event Document pointerdown event.
     * @returns {void}
     */
    function onPointerDown(event) {
        if (screen.opened || event.target?.closest?.("#register-visit-trigger")) {
            screen.focusModality = "pointer";
        }
    }

    /**
     * Releases the screen and restores focus after this dialog actually closes.
     *
     * @param {Event} event Native dialog close event.
     * @returns {void}
     */
    function onClose(event) {
        if (event.target === root.querySelector("dialog")) {
            screen.handleDialogClose();
        }
    }

    /**
     * Prevents late server notices from escaping a hidden or destroyed screen.
     *
     * @param {CustomEvent} event Server toast event bubbling to Flux's document listener.
     * @returns {void}
     */
    function onToast(event) {
        if (event.target === root && !screen.canPresentFeedback) {
            event.stopPropagation();
        }
    }

    /**
     * Destroys the screen before SPA navigation releases its DOM.
     *
     * @returns {void}
     */
    function onNavigate() {
        screen.destroy();
    }

    document.addEventListener("modal-show", onShow);
    document.addEventListener("livewire:navigating", onNavigate);
    document.addEventListener("keydown", onKeyDown, { capture: true });
    document.addEventListener("pointerdown", onPointerDown, { capture: true });
    root.addEventListener("click", onDismissClick, { capture: true });
    root.addEventListener("cancel", onCancel, { capture: true });
    root.addEventListener("close", onClose, { capture: true });
    root.addEventListener("toast-show", onToast);

    /**
     * Removes exactly the listeners installed by this modal binding.
     *
     * @returns {void}
     */
    return function removeModalListeners() {
        document.removeEventListener("modal-show", onShow);
        document.removeEventListener("livewire:navigating", onNavigate);
        document.removeEventListener("keydown", onKeyDown, { capture: true });
        document.removeEventListener("pointerdown", onPointerDown, { capture: true });
        root.removeEventListener("click", onDismissClick, { capture: true });
        root.removeEventListener("cancel", onCancel, { capture: true });
        root.removeEventListener("close", onClose, { capture: true });
        root.removeEventListener("toast-show", onToast);
    };
}

/**
 * @typedef {object} VisitSelection
 * @property {string} uuid Intentional Visit identity retained while its outcome is unknown.
 * @property {'visit'|'reward'} [kind] Original operation family.
 * @property {boolean} [combined] Whether this Visit also requested Reward redemption.
 * @property {number} [entitlementId] Original entitlement for naturally idempotent redemption.
 * @property {string} method Credential method revalidated by the server.
 * @property {string} credential Private action argument retained only in this closure.
 * @property {number|undefined} promotionId Selected offer comparison, never mutation authority.
 * @property {boolean} [cancelled] Whether Livewire cancelled the captured submission.
 *
 * @typedef {object} VisitRead
 * @property {string} name Native Livewire action name.
 * @property {(() => void)|null} cancel Cancellation handle for this exact read.
 * @property {boolean} [cancelled] Whether Livewire cancelled this read.
 */

// closed set of local visit states
const VISIT_PHASE = Object.freeze({
    IDLE: "idle",
    READY: "ready",
    PENDING: "pending",
    UNKNOWN: "unknown",
    SETTLED: "settled",
});

/**
 * Registers root-owned capture, private credentials and the submission lifecycle.
 *
 * @param {VisitFailureCatalog} failures Backend-generated feedback.
 * @returns {object} Browser lifecycle and native Livewire action handlers.
 */
Alpine.data("visitRegistration", ({ errors, codes }) => {
    let isDisposed = false;
    let root;
    let wire;
    let removeListeners = () => {};
    let removeInterceptor = () => {};
    let removeCloseBinding = () => {};
    /** @type {VisitRead|null} */
    let activeRead = null;
    /** @type {VisitSelection|null} */
    let selectedOperation = null;
    /** @type {VisitSelection|null} */
    let unresolvedVisit = null;

    return {
        opened: false,
        cameraEnabled: false,
        cameraFeedbackReady: false,
        busy: false,
        clientErrorCode: "",
        focusModality: "pointer",
        presentationReady: true,

        visitPhase: VISIT_PHASE.IDLE,

        /**
         * Binds the native modal lifecycle and the existing Livewire action interceptor.
         *
         * @returns {void}
         */
        init() {
            root = this.$el;
            wire = this.$wire;
            this.registerActionInterceptors();
            removeListeners = bindVisitRegistrationModal(root, this);
            const close = root.querySelector(
                "[data-flux-modal-close] button:not([data-visit-close])",
            );
            if (close) {
                removeCloseBinding = Alpine.bind(close, {
                    ":disabled": () => this.busy || this.cameraStarting,
                });
            }
        },

        /**
         * Releases the root, listeners, interceptor and all private credentials once.
         *
         * @returns {void}
         */
        destroy() {
            if (isDisposed) {
                return;
            }

            this.handleModalClose();
            isDisposed = true;
            selectedOperation = null;
            unresolvedVisit = null;
            this.busy = false;
            this.visitPhase = VISIT_PHASE.IDLE;
            removeListeners();
            removeInterceptor();
            removeCloseBinding();
        },

        // -----------------------------------------------------------------------------
        // derived presentation
        // -----------------------------------------------------------------------------

        /**
         * Resolves only backend-owned codes to safe persistent copy.
         *
         * @returns {string} Current client or server feedback, or no error.
         */
        get errorMessage() {
            if (wire.stage === "identify" && this.cameraEnabled && !this.cameraFeedbackReady) {
                return "";
            }

            return errors[this.clientErrorCode || wire.errorCode]?.message ?? "";
        },

        /**
         * Identifies whether this visible owner can publish client or server feedback.
         *
         * @returns {boolean} False after closing or destruction.
         */
        get canPresentFeedback() {
            return !isDisposed && this.opened;
        },

        /**
         * Enables automatic text entry only for an ordinary desktop pointer.
         *
         * @returns {boolean}
         */
        get manualAutofocus() {
            return Boolean(window.matchMedia?.("(hover: hover) and (pointer: fine)")?.matches);
        },

        /**
         * Reads the scanner's existing reactive startup truth without duplicating its lifecycle.
         *
         * @returns {boolean} Whether native permission or decoder startup is pending.
         */
        get cameraStarting() {
            const camera = root.querySelector("[data-qr-camera]");
            // observe parent mode before reading the later-initialized child scope
            return this.cameraEnabled && camera ? Boolean(Alpine.$data(camera).starting) : false;
        },

        /**
         * Identifies the return link's own reset rather than camera deactivation work.
         *
         * @returns {boolean} Whether this link owns the active reset.
         */
        get resettingReading() {
            return this.busy && activeRead?.name === "resetIdentification" && !this.cameraEnabled;
        },

        /**
         * Identifies the camera control's own deactivation request.
         *
         * @returns {boolean} Whether camera deactivation owns the reset.
         */
        get resettingCamera() {
            return this.busy && activeRead?.name === "resetIdentification" && this.cameraEnabled;
        },

        /**
         * Identifies the search control's own identification or prerequisite reconciliation.
         *
         * @returns {boolean} Whether the visible search owns a read.
         */
        get searchingPass() {
            return (
                this.busy &&
                wire.stage === "identify" &&
                ["findPass", "findVisitReceipt", "findRewardReceipt"].includes(activeRead?.name)
            );
        },

        /**
         * Distinguishes committed redemption from an older Visit result still on screen.
         *
         * @returns {boolean} Whether the selected operation itself has confirmed facts.
         */
        get hasCommittedOperation() {
            if (selectedOperation?.combined) {
                return Boolean(
                    wire.facts && (wire.combinedConfirmed || wire.redemption?.redeemed_at),
                );
            }
            return Boolean(selectedOperation?.kind === "reward" ? wire.redemption : wire.facts);
        },

        // -----------------------------------------------------------------------------
        // modal interaction
        // -----------------------------------------------------------------------------

        /**
         * Resumes an eligible selection or prepares clean identification when the modal opens.
         *
         * @returns {void}
         */
        handleModalShow() {
            if (isDisposed || this.opened) {
                return;
            }

            this.opened = true;

            // a closed-cycle reset cannot publish feedback into this new opening
            if (activeRead?.name === "resetIdentification") {
                this.cancelRead();
            }

            // a late response can settle while an unpreventable lifecycle close is hidden
            this.settleCommittedOperation();

            if (this.visitPhase === VISIT_PHASE.UNKNOWN) {
                unresolvedVisit = selectedOperation;
                selectedOperation = null;
                this.visitPhase = VISIT_PHASE.IDLE;
            } else if (this.visitPhase === VISIT_PHASE.SETTLED) {
                selectedOperation = null;
                this.visitPhase = VISIT_PHASE.IDLE;
            }

            this.clientErrorCode = "";
            if (
                this.visitPhase === VISIT_PHASE.IDLE &&
                (!this.presentationReady || wire.stage !== "identify" || wire.errorCode)
            ) {
                this.presentationReady = false;
                void this.reset();
                return;
            }

            this.focusStage(true);
        },

        /**
         * Handles an actual dialog close without cancelling a submitted visit.
         *
         * @returns {void} Releases reads, schedules an eligible reset and restores trigger focus.
         */
        handleDialogClose() {
            this.handleModalClose();

            if (this.visitPhase !== VISIT_PHASE.PENDING) {
                void this.reset();
            }

            this.restoreFocusToTrigger();
        },

        /**
         * Releases reads and camera capture while retaining unresolved submission identity.
         *
         * @returns {void}
         */
        handleModalClose() {
            this.opened = false;
            this.cancelRead();
            this.stopCamera();
            if (this.visitPhase !== VISIT_PHASE.PENDING) {
                this.presentationReady = false;
            }

            // native facts can arrive just before a receipt read is cancelled
            this.settleCommittedOperation();

            // clear the transient credential input
            const input = root.querySelector("#visit-manual-code");
            if (input) {
                input.value = "";
            }

            this.clientErrorCode = "";
            if (this.visitPhase === VISIT_PHASE.UNKNOWN) {
                unresolvedVisit = selectedOperation;
                selectedOperation = null;
                this.visitPhase = VISIT_PHASE.IDLE;
            } else if ([VISIT_PHASE.READY, VISIT_PHASE.SETTLED].includes(this.visitPhase)) {
                selectedOperation = null;
                this.visitPhase = VISIT_PHASE.IDLE;
            }
        },

        /**
         * Recognizes committed server facts without treating a pending request as settled.
         *
         * @returns {void}
         */
        settleCommittedOperation() {
            if (
                selectedOperation &&
                this.hasCommittedOperation &&
                this.visitPhase !== VISIT_PHASE.PENDING
            ) {
                this.visitPhase = VISIT_PHASE.SETTLED;
            }
        },

        // -----------------------------------------------------------------------------
        // camera controls
        // -----------------------------------------------------------------------------

        /**
         * Starts capture after the identification controls are ready.
         *
         * @returns {void}
         */
        requestCameraStart() {
            if (
                isDisposed ||
                this.busy ||
                !this.opened ||
                !this.presentationReady ||
                wire.stage !== "identify"
            ) {
                return;
            }

            this.clientErrorCode = "";
            this.cameraFeedbackReady = false;
            this.cameraEnabled = true;

            // notify the scanner component to start capture
            this.$nextTick(() => {
                if (
                    !isDisposed &&
                    this.opened &&
                    this.cameraEnabled &&
                    this.presentationReady &&
                    !this.busy &&
                    wire.stage === "identify"
                ) {
                    root.querySelector("[data-qr-camera]")?.dispatchEvent(
                        new Event("qr-camera-start"),
                    );
                }
            });
        },

        /**
         * Releases capture without revealing manual entry during a camera lookup.
         *
         * @param {boolean} [preserveMode=false] Retains the selected camera presentation until explicit return.
         * @returns {void}
         */
        stopCamera(preserveMode = false) {
            if (!preserveMode) {
                const input = root.querySelector("#visit-manual-code");
                if (this.cameraEnabled && input) {
                    input.value = "";
                }
                this.cameraEnabled = false;
            }

            root.querySelector("[data-qr-camera]")?.dispatchEvent(new Event("qr-camera-stop"));
        },

        // -----------------------------------------------------------------------------
        // identification flow
        // -----------------------------------------------------------------------------

        /**
         * Cancels a replaceable lookup when the user changes the manual input.
         *
         * @returns {void}
         */
        manualChanged() {
            if (isDisposed || this.visitPhase !== VISIT_PHASE.IDLE) {
                return;
            }

            if (activeRead?.name === "findPass") {
                this.cancelRead();
            }
        },

        /**
         * Submits the manual input.
         *
         * @returns {Promise<void>} Settles the read without exposing its credential to reactive state.
         */
        async findPassManually() {
            await this.findPass("manual", root.querySelector("#visit-manual-code")?.value ?? "");
        },

        /**
         * Calls Livewire to find the pass.
         *
         * @param {string} method Private credential method revalidated by the server.
         * @param {string} credential Exact private action argument, never Alpine state.
         * @returns {Promise<void>} Settles only the current identification read.
         */
        async findPass(method, credential) {
            if (
                isDisposed ||
                this.busy ||
                this.cameraStarting ||
                !this.opened ||
                wire.stage !== "identify" ||
                this.visitPhase !== VISIT_PHASE.IDLE
            ) {
                return;
            }

            this.clientErrorCode = "";
            this.stopCamera(method === "token");
            if (window.navigator.onLine === false) {
                this.announceFailure(codes.network);
                this.focusStage();
                return;
            }

            // reconcile a previous uncertain submission before identifying a new pass
            let read = {
                name: unresolvedVisit ? this.receiptActionName(unresolvedVisit) : "findPass",
                cancel: null,
            };
            activeRead = read;
            this.busy = true;

            try {
                if (unresolvedVisit) {
                    const previousVisit = unresolvedVisit;
                    const found = await this.readOriginalReceipt(previousVisit, false);
                    if (isDisposed || activeRead !== read) {
                        return;
                    }

                    if (method === "token") {
                        this.cameraFeedbackReady = true;
                    }

                    if (!found) {
                        return;
                    }

                    unresolvedVisit = null;
                    read = { name: "findPass", cancel: null };
                    activeRead = read;
                }

                const operationId = await wire.findPass(method, credential);
                if (isDisposed || activeRead !== read) {
                    return;
                }

                if (method === "token") {
                    this.cameraFeedbackReady = true;
                }

                if (operationId) {
                    selectedOperation = {
                        uuid: operationId,
                        method,
                        credential,
                        promotionId: wire.preview.promotion?.id,
                    };
                    this.visitPhase = VISIT_PHASE.READY;
                }
            } catch (error) {
                if (!isDisposed && activeRead === read && !read.cancelled) {
                    this.transportFailed(error, read.name, codes.read);
                }
            } finally {
                if (activeRead === read) {
                    activeRead = null;
                    this.busy = false;
                    this.focusStage();
                }
            }
        },

        /**
         * Resets the UI back to identify.
         *
         * @param {boolean} [preserveCameraMode=false] Keeps deactivation visible while its native reset is pending.
         * @returns {Promise<void>} Clears a resolved intent through the native rendered stage owner.
         */
        async reset(preserveCameraMode = false) {
            if (
                isDisposed ||
                this.busy ||
                (this.cameraStarting && !preserveCameraMode) ||
                [VISIT_PHASE.PENDING, VISIT_PHASE.UNKNOWN].includes(this.visitPhase)
            ) {
                return;
            }

            this.clientErrorCode = "";
            const read = { name: "resetIdentification", cancel: null };
            activeRead = read;
            selectedOperation = null;
            this.busy = true;
            this.stopCamera(preserveCameraMode);
            const input = root.querySelector("#visit-manual-code");
            if (input) {
                input.value = "";
            }
            this.visitPhase = VISIT_PHASE.IDLE;

            try {
                await wire.resetIdentification();
                if (!isDisposed && activeRead === read) {
                    this.presentationReady = true;
                }
            } catch (error) {
                if (!isDisposed && activeRead === read && !read.cancelled) {
                    this.transportFailed(error, read.name, codes.read);
                }
            } finally {
                if (activeRead === read) {
                    activeRead = null;
                    this.busy = false;
                    if (preserveCameraMode) {
                        this.cameraEnabled = false;
                    }
                    this.focusStage(true);
                }
            }
        },

        // -----------------------------------------------------------------------------
        // mutation flow
        // -----------------------------------------------------------------------------

        /**
         * Submits an independent redemption or reads its unresolved original entitlement.
         *
         * Blade directs combined commands to acceptVisit() instead.
         *
         * @returns {Promise<void>} Settles the original naturally idempotent target.
         */
        async redeemReward() {
            if (
                isDisposed ||
                this.busy ||
                this.cameraStarting ||
                !this.opened ||
                !selectedOperation
            ) {
                return;
            }

            if (window.navigator.onLine === false) {
                this.announceFailure(codes.network);
                return;
            }

            if (this.visitPhase === VISIT_PHASE.UNKNOWN) {
                await this.findVisitReceipt();
                return;
            }

            if (
                this.visitPhase !== VISIT_PHASE.READY ||
                wire.stage !== "confirm" ||
                !wire.reward?.can_redeem_reward ||
                wire.reward.promotion_id !== selectedOperation.promotionId
            ) {
                return;
            }

            selectedOperation = {
                ...selectedOperation,
                kind: "reward",
                entitlementId: wire.reward.reward_entitlement_id,
                promotionId: wire.reward.promotion_id,
            };

            const operation = selectedOperation;
            operation.cancelled = false;
            this.clientErrorCode = "";
            this.visitPhase = VISIT_PHASE.PENDING;
            this.busy = true;

            await this.submitOperation("redeemReward", operation, [
                operation.method,
                operation.credential,
                operation.entitlementId,
                operation.promotionId,
            ]);
        },

        /**
         * Routes a read to its original Visit or entitlement without changing that target.
         *
         * @param {VisitSelection} operation Retained private original target.
         * @param {boolean} showResult Whether confirmed facts should replace the visible step.
         * @returns {Promise<boolean>} Whether the original commit is authoritatively found.
         */
        readOriginalReceipt(operation, showResult = true) {
            if (operation.kind === "reward") {
                return wire.findRewardReceipt(
                    operation.method,
                    operation.credential,
                    operation.entitlementId,
                    operation.promotionId,
                    showResult,
                );
            }

            if (operation.combined) {
                return wire.findVisitReceipt(
                    operation.method,
                    operation.credential,
                    operation.uuid,
                    showResult,
                    true,
                );
            }

            if (!showResult) {
                return wire.findVisitReceipt(
                    operation.method,
                    operation.credential,
                    operation.uuid,
                    false,
                );
            }

            return wire.findVisitReceipt(operation.method, operation.credential, operation.uuid);
        },

        /**
         * Sends one explicit visit, or resolves the same unknown operation read-only.
         *
         * @param {number} [renderedPromotionId] Server-rendered offer when the reactive hint was unavailable.
         * @param {boolean} [redeemReward=false] Explicit compound command, never preview authority.
         * @returns {Promise<void>} Settles the captured operation even while the modal is closed.
         */
        async acceptVisit(renderedPromotionId, redeemReward = false) {
            if (
                isDisposed ||
                this.busy ||
                this.cameraStarting ||
                !this.opened ||
                !selectedOperation
            ) {
                return;
            }

            // keep the combined action's existing offline guard before receipt recovery
            if (redeemReward && window.navigator.onLine === false) {
                this.announceFailure(codes.network);
                return;
            }

            if (this.visitPhase === VISIT_PHASE.UNKNOWN) {
                await this.findVisitReceipt();
                return;
            }

            if (
                this.visitPhase !== VISIT_PHASE.READY ||
                wire.stage !== "confirm" ||
                !wire.preview?.can_accept_visit
            ) {
                return;
            }

            if (redeemReward) {
                selectedOperation = { ...selectedOperation, kind: "visit", combined: true };
            }

            const operation = selectedOperation;
            operation.cancelled = false;
            operation.promotionId ??= renderedPromotionId;
            this.clientErrorCode = "";
            this.busy = true;
            this.visitPhase = VISIT_PHASE.PENDING;
            this.stopCamera();

            const args = [
                operation.method,
                operation.credential,
                operation.uuid,
                operation.promotionId,
            ];
            if (redeemReward) {
                args.push(true);
            }

            await this.submitOperation("acceptVisit", operation, args);
        },

        /**
         * Coordinates one already-selected mutation and reconciles its original receipt after success.
         *
         * An interrupted submission remains unknown; a received rejection remains ready for the
         * same operation. Only the read-only receipt path may resolve an uncertain submission.
         *
         * @param {'acceptVisit'|'redeemReward'} action Native Livewire mutation name.
         * @param {VisitSelection} operation Captured identity, never replaced by a later request.
         * @param {Array<string|number|boolean|undefined>} args Arguments for the selected server action.
         * @returns {Promise<void>} Finalizes local request ownership without repeating its mutation.
         */
        async submitOperation(action, operation, args) {
            try {
                const outcome = await wire[action](...args);
                if (isDisposed || selectedOperation !== operation) {
                    return;
                }

                this.visitPhase = this.phaseAfterSubmission(outcome);
            } catch (error) {
                if (isDisposed || selectedOperation !== operation) {
                    return;
                }

                this.visitPhase = VISIT_PHASE.UNKNOWN;
                if (!operation.cancelled) {
                    this.transportFailed(error, action, codes.unknown);
                }
            } finally {
                if (!isDisposed && selectedOperation === operation) {
                    this.busy = false;
                }
            }

            if (isDisposed || selectedOperation !== operation || !this.opened) {
                return;
            }

            if (this.visitPhase === VISIT_PHASE.SETTLED) {
                await this.findVisitReceipt(true);
            } else {
                this.focusStage();
            }
        },

        /**
         * Maps the server's operation outcome to the local submission state.
         *
         * @param {string} outcome Server reply for this intentional mutation.
         * @returns {string} Settled, ready for the same intent, or unresolved.
         */
        phaseAfterSubmission(outcome) {
            if (outcome === "confirmed") {
                return VISIT_PHASE.SETTLED;
            }

            return outcome === "rejected" ? VISIT_PHASE.READY : VISIT_PHASE.UNKNOWN;
        },

        /**
         * Names the read-only request for an original Visit or Reward redemption.
         *
         * @param {VisitSelection} operation Original selection requiring reconciliation.
         * @returns {'findVisitReceipt'|'findRewardReceipt'} Matching Livewire reader.
         */
        receiptActionName(operation) {
            return operation.kind === "reward" ? "findRewardReceipt" : "findVisitReceipt";
        },

        /**
         * Reads the same operation for uncertainty or optional committed details.
         *
         * @param {boolean} [focusAfter=false] Restores initial action focus after controls re-enable.
         * @returns {Promise<void>} Refreshes native facts without repeating acceptance or inferring rollback.
         */
        async findVisitReceipt(focusAfter = false) {
            if (
                isDisposed ||
                this.busy ||
                !this.opened ||
                !selectedOperation ||
                ![VISIT_PHASE.UNKNOWN, VISIT_PHASE.SETTLED].includes(this.visitPhase)
            ) {
                return;
            }

            this.clientErrorCode = "";
            const submittedVisit = selectedOperation;
            const previousStage = wire.stage;
            const read = {
                name: this.receiptActionName(submittedVisit),
                cancel: null,
            };
            activeRead = read;
            this.busy = true;

            try {
                await this.readOriginalReceipt(submittedVisit);
                if (isDisposed || activeRead !== read || selectedOperation !== submittedVisit) {
                    return;
                }

                if (this.hasCommittedOperation) {
                    this.visitPhase = VISIT_PHASE.SETTLED;
                }
            } catch (error) {
                if (
                    !isDisposed &&
                    activeRead === read &&
                    selectedOperation === submittedVisit &&
                    !read.cancelled
                ) {
                    // settled operations retain success; only optional details failed
                    const failureCode =
                        this.visitPhase === VISIT_PHASE.SETTLED
                            ? submittedVisit.kind === "reward"
                                ? codes.rewardDetails
                                : codes.details
                            : codes.unknown;
                    this.transportFailed(error, read.name, failureCode);
                }
            } finally {
                if (activeRead === read) {
                    activeRead = null;
                    this.busy = false;
                    if (focusAfter || wire.stage !== previousStage || !this.hasCommittedOperation) {
                        this.focusStage();
                    }
                }
            }
        },

        // -----------------------------------------------------------------------------
        // request ownership
        // -----------------------------------------------------------------------------

        /**
         * Binds native cancellation to each read and records mutation cancellation without retrying it.
         *
         * @returns {void}
         */
        registerActionInterceptors() {
            removeInterceptor = wire.intercept((lifecycle) => {
                const { action, onCancel, onError } = lifecycle;

                if (["logCameraFatalError", "logTransportError"].includes(action.name)) {
                    onError(({ preventDefault }) => preventDefault());
                    return;
                }

                if (
                    ![
                        "findPass",
                        "findVisitReceipt",
                        "resetIdentification",
                        "acceptVisit",
                        "redeemReward",
                        "findRewardReceipt",
                    ].includes(action.name)
                ) {
                    return;
                }

                onError(({ preventDefault }) => preventDefault());
                if (["acceptVisit", "redeemReward"].includes(action.name)) {
                    // native cancellation must not turn an uncertain write into a new one
                    const operation = selectedOperation;
                    onCancel(() => {
                        if (operation) {
                            operation.cancelled = true;
                        }
                    });
                    return;
                }

                this.interceptRead(lifecycle);
            });
        },

        /**
         * Binds a replaceable read to its own cancellation and completion callbacks.
         *
         * @param {object} lifecycle Native read request and callbacks.
         * @returns {void}
         */
        interceptRead({ action, onCancel, onFinish }) {
            const read = activeRead;
            if (isDisposed || !read || read.name !== action.name) {
                action.cancel();
                return;
            }

            onCancel(() => {
                read.cancelled = true;
            });
            read.cancel = () => action.cancel();
            onFinish(() => {
                read.cancel = null;
            });
        },

        /**
         * Invalidates the active read before cancelling its native render effects.
         *
         * @returns {void}
         */
        cancelRead() {
            const read = activeRead;
            if (!read) {
                return;
            }

            activeRead = null;
            this.busy = false;
            read.cancel?.();
        },

        // -----------------------------------------------------------------------------
        // failure presentation and observations
        // -----------------------------------------------------------------------------

        /**
         * Presents one new visible failure and its generic notice outside rendering effects.
         *
         * @param {string} code Backend-owned allowlisted feedback identity.
         * @returns {void}
         */
        announceFailure(code) {
            if (isDisposed || !this.opened || !Object.hasOwn(errors, code)) {
                return;
            }

            this.cameraFeedbackReady = true;
            this.clientErrorCode = code;
            this.$flux.toast({ variant: "danger", text: errors[code].notice });
        },

        /**
         * Classifies a current rejection without retaining native error payloads.
         *
         * @param {unknown} error Native rejection used only for bounded status evidence.
         * @param {string} action Current owned action without private arguments.
         * @param {string} code Contextual backend-owned feedback code.
         * @returns {void}
         */
        transportFailed(error, action, code) {
            if (isDisposed || !this.opened) {
                return;
            }

            const isOffline = window.navigator.onLine === false;
            const failure = classifyVisitTransportFailure(error, codes, code, isOffline);
            this.announceFailure(failure.feedbackCode);

            if (isOffline) {
                return;
            }

            this.$dispatch("livewire-transport-error", {
                code: failure.code,
                action,
                status: failure.status,
                type: failure.type,
            });
        },

        /**
         * Presents current classified camera feedback before attempting optional telemetry.
         *
         * @param {object} detail Closed camera code and capability/startup/decode type.
         * @returns {Promise<void>} Settles telemetry without recursive reporting.
         */
        async cameraFailed({ code, type }) {
            if (
                isDisposed ||
                !this.opened ||
                !this.cameraEnabled ||
                !this.presentationReady ||
                this.busy
            ) {
                return;
            }

            this.announceFailure(code);
            this.focusStage();
            if (window.navigator.onLine === false) {
                return;
            }

            try {
                await wire.logCameraFatalError(code, type);
            } catch {
                // telemetry cannot recursively report itself or replace the original visible failure
            }
        },

        /**
         * Forwards only safe transport evidence while online, without reporting its own failure.
         *
         * @param {object} detail Closed code, action, HTTP status and failure type.
         * @returns {Promise<void>} Consumes the telemetry rejection without console output.
         */
        async logTransportError({ code, action, status, type }) {
            if (isDisposed || !this.opened || window.navigator.onLine === false) {
                return;
            }

            try {
                await wire.logTransportError(code, action, status, type);
            } catch {
                // the original failure remains visible even when observation delivery fails
            }
        },

        // -----------------------------------------------------------------------------
        // focus management
        // -----------------------------------------------------------------------------

        /**
         * Returns focus to the visible button that opened the modal.
         *
         * @returns {void}
         */
        restoreFocusToTrigger() {
            const trigger = root.ownerDocument.getElementById("register-visit-trigger");
            if (trigger?.getClientRects().length) {
                trigger.focus({ preventScroll: true });
            }
        },

        /**
         * Focuses a contextual action, avoiding automatic touch-device text entry.
         *
         * @param {boolean} [entering=false] Marks modal opening or return to manual identification.
         * @returns {void}
         */
        focusStage(entering = false) {
            // defer focus to the next render cycle
            this.$nextTick(() => {
                if (!isDisposed && this.opened && !this.busy) {
                    const target = root.querySelector(this.focusTargetSelector(entering));
                    if (target && !target.disabled && !target.closest?.("fieldset[disabled]")) {
                        target.focus({ preventScroll: true });
                    }
                }
            });
        },

        /**
         * Selects the next action for the rendered step without triggering touch-device text entry.
         *
         * @param {boolean} entering Whether focus follows opening or a return to identification.
         * @returns {string} Existing local selector for the contextual focus target.
         */
        focusTargetSelector(entering) {
            if (!this.presentationReady) {
                return "[data-visit-close]";
            }

            switch (wire.stage) {
                case "identify":
                    if (this.cameraEnabled) {
                        return "[data-visit-camera-control]";
                    }

                    if (entering && !this.manualAutofocus && this.focusModality !== "keyboard") {
                        return "[data-visit-close]";
                    }

                    return "#visit-manual-code";
                case "confirm":
                    if (root.querySelector("[data-reward-redeem]")) {
                        return "[data-reward-redeem]";
                    }
                    return root.querySelector("[data-visit-confirm]")
                        ? "[data-visit-confirm]"
                        : "[data-visit-back]";
                default:
                    return this.focusModality === "keyboard"
                        ? "[data-visit-back]"
                        : "[data-visit-close]";
            }
        },
    };
});
