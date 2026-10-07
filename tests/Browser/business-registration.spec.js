import { expect, test } from "@playwright/test";

const MAILPIT_URL = "http://fidelitopass-mailpit-dev:8025";

const VIEWPORT_HEIGHT = 800;
const MIN_TOUCH_TARGET_SIZE = 44;
const PASSWORD = "ValidPassword84!strong";

const DEFAULT_TIMEZONE = "America/Argentina/Buenos_Aires";
const EDITED_TIMEZONE = "Europe/Madrid";
const INVALID_TIMEZONE = "Invalid/Timezone";

const COLORS = {
    canvas: "rgb(36, 36, 36)",
    authenticatedSurface: "rgb(39, 39, 39)",
    authCard: "rgb(46, 46, 46)",
    authCardBorder: "rgb(82, 82, 82)",
    controlBackground: "rgb(34, 34, 34)",
    controlBorder: "rgb(125, 125, 125)",
    primary: "rgb(167, 123, 255)",
    primaryHover: "rgb(184, 147, 255)",
    primaryPressed: "rgb(149, 102, 235)",
    primaryForeground: "rgb(23, 19, 31)",
    primaryLight: "rgb(205, 176, 255)",
    text: "rgb(246, 245, 242)",
    secondaryText: "rgb(199, 196, 206)",
    overriddenSecondaryText: "rgb(212, 208, 220)",
    overriddenPrimaryHover: "rgb(192, 159, 255)",
};

const AUTH_SURFACES = [
    ["/register", "Crear una cuenta"],
    ["/login", "Iniciar sesión"],
];

const SETTINGS_PAGES = [
    ["/settings/profile", "Perfil"],
    ["/settings/security", "Actualizar contraseña"],
    ["/settings/appearance", "Apariencia"],
];

const TOKEN_OVERRIDES = {
    "--color-app-ink-secondary": "#D4D0DC",
    "--color-app-accent-hover": "#C09FFF",
    "--spacing-app-control": "3.25rem",
};

function luminance(color) {
    const channels = color
        .match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)/)
        ?.slice(1)
        .map(Number);

    expect(channels, `rendered color: ${color}`).toBeTruthy();

    const linear = channels.map((channel) => {
        const value = channel / 255;

        return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });

    return linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
}

function contrast(foreground, background) {
    const foregroundLuminance = luminance(foreground);

    const backgroundLuminance = luminance(background);

    const light = Math.max(foregroundLuminance, backgroundLuminance);

    const dark = Math.min(foregroundLuminance, backgroundLuminance);

    return (light + 0.05) / (dark + 0.05);
}

async function waitForFonts(page) {
    await page.evaluate(() => document.fonts.ready);
}

async function expectNoHorizontalOverflow(page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
    );
}

async function expectMinimumTouchTarget(locator) {
    const box = await locator.boundingBox();

    expect(box.height).toBeGreaterThanOrEqual(MIN_TOUCH_TARGET_SIZE);
}

async function fillRegistrationPasswords(page) {
    await page.locator('input[name="password"]').fill(PASSWORD);

    await page.locator('input[name="password_confirmation"]').fill(PASSWORD);
}

async function expectReadable(page) {
    await expect(page.locator("html")).toHaveClass(/\bdark\b/);

    expect(await page.evaluate(() => localStorage.getItem("flux.appearance"))).toBe("dark");

    const colors = await page.evaluate(() => {
        const body = getComputedStyle(document.body);

        const heading = getComputedStyle(document.querySelector("h1"));

        return {
            background: body.backgroundColor,
            text: body.color,
            heading: heading.color,
        };
    });

    expect(luminance(colors.background)).toBeLessThan(0.2);

    expect(contrast(colors.text, colors.background)).toBeGreaterThanOrEqual(4.5);

    expect(contrast(colors.heading, colors.background)).toBeGreaterThanOrEqual(4.5);
}

async function expectAuthenticatedSurface(page, surface) {
    const appearance = await page.evaluate((selector) => {
        const card = document.querySelector(selector);

        return {
            font: getComputedStyle(document.body).fontFamily,

            canvas: getComputedStyle(document.body).backgroundColor,

            card: getComputedStyle(card).backgroundColor,

            overflow: document.documentElement.scrollWidth > window.innerWidth,
        };
    }, surface);

    expect(appearance.font).toContain("Onest Variable");

    expect(appearance.canvas).toBe(COLORS.canvas);

    expect(appearance.card).toBe(COLORS.authenticatedSurface);

    expect(appearance.overflow).toBe(false);
}

async function verificationLink(request, recipient) {
    let message;

    await expect
        .poll(async () => {
            const response = await request.get(`${MAILPIT_URL}/api/v1/messages`);

            expect(response.ok()).toBeTruthy();

            const mailbox = await response.json();

            message = mailbox.messages?.find((item) =>
                item.To?.some((address) => address.Address === recipient),
            );

            return Boolean(message);
        })
        .toBe(true);

    const response = await request.get(`${MAILPIT_URL}/api/v1/message/${message.ID}`);

    expect(response.ok()).toBeTruthy();

    const detail = await response.json();

    const link = detail.Text?.match(/https?:\/\/[^\s<>)]*\/email\/verify\/[^\s<>)]*/)?.[0];

    expect(link, "verification link in recipient message").toBeTruthy();

    return link.replace(/&amp;/g, "&");
}

async function registerAndVerifyOwner(page, request, width, recipient, business) {
    if (width === 1280) {
        await page.goto("/settings/appearance");

        await expect(page).toHaveURL(/\/login(?:\?|$)/);
    }

    await page.goto("/register");

    await expect(
        page.getByRole("heading", {
            name: "Crear una cuenta",
        }),
    ).toBeVisible();

    await expectReadable(page);

    expect(await page.evaluate(() => matchMedia("(prefers-color-scheme: dark)").matches)).toBe(
        true,
    );

    await page
        .getByRole("textbox", {
            name: "Nombre",
            exact: true,
        })
        .fill(`Owner ${width}`);

    await page
        .getByRole("textbox", {
            name: /correo/i,
        })
        .fill(recipient);

    await page
        .getByRole("textbox", {
            name: "Nombre del negocio",
            exact: true,
        })
        .fill(business);

    const timezone = page.getByRole("combobox", {
        name: "Zona horaria",
        exact: true,
    });

    const suggestedTimezone = await page.evaluate(
        () => Intl.DateTimeFormat().resolvedOptions().timeZone,
    );

    await expect(timezone).toHaveValue(suggestedTimezone);

    await timezone.selectOption(DEFAULT_TIMEZONE);

    await fillRegistrationPasswords(page);

    if (width === 1280) {
        // a tampered option reaches Fortify;
        // the server, not the select list, owns validity.
        await timezone.evaluate((select, invalidTimezone) => {
            select.add(new Option(invalidTimezone, invalidTimezone));

            select.value = invalidTimezone;
        }, INVALID_TIMEZONE);

        const rejected = page.waitForResponse(
            (response) =>
                response.request().method() === "POST" &&
                new URL(response.url()).pathname === "/register",
        );

        await page
            .getByRole("button", {
                name: "Crear cuenta",
            })
            .click();

        expect((await rejected).status()).toBe(302);

        await expect(page).toHaveURL(/\/register(?:\?|$)/);

        await expect(
            page.getByText("El campo zona horaria no está en la lista de valores permitidos.", {
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByLabel("Correo electrónico", {
                exact: true,
            }),
        ).toHaveValue(recipient);

        await expect(
            page.getByLabel("Nombre del negocio", {
                exact: true,
            }),
        ).toHaveValue(business);

        await timezone.selectOption(DEFAULT_TIMEZONE);

        await fillRegistrationPasswords(page);
    }

    const registered = page.waitForResponse(
        (response) =>
            response.request().method() === "POST" &&
            new URL(response.url()).pathname === "/register",
    );

    await page
        .getByRole("button", {
            name: "Crear cuenta",
        })
        .click();

    expect((await registered).status()).toBe(302);

    console.info(`Created browser fixture owner: ${recipient}`);

    await expect(page).toHaveURL(/\/email\/verify(?:\?|$)/);

    await page.goto(await verificationLink(request, recipient));

    await expect(page).toHaveURL(
        width === 1280 ? /\/settings\/appearance(?:\?|$)/ : /\/dashboard(?:\?|$)/,
    );

    await page.goto("/dashboard");
}

test("saved light appearance is replaced before authentication renders", async ({
    page,
}, testInfo) => {
    await page.addInitScript(() => localStorage.setItem("flux.appearance", "light"));

    for (const width of [375, 1280]) {
        await page.setViewportSize({
            width,
            height: VIEWPORT_HEIGHT,
        });

        for (const [path, heading] of AUTH_SURFACES) {
            await page.goto(path);

            await expectReadable(page);

            await expect(
                page.getByRole("heading", {
                    name: heading,
                }),
            ).toBeVisible();

            const logoLink = page.getByRole("link", {
                name: "FidelitoPass",
            });

            const logo = logoLink.getByRole("img");

            await expect(logo).toBeVisible();

            await expect(logo).toHaveAttribute("src", /logo-header\.webp$/);

            const card = page.locator(".rounded-2xl.border").filter({
                has: page.locator("form"),
            });

            await expect(card).toHaveCSS("background-color", COLORS.authCard);

            await expect(card).toHaveCSS("border-color", COLORS.authCardBorder);

            expect((await card.boundingBox()).width).toBe(width === 375 ? 327 : 448);

            await waitForFonts(page);

            expect(
                await page.evaluate(() => document.fonts.check('600 24px "Onest Variable"')),
            ).toBe(true);

            await expect(page.locator("body")).toHaveCSS("background-color", COLORS.canvas);

            await expect(page.locator("body")).toHaveCSS("font-family", /Onest Variable/);

            const title = page.getByRole("heading", {
                name: heading,
            });

            await expect(title).toHaveCSS("font-size", width < 768 ? "24px" : "30px");

            await expect(title).toHaveCSS("line-height", width < 768 ? "32px" : "36px");

            await expect(title).toHaveCSS("font-weight", "600");

            await expect(title).toHaveCSS("color", COLORS.text);

            const description = card.locator("[data-flux-subheading]");

            await expect(description).toHaveCSS("font-size", "16px");

            await expect(description).toHaveCSS("line-height", "24px");

            await expect(description).toHaveCSS("color", COLORS.secondaryText);

            for (const label of await card.locator("[data-flux-label]").all()) {
                await expect(label).toHaveCSS("font-size", "16px");

                await expect(label).toHaveCSS("line-height", "24px");

                await expect(label).toHaveCSS("font-weight", "500");
            }

            await logoLink.focus();

            for (const input of await card
                .locator("input[data-flux-control], select[data-flux-control]")
                .all()) {
                await expect(input).toHaveCSS("font-size", "16px");

                await expect(input).toHaveCSS("line-height", "24px");

                await expect(input).toHaveCSS("background-color", COLORS.controlBackground);

                await expect(input).toHaveCSS("border-color", COLORS.controlBorder);

                await expectMinimumTouchTarget(input);
            }

            await expectNoHorizontalOverflow(page);

            const primary = page.getByRole("button", {
                name: path === "/login" ? "Iniciar sesión" : "Crear cuenta",
            });

            await expect(primary).toHaveCSS("background-color", COLORS.primary);

            await expect(primary).toHaveCSS("color", COLORS.primaryForeground);

            await expect(primary).toHaveCSS("font-size", "16px");

            await expect(primary).toHaveCSS("line-height", "24px");

            await expectMinimumTouchTarget(primary);

            await primary.hover();

            await expect(primary).toHaveCSS("background-color", COLORS.primaryHover);

            await page.mouse.down();

            await expect(primary).toHaveCSS("background-color", COLORS.primaryPressed);

            await page.mouse.move(0, 0);
            await page.mouse.up();

            const buttonColors = await primary.evaluate((element) => ({
                foreground: getComputedStyle(element).color,

                background: getComputedStyle(element).backgroundColor,
            }));

            expect(
                contrast(buttonColors.foreground, buttonColors.background),
            ).toBeGreaterThanOrEqual(4.5);

            // shared tokens change real consumers,
            // then restore the ordinary fixture.
            await page.evaluate((values) => {
                for (const [name, value] of Object.entries(values)) {
                    document.documentElement.style.setProperty(name, value);
                }
            }, TOKEN_OVERRIDES);

            try {
                await expect(description).toHaveCSS("color", COLORS.overriddenSecondaryText);

                await expect(
                    card
                        .getByRole("button", {
                            name: "Mostrar u ocultar contraseña",
                        })
                        .first(),
                ).toHaveCSS("color", COLORS.overriddenSecondaryText);

                for (const input of await card
                    .locator("input[data-flux-control], select[data-flux-control]")
                    .all()) {
                    expect((await input.boundingBox()).height).toBe(52);
                }

                expect((await primary.boundingBox()).height).toBe(52);

                await primary.hover();

                await expect(primary).toHaveCSS("background-color", COLORS.overriddenPrimaryHover);
            } finally {
                await page.evaluate((names) => {
                    for (const name of names) {
                        document.documentElement.style.removeProperty(name);
                    }
                }, Object.keys(TOKEN_OVERRIDES));

                await page.mouse.move(0, 0);
            }

            await expect(description).toHaveCSS("color", COLORS.secondaryText);

            await expect(primary).toHaveCSS("background-color", COLORS.primary);

            await logoLink.focus();

            await page.keyboard.press("Shift+Tab");

            await page.keyboard.press("Tab");

            await expect(logoLink).toBeFocused();

            await expect(logoLink).toHaveCSS("outline-style", "solid");

            await expect(logoLink).toHaveCSS("outline-color", COLORS.primaryLight);

            await expect(logoLink).toHaveCSS("outline-offset", "3px");

            await page.keyboard.press("Tab");

            const firstInput = page.locator("input[autofocus]");

            await expect(firstInput).toBeFocused();

            await expect(firstInput).toHaveCSS("outline-style", "solid");

            await expect(firstInput).toHaveCSS("outline-width", "2px");

            await expect(firstInput).toHaveCSS("outline-color", COLORS.primary);

            await expect(firstInput).toHaveCSS("outline-offset", "3px");

            await expect(firstInput).toHaveCSS("border-color", COLORS.primary);

            await page.screenshot({
                path: testInfo.outputPath(`auth-${path.slice(1)}-${width}.png`),
                fullPage: true,
            });
        }
    }
});

for (const width of [1280, 375]) {
    test(`registration creates a business and verified owner edits it at ${width}px`, async ({
        page,
        request,
    }, testInfo) => {
        await page.setViewportSize({
            width,
            height: VIEWPORT_HEIGHT,
        });

        await page.emulateMedia({
            colorScheme: "dark",
        });

        const recipient = `registration-${width}-${crypto.randomUUID()}@example.test`;

        const business = `Negocio ${width} ${crypto.randomUUID()}`;

        await registerAndVerifyOwner(page, request, width, recipient, business);

        await expect(page).toHaveURL(/\/dashboard(?:\?|$)/);

        await expect(
            page.getByRole("heading", {
                name: "Resumen",
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByRole("heading", {
                name: business,
            }),
        ).toBeVisible();

        await expectReadable(page);

        await page.reload();

        await expectReadable(page);

        await expect(page.getByText(`Zona horaria: ${DEFAULT_TIMEZONE}`)).toBeVisible();

        await expectAuthenticatedSurface(page, "section[aria-label]");

        await page.screenshot({
            path: testInfo.outputPath(`app-header-dashboard-${width}.png`),
            fullPage: true,
        });

        const menuButton = page.getByRole("button", {
            name: "Abrir menú de cuenta",
        });

        await menuButton.click();

        await page
            .getByRole("menuitem", {
                name: "Perfil del negocio",
            })
            .click();

        await expect(page).toHaveURL(/\/business\/profile(?:\?|$)/);

        await expect(
            page.getByRole("textbox", {
                name: "Nombre del negocio",
            }),
        ).toHaveValue(business);

        await expect(page.getByLabel("Zona horaria")).toHaveValue(DEFAULT_TIMEZONE);

        await expectReadable(page);

        await expectNoHorizontalOverflow(page);

        await expectAuthenticatedSurface(page, "form[wire\\:submit]");

        await page.screenshot({
            path: testInfo.outputPath(`app-header-profile-${width}.png`),
            fullPage: true,
        });

        const editedBusiness = `Negocio actualizado ${width}`;

        const name = page.getByRole("textbox", {
            name: "Nombre del negocio",
            exact: true,
        });

        await name.focus();

        await expect(name).toBeFocused();

        await page.keyboard.press("Tab");

        await expect(page.getByLabel("Zona horaria")).toBeFocused();

        await name.fill(editedBusiness);

        await page.getByLabel("Zona horaria").selectOption(EDITED_TIMEZONE);

        await page
            .getByRole("button", {
                name: "Guardar negocio",
            })
            .click();

        await expect(page).toHaveURL(/\/dashboard(?:\?|$)/);

        await page.reload();

        await expect(
            page.getByRole("heading", {
                name: editedBusiness,
                exact: true,
            }),
        ).toBeVisible();

        await expect(
            page.getByText(`Zona horaria: ${EDITED_TIMEZONE}`, {
                exact: true,
            }),
        ).toBeVisible();

        await menuButton.click();

        await expect(
            page.getByRole("menuitem", {
                name: "Cerrar sesión",
            }),
        ).toBeVisible();

        await page
            .getByRole("menuitem", {
                name: "Perfil",
                exact: true,
            })
            .click();

        await expect(page).toHaveURL(/\/settings\/profile(?:\?|$)/);

        for (const [path, heading] of SETTINGS_PAGES) {
            await page.goto(path);

            if (
                path === "/settings/security" &&
                (await page
                    .getByRole("heading", {
                        name: "Confirmar contraseña",
                    })
                    .isVisible())
            ) {
                await page
                    .getByRole("textbox", {
                        name: "Contraseña",
                    })
                    .fill(PASSWORD);

                await page
                    .getByRole("button", {
                        name: "Confirmar",
                    })
                    .click();

                await expect(page).toHaveURL(/\/settings\/security(?:\?|$)/);
            }

            await expect(
                page
                    .getByRole("heading", {
                        name: heading,
                    })
                    .last(),
            ).toBeVisible();

            await expectAuthenticatedSurface(page, '[data-test="settings-surface"]');

            await expect(page.locator('[data-test="settings-surface"]')).toHaveCSS(
                "background-color",
                COLORS.authenticatedSurface,
            );

            await page.screenshot({
                path: testInfo.outputPath(`settings-${path.split("/").at(-1)}-${width}.png`),
                fullPage: true,
            });
        }

        await expect(
            page.getByText("El tema oscuro está activo para todas las cuentas."),
        ).toBeVisible();

        await expect(page.getByRole("radio")).toHaveCount(0);

        await page.goto("/settings/security");

        const passwordInput = page.getByRole("textbox", {
            name: "Contraseña actual",
        });

        await passwordInput.focus();

        await expect(passwordInput).toBeFocused();

        if (width === 1280) {
            await expect(
                page.getByRole("link", {
                    name: "Apariencia",
                }),
            ).toHaveCount(0);

            await page.goto("/settings/appearance");

            await expect(
                page.getByText("El tema oscuro está activo para todas las cuentas."),
            ).toBeVisible();

            await expect(page.getByRole("radio")).toHaveCount(0);

            await expectReadable(page);

            await menuButton.click();

            await page
                .getByRole("menuitem", {
                    name: "Perfil del negocio",
                })
                .click();

            await expect(page).toHaveURL(/\/business\/profile(?:\?|$)/);

            await expectReadable(page);

            await page.reload();

            await expectReadable(page);
        }

        await menuButton.click();

        await page
            .getByRole("menuitem", {
                name: "Cerrar sesión",
            })
            .click();

        await expect(page).toHaveURL(/\/$/);

        await page.goto("/dashboard");

        await expect(page).toHaveURL(/\/login(?:\?|$)/);
    });
}
