import { test, expect } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

test('Otso configuration, fixed jackpot creation, result approval, winners and PDF', async ({
    page,
}, info) => {
    test.setTimeout(90000);
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
    await dialog.getByRole('button', { name: 'Save Config', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(
        page.getByRole('row').filter({ hasText: 'Browser Config ' + index }),
    ).toBeVisible();
    await expect(
        page.getByRole('alert').filter({ hasText: 'Game configuration created.' }),
    ).toBeVisible();

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
    expect(errors).toEqual([]);
});
