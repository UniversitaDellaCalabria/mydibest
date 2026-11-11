<?php
// Prove di «Protocolla in Titulus» sul protocollo definitivo della convenzione di tesi (App\ConvenzioniTesi\ProtocollaDefinitivaTesiInTitulus):
// il PDF firmato dal Direttore va a Titulus e il numero diventa il protocollo della convenzione stipulata. Titulus è finto.
// Chiamato da esegui.php (variabili $conn, $q, $EMAIL). Convenzioni di prova con id 9898x.
sezione("Convenzioni di tesi: protocollo definitivo con Titulus");

use App\ConvenzioniTesi\ProtocollaDefinitivaTesiInTitulus;
use App\ConvenzioniTesi\ServizioConvenzioniTesi;
use App\Core\Sito;
use App\Infrastructure\Sicurezza\Cifratura;
use Unical\Titulus\ClienteTitulus;
use App\Protocollo\ConfiguratoreProtocollo;
use Unical\Titulus\FabbricaClienteTitulus;
use Unical\Titulus\GeneratoreXml;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\ProtocolloRepository;
use App\Protocollo\ServizioProtocollo;

$fin_pt = new class implements ClienteTitulus {
    public array $salvati = [];
    public ?string $errore = null;
    public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array
    {
        if ($this->errore !== null) throw new RuntimeException($this->errore);
        $this->salvati[] = ['xml' => $xmlDocumento, 'allegati' => $allegati];
        return ['nrecord' => '903', 'num_prot' => '2026-UCALPRG-0007' . count($this->salvati), 'url' => ''];
    }
    public function fascicola(string $xmlFascicolo, string $matricolaRpa): void {}
    public function infoRecord(string $nrecord): array { return []; }
    public function urlDocumento(string $id): ?string { return null; }
    public function prova(): string { return 'ok'; }
};
$fab_pt = new class($fin_pt) implements FabbricaClienteTitulus {
    public function __construct(private ClienteTitulus $c) {}
    public function crea(\Unical\Titulus\ConfigTitulus $config, string $password): ClienteTitulus { return $this->c; }
};

$pulisci_pt = function () use ($conn, $q) {
    foreach (['titulus_invii', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $t) $conn->query("DELETE FROM $t");
    $q("DELETE FROM convenzioni_tesi WHERE id BETWEEN 98981 AND 98984");
};
$pulisci_pt();
$app_pt = \App\Core\App::per($conn);
$repo_pt = $app_pt->get(ProtocolloRepository::class);
$cifr_pt = new Cifratura('frase-di-prova-per-titulus-lunga');
$cfg_pt = new ConfiguratoreProtocollo($repo_pt, $cifr_pt);
$svc_pt = new ServizioProtocollo($repo_pt, $cifr_pt, $fab_pt, new GeneratoreXml());
$prot_pt = new ProtocollaDefinitivaTesiInTitulus($app_pt->get(ServizioConvenzioniTesi::class), new InvioProtocollo($svc_pt, $repo_pt));

$cartella_pt = $app_pt->get(Sito::class)->radice() . '/' . \App\ConvenzioniTesi\Costanti::DIR_DEFINITIVE;
if (!is_dir($cartella_pt)) mkdir($cartella_pt, 0775, true);
$pdf_pt = "%PDF-1.4\n" . str_repeat('convenzione tesi direttore ', 40);
file_put_contents($cartella_pt . 'tesi98981.pdf', $pdf_pt);
$dati_pt = $conn->real_escape_string(json_encode(['comune' => 'Cosenza', 'indirizzo' => 'Via Verdi 2', 'piva' => '01234567890', 'pec' => 'azienda.def@pec.example.org']));
$f_pt = \App\ConvenzioniTesi\Costanti::DIR_DEFINITIVE . 'tesi98981.pdf';
$q("INSERT INTO convenzioni_tesi (id, token, stato, azienda, email, dati_json, file_definitivo, firmatario) VALUES
    (98981, 'tokpt98981', 'firmata_direttore', 'Azienda Definitiva Srl', 'az.def@example.org', '$dati_pt', '$f_pt', 'Legale Rappresentante'),
    (98982, 'tokpt98982', 'firmata_direttore', 'Azienda Due Definitiva Srl', 'az2.def@example.org', '$dati_pt', '$f_pt', 'Legale Rappresentante'),
    (98983, 'tokpt98983', 'in_carico', 'Azienda Tre Srl', 'az3.def@example.org', '$dati_pt', NULL, 'Legale Rappresentante'),
    (98984, 'tokpt98984', 'firmata_direttore', 'Azienda Senza File Srl', 'az4.def@example.org', '$dati_pt', NULL, 'Legale Rappresentante')");
$riga_pt = fn(int $id) => $conn->query("SELECT * FROM convenzioni_tesi WHERE id = $id")->fetch_assoc();
$date_pt = ['valida_dal' => '2026-11-01', 'valida_al' => '2031-10-31'];

prova(!$prot_pt->disponibile(), "senza collegamento a Titulus il pulsante non si mostra");
$e_pt = $prot_pt->protocolla(98981, $date_pt, '');
prova($e_pt['errore'] !== null && str_contains($e_pt['errore'], 'Titulus') && $riga_pt(98981)['stato'] === 'firmata_direttore' && !$fin_pt->salvati, "senza collegamento: errore chiaro, la convenzione resta da protocollare", (string)$e_pt['errore']);

$cfg_pt->salvaCollegamento(['nome' => 'T', 'url_ws' => 'https://titulus.example.org/ws?wsdl', 'username' => 'ws', 'aoo' => 'UCALPRG', 'agd' => '', 'autore' => 'Portale', 'fascicolazione_separata' => 0, 'password' => 'segreta-prova', 'attivo' => 1]);
[$rid_pt, $err_pt] = $cfg_pt->salvaRegola(0, ['nome' => 'Tesi definitive', 'applica_a' => 'convenzione_tesi_definitivo', 'tipo_documento' => 'partenza', 'azione' => 'protocolla', 'uo' => '2014.2', 'cod_titolario' => '5/1', 'attiva' => 1, 'invia_email_rpa' => 0]);
prova($err_pt === null && $prot_pt->disponibile(), "con collegamento e regola dedicata il pulsante si mostra", (string)$err_pt);
$conn->query("UPDATE titulus_regole SET applica_a = 'convenzione_tesi'");
prova(!$prot_pt->disponibile(), "la regola dell'ingresso non vale per il definitivo");
$conn->query("UPDATE titulus_regole SET applica_a = 'convenzione_tesi_definitivo'");

// Controlli che non assegnano numeri
$e_pt = $prot_pt->protocolla(98983, $date_pt, '');
prova($e_pt['errore'] !== null && str_contains($e_pt['errore'], 'firmata dal Direttore') && !$fin_pt->salvati, "convenzione non ancora firmata dal Direttore: non si protocolla");
$e_pt = $prot_pt->protocolla(98981, ['valida_dal' => '2026-11-01', 'valida_al' => '2025-01-01'], '');
prova($e_pt['errore'] !== null && str_contains($e_pt['errore'], 'date di validità') && !$fin_pt->salvati, "date incoerenti: si ferma prima di chiedere il numero");
$e_pt = $prot_pt->protocolla(98984, $date_pt, '');
prova($e_pt['errore'] !== null && str_contains($e_pt['errore'], 'non si legge') && !$fin_pt->salvati, "PDF del Direttore mancante: si ferma prima di chiedere il numero");

// Caso normale
$EMAIL = [];
$e_pt = $prot_pt->protocolla(98981, $date_pt, '', 5);
$r_pt = $riga_pt(98981);
prova($e_pt['errore'] === null && $e_pt['numero'] === '2026-UCALPRG-00071', "protocollata e registrata: il numero lo dà Titulus", (string)$e_pt['errore']);
prova($r_pt['stato'] === 'protocollata' && str_starts_with((string)$r_pt['protocollo'], '2026-UCALPRG-00071') && $r_pt['valida_dal'] === '2026-11-01' && $r_pt['valida_al'] === '2031-10-31', "la convenzione è stipulata con numero e validità indicate", (string)$r_pt['protocollo']);
$inv_pt = $fin_pt->salvati[0] ?? null;
prova($inv_pt !== null && count($inv_pt['allegati']) === 1 && $inv_pt['allegati'][0]->contenuto === $pdf_pt && str_contains($inv_pt['xml'], 'tipo="partenza"') && str_contains($inv_pt['xml'], 'AZIENDA DEFINITIVA SRL') && str_contains($inv_pt['xml'], 'FIRMATA DAL DIRETTORE'), "a Titulus va il PDF del Direttore, come documento in partenza verso l'azienda");
prova(count(array_filter($EMAIL, fn($m) => $m['a'] === 'az.def@example.org')) >= 1, "l'azienda riceve l'avviso di convenzione stipulata");
$log_pt = array_values(array_filter($repo_pt->invii(10, ProtocollaDefinitivaTesiInTitulus::RIF, 98981), fn($i) => $i['esito'] === 'ok'));
prova(count($log_pt) === 1 && (int)$log_pt[0]['utente_id'] === 5, "l'invio è nel registro con chi l'ha fatto");
$e_pt = $prot_pt->protocolla(98981, $date_pt, '');
prova($e_pt['errore'] !== null && count($fin_pt->salvati) === 1, "ripremere il pulsante non protocolla due volte");

// Titulus in errore, poi riprova
$fin_pt->errore = 'Servizio non raggiungibile';
$e_pt = $prot_pt->protocolla(98982, $date_pt, '');
prova($e_pt['errore'] !== null && str_contains($e_pt['errore'], 'Servizio non raggiungibile') && $riga_pt(98982)['stato'] === 'firmata_direttore', "Titulus in errore: la convenzione resta da protocollare");
$fin_pt->errore = null;
$e_pt = $prot_pt->protocolla(98982, $date_pt, '');
prova($e_pt['errore'] === null && $riga_pt(98982)['stato'] === 'protocollata', "riprovando va a buon fine");

$pulisci_pt();
@unlink($cartella_pt . 'tesi98981.pdf');
