<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: s (la scheda), campoCsrf, puoFsl, chiaveUrl, oggi
$s = $d->s;
$sedi = $s->elenco('sedi');
?>
<?php if (!$sedi): ?>
    <p class="text-secondary mb-0">Questa scuola non è nell'anagrafe del Ministero. Quando la abbini a quella vera (<a href="scuole.php">Scuole</a>) compaiono qui le sue sedi.</p>
<?php else: ?>
    <p class="small text-secondary">Una convenzione firmata dall'istituto vale per tutte le sue sedi: gli eventi di ogni sede sono nel riquadro «Eventi e progetti».</p>
    <?php foreach ($sedi as $x): ?>
        <div class="sc-file"><span><?php echo $x->nome; ?></span><span class="font-monospace"><?php echo $x->codice; ?><?php echo $x->grezzo('principale') ? ' · istituto' : ''; ?></span></div>
    <?php endforeach; ?>
<?php endif; ?>
