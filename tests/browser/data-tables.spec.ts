import { test, expect } from '@playwright/test';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { csvContent } from '../../resources/js/composables/useTableExport';
import { ref } from 'vue';
import { useTableFilters } from '../../resources/js/composables/useTableFilters';
import type { TableFilterChanges } from '../../resources/js/composables/useTableFilters';

test('filter summaries use applied values, resolve names and preserve other constraints when removed', () => {
    const applied = ref({
        search: '',
        status: 'All',
        type: 'Regular',
        date_from: '2026-11-02',
        date_to: '2026-11-02',
        outlet_id: 7,
        sort: 'wallet',
        per_page: 25,
    });
    const changes: TableFilterChanges[] = [];
    const { activeFilters, removeFilter, resetTableFilters } = useTableFilters(
        applied,
        [
            'search',
            'status',
            'type',
            { key: 'date_range', removable: false },
            { key: 'outlet_id', options: [{ value: 7, title: 'North outlet' }] },
        ],
        (value) => changes.push(value),
    );
    expect(activeFilters.value).toEqual([
        { key: 'type', label: 'Type', value: 'Regular', removable: true },
        { key: 'date_range', label: 'Date', value: 'Nov 2, 2026', removable: false },
        { key: 'outlet_id', label: 'Outlet', value: 'North outlet', removable: true },
    ]);
    removeFilter('date_range');
    expect(changes).toEqual([]);
    removeFilter('outlet_id');
    expect(changes[0]).toEqual({
        search: '',
        status: 'All',
        type: 'Regular',
        date_from: '2026-11-02',
        date_to: '2026-11-02',
        outlet_id: null,
    });
    expect(activeFilters.value).toHaveLength(3);
    resetTableFilters();
    expect(changes[1]).toEqual({
        search: '',
        status: 'All',
        type: 'All',
        date_from: '',
        date_to: '',
        outlet_id: null,
    });
});

test('CSV preserves quoted fields and Unicode while escaping spreadsheet formulas', () => {
    expect(
        csvContent([
            ['Name', 'Value', 'Notes'],
            ['José', '=SUM(A1)', 'He said "yes"\nand left'],
            [null, '  @command', { status: 'Active' }],
        ]),
    ).toBe(
        '\uFEFF"Name","Value","Notes"\r\n"José","\'=SUM(A1)","He said ""yes""\nand left"\r\n"","\'  @command","{""status"":""Active""}"',
    );
});

test('shared tables preserve server paging and export only matching local rows', async ({
    page,
}, info) => {
    test.setTimeout(90000);
    page.setDefaultTimeout(15000);
    const index = ['desktop', 'tablet', 'mobile'].indexOf(info.project.name) + 1;
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/login');
    await page.getByLabel('Mobile Number', { exact: true }).fill(`91${index}1234567`);
    const folder = path.resolve('storage/app/private/browser-notifications');
    const previous = new Set(existsSync(folder) ? readdirSync(folder) : []);
    await page.getByRole('button', { name: 'Send OTP', exact: true }).click();
    await expect(
        page.getByText(
            'Local test mode: no SMS was sent. The OTP is in the private notifications folder.',
            { exact: true },
        ),
    ).toBeVisible({ timeout: 15000 });
    await expect
        .poll(() =>
            existsSync(folder)
                ? readdirSync(folder).filter((file) => !previous.has(file)).length
                : 0,
        )
        .toBeGreaterThan(0);
    const capture = readdirSync(folder)
        .filter((file) => !previous.has(file))
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

    await page.goto('/ending/games?per_page=5');
    const appliedFilters = page.getByRole('region', { name: 'Active filters', exact: true });
    await expect(appliedFilters).toContainText('Date:');
    await page.getByRole('button', { name: 'Filter games', exact: true }).click();
    const filterDialog = page.getByRole('dialog', { name: 'Filters', exact: true });
    await filterDialog.getByLabel('League', { exact: true }).fill('Test');
    await page.getByRole('option', { name: 'Test League', exact: true }).click();
    await expect(appliedFilters).not.toContainText('League: Test League');
    await filterDialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(appliedFilters).not.toContainText('League: Test League');
    await page.getByRole('button', { name: 'Filter games', exact: true }).click();
    await expect(filterDialog.getByLabel('League', { exact: true })).toHaveValue('');
    await filterDialog.getByLabel('League', { exact: true }).fill('Test');
    await page.getByRole('option', { name: 'Test League', exact: true }).click();
    await filterDialog.getByRole('combobox', { name: 'Status', exact: true }).press('Enter');
    await page.getByRole('option', { name: 'Scheduled', exact: true }).click();
    await filterDialog.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await expect(filterDialog).not.toBeVisible();
    await expect(appliedFilters).toContainText('League: Test League');
    await expect(appliedFilters).toContainText('Status: Scheduled');
    await page.reload();
    await expect(appliedFilters).toContainText('League: Test League');
    await appliedFilters.getByRole('button', { name: 'Remove League filter', exact: true }).click();
    await expect(appliedFilters).not.toContainText('League: Test League');
    await expect(appliedFilters).toContainText('Date:');
    await expect(appliedFilters).toContainText('Status: Scheduled');
    await expect(page.locator('.admin-table tbody tr')).toHaveCount(5);
    await page.getByRole('button', { name: 'Next page', exact: true }).click();
    await expect(page).toHaveURL(/page=2/);
    await page.getByLabel('Search', { exact: true }).fill('Blue');
    await expect(page).toHaveURL(/search=Blue/);
    await expect(page).not.toHaveURL(/[?&]page=2/);
    await expect(page.locator('.admin-table tbody tr')).toHaveCount(5);
    await page.getByRole('button', { name: 'Export data', exact: true }).click();
    const serverDownload = page.waitForEvent('download');
    await page.getByRole('listitem').filter({ hasText: 'Current page (CSV)' }).click();
    const serverCsv = readFileSync((await (await serverDownload).path())!, 'utf8');
    expect(serverCsv.split('\r\n')).toHaveLength(6);
    await appliedFilters.getByRole('button', { name: 'Reset filters', exact: true }).click();
    await expect(appliedFilters).not.toContainText('Status: Scheduled');
    await expect(appliedFilters).not.toContainText('Search: Blue');
    await expect(appliedFilters).toContainText('Date:');

    await page.goto('/ending/config');
    const table = page.locator('.admin-data-table').first();
    await expect(table.locator('tbody tr')).toHaveCount(4);
    await table.getByLabel('Search configurations', { exact: true }).fill('4th');
    await expect(table.locator('tbody tr')).toHaveCount(1);
    await expect(table.locator('tbody')).toContainText('4th Quarter');
    await table.getByRole('button', { name: 'Export data', exact: true }).click();
    const localDownload = page.waitForEvent('download');
    await page.getByRole('listitem').filter({ hasText: 'All matching rows (CSV)' }).click();
    const localCsv = readFileSync((await (await localDownload).path())!, 'utf8');
    expect(localCsv.split('\r\n')).toHaveLength(2);
    expect(localCsv).toContain('4th Quarter');
    expect(localCsv).not.toContain('1st Quarter');
    expect(localCsv).not.toContain('Actions');
    await table.locator('.v-field__clearable').click();
    await expect(table.locator('tbody tr')).toHaveCount(4);
    await table.getByRole('columnheader', { name: 'Value' }).click();
    await expect(table.getByRole('columnheader', { name: 'Value' })).toHaveAttribute(
        'aria-sort',
        'ascending',
    );
    await table
        .getByLabel('Search configurations', { exact: true })
        .fill('no matching configuration');
    await expect(table.getByText('No configuration found.', { exact: true })).toBeVisible();
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1),
    ).toBe(true);
    await page.screenshot({ path: info.outputPath('shared-tables.png'), fullPage: true });

    await page.goto('/players/list');
    const playerFilters = page.getByRole('region', { name: 'Active filters', exact: true });
    await expect(playerFilters).toContainText('Status: Active');
    await expect(playerFilters).toContainText('Verification: Files Submitted');
    await playerFilters
        .getByRole('button', { name: 'Remove Verification filter', exact: true })
        .click();
    await expect(playerFilters).not.toContainText('Verification: Files Submitted');
    await expect(playerFilters).toContainText('Status: Active');
    await playerFilters.getByRole('button', { name: 'Reset filters', exact: true }).click();
    await expect(playerFilters).toHaveCount(0);
    expect(errors).toEqual([]);
});
