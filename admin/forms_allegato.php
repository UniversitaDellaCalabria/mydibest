<?php
// forms_allegato.php - MyForms: scarica un allegato di una risposta (?id=<form>&r=<risposta>&c=<n. della domanda>) oppure tutti gli allegati
// in un ZIP (?id=<form>&zip=1, con gli stessi filtri della pagina delle risposte). Permesso controllato a ogni richiesta (ServizioForms::puoGestire);
// il file non si prende mai da un percorso della richiesta: lo trova ServizioRisposte nella cartella di QUESTO form, dalla risposta salvata.
require_once 'admin_header.php';
while (ob_get_level() > 0) ob_end_clean(); // qui non serve la pagina del pannello: solo il file o un messaggio

if (!$puo_forms_vedere) nega_accesso();

$app_f = \App\Core\App::per($conn);
$sv = $app_f->get(\App\Forms\ServizioForms::class);
$risp = $app_f->get(\App\Forms\ServizioRisposte::class);
$f = ($id = (int)($_GET['id'] ?? 0)) ? $app_f->get(\App\Forms\FormRepository::class)->perId($id) : null;
if (!$f || !$sv->puoGestire($f, $utente_admin)) nega_accesso();

$non_trovato = function (): never { http_response_code(404); exit('File non trovato.'); };

// ── Tutti gli allegati in ZIP (delle risposte che passano i filtri) ──
if (isset($_GET['zip'])) {
    $filtri = \App\Forms\FiltriRisposte::da($_GET, $risp->profiliPresenti($f));
    $indietro = 'forms_risposte.php?' . http_build_query(array_merge(['id' => (int)$f['id']], array_filter($filtri, fn($v) => $v !== '')));
    if (!class_exists(\ZipArchive::class)) {
        flash_set('Su questo server non si possono creare file ZIP: gli allegati si scaricano uno alla volta dal dettaglio di ogni risposta.', 'warning');
        header('Location: ' . $indietro); exit;
    }
    $tmp = $risp->zip($f, $risp->elenco($f, $filtri));
    if ($tmp === null) {
        flash_set('Non ci sono allegati da scaricare con questi filtri.', 'warning');
        header('Location: ' . $indietro); exit;
    }
    registra_log_audit($conn, 'MyForms: allegati scaricati in ZIP', ['Form' => $f['titolo']]);
    \App\Infrastructure\Documenti\Download::invia($tmp, preg_replace('/[^\w\-]+/u', '_', $f['titolo']) . '_allegati_' . date('Y-m-d') . '.zip', 'application/zip');
}

// ── Un allegato ──
$a = $risp->allegato($f, (int)($_GET['r'] ?? 0), (int)($_GET['c'] ?? -1));
if (!$a) $non_trovato();
$ext = strtolower(pathinfo($a['percorso'], PATHINFO_EXTENSION));
$tipi = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'p7m' => 'application/pkcs7-mime'];
$nome = preg_replace('/[^\w.\- ]+/u', '_', $a['nome']) ?: 'allegato';
header('Content-Type: ' . ($tipi[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: ' . (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) ? 'inline' : 'attachment') . '; filename="' . $nome . '"');
header('Content-Length: ' . filesize($a['percorso']));
header('X-Content-Type-Options: nosniff');
readfile($a['percorso']);
exit;
