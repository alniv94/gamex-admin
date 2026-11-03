import { test, expect } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

test('PAGCOR reports filter, paginate, export, and group at each viewport', async ({
    page,
}, info) => {
    test.setTimeout(90000);
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const user = ['desktop', 'tablet', 'mobile'].indexOf(info.project.name) + 1;
    await page.goto('/login');
    await page.getByLabel('Mobile Number', { exact: true }).fill(`91${user}1234567`);
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
    const otp = captures
        .find((capture) => capture.recipient === `+6391${user}1234567`)!
        .message.match(/\b([0-9]{6})\b/)![1];
    await page.getByLabel('OTP', { exact: true }).fill(otp);
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    const today = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Manila' }).format(new Date());
    await page.goto('/pagcor/reports/ending-game');
    await expect(page.getByRole('heading', { name: 'Ending Game', exact: true })).toBeVisible();
    await expect(page.getByText('Select a date range to generate the report.')).toBeVisible();
    await page.getByLabel('Schedule Date Start (00:00:00)', { exact: true }).fill(today);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.getByRole('table')).toContainText('Blue Team');
    await expect(page.getByRole('table')).toContainText('TOTAL');
    await page.getByLabel('Rows per page:', { exact: true }).press('Enter');
    await page.getByRole('option', { name: '5', exact: true }).click();
    await page.getByRole('button', { name: 'Next page', exact: true }).click();
    await expect(page).toHaveURL(/page=2/);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page).toHaveURL(/page=1/);
    await page.getByRole('button', { name: 'Export data', exact: true }).click();
    const download = page.waitForEvent('download');
    await page.getByText('All matching rows (CSV)', { exact: true }).click();
    expect((await download).suggestedFilename()).toMatch(/^pagcor-ending-game.*\.csv$/);
    await page.screenshot({ path: `test-results/pagcor-${info.project.name}.png`, fullPage: true });
    await page.goto(`/pagcor/reports/ending-progress?submitted=1&date_from=${today}`);
    await expect(page.getByRole('columnheader', { name: 'Total Gross Sales' })).toBeVisible();
    await page.getByLabel('Monthly', { exact: true }).check();
    await page.getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(page).toHaveURL(/period=monthly/);
    await expect(page.getByRole('table')).toContainText(today.slice(0, 7));
    await page.goto(`/pagcor/reports/lucky-four-game?submitted=1&date_from=${today}`);
    await expect(page.getByRole('table')).toContainText('No Activity');
    await expect(
        page.getByRole('columnheader', { name: 'Gross Amount', exact: true }),
    ).toBeVisible();
    await page.goto(`/pagcor/reports/lucky-four-progress?submitted=1&date_from=${today}`);
    await expect(page.getByRole('table')).toContainText('No Activity');
    await page.goto(
        `/pagcor/reports/transactional?submitted=1&date_from=${today}&gaming_venue=lascasas_bagac_bataan`,
    );
    await expect(page.getByRole('table')).toContainText('No Activity');
    await expect(page.getByRole('table')).toContainText('Channel Type');
    expect(errors).toEqual([]);
});
