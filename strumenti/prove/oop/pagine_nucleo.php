<?php
// Prova che il Nucleo si può togliere: con nucleo.php rinominato (e quindi senza la regola «3b» del router locale, che lo usa solo se il file c'è)
// il portale risponde come oggi a ogni indirizzo di oggi. Le tre pagine convertite (contatti.php, admin/contatti.php, stampa_ricevuta.php) sono
// gusci che chiamano da soli Nucleo::serviFile(): non passano da nucleo.php. Solo gli indirizzi nuovi, con una «/» e senza file, sono del Nucleo
// (404 pulito del Nucleo con nucleo.php, 404 del server senza). La prova con un Apache vero del .htaccess è strumenti/prove/apache_nucleo.sh.
// Lavora sull'ambiente locale (come le altre pagine_*.php): $http, $jar (amministratore), $jar2 (docente).
sezione("Il Nucleo si può togliere: con nucleo.php rinominato il portale risponde come prima");

$__nucleo_file = RADICE_SITO . '/nucleo.php';
$__nucleo_fuori = RADICE_SITO . '/nucleo.php.fuori';
if (is_file($__nucleo_fuori) && !is_file($__nucleo_file)) { rename($__nucleo_fuori, $__nucleo_file); } // un'esecuzione interrotta in passato
prova(is_file($__nucleo_file), "nucleo.php c'è all'inizio della prova");
// Qualunque cosa succeda (anche un errore fatale) nucleo.php torna al suo posto
register_shutdown_function(static function () use ($__nucleo_file, $__nucleo_fuori): void {
    if (is_file($__nucleo_fuori) && !is_file($__nucleo_file)) rename($__nucleo_fuori, $__nucleo_file);
});

$loc->query("DELETE FROM rate_limit_attempts");

// Le parti che cambiano a ogni richiesta (token, nonce della politica di sicurezza, codici casuali) non contano per il confronto
$__normalizza = static function (string $corpo): string {
    $corpo = preg_replace('/nonce="[^"]*"/', 'nonce="N"', $corpo) ?? $corpo;
    $corpo = preg_replace('/(csrf_token[\'"]?[,:=]?\s*[\'"]?(?:value=")?)[a-f0-9]{16,}/i', '$1T', $corpo) ?? $corpo;
    $corpo = preg_replace('/\b[a-f0-9]{32,}\b/', 'H', $corpo) ?? $corpo;
    $corpo = preg_replace('/\?v=\d+/', '?v=0', $corpo) ?? $corpo;
    $corpo = preg_replace('/\bscu_[a-f0-9]+/', 'scu_X', $corpo) ?? $corpo;                       // id casuale del campo scuola
    $corpo = preg_replace('/name="captcha_id" value="[a-f0-9]+"/', 'name="captcha_id" value="C"', $corpo) ?? $corpo; // id della domanda anti-robot
    $corpo = preg_replace('/Quanto fa \d+ [+\-x] \d+\?/u', 'Quanto fa N?', $corpo) ?? $corpo;     // domanda anti-robot a caso

    return preg_replace('/\s+/', ' ', $corpo) ?? $corpo;
};
$__istantanea = static function (array $elenco) use ($http, $__normalizza): array {
    $out = [];
    foreach ($elenco as [$url, $jar_x]) {
        $r = $http($url, null, $jar_x);
        $out[$url . '|' . ($jar_x === '' ? 'anonimo' : basename($jar_x))] = ['codice' => $r['codice'], 'dove' => $r['dove'], 'corpo' => $__normalizza($r['corpo'])];
    }

    return $out;
};

// Gli indirizzi di oggi: le tre pagine convertite, un'area, uno slug che non esiste, una pagina vecchia, un file che manca
$__di_oggi = [
    ['/mydibest/contatti.php', ''], ['/mydibest/contatti', ''], ['/mydibest/contatti.php', $jar2],
    ['/mydibest/admin/contatti.php', $jar], ['/mydibest/admin/contatti', $jar], ['/mydibest/admin/contatti.php', $jar2], ['/mydibest/admin/contatti.php', ''],
    ['/mydibest/stampa_ricevuta.php', ''], ['/mydibest/stampa_ricevuta.php?codice=NON-ESISTE-NUCLEO', ''], ['/mydibest/stampa_ricevuta.php', $jar],
    ['/mydibest/openlab', ''], ['/mydibest/openlab.php', ''], ['/mydibest/slug-che-non-esiste', ''], ['/mydibest/privacy.php', ''], ['/mydibest/admin/non_esiste.php', $jar],
    ['/mydibest/assets/manca.png', ''],
];
// Gli indirizzi nuovi: due o tre pezzi, nessun file (prima un 404 del server)
$__nuovi = ['/mydibest/pagine/nuova', '/mydibest/pagine/nuova/sotto'];

$__prima = $__istantanea($__di_oggi);
$__nuovi_con = [];
foreach ($__nuovi as $u) { $r = $http($u); $__nuovi_con[$u] = $r['codice']; }

$__spostato = rename($__nucleo_file, $__nucleo_fuori);
try {
    prova($__spostato && !is_file($__nucleo_file), "nucleo.php messo da parte per la prova");
    $__dopo = $__istantanea($__di_oggi);
    $__nuovi_senza = [];
    foreach ($__nuovi as $u) { $r = $http($u); $__nuovi_senza[$u] = $r['codice']; }
} finally {
    if (is_file($__nucleo_fuori) && !is_file($__nucleo_file)) rename($__nucleo_fuori, $__nucleo_file);
}
prova(is_file($__nucleo_file) && !is_file($__nucleo_fuori), "nucleo.php rimesso al suo posto");

foreach ($__prima as $chiave => $p) {
    $d = $__dopo[$chiave] ?? ['codice' => -1, 'dove' => '', 'corpo' => ''];
    $uguale = $p['codice'] === $d['codice'] && $p['dove'] === $d['dove'] && $p['corpo'] === $d['corpo'];
    prova($uguale, "senza nucleo.php risponde uguale: $chiave (" . $p['codice'] . ')',
        $uguale ? '' : 'prima ' . $p['codice'] . ' ' . $p['dove'] . ' (' . strlen($p['corpo']) . ' car.) · dopo ' . $d['codice'] . ' ' . $d['dove'] . ' (' . strlen($d['corpo']) . ' car.)');
}
foreach ($__nuovi as $u) {
    prova(($__nuovi_con[$u] ?? 0) === 404, "indirizzo nuovo $u: con nucleo.php è un 404 pulito (senza rotta)", 'risposta ' . ($__nuovi_con[$u] ?? 0));
    prova(($__nuovi_senza[$u] ?? 0) === 404, "indirizzo nuovo $u: senza nucleo.php è un 404, come prima del Nucleo", 'risposta ' . ($__nuovi_senza[$u] ?? 0));
}
$loc->query("DELETE FROM rate_limit_attempts");
