<?php
// Prove di «Protocolla in Titulus» sul protocollo definitivo della convenzione FSL (App\Fsl\ProtocollaDefinitivaFslInTitulus): il PDF firmato dal
// Direttore (e l'Allegato A) vanno a Titulus, il numero diventa il protocollo della convenzione registrata. Titulus è finto.
// Chiamato da esegui.php (variabili $conn, $q, $EMAIL). Compilazioni di prova con id 9891x, scuola ZZDF98910X.
sezione("Modulo FSL: protocollo definitivo con Titulus");

use App\Core\Sito;
use App\Fsl\ConvenzioneCompilataRepository;
use App\Fsl\ProtocollaDefinitivaFslInTitulus;
use App\Fsl\ServizioConvenzioneFirmata;
use App\Infrastructure\Sicurezza\Cifratura;
use App\Protocollo\ClienteTitulus;
use App\Protocollo\ConfiguratoreProtocollo;
use App\Protocollo\FabbricaClienteTitulus;
use App\Protocollo\GeneratoreXml;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\ProtocolloRepository;
use App\Protocollo\ServizioProtocollo;

$fin_pd = new class implements ClienteTitulus {
    public array $salvati = [];
    public ?string $errore = null;
    public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array
    {
        if ($this->errore !== null) throw new RuntimeException($this->errore);
        $this->salvati[] = ['xml' => $xmlDocumento, 'allegati' => $allegati];
        return ['nrecord' => '777', 'num_prot' => '2026-UCALPRG-0009001', 'url' => 'https://titulus.example.org/doc/777'];
    }
    public function fascicola(string $xmlFascicolo, string $matricolaRpa): void {}
    public function infoRecord(string $nrecord): array { return []; }
    public function urlDocumento(string $id): ?string { return null; }
    public function prova(): string { return 'ok'; }
};
$fab_pd = new class($fin_pd) implements FabbricaClienteTitulus {
    public function __construct(private ClienteTitulus $c) {}
    public function crea(\App\Protocollo\ConfigTitulus $config, string $password): ClienteTitulus { return $this->c; }
};

$pulisci_pd = function () use ($conn, $q) {
    foreach (['titulus_invii', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $t) $conn->query("DELETE FROM $t");
    $q("DELETE FROM convenzioni_compilate WHERE id BETWEEN 98911 AND 98915");
    $q("DELETE FROM convenzioni_scuole WHERE scuola_codice = 'ZZDF98910X'");
    $q("DELETE FROM scuole WHERE codice = 'ZZDF98910X'");
};
$pulisci_pd();
$q("INSERT INTO scuole (codice, denominazione, istituto_codice, istituto_denominazione, tipo, comune, provincia, regione, indirizzo, cap) VALUES ('ZZDF98910X', 'LICEO PROVA DEFINITIVO', NULL, NULL, 'LICEO', 'RENDE', 'COSENZA', 'CALABRIA', 'VIA DEF 1', '87036')");

$app_pd = \App\Core\App::per($conn);
$repo_pd = $app_pd->get(ProtocolloRepository::class);
$cifr_pd = new Cifratura('frase-di-prova-per-titulus-lunga');
$cfg_pd = new ConfiguratoreProtocollo($repo_pd, $cifr_pd);
$svc_pd = new ServizioProtocollo($repo_pd, $cifr_pd, $fab_pd, new GeneratoreXml());
$iter_pd = $app_pd->get(ServizioConvenzioneFirmata::class);
$compilate_pd = $app_pd->get(ConvenzioneCompilataRepository::class);
$prot_pd = new ProtocollaDefinitivaFslInTitulus($compilate_pd, $iter_pd, new InvioProtocollo($svc_pd, $repo_pd));

// File: PDF del Direttore nella cartella delle convenzioni, Allegato A nella cartella delle firmate
$radice_pd = $app_pd->get(Sito::class)->radice() . '/';
$cart_def_pd = $radice_pd . \App\Fsl\Costanti::DIR_CONVENZIONI;
$cart_fir_pd = $radice_pd . \App\Fsl\Costanti::DIR_FIRMATE;
foreach ([$cart_def_pd, $cart_fir_pd] as $c) if (!is_dir($c)) mkdir($c, 0775, true);
$file_pd = [$cart_def_pd . 'def98911.pdf' => "%PDF-1.4\n" . str_repeat('direttore ', 40), $cart_def_pd . 'def98912.pdf' => "%PDF-1.4\n" . str_repeat('direttore2 ', 40),
    $cart_fir_pd . 'all98911.pdf' => "%PDF-1.4\n" . str_repeat('allegato ', 40)];
foreach ($file_pd as $p => $c) file_put_contents($p, $c);
$dati_pd = $conn->real_escape_string(json_encode(['scuola' => ['denominazione' => 'Liceo Prova Definitivo', 'codice' => 'ZZDF98910X', 'cf' => '80001230789', 'indirizzo' => 'Via Def 1', 'comune' => 'Rende', 'pec' => 'liceo.def@pec.example.org', 'email' => 'liceo.def@example.org']]));
$d_def = \App\Fsl\Costanti::DIR_CONVENZIONI;
$d_fir = \App\Fsl\Costanti::DIR_FIRMATE;
$q("INSERT INTO convenzioni_compilate (id, token, scuola_codice, email, dati_json, stato_iter, file_firmato, file_allegato_scuola, file_definitivo, firmatario) VALUES
    (98911, 'tokdef98911', 'ZZDF98910X', 'liceo.def@example.org', '$dati_pd', 'firmata_direttore', NULL, '{$d_fir}all98911.pdf', '{$d_def}def98911.pdf', 'Dirigente Prova'),
    (98912, 'tokdef98912', 'ZZDF98910X', 'liceo.def@example.org', '$dati_pd', 'firmata_direttore', NULL, NULL, '{$d_def}def98912.pdf', 'Dirigente Prova'),
    (98913, 'tokdef98913', 'ZZDF98910X', 'liceo.def@example.org', '$dati_pd', 'in_carico', NULL, NULL, NULL, 'Dirigente Prova'),
    (98914, 'tokdef98914', NULL, 'liceo.def@example.org', '$dati_pd', 'firmata_direttore', NULL, NULL, '{$d_def}def98912.pdf', 'Dirigente Prova')");
$riga_pd = fn(int $id) => $conn->query("SELECT * FROM convenzioni_compilate WHERE id = $id")->fetch_assoc();
$date_pd = ['data_stipula' => '2026-11-01', 'scadenza' => '2031-10-31'];

// Senza collegamento: pulsante assente e nulla parte
prova(!$prot_pd->disponibile(), "senza collegamento a Titulus il pulsante non si mostra");
$e_pd = $prot_pd->protocolla(98911, $date_pd, '', 'ufficio@example.org');
prova($e_pd['errore'] !== null && str_contains($e_pd['errore'], 'Titulus') && $riga_pd(98911)['stato_iter'] === 'firmata_direttore' && !$fin_pd->salvati, "senza collegamento: errore chiaro, la convenzione resta da protocollare", (string)$e_pd['errore']);

// Collegamento + regola dedicata al definitivo
$cfg_pd->salvaCollegamento(['nome' => 'T', 'url_ws' => 'https://titulus.example.org/ws?wsdl', 'username' => 'ws', 'aoo' => 'UCALPRG', 'agd' => '', 'autore' => 'Portale', 'fascicolazione_separata' => 0, 'password' => 'segreta-prova', 'attivo' => 1]);
[$rid_pd, $err_pd] = $cfg_pd->salvaRegola(0, ['nome' => 'Convenzioni FSL definitive', 'applica_a' => 'convenzione_fsl_definitivo', 'tipo_documento' => 'partenza', 'azione' => 'protocolla', 'uo' => '2014.2', 'cod_titolario' => '5/1', 'attiva' => 1, 'invia_email_rpa' => 0, 'rpa_nome' => 'Ufficio', 'rpa_username' => 'ufficio']);
prova($err_pd === null && $prot_pd->disponibile(), "con collegamento e regola dedicata il pulsante si mostra", (string)$err_pd);

// Una regola solo per l'ingresso non basta: il definitivo non eredita arrivo/partenza dell'altra
$conn->query("UPDATE titulus_regole SET applica_a = 'convenzione_fsl'");
prova(!$prot_pd->disponibile(), "la regola dell'ingresso non vale per il definitivo (si rischierebbe il tipo di documento sbagliato)");
$conn->query("UPDATE titulus_regole SET applica_a = 'convenzione_fsl_definitivo'");

// Controlli che non assegnano numeri
$e_pd = $prot_pd->protocolla(98913, $date_pd, '', 'ufficio@example.org');
prova($e_pd['errore'] !== null && str_contains($e_pd['errore'], 'firmato dal Direttore') && !$fin_pd->salvati, "convenzione non firmata dal Direttore sul portale: non si protocolla");
$e_pd = $prot_pd->protocolla(98914, $date_pd, '', 'ufficio@example.org');
prova($e_pd['errore'] !== null && str_contains($e_pd['errore'], 'anagrafe') && !$fin_pd->salvati, "scuola non in anagrafe: si ferma prima di chiedere il numero");
$e_pd = $prot_pd->protocolla(98911, ['data_stipula' => '2026-11-01', 'scadenza' => '2025-01-01'], '', 'ufficio@example.org');
prova($e_pd['errore'] !== null && str_contains($e_pd['errore'], 'date di validità') && !$fin_pd->salvati, "date incoerenti: si ferma prima di chiedere il numero");

// Caso normale, con Allegato A
$EMAIL = [];
$e_pd = $prot_pd->protocolla(98911, $date_pd, '', 'ufficio@example.org', 7);
$r_pd = $riga_pd(98911);
prova($e_pd['errore'] === null && $e_pd['numero'] === '2026-UCALPRG-0009001' && $e_pd['convenzione_id'] > 0, "protocollata e registrata: il numero lo dà Titulus", (string)$e_pd['errore']);
prova($r_pd['stato_iter'] === 'protocollata' && str_starts_with((string)$r_pd['protocollo'], '2026-UCALPRG-0009001 del ' . date('d/m/Y')), "la compilazione risulta protocollata con numero e data di oggi", (string)$r_pd['protocollo']);
$conv_pd = $conn->query("SELECT * FROM convenzioni_scuole WHERE id = " . (int)$e_pd['convenzione_id'])->fetch_assoc();
prova($conv_pd && $conv_pd['scuola_codice'] === 'ZZDF98910X' && $conv_pd['data_stipula'] === '2026-11-01' && $conv_pd['scadenza'] === '2031-10-31', "nel registro delle convenzioni ci sono scuola e date di validità indicate");
$inv_pd = $fin_pd->salvati[0] ?? null;
prova($inv_pd !== null && count($inv_pd['allegati']) === 2 && $inv_pd['allegati'][0]->contenuto === $file_pd[$cart_def_pd . 'def98911.pdf'] && $inv_pd['allegati'][1]->contenuto === $file_pd[$cart_fir_pd . 'all98911.pdf'], "a Titulus vanno il PDF del Direttore e l'Allegato A, integri");
prova($inv_pd !== null && str_contains($inv_pd['xml'], 'tipo="partenza"') && str_contains($inv_pd['xml'], 'FIRMATA DAL DIRETTORE'),"documento in partenza con oggetto che dice «firmata dal Direttore»");
$log_pd = $repo_pd->invii(10, ProtocollaDefinitivaFslInTitulus::RIF, 98911);
$ok_pd = array_values(array_filter($log_pd, fn($i) => $i['esito'] === 'ok'));
prova(count($ok_pd) === 1 && (int)$ok_pd[0]['utente_id'] === 7, "l'invio è nel registro con chi l'ha fatto");
prova(count(array_filter($EMAIL, fn($m) => $m['a'] === 'liceo.def@example.org')) >= 1, "la scuola riceve l'avviso di convenzione attiva, come con il numero a mano");
$ancora_pd = $prot_pd->protocolla(98911, $date_pd, '', 'ufficio@example.org');
prova($ancora_pd['errore'] !== null && count($fin_pd->salvati) === 1, "ripremere il pulsante non protocolla due volte");

// Titulus non risponde: nessuna registrazione
$fin_pd->errore = 'Servizio non raggiungibile';
$e_pd = $prot_pd->protocolla(98912, $date_pd, '', 'ufficio@example.org');
prova($e_pd['errore'] !== null && str_contains($e_pd['errore'], 'Servizio non raggiungibile') && $riga_pd(98912)['stato_iter'] === 'firmata_direttore', "Titulus in errore: la convenzione resta da protocollare");
$fin_pd->errore = null;

// Numero già assegnato ma registrazione fallita prima: si riusa
$prima_pd = count($fin_pd->salvati);
$conn->query("DELETE FROM titulus_invii WHERE rif_id = 98912");
$repo_pd->registraInvio(['regola_id' => $rid_pd, 'applica_a' => 'convenzione_fsl_definitivo', 'rif_tipo' => ProtocollaDefinitivaFslInTitulus::RIF, 'rif_id' => 98912, 'oggetto' => 'x', 'tipo_documento' => 'partenza', 'azione' => 'protocolla',
    'esito' => 'ok', 'nrecord' => '5', 'num_prot' => '2026-UCALPRG-0000555', 'url' => '', 'errore' => null, 'utente_id' => null]);
$e_pd = $prot_pd->protocolla(98912, $date_pd, '', 'ufficio@example.org');
prova($e_pd['errore'] === null && $e_pd['numero'] === '2026-UCALPRG-0000555' && count($fin_pd->salvati) === $prima_pd && $riga_pd(98912)['stato_iter'] === 'protocollata', "numero già assegnato: si riusa, non si manda di nuovo a Titulus");

$pulisci_pd();
foreach (array_keys($file_pd) as $p) @unlink($p);
