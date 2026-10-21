import assert from "node:assert/strict";
import { afterEach, mock, test } from "node:test";
import { BrowserQRCodeReader } from "@zxing/browser";
import { NotFoundException } from "@zxing/library";
import { qrCameraScanner } from "../../resources/js/qr-camera-scanner.js";

let factory;
globalThis.Alpine = {
    data: (name, create) => {
        assert.equal(name, "visitRegistration");
        factory = create;
    },
};
await import("../../resources/views/components/⚡visit-registration/visit-registration.js");
delete globalThis.Alpine;

let active;
afterEach(() => {
    active?.destroy();
    mock.restoreAll();
    delete globalThis.window;
    delete globalThis.Alpine;
});

/** Creates a controlled asynchronous boundary.
 * @returns {object} Promise with explicit settlement controls.
 */
function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
}

/** Supplies safe server facts for a successful read.
 * @returns {object} Selected preview fixture, not a browser state implementation.
 */
function identified() {
    return {
        preview: {
            promotion: {
                id: 7,
                reward_title: "Real",
                earned_points: 2,
                target_points: 8,
                visit_points: 3,
            },
            can_accept_visit: true,
            can_redeem_reward: false,
        },
    };
}

/** Supplies immutable committed facts.
 * @returns {object} Six-fact fixture with the original award.
 */
function confirmed() {
    return {
        outcome: "confirmed",
        facts: {
            visit_id: 1,
            operation_id: "original-operation",
            customer_pass_id: 2,
            promotion_id: 7,
            points_awarded: 3,
            confirmed_at: "2030-01-02T12:00:00Z",
        },
    };
}

/** Supplies original-offer receipt details.
 * @returns {object} Read-only receipt fixture.
 */
function receipt() {
    return {
        ...confirmed(),
        details: {
            reward_title: "Original",
            earned_points: 5,
            target_points: 8,
            reward_unlocked: false,
        },
    };
}

/** Builds native-boundary doubles; this does not prove Flux DOM morphing or actual browser focus.
 * @param {object} responses Deterministic action outcomes before native state application.
 * @returns {object} Public interaction, camera resources, cancellation and render observations.
 */
function setup(responses = {}) {
    const document = new EventTarget();
    const focus = [],
        stopped = [],
        startup = [],
        cancellations = [],
        renders = [],
        toasts = [];
    document.getElementById = () => ({
        getClientRects: () => [1],
        focus: () => focus.push("trigger"),
    });
    const dialog = new EventTarget();
    const root = new EventTarget();
    const nativeDispatch = dialog.dispatchEvent.bind(dialog);
    dialog.dispatchEvent = (event) => {
        if (!Object.hasOwn(event, "target")) {
            Object.defineProperty(event, "target", { value: dialog, configurable: true });
        }
        root.dispatchEvent(event);
        return event.defaultPrevented ? false : nativeDispatch(event);
    };
    const controls = { stop: () => stopped.push("owned-camera") };
    mock.method(BrowserQRCodeReader.prototype, "decodeFromConstraints", async (...args) => {
        startup.push(args);
        return controls;
    });
    const refs = {
        manual: { value: "00007", focus: () => focus.push("manual") },
        video: { id: "initial-video" },
        dialog,
    };
    const native = {
        isSecureContext: false,
        matchMedia: () => ({ matches: true }),
        navigator: { onLine: true, mediaDevices: { getUserMedia() {} } },
    };
    globalThis.window = native;
    const interceptors = new Set();
    const state = {
        stage: "identify",
        preview: null,
        facts: null,
        details: null,
        combinedConfirmed: false,
        errorCode: "",
    };
    const wire = {
        ...state,
        logCameraFatalError: async () => {},
        logTransportError: async () => {},
        intercept(callback) {
            interceptors.add(callback);
            return () => interceptors.delete(callback);
        },
    };
    const outcomes = {
        findPass: async () => identified(),
        acceptVisit: async () => confirmed(),
        findVisitReceipt: async () => receipt(),
        redeemReward: async () => ({
            outcome: "confirmed",
            redemption: {
                reward_entitlement_id: 9,
                promotion_id: 7,
                redeemed_at: "2030-01-02T12:00:00Z",
            },
        }),
        findRewardReceipt: async () => null,
        resetIdentification: async () => null,
        ...responses,
    };
    for (const [name, respond] of Object.entries(outcomes)) {
        wire[name] = (...args) => {
            const pending = deferred();
            let cancelled = false;
            const finish = [],
                errors = [],
                failures = [],
                cancels = [];
            const action = {
                name,
                cancel() {
                    cancelled = true;
                    cancellations.push(name);
                    cancels.forEach((callback) => callback());
                    finish.forEach((callback) => callback());
                    pending.reject({ status: null, body: null, json: null, errors: null });
                },
            };
            for (const interceptor of interceptors) {
                interceptor({
                    action,
                    onCancel: (callback) => cancels.push(callback),
                    onError: (callback) => errors.push(callback),
                    onFailure: (callback) => failures.push(callback),
                    onFinish: (callback) => finish.push(callback),
                });
            }
            Promise.resolve()
                .then(() => respond(...args))
                .then(
                    (result) => {
                        if (cancelled) return;
                        renders.push(name);
                        if (name === "findPass") {
                            wire.preview = result?.preview ?? null;
                            wire.stage = result?.preview ? "confirm" : "identify";
                            if (wire.preview) refs.manual.value = "";
                            pending.resolve(wire.preview ? crypto.randomUUID() : null);
                        } else if (name === "acceptVisit") {
                            if (result.outcome === "confirmed") {
                                wire.preview = null;
                                wire.facts = result.facts;
                                wire.combinedConfirmed = result.combinedConfirmed ?? false;
                                wire.stage = "result";
                            }
                            pending.resolve(result.outcome);
                        } else if (name === "redeemReward") {
                            if (result.outcome === "confirmed") {
                                wire.redemption = result.redemption;
                                wire.stage = "result";
                            }
                            pending.resolve(result.outcome);
                        } else if (name === "findRewardReceipt") {
                            if (args[4] !== false && result?.redemption) {
                                wire.redemption = result.redemption;
                                wire.stage = "result";
                            }
                            pending.resolve(Boolean(result?.redemption));
                        } else if (name === "findVisitReceipt") {
                            if (args[3] !== false) {
                                if (result?.facts) {
                                    wire.preview = null;
                                    wire.facts = result.facts;
                                    wire.details = result.details;
                                }
                                if (wire.facts) wire.stage = "result";
                            }
                            pending.resolve(Boolean(result?.facts));
                        } else {
                            Object.assign(wire, state);
                            refs.video = { id: "returned-video" };
                            pending.resolve();
                        }
                        finish.forEach((callback) => callback());
                    },
                    (error) => {
                        if (cancelled) return;
                        const preventDefault = () => {};
                        if (error.status === null)
                            failures.forEach((callback) => callback({ error }));
                        else
                            errors.forEach((callback) =>
                                callback({
                                    preventDefault,
                                    response: { status: error.status ?? 500 },
                                }),
                            );
                        pending.reject(error);
                        finish.forEach((callback) => callback());
                    },
                );
            return pending.promise;
        };
    }
    const context = factory({
        errors: {
            read_unavailable: { message: "Read unavailable", notice: "Action needs attention" },
            unknown: { message: "Try again safely", notice: "Action needs attention" },
            details_unavailable: {
                message: "Registered; details unavailable",
                notice: "Registered; review the message",
            },
            reward_details_unavailable: {
                message: "Redeemed; details unavailable",
                notice: "Redeemed; review the message",
            },
            invalid_credential: { message: "Check the code", notice: "Action needs attention" },
            network_error: { message: "Check connection", notice: "Action needs attention" },
            denied: { message: "Camera denied", notice: "Action needs attention" },
            busy: { message: "Camera busy", notice: "Action needs attention" },
            unavailable: { message: "Camera unavailable", notice: "Action needs attention" },
            initialization: { message: "Reader unavailable", notice: "Action needs attention" },
        },
        codes: {
            network: "network_error",
            transport: "transport_failed",
            read: "read_unavailable",
            unknown: "unknown",
            details: "details_unavailable",
            rewardDetails: "reward_details_unavailable",
        },
    });
    const cameraRoot = new EventTarget();
    cameraRoot.querySelector = () => refs.video;
    Object.assign(context, {
        $wire: wire,
        $flux: { toast: (toast) => toasts.push(toast) },
        $dispatch: (name, detail) => {
            if (name === "qr-camera-fatal-error") void context.cameraFailed(detail);
            if (name === "livewire-transport-error") void context.logTransportError(detail);
        },
        $el: Object.assign(root, {
            ownerDocument: document,
            querySelector: (selector) => {
                if (selector === "dialog") return refs.dialog;
                if (selector === "[data-qr-camera]")
                    return wire.stage === "identify" ? cameraRoot : null;
                if (selector === "#visit-manual-code")
                    return wire.stage === "identify" ? refs.manual : null;
                if (selector === "[data-reward-redeem]")
                    return wire.stage === "confirm" &&
                        (wire.reward?.can_redeem_reward ||
                            (wire.preview?.can_accept_visit &&
                                wire.preview.promotion.target_points <=
                                    wire.preview.promotion.earned_points +
                                        wire.preview.promotion.visit_points))
                        ? { focus: () => focus.push("reward-button") }
                        : null;
                if (selector === "[data-visit-confirm]")
                    return wire.stage === "confirm"
                        ? { focus: () => focus.push("confirm-button") }
                        : null;
                if (selector === "[data-visit-restart]")
                    return { focus: () => focus.push("restart-button") };
                if (selector === "[data-visit-close]")
                    return { focus: () => focus.push("close-button") };
                if (selector === "[data-visit-back]")
                    return { focus: () => focus.push("back-button") };
                if (selector === "[data-visit-camera-control]")
                    return { focus: () => focus.push("camera-control") };
                const heading =
                    selector === "[data-visit-transport-focus]"
                        ? "status"
                        : ({ identify: "manual", confirm: "confirmation", result: "result" }[
                              wire.stage
                          ] ?? "status");
                return { focus: () => focus.push(heading) };
            },
        }),
        $nextTick: (callback) => Promise.resolve().then(callback),
    });
    const camera = qrCameraScanner((token) => void context.findPass("token", token), {
        DENIED: "denied",
        BUSY: "busy",
        UNAVAILABLE: "unavailable",
        INITIALIZATION: "initialization",
    });
    camera.$el = cameraRoot;
    camera.$dispatch = context.$dispatch;
    cameraRoot.addEventListener("qr-camera-start", () => void camera.startCamera());
    cameraRoot.addEventListener("qr-camera-stop", () => camera.stopCamera());
    globalThis.Alpine = { $data: () => camera, bind: () => () => {} };
    context.init();
    active = {
        destroy: () => {
            context.destroy();
            camera.destroy();
        },
    };
    return {
        context,
        wire,
        camera,
        native,
        root,
        dialog,
        document,
        refs,
        stopped,
        controls,
        startup,
        focus,
        cancellations,
        renders,
        toasts,
        interceptors,
    };
}

/** Flushes promise continuations without sleeps or owned decoder timers.
 * @returns {Promise<void>} Settled continuations.
 */
async function settle() {
    await new Promise((resolve) => setImmediate(resolve));
}

/** Identifies through the native manual field.
 * @param {object} context Public Alpine interaction.
 * @returns {Promise<void>} Completed read.
 */
async function identify(context) {
    context.handleModalShow();
    await context.findPassManually();
}

for (const status of [503, null]) {
    test(`direct Reward failure retains confirmation and pairs safe feedback (${status})`, async () => {
        const { context, wire, toasts } = setup({
            redeemReward: async () => {
                throw { status };
            },
        });
        await identify(context);
        wire.preview.can_accept_visit = false;
        wire.reward = { reward_entitlement_id: 9, promotion_id: 7, can_redeem_reward: true };

        await context.redeemReward();

        assert.equal(context.busy, false);
        assert.equal(context.visitPhase, "unknown");
        assert.equal(context.clientErrorCode, status === null ? "network_error" : "unknown");
        assert.equal(toasts.length, 1);
        assert.equal(wire.stage, "confirm");
        assert.equal(wire.facts, null);
    });
}

test("keyboard traverses confirmation, Result back and manual entry even with a coarse pointer", async () => {
    const { context, document, native, focus } = setup();
    native.matchMedia = () => ({ matches: false });
    document.dispatchEvent(new Event("pointerdown"));
    context.handleModalShow();
    await settle();
    assert.equal(focus.at(-1), "close-button");
    document.dispatchEvent(new Event("keydown"));
    await context.findPassManually();
    assert.equal(focus.at(-1), "confirm-button");
    await context.acceptVisit(7);
    assert.equal(focus.at(-1), "back-button");
    await context.reset();
    assert.equal(focus.at(-1), "manual");
});

test("one direct compound command submits the original private Visit once", async () => {
    const calls = [];
    const { context, wire, renders } = setup({
        acceptVisit: async (...args) => {
            calls.push(args);
            return { ...confirmed(), combinedConfirmed: true };
        },
    });
    await identify(context);
    wire.preview.promotion.target_points = 5;
    assert.deepEqual(renders, ["findPass"]);
    assert.deepEqual(calls, []);
    await context.acceptVisit(7, true);
    assert.equal(calls.length, 1);
    assert.deepEqual(calls[0].slice(0, 2), ["manual", "00007"]);
    assert.deepEqual(calls[0].slice(3), [7, true]);
    assert.equal(wire.stage, "result");
    await context.acceptVisit(7, true);
    assert.equal(calls.length, 1);
});

test("unknown compound confirmation keeps its intent and reconciles read-only before a fresh lookup", async () => {
    const calls = [];
    const { context, wire } = setup({
        acceptVisit: async (...args) => {
            calls.push(["mutate", ...args]);
            throw { status: 503 };
        },
        findVisitReceipt: async (...args) => {
            calls.push(["receipt", ...args]);
            return null;
        },
    });
    await identify(context);
    wire.preview.promotion.target_points = 5;
    await context.acceptVisit(7, true);
    assert.equal(context.visitPhase, "unknown");
    await context.acceptVisit(7, true);
    assert.deepEqual(calls[1], ["receipt", ...calls[0].slice(1, 4), true, true]);
    context.handleModalClose();
    context.handleModalShow();
    await settle();
    await context.findPass("manual", "22222");
    assert.deepEqual(calls[2], ["receipt", ...calls[0].slice(1, 4), false, true]);
    assert.equal(calls.filter(([kind]) => kind === "mutate").length, 1);
    assert.equal(wire.stage, "identify");
});

test("unknown compound outcome cannot settle from Visit facts without an established redemption postcondition", async () => {
    const { context, wire } = setup({
        acceptVisit: async () => ({ outcome: "unknown" }),
        findVisitReceipt: async () => receipt(),
    });
    await identify(context);
    wire.preview.promotion.target_points = 5;
    await context.acceptVisit(7, true);
    await context.acceptVisit(7, true);
    assert.ok(wire.facts);
    assert.equal(context.visitPhase, "unknown");
    assert.equal(context.hasCommittedOperation, false);
});

test("camera permission startup guards dismissal and competing reads but remains cancellable", async () => {
    const permission = deferred();
    const { context, camera, dialog, renders, native, controls } = setup();
    native.isSecureContext = true;
    BrowserQRCodeReader.prototype.decodeFromConstraints.mock.mockImplementation(
        () => permission.promise,
    );
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    assert.equal(camera.starting, true);
    const cancel = new Event("cancel", { cancelable: true });
    dialog.dispatchEvent(cancel);
    assert.equal(cancel.defaultPrevented, true);
    await context.findPassManually();
    await context.reset();
    assert.deepEqual(renders, []);
    await context.reset(true);
    assert.equal(camera.starting, false);
    assert.deepEqual(renders, ["resetIdentification"]);
    permission.resolve(controls);
    await settle();
    assert.equal(camera.active, false);
});

test("an unknown Reward submission reconciles only its original target and retains it on missing receipt", async () => {
    const calls = [];
    const { context, wire } = setup({
        redeemReward: async (...args) => {
            calls.push(["redeem", ...args]);
            throw { status: 503 };
        },
        findRewardReceipt: async (...args) => {
            calls.push(["receipt", ...args]);
            return null;
        },
    });
    await identify(context);
    wire.preview.can_accept_visit = false;
    wire.reward = { reward_entitlement_id: 9, promotion_id: 7, can_redeem_reward: true };
    await context.redeemReward();
    assert.equal(context.visitPhase, "unknown");
    await context.redeemReward();
    assert.equal(context.visitPhase, "unknown");
    assert.deepEqual(
        calls.map((call) => call[0]),
        ["redeem", "receipt"],
    );
    assert.deepEqual(calls[0].slice(1), ["manual", "00007", 9, 7]);
    assert.deepEqual(calls[1].slice(1), ["manual", "00007", 9, 7, true]);
});

test("final Reward confirmation submits once, preserves Visit facts, and keeps known redemption when details fail", async () => {
    const pending = deferred();
    const calls = [];
    const { context, wire, toasts } = setup({
        redeemReward: async (...args) => {
            calls.push(args);
            return pending.promise;
        },
        findRewardReceipt: async () => {
            throw { status: 503 };
        },
    });
    await identify(context);
    wire.preview.can_accept_visit = false;
    const visit = wire.facts;
    wire.reward = { reward_entitlement_id: 9, promotion_id: 7, can_redeem_reward: true };
    const submit = context.redeemReward();
    await context.redeemReward();
    await settle();
    assert.equal(context.busy, true);
    assert.equal(wire.stage, "confirm");
    assert.equal(wire.facts, visit);
    assert.equal(calls.length, 1);
    pending.resolve({
        outcome: "confirmed",
        redemption: { reward_entitlement_id: 9, redeemed_at: "2030-01-02T12:00:00Z" },
    });
    await submit;
    assert.equal(context.visitPhase, "settled");
    assert.equal(wire.redemption.reward_entitlement_id, 9);
    assert.equal(wire.facts, visit);
    assert.equal(context.clientErrorCode, "reward_details_unavailable");
    assert.equal(toasts.length, 1);
    assert.equal(toasts[0].text, "Redeemed; review the message");
});

test("offline final Reward confirmation sends zero requests or telemetry and pairs one error with one toast", async () => {
    const calls = [];
    const { context, wire, native, toasts } = setup({
        redeemReward: async () => {
            calls.push("redeem");
        },
    });
    await identify(context);
    wire.reward = { reward_entitlement_id: 9, promotion_id: 7, can_redeem_reward: true };
    native.navigator.onLine = false;
    await context.redeemReward();
    assert.deepEqual(calls, []);
    assert.equal(context.clientErrorCode, "network_error");
    assert.equal(toasts.length, 1);
    assert.equal(context.visitPhase, "ready");
});

test("lost Reward response cannot use old Visit facts as proof and fresh lookup reconciles the original target", async () => {
    const calls = [];
    const { context, wire, refs } = setup({
        redeemReward: async () => {
            calls.push("redeem");
            throw { status: 503 };
        },
        findRewardReceipt: async (...args) => {
            calls.push(args);
            return null;
        },
    });
    await identify(context);
    wire.preview.can_accept_visit = false;
    wire.facts = confirmed().facts;
    wire.reward = { reward_entitlement_id: 9, promotion_id: 7, can_redeem_reward: true };
    await context.redeemReward();
    assert.equal(context.visitPhase, "unknown");
    assert.ok(wire.facts);
    context.handleDialogClose();
    await settle();
    context.handleModalShow();
    await settle();
    refs.manual.value = "99999";
    await context.findPassManually();
    assert.deepEqual(calls, ["redeem", ["manual", "00007", 9, 7, false]]);
    assert.equal(wire.stage, "identify");
    assert.equal(wire.redemption, undefined);
    assert.equal(context.visitPhase, "idle");
});

for (const fails of [false, true]) {
    test(`morphed action origin still ${fails ? "reports optional receipt failure" : "loads original progress"} through its owning component`, async () => {
        let originConnected = true;
        const acceptances = [];
        const reads = [];
        const { context, wire, toasts } = setup({
            acceptVisit: async (...args) => {
                acceptances.push(args);
                originConnected = false;
                return confirmed();
            },
            findVisitReceipt: async (...args) => {
                reads.push(args);
                if (fails) throw new Error("synthetic receipt failure");
                return receipt();
            },
        });
        // installed Livewire returns no-op functions when an action's origin leaves the DOM
        const detachedMagic = new Proxy({}, { get: () => () => {} });
        Object.defineProperty(context, "$wire", {
            get: () => (originConnected ? wire : detachedMagic),
        });

        await identify(context);
        await context.acceptVisit(7);

        assert.equal(acceptances.length, 1);
        assert.deepEqual(reads, [acceptances[0].slice(0, 3)]);
        assert.equal(context.visitPhase, "settled");
        assert.equal(wire.stage, "result");
        assert.deepEqual(wire.facts, confirmed().facts);
        if (fails) {
            assert.equal(wire.details, null);
            assert.deepEqual(toasts, [
                { variant: "danger", text: "Registered; review the message" },
            ]);
        } else {
            assert.deepEqual(wire.details, receipt().details);
            assert.deepEqual(toasts, []);
        }
    });
}

test("unsupported camera keeps retry focus and an explicit clean return to manual entry", async () => {
    const { context, camera, wire, focus, refs } = setup();
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    assert.equal(camera.error, "unavailable");
    assert.equal(wire.stage, "identify");
    assert.equal(focus.at(-1), "camera-control");
    await context.reset(true);
    refs.manual.value = "00007";
    await context.findPassManually();
    await settle();
    assert.equal(wire.stage, "confirm");
    assert.equal(focus.at(-1), "confirm-button");
});

test("camera-capable runtime without a native detector starts the complete SDK", async () => {
    const { context, camera, native, startup } = setup();
    native.isSecureContext = true;
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    assert.equal(startup.length, 1);
    assert.equal(camera.active, true);
    assert.equal(camera.error, "");
});

test("denied permission and failed reader startup retain manual identification", async () => {
    for (const name of ["NotAllowedError", "Error"]) {
        const { context, camera, wire, native } = setup();
        native.isSecureContext = true;
        BrowserQRCodeReader.prototype.decodeFromConstraints.mock.mockImplementation(async () => {
            throw Object.assign(new Error("private startup details"), { name });
        });
        context.handleModalShow();
        context.requestCameraStart();
        await settle();
        assert.equal(wire.stage, "identify");
        assert.equal(camera.error, name === "NotAllowedError" ? "denied" : "initialization");
        assert.equal(
            context.errorMessage,
            name === "NotAllowedError" ? "Camera denied" : "Reader unavailable",
        );
        assert.equal(camera.active, false);
        await context.findPassManually();
        assert.equal(wire.stage, "confirm");
        context.destroy();
        mock.restoreAll();
    }
});

test("late permission after close releases returned camera controls without publishing", async () => {
    const permission = deferred();
    const { context, camera, wire, native, controls, stopped } = setup();
    native.isSecureContext = true;
    BrowserQRCodeReader.prototype.decodeFromConstraints.mock.mockImplementation(
        () => permission.promise,
    );
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    context.handleModalClose();
    permission.resolve(controls);
    await settle();
    assert.equal(camera.active, false);
    assert.equal(camera.starting, false);
    assert.deepEqual(stopped, ["owned-camera"]);
    assert.equal(wire.preview, null);
});

test("late SDK startup cannot restart capture after deactivation and manual identification", async () => {
    const starting = deferred();
    const { context, camera, native, controls, stopped } = setup();
    native.isSecureContext = true;
    BrowserQRCodeReader.prototype.decodeFromConstraints.mock.mockImplementation(
        () => starting.promise,
    );
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    await context.reset(true);
    context.manualChanged();
    const finding = context.findPassManually();
    starting.resolve(controls);
    await finding;
    await settle();
    assert.equal(camera.active, false);
    assert.deepEqual(stopped, ["owned-camera"]);
});

test("first SDK result sends one exact read only after stopping capture", async () => {
    const calls = [];
    let mutations = 0;
    const { context, wire, camera, native, stopped, controls, startup } = setup({
        findPass: async (...args) => {
            assert.deepEqual(stopped, ["owned-camera"]);
            calls.push(args);
            return identified();
        },
        acceptVisit: async () => {
            mutations++;
            return confirmed();
        },
    });
    native.isSecureContext = true;
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    startup[0][2]({ getText: () => " private-token " }, undefined, controls);
    startup[0][2]({ getText: () => "second-token" }, undefined, controls);
    await settle();
    assert.deepEqual(calls, [["token", " private-token "]]);
    assert.equal(mutations, 0);
    assert.equal(wire.stage, "confirm");
    assert.equal(camera.active, false);
    assert.equal(JSON.stringify(context).includes("private-token"), false);
});

test("unreadable SDK frames keep capture running until close releases scoped controls", async () => {
    const { context, camera, native, controls, startup, stopped } = setup();
    native.isSecureContext = true;
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    startup[0][2](undefined, new NotFoundException(), controls);
    assert.equal(camera.active, true);
    assert.deepEqual(stopped, []);
    context.handleModalClose();
    assert.equal(camera.active, false);
    assert.deepEqual(stopped, ["owned-camera"]);
});

test("late SDK result after explicit deactivation cannot send a pass-finding request", async () => {
    let calls = 0;
    const { context, camera, wire, native, startup, controls, stopped } = setup({
        findPass: async () => {
            calls++;
            return identified();
        },
    });
    native.isSecureContext = true;
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    context.stopCamera();
    startup[0][2]({ getText: () => "old-token" }, undefined, controls);
    await settle();
    assert.equal(calls, 0);
    assert.equal(wire.stage, "identify");
    assert.equal(camera.active, false);
    assert.deepEqual(stopped, ["owned-camera"]);
});

test("manual edits cancel native reads immediately so late responses cannot render over successor input", async () => {
    const old = deferred(),
        next = deferred();
    let calls = 0;
    const { context, wire, refs, cancellations, renders, toasts } = setup({
        findPass: () => (++calls === 1 ? old.promise : next.promise),
    });
    context.handleModalShow();
    const first = context.findPassManually();
    await settle();
    refs.manual.value = "00008";
    context.manualChanged();
    assert.deepEqual(cancellations, ["findPass"]);
    const second = context.findPassManually();
    old.resolve(identified());
    await first;
    assert.equal(wire.stage, "identify");
    assert.equal(refs.manual.value, "00008");
    assert.deepEqual(renders, []);
    assert.equal(context.busy, true);
    next.resolve(identified());
    await second;
    assert.equal(wire.stage, "confirm");
    assert.deepEqual(renders, ["findPass"]);
    assert.deepEqual(toasts, []);
});

test("close cancels a native read before stale rendering and clears the editable credential", async () => {
    const request = deferred();
    const { context, wire, refs, cancellations, renders } = setup({
        findPass: () => request.promise,
    });
    context.handleModalShow();
    const read = context.findPassManually();
    context.handleModalClose();
    assert.deepEqual(cancellations, ["findPass"]);
    request.resolve(identified());
    await read;
    assert.equal(wire.preview, null);
    assert.equal(refs.manual.value, "");
    assert.deepEqual(renders, []);
    context.handleModalShow();
    assert.equal(wire.stage, "identify");
});

test("explicit confirmation forwards the rendered offer when its reactive hint is absent", async () => {
    const mutations = [];
    const selected = identified();
    delete selected.preview.promotion.id;
    const { context } = setup({
        findPass: async () => selected,
        acceptVisit: async (...args) => {
            mutations.push(args);
            return confirmed();
        },
    });
    await identify(context);
    await context.acceptVisit(7);

    assert.equal(mutations.length, 1);
    assert.equal(mutations[0][3], 7);
});

test("double confirmation sends exactly one UUID and never stores credentials in reactive state", async () => {
    const response = deferred(),
        mutations = [];
    const { context, wire } = setup({
        acceptVisit: (...args) => {
            mutations.push(args);
            return response.promise;
        },
    });
    await identify(context);
    const first = context.acceptVisit();
    await context.acceptVisit();
    assert.equal(mutations.length, 1);
    assert.deepEqual(mutations[0].slice(0, 2), ["manual", "00007"]);
    assert.match(mutations[0][2], /^[a-f0-9-]{36}$/);
    assert.equal(mutations[0][3], 7);
    assert.equal(wire.stage, "confirm");
    assert.equal(wire.preview.promotion.id, 7);
    assert.equal(JSON.stringify(context).includes("00007"), false);
    response.resolve(confirmed());
    await first;
    assert.equal(wire.stage, "result");
    assert.equal(wire.facts.points_awarded, 3);
    assert.equal(wire.preview, null);
});

test("lost response and reopen retain the same identity for receipt finding, never acceptance", async () => {
    const mutations = [],
        reads = [];
    const { context, wire } = setup({
        acceptVisit: async (...args) => {
            mutations.push(args);
            throw new Error("lost response");
        },
        findVisitReceipt: async (...args) => {
            reads.push(args);
            return receipt();
        },
    });
    await identify(context);
    await context.acceptVisit();
    assert.equal(wire.stage, "confirm");
    assert.equal(wire.preview.promotion.id, 7);
    context.handleModalClose();
    context.handleModalShow();
    await settle();
    assert.equal(wire.stage, "identify");
    await context.findPassManually();
    assert.deepEqual(reads, [[...mutations[0].slice(0, 3), false]]);
    assert.equal(mutations.length, 1);
    assert.equal(wire.stage, "confirm");
    assert.equal(wire.facts, null);
});

test("null receipt cannot prove rollback or permit a new pass or mutation", async () => {
    let mutations = 0,
        searches = 0;
    const { context, wire } = setup({
        findPass: async () => {
            searches++;
            return identified();
        },
        acceptVisit: async () => {
            mutations++;
            return { outcome: "unknown" };
        },
        findVisitReceipt: async () => null,
    });
    await identify(context);
    await context.acceptVisit();
    await context.findVisitReceipt();
    await context.reset();
    context.manualChanged();
    await context.findPassManually();
    await context.acceptVisit();
    assert.equal(wire.stage, "confirm");
    assert.equal(mutations, 1);
    assert.equal(searches, 1);
});

test("close during acceptance never cancels the mutation or discards its returned native facts", async () => {
    const response = deferred();
    let reads = 0;
    const { context, wire, cancellations } = setup({
        acceptVisit: () => response.promise,
        findVisitReceipt: async () => {
            reads++;
            return receipt();
        },
    });
    await identify(context);
    const mutation = context.acceptVisit();
    context.handleModalClose();
    assert.deepEqual(cancellations, []);
    response.resolve(confirmed());
    await mutation;
    assert.equal(context.opened, false);
    assert.equal(reads, 0);
    assert.equal(wire.facts.visit_id, 1);
    context.handleModalShow();
    await settle();
    assert.equal(wire.stage, "identify");
    assert.equal(wire.facts, null);
    assert.equal(reads, 0);
});

test("known committed facts survive optional receipt failure and read-only refresh", async () => {
    const { context, wire, focus } = setup({
        findVisitReceipt: async () => {
            throw new Error("unavailable");
        },
    });
    await identify(context);
    await context.acceptVisit();
    await settle();
    assert.equal(wire.stage, "result");
    assert.equal(wire.facts.points_awarded, 3);
    assert.equal(wire.details, null);
    assert.equal(focus.at(-1), "close-button");
    await context.findVisitReceipt();
    assert.equal(wire.stage, "result");
    assert.equal(wire.facts.visit_id, 1);
});

test("available Reward cannot send acceptance even when the handler is called directly", async () => {
    let mutations = 0;
    const { context, wire } = setup({
        findPass: async () => ({
            preview: { ...identified().preview, can_accept_visit: false, can_redeem_reward: true },
        }),
        acceptVisit: async () => {
            mutations++;
            return confirmed();
        },
    });
    await identify(context);
    await context.acceptVisit();
    assert.equal(mutations, 0);
    assert.equal(wire.stage, "confirm");
});

test("resolved rejection allows a genuinely new search with a fresh UUID", async () => {
    const calls = [];
    const { context, wire, refs } = setup({
        acceptVisit: async (...args) => {
            calls.push(args);
            return { outcome: "rejected" };
        },
    });
    await identify(context);
    await context.acceptVisit();
    assert.equal(wire.stage, "confirm");
    await context.reset();
    refs.manual.value = "00008";
    await context.findPassManually();
    await context.acceptVisit();
    assert.equal(calls.length, 2);
    assert.notEqual(calls[0][2], calls[1][2]);
});

test("destroy detaches the native interceptor even without any pending action", () => {
    const { context, interceptors, document } = setup();
    assert.equal(interceptors.size, 1);

    context.destroy();

    assert.equal(interceptors.size, 0);
    const show = new Event("modal-show");
    show.detail = { name: "visit-registration" };
    document.dispatchEvent(show);
    assert.equal(context.opened, false);
});

test("closed acceptance settles its private guard and retains the captured offer until a fresh intent", async () => {
    const response = deferred();
    const mutations = [];
    const { context, wire, refs } = setup({
        acceptVisit: (...args) => {
            mutations.push(args);
            return mutations.length === 1 ? response.promise : Promise.resolve(confirmed());
        },
    });
    await identify(context);
    const pending = context.acceptVisit(99);
    context.handleModalClose();
    wire.preview.promotion.id = 99;
    context.handleModalShow();
    await context.acceptVisit();
    assert.equal(mutations.length, 1);
    assert.equal(mutations[0][3], 7);
    context.handleModalClose();
    response.resolve({ outcome: "rejected" });
    await pending;

    context.handleModalShow();
    await context.reset();
    refs.manual.value = "00008";
    await context.findPassManually();
    await context.acceptVisit();

    assert.equal(mutations.length, 2);
    assert.notEqual(mutations[0][2], mutations[1][2]);
    assert.equal(mutations[1][1], "00008");
});

test("a transport failure while closed defers feedback but never resets the unresolved operation", async () => {
    const response = deferred();
    const mutations = [],
        reads = [];
    const { context, toasts } = setup({
        acceptVisit: (...args) => {
            mutations.push(args);
            return response.promise;
        },
        findVisitReceipt: async (...args) => {
            reads.push(args);
            return receipt();
        },
    });
    await identify(context);
    const pending = context.acceptVisit();
    context.handleModalClose();
    response.reject(new Error("lost response"));
    await pending;
    assert.deepEqual(toasts, []);

    context.handleModalShow();
    await settle();
    assert.deepEqual(toasts, []);
    await context.findPassManually();

    assert.equal(mutations.length, 1);
    assert.deepEqual(reads, [[...mutations[0].slice(0, 3), false]]);
});

test("native close, navigation and root destruction unsubscribe owned hooks and ignore late work", async () => {
    const request = deferred();
    const { context, wire, document, dialog, interceptors, cancellations } = setup({
        findPass: () => request.promise,
    });
    context.handleModalShow();
    const search = context.findPassManually();
    dialog.dispatchEvent(new Event("close"));
    assert.equal(context.opened, false);
    context.handleModalShow();
    assert.deepEqual(cancellations, ["findPass", "resetIdentification"]);
    document.dispatchEvent(new Event("livewire:navigating"));
    request.resolve(identified());
    await search;
    context.handleModalShow();
    assert.equal(context.opened, false);
    assert.equal(wire.preview, null);
    assert.equal(interceptors.size, 0);
    assert.deepEqual(cancellations, ["findPass", "resetIdentification", "resetIdentification"]);
    const event = new Event("modal-show");
    event.detail = { name: "visit-registration" };
    document.dispatchEvent(event);
    assert.equal(context.opened, false);
});

test("a missing ancillary receipt cannot trap a known committed operation in uncertainty", async () => {
    let mutations = 0;
    const { context, wire } = setup({
        acceptVisit: async () => {
            mutations++;
            return confirmed();
        },
        findVisitReceipt: async () => null,
    });
    await identify(context);
    await context.acceptVisit();
    assert.equal(wire.stage, "result");
    assert.equal(wire.facts.points_awarded, 3);
    await context.reset();
    assert.equal(wire.stage, "identify");
    assert.equal(mutations, 1);
});

test("manual-first open never starts camera or requests permission", async () => {
    const { context, native, startup, focus } = setup();
    native.isSecureContext = true;
    context.handleModalShow();
    await settle();
    assert.equal(startup.length, 0);
    assert.equal(context.cameraEnabled, false);
    assert.equal(focus.at(-1), "manual");
});

test("typing and erasing preserve explicitly active capture and the editable code", async () => {
    const { context, camera, native, refs, stopped } = setup();
    native.isSecureContext = true;
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    for (const value of ["00007", ""]) {
        refs.manual.value = value;
        context.manualChanged();
        assert.equal(camera.active, true);
        assert.equal(refs.manual.value, value);
        assert.deepEqual(stopped, []);
    }
    context.stopCamera();
    assert.equal(context.cameraEnabled, false);
    assert.equal(camera.active, false);
});

test("explicit deactivation hides pending capture, releases late permission and clears manual input", async () => {
    const permission = deferred();
    const { context, camera, native, refs, controls, stopped } = setup();
    native.isSecureContext = true;
    BrowserQRCodeReader.prototype.decodeFromConstraints.mock.mockImplementation(
        () => permission.promise,
    );
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    assert.equal(camera.starting, true);
    context.stopCamera();
    assert.equal(context.cameraEnabled, false);
    assert.equal(refs.manual.value, "");
    permission.resolve(controls);
    await settle();
    assert.equal(camera.active, false);
    assert.equal(camera.starting, false);
    assert.deepEqual(stopped, ["owned-camera"]);
    assert.equal(context.cameraEnabled, false);
});

test("returning to identification stays manual until explicit activation targets the current video", async () => {
    const { context, native, startup, refs } = setup();
    native.isSecureContext = true;
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    const originalVideo = startup[0][1];
    context.stopCamera();
    context.requestCameraStart();
    await settle();
    assert.equal(startup.length, 2);
    assert.equal(startup[1][1], originalVideo);
    await context.findPassManually();
    await context.reset();
    await settle();
    assert.equal(startup.length, 2);
    assert.equal(context.cameraEnabled, false);
    context.requestCameraStart();
    await settle();
    assert.equal(startup.length, 3);
    assert.equal(startup[2][1], refs.video);
    assert.notEqual(startup[2][1], originalVideo);
});

test("uncertain primary retry reads only the retained Visit and keeps confirmation until facts are known", async () => {
    const mutations = [],
        reads = [];
    const { context, wire, toasts } = setup({
        acceptVisit: async (...args) => {
            mutations.push(args);
            throw new Error("lost response");
        },
        findVisitReceipt: async (...args) => {
            reads.push(args);
            return reads.length === 1 ? null : receipt();
        },
    });
    await identify(context);
    const preview = wire.preview;
    await context.acceptVisit();
    assert.equal(wire.stage, "confirm");
    assert.equal(wire.preview, preview);
    assert.deepEqual(toasts, [{ variant: "danger", text: "Action needs attention" }]);
    assert.equal(reads.length, 0);
    await context.reset();
    await context.findPassManually();
    assert.equal(wire.stage, "confirm");
    await context.acceptVisit();
    assert.equal(wire.stage, "confirm");
    assert.equal(mutations.length, 1);
    assert.deepEqual(reads, [mutations[0].slice(0, 3)]);
    await context.acceptVisit();
    assert.equal(wire.stage, "result");
    assert.equal(mutations.length, 1);
    assert.deepEqual(reads, [mutations[0].slice(0, 3), mutations[0].slice(0, 3)]);
});

test("reopen honors committed native facts even when closing interrupted receipt completion", async () => {
    const response = deferred();
    let mutations = 0;
    const { context, wire } = setup({
        acceptVisit: async () => {
            mutations++;
            return { outcome: "unknown" };
        },
        findVisitReceipt: () => response.promise,
    });
    await identify(context);
    await context.acceptVisit();
    const reading = context.acceptVisit();
    await settle();
    Object.assign(wire, { stage: "result", facts: confirmed().facts, details: receipt().details });
    context.handleModalClose();
    await reading;
    assert.equal(wire.facts.points_awarded, 3);
    context.handleModalShow();
    await settle();
    assert.equal(wire.stage, "identify");
    assert.equal(wire.facts, null);
    assert.equal(mutations, 1);
    response.resolve(receipt());
});

test("optional receipt transport failure toasts once without replacing the positive result", async () => {
    const { context, wire, toasts } = setup({
        findVisitReceipt: async () => {
            throw new Error("lost details");
        },
    });
    await identify(context);
    await context.acceptVisit();
    assert.equal(wire.stage, "result");
    assert.equal(wire.facts.points_awarded, 3);
    assert.deepEqual(toasts, [{ variant: "danger", text: "Registered; review the message" }]);
});

test("definitive rejection retries the same selected UUID and offer", async () => {
    const mutations = [];
    const { context, wire } = setup({
        acceptVisit: async (...args) => {
            mutations.push(args);
            return mutations.length === 1 ? { outcome: "rejected" } : confirmed();
        },
    });
    await identify(context);
    await context.acceptVisit(7);
    assert.equal(context.visitPhase, "ready");
    assert.equal(wire.stage, "confirm");
    await context.acceptVisit(999);
    assert.equal(mutations.length, 2);
    assert.deepEqual(mutations[1], mutations[0]);
    assert.equal(mutations[1][3], 7);
    assert.equal(wire.stage, "result");
});

test("stale receipt cannot clear a newer same-operation read", async () => {
    const old = deferred(),
        next = deferred();
    const reads = [],
        mutations = [];
    const { context, wire } = setup({
        acceptVisit: async (...args) => {
            mutations.push(args);
            return { outcome: "unknown" };
        },
        findVisitReceipt: (...args) => {
            reads.push(args);
            return reads.length === 1 ? old.promise : next.promise;
        },
    });
    await identify(context);
    await context.acceptVisit(7);
    const first = context.acceptVisit();
    await settle();
    context.handleModalClose();
    context.handleModalShow();
    await settle();
    const second = context.findPassManually();
    await settle();
    old.resolve(receipt());
    await first;
    assert.equal(context.busy, true);
    assert.equal(wire.stage, "identify");
    assert.equal(context.visitPhase, "idle");
    assert.deepEqual(reads, [mutations[0].slice(0, 3), [...mutations[0].slice(0, 3), false]]);
    next.resolve(receipt());
    await second;
    assert.equal(context.busy, false);
    assert.equal(wire.stage, "confirm");
    assert.equal(mutations.length, 1);
});

test("destroy during acceptance cannot announce or read late completion", async () => {
    const response = deferred();
    let reads = 0;
    const { context, interceptors, toasts } = setup({
        acceptVisit: () => response.promise,
        findVisitReceipt: async () => {
            reads++;
            return receipt();
        },
    });
    await identify(context);
    const pending = context.acceptVisit(7);
    context.destroy();
    response.reject(new Error("private late transport"));
    await pending;
    context.handleModalShow();
    await context.acceptVisit(7);
    assert.equal(context.opened, false);
    assert.equal(context.busy, false);
    assert.equal(context.visitPhase, "idle");
    assert.equal(interceptors.size, 0);
    assert.equal(reads, 0);
    assert.deepEqual(toasts, []);
});

test("closing an in-flight receipt cancels its native render without clearing the same-operation identity", async () => {
    const response = deferred(),
        reads = [],
        mutations = [];
    const { context, wire, cancellations, renders } = setup({
        acceptVisit: async (...args) => {
            mutations.push(args);
            return { outcome: "unknown" };
        },
        findVisitReceipt: async (...args) => {
            reads.push(args);
            return response.promise;
        },
    });
    await identify(context);
    await context.acceptVisit();
    const read = context.findVisitReceipt();
    await settle();
    context.handleModalClose();
    assert.deepEqual(cancellations, ["findVisitReceipt"]);
    response.resolve(receipt());
    await read;
    assert.equal(wire.stage, "confirm");
    assert.equal(renders.includes("findVisitReceipt"), false);
    context.handleModalShow();
    await settle();
    await context.findPassManually();
    assert.deepEqual(reads, [mutations[0].slice(0, 3), [...mutations[0].slice(0, 3), false]]);
    assert.equal(wire.stage, "confirm");
    assert.equal(wire.facts, null);
});

test("transport failure keeps a safe inline detail and uses only an attention toast", async () => {
    const { context, wire, toasts } = setup({
        findPass: async () => {
            throw new Error("private transport payload");
        },
    });
    context.handleModalShow();
    await context.findPassManually();
    assert.equal(context.errorMessage, "Read unavailable");
    assert.equal(wire.stage, "identify");
    assert.equal(wire.preview, null);
    assert.deepEqual(toasts, [{ variant: "danger", text: "Action needs attention" }]);
    assert.equal(context.busy, false);
});

test("optional detail failure keeps the positive result and its persistent safe detail", async () => {
    const { context, wire, toasts } = setup({
        findVisitReceipt: async () => {
            throw new Error("private provider payload");
        },
    });
    await identify(context);
    await context.acceptVisit(7);
    assert.equal(context.errorMessage, "Registered; details unavailable");
    assert.equal(wire.stage, "result");
    assert.equal(wire.facts.points_awarded, 3);
    assert.deepEqual(toasts, [{ variant: "danger", text: "Registered; review the message" }]);
});

test("camera lookup keeps manual mode hidden until explicit clean deactivation", async () => {
    const response = deferred();
    const calls = [];
    const { context, refs, wire, native, camera, focus, toasts } = setup({
        findPass: (...args) => {
            calls.push(["findPass", ...args]);
            return response.promise;
        },
        resetIdentification: async () => {
            calls.push(["resetIdentification"]);
            return null;
        },
    });
    mock.method(wire, "logCameraFatalError", async (...args) => {
        calls.push(["logCameraFatalError", ...args]);
    });
    mock.method(wire, "logTransportError", async (...args) => {
        calls.push(["logTransportError", ...args]);
    });
    native.isSecureContext = true;
    context.handleModalShow();
    wire.errorCode = "invalid_credential";
    assert.equal(context.errorMessage, "Check the code");

    context.requestCameraStart();
    assert.equal(context.errorMessage, "");
    assert.equal(wire.errorCode, "invalid_credential");
    assert.deepEqual(calls, []);
    assert.deepEqual(toasts, []);
    await settle();
    assert.equal(context.errorMessage, "");
    assert.deepEqual(calls, []);
    assert.deepEqual(toasts, []);

    const pending = context.findPass("token", "private-credential");
    assert.equal(camera.active, false);
    assert.equal(context.cameraEnabled, true);
    assert.equal(context.busy, true);
    wire.errorCode = "invalid_credential";
    response.resolve(null);
    await pending;
    await settle();
    assert.equal(context.errorMessage, "Check the code");
    assert.deepEqual(calls, [["findPass", "token", "private-credential"]]);
    assert.equal(context.cameraEnabled, true);
    assert.equal(focus.at(-1), "camera-control");
    wire.errorCode = "invalid_credential";
    refs.manual.value = "00007";
    await context.reset(true);
    await settle();
    assert.equal(context.cameraEnabled, false);
    assert.equal(refs.manual.value, "");
    assert.equal(wire.errorCode, "");
    assert.equal(context.errorMessage, "");
    assert.deepEqual(calls, [["findPass", "token", "private-credential"], ["resetIdentification"]]);
    assert.equal(focus.at(-1), "manual");
});

test("failed manual identification returns focus to the editable input", async () => {
    const { context, refs, focus } = setup({ findPass: async () => null });
    context.handleModalShow();
    await settle();
    focus.push("search-button");
    await context.findPassManually();
    await settle();
    assert.equal(focus.at(-1), "manual");
    assert.equal(refs.manual.value, "00007");
});

test("ancillary receipt completion cannot steal focus from another result control", async () => {
    const response = deferred();
    let reads = 0;
    const { context, focus } = setup({
        findVisitReceipt: () => (++reads === 1 ? receipt() : response.promise),
    });
    await identify(context);
    await context.acceptVisit(7);
    await settle();
    focus.push("another-result-control");
    const pending = context.findVisitReceipt();
    response.resolve(receipt());
    await pending;
    await settle();
    assert.equal(focus.at(-1), "another-result-control");
});

for (const capability of [false, undefined]) {
    test(`touch or unknown entry capability avoids opening the editable input (${capability})`, async () => {
        const { context, native, focus } = setup({ findPass: async () => null });
        native.matchMedia = capability === undefined ? undefined : () => ({ matches: capability });
        context.handleModalShow();
        await settle();
        assert.equal(context.manualAutofocus, false);
        assert.equal(focus.at(-1), "close-button");
        await context.findPassManually();
        await settle();
        assert.equal(focus.at(-1), "manual");
        await context.reset();
        await settle();
        assert.equal(focus.at(-1), "close-button");
    });
}

test("fine-pointer entry keeps manual autofocus and ordinary validation focus", async () => {
    const { context, focus } = setup({ findPass: async () => null });
    context.handleModalShow();
    await settle();
    assert.equal(context.manualAutofocus, true);
    assert.equal(focus.at(-1), "manual");
    await context.findPassManually();
    await settle();
    assert.equal(focus.at(-1), "manual");
});

test("deactivation cancels pending permission before native reset completes", async () => {
    const permission = deferred(),
        resetResponse = deferred(),
        started = deferred();
    const { context, camera, native, controls, stopped, refs, wire, focus, startup } = setup({
        resetIdentification: () => resetResponse.promise,
    });
    native.isSecureContext = true;
    BrowserQRCodeReader.prototype.decodeFromConstraints.mock.mockImplementation((...args) => {
        startup.push(args);
        started.resolve();
        return permission.promise;
    });
    context.handleModalShow();
    wire.errorCode = "invalid_credential";
    context.requestCameraStart();
    await started.promise;
    assert.equal(camera.starting, true);

    const resetting = context.reset(true);
    assert.equal(camera.starting, false);
    assert.equal(context.cameraEnabled, true);
    assert.equal(refs.manual.value, "");
    assert.equal(context.busy, true);
    startup[0][2]({ getText: () => "obsolete-token" }, undefined, controls);
    permission.resolve(controls);
    await settle();
    assert.deepEqual(stopped, ["owned-camera"]);
    assert.equal(wire.stage, "identify");
    resetResponse.resolve(null);
    await resetting;
    await settle();
    assert.equal(context.cameraEnabled, false);
    assert.equal(wire.errorCode, "");
    assert.equal(context.busy, false);
    assert.equal(focus.at(-1), "manual");
});

test("cancelled deactivation cannot hide or stop a successor camera", async () => {
    const response = deferred();
    let resets = 0;
    const { context, camera, native, startup, stopped, cancellations, focus } = setup({
        resetIdentification: () => (++resets === 1 ? response.promise : null),
    });
    native.isSecureContext = true;
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    const resetting = context.reset(true);
    context.handleModalClose();
    context.handleModalShow();
    await settle();
    context.requestCameraStart();
    await settle();
    assert.equal(startup.length, 2);
    assert.equal(camera.active, true);
    assert.equal(context.cameraEnabled, true);
    focus.push("successor-control");

    response.resolve(null);
    await resetting;
    await settle();
    assert.deepEqual(cancellations, ["resetIdentification"]);
    assert.equal(context.cameraEnabled, true);
    assert.equal(camera.active, true);
    assert.deepEqual(stopped, ["owned-camera"]);
    assert.equal(focus.at(-1), "successor-control");
});

test("optional receipt rejection cannot refocus an unchanged known result", async () => {
    const response = deferred();
    let reads = 0;
    const { context, wire, focus, toasts } = setup({
        findVisitReceipt: () => (++reads === 1 ? receipt() : response.promise),
    });
    await identify(context);
    await context.acceptVisit(7);
    await settle();
    assert.equal(focus.at(-1), "close-button");
    focus.push("operator-selected-back");
    const pending = context.findVisitReceipt();

    response.reject(new Error("private native receipt failure"));
    await pending;
    await settle();
    assert.equal(focus.at(-1), "operator-selected-back");
    assert.equal(wire.stage, "result");
    assert.equal(wire.facts.points_awarded, 3);
    assert.equal(context.visitPhase, "settled");
    assert.deepEqual(toasts, [{ variant: "danger", text: "Registered; review the message" }]);
});

test("entry focus rechecks pointer capability when the same modal reopens", async () => {
    const { context, native, focus } = setup();
    const queries = [];
    let matches = false;
    native.matchMedia = (query) => {
        queries.push(query);
        return { matches };
    };
    for (const [capability, expected] of [
        [false, "close-button"],
        [true, "manual"],
        [false, "close-button"],
    ]) {
        matches = capability;
        context.handleModalShow();
        await settle();
        assert.equal(focus.at(-1), expected);
        context.handleModalClose();
    }
    assert.ok(queries.length >= 3);
    assert.deepEqual([...new Set(queries)], ["(hover: hover) and (pointer: fine)"]);
});

test("touch cancellation keeps reset guarded and ignores late permission and decoded token", async () => {
    const permission = deferred(),
        resetResponse = deferred(),
        started = deferred();
    const {
        context,
        camera,
        native,
        controls,
        stopped,
        refs,
        wire,
        focus,
        startup,
        renders,
        toasts,
    } = setup({
        resetIdentification: () => resetResponse.promise,
    });
    native.isSecureContext = true;
    native.matchMedia = () => ({ matches: false });
    BrowserQRCodeReader.prototype.decodeFromConstraints.mock.mockImplementation((...args) => {
        startup.push(args);
        started.resolve();
        return permission.promise;
    });
    context.handleModalShow();
    context.requestCameraStart();
    await started.promise;
    const resetting = context.reset(true);
    context.requestCameraStart();
    assert.equal(context.busy, true);
    assert.equal(camera.starting, false);
    assert.equal(context.cameraEnabled, true);
    assert.equal(startup.length, 1);
    permission.resolve(controls);
    await settle();
    assert.equal(context.busy, true);
    assert.deepEqual(stopped, ["owned-camera"]);
    resetResponse.resolve(null);
    await resetting;
    await settle();
    startup[0][2]({ getText: () => "obsolete-token" }, undefined, controls);
    await settle();
    assert.equal(context.cameraEnabled, false);
    assert.equal(camera.active, false);
    assert.equal(context.busy, false);
    assert.equal(refs.manual.value, "");
    assert.equal(wire.preview, null);
    assert.equal(focus.at(-1), "close-button");
    assert.deepEqual(renders, ["resetIdentification"]);
    assert.deepEqual(toasts, []);
});

test("queued entry focus never targets a destroyed modal", async () => {
    const { context, native, focus } = setup();
    native.matchMedia = () => ({ matches: false });
    context.handleModalShow();
    context.destroy();
    await settle();
    assert.deepEqual(focus, []);
});

test("camera-origin uncertainty retains exact identity across close and ignores new intent", async () => {
    const mutations = [],
        reads = [];
    const { context, native, startup, controls, refs, wire, focus } = setup({
        acceptVisit: async (...args) => {
            mutations.push(args);
            return { outcome: "unknown" };
        },
        findVisitReceipt: async (...args) => {
            reads.push(args);
            return reads.length === 1 ? null : receipt();
        },
    });
    native.isSecureContext = true;
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    const token = "a".repeat(64);
    startup[0][2]({ getText: () => token }, undefined, controls);
    await settle();
    assert.equal(focus.at(-1), "confirm-button");
    await context.acceptVisit(7);
    await context.reset(true);
    context.handleModalClose();
    context.handleModalShow();
    await settle();
    refs.manual.value = "00008";
    await context.findPassManually();
    context.requestCameraStart();
    await context.acceptVisit(999);
    await context.acceptVisit(999);
    await settle();
    await context.findPassManually();
    assert.equal(startup.length, 2);
    assert.equal(mutations.length, 1);
    assert.deepEqual(mutations[0].slice(0, 2), ["token", token]);
    assert.equal(mutations[0][3], 7);
    assert.deepEqual(reads, [
        [...mutations[0].slice(0, 3), false],
        [...mutations[0].slice(0, 3), false],
    ]);
    assert.equal(wire.stage, "confirm");
    assert.equal(focus.at(-1), "confirm-button");
    assert.equal(JSON.stringify(context).includes(token), false);
});

test("pending lookup prevents dismissal without cancelling the selected read", async () => {
    const response = deferred();
    const { context, dialog, wire, renders, cancellations } = setup({
        findPass: () => response.promise,
    });
    context.handleModalShow();
    const pending = context.findPassManually();
    await settle();
    const event = new Event("cancel", { cancelable: true });
    dialog.dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(context.opened, true);
    assert.equal(context.busy, true);
    assert.deepEqual(cancellations, []);
    response.resolve(identified());
    await pending;
    await settle();
    assert.equal(wire.stage, "confirm");
    assert.deepEqual(renders, ["findPass"]);
    assert.equal(context.busy, false);
});

test("allowed cancellation cleans up only when the native dialog actually closes", async () => {
    const { context, dialog, focus } = setup();
    context.handleModalShow();
    await settle();

    dialog.dispatchEvent(new Event("cancel", { cancelable: true }));
    assert.equal(context.opened, true);
    assert.notEqual(focus.at(-1), "trigger");
    dialog.dispatchEvent(new Event("close"));
    assert.equal(context.opened, false);
    assert.equal(focus.at(-1), "trigger");
});

test("successful acceptance focuses Close only after its initial receipt work settles", async () => {
    const details = deferred();
    const { context, wire, focus } = setup({ findVisitReceipt: () => details.promise });
    await identify(context);
    await settle();
    const before = focus.length;
    const accepting = context.acceptVisit();
    await settle();
    assert.equal(wire.facts.points_awarded, 3);
    assert.equal(context.busy, true);
    assert.deepEqual(focus.slice(before), []);
    details.resolve(receipt());
    await accepting;
    await settle();
    assert.equal(context.busy, false);
    assert.equal(focus.at(-1), "close-button");
});

test("trigger keyboard entry overrides coarse capability while pointer reopening avoids text entry", async () => {
    const { context, document, native, focus } = setup();
    native.matchMedia = () => ({ matches: false });
    const trigger = { closest: (selector) => (selector === "#register-visit-trigger" ? {} : null) };
    const send = (type, target = null) => {
        const event = new Event(type);
        Object.defineProperty(event, "target", { value: target });
        document.dispatchEvent(event);
    };
    send("keydown", trigger);
    context.handleModalShow();
    await settle();
    assert.equal(focus.at(-1), "manual");
    context.handleDialogClose();
    await settle();
    send("pointerdown", trigger);
    send("keydown");
    context.handleModalShow();
    await settle();
    assert.equal(focus.at(-1), "close-button");
    context.destroy();
    const before = [...focus];
    send("keydown", trigger);
    context.handleModalShow();
    await settle();
    assert.deepEqual(focus, before);
});

test("pending SVG dismissal click is intercepted before the native close wrapper", async () => {
    const response = deferred();
    const { context, dialog, cancellations, renders } = setup({ findPass: () => response.promise });
    context.handleModalShow();
    const pending = context.findPassManually();
    await settle();
    const event = new Event("click", { cancelable: true });
    Object.defineProperty(event, "target", {
        value: { closest: (selector) => (selector === "[data-flux-modal-close]" ? {} : null) },
    });
    let wrapperReached = false;
    dialog.addEventListener("click", () => {
        wrapperReached = true;
    });
    dialog.dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(wrapperReached, false);
    assert.equal(context.opened, true);
    assert.equal(context.busy, true);
    assert.deepEqual(cancellations, []);
    response.resolve(identified());
    await pending;
    assert.deepEqual(renders, ["findPass"]);
});

test("known result reopening starts with empty identification rather than old receipt details", async () => {
    const { context, wire, refs } = setup();
    await identify(context);
    await context.acceptVisit(7);
    assert.equal(wire.stage, "result");
    context.handleModalClose();
    context.handleModalShow();
    await settle();
    assert.equal(wire.stage, "identify");
    assert.equal(wire.facts, null);
    assert.equal(wire.details, null);
    assert.equal(wire.preview, null);
    assert.equal(refs.manual.value, "");
    assert.equal(context.visitPhase, "idle");
});

test("failed lookup reopening clears its old field and transport feedback", async () => {
    const { context, wire, refs } = setup();
    context.handleModalShow();
    wire.errorCode = "invalid_credential";
    context.clientErrorCode = "read_unavailable";
    context.handleModalClose();
    context.handleModalShow();
    await settle();
    assert.equal(wire.stage, "identify");
    assert.equal(wire.errorCode, "");
    assert.equal(context.clientErrorCode, "");
    assert.equal(refs.manual.value, "");
});

for (const found of [false, true]) {
    test(`fresh reopening reconciles private uncertainty before a new lookup (${found})`, async () => {
        const mutations = [],
            receipts = [],
            lookups = [];
        const { context, wire, refs } = setup({
            findPass: async (...args) => {
                lookups.push(args);
                return identified();
            },
            acceptVisit: async (...args) => {
                mutations.push(args);
                throw new Error("lost response");
            },
            findVisitReceipt: async (...args) => {
                receipts.push(args);
                return found ? receipt() : null;
            },
        });
        await identify(context);
        await context.acceptVisit(7);
        const original = mutations[0];
        context.handleModalClose();
        context.handleModalShow();
        await settle();
        assert.equal(wire.stage, "identify");
        assert.equal(wire.facts, null);
        assert.equal(refs.manual.value, "");
        assert.equal(context.visitPhase, "idle");
        assert.equal(receipts.length, 0);
        await context.findPass("manual", "22222");
        assert.deepEqual(receipts, [[...original.slice(0, 3), false]]);
        assert.equal(mutations.length, 1);
        assert.equal(lookups.length, found ? 2 : 1);
        assert.equal(wire.stage, found ? "confirm" : "identify");
        if (!found) {
            await context.findPass("manual", "33333");
            assert.deepEqual(receipts, [
                [...original.slice(0, 3), false],
                [...original.slice(0, 3), false],
            ]);
            assert.equal(lookups.length, 1);
            assert.equal(mutations.length, 1);
        }
    });
}

test("failed fresh preparation never makes an old result available again", async () => {
    let attempts = 0;
    const { context, wire, focus, native, startup } = setup({
        resetIdentification: async () => {
            if (++attempts === 1) throw new Error("reset transport failure");
            return null;
        },
    });
    native.isSecureContext = true;
    await identify(context);
    await context.acceptVisit(7);
    context.handleModalClose();
    context.handleModalShow();
    await settle();
    assert.equal(wire.stage, "result");
    assert.equal(context.presentationReady, false);
    assert.equal(context.busy, false);
    assert.equal(focus.at(-1), "close-button");
    context.requestCameraStart();
    await settle();
    assert.equal(startup.length, 0);
    assert.equal(context.cameraEnabled, false);
    await context.reset();
    await settle();
    assert.equal(context.presentationReady, true);
    assert.equal(wire.stage, "identify");
    assert.equal(wire.facts, null);
});

test("pending dismissal guard follows the current dialog after a framework replacement", async () => {
    const response = deferred();
    const { context, root, refs } = setup({ findPass: () => response.promise });
    context.handleModalShow();
    const pending = context.findPassManually();
    await settle();
    const replacement = new EventTarget();
    refs.dialog = replacement;
    const cancel = new Event("cancel", { cancelable: true });
    Object.defineProperty(cancel, "target", { value: replacement });
    root.dispatchEvent(cancel);
    assert.equal(cancel.defaultPrevented, true);
    assert.equal(context.opened, true);
    response.resolve(identified());
    await pending;
});

test("late recovery finish cannot release the newer lookup cancellation handle", async () => {
    const reply = deferred();
    let searches = 0,
        previousFinish;
    const { context, wire, cancellations, interceptors } = setup({
        findPass: () => (++searches === 1 ? identified() : reply.promise),
        acceptVisit: async () => ({ outcome: "unknown" }),
        findVisitReceipt: async () => receipt(),
    });
    const registered = [...interceptors][0];
    interceptors.delete(registered);
    interceptors.add((payload) => {
        registered({
            ...payload,
            onFinish: (callback) =>
                payload.onFinish(() => {
                    if (payload.action.name === "findVisitReceipt") previousFinish = callback;
                    else callback();
                }),
        });
    });
    await identify(context);
    await context.acceptVisit(7);
    context.handleModalClose();
    context.handleModalShow();
    await settle();
    const reading = context.findPassManually();
    await settle();
    assert.equal(typeof previousFinish, "function");
    previousFinish();
    context.manualChanged();
    assert.deepEqual(cancellations, ["findPass"]);
    reply.resolve(identified());
    await reading;
    await settle();
    assert.equal(wire.stage, "identify");
    assert.equal(wire.preview, null);
    assert.equal(context.busy, false);
});

test("owned Escape keydown blocks native dismissal only during an application request", async () => {
    const response = deferred();
    const { context, document } = setup({ findPass: () => response.promise });
    const escape = () => {
        const event = new Event("keydown", { cancelable: true });
        Object.defineProperty(event, "key", { value: "Escape" });
        document.dispatchEvent(event);
        return event.defaultPrevented;
    };
    context.handleModalShow();
    assert.equal(escape(), false);
    const reading = context.findPassManually();
    await settle();
    assert.equal(escape(), true);
    response.resolve(identified());
    await reading;
    assert.equal(escape(), false);
    context.handleModalClose();
    assert.equal(escape(), false);
});

test("reopening after a failed QR read cannot inherit a closed-cycle reset failure or toast", async () => {
    const oldReset = deferred();
    let resets = 0,
        mutations = 0;
    const { context, wire, native, refs, dialog, toasts, cancellations } = setup({
        findPass: async () => {
            throw new Error("lookup transport failure");
        },
        resetIdentification: () => (++resets === 1 ? oldReset.promise : null),
        acceptVisit: async () => {
            mutations++;
            return confirmed();
        },
    });
    native.isSecureContext = true;
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    await context.findPass("token", "a".repeat(64));
    assert.equal(context.errorMessage, "Read unavailable");
    assert.equal(context.cameraEnabled, true);
    const originalToasts = [...toasts];

    dialog.dispatchEvent(new Event("close"));
    await settle();
    context.handleModalShow();
    oldReset.reject(new Error("late closed-cycle reset failure"));
    await settle();

    assert.deepEqual(toasts, originalToasts);
    assert.equal(resets, 2);
    assert.deepEqual(cancellations, ["resetIdentification"]);
    assert.equal(context.clientErrorCode, "");
    assert.equal(context.presentationReady, true);
    assert.equal(context.cameraEnabled, false);
    assert.equal(context.busy, false);
    assert.equal(context.visitPhase, "idle");
    assert.equal(refs.manual.value, "");
    assert.equal(wire.stage, "identify");
    assert.equal(wire.errorCode, "");
    assert.equal(wire.preview, null);
    assert.equal(wire.facts, null);
    assert.equal(wire.details, null);
    assert.equal(mutations, 0);
});
