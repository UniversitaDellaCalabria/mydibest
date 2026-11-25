<?php
// allegato_pratica.php - Scarica un allegato di una pratica (risposta del modulo ?r=n o messaggio ?e=id).
// Solo chi ha inviato la pratica, chi gestisce il modulo Didattica e i referenti del consiglio della seduta; i file stanno in uploads/pratiche/ (bloccata al web).
require_once __DIR__ . '/middleware.php';

$p = pratica($conn, (int)($_GET['p'] ?? 0));
$nega = function () { while (ob_get_level() > 0) ob_end_clean(); http_response_code(404); exit('File non trovato.'); };
if (!$p || ((int)$p['utente_id'] !== (int)$_SESSION['utente_id'] && !utente_vede_pratica($conn, $user_info, $p))) $nega();

// Anteprima dell'attestato preparato dal portale (solo chi gestisce la Didattica)
if (isset($_GET['attestato']) && utente_gestisce_didattica($conn, $user_info)) {
    $pdf_att = \App\Core\App::per($conn)->get(\App\Didattica\ServizioAttestati::class)->pdf((int)$p['id']);
    if (!$pdf_att) $nega();
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Anteprima_attestato_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$p['codice']) . '.pdf"');
    header('Content-Length: ' . strlen($pdf_att)); header('X-Content-Type-Options: nosniff');
    echo $pdf_att; exit;
}

// I due documenti di una domanda o di un attestato: ?d=domanda|attestato&v=originale|protocollato (tutti quelli che passano il controllo qui sopra),
// &v=titulus (il file ripreso da Titulus, con l'impronta controllata) e &v=apri («Apri in Titulus») solo per chi gestisce la Didattica.
// Il file lo sceglie il portale (mai un percorso dalla richiesta) e non si chiama mai Titulus per mostrare la pagina.
if (isset($_GET['d'])) {
    $rif_d = ['domanda' => \App\Protocollo\FileProtocollato::RIF_DOMANDA, 'attestato' => \App\Protocollo\FileProtocollato::RIF_ATTESTATO][(string)$_GET['d']] ?? null;
    $v_d = (string)($_GET['v'] ?? '');
    $ufficio_d = utente_gestisce_didattica($conn, $user_info);
    if (!$rif_d || !in_array($v_d, ['originale', 'protocollato', 'titulus', 'apri'], true) || (in_array($v_d, ['titulus', 'apri'], true) && !$ufficio_d)) $nega();
    $fp = \App\Core\App::per($conn)->get(\App\Protocollo\FileProtocollato::class);
    if ($v_d === 'apri') {
        $url_t = $fp->indirizzoTitulus($rif_d, (int)$p['id']);
        if ($url_t === null) $nega();
        header('Location: ' . $url_t); exit;
    }
    // chi non gestisce la Didattica vede l'attestato solo dopo il protocollo (prima c'è soltanto la firma del Direttore)
    if (!$ufficio_d && $rif_d === \App\Protocollo\FileProtocollato::RIF_ATTESTATO && !$fp->voci($rif_d, (int)$p['id'])['protocollato']['esiste']) $nega();
    $f_d = $fp->apri($rif_d, (int)$p['id'], $v_d);
    if (!$f_d) $nega();
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\-]+/', '_', $f_d['nome']) . '"');
    header('Content-Length: ' . filesize($f_d['percorso'])); header('X-Content-Type-Options: nosniff');
    readfile($f_d['percorso']); exit;
}

$file = null; $nome = 'allegato';
if (isset($_GET['r'])) {
    $r = (json_decode((string)$p['risposte_json'], true) ?: [])[(int)$_GET['r']] ?? null;
    if ($r && !empty($r['file'])) { $file = $r['file']; $nome = $r['nome_file'] ?: $nome; }
} elseif (isset($_GET['e'])) {
    $st = $conn->prepare("SELECT allegato, nome_allegato FROM pratiche_eventi WHERE id = ? AND pratica_id = ?" . (utente_gestisce_didattica($conn, $user_info) ? "" : " AND interno = 0"));
    $eid = (int)$_GET['e']; $pid = (int)$p['id'];
    $st->bind_param("ii", $eid, $pid); $st->execute();
    if ($x = $st->get_result()->fetch_assoc()) { $file = $x['allegato']; $nome = $x['nome_allegato'] ?: $nome; }
}
$base = realpath(RADICE_SITO . '/' . DIR_PRATICHE);
$percorso = $file ? realpath(RADICE_SITO . '/' . $file) : false;
if (!$percorso || !$base || strpos($percorso, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($percorso)) $nega();

$ext = strtolower(pathinfo($percorso, PATHINFO_EXTENSION));
$tipi = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'p7m' => 'application/pkcs7-mime'];
$nome = preg_replace('/[^\w.\- ]+/u', '_', $nome);
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . ($tipi[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: ' . (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) ? 'inline' : 'attachment') . '; filename="' . $nome . '"');
header('Content-Length: ' . filesize($percorso));
header('X-Content-Type-Options: nosniff');
readfile($percorso);
exit;
