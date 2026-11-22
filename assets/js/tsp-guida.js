/* tsp-guida.js - Motore delle guide interattive sulla piattaforma TSP 2.0.
 *
 *   TspGuida.avvia({ radice:'#tsp', chiave:'tsp_studente', colore:'#0e7490', fasi:[{id,nome}], passi:[…] })
 *
 * Un passo ha: fase, titolo, testo, fai:[…], attenzione, info, e UNA di queste cose:
 *   scena:{ frame:'tsp'|'libero', url, h, html, azioni:[…] }   schermata finta con animazione (cursore, digitazione, evidenziazioni)
 *   elenco:[{t, d, copia, link:[etichetta,href]}]                elenco da spuntare (si ricorda cosa hai già fatto)
 *   quiz:[{d, o:[…], g, s}]                                     domande con spiegazione
 *   bivio:[{icona, t, d, vai:'id-passo'}]                       due strade a scelta
 * Azioni di una scena (array): ['cap', testo, indiceDellaListaFai] · ['wait', ms] · ['move', sel] · ['click', sel] · ['type', sel, testo]
 *   ['set', sel, testo] · ['screen', nome] · ['show'|'hide', sel] · ['pane', sel] · ['add'|'rm', sel, classe] · ['check'|'uncheck', sel]
 *   ['ring', sel] · ['unring'] · ['tip', sel, testo, 'su'|'giu', 'warn'|'ok'] · ['untip'] · ['txt', sel, html] · ['scroll', sel] · ['menu', sel, indice]
 * I selettori che iniziano con «@» cercano l'attributo data-k (per esempio '@cerca' = [data-k="cerca"]); gli altri sono selettori CSS.
 */
(function () {
    'use strict';

    var DW = 720, DH0 = 430;
    var ridotto = false;
    try { ridotto = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { /* ok */ }

    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
    function mem() { try { return window.localStorage; } catch (e) { return null; } }

    /* ---------------- Mattoncini per disegnare le schermate finte ---------------- */
    function k(x) { return x ? ' data-k="' + esc(x) + '"' : ''; }
    var ui = {
        esc: esc,
        inp: function (key, ph, cls) { return '<div class="m-in ' + (cls || '') + '"' + k(key) + '><span class="m-ph">' + esc(ph || '') + '</span><span class="m-v"></span><i class="m-caret"></i></div>'; },
        sel: function (key, ph, opzioni, cls) {
            return '<div class="m-fld-in" style="position:relative;flex:1;min-width:0"><div class="m-in m-sel ' + (cls || '') + '"' + k(key) + '><span class="m-ph">' + esc(ph || '') + '</span><span class="m-v"></span><b class="m-chev"></b></div>' +
                (opzioni ? '<div class="m-menu hid"' + k(key + '_m') + '>' + opzioni.map(function (o) { return '<div>' + esc(o) + '</div>'; }).join('') + '</div>' : '') + '</div>';
        },
        area: function (key, ph) { return ui.inp(key, ph, 'tall'); },
        chk: function (key, testo, on) { return '<div class="m-chk' + (on ? ' on' : '') + '"' + k(key) + '><i class="m-box"></i><span>' + testo + '</span></div>'; },
        rad: function (key, testo, on) { return '<div class="m-chk m-rad' + (on ? ' on' : '') + '"' + k(key) + '><i class="m-box"></i><span>' + testo + '</span></div>'; },
        btn: function (key, testo, cls) { return '<span class="m-btn ' + (cls || '') + '"' + k(key) + '>' + testo + '</span>'; },
        ico: function (key, fa, cls) { return '<span class="m-btn ic ' + (cls || '') + '"' + k(key) + '><i class="fa ' + fa + '"></i></span>'; },
        riga: function (lab, campo, req) { return '<div class="m-row"><label class="m-lab">' + lab + (req ? ' <em>*</em>' : '') + '</label><div class="m-fld">' + campo + '</div></div>'; },
        pan: function (titolo, corpo, key) { return '<section class="m-pan"' + k(key) + '><h4>' + titolo + '</h4>' + corpo + '</section>'; },
        dot: function (c, key) { return '<i class="m-dot ' + c + '"' + k(key) + '></i>'; },
        st: function (testo, c, key) { return '<span class="m-st ' + (c || '') + '"' + k(key) + '>' + testo + '</span>'; },
        sk: function (n) { var s = ''; for (var i = 0; i < n; i++) s += '<div class="m-sk s' + (1 + i % 3) + '"></div>'; return s; },
        /** Struttura della piattaforma: barra del titolo, menu a sinistra e le «schermate» tra cui passare. */
        app: function (menu, schermi, cfg) {
            cfg = cfg || {};
            var lista = (menu || []).map(function (m) { return '<div class="m-mi' + ((cfg.spenti || []).indexOf(m) >= 0 ? ' dis' : '') + '"' + k('m:' + m) + '>' + esc(m) + '</div>'; }).join('');
            var corpo = schermi.map(function (s) {
                return '<div class="scr" data-scr="' + esc(s.n) + '" data-titolo="' + esc(s.titolo || '') + '" data-menu="' + esc(s.menu || '') + '"' + (s.nomenu ? ' data-nomenu="1"' : '') + '>' + s.html + '</div>';
            }).join('');
            return '<div class="m-app"><div class="m-top"><div class="m-logo"><b>TSP 2.0</b><small>Tirocini e stage</small></div><div class="m-tit"></div><div class="m-tools"><i></i><i></i></div></div>' +
                '<div class="m-body">' + (lista ? '<nav class="m-side">' + lista + '</nav>' : '') + '<div class="m-main"><div class="tg-pan">' + corpo + '</div>' + (cfg.fuori || '') + '</div></div></div>';
        },
        /** Nodo di un diagramma «chi fa cosa» */
        nodo: function (key, attore, classe, testo, num) {
            return '<div class="sw-n ' + classe + '"' + k(key) + '>' + '<i class="sw-num">' + (num == null ? '' : num) + '</i><div class="sw-c"><span class="ac ' + classe + '">' + esc(attore) + '</span> <span class="sw-t">' + testo + '</span></div></div>';
        }
    };

    /* ---------------- La scena animata ---------------- */
    function Scena(host, sc, cb) {
        var frame = sc.frame || 'tsp', DH = sc.h || DH0;
        var win = el('div', 'tg-win tg-f-' + frame);
        if (frame === 'tsp') win.appendChild(el('div', 'tg-bar', '<i></i><i></i><i></i><span class="tg-url"><i class="fa fa-lock"></i>' + esc(sc.url || 'unical.tsp.esse3.cineca.it') + '</span>'));
        var view = el('div', 'tg-view'), screen = el('div', 'tg-screen');
        view.setAttribute('aria-hidden', 'true');
        if ('inert' in view) view.inert = true;
        view.appendChild(screen); win.appendChild(view);

        var cap = el('div', 'tg-cap'); cap.setAttribute('role', 'status'); cap.setAttribute('aria-live', 'polite');
        var capT = el('span', '', ''); cap.appendChild(el('i', 'fa fa-circle-play', '')); cap.appendChild(capT);
        var ctl = el('div', 'tg-ctl');
        var bPausa = el('button', 'tg-b'), bRivedi = el('button', 'tg-b'), bFine = el('button', 'tg-b'), bVel = el('button', 'tg-b');
        [bPausa, bRivedi, bFine, bVel].forEach(function (b) { b.type = 'button'; });
        bRivedi.innerHTML = '<i class="fa fa-rotate-right" aria-hidden="true"></i> Rivedi';
        bFine.innerHTML = '<i class="fa fa-forward-step" aria-hidden="true"></i> Salta alla fine';
        ctl.appendChild(bPausa); ctl.appendChild(bRivedi); ctl.appendChild(bFine); ctl.appendChild(el('span', 'tg-sp')); ctl.appendChild(bVel);
        host.appendChild(el('div', 'tg-scena')); host = host.lastChild;
        host.appendChild(win); host.appendChild(cap); host.appendChild(ctl);

        var vels = [{ n: 'Lenta', m: 1.5 }, { n: 'Normale', m: .8 }, { n: 'Veloce', m: .45 }], vi = 1;
        try { var sv = parseInt(mem() && mem().getItem('tsp_vel'), 10); if (sv >= 0 && sv < 3) vi = sv; } catch (e) { /* ok */ }
        function aggVel() { bVel.innerHTML = '<i class="fa fa-gauge-high" aria-hidden="true"></i> Velocità: ' + vels[vi].n; }
        aggVel();

        var tok = { morto: true }, pausa = false, istantanea = false;
        var cur, pan, main, scr = [], tipEl = null, ringEl = [], scala = 1, zoom = false, panY = 0;

        function aggPausa() { bPausa.innerHTML = pausa ? '<i class="fa fa-play" aria-hidden="true"></i> Riprendi' : '<i class="fa fa-pause" aria-hidden="true"></i> Pausa'; bPausa.disabled = tok.morto; }

        /* adattamento alla larghezza: su schermi stretti la schermata finta si ingrandisce e il «video» segue il cursore */
        function adatta() {
            var w = view.clientWidth || 600;
            scala = Math.min(w / DW, 1.25);
            zoom = (w / DW) < .8;
            screen.style.width = DW + 'px'; screen.style.height = DH + 'px';
            view.style.height = (zoom ? Math.min(DH, Math.max(250, Math.round(w * .92))) : Math.round(DH * scala)) + 'px';
            panoramica(true);
        }
        function trasf(s, x, y, subito) {
            if (subito) screen.classList.add('senza');
            screen.style.transform = 'translate(' + x + 'px,' + y + 'px) scale(' + s + ')';
            if (subito) { void screen.offsetWidth; screen.classList.toggle('senza', istantanea); }
        }
        function panoramica(subito) { trasf(zoom ? view.clientWidth / DW : scala, 0, 0, subito); }
        function camera(x, y) {
            if (!zoom) return;
            var rw = view.clientWidth, rh = view.clientHeight;
            trasf(1, -clamp(x - rw / 2, 0, Math.max(0, DW - rw)), -clamp(y - rh / 2.3, 0, Math.max(0, DH - rh)));
        }

        function q(s) { return s.charAt(0) === '@' ? screen.querySelector('[data-k="' + s.slice(1).replace(/"/g, '\\"') + '"]') : screen.querySelector(s); }
        function qa(s) { return s.charAt(0) === '@' ? screen.querySelectorAll('[data-k="' + s.slice(1).replace(/"/g, '\\"') + '"]') : screen.querySelectorAll(s); }
        /* Posizione di un elemento nella scena (pixel di progetto), calcolata dal layout: non dipende né dallo zoom né dalla transizione in corso. */
        function layout(e) {
            var x = 0, y = 0, n = e;
            while (n && n !== screen) { x += n.offsetLeft; y += n.offsetTop; n = n.offsetParent; }
            if (pan && pan.contains(e)) y -= panY;
            return { x: x, y: y };
        }
        function dove(e, fx, fy) {
            var l = layout(e);
            return { x: l.x + e.offsetWidth * (fx == null ? .5 : fx), y: l.y + e.offsetHeight * (fy == null ? .55 : fy) };
        }
        function dormi(ms) {
            var t = tok; ms = ms * vels[vi].m;
            return new Promise(function (ok) {
                if (istantanea || ms <= 0) return ok();
                var acc = 0, ult = performance.now();
                (function tick() {
                    if (t.morto) return ok();
                    var ora = performance.now();
                    if (!pausa) acc += ora - ult; ult = ora;
                    if (acc >= ms) ok(); else setTimeout(tick, Math.max(8, Math.min(40, ms - acc)));
                })();
            });
        }
        function durata(ms) { return istantanea ? 0 : ms * vels[vi].m; }

        function scorri(target) {
            if (!pan || !main) return Promise.resolve();
            var daCima = layout(target).y + panY - main.offsetTop;          /* distanza dall'alto del contenuto, a scorrimento zero */
            var max = Math.max(0, pan.offsetHeight - main.clientHeight);
            var nuovo = clamp(daCima - 50, 0, max);
            if (Math.abs(nuovo - panY) < 4) return Promise.resolve();
            panY = nuovo;
            pan.style.transitionDuration = durata(700) + 'ms';
            pan.style.transform = 'translateY(' + (-panY) + 'px)';
            return dormi(750);
        }
        function visibile(target) {
            if (!pan || !main || !pan.contains(target)) return Promise.resolve();
            var y = layout(target).y, sopra = main.offsetTop + 4, sotto = main.offsetTop + main.clientHeight - 8;
            if (y < sopra || y + target.offsetHeight > sotto) return scorri(target);
            return Promise.resolve();
        }
        function vaiSchermo(nome) {
            var trovato = null;
            scr.forEach(function (s) { var on = s.getAttribute('data-scr') === nome; s.classList.toggle('on', on); if (on) trovato = s; });
            if (!trovato) return;
            var t = screen.querySelector('.m-tit'); if (t) t.textContent = trovato.getAttribute('data-titolo') || '';
            var voce = trovato.getAttribute('data-menu');
            screen.querySelectorAll('.m-mi').forEach(function (m) { m.classList.toggle('act', m.getAttribute('data-k') === 'm:' + voce); });
            var lat = screen.querySelector('.m-side'); if (lat) lat.style.display = trovato.getAttribute('data-nomenu') ? 'none' : '';
            panY = 0; if (pan) { pan.style.transitionDuration = '0ms'; pan.style.transform = 'translateY(0)'; }
            togliTip();
        }
        function togliTip() { if (tipEl && tipEl.parentNode) tipEl.parentNode.removeChild(tipEl); tipEl = null; }
        function togliAnelli() { ringEl.forEach(function (e) { e.classList.remove('tg-ring'); }); ringEl = []; }

        function monta() {
            togliTip(); ringEl = []; panY = 0;
            screen.innerHTML = sc.html + '<div class="tg-cur"><svg width="22" height="22" viewBox="0 0 22 22" aria-hidden="true"><path d="M3 2 L3 17 L7.2 13.2 L10 19.5 L12.6 18.4 L9.9 12.2 L15.5 12 Z" fill="#fff" stroke="#111" stroke-width="1.4" stroke-linejoin="round"/></svg></div>';
            cur = screen.querySelector('.tg-cur'); pan = screen.querySelector('.tg-pan'); main = screen.querySelector('.m-main');
            scr = [].slice.call(screen.querySelectorAll('.scr'));
            if (scr.length) vaiSchermo(scr[0].getAttribute('data-scr'));
            cur.style.transform = 'translate(' + (DW - 90) + 'px,' + (DH - 70) + 'px)';
            adatta();
        }

        function muovi(target, fx, fy) {
            return visibile(target).then(function () {
                var p = dove(target, fx != null ? fx : (target.classList.contains('m-in') ? .22 : .5), fy);
                cur.style.opacity = '1';
                cur.style.transitionDuration = durata(600) + 'ms';
                cur.style.transform = 'translate(' + p.x + 'px,' + p.y + 'px)';
                camera(p.x, p.y);
                return dormi(640).then(function () { return p; });
            });
        }
        function tocco(target, p) {
            cur.classList.add('press');
            var r = el('i', 'tg-rip'); r.style.left = p.x + 'px'; r.style.top = p.y + 'px'; screen.appendChild(r);
            setTimeout(function () { if (r.parentNode) r.parentNode.removeChild(r); }, 700);
            target.classList.remove('tg-hit'); void target.offsetWidth; target.classList.add('tg-hit');
            if (target.classList.contains('m-rad')) {
                var fr = target.parentNode; [].forEach.call(fr.querySelectorAll('.m-rad'), function (r2) { r2.classList.remove('on'); });
                target.classList.add('on');
            } else if (target.classList.contains('m-chk')) { target.classList.toggle('on'); }
            else if (target.classList.contains('m-in')) { screen.querySelectorAll('.m-in.foc').forEach(function (x) { x.classList.remove('foc'); }); target.classList.add('foc'); }
            return dormi(170).then(function () { cur.classList.remove('press'); });
        }
        function scrivi(target, testo) {
            var v = target.querySelector('.m-v'); if (!v) return Promise.resolve();
            screen.querySelectorAll('.m-in.foc').forEach(function (x) { x.classList.remove('foc'); });
            target.classList.add('foc'); target.classList.add('has');
            if (istantanea) { v.textContent = testo; return Promise.resolve(); }
            var i = 0, t = tok;
            return new Promise(function (ok) {
                (function passo() {
                    if (t.morto) return ok();
                    if (pausa) return setTimeout(passo, 80);
                    i++; v.textContent = testo.slice(0, i);
                    if (i >= testo.length) return ok();
                    setTimeout(passo, 42 * vels[vi].m);
                })();
            });
        }
        function suggerimento(target, testo, lato, tipo) {
            togliTip();
            return visibile(target).then(function () {
                var t = el('div', 'tg-tip ' + (tipo || ''), esc(testo));
                screen.appendChild(t);
                var p = dove(target, 0, 0), w = t.offsetWidth, hgt = t.offsetHeight, cx = p.x + target.offsetWidth / 2;
                var sopra = lato !== 'giu';
                var top = sopra ? p.y - hgt - 10 : p.y + target.offsetHeight + 10;
                if (top < 4) { top = p.y + target.offsetHeight + 10; sopra = false; }
                var left = clamp(cx - w / 2, 6, DW - w - 6);
                t.style.left = left + 'px'; t.style.top = top + 'px'; t.style.setProperty('--ax', clamp(cx - left, 14, w - 14) + 'px');
                t.classList.add(sopra ? 'su' : 'giu');
                tipEl = t;
                camera(cx, sopra ? top + hgt + 10 : top - 10);
            });
        }

        function azione(a) {
            var tipo = a[0], t;
            function trova() { t = q(a[1]); if (!t) { if (window.console) console.warn('TspGuida: elemento non trovato', a[1]); } return t; }
            switch (tipo) {
                case 'cap': cb.cap(a[1], a[2]); capT.textContent = a[1]; capT.style.animation = 'none'; void capT.offsetWidth; capT.style.animation = ''; return dormi(a[3] != null ? a[3] : 450);
                case 'wait': return dormi(a[1]);
                case 'move': if (!trova()) return Promise.resolve(); return muovi(t, a[2], a[3]);
                case 'click': if (!trova()) return Promise.resolve();
                    return muovi(t, a[2], a[3]).then(function (p) { togliAnelli(); return tocco(t, p); }).then(function () { return dormi(110); });
                case 'type': if (!trova()) return Promise.resolve();
                    return muovi(t).then(function (p) { return tocco(t, p); }).then(function () { return scrivi(t, a[2]); }).then(function () { return dormi(120); });
                case 'set': if (!trova()) return Promise.resolve(); var v = t.querySelector('.m-v'); if (v) v.textContent = a[2]; t.classList.add('has'); t.classList.remove('foc'); return dormi(150);
                case 'menu': /* apre il menu a tendina, sceglie la voce a[2] e lo chiude */
                    if (!trova()) return Promise.resolve(); var m = q('@' + a[1].replace(/^@/, '') + '_m'); if (!m) return Promise.resolve();
                    return muovi(t).then(function (p) { return tocco(t, p); }).then(function () {
                        m.classList.remove('hid'); [].forEach.call(m.children, function (c, i) { c.classList.toggle('sel', i === a[2]); }); return dormi(320);
                    }).then(function () { return muovi(m.children[a[2]]); }).then(function (p) { return tocco(m.children[a[2]], p); }).then(function () {
                        var vv = t.querySelector('.m-v'); vv.textContent = m.children[a[2]].textContent; t.classList.add('has'); t.classList.remove('foc'); m.classList.add('hid'); return dormi(250);
                    });
                case 'screen': vaiSchermo(a[1]); return dormi(a[2] != null ? a[2] : 500);
                case 'show': qa(a[1]).forEach(function (e) { e.classList.remove('hid'); e.style.animation = 'tg-pop .3s'; }); return dormi(300);
                case 'hide': qa(a[1]).forEach(function (e) { e.classList.add('hid'); }); return dormi(120);
                case 'pane': if (!trova()) return Promise.resolve(); [].forEach.call(t.parentNode.children, function (c) { if (c.getAttribute('data-g') && c.getAttribute('data-g') === t.getAttribute('data-g')) c.classList.add('hid'); });
                    t.classList.remove('hid'); t.style.animation = 'tg-fade .35s'; return dormi(300);
                case 'add': qa(a[1]).forEach(function (e) { e.classList.add(a[2]); }); return dormi(a[3] != null ? a[3] : 350);
                case 'rm': qa(a[1]).forEach(function (e) { e.classList.remove(a[2]); }); return dormi(a[3] != null ? a[3] : 200);
                case 'check': if (trova()) t.classList.add('on'); return dormi(300);
                case 'uncheck': if (trova()) t.classList.remove('on'); return dormi(300);
                case 'txt': if (trova()) t.innerHTML = a[2]; return dormi(200);
                case 'ring': if (!trova()) return Promise.resolve(); return visibile(t).then(function () { t.classList.add('tg-ring'); ringEl.push(t); var p = dove(t); camera(p.x, p.y); return dormi(a[2] != null ? a[2] : 600); });
                case 'unring': togliAnelli(); return Promise.resolve();
                case 'tip': if (!trova()) return Promise.resolve(); return suggerimento(t, a[2], a[3], a[4]).then(function () { return dormi(a[5] != null ? a[5] : 1500); });
                case 'untip': togliTip(); return Promise.resolve();
                case 'scroll': if (!trova()) return Promise.resolve(); return scorri(t);
                default: return Promise.resolve();
            }
        }

        function esegui(vel) {
            tok.morto = true; tok = { morto: false }; var mio = tok;
            istantanea = !!vel; pausa = false; aggPausa();
            screen.classList.toggle('senza', istantanea);
            monta(); cb.inizio();
            if (istantanea) { screen.classList.add('senza'); }
            var seq = sc.azioni || [], i = 0;
            return new Promise(function (fine) {
                (function prossima() {
                    if (mio.morto) return fine();
                    if (i >= seq.length) { cur.style.opacity = '0'; togliAnelli(); togliTip(); panoramica(); mio.morto = true; aggPausa(); capT.textContent = sc.fine || 'Fatto! Rivedi l\'animazione quando vuoi, oppure passa al passo successivo.'; cb.fine(); return fine(); }
                    var a = seq[i++], r;
                    try { r = azione(a); } catch (e) { if (window.console) console.error(e); r = Promise.resolve(); }
                    r.then(prossima, prossima);
                })();
            });
        }

        bPausa.addEventListener('click', function () { if (tok.morto) return; pausa = !pausa; aggPausa(); });
        bRivedi.addEventListener('click', function () { esegui(false); });
        bFine.addEventListener('click', function () { esegui(true); });
        bVel.addEventListener('click', function () { vi = (vi + 1) % 3; aggVel(); try { mem() && mem().setItem('tsp_vel', String(vi)); } catch (e) { /* ok */ } });
        var ro = null, ultW = 0;
        if ('ResizeObserver' in window) { ro = new ResizeObserver(function () { var w = view.clientWidth; if (w && w !== ultW) { ultW = w; adatta(); } }); ro.observe(view); }

        monta(); aggPausa();
        capT.textContent = 'Premi «Rivedi» per far partire l\'animazione.';
        return {
            avvia: function () { esegui(ridotto); },
            ferma: function () { tok.morto = true; if (ro) ro.disconnect(); }
        };
    }

    /* ---------------- L'applicazione (indice, passi, avanzamento) ---------------- */
    function avvia(cfg) {
        var radice = document.querySelector(cfg.radice);
        if (!radice) return;
        var passi = cfg.passi, fasi = {};
        cfg.fasi.forEach(function (f) { fasi[f.id] = f; });
        radice.style.setProperty('--tg-acc', cfg.colore || '#0e7490');
        radice.classList.add('tg');

        var stato = { fatti: {}, ck: {}, ultimo: 0 };
        function carica() { try { var s = mem() && mem().getItem(cfg.chiave); if (s) { var o = JSON.parse(s); stato.fatti = o.fatti || {}; stato.ck = o.ck || {}; stato.ultimo = o.ultimo || 0; } } catch (e) { /* ok */ } }
        function salva() { try { mem() && mem().setItem(cfg.chiave, JSON.stringify(stato)); } catch (e) { /* ok */ } }
        carica();

        var idIdx = {}; passi.forEach(function (p, i) { if (p.id) idIdx[p.id] = i; });
        var storia = [], corrente = -1, scena = null;

        radice.innerHTML = '<div class="tg-layout"><details class="tg-idx"><summary><i class="fa fa-list-ol" aria-hidden="true"></i><span class="tg-sum"></span></summary><div class="tg-idxc"></div><div class="tg-riep"></div></details>' +
            '<div class="tg-play" id="tg-play"><div class="tg-prog" aria-hidden="true"><i></i></div><div class="tg-corpo"></div></div></div>';
        var det = radice.querySelector('.tg-idx'), idx = radice.querySelector('.tg-idxc'), riep = radice.querySelector('.tg-riep'), sum = radice.querySelector('.tg-sum');
        var corpo = radice.querySelector('.tg-corpo'), barra = radice.querySelector('.tg-prog i');
        function aggDetails() { if (window.innerWidth >= 992) det.open = true; }
        aggDetails(); window.addEventListener('resize', aggDetails);

        function costruisciIndice() {
            var html = '', fasePrec = null;
            passi.forEach(function (p, i) {
                if (p.fase !== fasePrec) { html += '<div class="tg-fase">' + esc(fasi[p.fase] ? fasi[p.fase].nome : p.fase) + '</div>'; fasePrec = p.fase; }
                html += '<button type="button" class="tg-it' + (p.opzionale ? ' opz' : '') + '" data-i="' + i + '"><span class="tg-n"><b>' + (i + 1) + '</b></span><span class="tg-t">' + esc(p.titolo) + '</span></button>';
            });
            idx.innerHTML = html;
            [].forEach.call(idx.querySelectorAll('.tg-it'), function (b) { b.addEventListener('click', function () { vai(parseInt(b.getAttribute('data-i'), 10)); if (window.innerWidth < 992) det.open = false; }); });
        }
        function conta() { return Object.keys(stato.fatti).filter(function (x) { return stato.fatti[x] && !passi[x].opzionale; }).length; }
        function totale() { return passi.filter(function (p) { return !p.opzionale; }).length; }
        function aggIndice() {
            [].forEach.call(idx.querySelectorAll('.tg-it'), function (b) {
                var i = parseInt(b.getAttribute('data-i'), 10);
                b.classList.toggle('cur', i === corrente); b.classList.toggle('fatto', !!stato.fatti[i]);
                if (i === corrente) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
            });
            var c = conta(), t = totale(), pct = Math.round(100 * c / t);
            riep.innerHTML = '<div><b>' + c + ' passi su ' + t + '</b> completati</div><div class="tg-barra" aria-hidden="true"><i style="width:' + pct + '%"></i></div>' + (c > 0 ? '<button type="button" data-az="reset">Ricomincia da capo</button>' : '<span>Premi «Avanti» per iniziare.</span>');
            var rb = riep.querySelector('[data-az=reset]');
            if (rb) rb.addEventListener('click', function () {
                var azzera = function () { stato.fatti = {}; stato.ck = {}; stato.ultimo = 0; salva(); vai(0); };
                var q = 'Cancello i passi segnati come completati e ricomincio dall\'inizio?';
                if (window.pnConferma) window.pnConferma(q, azzera); else if (window.confirm(q)) azzera();
            });
            sum.textContent = 'Passo ' + (corrente + 1) + ' di ' + passi.length + ' · ' + (passi[corrente] ? passi[corrente].titolo : '');
            barra.style.width = Math.round(100 * (corrente + 1) / passi.length) + '%';
        }
        function segna(i) { if (!stato.fatti[i]) { stato.fatti[i] = 1; salva(); aggIndice(); } }

        function prossimo(i) { var p = passi[i]; if (p.avanti && idIdx[p.avanti] != null) return idIdx[p.avanti]; return i + 1 < passi.length ? i + 1 : -1; }

        function listaFai(p) {
            if (!p.fai || !p.fai.length) return '';
            return '<div class="tg-fai"><h3>Cosa fai</h3><ol>' + p.fai.map(function (f) { return '<li>' + f + '</li>'; }).join('') + '</ol></div>';
        }
        function dettaglio(p) {
            var a = listaFai(p), b = '';
            if (p.attenzione) b += '<div class="tg-att"><h3><i class="fa fa-triangle-exclamation" aria-hidden="true"></i> Attenzione</h3>' + p.attenzione + '</div>';
            if (p.info) b += '<div class="tg-att info"><h3><i class="fa fa-circle-info" aria-hidden="true"></i> Da sapere</h3>' + p.info + '</div>';
            if (!a && !b) return '';
            return '<div class="tg-det' + (a && b ? ' due' : '') + '">' + a + (b ? '<div style="display:grid;gap:12px;align-content:start">' + b + '</div>' : '') + '</div>';
        }

        function renderElenco(box, p, i) {
            var sel = stato.ck['p' + i] || [];
            var html = '<div class="tg-ck">' + p.elenco.map(function (x, n) {
                return '<label class="' + (sel.indexOf(n) >= 0 ? 'on' : '') + '"><input type="checkbox" data-n="' + n + '"' + (sel.indexOf(n) >= 0 ? ' checked' : '') + '><span class="tg-q"><i class="fa fa-check" aria-hidden="true"></i></span><span><strong>' + x.t + '</strong>' +
                    (x.d ? '<small>' + x.d + '</small>' : '') +
                    ((x.copia || x.link) ? '<span class="tg-az">' + (x.copia ? 'Chiave: <code>' + esc(x.copia) + '</code> <button type="button" class="tg-b" data-copia="' + esc(x.copia) + '"><i class="fa fa-copy" aria-hidden="true"></i> Copia</button>' : '') +
                        (x.link ? '<a class="tg-b" href="' + esc(x.link[1]) + '" target="_blank" rel="noopener noreferrer">' + esc(x.link[0]) + ' <i class="fa fa-arrow-up-right-from-square" aria-hidden="true"></i></a>' : '') + '</span>' : '') + '</span></label>';
            }).join('') + '</div><div class="tg-conta"><span class="tg-cn"></span><div class="tg-barra" aria-hidden="true"><i></i></div></div><div class="tg-brava" role="status"><i class="fa fa-circle-check" aria-hidden="true"></i><span>' + esc(p.fine || 'Perfetto, hai tutto!') + '</span></div>';
            box.innerHTML = html;
            function agg() {
                var on = [].map.call(box.querySelectorAll('input:checked'), function (c) { return parseInt(c.getAttribute('data-n'), 10); });
                stato.ck['p' + i] = on; salva();
                [].forEach.call(box.querySelectorAll('label'), function (l) { l.classList.toggle('on', l.querySelector('input').checked); });
                box.querySelector('.tg-cn').textContent = on.length + ' di ' + p.elenco.length;
                box.querySelector('.tg-barra i').style.width = Math.round(100 * on.length / p.elenco.length) + '%';
                var ok = on.length === p.elenco.length; box.querySelector('.tg-brava').classList.toggle('on', ok);
                if (ok) { segna(i); var nx = corpo.querySelector('[data-az=avanti]'); if (nx) nx.classList.add('pulsa'); }
            }
            [].forEach.call(box.querySelectorAll('input'), function (c) { c.addEventListener('change', agg); });
            [].forEach.call(box.querySelectorAll('[data-copia]'), function (b) {
                b.addEventListener('click', function (ev) {
                    ev.preventDefault(); ev.stopPropagation();
                    var tx = b.getAttribute('data-copia');
                    function ok() { b.innerHTML = '<i class="fa fa-check" aria-hidden="true"></i> Copiato'; setTimeout(function () { b.innerHTML = '<i class="fa fa-copy" aria-hidden="true"></i> Copia'; }, 1800); }
                    try { navigator.clipboard.writeText(tx).then(ok, ok); } catch (e) { ok(); }
                });
            });
            agg();
        }

        function renderQuiz(box, p, i) {
            var n = 0, punti = 0;
            function mostra() {
                if (n >= p.quiz.length) {
                    var pct = Math.round(100 * punti / p.quiz.length);
                    box.innerHTML = '<div class="tg-qfin"><div class="tg-big">' + punti + '/' + p.quiz.length + '</div><p><b>' + (punti === p.quiz.length ? 'Perfetto! Sei pronto.' : (punti >= p.quiz.length - 1 ? 'Quasi perfetto!' : 'Rivedi i passi in cui hai sbagliato e riprova.')) + '</b></p><button type="button" class="tg-b" data-az="ripeti"><i class="fa fa-rotate-right" aria-hidden="true"></i> Rifai il quiz</button></div>';
                    box.querySelector('[data-az=ripeti]').addEventListener('click', function () { n = 0; punti = 0; mostra(); });
                    if (pct >= 60) { segna(i); var nx = corpo.querySelector('[data-az=avanti]'); if (nx) nx.classList.add('pulsa'); }
                    return;
                }
                var d = p.quiz[n];
                box.innerHTML = '<div class="tg-quiz"><div class="tg-qp">Domanda ' + (n + 1) + ' di ' + p.quiz.length + '</div><div class="tg-qd" id="tg-qd" tabindex="-1">' + d.d + '</div><div class="tg-qo" role="group" aria-labelledby="tg-qd">' +
                    d.o.map(function (o, j) { return '<button type="button" data-j="' + j + '">' + o + '</button>'; }).join('') + '</div><div class="tg-qx" aria-live="polite"></div></div>';
                [].forEach.call(box.querySelectorAll('.tg-qo button'), function (b) {
                    b.addEventListener('click', function () {
                        var j = parseInt(b.getAttribute('data-j'), 10), ok = j === d.g;
                        [].forEach.call(box.querySelectorAll('.tg-qo button'), function (x) { x.disabled = true; });
                        b.classList.add(ok ? 'giusta' : 'errata');
                        if (!ok) box.querySelectorAll('.tg-qo button')[d.g].classList.add('giusta');
                        if (ok) punti++;
                        box.querySelector('.tg-qx').innerHTML = '<div class="tg-qs"><b>' + (ok ? 'Giusto! ' : 'Non proprio. ') + '</b>' + d.s + '</div><p style="margin:12px 0 0"><button type="button" class="tg-b pri" data-az="oltre">' + (n + 1 >= p.quiz.length ? 'Vedi il risultato' : 'Domanda successiva') + ' <i class="fa fa-arrow-right" aria-hidden="true"></i></button></p>';
                        var ob = box.querySelector('[data-az=oltre]'); ob.focus();
                        ob.addEventListener('click', function () { n++; mostra(); var t = box.querySelector('#tg-qd'); if (t) t.focus({ preventScroll: true }); });
                    });
                });
            }
            mostra();
        }

        function renderBivio(box, p) {
            box.innerHTML = '<div class="tg-bivio">' + p.bivio.map(function (b, n) {
                return '<button type="button" class="tg-bv" data-n="' + n + '"><i class="fa ' + esc(b.icona) + '" aria-hidden="true"></i><strong>' + b.t + '</strong><small>' + b.d + '</small><span class="tg-vai">' + esc(b.vai_t || 'Vedi come si fa') + ' →</span></button>';
            }).join('') + '</div>';
            [].forEach.call(box.querySelectorAll('.tg-bv'), function (b) { b.addEventListener('click', function () { var d = p.bivio[parseInt(b.getAttribute('data-n'), 10)]; segna(corrente); vai(idIdx[d.vai]); }); });
        }

        function vai(i, nonStoria, silenzio) {
            if (!(i >= 0 && i < passi.length)) return;
            if (scena) { scena.ferma(); scena = null; }
            if (corrente >= 0 && !nonStoria && i !== corrente) storia.push(corrente);
            corrente = i; stato.ultimo = i; salva();
            var p = passi[i], f = fasi[p.fase] || { nome: '' };
            var nxt = prossimo(i);
            corpo.innerHTML = '<div class="tg-sh"><span class="tg-chip"><i class="fa ' + esc(f.icona || 'fa-flag') + '" aria-hidden="true"></i> ' + esc(f.nome) + (p.opzionale ? ' · a scelta' : '') + '</span><h2 id="tg-titolo" tabindex="-1">' + esc(p.titolo) + '</h2><p class="tg-lead">' + (p.testo || '') + '</p></div>' +
                '<div class="tg-main"></div>' + dettaglio(p) +
                '<nav class="tg-nav" aria-label="Spostati tra i passi"><button type="button" class="tg-b" data-az="indietro"' + (i === 0 && !storia.length ? ' disabled' : '') + '><i class="fa fa-arrow-left" aria-hidden="true"></i> Indietro</button>' +
                '<span class="tg-pos">Passo ' + (i + 1) + ' di ' + passi.length + '</span>' +
                (nxt >= 0 ? '<button type="button" class="tg-b pri" data-az="avanti">Avanti <i class="fa fa-arrow-right" aria-hidden="true"></i></button>' : '<a class="tg-b pri" href="' + esc(cfg.fineLink || '#') + '">' + esc(cfg.fineEt || 'Fine') + ' <i class="fa fa-flag-checkered" aria-hidden="true"></i></a>') + '</nav>' +
                '<p class="tg-aiuto">Puoi usare i tasti <kbd>←</kbd> e <kbd>→</kbd> per spostarti tra i passi.</p>';
            var main = corpo.querySelector('.tg-main');
            var fai = [].slice.call(corpo.querySelectorAll('.tg-fai li'));
            if (p.scena) {
                scena = Scena(main, p.scena, {
                    inizio: function () { fai.forEach(function (l) { l.classList.remove('cur', 'ok'); }); var nx = corpo.querySelector('[data-az=avanti]'); if (nx) nx.classList.remove('pulsa'); },
                    cap: function (t, n) { if (n == null) return; fai.forEach(function (l, j) { l.classList.toggle('cur', j === n); l.classList.toggle('ok', j < n); }); },
                    fine: function () { fai.forEach(function (l) { l.classList.remove('cur'); l.classList.add('ok'); }); segna(i); var nx = corpo.querySelector('[data-az=avanti]'); if (nx) nx.classList.add('pulsa'); }
                });
                setTimeout(function () { if (scena && corrente === i) scena.avvia(); }, 350);
            } else if (p.html) { main.innerHTML = p.html; segna(i); }
            else if (p.elenco) renderElenco(main, p, i);
            else if (p.quiz) renderQuiz(main, p, i);
            else if (p.bivio) renderBivio(main, p);
            var bi = corpo.querySelector('[data-az=indietro]'), ba = corpo.querySelector('[data-az=avanti]');
            if (bi) bi.addEventListener('click', function () { if (storia.length) vai(storia.pop(), true); else vai(i - 1, true); });
            if (ba) ba.addEventListener('click', function () { segna(i); vai(nxt); });
            aggIndice();
            try { history.replaceState(null, '', '#passo-' + (i + 1)); } catch (e) { /* ok */ }
            if (silenzio) return;
            var h2 = corpo.querySelector('#tg-titolo'); if (h2) h2.focus({ preventScroll: true });
            var top = radice.querySelector('#tg-play').getBoundingClientRect().top;
            if (top < 0 || top > window.innerHeight * .5) { try { radice.querySelector('#tg-play').scrollIntoView({ behavior: ridotto ? 'auto' : 'smooth', block: 'start' }); } catch (e) { /* ok */ } }
        }

        radice.addEventListener('keydown', function (ev) {
            var t = ev.target && ev.target.tagName;
            if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') return;
            if (ev.key === 'ArrowRight') { var ba = corpo.querySelector('[data-az=avanti]'); if (ba) { ev.preventDefault(); ba.click(); } }
            else if (ev.key === 'ArrowLeft') { var bi = corpo.querySelector('[data-az=indietro]'); if (bi && !bi.disabled) { ev.preventDefault(); bi.click(); } }
        });

        costruisciIndice();
        var inizio = 0, m = /^#passo-(\d+)$/.exec(location.hash || '');
        if (m) inizio = clamp(parseInt(m[1], 10) - 1, 0, passi.length - 1);
        vai(inizio, true, true);
        window.addEventListener('hashchange', function () { var mm = /^#passo-(\d+)$/.exec(location.hash || ''); if (mm) { var n = clamp(parseInt(mm[1], 10) - 1, 0, passi.length - 1); if (n !== corrente) vai(n); } });
        if (!m && stato.ultimo > 0 && stato.ultimo < passi.length) {
            var ric = el('div', 'tg-att info', '<h3><i class="fa fa-bookmark" aria-hidden="true"></i> Bentornato</h3>Eri rimasto al passo <b>' + (stato.ultimo + 1) + '</b>: «' + esc(passi[stato.ultimo].titolo) + '». <a href="#passo-' + (stato.ultimo + 1) + '">Riprendi da lì</a>');
            ric.style.marginBottom = '14px';
            radice.insertBefore(ric, radice.firstChild);
        }
    }

    window.TspGuida = { avvia: avvia, ui: ui };
})();
