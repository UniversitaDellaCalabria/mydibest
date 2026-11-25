<?php
// convenzione_online.php - Convenzione FSL e Allegato A compilati online dalla scuola con un modulo guidato:
// dati dell'istituto e del Dirigente, logo della scuola, attività dell'Allegato A (quelle già prenotate dalla scuola e,
// se vuole, altre attività FSL ancora da prenotare, con il promemoria di prenotarle). Al termine si scaricano i due
// documenti Word dai modelli del Dipartimento, da firmare digitalmente e inviare via PEC.
// Accesso con il codice della prenotazione (?code=, come la ricevuta) o con il link personale ricevuto per email (?t=).
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$nega = function (string $msg) use ($h) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(404);
    exit('<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Convenzione non disponibile</title></head>'
       . '<body class="ui-maxw-640 ui-p-0-1r" style="font-family:system-ui,sans-serif; margin:3rem auto;"><h1>Convenzione non disponibile</h1><p>' . $h($msg) . '</p><p><a href="ricevi_link_convenzione.php">Hai perso il link? Fattelo rimandare per email</a> · <a href="index.php">Torna al portale</a></p></body></html>');
};
// Schermata semplice, senza dati della scuola né link: per chi non può aprire la compilazione ma deve sapere cosa è successo (stato 200)
$avviso = function (string $titolo, string $msg) use ($h) {
    while (ob_get_level() > 0) ob_end_clean();
    exit('<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $h($titolo) . '</title></head>'
       . '<body class="ui-maxw-640 ui-p-0-1r" style="font-family:system-ui,sans-serif; margin:3rem auto;"><h1>' . $h($titolo) . '</h1><p>' . $h($msg) . '</p><p><a href="ricevi_link_convenzione.php">Hai perso il link? Fattelo rimandare per email</a> · <a href="index.php">Torna al portale</a></p></body></html>');
};
// Per la tua scuola c'è già una convenzione in corso (la risposta è la stessa per chi ha scritto l'email giusta e per chi no): niente dati, file, messaggi né link.
// «Non riconosci questa convenzione?»: modulo breve per scrivere all'ufficio (CSRF, limite per IP e anti-robot come le altre pagine pubbliche).
$avviso_gia_in_corso = function (string $codice, ?string $errore = null, bool $inviato = false) use ($h) {
    while (ob_get_level() > 0) ob_end_clean();
    $cap = captcha_prenotazione();
    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Convenzione già in corso</title></head>'
       . '<body class="ui-maxw-640 ui-p-0-1r" style="font-family:system-ui,sans-serif; margin:3rem auto; max-width:640px; padding:0 1rem;"><h1>Convenzione già in corso</h1><p>' . $h(\App\Fsl\ServizioConvenzioneOnline::MESSAGGIO_GIA_IN_CORSO) . '</p>'
       . '<p><a href="ricevi_link_convenzione.php">Hai perso il link? Fattelo rimandare per email</a> · <a href="index.php">Torna al portale</a></p>';
    if ($inviato) {
        echo '<p role="status"><strong>Messaggio inviato.</strong> L\'ufficio lo legge e ti risponde.</p>';
    } else {
        echo '<hr><h2 style="font-size:1.1rem;">Non riconosci questa convenzione? Scrivi all\'ufficio</h2>'
           . ($errore ? '<p role="alert" style="color:#b91c1c;">' . $h($errore) . '</p>' : '')
           . '<form method="POST" action="convenzione_online.php?code=' . rawurlencode($codice) . '">';
        csrf_field();
        echo '<div style="position:absolute;left:-9999px;" aria-hidden="true"><label for="sito_web">Non compilare questo campo</label><input id="sito_web" name="sito_web" tabindex="-1" autocomplete="off"></div>'
           . '<p><label for="nr_messaggio">Cosa non riconosci?</label><br><textarea id="nr_messaggio" name="messaggio" rows="3" maxlength="1000" required style="width:100%;"></textarea></p>'
           . '<p><label for="nr_contatto">Come ti ricontattiamo (email o telefono, facoltativo)</label><br><input id="nr_contatto" name="contatto" maxlength="150" style="width:100%;"></p>'
           . '<p><label for="captcha_risposta">' . $h($cap['domanda']) . '</label> <input id="captcha_risposta" name="captcha_risposta" inputmode="numeric" pattern="[0-9]*" required autocomplete="off" style="width:6rem;"><input type="hidden" name="captcha_id" value="' . $h($cap['id']) . '"></p>'
           . '<p><button type="submit" name="non_riconosco" value="1">Scrivi all\'ufficio</button></p></form>';
    }
    exit('</body></html>');
};
if (!check_rate_limit($conn, 'convenzione_online', 120, 3600)) $nega("Troppe richieste: riprova tra poco.");

// ── Accesso: link personale (token) o codice della prenotazione ──
$conv_online = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioneOnline::class);
// L'email VERIFICATA di chi ha fatto l'accesso (null = nessun accesso o email non verificata): serve per il codice della prenotazione e per il rinnovo
$email_verificata = !empty($_SESSION['utente_id'])
    ? \App\Auth\UtenteRepository::emailVerificata(\App\Core\App::per($conn)->get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']))
    : null;
$cc = null;
$tok = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
if ($tok !== '') {
    $cc = $conv_online->perToken($tok);
    if (!$cc) $nega("Il link non è valido o è scaduto.");
} else {
    $codice = strtoupper(trim((string)($_GET['code'] ?? '')));
    // Senza link né codice: si va alla pagina «Ho perso il link»
    if ($codice === '') { header('Location: ricevi_link_convenzione.php'); exit; }
    // Più tentativi con i codici di prenotazione di quanto serva a una persona: stesso limite per IP delle altre pagine pubbliche, più stretto
    if (!check_rate_limit($conn, 'convenzione_codice', 40, 3600)) $nega("Troppe richieste: riprova tra poco.");
    // «Non riconosco questa convenzione»: avvisa l'ufficio (non apre niente)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['non_riconosco'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $errore_nr = null; $inviato_nr = false;
        if (!empty($_POST['sito_web'])) { $inviato_nr = true; }   // campo trappola: i programmi automatici lo riempiono
        elseif (!check_rate_limit($conn, 'convenzione_non_riconosco', 3, 3600)) { $errore_nr = "Troppe richieste da questo indirizzo: riprova tra un po'."; }
        elseif ($err_c = captcha_verifica((string)($_POST['captcha_id'] ?? ''), (string)($_POST['captcha_risposta'] ?? ''))) { $errore_nr = $err_c; }
        else { $errore_nr = $conv_online->nonRiconosco($codice, (string)($_POST['messaggio'] ?? ''), (string)($_POST['contatto'] ?? '')); $inviato_nr = $errore_nr === null; }
        $avviso_gia_in_corso($codice, $errore_nr, $inviato_nr);
    }
    // Il token di una compilazione che esiste già si dà solo a chi ha il codice della prenotazione da cui è nata o ha fatto l'accesso con un'email
    // VERIFICATA tra i proprietari: l'email scritta in una prenotazione non prova chi è. Negli altri casi la risposta è sempre la stessa.
    $apertura = $conv_online->apriDaCodice($codice, $email_verificata);
    if ($apertura === null) $nega("Controlla il codice della prenotazione: la convenzione si compila dal link della conferma o dell'email.");
    if ($apertura['riprova']) $avviso('Riprova tra qualche secondo', \App\Fsl\BloccoOccupato::MESSAGGIO);
    if ($apertura['token'] === null) $avviso_gia_in_corso($codice);
    header('Location: convenzione_online.php?t=' . $apertura['token']); exit;
}
// Compilazione doppia unita a un'altra dall'ufficio: niente dati, niente link dell'altra (l'indirizzo giusto è stato avvisato per email)
if (($cc['stato_iter'] ?? '') === \App\Fsl\Costanti::ITER_UNITA) $avviso('Compilazione unita a un\'altra', "Per la tua scuola c'erano due compilazioni della convenzione: il Dipartimento le ha unite in una sola. Il link giusto è stato mandato per email all'indirizzo che aveva fatto la compilazione; se non lo trovi, puoi farlo rimandare.");

$ctx = $conv_online->contesto($cc);
if (!$ctx) $nega("La prenotazione collegata non esiste più.");
['p0' => $p0, 'cfg' => $cfg, 'prenotate' => $prenotate, 'prenotabili' => $prenotabili, 'dati' => $dati, 's' => $s] = $ctx;

// ── Documenti: genera e scarica ──
if (isset($_GET['scarica']) && $_GET['scarica'] !== 'firmata' && $dati) {
    $doc_pronto = $conv_online->scarica((string)$_GET['scarica'], $cc, $ctx);
    if ($doc_pronto) invia_file_scaricabile($doc_pronto['file'], $doc_pronto['nome'], str_ends_with($doc_pronto['nome'], '.pdf') ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
}

// ── Convenzione firmata: la scuola la scarica di nuovo, la carica o la sostituisce ──
$conv_firmata = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioneFirmata::class);
if (($_GET['scarica'] ?? '') === 'firmata') {
    $ff = $conv_firmata->fileDaScaricare((int)$cc['id']);
    if (!$ff) $nega("Non c'è nessuna convenzione firmata caricata.");
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $ff['nome'] . '"');
    header('Content-Length: ' . filesize($ff['percorso']));
    header('X-Content-Type-Options: nosniff');
    readfile($ff['percorso']);
    exit;
}
if (($_GET['scarica'] ?? '') === 'protocollata') {
    $ff = $conv_firmata->documentoProtocollatoPerScuola((int)$cc['id']);
    if (!$ff) $nega("La convenzione protocollata non è ancora disponibile.");
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $ff['nome'] . '"');
    header('Content-Length: ' . filesize($ff['percorso']));
    header('X-Content-Type-Options: nosniff');
    readfile($ff['percorso']);
    exit;
}
$errore_firmata = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['carica_firmata'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $errore_firmata = $conv_firmata->caricaDaUpload($cc, $_FILES['convenzione_firmata'] ?? null, $_FILES['allegato_a'] ?? null);
    if ($errore_firmata === null) {
        flash_set("Convenzione firmata ricevuta: l'ufficio la prende in carico e ti scriviamo appena è pronta.");
        header('Location: convenzione_online.php?t=' . $cc['token'] . '&r=' . time() . '#firmata'); exit;
    }
    $cc = $conv_online->perToken($cc['token']) ?: $cc;
}

// ── Messaggi con il Dipartimento: la scuola risponde o scrive all'ufficio ──
$msg_serv = \App\Core\App::per($conn)->get(\App\Fsl\ServizioMessaggiConvenzione::class);
$msg_rep = \App\Core\App::per($conn)->get(\App\Fsl\MessaggiConvenzioneRepository::class);
$errore_msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scrivi_ufficio'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $errore_msg = $msg_serv->rispondi($cc, (string)($_POST['testo'] ?? ''));
    if ($errore_msg === null) {
        flash_set("Messaggio mandato: l'ufficio lo riceve per email e ti risponde appena può.");
        header('Location: convenzione_online.php?t=' . $cc['token'] . '&r=' . time() . '#messaggi'); exit;
    }
}

// ── Attività segnalate da altre email: chi ha il link le conferma o le scarta ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['conferma_attivita']) || isset($_POST['scarta_attivita']))) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $conferma_att = isset($_POST['conferma_attivita']);
    $pr_att = (int)($conferma_att ? $_POST['conferma_attivita'] : $_POST['scarta_attivita']);
    try {
        flash_set($conv_online->decidiInAttesa($cc, $pr_att, $conferma_att) ? ($conferma_att ? "Attività confermata: ora è nell'Allegato A." : 'Attività scartata.') : "Quell'attività non è più da confermare.");
    } catch (\App\Fsl\BloccoOccupato $e_blocco) {
        flash_set($e_blocco->getMessage(), 'danger');
    }
    header('Location: convenzione_online.php?t=' . $cc['token'] . '&r=' . time() . '#da-confermare'); exit;
}
// ── Rinnovo: una nuova compilazione per l'istituto, precompilata da questa ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rinnova_convenzione'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $rin = $conv_online->rinnova($cc, $email_verificata);
    if ($rin['riprova']) { flash_set(\App\Fsl\BloccoOccupato::MESSAGGIO, 'danger'); header('Location: convenzione_online.php?t=' . $cc['token'] . '&r=' . time()); exit; }
    if ($rin['segnalata']) $avviso('Convenzione già in corso', \App\Fsl\ServizioConvenzioneOnline::MESSAGGIO_GIA_IN_CORSO);
    if ($rin['token'] === null) $avviso('Rinnovo non disponibile', 'Questa convenzione non si può rinnovare da qui.');
    flash_set('Ho preparato la nuova convenzione con i dati di quella precedente: controllali e carica i documenti.');
    header('Location: convenzione_online.php?t=' . $rin['token'] . '&r=' . time()); exit;
}
// ── Allegato A aggiornato (la scuola ha già una convenzione valida): entra nel registro come integrazione ──
$errore_allegato = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['carica_allegato_aggiornato'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $errore_allegato = !empty($ctx['registro'])
        ? \App\Core\App::per($conn)->get(\App\Fsl\ServizioAllegatiConvenzione::class)->carica($cc, (int)$ctx['registro']['id'], $_FILES['allegato_aggiornato'] ?? null)
        : 'La convenzione registrata non copre queste attività: non si può aggiornare un Allegato A.';
    if ($errore_allegato === null) {
        flash_set("Allegato A aggiornato ricevuto: l'ufficio lo esamina e ti scrive l'esito. Le prenotazioni non aspettano.");
        header('Location: convenzione_online.php?t=' . $cc['token'] . '&r=' . time() . '#allegato-aggiornato'); exit;
    }
}

// ── Salvataggio del modulo ──
$errori = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salva_convenzione'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $esito = $conv_online->salva($cc, $ctx, $_POST, $_FILES['logo'] ?? null);
    $s = $esito['s'];
    $errori = $esito['errori'];
    if (!$errori) {
        if ($bz_cc = \App\Portale\BozzaModulo::chiave('fsl', (string)$cc['token'])) \App\Core\App::per($conn)->get(\App\Portale\BozzaModulo::class)->elimina($bz_cc);
        flash_set("Dati salvati: scarica i documenti, falli firmare digitalmente al Dirigente e inviali via PEC.");
        header('Location: convenzione_online.php?t=' . $cc['token'] . '&r=' . time() . '#documenti'); exit;
    }
}

$scelte = [];
foreach ($dati['attivita'] ?? [] as $a) $scelte[!empty($a['pr']) ? 'pr' . $a['pr'] : 'ev' . ($a['ev'] ?? 0)] = $a;
if (!$dati || empty($dati['attivita'])) foreach ($prenotate as $id => $p) $scelte['pr' . $id] = ['studenti' => 0, 'tutor' => ''];
$modo_aggiorna = !empty($ctx['registro']);   // la scuola ha già una convenzione valida: solo l'Allegato A
$puo_rinnovare = !$modo_aggiorna && ($cc['stato_iter'] ?? '') === \App\Fsl\Costanti::ITER_PROTOCOLLATA;
$non_prenotate = array_filter(array_keys($scelte), fn($k) => str_starts_with($k, 'ev'));

$page_cfg['titolo'] = 'Convenzione Formazione Scuola Lavoro';
require_once 'header.php';
?>
<style>
.cv-passo { display:inline-flex; width:28px; height:28px; border-radius:50%; background:var(--dibest-rosso); color:#fff; align-items:center; justify-content:center; font-weight:bold; margin-right:.4rem; }
.cv-att { border:1px solid #e5e7eb; border-radius:10px; padding:.7rem .9rem; margin-bottom:.5rem; }
.cv-att.scelta { border-color:#16a34a; background:#f0fdf4; }
</style>
<div class="container my-4 ui-maxw-960">
    <h1 class="fw-bold h2 mb-1"><i class="fa fa-file-signature me-2 ui-c-rosso-testo" aria-hidden="true"></i>Convenzione Formazione Scuola Lavoro</h1>
    <p class="text-secondary">Compila i dati: prepariamo noi la <strong>Convenzione</strong> e l'<strong>Allegato A</strong> sui modelli del Dipartimento. Poi li scarichi in Word, il Dirigente li firma digitalmente in PAdES (PDF firmato, non .p7m) e la scuola li invia via PEC.</p>
    <?php echo flash_html(); ?>
    <?php if ($modo_aggiorna): $reg = $ctx['registro']; ?>
    <div class="alert alert-success" role="status"><i class="fa fa-circle-check me-1" aria-hidden="true"></i><strong>La tua scuola ha già una convenzione valida per queste attività</strong>
        (n. <?php echo (int)$reg['id']; ?><?php echo !empty($reg['protocollo']) ? ', protocollo ' . $h($reg['protocollo']) : ''; ?>, <?php echo $h(\App\Fsl\PeriodiConvenzione::testoValidita($reg)); ?>).
        Non ne serve un'altra: qui aggiorni solo l'<strong>Allegato A</strong>. Le prenotazioni non aspettano.</div>
    <?php elseif ($puo_rinnovare): ?>
    <div class="alert alert-warning" role="alert"><i class="fa fa-rotate me-1" aria-hidden="true"></i><strong>La convenzione di questa scuola non copre più queste attività.</strong>
        <form method="POST" class="mt-2"><?php csrf_field(); ?><button type="submit" name="rinnova_convenzione" value="1" class="btn btn-sm fw-bold text-white ui-bg-rosso"><i class="fa fa-file-signature me-1" aria-hidden="true"></i>Prepara il rinnovo</button>
            <span class="small text-secondary ms-2">Una nuova compilazione con i dati e il logo di questa.</span></form></div>
    <?php endif; ?>
    <?php if ($ctx['da_confermare']): ?>
    <section id="da-confermare" class="card border-0 shadow-sm mb-4" style="border-left:5px solid #d97706 !important;"><div class="card-body">
        <h2 class="h5 fw-bold"><i class="fa fa-user-clock me-1 text-warning" aria-hidden="true"></i>Attività da confermare</h2>
        <p class="small text-secondary">Un altro docente della scuola ha prenotato queste attività. Non sono nell'Allegato A finché non le confermi: se non le conosci, scartale.</p>
        <?php foreach ($ctx['da_confermare'] as $pr_dc => $dc): ?>
            <form method="POST" class="d-flex flex-wrap align-items-center gap-2 border rounded-3 p-2 mb-2"><?php csrf_field(); ?>
                <div class="flex-grow-1"><strong><?php echo $h($dc['titolo']); ?></strong><?php echo $dc['turno'] !== '' ? ' – ' . $h($dc['turno']) : ''; ?>
                    <div class="small text-secondary"><?php echo $dc['studenti'] > 0 ? (int)$dc['studenti'] . ' studenti · ' : ''; ?><?php echo $dc['tutor'] !== '' ? 'tutor ' . $h($dc['tutor']) : ''; ?></div></div>
                <button type="submit" name="conferma_attivita" value="<?php echo (int)$pr_dc; ?>" class="btn btn-sm btn-success fw-bold"><i class="fa fa-check me-1" aria-hidden="true"></i>Conferma</button>
                <button type="submit" name="scarta_attivita" value="<?php echo (int)$pr_dc; ?>" class="btn btn-sm btn-outline-danger fw-bold"><i class="fa fa-xmark me-1" aria-hidden="true"></i>Scarta</button>
            </form>
        <?php endforeach; ?>
    </div></section>
    <?php endif; ?>
    <?php if ($errori): ?><div class="alert alert-danger" role="alert"><strong>Controlla i dati:</strong><ul class="mb-0"><?php foreach ($errori as $e): ?><li><?php echo $h($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <?php if ($dati && !empty($dati['attivita']) && !$errori): ?>
    <section id="documenti" class="card border-0 shadow-sm mb-4" style="border-left:5px solid #16a34a !important;"><div class="card-body">
        <h2 class="h5 fw-bold"><i class="fa fa-circle-check text-success me-1" aria-hidden="true"></i>I documenti sono pronti</h2>
        <div class="d-flex flex-wrap gap-2 my-3">
            <a class="btn btn-success fw-bold" href="convenzione_online.php?t=<?php echo $h($cc['token']); ?>&amp;scarica=allegato_pdf"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Scarica l'Allegato A (PDF)</a>
            <?php if (!$modo_aggiorna): ?><a class="btn btn-success fw-bold" href="convenzione_online.php?t=<?php echo $h($cc['token']); ?>&amp;scarica=convenzione_sola"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Scarica la Convenzione (Word)</a><?php endif; ?>
        </div>
        <p class="small text-secondary">Ti serve l'Allegato A da modificare? <a href="convenzione_online.php?t=<?php echo $h($cc['token']); ?>&amp;scarica=allegato">Scaricalo in Word</a>: ha lo stesso contenuto e lo stesso ordine del PDF.</p>
        <?php if ($modo_aggiorna): ?>
        <p class="mb-2">Controlla l'Allegato A e caricalo qui sotto (PDF, anche senza firma digitale): l'ufficio lo esamina e lo aggiunge alla convenzione.</p>
        <?php else: ?>
        <ol class="mb-2">
            <li>Apri i file e controlla i dati (nella Convenzione in Word i dati non ancora indicati restano come puntini da completare a mano).</li>
            <li>Il <strong>Dirigente Scolastico</strong> firma i documenti <strong>digitalmente in formato PAdES</strong> (PDF firmato; il formato CAdES .p7m non è accettato).</li>
            <li>La scuola <strong>carica qui sotto la convenzione firmata e l'Allegato A</strong> (la convenzione in PDF PAdES, l'Allegato A in PDF anche senza firma): l'ufficio riceve subito l'avviso. In alternativa si può inviare dalla PEC della scuola a <a href="mailto:<?php echo $h($cfg['pec']); ?>?subject=<?php echo rawurlencode('Convenzione Formazione Scuola Lavoro – ' . ($s['denominazione'] ?? '')); ?>" class="fw-bold"><?php echo $h($cfg['pec']); ?></a>.</li>
            <li>Il Dipartimento la protocolla in entrata, la fa firmare dal Direttore, la protocolla in via definitiva e <strong>conferma le prenotazioni</strong>: riceverete un'email a ogni passaggio importante.</li>
        </ol>
        <?php endif; ?>
        <?php if ($non_prenotate): ?>
            <div class="alert alert-warning small mb-0"><i class="fa fa-bell me-1" aria-hidden="true"></i><strong>Ricordati di prenotare</strong> le attività che hai inserito nell'Allegato A ma che non hai ancora prenotato:
                <?php foreach ($non_prenotate as $k): $ev = $prenotabili[(int)substr($k, 2)] ?? null; if (!$ev) continue; ?>
                    <a class="d-block fw-bold" href="<?php echo $h($ev['slug'] . '.php?' . (($ev['tipo'] ?? '') === 'progetto' ? 'progetto' : 'evento') . '=' . (int)$ev['evento_id']); ?>"><?php echo $h($ev['evento_titolo']); ?> → prenota</a>
                <?php endforeach; ?></div>
        <?php endif; ?>
        <p class="small text-secondary mt-2 mb-0">Puoi tornare su questa pagina dal link che ti abbiamo inviato per email e correggere i dati qui sotto: i documenti si aggiornano.</p>
    </div></section>

    <?php if ($modo_aggiorna): ?>
    <section id="allegato-aggiornato" class="card border-0 shadow-sm mb-4 ui-bsx-5-rosso"><div class="card-body">
        <h2 class="h5 fw-bold"><i class="fa fa-file-circle-plus me-1 ui-c-rosso-testo" aria-hidden="true"></i>Allegato A aggiornato</h2>
        <?php if ($errore_allegato): ?><div class="alert alert-danger" role="alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo $h($errore_allegato); ?></div><?php endif; ?>
        <?php foreach ($ctx['allegati'] as $al): $st_al = (string)$al['stato']; ?>
            <div class="alert alert-<?php echo $st_al === 'accettato' ? 'success' : ($st_al === 'rifiutato' ? 'warning' : 'primary'); ?> small py-2">
                <strong><?php echo $st_al === 'accettato' ? 'Accettato' : ($st_al === 'rifiutato' ? 'Non accettato' : "In esame presso l'ufficio"); ?></strong> · caricato il <?php echo $h(date('d/m/Y H:i', strtotime((string)$al['caricato_il']))); ?>
                <?php if ($st_al === 'rifiutato' && !empty($al['nota'])): ?><div>Motivo: <?php echo nl2br($h($al['nota'])); ?></div><?php endif; ?></div>
        <?php endforeach; ?>
        <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end"><?php csrf_field(); ?>
            <div class="col-md-8"><label class="form-label small fw-bold" for="cvAllegatoAgg">Allegato A aggiornato (PDF fino a 10 MB)</label>
                <input type="file" class="form-control" id="cvAllegatoAgg" name="allegato_aggiornato" accept=".pdf,application/pdf" required>
                <div class="form-text">Non sostituisce quello registrato: si aggiunge, dopo l'esame dell'ufficio. Non blocca le prenotazioni.</div></div>
            <div class="col-md-4"><button type="submit" name="carica_allegato_aggiornato" value="1" class="btn btn-lg fw-bold text-white w-100 ui-bg-rosso"><i class="fa fa-upload me-1" aria-hidden="true"></i>Carica l'Allegato A</button></div>
        </form>
    </div></section>
    <?php elseif (($dati['convenzione'] ?? '') !== 'si' || !empty($cc['file_firmato'])): // la scuola che ha già una convenzione valida non ne carica un'altra ?>
    <?php $cv_caricabile = \App\Fsl\ServizioConvenzioneFirmata::caricabile($cc); ?>
    <section id="firmata" class="card border-0 shadow-sm mb-4 ui-bsx-5-rosso"><div class="card-body">
        <h2 class="h5 fw-bold"><i class="fa fa-file-circle-check me-1 ui-c-rosso-testo" aria-hidden="true"></i>Convenzione firmata</h2>
        <?php if ($errore_firmata): ?><div class="alert alert-danger" role="alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo $h($errore_firmata); ?></div><?php endif; ?>
        <?php $it_sc = (string)($cc['stato_iter'] ?? ''); ?>
        <?php // Gli stessi passi, con le stesse parole, del pannello dell'ufficio (admin/fsl.php, fasi ①-⑤)
        $pas_corr = \App\Fsl\PassiConvenzione::corrente($it_sc, !empty($cc['file_firmato']));
        echo ui_passi(\App\Fsl\PassiConvenzione::passi(), $pas_corr, 'normale', 'Dove si trova la convenzione'); ?>
        <?php if ($it_sc === 'rifiutata'): ?>
            <div class="alert alert-warning mb-3" role="alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><strong>Il Dipartimento non ha potuto accettare il file.</strong>
                <?php if (!empty($cc['nota_ufficio'])): ?><div class="mt-1">Motivo: <?php echo nl2br($h($cc['nota_ufficio'])); ?></div><?php endif; ?>
                <div class="mt-1">Correggi il documento e caricalo di nuovo qui sotto.</div></div>
        <?php elseif ($it_sc === 'protocollata'): ?>
            <div class="alert alert-success mb-3" role="status"><i class="fa fa-circle-check me-1" aria-hidden="true"></i><strong>La convenzione è attiva.</strong>
                Firmata dal Direttore e protocollata<?php echo !empty($cc['protocollo']) ? ' (' . $h($cc['protocollo']) . ')' : ''; ?>. La trovi nell'Area personale, scheda «Programma FSL».
                <a class="fw-bold ms-1" href="convenzione_online.php?t=<?php echo $h($cc['token']); ?>&amp;scarica=protocollata">Scarica la convenzione protocollata</a>
                <div class="small mt-1">Il file firmato dal Direttore non viene mai modificato: se non c'è un documento protocollato caricato dall'ufficio, questa è una copia con il numero di protocollo e la scritta «copia per consultazione».</div></div>
        <?php elseif (!empty($cc['file_firmato'])): ?>
            <div class="alert alert-<?php echo $it_sc !== 'caricata_scuola' ? 'primary' : 'success'; ?> mb-3" role="status"><i class="fa fa-<?php echo $it_sc !== 'caricata_scuola' ? 'hand' : 'circle-check'; ?> me-1" aria-hidden="true"></i>
                <strong>Convenzione firmata ricevuta</strong> il <?php echo $h(date('d/m/Y \a\l\l\e H:i', strtotime((string)$cc['firmata_il']))); ?><?php echo !empty($cc['firmatario']) ? ' (firma di ' . $h($cc['firmatario']) . ')' : ''; ?>.
                <?php echo in_array($it_sc, ['in_carico', 'da_firmare', 'firmata_direttore'], true) ? "Il Dipartimento l'ha presa in carico" . (!empty($cc['protocollo_ingresso']) ? ' (protocollo in entrata ' . $h($cc['protocollo_ingresso']) . (!empty($cc['protocollo_ingresso_data']) ? ' del ' . $h(date('d/m/Y', strtotime((string)$cc['protocollo_ingresso_data']))) : '') . ')' : '') . ": la sta facendo firmare dal Direttore e protocollare. Ti scriviamo appena è pronta." : "Il Dipartimento la prenderà in carico a breve."; ?>
                <a class="fw-bold ms-1" href="convenzione_online.php?t=<?php echo $h($cc['token']); ?>&amp;scarica=firmata">Scarica il file caricato</a></div>
        <?php endif; ?>
        <?php if ($cv_caricabile): ?>
            <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end">
                <?php csrf_field(); ?>
                <div class="col-md-8"><label class="form-label small fw-bold" for="cvFirmata"><?php echo empty($cc['file_firmato']) ? 'Carica la convenzione firmata digitalmente in PAdES' : 'Hai sbagliato file? Carica la versione corretta'; ?> (PDF fino a 10 MB)</label>
                    <input type="file" class="form-control" id="cvFirmata" name="convenzione_firmata" accept=".pdf,application/pdf" required data-pdf-firmato="firmato"><script src="assets/js/controllo-pdf-firmato.js" defer></script>
                    <div class="form-text">Deve essere il PDF firmato dal Dirigente Scolastico in formato <strong>PAdES</strong>, senza modifiche dopo la firma. I file .p7m non sono accettati. Se il file viene rifiutato ti diciamo il perché e cosa fare; <a href="<?php echo $h(\App\Infrastructure\Pdf\MessaggiFirma::GUIDA_SCUOLE); ?>#s4" target="_blank" rel="noopener">la guida spiega la firma passo per passo</a>.</div></div>
                <div class="col-md-8"><label class="form-label small fw-bold" for="cvAllegatoA"><?php echo empty($cc['file_allegato_scuola']) ? 'Carica l\'Allegato A' : 'Allegato A (già caricato: scegli un file solo per sostituirlo)'; ?> (PDF fino a 10 MB)</label>
                    <input type="file" class="form-control" id="cvAllegatoA" name="allegato_a" accept=".pdf,application/pdf" data-pdf-firmato="semplice"<?php echo empty($cc['file_allegato_scuola']) ? ' required' : ''; ?>>
                    <div class="form-text">Puoi usare l'Allegato A in PDF scaricato qui sopra: non serve la firma digitale.</div></div>
                <div class="col-md-4"><button type="submit" name="carica_firmata" value="1" class="btn btn-lg fw-bold text-white w-100 ui-bg-rosso"><i class="fa fa-upload me-1" aria-hidden="true"></i><?php echo empty($cc['file_firmato']) ? 'Carica la convenzione' : 'Sostituisci il file'; ?></button></div>
            </form>
        <?php elseif (empty($cc['file_firmato']) && $it_sc !== 'protocollata'): ?>
            <p class="mb-0 text-secondary">La convenzione di questa scuola è già in lavorazione presso il Dipartimento.</p>
        <?php endif; ?>
    </div></section>
    <?php endif; ?>
    <?php endif; ?>

    <?php $conv_msg = $msg_rep->della((int)$cc['id']); $msg_rep->segnaLetti((int)$cc['id'], 'scuola'); ?>
    <section id="messaggi" class="card border-0 shadow-sm mb-4 ui-bsx-5-blu"><div class="card-body">
        <h2 class="h5 fw-bold"><i class="fa fa-comments me-1 ui-c-blu-testo" aria-hidden="true"></i>Messaggi con il Dipartimento</h2>
        <?php if ($errore_msg): ?><div class="alert alert-danger" role="alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo $h($errore_msg); ?></div><?php endif; ?>
        <?php if ($conv_msg): ?><div class="d-flex flex-column gap-2 my-3 ui-maxh-320 ui-ov-auto">
            <?php foreach ($conv_msg as $m): $mia = $m['da'] === 'scuola'; ?>
                <div class="rounded-3 p-2 px-3 small" style="max-width:90%;<?php echo $mia ? 'align-self:flex-end;background:#eff6ff;' : 'align-self:flex-start;background:#fef2f2;'; ?>">
                    <div class="text-secondary"><?php echo $mia ? 'Tu' : 'Dipartimento'; ?> · <?php echo $h(date('d/m/Y H:i', strtotime((string)$m['creato_il']))); ?></div>
                    <div><?php echo nl2br($h($m['testo'])); ?></div></div>
            <?php endforeach; ?></div>
        <?php else: ?><p class="small text-secondary">Qui trovi i messaggi dell'ufficio e puoi scrivergli per dubbi o problemi con la convenzione.</p><?php endif; ?>
        <form method="POST" class="d-flex flex-column gap-2"><?php csrf_field(); ?>
            <label class="form-label small fw-bold mb-0" for="cvTesto">Scrivi all'ufficio</label>
            <textarea class="form-control" id="cvTesto" name="testo" rows="3" maxlength="1500" required placeholder="Es. Il Dirigente ha firmato ma il file non viene accettato…"></textarea>
            <div><button type="submit" name="scrivi_ufficio" value="1" class="btn fw-bold text-white ui-bg-blu"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Manda il messaggio</button></div>
        </form>
    </div></section>

    <form method="POST" enctype="multipart/form-data" id="formConvenzione">
        <?php csrf_field(); ?>
        <section class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold"><span class="cv-passo">1</span>Istituzione scolastica</h2>
            <div class="row g-2">
                <div class="col-md-8"><label class="form-label small fw-bold" for="cvDen">Denominazione <span class="text-danger">*</span></label><input class="form-control" id="cvDen" name="denominazione" value="<?php echo $h($s['denominazione']); ?>" required maxlength="255"></div>
                <div class="col-md-4"><label class="form-label small fw-bold" for="cvCod">Codice meccanografico</label><input class="form-control" id="cvCod" name="codice" value="<?php echo $h($s['codice']); ?>" maxlength="20"></div>
                <div class="col-md-4"><label class="form-label small fw-bold" for="cvCom">Comune (provincia)</label><input class="form-control" id="cvCom" name="comune" value="<?php echo $h($s['comune']); ?>" maxlength="255"></div>
                <div class="col-md-5"><label class="form-label small fw-bold" for="cvInd">Indirizzo</label><input class="form-control" id="cvInd" name="indirizzo" value="<?php echo $h($s['indirizzo']); ?>" maxlength="255"></div>
                <div class="col-md-3"><label class="form-label small fw-bold" for="cvCf">Codice fiscale dell'istituto</label><input class="form-control font-monospace" id="cvCf" name="cf" value="<?php echo $h($s['cf']); ?>" maxlength="16" placeholder="11 cifre"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvPec">PEC della scuola</label><input type="email" class="form-control" id="cvPec" name="pec" value="<?php echo $h($s['pec']); ?>" maxlength="255"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvEm">Email di riferimento della scuola (per le comunicazioni)</label><input type="email" class="form-control" id="cvEm" name="email" value="<?php echo $h($s['email']); ?>" maxlength="255">
                    <div class="form-text">Il link personale di questa pagina arriva solo all'email con cui è stata aperta la compilazione.</div></div>
                <div class="col-12"><label class="form-label small fw-bold" for="cvLogo">Logo della scuola (facoltativo, PNG o JPG fino a 2 MB)</label>
                    <input type="file" class="form-control" id="cvLogo" name="logo" accept=".png,.jpg,.jpeg">
                    <?php if ($cc['logo']): ?><label class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="togli_logo" value="1"> Il logo è già caricato: spunta per toglierlo</label><?php endif; ?>
                    <div class="form-text">Compare in testa alla Convenzione e all'Allegato A.</div></div>
            </div>
        </div></section>

        <section class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold"><span class="cv-passo">2</span>Dirigente Scolastico</h2>
            <div class="row g-2">
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvDir">Nome e cognome</label><input class="form-control" id="cvDir" name="dirigente" value="<?php echo $h($s['dirigente']); ?>" maxlength="255" placeholder="Dott.ssa Maria Rossi"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvDcf">Codice fiscale</label><input class="form-control font-monospace ui-tt-uppercase" id="cvDcf" name="dir_cf" value="<?php echo $h($s['dir_cf']); ?>" maxlength="16"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvLn">Luogo di nascita</label><input class="form-control" id="cvLn" name="luogo_nascita" value="<?php echo $h($s['luogo_nascita']); ?>" maxlength="255"></div>
                <div class="col-md-6"><label class="form-label small fw-bold" for="cvDn">Data di nascita</label><input type="date" class="form-control" id="cvDn" name="data_nascita" value="<?php echo $h($s['data_nascita']); ?>"></div>
            </div>
            <p class="small text-secondary mb-0 mt-2">I campi lasciati vuoti restano evidenziati nel documento: potete completarli a mano prima della firma.</p>
        </div></section>

        <section class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h2 class="h5 fw-bold"><span class="cv-passo">3</span>Attività dell'Allegato A</h2>
            <p class="small text-secondary">Scegli le attività da inserire nell'Allegato A. Per ognuna puoi indicare il numero di studenti e il docente tutor della scuola.</p>
            <h3 class="h6 fw-bold mt-2">Attività già prenotate dalla scuola</h3>
            <?php foreach ($prenotate as $id => $p): $k = 'pr' . $id; $v = dati_convenzione_precompilata($conn, $p); $sel = isset($scelte[$k]); ?>
                <div class="cv-att<?php echo $sel ? ' scelta' : ''; ?>">
                    <label class="form-check fw-bold mb-1"><input class="form-check-input cv-sc" type="checkbox" name="att[<?php echo $k; ?>][scelta]" value="1"<?php echo $sel ? ' checked' : ''; ?>> <?php echo $h($v['TITOLO']); ?></label>
                    <div class="small text-secondary mb-1"><?php echo $h($v['PERIODO']); ?><?php echo $v['DURATA'] ? ' · ' . $h($v['DURATA']) : ''; ?> · prenotazione <?php echo $h($p['codice_prenotazione']); ?></div>
                    <div class="row g-2"><div class="col-sm-4"><input type="number" min="0" max="500" class="form-control form-control-sm" name="att[<?php echo $k; ?>][studenti]" value="<?php echo $h(($scelte[$k]['studenti'] ?? 0) ?: $v['STUDENTI']); ?>" placeholder="N. studenti" aria-label="Numero di studenti"></div>
                        <div class="col-sm-8"><input class="form-control form-control-sm" name="att[<?php echo $k; ?>][tutor]" value="<?php echo $h(($scelte[$k]['tutor'] ?? '') ?: $v['TUTOR_SCUOLA']); ?>" placeholder="Docente tutor della scuola" aria-label="Docente tutor della scuola" maxlength="150"></div></div>
                </div>
            <?php endforeach; ?>
            <?php if ($prenotabili): ?>
                <details class="mt-3"<?php echo $non_prenotate ? ' open' : ''; ?>><summary class="fw-bold">Altre attività di Formazione Scuola Lavoro da prenotare (<?php echo count($prenotabili); ?>)</summary>
                    <p class="small text-secondary mt-2">Se le inserisci nell'Allegato A, <strong>ricordati di prenotarle</strong>: la convenzione non sostituisce la prenotazione.</p>
                    <?php foreach ($prenotabili as $id => $ev): $k = 'ev' . $id; $v = dati_convenzione_precompilata($conn, $ev); $sel = isset($scelte[$k]); ?>
                        <div class="cv-att<?php echo $sel ? ' scelta' : ''; ?>">
                            <label class="form-check fw-bold mb-1"><input class="form-check-input cv-sc" type="checkbox" name="att[<?php echo $k; ?>][scelta]" value="1"<?php echo $sel ? ' checked' : ''; ?>> <?php echo $h($v['TITOLO']); ?></label>
                            <div class="small text-secondary mb-1"><?php echo $h($v['PERIODO']); ?><?php echo $v['DURATA'] ? ' · ' . $h($v['DURATA']) : ''; ?> · <a href="<?php echo $h($ev['slug'] . '.php?' . (($ev['tipo'] ?? '') === 'progetto' ? 'progetto' : 'evento') . '=' . $id); ?>" target="_blank" rel="noopener">vedi e prenota<span class="visually-hidden"> (si apre in una nuova scheda)</span></a></div>
                            <div class="cv-promemoria alert alert-warning small py-1 px-2 mb-1"<?php echo $sel ? '' : ' hidden'; ?>><i class="fa fa-bell me-1" aria-hidden="true"></i>Non l'hai ancora prenotata: ricordati di farlo.</div>
                            <div class="row g-2"><div class="col-sm-4"><input type="number" min="0" max="500" class="form-control form-control-sm" name="att[<?php echo $k; ?>][studenti]" value="<?php echo $h($scelte[$k]['studenti'] ?? ''); ?>" placeholder="N. studenti" aria-label="Numero di studenti"></div>
                                <div class="col-sm-8"><input class="form-control form-control-sm" name="att[<?php echo $k; ?>][tutor]" value="<?php echo $h($scelte[$k]['tutor'] ?? ''); ?>" placeholder="Docente tutor della scuola" aria-label="Docente tutor della scuola" maxlength="150"></div></div>
                        </div>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>
        </div></section>

        <button type="submit" name="salva_convenzione" value="1" class="btn btn-lg fw-bold text-white mb-2 ui-bg-rosso"><i class="fa fa-wand-magic-sparkles me-1" aria-hidden="true"></i><?php echo $modo_aggiorna ? "Aggiorna l'Allegato A" : ($dati && !empty($dati['attivita']) ? 'Aggiorna i documenti' : 'Prepara i documenti'); ?></button>
        <p class="small text-secondary">Preferisci i modelli vuoti? <a href="<?php echo $h($cfg['modello']); ?>">Convenzione</a> · <a href="<?php echo $h($cfg['allegato']); ?>">Allegato A</a></p>
    </form>
    <?php echo ui_avviso_modifiche('formConvenzione'); ?>
    <?php echo ui_bozza_automatica('formConvenzione', 'fsl', (string)$cc['token'], \App\Core\App::per($conn)->get(\App\Portale\BozzaModulo::class)->leggi((string)\App\Portale\BozzaModulo::chiave('fsl', (string)$cc['token']))); ?>
</div>
<script nonce="<?php echo csp_nonce(); ?>">
document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('cv-sc')) return;
    var box = e.target.closest('.cv-att'); box.classList.toggle('scelta', e.target.checked);
    var pm = box.querySelector('.cv-promemoria'); if (pm) pm.hidden = !e.target.checked;
});
</script>
<style>
/* Colore e misura usati solo in questa pagina */
.ui-c-blu-testo { color: var(--dibest-blu-testo) !important; }
.ui-maxh-320 { max-height: 320px; }
</style>
<?php require_once 'footer.php'; ?>
