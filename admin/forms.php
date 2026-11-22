<?php
// forms.php - MyForms: moduli online da compilare (questionari, raccolte di informazioni, iscrizioni), risposte in Excel.
// Elenco (admin/forms.php), scheda (?id=N o ?azione=nuovo), anteprima (?id=N&anteprima=1). Dominio in src/Forms/.
// Il costruttore dei campi riusa i tipi della Didattica (src/Didattica/Costanti, CampiModulo) ma SENZA le anagrafi di
// Ateneo né i campi "ufficio": admin/parziali/costruttore_campi.php, scritto apposta (non tocca admin/didattica_moduli.php).
// Permessi: amministratori e proprietario vedono/gestiscono tutto; i co-gestori modificano, aprono/chiudono, duplicano
// ma non eliminano il form né cambiano i co-gestori (ServizioForms::puoGestire / puoEliminare).
require_once 'admin_header.php';
require_once __DIR__ . '/parziali/costruttore_campi.php';

// $puo_forms_vedere (calcolato in admin_header.php) include anche chi è co-gestore di almeno un form senza
// l'abilitazione al modulo: entra ed è gestito nella sezione "Co-gestori", ma non può crearne di nuovi ($puo_forms).
if (!$puo_forms_vedere) nega_accesso();

function admin_redirect($url) { vai_a($url); }

$app_f = \App\Core\App::per($conn);
$sv = $app_f->get(\App\Forms\ServizioForms::class);
$formsRepo = $app_f->get(\App\Forms\FormRepository::class);
$rispRepo = $app_f->get(\App\Forms\RispostaRepository::class);
$utentiRepo = $app_f->get(\App\Auth\UtenteRepository::class);
$sito = $app_f->get(\App\Core\Sito::class);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$dataOra = fn(?string $d) => $d ? date('d/m/Y H:i', strtotime($d)) : '';

// Tipi di campo offerti dal costruttore (e controllati di nuovo lato server da CostruttoreCampi::daPost()): tutti
// tranne quelli dalle anagrafi di Ateneo (corso di studio, insegnamenti, docente, anno accademico, azienda
// convenzionata): non hanno senso fuori dalla Didattica. Niente campi "ufficio".
$tipi_ammessi = \App\Forms\CostruttoreCampi::tipiAmmessi();

$nominativo = function (int $uid) use ($utentiRepo): string {
    $u = $uid ? $utentiRepo->perId($uid) : null;
    return $u ? trim($u['cognome'] . ' ' . $u['nome']) ?: $u['email'] : '(persona non trovata)';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $fid = (int)($_POST['form_id'] ?? 0);
    $f_post = $fid ? $formsRepo->perId($fid) : null;

    if (isset($_POST['salva_form'])) {
        if ($fid && (!$f_post || !$sv->puoGestire($f_post, $utente_admin))) nega_accesso();
        if (!$fid && !$puo_forms) nega_accesso(); // chi entra solo da co-gestore non crea form nuovi

        // Periodo: formato di datetime-local (yyyy-mm-ddThh:mm), data e ora che esistono davvero (FiltriRisposte::dataOra) e "al" non prima di "dal"
        $tornaAllaScheda = fn() => admin_redirect('forms.php' . ($fid ? "?id=$fid" : '?azione=nuovo'));
        $aperta_dal_raw = trim((string)($_POST['aperta_dal'] ?? ''));
        $aperta_al_raw = trim((string)($_POST['aperta_al'] ?? ''));
        $data_valida = fn(string $v): bool => $v === '' || \App\Forms\FiltriRisposte::dataOra($v) !== '';
        if (!$data_valida($aperta_dal_raw) || !$data_valida($aperta_al_raw)) {
            flash_set('La data non è valida: controlla "Aperto dal" e "al".', 'danger');
            $tornaAllaScheda();
        }
        if ($aperta_dal_raw !== '' && $aperta_al_raw !== '' && $aperta_al_raw < $aperta_dal_raw) {
            flash_set('La data "al" non può essere prima della data "dal".', 'danger');
            $tornaAllaScheda();
        }

        $scarti = [];
        $dati = [
            'titolo' => trim((string)($_POST['titolo'] ?? '')),
            'descrizione' => pulisci_html_semplice((string)($_POST['descrizione'] ?? '')),
            'campi_json' => \App\Forms\CostruttoreCampi::daPost($_POST, 'c', $tipi_ammessi),
            'pubblico_json' => (string)json_encode(pubblico_da_post('pubblico', $_POST), JSON_UNESCAPED_UNICODE),
            'aperta_dal' => $aperta_dal_raw !== '' ? str_replace('T', ' ', $aperta_dal_raw) . ':00' : null,
            'aperta_al' => $aperta_al_raw !== '' ? str_replace('T', ' ', $aperta_al_raw) . ':00' : null,
            'una_per_persona' => !empty($_POST['una_per_persona']),
            'modificabile' => !empty($_POST['modificabile']),
            'anonima' => !empty($_POST['anonima']),
            'max_risposte' => max(0, (int)($_POST['max_risposte'] ?? 0)),
            'messaggio_fine' => trim((string)($_POST['messaggio_fine'] ?? '')),
            'email_notifica' => implode(', ', normalizza_lista_email((string)($_POST['email_notifica'] ?? ''), 10, $scarti)),
            'ricevuta_email' => !empty($_POST['ricevuta_email']),
            'conserva_mesi' => trim((string)($_POST['conserva_mesi'] ?? '')) !== '' ? max(1, (int)$_POST['conserva_mesi']) : null,
        ];
        $avviso_scarti = $scarti ? ' Email scartate (non valide): ' . implode(', ', $scarti) . '.' : '';
        if ($dati['titolo'] === '') {
            flash_set('Il titolo è obbligatorio.', 'danger');
            $tornaAllaScheda();
        }
        if (!$fid) {
            $dati['proprietario_id'] = (int)$utente_admin['id'];
            $nid = $sv->crea($dati);
            registra_log_audit($conn, 'MyForms: form creato', ['Titolo' => $dati['titolo']]);
            flash_set('Form creato (in bozza).' . $avviso_scarti);
            admin_redirect("forms.php?id=$nid");
        }
        $sv->modifica($fid, $dati);
        registra_log_audit($conn, 'MyForms: form modificato', ['Titolo' => $dati['titolo']]);
        flash_set('Form salvato.' . $avviso_scarti);
        admin_redirect("forms.php?id=$fid");
    }

    if (!$f_post || !$sv->puoGestire($f_post, $utente_admin)) nega_accesso();

    if (isset($_POST['duplica_form'])) {
        $nid = $sv->duplica($fid);
        registra_log_audit($conn, 'MyForms: form duplicato', ['Da' => $fid, 'Copia' => $nid]);
        flash_set('Copia creata in bozza: ritocca il titolo e quello che cambia.');
        admin_redirect("forms.php?id=$nid");
    }
    if (isset($_POST['apri_form'])) {
        $campi_apri = \App\Didattica\CampiModulo::da((string)$f_post['campi_json']);
        $pubblico_apri = json_decode((string)$f_post['pubblico_json'], true) ?: [];
        $errori_apri = [];
        if (trim((string)$f_post['titolo']) === '') $errori_apri[] = 'manca il titolo';
        if (!$campi_apri) $errori_apri[] = 'non ha nessuna domanda';
        if (empty($pubblico_apri['profili'])) $errori_apri[] = 'non ha nessun profilo scelto (a chi si rivolge)';
        if ($errori_apri) {
            flash_set('Non si può aprire il form: ' . implode(', ', $errori_apri) . '.', 'danger');
        } else {
            $sv->apri($fid);
            registra_log_audit($conn, 'MyForms: form aperto', ['Titolo' => $f_post['titolo']]);
            flash_set('Form aperto: ora è compilabile.');
        }
        admin_redirect("forms.php?id=$fid");
    }
    if (isset($_POST['chiudi_form'])) {
        $sv->chiudi($fid);
        registra_log_audit($conn, 'MyForms: form chiuso', ['Titolo' => $f_post['titolo']]);
        flash_set('Form chiuso: non si può più compilare.', 'warning');
        admin_redirect("forms.php?id=$fid");
    }
    if (isset($_POST['aggiungi_gestore'])) {
        if (!$sv->puoEliminare($f_post, $utente_admin)) nega_accesso();
        $err = $sv->aggiungiGestoreDaAnagrafe($fid, (string)($_POST['persona_id'] ?? ''));
        if (!$err) registra_log_audit($conn, 'MyForms: co-gestore aggiunto', ['Form' => $fid]);
        flash_set($err ?? 'Co-gestore aggiunto.', $err ? 'danger' : 'success');
        admin_redirect("forms.php?id=$fid");
    }
    if (isset($_POST['togli_gestore'])) {
        if (!$sv->puoEliminare($f_post, $utente_admin)) nega_accesso();
        $sv->togliGestore($fid, (int)$_POST['togli_gestore']);
        registra_log_audit($conn, 'MyForms: co-gestore tolto', ['Form' => $fid]);
        flash_set('Co-gestore tolto.', 'warning');
        admin_redirect("forms.php?id=$fid");
    }
    if (isset($_POST['elimina_form'])) {
        if (!$sv->puoEliminare($f_post, $utente_admin)) nega_accesso();
        $sv->elimina($fid);
        registra_log_audit($conn, 'MyForms: form eliminato', ['Titolo' => $f_post['titolo']]);
        flash_set('Form eliminato.', 'warning');
        admin_redirect('forms.php');
    }
}

$id = (int)($_GET['id'] ?? 0);
$nuovo = !$id && ($_GET['azione'] ?? '') === 'nuovo';
if ($nuovo && !$puo_forms) nega_accesso(); // chi entra solo da co-gestore non crea form nuovi
$anteprima = $id > 0 && isset($_GET['anteprima']);
$f = $id ? $formsRepo->perId($id) : null;
if ($id && (!$f || !$sv->puoGestire($f, $utente_admin))) nega_accesso();
$mostra_scheda = $f || $nuovo;

$page_cfg['titolo'] = 'MyForms';
?>
<style>
.cc-campo { display: grid; grid-template-columns: 2fr 1.4fr 2fr auto 1.6fr 34px 34px; gap: .4rem; align-items: center; margin-bottom: .4rem; padding: .3rem; border-radius: 6px; }
.cc-campo.cc-testa { margin-bottom: 0; padding-bottom: 0; }
.cc-logica { grid-column: 1 / -1; background: #fff7ed; border: 1px dashed #fdba74; border-radius: 6px; padding: .5rem; }
.cc-col-riga { display: grid; grid-template-columns: 2fr 1.6fr 2fr 30px; gap: .3rem; margin-bottom: .25rem; }
@media (max-width: 991.98px) { .cc-campo { grid-template-columns: 1fr 1fr; } }
</style>
<?php echo flash_html(); ?>

<?php if ($anteprima && $f): ?>
    <?php
    $campi_ap = \App\Didattica\CampiModulo::da((string)$f['campi_json']);
    ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status">
        <i class="fa fa-eye" aria-hidden="true"></i>
        <span><strong>Anteprima:</strong> le risposte non vengono salvate. Il modulo come lo vedrà chi risponde.</span>
        <a class="btn btn-sm btn-outline-secondary ms-auto" href="forms.php?id=<?php echo (int)$f['id']; ?>"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Torna alla scheda</a>
    </div>
    <div class="container" style="max-width: <?php echo in_array('tabella', array_column($campi_ap, 'tipo'), true) ? 1180 : 920; ?>px;">
        <h1 class="fw-bold h3 mb-2"><?php echo $h($f['titolo']); ?></h1>
        <?php $descrizione_pulita = pulisci_html_semplice((string)$f['descrizione']); if ($descrizione_pulita !== ''): ?><div class="mb-3"><?php echo $descrizione_pulita; ?></div><?php endif; ?>
        <div class="card border-0 shadow-sm p-3 p-md-4" style="border-radius:12px;">
            <?php echo html_datalist_didattica($conn, $campi_ap); ?>
            <div class="pagine-modulo"><div class="row gx-4">
                <?php foreach ($campi_ap as $c_ap): echo html_campo_pratica($c_ap, '', $conn, true); endforeach; ?>
            </div></div>
            <?php if (!$campi_ap): ?><p class="text-secondary mb-0">Il modulo non ha ancora nessuna domanda.</p><?php endif; ?>
        </div>
    </div>
    <?php echo js_tabelle_pratica(); ?>

<?php elseif ($mostra_scheda): ?>
    <?php
    $fd = $f ?: ['id' => 0, 'codice' => '', 'titolo' => '', 'descrizione' => '', 'campi_json' => null, 'pubblico_json' => null, 'stato' => 'bozza',
        'aperta_dal' => null, 'aperta_al' => null, 'una_per_persona' => 1, 'modificabile' => 0, 'anonima' => 0, 'max_risposte' => 0,
        'messaggio_fine' => '', 'email_notifica' => '', 'ricevuta_email' => 0, 'conserva_mesi' => null, 'proprietario_id' => (int)$utente_admin['id']];
    $campi_fd = json_decode((string)$fd['campi_json'], true) ?: [];
    $pubblico_fd = json_decode((string)$fd['pubblico_json'], true) ?: ['profili' => []];
    $puo_eliminare_fd = $f && $sv->puoEliminare($f, $utente_admin);
    $ha_campo_file = (bool)array_filter($campi_fd, fn($c) => ($c['tipo'] ?? '') === 'file');
    $n_risposte_fd = $f ? $rispRepo->conta((int)$f['id']) : 0;
    ?>
    <?php echo pn_testata($f ? 'Modifica form' : 'Nuovo form', 'fa-clipboard-list', 'MyForms: un modulo da compilare con risposte in Excel.',
        '<a class="btn btn-outline-secondary btn-sm" href="../guida.php?r=myforms" target="_blank" rel="noopener"><i class="fa fa-circle-question me-1" aria-hidden="true"></i>Guida</a> '
        . '<a class="btn btn-outline-secondary btn-sm" href="forms.php"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Elenco form</a>'); ?>

    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="form_id" value="<?php echo (int)$fd['id']; ?>">
        <div class="row g-2">
            <div class="col-md-8"><label class="form-label small fw-bold" for="mfTit">Titolo <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="mfTit" name="titolo" value="<?php echo $h($fd['titolo']); ?>" maxlength="200" required></div>
            <div class="col-md-4 d-flex align-items-end">
                <?php echo pn_stato(['bozza' => 'Bozza', 'aperta' => 'Aperto', 'chiusa' => 'Chiuso'][$fd['stato']] ?? $fd['stato'], ['bozza' => 'spento', 'aperta' => 'ok', 'chiusa' => 'info'][$fd['stato']] ?? 'info', ['bozza' => 'fa-pen', 'aperta' => 'fa-door-open', 'chiusa' => 'fa-lock'][$fd['stato']] ?? ''); ?>
            </div>
            <div class="col-12"><label class="form-label small fw-bold" for="mfDes">Descrizione (facoltativa)</label>
                <textarea class="form-control editor-html" id="mfDes" name="descrizione" rows="3"><?php echo $h($fd['descrizione']); ?></textarea></div>
        </div>

        <fieldset class="mt-3 border rounded p-2">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1">A chi si rivolge</legend>
            <?php echo html_scelta_pubblico('pubblico', $pubblico_fd); ?>
            <?php if (in_array('anonimi', $pubblico_fd['profili'] ?? [], true) && $ha_campo_file): ?>
                <div class="alert alert-warning mt-2 mb-0 py-2"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Il form ha un campo "allegato": chi non ha fatto l'accesso non potrà rispondere (serve l'accesso per caricare un file).</div>
            <?php endif; ?>
        </fieldset>

        <fieldset class="mt-3 border rounded p-2">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1">Opzioni delle risposte</legend>
            <div class="row g-2">
                <div class="col-md-3"><label class="form-label small" for="mfDal">Aperto dal</label><input type="datetime-local" class="form-control form-control-sm" id="mfDal" name="aperta_dal" value="<?php echo $fd['aperta_dal'] ? substr(str_replace(' ', 'T', $fd['aperta_dal']), 0, 16) : ''; ?>"></div>
                <div class="col-md-3"><label class="form-label small" for="mfAl">al</label><input type="datetime-local" class="form-control form-control-sm" id="mfAl" name="aperta_al" value="<?php echo $fd['aperta_al'] ? substr(str_replace(' ', 'T', $fd['aperta_al']), 0, 16) : ''; ?>"><div class="form-text">Vuoti = nessun limite di data. Alla scadenza il form si chiude da solo e il proprietario riceve un'email.</div></div>
                <div class="col-md-2"><label class="form-label small" for="mfMax">N. massimo di risposte</label><input type="number" min="0" class="form-control form-control-sm" id="mfMax" name="max_risposte" value="<?php echo (int)$fd['max_risposte']; ?>"><div class="form-text">0 = nessun limite. Arrivato al numero, il form si chiude da solo.</div></div>
                <div class="col-md-4"><label class="form-label small" for="mfMesi">Conserva le risposte per (mesi)</label><input type="number" min="1" class="form-control form-control-sm" id="mfMesi" name="conserva_mesi" value="<?php echo $h($fd['conserva_mesi'] ?? ''); ?>"><div class="form-text">Vuoto = non si cancellano da sole. Passati questi mesi dall'invio, le risposte e i file allegati si cancellano da soli; il proprietario riceve un'email 7 giorni prima.</div></div>
                <div class="col-md-4 d-flex align-items-center"><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="una_per_persona" value="1"<?php echo $fd['una_per_persona'] ? ' checked' : ''; ?>> Una sola risposta per persona</label></div>
                <div class="col-md-4 d-flex align-items-center"><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="modificabile" value="1"<?php echo $fd['modificabile'] ? ' checked' : ''; ?>> Si può modificare la risposta già data</label></div>
                <div class="col-md-4 d-flex align-items-center"><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="anonima" value="1"<?php echo $fd['anonima'] ? ' checked' : ''; ?>> Risposte anonime</label></div>
                <?php if ($fd['anonima']): ?><div class="col-12"><div class="alert alert-warning mb-0 py-2"><i class="fa fa-user-secret me-1" aria-hidden="true"></i>Form anonimo: chi gestisce il form non vedrà nome ed email di chi risponde.</div></div><?php endif; ?>
                <div class="col-md-6"><label class="form-label small" for="mfMsg">Messaggio di ringraziamento (facoltativo)</label><input type="text" class="form-control form-control-sm" id="mfMsg" name="messaggio_fine" maxlength="1000" value="<?php echo $h($fd['messaggio_fine']); ?>"></div>
                <div class="col-md-6"><label class="form-label small" for="mfEmail">Email di notifica (facoltative, separate da virgola)</label><input type="text" class="form-control form-control-sm" id="mfEmail" name="email_notifica" maxlength="500" value="<?php echo $h($fd['email_notifica']); ?>"></div>
                <div class="col-md-6 d-flex align-items-center"><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="ricevuta_email" value="1"<?php echo $fd['ricevuta_email'] ? ' checked' : ''; ?>> Manda una ricevuta per email a chi risponde</label></div>
            </div>
        </fieldset>

        <fieldset class="mt-3 border rounded p-2">
            <legend class="form-label small fw-bold float-none w-auto px-1 mb-1">Campi del modulo</legend>
            <p class="small text-secondary mb-2">Tipi, obbligatorietà, aiuto. Con <i class="fa fa-sliders" aria-hidden="true"></i> imposti le colonne di una tabella a righe e la logica: il campo compare solo se un'altra risposta ha un certo valore, oppure si compila in automatico.</p>
            <?php if ($n_risposte_fd > 0): ?>
                <div class="alert alert-warning py-2"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Il form ha già <?php echo (int)$n_risposte_fd; ?> risposte: se cambi o togli domande, le risposte già date restano com'erano (le domande nuove risulteranno vuote).</div>
            <?php endif; ?>
            <?php echo html_costruttore_campi('c', $campi_fd, $tipi_ammessi); ?>
        </fieldset>

        <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="submit" name="salva_form" value="1" class="btn btn-primary fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
            <a href="forms.php" class="btn btn-outline-secondary">Annulla</a>
            <?php if ($f): ?><a class="btn btn-outline-secondary ms-auto" href="forms.php?id=<?php echo (int)$f['id']; ?>&amp;anteprima=1"><i class="fa fa-eye me-1" aria-hidden="true"></i>Anteprima</a><?php endif; ?>
        </div>
    </div></form>

    <?php if ($f): ?>
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <h2 class="h6 fw-bold">Stato del form</h2>
                <form method="POST" class="d-flex flex-wrap gap-2 align-items-center"><?php csrf_field(); ?><input type="hidden" name="form_id" value="<?php echo (int)$f['id']; ?>">
                    <?php if ($f['stato'] === 'aperta'): ?>
                        <button type="submit" name="chiudi_form" value="1" class="btn btn-outline-warning btn-sm" data-confirm="Chiudere il form? Non si potrà più compilare.">Chiudi</button>
                    <?php else: ?>
                        <button type="submit" name="apri_form" value="1" class="btn btn-outline-success btn-sm">Apri</button>
                    <?php endif; ?>
                    <button type="submit" name="duplica_form" value="1" class="btn btn-outline-secondary btn-sm"><i class="fa fa-copy me-1" aria-hidden="true"></i>Duplica</button>
                    <?php if ($puo_eliminare_fd): ?><button type="submit" name="elimina_form" value="1" class="btn btn-outline-danger btn-sm" data-confirm="Eliminare il form «<?php echo $h($f['titolo']); ?>» con tutte le sue risposte?" data-conferma-scrivi="<?php echo $h($f['titolo']); ?>"><i class="fa fa-trash me-1" aria-hidden="true"></i>Elimina</button><?php endif; ?>
                </form>
                <p class="small text-secondary mt-2 mb-0"><?php echo (int)$rispRepo->conta((int)$f['id']); ?> risposte ricevute · proprietario: <?php echo $h($nominativo((int)$f['proprietario_id'])); ?></p>
                <a class="btn btn-success btn-sm fw-bold mt-2" href="forms_risposte.php?id=<?php echo (int)$f['id']; ?>"><i class="fa fa-inbox me-1" aria-hidden="true"></i>Risposte (<?php echo (int)$rispRepo->conta((int)$f['id']); ?>)</a>
            </div></div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <h2 class="h6 fw-bold">Link e QR da condividere</h2>
                <?php $link_pubblico = $sito->urlBase() . '/form.php?c=' . $f['codice']; ?>
                <p class="small mb-2"><code><?php echo $h($link_pubblico); ?></code></p>
                <?php echo qr_html($link_pubblico, 'width:130px', 'QR code del form'); ?>
                <p class="form-text mb-0">Il link porta alla pagina di compilazione. Finché il form è in bozza (o chiuso) chi lo apre vede «Modulo non trovato» o il motivo della chiusura.</p>
            </div></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-3"><div class="card-body">
        <h2 class="h6 fw-bold">Co-gestori (<?php echo count($formsRepo->gestori((int)$f['id'])); ?>)</h2>
        <p class="small text-secondary">Possono modificare il form, aprirlo/chiuderlo e duplicarlo. Solo il proprietario e gli amministratori possono eliminarlo o cambiare i co-gestori.</p>
        <?php foreach ($formsRepo->gestori((int)$f['id']) as $gid): ?>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="flex-grow-1"><?php echo $h($nominativo($gid)); ?></span>
                <?php if ($puo_eliminare_fd): ?>
                <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="form_id" value="<?php echo (int)$f['id']; ?>">
                    <button type="submit" name="togli_gestore" value="<?php echo (int)$gid; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Togliere <?php echo $h($nominativo($gid)); ?> dai co-gestori?">Togli</button>
                </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$formsRepo->gestori((int)$f['id'])): ?><p class="small text-secondary">Nessun co-gestore.</p><?php endif; ?>
        <?php if ($puo_eliminare_fd): ?>
        <h3 class="h6 fw-bold mt-3">Aggiungi un co-gestore dall'anagrafe di Ateneo</h3>
        <form method="POST" id="formFormsGestore" class="bg-light rounded p-2"><?php csrf_field(); ?>
            <input type="hidden" name="form_id" value="<?php echo (int)$f['id']; ?>"><input type="hidden" name="persona_id" id="mfGestorePid">
            <?php echo html_ricerca_personale($conn, 'Aggiungi'); ?>
            <button type="submit" name="aggiungi_gestore" value="1" id="mfGestoreInvia" hidden></button>
        </form>
        <p class="form-text">Deve aver già fatto l'accesso al portale almeno una volta: se non l'ha ancora fatto, chiedile di accedere e poi aggiungila.</p>
        <?php endif; ?>
    </div></div>
    <script nonce="<?php echo csp_nonce(); ?>">
    var fFg = document.getElementById('formFormsGestore');
    if (fFg) fFg.addEventListener('persona-scelta', function (e) {
        document.getElementById('mfGestorePid').value = e.detail.id;
        document.getElementById('mfGestoreInvia').click();
    });
    </script>
    <?php endif; ?>

<?php else: ?>
    <?php
    $stato_f = in_array($_GET['stato'] ?? '', ['bozza', 'aperta', 'chiusa'], true) ? $_GET['stato'] : '';
    $q_f = trim((string)($_GET['q'] ?? ''));
    $elenco = $sv->visibiliA($utente_admin);
    if ($stato_f !== '') $elenco = array_values(array_filter($elenco, fn($x) => $x['stato'] === $stato_f));
    if ($q_f !== '') $elenco = array_values(array_filter($elenco, fn($x) => mb_stripos($x['titolo'], $q_f) !== false));
    ?>
    <?php echo pn_testata('MyForms', 'fa-clipboard-list', 'Moduli online da compilare: questionari, raccolte di informazioni e iscrizioni, con risposte in Excel.',
        '<a class="btn btn-outline-secondary btn-sm" href="../guida.php?r=myforms" target="_blank" rel="noopener"><i class="fa fa-circle-question me-1" aria-hidden="true"></i>Guida</a> '
        . ($puo_forms ? '<a class="btn btn-primary btn-sm fw-bold" href="forms.php?azione=nuovo"><i class="fa fa-plus me-1" aria-hidden="true"></i>Nuovo form</a>' : '')); ?>
    <form method="GET" class="d-flex flex-wrap gap-2 align-items-end mb-3">
        <div><label class="form-label small mb-0" for="mfQ">Cerca per titolo</label><input type="text" class="form-control form-control-sm" id="mfQ" name="q" value="<?php echo $h($q_f); ?>"></div>
        <div><label class="form-label small mb-0" for="mfS">Stato</label>
            <select class="form-select form-select-sm" id="mfS" name="stato">
                <option value="">Tutti</option>
                <option value="bozza"<?php echo $stato_f === 'bozza' ? ' selected' : ''; ?>>Bozza</option>
                <option value="aperta"<?php echo $stato_f === 'aperta' ? ' selected' : ''; ?>>Aperto</option>
                <option value="chiusa"<?php echo $stato_f === 'chiusa' ? ' selected' : ''; ?>>Chiuso</option>
            </select></div>
        <button class="btn btn-outline-secondary btn-sm" type="submit">Filtra</button>
        <?php if ($stato_f !== '' || $q_f !== ''): ?><a class="btn btn-link btn-sm" href="forms.php">Azzera</a><?php endif; ?>
    </form>

    <?php if (!$elenco): ?>
        <div class="card border-0 shadow-sm"><?php echo pn_vuoto($puo_forms ? 'Nessun form: crea il primo con "Nuovo form".' : 'Nessun form di cui sei proprietario o co-gestore.', 'fa-clipboard-list'); ?></div>
    <?php else: ?>
        <div class="card border-0 shadow-sm"><ul class="list-group list-group-flush small">
            <?php foreach ($elenco as $x):
                $periodo_x = $x['aperta_dal'] || $x['aperta_al'] ? trim(($x['aperta_dal'] ? 'dal ' . $dataOra($x['aperta_dal']) : '') . ' ' . ($x['aperta_al'] ? 'al ' . $dataOra($x['aperta_al']) : '')) : 'sempre aperto';
                $puo_el_x = $sv->puoEliminare($x, $utente_admin);
            ?>
            <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
                <strong><a href="forms.php?id=<?php echo (int)$x['id']; ?>" class="text-decoration-none"><?php echo $h($x['titolo'] ?: '(senza titolo)'); ?></a></strong>
                <?php echo pn_stato(['bozza' => 'Bozza', 'aperta' => 'Aperto', 'chiusa' => 'Chiuso'][$x['stato']] ?? $x['stato'], ['bozza' => 'spento', 'aperta' => 'ok', 'chiusa' => 'info'][$x['stato']] ?? 'info', ['bozza' => 'fa-pen', 'aperta' => 'fa-door-open', 'chiusa' => 'fa-lock'][$x['stato']] ?? ''); ?>
                <span class="text-secondary"><?php echo $h($periodo_x); ?> · <?php echo (int)$rispRepo->conta((int)$x['id']); ?> risposte · <?php echo $h($nominativo((int)$x['proprietario_id'])); ?></span>
                <span class="ms-auto d-flex gap-1">
                    <a class="btn btn-sm btn-outline-success py-0" href="forms_risposte.php?id=<?php echo (int)$x['id']; ?>"><i class="fa fa-inbox me-1" aria-hidden="true"></i>Risposte (<?php echo (int)$rispRepo->conta((int)$x['id']); ?>)</a>
                    <a class="btn btn-sm btn-outline-secondary py-0" href="forms.php?id=<?php echo (int)$x['id']; ?>&amp;anteprima=1">Anteprima</a>
                    <a class="btn btn-sm btn-outline-primary py-0" href="forms.php?id=<?php echo (int)$x['id']; ?>">Modifica</a>
                    <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="form_id" value="<?php echo (int)$x['id']; ?>"><button type="submit" name="duplica_form" value="1" class="btn btn-sm btn-outline-secondary py-0" title="Crea una copia in bozza"><i class="fa fa-copy me-1" aria-hidden="true"></i>Copia</button></form>
                    <?php if ($puo_el_x): ?><form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="form_id" value="<?php echo (int)$x['id']; ?>"><button type="submit" name="elimina_form" value="1" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare il form «<?php echo $h($x['titolo']); ?>» con tutte le sue risposte?" data-conferma-scrivi="<?php echo $h($x['titolo']); ?>" aria-label="Elimina"><i class="fa fa-trash" aria-hidden="true"></i></button></form><?php endif; ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
<?php endif; ?>
<?php require_once 'admin_footer.php'; ?>
