<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: filtro (area), campoCsrf (HTML), modifica (-1 nessuno · 0 nuovo · id), nuovo (record vuoto), contatti (elenco di record + 'recapiti' = elenco di testi) ?>
<?php $filtro = (int) $d->filtro; $modifica = (int) $d->modifica; $contatti = $d->elenco('contatti'); ?>
<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div><h1 class="pn-titolo"><i class="fa fa-address-card me-2" aria-hidden="true"></i>Contatti per gli studenti</h1>
        <p class="pn-aiuto">«Chi contattare per cosa»: la pagina pubblica che dice agli studenti a quale ufficio rivolgersi. Qui scegli tu cosa mostrare: niente viene preso dagli uffici o dalle persone.</p></div>
    <div class="d-flex gap-2"><a href="../contatti.php" target="_blank" rel="noopener" class="btn btn-outline-secondary"><i class="fa fa-external-link-alt me-1" aria-hidden="true"></i>Guarda la pagina</a>
        <a href="contatti.php?modifica=0&amp;p_id=<?php echo $filtro; ?>" class="btn btn-azione fw-bold"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi un contatto</a></div>
</div>

<?php if ($modifica === 0): ?>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5 fw-bold mb-3">Nuovo contatto</h2><?php echo $d->parziale('pannello/_contatti_modulo', ['c' => $d->nuovo, 'campoCsrf' => $d->grezzo('campoCsrf')]); ?></div></div>
<?php endif; ?>

<?php if (!$contatti && $modifica !== 0): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5">
        <i class="fa fa-address-card fs-1 text-secondary mb-3 d-block" aria-hidden="true"></i>
        <h2 class="h5 fw-bold">Non c'è ancora nessun contatto</h2>
        <p class="text-secondary mb-3">Puoi partire da cinque voci di esempio (segreteria, tirocini, tesi, orientamento, direzione): restano nascoste finché non le completi.</p>
        <form method="POST" class="d-inline"><?php echo $d->grezzo('campoCsrf'); ?><button type="submit" name="esempi" value="1" class="btn btn-azione fw-bold"><i class="fa fa-wand-magic-sparkles me-1" aria-hidden="true"></i>Parti dagli esempi</button></form>
    </div></div>
<?php endif; ?>

<div class="d-flex flex-column gap-3">
<?php foreach ($contatti as $c): $id = (int) $c->id; $vis = (int) $c->visibile === 1; $manca = $c->email === '' && $c->telefono === '' && $c->link_url === ''; ?>
    <div class="card border-0 shadow-sm"><div class="card-body">
        <?php if ($modifica === $id): ?>
            <h2 class="h5 fw-bold mb-3">Modifica «<?php echo $c->titolo; ?>»</h2><?php echo $d->parziale('pannello/_contatti_modulo', ['c' => $c, 'campoCsrf' => $d->grezzo('campoCsrf')]); ?>
        <?php else: ?>
            <div class="d-flex flex-wrap align-items-start gap-3">
                <div class="flex-grow-1" style="min-width:240px;">
                    <h2 class="h5 fw-bold mb-1"><?php echo $c->titolo; ?> <?php echo $vis ? \App\Portale\Vista\Pannello::stato('Si vede sul sito', 'ok', 'fa-eye') : \App\Portale\Vista\Pannello::stato('Nascosto', 'spento', 'fa-eye-slash'); ?></h2>
                    <div class="text-secondary"><?php echo $c->per_cosa; ?></div>
                    <div class="small mt-2"><?php $recapiti = $c->elenco('recapiti'); echo $recapiti ? implode(' · ', $recapiti) : '<span class="text-secondary">Nessun recapito ancora: aggiungi almeno un\'email o un telefono.</span>'; ?></div>
                    <?php if ($manca && $vis): ?><div class="small text-danger mt-1"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Si vede sul sito ma senza recapiti.</div><?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <form method="POST" class="m-0"><?php echo $d->grezzo('campoCsrf'); ?><input type="hidden" name="valore" value="<?php echo $vis ? 0 : 1; ?>"><button type="submit" name="mostra" value="<?php echo $id; ?>" class="btn btn-outline-secondary"><?php echo $vis ? 'Nascondi' : 'Mostra sul sito'; ?></button></form>
                    <a href="contatti.php?modifica=<?php echo $id; ?>&amp;p_id=<?php echo $filtro; ?>" class="btn btn-azione fw-bold">Modifica</a>
                    <form method="POST" class="m-0"><?php echo $d->grezzo('campoCsrf'); ?><button type="submit" name="elimina" value="<?php echo $id; ?>" class="btn btn-outline-danger" data-conferma="Eliminare il contatto «<?php echo $c->titolo; ?>»?" aria-label="Elimina «<?php echo $c->titolo; ?>»"><i class="fa fa-trash" aria-hidden="true"></i></button></form>
                </div>
            </div>
        <?php endif; ?>
    </div></div>
<?php endforeach; ?>
</div>
