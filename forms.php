<?php
// forms.php - MyForms · moduli da compilare: i moduli aperti ora e rivolti a chi guarda (senza accesso, solo quelli aperti a chiunque).
// Pagina pubblica: l'elenco lo decide App\Forms\ServizioForms::apertiPer() (stato, periodo, numero massimo e pubblico, con App\Auth\Pubblico).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
sync_sso_user($conn); // accesso automatico dalla sessione SSO di Ateneo (serve prima di decidere cosa mostrare)

$app = \App\Core\App::per($conn);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$utente = !empty($_SESSION['utente_id']) ? $app->get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']) : null;
$loggato = $utente !== null;
$elenco = $app->get(\App\Forms\ServizioForms::class)->apertiPer($utente);

$page_cfg['titolo'] = 'MyForms · moduli da compilare';
require_once 'header.php';
?>
<div class="container my-4" style="max-width: 920px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">MyForms</span></nav>
    <h1 class="fw-bold h2 mb-1">MyForms</h1>
    <p class="text-secondary mb-4">Moduli da compilare<?php echo $loggato ? '' : ': qui vedi quelli aperti a tutti'; ?>.</p>

    <?php if (!$loggato): ?>
        <div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status">
            <i class="fa fa-key" aria-hidden="true"></i>
            <span>Alcuni moduli sono riservati a studenti, docenti o personale: <strong>accedi</strong> per vedere anche quelli.</span>
            <a class="btn btn-sm btn-primary fw-bold ms-auto" href="saml_login.php?redirect=<?php echo urlencode('forms.php'); ?>">Accedi</a>
        </div>
    <?php endif; ?>

    <?php if (!$elenco): ?>
        <div class="alert alert-light border text-secondary" role="status"><i class="fa fa-mug-hot me-1" aria-hidden="true"></i>In questo momento non ci sono moduli da compilare. Torna a trovarci più avanti.</div>
    <?php else: ?>
        <div class="row g-3">
        <?php foreach ($elenco as $x):
            $f = $x['form'];
            $r = $x['risposta'];
            $scad = !empty($f['aperta_al']) ? 'Si chiude il ' . date('d/m/Y \a\l\l\e H:i', strtotime($f['aperta_al'])) : 'Nessuna scadenza';
            $breve = trim(preg_replace('/\s+/', ' ', strip_tags((string)$f['descrizione'])) ?? '');
            if (mb_strlen($breve) > 160) $breve = rtrim(mb_substr($breve, 0, 160)) . '…';
            $puo_modificare = $r && !empty($f['modificabile']);
        ?>
            <div class="col-12 col-md-6">
                <div class="card border-0 shadow-sm h-100 p-3" style="border-radius:12px;">
                    <h2 class="h5 fw-bold mb-1"><a href="form.php?c=<?php echo $h(rawurlencode((string)$f['codice'])); ?>" class="text-decoration-none stretched-link"><?php echo $h($f['titolo'] !== '' ? $f['titolo'] : 'Modulo senza titolo'); ?></a></h2>
                    <?php if ($breve !== ''): ?><div class="small text-secondary mb-2"><?php echo $h($breve); ?></div><?php endif; ?>
                    <div class="small mt-auto"><i class="fa fa-calendar me-1" aria-hidden="true"></i><?php echo $h($scad); ?></div>
                    <?php if ($r): ?>
                        <div class="small text-success fw-bold mt-1"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Già compilato il <?php echo $h(date('d/m/Y', strtotime($r['inviata_il']))); ?><?php echo $puo_modificare ? ' · puoi ancora modificarlo' : ''; ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
