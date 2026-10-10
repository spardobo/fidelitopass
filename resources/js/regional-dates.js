/** Formats a calendar date as MM/DD/YYYY without timezone reinterpretation.
 * @param {string | null | undefined} isoDate Calendar date in YYYY-MM-DD form, not an instant.
 * @returns {string} Zero-padded month/day and four-digit year, or an empty string for missing or invalid dates.
 */
export function formatRegionalDate(isoDate) {
    if (typeof isoDate !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(isoDate)) {
        return "";
    }

    const [year, month, day] = isoDate.split("-").map(Number);
    const calendarDate = new Date(0);
    calendarDate.setUTCFullYear(year, month - 1, day);
    calendarDate.setUTCHours(0, 0, 0, 0);

    if (
        year === 0 ||
        calendarDate.getUTCFullYear() !== year ||
        calendarDate.getUTCMonth() !== month - 1 ||
        calendarDate.getUTCDate() !== day
    ) {
        return "";
    }

    return `${isoDate.slice(5, 7)}/${isoDate.slice(8, 10)}/${isoDate.slice(0, 4)}`;
}

/** Formats inclusive calendar endpoints with the same fixed MM/DD/YYYY presentation.
 * @param {string | null | undefined} startDate Inclusive ISO start date.
 * @param {string | null | undefined} endDate Inclusive ISO end date.
 * @returns {string} Full numeric dates separated by an en dash, or empty when either endpoint is invalid.
 */
export function formatRegionalRange(startDate, endDate) {
    const start = formatRegionalDate(startDate);
    const end = formatRegionalDate(endDate);

    return start && end ? `${start} – ${end}` : "";
}
