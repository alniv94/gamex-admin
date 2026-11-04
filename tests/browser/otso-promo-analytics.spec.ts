import { test, expect } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

test('promo analytics shows engagement, filters steps, and exports the applied report', async ({
    page,
}, info) => {
    const index = ['desktop', 'tablet', 'mobile'].indexOf(info.project.name) + 1;
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/login');
    await page.getByLabel('Mobile Number', { exact: true }).fill('918880000' + index);
    await page.getByRole('button', { name: 'Send OTP', exact: true }).click();
    await expect(
        page.getByText(
            'Local test mode: no SMS was sent. The OTP is in the private notifications folder.',
            { exact: true },
        ),
    ).toBeVisible();
    const folder = path.resolve('storage/app/private/browser-notifications');
    const capture = readdirSync(folder)
        .map(
            (file) =>
                JSON.parse(readFileSync(path.join(folder, file), 'utf8')) as {
                    recipient: string;
                    message: string;
                },
        )
        .find((record) => record.recipient === '+63918880000' + index)!;
    await page.getByLabel('OTP', { exact: true }).fill(capture.message.match(/\b([0-9]{6})\b/)![1]);
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto('/otso-panalo/promo-analytics');
    await expect(page.getByRole('heading', { name: 'Promo Analytics', exact: true })).toBeVisible();
    const metric = (title: string) =>
        page.locator('.metric-card').filter({ has: page.getByText(title, { exact: true }) });
    await expect(metric('Promo impressions')).toContainText('2');
    await expect(metric('Play click rate')).toContainText('50.0%');
    await expect(metric('Tutorial completion')).toContainText('100.0%');
    await expect(
        page.getByRole('heading', { name: 'Player retention', exact: true }),
    ).toBeVisible();
    await expect(page.getByText('insufficient tokens', { exact: true })).toBeVisible();
    await expect(page.locator('.trend-day')).toHaveCount(30);
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
    await page.screenshot({ path: info.outputPath('otso-promo-analytics.png'), fullPage: true });

    await page.getByRole('combobox', { name: 'Dialog step', exact: true }).press('ArrowDown');
    await page.getByRole('option', { name: 'Step 2', exact: true }).click();
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await expect(page).toHaveURL(/dialog_step=2/);
    await expect(metric('Play click rate')).toContainText('0.0%');
    await expect(metric('Promo impressions').locator('strong')).toHaveText('1');
    await expect(page.locator('.step-title').filter({ hasText: 'Step 2' })).toBeVisible();

    const downloaded = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Export summary', exact: true }).click();
    const download = await downloaded;
    expect(download.suggestedFilename()).toBe('otso-promo-summary.csv');
    const downloadedPath = await download.path();
    expect(readFileSync(downloadedPath!, 'utf8')).toContain('"dialog step"');
    expect(errors).toEqual([]);
});
