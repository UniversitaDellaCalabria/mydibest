/* azioni.js - Azioni dei pulsanti e dei campi che prima stavano negli attributi onclick/onchange/oninput/onsubmit.
 *
 * La Content-Security-Policy non ammette gestori scritti dentro l'HTML: al loro posto la pagina mette un data-attributo
 * e questo file (un solo ascoltatore per tipo di evento, sul documento) fa la stessa cosa.
 *
 *   data-auto-submit               cambio del campo  → invia il suo modulo
 *   data-invia-form="id"           cambio del campo  → invia il modulo con quell'id
 *   data-stampa                    clic              → stampa la pagina
 *   data-chiudi-finestra           clic              → chiude la finestra
 *   data-seleziona                 clic              → seleziona il testo del campo
 *   data-copia-da="#id"            clic              → seleziona e copia il testo del campo, il pulsante scrive «Copiato»
 *   data-tinymce-salva             invio del modulo (o clic sul pulsante) → copia il testo dell'editor nel campo
 *   data-no-invio                  invio del modulo  → non invia (la pagina lo gestisce)
 *   data-ferma-click               clic              → non arriva agli elementi sopra (si lega a ogni elemento all'avvio)
 *   data-vai="indirizzo"           cambio del campo  → va all'indirizzo (con __V__ al posto del valore, o il valore in coda)
 *   data-mostra-valore="#id"       scrittura         → copia il valore nel testo dell'elemento indicato
 *   data-togli-spunta="#id"        scrittura         → toglie la spunta all'elemento indicato
 *   data-mostra-se-spuntato="#id"  cambio            → mostra l'elemento indicato solo se la casella è spuntata (classe d-none)
 *   data-href-base + data-href-valore="#id"  clic    → l'indirizzo del link diventa base + valore del campo indicato
 *   data-maiuscolo                 scrittura         → il testo diventa maiuscolo
 *   data-alert="testo"             clic              → mostra il testo in una finestra
 *   data-blocca-menu               menu contestuale e trascinamento disattivati su quell'elemento e dentro
 *   data-nascondi-errore           immagine che non si carica → si nasconde
 *   data-on-click | data-on-change | data-on-input | data-on-keyup = "nomeFunzione"  (+ data-args='["x","@this","@value","@#id"]')
 *       chiama una funzione globale; negli argomenti «@this» è l'elemento, «@value» il suo valore, «@#id» il valore dell'elemento con quell'id.
 */
(function () {
    'use strict';

    function elemento(sel) { try { return document.querySelector(sel); } catch (e) { return null; } }

    function chiama(el, nome, grezzo, ev) {
        var fn = window[nome];
        if (typeof fn !== 'function') { return; }
        var args = [];
        if (grezzo) { try { args = JSON.parse(grezzo); } catch (e) { args = []; } if (!Array.isArray(args)) { args = [args]; } }
        args = args.map(function (a) {
            if (a === '@this') { return el; }
            if (a === '@value') { return el.value; }
            if (a === '@event') { return ev; }
            if (typeof a === 'string' && a.charAt(0) === '@' && a.charAt(1) === '#') { var t = document.getElementById(a.slice(2)); return t ? t.value : ''; }
            return a;
        });
        return fn.apply(el, args);
    }

    function salvaEditor() { if (window.tinymce && typeof window.tinymce.triggerSave === 'function') { window.tinymce.triggerSave(); } }

    // Funzioni chiamate dagli attributi data-on-*: un ascoltatore per tipo di evento
    ['click', 'change', 'input', 'keyup'].forEach(function (tipo) {
        document.addEventListener(tipo, function (ev) {
            var el = ev.target && ev.target.closest ? ev.target.closest('[data-on-' + tipo + ']') : null;
            if (el) { chiama(el, el.getAttribute('data-on-' + tipo), el.getAttribute('data-args'), ev); }
        });
    });

    document.addEventListener('change', function (ev) {
        var el = ev.target;
        if (!el || !el.closest) { return; }
        if (el.hasAttribute('data-auto-submit') && el.form) { el.form.submit(); return; }
        var id = el.getAttribute('data-invia-form');
        if (id) { var f = document.getElementById(id); if (f) { f.submit(); } return; }
        var vai = el.getAttribute('data-vai');
        if (vai) { window.location.href = vai.indexOf('__V__') >= 0 ? vai.replace('__V__', el.value) : vai + el.value; return; }
        var sp = el.getAttribute('data-mostra-se-spuntato');
        if (sp) { var d = elemento(sp); if (d) { d.classList.toggle('d-none', !el.checked); } }
    });

    document.addEventListener('input', function (ev) {
        var el = ev.target;
        if (!el || !el.getAttribute) { return; }
        var m = el.getAttribute('data-mostra-valore');
        if (m) { var d = elemento(m); if (d) { d.textContent = el.value; } }
        var t = el.getAttribute('data-togli-spunta');
        if (t) { var c = elemento(t); if (c) { c.checked = false; } }
        if (el.hasAttribute('data-maiuscolo')) { el.value = el.value.toUpperCase(); }
    });

    document.addEventListener('click', function (ev) {
        var t = ev.target && ev.target.closest ? ev.target : null;
        if (!t) { return; }
        var el;
        if ((el = t.closest('[data-stampa]'))) { window.print(); return; }
        if ((el = t.closest('[data-chiudi-finestra]'))) { window.close(); return; }
        if ((el = t.closest('[data-seleziona]'))) { if (el.select) { el.select(); } return; }
        if ((el = t.closest('[data-copia-da]'))) {
            var campo = elemento(el.getAttribute('data-copia-da'));
            if (campo) {
                campo.select();
                if (navigator.clipboard) { navigator.clipboard.writeText(campo.value); }
                el.textContent = 'Copiato';
            }
            return;
        }
        if ((el = t.closest('[data-alert]'))) { window.alert(el.getAttribute('data-alert')); return; }
        if ((el = t.closest('[data-href-base]'))) {
            var v = elemento(el.getAttribute('data-href-valore'));
            el.href = el.getAttribute('data-href-base') + (v ? v.value : '');
            return;
        }
        if ((el = t.closest('[data-tinymce-salva]')) && el.tagName !== 'FORM') { salvaEditor(); }
    });

    document.addEventListener('submit', function (ev) {
        var f = ev.target;
        if (!f || !f.hasAttribute) { return; }
        if (f.hasAttribute('data-tinymce-salva')) { salvaEditor(); }
        if (f.hasAttribute('data-no-invio')) { ev.preventDefault(); }
    });

    ['contextmenu', 'dragstart'].forEach(function (tipo) {
        document.addEventListener(tipo, function (ev) {
            if (ev.target && ev.target.closest && ev.target.closest('[data-blocca-menu]')) { ev.preventDefault(); }
        });
    });

    // Gli errori di caricamento delle immagini non risalgono: si ascoltano in fase di cattura
    document.addEventListener('error', function (ev) {
        var el = ev.target;
        if (el && el.tagName === 'IMG' && el.hasAttribute('data-nascondi-errore')) { el.style.display = 'none'; }
    }, true);

    function allAvvio() {
        // Immagini già fallite prima che questo file partisse
        document.querySelectorAll('img[data-nascondi-errore]').forEach(function (i) { if (i.complete && i.naturalWidth === 0) { i.style.display = 'none'; } });
        // Il clic non deve arrivare agli elementi sopra (es. riga cliccabile): serve un ascoltatore sull'elemento stesso, non sul documento
        document.querySelectorAll('[data-ferma-click]').forEach(function (e) { e.addEventListener('click', function (ev) { ev.stopPropagation(); }); });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', allAvvio); } else { allAvvio(); }
})();
