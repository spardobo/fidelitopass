import { expect, test } from "@playwright/test";

/** Checks rendered calendar dates against fixed MM/DD/YYYY presentation across browser locales.
 * @param {import('@playwright/test').Locator} dates Semantic date-only time elements within the current surface.
 * @returns {Promise<void>} Resolves when all dates retain their ISO meaning and fixed date text; rejects on mismatch.
 */
async function expectRegionalDates(dates) {
    await expect(dates.first()).toBeVisible();
    await expect
        .poll(() =>
            dates.evaluateAll((elements) =>
                elements.every((element) => {
                    const iso = element.getAttribute("datetime");
                    const expected = iso.replace(/^(\d{4})-(\d{2})-(\d{2})$/, "$2/$3/$1");
                    return element.textContent.trim() === expected;
                }),
            ),
        )
        .toBe(true);
}

for (const locale of ["en-US", "en-GB"]) {
    test.describe(`fixed calendar dates in ${locale}`, () => {
        test.use({ locale, timezoneId: "Pacific/Kiritimati" });

        test("keeps date meaning through navigation, Livewire refresh, detail and reactive preview", async ({
            page,
        }) => {
            test.skip(
                !process.env.PLAYWRIGHT_PROMOTION_FIXTURE,
                "Requires the isolated Promotion runner",
            );
            const fixture = JSON.parse(process.env.PLAYWRIGHT_PROMOTION_FIXTURE);
            const errors = [];
            page.on("pageerror", (error) => errors.push(error.message));

            await page.goto("/login");
            await page.locator('input[name="email"]').fill(fixture.email);
            await page.locator('input[name="password"]').fill("ValidPassword84!strong");
            await page.getByRole("button", { name: "Iniciar sesión", exact: true }).click();
            await expect(page).toHaveURL(/\/dashboard$/);
            await expectRegionalDates(page.locator("main time[datetime]"));

            await page.evaluate(async () => {
                const root = document.querySelector("main").closest("[wire\\:id]");
                await window.Livewire.find(root.getAttribute("wire:id")).$refresh();
            });
            await expectRegionalDates(page.locator("main time[datetime]"));
            await page.getByRole("link", { name: "Ir a Pase", exact: true }).click();
            await expect(page).toHaveURL(/\/pass$/);
            await expectRegionalDates(page.locator("main time[datetime]"));

            await page.locator(`#promotion-detail-trigger-${fixture.promotions.Activa}`).click();
            const detail = page.locator('dialog[data-modal="promotion-detail"]');
            await expect(detail).toBeVisible();
            await expectRegionalDates(detail.locator("time[datetime]"));
            await detail.getByRole("button", { name: "Volver al pase", exact: true }).click();

            await page.goto("/promotions/create");
            const start = page.locator("#local-start-date");
            const end = page.locator("#local-end-date");
            const iso = await start.getAttribute("min");
            await start.fill(iso);
            await end.fill(iso);
            const expected = iso.replace(/^(\d{4})-(\d{2})-(\d{2})$/, "$2/$3/$1");
            const preview = page.locator('[data-test="summary-validity"]');
            await expect(preview).toHaveText(`${expected} – ${expected}`);
            await expect(start).toHaveValue(iso);
            await expect(end).toHaveValue(iso);

            await page.evaluate(async () => {
                const root = document.querySelector("main").closest("[wire\\:id]");
                await window.Livewire.find(root.getAttribute("wire:id")).$refresh();
            });
            await expect(preview).toHaveText(`${expected} – ${expected}`);
            await expect(start).toHaveValue(iso);
            await expect(end).toHaveValue(iso);
            expect(errors).toEqual([]);
        });
    });
}
