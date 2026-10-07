import { readFileSync } from "node:fs";
import { expect, test } from "@playwright/test";

const REQUIRED_MESSAGE = "Completa este campo.";
const EMAIL_MESSAGE = "Introduce una dirección de correo electrónico válida.";
const MULTIPLE_EMAIL_MESSAGE =
    "Introduce direcciones de correo electrónico válidas separadas por comas.";

const VALID_EMAIL = "owner@example.test";
const PASSWORD = "ValidationPassword-123!";

const ALLOWED_GUEST_METHODS = ["GET", "HEAD"];

const EXPECTED_NATIVE_MESSAGE_KEYS = [
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
];

test.use({ locale: "en-US" });

// guest checks must never create accounts or submit credentials.
test.beforeEach(async ({ page }) => {
    await page.route("**/*", async (route) => {
        const method = route.request().method();

        if (!ALLOWED_GUEST_METHODS.includes(method)) {
            await route.abort();

            throw new Error("Native validation checks must not send mutation requests");
        }

        await route.continue();
    });
});

// additional native constraints use controls on the real guest page,
// not app workflows.
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

async function expectValidity(control, expected) {
    expect(await reportValidity(control)).toMatchObject(expected);
}

async function expectBrowserLocale(page) {
    expect(await page.evaluate(() => navigator.language)).toBe("en-US");
}

test("Laravel active-locale payload is independent of browser language", async ({ page }) => {
    await page.goto("/login");

    await expectBrowserLocale(page);

    await expect(page.locator("html")).toHaveAttribute("lang", "es");

    const messages = await page
        .locator('meta[name="native-validation"]')
        .evaluate((element) => JSON.parse(element.content));

    expect(messages.required).toBe(REQUIRED_MESSAGE);

    expect(messages.email).toBe(EMAIL_MESSAGE);

    expect(Object.keys(messages).sort()).toEqual(EXPECTED_NATIVE_MESSAGE_KEYS);

    // structural evidence only:
    // no safe per-request locale switch exists in this scope.
    const head = readFileSync("resources/views/partials/head.blade.php", "utf8");

    expect(head).toContain("__('validation.native', [], app()->getLocale())");

    expect(head).toContain("content='@json($nativeValidationMessages)'");

    const module = readFileSync("resources/js/native-validation.js", "utf8");

    expect(module).not.toContain("navigator.language");

    expect(module).not.toContain(REQUIRED_MESSAGE);
});

test("required login input localizes the native bubble and clears on correction", async ({
    page,
}) => {
    await page.goto("/login");

    await expectBrowserLocale(page);

    const email = page.getByLabel("Correo electrónico", {
        exact: true,
    });

    await page
        .getByRole("button", {
            name: "Iniciar sesión",
            exact: true,
        })
        .click();

    await expect(email).toBeFocused();

    await expectValidity(email, {
        valid: false,
        missing: true,
        customError: true,
        message: REQUIRED_MESSAGE,
    });

    for (let attempt = 0; attempt < 2; attempt++) {
        await email.fill("not-an-email");

        await expectValidity(email, {
            valid: false,
            missing: false,
            typeMismatch: true,
            customError: true,
            message: EMAIL_MESSAGE,
        });

        await email.fill(VALID_EMAIL);

        await expectValidity(email, {
            valid: true,
            customError: false,
            message: "",
        });

        await email.fill("");

        await expectValidity(email, {
            valid: false,
            message: REQUIRED_MESSAGE,
        });
    }

    await email.fill("not-an-email");

    await expectValidity(email, {
        valid: false,
        missing: false,
        customError: true,
        typeMismatch: true,
        message: EMAIL_MESSAGE,
    });
});

test("required registration text and native select recover without blocking submission", async ({
    page,
}) => {
    await page.goto("/register");

    const name = page.getByLabel("Nombre", {
        exact: true,
    });

    const timezone = page.locator("select[name='timezone']");

    await expect(timezone).toHaveAttribute("required");
    await timezone.selectOption("");

    for (const control of [name, timezone]) {
        await expectValidity(control, {
            valid: false,
            missing: true,
            message: REQUIRED_MESSAGE,
        });
    }

    await name.fill("Native validation fixture");

    await page
        .getByLabel("Correo electrónico", {
            exact: true,
        })
        .fill(VALID_EMAIL);

    await page.locator("input[name='business_name']").fill("Validation fixture");

    await timezone.selectOption("Europe/Madrid");

    await page
        .getByLabel("Contraseña", {
            exact: true,
        })
        .fill(PASSWORD);

    await page
        .getByLabel("Confirmar contraseña", {
            exact: true,
        })
        .fill(PASSWORD);

    await expectValidity(timezone, {
        valid: true,
        customError: false,
        message: "",
    });

    expect(await name.evaluate((element) => element.form.checkValidity())).toBe(true);

    await timezone.selectOption("");

    await expectValidity(timezone, {
        valid: false,
        message: REQUIRED_MESSAGE,
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

        const name = page.getByLabel("Nombre", {
            exact: true,
        });

        await expectValidity(name, {
            valid: false,
            message: REQUIRED_MESSAGE,
        });

        await name.fill("Corrected name");

        await expectValidity(name, {
            valid: true,
            customError: false,
        });

        await page.locator("a[wire\\:navigate][href$='/login']").click();

        await expect(page).toHaveURL(/\/login$/);

        const email = page.getByLabel("Correo electrónico", {
            exact: true,
        });

        await expectValidity(email, {
            valid: false,
            message: REQUIRED_MESSAGE,
        });

        await email.fill(VALID_EMAIL);

        await expectValidity(email, {
            valid: true,
            customError: false,
        });
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

    await expectValidity(textarea, {
        valid: false,
        message: REQUIRED_MESSAGE,
    });

    await textarea.fill("Corrected value");

    await expectValidity(textarea, {
        valid: true,
        customError: false,
    });

    const email = page.getByLabel("Correo electrónico", {
        exact: true,
    });

    await email.evaluate((element) => element.setCustomValidity("Caller-owned constraint"));

    expect((await reportValidity(email)).message).toBe("Caller-owned constraint");

    await email.fill(VALID_EMAIL);

    await expectValidity(email, {
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

        const attributes = {
            type: "email",
        };

        if (multiple) {
            attributes.multiple = "";
        }

        const control = await appendControl(page, attributes);

        await control.fill(multiple ? "a@example.test,invalid" : "invalid");

        await expectValidity(control, {
            valid: false,
            typeMismatch: true,
            customError: true,
            message: multiple ? MULTIPLE_EMAIL_MESSAGE : EMAIL_MESSAGE,
        });

        await control.fill(multiple ? "a@example.test,b@example.test" : "a@example.test");

        await expectValidity(control, {
            valid: true,
            customError: false,
            message: "",
        });
    });
}
