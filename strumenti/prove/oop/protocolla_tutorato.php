<?php
// Prove di «Protocolla in Titulus» per la lettera di incarico di tutorato (App\Tutorato\ProtocollaLetteraInTitulus) e della catena delle regole
// (tutorato_lettera → tutorato → generale). Titulus è finto. Chiamato da esegui.php (variabili $conn, $q, $EMAIL). Bando 9896, lettere TU-PT*.
sezione("Modulo Tutorato: protocollo della lettera con Titulus");

use App\Core\Sito;
use App\Infrastructure\Sicurezza\Cifratura;
use App\Protocollo\ClienteTitulus;
use App\Protocollo\ConfiguratoreProtocollo;
use App\Protocollo\FabbricaClienteTitulus;
use App\Protocollo\GeneratoreXml;
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
    public function prova(): string { return 'ok'; }
};
$fab_pt2 = new class($fin_pt) implements FabbricaClienteTitulus {
    public function __construct(private ClienteTitulus $c) {}
    public function crea(\App\Protocollo\ConfigTitulus $config, string $password): ClienteTitulus { return $this->c; }
};

foreach (['titulus_invii', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
foreach (["DELETE FROM tutorato_eventi WHERE incarico_id IN (SELECT id FROM tutorato_incarichi WHERE bando_id = 9896)", "DELETE FROM tutorato_incarichi WHERE bando_id = 9896", "DELETE FROM tutorato_bandi WHERE id = 9896"] as $sql) $q($sql);
$app_pt2 = \App\Core\App::per($conn);
$repo_pt2 = $app_pt2->get(ProtocolloRepository::class);
$cifr_pt2 = new Cifratura('frase-di-prova-per-titulus-lunga');
$cfg_pt2 = new ConfiguratoreProtocollo($repo_pt2, $cifr_pt2);
$svc_pt2 = new ServizioProtocollo($repo_pt2, $cifr_pt2, $fab_pt2, new GeneratoreXml());
$prot_pt2 = new ProtocollaLetteraInTitulus($app_pt2->get(IncaricoRepository::class), $app_pt2->get(ArchivioIncarichi::class), $app_pt2->get(ServizioIncarichi::class), new InvioProtocollo($svc_pt2, $repo_pt2));

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

// Pulizia
foreach (['titulus_invii', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
foreach (["DELETE FROM tutorato_eventi WHERE incarico_id IN (SELECT id FROM tutorato_incarichi WHERE bando_id = 9896)", "DELETE FROM tutorato_incarichi WHERE bando_id = 9896", "DELETE FROM tutorato_bandi WHERE id = 9896"] as $sql) $q($sql);
foreach (array_keys($pdf_pt) as $n) @unlink($cartella_pt . $n);
