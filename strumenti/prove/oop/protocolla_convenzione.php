<?php
// Prove di «Protocolla in Titulus» sulla convenzione FSL caricata dalla scuola (App\Fsl\ProtocollaConvenzioneInTitulus): il numero assegnato
// da Titulus diventa il protocollo in entrata, senza protocollare due volte. Titulus è finto. Chiamato da esegui.php (variabili $conn, $q, $EMAIL).
// Compilazioni di prova con id 989xx.
sezione("Modulo FSL: protocollo in entrata con Titulus");

use App\Core\Sito;
use App\Fsl\ConvenzioneCompilataRepository;
use App\Fsl\ProtocollaConvenzioneInTitulus;
use App\Fsl\ServizioConvenzioneFirmata;
use App\Infrastructure\Sicurezza\Cifratura;
use Unical\Titulus\ClienteTitulus;
use App\Protocollo\ConfiguratoreProtocollo;
use Unical\Titulus\FabbricaClienteTitulus;
use Unical\Titulus\GeneratoreXml;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\ProtocolloRepository;
use App\Protocollo\ServizioProtocollo;

$fin_pc = new class implements ClienteTitulus {
    public array $salvati = [];
    public ?string $errore = null;
    public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array
    {
        if ($this->errore !== null) throw new RuntimeException($this->errore);
        $this->salvati[] = ['xml' => $xmlDocumento, 'allegati' => $allegati];
        return ['nrecord' => '888', 'num_prot' => str_contains($xmlDocumento, 'bozza="si"') ? '' : '2026-UCALPRG-0004321', 'url' => 'https://titulus.example.org/doc/888'];
    }
    public function fascicola(string $xmlFascicolo, string $matricolaRpa): void {}
    public function infoRecord(string $nrecord): array { return []; }
    public function urlDocumento(string $id): ?string { return null; }
    public function prova(): string { return 'ok'; }
};
$fab_pc = new class($fin_pc) implements FabbricaClienteTitulus {
    public function __construct(private ClienteTitulus $c) {}
    public function crea(\Unical\Titulus\ConfigTitulus $config, string $password): ClienteTitulus { return $this->c; }
};

foreach (['titulus_invii', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
$q("DELETE FROM convenzioni_compilate WHERE id BETWEEN 98901 AND 98905");
$app_pc = \App\Core\App::per($conn);
$repo_pc = $app_pc->get(ProtocolloRepository::class);
$cifr_pc = new Cifratura('frase-di-prova-per-titulus-lunga');
$cfg_pc = new ConfiguratoreProtocollo($repo_pc, $cifr_pc);
$svc_pc = new ServizioProtocollo($repo_pc, $cifr_pc, $fab_pc, new GeneratoreXml());
$iter_pc = $app_pc->get(ServizioConvenzioneFirmata::class);
$compilate_pc = $app_pc->get(ConvenzioneCompilataRepository::class);
$prot_pc = new ProtocollaConvenzioneInTitulus($compilate_pc, $iter_pc, new InvioProtocollo($svc_pc, $repo_pc));

// File firmati veri nella cartella protetta delle convenzioni firmate
$cartella_pc = $app_pc->get(Sito::class)->radice() . '/' . \App\Fsl\Costanti::DIR_FIRMATE;
if (!is_dir($cartella_pc)) mkdir($cartella_pc, 0775, true);
$file_pc = ['conv98901.pdf' => "%PDF-1.4\n" . str_repeat('convenzione ', 40), 'all98901.pdf' => "%PDF-1.4\n" . str_repeat('allegato ', 40), 'conv98902.pdf' => "%PDF-1.4\n" . str_repeat('altra ', 40)];
foreach ($file_pc as $n => $c) file_put_contents($cartella_pc . $n, $c);
$dati_pc = $conn->real_escape_string(json_encode(['scuola' => ['denominazione' => 'Liceo Prova Titulus', 'codice' => 'ZZLP98900A', 'cf' => '80001230789', 'indirizzo' => 'Via Roma 1', 'comune' => 'Rende', 'pec' => 'liceo.prova@pec.example.org', 'email' => 'liceo.prova@example.org']]));
$q("INSERT INTO convenzioni_compilate (id, token, email, dati_json, stato_iter, file_firmato, file_allegato_scuola, firmatario) VALUES
    (98901, 'tokprot98901', 'liceo.prova@example.org', '$dati_pc', 'caricata_scuola', 'uploads/convenzioni_compilate/firmate/conv98901.pdf', 'uploads/convenzioni_compilate/firmate/all98901.pdf', 'Dirigente Prova'),
    (98902, 'tokprot98902', 'liceo.prova@example.org', '$dati_pc', 'caricata_scuola', 'uploads/convenzioni_compilate/firmate/conv98902.pdf', NULL, 'Dirigente Prova'),
    (98903, 'tokprot98903', 'liceo.prova@example.org', '$dati_pc', 'caricata_scuola', 'uploads/convenzioni_compilate/firmate/conv98902.pdf', NULL, 'Dirigente Prova'),
    (98904, 'tokprot98904', 'liceo.prova@example.org', '$dati_pc', 'caricata_scuola', 'uploads/convenzioni_compilate/firmate/conv98902.pdf', NULL, 'Dirigente Prova'),
    (98905, 'tokprot98905', 'liceo.prova@example.org', '$dati_pc', '', NULL, NULL, '')");
$riga_pc = fn(int $id) => $conn->query("SELECT * FROM convenzioni_compilate WHERE id = $id")->fetch_assoc();

// Senza collegamento a Titulus il pulsante non c'è e nulla parte
prova(!$prot_pc->disponibile(), "senza collegamento a Titulus il pulsante non si mostra");
$e_pc = $prot_pc->protocolla(98901, 'ufficio@example.org');
prova($e_pc['errore'] !== null && str_contains($e_pc['errore'], 'Titulus') && $riga_pc(98901)['stato_iter'] === 'caricata_scuola' && !$fin_pc->salvati, "senza collegamento: errore chiaro, la convenzione resta da prendere in carico", (string)$e_pc['errore']);

// Collegamento e regola per le convenzioni
$cfg_pc->salvaCollegamento(['nome' => 'T', 'url_ws' => 'https://titulus.example.org/ws?wsdl', 'username' => 'ws', 'aoo' => 'UCALPRG', 'agd' => '', 'autore' => 'Portale', 'fascicolazione_separata' => 0, 'password' => 'segreta-prova', 'attivo' => 1]);
[$rid_pc, $err_pc] = $cfg_pc->salvaRegola(0, ['nome' => 'Convenzioni FSL', 'applica_a' => 'convenzione_fsl', 'tipo_documento' => 'arrivo', 'azione' => 'protocolla', 'uo' => '2014.2', 'cod_titolario' => '5/1', 'attiva' => 1, 'invia_email_rpa' => 0]);
prova($err_pc === null && $prot_pc->disponibile(), "con collegamento e regola il pulsante si mostra", (string)$err_pc);

// Fuori fase o senza file: non si manda nulla a Titulus
$e_pc = $prot_pc->protocolla(98905, 'ufficio@example.org');
prova($e_pc['errore'] !== null && !$fin_pc->salvati, "senza file firmato non si protocolla");
$q("UPDATE convenzioni_compilate SET stato_iter = 'in_carico' WHERE id = 98904");
$e_pc = $prot_pc->protocolla(98904, 'ufficio@example.org');
prova($e_pc['errore'] !== null && str_contains($e_pc['errore'], 'non è in attesa') && !$fin_pc->salvati, "già presa in carico: non si protocolla di nuovo");

// Caso normale
$EMAIL = [];
$e_pc = $prot_pc->protocolla(98901, 'ufficio@example.org', 7);
$r_pc = $riga_pc(98901);
prova($e_pc['errore'] === null && $e_pc['numero'] === '2026-UCALPRG-0004321', "protocollata: il numero lo dà Titulus", (string)$e_pc['errore']);
prova(in_array($r_pc['stato_iter'], ['in_carico', 'da_firmare'], true) && $r_pc['protocollo_ingresso'] === '2026-UCALPRG-0004321' && $r_pc['protocollo_ingresso_data'] === date('Y-m-d') && $r_pc['in_carico_da'] === 'ufficio@example.org', "la convenzione è presa in carico (e va al Direttore) con numero e data di oggi");
$inv_pc = $fin_pc->salvati[0] ?? null;
prova($inv_pc !== null && count($inv_pc['allegati']) === 2 && $inv_pc['allegati'][0]->contenuto === $file_pc['conv98901.pdf'] && $inv_pc['allegati'][1]->contenuto === $file_pc['all98901.pdf'], "a Titulus vanno la convenzione firmata e l'Allegato A, integri");
prova($inv_pc !== null && str_contains($inv_pc['xml'], 'tipo="arrivo"') && str_contains($inv_pc['xml'], 'LICEO PROVA TITULUS') && str_contains($inv_pc['xml'], 'liceo.prova@pec.example.org'), "documento in arrivo con oggetto e indirizzo (PEC) della scuola");
$log_pc = $repo_pc->invii(10, ProtocollaConvenzioneInTitulus::RIF, 98901);
$ok_pc = array_values(array_filter($log_pc, fn($i) => $i['esito'] === 'ok'));
prova(count($ok_pc) === 1 && (int)$ok_pc[0]['utente_id'] === 7 && $ok_pc[0]['num_prot'] === '2026-UCALPRG-0004321', "l'invio è nel registro, con chi l'ha fatto");
prova(count(array_filter($EMAIL, fn($m) => $m['a'] === 'liceo.prova@example.org')) >= 1, "la scuola riceve l'avviso di presa in carico, come con il numero a mano");
$ancora_pc = $prot_pc->protocolla(98901, 'ufficio@example.org');
prova($ancora_pc['errore'] !== null && count($fin_pc->salvati) === 1, "ripremere il pulsante non protocolla due volte");

// Titulus non risponde: nessuna modifica nel portale, errore registrato
$fin_pc->errore = 'Servizio non raggiungibile';
$e_pc = $prot_pc->protocolla(98902, 'ufficio@example.org');
$log_pc = $repo_pc->invii(10, ProtocollaConvenzioneInTitulus::RIF, 98902);
prova($e_pc['errore'] !== null && str_contains($e_pc['errore'], 'Servizio non raggiungibile') && $riga_pc(98902)['stato_iter'] === 'caricata_scuola' && $riga_pc(98902)['protocollo_ingresso'] === '', "Titulus in errore: la convenzione resta da prendere in carico");
prova(count($log_pc) === 1 && $log_pc[0]['esito'] === 'errore', "l'errore resta nel registro degli invii");
$fin_pc->errore = null;

// Dopo l'errore si può riprovare, e ora va
$e_pc = $prot_pc->protocolla(98902, 'ufficio@example.org');
prova($e_pc['errore'] === null && in_array($riga_pc(98902)['stato_iter'], ['in_carico', 'da_firmare'], true), "riprovando dopo l'errore va a buon fine");

// Protocollo già assegnato ma registrazione non riuscita: si riusa il numero, niente secondo protocollo
$prima_pc = count($fin_pc->salvati);
$repo_pc->registraInvio(['regola_id' => $rid_pc, 'applica_a' => 'convenzione_fsl', 'rif_tipo' => ProtocollaConvenzioneInTitulus::RIF, 'rif_id' => 98903, 'oggetto' => 'x', 'tipo_documento' => 'arrivo', 'azione' => 'protocolla',
    'esito' => 'ok', 'nrecord' => '5', 'num_prot' => '2026-UCALPRG-0000777', 'url' => '', 'errore' => null, 'utente_id' => null]);
$e_pc = $prot_pc->protocolla(98903, 'ufficio@example.org');
prova($e_pc['errore'] === null && $e_pc['numero'] === '2026-UCALPRG-0000777' && count($fin_pc->salvati) === $prima_pc && $riga_pc(98903)['protocollo_ingresso'] === '2026-UCALPRG-0000777', "numero già assegnato: si riusa, non si manda di nuovo");

// Regola che avvia l'iter: il numero non c'è, la convenzione resta com'è e il motivo è chiaro
$q("UPDATE convenzioni_compilate SET stato_iter = 'caricata_scuola', protocollo_ingresso = '' WHERE id = 98903");
$conn->query("DELETE FROM titulus_invii");
$cfg_pc->salvaVoce(0, 'Iter Prova', true);
$voce_pc = (int)$repo_pc->vociIndice()[0]['id'];
$conn->query("UPDATE titulus_regole SET azione = 'iter', voce_indice_id = $voce_pc WHERE id = $rid_pc");
$e_pc = $prot_pc->protocolla(98903, 'ufficio@example.org');
prova($e_pc['errore'] !== null && str_contains($e_pc['errore'], 'iter') && $riga_pc(98903)['stato_iter'] === 'caricata_scuola', "regola con iter: niente numero subito, la convenzione resta da registrare e si spiega perché", (string)$e_pc['errore']);

// Pulizia
foreach (['titulus_invii', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
$q("DELETE FROM convenzioni_compilate WHERE id BETWEEN 98901 AND 98905");
foreach (array_keys($file_pc) as $n) @unlink($cartella_pc . $n);
