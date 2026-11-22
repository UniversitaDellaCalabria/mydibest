<?php
// Fondo della pagina pubblica di un'area: le finestre con il modulo di prenotazione di ogni turno (una per turno, aperte dai pulsanti dei layout)
// e lo script che controlla le due email e ripulisce l'indirizzo dopo l'esito. Caricato da master_template.php prima di footer.php.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $all_turni_flat, $col_primaria, $utente_logged, $val_nome, $val_cognome, $val_email, $val_matricola, $chiedi_matricola, $conn, $p_id
?>
<?php foreach($all_turni_flat as $t_flat): ?>
    <?php printModalPrenotazione($t_flat, $col_primaria, $utente_logged, $val_nome, $val_cognome, $val_email, $val_matricola, $chiedi_matricola, $conn, $p_id); ?>
<?php endforeach; ?>

<script nonce="<?php echo csp_nonce(); ?>">
    // Email ripetuta: avviso subito se le due non coincidono (il server ricontrolla)
    document.addEventListener('input', function (e) {
        if (!e.target.matches('.pren-email, .pren-email-conf')) return;
        var f = e.target.form, a = f.querySelector('.pren-email'), b = f.querySelector('.pren-email-conf');
        if (!a || !b) return;
        b.setCustomValidity(b.value && a.value.trim().toLowerCase() !== b.value.trim().toLowerCase() ? 'Le due email non coincidono' : '');
    });
    if (window.history.replaceState) {
        const url = new URL(window.location);
        // Toglie i parametri dell'esito, lasciando quelli di navigazione (es. ?progetto=ID)
        if (url.searchParams.has('status')) { ['status', 'code', 'st_tipo', 'ev'].forEach(function (k) { url.searchParams.delete(k); }); window.history.replaceState({path:url.href}, '', url.href); }
    }
</script>
