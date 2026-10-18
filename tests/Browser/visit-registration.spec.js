import { expect, test } from "@playwright/test";
import { readFileSync } from "node:fs";
import { BarcodeFormat, QRCodeWriter } from "@zxing/library";

const fixture = JSON.parse(process.env.PLAYWRIGHT_PROMOTION_FIXTURE ?? "{}");
const updatePath = /\/livewire-[^/]+\/update$/;

/** Holds one real native response without timing sleeps or bypassing server effects.
 * @param {import('@playwright/test').Page} page Current authenticated browser.
 * @param {string} method Native action whose response is held.
 * @returns {Promise<object>} Arrival promise and explicit delivery release.
 */
async function holdAction(page, method) {
    let release, arrived;
    const delivery = new Promise((resolve) => {
        release = resolve;
    });
    const requested = new Promise((resolve) => {
        arrived = resolve;
    });
    let first = true;
    await page.route(updatePath, async (route) => {
        if (first && actionCalls(route.request()).some((call) => call.method === method)) {
            first = false;
            const response = await route.fetch();
            expect(response.ok()).toBe(true);
            arrived();
            await delivery;
            await route.fulfill({ response });
        } else await route.fallback();
    });
    return { requested, release };
}

/** Checks native visual replacement while keeping geometry and accessible naming.
 * @param {import('@playwright/test').Locator} button Button with actual pending work.
 * @param {number} width Layout width before dispatch.
 * @returns {Promise<void>} Visible spinner, hidden label and unchanged width.
 */
async function expectButtonSpinner(button, width) {
    await expect(button.locator(":scope > [data-flux-loading-indicator]")).toHaveCSS(
        "opacity",
        "1",
    );
    await expect(button.locator(":scope > span")).toHaveCSS("opacity", "0");
    expect(await button.evaluate((element) => element.offsetWidth)).toBe(width);
}

const dummyQr = JSON.parse(
    readFileSync(new URL("../JavaScript/fixtures/qr-token.json", import.meta.url)),
);

/** Opens the actual owner app and modal through existing authentication.
 * @param {import('@playwright/test').Page} page Current browser page.
 * @returns {Promise<import('@playwright/test').Locator>} Visible native Flux dialog.
 */
async function openVisits(page) {
    expect(fixture.kind, "Run through run-isolated-promotions.sh --visits").toBe("visits");
    await page.addInitScript(() => {
        window.__visitTopLayer = [];
        document.addEventListener(
            "beforetoggle",
            (event) => {
                if (
                    !(event.target instanceof Element) ||
                    !event.target.matches("dialog, [popover]")
                )
                    return;
                window.__visitTopLayer = window.__visitTopLayer.filter(
                    (element) => element !== event.target,
                );
                if (event.newState === "open") window.__visitTopLayer.push(event.target);
            },
            true,
        );
    });
    await page.goto("/login");
    await page.getByLabel("Correo electrónico", { exact: true }).fill(fixture.owner.email);
    await page.locator('input[name="password"]').fill(fixture.owner.password);
    await page.locator('[data-test="login-button"]').click();
    await expect(page).not.toHaveURL(/\/login$/);
    await page.goto("/promotions/create");
    await page.locator("#reward-title").fill("Borrador sin guardar");
    await page.getByRole("button", { name: "Registrar visita", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toBeFocused();
    return dialog;
}

/** Uses real read-only Livewire identification, not a client stage double.
 * @param {import('@playwright/test').Locator} dialog Actual open dialog.
 * @param {string} caseName Independent seeded pass case.
 * @returns {Promise<void>} Focused server-rendered confirmation.
 */
async function findManually(dialog, caseName) {
    await dialog
        .getByLabel("Código de la Tarjeta", { exact: true })
        .fill(fixture.passes[caseName].code);
    await dialog.getByRole("button", { name: "Buscar tarjeta", exact: true }).click();
    await expect(dialog.getByRole("heading", { name: "Revisa la visita" })).toBeVisible();
    const confirm = dialog.getByRole("button", { name: "Confirmar visita", exact: true });
    const primary = (await confirm.count())
        ? confirm
        : dialog.getByRole("button", { name: "Volver a leer", exact: true });
    await expect(primary).toBeFocused();
    await expect(dialog.getByText("Café de la casa", { exact: true })).toBeVisible();
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toHaveCount(0);
}

/** Reads persisted facts through the guarded, read-only tests-only router.
 * @param {import('@playwright/test').Page} page Authenticated page sharing the fixture origin.
 * @param {string} caseName Allowlisted independent pass.
 * @returns {Promise<object>} Committed operation facts and safe native effect counters.
 */
async function factsFor(page, caseName) {
    const response = await page.request.get(`/__visit-fixture/facts/${caseName}`);
    expect(response.ok()).toBe(true);
    return response.json();
}

/** Verifies painted danger feedback occupies the native top layer above the open modal.
 * @param {import('@playwright/test').Page} page Current browser page.
 * @param {string} text Expected safe Spanish feedback.
 * @returns {Promise<void>} Painted toast opened after the modal, independent of Flux pointer locking.
 */
async function expectDangerToast(page, text) {
    const toast = page
        .locator('[data-flux-toast-dialog][data-variant="danger"]')
        .filter({ hasText: text })
        .last();
    await expect(toast).toBeVisible();
    await expect(page.getByRole("dialog")).toBeVisible();
    await expect
        .poll(() =>
            toast.evaluate((element) => {
                const host = element.closest("ui-toast-group, ui-toast");
                const dialog = document.querySelector("dialog[open]");
                const order = window.__visitTopLayer;
                return {
                    open: host.matches(":popover-open"),
                    aboveModal: order.includes(dialog) && order.at(-1) === host,
                    painted: Number(getComputedStyle(element.firstElementChild).opacity) >= 0.99,
                };
            }),
        )
        .toEqual({ open: true, aboveModal: true, painted: true });
    await expect(page.getByRole("button", { name: /Comprobar/ })).toHaveCount(0);
    await expect(page.getByText("Resultado pendiente de comprobar", { exact: true })).toHaveCount(
        0,
    );
}

/** Extracts native Livewire action calls without retaining raw requests or credentials.
 * @param {import('@playwright/test').Request} request Request used only during interception.
 * @returns {object[]} Action calls for this request.
 */
function actionCalls(request) {
    return request.postDataJSON()?.components?.flatMap((component) => component.calls ?? []) ?? [];
}

/** Installs test-only secure-context/media simulation with real Canvas capture and video playback.
 * @param {import('@playwright/test').Page} page Page before its first navigation.
 * @param {string[]} rows Independent synthetic QR matrix, never a live credential.
 * @returns {Promise<void>} Installed media boundary; production SDK and decoder remain untouched.
 */
async function installCanvasCamera(page, rows) {
    await page.addInitScript(
        ({ rows }) => {
            const camera = {
                requests: 0,
                streams: [],
                frames: [],
                visibleAtAcquisition: [],
                events: [],
                showQr: false,
                previousVideo: null,
            };
            window.__visitCamera = camera;
            for (const type of [
                "loadedmetadata",
                "loadeddata",
                "canplay",
                "playing",
                "pause",
                "emptied",
                "error",
            ]) {
                document.addEventListener(
                    type,
                    (event) => {
                        const video = event.target;
                        if (!(video instanceof HTMLVideoElement) || !video.closest("dialog"))
                            return;
                        camera.events.push({
                            type,
                            readyState: video.readyState,
                            paused: video.paused,
                            muted: video.muted,
                            width: video.videoWidth,
                            height: video.videoHeight,
                            errorCode: video.error?.code ?? null,
                        });
                        camera.events = camera.events.slice(-12);
                    },
                    true,
                );
            }
            Object.defineProperty(window, "isSecureContext", { configurable: true, value: true });
            Object.defineProperty(navigator, "mediaDevices", {
                configurable: true,
                value: {
                    getUserMedia: async () => {
                        const capture = camera.requests++;
                        if (camera.holdPermission)
                            await new Promise((resolve) => {
                                camera.releasePermission = resolve;
                            });
                        const video = document.querySelector("dialog video");
                        camera.visibleAtAcquisition.push(Boolean(video?.getClientRects().length));
                        camera.frames[capture] = 0;
                        const canvas = document.createElement("canvas");
                        canvas.width = 640;
                        canvas.height = 480;
                        const context = canvas.getContext("2d");
                        const stream = canvas.captureStream(20);
                        camera.streams.push(stream);
                        const draw = () => {
                            camera.frames[capture]++;
                            context.fillStyle = "white";
                            context.fillRect(0, 0, 640, 480);
                            if (camera.showQr) {
                                const scale = Math.floor(400 / (rows.length + 8));
                                const left = (640 - rows.length * scale) / 2;
                                const top = (480 - rows.length * scale) / 2;
                                context.fillStyle = "black";
                                rows.forEach((row, y) =>
                                    [...row].forEach((bit, x) => {
                                        if (bit === "1")
                                            context.fillRect(
                                                left + x * scale,
                                                top + y * scale,
                                                scale,
                                                scale,
                                            );
                                    }),
                                );
                            }
                            if (stream.getTracks().some((track) => track.readyState === "live"))
                                requestAnimationFrame(draw);
                        };
                        draw();
                        return stream;
                    },
                },
            });
        },
        { rows },
    );
}

/** Observes actual current video frames before asking the real decoder to read the fixture.
 * @param {import('@playwright/test').Locator} dialog Current Flux stage containing a live video.
 * @param {import('@playwright/test').TestInfo} testInfo Existing runner's evidence attachment owner.
 * @returns {Promise<void>} Dimensions, playback and visible dark/light QR pixels observed on that video.
 */
async function showQrOnCurrentVideo(dialog, testInfo) {
    const video = dialog.locator("video");
    await expect(video).toBeVisible();
    await expect
        .poll(() =>
            video.evaluate((element) => ({
                dimensions: element.videoWidth > 0 && element.videoHeight > 0,
                currentFrame: element.readyState >= 2,
                readyState: element.readyState,
                paused: element.paused,
                muted: element.muted,
                errorCode: element.error?.code ?? null,
                tracks: [...(element.srcObject?.getTracks() ?? [])].map(
                    (track) => track.readyState,
                ),
                fixtureFrames: window.__visitCamera.frames,
                mediaEvents: window.__visitCamera.events,
            })),
        )
        .toMatchObject({ dimensions: true, currentFrame: true, paused: false });
    const pixels = await video.evaluate(async (element) => {
        const canvas = document.createElement("canvas");
        canvas.width = 640;
        canvas.height = 480;
        const context = canvas.getContext("2d");
        window.__visitCamera.showQr = true;
        return new Promise((resolve) => {
            const sample = () => {
                context.drawImage(element, 0, 0, 640, 480);
                const image = context.getImageData(0, 0, 640, 480).data;
                let dark = 0;
                let light = 0;
                for (let index = 0; index < image.length; index += 4) {
                    if (image[index] < 50) dark++;
                    if (image[index] > 200) light++;
                }
                if (dark > 1000 && light > 1000)
                    resolve({ dark, light, image: canvas.toDataURL("image/png") });
                else element.requestVideoFrameCallback(sample);
            };
            element.requestVideoFrameCallback(sample);
        });
    });
    expect(pixels.dark).toBeGreaterThan(1000);
    expect(pixels.light).toBeGreaterThan(1000);
    await testInfo.attach("actual-video-qr-frame", {
        body: Buffer.from(pixels.image.split(",")[1], "base64"),
        contentType: "image/png",
    });
    await expect(
        dialog.getByRole("button", { name: "Confirmar visita", exact: true }),
    ).toBeFocused();
}

for (const width of [1280, 375]) {
    test(`manual-first composition, viewport and unsaved editor at ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 800 });
        await installCanvasCamera(page, dummyQr.rows);
        const dialog = await openVisits(page);
        const input = dialog.getByLabel("Código de la Tarjeta", { exact: true });
        const search = dialog.getByRole("button", { name: "Buscar tarjeta", exact: true });
        await expect(input).toHaveValue("");
        await expect(input).toHaveAttribute("placeholder", "Ej: 583");
        await expect(
            dialog.getByRole("button", { name: "Activar cámara", exact: true }),
        ).toBeVisible();
        await expect(dialog.locator("video")).toBeHidden();
        await expect(dialog.locator("[data-qr-camera-region]")).toBeHidden();
        expect(await page.evaluate(() => window.__visitCamera.requests)).toBe(0);
        await expect(
            dialog.getByText("5 dígitos. Conserva los ceros iniciales.", { exact: true }),
        ).toBeVisible();
        await expect(dialog.locator("#visit-code-help svg")).toBeVisible();
        const clearance = await input.evaluate((element) => {
            const field = element.getBoundingClientRect();
            const scroll = element.closest("[aria-busy]").getBoundingClientRect();
            const style = getComputedStyle(element);
            const outline = parseFloat(style.outlineWidth) + parseFloat(style.outlineOffset);
            return {
                left: field.left - outline - scroll.left,
                right: scroll.right - field.right - outline,
            };
        });
        expect(
            clearance.left,
            "focused outline must fit inside scroll clip",
        ).toBeGreaterThanOrEqual(0);
        expect(clearance.right).toBeGreaterThanOrEqual(0);
        const field = await input.boundingBox();
        const button = await search.boundingBox();
        expect(Math.abs(field.width - button.width)).toBeLessThanOrEqual(2);
        expect(Math.abs(field.height - button.height)).toBeLessThanOrEqual(2);
        if (width > 640) expect(Math.abs(field.y - button.y)).toBeLessThanOrEqual(2);
        else {
            expect(button.y).toBeGreaterThanOrEqual(field.y + field.height);
            const centers = await dialog
                .locator('ui-label[for="visit-manual-code"]')
                .evaluate((element) => {
                    const range = document.createRange();
                    range.selectNodeContents(element);
                    const text = range.getBoundingClientRect();
                    const input = element.ownerDocument
                        .getElementById(element.getAttribute("for"))
                        .getBoundingClientRect();
                    return {
                        text: text.x + text.width / 2,
                        input: input.x + input.width / 2,
                    };
                });
            expect(Math.abs(centers.text - centers.input)).toBeLessThanOrEqual(2);
        }
        const bounds = await dialog.boundingBox();
        expect(bounds.y).toBeGreaterThanOrEqual(0);
        expect(bounds.y + bounds.height).toBeLessThanOrEqual(800);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
            true,
        );
        await expect(dialog.getByRole("button", { name: "Cerrar", exact: true })).toBeInViewport();
        await testInfo.attach(`manual-first-${width}`, {
            body: await page.screenshot(),
            contentType: "image/png",
        });
        await page.keyboard.press("Escape");
        await expect(dialog).not.toBeVisible();
        await expect(
            page.getByRole("button", { name: "Registrar visita", exact: true }),
        ).toBeFocused();
        await expect(page.locator("#reward-title")).toHaveValue("Borrador sin guardar");
        await expect(page).toHaveURL(/\/promotions\/create$/);
        expect(await factsFor(page, `layout-${width}`)).toEqual({
            visits: [],
            effects: { GET: 0, PATCH: 0 },
        });
    });
}

test("leading-zero manual identification requires explicit confirmation and commits one Visit", async ({
    page,
}) => {
    const dialog = await openVisits(page);
    const search = dialog.getByRole("button", { name: "Buscar tarjeta", exact: true });
    const searchWidth = await search.evaluate((element) => element.offsetWidth);
    const searchHold = await holdAction(page, "findPass");
    const finding = findManually(dialog, "manual");
    await searchHold.requested;
    try {
        await expectButtonSpinner(search, searchWidth);
    } finally {
        searchHold.release();
    }
    await finding;
    expect(await factsFor(page, "manual")).toEqual({ visits: [], effects: { GET: 0, PATCH: 0 } });
    const confirm = dialog.getByRole("button", { name: "Confirmar visita", exact: true });
    const confirmWidth = await confirm.evaluate((element) => element.offsetWidth);
    const confirmation = await holdAction(page, "acceptVisit");
    await confirm.click();
    await confirmation.requested;
    try {
        await expectButtonSpinner(confirm, confirmWidth);
    } finally {
        confirmation.release();
    }
    await expect(dialog.getByRole("button", { name: "Cerrar", exact: true })).toBeFocused();
    await expect(dialog.getByText("1 / 8 puntos", { exact: true })).toBeVisible();
    const facts = await factsFor(page, "manual");
    expect(facts.visits).toHaveLength(1);
    expect(facts.visits[0].awarded_points).toBe(1);
    expect(facts.effects).toEqual({ GET: 1, PATCH: 1 });
    const back = dialog.getByRole("button", { name: "Volver a leer", exact: true });
    const backWidth = await back.evaluate((element) => element.offsetWidth);
    const reset = await holdAction(page, "resetIdentification");
    await back.click();
    await reset.requested;
    try {
        await expectButtonSpinner(back, backWidth);
    } finally {
        reset.release();
    }
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toBeFocused();
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toHaveValue("");
});

test("camera denial, lookup and definitive provider failure retain usable origin", async ({
    page,
}) => {
    await page.addInitScript(() => {
        Object.defineProperty(window, "isSecureContext", { configurable: true, value: true });
        Object.defineProperty(navigator, "mediaDevices", {
            configurable: true,
            value: {
                getUserMedia: async () => {
                    throw new DOMException("Synthetic denial", "NotAllowedError");
                },
            },
        });
    });
    const dialog = await openVisits(page);
    await dialog.getByRole("button", { name: "Activar cámara", exact: true }).click();
    await expect(dialog.getByText(/No se permitió el acceso a la cámara/)).toBeVisible();
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toBeHidden();
    await dialog.getByRole("button", { name: "Desactivar cámara", exact: true }).click();
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toBeFocused();
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toHaveValue("");
    await dialog.getByLabel("Código de la Tarjeta", { exact: true }).fill("99999");
    await dialog.getByRole("button", { name: "Buscar tarjeta", exact: true }).click();
    await expectDangerToast(page, "No se pudo completar la acción.");
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toHaveValue("99999");
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toBeFocused();
    await expect(dialog.locator("#visit-manual-error")).toContainText("Revisa el código");
    await findManually(dialog, "failure");
    await dialog.getByRole("button", { name: "Confirmar visita", exact: true }).click();
    await expectDangerToast(page, "No se pudo completar la acción.");
    await expect(dialog.getByRole("heading", { name: "Revisa la visita" })).toBeVisible();
    await expect(dialog.locator("#visit-action-error")).toContainText(
        "No pudimos registrar la visita",
    );
    await expect(
        dialog.getByRole("button", { name: "Confirmar visita", exact: true }),
    ).toBeFocused();
    expect(await factsFor(page, "failure")).toEqual({ visits: [], effects: { GET: 1, PATCH: 1 } });
});

test("lost committed response and failed receipt preserve UUID; primary retry only reads", async ({
    page,
}) => {
    const dialog = await openVisits(page);
    await findManually(dialog, "lost");
    const accepted = [];
    const receipts = [];
    await page.route(updatePath, async (route) => {
        const calls = actionCalls(route.request());
        const acceptance = calls.find((call) => call.method === "acceptVisit");
        const receipt = calls.find((call) => call.method === "findVisitReceipt");
        if (acceptance) {
            expect(
                acceptance.params[3],
                "explicit confirmation retains its rendered Promotion ID",
            ).toBe(fixture.promotion.id);
            accepted.push(acceptance.params[2]);
            const response = await route.fetch();
            expect(response.ok()).toBe(true);
            expect((await factsFor(page, "lost")).visits).toHaveLength(1);
            await route.fulfill({
                status: 503,
                contentType: "text/plain",
                body: "Synthetic lost response",
            });
        } else if (receipt) {
            receipts.push(receipt.params[2]);
            if (receipts.length === 1)
                await route.fulfill({ status: 503, body: "Synthetic receipt failure" });
            else await route.continue();
        } else await route.continue();
    });
    const primary = dialog.getByRole("button", { name: "Confirmar visita", exact: true });
    await primary.click();
    await expectDangerToast(page, "No se pudo completar la acción.");
    await expect(primary).toBeEnabled();
    await expect(dialog.getByRole("button", { name: "Volver a leer" })).toBeDisabled();
    await primary.click();
    await expect.poll(() => receipts.length).toBe(1);
    await expect(primary).toBeEnabled();
    await expectDangerToast(page, "No se pudo completar la acción.");
    await dialog.getByRole("button", { name: "Cerrar", exact: true }).click();
    await page.getByRole("button", { name: "Registrar visita", exact: true }).click();
    await expect(dialog.getByRole("heading", { name: "Revisa la visita" })).toBeVisible();
    await primary.click();
    await expect(dialog.getByRole("button", { name: "Cerrar", exact: true })).toBeVisible();
    const facts = await factsFor(page, "lost");
    expect(accepted).toHaveLength(1);
    expect(receipts).toEqual([accepted[0], accepted[0]]);
    expect(facts.visits).toEqual([{ operation_id: accepted[0], awarded_points: 1 }]);
    expect(facts.effects).toEqual({ GET: 1, PATCH: 1 });
});

test("known commit stays positive when optional receipt fails and reopening refreshes read-only", async ({
    page,
}) => {
    const dialog = await openVisits(page);
    await findManually(dialog, "details");
    let receipts = 0;
    await page.route(updatePath, async (route) => {
        if (
            actionCalls(route.request()).some((call) => call.method === "findVisitReceipt") &&
            ++receipts === 1
        ) {
            await route.fulfill({ status: 503, body: "Synthetic optional read failure" });
        } else await route.continue();
    });
    await dialog.getByRole("button", { name: "Confirmar visita", exact: true }).click();
    await expect(dialog.getByRole("button", { name: "Cerrar", exact: true })).toBeVisible();
    await expectDangerToast(page, "La visita está registrada. Revisa el mensaje.");
    await expect(dialog.getByRole("button", { name: "Confirmar visita" })).toHaveCount(0);
    await dialog.getByRole("button", { name: "Cerrar", exact: true }).click();
    await page.getByRole("button", { name: "Registrar visita", exact: true }).click();
    await expect(dialog.getByText("1 / 8 puntos", { exact: true })).toBeVisible();
    expect(receipts).toBe(2);
    expect((await factsFor(page, "details")).effects).toEqual({ GET: 1, PATCH: 1 });
});

test("available Reward has no Visit CTA or enabled redemption stub", async ({ page }) => {
    const dialog = await openVisits(page);
    await findManually(dialog, "reward");
    await expect(dialog.getByText("Recompensa disponible", { exact: true })).toBeVisible();
    await expect(dialog.getByRole("button", { name: /Confirmar visita|Canjear/ })).toHaveCount(0);
    const facts = await factsFor(page, "reward");
    expect(facts.visits).toHaveLength(8);
    expect(facts.effects).toEqual({ GET: 0, PATCH: 0 });
});

for (const changedIntent of ["edit", "close", "back"]) {
    test(`late read after ${changedIntent} cannot restore stale confirmation`, async ({ page }) => {
        const dialog = await openVisits(page);
        const caseName = `readonly-${changedIntent}`;
        let release;
        let reached;
        let delivered;
        const held = new Promise((resolve) => {
            release = resolve;
        });
        const serverRead = new Promise((resolve) => {
            reached = resolve;
        });
        const finished = new Promise((resolve) => {
            delivered = resolve;
        });
        let first = true;
        await page.route(updatePath, async (route) => {
            if (first && actionCalls(route.request()).some((call) => call.method === "findPass")) {
                first = false;
                const response = await route.fetch();
                expect(response.ok()).toBe(true);
                reached();
                await held;
                await route.fulfill({ response });
                delivered();
            } else await route.continue();
        });
        const input = dialog.getByLabel("Código de la Tarjeta", { exact: true });
        await input.fill(fixture.passes[caseName].code);
        await dialog.getByRole("button", { name: "Buscar tarjeta", exact: true }).click();
        await serverRead;
        try {
            if (changedIntent === "edit") await input.fill("00000");
            else {
                await dialog.getByRole("button", { name: "Cerrar", exact: true }).click();
                await page.getByRole("button", { name: "Registrar visita", exact: true }).click();
                if (changedIntent === "back") {
                    await findManually(dialog, caseName);
                    await dialog.getByRole("button", { name: "Volver a leer" }).click();
                    await expect(input).toBeVisible();
                }
            }
        } finally {
            release();
        }
        await finished;
        // synchronize native response delivery and the following rendering frames, not elapsed time
        await page.evaluate(
            () =>
                new Promise((resolve) =>
                    requestAnimationFrame(() => requestAnimationFrame(resolve)),
                ),
        );
        await expect(input).toBeVisible();
        await expect(input).toHaveValue(changedIntent === "edit" ? "00000" : "");
        await expect(dialog.getByRole("heading", { name: "Revisa la visita" })).toHaveCount(0);
        expect(await factsFor(page, caseName)).toEqual({
            visits: [],
            effects: { GET: 0, PATCH: 0 },
        });
    });
}

for (const browserName of ["chromium", "firefox", "webkit"]) {
    const cameraTest = test.extend({ browserName });
    cameraTest.describe(`synthetic Canvas camera in actual ${browserName}`, () => {
        cameraTest(
            "explicit capture, typing, deactivation and new video after real Flux return",
            async ({ page, browserName: actualBrowserName }, testInfo) => {
                expect(actualBrowserName).toBe(browserName);
                expect(
                    fixture.kind,
                    "All three installed engines are required; do not skip missing runtimes",
                ).toBe("visits");
                const caseName = `camera-${browserName}`;
                const token = fixture.passes[caseName].token;
                const matrix = new QRCodeWriter().encode(
                    token,
                    BarcodeFormat.QR_CODE,
                    0,
                    0,
                    new Map(),
                );
                const rows =
                    browserName === "chromium"
                        ? dummyQr.rows
                        : Array.from({ length: matrix.getHeight() }, (_, y) =>
                              Array.from({ length: matrix.getWidth() }, (_, x) =>
                                  matrix.get(x, y) ? "1" : "0",
                              ).join(""),
                          );
                await installCanvasCamera(page, rows);
                const dialog = await openVisits(page);
                expect(await page.evaluate(() => window.__visitCamera.requests)).toBe(0);
                await dialog.getByLabel("Código de la Tarjeta", { exact: true }).fill("012");
                await page.evaluate(() => {
                    window.__visitCamera.holdPermission = true;
                });
                const start = dialog.locator("#visit-camera-start");
                const startWidth = await start.evaluate((element) => element.offsetWidth);
                await start.click();
                await expect
                    .poll(() =>
                        page.evaluate(() => Boolean(window.__visitCamera.releasePermission)),
                    )
                    .toBe(true);
                try {
                    await expectButtonSpinner(start, startWidth);
                    await expect(
                        dialog.getByRole("button", { name: "Desactivar cámara", exact: true }),
                    ).toBeEnabled();
                    await expect(
                        dialog.getByLabel("Código de la Tarjeta", { exact: true }),
                    ).toBeHidden();
                } finally {
                    await page.evaluate(() => {
                        window.__visitCamera.holdPermission = false;
                        window.__visitCamera.releasePermission();
                    });
                }
                const video = dialog.locator("video");
                await expect
                    .poll(() =>
                        video.evaluate(
                            (element) =>
                                element.readyState >= 2 &&
                                element.videoWidth > 0 &&
                                element.currentTime > 0,
                        ),
                    )
                    .toBe(true);
                const beforeTyping = await video.evaluate((element) => element.currentTime);
                await expect(
                    dialog.getByLabel("Código de la Tarjeta", { exact: true }),
                ).toBeHidden();
                await expect(
                    dialog.getByRole("button", { name: "Buscar tarjeta", exact: true }),
                ).toBeHidden();
                await expect
                    .poll(() => video.evaluate((element) => element.currentTime))
                    .toBeGreaterThan(beforeTyping);
                expect(
                    await page.evaluate(
                        () => window.__visitCamera.streams[0].getVideoTracks()[0].readyState,
                    ),
                ).toBe("live");
                await expect(video).toBeVisible();
                const stop = dialog.getByRole("button", { name: "Desactivar cámara", exact: true });
                const stopWidth = await stop.evaluate((element) => element.offsetWidth);
                const deactivation = await holdAction(page, "resetIdentification");
                await stop.click();
                await deactivation.requested;
                try {
                    await expectButtonSpinner(stop, stopWidth);
                    await expect(
                        dialog.getByLabel("Código de la Tarjeta", { exact: true }),
                    ).toBeHidden();
                } finally {
                    deactivation.release();
                }
                await expect(video).toBeHidden();
                await expect(
                    dialog.getByLabel("Código de la Tarjeta", { exact: true }),
                ).toHaveValue("");
                await expect
                    .poll(() =>
                        page.evaluate(() =>
                            window.__visitCamera.streams.every((stream) =>
                                stream.getTracks().every((track) => track.readyState === "ended"),
                            ),
                        ),
                    )
                    .toBe(true);
                await dialog.getByRole("button", { name: "Activar cámara", exact: true }).click();
                await expect
                    .poll(() => page.evaluate(() => window.__visitCamera.visibleAtAcquisition))
                    .toEqual([true, true]);
                await page.evaluate(() => {
                    window.__visitCamera.previousVideo = document.querySelector("dialog video");
                });
                await showQrOnCurrentVideo(dialog, testInfo);
                await dialog.getByRole("button", { name: "Confirmar visita", exact: true }).click();
                await expect(
                    dialog.getByRole("button", { name: "Cerrar", exact: true }),
                ).toBeFocused();
                await expect(dialog.getByText("1 / 8 puntos", { exact: true })).toBeVisible();
                await page.evaluate(() => {
                    window.__visitCamera.showQr = false;
                });
                await dialog.getByRole("button", { name: "Volver a leer" }).click();
                await expect(
                    dialog.getByLabel("Código de la Tarjeta", { exact: true }),
                ).toBeFocused();
                await expect(dialog.locator("video")).toBeHidden();
                expect(await page.evaluate(() => window.__visitCamera.requests)).toBe(2);
                expect(
                    await dialog
                        .locator("video")
                        .evaluate((element) => element !== window.__visitCamera.previousVideo),
                ).toBe(true);
                await dialog.getByRole("button", { name: "Activar cámara", exact: true }).click();
                await showQrOnCurrentVideo(dialog, testInfo);
                await testInfo.attach(`camera-${browserName}-confirmation`, {
                    body: await page.screenshot(),
                    contentType: "image/png",
                });
                expect(await page.evaluate(() => window.__visitCamera.requests)).toBe(3);
                await dialog.getByRole("button", { name: "Cerrar", exact: true }).click();
                await expect
                    .poll(() =>
                        page.evaluate(() =>
                            window.__visitCamera.streams.every((stream) =>
                                stream.getTracks().every((track) => track.readyState === "ended"),
                            ),
                        ),
                    )
                    .toBe(true);
                await page.evaluate(() => {
                    window.__visitCamera.showQr = false;
                });
                await page.getByRole("button", { name: "Registrar visita", exact: true }).click();
                await expect(
                    dialog.getByLabel("Código de la Tarjeta", { exact: true }),
                ).toBeFocused();
                expect(await page.evaluate(() => window.__visitCamera.requests)).toBe(3);
                await dialog.getByRole("button", { name: "Activar cámara", exact: true }).click();
                await showQrOnCurrentVideo(dialog, testInfo);
                expect(await page.evaluate(() => window.__visitCamera.requests)).toBe(4);
                await dialog.getByRole("button", { name: "Cerrar", exact: true }).click();
                await expect
                    .poll(() =>
                        page.evaluate(() =>
                            window.__visitCamera.streams.every((stream) =>
                                stream.getTracks().every((track) => track.readyState === "ended"),
                            ),
                        ),
                    )
                    .toBe(true);
                expect(
                    await page.evaluate(() => window.__visitCamera.visibleAtAcquisition),
                ).toEqual([true, true, true, true]);
                const facts = await factsFor(page, caseName);
                expect(facts.visits).toHaveLength(1);
                expect(facts.effects).toEqual({ GET: 1, PATCH: 1 });
            },
        );
    });
}
