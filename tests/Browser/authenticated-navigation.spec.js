import { existsSync } from "node:fs";
import { expect, test } from "@playwright/test";

const VIEWPORT_HEIGHT = 800;
const MIN_TOUCH_TARGET_SIZE = 44;

const USER_NAME = "Sergio";
const BUSINESS_NAME = "Jaqaku";
const TIMEZONE = "America/La_Paz";
const PASSWORD = "ValidPassword84!strong";

const MAILPIT_URL = "http://fidelitopass-mailpit-dev:8025";

const LOCAL_MOCKUP_PATH = "tmp/fidelitopass-maquetado/fidelitopass-menu-ajustado.html";

const LOCAL_MOCKUP_URL = "file:///work/tmp/fidelitopass-maquetado/fidelitopass-menu-ajustado.html";

const COLORS = {
    headerBackground: "rgb(36, 36, 36)",
    primary: "rgb(167, 123, 255)",
    primaryLight: "rgb(205, 176, 255)",
    inactiveNavigation: "rgb(199, 196, 206)",
    text: "rgb(246, 245, 242)",
};

const AUTHENTICATED_WIDTHS = [1280, 375];
const RESPONSIVE_WIDTHS = [320, 768, 900];

const REMOVED_HEADER_LINKS = ["Search", "Repository", "Documentation"];

const NAVIGATION_DESTINATIONS = [
    ["Pase", "/pass"],
    ["Invitar clientes", "/invite"],
    ["Registrar visita", "/visits/create"],
];

const ACCOUNT_DESTINATIONS = [
    ["Perfil del negocio", "/business/profile"],
    ["Perfil", "/settings/profile"],
    ["Seguridad", "/settings/security"],
];

async function waitForFonts(page) {
    await page.evaluate(() => document.fonts.ready);
}

async function expectInsideViewport(control, width) {
    const box = await control.boundingBox();

    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(width);

    return box;
}

async function expectMinimumTouchTarget(control) {
    const box = await control.boundingBox();

    expect(box.height).toBeGreaterThanOrEqual(MIN_TOUCH_TARGET_SIZE);
}

async function registerVerifiedBusiness(page, request) {
    const recipient = `shell-${crypto.randomUUID()}@example.test`;

    await page.goto("/register");

    await page
        .getByRole("textbox", {
            name: "Nombre",
            exact: true,
        })
        .fill(USER_NAME);

    await page
        .getByLabel("Correo electrónico", {
            exact: true,
        })
        .fill(recipient);

    await page
        .getByLabel("Nombre del negocio", {
            exact: true,
        })
        .fill(BUSINESS_NAME);

    await page
        .getByLabel("Zona horaria", {
            exact: true,
        })
        .selectOption(TIMEZONE);

    await page.locator('input[name="password"]').fill(PASSWORD);

    await page.locator('input[name="password_confirmation"]').fill(PASSWORD);

    await page
        .getByRole("button", {
            name: "Crear cuenta",
        })
        .click();

    await expect(page).toHaveURL(/\/email\/verify(?:\?|$)/);

    console.info(`Created shell browser fixture User + Business: ${recipient}`);

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

async function measureShell(page, mockup) {
    return page.evaluate((prototype) => {
        const header = document.querySelector(prototype ? ".app-header" : "[data-flux-header]");

        const navigation = header.querySelector(
            prototype && innerWidth <= 768 ? ".mobile-nav" : "nav",
        );

        const current = navigation.querySelector('[aria-current="page"]');

        const content = current.querySelector(
            prototype ? ".nav-link__content" : ".app-navigation-content",
        );

        const avatar = header.querySelector(prototype ? ".avatar" : "[data-flux-avatar]");

        const rectangle = (element) => {
            const { x, y, width, height } = element.getBoundingClientRect();

            return {
                x,
                y,
                width,
                height,
            };
        };

        const style = getComputedStyle(current);
        const marker = getComputedStyle(content, "::after");

        return {
            header: rectangle(header),
            link: rectangle(current),
            content: rectangle(content),
            avatar: rectangle(avatar),
            headerColor: getComputedStyle(header).backgroundColor,
            font: style.fontFamily,
            loadedFonts: [...document.fonts]
                .filter((font) => font.status === "loaded")
                .map((font) => font.family),
            fontSize: style.fontSize,
            marker: {
                height: marker.height,
                width: marker.width,
                bottom: marker.bottom,
                color: marker.backgroundColor,
                pointerEvents: marker.pointerEvents,
            },
            overflow: document.documentElement.scrollWidth > innerWidth,
        };
    }, mockup);
}

async function getLocalReference(page, width, testInfo) {
    // the ignored owner reference is local evidence,
    // not a CI fixture dependency.
    if (!existsSync(LOCAL_MOCKUP_PATH)) {
        console.info("Local mockup comparison unavailable; shell regression still runs.");

        return null;
    }

    await page.goto(LOCAL_MOCKUP_URL);

    await expect(
        page.locator(width <= 768 ? ".mobile-nav" : ".desktop-nav").getByRole("link", {
            name: "Resumen",
        }),
    ).toBeVisible();

    await waitForFonts(page);

    const reference = await measureShell(page, true);

    await page.screenshot({
        path: testInfo.outputPath(`mockup-${width}.png`),
        fullPage: true,
    });

    return reference;
}

for (const width of AUTHENTICATED_WIDTHS) {
    test(`authenticated navigation and local reference at ${width}px`, async ({
        page,
        request,
    }, testInfo) => {
        await page.setViewportSize({
            width,
            height: VIEWPORT_HEIGHT,
        });

        await page.emulateMedia({
            colorScheme: "dark",
            reducedMotion: "reduce",
        });

        const reference = await getLocalReference(page, width, testInfo);

        await registerVerifiedBusiness(page, request);

        const header = page.getByRole("banner");

        const navigation = header.getByRole("navigation", {
            name: "Navegación principal",
        });

        const account = header.getByRole("button", {
            name: "Abrir menú de cuenta",
        });

        await expect(header.locator("[data-flux-avatar]")).toHaveText("Se");

        await expect(
            header
                .getByRole("link", {
                    name: "FidelitoPass",
                })
                .locator("img"),
        ).toHaveAttribute("src", /logo-header\.webp$/);

        for (const label of REMOVED_HEADER_LINKS) {
            await expect(
                header.getByRole("link", {
                    name: label,
                    exact: true,
                }),
            ).toHaveCount(0);
        }

        await expect(
            header.locator(
                'a[href="#"], a[href*="github.com/laravel"], a[href*="laravel.com/docs"]',
            ),
        ).toHaveCount(0);

        const summary = navigation.getByRole("link", {
            name: "Resumen",
            exact: true,
        });

        await expect(summary).toHaveAttribute("aria-current", "page");

        await expect(header.locator('[aria-current="page"]')).toHaveCount(1);

        await waitForFonts(page);

        const actual = await measureShell(page, false);

        expect(actual.loadedFonts).toContain("Onest Variable");

        expect(actual.fontSize).toBe("16px");

        expect(actual.headerColor).toBe(COLORS.headerBackground);

        expect(actual.marker).toEqual({
            height: "2px",
            width: `${actual.content.width}px`,
            bottom: "-6px",
            color: COLORS.primary,
            pointerEvents: "none",
        });

        await expect(summary).toHaveCSS("color", COLORS.primaryLight);

        const pass = navigation.getByRole("link", {
            name: "Pase",
            exact: true,
        });

        await expect(pass).toHaveCSS("color", COLORS.inactiveNavigation);

        await pass.hover();

        await expect(pass).toHaveCSS("color", COLORS.primaryLight);

        const inactiveMarker = await pass.locator(".app-navigation-content").evaluate((content) => {
            return getComputedStyle(content, "::after").content;
        });

        expect(inactiveMarker).toBe("none");

        expect(actual.link.height).toBeGreaterThanOrEqual(MIN_TOUCH_TARGET_SIZE);

        expect(actual.avatar.width).toBe(MIN_TOUCH_TARGET_SIZE);

        expect(actual.overflow).toBe(false);

        expect(actual.header.width).toBe(width);

        expect(actual.avatar.x).toBeGreaterThanOrEqual(0);

        expect(actual.avatar.x + actual.avatar.width).toBeLessThanOrEqual(width);

        await summary.focus();
        await page.keyboard.press("Shift+Tab");
        await page.keyboard.press("Tab");

        await expect(summary).toBeFocused();

        await expect(summary).toHaveCSS("outline-color", COLORS.primaryLight);

        await expect(summary).toHaveCSS("outline-width", "2px");

        await expect(summary).toHaveCSS("outline-offset", "3px");

        await page.screenshot({
            path: testInfo.outputPath(`app-${width}.png`),
            fullPage: true,
        });

        console.info(
            JSON.stringify({
                width,
                state: "Resumen, appearance unsaved, no Promotion",
                reference,
                actual,
            }),
        );

        await testInfo.attach("shell-comparison", {
            body: JSON.stringify(
                {
                    width,
                    reference,
                    actual,
                },
                null,
                2,
            ),
            contentType: "application/json",
        });

        for (const narrowWidth of RESPONSIVE_WIDTHS) {
            await page.setViewportSize({
                width: narrowWidth,
                height: VIEWPORT_HEIGHT,
            });

            expect(
                await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),
            ).toBe(false);

            for (const control of [
                account,
                header.getByRole("link", {
                    name: "Registrar visita",
                }),
            ]) {
                await expectInsideViewport(control, narrowWidth);
            }
        }

        await page.setViewportSize({
            width,
            height: VIEWPORT_HEIGHT,
        });

        for (const [label, path] of NAVIGATION_DESTINATIONS) {
            const link = header.getByRole("link", {
                name: label,
                exact: true,
            });

            await expect(link).toBeVisible();
            await expect(link).toBeEnabled();

            await expect(link).toHaveAttribute("href", new RegExp(`${path}$`));

            await expectMinimumTouchTarget(link);

            await link.click();

            await expect(page).toHaveURL(new RegExp(`${path}$`));

            await page.goto("/dashboard");
        }

        await account.focus();
        await page.keyboard.press("Enter");

        await expect(
            page.getByRole("menuitem", {
                name: "Perfil del negocio",
            }),
        ).toBeVisible();

        const accountName = page.locator("[data-flux-menu] [data-flux-heading]");

        await expect(accountName).toHaveText(USER_NAME);

        await expect(accountName).toHaveCSS("color", COLORS.text);

        await expect(accountName).toHaveCSS("font-size", "16px");

        await page.keyboard.press("Escape");

        await expect(account).toBeFocused();

        for (const [index, [label, path]] of ACCOUNT_DESTINATIONS.entries()) {
            await account.focus();

            await page.keyboard.press("ArrowDown");

            await expect(
                page.getByRole("menuitem", {
                    name: "Perfil del negocio",
                }),
            ).toBeFocused();

            for (let step = 0; step < index; step++) {
                await page.keyboard.press("ArrowDown");
            }

            const item = page.getByRole("menuitem", {
                name: label,
                exact: true,
            });

            await expect(item).toBeFocused();

            await page.keyboard.press("Enter");

            if (label === "Seguridad") {
                await expect(page).toHaveURL(/\/confirm-password(?:\?|$)/);

                await page
                    .getByRole("textbox", {
                        name: "Contraseña",
                        exact: true,
                    })
                    .fill(PASSWORD);

                await page
                    .getByRole("button", {
                        name: "Confirmar",
                        exact: true,
                    })
                    .click();
            }

            await expect(page).toHaveURL(new RegExp(`${path}$`));

            await account.click();

            await expect(
                page.getByRole("menuitem", {
                    name: label,
                    exact: true,
                }),
            ).toHaveAttribute("aria-current", "page");

            await expect(header.locator('[aria-current="page"]')).toHaveCount(1);

            await page.keyboard.press("Escape");
        }

        await account.click();

        const logout = page.waitForResponse(
            (response) =>
                new URL(response.url()).pathname === "/logout" &&
                response.request().method() === "POST",
        );

        await page
            .getByRole("menuitem", {
                name: "Cerrar sesión",
            })
            .click();

        expect((await logout).status()).toBe(302);

        await page.goto("/dashboard");

        await expect(page).toHaveURL(/\/login(?:\?|$)/);
    });
}
