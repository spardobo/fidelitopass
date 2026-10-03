import { expect, test } from "@playwright/test";

const resetForm = "/reset-password/presentation-only-token?email=owner%40example.test";

// these guest fixtures render forms only; no token is redeemed or account created.
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

for (const { path, heading, emailLabel } of [
    { path: "/login", heading: "Iniciar sesión en tu cuenta", emailLabel: "Correo electrónico" },
    { path: "/register", heading: "Crear una cuenta", emailLabel: "Correo electrónico" },
    { path: "/forgot-password", heading: "Recuperar contraseña", emailLabel: "Correo electrónico" },
    { path: resetForm, heading: "Restablecer contraseña", emailLabel: "Correo electrónico" },
]) {
    test(`guest ${path.split("?")[0]} retains narrow dark layout and keyboard field focus`, async ({
        page,
    }) => {
        await page.setViewportSize({ width: 375, height: 800 });
        await page.emulateMedia({ colorScheme: "light" });
        await page.addInitScript(() => localStorage.setItem("flux.appearance", "light"));

        await page.goto(path);
        await expect(page.getByRole("heading", { name: heading, exact: true })).toBeVisible();
        await expect(page.locator("body")).toHaveCSS("background-color", "rgb(36, 36, 36)");
        await page.evaluate(() => document.fonts.ready);
        expect(await page.evaluate(() => document.fonts.check('400 16px "Onest Variable"'))).toBe(
            true,
        );
        await expect(page.locator("body")).toHaveCSS("font-family", /Onest Variable/);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
            true,
        );

        const email = page.getByLabel(emailLabel, { exact: true });
        await expect(email).toBeVisible();
        await email.focus();
        await page.keyboard.press("Tab");
        await page.keyboard.press("Shift+Tab");
        await expect(email).toBeFocused();
        await expect(email).toHaveCSS("outline-style", "solid");
        await expect(email).toHaveCSS("outline-width", "2px");
        await expect(email).toHaveCSS("outline-color", "rgb(167, 123, 255)");
        await expect(email).toHaveCSS("outline-offset", "3px");
    });
}

for (const path of ["/login", "/register", resetForm]) {
    test(`guest ${path.split("?")[0]} password toggles preserve values and keyboard focus`, async ({
        page,
    }) => {
        await page.goto(path);
        const labels = path === "/login" ? ["Contraseña"] : ["Contraseña", "Confirmar contraseña"];
        const toggles = page.getByRole("button", { name: "Mostrar u ocultar contraseña" });
        await expect(toggles).toHaveCount(labels.length);

        for (const [index, label] of labels.entries()) {
            const password = page.getByLabel(label, { exact: true });
            const toggle = toggles.nth(index);
            const value = `EphemeralPassword-${index}!`;
            await password.fill(value);
            await expect(password).toHaveAttribute("type", "password");
            await password.focus();
            await page.keyboard.press("Tab");
            await expect(toggle).toBeFocused();
            await expect(toggle).toHaveCSS("outline-style", "solid");
            await expect(toggle).toHaveCSS("outline-width", "2px");
            await expect(toggle).toHaveCSS("outline-color", "rgb(205, 176, 255)");
            expect((await toggle.boundingBox()).height).toBeGreaterThanOrEqual(44);

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

test("remember label and keyboard toggle the same login checkbox", async ({ page }) => {
    await page.goto("/login");
    const remember = page.getByRole("checkbox", { name: "Recordarme", exact: true });
    await expect(remember).not.toBeChecked();

    await page.getByText("Recordarme", { exact: true }).click();
    await expect(remember).toBeChecked();
    await remember.focus();
    await page.keyboard.press("Shift+Tab");
    await page.keyboard.press("Tab");
    await expect(remember).toBeFocused();
    await expect(remember).toHaveCSS("outline-style", "solid");
    await expect(remember).toHaveCSS("outline-color", "rgb(205, 176, 255)");
    await page.keyboard.press("Space");
    await expect(remember).not.toBeChecked();
    await page.keyboard.press("Space");
    await expect(remember).toBeChecked();
});
