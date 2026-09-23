/*
 * Kopfzeile: kompakter Zustand beim Scrollen, Untermenüs als Disclosure (aria-expanded),
 * mobile Navigation mit aria-expanded. Escape schließt und gibt den Fokus an den Auslöser zurück.
 */
const DESKTOP = '(min-width: 72em)';

export function initKopfzeile() {
    const header = document.querySelector('[data-kopfzeile]');
    if (!header) {
        return;
    }
    const root = document.documentElement;
    const desktop = window.matchMedia(DESKTOP);

    // Kompakter Zustand
    let kompakt = null;
    let geplant = false;
    const aktualisieren = () => {
        geplant = false;
        const soll = window.scrollY > 48 || root.classList.contains('has-nav-offen');
        if (soll !== kompakt) {
            kompakt = soll;
            header.classList.toggle('is-kompakt', soll);
        }
    };
    window.addEventListener('scroll', () => {
        if (!geplant) {
            geplant = true;
            window.requestAnimationFrame(aktualisieren);
        }
    }, { passive: true });
    aktualisieren();

    // Untermenüs
    const toggles = Array.from(header.querySelectorAll('[data-nav-toggle]'));
    const schliesseUntermenues = (ausser = null) => {
        toggles.forEach((t) => {
            if (t !== ausser) {
                t.setAttribute('aria-expanded', 'false');
            }
        });
    };
    toggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const offen = toggle.getAttribute('aria-expanded') === 'true';
            schliesseUntermenues(toggle);
            toggle.setAttribute('aria-expanded', String(!offen));
        });
        const eintrag = toggle.closest('.c-nav__item');
        eintrag?.addEventListener('focusout', (event) => {
            if (desktop.matches && !eintrag.contains(event.relatedTarget)) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    });
    document.addEventListener('click', (event) => {
        if (!header.contains(event.target)) {
            schliesseUntermenues();
        }
    });

    // Mobile Navigation
    const menueKnopf = header.querySelector('[data-menue]');
    const nav = header.querySelector('[data-nav]');
    const label = menueKnopf?.querySelector('[data-menue-label]');
    const menueOffen = () => menueKnopf?.getAttribute('aria-expanded') === 'true';
    const setzeMenue = (offen) => {
        if (!menueKnopf || !nav) {
            return;
        }
        menueKnopf.setAttribute('aria-expanded', String(offen));
        nav.classList.toggle('is-offen', offen);
        root.classList.toggle('has-nav-offen', offen);
        if (label) {
            label.textContent = offen ? 'Schließen' : 'Menü';
        }
        aktualisieren();
    };
    if (menueKnopf && nav) {
        menueKnopf.hidden = false;
        menueKnopf.addEventListener('click', () => setzeMenue(!menueOffen()));
        desktop.addEventListener('change', (event) => {
            if (event.matches) {
                setzeMenue(false);
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        const offenesUntermenue = header.querySelector('[data-nav-toggle][aria-expanded="true"]');
        if (offenesUntermenue) {
            offenesUntermenue.setAttribute('aria-expanded', 'false');
            offenesUntermenue.focus();
            return;
        }
        if (menueOffen()) {
            setzeMenue(false);
            menueKnopf.focus();
        }
    });
}
