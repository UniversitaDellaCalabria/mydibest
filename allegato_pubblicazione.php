<?php
// allegato_pubblicazione.php - Scarica un file di una richiesta di pubblicazione (?p=<richiesta>&a=<file>).
// Permesso controllato a ogni richiesta (ServizioPubblicazioni::fileDaScaricare): il docente che ha inviato la richiesta e chi gestisce le richieste
// (ServizioPubblicazioni::puoGestire: amministratori e abilitati al modulo «Richieste di pubblicazione» del pannello). Il file non si prende mai da un percorso della richiesta: lo
// trova il servizio dalla riga salvata, solo se sta nella cartella protetta uploads/pubblicazioni/. A chi non può vederlo si risponde «File non trovato».
require_once __DIR__ . '/middleware.php';

$app_ap = \App\Core\App::per($conn);
$non_trovato = function (): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(404);
    exit('File non trovato.');
};

$f = $app_ap->get(\App\Pubblicazioni\ServizioPubblicazioni::class)->fileDaScaricare($user_info ?? null, (int)($_GET['p'] ?? 0), (int)($_GET['a'] ?? 0));
if ($f === null) {
    $non_trovato();
}

$tipi = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$nome = preg_replace('/[^\w.\- ]+/u', '_', $f['nome']) ?: 'allegato';
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . ($tipi[$f['estensione']] ?? 'application/octet-stream'));
header('Content-Disposition: ' . (isset($tipi[$f['estensione']]) ? 'inline' : 'attachment') . '; filename="' . $nome . '"');
header('Content-Length: ' . filesize($f['percorso']));
header('X-Content-Type-Options: nosniff');
readfile($f['percorso']);
exit;
