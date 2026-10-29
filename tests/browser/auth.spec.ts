import { test, expect } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

test('mobile OTP login, dashboard, logout, and guest protection', async ({ page }, info) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const index = ['desktop', 'tablet', 'mobile'].indexOf(info.project.name) + 1;
    const localMobile = `91${index}1234567`;
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Sign In', exact: true })).toBeVisible();
    await expect(page.getByRole('img', { name: 'GameX Sports Logo' })).toBeVisible();
    await expect(page.getByLabel('Mobile Number', { exact: true })).toBeVisible();
    await expect(page.getByLabel('OTP', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send OTP', exact: true })).toBeDisabled();
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-login.png`,
        fullPage: true,
    });
    await page.getByLabel('Mobile Number', { exact: true }).fill(localMobile);
    await page.getByRole('button', { name: 'Send OTP', exact: true }).click();
    await expect(
        page.getByText(
            'Local test mode: no SMS was sent. The OTP is in the private notifications folder.',
            { exact: true },
        ),
    ).toBeVisible();
    const folder = path.resolve('storage/app/private/browser-notifications');
    const captures = readdirSync(folder).map(
        (file) =>
            JSON.parse(readFileSync(path.join(folder, file), 'utf8')) as {
                recipient: string;
                message: string;
            },
    );
    const capture = captures.find((record) => record.recipient === `+6391${index}1234567`);
    expect(capture).toBeDefined();
    const code = capture!.message.match(/\b([0-9]{6})\b/)![1];
    await page.getByLabel('OTP', { exact: true }).fill(code);
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible();
    await expect(
        page.getByText(`Welcome, Test Administrator ${index}.`, { exact: true }),
    ).toBeVisible();
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth),
    ).toBe(false);
    await page.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-dashboard.png`,
        fullPage: true,
    });
    if (info.project.name !== 'desktop') {
        await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
        await expect(page.getByRole('img', { name: 'GameX Sports Logo' })).toBeVisible();
        await page.getByRole('link', { name: 'Dashboard', exact: true }).click();
    } else {
        await page.getByRole('button', { name: 'Close navigation', exact: true }).click();
        await expect(
            page.getByRole('button', { name: 'Open navigation', exact: true }),
        ).toBeVisible();
    }
    await page.getByRole('button', { name: 'Account menu for Test', exact: true }).click();
    await page.getByRole('menuitem', { name: 'Logout', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByLabel('Mobile Number', { exact: true })).toHaveValue(localMobile);
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
    expect(errors).toEqual([]);
});

test('invalid mobile validation and CSRF protection', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Mobile Number', { exact: true }).fill('123');
    await expect(page.getByRole('button', { name: 'Send OTP', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(
        page.getByText('Enter a valid Philippine mobile number.', { exact: true }),
    ).toBeVisible();
    const rejected = await page.request.post('/api/login/otp', {
        data: { mobile_number: '123' },
        headers: { Accept: 'application/json' },
    });
    expect(rejected.status()).toBe(419);
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth),
    ).toBe(false);
});
