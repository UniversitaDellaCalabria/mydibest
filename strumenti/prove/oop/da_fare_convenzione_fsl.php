<?php
// Prove di «Da fare per te» per la convenzione FSL da caricare (App\Portale\DaFarePersona). Chiamato da esegui.php. Dati di prova con id 988xx.
sezione("Da fare per te: convenzione della scuola da caricare (FSL)");

foreach (["DELETE FROM convenzioni_compilate WHERE id IN (98830, 98831)", "DELETE FROM prenotazioni WHERE id IN (98833, 98834)", "DELETE FROM turni WHERE id = 98832", "DELETE FROM progetti_dettagli WHERE evento_id = 98831", "DELETE FROM eventi WHERE id = 98831", "DELETE FROM pagine_eventi WHERE id = 98830"] as $sql) $q($sql);
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, visibile, colore_primario) VALUES (98830, 'FSL da fare 988', 'fsl_da_fare_988', 'fsl', 1, '#445566')");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve) VALUES (98831, 98830, 'Progetto FSL da fare', 'progetto', 'Prova.')");
$q("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati, data_inizio, data_fine, ore_totali) VALUES (98831, 1, 1, 0, '" . $giorni(40) . "', '" . $giorni(70) . "', 20)");
$q("INSERT INTO turni (id, evento_id, nome_turno, max_posti, richiede_approvazione) VALUES (98832, 98831, 'Edizione 988', 5, 0)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, data_prenotazione, convenzione)
    VALUES (98833, 98832, 'FS-DAF988', 'da_approvare', 'Rosa', 'Docente', 'rosa988@example.org', NOW(), 'no'),
           (98834, 98832, 'FS-DAF989', 'da_approvare', 'Gino', 'Docente', 'gino988@example.org', NOW(), 'no')");
$dati_no = json_encode(['scuola' => ['denominazione' => 'Liceo di prova'], 'convenzione' => 'no']);
$dati_si = json_encode(['scuola' => ['denominazione' => 'Liceo di prova'], 'convenzione' => 'si']);
$q("INSERT INTO convenzioni_compilate (id, token, prenotazione_id, email, dati_json, stato_iter, rivendicata_il) VALUES (98830, 'tokdaf98830', 98833, 'rosa988@example.org', '" . $conn->real_escape_string($dati_no) . "', '', NOW()),
    (98831, 'tokdaf98831', 98834, 'gino988@example.org', '" . $conn->real_escape_string($dati_si) . "', '', NOW())");

$daFare = \App\Core\App::per($conn)->get(\App\Portale\DaFarePersona::class);
$gr = array_values(array_filter($daFare->gruppi(['email' => 'rosa988@example.org']), fn($g) => $g['chiave'] === 'convenzioni_fsl'));
prova(count($gr) === 1 && $gr[0]['n'] === 1 && str_contains($gr[0]['righe'][0]['link'], 'convenzione_online.php?t=tokdaf98830#firmata') && str_contains($gr[0]['righe'][0]['testo'], 'Liceo di prova'),
    "da fare: al docente senza convenzione compare «Convenzione della scuola da caricare» con il link diretto", json_encode($gr));
// Compilazione non ancora rivendicata dall'email verificata: niente token nel link (una GET non cambia niente), si apre dal codice della prenotazione
$q("UPDATE convenzioni_compilate SET rivendicata_il = NULL WHERE id = 98830");
$gr_nr = array_values(array_filter($daFare->gruppi(['email' => 'rosa988@example.org']), fn($g) => $g['chiave'] === 'convenzioni_fsl'));
prova(count($gr_nr) === 1 && $gr_nr[0]['righe'][0]['link'] === 'convenzione_online.php?code=FS-DAF988' && !str_contains(json_encode($gr_nr), 'tokdaf98830'),
    "da fare: con la compilazione non rivendicata il link porta all'apertura dal codice e non contiene il token", json_encode($gr_nr));
$q("UPDATE convenzioni_compilate SET rivendicata_il = NOW() WHERE id = 98830");
// Un'email scelta a mano e mai confermata non è una prova: non dà il link personale della convenzione (S1); confermata sì
prova(array_filter($daFare->gruppi(['email' => 'rosa988@example.org', 'email_personalizzata' => 1, 'email_verificata_il' => null]), fn($g) => $g['chiave'] === 'convenzioni_fsl') === [], "da fare: con un'email NON verificata il link della convenzione non compare");
prova(count(array_filter($daFare->gruppi(['email' => 'rosa988@example.org', 'email_personalizzata' => 1, 'email_verificata_il' => '2026-11-01 10:00:00']), fn($g) => $g['chiave'] === 'convenzioni_fsl')) === 1, "da fare: con l'email personalizzata ma confermata il link compare");
prova(array_filter($daFare->gruppi(['email' => 'gino988@example.org']), fn($g) => $g['chiave'] === 'convenzioni_fsl') === [], "da fare: se la scuola ha già la convenzione non compare niente");
$q("UPDATE convenzioni_compilate SET stato_iter = 'caricata_scuola' WHERE id = 98830");
prova(array_filter($daFare->gruppi(['email' => 'rosa988@example.org']), fn($g) => $g['chiave'] === 'convenzioni_fsl') === [], "da fare: convenzione firmata caricata, non compare più");
$q("UPDATE convenzioni_compilate SET stato_iter = 'rifiutata', nota_ufficio = 'firma mancante' WHERE id = 98830");
$gr = array_values(array_filter($daFare->gruppi(['email' => 'rosa988@example.org']), fn($g) => $g['chiave'] === 'convenzioni_fsl'));
prova(count($gr) === 1 && str_contains($gr[0]['righe'][0]['dettaglio'], 'rifiutata') && str_contains($gr[0]['righe'][0]['dettaglio'], 'firma mancante'), "da fare: convenzione rifiutata, torna con il motivo");

$pagina = (string) file_get_contents(__DIR__ . '/../../../area_personale.php');
prova(str_contains($pagina, "\$vedi_come !== null") && str_contains($pagina, "=== 'lavoro') continue;"), "area personale: guardando «come» un profilo non compare «Il mio lavoro» dell'amministratore");

foreach (["DELETE FROM convenzioni_compilate WHERE id IN (98830, 98831)", "DELETE FROM prenotazioni WHERE id IN (98833, 98834)", "DELETE FROM turni WHERE id = 98832", "DELETE FROM progetti_dettagli WHERE evento_id = 98831", "DELETE FROM eventi WHERE id = 98831", "DELETE FROM pagine_eventi WHERE id = 98830"] as $sql) $q($sql);
