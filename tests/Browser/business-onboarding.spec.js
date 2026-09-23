import { expect, test } from "@playwright/test";

const mailpit = "http://biz02-mail-84:8025";

function luminance(color) {
    const channels = color
        .match(/^rgba?\((\d+), (\d+), (\d+)/)
        ?.slice(1)
        .map(Number);
    expect(channels, `rendered RGB color: ${color}`).toBeTruthy();
    const linear = channels.map((channel) => {
        const value = channel / 255;
        return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });
    return linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
}

function contrast(foreground, background) {
    const light = Math.max(luminance(foreground), luminance(background));
    const dark = Math.min(luminance(foreground), luminance(background));
    return (light + 0.05) / (dark + 0.05);
}

async function expectRenderedTheme(page, mode, heading, checkButton = false) {
    const rendered = await page.evaluate(
        ({ headingText, checkButton }) => {
            const title = [...document.querySelectorAll("h1, h2")].find((element) =>
                element.textContent.includes(headingText),
            );
            const action = checkButton
                ? [...document.querySelectorAll("button[data-flux-button]")].find(
                      (element) =>
                          getComputedStyle(element).backgroundColor === "rgb(183, 243, 74)",
                  )
                : null;
            const sidebar = document.querySelector("[data-flux-sidebar]");
            const field = document.querySelector("input[data-flux-control]");
            const surface = sidebar ?? field?.closest(".bg-business-surface");
            const canvas = getComputedStyle(document.body).backgroundColor;
            if (field) field.focus();
            return {
                canvas,
                ink: getComputedStyle(document.body).color,
                surface: surface ? getComputedStyle(surface).backgroundColor : null,
                border: surface ? getComputedStyle(surface).borderRightColor : null,
                title: title && getComputedStyle(title).color,
                titleBackground: title?.closest(".bg-business-surface")
                    ? getComputedStyle(title.closest(".bg-business-surface")).backgroundColor
                    : canvas,
                button: action && {
                    background: getComputedStyle(action).backgroundColor,
                    text: getComputedStyle(action).color,
                },
                focus: field && getComputedStyle(field).boxShadow,
                focusBackdrop: field?.closest(".bg-business-surface")
                    ? getComputedStyle(field.closest(".bg-business-surface")).backgroundColor
                    : canvas,
            };
        },
        { headingText: heading, checkButton },
    );

    expect(rendered.canvas).toBe(mode === "light" ? "rgb(244, 241, 232)" : "rgb(24, 27, 23)");
    expect(rendered.ink).toBe(mode === "light" ? "rgb(37, 40, 32)" : "rgb(238, 235, 221)");
    expect(contrast(rendered.ink, rendered.canvas)).toBeGreaterThanOrEqual(4.5);
    expect(rendered.title).toBeTruthy();
    if (checkButton) {
        expect(rendered.button).toBeTruthy();
        expect(contrast(rendered.button.text, rendered.button.background)).toBeGreaterThanOrEqual(
            4.5,
        );
    }
    if (rendered.surface) {
        expect(rendered.surface).toBe(mode === "light" ? "rgb(251, 249, 242)" : "rgb(32, 36, 31)");
        expect(rendered.border).toBe(mode === "light" ? "rgb(218, 214, 200)" : "rgb(58, 64, 55)");
    }
    expect(contrast(rendered.title, rendered.titleBackground)).toBeGreaterThanOrEqual(4.5);
    if (rendered.focus) {
        const focus = mode === "light" ? "rgb(82, 123, 19)" : "rgb(183, 243, 74)";
        expect(rendered.focus).toContain(focus);
        expect(contrast(focus, rendered.focusBackdrop)).toBeGreaterThanOrEqual(3);
    }
}

async function verificationLink(request, recipient) {
    await expect
        .poll(async () => {
            const response = await request.get(`${mailpit}/api/v1/messages`);
            expect(response.ok()).toBeTruthy();
            const mailbox = await response.json();
            return mailbox.messages?.some((message) =>
                message.To?.some((address) => address.Address === recipient),
            );
        })
        .toBe(true);

    const mailboxResponse = await request.get(`${mailpit}/api/v1/messages`);
    expect(mailboxResponse.ok()).toBeTruthy();
    const mailbox = await mailboxResponse.json();
    const message = mailbox.messages?.find((item) =>
        item.To?.some((address) => address.Address === recipient),
    );
    expect(message, "verification mail in isolated recipient inbox").toBeTruthy();
    const detailResponse = await request.get(`${mailpit}/api/v1/message/${message.ID}`);
    expect(detailResponse.ok()).toBeTruthy();
    const detail = await detailResponse.json();
    const link = detail.Text?.match(/https?:\/\/[^\s<>)]*\/email\/verify\/[^\s<>)]*/)?.[0];
    expect(link, "verification link in isolated recipient message").toBeTruthy();
    return link.replace(/&amp;/g, "&");
}

for (const width of [1280, 375]) {
    test(`verified owner completes onboarding at ${width}px`, async ({ page, request }) => {
        await page.setViewportSize({ width, height: 800 });
        await page.emulateMedia({ colorScheme: "dark" });
        const recipient = `biz02-${width}-${crypto.randomUUID()}@example.test`;
        const business = `Negocio ${width}`;

        await page.goto("/dashboard");
        await expect(page).toHaveURL(/\/login(?:\?|$)/);
        await expect(page.locator("html")).not.toHaveClass(/\bdark\b/);
        expect(await page.evaluate(() => localStorage.getItem("flux.appearance"))).toBe("light");
        await page.goto("/register");
        await expect(
            page.getByRole("heading", { name: /create an account|crear una cuenta/i }),
        ).toBeVisible();
        await expectRenderedTheme(page, "light", "", true);
        await page
            .getByRole("textbox", { name: /name|nombre/i })
            .first()
            .focus();
        await expect(page.getByRole("textbox", { name: /name|nombre/i }).first()).toBeFocused();
        await page
            .getByRole("textbox", { name: /name|nombre/i })
            .first()
            .fill(`Owner ${width}`);
        await page.getByRole("textbox", { name: /email|correo/i }).fill(recipient);
        await page.locator('input[name="password"]').fill("ValidPassword84!strong");
        await page.locator('input[name="password_confirmation"]').fill("ValidPassword84!strong");
        await page.getByRole("button", { name: /create account|crear cuenta/i }).click();

        await expect(page).toHaveURL(/\/email\/verify(?:\?|$)/);
        await page.goto("/dashboard");
        await expect(page).toHaveURL(/\/email\/verify(?:\?|$)/);
        const link = await verificationLink(request, recipient);
        await page.goto(link);
        await expect(page).toHaveURL(/\/business\/onboarding(?:\?|$)/);
        await expect(page.getByRole("heading", { name: "Configurá tu negocio" })).toBeVisible();

        const name = page.getByRole("textbox", { name: "Nombre del negocio" });
        await name.focus();
        await expect(name).toBeFocused();
        await page.keyboard.press("Tab");
        await expect(page.getByLabel("Zona horaria")).toBeFocused();
        await name.fill(business);
        await page.getByLabel("Zona horaria").selectOption("America/Argentina/Buenos_Aires");
        await page.getByRole("button", { name: "Guardar negocio" }).focus();
        await page.keyboard.press("Enter");
        await expect(page).toHaveURL(/\/dashboard(?:\?|$)/);
        await expect(page.getByRole("heading", { name: business })).toBeVisible();
        await expectRenderedTheme(page, "light", business);
        await expect(page.locator("html")).not.toHaveClass(/\bdark\b/);
        await expect(page.getByText("Zona horaria: America/Argentina/Buenos_Aires")).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
            true,
        );

        await page.goto("/settings/appearance");
        await page.getByRole("radio", { name: /dark|oscuro/i }).check();
        await expect(page.locator("html")).toHaveClass(/\bdark\b/);
        expect(await page.evaluate(() => localStorage.getItem("flux.appearance"))).toBe("dark");
        await page.goto("/dashboard");
        await expect(page.locator("html")).toHaveClass(/\bdark\b/);
        await expectRenderedTheme(page, "dark", business);
        await page.getByRole("link", { name: "Editar perfil del negocio" }).click();
        await expect(page.locator("html")).toHaveClass(/\bdark\b/);
        await expect(page.getByRole("textbox", { name: "Nombre del negocio" })).toHaveValue(
            business,
        );
        await expect(page.getByLabel("Zona horaria")).toHaveValue("America/Argentina/Buenos_Aires");
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
            true,
        );
    });
}
