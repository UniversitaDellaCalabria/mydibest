<?php
// modulistica_amministrativa.php - Modulistica amministrativa: in cima le procedure online del personale (per ora la Richiesta di pubblicazione di
// un'iniziativa), sotto i documenti da scaricare e i link divisi per categoria, con la ricerca. Pagina pubblica, stessa grafica di modulistica.php
// (le voci le disegna lo stesso parziale: inc/parziali/modulistica_voci.php); i documenti si gestiscono da admin/amministrazione.php?tab=modulistica.
// Niente sportelli di prenotazione né moduli online che aprono una pratica: quelli sono della Didattica (modulistica.php).
// Cosa mostra la carta di ogni procedura: «Compila online» a chi può inviarla, «Accedi e compila» a chi non è entrato, «Riservata a …» a chi non può
// (la regola è ServizioPubblicazioni::puoInviare, la stessa della pagina della richiesta).
require_once 'config.php';
require_once 'functions.php';
sync_sso_user($conn); // accesso automatico dalla sessione SSO di Ateneo, come fa header.php (serve prima di decidere cosa mostrare)

$app = \App\Core\App::per($conn);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$moduli = $app->get(\App\Didattica\ModuloRepository::class)->pubblicatiPerCategoria(\App\Didattica\ModuloRepository::AMBITO_AMMINISTRAZIONE);
$utente = !empty($_SESSION['utente_id']) ? $app->get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']) : null;
$loggato = $utente !== null;
$q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
$mod_online_ammesso = false; // qui i moduli non aprono pratiche: il parziale non mostra mai «Compila online» per i documenti
$procedure_on = $app->get(\App\Amministrazione\ProcedureOnline::class);
$procedure = array_values(array_filter($procedure_on->elenco(), fn($p) => $q === '' || str_contains(mb_strtolower($p['titolo'] . ' ' . $p['descrizione']), $q)));

$page_cfg['titolo'] = 'Modulistica amministrativa';
require_once 'header.php';
?>
<?php require __DIR__ . '/inc/parziali/modulistica_stile.php'; ?>
<div class="container my-4" style="max-width: 1000px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Modulistica amministrativa</span></nav>
    <h1 class="fw-bold mb-1">Modulistica amministrativa</h1>
    <p class="text-secondary">Procedure da compilare online e documenti da scaricare per il personale del Dipartimento.</p>
    <form method="GET" class="mb-3" role="search"><label class="visually-hidden" for="modQ">Cerca un documento o una procedura</label>
        <div class="input-group" style="max-width:420px;"><input type="search" class="form-control" id="modQ" name="q" value="<?php echo $h($_GET['q'] ?? ''); ?>" placeholder="Cerca un documento o una procedura"><button class="btn btn-outline-secondary" aria-label="Cerca"><i class="fa fa-search" aria-hidden="true"></i></button></div></form>
    <?php if ($procedure): ?>
    <section class="card border-0 shadow-sm mb-3" style="border-radius:12px;"><div class="card-body">
        <h2 class="h5 fw-bold mb-1">Procedure online</h2>
        <?php foreach ($procedure as $p): $puo_p = $loggato && $procedure_on->puoInviare($p['chiave'], $utente); ?>
            <div class="mod-voce">
                <span class="mod-ico" style="background:#dcfce7;color:#15803d;" aria-hidden="true"><i class="fa fa-bullhorn"></i></span>
                <div class="flex-grow-1" style="min-width:0;">
                    <h3 class="h6 fw-bold mb-1"><?php echo $h($p['titolo']); ?><?php if (!$p['aperta']): ?> <span class="badge bg-secondary fw-normal">Chiusa</span><?php endif; ?></h3>
                    <div class="small text-secondary"><?php echo $h($p['descrizione']); ?></div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                        <?php if (!$p['aperta']): ?>
                            <span class="small text-secondary"><i class="fa fa-lock me-1" aria-hidden="true"></i>Al momento non si possono inviare richieste.</span>
                        <?php elseif ($puo_p || !$loggato): ?>
                            <a href="<?php echo $h($p['pagina']); ?>" class="btn btn-sm btn-success fw-bold"><i class="fa fa-pen me-1" aria-hidden="true"></i><?php echo $loggato ? 'Compila online' : 'Accedi e compila'; ?></a>
                        <?php else: ?>
                            <span class="small text-secondary"><i class="fa fa-lock me-1" aria-hidden="true"></i>Riservata a: <?php echo $h($p['chi']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div></section>
    <?php endif; ?>
    <?php if (!$moduli): ?><div class="alert alert-info">Al momento non ci sono documenti pubblicati. Controlla i <a href="contatti.php">contatti</a> per sapere a chi rivolgerti.</div><?php endif; ?>
<?php require __DIR__ . '/inc/parziali/modulistica_voci.php'; ?>
</div>
<?php require_once 'footer.php'; ?>
