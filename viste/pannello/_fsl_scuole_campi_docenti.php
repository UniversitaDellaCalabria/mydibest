<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: docenti (lista di nome, email), titolo
// Le righe «nome e email» dei docenti di una convenzione: stesse regole del modulo del registro (doc_nome[] e doc_email[]), con aggiungi e togli (assets/js/pannello.js).
$docenti = $d->elenco('docenti');
?>
<div class="cv-docenti-blocco">
    <div class="form-label small fw-bold mb-1"><?php echo $d->titolo; ?></div>
    <div class="cv-docenti">
        <?php if (!$docenti): ?>
            <div class="d-flex gap-2 mb-1 cv-doc">
                <input type="text" name="doc_nome[]" class="form-control form-control-sm" placeholder="Nome e cognome" value="" maxlength="150" aria-label="Nome e cognome del docente">
                <input type="email" name="doc_email[]" class="form-control form-control-sm" placeholder="Email" value="" aria-label="Email del docente">
                <button type="button" class="btn btn-sm btn-outline-danger" data-on-click="fslConvTogliDocente" aria-label="Togli il docente">×</button>
            </div>
        <?php endif; ?>
        <?php foreach ($docenti as $doc): ?>
            <div class="d-flex gap-2 mb-1 cv-doc">
                <input type="text" name="doc_nome[]" class="form-control form-control-sm" placeholder="Nome e cognome" value="<?php echo $doc->nome; ?>" maxlength="150" aria-label="Nome e cognome del docente">
                <input type="email" name="doc_email[]" class="form-control form-control-sm<?php echo $doc->grezzo('email') === '' ? ' sc-manca' : ''; ?>" placeholder="Email" value="<?php echo $doc->email; ?>" aria-label="Email del docente">
                <button type="button" class="btn btn-sm btn-outline-danger" data-on-click="fslConvTogliDocente" aria-label="Togli il docente">×</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary fw-bold py-0" data-on-click="fslConvAggiungiDocente"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi docente</button>
</div>
