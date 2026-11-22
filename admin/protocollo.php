<?php
// protocollo.php - Collegamento al protocollo di Ateneo (Titulus), Sistema → Protocollo.
// Come in UniTicket: una configurazione GLOBALE (come collegarsi a Titulus: servizio, utenza, AOO) e una configurazione per ogni TIPO DI RICHIESTA
// (che documento è, se protocollare subito o avviare l'iter di approvazione, UO, RPA, titolario, fascicolo, copie conoscenza). Logica in src/Protocollo/.
// Solo gli amministratori. La password dell'utenza di Titulus si conserva cifrata (CIFRATURA_CHIAVE nel .env).
require_once 'admin_header.php';

if (!$is_full_admin) nega_accesso();

function admin_redirect($url) { vai_a($url); }

$app_p = \App\Core\App::per($conn);
$repo = $app_p->get(\App\Protocollo\ProtocolloRepository::class);
$cfg = $app_p->get(\App\Protocollo\ConfiguratoreProtocollo::class);
$svc = $app_p->get(\App\Protocollo\ServizioProtocollo::class);
$cifr = $app_p->get(\App\Infrastructure\Sicurezza\Cifratura::class);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$tab = in_array($_GET['tab'] ?? '', ['collegamento', 'regole', 'voci', 'registro'], true) ? $_GET['tab'] : 'collegamento';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['salva_collegamento'])) {
        $err = $cfg->salvaCollegamento($_POST);
        if (!$err) registra_log_audit($conn, "Protocollo Titulus: collegamento salvato", ["AOO" => (string)($_POST['aoo'] ?? ''), "Attivo" => !empty($_POST['attivo']) ? 'sì' : 'no']);
        flash_set($err ?? "Collegamento salvato.", $err ? 'danger' : 'success');
        admin_redirect("protocollo.php?tab=collegamento&r=" . time());
    }
    if (isset($_POST['prova_collegamento'])) {
        $r = $svc->provaConnessione();
        flash_set($r['messaggio'], $r['ok'] ? 'success' : 'danger');
        admin_redirect("protocollo.php?tab=collegamento&r=" . time());
    }
    if (isset($_POST['salva_regola'])) {
        [$rid, $err] = $cfg->salvaRegola((int)($_POST['regola_id'] ?? 0), $_POST);
        if (!$err) registra_log_audit($conn, "Protocollo Titulus: configurazione richiesta salvata", ["Nome" => (string)($_POST['nome'] ?? ''), "Si applica a" => (string)($_POST['applica_a'] ?? '')]);
        flash_set($err ?? "Configurazione salvata.", $err ? 'danger' : 'success');
        admin_redirect("protocollo.php?tab=regole&regola=" . ($err ? (int)($_POST['regola_id'] ?? 0) : $rid) . "&r=" . time());
    }
    if (isset($_POST['elimina_regola'])) {
        $repo->eliminaRegola((int)$_POST['elimina_regola']);
        registra_log_audit($conn, "Protocollo Titulus: configurazione richiesta eliminata", ["Id" => (int)$_POST['elimina_regola']]);
        flash_set("Configurazione eliminata.", 'warning');
        admin_redirect("protocollo.php?tab=regole&r=" . time());
    }
    if (isset($_POST['salva_voce'])) {
        $err = $cfg->salvaVoce((int)($_POST['voce_id'] ?? 0), (string)($_POST['voce'] ?? ''), !empty($_POST['attiva']));
        flash_set($err ?? "Voce d'indice salvata.", $err ? 'danger' : 'success');
        admin_redirect("protocollo.php?tab=voci&r=" . time());
    }
    if (isset($_POST['elimina_voce'])) {
        $ok = $repo->eliminaVoce((int)$_POST['elimina_voce']);
        flash_set($ok ? "Voce eliminata." : "La voce è usata da una configurazione: disattivala invece di eliminarla.", $ok ? 'warning' : 'danger');
        admin_redirect("protocollo.php?tab=voci&r=" . time());
    }
    if (isset($_POST['salva_repertorio'])) {
        $err = $cfg->salvaRepertorio((int)($_POST['repertorio_id'] ?? 0), (string)($_POST['codice'] ?? ''), (string)($_POST['nome'] ?? ''), !empty($_POST['attivo']));
        flash_set($err ?? "Repertorio salvato.", $err ? 'danger' : 'success');
        admin_redirect("protocollo.php?tab=voci&r=" . time());
    }
    if (isset($_POST['elimina_repertorio'])) {
        $ok = $repo->eliminaRepertorio((int)$_POST['elimina_repertorio']);
        flash_set($ok ? "Repertorio eliminato." : "Il repertorio è usato da una configurazione: disattivalo invece di eliminarlo.", $ok ? 'warning' : 'danger');
        admin_redirect("protocollo.php?tab=voci&r=" . time());
    }
    if (isset($_POST['aggiorna_numero'])) {
        $r = $svc->aggiornaNumero((int)$_POST['aggiorna_numero']);
        flash_set($r['messaggio'], $r['ok'] ? 'success' : 'warning');
        admin_redirect("protocollo.php?tab=registro&r=" . time());
    }
}

$c = $repo->configurazione();
$regole = $repo->regole();
$voci = $repo->vociIndice();
$repertori = $repo->repertori();
$contesti = $cfg->contesti();
$titolario = \Unical\Titulus\CatalogoTitulus::TITOLARIO;
$uffici = \Unical\Titulus\CatalogoTitulus::UFFICI;
$pronto = $svc->nonPronto();

$page_cfg['titolo'] = 'Protocollo';
echo pn_testata('Protocollo di Ateneo (Titulus)', 'fa-stamp', "Collega il portale al protocollo di Ateneo. «Collegamento» dice come entrare in Titulus (una volta sola); «Configurazioni per richiesta» dice, per ogni tipo di richiesta, che documento è, chi ne è responsabile e dove va classificato.");
echo flash_html();
echo pn_schede([
    'collegamento' => ['href' => 'protocollo.php?tab=collegamento', 'testo' => 'Collegamento', 'icona' => 'fa-plug'],
    'regole' => ['href' => 'protocollo.php?tab=regole', 'testo' => 'Configurazioni per richiesta', 'icona' => 'fa-list-check', 'numero' => count($regole)],
    'voci' => ['href' => 'protocollo.php?tab=voci', 'testo' => "Voci d'indice e repertori", 'icona' => 'fa-book'],
    'registro' => ['href' => 'protocollo.php?tab=registro', 'testo' => 'Registro degli invii', 'icona' => 'fa-clock-rotate-left'],
], $tab, 'Sezioni del protocollo');

$opt = function (array $voci, $sel) use ($h): string {
    $o = '';
    foreach ($voci as $k => $n) $o .= '<option value="' . $h($k) . '"' . ((string)$k === (string)$sel ? ' selected' : '') . '>' . $h($n) . '</option>';
    return $o;
};
?>
<?php if ($tab === 'collegamento'): ?>
<div class="alert <?php echo $pronto === null ? 'alert-success' : 'alert-warning'; ?> d-flex gap-2 align-items-start" role="status">
    <i class="fa <?php echo $pronto === null ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> mt-1" aria-hidden="true"></i>
    <div><?php echo $pronto === null ? '<strong>Collegamento attivo.</strong> Le richieste con una configurazione possono essere inviate a Titulus.' : '<strong>Collegamento non pronto:</strong> ' . $h($pronto); ?>
        <?php if (!empty($c['ultima_prova_il'])): ?><div class="small mt-1">Ultima prova: <?php echo $h(date('d/m/Y H:i', strtotime((string)$c['ultima_prova_il']))); ?> — <?php echo $h($c['ultima_prova_esito']); ?></div><?php endif; ?></div>
</div>
<?php if (!$cifr->disponibile()): ?>
<div class="alert alert-danger"><i class="fa fa-key me-1" aria-hidden="true"></i><strong>Manca CIFRATURA_CHIAVE nel file .env del server</strong> (almeno 16 caratteri casuali): senza, la password di Titulus non si può conservare in modo sicuro e non viene salvata.</div>
<?php endif; ?>
<?php if (!extension_loaded('soap')): ?>
<div class="alert alert-danger"><i class="fa fa-puzzle-piece me-1" aria-hidden="true"></i><strong>L'estensione PHP «soap» non è attiva su questo server.</strong> Serve per parlare con Titulus: si attiva con <code>extension=soap</code> in php.ini (poi riavvia Apache).</div>
<?php endif; ?>
<div class="alert alert-info small mb-3"><i class="fa fa-info-circle me-1" aria-hidden="true"></i>Parametri impostati dall'<strong>Ufficio Protocollo del Centro ICT di Ateneo</strong>. L'indirizzo è quello del WSDL del servizio «Titulus4» (te lo dà l'Ufficio Protocollo; per le prove usa l'indirizzo dell'ambiente di collaudo, non quello di produzione).</div>
<div class="card border-0 shadow-sm"><div class="card-body">
    <form method="POST" class="row g-3" autocomplete="off"><?php csrf_field(); ?>
        <div class="col-md-6"><label class="form-label fw-bold" for="pcN">Denominazione configurazione: *</label><input class="form-control" id="pcN" name="nome" maxlength="255" required value="<?php echo $h($c['nome'] ?? 'Titulus DiBEST'); ?>"></div>
        <div class="col-md-6"><label class="form-label fw-bold" for="pcU">Indirizzo del servizio (WSDL):</label><input class="form-control" id="pcU" name="url_ws" type="url" maxlength="300" placeholder="https://…/ws/…?wsdl" value="<?php echo $h($c['url_ws'] ?? ''); ?>"></div>
        <div class="col-md-4"><label class="form-label fw-bold" for="pcUs">Username: *</label><input class="form-control" id="pcUs" name="username" maxlength="255" value="<?php echo $h($c['username'] ?? ''); ?>"></div>
        <div class="col-md-4"><label class="form-label fw-bold" for="pcP">Password:</label><input class="form-control" id="pcP" name="password" type="password" maxlength="255" autocomplete="new-password" placeholder="<?php echo !empty($c['password_cifrata']) ? '•••••••• (lasciala vuota per non cambiarla)' : ''; ?>"><div class="form-text">Conservata cifrata, mai mostrata.</div></div>
        <div class="col-md-2"><label class="form-label fw-bold" for="pcA">AOO: *</label><input class="form-control" id="pcA" name="aoo" maxlength="12" value="<?php echo $h($c['aoo'] ?? ''); ?>" placeholder="UCALPRG"></div>
        <div class="col-md-2"><label class="form-label fw-bold" for="pcG">AGD:</label><input class="form-control" id="pcG" name="agd" maxlength="12" value="<?php echo $h($c['agd'] ?? ''); ?>"></div>
        <div class="col-md-6"><label class="form-label fw-bold" for="pcAu">Autore dei documenti:</label><input class="form-control" id="pcAu" name="autore" maxlength="150" value="<?php echo $h($c['autore'] ?? ''); ?>" placeholder="Portale MyDiBEST"><div class="form-text">Compare in Titulus come autore del documento.</div></div>
        <div class="col-md-6"><label class="form-label fw-bold" for="pcE">E-mail predefinita:</label><input class="form-control" id="pcE" name="email_default" type="email" maxlength="255" value="<?php echo $h($c['email_default'] ?? ''); ?>"></div>
        <div class="col-md-6"><label class="form-label fw-bold" for="pcNs">Namespace del servizio (facoltativo):</label><input class="form-control" id="pcNs" name="namespace_ns2" maxlength="300" value="<?php echo $h($c['namespace_ns2'] ?? ''); ?>"><div class="form-text">Serve solo se l'Ufficio Protocollo lo indica.</div></div>
        <div class="col-md-6 d-flex flex-column justify-content-end gap-1">
            <label class="form-check"><input class="form-check-input" type="checkbox" name="fascicolazione_separata" value="1"<?php echo !isset($c['fascicolazione_separata']) || (int)$c['fascicolazione_separata'] ? ' checked' : ''; ?>> Inserisci il documento nel fascicolo dopo la protocollazione</label>
            <label class="form-check"><input class="form-check-input" type="checkbox" name="attivo" value="1"<?php echo (int)($c['attivo'] ?? 0) ? ' checked' : ''; ?>> <strong>Collegamento attivo</strong> (se spento, il portale non invia nulla a Titulus)</label></div>
        <div class="col-12 d-flex flex-wrap gap-2">
            <button class="btn btn-danger fw-bold" type="submit" name="salva_collegamento" value="1"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
            <button class="btn btn-outline-secondary fw-bold" type="submit" name="prova_collegamento" value="1" formnovalidate><i class="fa fa-plug me-1" aria-hidden="true"></i>Prova il collegamento</button>
        </div>
        <div class="col-12 form-text">«Prova il collegamento» legge soltanto il servizio e controlla che ci siano le operazioni attese: <strong>non crea nessun documento</strong> in Titulus. Salva prima di provare.</div>
    </form>
</div></div>

<?php elseif ($tab === 'regole'):
    $sel_id = (int)($_GET['regola'] ?? 0);
    $nuova = isset($_GET['nuova']) || (!$sel_id && !$regole);
    $r = $sel_id && !isset($_GET['nuova']) ? $repo->regola($sel_id) : null;
    if (!$r && !$nuova && $regole) { $r = $repo->regola((int)$regole[0]['id']); }
    $ccs = $r ? $repo->copieConoscenza((int)$r['id']) : [];
    $ccs = array_merge($ccs, array_fill(0, max(1, 3 - count($ccs)), ['uo' => '', 'nome' => '', 'username' => '', 'matricola' => '']));
    $pre_ctx = $nuova && isset($contesti[(string)($_GET['applica_a'] ?? '')]) ? (string)$_GET['applica_a'] : '';
    $v = fn($k, $d = '') => $r[$k] ?? ($k === 'applica_a' && $pre_ctx !== '' ? $pre_ctx : $d);
    $voci_att = array_filter($voci, fn($x) => (int)$x['attiva'] === 1 || (int)$x['id'] === (int)$v('voce_indice_id', 0));
    $rep_att = array_filter($repertori, fn($x) => (int)$x['attivo'] === 1 || (int)$x['id'] === (int)$v('repertorio_id', 0));
?>
<div class="alert alert-info small"><i class="fa fa-info-circle me-1" aria-hidden="true"></i>Una configurazione dice a Titulus <strong>che documento è e a chi va</strong> per un tipo di richiesta. Quella «Qualsiasi richiesta» fa da riserva per le richieste che non ne hanno una propria. Per avviare un iter di approvazione scegli la voce d'indice; per protocollare subito, «Protocolla subito».</div>
<details class="card border-0 shadow-sm mb-3"<?php echo !$regole ? ' open' : ''; ?>><summary class="card-body py-2 fw-bold" style="cursor:pointer;"><i class="fa fa-sitemap me-1" aria-hidden="true"></i>Dove si usa: quale configurazione vale per ogni richiesta</summary>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Richiesta</th><th>Dove compare il pulsante «Protocolla in Titulus»</th><th>Configurazione che vale</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($contesti as $ctx => $etichetta):
            if ($ctx === 'generale') continue;
            $vale = $repo->regolaPer($ctx);
            $dedicata = $vale && (string)$vale['applica_a'] === (string)$ctx;
            $dove = match (true) {
                $ctx === 'convenzione_fsl' => 'Gestione FSL → Convenzioni da seguire (presa in carico della convenzione firmata)',
                $ctx === 'convenzione_fsl_definitivo' => 'Gestione FSL → Convenzioni da seguire (firmate dal Direttore: protocollo definitivo).',
                $ctx === 'convenzione_tesi' => 'Convenzioni di tesi (presa in carico della convenzione firmata)',
                $ctx === 'convenzione_tesi_definitivo' => 'Convenzioni di tesi (firmate dal Direttore: protocollo definitivo).',
                str_starts_with($ctx, \App\Protocollo\Costanti::PREFISSO_USCITA) => 'Pratiche → attestato firmato: protocollo in uscita (documento in partenza all\'azienda)',
                $ctx === 'tutorato_lettera' => 'Tutorato → lettera di incarico firmata da tutti',
                $ctx === 'tutorato' => 'Vale per la lettera di tutorato se manca quella dedicata',
                str_starts_with($ctx, \App\Protocollo\Costanti::PREFISSO_PRATICA) => 'Pratiche → domanda all\'Ufficio protocollo',
                default => '',
            };
        ?>
            <tr>
                <td class="small fw-bold"><?php echo $h($etichetta); ?></td>
                <td class="small text-secondary"><?php echo $h($dove); ?></td>
                <td class="small"><?php if (!$vale): ?><span class="badge bg-danger">nessuna: il pulsante non compare</span>
                    <?php else: ?><a href="protocollo.php?tab=regole&amp;regola=<?php echo (int)$vale['id']; ?>"><?php echo $h($vale['nome']); ?></a> <?php echo $dedicata ? '<span class="badge bg-success">dedicata</span>' : ((string)$vale['applica_a'] === 'generale' ? '<span class="badge bg-secondary">riserva generale</span>' : '<span class="badge bg-info text-dark">del Tutorato</span>'); ?><?php endif; ?></td>
                <td class="text-end"><?php if (!$dedicata): ?><a class="btn btn-sm btn-outline-danger" href="protocollo.php?tab=regole&amp;nuova=1&amp;applica_a=<?php echo urlencode($ctx); ?>"><i class="fa fa-plus me-1" aria-hidden="true"></i>Configura</a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</details>
<div class="row g-3">
    <div class="col-lg-3">
        <div class="list-group shadow-sm mb-3">
            <?php foreach ($regole as $x): ?>
                <a class="list-group-item list-group-item-action<?php echo $r && (int)$r['id'] === (int)$x['id'] ? ' active' : ''; ?>" href="protocollo.php?tab=regole&amp;regola=<?php echo (int)$x['id']; ?>">
                    <div class="fw-bold"><?php echo $h($x['nome']); ?><?php echo (int)$x['attiva'] ? '' : ' <span class="badge bg-secondary">non attiva</span>'; ?></div>
                    <div class="small"><?php echo $h($contesti[$x['applica_a']] ?? $x['applica_a']); ?> · <?php echo $h(\App\Protocollo\Costanti::TIPI_DOCUMENTO[$x['tipo_documento']] ?? ''); ?></div></a>
            <?php endforeach; ?>
            <?php if (!$regole): ?><div class="list-group-item text-secondary small">Nessuna configurazione ancora.</div><?php endif; ?>
        </div>
        <a class="btn btn-outline-danger fw-bold w-100" href="protocollo.php?tab=regole&amp;nuova=1"><i class="fa fa-plus me-1" aria-hidden="true"></i>Nuova configurazione</a>
    </div>
    <div class="col-lg-9"><div class="card border-0 shadow-sm"><div class="card-body">
        <h2 class="h5 fw-bold"><?php echo $r ? 'Configurazione' : 'Nuova configurazione'; ?></h2>
        <form method="POST" class="row g-3"><?php csrf_field(); ?>
            <input type="hidden" name="regola_id" value="<?php echo (int)$v('id', 0); ?>">
            <div class="col-md-6"><label class="form-label fw-bold" for="rgN">Nome: *</label><input class="form-control" id="rgN" name="nome" maxlength="255" required value="<?php echo $h($v('nome')); ?>" placeholder="Convenzioni FSL in arrivo"></div>
            <div class="col-md-6"><label class="form-label fw-bold" for="rgA">Si applica a: *</label><select class="form-select" id="rgA" name="applica_a" required><option value="">-- scegli la richiesta --</option><?php echo $opt($contesti, $v('applica_a')); ?></select></div>
            <div class="col-md-4"><label class="form-label fw-bold" for="rgT">Tipo di documento: *</label><select class="form-select" id="rgT" name="tipo_documento"><?php echo $opt(\App\Protocollo\Costanti::TIPI_DOCUMENTO, $v('tipo_documento', 'arrivo')); ?></select></div>
            <div class="col-md-4"><label class="form-label fw-bold" for="rgZ">Cosa fa Titulus: *</label><select class="form-select" id="rgZ" name="azione"><?php echo $opt(\App\Protocollo\Costanti::AZIONI, $v('azione', 'protocolla')); ?></select></div>
            <div class="col-md-4" id="rgVoceBox"><label class="form-label fw-bold" for="rgV">Voce d'indice (per l'iter):</label><select class="form-select" id="rgV" name="voce_indice_id"><option value="0">-- nessuna --</option><?php foreach ($voci_att as $x): ?><option value="<?php echo (int)$x['id']; ?>"<?php echo (int)$v('voce_indice_id', 0) === (int)$x['id'] ? ' selected' : ''; ?>><?php echo $h($x['voce']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-bold" for="rgU">UO (ufficio responsabile): *</label><select class="form-select" id="rgU" name="uo" required><option value="">-- scegli l'UO --</option><?php foreach ($uffici as $cod => $nome): ?><option value="<?php echo $h($cod); ?>"<?php echo (string)$v('uo') === (string)$cod ? ' selected' : ''; ?>><?php echo $h($nome . ' (' . $cod . ')'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-bold" for="rgC">Codice del titolario: *</label><select class="form-select" id="rgC" name="cod_titolario" required><option value="">-- scegli il codice --</option><?php foreach ($titolario as $gruppo => $vv): ?><optgroup label="<?php echo $h($gruppo); ?>"><?php foreach ($vv as $cod => $nome): ?><option value="<?php echo $h($cod); ?>"<?php echo (string)$v('cod_titolario') === (string)$cod ? ' selected' : ''; ?>><?php echo $h($cod . ' — ' . $nome); ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-bold" for="rgR">Repertorio:</label><select class="form-select" id="rgR" name="repertorio_id"><option value="0">-- nessuno --</option><?php foreach ($rep_att as $x): ?><option value="<?php echo (int)$x['id']; ?>"<?php echo (int)$v('repertorio_id', 0) === (int)$x['id'] ? ' selected' : ''; ?>><?php echo $h($x['codice'] . ' — ' . $x['nome']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6 d-flex flex-column justify-content-end gap-1">
                <label class="form-check"><input class="form-check-input" type="checkbox" name="invia_email_rpa" value="1"<?php echo !$r || (int)$r['invia_email_rpa'] ? ' checked' : ''; ?>> Titulus invia l'e-mail all'RPA</label>
                <label class="form-check"><input class="form-check-input" type="checkbox" name="attiva" value="1"<?php echo !$r || (int)$r['attiva'] ? ' checked' : ''; ?>> <strong>Configurazione attiva</strong></label></div>

            <div class="col-12"><h3 class="h6 fw-bold mb-0 mt-2"><i class="fa fa-user-tie me-1" aria-hidden="true"></i>RPA (responsabile del procedimento)</h3></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="rgRn">Nominativo</label><input class="form-control" id="rgRn" name="rpa_nome" maxlength="255" value="<?php echo $h($v('rpa_nome')); ?>"></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="rgRu">Username in Titulus</label><input class="form-control" id="rgRu" name="rpa_username" maxlength="255" value="<?php echo $h($v('rpa_username')); ?>"></div>
            <div class="col-md-4"><label class="form-label small fw-bold" for="rgRm">Matricola</label><input class="form-control" id="rgRm" name="rpa_matricola" maxlength="255" value="<?php echo $h($v('rpa_matricola')); ?>"></div>

            <div class="col-12"><h3 class="h6 fw-bold mb-0 mt-2"><i class="fa fa-folder-tree me-1" aria-hidden="true"></i>Fascicolo (facoltativo)</h3></div>
            <div class="col-md-6"><label class="form-label small fw-bold" for="rgFn">Numero del fascicolo</label><input class="form-control" id="rgFn" name="fascicolo_numero" maxlength="255" value="<?php echo $h($v('fascicolo_numero')); ?>" placeholder="2026-I/1.1"><div class="form-text">Con un fascicolo serve la matricola dell'RPA.</div></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="rgFa">Anno del fascicolo</label><input class="form-control" id="rgFa" name="fascicolo_anno" type="number" min="1990" max="2100" value="<?php echo $h($v('fascicolo_anno')); ?>"></div>

            <div class="col-12"><h3 class="h6 fw-bold mb-0 mt-2"><i class="fa fa-user-gear me-1" aria-hidden="true"></i>Operatore (OP, facoltativo)</h3></div>
            <div class="col-md-5"><label class="form-label small fw-bold" for="rgOu">UO</label><select class="form-select form-select-sm" id="rgOu" name="op_uo"><option value="">-- nessun operatore --</option><?php foreach ($uffici as $cod => $nome): ?><option value="<?php echo $h($cod); ?>"<?php echo (string)$v('op_uo') === (string)$cod ? ' selected' : ''; ?>><?php echo $h($nome . ' (' . $cod . ')'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small fw-bold" for="rgOn">Nominativo</label><input class="form-control form-control-sm" id="rgOn" name="op_nome" maxlength="255" value="<?php echo $h($v('op_nome')); ?>"></div>
            <div class="col-md-2"><label class="form-label small fw-bold" for="rgOs">Username</label><input class="form-control form-control-sm" id="rgOs" name="op_username" maxlength="255" value="<?php echo $h($v('op_username')); ?>"></div>
            <div class="col-md-2"><label class="form-label small fw-bold" for="rgOm">Matricola</label><input class="form-control form-control-sm" id="rgOm" name="op_matricola" maxlength="255" value="<?php echo $h($v('op_matricola')); ?>"></div>

            <div class="col-12"><h3 class="h6 fw-bold mb-0 mt-2"><i class="fa fa-copy me-1" aria-hidden="true"></i>Copia conoscenza (CC)</h3><div class="form-text">Lascia vuota l'UO delle righe che non servono.</div></div>
            <div class="col-12" id="rgCcBox">
                <?php foreach ($ccs as $cc): ?>
                <div class="row g-2 mb-2 rg-cc">
                    <div class="col-md-5"><select class="form-select form-select-sm" name="cc_uo[]" aria-label="UO della copia conoscenza"><option value="">-- UO --</option><?php foreach ($uffici as $cod => $nome): ?><option value="<?php echo $h($cod); ?>"<?php echo (string)$cc['uo'] === (string)$cod ? ' selected' : ''; ?>><?php echo $h($nome . ' (' . $cod . ')'); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><input class="form-control form-control-sm" name="cc_nome[]" maxlength="255" placeholder="Nominativo" aria-label="Nominativo" value="<?php echo $h($cc['nome']); ?>"></div>
                    <div class="col-md-2"><input class="form-control form-control-sm" name="cc_username[]" maxlength="255" placeholder="Username" aria-label="Username" value="<?php echo $h($cc['username']); ?>"></div>
                    <div class="col-md-2"><input class="form-control form-control-sm" name="cc_matricola[]" maxlength="255" placeholder="Matricola" aria-label="Matricola" value="<?php echo $h($cc['matricola']); ?>"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="col-12"><button type="button" class="btn btn-sm btn-outline-secondary" id="rgCcAggiungi"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi una riga</button></div>

            <div class="col-12 d-flex flex-wrap gap-2">
                <button class="btn btn-danger fw-bold" type="submit" name="salva_regola" value="1"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
                <?php if ($r): ?><button class="btn btn-outline-danger" type="submit" name="elimina_regola" value="<?php echo (int)$r['id']; ?>" formnovalidate data-confirm="Eliminare questa configurazione? Il registro degli invii resta."><i class="fa fa-trash me-1" aria-hidden="true"></i>Elimina</button><?php endif; ?>
            </div>
        </form>
    </div></div></div>
</div>
<script nonce="<?php echo csp_nonce(); ?>">
(function () {
    var azione = document.getElementById('rgZ'), box = document.getElementById('rgVoceBox');
    function aggiorna() { if (azione && box) box.style.opacity = azione.value === 'iter' ? '1' : '.5'; }
    if (azione) { azione.addEventListener('change', aggiorna); aggiorna(); }
    var btn = document.getElementById('rgCcAggiungi'), cont = document.getElementById('rgCcBox');
    if (btn && cont) btn.addEventListener('click', function () {
        var righe = cont.querySelectorAll('.rg-cc'), nuova = righe[righe.length - 1].cloneNode(true);
        nuova.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        nuova.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
        cont.appendChild(nuova);
    });
})();
</script>

<?php elseif ($tab === 'voci'): ?>
<div class="row g-3">
    <div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body">
        <h2 class="h5 fw-bold">Voci d'indice (iter di approvazione)</h2>
        <p class="small text-secondary">Sono i tipi di iter che Titulus conosce (es. «Iter Approva e Firma»). Scrivile <strong>esattamente</strong> come in Titulus.</p>
        <?php foreach ($voci as $x): ?>
            <form method="POST" class="d-flex gap-2 align-items-center mb-2"><?php csrf_field(); ?>
                <input type="hidden" name="voce_id" value="<?php echo (int)$x['id']; ?>">
                <input class="form-control form-control-sm" name="voce" maxlength="255" value="<?php echo $h($x['voce']); ?>" aria-label="Voce d'indice">
                <label class="form-check small text-nowrap mb-0"><input class="form-check-input" type="checkbox" name="attiva" value="1"<?php echo (int)$x['attiva'] ? ' checked' : ''; ?>> attiva</label>
                <button class="btn btn-sm btn-outline-secondary" type="submit" name="salva_voce" value="1" title="Salva" aria-label="Salva la voce"><i class="fa fa-save" aria-hidden="true"></i></button>
                <button class="btn btn-sm btn-outline-danger" type="submit" name="elimina_voce" value="<?php echo (int)$x['id']; ?>" formnovalidate title="<?php echo (int)$x['usi'] ? 'Usata da ' . (int)$x['usi'] . ' configurazioni' : 'Elimina'; ?>" aria-label="Elimina la voce" data-confirm="Eliminare questa voce?"><i class="fa fa-trash" aria-hidden="true"></i></button>
            </form>
        <?php endforeach; ?>
        <form method="POST" class="d-flex gap-2 align-items-center mt-3 border-top pt-3"><?php csrf_field(); ?>
            <input type="hidden" name="voce_id" value="0"><input type="hidden" name="attiva" value="1">
            <input class="form-control form-control-sm" name="voce" maxlength="255" placeholder="Nuova voce d'indice" aria-label="Nuova voce d'indice" required>
            <button class="btn btn-sm btn-danger fw-bold text-nowrap" type="submit" name="salva_voce" value="1"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi</button>
        </form>
    </div></div></div>
    <div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body">
        <h2 class="h5 fw-bold">Repertori</h2>
        <p class="small text-secondary">Il codice del repertorio di Titulus (massimo 15 caratteri) e il suo nome.</p>
        <?php foreach ($repertori as $x): ?>
            <form method="POST" class="d-flex gap-2 align-items-center mb-2"><?php csrf_field(); ?>
                <input type="hidden" name="repertorio_id" value="<?php echo (int)$x['id']; ?>">
                <input class="form-control form-control-sm" style="max-width:8rem" name="codice" maxlength="15" value="<?php echo $h($x['codice']); ?>" aria-label="Codice">
                <input class="form-control form-control-sm" name="nome" maxlength="255" value="<?php echo $h($x['nome']); ?>" aria-label="Nome del repertorio">
                <label class="form-check small text-nowrap mb-0"><input class="form-check-input" type="checkbox" name="attivo" value="1"<?php echo (int)$x['attivo'] ? ' checked' : ''; ?>> attivo</label>
                <button class="btn btn-sm btn-outline-secondary" type="submit" name="salva_repertorio" value="1" title="Salva" aria-label="Salva il repertorio"><i class="fa fa-save" aria-hidden="true"></i></button>
                <button class="btn btn-sm btn-outline-danger" type="submit" name="elimina_repertorio" value="<?php echo (int)$x['id']; ?>" formnovalidate title="<?php echo (int)$x['usi'] ? 'Usato da ' . (int)$x['usi'] . ' configurazioni' : 'Elimina'; ?>" aria-label="Elimina il repertorio" data-confirm="Eliminare questo repertorio?"><i class="fa fa-trash" aria-hidden="true"></i></button>
            </form>
        <?php endforeach; ?>
        <form method="POST" class="d-flex gap-2 align-items-center mt-3 border-top pt-3"><?php csrf_field(); ?>
            <input type="hidden" name="repertorio_id" value="0"><input type="hidden" name="attivo" value="1">
            <input class="form-control form-control-sm" style="max-width:8rem" name="codice" maxlength="15" placeholder="Codice" aria-label="Codice" required>
            <input class="form-control form-control-sm" name="nome" maxlength="255" placeholder="Nome del repertorio" aria-label="Nome del repertorio">
            <button class="btn btn-sm btn-danger fw-bold text-nowrap" type="submit" name="salva_repertorio" value="1"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi</button>
        </form>
    </div></div></div>
</div>

<?php else:
    $invii = $repo->invii(150);
?>
<div class="card border-0 shadow-sm"><div class="card-body p-0">
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Data</th><th>Richiesta</th><th>Oggetto</th><th>Esito</th><th>Protocollo / record</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($invii as $x): ?>
            <tr>
                <td class="small text-nowrap"><?php echo $h(date('d/m/Y H:i', strtotime((string)$x['creato_il']))); ?></td>
                <td class="small"><?php echo $h($contesti[$x['applica_a']] ?? $x['applica_a']); ?><?php echo $x['rif_tipo'] !== '' ? '<div class="text-secondary">' . $h($x['rif_tipo'] . ' #' . $x['rif_id']) . '</div>' : ''; ?></td>
                <td class="small"><?php echo $h(mb_strimwidth((string)$x['oggetto'], 0, 80, '…')); ?></td>
                <td><?php echo $x['esito'] === 'ok' ? '<span class="badge bg-success">inviato</span>' : '<span class="badge bg-danger">errore</span>'; ?>
                    <?php if (!empty($x['errore'])): ?><div class="small text-danger"><?php echo $h(mb_strimwidth((string)$x['errore'], 0, 220, '…')); ?></div><?php endif; ?></td>
                <td class="small"><?php if ($x['num_prot'] !== ''): ?><strong><?php echo $h($x['num_prot']); ?></strong><?php elseif ($x['nrecord'] !== ''): ?>record <?php echo $h($x['nrecord']); ?> <span class="badge bg-warning text-dark">iter in corso</span><?php endif; ?>
                    <?php if (preg_match('#^https?://#i', (string)$x['url'])): ?><div><a href="<?php echo $h($x['url']); ?>" target="_blank" rel="noopener noreferrer">Apri in Titulus</a></div><?php endif; ?></td>
                <td class="text-end"><?php if ($x['esito'] === 'ok' && $x['num_prot'] === '' && $x['nrecord'] !== ''): ?>
                    <form method="POST"><?php csrf_field(); ?><button class="btn btn-sm btn-outline-secondary" type="submit" name="aggiorna_numero" value="<?php echo (int)$x['id']; ?>"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Leggi il numero</button></form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$invii): ?><tr><td colspan="6" class="text-center text-secondary py-4">Nessun invio a Titulus ancora.</td></tr><?php endif; ?>
        </tbody></table></div>
</div></div>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
