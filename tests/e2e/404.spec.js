// @ts-check
const { test, expect } = require('@playwright/test');

test('404-Seite mit Suche und Kern-CTAs', async ({ page, request, baseURL }) => {
  const antwort = await request.get(baseURL + '/diese-seite-gibt-es-nicht/');
  expect(antwort.status()).toBe(404);
  expect(antwort.headers()['x-robots-tag']).toContain('noindex');

  await page.goto('/diese-seite-gibt-es-nicht/');
  await expect(page.locator('h1')).toHaveCount(1);
  await expect(page.locator('a[href="/angebot/"], a[href^="/angebot/"]').first()).toBeVisible();
});
