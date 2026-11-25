<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: s (la scheda), campoCsrf, puoFsl, chiaveUrl, oggi
$s = $d->s;
$csrf = $d->grezzo('campoCsrf');
$messaggi = $s->elenco('messaggi');
$compilazioni = $s->elenco('compilazioni_con_messaggi');
$daLeggere = [];
foreach ((array) $s->grezzo('messaggi') as $m) {
    if (!empty($m['non_letto'])) {
        $daLeggere[(int) $m['compilazione']] = (int) $m['compilazione'];
    }
}
?>
<?php foreach ($daLeggere as $idc): ?>
    <form method="POST" class="mb-2"><?php echo $csrf; ?><button type="submit" name="cv_letto" value="<?php echo $idc; ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-check-double me-1" aria-hidden="true"></i>Segna come letti i messaggi della compilazione n. <?php echo $idc; ?></button></form>
<?php endforeach; ?>
<?php foreach ($messaggi as $m): $daScuola = $m->grezzo('da') === \App\Fsl\MessaggiConvenzioneRepository::SCUOLA; ?>
    <div class="sc-messaggio<?php echo $daScuola ? ' scuola' : ''; ?><?php echo $m->grezzo('non_letto') ? ' nuovo' : ''; ?>">
        <span class="sc-sotto"><?php echo $daScuola ? 'Dalla scuola' : "Dall'ufficio" .($m->grezzo('autore') !== '' ? ' (' . $m->autore . ')' : ''); ?> · <?php echo date('d/m/Y H:i', (int) strtotime((string) $m->grezzo('creato_il'))); ?> · compilazione n. <?php echo $m->compilazione; ?><?php echo $m->grezzo('non_letto') ? ' · da leggere' : ''; ?></span>
        <?php echo nl2br($m->testo); ?>
    </div>
<?php endforeach; ?>
<?php if (!$messaggi): ?><p class="text-secondary">Nessun messaggio ancora.</p><?php endif; ?>

<?php // «Scrivi alla scuola» si può sempre: compilazione in corso → ultima conclusa → docenti scelti. Chi scrive vede a chi arriva. ?>
<?php $scrivi = $s->scrivi; $modo = (string) $scrivi->grezzo('modo'); $dest = $scrivi->elenco('destinatari'); ?>
<?php if ($modo === 'nessuno'): ?>
    <p class="text-secondary mb-0 mt-2">Non c'è nessun indirizzo a cui scrivere: nessuna compilazione e nessun docente che abbia prenotato con un'email valida. Con «Avvia la stipula» puoi mandare il link a un referente: poi potrai scrivergli da qui.</p>
<?php elseif ($modo === 'compilazione'): ?>
    <form method="POST" class="mt-2"><?php echo $csrf; ?>
        <?php if (count($dest) > 1): ?>
            <label class="form-label small fw-bold mb-1" for="scMsgCompilazione">A chi scrivi (compilazione in corso)</label>
            <select class="form-select mb-2" id="scMsgCompilazione" name="cv_messaggio_scelta"><?php foreach ($dest as $c): ?><option value="<?php echo $c->id; ?>"<?php echo (int) $c->id === (int) $scrivi->grezzo('predefinita') ? ' selected' : ''; ?>><?php echo $c->email; ?> — compilazione n. <?php echo $c->id; ?> (<?php echo $c->passo; ?>)</option><?php endforeach; ?></select>
        <?php else: $c = $dest[0]; ?>
            <p class="small mb-2"><i class="fa fa-envelope me-1" aria-hidden="true"></i>Arriva a <strong><?php echo $c->email; ?></strong>, il referente della compilazione n. <?php echo $c->id; ?> (<?php echo $c->passo; ?>), con il link personale per rispondere.</p>
        <?php endif; ?>
        <label class="form-label small fw-bold mb-1" for="scMsgTesto">Scrivi alla scuola</label>
        <textarea class="form-control mb-2" id="scMsgTesto" name="testo" rows="3" maxlength="1500" required></textarea>
        <button type="submit" name="cv_messaggio" value="<?php echo (int) $scrivi->grezzo('predefinita'); ?>" class="btn btn-azione fw-bold"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Manda il messaggio</button>
    </form>
<?php else: ?>
    <form method="POST" class="mt-2"><?php echo $csrf; ?>
        <p class="small mb-1">Non c'è una compilazione a cui scrivere: scegli i docenti che hanno prenotato. Il messaggio arriva per email a chi selezioni, senza il link della convenzione.</p>
        <fieldset class="mb-2"><legend class="form-label small fw-bold mb-1">A chi scrivi</legend>
            <?php foreach ($dest as $i => $x): ?>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="destinatari[]" value="<?php echo $x->prenotazione; ?>" id="scDest<?php echo $i; ?>" checked><label class="form-check-label" for="scDest<?php echo $i; ?>"><?php echo $x->nome; ?> <span class="sc-sotto d-inline"><?php echo $x->email; ?></span></label></div>
            <?php endforeach; ?>
        </fieldset>
        <label class="form-label small fw-bold mb-1" for="scMsgTesto">Il messaggio</label>
        <textarea class="form-control mb-2" id="scMsgTesto" name="testo" rows="3" maxlength="1500" required></textarea>
        <button type="submit" name="cv_scrivi_docenti" value="1" class="btn btn-azione fw-bold" data-conferma="Mandare il messaggio ai docenti scelti?"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Manda il messaggio</button>
    </form>
<?php endif; ?>
