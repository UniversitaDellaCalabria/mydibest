<?php
// inc/ambiente.php - Ambiente del portale: file .env da leggere, ambiente locale e ambiente di prova (AMBIENTE=prova nel .env).
// Si carica per primo (config.php, functions.php, esci.php) PRIMA di sessione e database, per questo non usa l'autoload:
// carica a mano le due classi senza dipendenze. Senza AMBIENTE=prova nel .env non cambia nulla rispetto a prima.

require_once dirname(__DIR__) . '/src/Core/Ambiente.php';
require_once dirname(__DIR__) . '/src/Core/FasciaProva.php';

// Ambiente locale di prova (strumenti/locale): solo con il server integrato di PHP e il file .env.locale.
// Su Apache PHP_SAPI non è mai 'cli-server', quindi sul server resta tutto com'è.
if (!defined('AMBIENTE_LOCALE')) define('AMBIENTE_LOCALE', PHP_SAPI === 'cli-server' && is_file(dirname(__DIR__) . '/.env.locale'));
if (!defined('FILE_ENV')) define('FILE_ENV', AMBIENTE_LOCALE ? dirname(__DIR__) . '/.env.locale' : dirname(__DIR__) . '/.env');

// Ambiente di prova (la copia del portale in /mydibest_pp): vero solo con AMBIENTE=prova nel .env
if (!defined('AMBIENTE_PROVA')) {
    \App\Core\Ambiente::imposta(\App\Core\Ambiente::daFile(FILE_ENV));
    define('AMBIENTE_PROVA', \App\Core\Ambiente::corrente()->inProva());
}

// Cookie «uscito» (dopo «Esci» niente accesso automatico dal SSO): in prova ha un nome suo, come la sessione
if (!defined('COOKIE_USCITO')) define('COOKIE_USCITO', \App\Core\Ambiente::corrente()->nomeCookieUscito());

// Sessione: in prova il cookie si chiama MYDIBEST_PP, così le due cartelle sullo stesso sito non si scollegano a vicenda.
// Va fatto prima di ogni session_start() (un session_start già fatto lo lascia com'è).
if (AMBIENTE_PROVA && session_status() === PHP_SESSION_NONE) {
    session_name((string) \App\Core\Ambiente::corrente()->nomeSessione());
}
