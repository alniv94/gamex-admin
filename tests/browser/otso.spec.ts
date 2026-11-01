import { test, expect as baseExpect } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

const expect = baseExpect.configure({ timeout: 15000 });

test('Otso configuration, fixed jackpot, results, winners, bets filters and exports', async ({
    page,
}, info) => {
    test.setTimeout(210000);
    page.setDefaultTimeout(15000);
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
    const captures = readdirSync(folder).map(
        (file) =>
            JSON.parse(readFileSync(path.join(folder, file), 'utf8')) as {
                recipient: string;
                message: string;
            },
    );
    const capture = captures.find((record) => record.recipient === '+63918880000' + index)!;
    await page.getByLabel('OTP', { exact: true }).fill(capture.message.match(/\b([0-9]{6})\b/)![1]);
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto('/otso-panalo/configs');
    await page.getByRole('button', { name: 'Create Config', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toHaveAccessibleName('Create Game Config');
    await expect(dialog.getByText('Save this configuration without activating it.')).toBeVisible();
    await dialog.locator('input[type="file"]').setInputFiles({
        name: 'config-logo.png',
        mimeType: 'image/png',
        buffer: Buffer.from(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1ioAAAAASUVORK5CYII=',
            'base64',
        ),
    });
    await expect(dialog.getByAltText('Selected logo preview', { exact: true })).toBeVisible();
    await dialog.getByRole('button', { name: 'Close configuration', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await page.getByRole('button', { name: 'Create Config', exact: true }).click();
    await expect(dialog.getByAltText('Selected logo preview', { exact: true })).toHaveCount(0);
    await dialog.getByLabel('Name', { exact: true }).fill('Browser Config ' + index);
    for (const [label, amount] of [
        ['Bet Amount', '20'],
        ['Jackpot Prize', '2500'],
        ['Second Prize', '100'],
        ['Third Prize', '50'],
        ['Fourth Prize', '10'],
        ['Pool Percentage', '20'],
    ]) {
        await dialog.getByLabel(label, { exact: true }).fill(amount);
    }
    const configSummary = dialog.getByRole('region', { name: 'At a glance', exact: true });
    await expect(configSummary).toContainText('₱ 20.00');
    await expect(configSummary).toContainText('₱ 2,500.00');
    await expect(configSummary).toContainText('20.00%');
    await dialog.getByRole('combobox', { name: 'Status', exact: true }).press('ArrowDown');
    await page.getByRole('option', { name: 'Active', exact: true }).click();
    await expect(dialog.getByRole('status')).toHaveText(
        'Saving as active will deactivate any other active configuration.',
    );
    await dialog.getByRole('combobox', { name: 'Status', exact: true }).press('ArrowDown');
    await page.getByRole('option', { name: 'Inactive', exact: true }).click();
    await expect(dialog.getByRole('status')).toHaveCount(0);
    await dialog.locator('.otso-config-dialog-body').evaluate((element) => {
        element.scrollTop = 0;
    });
    await expect(dialog.getByRole('button', { name: 'Save Config', exact: true })).toBeInViewport();
    await page.screenshot({ path: info.outputPath('otso-config-create.png'), fullPage: true });
    await dialog.getByRole('button', { name: 'Save Config', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(
        page.getByRole('row').filter({ hasText: 'Browser Config ' + index }),
    ).toBeVisible();
    await expect(
        page.getByRole('alert').filter({ hasText: 'Game configuration created.' }),
    ).toBeVisible();
    const configRow = page.getByRole('row').filter({ hasText: 'Browser Config ' + index });
    await configRow.getByRole('button', { name: 'Edit configuration', exact: true }).click();
    await expect(dialog).toHaveAccessibleName('Edit Game Config');
    await expect(dialog.getByLabel('Jackpot Prize', { exact: true })).toHaveValue(
        /^2500(?:\.00)?$/,
    );
    await dialog.getByLabel('Jackpot Prize', { exact: true }).fill('2750');
    await expect(configSummary).toContainText('₱ 2,750.00');
    await dialog.getByRole('button', { name: 'Save Config', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(configRow).toContainText('2,750.00');
    await page.screenshot({ path: info.outputPath('otso-config-list.png'), fullPage: true });

    await page.goto('/otso-panalo/games');
    await page.getByRole('button', { name: 'Create Game', exact: true }).click();
    await dialog.getByRole('combobox', { name: 'Available Ending Game', exact: true }).click();
    await page
        .getByRole('option')
        .filter({ hasText: '#' + (9000 + index) + ' ·' })
        .click();
    await expect(dialog.getByLabel('Jackpot Prize', { exact: true })).toHaveValue('1,000.00');
    await expect(dialog.getByText(/does not roll over/)).toBeVisible();
    await dialog.getByRole('button', { name: 'Create Game', exact: true }).click();
    await expect(page).toHaveURL(/\/otso-panalo\/games\/\d+$/);
    await expect(
        page
            .getByRole('row')
            .filter({ has: page.getByRole('rowheader', { name: 'Jackpot Prize', exact: true }) }),
    ).toContainText('PHP 1,000.00');

    const bettingSwitch = page.getByRole('switch', { name: 'Enable Betting', exact: true });
    await expect(bettingSwitch).not.toBeChecked();
    await bettingSwitch.click();
    await expect(dialog.getByText('Enable betting for this game?')).toBeVisible();
    await dialog.getByRole('button', { name: 'Back', exact: true }).click();
    await expect(bettingSwitch).not.toBeChecked();
    await bettingSwitch.click();
    await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('switch', { name: 'Disable Betting', exact: true })).toBeChecked();

    await page.goto('/otso-panalo/games/' + (900 + index));
    const resultEntry = page.locator('.otso-result-card');
    const firstScore = resultEntry.getByLabel('1st Quarter Winning Score', { exact: true });
    const secondScore = resultEntry.getByLabel('1st Quarter Losing Score', { exact: true });
    await expect(resultEntry.getByRole('textbox')).toHaveCount(8);
    await expect(resultEntry.getByText('No quarter input yet', { exact: true })).toBeVisible();
    await resultEntry.screenshot({ path: info.outputPath('otso-create-result-empty.png') });
    await resultEntry.getByRole('button', { name: 'Save Result', exact: true }).click();
    await expect(resultEntry.getByText('At least one quarter result is required.')).toBeVisible();
    await firstScore.fill('0');
    await resultEntry.getByRole('button', { name: 'Save Result', exact: true }).click();
    await expect(resultEntry.getByText('Complete both digits for this quarter.')).toBeVisible();
    await secondScore.fill('0');
    await expect(
        resultEntry.getByRole('progressbar', { name: 'Quarter completion' }),
    ).toHaveAttribute('aria-valuenow', '25');
    const livePreview = resultEntry.getByRole('region', { name: 'Live Preview', exact: true });
    await expect(livePreview.getByText('25%', { exact: true })).toBeVisible();
    await expect(
        resultEntry.getByLabel('1st Quarter formatted result', { exact: true }),
    ).toHaveText('0-0');
    await resultEntry.getByRole('button', { name: 'Clear Inputs', exact: true }).click();
    await expect(firstScore).toHaveValue('');
    await expect(secondScore).toHaveValue('');
    await expect(
        resultEntry.getByRole('progressbar', { name: 'Quarter completion' }),
    ).toHaveAttribute('aria-valuenow', '0');
    await firstScore.fill('x');
    await expect(firstScore).toHaveValue('');
    await resultEntry
        .getByRole('button', { name: 'Use Last Digits of Saved Scores', exact: true })
        .click();
    await expect(firstScore).toHaveValue('1');
    await expect(secondScore).toHaveValue('2');
    await expect(livePreview.getByText('100%', { exact: true })).toBeVisible();
    await resultEntry.screenshot({ path: info.outputPath('otso-create-result-ready.png') });
    await resultEntry.getByRole('button', { name: 'Save Result', exact: true }).click();
    await expect(resultEntry.getByRole('textbox')).toHaveCount(0);
    await expect(resultEntry.getByText('For Approval', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Approve Result', exact: true }).click();
    await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('button', { name: 'Edit Result', exact: true })).toHaveCount(0);
    await page.getByRole('tab', { name: 'Winners', exact: true }).click();
    await page.getByRole('button', { name: 'Generate Winners', exact: true }).click();
    await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    const secondPrizeWinner = page.getByRole('row').filter({
        has: page.getByRole('cell', { name: 'Second Prize', exact: true }),
    });
    await expect(secondPrizeWinner).toContainText('100.00');
    await page.getByRole('button', { name: 'Approve All', exact: true }).click();
    await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(secondPrizeWinner).toContainText('Approved');
    await expect(secondPrizeWinner).toContainText('Yes');
    await expect(
        page
            .getByRole('row')
            .filter({ has: page.getByRole('cell', { name: 'Jackpot', exact: true }) }),
    ).toContainText('No');
    const downloaded = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Generate Bets PDF', exact: true }).click();
    const download = await downloaded;
    expect(download.suggestedFilename()).toBe('otso_panalo_bets_' + (900 + index) + '.pdf');
    await page.screenshot({ path: info.outputPath('otso-winners.png'), fullPage: true });

    await page.goto('/otso-panalo/bets');
    await expect(page.getByText(/Historical Otso Panalo games are being prepared/)).toHaveCount(0);
    const betTable = page.getByRole('table', { name: 'Bet records', exact: true });
    await expect(betTable.getByRole('columnheader')).toHaveCount(10);
    await expect(page.getByText('Total: 6', { exact: true })).toBeVisible();
    await page.screenshot({ path: info.outputPath('otso-bets.png'), fullPage: true });
    await page.getByRole('button', { name: 'Filters', exact: true }).click();
    await dialog.getByLabel('Bet Date From', { exact: true }).fill('2026-11-02');
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByText('Total: 6', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Filters', exact: true }).click();
    await expect(dialog.getByLabel('Bet Date From', { exact: true })).toHaveValue('');
    await dialog.getByLabel('Bet Date From', { exact: true }).fill('2026-11-02');
    await dialog.getByLabel('Bet Date To (Optional)', { exact: true }).fill('2026-11-01');
    await dialog.getByRole('button', { name: 'Apply Filters', exact: true }).click();
    await expect(
        dialog.getByText('The date to field must be a date after or equal to date from.'),
    ).toBeVisible();
    await dialog.getByRole('button', { name: 'Reset', exact: true }).click();
    await expect(dialog.getByLabel('Bet Date From', { exact: true })).toHaveValue('');
    await expect(dialog.getByLabel('Bet Date To (Optional)', { exact: true })).toHaveValue('');
    await dialog.getByRole('combobox', { name: 'Ending Game', exact: true }).click();
    await page
        .getByRole('option')
        .filter({ hasText: '#' + (8000 + index) + ' /' })
        .click();
    await page.screenshot({ path: info.outputPath('otso-bets-filters.png'), fullPage: true });
    await dialog.getByRole('button', { name: 'Apply Filters', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByText('Total: 2', { exact: true })).toBeVisible();
    const betSearch = page.getByRole('textbox', { name: 'Search bets', exact: true });
    await betSearch.fill('Browser 3 quarters');
    await expect(page.getByText('Total: 1', { exact: true })).toBeVisible();
    await expect(betTable.getByRole('row').last()).toContainText('100.00');
    await expect(betTable.getByText('Win', { exact: true })).toHaveCount(3);
    await expect(betTable.getByText('Lose', { exact: true })).toHaveCount(1);
    await betTable.getByRole('button', { name: /^View bet / }).click();
    await expect(dialog.getByText('Prize: PHP 100.00', { exact: true })).toBeVisible();
    await dialog.getByRole('button', { name: 'Close', exact: true }).click();
    await betTable.getByRole('button', { name: 'Sort by Amount', exact: true }).click();
    await expect(page).toHaveURL(
        (url) =>
            url.searchParams.get('sort') === 'amount' &&
            url.searchParams.get('direction') === 'desc',
    );
    await betTable.getByRole('button', { name: 'Sort by Amount', exact: true }).click();
    await expect(page).toHaveURL(
        (url) =>
            url.searchParams.get('sort') === 'amount' &&
            url.searchParams.get('direction') === 'asc',
    );
    await expect(
        betTable.getByRole('columnheader', { name: 'Sort by Amount', exact: true }),
    ).toHaveAttribute('aria-sort', 'ascending');
    await page.getByRole('combobox', { name: 'Rows per page', exact: true }).press('ArrowDown');
    await page.getByRole('option', { name: '20', exact: true }).click();
    await expect(page).toHaveURL(/per_page=20/);
    const exported = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Export CSV', exact: true }).click();
    const csvDownload = await exported;
    expect(csvDownload.suggestedFilename()).toBe('otso_panalo_bets.csv');
    const csv = readFileSync((await csvDownload.path())!, 'utf8');
    expect(csv).toContain('Browser 3 quarters');
    expect(csv).not.toContain('Browser 4 quarters');
    await page.getByRole('button', { name: 'Refresh bets', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Filters', exact: true })).toBeEnabled();
    await expect(page.getByText('Total: 1', { exact: true })).toBeVisible();
    await betSearch.fill('');
    await expect(page.getByText('Total: 2', { exact: true })).toBeVisible();
    await expect(betSearch).toHaveValue('');
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: info.outputPath('otso-bets-filtered.png'), fullPage: true });
    expect(errors).toEqual([]);
});
