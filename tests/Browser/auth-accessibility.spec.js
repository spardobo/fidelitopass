import { expect, test } from "@playwright/test";

const RESET_FORM = "/reset-password/presentation-only-token?email=owner%40example.test";

const VIEWPORT_HEIGHT = 800;
const MIN_TOUCH_TARGET_SIZE = 44;

const COLORS = {
    pageBackground: "rgb(36, 36, 36)",
    primaryFocus: "rgb(167, 123, 255)",
    secondaryFocus: "rgb(205, 176, 255)",
    controlText: "rgb(246, 245, 242)",
    controlBackground: "rgb(34, 34, 34)",
};

const GUEST_FORMS = [
    {
        path: "/login",
        heading: "Iniciar sesión en tu cuenta",
        emailLabel: "Correo electrónico",
    },
    {
        path: "/register",
        heading: "Crear una cuenta",
        emailLabel: "Correo electrónico",
    },
    {
        path: "/forgot-password",
        heading: "Recuperar contraseña",
        emailLabel: "Correo electrónico",
    },
    {
        path: RESET_FORM,
        heading: "Restablecer contraseña",
        emailLabel: "Correo electrónico",
    },
];

const PASSWORD_FORMS = ["/login", "/register", RESET_FORM];
const REGISTRATION_WIDTHS = [375, 1280, 320];

const PASSWORD_TOGGLE_NAME = "Mostrar u ocultar contraseña";
const ONEST_FONT = '400 16px "Onest Variable"';

function getPathName(path) {
    return path.split("?")[0];
}

async function expectNoHorizontalOverflow(page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
    );
}

async function expectPrimaryFocus(locator, { border = false } = {}) {
    await expect(locator).toHaveCSS("outline-style", "solid");
    await expect(locator).toHaveCSS("outline-width", "2px");
    await expect(locator).toHaveCSS("outline-color", COLORS.primaryFocus);
    await expect(locator).toHaveCSS("outline-offset", "3px");

    if (border) {
        await expect(locator).toHaveCSS("border-color", COLORS.primaryFocus);
    }
}

async function expectSecondaryFocus(locator) {
    await expect(locator).toHaveCSS("outline-style", "solid");
    await expect(locator).toHaveCSS("outline-width", "2px");
    await expect(locator).toHaveCSS("outline-color", COLORS.secondaryFocus);
}

async function getHeadingPlatformFonts(page) {
    const session = await page.context().newCDPSession(page);

    try {
        await session.send("DOM.enable");
        await session.send("CSS.enable");

        const { root } = await session.send("DOM.getDocument");

        const { nodeId } = await session.send("DOM.querySelector", {
            nodeId: root.nodeId,
            selector: "h1",
        });

        const { fonts } = await session.send("CSS.getPlatformFontsForNode", { nodeId });

        return fonts;
    } finally {
        await session.detach();
    }
}

async function getRegistrationMeasurements(page) {
    return page.evaluate(() => {
        const selectors = ["h1", 'input[name="business_name"]', 'select[name="timezone"]'];

        return selectors.map((selector) => {
            const element = document.querySelector(selector);
            const style = getComputedStyle(element);
            const { x, y, width, height } = element.getBoundingClientRect();

            return {
                selector,
                x,
                y,
                width,
                height,
                color: style.color,
                background: style.backgroundColor,
                font: style.fontFamily,
                size: style.fontSize,
                lineHeight: style.lineHeight,
            };
        });
    });
}

// these guest fixtures render forms only;
// no token is redeemed or account created.

test.beforeEach(async ({ page }) => {
    await page.route("**/*", async (route) => {
        const method = route.request().method();

        if (!["GET", "HEAD"].includes(method)) {
            await route.abort();

            throw new Error(`Guest accessibility checks must not send ${method} requests`);
        }

        await route.continue();
    });
});

for (const { path, heading, emailLabel } of GUEST_FORMS) {
    test(`guest ${getPathName(path)} retains narrow dark layout and keyboard field focus`, async ({
        page,
    }) => {
        await page.setViewportSize({
            width: 375,
            height: VIEWPORT_HEIGHT,
        });

        await page.emulateMedia({
            colorScheme: "light",
        });

        await page.addInitScript(() => {
            localStorage.setItem("flux.appearance", "light");
        });

        await page.goto(path);

        await expect(
            page.getByRole("heading", {
                name: heading,
                exact: true,
            }),
        ).toBeVisible();

        await expect(page.locator("body")).toHaveCSS("background-color", COLORS.pageBackground);

        await page.evaluate(() => document.fonts.ready);

        expect(await page.evaluate((font) => document.fonts.check(font), ONEST_FONT)).toBe(true);

        await expect(page.locator("body")).toHaveCSS("font-family", /Onest Variable/);

        await expectNoHorizontalOverflow(page);

        const email = page.getByLabel(emailLabel, {
            exact: true,
        });

        await expect(email).toBeVisible();

        await email.focus();
        await page.keyboard.press("Tab");
        await page.keyboard.press("Shift+Tab");

        await expect(email).toBeFocused();
        await expectPrimaryFocus(email);
    });
}

for (const path of PASSWORD_FORMS) {
    test(`guest ${getPathName(path)} password toggles preserve values and keyboard focus`, async ({
        page,
    }) => {
        await page.goto(path);

        const labels = path === "/login" ? ["Contraseña"] : ["Contraseña", "Confirmar contraseña"];

        const toggles = page.getByRole("button", {
            name: PASSWORD_TOGGLE_NAME,
        });

        await expect(toggles).toHaveCount(labels.length);

        for (const [index, label] of labels.entries()) {
            const password = page.getByLabel(label, {
                exact: true,
            });

            const toggle = toggles.nth(index);
            const value = `EphemeralPassword-${index}!`;

            await password.fill(value);

            await expect(password).toHaveAttribute("type", "password");

            await password.focus();
            await page.keyboard.press("Tab");

            await expect(toggle).toBeFocused();
            await expectSecondaryFocus(toggle);

            const toggleBox = await toggle.boundingBox();

            expect(toggleBox.height).toBeGreaterThanOrEqual(MIN_TOUCH_TARGET_SIZE);

            await page.keyboard.press("Space");

            await expect(password).toHaveAttribute("type", "text");
            await expect(password).toHaveValue(value);
            await expect(toggle).toBeFocused();

            await page.keyboard.press("Enter");

            await expect(password).toHaveAttribute("type", "password");
            await expect(password).toHaveValue(value);
            await expect(toggle).toBeFocused();
        }
    });
}

for (const width of REGISTRATION_WIDTHS) {
    test(`guest registration business fields retain real Onest, geometry and keyboard access at ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({
            width,
            height: VIEWPORT_HEIGHT,
        });

        await page.goto("/register");

        await page.evaluate(() => document.fonts.ready);

        // Chromium reports the font used for actual heading glyphs,
        // not just a CSS fallback list.
        const fonts = await getHeadingPlatformFonts(page);

        expect(
            fonts.some(
                (font) =>
                    font.familyName.includes("Onest") && font.isCustomFont && font.glyphCount > 0,
            ),
        ).toBe(true);

        expect(
            await page.evaluate(() =>
                performance
                    .getEntriesByType("resource")
                    .some(
                        (entry) =>
                            new URL(entry.name).origin === location.origin &&
                            /onest.*\.woff2/.test(entry.name),
                    ),
            ),
        ).toBe(true);

        const business = page.getByLabel("Nombre del negocio", { exact: true });

        const timezone = page.getByRole("combobox", {
            name: "Zona horaria",
            exact: true,
        });

        await expect(business).toHaveValue("");
        await expect(timezone).toHaveValue("");

        expect(await business.evaluate((input) => input.validity.valueMissing)).toBe(true);

        expect(await timezone.evaluate((select) => select.validity.valueMissing)).toBe(true);

        await expect(
            page.getByText(
                "Selecciona la zona horaria de tu negocio. Define los días y las fechas límite de tus promociones.",
                { exact: true },
            ),
        ).toBeVisible();

        expect(await timezone.evaluate((select) => select.reportValidity())).toBe(false);

        expect(await timezone.evaluate((select) => select.validationMessage)).toBe(
            "Completa este campo.",
        );

        await business.focus();
        await page.keyboard.press("Tab");

        await expect(timezone).toBeFocused();

        await expectPrimaryFocus(timezone, {
            border: true,
        });

        await timezone.selectOption("America/Argentina/Buenos_Aires");

        expect(await timezone.evaluate((select) => select.checkValidity())).toBe(true);

        await page.keyboard.press("Tab");

        await expect(
            page.getByLabel("Contraseña", {
                exact: true,
            }),
        ).toBeFocused();

        const measurements = await getRegistrationMeasurements(page);

        for (const control of [business, timezone]) {
            const box = await control.boundingBox();

            expect(box.height).toBeGreaterThanOrEqual(MIN_TOUCH_TARGET_SIZE);

            expect(box.x).toBeGreaterThanOrEqual(0);

            expect(box.x + box.width).toBeLessThanOrEqual(width);

            await expect(control).toHaveCSS("font-size", "16px");

            await expect(control).toHaveCSS("line-height", "24px");

            await expect(control).toHaveCSS("color", COLORS.controlText);

            await expect(control).toHaveCSS("background-color", COLORS.controlBackground);
        }

        await expectNoHorizontalOverflow(page);

        await testInfo.attach(`registration-measurements-${width}`, {
            body: JSON.stringify(
                {
                    width,
                    fonts,
                    measurements,
                },
                null,
                2,
            ),
            contentType: "application/json",
        });

        await page.screenshot({
            path: testInfo.outputPath(`registration-fields-${width}.png`),
            fullPage: true,
        });
    });
}

test("remember label and keyboard toggle the same login checkbox", async ({ page }) => {
    await page.goto("/login");

    const remember = page.getByRole("checkbox", {
        name: "Recordarme",
        exact: true,
    });

    await expect(remember).not.toBeChecked();

    await page.getByText("Recordarme", { exact: true }).click();

    await expect(remember).toBeChecked();

    await remember.focus();
    await page.keyboard.press("Shift+Tab");
    await page.keyboard.press("Tab");

    await expect(remember).toBeFocused();

    await expect(remember).toHaveCSS("outline-style", "solid");

    await expect(remember).toHaveCSS("outline-color", COLORS.secondaryFocus);

    await page.keyboard.press("Space");
    await expect(remember).not.toBeChecked();

    await page.keyboard.press("Space");
    await expect(remember).toBeChecked();
});
