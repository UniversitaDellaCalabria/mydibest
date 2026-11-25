<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: c (una compilazione in corso, da IterNellaScheda), campoCsrf, direttore (nome, email), chiaveUrl, tutte (le compilazioni in corso, per «Unisci»)
// TUTTO l'iter di una convenzione compilata online, dalla scheda della scuola e senza uscirne: controllo e presa in carico, protocollo in entrata, firma del
// Direttore, protocollo del firmato e registrazione. Sono le stesse azioni (AzioniIter) e gli stessi campi della vecchia pagina dell'iter di fsl.php.
$c = $d->c;
$csrf = $d->grezzo('campoCsrf');
$id = (int) $c->grezzo('id');
$st = (string) $c->grezzo('stato_iter');
$direttore = $d->direttore;
$senzaDirettore = $d->direttore->grezzo('email') === '';
$titulus = $c->titulus;
$statoTitulus = (string) $c->titulus->grezzo('stato');
$bloccoTitulus = in_array($statoTitulus, ['incerto', 'in_approvazione', 'in_invio'], true);
$pronto = (bool) $c->grezzo('titulus_pronto') && !$bloccoTitulus;
$oggiIso = date('Y-m-d');
$data = static fn (string $x): string => $x !== '' ? date('d/m/Y', (int) strtotime($x)) : '';
$dataOra = static fn (string $x): string => $x !== '' ? date('d/m/Y H:i', (int) strtotime($x)) : '';
$file = static fn (string $f, string $testo, string $classe = 'btn-outline-danger') => '<a class="btn btn-sm ' . $classe . '" href="convenzione_file.php?c=' . $id . ($f !== '' ? '&amp;f=' . $f : '') . '" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>' . $testo . '</a>';
$percorso = static fn (string $quale): string => ['scuola' => '', 'allegato' => '&amp;f=allegato', 'dir' => '&amp;f=dir', 'orig' => '&amp;f=orig', 'prot' => '&amp;f=prot'][$quale] ?? '';
$haAllegato = false;
foreach ($c->elenco('files') as $f) {
    $haAllegato = $haAllegato || $f->grezzo('quale') === 'allegato';
}
$campoAllegato = static function (string $idCampo) use ($id, $haAllegato): string {
    ob_start(); ?>
    <label for="<?php echo $idCampo; ?>" class="form-label small mb-0">Allegato A (PDF anche senza firma, fino a <?php echo (int) (\App\Fsl\Costanti::MAX_FIRMATA / 1048576); ?> MB)<?php echo $haAllegato ? ' – carica solo per sostituirlo' : ''; ?></label>
    <input type="file" name="allegato_a" id="<?php echo $idCampo; ?>" class="form-control form-control-sm" accept=".pdf,application/pdf">
    <div class="form-text"><?php if ($haAllegato): ?>Già caricato dalla scuola: <a href="convenzione_file.php?c=<?php echo $id; ?>&amp;f=allegato" target="_blank" rel="noopener">vedi</a><?php else: ?>La scuola non l'ha caricato.<?php endif; ?></div>
    <?php return (string) ob_get_clean();
};
$campiDocenti = fn (string $titolo = 'Docenti di riferimento (Allegato A)'): string => $d->parziale('pannello/_fsl_scuole_campi_docenti', ['docenti' => $c->grezzo('docenti_iter'), 'titolo' => $titolo]);
$avvisoTitulus = fn (string $fase): string => $d->parziale('pannello/_fsl_scuole_titulus', ['stato' => $statoTitulus, 'numero' => $titulus->grezzo('numero'), 'il' => $titulus->grezzo('il'), 'id' => $id, 'fase' => $fase, 'campoCsrf' => $csrf]);
$attivitaDaConfermare = $c->elenco('da_confermare');
$formDocenti = '';
?>
<article class="sc-iter" id="cv<?php echo $id; ?>" aria-labelledby="scIter<?php echo $id; ?>">
    <header class="sc-iter-testa">
        <h3 class="h6 fw-bold mb-1" id="scIter<?php echo $id; ?>"><?php echo $c->passo; ?> <small class="text-secondary fw-normal">compilazione n. <?php echo $id; ?></small></h3>
        <p class="small mb-1"><strong><?php echo $c->grezzo('scuola_nome') !== '' ? $c->scuola_nome : 'La scuola'; ?></strong>
            <?php echo $c->grezzo('dirigente') !== '' ? ' · Dirigente ' . $c->dirigente : ''; ?>
            · Referente: <strong><?php echo $c->grezzo('email') !== '' ? $c->email : 'non ancora scelto'; ?></strong>
            <?php if ($c->grezzo('avviata_il') !== ''): ?> · avviata il <?php echo $data((string) $c->grezzo('avviata_il')); ?><?php echo $c->grezzo('avviata_da') !== '' ? ' da ' . $c->avviata_da : ''; ?><?php endif; ?>
            <?php if ($c->grezzo('aggiornata_il') !== ''): ?> · compilata il <?php echo $data((string) $c->grezzo('aggiornata_il')); ?><?php endif; ?>
            · <?php echo (int) $c->grezzo('attivita'); ?> <?php echo (int) $c->grezzo('attivita') === 1 ? 'attività' : 'attività'; ?> nell'Allegato A</p>
        <?php echo $c->grezzo('passi_html'); ?>
    </header>

    <?php foreach ($c->elenco('files') as $f): ?>
        <div class="sc-file"><span><i class="fa fa-file-pdf me-1" aria-hidden="true"></i><?php echo $f->nome; ?></span><span><?php echo $f->grezzo('data') !== '' ? $data((string) $f->grezzo('data')) : ''; ?> · <a href="convenzione_file.php?c=<?php echo $id; ?><?php echo $percorso((string) $f->grezzo('quale')); ?>" target="_blank" rel="noopener">Scarica</a></span></div>
    <?php endforeach; ?>

    <?php if ((int) $c->grezzo('dopo_firma') > 0): ?>
        <div class="alert alert-warning py-1 px-2 mt-2 mb-0 small" role="note"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><strong><?php echo (int) $c->grezzo('dopo_firma'); ?> <?php echo (int) $c->grezzo('dopo_firma') === 1 ? 'attività aggiunta dopo la firma' : 'attività aggiunte dopo la firma'; ?> della scuola</strong>: non sono nel PDF firmato. Controlla se la scuola deve ricaricare un Allegato A aggiornato.</div>
    <?php endif; ?>
    <?php foreach ($attivitaDaConfermare as $x): ?>
        <form method="POST" class="alert alert-warning py-2 px-3 mt-2 mb-0 small d-flex flex-wrap gap-2 align-items-center"><?php echo $csrf; ?>
            <span><i class="fa fa-user-clock me-1" aria-hidden="true"></i><strong>Attività da confermare</strong>: segnalata da un'altra email (prenotazione n. <?php echo $x->pr; ?><?php echo $x->grezzo('tutor') !== '' ? ', tutor ' . $x->tutor : ''; ?>). Resta fuori dai documenti finché non la confermi.</span>
            <button type="submit" name="att_conferma" value="<?php echo $id; ?>:<?php echo $x->pr; ?>" class="btn btn-sm btn-outline-success py-0">Conferma</button>
            <button type="submit" name="att_scarta" value="<?php echo $id; ?>:<?php echo $x->pr; ?>" class="btn btn-sm btn-outline-secondary py-0" data-conferma="Scartare questa attività segnalata?">Scarta</button></form>
    <?php endforeach; ?>

<?php // ───────── 1 · La scuola non ha ancora caricato il PDF (avviata, compilata o file rifiutato) ───────── ?>
<?php if (!in_array($st, ['caricata_scuola', 'in_carico', 'da_firmare', 'firmata_direttore'], true)): ?>
    <?php if ($st === 'rifiutata'): ?><p class="small mt-2 mb-1"><span class="badge bg-secondary">Rifiutato da te: attende un nuovo file</span> <?php echo $c->nota_ufficio; ?></p><?php endif; ?>
    <p class="small text-secondary mt-2 mb-2"><?php echo $c->grezzo('compilata') ? 'La scuola ha compilato online e deve caricare il PDF firmato.' : 'Il link per compilare è partito per email al referente: finché non compila non c\'è altro da fare.'; ?></p>
    <div class="sc-azioni">
        <?php if ($c->grezzo('compilata')): ?>
            <form method="POST"><?php echo $csrf; ?><button type="submit" name="cv_ricorda" value="<?php echo $id; ?>" class="btn btn-outline-secondary fw-bold" data-conferma="Mandare alla scuola il promemoria per caricare il PDF firmato?"><i class="fa fa-bell me-1" aria-hidden="true"></i>Ricorda il PDF firmato</button></form>
        <?php endif; ?>
        <span class="small text-secondary">Già firmata fuori dal portale? Registrala da «Altro», qui sotto.</span>
    </div>
<?php endif; ?>

<?php // ───────── 2 · Caricata dalla scuola: controllo e presa in carico ───────── ?>
<?php if ($st === 'caricata_scuola'): ?>
    <h4 class="h6 fw-bold mt-3 mb-1"><i class="fa fa-clipboard-check me-1" aria-hidden="true"></i>Controlla la convenzione</h4>
    <?php echo $avvisoTitulus('ingresso'); ?>
    <form method="POST" enctype="multipart/form-data" class="iter-controlla" id="controlla<?php echo $id; ?>"><?php echo $csrf; ?>
        <ol class="iter-azioni">
            <li><div><strong>PDF firmato dalla scuola</strong>
                <div class="mt-1 d-flex gap-2 flex-wrap"><?php echo $file('', 'PDF della convenzione firmata'); ?><?php echo $haAllegato ? $file('allegato', 'Allegato A', 'btn-outline-secondary') : ''; ?></div>
                <div class="text-secondary small mt-1">Firmato da <?php echo $c->grezzo('firmatario') !== '' ? $c->firmatario : 'la scuola'; ?><?php echo $c->grezzo('firmata_il') !== '' ? ' il ' . $data((string) $c->grezzo('firmata_il')) : ''; ?>.</div>
                <?php if ($c->grezzo('avviso_firma_ingresso') !== ''): ?><div class="alert alert-warning py-1 px-2 mt-1 mb-0 small" role="note"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><strong>Da controllare:</strong> <?php echo $c->avviso_firma_ingresso; ?> <span class="text-secondary">Con un avviso il protocollo automatico non parte: lo decidi tu.</span></div>
                <?php else: ?><div class="small text-success mt-1"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>La firma non ha dato avvisi.</div><?php endif; ?></div></li>
            <li><div><strong>Allegato A</strong>: <?php echo (int) $c->grezzo('attivita'); ?> attività.
                <div class="mt-1 sc-campo"><?php echo $campoAllegato('scAt' . $id); ?></div></div></li>
            <li><div><strong>Per quanto vale la convenzione e chi sono i docenti</strong> <span class="text-secondary small">(si salvano sulla convenzione e li usa anche il protocollo automatico dopo la firma)</span>
                <div class="row g-2 mt-1">
                    <div class="col-sm-3"><label class="form-label small mb-0" for="scVd<?php echo $id; ?>">Valida dal</label><input id="scVd<?php echo $id; ?>" type="date" name="data_stipula" class="form-control form-control-sm" value="<?php echo $c->valida_dal; ?>"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0" for="scVa<?php echo $id; ?>">Valida fino al</label><input id="scVa<?php echo $id; ?>" type="date" name="scadenza" class="form-control form-control-sm" value="<?php echo $c->valida_al; ?>"></div>
                    <div class="col-12"><?php echo $campiDocenti(); ?></div>
                    <div class="col-12"><button type="submit" name="cv_controllo" value="<?php echo $id; ?>" formnovalidate class="btn btn-sm btn-outline-secondary"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva date e docenti</button></div></div></div></li>
            <li><div><strong>Che cosa fai</strong>
                <?php if ($pronto): ?><div class="d-flex flex-wrap gap-2 mt-1"><button type="submit" name="cv_titulus" value="<?php echo $id; ?>" formnovalidate class="btn btn-azione fw-bold" data-conferma="Mandare la convenzione a Titulus? Il protocollo si assegna subito e non si annulla da qui. Poi parte l'invio al Direttore." data-conferma-livello="pericolo"><i class="fa fa-stamp me-1" aria-hidden="true"></i>Invia al protocollo</button></div><?php endif; ?>
                <div class="row g-2 mt-1 align-items-end">
                    <div class="col-sm-4"><label for="scProtIn<?php echo $id; ?>" class="form-label small mb-0">N. protocollo in entrata (a mano)</label><input id="scProtIn<?php echo $id; ?>" name="prot_ingresso" class="form-control form-control-sm" maxlength="80" placeholder="es. 1234"></div>
                    <div class="col-sm-3"><label for="scProtInData<?php echo $id; ?>" class="form-label small mb-0">Data</label><input id="scProtInData<?php echo $id; ?>" type="date" name="prot_ingresso_data" class="form-control form-control-sm" value="<?php echo $oggiIso; ?>"></div>
                    <div class="col-sm-5"><button type="submit" name="cv_prendi" value="<?php echo $id; ?>" formnovalidate class="btn btn-outline-danger fw-bold w-100"><i class="fa fa-pen me-1" aria-hidden="true"></i>Registra il numero a mano</button></div></div>
                <div class="form-text"><?php echo $pronto ? '«Invia al protocollo» prende il numero da Titulus; «Registra il numero a mano» è per chi ha già protocollato nel sistema di Ateneo.' : 'Protocolla la convenzione nel sistema di protocollo di Ateneo, poi scrivi qui numero e data.'; ?></div></div></li>
        </ol>
    </form>
    <div class="iter-esito small"><i class="fa fa-bolt me-1" aria-hidden="true"></i>Appena il protocollo in entrata è registrato: la scuola riceve l'avviso e <strong><?php echo $senzaDirettore ? 'il Direttore' : $direttore->nome; ?></strong> riceve da solo l'email con il link per firmare.</div>
    <details class="mt-2"><summary class="small fw-bold text-danger">Rimanda alla scuola: qualcosa non va, con un motivo</summary>
        <form method="POST" class="mt-1 sc-campo"><?php echo $csrf; ?>
            <label class="form-label small fw-bold" for="scMotivo<?php echo $id; ?>">Perché il file non va (la scuola lo riceve per email)</label>
            <textarea name="motivo" id="scMotivo<?php echo $id; ?>" class="form-control form-control-sm mb-1" rows="2" maxlength="500" required placeholder="Es. firma mancante, dati del Dirigente errati…"></textarea>
            <button type="submit" name="cv_rifiuta" value="<?php echo $id; ?>" class="btn btn-sm btn-outline-danger" data-conferma="Rifiutare il file e avvisare la scuola?" data-conferma-livello="pericolo">Rimanda alla scuola e avvisala</button></form></details>
<?php endif; ?>

<?php // ───────── 3 · Dal Direttore per la firma ───────── ?>
<?php if (in_array($st, ['in_carico', 'da_firmare'], true)): $inviata = $st === 'da_firmare'; ?>
    <div class="border rounded p-2 my-2 small<?php echo $senzaDirettore ? ' sc-manca-box' : ''; ?>" id="direttore<?php echo $id; ?>">
        <i class="fa fa-user-tie me-1" aria-hidden="true"></i><strong>Firma ora:</strong>
        <?php if ($senzaDirettore): ?><span class="text-danger fw-bold">nessun Direttore indicato</span> · si sceglie in Persone e permessi → Uffici e persone → Direzione.
        <?php else: ?><span class="fw-bold"><?php echo $direttore->nome; ?></span> · <?php echo $direttore->email; ?><?php endif; ?></div>
    <p class="mb-2 small">
        <?php if ($inviata): ?><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>Il link per firmare è stato mandato a <strong><?php echo $senzaDirettore ? 'il Direttore' : $direttore->nome; ?></strong><?php echo $c->grezzo('da_firmare_il') !== '' ? ' il ' . $dataOra((string) $c->grezzo('da_firmare_il')) : ''; ?>. Quando firma, il PDF torna qui e la convenzione passa al protocollo definitivo.
        <?php else: ?><i class="fa fa-triangle-exclamation me-1 text-danger" aria-hidden="true"></i>Protocollo in entrata registrato (<?php echo $c->grezzo('protocollo_ingresso') !== '' ? $c->protocollo_ingresso : '—'; ?>), ma <strong>l'email al Direttore non è partita</strong>: controlla che sia indicato qui sopra e premi il pulsante.<?php endif; ?></p>
    <div class="sc-azioni">
        <form method="POST" class="m-0"><?php echo $csrf; ?><button type="submit" name="cv_invia_direttore" value="<?php echo $id; ?>" class="btn <?php echo $inviata ? 'btn-outline-secondary' : 'btn-azione fw-bold'; ?>" data-conferma="Mandare al Direttore il link per firmare?"><i class="fa fa-file-signature me-1" aria-hidden="true"></i><?php echo $inviata ? 'Rimanda il link al Direttore' : 'Manda il link al Direttore per la firma'; ?></button></form>
    </div>
    <details class="mt-2"><summary class="small fw-bold">Il Direttore ha firmato fuori dal portale? Carica tu il PDF firmato</summary>
        <form method="POST" enctype="multipart/form-data" class="row g-2 mt-1"><?php echo $csrf; ?>
            <div class="col-12"><label for="scFe<?php echo $id; ?>" class="form-label small fw-bold mb-0">PDF firmato dal Direttore in PAdES</label><input type="file" name="file_definitivo" id="scFe<?php echo $id; ?>" class="form-control form-control-sm" accept=".pdf,application/pdf" required></div>
            <div class="col-sm-3"><label for="scEp<?php echo $id; ?>" class="form-label small mb-0">N. protocollo</label><input id="scEp<?php echo $id; ?>" name="protocollo" class="form-control form-control-sm" maxlength="80" required></div>
            <div class="col-sm-3"><label for="scEpd<?php echo $id; ?>" class="form-label small mb-0">Data protocollo</label><input id="scEpd<?php echo $id; ?>" type="date" name="protocollo_data" class="form-control form-control-sm" value="<?php echo $oggiIso; ?>"></div>
            <div class="col-sm-3"><label for="scEd<?php echo $id; ?>" class="form-label small mb-0">Valida dal</label><input id="scEd<?php echo $id; ?>" type="date" name="data_stipula" class="form-control form-control-sm" required value="<?php echo $c->valida_dal; ?>"></div>
            <div class="col-sm-3"><label for="scEa<?php echo $id; ?>" class="form-label small mb-0">Valida fino al</label><input id="scEa<?php echo $id; ?>" type="date" name="scadenza" class="form-control form-control-sm" required value="<?php echo $c->valida_al; ?>"></div>
            <div class="col-12 sc-campo"><?php echo $campoAllegato('scAe' . $id); ?></div>
            <div class="col-12"><?php echo $campiDocenti(); ?></div>
            <div class="col-12"><button type="submit" name="cv_completa" value="<?php echo $id; ?>" class="btn btn-sm btn-azione fw-bold" data-conferma="Registrare la convenzione, confermare le prenotazioni in attesa della scuola e avvisarla?">Registra e conferma le prenotazioni</button></div></form></details>
<?php endif; ?>

<?php // ───────── 4 · Firmata dal Direttore: protocollo definitivo e registrazione ───────── ?>
<?php if ($st === 'firmata_direttore'): ?>
    <h4 class="h6 fw-bold mt-3 mb-1"><i class="fa fa-stamp me-1" aria-hidden="true"></i>Protocolla e registra</h4>
    <?php echo $avvisoTitulus('definitivo'); ?>
    <?php if ($c->grezzo('protocollo_errore') !== ''): ?><div class="alert alert-danger py-2 px-3 small mb-2" role="alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo $c->protocollo_errore; ?> <span class="text-secondary">La firma del Direttore è salvata: decidi tu con «Invia al protocollo» o con il numero a mano.</span></div><?php endif; ?>
    <ol class="iter-azioni">
        <li><div><strong>Scarica il PDF firmato dal Direttore</strong>
            <div class="mt-1"><?php echo $file('dir', 'PDF firmato dal Direttore', 'btn-outline-success'); ?></div>
            <div class="text-secondary small mt-1">Firmato da <?php echo $c->grezzo('firmatario_direttore') !== '' ? $c->firmatario_direttore : 'il Direttore'; ?><?php echo $c->grezzo('firmata_direttore_il') !== '' ? ' il ' . $data((string) $c->grezzo('firmata_direttore_il')) : ''; ?>. Protocollo in entrata: <?php echo $c->grezzo('protocollo_ingresso') !== '' ? $c->protocollo_ingresso : '—'; ?>.</div>
            <?php if ($c->grezzo('avviso_firma') !== ''): ?><div class="alert alert-warning py-1 px-2 mt-1 mb-0 small" role="note"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><strong>Da controllare:</strong> <?php echo $c->avviso_firma; ?></div><?php endif; ?></div></li>
        <li><div><strong>Protocollalo</strong> in via definitiva nel sistema di Ateneo, poi scrivi qui il protocollo e per quanto vale la convenzione.
            <form method="POST" enctype="multipart/form-data" class="row g-2 mt-1"><?php echo $csrf; ?>
                <div class="col-sm-3"><label for="scPr<?php echo $id; ?>" class="form-label small mb-0">N. protocollo</label><input id="scPr<?php echo $id; ?>" name="protocollo" class="form-control form-control-sm" maxlength="80" required placeholder="es. 1234"></div>
                <div class="col-sm-3"><label for="scPrd<?php echo $id; ?>" class="form-label small mb-0">Data protocollo</label><input id="scPrd<?php echo $id; ?>" type="date" name="protocollo_data" class="form-control form-control-sm" value="<?php echo $oggiIso; ?>"></div>
                <div class="col-sm-3"><label for="scPd<?php echo $id; ?>" class="form-label small mb-0">Valida dal</label><input id="scPd<?php echo $id; ?>" type="date" name="data_stipula" class="form-control form-control-sm" required value="<?php echo $c->valida_dal; ?>"></div>
                <div class="col-sm-3"><label for="scPa<?php echo $id; ?>" class="form-label small mb-0">Valida fino al</label><input id="scPa<?php echo $id; ?>" type="date" name="scadenza" class="form-control form-control-sm" required value="<?php echo $c->valida_al; ?>"></div>
                <div class="col-12 sc-campo"><label for="scFd<?php echo $id; ?>" class="form-label small mb-0">Documento protocollato: PDF con la segnatura (facoltativo)</label><input type="file" name="file_definitivo" id="scFd<?php echo $id; ?>" class="form-control form-control-sm" accept=".pdf,application/pdf">
                    <div class="form-text">Se lo carichi, nel registro e per la scuola c'è questo file (vale più della copia «per consultazione» stampigliata dal portale). Il PDF firmato dal Direttore non si modifica mai e resta scaricabile qui.<?php echo $c->grezzo('ha_protocollato') ? ' <strong>Ne hai già caricato uno: verrà usato questo.</strong>' : ''; ?></div></div>
                <div class="col-12 sc-campo"><?php echo $campoAllegato('scAf' . $id); ?></div>
                <div class="col-12"><?php echo $campiDocenti(); ?></div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <?php if ($pronto): ?><button type="submit" name="cv_titulus_def" value="<?php echo $id; ?>" formnovalidate class="btn btn-azione fw-bold" data-conferma="Mandare la convenzione firmata a Titulus? Il protocollo si assegna subito e non si annulla da qui. Poi la convenzione si registra con le date indicate." data-conferma-livello="pericolo"><i class="fa fa-stamp me-1" aria-hidden="true"></i>Invia al protocollo e registra</button><?php endif; ?>
                    <button type="submit" name="cv_completa" value="<?php echo $id; ?>" class="btn btn-outline-success fw-bold" data-conferma="Registrare la convenzione con il numero scritto qui, confermare le prenotazioni in attesa della scuola e avvisarla?"><i class="fa fa-pen me-1" aria-hidden="true"></i>Registra il numero a mano</button>
                    <?php if ($pronto): ?><span class="small text-secondary align-self-center">Con «Invia al protocollo» non serve scrivere numero e data: indica solo le date di validità.</span><?php endif; ?></div></form></div></li>
    </ol>
    <div class="iter-esito small"><i class="fa fa-bolt me-1" aria-hidden="true"></i>Alla registrazione la convenzione entra <strong>da sola</strong> nel registro, le prenotazioni in attesa della scuola vengono confermate e le persone avvisate per email.</div>
    <?php if ((int) $c->grezzo('convenzione_id') > 0): ?>
        <form method="POST" class="mt-2"><?php echo $csrf; ?><button type="submit" name="cv_ripeti_applicazione" value="<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary" data-conferma="Ripetere l'approvazione delle prenotazioni coperte? Chi è già coperto non riceve altre email."><i class="fa fa-check-double me-1" aria-hidden="true"></i>Ripeti l'approvazione</button></form>
    <?php endif; ?>
<?php endif; ?>

<?php // ───────── Cambiare referente (ogni passo) ───────── ?>
    <details class="mt-2"><summary class="small fw-bold">Cambia referente</summary>
        <form method="POST" class="mt-2 d-flex flex-wrap gap-2 align-items-end"><?php echo $csrf; ?>
            <input type="hidden" name="cv_referente_id" value="<?php echo $id; ?>">
            <div class="flex-grow-1 sc-campo"><label class="form-label small fw-bold mb-1" for="scNuovaEmail<?php echo $id; ?>">Nuovo indirizzo del referente</label>
                <input type="email" class="form-control" id="scNuovaEmail<?php echo $id; ?>" name="nuova_email" required autocomplete="off"></div>
            <button type="submit" name="cv_cambia_referente" value="1" class="btn btn-outline-danger fw-bold" data-conferma="Cambiare il referente? Il vecchio link smette di funzionare e il nuovo indirizzo riceve il link nuovo." data-conferma-livello="pericolo">Cambia referente</button></form></details>
</article>
