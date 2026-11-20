<?php
// inc/auth.php - Facciate di App\Auth per le pagine (chi è ammesso: App\Auth\Pubblico).
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!function_exists('html_scelta_pubblico')) {
    // Caselle accessibili dei profili (chi può vedere/fare una cosa): $nome è il prefisso dei campi del modulo; $profili (facoltativo) limita le caselle
    // offerte e $legenda ne cambia il titolo (senza: tutti i profili, «A chi si rivolge»)
    function html_scelta_pubblico(string $nome, array $regola, ?array $profili = null, string $legenda = 'A chi si rivolge'): string {
        return \App\Auth\Vista\PubblicoVista::scelta($nome, $regola, $profili, $legenda);
    }
    // Legge dal POST la regola scelta con html_scelta_pubblico() (stesso $nome)
    function pubblico_da_post(string $nome, array $post): array {
        return \App\Auth\Vista\PubblicoVista::daPost($nome, $post);
    }
}
