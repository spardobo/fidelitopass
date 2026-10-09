import { existsSync } from "node:fs";
import { expect, test } from "@playwright/test";

/**
 * @typedef {{x: number, y: number, width: number, height: number}} ShellRectangle Viewport-relative element dimensions.
 * @typedef {Object} ShellMeasurements
 * @property {ShellRectangle} header Header bounds.
 * @property {ShellRectangle} link Current navigation link bounds.
 * @property {ShellRectangle} content Current navigation content bounds.
 * @property {ShellRectangle} avatar Account avatar bounds.
 * @property {string} headerColor Computed header background color.
 * @property {string} font Computed font family for the current navigation link.
 * @property {string[]} loadedFonts Font families loaded in the document.
 * @property {string} fontSize Computed font size for the current navigation link.
 * @property {{height: string, width: string, bottom: string, color: string, pointerEvents: string}} marker Current-link marker pseudo-element styles.
 * @property {boolean} overflow Whether the document exceeds the viewport width.
 */

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

/**
 * Waits for all document fonts to finish loading.
 *
 * @param {import('@playwright/test').Page} page Browser page.
 * @returns {Promise<void>} Resolves when the FontFaceSet is ready and rejects if page evaluation fails.
 */
async function waitForFonts(page) {
    await page.evaluate(() => document.fonts.ready);
}

/**
 * Verifies that a control is fully contained within the viewport width.
 *
 * @param {import('@playwright/test').Locator} control Element being measured.
 * @param {number} width Viewport width in pixels.
 * @returns {Promise<ShellRectangle>} Viewport-relative control bounds when they fit; rejects when Playwright cannot measure the control or the bounds exceed the viewport.
 */
async function expectInsideViewport(control, width) {
    const box = await control.boundingBox();

    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(width);

    return box;
}

/**
 * Verifies that a control meets the minimum touch-target height.
 *
 * @param {import('@playwright/test').Locator} control Interactive element.
 * @returns {Promise<void>} Resolves when the target meets the minimum height; rejects when geometry is unavailable or too short.
 */
async function expectMinimumTouchTarget(control) {
    const box = await control.boundingBox();

    expect(box.height).toBeGreaterThanOrEqual(MIN_TOUCH_TARGET_SIZE);
}

/** Verifies the mobile visit action is centered on its own row with an accessible QR icon.
 * @param {import('@playwright/test').Locator} header Authenticated application header.
 * @param {number} width Viewport width used for the centering assertion.
 * @returns {Promise<void>} Resolves when focus, touch-target, icon, and row checks pass; rejects when any assertion fails.
 */
async function expectCenteredMobileVisitAction(header, width) {
    const action = header.getByRole("link", {
        name: "Registrar visita",
        exact: true,
    });

    await expect(action).toBeVisible();
    await expect(action).toHaveAccessibleName("Registrar visita");
    await expectMinimumTouchTarget(action);
    await action.focus();
    await expect(action).toBeFocused();
    await expect(action).toHaveCSS("outline-color", COLORS.primaryLight);
    await expect(action).toHaveCSS("outline-width", "2px");
    await expect(action).toHaveCSS("outline-offset", "3px");

    const geometry = await action.evaluate((element) => {
        /** Converts an element's bounding rectangle to serializable coordinates.
         * @param {Element} node Element being measured.
         * @returns {{x: number, y: number, width: number, height: number}} Viewport-relative rectangle.
         */
        const rectangle = (node) => {
            const { x, y, width, height } = node.getBoundingClientRect();

            return { x, y, width, height };
        };
        const icon = element.querySelector("svg[data-flux-icon]");

        return {
            action: rectangle(element),
            logo: rectangle(
                element.closest("header").querySelector('a[aria-label="FidelitoPass"]'),
            ),
            navigation: rectangle(element.closest("header").querySelector("nav")),
            iconCount: element.querySelectorAll("svg[data-flux-icon]").length,
            iconHidden: icon?.getAttribute("aria-hidden"),
        };
    });

    expect(Math.abs(geometry.action.x + geometry.action.width / 2 - width / 2)).toBeLessThan(1);
    expect(geometry.action.y).toBeGreaterThan(geometry.logo.y + geometry.logo.height);
    expect(geometry.action.y + geometry.action.height).toBeLessThan(geometry.navigation.y);
    expect(geometry.iconCount).toBe(1);
    expect(geometry.iconHidden).toBe("true");
}

/** Verifies the desktop visit action remains inline between navigation and account controls.
 * @param {import('@playwright/test').Locator} header Authenticated application header.
 * @returns {Promise<void>} Resolves when desktop alignment checks pass; rejects when an alignment assertion fails.
 */
async function expectDesktopVisitActionRow(header) {
    const action = header.getByRole("link", {
        name: "Registrar visita",
        exact: true,
    });
    const geometry = await action.evaluate((element) => {
        /** Converts an element's bounding rectangle to serializable coordinates.
         * @param {Element} node Element being measured.
         * @returns {{x: number, y: number, width: number, height: number}} Viewport-relative rectangle.
         */
        const bounds = (node) => {
            const { x, y, width, height } = node.getBoundingClientRect();

            return { x, y, width, height };
        };
        const navigationLink = element.closest("header").querySelector("nav a");
        const account = element
            .closest("header")
            .querySelector('[data-test="sidebar-menu-button"]');

        return {
            action: bounds(element),
            navigation: bounds(navigationLink),
            account: bounds(account),
        };
    });

    expect(
        Math.abs(
            geometry.action.y +
                geometry.action.height / 2 -
                (geometry.navigation.y + geometry.navigation.height / 2),
        ),
    ).toBeLessThan(1);
    expect(geometry.action.x + geometry.action.width).toBeLessThanOrEqual(geometry.account.x);
}

/**
 * Registers a new business and verify its email address through Mailpit.
 *
 * @param {import('@playwright/test').Page} page Browser page.
 * @param {import('@playwright/test').APIRequestContext} request API request context.
 * @returns {Promise<void>} Leaves the page authenticated on the dashboard; rejects when signup, verification, or redirect checks fail.
 */
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

/**
 * Measures header layout, navigation geometry, typography, and visual properties.
 *
 * @param {import('@playwright/test').Page} page Browser page.
 * @param {boolean} mockup Whether to measure the reference mockup instead of the application.
 * @returns {Promise<ShellMeasurements>} Resolves with measured header geometry and styles; rejects when required shell elements cannot be measured.
 */
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

/**
 * Loads and measure the local reference mockup when available.
 *
 * @param {import('@playwright/test').Page} page Browser page.
 * @param {number} width Viewport width used to select navigation and name the screenshot.
 * @param {import('@playwright/test').TestInfo} testInfo Playwright test metadata and output paths.
 * @returns {Promise<ShellMeasurements|null>} Resolves with measurements or null when the mockup is unavailable; rejects when navigation, assertions, or screenshot capture fails.
 */
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

/**
 * Verifies the shared decorative mark preserves preview geometry and inherited ink.
 *
 * @param {import('@playwright/test').Page} page Browser page containing a pass preview.
 * @param {import('@playwright/test').TestInfo} testInfo Screenshot destination owner.
 * @param {string} context Preview context used to identify visual evidence.
 * @returns {Promise<void>} Resolves when geometry, decorative semantics and inherited colors match; rejects on a failed rendering assertion.
 */
async function expectPassBrandMark(page, testInfo, context) {
    const mark = page.locator(".app-pass-brand-mark").first();

    await expect(mark).toBeVisible();
    await mark.screenshot({ path: testInfo.outputPath(`${context}-brand-mark.png`) });
    await expect(mark).toHaveJSProperty("tagName", "svg");
    await expect(mark).toHaveAttribute("aria-hidden", "true");
    await expect(mark).toHaveAttribute("focusable", "false");
    await expect(mark).toHaveCSS("width", "32px");
    await expect(mark).toHaveCSS("height", "32px");

    const geometry = await mark.evaluate((element) => {
        const box = element.getBoundingClientRect();
        const card = element.closest("article");
        const outline = element.querySelector("rect");
        const dot = element.querySelector("circle");
        const shape = outline.getBBox();
        const circle = dot.getBBox();
        const transform = element.getScreenCTM();
        const center = new DOMPoint(22, 16.5).matrixTransform(transform);

        return {
            color: getComputedStyle(card).color,
            stroke: getComputedStyle(outline).stroke,
            fill: getComputedStyle(dot).fill,
            box: { x: box.x, y: box.y, width: box.width, height: box.height },
            center: { x: center.x, y: center.y },
            scale: transform.a,
            shape: { x: shape.x, y: shape.y, width: shape.width, height: shape.height },
            circle: { x: circle.x, y: circle.y, width: circle.width, height: circle.height },
            overflow: document.documentElement.scrollWidth > window.innerWidth,
        };
    });

    expect(geometry.stroke).toBe(geometry.color);
    expect(geometry.fill).toBe(geometry.color);
    expect(geometry.shape).toEqual({ x: 3, y: 3.5, width: 38, height: 26 });
    expect(geometry.circle).toEqual({ x: 17.5, y: 12, width: 9, height: 9 });
    expect(geometry.scale).toBeCloseTo(geometry.box.width / 44, 3);
    expect(geometry.center.x).toBeCloseTo(geometry.box.x + geometry.box.width / 2, 2);
    expect(geometry.center.y).toBeCloseTo(geometry.box.y + geometry.box.height / 2, 2);
    expect(geometry.box.width).toBeCloseTo(geometry.box.height, 1);
    expect(geometry.box.x).toBeGreaterThanOrEqual(0);
    expect(geometry.box.x + geometry.box.width).toBeLessThanOrEqual(page.viewportSize().width);
    expect(geometry.overflow).toBe(false);
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

        await page.goto("/");
        await expectPassBrandMark(page, testInfo, "landing");

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

            if (narrowWidth < 900) {
                await expectCenteredMobileVisitAction(header, narrowWidth);
            } else {
                await expectDesktopVisitActionRow(header);
            }
        }

        await page.setViewportSize({
            width,
            height: VIEWPORT_HEIGHT,
        });

        if (width < 900) {
            await expectCenteredMobileVisitAction(header, width);
        } else {
            await expectDesktopVisitActionRow(header);
        }

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

            if (path === "/pass") {
                await expectPassBrandMark(page, testInfo, "business-pass");
                await page.goto("/pass/appearance");
                await expectPassBrandMark(page, testInfo, "appearance-editor");
            }

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

        for (const [label, path] of ACCOUNT_DESTINATIONS) {
            const item = page.getByRole("menuitem", {
                name: label,
                exact: true,
            });

            await expect(item).toBeVisible();
            await expect(item).toBeEnabled();

            await item.press("Enter");

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

            await account.focus();
            await page.keyboard.press("Enter");

            await expect(
                page.getByRole("menuitem", {
                    name: label,
                    exact: true,
                }),
            ).toHaveAttribute("aria-current", "page");

            await expect(header.locator('[aria-current="page"]')).toHaveCount(1);
        }

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
