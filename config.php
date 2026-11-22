<?php
// Ambiente: file .env (o .env.locale), ambiente locale e ambiente di prova (AMBIENTE=prova). Prima di tutto il resto, sessione compresa.
require_once __DIR__ . '/inc/ambiente.php';

// ATTIVAZIONE OUTPUT BUFFERING: Previene l'invio prematuro di HTML al browser
// permettendo il download pulito dei file CSV e Excel.
// Nella copia di prova il buffer aggiunge a ogni pagina HTML la fascia «AMBIENTE DI PROVA» (src/Core/FasciaProva.php).
if (AMBIENTE_PROVA) {
    ob_start([\App\Core\FasciaProva::class, 'callback']);
    header('X-Robots-Tag: noindex, nofollow');
} else {
    ob_start();
}

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Imposta il fuso orario di base per il server PHP
date_default_timezone_set('Europe/Rome');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !AMBIENTE_LOCALE, // in locale si lavora in http://127.0.0.1
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// «Entra come…» dell'amministratore: se si sta guardando il portale come un'altra persona, la guardia blocca ogni modifica (inc/simulazione_guardia.php)
require_once __DIR__ . '/inc/simulazione_guardia.php';

// Previene la cache del browser sulle pagine dinamiche (protegge dallo stato sessione stale)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// HTTP Security Headers
if (!AMBIENTE_LOCALE) header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
// Il portale non usa mai questi sensori: se una pagina (o uno script esterno) li chiedesse, il browser li nega. La fotocamera resta per lo scanner QR.
header("Permissions-Policy: geolocation=(), microphone=(), payment=(), usb=(), camera=(self)");
// Content-Security-Policy (S17): gli script in linea valgono solo con il nonce di questa richiesta (csp_nonce(), inc/csp.php), non più con 'unsafe-inline'.
// Le violazioni arrivano a csp_report.php, che le scrive nel registro degli errori del server (righe «[CSP] ...»).
// Lo stile resta con 'unsafe-inline' (serve anche all'editor di testi).
// Per tornare al testo vecchio (script in linea sempre permessi) basta mettere 'unsafe-inline' al posto del nonce qui sotto.
$_csp_script = "'self' 'nonce-" . csp_nonce() . "' cdn.jsdelivr.net cdnjs.cloudflare.com";
$_csp_resto = "style-src 'self' 'unsafe-inline' cdn.jsdelivr.net cdnjs.cloudflare.com; " .
    "font-src 'self' cdnjs.cloudflare.com data:; " .
    "img-src 'self' data: blob:; " .
    "connect-src 'self'; " .
    "base-uri 'self'; object-src 'none'; ";
// Indirizzo di csp_report.php: dalla radice del portale (le pagine del pannello stanno in /admin)
$_csp_radice = preg_replace('#/admin$#', '', rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/'));
header("Content-Security-Policy: default-src 'self'; script-src " . $_csp_script . "; " . $_csp_resto . "frame-ancestors 'self'; report-uri " . $_csp_radice . "/csp_report.php");
unset($_csp_script, $_csp_resto, $_csp_radice);

// Copia di prova: se una cartella del portale porta sul NAS (/mnt/...), ci si ferma subito. Altrimenti i file di prova finirebbero tra quelli veri.
if (AMBIENTE_PROVA && ($__su_nas = \App\Core\Ambiente::cartelleSulNas(__DIR__))) {
    error_log('[Prova] cartelle sul NAS: ' . implode(', ', $__su_nas));
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    die("Ambiente di prova fermato: queste cartelle portano sul NAS e qui non si può scrivere: " . implode(', ', $__su_nas)
        . ".\nNella copia di prova devono essere cartelle normali dentro il portale (lo script crea_ambiente_prova.sh le crea così).\n");
}
unset($__su_nas);

// Le righe che iniziano con # non sono commenti validi nei file ini (servirebbe ;): si scartano prima della lettura,
// altrimenti un # con parentesi o altri simboli fa fallire la lettura di tutto il file e il sito resta senza database
$_testo_env = is_file(FILE_ENV) && is_readable(FILE_ENV) ? (string)file_get_contents(FILE_ENV) : '';
if ($_testo_env === '') error_log('[ENV] file .env mancante o vuoto: ' . FILE_ENV);
set_error_handler(static function (int $n, string $m): bool { error_log('[ENV] riga non valida in .env: ' . $m); return true; });
$_env = parse_ini_string(preg_replace('/^\s*#.*$/m', '', $_testo_env)) ?: [];
restore_error_handler();
unset($_testo_env);
$db_host = $_env['DB_HOST'] ?? 'localhost';
$db_user = $_env['DB_USER'] ?? '';
$db_pass = $_env['DB_PASS'] ?? '';
$db_name = $_env['DB_NAME'] ?? '';
unset($_env);

// Se la connessione fallisce mysqli lancia un'eccezione (prima l'avviso si nascondeva con la @): si registra e si risponde «non disponibile»
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$_errore_db = '';
try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $_errore_db = (string)$conn->connect_error;
} catch (mysqli_sql_exception $_e_db) {
    $_errore_db = $_e_db->getMessage();
}
mysqli_report(MYSQLI_REPORT_OFF);

if ($_errore_db !== '') {
    error_log('[DB] connect_error: ' . $_errore_db);
    http_response_code(503);
    die("Servizio temporaneamente non disponibile. Riprova tra poco.");
}

// Impostazioni definitive sulla connessione aperta
$conn->set_charset("utf8mb4");

// Calcola dinamicamente l'offset italiano (es. +02:00 o +01:00) e lo passa a MySQL
$offset = date('P');
$conn->query("SET time_zone = '$offset'");


// $for_update = true: da usare SOLO dentro una transazione già aperta (begin_transaction).
// Blocca le righe di 'prenotazioni' di questo turno finché la transazione non fa commit/rollback,
// così due prenotazioni concorrenti sullo stesso turno vengono serializzate invece di leggere
// lo stesso conteggio "vecchio" in parallelo (prevenzione overbooking - Fase 2).
//
// Posti occupati = somma di num_posti delle prenotazioni che tengono un posto:
// confermata, richiesta_conferma (posto offerto dalla lista d'attesa, 24h per confermare)
// e da_approvare (occupa MOMENTANEAMENTE: se rifiutata il posto si libera).
// Non occupano: in_attesa, annullata, rifiutata, scaduta. stato NULL = vecchie righe confermate.
// La query sta in src/Iscrizioni/PrenotazioneRepository::postiOccupati (la classe si carica con l'autoload di
// functions.php, che le pagine includono dopo config.php: la funzione si chiama sempre a pagina avviata).
function getPostiOccupati($conn, $turno_id, $for_update = false) {
    return \App\Core\App::per($conn)->get(\App\Iscrizioni\PrenotazioneRepository::class)->postiOccupati((int)$turno_id, (bool)$for_update);
}

?>