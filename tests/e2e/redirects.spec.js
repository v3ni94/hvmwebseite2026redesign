// @ts-check
const { test, expect } = require('@playwright/test');
const { ladeRedirects } = require('./helpers/sitemap');

const redirects = ladeRedirects().filter((r) => r.typ === 'exakt');

for (const redirect of redirects) {
  test(`Weiterleitung ${redirect.von} -> ${redirect.nach ?? '(410)'}`, async ({ request, baseURL }) => {
    const antwort = await request.get(baseURL + redirect.von, { maxRedirects: 0 });
    expect(antwort.status(), redirect.von).toBe(redirect.status);

    if (redirect.status !== 410 && redirect.nach) {
      const ziel = antwort.headers()['location'];
      expect(ziel, redirect.von).toBe(redirect.nach);
    }
  });
}
