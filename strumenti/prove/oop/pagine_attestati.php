<?php
// Prove via HTTP delle pagine del modulo Attestati (ambiente locale acceso): verifica, attestati, elenco degli studenti, cron.
sezione("Modulo Attestati: pagine");
$senza_errori = fn(array $r) => !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:') && !str_contains($r['corpo'], 'Uncaught');

// Verifica pubblica
$r = $http('/mydibest/verifica_attestato.php');
prova($r['codice'] === 200 && $senza_errori($r) && str_contains($r['corpo'], 'Verifica attestato') && !str_contains($r['corpo'], 'Attestato valido'), "verifica_attestato.php: maschera di ricerca");
$r = $http('/mydibest/verifica_attestato.php?c=AT-NONESISTE9');
prova($r['codice'] === 200 && $senza_errori($r) && str_contains($r['corpo'], 'Codice non riconosciuto'), "verifica_attestato.php: codice sconosciuto");
$r = $http('/mydibest/verifica_attestato.php?c=' . urlencode('a$b'));
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Codice non riconosciuto'), "verifica_attestato.php: codice con caratteri non validi");

// Attestato personale: serve l'accesso, la presenza e il titolare
$r = $http('/mydibest/stampa_attestato.php');
prova(str_contains($r['corpo'], 'Codice di sicurezza non valido.'), "stampa_attestato.php: senza codice");
$r = $http('/mydibest/stampa_attestato.php?code=NONESISTE');
prova(str_contains($r['corpo'], 'Nessun dato trovato per questo codice.'), "stampa_attestato.php: codice sconosciuto");
$r = $http('/mydibest/stampa_attestato.php?code=OP-PROVA001', null, $jar);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Attestato non disponibile') && $senza_errori($r), "stampa_attestato.php: senza presenza non si stampa");
$r = $http('/mydibest/stampa_attestato.php?code=D1');
prova(str_contains($r['corpo'], 'Accesso negato'), "stampa_attestato.php: anonimo non vede l'attestato di altri");
$r = $http('/mydibest/stampa_attestato.php?code=D1', null, $jar);
prova($r['codice'] === 200 && str_starts_with(ltrim($r['corpo']), '<!DOCTYPE html>') && str_contains($r['corpo'], 'cert-container') && str_contains($r['corpo'], 'verifica_attestato.php?c=D1') && $senza_errori($r), "stampa_attestato.php: l'amministratore stampa l'attestato (codice e QR di verifica)");
$r = $http('/mydibest/verifica_attestato.php?c=OP-PROVA001');
prova(str_contains($r['corpo'], 'Codice non riconosciuto'), "verifica_attestato.php: la prenotazione senza presenza non ha attestato valido");

// Attestati di gruppo ed elenco degli studenti
foreach (['attestati_gruppo.php?code=OP-PROVA001', 'elenco_studenti.php?code=OP-PROVA001'] as $pag) {
    $r = $http("/mydibest/$pag");
    prova($r['codice'] === 302 && str_contains($r['dove'], 'saml_login.php'), "$pag senza accesso: rimando al login");
}
foreach (['attestati_gruppo.php?code=a', 'elenco_studenti.php'] as $pag) {
    prova($http("/mydibest/$pag")['codice'] === 400, "$pag: codice non valido (400)");
}
foreach (['attestati_gruppo.php?code=NONESISTE', 'elenco_studenti.php?code=NONESISTE'] as $pag) {
    prova($http("/mydibest/$pag", null, $jar)['codice'] === 403, "$pag: prenotazione sconosciuta (403)");
}
$r = $http('/mydibest/elenco_studenti.php?code=OP-PROVA001', null, $jar);
prova($r['codice'] === 200 && $senza_errori($r) && str_contains($r['corpo'], "Documenti da consegnare prima dell'attività") && str_contains($r['corpo'], 'name="stud_cognome[]"') && str_contains($r['corpo'], 'name="file_autorizzazione"'), "elenco_studenti.php: modulo dell'elenco e dell'autorizzazione (attività FSL)");
$r = $http('/mydibest/elenco_studenti.php?code=OP-PROVA001&modello=1', null, $jar);
prova($r['codice'] === 200 && (str_starts_with($r['corpo'], 'PK') || str_contains($r['corpo'], 'Cognome;Nome')), "elenco_studenti.php: modello da scaricare");
prova($http('/mydibest/elenco_studenti.php?code=OP-PROVA001', ['csrf_token' => 'sbagliato', 'salva_elenco' => '1'], $jar)['codice'] === 403, "elenco_studenti.php: token CSRF sbagliato respinto");
$r = $http('/mydibest/attestati_gruppo.php?code=OP-PROVA001', null, $jar);
prova($r['codice'] === 200 && $senza_errori($r), "attestati_gruppo.php: prenotazione senza presenza");

// Cron
prova($http('/mydibest/cron_attestati.php')['codice'] === 403 && $http('/mydibest/cron_attestati.php', null, $jar2)['codice'] === 403, "cron_attestati.php: senza accesso o da docente non parte");

// Evento ad accesso libero con presenza statistica (interruttore «Rilascia l'attestato» spento): niente attestato, niente codice mostrato
// (presenza con codice AL-…, creata dal QR; id 99811)
$loc->query("DELETE FROM prenotazioni WHERE codice_prenotazione = 'AL-PROVA811'");
$loc->query("DELETE FROM turni WHERE id = 99811");
$loc->query("DELETE FROM eventi WHERE id = 99811");
$__al_area = (int)($loc->query("SELECT id FROM pagine_eventi WHERE slug = 'openlab'")->fetch_assoc()['id'] ?? 1);
$loc->query("INSERT INTO eventi (id, pagina_id, titolo, tipo, luogo, archiviato, richiede_prenotazione, abilita_presenze, rilascia_attestato) VALUES (99811, $__al_area, 'Seminario libero delle prove', 'evento', 'Aula', 0, 0, 1, 0)");
$loc->query("INSERT INTO turni (id, evento_id, nome_turno, data_turno, max_posti) VALUES (99811, 99811, 'Unico', CURDATE() - INTERVAL 1 DAY, 50)");
$loc->query("INSERT INTO prenotazioni (turno_id, utente_id, codice_prenotazione, stato, num_posti, presente, nome, cognome, email) VALUES (99811, 4, 'AL-PROVA811', 'confermata', 1, 1, 'Luca', 'Insegnante', 'luca.insegnante@example.org')");
$r = $http('/mydibest/stampa_attestato.php?code=AL-PROVA811', null, $jar);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Attestato non previsto') && !str_contains($r['corpo'], 'cert-container') && $senza_errori($r), "stampa_attestato.php: l'evento senza attestato rifiuta, anche con il link salvato");
$r = $http('/mydibest/stampa_ricevuta.php?code=AL-PROVA811', null, $jar);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Presenza registrata') && !str_contains($r['corpo'], 'AL-PROVA811') && !str_contains($r['corpo'], 'CODICE UNIVOCO') && $senza_errori($r), "stampa_ricevuta.php: evento senza prenotazione, il codice non si mostra");
$r = $http('/mydibest/miei_eventi.php', null, $jar2);
$visibile_pl = preg_replace('/href="[^"]*"/', '', $r['corpo']); // il codice resta nell'indirizzo della ricevuta, ma non si legge in pagina
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Seminario libero delle prove') && str_contains($visibile_pl, 'Presenza registrata') && !str_contains($visibile_pl, 'AL-PROVA811') && !str_contains($r['corpo'], 'stampa_attestato.php?code=AL-PROVA811') && $senza_errori($r), "miei_eventi.php: la presenza mostra «Presenza registrata», non il codice AL-…, e non offre l'attestato");
// Con l'interruttore acceso l'attestato esce come prima
$loc->query("UPDATE eventi SET rilascia_attestato = 1 WHERE id = 99811");
$r = $http('/mydibest/stampa_attestato.php?code=AL-PROVA811', null, $jar);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'cert-container') && $senza_errori($r), "stampa_attestato.php: con l'interruttore acceso l'attestato si stampa");
$loc->query("DELETE FROM prenotazioni WHERE codice_prenotazione = 'AL-PROVA811'");
$loc->query("DELETE FROM turni WHERE id = 99811");
$loc->query("DELETE FROM eventi WHERE id = 99811");
