<?php
// Prove di «Protocolla in Titulus» per la domanda di una pratica (App\Didattica\ProtocollaPraticaInTitulus): regola per modulo («pratica:<id>»),
// domanda in PDF/A e allegati dello studente, registrazione del numero. Titulus è finto. Chiamato da esegui.php (variabili $conn, $q).
// Moduli di prova 98981-98982, pratiche 98981-98988.
sezione("Pratiche: protocollo della domanda con Titulus");

use App\Core\Sito;
use App\Didattica\AllegatiPratiche;
use App\Didattica\ProtocollaPraticaInTitulus;
use App\Didattica\ProtocolloAutomaticoPratica;
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
    public function fileDelDocumento(string $id): array { return []; }
    public function scaricaFile(string $fileId): string { throw new RuntimeException('Il finto non ha file.'); }
    public function prova(): string { return 'ok'; }
};
$fab_pp = new class($fin_pp) implements FabbricaClienteTitulus {
    public function __construct(private ClienteTitulus $c) {}
    public function crea(\Unical\Titulus\ConfigTitulus $config, string $password): ClienteTitulus { return $this->c; }
};

foreach (['titulus_invii', 'protocollo_file', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
foreach (["DELETE FROM pratiche_eventi WHERE pratica_id BETWEEN 98979 AND 98990", "DELETE FROM pratiche WHERE id BETWEEN 98979 AND 98990", "DELETE FROM didattica_moduli WHERE id BETWEEN 98981 AND 98987"] as $sql) $q($sql);
$app_pp = \App\Core\App::per($conn);
$repo_pp = $app_pp->get(ProtocolloRepository::class);
$cifr_pp = new Cifratura('frase-di-prova-per-titulus-lunga');
$cfg_pp = new ConfiguratoreProtocollo($repo_pp, $cifr_pp);
$svc_pp = new ServizioProtocollo($repo_pp, $cifr_pp, $fab_pp, new GeneratoreXml());
$prot_pp = new ProtocollaPraticaInTitulus($app_pp->get(\App\Core\Database::class), $app_pp->get(\App\Didattica\PraticaRepository::class), $app_pp->get(\App\Didattica\DomandaPdfa::class),
    $app_pp->get(AllegatiPratiche::class), $app_pp->get(\App\Didattica\ServizioFlussoConvalide::class), new InvioProtocollo($svc_pp, $repo_pp), $app_pp->get(Sito::class),
    $fp_pp = new \App\Protocollo\FileProtocollato($app_pp->get(\App\Core\Database::class), $svc_pp, $app_pp->get(\App\Infrastructure\Storage\Upload::class), $app_pp->get(Sito::class)));

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
// La copia della domanda con la segnatura: nuovo evento nello storico (visibile allo studente), file creato, domanda originale intatta
$ev_seg = $conn->query("SELECT * FROM pratiche_eventi WHERE pratica_id = 98981 AND nome_allegato = 'Domanda_PP-1_protocollata.pdf'")->fetch_assoc();
prova($ev_seg !== null && (int) $ev_seg['interno'] === 0 && str_contains((string) $ev_seg['testo'], 'Prot. n. 2026-UCALPRG-00071 del ' . date('d/m/Y')), "dopo il protocollo la domanda con la segnatura entra nello storico, visibile allo studente");
$f_seg = $ev_seg ? $app_pp->get(Sito::class)->radice() . '/' . $ev_seg['allegato'] : '';
prova($f_seg !== '' && is_file($f_seg) && str_starts_with((string) file_get_contents($f_seg), '%PDF'), "il file con la segnatura è un PDF salvato nella cartella protetta delle pratiche");
prova($riga_pp(98981)['domanda_pdf'] === $cart_pp . 'pp98981_domanda.pdf' && count($fin_pp->salvati) === 1, "la domanda originale (quella mandata a Titulus) non cambia");
// Passo 7: due voci, originale identico byte per byte e documento protocollato = la copia con la segnatura (per consultazione)
prova(file_get_contents($dir_pp . 'pp98981_domanda.pdf') === $pdf_dom, "passo 7: il file della domanda PDF/A è identico byte per byte dopo il protocollo");
$o_pp = $fp_pp->apri(\App\Protocollo\FileProtocollato::RIF_DOMANDA, 98981, 'originale');
$d_pp = $fp_pp->apri(\App\Protocollo\FileProtocollato::RIF_DOMANDA, 98981, 'protocollato');
prova($o_pp !== null && file_get_contents($o_pp['percorso']) === $pdf_dom, "passo 7: «Originale» della domanda è la domanda PDF/A, integra");
prova($d_pp !== null && $f_seg !== '' && realpath($d_pp['percorso']) === realpath($f_seg) && $d_pp['percorso'] !== $o_pp['percorso'], "passo 7: «Documento protocollato» è la copia con la segnatura, un file diverso dall'originale");
prova($fp_pp->voci(\App\Protocollo\FileProtocollato::RIF_DOMANDA, 98981)['protocollato']['origine'] === 'copia' && $fp_pp->voci(\App\Protocollo\FileProtocollato::RIF_DOMANDA, 98981)['titulus']['stato'] === 'attesa', "passo 7: origine «copia per consultazione» e file di Titulus in programma per il cron");
$fp_pp_riga = $fp_pp->riga(\App\Protocollo\FileProtocollato::RIF_DOMANDA, 98981);
prova($fp_pp_riga !== null && $fp_pp_riga['numero'] === '2026-UCALPRG-00071' && count($fin_pp->salvati) === 1, "passo 7: mostrare le voci non rimanda niente a Titulus (un solo invio in tutto)");
// Una pratica protocollata a mano (98983) non ha una riga né una chiamata a Titulus: solo «Riprova» la crea
prova($fp_pp->riga(\App\Protocollo\FileProtocollato::RIF_DOMANDA, 98983) === null, "passo 7: una pratica già protocollata a mano non ha riga: nessuna chiamata automatica a Titulus");
prova(\App\Didattica\DomandaPdfa::testoSegnatura(['protocollo' => '', 'protocollo_data' => '']) === '' && \App\Didattica\DomandaPdfa::testoSegnatura(['protocollo' => '7/2026', 'protocollo_data' => '2026-11-14']) === 'Prot. n. 7/2026 del 14/11/2026', "il testo della segnatura: vuoto senza protocollo, «Prot. n. … del …» con il protocollo");
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

// ── Protocollo automatico all'invio (ProtocolloAutomaticoPratica e il flusso che lo chiama) ──
$auto_pp = new \App\Didattica\ProtocolloAutomaticoPratica($prot_pp, $app_pp->get(\App\Didattica\PraticaRepository::class), $app_pp->get(\App\Didattica\StoricoPratica::class), $app_pp->get(\App\Didattica\ModuloRepository::class), $app_pp->get(\App\Didattica\ServizioIter::class));
$q("INSERT INTO pratiche (id, modulo_id, codice, nome, cognome, matricola, email, stato, domanda_pdf) VALUES
    (98984, 98982, 'PP-4', 'Eva', 'Verdi', '222222', 'eva.pp@example.org', 'inviata', '{$cart_pp}pp98981_domanda.pdf'),
    (98985, 98982, 'PP-5', 'Ugo', 'Neri', '333333', 'ugo.pp@example.org', 'inviata', '{$cart_pp}pp98981_domanda.pdf'),
    (98986, 98982, 'PP-6', 'Ada', 'Bruni', '444444', 'ada.pp@example.org', 'inviata', '{$cart_pp}pp98981_domanda.pdf')");
$n_auto = count($fin_pp->salvati);
prova($auto_pp->dopoInvio(98984) === null && $riga_pp(98984)['protocollo'] !== '' && $riga_pp(98984)['protocollo_data'] === date('Y-m-d') && count($fin_pp->salvati) === $n_auto + 1, "automatico: all'invio la pratica prende da sola il numero di Titulus (regola generale)");
prova($auto_pp->dopoInvio(98984) === null && count($fin_pp->salvati) === $n_auto + 1, "automatico: una pratica già protocollata non si rimanda");
$fin_pp->errore = 'Servizio non raggiungibile';
$err_auto = $auto_pp->dopoInvio(98985);
prova($err_auto !== null && str_contains($err_auto, 'Servizio non raggiungibile') && $riga_pp(98985)['protocollo'] === '', "automatico: Titulus in errore, la pratica resta da protocollare senza eccezioni");
prova((int)$conn->query("SELECT COUNT(*) FROM pratiche_eventi WHERE pratica_id = 98985 AND interno = 1 AND testo LIKE '%protocollo automatico%Servizio non raggiungibile%'")->fetch_row()[0] === 1, "automatico: l'errore è nello storico interno, per l'ufficio");
$fin_pp->errore = null;
$dentro_pp = new class implements \App\Didattica\FlussoPratica {
    public array $chiamate = [];
    public function avvia(int $pratica, array $modulo, array $utente): void { $this->chiamate[] = 'avvia'; }
    public function rigenera(int $pratica, array $modulo): void { $this->chiamate[] = 'rigenera'; }
};
$flusso_pp = new \App\Didattica\FlussoPraticaConProtocollo($dentro_pp, $auto_pp);
$flusso_pp->avvia(98985, [], []);
prova($dentro_pp->chiamate === ['avvia'] && $riga_pp(98985)['protocollo'] !== '', "il flusso delle pratiche fa prima il suo lavoro e poi protocolla da solo");
$flusso_pp->rigenera(98985, []);
prova($dentro_pp->chiamate === ['avvia', 'rigenera'] && count($fin_pp->salvati) === $n_auto + 2, "rigenerare la domanda non protocolla di nuovo");
// Tesi: il percorso comincia con la conferma del relatore, quindi il protocollo parte solo quando la pratica arriva all'ufficio protocollo
$q("INSERT INTO didattica_moduli (id, titolo, chiave, attivo, tipo, iter_json) VALUES (98987, 'Tesi di prova', 'prova_pp87', 1, 'online', '[{\"t\":\"conferma\",\"campo\":\"Relatore/trice\"},\"protocollo\"]')");
$q("INSERT INTO pratiche (id, modulo_id, codice, nome, cognome, matricola, email, stato, domanda_pdf) VALUES
    (98987, 98987, 'PP-7', 'Tea', 'Rossi', '555555', 'tea.pp@example.org', 'inviata', '{$cart_pp}pp98981_domanda.pdf'),
    (98988, 98982, 'PP-8', 'Leo', 'Marini', '666666', 'leo.pp@example.org', 'inviata', '{$cart_pp}pp98981_domanda.pdf')");
$n_tesi = count($fin_pp->salvati);
prova($auto_pp->dopoInvio(98987) === null && $riga_pp(98987)['protocollo'] === '' && count($fin_pp->salvati) === $n_tesi, "tesi: all'invio non si protocolla, si aspetta la conferma del relatore");
prova($auto_pp->alPassoProtocollo(98987) === null && $riga_pp(98987)['protocollo'] !== '' && count($fin_pp->salvati) === $n_tesi + 1, "tesi: arrivata all'ufficio protocollo (dopo la conferma) si protocolla da sola");
// ── Correzioni al passo 6 (T1): esito incerto, «in approvazione», numero a mano, protocollo automatico, data dell'invio riuscito ──
$q("INSERT INTO pratiche (id, modulo_id, codice, nome, cognome, matricola, email, stato, domanda_pdf) VALUES
    (98979, 98982, 'PP-11', 'Ivo', 'Verde', '999001', 'ivo.pp@example.org', 'inviata', '{$cart_pp}pp98981_domanda.pdf'),
    (98980, 98982, 'PP-12', 'Elsa', 'Grigi', '999002', 'elsa.pp@example.org', 'inviata', '{$cart_pp}pp98981_domanda.pdf'),
    (98989, 98982, 'PP-9', 'Mia', 'Rosa', '777777', 'mia.pp@example.org', 'inviata', '{$cart_pp}pp98981_domanda.pdf'),
    (98990, 98982, 'PP-10', 'Nico', 'Bianchi', '888888', 'nico.pp@example.org', 'inviata', '{$cart_pp}pp98981_domanda.pdf')");
$riga_inv = fn(string $rif, int $id, string $esito, string $num = '') => $repo_pp->registraInvio(['regola_id' => null, 'applica_a' => 'pratica:98982', 'rif_tipo' => $rif, 'rif_id' => $id, 'oggetto' => 'prova', 'tipo_documento' => 'arrivo', 'azione' => 'protocolla', 'esito' => $esito, 'nrecord' => '', 'num_prot' => $num, 'url' => '', 'errore' => null, 'utente_id' => null]);
// T1a/b/c — esito incerto
$repo_pp->segnaInvio(ProtocollaPraticaInTitulus::RIF, 98989, 'incerto', 'prova', 'timeout');
prova($prot_pp->stato(98989)['stato'] === 'incerto', "T1a: lo stato dell'invio della domanda si legge dalla classe (esito incerto)");
$n_t1 = count($fin_pp->salvati);
$err_t1 = $auto_pp->dopoInvio(98989);
prova($err_t1 !== null && str_contains($err_t1, "Non c'è in Titulus: riprova") && count($fin_pp->salvati) === $n_t1 && $riga_pp(98989)['protocollo'] === '', "T1c: con l'esito incerto il protocollo automatico si ferma, non chiama Titulus e dice all'ufficio che cosa fare");
prova((int)$conn->query("SELECT COUNT(*) FROM pratiche_eventi WHERE pratica_id = 98989 AND interno = 1 AND testo LIKE '%protocollo automatico%si è fermato%INCERTO%'")->fetch_row()[0] === 1, "T1c: l'avviso è nello storico interno della pratica");
[$e_m, $m_m] = $prot_pp->protocolloAMano(98989, '', date('Y-m-d'), 1, 'Anna Protocollo');
prova($e_m !== null && $riga_pp(98989)['protocollo'] === '' && $prot_pp->stato(98989)['stato'] === 'incerto', "T1b: un numero a mano vuoto non registra niente e NON chiude l'incertezza");
[$e_m, $m_m] = $prot_pp->protocolloAMano(98989, '55/2026', date('Y-m-d', strtotime('+3 days')), 1, 'Anna Protocollo');
prova($e_m !== null && $riga_pp(98989)['protocollo'] === '' && $prot_pp->stato(98989)['stato'] === 'incerto', "T1b: un numero a mano con la data nel futuro non registra niente e NON chiude l'incertezza");
[$e_m, $m_m] = $prot_pp->protocolloAMano(98989, '55/2026', date('Y-m-d'), 1, 'Anna Protocollo');
prova($e_m === null && $riga_pp(98989)['protocollo'] === '55/2026' && $prot_pp->stato(98989)['stato'] === 'libero', "T1b: un numero a mano valido registra e chiude l'incertezza (la riga del registro lo dice)", (string)$e_m);
prova(count($fin_pp->salvati) === $n_t1, "T1b: il numero a mano non chiama Titulus");
// «in approvazione»: si ferma, poi «Respinto» e si può rimandare
$riga_inv(ProtocollaPraticaInTitulus::RIF, 98990, 'ok', '');
prova($prot_pp->stato(98990)['stato'] === 'in_approvazione', "T1a: un invio riuscito ma senza numero risulta «in approvazione»");
$err_t1 = $auto_pp->dopoInvio(98990);
prova($err_t1 !== null && str_contains($err_t1, 'Respinto') && count($fin_pp->salvati) === $n_t1 && $riga_pp(98990)['protocollo'] === '', "T1c: «in approvazione»: il protocollo automatico si ferma e lo dice all'ufficio");
$e_t1 = $prot_pp->protocolla(98990, 1, 'Anna Protocollo');
prova($e_t1['errore'] !== null && count($fin_pp->salvati) === $n_t1, "T1c: nemmeno il pulsante rimanda un documento in approvazione");
require_once RADICE_SITO . '/inc/parziali/protocollo_stato.php';
$_SESSION['utente_id'] = 1; $_SESSION['utente_email'] = 'ufficio@example.org';
[$msg_t1, $tipo_t1] = protocollo_stato_azione($conn, ProtocollaPraticaInTitulus::RIF, 98990, true, 'Pratica');
prova($tipo_t1 === 'success' && $prot_pp->stato(98990)['stato'] === 'libero' && (int)$conn->query("SELECT COUNT(*) FROM log_attivita WHERE azione LIKE 'Pratica: respinto in Titulus%' AND utente_id = 1")->fetch_row()[0] >= 1, "T1a: «Respinto» chiude l'approvazione e scrive nel registro delle operazioni", $msg_t1);
[$msg_t1, $tipo_t1] = protocollo_stato_azione($conn, ProtocollaPraticaInTitulus::RIF, 98990, false, 'Pratica');
prova($tipo_t1 === 'danger', "T1a: «Non c'è in Titulus: riprova» senza un esito incerto non fa niente");
$e_t1 = $prot_pp->protocolla(98990, 1, 'Anna Protocollo');
prova($e_t1['errore'] === null && $riga_pp(98990)['protocollo'] !== '', "T1a: dopo «Respinto» si può rimandare e la pratica si protocolla");
// «Non c'è in Titulus: riprova»
$repo_pp->segnaInvio(ProtocollaPraticaInTitulus::RIF, 98980, 'incerto', 'prova', 'timeout');
[$msg_t1, $tipo_t1] = protocollo_stato_azione($conn, ProtocollaPraticaInTitulus::RIF, 98980, false, 'Pratica');
prova($tipo_t1 === 'success' && $prot_pp->stato(98980)['stato'] === 'libero' && (int)$conn->query("SELECT COUNT(*) FROM titulus_invii WHERE rif_tipo = 'pratica_domanda' AND rif_id = 98980 AND esito = 'verificato'")->fetch_row()[0] === 1, "T1a: «Non c'è in Titulus: riprova» chiude l'esito incerto e lo scrive nel registro degli invii", $msg_t1);
ob_start(); protocollo_stato_riquadro(['stato' => 'incerto', 'numero' => '', 'il' => date('Y-m-d H:i:s')], 'prot_incerto_chiudi', 'prot_respinto', '1', ['pratica_id' => '98980']); $html_t1 = ob_get_clean();
prova(str_contains($html_t1, "Non c'è in Titulus: riprova") && str_contains($html_t1, 'name="csrf_token"') && str_contains($html_t1, 'data-conferma=') && str_contains($html_t1, 'name="pratica_id"'), "T1a: il riquadro ha il pulsante, il CSRF, la conferma e l'id della pratica");
ob_start(); protocollo_stato_riquadro(['stato' => 'in_approvazione', 'numero' => '', 'il' => date('Y-m-d H:i:s')], 'prot_incerto_chiudi', 'prot_respinto', '1'); $html_t1 = ob_get_clean();
prova(str_contains($html_t1, '>Respinto<') && str_contains($html_t1, 'name="prot_respinto"'), "T1a: in approvazione il riquadro ha «Respinto»");
// T1b — un numero diverso da quello già dato da Titulus non si accetta
$riga_inv(ProtocollaPraticaInTitulus::RIF, 98979, 'ok', 'OLD-77');
$q("UPDATE titulus_invii SET creato_il = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE rif_tipo = 'pratica_domanda' AND rif_id = 98979");
[$e_m, $m_m] = $prot_pp->protocolloAMano(98979, 'ALTRO-1', date('Y-m-d'), 1, 'Anna Protocollo');
prova($e_m !== null && str_contains($e_m, 'OLD-77') && $riga_pp(98979)['protocollo'] === '', "T1b: se Titulus ha già dato un numero, un altro scritto a mano non si accetta");
// T1d — la data è quella dell'invio riuscito, non quella di oggi
$e_t1 = $prot_pp->protocolla(98979, 1, 'Anna Protocollo');
$r_t1 = $riga_pp(98979);
prova($e_t1['errore'] === null && $r_t1['protocollo'] === 'OLD-77' && $r_t1['protocollo_data'] === date('Y-m-d', strtotime('-3 days')), "T1d: un numero già dato 3 giorni fa si registra con la data dell'invio, non con quella di oggi", json_encode([$e_t1['errore'], $r_t1['protocollo'], $r_t1['protocollo_data']]));
$q("INSERT INTO uffici (nome, tipo) VALUES ('Ufficio protocollo di prova automatico', 'protocollo')");
$uff_pa = (int)$conn->insert_id;
$app_pp->get(\App\Didattica\UfficioRepository::class)->tutti(true);
// Nel container il protocollo automatico parla con Titulus finto (non con quello vero)
$app_pp->istanza(ProtocolloAutomaticoPratica::class, $auto_pp);
$flusso_vero = $app_pp->get(\App\Didattica\ServizioFlussoConvalide::class);
$n_pass = count($fin_pp->salvati);
$flusso_vero->assegnaAUfficio(98988, $uff_pa, 2, 0, 'Prova');
prova($riga_pp(98988)['protocollo'] !== '' && count($fin_pp->salvati) === $n_pass + 1, "il flusso vero: assegnare la pratica a un ufficio protocollo la fa protocollare da sola");
(new \App\Didattica\ProtocolloAlPasso(fn() => $auto_pp))->raggiunto(98988);
prova(count($fin_pp->salvati) === $n_pass + 1, "avvisare di nuovo non protocolla due volte");
$q("DELETE FROM uffici WHERE id = $uff_pa");
$app_pp->set(ProtocolloAutomaticoPratica::class, fn($c) => new ProtocolloAutomaticoPratica($c->get(ProtocollaPraticaInTitulus::class), $c->get(\App\Didattica\PraticaRepository::class), $c->get(\App\Didattica\StoricoPratica::class), $c->get(\App\Didattica\ModuloRepository::class), $c->get(\App\Didattica\ServizioIter::class)));
$conn->query("UPDATE titulus_regole SET attiva = 0");
$n_auto = count($fin_pp->salvati);
prova($auto_pp->dopoInvio(98986) === null && $riga_pp(98986)['protocollo'] === '' && count($fin_pp->salvati) === $n_auto, "automatico: senza regola attiva non fa nulla e non segnala errori");

foreach (['titulus_invii', 'protocollo_file', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
foreach (["DELETE FROM pratiche_eventi WHERE pratica_id BETWEEN 98979 AND 98990", "DELETE FROM pratiche WHERE id BETWEEN 98979 AND 98990", "DELETE FROM didattica_moduli WHERE id BETWEEN 98981 AND 98987"] as $sql) $q($sql);
@unlink($dir_pp . 'pp98981_domanda.pdf');
@unlink($dir_pp . 'pp98981_allegato.pdf');
