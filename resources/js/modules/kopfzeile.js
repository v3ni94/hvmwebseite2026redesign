/*
 * Kopfzeile: kompakter Zustand beim Scrollen, Untermenüs als Disclosure (aria-expanded),
 * mobile Navigation mit aria-expanded. Escape schließt und gibt den Fokus an den Auslöser zurück.
 * Geöffnetes mobiles Menü: Fokus auf dem ersten Menüpunkt, Tab und Umschalt+Tab bleiben zyklisch im Menü
 * (Menüpunkte und Schließen-Button), der übrige Seiteninhalt ist per inert gesperrt.
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
    let gesperrt = [];
    const sichtbar = (element) => element.getClientRects().length > 0 && getComputedStyle(element).visibility !== 'hidden';
    const fokussierbar = () => {
        const imMenue = nav
            ? Array.from(nav.querySelectorAll('a[href], button:not([disabled])')).filter(sichtbar)
            : [];
        return menueKnopf ? [...imMenue, menueKnopf] : imMenue;
    };
    const sperreHintergrund = (sperren) => {
        gesperrt.forEach((element) => { element.inert = false; });
        gesperrt = [];
        if (!sperren) {
            return;
        }
        // Alles außerhalb der Kopfzeile sowie Kopfzeilen-Elemente außerhalb von Menü und Button
        const ausnahmen = new Set([header, nav, menueKnopf]);
        const sperrbar = [
            ...Array.from(document.body.children).filter((element) => !element.contains(header)),
            ...Array.from(header.querySelectorAll('.c-header__pill > *')).filter((element) => !ausnahmen.has(element)),
        ];
        sperrbar.forEach((element) => {
            if (!element.inert) {
                element.inert = true;
                gesperrt.push(element);
            }
        });
    };
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
        sperreHintergrund(offen);
        aktualisieren();
        if (offen) {
            const erster = fokussierbar()[0];
            if (erster && erster !== menueKnopf) {
                erster.focus();
            }
        }
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

    // Fokusfalle im geöffneten mobilen Menü
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab' || !menueOffen()) {
            return;
        }
        const ziele = fokussierbar();
        if (ziele.length === 0) {
            return;
        }
        const erstes = ziele[0];
        const letztes = ziele[ziele.length - 1];
        const aktiv = document.activeElement;
        if (!ziele.includes(aktiv)) {
            event.preventDefault();
            (event.shiftKey ? letztes : erstes).focus();
        } else if (event.shiftKey && aktiv === erstes) {
            event.preventDefault();
            letztes.focus();
        } else if (!event.shiftKey && aktiv === letztes) {
            event.preventDefault();
            erstes.focus();
        }
    });

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
