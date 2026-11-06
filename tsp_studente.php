<?php
// tsp_studente.php - Guida interattiva per lo studente: il tirocinio curriculare sulla piattaforma TSP 2.0, passo per passo con animazioni.
// Pagina pubblica (non mostra dati). Contenuti in assets/js/tsp-guida-studente.js, motore in assets/js/tsp-guida.js.
require_once 'config.php';
require_once 'functions.php';
require_once 'inc/tsp_guida_vista.php';

$page_cfg['titolo'] = 'Tirocinio sulla piattaforma TSP: guida per lo studente';
require_once 'header.php';
tsp_guida_vista([
    'titolo' => 'Guida per lo studente: il tirocinio su TSP 2.0',
    'sottotitolo' => 'Dalla proposta di stage ai crediti in carriera, un passo alla volta: guarda come si fa sulla piattaforma e poi rifallo tu.',
    'colore' => '#0e7490',
    'icona' => 'fa-user-graduate',
    'script' => 'tsp-guida-studente.js',
    'altra' => ['Guida per enti e aziende', 'tsp_enti.php'],
]);
require_once 'footer.php';
