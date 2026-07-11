import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

/**
 * Regression spec for bugfile item: "The searchbar at the top of the page
 * does not do anything when you enter text and then hit ENTER."
 */
test.describe('Topbar global search', () => {
  test('typing shows results dropdown', async ({ page }) => {
    await login(page, 'admin');
    await page.waitForLoadState('networkidle');
    const input = page.locator('.topbar-search input[type="search"]');
    await expect(input).toBeVisible();

    await input.pressSequentially('Anderson', { delay: 30 });
    const results = page.locator('#search-results');
    await expect(results.locator('.list-group-item').first()).toBeVisible({ timeout: 5000 });
  });

  test('pressing Enter shows results dropdown', async ({ page }) => {
    await login(page, 'admin');
    await page.waitForLoadState('networkidle');
    const input = page.locator('.topbar-search input[type="search"]');

    await input.pressSequentially('Anderson', { delay: 30 });
    await input.press('Enter');

    const results = page.locator('#search-results');
    await expect(results.locator('.list-group-item').first()).toBeVisible({ timeout: 5000 });
  });

  test('clicking the search button shows results dropdown', async ({ page }) => {
    await login(page, 'admin');
    await page.waitForLoadState('networkidle');

    // fill() intentionally: it fires no keyup, so only the button can trigger
    await page.locator('.topbar-search input[type="search"]').fill('Anderson');
    await page.locator('.topbar-search-btn').click();

    const results = page.locator('#search-results');
    await expect(results.locator('.list-group-item').first()).toBeVisible({ timeout: 5000 });
  });
});

test.describe('/account route', () => {
  test('regular member reaches their profile without 403', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/account');
    // /account → /me/profile → /members/{id} (own record)
    await expect(page).toHaveURL(/\/members\/\d+/);
    await expect(page.locator('body')).not.toContainText('Access Denied');
    await expect(page.locator('h1, h2').first()).toBeVisible();
  });
});
