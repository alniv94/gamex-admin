import { test, expect } from '@playwright/test';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

test('sidebar keeps its scroll position and collapsed groups when opening lower modules', async ({
    page,
}, info) => {
    test.setTimeout(60000);
    const index = ['desktop', 'tablet', 'mobile'].indexOf(info.project.name) + 4;
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/login');
    await page.getByLabel('Mobile Number', { exact: true }).fill(`91${index}1234567`);
    const folder = path.resolve('storage/app/private/browser-notifications');
    const existing = new Set(existsSync(folder) ? readdirSync(folder) : []);
    await page.getByRole('button', { name: 'Send OTP', exact: true }).click();
    await expect(
        page.getByText(
            'Local test mode: no SMS was sent. The OTP is in the private notifications folder.',
            { exact: true },
        ),
    ).toBeVisible();
    const capture = readdirSync(folder)
        .filter((file) => !existing.has(file))
        .map(
            (file) =>
                JSON.parse(readFileSync(path.join(folder, file), 'utf8')) as {
                    recipient: string;
                    message: string;
                },
        )
        .find((record) => record.recipient === `+6391${index}1234567`)!;
    await page.getByLabel('OTP', { exact: true }).fill(capture.message.match(/\b([0-9]{6})\b/)![1]);
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.setViewportSize({ width: page.viewportSize()!.width, height: 360 });

    if (info.project.name !== 'desktop') {
        await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    }
    const navigation = page.getByLabel('Main navigation', { exact: true });
    const players = navigation.getByRole('button', { name: 'Players', exact: true });
    await players.click();
    await expect(players).toHaveAttribute('aria-expanded', 'false');
    const scrollContainer = navigation.locator('.v-navigation-drawer__content');
    const config = navigation.getByRole('link', { name: 'Config', exact: true });
    await config.scrollIntoViewIfNeeded();
    await expect
        .poll(() => scrollContainer.evaluate((element) => element.scrollTop))
        .toBeGreaterThan(0);
    const scrollTop = await scrollContainer.evaluate((element) => element.scrollTop);

    await config.click();
    await expect(page).toHaveURL(/\/ending\/config$/);
    if (info.project.name !== 'desktop') {
        await expect(
            page.getByRole('button', { name: 'Open navigation', exact: true }),
        ).toHaveAttribute('aria-expanded', 'false');
        await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    }
    await expect(config).toHaveAttribute('aria-current', 'page');
    await expect
        .poll(() => scrollContainer.evaluate((element) => element.scrollTop))
        .toBe(scrollTop);
    await expect(players).toHaveAttribute('aria-expanded', 'false');

    await navigation.getByRole('link', { name: 'Leagues', exact: true }).click();
    await expect(page).toHaveURL(/\/ending\/leagues$/);
    if (info.project.name !== 'desktop') {
        await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    }
    await expect
        .poll(() => scrollContainer.evaluate((element) => element.scrollTop))
        .toBe(scrollTop);
    await expect(players).toHaveAttribute('aria-expanded', 'false');

    if (info.project.name !== 'desktop') {
        await page.getByRole('button', { name: 'Close sidebar', exact: true }).click();
    }
    await page.goBack();
    await expect(page).toHaveURL(/\/ending\/config$/);
    if (info.project.name !== 'desktop') {
        await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    }
    await expect
        .poll(() => scrollContainer.evaluate((element) => element.scrollTop))
        .toBe(scrollTop);
    expect(errors).toEqual([]);
});
