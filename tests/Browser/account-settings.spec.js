import { expect, test } from "@playwright/test";

const PASSWORD = "ValidPassword84!strong";

/**
 * Checks rendered field geometry, palette and keyboard focus.
 * @param {import('@playwright/test').Locator} control Native input or select.
 * @returns {Promise<void>} Resolves after assertions; rejects on a rendering mismatch.
 */
async function expectAccountControl(control) {
    await control.evaluate((element) => element.blur());
    await expect(control).toHaveCSS("background-color", "rgb(34, 34, 34)");
    await expect(control).toHaveCSS("color", "rgb(246, 245, 242)");
    await expect(control).toHaveCSS("border-color", "rgb(125, 125, 125)");
    await expect(control).toHaveCSS("font-size", "16px");
    await expect(control).toHaveCSS("line-height", "24px");
    await expect.poll(async () => (await control.boundingBox()).height).toBeGreaterThanOrEqual(44);

    await control.focus();
    await control.page().keyboard.press("Tab");
    await control.page().keyboard.press("Shift+Tab");
    await expect(control).toBeFocused();
    await expect(control).toHaveCSS("outline-color", "rgb(167, 123, 255)");
    await expect(control).toHaveCSS("outline-width", "2px");
    await expect(control).toHaveCSS("outline-offset", "3px");
}

/**
 * Creates an isolated browser-owned Business and verifies its email with local Mailpit.
 * @param {import('@playwright/test').Page} page Fresh browser context.
 * @param {import('@playwright/test').APIRequestContext} request Isolated local HTTP context.
 * @returns {Promise<void>} Resolves on the verified dashboard; rejects on signup or verification failure.
 */
async function registerAccount(page, request) {
    const recipient = `account-styles-${crypto.randomUUID()}@example.test`;
    await page.goto("/register");
    await page.getByLabel("Nombre", { exact: true }).fill("Account Owner");
    await page.getByLabel("Correo electrónico", { exact: true }).fill(recipient);
    await page.getByLabel("Nombre del negocio", { exact: true }).fill("Account Style Fixture");
    await page.getByLabel("Zona horaria", { exact: true }).selectOption("America/La_Paz");
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.locator('input[name="password_confirmation"]').fill(PASSWORD);
    await page.getByRole("button", { name: "Crear cuenta", exact: true }).click();
    await expect(page).toHaveURL(/\/email\/verify(?:\?|$)/);

    const resend = page.getByRole("button", {
        name: "Reenviar correo de verificación",
        exact: true,
    });
    await expect(resend).toHaveCSS("background-color", "rgb(167, 123, 255)");
    expect((await resend.boundingBox()).height).toBeGreaterThanOrEqual(44);
    await page.goto("/settings/profile");
    await expect(page.getByRole("button", { name: "Eliminar cuenta", exact: true })).toHaveCount(0);
    const unverifiedNote = page.locator("p").filter({
        has: page.getByRole("button", {
            name: "Haz clic aquí para reenviar el correo de verificación.",
            exact: true,
        }),
    });
    await expect(unverifiedNote).toHaveCSS("font-size", "14px");
    await expect(unverifiedNote).toHaveCSS("line-height", "20px");
    await expect(unverifiedNote).toHaveCSS("color", "rgb(176, 172, 184)");
    await page
        .getByRole("button", {
            name: "Haz clic aquí para reenviar el correo de verificación.",
            exact: true,
        })
        .click();
    await expect(page.locator('[data-flux-toast-dialog][data-variant="success"]')).toContainText(
        "enlace de verificación",
    );
    await expect(page.locator('p[role="status"]')).toHaveCount(0);

    let message;
    await expect
        .poll(async () => {
            const response = await request.get(
                `${process.env.PLAYWRIGHT_MAILPIT_URL}/api/v1/messages`,
            );
            expect(response.ok()).toBe(true);
            const mailbox = await response.json();
            message = mailbox.messages.find((item) =>
                item.To.some((address) => address.Address === recipient),
            );
            return Boolean(message);
        })
        .toBe(true);
    const response = await request.get(
        `${process.env.PLAYWRIGHT_MAILPIT_URL}/api/v1/message/${message.ID}`,
    );
    expect(response.ok()).toBe(true);
    const detail = await response.json();
    const link = detail.Text.match(/https?:\/\/[^\s<>)]*\/email\/verify\/[^\s<>)]*/)[0];
    await page.goto(link.replace(/&amp;/g, "&"));
    await expect(page).toHaveURL(/\/dashboard(?:\?|$)/);
}

for (const width of [375, 1280]) {
    test(`active account destinations retain canonical roles and safe interactions at ${width}px`, async ({
        page,
        request,
    }, testInfo) => {
        expect(
            process.env.PLAYWRIGHT_BASE_URL,
            "An explicitly isolated worktree application is required",
        ).toBeTruthy();
        expect(
            process.env.PLAYWRIGHT_MAILPIT_URL,
            "An explicitly isolated Mailpit fixture is required",
        ).toBeTruthy();
        await page.setViewportSize({ width, height: 800 });
        await page.emulateMedia({
            colorScheme: "light",
            reducedMotion: "reduce",
        });
        await page.addInitScript(() => localStorage.setItem("flux.appearance", "light"));
        await registerAccount(page, request);
        const summaryHeader = await page.locator("main.app-workspace > header").boundingBox();

        for (const [label, path] of [
            ["Perfil del negocio", "/business/profile"],
            ["Perfil", "/settings/profile"],
            ["Seguridad", "/settings/security"],
        ]) {
            const account = page.getByRole("button", {
                name: "Abrir menú de cuenta",
                exact: true,
            });
            await account.focus();
            await page.keyboard.press("Enter");
            await page.getByRole("menuitem", { name: label, exact: true }).click();

            if (path === "/settings/security") {
                await expect(page).toHaveURL(/\/confirm-password(?:\?|$)/);
                const password = page.getByLabel("Contraseña", { exact: true });
                await expectAccountControl(password);
                const confirm = page.getByRole("button", {
                    name: "Confirmar",
                    exact: true,
                });
                await expect(confirm).toHaveCSS("background-color", "rgb(167, 123, 255)");
                await password.fill(PASSWORD);
                await confirm.click();
            }
            await expect(page).toHaveURL(new RegExp(`${path}$`));
            await page.evaluate(() => document.fonts.ready);
            expect(
                await page.evaluate(() =>
                    [...document.fonts].some(
                        (font) => font.status === "loaded" && font.family.includes("Onest"),
                    ),
                ),
            ).toBe(true);
            await expect(page.locator("html")).toHaveClass(/\bdark\b/);
            const title = page.getByRole("heading", { level: 1 });
            await expect(title).toHaveCSS("font-family", /Onest Variable/);
            await expect(title).toHaveCSS("font-size", "32px");
            await expect(title).toHaveCSS("line-height", "40px");
            await expect(title).toHaveCSS("font-weight", "700");
            await expect(title).toHaveCSS("color", "rgb(246, 245, 242)");
            const eyebrow = page
                .locator("section.app-workspace > header > p")
                .filter({ hasText: "· Tu negocio" });
            await expect(eyebrow).toHaveText(
                `${path === "/business/profile" ? "Account Style Fixture" : "Updated Account Business"} · Tu negocio`,
            );
            await expect(eyebrow).toHaveCSS("color", "rgb(167, 123, 255)");
            await expect(eyebrow).toHaveCSS("font-size", "16px");
            await expect(page.locator("section.app-workspace")).toHaveCSS("padding-top", "0px");
            const header = await page.locator("section.app-workspace > header").boundingBox();
            expect(header.x).toBe(summaryHeader.x);
            expect(header.y).toBe(summaryHeader.y);
            const surface = page.locator('[data-test="settings-surface"]');
            await expect(surface).toHaveCSS("background-color", "rgb(46, 46, 46)");
            await expect(surface).toHaveCSS("border-color", "rgb(82, 82, 82)");
            await expect(surface).toHaveCSS("border-radius", "20px");
            await expect(surface).toHaveCSS("padding-left", width < 640 ? "16px" : "24px");
            await expect(surface).toHaveCSS("padding-top", width < 640 ? "16px" : "24px");
            const form = page.locator("form[wire\\:submit]").first();
            await expect(form).toHaveAttribute("novalidate", "");
            for (const control of await surface.locator("input:visible, select:visible").all()) {
                await expectAccountControl(control);
            }
            for (const action of await surface.locator("button:visible, a:visible").all()) {
                expect((await action.boundingBox()).height).toBeGreaterThanOrEqual(44);
                await expect(action).toHaveCSS("font-size", "16px");
            }
            expect(
                await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
            ).toBe(true);
            await page.screenshot({
                path: testInfo.outputPath(
                    `account-${path.slice(1).replaceAll("/", "-")}-${width}.png`,
                ),
                fullPage: true,
            });

            if (path === "/business/profile") {
                const name = page.getByLabel("Nombre del negocio", {
                    exact: true,
                });
                const save = page.getByRole("button", {
                    name: "Guardar negocio",
                    exact: true,
                });
                await expect(save).toHaveCSS("background-color", "rgb(167, 123, 255)");
                await expect(save).toHaveCSS("color", "rgb(23, 19, 31)");
                await name.fill("Unsaved Business Name");
                await expect(eyebrow).toHaveText("Account Style Fixture · Tu negocio");
                const back = page.getByRole("link", {
                    name: "Volver al resumen",
                    exact: true,
                });
                expect(
                    await back.evaluate((element) =>
                        Boolean(element.nextElementSibling?.matches('button[type="submit"]')),
                    ),
                ).toBe(true);
                await expect(save.locator("..")).toHaveCSS("justify-content", "flex-end");
                const primaryBounds = await save.boundingBox();
                const secondaryBounds = await back.boundingBox();
                expect(primaryBounds.x + primaryBounds.width).toBeGreaterThanOrEqual(
                    secondaryBounds.x + secondaryBounds.width,
                );
                await name.fill("");
                const rejected = page.waitForResponse(
                    (response) =>
                        response.request().method() === "POST" &&
                        /\/livewire[^/]*\/update/.test(response.url()),
                );
                await save.click();
                expect((await rejected).ok()).toBe(true);
                await expect(
                    page.locator('[data-flux-toast-dialog][data-variant="danger"]').last(),
                ).toContainText("Revisa los campos indicados");
                await page
                    .locator('[data-flux-toast-dialog][data-variant="danger"]')
                    .last()
                    .getByRole("button")
                    .click();
                await expect(name).toHaveAttribute("aria-invalid", "true");
                await expect(eyebrow).toHaveText("Account Style Fixture · Tu negocio");
                await expect(surface.locator("[data-flux-error]").first()).toHaveCSS(
                    "color",
                    "rgb(255, 180, 190)",
                );
                await name.fill("Updated Account Business");
                await page
                    .getByLabel("Zona horaria", { exact: true })
                    .selectOption("Europe/Madrid");
                await expect(page.locator("#business-timezone-name")).toContainText(
                    "Europa central",
                );
                await save.click();
                await expect(page).toHaveURL(/\/dashboard$/);
                await expect(
                    page.locator('[data-flux-toast-dialog][data-variant="success"]').last(),
                ).toContainText("Negocio actualizado.");
                await page.goto(path);
                await expect(name).toHaveValue("Updated Account Business");
                await expect(eyebrow).toHaveText("Updated Account Business · Tu negocio");
                await expect(page.getByLabel("Zona horaria", { exact: true })).toHaveValue(
                    "Europe/Madrid",
                );
            } else if (path === "/settings/profile") {
                const save = page.getByRole("button", {
                    name: "Guardar",
                    exact: true,
                });
                await expect(save).toHaveCSS("background-color", "rgb(167, 123, 255)");
                const ownerName = page.getByLabel("Nombre", { exact: true });
                await ownerName.fill("");
                const rejected = page.waitForResponse(
                    (response) =>
                        response.request().method() === "POST" &&
                        /\/livewire[^/]*\/update/.test(response.url()),
                );
                await save.click();
                expect((await rejected).ok()).toBe(true);
                await expect(ownerName).toHaveAttribute("aria-invalid", "true");
                await expect(
                    page.locator('[data-flux-toast-dialog][data-variant="danger"]').last(),
                ).toContainText("Revisa los campos indicados");
                await page
                    .locator('[data-flux-toast-dialog][data-variant="danger"]')
                    .last()
                    .getByRole("button")
                    .click();
                await ownerName.fill("Updated Account Owner");
                await expect(eyebrow).toHaveText("Updated Account Business · Tu negocio");
                await expect(save.locator("..")).toHaveCSS("justify-content", "flex-end");
                await save.click();
                await expect(page.getByText("Perfil actualizado.", { exact: true })).toBeVisible();
                await page.reload();
                await expect(page.getByLabel("Nombre", { exact: true })).toHaveValue(
                    "Updated Account Owner",
                );

                const remove = page.getByRole("button", {
                    name: "Eliminar cuenta",
                    exact: true,
                });
                await expect(remove).toHaveCSS("background-color", "oklch(0.577 0.245 27.325)");
                await expect(remove).toHaveCSS("color", "rgb(255, 255, 255)");
                await remove.click();
                const dialog = page.getByRole("dialog");
                await expect(dialog).toBeVisible();
                await expect(dialog).toHaveCSS("background-color", "rgb(46, 46, 46)");
                const confirmDelete = dialog.getByRole("button", {
                    name: "Eliminar cuenta",
                    exact: true,
                });
                await expect(confirmDelete).toHaveCSS(
                    "background-color",
                    "oklch(0.577 0.245 27.325)",
                );
                await expect(confirmDelete).toHaveCSS("color", "rgb(255, 255, 255)");
                await expect(dialog.locator("form")).toHaveAttribute("novalidate", "");
                expect(
                    await confirmDelete.evaluate(
                        (element) => element.parentElement.lastElementChild === element,
                    ),
                ).toBe(true);
                await expect(dialog.locator("[data-flux-icon]").first()).toBeVisible();
                await expectAccountControl(dialog.getByLabel("Contraseña", { exact: true }));
                for (const button of await dialog.getByRole("button").all()) {
                    await expect
                        .poll(async () => (await button.boundingBox()).height)
                        .toBeGreaterThanOrEqual(44);
                    await expect
                        .poll(async () => (await button.boundingBox()).width)
                        .toBeGreaterThanOrEqual(44);
                }
                expect(
                    await dialog.evaluate((element) => element.scrollWidth <= element.clientWidth),
                ).toBe(true);
                await page.screenshot({
                    path: testInfo.outputPath(`delete-account-${width}.png`),
                    fullPage: true,
                });
                await dialog.getByRole("button", { name: "Cancelar", exact: true }).click();
                await expect(dialog).not.toBeVisible();
                await expect(remove).toBeFocused();
                await page.reload();
                await expect(page.getByLabel("Nombre", { exact: true })).toHaveValue(
                    "Updated Account Owner",
                );
            } else {
                await expect(
                    page.getByText("Autenticación de dos factores", {
                        exact: true,
                    }),
                ).toHaveCount(0);
                await expect(page.getByText("Claves de acceso", { exact: true })).toHaveCount(0);
                const current = page.getByLabel("Contraseña actual", {
                    exact: true,
                });
                const save = page.getByRole("button", {
                    name: "Guardar",
                    exact: true,
                });
                const rejected = page.waitForResponse(
                    (response) =>
                        response.request().method() === "POST" &&
                        /\/livewire[^/]*\/update/.test(response.url()),
                );
                await save.click();
                expect((await rejected).ok()).toBe(true);
                await expect(current).toHaveAttribute("aria-invalid", "true");
                await expect(
                    page.locator('[data-flux-toast-dialog][data-variant="danger"]').last(),
                ).toContainText("Revisa los campos indicados");
                await page
                    .locator('[data-flux-toast-dialog][data-variant="danger"]')
                    .last()
                    .getByRole("button")
                    .click();
                await expect(save.locator("..")).toHaveCSS("justify-content", "flex-end");
                await current.fill(PASSWORD);
                await current.press("Tab");
                const toggle = page
                    .getByRole("button", {
                        name: "Mostrar u ocultar contraseña",
                        exact: true,
                    })
                    .first();
                await expect(toggle).toBeFocused();
                await expect(toggle).toHaveCSS("outline-color", "rgb(205, 176, 255)");
                await page.keyboard.press("Space");
                await expect(current).toHaveAttribute("type", "text");
                await expect(current).toHaveValue(PASSWORD);
                await page.keyboard.press("Space");
                await expect(current).toHaveAttribute("type", "password");
                await current.fill("WrongPassword84!strong");
                await page
                    .getByLabel("Nueva contraseña", { exact: true })
                    .fill("ReplacementPassword84!strong");
                await page
                    .getByLabel("Confirmar contraseña", { exact: true })
                    .fill("ReplacementPassword84!strong");
                await save.click();
                await expect(current).toHaveAttribute("aria-invalid", "true");
                await expect(
                    page.locator('[data-flux-toast-dialog][data-variant="danger"]').last(),
                ).toContainText("Revisa los campos indicados");
                await page
                    .locator('[data-flux-toast-dialog][data-variant="danger"]')
                    .last()
                    .getByRole("button")
                    .click();
                await expect(surface.locator("[data-flux-error]").first()).toHaveCSS(
                    "color",
                    "rgb(255, 180, 190)",
                );
                await current.fill(PASSWORD);
                await page
                    .getByLabel("Nueva contraseña", { exact: true })
                    .fill("ReplacementPassword84!strong");
                await page
                    .getByLabel("Confirmar contraseña", { exact: true })
                    .fill("ReplacementPassword84!strong");
                await save.click();
                await expect(
                    page.getByText("Contraseña actualizada.", { exact: true }),
                ).toBeVisible();
                await expect(current).toHaveValue("");
            }
        }
        await page.goto("/settings/profile");
        await page.getByRole("button", { name: "Eliminar cuenta", exact: true }).click();
        const deletion = page.getByRole("dialog");
        await deletion.getByRole("button", { name: "Eliminar cuenta", exact: true }).click();
        await expect(deletion.locator("[data-flux-error]").first()).toBeVisible();
        await expect(
            page.locator('[data-flux-toast-dialog][data-variant="danger"]').last(),
        ).toContainText("Revisa los campos indicados");
        await deletion
            .getByLabel("Contraseña", { exact: true })
            .fill("ReplacementPassword84!strong");
        await deletion.getByRole("button", { name: "Eliminar cuenta", exact: true }).click();
        await expect(
            page.locator('[data-flux-toast-dialog][data-variant="danger"]').last(),
        ).toContainText("No pudimos completar la operación.");
        await expect(
            page.locator('[data-flux-toast-dialog][data-variant="danger"]').last(),
        ).toBeVisible();
        await expect(page.locator("body")).not.toContainText(
            /SQLSTATE|QueryException|avatar-preview-db/,
        );
        await page.screenshot({
            path: testInfo.outputPath(`account-delete-restricted-${width}.png`),
            fullPage: true,
        });
        await expect(page).toHaveURL(/\/settings\/profile$/);
        await deletion.getByRole("button", { name: "Cancelar", exact: true }).click();
        await page.reload();
        await expect(page.getByLabel("Nombre", { exact: true })).toHaveValue(
            "Updated Account Owner",
        );
        await page.goto("/business/profile");
        await expect(page.getByLabel("Nombre del negocio", { exact: true })).toHaveValue(
            "Updated Account Business",
        );
    });
}

for (const width of [375, 768, 1280, 1440]) {
    test(`settings navigation stays outside Business-sized form cards at ${width}px`, async ({
        page,
        request,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 900 });
        await registerAccount(page, request);
        await page.goto("/business/profile");
        let businessWidth;

        for (const [path, label] of [
            ["/business/profile", "Perfil del negocio"],
            ["/settings/profile", "Perfil"],
            ["/settings/security", "Seguridad"],
            ["/business/profile", "Perfil del negocio"],
        ]) {
            if (businessWidth !== undefined) {
                await page
                    .getByRole("navigation", {
                        name: "Configuración",
                        exact: true,
                    })
                    .getByRole("link", { name: label, exact: true })
                    .click();
                if (path.endsWith("security")) {
                    await page.getByLabel("Contraseña", { exact: true }).fill(PASSWORD);
                    await page.getByRole("button", { name: "Confirmar", exact: true }).click();
                }
            }
            await expect(page).toHaveURL(new RegExp(`${path}$`));
            await expect(
                page.getByRole("heading", {
                    level: 1,
                    name: "Configuración",
                    exact: true,
                }),
            ).toBeVisible();
            const card = page.locator('[data-test="settings-surface"]');
            const navigation = page.getByRole("navigation", {
                name: "Configuración",
                exact: true,
            });
            await expect(navigation.getByRole("link")).toHaveText([
                "Perfil del negocio",
                "Perfil",
                "Seguridad",
            ]);
            await expect(
                navigation.getByRole("link", { name: label, exact: true }),
            ).toHaveAttribute("data-current", "");
            expect(await card.evaluate((element) => element.querySelector("nav") === null)).toBe(
                true,
            );
            if (path === "/business/profile") {
                await expect(
                    card.getByRole("heading", {
                        name: "Perfil del negocio",
                        exact: true,
                    }),
                ).toBeVisible();
                await expect(
                    card.getByText("Edita el nombre y la zona horaria de tu negocio.", {
                        exact: true,
                    }),
                ).toBeVisible();
            }
            const bounds = await card.boundingBox();
            const navBounds = await navigation.boundingBox();
            businessWidth ??= bounds.width;
            expect(bounds.width).toBe(businessWidth);
            expect(bounds.width).toBeLessThanOrEqual(672);
            if (width >= 1024) {
                expect(navBounds.x + navBounds.width).toBeLessThan(bounds.x);
            } else {
                expect(navBounds.y + navBounds.height).toBeLessThan(bounds.y);
            }
            expect(
                await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
            ).toBe(true);
            await page.screenshot({
                path: testInfo.outputPath(
                    `settings-card-${path.slice(1).replaceAll("/", "-")}-${width}.png`,
                ),
                fullPage: true,
            });
        }
    });
}
