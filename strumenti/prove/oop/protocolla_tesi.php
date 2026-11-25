<?php
// Prove di «Protocolla in Titulus» per la convenzione di tesi firmata dall'azienda (App\ConvenzioniTesi\ProtocollaConvenzioneTesiInTitulus).
// Titulus è finto. Chiamato da esegui.php (variabili $conn, $q, $EMAIL). Convenzioni di prova con id 9897x.
sezione("Convenzioni di tesi: protocollo in entrata con Titulus");

use App\ConvenzioniTesi\ProtocollaConvenzioneTesiInTitulus;
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

$fin_pe = new class implements ClienteTitulus {
    public array $salvati = [];
    public ?string $errore = null;
    public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array
    {
        if ($this->errore !== null) throw new RuntimeException($this->errore);
        $this->salvati[] = ['xml' => $xmlDocumento, 'allegati' => $allegati];
        return ['nrecord' => '902', 'num_prot' => '2026-UCALPRG-0008' . count($this->salvati), 'url' => ''];
    }
    public function fascicola(string $xmlFascicolo, string $matricolaRpa): void {}
    public function infoRecord(string $nrecord): array { return []; }
    public function urlDocumento(string $id): ?string { return null; }
    public function fileDelDocumento(string $id): array { return []; }
    public function scaricaFile(string $fileId): string { throw new RuntimeException('Il finto non ha file.'); }
    public function prova(): string { return 'ok'; }
};
$fab_pe = new class($fin_pe) implements FabbricaClienteTitulus {
    public function __construct(private ClienteTitulus $c) {}
    public function crea(\Unical\Titulus\ConfigTitulus $config, string $password): ClienteTitulus { return $this->c; }
};

foreach (['titulus_invii', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
$q("DELETE FROM convenzioni_tesi WHERE id BETWEEN 98971 AND 98974");
$app_pe = \App\Core\App::per($conn);
$repo_pe = $app_pe->get(ProtocolloRepository::class);
$cifr_pe = new Cifratura('frase-di-prova-per-titulus-lunga');
$cfg_pe = new ConfiguratoreProtocollo($repo_pe, $cifr_pe);
$svc_pe = new ServizioProtocollo($repo_pe, $cifr_pe, $fab_pe, new GeneratoreXml());
$prot_pe = new ProtocollaConvenzioneTesiInTitulus($app_pe->get(ServizioConvenzioniTesi::class), new InvioProtocollo($svc_pe, $repo_pe));

$cartella_pe = $app_pe->get(Sito::class)->radice() . '/' . \App\ConvenzioniTesi\Costanti::DIR_FIRMATE;
if (!is_dir($cartella_pe)) mkdir($cartella_pe, 0775, true);
$pdf_pe = "%PDF-1.4\n" . str_repeat('convenzione tesi ', 40);
file_put_contents($cartella_pe . 'tesi98971.pdf', $pdf_pe);
$dati_pe = $conn->real_escape_string(json_encode(['comune' => 'Cosenza', 'indirizzo' => 'Via Verdi 2', 'piva' => '01234567890', 'pec' => 'azienda.prova@pec.example.org']));
$f_pe = \App\ConvenzioniTesi\Costanti::DIR_FIRMATE . 'tesi98971.pdf';
$q("INSERT INTO convenzioni_tesi (id, token, stato, azienda, email, dati_json, file_firmato, firmatario) VALUES
    (98971, 'tokpe98971', 'caricata', 'Azienda Prova Tesi Srl', 'az.prova@example.org', '$dati_pe', '$f_pe', 'Legale Rappresentante'),
    (98972, 'tokpe98972', 'caricata', 'Azienda Due Srl', 'az2.prova@example.org', '$dati_pe', '$f_pe', 'Legale Rappresentante'),
    (98973, 'tokpe98973', 'in_carico', 'Azienda Tre Srl', 'az3.prova@example.org', '$dati_pe', '$f_pe', 'Legale Rappresentante'),
    (98974, 'tokpe98974', 'invitata', 'Azienda Quattro Srl', 'az4.prova@example.org', NULL, NULL, '')");
$riga_pe = fn(int $id) => $conn->query("SELECT * FROM convenzioni_tesi WHERE id = $id")->fetch_assoc();

prova(!$prot_pe->disponibile(), "senza collegamento a Titulus il pulsante non si mostra");
$e_pe = $prot_pe->protocolla(98971, 'ufficio@example.org');
prova($e_pe['errore'] !== null && $riga_pe(98971)['stato'] === 'caricata' && !$fin_pe->salvati, "senza collegamento: errore e la convenzione resta da prendere in carico");

$cfg_pe->salvaCollegamento(['nome' => 'T', 'url_ws' => 'https://titulus.example.org/ws?wsdl', 'username' => 'ws', 'aoo' => 'UCALPRG', 'agd' => '', 'autore' => 'Portale', 'fascicolazione_separata' => 0, 'password' => 'segreta-prova', 'attivo' => 1]);
[$rid_pe, $err_pe] = $cfg_pe->salvaRegola(0, ['nome' => 'Convenzioni di tesi', 'applica_a' => 'convenzione_tesi', 'tipo_documento' => 'arrivo', 'azione' => 'protocolla', 'uo' => '2014.2', 'cod_titolario' => '5/1', 'attiva' => 1, 'invia_email_rpa' => 0]);
prova($err_pe === null && $prot_pe->disponibile(), "con collegamento e regola per le tesi il pulsante si mostra", (string)$err_pe);

$EMAIL = [];
$e_pe = $prot_pe->protocolla(98971, 'ufficio@example.org', 5);
$r_pe = $riga_pe(98971);
prova($e_pe['errore'] === null && $e_pe['numero'] === '2026-UCALPRG-00081', "protocollata: il numero lo dà Titulus", (string)$e_pe['errore']);
prova(in_array($r_pe['stato'], ['in_carico', 'da_firmare'], true) && $r_pe['protocollo_ingresso'] === '2026-UCALPRG-00081' && $r_pe['protocollo_ingresso_data'] === date('Y-m-d'), "la convenzione è presa in carico con numero e data di oggi");
$inv_pe = $fin_pe->salvati[0] ?? null;
prova($inv_pe !== null && count($inv_pe['allegati']) === 1 && $inv_pe['allegati'][0]->contenuto === $pdf_pe && str_contains($inv_pe['xml'], 'AZIENDA PROVA TESI SRL') && str_contains($inv_pe['xml'], 'azienda.prova@pec.example.org') && str_contains($inv_pe['xml'], 'tipo="arrivo"'), "a Titulus va il PDF firmato, come documento in arrivo dall'azienda (PEC)");
prova(count(array_filter($EMAIL, fn($m) => $m['a'] === 'az.prova@example.org')) >= 1, "l'azienda riceve l'avviso di presa in carico");
$log_pe = array_values(array_filter($conn->query("SELECT * FROM titulus_invii WHERE rif_tipo LIKE '" . ProtocollaConvenzioneTesiInTitulus::RIF . "~%' AND rif_id = 98971 ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC), fn($i) => $i['esito'] === 'ok'));
prova(count($log_pe) === 1 && (int)$log_pe[0]['utente_id'] === 5, "l'invio è nel registro, con chi l'ha fatto");
$e_pe = $prot_pe->protocolla(98971, 'ufficio@example.org');
prova($e_pe['errore'] !== null && count($fin_pe->salvati) === 1, "ripremere il pulsante non protocolla due volte");

$fin_pe->errore = 'Servizio non raggiungibile';
$e_pe = $prot_pe->protocolla(98972, 'ufficio@example.org');
prova($e_pe['errore'] !== null && str_contains($e_pe['errore'], 'Servizio non raggiungibile') && $riga_pe(98972)['stato'] === 'caricata' && $riga_pe(98972)['protocollo_ingresso'] === '', "Titulus in errore: la convenzione resta da prendere in carico");
$fin_pe->errore = null;
$e_pe = $prot_pe->protocolla(98972, 'ufficio@example.org');
prova($e_pe['errore'] === null && $riga_pe(98972)['protocollo_ingresso'] !== '', "riprovando va a buon fine");

foreach ([98973, 98974] as $idx) {
    $e_pe = $prot_pe->protocolla($idx, 'ufficio@example.org');
    prova($e_pe['errore'] !== null && count($fin_pe->salvati) === 2, "fuori fase o senza file ($idx): non si manda nulla a Titulus");
}

foreach (['titulus_invii', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
$q("DELETE FROM convenzioni_tesi WHERE id BETWEEN 98971 AND 98974");
@unlink($cartella_pe . 'tesi98971.pdf');
