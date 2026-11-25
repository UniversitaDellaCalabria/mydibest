<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: s (la scheda), campoCsrf, puoFsl, chiaveUrl, oggi
$s = $d->s;
$csrf = $d->grezzo('campoCsrf');
$docenti = $s->elenco('docenti');
$soloMano = (bool) $s->grezzo('a_mano');
?>
<?php if (!$docenti): ?>
    <p class="text-secondary">Nessun docente conosciuto per questa scuola: aggiungine uno qui sotto.</p>
<?php else: ?>
    <?php foreach ($docenti as $x): ?>
        <div class="sc-file sc-alto">
            <span><strong><?php echo $x->nome; ?></strong>
                <span class="sc-sotto"><?php echo $x->grezzo('email') !== '' ? '<a href="mailto:' . $x->email . '">' . $x->email . '</a>' : '<span class="sc-manca">manca l\'indirizzo email</span>'; ?><?php if ($x->grezzo('telefono') !== ''): ?> · <a href="tel:<?php echo \App\Http\DatiVista::escapa(preg_replace('/[^0-9+]/', '', (string) $x->grezzo('telefono'))); ?>"><?php echo $x->telefono; ?></a><?php endif; ?></span>
                <span class="sc-sotto"><?php echo (int) $x->grezzo('eventi'); ?> <?php echo (int) $x->grezzo('eventi') === 1 ? 'evento seguito' : 'eventi seguiti'; ?> · <?php echo $x->grezzo('in_allegato') ? 'nell\'Allegato A' : ($x->grezzo('proposto') ? 'proposto per l\'Allegato A, da salvare' : 'non nell\'Allegato A'); ?><?php echo $x->grezzo('ultimo_contatto') !== '' ? ' · ultimo contatto ' . date('d/m/Y', (int) strtotime((string) $x->grezzo('ultimo_contatto'))) : ' · mai contattato'; ?> · <?php echo $x->fonte; ?></span></span>
            <span class="d-flex flex-wrap gap-1">
                <?php if ($x->grezzo('email') !== ''): ?><a class="btn btn-sm btn-outline-secondary" href="mailto:<?php echo $x->email; ?>"><i class="fa fa-envelope me-1" aria-hidden="true"></i>Scrivi</a><?php endif; ?>
                <?php if ($x->grezzo('modificabile') && !$soloMano): ?><details class="m-0 border-0 p-0"><summary class="btn btn-sm btn-outline-secondary d-inline-block"><i class="fa fa-pen me-1" aria-hidden="true"></i>Modifica</summary>
                    <form method="POST" class="mt-2 d-grid gap-2"><?php echo $csrf; ?>
                        <input type="hidden" name="email" value="<?php echo $x->email; ?>">
                        <input type="text" class="form-control form-control-sm" name="nome" value="<?php echo $x->nome; ?>" maxlength="200" aria-label="Nome e cognome" placeholder="Nome e cognome">
                        <input type="tel" class="form-control form-control-sm" name="telefono" value="<?php echo $x->telefono; ?>" maxlength="60" aria-label="Telefono" placeholder="Telefono">
                        <button type="submit" name="docente_salva" value="1" class="btn btn-sm btn-azione fw-bold">Salva</button></form></details><?php endif; ?>
                <?php if ($x->grezzo('senza_email') && !$soloMano): ?><details class="m-0 border-0 p-0"><summary class="btn btn-sm btn-azione d-inline-block"><i class="fa fa-at me-1" aria-hidden="true"></i>Scrivi l'email</summary>
                    <form method="POST" class="mt-2 d-grid gap-2"><?php echo $csrf; ?>
                        <input type="hidden" name="nome" value="<?php echo $x->nome; ?>">
                        <input type="email" class="form-control form-control-sm" name="email" required maxlength="255" aria-label="Email di <?php echo $x->nome; ?>" placeholder="email@scuola.edu.it" autocomplete="off">
                        <input type="tel" class="form-control form-control-sm" name="telefono" value="<?php echo $x->telefono; ?>" maxlength="60" aria-label="Telefono" placeholder="Telefono (facoltativo)">
                        <button type="submit" name="docente_salva" value="1" class="btn btn-sm btn-azione fw-bold">Salva l'email</button></form></details><?php endif; ?>
            </span>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php if (!$soloMano): ?>
<details><summary><i class="fa fa-user-plus me-1" aria-hidden="true"></i>Aggiungi un docente</summary>
    <form method="POST" class="mt-2 d-grid gap-2"><?php echo $csrf; ?>
        <label class="visually-hidden" for="scDocNome">Nome e cognome</label><input type="text" class="form-control" id="scDocNome" name="nome" maxlength="200" placeholder="Nome e cognome" required>
        <label class="visually-hidden" for="scDocEmail">Email</label><input type="email" class="form-control" id="scDocEmail" name="email" maxlength="255" placeholder="email@scuola.edu.it" required autocomplete="off">
        <label class="visually-hidden" for="scDocTel">Telefono</label><input type="tel" class="form-control" id="scDocTel" name="telefono" maxlength="60" placeholder="Telefono (facoltativo)">
        <button type="submit" name="docente_salva" value="1" class="btn btn-azione fw-bold">Aggiungi il docente</button>
        <p class="small text-secondary mb-0">Un docente aggiunto qui non diventa il referente della convenzione: per quello serve il link inviato al suo indirizzo con «Avvia la stipula».</p></form></details>
<?php endif; ?>
