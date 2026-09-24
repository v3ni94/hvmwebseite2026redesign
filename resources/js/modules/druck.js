/*
 * Druckansicht (resources/css/90-druck.css): geschlossene Aufklappelemente (FAQ) vor dem Drucken öffnen,
 * damit die Antworten auf dem Papier stehen, und danach den vorherigen Zustand wiederherstellen.
 */
export function initDruck() {
    let geoeffnet = [];
    window.addEventListener('beforeprint', () => {
        geoeffnet = Array.from(document.querySelectorAll('details:not([open])'));
        geoeffnet.forEach((element) => {
            element.open = true;
        });
    });
    window.addEventListener('afterprint', () => {
        geoeffnet.forEach((element) => {
            element.open = false;
        });
        geoeffnet = [];
    });
}
