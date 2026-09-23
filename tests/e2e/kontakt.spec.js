// @ts-check
// Kontaktformular im Browser, mit und ohne JavaScript. Fiktive Testdaten (example.org).
// Die Danke-Seite nennt das Anliegen nur nach echter Speicherung, nicht bei Spamverdacht.
const { test, expect } = require('@playwright/test');
const { testIp, testEmail, warteZeitfalle, klicke } = require('./helpers/formular');

async function fuelleAus(page, email) {
  await page.locator('#feld-anliegen').selectOption('allgemein');
  await page.locator('#feld-name').fill('Max Beispiel');
  await page.locator('#feld-email').fill(email);
  await page.locator('#feld-nachricht').fill('Fiktive Testnachricht aus dem automatisierten Browsertest.');
  await page.locator('#feld-datenschutz').check();
}

for (const js of [true, false]) {
  test.describe(`Kontaktformular ${js ? 'mit' : 'ohne'} JavaScript`, () => {
    test.use({ javaScriptEnabled: js, extraHTTPHeaders: { 'X-Forwarded-For': testIp() }, contextOptions: { reducedMotion: 'reduce' } });

    test('Fehlerfall und Absenden', async ({ page }) => {
      await page.goto('/kontakt/');
      const formular = page.locator('form.c-kontakt__formular');
      await expect(formular).toBeVisible();
      const geladen = Date.now();

      // Leeres Formular nach Ablauf der Mindestzeit (sonst Spam-Pfad): serverseitige Fehlerliste
      await warteZeitfalle(page, geladen);
      await klicke(formular.locator('button[type="submit"]'));
      await expect(page).toHaveURL(/\/kontakt\/$/);
      await expect(page.locator('#kontakt-fehlerliste')).toBeVisible();

      await fuelleAus(page, testEmail(js ? 'e2e-kontakt-js' : 'e2e-kontakt-nojs'));
      await warteZeitfalle(page, geladen);
      await klicke(formular.locator('button[type="submit"]'));

      await expect(page).toHaveURL(/\/kontakt\/danke\/$/);
      await expect(page.locator('h1')).toHaveText('Vielen Dank für Ihre Nachricht');
      await expect(page.locator('main')).toContainText('Ihre Nachricht zum Anliegen „Allgemeine Anfrage“ ist bei uns eingegangen.');
    });
  });
}
