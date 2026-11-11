<?php
// Prove della presenza negli eventi SENZA prenotazione (ServizioCheckin): chi inquadra il QR e accede entra nell'elenco dei presenti.
// Chiamato da esegui.php. Dati di prova con id 998xx.
sezione("Presenza con il QR negli eventi senza prenotazione");

use App\Iscrizioni\ServizioCheckin;

$q("DELETE FROM prenotazioni WHERE turno_id BETWEEN 99801 AND 99809");
$q("DELETE FROM turni WHERE id BETWEEN 99801 AND 99809");
$q("DELETE FROM eventi WHERE id BETWEEN 99801 AND 99809");
$q("DELETE FROM utenti WHERE id BETWEEN 99801 AND 99809");
$q("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari) VALUES (99801, 'Giulia', 'Verdi', 'Giulia.Verdi998@Studenti.Example.org', 3, '')");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, richiede_prenotazione, abilita_presenze) VALUES (99801, 1, 'Seminario libero 998', 'evento', 0, 1), (99802, 1, 'Seminario libero senza presenze 998', 'evento', 0, 0), (99803, 1, 'Evento con prenotazione 998', 'evento', 1, 1)");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, token_checkin) VALUES
    (99801, 99801, 'Libero', NULL, NULL, NULL, 30, 'lib998'), (99802, 99802, 'Libero no presenze', NULL, NULL, NULL, 30, 'nop998'), (99803, 99803, 'Con prenotazione', NULL, NULL, NULL, 30, 'pre998')");

$ck_pl = \App\Core\App::per($conn)->get(ServizioCheckin::class);
$r_pl = $ck_pl->autoRegistrazione(99801, 'lib998', 99801);
prova($r_pl['esito'] === 'success' && str_contains($r_pl['messaggio'], 'elenco dei presenti'), "evento libero: il QR registra la presenza", json_encode($r_pl['messaggio']));
$riga_pl = $conn->query("SELECT * FROM prenotazioni WHERE turno_id = 99801")->fetch_assoc();
prova($riga_pl && (int)$riga_pl['utente_id'] === 99801 && $riga_pl['stato'] === 'confermata' && (int)$riga_pl['presente'] === 1 && $riga_pl['data_presenza'] !== null, "la riga dei presenti nasce già confermata e presente, con l'ora");
prova($riga_pl && $riga_pl['nome'] === 'Giulia' && $riga_pl['cognome'] === 'Verdi' && $riga_pl['email'] === 'giulia.verdi998@studenti.example.org' && str_starts_with($riga_pl['codice_prenotazione'], 'AL-'), "nome, cognome ed email dall'account istituzionale");
$r_pl2 = $ck_pl->autoRegistrazione(99801, 'lib998', 99801);
prova($r_pl2['esito'] === 'success' && str_contains($r_pl2['messaggio'], 'già stata registrata') && (int)$conn->query("SELECT COUNT(*) FROM prenotazioni WHERE turno_id = 99801")->fetch_row()[0] === 1, "seconda scansione: nessun doppione");
prova($ck_pl->autoRegistrazione(99801, 'lib998', 99809)['esito'] === 'error' && (int)$conn->query("SELECT COUNT(*) FROM prenotazioni WHERE turno_id = 99801")->fetch_row()[0] === 1, "account sconosciuto: nessuna riga creata");
prova($ck_pl->autoRegistrazione(99801, 'sbagliato', 99801)['esito'] === 'error', "codice del QR sbagliato: rifiutato");
$r_np = $ck_pl->autoRegistrazione(99802, 'nop998', 99801);
prova($r_np['esito'] === 'error' && (int)$conn->query("SELECT COUNT(*) FROM prenotazioni WHERE turno_id = 99802")->fetch_row()[0] === 0, "evento libero con presenze disattivate: non si registra nulla");
$r_pr = $ck_pl->autoRegistrazione(99803, 'pre998', 99801);
prova($r_pr['esito'] === 'error' && str_contains($r_pr['messaggio'], 'Non risulti iscritto') && (int)$conn->query("SELECT COUNT(*) FROM prenotazioni WHERE turno_id = 99803")->fetch_row()[0] === 0, "evento con prenotazione: serve ancora essere iscritti");

$q("DELETE FROM prenotazioni WHERE turno_id BETWEEN 99801 AND 99809");
$q("DELETE FROM turni WHERE id BETWEEN 99801 AND 99809");
$q("DELETE FROM eventi WHERE id BETWEEN 99801 AND 99809");
$q("DELETE FROM utenti WHERE id BETWEEN 99801 AND 99809");
