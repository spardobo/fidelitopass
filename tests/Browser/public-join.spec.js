import { expect, test } from "@playwright/test";

const fixture = JSON.parse(process.env.PLAYWRIGHT_PROMOTION_FIXTURE ?? "null");

test.beforeAll(() => {
    if (fixture?.kind !== "public-join") {
        throw new Error(
            "Public join browser tests require the guarded isolated public-join fixture.",
        );
    }
});

/** Measures WCAG contrast for opaque computed RGB colors.
 * @param {string} foreground Computed foreground RGB color.
 * @param {string} background Opaque computed card RGB color.
 * @returns {number} Relative luminance contrast ratio.
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

/** Asserts readable rendered type, including any ancestor scaling, on a shared example card.
 * @param {import("@playwright/test").Locator} card Semantic illustrative card.
 * @returns {Promise<object>} Actual rendered font and line-height matrix for review evidence.
 */
async function expectReadableCard(card) {
    const typography = await card.evaluate((element) => {
        const scale =
            element.getBoundingClientRect().width / parseFloat(getComputedStyle(element).width);
        const selectors = {
            business: "h2",
            label: "h3",
            reward: ".app-role-marketing-pass-reward, .app-role-pass-preview-reward",
            description: ".app-pass-preview-details > p:nth-of-type(2)",
            earningTerms: ".app-pass-preview-details > p:nth-of-type(3)",
            progress: ".app-role-marketing-pass-metric, .app-role-pass-preview-metric",
            extra: ".app-pass-preview-extra-points p",
            deadline: ".app-pass-preview-validity p, .app-pass-preview-reward > p",
        };
        return {
            width: element.getBoundingClientRect().width,
            scale,
            roles: Object.fromEntries(
                Object.entries(selectors).map(([role, selector]) => {
                    const text = element.querySelector(selector);
                    const style = getComputedStyle(text);
                    return [
                        role,
                        {
                            font: parseFloat(style.fontSize) * scale,
                            line: parseFloat(style.lineHeight) * scale,
                            weight: Number(style.fontWeight),
                        },
                    ];
                }),
            ),
        };
    });
    expect(typography.scale).toBeCloseTo(1, 2);
    const wide = typography.width >= 448;
    const credentials = card.locator(".app-pass-preview-qr, .app-pass-preview-manual-code");
    for (const region of await credentials.all()) {
        if (wide) await expect(region).toBeVisible();
        else await expect(region).toBeHidden();
    }
    const sizes = {
        business: [wide ? 28 : 24, 1.15, 700],
        label: [14, 1.35, 500],
        reward: [wide ? 24 : 22, 1.25, 600],
        description: [16, 1.45, 400],
        earningTerms: [16, 1.45, 400],
        progress: [wide ? 28 : 26, 1.15, 700],
        extra: [14, 1.35, 400],
        deadline: [14, 1.35, 400],
    };
    for (const [role, [font, ratio, weight]] of Object.entries(sizes)) {
        expect(typography.roles[role].font, `${role} rendered font`).toBeCloseTo(font, 1);
        expect(typography.roles[role].line, `${role} rendered line height`).toBeCloseTo(
            font * ratio,
            1,
        );
        expect(typography.roles[role].weight, `${role} weight`).toBe(weight);
    }
    await expect(card.locator('svg path[d^="M21 11.25v8.25"]')).toHaveCount(0);
    const details = await card.evaluate((element) => {
        const scale =
            element.getBoundingClientRect().width / parseFloat(getComputedStyle(element).width);
        const manual = element.querySelector(".app-pass-preview-manual-code");
        const header = element.querySelector(".app-pass-preview-header");
        const footer = element.querySelector(".app-pass-preview-reward");
        const icons = [
            [element.querySelector(".app-pass-preview-details > div"), "h3"],
            [element.querySelector(".app-pass-preview-extra-points"), "p"],
            [element.querySelector(".app-pass-preview-validity"), "p"],
        ].map(([row, selector]) => {
            const icon = row.querySelector("svg");
            const text = row.querySelector(selector);
            const bounds = icon.getBoundingClientRect();
            const textBounds = text.getBoundingClientRect();
            return {
                center: bounds.y + bounds.height / 2,
                firstLine:
                    textBounds.y + (parseFloat(getComputedStyle(text).lineHeight) * scale) / 2,
                decorative: icon.getAttribute("aria-hidden"),
            };
        });
        return {
            icons,
            header: parseFloat(getComputedStyle(header).borderBottomWidth) * scale,
            footer: parseFloat(getComputedStyle(footer).borderTopWidth) * scale,
            manual: manual &&
                manual.getBoundingClientRect().height > 0 && {
                    inline: parseFloat(getComputedStyle(manual).borderInlineStartWidth) * scale,
                    block: parseFloat(getComputedStyle(manual).borderBlockStartWidth) * scale,
                    height: manual.getBoundingClientRect().height,
                    roles: [manual.querySelector("p"), manual.querySelector("span")].map((text) => {
                        const style = getComputedStyle(text);
                        return {
                            font: parseFloat(style.fontSize) * scale,
                            line: parseFloat(style.lineHeight) * scale,
                            weight: Number(style.fontWeight),
                        };
                    }),
                },
        };
    });
    for (const icon of details.icons) {
        expect(Math.abs(icon.center - icon.firstLine)).toBeLessThan(0.6);
        expect(icon.decorative).toBe("true");
    }
    expect(details.header).toBeCloseTo(1, 1);
    expect(details.footer).toBeCloseTo(1, 1);
    if (details.manual) {
        expect(details.manual.inline).toBeCloseTo(1, 2);
        expect(details.manual.block).toBeCloseTo(0, 2);
        expect(details.manual.height).toBeGreaterThan(20);
        for (const [index, [font, weight, ratio]] of [
            [12, 500, 1.35],
            [wide ? 22 : 20, 700, 1.15],
        ].entries()) {
            expect(details.manual.roles[index].font).toBeCloseTo(font, 1);
            expect(details.manual.roles[index].weight).toBe(weight);
            expect(details.manual.roles[index].line).toBeCloseTo(font * ratio, 1);
        }
    }
    return { ...typography, details };
}

for (const width of [320, 1280]) {
    for (const state of ["active", "scheduled", "waiting"]) {
        test(`persisted ${state} case keeps the public example readable at ${width}px`, async ({
            browser,
        }, testInfo) => {
            const context = await browser.newContext({
                viewport: { width, height: 900 },
                colorScheme: "light",
                hasTouch: width === 320,
            });
            const page = await context.newPage();

            try {
                const business = fixture.businesses[state];
                const errors = [];
                page.on("pageerror", (error) => errors.push(error.message));
                await page.addInitScript(() => localStorage.setItem("flux.appearance", "light"));
                await page.goto(`/join/${business.publicId}`);
                await page.evaluate(() => document.fonts.ready);

                const main = page.getByRole("main");
                const card = main.getByRole("article", {
                    name: "Promoción de ejemplo del negocio",
                });
                const fontMatrix = await expectReadableCard(card);
                console.log(JSON.stringify({ state, viewport: width, fontMatrix }));
                const logo = page.getByRole("banner").getByRole("img", { name: "FidelitoPass" });
                await expect(logo).toBeVisible();
                await expect(logo).toHaveAttribute("src", /\/logo-header\.webp$/);
                await expect(logo).toHaveAttribute("width", "480");
                await expect(logo).toHaveAttribute("height", "105");
                expect(
                    await logo.evaluate((image) => image.complete && image.naturalWidth === 480),
                ).toBe(true);
                const logoBounds = await logo.boundingBox();
                const cardBounds = await card.boundingBox();
                const rowGeometry = await card.evaluate((element) => {
                    const scale = element.getBoundingClientRect().width / element.offsetWidth;
                    const shell = element.closest("[data-thumbnail]");
                    const header = element.querySelector(".app-pass-preview-header");
                    const body = element.querySelector(".app-pass-preview-content");
                    const footer = element.querySelector(".app-pass-preview-reward");
                    return {
                        sourceWidth: element.offsetWidth,
                        sourceHeight: element.offsetHeight,
                        shellHeight: shell.getBoundingClientRect().height,
                        scale,
                        header: header.getBoundingClientRect().height / scale,
                        body: body.getBoundingClientRect().height / scale,
                        footer: footer.getBoundingClientRect().height / scale,
                        contentFits: [header, body, footer].every(
                            (row) => row.scrollHeight <= row.clientHeight + 1,
                        ),
                    };
                });
                expect(rowGeometry.sourceWidth).toBeCloseTo(cardBounds.width, 0);
                expect(rowGeometry.scale).toBeCloseTo(1, 2);
                if (width >= 448) expect(rowGeometry.sourceHeight).toBeGreaterThanOrEqual(384);
                expect(rowGeometry.header).toBeLessThan(160);
                expect(rowGeometry.shellHeight).toBeCloseTo(cardBounds.height, 0);
                expect(rowGeometry.contentFits).toBe(true);
                expect(
                    Math.abs(cardBounds.x + cardBounds.width / 2 - width / 2),
                ).toBeLessThanOrEqual(1);
                expect(logoBounds.width).toBe(width < 640 ? 160 : 192);
                expect(
                    Math.abs(logoBounds.x + logoBounds.width / 2 - width / 2),
                ).toBeLessThanOrEqual(1);
                expect(logoBounds.y + logoBounds.height).toBeLessThan(cardBounds.y);
                await expect(main.getByRole("heading", { name: business.name })).toBeVisible();
                await expect(main.getByRole("heading", { level: 1 })).toHaveCSS("width", "1px");
                await expect(card.getByRole("heading", { name: business.name })).toHaveCSS(
                    "font-size",
                    width === 320 ? "24px" : "28px",
                );
                await expect(
                    main.getByRole("button", { name: "Unirme y añadir a Google Wallet" }),
                ).toBeEnabled();
                await expect(main.locator("input:not([type=hidden]), canvas")).toHaveCount(0);
                await expect(card.locator(".app-pass-preview-qr img")).toHaveAttribute(
                    "src",
                    /preview-pass-qr\.svg$/,
                );
                await expect(card.locator(".app-pass-preview-manual-code")).toContainText("48273");
                await expect(card.locator(".app-pass-preview-header svg")).toBeVisible();
                await expect(main).toContainText("Promoción de ejemplo.");
                const caption = main.locator(".app-note-with-icon");
                await expect(caption).toHaveText("Promoción de ejemplo.");
                await expect(caption).toHaveClass(/text-app-ink-help/);
                await expect(caption).toHaveCSS("gap", "8px");
                await expect(caption.locator("p")).toHaveCSS("font-size", "14px");
                await expect(caption.locator("p")).toHaveCSS("line-height", "20px");
                const informationIcon = caption.locator("svg");
                await expect(informationIcon).toHaveAttribute("aria-hidden", "true");
                await expect(informationIcon).toHaveAttribute("viewBox", "0 0 24 24");
                await expect(informationIcon).toHaveCSS("width", "16px");
                await expect(informationIcon).toHaveCSS("height", "16px");
                const iconBox = await informationIcon.boundingBox();
                const captionBox = await caption.locator("p").boundingBox();
                expect(Math.abs(iconBox.y + iconBox.height / 2 - captionBox.y - 10)).toBeLessThan(
                    0.1,
                );
                await expect(main).toContainText(
                    "No sirven para validar visitas. No es una tarjeta emitida.",
                );
                await expect(main).not.toContainText(
                    /9 \/ 15|Tu tarjeta ya está lista|Promoción actual|Próxima promoción|Todavía no está activa/,
                );
                await expect(card).not.toContainText(/Capuchino|desayuno artesanal|09:00|×3/);
                await expect(card).not.toContainText(/Consigue \d+ puntos hasta/);
                await expect(card.locator(".app-pass-preview-extra-points")).toHaveText(
                    "Ahora tu visita vale 2 puntos.",
                );
                const content = await card.innerText();
                const terms = [
                    business.name,
                    "PROMOCIÓN",
                    "Un beneficio especial",
                    "Accede a un beneficio exclusivo al completar tu meta.",
                    "3 / 10 puntos",
                    "Ahora tu visita vale 2 puntos.",
                    "Válido hasta el 15 de octubre de 2026",
                ];
                let previous = -1;
                for (const term of terms) {
                    const position = content.indexOf(term);
                    expect(position).toBeGreaterThan(previous);
                    previous = position;
                }

                await expect(page.locator("html")).toHaveClass(/dark/);
                await expect(page.locator("body")).toHaveCSS("background-color", "rgb(36, 36, 36)");
                await expect(card).toHaveCSS(
                    "background-color",
                    {
                        active: "rgb(167, 123, 255)",
                        scheduled: "rgb(0, 0, 0)",
                        waiting: "rgb(46, 46, 46)",
                    }[state],
                );
                const presentation = await card.evaluate((element) => {
                    const style = getComputedStyle(element);

                    return {
                        ink: style.color,
                        background: style.backgroundColor,
                        font: style.fontFamily,
                        radius: style.borderTopLeftRadius,
                        gradient: style.backgroundImage,
                        sheen: style.getPropertyValue("--pass-sheen-rgb").trim(),
                        fontLoaded: document.fonts.check('400 16px "Onest Variable"'),
                        overflow: document.documentElement.scrollWidth > innerWidth,
                    };
                });
                expect(presentation.gradient).toContain("linear-gradient");
                if (state === "scheduled") {
                    expect(presentation.sheen).toBe("0 0 0");
                    expect(presentation.gradient).toContain("rgba(0, 0, 0, 0.24)");
                }
                expect(presentation.font).toContain("Onest Variable");
                expect(presentation.fontLoaded).toBe(true);
                expect(presentation.radius).toBe("16px");
                expect(presentation.overflow).toBe(false);
                expect(
                    contrastRatio(presentation.ink, presentation.background),
                ).toBeGreaterThanOrEqual(4.5);

                const about = page
                    .getByRole("banner")
                    .getByRole("link", { name: "Inicio de FidelitoPass" });
                await expect(page.locator("footer")).toHaveCount(0);
                await expect(about).toHaveCSS("cursor", "pointer");
                await page.keyboard.press("Tab");
                await expect(about).toBeFocused();
                await expect(about).toHaveCSS("outline-width", "2px");
                await expect(about).toHaveCSS("outline-color", "rgb(205, 176, 255)");
                const target = await about.boundingBox();
                expect(target.height).toBeGreaterThanOrEqual(44);
                expect(target.width).toBeGreaterThanOrEqual(44);
                await page.screenshot({
                    path: testInfo.outputPath(`${state}-${width}.png`),
                    fullPage: true,
                });
                if (width === 320) {
                    await about.tap();
                } else {
                    await page.keyboard.press("Enter");
                }
                await expect(page).toHaveURL(/\/$/);
                const landingFonts = await expectReadableCard(page.locator("[data-pass]"));
                console.log(JSON.stringify({ state, viewport: width, landingFonts }));
                const landingGeometry = await page.locator("[data-pass]").evaluate((element) => {
                    const bounds = element.getBoundingClientRect();
                    const style = getComputedStyle(element);

                    return {
                        width: bounds.width,
                        height: bounds.height,
                        rows: style.gridTemplateRows,
                    };
                });
                console.log(
                    JSON.stringify({
                        state,
                        viewport: width,
                        card: cardBounds,
                        rowGeometry,
                        landing: landingGeometry,
                    }),
                );
                expect(errors).toEqual([]);
            } finally {
                await context.close();
            }
        });
    }
}

test("public information remains readable without JavaScript", async ({ browser }) => {
    const context = await browser.newContext({
        javaScriptEnabled: false,
        viewport: { width: 375, height: 812 },
    });

    try {
        const page = await context.newPage();
        await page.goto(`/join/${fixture.businesses.active.publicId}`);
        await expect(page.getByRole("heading", { name: "PROMOCIÓN", exact: true })).toBeVisible();
        await expect(page.getByRole("main")).toContainText("Un beneficio especial");
        await expect(page.locator(".app-pass-preview-qr")).toBeHidden();
        await expect(page.locator(".app-pass-preview-manual-code")).toBeHidden();
        await expect(page.getByRole("main")).toContainText("No sirven para validar visitas.");
        await expect(
            page.getByRole("button", { name: "Unirme y añadir a Google Wallet" }),
        ).toBeDisabled();
        await expect(
            page.getByText("Activa JavaScript para solicitar tu tarjeta.", { exact: false }),
        ).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
            true,
        );
    } finally {
        await context.close();
    }
});

test("public card shares bounded mouse tilt sheen and returns to rest", async ({ page }) => {
    await page.goto(`/join/${fixture.businesses.active.publicId}`);
    const card = page.getByRole("article");
    const bounds = await card.boundingBox();
    await page.mouse.move(bounds.x + bounds.width * 0.9, bounds.y + bounds.height * 0.2);
    await expect
        .poll(() => card.evaluate((el) => getComputedStyle(el).transform))
        .toMatch(/^matrix3d\(/);
    await expect
        .poll(() => card.evaluate((el) => parseFloat(el.style.getPropertyValue("--sheen-x"))))
        .toBeCloseTo(82, 1);
    await expect
        .poll(() => card.evaluate((el) => parseFloat(el.style.getPropertyValue("--sheen-y"))))
        .toBeCloseTo(26, 1);
    await expect(card).toHaveCSS("background-image", /linear-gradient/);
    await card.dispatchEvent("pointermove", {
        pointerType: "mouse",
        clientX: bounds.x + bounds.width / 2,
        clientY: bounds.y + bounds.height / 2,
    });
    await expect(card).toHaveCSS("transform", "none");
    await page.mouse.move(0, 0);
    await expect(card).toHaveCSS("transform", "none");
    expect(await card.evaluate((el) => el.style.getPropertyValue("--sheen-x"))).toBe("");
});

test("public card stays static for touch and changing reduced motion preferences", async ({
    page,
}) => {
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.goto(`/join/${fixture.businesses.active.publicId}`);
    const card = page.getByRole("article");
    const bounds = await card.boundingBox();
    const point = { clientX: bounds.x + bounds.width, clientY: bounds.y };
    await card.dispatchEvent("pointermove", { pointerType: "mouse", ...point });
    await expect(card).toHaveCSS("transform", "none");
    await expect(card).toHaveCSS("transition-duration", "0s");
    await page.emulateMedia({ reducedMotion: "no-preference" });
    await card.dispatchEvent("pointermove", { pointerType: "mouse", ...point });
    await expect
        .poll(() => card.evaluate((el) => getComputedStyle(el).transform))
        .toMatch(/^matrix3d\(/);
    await page.emulateMedia({ reducedMotion: "reduce" });
    await expect(card).toHaveCSS("transform", "none");
    expect(await card.evaluate((el) => el.style.getPropertyValue("--sheen-x"))).toBe("");
    await page.emulateMedia({ reducedMotion: "no-preference" });
    await card.dispatchEvent("pointermove", { pointerType: "touch", ...point });
    await expect(card).toHaveCSS("transform", "none");
});

test("replacing the interactive root cancels stale frames and initializes its successor", async ({
    page,
}) => {
    await page.goto(`/join/${fixture.businesses.active.publicId}`);
    const card = page.getByRole("article");
    const staleAppearance = await card.evaluate(async (element) => {
        const root = element.closest("[data-thumbnail]");
        const replacement = root.cloneNode(true);
        const bounds = root.getBoundingClientRect();
        element.dispatchEvent(
            new PointerEvent("pointermove", {
                pointerType: "mouse",
                clientX: bounds.right,
                clientY: bounds.top,
            }),
        );
        root.replaceWith(replacement);
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        return {
            transform: element.style.transform,
            sheen: element.style.getPropertyValue("--sheen-x"),
        };
    });
    expect(staleAppearance).toEqual({ transform: "", sheen: "" });
    const bounds = await card.boundingBox();
    await card.dispatchEvent("pointermove", {
        pointerType: "mouse",
        clientX: bounds.x + bounds.width,
        clientY: bounds.y,
    });
    await expect
        .poll(() => card.evaluate((el) => getComputedStyle(el).transform))
        .toMatch(/^matrix3d\(/);
    await card.dispatchEvent("pointerleave");
    await expect(card).toHaveCSS("transform", "none");
});

for (const width of [320, 1280]) {
    test(`owner overview and editor share the example and reactive colour at ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 900 });
        await page.goto("/login");
        await page.locator('input[name="email"]').fill(fixture.owner.email);
        await page.locator('input[name="password"]').fill(fixture.owner.password);
        await page.locator('[data-test="login-button"]').click();
        await expect(page).not.toHaveURL(/\/login$/);
        let overviewText;
        for (const mode of ["overview", "editor"]) {
            await page.goto(mode === "overview" ? "/pass" : "/pass/appearance");
            await page.evaluate(() => document.fonts.ready);
            const card = page.getByRole("article", {
                name: "Tarjeta de fidelidad de ejemplo",
                exact: true,
            });
            const fontMatrix = await expectReadableCard(card);
            console.log(JSON.stringify({ mode, viewport: width, fontMatrix }));
            await expect(card).toContainText("PROMOCIÓN");
            const label = card.getByRole("heading", { name: "PROMOCIÓN", exact: true });
            expect((await label.boundingBox()).height).toBeCloseTo(18.9, 1);
            await expect(card).toContainText("Una recompensa especial");
            await expect(card).toContainText("4 / 12 puntos");
            await expect(card).toContainText("Ahora tu visita vale 3 puntos.");
            await expect(card).toContainText("Válido hasta el 20 de noviembre de 2026");
            await expect(card.getByRole("img", { name: "QR de ejemplo" })).toHaveCount(0);
            await expect(card.locator(".app-pass-preview-manual-code")).toHaveCount(0);
            await expect(card).not.toContainText("48273");
            await expect(card).not.toHaveAttribute("aria-description");
            await expect(card).toHaveCSS("background-color", "rgb(167, 123, 255)");
            if (mode === "overview") {
                const insets = await card.evaluate((element) => {
                    const panel = element
                        .closest(".app-pass-overview-layout")
                        .getBoundingClientRect();
                    const bounds = element.getBoundingClientRect();
                    return { left: bounds.left - panel.left, right: panel.right - bounds.right };
                });
                expect(insets.left).toBeGreaterThanOrEqual(16);
                expect(insets.right).toBeGreaterThanOrEqual(16);
                overviewText = await card.innerText();
            } else {
                expect(await card.innerText()).toBe(overviewText);
                const blue = page.getByRole("button", {
                    name: "Usar el color Azul (#1E88E5)",
                    exact: true,
                });
                await page.keyboard.press("Tab");
                await blue.focus();
                await expect(blue).toBeFocused();
                await expect(blue).toHaveCSS("outline-width", "2px");
                await blue.press("Enter");
                await expect(card).toHaveCSS("background-color", "rgb(30, 136, 229)");
                expect(await card.innerText()).toBe(overviewText);
            }
            const geometry = await card.evaluate((element) => ({
                width: element.getBoundingClientRect().width,
                height: element.getBoundingClientRect().height,
                overflow: document.documentElement.scrollWidth > innerWidth,
                fits:
                    element.scrollWidth <= element.clientWidth + 1 &&
                    element.scrollHeight <= element.clientHeight + 1,
            }));
            expect(geometry.width).toBeLessThanOrEqual(400);
            if (width === 320) expect(geometry.width).toBe(mode === "overview" ? 206 : 240);
            expect(geometry.overflow).toBe(false);
            expect(geometry.fits).toBe(true);
            console.log(JSON.stringify({ mode, viewport: width, ownerCard: geometry }));
            await page.screenshot({
                path: testInfo.outputPath(`owner-${mode}-${width}.png`),
                fullPage: true,
            });
        }
        // Reloading, without submitting appearance, must retain the saved Business colour.
        await page.goto("/pass");
        await expect(
            page.getByRole("article", { name: "Tarjeta de fidelidad de ejemplo", exact: true }),
        ).toHaveCSS("background-color", "rgb(167, 123, 255)");
    });
}

test("card container owns the 448px role boundary and wrapped icons keep first-line alignment", async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`/join/${fixture.businesses.active.publicId}`);
    const card = page.getByRole("article", { name: "Promoción de ejemplo del negocio" });
    for (const width of [447, 447.5, 448]) {
        await page
            .locator("[data-thumbnail]")
            .evaluate((root, size) => (root.style.width = `${size}px`), width);
        await expect(card).toHaveCSS("width", `${width}px`);
        const matrix = await expectReadableCard(card);
        console.log(JSON.stringify({ boundary: width, matrix }));
    }
    await page.locator("[data-thumbnail]").evaluate((root) => (root.style.width = "288px"));
    await card
        .locator(".app-pass-preview-extra-points p")
        .evaluate(
            (text) =>
                (text.textContent =
                    "Ahora tu visita vale 2 puntos al completar una visita en este ejemplo."),
        );
    await expectReadableCard(card);
});

test("explicit saving guards duplicates and reopens only the confirmed private handoff", async ({
    page,
    context,
}) => {
    await page.addInitScript(() => {
        window.walletFeedback = [];
        document.addEventListener("toast-show", (event) =>
            window.walletFeedback.push(structuredClone(event.detail)),
        );
    });
    const publicId = fixture.businesses.active.publicId;
    const saveUrl = "https://pay.google.com/gp/v/save/header.payload.signature";
    let requests = 0;
    let release;
    const responding = new Promise((resolve) => {
        release = resolve;
    });
    await page.route(`**/join/${publicId}/save`, async (route) => {
        requests++;
        expect(route.request().method()).toBe("POST");
        expect(route.request().headers()["x-csrf-token"]).toBeTruthy();
        await responding;
        await route.fulfill({
            status: 201,
            json: { save_url: saveUrl, manual_code: "00007" },
            headers: { "Cache-Control": "no-store, private" },
        });
    });
    await context.route("https://pay.google.com/**", (route) =>
        route.fulfill({ body: "Wallet handoff stub" }),
    );
    await page.goto(`/join/${publicId}`);
    const save = page.getByRole("button", { name: "Unirme y añadir a Google Wallet" });

    await save.click();
    await expect(save).toBeDisabled();
    await expect(save).toHaveText("", { useInnerText: true });
    await expect(save.locator("svg")).toBeVisible();
    await expect(page.locator("[data-customer-pass-acquisition]")).toHaveAttribute(
        "aria-busy",
        "true",
    );
    await page.locator("[data-customer-pass-acquisition]").dispatchEvent("submit");
    expect(requests).toBe(1);
    release();

    await expect(page.locator("[data-customer-pass-acquisition]").getByRole("status")).toHaveCount(
        0,
    );
    const manualCode = page.getByText("00007", { exact: true });
    await expect(manualCode).toBeVisible();
    await expect(manualCode).toHaveCSS("font-size", "48px");
    await expect(page.locator("ui-toast-group")).toHaveCount(0);
    await expect(page.locator("ui-toast")).toHaveCount(1);
    const handoff = page.getByRole("link", {
        name: "Agregar a la Billetera de Google (nueva pestaña)",
    });
    await expect(handoff).toHaveAttribute("href", saveUrl);
    await expect(handoff).toBeFocused();
    await expect(handoff).toHaveAttribute("target", "_blank");
    await expect(handoff).toHaveAttribute("rel", "noopener noreferrer");
    await expect(handoff.locator("img")).toHaveAttribute(
        "src",
        /images\/google-wallet\/add-to-google-wallet-es-419\.svg$/,
    );
    const brandGeometry = await handoff.evaluate((link) => {
        const image = link.querySelector("img");
        const bounds = image.getBoundingClientRect();
        const wrapper = getComputedStyle(link.parentElement);
        return {
            width: bounds.width,
            height: bounds.height,
            clearSpace: [
                wrapper.paddingTop,
                wrapper.paddingRight,
                wrapper.paddingBottom,
                wrapper.paddingLeft,
            ],
        };
    });
    expect(brandGeometry.height).toBeGreaterThanOrEqual(48);
    expect(brandGeometry.width / brandGeometry.height).toBeCloseTo(239 / 55, 4);
    expect(brandGeometry.clearSpace).toEqual(["8px", "8px", "8px", "8px"]);
    await expect(
        page.locator("form > div").filter({ has: page.locator("[data-flux-error]") }),
    ).toBeHidden();
    await expect(page.getByRole("main")).toHaveCSS("gap", "16px");
    await expect(page.locator('[data-flux-toast-dialog][data-variant="success"]')).toBeVisible();
    await expect(page.locator("ui-toast")).toHaveAttribute("position", "bottom end");
    for (let opening = 0; opening < 2; opening++) {
        const popupReady = page.waitForEvent("popup");
        await handoff.click();
        const popup = await popupReady;
        await popup.waitForURL(saveUrl);
        await popup.close();
    }
    expect(requests).toBe(1);
    const feedback = await page.evaluate(() => window.walletFeedback);
    expect(feedback).toHaveLength(1);
    expect(feedback[0].slots.text).toBe("Tu tarjeta fue creada.");
    expect(feedback[0].dataset).not.toHaveProperty("position");
    expect(JSON.stringify(feedback)).not.toContain(saveUrl);
    expect(JSON.stringify(feedback)).not.toContain("00007");
    await expect(page.getByRole("article")).toContainText("3 / 10 puntos");
    await expect(page.getByRole("article")).not.toContainText("00007");
});

for (const [outcome, retryAllowed] of [
    ["definitive", true],
    ["unknown", false],
]) {
    test(`saving ${outcome} exposes only the permitted retry interaction`, async ({ page }) => {
        await page.addInitScript(() => {
            window.walletFeedback = [];
            document.addEventListener("toast-show", (event) =>
                window.walletFeedback.push(structuredClone(event.detail)),
            );
        });
        const publicId = fixture.businesses.active.publicId;
        let requests = 0;
        await page.route(`**/join/${publicId}/save`, (route) => {
            requests++;
            return route.fulfill({
                status: 503,
                json: { outcome, stage: "provider", retry_allowed: retryAllowed },
            });
        });
        await page.goto(`/join/${publicId}`);
        const save = page.getByRole("button", { name: "Unirme y añadir a Google Wallet" });
        await page.evaluate(() =>
            window.Flux.toast({
                text: "Tu tarjeta fue creada.",
                variant: "success",
            }),
        );
        await expect(page.locator("ui-toast")).toHaveAttribute("position", "bottom end");
        await page.evaluate(() => (window.walletFeedback = []));

        await save.click();
        const error = page
            .locator("[data-customer-pass-acquisition]")
            .getByRole("alert")
            .filter({ visible: true });
        await expect(error).toBeVisible();
        await expect(page.locator('[data-flux-toast-dialog][data-variant="danger"]')).toBeVisible();
        const toast = page.locator('[data-flux-toast-dialog][data-variant="danger"]');
        await expect(toast).toContainText("Hubo un problema al generar tu tarjeta.");
        await expect(page.locator("ui-toast")).toHaveAttribute("position", "bottom end");
        await expect(toast).not.toContainText(await error.textContent());
        if (retryAllowed) {
            await expect(error).toContainText("Inténtalo más tarde o contacta con el negocio.");
            await expect(save).toBeEnabled();
            await save.click();
            await expect.poll(() => requests).toBe(2);
        } else {
            await expect(error).toContainText(
                "No pudimos confirmar si tu tarjeta se creó. Para evitar duplicados, no la solicites de nuevo. Contacta con el negocio.",
            );
            await expect(save).toBeDisabled();
            await page.locator("[data-customer-pass-acquisition]").dispatchEvent("submit");
            expect(requests).toBe(1);
        }
        await expect
            .poll(() => page.evaluate(() => window.walletFeedback.length))
            .toBe(retryAllowed ? 2 : 1);
        const feedback = await page.evaluate(() => window.walletFeedback);
        for (const event of feedback) {
            expect(event.dataset).not.toHaveProperty("position");
        }
        await expect(
            page.getByRole("link", { name: "Agregar a la Billetera de Google (nueva pestaña)" }),
        ).toBeHidden();
    });
}

test("unconfigured join stays public without an issuance action", async ({ page }) => {
    const publicId = fixture.businesses.waiting.publicId;
    let requests = 0;
    await page.route(`**/join/${publicId}/save`, (route) => {
        requests++;
        return route.abort();
    });
    await page.goto(`/join/${publicId}`);
    await expect(page.getByRole("article")).toContainText("Un beneficio especial");
    await expect(page.getByRole("main")).toContainText(
        "Este negocio todavía no ha preparado su tarjeta. Consulta al negocio para saber cuándo estará disponible.",
    );
    await expect(page.getByRole("button", { name: "Unirme y añadir a Google Wallet" })).toHaveCount(
        0,
    );
    await expect(page.locator("[data-customer-pass-acquisition]")).toHaveCount(0);
    expect(requests).toBe(0);
});

test("appearance reset after page load explains the prerequisite without a retry", async ({
    page,
}) => {
    const publicId = fixture.businesses.active.publicId;
    let requests = 0;
    await page.route(`**/join/${publicId}/save`, (route) => {
        requests++;
        return route.fulfill({
            status: 409,
            json: {
                outcome: "definitive",
                stage: "preparation",
                retry_allowed: false,
                code: "business_unconfigured",
                message: "Private provider diagnostic",
            },
        });
    });
    await page.goto(`/join/${publicId}`);
    const form = page.locator("[data-customer-pass-acquisition]");
    await page.getByRole("button", { name: "Unirme y añadir a Google Wallet" }).click();
    await expect(form.getByRole("alert").filter({ visible: true })).toContainText(
        "Este negocio todavía no ha preparado su tarjeta.",
    );
    await expect(
        page.getByRole("button", { name: "Unirme y añadir a Google Wallet" }),
    ).toBeHidden();
    await expect(
        page.getByRole("link", { name: "Agregar a la Billetera de Google (nueva pestaña)" }),
    ).toBeHidden();
    await form.dispatchEvent("submit");
    expect(requests).toBe(1);
    await expect(page.getByRole("main")).not.toContainText("Private provider diagnostic");
});
