import { expect, test } from "@playwright/test";

const mailpit = "http://biz02-mail-84:8025";

async function verificationLink(request, recipient) {
    await expect
        .poll(async () => {
            const response = await request.get(`${mailpit}/api/v1/messages`);
            expect(response.ok()).toBeTruthy();
            const mailbox = await response.json();
            return mailbox.messages?.some((message) =>
                message.To?.some((address) => address.Address === recipient),
            );
        })
        .toBe(true);

    const mailboxResponse = await request.get(`${mailpit}/api/v1/messages`);
    expect(mailboxResponse.ok()).toBeTruthy();
    const mailbox = await mailboxResponse.json();
    const message = mailbox.messages?.find((item) =>
        item.To?.some((address) => address.Address === recipient),
    );
    expect(message, "verification mail in isolated recipient inbox").toBeTruthy();
    const detailResponse = await request.get(`${mailpit}/api/v1/message/${message.ID}`);
    expect(detailResponse.ok()).toBeTruthy();
    const detail = await detailResponse.json();
    const link = detail.Text?.match(/https?:\/\/[^\s<>)]*\/email\/verify\/[^\s<>)]*/)?.[0];
    expect(link, "verification link in isolated recipient message").toBeTruthy();
    return link.replace(/&amp;/g, "&");
}

for (const width of [1280, 375]) {
    test(`verified owner completes onboarding at ${width}px`, async ({ page, request }) => {
        await page.setViewportSize({ width, height: 800 });
        const recipient = `biz02-${width}-${crypto.randomUUID()}@example.test`;
        const business = `Negocio ${width}`;

        await page.goto("/dashboard");
        await expect(page).toHaveURL(/\/login(?:\?|$)/);
        await page.goto("/register");
        await expect(
            page.getByRole("heading", { name: /create an account|crear una cuenta/i }),
        ).toBeVisible();
        await page
            .getByRole("textbox", { name: /name|nombre/i })
            .first()
            .focus();
        await expect(page.getByRole("textbox", { name: /name|nombre/i }).first()).toBeFocused();
        await page
            .getByRole("textbox", { name: /name|nombre/i })
            .first()
            .fill(`Owner ${width}`);
        await page.getByRole("textbox", { name: /email|correo/i }).fill(recipient);
        await page.locator('input[name="password"]').fill("ValidPassword84!strong");
        await page.locator('input[name="password_confirmation"]').fill("ValidPassword84!strong");
        await page.getByRole("button", { name: /create account|crear cuenta/i }).click();

        await expect(page).toHaveURL(/\/email\/verify(?:\?|$)/);
        await page.goto("/dashboard");
        await expect(page).toHaveURL(/\/email\/verify(?:\?|$)/);
        const link = await verificationLink(request, recipient);
        await page.goto(link);
        await expect(page).toHaveURL(/\/business\/onboarding(?:\?|$)/);
        await expect(page.getByRole("heading", { name: "Configurá tu negocio" })).toBeVisible();

        const name = page.getByRole("textbox", { name: "Nombre del negocio" });
        await name.focus();
        await expect(name).toBeFocused();
        await page.keyboard.press("Tab");
        await expect(page.getByLabel("Zona horaria")).toBeFocused();
        await name.fill(business);
        await page.getByLabel("Zona horaria").selectOption("America/Argentina/Buenos_Aires");
        await page.getByRole("button", { name: "Guardar negocio" }).focus();
        await page.keyboard.press("Enter");
        await expect(page).toHaveURL(/\/dashboard(?:\?|$)/);
        await expect(page.getByRole("heading", { name: business })).toBeVisible();
        await expect(page.getByText("Zona horaria: America/Argentina/Buenos_Aires")).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
            true,
        );

        await page.getByRole("link", { name: "Editar perfil del negocio" }).click();
        await expect(page.getByRole("textbox", { name: "Nombre del negocio" })).toHaveValue(
            business,
        );
        await expect(page.getByLabel("Zona horaria")).toHaveValue("America/Argentina/Buenos_Aires");
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
            true,
        );
    });
}
