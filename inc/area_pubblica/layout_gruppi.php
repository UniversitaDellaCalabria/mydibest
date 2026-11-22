<?php
// Layout «gruppi / corsi»: discipline, gruppi e turni, con le regole di iscrizione dell'area.
// Caricato da master_template.php (stesso ambito della pagina) quando serve questo disegno.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $col_primaria, $page_cfg, $limite_iscrizioni, $eventi_per_categoria, $mie_iscrizioni, $conn, $col_class, $current_filename, $nomi_ruoli
//   $utente_logged, $utente_ruolo_id
?>
    <style>
        .gruppo-card { border-top: 4px solid <?php echo $col_primaria; ?> !important; border-radius: 12px; transition: box-shadow .2s ease; }
        .gruppo-card:hover { box-shadow: 0 8px 22px rgba(0,0,0,.09) !important; }
        .gruppo-card.gruppo-iscritto { outline: 2px solid #198754; }
        .gruppo-desc { font-size: .9rem; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
        .gruppo-desc.aperta { -webkit-line-clamp: unset; display: block; }
        .gruppi-pill { border-radius: 999px; font-weight: 600; font-size: .85rem; }
        .gruppi-pill.active { background-color: <?php echo $col_primaria; ?> !important; border-color: <?php echo $col_primaria; ?> !important; color: #fff !important; }
    </style>
    <div class="ui-larg-cont container mb-5">

        <div class="ui-area-bl5 card shadow-sm border-0 p-4 mb-4" style="border-radius:12px;">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <h1 class="ui-area-testo fw-bold m-0" style="letter-spacing:-0.5px;"><?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'GRUPPI'); ?></h1>
                    <?php if (!empty($page_cfg['sottotitolo'])): ?><div class="fs-5 fw-semibold text-secondary"><?php echo htmlspecialchars($page_cfg['sottotitolo']); ?></div><?php endif; ?>
                </div>
                <?php if (!empty($page_cfg['sidebar_intervallo_date'])): ?>
                    <span class="badge bg-warning text-dark fw-bold px-3 py-2 fs-6 rounded-pill">📝 <?php echo htmlspecialchars($page_cfg['sidebar_intervallo_date']); ?></span>
                <?php endif; ?>
            </div>
            <?php if (!empty($page_cfg['hero_descrizione'])): ?>
                <div class="text-dark mt-3 ui-fs-95 ui-lh-1_6"><?php echo $page_cfg['hero_descrizione']; ?></div>
            <?php endif; ?>
            <?php if ($limite_iscrizioni !== 'nessuno'): ?>
                <div class="alert alert-info border-0 mb-0 mt-3 py-2 small fw-semibold">
                    <i class="fa fa-circle-info me-1"></i>
                    <?php echo $limite_iscrizioni === 'un_evento' ? "Puoi iscriverti a <strong>un solo gruppo</strong> di quest'area." : "Per ogni gruppo puoi scegliere <strong>un solo turno</strong>."; ?>
                    Per cambiare, annulla prima la prenotazione dall'<a href="area_personale.php" class="alert-link">Area Personale</a>.
                    Puoi invece metterti in <strong>lista d'attesa</strong> su più <?php echo $limite_iscrizioni === 'un_evento' ? 'gruppi' : 'turni'; ?>: appena ottieni un posto confermato, le altre liste d'attesa vengono annullate automaticamente.
                </div>
            <?php endif; ?>
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

        <?php if (empty($eventi_per_categoria)): ?>
            <div class="alert alert-light text-center border p-4 shadow-sm">Nessun gruppo disponibile al momento.</div>
        <?php else: ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-4 p-3 bg-light border rounded-3">
                <input type="search" id="gruppiCerca" class="form-control form-control-sm ui-maxw-280" placeholder="Cerca gruppo, luogo, istruttore..." aria-label="Cerca gruppo">
                <?php if (count($eventi_per_categoria) > 1): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary gruppi-pill active" data-gruppi-cat="__all">Tutte</button>
                    <?php foreach (array_keys($eventi_per_categoria) as $cat_nome): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary gruppi-pill" data-gruppi-cat="<?php echo htmlspecialchars($cat_nome); ?>"><?php echo htmlspecialchars($cat_nome); ?></button>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="form-check form-switch ms-lg-auto mb-0">
                    <input class="form-check-input" type="checkbox" id="gruppiSoloLiberi">
                    <label class="form-check-label small fw-bold" for="gruppiSoloLiberi">Solo con posti liberi</label>
                </div>
            </div>

            <?php foreach ($eventi_per_categoria as $cat_nome => $lista_gruppi): ?>
                <section class="gruppi-sezione mb-5" data-cat="<?php echo htmlspecialchars($cat_nome); ?>">
                    <h2 class="ui-area-testo fw-bold fs-4 border-bottom pb-2 mb-3">
                        <i class="fa fa-people-group me-2"></i><?php echo htmlspecialchars($cat_nome); ?>
                        <span class="badge bg-light text-secondary border ms-2 align-middle ui-fs-80"><?php echo count($lista_gruppi); ?> <?php echo count($lista_gruppi) === 1 ? 'gruppo' : 'gruppi'; ?></span>
                    </h2>
                    <div class="row g-4">
                        <?php foreach ($lista_gruppi as $ev):
                            $ev_id = (int)$ev['id'];
                            // Solo le iscrizioni "vere" bloccano: le liste d'attesa no (decadono alla prima conferma)
                            $miei_turni_ev = $mie_iscrizioni[$ev_id] ?? [];
                            $iscritto_ev = (bool)array_diff($miei_turni_ev, ['in_attesa', 'richiesta_conferma']);
                            $iscritto_altrove = false;
                            foreach ($mie_iscrizioni as $ev_x => $turni_x) {
                                if ($ev_x !== $ev_id && array_diff($turni_x, ['in_attesa', 'richiesta_conferma'])) { $iscritto_altrove = true; break; }
                            }
                            $bloccato_area = ($limite_iscrizioni === 'un_evento' && $iscritto_altrove && !$iscritto_ev);
                            $bloccato_turni = ($limite_iscrizioni === 'un_turno' && $iscritto_ev);
                            $ruolo_ev = (int)($ev['ruolo_accesso_id'] ?? 0);

                            $righe_turni = []; $liberi_tot = 0;
                            foreach ($ev['turni'] as $t) {
                                $occ_g = getPostiOccupati($conn, $t['id']);
                                $max_g = max(0, (int)$t['max_posti']);
                                $disp_g = max(0, $max_g - $occ_g);
                                if (!turno_concluso($t)) $liberi_tot += $disp_g;
                                $righe_turni[] = ['t' => $t, 'occ' => $occ_g, 'max' => $max_g, 'disp' => $disp_g];
                            }
                            $search_txt = mb_strtolower($ev['titolo'] . ' ' . ($ev['luogo'] ?? '') . ' ' . strip_tags($ev['descrizione'] ?? '') . ' ' . $cat_nome);
                        ?>
                            <div class="<?php echo $col_class; ?> gruppo-item" data-search="<?php echo htmlspecialchars($search_txt); ?>" data-liberi="<?php echo (int)($ev['richiede_prenotazione'] ?? 1) === 0 ? 1 : $liberi_tot; ?>">
                                <div class="card h-100 border-0 shadow-sm gruppo-card <?php echo $iscritto_ev ? 'gruppo-iscritto' : ''; ?>">
                                    <?php if (!empty($ev['locandina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $ev['locandina_path'])): ?>
                                        <img src="<?php echo htmlspecialchars($ev['locandina_path']); ?>" class="card-img-top ui-h-160 ui-of-cover" alt="" style="border-radius: 8px 8px 0 0;">
                                    <?php endif; ?>
                                    <div class="card-body p-4 d-flex flex-column">
                                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                            <h3 class="fw-bold fs-5 m-0"><a href="<?php echo htmlspecialchars($current_filename); ?>.php?evento=<?php echo (int)$ev['id']; ?>" class="ui-area-testo text-decoration-none"><?php echo htmlspecialchars($ev['titolo']); ?></a></h3>
                                            <div class="d-flex flex-column align-items-end gap-1">
                                                <?php if ($iscritto_ev): ?><span class="badge bg-success"><i class="fa fa-check me-1"></i>Iscritto</span><?php endif; ?>
                                                <?php if ($ruolo_ev === -1): ?><span class="badge bg-warning text-dark">🔑 Solo Autenticati</span>
                                                <?php elseif ($ruolo_ev > 0): ?><span class="badge bg-dark">🔒 Solo <?php echo htmlspecialchars($nomi_ruoli[$ruolo_ev] ?? ''); ?></span><?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if (!empty($ev['luogo'])): ?>
                                            <div class="small fw-semibold text-dark mb-2"><i class="fa fa-location-dot text-danger me-1"></i><?php echo htmlspecialchars($ev['luogo']); ?></div>
                                        <?php endif; ?>

                                        <?php $testo_g = testo_card_evento($ev); if ($testo_g !== ''): ?>
                                            <p class="text-secondary mb-3 ui-fs-90 ui-lh-1_5"><?php echo $testo_g; ?></p>
                                        <?php endif; ?>

                                        <div class="mt-auto">
                                            <?php if (empty($righe_turni)): ?>
                                                <div class="small text-muted fst-italic">Orari in definizione.</div>
                                            <?php endif; ?>
                                            <?php foreach ($righe_turni as $rt):
                                                $t = $rt['t'];
                                                $pct = $rt['max'] > 0 ? min(100, round($rt['occ'] / $rt['max'] * 100)) : 100;
                                                $bar = $pct >= 100 ? 'bg-danger' : ($pct >= 75 ? 'bg-warning' : 'bg-success');
                                                $tf = $t + ['richiede_prenotazione' => $ev['richiede_prenotazione'], 'ruolo_accesso_id' => $ev['ruolo_accesso_id']];

                                                $mio_stato_t = $miei_turni_ev[(int)$t['id']] ?? null;
                                                if ($mio_stato_t === 'in_attesa' || $mio_stato_t === 'richiesta_conferma') {
                                                    $btn = '<span class="badge bg-warning text-dark py-2 px-3"><i class="fa fa-hourglass-half me-1"></i>' . ($mio_stato_t === 'in_attesa' ? "In lista d'attesa" : 'Posto da confermare') . '</span>';
                                                } elseif ($mio_stato_t !== null) {
                                                    $btn = '<span class="badge bg-success py-2 px-3"><i class="fa fa-check me-1"></i>Sei iscritto</span>';
                                                } else {
                                                    $btn = getPulsanteAzione($tf, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli);
                                                    if (strpos($btn, 'modPrenota') !== false && ($bloccato_area || $bloccato_turni)) {
                                                        $motivo = $bloccato_area ? 'Hai già un gruppo' : 'Già iscritto a un turno';
                                                        $btn = '<button class="btn btn-outline-secondary btn-sm fw-bold py-1 px-3" disabled><i class="fa fa-user-lock me-1"></i>' . $motivo . '</button>';
                                                    }
                                                }
                                            ?>
                                                <div class="border rounded-3 p-2 px-3 mb-2 bg-white">
                                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                        <div class="small">
                                                            <?php if (!empty($t['nome_turno'])): ?><strong class="text-dark ui-fs-95"><?php echo htmlspecialchars($t['nome_turno']); ?></strong><?php endif; ?>
                                                            <?php if (!empty($t['data_turno'])): ?><?php echo !empty($t['nome_turno']) ? '<span class="text-muted">·</span> ' : ''; ?><strong><?php echo formattaDataItaliano($t['data_turno']); ?></strong><?php endif; ?>
                                                            <?php if (orario_turno($t) !== ''): ?><span class="text-muted">· <?php echo orario_turno($t); ?></span><?php endif; ?>
                                                        </div>
                                                        <div><?php echo $btn; ?></div>
                                                    </div>
                                                    <?php if ((int)($ev['richiede_prenotazione'] ?? 1) === 1): ?>
                                                        <div class="progress mt-2 ui-h-6" role="progressbar" aria-label="Posti occupati" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100">
                                                            <div class="progress-bar <?php echo $bar; ?>" style="width: <?php echo $pct; ?>%;"></div>
                                                        </div>
                                                        <div class="d-flex justify-content-between mt-1 ui-fs-80">
                                                            <span class="text-muted"><?php echo $rt['occ']; ?>/<?php echo $rt['max']; ?> iscritti</span>
                                                            <span class="fw-bold <?php echo $rt['disp'] > 0 ? 'text-success' : 'text-danger'; ?>"><?php echo $rt['disp'] > 0 ? testo_posti_liberi((int)$rt['disp']) : 'Completo'; ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            <div id="gruppiVuoto" class="alert alert-light text-center border p-4" style="display: none;">Nessun gruppo corrisponde ai filtri.</div>
        <?php endif; ?>
    </div>
    <script nonce="<?php echo csp_nonce(); ?>">
    (function () {
        var cerca = document.getElementById('gruppiCerca');
        if (!cerca) return;
        var soloLiberi = document.getElementById('gruppiSoloLiberi');
        var pills = document.querySelectorAll('[data-gruppi-cat]');
        var catAttiva = '__all';

        function applica() {
            var term = cerca.value.trim().toLowerCase();
            var totVisibili = 0;
            document.querySelectorAll('.gruppi-sezione').forEach(function (sec) {
                var catOk = (catAttiva === '__all' || sec.dataset.cat === catAttiva);
                var vis = 0;
                sec.querySelectorAll('.gruppo-item').forEach(function (it) {
                    var ok = catOk && (!term || it.dataset.search.indexOf(term) !== -1) && (!soloLiberi.checked || parseInt(it.dataset.liberi, 10) > 0);
                    it.style.display = ok ? '' : 'none';
                    if (ok) vis++;
                });
                sec.style.display = vis ? '' : 'none';
                totVisibili += vis;
            });
            document.getElementById('gruppiVuoto').style.display = totVisibili ? 'none' : '';
        }

        cerca.addEventListener('input', applica);
        soloLiberi.addEventListener('change', applica);
        pills.forEach(function (p) {
            p.addEventListener('click', function () {
                pills.forEach(function (x) { x.classList.remove('active'); });
                p.classList.add('active');
                catAttiva = p.dataset.gruppiCat;
                applica();
            });
        });
        document.querySelectorAll('.gruppo-desc-toggle').forEach(function (btn) {
            var desc = btn.previousElementSibling;
            if (desc.scrollHeight <= desc.clientHeight + 2) { btn.remove(); return; }
            btn.addEventListener('click', function () {
                var aperta = desc.classList.toggle('aperta');
                btn.textContent = aperta ? 'Mostra meno' : 'Mostra dettagli';
            });
        });
    })();
    </script>
