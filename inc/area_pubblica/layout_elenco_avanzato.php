<?php
// Layout «elenco avanzato»: testata dell'area, ricerca e filtri, turni in elenco con prenotazione.
// Caricato da master_template.php (stesso ambito della pagina) quando serve questo disegno.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $page_cfg, $all_turni_flat, $categorie_nomi, $conn, $current_filename, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli
?>
    <div class="ui-larg-cont container mb-5">
        
        <!-- HERO / TESTATA EVENTO -->
        <div class="ui-area-bb5 card shadow-sm border-0 mb-4 p-4 text-center" style="background-color:#fdfbfb; border-radius:12px;">
            <h1 class="ui-area-testo fw-black display-5 m-0 mb-2" style="font-weight:900; letter-spacing:-1px;">
                <i class="fa fa-calendar-check me-2"></i> <?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'EVENTI'); ?>
            </h1>
            <div class="row justify-content-center border-top border-bottom py-3 my-3">
                <div class="col-md-3 border-end">
                    <span class="text-muted small fw-bold text-uppercase d-block">Da</span>
<?php $date_con_valore = array_values(array_filter(array_column($all_turni_flat, 'data_turno'))); ?>
                    <strong class="fs-5"><?php echo $date_con_valore ? date('d/m/Y', strtotime(min($date_con_valore))) : '-'; ?></strong>
                </div>
                <div class="col-md-3 border-end">
                    <span class="text-muted small fw-bold text-uppercase d-block">A</span>
                    <strong class="fs-5"><?php echo $date_con_valore ? date('d/m/Y', strtotime(max($date_con_valore))) : '-'; ?></strong>
                </div>
                <div class="col-md-3">
                    <span class="text-muted small fw-bold text-uppercase d-block">Accesso</span>
                    <strong class="fs-5 text-success">Prenotazione Libera</strong>
                </div>
            </div>
            <p class="text-secondary mb-0 mx-auto ui-maxw-800 ui-lh-1_6">
                <?php echo !empty($page_cfg['hero_descrizione']) ? $page_cfg['hero_descrizione'] : 'Scopri il programma completo ed iscriviti alle attività di tuo interesse.'; ?>
            </p>
        </div>

        <div class="row g-4 align-items-start">
            <!-- SIDEBAR RICERCA E DOCUMENTI (LEFT) -->
            <div class="col-lg-3">
                <div class="card shadow-sm border-0 rounded-3 sidebar-sticky-fix">
                    <div class="card-header ui-testata-scura fw-bold py-3">
                        <i class="fa fa-search me-1"></i> Ricerca
                    </div>
                    <div class="card-body p-4 bg-white">
                        <div class="mb-3">
                            <label for="advSearchText" class="form-label small fw-bold text-secondary"><i class="fa fa-font me-1"></i> Ricerca testo</label>
                            <input type="text" id="advSearchText" class="form-control form-control-sm" placeholder="Cerca...">
                        </div>
                        <div class="mb-3">
                            <label for="advSearchCat" class="form-label small fw-bold text-secondary"><i class="fa fa-folder-open me-1"></i> Sezione / Categoria</label>
                            <select id="advSearchCat" class="form-select form-select-sm">
                                <option value="">Tutte le sezioni</option>
                                <?php foreach(array_unique($categorie_nomi) as $cat_n): ?>
                                    <?php if(!empty($cat_n)): ?><option value="<?php echo htmlspecialchars(strtolower($cat_n)); ?>"><?php echo htmlspecialchars($cat_n); ?></option><?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="advSearchDateFrom" class="form-label small fw-bold text-secondary"><i class="fa fa-calendar-day me-1"></i> Da data</label>
                            <input type="date" id="advSearchDateFrom" class="form-control form-control-sm">
                        </div>
                        <div class="mb-3">
                            <label for="advSearchDateTo" class="form-label small fw-bold text-secondary"><i class="fa fa-calendar-day me-1"></i> A data</label>
                            <input type="date" id="advSearchDateTo" class="form-control form-control-sm">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="advSearchSenzaData" checked>
                            <label class="form-check-label small fw-bold text-secondary" for="advSearchSenzaData">Includi attività senza data</label>
                        </div>
                        <div class="mb-4">
                            <label for="advSearchSoldout" class="form-label small fw-bold text-secondary"><i class="fa fa-users-slash me-1"></i> Mostra Soldout</label>
                            <select id="advSearchSoldout" class="form-select form-select-sm">
                                <option value="1">Includi soldout (Tutti)</option>
                                <option value="0">Nascondi soldout (Solo posti liberi)</option>
                            </select>
                        </div>
                        
                        <!-- DOCUMENTI SIDEBAR SPOSTATI IN BASSO -->
                        <?php if (!empty($page_cfg['allegati_sidebar'])): ?>
                            <div class="d-flex flex-column gap-2 mt-3 pt-3 border-top">
                                <?php 
                                    $allegati_sb = explode(',', $page_cfg['allegati_sidebar']);
                                    foreach($allegati_sb as $asb): 
                                        $asb = trim($asb);
                                        if(empty($asb)) continue;
                                ?>
                                    <a href="<?php echo htmlspecialchars($asb); ?>" target="_blank" class="btn btn-outline-danger fw-bold shadow-sm py-2">
                                        <i class="fa fa-file-pdf me-1"></i> Scarica Guida
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RISULTATI LISTA (RIGHT) -->
            <div class="col-lg-9">
                <div id="advEventsContainer" class="d-flex flex-column gap-3">
                    <?php if (empty($all_turni_flat)): ?>
                        <div class="alert alert-light text-center border p-5 shadow-sm">Nessun evento in programma.</div>
                    <?php else: ?>
                        <?php foreach($all_turni_flat as $t_flat): ?>
                            <?php 
                                $occ = getPostiOccupati($conn, $t_flat['id']);
                                $disponibili = max(0, $t_flat['max_posti'] - $occ);
                                $is_soldout_flag = ($disponibili <= 0) ? '1' : '0';
                                
                                $data_text = strtolower($t_flat['evento_titolo'] . ' ' . $t_flat['evento_luogo'] . ' ' . $t_flat['categoria'] . ' ' . ($t_flat['nome_turno'] ?? ''));
                                $data_cat  = strtolower($t_flat['categoria']);
                                $data_date = (string)($t_flat['data_turno'] ?? ''); // vuoto = turno senza data
                            ?>
                            <div class="card shadow-sm border-0 adv-event-item ui-r-12 ui-ov-hidden" data-text="<?php echo htmlspecialchars($data_text); ?>" data-cat="<?php echo htmlspecialchars($data_cat); ?>" data-date="<?php echo $data_date; ?>" data-soldout="<?php echo $is_soldout_flag; ?>">
                                <div class="row g-0">
                                    <?php if (!empty($t_flat['evento_locandina']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $t_flat['evento_locandina'])): ?>
                                    <div class="col-md-2 position-relative ui-minh-150">
                                        <img class="ui-pos-absolute ui-top-0 ui-left-0 ui-w-100p ui-h-100p ui-of-cover ui-op-center" src="<?php echo htmlspecialchars($t_flat['evento_locandina']); ?>" alt="Locandina">
                                    </div>
                                    <?php else: ?>
                                    <div class="ui-area-fondo col-md-2 d-flex flex-column align-items-center justify-content-center text-white" style="min-height:150px;">
                                        <i class="fa fa-calendar-star fs-2 opacity-75 mb-1"></i>
                                        <span class="small fw-bold text-uppercase text-center px-2 lh-sm ui-ls-5 ui-fs-80"><?php echo htmlspecialchars($t_flat['categoria']); ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <div class="col-md-10 p-4 d-flex flex-column justify-content-between bg-white">
                                        <div>
                                            <div class="small fw-bold mb-1 ui-c-testo-secondario"><i class="fa fa-folder-open me-1"></i> <?php echo htmlspecialchars($t_flat['categoria']); ?></div>
                                            <h2 class="fw-bold fs-4 mb-2"><a href="<?php echo htmlspecialchars($current_filename); ?>.php?evento=<?php echo (int)$t_flat['evento_id']; ?>" class="ui-area-testo text-decoration-none"><?php echo htmlspecialchars($t_flat['evento_titolo']); ?></a></h2>
                                            
                                            <!-- --- AGGIORNAMENTO UI: BOX LUOGO INGRANDITO (LISTA AVANZATA) --- -->
                                            <?php if(!empty($t_flat['evento_luogo'])): ?>
                                            <div class="bg-light p-2 rounded mb-3 text-dark border-start border-3 border-danger shadow-sm ui-fs-100">
                                                <i class="fa fa-map-marker-alt text-danger me-1"></i> <strong class="text-primary">Luogo:</strong> <?php echo htmlspecialchars($t_flat['evento_luogo']); ?>
                                            </div>
                                            <?php endif; ?>

                                            <div class="d-flex flex-wrap gap-3 mb-3 text-secondary small fw-semibold">
                                                <?php if (!empty($t_flat['nome_turno'])): ?><span><i class="fa fa-tag text-secondary me-1"></i> <?php echo htmlspecialchars($t_flat['nome_turno']); ?></span><?php endif; ?>
                                                <?php if (!empty($t_flat['data_turno'])): ?><span><i class="fa fa-calendar-alt text-danger me-1"></i> <?php echo date('d/m/Y', strtotime($t_flat['data_turno'])); ?></span><?php endif; ?>
                                                <?php if (orario_turno($t_flat) !== ''): ?><span><i class="fa fa-clock text-primary me-1"></i> <?php echo orario_turno($t_flat); ?></span><?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center mt-auto border-top pt-3">
                                            <span class="badge bg-light text-dark border fs-6 px-3 py-2">
                                                <i class="fa fa-users text-secondary me-1"></i> Posti occupati: <strong><?php echo $occ; ?>/<?php echo $t_flat['max_posti']; ?></strong>
                                            </span>
                                            <div><?php echo getPulsanteAzione($t_flat, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Script Ricerca Avanzata -->
    <script nonce="<?php echo csp_nonce(); ?>">
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('advSearchText');
            const catSelect = document.getElementById('advSearchCat');
            const dateFrom = document.getElementById('advSearchDateFrom');
            const dateTo = document.getElementById('advSearchDateTo');
            const soldoutSelect = document.getElementById('advSearchSoldout');
            const senzaData = document.getElementById('advSearchSenzaData');
            
            function filterEvents() {
                const textVal = searchInput.value.toLowerCase();
                const catVal = catSelect.value.toLowerCase();
                const fromVal = dateFrom.value;
                const toVal = dateTo.value;
                const soldoutVal = soldoutSelect.value;
                
                document.querySelectorAll('.adv-event-item').forEach(el => {
                    let show = true;
                    if (textVal && !el.dataset.text.includes(textVal)) show = false;
                    if (catVal && el.dataset.cat !== catVal) show = false;
                    // Turni senza data: esclusi dai filtri per data, mostrati se la casella è spuntata
                    if (!el.dataset.date) { if ((fromVal || toVal) && !senzaData.checked) show = false; }
                    else {
                        if (fromVal && el.dataset.date < fromVal) show = false;
                        if (toVal && el.dataset.date > toVal) show = false;
                    }
                    if (soldoutVal === '0' && el.dataset.soldout === '1') show = false;
                    
                    if(show) { el.classList.remove('d-none'); } else { el.classList.add('d-none'); }
                });
            }

            if(searchInput) {
                searchInput.addEventListener('input', filterEvents);
                catSelect.addEventListener('change', filterEvents);
                dateFrom.addEventListener('change', filterEvents);
                dateTo.addEventListener('change', filterEvents);
                soldoutSelect.addEventListener('change', filterEvents);
                senzaData.addEventListener('change', filterEvents);
            }
        });
    </script>
