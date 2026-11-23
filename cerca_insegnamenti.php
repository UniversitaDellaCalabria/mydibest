<?php
// cerca_insegnamenti.php - Catalogo di Ateneo per i campi "Insegnamento" dei moduli online (JSON, serve l'accesso).
// Usato da assets/js/campi-pratica.js (finestra di scelta dell'insegnamento).
// Azioni (?azione=tipi|anni|corsi|insegnamenti) e logica: App\Anagrafi\Endpoint\RicercaInsegnamenti.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

\App\Core\App::per($conn)->get(\App\Anagrafi\Endpoint\RicercaInsegnamenti::class)
    ->risposta(\App\Http\Richiesta::daGlobali())
    ->emetti();
