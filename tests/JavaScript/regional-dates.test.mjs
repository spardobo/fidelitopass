import assert from "node:assert/strict";
import { test } from "node:test";
import { formatRegionalDate, formatRegionalRange } from "../../resources/js/regional-dates.js";

test("formats calendar dates as zero-padded MM/DD/YYYY", () => {
    assert.equal(formatRegionalDate("2026-12-09"), "12/09/2026");
    assert.equal(formatRegionalDate("2026-02-01"), "02/01/2026");
    assert.equal(formatRegionalDate("0099-01-02"), "01/02/0099");
});

test("keeps the fixed format regardless of browser language preferences", (context) => {
    const originalLanguages = Object.getOwnPropertyDescriptor(navigator, "languages");
    context.after(() => {
        if (originalLanguages) Object.defineProperty(navigator, "languages", originalLanguages);
        else delete navigator.languages;
    });

    for (const languages of [[], ["en-US"], ["en-GB"], ["de-DE"], ["ar-SA"]]) {
        Object.defineProperty(navigator, "languages", { configurable: true, value: languages });
        assert.equal(formatRegionalDate("2026-12-09"), "12/09/2026");
        assert.equal(formatRegionalRange("2026-12-31", "2027-01-01"), "12/31/2026 – 01/01/2027");
    }
});

test("rejects missing, malformed and impossible calendar dates without rolling over", () => {
    for (const date of [
        null,
        undefined,
        "",
        "2026-2-01",
        "2026-02-29",
        "2024-02-30",
        "1900-02-29",
        "2026-13-01",
        "2026-01-00",
        "0000-01-01",
        "2026-12-09T00:00:00Z",
        "<script>",
    ]) {
        assert.equal(formatRegionalDate(date), "", String(date));
    }
    assert.equal(formatRegionalDate("2024-02-29"), "02/29/2024");
    assert.equal(formatRegionalDate("2000-02-29"), "02/29/2000");
    assert.equal(formatRegionalDate("0099-01-02"), "01/02/0099");
});

test("keeps both years in ranges and leaves incomplete reactive values unformatted", () => {
    assert.equal(formatRegionalRange("2026-12-31", "2027-01-01"), "12/31/2026 – 01/01/2027");
    assert.equal(formatRegionalRange("2026-12-09", "2026-12-16"), "12/09/2026 – 12/16/2026");
    for (const [start, end] of [
        ["", "2026-12-09"],
        ["2026-12-09", null],
        ["2026-02-29", "2026-03-01"],
    ]) {
        assert.equal(formatRegionalRange(start, end), "");
    }
    assert.equal(formatRegionalRange("2026-12-10", "2026-12-17"), "12/10/2026 – 12/17/2026");
});

test("does not reinterpret date-only tokens in the browser timezone", () => {
    const original = process.env.TZ;
    try {
        for (const timezone of [
            "Pacific/Honolulu",
            "Pacific/Kiritimati",
            "America/Sao_Paulo",
            "UTC",
        ]) {
            process.env.TZ = timezone;
            assert.equal(formatRegionalDate("2018-11-04"), "11/04/2018");
            assert.equal(
                formatRegionalRange("2026-12-31", "2027-01-01"),
                "12/31/2026 – 01/01/2027",
            );
        }
    } finally {
        if (original === undefined) delete process.env.TZ;
        else process.env.TZ = original;
    }
});

test("registers shared Alpine presentation at initialization with fixed date formatting", async (context) => {
    const originalDocument = globalThis.document;
    const originalWindow = globalThis.window;
    const originalLanguages = Object.getOwnPropertyDescriptor(navigator, "languages");
    const registrations = [];
    globalThis.document = new EventTarget();
    globalThis.window = {
        Alpine: { magic: (name, provider) => registrations.push({ name, provider }) },
    };
    Object.defineProperty(navigator, "languages", { configurable: true, value: ["en-GB"] });
    context.after(() => {
        globalThis.document = originalDocument;
        globalThis.window = originalWindow;
        if (originalLanguages) Object.defineProperty(navigator, "languages", originalLanguages);
        else delete navigator.languages;
    });

    await import("../../resources/js/app.js");
    assert.equal(registrations.length, 0);
    document.dispatchEvent(new Event("alpine:init"));
    assert.equal(registrations.length, 1);
    assert.equal(registrations[0].name, "regionalDates");
    const presentation = registrations[0].provider();
    assert.equal(presentation.date("2026-12-09"), "12/09/2026");
    assert.equal(presentation.range("2026-12-09", "2026-12-16"), "12/09/2026 – 12/16/2026");
    assert.equal(presentation.range("", "2026-12-16"), "");
});
