import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Session & Timeout', () => {
  test('session persists across page navigation', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/dashboard');
    await expect(page).not.toHaveURL(/\/login/);
    await page.goto('/members');
    await expect(page).not.toHaveURL(/\/login/);
    await page.goto('/admin/settings');
    await expect(page).not.toHaveURL(/\/login/);
  });

  test('expired session redirects to login', async ({ page }) => {
    await login(page, 'admin');
    // Clear session cookies to simulate expiry
    await page.context().clearCookies();
    await page.goto('/admin/dashboard');
    await expect(page).toHaveURL(/\/login/);
  });

  test('CSRF token is present in forms', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/members/create');
    // Use .first() to avoid strict-mode violation when multiple forms on the
    // page each carry their own _csrf_token (language picker, view-mode switcher,
    // the main member form, etc.).
    const csrfInput = page.locator('input[name="_csrf_token"], input[name="_csrf"], input[name="csrf_token"], input[name="_token"]').first();
    await expect(csrfInput).toBeAttached();
    const value = await csrfInput.getAttribute('value');
    expect(value).toBeTruthy();
    expect(value!.length).toBeGreaterThan(10);
  });

  test('submitting form without CSRF token is rejected', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/members/create');
    // Remove ALL CSRF tokens from every form on the page (language switcher,
    // view-mode switcher, main form, etc.) so the POST truly has no token.
    await page.evaluate(() => {
      document.querySelectorAll('input[name="_csrf_token"], input[name="_csrf"], input[name="csrf_token"], input[name="_token"]')
        .forEach((el) => el.remove());
    });
    await page.fill('input[name="first_name"]', 'Test');
    await page.fill('input[name="surname"]', 'User');
    await page.click('button[type="submit"]');
    // Should show an error, not succeed
    await expect(page.locator('body')).toContainText(/csrf|token|invalid|expired|error/i);
  });
});
