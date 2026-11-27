<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Pagina di chi approva. Dati: quale (elenco | richiesta | mancante), avvisoHtml e temaHtml (HTML già preparato), e per ogni «quale»: vedi la classe PaginaApprovaPubblicazioni ?>
<?php echo $d->grezzo('temaHtml'); ?>
<div class="container my-4 ui-maxw-960" id="approva-pubblicazioni">
    <?php echo $d->grezzo('avvisoHtml'); ?>
<?php if ((string) $d->grezzo('quale') === 'elenco'): ?>
    <?php $da = $d->elenco('da'); $decise = $d->elenco('decise'); ?>
    <header class="ui-pagina-testa">
        <a href="area_personale.php" class="ui-indietro"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Area personale</a>
        <h1>Richieste di pubblicazione da approvare</h1>
        <p>Basta che una sola delle persone che approvano decida: chi arriva dopo vede chi ha deciso e quando.</p>
    </header>

    <h2 class="ui-sezione mb-2">Da approvare (<?php echo count($da); ?>)</h2>
    <?php if (!$da): ?>
        <div class="ap-vuoto"><i class="fa fa-circle-check" aria-hidden="true"></i><div><strong>Non c'è niente da approvare.</strong><br>Quando arriva una richiesta nuova ricevi una email.</div></div>
    <?php endif; ?>
    <div class="d-flex flex-column gap-3 mb-4">
    <?php foreach ($da as $p): ?>
        <div class="ap-scheda">
            <?php if ($p->locandina !== ''): ?>
                <img src="<?php echo $p->locandina; ?>" alt="Locandina di «<?php echo $p->titolo; ?>»" class="ap-miniatura" loading="lazy" data-nascondi-errore>
            <?php else: ?>
                <span class="ap-miniatura ap-miniatura-vuota" aria-hidden="true"><i class="fa fa-file-lines"></i></span>
            <?php endif; ?>
            <div class="ap-scheda-corpo">
                <h3 class="ap-titolo"><a href="<?php echo $p->href; ?>"><?php echo $p->titolo; ?></a></h3>
                <div class="ap-meta"><i class="fa fa-user" aria-hidden="true"></i> <?php echo $p->richiedente; ?></div>
                <div class="ap-meta"><i class="fa fa-calendar-day" aria-hidden="true"></i> Evento il <?php echo $p->dataEvento; ?> <span class="ap-sep">·</span> <i class="fa fa-paper-plane" aria-hidden="true"></i> inviata il <?php echo $p->inviata; ?></div>
            </div>
            <a href="<?php echo $p->href; ?>" class="btn btn-danger fw-bold ap-apri">Apri e decidi</a>
        </div>
    <?php endforeach; ?>
    </div>

    <h2 class="ui-sezione mb-2">Decise di recente</h2>
    <?php if (!$decise): ?>
        <p class="text-secondary">Ancora nessuna richiesta decisa.</p>
    <?php endif; ?>
    <div class="d-flex flex-column gap-2">
    <?php foreach ($decise as $x): ?>
        <a href="<?php echo $x->href; ?>" class="ap-decisa">
            <span class="ui-stato <?php echo (int) $x->grezzo('approvata') === 1 ? 'ok' : 'azione'; ?>"><i class="fa <?php echo (int) $x->grezzo('approvata') === 1 ? 'fa-check' : 'fa-xmark'; ?>" aria-hidden="true"></i><?php echo (int) $x->grezzo('approvata') === 1 ? 'Approvata' : 'Respinta'; ?></span>
            <span class="ap-decisa-testo"><strong><?php echo $x->titolo; ?></strong> <span class="ap-sep">·</span> <?php echo $x->richiedente; ?><br>
                <span class="text-secondary">da <?php echo $x->chi; ?> il <?php echo $x->quando; ?></span><?php if ($x->motivo !== ''): ?><br><span class="text-secondary">Motivo: <?php echo $x->motivo; ?></span><?php endif; ?></span>
        </a>
    <?php endforeach; ?>
    </div>

<?php elseif ((string) $d->grezzo('quale') === 'richiesta'): ?>
    <header class="ui-pagina-testa">
        <a href="<?php echo $d->hrefElenco; ?>" class="ui-indietro"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Tutte le richieste</a>
        <h1><?php echo $d->titolo; ?></h1>
        <p>Inviata da <?php echo $d->richiedente; ?> il <?php echo $d->inviata; ?></p>
    </header>

    <?php if ((int) $d->grezzo('decisa') === 1): ?>
        <div class="ap-esito <?php echo (int) $d->grezzo('decisaApprovata') === 1 ? 'ok' : 'azione'; ?>" role="status">
            <i class="fa <?php echo (int) $d->grezzo('decisaApprovata') === 1 ? 'fa-circle-check' : 'fa-circle-xmark'; ?>" aria-hidden="true"></i>
            <div><?php if ((int) $d->grezzo('decisaDaMe') === 1): ?><strong>L'hai <?php echo (int) $d->grezzo('decisaApprovata') === 1 ? 'approvata' : 'respinta'; ?> tu il <?php echo $d->decisaIl; ?>.</strong><?php else: ?><strong>Questa richiesta è già stata <?php echo (int) $d->grezzo('decisaApprovata') === 1 ? 'approvata' : 'respinta'; ?> da <?php echo $d->decisaDa; ?> il <?php echo $d->decisaIl; ?>.</strong><br>Non devi fare niente.<?php endif; ?><?php if ($d->decisaMotivo !== ''): ?><br>Motivo: <?php echo $d->decisaMotivo; ?><?php endif; ?></div>
        </div>
    <?php elseif ((int) $d->grezzo('attesa') !== 1): ?>
        <div class="ap-esito info" role="status"><i class="fa fa-circle-info" aria-hidden="true"></i><div><strong>Questa richiesta non è in attesa di approvazione</strong> (è «<?php echo $d->statoTesto; ?>»). Non devi fare niente.</div></div>
    <?php endif; ?>

    <div class="ap-riepilogo">
        <dl class="ap-righe">
        <?php foreach ($d->elenco('righe') as $riga): ?>
            <dt><?php echo $riga->etichetta; ?></dt>
            <dd><?php echo $riga->grezzo('valore'); ?></dd>
        <?php endforeach; ?>
        </dl>
    </div>

    <?php if ((int) $d->grezzo('attesa') === 1): ?>
        <div class="ap-azioni">
            <form method="POST" action="approva_pubblicazioni.php?id=<?php echo (int) $d->grezzo('id'); ?>" class="ap-form-approva" data-conferma="Approvare la richiesta «<?php echo $d->titolo; ?>»? Passa all'ufficio comunicazione e chi l'ha inviata lo sa.">
                <?php echo $d->grezzo('campoCsrf'); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d->grezzo('id'); ?>">
                <button type="submit" name="approva" value="1" class="btn btn-success btn-lg fw-bold ap-grande">
                    <i class="fa fa-check me-2" aria-hidden="true"></i>Approva
                </button>
            </form>
            <details class="ap-respingi"<?php if ((int) $d->grezzo('apriRespingi') === 1): ?> open<?php endif; ?>>
                <summary class="btn btn-outline-danger btn-lg fw-bold ap-grande"><i class="fa fa-xmark me-2" aria-hidden="true"></i>Respingi</summary>
                <form method="POST" action="approva_pubblicazioni.php?id=<?php echo (int) $d->grezzo('id'); ?>" class="ap-motivo" data-conferma="Respingere la richiesta «<?php echo $d->titolo; ?>»? Il motivo che hai scritto arriva a chi l'ha inviata." data-conferma-livello="pericolo">
                    <?php echo $d->grezzo('campoCsrf'); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $d->grezzo('id'); ?>">
                    <label for="ap-motivo-testo" class="form-label fw-bold">Perché la respingi? Lo leggerà chi ha inviato la richiesta.</label>
                    <textarea id="ap-motivo-testo" name="motivo" class="form-control" rows="4" maxlength="<?php echo (int) $d->grezzo('maxMotivo'); ?>" required></textarea>
                    <button type="submit" name="respingi" value="1" class="btn btn-danger btn-lg fw-bold ap-grande mt-3">
                        <i class="fa fa-xmark me-2" aria-hidden="true"></i>Respingi con questo motivo
                    </button>
                </form>
            </details>
        </div>
    <?php endif; ?>

<?php else: ?>
    <header class="ui-pagina-testa">
        <a href="approva_pubblicazioni.php" class="ui-indietro"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Tutte le richieste</a>
        <h1>Richiesta non trovata</h1>
        <p>Questa richiesta non esiste più. Torna all'elenco per vedere quelle da approvare.</p>
    </header>
<?php endif; ?>
</div>
