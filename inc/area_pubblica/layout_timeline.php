<?php
// Layout «timeline»: turni su una linea verticale, per giorno.
// Caricato da master_template.php (stesso ambito della pagina) quando serve questo disegno.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $page_cfg, $eventi_per_data, $titolo_giorno, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome
//   $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola
?>
    <div class="ui-larg-cont container mb-5">
        
        <!-- Hero Testata Timeline -->
        <div class="text-center mb-5 mt-4">
            <h1 class="ui-area-testo fw-black display-5 m-0 my-2" style="font-weight:900; letter-spacing:-1px;">
                <?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'PROGRAMMA EVENTI'); ?>
            </h1>
            <p class="text-secondary fs-5 mx-auto ui-maxw-700">
                <?php echo !empty($page_cfg['hero_descrizione']) ? $page_cfg['hero_descrizione'] : 'Segui il programma cronologico delle attività.'; ?>
            </p>
        </div>

        <div class="ui-area-bl4 timeline-container mx-auto" style="max-width:900px; position:relative; padding-left:3rem;">
            <?php if (empty($eventi_per_data)): ?>
                <div class="alert alert-light text-center border p-4 shadow-sm">Nessun evento in programma.</div>
            <?php else: ?>
                <?php foreach ($eventi_per_data as $data_giorno => $lista_ev_giorno): ?>
                    <div class="timeline-group mb-5 position-relative">
                        <!-- Nodo Data -->
                        <div class="ui-area-fondo timeline-badge shadow-sm" style="position:absolute; left:-4.3rem; top:0; width:50px; height:50px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:white; border:4px solid #fff; z-index:2;">
                            <i class="fa <?php echo $data_giorno === GIORNO_SENZA_DATA ? 'fa-infinity' : 'fa-calendar-day'; ?> fs-5" aria-hidden="true"></i>
                        </div>
                        
                        <h2 class="ui-area-testo fw-bold fs-3 mb-4 ms-2 text-dark pt-2">
                            <?php echo $titolo_giorno($data_giorno); ?>
                        </h2>
                        
                        <div class="row g-4 ms-1">
                            <?php foreach ($lista_ev_giorno as $ev): ?>
                                <div class="col-12 position-relative">
                                    <!-- Linea orizzontale di collegamento -->
                                    <div class="ui-area-fondo" style="position:absolute; left:-2rem; top:2rem; width:2rem; height:3px; opacity:0.3; z-index:1;"></div>
                                    <?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
