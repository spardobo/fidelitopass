import "./native-validation.js";
import { formatRegionalDate, formatRegionalRange } from "./regional-dates.js";

document.addEventListener("alpine:init", () => {
    window.Alpine.magic("regionalDates", () => ({
        date: formatRegionalDate,
        range: formatRegionalRange,
    }));
});
