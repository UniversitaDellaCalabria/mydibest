<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: campoCsrf (HTML), puoFsl, righe (record: nome, comune, codice, aMano, href, statoHtml (HTML), data, passo, eventi, studenti|null, docenti, cosa, daFare), tessere (chiave, nome, numero, attiva, href), anni e stati (valore, testo, scelto), filtroTesto, annoValore, totale, totaleScuole, filtrato, pag, pagine, hrefPrec, hrefSucc, hrefScarica, hrefTutti, aMano, apriScuola (apre «Avviare o registrare una convenzione per una scuola che non è in elenco») ?>
<?php
$puoFsl = (bool) $d->grezzo('puoFsl');
$righe = $d->elenco('righe');
$csrf = $d->grezzo('campoCsrf');
$voci = [
    \App\Portale\Vista\VociMenu::post($csrf, 'cv_ricorda_tutte', '1', 'Ricorda il PDF firmato a tutte le scuole in attesa', 'fa-bell', 'Mandare il promemoria a tutte le scuole che devono ancora caricare il PDF firmato? Chi è stato avvisato nelle ultime 24 ore viene saltato.'),
];
if ($puoFsl) {
    $voci[] = \App\Portale\Vista\VociMenu::post($csrf, 'conv_verifica', '1', 'Verifica ora le iscrizioni coperte da una convenzione', 'fa-rotate', 'Ricalcolare quali iscrizioni sono coperte da una convenzione valida? Si può ripetere senza effetti doppi.');
    $voci[] = \App\Portale\Vista\VociMenu::post($csrf, 'ricorda_elenco_tutti', '1', "Ricorda l'elenco studenti a tutti i docenti a cui manca", 'fa-list-check', "Mandare il promemoria a tutti i docenti che non hanno ancora caricato l'elenco degli studenti? Chi è stato avvisato nelle ultime 24 ore viene saltato.");
}
if ((int) $d->aMano > 0) {
    $voci[] = \App\Portale\Vista\VociMenu::link('scuole.php', 'Abbina le scuole scritte a mano (' . (int) $d->aMano . ')', 'fa-link');
}
?>
<div class="pn-testata">
    <div class="pn-testata-riga">
        <span class="pn-testata-ico" aria-hidden="true"><i class="fa fa-school"></i></span>
        <div class="pn-testata-testi">
            <h1 class="pn-titolo">Scuole e convenzioni</h1>
            <p class="pn-aiuto">Una riga per ogni scuola: com'è la convenzione, quanti eventi e studenti, e la prossima cosa da fare. «Apri scheda» mostra tutto di quella scuola in una pagina sola.</p>
        </div>
        <div class="pn-testata-azioni"><?php echo \App\Portale\Vista\Pannello::scarica($d->grezzo('hrefScarica')); ?><?php echo \App\Portale\Vista\Pannello::altro(\App\Portale\Vista\VociMenu::ordina($voci)); ?></div>
    </div>
</div>

<nav class="pn-fasi mb-3" aria-label="Cosa devo fare">
<?php foreach ($d->elenco('tessere') as $t): ?>
    <a class="pn-fase<?php echo $t->grezzo('attiva') ? ' attiva' : ''; ?>" href="<?php echo $t->href; ?>"<?php echo $t->grezzo('attiva') ? ' aria-current="true"' : ''; ?>><b><?php echo $t->numero; ?></b><span><?php echo $t->nome; ?></span></a>
<?php endforeach; ?>
</nav>

<form method="GET" action="fsl_scuole.php" class="d-flex flex-wrap gap-2 align-items-end mb-3">
    <div><label class="form-label small fw-bold mb-1" for="scAnno">Anno scolastico</label>
        <select class="form-select" id="scAnno" name="anno"><?php foreach ($d->elenco('anni') as $a): ?><option value="<?php echo $a->valore; ?>"<?php echo $a->grezzo('scelto') ? ' selected' : ''; ?>><?php echo $a->testo; ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label small fw-bold mb-1" for="scStato">Convenzione</label>
        <select class="form-select" id="scStato" name="stato"><?php foreach ($d->elenco('stati') as $s): ?><option value="<?php echo $s->valore; ?>"<?php echo $s->grezzo('scelto') ? ' selected' : ''; ?>><?php echo $s->testo; ?></option><?php endforeach; ?></select></div>
    <div class="flex-grow-1 sc-campo"><label class="form-label small fw-bold mb-1" for="scTesto">Cerca</label>
        <input type="search" class="form-control" id="scTesto" name="q" value="<?php echo $d->filtroTesto; ?>" placeholder="Scuola, comune, docente, email" aria-label="Cerca per scuola, comune, codice, docente o email"></div>
    <button type="submit" class="btn btn-azione fw-bold"><i class="fa fa-filter me-1" aria-hidden="true"></i>Filtra</button>
    <?php if ($d->grezzo('filtrato')): ?><a class="btn btn-outline-secondary" href="<?php echo $d->hrefTutti; ?>">Togli i filtri</a><?php endif; ?>
</form>

<details class="mb-3" id="apriScuola"<?php echo $d->grezzo('apriScuola') ? ' open' : ''; ?>><summary class="small fw-bold"><i class="fa fa-file-signature me-1" aria-hidden="true"></i>Avviare o registrare una convenzione per una scuola che non è in elenco</summary>
    <form method="GET" action="fsl_scuole.php" class="d-flex flex-wrap gap-2 align-items-end mt-2">
        <div class="sc-campo"><label class="form-label small fw-bold mb-1" for="scCodiceApri">Codice meccanografico della scuola</label>
            <input type="text" class="form-control text-uppercase" id="scCodiceApri" name="k" maxlength="10" pattern="[A-Za-z0-9]{10}" required placeholder="es. CSIC81200X" autocomplete="off"></div>
        <button type="submit" class="btn btn-azione fw-bold">Apri la scheda</button></form>
    <p class="small text-secondary mt-1 mb-0">Nella scheda trovi «Avvia la stipula» e «Registrane una già firmata». Se non conosci il codice, cerca la scuola per nome qui sopra.</p></details>

<?php if (!$righe): ?>
    <div class="pn-vuoto"><i class="fa fa-school" aria-hidden="true"></i><p><?php echo $d->grezzo('filtrato') ? 'Nessuna scuola corrisponde ai filtri scelti.' : 'Non ci sono ancora scuole né prenotazioni FSL.'; ?></p></div>
<?php else: ?>
    <p class="small text-secondary mb-2"><?php echo $d->totale; ?> <?php echo (int) $d->totale === 1 ? 'scuola' : 'scuole'; ?><?php echo $d->grezzo('filtrato') ? ' su ' . (int) $d->totaleScuole : ''; ?> · prima quelle con qualcosa da fare, poi le scadute e in scadenza, poi per nome.</p>
    <table class="sc-tabella">
        <thead><tr><th>Scuola</th><th>Convenzione</th><th class="sc-num">Eventi</th><?php if ($puoFsl): ?><th class="sc-num">Studenti</th><?php endif; ?><th class="sc-num">Docenti</th><th>Prossima cosa da fare</th><th><span class="visually-hidden">Azioni</span></th></tr></thead>
        <tbody>
        <?php foreach ($righe as $r): ?>
            <tr>
                <td><a class="sc-nome" href="<?php echo $r->href; ?>"><?php echo $r->nome; ?></a>
                    <span class="sc-sotto"><?php echo $r->comune; ?><?php echo $r->grezzo('codice') !== '' ? ' · ' . $r->codice : ''; ?></span>
                    <?php if ($r->grezzo('aMano')): ?><span class="sc-sotto"><i class="fa fa-pen me-1" aria-hidden="true"></i>scritta a mano: non è nell'anagrafe</span><?php endif; ?></td>
                <td data-etichetta="Convenzione"><?php echo $r->grezzo('statoHtml'); ?>
                    <?php if ($r->grezzo('data') !== ''): ?><span class="sc-sotto"><?php echo $r->data; ?></span><?php endif; ?>
                    <?php if ($r->grezzo('passo') !== ''): ?><span class="sc-sotto"><?php echo $r->passo; ?></span><?php endif; ?></td>
                <td class="sc-num" data-etichetta="Eventi"><?php echo $r->eventi; ?></td>
                <?php if ($puoFsl): ?><td class="sc-num" data-etichetta="Studenti"><?php echo $r->studenti; ?></td><?php endif; ?>
                <td class="sc-num" data-etichetta="Docenti"><?php echo $r->docenti; ?>
                    <?php foreach ($r->elenco('docentiNomi') as $nm): ?><span class="sc-sotto"><?php echo $nm; ?></span><?php endforeach; ?>
                    <?php if ((int) $r->grezzo('docentiAltri') > 0): ?><span class="sc-sotto">e altri <?php echo (int) $r->grezzo('docentiAltri'); ?></span><?php endif; ?></td>
                <td data-etichetta="Da fare"><span class="sc-fare<?php echo $r->grezzo('daFare') ? ' da-fare' : ''; ?>"><?php echo $r->cosa; ?></span></td>
                <td><a class="btn btn-sm btn-azione fw-bold text-nowrap" href="<?php echo $r->href; ?>"><i class="fa fa-folder-open me-1" aria-hidden="true"></i>Apri scheda</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ((int) $d->pagine > 1): ?>
        <nav class="d-flex align-items-center justify-content-between gap-2 mt-3" aria-label="Pagine dell'elenco">
            <?php if ($d->hrefPrec !== ''): ?><a class="btn btn-outline-secondary" href="<?php echo $d->hrefPrec; ?>">&larr; Precedenti</a><?php else: ?><span></span><?php endif; ?>
            <span class="small text-secondary">Pagina <?php echo $d->pag; ?> di <?php echo $d->pagine; ?></span>
            <?php if ($d->hrefSucc !== ''): ?><a class="btn btn-outline-secondary" href="<?php echo $d->hrefSucc; ?>">Successive &rarr;</a><?php else: ?><span></span><?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php echo $d->parziale('pannello/_fsl_scuole_ancore', ['pagina' => 'elenco']); ?>
