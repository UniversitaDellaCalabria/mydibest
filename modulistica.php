<?php
// modulistica.php - Modulistica della didattica: documenti da scaricare e moduli online (con accesso) divisi per categoria.
require_once 'config.php';
require_once 'functions.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$moduli = \App\Core\App::per($conn)->get(\App\Didattica\ModuloRepository::class)->pubblicatiPerCategoria();
$loggato = !empty($_SESSION['utente_id']);
$q = mb_strtolower(trim((string)($_GET['q'] ?? '')));

$page_cfg['titolo'] = 'Modulistica';
require_once 'header.php';
?>
<?php require __DIR__ . '/inc/parziali/modulistica_stile.php'; ?>
<div class="container my-4" style="max-width: 1000px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Modulistica</span></nav>
    <h1 class="fw-bold mb-1">Modulistica</h1>
    <p class="text-secondary">Documenti da scaricare e richieste da compilare online. Le richieste online aprono una <strong>pratica</strong> che puoi seguire dalla tua <a href="pratiche.php">Area personale → Le mie pratiche</a>.</p>
    <?php foreach (sportelli_ufficio_didattica($conn, true) as $sp): ?>
        <div class="alert d-flex flex-wrap align-items-center gap-2" style="background:#f5f3ff;border:1px solid #ddd6fe;">
            <i class="fa fa-user-clock fs-4" style="color:#7c3aed;" aria-hidden="true"></i>
            <div class="flex-grow-1"><strong><?php echo $h($sp['nome']); ?></strong><?php echo $sp['luogo'] ? '<div class="small text-secondary">' . $h($sp['luogo']) . '</div>' : ''; ?></div>
            <a href="<?php echo $h($sp['area_slug']); ?>.php?risorsa=<?php echo (int)$sp['id']; ?>" class="btn btn-sm fw-bold text-white" style="background:#7c3aed;">Prenota un appuntamento</a>
        </div>
    <?php endforeach; ?>
    <form method="GET" class="mb-3" role="search"><label class="visually-hidden" for="modQ">Cerca un modulo</label>
        <div class="input-group" style="max-width:420px;"><input type="search" class="form-control" id="modQ" name="q" value="<?php echo $h($_GET['q'] ?? ''); ?>" placeholder="Cerca un modulo"><button class="btn btn-outline-secondary" aria-label="Cerca"><i class="fa fa-search" aria-hidden="true"></i></button></div></form>
    <?php if (!$moduli): ?><div class="alert alert-info">Al momento non ci sono moduli pubblicati. Controlla le <a href="domande_frequenti.php">domande frequenti</a> oppure guarda <a href="contatti.php">a chi rivolgerti</a>.</div><?php endif; ?>
<?php require __DIR__ . '/inc/parziali/modulistica_voci.php'; ?>
</div>
<?php require_once 'footer.php'; ?>
