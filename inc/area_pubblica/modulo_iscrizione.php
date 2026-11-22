<?php
// Finestra (modale) con il modulo di prenotazione, iscrizione o aggiunta al programma FSL di un turno.
// Incluso da printModalPrenotazione() (inc/area_pubblica/funzioni.php): le variabili sono i parametri di quella funzione;
// usa anche $GLOBALS['evento_scheda_id'] (scheda dell'evento aperta) e $GLOBALS['logged_u_info'] (dati della persona che ha fatto l'accesso).
// Variabili che usa (i parametri della funzione):
//   $t, $col_primaria, $utente_logged, $val_nome, $val_cognome, $val_email, $val_matricola, $chiedi_matricola, $conn, $p_id
    $read_nome = ($utente_logged && !empty($val_nome)) ? 'readonly' : '';
    $read_cognome = ($utente_logged && !empty($val_cognome)) ? 'readonly' : '';
    $read_matricola = ($utente_logged && !empty($val_matricola)) ? 'readonly' : '';

    $occ = getPostiOccupati($conn, $t['id']);
    $disponibili = $t['max_posti'] - $occ;
    $soldout = ($disponibili <= 0);
    $is_waitlist = ($soldout && isset($t['abilita_lista_attesa']) && $t['abilita_lista_attesa'] == 1);
    $is_progetto = ($t['evento_tipo'] ?? '') === 'progetto';
    $per_scuole_m = $is_progetto && !empty($t['per_scuole']); // progetto per le scuole: testi "scuola" e numero di partecipanti
    $classe_m = prenotazione_di_classe($is_progetto, $t['dett_progetto'] ?? null); // numero di studenti (progetti per le scuole, eventi con attestati di classe)
    $fsl_m = (int)($t['dett_progetto']['convenzione'] ?? 0) === 1; // attività FSL: si aggiunge al programma della scuola e si prenota insieme alle altre
    ?>
    <div class="modal fade" id="modPrenota<?php echo $t['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content shadow-lg border-0 ui-r-12">
                <form method="POST" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="turno_id" value="<?php echo $t['id']; ?>">
                    <?php if ($fsl_m): ?><input type="hidden" name="aggiungi_programma" value="1"><?php endif; ?>
                    <?php if (!empty($GLOBALS['evento_scheda_id'])): ?><input type="hidden" name="da_scheda" value="<?php echo (int)$GLOBALS['evento_scheda_id']; ?>"><?php endif; ?>
                    <div class="modal-header py-2 bg-light border-bottom-0">
                        <h6 class="modal-title fw-bold text-dark"><i class="ui-area-testo fa <?php echo $fsl_m ? 'fa-clipboard-list' : ($per_scuole_m ? 'fa-school' : 'fa-ticket-alt'); ?> me-1"></i> <?php echo $fsl_m ? 'Aggiungi al programma FSL' : ($per_scuole_m ? 'Iscrizione della scuola' : ($is_progetto ? 'Iscrizione' : 'Prenotazione')); ?>: <?php echo htmlspecialchars($t['evento_titolo']); ?><?php if ($is_progetto && !empty($t['nome_turno']) && !in_array($t['nome_turno'], ['Iscrizione scuole', 'Iscrizioni'], true)): ?> <span class="ui-area-fondo badge ms-1" style="color:<?php echo colore_testo_su($col_primaria); ?>;"><?php echo htmlspecialchars($t['nome_turno']); ?></span><?php endif; ?></h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-start">
                        <?php if ($fsl_m): ?>
                        <div role="note" style="<?php echo \App\Fsl\Vista\AvvisoDocumenti::STILE_RIQUADRO; ?>">
                            <div style="<?php echo \App\Fsl\Vista\AvvisoDocumenti::STILE_TITOLO; ?>"><i class="fa fa-clipboard-list me-1" aria-hidden="true"></i> Stai aggiungendo l'attività al programma della scuola</div>
                            Potrai sceglierne altre e poi prenotarle <strong>tutte insieme</strong>, con un'unica convenzione e l'Allegato A già compilato. I dati della scuola e di chi prenota si chiedono una volta sola, alla fine.
                            <?php if ($is_waitlist): ?><strong>I posti sono esauriti</strong>: alla conferma la richiesta entrerà in lista d'attesa.<?php else: ?>I posti si assegnano alla conferma, non quando aggiungi l'attività.<?php endif; ?>
                        </div>
                        <?php elseif ($is_progetto): ?>
                        <div class="alert <?php echo $is_waitlist ? 'alert-warning' : 'alert-info'; ?> border-0 small mb-3">
                            <i class="fa fa-circle-info me-1" aria-hidden="true"></i>
                            <?php if ($per_scuole_m && $is_waitlist): ?>
                                L'edizione è già stata assegnata a un'altra scuola: la tua richiesta entra in <strong>lista d'attesa</strong>, in ordine di arrivo. Se il posto si libera riceverai un'email per confermarlo.
                            <?php elseif ($per_scuole_m): ?>
                                Ogni edizione accoglie <strong>una sola scuola</strong>, in ordine di arrivo. Compila i dati come docente referente della scuola.
                            <?php elseif ($is_waitlist): ?>
                                I posti sono esauriti: entri in <strong>lista d'attesa</strong>, in ordine di arrivo. Se un posto si libera riceverai un'email per confermarlo.
                            <?php else: ?>
                                <?php echo !empty($t['dett_progetto']['piu_edizioni']) ? 'Puoi iscriverti a <strong>una o più edizioni</strong> del progetto (una prenotazione per ogni edizione).' : 'Puoi iscriverti a <strong>una sola edizione</strong> del progetto.'; ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php // Attività FSL: riquadro evidente sui documenti da caricare dopo la prenotazione (elenco studenti e autorizzazione)
                        // L'avviso è solo informativo: se non si può costruire (es. file del server non allineati) la prenotazione deve funzionare lo stesso
                        try { $box_doc_m = \App\Core\App::get(\App\Fsl\ServizioDocumentiClasse::class)->avvisoModulo($t['dett_progetto'] ?? null, (bool)$is_progetto, $t['data_turno'] ?? null); }
                        catch (\Throwable $e_doc) { error_log('[Prenotazione] avviso documenti FSL non disponibile: ' . $e_doc->getMessage()); $box_doc_m = ''; }
                        if ($box_doc_m !== ''): echo $box_doc_m;
                        elseif (!$is_progetto && $classe_m): ?>
                        <div class="alert alert-info border border-2 border-info shadow-sm mb-3 ui-fs-105 ui-lh-1_45" role="note" style="border-left-width:8px !important;">
                            <i class="fa fa-school me-1" aria-hidden="true"></i>
                            Prenotazione per una <strong>classe</strong>: indica il numero di studenti. Dopo la conferma potrai inserire l'elenco degli studenti per gli <strong>attestati</strong> dalla tua Area personale (accesso con SPID, CIE o credenziali Unical, con la stessa email).
                        </div>
                        <?php endif; ?>
                        <div class="alert alert-light border shadow-sm mb-3" <?php echo ($is_progetto || $fsl_m) ? 'hidden' : ''; ?>>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <?php if (!empty($t['nome_turno'])): ?><span class="ui-area-fondo badge">🏷️ <?php echo htmlspecialchars($t['nome_turno']); ?></span><?php endif; ?>
                                <?php if (!empty($t['data_turno'])): ?><span class="badge bg-dark">📅 <?php echo date('d/m/Y', strtotime($t['data_turno'])); ?></span><?php endif; ?>
                                <?php if (orario_turno($t) !== ''): ?><span class="badge bg-secondary">🕒 <?php echo orario_turno($t); ?></span><?php endif; ?>
                            </div>
                            <?php if(!empty($t['evento_luogo'])): ?><small class="text-muted fw-bold d-block"><i class="fa fa-map-marker-alt text-danger me-1"></i> <?php echo htmlspecialchars($t['evento_luogo']); ?></small><?php endif; ?>
                            <?php if(!empty($t['annullabile_fino'])): ?><small class="text-muted d-block mt-1"><i class="fa fa-rotate-left me-1" aria-hidden="true"></i> Potrai annullare o cambiare turno fino al <strong><?php echo date('d/m/Y \a\l\l\e H:i', strtotime($t['annullabile_fino'])); ?></strong>.</small><?php endif; ?>
                        </div>
                        
                        <?php if (!$fsl_m && isset($t['abilita_multi_posto']) && $t['abilita_multi_posto'] == 1): ?>
                            <div class="mb-3 p-2 bg-light rounded border border-primary">
                                <label class="form-label small fw-bold text-primary mb-1"><i class="fa fa-users me-1"></i> Posti da Prenotare <span class="text-danger">*</span></label>
                                <input aria-label="Posti da Prenotare" type="number" name="num_posti" class="form-control form-control-sm fw-bold text-primary" value="1" min="1" max="<?php echo max(1, $disponibili); ?>" required>
                            </div>
                        <?php endif; ?>

                        <!-- DATI ANAGRAFICI A DUE A DUE (programma FSL: si chiedono una volta sola, alla conferma) -->
                        <?php if (!$fsl_m): ?>
                        <div class="row g-3 mb-2">
                            <div class="col-md-6"><label class="form-label small fw-bold">Nome <span class="text-danger">*</span></label><input aria-label="Nome" type="text" name="nome" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val_nome); ?>" required <?php echo $read_nome; ?>></div>
                            <div class="col-md-6"><label class="form-label small fw-bold">Cognome <span class="text-danger">*</span></label><input aria-label="Cognome" type="text" name="cognome" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val_cognome); ?>" required <?php echo $read_cognome; ?>></div>
                            
                            <?php if ($chiedi_matricola == 1): ?>
                                <div class="col-md-6"><label class="form-label small fw-bold">Matricola <span class="text-muted fw-normal">(Opzionale)</span></label><input aria-label="Matricola (Opzionale)" type="text" name="matricola" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val_matricola); ?>" <?php echo $read_matricola; ?>></div>
                                <div class="col-md-6"><label for="em<?php echo $t['id']; ?>" class="form-label small fw-bold">Email <span class="text-danger">*</span></label><input type="email" name="email" id="em<?php echo $t['id']; ?>" class="form-control form-control-sm pren-email" autocomplete="email" required></div>
                                <div class="col-md-6"><label for="emc<?php echo $t['id']; ?>" class="form-label small fw-bold">Ripeti l'email <span class="text-danger">*</span></label><input type="email" name="email_conferma" id="emc<?php echo $t['id']; ?>" class="form-control form-control-sm pren-email-conf" autocomplete="off" required></div>
                                <div class="col-12 form-text mt-1">Scrivi l'indirizzo a cui vuoi ricevere conferma, promemoria e attestati.</div>
                            <?php else: ?>
                                <div class="col-md-6"><label for="em<?php echo $t['id']; ?>" class="form-label small fw-bold">Email <span class="text-danger">*</span></label><input type="email" name="email" id="em<?php echo $t['id']; ?>" class="form-control form-control-sm pren-email" autocomplete="email" required></div>
                                <div class="col-md-6"><label for="emc<?php echo $t['id']; ?>" class="form-label small fw-bold">Ripeti l'email <span class="text-danger">*</span></label><input type="email" name="email_conferma" id="emc<?php echo $t['id']; ?>" class="form-control form-control-sm pren-email-conf" autocomplete="off" required></div>
                                <div class="col-12 form-text mt-1">Scrivi l'indirizzo a cui vuoi ricevere conferma, promemoria e attestati.</div>
                            <?php endif; ?>
                        </div>
                        <?php endif; // !fsl_m ?>

                        <!-- CAMPI PERSONALIZZATI (con campi condizionali e nuovi tipi) -->
                        <?php require __DIR__ . '/campi_iscrizione.php'; ?>
                        
                        <?php // Attività FSL: la risposta sulla convenzione si dà una volta sola, nel riepilogo del programma (programma_fsl.php) ?>

                        <?php if (!$utente_logged && !$fsl_m): $cap = captcha_prenotazione(); ?>
                        <!-- CONTROLLO ANTI-ROBOT (solo prenotazioni senza accesso) -->
                        <div class="mt-3 p-3 rounded border bg-light">
                            <label for="captcha_<?php echo $t['id']; ?>" class="form-label small fw-bold mb-1"><i class="fa fa-shield-halved me-1" aria-hidden="true"></i>Controllo anti-robot: <?php echo htmlspecialchars($cap['domanda']); ?> <span class="text-danger">*</span></label>
                            <input type="text" name="captcha_risposta" id="captcha_<?php echo $t['id']; ?>" class="form-control form-control-sm ui-maxw-140" inputmode="numeric" pattern="[0-9]*" maxlength="3" autocomplete="off" required>
                            <input type="hidden" name="captcha_id" value="<?php echo htmlspecialchars($cap['id']); ?>">
                            <div class="form-text">Con l'accesso SPID, CIE o Unical questa domanda non viene chiesta.</div>
                        </div>
                        <!-- Campo trappola: invisibile alle persone, i robot lo compilano -->
                        <div class="ui-pos-absolute ui-w-1 ui-h-1 ui-ov-hidden" style="left:-10000px;" aria-hidden="true">
                            <label for="sitoWeb_<?php echo $t['id']; ?>">Sito web (lascia vuoto)</label>
                            <input type="text" name="sito_web" id="sitoWeb_<?php echo $t['id']; ?>" value="" tabindex="-1" autocomplete="off">
                        </div>
                        <?php endif; ?>

                        <!-- CHECKBOX PRIVACY OBBLIGATORIO (programma FSL: nel riepilogo) -->
                        <?php if (!$fsl_m): ?>
                        <div class="form-check mt-3 mb-1 p-3 bg-light rounded border border-secondary shadow-sm">
                            <input class="form-check-input border-secondary" type="checkbox" name="accetta_privacy" id="privacyCheck_<?php echo $t['id']; ?>" required>
                            <label class="form-check-label text-dark ui-fs-85 ui-lh-1_4" for="privacyCheck_<?php echo $t['id']; ?>">
                                <?php // Base giuridica: compito di interesse pubblico (art. 6.1.e GDPR), non il consenso: si dichiara solo la presa visione ?>
                                Ho letto l'<a href="privacy.php" target="_blank" rel="noopener" class="fw-bold" style="color: <?php echo colore_testo_su($col_primaria) === '#FFFFFF' ? $col_primaria : '#1F2937'; ?>; text-decoration: underline;">informativa sul trattamento dei dati personali</a>.
                            </label>
                        </div>
                        <?php endif; // !fsl_m ?>
                        
                    </div>
                    <div class="modal-footer py-2 bg-light border-top-0">
                        <?php if ($fsl_m): ?>
                            <button type="submit" class="btn btn-sm fw-bold w-100 shadow-sm <?php echo $is_waitlist ? 'btn-warning text-dark' : 'btn-primary'; ?>" <?php echo $is_waitlist ? '' : 'style="background-color: ' . $col_primaria . '; border: none;"'; ?>><i class="fa fa-clipboard-list me-1" aria-hidden="true"></i><?php echo $is_waitlist ? "Aggiungi al programma (lista d'attesa)" : 'Aggiungi al programma'; ?></button>
                        <?php elseif($is_waitlist): ?>
                            <button type="submit" name="invia_prenotazione" class="btn btn-warning btn-sm fw-bold w-100 text-dark border-0"><?php echo $per_scuole_m ? "Metti la scuola in lista d'attesa" : "Aggiungimi in Lista d'Attesa"; ?></button>
                        <?php else: ?>
                            <button type="submit" name="invia_prenotazione" class="ui-area-fondo btn btn-primary btn-sm fw-bold w-100 shadow-sm" style="border:none;"><?php echo $per_scuole_m ? 'Conferma l\'iscrizione della scuola' : ($is_progetto ? 'Conferma l\'iscrizione' : 'Conferma e Prenota'); ?></button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php /* fine del modulo */
