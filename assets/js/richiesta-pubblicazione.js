/*
 * richiesta-pubblicazione.js - la procedura a sette passi della richiesta di pubblicazione dei docenti (richiesta_pubblicazione.php).
 * Il controllo vero dei dati è sul server (ServizioPubblicazioni::valida): qui il browser aiuta soltanto, con gli stessi messaggi:
 * - un passo alla volta, con barra di avanzamento e Indietro / Avanti; si va avanti solo se il passo è compilato;
 * - i contatori di sottotitolo e descrizione contano come RegolePubblicazione::lunghezzaTesto (testo senza formattazione, entità come un carattere,
 *   a capo come spazio, spazi ai lati esclusi) e diventano rossi oltre il limite;
 * - la descrizione ha l'editor semplice (TinyMCE del portale: grassetto e corsivo);
 * - il riquadro del Public Engagement compare con «Cittadinanza»;
 * - il riepilogo del passo 7 ha «Modifica» accanto a ogni sezione.
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

    function el(id) { return document.getElementById(id); }
    function valore(nome) { var e = form.elements[nome]; return e ? String(e.value || '').trim() : ''; }

    // ── Conteggio dei caratteri: lo stesso del server (RegolePubblicazione::lunghezzaTesto) ──
    var SPAZI_AI_LATI = /^[ \t\n\r\0\x0B ]+|[ \t\n\r\0\x0B ]+$/g;
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
        return form.elements.descrizione.value;
    }

    function aggiornaContatore(id, n, max) {
        var box = el(id);
        if (!box) { return; }
        box.hidden = false;
        box.querySelector('.rp-n').textContent = String(n);
        box.classList.toggle('rp-oltre', n > max);
    }
    function aggiornaContatori() {
        aggiornaContatore('conta-sottotitolo', lunghezzaSottotitolo(form.elements.sottotitolo.value), parseInt(cfg.maxSottotitolo, 10));
        aggiornaContatore('conta-descrizione', lunghezzaTesto(htmlDescrizione()), parseInt(cfg.maxDescrizione, 10));
    }

    // ── Editor semplice della descrizione (TinyMCE del portale, solo grassetto e corsivo): parte quando si arriva al passo 3 ──
    function avviaEditor() {
        if (editor || editorInAvvio || typeof window.tinymce === 'undefined') { return; }
        editorInAvvio = true;
        window.tinymce.init({
            target: form.elements.descrizione, language: 'it', language_url: cfg.tinymceIt, base_url: cfg.tinymceBase, suffix: '.min',
            menubar: false, statusbar: false, height: 180, branding: false, promotion: false, plugins: '',
            toolbar: 'undo redo | bold italic | removeformat', valid_elements: 'strong/b,em/i,br,p',
            setup: function (ed) {
                ed.on('init', function () { editor = ed; editorInAvvio = false; aggiornaContatori(); });
                ed.on('input change keyup SetContent', aggiornaContatori);
            }
        }).catch(function () { editorInAvvio = false; });
    }

    // ── Controlli del singolo passo (stessi messaggi del server) ──
    function urlOk(u) { return /^https?:\/\/[^\s\/?#]+[^\s]*$/i.test(u); }
    function dataOk(d) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) { return false; }
        var x = new Date(d + 'T00:00:00Z'); // in UTC: con l'ora locale, in Italia toISOString() darebbe il giorno prima
        return !isNaN(x.getTime()) && x.toISOString().slice(0, 10) === d;
    }
    function estensione(nome) { var m = /\.([^.]+)$/.exec(nome); return m ? m[1].toLowerCase() : ''; }
    function maxFile() { var mb = parseInt(cfg.maxByte, 10) / 1048576; return (Math.floor(mb) === mb ? mb : mb.toFixed(1).replace('.', ',')) + ' MB'; }
    function errorePerFile(files, ammessi, etichetta, massimo, fraseMassimo) {
        if (files.length > massimo) { return fraseMassimo; }
        for (var i = 0; i < files.length; i++) {
            if (files[i].size > parseInt(cfg.maxByte, 10)) { return '«' + files[i].name + '»: il file è troppo grande (massimo ' + maxFile() + ').'; }
            if (ammessi.indexOf(estensione(files[i].name)) < 0) { return '«' + files[i].name + '»: sono ammessi solo file ' + etichetta + '.'; }
        }
        return null;
    }

    var controlli = {
        1: function (e) {
            if (!form.elements.ho_letto.checked) { e.ho_letto = 'Per continuare spunta «Ho letto le note operative».'; }
        },
        2: function (e) {
            var d = valore('data_evento');
            if (d === '') { e.data_evento = 'Indica la data dell\'evento.'; } else if (!dataOk(d)) { e.data_evento = 'La data dell\'evento non è valida.'; }
            if (!form.querySelector('input[name="modalita"]:checked')) { e.modalita = 'Scegli la modalità di svolgimento: in presenza, online o mista.'; }
            var u = valore('url_docente');
            if (u === '') { e.url_docente = 'Indica l\'indirizzo (URL) della pagina.'; } else if (!urlOk(u)) { e.url_docente = 'L\'indirizzo deve essere completo e iniziare con http:// o https://.'; }
            var altri = valore('altri_link_docente').split(/\s+/).filter(Boolean);
            for (var i = 0; i < altri.length; i++) {
                if (!urlOk(altri[i])) { e.altri_link_docente = 'Questo link non è valido: «' + altri[i] + '». Ogni link deve essere completo e iniziare con http:// o https:// (uno per riga).'; break; }
            }
        },
        3: function (e) {
            var t = valore('titolo');
            if (t === '') { e.titolo = 'Scrivi il titolo.'; } else if (Array.from(t).length > parseInt(cfg.maxTitolo, 10)) { e.titolo = 'Il titolo è troppo lungo (massimo ' + cfg.maxTitolo + ' caratteri).'; }
            var s = lunghezzaSottotitolo(form.elements.sottotitolo.value);
            if (s === 0) { e.sottotitolo = 'Scrivi il sottotitolo.'; } else if (s > parseInt(cfg.maxSottotitolo, 10)) { e.sottotitolo = 'Il sottotitolo ha ' + s + ' caratteri: al massimo ' + cfg.maxSottotitolo + ', spazi inclusi.'; }
            var n = lunghezzaTesto(htmlDescrizione());
            if (n === 0) { e.descrizione = 'Scrivi la breve descrizione.'; } else if (n > parseInt(cfg.maxDescrizione, 10)) { e.descrizione = 'La descrizione ha ' + n + ' caratteri: al massimo ' + cfg.maxDescrizione + ', spazi inclusi.'; }
        },
        4: function (e) {
            if (!form.querySelector('.rp-destinatario:checked')) { e.destinatari = 'Indica almeno un destinatario dell\'evento.'; }
        },
        5: function (e) {
            var a = el('allegati').files, im = el('immagini').files;
            if (a.length === 0) { e.allegati = 'Carica almeno un allegato (PDF, JPG o PNG).'; } else {
                var ea = errorePerFile(a, ['pdf', 'jpg', 'jpeg', 'png'], 'PDF, JPG o PNG', parseInt(cfg.maxAllegati, 10), 'Puoi caricare al massimo ' + cfg.maxAllegati + ' allegati.');
                if (ea) { e.allegati = ea; }
            }
            var ei = errorePerFile(im, ['jpg', 'jpeg', 'png'], 'JPG o PNG', parseInt(cfg.maxImmagini, 10), 'Puoi caricare al massimo ' + cfg.maxImmagini + ' immagini.');
            if (ei) { e.immagini = ei; }
        },
        6: function (e) {
            if (valore('tag') === '') { e.tag = 'Scrivi almeno un tag.'; }
            var d = valore('data_inizio_pubblicazione');
            if (d === '') { e.data_inizio_pubblicazione = 'Indica da quando pubblicare la notizia.'; } else if (!dataOk(d)) { e.data_inizio_pubblicazione = 'La data di inizio pubblicazione non è valida.'; } else if (d < cfg.oggi) { e.data_inizio_pubblicazione = 'La data di inizio pubblicazione non può essere nel passato.'; }
            if (valore('note') === '') { e.note = 'Scrivi le note (se non ce ne sono, scrivi «nessuna»).'; }
        }
    };

    function campoDi(nome) {
        if (nome === 'destinatari') { return el('per_cittadinanza'); }
        if (nome === 'allegati') { return el('allegati'); }
        if (nome === 'immagini') { return el('immagini'); }
        if (nome === 'modalita') { return form.querySelector('input[name="modalita"]'); }
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
        if (controlli[i + 1]) { controlli[i + 1](errori); }
        var primo = mostraErrori(passi[i], errori);
        return { ok: Object.keys(errori).length === 0, primo: primo };
    }
    function focalizza(c) {
        if (!c) { return; }
        try { c.scrollIntoView({ block: 'center' }); if (c.focus) { c.focus(); } } catch (x) { /* nessun problema: resta l'errore scritto */ }
    }

    // ── Riepilogo del passo 7 (tutto con textContent; la descrizione solo con grassetto, corsivo, a capo) ──
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
    function riepilogo() {
        var box = el('rp-riepilogo');
        box.textContent = '';
        var modalita = form.querySelector('input[name="modalita"]:checked');
        var etichettaModalita = modalita ? form.querySelector('label[for="' + modalita.id + '"]').textContent.trim() : '';
        var destinatari = Array.prototype.map.call(form.querySelectorAll('.rp-destinatario:checked'), function (c) { return form.querySelector('label[for="' + c.id + '"]').textContent.trim(); }).join(', ');
        var sezioni = [
            { passo: 1, titolo: 'Note operative', righe: [['Note operative', form.elements.ho_letto.checked ? 'Lette' : '']] },
            { passo: 2, titolo: 'L\'evento', righe: [['Richiedente', el('richiedente').value], ['Data dell\'evento', dataIt(valore('data_evento'))], ['Modalità di svolgimento', etichettaModalita], ['URL', valore('url_docente')], ['URL/Link ad altre pagine', valore('altri_link_docente').split(/\s+/).filter(Boolean).join('\n')]] },
            { passo: 3, titolo: 'I testi', righe: [['Titolo', valore('titolo')], ['Sottotitolo', valore('sottotitolo')], ['Breve descrizione', { html: htmlDescrizione() }]] },
            { passo: 4, titolo: 'A chi è rivolto', righe: [['Evento rivolto a', destinatari]] },
            { passo: 5, titolo: 'Allegati e immagini', righe: [['Allegati da pubblicare', nomiFile(el('allegati').files)], ['Ulteriori immagini', nomiFile(el('immagini').files)]] },
            { passo: 6, titolo: 'Pubblicazione', righe: [['Tag', valore('tag')], ['Data inizio pubblicazione', dataIt(valore('data_inizio_pubblicazione'))], ['Note', valore('note')]] }
        ];
        sezioni.forEach(function (s) {
            var sez = document.createElement('div'); sez.className = 'rp-riep-sezione';
            var testa = document.createElement('div'); testa.className = 'd-flex justify-content-between align-items-center mb-1';
            var h = document.createElement('strong'); h.textContent = s.passo + '. ' + s.titolo; testa.appendChild(h);
            var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm btn-outline-primary fw-bold'; b.textContent = 'Modifica';
            b.setAttribute('aria-label', 'Modifica: ' + s.titolo);
            b.addEventListener('click', function () { vai(s.passo - 1); });
            testa.appendChild(b); sez.appendChild(testa);
            var dl = document.createElement('dl'); dl.className = 'mb-0';
            s.righe.forEach(function (r) {
                var dt = document.createElement('dt'); dt.textContent = r[0]; dl.appendChild(dt);
                var dd = document.createElement('dd');
                if (r[1] && r[1].html !== undefined) {
                    if (r[1].html.replace(/<[^>]*>/g, '').trim() === '') { dd.textContent = '—'; } else { aggiungiHtmlSicuro(dd, r[1].html); }
                } else if (r[1] === '' || r[1] === undefined) {
                    dd.textContent = '—';
                } else {
                    String(r[1]).split('\n').forEach(function (riga, k) { if (k > 0) { dd.appendChild(document.createElement('br')); } dd.appendChild(document.createTextNode(riga)); });
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
        if (corrente === 2) { avviaEditor(); }
        if (corrente === TOTALE - 1) { riepilogo(); }
        if (!senzaScorrere) { form.scrollIntoView({ block: 'start' }); }
    }

    function cittadinanza() { el('rp-pe').hidden = !el('per_cittadinanza').checked; }

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
        for (var i = 0; i < TOTALE - 1; i++) {
            var r = controllaPasso(i);
            if (!r.ok) { ev.preventDefault(); vai(i, true); focalizza(r.primo); return; }
        }
        // un solo invio: il pulsante si spegne dopo che il browser ha raccolto i dati
        var invia = el('rp-invia');
        window.setTimeout(function () { invia.disabled = true; invia.textContent = 'Invio in corso…'; }, 0);
    });
    ['sottotitolo'].forEach(function (nome) { form.elements[nome].addEventListener('input', aggiornaContatori); });
    form.elements.descrizione.addEventListener('input', aggiornaContatori);
    form.querySelectorAll('.rp-destinatario').forEach(function (c) { c.addEventListener('change', cittadinanza); });
    cittadinanza();
    aggiornaContatori();

    var iniziale = parseInt(cfg.passoIniziale, 10) || 1;
    vai(iniziale - 1, true);
    // dopo un errore del server: gli errori accanto ai campi sono già scritti; si porta il browser sul primo campo sbagliato
    if (el('rp-errori-server')) {
        var primoErrore = passi[iniziale - 1].querySelector('.rp-errore:not(:empty)');
        if (primoErrore) { primoErrore.scrollIntoView({ block: 'center' }); } else { form.scrollIntoView({ block: 'start' }); }
    }
})();
