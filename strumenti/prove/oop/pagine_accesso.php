<?php
// Prove via HTTP (ambiente locale acceso) di come il pannello risponde a chi non può entrare, per le DUE strade che oggi convivono:
//  - una pagina servita dal Nucleo (admin/contatti.php: guscio → Nucleo → AccessoPannello::decidi, e la testata NON decide di nuovo);
//  - una pagina-script (admin/sistema.php: la testata decide da sé con le stesse regole, via AccessoPannello::seNonCollegata e perPagina).
// Le risposte devono essere le stesse da tutte e due: è la rete che ha permesso di far leggere la testata da ContestoPannello e di spostare nel
// Nucleo i tre casi (non collegato, niente da gestire, login non riuscito). Usa $jar (amministratore) e $jar2 (docente, senza sezioni).
sezione("Accesso al pannello: pagina del Nucleo (admin/contatti.php) e pagina-script (admin/sistema.php)");

$senza_errori_php = static fn (string $corpo): bool => !preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $corpo);
foreach (['Nucleo' => 'contatti.php', 'script' => 'sistema.php'] as $strada => $file) {
    $url = '/mydibest/admin/' . $file;

    // ── Non collegato ──
    $r = $http($url);
    prova($r['codice'] === 302 && str_contains($r['dove'], 'saml_login.php?redirect=admin%2F' . $file . '%3Fsso_tentato%3D1'), "$strada, anonimo: rimando al login SSO con il ritorno alla pagina", $r['codice'] . ' ' . $r['dove']);
    $r = $http($url . '?p_id=3&x=a%20b');
    prova($r['codice'] === 302 && str_contains($r['dove'], 'redirect=admin%2F' . $file . '%3Fp_id%3D3%26x%3Da%2Bb%26sso_tentato%3D1'), "$strada, anonimo con parametri: il ritorno li conserva", $r['dove']);
    $r = $http($url . '?sso_tentato=1');
    prova($r['codice'] === 200 && str_contains($r['corpo'], 'Autenticazione Richiesta') && substr_count($r['corpo'], '</html>') === 1 && !str_contains($r['corpo'], 'id="contenuto-pannello"'), "$strada, anonimo dopo un login fallito: la pagina «Autenticazione richiesta», senza pannello", $r['codice']);
    prova(str_contains($r['corpo'], 'href="../saml_login.php?redirect=admin%2Findex.php"') && $senza_errori_php($r['corpo']), "$strada, «Autenticazione richiesta»: il pulsante riporta al login");

    // ── Collegato senza il permesso (docente): 403, una sola pagina, nessuna logica ──
    $r = $http($url, null, $jar2);
    prova($r['codice'] === 403, "$strada, docente: 403", "risposta " . $r['codice']);
    prova(substr_count($r['corpo'], '<html') === 1 && substr_count($r['corpo'], '</html>') === 1 && str_contains($r['corpo'], 'id="contenuto-pannello"'), "$strada, docente: una sola pagina, dentro il layout del pannello");
    prova(str_contains($r['corpo'], 'Accesso negato. Non hai i permessi per aprire questa pagina.') && $senza_errori_php($r['corpo']), "$strada, docente: il testo di sempre, senza errori PHP");
    prova(!str_contains($r['corpo'], 'modSessionTimeout'), "$strada, docente: la testata si ferma alla guardia (nessuna finestra «Sessione in scadenza» nella pagina del 403)");

    // ── Chi può ──
    $r = $http($url, null, $jar);
    prova($r['codice'] === 200 && str_contains($r['corpo'], 'id="contenuto-pannello"') && str_contains($r['corpo'], 'modSessionTimeout') && $senza_errori_php($r['corpo']), "$strada, amministratore: 200 con la pagina intera");
}

// Il rimando di chi non ha sezioni da gestire: una pagina d'ingresso è un rimando all'area personale con un avviso, non un 403
$r = $http('/mydibest/admin/index.php', null, $jar2);
prova($r['codice'] === 303 && str_contains($r['dove'], 'area_personale.php'), "docente su admin/index.php: rimando 303 all'area personale", $r['codice'] . ' ' . $r['dove']);
$r = $http('/mydibest/area_personale.php', null, $jar2);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Non hai sezioni del pannello da gestire'), "l'avviso del rimando si vede una volta sulla pagina successiva");
prova(!str_contains($http('/mydibest/area_personale.php', null, $jar2)['corpo'], 'Non hai sezioni del pannello da gestire'), "e poi non c'è più");
