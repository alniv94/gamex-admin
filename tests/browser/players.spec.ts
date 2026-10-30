import { test, expect as baseExpect } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

const expect = baseExpect.configure({ timeout: 15000 });

test('player list, filters, export, verification, controls, funds and logs at each viewport', async ({
    page,
    context,
}, info) => {
    test.setTimeout(210000);
    page.setDefaultTimeout(15000);
    const index = ['desktop', 'tablet', 'mobile'].indexOf(info.project.name) + 1;
    const loginIndex = index + 6;
    const errors: string[] = [];
    context.on('page', (newPage) => newPage.on('pageerror', (error) => errors.push(error.message)));
    page.on('pageerror', (error) => errors.push(error.message));
    await context.route('**/players/*/ndrp', (route) =>
        route.fulfill({
            json: {
                status: 'ready',
                data: {
                    totalNumberOfRecords: 1,
                    numberOfGelRecords: 1,
                    numberOfGuestRecords: 0,
                    numberOfGovtRecords: 0,
                    records: [
                        {
                            firstName: 'BROWSER',
                            middleName: '',
                            lastName: 'PLAYER',
                            birthdate: '1997-08-13',
                            source: 'GEL',
                            photoPath: 'Unavailable',
                        },
                    ],
                },
            },
        }),
    );
    await page.goto('/login');
    await page.getByLabel('Mobile Number', { exact: true }).fill(`91${loginIndex}1234567`);
    await page.getByRole('button', { name: 'Send OTP', exact: true }).click();
    await expect(
        page.getByText(
            'Local test mode: no SMS was sent. The OTP is in the private notifications folder.',
            { exact: true },
        ),
    ).toBeVisible();
    const captures = readdirSync(path.resolve('storage/app/private/browser-notifications')).map(
        (file) =>
            JSON.parse(
                readFileSync(
                    path.resolve('storage/app/private/browser-notifications', file),
                    'utf8',
                ),
            ) as { recipient: string; message: string },
    );
    const capture = captures.find((record) => record.recipient === `+6391${loginIndex}1234567`)!;
    await page.getByLabel('OTP', { exact: true }).fill(capture.message.match(/\b([0-9]{6})\b/)![1]);
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/, { timeout: 15000 });
    if (info.project.name !== 'desktop') {
        await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    }
    await page.getByRole('link', { name: 'Player Withdrawal Accounts', exact: true }).click();
    await expect(page).toHaveURL(/\/players\/withdrawal_accounts$/);
    await expect(
        page.getByRole('heading', { name: 'Player Withdrawal Account', exact: true }),
    ).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Outlet' })).toBeVisible();
    await expect(page.locator('tbody tr')).toHaveCount(3);
    const withdrawalSearch = page.getByLabel('Search withdrawal accounts', { exact: true });
    await withdrawalSearch.fill(`912345678${index}`);
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await page.getByRole('button', { name: 'Date Created', exact: true }).click();
    await expect(page).toHaveURL(/direction=asc/);
    await page.getByRole('button', { name: 'Filter withdrawal accounts', exact: true }).click();
    const withdrawalFilter = page.getByRole('dialog');
    await withdrawalFilter.getByLabel('Date From', { exact: true }).fill('2026-10-16');
    await withdrawalFilter.getByLabel('Date To', { exact: true }).fill('2026-10-16');
    await withdrawalFilter.getByRole('button', { name: 'Filter', exact: true }).click();
    await expect(withdrawalFilter).not.toBeVisible();
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.getByRole('cell', { name: /Oct 16, 2026/ })).toBeVisible();
    await page.getByRole('button', { name: 'Refresh withdrawal accounts', exact: true }).click();
    await expect(withdrawalSearch).toHaveValue(`912345678${index}`);
    await page
        .getByRole('link', {
            name: `Details for withdrawal account 912345678${index}`,
            exact: true,
        })
        .click();
    await expect(page).toHaveURL(new RegExp(`/player/${1000 + index}$`));
    await expect(page.getByRole('heading', { name: 'Player Details', exact: true })).toBeVisible();
    await page.goto('/players/withdrawal_accounts');
    await page.getByRole('button', { name: 'Filter withdrawal accounts', exact: true }).click();
    await withdrawalFilter.getByLabel('Date From', { exact: true }).fill('2026-10-17');
    await withdrawalFilter.getByLabel('Date To', { exact: true }).fill('2026-10-17');
    await withdrawalFilter.getByRole('button', { name: 'Filter', exact: true }).click();
    await expect(page.getByRole('cell', { name: 'No data available', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Filter withdrawal accounts', exact: true }).click();
    await withdrawalFilter.getByRole('button', { name: 'Reset', exact: true }).click();
    await expect(page.locator('tbody tr')).toHaveCount(3);
    await withdrawalSearch.fill('No matching withdrawal account');
    await expect(page.getByRole('cell', { name: 'No data available', exact: true })).toBeVisible();
    await withdrawalSearch.fill('');
    await expect(page.locator('tbody tr')).toHaveCount(3);
    await page.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-withdrawal-accounts.png`,
        fullPage: true,
    });
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth),
    ).toBe(false);
    await page.goto('/players/list');
    await expect(page.getByRole('heading', { name: 'Player List', exact: true })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Verification Status' })).toBeVisible();
    await page
        .getByLabel('Search name, mobile, email', { exact: true })
        .fill(`Browser Player 1000 ${index}`);
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await page
        .getByRole('button', { name: `Other details for Browser Player 1000 ${index}` })
        .click();
    await expect(page.getByText('Other Details', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    const downloadEvent = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Export players to CSV' }).click();
    const download = await downloadEvent;
    expect(download.suggestedFilename()).toMatch(/players-list-report-.*\.csv$/);
    expect(readFileSync((await download.path())!, 'utf8')).toContain(
        `Browser Player 1000 ${index}`,
    );
    await page.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-players-list.png`,
        fullPage: true,
    });
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth),
    ).toBe(false);
    const detailEvent = context.waitForEvent('page');
    await page
        .getByRole('link', { name: `Details for Browser Player 1000 ${index}`, exact: true })
        .click();
    const details = await detailEvent;
    await expect(
        details.getByRole('heading', { name: 'Player Details', exact: true }),
    ).toBeVisible();
    await expect(details.getByText('Total Records: 1', { exact: true })).toBeVisible();
    await expect(details.getByText('Browser wallet transaction', { exact: true })).toBeVisible();
    await details.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-player-details.png`,
        fullPage: true,
    });
    expect(
        await details.evaluate(() => document.documentElement.scrollWidth > window.innerWidth),
    ).toBe(false);
    await details.getByRole('button', { name: 'Verify', exact: true }).click();
    const dialog = details.getByRole('dialog');
    await expect(
        dialog.getByText('Do you really want to verify this player?', { exact: true }),
    ).toBeVisible();
    await dialog.getByRole('button', { name: 'Verify', exact: true }).click();
    await expect(details.getByText('Player verified.', { exact: true })).toBeVisible();
    await expect(
        details.getByRole('checkbox', { name: `Betting for Browser Player 1000 ${index}` }),
    ).toBeChecked();
    await details
        .getByRole('checkbox', { name: `Betting for Browser Player 1000 ${index}` })
        .click();
    await dialog.getByRole('button', { name: 'Yes', exact: true }).click();
    await expect(
        details.getByRole('checkbox', { name: `Betting for Browser Player 1000 ${index}` }),
    ).not.toBeChecked();
    await details.getByRole('button', { name: `Review fund account 912345678${index}` }).click();
    await details.getByText('Verify', { exact: true }).last().click();
    await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(details.getByText('Withdrawal account verified.', { exact: true })).toBeVisible();
    await expect(details.getByText('Default', { exact: true })).toBeVisible();
    await details.getByRole('tab', { name: 'Player Logs', exact: true }).click();
    await expect(details.getByText('Browser player logged in', { exact: true })).toBeVisible();
    await details.getByRole('tab', { name: 'Admin Logs', exact: true }).click();
    await expect(
        details.getByRole('cell', { name: "Verified player's account.", exact: true }),
    ).toBeVisible();
    await details
        .getByRole('checkbox', { name: `Account Status for Browser Player 1000 ${index}` })
        .click();
    await dialog.getByLabel('Reason', { exact: true }).fill('Browser account review');
    await dialog.getByRole('button', { name: 'Yes', exact: true }).click();
    await expect(
        details.getByRole('checkbox', { name: `Account Status for Browser Player 1000 ${index}` }),
    ).not.toBeChecked();
    await details.close();
    await page.goto(`/player/${1100 + index}`);
    await page.getByRole('button', { name: 'Reject', exact: true }).click();
    const rejectDialog = page.getByRole('dialog');
    await rejectDialog.getByLabel('Reason', { exact: true }).fill('Unclear');
    await page.getByRole('option', { name: 'Unclear ID', exact: true }).click();
    await rejectDialog.getByRole('button', { name: 'Reject', exact: true }).click();
    await expect(
        page.getByText('Player rejected. The account is pending updated verification details.', {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByText("Rejected player's account: Unclear ID", { exact: true }),
    ).toBeVisible();
    expect(errors).toEqual([]);
});
