<?php
// Prove di «Da fare per te» per aziende ed enti: convenzione di tesi da completare (dati, firma, risposta alle modifiche, nuovo caricamento).
// Chiamato da esegui.php. Dati di prova con id 9895xx.
sezione("Da fare per te: azienda (convenzione di tesi)");

$q("DELETE FROM convenzioni_tesi WHERE email LIKE '%9894@example.org'");
$q("INSERT INTO convenzioni_tesi (token, stato, origine, azienda, email, aggiornata_il) VALUES
    ('tokaz9894aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'invitata', 'invito', 'Azienda 9894 Spa', 'azienda9894@example.org', NOW() - INTERVAL 3 DAY),
    ('tokaz9894bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'in_carico', 'invito', 'Ente 9894', 'ente9894@example.org', NOW() - INTERVAL 1 DAY)");

$daFare = \App\Core\App::per($conn)->get(\App\Portale\DaFarePersona::class);
$per = fn(array $u) => array_values(array_filter($daFare->gruppi($u), fn($g) => $g['chiave'] === 'convenzioni_azienda'));
$nv = fn(string $e) => ['id' => 77, 'email' => $e, 'email_personalizzata' => 1, 'email_verificata_il' => null];   // email scelta a mano e mai confermata
$cv = fn(string $e) => ['id' => 77, 'email' => $e, 'email_personalizzata' => 1, 'email_verificata_il' => '2026-11-01 10:00:00'];   // confermata con il codice

$g = $per(['email' => 'Azienda9894@example.org']);
prova(count($g) === 1 && $g[0]['n'] === 1 && $g[0]['righe'][0]['testo'] === 'Azienda 9894 Spa' && str_starts_with($g[0]['righe'][0]['link'], 'convenzione_tesi.php?t=tokaz9894aaaa') && $g[0]['righe'][0]['giorni'] === 3, "da fare: l'azienda invitata vede la convenzione da compilare con il link diretto", json_encode($g));
prova($per($nv('azienda9894@example.org')) === [], "T3 da fare: un'email scelta a mano e non confermata non porta la convenzione di tesi dell'azienda");
prova(count($per($cv('azienda9894@example.org'))) === 1, "T3 da fare: con l'email confermata col codice la convenzione di tesi c'è");
prova($per($cv('azienda9894@example.org')) == $g && count($per(['id' => 77, 'email' => 'azienda9894@example.org', 'email_personalizzata' => 0])) === 1, "T3 da fare: chi entra con SSO/SPID/CIE non perde la convenzione di tesi");
prova($per(['email' => 'ente9894@example.org']) === [], "da fare: quando tocca al Dipartimento l'azienda non ha niente da fare");
prova($per(['email' => 'altra9894@example.org']) === [], "da fare: un'altra persona non vede la convenzione");

$q("DELETE FROM convenzioni_tesi WHERE email LIKE '%9894@example.org'");

// T3: l'ufficio convenzioni di tesi e la firma del Direttore si riconoscono dall'email verificata
$uff = (int) ($conn->query("SELECT id FROM uffici WHERE chiave = 'convenzioni_tesi'")->fetch_assoc()['id'] ?? 0);
if ($uff === 0) { $q("INSERT INTO uffici (nome, chiave) VALUES ('Ufficio convenzioni di tesi (prova 9894)', 'convenzioni_tesi')"); $uff = (int) $conn->insert_id; $uff_nuovo = true; }
$q("DELETE FROM uffici_persone WHERE email = 'ufficio9894@example.org'");
$q("INSERT INTO uffici_persone (ufficio_id, email, nominativo) VALUES ($uff, 'ufficio9894@example.org', 'Persona ufficio 9894')");
$q("INSERT INTO convenzioni_tesi (token, stato, origine, azienda, email, aggiornata_il, token_direttore) VALUES ('tokuf9894cccccccccccccccccccccccccccccccccc', 'caricata', 'invito', 'Azienda ufficio 9894', 'azienda9894@example.org', NOW(), NULL)");
$perUff = fn(array $u) => array_values(array_filter($daFare->gruppi($u), fn($g) => $g['chiave'] === 'tesi_ufficio'));
prova(count($perUff($cv('ufficio9894@example.org'))) === 1, "T3 da fare: la persona dell'ufficio con l'email confermata vede le convenzioni di tesi da seguire");
prova(count($perUff(['id' => 77, 'email' => 'ufficio9894@example.org'])) === 1, "T3 da fare: la persona dell'ufficio che entra con SSO/SPID/CIE non perde le convenzioni di tesi da seguire");
prova($perUff($nv('ufficio9894@example.org')) === [], "T3 da fare: un'email scelta a mano uguale a quella di una persona dell'ufficio non porta le convenzioni di tesi");
$svcTesi = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class);
prova($svcTesi->utenteDellUfficio($cv('ufficio9894@example.org')) === true && $svcTesi->utenteDellUfficio($nv('ufficio9894@example.org')) === false, "T3 pagina: admin/convenzioni_tesi.php riconosce l'ufficio solo dall'email verificata");

$q("DELETE FROM convenzioni_tesi WHERE token = 'tokuf9894cccccccccccccccccccccccccccccccccc'");
$q("DELETE FROM uffici_persone WHERE email = 'ufficio9894@example.org'");
if (!empty($uff_nuovo)) $q("DELETE FROM uffici WHERE id = $uff");
