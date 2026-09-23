/*
 * Ergänzung zum Lesefortschritt der Wissensartikel. Der Fortschrittsbalken selbst
 * (c-fortschritt--lesen, data-lesefortschritt) wird bereits generisch von
 * resources/js/modules/fortschritt.js über setzeFortschritt() bedient.
 * Dieses Modul markiert zusätzlich den aktuell gelesenen Abschnitt im Inhaltsverzeichnis
 * (aria-current), rein progressive Verbesserung, ohne JavaScript bleibt das
 * Inhaltsverzeichnis eine normale Liste von Sprungmarken.
 */
export function initLesefortschritt() {
    const verzeichnis = document.querySelector('[data-inhaltsverzeichnis]');
    if (!verzeichnis) {
        return;
    }
    const links = Array.from(verzeichnis.querySelectorAll('a[href^="#"]'));
    const abschnitte = links
        .map((link) => document.getElementById(link.hash.slice(1)))
        .filter((el) => el !== null);
    if (abschnitte.length === 0 || typeof IntersectionObserver === 'undefined') {
        return;
    }

    const setzeAktiv = (id) => {
        links.forEach((link) => {
            if (link.hash === `#${id}`) {
                link.setAttribute('aria-current', 'location');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    const beobachter = new IntersectionObserver(
        (eintraege) => {
            const sichtbar = eintraege
                .filter((e) => e.isIntersecting)
                .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
            if (sichtbar) {
                setzeAktiv(sichtbar.target.id);
            }
        },
        { rootMargin: '-15% 0px -70% 0px' }
    );
    abschnitte.forEach((abschnitt) => beobachter.observe(abschnitt));
}
