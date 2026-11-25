<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: s (la scheda), campoCsrf, puoFsl, chiaveUrl, oggi, statoHtml, anteprima (record o null), erroreAvvio, docentiConEmail, periodoNuovo (dal, al), apri (modulo già aperto: modifica, nuova, rinnova)
// Convenzione e iter: da qui si fa TUTTO, senza uscire dalla scheda (le azioni sono quelle di AzioniScuolaFsl). Un solo collegamento fuori dalla scheda: «Numeri e report», nella pagina FSL.
$s = $d->s;
$csrf = $d->grezzo('campoCsrf');
$chiave = (string) $s->azione->grezzo('chiave');
$aMano = (bool) $s->grezzo('a_mano');
$anteprima = $d->grezzo('anteprima') !== null ? $d->anteprima : null;
$stati = ['valida' => ['Valida', 'ok'], 'in_scadenza' => ['In scadenza', 'attesa'], 'scaduta' => ['Scaduta', 'blocco'], 'futura' => ['Non ancora valida', 'info']];
$statiAllegato = ['da_vedere' => ['Da vedere', 'attesa'], 'accettato' => ['Accettato', 'ok'], 'rifiutato' => ['Rifiutato', 'blocco']];
$percorso = static fn (string $quale): string => ['scuola' => '', 'allegato' => '&amp;f=allegato', 'dir' => '&amp;f=dir', 'orig' => '&amp;f=orig', 'prot' => '&amp;f=prot'][$quale] ?? '';
$aperto = in_array($chiave, ['avvia', 'rinnova'], true) || $anteprima !== null || $d->erroreAvvio !== '';
$data = static fn (string $x): string => $x !== '' ? date('d/m/Y', (int) strtotime($x)) : '';
$campiDocenti = fn (array $docenti, string $titolo = 'Docenti di riferimento (Allegato A)'): string => $d->parziale('pannello/_fsl_scuole_campi_docenti', ['docenti' => $docenti, 'titolo' => $titolo]);
$aperte = $s->grezzo('iter_aperti');
$registro = $s->elenco('registro');
$statoScuola = (string) $s->grezzo('stato');
$puoRinnovare = !$aMano && in_array($statoScuola, ['in_scadenza', 'scaduta'], true);
// Il modulo con cui si registra, si modifica o si rinnova una convenzione del registro (le stesse regole e gli stessi file di fsl.php)
$modulo = function (string $suffisso, int $convId, string $dal, string $al, array $docenti, string $protocollo, string $note, bool $haConv, bool $haAll, string $pulsante) use ($d, $csrf, $campiDocenti): string {
    ob_start(); ?>
    <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end border rounded p-2 mt-2"><?php echo $csrf; ?>
        <input type="hidden" name="conv_id" value="<?php echo $convId; ?>">
        <input type="hidden" name="scuola_codice[conv]" value="<?php echo $d->s->codice; ?>">
        <div class="col-6 col-lg-3"><label class="form-label small fw-bold mb-1" for="scCvDal<?php echo $suffisso; ?>">Valida dal *</label><input type="date" name="data_stipula" id="scCvDal<?php echo $suffisso; ?>" class="form-control form-control-sm" required value="<?php echo \App\Http\DatiVista::escapa($dal); ?>"></div>
        <div class="col-6 col-lg-3"><label class="form-label small fw-bold mb-1" for="scCvAl<?php echo $suffisso; ?>">Valida fino al *</label><input type="date" name="scadenza" id="scCvAl<?php echo $suffisso; ?>" class="form-control form-control-sm" required value="<?php echo \App\Http\DatiVista::escapa($al); ?>"></div>
        <div class="col-6 col-lg-3"><label class="form-label small fw-bold mb-1" for="scCvProt<?php echo $suffisso; ?>">Protocollo</label><input type="text" name="protocollo" id="scCvProt<?php echo $suffisso; ?>" class="form-control form-control-sm" maxlength="100" value="<?php echo \App\Http\DatiVista::escapa($protocollo); ?>" placeholder="es. n. 1234 del 01/10/2026"></div>
        <div class="col-6 col-lg-3"><label class="form-label small fw-bold mb-1" for="scCvNote<?php echo $suffisso; ?>">Note</label><input type="text" name="note" id="scCvNote<?php echo $suffisso; ?>" class="form-control form-control-sm" maxlength="500" value="<?php echo \App\Http\DatiVista::escapa($note); ?>"></div>
        <div class="col-md-6"><label class="form-label small fw-bold mb-1" for="scCvFc<?php echo $suffisso; ?>">Convenzione firmata (PDF PAdES)<?php echo $haConv ? ' – carica solo per sostituirla' : ''; ?></label><input type="file" name="file_convenzione" id="scCvFc<?php echo $suffisso; ?>" class="form-control form-control-sm" accept=".pdf,application/pdf"></div>
        <div class="col-md-6"><label class="form-label small fw-bold mb-1" for="scCvFa<?php echo $suffisso; ?>">Allegato A (PDF, anche senza firma PAdES)<?php echo $haAll ? ' – carica solo per sostituirlo' : ''; ?></label><input type="file" name="file_allegato" id="scCvFa<?php echo $suffisso; ?>" class="form-control form-control-sm" accept=".pdf,application/pdf"></div>
        <div class="col-12"><?php echo $campiDocenti($docenti); ?></div>
        <div class="col-12"><button type="submit" name="conv_salva" value="1" class="btn btn-azione fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i><?php echo \App\Http\DatiVista::escapa($pulsante); ?></button></div>
    </form>
    <?php return (string) ob_get_clean();
};
$avvio = static function (\App\Http\DatiVista $d, string $csrf): string {
    ob_start(); ?>
    <form method="POST" class="d-flex flex-wrap gap-2 align-items-end">
        <?php echo $csrf; ?>
        <div><label class="form-label small fw-bold mb-1" for="scReferente">A chi va il link</label>
            <select class="form-select" id="scReferente" name="referente">
                <?php foreach ($d->elenco('docentiConEmail') as $x): ?><option value="<?php echo $x->email; ?>"><?php echo $x->nome; ?> — <?php echo $x->email; ?></option><?php endforeach; ?>
                <option value="__altra">Un'altra email…</option>
            </select></div>
        <div class="flex-grow-1 sc-campo"><label class="form-label small fw-bold mb-1" for="scAltraEmail">Se è un'altra email</label>
            <input type="email" class="form-control" id="scAltraEmail" name="altra_email" autocomplete="off" placeholder="referente@scuola.edu.it"></div>
        <button type="submit" name="avvia_anteprima" value="1" class="btn btn-azione fw-bold"><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Controlla prima di inviare</button>
    </form>
    <p class="small text-secondary mt-2 mb-0">Il link per compilare parte <strong>solo per email</strong> al referente scelto: non appare mai in questa pagina. Non vedrai nulla partire finché non confermi.</p>
    <?php return (string) ob_get_clean();
};
?>
<?php if ($d->erroreAvvio !== ''): ?><div class="alert alert-danger py-2" role="alert"><i class="fa fa-circle-exclamation me-1" aria-hidden="true"></i><?php echo $d->erroreAvvio; ?></div><?php endif; ?>

<?php if (!$aMano): ?>
<div id="avvio" class="mb-3">
    <?php if ($aperto): ?>
        <h3 class="h6 fw-bold mb-2"><?php echo $chiave === 'rinnova' ? 'Rinnova la convenzione' : ($chiave === 'avvia' ? 'Avvia la stipula' : 'Rimanda il link o aggiorna l\'Allegato A'); ?></h3>
        <?php echo $avvio($d, $csrf); ?>
    <?php elseif ($chiave === 'iter' || $chiave === 'attesa'): ?>
        <?php // la scuola ha già fatto la sua parte (ha caricato il PDF firmato): non c'è un link da rimandarle ?>
    <?php else: ?>
        <details><summary><?php echo $chiave === 'iter' || $chiave === 'sollecita' ? "Rimanda il link a un referente" : "Fai aggiornare l'Allegato A al referente (la convenzione è già valida)"; ?></summary>
            <div class="mt-2"><?php echo $avvio($d, $csrf); ?></div></details>
    <?php endif; ?>

    <?php if ($anteprima !== null): ?>
        <div class="sc-anteprima" role="status">
            <strong>Anteprima, non è partito niente.</strong>
            <?php if ($anteprima->grezzo('azione') === 'creare'): ?>
                <p class="mb-2">Si apre una compilazione nuova per <strong><?php echo $anteprima->scuola; ?></strong> e il link per compilare <?php echo $anteprima->cosa; ?> parte per email a <strong><?php echo $anteprima->email; ?></strong>. Solo chi lo apre con quell'indirizzo verificato ne diventa il referente.</p>
            <?php elseif ($anteprima->grezzo('azione') === 'riprendere'): ?>
                <p class="mb-2">C'è già una compilazione in corso per <strong><?php echo $anteprima->scuola; ?></strong>: la riprendo, non ne apro una seconda. Il link per <?php echo $anteprima->cosa; ?> riparte per email a <strong><?php echo $anteprima->email; ?></strong> (al più una volta ogni 10 minuti).</p>
            <?php else: ?>
                <p class="mb-2 text-danger">C'è già una compilazione in corso con <strong><?php echo $anteprima->referente_attuale; ?></strong>: per passarla a <?php echo $anteprima->email; ?> premi il pulsante qui sotto.</p>
                <form method="POST" class="d-flex flex-wrap gap-2 m-0"><?php echo $csrf; ?>
                    <input type="hidden" name="cv_referente_id" value="<?php echo (int) $anteprima->grezzo('compilazione_id'); ?>">
                    <input type="hidden" name="nuova_email" value="<?php echo $anteprima->email; ?>">
                    <button type="submit" name="cv_cambia_referente" value="1" class="btn btn-outline-danger fw-bold" data-conferma="Passare la compilazione n. <?php echo (int) $anteprima->grezzo('compilazione_id'); ?> a <?php echo $anteprima->email; ?>? Il vecchio link smette di funzionare e il nuovo indirizzo riceve il link nuovo." data-conferma-livello="pericolo"><i class="fa fa-right-left me-1" aria-hidden="true"></i>Passa la compilazione n. <?php echo (int) $anteprima->grezzo('compilazione_id'); ?> a <?php echo $anteprima->email; ?></button>
                    <a class="btn btn-outline-secondary" href="fsl_scuole.php?k=<?php echo $d->chiaveUrl; ?>#convenzione">Annulla</a></form>
            <?php endif; ?>
            <?php if ($anteprima->grezzo('azione') !== 'bloccata'): ?>
                <form method="POST" class="m-0"><?php echo $csrf; ?>
                    <input type="hidden" name="email" value="<?php echo $anteprima->email; ?>">
                    <?php $copia = $anteprima->copia; if ($anteprima->grezzo('azione') === 'creare' && $copia->grezzo('disponibile')): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="scCopiaDati" name="copia_dati" value="1"<?php echo $copia->grezzo('permessa') ? ' checked' : ' disabled'; ?>>
                            <label class="form-check-label" for="scCopiaDati">Parti dai dati della convenzione di prima<?php echo $copia->grezzo('dirigente') !== '' ? ' (Dirigente: ' . $copia->dirigente . ')' : ''; ?></label>
                            <?php if (!$copia->grezzo('permessa')): ?><div class="form-text">Non si può: questo indirizzo non era tra i referenti verificati della convenzione di prima, e i suoi dati non si danno a un altro indirizzo.</div><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" name="avvia_conferma" value="1" class="btn btn-azione fw-bold" data-conferma="Mandare il link a <?php echo $anteprima->email; ?>?"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Conferma e manda il link</button>
                        <a class="btn btn-outline-secondary" href="fsl_scuole.php?k=<?php echo $d->chiaveUrl; ?>#convenzione">Annulla</a></div></form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php // ───────── L'iter: una scheda per ogni compilazione in corso, la più avanti per prima ───────── ?>
<?php if ($aperte): ?>
<div class="mb-3" id="iter">
    <?php if (count($aperte) > 1): ?><div class="alert alert-warning py-2 small" role="note"><i class="fa fa-clone me-1" aria-hidden="true"></i><strong><?php echo count($aperte); ?> compilazioni in corso</strong> per questa scuola: ognuna ha il suo passo qui sotto. Se sono doppie, unisci.</div><?php endif; ?>
    <?php foreach ($aperte as $cv): ?>
        <?php echo $d->parziale('pannello/_fsl_scuole_iter', ['c' => $cv, 'campoCsrf' => $d->grezzo('campoCsrf'), 'direttore' => $s->grezzo('direttore'), 'chiaveUrl' => $d->grezzo('chiaveUrl')]); ?>
    <?php endforeach; ?>
    <?php $aperteCorte = $s->elenco('aperte'); if (count($aperteCorte) > 1): ?>
    <details class="mt-2"><summary class="fw-bold">Unisci due compilazioni</summary>
        <form method="POST" class="mt-2 d-flex flex-wrap gap-2 align-items-end"><?php echo $csrf; ?>
            <div><label class="form-label small fw-bold mb-1" for="scResta">Resta questa</label><select class="form-select" id="scResta" name="unisci_resta"><?php foreach ($aperteCorte as $x): ?><option value="<?php echo $x->id; ?>">n. <?php echo $x->id; ?> — <?php echo $x->email; ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label small fw-bold mb-1" for="scAssorbita">Si unisce a quella</label><select class="form-select" id="scAssorbita" name="unisci_assorbita"><?php foreach ($aperteCorte as $x): ?><option value="<?php echo $x->id; ?>">n. <?php echo $x->id; ?> — <?php echo $x->email; ?></option><?php endforeach; ?></select></div>
            <button type="submit" name="cv_unisci" value="1" class="btn btn-outline-danger fw-bold" data-conferma="Unire le due compilazioni? Le attività e i messaggi passano alla prima." data-conferma-livello="pericolo">Unisci</button></form></details>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php // ───────── Il registro: le convenzioni registrate. Un solo menu «Altro» con Modifica, Elimina e «Registrane una già firmata» ───────── ?>
<?php
$apri = (array) $d->grezzo('apri');   // dal vecchio indirizzo (conv_mod, conv_nuova, conv_rinnova): il modulo si apre già compilato
$apriModifica = (int) ($apri['modifica'] ?? 0);
$apriNuova = !empty($apri['nuova']);
$apriRinnova = (int) ($apri['rinnova'] ?? 0);
$altroAperto = $apriModifica > 0 || $apriNuova;
?>
<?php if ($registro || !$aMano): ?>
<div class="sc-reg-testa" id="registro">
    <h3 class="h6 fw-bold mb-0"><?php echo $registro ? 'Convenzioni registrate' : 'Nessuna convenzione nel registro'; ?></h3>
    <?php if (!$aMano): ?>
    <details class="sc-altro"<?php echo $altroAperto ? ' open' : ''; ?>><summary class="btn btn-sm btn-outline-secondary"><i class="fa fa-ellipsis me-1" aria-hidden="true"></i>Altro</summary>
        <div class="sc-altro-pannello">
            <?php foreach ($registro as $c): $cid = (int) $c->grezzo('id'); ?>
                <details<?php echo $apriModifica === $cid ? ' open' : ''; ?> id="modifica<?php echo $cid; ?>"><summary><i class="fa fa-pen me-1" aria-hidden="true"></i>Modifica la convenzione n. <?php echo $cid; ?></summary>
                    <?php echo $modulo('M' . $cid, $cid, (string) $c->grezzo('data_stipula'), (string) $c->grezzo('scadenza'), $c->grezzo('docenti'), (string) $c->grezzo('protocollo'), (string) $c->grezzo('note'), (bool) $c->grezzo('file_convenzione'), (bool) $c->grezzo('file_allegato'), 'Salva le modifiche'); ?></details>
                <details><summary class="text-danger"><i class="fa fa-trash me-1" aria-hidden="true"></i>Elimina la convenzione n. <?php echo $cid; ?> dal registro</summary>
                    <form method="POST" class="mt-2"><?php echo $csrf; ?><p class="small text-secondary mb-2">Toglie la convenzione e i suoi file dal registro. Non si può annullare.</p>
                        <button type="submit" name="conv_elimina" value="<?php echo $cid; ?>" class="btn btn-outline-danger fw-bold" data-conferma="Eliminare la convenzione n. <?php echo $cid; ?> dal registro, con i suoi file?" data-conferma-scrivi="elimina" data-conferma-livello="pericolo"><i class="fa fa-trash me-1" aria-hidden="true"></i>Elimina la convenzione n. <?php echo $cid; ?></button></form></details>
            <?php endforeach; ?>
            <details id="registra"<?php echo $apriNuova ? ' open' : ''; ?>><summary><i class="fa fa-file-signature me-1" aria-hidden="true"></i>Registrane una già firmata <span class="text-secondary fw-normal">(carica i file e le date di una convenzione che c'è già)</span></summary>
                <?php echo $modulo('N', 0, (string) $d->periodoNuovo->grezzo('dal'), (string) $d->periodoNuovo->grezzo('al'), [], '', '', false, false, 'Registra la convenzione'); ?></details>
        </div>
    </details>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php if (!$registro): ?>
    <p class="text-secondary mb-2"><?php echo $aMano ? 'Nessuna convenzione nel registro: la scuola è scritta a mano.' : 'Se la convenzione è già firmata fuori dal portale, registrala da «Altro».'; ?></p>
<?php else: ?>
    <?php foreach ($registro as $i => $c): [$testoSt, $tonoSt] = $stati[(string) $c->grezzo('stato')] ?? [(string) $c->grezzo('stato'), 'info']; $cid = (int) $c->grezzo('id'); ?>
        <div class="mb-3 pb-2 border-bottom" id="conv<?php echo $cid; ?>">
            <div class="d-flex flex-wrap align-items-center gap-2"><strong>Convenzione n. <?php echo $c->id; ?></strong> <?php echo \App\Portale\Vista\Pannello::stato($testoSt, $tonoSt); ?> <span><?php echo $c->validita; ?></span></div>
            <div class="small text-secondary">
                <?php echo $c->grezzo('protocollo') !== '' ? 'Protocollo ' . $c->protocollo . ' · ' : ''; ?>
                <?php echo $c->grezzo('registrata_da') !== '' ? 'registrata da ' . $c->registrata_da : 'registrata dall\'ufficio'; ?>
                <?php $dc = $c->elenco('docenti'); echo $dc ? ' · firmatari e docenti: ' . implode(', ', array_map(static fn ($x): string => (string) $x->nome, $dc)) : ''; ?></div>
            <?php if ($c->grezzo('note') !== ''): ?><div class="small"><?php echo $c->note; ?></div><?php endif; ?>
            <?php // I file della convenzione: «Originale» (firmato dalle due parti) e «Documento protocollato», ciascuno con la data e da dove viene. Con un file solo se ne mostra uno. ?>
            <?php foreach ($c->elenco('documenti') as $f): ?>
                <div class="sc-file"><span><i class="fa fa-file-pdf me-1" aria-hidden="true"></i><?php echo $f->voce; ?> <span class="sc-sotto d-inline"><?php echo $f->origine; ?></span></span><span><?php echo $f->grezzo('data') !== '' ? $data((string) $f->grezzo('data')) . ' · ' : ''; ?><a href="<?php echo $f->url; ?>" target="_blank" rel="noopener">Scarica</a></span></div>
            <?php endforeach; ?>
            <?php if (!$c->elenco('documenti')): ?><div class="small text-secondary"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Il PDF della convenzione non c'è: caricalo da «Altro» → «Modifica».</div><?php endif; ?>
            <?php if ($c->grezzo('file_allegato')): ?><div class="sc-file"><span><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Allegato A</span><span><?php echo $c->grezzo('data_allegato') !== '' ? $data((string) $c->grezzo('data_allegato')) . ' · ' : ''; ?><a href="convenzione_file.php?id=<?php echo $c->id; ?>&amp;f=all" target="_blank" rel="noopener">Scarica</a></span></div><?php endif; ?>
            <?php foreach ($c->elenco('integrazioni') as $a): [$testoA, $tonoA] = $statiAllegato[(string) $a->grezzo('stato')] ?? [(string) $a->grezzo('stato'), 'info']; ?>
                <div class="sc-file"><span><i class="fa fa-file-circle-plus me-1" aria-hidden="true"></i>Allegato A aggiornato (<?php echo $a->attivita; ?> att.) <?php echo \App\Portale\Vista\Pannello::stato($testoA, $tonoA); ?></span>
                    <span><?php echo $a->grezzo('caricato_il') !== '' ? $data((string) $a->grezzo('caricato_il')) : ''; ?> · <a href="convenzione_file.php?a=<?php echo $a->id; ?>" target="_blank" rel="noopener">Scarica</a></span></div>
                <?php if ($a->grezzo('nota') !== ''): ?><div class="small text-secondary">Nota: <?php echo $a->nota; ?></div><?php endif; ?>
                <?php if ($a->grezzo('stato') === 'da_vedere'): ?>
                    <form method="POST" class="d-flex flex-wrap gap-2 align-items-end my-2"><?php echo $csrf; ?>
                        <input type="hidden" name="allegato_id" value="<?php echo $a->id; ?>">
                        <div class="flex-grow-1 sc-campo"><label class="form-label small fw-bold mb-1" for="scNotaAll<?php echo $a->id; ?>">Nota (obbligatoria se rifiuti)</label><input type="text" class="form-control" id="scNotaAll<?php echo $a->id; ?>" name="nota" maxlength="500"></div>
                        <div><label class="form-label small fw-bold mb-1" for="scProtAll<?php echo $a->id; ?>">Protocollo (facoltativo)</label><input type="text" class="form-control" id="scProtAll<?php echo $a->id; ?>" name="protocollo" maxlength="100"></div>
                        <button type="submit" name="allegato_accetta" value="1" class="btn btn-azione fw-bold">Accetta</button>
                        <button type="submit" name="allegato_rifiuta" value="1" class="btn btn-outline-danger fw-bold" data-conferma="Rifiutare l'Allegato A aggiornato? La scuola riceve il motivo." data-conferma-livello="pericolo">Rifiuta</button></form>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($puoRinnovare && $i === 0): ?>
                <details<?php echo $apriRinnova === $cid ? ' open' : ''; ?> id="rinnova<?php echo $cid; ?>"><summary class="text-success fw-bold">Rinnova: registra la nuova convenzione già firmata</summary>
                    <p class="small text-secondary mt-2 mb-0">Nuova convenzione per la stessa scuola, con i docenti di riferimento della precedente: controlla le date e carica i nuovi file firmati. La convenzione scaduta resta in archivio.</p>
                    <?php echo $modulo('R' . $cid, 0, (string) $d->periodoNuovo->grezzo('dal'), (string) $d->periodoNuovo->grezzo('al'), $c->grezzo('docenti'), '', '', false, false, 'Registra il rinnovo'); ?></details>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php // ───────── Registrate dalla compilazione online: il resoconto e il file di Titulus ───────── ?>
<?php foreach ($s->elenco('registrate') as $c): $cid = (int) $c->grezzo('id'); $os = (string) $c->grezzo('originale_stato'); $haProt = in_array('prot', array_map(static fn ($x): string => (string) $x->grezzo('quale'), $c->elenco('files')), true); ?>
    <details class="mt-2" id="cvreg<?php echo $cid; ?>"><summary class="fw-bold">Compilazione n. <?php echo $cid; ?> registrata<?php echo $c->grezzo('protocollo') !== '' ? ' · protocollo ' . $c->protocollo : ''; ?></summary>
        <div class="small mt-2">
            <div>Protocollo in entrata: <?php echo $c->grezzo('protocollo_ingresso') !== '' ? $c->protocollo_ingresso : '—'; ?><?php echo $c->grezzo('protocollo_ingresso_data') !== '' ? ' del ' . $data((string) $c->grezzo('protocollo_ingresso_data')) : ''; ?>
                · definitivo: <?php echo $c->grezzo('protocollo') !== '' ? $c->protocollo : '—'; ?><?php echo $c->grezzo('protocollo_data') !== '' ? ' del ' . $data((string) $c->grezzo('protocollo_data')) : ''; ?></div>
            <?php foreach ($c->elenco('files') as $f): ?><div class="sc-file"><span><?php echo $f->nome; ?></span><span><?php echo $f->grezzo('data') !== '' ? $data((string) $f->grezzo('data')) : ''; ?> · <a href="convenzione_file.php?c=<?php echo $cid; ?><?php echo $percorso((string) $f->grezzo('quale')); ?>" target="_blank" rel="noopener">Scarica</a></span></div><?php endforeach; ?>
            <?php if ($c->grezzo('resoconto') !== null): $rg = $c->resoconto; ?>
                <div class="mt-1"><strong>Prenotazioni:</strong> <?php echo (int) $rg->grezzo('coperte'); ?> coperte dalla convenzione, <?php echo (int) $rg->grezzo('approvate'); ?> approvate<?php echo count($rg->elenco('restano')) ? ', ' . count($rg->elenco('restano')) . ' restano come sono' : ''; ?><?php echo (int) $rg->grezzo('senza_codice') > 0 ? '; ' . (int) $rg->grezzo('senza_codice') . " con la scuola scritta a mano (da abbinare all'anagrafe, non si possono coprire da sole)" : ''; ?>.
                    <?php if ($rg->grezzo('errore') !== ''): ?><span class="text-danger fw-bold"><?php echo $rg->errore; ?></span><?php endif; ?>
                    <?php if (count($rg->elenco('restano'))): ?><ul class="mb-1 ps-3"><?php foreach ($rg->elenco('restano') as $x): ?><li><?php echo $x->codice; ?>: <?php echo $x->motivo; ?></li><?php endforeach; ?></ul><?php endif; ?></div>
            <?php endif; ?>
            <div class="mt-1"><strong>File di Titulus:</strong>
                <?php if ($os === 'ok'): ?><span class="badge bg-success">originale ripreso da Titulus</span> (senza stampigliatura, impronta controllata)
                <?php elseif ($os === 'impronta'): ?><span class="badge bg-danger">impronta diversa</span> <?php echo $c->originale_avviso; ?> Si tiene il file del portale.
                <?php elseif ($os === 'mancante'): ?><span class="badge bg-warning text-dark">non arrivato</span> <?php echo $c->originale_avviso; ?> Per la scuola vale <?php echo $haProt ? 'il documento che hai caricato' : 'la copia del portale (stampigliata «per consultazione»)'; ?>.<?php echo (int) $c->grezzo('originale_tentativi') < 8 ? ' Il portale riprova da solo una volta al giorno.' : ' I tentativi automatici sono finiti.'; ?>
                <?php else: ?><span class="text-secondary">non ripreso (protocollo scritto a mano o registrata prima di questa funzione)</span><?php endif; ?></div>
            <form method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center mt-1"><?php echo $csrf; ?>
                <button type="submit" name="cv_riprova_originale" value="<?php echo $cid; ?>" class="btn btn-sm btn-outline-secondary py-0"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Riprova da Titulus</button>
                <input type="file" name="protocollato" class="form-control form-control-sm w-auto" accept=".pdf,application/pdf" aria-label="Documento protocollato (PDF)">
                <button type="submit" name="cv_carica_protocollato" value="<?php echo $cid; ?>" class="btn btn-sm btn-outline-primary py-0"><i class="fa fa-upload me-1" aria-hidden="true"></i>Carica il documento protocollato</button>
                <?php if ((int) $c->grezzo('convenzione_id') > 0): ?><button type="submit" name="cv_ripeti_applicazione" value="<?php echo $cid; ?>" class="btn btn-sm btn-outline-secondary py-0" data-conferma="Ripetere l'approvazione delle prenotazioni coperte dalla convenzione? Chi è già coperto non riceve altre email."><i class="fa fa-check-double me-1" aria-hidden="true"></i>Ripeti l'approvazione</button><?php endif; ?></form>
        </div></details>
<?php endforeach; ?>

<?php $unite = array_filter($s->elenco('storico'), static fn ($c): bool => $c->grezzo('stato_iter') !== 'protocollata'); if ($unite): ?>
<details class="mt-2"><summary>Altre compilazioni concluse (<?php echo count($unite); ?>)</summary>
    <?php foreach ($unite as $c): ?>
        <div class="mt-2 small"><strong>Compilazione n. <?php echo $c->id; ?></strong> · <?php echo $c->passo; ?><?php echo (int) $c->unita_in > 0 ? ' · unita alla n. ' . $c->unita_in : ''; ?>
            <?php foreach ($c->elenco('files') as $f): ?><div class="sc-file"><span><?php echo $f->nome; ?></span><span><?php echo $f->grezzo('data') !== '' ? $data((string) $f->grezzo('data')) : ''; ?> · <a href="convenzione_file.php?c=<?php echo $c->id; ?><?php echo $percorso((string) $f->grezzo('quale')); ?>" target="_blank" rel="noopener">Scarica</a></span></div><?php endforeach; ?></div>
    <?php endforeach; ?>
</details>
<?php endif; ?>
