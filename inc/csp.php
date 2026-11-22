<?php
// inc/csp.php - Nonce della Content-Security-Policy: uno per richiesta (16 byte casuali, base64).
// config.php lo mette nell'intestazione (script-src 'nonce-...') e ogni script in linea lo ripete nell'attributo nonce,
// scritto con la funzione csp_nonce() al posto del valore.
// Senza dipendenze: si carica da inc/ambiente.php (prima di tutto) e da src/autoload.php (classi e prove).
if (!function_exists('csp_nonce')) {
    function csp_nonce(): string {
        static $nonce = null;
        return $nonce ??= base64_encode(random_bytes(16));
    }
}
