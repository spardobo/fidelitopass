import { expect, test } from "@playwright/test";

const timezoneSelect = (page) => page.getByRole("combobox", { name: "Zona horaria", exact: true });

// stub only timezone discovery; keep native Intl formatting available.
async function stubBrowserZone(page, zone) {
    await page.addInitScript((reportedZone) => {
        if (reportedZone === "unavailable") {
            Intl.DateTimeFormat = undefined;
            return;
        }

        const resolvedOptions = Intl.DateTimeFormat.prototype.resolvedOptions;
        Intl.DateTimeFormat.prototype.resolvedOptions = function () {
            if (reportedZone === "throws") throw new Error("Timezone unavailable");
            return { ...resolvedOptions.call(this), timeZone: reportedZone };
        };
    }, zone);
}

async function fillRegistration(page) {
    await page.getByRole("textbox", { name: "Nombre", exact: true }).fill("Owner de prueba");
    await page
        .getByLabel("Correo electrónico", { exact: true })
        .fill("timezone-contract@example.test");
    await page.getByLabel("Nombre del negocio", { exact: true }).fill("Negocio de prueba");
    await page.locator('input[name="password"]').fill("ValidPassword84!strong");
    await page.locator('input[name="password_confirmation"]').fill("DifferentPassword84!strong");
}

test("fresh registration prefills a supported browser timezone", async ({ page }) => {
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await stubBrowserZone(page, "America/Montevideo");
    await page.goto("/register");

    await expect(timezoneSelect(page)).toHaveValue("America/Montevideo");
    await expect(timezoneSelect(page).locator('option[value="America/Montevideo"]')).toHaveText(
        /^Uruguay, Montevideo \(UTC-03:00\)$/,
    );
    await expect(page.locator("#registration-timezone-name")).toHaveText(
        "Hora estándar de Uruguay",
    );
    expect(errors).toEqual([]);
});

for (const zone of ["Mars/Olympus", "US/Eastern", undefined, "throws", "unavailable"]) {
    test(`manual fallback when browser zone is ${zone}`, async ({ page }) => {
        await stubBrowserZone(page, zone);
        await page.goto("/register");

        await expect(timezoneSelect(page)).toHaveValue("");
        await timezoneSelect(page).selectOption("UTC");
        await expect(timezoneSelect(page)).toHaveValue("UTC");
    });
}

test("manual override submits the canonical IANA value without creating an account", async ({
    page,
}) => {
    await stubBrowserZone(page, "America/Montevideo");
    await page.goto("/register");
    await expect(timezoneSelect(page)).toHaveValue("America/Montevideo");
    await timezoneSelect(page).selectOption("Europe/Madrid");
    await expect(page.locator("#registration-timezone-name")).toHaveText("Hora de Europa central");
    await fillRegistration(page);
    await page.route("**/register", async (route) => {
        if (route.request().method() !== "POST") return route.continue();
        await route.fulfill({
            status: 200,
            contentType: "text/plain",
            body: "Intercepted registration",
        });
    });

    const submitted = page.waitForRequest(
        (request) => request.method() === "POST" && new URL(request.url()).pathname === "/register",
    );
    await page.getByRole("button", { name: "Crear cuenta" }).click();
    const payload = new URLSearchParams((await submitted).postData());

    expect(payload.get("timezone")).toBe("Europe/Madrid");
    expect(payload.get("timezone")).not.toContain("UTC+");
});

test("validation retry preserves the manual zone rather than detecting again", async ({ page }) => {
    await stubBrowserZone(page, "America/Montevideo");
    await page.goto("/register");
    await timezoneSelect(page).selectOption("Asia/Kathmandu");
    await fillRegistration(page);

    await page.getByRole("button", { name: "Crear cuenta" }).click();

    await expect(
        page.getByText("La confirmación de contraseña no coincide.", { exact: true }),
    ).toBeVisible();
    await expect(timezoneSelect(page)).toHaveValue("Asia/Kathmandu");
});

test("empty old input stays manual after a server validation retry", async ({ page }) => {
    await stubBrowserZone(page, "America/Montevideo");
    await page.goto("/register");
    await timezoneSelect(page).selectOption("");
    await fillRegistration(page);
    await page.locator("form").evaluate((form) => (form.noValidate = true));

    await page.getByRole("button", { name: "Crear cuenta" }).click();

    await expect(
        page.getByText("El campo zona horaria es obligatorio.", { exact: true }),
    ).toBeVisible();
    await expect(timezoneSelect(page)).toHaveValue("");
});

test("wire navigate initializes each fresh registration without resetting manual changes", async ({
    page,
}) => {
    await stubBrowserZone(page, "America/Montevideo");
    await page.goto("/register");
    for (let visit = 0; visit < 2; visit++) {
        await expect(timezoneSelect(page)).toHaveValue("America/Montevideo");
        await timezoneSelect(page).selectOption("Europe/Madrid");
        await expect(timezoneSelect(page)).toHaveValue("Europe/Madrid");
        await page.getByRole("link", { name: "Iniciar sesión", exact: true }).click();
        await expect(page).toHaveURL(/\/login$/);
        await page.getByRole("link", { name: "Registrarse", exact: true }).click();
        await expect(page).toHaveURL(/\/register$/);
    }
    await expect(timezoneSelect(page)).toHaveValue("America/Montevideo");
});

for (const width of [1280, 375]) {
    test(`native registration timezone is readable and keyboard accessible at ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 800 });
        await page.emulateMedia({ colorScheme: "light" });
        await page.addInitScript(() => localStorage.setItem("flux.appearance", "light"));
        await stubBrowserZone(page, "America/Montevideo");
        await page.goto("/register");
        const timezone = timezoneSelect(page);

        await page.getByLabel("Nombre del negocio", { exact: true }).focus();
        await page.keyboard.press("Tab");
        await expect(timezone).toBeFocused();
        await expect(timezone).toHaveCSS("outline-style", "solid");
        await expect(timezone).toHaveCSS("outline-width", "2px");
        await expect(timezone).toHaveCSS("outline-color", "rgb(167, 123, 255)");
        await page.keyboard.press("ArrowDown");
        await expect(timezone).not.toHaveValue("America/Montevideo");
        await expect(timezone.locator('option[value="America/Argentina/Buenos_Aires"]')).toHaveText(
            /^Argentina, Buenos Aires \(UTC-03:00\)$/,
        );
        expect(await timezone.locator("option").count()).toBeGreaterThan(400);
        await expect(
            page.getByText(
                "Las fechas y los horarios tendrán como referencia la hora local de tu negocio.",
                { exact: true },
            ),
        ).toBeVisible();
        await page.evaluate(() => document.fonts.ready);
        await expect(page.locator("html")).toHaveClass(/\bdark\b/);
        await expect(page.locator("body")).toHaveCSS("font-family", /Onest Variable/);
        expect(
            await page.evaluate(() =>
                [...document.fonts].some(
                    (font) => font.status === "loaded" && font.family.includes("Onest"),
                ),
            ),
        ).toBe(true);
        expect((await timezone.boundingBox()).height).toBeGreaterThanOrEqual(44);
        await expect(timezone).toHaveCSS("font-size", "16px");
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
            true,
        );
        await page.screenshot({
            path: testInfo.outputPath(`registration-timezone-${width}.png`),
            fullPage: true,
        });
    });
}
