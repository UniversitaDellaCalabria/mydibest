<?php
// Domanda «prenota ora o scegli altre attività», che compare dopo aver aggiunto un'attività al programma FSL della scuola (?programma=ok).
// Caricato da master_template.php solo se c'è un'attività appena aggiunta.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $modal_programma
$n_prog = \App\Core\App::get(\App\Fsl\ProgrammaFsl::class)->conta();
?>
<!-- Attività appena aggiunta al programma FSL: prenotare ora o scegliere altre attività -->
<div class="modal fade" id="modProgrammaAggiunta" tabindex="-1" aria-labelledby="modProgrammaAggiuntaTit" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg ui-r-12">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold" id="modProgrammaAggiuntaTit"><i class="fa fa-circle-check text-success me-2" aria-hidden="true"></i><?php echo $modal_programma['sostituita'] ? 'Programma aggiornato' : 'Aggiunta al programma'; ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong><?php echo htmlspecialchars($modal_programma['titolo']); ?></strong> <?php echo $modal_programma['sostituita'] ? 'è stata aggiornata nel' : 'è nel'; ?> programma della tua scuola, che ora ha <strong><?php echo $n_prog; ?> attività</strong>.</p>
                <p class="small text-secondary mb-0">Puoi prenotare adesso oppure scegliere altre attività: le prenoterai tutte insieme, con un'unica convenzione e l'Allegato A già compilato. I posti si assegnano alla conferma.</p>
            </div>
            <div class="modal-footer border-0 flex-column flex-sm-row gap-2">
                <button type="button" class="btn btn-outline-secondary fw-bold w-100 m-0" data-bs-dismiss="modal"><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Scegli altre attività</button>
                <a href="programma_fsl.php" class="ui-area-btn btn btn-primary fw-bold w-100 m-0"><i class="fa fa-clipboard-list me-1" aria-hidden="true"></i>Vai al programma e prenota</a>
            </div>
        </div>
    </div>
</div>
<script nonce="<?php echo csp_nonce(); ?>">
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('modProgrammaAggiunta');
    if (el && window.bootstrap) new bootstrap.Modal(el).show();
    // Ripulisce l'indirizzo: ricaricando la pagina la domanda non si ripropone
    if (history.replaceState) history.replaceState(null, '', location.pathname + location.search.replace(/([?&])programma=[^&]*&?/, '$1').replace(/[?&]$/, ''));
});
</script>
