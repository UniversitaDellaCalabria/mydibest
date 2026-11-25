<?php
// Prove di «Protocolla in Titulus» per la lettera di incarico di tutorato (App\Tutorato\ProtocollaLetteraInTitulus) e della catena delle regole
// (tutorato_lettera → tutorato → generale). Titulus è finto. Chiamato da esegui.php (variabili $conn, $q, $EMAIL). Bando 9896, lettere TU-PT*.
sezione("Modulo Tutorato: protocollo della lettera con Titulus");

use App\Core\Sito;
use App\Infrastructure\Sicurezza\Cifratura;
use Unical\Titulus\ClienteTitulus;
use App\Protocollo\ConfiguratoreProtocollo;
use Unical\Titulus\FabbricaClienteTitulus;
use Unical\Titulus\GeneratoreXml;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\ProtocolloRepository;
use App\Protocollo\ServizioProtocollo;
use App\Tutorato\ArchivioIncarichi;
use App\Tutorato\IncaricoRepository;
use App\Tutorato\ProtocollaLetteraInTitulus;
use App\Tutorato\ServizioIncarichi;

$fin_pt = new class implements ClienteTitulus {
    public array $salvati = [];
    public ?string $errore = null;
    public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array
    {
        if ($this->errore !== null) throw new RuntimeException($this->errore);
        $this->salvati[] = ['xml' => $xmlDocumento, 'allegati' => $allegati];
        return ['nrecord' => '901', 'num_prot' => str_contains($xmlDocumento, 'bozza="si"') ? '' : '2026-UCALPRG-0009' . count($this->salvati), 'url' => ''];
    }
    public function fascicola(string $xmlFascicolo, string $matricolaRpa): void {}
    public function infoRecord(string $nrecord): array { return []; }
    public function urlDocumento(string $id): ?string { return null; }
    public function fileDelDocumento(string $id): array { return []; }
    public function scaricaFile(string $fileId): string { throw new RuntimeException('Il finto non ha file.'); }
    public function prova(): string { return 'ok'; }
};
$fab_pt2 = new class($fin_pt) implements FabbricaClienteTitulus {
    public function __construct(private ClienteTitulus $c) {}
    public function crea(\Unical\Titulus\ConfigTitulus $config, string $password): ClienteTitulus { return $this->c; }
};

foreach (['titulus_invii', 'protocollo_file', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
foreach (["DELETE FROM tutorato_eventi WHERE incarico_id IN (SELECT id FROM tutorato_incarichi WHERE bando_id = 9896)", "DELETE FROM tutorato_incarichi WHERE bando_id = 9896", "DELETE FROM tutorato_bandi WHERE id = 9896"] as $sql) $q($sql);
$app_pt2 = \App\Core\App::per($conn);
$repo_pt2 = $app_pt2->get(ProtocolloRepository::class);
$cifr_pt2 = new Cifratura('frase-di-prova-per-titulus-lunga');
$cfg_pt2 = new ConfiguratoreProtocollo($repo_pt2, $cifr_pt2);
$svc_pt2 = new ServizioProtocollo($repo_pt2, $cifr_pt2, $fab_pt2, new GeneratoreXml());
$prot_pt2 = new ProtocollaLetteraInTitulus($app_pt2->get(IncaricoRepository::class), $app_pt2->get(ArchivioIncarichi::class), $app_pt2->get(ServizioIncarichi::class), new InvioProtocollo($svc_pt2, $repo_pt2),
    $fp_pt = new \App\Protocollo\FileProtocollato($app_pt2->get(\App\Core\Database::class), $svc_pt2, $app_pt2->get(\App\Infrastructure\Storage\Upload::class), $app_pt2->get(Sito::class)));

$dir_pt = defined('DIR_INCARICHI') ? (string) DIR_INCARICHI : \App\Tutorato\Costanti::DIR_INCARICHI;
$cartella_pt = $app_pt2->get(Sito::class)->radice() . '/' . $dir_pt;
if (!is_dir($cartella_pt)) mkdir($cartella_pt, 0775, true);
$pdf_pt = ['TU-PT1_firmata.pdf' => "%PDF-1.4\n" . str_repeat('lettera ', 50), 'TU-PT1_scafis.pdf' => "%PDF-1.4\n" . str_repeat('scafis ', 50), 'TU-PT2_firmata.pdf' => "%PDF-1.4\n" . str_repeat('altra ', 50)];
foreach ($pdf_pt as $n => $c) file_put_contents($cartella_pt . $n, $c);
$q("INSERT INTO tutorato_bandi (id, titolo, direttore_nome, direttore_email, direttore_cf) VALUES (9896, 'Bando protocollo 9896', 'Dir Prova', 'dir9896@example.org', 'BNCMRA60A01D086X')");
$rel_pt = $dir_pt;
$q("INSERT INTO tutorato_incarichi (bando_id, codice, cognome, nome, codice_fiscale, email, indirizzo, civico, comune_residenza, attivita, stato, iter, file_pdf, scafis_pdf) VALUES
    (9896, 'TU-PT1', 'Verdi', 'Giulia', 'VRDGLI98A41D086X', 'giulia.pt@example.org', 'Via Po', '3', 'Rende', 'Tutorato di prova', 'firmata', 'uniticket', '{$rel_pt}TU-PT1_firmata.pdf', '{$rel_pt}TU-PT1_scafis.pdf'),
    (9896, 'TU-PT2', 'Neri', 'Marco', 'NRIMRC98A01D086X', 'marco.pt@example.org', 'Via Arno', '5', 'Cosenza', 'Tutorato di prova', 'firmata', 'portale', '{$rel_pt}TU-PT2_firmata.pdf', NULL),
    (9896, 'TU-PT3', 'Bianchi', 'Anna', 'BNCNNA98A41D086X', 'anna.pt@example.org', '', '', '', 'Tutorato di prova', 'confermata', 'portale', NULL, NULL)");
$id_pt = fn(string $cod) => (int)$conn->query("SELECT id FROM tutorato_incarichi WHERE codice = '$cod'")->fetch_row()[0];
$riga_pt = fn(string $cod) => $conn->query("SELECT * FROM tutorato_incarichi WHERE codice = '$cod'")->fetch_assoc();

// Senza collegamento
prova(!$prot_pt2->disponibile(), "senza collegamento a Titulus il pulsante non si mostra");
$e_pt = $prot_pt2->protocolla($id_pt('TU-PT1'), false, 'ufficio@example.org');
prova($e_pt['errore'] !== null && $riga_pt('TU-PT1')['stato'] === 'firmata' && !$fin_pt->salvati, "senza collegamento: errore e la lettera resta da protocollare");

// Collegamento e regole: «tutorato» (padre), poi una dedicata «tutorato_lettera»
$cfg_pt2->salvaCollegamento(['nome' => 'T', 'url_ws' => 'https://titulus.example.org/ws?wsdl', 'username' => 'ws', 'aoo' => 'UCALPRG', 'agd' => '', 'autore' => 'Portale', 'fascicolazione_separata' => 0, 'password' => 'segreta-prova', 'attivo' => 1]);
$base_rg = ['azione' => 'protocolla', 'uo' => '2014.2', 'cod_titolario' => '5/1', 'attiva' => 1, 'invia_email_rpa' => 0];
[$rid_gen, $e1] = $cfg_pt2->salvaRegola(0, ['nome' => 'Generale interno', 'applica_a' => 'generale', 'tipo_documento' => 'interno', 'rpa_nome' => 'Mario Rossi'] + $base_rg);
prova($e1 === null && $prot_pt2->disponibile(), "con la sola regola generale il pulsante si mostra", (string)$e1);
[$rid_tut, $e2] = $cfg_pt2->salvaRegola(0, ['nome' => 'Tutorato', 'applica_a' => 'tutorato', 'tipo_documento' => 'arrivo', 'cod_titolario' => '5/1'] + $base_rg);
prova($e2 === null && (int)$repo_pt2->regolaPer('tutorato_lettera')['id'] === $rid_tut, "senza regola dedicata vale quella «tutorato»", (string)$e2);
[$rid_let, $e3] = $cfg_pt2->salvaRegola(0, ['nome' => 'Lettere tutorato', 'applica_a' => 'tutorato_lettera', 'tipo_documento' => 'partenza'] + $base_rg);
prova($e3 === null && (int)$repo_pt2->regolaPer('tutorato_lettera')['id'] === $rid_let && (int)$repo_pt2->regolaPer('tutorato')['id'] === $rid_tut, "la regola dedicata vince su «tutorato», che resta per il resto", (string)$e3);

// Lettera in iter Uniticket: lettera + SCAFIS, il numero diventa il protocollo e la copia parte
$EMAIL = [];
$e_pt = $prot_pt2->protocolla($id_pt('TU-PT1'), true, 'ufficio@example.org', 9);
$r1 = $riga_pt('TU-PT1');
prova($e_pt['errore'] === null && $e_pt['numero'] === '2026-UCALPRG-00091', "protocollata: il numero lo dà Titulus", (string)$e_pt['errore']);
prova($r1['stato'] === 'protocollata' && $r1['protocollo'] === '2026-UCALPRG-00091' && $r1['protocollo_data'] === date('Y-m-d'), "la lettera risulta protocollata con numero e data di oggi");
$inv1 = $fin_pt->salvati[0] ?? null;
prova($inv1 !== null && count($inv1['allegati']) === 2 && $inv1['allegati'][0]->contenuto === $pdf_pt['TU-PT1_firmata.pdf'] && $inv1['allegati'][1]->contenuto === $pdf_pt['TU-PT1_scafis.pdf'], "a Titulus vanno la lettera firmata e la dichiarazione SCAFIS (iter Uniticket)");
prova($inv1 !== null && str_contains($inv1['xml'], 'tipo="partenza"') && str_contains($inv1['xml'], 'VRDGLI98A41D086X') && str_contains($inv1['xml'], 'LETTERA DI INCARICO DI TUTORATO'), "usata la regola dedicata: documento in partenza con il codice fiscale dello studente");
prova(count(array_filter($EMAIL, fn($m) => $m['a'] === 'giulia.pt@example.org')) === 1, "la copia della lettera firmata parte allo studente");
// Passo 7: la lettera firmata non si stampiglia sul posto; il documento protocollato è la copia per consultazione
prova(file_get_contents($cartella_pt . 'TU-PT1_firmata.pdf') === $pdf_pt['TU-PT1_firmata.pdf'], "passo 7: la lettera firmata è identica byte per byte dopo il protocollo");
$o_pt = $fp_pt->apri(\App\Protocollo\FileProtocollato::RIF_LETTERA, $id_pt('TU-PT1'), 'originale');
$d_pt = $fp_pt->apri(\App\Protocollo\FileProtocollato::RIF_LETTERA, $id_pt('TU-PT1'), 'protocollato');
prova($o_pt !== null && file_get_contents($o_pt['percorso']) === $pdf_pt['TU-PT1_firmata.pdf'], "passo 7: «Originale» della lettera è la lettera firmata, integra");
// Il PDF di prova non si può stampigliare (non è un PDF vero): la copia non c'è e il documento protocollato è la lettera firmata stessa («vale la lettera firmata»)
$seg_pt = $app_pt2->get(\App\Tutorato\ArchivioIncarichi::class)->segnatoCorrente($riga_pt('TU-PT1'));
$v_pt = $fp_pt->voci(\App\Protocollo\FileProtocollato::RIF_LETTERA, $id_pt('TU-PT1'));
prova($d_pt !== null && $v_pt['protocollato']['esiste'] && ($seg_pt !== null
    ? ($d_pt['percorso'] !== $o_pt['percorso'] && str_contains($d_pt['nome'], 'copia_per_consultazione') && realpath($d_pt['percorso']) === realpath($seg_pt))
    : ($v_pt['protocollato']['origine'] === 'originale' && file_get_contents($d_pt['percorso']) === $pdf_pt['TU-PT1_firmata.pdf'])), "passo 7: «Documento protocollato» è la copia con la segnatura, o (se il PDF non si stampiglia) la lettera firmata stessa");
$riga_fp_pt = $fp_pt->riga(\App\Protocollo\FileProtocollato::RIF_LETTERA, $id_pt('TU-PT1'));
prova($riga_fp_pt !== null && $riga_fp_pt['stato'] === 'attesa' && $riga_fp_pt['numero'] === '2026-UCALPRG-00091' && count($fin_pt->salvati) === 1, "passo 7: il file di Titulus è in programma per il cron e nessuna pagina lo chiede subito");
$log_pt = array_values(array_filter($repo_pt2->invii(10, ProtocollaLetteraInTitulus::RIF, $id_pt('TU-PT1')), fn($i) => $i['esito'] === 'ok'));
prova(count($log_pt) === 1 && (int)$log_pt[0]['utente_id'] === 9, "l'invio è nel registro, con chi l'ha fatto");
$e_pt = $prot_pt2->protocolla($id_pt('TU-PT1'), false, 'ufficio@example.org');
prova($e_pt['errore'] !== null && count($fin_pt->salvati) === 1, "una lettera già protocollata non si protocolla di nuovo");

// Iter nel portale: niente SCAFIS; Titulus in errore → la lettera resta com'è
$fin_pt->errore = 'Servizio non raggiungibile';
$e_pt = $prot_pt2->protocolla($id_pt('TU-PT2'), false, 'ufficio@example.org');
prova($e_pt['errore'] !== null && str_contains($e_pt['errore'], 'Servizio non raggiungibile') && $riga_pt('TU-PT2')['stato'] === 'firmata' && $riga_pt('TU-PT2')['protocollo'] === '', "Titulus in errore: la lettera resta da protocollare");
$fin_pt->errore = null;
$e_pt = $prot_pt2->protocolla($id_pt('TU-PT2'), false, 'ufficio@example.org');
prova($e_pt['errore'] === null && $riga_pt('TU-PT2')['stato'] === 'protocollata' && count(end($fin_pt->salvati)['allegati']) === 1, "riprovando va a buon fine, con la sola lettera (iter nel portale)");

// Non ancora firmata da tutti
$e_pt = $prot_pt2->protocolla($id_pt('TU-PT3'), false, 'ufficio@example.org');
prova($e_pt['errore'] !== null && $riga_pt('TU-PT3')['stato'] === 'confermata', "una lettera non ancora firmata da tutti non si protocolla");

// Regola tra uffici: il mittente è l'ufficio della regola
$conn->query("UPDATE titulus_regole SET attiva = 0 WHERE id IN ($rid_let, $rid_tut)");
$q("UPDATE tutorato_incarichi SET stato = 'firmata', protocollo = '', protocollo_data = NULL WHERE codice = 'TU-PT2'");
$conn->query("DELETE FROM titulus_invii");
$n_prima = count($fin_pt->salvati);
$e_pt = $prot_pt2->protocolla($id_pt('TU-PT2'), false, 'ufficio@example.org');
prova($e_pt['errore'] === null && count($fin_pt->salvati) === $n_prima + 1 && str_contains(end($fin_pt->salvati)['xml'], 'Mario Rossi'), "documento tra uffici con la sola regola generale: mittente preso dalla regola (UO e RPA)", (string)$e_pt['errore']);

// ── Protocollo automatico dopo la firma del direttore (ProtocolloAutomaticoLettera) ──
$q("INSERT INTO tutorato_incarichi (bando_id, codice, cognome, nome, codice_fiscale, email, indirizzo, civico, comune_residenza, attivita, stato, iter, file_pdf, scafis_pdf) VALUES
    (9896, 'TU-PT4', 'Gialli', 'Pia', 'GLLPIA98A41D086X', 'pia.pt@example.org', 'Via Tevere', '1', 'Rende', 'Tutorato di prova', 'firmata', 'portale', '{$rel_pt}TU-PT2_firmata.pdf', NULL)");
$auto_pt = new \App\Tutorato\ProtocolloAutomaticoLettera($prot_pt2, $app_pt2->get(IncaricoRepository::class), $app_pt2->get(\App\Tutorato\ServizioIncarichi::class));
$EMAIL = [];
$n_auto_pt = count($fin_pt->salvati);
prova($auto_pt->dopoFirma($id_pt('TU-PT4')) === null && $riga_pt('TU-PT4')['stato'] === 'protocollata' && $riga_pt('TU-PT4')['protocollo'] !== '' && count($fin_pt->salvati) === $n_auto_pt + 1, "automatico: dopo la firma del direttore la lettera prende da sola il numero di Titulus");
prova(count(array_filter($EMAIL, fn($m) => $m['a'] === 'pia.pt@example.org')) === 1, "automatico: la copia della lettera firmata parte allo studente");
prova($auto_pt->dopoFirma($id_pt('TU-PT4')) === null && count($fin_pt->salvati) === $n_auto_pt + 1, "automatico: una lettera già protocollata non si rimanda");
prova($auto_pt->dopoFirma($id_pt('TU-PT3')) === null && $riga_pt('TU-PT3')['stato'] === 'confermata' && count($fin_pt->salvati) === $n_auto_pt + 1, "automatico: una lettera non ancora firmata da tutti non si protocolla");
$q("UPDATE tutorato_incarichi SET stato = 'firmata', protocollo = '', protocollo_data = NULL WHERE codice = 'TU-PT4'");
$conn->query("DELETE FROM titulus_invii");
$fin_pt->errore = 'Servizio non raggiungibile';
$err_auto_pt = $auto_pt->dopoFirma($id_pt('TU-PT4'));
prova($err_auto_pt !== null && str_contains($err_auto_pt, 'Servizio non raggiungibile') && $riga_pt('TU-PT4')['stato'] === 'firmata', "automatico: Titulus in errore, la lettera resta da protocollare senza eccezioni");
prova((int)$conn->query("SELECT COUNT(*) FROM tutorato_eventi WHERE incarico_id = " . $id_pt('TU-PT4') . " AND tipo = 'errore' AND testo LIKE '%protocollo automatico%Servizio non raggiungibile%'")->fetch_row()[0] === 1, "automatico: l'errore è tra gli eventi della lettera, per l'operatore");
$fin_pt->errore = null;
$conn->query("UPDATE titulus_regole SET attiva = 0");
$n_auto_pt = count($fin_pt->salvati);
prova($auto_pt->dopoFirma($id_pt('TU-PT4')) === null && $riga_pt('TU-PT4')['stato'] === 'firmata' && count($fin_pt->salvati) === $n_auto_pt, "automatico: senza regola attiva non fa nulla e non segnala errori");

// ── Correzioni al passo 6 (T1): esito incerto, numero a mano, protocollo automatico, data dell'invio riuscito ──
$q("INSERT INTO tutorato_incarichi (bando_id, codice, cognome, nome, codice_fiscale, email, indirizzo, civico, comune_residenza, attivita, stato, iter, file_pdf, scafis_pdf) VALUES
    (9896, 'TU-PT5', 'Rossi', 'Elio', 'RSSLEI98A01D086X', 'elio.pt@example.org', 'Via Neto', '2', 'Rende', 'Tutorato di prova', 'firmata', 'portale', '{$rel_pt}TU-PT2_firmata.pdf', NULL),
    (9896, 'TU-PT6', 'Rossi', 'Lia', 'RSSLIA98A41D086X', 'lia.pt@example.org', 'Via Neto', '3', 'Rende', 'Tutorato di prova', 'firmata', 'portale', '{$rel_pt}TU-PT2_firmata.pdf', NULL),
    (9896, 'TU-PT7', 'Rossi', 'Ivan', 'RSSVNI98A01D086X', 'ivan.pt@example.org', 'Via Neto', '4', 'Rende', 'Tutorato di prova', 'firmata', 'portale', '{$rel_pt}TU-PT2_firmata.pdf', NULL)");
$conn->query("UPDATE titulus_regole SET attiva = 1");
$riga_inv_pt = fn(int $id, string $esito, string $num = '') => $repo_pt2->registraInvio(['regola_id' => null, 'applica_a' => 'tutorato_lettera', 'rif_tipo' => ProtocollaLetteraInTitulus::RIF, 'rif_id' => $id, 'oggetto' => 'prova', 'tipo_documento' => 'partenza', 'azione' => 'protocolla', 'esito' => $esito, 'nrecord' => '', 'num_prot' => $num, 'url' => '', 'errore' => null, 'utente_id' => null]);
$repo_pt2->segnaInvio(ProtocollaLetteraInTitulus::RIF, $id_pt('TU-PT5'), 'incerto', 'prova', 'timeout');
prova($prot_pt2->stato($id_pt('TU-PT5'))['stato'] === 'incerto', "T1a: lo stato dell'invio della lettera si legge dalla classe (esito incerto)");
$n_t1 = count($fin_pt->salvati);
$err_t1 = $auto_pt->dopoFirma($id_pt('TU-PT5'));
prova($err_t1 !== null && str_contains($err_t1, "Non c'è in Titulus: riprova") && count($fin_pt->salvati) === $n_t1 && $riga_pt('TU-PT5')['stato'] === 'firmata', "T1c: con l'esito incerto il protocollo automatico si ferma, non chiama Titulus e dice all'operatore che cosa fare");
prova((int)$conn->query("SELECT COUNT(*) FROM tutorato_eventi WHERE incarico_id = " . $id_pt('TU-PT5') . " AND tipo = 'errore' AND testo LIKE '%protocollo automatico%si è fermato%INCERTO%'")->fetch_row()[0] === 1, "T1c: l'avviso è tra gli eventi della lettera");
$e_t1 = $prot_pt2->protocolla($id_pt('TU-PT5'), false, 'ufficio@example.org');
prova($e_t1['errore'] !== null && str_contains($e_t1['errore'], 'INCERTO') && count($fin_pt->salvati) === $n_t1, "T1c: nemmeno il pulsante rimanda a Titulus con l'esito incerto");
$e_m = $prot_pt2->protocolloAMano($id_pt('TU-PT5'), '', date('Y-m-d'), false, 'ufficio@example.org');
prova($e_m !== null && $riga_pt('TU-PT5')['stato'] === 'firmata' && $prot_pt2->stato($id_pt('TU-PT5'))['stato'] === 'incerto', "T1b: un numero a mano vuoto non registra niente e NON chiude l'incertezza");
$e_m = $prot_pt2->protocolloAMano($id_pt('TU-PT5'), 'T-55/2026', date('Y-m-d'), false, 'ufficio@example.org');
prova($e_m === null && $riga_pt('TU-PT5')['stato'] === 'protocollata' && $riga_pt('TU-PT5')['protocollo'] === 'T-55/2026' && $prot_pt2->stato($id_pt('TU-PT5'))['stato'] === 'libero', "T1b: un numero a mano valido registra e chiude l'incertezza", (string)$e_m);
prova(count($fin_pt->salvati) === $n_t1, "T1b: il numero a mano non chiama Titulus");
// «in approvazione» e «Respinto»
$riga_inv_pt($id_pt('TU-PT6'), 'ok', '');
prova($prot_pt2->stato($id_pt('TU-PT6'))['stato'] === 'in_approvazione', "T1a: un invio riuscito senza numero risulta «in approvazione»");
$err_t1 = $auto_pt->dopoFirma($id_pt('TU-PT6'));
prova($err_t1 !== null && str_contains($err_t1, 'Respinto') && count($fin_pt->salvati) === $n_t1, "T1c: in approvazione il protocollo automatico si ferma e lo dice");
require_once RADICE_SITO . '/inc/parziali/protocollo_stato.php';
$_SESSION['utente_id'] = 1; $_SESSION['utente_email'] = 'ufficio@example.org';
[$msg_t1, $tipo_t1] = protocollo_stato_azione($conn, ProtocollaLetteraInTitulus::RIF, $id_pt('TU-PT6'), true, 'Tutorato: lettera');
$e_t1 = $prot_pt2->protocolla($id_pt('TU-PT6'), false, 'ufficio@example.org');
prova($tipo_t1 === 'success' && $e_t1['errore'] === null && $riga_pt('TU-PT6')['stato'] === 'protocollata', "T1a: «Respinto» e poi si può rimandare: la lettera si protocolla", $msg_t1 . ' ' . (string)$e_t1['errore']);
// T1d — la data è quella dell'invio riuscito
$riga_inv_pt($id_pt('TU-PT7'), 'ok', 'OLD-T1');
$q("UPDATE titulus_invii SET creato_il = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE rif_tipo = 'tutorato_lettera' AND rif_id = " . $id_pt('TU-PT7'));
$e_t1 = $prot_pt2->protocolla($id_pt('TU-PT7'), false, 'ufficio@example.org');
$r_t1 = $riga_pt('TU-PT7');
prova($e_t1['errore'] === null && $r_t1['protocollo'] === 'OLD-T1' && $r_t1['protocollo_data'] === date('Y-m-d', strtotime('-3 days')), "T1d: un numero già dato 3 giorni fa si registra con la data dell'invio, non con quella di oggi", json_encode([$e_t1['errore'], $r_t1['protocollo'], $r_t1['protocollo_data']]));
// Pulizia
foreach (['titulus_invii', 'protocollo_file', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
foreach (["DELETE FROM tutorato_eventi WHERE incarico_id IN (SELECT id FROM tutorato_incarichi WHERE bando_id = 9896)", "DELETE FROM tutorato_incarichi WHERE bando_id = 9896", "DELETE FROM tutorato_bandi WHERE id = 9896"] as $sql) $q($sql);
foreach (array_keys($pdf_pt) as $n) @unlink($cartella_pt . $n);
