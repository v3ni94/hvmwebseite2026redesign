/*
 * Kinetische Typografie im Hero: Wörter der Headline werden erst zur Laufzeit in Spans zerlegt und
 * zeitversetzt eingeblendet (CSS .c-wort, --i). Das ausgelieferte h1 bleibt ohne Kindelemente.
 * Nur ohne prefers-reduced-motion; ohne JavaScript blendet die Headline als Ganzes ein (.u-auftritt).
 * Weiche Trennzeichen (U+00AD) bleiben innerhalb der Wörter erhalten.
 */
export function initKinetik() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }
    document.querySelectorAll('[data-kinetisch]').forEach((block) => {
        let index = 0;
        const zerlege = (knoten) => {
            for (const kind of Array.from(knoten.childNodes)) {
                if (kind.nodeType === Node.TEXT_NODE) {
                    const teile = kind.textContent.split(/(\s+)/);
                    const fragment = document.createDocumentFragment();
                    for (const teil of teile) {
                        if (teil === '') {
                            continue;
                        }
                        if (/^\s+$/.test(teil)) {
                            fragment.append(document.createTextNode(teil));
                            continue;
                        }
                        const wort = document.createElement('span');
                        wort.className = 'c-wort';
                        wort.textContent = teil;
                        wort.style.setProperty('--i', String(index));
                        index += 1;
                        fragment.append(wort);
                    }
                    kind.replaceWith(fragment);
                } else if (kind.nodeType === Node.ELEMENT_NODE) {
                    zerlege(kind);
                }
            }
        };
        zerlege(block);
        block.classList.remove('u-auftritt', 'u-auftritt--2');
        block.classList.add('is-kinetisch');
    });
}
