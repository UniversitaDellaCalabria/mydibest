<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: anni (valore, testo, scelto), annoValore, dal, al, compitiHtml, percorsoHtml (HTML), elenchi, attivita, scuole, scaricaAttivita/Scuole/Valutazioni/Report (HTML), valutazioni, aspetti, rip, intestazioniAperte, reportPercorsoHtml (HTML), reportScuole ?>
<form method="GET" action="fsl.php" class="d-flex flex-wrap align-items-end gap-2 mb-3">
    <div><label class="form-label small fw-bold mb-1" for="nrAnno">Anno scolastico</label>
        <select class="form-select" id="nrAnno" name="anno" data-auto-submit><?php foreach ($d->elenco('anni') as $a): ?><option value="<?php echo $a->valore; ?>"<?php echo $a->grezzo('scelto') ? ' selected' : ''; ?>><?php echo $a->testo; ?></option><?php endforeach; ?></select></div>
    <noscript><button type="submit" class="btn btn-outline-secondary">Vai</button></noscript>
    <p class="small text-secondary mb-1 flex-grow-1">Attività con <strong>Attività di Formazione Scuola Lavoro</strong> attivo, di ogni area, dal <?php echo $d->dal; ?> al <?php echo $d->al; ?>. Ogni tabella si scarica in Excel, PDF o CSV (menu «Scarica»).</p>
</form>

<nav class="pn-indice" aria-label="Sezioni della pagina">
    <span>Vai a</span>
    <a href="#riepilogo">Riepilogo</a>
    <a href="#valutazioni">Valutazioni</a>
    <a href="#report">Report</a>
</nav>

<section class="nr-sez" id="riepilogo" aria-labelledby="nrTitoloRiepilogo">
    <h2 id="nrTitoloRiepilogo"><i class="fa fa-chart-column me-1" aria-hidden="true"></i>Riepilogo dell'anno scolastico</h2>
    <?php echo $d->grezzo('compitiHtml'); ?>
    <?php echo $d->grezzo('percorsoHtml'); ?>

    <?php if ($d->elenco('elenchi')): ?>
    <div id="elenchi" class="nr-sotto">
        <h3><i class="fa fa-user-graduate me-1" aria-hidden="true"></i>Elenchi studenti da inserire (<?php echo count($d->elenco('elenchi')); ?>)</h3>
        <p class="small text-secondary">Attività già finite con attestati per gli studenti ma senza l'elenco dei nomi: finché manca, gli attestati non partono. Il docente riceve un promemoria; da qui puoi aprire l'iscrizione.</p>
        <div class="table-responsive"><table class="table table-sm align-middle small mb-0">
            <thead class="table-light"><tr><th>Scuola</th><th>Attività</th><th>Finita il</th><th>Docente</th><th><span class="visually-hidden">Azioni</span></th></tr></thead>
            <tbody>
            <?php foreach ($d->elenco('elenchi') as $x): ?>
                <tr>
                    <td class="fw-semibold"><?php echo $x->scuola; ?></td><td><?php echo $x->titolo; ?><div class="text-secondary"><?php echo $x->area; ?></div></td>
                    <td class="text-nowrap"><?php echo $x->al; ?></td>
                    <td><?php echo $x->docente; ?><div class="text-secondary"><?php echo $x->email; ?></div></td>
                    <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary fw-bold py-0" href="<?php echo $x->href; ?>">Apri l'iscrizione</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <div class="nr-sotto">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h3 class="mb-0"><i class="fa fa-list me-1" aria-hidden="true"></i>Attività (<?php echo count($d->elenco('attivita')); ?>)</h3>
            <?php if ($d->elenco('attivita')) { echo $d->grezzo('scaricaAttivita'); } ?>
        </div>
        <?php if (!$d->elenco('attivita')): ?><p class="text-secondary small mb-0">Nessuna attività FSL con iscrizioni in questo anno scolastico.</p><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle small" id="tabAtt">
            <thead class="table-light"><tr><th>Attività</th><th>Area</th><th>Periodo</th><th class="text-center">Scuole</th><th class="text-center">Confermate</th><th class="text-center">Studenti</th><th class="text-center">Presenze</th><th class="text-center">Senza convenzione</th><th class="text-center">Valutazioni</th><th class="text-center">Media</th></tr></thead>
            <tbody>
            <?php foreach ($d->elenco('attivita') as $a): ?>
                <tr>
                    <td class="fw-semibold"><?php echo $a->titolo; ?></td><td><?php echo $a->area; ?></td><td class="text-nowrap"><?php echo $a->periodo; ?></td>
                    <td class="text-center"><?php echo $a->scuole; ?></td><td class="text-center"><?php echo $a->confermate; ?></td><td class="text-center"><?php echo $a->studenti; ?></td><td class="text-center"><?php echo $a->presenze; ?></td>
                    <td class="text-center<?php echo (int) $a->grezzo('senzaConvenzione') > 0 ? ' text-danger fw-bold' : ''; ?>"><?php echo $a->senzaConvenzione; ?></td>
                    <td class="text-center"><?php echo $a->valutazioni; ?></td><td class="text-center"><?php echo $a->media; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="nr-sotto">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h3 class="mb-0"><i class="fa fa-school me-1" aria-hidden="true"></i>Scuole (<?php echo count($d->elenco('scuole')); ?>)</h3>
            <?php if ($d->elenco('scuole')) { echo $d->grezzo('scaricaScuole'); } ?>
        </div>
        <?php if (!$d->elenco('scuole')): ?><p class="text-secondary small mb-0">Nessuna scuola in questo anno scolastico.</p><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle small" id="tabScu">
            <thead class="table-light"><tr><th>Scuola</th><th>Codice</th><th>Attività</th><th class="text-center">Iscrizioni</th><th class="text-center">Studenti</th><th class="text-center">Presenze</th><th>Convenzione in registro</th><th class="text-center">Valutazione</th></tr></thead>
            <tbody>
            <?php foreach ($d->elenco('scuole') as $s): ?>
                <tr>
                    <td class="fw-semibold"><?php if ($s->grezzo('href') !== ''): ?><a href="<?php echo $s->href; ?>" class="text-decoration-none"><?php echo $s->nome; ?></a><?php else: ?><?php echo $s->nome; ?><?php endif; ?></td>
                    <td class="font-monospace"><?php echo $s->codice; ?></td><td><?php echo $s->attivita; ?></td>
                    <td class="text-center"><?php echo $s->iscrizioni; ?></td><td class="text-center"><?php echo $s->studenti; ?></td><td class="text-center"><?php echo $s->presenze; ?></td>
                    <td><?php echo $s->grezzo('convenzione') === 'nessuna' ? '<span class="text-secondary">nessuna</span>' : $s->convenzione; ?><?php if ($s->grezzo('daStipulare')): ?> <span class="badge ui-bg-pericolo-sfondo ui-c-pericolo-testo">da stipulare</span><?php endif; ?></td>
                    <td class="text-center"><?php echo $s->media; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</section>

<section class="nr-sez" id="valutazioni" aria-labelledby="nrTitoloValutazioni">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 id="nrTitoloValutazioni" class="mb-0"><i class="fa fa-star me-1" aria-hidden="true"></i>Schede di valutazione della struttura ospitante (<?php echo count($d->elenco('valutazioni')); ?>)</h2>
        <?php if ($d->elenco('valutazioni')) { echo $d->grezzo('scaricaValutazioni'); } ?>
    </div>
    <p class="small text-secondary">A fine attività (presenza registrata) il docente riceve per email il link alla scheda, con un promemoria dopo 7 giorni. Voti da 1 a 5.</p>
    <?php if (!$d->elenco('valutazioni')): ?><p class="text-secondary small mb-0">Nessuna scheda compilata in questo anno scolastico.</p><?php else: ?>
    <div class="row g-4 mb-3">
        <div class="col-lg-7">
            <table class="table table-sm small mb-0">
                <?php foreach ($d->elenco('aspetti') as $a): ?>
                    <tr><td><?php echo $a->etichetta; ?></td><td class="ui-w-40p"><div class="nr-barra" role="img" aria-label="Media <?php echo $a->media; ?> su 5"><span style="width:<?php echo (int) $a->grezzo('larghezza'); ?>%"></span></div></td><td class="fw-bold text-end"><?php echo $a->media; ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="col-lg-5">
            <div class="small fw-bold mb-1">Riproporrebbero l'attività</div>
            <?php foreach ($d->elenco('rip') as $x): ?><div class="small"><?php echo $x->testo; ?>: <strong><?php echo $x->n; ?></strong></div><?php endforeach; ?>
        </div>
    </div>
    <div class="table-responsive"><table class="table table-sm align-top small" id="tabVal">
        <thead class="table-light"><tr><th>Data</th><th>Attività</th><th>Scuola</th><th>Compilata da</th><th class="text-center">Media</th><th>Riproporrebbe</th>
            <?php foreach ($d->elenco('intestazioniAperte') as $t): ?><th><?php echo $t; ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($d->elenco('valutazioni') as $v): ?>
            <tr>
                <td class="text-nowrap"><?php echo $v->data; ?></td><td><?php echo $v->titolo; ?></td><td><?php echo $v->scuola; ?></td><td><?php echo $v->compilata; ?></td>
                <td class="text-center fw-bold"><?php echo $v->media; ?></td><td><?php echo $v->riproporrebbe; ?></td>
                <?php foreach ($v->elenco('testi') as $t): ?><td class="ui-maxw-260"><?php echo nl2br((string) $t); ?></td><?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="nr-sez" id="report" aria-labelledby="nrTitoloReport">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 id="nrTitoloReport" class="mb-0"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Report per le scuole e per il Ministero</h2>
        <?php echo $d->grezzo('scaricaReport'); ?>
    </div>
    <p class="text-secondary small">Attività di Formazione Scuola Lavoro confermate dal <?php echo $d->dal; ?> al <?php echo $d->al; ?>. Le <strong>ore di formazione</strong> sono le ore dell'attività per gli studenti <strong>presenti</strong>; nell'Excel trovi anche le ore di ogni studente, per le classi con l'elenco dei nomi inserito.</p>
    <?php echo $d->grezzo('reportPercorsoHtml'); ?>
    <?php if (!$d->elenco('reportScuole')): ?><p class="text-secondary small mb-0">Nessuna attività FSL confermata in questo anno scolastico.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle small" id="tabRep">
        <thead class="table-light"><tr><th>Scuola</th><th>Comune</th><th class="text-center">Attività</th><th class="text-center">Classi</th><th class="text-center">Studenti</th><th class="text-center">Presenti</th><th class="text-end">Ore complessive</th><th class="text-end">Ore medie</th></tr></thead>
        <tbody><?php foreach ($d->elenco('reportScuole') as $s): ?>
            <tr><td class="fw-semibold"><?php echo $s->nome; ?></td><td><?php echo $s->comune; ?></td><td class="text-center"><?php echo $s->attivita; ?></td><td class="text-center"><?php echo $s->classi; ?></td>
                <td class="text-center"><?php echo $s->studenti; ?></td><td class="text-center"><?php echo $s->presenti; ?></td><td class="text-end"><?php echo $s->ore; ?></td><td class="text-end"><?php echo $s->oreMedie; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
