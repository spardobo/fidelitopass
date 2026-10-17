import assert from "node:assert/strict";
import { afterEach, mock, test } from "node:test";
import { BrowserQRCodeReader } from "@zxing/browser";
import {
    NotFoundException,
    ChecksumException,
    FormatException,
    RGBLuminanceSource,
    HybridBinarizer,
    BinaryBitmap,
    QRCodeReader,
} from "@zxing/library";
import fixture from "./fixtures/qr-token.json" with { type: "json" };
import { qrCameraScanner } from "../../resources/js/qr-camera-scanner.js";

test("public pure-JavaScript decoder reads the fixed BaconQrCode dummy token matrix", () => {
    const scale = 4;
    const quietZone = 4;
    const width = (fixture.rows.length + quietZone * 2) * scale;
    const pixels = new Uint8ClampedArray(width * width).fill(255);
    for (let y = 0; y < width; y++) {
        for (let x = 0; x < width; x++) {
            const row = Math.floor(y / scale) - quietZone;
            const column = Math.floor(x / scale) - quietZone;
            if (fixture.rows[row]?.[column] === "1") pixels[y * width + x] = 0;
        }
    }
    const bitmap = new BinaryBitmap(
        new HybridBinarizer(new RGBLuminanceSource(pixels, width, width)),
    );

    const result = new QRCodeReader().decode(bitmap);

    assert.equal(
        result.getText(),
        "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
    );
    assert.equal(result.getText(), fixture.payload);
    assert.throws(
        () =>
            new QRCodeReader().decode(
                new BinaryBitmap(
                    new HybridBinarizer(
                        new RGBLuminanceSource(
                            new Uint8ClampedArray(width * width).fill(255),
                            width,
                            width,
                        ),
                    ),
                ),
            ),
        NotFoundException,
    );
});

let scanner;
afterEach(() => {
    scanner?.destroy();
    mock.restoreAll();
    delete globalThis.window;
});

/** Creates an explicitly settled startup boundary.
 * @returns {object} Promise and settlement methods.
 */
function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
}

/** Binds the public provider to a current local video without native detector support.
 * @param {Function} decode Public SDK startup replacement.
 * @returns {object} Provider and observable SDK/application effects.
 */
function setup(decode) {
    const results = [];
    const stops = [];
    const controls = { stop: () => stops.push("owned") };
    let video = { id: "first-video" };
    globalThis.window = {
        isSecureContext: true,
        navigator: { mediaDevices: { getUserMedia() {} } },
    };
    const calls = [];
    mock.method(BrowserQRCodeReader.prototype, "decodeFromConstraints", (...args) => {
        calls.push(args);
        return decode === undefined ? Promise.resolve(controls) : decode(...args, controls);
    });
    scanner = qrCameraScanner((token) => results.push(token));
    scanner.$el = { querySelector: () => video };
    return {
        scanner,
        results,
        stops,
        calls,
        controls,
        replaceVideo: () => {
            video = { id: "new-video" };
            return video;
        },
    };
}

/** Waits for the already cached SDK import and queued startup continuations.
 * @returns {Promise<void>} Completed event-loop turn.
 */
async function settle() {
    await new Promise((resolve) => setImmediate(resolve));
}

test("camera starts without BarcodeDetector, prefers rear video and never requests audio", async () => {
    const { scanner, calls } = setup(async (...args) => args.at(-1));
    await scanner.start();
    assert.equal(calls.length, 1);
    assert.deepEqual(calls[0][0], {
        video: { facingMode: { ideal: "environment" } },
        audio: false,
    });
    assert.equal(calls[0][1].id, "first-video");
    assert.equal(scanner.active, true);
    assert.equal(scanner.error, "");
});

test("duplicate start clicks share one startup and active stream", async () => {
    const permission = deferred();
    const { scanner, calls, controls } = setup(() => permission.promise);
    const first = scanner.start();
    await scanner.start();
    await settle();
    assert.equal(calls.length, 1);
    permission.resolve(controls);
    await first;
    await scanner.start();
    assert.equal(calls.length, 1);
});

test("close during permission releases late scoped controls and never publishes a result", async () => {
    const permission = deferred();
    const { scanner, calls, controls, stops, results } = setup(() => permission.promise);
    const start = scanner.start();
    await settle();
    scanner.stop();
    calls[0][2]({ getText: () => "late-private-token" }, undefined, controls);
    permission.resolve(controls);
    await start;
    assert.deepEqual(stops, ["owned"]);
    assert.deepEqual(results, []);
    assert.equal(scanner.active, false);
    assert.equal(scanner.starting, false);
    assert.equal(scanner.error, "");
});

test("synchronous first read stops callback controls and delivers exactly one unchanged token", async () => {
    const { scanner, stops, results, calls } = setup(
        async (_constraints, _video, callback, controls) => {
            callback({ getText: () => "  exact-private-token  " }, undefined, controls);
            callback({ getText: () => "duplicate" }, undefined, controls);
            return controls;
        },
    );
    await scanner.start();
    calls[0][2]({ getText: () => "later" }, undefined, { stop() {} });
    assert.deepEqual(results, ["  exact-private-token  "]);
    assert.deepEqual(stops, ["owned"]);
    assert.equal(scanner.active, false);
});

test("reopen waits for obsolete startup before acquiring current video and ignores stale errors", async () => {
    const permission = deferred();
    const stops = [];
    const oldControls = { stop: () => stops.push("old") };
    const newControls = { stop: () => stops.push("new") };
    let count = 0;
    const { scanner, calls, replaceVideo } = setup(() =>
        ++count === 1 ? permission.promise : Promise.resolve(newControls),
    );
    const first = scanner.start();
    await settle();
    scanner.stop();
    const newVideo = replaceVideo();
    const next = scanner.start();
    await settle();
    assert.equal(calls.length, 1);
    permission.resolve(oldControls);
    await Promise.all([first, next]);
    assert.equal(calls.length, 2);
    assert.equal(calls[1][1], newVideo);
    assert.deepEqual(stops, ["old"]);
    calls[0][2](undefined, new Error("private native error"), oldControls);
    assert.equal(scanner.active, true);
    assert.equal(scanner.error, "");
    scanner.stop();
    assert.deepEqual(stops, ["old", "new"]);
});

test("unavailable capability does not initialize SDK camera and leaves a safe reason", async () => {
    for (const unavailable of ["insecure", "media"]) {
        const { scanner, calls } = setup(async (...args) => args.at(-1));
        if (unavailable === "insecure") window.isSecureContext = false;
        else window.navigator.mediaDevices = undefined;
        await scanner.start();
        assert.equal(scanner.error, "unavailable");
        assert.equal(calls.length, 0);
        scanner.destroy();
        mock.restoreAll();
    }
});

test("startup failures expose only denied, busy, unavailable or initialization reasons", async () => {
    for (const [name, expected] of [
        ["NotAllowedError", "denied"],
        ["SecurityError", "denied"],
        ["NotReadableError", "busy"],
        ["AbortError", "busy"],
        ["NotFoundError", "unavailable"],
        ["Error", "initialization"],
    ]) {
        const { scanner } = setup(async () => {
            throw Object.assign(new Error("secret details"), { name });
        });
        await scanner.start();
        assert.equal(scanner.error, expected);
        assert.equal(scanner.active, false);
        assert.equal(scanner.starting, false);
        scanner.destroy();
        mock.restoreAll();
    }
});

test("ordinary unreadable frames remain SDK-owned while fatal decoding stops the scoped session", async () => {
    const { scanner, calls, controls, stops, results } = setup(async (...args) => args.at(-1));
    await scanner.start();
    for (const ErrorType of [NotFoundException, ChecksumException, FormatException])
        calls[0][2](undefined, new ErrorType(), controls);
    assert.equal(scanner.active, true);
    assert.equal(scanner.error, "");
    assert.deepEqual(stops, []);
    calls[0][2](undefined, new Error("raw decoder details"), controls);
    assert.deepEqual(stops, ["owned"]);
    assert.deepEqual(results, []);
    assert.equal(scanner.active, false);
    assert.equal(scanner.error, "initialization");
});

test("destroy invalidates pending startup and cannot be reopened", async () => {
    const permission = deferred();
    const { scanner, calls, controls, stops, results } = setup(() => permission.promise);
    const start = scanner.start();
    await settle();
    scanner.destroy();
    permission.resolve(controls);
    await start;
    await scanner.start();
    calls[0][2]({ getText: () => "late" }, undefined, controls);
    assert.deepEqual(stops, ["owned"]);
    assert.deepEqual(results, []);
    assert.equal(calls.length, 1);
    assert.equal(scanner.active, false);
});
