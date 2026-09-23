/*
 * Fortschrittsbalken mit Kennlinie. Wert über die CSS-Variable --fortschritt (0% bis 100%).
 * - [data-fortschritt="37"]: exakter Wert (ohne JavaScript greift data-wert in 5er-Schritten)
 * - [data-lesefortschritt="#artikel"]: Lesefortschritt durch das angegebene Element
 * - [data-seitenfortschritt]: Kennlinie am oberen Rand folgt dem Scrollweg der ganzen Seite
 * setzeFortschritt() kann von anderen Modulen (z. B. angebot.js) genutzt werden.
 */
export function setzeFortschritt(element, wert, werttext = null) {
    const begrenzt = Math.max(0, Math.min(100, Number(wert) || 0));
    element.style.setProperty('--fortschritt', `${begrenzt}%`);
    element.setAttribute('aria-valuenow', String(Math.round(begrenzt)));
    if (werttext) {
        element.setAttribute('aria-valuetext', werttext);
    }
}

export function initFortschritt() {
    document.querySelectorAll('[data-fortschritt]').forEach((element) => {
        setzeFortschritt(element, element.dataset.fortschritt);
    });

    const seite = document.querySelector('[data-seitenfortschritt]');
    if (seite) {
        let geplantSeite = false;
        const messeSeite = () => {
            geplantSeite = false;
            const strecke = document.documentElement.scrollHeight - window.innerHeight;
            const anteil = strecke > 0 ? (window.scrollY / strecke) * 100 : 100;
            seite.style.setProperty('--fortschritt', `${Math.max(0, Math.min(100, anteil))}%`);
        };
        window.addEventListener('scroll', () => {
            if (!geplantSeite) {
                geplantSeite = true;
                window.requestAnimationFrame(messeSeite);
            }
        }, { passive: true });
        window.addEventListener('resize', messeSeite, { passive: true });
        messeSeite();
    }

    const lesen = document.querySelector('[data-lesefortschritt]');
    if (!lesen) {
        return;
    }
    const ziel = document.querySelector(lesen.dataset.lesefortschritt) ?? document.body;
    let geplant = false;
    const messen = () => {
        geplant = false;
        const box = ziel.getBoundingClientRect();
        const strecke = box.height - window.innerHeight;
        const anteil = strecke > 0 ? (-box.top / strecke) * 100 : 100;
        setzeFortschritt(lesen, anteil);
    };
    window.addEventListener('scroll', () => {
        if (!geplant) {
            geplant = true;
            window.requestAnimationFrame(messen);
        }
    }, { passive: true });
    messen();
}
