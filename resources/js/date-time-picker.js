import flatpickr from "flatpickr";
import localeCatalog from "flatpickr/dist/l10n/index.js";

/** Owns one Flatpickr instance and keeps its visible value connected to the local form.
 * @param {string} model Livewire property bound to the original input.
 * @param {object} options Blade-owned picker configuration, application locale and translated accessibility labels.
 * @returns {object} Alpine initialization and disposal hooks; no independent form state or requests.
 */
export function dateTimePicker(model, options) {
    let picker;

    return {
        init() {
            const { locale, labels, ...config } = options;
            const locales = localeCatalog.default ?? localeCatalog;
            const formatter = new Intl.DateTimeFormat(
                locale,
                config.enableTime ? { timeStyle: "short" } : undefined,
            );
            const publish = (instance) =>
                this.$dispatch("picker-display", { model, value: instance.altInput.value });

            picker = flatpickr(this.$refs.input, {
                ...config,
                locale: {
                    ...(locales[locale] ??
                        locales[locale.split("-")[0]] ??
                        flatpickr.l10ns.default),
                    ...labels,
                },
                time_24hr: config.enableTime ? !formatter.resolvedOptions().hour12 : undefined,
                defaultDate: this.$wire[model] || null,
                formatDate: (date, pattern, language) =>
                    pattern === "regional"
                        ? formatter.format(date)
                        : flatpickr.formatDate(date, pattern, language),
                onReady: (_, __, instance) => {
                    instance.altInput.id = instance.input.id;
                    instance.input.id += "-value";
                    for (const attribute of [
                        "aria-labelledby",
                        "aria-describedby",
                        "aria-invalid",
                        "min",
                        "max",
                    ]) {
                        if (instance.input.hasAttribute(attribute)) {
                            instance.altInput.setAttribute(
                                attribute,
                                instance.input.getAttribute(attribute),
                            );
                        }
                    }
                    instance.input.removeAttribute("aria-labelledby");
                    publish(instance);
                },
                onChange: (_, __, instance) => publish(instance),
            });

            this.$watch(`$wire.${model}`, (value) => {
                picker.setDate(value || "", false);
                publish(picker);
            });
        },
        destroy() {
            picker.destroy();
        },
    };
}
