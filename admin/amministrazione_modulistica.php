<?php
// amministrazione_modulistica.php - Modulistica amministrativa: le procedure online del personale (per ora la Richiesta di pubblicazione) e i documenti
// da scaricare e i link, divisi per categoria, come li vede chi apre la pagina pubblica modulistica_amministrativa.php.
// Scheda del pannello Amministrazione: si apre da amministrazione.php?tab=modulistica, mai direttamente. Solo per chi può tutto il modulo
// (PermessiAmministrazione::puoTutto), ricontrollato qui anche per i POST. Usa gli stessi servizi della Didattica (ServizioModuli, ModuloRepository) con
// l'ambito «amministrazione»: dalla scheda non si toccano i moduli della Didattica, anche cambiando l'id a mano, e non si crea un modulo online
// (quelli che aprono una pratica arriveranno più avanti: il server li rifiuta).
// $fase = 'azioni': gestisce i POST (ognuno risponde con un rimando e termina); $fase = 'vista': la pagina.
if (!defined('AMMINISTRAZIONE_PANNELLO')) { http_response_code(403); exit('Accesso negato.'); }
if (!$perm_amm->puoTutto($utente_admin)) nega_accesso();

$ambito_am = \App\Didattica\ModuloRepository::AMBITO_AMMINISTRAZIONE;

if ($fase === 'azioni') {
    // ── Documenti e link: salvataggio ed eliminazione ──
    if (isset($_POST['salva_modulistica'])) {
        $id = (int)($_POST['modulo_id'] ?? 0);
        $esito = \App\Core\App::per($conn)->get(\App\Didattica\ServizioModuli::class)->salva($_POST, $_FILES, $ambito_am);
        if ($esito['errore'] === 'titolo') { flash_set("Il titolo è obbligatorio.", 'danger'); admin_redirect("$base&tab=modulistica&" . ($id ? "modifica=$id" : "nuovo=1")); }
        if ($esito['errore'] === 'non_trovato') { flash_set("Documento non trovato.", 'danger'); admin_redirect("$base&tab=modulistica"); }
        if ($esito['errore'] === 'online_non_ammesso') { flash_set("I moduli online che aprono una pratica arriveranno più avanti: per ora qui si caricano documenti da scaricare e link.", 'warning'); admin_redirect("$base&tab=modulistica"); }
        if ($esito['file_rifiutato']) flash_set("Il file non è stato caricato: usa PDF, Word, LibreOffice o Excel.", 'warning');
        registra_log_audit($conn, "Documento della modulistica amministrativa salvato", ["Titolo" => $esito['titolo'], "ID" => $esito['id']]);
        if (!isset($_SESSION['_flash'])) flash_set("Documento \"{$esito['titolo']}\" salvato.");
        admin_redirect("$base&tab=modulistica");
    }
    if (isset($_POST['elimina_modulistica'])) {
        $id = (int)$_POST['elimina_modulistica'];
        $n = \App\Core\App::per($conn)->get(\App\Didattica\ServizioModuli::class)->elimina($id, $ambito_am);
        if ($n < 0) {
            flash_set("Documento non trovato.", 'danger'); // non è un documento della modulistica amministrativa: non si tocca
        } else {
            registra_log_audit($conn, "Documento della modulistica amministrativa eliminato", ["ID" => $id]);
            flash_set("Documento eliminato.", 'warning');
        }
        admin_redirect("$base&tab=modulistica");
    }

    return;
}

// ── PAGINA ──
$repo_mod = \App\Core\App::per($conn)->get(\App\Didattica\ModuloRepository::class);
$moduli = $repo_mod->elenco($ambito_am);
$categorie = array_values(array_unique(array_column($moduli, 'categoria')));
$procedure = \App\Core\App::per($conn)->get(\App\Amministrazione\ProcedureOnline::class)->elenco();
// Solo un documento di questa modulistica si apre in modifica: l'id di un modulo della Didattica (o inesistente) dà «non trovato»
$mod_m = !empty($_GET['modifica']) ? $repo_mod->perId((int)$_GET['modifica'], $ambito_am) : null;
$mostra_form = $mod_m || !empty($_GET['nuovo']);
$f = $mod_m ?: ['id' => 0, 'titolo' => '', 'categoria' => '', 'descrizione' => '', 'tipo' => 'documento', 'file_path' => null, 'link' => '', 'attivo' => 1, 'ordine' => 0];
?>
<h2 class="h6 fw-bold text-uppercase text-secondary mb-2" style="letter-spacing:.05em;">Procedure online</h2>
<div class="card border-0 shadow-sm mb-4"><ul class="list-group list-group-flush small">
    <?php foreach ($procedure as $proc): ?>
    <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
        <strong><?php echo $h($proc['titolo']); ?></strong>
        <?php echo $proc['aperta'] ? pn_stato('Aperta', 'ok', 'fa-lock-open') : pn_stato('Chiusa', 'spento', 'fa-lock'); ?>
        <span class="text-secondary">Chi può inviarla: <?php echo $h($proc['chi']); ?></span>
        <span class="ms-auto d-flex flex-wrap gap-1">
            <a class="btn btn-sm btn-outline-secondary py-0" href="../<?php echo $h($proc['pagina']); ?>" target="_blank" rel="noopener"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Pagina pubblica<span class="visually-hidden"> (si apre in una nuova scheda)</span></a>
            <a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $h($proc['gestione']); ?>"><i class="fa fa-bullhorn me-1" aria-hidden="true"></i><?php echo $h($proc['gestione_nome']); ?></a>
            <a class="btn btn-sm btn-outline-secondary py-0" href="<?php echo $h($proc['impostazioni']); ?>"><i class="fa fa-sliders me-1" aria-hidden="true"></i>Impostazioni</a>
        </span>
    </li>
    <?php endforeach; ?>
</ul></div>

<h2 class="h6 fw-bold text-uppercase text-secondary mb-2" style="letter-spacing:.05em;">Documenti e link</h2>
<?php if (!empty($_GET['modifica']) && !$mod_m): ?>
    <div class="alert alert-warning small" role="alert">Documento non trovato.</div>
<?php endif; ?>
<?php if ($mostra_form): ?>
<form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm mb-3" id="amForm"><div class="card-body">
    <?php csrf_field(); ?><input type="hidden" name="modulo_id" value="<?php echo (int)$f['id']; ?>">
    <h3 class="h5 fw-bold mb-3"><?php echo $f['id'] ? 'Modifica documento o link' : 'Nuovo documento o link'; ?></h3>
    <div class="row g-2">
<?php $solo_documento = true; $suggerimento_categoria = 'es. Missioni, Acquisti, Personale'; require __DIR__ . '/parziali/modulo_documento_campi.php'; ?>
        <div class="col-md-2"><label class="form-label small fw-bold" for="mOrd">Ordine</label><input type="number" class="form-control" id="mOrd" name="ordine" value="<?php echo (int)$f['ordine']; ?>"></div>
        <div class="col-md-4 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="attivo" value="1"<?php echo (int)$f['attivo'] ? ' checked' : ''; ?>> Pubblicato (visibile nella pagina pubblica)</label></div>
    </div>
    <p class="small text-secondary mt-2 mb-0"><i class="fa fa-lock me-1" aria-hidden="true"></i>I moduli online che aprono una pratica arriveranno più avanti.</p>
    <div class="pn-salva">
        <button type="submit" name="salva_modulistica" value="1" class="btn btn-primary fw-bold"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva</button>
        <a href="<?php echo $h($base); ?>&amp;tab=modulistica" class="btn btn-outline-secondary">Annulla</a>
    </div>
</div></form>
<script>
(function () {
    // L'editor del testo scrive nel campo solo quando serve: prima dell'invio si aggiorna
    var form = document.getElementById('amForm');
    if (form) form.addEventListener('submit', function () { if (window.tinymce) tinymce.triggerSave(); });
})();
</script>
<?php else: ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <a href="<?php echo $h($base); ?>&amp;tab=modulistica&amp;nuovo=1" class="btn btn-primary fw-bold"><i class="fa fa-plus-circle me-1" aria-hidden="true"></i>Nuovo documento o link</a>
        <a href="../modulistica_amministrativa.php" class="btn btn-outline-secondary" target="_blank" rel="noopener"><i class="fa fa-eye me-1" aria-hidden="true"></i>Vedi la pagina pubblica<span class="visually-hidden"> (si apre in una nuova scheda)</span></a>
        <span class="small text-secondary">Un documento si scarica; un link rimanda a una pagina. I moduli online che aprono una pratica arriveranno più avanti.</span>
    </div>
<?php endif; ?>

<?php if (!$moduli): ?>
    <div class="card border-0 shadow-sm"><?php echo pn_vuoto("Nessun documento: carica il primo documento da scaricare o aggiungi un link.", 'fa-file-lines'); ?></div>
<?php endif; ?>
<?php foreach ($categorie as $cat): ?>
    <h3 class="h6 fw-bold text-secondary text-uppercase mt-3 mb-2" style="font-size:.75rem;letter-spacing:.05em;"><?php echo $h($cat); ?></h3>
    <div class="card border-0 shadow-sm"><ul class="list-group list-group-flush small">
    <?php foreach ($moduli as $m): if ($m['categoria'] !== $cat) continue; ?>
        <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
            <strong><?php echo $h($m['titolo']); ?></strong>
            <?php echo $m['file_path'] ? pn_stato('Documento da scaricare', 'info', 'fa-file-arrow-down') : ($m['link'] ? pn_stato('Link', 'info', 'fa-link') : pn_stato('Senza file né link', 'attesa', 'fa-triangle-exclamation')); ?>
            <?php if (!(int)$m['attivo']): ?><?php echo pn_stato('Non pubblicato', 'spento', 'fa-eye-slash'); ?><?php endif; ?>
            <span class="ms-auto d-flex gap-1">
                <a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $h($base); ?>&amp;tab=modulistica&amp;modifica=<?php echo (int)$m['id']; ?>">Modifica</a>
                <form method="POST" class="m-0"><?php csrf_field(); ?><button type="submit" name="elimina_modulistica" value="<?php echo (int)$m['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare il documento?" aria-label="Elimina"><i class="fa fa-trash" aria-hidden="true"></i></button></form>
            </span>
        </li>
    <?php endforeach; ?>
    </ul></div>
<?php endforeach; ?>
