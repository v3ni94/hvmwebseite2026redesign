// Playwright-Konfiguration (docs/architektur.md Abschnitt 1 und 11).
// Chromium liegt vorinstalliert unter /opt/pw-browsers, "playwright install" wird nie ausgeführt.
process.env.PLAYWRIGHT_BROWSERS_PATH ||= '/opt/pw-browsers';

const { defineConfig, devices } = require('@playwright/test');

// E2E_PORT erlaubt parallele Läufe (z. B. mehrere Arbeitskopien), Standard 8090
const PORT = Number(process.env.E2E_PORT || 8090);
const BASE_URL = `http://127.0.0.1:${PORT}`;
// Fake-Empfänger für Webhook (HTTP) und Mail (SMTP) im Dateimodus, Ports abgeleitet vom E2E_PORT
const FAKE_PORT = Number(process.env.E2E_FAKE_PORT || PORT + 1);
const SMTP_PORT = Number(process.env.E2E_SMTP_PORT || PORT + 2);
// Fiktives Geheimnis nur für den lokalen Testlauf
const WEBHOOK_SECRET = 'e2e-fiktives-webhook-geheimnis-0123456789abcdef';
process.env.E2E_FAKE_URL = `http://127.0.0.1:${FAKE_PORT}`;

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  expect: { timeout: 5_000 },
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  outputDir: 'tests/e2e/results',
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    locale: 'de-DE',
  },
  projects: [
    {
      name: 'chromium-desktop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
    },
    {
      name: 'chromium-mobile',
      use: { ...devices['Pixel 7'] },
    },
  ],
  webServer: [
    {
      command: 'node tests/e2e/helpers/fake-empfaenger.js',
      url: `http://127.0.0.1:${FAKE_PORT}/_status`,
      reuseExistingServer: !process.env.CI,
      timeout: 10_000,
      env: { E2E_FAKE_PORT: String(FAKE_PORT), E2E_SMTP_PORT: String(SMTP_PORT), E2E_WEBHOOK_SECRET: WEBHOOK_SECRET },
    },
    {
      command: `php -S 127.0.0.1:${PORT} -t public public/index.php`,
      url: BASE_URL + '/',
      reuseExistingServer: !process.env.CI,
      timeout: 20_000,
      env: {
        APP_ENV: 'development',
        APP_URL: BASE_URL,
        SHOW_DRAFTS: 'true',
        // Formular-Tests setzen je Test eine fiktive X-Forwarded-For-Adresse, damit das Rate Limit
        // (5 Anfragen je IP in 10 Minuten) wiederholte Läufe nicht blockiert (tests/e2e/helpers/formular.js).
        TRUSTED_PROXIES: '127.0.0.1/32',
        // Betrieb ohne Datenbank (Standard seit 24.09.2026): Formulare gehen an die Fake-Empfänger
        STORAGE_MODE: 'datei',
        OUTBOX_MODE: 'inline',
        N8N_WEBHOOK_URL: `http://127.0.0.1:${FAKE_PORT}/webhook`,
        N8N_WEBHOOK_SECRET: WEBHOOK_SECRET,
        MAIL_HOST: '127.0.0.1',
        MAIL_PORT: String(SMTP_PORT),
        MAIL_USER: '',
        MAIL_PASSWORD: '',
        MAIL_ENCRYPTION: 'none',
        MAIL_FROM: 'website@example.org',
        LEAD_NOTIFY_TO: 'leads@example.org',
        BEWERBUNG_NOTIFY_TO: 'bewerbung@example.org',
      },
    },
  ],
});
