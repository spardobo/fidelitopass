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

// The native matrix appends controls to the real page without inventing app flows.
async function appendControl(page, tag, attributes) {
    await page.evaluate(
        ({ tag, attributes }) => {
            const control = document.createElement(tag);
            control.id = "native-fixture";
            for (const [name, value] of Object.entries(attributes)) {
                control.setAttribute(name, value);
            }
            document.querySelector("form").append(control);
        },
        { tag, attributes },
    );
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
            patternMismatch: element.validity.patternMismatch,
            tooShort: element.validity.tooShort,
            tooLong: element.validity.tooLong,
            rangeUnderflow: element.validity.rangeUnderflow,
            rangeOverflow: element.validity.rangeOverflow,
            stepMismatch: element.validity.stepMismatch,
            badInput: element.validity.badInput,
        };
    });
}

const constraintCases = [
    [
        "URL",
        { type: "url" },
        "invalid",
        "https://example.test",
        "typeMismatch",
        "Introduce una URL válida.",
    ],
    [
        "pattern",
        { pattern: "[A-Z]{3}" },
        "abc",
        "ABC",
        "patternMismatch",
        "Introduce un valor que coincida con el formato solicitado.",
    ],
    [
        "minimum",
        { type: "number", min: "2", max: "10", step: "2" },
        "0",
        "2",
        "rangeUnderflow",
        "Introduce un valor mayor o igual que 2.",
    ],
    [
        "maximum",
        { type: "number", min: "2", max: "10", step: "2" },
        "12",
        "10",
        "rangeOverflow",
        "Introduce un valor menor o igual que 10.",
    ],
    [
        "step",
        { type: "number", min: "2", max: "10", step: "2" },
        "3",
        "4",
        "stepMismatch",
        "Introduce un valor que respete el incremento permitido.",
    ],
    [
        "default step base",
        { type: "number" },
        "0.5",
        "1",
        "stepMismatch",
        "Introduce un valor que respete el incremento permitido.",
    ],
    [
        "value step base",
        { type: "number", value: "0.5", step: "2" },
        "2",
        "2.5",
        "stepMismatch",
        "Introduce un valor que respete el incremento permitido.",
    ],
    [
        "range before step",
        { type: "number", min: "2", step: "2" },
        "1",
        "2",
        "rangeUnderflow",
        "Introduce un valor mayor o igual que 2.",
        { stepMismatch: true },
    ],
    [
        "type before pattern",
        { type: "email", pattern: ".+@example[.]test" },
        "invalid",
        "a@example.test",
        "typeMismatch",
        "Introduce una dirección de correo electrónico válida.",
        { patternMismatch: true },
    ],
];

for (const [
    name,
    attributes,
    invalidValue,
    validValue,
    flag,
    message,
    simultaneousFlags = {},
] of constraintCases) {
    test(`native ${name} message localizes and recovers`, async ({ page }) => {
        await page.goto("/login");
        const control = await appendControl(page, "input", attributes);
        await control.fill(invalidValue);
        expect(await reportValidity(control)).toMatchObject({
            valid: false,
            [flag]: true,
            ...simultaneousFlags,
            customError: true,
            message,
        });
        await control.fill(validValue);
        expect(await reportValidity(control)).toMatchObject({
            valid: true,
            customError: false,
            message: "",
        });
    });
}

for (const type of ["checkbox", "radio", "file"]) {
    test(`required ${type} uses native missing flag and recovers`, async ({ page }) => {
        await page.goto("/login");
        const control = await appendControl(page, "input", {
            type,
            name: "native-required",
            required: "",
        });
        expect(await reportValidity(control)).toMatchObject({
            valid: false,
            missing: true,
            message: requiredMessage,
        });
        if (type === "file") {
            await control.setInputFiles({
                name: "fixture.txt",
                mimeType: "text/plain",
                buffer: Buffer.from("fixture"),
            });
        } else {
            await control.check();
        }
        expect(await reportValidity(control)).toMatchObject({
            valid: true,
            customError: false,
            message: "",
        });
    });
}

for (const attributes of [{ disabled: "" }, { readonly: "" }, { type: "hidden" }]) {
    test(`barred control ${JSON.stringify(attributes)} remains untouched`, async ({ page }) => {
        await page.goto("/login");
        const control = await appendControl(page, "input", { required: "", ...attributes });
        expect(await control.evaluate((element) => element.willValidate)).toBe(false);
        expect(await reportValidity(control)).toMatchObject({
            valid: true,
            customError: false,
            message: "",
        });
        // Even an unrelated synthetic invalid event must not create a localization error.
        await control.dispatchEvent("invalid");
        expect(await reportValidity(control)).toMatchObject({
            valid: true,
            customError: false,
            message: "",
        });
    });
}

for (const tag of ["input", "textarea"]) {
    test(`native ${tag} length constraints use real user edits`, async ({ page }) => {
        await page.goto("/login");
        const control = await appendControl(page, tag, { minlength: "3", maxlength: "5" });
        await control.pressSequentially("ab");
        expect(await reportValidity(control)).toMatchObject({
            valid: false,
            tooShort: true,
            message: "Introduce al menos 3 caracteres.",
        });
        await control.pressSequentially("c");
        expect(await reportValidity(control)).toMatchObject({ valid: true, tooShort: false });
        await control.pressSequentially("def");
        await expect(control).toHaveValue("abcde");
        expect(await reportValidity(control)).toMatchObject({ valid: true, tooLong: false });
        // Chromium prevents typing beyond maxlength, but tightening the limit
        // after a real edit exposes native tooLong without fabricated flags.
        await control.evaluate((element) => (element.maxLength = 4));
        expect(await reportValidity(control)).toMatchObject({
            valid: false,
            tooLong: true,
            message: "Introduce como máximo 4 caracteres.",
        });
        await control.press("Backspace");
        expect(await reportValidity(control)).toMatchObject({ valid: true, customError: false });
    });
}

test("number badInput from keyboard takes precedence over missing value", async ({ page }) => {
    await page.goto("/login");
    const control = await appendControl(page, "input", { type: "number", required: "" });
    await control.pressSequentially("-");
    expect(await reportValidity(control)).toMatchObject({
        valid: false,
        badInput: true,
        missing: true,
        message: "Introduce un valor válido para este campo.",
    });
    await control.fill("2");
    expect(await reportValidity(control)).toMatchObject({
        valid: true,
        customError: false,
        message: "",
    });
});

test("repeat invalid reports reselect changed native constraints and preserve replacements", async ({
    page,
}) => {
    await page.goto("/login");
    const control = await appendControl(page, "input", { type: "number", min: "2" });
    await control.fill("1");
    expect(await reportValidity(control)).toMatchObject({
        rangeUnderflow: true,
        message: "Introduce un valor mayor o igual que 2.",
    });
    await control.evaluate((element) => (element.min = "3"));
    expect(await reportValidity(control)).toMatchObject({
        rangeUnderflow: true,
        message: "Introduce un valor mayor o igual que 3.",
    });
    await control.evaluate((element) => element.setCustomValidity("Caller replacement"));
    expect((await reportValidity(control)).message).toBe("Caller replacement");
    await control.fill("4");
    expect((await reportValidity(control)).message).toBe("Caller replacement");
});

test("temporarily disabled localized control can recover when reenabled", async ({ page }) => {
    await page.goto("/login");
    const control = await appendControl(page, "input", { required: "" });
    expect((await reportValidity(control)).message).toBe(requiredMessage);
    await control.evaluate((element) => (element.disabled = true));
    await control.dispatchEvent("input");
    expect((await reportValidity(control)).valid).toBe(true);
    await control.evaluate((element) => (element.disabled = false));
    await control.fill("corrected");
    expect(await reportValidity(control)).toMatchObject({
        valid: true,
        customError: false,
        message: "",
    });
});

test("replacement nodes and valid controls do not acquire stale custom errors", async ({
    page,
}) => {
    await page.goto("/login");
    const control = await appendControl(page, "input", { type: "email", required: "" });
    await reportValidity(control);
    await control.evaluate((element) => element.replaceWith(element.cloneNode()));
    await control.fill("valid@example.test");
    await control.dispatchEvent("invalid");
    expect(await reportValidity(control)).toMatchObject({
        valid: true,
        customError: false,
        message: "",
    });
    await control.fill("<invalid>");
    expect(await reportValidity(control)).toMatchObject({
        typeMismatch: true,
        message: "Introduce una dirección de correo electrónico válida.",
    });
    expect(await control.inputValue()).toBe("<invalid>");
});
