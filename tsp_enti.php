<?php
// tsp_enti.php - Guida interattiva per enti e aziende ospitanti: accredito, convenzione, opportunità o avvio diretto, progetto formativo e chiusura su TSP 2.0.
// Pagina pubblica (non mostra dati). Contenuti in assets/js/tsp-guida-enti.js, motore in assets/js/tsp-guida.js.
require_once 'config.php';
require_once 'functions.php';
require_once 'inc/tsp_guida_vista.php';

$page_cfg['titolo'] = 'Tirocinio sulla piattaforma TSP: guida per enti e aziende';
require_once 'header.php';
tsp_guida_vista([
    'titolo' => 'Guida per enti e aziende: ospitare tirocinanti su TSP 2.0',
    'sottotitolo' => 'Dall\'accredito alla convenzione, dalla scelta dello studente al progetto formativo e alla chiusura del tirocinio: ogni passaggio mostrato sulla piattaforma.',
    'colore' => '#9a3412',
    'icona' => 'fa-building',
    'script' => 'tsp-guida-enti.js',
    'altra' => ['Guida per studenti', 'tsp_studente.php'],
]);
require_once 'footer.php';
