// @ts-check
// Formulare im Dateimodus (STORAGE_MODE=datei, keine Datenbank): Webhook kommt signiert beim lokalen
// Fake-Empfänger an, interne Mail und Eingangsbestätigung beim SMTP-Fake, storage/outbox ist danach leer.
// Fake-Empfänger: tests/e2e/helpers/fake-empfaenger.js (gestartet über playwright.config.js). Testdaten fiktiv.
const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');
const { testIp, testEmail, warteZeitfalle, klicke } = require('./helpers/formular');

const OUTBOX = path.resolve(__dirname, '..', '..', 'storage', 'outbox');

async function empfangen(request) {
  const antwort = await request.get(`${process.env.E2E_FAKE_URL}/_empfangen`);
  expect(antwort.ok()).toBeTruthy();
  return antwort.json();
}

function offeneAuftraege() {
  return fs.existsSync(OUTBOX) ? fs.readdirSync(OUTBOX).filter((d) => d.endsWith('.job')) : [];
}

/** Webhook und Mails zu einer E-Mail-Adresse, wartet bis alles angekommen ist. */
async function warteAufZustellung(request, email, typ, mailAnzahl = 2) {
  let daten = { webhooks: [], mails: [] };
  await expect
    .poll(async () => {
      const alle = await empfangen(request);
      daten = {
        webhooks: alle.webhooks.filter((w) => w.body.includes(email)),
        mails: alle.mails.filter((m) => (m.text || m.raw).includes(email) || m.to.includes(email)),
      };
      return daten.webhooks.length + daten.mails.length;
    }, { timeout: 15_000 })
    .toBeGreaterThanOrEqual(1 + mailAnzahl);
  const hook = daten.webhooks[0];
  expect(hook.signaturOk).toBe(true);
  expect(hook.headers['x-hvm-event']).toBe(`${typ}.eingegangen`);
  const body = JSON.parse(hook.body);
  expect(body.typ).toBe(typ);
  expect(body.uuid).toMatch(/^[0-9a-f-]{36}$/);
  return { body, mails: daten.mails };
}

test.use({ contextOptions: { reducedMotion: 'reduce' } });

test.describe.serial('Dateimodus ohne Datenbank', () => {
  test.use({ extraHTTPHeaders: { 'X-Forwarded-For': testIp() }, javaScriptEnabled: false });

  test('Health meldet keine Datenbank, Admin liefert 404', async ({ request }) => {
    const health = await request.get('/health');
    expect(await health.json()).toEqual({ status: 'ok', datenbank: 'nicht_verwendet' });
    expect((await request.get('/admin/login/')).status()).toBe(404);
  });

  test('Angebotsformular: signierter Webhook, zwei Mails', async ({ page, request }) => {
    const email = testEmail('e2e-datei-angebot');
    await page.goto('/angebot/?art=weg');
    const geladen = Date.now();
    await page.locator('#feld-plz').fill('40210');
    await page.locator('#feld-ort').fill('Musterstadt');
    await page.locator('#feld-wohneinheiten').fill('8');
    await page.locator('#feld-nachname').fill('Beispiel');
    await page.locator('#feld-email').fill(email);
    await page.locator('#feld-datenschutz').check();
    await warteZeitfalle(page, geladen);
    await klicke(page.locator('form button[type="submit"]').last());
    await expect(page).toHaveURL(/\/angebot\/danke\/$/);
    await expect(page.locator('main')).toContainText('WEG-Verwaltung');

    const { body, mails } = await warteAufZustellung(request, email, 'angebot');
    expect(body.data.units.residential).toBe(8);
    expect(body.data.object.zip).toBe('40210');
    expect(body.consent_text_version).toBeTruthy();
    const intern = mails.find((m) => m.to.includes('leads@example.org'));
    expect(intern).toBeTruthy();
    expect(intern.raw).toMatch(/Subject: .*Neue Verwaltungsanfrage/);
    expect(mails.find((m) => m.to.includes(email))).toBeTruthy();
  });

  test('Kontaktformular: signierter Webhook, zwei Mails', async ({ page, request }) => {
    const email = testEmail('e2e-datei-kontakt');
    await page.goto('/kontakt/');
    const geladen = Date.now();
    await page.locator('#feld-anliegen').selectOption('allgemein');
    await page.locator('#feld-name').fill('Max Beispiel');
    await page.locator('#feld-email').fill(email);
    await page.locator('#feld-nachricht').fill('Fiktive Testnachricht im Dateimodus.');
    await page.locator('#feld-datenschutz').check();
    await warteZeitfalle(page, geladen);
    await klicke(page.locator('form.c-kontakt__formular button[type="submit"]'));
    await expect(page).toHaveURL(/\/kontakt\/danke\/$/);
    const { body } = await warteAufZustellung(request, email, 'kontakt');
    expect(body.data.subject).toBe('allgemein');
  });

  test('Bewerbung: PDF als Anhang der internen Mail, Webhook nur Metadaten', async ({ page, request }) => {
    const email = testEmail('e2e-datei-bewerbung');
    await page.goto('/karriere/bewerbung/');
    const geladen = Date.now();
    await page.locator('#feld-name').fill('Max Mustermann');
    await page.locator('#feld-email').fill(email);
    await page.locator('#feld-datei').setInputFiles({ name: 'lebenslauf.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\nFiktiver Lebenslauf.\n') });
    await page.locator('#feld-einwilligung').check();
    await warteZeitfalle(page, geladen);
    await klicke(page.locator('button[type="submit"]', { hasText: 'Bewerbung senden' }));
    await expect(page).toHaveURL(/\/karriere\/bewerbung\/danke\/$/);
    const { body, mails } = await warteAufZustellung(request, email, 'bewerbung');
    expect(body.data.file.mime).toBe('application/pdf');
    expect(JSON.stringify(body)).not.toContain('PDF-1.4');
    const intern = mails.find((m) => m.to.includes('bewerbung@example.org'));
    expect(intern).toBeTruthy();
    expect(intern.raw).toContain('application/pdf');
    expect(intern.raw).toMatch(/filename=.*\.pdf/);
  });

  test('storage/outbox ist nach dem Versand leer', async () => {
    await expect.poll(() => offeneAuftraege().length, { timeout: 15_000 }).toBe(0);
  });
});
