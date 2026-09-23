/*
 * Dezente Parallaxe für [data-parallaxe] (Hausumriss im Hero): Versatz von 8 % des Scrollwegs, höchstens
 * 120 px, als CSS-Variable --parallaxe (element.style.setProperty, CSP-konform). Nur ohne reduzierte Bewegung.
 */
export function initParallaxe() {
    const elemente = Array.from(document.querySelectorAll('[data-parallaxe]'));
    if (elemente.length === 0 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }
    let geplant = false;
    const setze = () => {
        geplant = false;
        const versatz = Math.min(120, window.scrollY * 0.08);
        elemente.forEach((element) => element.style.setProperty('--parallaxe', `${versatz.toFixed(1)}px`));
    };
    window.addEventListener('scroll', () => {
        if (!geplant) {
            geplant = true;
            window.requestAnimationFrame(setze);
        }
    }, { passive: true });
    setze();
}
