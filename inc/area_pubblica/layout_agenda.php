<?php
// Layout «agenda»: una scheda per ogni giorno (più «Senza data»), turni in ordine di orario.
// Caricato da master_template.php (stesso ambito della pagina) quando serve questo disegno.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $all_turni_flat, $col_primaria, $col_testo_area, $page_cfg, $titolo_giorno, $conn, $mie_iscrizioni, $current_filename, $utente_logged
//   $utente_ruolo_id, $nomi_ruoli
?>
    <?php
    // AGENDA A SCHEDE PER GIORNO: una scheda per ogni giorno (+ "Senza data"), turni in ordine di orario
    $agenda = [];
    foreach ($all_turni_flat as $t_ag) {
        $agenda[!empty($t_ag['data_turno']) ? $t_ag['data_turno'] : GIORNO_SENZA_DATA][] = $t_ag;
    }
    ksort($agenda);
    // Giorno aperto all'inizio: oggi se c'è, altrimenti il primo con turni non conclusi, altrimenti il primo
    $giorno_attivo = isset($agenda[date('Y-m-d')]) ? date('Y-m-d') : null;
    if ($giorno_attivo === null) {
        foreach ($agenda as $g => $lista_g) {
            foreach ($lista_g as $t_g) { if (!turno_concluso($t_g)) { $giorno_attivo = $g; break 2; } }
        }
    }
    if ($giorno_attivo === null && $agenda) $giorno_attivo = array_key_first($agenda);
    $giorni_brevi = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
    $mesi_ag      = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
    $txt_on_area  = colore_testo_su($col_primaria);
    ?>
    <style>
        .ag-giorni { display: flex; gap: .5rem; overflow-x: auto; padding-bottom: .5rem; scrollbar-width: thin; }
        .ag-giorno { flex: 0 0 auto; min-width: 84px; border: 2px solid #e2e8f0; background: #fff; border-radius: 12px; padding: .5rem .75rem; text-align: center; line-height: 1.15; cursor: pointer; }
        .ag-giorno .ag-gs { font-size: .72rem; text-transform: uppercase; font-weight: 700; color: #64748b; }
        .ag-giorno .ag-gn { font-size: 1.5rem; font-weight: 800; color: #1f2937; }
        .ag-giorno .ag-gm { font-size: .72rem; font-weight: 600; color: #64748b; }
        .ag-giorno.attivo { background: <?php echo $col_primaria; ?>; border-color: <?php echo $col_primaria; ?>; }
        .ag-giorno.attivo * { color: <?php echo $txt_on_area; ?> !important; }
        .ag-riga { display: grid; grid-template-columns: 92px 1fr auto; gap: 1rem; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid #eef2f7; }
        .ag-riga:last-child { border-bottom: 0; }
        .ag-riga.conclusa { opacity: .55; }
        .ag-ora { font-size: 1.35rem; font-weight: 800; color: <?php echo $col_testo_area; ?>; line-height: 1; }
        .ag-ora small { display: block; font-size: .78rem; font-weight: 600; color: #64748b; margin-top: .25rem; }
        @media (max-width: 575.98px) {
            .ag-riga { grid-template-columns: 64px 1fr; }
            .ag-riga .ag-azione { grid-column: 1 / -1; }
            .ag-ora { font-size: 1.1rem; }
        }
    </style>
    <div class="ui-larg-cont container mb-5">
        <div class="text-center mb-4 mt-4">
            <h1 class="ui-area-testo-ok fw-black display-5 m-0 my-2" style="font-weight:900; letter-spacing:-1px;">
                <?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'AGENDA'); ?>
            </h1>
            <?php if (!empty($page_cfg['hero_descrizione'])): ?>
                <div class="text-secondary fs-5 mx-auto ui-maxw-760"><?php echo $page_cfg['hero_descrizione']; ?></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($page_cfg['box_info_html'])): ?>
            <div class="ui-area-bl5 card shadow-sm border-0 p-4 mb-4" style="border-radius:8px;">
                <div class="fs-6 text-dark ui-lh-1_7"><?php echo $page_cfg['box_info_html']; ?></div>
            </div>
        <?php endif; ?>

        <?php if (!$agenda): ?>
            <div class="alert alert-light text-center border p-4 shadow-sm">Nessuna attività in programma.</div>
        <?php else: ?>
            <!-- Selettore giorni -->
            <div class="ag-giorni mb-3" role="tablist" aria-label="Giorni del programma">
                <?php foreach ($agenda as $g => $lista_g):
                    $attivo = ($g === $giorno_attivo);
                    $id_g = 'ag-' . preg_replace('/[^0-9]/', '', $g);
                ?>
                    <button type="button" class="ag-giorno<?php echo $attivo ? ' attivo' : ''; ?>" role="tab" id="tab-<?php echo $id_g; ?>"
                            aria-selected="<?php echo $attivo ? 'true' : 'false'; ?>" aria-controls="<?php echo $id_g; ?>" tabindex="<?php echo $attivo ? '0' : '-1'; ?>">
                        <?php if ($g === GIORNO_SENZA_DATA): ?>
                            <div class="ag-gs">Senza</div><div class="ag-gn"><i class="fa fa-infinity" aria-hidden="true"></i></div><div class="ag-gm">data fissa</div>
                        <?php else: $ts_g = strtotime($g); ?>
                            <div class="ag-gs"><?php echo $g === date('Y-m-d') ? 'Oggi' : $giorni_brevi[(int)date('w', $ts_g)]; ?></div>
                            <div class="ag-gn"><?php echo date('j', $ts_g); ?></div>
                            <div class="ag-gm"><?php echo $mesi_ag[(int)date('n', $ts_g)] . (date('Y', $ts_g) !== date('Y') ? ' ' . date('Y', $ts_g) : ''); ?></div>
                        <?php endif; ?>
                        <span class="visually-hidden"><?php echo count($lista_g); ?> attività</span>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Filtri -->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <label for="agCerca" class="visually-hidden">Cerca attività</label>
                <input type="search" id="agCerca" class="form-control form-control-sm ui-maxw-280" placeholder="Cerca attività, luogo...">
                <div class="form-check form-switch mb-0 ms-md-auto">
                    <input class="form-check-input" type="checkbox" id="agSoloLiberi">
                    <label class="form-check-label small fw-bold" for="agSoloLiberi">Solo con posti liberi</label>
                </div>
            </div>

            <!-- Pannelli dei giorni -->
            <?php foreach ($agenda as $g => $lista_g):
                $id_g = 'ag-' . preg_replace('/[^0-9]/', '', $g);
                usort($lista_g, fn($a, $b) => strcmp(($a['orario_inizio'] ?? '99') . $a['evento_titolo'], ($b['orario_inizio'] ?? '99') . $b['evento_titolo']));
            ?>
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden" role="tabpanel" id="<?php echo $id_g; ?>" aria-labelledby="tab-<?php echo $id_g; ?>" <?php echo $g === $giorno_attivo ? '' : 'hidden'; ?>>
                    <div class="px-4 py-3 border-bottom bg-light">
                        <h2 class="fw-bold fs-5 m-0"><?php echo $titolo_giorno($g); ?></h2>
                    </div>
                    <?php foreach ($lista_g as $t_ag):
                        $conclusa  = turno_concluso($t_ag);
                        $req_pren  = (int)($t_ag['richiede_prenotazione'] ?? 1) === 1;
                        $occ_ag    = $req_pren ? getPostiOccupati($conn, $t_ag['id']) : 0;
                        $max_ag    = max(0, (int)$t_ag['max_posti']);
                        $lib_ag    = max(0, $max_ag - $occ_ag);
                        $illimitato = $max_ag >= POSTI_SENZA_LIMITE;
                        $pct_ag    = ($max_ag > 0 && !$illimitato) ? min(100, (int)round($occ_ag / $max_ag * 100)) : 0;
                        $mio_stato = $mie_iscrizioni[(int)$t_ag['evento_id']][(int)$t_ag['id']] ?? null;
                        $cerca_ag  = mb_strtolower($t_ag['evento_titolo'] . ' ' . ($t_ag['nome_turno'] ?? '') . ' ' . ($t_ag['evento_luogo'] ?? '') . ' ' . $t_ag['categoria']);
                    ?>
                        <div class="ag-riga<?php echo $conclusa ? ' conclusa' : ''; ?>" data-cerca="<?php echo htmlspecialchars($cerca_ag); ?>" data-liberi="<?php echo (!$req_pren || $illimitato) ? 1 : $lib_ag; ?>">
                            <div class="ag-ora">
                                <?php if (!empty($t_ag['orario_inizio'])): ?>
                                    <?php echo substr($t_ag['orario_inizio'], 0, 5); ?>
                                    <?php if (!empty($t_ag['orario_fine'])): ?><small>fino alle <?php echo substr($t_ag['orario_fine'], 0, 5); ?></small><?php endif; ?>
                                <?php else: ?>
                                    <i class="fa fa-clock" aria-hidden="true"></i><small>orario da definire</small>
                                <?php endif; ?>
                            </div>
                            <div class="ui-minw-0">
                                <div class="d-flex flex-wrap gap-1 mb-1">
                                    <span class="badge ui-bg-neutro-sfondo ui-c-testo-forte ui-fs-80"><?php echo htmlspecialchars($t_ag['categoria']); ?></span>
                                    <?php if ($mio_stato === 'in_attesa' || $mio_stato === 'richiesta_conferma'): ?>
                                        <span class="badge bg-warning text-dark ui-fs-80"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>In lista d'attesa</span>
                                    <?php elseif ($mio_stato !== null): ?>
                                        <span class="badge bg-success ui-fs-80"><i class="fa fa-check me-1" aria-hidden="true"></i>Sei iscritto</span>
                                    <?php endif; ?>
                                </div>
                                <h3 class="fw-bold fs-6 m-0"><a href="<?php echo htmlspecialchars($current_filename); ?>.php?evento=<?php echo (int)$t_ag['evento_id']; ?>" class="text-dark"><?php echo htmlspecialchars($t_ag['evento_titolo']); ?></a><?php if (!empty($t_ag['nome_turno'])): ?> <span class="fw-semibold text-secondary">· <?php echo htmlspecialchars($t_ag['nome_turno']); ?></span><?php endif; ?></h3>
                                <?php if (!empty($t_ag['evento_luogo'])): ?>
                                    <div class="small text-secondary mt-1"><i class="fa fa-map-marker-alt me-1" aria-hidden="true"></i><?php echo htmlspecialchars($t_ag['evento_luogo']); ?></div>
                                <?php endif; ?>
                                <?php if ($req_pren && !$illimitato && $max_ag > 0 && !$conclusa): ?>
                                    <div class="d-flex align-items-center gap-2 mt-2 ui-maxw-320">
                                        <div class="progress flex-grow-1 ui-h-6" role="progressbar" aria-label="Posti occupati: <?php echo $pct_ag; ?>%" aria-valuenow="<?php echo $pct_ag; ?>" aria-valuemin="0" aria-valuemax="100">
                                            <div class="progress-bar <?php echo $pct_ag >= 100 ? 'bg-danger' : ($pct_ag >= 75 ? 'bg-warning' : 'bg-success'); ?>" style="width: <?php echo $pct_ag; ?>%;"></div>
                                        </div>
                                        <span class="small fw-bold text-nowrap <?php echo $lib_ag > 0 ? 'text-success' : 'text-danger'; ?>"><?php echo $lib_ag > 0 ? $lib_ag . ($lib_ag === 1 ? ' posto' : ' posti') : 'Completo'; ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="ag-azione text-end">
                                <?php if ($mio_stato !== null && !in_array($mio_stato, ['in_attesa', 'richiesta_conferma'], true)): ?>
                                    <a href="area_personale.php" class="btn btn-sm btn-outline-success fw-bold">La mia prenotazione</a>
                                <?php else: ?>
                                    <?php echo getPulsanteAzione($t_ag, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="ag-vuoto p-4 text-center text-muted d-none">Nessuna attività corrisponde ai filtri in questo giorno.</div>
                </div>
            <?php endforeach; ?>

            <script nonce="<?php echo csp_nonce(); ?>">
            document.addEventListener('DOMContentLoaded', function () {
                var tabs = Array.prototype.slice.call(document.querySelectorAll('.ag-giorno'));
                function apri(tab) {
                    tabs.forEach(function (t) {
                        var sel = t === tab;
                        t.classList.toggle('attivo', sel);
                        t.setAttribute('aria-selected', sel ? 'true' : 'false');
                        t.tabIndex = sel ? 0 : -1;
                        document.getElementById(t.getAttribute('aria-controls')).hidden = !sel;
                    });
                    filtra();
                }
                tabs.forEach(function (t, i) {
                    t.addEventListener('click', function () { apri(t); });
                    // Tastiera: frecce sinistra/destra tra i giorni (pattern ARIA delle schede)
                    t.addEventListener('keydown', function (e) {
                        var j = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : null;
                        if (j === null || !tabs[j]) return;
                        e.preventDefault(); tabs[j].focus(); apri(tabs[j]);
                    });
                });
                var attiva = document.querySelector('.ag-giorno.attivo');
                if (attiva) attiva.scrollIntoView({ block: 'nearest', inline: 'center' });

                var cerca = document.getElementById('agCerca'), liberi = document.getElementById('agSoloLiberi');
                function filtra() {
                    var q = cerca.value.trim().toLowerCase(), soloLiberi = liberi.checked;
                    document.querySelectorAll('[role="tabpanel"]').forEach(function (p) {
                        var visibili = 0;
                        p.querySelectorAll('.ag-riga').forEach(function (r) {
                            var ok = (!q || r.dataset.cerca.indexOf(q) !== -1) && (!soloLiberi || parseInt(r.dataset.liberi, 10) > 0);
                            r.classList.toggle('d-none', !ok);
                            if (ok) visibili++;
                        });
                        p.querySelector('.ag-vuoto').classList.toggle('d-none', visibili > 0);
                    });
                }
                cerca.addEventListener('input', filtra);
                liberi.addEventListener('change', filtra);
            });
            </script>
        <?php endif; ?>
    </div>
