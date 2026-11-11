<?php
// Prove di «Protocolla in Titulus» per la domanda di una pratica (App\Didattica\ProtocollaPraticaInTitulus): regola per modulo («pratica:<id>»),
// domanda in PDF/A e allegati dello studente, registrazione del numero. Titulus è finto. Chiamato da esegui.php (variabili $conn, $q).
// Moduli di prova 98981-98982, pratiche 98981-98983.
sezione("Pratiche: protocollo della domanda con Titulus");

use App\Core\Sito;
use App\Didattica\AllegatiPratiche;
use App\Didattica\ProtocollaPraticaInTitulus;
use App\Infrastructure\Sicurezza\Cifratura;
use Unical\Titulus\ClienteTitulus;
use App\Protocollo\ConfiguratoreProtocollo;
use Unical\Titulus\FabbricaClienteTitulus;
use Unical\Titulus\GeneratoreXml;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\ProtocolloRepository;
use App\Protocollo\ServizioProtocollo;

$fin_pp = new class implements ClienteTitulus {
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
$fab_pp = new class($fin_pp) implements FabbricaClienteTitulus {
    public function __construct(private ClienteTitulus $c) {}
    public function crea(\Unical\Titulus\ConfigTitulus $config, string $password): ClienteTitulus { return $this->c; }
};

foreach (['titulus_invii', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
foreach (["DELETE FROM pratiche_eventi WHERE pratica_id BETWEEN 98981 AND 98983", "DELETE FROM pratiche WHERE id BETWEEN 98981 AND 98983", "DELETE FROM didattica_moduli WHERE id BETWEEN 98981 AND 98982"] as $sql) $q($sql);
$app_pp = \App\Core\App::per($conn);
$repo_pp = $app_pp->get(ProtocolloRepository::class);
$cifr_pp = new Cifratura('frase-di-prova-per-titulus-lunga');
$cfg_pp = new ConfiguratoreProtocollo($repo_pp, $cifr_pp);
$svc_pp = new ServizioProtocollo($repo_pp, $cifr_pp, $fab_pp, new GeneratoreXml());
$prot_pp = new ProtocollaPraticaInTitulus($app_pp->get(\App\Core\Database::class), $app_pp->get(\App\Didattica\PraticaRepository::class), $app_pp->get(\App\Didattica\DomandaPdfa::class),
    $app_pp->get(AllegatiPratiche::class), $app_pp->get(\App\Didattica\ServizioFlussoConvalide::class), new InvioProtocollo($svc_pp, $repo_pp), $app_pp->get(Sito::class));

$cart_pp = $app_pp->get(AllegatiPratiche::class)->cartella();
$dir_pp = $app_pp->get(Sito::class)->radice() . '/' . $cart_pp;
$app_pp->get(AllegatiPratiche::class)->proteggi();
$pdf_dom = "%PDF-1.4\n" . str_repeat('domanda ', 40);
$pdf_all = "%PDF-1.4\n" . str_repeat('allegato ', 40);
file_put_contents($dir_pp . 'pp98981_domanda.pdf', $pdf_dom);
file_put_contents($dir_pp . 'pp98981_allegato.pdf', $pdf_all);
$q("INSERT INTO didattica_moduli (id, titolo, chiave, attivo, tipo) VALUES (98981, 'Richiesta di prova protocollo', 'prova_pp81', 1, 'online'), (98982, 'Altro modulo di prova', 'prova_pp82', 1, 'online')");
$q("INSERT INTO pratiche (id, modulo_id, codice, nome, cognome, matricola, email, stato, domanda_pdf) VALUES
    (98981, 98981, 'PP-1', 'Luca', 'Gialli', '246810', 'luca.pp@example.org', 'inviata', '{$cart_pp}pp98981_domanda.pdf'),
    (98982, 98982, 'PP-2', 'Sara', 'Blu', '135790', 'sara.pp@example.org', 'inviata', '{$cart_pp}pp98981_domanda.pdf'),
    (98983, 98981, 'PP-3', 'Gino', 'Rossi', '111111', 'gino.pp@example.org', 'inviata', NULL)");
$q("UPDATE pratiche SET protocollo = '123/2026', protocollo_data = CURDATE() WHERE id = 98983");
$q("INSERT INTO pratiche_eventi (pratica_id, tipo, autore, testo, allegato, nome_allegato, interno) VALUES
    (98981, 'attivita', 'studente', 'Domanda', '{$cart_pp}pp98981_domanda.pdf', 'Domanda_PP-1.pdf', 0),
    (98981, 'messaggio', 'studente', 'Allegato', '{$cart_pp}pp98981_allegato.pdf', 'Certificato.pdf', 0),
    (98981, 'messaggio', 'ufficio', 'Nota', '{$cart_pp}pp98981_allegato.pdf', 'Interno.pdf', 1)");
$riga_pp = fn(int $id) => $conn->query("SELECT * FROM pratiche WHERE id = $id")->fetch_assoc();

prova(!$prot_pp->disponibile(98981), "senza collegamento a Titulus il pulsante non si mostra");
$e_pp = $prot_pp->protocolla(98981, 1, 'Anna Protocollo');
prova($e_pp['errore'] !== null && $riga_pp(98981)['protocollo'] === '' && !$fin_pp->salvati, "senza collegamento: errore, la pratica resta da protocollare");

$cfg_pp->salvaCollegamento(['nome' => 'T', 'url_ws' => 'https://titulus.example.org/ws?wsdl', 'username' => 'ws', 'aoo' => 'UCALPRG', 'agd' => '', 'autore' => 'Portale', 'fascicolazione_separata' => 0, 'password' => 'segreta-prova', 'attivo' => 1]);
$base_rg = ['tipo_documento' => 'arrivo', 'azione' => 'protocolla', 'uo' => '2014.2', 'cod_titolario' => '5/1', 'attiva' => 1, 'invia_email_rpa' => 0];
[$rid_pp, $err_pp] = $cfg_pp->salvaRegola(0, ['nome' => 'Richiesta di prova', 'applica_a' => 'pratica:98981'] + $base_rg);
prova($err_pp === null && $prot_pp->disponibile(98981) && !$prot_pp->disponibile(98982), "la regola del modulo vale solo per quel modulo", (string)$err_pp);
[$rid_gen_pp, $e0] = $cfg_pp->salvaRegola(0, ['nome' => 'Riserva', 'applica_a' => 'generale'] + $base_rg);
prova($prot_pp->disponibile(98982) && (int)$repo_pp->regolaPer('pratica:98982')['id'] === $rid_gen_pp && (int)$repo_pp->regolaPer('pratica:98981')['id'] === $rid_pp, "gli altri moduli usano la regola generale, quello dedicato la sua");

$e_pp = $prot_pp->protocolla(98981, 1, 'Anna Protocollo');
$r_pp = $riga_pp(98981);
prova($e_pp['errore'] === null && $e_pp['numero'] === '2026-UCALPRG-00071', "protocollata: il numero lo dà Titulus", (string)$e_pp['errore']);
prova($r_pp['protocollo'] === '2026-UCALPRG-00071' && $r_pp['protocollo_data'] === date('Y-m-d'), "il numero e la data di oggi sono registrati nella pratica");
$inv_pp = $fin_pp->salvati[0] ?? null;
prova($inv_pp !== null && count($inv_pp['allegati']) === 2 && $inv_pp['allegati'][0]->contenuto === $pdf_dom && $inv_pp['allegati'][1]->contenuto === $pdf_all, "a Titulus vanno la domanda e l'allegato dello studente (non le note interne)");
prova($inv_pp !== null && str_contains(mb_strtoupper($inv_pp['xml']), 'LUCA') && str_contains(mb_strtoupper($inv_pp['xml']), 'GIALLI') && str_contains($inv_pp['xml'], 'tipo="arrivo"'), "il documento è in arrivo dallo studente");
$log_pp = array_values(array_filter($repo_pp->invii(10, ProtocollaPraticaInTitulus::RIF, 98981), fn($i) => $i['esito'] === 'ok'));
prova(count($log_pp) === 1 && (int)$log_pp[0]['utente_id'] === 1, "l'invio è nel registro, con chi l'ha fatto");
$e_pp = $prot_pp->protocolla(98981, 1, 'Anna Protocollo');
prova($e_pp['errore'] !== null && count($fin_pp->salvati) === 1, "una pratica già protocollata non si protocolla di nuovo");
$e_pp = $prot_pp->protocolla(98983, 1, 'Anna Protocollo');
prova($e_pp['errore'] !== null && str_contains($e_pp['errore'], 'già un protocollo') && count($fin_pp->salvati) === 1, "una pratica già protocollata a mano non si manda a Titulus");

$fin_pp->errore = 'Servizio non raggiungibile';
$e_pp = $prot_pp->protocolla(98982, 1, 'Anna Protocollo');
prova($e_pp['errore'] !== null && str_contains($e_pp['errore'], 'Servizio non raggiungibile') && $riga_pp(98982)['protocollo'] === '', "Titulus in errore: la pratica resta da protocollare");
$fin_pp->errore = null;
$e_pp = $prot_pp->protocolla(98982, 1, 'Anna Protocollo');
prova($e_pp['errore'] === null && $riga_pp(98982)['protocollo'] !== '', "riprovando va a buon fine (regola generale)");

foreach (['titulus_invii', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
foreach (["DELETE FROM pratiche_eventi WHERE pratica_id BETWEEN 98981 AND 98983", "DELETE FROM pratiche WHERE id BETWEEN 98981 AND 98983", "DELETE FROM didattica_moduli WHERE id BETWEEN 98981 AND 98982"] as $sql) $q($sql);
@unlink($dir_pp . 'pp98981_domanda.pdf');
@unlink($dir_pp . 'pp98981_allegato.pdf');
