<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: campoCsrf (HTML), puoFsl, s (la scheda di SchedaScuolaFsl), chiaveUrl, annoValore, hrefElenco, hrefPdf, statoHtml (HTML), docentiConEmail, anteprima (record o null), erroreAvvio, anni, oggi, iterId, periodoNuovo (dal, al), apri (modulo già aperto: modifica, nuova, rinnova) ?>
<?php
$s = $d->s;
$puoFsl = (bool) $d->grezzo('puoFsl');
$comuni = [
    's' => $s,
    'campoCsrf' => $d->grezzo('campoCsrf'),
    'puoFsl' => $puoFsl,
    'chiaveUrl' => $d->grezzo('chiaveUrl'),
    'oggi' => $d->grezzo('oggi'),
];
?>
<?php echo \App\Portale\Vista\Pannello::indietro((string) $d->grezzo('hrefElenco'), 'Tutte le scuole'); ?>

<div class="pn-testata">
    <div class="pn-testata-riga">
        <span class="pn-testata-ico" aria-hidden="true"><i class="fa fa-school"></i></span>
        <div class="pn-testata-testi">
            <h1 class="pn-titolo"><?php echo $s->nome; ?></h1>
            <p class="pn-aiuto"><?php echo $s->grezzo('codice') !== '' ? 'Codice meccanografico ' . $s->codice . '. ' : "Scuola scritta a mano nel modulo di prenotazione: non è nell'anagrafe. "; ?>Tutto quello che serve sapere di questa scuola è qui sotto, in una pagina sola.</p>
        </div>
        <div class="pn-testata-azioni">
            <a class="btn btn-outline-secondary fw-bold" href="<?php echo $d->hrefPdf; ?>"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Scarica la scheda</a>
            <form method="GET" action="fsl_scuole.php" class="d-flex gap-2 align-items-center m-0">
                <input type="hidden" name="k" value="<?php echo $s->chiave; ?>">
                <label class="visually-hidden" for="scAnnoScheda">Anno scolastico degli eventi</label>
                <select class="form-select" id="scAnnoScheda" name="anno" data-auto-submit><?php foreach ($d->elenco('anni') as $a): ?><option value="<?php echo $a->valore; ?>"<?php echo $a->grezzo('scelto') ? ' selected' : ''; ?>><?php echo $a->testo; ?></option><?php endforeach; ?></select>
                <noscript><button type="submit" class="btn btn-outline-secondary">Vai</button></noscript>
            </form>
        </div>
    </div>
</div>

<nav class="pn-indice" aria-label="Sezioni della scheda">
    <span>Vai a</span>
    <a href="#convenzione">Convenzione</a>
    <a href="#eventi">Eventi e progetti</a>
    <a href="#docenti">Docenti referenti</a>
    <a href="#messaggi">Messaggi</a>
    <a href="#documenti">Documenti</a>
    <?php if ($puoFsl): ?><a href="#valutazioni">Valutazioni</a><?php endif; ?>
    <a href="#anagrafe">Anagrafe e sedi</a>
</nav>

<div class="sc-griglia">
    <section class="sc-riquadro sc-largo" id="testata" aria-labelledby="scTitoloTestata">
        <h2 id="scTitoloTestata"><i class="fa fa-id-card" aria-hidden="true"></i>La scuola <?php echo $d->grezzo('statoHtml'); ?></h2>
        <?php echo $d->parziale('pannello/_fsl_scuole_testata', $comuni + ['statoHtml' => $d->grezzo('statoHtml')]); ?>
    </section>

    <section class="sc-riquadro sc-largo" id="convenzione" aria-labelledby="scTitoloConv">
        <h2 id="scTitoloConv"><i class="fa fa-file-signature" aria-hidden="true"></i>Convenzione</h2>
        <?php echo $d->parziale('pannello/_fsl_scuole_convenzione', $comuni + ['anteprima' => $d->grezzo('anteprima'), 'erroreAvvio' => $d->grezzo('erroreAvvio'), 'docentiConEmail' => $d->grezzo('docentiConEmail'), 'statoHtml' => $d->grezzo('statoHtml'), 'periodoNuovo' => $d->grezzo('periodoNuovo'), 'apri' => $d->grezzo('apri')]); ?>
    </section>

    <section class="sc-riquadro sc-largo" id="eventi" aria-labelledby="scTitoloEventi">
        <h2 id="scTitoloEventi"><i class="fa fa-calendar-check" aria-hidden="true"></i>Eventi e progetti <small><?php echo count($s->elenco('eventi')); ?> <?php echo (int) $s->anno > 0 ? "nell'anno scolastico " . (int) $s->anno . '/' . substr((string) ((int) $s->anno + 1), 2) : 'in tutti gli anni'; ?><?php echo (int) $s->n_eventi_tutti > count($s->elenco('eventi')) ? ' · ' . (int) $s->n_eventi_tutti . ' in tutto' : ''; ?></small></h2>
        <?php echo $d->parziale('pannello/_fsl_scuole_eventi', $comuni); ?>
    </section>

    <div class="sc-colonne sc-largo">
    <section class="sc-riquadro" id="docenti" aria-labelledby="scTitoloDocenti">
        <h2 id="scTitoloDocenti"><i class="fa fa-user-group" aria-hidden="true"></i>Docenti referenti <small><?php echo count($s->elenco('docenti')); ?></small></h2>
        <?php echo $d->parziale('pannello/_fsl_scuole_docenti', $comuni); ?>
    </section>

    <section class="sc-riquadro" id="messaggi" aria-labelledby="scTitoloMessaggi">
        <h2 id="scTitoloMessaggi"><i class="fa fa-comments" aria-hidden="true"></i>Messaggi con la scuola</h2>
        <?php echo $d->parziale('pannello/_fsl_scuole_messaggi', $comuni); ?>
    </section>

    <section class="sc-riquadro" id="documenti" aria-labelledby="scTitoloDocumenti">
        <h2 id="scTitoloDocumenti"><i class="fa fa-file-lines" aria-hidden="true"></i>Altri documenti</h2>
        <?php echo $d->parziale('pannello/_fsl_scuole_documenti', $comuni); ?>
    </section>

    <?php if ($puoFsl): ?>
    <section class="sc-riquadro" id="valutazioni" aria-labelledby="scTitoloVal">
        <h2 id="scTitoloVal"><i class="fa fa-star-half-stroke" aria-hidden="true"></i>Valutazioni</h2>
        <?php echo $d->parziale('pannello/_fsl_scuole_valutazioni', $comuni); ?>
    </section>
    <?php endif; ?>

    <section class="sc-riquadro" id="anagrafe" aria-labelledby="scTitoloAnag">
        <h2 id="scTitoloAnag"><i class="fa fa-building-columns" aria-hidden="true"></i>Anagrafe e sedi</h2>
        <?php echo $d->parziale('pannello/_fsl_scuole_anagrafe', $comuni); ?>
    </section>
    </div>
</div>

<?php echo $d->parziale('pannello/_fsl_scuole_ancore', ['pagina' => 'scheda']); ?>
