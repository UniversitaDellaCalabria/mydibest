<?php
// Stile e script comuni a tutti i disegni della pagina pubblica di un'area: colore dell'area (dal database) e larghezza del contenitore come variabili CSS,
// correzione dello scorrimento della barra laterale, stelle della valutazione. Caricato da master_template.php subito dopo header.php.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $col_primaria, $col_testo_area, $page_cfg
?>
<style>
    /* Colore dell'area (dal database) e larghezza del contenitore: le classi ui-area-* e ui-larg-cont di portale-ui.css li usano */
    :root { --c-area: <?php echo htmlspecialchars($col_primaria); ?>; --c-area-su: <?php echo htmlspecialchars(colore_testo_su($col_primaria)); ?>; --c-area-testo: <?php echo htmlspecialchars($col_testo_area); ?>; --larg-cont: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>; }
</style>
<style>
    /* FIX STICKY: Rimuove l'overflow nascosto che blocca lo scrolling della sidebar nel framework AGID */
    html, body { overflow-x: visible !important; overflow-y: visible !important;}
    .it-header-wrapper { overflow-x: clip !important; } /* Limitiamo l'overflow-x nascosto solo all'header */
    main, .container-fluid { overflow: visible !important; }
    
    .sidebar-sticky-fix {
        position: -webkit-sticky !important;
        position: sticky !important;
        top: 110px !important;
        z-index: 1020;
    }
    /* Bootstrap Italia aggiunge 48px sotto ogni card (::after): nelle card degli eventi il pulsante resta sul fondo */
    .card-evento-u::after { display: none; }
    .star-btn { transition: color .1s; }
    .star-btn.active, .star-btn:hover, .star-btn.hover { color: #f59e0b !important; border-color: #f59e0b !important; }
    .star-btn.active::before { content: '★'; position:absolute; }
</style>
<script nonce="<?php echo csp_nonce(); ?>">
function evSetRating(btn) {
    var targetId = btn.dataset.target;
    var val = btn.dataset.val;
    var container = btn.parentElement;
    container.querySelectorAll('.star-btn').forEach(function(b) {
        b.textContent = parseInt(b.dataset.val) <= parseInt(val) ? '★' : '☆';
        b.classList.toggle('active', parseInt(b.dataset.val) <= parseInt(val));
    });
    document.getElementById(targetId).value = val;
}
</script>
