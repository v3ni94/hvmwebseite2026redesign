/*
 * Einstieg für alle Seiten. Progressive Enhancement: jede Seite funktioniert ohne JavaScript.
 * Keine Inline-Styles im Markup; dynamische Werte nur über element.style.setProperty (CSP-konform).
 */
import { initKopfzeile } from './modules/kopfzeile.js';
import { initEinblenden } from './modules/einblenden.js';
import { initZaehler } from './modules/zaehler.js';
import { initFortschritt } from './modules/fortschritt.js';
import { initKinetik } from './modules/kinetik.js';
import { initParallaxe } from './modules/parallaxe.js';
import { initSuche } from './search.js';
import { initLesefortschritt } from './lesefortschritt.js';

document.documentElement.classList.add('js');

initKopfzeile();
initEinblenden();
initZaehler();
initFortschritt();
initKinetik();
initParallaxe();
initSuche();
initLesefortschritt();
