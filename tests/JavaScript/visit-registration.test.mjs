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
    const controls = { stop: () => stopped.push("owned-camera") };
    mock.method(BrowserQRCodeReader.prototype, "decodeFromConstraints", async (...args) => {
        startup.push(args);
        return controls;
    });
    const refs = { manual: { value: "00007" }, video: { id: "initial-video" } };
    const native = { isSecureContext: false, navigator: { mediaDevices: { getUserMedia() {} } } };
    globalThis.window = native;
    const interceptors = new Set();
    const state = { stage: "identify", preview: null, facts: null, details: null, message: "" };
    const wire = {
        ...state,
        intercept(callback) {
            interceptors.add(callback);
            return () => interceptors.delete(callback);
        },
    };
    const outcomes = {
        findPass: async () => identified(),
        acceptVisit: async () => confirmed(),
        findVisitReceipt: async () => receipt(),
        resetIdentification: async () => null,
        ...responses,
    };
    for (const [name, respond] of Object.entries(outcomes)) {
        wire[name] = (...args) => {
            const pending = deferred();
            let cancelled = false;
            const finish = [],
                errors = [];
            const action = {
                name,
                cancel() {
                    cancelled = true;
                    cancellations.push(name);
                    finish.forEach((callback) => callback());
                    pending.reject(new Error("cancelled native action"));
                },
            };
            for (const interceptor of interceptors) {
                interceptor({
                    action,
                    onError: (callback) => errors.push(callback),
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
                                wire.stage = "result";
                            }
                            pending.resolve(result.outcome);
                        } else if (name === "findVisitReceipt") {
                            if (result?.facts) {
                                wire.preview = null;
                                wire.facts = result.facts;
                                wire.details = result.details;
                            }
                            if (wire.facts) wire.stage = "result";
                            pending.resolve(Boolean(result?.facts));
                        } else {
                            Object.assign(wire, state);
                            refs.manual.value = "";
                            refs.video = { id: "returned-video" };
                            pending.resolve();
                        }
                        finish.forEach((callback) => callback());
                    },
                    (error) => {
                        if (cancelled) return;
                        const preventDefault = () => {};
                        errors.forEach((callback) => callback({ preventDefault }));
                        pending.reject(error);
                        finish.forEach((callback) => callback());
                    },
                );
            return pending.promise;
        };
    }
    const context = factory({
        read_unavailable: "Read unavailable",
        unknown: "Try again safely",
        details_unavailable: "Registered; details unavailable",
    });
    const cameraRoot = new EventTarget();
    cameraRoot.querySelector = () => refs.video;
    Object.assign(context, {
        $wire: wire,
        $flux: { toast: (toast) => toasts.push(toast) },
        $el: {
            ownerDocument: document,
            querySelector: (selector) => {
                if (selector === "dialog") return dialog;
                if (selector === "[data-qr-camera]")
                    return wire.stage === "identify" ? cameraRoot : null;
                if (selector === "#visit-manual-code")
                    return wire.stage === "identify" ? refs.manual : null;
                const heading =
                    selector === "[data-visit-transport-focus]"
                        ? "status"
                        : ({ identify: "manual", confirm: "confirmation", result: "result" }[
                              wire.stage
                          ] ?? "status");
                return { focus: () => focus.push(heading) };
            },
        },
        $nextTick: (callback) => Promise.resolve().then(callback),
    });
    const camera = qrCameraScanner((token) => void context.findPass("token", token));
    camera.$el = cameraRoot;
    cameraRoot.addEventListener("qr-camera-start", () => void camera.startCamera());
    cameraRoot.addEventListener("qr-camera-stop", () => camera.stopCamera());
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
                { variant: "danger", text: "Registered; details unavailable" },
            ]);
        } else {
            assert.deepEqual(wire.details, receipt().details);
            assert.deepEqual(toasts, []);
        }
    });
}

test("unsupported camera leaves manual identification available and focuses the rendered stage", async () => {
    const { context, camera, wire, focus } = setup();
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
    assert.equal(camera.error, "unavailable");
    assert.equal(wire.stage, "identify");
    assert.equal(focus.at(-1), "manual");
    await context.findPassManually();
    await settle();
    assert.equal(wire.stage, "confirm");
    assert.equal(focus.at(-1), "confirmation");
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

test("late SDK startup cannot restart capture after manual identification begins", async () => {
    const starting = deferred();
    const { context, camera, native, controls, stopped } = setup();
    native.isSecureContext = true;
    BrowserQRCodeReader.prototype.decodeFromConstraints.mock.mockImplementation(
        () => starting.promise,
    );
    context.handleModalShow();
    context.requestCameraStart();
    await settle();
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
    await context.acceptVisit();
    assert.deepEqual(reads, [mutations[0].slice(0, 3)]);
    assert.equal(mutations.length, 1);
    assert.equal(wire.stage, "result");
    assert.equal(wire.preview, null);
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
    assert.equal(wire.stage, "result");
    await context.findVisitReceipt();
    assert.equal(reads, 1);
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
    assert.equal(focus.at(-1), "result");
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
    assert.deepEqual(toasts, [{ variant: "danger", text: "Try again safely" }]);
    await context.acceptVisit();

    assert.equal(mutations.length, 1);
    assert.deepEqual(reads, [mutations[0].slice(0, 3)]);
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
    document.dispatchEvent(new Event("livewire:navigating"));
    request.resolve(identified());
    await search;
    context.handleModalShow();
    assert.equal(context.opened, false);
    assert.equal(wire.preview, null);
    assert.equal(interceptors.size, 0);
    assert.deepEqual(cancellations, ["findPass"]);
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

test("explicit deactivation hides pending capture and releases late permission without erasing input", async () => {
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
    assert.equal(refs.manual.value, "00007");
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
    assert.deepEqual(toasts, [{ variant: "danger", text: "Try again safely" }]);
    assert.equal(reads.length, 0);
    context.handleModalClose();
    context.handleModalShow();
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
    context.handleModalShow();
    assert.equal(wire.stage, "result");
    assert.equal(wire.facts.points_awarded, 3);
    await context.reset();
    assert.equal(wire.stage, "identify");
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
    assert.deepEqual(toasts, [{ variant: "danger", text: "Registered; details unavailable" }]);
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
    const second = context.acceptVisit();
    await settle();
    old.resolve(receipt());
    await first;
    assert.equal(context.busy, true);
    assert.equal(wire.stage, "confirm");
    assert.equal(context.visitPhase, "unknown");
    assert.deepEqual(reads, [mutations[0].slice(0, 3), mutations[0].slice(0, 3)]);
    next.resolve(receipt());
    await second;
    assert.equal(context.busy, false);
    assert.equal(wire.stage, "result");
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
    await context.findVisitReceipt();
    assert.deepEqual(reads, [mutations[0].slice(0, 3), mutations[0].slice(0, 3)]);
    assert.equal(wire.stage, "result");
});
