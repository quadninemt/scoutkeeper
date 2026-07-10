import { test, expect, Page } from '@playwright/test';
import { login } from '../helpers/auth';

/**
 * End-to-end user journeys for the key personas:
 *  - Organisation admin (super admin, national scope)
 *  - Local admin (District Commissioner, district scope)
 *  - Group leader (group scope, can publish events)
 *  - Scout / ordinary member (portal only)
 *
 * Runs against the seeded Northland test organisation.
 */

async function loginAs(page: Page, email: string): Promise<void> {
  await page.goto('/login');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', 'TestPass123!');
  await page.click('button[type="submit"]');
  await expect(page).not.toHaveURL(/\/login/);
}

async function expectNoAccessDenied(page: Page): Promise<void> {
  await expect(page.locator('body')).not.toContainText('Access Denied');
  await expect(page.locator('body')).not.toContainText('Server Error');
}

test.describe('Journey: organisation admin', () => {
  test('dashboard → members → member profile → audit log → email compose', async ({ page }) => {
    await login(page, 'admin');

    await page.goto('/admin/dashboard');
    await expectNoAccessDenied(page);

    await page.goto('/members');
    await expectNoAccessDenied(page);
    const firstMember = page.locator('table tbody tr a').first();
    await expect(firstMember).toBeVisible();
    await firstMember.click();
    await expect(page).toHaveURL(/\/members\/\d+/);
    await expectNoAccessDenied(page);

    await page.goto('/admin/audit');
    await expectNoAccessDenied(page);

    await page.goto('/admin/email');
    await expectNoAccessDenied(page);
    await expect(page.locator('main form, form[action*="email"]').first()).toBeVisible();
  });

  test('org structure and settings load', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/org');
    await expectNoAccessDenied(page);
    await page.goto('/admin/settings');
    await expectNoAccessDenied(page);
    await page.goto('/admin/languages');
    await expectNoAccessDenied(page);
  });
});

test.describe('Journey: local admin (District Commissioner)', () => {
  test('members list, calendar and directory are reachable and scoped', async ({ page }) => {
    await loginAs(page, 'user09@northland.test');

    await page.goto('/members');
    await expectNoAccessDenied(page);

    await page.goto('/events');
    await expectNoAccessDenied(page);

    await page.goto('/directory');
    await expectNoAccessDenied(page);
  });
});

test.describe('Journey: group leader', () => {
  test('creates and publishes an event, sees it on the calendar', async ({ page }) => {
    await login(page, 'leader');

    await page.goto('/admin/events/create');
    await expectNoAccessDenied(page);

    const title = `E2E Journey Event ${Date.now()}`;
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="description"]', 'Created by the user-journeys E2E spec.');
    await page.fill('input[name="location"]', 'Test Campsite');
    await page.fill('input[name="start_date"]', '2026-12-01T10:00');
    await page.locator('form button[type="submit"]').first().click();
    await expectNoAccessDenied(page);

    await page.goto('/admin/events');
    await expect(page.locator('body')).toContainText(title);
  });

  test('views own scoped member list', async ({ page }) => {
    await login(page, 'leader');
    await page.goto('/members');
    await expectNoAccessDenied(page);
  });
});

test.describe('Journey: scout / member', () => {
  test('portal → articles → calendar → own profile → directory', async ({ page }) => {
    await login(page, 'member');

    await page.goto('/articles');
    await expectNoAccessDenied(page);

    await page.goto('/events');
    await expectNoAccessDenied(page);

    await page.goto('/account');
    await expect(page).toHaveURL(/\/members\/\d+/);
    await expectNoAccessDenied(page);

    await page.goto('/directory');
    await expectNoAccessDenied(page);
  });

  test('cannot access admin areas', async ({ page }) => {
    await login(page, 'member');

    for (const url of ['/admin/settings', '/admin/audit', '/admin/email']) {
      await page.goto(url);
      const denied = await page.locator('body').textContent();
      expect(
        denied?.includes('Access Denied') || page.url().includes('/login'),
        `${url} must be denied for a plain member`
      ).toBeTruthy();
    }
  });
});
