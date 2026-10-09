import { expect, test } from "@playwright/test";
import crypto from "node:crypto";

const USER_NAME = "Promotion Owner";
const BUSINESS_NAME = "Jaqaku";
const TIMEZONE = "America/La_Paz";
const PASSWORD = "ValidPassword84!strong";
const MAILPIT_URL = process.env.PLAYWRIGHT_MAILPIT_URL ?? "http://fidelitopass-mailpit-dev:8025";
const LIVEWIRE_UPDATE_PATH = /\/livewire-[^/]+\/update$/;

/** Shifts an ISO calendar date by whole days without host-timezone conversion.
 * @param {string} date ISO date in YYYY-MM-DD form.
 * @param {number} days Signed number of calendar days to shift.
 * @returns {string} Shifted ISO calendar date.
 */
function shiftCalendarDate(date, days) {
    const [year, month, day] = date.split("-").map(Number);
    const shifted = new Date(Date.UTC(year, month - 1, day + days));

    return shifted.toISOString().slice(0, 10);
}

/** Formats the textual validity summary, omitting the first year when both dates share it.
 * @param {string} start Promotion start date in YYYY-MM-DD form, or an empty value.
 * @param {string} end Promotion end date in YYYY-MM-DD form, or an empty value.
 * @returns {string} Spanish validity summary or its unconfigured placeholder.
 */
function expectedValidityRange(start, end) {
    if (!start || !end) return "Fechas por definir";

    const includeStartYear = start.slice(0, 4) !== end.slice(0, 4);
    /** Formats one ISO date with an optional year for the range summary.
     * @param {string} date Date in YYYY-MM-DD form.
     * @param {boolean} includeYear Whether to include the calendar year.
     * @returns {string} Localized Spanish date without abbreviation punctuation.
     */
    const formatDate = (date, includeYear) => {
        const [year, month, day] = date.split("-").map(Number);
        const calendarDate = new Date(Date.UTC(year, month - 1, day));
        const options = { day: "numeric", month: "short", timeZone: "UTC" };

        if (includeYear) options.year = "numeric";

        return new Intl.DateTimeFormat("es", options).format(calendarDate).replace(/\./g, "");
    };

    return `${formatDate(start, includeStartYear)} – ${formatDate(end, true)}`;
}

/** Derives stable date cases from the editor's server-provided minimum date.
 * @param {import('@playwright/test').Page} page Editor page exposing its start-date minimum.
 * @returns {Promise<{minimum: string, past: string, futureStart: string, futureEnd: string, sameYearStart: string, sameYearEnd: string, yearEnd: string, nextYear: string}>} Resolves with deterministic calendar cases; rejects if the editor has no server-provided minimum date.
 */
async function promotionDateCases(page) {
    const minimum = await page.locator("#local-start-date").getAttribute("min");
    const [year, month] = minimum.split("-").map(Number);
    const monthEnd = new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10);

    return {
        minimum,
        past: shiftCalendarDate(minimum, -1),
        futureStart: shiftCalendarDate(minimum, 1),
        futureEnd: shiftCalendarDate(minimum, 7),
        sameYearStart: minimum,
        sameYearEnd: monthEnd,
        yearEnd: `${year}-12-31`,
        nextYear: `${year + 1}-01-01`,
    };
}

/** Asserts that promotion routes select exactly the Pase navigation item.
 * @param {import('@playwright/test').Page} page Browser page currently showing a promotion route.
 * @returns {Promise<void>} Resolves when Pase is the single current page; rejects when the navigation assertions fail.
 */
async function expectPassNavigationToBeActive(page) {
    const navigation = page.getByRole("navigation", { name: "Navegación principal" });

    await expect(navigation.locator('a[aria-current="page"]')).toHaveCount(1);
    await expect(navigation.getByRole("link", { name: "Pase", exact: true })).toHaveAttribute(
        "aria-current",
        "page",
    );
}

/** Registers a disposable verified Business account for an isolated browser journey.
 * @param {import('@playwright/test').Page} page Browser page used for signup and verification.
 * @param {import('@playwright/test').APIRequestContext} request API client used to retrieve the verification message from Mailpit.
 * @returns {Promise<void>} Leaves the page authenticated on the dashboard; rejects when signup, verification, or redirect checks fail.
 */
async function registerVerifiedBusiness(page, request) {
    const recipient = `promotion-${crypto.randomUUID()}@example.test`;

    await page.goto("/register");
    await page.getByRole("textbox", { name: "Nombre", exact: true }).fill(USER_NAME);
    await page.getByLabel("Correo electrónico", { exact: true }).fill(recipient);
    await page.getByLabel("Nombre del negocio", { exact: true }).fill(BUSINESS_NAME);
    await page.getByLabel("Zona horaria", { exact: true }).selectOption(TIMEZONE);
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.locator('input[name="password_confirmation"]').fill(PASSWORD);
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

/** Reads computed typography for editor labels and the schedule legend.
 * @param {import('@playwright/test').Page} page Rendered Promotion editor page.
 * @returns {Promise<Record<string, {fontSize: string, lineHeight: string, fontWeight: string, color: string}>>} Resolves with computed label styles; rejects if a required label cannot be measured.
 */
async function readPromotionLabelStyles(page) {
    return page.evaluate(() => {
        const selectors = {
            reward: 'ui-label[for="reward-title"]',
            target: 'ui-label[for="target-points"]',
            startDate: 'ui-label[for="local-start-date"]',
            endDate: 'ui-label[for="local-end-date"]',
            weekday: 'ui-label[for="extra-weekday"]',
            multiplier: 'ui-label[for="extra-multiplier"]',
            schedule: "fieldset legend",
        };

        return Object.fromEntries(
            Object.entries(selectors).map(([name, selector]) => {
                const style = getComputedStyle(document.querySelector(selector));

                return [
                    name,
                    {
                        fontSize: style.fontSize,
                        lineHeight: style.lineHeight,
                        fontWeight: style.fontWeight,
                        color: style.color,
                    },
                ];
            }),
        );
    });
}

test("isolated owner inspects frozen Promotions, history and safe cancellation", async ({
    page,
}) => {
    test.skip(!process.env.PLAYWRIGHT_PROMOTION_FIXTURE, "Requires the isolated Promotion runner");
    test.setTimeout(90_000);
    const fixture = JSON.parse(process.env.PLAYWRIGHT_PROMOTION_FIXTURE);
    const consoleErrors = [];
    page.on("pageerror", (error) => consoleErrors.push(error.message));

    await page.goto("/login");
    await page.locator('input[name="email"]').fill(fixture.email);
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.getByRole("button", { name: "Iniciar sesión", exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto("/pass");
    await expectPassNavigationToBeActive(page);
    await page.evaluate(() => document.fonts.ready);

    const history = page.locator('details[wire\\:key="promotion-history-disclosure"]');
    const scheduled = page.locator('details[wire\\:key="scheduled-promotions-disclosure"]');
    const drafts = page.locator('details[wire\\:key="promotion-drafts-disclosure"]');
    const dialog = page.locator('dialog[data-modal="promotion-detail"]');
    const activeOpener = page.locator(`#promotion-detail-trigger-${fixture.promotions.Activa}`);
    await expect(history).not.toHaveAttribute("open", "");
    await activeOpener.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole("heading", { name: "Activa", exact: true })).toBeVisible();
    await expect(dialog.locator("dd").nth(1)).toContainText(
        `${fixture.startDate} – ${fixture.endDate}`,
    );
    await expect(dialog.locator("input, textarea, select")).toHaveCount(0);
    await expect(dialog.locator("header > span")).toHaveCSS("width", "64px");
    await expect(dialog.locator("header > span > svg")).toHaveCSS("width", "36px");
    const rules = dialog.locator("details[data-promotion-review-extra-rules]");
    await expect(rules).not.toHaveAttribute("open", "");
    const count = rules.locator("summary > span").last();
    await expect(count).toContainText("3 configuraciones");
    for (const value of [dialog.locator("dd").first(), dialog.locator("dd").nth(1), count]) {
        await expect(value).toHaveCSS("font-size", "16px");
        await expect(value).toHaveCSS("font-weight", "600");
    }
    await rules.locator("summary").click();
    await expect(rules.locator("li")).toHaveCount(3);
    for (const multiplier of ["×2", "×3", "×5"]) {
        await expect(rules.getByText(multiplier, { exact: true })).toBeVisible();
    }
    const requestCancellation = dialog.getByRole("button", {
        name: "Cancelar promoción",
        exact: true,
    });
    await expect(requestCancellation).toHaveCSS("background-color", "rgb(167, 123, 255)");
    await page.keyboard.press("Escape");
    await expect(dialog).not.toBeVisible();
    await expect(activeOpener).toBeFocused();

    await activeOpener.click();
    await requestCancellation.click();
    await expect(dialog.locator("#promotion-cancellation-heading")).toBeFocused();
    await expect(dialog.getByRole("button", { name: "Sí, cancelar promoción" })).toBeVisible();
    await dialog.getByRole("button", { name: "Cerrar ventana" }).click();
    await expect(activeOpener).toBeFocused();
    await activeOpener.click();
    await expect(dialog.getByRole("button", { name: "Sí, cancelar promoción" })).toHaveCount(0);
    await expect(dialog.locator("[data-promotion-detail-phase]")).toHaveAttribute(
        "data-promotion-detail-phase",
        "active",
    );
    await dialog.getByRole("button", { name: "Volver al pase" }).click();
    await expect(activeOpener).toBeFocused();

    // all lifecycle badges are measured on the actual authenticated page, not cloned markup.
    const badgeColors = {};
    for (const [title, phase] of [
        ["Activa", "active"],
        ["Programada 1", "scheduled"],
        ["Finalizada 1", "ended"],
        ["Cancelada 1", "cancelled"],
    ]) {
        if (phase === "ended") await history.locator("summary").click();
        const row = page.locator(`[data-promotion-public-id="${fixture.promotions[title]}"]`);
        const listBadge = row.locator("[data-flux-badge]");
        badgeColors[phase] = await listBadge.evaluate(
            (badge) => getComputedStyle(badge).backgroundColor,
        );
        await row.getByRole("button", { name: "Ver detalle" }).click();
        await expect(dialog.locator("[data-promotion-detail-phase]")).toHaveAttribute(
            "data-promotion-detail-phase",
            phase,
        );
        await expect(dialog.locator("header [data-flux-badge]")).toHaveCSS(
            "background-color",
            badgeColors[phase],
        );
        if (["ended", "cancelled"].includes(phase)) {
            await expect(
                dialog.getByRole("button", { name: "Cancelar promoción", exact: true }),
            ).toHaveCount(0);
        }
        await page.keyboard.press("Escape");
        await expect(row.getByRole("button", { name: "Ver detalle" })).toBeFocused();
    }
    expect(new Set(Object.values(badgeColors)).size).toBe(4);
    await expect(history.locator("li h4")).toHaveText([
        "Finalizada 1",
        "Finalizada 2",
        "Cancelada 1",
    ]);
    const historicalRow = history.locator("li").first();
    await expect(historicalRow.locator("h4")).toHaveCSS("font-size", "14px");
    await expect(historicalRow.locator("p")).toHaveCSS("font-size", "14px");
    await expect(historicalRow.locator("[data-flux-badge]")).toHaveCSS("font-size", "12px");
    await expect(historicalRow).not.toContainText("Condiciones originales");
    await expect(historicalRow).toHaveCSS("border-left-width", "0px");
    await expect(history.locator("ul")).toHaveCSS("border-left-width", "0px");
    const historicalAction = historicalRow.getByRole("button", { name: "Ver detalle" });
    await expect(historicalAction).toHaveCSS("font-size", "14px");
    await expect(historicalAction).toHaveCSS("color", "rgb(205, 176, 255)");
    await expect(historicalAction).toHaveCSS("cursor", "pointer");
    expect((await historicalAction.boundingBox()).height).toBeGreaterThanOrEqual(44);
    await expect(historicalAction).toHaveCSS("background-color", "rgba(0, 0, 0, 0)");
    await historicalAction.hover();
    await expect(historicalAction).toHaveCSS("background-color", "rgba(0, 0, 0, 0)");
    await historicalAction.focus();
    await expect(historicalAction).toHaveCSS("background-color", "rgba(0, 0, 0, 0)");
    await page.mouse.down();
    await expect(historicalAction).toHaveCSS("background-color", "rgba(0, 0, 0, 0)");
    await page.mouse.move(0, 0);
    await page.mouse.up();

    await history.getByRole("button", { name: /Siguiente/ }).click();
    await expect(history.locator("li h4")).toHaveText(["Cancelada 2"]);
    await expect(scheduled.locator("li h4")).toHaveText([
        "Programada 1",
        "Programada 2",
        "Programada 3",
    ]);
    await scheduled.getByRole("button", { name: /Siguiente/ }).click();
    await expect(scheduled.locator("li h4")).toHaveText(["Programada 4"]);
    await expect(history.locator("li h4")).toHaveText(["Cancelada 2"]);
    await drafts.getByRole("button", { name: /Siguiente/ }).click();
    await expect(drafts.locator("li h3")).toHaveText(["Borrador 4"]);
    await expect(scheduled.locator("li h4")).toHaveText(["Programada 4"]);
    await history.locator("summary").click();
    await scheduled.getByRole("button", { name: /Anterior/ }).click();
    await expect(history).not.toHaveAttribute("open", "");
    await expect(drafts.locator("li h3")).toHaveText(["Borrador 4"]);

    await page.goto("/pass");
    await page.setViewportSize({ width: 375, height: 667 });
    await activeOpener.click();
    await rules.locator("summary").click();
    await requestCancellation.click();
    const finalCancellation = dialog.getByRole("button", { name: "Sí, cancelar promoción" });
    await expect(finalCancellation).not.toHaveCSS("background-color", "rgb(167, 123, 255)");
    const overflow = await dialog.evaluate((element) => {
        const body = element.querySelector('[data-test="promotion-detail-scroll-body"]');
        const footer = element.querySelector("footer").getBoundingClientRect();
        const pageWidth = document.documentElement.clientWidth;
        const windowScroll = window.scrollY;
        body.scrollTop = body.scrollHeight;
        return {
            pageWidth,
            contentWidth: document.documentElement.scrollWidth,
            bodyScrollable: body.scrollHeight > body.clientHeight,
            footerBottom: footer.bottom,
            viewportHeight: innerHeight,
            windowScroll,
            afterScroll: window.scrollY,
        };
    });
    expect(overflow.contentWidth).toBeLessThanOrEqual(overflow.pageWidth);
    expect(overflow.bodyScrollable).toBe(true);
    expect(overflow.footerBottom).toBeLessThanOrEqual(overflow.viewportHeight);
    expect(overflow.afterScroll).toBe(overflow.windowScroll);
    await finalCancellation.click();
    await expect(dialog.locator("[data-promotion-detail-phase]")).toHaveAttribute(
        "data-promotion-detail-phase",
        "cancelled",
    );
    await expect(dialog.getByRole("heading", { name: "Activa", exact: true })).toBeVisible();
    await expect(dialog.locator("dd").nth(1)).toContainText(
        `${fixture.startDate} – ${fixture.endDate}`,
    );
    await page.keyboard.press("Escape");
    await expect(dialog).not.toBeVisible();
    await expect(page.locator("#promotions-heading")).toBeFocused();
    await expect(history).not.toHaveAttribute("open", "");

    // a second authenticated tab cancels after the first has armed its confirmation.
    await page.setViewportSize({ width: 1440, height: 1000 });
    const scheduledOpener = page.locator(
        `#promotion-detail-trigger-${fixture.promotions["Programada 1"]}`,
    );
    await scheduledOpener.click();
    await requestCancellation.click();
    const competingTab = await page.context().newPage();
    await competingTab.goto("/pass");
    await competingTab
        .locator(`#promotion-detail-trigger-${fixture.promotions["Programada 1"]}`)
        .click();
    const competingDialog = competingTab.locator('dialog[data-modal="promotion-detail"]');
    await competingDialog.getByRole("button", { name: "Cancelar promoción", exact: true }).click();
    await competingDialog.getByRole("button", { name: "Sí, cancelar promoción" }).click();
    await expect(competingDialog.locator("[data-promotion-detail-phase]")).toHaveAttribute(
        "data-promotion-detail-phase",
        "cancelled",
    );
    await finalCancellation.click();
    await expect(dialog.getByRole("alert")).toHaveText("Esta promoción ya fue cancelada.");
    await expect(dialog.locator("[data-promotion-detail-phase]")).toHaveAttribute(
        "data-promotion-detail-phase",
        "cancelled",
    );
    await competingTab.close();
    expect(consoleErrors).toEqual([]);
});

test("owner reviews and responsively renders a Promotion draft editor", async ({
    page,
    request,
}, testInfo) => {
    await registerVerifiedBusiness(page, request);
    await page.setViewportSize({ width: 1440, height: 1000 });

    await page.goto("/pass/appearance");
    await page.evaluate(() => document.fonts.ready);
    const passStyleMeasurements = await page.evaluate(() => {
        const header = document.querySelector("main > header");
        const identity = header.querySelector('p[class*="app-role-body"]');
        const heading = header.querySelector("h1");
        const intro = header.querySelector('[class~="app-role-intro!"]');
        return {
            identityColor: getComputedStyle(identity).color,
            heading: {
                fontSize: getComputedStyle(heading).fontSize,
                lineHeight: getComputedStyle(heading).lineHeight,
                fontWeight: getComputedStyle(heading).fontWeight,
            },
            intro: {
                fontSize: getComputedStyle(intro).fontSize,
                lineHeight: getComputedStyle(intro).lineHeight,
                fontWeight: getComputedStyle(intro).fontWeight,
            },
        };
    });
    await page.getByLabel("Código hexadecimal").fill("invalid");
    await page.getByRole("button", { name: "Guardar apariencia" }).click();
    await expect(page.getByRole("alert")).toBeVisible();
    const appearanceToast = page.locator("ui-toast-group > [data-flux-toast-dialog]");
    await expect(appearanceToast).toHaveCount(0);
    const passErrorColor = await page
        .getByRole("alert")
        .evaluate((element) => getComputedStyle(element).color);
    expect(passErrorColor).toBe("rgb(255, 180, 190)");
    await expect(page.getByRole("alert")).toHaveCSS("font-size", "14px");
    await expect(page.getByRole("alert")).toHaveCSS("line-height", "20px");
    const passHeaderSpacing = await page.evaluate(() => {
        const header = document.querySelector("main > header");
        const navigation = document.querySelector("[data-flux-header]");
        const backLink = Array.from(header.querySelectorAll("a, button")).find((link) =>
            link.textContent.includes("Volver al pase"),
        );
        const identity = header.querySelector('p[class*="app-role-body"]');

        return {
            navigationToBack:
                backLink.getBoundingClientRect().top - navigation.getBoundingClientRect().bottom,
            backToIdentity:
                identity.getBoundingClientRect().top - backLink.getBoundingClientRect().bottom,
        };
    });

    await page.getByLabel("Código hexadecimal").fill("#A77BFF");
    await page.getByRole("button", { name: "Guardar apariencia" }).click();
    await expect(page).toHaveURL(/\/pass$/);
    await expect(appearanceToast).toHaveCount(1);
    await expect(appearanceToast).toBeVisible();
    await expect(appearanceToast).toContainText("Apariencia guardada");
    await expect(page.getByRole("heading", { name: "Tu primera razón para volver" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Crear primera promoción" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Borradores", exact: true })).toHaveCount(0);
    await expect(page.getByRole("link", { name: "Nueva promoción" })).toHaveCount(0);

    await page.goto("/promotions/create");
    await expectPassNavigationToBeActive(page);
    const dateCases = await promotionDateCases(page);

    await expect(page.getByRole("heading", { name: "Nueva promoción" })).toBeVisible();
    await page.evaluate(() => document.fonts.ready);
    const promotionStyleMeasurements = await page.evaluate(() => {
        const header = document.querySelector("main > header");
        const identity = header.querySelector('p[class*="app-role-body"]');
        const heading = header.querySelector("h1");
        const intro = header.querySelector('[class~="app-role-intro!"]');
        return {
            identityColor: getComputedStyle(identity).color,
            heading: {
                fontSize: getComputedStyle(heading).fontSize,
                lineHeight: getComputedStyle(heading).lineHeight,
                fontWeight: getComputedStyle(heading).fontWeight,
            },
            intro: {
                fontSize: getComputedStyle(intro).fontSize,
                lineHeight: getComputedStyle(intro).lineHeight,
                fontWeight: getComputedStyle(intro).fontWeight,
            },
        };
    });
    expect(promotionStyleMeasurements).toEqual(passStyleMeasurements);
    const desktopLabelStyles = await readPromotionLabelStyles(page);
    for (const labelStyle of Object.values(desktopLabelStyles)) {
        expect(labelStyle).toEqual(desktopLabelStyles.reward);
    }
    const saveButton = page.getByRole("button", { name: "Guardar borrador" });
    const reviewButton = page.getByRole("button", { name: "Publicar promoción", exact: true });
    const addRuleButton = page.getByRole("button", { name: "Añadir puntos extra" });
    /** Reads semantic and computed style details for one editor action button.
     * @param {import('@playwright/test').Locator} button Button whose neutral or accent treatment is being compared.
     * @returns {Promise<{background: string, borderColor: string, color: string, minHeight: string, borderRadius: string, usesNeutralAppButton: boolean}>} Resolves with computed button styles; rejects if the locator is detached before measurement.
     */
    const measureButton = (button) =>
        button.evaluate((element) => {
            const style = getComputedStyle(element);

            return {
                background: style.backgroundColor,
                borderColor: style.borderColor,
                color: style.color,
                minHeight: style.minHeight,
                borderRadius: style.borderRadius,
                usesNeutralAppButton: element.classList.contains("app-button-secondary"),
            };
        });
    const [saveButtonStyle, reviewButtonStyle, addRuleButtonStyle] = await Promise.all([
        measureButton(saveButton),
        measureButton(reviewButton),
        measureButton(addRuleButton),
    ]);
    expect(saveButtonStyle.usesNeutralAppButton).toBe(true);
    expect(saveButtonStyle.background).toBe("rgb(56, 56, 56)");
    expect(saveButtonStyle.borderColor).toBe("rgb(130, 130, 130)");
    expect(saveButtonStyle.minHeight).toBe("44px");
    expect(saveButtonStyle.background).toBe(addRuleButtonStyle.background);
    expect(saveButtonStyle.color).toBe(addRuleButtonStyle.color);
    expect(saveButtonStyle.background).not.toBe(reviewButtonStyle.background);
    expect(saveButtonStyle.color).not.toBe(reviewButtonStyle.color);
    await saveButton.hover();
    await expect(saveButton).toHaveCSS("background-color", "rgb(68, 68, 68)");
    await page.mouse.down();
    await expect(saveButton).toHaveCSS("background-color", "rgb(68, 68, 68)");
    await page.mouse.up();
    const promotionHeaderSpacing = await page.evaluate(() => {
        const header = document.querySelector("main > header");
        const navigation = document.querySelector("[data-flux-header]");
        const backLink = Array.from(header.querySelectorAll("a")).find((link) =>
            link.textContent.includes("Volver al pase"),
        );
        const identity = header.querySelector('p[class*="app-role-body"]');

        return {
            navigationToBack:
                backLink.getBoundingClientRect().top - navigation.getBoundingClientRect().bottom,
            backToIdentity:
                identity.getBoundingClientRect().top - backLink.getBoundingClientRect().bottom,
        };
    });
    expect(
        Math.abs(promotionHeaderSpacing.navigationToBack - passHeaderSpacing.navigationToBack),
    ).toBeLessThan(1);
    expect(
        Math.abs(promotionHeaderSpacing.backToIdentity - passHeaderSpacing.backToIdentity),
    ).toBeLessThan(1);
    await expect(page.getByText(`Hora del negocio: ${TIMEZONE}`)).toHaveCount(0);
    await expect(
        page.getByText(
            "La fecha de fin incluye ese día completo. La recompensa también vence al terminar la promoción.",
        ),
    ).toBeVisible();
    await expect(page.locator("#reward-title")).toHaveAttribute("required", "required");
    await expect(page.locator("#target-points")).toHaveAttribute("required", "required");
    await expect(page.locator("#local-start-date")).toHaveAttribute("required", "required");
    await expect(page.locator("#local-end-date")).toHaveAttribute("required", "required");
    await expect(page.locator('[data-test="promotion-editor"] form')).toHaveAttribute(
        "novalidate",
        "",
    );
    await expect(
        page.getByText("* ¿Qué recompensa recibirá tu cliente?", { exact: true }),
    ).toBeVisible();
    await expect(page.getByText("* ¿Cuántos puntos necesita?", { exact: true })).toBeVisible();
    await expect(page.getByText("opcional", { exact: true }).first()).toBeVisible();
    await expect(
        page.getByText("Haz que ciertas visitas sumen más.", { exact: true }),
    ).toBeVisible();
    const requiredLabelGaps = await page.evaluate(() =>
        ["reward-title", "target-points", "local-start-date", "local-end-date"].map((id) => {
            const label = document.querySelector(`ui-label[for="${id}"]`);
            const marker = label.querySelector('[aria-hidden="true"]');
            const labelText = Array.from(label.childNodes).find(
                (node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim(),
            );
            const textRange = document.createRange();
            textRange.selectNodeContents(labelText);

            return textRange.getBoundingClientRect().left - marker.getBoundingClientRect().right;
        }),
    );
    for (const gap of requiredLabelGaps) {
        expect(gap).toBeGreaterThanOrEqual(4);
    }
    const optionalBadgeColors = await page
        .locator("[data-flux-badge]")
        .filter({ hasText: "opcional" })
        .first()
        .evaluate((badge) => {
            const section = badge.closest("section");
            const style = getComputedStyle(badge);
            const context = document.createElement("canvas").getContext("2d");
            /** Resolves a CSS color to its RGB channel values through canvas.
             * @param {string} color CSS color string.
             * @returns {number[]} Three RGB channel values.
             */
            const pixelFor = (color) => {
                context.clearRect(0, 0, 1, 1);
                context.fillStyle = color;
                context.fillRect(0, 0, 1, 1);

                return Array.from(context.getImageData(0, 0, 1, 1).data).slice(0, 3);
            };
            /** Calculates relative luminance from three RGB channels.
             * @param {[number, number, number]} channels Red, green, and blue values from 0 to 255.
             * @returns {number} WCAG relative luminance for the supplied color.
             */
            const luminance = ([r, g, b]) =>
                [r, g, b]
                    .map((channel) => channel / 255)
                    .map((channel) =>
                        channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4,
                    )
                    .reduce(
                        (total, channel, index) =>
                            total + channel * [0.2126, 0.7152, 0.0722][index],
                        0,
                    );
            context.fillStyle = getComputedStyle(section).backgroundColor;
            context.fillRect(0, 0, 1, 1);
            context.fillStyle = style.backgroundColor;
            context.fillRect(0, 0, 1, 1);
            const background = Array.from(context.getImageData(0, 0, 1, 1).data).slice(0, 3);
            const foreground = pixelFor(style.color);
            const foregroundLuminance = luminance(foreground);
            const backgroundLuminance = luminance(background);
            const contrast =
                (Math.max(foregroundLuminance, backgroundLuminance) + 0.05) /
                (Math.min(foregroundLuminance, backgroundLuminance) + 0.05);

            return {
                background: style.backgroundColor,
                foreground: style.color,
                surface: getComputedStyle(section).backgroundColor,
                contrast,
            };
        });
    expect(optionalBadgeColors.background).not.toBe("rgba(0, 0, 0, 0)");
    expect(optionalBadgeColors.contrast).toBeGreaterThanOrEqual(4.5);
    await expect(reviewButton).toBeEnabled();
    await reviewButton.click();
    await expect(
        page.getByText("El campo recompensa es obligatorio.", { exact: true }),
    ).toBeVisible();
    await expect(
        page.locator('dialog[data-modal="promotion-publication-review"]'),
    ).not.toBeVisible();
    await expect(page.getByText("La publicación no está disponible en esta versión.")).toHaveCount(
        0,
    );
    await expect(
        page.getByText("Sin un horario especial, cada visita suma 1 punto.", { exact: true }),
    ).toHaveCount(0);
    await expect(page.getByText(/Se repite cada semana/)).toHaveCount(0);
    const windowHelp = page.getByText(
        "Los puntos indicados son el total por visita, no puntos que se agregan al punto habitual.",
        { exact: true },
    );
    await expect(windowHelp).toBeVisible();
    await expect(windowHelp.locator("xpath=..").locator("svg")).toBeVisible();

    /** Measures rule-builder labels, controls, and add action relative to their panel.
     * @returns {Promise<{weekdayLabel: number, multiplierLabel: number, weekdayControl: number, multiplierControl: number, addButton: number}>} Resolves with panel-relative coordinates; rejects if required controls cannot be measured.
     */
    const fieldTops = async () =>
        page.evaluate(() => {
            const section = document
                .querySelector("#promotion-extra-points-heading")
                .closest("section");
            /** Gets a visible element's top coordinate relative to the extra-points panel.
             * @param {string} selector CSS selector for an element in the panel.
             * @returns {number} Panel-relative top coordinate in CSS pixels.
             */
            const top = (selector) =>
                document.querySelector(selector).getBoundingClientRect().top -
                section.getBoundingClientRect().top;

            return {
                weekdayLabel: top('ui-label[for="extra-weekday"]'),
                multiplierLabel: top('ui-label[for="extra-multiplier"]'),
                weekdayControl: top("#extra-weekday"),
                multiplierControl: top("#extra-multiplier"),
                addButton:
                    Array.from(document.querySelectorAll("button[data-flux-button]"))
                        .find((button) => button.textContent.includes("Añadir puntos extra"))
                        .getBoundingClientRect().top - section.getBoundingClientRect().top,
            };
        });
    const initialFieldTops = await fieldTops();
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await expect(
        page.getByText("Elige el día para los puntos extra.", { exact: true }),
    ).toBeVisible();
    const invalidFieldTops = await fieldTops();
    expect(Math.abs(invalidFieldTops.weekdayLabel - invalidFieldTops.multiplierLabel)).toBeLessThan(
        1,
    );
    expect(
        Math.abs(invalidFieldTops.weekdayControl - invalidFieldTops.multiplierControl),
    ).toBeLessThan(1);
    expect(Math.abs(invalidFieldTops.weekdayLabel - initialFieldTops.weekdayLabel)).toBeLessThan(1);
    expect(
        Math.abs(invalidFieldTops.multiplierControl - initialFieldTops.multiplierControl),
    ).toBeLessThan(1);
    expect(invalidFieldTops.addButton).toBeGreaterThan(initialFieldTops.addButton);

    await page.getByRole("button", { name: "Guardar borrador" }).click();
    const promotionErrorColor = await page
        .getByText("El campo recompensa es obligatorio.", { exact: true })
        .evaluate((element) => getComputedStyle(element).color);
    expect(promotionErrorColor).toBe(passErrorColor);
    await expect(page.getByText("El campo recompensa es obligatorio.", { exact: true })).toHaveCSS(
        "font-size",
        "14px",
    );
    await expect(page.getByText("El campo recompensa es obligatorio.", { exact: true })).toHaveCSS(
        "line-height",
        "20px",
    );
    await expect(page.getByRole("heading", { name: "Información general" })).toHaveCSS(
        "font-weight",
        "600",
    );
    await expect(
        page
            .getByText("Revisa los campos marcados y corrige los datos antes de continuar.", {
                exact: true,
            })
            .last(),
    ).toBeVisible();
    await expect(
        page.getByText("El campo meta de puntos es obligatorio.", { exact: true }),
    ).toBeVisible();
    expect(promotionErrorColor).toBe("rgb(255, 180, 190)");

    await page.locator("ui-toast-group ui-close button").evaluateAll((buttons) => {
        buttons.forEach((button) => button.click());
    });

    await page.getByRole("link", { name: "Volver al pase" }).focus();
    await page.keyboard.press("Tab");

    const rewardTitle = page.getByLabel("¿Qué recompensa recibirá tu cliente?");
    await expect(rewardTitle).toBeFocused();

    const focusedField = await rewardTitle.evaluate((element) => ({
        outlineColor: getComputedStyle(element).outlineColor,
        outlineWidth: getComputedStyle(element).outlineWidth,
        outlineOffset: getComputedStyle(element).outlineOffset,
    }));

    await page.route(LIVEWIRE_UPDATE_PATH, (route) => route.abort());
    await rewardTitle.fill("Café de cortesía");
    const summary = page.locator('[data-test="promotion-summary"]');
    await expect(summary.locator('[data-test="summary-reward-title"]')).toHaveText(
        "Café de cortesía",
    );
    await page.getByLabel("Detalle de la recompensa").fill("Café americano mediano.");
    await expect(summary.locator('[data-test="summary-reward-description"]')).toHaveText(
        "Café americano mediano.",
    );
    await page.getByLabel("¿Cuántos puntos necesita?").fill("12");
    await expect(summary.locator('[data-test="summary-target"]')).toHaveText("12 puntos");
    await expect(summary.locator('[data-test="summary-validity"]')).toHaveText(
        "Fechas por definir",
    );
    await page.getByLabel("Fecha de inicio").fill(dateCases.sameYearStart);
    await expect(summary.locator('[data-test="summary-validity"]')).toHaveText(
        "Fechas por definir",
    );
    await page.getByLabel("Fecha de fin").fill(dateCases.sameYearEnd);
    await expect(summary.locator('[data-test="summary-validity"]')).toHaveText(
        expectedValidityRange(dateCases.sameYearStart, dateCases.sameYearEnd),
    );
    await page.getByLabel("Fecha de inicio").fill(dateCases.yearEnd);
    await page.getByLabel("Fecha de fin").fill(dateCases.nextYear);
    await expect(summary.locator('[data-test="summary-validity"]')).toHaveText(
        expectedValidityRange(dateCases.yearEnd, dateCases.nextYear),
    );
    await page.getByLabel("Fecha de inicio").fill("");
    await expect(summary.locator('[data-test="summary-validity"]')).toHaveText(
        "Fechas por definir",
    );
    await page.getByLabel("Fecha de fin").fill("");
    await page.getByLabel("Fecha de fin").fill(dateCases.sameYearEnd);
    await expect(summary.locator('[data-test="summary-validity"]')).toHaveText(
        "Fechas por definir",
    );
    await page.getByLabel("Fecha de inicio").fill(dateCases.futureStart);
    await page.getByLabel("Fecha de fin").fill(dateCases.futureEnd);
    await page.unroute(LIVEWIRE_UPDATE_PATH);
    await expect(page.getByText("Café de cortesía", { exact: true })).toBeVisible();
    await page.getByLabel("Día", { exact: true }).selectOption("3");
    await page.getByLabel("Puntos por visita", { exact: true }).selectOption("3");
    await page.getByLabel("Por horario", { exact: true }).check();
    await expect(page.getByLabel("Hora de inicio")).toBeVisible();
    /** Measures timed-window fields in the extra-points panel's local coordinate frame.
     * @returns {Promise<{startLabel: number, endLabel: number, startInput: number, endInput: number, addButton: number}>} Resolves with panel-relative coordinates; rejects if required controls cannot be measured.
     */
    const timeFieldTops = async () =>
        page.evaluate(() => {
            const section = document
                .querySelector("#promotion-extra-points-heading")
                .closest("section");
            /** Gets an element's top coordinate relative to the extra-points panel.
             * @param {string} selector CSS selector for a panel element.
             * @returns {number} Panel-relative top coordinate in CSS pixels.
             */
            const top = (selector) =>
                document.querySelector(selector).getBoundingClientRect().top -
                section.getBoundingClientRect().top;

            return {
                startLabel: top('ui-label[for="extra-start-time"]'),
                endLabel: top('ui-label[for="extra-end-time"]'),
                startInput: top("#extra-start-time"),
                endInput: top("#extra-end-time"),
                addButton:
                    Array.from(document.querySelectorAll("button[data-flux-button]"))
                        .find((button) => button.textContent.includes("Añadir puntos extra"))
                        .getBoundingClientRect().top - section.getBoundingClientRect().top,
            };
        });
    const timeTopsBeforeError = await timeFieldTops();
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await expect(
        page.getByText("El campo hora de inicio es obligatorio.", { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText("El campo hora de fin es obligatorio.", { exact: true }),
    ).toBeVisible();
    const timeTopsAfterError = await timeFieldTops();
    expect(Math.abs(timeTopsAfterError.startLabel - timeTopsAfterError.endLabel)).toBeLessThan(1);
    expect(Math.abs(timeTopsAfterError.startInput - timeTopsAfterError.endInput)).toBeLessThan(1);
    expect(Math.abs(timeTopsAfterError.startLabel - timeTopsBeforeError.startLabel)).toBeLessThan(
        1,
    );
    expect(timeTopsAfterError.addButton).toBeGreaterThan(timeTopsBeforeError.addButton);
    await page.getByLabel("Hora de inicio").fill("09:00");
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await expect(
        page.getByText("El campo hora de fin es obligatorio.", { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText("El campo hora de inicio es obligatorio.", { exact: true }),
    ).toHaveCount(0);
    await page.getByLabel("Hora de inicio").fill("");
    await page.getByLabel("Hora de fin").fill("12:00");
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await expect(
        page.getByText("El campo hora de inicio es obligatorio.", { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText("El campo hora de fin es obligatorio.", { exact: true }),
    ).toHaveCount(0);
    await page.getByLabel("Hora de inicio").fill("09:00");
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await expect(page.getByText("Entrada sin añadir", { exact: true })).toHaveCount(0);
    await expect(page.getByText("La regla se repetirá cada semana.", { exact: true })).toHaveCount(
        0,
    );
    await expect(page.getByRole("button", { name: "Descartar entrada" })).toHaveCount(0);
    await expect(page.locator('[data-test="promotion-summary"]')).not.toContainText("En edición");
    await expect(summary.locator('[data-test="summary-validity"]')).toHaveText(
        expectedValidityRange(dateCases.futureStart, dateCases.futureEnd),
    );
    await expect(
        page.getByText("Todavía no hay puntos extra configurados.", { exact: true }),
    ).toHaveCount(0);
    const addRuleLayout = await addRuleButton.evaluate((button) => {
        const icon = Array.from(button.querySelectorAll("svg"))
            .find((svg) => !svg.closest("[data-flux-loading-indicator]"))
            .getBoundingClientRect();
        const label = Array.from(button.querySelectorAll("span"))
            .find((span) => span.textContent.includes("Añadir puntos extra"))
            .getBoundingClientRect();

        return {
            horizontal: icon.right <= label.left,
            verticalDifference: Math.abs(icon.y + icon.height / 2 - (label.y + label.height / 2)),
        };
    });
    expect(addRuleLayout.horizontal).toBe(true);
    expect(addRuleLayout.verticalDifference).toBeLessThan(3);
    await addRuleButton.click();

    const acceptedRule = page
        .getByRole("list", { name: "Reglas de puntos extra añadidas" })
        .locator("li")
        .first();
    await expect(page.getByLabel("Día", { exact: true })).toHaveValue("");
    await expect(acceptedRule.getByText("Miércoles", { exact: true })).toBeVisible();
    await expect(acceptedRule.getByText("09:00–12:00", { exact: true })).toBeVisible();
    await expect(acceptedRule.getByText("3 puntos · ×3", { exact: true })).toBeVisible();
    const pointsBadge = await acceptedRule.locator("[data-flux-badge]").evaluate((badge) => {
        const style = getComputedStyle(badge);
        const { x, y, width, height } = badge.getBoundingClientRect();

        return {
            background: style.backgroundColor,
            foreground: style.color,
            bounds: { x, y, width, height },
        };
    });
    expect(pointsBadge.background).not.toBe("rgba(0, 0, 0, 0)");
    await expect(page.getByText("Café de cortesía", { exact: true })).toBeVisible();

    await page.getByLabel("Día", { exact: true }).selectOption("3");
    await expect(page.getByLabel("Día", { exact: true })).toHaveValue("3");
    await page.getByLabel("Todo el día", { exact: true }).check();
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await expect(
        page.getByText(
            "No se puede combinar una regla de día completo con otros horarios en el mismo día.",
        ),
    ).toBeVisible();
    await expect(
        page.getByText("Revisa los campos marcados y corrige los datos antes de continuar.").last(),
    ).toBeVisible();
    await expect(page.getByLabel("Día", { exact: true })).toHaveValue("3");
    await expect(page.getByText("Entrada sin añadir", { exact: true })).toHaveCount(0);
    await page.getByLabel("Puntos por visita", { exact: true }).selectOption("5");
    await expect(
        page.getByText(
            "No se puede combinar una regla de día completo con otros horarios en el mismo día.",
        ),
    ).toBeVisible();
    await page.getByLabel("Puntos por visita", { exact: true }).selectOption("2");
    await expect(
        page.getByText(
            "No se puede combinar una regla de día completo con otros horarios en el mismo día.",
        ),
    ).toBeVisible();

    await page.getByRole("button", { name: "Quitar la regla de Miércoles" }).first().click();
    await page.getByLabel("Día", { exact: true }).selectOption("3");
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    const allDayRule = page
        .getByRole("list", { name: "Reglas de puntos extra añadidas" })
        .locator("li")
        .first();
    await expect(allDayRule.getByText("Miércoles", { exact: true })).toBeVisible();
    await expect(allDayRule.getByText("Todo el día", { exact: true })).toBeVisible();
    await expect(allDayRule.getByText("2 puntos · ×2", { exact: true })).toBeVisible();
    await page.getByLabel("Por horario", { exact: true }).check();
    await page.getByLabel("Día", { exact: true }).selectOption("3");
    await page.getByLabel("Hora de inicio").fill("09:00");
    await page.getByLabel("Hora de fin").fill("12:00");
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await expect(
        page.getByText(
            "No se puede combinar una regla de día completo con otros horarios en el mismo día.",
        ),
    ).toBeVisible();
    await expect(page.getByLabel("Hora de inicio")).toHaveValue("09:00");
    await expect(page.getByLabel("Hora de fin")).toHaveValue("12:00");
    await expect(page.getByText("Entrada sin añadir", { exact: true })).toHaveCount(0);
    await page.getByRole("button", { name: "Quitar la regla de Miércoles" }).first().click();

    await page.getByLabel("Por horario", { exact: true }).check();
    await page.getByLabel("Día", { exact: true }).selectOption("3");
    await page.getByLabel("Hora de inicio").fill("09:00");
    await page.getByLabel("Hora de fin").fill("10:00");
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await expect(page.getByText("09:00–10:00", { exact: true })).toBeVisible();
    await page.getByLabel("Por horario", { exact: true }).check();
    await page.getByLabel("Día", { exact: true }).selectOption("3");
    await page.getByLabel("Hora de inicio").fill("10:00");
    await page.getByLabel("Hora de fin").fill("11:00");
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await expect(page.getByText("10:00–11:00", { exact: true })).toBeVisible();
    await page.getByLabel("Por horario", { exact: true }).check();
    await page.getByLabel("Día", { exact: true }).selectOption("3");
    await page.getByLabel("Hora de inicio").fill("10:30");
    await page.getByLabel("Hora de fin").fill("11:30");
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await expect(
        page.getByText("Los horarios de puntos extra no pueden superponerse."),
    ).toBeVisible();
    await expect(page.getByLabel("Hora de inicio")).toHaveValue("10:30");
    await expect(page.getByLabel("Hora de fin")).toHaveValue("11:30");

    const controlBackgrounds = await page.evaluate(() => ({
        required: getComputedStyle(document.getElementById("reward-title")).backgroundColor,
        optional: getComputedStyle(document.getElementById("reward-description")).backgroundColor,
    }));
    expect(controlBackgrounds.required).toBe(controlBackgrounds.optional);

    await page.getByRole("button", { name: "Guardar borrador" }).click();
    await expect(page.getByText("Completa la regla antes de guardar.")).toBeVisible();
    await expect(
        page.getByText("Revisa los campos marcados y corrige los datos antes de continuar.").last(),
    ).toBeVisible();
    await page.getByLabel("Hora de inicio").fill("");
    await page.getByLabel("Hora de fin").fill("");
    await page.getByLabel("Día", { exact: true }).selectOption("");
    await page.getByLabel("Todo el día", { exact: true }).check();

    const helperWidths = await page.evaluate(() => ({
        input: document.getElementById("target-points").getBoundingClientRect().width,
        helper: document.getElementById("target-points-help").getBoundingClientRect().width,
    }));
    expect(helperWidths.helper).toBeGreaterThan(helperWidths.input * 1.5);
    await page.getByRole("button", { name: "Cancelar", exact: true }).click();
    await expect(page.getByText("Los cambios que no guardaste se perderán.")).toBeVisible();
    await page.getByRole("button", { name: "Seguir editando" }).click();
    await expect(page.getByText("Los cambios que no guardaste se perderán.")).toBeHidden();
    await expect(page.getByRole("heading", { name: "Nueva promoción" })).toBeVisible();
    await expect(page.getByText("2 configuraciones", { exact: true })).toBeVisible();

    const draftWeekday = page.getByLabel("Día", { exact: true });
    await draftWeekday.selectOption("7");
    await expect(draftWeekday).toHaveValue("7");
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await expect(
        page.getByRole("list", { name: "Reglas de puntos extra añadidas" }).locator("li"),
    ).toHaveCount(3);
    await draftWeekday.selectOption("1");
    await expect(draftWeekday).toHaveValue("1");
    await page.getByLabel("Por horario", { exact: true }).check();
    await expect(page.getByLabel("Por horario", { exact: true })).toBeChecked();
    await page.getByLabel("Hora de inicio").fill("16:00");
    await page.getByLabel("Hora de fin").fill("17:00");
    await expect(page.getByLabel("Hora de inicio")).toHaveValue("16:00");
    await expect(page.getByLabel("Hora de fin")).toHaveValue("17:00");
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await expect(
        page.getByRole("list", { name: "Reglas de puntos extra añadidas" }).locator("li"),
    ).toHaveCount(4);
    await draftWeekday.selectOption("1");
    await expect(draftWeekday).toHaveValue("1");
    await page.getByLabel("Por horario", { exact: true }).check();
    await page.getByLabel("Hora de inicio").fill("09:00");
    await page.getByLabel("Hora de fin").fill("10:00");
    await expect(page.getByLabel("Hora de inicio")).toHaveValue("09:00");
    await expect(page.getByLabel("Hora de fin")).toHaveValue("10:00");
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    const sortedRows = page
        .getByRole("list", { name: "Reglas de puntos extra añadidas" })
        .locator("li");
    await expect(sortedRows).toHaveCount(5);
    await expect(sortedRows.nth(0).getByText("Lunes", { exact: true })).toBeVisible();
    await expect(sortedRows.nth(0).getByText("09:00–10:00", { exact: true })).toBeVisible();
    await expect(sortedRows.nth(1).getByText("16:00–17:00", { exact: true })).toBeVisible();
    await expect(sortedRows.nth(2).getByText("Miércoles", { exact: true })).toBeVisible();
    await expect(sortedRows.nth(4).getByText("Domingo", { exact: true })).toBeVisible();
    await sortedRows.nth(1).getByRole("button", { name: "Quitar la regla de Lunes" }).click();
    await expect(sortedRows).toHaveCount(4);
    await expect(page.getByText("16:00–17:00", { exact: true })).toHaveCount(0);
    await expect(sortedRows.nth(1).getByText("Miércoles", { exact: true })).toBeVisible();

    await page.evaluate(() => window.scrollTo(0, 0));
    await page.locator("ui-toast-group ui-close button").evaluateAll((buttons) => {
        buttons.forEach((button) => button.click());
    });
    const toastDialogs = page.locator("ui-toast-group > [data-flux-toast-dialog]");
    await expect(toastDialogs).toHaveCount(0);
    await page.getByRole("button", { name: "Guardar borrador" }).click();
    await expect(page).toHaveURL(/\/pass$/);
    const successToast = page.locator("ui-toast-group > [data-flux-toast-dialog]");
    await expect(successToast).toHaveCount(1);
    await expect(successToast).toBeVisible();
    await expect(successToast).toContainText("Borrador de promoción guardado.");
    await page.locator("ui-toast-group ui-close button").evaluateAll((buttons) => {
        buttons.forEach((button) => button.click());
    });
    await page.goto("/pass");
    await expect(toastDialogs).toHaveCount(0);

    const savedDraft = page
        .locator("[data-promotion-public-id]")
        .filter({ hasText: "Café de cortesía" });
    const savedDraftCard = await savedDraft.evaluate((card) => ({
        padding: getComputedStyle(card).padding,
        listGapClass: card.parentElement.classList.contains("space-y-4"),
    }));
    expect(savedDraftCard.padding).toBe("24px");
    expect(savedDraftCard.listGapClass).toBe(true);
    await expect(savedDraft).toContainText("Borrador");
    await expect(savedDraft).toContainText("12 puntos para obtener la recompensa");
    await expect(page.getByRole("heading", { name: "Borradores (1)", exact: true })).toBeVisible();
    const newPromotionLink = page.getByRole("link", { name: "Nueva promoción" });
    await expect(newPromotionLink).toBeVisible();
    await expect(page.getByRole("heading", { name: "Tu primera razón para volver" })).toHaveCount(
        0,
    );
    await expect(page.getByRole("link", { name: "Crear primera promoción" })).toHaveCount(0);
    await page.screenshot({ path: testInfo.outputPath("pass-drafts-desktop.png"), fullPage: true });

    await page.setViewportSize({ width: 375, height: 812 });
    await expect(newPromotionLink).toBeVisible();
    const draftListMobile = await page.evaluate(() => ({
        documentWidth: document.documentElement.scrollWidth,
        viewportWidth: window.innerWidth,
    }));
    expect(draftListMobile.documentWidth).toBeLessThanOrEqual(draftListMobile.viewportWidth);
    await expect(newPromotionLink).toHaveCSS("min-height", "44px");
    await page.screenshot({ path: testInfo.outputPath("pass-drafts-mobile.png"), fullPage: true });
    await page.setViewportSize({ width: 1440, height: 1000 });

    await page.goto("/promotions/create");
    await page.getByLabel("¿Qué recompensa recibirá tu cliente?").fill("Segunda promoción");
    await page.getByLabel("¿Cuántos puntos necesita?").fill("9");
    await page.getByLabel("Fecha de inicio").fill(dateCases.futureStart);
    await page.getByLabel("Fecha de fin").fill(dateCases.futureEnd);
    await page.getByRole("button", { name: "Guardar borrador" }).click();
    await expect(page).toHaveURL(/\/pass$/);
    await page.locator("ui-toast-group ui-close button").evaluateAll((buttons) => {
        buttons.forEach((button) => button.click());
    });
    const draftCards = page.locator("[data-promotion-public-id]");
    await expect(draftCards).toHaveCount(2);
    const desktopDraftSpacing = await draftCards.evaluateAll(([first, second]) => {
        const firstBounds = first.getBoundingClientRect();
        const secondBounds = second.getBoundingClientRect();

        return {
            padding: getComputedStyle(first).padding,
            gap: secondBounds.top - firstBounds.bottom,
        };
    });
    expect(desktopDraftSpacing.padding).toBe("24px");
    expect(desktopDraftSpacing.gap).toBe(16);
    const passEditLink = savedDraft.getByRole("link", { name: "Editar borrador" });
    await expect(passEditLink).toHaveCSS("background-color", "rgb(56, 56, 56)");
    await expect(passEditLink).toHaveCSS("border-color", "rgb(130, 130, 130)");
    await passEditLink.hover();
    await expect(passEditLink).toHaveCSS("background-color", "rgb(68, 68, 68)");
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(draftCards.first()).toHaveCSS("padding", "16px");
    await page.setViewportSize({ width: 1440, height: 1000 });

    await savedDraft.getByRole("link", { name: "Editar borrador" }).click();
    await expect(page).toHaveURL(/\/promotions\/[0-9a-f-]+\/edit$/);
    await expectPassNavigationToBeActive(page);
    await expect(page.getByLabel("¿Qué recompensa recibirá tu cliente?")).toHaveValue(
        "Café de cortesía",
    );

    await page.reload();
    await expect(page.getByLabel("¿Qué recompensa recibirá tu cliente?")).toHaveValue(
        "Café de cortesía",
    );
    await expect(page.getByLabel("Detalle de la recompensa")).toHaveValue(
        "Café americano mediano.",
    );
    await expect(page.getByLabel("¿Cuántos puntos necesita?")).toHaveValue("12");
    await expect(page.getByText("4 configuraciones", { exact: true })).toBeVisible();

    const desktopMeasurements = await page.evaluate(() => {
        const editor = document.querySelector('[data-test="promotion-editor"]');
        const general = document.querySelector("#promotion-general-heading").closest("section");
        const summary = document.querySelector("#promotion-summary-heading").closest("section");
        /** Returns a DOM element's serializable rectangle coordinates.
         * @param {Element} element Measured editor element.
         * @returns {{x: number, y: number, width: number, height: number}} Viewport-relative bounding box.
         */
        const bounds = (element) => {
            const { x, y, width, height } = element.getBoundingClientRect();

            return { x, y, width, height };
        };

        return {
            viewport: { width: innerWidth, height: innerHeight },
            pageWidth: editor.getBoundingClientRect().width,
            overflow: document.documentElement.scrollWidth > innerWidth,
            font: getComputedStyle(document.body).fontFamily,
            fontLoaded: document.fonts.check('16px "Onest Variable"'),
            topPadding: getComputedStyle(editor).paddingTop,
            headingSize: getComputedStyle(document.querySelector("h1")).fontSize,
            generalBackground: getComputedStyle(general).backgroundColor,
            generalBorder: getComputedStyle(general).borderTopColor,
            general: bounds(general),
            summary: bounds(summary),
        };
    });

    expect(desktopMeasurements.overflow).toBe(false);
    expect(desktopMeasurements.fontLoaded).toBe(true);
    expect(desktopMeasurements.generalBackground).toBe("rgb(61, 46, 85)");
    expect(desktopMeasurements.generalBorder).toBe("rgb(132, 101, 172)");
    await page.screenshot({ path: testInfo.outputPath("promotion-desktop.png"), fullPage: true });

    await page.setViewportSize({ width: 375, height: 812 });
    await expect(page.getByRole("heading", { name: "Editar borrador" })).toBeVisible();
    const mobileLabelStyles = await readPromotionLabelStyles(page);
    for (const labelStyle of Object.values(mobileLabelStyles)) {
        expect(labelStyle).toEqual(mobileLabelStyles.reward);
    }

    const mobileMeasurements = await page.evaluate(() => ({
        viewport: { width: innerWidth, height: innerHeight },
        documentWidth: document.documentElement.scrollWidth,
        overflow: document.documentElement.scrollWidth > innerWidth,
        editorWidth: document
            .querySelector('[data-test="promotion-editor"]')
            .getBoundingClientRect().width,
    }));

    expect(mobileMeasurements.overflow).toBe(false);
    await page.screenshot({ path: testInfo.outputPath("promotion-mobile.png"), fullPage: true });

    await page.setViewportSize({ width: 1440, height: 1000 });

    const existingRewardTitle = page.getByLabel("¿Qué recompensa recibirá tu cliente?");
    await existingRewardTitle.fill("Cambio temporal");
    await expect(page.getByRole("button", { name: "Volver al pase" })).toBeVisible();
    await page.getByRole("button", { name: "Volver al pase" }).click();
    await expect(page.getByText("Los cambios que no guardaste se perderán.")).toBeVisible();
    await page.getByRole("button", { name: "Seguir editando" }).click();
    await existingRewardTitle.fill("Café de cortesía");
    await expect(page.getByRole("link", { name: "Volver al pase" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Volver al pase" })).toBeHidden();
    await page.getByRole("link", { name: "Volver al pase" }).click();
    await expect(page).toHaveURL(/\/pass$/);

    console.info(
        JSON.stringify({
            desktopMeasurements,
            focusedField,
            mobileMeasurements,
            passHeaderSpacing,
            promotionHeaderSpacing,
            controlBackgrounds,
            optionalBadgeColors,
            pointsBadge,
            weekdayFieldTops: { before: initialFieldTops, after: invalidFieldTops },
            timeFieldTops: { before: timeTopsBeforeError, after: timeTopsAfterError },
        }),
    );
});

test("disables competing Promotion actions while a save request is pending", async ({
    page,
    request,
}) => {
    await registerVerifiedBusiness(page, request);
    await page.goto("/pass/appearance");
    await page.getByLabel("Código hexadecimal").fill("#A77BFF");
    await page.getByRole("button", { name: "Guardar apariencia" }).click();
    await expect(page).toHaveURL(/\/pass$/);

    await page.goto("/promotions/create");
    const dates = await promotionDateCases(page);
    await page.getByLabel("¿Qué recompensa recibirá tu cliente?").fill("Borrador de bloqueo");
    await page.getByLabel("¿Cuántos puntos necesita?").fill("8");
    await page.getByLabel("Fecha de inicio").fill(dates.futureStart);
    await page.getByLabel("Fecha de fin").fill(dates.futureEnd);
    await page.getByLabel("Día", { exact: true }).selectOption("3");
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await page.getByRole("button", { name: "Guardar borrador" }).click();
    await expect(page).toHaveURL(/\/pass$/);
    const createSuccessToast = page.locator("ui-toast-group > [data-flux-toast-dialog]");
    await expect(createSuccessToast).toHaveCount(1);
    await expect(createSuccessToast).toBeVisible();
    await expect(page.getByText("Borrador de promoción guardado.", { exact: true })).toBeVisible();
    await page.locator("ui-toast-group ui-close button").evaluateAll((buttons) => {
        buttons.forEach((button) => button.click());
    });
    await page.getByRole("link", { name: "Editar borrador" }).click();
    await expect(page).toHaveURL(/\/promotions\/[0-9a-f-]+\/edit$/);

    const saveButton = page.getByRole("button", { name: "Guardar borrador" });
    const addRuleButton = page.getByRole("button", { name: "Añadir puntos extra" });
    const removeRuleButton = page.getByRole("button", { name: /Quitar la regla de/ }).first();
    const cleanBackLink = page.getByRole("link", { name: "Volver al pase" });
    const cancelButton = page.getByRole("button", { name: "Cancelar", exact: true });
    let requestCount = 0;
    let resolveRequestStarted;
    let releaseSaveRequest;
    const requestStarted = new Promise((resolve) => {
        resolveRequestStarted = resolve;
    });
    const saveResponseGate = new Promise((resolve) => {
        releaseSaveRequest = resolve;
    });
    let routeHandler;
    let saveResponse;

    /** Holds the editor's Livewire save request until the test releases the gate.
     * @param {import('@playwright/test').Route} route Intercepted Livewire update route carrying the save action.
     * @returns {Promise<void>} Continues the route after the gate resolves; rejects if Playwright cannot continue the intercepted request.
     */
    routeHandler = async (route) => {
        requestCount++;
        resolveRequestStarted();
        await saveResponseGate;
        await route.continue();
    };
    await page.route(LIVEWIRE_UPDATE_PATH, routeHandler);

    try {
        saveResponse = page.waitForResponse((response) =>
            LIVEWIRE_UPDATE_PATH.test(new URL(response.url()).pathname),
        );
        await saveButton.click();
        await requestStarted;
        await expect(saveButton).toBeDisabled();
        await expect(addRuleButton).toBeDisabled();
        await expect(removeRuleButton).toBeDisabled();
        await expect(cancelButton).toBeDisabled();
        await expect(cleanBackLink).toHaveAttribute("inert");
        await expect(cleanBackLink).toHaveCSS("opacity", "0.6");
        await expect(page.getByLabel("¿Qué recompensa recibirá tu cliente?")).toHaveAttribute(
            "readonly",
        );
        const backLinkBounds = await cleanBackLink.boundingBox();
        await page.mouse.click(
            backLinkBounds.x + backLinkBounds.width / 2,
            backLinkBounds.y + backLinkBounds.height / 2,
        );
        await expect(page).toHaveURL(/\/promotions\/[0-9a-f-]+\/edit$/);
        await cleanBackLink.evaluate((link) => link.focus());
        expect(await cleanBackLink.evaluate((link) => document.activeElement !== link)).toBe(true);
        await page.keyboard.press("Enter");

        await addRuleButton.evaluate((button) => button.click());
        await removeRuleButton.evaluate((button) => button.click());
        await cancelButton.evaluate((button) => button.click());
        await page.keyboard.press("Enter");
        expect(requestCount).toBe(1);
    } finally {
        releaseSaveRequest();
        if (saveResponse) await saveResponse;
        await page.unroute(LIVEWIRE_UPDATE_PATH, routeHandler);
    }

    await expect(page).toHaveURL(/\/pass$/);
    const updateSuccessToast = page.locator("ui-toast-group > [data-flux-toast-dialog]");
    await expect(updateSuccessToast).toHaveCount(1);
    await expect(updateSuccessToast).toBeVisible();
    await expect(page.getByText("Borrador de promoción guardado.", { exact: true })).toBeVisible();
    await page.locator("ui-toast-group ui-close button").evaluateAll((buttons) => {
        buttons.forEach((button) => button.click());
    });
    await page.goto("/pass");
    await expect(page.locator("ui-toast-group > [data-flux-toast-dialog]")).toHaveCount(0);
    await page.getByRole("link", { name: "Editar borrador" }).click();
    await expect(page).toHaveURL(/\/promotions\/[0-9a-f-]+\/edit$/);
    await expect(saveButton).toBeEnabled();
    await expect(addRuleButton).toBeEnabled();
    await expect(removeRuleButton).toBeEnabled();
    await expect(cancelButton).toBeEnabled();
    await expect(cleanBackLink).not.toHaveAttribute("inert", "");
    await expect(cleanBackLink).toHaveCSS("opacity", "1");

    const targetPoints = page.getByLabel("¿Cuántos puntos necesita?");
    const dirtyBackButton = page.getByRole("button", { name: "Volver al pase" });
    await targetPoints.fill("");
    await expect(dirtyBackButton).toBeVisible();
    await cancelButton.click();
    await expect(page.getByText("Los cambios que no guardaste se perderán.")).toBeVisible();

    let modalRequestCount = 0;
    let resolveModalRequestStarted;
    let releaseModalSave;
    const modalRequestStarted = new Promise((resolve) => {
        resolveModalRequestStarted = resolve;
    });
    const modalSaveResponseGate = new Promise((resolve) => {
        releaseModalSave = resolve;
    });
    /** Holds a Livewire save response until the test releases its synchronization gate.
     * @param {import('@playwright/test').Route} route Intercepted Livewire update request.
     * @returns {Promise<void>} Resolves after continuing the intercepted request; rejects if the route continuation fails.
     */
    const modalSaveHandler = async (route) => {
        modalRequestCount++;
        resolveModalRequestStarted();
        await modalSaveResponseGate;
        await route.continue();
    };
    await page.route(LIVEWIRE_UPDATE_PATH, modalSaveHandler);

    let modalSaveResponse;
    try {
        modalSaveResponse = page.waitForResponse((response) =>
            LIVEWIRE_UPDATE_PATH.test(new URL(response.url()).pathname),
        );
        await saveButton.evaluate((button) => button.click());
        await modalRequestStarted;
        await expect(saveButton).toBeDisabled();
        await expect(dirtyBackButton).toBeDisabled();
        await expect(cancelButton).toBeDisabled();
        await expect(page.getByRole("button", { name: "Seguir editando" })).toBeDisabled();
        await expect(page.getByRole("button", { name: "Descartar cambios" })).toBeDisabled();

        await dirtyBackButton.evaluate((button) => button.click());
        await page
            .getByRole("button", { name: "Descartar cambios" })
            .evaluate((button) => button.click());
        await page.keyboard.press("Space");
        expect(modalRequestCount).toBe(1);
    } finally {
        releaseModalSave();
        if (modalSaveResponse) await modalSaveResponse;
        await page.unroute(LIVEWIRE_UPDATE_PATH, modalSaveHandler);
    }

    await expect(page).toHaveURL(/\/promotions\/[0-9a-f-]+\/edit$/);
    await expect(saveButton).toBeEnabled();
    await expect(dirtyBackButton).toBeEnabled();
    await expect(cancelButton).toBeEnabled();
    await expect(page.getByRole("button", { name: "Seguir editando" })).toBeEnabled();
    await expect(page.getByRole("button", { name: "Descartar cambios" })).toBeEnabled();
    await expect(targetPoints).toHaveValue("");
    await expect(
        page.getByText("El campo meta de puntos es obligatorio.", { exact: true }),
    ).toBeVisible();
});

test("confirms leaving a fresh draft changed only in general information", async ({
    page,
    request,
}) => {
    await registerVerifiedBusiness(page, request);
    await page.goto("/promotions/create");

    const rewardTitle = page.getByLabel("¿Qué recompensa recibirá tu cliente?");
    await rewardTitle.fill("Borrador sin guardar");
    await expect(page.locator('[data-test="summary-reward-title"]')).toHaveText(
        "Borrador sin guardar",
    );

    await page.getByRole("button", { name: "Volver al pase" }).click();
    await expect(page.getByText("Los cambios que no guardaste se perderán.")).toBeVisible();
    await page.getByRole("button", { name: "Seguir editando" }).click();
    await expect(rewardTitle).toHaveValue("Borrador sin guardar");

    await page.getByRole("button", { name: "Cancelar", exact: true }).click();
    await expect(page.getByText("Los cambios que no guardaste se perderán.")).toBeVisible();
    await page.getByRole("button", { name: "Seguir editando" }).click();
    await expect(rewardTitle).toHaveValue("Borrador sin guardar");

    await page.getByRole("button", { name: "Cancelar", exact: true }).click();
    await page.getByRole("button", { name: "Descartar cambios" }).click();
    await expect(page).toHaveURL(/\/pass$/);
});

test("rejects past draft dates through Livewire without native form interception", async ({
    page,
    request,
}) => {
    await registerVerifiedBusiness(page, request);
    await page.goto("/promotions/create");

    const dates = await promotionDateCases(page);
    const startDate = page.getByLabel("Fecha de inicio");
    const endDate = page.getByLabel("Fecha de fin");
    await expect(page.locator('[data-test="promotion-editor"] form')).toHaveAttribute(
        "novalidate",
        "",
    );
    await expect(startDate).toHaveAttribute("min", dates.minimum);
    await startDate.fill(dates.past);
    await endDate.fill(dates.minimum);
    await page.getByLabel("¿Qué recompensa recibirá tu cliente?").fill("No debe guardarse");
    await page.getByLabel("¿Cuántos puntos necesita?").fill("8");

    expect(await startDate.evaluate((input) => input.validity.rangeUnderflow)).toBe(true);
    await page.getByRole("button", { name: "Guardar borrador" }).click();

    await expect(page).toHaveURL(/\/promotions\/create$/);
    await expect(
        page.getByText(
            "La fecha de inicio debe ser hoy o posterior en la zona horaria del negocio.",
            { exact: true },
        ),
    ).toBeVisible();
    await expect(
        page.getByText("Revisa los campos marcados y corrige los datos antes de continuar."),
    ).toBeVisible();
    await expect(startDate).toHaveValue(dates.past);
    await expect(page.getByLabel("¿Qué recompensa recibirá tu cliente?")).toHaveValue(
        "No debe guardarse",
    );
});

test("serializes extra-point Add and Remove actions while Livewire is pending", async ({
    page,
    request,
}) => {
    await registerVerifiedBusiness(page, request);
    await page.goto("/promotions/create");

    const weekday = page.getByLabel("Día", { exact: true });
    const addRuleButton = page.getByRole("button", { name: "Añadir puntos extra" });
    const acceptedRules = page
        .getByRole("list", { name: "Reglas de puntos extra añadidas" })
        .locator("li");

    await weekday.selectOption("3");
    await page.getByLabel("Todo el día", { exact: true }).check();
    await addRuleButton.click();
    await expect(acceptedRules).toHaveCount(1);
    await weekday.selectOption("1");
    await expect(addRuleButton).toBeEnabled();
    await addRuleButton.click();
    await expect(acceptedRules).toHaveCount(2);

    let releaseRemoveRequest;
    let resolveRemoveStarted;
    let resolveRemoveContinued;
    let removeRequestWasStarted = false;
    const removeStarted = new Promise((resolve) => {
        resolveRemoveStarted = resolve;
    });
    const removeContinued = new Promise((resolve) => {
        resolveRemoveContinued = resolve;
    });
    const removeResponseGate = new Promise((resolve) => {
        releaseRemoveRequest = resolve;
    });
    const removeRequestHandler = async (route) => {
        resolveRemoveStarted();
        await removeResponseGate;
        await route.continue();
        resolveRemoveContinued();
    };

    await page.route(LIVEWIRE_UPDATE_PATH, removeRequestHandler);

    try {
        await page.getByRole("button", { name: "Quitar la regla de Miércoles" }).click();
        await removeStarted;
        removeRequestWasStarted = true;

        await expect(addRuleButton).toBeDisabled();
        const removeButtons = page.getByRole("button", { name: /Quitar la regla de/ });
        await expect(removeButtons).toHaveCount(2);

        for (const index of [0, 1]) {
            await expect(removeButtons.nth(index)).toBeDisabled();
        }
    } finally {
        releaseRemoveRequest();
        if (removeRequestWasStarted) await removeContinued;
        await page.unroute(LIVEWIRE_UPDATE_PATH, removeRequestHandler);
    }

    await expect(acceptedRules).toHaveCount(1);
    await expect(acceptedRules.getByText("Lunes", { exact: true })).toBeVisible();
    await expect(acceptedRules.getByText("Miércoles", { exact: true })).toHaveCount(0);

    await weekday.selectOption("5");
    await expect(addRuleButton).toBeEnabled();

    let releaseAddRequest;
    let resolveAddStarted;
    let resolveAddContinued;
    let addRequestWasStarted = false;
    const addStarted = new Promise((resolve) => {
        resolveAddStarted = resolve;
    });
    const addContinued = new Promise((resolve) => {
        resolveAddContinued = resolve;
    });
    const addResponseGate = new Promise((resolve) => {
        releaseAddRequest = resolve;
    });
    const addRequestHandler = async (route) => {
        resolveAddStarted();
        await addResponseGate;
        await route.continue();
        resolveAddContinued();
    };

    await page.route(LIVEWIRE_UPDATE_PATH, addRequestHandler);

    try {
        await addRuleButton.click();
        await addStarted;
        addRequestWasStarted = true;

        await expect(addRuleButton).toBeDisabled();
        const removeButtons = page.getByRole("button", { name: /Quitar la regla de/ });
        await expect(removeButtons).toHaveCount(1);
        await expect(removeButtons.first()).toBeDisabled();
    } finally {
        releaseAddRequest();
        if (addRequestWasStarted) await addContinued;
        await page.unroute(LIVEWIRE_UPDATE_PATH, addRequestHandler);
    }

    await expect(acceptedRules).toHaveCount(2);
    await expect(acceptedRules.getByText("Lunes", { exact: true })).toBeVisible();
    await expect(acceptedRules.getByText("Viernes", { exact: true })).toBeVisible();
});

test("contains keyboard focus and restores the Promotion review opener after dismissal", async ({
    page,
    request,
}) => {
    await registerVerifiedBusiness(page, request);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto("/promotions/create");

    const dates = await promotionDateCases(page);
    const rewardTitle = page.getByLabel("¿Qué recompensa recibirá tu cliente?");
    const reviewButton = page.getByRole("button", { name: "Publicar promoción" });
    const dialog = page.locator('dialog[data-modal="promotion-publication-review"]');

    await rewardTitle.fill("Café de cortesía");
    await page.getByLabel("¿Cuántos puntos necesita?").fill("8");
    await page.getByLabel("Fecha de inicio").fill(dates.futureStart);
    await page.getByLabel("Fecha de fin").fill(dates.futureEnd);
    await page.getByLabel("Día", { exact: true }).selectOption("1");
    await page.getByRole("button", { name: "Añadir puntos extra" }).click();
    await page.evaluate(() => window.scrollTo({ top: 250 }));

    /** Opens the review after server validation and wait for its dialog.
     * @returns {Promise<void>} Resolves when the publication review is visible.
     */
    const openReview = async () => {
        await reviewButton.click();
        await expect(dialog).toBeVisible();
    };

    /** Dismisses through one browser control and verify state, focus, scroll and unsaved values remain.
     * @param {() => Promise<void>} dismiss Invokes one native or secondary dismissal path.
     * @returns {Promise<void>} Resolves when the editor is restored without losing its current viewport or value.
     */
    const dismissAndExpectFocusReturn = async (dismiss) => {
        const scrollTop = await page.evaluate(() => window.scrollY);

        await dismiss();
        await expect(dialog).not.toBeVisible();
        await expect(dialog.locator("[data-promotion-review-scroll-body]")).toHaveCount(0);
        await expect(reviewButton).toBeFocused();
        expect(await page.evaluate(() => window.scrollY)).toBe(scrollTop);
        await expect(rewardTitle).toHaveValue("Café de cortesía");
    };

    await openReview();

    await expect(dialog.locator("[data-promotion-review-scroll-body]")).toBeFocused();
    for (const value of [
        dialog.locator("dd").first(),
        dialog.locator("dd").nth(1),
        dialog.locator("details summary > span").last(),
    ]) {
        await expect(value).toHaveCSS("font-size", "16px");
        await expect(value).toHaveCSS("font-weight", "600");
    }

    const focusSequence = [];

    for (let index = 0; index < 8; index++) {
        await page.keyboard.press("Tab");
        const focusedElement = await page.evaluate(() => {
            const activeElement = document.activeElement;
            const reviewDialog = document.querySelector(
                'dialog[data-modal="promotion-publication-review"]',
            );

            return {
                dialogContainsFocus: reviewDialog.contains(activeElement),
                pageHasFocus: document.hasFocus(),
                activeTag: activeElement.tagName,
                activeName:
                    activeElement.getAttribute("aria-label") ||
                    activeElement.textContent.trim().slice(0, 40),
            };
        });

        focusSequence.push(focusedElement);

        if (!focusedElement.dialogContainsFocus) break;
    }

    expect(
        focusSequence.every(
            (focusedElement) => focusedElement.dialogContainsFocus || !focusedElement.pageHasFocus,
        ),
        JSON.stringify(focusSequence),
    ).toBe(true);

    await dismissAndExpectFocusReturn(() => page.keyboard.press("Escape"));

    await openReview();
    await dismissAndExpectFocusReturn(() =>
        page.getByRole("button", { name: "Seguir editando" }).click(),
    );

    await openReview();
    await dismissAndExpectFocusReturn(() =>
        dialog.getByRole("button", { name: "Cerrar ventana" }).click(),
    );

    await openReview();
    await dismissAndExpectFocusReturn(() => page.mouse.click(4, 4));
});

test("keeps an overlapping publication in the editor with its review closed", async ({
    page,
    request,
}) => {
    await registerVerifiedBusiness(page, request);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto("/promotions/create");

    const dates = await promotionDateCases(page);
    const dialog = page.locator('dialog[data-modal="promotion-publication-review"]');

    /** Enters the required terms and publish a promotion using this test's disposable Business.
     * @param {string} title Reward title for this test-owned publication.
     * @returns {Promise<void>} Resolves after the app confirms the publication on Pase.
     */
    const publish = async (title) => {
        await page.goto("/promotions/create");
        await page.getByLabel("¿Qué recompensa recibirá tu cliente?").fill(title);
        await page.getByLabel("¿Cuántos puntos necesita?").fill("8");
        await page.getByLabel("Fecha de inicio").fill(dates.futureStart);
        await page.getByLabel("Fecha de fin").fill(dates.futureEnd);
        await page.getByRole("button", { name: "Publicar promoción" }).click();
        await expect(dialog).toBeVisible();
        await page.getByRole("button", { name: "Confirmar publicación" }).click();
        await expect(page).toHaveURL(/\/pass$/);
    };

    await publish("Promoción ya publicada");
    await expect(page.getByText("Promoción publicada.", { exact: true })).toBeVisible();

    await page.goto("/promotions/create");
    const rewardTitle = page.getByLabel("¿Qué recompensa recibirá tu cliente?");
    const reviewButton = page.getByRole("button", { name: "Publicar promoción" });

    await rewardTitle.fill("Promoción que se superpone");
    await page.getByLabel("¿Cuántos puntos necesita?").fill("8");
    await page.getByLabel("Fecha de inicio").fill(dates.futureStart);
    await page.getByLabel("Fecha de fin").fill(dates.futureEnd);
    await page.evaluate(() => window.scrollTo({ top: 250 }));
    await reviewButton.click();
    await expect(dialog).toBeVisible();

    const scrollTop = await page.evaluate(() => window.scrollY);

    await page.getByRole("button", { name: "Confirmar publicación" }).click();

    await expect(dialog).not.toBeVisible();
    await expect(page).toHaveURL(/\/promotions\/create$/);
    await expect(
        page.getByText("El período de esta promoción se superpone con otra promoción publicada.", {
            exact: true,
        }),
    ).toBeVisible();
    await expect(reviewButton).toBeFocused();
    await expect(rewardTitle).toHaveValue("Promoción que se superpone");
    await expect(page.getByLabel("Fecha de inicio")).toHaveValue(dates.futureStart);
    await expect(page.getByLabel("Fecha de fin")).toHaveValue(dates.futureEnd);
    expect(await page.evaluate(() => window.scrollY)).toBe(scrollTop);
});
