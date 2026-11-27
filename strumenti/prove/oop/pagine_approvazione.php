<?php
// Prove via HTTP (ambiente locale acceso) del giro completo di una richiesta di pubblicazione con l'approvazione: si compongono le tre liste di persone e si accende
// l'approvazione dal pannello, un docente invia, il secondo approvatore approva, il primo trova «già decisa», le email partono a chi di dovere, l'ufficio la manda nel
// file Excel e un operatore limitato da «Chi compila cosa» compila solo le sue colonne. Chiamato da esegui.php: usa $http, $jar (amministratore), $loc, $SITO, $BASE.
sezione("Approvazione delle pubblicazioni: il giro completo (invia → approva → file Excel → «Chi compila cosa»)");

$ap_errore = fn(array $r): bool => (bool) preg_match('/<b>(Fatal error|Parse error|Warning|Deprecated|Notice)<\/b>|Uncaught /', $r['corpo']);
$ap_apre = fn(array $r, string ...$testi): bool => $r['codice'] === 200 && !$ap_errore($r) && str_contains($r['corpo'], '</html>') && array_reduce($testi, fn($ok, $t) => $ok && str_contains($r['corpo'], $t), true);
$ap_accedi = function (int $u) use ($http): string { $j = tempnam(sys_get_temp_dir(), 'jar'); $http('/__accesso?u=' . $u, null, $j); return $j; };
$ap_token = fn(array $r): string => preg_match('/name="csrf_token" value="([^"]+)"/', $r['corpo'], $m) ? $m[1] : '';
$ap_uno = fn(string $q) => $loc->query($q)->fetch_row()[0] ?? null;

// ── Preparazione: quello che c'era si rimette alla fine ──
$http('/mydibest/admin/amministrazione.php', null, $jar); // lo schema si aggiorna da solo (v146)
$ap_col = ['pubblicazioni_chi_puo_inviare', 'pubblicazioni_aperta', 'pubblicazioni_email_notifica', 'pubblicazioni_ricevuta', 'pubblicazioni_allegati_obbligatori', 'pubblicazioni_giorni_minimi', 'pubblicazioni_approvazione_attiva'];
$ap_imp_prima = $loc->query('SELECT ' . implode(', ', $ap_col) . ' FROM impostazioni_sistema WHERE id = 1')->fetch_assoc() ?: [];
$ap_persone_prima = $loc->query('SELECT * FROM pubblicazioni_persone')->fetch_all(MYSQLI_ASSOC);
$ap_colonne_prima = $loc->query('SELECT * FROM pubblicazioni_colonne_utenti')->fetch_all(MYSQLI_ASSOC);
$ap_ids = '99981, 99982, 99983, 99984, 99985';
$ap_titolo = 'Richiesta di prova GIRO approvazione';

$ap_pulisci = function () use ($loc, $SITO, $ap_ids, $ap_titolo): void {
    foreach ($loc->query("SELECT a.percorso FROM pubblicazioni_allegati a JOIN pubblicazioni p ON p.id = a.pubblicazione_id WHERE p.titolo = '$ap_titolo'")->fetch_all(MYSQLI_ASSOC) as $x) {
        if (str_starts_with($x['percorso'], 'uploads/pubblicazioni/') && is_file($SITO . '/' . $x['percorso'])) unlink($SITO . '/' . $x['percorso']);
    }
    $loc->query("DELETE FROM pubblicazioni WHERE titolo = '$ap_titolo'");
    $loc->query("DELETE FROM pubblicazioni_persone WHERE persona_id LIKE 'PROVA-GIRO-%'");
    $loc->query("DELETE FROM pubblicazioni_colonne_utenti WHERE utente_id IN ($ap_ids)");
    $loc->query("DELETE FROM abilitazioni_ambito WHERE utente_id IN ($ap_ids)");
    $loc->query("DELETE FROM utenti WHERE id IN ($ap_ids)");
    $loc->query("DELETE FROM personale_ateneo WHERE id LIKE 'PROVA-GIRO-%'");
    $loc->query("DELETE FROM log_email WHERE destinatario LIKE '%.giro@example.org'");
};
$ap_pulisci();
$loc->query('DELETE FROM pubblicazioni_persone');
$loc->query('DELETE FROM pubblicazioni_colonne_utenti');
$loc->query("UPDATE impostazioni_sistema SET pubblicazioni_chi_puo_inviare = 'docenti,pta', pubblicazioni_aperta = 1, pubblicazioni_email_notifica = 'comunicazione.giro@example.org', pubblicazioni_ricevuta = 1,
    pubblicazioni_allegati_obbligatori = 0, pubblicazioni_giorni_minimi = 0, pubblicazioni_approvazione_attiva = 0 WHERE id = 1");

// Due approvatori, due operatori (il primo è anche nella lista «Chi lavora»), una persona informata e il docente che chiede
$ap_docenti = (int) $ap_uno("SELECT id FROM ruoli WHERE nome = 'Docenti'");
foreach ([['A', 'Primo', 'Anna', 'appr1'], ['B', 'Secondo', 'Bruno', 'appr2'], ['C', 'Operatrice', 'Carla', 'oper1'], ['D', 'Informato', 'Dario', 'info']] as [$k, $cog, $nome, $email]) {
    $loc->query("INSERT INTO personale_ateneo (id, cognome, nome, email, attivo) VALUES ('PROVA-GIRO-$k', '$cog', '$nome', '$email.giro@example.org', 1)");
}
$loc->query("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id, persona_id) VALUES
    (99981, 'APPGIRO99981XXXX', 'Anna', 'Primo', 'appr1.giro@example.org', 4, 'PROVA-GIRO-A'),
    (99982, 'APPGIRO99982XXXX', 'Bruno', 'Secondo', 'appr2.giro@example.org', 4, 'PROVA-GIRO-B'),
    (99983, 'APPGIRO99983XXXX', 'Carla', 'Operatrice', 'oper1.giro@example.org', 4, 'PROVA-GIRO-C'),
    (99984, 'APPGIRO99984XXXX', 'Elio', 'Operatore', 'oper2.giro@example.org', 4, NULL)");
$loc->query("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id, ruoli_secondari) VALUES (99985, 'APPGIRO99985XXXX', 'Franca', 'Richiedente', 'docente.giro@example.org', 4, '$ap_docenti')");
$loc->query("INSERT INTO abilitazioni_ambito (utente_id, tipo, pagina_id) VALUES (99983, 'modulo_pubblicazioni', 0), (99984, 'modulo_pubblicazioni', 0)");

// ── Dal pannello: le tre liste dall'anagrafe e l'approvazione accesa per ultima ──
$ap_imp_url = '/mydibest/admin/amministrazione.php?tab=impostazioni';
$ap_tok_admin = $ap_token($http($ap_imp_url, null, $jar));
foreach (['approvatore' => ['PROVA-GIRO-A', 'PROVA-GIRO-B'], 'ufficio' => ['PROVA-GIRO-C'], 'conoscenza' => ['PROVA-GIRO-D']] as $lista => $persone) {
    foreach ($persone as $p) $http($ap_imp_url, ['csrf_token' => $ap_tok_admin, 'pb_persona_aggiungi' => 1, 'pb_lista' => $lista, 'pb_persona' => $p], $jar);
}
prova((int) $ap_uno("SELECT COUNT(*) FROM pubblicazioni_persone") === 4, "impostazioni: le persone dell'anagrafe si aggiungono alle tre liste", (string) $ap_uno("SELECT COUNT(*) FROM pubblicazioni_persone"));
$http($ap_imp_url, ['csrf_token' => $ap_tok_admin, 'salva_approvazione_pubblicazioni' => 1, 'approvazione_attiva' => 1], $jar);
prova((int) $ap_uno("SELECT pubblicazioni_approvazione_attiva FROM impostazioni_sistema WHERE id = 1") === 1, "impostazioni: l'approvazione si accende (c'è almeno un approvatore con account)");

// ── Il docente invia ──
$ap_email_da = (int) $ap_uno("SELECT COALESCE(MAX(id), 0) FROM log_email");
$ap_email = function (string $chi) use ($loc, &$ap_email_da): int {
    return (int) $loc->query("SELECT COUNT(*) FROM log_email WHERE id > $ap_email_da AND destinatario = '$chi.giro@example.org'")->fetch_row()[0];
};
$ap_tutte = fn() => array_combine($n = ['appr1', 'appr2', 'oper1', 'info', 'docente', 'comunicazione'], array_map($ap_email, $n));
$j_doc = $ap_accedi(99985);
$rp = '/mydibest/richiesta_pubblicazione.php';
$ap_pdf = tempnam(sys_get_temp_dir(), 'pdf');
file_put_contents($ap_pdf, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
$ch = curl_init($BASE . $rp);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $j_doc, CURLOPT_COOKIEFILE => $j_doc, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['csrf_token' => $ap_token($http($rp, null, $j_doc)), 'ho_letto' => 1, 'data_evento' => date('Y-m-d', strtotime('+40 days')), 'modalita' => 'online', 'url_docente' => 'https://www.unical.it/giro',
        'titolo' => $ap_titolo, 'sottotitolo' => 'Una frase', 'descrizione' => 'Descrizione del giro', 'per_studenti' => 1, 'tag' => 't', 'note' => 'n', 'data_inizio_pubblicazione' => date('Y-m-d', strtotime('+10 days')),
        'locandina' => new CURLFile($ap_pdf, 'application/pdf', 'manifesto.pdf')]]);
curl_exec($ch);
$ap_dove = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);
unlink($ap_pdf);
$ap_riga = fn() => $loc->query("SELECT * FROM pubblicazioni WHERE titolo = '$ap_titolo'")->fetch_assoc() ?: [];
$id_g = (int) ($ap_riga()['id'] ?? 0);
prova($id_g > 0 && $ap_riga()['stato'] === 'da_approvare', "invio: la richiesta nasce «in attesa di approvazione»", (string) ($ap_riga()['stato'] ?? 'nessuna riga'));
$r = $http($ap_dove !== '' ? $ap_dove : $rp, null, $j_doc);
prova($ap_apre($r, 'in attesa di approvazione') && !str_contains($r['corpo'], 'arrivata all&#039;ufficio comunicazione') && !str_contains($r['corpo'], "arrivata all'ufficio comunicazione"),
    "invio: la conferma dice che aspetta l'approvazione, non che è arrivata all'ufficio");
$e = $ap_tutte();
prova($e['appr1'] === 1 && $e['appr2'] === 1 && $e['docente'] === 1 && $e['oper1'] === 0 && $e['info'] === 0 && $e['comunicazione'] === 0, "invio: email ai due approvatori e a chi invia, nessuna all'ufficio né per conoscenza", json_encode($e));

// In attesa non si manda nel file Excel
$u_pb = '/mydibest/admin/pubblicazioni.php';
$j_op1 = $ap_accedi(99983);
$ap_tok_op1 = $ap_token($http($u_pb . '?id=' . $id_g, null, $j_op1));
$http($u_pb, ['csrf_token' => $ap_tok_op1, 'azione' => 'invia_excel', 'id' => $id_g, 'da' => 'scheda'], $j_op1);
prova((int) $ap_riga()['nel_monitoraggio'] === 0, "in attesa: l'ufficio non può mandarla nel file Excel");

// ── Approva il secondo approvatore, il primo la trova già decisa ──
$u_ap = '/mydibest/approva_pubblicazioni.php';
$r = $http($u_ap . '?id=' . $id_g);
prova(in_array($r['codice'], [302, 303], true) && str_contains($r['dove'], 'saml_login.php'), "approva_pubblicazioni.php senza accesso: porta al login", $r['codice'] . ' ' . $r['dove']);
$r = $http($u_ap . '?id=' . $id_g, null, $j_op1);
prova($r['codice'] === 403 && !str_contains($r['corpo'], $ap_titolo), "approva_pubblicazioni.php: chi non è nella lista «Chi approva» non entra", (string) $r['codice']);
$j_a1 = $ap_accedi(99981);
$j_a2 = $ap_accedi(99982);
$pag_a1 = $http($u_ap . '?id=' . $id_g, null, $j_a1); // la apre prima che l'altro decida: il suo «Approva» arriverà dopo
$pag_a2 = $http($u_ap . '?id=' . $id_g, null, $j_a2);
prova($ap_apre($pag_a2, $ap_titolo, 'name="approva"', 'name="respingi"', 'allegato_pubblicazione.php?p=' . $id_g), "approva_pubblicazioni.php: l'approvatore vede il riepilogo, la locandina e i due pulsanti");
$ap_email_da = (int) $ap_uno("SELECT COALESCE(MAX(id), 0) FROM log_email");
$r = $http($u_ap . '?id=' . $id_g, ['csrf_token' => $ap_token($pag_a2), 'id' => $id_g, 'approva' => 1], $j_a2);
prova(in_array($r['codice'], [302, 303], true) && $ap_riga()['stato'] === 'ricevuta' && (int) $ap_riga()['decisa_da'] === 99982, "il secondo approvatore approva: stato «Approvata» e chi ha deciso", $r['codice'] . ' ' . ($ap_riga()['stato'] ?? ''));
$e = $ap_tutte();
prova($e['oper1'] === 1 && $e['comunicazione'] === 1 && $e['info'] === 1 && $e['docente'] === 1 && $e['appr1'] === 1 && $e['appr2'] === 0,
    "approvata: email all'ufficio (lista e indirizzi), per conoscenza, a chi ha inviato e all'altro approvatore; non a chi ha deciso", json_encode($e));
$ap_email_da = (int) $ap_uno("SELECT COALESCE(MAX(id), 0) FROM log_email");
$http($u_ap . '?id=' . $id_g, ['csrf_token' => $ap_token($pag_a1), 'id' => $id_g, 'approva' => 1], $j_a1);
$r = $http($u_ap . '?id=' . $id_g, null, $j_a1);
prova($ap_apre($r, 'già stata approvata', 'Bruno') && !str_contains($r['corpo'], 'name="approva"') && (int) $ap_riga()['decisa_da'] === 99982 && array_sum($ap_tutte()) === 0,
    "il primo approvatore trova «già approvata da …»: niente cambia e non parte nessuna email", json_encode($ap_tutte()));

// ── L'ufficio la manda nel file Excel; «Chi compila cosa» limita i due operatori ──
$http($u_pb, ['csrf_token' => $ap_tok_op1, 'azione' => 'invia_excel', 'id' => $id_g, 'da' => 'scheda'], $j_op1);
prova((int) $ap_riga()['nel_monitoraggio'] === 1, "approvata: l'operatore la manda nel file Excel");
$u_campi = '/mydibest/admin/amministrazione.php?tab=campi';
$r = $http($u_campi, null, $jar);
prova($ap_apre($r, 'Chi compila cosa', 'name="chi[j_tipo_iniziativa][]"'), "Campi e colonne: c'è la griglia «Chi compila cosa»");
$http($u_campi, ['csrf_token' => $ap_token($r), 'salva_chi_compila' => 1, 'chi' => ['j_tipo_iniziativa' => [99983], 'c_altri_referenti' => [99984]]], $jar);
prova((int) $ap_uno("SELECT COUNT(*) FROM pubblicazioni_colonne_utenti") === 2, "Campi e colonne: le assegnazioni si salvano", (string) $ap_uno("SELECT COUNT(*) FROM pubblicazioni_colonne_utenti"));

$r = $http($u_pb . '?id=' . $id_g, null, $j_op1);
prova($ap_apre($r, 'name="j_tipo_iniziativa"') && !str_contains($r['corpo'], 'name="c_altri_referenti"'), "operatore limitato, scheda: compila la sua colonna e non quella dell'altro");
$r = $http($u_pb . '?vista=da_compilare', null, $j_op1);
prova($ap_apre($r, $ap_titolo, "c[$id_g][j_tipo_iniziativa]") && !str_contains($r['corpo'], "c[$id_g][c_altri_referenti]"), "operatore limitato, «Da compilare»: nella scheda della richiesta c'è solo la sua colonna");
$ap_salva = fn(array $celle) => $http($u_pb, ['csrf_token' => $ap_tok_op1, 'azione' => 'salva', 'id' => $id_g, 'da' => 'scheda', 'stato' => 'ricevuta'] + $celle, $j_op1);
$ap_salva(['j_tipo_iniziativa' => 'Seminario', 'c_altri_referenti' => 'Intruso']);
prova((string) $ap_riga()['c_altri_referenti'] === '' && (string) $ap_riga()['j_tipo_iniziativa'] !== 'Intruso', "una colonna non sua viene rifiutata dal server", json_encode([$ap_riga()['c_altri_referenti'], $ap_riga()['j_tipo_iniziativa']]));
$prima_col = (string) $ap_riga()['j_tipo_iniziativa'];
$ap_salva(['c_altri_referenti' => 'Intruso']);
prova((string) $ap_riga()['c_altri_referenti'] === '' && (string) $ap_riga()['j_tipo_iniziativa'] === $prima_col, "forzando il POST con la sola colonna dell'altro non cambia niente");
$ap_salva(['j_tipo_iniziativa' => 'Seminario divulgativo']);
prova((string) $ap_riga()['j_tipo_iniziativa'] === 'Seminario divulgativo' && (string) $ap_riga()['c_altri_referenti'] === '', "l'operatore compila la sua colonna e si salva", (string) $ap_riga()['j_tipo_iniziativa']);

// ── «Entra come…» (sola lettura) come operatore limitato e come approvatore ──
$ap_entra = function (int $u) use ($http, $ap_accedi, $ap_token): array {
    $j = $ap_accedi(1);
    return [$j, $http('/mydibest/admin/simula.php', ['csrf_token' => $ap_token($http('/mydibest/admin/simula.php', null, $j)), 'utente_id' => $u], $j)];
};
[$j_sim_op, $r] = $ap_entra(99983);
prova(in_array($r['codice'], [302, 303], true), "«Entra come…» l'operatore limitato: la simulazione parte", $r['codice'] . ' ' . $r['dove']);
$r = $http($u_pb . '?id=' . $id_g, null, $j_sim_op);
prova($ap_apre($r, 'name="j_tipo_iniziativa"', 'simula_esci.php') && !str_contains($r['corpo'], 'name="c_altri_referenti"'), "«Entra come…» operatore: vede la scheda con la sola sua colonna", (string) $r['codice']);
$http($u_pb, ['csrf_token' => $ap_token($r), 'azione' => 'salva', 'id' => $id_g, 'da' => 'scheda', 'stato' => 'ricevuta', 'j_tipo_iniziativa' => 'Simulata'], $j_sim_op);
prova((string) $ap_riga()['j_tipo_iniziativa'] === 'Seminario divulgativo', "«Entra come…» operatore: è in sola lettura, non salva", (string) $ap_riga()['j_tipo_iniziativa']);

$loc->query("UPDATE pubblicazioni SET stato = 'da_approvare', nel_monitoraggio = 0, decisa_da = NULL, decisa_il = NULL WHERE id = $id_g");
[$j_sim_ap] = $ap_entra(99981);
$r = $http('/mydibest/area_personale.php', null, $j_sim_ap);
prova($ap_apre($r, 'Richieste di pubblicazione da approvare', 'approva_pubblicazioni.php'), "«Entra come…» approvatore: nell'area personale c'è la tessera delle richieste da approvare", (string) $r['codice']);
$r = $http($u_ap . '?id=' . $id_g, null, $j_sim_ap);
prova($ap_apre($r, $ap_titolo, 'name="approva"', 'simula_esci.php'), "«Entra come…» approvatore: apre la richiesta da approvare", (string) $r['codice']);
$ap_email_da = (int) $ap_uno("SELECT COALESCE(MAX(id), 0) FROM log_email");
$http($u_ap . '?id=' . $id_g, ['csrf_token' => $ap_token($r), 'id' => $id_g, 'approva' => 1], $j_sim_ap);
prova($ap_riga()['stato'] === 'da_approvare' && $ap_riga()['decisa_da'] === null && array_sum($ap_tutte()) === 0, "«Entra come…» approvatore: è in sola lettura, non approva e non parte nessuna email", (string) $ap_riga()['stato']);

// ── Pulizia: persone, richiesta, file, email e impostazioni di prima ──
$ap_pulisci();
foreach (['pubblicazioni_persone' => $ap_persone_prima, 'pubblicazioni_colonne_utenti' => $ap_colonne_prima] as $tab => $righe) {
    $loc->query("DELETE FROM $tab");
    foreach ($righe as $x) {
        $st = $loc->prepare("INSERT INTO $tab (" . implode(', ', array_keys($x)) . ') VALUES (' . implode(', ', array_fill(0, count($x), '?')) . ')');
        $v = array_values($x);
        $st->bind_param(str_repeat('s', count($v)), ...$v);
        $st->execute();
    }
}
if ($ap_imp_prima) {
    $st = $loc->prepare('UPDATE impostazioni_sistema SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($ap_imp_prima))) . ' WHERE id = 1');
    $v = array_values($ap_imp_prima);
    $st->bind_param(str_repeat('s', count($v)), ...$v);
    $st->execute();
}
foreach ([$j_doc, $j_op1, $j_a1, $j_a2, $j_sim_op, $j_sim_ap] as $j) is_file($j) && unlink($j);
