<?php
// Layout «lista» e «griglia» (i layout semplici): benvenuto e banner, evento in evidenza, box informativi, card degli eventi con o senza barra laterale.
// Caricato da master_template.php (stesso ambito della pagina) quando serve questo disegno.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $page_cfg, $evento_evidenza, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome
//   $val_email, $val_matricola, $conn, $chiedi_matricola, $layout_template, $eventi_per_data, $titolo_giorno, $col_class, $sezioni_superiori
//   $gruppi_griglia, $titolo_gruppo_griglia
?>
    <div class="ui-larg-cont container mb-5">
        
        <!-- RIGA 1: TESTO BENVENUTO E BANNER -->
        <div class="row align-items-center mb-4 g-4">
            <div class="col-lg-7">
                <div class="ui-area-bl5 card shadow-sm border-0 p-4" style="background:#ffffff; border-radius:12px;">
                    <div class="text-dark ui-fs-95 ui-lh-1_6">
                        <?php if (!empty($page_cfg['hero_descrizione'])): echo $page_cfg['hero_descrizione']; else: ?><strong style="color: #000;">Benvenuto/a:</strong> scopri il programma ed iscriviti alle attività di tuo interesse.<br><br><strong class="ui-area-testo">I posti sono limitati!</strong><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="ui-area-bx2 card shadow-sm border-0 text-center p-4 w-100 d-flex flex-column justify-content-center align-items-center position-relative overflow-hidden" style="border-radius:14px; background:#fdfbfb;">
                    <div>
                        <?php if (!empty($page_cfg['sidebar_intervallo_date'])): ?><div class="d-inline-block badge bg-warning text-dark fw-bold px-3 py-1 fs-6 rounded-pill mb-2 border shadow-sm">📝 <?php echo htmlspecialchars($page_cfg['sidebar_intervallo_date']); ?></div><?php endif; ?>
                        <h1 class="ui-area-testo fw-bold display-5 m-0 my-1" style="font-weight:900; letter-spacing:-1px;"><?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'EVENTI'); ?></h1>
                        <h2 class="ui-area-testo-ok fw-bold fs-3 mb-0" style="letter-spacing:2px;"><?php echo htmlspecialchars(!empty($page_cfg['sottotitolo']) ? $page_cfg['sottotitolo'] : 'DiBEST'); ?></h2>
                    </div>
                    <div class="ui-area-testo mt-auto pt-3 w-100 text-center">
                        <?php if (!empty($page_cfg['hero_banner_path'])): ?><img src="uploads/<?php echo htmlspecialchars(basename($page_cfg['hero_banner_path'])); ?>" class="img-fluid mt-auto ui-maxh-100 ui-of-contain" alt="Banner Custom"><?php else: ?><svg viewBox="0 0 500 80" class="w-100 mt-auto ui-maxh-60" fill="currentColor"><path d="M10,80 L35,40 L60,80 Z M30,50 L40,80 Z"></path><circle cx="48" cy="42" r="3"></circle><path d="M70,80 C70,60 85,50 100,50 C115,50 120,60 120,80 Z"></path><path d="M130,80 C130,55 145,40 160,40 C175,40 190,55 190,80 Z"></path><circle cx="210" cy="50" r="4"></circle><path d="M210,55 L210,75 M203,62 L217,62 M205,80 L210,75 L215,80"></path><circle cx="225" cy="45" r="4"></circle><path d="M225,50 L225,72 M218,55 L232,55 M220,80 L225,72 L230,80"></path><circle cx="240" cy="48" r="4"></circle><path d="M240,53 L240,75 M233,60 L247,60 M235,80 L240,75 L245,80"></path><circle cx="255" cy="42" r="4"></circle><path d="M255,47 L255,70 M248,52 L262,52 M250,80 L255,70 L260,80"></path><path d="M320,80 L320,50 L360,50 L360,80 Z M330,80 L330,60 L350,60 L350,80 Z M340,40 L320,50 L360,50 Z"></path></svg><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGA 2: EVENTO IN EVIDENZA -->
        <?php if ($evento_evidenza): ?>
            <?php $data_head_ev = !empty($evento_evidenza['turni'][0]['data_turno']) ? $evento_evidenza['turni'][0]['data_turno'] : ''; ?>
            
            <div class="ui-area-bx3 mb-5 shadow-sm rounded-4" style="background-color:#fff; overflow:hidden;">
                
                <div class="ui-area-fondo text-white px-3 py-2 d-flex align-items-center flex-wrap gap-3">
                    <span class="badge bg-warning text-dark fw-bold px-3 py-2 text-uppercase d-flex align-items-center gap-2 shadow-sm ui-ls-1 ui-fs-90">
                        <i class="fa fa-star text-dark fs-6"></i> IN EVIDENZA
                    </span>
                    
                    <?php if ($data_head_ev): ?>
                        <span class="fs-4 d-flex align-items-center gap-2 ms-md-2 date-white-force">
                            <i class="fa fa-calendar-alt fs-5"></i> <?php echo formattaDataItaliano($data_head_ev); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="p-2 p-md-3 bg-white box-evidenza">
                    <style>
                        .box-evidenza .card { box-shadow: none !important; margin-bottom: 0 !important; border: none !important; }
                        .date-white-force, .date-white-force * { color: #ffffff !important; font-weight: 700 !important; }
                    </style>
                    <?php renderCardUniversal($evento_evidenza, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?>
                </div>
                
            </div>
        <?php endif; ?>

        <?php if (!empty($page_cfg['box_info_html']) || !empty($page_cfg['allegati_box_info'])): ?>
            <div class="ui-area-bl5 card shadow-sm border-0 p-4 mb-4" style="background:#ffffff; border-radius:8px;">
                <?php if (!empty($page_cfg['box_info_html'])): ?>
                    <div class="fs-6 text-dark ui-lh-1_7"><?php echo $page_cfg['box_info_html']; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($page_cfg['allegati_box_info'])): ?>
                    <div class="mt-3 pt-3 border-top d-flex flex-wrap gap-2">
                        <?php 
                            $allegati = explode(',', $page_cfg['allegati_box_info']);
                            foreach($allegati as $all): 
                                $all = trim($all);
                                if(empty($all)) continue;
                        ?>
                            <a href="<?php echo htmlspecialchars($all); ?>" target="_blank" class="btn btn-outline-danger btn-sm fw-bold shadow-sm">
                                <i class="fa fa-file-pdf me-1"></i> Guida / Allegato (PDF)
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- CONTROLLO SIDEBAR PER GRID E LIST -->
        <?php if (!empty($page_cfg['mostra_sidebar']) && $page_cfg['mostra_sidebar'] == 1): ?>
            <div class="row g-4 mt-1 align-items-start">
                <div class="col-lg-3">
                    <div class="ui-area-bt4 card shadow-sm border-0 p-4 text-center sidebar-sticky-fix" style="border-radius:8px; background:#ffffff;">
                        <h3 class="ui-area-testo fw-bold fs-4 mb-2"><?php echo htmlspecialchars(!empty($page_cfg['sidebar_titolo']) ? $page_cfg['sidebar_titolo'] : 'Scegli il tuo evento'); ?></h3>
                        <?php if (!empty($page_cfg['sidebar_intervallo_date'])): ?>
                            <div class="badge bg-danger p-2 fs-6 mb-3">📅 <?php echo htmlspecialchars($page_cfg['sidebar_intervallo_date']); ?></div>
                        <?php endif; ?>
                        
                        <div class="text-secondary small text-start mt-2 mb-4 ui-lh-1_6"><?php echo !empty($page_cfg['sidebar_testo']) ? $page_cfg['sidebar_testo'] : 'Naviga il calendario qui a fianco per scoprire tutte le iniziative previste.'; ?></div>
                        
                        <!-- DOCUMENTI SIDEBAR SPOSTATI IN BASSO - GRID/LIST -->
                        <?php if (!empty($page_cfg['allegati_sidebar'])): ?>
                            <div class="d-flex flex-column gap-2 mt-auto border-top pt-3">
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
                
                <div class="col-lg-9">
                    <?php if ($layout_template == 'list'): ?>
                        <?php foreach ($eventi_per_data as $data_giorno => $lista_ev_giorno): ?>
                            <h3 class="fw-bold mb-3 fs-3 text-dark border-bottom pb-2"><?php echo $titolo_giorno($data_giorno); ?></h3>
                            <div class="row g-4 mb-5">
                                <?php $dinamic_col = (count($lista_ev_giorno) == 1) ? 'col-12' : $col_class; ?>
                                <?php foreach ($lista_ev_giorno as $ev): ?>
                                    <div class="<?php echo $dinamic_col; ?>">
                                        <?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- GRIGLIA -->
                        <div class="row g-4 mb-4 align-items-start">
                            <?php foreach ($sezioni_superiori as $nome_sezione => $lista_ev_sup): ?>
                                <div class="col-md-6 mb-2">
                                    <h2 class="ui-area-bg p-3 mb-3 rounded shadow-sm text-center text-uppercase fw-bold fs-6" style="letter-spacing:1px;"><?php echo htmlspecialchars($nome_sezione); ?></h2>
                                    <div class="row g-4">
                                        <?php foreach ($lista_ev_sup as $ev): ?><div class="col-12"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div><?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php foreach ($gruppi_griglia as $nome_gruppo => $lista_gruppo): $tit_g = $titolo_gruppo_griglia((string)$nome_gruppo); ?>
                            <?php if ($tit_g !== ''): ?>
                                <h2 class="ui-area-bg p-3 my-4 rounded shadow-sm text-center text-uppercase fw-bold fs-5" style="letter-spacing:1px;"><?php echo htmlspecialchars($tit_g); ?></h2>
                            <?php endif; ?>
                            <div class="row g-4 mb-4">
                                <?php foreach ($lista_gruppo as $ev): ?><div class="<?php echo $col_class; ?>"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div><?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- SENZA SIDEBAR -->
            <?php if ($layout_template == 'list'): ?>
                <?php foreach ($eventi_per_data as $data_giorno => $lista_ev_giorno): ?>
                    <h3 class="fw-bold mb-3 fs-3 text-dark border-bottom pb-2"><?php echo $titolo_giorno($data_giorno); ?></h3>
                    <div class="row g-4 mb-5">
                        <?php $dinamic_col = (count($lista_ev_giorno) == 1) ? 'col-12' : $col_class; ?>
                        <?php foreach ($lista_ev_giorno as $ev): ?>
                            <div class="<?php echo $dinamic_col; ?>">
                                <?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- GRIGLIA -->
                <div class="row g-4 mb-4 align-items-start">
                    <?php foreach ($sezioni_superiori as $nome_sezione => $lista_ev_sup): ?>
                        <div class="col-md-6 mb-2">
                            <h2 class="ui-area-bg p-3 mb-3 rounded shadow-sm text-center text-uppercase fw-bold fs-6" style="letter-spacing:1px;"><?php echo htmlspecialchars($nome_sezione); ?></h2>
                            <div class="row g-4">
                                <?php foreach ($lista_ev_sup as $ev): ?><div class="col-12"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div><?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($gruppi_griglia as $nome_gruppo => $lista_gruppo): $tit_g = $titolo_gruppo_griglia((string)$nome_gruppo); ?>
                    <?php if ($tit_g !== ''): ?>
                        <h2 class="ui-area-bg p-3 my-4 rounded shadow-sm text-center text-uppercase fw-bold fs-5" style="letter-spacing:1px;"><?php echo htmlspecialchars($tit_g); ?></h2>
                    <?php endif; ?>
                    <div class="row g-4 mb-4">
                        <?php foreach ($lista_gruppo as $ev): ?><div class="<?php echo $col_class; ?>"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div><?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
