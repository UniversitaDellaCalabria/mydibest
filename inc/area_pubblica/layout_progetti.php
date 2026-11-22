<?php
// Layout «progetti»: elenco con stato, periodo e scheda di dettaglio (FSL e simili).
// Caricato da master_template.php (stesso ambito della pagina) quando serve questo disegno.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $eventi_by_id, $col_primaria, $col_testo_area, $page_cfg, $current_filename, $col_class, $utente_logged, $utente_ruolo_id, $nomi_ruoli
//   $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola
?>
    <?php
    $mesi_pj = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
    $lista_pj = []; $altri_eventi = [];
    foreach ($eventi_by_id as $ev_l) {
        if (($ev_l['tipo'] ?? '') !== 'progetto') { $altri_eventi[] = $ev_l; continue; }
        $lista_pj[] = ['ev' => $ev_l, 'ip' => $info_progetto($ev_l)];
    }
    // Prima l'ordine scelto in admin (trascinamento); a parità: iscrizioni aperte, in arrivo, chiuse, in corso, concluse, poi data di inizio
    usort($lista_pj, fn($a, $b) => [(int)$a['ev']['ordine'], $a['ip']['stato']['ordine'], empty($a['ip']['d']['data_inizio']), $a['ip']['d']['data_inizio'] ?? '', (int)$a['ev']['id']]
                               <=> [(int)$b['ev']['ordine'], $b['ip']['stato']['ordine'], empty($b['ip']['d']['data_inizio']), $b['ip']['d']['data_inizio'] ?? '', (int)$b['ev']['id']]);
    $filtri_stato = ['aperte' => 'Iscrizioni aperte', 'arrivo' => 'In arrivo', 'attesa' => "Con lista d'attesa", 'in_corso' => 'In corso', 'chiuse' => 'Iscrizioni chiuse', 'concluso' => 'Conclusi'];
    $conta_stato = array_count_values(array_map(fn($x) => $x['ip']['stato']['codice'], $lista_pj));
    $strutture_pj = array_values(array_unique(array_filter(array_map(fn($x) => trim((string)($x['ip']['d']['struttura'] ?? '')), $lista_pj))));
    sort($strutture_pj);
    $txt_on_l = colore_testo_su($col_primaria);
    ?>
    <style>
        .pjl-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; box-shadow: 0 2px 10px rgba(15,23,42,.05); display: grid; grid-template-columns: 110px 1fr 240px; overflow: hidden; transition: box-shadow .2s, transform .2s; }
        .pjl-card:hover { box-shadow: 0 10px 28px rgba(15,23,42,.10); transform: translateY(-2px); }
        .pjl-card.concluso { opacity: .7; }
        .pjl-data { background: <?php echo $col_primaria; ?>; color: <?php echo $txt_on_l; ?>; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 1rem .5rem; line-height: 1.1; }
        .pjl-data .g { font-size: 2.1rem; font-weight: 800; }
        .pjl-data .m { font-size: .85rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        .pjl-data .a { font-size: .75rem; opacity: .85; }
        .pjl-corpo { padding: 1.25rem 1.5rem; min-width: 0; }
        .pjl-corpo h3 { font-size: 1.2rem; font-weight: 800; margin: .35rem 0 .4rem; }
        .pjl-corpo h3 a { color: #111827; text-decoration: none; }
        .pjl-corpo h3 a:hover { color: <?php echo $col_testo_area; ?>; text-decoration: underline; }
        .pjl-estratto { color: #475569; font-size: .92rem; line-height: 1.55; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin: .5rem 0 0; }
        .pjl-chip { display: inline-flex; align-items: center; gap: .35rem; background: #f1f5f9; color: #334155; border-radius: 999px; padding: .2rem .65rem; font-size: .78rem; font-weight: 600; }
        .pjl-azioni { padding: 1.25rem; border-left: 1px solid #f1f5f9; display: flex; flex-direction: column; justify-content: center; gap: .5rem; background: #fcfcfd; }
        .pjl-stato { display: inline-flex; font-size: .72rem; font-weight: 700; padding: .25rem .65rem; border-radius: 999px; }
        .pjl-pill { border-radius: 999px; font-weight: 600; font-size: .85rem; }
        .pjl-pill.active { background-color: <?php echo $col_primaria; ?> !important; border-color: <?php echo $col_primaria; ?> !important; color: <?php echo $txt_on_l; ?> !important; }
        @media (max-width: 991.98px) { .pjl-card { grid-template-columns: 90px 1fr; } .pjl-azioni { grid-column: 1 / -1; border-left: 0; border-top: 1px solid #f1f5f9; flex-direction: row; flex-wrap: wrap; } .pjl-azioni > * { flex: 1 1 180px; } }
        @media (max-width: 575.98px) { .pjl-card { grid-template-columns: 1fr; } .pjl-data { flex-direction: row; gap: .5rem; padding: .6rem; } .pjl-data .g { font-size: 1.3rem; } }
    </style>
    <div class="ui-larg-cont container mb-5">
        <div class="ui-area-bl5 pj-box-intro card shadow-sm border-0 p-4 mb-4 mt-2" style="border-radius:14px;">
            <h1 class="ui-area-testo-ok fw-bold m-0" style="letter-spacing:-0.5px;"><?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'Progetti'); ?></h1>
            <?php if (!empty($page_cfg['sottotitolo'])): ?><div class="fs-5 fw-semibold text-secondary"><?php echo htmlspecialchars($page_cfg['sottotitolo']); ?></div><?php endif; ?>
            <?php if (!empty($page_cfg['hero_descrizione'])): ?><div class="text-dark mt-3 ui-fs-95 ui-lh-1_6"><?php echo $page_cfg['hero_descrizione']; ?></div><?php endif; ?>
        </div>

        <?php if (!empty($page_cfg['box_info_html']) || !empty($page_cfg['allegati_box_info'])): ?>
            <div class="ui-area-bl5 card shadow-sm border-0 p-4 mb-4" style="border-radius:8px;">
                <?php if (!empty($page_cfg['box_info_html'])): ?><div class="fs-6 text-dark ui-lh-1_7"><?php echo $page_cfg['box_info_html']; ?></div><?php endif; ?>
                <?php if (!empty($page_cfg['allegati_box_info'])): ?>
                    <div class="mt-3 pt-3 border-top d-flex flex-wrap gap-2">
                        <?php foreach (explode(',', $page_cfg['allegati_box_info']) as $all): $all = trim($all); if ($all === '') continue; ?>
                            <a href="<?php echo htmlspecialchars($all); ?>" target="_blank" class="btn btn-outline-danger btn-sm fw-bold"><i class="fa fa-file-pdf me-1"></i> Guida / Allegato (PDF)</a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$lista_pj): ?>
            <div class="alert alert-light text-center border p-4 shadow-sm">Nessun progetto pubblicato al momento.</div>
        <?php else: ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-4 p-3 bg-light border rounded-3">
                <label for="pjlCerca" class="visually-hidden">Cerca progetto</label>
                <input type="search" id="pjlCerca" class="form-control form-control-sm ui-maxw-300" placeholder="Cerca progetto, struttura, referente...">
                <button type="button" class="btn btn-sm btn-outline-secondary pjl-pill active" data-pjl-stato="">Tutti <span>(<?php echo count($lista_pj); ?>)</span></button>
                <?php foreach ($filtri_stato as $cod => $lbl): if (empty($conta_stato[$cod])) continue; ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary pjl-pill" data-pjl-stato="<?php echo $cod; ?>"><?php echo $lbl; ?> <span>(<?php echo $conta_stato[$cod]; ?>)</span></button>
                <?php endforeach; ?>
                <?php if (count($strutture_pj) > 1): ?>
                    <label for="pjlStruttura" class="visually-hidden">Struttura</label>
                    <select id="pjlStruttura" class="form-select form-select-sm ms-lg-auto ui-maxw-320">
                        <option value="">Tutte le strutture</option>
                        <?php foreach ($strutture_pj as $s_pj): ?><option value="<?php echo htmlspecialchars(mb_strtolower($s_pj)); ?>"><?php echo htmlspecialchars($s_pj); ?></option><?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if (count($lista_pj) > 1): ?>
                    <label for="pjlOrdina" class="small fw-semibold text-secondary mb-0 <?php echo count($strutture_pj) > 1 ? '' : 'ms-lg-auto'; ?>">Ordina per</label>
                    <select id="pjlOrdina" class="form-select form-select-sm ui-maxw-220">
                        <option value="">Predefinito</option>
                        <option value="stato">Iscrizioni aperte prima</option>
                        <option value="inizio">Data di inizio</option>
                        <option value="titolo">Titolo (A-Z)</option>
                    </select>
                <?php endif; ?>
            </div>

            <div class="d-flex flex-column gap-3" id="pjlLista">
                <?php foreach ($lista_pj as $pos_l => ['ev' => $ev_l, 'ip' => $ip_l]):
                    $d_l = $ip_l['d']; $st_l = $ip_l['stato'];
                    $dest_l = $ip_l['dest'] ?? null;
                    $url_scheda = $dest_l ? htmlspecialchars($dest_l['url']) : htmlspecialchars($current_filename) . '.php?progetto=' . (int)$ev_l['id'];
                    $target_l = ($dest_l && $dest_l['esterno']) ? ' target="_blank" rel="noopener"' : '';
                    $cerca_l = mb_strtolower($ev_l['titolo'] . ' ' . ($d_l['struttura'] ?? '') . ' ' . ($d_l['destinatari'] ?? '') . ' ' . ($ev_l['luogo'] ?? '') . ' '
                             . implode(' ', array_map(fn($r) => ($r['nome'] ?? ''), $d_l['referenti'] ?? [])) . ' ' . strip_tags($ev_l['descrizione'] ?? ''));
                    $estratto = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $ev_l['descrizione'] ?? '')), ENT_QUOTES, 'UTF-8')));
                ?>
                    <article class="pjl-card<?php echo $st_l['codice'] === 'concluso' ? ' concluso' : ''; ?>" data-cerca="<?php echo htmlspecialchars($cerca_l); ?>" data-stato="<?php echo $st_l['codice']; ?>" data-struttura="<?php echo htmlspecialchars(mb_strtolower(trim((string)($d_l['struttura'] ?? '')))); ?>" data-pos="<?php echo (int)$pos_l; ?>" data-ord-stato="<?php echo (int)$st_l['ordine']; ?>" data-inizio="<?php echo htmlspecialchars($d_l['data_inizio'] ?? ''); ?>" data-titolo="<?php echo htmlspecialchars(mb_strtolower($ev_l['titolo'])); ?>">
                        <div class="pjl-data" aria-hidden="true">
                            <?php if (!empty($d_l['data_inizio'])): $ts_l = strtotime($d_l['data_inizio']); ?>
                                <span class="g"><?php echo date('j', $ts_l); ?></span><span class="m"><?php echo $mesi_pj[(int)date('n', $ts_l)]; ?></span><span class="a"><?php echo date('Y', $ts_l); ?></span>
                            <?php else: ?>
                                <i class="fa fa-calendar-plus fs-3 mb-1"></i><span class="m ui-fs-80">Date da definire</span>
                            <?php endif; ?>
                        </div>
                        <div class="pjl-corpo">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="pjl-stato" style="background: <?php echo $st_l['bg']; ?>; color: <?php echo $st_l['fg']; ?>;"><?php echo htmlspecialchars($st_l['etichetta']); ?></span>
                                <?php if (!empty($d_l['struttura'])): ?><span class="small text-secondary fw-semibold"><i class="fa fa-building-columns me-1" aria-hidden="true"></i><?php echo htmlspecialchars($d_l['struttura']); ?></span><?php endif; ?>
                            </div>
                            <h3><a href="<?php echo $url_scheda; ?>"<?php echo $target_l; ?>><?php echo htmlspecialchars($ev_l['titolo']); ?></a></h3>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="pjl-chip"><i class="fa fa-calendar-days" aria-hidden="true"></i><?php echo htmlspecialchars($ip_l['periodo']); ?></span>
                                <?php if (count($ip_l['edizioni']) > 1): ?><span class="pjl-chip"><i class="fa fa-clone" aria-hidden="true"></i><?php echo count($ip_l['edizioni']); ?> edizioni</span><?php endif; ?>
                                <?php if (!empty($d_l['ore_totali'])): ?><span class="pjl-chip"><i class="fa fa-clock" aria-hidden="true"></i><?php echo (int)$d_l['ore_totali']; ?> ore</span><?php endif; ?>
                                <?php if ($ip_l['scuole'] && $ip_l['limiti'] !== ''): ?><span class="pjl-chip"><i class="fa fa-users" aria-hidden="true"></i><?php echo htmlspecialchars($ip_l['limiti']); ?> studenti</span><?php endif; ?>
                                <?php if (!empty($d_l['destinatari'])): ?><span class="pjl-chip"><i class="fa fa-user-graduate" aria-hidden="true"></i><?php echo htmlspecialchars($d_l['destinatari']); ?></span><?php endif; ?>
                            </div>
                            <?php if ($estratto !== ''): ?><p class="pjl-estratto"><?php echo htmlspecialchars(mb_strimwidth($estratto, 0, 320, '…')); ?></p><?php endif; ?>
                        </div>
                        <div class="pjl-azioni">
                            <?php if ($dest_l): ?>
                                <div class="small fw-semibold ui-c-cielo-testo"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Prenotazioni sulla pagina <?php echo htmlspecialchars($dest_l['nome']); ?></div>
                            <?php elseif ($ip_l['t'] && in_array($st_l['codice'], ['aperte', 'attesa', 'arrivo'], true)):
                                $n_ed_l = count($ip_l['edizioni']);
                                if (!$ip_l['scuole']) $txt_disp = $ip_l['liberi'] > 0 ? testo_posti_liberi((int)$ip_l['liberi']) : 'Posti esauriti' . ($ip_l['attesa'] ? ' · ' . $ip_l['attesa'] . ' in attesa' : '');
                                elseif ($ip_l['liberi'] > 0) $txt_disp = $n_ed_l > 1 ? $ip_l['liberi'] . ' ' . ($ip_l['liberi'] === 1 ? 'edizione disponibile' : 'edizioni disponibili') . ' su ' . $n_ed_l : 'Posto disponibile';
                                else $txt_disp = ($n_ed_l > 1 ? 'Edizioni assegnate' : 'Assegnato') . ($ip_l['attesa'] ? ' · ' . $ip_l['attesa'] . ' in attesa' : '');
                            ?>
                                <div class="small fw-semibold <?php echo $ip_l['liberi'] > 0 ? 'text-success' : 'text-warning'; ?>">
                                    <i class="fa <?php echo $ip_l['liberi'] > 0 ? 'fa-circle-check' : 'fa-lock'; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($txt_disp); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($dest_l): ?>
                                <a href="<?php echo $url_scheda; ?>"<?php echo $target_l; ?> class="btn btn-outline-secondary fw-bold w-100">Vai a <?php echo htmlspecialchars($dest_l['nome']); ?> <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></a>
                            <?php else: ?>
                                <a href="<?php echo $url_scheda; ?>" class="btn btn-outline-secondary fw-bold w-100">Dettagli<span class="visually-hidden"> di <?php echo htmlspecialchars($ev_l['titolo']); ?></span> <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div id="pjlVuoto" class="alert alert-light text-center border p-4 mt-3" style="display: none;">Nessun progetto corrisponde ai filtri.</div>

            <script nonce="<?php echo csp_nonce(); ?>">
            (function () {
                var cerca = document.getElementById('pjlCerca'), strutt = document.getElementById('pjlStruttura');
                var pills = document.querySelectorAll('[data-pjl-stato]'), statoAtt = '';
                function applica() {
                    var q = cerca.value.trim().toLowerCase(), s = strutt ? strutt.value : '', vis = 0;
                    document.querySelectorAll('#pjlLista .pjl-card').forEach(function (c) {
                        var ok = (!q || c.dataset.cerca.indexOf(q) !== -1) && (!statoAtt || c.dataset.stato === statoAtt) && (!s || c.dataset.struttura === s);
                        c.style.display = ok ? '' : 'none';
                        if (ok) vis++;
                    });
                    document.getElementById('pjlVuoto').style.display = vis ? 'none' : '';
                }
                cerca.addEventListener('input', applica);
                if (strutt) strutt.addEventListener('change', applica);
                var ordina = document.getElementById('pjlOrdina'), lista = document.getElementById('pjlLista');
                var inizio = function (c) { return c.dataset.inizio || '9999'; };
                var confronti = {
                    '':     function (a, b) { return a.dataset.pos - b.dataset.pos; },
                    stato:  function (a, b) { return (a.dataset.ordStato - b.dataset.ordStato) || inizio(a).localeCompare(inizio(b)) || (a.dataset.pos - b.dataset.pos); },
                    inizio: function (a, b) { return inizio(a).localeCompare(inizio(b)) || (a.dataset.pos - b.dataset.pos); },
                    titolo: function (a, b) { return a.dataset.titolo.localeCompare(b.dataset.titolo, 'it'); }
                };
                if (ordina) ordina.addEventListener('change', function () {
                    Array.from(lista.querySelectorAll('.pjl-card')).sort(confronti[ordina.value] || confronti['']).forEach(function (c) { lista.appendChild(c); });
                });
                pills.forEach(function (p) {
                    p.addEventListener('click', function () {
                        pills.forEach(function (x) { x.classList.remove('active'); x.setAttribute('aria-pressed', 'false'); });
                        p.classList.add('active'); p.setAttribute('aria-pressed', 'true');
                        statoAtt = p.dataset.pjlStato; applica();
                    });
                });
            })();
            </script>
        <?php endif; ?>

        <?php if ($altri_eventi): ?>
            <h2 class="ui-area-testo-ok fw-bold fs-4 border-bottom pb-2 mt-5 mb-3">Altre attività</h2>
            <div class="row g-4">
                <?php foreach ($altri_eventi as $ev): ?>
                    <div class="<?php echo $col_class; ?>"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
