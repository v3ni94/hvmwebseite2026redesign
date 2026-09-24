// @ts-check
// Nachweise zum Code-Review (docs/pruefbericht.md): Live-Suche, Sendesperre im Angebotsformular,
// Druckansicht, security.txt und /health. Keine Formulardaten werden gespeichert.
const { test, expect } = require('@playwright/test');

test.use({ contextOptions: { reducedMotion: 'reduce' } });

test.describe('Live-Suche Wissen', () => {
  test('Trefferliste ist sichtbar', async ({ page }) => {
    await page.goto('/wissen/');
    await page.locator('#suche-q').fill('Hausgeld');
    const live = page.locator('[data-wissen-live]');
    const ersterTreffer = live.locator('a').first();
    await expect(ersterTreffer).toBeVisible();
    const box = await live.boundingBox();
    expect(box && box.height).toBeGreaterThan(20);
    await expect(page.locator('[data-wissen-abschnitt]').first()).toBeHidden();
  });

  test('Escape verwirft eine noch geplante Suche', async ({ page }) => {
    await page.goto('/wissen/');
    const feld = page.locator('#suche-q');
    await feld.fill('Beirat');
    await feld.press('Escape');
    await page.waitForTimeout(500);
    await expect(feld).toHaveValue('');
    await expect(page.locator('[data-wissen-live]')).toBeHidden();
    await expect(page.locator('[data-wissen-abschnitt]').first()).toBeVisible();
  });
});

test('Angebot: Rückkehr aus dem Back-Forward-Cache hebt die Sendesperre auf', async ({ page }) => {
  await page.goto('/angebot/');
  const zustand = await page.evaluate(() => {
    const form = /** @type {HTMLFormElement} */ (document.querySelector('[data-angebot]'));
    const knopf = form.querySelector('[data-absenden]');
    form.dataset.gesendet = '1';
    form.setAttribute('aria-busy', 'true');
    knopf?.setAttribute('aria-disabled', 'true');
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    return {
      gesendet: form.dataset.gesendet ?? null,
      busy: form.getAttribute('aria-busy'),
      knopf: knopf ? knopf.getAttribute('aria-disabled') : 'fehlt',
    };
  });
  expect(zustand).toEqual({ gesendet: null, busy: null, knopf: null });
});

test.describe('Druckansicht', () => {
  test('Wissensartikel: ohne Navigation und CTA, Links mit Adresse, Kennlinie oben', async ({ page }) => {
    await page.goto('/wissen/verwalterwechsel-weg/');
    await page.emulateMedia({ media: 'print' });
    for (const selektor of ['.c-header', '.c-mobilbar', '.c-cta-band', '.c-inhaltsverzeichnis', '.c-fortschritt--seite']) {
      await expect(page.locator(selektor).first()).toBeHidden();
    }
    const nachLink = await page.locator('.c-prosa a[href^="/"]').first().evaluate((a) => getComputedStyle(a, '::after').content);
    expect(nachLink).toContain('www.muellerhv.de');
    const kennlinie = await page.evaluate(() => {
      const stil = getComputedStyle(document.body, '::before');
      return { bild: stil.backgroundImage, hoehe: parseFloat(stil.height) };
    });
    expect(kennlinie.bild).toContain('linear-gradient');
    expect(kennlinie.hoehe).toBeGreaterThan(5);
    await expect(page.locator('h1')).toBeVisible();
  });

  test('Rechtsseite: Entwurfsband und Navigation ausgeblendet, FAQ vor dem Druck geöffnet', async ({ page }) => {
    await page.goto('/datenschutz/');
    await page.emulateMedia({ media: 'print' });
    await expect(page.locator('.c-entwurf')).toBeHidden();
    await expect(page.locator('.c-nav')).toBeHidden();
    await expect(page.locator('main h1')).toBeVisible();
    await page.goto('/wissen/verwalterwechsel-weg/');
    const offen = await page.evaluate(() => {
      window.dispatchEvent(new Event('beforeprint'));
      const alle = Array.from(document.querySelectorAll('details'));
      const vorher = alle.every((d) => d.open);
      window.dispatchEvent(new Event('afterprint'));
      return { anzahl: alle.length, vorher, nachher: alle.filter((d) => d.open).length };
    });
    expect(offen.anzahl).toBeGreaterThan(0);
    expect(offen.vorher).toBe(true);
    expect(offen.nachher).toBe(0);
  });
});

test('security.txt und /health', async ({ request }) => {
  const sec = await request.get('/.well-known/security.txt');
  expect(sec.status()).toBe(200);
  const text = await sec.text();
  expect(text).toContain('Contact: mailto:info@muellerhv.de');
  expect(text).toMatch(/^Expires: \d{4}-\d{2}-\d{2}T00:00:00Z$/m);
  expect(text).toContain('Preferred-Languages: de');

  const health = await request.get('/health', { maxRedirects: 0 });
  expect(health.status()).toBe(200);
  // E2E läuft im Dateimodus (playwright.config.js): keine Datenbank der Webseite
  expect(await health.json()).toEqual({ status: 'ok', datenbank: 'nicht_verwendet' });
});
