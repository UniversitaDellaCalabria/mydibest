<?php
// router.php - Ambiente locale di prova: server integrato di PHP al posto di Apache.
// Fa ciò che fa .htaccess (file riservati bloccati, admin senza .php, /slug.php → area.php) e serve
// il portale su http://127.0.0.1:8080/mydibest/ come sul server. Avvio: bash strumenti/locale/avvia.sh
// Pagine di servizio: /__accesso (accesso di prova), /__email (email intercettate), /__cron (cron a mano).
// Non viene mai usato sul server: strumenti/ è bloccata dal suo .htaccess e Apache non passa di qui.

if (PHP_SAPI !== 'cli-server') exit;

$__r_radice = realpath(__DIR__ . '/../..');
$__r_uri = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Il portale sta su /mydibest/ (come sul server). Il vecchio /eventi/... e la radice rimandano lì con 301, stesso percorso e stessa query.
$__r_query = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
if ($__r_uri === '/') { header('Location: /mydibest/', true, 301); exit; }
if ($__r_uri === '/eventi' || strpos($__r_uri, '/eventi/') === 0) {
    $__r_nuovo = '/mydibest' . substr((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen('/eventi'));
    header('Location: ' . ($__r_nuovo === '/mydibest' ? '/mydibest/' : $__r_nuovo) . ($__r_query !== '' ? '?' . $__r_query : ''), true, 301); exit;
}
if ($__r_uri === '/mydibest') { header('Location: /mydibest/' . ($__r_query !== '' ? '?' . $__r_query : ''), true, 301); exit; }
if (in_array($__r_uri, ['/__accesso', '/__email', '/__cron'], true)) { require __DIR__ . '/servizio.php'; exit; }
if (strpos($__r_uri, '/mydibest/') !== 0) { http_response_code(404); exit('Il portale è su /mydibest/'); }

$__r_rel = ltrim(substr($__r_uri, strlen('/mydibest/')), '/');

// Come .htaccess: file e cartelle riservati
if (preg_match('#(^|/)\.#', $__r_rel)
    || preg_match('#^(cache|backups|database|strumenti|vendor|modelli_documenti|inc|src|tests)(/|$)#i', $__r_rel)
    || preg_match('#^((config|functions|middleware)\.php|composer\.(json|lock)|phpunit\.xml(\.dist)?)$#i', $__r_rel)
    || preg_match('#\.(save|bak|old|orig|swp|sql|log)(\.\d+)?$#i', $__r_rel)
    || preg_match('#^uploads/(convenzioni/|convenzioni_tesi/|convenzioni_compilate/|incarichi/|pratiche/|protocollo_file/|verbali/|autorizzazioni/|.*\.(php\d?|phtml|pl|py|cgi|sh|exe)$)#i', $__r_rel)) {
    http_response_code(403); exit('Accesso negato');
}
// Login SSO: in locale si usa l'accesso di prova
if ($__r_rel === 'saml_login.php') {
    header('Location: /__accesso?vai=' . urlencode('/mydibest/' . ltrim((string)($_GET['redirect'] ?? ''), '/'))); exit;
}

$__r_file = $__r_radice . '/' . $__r_rel;
if ($__r_rel === '' || is_dir($__r_file)) {
    if ($__r_rel !== '' && substr($__r_uri, -1) !== '/') { header('Location: ' . $__r_uri . '/'); exit; }
    $__r_file = rtrim($__r_file, '/') . '/index.php';
    $__r_rel = ltrim(rtrim($__r_rel, '/') . '/index.php', '/');
}
if (!is_file($__r_file)) {
    if (is_file($__r_file . '.php')) { $__r_file .= '.php'; $__r_rel .= '.php'; }
    elseif (is_file($__r_radice . '/nucleo.php')
        && preg_match('#^(?!(?:admin|assets|uploads|cache|vendor|src|inc|tests|strumenti|database|backups|modelli_documenti)/)[a-zA-Z0-9_-]+/[a-zA-Z0-9_/.-]+$#', $__r_rel)) {
        // Come la regola 3b del .htaccess: un indirizzo con una «/» che non è un file → il Nucleo (se nucleo.php c'è: cancellandolo si torna a prima)
        $__r_file = $__r_radice . '/nucleo.php'; $__r_rel = 'nucleo.php';
    }
    elseif (preg_match('#^([a-zA-Z0-9_-]+)(\.php)?/?$#', $__r_rel, $__r_m)) {
        // Pagine delle aree: /fsl.php o /fsl → area.php?slug=fsl
        $_GET['slug'] = $_REQUEST['slug'] = $__r_m[1];
        $__r_file = $__r_radice . '/area.php'; $__r_rel = 'area.php';
    } else { http_response_code(404); exit('Non trovato'); }
}

// File statici
if (strtolower(substr($__r_file, -4)) !== '.php') {
    $__r_tipi = ['css' => 'text/css', 'js' => 'text/javascript', 'json' => 'application/json', 'webmanifest' => 'application/manifest+json',
                 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
                 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
                 'pdf' => 'application/pdf', 'doc' => 'application/msword', 'html' => 'text/html; charset=utf-8', 'map' => 'application/json',
                 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'txt' => 'text/plain; charset=utf-8'];
    $__r_ext = strtolower(pathinfo($__r_file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($__r_tipi[$__r_ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($__r_file));
    readfile($__r_file);
    exit;
}

// Pagine PHP: stesse variabili che imposta Apache per /mydibest/<file>
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/mydibest/' . $__r_rel;
$_SERVER['SCRIPT_FILENAME'] = $__r_file;
chdir(dirname($__r_file));
unset($__r_uri, $__r_m, $__r_tipi, $__r_ext);
require $__r_file;
