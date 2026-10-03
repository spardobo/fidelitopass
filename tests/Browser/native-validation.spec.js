import { readFileSync } from "node:fs";
import { expect, test } from "@playwright/test";

const requiredMessage = "Completa este campo.";

test.use({ locale: "en-US" });

// Guest checks must never create accounts or submit credentials.
test.beforeEach(async ({ page }) => {
    await page.route("**/*", async (route) => {
        if (!["GET", "HEAD"].includes(route.request().method())) {
            await route.abort();
            throw new Error("Native validation checks must not send mutation requests");
        }
        await route.continue();
    });
});

// Additional native constraints use controls on the real guest page, not app workflows.
async function appendControl(page, attributes) {
    await page.evaluate((attributes) => {
        const control = document.createElement("input");
        control.id = "native-fixture";
        for (const [name, value] of Object.entries(attributes)) {
            control.setAttribute(name, value);
        }
        document.querySelector("form").append(control);
    }, attributes);
    return page.locator("#native-fixture");
}

async function reportValidity(control) {
    return control.evaluate((element) => {
        const valid = element.reportValidity();
        return {
            valid,
            message: element.validationMessage,
            missing: element.validity.valueMissing,
            customError: element.validity.customError,
            typeMismatch: element.validity.typeMismatch,
        };
    });
}

test("Laravel active-locale payload is independent of browser language", async ({ page }) => {
    await page.goto("/login");
    expect(await page.evaluate(() => navigator.language)).toBe("en-US");
    await expect(page.locator("html")).toHaveAttribute("lang", "es");
    const messages = await page
        .locator('meta[name="native-validation"]')
        .evaluate((element) => JSON.parse(element.content));
    expect(messages.required).toBe(requiredMessage);
    expect(messages.email).toBe("Introduce una dirección de correo electrónico válida.");
    expect(Object.keys(messages).sort()).toEqual([
        "bad_input",
        "email",
        "email_multiple",
        "pattern",
        "range_overflow",
        "range_underflow",
        "required",
        "step",
        "too_long",
        "too_short",
        "url",
    ]);

    // Structural evidence only: no safe per-request locale switch exists in this scope.
    const head = readFileSync("resources/views/partials/head.blade.php", "utf8");
    expect(head).toContain("__('validation.native', [], app()->getLocale())");
    expect(head).toContain("content='@json($nativeValidationMessages)'");
    const module = readFileSync("resources/js/native-validation.js", "utf8");
    expect(module).not.toContain("navigator.language");
    expect(module).not.toContain(requiredMessage);
});

test("required login input localizes the native bubble and clears on correction", async ({
    page,
}) => {
    await page.goto("/login");
    expect(await page.evaluate(() => navigator.language)).toBe("en-US");
    const email = page.getByLabel("Correo electrónico", { exact: true });

    await page.getByRole("button", { name: "Iniciar sesión", exact: true }).click();
    await expect(email).toBeFocused();
    expect(await reportValidity(email)).toMatchObject({
        valid: false,
        missing: true,
        customError: true,
        message: requiredMessage,
    });

    for (let attempt = 0; attempt < 2; attempt++) {
        await email.fill("not-an-email");
        expect(await reportValidity(email)).toMatchObject({
            valid: false,
            missing: false,
            typeMismatch: true,
            customError: true,
            message: "Introduce una dirección de correo electrónico válida.",
        });
        await email.fill("owner@example.test");
        expect(await reportValidity(email)).toMatchObject({
            valid: true,
            customError: false,
            message: "",
        });
        await email.fill("");
        expect(await reportValidity(email)).toMatchObject({
            valid: false,
            message: requiredMessage,
        });
    }

    await email.fill("not-an-email");
    expect(await reportValidity(email)).toMatchObject({
        valid: false,
        missing: false,
        customError: true,
        typeMismatch: true,
        message: "Introduce una dirección de correo electrónico válida.",
    });
});

test("required registration text and native select recover without blocking submission", async ({
    page,
}) => {
    await page.goto("/register");
    const name = page.getByLabel("Nombre", { exact: true });
    const timezone = page.locator("select[name='timezone']");
    await expect(timezone).toHaveAttribute("required");

    for (const control of [name, timezone]) {
        expect(await reportValidity(control)).toMatchObject({
            valid: false,
            missing: true,
            message: requiredMessage,
        });
    }

    await name.fill("Native validation fixture");
    await page.getByLabel("Correo electrónico", { exact: true }).fill("owner@example.test");
    await page.locator("input[name='business_name']").fill("Validation fixture");
    await timezone.selectOption("Europe/Madrid");
    await page.getByLabel("Contraseña", { exact: true }).fill("ValidationPassword-123!");
    await page.getByLabel("Confirmar contraseña", { exact: true }).fill("ValidationPassword-123!");
    expect(await reportValidity(timezone)).toMatchObject({
        valid: true,
        customError: false,
        message: "",
    });
    expect(await name.evaluate((element) => element.form.checkValidity())).toBe(true);

    await timezone.selectOption("");
    expect(await reportValidity(timezone)).toMatchObject({
        valid: false,
        message: requiredMessage,
    });
    await timezone.selectOption("America/Bogota");
    expect(await name.evaluate((element) => element.form.checkValidity())).toBe(true);
});

test("Livewire navigation keeps native validation working on replaced controls", async ({
    page,
}) => {
    await page.goto("/login");
    await page.evaluate(() => {
        window.validationDocument = document;
    });

    for (let attempt = 0; attempt < 2; attempt++) {
        await page.locator("a[wire\\:navigate][href$='/register']").click();
        await expect(page).toHaveURL(/\/register$/);
        expect(await page.evaluate(() => window.validationDocument === document)).toBe(true);
        const name = page.getByLabel("Nombre", { exact: true });
        expect(await reportValidity(name)).toMatchObject({
            valid: false,
            message: requiredMessage,
        });
        await name.fill("Corrected name");
        expect(await reportValidity(name)).toMatchObject({ valid: true, customError: false });

        await page.locator("a[wire\\:navigate][href$='/login']").click();
        await expect(page).toHaveURL(/\/login$/);
        const email = page.getByLabel("Correo electrónico", { exact: true });
        expect(await reportValidity(email)).toMatchObject({
            valid: false,
            message: requiredMessage,
        });
        await email.fill("owner@example.test");
        expect(await reportValidity(email)).toMatchObject({ valid: true, customError: false });
    }
});

test("dynamic textarea is supported and unrelated custom validity stays owned by its caller", async ({
    page,
}) => {
    await page.goto("/login");
    await page.evaluate(() => {
        const textarea = document.createElement("textarea");
        textarea.required = true;
        textarea.setAttribute("aria-label", "Dynamic validation fixture");
        document.querySelector("form").append(textarea);
    });
    const textarea = page.getByLabel("Dynamic validation fixture");
    expect(await reportValidity(textarea)).toMatchObject({
        valid: false,
        message: requiredMessage,
    });
    await textarea.fill("Corrected value");
    expect(await reportValidity(textarea)).toMatchObject({ valid: true, customError: false });

    const email = page.getByLabel("Correo electrónico", { exact: true });
    await email.evaluate((element) => element.setCustomValidity("Caller-owned constraint"));
    expect((await reportValidity(email)).message).toBe("Caller-owned constraint");
    await email.fill("owner@example.test");
    expect(await reportValidity(email)).toMatchObject({
        valid: false,
        message: "Caller-owned constraint",
    });

    await textarea.fill("");
    await reportValidity(textarea);
    await textarea.evaluate((element) => element.setCustomValidity("Replacement constraint"));
    await textarea.fill("Another correction");
    expect((await reportValidity(textarea)).message).toBe("Replacement constraint");
});

for (const multiple of [false, true]) {
    test(`native ${multiple ? "multiple emails" : "email"} message localizes and recovers`, async ({
        page,
    }) => {
        await page.goto("/login");
        const attributes = { type: "email" };
        if (multiple) attributes.multiple = "";
        const control = await appendControl(page, attributes);
        await control.fill(multiple ? "a@example.test,invalid" : "invalid");
        expect(await reportValidity(control)).toMatchObject({
            valid: false,
            typeMismatch: true,
            customError: true,
            message: multiple
                ? "Introduce direcciones de correo electrónico válidas separadas por comas."
                : "Introduce una dirección de correo electrónico válida.",
        });
        await control.fill(multiple ? "a@example.test,b@example.test" : "a@example.test");
        expect(await reportValidity(control)).toMatchObject({
            valid: true,
            customError: false,
            message: "",
        });
    });
}
