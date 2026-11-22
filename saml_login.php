<?php
// config.php apre la sessione con i cookie params corretti (SameSite, Secure, HttpOnly)
require_once 'config.php';
require_once 'functions.php';

// Rate limiting: max 15 accessi alla pagina di login in 5 minuti per IP
if (!check_rate_limit($conn, 'saml_login', 15, 300)) {
    http_response_code(429);
    die("Troppi tentativi di accesso. Attendi qualche minuto e riprova.");
}


$_saml_file_env = defined('FILE_ENV') ? FILE_ENV : __DIR__ . '/.env';
$_saml_testo_env = is_file($_saml_file_env) && is_readable($_saml_file_env) ? (string)file_get_contents($_saml_file_env) : '';
$_saml_env = \App\Core\File::leggiIni((string)preg_replace('/^\s*#.*$/m', '', $_saml_testo_env)); // righe con # ignorate (vedi config.php)
unset($_saml_file_env, $_saml_testo_env);
$simplesaml_path = $_saml_env['SIMPLESAML_PATH'] ?? '/opt/simplesamlphp/lib/_autoload.php';
unset($_saml_env);

// Accesso con il SSO di Ateneo (ruoli di base, SimpleSAML, creazione/aggiornamento dell'utente, sessione): App\Auth\Saml\AccessoSso
\App\Core\App::get(\App\Auth\Saml\AccessoSso::class)->accedi(
    (string)$simplesaml_path, (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    function () { imposta_cookie_uscito(false); }
);

// Blocca open redirect: accetta solo percorsi relativi del sito (no schema http://, javascript:, né URL protocol-relative //,
// né spazi, caratteri di controllo o barre rovesciate che il browser trasformerebbe in un altro indirizzo): App\Auth\RitornoInterno
$redirect_raw = is_string($_GET['redirect'] ?? null) ? $_GET['redirect'] : '';
if (\App\Auth\RitornoInterno::valido($redirect_raw)) {
    $redirect = $redirect_raw;
} else {
    // senza una pagina di partenza si va dove ci sono i compiti della persona (il Direttore, se l'ha scelto nel profilo, al Cruscotto)
    $redirect = \App\Portale\PaginaIniziale::AREA;
    try {
        if (!empty($_SESSION['utente_id'])) {
            $utente_sso = \App\Core\App::get(\App\Auth\UtenteRepository::class)->perId((int) $_SESSION['utente_id']);
            $redirect = \App\Core\App::get(\App\Portale\PaginaIniziale::class)->dopoAccesso($utente_sso);
        }
    } catch (\Throwable $e_pi) {
        error_log('[Pagina iniziale] ' . $e_pi->getMessage());
    }
}
$redirect_js = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'); // per il link
// Per lo script: testo JavaScript con virgolette e senza caratteri che chiuderebbero il tag o l'attributo
$redirect_json = json_encode($redirect, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
// JS redirect bypassa bfcache e cache HTTP: il browser ricarica sempre la pagina dal server
ob_end_clean();
?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Accesso completato</title>
<script nonce="<?php echo csp_nonce(); ?>">window.location.replace(<?php echo $redirect_json; ?>);</script>
</head>
<body>Accesso effettuato. <a href="<?php echo $redirect_js; ?>">Clicca qui se non vieni reindirizzato.</a></body>
</html>
<?php
exit;
