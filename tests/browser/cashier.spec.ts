import { test, expect as baseExpect } from '@playwright/test';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

const expect = baseExpect.configure({ timeout: 15000 });

test('cashier pages support review, verification, approval, filters and export', async ({
    page,
}, info) => {
    test.setTimeout(150000);
    page.setDefaultTimeout(15000);
    const index = ['desktop', 'tablet', 'mobile'].indexOf(info.project.name) + 1;
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/login');
    await page.getByLabel('Mobile Number', { exact: true }).fill(`91${index + 6}1234567`);
    const notificationFolder = path.resolve('storage/app/private/browser-notifications');
    const previousCaptures = new Set(
        existsSync(notificationFolder) ? readdirSync(notificationFolder) : [],
    );
    await page.getByRole('button', { name: 'Send OTP', exact: true }).click();
    await expect(
        page.getByText(
            'Local test mode: no SMS was sent. The OTP is in the private notifications folder.',
            { exact: true },
        ),
    ).toBeVisible();
    const capture = readdirSync(notificationFolder)
        .filter((file) => !previousCaptures.has(file))
        .map(
            (file) =>
                JSON.parse(
                    readFileSync(
                        path.resolve('storage/app/private/browser-notifications', file),
                        'utf8',
                    ),
                ) as { recipient: string; message: string },
        )
        .find((record) => record.recipient === `+6391${index + 6}1234567`)!;
    await page.getByLabel('OTP', { exact: true }).fill(capture.message.match(/\b([0-9]{6})\b/)![1]);
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    for (const [kind, title] of [
        ['cashin', 'Cash In'],
        ['cashout', 'Cash Out'],
    ]) {
        if (info.project.name !== 'desktop')
            await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
        await page.getByRole('link', { name: title, exact: true }).click();
        await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
        await page.getByLabel(`Search ${title}`, { exact: true }).fill(`Cashier Player ${index}`);
        await expect(page.locator('tbody tr')).toHaveCount(1);
        await expect(page.locator('tbody')).not.toContainText('NaN');
        await page.screenshot({
            path: `test-results/cashier-${kind}-${info.project.name}.png`,
            fullPage: true,
        });
        await page
            .getByRole('button', { name: `Review ${title} request ${900 + index}`, exact: true })
            .click();
        const dialog = page.getByRole('dialog');
        await expect(
            dialog.getByText(kind === 'cashin' ? '123.45' : '148.55', { exact: true }),
        ).toBeVisible();
        await dialog.getByRole('button', { name: 'Verify', exact: true }).click();
        await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        await expect(page.getByText('No data available', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: `Filter ${title}`, exact: true }).click();
        const filter = page.getByRole('dialog');
        await filter.getByRole('combobox', { name: 'Status', exact: true }).press('Enter');
        await page.getByRole('option', { name: 'Verified', exact: true }).click();
        await filter.getByRole('button', { name: 'Filter', exact: true }).click();
        await expect(page.locator('tbody tr')).toHaveCount(1);
        await page
            .getByRole('button', { name: `Review ${title} request ${900 + index}`, exact: true })
            .click();
        await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
        await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        const download = page.waitForEvent('download');
        await page.getByRole('link', { name: `Export ${title}`, exact: true }).click();
        expect((await download).suggestedFilename()).toMatch(
            new RegExp(`^${kind}-report-.*\\.csv$`),
        );
    }
    expect(errors).toEqual([]);
});
