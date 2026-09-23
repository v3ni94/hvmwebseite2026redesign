/*
 * Zähleranimation für Kennzahlen: einmalig, sobald sichtbar, nicht bei prefers-reduced-motion.
 * Der Endwert steht bereits im HTML. Während der Animation liest ein Screenreader den Endwert
 * aus einem unsichtbaren Text, die laufende Zahl ist aria-hidden. Die Breite wird vorher fixiert (kein Springen).
 */
export function initZaehler() {
    const ziele = document.querySelectorAll('[data-zaehler]');
    if (ziele.length === 0 || !('IntersectionObserver' in window)) {
        return;
    }
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }
    const format = new Intl.NumberFormat('de-DE');
    const beobachter = new IntersectionObserver((eintraege) => {
        for (const eintrag of eintraege) {
            if (eintrag.isIntersecting) {
                beobachter.unobserve(eintrag.target);
                zaehle(eintrag.target, format);
            }
        }
    }, { threshold: 0.6 });
    ziele.forEach((ziel) => beobachter.observe(ziel));
}

function zaehle(element, format) {
    const endwert = Number(element.dataset.zaehler);
    if (!Number.isFinite(endwert) || endwert <= 0) {
        return;
    }
    const breite = element.getBoundingClientRect().width;
    element.style.setProperty('display', 'inline-block');
    element.style.setProperty('min-width', `${Math.ceil(breite)}px`);

    const vorlesen = document.createElement('span');
    vorlesen.className = 'u-visually-hidden';
    vorlesen.textContent = format.format(endwert);
    element.after(vorlesen);
    element.setAttribute('aria-hidden', 'true');

    const dauer = 1400;
    const beginn = performance.now();
    const schritt = (jetzt) => {
        const anteil = Math.min(1, (jetzt - beginn) / dauer);
        const verlauf = 1 - Math.pow(1 - anteil, 3);
        element.textContent = format.format(Math.round(endwert * verlauf));
        if (anteil < 1) {
            window.requestAnimationFrame(schritt);
        }
    };
    element.textContent = format.format(0);
    window.requestAnimationFrame(schritt);
}
