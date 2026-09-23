// @ts-check
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { ladeOeffentlicheSeiten } = require('./helpers/sitemap');

const seiten = ladeOeffentlicheSeiten();

for (const seite of seiten) {
  test.describe(`Seite ${seite.pfad}`, () => {
    test('lädt mit Status 200 und den Pflichtangaben in <head>', async ({ page }) => {
      const cspVerstoesse = [];
      page.on('console', (msg) => {
        if (msg.type() === 'error' && /content security policy|refused to/i.test(msg.text())) {
          cspVerstoesse.push(msg.text());
        }
      });

      const antwort = await page.goto(seite.pfad);
      expect(antwort?.status(), seite.pfad).toBe(200);

      await expect(page.locator('h1')).toHaveCount(1);
      await expect(page).toHaveTitle(/.+/);
      expect(await page.locator('meta[name="description"]').getAttribute('content')).toBeTruthy();
      expect(await page.locator('link[rel="canonical"]').getAttribute('href')).toContain(seite.pfad);

      if (seite.og) {
        expect(await page.locator('meta[property="og:image"]').getAttribute('content')).toContain('/og/' + seite.slug + '.png');
        expect(await page.locator('meta[property="og:image:alt"]').getAttribute('content')).toBeTruthy();
      }

      expect(cspVerstoesse, cspVerstoesse.join('\n')).toEqual([]);
    });

    test('axe: keine serious oder critical Befunde (WCAG 2.2 AA)', async ({ page }) => {
      await page.goto(seite.pfad);
      const ergebnis = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'])
        .analyze();

      const schwerwiegend = ergebnis.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(schwerwiegend, JSON.stringify(schwerwiegend, null, 2)).toEqual([]);
    });
  });
}
