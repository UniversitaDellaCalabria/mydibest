<?php
// csp_report.php - Riceve le segnalazioni della Content-Security-Policy (config.php) e le scrive nel registro degli errori del server.
// Senza sessione e senza database. Si scrive solo ciò che serve a correggere la pagina: direttiva violata, indirizzo bloccato,
// pagina e file senza parametri (niente query, niente nomi, niente email), riga. Al massimo CSP_REPORT_MAX_ORA righe all'ora.
declare(strict_types=1);

const CSP_REPORT_MAX_ORA = 300;
const CSP_REPORT_MAX_BYTE = 8192;

http_response_code(204);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    exit;
}

$corpo = file_get_contents('php://input', false, null, 0, CSP_REPORT_MAX_BYTE);
$dati = is_string($corpo) && $corpo !== '' ? json_decode($corpo, true) : null;
$r = is_array($dati) && isset($dati['csp-report']) && is_array($dati['csp-report']) ? $dati['csp-report'] : null;
if ($r === null) {
    exit;
}

// Limite orario: un file con "ora:conteggio" nella cartella cache (se non si può scrivere, non si registra nulla)
$cartella = __DIR__ . '/cache';
$file = $cartella . '/csp_report_conta.txt';
if (!is_dir($cartella) || !is_writable($cartella)) {
    exit;
}
$ora = date('YmdH');
$conta = 0;
if (is_file($file)) {
    $parti = explode(':', (string) file_get_contents($file));
    if (count($parti) === 2 && $parti[0] === $ora) {
        $conta = (int) $parti[1];
    }
}
if ($conta >= CSP_REPORT_MAX_ORA) {
    exit;
}
file_put_contents($file, $ora . ':' . ($conta + 1), LOCK_EX);

// Solo percorso (senza query né frammento, senza nome del sito): è l'unica parte dell'indirizzo utile e priva di dati personali
$senza_parametri = static function (mixed $v): string {
    $s = (string) $v;
    if ($s === '' || $s === 'inline' || $s === 'eval' || $s === 'data' || $s === 'blob' || $s === 'self') {
        return $s;
    }
    $p = parse_url($s);
    if ($p === false) {
        return '?';
    }
    $host = $p['host'] ?? '';
    $percorso = $p['path'] ?? '';
    return ($host !== '' && ($p['scheme'] ?? '') !== '' ? $host : '') . $percorso;
};
$pulisci = static fn (mixed $v): string => substr(preg_replace('/[^\x20-\x7E]/', '?', (string) $v) ?? '', 0, 160);

error_log('[CSP] ' . $pulisci($r['effective-directive'] ?? $r['violated-directive'] ?? '')
    . ' | bloccato=' . $pulisci($senza_parametri($r['blocked-uri'] ?? ''))
    . ' | pagina=' . $pulisci(parse_url((string) ($r['document-uri'] ?? ''), PHP_URL_PATH) ?: '')
    . ' | file=' . $pulisci($senza_parametri($r['source-file'] ?? ''))
    . ' | riga=' . (int) ($r['line-number'] ?? 0));
