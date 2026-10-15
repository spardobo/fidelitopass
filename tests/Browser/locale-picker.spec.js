import { expect, test } from "@playwright/test";

test.use({ locale: "en-US", timezoneId: "Pacific/Kiritimati" });

test("selection-only regional date and time controls", async ({ page }) => {
    test.skip(!process.env.PLAYWRIGHT_PROMOTION_FIXTURE, "Requires isolated Promotion fixtures");
    const fixture = JSON.parse(process.env.PLAYWRIGHT_PROMOTION_FIXTURE);
    await page.goto("/promotions/create");
    await page.locator('input[name="email"]').fill(fixture.email);
    await page.locator('input[name="password"]').fill("ValidPassword84!strong");
    await page.getByRole("button", { name: "Iniciar sesión", exact: true }).click();
    await expect(page).toHaveURL(/\/promotions\/create$/);

    const updates = [];
    page.on("request", (request) => {
        if (
            request.method() === "POST" &&
            /\/livewire-[^/]+\/update$/.test(new URL(request.url()).pathname)
        )
            updates.push(request.url());
    });
    await page.getByLabel("¿Cuántos puntos necesita?").fill("15");
    await expect(page.locator('[data-test="summary-target"]')).toHaveText("15 puntos");
    const date = page.getByLabel("Fecha de inicio");
    await expect(date).toHaveJSProperty("readOnly", true);
    await expect(date).toBeEnabled();
    await date.click();
    const calendar = page.locator(".flatpickr-calendar.open");
    await expect(calendar.getByLabel("Mes", { exact: true })).toBeVisible();
    await calendar
        .locator(".flatpickr-day:not(.flatpickr-disabled):not(.prevMonthDay):not(.nextMonthDay)")
        .first()
        .click();
    const iso = await page
        .locator('[data-date-time-picker="date"]')
        .first()
        .locator('input[type="hidden"]')
        .inputValue();
    const expected = await page.evaluate(
        (value) =>
            new Intl.DateTimeFormat(document.documentElement.lang, { timeZone: "UTC" }).format(
                new Date(`${value}T12:00:00Z`),
            ),
        iso,
    );
    await expect(date).toHaveValue(expected);
    const end = page.getByLabel("Fecha de fin");
    await end.click();
    await page
        .locator(
            ".flatpickr-calendar.open .flatpickr-day:not(.flatpickr-disabled):not(.prevMonthDay):not(.nextMonthDay)",
        )
        .first()
        .click();
    await expect(end).toHaveValue(expected);
    await expect(page.locator('[data-test="summary-validity"]')).toHaveText(
        `${expected} – ${expected}`,
    );
    const modelDate = await page
        .locator('[data-date-time-picker="date"]')
        .first()
        .locator('input[type="hidden"]')
        .evaluate(
            (input) =>
                window.Livewire.find(input.closest("[wire\\:id]").getAttribute("wire:id"))
                    .localStartDate,
        );
    expect(modelDate).toBe(iso);
    expect(updates).toEqual([]);
    await date.press("1");
    await expect(date).toHaveValue(expected);

    await page.getByLabel("Día", { exact: true }).selectOption("3");
    await page.getByLabel("Puntos por visita", { exact: true }).selectOption("3");
    await page.waitForTimeout(500);
    expect(updates).toEqual([]);

    await page.getByLabel("Por horario", { exact: true }).check();
    const time = page.getByLabel("Hora de inicio", { exact: true });
    await expect(time).toHaveJSProperty("readOnly", true);
    await time.click();
    const clock = page.locator(".flatpickr-calendar.open");
    await expect(clock.locator(".flatpickr-am-pm")).toHaveCount(0);
    await expect(clock.getByLabel("Hora", { exact: true })).toHaveAttribute("min", "0");
    await expect(clock.getByLabel("Hora", { exact: true })).toHaveAttribute("max", "23");
    await expect(clock.getByLabel("Minuto", { exact: true })).toHaveAttribute("max", "59");
    const beforeTimeChange = updates.length;
    await clock.getByLabel("Hora", { exact: true }).fill("15");
    await clock.getByLabel("Minuto", { exact: true }).fill("17");
    await clock.getByLabel("Minuto", { exact: true }).press("Tab");
    await expect(time).toHaveValue("15:17");
    await page.waitForTimeout(500);
    expect(updates).toHaveLength(beforeTimeChange);

    await page.evaluate(async () => {
        const root = document.querySelector("main").closest("[wire\\:id]");
        await window.Livewire.find(root.getAttribute("wire:id")).$refresh();
    });
    await expect(date).toHaveValue(expected);
    await expect(time).toHaveValue("15:17");
    await page.getByLabel("Todo el día", { exact: true }).check();
    await expect(page.locator(".flatpickr-calendar")).toHaveCount(2);
    await page.evaluate(() => window.Livewire.navigate("/settings/appearance"));
    await expect(page).toHaveURL(/\/settings\/appearance$/);
    await expect(page.locator(".flatpickr-calendar")).toHaveCount(0);

    await page.goto("/pass");
    const drafts = page.locator('details[wire\\:key="promotion-drafts-disclosure"]');
    if ((await drafts.getAttribute("open")) === null) await drafts.locator("summary").click();
    await drafts.locator('li[data-promotion-phase="draft"]').first().getByRole("link").click();
    await expect(page).toHaveURL(/\/promotions\/[^/]+\/edit$/);
    for (const label of ["Fecha de inicio", "Fecha de fin"]) {
        const field = page.getByLabel(label);
        const isoDate = await field.evaluate(
            (input, name) =>
                window.Livewire.find(input.closest("[wire\\:id]").getAttribute("wire:id"))[name],
            label === "Fecha de inicio" ? "localStartDate" : "localEndDate",
        );
        expect(isoDate).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        if (label === "Fecha de inicio")
            expect(isoDate < (await field.getAttribute("min"))).toBe(true);
        await expect(field.locator("..").locator('input[type="hidden"]')).toHaveValue(isoDate);
        const text = await page.evaluate(
            (value) =>
                new Intl.DateTimeFormat(document.documentElement.lang, { timeZone: "UTC" }).format(
                    new Date(`${value}T12:00:00Z`),
                ),
            isoDate,
        );
        await expect(field).toHaveValue(text);
    }
});
