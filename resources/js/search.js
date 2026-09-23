/*
 * Live-Suche für /wissen/ (progressive enhancement). Ohne JavaScript filtert das Formular
 * server-seitig über den GET-Parameter q (Hvm\Controller\WissenController::index()).
 * Mit JavaScript sucht dieses Modul im vorab erzeugten Index public/assets/search-index.json
 * (bin/build-search-index.php) und ersetzt die serverseitig gerenderten Abschnitte durch eine
 * eigene, barrierefreie Trefferliste (aria-live, reine <a>-Elemente: Tastaturbedienung ergibt
 * sich aus der nativen Fokusreihenfolge).
 *
 * Normalisierung: Kleinschreibung, Umlaute und ß gleichwertig zu ae/oe/ue/ss (gleiche Regel wie
 * Hvm\Controller\WissenController::normalisieren() für die serverseitige Suche).
 */

const INDEX_URL = '/assets/search-index.json';
const MIN_LAENGE = 2;

export function normalisieren(text) {
    return String(text ?? '')
        .toLowerCase()
        .replace(/ä/g, 'ae')
        .replace(/ö/g, 'oe')
        .replace(/ü/g, 'ue')
        .replace(/ß/g, 'ss');
}

function woerter(text) {
    return normalisieren(text).split(/\s+/).filter(Boolean);
}

function treffer(eintrag, begriffe) {
    const heuhaufen = normalisieren(`${eintrag.titel} ${eintrag.beschreibung} ${eintrag.text ?? ''}`);
    return begriffe.every((begriff) => heuhaufen.includes(begriff));
}

async function ladeIndex() {
    const antwort = await fetch(INDEX_URL, { headers: { Accept: 'application/json' } });
    if (!antwort.ok) {
        throw new Error(`Suchindex nicht verfügbar (${antwort.status})`);
    }

    return antwort.json();
}

function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, (zeichen) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[zeichen]));
}

function ergebnisListe(eintraege) {
    if (eintraege.length === 0) {
        return '<p class="c-suche-live__leer">Keine Treffer. Bitte anderen Begriff versuchen.</p>';
    }
    const punkte = eintraege
        .map((e) => `<li class="c-suche-live__eintrag"><a href="${escapeHtml(e.url)}"><span class="c-suche-live__titel">${escapeHtml(e.titel)}</span><span class="c-suche-live__zielgruppe">${escapeHtml(e.zielgruppeLabel ?? '')}</span></a><p>${escapeHtml(e.beschreibung)}</p></li>`)
        .join('');

    return `<ul class="c-suche-live__liste">${punkte}</ul>`;
}

export function initSuche() {
    const feld = document.querySelector('#suche-q');
    const live = document.querySelector('[data-wissen-live]');
    const abschnitte = document.querySelectorAll('[data-wissen-abschnitt]');
    if (!feld || !live || abschnitte.length === 0) {
        return;
    }

    let index = null;
    let ladefehler = false;
    let zeitgeber = null;

    const zeigeAbschnitte = (sichtbar) => {
        abschnitte.forEach((abschnitt) => {
            abschnitt.hidden = !sichtbar;
        });
        live.hidden = sichtbar;
    };

    const suchen = async (wert) => {
        const begriffe = woerter(wert);
        if (begriffe.length === 0) {
            zeigeAbschnitte(true);
            live.innerHTML = '';

            return;
        }
        if (index === null && !ladefehler) {
            try {
                index = await ladeIndex();
            } catch {
                ladefehler = true;
                index = [];
            }
        }
        zeigeAbschnitte(false);
        const gefunden = (index ?? []).filter((eintrag) => treffer(eintrag, begriffe));
        live.innerHTML = ladefehler
            ? '<p class="c-suche-live__leer">Suche derzeit nicht verfügbar. Bitte die Seite neu laden.</p>'
            : `<p class="u-visually-hidden">${gefunden.length} Treffer für „${escapeHtml(wert)}“</p>${ergebnisListe(gefunden)}`;
    };

    feld.addEventListener('input', () => {
        window.clearTimeout(zeitgeber);
        const wert = feld.value.trim();
        zeitgeber = window.setTimeout(() => {
            if (wert.length >= MIN_LAENGE || wert.length === 0) {
                suchen(wert);
            }
        }, 150);
    });

    feld.addEventListener('keydown', (ereignis) => {
        if (ereignis.key === 'Escape' && feld.value !== '') {
            feld.value = '';
            zeigeAbschnitte(true);
            live.innerHTML = '';
        }
    });
}
