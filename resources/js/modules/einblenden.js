/*
 * Fallback für scroll-getriebenes Einblenden, wenn animation-timeline: view() fehlt.
 * Nur aktiv ohne prefers-reduced-motion. Ohne JavaScript bleibt alles sichtbar.
 */
export function initEinblenden() {
    const reduziert = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const nativ = window.CSS?.supports?.('animation-timeline: view()') ?? false;
    if (reduziert || nativ || !('IntersectionObserver' in window)) {
        return;
    }
    const elemente = document.querySelectorAll('[data-einblenden]');
    if (elemente.length === 0) {
        return;
    }
    document.documentElement.classList.add('js-einblenden');
    const beobachter = new IntersectionObserver((eintraege) => {
        for (const eintrag of eintraege) {
            if (eintrag.isIntersecting) {
                eintrag.target.classList.add('is-sichtbar');
                beobachter.unobserve(eintrag.target);
            }
        }
    }, { rootMargin: '0px 0px -6% 0px', threshold: 0.1 });
    elemente.forEach((element) => beobachter.observe(element));
}
