import { test, expect } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

test('ending creation, result, verification, declaration, and audit at each viewport', async ({
    page,
}, info) => {
    test.setTimeout(90000);
    page.setDefaultTimeout(10000);
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
    await dialog.getByLabel('Schedule Date', { exact: true }).fill(schedule.split('T')[0]);
    await dialog.getByLabel('Schedule Time', { exact: true }).fill(schedule.split('T')[1]);
    await page.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-ending-create.png`,
        fullPage: true,
    });
    await dialog.getByRole('button', { name: 'Create Game', exact: true }).click();
    await expect(page).toHaveURL(/\/ending\/games\/\d+$/);
    await expect(page.getByRole('tab', { name: 'Cards (2)', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Add Per Quarter 15.00 cards', exact: true }).click();
    await dialog.getByLabel('Number of cards', { exact: true }).fill('12');
    await dialog.getByRole('button', { name: 'Create Cards', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('tab', { name: 'Cards (14)', exact: true })).toBeVisible();
    await page.getByRole('tab', { name: 'Activity', exact: true }).click();
    const addedCards = page
        .getByRole('row')
        .filter({ has: page.getByRole('cell', { name: 'add card', exact: true }) });
    await expect(
        addedCards.getByText('12 cards added · 2 → 14 cards', { exact: true }),
    ).toBeVisible();
    await addedCards.getByRole('button', { name: 'View details', exact: true }).click();
    const counts = page.getByRole('table', { name: 'Before and after values', exact: true });
    const cardCounts = counts.getByRole('row').filter({ hasText: 'Cards at this bet amount' });
    await expect(cardCounts.getByRole('cell', { name: '2', exact: true })).toBeVisible();
    await expect(cardCounts.getByRole('cell', { name: '14', exact: true })).toBeVisible();
    const creation = page
        .getByRole('row')
        .filter({ has: page.getByRole('cell', { name: 'create game', exact: true }) });
    await creation.getByRole('button', { name: 'View details', exact: true }).click();
    const initialPresets = page.getByRole('table', { name: 'Initial card presets', exact: true });
    await expect(
        initialPresets.getByRole('cell', { name: 'Per Quarter', exact: true }),
    ).toBeVisible();
    await expect(initialPresets.getByRole('cell', { name: '2', exact: true })).toBeVisible();
    await expect(page.getByText('Q1: 150.00 tokens', { exact: true })).toBeVisible();
    await expect(page.locator('.ending-activity pre')).toHaveCount(0);
    await page.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-ending-activity.png`,
        fullPage: true,
        animations: 'disabled',
    });
    await page.goto(`/ending/games/${100 + index}`);
    const gameUrl = page.url();
    const viewCards = page.getByRole('link', { name: 'View Per Quarter 15.00 cards', exact: true });
    await expect(viewCards).toHaveAttribute('target', '_blank');
    const cardsPopup = page.waitForEvent('popup');
    await viewCards.click();
    const cardsPage = await cardsPopup;
    cardsPage.on('pageerror', (error) => errors.push(error.message));
    await expect(cardsPage).toHaveURL(
        new RegExp(`/ending/games/${100 + index}/cards\\?bet_amount_id=${200 + index}$`),
    );
    await expect(page).toHaveURL(gameUrl);
    await expect(cardsPage.getByRole('heading', { name: 'Game Cards', exact: true })).toBeVisible();
    await expect(
        cardsPage.getByText('Historical ending games are being prepared.', { exact: false }),
    ).toHaveCount(0);
    await expect(cardsPage.getByText('Bet amount: PHP 15.00', { exact: true })).toBeVisible();
    const cardNumber = `#000${300 + index}`;
    const cardRow = cardsPage
        .getByRole('row')
        .filter({ has: cardsPage.getByRole('cell', { name: cardNumber, exact: true }) });
    await expect(cardRow.getByRole('cell', { name: 'PHP 15.00', exact: true })).toBeVisible();
    await expect(cardRow.getByText('1%', { exact: true })).toBeVisible();
    await cardRow
        .getByRole('button', { name: `View card ${cardNumber} details`, exact: true })
        .click();
    const cardDialog = cardsPage.getByRole('dialog');
    await expect(
        cardDialog.getByRole('cell', { name: 'Browser Test Player', exact: true }),
    ).toBeVisible();
    await expect(cardDialog.getByText('1-0', { exact: true })).toBeVisible();
    await cardsPage.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-ending-card-details.png`,
        fullPage: true,
        animations: 'disabled',
    });
    await cardDialog.getByRole('button', { name: 'Close', exact: true }).click();
    await cardsPage.getByRole('textbox', { name: 'Search', exact: true }).fill('missing');
    await expect(
        cardsPage.getByText('No cards match this card number.', { exact: true }),
    ).toBeVisible();
    await cardsPage.getByRole('textbox', { name: 'Search', exact: true }).fill(cardNumber.slice(1));
    await expect(cardRow).toBeVisible();
    const cardDownload = cardsPage.waitForEvent('download');
    await cardsPage.getByRole('button', { name: 'Export current page', exact: true }).click();
    expect((await cardDownload).suggestedFilename()).toBe(`ending-game-${100 + index}-cards.csv`);
    expect(
        await cardsPage.evaluate(() => document.documentElement.scrollWidth > window.innerWidth),
    ).toBe(false);
    await cardsPage.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-ending-cards.png`,
        fullPage: true,
        animations: 'disabled',
    });
    await cardsPage.close();
    const allCardsPopup = page.waitForEvent('popup');
    await page.getByRole('link', { name: 'View All Cards', exact: true }).click();
    const allCardsPage = await allCardsPopup;
    allCardsPage.on('pageerror', (error) => errors.push(error.message));
    await expect(allCardsPage).toHaveURL(new RegExp(`/ending/games/${100 + index}/cards$`));
    await expect(allCardsPage.getByText('All bet amounts', { exact: true })).toBeVisible();
    await allCardsPage.close();
    await page.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-ending-overview.png`,
        fullPage: true,
        animations: 'disabled',
    });
    await page.getByRole('button', { name: 'Create Result', exact: true }).click();
    await dialog.getByLabel('Blue Team score', { exact: true }).fill('21');
    await dialog.getByLabel('Red Team score', { exact: true }).fill('30');
    await expect(dialog.getByText('1-0', { exact: true })).toBeVisible();
    await page.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-ending-result.png`,
        fullPage: true,
    });
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
    const earlierCards = page
        .getByRole('row')
        .filter({ has: page.getByRole('cell', { name: 'add card', exact: true }) });
    await earlierCards.getByRole('button', { name: 'View details', exact: true }).click();
    await expect(
        page.getByText(
            'Previous values were not recorded at the time. They cannot be recovered from current preset settings.',
            { exact: true },
        ),
    ).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Not recorded', exact: true })).toBeVisible();

    const leagueName = `Browser League ${info.project.name}`;
    await page.goto('/ending/leagues');
    await page.getByRole('button', { name: 'Create League', exact: true }).click();
    await dialog.getByLabel('Name', { exact: true }).fill(leagueName);
    await dialog.getByLabel('Logo URL', { exact: true }).fill('https://example.test/league.png');
    await dialog.getByRole('radio', { name: 'International', exact: true }).check();
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('cell', { name: leagueName, exact: true })).toBeVisible();
    await page.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-ending-leagues.png`,
        fullPage: true,
    });

    const teamName = `Browser Team ${info.project.name}`;
    await page.goto('/ending/teams');
    await page.getByRole('button', { name: 'Create Team', exact: true }).click();
    await dialog.getByRole('combobox', { name: 'League', exact: true }).click();
    await page
        .getByRole('listbox', { name: 'League', exact: true })
        .getByRole('option', { name: leagueName, exact: true })
        .click();
    await dialog.getByLabel('Name', { exact: true }).fill(teamName);
    await dialog.getByLabel('Alias', { exact: true }).fill(`Stars ${index}`);
    await dialog.getByLabel('Logo URL', { exact: true }).fill('https://example.test/team.png');
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await page.getByRole('textbox', { name: 'Search', exact: true }).fill(teamName);
    await expect(page.getByRole('cell', { name: teamName, exact: true })).toBeVisible();
    await page.getByRole('button', { name: `Edit ${teamName}`, exact: true }).click();
    await dialog.getByLabel('Alias', { exact: true }).fill(`Comets ${index}`);
    await dialog.getByRole('button', { name: 'Save Changes', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('cell', { name: `Comets ${index}`, exact: true })).toBeVisible();
    const download = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Export current page', exact: true }).click();
    expect((await download).suggestedFilename()).toBe('ending-teams.csv');
    await page.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-ending-teams.png`,
        fullPage: true,
    });

    await page.goto('/ending/config');
    await page.getByRole('button', { name: 'New Preset', exact: true }).click();
    await dialog.getByLabel('Amount', { exact: true }).fill(String(200 + index));
    await dialog.getByLabel('Card quantity', { exact: true }).fill('3');
    await dialog.getByRole('button', { name: 'Save Preset', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByText('5 cards per new game', { exact: true })).toBeVisible();
    await page
        .getByRole('button', { name: `Edit Per Quarter ${200 + index}.00 preset`, exact: true })
        .click();
    await dialog.getByLabel('Card quantity', { exact: true }).fill('4');
    await dialog.getByRole('button', { name: 'Save Preset', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByText('6 cards per new game', { exact: true })).toBeVisible();
    await page.screenshot({
        path: `storage/app/visual-baselines/${info.project.name}-ending-config.png`,
        fullPage: true,
    });
    await page
        .getByRole('button', { name: `Delete Per Quarter ${200 + index}.00 preset`, exact: true })
        .click();
    await dialog.getByRole('button', { name: 'Delete Preset', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByText('2 cards per new game', { exact: true })).toBeVisible();
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth),
    ).toBe(false);
    expect(errors).toEqual([]);
    expect(endingMutations.length).toBeGreaterThanOrEqual(6);
    expect(
        endingMutations.every(
            (request) => request.inertia === 'true' && !request.url.includes('/api/ending'),
        ),
    ).toBe(true);
});
