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
async function openVisits(page, { manualAutofocus = true } = {}) {
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
    const input = dialog.getByLabel("Código de la Tarjeta", { exact: true });
    if (manualAutofocus) await expect(input).toBeFocused();
    else {
        await expect(input).not.toBeFocused();
        await expect(dialog.getByRole("button", { name: "Cerrar", exact: true })).toBeFocused();
    }
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

/** Checks shared point/progress roles and the actual desktop action hierarchy.
 * @param {import('@playwright/test').Locator} dialog Current rendered modal.
 * @returns {Promise<object>} Computed role styles for before/after comparison.
 */
async function visitPresentation(dialog) {
    const progress = dialog.getByRole("progressbar");
    await expect(progress).toBeVisible();
    await expect(progress).toHaveAttribute("aria-valuemax", "8");
    const gain = dialog.locator("#visit-points-gain");
    const style = await gain.evaluate((element) => {
        const value = getComputedStyle(element);
        const card = getComputedStyle(element.closest(".rounded-2xl"));
        return {
            fontSize: value.fontSize,
            fontWeight: value.fontWeight,
            color: value.color,
            background: card.backgroundColor,
        };
    });
    expect(parseFloat(style.fontSize)).toBe(36);
    await expect(dialog.locator("[data-visit-points-card]")).toHaveClass(/bg-app-emphasis/);
    const roles = await dialog.locator("[data-visit-points-card]").evaluate((card) => {
        const icon = card.querySelector("#visit-reward svg").getBoundingClientRect();
        const text = card.querySelector("#visit-reward > div").getBoundingClientRect();
        return {
            iconWidth: icon.width,
            iconY: icon.y + icon.height / 2,
            textY: text.y + text.height / 2,
        };
    });
    expect(roles.iconWidth).toBe(32);
    expect(Math.abs(roles.iconY - roles.textY)).toBeLessThanOrEqual(1);
    const stageIcon = await dialog.locator("[data-modal-step-heading] svg").boundingBox();
    expect(stageIcon.width).toBe(36);
    expect(stageIcon.height).toBe(36);
    const actions = await dialog.locator("footer").evaluate((footer) => {
        const rect = footer.getBoundingClientRect();
        const buttons = [...footer.querySelectorAll("button")];
        return {
            left: rect.left,
            right: rect.right,
            actions: buttons.map((button) => {
                const box = button.getBoundingClientRect();
                return { name: button.textContent.trim(), left: box.left, right: box.right };
            }),
        };
    });
    expect(actions.actions.map((action) => action.name)).toEqual([
        "Cerrar",
        "Volver a leer",
        "Confirmar visita",
    ]);
    expect(Math.abs(actions.actions[0].left - actions.left)).toBeLessThanOrEqual(1);
    expect(Math.abs(actions.actions[2].right - actions.right)).toBeLessThanOrEqual(1);
    return {
        ...style,
        barColor: await progress
            .locator(":scope > div")
            .evaluate((element) => getComputedStyle(element).backgroundColor),
    };
}

/** Verifies the native pending-work lock, including both dismissal paths.
 * @param {import('@playwright/test').Page} page Owning browser page.
 * @param {import('@playwright/test').Locator} dialog Current pending modal.
 * @returns {Promise<void>} Disabled native controls and retained modal.
 */
async function expectModalLocked(page, dialog) {
    const assertLocked = async () => {
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveJSProperty("open", true);
        await expect.poll(() => dialog.evaluate((element) => element.matches(":modal"))).toBe(true);
    };
    for (const control of await dialog.locator("button, input").all()) {
        await expect(control).toBeDisabled();
    }
    await page.keyboard.press("Escape");
    await assertLocked();
    await page.mouse.click(1, 1);
    await assertLocked();
    for (const close of await dialog.locator("[data-flux-modal-close] button").all()) {
        await close.click({ force: true });
        await assertLocked();
    }
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
    // camera decoding and its rendered lookup are sequential asynchronous boundaries
    await expect(dialog.getByRole("heading", { name: "Revisa la visita" })).toBeVisible({
        timeout: 15000,
    });
    await expect(
        dialog.getByRole("button", { name: "Confirmar visita", exact: true }),
    ).toBeFocused();
}

for (const width of [1280, 375]) {
    const viewportTest = width === 375 ? test.extend({ isMobile: true, hasTouch: true }) : test;
    viewportTest(
        `manual-first composition, viewport and unsaved editor at ${width}px`,
        async ({ page }, testInfo) => {
            await page.setViewportSize({ width, height: 800 });
            await installCanvasCamera(page, dummyQr.rows);
            const dialog = await openVisits(page, { manualAutofocus: width !== 375 });
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
                dialog.getByText(
                    "Ingresa el código de 5 dígitos que aparece en la Tarjeta del Cliente.",
                    { exact: true },
                ),
            ).toBeVisible();
            await expect(dialog.locator("#visit-code-help svg")).toBeVisible();
            await input.focus();
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
                await expect(
                    dialog.getByRole("heading", { name: "Código de la Tarjeta" }),
                ).toBeVisible();
            }
            const bounds = await dialog.boundingBox();
            expect(bounds.y).toBeGreaterThanOrEqual(0);
            expect(bounds.y + bounds.height).toBeLessThanOrEqual(800);
            expect(
                await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
            ).toBe(true);
            await expect(
                dialog.getByRole("button", { name: "Cerrar", exact: true }),
            ).toBeInViewport();
            await input.fill("99999");
            await search.click();
            await expectDangerToast(page, "No se pudo completar la acción.");
            const manualError = dialog.locator("#visit-manual-error");
            await expect(manualError).toBeVisible();
            await expect(manualError).toContainText("Revisa el código");
            const invalidField = await input.boundingBox();
            const error = await manualError.boundingBox();
            const invalidButton = await search.boundingBox();
            expect(error.y).toBeGreaterThanOrEqual(invalidField.y + invalidField.height);
            if (width === 375) {
                expect(error.y + error.height).toBeLessThanOrEqual(invalidButton.y);
            }
            expect(Math.abs(invalidField.width - field.width)).toBeLessThanOrEqual(2);
            expect(Math.abs(invalidField.height - field.height)).toBeLessThanOrEqual(2);
            expect(Math.abs(invalidButton.width - button.width)).toBeLessThanOrEqual(2);
            expect(Math.abs(invalidButton.height - button.height)).toBeLessThanOrEqual(2);
            await testInfo.attach(`manual-first-${width}`, {
                body: await page.screenshot(),
                contentType: "image/png",
            });
            if (width === 375) {
                await findManually(dialog, "layout-375");
                const footer = dialog.locator("footer");
                const edges = await footer.evaluate((element) => {
                    const rect = element.getBoundingClientRect();
                    const close = element
                        .querySelector("[data-visit-close]")
                        .getBoundingClientRect();
                    const confirm = element
                        .querySelector("[data-visit-confirm]")
                        .getBoundingClientRect();
                    return {
                        left: rect.left,
                        right: rect.right,
                        close: close.left,
                        confirm: confirm.right,
                    };
                });
                expect(Math.abs(edges.left - edges.close)).toBeLessThanOrEqual(1);
                expect(Math.abs(edges.right - edges.confirm)).toBeLessThanOrEqual(1);
                await dialog.getByRole("button", { name: "Volver a leer", exact: true }).click();
                await expect(input).toBeVisible();
                await expect(input).toHaveValue("");
                await expect(
                    dialog.getByRole("button", { name: "Cerrar", exact: true }),
                ).toBeFocused();
            }
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
        },
    );
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
        await expectModalLocked(page, dialog);
    } finally {
        searchHold.release();
    }
    await finding;
    const presentation = await visitPresentation(dialog);
    const keyboardTarget = dialog.getByRole("button", { name: "Confirmar visita", exact: true });
    await expect
        .poll(() => keyboardTarget.evaluate((element) => getComputedStyle(element).outlineStyle))
        .toBe("none");
    await page.keyboard.press("Tab");
    await page.keyboard.press("Shift+Tab");
    await expect(keyboardTarget).toBeFocused();
    await expect
        .poll(() => keyboardTarget.evaluate((element) => getComputedStyle(element).outlineStyle))
        .toBe("solid");
    await expect(dialog.getByRole("progressbar")).toHaveAttribute("aria-valuenow", "0");
    await test.info().attach("confirmation-hierarchy", {
        body: await page.screenshot(),
        contentType: "image/png",
    });
    expect(await factsFor(page, "manual")).toEqual({ visits: [], effects: { GET: 0, PATCH: 0 } });
    const confirm = dialog.getByRole("button", { name: "Confirmar visita", exact: true });
    const confirmWidth = await confirm.evaluate((element) => element.offsetWidth);
    const confirmation = await holdAction(page, "acceptVisit");
    await confirm.click();
    await confirmation.requested;
    try {
        await expectButtonSpinner(confirm, confirmWidth);
        await expectModalLocked(page, dialog);
    } finally {
        confirmation.release();
    }
    await expect(dialog.getByRole("button", { name: "Cerrar", exact: true })).toBeFocused();
    await expect(dialog.getByText("1 / 8 puntos", { exact: true })).toBeVisible();
    const progress = dialog.getByRole("progressbar");
    await expect(progress).toHaveAttribute("aria-valuenow", "1");
    await expect(progress).toHaveAttribute("aria-valuemax", "8");
    const resultStyle = await dialog.locator("#visit-points-gain").evaluate((element) => {
        const value = getComputedStyle(element);
        return {
            fontSize: value.fontSize,
            fontWeight: value.fontWeight,
            color: value.color,
            background: getComputedStyle(element.closest(".rounded-2xl")).backgroundColor,
        };
    });
    expect(resultStyle.background).not.toBe(presentation.background);
    expect({ ...resultStyle, background: undefined }).toEqual({
        ...Object.fromEntries(Object.entries(presentation).filter(([key]) => key !== "barColor")),
        background: undefined,
    });
    await expect(dialog.locator("[data-visit-points-card]")).toHaveClass(/bg-app-success-surface/);
    await expect(dialog.locator("[data-modal-step-heading] > span")).toHaveClass(
        /text-app-success-ink/,
    );
    expect(
        await progress
            .locator(":scope > div")
            .evaluate((element) => getComputedStyle(element).backgroundColor),
    ).toBe(presentation.barColor);
    await expect
        .poll(() =>
            progress.evaluate((element) => {
                const track = element.getBoundingClientRect();
                const fill = element.firstElementChild.getBoundingClientRect();
                return Math.round((fill.width / track.width) * 1000);
            }),
        )
        .toBe(125);
    const close = dialog.getByRole("button", { name: "Cerrar", exact: true });
    const order = await dialog
        .locator("footer")
        .evaluate((element) =>
            [...element.querySelectorAll("button")].map((button) => button.textContent.trim()),
        );
    expect(order).toEqual(["Cerrar", "Volver a leer"]);
    await expect(close).not.toHaveClass(/app-button-secondary/);
    const resultEdges = await dialog.locator("footer").evaluate((element) => ({
        left: element.getBoundingClientRect().left,
        close: element.querySelector("[data-visit-close]").getBoundingClientRect().left,
    }));
    expect(Math.abs(resultEdges.left - resultEdges.close)).toBeLessThanOrEqual(1);
    await test
        .info()
        .attach("result-hierarchy", { body: await page.screenshot(), contentType: "image/png" });
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
        await expectModalLocked(page, dialog);
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
                getUserMedia: () =>
                    new Promise((resolve, reject) => {
                        window.__visitRejectPermission = () =>
                            reject(new DOMException("Synthetic denial", "NotAllowedError"));
                    }),
            },
        });
    });
    const dialog = await openVisits(page);
    await dialog.getByLabel("Código de la Tarjeta", { exact: true }).fill("99999");
    await dialog.getByRole("button", { name: "Buscar tarjeta", exact: true }).click();
    await expectDangerToast(page, "No se pudo completar la acción.");
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toHaveValue("99999");
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toBeFocused();
    await expect(dialog.locator("#visit-manual-error")).toContainText("Revisa el código");
    await expect(dialog.locator("#visit-manual-error")).toBeVisible();

    await dialog.getByRole("button", { name: "Activar cámara", exact: true }).click();
    const cameraRegion = dialog.getByLabel("Escáner de la tarjeta del cliente", { exact: true });
    await expect(cameraRegion).toBeVisible();
    await expect
        .poll(() => page.evaluate(() => typeof window.__visitRejectPermission))
        .toBe("function");
    await expect(cameraRegion.getByText(/Revisa el código/)).toBeHidden();
    await expect(
        dialog.getByRole("button", { name: "Desactivar cámara", exact: true }),
    ).toBeEnabled();
    await page.evaluate(() => window.__visitRejectPermission());
    await expect(
        dialog
            .getByLabel("Escáner de la tarjeta del cliente", { exact: true })
            .getByText(/No se permitió el acceso a la cámara/),
    ).toBeVisible();
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toBeHidden();
    await dialog.getByRole("button", { name: "Desactivar cámara", exact: true }).click();
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toBeFocused();
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toHaveValue("");
    await expect(dialog.locator("#visit-manual-error")).toBeHidden();
    await findManually(dialog, "failure");
    await dialog.getByRole("button", { name: "Confirmar visita", exact: true }).click();
    await expectDangerToast(page, "No se pudo completar la acción.");
    await expect(dialog.getByRole("heading", { name: "Revisa la visita" })).toBeVisible();
    await expect(dialog.locator("#visit-action-error")).toContainText(
        "No pudimos registrar la visita",
    );
    await expect(
        dialog
            .locator("section")
            .filter({ has: page.getByRole("heading", { name: "Revisa la visita" }) })
            .getByRole("alert")
            .filter({ hasText: /No pudimos registrar la visita/ }),
    ).toBeVisible();
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
    const freshCode = dialog.getByLabel("Código de la Tarjeta", { exact: true });
    await expect(freshCode).toBeVisible();
    await expect(freshCode).toHaveValue("");
    await expect(dialog.getByRole("heading", { name: "Visita registrada" })).toHaveCount(0);
    await findManually(dialog, "lost");
    await expect(dialog.getByText("1 / 8 puntos", { exact: true })).toBeVisible();
    const facts = await factsFor(page, "lost");
    expect(accepted).toHaveLength(1);
    expect(receipts).toEqual([accepted[0], accepted[0]]);
    expect(facts.visits).toEqual([{ operation_id: accepted[0], awarded_points: 1 }]);
    expect(facts.effects).toEqual({ GET: 1, PATCH: 1 });
});

test("known commit stays positive after detail failure and reopening requires a fresh read", async ({
    page,
}) => {
    const dialog = await openVisits(page);
    await findManually(dialog, "details");
    let receipts = 0;
    let allowFreshReset = false;
    await page.route(updatePath, async (route) => {
        const calls = actionCalls(route.request());
        if (calls.some((call) => call.method === "findVisitReceipt") && ++receipts === 1) {
            await route.fulfill({ status: 503, body: "Synthetic optional read failure" });
        } else if (
            !allowFreshReset &&
            calls.some((call) => call.method === "resetIdentification")
        ) {
            await route.fulfill({ status: 503, body: "Synthetic fresh preparation failure" });
        } else await route.continue();
    });
    await dialog.getByRole("button", { name: "Confirmar visita", exact: true }).click();
    await expect(dialog.getByRole("button", { name: "Cerrar", exact: true })).toBeVisible();
    await expectDangerToast(page, "La visita está registrada. Revisa el mensaje.");
    await expect(
        dialog
            .locator("section")
            .filter({ has: page.getByRole("heading", { name: "Visita registrada" }) })
            .getByText("La visita está registrada, pero no pudimos cargar todos los detalles.", {
                exact: true,
            }),
    ).toBeVisible();
    await expect(dialog.getByRole("button", { name: "Confirmar visita" })).toHaveCount(0);
    await dialog.getByRole("button", { name: "Cerrar", exact: true }).click();
    await page.getByRole("button", { name: "Registrar visita", exact: true }).click();
    const restart = dialog.getByRole("button", { name: "Reintentar", exact: true });
    await expect(restart).toBeVisible();
    await expect(dialog.getByRole("heading", { name: "Visita registrada" })).toHaveCount(0);
    await expect(dialog.getByRole("button", { name: "Volver a leer", exact: true })).toHaveCount(0);
    allowFreshReset = true;
    await restart.click();
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toHaveValue("");
    expect(receipts).toBe(1);
    await findManually(dialog, "details");
    await expect(dialog.getByText("1 / 8 puntos", { exact: true })).toBeVisible();
    expect(receipts).toBe(1);
    expect((await factsFor(page, "details")).effects).toEqual({ GET: 1, PATCH: 1 });
});

test("failed lookup reopening cannot replay a closed reset error or toast", async ({ page }) => {
    await page.addInitScript(() => {
        window.__visitReopenToasts = 0;
        document.addEventListener("toast-show", () => window.__visitReopenToasts++);
    });
    const dialog = await openVisits(page);
    let releaseOld, oldArrived, oldFinished;
    const delivery = new Promise((resolve) => {
        releaseOld = resolve;
    });
    const arrived = new Promise((resolve) => {
        oldArrived = resolve;
    });
    const finished = new Promise((resolve) => {
        oldFinished = resolve;
    });
    let failLookup = true,
        resets = 0;
    await page.route(updatePath, async (route) => {
        const calls = actionCalls(route.request());
        if (failLookup && calls.some((call) => call.method === "findPass")) {
            failLookup = false;
            await route.fulfill({ status: 503, body: "Synthetic lookup failure" });
        } else if (calls.some((call) => call.method === "resetIdentification") && ++resets === 1) {
            oldArrived();
            await delivery;
            try {
                await route.fulfill({ status: 503, body: "Synthetic closed reset failure" });
            } finally {
                oldFinished();
            }
        } else await route.continue();
    });
    await dialog
        .getByLabel("Código de la Tarjeta", { exact: true })
        .fill(fixture.passes.details.code);
    await dialog.getByRole("button", { name: "Buscar tarjeta", exact: true }).click();
    await expectDangerToast(page, "No se pudo completar la acción.");
    await expect.poll(() => page.evaluate(() => window.__visitReopenToasts)).toBe(1);
    await expect(dialog.locator("#visit-manual-transport-error")).toBeVisible();
    await dialog.getByRole("button", { name: "Cerrar", exact: true }).click();
    await arrived;
    await page.getByRole("button", { name: "Registrar visita", exact: true }).click();
    try {
        await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toHaveValue("");
        await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toBeEnabled();
        await expect(dialog.locator("#visit-manual-transport-error")).toBeHidden();
        await expect(dialog.locator("#visit-manual-error")).toBeHidden();
        expect(resets).toBe(2);
    } finally {
        releaseOld();
    }
    await finished;
    await expect.poll(() => page.evaluate(() => window.__visitReopenToasts)).toBe(1);
    await expect(dialog.getByLabel("Código de la Tarjeta", { exact: true })).toHaveValue("");
    await expect(dialog.locator("#visit-manual-transport-error")).toBeHidden();
});

test("available Reward has no Visit CTA or enabled redemption stub", async ({ page }) => {
    const dialog = await openVisits(page);
    await findManually(dialog, "reward");
    await expect(dialog.getByText("¡Recompensa lista!", { exact: true })).toBeVisible();
    await expect(dialog.getByRole("button", { name: /Confirmar visita|Canjear/ })).toHaveCount(0);
    const facts = await factsFor(page, "reward");
    expect(facts.visits).toHaveLength(8);
    expect(facts.effects).toEqual({ GET: 0, PATCH: 0 });
});

test.extend({ isMobile: true, hasTouch: true })(
    "newly unlocked reward is readable beside the title with bounded optional motion",
    async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 800 });
        const dialog = await openVisits(page, { manualAutofocus: false });
        await findManually(dialog, "unlock");
        await expect(dialog.getByText("7 / 8 puntos", { exact: true })).toBeVisible();
        await expect(dialog.locator("[data-visit-reward-unlocked]")).toHaveCount(0);
        await dialog.getByRole("button", { name: "Confirmar visita", exact: true }).click();
        const badge = dialog.locator("[data-visit-reward-unlocked]");
        await expect(badge).toBeVisible();
        await expect(badge).toHaveText("Recompensa desbloqueada");
        await expect(dialog.getByText("8 / 8 puntos", { exact: true })).toBeVisible();
        const badgeStyle = await badge.evaluate((element) => ({
            textTransform: getComputedStyle(element).textTransform,
            color: getComputedStyle(element).color,
            top: element.getBoundingClientRect().top,
            bottom: element.getBoundingClientRect().bottom,
            cardTop: element
                .closest("section")
                .querySelector("[data-visit-points-card]")
                .getBoundingClientRect().top,
            cardContains: Boolean(element.closest("[data-visit-points-card]")),
        }));
        expect(badgeStyle.textTransform).toBe("none");
        expect(badgeStyle.cardContains).toBe(false);
        expect(badgeStyle.bottom).toBeLessThanOrEqual(badgeStyle.cardTop);
        const pulse = badge.locator("[aria-hidden]");
        const animation = await pulse.evaluate((element) => ({
            count: getComputedStyle(element).animationIterationCount,
            duration: getComputedStyle(element).animationDuration,
        }));
        expect(animation.count).toBe("2");
        expect(parseFloat(animation.duration) * Number(animation.count)).toBeLessThanOrEqual(4);
        await page.emulateMedia({ reducedMotion: "reduce" });
        await expect
            .poll(() => pulse.evaluate((element) => getComputedStyle(element).animationName))
            .toBe("none");
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
            true,
        );
        await test.info().attach("reward-unlocked-heading-mobile", {
            body: await page.screenshot(),
            contentType: "image/png",
        });
        const facts = await factsFor(page, "unlock");
        expect(facts.visits).toHaveLength(8);
        expect(facts.effects).toEqual({ GET: 1, PATCH: 1 });
    },
);

for (const changedIntent of ["edit", "close", "back"]) {
    test(`pending read blocks ${changedIntent} intent without cancelling its response`, async ({
        page,
    }) => {
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
            await expect(input).toBeDisabled();
            await expect(
                dialog.getByRole("button", { name: "Cerrar", exact: true }),
            ).toBeDisabled();
            if (changedIntent === "edit") {
                await input.evaluate((element) => element.focus());
                await expect(input).not.toBeFocused();
                await expect(input).toHaveValue(fixture.passes[caseName].code);
            } else if (changedIntent === "close") {
                await expectModalLocked(page, dialog);
            } else {
                await expect(
                    dialog.getByRole("button", { name: "Buscar tarjeta", exact: true }),
                ).toBeDisabled();
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
        await expect(dialog.getByRole("heading", { name: "Revisa la visita" })).toBeVisible();
        await expect(
            dialog.getByRole("button", { name: "Confirmar visita", exact: true }),
        ).toBeFocused();
        await dialog.getByRole("button", { name: "Volver a leer", exact: true }).click();
        await expect(input).toBeVisible();
        await expect(input).toHaveValue("");
        expect(await factsFor(page, caseName)).toEqual({
            visits: [],
            effects: { GET: 0, PATCH: 0 },
        });
    });
}

for (const browserName of ["chromium", "firefox", "webkit"]) {
    const cameraTest = test.extend({
        browserName,
        ...(browserName === "chromium"
            ? { isMobile: true, hasTouch: true, viewport: { width: 393, height: 851 } }
            : {}),
    });
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
                const dialog = await openVisits(page, {
                    manualAutofocus: browserName !== "chromium",
                });
                expect(await page.evaluate(() => window.__visitCamera.requests)).toBe(0);
                await dialog.getByLabel("Código de la Tarjeta", { exact: true }).fill("012");
                await page.evaluate(() => {
                    window.__visitCamera.holdPermission = true;
                });
                const start = dialog.getByRole("button", { name: "Activar cámara", exact: true });
                const startWidth = await start.evaluate((element) => element.offsetWidth);
                await start.click();
                await expect
                    .poll(() =>
                        page.evaluate(() => Boolean(window.__visitCamera.releasePermission)),
                    )
                    .toBe(true);
                try {
                    const stop = dialog.getByRole("button", {
                        name: "Desactivar cámara",
                        exact: true,
                    });
                    await expect(dialog.locator("[data-qr-camera] button:visible")).toHaveCount(1);
                    await expectButtonSpinner(stop, startWidth);
                    await expect(stop).toBeEnabled();
                    expect(
                        await stop.evaluate((element) => getComputedStyle(element).pointerEvents),
                    ).not.toBe("none");
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
                    browserName === "chromium"
                        ? dialog.getByRole("button", { name: "Cerrar", exact: true })
                        : dialog.getByLabel("Código de la Tarjeta", { exact: true }),
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
                    browserName === "chromium"
                        ? dialog.getByRole("button", { name: "Cerrar", exact: true })
                        : dialog.getByLabel("Código de la Tarjeta", { exact: true }),
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
