// @ts-check
// Gemeinsame Hilfsfunktionen für die Playwright-Tests: Sitemap und Redirects einlesen.

const { execFileSync } = require('node:child_process');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..', '..', '..');

/**
 * Liest config/redirects.php über PHP aus (kein zweiter Parser in JS) und gibt sie als Array zurück.
 * @returns {Array<{von: string, nach: string|null, status: number, typ: string, verifiziert: boolean}>}
 */
function ladeRedirects() {
  const php = `
    $redirects = require '${ROOT}/config/redirects.php';
    echo json_encode(array_values($redirects));
  `;
  const ausgabe = execFileSync('php', ['-r', php], { encoding: 'utf-8' });
  return JSON.parse(ausgabe);
}

/**
 * Liest die statischen Seiten aus config/seiten.php, die in der Sitemap erscheinen sollen
 * (sitemap === true), inklusive og-Flag. Praktisch für Tests, die nicht erst den Server abfragen
 * müssen, um die Seitenliste zu kennen.
 * @returns {Array<{slug: string, pfad: string, titel: string, og: boolean}>}
 */
function ladeOeffentlicheSeiten() {
  const php = `
    $seiten = require '${ROOT}/config/seiten.php';
    $ergebnis = [];
    foreach ($seiten as $slug => $meta) {
        if (($meta['sitemap'] ?? false) !== true) {
            continue;
        }
        $ergebnis[] = [
            'slug' => (string) $slug,
            'pfad' => $meta['pfad'],
            'titel' => $meta['titel'] ?? $slug,
            'og' => ($meta['og'] ?? true) !== false,
        ];
    }
    echo json_encode($ergebnis);
  `;
  const ausgabe = execFileSync('php', ['-r', php], { encoding: 'utf-8' });
  return JSON.parse(ausgabe);
}

module.exports = { ladeRedirects, ladeOeffentlicheSeiten };
