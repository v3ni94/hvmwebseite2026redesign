// @ts-check
// Mobiles Menü: Fokus beim Öffnen auf dem ersten Menüpunkt, Fokusfalle (Tab und Umschalt+Tab zyklisch),
// Hintergrund per inert gesperrt, Escape schließt und gibt den Fokus an den Menü-Button zurück.
const { test, expect } = require('@playwright/test');

test.use({ viewport: { width: 390, height: 844 }, contextOptions: { reducedMotion: 'reduce' } });

test('Fokus bleibt im geöffneten Menü, Escape schließt', async ({ page }) => {
  await page.goto('/wissen/');
  const knopf = page.locator('[data-menue]');
  await expect(knopf).toBeVisible();
  await knopf.click();
  await expect(knopf).toHaveAttribute('aria-expanded', 'true');

  // Fokus auf dem ersten Menüpunkt
  const ersterPunkt = page.locator('#hauptnavigation').locator('a[href], button').first();
  await expect(ersterPunkt).toBeFocused();

  // Hintergrund gesperrt
  expect(await page.locator('main').evaluate((el) => el.inert)).toBe(true);
  expect(await page.locator('.c-header__marke').evaluate((el) => el.inert)).toBe(true);

  // Umschalt+Tab vom ersten Menüpunkt springt zum Schließen-Button, Tab von dort zurück zum ersten Punkt
  await page.keyboard.press('Shift+Tab');
  await expect(knopf).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(ersterPunkt).toBeFocused();

  // Viele Tabs verlassen das Menü nie
  for (let i = 0; i < 25; i += 1) {
    await page.keyboard.press('Tab');
    const imMenue = await page.evaluate(() => {
      const aktiv = document.activeElement;
      return !!aktiv && (!!aktiv.closest('#hauptnavigation') || aktiv.matches('[data-menue]'));
    });
    expect(imMenue).toBe(true);
  }

  await page.keyboard.press('Escape');
  // Ein offenes Untermenü schließt zuerst, dann das Menü
  if ((await knopf.getAttribute('aria-expanded')) === 'true') {
    await page.keyboard.press('Escape');
  }
  await expect(knopf).toHaveAttribute('aria-expanded', 'false');
  await expect(knopf).toBeFocused();
  expect(await page.locator('main').evaluate((el) => el.inert)).toBe(false);
  expect(await page.locator('.c-header__marke').evaluate((el) => el.inert)).toBe(false);
});
