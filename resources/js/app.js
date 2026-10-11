import "./native-validation.js";
import { formatRegionalDate, formatRegionalRange } from "./regional-dates.js";
import { passPreviewMotion } from "./pass-preview-motion.js";

document.addEventListener("alpine:init", () => {
    window.Alpine.data("passPreviewMotion", passPreviewMotion);
    window.Alpine.magic("regionalDates", () => ({
        date: formatRegionalDate,
        range: formatRegionalRange,
    }));
});
