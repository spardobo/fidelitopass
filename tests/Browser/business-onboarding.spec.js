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

async function expectReadable(page) {
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    expect(await page.evaluate(() => localStorage.getItem('flux.appearance'))).toBe('dark');
    const colors = await page.evaluate(() => {
        const body = getComputedStyle(document.body);
        const heading = getComputedStyle(document.querySelector('h1'));
        return { background: body.backgroundColor, text: body.color, heading: heading.color };
    });
    expect(luminance(colors.background)).toBeLessThan(0.2);
    expect(contrast(colors.text, colors.background)).toBeGreaterThanOrEqual(4.5);
    expect(contrast(colors.heading, colors.background)).toBeGreaterThanOrEqual(4.5);
}

async function expectAuthenticatedSurface(page, surface) {
    const appearance = await page.evaluate((selector) => {
        const card = document.querySelector(selector);
        const header = document.querySelector('[data-flux-header], [data-flux-sidebar]');

        return {
            font: getComputedStyle(document.body).fontFamily,
            canvas: getComputedStyle(document.body).backgroundColor,
            card: getComputedStyle(card).backgroundColor,
            header: header ? getComputedStyle(header).backgroundColor : null,
            overflow: document.documentElement.scrollWidth > window.innerWidth,
        };
    }, surface);

    expect(appearance.font).toContain('Onest Variable');
    expect(appearance.canvas).toBe('rgb(24, 24, 24)');
    expect(appearance.card).toBe('rgb(39, 39, 39)');
    expect(appearance.header).not.toBeNull();
    expect(luminance(appearance.header)).toBeLessThan(luminance(appearance.canvas));
    expect(appearance.overflow).toBe(false);

    const logoIcon = page.locator('img[src$="logo_icon.svg"]').first();
    await expect(logoIcon).toBeVisible();
    await expect(logoIcon).toHaveCSS('filter', 'brightness(0)');
    await expect(logoIcon.locator('..')).toHaveCSS('background-color', 'rgb(183, 171, 228)');
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

async function registerAndVerifyOwner(page, request, width, recipient) {
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login(?:\?|$)/);
    await page.goto('/register');
    await expect(page.getByRole('heading', { name: 'Crear una cuenta' })).toBeVisible();
    await expectReadable(page);
    expect(await page.evaluate(() => matchMedia('(prefers-color-scheme: dark)').matches)).toBe(true);
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
}

test('saved light appearance is replaced before authentication renders', async ({ page }, testInfo) => {
    await page.addInitScript(() => localStorage.setItem('flux.appearance', 'light'));

    for (const width of [375, 1280]) {
        await page.setViewportSize({ width, height: 800 });

        for (const [path, heading] of [['/register', 'Crear una cuenta'], ['/login', 'Iniciar sesión']]) {
            await page.goto(path);
            await expectReadable(page);
            await expect(page.getByRole('heading', { name: heading })).toBeVisible();

            const logo = page.getByRole('link', { name: 'FidelitoPass' }).getByRole('img');
            await expect(logo).toBeVisible();
            await expect(logo).toHaveAttribute('src', /logo-header\.webp$/);

            const card = page.locator('.rounded-2xl.border').filter({ has: page.locator('form') });
            await expect(card).toHaveCSS('background-color', 'rgb(39, 39, 39)');
            await expect(card).toHaveCSS('border-color', 'rgb(65, 65, 65)');
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);

            const primary = page.getByRole('button', { name: path === '/login' ? 'Iniciar sesión' : 'Crear cuenta' });
            await expect(primary).toHaveCSS('background-color', 'rgb(183, 171, 228)');
            const buttonColors = await primary.evaluate((element) => ({
                foreground: getComputedStyle(element).color,
                background: getComputedStyle(element).backgroundColor,
            }));
            expect(contrast(buttonColors.foreground, buttonColors.background)).toBeGreaterThanOrEqual(4.5);

            const logoLink = page.getByRole('link', { name: 'FidelitoPass' });
            await logoLink.focus();
            await page.keyboard.press('Shift+Tab');
            await page.keyboard.press('Tab');
            await expect(logoLink).toBeFocused();
            await expect(logoLink).toHaveCSS('outline-style', 'solid');
            await expect(logoLink).toHaveCSS('outline-color', 'rgb(183, 171, 228)');
            await page.keyboard.press('Tab');
            await expect(page.locator('input[autofocus]')).toBeFocused();
            await page.screenshot({ path: testInfo.outputPath(`auth-${path.slice(1)}-${width}.png`), fullPage: true });
        }
    }
});

for (const width of [1280, 375]) {
    test(`verified owner completes onboarding and edits business at ${width}px`, async ({ page, request }, testInfo) => {
        await page.setViewportSize({ width, height: 800 });
        await page.emulateMedia({ colorScheme: 'dark' });
        const recipient = `onboarding-${width}-${crypto.randomUUID()}@example.test`;
        const business = `Negocio ${width} ${crypto.randomUUID()}`;

        await registerAndVerifyOwner(page, request, width, recipient);
        await expect(page.getByRole('heading', { name: 'Configura tu negocio' })).toBeVisible();
        await expectReadable(page);

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
        await expectReadable(page);
        await page.reload();
        await expectReadable(page);
        await expect(page.getByText('Zona horaria: America/Argentina/Buenos_Aires')).toBeVisible();
        await expectAuthenticatedSurface(page, 'section[aria-label]');
        await page.screenshot({ path: testInfo.outputPath(`app-header-dashboard-${width}.png`), fullPage: true });

        const ownerName = `Owner ${width}`;
        const menuButton = page.locator('[data-test="sidebar-menu-button"]');
        await expect(menuButton).toContainText(ownerName);
        await expect(page.locator('[data-flux-avatar]')).toHaveCount(0);

        if (width === 375) {
            await page.getByRole('banner').getByRole('button', { name: /mostrar u ocultar barra lateral/i }).click();
        }

        const activeNavigation = width === 375 ? page.locator('[data-flux-sidebar]') : page.getByRole('banner');
        for (const label of ['Search', 'Repository', 'Documentation']) {
            await expect(activeNavigation.getByRole('link', { name: label, exact: true })).toHaveCount(0);
        }
        await expect(activeNavigation.locator('a[href="#"]')).toHaveCount(0);
        await expect(activeNavigation.locator('a[href="https://github.com/laravel/livewire-starter-kit"]')).toHaveCount(0);
        await expect(activeNavigation.locator('a[href="https://laravel.com/docs/starter-kits#livewire"]')).toHaveCount(0);

        const navigation = width === 375
            ? page.locator('[data-flux-sidebar] [data-flux-sidebar-item]').filter({ hasText: 'Panel' })
            : page.getByRole('banner').getByRole('link', { name: 'Panel' });
        const profileLink = width === 375
            ? page.locator('[data-flux-sidebar] [data-flux-sidebar-item]').filter({ hasText: 'Perfil del negocio' })
            : page.getByRole('banner').getByRole('link', { name: 'Perfil del negocio' });

        await expect(navigation).toHaveAttribute('data-current', 'data-current');
        await expect(profileLink).not.toHaveAttribute('data-current', '');
        await profileLink.click();
        await expect(page).toHaveURL(/\/business\/profile(?:\?|$)/);
        await expect(profileLink).toHaveAttribute('data-current', 'data-current');
        await expect(navigation).not.toHaveAttribute('data-current', '');
        await expect(page.getByRole('textbox', { name: 'Nombre del negocio' })).toHaveValue(business);
        await expect(page.getByLabel('Zona horaria')).toHaveValue('America/Argentina/Buenos_Aires');
        await expectReadable(page);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await expectAuthenticatedSurface(page, 'form[wire\\:submit]');
        await page.screenshot({ path: testInfo.outputPath(`app-header-profile-${width}.png`), fullPage: true });

        await menuButton.click();
        await expect(page.getByRole('menuitem', { name: 'Cerrar sesión' })).toBeVisible();
        await page.getByRole('menuitem', { name: 'Configuración' }).click();
        await expect(page).toHaveURL(/\/settings\/profile(?:\?|$)/);

        for (const [path, heading] of [
            ['/settings/profile', 'Perfil'],
            ['/settings/security', 'Actualizar contraseña'],
            ['/settings/appearance', 'Apariencia'],
        ]) {
            await page.goto(path);
            if (path === '/settings/security' && await page.getByRole('heading', { name: 'Confirmar contraseña' }).isVisible()) {
                await page.getByRole('textbox', { name: 'Contraseña' }).fill('ValidPassword84!strong');
                await page.getByRole('button', { name: 'Confirmar' }).click();
                await expect(page).toHaveURL(/\/settings\/security(?:\?|$)/);
            }
            await expect(page.getByRole('heading', { name: heading }).last()).toBeVisible();
            await expectAuthenticatedSurface(page, '[data-test="settings-surface"]');
            await expect(page.locator('[data-test="settings-surface"]')).toHaveCSS('background-color', 'rgb(39, 39, 39)');
            await page.screenshot({ path: testInfo.outputPath(`settings-${path.split('/').at(-1)}-${width}.png`), fullPage: true });
        }

        await expect(page.getByText('El tema oscuro está activo para todas las cuentas.')).toBeVisible();
        await expect(page.getByRole('radio')).toHaveCount(0);
        await page.goto('/settings/security');
        const passwordInput = page.getByRole('textbox', { name: 'Contraseña actual' });
        await passwordInput.focus();
        await expect(passwordInput).toBeFocused();

        if (width === 1280) {
            await expect(page.getByRole('link', { name: 'Apariencia' })).toHaveCount(0);
            await page.goto('/settings/appearance');
            await expect(page.getByText('El tema oscuro está activo para todas las cuentas.')).toBeVisible();
            await expect(page.getByRole('radio')).toHaveCount(0);
            await expectReadable(page);
            await page.getByRole('link', { name: 'Perfil del negocio' }).click();
            await expect(page).toHaveURL(/\/business\/profile(?:\?|$)/);
            await expectReadable(page);
            await page.reload();
            await expectReadable(page);
        }

        await menuButton.click();
        await page.getByRole('menuitem', { name: 'Cerrar sesión' }).click();
        await expect(page).toHaveURL(/\/$/);
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/login(?:\?|$)/);
    });
}
