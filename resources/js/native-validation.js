// Module evaluation and these delegated listeners share the document lifetime,
// including Livewire navigation; replaced controls need no initialization.

const NATIVE_VALIDATION_META_SELECTOR = 'meta[name="native-validation"]';

const localizedErrors = new WeakMap();

function isNativeControl(element) {
    return (
        element instanceof HTMLInputElement ||
        element instanceof HTMLSelectElement ||
        element instanceof HTMLTextAreaElement
    );
}

function clearLocalizedError(event) {
    const control = event.target;
    const ownedMessage = localizedErrors.get(control);

    if (ownedMessage === undefined) {
        return;
    }

    // barred controls hide validationMessage;
    // retain ownership until it is readable.
    if (!control.willValidate) {
        return;
    }

    // preserve a replacement from another caller.
    // an identical replacement cannot be distinguished
    // through the native custom-validity API.
    if (control.validationMessage === ownedMessage) {
        control.setCustomValidity("");
    }

    localizedErrors.delete(control);
}

/**
 * Selects copy from native flags only;
 * never reimplement the browser's constraints.
 */
function nativeMessage(control, messages) {
    const validity = control.validity;

    // deterministic precedence:
    // unusable/missing input, type/format, length,
    // range, then step.
    // for example, badInput can coexist with valueMissing.
    if (validity.badInput) {
        return messages.bad_input;
    }

    if (validity.valueMissing) {
        return messages.required;
    }

    if (validity.typeMismatch) {
        if (control.type === "email") {
            return control.multiple ? messages.email_multiple : messages.email;
        }

        return messages.url;
    }

    if (validity.patternMismatch) {
        return messages.pattern;
    }

    if (validity.tooShort) {
        return messages.too_short.replace(":minlength", () => String(control.minLength));
    }

    if (validity.tooLong) {
        return messages.too_long.replace(":maxlength", () => String(control.maxLength));
    }

    if (validity.rangeUnderflow) {
        return messages.range_underflow.replace(":min", () => control.min);
    }

    if (validity.rangeOverflow) {
        return messages.range_overflow.replace(":max", () => control.max);
    }

    if (validity.stepMismatch) {
        return messages.step;
    }

    return "";
}

function validationMessages() {
    const meta = document.querySelector(NATIVE_VALIDATION_META_SELECTOR);

    return JSON.parse(meta.content);
}

function localizeNativeError(event) {
    const control = event.target;

    if (!isNativeControl(control)) {
        return;
    }

    // Release our custom error before reading
    // the underlying native flags.
    clearLocalizedError(event);

    if (!control.willValidate || control.validity.customError || control.validity.valid) {
        return;
    }

    const message = nativeMessage(control, validationMessages());

    if (!message) {
        return;
    }

    control.setCustomValidity(message);
    localizedErrors.set(control, message);
}

// "invalid" does not bubble; capture preserves native focus
// and the validation bubble.
document.addEventListener("invalid", localizeNativeError, true);

document.addEventListener("input", clearLocalizedError);

document.addEventListener("change", clearLocalizedError);
