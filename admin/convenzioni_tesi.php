<?php
// convenzioni_tesi.php - Modulo Didattica › Convenzioni di tesi: le convenzioni con enti e aziende per lo svolgimento della tesi.
//   Da seguire   iter guidato: modifiche agli articoli da valutare → protocollo in entrata (il Direttore riceve il link) → firma del Direttore → protocollo in uscita → stipulata
//   Archivio     tutte le convenzioni stipulate (anche quelle già in essere inserite a mano), ricerca, rinnovo, elenco pubblico delle aziende
// Logica in src/ConvenzioniTesi/; pagine dell'azienda: convenzione_tesi.php (link personale), richiedi_convenzione_tesi.php (pubblica), aziende_convenzionate_tesi.php (elenco).
require_once 'admin_header.php';

if (!$puo_conv_tesi) nega_accesso();

function admin_redirect($url) { vai_a($url); }

$svc = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class);
$notif = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\NotificheConvenzioniTesi::class);
$S = \App\ConvenzioniTesi\Costanti::class;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$d = fn($x) => $x ? date('d/m/Y', strtotime((string)$x)) : '—';
$autore = (string)($_SESSION['utente_nome'] ?? $_SESSION['utente_email'] ?? '');
$tab = ($_GET['tab'] ?? '') === 'archivio' ? 'archivio' : 'iter';
$url = fn(string $t = 'iter', string $extra = '') => 'convenzioni_tesi.php?p_id=' . (int)$filtro_p . '&tab=' . $t . $extra;

// ── Download dei file (solo da qui: le cartelle sono bloccate al web) ──
if (isset($_GET['file'])) {
    $ff = $svc->fileDaScaricare((int)$_GET['file'], (string)($_GET['f'] ?? 'finale'));
    while (ob_get_level() > 0) ob_end_clean();
    if (!$ff) { http_response_code(404); exit('File non trovato.'); }
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $ff['nome'] . '"'); header('Content-Length: ' . filesize($ff['percorso'])); header('X-Content-Type-Options: nosniff');
    readfile($ff['percorso']); exit;
}

// ── Azioni ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $ok = fn(string $m, string $tipo = 'success') => flash_set($m, $tipo);
    $torna = $url('iter') . '&r=' . time();
    if (isset($_POST['invita'])) {
        [$err] = $svc->avvia((string)($_POST['email'] ?? ''), (string)($_POST['azienda'] ?? ''), $S::ORIGINE_INVITO, $autore);
        if (!$err) registra_log_audit($conn, "Convenzione di tesi: azienda invitata", ["Email" => (string)($_POST['email'] ?? ''), "Azienda" => (string)($_POST['azienda'] ?? '')]);
        $ok($err ?? "Invito mandato: l'azienda riceve il link per compilare la convenzione.", $err ? 'danger' : 'success');
    } elseif (isset($_POST['salva_direttore_testo'])) {
        $errori_d = $svc->salvaDirettoreTesto((string)($_POST['titolo'] ?? ''), (string)($_POST['nome'] ?? ''), (string)($_POST['nato_a'] ?? ''), (string)($_POST['nato_il'] ?? ''));
        if (!$errori_d) registra_log_audit($conn, "Convenzione di tesi: dati del Direttore nel testo", ["Nome" => (string)($_POST['nome'] ?? '')]);
        $ok($errori_d ? implode(' ', $errori_d) : "Dati del Direttore salvati: da ora compaiono nel Word e nel PDF delle convenzioni.", $errori_d ? 'danger' : 'success');
    } elseif (isset($_POST['rimanda'])) {
        $err = $svc->rimandaLink($id);
        $ok($err ?? "Link rimandato all'azienda.", $err ? 'danger' : 'success');
    } elseif (isset($_POST['rispondi_modifiche'])) {
        $c = $svc->perId($id);
        $err = $c ? $svc->rispondiModifiche($c, (array)($_POST['m'] ?? [])) : 'Convenzione non trovata.';
        if (!$err) registra_log_audit($conn, "Convenzione di tesi: risposta alle modifiche agli articoli", ["Convenzione" => $id]);
        $ok($err ?? "Risposta mandata: l'azienda la vede e riceve l'email.", $err ? 'danger' : 'success');
    } elseif (isset($_POST['controllo']) || isset($_POST['prendi_titulus']) || isset($_POST['prendi'])) {
        // «Controlla la convenzione»: la validità decisa dall'ufficio si salva PRIMA di qualsiasi azione (la usa anche il protocollo automatico dopo la firma)
        $err_ctl = isset($_POST['valida_dal']) ? $svc->salvaControllo($id, (string)($_POST['valida_dal'] ?? ''), (string)($_POST['valida_al'] ?? '')) : null;
        if ($err_ctl !== null) {
            $ok($err_ctl, 'danger');
        } elseif (isset($_POST['controllo'])) {
            registra_log_audit($conn, "Convenzione di tesi: validità salvata", ["Convenzione" => $id, "Valida dal" => (string)($_POST['valida_dal'] ?? ''), "Valida fino al" => (string)($_POST['valida_al'] ?? '')]);
            $ok("Date di validità salvate sulla convenzione.");
        } elseif (isset($_POST['prendi_titulus'])) {
            // Il protocollo in entrata lo assegna Titulus: la convenzione firmata parte come documento in arrivo
            $es = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ProtocollaConvenzioneTesiInTitulus::class)->protocolla($id, $autore, (int)($_SESSION['utente_id'] ?? 0) ?: null);
            if (!$es['errore']) registra_log_audit($conn, "Convenzione di tesi protocollata in entrata con Titulus", ["Convenzione" => $id, "Protocollo in entrata" => $es['numero']]);
            $ok($es['errore'] ?? ("Protocollata in Titulus con il numero " . $es['numero'] . ": l'azienda è stata avvisata. " . ($es['direttore'] === null ? "Il Direttore ha ricevuto l'email con il link per firmare." : $es['direttore'])), $es['errore'] ? 'danger' : ($es['direttore'] === null ? 'success' : 'warning'));
        } else {
            // Numero scritto a mano: con la stessa prenotazione dell'invio, così non si scrive un numero mentre Titulus sta protocollando lo stesso documento
            $es = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ProtocollaConvenzioneTesiInTitulus::class)->protocolloAMano($id, (string)($_POST['prot_ingresso'] ?? ''), (string)($_POST['prot_ingresso_data'] ?? ''), $autore);
            if (!$es['errore']) registra_log_audit($conn, "Convenzione di tesi protocollata in entrata", ["Convenzione" => $id, "Protocollo in entrata" => (string)($_POST['prot_ingresso'] ?? '')]);
            $ok($es['errore'] ?? ("Protocollo in entrata registrato: l'azienda è stata avvisata. " . ($es['direttore'] === null ? "Il Direttore ha ricevuto l'email con il link per firmare." : $es['direttore'])), $es['errore'] ? 'danger' : ($es['direttore'] === null ? 'success' : 'warning'));
        }
    } elseif (isset($_POST['incerto_chiudi']) || isset($_POST['respinto'])) {
        // Esito incerto o documento respinto in Titulus: lo dichiara l'ufficio dopo aver cercato il documento in Titulus; poi si può inviare di nuovo
        $respinto_ic = isset($_POST['respinto']);
        $c_ic = $svc->perId($id);
        if ($c_ic) {
            $invio_ic = \App\Core\App::per($conn)->get(\App\Protocollo\InvioProtocollo::class);
            $fase_ic = (string)($_POST['fase'] ?? '');
            $rif_ic = $fase_ic === 'ingresso' ? $svc->rifIngresso($c_ic) : $svc->rifDefinitivo($c_ic);
            $chi_ic = (string)($_SESSION['utente_email'] ?? 'ufficio');
            $err_ic = $respinto_ic
                ? $invio_ic->respinto($rif_ic, $id, 'Respinto in Titulus, dichiarato da ' . $chi_ic, (int)($_SESSION['utente_id'] ?? 0) ?: null)
                : $invio_ic->chiudiIncerto($rif_ic, $id, 'Cercato in Titulus da ' . $chi_ic . ': il documento non c\'è', (int)($_SESSION['utente_id'] ?? 0) ?: null);
            if ($err_ic === null) registra_log_audit($conn, $respinto_ic ? "Documento di tesi respinto in Titulus: si può rimandare" : "Esito incerto verificato in Titulus: documento di tesi non protocollato", ["Convenzione" => $id, "Fase" => $fase_ic]);
            $ok($err_ic ?? ($respinto_ic ? "Registrato: in Titulus il documento è stato respinto. Ora puoi premere di nuovo «Invia al protocollo»." : "Registrato: hai verificato che in Titulus il documento non c'è. Ora puoi premere di nuovo «Invia al protocollo»."), $err_ic ? 'danger' : 'success');
        }
    } elseif (isset($_POST['riprova_originale'])) {
        $r_ro = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\OriginaleDaTitulus::class)->scarica($id);
        if ($r_ro['ok']) registra_log_audit($conn, "File originale di tesi ripreso da Titulus", ["Convenzione" => $id]);
        $ok($r_ro['ok'] ? "File originale ripreso da Titulus e conservato." : "Da Titulus il file non arriva: " . $r_ro['messaggio'], $r_ro['ok'] ? 'success' : 'warning');
        $torna = $url('archivio') . '&r=' . time() . '#cv' . $id;
    } elseif (isset($_POST['carica_protocollato'])) {
        $pdf_cp = '';
        if (($_FILES['protocollato']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$_FILES['protocollato']['tmp_name'])) $pdf_cp = (string)file_get_contents($_FILES['protocollato']['tmp_name']);
        $err_cp = $pdf_cp === '' ? 'Scegli il PDF del documento protocollato.' : $svc->caricaProtocollato($id, $pdf_cp);
        if ($err_cp === null) registra_log_audit($conn, "Documento di tesi protocollato caricato dall'ufficio", ["Convenzione" => $id]);
        $ok($err_cp ?? "Documento protocollato caricato: è quello che vedono l'azienda e l'archivio. Il PDF firmato dal Direttore non è stato toccato.", $err_cp ? 'danger' : 'success');
        $torna = $url('archivio') . '&r=' . time() . '#cv' . $id;
    } elseif (isset($_POST['ripeti_avvisi'])) {
        $rep_av = $svc->avvisaStipula($id);
        if ($rep_av !== null) registra_log_audit($conn, "Avvisi della stipula ripetuti (tesi)", ["Convenzione" => $id, "Azienda" => $rep_av['azienda'] ? 1 : 0, "Studenti" => $rep_av['studenti'], "Relatori" => $rep_av['relatori'], "Da rifare" => $rep_av['restano']]);
        $ok($rep_av === null ? "Gli avvisi non sono partiti: la convenzione non è stipulata o un'altra operazione è in corso. Riprova tra poco." : ("Avvisi mandati adesso: " . ($rep_av['azienda'] ? 'azienda, ' : '') . $rep_av['studenti'] . " studenti, " . $rep_av['relatori'] . " relatori" . ($rep_av['restano'] ? "; " . $rep_av['restano'] . " non sono partiti (riprova)." : ". Chi li aveva già ricevuti non li riceve di nuovo.")), $rep_av === null || $rep_av['restano'] ? 'warning' : 'success');
        $torna = $url('archivio') . '&r=' . time() . '#cv' . $id;
    } elseif (isset($_POST['rifiuta'])) {
        $err = $svc->rifiuta($id, (string)($_POST['motivo'] ?? ''));
        if (!$err) registra_log_audit($conn, "Convenzione di tesi rifiutata", ["Convenzione" => $id]);
        $ok($err ?? "Rifiutata: l'azienda ha ricevuto il motivo per email e può caricare un altro file.", $err ? 'danger' : 'warning');
    } elseif (isset($_POST['invia_direttore'])) {
        $err = $svc->inviaDirettore($id);
        if (!$err) registra_log_audit($conn, "Convenzione di tesi mandata al Direttore per la firma", ["Convenzione" => $id]);
        $ok($err ?? "Mandata al Direttore: ha ricevuto l'email con il link per firmare.", $err ? 'danger' : 'success');
    } elseif (isset($_POST['completa_titulus'])) {
        // Protocollo definitivo: il numero lo assegna Titulus (PDF firmato dal Direttore), la convenzione si registra come stipulata con le date indicate
        $pdf = '';
        if (($_FILES['file_protocollato']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$_FILES['file_protocollato']['tmp_name'])) $pdf = (string)file_get_contents($_FILES['file_protocollato']['tmp_name']);
        $es_d = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ProtocollaDefinitivaTesiInTitulus::class)->protocolla($id, $_POST, $pdf, (int)($_SESSION['utente_id'] ?? 0) ?: null);
        if (!$es_d['errore']) registra_log_audit($conn, "Convenzione di tesi stipulata con protocollo Titulus", ["Convenzione" => $id, "Protocollo" => $es_d['numero']]);
        $ok($es_d['errore'] ?? ("Protocollata in Titulus con il numero " . $es_d['numero'] . ": convenzione stipulata, è in archivio; l'azienda, gli studenti e i relatori hanno ricevuto l'avviso e, se l'azienda ha dato il consenso, compare nell'elenco pubblico. Il file di Titulus si vede in Archivio."), $es_d['errore'] ? 'danger' : 'success');
    } elseif (isset($_POST['completa'])) {
        $pdf = '';
        if (($_FILES['file_protocollato']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$_FILES['file_protocollato']['tmp_name'])) $pdf = (string)file_get_contents($_FILES['file_protocollato']['tmp_name']);
        // Numero scritto a mano: con la prenotazione dell'invio (mai un secondo numero dopo quello di Titulus, né mentre Titulus sta protocollando)
        $err = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ProtocollaDefinitivaTesiInTitulus::class)->completaAMano($id, $_POST, $pdf)['errore'];
        if (!$err) registra_log_audit($conn, "Convenzione di tesi stipulata", ["Convenzione" => $id, "Protocollo" => (string)($_POST['protocollo'] ?? '')]);
        $ok($err ?? "Convenzione stipulata: è in archivio" . "; l'azienda, gli studenti e i relatori hanno ricevuto l'avviso e, se l'azienda ha dato il consenso, compare nell'elenco pubblico.", $err ? 'danger' : 'success');
    } elseif (isset($_POST['archivia'])) {
        $pdf = '';
        if (($_FILES['file_convenzione']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string)$_FILES['file_convenzione']['tmp_name'])) $pdf = (string)file_get_contents($_FILES['file_convenzione']['tmp_name']);
        [$errori, $nuovo] = $svc->archivia($_POST, $pdf, $autore, $id);
        if (!$errori) registra_log_audit($conn, $id ? "Convenzione di tesi dell'archivio modificata" : "Convenzione di tesi già in essere inserita in archivio", ["Convenzione" => $nuovo, "Azienda" => (string)($_POST['ragione_sociale'] ?? '')]);
        $ok($errori ? implode(' ', $errori) : ($id ? "Convenzione aggiornata." : "Convenzione inserita in archivio."), $errori ? 'danger' : 'success');
        $torna = $url('archivio') . ($errori && $id ? '&mod=' . $id : '') . '&r=' . time();
    } elseif (isset($_POST['elimina'])) {
        $svc->elimina($id);
        registra_log_audit($conn, "Convenzione di tesi eliminata", ["Convenzione" => $id]);
        $ok("Convenzione eliminata con i suoi file.", 'warning');
        $torna = $url('archivio') . '&r=' . time();
    } elseif (isset($_POST['rinnova'])) {
        [$err, $tk] = $svc->rinnova($id);
        $ok($err ?? "Rinnovo avviato: l'azienda ha ricevuto il link con i dati già inseriti.", $err ? 'danger' : 'success');
        $torna = $url('archivio') . '&r=' . time();
    }
    admin_redirect($torna);
}

$conteggi = $svc->conteggi();
$titulus_tesi_ok = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ProtocollaConvenzioneTesiInTitulus::class)->disponibile(); // collegamento a Titulus pronto e regola per le tesi
$titulus_def_tesi_ok = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ProtocollaDefinitivaTesiInTitulus::class)->disponibile(); // idem per il protocollo definitivo
$gruppi = [
    'modifiche' => $svc->inStati([$S::MODIFICHE]), 'caricata' => $svc->inStati([$S::CARICATA]), 'dirfirma' => $svc->inStati([$S::IN_CARICO, $S::DA_FIRMARE]), 'firmata' => $svc->inStati([$S::FIRMATA]),
    'attesa' => $svc->inStati([$S::INVITATA, $S::COMPILATA, $S::RISPOSTA, $S::RIFIUTATA]),
];
$n_da_fare = $svc->daFare();
$dir_firma = $svc->chiFirma();
$dir_testo = $svc->datiDirettoreTesto();
$standard = $svc->articoliStandard();
$oggi = date('Y-m-d');
$val_dal = $oggi; $val_al = date('Y-m-d', strtotime('+' . $S::DURATA_ANNI . ' years -1 day'));
$nome_az = fn(array $c) => (string)($c['azienda'] ?: $c['email']);
// Com'è l'invio a Titulus di quel documento: libero | in_invio | incerto | in_approvazione | precedente | protocollato (senza Titulus configurato: sempre libero)
try { $invio_cv = \App\Core\App::per($conn)->get(\App\Protocollo\InvioProtocollo::class); } catch (\Throwable) { $invio_cv = null; }
$stato_invio = function (array $c, string $fase) use ($invio_cv, $svc): array {
    $libero = ['stato' => 'libero', 'numero' => '', 'il' => ''];
    if ($invio_cv === null) return $libero;
    try {
        return $fase === 'ingresso'
            ? $invio_cv->stato($svc->rifIngresso($c), (int)$c['id'], \App\ConvenzioniTesi\ProtocollaConvenzioneTesiInTitulus::RIF, (string)($c['firmata_il'] ?? ''))
            : $invio_cv->stato($svc->rifDefinitivo($c), (int)$c['id'], \App\ConvenzioniTesi\ProtocollaDefinitivaTesiInTitulus::RIF, (string)($c['firmata_direttore_il'] ?? ''));
    } catch (\Throwable) { return $libero; }
};
// Avviso di un invio con esito incerto, in approvazione o in corso (il riquadro è lo stesso di tutti i punti con «Protocolla in Titulus»)
require_once __DIR__ . '/../inc/parziali/protocollo_stato.php';
$avviso_invio = function (array $st, array $c, string $fase): void {
    protocollo_stato_riquadro($st, 'incerto_chiudi', 'respinto', '1', ['id' => (string)(int)$c['id'], 'fase' => $fase]);
};
?>
<?php
echo pn_testata('Convenzioni di tesi', 'fa-handshake', "Le convenzioni con enti e aziende che accolgono i nostri studenti per la tesi: l'azienda compila online (senza registrarsi), tu protocolli, il Direttore firma, poi va in archivio e nell'elenco pubblico.",
    '<a class="btn btn-outline-secondary btn-sm" href="../aziende_convenzionate_tesi.php" target="_blank" rel="noopener"><i class="fa fa-list me-1" aria-hidden="true"></i>Elenco pubblico</a> <a class="btn btn-outline-secondary btn-sm" href="email.php?p_id=' . (int)$filtro_p . '&amp;gruppo=Convenzioni%20di%20tesi"><i class="fa fa-envelope-open-text me-1" aria-hidden="true"></i>Testi delle email</a>');
echo pn_schede([
    'iter' => ['href' => $url('iter'), 'testo' => 'Da seguire', 'icona' => 'fa-route', 'numero' => $n_da_fare, 'avviso' => true, 'titolo' => 'Convenzioni che aspettano te'],
    'archivio' => ['href' => $url('archivio'), 'testo' => 'Archivio e aziende convenzionate', 'icona' => 'fa-box-archive'],
], $tab, 'Sezioni delle convenzioni di tesi');
?>

<?php if ($tab === 'iter'): ?>
<section class="scu-sez" id="iter">
    <ol class="cvt-passi">
        <li class="cvt-altri"><span class="n">1</span><span class="chi">L'azienda</span><h3>Compila online</h3><p>Dati e, se vuole, modifiche agli articoli. Senza registrarsi.</p><a class="conta<?php echo $gruppi['attesa'] ? '' : ' zero'; ?>" href="#fase-attesa"><?php echo count($gruppi['attesa']); ?> in corso</a></li>
        <li class="cvt-tuo"><span class="n">2</span><span class="chi">Tu</span><h3>Valuti le modifiche</h3><p>Solo se l'azienda le chiede: accetti, rifiuti o rispondi con un testo.</p><a class="conta<?php echo $gruppi['modifiche'] ? '' : ' zero'; ?>" href="#fase-modifiche"><?php echo count($gruppi['modifiche']); ?> da valutare</a></li>
        <li class="cvt-altri"><span class="n">3</span><span class="chi">L'azienda</span><h3>Scarica, firma e ricarica</h3><p>Il PDF compilato, firmato in PAdES dal legale rappresentante.</p></li>
        <li class="cvt-tuo"><span class="n">4</span><span class="chi">Tu · Protocollo</span><h3>Protocollo in entrata</h3><p>Scarichi, protocolli, scrivi il numero: il Direttore riceve da solo l'email.</p><a class="conta<?php echo $gruppi['caricata'] ? '' : ' zero'; ?>" href="#fase-caricata"><?php echo count($gruppi['caricata']); ?> da fare</a></li>
        <li class="cvt-dir"><span class="n">5</span><span class="chi">Il Direttore</span><h3>Firma dal link</h3><p>Il PDF firmato torna qui da solo.</p><a class="conta<?php echo $gruppi['dirfirma'] ? '' : ' zero'; ?>" href="#fase-dirfirma"><?php echo count($gruppi['dirfirma']); ?> dal Direttore</a></li>
        <li class="cvt-tuo"><span class="n">6</span><span class="chi">Tu · Protocollo</span><h3>Protocollo in uscita</h3><p>Numero e validità: stipulata, in archivio e nell'elenco pubblico.</p><a class="conta<?php echo $gruppi['firmata'] ? '' : ' zero'; ?>" href="#fase-firmata"><?php echo count($gruppi['firmata']); ?> da fare</a></li>
    </ol>

    <div class="row g-3 mb-2">
        <div class="col-lg-7"><form method="POST" class="border rounded p-3 bg-white h-100"><?php csrf_field(); ?>
            <h3 class="h6 fw-bold"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invita un'azienda</h3>
            <div class="row g-2"><div class="col-sm-5"><label class="form-label small mb-0" for="invAz">Ente o azienda</label><input class="form-control form-control-sm" id="invAz" name="azienda" maxlength="200" placeholder="facoltativo"></div>
                <div class="col-sm-5"><label class="form-label small mb-0" for="invEm">Email *</label><input type="email" class="form-control form-control-sm" id="invEm" name="email" maxlength="255" required></div>
                <div class="col-sm-2 d-flex align-items-end"><button class="btn btn-danger btn-sm fw-bold w-100" type="submit" name="invita" value="1">Invia il link</button></div></div>
            <div class="form-text">L'azienda riceve un link personale per compilare la convenzione. Se ne ha già una aperta riceve di nuovo il link di quella.</div></form></div>
        <div class="col-lg-5"><div class="border rounded p-3 bg-white h-100">
            <h3 class="h6 fw-bold"><i class="fa fa-link me-1" aria-hidden="true"></i>Pagina per le aziende</h3>
            <p class="small mb-1">Da dare a chi chiede la convenzione (si richiede da solo, con l'email):</p>
            <input class="form-control form-control-sm font-monospace" readonly value="<?php echo $h(\App\Core\App::get(\App\Core\Sito::class)->urlBase() . '/richiedi_convenzione_tesi.php'); ?>" data-seleziona aria-label="Indirizzo della pagina pubblica">
            <div class="small mt-2"><i class="fa fa-user-tie me-1" aria-hidden="true"></i>Firma: <strong><?php echo $h($dir_firma['nome'] ?: 'Direttore non indicato'); ?></strong>
                <?php if ($dir_firma['email'] === ''): ?> <a href="uffici.php?p_id=<?php echo (int)$filtro_p; ?>" class="fw-bold">Indicalo in Uffici → Direzione</a><?php endif; ?></div>
        </div></div>
    </div>

    <details class="border rounded p-2 mb-3 bg-white"<?php echo $dir_testo['stato'] !== 'a_posto' ? ' open' : ''; ?> id="direttoreTesto">
        <summary class="fw-bold small"><i class="fa fa-user-tie me-1" aria-hidden="true"></i>Il Direttore nel testo della convenzione
            <?php echo ['mai_indicato' => ' · <span class="badge bg-warning text-dark">il testo usa ancora il modello com\'è</span>', 'a_posto' => ' · <span class="badge bg-success">a posto</span>', 'da_aggiornare' => ' · <span class="badge bg-danger">da aggiornare: il Direttore è cambiato</span>'][$dir_testo['stato']]; ?></summary>
        <form method="POST" class="row g-2 mt-1"><?php csrf_field(); ?>
            <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="dtTit">Titolo</label><select class="form-select form-select-sm" id="dtTit" name="titolo"><?php foreach ($S::TITOLI_DIRETTORE as $t_d): ?><option<?php echo $dir_testo['titolo'] === $t_d ? ' selected' : ''; ?>><?php echo $h($t_d); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label small fw-bold mb-0" for="dtNome">Nome e cognome come nel testo</label><input class="form-control form-control-sm" id="dtNome" name="nome" required maxlength="150" value="<?php echo $h($dir_testo['stato'] === 'da_aggiornare' ? $dir_testo['in_carica'] : ($dir_testo['nome'] ?: $dir_testo['in_carica'])); ?>"></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0" for="dtLuogo">Nato a</label><input class="form-control form-control-sm" id="dtLuogo" name="nato_a" required maxlength="100" placeholder="es. Lachen (CH)" value="<?php echo $h($dir_testo['stato'] === 'da_aggiornare' ? '' : $dir_testo['nato_a']); ?>"></div>
            <div class="col-md-2"><label class="form-label small fw-bold mb-0" for="dtData">Il</label><input type="date" class="form-control form-control-sm" id="dtData" name="nato_il" required value="<?php echo $h($dir_testo['stato'] === 'da_aggiornare' ? '' : $dir_testo['nato_il']); ?>"></div>
            <div class="col-md-1 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100" type="submit" name="salva_direttore_testo" value="1">Salva</button></div>
            <div class="form-text">Nel modello il Direttore è «rappresentato dal Direttore, <em>titolo nome</em>, nato a <em>luogo</em> il <em>data</em>, domiciliato per la carica…». Il Direttore in carica (Persone e permessi → Uffici e persone → Direzione) è <strong><?php echo $h($dir_testo['in_carica'] ?: 'non indicato'); ?></strong>. Finché non salvi questi dati il Word e il PDF usano il testo del modello com'è; se il Direttore cambia, il nuovo nome compare da solo e luogo e data restano puntini finché non li aggiorni qui.</div>
        </form>
    </details>

    <?php if (!$svc->modelloDisponibile()): ?><div class="alert alert-danger small">Manca il modello Word (<code><?php echo $h($S::MODELLO); ?></code>): le aziende non possono scaricare la convenzione.</div><?php endif; ?>

    <!-- 2 · Modifiche da valutare -->
    <div class="cvt-fase cvt-tuo" id="fase-modifiche"><span class="chi">Tocca a te</span><h2>② Modifiche agli articoli da valutare (<?php echo count($gruppi['modifiche']); ?>)</h2></div>
    <?php if (!$gruppi['modifiche']): ?><p class="cvt-vuoto">Nessuna modifica da valutare.</p><?php endif; ?>
    <?php foreach ($gruppi['modifiche'] as $c): $idc = (int)$c['id']; ?>
    <div class="cvt-card cvt-tuo">
        <div class="cvt-az"><strong><?php echo $h($nome_az($c)); ?></strong><span class="text-secondary small"><?php echo $h($c['email']); ?> · aggiornata il <?php echo $d($c['aggiornata_il']); ?></span></div>
        <?php if (!empty($c['nota_azienda'])): ?><p class="small"><strong>Nota dell'azienda:</strong> <?php echo nl2br($h($c['nota_azienda'])); ?></p><?php endif; ?>
        <form method="POST"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo $idc; ?>">
        <?php foreach ($svc->modifiche($c) as $n => $m): if ($m['stato'] !== 'proposta') continue; ?>
            <div class="cvt-art">
                <div class="fw-bold mb-2">Art. <?php echo (int)$n; ?> – <?php echo $h($standard[$n]['titolo'] ?? ''); ?></div>
                <div class="row g-2">
                    <div class="col-md-6"><div class="small text-secondary mb-1">Testo standard</div><pre><?php echo $h($standard[$n]['testo'] ?? ''); ?></pre></div>
                    <div class="col-md-6"><div class="small text-secondary mb-1">Proposta dell'azienda</div><pre><?php echo $h($m['testo']); ?></pre></div>
                </div>
                <div class="mt-2 d-flex flex-wrap gap-3 small">
                    <?php foreach (['accetta' => 'Accetto', 'rifiuta' => 'Rifiuto', 'controproposta' => 'Rispondo con un altro testo'] as $az => $et): ?>
                        <div class="form-check"><input class="form-check-input" type="radio" name="m[<?php echo (int)$n; ?>][azione]" id="m<?php echo $idc . '_' . (int)$n . $az; ?>" value="<?php echo $az; ?>" required><label class="form-check-label fw-bold" for="m<?php echo $idc . '_' . (int)$n . $az; ?>"><?php echo $h($et); ?></label></div>
                    <?php endforeach; ?>
                </div>
                <label for="mt<?php echo $idc . '_' . (int)$n; ?>" class="form-label small mb-0 mt-2">Testo da proporre (solo se rispondi con un altro testo)</label>
                <textarea class="form-control form-control-sm" id="mt<?php echo $idc . '_' . (int)$n; ?>" name="m[<?php echo (int)$n; ?>][testo]" rows="3"><?php echo $h($m['testo']); ?></textarea>
                <label for="mr<?php echo $idc . '_' . (int)$n; ?>" class="form-label small mb-0 mt-2">Nota per l'azienda (obbligatoria se rifiuti)</label>
                <input class="form-control form-control-sm" id="mr<?php echo $idc . '_' . (int)$n; ?>" name="m[<?php echo (int)$n; ?>][risposta]" maxlength="500" placeholder="es. clausola standard di Ateneo, non modificabile">
            </div>
        <?php endforeach; ?>
        <button type="submit" name="rispondi_modifiche" value="1" class="btn btn-danger fw-bold"><i class="fa fa-reply me-1" aria-hidden="true"></i>Manda la risposta all'azienda</button>
        <div class="cvt-esito mt-2"><i class="fa fa-bolt me-1" aria-hidden="true"></i>Se accetti tutto l'azienda può subito scaricare e firmare; altrimenti rivede il testo e torna da te.</div>
        </form>
    </div>
    <?php endforeach; ?>

    <!-- 4 · Controlla la convenzione (presa in carico) -->
    <div class="cvt-fase cvt-tuo" id="fase-caricata"><span class="chi">Tocca a te</span><h2>④ <?php echo $h($S::PASSI[1]); ?> (<?php echo count($gruppi['caricata']); ?>)</h2></div>
    <?php if (!$gruppi['caricata']): ?><p class="cvt-vuoto">Nessuna convenzione da controllare.</p><?php endif; ?>
    <?php foreach ($gruppi['caricata'] as $c): $idc = (int)$c['id']; $dc = $svc->dati($c);
        $st_in = $stato_invio($c, 'ingresso'); $altra = $svc->altraConvenzioneValida($c); $stud = $svc->studentiCollegati($idc);
        [$v_dal, $v_al] = [(string)($c['valida_dal'] ?: $val_dal), (string)($c['valida_al'] ?: $val_al)]; ?>
    <div class="cvt-card cvt-tuo">
        <div class="cvt-az"><strong><?php echo $h($nome_az($c)); ?></strong><span class="text-secondary small"><?php echo $h(($dc['comune'] ?? '') . (!empty($dc['piva']) ? ' · P. IVA ' . $dc['piva'] : '') . (!empty($dc['pec']) ? ' · PEC ' . $dc['pec'] : '')); ?><?php echo $svc->modifiche($c) ? ' · con articoli modificati' : ''; ?></span></div>
        <h3 class="h6 fw-bold mt-2 mb-1"><i class="fa fa-clipboard-check me-1" aria-hidden="true"></i>Controlla la convenzione</h3>
        <?php $avviso_invio($st_in, $c, 'ingresso'); ?>
        <?php if ($altra): ?><div class="alert alert-warning py-2 px-3 small mb-2" role="note"><i class="fa fa-copy me-1" aria-hidden="true"></i><strong>Questa azienda ha già una convenzione stipulata e valida</strong> (protocollo <?php echo $h($altra['protocollo']); ?>, fino al <?php echo $d($altra['valida_al']); ?>; trovata dal codice fiscale o dalla partita IVA). Controlla in <a href="<?php echo $h($url('archivio', '&q=' . urlencode((string)$altra['azienda']))); ?>">Archivio</a> se serve davvero un'altra convenzione.</div><?php endif; ?>
        <form method="POST" class="cvt-controlla" id="controlla<?php echo $idc; ?>"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo $idc; ?>">
        <ol class="cvt-azioni">
            <li><div><strong>PDF firmato dall'azienda</strong><div class="mt-1"><a class="btn btn-sm btn-outline-danger" href="<?php echo $h($url('iter', '&file=' . $idc . '&f=azienda')); ?>" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>PDF della convenzione firmata</a></div>
                <div class="text-secondary small mt-1">Firmato da <?php echo $h($c['firmatario'] ?: 'il legale rappresentante'); ?> il <?php echo $d($c['firmata_il']); ?>.</div>
                <?php if (($c['avviso_firma_ingresso'] ?? '') !== ''): ?><div class="alert alert-warning py-1 px-2 mt-1 mb-0 small" role="note"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><strong>Da controllare:</strong> <?php echo $h($c['avviso_firma_ingresso']); ?> <span class="text-secondary">Con un avviso il protocollo automatico dopo la firma del Direttore non parte: lo decidi tu.</span></div>
                <?php else: ?><div class="small text-success mt-1"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>La firma non ha dato avvisi.</div><?php endif; ?></div></li>
            <li><div><strong>Studenti collegati</strong>
                <?php if (!$stud): ?><div class="text-secondary small mt-1">Nessuno studente ha chiesto questa convenzione dalla sua pratica: alla stipula è avvisata solo l'azienda.</div>
                <?php else: ?><ul class="small mb-0 mt-1 ps-3"><?php foreach ($stud as $s_c): ?><li><?php echo $h($s_c['nome'] ?: $s_c['email']); ?> <span class="text-secondary">· pratica <?php echo $h($s_c['codice']); ?></span></li><?php endforeach; ?></ul>
                <div class="text-secondary small">Alla stipula ricevono l'avviso, con i relatori delle loro pratiche.</div><?php endif; ?></div></li>
            <li><div><strong>Per quanto vale la convenzione</strong> <span class="text-secondary small">(si salva sulla convenzione e la usa anche il protocollo automatico dopo la firma; l'art. 10 del modello dice 5 anni)</span>
                <div class="row g-2 mt-1">
                    <div class="col-sm-3"><label class="form-label small mb-0" for="vd<?php echo $idc; ?>">Valida dal</label><input id="vd<?php echo $idc; ?>" type="date" name="valida_dal" class="form-control form-control-sm" value="<?php echo $h($v_dal); ?>"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0" for="va<?php echo $idc; ?>">Valida fino al</label><input id="va<?php echo $idc; ?>" type="date" name="valida_al" class="form-control form-control-sm" value="<?php echo $h($v_al); ?>"></div>
                    <div class="col-sm-6 d-flex align-items-end"><button type="submit" name="controllo" value="1" formnovalidate class="btn btn-sm btn-outline-secondary"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva le date</button></div></div></div></li>
            <li><div><strong>Che cosa fai</strong>
                <div class="d-flex flex-wrap gap-2 mt-1">
                    <?php if ($titulus_tesi_ok): ?><button type="submit" name="prendi_titulus" value="1" formnovalidate class="btn btn-danger fw-bold" data-confirm="Mandare la convenzione a Titulus? Il protocollo si assegna subito e non si annulla da qui. Poi parte l'invio al Direttore."><i class="fa fa-stamp me-1" aria-hidden="true"></i>Invia al protocollo</button><?php endif; ?></div>
                <div class="row g-2 mt-1 align-items-end">
                    <div class="col-sm-4"><label for="c-prot-ingresso<?php echo $idc; ?>" class="form-label small mb-0">N. protocollo in entrata (a mano)</label><input id="c-prot-ingresso<?php echo $idc; ?>" name="prot_ingresso" class="form-control form-control-sm" maxlength="80" placeholder="es. 1234"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0">Data</label><input aria-label="Data" type="date" name="prot_ingresso_data" class="form-control form-control-sm" value="<?php echo $oggi; ?>"></div>
                    <div class="col-sm-5"><button type="submit" name="prendi" value="1" formnovalidate class="btn btn-outline-danger fw-bold w-100"><i class="fa fa-pen me-1" aria-hidden="true"></i>Registra il numero a mano</button></div></div>
                <div class="form-text"><?php echo $titulus_tesi_ok ? '«Invia al protocollo» prende il numero da Titulus; «Registra il numero a mano» è per chi ha già protocollato nel sistema di Ateneo.' : 'Protocolla la convenzione nel sistema di protocollo di Ateneo, poi scrivi qui numero e data.'; ?></div></div></li>
        </ol>
        </form>
        <div class="cvt-esito"><i class="fa fa-bolt me-1" aria-hidden="true"></i>Appena il protocollo in entrata è registrato: l'azienda riceve l'avviso e <strong><?php echo $h($dir_firma['nome'] ?: 'il Direttore'); ?></strong> riceve l'email con il link per firmare. Non devi ricaricare il file.</div>
        <details class="mt-2"><summary class="small fw-bold text-danger" style="cursor:pointer;">Rimanda all'azienda: qualcosa non va, con un motivo</summary>
            <form method="POST" class="mt-1" style="max-width:420px;"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo $idc; ?>">
                <textarea name="motivo" class="form-control form-control-sm mb-1" rows="2" maxlength="500" required placeholder="Es. firma mancante, dati del rappresentante errati…" aria-label="Motivo del rifiuto"></textarea>
                <button type="submit" name="rifiuta" value="1" class="btn btn-sm btn-outline-danger">Rimanda all'azienda e avvisala</button></form></details>
    </div>
    <?php endforeach; ?>

    <!-- 6 · Firmate dal Direttore -->
    <div class="cvt-fase cvt-tuo" id="fase-firmata"><span class="chi">Tocca a te</span><h2>⑥ <?php echo $h($S::PASSI[3]); ?> (<?php echo count($gruppi['firmata']); ?>)</h2></div>
    <?php if (!$gruppi['firmata']): ?><p class="cvt-vuoto">Nessuna convenzione in attesa del protocollo definitivo.</p><?php endif; ?>
    <?php foreach ($gruppi['firmata'] as $c): $idc = (int)$c['id']; $st_def = $stato_invio($c, 'definitivo'); [$v_dal, $v_al] = [(string)($c['valida_dal'] ?: $val_dal), (string)($c['valida_al'] ?: $val_al)]; ?>
    <div class="cvt-card cvt-tuo">
        <div class="cvt-az"><strong><?php echo $h($nome_az($c)); ?></strong><span class="text-secondary small">firmata dal Direttore il <?php echo $d($c['firmata_direttore_il']); ?> · protocollo in entrata <?php echo $h($c['protocollo_ingresso'] ?: '—'); ?></span></div>
        <?php $avviso_invio($st_def, $c, 'definitivo'); ?>
        <?php if (($c['protocollo_errore'] ?? '') !== ''): ?><div class="alert alert-danger py-2 px-3 small mb-2" role="alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo $h($c['protocollo_errore']); ?> <span class="text-secondary">La firma del Direttore è salvata: decidi tu con «Invia al protocollo e registra» o con il numero a mano.</span></div><?php endif; ?>
        <ol class="cvt-azioni">
            <li><div><strong>Scarica il PDF firmato dal Direttore</strong><div class="mt-1"><a class="btn btn-sm btn-outline-success" href="<?php echo $h($url('iter', '&file=' . $idc . '&f=direttore')); ?>" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>PDF firmato dal Direttore</a></div>
                <?php if (($c['avviso_firma'] ?? '') !== ''): ?><div class="alert alert-warning py-1 px-2 mt-1 mb-0 small" role="note"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><strong>Da controllare:</strong> <?php echo $h($c['avviso_firma']); ?></div><?php endif; ?></div></li>
            <li><div><strong>Protocollalo</strong> in uscita nel sistema di Ateneo, oppure lascia che lo faccia Titulus dal pulsante qui sotto.</div></li>
            <li><div><strong>Scrivi qui il protocollo e per quanto vale la convenzione</strong> (l'art. 10 del modello dice 5 anni)
                <form method="POST" enctype="multipart/form-data" class="row g-2 mt-1"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo $idc; ?>">
                    <div class="col-sm-3"><label class="form-label small mb-0">N. protocollo</label><input aria-label="N. protocollo" name="protocollo" class="form-control form-control-sm" maxlength="100" placeholder="es. 2201"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0">Data protocollo</label><input aria-label="Data protocollo" type="date" name="protocollo_data" class="form-control form-control-sm" value="<?php echo $oggi; ?>"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0">Valida dal</label><input aria-label="Valida dal" type="date" name="valida_dal" class="form-control form-control-sm" required value="<?php echo $h($v_dal); ?>"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0">Valida fino al</label><input aria-label="Valida fino al" type="date" name="valida_al" class="form-control form-control-sm" required value="<?php echo $h($v_al); ?>"></div>
                    <div class="col-12"><label for="fp<?php echo $idc; ?>" class="form-label small mb-0">Documento protocollato: PDF con la segnatura (facoltativo)</label><input type="file" name="file_protocollato" id="fp<?php echo $idc; ?>" class="form-control form-control-sm" accept=".pdf,application/pdf">
                        <div class="form-text">Se lo carichi, per l'azienda e nell'archivio c'è questo file (vale più della copia «per consultazione» stampigliata dal portale). Il PDF firmato dal Direttore non si modifica mai e resta scaricabile qui.<?php echo $svc->haProtocollatoAMano($c) ? ' <strong>Ne hai già caricato uno: verrà usato questo.</strong>' : ''; ?></div></div>
                    <div class="col-12 d-flex flex-wrap gap-2"><?php if ($titulus_def_tesi_ok): ?><button type="submit" name="completa_titulus" value="1" formnovalidate class="btn btn-danger fw-bold" data-confirm="Mandare la convenzione firmata a Titulus? Il protocollo si assegna subito e non si annulla da qui. Poi la convenzione si registra come stipulata con le date indicate."><i class="fa fa-stamp me-1" aria-hidden="true"></i>Invia al protocollo e registra</button><?php endif; ?>
                        <button type="submit" name="completa" value="1" class="btn btn-outline-success fw-bold" data-confirm="Registrare la convenzione come stipulata con il numero scritto qui e avvisare azienda, studenti e relatori?"><i class="fa fa-pen me-1" aria-hidden="true"></i>Registra il numero a mano</button>
                        <?php if ($titulus_def_tesi_ok): ?><span class="small text-secondary align-self-center">Con «Invia al protocollo e registra» non serve scrivere numero e data: indica solo le date di validità.</span><?php endif; ?></div></form></div></li>
        </ol>
        <div class="cvt-esito"><i class="fa fa-bolt me-1" aria-hidden="true"></i>Alla registrazione la convenzione va in archivio, l'azienda, gli studenti e i relatori ricevono l'avviso e, se l'azienda ha dato il consenso, compare nell'<a href="../aziende_convenzionate_tesi.php" target="_blank" rel="noopener">elenco pubblico</a>.</div>
    </div>
    <?php endforeach; ?>

    <!-- 5 · Dal Direttore -->
    <div class="cvt-fase cvt-dir" id="fase-dirfirma"><span class="chi">Attendi</span><h2>⑤ <?php echo $h($S::PASSI[2]); ?> (<?php echo count($gruppi['dirfirma']); ?>)</h2></div>
    <?php if (!$gruppi['dirfirma']): ?><p class="cvt-vuoto">Nessuna convenzione dal Direttore.</p><?php endif; ?>
    <?php foreach ($gruppi['dirfirma'] as $c): $idc = (int)$c['id']; $inviata = $c['stato'] === $S::DA_FIRMARE; ?>
    <div class="cvt-card cvt-dir">
        <div class="cvt-az"><strong><?php echo $h($nome_az($c)); ?></strong><span class="text-secondary small">protocollo in entrata <?php echo $h($c['protocollo_ingresso']); ?></span></div>
        <p class="mb-2 small"><?php if ($inviata): ?><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>Il link per firmare è stato mandato a <strong><?php echo $h($dir_firma['nome']); ?></strong> il <?php echo date('d/m/Y H:i', strtotime((string)$c['da_firmare_il'])); ?>. Quando firma, il PDF torna qui e passa al punto ⑥.
            <?php else: ?><i class="fa fa-triangle-exclamation me-1 text-danger" aria-hidden="true"></i><strong>L'email al Direttore non è partita</strong>: controlla che sia indicato in Uffici → Direzione e premi il pulsante.<?php endif; ?></p>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo $idc; ?>"><button type="submit" name="invia_direttore" value="1" class="btn btn-sm <?php echo $inviata ? 'btn-outline-primary' : 'btn-primary fw-bold'; ?>"><i class="fa fa-file-signature me-1" aria-hidden="true"></i><?php echo $inviata ? 'Rimanda il link' : 'Invia al Direttore per la firma'; ?></button></form>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo $h($url('iter', '&file=' . $idc . '&f=azienda')); ?>" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>PDF dell'azienda</a></div>
    </div>
    <?php endforeach; ?>

    <!-- 1 / 3 · In attesa dell'azienda -->
    <div class="cvt-fase cvt-altri" id="fase-attesa"><span class="chi">Attendi</span><h2>① ③ <?php echo $h($S::PASSI[0]); ?> (<?php echo count($gruppi['attesa']); ?>)</h2></div>
    <?php if (!$gruppi['attesa']): ?><p class="cvt-vuoto">Nessuna convenzione in corso con le aziende.</p>
    <?php else: ?><div class="table-responsive"><table class="table table-sm small align-middle bg-white border">
        <thead><tr><th>Ente o azienda</th><th>Email</th><th>Stato</th><th>Ultima novità</th><th></th></tr></thead><tbody>
        <?php foreach ($gruppi['attesa'] as $c): $et = $S::ETICHETTE[$c['stato']] ?? [$c['stato'], 'secondary']; ?>
            <tr><td><strong><?php echo $h($nome_az($c)); ?></strong><div class="text-secondary"><?php echo $c['origine'] === 'pubblica' ? 'ha richiesto dalla pagina pubblica' : 'invitata da ' . $h($c['invitata_da'] ?: 'ufficio'); ?></div></td>
                <td><?php echo $h($c['email']); ?></td><td><span class="badge bg-<?php echo $et[1]; ?>"><?php echo $h($et[0]); ?></span><?php echo $c['stato'] === $S::RIFIUTATA ? '<div class="text-secondary" style="max-width:240px;white-space:normal;">' . $h($c['nota_ufficio']) . '</div>' : ''; ?></td>
                <td class="text-nowrap"><?php echo $d($c['aggiornata_il']); ?></td>
                <td class="text-nowrap"><form method="POST" class="d-inline"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>"><button type="submit" name="rimanda" value="1" class="btn btn-sm btn-outline-primary py-0" title="Rimanda all'azienda il link personale">Rimanda il link</button>
                    <button type="submit" name="elimina" value="1" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare questa convenzione in corso?">Elimina</button></form></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    <p class="text-secondary small mt-3 mb-0">Le convenzioni stipulate (anche quelle già in essere) sono in <a href="<?php echo $h($url('archivio')); ?>">Archivio e aziende convenzionate</a>.</p>
</section>

<?php else:
    $vista = in_array($_GET['vista'] ?? '', ['valide', 'scadute'], true) ? $_GET['vista'] : 'tutte';
    $cerca = trim((string)($_GET['q'] ?? ''));
    $archivio = $svc->archivio($cerca, $vista);
    $mod = !empty($_GET['mod']) ? $svc->perId((int)$_GET['mod']) : null;
    if ($mod && $mod['stato'] !== $S::PROTOCOLLATA) $mod = null;
    $dm = $mod ? $svc->dati($mod) : [];
    $tra = date('Y-m-d', strtotime('+60 days'));
?>
<section class="scu-sez" id="archivio">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <form method="GET" class="d-flex gap-2 m-0 flex-grow-1" role="search"><input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="tab" value="archivio">
            <input class="form-control form-control-sm" name="q" value="<?php echo $h($cerca); ?>" placeholder="Cerca per azienda, protocollo, comune…" aria-label="Cerca">
            <select class="form-select form-select-sm" name="vista" style="width:auto;" aria-label="Quali convenzioni"><?php foreach (['tutte' => 'Tutte', 'valide' => 'Valide', 'scadute' => 'Scadute'] as $k => $t): ?><option value="<?php echo $k; ?>"<?php echo $vista === $k ? ' selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?></select>
            <button class="btn btn-sm btn-outline-secondary" type="submit">Filtra</button></form>
    </div>
    <details class="mb-3 border rounded p-3 bg-white"<?php echo $mod ? ' open' : ''; ?> id="formArchivio"><summary class="fw-bold"><i class="fa fa-plus me-1" aria-hidden="true"></i><?php echo $mod ? 'Modifica la convenzione' : 'Inserisci una convenzione già in essere'; ?></summary>
        <p class="small text-secondary mt-2">Per le convenzioni stipulate prima del portale: senza passare dall'iter. Indica almeno ragione sociale, protocollo e validità; il PDF è facoltativo ma utile.</p>
        <form method="POST" enctype="multipart/form-data" class="row g-2"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)($mod['id'] ?? 0); ?>">
            <?php $f = fn(string $k) => $h($dm[$k] ?? ''); ?>
            <div class="col-md-6"><label class="form-label small fw-bold mb-0">Ragione sociale *</label><input aria-label="Ragione sociale" class="form-control form-control-sm" name="ragione_sociale" required maxlength="200" value="<?php echo $mod ? $h($mod['azienda']) : ''; ?>"></div>
            <div class="col-md-3"><label class="form-label small mb-0">Partita IVA</label><input aria-label="Partita IVA" class="form-control form-control-sm" name="piva" maxlength="20" value="<?php echo $f('piva'); ?>"></div>
            <div class="col-md-3"><label class="form-label small mb-0">Codice fiscale</label><input aria-label="Codice fiscale" class="form-control form-control-sm" name="cf" maxlength="20" value="<?php echo $f('cf'); ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">Comune</label><input aria-label="Comune" class="form-control form-control-sm" name="comune" maxlength="100" value="<?php echo $f('comune'); ?>"></div>
            <div class="col-md-2"><label class="form-label small mb-0">Prov.</label><input aria-label="Prov." class="form-control form-control-sm" name="provincia" maxlength="2" value="<?php echo $f('provincia'); ?>"></div>
            <div class="col-md-6"><label class="form-label small mb-0">Indirizzo</label><input aria-label="Indirizzo" class="form-control form-control-sm" name="indirizzo" maxlength="200" value="<?php echo $f('indirizzo'); ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">PEC</label><input aria-label="PEC" class="form-control form-control-sm" name="pec" maxlength="150" value="<?php echo $f('pec'); ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">Email di contatto (riceve gli avvisi di scadenza)</label><input aria-label="Email di contatto (riceve gli avvisi di scadenza)" type="email" class="form-control form-control-sm" name="email" maxlength="255" value="<?php echo $mod ? $h($mod['email']) : ''; ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">Settore</label><input aria-label="Settore" class="form-control form-control-sm" name="settore" maxlength="150" value="<?php echo $f('settore'); ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">Sito web</label><input aria-label="Sito web" class="form-control form-control-sm" name="sito" maxlength="200" value="<?php echo $f('sito'); ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">Rappresentante legale</label><input aria-label="Rappresentante legale" class="form-control form-control-sm" name="rappr_nome" maxlength="150" value="<?php echo $f('rappr_nome'); ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-0">Referente</label><input aria-label="Referente" class="form-control form-control-sm" name="referente_nome" maxlength="150" value="<?php echo $f('referente_nome'); ?>"></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0">N. protocollo *</label><input aria-label="N. protocollo" class="form-control form-control-sm" name="protocollo" required maxlength="100" value="<?php echo $mod ? $h($mod['protocollo']) : ''; ?>"></div>
            <div class="col-md-3"><label class="form-label small mb-0">Data protocollo</label><input aria-label="Data protocollo" type="date" class="form-control form-control-sm" name="protocollo_data" value="<?php echo $mod ? $h($mod['protocollo_data']) : ''; ?>"></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0">Valida dal *</label><input aria-label="Valida dal" type="date" class="form-control form-control-sm" name="valida_dal" required value="<?php echo $mod ? $h($mod['valida_dal']) : $val_dal; ?>"></div>
            <div class="col-md-3"><label class="form-label small fw-bold mb-0">Valida fino al *</label><input aria-label="Valida fino al" type="date" class="form-control form-control-sm" name="valida_al" required value="<?php echo $mod ? $h($mod['valida_al']) : $val_al; ?>"></div>
            <div class="col-md-8"><label class="form-label small mb-0">PDF della convenzione<?php echo $mod && $mod['file_protocollato'] ? ' (carica solo per sostituirlo)' : ''; ?></label><input aria-label="File convenzione" type="file" class="form-control form-control-sm" name="file_convenzione" accept=".pdf,application/pdf"></div>
            <div class="col-md-4 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="consenso_elenco" id="cArc" value="1"<?php echo ($mod ? (int)$mod['consenso_elenco'] === 1 : true) ? ' checked' : ''; ?>><label class="form-check-label small" for="cArc">Compare nell'elenco pubblico (consenso dell'azienda)</label></div></div>
            <div class="col-12"><button type="submit" name="archivia" value="1" class="btn btn-danger fw-bold"><?php echo $mod ? 'Salva le modifiche' : 'Inserisci in archivio'; ?></button><?php if ($mod): ?> <a class="btn btn-outline-secondary" href="<?php echo $h($url('archivio')); ?>">Annulla</a><?php endif; ?></div>
        </form>
    </details>
    <?php if (!$archivio): ?><p class="text-muted">Nessuna convenzione stipulata<?php echo $cerca !== '' || $vista !== 'tutte' ? ' con questi filtri' : ' per ora'; ?>.</p>
    <?php else: ?><div class="table-responsive"><table class="table table-sm small align-middle bg-white border">
        <thead class="table-light"><tr><th>Ente o azienda</th><th>Comune</th><th>Protocollo</th><th>Validità</th><th>Elenco pubblico</th><th>File</th><th></th></tr></thead><tbody>
        <?php foreach ($archivio as $c): $dc = $svc->dati($c); $scaduta = $c['valida_al'] && $c['valida_al'] < $oggi; $presto = !$scaduta && $c['valida_al'] && $c['valida_al'] <= $tra; ?>
            <tr id="cv<?php echo (int)$c['id']; ?>"><td><strong><?php echo $h($c['azienda']); ?></strong><div class="text-secondary"><?php echo $h(($dc['piva'] ?? '') ? 'P. IVA ' . $dc['piva'] : ($dc['cf'] ?? '')); ?><?php echo $c['origine'] === 'archivio' ? ' · inserita a mano' : ''; ?></div></td>
                <td><?php echo $h(($dc['comune'] ?? '') . (!empty($dc['provincia']) ? ' (' . $dc['provincia'] . ')' : '')); ?></td>
                <td><?php echo $h($c['protocollo']); ?><div class="text-secondary"><?php echo $d($c['protocollo_data']); ?></div></td>
                <td class="text-nowrap"><?php echo $d($c['valida_dal']); ?> – <?php echo $d($c['valida_al']); ?> <?php echo $scaduta ? '<span class="badge bg-secondary">scaduta</span>' : ($presto ? '<span class="badge bg-warning text-dark">in scadenza</span>' : '<span class="badge bg-success">valida</span>'); ?></td>
                <td><?php echo (int)$c['consenso_elenco'] === 1 ? ($scaduta ? 'sì (non più valida)' : 'sì') : 'no'; ?></td>
                <td class="text-nowrap"><?php if ($svc->fileDaScaricare((int)$c['id'], 'finale')): ?><a class="btn btn-sm btn-outline-success py-0" href="<?php echo $h($url('archivio', '&file=' . (int)$c['id'] . '&f=finale')); ?>" target="_blank" rel="noopener">PDF</a><?php else: ?>—<?php endif; ?></td>
                <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary py-0" href="<?php echo $h($url('archivio', '&mod=' . (int)$c['id'])); ?>#formArchivio">Modifica</a>
                    <form method="POST" class="d-inline"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                        <?php if (($scaduta || $presto) && $c['email']): ?><button type="submit" name="rinnova" value="1" class="btn btn-sm btn-outline-primary py-0" data-confirm="Mandare all'azienda il link per rinnovare la convenzione?">Rinnova</button><?php endif; ?>
                        <button type="submit" name="elimina" value="1" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare questa convenzione dall'archivio con i suoi file?">Elimina</button></form></td></tr>
            <?php if ($c['origine'] !== 'archivio'): $os = (string)($c['originale_stato'] ?? ''); $rg = json_decode((string)($c['esito_registrazione'] ?? ''), true) ?: []; $idc = (int)$c['id']; ?>
            <tr><td colspan="7" class="bg-light border-top-0 pt-1">
                <div class="mb-1"><strong>File di Titulus:</strong>
                    <?php if ($os === 'ok'): ?><span class="badge bg-success">originale ripreso da Titulus</span> (senza stampigliatura, impronta controllata)
                        <?php if (!empty($c['file_originale']) && $svc->fileDaScaricare($idc, 'originale')): ?><a class="btn btn-sm btn-outline-dark py-0" href="<?php echo $h($url('archivio', '&file=' . $idc . '&f=originale')); ?>" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Originale Titulus</a><?php endif; ?>
                    <?php elseif ($os === 'impronta'): ?><span class="badge bg-danger">impronta diversa</span> <?php echo $h($c['originale_avviso']); ?> Si tiene il file del portale.
                    <?php elseif ($os === 'mancante'): ?><span class="badge bg-warning text-dark">non arrivato</span> <?php echo $h($c['originale_avviso']); ?> Per l'azienda vale <?php echo ($c['protocollato_origine'] ?? '') === 'mano' ? 'il documento che hai caricato' : 'la copia del portale (stampigliata «per consultazione»)'; ?>.<?php echo (int)$c['originale_tentativi'] < 8 ? ' Il portale riprova da solo una volta al giorno.' : ' I tentativi automatici sono finiti.'; ?>
                    <?php else: ?><span class="text-secondary">non ripreso (protocollo scritto a mano o registrata prima di questa funzione)</span><?php endif; ?></div>
                <div class="mb-1"><strong>Documento protocollato:</strong> <?php echo match ((string)($c['protocollato_origine'] ?? '')) { 'mano' => 'caricato dall\'ufficio', 'copia' => '<span class="badge bg-secondary">copia per consultazione</span> stampigliata dal portale (il PDF firmato non è stato modificato)', default => 'nessuno: per l\'azienda vale il PDF firmato dal Direttore' }; ?>.</div>
                <div class="mb-1"><strong>Avvisi della stipula:</strong> azienda <?php echo !empty($rg['azienda']) ? '<span class="badge bg-success">mandato</span>' : '<span class="badge bg-warning text-dark">da mandare</span>'; ?> · <?php echo count((array)($rg['studenti'] ?? [])); ?> studenti · <?php echo count((array)($rg['relatori'] ?? [])); ?> relatori.</div>
                <form method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center mb-1"><?php csrf_field(); ?><input type="hidden" name="id" value="<?php echo $idc; ?>">
                    <button type="submit" name="riprova_originale" value="1" class="btn btn-sm btn-outline-secondary py-0"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Riprova da Titulus</button>
                    <input type="file" name="protocollato" class="form-control form-control-sm w-auto" accept=".pdf,application/pdf" aria-label="Documento protocollato (PDF)">
                    <button type="submit" name="carica_protocollato" value="1" class="btn btn-sm btn-outline-primary py-0"><i class="fa fa-upload me-1" aria-hidden="true"></i>Carica il documento protocollato</button>
                    <button type="submit" name="ripeti_avvisi" value="1" class="btn btn-sm btn-outline-secondary py-0" title="Si può ripetere senza effetti doppi" data-confirm="Ripetere gli avvisi della stipula? Chi li ha già ricevuti non li riceve di nuovo."><i class="fa fa-envelope me-1" aria-hidden="true"></i>Ripeti gli avvisi</button></form></td></tr>
            <?php endif; ?>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>
<?php require_once 'admin_footer.php'; ?>
