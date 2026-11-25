<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: s (la scheda), campoCsrf, puoFsl, chiaveUrl, oggi
$s = $d->s;
$eventi = $s->elenco('eventi');
$aMano = (bool) $s->grezzo('a_mano');
$conAutorizzazione = array_filter($eventi, static fn ($e): bool => (bool) $e->grezzo('autorizzazione'));
$conElenco = array_filter($eventi, static fn ($e): bool => (bool) $e->grezzo('puo_studenti') && (int) $e->grezzo('n_elenco') > 0);
?>
<h3 class="h6 fw-bold mb-2">Modelli già compilati</h3>
<?php if (!$aMano && (int) $s->n_fsl_in_essere > 0): ?>
    <p class="small text-secondary">Con i dati dell'anagrafe e delle prenotazioni FSL in corso. Il logo della scuola non è incluso, a meno che l'abbia caricato la scuola.</p>
    <div class="d-grid gap-2 mb-3">
        <a class="btn btn-azione fw-bold" href="allegato_a.php?k=<?php echo $d->chiaveUrl; ?>&amp;doc=allegato_pdf"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Allegato A (PDF)</a>
        <a class="btn btn-outline-secondary fw-bold" href="allegato_a.php?k=<?php echo $d->chiaveUrl; ?>&amp;doc=allegato"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Allegato A (Word)</a>
        <a class="btn btn-outline-secondary fw-bold" href="allegato_a.php?k=<?php echo $d->chiaveUrl; ?>&amp;doc=convenzione"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Convenzione (Word)</a>
    </div>
<?php else: ?>
    <p class="small text-secondary">L'Allegato A e la convenzione precompilati si generano solo per una scuola in anagrafe che ha prenotazioni FSL ancora da svolgere.</p>
<?php endif; ?>

<h3 class="h6 fw-bold mb-2">Autorizzazioni caricate</h3>
<?php if (!$conAutorizzazione): ?><p class="small text-secondary">Nessuna autorizzazione caricata<?php echo (int) $s->anno > 0 ? " per l'anno scelto" : ''; ?>.</p>
<?php else: foreach ($conAutorizzazione as $e): ?>
    <div class="sc-file"><span><?php echo $e->titolo; ?> <span class="sc-sotto"><?php echo $e->docente; ?></span></span><span><a href="../autorizzazione_scuola.php?code=<?php echo \App\Http\DatiVista::escapa(urlencode((string) $e->grezzo('codice'))); ?>" target="_blank" rel="noopener">Vedi il file</a></span></div>
<?php endforeach; endif; ?>

<?php if ($d->grezzo('puoFsl')): ?>
<h3 class="h6 fw-bold mt-3 mb-2">Elenchi degli studenti</h3>
<?php if (!$conElenco): ?><p class="small text-secondary mb-0">Nessun elenco che tu possa aprire<?php echo (int) $s->anno > 0 ? " per l'anno scelto" : ''; ?>. I nomi degli studenti si vedono solo dalla pagina dell'attività, a chi può gestirla.</p>
<?php else: foreach ($conElenco as $e): ?>
    <div class="sc-file"><span><?php echo $e->titolo; ?> <span class="sc-sotto"><?php echo $e->n_elenco; ?> studenti</span></span><span><a href="partecipanti.php?p_id=<?php echo $e->pagina_id; ?>&amp;pr=<?php echo $e->id; ?>">Apri l'elenco</a></span></div>
<?php endforeach; endif; ?>
<?php endif; ?>
