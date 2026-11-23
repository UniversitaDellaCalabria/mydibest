<?php
// cerca_scuole.php - Ricerca nell'anagrafe delle scuole per il campo "Scuola" dei moduli (JSON).
// Dati pubblici (open data del Ministero); limite di richieste per indirizzo IP contro gli abusi.
// Parametri (?dettaglio=, ?elenco=, ?q=…) e logica: App\Anagrafi\Endpoint\RicercaScuole.
require_once 'config.php';
require_once 'functions.php';

\App\Core\App::per($conn)->get(\App\Anagrafi\Endpoint\RicercaScuole::class)
    ->risposta(\App\Http\Richiesta::daGlobali())
    ->emetti();
