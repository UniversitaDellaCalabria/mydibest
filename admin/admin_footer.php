</div> <!-- Fine container-fluid -->
    </div> <!-- Fine page-content-wrapper -->
</div> <!-- Fine wrapper -->

<!-- La finestra di conferma (data-conferma, data-conferma-scrivi, vecchio data-confirm) la crea assets/js/conferma.js -->

<script src="<?php echo url_vendor('jsdelivr/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'); ?>" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
<script src="<?php echo url_vendor('datatables/1.13.6/js/jquery.dataTables.min.js'); ?>" integrity="sha384-k5vbMeKHbxEZ0AEBTSdR7UjAgWCcUfrS8c0c5b2AfIh7olfhNkyCZYwOfzOQhauK" crossorigin="anonymous"></script>
<script src="<?php echo url_vendor('datatables/1.13.6/js/dataTables.bootstrap5.min.js'); ?>" integrity="sha384-PgPBH0hy6DTJwu7pTf6bkRqPlf/+pjUBExpr/eIfzszlGYFlF9Wi9VTAJODPhgCO" crossorigin="anonymous"></script>
<script src="<?php echo url_vendor('jsdelivr/npm/chart.js@4.4.0/dist/chart.umd.min.js'); ?>" integrity="sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g" crossorigin="anonymous"></script>
<script src="<?php echo url_vendor('jsdelivr/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'); ?>" integrity="sha384-d3UHjPdzJkZuk5H3qKYMLRyWLAQBJbby2yr2Q58hXXtAGF8RSNO9jpLDlKKPv5v3" crossorigin="anonymous"></script>
<script src="<?php echo url_vendor('tinymce/8.10.2/tinymce.min.js'); ?>"></script>
<script src="../assets/js/campo-scuola.js?v=3"></script>
<script src="../assets/js/ricerca-personale.js?v=1"></script>
<script src="../assets/js/conferma.js?v=<?php echo (int)\App\Core\File::modificato(__DIR__ . '/../assets/js/conferma.js'); ?>"></script>
<script src="../assets/js/azioni.js?v=<?php echo (int)\App\Core\File::modificato(__DIR__ . '/../assets/js/azioni.js'); ?>"></script>
<script src="../assets/js/pannello.js?v=<?php echo (int)\App\Core\File::modificato(__DIR__ . '/../assets/js/pannello.js'); ?>"></script>

<script nonce="<?php echo csp_nonce(); ?>">
    // Editor completo (TinyMCE 8, GPL): formattazione, titoli, elenchi, tabelle, link, immagini (caricate sul sito o incollate), video, emoji, cerca/sostituisci, schermo intero, codice HTML
    tinymce.init({
        selector: 'textarea.editor-html', license_key: 'gpl', language: 'it', language_url: '<?php echo url_vendor('tinymce/8.10.2/langs/it.js'); ?>',
        base_url: '<?php echo url_vendor('tinymce/8.10.2'); ?>', suffix: '.min',
        plugins: 'advlist autolink lists link image media table charmap anchor searchreplace visualblocks code codesample fullscreen insertdatetime preview emoticons wordcount autoresize help pagebreak nonbreaking directionality quickbars accordion',
        menubar: 'file edit view insert format table tools help',
        toolbar: 'undo redo | blocks | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image media table | blockquote hr charmap emoticons | removeformat | fullscreen code help',
        toolbar_mode: 'sliding', quickbars_selection_toolbar: 'bold italic | quicklink h2 h3 blockquote', quickbars_insert_toolbar: false,
        block_formats: 'Paragrafo=p; Titolo 2=h2; Titolo 3=h3; Titolo 4=h4; Citazione=blockquote; Codice=pre',
        min_height: 320, max_height: 900, autoresize_bottom_margin: 20, branding: false, promotion: false, resize: true,
        relative_urls: false, convert_urls: false, link_default_target: '_blank', link_default_protocol: 'https', link_assume_external_targets: 'https',
        image_caption: true, image_advtab: true, image_title: true, automatic_uploads: true, paste_data_images: true,
        media_alt_source: false, media_poster: false, media_dimensions: true,
        content_style: 'body{font-family:system-ui,Segoe UI,Arial,sans-serif;font-size:16px;line-height:1.6;padding:8px} img{max-width:100%;height:auto} table{border-collapse:collapse} td,th{border:1px solid #ccc;padding:4px 8px}',
        images_upload_handler: function (blob) {
            return new Promise(function (ok, no) {
                var f = new FormData();
                f.append('file', blob.blob(), blob.filename());
                f.append('csrf_token', <?php echo json_encode((string)(new \App\Auth\Csrf(new \App\Auth\SessioneNativa()))->token()); ?>);
                fetch('editor_upload.php', { method: 'POST', body: f, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) { if (j && j.location) ok(j.location); else no({ message: (j && j.errore) || 'Caricamento non riuscito.', remove: true }); })
                    .catch(function () { no({ message: 'Caricamento non riuscito.', remove: true }); });
            });
        }
    });
    // Campi brevi (card): solo formattazione in linea; il contatore conta il testo visibile
    tinymce.init({
        selector: 'textarea.editor-breve', license_key: 'gpl', menubar: false, statusbar: false, height: 130,
        toolbar: 'undo redo | bold italic underline | removeformat', valid_elements: 'strong/b,em/i,u,br,p',
        setup: function (ed) {
            var conta = function () {
                var out = ed.getElement().parentElement.querySelector('.desc-breve-conta');
                if (out) { var n = ed.getContent({ format: 'text' }).trim().length; out.textContent = n; out.parentElement.classList.toggle('text-danger', n > 300); }
            };
            ed.on('init input change keyup SetContent', conta);
        }
    });

    // Le conferme (data-conferma, data-conferma-scrivi, data-confirm) sono in assets/js/conferma.js

    // Accessibilità: dà un nome ai pulsanti con la sola icona (titolo, altrimenti dall'icona)
    document.addEventListener('DOMContentLoaded', function () {
        var nomi = { 'fa-edit': 'Modifica', 'fa-pen': 'Modifica', 'fa-pencil': 'Modifica', 'fa-trash': 'Elimina', 'fa-trash-alt': 'Elimina',
            'fa-times': 'Chiudi', 'fa-xmark': 'Chiudi', 'fa-plus': 'Aggiungi', 'fa-plus-circle': 'Aggiungi', 'fa-bars': 'Menu',
            'fa-moon': 'Cambia tema', 'fa-sun': 'Cambia tema', 'fa-circle-half-stroke': 'Cambia tema', 'fa-magnifying-glass': 'Cerca',
            'fa-search': 'Cerca', 'fa-copy': 'Copia', 'fa-eye': 'Mostra', 'fa-eye-slash': 'Nascondi', 'fa-download': 'Scarica',
            'fa-print': 'Stampa', 'fa-arrow-up': 'Sposta su', 'fa-arrow-down': 'Sposta giù', 'fa-ellipsis-v': 'Altre azioni' };
        document.querySelectorAll('button, a.btn, .btn-close').forEach(function (b) {
            if (b.textContent.trim() || b.getAttribute('aria-label') || b.getAttribute('aria-labelledby')) return;
            var nome = b.getAttribute('title');
            if (!nome && b.classList.contains('btn-close')) nome = 'Chiudi';
            if (!nome) {
                var i = b.querySelector('i.fa, i[class*="fa-"]');
                if (i) i.classList.forEach(function (c) { if (!nome && nomi[c]) nome = nomi[c]; });
            }
            if (nome) b.setAttribute('aria-label', nome);
        });
    });

    $(document).ready(function() {
        // Logica apertura/chiusura Sidebar su mobile
        $('#sidebarToggle').on('click', function() {
            $('#sidebar').toggleClass('active');
            $('#sidebarOverlay').toggleClass('active');
        });
        $('#closeSidebar, #sidebarOverlay').on('click', function() {
            $('#sidebar').removeClass('active');
            $('#sidebarOverlay').removeClass('active');
        });
        
        // Pulizia URL se c'è un parametro status
        if (window.history.replaceState) {
            const url = new URL(window.location);
            if (url.searchParams.has('status')) { 
                url.searchParams.delete('status');
                window.history.replaceState({path:url.href}, '', url.href); 
            }
        }
    });
</script>
<?php include __DIR__ . '/../inc/barra_simulazione.php'; ?>
<?php
// Percorso in alto: ora la pagina ha detto qual è l'elemento aperto ($percorso_elemento), si completa il segnaposto lasciato da admin_header.php
if (function_exists('pn_briciole_html') && ob_get_level() > 0) {
    $pn_pagina = (string)ob_get_contents();
    if (str_contains($pn_pagina, '<!--PN_BRICIOLE-->')) {
        ob_clean();
        echo str_replace('<!--PN_BRICIOLE-->', pn_briciole_html(), $pn_pagina);
    }
    unset($pn_pagina);
}
?>
</body>
</html>
