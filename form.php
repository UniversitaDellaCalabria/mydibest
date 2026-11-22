<?php
// form.php - MyForms: pagina pubblica per compilare un modulo (?c=<codice>). Si apre anche senza accesso, se il modulo lo prevede.
// Le regole (chi può rispondere, periodo, numero massimo, una per persona, modifica) stanno in src/Forms/ServizioForms; i controlli di chi non ha fatto
// l'accesso (domanda anti-robot, campo trappola, limite per connessione) in src/Forms/ControlliAnonimi; le email in src/Forms/NotificheForms.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
sync_sso_user($conn); // accesso automatico dalla sessione SSO di Ateneo, come fa header.php (qui serve prima di decidere cosa mostrare)

use App\Didattica\CampiModulo;
use App\Forms\ServizioForms;

$app = \App\Core\App::per($conn);
$sv = $app->get(ServizioForms::class);
$formsRepo = $app->get(\App\Forms\FormRepository::class);
$rispRepo = $app->get(\App\Forms\RispostaRepository::class);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$dataIt = fn(?string $d) => $d ? date('d/m/Y', strtotime($d)) : '';
$dataOra = fn(?string $d) => $d ? date('d/m/Y \a\l\l\e H:i', strtotime($d)) : '';

$utente = !empty($_SESSION['utente_id']) ? $app->get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']) : null;
$loggato = $utente !== null;
$codice = (string)($_GET['c'] ?? '');
$form = preg_match('/^[A-Za-z0-9_-]{8,45}$/', $codice) === 1 ? $formsRepo->perCodice($codice) : null;

// Un modulo che non esiste o è ancora in bozza non si vede
if (!$form || ($form['stato'] ?? '') === 'bozza') {
    http_response_code(404);
    $page_cfg['titolo'] = 'Modulo non trovato';
    require_once 'header.php';
    echo '<div class="container my-5" style="max-width:720px;"><h1 class="fw-bold h2">Modulo non trovato</h1><p>Il modulo che cerchi non esiste o non è più disponibile.</p><p><a href="forms.php">Vedi i moduli da compilare</a></p></div>';
    require_once 'footer.php';
    exit;
}

$url_form = 'form.php?c=' . rawurlencode((string)$form['codice']);
$url_accedi = 'saml_login.php?redirect=' . urlencode($url_form);
$campi = CampiModulo::da((string)($form['campi_json'] ?? ''));
$ha_file = in_array('file', array_column($campi, 'tipo'), true);
$ricevuta_chiave = 'myforms_ricevuta';

// ── Dopo l'invio: ringraziamento e ricevuta. Si arriva qui con un rimando (così F5 non rimanda la risposta); la ricevuta sta in sessione ──
$appena_inviato = isset($_GET['inviato']) && isset($_SESSION[$ricevuta_chiave][$form['codice']]) ? $_SESSION[$ricevuta_chiave][$form['codice']] : null;

// ── Cosa può fare questa persona adesso ──
// modalita: 'compila' (nuova risposta), 'modifica' (corregge la sua) o 'messaggio' (non può: si spiega perché)
$modalita = 'messaggio';
$messaggio = ['tipo' => 'secondary', 'icona' => 'fa-circle-info', 'testo' => ''];
$mostra_accedi = false;
$mia = $sv->rispostaDi($form, $utente);
$stato = $sv->statoPerPersona($form, $utente);
$gia_risposto = $mia ? 'Hai già risposto il ' . $dataIt($mia['inviata_il']) . ' (ricevuta ' . $mia['codice_ricevuta'] . ').' : '';

if ($appena_inviato) {
    $modalita = 'inviato';
} elseif ($stato['codice'] === ServizioForms::CHIUSO) {
    $messaggio = ['tipo' => 'secondary', 'icona' => 'fa-calendar-xmark', 'testo' => $stato['motivo'] . ($gia_risposto !== '' ? ' ' . $gia_risposto : '')];
} elseif ($mia && !empty($form['modificabile']) && $sv->puoModificare($form, $mia, $utente)) {
    $modalita = 'modifica'; // anche se il modulo è «pieno»: chi ha già risposto può correggere
} elseif ($stato['codice'] === ServizioForms::PIENO) {
    $messaggio = ['tipo' => 'secondary', 'icona' => 'fa-users', 'testo' => $stato['motivo'] . ($gia_risposto !== '' ? ' ' . $gia_risposto : '')];
} elseif ($stato['codice'] === ServizioForms::PROFILO) {
    if (!$loggato) {
        $messaggio = ['tipo' => 'info', 'icona' => 'fa-key', 'testo' => 'Per compilare questo modulo devi fare l\'accesso.'];
        $mostra_accedi = true;
    } else {
        $per = $sv->profiliInParole($form);
        $messaggio = ['tipo' => 'warning', 'icona' => 'fa-lock', 'testo' => 'Questo modulo è riservato a: ' . ($per !== '' ? $per : 'nessun profilo') . '.'];
    }
} elseif ($stato['codice'] === ServizioForms::GIA) {
    $messaggio = ['tipo' => 'success', 'icona' => 'fa-circle-check', 'testo' => $gia_risposto !== '' ? $gia_risposto : 'Hai già risposto a questo modulo.'];
} elseif (!$loggato && $ha_file) {
    $messaggio = ['tipo' => 'info', 'icona' => 'fa-paperclip', 'testo' => 'Questo modulo chiede di allegare dei file: per rispondere serve l\'accesso.'];
    $mostra_accedi = true;
} else {
    $modalita = 'compila';
}

// ── Invio ──
$errori = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($modalita === 'compila' || $modalita === 'modifica')) {
    csrf_verify($_POST['csrf_token'] ?? '');
    if ($modalita === 'modifica') {
        $esito = $sv->modificaRisposta($form, $mia, $utente, $_POST, $_FILES);
        if ($esito['ok']) {
            header('Location: ' . $url_form . '&modificato=1');
            exit;
        }
        $errori = $esito['errori'];
    } else {
        // Chi non ha fatto l'accesso: trappola per i robot, limite di invii e domanda di controllo
        $errore_controllo = $loggato ? null : $app->get(\App\Forms\ControlliAnonimi::class)->verifica((int)$form['id'], $_POST, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($errore_controllo !== null) {
            $errori = [$errore_controllo];
        } else {
            $esito = $sv->salvaRisposta($form, $utente, $_POST, $_FILES, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
            if ($esito['ok']) {
                $riga = $rispRepo->perId((int)$esito['id']);
                if ($riga) {
                    // Ricevuta ed avviso per email: un errore qui non cambia il fatto che la risposta è salvata (resta nel log)
                    $app->get(\App\Forms\NotificheForms::class)->rispostaInviata($form, $utente, $riga);
                    $_SESSION[$ricevuta_chiave][$form['codice']] = ['codice' => (string)$riga['codice_ricevuta'], 'quando' => (string)$riga['inviata_il']];
                }
                header('Location: ' . $url_form . '&inviato=1');
                exit;
            }
            $errori = $esito['errori'];
        }
    }
}

// Errori accanto ai campi: i messaggi di LetturaRisposte cominciano con «etichetta: » (quelli generali restano solo nell'elenco in alto)
$errori_campo = [];
foreach ($errori as $e) {
    foreach ($campi as $c) {
        $prefisso = $c['etichetta'] . ': ';
        if (str_starts_with($e, $prefisso)) {
            $errori_campo[$c['nome']] = substr($e, strlen($prefisso));
            break;
        }
    }
}

$vecchie = $mia ? (json_decode((string)$mia['risposte_json'], true) ?: []) : [];
$iniziali = ($modalita === 'modifica' && $mia) ? valori_iniziali_pratica($conn, $campi, $vecchie) : [];
$e_post = $_SERVER['REQUEST_METHOD'] === 'POST';
$descrizione = pulisci_html_semplice((string)($form['descrizione'] ?? '')); // pulita anche in uscita, non solo quando si salva

$page_cfg['titolo'] = $form['titolo'] !== '' ? $form['titolo'] : 'Modulo';
require_once 'header.php';
$con_tabelle = in_array('tabella', array_column($campi, 'tipo'), true);
?>
<div class="container my-4" style="max-width: <?php echo $con_tabelle ? 1180 : 920; ?>px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <a href="forms.php">MyForms</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary"><?php echo $h($form['titolo']); ?></span></nav>
    <h1 class="fw-bold h2 mb-2"><?php echo $h($form['titolo']); ?></h1>
    <?php if ($descrizione !== ''): ?><div class="mb-3"><?php echo $descrizione; ?></div><?php endif; ?>

    <?php if ($modalita === 'inviato'): ?>
        <div class="alert alert-success" role="status">
            <div class="fw-bold mb-1"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Risposta inviata</div>
            <div><?php echo trim((string)$form['messaggio_fine']) !== '' ? nl2br($h($form['messaggio_fine'])) : 'Grazie: la tua risposta è stata registrata.'; ?></div>
        </div>
        <div class="card border-0 shadow-sm p-3 mb-3" style="border-radius:12px;">
            <div class="small text-secondary">Codice di ricevuta</div>
            <div class="fs-5 fw-bold font-monospace" style="overflow-wrap:anywhere;"><?php echo $h($appena_inviato['codice']); ?></div>
            <div class="small text-secondary mt-1">Inviata il <?php echo $h($dataOra($appena_inviato['quando'])); ?>. Conserva il codice: ti serve se devi chiedere informazioni sulla tua risposta.<?php
                if (!empty($form['anonima'])) echo ' Il modulo è anonimo: la ricevuta è solo questa, conservala.'; // nei form anonimi nessuna email con la ricevuta
                elseif (!empty($form['ricevuta_email']) && $loggato && trim((string)($utente['email'] ?? '')) !== '') echo ' Ti abbiamo mandato la ricevuta anche per email.';
            ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="forms.php" class="btn btn-outline-secondary"><i class="fa fa-list me-1" aria-hidden="true"></i>Altri moduli da compilare</a>
            <?php if ($loggato): ?><a href="area_personale.php" class="btn btn-outline-secondary"><i class="fa fa-house me-1" aria-hidden="true"></i>La mia area personale</a><?php endif; ?>
            <?php if ($loggato && !empty($form['modificabile'])): ?><a href="<?php echo $h($url_form); ?>" class="btn btn-outline-primary"><i class="fa fa-pen me-1" aria-hidden="true"></i>Modifica la risposta</a><?php endif; ?>
        </div>

    <?php elseif ($modalita === 'messaggio'): ?>
        <div class="alert alert-<?php echo $h($messaggio['tipo']); ?> d-flex flex-wrap align-items-center gap-2" role="status">
            <i class="fa <?php echo $h($messaggio['icona']); ?>" aria-hidden="true"></i>
            <span><?php echo $h($messaggio['testo']); ?></span>
            <?php if ($mostra_accedi): ?><a class="btn btn-sm btn-primary fw-bold ms-auto" href="<?php echo $h($url_accedi); ?>"><i class="fa fa-key me-1" aria-hidden="true"></i>Accedi per compilare</a><?php endif; ?>
        </div>
        <p><a href="forms.php">Vedi gli altri moduli da compilare</a></p>

    <?php else: ?>
        <?php if (isset($_GET['modificato']) && !$e_post): ?><div class="alert alert-success" role="status"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Modifiche salvate. Hai ancora tempo per correggere la risposta finché il modulo è aperto.</div><?php endif; ?>
        <?php if ($errori): ?><div class="alert alert-danger" role="alert"><strong>Controlla i dati:</strong><ul class="mb-0"><?php foreach ($errori as $e): ?><li><?php echo $h($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <?php if (!empty($form['aperta_al'])): ?><p class="small text-secondary"><i class="fa fa-calendar me-1" aria-hidden="true"></i>Puoi rispondere fino al <?php echo $h($dataOra($form['aperta_al'])); ?>.</p><?php endif; ?>
        <form method="POST" action="<?php echo $h($url_form); ?>" enctype="multipart/form-data" class="card border-0 shadow-sm p-3 p-md-4" style="border-radius:12px;">
            <?php csrf_field(); ?>
            <?php if ($loggato): ?>
                <div class="bg-light rounded p-2 small mb-3"><i class="fa fa-user me-1" aria-hidden="true"></i>Stai rispondendo come <strong><?php echo $h(trim($utente['nome'] . ' ' . $utente['cognome'])); ?></strong><?php echo trim((string)$utente['email']) !== '' ? ' · ' . $h($utente['email']) : ''; ?>
                    <?php if (!empty($form['anonima'])): ?><div class="text-secondary">Il modulo è anonimo: la risposta non porta il tuo nome né la tua email.</div><?php endif; ?></div>
            <?php elseif (!empty($form['anonima'])): ?>
                <div class="bg-light rounded p-2 small mb-3"><i class="fa fa-user-secret me-1" aria-hidden="true"></i>Il modulo è anonimo: non registriamo chi risponde.</div>
            <?php endif; ?>
            <?php echo html_datalist_didattica($conn, $campi); ?>
            <div class="pagine-modulo"><div class="row gx-4">
            <?php foreach ($campi as $c):
                if ($c['tipo'] === 'file') { $valore = ''; }
                elseif ($e_post) { $valore = valori_post_campo($c); }
                elseif (array_key_exists($c['nome'], $iniziali)) { $valore = $iniziali[$c['nome']]; }
                // Dal profilo si precompila solo nei form non anonimi: in uno anonimo codice fiscale, matricola o telefono resterebbero nella risposta
                else { $valore = ($loggato && empty($form['anonima'])) ? valore_iniziale_campo($c, $utente) : ''; }
                echo html_campo_pratica($c, $valore, $conn, true);
            endforeach; ?>
            </div></div>
            <?php if (!$campi): ?><p class="text-secondary">Questo modulo non ha domande.</p><?php endif; ?>
            <?php $allegati_presenti = array_filter($vecchie, fn($r) => !empty($r['file'])); if ($modalita === 'modifica' && $allegati_presenti): ?><div class="small text-secondary mb-2"><i class="fa fa-paperclip me-1" aria-hidden="true"></i>Allegati già caricati: <?php echo $h(implode(', ', array_map(fn($r) => (string)($r['nome_file'] ?? ''), $allegati_presenti))); ?>. Restano se non ne scegli di nuovi.</div><script nonce="<?php echo csp_nonce(); ?>">document.querySelectorAll('input[type=file][required]').forEach(function (i) { i.required = false; });</script><?php endif; ?>

            <?php if (!$loggato): $cap = $app->get(\App\Forms\ControlliAnonimi::class)->domanda(); ?>
                <!-- Controllo anti-robot: solo per chi non ha fatto l'accesso -->
                <div class="mt-3 p-3 rounded border bg-light">
                    <label for="captcha_form" class="form-label small fw-bold mb-1"><i class="fa fa-shield-halved me-1" aria-hidden="true"></i>Controllo anti-robot: <?php echo $h($cap['domanda']); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="captcha_risposta" id="captcha_form" class="form-control form-control-sm" style="max-width:140px;" inputmode="numeric" pattern="[0-9]*" maxlength="3" autocomplete="off" required>
                    <input type="hidden" name="captcha_id" value="<?php echo $h($cap['id']); ?>">
                    <div class="form-text">Con l'accesso (SPID, CIE o credenziali Unical) questa domanda non viene chiesta.</div>
                </div>
                <!-- Campo trappola: invisibile alle persone, i robot lo compilano -->
                <div style="position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden;" aria-hidden="true">
                    <label for="sitoWebForm">Sito web (lascia vuoto)</label>
                    <input type="text" name="sito_web" id="sitoWebForm" value="" tabindex="-1" autocomplete="off">
                </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
                <?php if ($modalita === 'modifica'): ?>
                    <button type="submit" class="btn btn-success fw-bold invia-modulo"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva le modifiche</button>
                    <a href="forms.php" class="btn btn-outline-secondary">Annulla</a>
                <?php else: ?>
                    <button type="submit" class="btn btn-success fw-bold invia-modulo"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia la risposta</button>
                <?php endif; ?>
            </div>
            <div class="form-text mt-2"><?php echo $modalita === 'modifica' ? 'Stai correggendo la risposta che hai già inviato' . (!empty($form['aperta_al']) ? ': puoi farlo fino al ' . $h($dataOra($form['aperta_al'])) : ' finché il modulo è aperto') . '.' : (!empty($form['modificabile']) && $loggato ? 'Dopo l\'invio potrai correggere la risposta finché il modulo è aperto.' : ''); ?>
                Inviando il modulo dichiari di aver letto l'<a href="privacy.php" target="_blank" rel="noopener">informativa sul trattamento dei dati personali</a>.</div>
        </form>
        <?php echo js_tabelle_pratica(); ?>
        <?php if ($errori_campo): ?>
        <script nonce="<?php echo csp_nonce(); ?>">
        (function () {
            var errori = <?php echo json_encode($errori_campo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            document.querySelectorAll('.campo-pratica').forEach(function (el) {
                var m = errori[el.getAttribute('data-nome')];
                if (!m) { return; }
                var d = document.createElement('div');
                d.className = 'invalid-feedback d-block fw-bold';
                d.setAttribute('role', 'alert');
                d.textContent = m.charAt(0).toUpperCase() + m.slice(1) + '.';
                el.appendChild(d);
                el.querySelectorAll('input, select, textarea').forEach(function (i) { i.classList.add('is-invalid'); });
            });
        })();
        </script>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
