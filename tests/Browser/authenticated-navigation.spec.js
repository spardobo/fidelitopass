import { existsSync } from "node:fs";
import { expect, test } from "@playwright/test";

async function registerVerifiedBusiness(page, request) {
    const recipient = `shell-${crypto.randomUUID()}@example.test`;
    await page.goto("/register");
    await page.getByRole("textbox", { name: "Nombre", exact: true }).fill("Sergio");
    await page.getByLabel("Correo electrónico", { exact: true }).fill(recipient);
    await page.getByLabel("Nombre del negocio", { exact: true }).fill("Jaqaku");
    await page.getByLabel("Zona horaria", { exact: true }).selectOption("America/La_Paz");
    await page.locator('input[name="password"]').fill("ValidPassword84!strong");
    await page.locator('input[name="password_confirmation"]').fill("ValidPassword84!strong");
    await page.getByRole("button", { name: "Crear cuenta" }).click();
    await expect(page).toHaveURL(/\/email\/verify(?:\?|$)/);
    console.info(`Created shell browser fixture User + Business: ${recipient}`);

    const mailpit = "http://fidelitopass-mailpit-dev:8025";
    let message;
    await expect
        .poll(async () => {
            const response = await request.get(`${mailpit}/api/v1/messages`);
            const mailbox = await response.json();
            message = mailbox.messages?.find((item) =>
                item.To?.some((address) => address.Address === recipient),
            );
            return Boolean(message);
        })
        .toBe(true);
    const response = await request.get(`${mailpit}/api/v1/message/${message.ID}`);
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
            return { x, y, width, height };
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

for (const width of [1280, 375]) {
    test(`authenticated navigation and local reference at ${width}px`, async ({
        page,
        request,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 800 });
        await page.emulateMedia({ colorScheme: "dark", reducedMotion: "reduce" });
        let reference = null;
        // The ignored owner reference is local evidence, not a CI fixture dependency.
        if (existsSync("tmp/fidelitopass-maquetado/fidelitopass-menu-ajustado.html")) {
            await page.goto(
                "file:///work/tmp/fidelitopass-maquetado/fidelitopass-menu-ajustado.html",
            );
            await expect(
                page
                    .locator(width <= 768 ? ".mobile-nav" : ".desktop-nav")
                    .getByRole("link", { name: "Resumen" }),
            ).toBeVisible();
            await page.evaluate(() => document.fonts.ready);
            reference = await measureShell(page, true);
            await page.screenshot({
                path: testInfo.outputPath(`mockup-${width}.png`),
                fullPage: true,
            });
        } else {
            console.info("Local mockup comparison unavailable; shell regression still runs.");
        }

        await registerVerifiedBusiness(page, request);
        const header = page.getByRole("banner");
        const navigation = header.getByRole("navigation", { name: "Navegación principal" });
        const account = header.getByRole("button", { name: "Abrir menú de cuenta" });
        await expect(header.locator("[data-flux-avatar]")).toHaveText("Se");
        await expect(
            header.getByRole("link", { name: "FidelitoPass" }).locator("img"),
        ).toHaveAttribute("src", /logo-header\.webp$/);
        for (const label of ["Search", "Repository", "Documentation"]) {
            await expect(header.getByRole("link", { name: label, exact: true })).toHaveCount(0);
        }
        await expect(
            header.locator(
                'a[href="#"], a[href*="github.com/laravel"], a[href*="laravel.com/docs"]',
            ),
        ).toHaveCount(0);
        const summary = navigation.getByRole("link", { name: "Resumen", exact: true });
        await expect(summary).toHaveAttribute("aria-current", "page");
        await expect(header.locator('[aria-current="page"]')).toHaveCount(1);
        await page.evaluate(() => document.fonts.ready);
        const actual = await measureShell(page, false);
        expect(actual.loadedFonts).toContain("Onest Variable");
        expect(actual.fontSize).toBe("16px");
        expect(actual.headerColor).toBe("rgb(36, 36, 36)");
        expect(actual.marker).toEqual({
            height: "2px",
            width: `${actual.content.width}px`,
            bottom: "-6px",
            color: "rgb(167, 123, 255)",
            pointerEvents: "none",
        });
        await expect(summary).toHaveCSS("color", "rgb(205, 176, 255)");
        const pass = navigation.getByRole("link", { name: "Pase", exact: true });
        await expect(pass).toHaveCSS("color", "rgb(199, 196, 206)");
        await pass.hover();
        await expect(pass).toHaveCSS("color", "rgb(205, 176, 255)");
        const inactiveMarker = await pass.locator(".app-navigation-content").evaluate((content) => {
            return getComputedStyle(content, "::after").content;
        });
        expect(inactiveMarker).toBe("none");
        expect(actual.link.height).toBeGreaterThanOrEqual(44);
        expect(actual.avatar.width).toBe(44);
        expect(actual.overflow).toBe(false);
        expect(actual.header.width).toBe(width);
        expect(actual.avatar.x).toBeGreaterThanOrEqual(0);
        expect(actual.avatar.x + actual.avatar.width).toBeLessThanOrEqual(width);
        await summary.focus();
        await page.keyboard.press("Shift+Tab");
        await page.keyboard.press("Tab");
        await expect(summary).toBeFocused();
        await expect(summary).toHaveCSS("outline-color", "rgb(205, 176, 255)");
        await expect(summary).toHaveCSS("outline-width", "2px");
        await expect(summary).toHaveCSS("outline-offset", "3px");
        await page.screenshot({ path: testInfo.outputPath(`app-${width}.png`), fullPage: true });
        console.info(
            JSON.stringify({
                width,
                state: "Resumen, appearance unsaved, no Promotion",
                reference,
                actual,
            }),
        );
        await testInfo.attach("shell-comparison", {
            body: JSON.stringify({ width, reference, actual }, null, 2),
            contentType: "application/json",
        });

        for (const narrowWidth of [320, 768, 900]) {
            await page.setViewportSize({ width: narrowWidth, height: 800 });
            expect(
                await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),
            ).toBe(false);
            for (const control of [
                account,
                header.getByRole("link", { name: "Registrar visita" }),
            ]) {
                const box = await control.boundingBox();
                expect(box.x).toBeGreaterThanOrEqual(0);
                expect(box.x + box.width).toBeLessThanOrEqual(narrowWidth);
            }
        }
        await page.setViewportSize({ width, height: 800 });

        for (const [label, path] of [
            ["Pase", "/pass"],
            ["Invitar clientes", "/invite"],
            ["Registrar visita", "/visits/create"],
        ]) {
            const link = header.getByRole("link", { name: label, exact: true });
            await expect(link).toBeVisible();
            await expect(link).toBeEnabled();
            await expect(link).toHaveAttribute("href", new RegExp(`${path}$`));
            expect((await link.boundingBox()).height).toBeGreaterThanOrEqual(44);
            await link.click();
            await expect(page).toHaveURL(new RegExp(`${path}$`));
            await page.goto("/dashboard");
        }

        await account.focus();
        await page.keyboard.press("Enter");
        await expect(page.getByRole("menuitem", { name: "Perfil del negocio" })).toBeVisible();
        const accountName = page.locator("[data-flux-menu] [data-flux-heading]");
        await expect(accountName).toHaveText("Sergio");
        await expect(accountName).toHaveCSS("color", "rgb(246, 245, 242)");
        await expect(accountName).toHaveCSS("font-size", "16px");
        await page.keyboard.press("Escape");
        await expect(account).toBeFocused();
        const accountDestinations = [
            ["Perfil del negocio", "/business/profile"],
            ["Perfil", "/settings/profile"],
            ["Seguridad", "/settings/security"],
        ];
        for (const [index, [label, path]] of accountDestinations.entries()) {
            await account.focus();
            await page.keyboard.press("ArrowDown");
            await expect(page.getByRole("menuitem", { name: "Perfil del negocio" })).toBeFocused();
            for (let step = 0; step < index; step++) {
                await page.keyboard.press("ArrowDown");
            }
            const item = page.getByRole("menuitem", { name: label, exact: true });
            await expect(item).toBeFocused();
            await page.keyboard.press("Enter");
            if (label === "Seguridad") {
                await expect(page).toHaveURL(/\/confirm-password(?:\?|$)/);
                await page
                    .getByRole("textbox", { name: "Contraseña", exact: true })
                    .fill("ValidPassword84!strong");
                await page.getByRole("button", { name: "Confirmar", exact: true }).click();
            }
            await expect(page).toHaveURL(new RegExp(`${path}$`));
            await account.click();
            await expect(page.getByRole("menuitem", { name: label, exact: true })).toHaveAttribute(
                "aria-current",
                "page",
            );
            await expect(header.locator('[aria-current="page"]')).toHaveCount(1);
            await page.keyboard.press("Escape");
        }
        await account.click();
        const logout = page.waitForResponse(
            (response) =>
                new URL(response.url()).pathname === "/logout" &&
                response.request().method() === "POST",
        );
        await page.getByRole("menuitem", { name: "Cerrar sesión" }).click();
        expect((await logout).status()).toBe(302);
        await page.goto("/dashboard");
        await expect(page).toHaveURL(/\/login(?:\?|$)/);
    });
}
