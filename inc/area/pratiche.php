<?php
// Tessera «pratiche» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info, $u_id…).
// Pratiche della didattica (moduli online): collegamento a Le mie pratiche se la persona ne ha. Il numero in rosso conta le pratiche con novità: l'integrazione
// richiesta e gli esiti (accolta, respinta) degli ultimi sette giorni. Sotto il testo, i passi dell'ultima pratica (Inviata → In lavorazione → Esito).
$pr_rows = \App\Core\App::get(\App\Iscrizioni\AreaPersonaleRepository::class)->riepilogoPratiche((int)$_SESSION['utente_id']);
if ($pr_rows && (int)$pr_rows['n'] > 0) {
    $pr_novita = (int)$pr_rows['integr'] + (int)($pr_rows['esiti_recenti'] ?? 0);
    $pr_et = (int)$pr_rows['integr'] > 0 && (int)($pr_rows['esiti_recenti'] ?? 0) > 0 ? 'con novità' : ((int)$pr_rows['integr'] > 0 ? 'con integrazione richiesta' : 'con un esito nuovo');
    $pr_passi = \App\Portale\Area\PassiStudente::pratica(($pr_rows['ultima_stato'] ?? '') !== '' ? (string)$pr_rows['ultima_stato'] : null);
    area_tessera('fa-folder-open', '#047857', 'Le mie pratiche', (int)$pr_rows['aperte'] . ' in corso su ' . (int)$pr_rows['n'], 'pratiche.php',
        $pr_novita, $pr_et, [['Nuova pratica', 'modulistica.php']], false, ui_passi($pr_passi['passi'], $pr_passi['corrente'], 'piccolo', 'Passi della tua ultima pratica'));
}
