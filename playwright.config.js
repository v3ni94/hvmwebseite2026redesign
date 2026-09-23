// Playwright-Konfiguration (docs/architektur.md Abschnitt 1 und 11).
// Chromium liegt vorinstalliert unter /opt/pw-browsers, "playwright install" wird nie ausgeführt.
process.env.PLAYWRIGHT_BROWSERS_PATH ||= '/opt/pw-browsers';

const { defineConfig, devices } = require('@playwright/test');

const PORT = 8090;
const BASE_URL = `http://127.0.0.1:${PORT}`;

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
  webServer: {
    command: `php -S 127.0.0.1:${PORT} -t public public/index.php`,
    url: BASE_URL + '/',
    reuseExistingServer: !process.env.CI,
    timeout: 20_000,
    env: {
      APP_ENV: 'development',
      APP_URL: BASE_URL,
      SHOW_DRAFTS: 'true',
    },
  },
});
