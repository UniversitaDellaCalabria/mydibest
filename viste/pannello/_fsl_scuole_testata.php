<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: s (la scheda), campoCsrf, puoFsl, chiaveUrl, oggi, statoHtml (HTML)
$s = $d->s;
$an = $s->grezzo('anagrafe') !== null ? $s->anagrafe : null;
$azione = $s->azione;
$chiave = (string) $s->azione->grezzo('chiave');
$aMano = (bool) $s->grezzo('a_mano');
?>
<?php if ($an !== null): ?>
    <p class="mb-1"><strong><?php echo $an->denominazione; ?></strong> — <?php echo $an->comune; ?><?php echo $an->grezzo('provincia') !== '' ? ' (' . $an->provincia . ')' : ''; ?><?php echo $an->grezzo('tipo') !== '' ? ' · ' . $an->tipo : ''; ?> · codice <span class="font-monospace"><?php echo $s->codice; ?></span></p>
    <?php if ($an->grezzo('indirizzo') !== ''): ?><p class="small text-secondary mb-1"><i class="fa fa-location-dot me-1" aria-hidden="true"></i><?php echo $an->indirizzo; ?></p><?php endif; ?>
    <div class="sc-contatti">
        <?php if ($an->grezzo('pec') !== ''): ?><span><i class="fa fa-envelope-circle-check me-1" aria-hidden="true"></i>PEC <a href="mailto:<?php echo $an->pec; ?>"><?php echo $an->pec; ?></a></span><?php endif; ?>
        <?php if ($an->grezzo('email') !== ''): ?><span><i class="fa fa-envelope me-1" aria-hidden="true"></i><a href="mailto:<?php echo $an->email; ?>"><?php echo $an->email; ?></a></span><?php endif; ?>
        <?php if ($an->grezzo('telefono') !== ''): ?><span><i class="fa fa-phone me-1" aria-hidden="true"></i><a href="tel:<?php echo \App\Http\DatiVista::escapa(preg_replace('/[^0-9+]/', '', (string) $an->grezzo('telefono'))); ?>"><?php echo $an->telefono; ?></a></span><?php endif; ?>
        <?php $ind = $s->contatti_indicati; $haInd = $ind->grezzo('pec') !== '' || $ind->grezzo('email') !== '' || $ind->grezzo('telefono') !== ''; ?>
        <?php if ($ind->grezzo('pec') !== ''): ?><span><i class="fa fa-envelope-circle-check me-1" aria-hidden="true"></i>PEC <a href="mailto:<?php echo $ind->pec; ?>"><?php echo $ind->pec; ?></a> <small class="text-secondary">indicata dalla scuola</small></span><?php endif; ?>
        <?php if ($ind->grezzo('email') !== ''): ?><span><i class="fa fa-envelope me-1" aria-hidden="true"></i><a href="mailto:<?php echo $ind->email; ?>"><?php echo $ind->email; ?></a> <small class="text-secondary">indicata dalla scuola</small></span><?php endif; ?>
        <?php if ($ind->grezzo('telefono') !== ''): ?><span><i class="fa fa-phone me-1" aria-hidden="true"></i><a href="tel:<?php echo \App\Http\DatiVista::escapa(preg_replace('/[^0-9+]/', '', (string) $ind->grezzo('telefono'))); ?>"><?php echo $ind->telefono; ?></a> <small class="text-secondary">indicato da un docente della scuola</small></span><?php endif; ?>
        <?php if ($an->grezzo('pec') === '' && $an->grezzo('email') === '' && $an->grezzo('telefono') === '' && !$haInd): ?><span class="text-secondary">Nessun recapito nell'anagrafe né indicato dalla scuola: i docenti sono qui sotto.</span><?php endif; ?>
    </div>
<?php else: ?>
    <p class="mb-2">Questa scuola è stata scritta a mano nel modulo di prenotazione e non ha un codice meccanografico. <a href="scuole.php">Abbinala all'anagrafe</a> per poterle avviare la convenzione.</p>
<?php endif; ?>

<div class="sc-principale">
    <span><?php echo $d->grezzo('statoHtml'); ?>
        <?php if ($s->grezzo('validita') !== ''): ?> <strong><?php echo $s->validita; ?></strong><?php endif; ?>
        <?php if ($s->grezzo('stato') === 'manca'): ?> Nessuna convenzione registrata per questa scuola.<?php endif; ?>
        <?php if ((int) $s->n_scoperte > 0): $inCorso = $s->grezzo('stato') === 'in_corso'; ?><span class="sc-sotto<?php echo $inCorso ? '' : ' text-danger'; ?>"><?php echo $s->n_scoperte; ?> <?php echo (int) $s->n_scoperte === 1 ? 'attività FSL in corso o da svolgere non è coperta' : 'attività FSL in corso o da svolgere non sono coperte'; ?> da una convenzione<?php echo $inCorso ? ': si sta stipulando.' : '.'; ?></span><?php endif; ?></span>
    <?php if ($aMano): ?>
        <a class="btn btn-azione fw-bold" href="scuole.php"><i class="fa fa-link me-1" aria-hidden="true"></i>Abbina la scuola all'anagrafe</a>
    <?php elseif (in_array($chiave, ['iter', 'sollecita', 'avvia', 'rinnova'], true)): ?>
        <a class="btn btn-azione fw-bold" href="#<?php echo $azione->ancora; ?>"><i class="fa <?php echo ['iter' => 'fa-arrow-right', 'sollecita' => 'fa-paper-plane', 'avvia' => 'fa-paper-plane', 'rinnova' => 'fa-rotate'][$chiave]; ?> me-1" aria-hidden="true"></i><?php echo $azione->testo; ?></a>
    <?php elseif ($chiave === 'attesa'): ?>
        <span class="text-secondary fw-bold"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i><?php echo $azione->testo; ?></span>
    <?php else: ?>
        <span class="text-secondary fw-bold"><i class="fa fa-circle-check me-1" aria-hidden="true"></i><?php echo $s->grezzo('attesa') !== '' ? $s->attesa : $azione->testo; ?></span>
    <?php endif; ?>
</div>
<?php if (in_array('scaduta_nessuna_attivita', (array) $s->grezzo('tags'), true)): ?><p class="small text-secondary mb-1"><i class="fa fa-clock-rotate-left me-1" aria-hidden="true"></i>Scaduta, nessuna attività: non c'è niente di urgente, ma la convenzione non vale più.</p><?php endif; ?>
<?php if ($s->elenco('cose_altre')): ?>
<ul class="sc-cose" aria-label="Altro da fare"><?php foreach ($s->elenco('cose_altre') as $x): ?><li><?php echo $x; ?></li><?php endforeach; ?></ul>
<?php endif; ?>
