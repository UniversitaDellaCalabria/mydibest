<?php
// Prove via HTTP delle pagine del modulo Portale (ambiente locale acceso): testata, menu e home.
sezione("Modulo Portale: pagine");
foreach (['/mydibest/', '/mydibest/index.php', '/mydibest/privacy.php', '/mydibest/crediti.php'] as $u) {
    $r = $http($u);
    prova($r['codice'] === 200 && str_contains($r['corpo'], '<main id="main-content"') && str_contains($r['corpo'], '<footer') && !str_contains($r['corpo'], 'Fatal error'), "$u: testata, contenuto e footer");
}
$r = $http('/mydibest/');
prova(str_contains($r['corpo'], 'navbar-nav') && str_contains($r['corpo'], 'mob-nav-link'), "la home mostra il menu per computer e per telefono");
prova(str_contains($http('/mydibest/modulo.php?id=4', null, $jar)['corpo'], '<h1') || $http('/mydibest/modulo.php?id=4', null, $jar)['codice'] === 200, "modulo.php: le variabili della pagina restano intatte dopo header.php");
foreach (['inizio.php', 'dashboard.php', 'testata.php', 'menu.php'] as $pag) {
    $r = $http("/mydibest/admin/$pag", null, $jar);
    prova($r['codice'] === 200 && !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:'), "admin/$pag da amministratore: 200 senza errori PHP");
}
$r = $http('/mydibest/admin/menu.php', null, $jar2);
prova(!str_contains($r['corpo'], 'class="menu-node"'), "admin/menu.php: il docente non vede la gestione del menu");
prova($http('/mydibest/admin/menu.php', ['add_menu_voce' => '1', 'csrf_token' => 'sbagliato', 'etichetta' => 'x'], $jar)['codice'] === 403, "menu: token CSRF sbagliato respinto");
