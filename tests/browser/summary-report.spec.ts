import { test, expect } from '@playwright/test';

test('summary report supports sorting, pagination, refresh, and responsive navigation', async ({
    page,
    request,
}, info) => {
    page.setDefaultTimeout(10000);
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const login = await request.get('/login');
    const html = (await login.text()).replace(
        /<script\b[^>]*src="[^"]*\/herd-studio\/[^"]*"[^>]*><\/script>/g,
        '',
    );
    const pageScript = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    const initialPage = JSON.parse(html.match(pageScript)![2]);
    const records = Array.from({ length: 12 }, (_, index) => ({
        year: 2015 + index,
        total_players: 100 + index,
        bet_count: 1000 + index,
        bet_amount: index === 0 ? '1000.00' : index === 1 ? '25.00' : '100.00',
    }));
    const payload = {
        ...initialPage,
        component: 'SummaryReport',
        url: '/summary_report',
        props: {
            ...initialPage.props,
            auth: {
                user: {
                    id: 1,
                    name: 'Report Administrator',
                    mobile_number: '+639171234567',
                    email: null,
                    status: 'Active',
                    permissions: ['Players Summary'],
                    roles: [],
                },
            },
            records,
        },
    };
    let refreshes = 0;
    await page.route('**/summary_report', async (route) => {
        if (route.request().headers()['x-inertia']) {
            expect(route.request().headers()['x-inertia-partial-data']).toBe('records');
            refreshes++;
            payload.props.records[0].total_players = 999;
            await route.fulfill({
                json: payload,
                headers: { 'X-Inertia': 'true' },
            });
        } else {
            await route.fulfill({
                contentType: 'text/html',
                body: html.replace(
                    pageScript,
                    (_, open, _data, close) =>
                        open + JSON.stringify(payload).replace(/</g, '\\u003c') + close,
                ),
            });
        }
    });

    await page.goto('/summary_report');
    await expect(page.getByRole('heading', { name: 'Summary Report', exact: true })).toBeVisible();
    const table = page.getByRole('table', { name: 'Yearly summary report' });
    const rows = table.locator('tbody tr');
    await expect(rows).toHaveCount(10);
    await expect(rows.first().locator('td').first()).toHaveText('2015');
    await expect(rows.first()).toContainText('₱1,000.00');
    await page.screenshot({ path: info.outputPath('summary-report.png'), fullPage: true });

    await page.getByRole('button', { name: 'Next page', exact: true }).click();
    await expect(rows).toHaveCount(2);
    await expect(rows.first().locator('td').first()).toHaveText('2025');
    await page.getByRole('button', { name: 'Previous page', exact: true }).click();
    await page.getByRole('combobox', { name: 'Rows per page:', exact: true }).press('Enter');
    await page.getByRole('option', { name: '25', exact: true }).click();
    await expect(rows).toHaveCount(12);
    await table.getByRole('columnheader', { name: 'Bet Amount' }).click();
    await expect(rows.first().locator('td').first()).toHaveText('2016');
    await table.getByRole('columnheader', { name: 'Bet Amount' }).click();
    await expect(rows.first().locator('td').first()).toHaveText('2015');
    await expect(table.getByRole('columnheader', { name: 'Bet Amount' })).toHaveAttribute(
        'aria-sort',
        'descending',
    );

    await page.getByRole('button', { name: 'Refresh summary report', exact: true }).click();
    await expect(rows.first().locator('td').nth(1)).toHaveText('999');
    await expect(table.getByRole('columnheader', { name: 'Bet Amount' })).toHaveAttribute(
        'aria-sort',
        'descending',
    );
    await expect(rows).toHaveCount(12);
    expect(refreshes).toBe(1);

    if (page.viewportSize()!.width < 1264) {
        await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    }
    const link = page.getByRole('link', { name: 'Summary Report', exact: true });
    await expect(link).toBeVisible();
    await expect(link).toHaveAttribute('aria-current', 'page');
    if (page.viewportSize()!.width < 1264) {
        await page.getByRole('button', { name: 'Close sidebar', exact: true }).click();
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
    );
    expect(errors).toEqual([]);
});
