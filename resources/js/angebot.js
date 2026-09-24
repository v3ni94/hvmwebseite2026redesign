/*
 * Angebotsformular (MP 6.1): schrittweise Führung als progressive Erweiterung.
 *
 * Ohne JavaScript ist das Formular ein einseitiges Formular mit serverseitiger Prüfung.
 * Mit JavaScript:
 * - fünf Schritte mit Kennlinie als Fortschrittsanzeige (Stepper aus dem Designsystem),
 * - Prüfung je Schritt über die Constraint Validation API plus Querregeln, Fehler am Feld
 *   (aria-invalid, aria-describedby) und als Zusammenfassung mit Sprunglinks,
 * - Fokus beim Schrittwechsel auf der Überschrift des Schritts, Zurück-Funktion,
 *   Browser-Zurück über history.pushState (im Zustand steht nur die Schrittnummer),
 * - Zusammenfassung vor dem Absenden, optional unverbindliche Preisindikation.
 *
 * Zwischenstand nur im Speicher der Seite: kein localStorage, kein sessionStorage, keine Cookies.
 * CSP: keine Inline-Styles und kein innerHTML mit Nutzereingaben, Texte nur über textContent.
 */
import { setzeFortschritt } from './modules/fortschritt.js';

const formular = document.querySelector('[data-angebot]');
if (formular) {
    initAngebot(formular);
}

function initAngebot(form) {
    const schritte = Array.from(form.querySelectorAll('[data-schritt]'));
    const anzahl = schritte.length;
    if (anzahl < 2) {
        return;
    }
    const stepperBox = document.querySelector('[data-stepper]');
    const stepper = stepperBox ? stepperBox.querySelector('.c-stepper') : null;
    const vorlage = document.getElementById('angebot-fehler-vorlage');
    const reduziert = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const monatUnterstuetzt = pruefeMonatsfeld();
    let aktuell = 1;

    form.classList.add('is-schrittweise');
    if (stepperBox) {
        stepperBox.hidden = false;
    }
    form.querySelectorAll('[data-navigation], [data-zurueck]').forEach((element) => {
        element.hidden = false;
    });

    // Server hat Fehler gemeldet: beim ersten fehlerhaften Schritt beginnen.
    const serverListe = document.querySelector('[data-fehlerliste]');
    if (form.hasAttribute('data-server-fehler') && serverListe) {
        const erster = serverListe.querySelector('[data-schritt-ziel]');
        aktuell = erster ? begrenze(Number(erster.dataset.schrittZiel)) : 1;
        serverListe.addEventListener('click', (event) => {
            const link = event.target.closest('a[data-schritt-ziel]');
            if (!link) {
                return;
            }
            event.preventDefault();
            zeige(begrenze(Number(link.dataset.schrittZiel)), { fokus: false, verlauf: true });
            fokussiereFeld(link.getAttribute('href').slice(1));
        });
    }

    zeige(aktuell, { fokus: false, verlauf: false });
    history.replaceState(Object.assign({}, history.state, { angebotSchritt: aktuell }), '');
    if (serverListe) {
        serverListe.focus();
    }

    form.addEventListener('click', (event) => {
        const weiter = event.target.closest('[data-weiter]');
        const zurueck = event.target.closest('[data-zurueck]');
        const aendern = event.target.closest('[data-gehe-zu]');
        if (weiter) {
            event.preventDefault();
            if (pruefeSchritt(aktuell, true)) {
                zeige(aktuell + 1, { fokus: true, verlauf: true });
            }
        } else if (zurueck) {
            event.preventDefault();
            zeige(aktuell - 1, { fokus: true, verlauf: true });
        } else if (aendern) {
            event.preventDefault();
            zeige(begrenze(Number(aendern.dataset.geheZu)), { fokus: true, verlauf: true });
        }
    });

    // Enter in einem Eingabefeld führt zum nächsten Schritt statt das Formular vorzeitig abzusenden.
    form.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || aktuell === anzahl) {
            return;
        }
        const ziel = event.target;
        if (ziel instanceof HTMLInputElement && !['checkbox', 'radio', 'submit', 'button'].includes(ziel.type)) {
            event.preventDefault();
            if (pruefeSchritt(aktuell, true)) {
                zeige(aktuell + 1, { fokus: true, verlauf: true });
            }
        }
    });

    form.addEventListener('change', (event) => {
        const feld = event.target;
        if (!(feld instanceof HTMLElement) || !feld.name) {
            return;
        }
        if (feld.getAttribute('aria-invalid') === 'true' || feld.closest('.c-auswahl.is-fehler')) {
            const meldung = pruefeFeld(feld);
            if (!meldung) {
                entferneFehler(feld);
            }
        }
    });

    form.addEventListener('submit', (event) => {
        if (form.dataset.gesendet === '1') {
            event.preventDefault();
            return;
        }
        for (let n = 1; n <= anzahl; n += 1) {
            if (!pruefeSchritt(n, false)) {
                event.preventDefault();
                zeige(n, { fokus: false, verlauf: true });
                pruefeSchritt(n, true);
                return;
            }
        }
        form.dataset.gesendet = '1';
        const knopf = form.querySelector('[data-absenden]');
        if (knopf) {
            knopf.setAttribute('aria-disabled', 'true');
            knopf.classList.add('is-deaktiviert');
        }
        form.setAttribute('aria-busy', 'true');
    });

    // Rückkehr aus dem Back-Forward-Cache: Sendesperre aufheben, sonst bleibt das Formular blockiert
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) {
            return;
        }
        delete form.dataset.gesendet;
        form.removeAttribute('aria-busy');
        const knopf = form.querySelector('[data-absenden]');
        if (knopf) {
            knopf.removeAttribute('aria-disabled');
            knopf.classList.remove('is-deaktiviert');
        }
    });

    window.addEventListener('popstate', (event) => {
        const ziel = event.state && Number(event.state.angebotSchritt);
        if (!ziel) {
            return;
        }
        // Vorwärts nur bis zum ersten unvollständigen Schritt.
        let erlaubt = begrenze(ziel);
        for (let n = 1; n < erlaubt; n += 1) {
            if (!pruefeSchritt(n, false)) {
                erlaubt = n;
                break;
            }
        }
        zeige(erlaubt, { fokus: true, verlauf: false });
    });

    function begrenze(n) {
        return Math.min(anzahl, Math.max(1, Number.isFinite(n) ? n : 1));
    }

    function schritt(n) {
        return schritte[n - 1];
    }

    function zeige(n, { fokus, verlauf }) {
        aktuell = begrenze(n);
        schritte.forEach((element, index) => {
            element.hidden = index + 1 !== aktuell;
        });
        aktualisiereStepper();
        if (aktuell === anzahl) {
            baueZusammenfassung();
            zeigeIndikation();
        }
        if (verlauf) {
            history.pushState(Object.assign({}, history.state, { angebotSchritt: aktuell }), '');
        }
        if (fokus) {
            const ziel = stepperBox && !stepperBox.hidden ? stepperBox : schritt(aktuell);
            ziel.scrollIntoView({ block: 'start', behavior: reduziert ? 'auto' : 'smooth' });
            const titel = schritt(aktuell).querySelector('.c-angebot__titel');
            if (titel) {
                titel.focus({ preventScroll: true });
            }
        }
    }

    function aktualisiereStepper() {
        if (!stepper) {
            return;
        }
        const titel = schritt(aktuell).dataset.titel || '';
        const status = stepper.querySelector('.c-stepper__status');
        if (status) {
            status.textContent = `Schritt ${aktuell} von ${anzahl}: ${titel}`;
        }
        stepper.querySelectorAll('.c-stepper__schritt').forEach((punkt, index) => {
            const nummer = index + 1;
            punkt.classList.toggle('is-erledigt', nummer < aktuell);
            if (nummer === aktuell) {
                punkt.setAttribute('aria-current', 'step');
            } else {
                punkt.removeAttribute('aria-current');
            }
            const text = punkt.querySelector('.c-stepper__text');
            if (!text) {
                return;
            }
            let erledigt = text.querySelector('.u-visually-hidden');
            if (nummer < aktuell && !erledigt) {
                erledigt = document.createElement('span');
                erledigt.className = 'u-visually-hidden';
                erledigt.textContent = ' (erledigt)';
                text.append(erledigt);
            } else if (nummer >= aktuell && erledigt) {
                erledigt.remove();
            }
        });
        const balken = stepper.querySelector('.c-fortschritt');
        if (balken) {
            const wert = ((aktuell - 1) / (anzahl - 1)) * 100;
            balken.dataset.wert = String(Math.round(wert / 5) * 5);
            balken.dataset.fortschritt = String(wert);
            setzeFortschritt(balken, wert, `Schritt ${aktuell} von ${anzahl}`);
        }
    }

    /* ---------- Prüfung ---------- */

    function felderIn(bereich) {
        return Array.from(bereich.querySelectorAll('input, select, textarea'))
            .filter((feld) => feld.type !== 'hidden' && !feld.closest('.c-angebot__hp') && feld.name);
    }

    function wert(name) {
        const feld = form.elements.namedItem(name);
        if (!feld) {
            return '';
        }
        if (feld instanceof RadioNodeList) {
            return feld.value || '';
        }
        if (feld.type === 'checkbox') {
            return feld.checked ? feld.value : '';
        }
        return (feld.value || '').trim();
    }

    function zahl(name) {
        const text = wert(name);
        return /^\d+$/.test(text) ? Number(text) : 0;
    }

    function pruefeFeld(feld) {
        if (feld.type === 'radio') {
            const gruppe = form.querySelectorAll(`input[type="radio"][name="${CSS.escape(feld.name)}"]`);
            const gewaehlt = Array.from(gruppe).some((radio) => radio.checked);
            if (feld.required && !gewaehlt) {
                return feld.name === 'art' ? 'Bitte wählen Sie die Verwaltungsart.' : 'Bitte wählen Sie eine Angabe.';
            }
            return '';
        }
        if (feld.type === 'checkbox') {
            return feld.required && !feld.checked ? 'Bitte bestätigen Sie, dass Sie den Datenschutzhinweis gelesen haben.' : '';
        }
        const text = (feld.value || '').trim();
        if (feld.name === 'beginn' && text !== '' && !monatUnterstuetzt && !/^(\d{4}-\d{1,2}|\d{1,2}[./]\d{4})$/.test(text)) {
            return feld.dataset.fehlertext || 'Bitte geben Sie Monat und Jahr an.';
        }
        const gueltigkeit = feld.validity;
        if (gueltigkeit.valueMissing) {
            return feld.dataset.fehlertext || 'Bitte füllen Sie dieses Feld aus.';
        }
        if (!gueltigkeit.valid) {
            if (gueltigkeit.tooLong) {
                return `Bitte kürzen Sie die Eingabe auf höchstens ${feld.maxLength} Zeichen.`;
            }
            return feld.dataset.fehlertext || 'Bitte prüfen Sie diese Angabe.';
        }
        if (feld.name === 'telefon' && text !== '' && (!/^\+?[0-9 ()/-]+$/.test(text) || (text.match(/\d/g) || []).length < 6)) {
            return feld.dataset.fehlertext || 'Bitte prüfen Sie die Telefonnummer.';
        }
        return '';
    }

    function pruefeSchritt(n, anzeigen) {
        const bereich = schritt(n);
        const fehler = [];
        const gesehen = new Set();
        felderIn(bereich).forEach((feld) => {
            if (feld.type === 'radio') {
                if (gesehen.has(feld.name)) {
                    return;
                }
                gesehen.add(feld.name);
            }
            const meldung = pruefeFeld(feld);
            if (meldung) {
                fehler.push({ feld, meldung });
            }
        });

        // Querregeln wie im AngebotValidator
        if (n === 2 && !fehler.some((f) => ['wohneinheiten', 'gewerbeeinheiten'].includes(f.feld.name))
            && zahl('wohneinheiten') + zahl('gewerbeeinheiten') < 1) {
            fehler.push({ feld: form.elements.namedItem('wohneinheiten'), meldung: 'Bitte geben Sie mindestens eine Wohn- oder Gewerbeeinheit an.' });
        }
        if (n === 4 && wert('vorname') === '' && wert('nachname') === '') {
            fehler.push({ feld: form.elements.namedItem('nachname'), meldung: 'Bitte geben Sie Ihren Vor- oder Nachnamen an.' });
        }

        if (!anzeigen) {
            return fehler.length === 0;
        }
        felderIn(bereich).forEach((feld) => entferneFehler(feld));
        fehler.forEach(({ feld, meldung }) => setzeFehler(feld, meldung));
        zeigeSchrittFehler(bereich, fehler);

        return fehler.length === 0;
    }

    function fehlerZiel(feld) {
        return feld.type === 'radio' ? feld.closest('.c-auswahl') : feld;
    }

    function feldBasisId(feld) {
        if (feld.type === 'radio') {
            return `feld-${feld.name}`;
        }
        return feld.id;
    }

    function setzeFehler(feld, meldung) {
        const ziel = fehlerZiel(feld);
        const id = `${feldBasisId(feld)}-fehler`;
        let absatz = document.getElementById(id);
        if (absatz) {
            absatz.remove();
        }
        absatz = vorlage ? vorlage.content.firstElementChild.cloneNode(true) : document.createElement('p');
        absatz.id = id;
        absatz.classList.add('c-feld__fehler');
        const text = absatz.querySelector('[data-fehler-text]') || absatz;
        text.textContent = meldung;
        const container = feld.type === 'radio' ? ziel : feld.closest('.c-feld');
        (container || ziel.parentElement).append(absatz);

        const beschreibung = new Set((ziel.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
        beschreibung.add(id);
        ziel.setAttribute('aria-describedby', Array.from(beschreibung).join(' '));
        if (feld.type === 'radio') {
            ziel.classList.add('is-fehler');
        } else {
            feld.setAttribute('aria-invalid', 'true');
        }
    }

    function entferneFehler(feld) {
        const ziel = fehlerZiel(feld);
        if (!ziel) {
            return;
        }
        const id = `${feldBasisId(feld)}-fehler`;
        const absatz = document.getElementById(id);
        if (absatz) {
            absatz.remove();
        }
        const rest = (ziel.getAttribute('aria-describedby') || '').split(/\s+/).filter((t) => t && t !== id);
        if (rest.length) {
            ziel.setAttribute('aria-describedby', rest.join(' '));
        } else {
            ziel.removeAttribute('aria-describedby');
        }
        ziel.classList.remove('is-fehler');
        if (feld.type !== 'radio') {
            feld.removeAttribute('aria-invalid');
        }
        const kasten = feld.closest('[data-schritt]')?.querySelector('[data-schritt-fehler]');
        if (kasten && !kasten.hidden && !feld.closest('[data-schritt]').querySelector('[aria-invalid="true"], .c-auswahl.is-fehler')) {
            kasten.hidden = true;
            kasten.replaceChildren();
        }
    }

    function zeigeSchrittFehler(bereich, fehler) {
        const kasten = bereich.querySelector('[data-schritt-fehler]');
        if (!kasten) {
            return;
        }
        kasten.replaceChildren();
        if (fehler.length === 0) {
            kasten.hidden = true;
            return;
        }
        const titel = document.createElement('p');
        titel.className = 'c-fehlerliste__titel';
        titel.textContent = fehler.length === 1 ? 'Bitte prüfen Sie diese Angabe:' : `Bitte prüfen Sie diese ${fehler.length} Angaben:`;
        const liste = document.createElement('ul');
        liste.className = 'c-fehlerliste__liste';
        fehler.forEach(({ feld, meldung }) => {
            const eintrag = document.createElement('li');
            const link = document.createElement('a');
            const zielId = feld.type === 'radio'
                ? (form.querySelector(`input[type="radio"][name="${CSS.escape(feld.name)}"]`) || feld).id
                : feld.id;
            link.href = `#${zielId}`;
            link.textContent = meldung;
            link.addEventListener('click', (event) => {
                event.preventDefault();
                fokussiereFeld(zielId);
            });
            eintrag.append(link);
            liste.append(eintrag);
        });
        kasten.append(titel, liste);
        kasten.hidden = false;
        kasten.focus();
    }

    function fokussiereFeld(id) {
        const feld = document.getElementById(id);
        if (!feld) {
            return;
        }
        const bereich = feld.closest('[data-schritt]');
        if (bereich && bereich.hidden) {
            zeige(Number(bereich.dataset.schritt), { fokus: false, verlauf: true });
        }
        const block = feld.closest('.c-feld, .c-auswahl') || feld;
        block.scrollIntoView({ block: 'center', behavior: reduziert ? 'auto' : 'smooth' });
        feld.focus({ preventScroll: true });
    }

    /* ---------- Zusammenfassung ---------- */

    function labelVon(name) {
        const feld = form.elements.namedItem(name);
        if (!feld) {
            return '';
        }
        if (feld instanceof RadioNodeList) {
            const gewaehlt = Array.from(feld).find((radio) => radio.checked);
            const titel = gewaehlt ? gewaehlt.closest('label')?.querySelector('.c-auswahlkarte__titel') : null;
            return titel ? titel.textContent.trim() : '';
        }
        if (feld instanceof HTMLSelectElement) {
            return feld.value ? feld.options[feld.selectedIndex].textContent.trim() : '';
        }
        return '';
    }

    function monatText(text) {
        const iso = /^(\d{4})-(\d{1,2})$/.exec(text);
        return iso ? `${iso[2].padStart(2, '0')}.${iso[1]}` : text;
    }

    function baueZusammenfassung() {
        const box = form.querySelector('[data-zusammenfassung]');
        const liste = form.querySelector('[data-zusammenfassung-liste]');
        if (!box || !liste) {
            return;
        }
        const leer = 'keine Angabe';
        const objekt = [wert('strasse'), [wert('plz'), wert('ort')].filter(Boolean).join(' ')].filter(Boolean).join(', ');
        const einheiten = [`${zahl('wohneinheiten')} Wohneinheiten`, `${zahl('gewerbeeinheiten')} Gewerbeeinheiten`];
        if (zahl('stellplaetze') > 0) {
            einheiten.push(`${zahl('stellplaetze')} Stellplätze`);
        }
        const name = [labelVon('anrede') === 'Keine Angabe' ? '' : labelVon('anrede'), wert('vorname'), wert('nachname')].filter(Boolean).join(' ');
        const nachricht = wert('nachricht');
        const eintraege = [
            { titel: 'Verwaltungsart', text: labelVon('art') || leer, schritt: 1 },
            { titel: 'Objekt', text: objekt || leer, schritt: 2 },
            { titel: 'Einheiten', text: einheiten.join(', '), schritt: 2 },
            { titel: 'Baujahr', text: wert('baujahr') || leer, schritt: 2 },
            { titel: 'Gewünschter Beginn', text: wert('beginn') ? monatText(wert('beginn')) : leer, schritt: 3 },
            { titel: 'Aktueller Verwalter', text: labelVon('aktueller_verwalter') || leer, schritt: 3 },
            { titel: 'Name', text: name || leer, schritt: 4 },
            { titel: 'E-Mail-Adresse', text: wert('email') || leer, schritt: 4 },
            { titel: 'Telefon', text: wert('telefon') || leer, schritt: 4 },
            { titel: 'Rolle', text: labelVon('rolle') || leer, schritt: 4 },
            { titel: 'Nachricht', text: nachricht ? (nachricht.length > 240 ? `${nachricht.slice(0, 240)} …` : nachricht) : leer, schritt: 4 },
        ];
        liste.replaceChildren();
        eintraege.forEach((eintrag) => {
            const gruppe = document.createElement('div');
            gruppe.className = 'c-angebot__eintrag';
            const dt = document.createElement('dt');
            dt.textContent = eintrag.titel;
            const dd = document.createElement('dd');
            const text = document.createElement('span');
            text.textContent = eintrag.text;
            const knopf = document.createElement('button');
            knopf.type = 'button';
            knopf.className = 'c-angebot__aendern';
            knopf.dataset.geheZu = String(eintrag.schritt);
            knopf.textContent = 'Ändern';
            const hinweis = document.createElement('span');
            hinweis.className = 'u-visually-hidden';
            hinweis.textContent = `: ${eintrag.titel}`;
            knopf.append(hinweis);
            dd.append(text, knopf);
            gruppe.append(dt, dd);
            liste.append(gruppe);
        });
        box.hidden = false;
    }

    /* ---------- Preisindikation (nur wenn freigeschaltet und Staffeln vorhanden) ---------- */

    function zeigeIndikation() {
        const box = form.querySelector('[data-indikation]');
        if (!box || !form.dataset.preise) {
            return;
        }
        let staffeln;
        try {
            staffeln = JSON.parse(form.dataset.preise);
        } catch {
            box.hidden = true;
            return;
        }
        const art = wert('art');
        const mengen = { residential: zahl('wohneinheiten'), commercial: zahl('gewerbeeinheiten'), parking: zahl('stellplaetze') };
        let summe = 0;
        let vollstaendig = true;
        let belegt = false;
        Object.entries(mengen).forEach(([typ, menge]) => {
            if (menge < 1) {
                return;
            }
            belegt = true;
            const staffel = staffeln.find((s) => s.art === art && s.typ === typ && menge >= s.von && (s.bis === null || menge <= s.bis));
            if (!staffel) {
                vollstaendig = false;
                return;
            }
            summe += menge * Number(staffel.preis);
        });
        if (!belegt || !vollstaendig) {
            box.hidden = true;
            return;
        }
        const format = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const betrag = box.querySelector('[data-indikation-betrag]');
        if (betrag) {
            betrag.textContent = `${format.format(summe)} EUR`;
        }
        box.hidden = false;
    }

    function pruefeMonatsfeld() {
        const test = document.createElement('input');
        test.setAttribute('type', 'month');
        return test.type === 'month';
    }
}
