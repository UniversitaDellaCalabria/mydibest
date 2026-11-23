<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Parte di pannello/contatti. Dati: 'c' = il contatto (record completo, anche per uno nuovo), 'campoCsrf' (HTML del campo nascosto) ?>
<?php $c = $d->c; $id = (int) $c->id; ?>
<form method="POST" class="row g-3"><?php echo $d->grezzo('campoCsrf'); ?>
<?php // I testi dei campi sono scritti qui, nel modello: non sono dati, quindi non passano da $d. Il valore sì: $c->$k è già con l'escape. ?>
<?php foreach ([
    ['titolo', 'A chi ci si rivolge *', 'Per esempio: Segreteria didattica', 'text', 'col-12'],
    ['per_cosa', 'Per quali richieste', 'Una frase: certificati, piano di studi, convalide…', 'text', 'col-12'],
    ['luogo', 'Dove si trova', 'Edificio, piano, stanza', 'text', 'col-md-6'],
    ['orari', 'Quando è aperto', 'Per esempio: lunedì e mercoledì 10-12', 'text', 'col-md-6'],
    ['email', 'Email', 'Un indirizzo dell\'ufficio, non di una persona', 'text', 'col-md-6'],
    ['telefono', 'Telefono', '', 'text', 'col-md-6'],
    ['link_etichetta', 'Testo del collegamento', 'Per esempio: Prenota un appuntamento', 'text', 'col-md-6'],
    ['link_url', 'Indirizzo del collegamento', 'Inizia con https:// oppure una pagina del portale', 'text', 'col-md-6'],
    ['ordine', 'Posizione nell\'elenco', 'Numero piccolo = in alto', 'number', 'col-md-3'],
] as [$k, $etichetta, $aiuto, $tipo, $colonna]): ?>
    <div class="<?php echo $colonna; ?>"><label class="form-label fw-bold" for="ct<?php echo $id . $k; ?>"><?php echo $etichetta; ?></label><input class="form-control" type="<?php echo $tipo; ?>" id="ct<?php echo $id . $k; ?>" name="<?php echo $k; ?>" value="<?php echo $c->$k; ?>"><?php if ($aiuto !== ''): ?><div class="form-text"><?php echo $aiuto; ?></div><?php endif; ?></div>
<?php endforeach; ?>
    <div class="col-md-9 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="visibile" value="1"<?php echo !empty($c->visibile) ? ' checked' : ''; ?>> <span class="fw-bold">Mostra sul sito</span></label></div>
    <div class="col-12 d-flex gap-2"><button type="submit" name="salva" value="<?php echo $id; ?>" class="btn btn-azione fw-bold"><i class="fa fa-save me-1" aria-hidden="true"></i>Salva</button>
        <a class="btn btn-outline-secondary" href="contatti.php">Annulla</a></div>
</form>
