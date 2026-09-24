// @ts-check
// Angebotsstrecke im Browser (docs/offene-punkte.md C6): einmal schrittweise mit JavaScript,
// einmal als einseitiges Formular ohne JavaScript. Fiktive Anfragen (example.org) gehen im Dateimodus
// (playwright.config.js) an die lokalen Fake-Empfänger; die Danke-Seite nennt die Verwaltungsart nur nach echter Speicherung
// (bei Spamverdacht oder Fehler fehlt sie), das dient als Nachweis des erfolgreichen Absendens.
const { test, expect } = require('@playwright/test');
const { testIp, testEmail, warteZeitfalle, klicke } = require('./helpers/formular');

// Ohne Bewegung: html { scroll-behavior: smooth } lässt Elemente beim automatischen Scrollen
// sonst als "nicht stabil" erscheinen (80-bewegung.css schaltet das bei reduzierter Bewegung ab).
test.use({ contextOptions: { reducedMotion: 'reduce' } });

test.describe('Angebotsformular mit JavaScript', () => {
  test.use({ extraHTTPHeaders: { 'X-Forwarded-For': testIp() } });

  test('Schritte, Fortschritt, Prüfung, Absenden und Danke-Seite', async ({ page }) => {
    const cspVerstoesse = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error' && /content security policy|refused to/i.test(msg.text())) {
        cspVerstoesse.push(msg.text());
      }
    });

    await page.goto('/angebot/');
    const geladen = Date.now();

    const stepper = page.locator('[data-stepper]');
    const status = stepper.locator('.c-stepper__status');
    const balken = stepper.locator('[role="progressbar"]');
    const schritt = (n) => page.locator(`[data-schritt="${n}"]`);
    const weiter = (n) => schritt(n).locator('[data-weiter]');

    // Mit JavaScript: Stepper sichtbar, nur Schritt 1 sichtbar
    await expect(stepper).toBeVisible();
    await expect(status).toHaveText('Schritt 1 von 5: Verwaltungsart');
    await expect(balken).toHaveAttribute('aria-valuenow', '0');
    await expect(schritt(1)).toBeVisible();
    for (const n of [2, 3, 4, 5]) {
      await expect(schritt(n)).toBeHidden();
    }

    // Weiter ohne Pflichtangabe: Fehlermeldung, Schritt bleibt
    await weiter(1).click();
    await expect(schritt(1).locator('[data-schritt-fehler]')).toBeVisible();
    await expect(status).toHaveText('Schritt 1 von 5: Verwaltungsart');

    // Schritt 1: Verwaltungsart
    await page.locator('label[for="feld-art-weg"]').click();
    await weiter(1).click();
    await expect(status).toHaveText('Schritt 2 von 5: Objekt');
    await expect(balken).toHaveAttribute('aria-valuenow', '25');
    await expect(schritt(1)).toBeHidden();

    // Schritt 2: ungültige PLZ wird clientseitig abgefangen
    await page.locator('#feld-plz').fill('123');
    await page.locator('#feld-ort').fill('Musterstadt');
    await page.locator('#feld-wohneinheiten').fill('12');
    await weiter(2).click();
    await expect(page.locator('#feld-plz')).toHaveAttribute('aria-invalid', 'true');
    await expect(status).toHaveText('Schritt 2 von 5: Objekt');
    await page.locator('#feld-plz').fill('40210');
    await weiter(2).click();

    // Schritt 3: Zeitpunkt (optional)
    await expect(status).toHaveText('Schritt 3 von 5: Zeitpunkt');
    await expect(balken).toHaveAttribute('aria-valuenow', '50');
    await page.locator('label[for="feld-aktueller_verwalter-nein"]').click();
    await weiter(3).click();

    // Schritt 4: Kontakt, Zurück und wieder Weiter
    await expect(status).toHaveText('Schritt 4 von 5: Kontakt');
    await schritt(4).locator('[data-zurueck]').click();
    await expect(status).toHaveText('Schritt 3 von 5: Zeitpunkt');
    await weiter(3).click();
    await page.locator('#feld-nachname').fill('Beispiel');
    await page.locator('#feld-email').fill(testEmail('e2e-angebot-js'));
    await weiter(4).click();

    // Schritt 5: Zusammenfassung, Datenschutz, Absenden
    await expect(status).toHaveText('Schritt 5 von 5: Absenden');
    await expect(balken).toHaveAttribute('aria-valuenow', '100');
    const zusammenfassung = page.locator('[data-zusammenfassung]');
    await expect(zusammenfassung).toBeVisible();
    await expect(zusammenfassung).toContainText('WEG-Verwaltung');
    await expect(zusammenfassung).toContainText('40210');

    await klicke(page.locator('[data-absenden]'));
    await expect(schritt(5).locator('[data-schritt-fehler]')).toBeVisible();
    await expect(page).toHaveURL(/\/angebot\/$/);

    await page.locator('label[for="feld-datenschutz"]').click();
    await warteZeitfalle(page, geladen);
    await klicke(page.locator('[data-absenden]'));

    await expect(page).toHaveURL(/\/angebot\/danke\/$/);
    await expect(page.locator('h1')).toHaveText('Vielen Dank für Ihre Anfrage');
    await expect(page.locator('main')).toContainText('Ihre Anfrage zur WEG-Verwaltung ist bei uns eingegangen.');
    expect(cspVerstoesse, cspVerstoesse.join('\n')).toEqual([]);
  });
});

test.describe('Angebotsformular ohne JavaScript', () => {
  test.use({ javaScriptEnabled: false, extraHTTPHeaders: { 'X-Forwarded-For': testIp() } });

  test('einseitiges Formular, Fehlerfall mit erhaltenen Eingaben, Absenden', async ({ page }) => {
    await page.goto('/angebot/');
    const geladen = Date.now();

    // Alle Schritte gleichzeitig sichtbar, keine JavaScript-Steuerung
    await expect(page.locator('[data-stepper]')).toBeHidden();
    for (const n of [1, 2, 3, 4, 5]) {
      await expect(page.locator(`[data-schritt="${n}"]`)).toBeVisible();
    }
    await expect(page.locator('[data-weiter]').first()).toBeHidden();

    // Fehlerfall: Pflichtangaben fehlen, Eingaben bleiben erhalten
    await page.locator('#feld-ort').fill('Musterstadt');
    await page.locator('#feld-nachricht').fill('Fiktive Testanfrage ohne JavaScript.');
    // Auch der Fehlerfall braucht die Mindestzeit, sonst verwirft der Server die Eingabe als Spam
    await warteZeitfalle(page, geladen);
    await klicke(page.locator('[data-absenden]'));

    await expect(page).toHaveURL(/\/angebot\/$/);
    const fehlerliste = page.locator('[data-fehlerliste]');
    await expect(fehlerliste).toBeVisible();
    await expect(fehlerliste).toContainText('Bitte wählen Sie die Verwaltungsart.');
    await expect(fehlerliste).toContainText('Bitte geben Sie die Postleitzahl des Objekts an.');
    await expect(fehlerliste).toContainText('Datenschutzhinweis');
    await expect(page.locator('#feld-ort')).toHaveValue('Musterstadt');
    await expect(page.locator('#feld-nachricht')).toHaveValue('Fiktive Testanfrage ohne JavaScript.');
    await expect(page.locator('#feld-plz')).toHaveAttribute('aria-invalid', 'true');

    // Sprunglink aus der Fehlerliste führt zum Feld
    await expect(fehlerliste.locator('a[href="#feld-plz"]')).toHaveCount(1);

    // Korrigieren und absenden (der Server gibt nach dem Fehler denselben, bereits gealterten Zeitstempel aus)
    await page.locator('#feld-art-miet').check();
    await page.locator('#feld-plz').fill('40210');
    await page.locator('#feld-gewerbeeinheiten').fill('2');
    await page.locator('#feld-vorname').fill('Erika');
    await page.locator('#feld-email').fill(testEmail('e2e-angebot-nojs'));
    await page.locator('#feld-datenschutz').check();
    await klicke(page.locator('[data-absenden]'));

    await expect(page).toHaveURL(/\/angebot\/danke\/$/);
    await expect(page.locator('h1')).toHaveText('Vielen Dank für Ihre Anfrage');
    await expect(page.locator('main')).toContainText('Ihre Anfrage zur Mietverwaltung ist bei uns eingegangen.');
  });
});
