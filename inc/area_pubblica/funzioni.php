<?php
// Funzioni di disegno della pagina pubblica di un'area (card, modulo di prenotazione, pulsante del turno). Le prime due caricano un parziale che
// ne contiene il disegno; la terza decide solo quale pulsante mostrare. Caricato da master_template.php.

// =========================================================================
// FUNZIONI DI RENDER (MODALE e CARD)
// =========================================================================
if (!function_exists('renderCardUniversal')) {
    function renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola = 1) {
        require __DIR__ . '/card_evento.php';
    }
}

function printModalPrenotazione($t, $col_primaria, $utente_logged, $val_nome, $val_cognome, $val_email, $val_matricola, $chiedi_matricola, $conn, $p_id) {
    require __DIR__ . '/modulo_iscrizione.php';
}

function getPulsanteAzione($t, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli) {
    $ruolo_richiesto = (int)($t['ruolo_accesso_id'] ?? 0);
    $sec_roles = isset($_SESSION['utente_ruoli_secondari']) ? array_map('trim', explode(',', (string)$_SESSION['utente_ruoli_secondari'])) : [];
    $ruolo_ok = \App\Eventi\AccessoEvento::ammesso($ruolo_richiesto, (bool)$utente_logged, (int)$utente_ruolo_id, $sec_roles);
    $serve_accesso = \App\Eventi\AccessoEvento::serveAccesso($ruolo_richiesto);

    if ((int)$t['richiede_prenotazione'] == 0) {
        return '<span class="badge bg-success py-2 px-3 shadow-sm fs-6"><i class="fa fa-unlock me-1"></i> Libero</span>';
    }

    $occ = getPostiOccupati($conn, $t['id']);
    $disponibili = $t['max_posti'] - $occ;
    $soldout = ($disponibili <= 0);
    $is_waitlist = ($soldout && isset($t['abilita_lista_attesa']) && $t['abilita_lista_attesa'] == 1);
    
    $now = date('Y-m-d H:i:s');
    $fsl = (int)($t['dett_progetto']['convenzione'] ?? 0) === 1; // attività FSL: va nel programma della scuola
    $nel_programma = $fsl && fsl_programma_ha((int)$t['evento_id'], (int)$t['id']);

    if (turno_concluso($t)) {
        return '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3 shadow-sm" disabled><i class="fa fa-flag-checkered me-1"></i> Evento Concluso</button>';
    }

    if (!empty($t['data_apertura']) && $now < $t['data_apertura']) {
        return '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3 shadow-sm" disabled>Apertura: '.date('d/m H:i', strtotime($t['data_apertura'])).'</button>';
    } elseif (!empty($t['data_chiusura']) && $now > $t['data_chiusura']) {
        return '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3 shadow-sm" disabled>Prenotazioni Chiuse</button>';
    }

    if ($soldout && !$is_waitlist) {
        return '<button class="btn btn-danger btn-sm fw-bold py-1 px-3 shadow-sm" disabled>Sold Out</button>';
    } elseif ($serve_accesso && !$utente_logged) {
        return '<a href="saml_login.php" class="ui-area-btn btn btn-primary btn-sm fw-bold py-1 px-3 shadow-sm"><i class="fa fa-key me-1"></i> Accedi</a>';
    } elseif ($serve_accesso && $utente_logged && !$ruolo_ok) {
        return '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3" disabled>🔒 Riservato</button>';
    } elseif ($is_waitlist) {
        $etic = $fsl ? ($nel_programma ? 'Nel programma' : 'Aggiungi (lista d\'attesa)') : 'Lista d\'Attesa';
        return '<button type="button" class="btn btn-warning btn-sm fw-bold text-dark py-1 px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modPrenota'.$t['id'].'">'.($fsl ? '<i class="fa fa-clipboard-list me-1" aria-hidden="true"></i>' : '').$etic.'</button>';
    } else {
        $etic = $fsl ? ($nel_programma ? 'Nel programma: modifica' : 'Aggiungi al programma') : 'Prenota Ora';
        return '<button type="button" class="ui-area-btn btn btn-primary btn-sm fw-bold py-1 px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#modPrenota'.$t['id'].'">'.($fsl ? '<i class="fa fa-clipboard-list me-1" aria-hidden="true"></i>' : '').$etic.'</button>';
    }
}
