import { test, expect } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

test('ending creation, result, verification, declaration, and audit at each viewport', async ({
    page,
}, info) => {
    const errors: string[] = [];
    const endingMutations: { url: string; inertia: string | undefined }[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('request', (request) => {
        if (request.method() !== 'GET' && request.url().includes('/ending/')) {
            endingMutations.push({ url: request.url(), inertia: request.headers()['x-inertia'] });
        }
    });
    const index = ['desktop', 'tablet', 'mobile'].indexOf(info.project.name) + 1;
    const loginIndex = index + 3;
    await page.goto('/login');
    await page.getByLabel('Mobile Number', { exact: true }).fill(`91${loginIndex}1234567`);
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
    const capture = captures.find((record) => record.recipient === `+6391${loginIndex}1234567`)!;
    await page.getByLabel('OTP', { exact: true }).fill(capture.message.match(/\b([0-9]{6})\b/)![1]);
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/ending/games');
    await expect(page.getByRole('heading', { name: 'Scheduled Games', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Create Game', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Create Game', exact: true }).click();
    await expect(
        dialog.getByRole('alert').filter({ hasText: 'The team a field is required.' }).first(),
    ).toBeVisible();
    await dialog.getByRole('combobox', { name: 'League', exact: true }).click();
    await page
        .getByRole('listbox', { name: 'League', exact: true })
        .getByRole('option', { name: 'Test League', exact: true })
        .click();
    await dialog.getByRole('combobox', { name: 'Team A', exact: true }).click();
    await page
        .getByRole('listbox', { name: 'Team A', exact: true })
        .getByRole('option', { name: 'Blue Team', exact: true })
        .click();
    await dialog.getByRole('combobox', { name: 'Team B', exact: true }).click();
    await page
        .getByRole('listbox', { name: 'Team B', exact: true })
        .getByRole('option', { name: 'Red Team', exact: true })
        .click();
    await dialog.getByRole('button', { name: 'Create Game', exact: true }).click();
    await expect(
        dialog.getByRole('alert').filter({ hasText: 'The schedule field is required.' }).first(),
    ).toBeVisible();
    await expect(dialog.getByRole('combobox', { name: 'Team A', exact: true })).toHaveValue(
        'Blue Team',
    );
    const future = new Date(Date.now() + 86400000);
    const schedule = new Intl.DateTimeFormat('sv-SE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Asia/Manila',
        hourCycle: 'h23',
    })
        .format(future)
        .replace(' ', 'T');
    await dialog.getByLabel('Schedule (Philippine time)', { exact: true }).fill(schedule);
    await dialog.getByRole('button', { name: 'Create Game', exact: true }).click();
    await expect(page).toHaveURL(/\/ending\/games\/\d+$/);
    await expect(page.getByRole('tab', { name: 'Cards (2)', exact: true })).toBeVisible();
    await page.goto(`/ending/games/${100 + index}`);
    await page.getByRole('button', { name: 'Create Result', exact: true }).click();
    await dialog.getByLabel('Blue Team score', { exact: true }).fill('21');
    await dialog.getByLabel('Red Team score', { exact: true }).fill('30');
    await expect(dialog.getByText('1-0', { exact: true })).toBeVisible();
    await dialog.getByRole('button', { name: 'Save Result', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await page.getByRole('tab', { name: 'Winners (1)', exact: true }).click();
    await page.getByRole('button', { name: 'Verify', exact: true }).click();
    await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByText('Verified', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Approve & Declare', exact: true }).click();
    await expect(dialog.getByText(/Browser Test Player/)).toBeVisible();
    await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByText('Approved', { exact: true })).toBeVisible();
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth),
    ).toBe(false);
    await page.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-ending-winners.png`,
        fullPage: true,
    });
    await page.getByRole('tab', { name: 'Activity', exact: true }).click();
    await expect(page.getByRole('cell', { name: 'declare winner', exact: true })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'verify winner', exact: true })).toBeVisible();
    expect(errors).toEqual([]);
    expect(endingMutations.length).toBeGreaterThanOrEqual(6);
    expect(
        endingMutations.every(
            (request) => request.inertia === 'true' && !request.url.includes('/api/ending'),
        ),
    ).toBe(true);
});
