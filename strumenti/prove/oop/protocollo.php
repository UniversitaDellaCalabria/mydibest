<?php
// Prove del collegamento a Titulus (App\Protocollo): configurazione, regole, XML del documento, invio con un client finto, registro degli invii.
// Chiamato da esegui.php. Nessuna chiamata di rete: il servizio vero (SOAP) non si prova qui, si prova dal pannello «Prova il collegamento».
sezione("Protocollo di Ateneo (Titulus)");

use App\Infrastructure\Sicurezza\Cifratura;
use Unical\Titulus\AllegatoTitulus;
use Unical\Titulus\ClienteTitulus;
use App\Protocollo\ConfiguratoreProtocollo;
use Unical\Titulus\FabbricaClienteTitulus;
use Unical\Titulus\GeneratoreXml;
use App\Protocollo\ProtocolloRepository;
use App\Protocollo\RichiestaProtocollo;
use App\Protocollo\ServizioProtocollo;

// Client finto: registra cosa riceve e risponde come Titulus
$dop_pt = new class implements ClienteTitulus {
    public array $salvati = [];
    public array $fascicoli = [];
    public ?string $errore = null;
    public ?string $erroreFascicolo = null;
    public array $info = ['num_prot' => '', 'nrecord' => '777'];
    public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array
    {
        if ($this->errore !== null) throw new RuntimeException($this->errore);
        $this->salvati[] = ['xml' => $xmlDocumento, 'allegati' => $allegati, 'email' => $inviaEmail];
        $bozza = str_contains($xmlDocumento, 'bozza="si"');
        return ['nrecord' => '777', 'num_prot' => $bozza ? '' : '2026-UCALPRG-0001234', 'url' => 'https://titulus.example.org/doc/777'];
    }
    public function fascicola(string $xmlFascicolo, string $matricolaRpa): void
    {
        if ($this->erroreFascicolo !== null) throw new RuntimeException($this->erroreFascicolo);
        $this->fascicoli[] = [$xmlFascicolo, $matricolaRpa];
    }
    public function infoRecord(string $nrecord): array { return $this->info; }
    public function urlDocumento(string $id): ?string { return 'https://titulus.example.org/doc/' . $id; }
    public function prova(): string { if ($this->errore !== null) throw new RuntimeException($this->errore); return 'Collegamento riuscito (finto).'; }
};
$fab_pt = new class($dop_pt) implements FabbricaClienteTitulus {
    public function __construct(private ClienteTitulus $c) {}
    public function crea(\Unical\Titulus\ConfigTitulus $config, string $password): ClienteTitulus { return $this->c; }
};

foreach (['titulus_invii', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
$app_pt = \App\Core\App::per($conn);
$repo_pt = $app_pt->get(ProtocolloRepository::class);
$cifr_pt = new Cifratura('frase-di-prova-per-titulus-lunga');
$cfg_pt = new ConfiguratoreProtocollo($repo_pt, $cifr_pt);
$svc_pt = new ServizioProtocollo($repo_pt, $cifr_pt, $fab_pt, new GeneratoreXml());

prova((int)$conn->query("SHOW TABLES LIKE 'titulus\\_%'")->num_rows === 6, "schema: le sei tabelle di Titulus esistono");

// --- Collegamento --------------------------------------------------------------------------------------------------------------
$base_pt = ['nome' => 'Titulus DiBEST', 'url_ws' => 'https://titulus.example.org/ws/Titulus4?wsdl', 'username' => 'ws4tkt', 'aoo' => 'UCALPRG', 'agd' => '', 'autore' => 'Portale DiBEST', 'fascicolazione_separata' => 1];
prova($cfg_pt->salvaCollegamento($base_pt + ['attivo' => 1]) !== null, "collegamento: non si accende senza la password");
prova($cfg_pt->salvaCollegamento(['url_ws' => 'ftp://x'] + $base_pt) !== null && $cfg_pt->salvaCollegamento(['url_ws' => 'non un indirizzo'] + $base_pt) !== null, "collegamento: indirizzo non web rifiutato");
prova($svc_pt->nonPronto() !== null, "servizio: collegamento spento = non pronto");
prova($cfg_pt->salvaCollegamento($base_pt + ['password' => 'segretissima', 'attivo' => 1]) === null, "collegamento: salvato e acceso con la password");
$cr_pt = $repo_pt->passwordCifrata();
prova(str_starts_with($cr_pt, 'v1:') && !str_contains($cr_pt, 'segretissima') && $cifr_pt->decifra($cr_pt) === 'segretissima', "la password è cifrata nel database e si rilegge");
prova($cfg_pt->salvaCollegamento($base_pt + ['attivo' => 1]) === null && $cifr_pt->decifra($repo_pt->passwordCifrata()) === 'segretissima', "password vuota nel modulo: resta quella di prima");
prova($svc_pt->nonPronto() === null, "servizio: collegamento acceso e completo = pronto");
$dop_pt->errore = 'Servizio non raggiungibile';
$pv_pt = $svc_pt->provaConnessione();
prova(!$pv_pt['ok'] && str_contains((string)$conn->query("SELECT ultima_prova_esito FROM titulus_config WHERE id = 1")->fetch_row()[0], 'ERRORE'), "prova del collegamento: l'errore si mostra e si ricorda");
$dop_pt->errore = null;
prova($svc_pt->provaConnessione()['ok'], "prova del collegamento riuscita");

// --- Voci d'indice, repertori ---------------------------------------------------------------------------------------------------
prova($cfg_pt->salvaVoce(0, 'Iter Approva e Firma', true) === null && $cfg_pt->salvaVoce(0, 'Iter Approva e Firma', true) !== null && $cfg_pt->salvaVoce(0, '  ', true) !== null, "voci d'indice: una sola per nome, non vuota");
prova($cfg_pt->salvaRepertorio(0, 'DR', 'Decreti rettorali', true) === null && $cfg_pt->salvaRepertorio(0, 'DR', 'x', true) !== null, "repertori: codice unico");
$voce_pt = (int)$repo_pt->vociIndice()[0]['id'];
$rep_pt = (int)$repo_pt->repertori()[0]['id'];

// --- Regole ----------------------------------------------------------------------------------------------------------------------
$regola_base = ['nome' => 'Convenzioni FSL', 'applica_a' => 'convenzione_fsl', 'tipo_documento' => 'arrivo', 'azione' => 'protocolla', 'uo' => '2014.2', 'cod_titolario' => '5/1', 'rpa_nome' => 'Mario Rossi', 'rpa_username' => 'mrossi', 'rpa_matricola' => '123', 'attiva' => 1, 'invia_email_rpa' => 1];
prova($cfg_pt->salvaRegola(0, ['uo' => 'XX'] + $regola_base)[1] !== null, "regola: UO fuori dall'elenco di Titulus rifiutata");
prova($cfg_pt->salvaRegola(0, ['cod_titolario' => '99/9'] + $regola_base)[1] !== null, "regola: codice di titolario inesistente rifiutato");
prova($cfg_pt->salvaRegola(0, ['applica_a' => 'altro'] + $regola_base)[1] !== null && $cfg_pt->salvaRegola(0, ['applica_a' => 'pratica:99999'] + $regola_base)[1] !== null, "regola: richiesta sconosciuta rifiutata");
prova($cfg_pt->salvaRegola(0, ['azione' => 'iter'] + $regola_base)[1] !== null, "regola: l'iter vuole la voce d'indice");
prova($cfg_pt->salvaRegola(0, ['fascicolo_numero' => '2026-I/1.1', 'rpa_matricola' => ''] + $regola_base)[1] !== null, "regola: con il fascicolo serve la matricola dell'RPA");
[$rid_pt, $err_pt] = $cfg_pt->salvaRegola(0, $regola_base + ['fascicolo_numero' => '2026-I/1.1', 'fascicolo_anno' => 2026, 'repertorio_id' => $rep_pt, 'op_uo' => '2014.1', 'op_nome' => 'Anna Bianchi', 'op_matricola' => '456',
    'cc_uo' => ['2014.2', '', '2014.3'], 'cc_nome' => ['Luisa Neri', 'x', ''], 'cc_username' => ['', '', ''], 'cc_matricola' => ['789', '', '']]);
prova($err_pt === null && $rid_pt > 0 && count($repo_pt->copieConoscenza($rid_pt)) === 2, "regola completa salvata, con due righe di copia conoscenza (la vuota scartata)", (string)$err_pt);
[$rid_iter, $e2] = $cfg_pt->salvaRegola(0, ['nome' => 'Iter generale', 'applica_a' => 'generale', 'tipo_documento' => 'partenza', 'azione' => 'iter', 'voce_indice_id' => $voce_pt, 'attiva' => 1] + $regola_base);
prova($e2 === null && $rid_iter > 0, "regola «generale» con iter", (string)$e2);
prova((int)$repo_pt->regolaPer('convenzione_fsl')['id'] === $rid_pt && (int)$repo_pt->regolaPer('tutorato')['id'] === $rid_iter, "la regola dedicata vince, altrimenti quella generale");
prova(!$repo_pt->eliminaVoce($voce_pt) && !$repo_pt->eliminaRepertorio($rep_pt), "voce e repertorio in uso non si eliminano");

// --- Invio: protocollo diretto -----------------------------------------------------------------------------------------------
$pdf_pt = "%PDF-1.4\n%finto\n";
$ric_pt = fn(string $applica, string $oggetto = 'Convenzione Liceo Galilei', array $ext = ['nome' => 'Anna', 'cognome' => "D'Angelo", 'codice_fiscale' => 'DNGNNA80A41D086X', 'email' => 'anna@example.org'], ?array $mitt = null)
    => new RichiestaProtocollo($applica, $oggetto, AllegatoTitulus::crea('convenzione', $pdf_pt), [AllegatoTitulus::crea('allegato_a.pdf', $pdf_pt)], $ext, $mitt, 'convenzione_fsl', 42, 1);
$e = $svc_pt->protocolla($ric_pt('convenzione_fsl', 'Tesi & Co <prova> "citata"'));
prova($e->ok && $e->numProt === '2026-UCALPRG-0001234' && $e->nrecord === '777' && $e->invioId > 0, "arrivo: protocollato, con numero e record", $e->messaggio);
$xml_pt = $dop_pt->salvati[0]['xml'] ?? '';
$d_pt = @simplexml_load_string($xml_pt);
prova($d_pt !== false && (string)$d_pt['tipo'] === 'arrivo' && (string)$d_pt['bozza'] === 'no' && (string)$d_pt['cod_amm_aoo'] === 'UCALPRG', "XML: radice con tipo, bozza e AOO", $xml_pt);
prova($d_pt !== false && trim((string)$d_pt->oggetto) === 'TESI & CO <PROVA> "CITATA"', "XML: oggetto in maiuscolo e caratteri speciali protetti", (string)($d_pt->oggetto ?? ''));
prova($d_pt !== false && (string)$d_pt->repertorio['cod'] === 'DR' && (string)$d_pt->classif['cod'] === '5/1' && (string)$d_pt->allegato === '2', "XML: repertorio, titolario e numero degli allegati (2)");
$rif_pt = $d_pt !== false ? $d_pt->rif_interni->rif_interno : [];
$dir_pt = []; foreach ($rif_pt as $ri) $dir_pt[] = (string)$ri['diritto'];
prova($dir_pt === ['RPA', 'OP', 'CC', 'CC'] && (string)$rif_pt[0]['cod_uff'] === '2014.2' && (string)$rif_pt[0]['nome_uff'] === 'DIDATTICA DIBEST' && (string)$rif_pt[1]['cod_persona'] === '456' && (string)$rif_pt[2]['cod_persona'] === '789', "XML: RPA, OP e le due copie conoscenza con UO e matricole", implode(',', $dir_pt));
prova($d_pt !== false && (string)$d_pt->rif_esterni->rif_esterno['codice_fiscale'] === 'DNGNNA80A41D086X' && str_contains((string)$d_pt->rif_esterni->rif_esterno->nome, "Anna D'Angelo"), "XML: riferimento esterno con codice fiscale e nome");
prova(count($dop_pt->salvati[0]['allegati']) === 2 && $dop_pt->salvati[0]['allegati'][0]->nomeFile === 'convenzione.pdf' && $dop_pt->salvati[0]['allegati'][1]->nomeFile === 'allegato_a.pdf', "il documento principale è il primo allegato, con l'estensione completata");
prova(count($dop_pt->fascicoli) === 1 && $dop_pt->fascicoli[0][1] === '123' && str_contains($dop_pt->fascicoli[0][0], 'numero="2026-I/1.1"') && str_contains($dop_pt->fascicoli[0][0], 'num_prot="2026-UCALPRG-0001234"'), "fascicolazione con la matricola dell'RPA e il numero di protocollo");
$inv_pt = $repo_pt->invio($e->invioId);
prova($inv_pt['esito'] === 'ok' && $inv_pt['rif_tipo'] === 'convenzione_fsl' && (int)$inv_pt['rif_id'] === 42 && $inv_pt['num_prot'] === '2026-UCALPRG-0001234', "registro: invio riuscito con il riferimento alla richiesta");

// Fascicolazione che fallisce: il protocollo resta, l'avviso si vede
$dop_pt->erroreFascicolo = 'fascicolo chiuso';
$e = $svc_pt->protocolla($ric_pt('convenzione_fsl'));
prova($e->ok && $e->numProt !== '' && str_contains($e->messaggio, 'NON è stato inserito nel fascicolo'), "fascicolo rifiutato: il protocollo resta e l'avviso è chiaro", $e->messaggio);
$dop_pt->erroreFascicolo = null;

// --- Invio: iter di approvazione (regola generale, in partenza) ----------------------------------------------------------------
$n_prima = count($dop_pt->salvati);
$e = $svc_pt->protocolla($ric_pt('tutorato'));
$d_it = @simplexml_load_string($dop_pt->salvati[$n_prima]['xml'] ?? '');
prova($e->ok && $e->numProt === '' && $e->nrecord === '777' && str_contains($e->messaggio, 'iter'), "iter: bozza salvata, senza numero di protocollo", $e->messaggio);
prova($d_it !== false && (string)$d_it['bozza'] === 'si' && (string)$d_it->voce_indice === 'Iter Approva e Firma' && (string)$d_it['tipo'] === 'partenza', "XML dell'iter: bozza, voce d'indice e verso in partenza");
$dop_pt->info = ['num_prot' => '', 'nrecord' => '777'];
prova(!$svc_pt->aggiornaNumero($e->invioId)['ok'], "iter ancora aperto: il numero non c'è");
$dop_pt->info = ['num_prot' => '2026-UCALPRG-0009999', 'nrecord' => '777'];
$agg_pt = $svc_pt->aggiornaNumero($e->invioId);
prova($agg_pt['ok'] && $repo_pt->invio($e->invioId)['num_prot'] === '2026-UCALPRG-0009999' && str_contains((string)$repo_pt->invio($e->invioId)['url'], '2026-UCALPRG-0009999'), "iter concluso: numero e indirizzo letti da Titulus e salvati", $agg_pt['messaggio']);

// --- Errori: mai eccezioni, sempre nel registro ------------------------------------------------------------------------------------
$n_inv = (int)$conn->query("SELECT COUNT(*) FROM titulus_invii")->fetch_row()[0];
$dop_pt->errore = 'Titulus ha risposto con un errore (saveDocument): campo obbligatorio';
$e = $svc_pt->protocolla($ric_pt('convenzione_fsl'));
prova(!$e->ok && str_contains($e->messaggio, 'campo obbligatorio') && (int)$conn->query("SELECT COUNT(*) FROM titulus_invii WHERE esito = 'errore'")->fetch_row()[0] === 1, "errore di Titulus: nessuna eccezione, motivo nel registro");
$dop_pt->errore = null;
$e = $svc_pt->protocolla($ric_pt('convenzione_fsl', '   '));
prova(!$e->ok && str_contains($e->messaggio, 'oggetto'), "senza oggetto: rifiutato prima dell'invio");
$e = $svc_pt->protocolla($ric_pt('tutorato', 'Lettera', ['nome' => 'Anna', 'cognome' => 'X', 'codice_fiscale' => '']));
prova(!$e->ok && str_contains($e->messaggio, 'codice fiscale'), "in partenza senza codice fiscale del destinatario: rifiutato");
$repo_pt->salvaRegola($rid_pt, ['nome' => 'Convenzioni FSL', 'applica_a' => 'convenzione_fsl', 'attiva' => 0, 'tipo_documento' => 'interno', 'azione' => 'protocolla', 'voce_indice_id' => null, 'repertorio_id' => null, 'uo' => '2014.2', 'rpa_nome' => 'R', 'rpa_username' => '', 'rpa_matricola' => '', 'invia_email_rpa' => 1, 'email' => '', 'cod_titolario' => '5/1', 'fascicolo_numero' => '', 'fascicolo_anno' => null, 'op_uo' => '', 'op_nome' => '', 'op_username' => '', 'op_matricola' => '']);
$n_salvati = count($dop_pt->salvati);
$e = $svc_pt->protocolla(new RichiestaProtocollo('convenzione_fsl', 'Lettera', AllegatoTitulus::crea('l.pdf', $pdf_pt), regolaId: $rid_pt));
prova(!$e->ok && str_contains($e->messaggio, 'disattivata') && count($dop_pt->salvati) === $n_salvati, "regola disattivata: niente invio");
$repo_pt->salvaRegola($rid_pt, ['nome' => 'x', 'applica_a' => 'convenzione_fsl', 'attiva' => 1, 'tipo_documento' => 'interno', 'azione' => 'protocolla', 'voce_indice_id' => null, 'repertorio_id' => null, 'uo' => '2014.2', 'rpa_nome' => 'R', 'rpa_username' => '', 'rpa_matricola' => '', 'invia_email_rpa' => 0, 'email' => '', 'cod_titolario' => '5/1', 'fascicolo_numero' => '', 'fascicolo_anno' => null, 'op_uo' => '', 'op_nome' => '', 'op_username' => '', 'op_matricola' => '']);
$e = $svc_pt->protocolla(new RichiestaProtocollo('convenzione_fsl', 'Nota interna', AllegatoTitulus::crea('n.pdf', $pdf_pt), mittenteInterno: ['nome' => 'Mario Rossi', 'uff_nome' => 'DIDATTICA DIBEST', 'uff_cod' => '2014.2']));
$d_in = @simplexml_load_string(end($dop_pt->salvati)['xml']);
prova($e->ok && $d_in !== false && (string)$d_in->minuta->mittente['cod_uff'] === '2014.2' && !isset($d_in->rif_esterni) && (int)end($dop_pt->salvati)['email'] === 0, "tra uffici: minuta con mittente, nessun riferimento esterno, nessuna e-mail all'RPA", $e->messaggio);
$e = $svc_pt->protocolla(new RichiestaProtocollo('convenzione_fsl', 'Nota', AllegatoTitulus::crea('n.pdf', $pdf_pt)));
$d_dm = $e->ok ? @simplexml_load_string(end($dop_pt->salvati)['xml']) : false;
prova($e->ok && $d_dm !== false && (string)$d_dm->minuta->mittente['cod_uff'] === '2014.2', "tra uffici senza mittente indicato: si usa l'ufficio della regola", $e->messaggio);
$cfg_pt->salvaCollegamento($base_pt + ['attivo' => 0]);
$e = $svc_pt->protocolla($ric_pt('convenzione_fsl'));
prova(!$e->ok && str_contains($e->messaggio, 'non è attivo'), "collegamento spento: niente invio");
prova((int)$conn->query("SELECT COUNT(*) FROM titulus_invii")->fetch_row()[0] > $n_inv, "ogni tentativo, riuscito o no, è nel registro");

// --- File -------------------------------------------------------------------------------------------------------------------------
prova(AllegatoTitulus::crea('scansione', "\x89PNG\r\n\x1a\n....")->nomeFile === 'scansione.png' && AllegatoTitulus::crea('doc', $pdf_pt)->nomeFile === 'doc.pdf' && AllegatoTitulus::crea('a/b\\c.pdf', $pdf_pt)->nomeFile === 'a_b_c.pdf', "file: estensione dal contenuto, nome ripulito dai separatori di percorso");
$err_f = ''; try { AllegatoTitulus::crea('falso.pdf', 'questo non è un pdf'); } catch (InvalidArgumentException $x) { $err_f = $x->getMessage(); }
prova(str_contains($err_f, '«falso.pdf» ha estensione .pdf ma non è un PDF valido'), "file: un falso PDF si rifiuta come farebbe Titulus, con il nome del file", $err_f);
$err_f = ''; try { AllegatoTitulus::crea('vuoto.pdf', ''); } catch (InvalidArgumentException $x) { $err_f = $x->getMessage(); }
prova($err_f === 'Il file «vuoto.pdf» è vuoto.', "file: un file vuoto si rifiuta, con il nome del file", $err_f);
$zip_pt = tempnam(sys_get_temp_dir(), 'z'); $za = new ZipArchive(); $za->open($zip_pt, ZipArchive::OVERWRITE); $za->addFromString('word/document.xml', '<x/>'); $za->close();
prova(AllegatoTitulus::crea('relazione', (string)file_get_contents($zip_pt))->nomeFile === 'relazione.docx', "file: un .docx senza estensione si riconosce dal contenuto"); @unlink($zip_pt);

foreach (['titulus_invii', 'titulus_cc', 'titulus_regole', 'titulus_voci_indice', 'titulus_repertori', 'titulus_config'] as $__t) $conn->query("DELETE FROM $__t");
