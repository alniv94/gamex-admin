import { test, expect as baseExpect } from '@playwright/test';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

const expect = baseExpect.configure({ timeout: 30000 });

test('settings manages role permissions, direct grants and user activation at each viewport', async ({
    page,
}, info) => {
    test.setTimeout(180000);
    const index = ['desktop', 'tablet', 'mobile'].indexOf(info.project.name) + 1;
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/login');
    await page.getByLabel('Mobile Number', { exact: true }).fill('920123456' + index);
    const folder = path.resolve('storage/app/private/browser-notifications');
    const existing = new Set(existsSync(folder) ? readdirSync(folder) : []);
    await page.getByRole('button', { name: 'Send OTP', exact: true }).click();
    await expect(
        page.getByText(
            'Local test mode: no SMS was sent. The OTP is in the private notifications folder.',
            { exact: true },
        ),
    ).toBeVisible();
    const capture = readdirSync(folder)
        .filter((file) => !existing.has(file))
        .map(
            (file) =>
                JSON.parse(readFileSync(path.join(folder, file), 'utf8')) as {
                    recipient: string;
                    message: string;
                },
        )
        .find((record) => record.recipient === '+63920123456' + index)!;
    await page.getByLabel('OTP', { exact: true }).fill(capture.message.match(/\b([0-9]{6})\b/)![1]);
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    if (info.project.name !== 'desktop')
        await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    await page.getByRole('link', { name: 'Users', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Users', exact: true })).toBeVisible();

    const roleName = 'Support team ' + index;
    const userName = 'Browser Managed Admin ' + index;
    await page.getByRole('tab', { name: 'Roles', exact: true }).click();
    await page.getByRole('button', { name: 'Create role', exact: true }).click();
    const roleDialog = page.getByRole('dialog', { name: 'Role details' });
    await roleDialog.getByLabel('Role name', { exact: true }).fill(roleName);
    await roleDialog.getByLabel('Search permissions', { exact: true }).fill('Player List');
    await roleDialog.getByRole('checkbox', { name: 'Grant: Player List', exact: true }).check();
    await roleDialog.getByRole('button', { name: 'Create role', exact: true }).click();
    await expect(roleDialog).not.toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: roleName })).toBeVisible();

    await page.getByRole('tab', { name: 'Users', exact: true }).click();
    await page.getByRole('link', { name: 'Create user', exact: true }).click();
    await expect(page).toHaveURL(/\/settings\/users\/create$/);
    await expect(page.getByRole('heading', { name: 'Create user', exact: true })).toBeVisible();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(page).toHaveURL(/\/settings\/users$/);
    await page.getByRole('link', { name: 'Create user', exact: true }).click();
    await page.getByRole('button', { name: 'Create user', exact: true }).click();
    await expect(
        page.getByRole('alert').filter({ hasText: 'Check the details below' }),
    ).toBeVisible();
    await expect(page).toHaveURL(/\/settings\/users\/create$/);
    await page.getByLabel('Name', { exact: true }).fill(userName);
    await page.getByLabel('Mobile number', { exact: true }).fill('0930123456' + index);
    await page
        .getByLabel('Email (optional)', { exact: true })
        .fill('managed' + index + '@example.test');
    await page.getByRole('combobox', { name: 'Roles', exact: true }).fill(roleName);
    await page.getByRole('option', { name: roleName, exact: true }).click();
    await page.getByLabel('Name', { exact: true }).click();
    await page.getByLabel('Search permissions', { exact: true }).fill('Cash In');
    await page.getByRole('checkbox', { name: 'Direct grant: Cash In', exact: true }).check();
    await expect(
        page.getByRole('complementary', { name: 'Access summary' }).getByRole('definition').last(),
    ).toHaveText('2');
    await page.getByLabel('Search permissions', { exact: true }).fill('');
    await page.getByRole('checkbox', { name: 'Assigned only', exact: true }).check();
    await expect(page.getByText('From role: ' + roleName, { exact: true })).toBeVisible();
    await page.getByRole('checkbox', { name: 'Assigned only', exact: true }).uncheck();
    await page.screenshot({ path: info.outputPath('create-user.png'), fullPage: true });
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1),
    ).toBeTruthy();
    await page.getByRole('button', { name: 'Create user', exact: true }).click();
    await expect(page).toHaveURL(/\/settings\/users$/);
    await expect(page.getByRole('status')).toContainText('User created.');
    const userDialog = page.getByRole('dialog', { name: 'User details' });
    const userRow = page.getByRole('row').filter({ hasText: userName });
    await expect(userRow).toContainText('2 assigned');
    await expect(userRow).toContainText('1 inherited · 1 direct');

    await userRow.getByRole('button', { name: 'Edit ' + userName, exact: true }).click();
    await userDialog.getByLabel('Search permissions', { exact: true }).fill('Player List');
    await expect(userDialog.getByText('From role: ' + roleName, { exact: true })).toBeVisible();
    await expect(
        userDialog.getByRole('checkbox', { name: 'Direct grant: Player List', exact: true }),
    ).not.toBeChecked();
    await page.screenshot({ path: info.outputPath('inherited-permissions.png'), fullPage: true });
    await userDialog.getByLabel('Search permissions', { exact: true }).fill('Cash In');
    await userDialog
        .getByRole('checkbox', { name: 'Direct grant: Cash In', exact: true })
        .uncheck();
    await userDialog.getByRole('button', { name: 'Save user', exact: true }).click();
    await expect(userDialog).not.toBeVisible();
    await expect(userRow).toContainText('1 assigned');
    await expect(userRow).toContainText('1 inherited · 0 direct');

    await page.getByRole('tab', { name: 'Roles', exact: true }).click();
    await page.getByRole('button', { name: 'Edit role ' + roleName, exact: true }).click();
    await roleDialog.getByLabel('Search permissions', { exact: true }).fill('Cash Out');
    await roleDialog.getByRole('checkbox', { name: 'Grant: Cash Out', exact: true }).check();
    await roleDialog.getByRole('button', { name: 'Save role', exact: true }).click();
    await expect(roleDialog).not.toBeVisible();
    await page.getByRole('tab', { name: 'Users', exact: true }).click();
    await expect(userRow).toContainText('2 assigned');
    await expect(userRow).toContainText('2 inherited · 0 direct');

    await userRow.getByRole('button', { name: 'Deactivate ' + userName, exact: true }).click();
    const statusDialog = page.getByRole('dialog', { name: 'Change account status' });
    await statusDialog.getByRole('button', { name: 'Deactivate user', exact: true }).click();
    await expect(statusDialog).not.toBeVisible();
    await expect(userRow).toContainText('Inactive');
    await userRow.getByRole('button', { name: 'Activate ' + userName, exact: true }).click();
    await statusDialog.getByRole('button', { name: 'Activate user', exact: true }).click();
    await expect(statusDialog).not.toBeVisible();
    await expect(userRow.getByText('Active', { exact: true })).toBeVisible();
    await page.getByLabel('Search users', { exact: true }).fill(userName);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody tr')).toContainText(userName);
    await page.screenshot({ path: info.outputPath('users.png'), fullPage: true });
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1),
    ).toBeTruthy();
    expect(errors).toEqual([]);
});
