<?php
// Prove via HTTP delle due pagine dei contatti (ambiente locale acceso): contatti.php (pubblica) e admin/contatti.php (pannello), che passano dal Nucleo
// (App\Http\Nucleo, guscio + Pagina). È la rete di sicurezza che si porta dietro la conversione: senza, la prossima modifica le rompe in silenzio.
// Il file è «pagine_» e non «contatti» perché serve $http (il lanciatore lo definisce dopo le prove in-process). Usa $jar (amministratore), $jar2 (docente) e $loc.
sezione("Contatti: pagina pubblica e pagina del pannello (Nucleo)");

$loc->query("DELETE FROM contatti_studenti WHERE titolo LIKE 'PROVA-NUCLEO%'");
$loc->query("INSERT INTO contatti_studenti (titolo, per_cosa, luogo, ordine, visibile) VALUES ('PROVA-NUCLEO visibile <b>x</b>', 'Per cosa visibile', 'Edificio \"A\"', 990, 1), ('PROVA-NUCLEO nascosto', 'Per cosa nascosto', '', 991, 0)");
$conta_nuc = fn(string $titolo): int => (int)($loc->query("SELECT COUNT(*) n FROM contatti_studenti WHERE titolo = '" . $loc->real_escape_string($titolo) . "'")->fetch_assoc()['n'] ?? 0);
$token_da = static function (string $corpo): string {
    // il campo nascosto dei moduli, oppure la riga JavaScript della testata (anche la pagina del 403 la porta)
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $corpo, $m) || preg_match('/csrf_token[\'"],\s*"([a-f0-9]+)"/', $corpo, $m)) return $m[1];
    return '';
};

// ── La pagina pubblica ──
$r = $http('/mydibest/contatti.php');
prova($r['codice'] === 200 && str_contains($r['corpo'], '</html>') && !preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']), "contatti.php senza accesso: 200 senza errori PHP");
prova(str_contains($r['corpo'], 'PROVA-NUCLEO visibile') && str_contains($r['corpo'], 'Per cosa visibile'), "contatti.php: il contatto visibile c'è");
prova(!str_contains($r['corpo'], 'PROVA-NUCLEO nascosto') && !str_contains($r['corpo'], 'Per cosa nascosto'), "contatti.php: il contatto nascosto non c'è");
prova(str_contains($r['corpo'], '&lt;b&gt;x&lt;/b&gt;') && !str_contains($r['corpo'], 'visibile <b>x</b>') && str_contains($r['corpo'], 'Edificio &quot;A&quot;'), "contatti.php: i testi dei contatti escono con l'escape");
prova($http('/mydibest/contatti')['codice'] === 200 && $http('/mydibest/contatti', null, $jar2)['codice'] === 200, "contatti senza «.php», e anche da una persona collegata: 200");

// ── Il pannello: chi non è amministratore ──
$r_adm = $http('/mydibest/admin/contatti.php?modifica=0', null, $jar);
$token_adm = $token_da($r_adm['corpo']);
prova($r_adm['codice'] === 200 && str_contains($r_adm['corpo'], '</html>') && str_contains($r_adm['corpo'], 'Contatti per gli studenti') && str_contains($r_adm['corpo'], 'PROVA-NUCLEO nascosto') && $token_adm !== '', "admin/contatti.php da amministratore: 200, con l'elenco e il modulo del nuovo contatto");
prova(str_contains($r_adm['corpo'], 'id="contenuto-pannello"') && !preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r_adm['corpo']), "admin/contatti.php: dentro il layout del pannello, senza errori PHP");
$r_doc = $http('/mydibest/admin/contatti.php', null, $jar2);
prova($r_doc['codice'] === 403 && !str_contains($r_doc['corpo'], 'Aggiungi un contatto') && !str_contains($r_doc['corpo'], 'PROVA-NUCLEO nascosto'), "admin/contatti.php da docente: 403 pulito, nessuna mezza pagina", "risposta " . $r_doc['codice']);
prova(str_contains($r_doc['corpo'], 'Non hai i permessi') && substr_count($r_doc['corpo'], '</html>') === 1 && substr_count($r_doc['corpo'], '<html') === 1, "admin/contatti.php da docente: una sola pagina, col messaggio di accesso negato");
$token_doc = $token_da($r_doc['corpo']);
$r = $http('/mydibest/admin/contatti.php', ['csrf_token' => $token_doc, 'salva' => 0, 'titolo' => 'PROVA-NUCLEO dal docente', 'visibile' => 1], $jar2);
prova($r['codice'] === 403 && $conta_nuc('PROVA-NUCLEO dal docente') === 0, "POST di un docente (con un token valido per lui): 403 e nessun effetto della logica", "risposta " . $r['codice']);
$r = $http('/mydibest/admin/contatti.php', ['esempi' => 1, 'csrf_token' => $token_doc], $jar2);
prova($r['codice'] === 403 && (int)$loc->query("SELECT COUNT(*) n FROM contatti_studenti WHERE titolo = 'Segreteria didattica' AND ordine = 1 AND visibile = 0 AND per_cosa LIKE 'Certificati%'")->fetch_assoc()['n'] === 0, "POST «parti dagli esempi» di un docente: nessun effetto");
$r = $http('/mydibest/admin/contatti.php');
prova($r['codice'] === 302 && str_contains($r['dove'], 'saml_login.php'), "admin/contatti.php senza accesso: rimando al login", "risposta " . $r['codice'] . ' ' . $r['dove']);
$r = $http('/mydibest/admin/contatti.php', ['csrf_token' => 'x', 'salva' => 0, 'titolo' => 'PROVA-NUCLEO anonimo']);
prova($r['codice'] === 302 && $conta_nuc('PROVA-NUCLEO anonimo') === 0, "POST senza accesso: rimando al login, nessun effetto");

// ── Il pannello: l'amministratore ──
$r = $http('/mydibest/admin/contatti.php', ['salva' => 0, 'titolo' => 'PROVA-NUCLEO senza token', 'visibile' => 1], $jar);
prova($r['codice'] === 403 && $conta_nuc('PROVA-NUCLEO senza token') === 0, "POST senza token: rifiutato (403), niente salvato", "risposta " . $r['codice']);
$r = $http('/mydibest/admin/contatti.php', ['csrf_token' => 'sbagliato', 'salva' => 0, 'titolo' => 'PROVA-NUCLEO token sbagliato'], $jar);
prova($r['codice'] === 403 && $conta_nuc('PROVA-NUCLEO token sbagliato') === 0, "POST con un token sbagliato: rifiutato, niente salvato");
$r = $http('/mydibest/admin/contatti.php?p_id=1', ['csrf_token' => $token_adm, 'salva' => 0, 'titolo' => 'PROVA-NUCLEO salvato', 'per_cosa' => 'Salvato col token', 'ordine' => 992, 'visibile' => 1], $jar);
prova(in_array($r['codice'], [302, 303], true) && str_contains($r['dove'], 'contatti.php?p_id='), "POST con il token: rimando all'elenco", $r['codice'] . ' ' . $r['dove']);
prova($conta_nuc('PROVA-NUCLEO salvato') === 1, "POST con il token: il contatto è salvato");
$r = $http('/mydibest/admin/contatti.php', null, $jar);
prova(str_contains($r['corpo'], 'Contatto aggiunto.') && str_contains($r['corpo'], 'PROVA-NUCLEO salvato'), "dopo il salvataggio: il messaggio si vede e il contatto è nell'elenco");
$r = $http('/mydibest/admin/contatti.php', null, $jar);
prova(!str_contains($r['corpo'], 'Contatto aggiunto.'), "il messaggio si vede una volta sola");
prova(str_contains($http('/mydibest/contatti.php')['corpo'], 'PROVA-NUCLEO salvato'), "il contatto salvato (visibile) compare nella pagina pubblica");
$id_salvato = (int)($loc->query("SELECT id FROM contatti_studenti WHERE titolo = 'PROVA-NUCLEO salvato'")->fetch_assoc()['id'] ?? 0);
$r = $http('/mydibest/admin/contatti.php', ['csrf_token' => $token_adm, 'mostra' => $id_salvato, 'valore' => 0], $jar);
prova(in_array($r['codice'], [302, 303], true) && !str_contains($http('/mydibest/contatti.php')['corpo'], 'PROVA-NUCLEO salvato'), "«nascondi» dal pannello: sparisce dalla pagina pubblica");
$r = $http('/mydibest/admin/contatti.php', ['csrf_token' => $token_adm, 'salva' => $id_salvato, 'titolo' => '', 'visibile' => 1], $jar);
prova(in_array($r['codice'], [302, 303], true) && $conta_nuc('PROVA-NUCLEO salvato') === 1 && str_contains($http('/mydibest/admin/contatti.php', null, $jar)['corpo'], 'Scrivi a chi ci si rivolge'), "un titolo vuoto non si salva e il messaggio d'errore si vede");
$r = $http('/mydibest/admin/contatti.php', ['csrf_token' => $token_adm, 'elimina' => $id_salvato], $jar);
prova(in_array($r['codice'], [302, 303], true) && $conta_nuc('PROVA-NUCLEO salvato') === 0, "elimina dal pannello");
$riga_audit = $loc->query("SELECT azione FROM log_attivita WHERE azione IN ('Contatto aggiunto', 'Contatto eliminato') ORDER BY id DESC LIMIT 2");
$azioni_audit = []; while ($riga_audit && ($ra = $riga_audit->fetch_assoc())) $azioni_audit[] = $ra['azione'];
prova(in_array('Contatto aggiunto', $azioni_audit, true) && in_array('Contatto eliminato', $azioni_audit, true), "le operazioni sono nel registro (audit)", implode(', ', $azioni_audit));

$loc->query("DELETE FROM contatti_studenti WHERE titolo LIKE 'PROVA-NUCLEO%'");
// L'ultimo POST ha lasciato un messaggio in attesa («Contatto eliminato»): lo vede la prima pagina del pannello che si apre dopo. Lo si consuma qui,
// altrimenti finisce in una prova che confronta due richieste uguali (pagine_nucleo.php) e le fa sembrare diverse.
$http('/mydibest/admin/contatti.php', null, $jar);
