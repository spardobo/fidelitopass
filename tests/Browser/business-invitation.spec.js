import { expect, test } from "@playwright/test";
import crypto from "node:crypto";
import { readFile } from "node:fs/promises";

const MAILPIT_URL = process.env.PLAYWRIGHT_MAILPIT_URL ?? "http://fidelitopass-mailpit-dev:8025";

/** Measures WCAG contrast between two opaque computed RGB colors.
 * @param {string} foreground Computed RGB foreground color without ancestor opacity.
 * @param {string} background Computed opaque RGB surface beneath the foreground.
 * @returns {number} Relative luminance contrast ratio, from 1 to 21.
 */
function contrastRatio(foreground, background) {
    const luminances = [foreground, background].map((color) => {
        const channels = color
            .match(/[\d.]+/g)
            .slice(0, 3)
            .map(Number)
            .map((channel) => {
                const normalized = channel / 255;

                return normalized <= 0.04045
                    ? normalized / 12.92
                    : ((normalized + 0.055) / 1.055) ** 2.4;
            });

        return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
    });

    return (Math.max(...luminances) + 0.05) / (Math.min(...luminances) + 0.05);
}

/** Registers a disposable owner without appearance or Promotion prerequisites.
 * @param {import('@playwright/test').Page} page Browser used for registration and verification.
 * @param {import('@playwright/test').APIRequestContext} request Client for the fixture's Mailpit message.
 * @returns {Promise<void>} Resolves on the authenticated dashboard; rejects on registration or verification failure.
 */
async function registerInvitationOwner(page, request) {
    const recipient = `invitation-${crypto.randomUUID()}@example.test`;

    await page.goto("/register");
    await page.getByRole("textbox", { name: "Nombre", exact: true }).fill("Invitation Owner");
    await page.getByLabel("Correo electrónico", { exact: true }).fill(recipient);
    await page.getByLabel("Nombre del negocio", { exact: true }).fill("Invitation Business");
    await page.getByLabel("Zona horaria", { exact: true }).selectOption("America/La_Paz");
    await page.locator('input[name="password"]').fill("ValidPassword84!strong");
    await page.locator('input[name="password_confirmation"]').fill("ValidPassword84!strong");
    await page.getByRole("button", { name: "Crear cuenta" }).click();
    await expect(page).toHaveURL(/\/email\/verify(?:\?|$)/);

    let message;

    await expect
        .poll(async () => {
            const response = await request.get(`${MAILPIT_URL}/api/v1/messages`);
            const mailbox = await response.json();
            message = mailbox.messages?.find((item) =>
                item.To?.some((address) => address.Address === recipient),
            );

            return Boolean(message);
        })
        .toBe(true);

    const response = await request.get(`${MAILPIT_URL}/api/v1/message/${message.ID}`);
    const detail = await response.json();
    const link = detail.Text.match(/https?:\/\/[^\s<>)]*\/email\/verify\/[^\s<>)]*/)[0];

    await page.goto(link.replace(/&amp;/g, "&"));
    await expect(page).toHaveURL(/\/dashboard(?:\?|$)/);
}

/** Checks the contextual preparation or download action and permanent instructions.
 * @param {import('@playwright/test').Page} page Authenticated invitation page.
 * @param {boolean} appearanceSaved Whether the owner has saved the pass appearance.
 * @returns {Promise<void>} Resolves when each appearance state exposes its intended single action; rejects on a mismatch.
 */
async function expectInvitationState(page, appearanceSaved) {
    const navigation = page.getByRole("navigation", { name: "Navegación principal" });
    const main = page.getByRole("main");
    const qr = main.getByRole("region", { name: "QR del negocio", exact: true });
    const instructions = main.getByRole("region", {
        name: "Cómo invitar a tus clientes",
        exact: true,
    });
    const action = appearanceSaved
        ? qr.getByRole("button", { name: "Descargar QR", exact: true })
        : qr.getByRole("link", { name: "Preparar pase", exact: true });

    await expect(navigation.locator('[aria-current="page"]')).toHaveCount(1);
    await expect(
        navigation.getByRole("link", { name: "Invitar clientes", exact: true }),
    ).toHaveAttribute("aria-current", "page");
    await expect(qr.getByRole("group", { name: "Vista del QR del negocio" })).toBeVisible();
    if (appearanceSaved) {
        await expect(
            qr.getByRole("img", {
                name: "QR público permanente del negocio",
            }),
        ).toBeVisible();
        await expect(action).toBeEnabled();
    } else {
        await expect(qr.getByText("Pase sin preparar", { exact: true })).toBeVisible();
        await expect(action).toHaveAttribute("href", /\/pass\/appearance$/);
        await expect(qr.getByRole("button", { name: "Descargar QR", exact: true })).toHaveCount(0);
        await expect(qr.getByRole("group").locator("p")).toHaveCount(1);
    }
    await expect(action).toHaveAccessibleDescription(
        appearanceSaved
            ? "Imprime el QR y colócalo junto a tu caja."
            : "Elige un color y guarda la apariencia del pase.",
    );
    await expect(main.getByRole("img")).toHaveCount(appearanceSaved ? 1 : 0);
    await expect(main.getByRole("button")).toHaveCount(appearanceSaved ? 1 : 0);
    await expect(qr.locator('[aria-describedby="invitation-action-help"]')).toHaveCount(1);
    await expect(main.getByRole("link")).toHaveCount(appearanceSaved ? 0 : 1);
    await expect(
        main.locator(
            'canvas, svg:not([aria-hidden="true"]), input, textarea, a[download], [data-flux-badge]',
        ),
    ).toHaveCount(0);
    await expect(main.getByText(/https?:\/\//)).toHaveCount(0);
    await expect(main.getByText(/Falta integrar|maqueta|Error al generar/)).toHaveCount(0);
    await expect(main.getByRole("heading", { name: "Promoción", exact: true })).toHaveCount(0);
    await expect(main.getByRole("button", { name: "Ver detalle", exact: true })).toHaveCount(0);

    const steps = instructions.getByRole("listitem");
    await expect(steps).toHaveCount(3);
    for (const [index, heading] of [
        "Coloca el QR en tu negocio",
        "Tus clientes guardan el pase",
        "Confirma cada visita",
    ].entries()) {
        await expect(
            steps.nth(index).getByRole("heading", { name: heading, exact: true }),
        ).toBeVisible();
        await expect(steps.nth(index).locator('svg[aria-hidden="true"]')).toHaveAttribute(
            "fill",
            "none",
        );
    }
    await expect(instructions.getByText(/sin crear una cuenta en FidelitoPass\./)).toBeVisible();
    await expect(
        instructions.getByText(/confirma cada visita antes de sumar puntos\./),
    ).toBeVisible();
    await expect(
        instructions.getByText(
            "El QR público será solo para guardar el pase, no autoriza visitas ni canjes.",
            { exact: true },
        ),
    ).toBeVisible();

    const notes = main.locator(".app-note-with-icon");
    await expect(notes).toHaveCount(2);
    for (const note of await notes.all()) {
        await expect(note.locator('svg[aria-hidden="true"]')).toHaveCount(1);
        await expect(note.locator("p")).toBeVisible();
        await expect(note.locator("p")).toHaveCSS("font-size", "14px");
        await expect(note.locator("p")).toHaveCSS("line-height", "20px");
        await expect(note.locator("p")).toHaveCSS("color", "rgb(176, 172, 184)");
        await expect(note).toHaveCSS("opacity", "1");
    }
}

/** Verifies computed shared roles and responsive composition, not implementation class names.
 * @param {import('@playwright/test').Page} page Authenticated invitation page.
 * @param {number} width Viewport width in CSS pixels.
 * @param {boolean} appearanceSaved Whether the owner has saved the pass appearance.
 * @returns {Promise<{qrSlot: {x: number, y: number, width: number, height: number}, action: {x: number, y: number, width: number, height: number}}>} Measured slots before focus scrolls the viewport; rejects on a visual contract failure.
 */
async function expectInvitationPresentation(page, width, appearanceSaved) {
    await page.evaluate(() => document.fonts.ready);

    const main = page.getByRole("main");
    const title = main.getByRole("heading", { level: 1 });
    const qr = main.getByRole("region", { name: "QR del negocio", exact: true });
    const instructions = main.getByRole("region", {
        name: "Cómo invitar a tus clientes",
        exact: true,
    });
    const qrSlot = qr.getByRole("group", { name: "Vista del QR del negocio" });
    const action = appearanceSaved
        ? qr.getByRole("button", { name: "Descargar QR", exact: true })
        : qr.getByRole("link", { name: "Preparar pase", exact: true });
    const intro = main.getByText(
        "Comparte el pase de tu negocio e invita a tus clientes a volver.",
        { exact: true },
    );

    for (const [element, size, line, weight, color] of [
        [title, "32px", "40px", "700", "rgb(246, 245, 242)"],
        [intro, "18px", "28px", "400", "rgb(199, 196, 206)"],
        [qr.getByRole("heading"), "20px", "28px", "600", "rgb(246, 245, 242)"],
        [
            instructions.getByRole("heading", { level: 2 }),
            "20px",
            "28px",
            "600",
            "rgb(246, 245, 242)",
        ],
        [
            instructions.getByRole("heading", { level: 3 }).first(),
            "18px",
            "28px",
            "600",
            "rgb(246, 245, 242)",
        ],
    ]) {
        await expect(element).toHaveCSS("font-size", size);
        await expect(element).toHaveCSS("line-height", line);
        await expect(element).toHaveCSS("font-weight", weight);
        await expect(element).toHaveCSS("color", color);
        await expect(element).toHaveCSS("font-family", /Onest/);
    }

    await expect(main.getByText("Invitation Business · Tu negocio", { exact: true })).toHaveCSS(
        "color",
        "rgb(167, 123, 255)",
    );
    await expect(page.locator("body")).toHaveCSS("background-color", "rgb(36, 36, 36)");
    await expect(main).toHaveCSS("max-width", "1088px");
    await expect(main).toHaveCSS("padding-left", width < 640 ? "16px" : "24px");
    await expect(main).toHaveCSS("padding-right", width < 640 ? "16px" : "24px");
    await expect(main).toHaveCSS("gap", "32px");

    await expect(qr).toHaveCSS("background-color", "rgb(61, 46, 85)");
    await expect(qr).toHaveCSS("border-color", "rgb(132, 101, 172)");
    await expect(instructions).toHaveCSS("background-color", "rgb(46, 46, 46)");
    await expect(instructions).toHaveCSS("border-color", "rgb(82, 82, 82)");
    for (const card of [qr, instructions]) {
        await expect(card).toHaveCSS("border-radius", "20px");
        await expect(card).toHaveCSS("padding", width < 640 ? "16px" : "24px");
    }

    const geometry = await main.evaluate((element) => {
        const header = element.querySelector("header");
        const grid = header.nextElementSibling;
        const note = element.querySelector(".app-note-with-icon");
        const qrCard = grid.children[0];
        const description = qrCard.children[0].querySelector("p");
        const text = document.createRange();
        text.selectNodeContents(description);
        const descriptionBounds = description.getBoundingClientRect();
        const qrBounds = qrCard.children[1].getBoundingClientRect();
        const actionBounds = qrCard
            .querySelector('[aria-describedby="invitation-action-help"]')
            .getBoundingClientRect();

        return {
            loadedFonts: [...document.fonts]
                .filter((font) => font.status === "loaded")
                .map((font) => font.family),
            fontSources: performance
                .getEntriesByType("resource")
                .filter((entry) => /\.woff2(?:\?|$)/.test(entry.name))
                .map((entry) => new URL(entry.name).origin),
            overflow: document.documentElement.scrollWidth > innerWidth,
            width: element.getBoundingClientRect().width,
            headerGap:
                header.children[1].getBoundingClientRect().top -
                header.children[0].getBoundingClientRect().bottom,
            gridGap: getComputedStyle(grid).gap,
            noteGap: getComputedStyle(note).gap,
            descriptionHeight: descriptionBounds.height,
            descriptionNaturalHeight:
                new Set([...text.getClientRects()].map((line) => line.top)).size *
                parseFloat(getComputedStyle(description).lineHeight),
            descriptionToQr: qrBounds.top - descriptionBounds.bottom,
            qrToAction: actionBounds.top - qrBounds.bottom,
            cardGap:
                grid.children[0].children[1].getBoundingClientRect().top -
                grid.children[0].children[0].getBoundingClientRect().bottom,
        };
    });
    expect(geometry.loadedFonts).toContain("Onest Variable");
    expect(geometry.fontSources.length).toBeGreaterThan(0);
    expect(geometry.fontSources.every((origin) => origin === new URL(page.url()).origin)).toBe(
        true,
    );
    expect(geometry.overflow).toBe(false);
    expect(geometry.width).toBeLessThanOrEqual(Math.min(width, 1088));
    expect(geometry.headerGap).toBe(8);
    expect(geometry.gridGap).toBe("32px");
    expect(geometry.noteGap).toBe("8px");
    expect(geometry.cardGap).toBe(16);
    expect(geometry.descriptionHeight).toBe(geometry.descriptionNaturalHeight);
    expect(geometry.descriptionToQr).toBe(16);
    expect(geometry.qrToAction).toBe(geometry.descriptionToQr);

    const qrBounds = await qr.boundingBox();
    const instructionBounds = await instructions.boundingBox();
    if (width >= 1024) {
        expect(instructionBounds.y).toBe(qrBounds.y);
        expect(instructionBounds.x - qrBounds.x - qrBounds.width).toBeCloseTo(32);
        expect(instructionBounds.width).toBeCloseTo(qrBounds.width * 2);
    } else {
        expect(instructionBounds.y - qrBounds.y - qrBounds.height).toBeCloseTo(32);
    }

    const slotBounds = await qrSlot.boundingBox();
    expect(slotBounds.width).toBeCloseTo(slotBounds.height);
    expect(slotBounds.width).toBeLessThanOrEqual(224);
    expect(slotBounds.width).toBeGreaterThanOrEqual(190);
    await expect(qrSlot).toHaveCSS(
        "background-color",
        appearanceSaved ? "rgba(0, 0, 0, 0)" : "rgb(34, 34, 34)",
    );
    await expect(qrSlot).toHaveCSS("border-width", appearanceSaved ? "0px" : "1px");
    if (appearanceSaved) {
        const imageBounds = await qrSlot.getByRole("img").boundingBox();
        expect(imageBounds.width).toBe(imageBounds.height);
        expect(imageBounds.width).toBe(slotBounds.width);
    } else {
        await expect(qrSlot).toHaveCSS("border-color", "rgb(132, 101, 172)");
        await expect(qrSlot.locator('svg[aria-hidden="true"]')).toHaveAttribute("fill", "none");
        await expect(qrSlot.locator("svg")).toHaveCSS("color", "rgb(228, 214, 250)");
    }
    await expect(action).toHaveCSS("background-color", "rgba(0, 0, 0, 0)");
    await expect(action).toHaveCSS("color", "rgb(228, 214, 250)");
    await expect(action).toHaveCSS("border-color", "rgb(228, 214, 250)");
    await expect(action).toHaveCSS("opacity", "1");

    const contrasts = [];
    const contrastSubjects = [
        [qr.getByRole("heading"), qr],
        [qr.locator("p").first(), qr],
        [qr.locator("#invitation-action-help p"), qr],
        [instructions.locator(".app-note-with-icon p"), instructions],
        [action, qr],
    ];
    if (!appearanceSaved) {
        contrastSubjects.push([qrSlot.locator("p"), qrSlot]);
    }
    for (const [text, surface] of contrastSubjects) {
        const foreground = await text.evaluate((element) => getComputedStyle(element).color);
        const background = await surface.evaluate(
            (element) => getComputedStyle(element).backgroundColor,
        );
        const contrast = contrastRatio(foreground, background);
        expect(contrast).toBeGreaterThanOrEqual(4.5);
        contrasts.push({ foreground, background, ratio: contrast });
    }
    const actionBounds = await action.boundingBox();
    expect(actionBounds.height).toBeGreaterThanOrEqual(44);

    const focusTarget = action;
    await focusTarget.focus();
    await page.keyboard.press("Shift+Tab");
    await page.keyboard.press("Tab");
    await expect(focusTarget).toBeFocused();
    await expect(focusTarget).toHaveCSS("outline-color", "rgb(205, 176, 255)");
    await expect(focusTarget).toHaveCSS("outline-width", "2px");
    await expect(focusTarget).toHaveCSS("outline-offset", "3px");
    expect((await focusTarget.boundingBox()).height).toBeGreaterThanOrEqual(44);
    console.info(
        JSON.stringify({
            width,
            appearanceSaved,
            invitation: geometry,
            qrSlot: slotBounds,
            contrasts,
        }),
    );

    return { qrSlot: slotBounds, action: actionBounds };
}

for (const width of [1280, 375]) {
    test(`owner invitation stays truthful before and after saving appearance at ${width}px`, async ({
        page,
        request,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 900 });
        await page.emulateMedia({ colorScheme: "dark", reducedMotion: "reduce" });
        await registerInvitationOwner(page, request);

        await page
            .getByRole("navigation", { name: "Navegación principal" })
            .getByRole("link", { name: "Invitar clientes", exact: true })
            .click();
        await expect(page).toHaveURL(/\/invite$/);
        await expect(page.getByText("Pase sin preparar", { exact: true })).toBeVisible();
        await expect(
            page.getByText(
                "Prepara el pase del negocio para que tus clientes puedan guardarlo en Google Wallet.",
                {
                    exact: true,
                },
            ),
        ).toBeVisible();
        await expectInvitationState(page, false);
        const unsavedSlots = await expectInvitationPresentation(page, width, false);
        await page.screenshot({
            path: testInfo.outputPath(`invitation-unsaved-${width}.png`),
            fullPage: true,
        });
        if (width === 375) {
            await page.setViewportSize({ width: 320, height: 900 });
            await expectInvitationState(page, false);
            await expectInvitationPresentation(page, 320, false);
            await page.screenshot({
                path: testInfo.outputPath("invitation-unsaved-320.png"),
                fullPage: true,
            });
            await page.setViewportSize({ width, height: 900 });
        }

        await page
            .getByRole("main")
            .getByRole("link", { name: "Preparar pase", exact: true })
            .press("Enter");
        await expect(page).toHaveURL(/\/pass\/appearance$/);
        await page.getByLabel("Código hexadecimal").fill("#A77BFF");
        await page.getByRole("button", { name: "Guardar apariencia" }).click();
        await expect(page).toHaveURL(/\/pass$/);

        const invitation = page.getByRole("link", { name: "Ver QR para invitar", exact: true });
        await expect(invitation).toHaveAttribute("href", /\/invite$/);
        expect((await invitation.boundingBox()).height).toBeGreaterThanOrEqual(44);
        await invitation.click();
        await expect(page).toHaveURL(/\/invite$/);
        await expect(
            page.getByText("Tus clientes escanean este QR y guardan su pase en Google Wallet.", {
                exact: true,
            }),
        ).toBeVisible();
        await expect(page.getByText("Pase sin preparar", { exact: true })).toHaveCount(0);
        await expectInvitationState(page, true);
        const savedSlots = await expectInvitationPresentation(page, width, true);
        expect(savedSlots.qrSlot.width).toBe(unsavedSlots.qrSlot.width);
        expect(savedSlots.qrSlot.height).toBe(unsavedSlots.qrSlot.height);
        expect(savedSlots.action.width).toBe(unsavedSlots.action.width);
        expect(savedSlots.action.height).toBe(unsavedSlots.action.height);
        const image = page.getByRole("main").getByRole("img");
        const source = await image.getAttribute("src");
        const qrFrame = page.getByRole("group", { name: "Vista del QR del negocio" });
        await expect(qrFrame).toHaveCSS("background-color", "rgba(0, 0, 0, 0)");
        await expect(qrFrame).toHaveCSS("border-width", "0px");
        await expect(qrFrame).toHaveCSS("border-radius", "20px");
        const artwork = await image.evaluate((element) => {
            const document = new DOMParser().parseFromString(
                atob(element.src.split(",")[1]),
                "image/svg+xml",
            );
            const background = document.querySelector("rect");

            return {
                background: ["fill", "x", "y", "width", "height", "rx", "ry"].map((attribute) =>
                    background.getAttribute(attribute),
                ),
                moduleFills: [
                    ...new Set(
                        [...document.querySelectorAll("path")].map((path) =>
                            path.getAttribute("fill"),
                        ),
                    ),
                ],
                quietZone: document.querySelectorAll('g[transform="translate(4,4)"]').length,
                exteriorPadding: document.querySelectorAll('svg > g[transform="translate(15,15)"]')
                    .length,
                nonstandardContent: document.querySelectorAll(
                    "script, image, a, linearGradient, radialGradient, [opacity], [fill-opacity]",
                ).length,
            };
        });
        expect(artwork).toEqual({
            background: ["#ffffff", "0", "0", "542", "542", "48", "48"],
            moduleFills: ["#242424"],
            quietZone: 1,
            exteriorPadding: 1,
            nonstandardContent: 0,
        });
        const downloadEvent = page.waitForEvent("download");
        await page.getByRole("button", { name: "Descargar QR", exact: true }).click();
        const preparedQr = await downloadEvent;
        expect(preparedQr.suggestedFilename()).toMatch(/^fidelitopass-qr-[0-9a-f-]{36}\.svg$/);
        const output = testInfo.outputPath("prepared-qr.svg");
        await preparedQr.saveAs(output);
        expect(await readFile(output, "utf8")).toBe(
            Buffer.from(source.split(",")[1], "base64").toString("utf8"),
        );
        await page.reload();
        await expectInvitationState(page, true);
        await expect(image).toHaveAttribute("src", source);
        await page.screenshot({
            path: testInfo.outputPath(`invitation-saved-${width}.png`),
            fullPage: true,
        });

        if (width === 375) {
            await page.setViewportSize({ width: 320, height: 900 });
            await expectInvitationState(page, true);
            await expectInvitationPresentation(page, 320, true);
            await page.screenshot({
                path: testInfo.outputPath("invitation-saved-320.png"),
                fullPage: true,
            });
        }
    });
}
