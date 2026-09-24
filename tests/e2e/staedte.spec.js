// @ts-check
// Stadtseiten /hausverwaltung-<slug>/ (config/staedte.php, content/staedte/*.md) und Übersicht /betreuungsgebiete/.
// Der Testserver läuft mit SHOW_DRAFTS=true, daher sind auch Entwürfe erreichbar.
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');

const ROOT = path.resolve(__dirname, '..', '..');

/** @returns {Array<{slug: string, name: string, plz_von: string, plz_bis: string, indexierbar: boolean}>} */
function ladeStaedte() {
  const php = `echo json_encode(require '${ROOT}/config/staedte.php');`;
  return JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf-8' }));
}

const staedte = ladeStaedte();
const mitText = staedte.filter((s) => fs.existsSync(path.join(ROOT, 'content', 'staedte', `${s.slug}.md`)));

for (const stadt of mitText) {
  test(`Stadtseite ${stadt.slug} lädt mit 200, einer h1 und ohne CSP-Verstoß`, async ({ page }) => {
    const verstoesse = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error' && /content security policy|refused to/i.test(msg.text())) {
        verstoesse.push(msg.text());
      }
    });
    const pfad = `/hausverwaltung-${stadt.slug}/`;
    const antwort = await page.goto(pfad);
    expect(antwort?.status(), pfad).toBe(200);
    await expect(page.locator('h1')).toHaveCount(1);
    expect(await page.locator('link[rel="canonical"]').getAttribute('href')).toContain(pfad);
    await expect(page.locator('#plz-titel')).toContainText(`${stadt.plz_von} bis ${stadt.plz_bis}`);
    await expect(page.locator('.c-breadcrumbs')).toContainText('Betreuungsgebiete');
    expect(verstoesse, verstoesse.join('\n')).toEqual([]);
  });
}

for (const slug of ['koeln', 'muenchen', 'monheim-am-rhein', 'berlin']) {
  test(`axe: Stadtseite ${slug} ohne serious oder critical Befunde`, async ({ page }) => {
    test.skip(!mitText.some((s) => s.slug === slug), 'Stadttext fehlt noch');
    await page.goto(`/hausverwaltung-${slug}/`);
    const ergebnis = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'])
      .analyze();
    const schwer = ergebnis.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(schwer, JSON.stringify(schwer, null, 2)).toEqual([]);
  });
}

test('Übersicht zeigt alle Städte, Karte mit allen Punkten und Links zu den Stadtseiten', async ({ page }) => {
  await page.goto('/betreuungsgebiete/');
  await expect(page.locator('.c-gebiete__eintrag')).toHaveCount(staedte.length);
  await expect(page.locator('.c-karte-de__punkt')).toHaveCount(staedte.length);
  await expect(page.locator('.c-karte-de__punkt--hauptsitz')).toHaveCount(1);
  await expect(page.locator('.c-gebiete a[href^="/hausverwaltung-"]')).toHaveCount(mitText.length);
});

test('Stadtseite: Angebots-CTA belegt die Region vor', async ({ page }) => {
  test.skip(!mitText.some((s) => s.slug === 'koeln'), 'Stadttext fehlt noch');
  await page.goto('/hausverwaltung-koeln/');
  await page.locator('.c-cta-band a', { hasText: 'Angebot anfordern' }).first().click();
  await expect(page).toHaveURL(/\/angebot\/\?.*region=K%C3%B6ln/);
  await expect(page.locator('.c-angebot__region')).toContainText('Köln');
});

test('Sitemap enthält keine Stadtseiten ohne lokalen Bezug', async ({ request, baseURL }) => {
  // Der Testserver ist keine Produktion und liefert überall noindex; hier nur die Erreichbarkeit der Sitemap prüfen.
  const antwort = await request.get(`${baseURL}/sitemap.xml`);
  expect(antwort.status()).toBe(200);
  const xml = await antwort.text();
  for (const stadt of staedte.filter((s) => !s.indexierbar)) {
    expect(xml, stadt.slug).not.toContain(`/hausverwaltung-${stadt.slug}/`);
  }
});
