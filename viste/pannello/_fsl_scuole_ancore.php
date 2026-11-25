<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: pagina («elenco» o «scheda»)
// Le ancore dei vecchi indirizzi di admin/fsl.php (#cv12, #fase-caricata, #verifica, #registro, #convForm…) non arrivano al server: il browser le tiene dopo il
// rimando. Se l'ancora non c'è in questa pagina, questo script la porta dove oggi sta la stessa cosa. Non fa nient'altro e non gira in cerchio: un indirizzo
// con ?cv= o ?cosa= non ha più nessuna ancora da tradurre. Il nonce è quello della pagina (csp_nonce()).
?>
<script nonce="<?php echo csp_nonce(); ?>" data-pagina="<?php echo $d->pagina; ?>">
(function () {
    var h = decodeURIComponent((location.hash || '').slice(1)), pagina = document.currentScript.getAttribute('data-pagina');
    if (!h || document.getElementById(h)) { return; }
    var m, dove = null, vai = null;
    var filtri = { 'fase-caricata': 'da_controllare', 'fase-dirfirma': 'dal_direttore', 'fase-firmata': 'da_protocollare', 'verifica': 'senza_convenzione', 'elenchi': 'elenco_mancante' };
    if ((m = /^cv(\d+)$/.exec(h)) && pagina === 'elenco') {
        vai = 'fsl_scuole.php?cv=' + m[1];
    } else if (pagina === 'elenco' && filtri[h]) {
        vai = 'fsl_scuole.php?cosa=' + filtri[h];
    } else if (pagina === 'elenco' && h === 'convForm') {
        dove = 'apriScuola';
    } else if (pagina === 'scheda') {
        dove = /^cv\d+$/.test(h) ? 'messaggi' : (/^(registro|convForm|registra)$/.test(h) ? 'registro' : (h === 'verifica' ? 'eventi' : (/^fase-/.test(h) ? 'convenzione' : null)));
    }
    if (vai) { location.replace(vai); return; }
    var el = dove ? document.getElementById(dove) : null;
    if (el) { if (el.tagName === 'DETAILS') { el.open = true; } el.scrollIntoView(); }
}());
</script>
