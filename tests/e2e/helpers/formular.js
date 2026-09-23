// @ts-check
// Gemeinsame Hilfen für Formular-Tests (Angebot, Kontakt). Alle Testdaten sind fiktiv (example.org).

/** Mindestalter des signierten Zeitstempels in Sekunden (Hvm\Security\SpamGuard::MIN_SECONDS) plus Puffer. */
const ZEITFALLE_MS = 4_600;

/**
 * Eindeutige, fiktive Client-IP je Test. Der Testserver vertraut 127.0.0.1 als Proxy
 * (TRUSTED_PROXIES in playwright.config.js), dadurch zählt das Rate Limit je Test getrennt.
 */
function testIp() {
  const z = () => Math.floor(Math.random() * 254) + 1;
  return `10.${z()}.${z()}.${z()}`;
}

/** Eindeutige fiktive E-Mail-Adresse unter example.org. */
function testEmail(prefix) {
  return `${prefix}.${Date.now().toString(36)}${Math.floor(Math.random() * 1e6).toString(36)}@example.org`;
}

/**
 * Wartet, bis seit dem Laden des Formulars die Mindestzeit der Zeitfalle vergangen ist.
 * Ohne diese Wartezeit würde der Server die Anfrage stillschweigend als Spam behandeln.
 * @param {import('@playwright/test').Page} page
 * @param {number} geladen Zeitpunkt (Date.now()) nach dem Laden des Formulars
 */
async function warteZeitfalle(page, geladen) {
  const rest = ZEITFALLE_MS - (Date.now() - geladen);
  if (rest > 0) {
    await page.waitForTimeout(rest);
  }
}

/**
 * Klickt nach Scrollen in die Bildschirmmitte. Playwright scrollt sonst nur bis an den unteren Rand,
 * wo mobil die feste Schnellzugriffsleiste und außerhalb der Produktion das Entwurfsband liegen.
 * @param {import('@playwright/test').Locator} locator
 */
async function klicke(locator) {
  await locator.evaluate((element) => element.scrollIntoView({ block: 'center' }));
  await locator.click();
}

module.exports = { testIp, testEmail, warteZeitfalle, klicke };
