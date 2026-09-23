// @ts-check
// Security-Header laut docs/architektur.md Abschnitt 4. Ergänzt bin/check-headers.php (CLI gegen
// alle Sitemap-Adressen) um einen Playwright-Test für die Startseite und den Admin-Bereich.
const { test, expect } = require('@playwright/test');

test('Security-Header auf der Startseite', async ({ request, baseURL }) => {
  const antwort = await request.get(baseURL + '/');
  const header = antwort.headers();

  expect(header['content-security-policy'], 'CSP fehlt').toBeTruthy();
  expect(header['content-security-policy']).not.toContain('unsafe-inline');
  expect(header['content-security-policy']).toMatch(/script-src[^;]*'nonce-[^']+'/);
  expect(header['content-security-policy']).toContain("frame-ancestors 'none'");
  expect(header['x-content-type-options']).toBe('nosniff');
  expect(header['referrer-policy']).toBeTruthy();
  expect(header['permissions-policy']).toBeTruthy();
  expect(header['x-frame-options']).toBe('DENY');
  expect(header['cross-origin-opener-policy']).toBe('same-origin');
});

test('Admin-Bereich trägt X-Robots-Tag noindex', async ({ request, baseURL }) => {
  const antwort = await request.get(baseURL + '/admin/');
  expect(antwort.headers()['x-robots-tag']).toContain('noindex');
});

test('Zwei Aufrufe derselben Seite tragen unterschiedliche CSP-Nonces', async ({ request, baseURL }) => {
  const nonceAus = (csp) => (csp.match(/'nonce-([^']+)'/) || [])[1];
  const a = nonceAus((await request.get(baseURL + '/')).headers()['content-security-policy']);
  const b = nonceAus((await request.get(baseURL + '/')).headers()['content-security-policy']);
  expect(a).toBeTruthy();
  expect(a).not.toBe(b);
});
