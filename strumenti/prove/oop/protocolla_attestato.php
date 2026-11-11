<?php
// Prove di «Protocolla in Titulus» sul protocollo in uscita dell'attestato (App\Didattica\ProtocollaAttestatoInTitulus): il PDF firmato dal
// Direttore va a Titulus come documento in partenza all'azienda, il numero chiude la pratica. Titulus è finto.
// Chiamato da esegui.php (variabili $conn, $q, $EMAIL). Pratiche di prova 98991-98995.
sezione("Attestati: protocollo in uscita con Titulus");

use App\Core\Sito;
use App\Didattica\AllegatiPratiche;
use App\Didattica\FirmaPraticaRepository;
use App\Didattica\PraticaRepository;
use App\Didattica\ProtocollaAttestatoInTitulus;
use App\Didattica\ServizioAttestati;
use App\Didattica\ServizioFlussoConvalide;
use App\Infrastructure\Sicurezza\Cifratura;
use Unical\Titulus\ClienteTitulus;
use App\Protocollo\Costanti as CostProto;
use App\Protocollo\ConfiguratoreProtocollo;
use Unical\Titulus\FabbricaClienteTitulus;
use Unical\Titulus\GeneratoreXml;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\ProtocolloRepository;
use App\Protocollo\ServizioProtocollo;

$fin_pa = new class implements ClienteTitulus {
    public array $salvati = [];
    public ?string $errore = null;
    public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array
    {
        if ($this->errore !== null) throw new RuntimeException($this->errore);
        $this->salvati[] = ['xml' => $xmlDocumento, 'allegati' => $allegati];
        return ['nrecord' => '904', 'num_prot' => '2026-UCALPRG-0006' . count($this->salvati), 'url' => ''];
    }
    public function fascicola(string $xmlFascicolo, string $matricolaRpa): void {}
    public function infoRecord(string $nrecord): array { return []; }
    public function urlDocumento(string $id): ?string { return null; }
    public function prova(): string { return 'ok'; }
};
$fab_pa = new class($fin_pa) implements FabbricaClienteTitulus {
    public function __construct(private ClienteTitulus $c) {}
    public function crea(\Unical\Titulus\ConfigTitulus $config, string $password): ClienteTitulus { return $this->c; }
};

$pulisci_pa = function () use ($conn, $q) {
    foreach (['titulus_invii', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $t) $conn->query("DELETE FROM $t");
    foreach (["DELETE FROM pratiche_firme WHERE pratica_id BETWEEN 98991 AND 98995", "DELETE FROM pratiche_eventi WHERE pratica_id BETWEEN 98991 AND 98995", "DELETE FROM pratiche WHERE id BETWEEN 98991 AND 98995"] as $sql) $q($sql);
};
$pulisci_pa();
$app_pa = \App\Core\App::per($conn);
$app_pa->get(ServizioFlussoConvalide::class)->preparaFlusso();
$q("UPDATE didattica_moduli SET attivo = 1 WHERE chiave IN ('attestato_frequenza', 'attestato_esame')");
$mod_pa = (int)$conn->query("SELECT id FROM didattica_moduli WHERE chiave = 'attestato_frequenza'")->fetch_assoc()['id'];
$altro_pa = (int)$conn->query("SELECT id FROM didattica_moduli WHERE chiave <> 'attestato_frequenza' AND chiave <> 'attestato_esame' LIMIT 1")->fetch_assoc()['id'];

$repo_pa = $app_pa->get(ProtocolloRepository::class);
$cifr_pa = new Cifratura('frase-di-prova-per-titulus-lunga');
$cfg_pa = new ConfiguratoreProtocollo($repo_pa, $cifr_pa);
$svc_pa = new ServizioProtocollo($repo_pa, $cifr_pa, $fab_pa, new GeneratoreXml());
$prot_pa = new ProtocollaAttestatoInTitulus($app_pa->get(PraticaRepository::class), $app_pa->get(FirmaPraticaRepository::class), $app_pa->get(AllegatiPratiche::class),
    $app_pa->get(ServizioAttestati::class), new InvioProtocollo($svc_pa, $repo_pa), $app_pa->get(Sito::class));

// PDF firmato dal Direttore nella cartella degli allegati delle pratiche
$cart_pa = $app_pa->get(AllegatiPratiche::class)->cartella();
$dir_pa = $app_pa->get(Sito::class)->radice() . '/' . $cart_pa;
$app_pa->get(AllegatiPratiche::class)->proteggi();
$pdf_pa = "%PDF-1.4\n" . str_repeat('attestato firmato ', 40);
$pdf_seg = "%PDF-1.4\n" . str_repeat('attestato con segnatura ', 40);
file_put_contents($dir_pa . 'pa98991_firmato.pdf', $pdf_pa);

$risposte_pa = $conn->real_escape_string(json_encode([
    ['etichetta' => 'Ente o azienda', 'tipo' => 'text', 'valore' => 'Azienda Calabra S.r.l.'],
    ['etichetta' => "PEC o email dell'azienda", 'tipo' => 'email', 'valore' => 'azienda@pec.example.org'],
]));
$q("INSERT INTO pratiche (id, modulo_id, codice, nome, cognome, matricola, email, stato, passo, risposte_json) VALUES
    (98991, $mod_pa, 'AT-1', 'Luca', 'Rossi', '245678', 'luca.at@example.org', 'in_lavorazione', 4, '$risposte_pa'),
    (98992, $mod_pa, 'AT-2', 'Sara', 'Blu', '245679', 'sara.at@example.org', 'in_lavorazione', 4, '$risposte_pa'),
    (98993, $mod_pa, 'AT-3', 'Gino', 'Verdi', '245680', 'gino.at@example.org', 'in_lavorazione', 3, '$risposte_pa'),
    (98994, $mod_pa, 'AT-4', 'Anna', 'Neri', '245681', 'anna.at@example.org', 'in_lavorazione', 4, '$risposte_pa'),
    (98995, $altro_pa, 'AT-5', 'Pia', 'Gialli', '245682', 'pia.at@example.org', 'in_lavorazione', 1, '$risposte_pa')");
// Firma del Direttore: 98991 e 98992 con file, 98994 senza file
$q("INSERT INTO pratiche_firme (pratica_id, passo, tipo, nome, email, token, stato, file_firmato, firmata_il) VALUES
    (98991, 3, 'direttore', 'Direttore', 'direttore@example.org', 'tokpa98991', 'firmata', '{$cart_pa}pa98991_firmato.pdf', NOW()),
    (98992, 3, 'direttore', 'Direttore', 'direttore@example.org', 'tokpa98992', 'firmata', '{$cart_pa}pa98991_firmato.pdf', NOW()),
    (98994, 3, 'direttore', 'Direttore', 'direttore@example.org', 'tokpa98994', 'attesa', NULL, NULL)");
$riga_pa = fn(int $id) => $conn->query("SELECT * FROM pratiche WHERE id = $id")->fetch_assoc();
$ctx_pa = CostProto::PREFISSO_USCITA . $mod_pa;

prova(isset($cfg_pa->contesti()[$ctx_pa]), "il pannello offre la regola «Pratica, protocollo in uscita» per il modulo dell'attestato");
prova(!isset($cfg_pa->contesti()[CostProto::PREFISSO_USCITA . $altro_pa]), "un modulo senza passo di uscita non ha la regola di uscita");

// Senza collegamento
prova(!$prot_pa->disponibile($mod_pa), "senza collegamento a Titulus il pulsante non si mostra");
$e_pa = $prot_pa->protocolla(98991, 1, 'Anna Ufficio');
prova($e_pa['errore'] !== null && str_contains($e_pa['errore'], 'Titulus') && $riga_pa(98991)['stato'] === 'in_lavorazione' && !$fin_pa->salvati, "senza collegamento: errore, la pratica resta al protocollo in uscita", (string)$e_pa['errore']);

// Collegamento e regola di uscita del modulo
$cfg_pa->salvaCollegamento(['nome' => 'T', 'url_ws' => 'https://titulus.example.org/ws?wsdl', 'username' => 'ws', 'aoo' => 'UCALPRG', 'agd' => '', 'autore' => 'Portale', 'fascicolazione_separata' => 0, 'password' => 'segreta-prova', 'attivo' => 1]);
[$rid_pa, $err_pa] = $cfg_pa->salvaRegola(0, ['nome' => 'Attestati in uscita', 'applica_a' => $ctx_pa, 'tipo_documento' => 'partenza', 'azione' => 'protocolla', 'uo' => '2014.2', 'cod_titolario' => '5/1', 'attiva' => 1, 'invia_email_rpa' => 0, 'rpa_nome' => 'Ufficio', 'rpa_username' => 'ufficio']);
prova($err_pa === null && $prot_pa->disponibile($mod_pa), "con collegamento e regola di uscita il pulsante si mostra", (string)$err_pa);
$conn->query("UPDATE titulus_regole SET applica_a = 'pratica:$mod_pa'");
prova(!$prot_pa->disponibile($mod_pa), "la regola della domanda non vale per l'uscita (arrivo/partenza diversi)");
$conn->query("UPDATE titulus_regole SET applica_a = '$ctx_pa'");

// Fuori fase o senza PDF del Direttore: nulla a Titulus
$e_pa = $prot_pa->protocolla(98993, 1, 'Anna Ufficio');
prova($e_pa['errore'] !== null && str_contains($e_pa['errore'], 'uscita') && !$fin_pa->salvati, "pratica non ancora al protocollo in uscita: non si protocolla");
$e_pa = $prot_pa->protocolla(98994, 1, 'Anna Ufficio');
prova($e_pa['errore'] !== null && str_contains($e_pa['errore'], 'Direttore') && !$fin_pa->salvati, "senza il PDF firmato dal Direttore sul portale: non si protocolla");

// Caso normale
$EMAIL = [];
$e_pa = $prot_pa->protocolla(98991, 7, 'Anna Ufficio', '', '01234567890');
$r_pa = $riga_pa(98991);
prova($e_pa['errore'] === null && $e_pa['numero'] === '2026-UCALPRG-00061', "protocollato: il numero lo dà Titulus", (string)$e_pa['errore']);
prova($r_pa['stato'] === 'chiusa' && $r_pa['protocollo'] === '2026-UCALPRG-00061' && $r_pa['protocollo_data'] === date('Y-m-d'), "la pratica è chiusa con numero e data di oggi", json_encode([$r_pa['stato'], $r_pa['protocollo']]));
$inv_pa = $fin_pa->salvati[0] ?? null;
prova($inv_pa !== null && count($inv_pa['allegati']) === 1 && $inv_pa['allegati'][0]->contenuto === $pdf_pa, "a Titulus va il PDF firmato dal Direttore, integro");
prova($inv_pa !== null && str_contains($inv_pa['xml'], 'tipo="partenza"') && str_contains($inv_pa['xml'], 'AZIENDA CALABRA S.R.L.') && str_contains($inv_pa['xml'], '01234567890') && str_contains($inv_pa['xml'], 'azienda@pec.example.org'), "documento in partenza verso l'azienda, con codice fiscale e PEC");
prova(count(array_filter($EMAIL, fn($m) => $m['a'] === 'luca.at@example.org')) >= 1, "lo studente riceve l'avviso che l'attestato è pronto");
$log_pa = array_values(array_filter($repo_pa->invii(10, ProtocollaAttestatoInTitulus::RIF, 98991), fn($i) => $i['esito'] === 'ok'));
prova(count($log_pa) === 1 && (int)$log_pa[0]['utente_id'] === 7, "l'invio è nel registro con chi l'ha fatto");
$e_pa = $prot_pa->protocolla(98991, 7, 'Anna Ufficio', '', '01234567890');
prova($e_pa['errore'] !== null && count($fin_pa->salvati) === 1, "ripremere il pulsante non protocolla due volte");

// Con PDF con segnatura caricato a mano: è quello che vede lo studente
$e_pa = $prot_pa->protocolla(98992, 7, 'Anna Ufficio', $pdf_seg, '01234567890');
prova($e_pa['errore'] === null && $riga_pa(98992)['stato'] === 'chiusa', "con il PDF con segnatura la pratica si chiude lo stesso", (string)$e_pa['errore']);
$ev_pa = $conn->query("SELECT allegato FROM pratiche_eventi WHERE pratica_id = 98992 AND nome_allegato LIKE 'Firmato_direttore_%'")->fetch_assoc();
prova($ev_pa && is_file($app_pa->get(Sito::class)->radice() . '/' . $ev_pa['allegato']) && file_get_contents($app_pa->get(Sito::class)->radice() . '/' . $ev_pa['allegato']) === $pdf_seg, "allo studente va il PDF con la segnatura");
prova(count($fin_pa->salvati) === 2 && $fin_pa->salvati[1]['allegati'][0]->contenuto === $pdf_pa, "a Titulus va comunque il PDF firmato dal Direttore");

// Titulus in errore: la pratica resta, poi riprova
$q("UPDATE pratiche SET stato = 'in_lavorazione', passo = 4, protocollo = '', protocollo_data = NULL WHERE id = 98992");
$conn->query("DELETE FROM titulus_invii WHERE rif_id = 98992");
$fin_pa->errore = 'Servizio non raggiungibile';
$e_pa = $prot_pa->protocolla(98992, 7, 'Anna Ufficio', '', '01234567890');
prova($e_pa['errore'] !== null && str_contains($e_pa['errore'], 'Servizio non raggiungibile') && $riga_pa(98992)['stato'] === 'in_lavorazione', "Titulus in errore: la pratica resta al protocollo in uscita");
$fin_pa->errore = null;
$e_pa = $prot_pa->protocolla(98992, 7, 'Anna Ufficio', '', '01234567890');
prova($e_pa['errore'] === null && $riga_pa(98992)['stato'] === 'chiusa', "riprovando va a buon fine");

$pulisci_pa();
@unlink($dir_pa . 'pa98991_firmato.pdf');
