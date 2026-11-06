<?php
// inc/tsp_guida_vista.php - Pagina di una guida interattiva sulla piattaforma TSP 2.0 (animazioni passo per passo).
// I contenuti e le animazioni stanno in assets/js/<script>.js (il motore è assets/js/tsp-guida.js, lo stile assets/css/tsp-guida.css).
// $p: titolo, sottotitolo, colore, script (file in assets/js), a_chi, altra = [etichetta, indirizzo], icona
if (!function_exists('tsp_guida_vista')) {
    function tsp_guida_vista(array $p): void
    {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $v = fn(string $f): int => (int)@filemtime(__DIR__ . '/../' . $f);
        echo ui_tema_script();
        ?>
<link rel="stylesheet" href="assets/css/tsp-guida.css?v=<?php echo $v('assets/css/tsp-guida.css'); ?>">
<div class="container my-4" style="max-width: 1140px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <a href="percorso_tirocini.php">Tirocinio: i passi</a> <span class="text-secondary mx-1">/</span> <?php echo $h($p['titolo']); ?></nav>
    <header class="tg-hero" style="--tg-acc: <?php echo $h($p['colore']); ?>;">
        <h1><i class="fa <?php echo $h($p['icona']); ?> me-2" aria-hidden="true"></i><?php echo $h($p['titolo']); ?></h1>
        <p><?php echo $h($p['sottotitolo']); ?></p>
        <div class="tg-hero-az">
            <a class="btn btn-light" href="https://unical.tsp.esse3.cineca.it" target="_blank" rel="noopener noreferrer"><i class="fa fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>Vai alla piattaforma TSP 2.0</a>
            <a class="btn btn-outline-light" href="<?php echo $h($p['altra'][1]); ?>"><?php echo $h($p['altra'][0]); ?></a>
            <span class="d-print-none ms-md-auto"><?php echo ui_tema_pulsante(); ?></span>
        </div>
    </header>
    <ul class="tg-come" style="--tg-acc: <?php echo $h($p['colore']); ?>;">
        <li><i class="fa fa-eye" aria-hidden="true"></i><span><b>Guarda</b><br>Ogni passo ha un&rsquo;animazione della schermata: segui il cursore.</span></li>
        <li><i class="fa fa-hand-pointer" aria-hidden="true"></i><span><b>Rifai</b><br>Poi fai lo stesso sulla piattaforma, con la lista «Cosa fai» accanto.</span></li>
        <li><i class="fa fa-sliders" aria-hidden="true"></i><span><b>Controlli tu</b><br>Pausa, ripeti, rallenta o salta alla fine dell&rsquo;animazione.</span></li>
        <li><i class="fa fa-bookmark" aria-hidden="true"></i><span><b>Ti ricordo dove sei</b><br>I passi fatti restano segnati su questo dispositivo.</span></li>
    </ul>
    <div id="tsp-guida"><noscript><div class="alert alert-warning">Questa guida animata ha bisogno di JavaScript. Senza, trovi i passaggi nella <a href="percorso_tirocini.php">pagina dei tirocini</a> o chiedi all&rsquo;<a href="assistenza.php">assistenza</a>.</div></noscript></div>
    <p class="small text-secondary mt-3"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Le schermate delle animazioni sono ricostruzioni schematiche con nomi e dati di fantasia: sulla piattaforma vera l&rsquo;aspetto può cambiare leggermente, ma i passaggi sono quelli della guida operativa dell&rsquo;Ufficio Tirocini.</p>
</div>
<script src="assets/js/tsp-guida.js?v=<?php echo $v('assets/js/tsp-guida.js'); ?>"></script>
<script src="assets/js/<?php echo $h($p['script']); ?>?v=<?php echo $v('assets/js/' . $p['script']); ?>"></script>
<?php
    }
}
