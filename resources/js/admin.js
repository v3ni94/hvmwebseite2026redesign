/*
 * Admin-Bereich: kleine Verbesserungen, alles funktioniert auch ohne JavaScript.
 * - Rückfrage vor Statuswechseln, die zur Löschung oder Anonymisierung führen (data-bestaetigen-bei).
 * - Schutz gegen doppeltes Absenden von Formularen.
 */

function statusRueckfrage(formular) {
    const kritisch = (formular.dataset.bestaetigenBei || '').split(/\s+/).filter(Boolean);
    const auswahl = formular.querySelector('select[name="status"]');
    if (!auswahl || !kritisch.includes(auswahl.value)) {
        return true;
    }
    return window.confirm(formular.dataset.bestaetigenText || 'Wirklich ändern?');
}

document.addEventListener('submit', (ereignis) => {
    const formular = ereignis.target;
    if (!(formular instanceof HTMLFormElement)) {
        return;
    }
    if (formular.dataset.bestaetigenBei !== undefined && !statusRueckfrage(formular)) {
        ereignis.preventDefault();
        return;
    }
    if (formular.method.toLowerCase() !== 'post') {
        return;
    }
    if (formular.dataset.gesendet === 'ja') {
        ereignis.preventDefault();
        return;
    }
    formular.dataset.gesendet = 'ja';
    formular.querySelectorAll('button[type="submit"]').forEach((knopf) => {
        knopf.setAttribute('aria-disabled', 'true');
    });
});

// Nach Zurück-Navigation aus dem Browser-Cache wieder freigeben
window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-gesendet]').forEach((formular) => {
        delete formular.dataset.gesendet;
        formular.querySelectorAll('button[aria-disabled]').forEach((knopf) => knopf.removeAttribute('aria-disabled'));
    });
});
