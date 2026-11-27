/*
 * richiesta-pubblicazione.js - la procedura a passi della richiesta di pubblicazione (richiesta_pubblicazione.php).
 * Il controllo vero dei dati è sul server (ServizioPubblicazioni::valida): qui il browser aiuta soltanto, con gli stessi messaggi.
 * Quali campi ci sono, quali sono obbligatori e come si chiamano NON è scritto qui: li passa la pagina nell'attributo data-campi del modulo
 * (ConfigurazioneCampi::regole(), scelti dall'amministratore in Amministrazione › Campi e colonne); un passo senza campi visibili non è nella pagina.
 * - un passo alla volta, con barra di avanzamento e Indietro / Avanti; si va avanti solo se il passo è compilato;
 * - i contatori di sottotitolo e descrizione contano come RegolePubblicazione::lunghezzaTesto (testo senza formattazione, entità come un carattere,
 *   a capo come spazio, spazi ai lati esclusi) e diventano rossi oltre il limite;
 * - la descrizione ha l'editor semplice (TinyMCE del portale: grassetto e corsivo);
 * - il riquadro del Public Engagement compare con «Cittadinanza»;
 * - il riepilogo dell'ultimo passo ha «Modifica» accanto a ogni sezione;
 * - «Dove vuoi che si pubblichi?»: con l'elenco delle pagine pieno sono caselle (pagine_scelte[]) più «Altra pagina del sito DiBEST», che deve stare su un host
 *   di unical.it con «dibest» nel nome (come RegolePubblicazione::indirizzoDibest); con l'elenco vuoto è un indirizzo libero;
 * - passo 5: la locandina (un file, sempre obbligatoria), gli altri allegati e le immagini (facoltativi);
 * - i campi nuovi aggiunti dall'amministratore (x_<n>, contenitori con data-extra) si controllano e si riassumono come gli altri, ognuno nel suo passo
 *   (anche il primo e l'ultimo); messaggi uguali a quelli del server (LetturaRisposte, lo stesso codice di MyForms).
 * Senza JavaScript la pagina resta un modulo unico con i passi uno sotto l'altro.
 */
(function () {
    'use strict';
    var form = document.getElementById('rp-form');
    if (!form) { return; }

    var passi = Array.prototype.slice.call(form.querySelectorAll('.rp-passo'));
    var TOTALE = passi.length;
    var cfg = form.dataset;
    var corrente = 0;
    var editor = null;
    var editorInAvvio = false;

    // Le regole dei campi come le ha scelte l'amministratore: {chiave: {visibile, obbligatorio, etichetta}}. Senza, vale tutto com'era.
    var campi = {};
    try { campi = JSON.parse(cfg.campi || '{}') || {}; } catch (x) { campi = {}; }
    function vis(k) { return !campi[k] || campi[k].visibile !== false; }
    function obb(k) { return vis(k) && (!campi[k] || campi[k].obbligatorio !== false); }
    function etichetta(k, predefinita) { return campi[k] && campi[k].etichetta ? campi[k].etichetta : predefinita; }

    function el(id) { return document.getElementById(id); }
    function valore(nome) { var e = form.elements[nome]; return e ? String(e.value || '').trim() : ''; }
    function numeroPasso(i) { return parseInt(passi[i].dataset.passo, 10); }
    // Posizione (0, 1, …) del passo con quel numero tra i passi della pagina: i passi senza campi visibili mancano
    function indicePasso(numero) {
        for (var i = 0; i < passi.length; i++) { if (numeroPasso(i) === numero) { return i; } }
        return 0;
    }

    // ── Conteggio dei caratteri: lo stesso del server (RegolePubblicazione::lunghezzaTesto) ──
    var SPAZI_AI_LATI = /^[ \t\n\r\0\x0B ]+|[ \t\n\r\0\x0B ]+$/g;
    function decodifica(s) {
        // come html_entity_decode: solo le entità scritte per intero (con il punto e virgola); le altre «&» restano com'è
        var t = document.createElement('textarea');
        t.innerHTML = s.replace(/&(?!#\d+;|#x[0-9a-f]+;|[a-z][a-z0-9]*;)/gi, '&amp;');
        return t.value;
    }
    function togliTag(s) { return s.replace(/<!--[\s\S]*?-->|<\?[\s\S]*?\?>|<\/?[a-zA-Z][^>]*>?/g, ''); }
    function lunghezzaTesto(html) {
        html = html.replace(/<br\s*\/?>|<\/p>\s*<p[^>]*>/gi, ' ');
        return Array.from(decodifica(togliTag(html)).replace(SPAZI_AI_LATI, '')).length;
    }
    // il sottotitolo sul server prima perde i tag e ha gli spazi ridotti a uno (RegolePubblicazione::normalizza)
    function lunghezzaSottotitolo(s) { return lunghezzaTesto(togliTag(s).replace(/\s+/g, ' ').trim()); }
    function htmlDescrizione() {
        if (editor) { return editor.getContent(); }
        return form.elements.descrizione ? form.elements.descrizione.value : '';
    }

    function aggiornaContatore(id, n, max) {
        var box = el(id);
        if (!box) { return; }
        box.hidden = false;
        box.querySelector('.rp-n').textContent = String(n);
        box.classList.toggle('rp-oltre', n > max);
    }
    function aggiornaContatori() {
        if (form.elements.sottotitolo) { aggiornaContatore('conta-sottotitolo', lunghezzaSottotitolo(form.elements.sottotitolo.value), parseInt(cfg.maxSottotitolo, 10)); }
        if (form.elements.descrizione) { aggiornaContatore('conta-descrizione', lunghezzaTesto(htmlDescrizione()), parseInt(cfg.maxDescrizione, 10)); }
    }

    // ── Editor semplice della descrizione (TinyMCE del portale, solo grassetto e corsivo): parte quando si arriva al passo dei testi ──
    function avviaEditor() {
        if (editor || editorInAvvio || typeof window.tinymce === 'undefined' || !form.elements.descrizione) { return; }
        editorInAvvio = true;
        window.tinymce.init({
            target: form.elements.descrizione, license_key: 'gpl', language: 'it', language_url: cfg.tinymceIt, base_url: cfg.tinymceBase, suffix: '.min',
            menubar: false, statusbar: false, height: 180, branding: false, promotion: false, plugins: '',
            toolbar: 'undo redo | bold italic | removeformat', valid_elements: 'strong/b,em/i,br,p',
            setup: function (ed) {
                ed.on('init', function () { editor = ed; editorInAvvio = false; aggiornaContatori(); });
                ed.on('input change keyup SetContent', aggiornaContatori);
            }
        }).catch(function () { editorInAvvio = false; });
    }

    // ── Controlli del singolo passo (stessi messaggi del server): solo i campi visibili; quelli facoltativi si controllano se compilati ──
    function urlOk(u) { return /^https?:\/\/[^\s\/?#]+[^\s]*$/i.test(u); }
    // come RegolePubblicazione::indirizzoDibest: host sotto unical.it con «dibest» nel nome
    function indirizzoDibest(u) {
        var m = /^https?:\/\/([^\s\/?#:]+)/i.exec(u);
        if (!m) { return false; }
        var host = m[1].toLowerCase().replace(/\.$/, '');
        return /\.unical\.it$/.test(host) && host.indexOf('dibest') >= 0;
    }
    function dataOk(d) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) { return false; }
        var x = new Date(d + 'T00:00:00Z'); // in UTC: con l'ora locale, in Italia toISOString() darebbe il giorno prima
        return !isNaN(x.getTime()) && x.toISOString().slice(0, 10) === d;
    }
    function estensione(nome) { var m = /\.([^.]+)$/.exec(nome); return m ? m[1].toLowerCase() : ''; }
    function maxFile() { var mb = parseInt(cfg.maxByte, 10) / 1048576; return (Math.floor(mb) === mb ? mb : mb.toFixed(1).replace('.', ',')) + ' MB'; }
    function errorePerFile(files, ammessi, etichettaFile, massimo, fraseMassimo) {
        if (files.length > massimo) { return fraseMassimo; }
        for (var i = 0; i < files.length; i++) {
            if (files[i].size > parseInt(cfg.maxByte, 10)) { return '«' + files[i].name + '»: il file è troppo grande (massimo ' + maxFile() + ').'; }
            if (ammessi.indexOf(estensione(files[i].name)) < 0) { return '«' + files[i].name + '»: sono ammessi solo file ' + etichettaFile + '.'; }
        }
        return null;
    }

    // ── Campi nuovi dell'amministratore (x_<n>): ogni campo sta in un contenitore con data-extra e data-tipo ──
    function valoriExtra(box) {
        var k = box.dataset.extra, tipo = box.dataset.tipo;
        function etichettaDi(inp) { var l = form.querySelector('label[for="' + inp.id + '"]'); return l ? l.textContent.trim() : inp.value; }
        if (tipo === 'multicheck') { return Array.prototype.map.call(box.querySelectorAll('input[type="checkbox"]:checked'), etichettaDi); }
        if (tipo === 'radio') { var r = box.querySelector('input[type="radio"]:checked'); return r ? [r.value] : []; }
        if (tipo === 'checkbox') { var c = box.querySelector('input[type="checkbox"]'); return c && c.checked ? ['Sì'] : []; }
        var campo = form.elements[k];
        var v = campo ? String(campo.value || '').trim() : '';
        return v === '' ? [] : [v];
    }
    function erroreExtra(box) {
        var k = box.dataset.extra, tipo = box.dataset.tipo;
        var nome = (campi[k] && campi[k].etichetta) || k;
        var v = valoriExtra(box);
        if (v.length === 0) { return obb(k) ? nome + ': campo obbligatorio' : null; }
        if (tipo === 'url') { return urlOk(/^https?:\/\//i.test(v[0]) ? v[0] : 'https://' + v[0]) ? null : nome + ': indirizzo web non valido'; }
        if (tipo === 'date') { return dataOk(v[0]) ? null : nome + ': data non valida'; }
        if (tipo === 'number') { return isNaN(Number(v[0].replace(',', '.'))) ? nome + ': scrivi un numero' : null; }
        return null;
    }
    function controlliExtra(passo, e) {
        passo.querySelectorAll('[data-extra]').forEach(function (box) {
            var k = box.dataset.extra;
            if (!vis(k)) { return; }
            var m = erroreExtra(box);
            if (m) { e[k] = m; }
        });
    }

    var controlli = {
        1: function (e) {
            if (!form.elements.ho_letto.checked) { e.ho_letto = 'Per continuare spunta «Ho letto le note operative».'; }
        },
        2: function (e) {
            var d = valore('data_evento');
            if (d === '') { e.data_evento = 'Indica la data dell\'evento.'; } else if (!dataOk(d)) { e.data_evento = 'La data dell\'evento non è valida.'; }
            if (vis('modalita') && obb('modalita') && !form.querySelector('input[name="modalita"]:checked')) { e.modalita = 'Scegli la modalità di svolgimento: in presenza, online o mista.'; }
            if (vis('url_docente') && form.elements.url_docente) {
                var u = valore('url_docente');
                if (u === '') { if (obb('url_docente')) { e.url_docente = 'Indica l\'indirizzo (URL) della pagina.'; } } else if (!urlOk(u)) { e.url_docente = 'L\'indirizzo deve essere completo e iniziare con http:// o https://.'; }
            } else if (vis('url_docente') && el('rp-pagine')) {
                var altra = valore('altra_pagina');
                if (altra === '') {
                    if (obb('url_docente') && !form.querySelector('.rp-pagina:checked')) { e.url_docente = 'Scegli almeno una pagina dove pubblicare.'; }
                } else if (!urlOk(altra)) { e.url_docente = 'L\'indirizzo «' + altra + '» deve essere completo e iniziare con http:// o https://.';
                } else if (!indirizzoDibest(altra)) { e.url_docente = 'Scegli una pagina del DiBEST: l\'indirizzo deve stare su dibest.unical.it o su un sito di Unical con «dibest» nel nome (per esempio didattica.dibest.unical.it).'; }
            }
            if (vis('altri_link_docente')) {
                var altri = valore('altri_link_docente').split(/\s+/).filter(Boolean);
                for (var i = 0; i < altri.length; i++) {
                    if (!urlOk(altri[i])) { e.altri_link_docente = 'Questo link non è valido: «' + altri[i] + '». Ogni link deve essere completo e iniziare con http:// o https:// (uno per riga).'; break; }
                }
            }
        },
        3: function (e) {
            var t = valore('titolo');
            if (t === '') { e.titolo = 'Scrivi il titolo.'; } else if (Array.from(t).length > parseInt(cfg.maxTitolo, 10)) { e.titolo = 'Il titolo è troppo lungo (massimo ' + cfg.maxTitolo + ' caratteri).'; }
            if (vis('sottotitolo')) {
                var s = lunghezzaSottotitolo(form.elements.sottotitolo.value);
                if (s === 0) { if (obb('sottotitolo')) { e.sottotitolo = 'Scrivi il sottotitolo.'; } } else if (s > parseInt(cfg.maxSottotitolo, 10)) { e.sottotitolo = 'Il sottotitolo ha ' + s + ' caratteri: al massimo ' + cfg.maxSottotitolo + ', spazi inclusi.'; }
            }
            if (vis('descrizione')) {
                var n = lunghezzaTesto(htmlDescrizione());
                if (n === 0) { if (obb('descrizione')) { e.descrizione = 'Scrivi la breve descrizione.'; } } else if (n > parseInt(cfg.maxDescrizione, 10)) { e.descrizione = 'La descrizione ha ' + n + ' caratteri: al massimo ' + cfg.maxDescrizione + ', spazi inclusi.'; }
            }
        },
        4: function (e) {
            if (vis('destinatari') && !form.querySelector('.rp-destinatario:checked')) { e.destinatari = 'Indica almeno un destinatario dell\'evento.'; }
        },
        5: function (e) {
            // «almeno un allegato» e i numeri massimi vengono dalle impostazioni (attributi data-* del modulo, scritti dalla pagina)
            if (el('locandina')) {
                var l = el('locandina').files;
                if (l.length === 0) { e.locandina = 'Carica la locandina (un file PDF, JPG o PNG).'; } else {
                    var el_ = errorePerFile(l, ['pdf', 'jpg', 'jpeg', 'png'], 'PDF, JPG o PNG', 1, 'La locandina è un file solo: gli altri file vanno negli altri allegati.');
                    if (el_) { e.locandina = el_; }
                }
            }
            if (vis('allegati') && el('allegati')) {
                var a = el('allegati').files;
                if (a.length === 0) { if (cfg.allegatiObbligatori !== '0') { e.allegati = 'Carica almeno un altro allegato, oltre alla locandina (PDF, JPG o PNG).'; } } else {
                    var ea = errorePerFile(a, ['pdf', 'jpg', 'jpeg', 'png'], 'PDF, JPG o PNG', parseInt(cfg.maxAllegati, 10), 'Puoi caricare al massimo ' + cfg.maxAllegati + ' allegati.');
                    if (ea) { e.allegati = ea; }
                }
            }
            if (vis('immagini') && el('immagini')) {
                var ei = errorePerFile(el('immagini').files, ['jpg', 'jpeg', 'png'], 'JPG o PNG', parseInt(cfg.maxImmagini, 10), 'Puoi caricare al massimo ' + cfg.maxImmagini + ' immagini.');
                if (ei) { e.immagini = ei; }
            }
        },
        6: function (e) {
            if (vis('tag') && obb('tag') && valore('tag') === '') { e.tag = 'Scrivi almeno un tag.'; }
            if (vis('data_inizio_pubblicazione')) {
                var d = valore('data_inizio_pubblicazione');
                if (d === '') { if (obb('data_inizio_pubblicazione')) { e.data_inizio_pubblicazione = 'Indica da quando pubblicare la notizia.'; } } else if (!dataOk(d)) { e.data_inizio_pubblicazione = 'La data di inizio pubblicazione non è valida.'; } else if (d < (cfg.primaInizio || cfg.oggi)) { e.data_inizio_pubblicazione = cfg.messaggioInizio || 'La data di inizio pubblicazione non può essere nel passato.'; }
            }
            if (vis('note') && obb('note') && valore('note') === '') { e.note = 'Scrivi le note (se non ce ne sono, scrivi «nessuna»).'; }
        }
    };

    function campoDi(nome) {
        if (nome === 'destinatari') { return el('per_cittadinanza'); }
        if (nome === 'url_docente' && el('rp-pagine')) { return form.querySelector('.rp-pagina') || el('altra_pagina'); }
        if (nome === 'locandina') { return el('locandina'); }
        if (nome === 'allegati') { return el('allegati'); }
        if (nome === 'immagini') { return el('immagini'); }
        if (nome === 'modalita') { return form.querySelector('input[name="modalita"]'); }
        if (/^x_\d+$/.test(nome)) { return form.querySelector('[data-extra="' + nome + '"] input, [data-extra="' + nome + '"] textarea'); }
        return form.elements[nome] || null;
    }

    // Toglie gli errori del passo e mette quelli nuovi; ritorna il primo campo con errore (null se tutto a posto)
    function mostraErrori(passo, errori) {
        passo.querySelectorAll('[data-errore-per]').forEach(function (box) { box.textContent = ''; });
        passo.querySelectorAll('.rp-invalido').forEach(function (c) { c.classList.remove('rp-invalido'); });
        var primo = null;
        Object.keys(errori).forEach(function (nome) {
            var box = passo.querySelector('[data-errore-per="' + nome + '"]');
            if (box) { box.textContent = errori[nome]; }
            var c = campoDi(nome);
            if (c && c.classList) { c.classList.add('rp-invalido'); }
            if (primo === null && c) { primo = c; }
        });
        return primo;
    }
    function controllaPasso(i) {
        var errori = {};
        var n = numeroPasso(i);
        if (controlli[n]) { controlli[n](errori); }
        controlliExtra(passi[i], errori);
        var primo = mostraErrori(passi[i], errori);
        return { ok: Object.keys(errori).length === 0, primo: primo };
    }
    function focalizza(c) {
        if (!c) { return; }
        try { c.scrollIntoView({ block: 'center' }); if (c.focus) { c.focus(); } } catch (x) { /* nessun problema: resta l'errore scritto */ }
    }

    // ── Riepilogo dell'ultimo passo (tutto con textContent; la descrizione solo con grassetto, corsivo, a capo) ──
    function dataIt(d) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(d); return m ? m[3] + '/' + m[2] + '/' + m[1] : d; }
    function aggiungiHtmlSicuro(dest, html) {
        var doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
        var consentiti = { STRONG: 'strong', B: 'strong', EM: 'em', I: 'em', U: 'u', BR: 'br', P: 'p' };
        (function visita(nodo, genitore) {
            Array.prototype.forEach.call(nodo.childNodes, function (n) {
                if (n.nodeType === 3) { genitore.appendChild(document.createTextNode(n.textContent)); return; }
                if (n.nodeType !== 1) { return; }
                var tag = consentiti[n.tagName];
                if (tag) { var x = document.createElement(tag); genitore.appendChild(x); if (tag !== 'br') { visita(n, x); } } else { visita(n, genitore); }
            });
        })(doc.body, dest);
    }
    function nomiFile(lista) {
        return Array.prototype.map.call(lista, function (f) { return f.name; }).join('\n');
    }
    // Le pagine scelte (nomi delle caselle e, se c'è, l'altra pagina) o l'indirizzo libero
    function pagineScelte() {
        if (!el('rp-pagine')) { return valore('url_docente'); }
        var nomi = Array.prototype.map.call(form.querySelectorAll('.rp-pagina:checked'), function (c) { return form.querySelector('label[for="' + c.id + '"]').textContent.trim(); });
        if (valore('altra_pagina') !== '') { nomi.push(valore('altra_pagina')); }
        return nomi.join('\n');
    }
    function riepilogo() {
        var box = el('rp-riepilogo');
        box.textContent = '';
        var modalita = form.querySelector('input[name="modalita"]:checked');
        var etichettaModalita = modalita ? form.querySelector('label[for="' + modalita.id + '"]').textContent.trim() : '';
        var destinatari = Array.prototype.map.call(form.querySelectorAll('.rp-destinatario:checked'), function (c) { return form.querySelector('label[for="' + c.id + '"]').textContent.trim(); }).join(', ');
        // per ogni passo le righe (chiave del campo, valore): i campi nascosti non ci sono; l'etichetta è quella scelta
        var righeDi = {
            2: [['richiedente', function () { return el('richiedente') ? el('richiedente').value : ''; }, 'Richiedente'], ['data_evento', function () { return dataIt(valore('data_evento')); }, 'Data dell\'evento'],
                ['modalita', function () { return etichettaModalita; }, 'Modalità di svolgimento'], ['url_docente', pagineScelte, 'URL'],
                ['altri_link_docente', function () { return valore('altri_link_docente').split(/\s+/).filter(Boolean).join('\n'); }, 'URL/Link ad altre pagine']],
            3: [['titolo', function () { return valore('titolo'); }, 'Titolo'], ['sottotitolo', function () { return valore('sottotitolo'); }, 'Sottotitolo'],
                ['descrizione', function () { return { html: htmlDescrizione() }; }, 'Breve descrizione']],
            4: [['destinatari', function () { return destinatari; }, 'Evento rivolto a']],
            5: [['locandina', function () { return el('locandina') ? nomiFile(el('locandina').files) : ''; }, 'Locandina'], ['allegati', function () { return el('allegati') ? nomiFile(el('allegati').files) : ''; }, 'Altri allegati'], ['immagini', function () { return el('immagini') ? nomiFile(el('immagini').files) : ''; }, 'Ulteriori immagini']],
            6: [['tag', function () { return valore('tag'); }, 'Tag'], ['data_inizio_pubblicazione', function () { return dataIt(valore('data_inizio_pubblicazione')); }, 'Data inizio pubblicazione'],
                ['note', function () { return valore('note'); }, 'Note']]
        };
        // le righe dei campi nuovi di un passo (nell'ordine della pagina): [etichetta, valore]
        function righeExtra(n) {
            var righe = [];
            var passo = form.querySelector('.rp-passo[data-passo="' + n + '"]');
            if (!passo) { return righe; }
            passo.querySelectorAll('[data-extra]').forEach(function (box) {
                var k = box.dataset.extra;
                if (!vis(k)) { return; }
                var v = valoriExtra(box).join(', ');
                if (box.dataset.tipo === 'date') { v = dataIt(v); }
                righe.push([etichetta(k, k), v]);
            });
            return righe;
        }
        var sezioni = [{ passo: 1, titolo: 'Note operative', righe: [['Note operative', form.elements.ho_letto.checked ? 'Lette' : '']].concat(righeExtra(1)) }];
        [2, 3, 4, 5, 6].forEach(function (n) {
            // il passo è nella pagina solo se ha campi visibili
            if (!form.querySelector('.rp-passo[data-passo="' + n + '"]')) { return; }
            var righe = [];
            righeDi[n].forEach(function (r) {
                if (!vis(r[0])) { return; }
                var nome = r[0] === 'url_docente' && el('rp-pagine') ? el('rp-pagine').dataset.etichetta : etichetta(r[0], r[2]);
                righe.push([nome, r[1]()]);
            });
            righeExtra(n).forEach(function (r) { righe.push(r); });
            if (righe.length) { sezioni.push({ passo: n, titolo: passi[indicePasso(n)].dataset.titolo, righe: righe }); }
        });
        sezioni.forEach(function (s) {
            var sez = document.createElement('div'); sez.className = 'rp-riep-sezione';
            var testa = document.createElement('div'); testa.className = 'd-flex justify-content-between align-items-center mb-1';
            var h = document.createElement('strong'); h.textContent = (indicePasso(s.passo) + 1) + '. ' + s.titolo; testa.appendChild(h);
            var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm btn-outline-primary fw-bold'; b.textContent = 'Modifica';
            b.setAttribute('aria-label', 'Modifica: ' + s.titolo);
            b.addEventListener('click', function () { vai(indicePasso(s.passo)); });
            testa.appendChild(b); sez.appendChild(testa);
            var dl = document.createElement('dl'); dl.className = 'mb-0';
            s.righe.forEach(function (r) {
                var dt = document.createElement('dt'); dt.textContent = r[0]; dl.appendChild(dt);
                var dd = document.createElement('dd');
                var v = typeof r[1] === 'function' ? r[1]() : r[1];
                if (v && v.html !== undefined) {
                    if (v.html.replace(/<[^>]*>/g, '').trim() === '') { dd.textContent = '—'; } else { aggiungiHtmlSicuro(dd, v.html); }
                } else if (v === '' || v === undefined) {
                    dd.textContent = '—';
                } else {
                    String(v).split('\n').forEach(function (riga, k) { if (k > 0) { dd.appendChild(document.createElement('br')); } dd.appendChild(document.createTextNode(riga)); });
                }
                dl.appendChild(dd);
            });
            sez.appendChild(dl); box.appendChild(sez);
        });
    }

    // ── Passi ──
    function vai(i, senzaScorrere) {
        corrente = Math.max(0, Math.min(TOTALE - 1, i));
        passi.forEach(function (p, k) { p.hidden = k !== corrente; });
        el('rp-avanz-testo').textContent = 'Passo ' + (corrente + 1) + ' di ' + TOTALE;
        el('rp-avanz-titolo').textContent = passi[corrente].dataset.titolo;
        el('rp-barra').style.width = Math.round(((corrente + 1) / TOTALE) * 100) + '%';
        el('rp-avanzamento').querySelector('[role="progressbar"]').setAttribute('aria-valuenow', String(corrente + 1));
        el('rp-indietro').hidden = corrente === 0;
        el('rp-avanti').hidden = corrente === TOTALE - 1;
        if (numeroPasso(corrente) === 3) { avviaEditor(); }
        if (corrente === TOTALE - 1) { riepilogo(); }
        if (!senzaScorrere) { form.scrollIntoView({ block: 'start' }); }
    }

    function cittadinanza() { if (el('rp-pe') && el('per_cittadinanza')) { el('rp-pe').hidden = !el('per_cittadinanza').checked; } }

    // ── Avvio: da qui i passi diventano uno alla volta ──
    form.noValidate = true; // i controlli li fa questo file, con le frasi del portale
    el('rp-avanzamento').hidden = false;
    el('rp-nav').hidden = false;
    form.classList.add('rp-con-passi');

    el('rp-avanti').addEventListener('click', function () {
        var r = controllaPasso(corrente);
        if (!r.ok) { focalizza(r.primo); return; }
        vai(corrente + 1);
    });
    el('rp-indietro').addEventListener('click', function () { vai(corrente - 1); });
    // Invio dentro un campo di testo = Avanti (non deve inviare la richiesta a metà strada)
    form.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && ev.target.tagName === 'INPUT' && ev.target.type !== 'submit' && ev.target.type !== 'button') {
            ev.preventDefault();
            if (corrente < TOTALE - 1) { el('rp-avanti').click(); }
        }
    });
    form.addEventListener('submit', function (ev) {
        if (editor) { editor.save(); }
        for (var i = 0; i < TOTALE; i++) { // anche l'ultimo passo: può avere campi nuovi da compilare
            var r = controllaPasso(i);
            if (!r.ok) { ev.preventDefault(); vai(i, true); focalizza(r.primo); return; }
        }
        // un solo invio: il pulsante si spegne dopo che il browser ha raccolto i dati
        var invia = el('rp-invia');
        window.setTimeout(function () { invia.disabled = true; invia.textContent = 'Invio in corso…'; }, 0);
    });
    if (form.elements.sottotitolo) { form.elements.sottotitolo.addEventListener('input', aggiornaContatori); }
    if (form.elements.descrizione) { form.elements.descrizione.addEventListener('input', aggiornaContatori); }
    form.querySelectorAll('.rp-destinatario').forEach(function (c) { c.addEventListener('change', cittadinanza); });
    cittadinanza();
    aggiornaContatori();

    var iniziale = indicePasso(parseInt(cfg.passoIniziale, 10) || 1);
    vai(iniziale, true);
    // dopo un errore del server: gli errori accanto ai campi sono già scritti; si porta il browser sul primo campo sbagliato
    if (el('rp-errori-server')) {
        var primoErrore = passi[iniziale].querySelector('.rp-errore:not(:empty)');
        if (primoErrore) { primoErrore.scrollIntoView({ block: 'center' }); } else { form.scrollIntoView({ block: 'start' }); }
    }
})();
