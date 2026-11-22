<?php
// Card di un evento nei layout dell'area (elenco, griglia, in evidenza): locandina, titolo, descrizione breve, luogo, disponibilità e pulsante verso la scheda.
// Incluso da renderCardUniversal() (inc/area_pubblica/funzioni.php): le variabili sono i parametri di quella funzione.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola
//   $conn, $chiedi_matricola
        $ruolo_richiesto = (int)($ev['ruolo_accesso_id'] ?? 0); // qui serve solo per il badge «Solo …»: chi può prenotare lo decide AccessoEvento nei pulsanti

        $req_prenotazione = (int)($ev['richiede_prenotazione'] ?? 1);
        $first_turno_id = !empty($ev['turni'][0]['id']) ? $ev['turni'][0]['id'] : rand(100, 999);
        $collapse_id = "colTurni_" . $ev['id'] . "_" . $first_turno_id;

        echo '<div class="ui-area-bt4 card card-evento-u shadow-sm border-0 h-100 d-flex flex-column" style="border-radius:8px; overflow:hidden; background:#ffffff;">';
        
        if (!empty($ev['locandina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $ev['locandina_path'])) {
            echo '<img src="'.htmlspecialchars($ev['locandina_path']).'" class="card-img-top border-bottom ui-maxh-250 ui-of-cover" alt="Locandina">';
        }
        
        echo '<div class="card-body p-4 d-flex flex-column">';
        echo '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">';
        $url_sch = htmlspecialchars($GLOBALS['current_filename'] ?? '') . '.php?' . (($ev['tipo'] ?? '') === 'progetto' ? 'progetto=' : 'evento=') . (int)$ev['id'];
        echo '<h4 class="fw-bold fs-5 m-0"><a href="' . $url_sch . '" class="ui-area-testo text-decoration-none">'.htmlspecialchars($ev['titolo']).'</a></h4>';
        if ($ruolo_richiesto === -1) echo '<span class="badge bg-warning text-dark">🔑 Solo Autenticati</span>';
        elseif ($ruolo_richiesto > 0) echo '<span class="badge bg-dark">🔒 Solo '.htmlspecialchars($nomi_ruoli[$ruolo_richiesto] ?? '').'</span>';
        echo '</div>';
        
        // Nelle card solo la descrizione breve: la completa è nella scheda dell'evento
        $testo_card = testo_card_evento($ev);
        if ($testo_card !== '') echo '<p class="text-secondary mb-3 flex-grow-1 ui-fs-95 ui-lh-1_55">' . $testo_card . '</p>';
        
        if (!empty($ev['allegato_pdf'])) {
            echo '<div class="ui-area-bl4 mb-3 p-3 rounded" style="background-color:#f8f9fa; font-size:0.9rem;">
                    <strong class="d-block mb-1 text-dark">Materiale Informativo:</strong>
                    <a href="'.htmlspecialchars($ev['allegato_pdf']).'" target="_blank" class="btn btn-outline-danger btn-sm fw-bold">
                        <i class="fa fa-file-pdf me-1"></i> Visualizza / Scarica Programma
                    </a>
                  </div>';
        }

        if (!empty($ev['luogo'])) {
            echo '<div class="ui-area-bl4 bg-light p-2 rounded mt-2 text-dark shadow-sm" style="font-size:1rem;">
                    <i class="fa fa-map-pin text-danger me-2"></i> <strong class="text-primary">Luogo:</strong> '.htmlspecialchars($ev['luogo']).'
                  </div>';
        }
        
        echo '</div>';

        // Fondo della card: disponibilità, scadenza delle prenotazioni e pulsante verso la scheda completa
        // (turni, prenotazione e contatti sono nella scheda dell'evento)
        $txt_btn = colore_testo_su($col_primaria);
        $badge_disp_html = '';
        if ($req_prenotazione == 0) {
            $badge_disp_html = '<span class="badge bg-success ui-fs-80"><i class="fa fa-unlock me-1" aria-hidden="true"></i>Ingresso libero</span>';
        } elseif (!empty($ev['turni'])) {
            $posti_badge_disp = 0; $badge_has_waitlist = false; $badge_all_ended = true; $illimitato = false;
            foreach ($ev['turni'] as $_bt) {
                if (turno_concluso($_bt)) continue;
                $badge_all_ended = false;
                if ((int)$_bt['max_posti'] >= POSTI_SENZA_LIMITE) { $illimitato = true; continue; }
                $_disp_bt = (int)$_bt['max_posti'] - getPostiOccupati($conn, $_bt['id']);
                if ($_disp_bt <= 0 && !empty($_bt['abilita_lista_attesa'])) $badge_has_waitlist = true;
                $posti_badge_disp += max(0, $_disp_bt);
            }
            if ($badge_all_ended) $badge_disp_html = '<span class="badge bg-secondary ui-fs-80">Concluso</span>';
            elseif ($illimitato) $badge_disp_html = '<span class="badge bg-success ui-fs-80">Posti disponibili</span>';
            elseif ($posti_badge_disp > 0) $badge_disp_html = '<span class="badge bg-success ui-fs-80">' . testo_posti_liberi((int)$posti_badge_disp) . '</span>';
            elseif ($badge_has_waitlist) $badge_disp_html = '<span class="badge bg-warning text-dark ui-fs-80">Lista d\'attesa</span>';
            else $badge_disp_html = '<span class="badge bg-danger ui-fs-80">Posti esauriti</span>';
        }
        $finestra = ($req_prenotazione == 1 && !empty($ev['turni'])) ? finestra_prenotazione($ev['turni']) : null;
        $n_turni = count($ev['turni'] ?? []);
        echo '<div class="card-footer border-top-0 p-3 d-flex flex-column gap-2 mt-auto ui-bg-sfondo-lieve">';
        echo '<div class="d-flex flex-wrap align-items-center gap-2">' . $badge_disp_html;
        if ($n_turni > 1) echo '<span class="badge bg-light text-dark border ui-fs-80"><i class="fa fa-clock me-1" aria-hidden="true"></i>' . $n_turni . ' turni</span>';
        if ($finestra) echo '<span class="badge" style="font-size:0.8rem; background:' . $finestra['bg'] . '; color:' . $finestra['fg'] . ';"><i class="fa ' . $finestra['icona'] . ' me-1" aria-hidden="true"></i>' . htmlspecialchars($finestra['testo']) . '</span>';
        echo '</div>';
        echo '<a href="' . $url_sch . '" class="ui-area-fondo btn fw-bold w-100 py-2 shadow-sm" style="color:' . $txt_btn . '; border:none; border-radius:8px;">'
           . ($req_prenotazione == 1 ? 'Scheda completa, turni e prenotazione' : 'Scheda completa, orari e contatti') . ' <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></a>';
        echo '</div>';
        echo '</div>'; 
