import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

test.describe('Directory & Organogram', () => {
  test('organogram page loads', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/directory');
    await expect(page.locator('body')).toContainText(/scouts of northland|organogram|structure/i);
  });

  test('contacts page loads', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/directory/contacts');
    // The contacts page heading is "Directory"; the count badge says "N people" / "N person".
    // These are always present regardless of the member's scope or which roles members hold.
    await expect(page.locator('body')).toContainText(/directory|people|person/i);
  });

  test('organogram shows hierarchy', async ({ page }) => {
    await login(page, 'member');
    await page.goto('/directory');
    await expect(page.locator('body')).toContainText(/region|district|group/i);
  });

  test('unauthenticated users cannot access directory', async ({ page }) => {
    await page.goto('/directory');
    await expect(page).toHaveURL(/\/login/);
  });
});
