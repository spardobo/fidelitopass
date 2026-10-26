/**
 * Copies the public invitation URL, leaving it selected when manual copying is required.
 *
 * @param {HTMLInputElement} input Readonly input holding the canonical public QR URL.
 * @returns {Promise<boolean>} Whether a browser copy operation confirmed success.
 */
async function copyInvitationUrl(input) {
    const document = input.ownerDocument;
    const clipboard = document.defaultView.navigator.clipboard;

    if (clipboard?.writeText) {
        try {
            await clipboard.writeText(input.value);
            return true;
        } catch {
            // preserve the user-activated selection fallback when the modern API is denied
        }
    }

    if (!input.isConnected) {
        return false;
    }

    const previousFocus = document.activeElement;
    input.focus({ preventScroll: true });
    input.select();
    input.setSelectionRange(0, input.value.length);

    try {
        const copied = document.execCommand("copy");

        if (copied) {
            previousFocus?.focus({ preventScroll: true });
        }

        return copied;
    } catch {
        return false;
    }
}

Alpine.data("invitationLink", () => ({
    copying: false,
    copied: false,
    copyFailed: false,

    /**
     * Publishes copy feedback only for this connected invitation control.
     *
     * @returns {Promise<void>} Settles one user-activated copy without a server request.
     */
    async copy() {
        if (this.copying) {
            return;
        }

        this.copying = true;
        this.copied = false;
        this.copyFailed = false;

        const copied = await copyInvitationUrl(this.$refs.url);

        if (!this.$el.isConnected) {
            return;
        }

        this.copied = copied;
        this.copyFailed = !copied;
        this.copying = false;
    },
}));
