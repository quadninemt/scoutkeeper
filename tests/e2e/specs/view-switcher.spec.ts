import { test, expect } from '@playwright/test';
import { login } from '../helpers/auth';

/**
 * Covers the topbar Member/Admin mode pills and the scope dropdown.
 *
 * Regression guard: the forms must submit a CSRF field name that matches
 * the global validator in Application.php (_csrf_token / _csrf). A mismatch
 * would produce a 403 on every pill click.
 */
/** Open the offcanvas sidebar on mobile so view-mode buttons become visible. */
async function openOffcanvasIfMobile(page: import('@playwright/test').Page): Promise<void> {
  const hamburger = page.locator('button[data-bs-toggle="offcanvas"]').first();
  if (await hamburger.isVisible({ timeout: 1_500 }).catch(() => false)) {
    await hamburger.click();
    // Wait for Bootstrap offcanvas open animation (300 ms default)
    await page.waitForTimeout(400);
  }
}

test.describe('View switcher', () => {
  test('admin user can switch to member mode and back', async ({ page }) => {
    await login(page, 'admin');

    // On mobile the desktop pills are hidden inside the offcanvas sidebar;
    // open it first so the buttons are interactable.
    await openOffcanvasIfMobile(page);

    const adminPill = page.locator('.view-mode-btn', { hasText: /admin/i }).first();
    const memberPill = page.locator('.view-mode-btn', { hasText: /member/i }).first();

    await expect(adminPill).toBeVisible();
    await expect(memberPill).toBeVisible();

    // Admin -> Member
    await memberPill.click();
    await expect(page).not.toHaveURL(/\/login/);
    await expect(page.locator('body')).not.toContainText(/CSRF token validation failed/i);

    // Re-open offcanvas after redirect (closes on navigation) before asserting active pill.
    await openOffcanvasIfMobile(page);
    await expect(
      page.locator('.view-mode-btn', { hasText: /member/i }).first()
    ).toHaveClass(/active/);

    // Member -> Admin
    await page.locator('.view-mode-btn', { hasText: /admin/i }).first().click();
    await expect(page).not.toHaveURL(/\/login/);
    await expect(page.locator('body')).not.toContainText(/CSRF token validation failed/i);
    await openOffcanvasIfMobile(page);
    await expect(
      page.locator('.view-mode-btn', { hasText: /admin/i }).first()
    ).toHaveClass(/active/);
  });

  test('mode-switch POST is not rejected as CSRF', async ({ page }) => {
    await login(page, 'admin');

    // Open offcanvas on mobile so the member pill is interactable.
    await openOffcanvasIfMobile(page);

    const response = await Promise.all([
      page.waitForResponse((r) => r.url().endsWith('/context/mode') && r.request().method() === 'POST'),
      page.locator('.view-mode-btn', { hasText: /member/i }).first().click(),
    ]).then(([r]) => r);

    expect(response.status(), 'POST /context/mode must not return 403 (CSRF)').not.toBe(403);
    expect([200, 302, 303]).toContain(response.status());
  });
});
