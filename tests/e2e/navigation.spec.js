// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('Tastaturbedienung der Hauptnavigation', () => {
  test('Untermenü Leistungen öffnet mit Enter und schließt mit Escape', async ({ page }) => {
    await page.goto('/');
    const toggle = page.locator('[data-nav-toggle]').first();
    await toggle.focus();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');

    await page.keyboard.press('Enter');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');

    await page.keyboard.press('Escape');
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(toggle).toBeFocused();
  });

  test('Sprunglink führt direkt zum Inhalt', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('Tab');
    await expect(page.locator('.u-skip-link')).toBeFocused();
  });
});

test.describe('Mobile Bottom-Bar', () => {
  test('ist unter 768 px sichtbar mit den drei Aktionen', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    const bar = page.locator('.c-mobilbar');
    await expect(bar).toBeVisible();
    await expect(bar.getByText('Angebot')).toBeVisible();
    await expect(bar.getByText('Notfall')).toBeVisible();
  });

  test('ist ab 768 px nicht sichtbar', async ({ page }) => {
    await page.setViewportSize({ width: 1024, height: 900 });
    await page.goto('/');
    await expect(page.locator('.c-mobilbar')).not.toBeVisible();
  });
});
