<?php
// comunicazioni_feed.php - Feed RSS delle ultime notizie visibili (?per=studenti|scuole|aziende|docenti per un solo pubblico).
// Logica: App\Portale\Endpoint\FeedComunicazioni.
require_once 'config.php';
require_once 'functions.php';

\App\Core\App::per($conn)->get(\App\Portale\Endpoint\FeedComunicazioni::class)
    ->risposta(\App\Http\Richiesta::daGlobali())
    ->emetti();
