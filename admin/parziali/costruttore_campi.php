<?php
// costruttore_campi.php - Costruttore dei campi riusabile nel pannello (oggi: MyForms, admin/forms.php): una riga per
// campo (domanda, tipo, opzioni, obbligatorio, aiuto) con colonne delle tabelle a righe e logica "mostra solo se" /
// "compila se", nello stesso stile del costruttore della Didattica (admin/didattica_moduli.php) ma SENZA i tipi delle
// anagrafi di Ateneo né i campi "ufficio": scritto apposta per non rischiare la pagina della Didattica, già in uso.
// Stesso formato di campi_json letto da App\Didattica\CampiModulo::da() e, lato POST, da App\Forms\CostruttoreCampi::daPost().
// Solo definizioni di funzione, ma senza la guardia il file si aprirebbe da Apache: vedi sotto.
// Guardia: non si apre da solo. Lo includono solo le pagine del pannello, dopo admin_header.php (che definisce nega_accesso_pannello() e applica la mappa
// PermessiPagine). Le pagine che lo includono non hanno una costante propria (forms.php, pubblicazioni.php): si usa il segno che admin_header.php è passato.
if (!function_exists('nega_accesso_pannello')) { http_response_code(403); exit('Accesso negato.'); }

if (!function_exists('costruttore_tipi_colonna')) {
    /** Tipi di colonna offerti nelle tabelle a righe del costruttore condiviso (senza i tipi legati alle anagrafi di Ateneo): App\Forms\CostruttoreCampi::TIPI_COLONNA, controllati anche lato server da CostruttoreCampi::daPost(). */
    function costruttore_tipi_colonna(): array {
        return \App\Forms\CostruttoreCampi::TIPI_COLONNA;
    }
}

if (!function_exists('html_costruttore_campi')) {
    /**
     * HTML + JavaScript del costruttore dei campi.
     *
     * @param string $prefisso prefisso dei nomi nel POST, letti con App\Forms\CostruttoreCampi::daPost($post, $prefisso)
     * @param list<array<string, mixed>> $campi campi di partenza (stessa forma del campi_json decodificato)
     * @param list<string> $tipiAmmessi chiavi di App\Didattica\Costanti::TIPI_CAMPO_PRATICA da offrire
     */
    function html_costruttore_campi(string $prefisso, array $campi, array $tipiAmmessi): string {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $pfx = preg_replace('/[^a-zA-Z0-9_]/', '', $prefisso) ?: 'c';
        $boxId = 'cc_' . $pfx . '_box';
        $gruppi = [];
        foreach (\App\Didattica\Costanti::GRUPPI_TIPI_CAMPO as $g => $tt) {
            $f = array_values(array_intersect($tt, $tipiAmmessi));
            if ($f) $gruppi[$g] = $f;
        }
        $tipiColonna = costruttore_tipi_colonna();
        $righe = $campi ?: [['etichetta' => '', 'tipo' => 'text']];

        ob_start(); ?>
<div class="cc-box" id="<?php echo $h($boxId); ?>">
    <div class="cc-campo cc-testa small fw-bold text-secondary d-none d-lg-grid"><span>Domanda</span><span>Tipo</span><span>Opzioni / colonne</span><span></span><span>Aiuto (facoltativo)</span><span></span><span></span></div>
    <div class="cc-righe">
        <?php foreach ($righe as $c):
            $cond = is_array($c['cond'] ?? null) ? $c['cond'] : [];
            $auto = is_array($c['auto'] ?? null) ? $c['auto'] : [];
            $opzTxt = is_array($c['opzioni'] ?? null) ? implode(', ', $c['opzioni']) : (string)($c['opzioni'] ?? '');
            $isTabella = ($c['tipo'] ?? '') === 'tabella';
            $colonneF = $isTabella ? \App\Didattica\CampiModulo::colonne(array_values(array_filter(array_map('trim', preg_split('/[,;\n]/', $opzTxt)), 'strlen'))) : [];
        ?>
        <div class="cc-campo">
            <input type="text" class="form-control form-control-sm" name="<?php echo $h($pfx); ?>_etichetta[]" value="<?php echo $h($c['etichetta'] ?? ''); ?>" placeholder="Domanda" aria-label="Domanda">
            <select class="form-select form-select-sm cc-tipo" name="<?php echo $h($pfx); ?>_tipo[]" aria-label="Tipo">
                <?php foreach ($gruppi as $g => $tt): ?><optgroup label="<?php echo $h($g); ?>"><?php foreach ($tt as $k): ?><option value="<?php echo $h($k); ?>"<?php echo ($c['tipo'] ?? '') === $k ? ' selected' : ''; ?>><?php echo $h(\App\Didattica\Costanti::TIPI_CAMPO_PRATICA[$k]); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
            </select>
            <input type="text" class="form-control form-control-sm cc-opz" name="<?php echo $h($pfx); ?>_opzioni[]" value="<?php echo $h($colonneF ? \App\Didattica\CampiModulo::testoColonne($colonneF) : $opzTxt); ?>" placeholder="Opzioni o colonne" aria-label="Opzioni o colonne">
            <span><input type="hidden" name="<?php echo $h($pfx); ?>_obbl[]" value="<?php echo !empty($c['obbligatorio']) ? '1' : '0'; ?>"><label class="form-check small text-nowrap m-0"><input class="form-check-input cc-chk" type="checkbox"<?php echo !empty($c['obbligatorio']) ? ' checked' : ''; ?>> obbligatorio</label></span>
            <input type="text" class="form-control form-control-sm" name="<?php echo $h($pfx); ?>_aiuto[]" value="<?php echo $h($c['aiuto'] ?? ''); ?>" placeholder="Aiuto o testo (facoltativo)" aria-label="Testo di aiuto">
            <button type="button" class="btn btn-sm <?php echo $cond || $auto ? 'btn-warning' : 'btn-outline-secondary'; ?> cc-logica-btn" aria-label="Colonne e logica del campo" title="Colonne e logica"><i class="fa fa-sliders" aria-hidden="true"></i></button>
            <button type="button" class="btn btn-sm btn-outline-danger cc-togli" aria-label="Togli il campo"><i class="fa fa-times" aria-hidden="true"></i></button>
            <div class="cc-logica" hidden>
                <div class="cc-colonne mb-2"<?php echo $isTabella ? '' : ' hidden'; ?>>
                    <div class="small fw-bold mb-1"><i class="fa fa-table-columns me-1" aria-hidden="true"></i>Colonne della tabella</div>
                    <div class="cc-col-righe"></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 cc-col-agg"><i class="fa fa-plus me-1" aria-hidden="true"></i>Colonna</button>
                </div>
                <div class="row g-1 align-items-center small mb-1">
                    <div class="col-md-2 fw-bold"><i class="fa fa-eye me-1" aria-hidden="true"></i>Mostra solo se</div>
                    <div class="col-md-4"><select class="form-select form-select-sm cc-rif" name="<?php echo $h($pfx); ?>_cond_campo[]" data-val="<?php echo $h($cond['campo'] ?? ''); ?>" aria-label="Campo della condizione"><option value="">— sempre visibile —</option></select></div>
                    <div class="col-md-2"><select class="form-select form-select-sm" name="<?php echo $h($pfx); ?>_cond_op[]" aria-label="Condizione"><?php foreach (\App\Didattica\Costanti::OPERATORI_CONDIZIONE as $k => $n): ?><option value="<?php echo $h($k); ?>"<?php echo ($cond['op'] ?? 'uguale') === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4"><input class="form-control form-control-sm" name="<?php echo $h($pfx); ?>_cond_val[]" value="<?php echo $h($cond['valore'] ?? ''); ?>" placeholder="valore (es. Sì)" aria-label="Valore della condizione"></div>
                </div>
                <div class="row g-1 align-items-center small">
                    <div class="col-md-2 fw-bold"><i class="fa fa-wand-magic-sparkles me-1" aria-hidden="true"></i>Compila se</div>
                    <div class="col-md-3"><select class="form-select form-select-sm cc-rif" name="<?php echo $h($pfx); ?>_auto_campo[]" data-val="<?php echo $h($auto['campo'] ?? ''); ?>" aria-label="Campo del valore automatico"><option value="">— mai —</option></select></div>
                    <div class="col-md-2"><select class="form-select form-select-sm" name="<?php echo $h($pfx); ?>_auto_op[]" aria-label="Condizione"><?php foreach (\App\Didattica\Costanti::OPERATORI_CONDIZIONE as $k => $n): ?><option value="<?php echo $h($k); ?>"<?php echo ($auto['op'] ?? 'uguale') === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-2"><input class="form-control form-control-sm" name="<?php echo $h($pfx); ?>_auto_val[]" value="<?php echo $h($auto['valore'] ?? ''); ?>" placeholder="valore" aria-label="Valore"></div>
                    <div class="col-md-3"><input class="form-control form-control-sm" name="<?php echo $h($pfx); ?>_auto_imposta[]" value="<?php echo $h($auto['imposta'] ?? ''); ?>" placeholder="allora questo campo vale…" aria-label="Valore automatico"></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary fw-bold cc-aggiungi"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi campo</button>
</div>
<script nonce="<?php echo csp_nonce(); ?>">
(function () {
    var TIPI_COL = <?php echo json_encode($tipiColonna, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var box = document.getElementById(<?php echo json_encode($boxId); ?>);
    if (!box || box.dataset.ccInit) return;
    box.dataset.ccInit = '1';
    var righe = box.querySelector('.cc-righe');

    // ── Colonne delle tabelle: righe nome + tipo, scritte nelle opzioni come "Nome:tipo" ──
    function leggiColonne(testo) {
        return testo.split(/[,;\n]/).map(function (x) { return x.trim(); }).filter(Boolean).map(function (x) {
            var m = x.match(/^(.*?):\s*([a-z_]+)(?:\((.*)\))?\s*$/);
            if (m && TIPI_COL[m[2]]) return { nome: m[1].trim(), tipo: m[2], scelte: (m[3] || '').split('|').map(function (y) { return y.trim(); }).filter(Boolean).join(' | ') };
            return { nome: x, tipo: 'testo', scelte: '' };
        });
    }
    function rigaCol(c) {
        var d = document.createElement('div'); d.className = 'cc-col-riga';
        var o = Object.keys(TIPI_COL).map(function (k) { return '<option value="' + k + '"' + (k === c.tipo ? ' selected' : '') + '>' + TIPI_COL[k] + '</option>'; }).join('');
        d.innerHTML = '<input class="form-control form-control-sm cc-nome" placeholder="Nome della colonna" aria-label="Nome della colonna"><select class="form-select form-select-sm cc-col-tipo" aria-label="Tipo della colonna">' + o + '</select>'
            + '<input class="form-control form-control-sm cc-scelte" placeholder="Scelte separate da |" aria-label="Scelte della tendina"><button type="button" class="btn btn-sm btn-link text-danger p-0 cc-col-togli" aria-label="Togli la colonna"><i class="fa fa-times" aria-hidden="true"></i></button>';
        d.querySelector('.cc-nome').value = c.nome; d.querySelector('.cc-scelte').value = c.scelte || '';
        d.querySelector('.cc-scelte').hidden = c.tipo !== 'scelta';
        return d;
    }
    function disegnaColonne(r) {
        var cont = r.querySelector('.cc-col-righe'); cont.innerHTML = '';
        var cols = leggiColonne(r.querySelector('.cc-opz').value);
        if (!cols.length) cols = [{ nome: 'Descrizione', tipo: 'testo' }];
        cols.forEach(function (c) { cont.appendChild(rigaCol(c)); });
        scriviColonne(r);
    }
    function scriviColonne(r) {
        var parti = [];
        r.querySelectorAll('.cc-col-riga').forEach(function (d) {
            var n = d.querySelector('.cc-nome').value.replace(/[,;:]/g, ' ').trim(), t = d.querySelector('.cc-col-tipo').value;
            d.querySelector('.cc-scelte').hidden = t !== 'scelta';
            if (!n) return;
            parti.push(n + (t !== 'testo' ? ':' + t + (t === 'scelta' ? '(' + d.querySelector('.cc-scelte').value.split('|').map(function (x) { return x.replace(/[,;()]/g, ' ').trim(); }).filter(Boolean).join('|') + ')' : '') : ''));
        });
        r.querySelector('.cc-opz').value = parti.join(', ');
    }
    // ── Logica: tendine con le domande degli altri campi ──
    function aggiornaRif() {
        var et = Array.prototype.map.call(righe.querySelectorAll('.cc-campo > input[type=text]:first-child'), function (i) { return i.value.trim(); });
        righe.querySelectorAll('.cc-campo').forEach(function (r) {
            var mia = r.querySelector('input[type=text]').value.trim();
            r.querySelectorAll('.cc-rif').forEach(function (s) {
                var v = s.dataset.val !== undefined ? s.dataset.val : s.value, primo = s.options[0].outerHTML;
                s.innerHTML = primo + et.filter(function (x) { return x && x !== mia; }).map(function (x) { var o = document.createElement('option'); o.value = x; o.textContent = x; return o.outerHTML; }).join('');
                if (v && et.indexOf(v) < 0) { var o = document.createElement('option'); o.value = v; o.textContent = v + ' (domanda non trovata)'; s.appendChild(o); }
                s.value = v || ''; s.dataset.val = s.value;
            });
            var attiva = r.querySelector('.cc-rif[name$="_cond_campo[]"]').value || r.querySelector('.cc-rif[name$="_auto_campo[]"]').value;
            var b = r.querySelector('.cc-logica-btn'); b.classList.toggle('btn-warning', !!attiva); b.classList.toggle('btn-outline-secondary', !attiva);
        });
    }
    function preparaRiga(r) {
        var t = r.querySelector('.cc-tipo');
        r.querySelector('.cc-colonne').hidden = t.value !== 'tabella';
        if (t.value === 'tabella') disegnaColonne(r);
    }
    function nuovaRiga() {
        var n = righe.lastElementChild.cloneNode(true);
        n.querySelectorAll('input[type=text], .cc-logica input').forEach(function (i) { i.value = ''; });
        n.querySelectorAll('input[type=hidden]').forEach(function (i) { i.value = '0'; });
        n.querySelectorAll('.cc-chk').forEach(function (i) { i.checked = false; });
        n.querySelectorAll('.cc-rif').forEach(function (s) { s.dataset.val = ''; });
        n.querySelector('.cc-col-righe').innerHTML = ''; n.querySelector('.cc-logica').hidden = true;
        n.querySelector('.cc-tipo').value = 'text';
        righe.appendChild(n); preparaRiga(n); aggiornaRif(); return n;
    }
    righe.querySelectorAll('.cc-campo').forEach(preparaRiga); aggiornaRif();
    box.querySelector('.cc-aggiungi').addEventListener('click', function () {
        var n = nuovaRiga(); n.style.display = ''; n.querySelector('input').focus();
    });
    righe.addEventListener('click', function (e) {
        var b = e.target.closest('.cc-togli');
        if (b) { var r = b.closest('.cc-campo'); if (righe.children.length > 1) r.remove(); else r.querySelectorAll('input[type=text]').forEach(function (i) { i.value = ''; }); aggiornaRif(); return; }
        var l = e.target.closest('.cc-logica-btn');
        if (l) { var p = l.closest('.cc-campo').querySelector('.cc-logica'); p.hidden = !p.hidden; aggiornaRif(); return; }
        var ca = e.target.closest('.cc-col-agg');
        if (ca) { var rr = ca.closest('.cc-campo'), nr = rigaCol({ nome: '', tipo: 'testo' }); rr.querySelector('.cc-col-righe').appendChild(nr); nr.querySelector('input').focus(); return; }
        var ct = e.target.closest('.cc-col-togli');
        if (ct) { var r2 = ct.closest('.cc-campo'); ct.closest('.cc-col-riga').remove(); scriviColonne(r2); }
    });
    righe.addEventListener('input', function (e) {
        if (e.target.closest('.cc-col-riga')) scriviColonne(e.target.closest('.cc-campo'));
        if (e.target.classList.contains('cc-opz')) {
            var r = e.target.closest('.cc-campo');
            if (r.querySelector('.cc-tipo').value === 'tabella' && !r.querySelector('.cc-logica').hidden) {
                var cont = r.querySelector('.cc-col-righe'); cont.innerHTML = '';
                leggiColonne(e.target.value).forEach(function (c) { cont.appendChild(rigaCol(c)); });
            }
        }
    });
    righe.addEventListener('focusout', function (e) { if (e.target.matches('.cc-campo > input[type=text]:first-child')) aggiornaRif(); });
    righe.addEventListener('change', function (e) {
        if (e.target.classList.contains('cc-rif')) { e.target.dataset.val = e.target.value; aggiornaRif(); }
        if (e.target.closest('.cc-col-riga')) scriviColonne(e.target.closest('.cc-campo'));
        if (e.target.classList.contains('cc-tipo')) { var r = e.target.closest('.cc-campo'); preparaRiga(r); if (e.target.value === 'tabella') r.querySelector('.cc-logica').hidden = false; }
        if (e.target.classList.contains('cc-chk')) e.target.closest('span').querySelector('input[type=hidden]').value = e.target.checked ? '1' : '0';
    });
})();
</script>
        <?php
        return ob_get_clean();
    }
}
