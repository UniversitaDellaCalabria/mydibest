<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: s (la scheda), campoCsrf, puoFsl, chiaveUrl, oggi (solo per chi gestisce tutta la FSL)
$s = $d->s;
$csrf = $d->grezzo('campoCsrf');
$fatte = $s->elenco('valutazioni');
$mancano = array_filter($s->elenco('eventi'), static fn ($e): bool => (bool) $e->grezzo('fsl') && (bool) $e->grezzo('attiva') && (bool) $e->grezzo('conclusa') && (int) $e->grezzo('val_id') === 0);
?>
<?php if (!$fatte && !$mancano): ?><p class="text-secondary mb-0">Nessuna valutazione da mostrare<?php echo (int) $s->anno > 0 ? " per l'anno scelto" : ''; ?>.</p><?php endif; ?>
<?php foreach ($fatte as $e): ?>
    <div class="sc-file"><span><?php echo $e->titolo; ?> <span class="sc-sotto"><?php echo $e->docente; ?></span></span><span><i class="fa fa-star me-1" aria-hidden="true"></i><?php echo $e->grezzo('val_media') !== '' ? 'media ' . $e->val_media : 'compilata'; ?></span></div>
<?php endforeach; ?>
<?php if ($mancano): ?>
    <h3 class="h6 fw-bold mt-3 mb-2">Concluse, senza scheda di valutazione</h3>
    <?php foreach ($mancano as $e): ?>
        <div class="sc-file"><span><?php echo $e->titolo; ?> <span class="sc-sotto"><?php echo $e->docente; ?></span></span>
            <span><?php if ($e->grezzo('email') !== ''): ?><form method="POST" class="d-inline"><?php echo $csrf; ?><button type="submit" name="ricorda_valutazione" value="<?php echo $e->id; ?>" class="btn btn-sm btn-outline-secondary" data-conferma="Mandare al docente il promemoria per la scheda di valutazione?"><i class="fa fa-bell me-1" aria-hidden="true"></i>Ricorda</button></form><?php endif; ?></span></div>
    <?php endforeach; ?>
<?php endif; ?>
