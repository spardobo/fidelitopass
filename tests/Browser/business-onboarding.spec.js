import { expect, test } from '@playwright/test';

const mailpit = 'http://fidelitopass-mailpit-dev:8025';

function luminance(color) {
    const channels = color.match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)/)?.slice(1).map(Number);
    expect(channels, `rendered color: ${color}`).toBeTruthy();
    const linear = channels.map((channel) => {
        const value = channel / 255;
        return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });
    return linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
}

function contrast(foreground, background) {
    const light = Math.max(luminance(foreground), luminance(background));
    const dark = Math.min(luminance(foreground), luminance(background));
    return (light + 0.05) / (dark + 0.05);
}

async function expectDarkReadable(page) {
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    const colors = await page.evaluate(() => {
        const body = getComputedStyle(document.body);
        const heading = getComputedStyle(document.querySelector('h1'));
        return { background: body.backgroundColor, text: body.color, heading: heading.color };
    });
    expect(luminance(colors.background)).toBeLessThan(0.2);
    expect(contrast(colors.text, colors.background)).toBeGreaterThanOrEqual(4.5);
    expect(contrast(colors.heading, colors.background)).toBeGreaterThanOrEqual(4.5);
}

async function verificationLink(request, recipient) {
    let message;
    await expect.poll(async () => {
        const response = await request.get(`${mailpit}/api/v1/messages`);
        expect(response.ok()).toBeTruthy();
        const mailbox = await response.json();
        message = mailbox.messages?.find((item) =>
            item.To?.some((address) => address.Address === recipient),
        );
        return Boolean(message);
    }).toBe(true);

    const response = await request.get(`${mailpit}/api/v1/message/${message.ID}`);
    expect(response.ok()).toBeTruthy();
    const detail = await response.json();
    const link = detail.Text?.match(/https?:\/\/[^\s<>)]*\/email\/verify\/[^\s<>)]*/)?.[0];
    expect(link, 'verification link in recipient message').toBeTruthy();
    return link.replace(/&amp;/g, '&');
}

for (const width of [1280, 375]) {
    test(`verified owner completes onboarding and edits business at ${width}px`, async ({ page, request }) => {
        await page.setViewportSize({ width, height: 800 });
        await page.emulateMedia({ colorScheme: 'light' });
        const recipient = `onboarding-${width}-${crypto.randomUUID()}@example.test`;
        const business = `Negocio ${width} ${crypto.randomUUID()}`;

        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/login(?:\?|$)/);
        await page.goto('/register');
        await expect(page.getByRole('heading', { name: 'Crear una cuenta' })).toBeVisible();
        await expectDarkReadable(page);
        await page.getByRole('textbox', { name: /nombre/i }).first().fill(`Owner ${width}`);
        await page.getByRole('textbox', { name: /correo/i }).fill(recipient);
        await page.locator('input[name="password"]').fill('ValidPassword84!strong');
        await page.locator('input[name="password_confirmation"]').fill('ValidPassword84!strong');
        await page.getByRole('button', { name: 'Crear cuenta' }).click();

        await expect(page).toHaveURL(/\/email\/verify(?:\?|$)/);
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/email\/verify(?:\?|$)/);
        await page.goto(await verificationLink(request, recipient));
        await expect(page).toHaveURL(/\/business\/onboarding(?:\?|$)/);
        await expect(page.getByRole('heading', { name: 'Configura tu negocio' })).toBeVisible();
        await expectDarkReadable(page);

        const name = page.getByRole('textbox', { name: 'Nombre del negocio' });
        await name.focus();
        await expect(name).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(page.getByLabel('Zona horaria')).toBeFocused();
        await name.fill(business);
        await page.getByLabel('Zona horaria').selectOption('America/Argentina/Buenos_Aires');
        await page.getByRole('button', { name: 'Guardar negocio' }).click();
        await expect(page).toHaveURL(/\/dashboard(?:\?|$)/);
        await expect(page.getByRole('heading', { name: business })).toBeVisible();
        await expectDarkReadable(page);
        await expect(page.getByText('Zona horaria: America/Argentina/Buenos_Aires')).toBeVisible();

        const navigation = page.locator('[data-flux-sidebar] [data-flux-sidebar-item]').filter({ hasText: 'Panel' });
        const profileLink = page.locator('[data-flux-sidebar] [data-flux-sidebar-item]').filter({ hasText: 'Perfil del negocio' });
        if (width === 375) {
            await page.getByRole('banner').getByRole('button', { name: /mostrar u ocultar barra lateral/i }).click();
        }
        await expect(navigation).toHaveAttribute('data-current', '');
        await expect(profileLink).not.toHaveAttribute('data-current', '');
        await navigation.evaluate((element) => { window.__persistedSidebarLink = element; });
        await profileLink.click();
        await expect(page).toHaveURL(/\/business\/profile(?:\?|$)/);
        expect(await navigation.evaluate((element) => element === window.__persistedSidebarLink)).toBe(true);
        await expect(profileLink).toHaveAttribute('data-current', '');
        await expect(navigation).not.toHaveAttribute('data-current', '');
        await expect(page.getByRole('textbox', { name: 'Nombre del negocio' })).toHaveValue(business);
        await expect(page.getByLabel('Zona horaria')).toHaveValue('America/Argentina/Buenos_Aires');
        await expectDarkReadable(page);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    });
}
