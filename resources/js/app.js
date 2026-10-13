import "./native-validation.js";
import { customerPassAcquisition } from "./customer-pass-acquisition.js";
import { formatRegionalDate, formatRegionalRange } from "./regional-dates.js";
import { passPreviewMotion } from "./pass-preview-motion.js";

document.addEventListener("alpine:init", () => {
    window.Alpine.data("passPreviewMotion", passPreviewMotion);
    window.Alpine.data("customerPassAcquisition", customerPassAcquisition);
    window.Alpine.magic("regionalDates", () => ({
        date: formatRegionalDate,
        range: formatRegionalRange,
    }));
});
