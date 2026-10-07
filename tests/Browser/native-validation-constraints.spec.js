import { expect, test } from "@playwright/test";

const REQUIRED_MESSAGE = "Completa este campo.";
const EMAIL_MESSAGE = "Introduce una dirección de correo electrónico válida.";
const URL_MESSAGE = "Introduce una URL válida.";
const PATTERN_MESSAGE = "Introduce un valor que coincida con el formato solicitado.";
const STEP_MESSAGE = "Introduce un valor que respete el incremento permitido.";
const BAD_INPUT_MESSAGE = "Introduce un valor válido para este campo.";

const ALLOWED_GUEST_METHODS = ["GET", "HEAD"];

const CONSTRAINT_CASES = [
    {
        name: "URL",
        attributes: {
            type: "url",
        },
        invalidValue: "invalid",
        validValue: "https://example.test",
        expectedValidityFlag: "typeMismatch",
        expectedMessage: URL_MESSAGE,
    },
    {
        name: "pattern",
        attributes: {
            pattern: "[A-Z]{3}",
        },
        invalidValue: "abc",
        validValue: "ABC",
        expectedValidityFlag: "patternMismatch",
        expectedMessage: PATTERN_MESSAGE,
    },
    {
        name: "minimum",
        attributes: {
            type: "number",
            min: "2",
            max: "10",
            step: "2",
        },
        invalidValue: "0",
        validValue: "2",
        expectedValidityFlag: "rangeUnderflow",
        expectedMessage: "Introduce un valor mayor o igual que 2.",
    },
    {
        name: "maximum",
        attributes: {
            type: "number",
            min: "2",
            max: "10",
            step: "2",
        },
        invalidValue: "12",
        validValue: "10",
        expectedValidityFlag: "rangeOverflow",
        expectedMessage: "Introduce un valor menor o igual que 10.",
    },
    {
        name: "step",
        attributes: {
            type: "number",
            min: "2",
            max: "10",
            step: "2",
        },
        invalidValue: "3",
        validValue: "4",
        expectedValidityFlag: "stepMismatch",
        expectedMessage: STEP_MESSAGE,
    },
    {
        name: "default step base",
        attributes: {
            type: "number",
        },
        invalidValue: "0.5",
        validValue: "1",
        expectedValidityFlag: "stepMismatch",
        expectedMessage: STEP_MESSAGE,
    },
    {
        name: "value step base",
        attributes: {
            type: "number",
            value: "0.5",
            step: "2",
        },
        invalidValue: "2",
        validValue: "2.5",
        expectedValidityFlag: "stepMismatch",
        expectedMessage: STEP_MESSAGE,
    },
    {
        name: "range before step",
        attributes: {
            type: "number",
            min: "2",
            step: "2",
        },
        invalidValue: "1",
        validValue: "2",
        expectedValidityFlag: "rangeUnderflow",
        expectedMessage: "Introduce un valor mayor o igual que 2.",
        simultaneousValidityFlags: {
            stepMismatch: true,
        },
    },
    {
        name: "type before pattern",
        attributes: {
            type: "email",
            pattern: ".+@example[.]test",
        },
        invalidValue: "invalid",
        validValue: "a@example.test",
        expectedValidityFlag: "typeMismatch",
        expectedMessage: EMAIL_MESSAGE,
        simultaneousValidityFlags: {
            patternMismatch: true,
        },
    },
];

const REQUIRED_CONTROL_TYPES = ["checkbox", "radio", "file"];

const BARRED_CONTROL_ATTRIBUTES = [
    {
        disabled: "",
    },
    {
        readonly: "",
    },
    {
        type: "hidden",
    },
];

const LENGTH_CONTROL_TAGS = ["input", "textarea"];

test.use({
    locale: "en-US",
});

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

// the native matrix appends controls to the real page
// without inventing app flows.
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
        {
            tag,
            attributes,
        },
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

async function expectValidity(control, expected) {
    expect(await reportValidity(control)).toMatchObject(expected);
}

for (const {
    name,
    attributes,
    invalidValue,
    validValue,
    expectedValidityFlag,
    expectedMessage,
    simultaneousValidityFlags = {},
} of CONSTRAINT_CASES) {
    test(`native ${name} message localizes and recovers`, async ({ page }) => {
        await page.goto("/login");

        const control = await appendControl(page, "input", attributes);

        await control.fill(invalidValue);

        await expectValidity(control, {
            valid: false,
            [expectedValidityFlag]: true,
            ...simultaneousValidityFlags,
            customError: true,
            message: expectedMessage,
        });

        await control.fill(validValue);

        await expectValidity(control, {
            valid: true,
            customError: false,
            message: "",
        });
    });
}

for (const type of REQUIRED_CONTROL_TYPES) {
    test(`required ${type} uses native missing flag and recovers`, async ({ page }) => {
        await page.goto("/login");

        const control = await appendControl(page, "input", {
            type,
            name: "native-required",
            required: "",
        });

        await expectValidity(control, {
            valid: false,
            missing: true,
            message: REQUIRED_MESSAGE,
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

        await expectValidity(control, {
            valid: true,
            customError: false,
            message: "",
        });
    });
}

for (const attributes of BARRED_CONTROL_ATTRIBUTES) {
    test(`barred control ${JSON.stringify(attributes)} remains untouched`, async ({ page }) => {
        await page.goto("/login");

        const control = await appendControl(page, "input", {
            required: "",
            ...attributes,
        });

        expect(await control.evaluate((element) => element.willValidate)).toBe(false);

        await expectValidity(control, {
            valid: true,
            customError: false,
            message: "",
        });

        // even an unrelated synthetic invalid event
        // must not create a localization error.
        await control.dispatchEvent("invalid");

        await expectValidity(control, {
            valid: true,
            customError: false,
            message: "",
        });
    });
}

for (const tag of LENGTH_CONTROL_TAGS) {
    test(`native ${tag} length constraints use real user edits`, async ({ page }) => {
        await page.goto("/login");

        const control = await appendControl(page, tag, {
            minlength: "3",
            maxlength: "5",
        });

        await control.pressSequentially("ab");

        await expectValidity(control, {
            valid: false,
            tooShort: true,
            message: "Introduce al menos 3 caracteres.",
        });

        await control.pressSequentially("c");

        await expectValidity(control, {
            valid: true,
            tooShort: false,
        });

        await control.pressSequentially("def");

        await expect(control).toHaveValue("abcde");

        await expectValidity(control, {
            valid: true,
            tooLong: false,
        });

        // chromium prevents typing beyond maxlength,
        // but tightening the limit after a real edit
        // exposes native tooLong without fabricated flags.
        await control.evaluate((element) => {
            element.maxLength = 4;
        });

        await expectValidity(control, {
            valid: false,
            tooLong: true,
            message: "Introduce como máximo 4 caracteres.",
        });

        await control.press("Backspace");

        await expectValidity(control, {
            valid: true,
            customError: false,
        });
    });
}

test("number badInput from keyboard takes precedence over missing value", async ({ page }) => {
    await page.goto("/login");

    const control = await appendControl(page, "input", {
        type: "number",
        required: "",
    });

    await control.pressSequentially("-");

    await expectValidity(control, {
        valid: false,
        badInput: true,
        missing: true,
        message: BAD_INPUT_MESSAGE,
    });

    await control.fill("2");

    await expectValidity(control, {
        valid: true,
        customError: false,
        message: "",
    });
});

test("repeat invalid reports reselect changed native constraints and preserve replacements", async ({
    page,
}) => {
    await page.goto("/login");

    const control = await appendControl(page, "input", {
        type: "number",
        min: "2",
    });

    await control.fill("1");

    await expectValidity(control, {
        rangeUnderflow: true,
        message: "Introduce un valor mayor o igual que 2.",
    });

    await control.evaluate((element) => {
        element.min = "3";
    });

    await expectValidity(control, {
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

    const control = await appendControl(page, "input", {
        required: "",
    });

    expect((await reportValidity(control)).message).toBe(REQUIRED_MESSAGE);

    await control.evaluate((element) => {
        element.disabled = true;
    });

    await control.dispatchEvent("input");

    expect((await reportValidity(control)).valid).toBe(true);

    await control.evaluate((element) => {
        element.disabled = false;
    });

    await control.fill("corrected");

    await expectValidity(control, {
        valid: true,
        customError: false,
        message: "",
    });
});

test("replacement nodes and valid controls do not acquire stale custom errors", async ({
    page,
}) => {
    await page.goto("/login");

    const control = await appendControl(page, "input", {
        type: "email",
        required: "",
    });

    await reportValidity(control);

    await control.evaluate((element) => element.replaceWith(element.cloneNode()));

    await control.fill("valid@example.test");

    await control.dispatchEvent("invalid");

    await expectValidity(control, {
        valid: true,
        customError: false,
        message: "",
    });

    await control.fill("<invalid>");

    await expectValidity(control, {
        typeMismatch: true,
        message: EMAIL_MESSAGE,
    });

    expect(await control.inputValue()).toBe("<invalid>");
});
