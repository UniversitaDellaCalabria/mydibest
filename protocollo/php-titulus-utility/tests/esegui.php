<?php

declare(strict_types=1);

// Prove del pacchetto, senza Composer e senza rete: `php tests/esegui.php`. Il servizio vero (SOAP) si prova con provaConnessione().
require __DIR__ . '/../autoload.php';

use Unical\Titulus\AllegatoTitulus;
use Unical\Titulus\ClienteTitulus;
use Unical\Titulus\ConfigTitulus;
use Unical\Titulus\DocumentoDaProtocollare;
use Unical\Titulus\FabbricaClienteTitulus;
use Unical\Titulus\GeneratoreXml;
use Unical\Titulus\LetturaFileTitulus;
use Unical\Titulus\ParametriProtocollo;
use Unical\Titulus\RegistroInMemoria;
use Unical\Titulus\RegistroSuFile;
use Unical\Titulus\ServizioTitulus;

$ok = 0;
$ko = 0;
function prova(bool $condizione, string $nome): void
{
    global $ok, $ko;
    if ($condizione) {
        $ok++;
        echo "  ok  $nome\n";
    } else {
        $ko++;
        echo "  KO  $nome\n";
    }
}
function sezione(string $t): void
{
    echo "\n== $t ==\n";
}

// Cliente finto: registra ciò che riceve e risponde come Titulus
$finto = new class implements ClienteTitulus {
    public array $salvati = [];
    public array $fascicoli = [];
    public ?string $errore = null;
    public ?string $erroreFascicolo = null;
    public array $info = ['num_prot' => '', 'nrecord' => '777'];
    public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array
    {
        if ($this->errore !== null) {
            throw new RuntimeException($this->errore);
        }
        $this->salvati[] = ['xml' => $xmlDocumento, 'allegati' => $allegati, 'email' => $inviaEmail];
        $bozza = str_contains($xmlDocumento, 'bozza="si"');
        return ['nrecord' => '777', 'num_prot' => $bozza ? '' : '2026-UCALPRG-0001234', 'url' => 'https://titulus.example.org/doc/777'];
    }
    public function fascicola(string $xmlFascicolo, string $matricolaRpa): void
    {
        if ($this->erroreFascicolo !== null) {
            throw new RuntimeException($this->erroreFascicolo);
        }
        $this->fascicoli[] = [$xmlFascicolo, $matricolaRpa];
    }
    public function infoRecord(string $nrecord): array
    {
        return $this->info;
    }
    public function urlDocumento(string $id): ?string
    {
        return 'https://titulus.example.org/doc/' . $id;
    }
    /** @var list<array{titolo: string, fileId: string, principale: bool, sha256: ?string}> */
    public array $file = [];
    /** @var array<string, string> */
    public array $contenuti = [];
    public ?string $erroreLettura = null;
    public function fileDelDocumento(string $id): array
    {
        if ($this->erroreLettura !== null) {
            throw new RuntimeException($this->erroreLettura);
        }
        return $this->file;
    }
    public function scaricaFile(string $fileId): string
    {
        if ($this->erroreLettura !== null) {
            throw new RuntimeException($this->erroreLettura);
        }
        return $this->contenuti[$fileId] ?? '';
    }
    public function prova(): string
    {
        if ($this->errore !== null) {
            throw new RuntimeException($this->errore);
        }
        return 'Collegamento riuscito (finto).';
    }
};
$fabbrica = new class($finto) implements FabbricaClienteTitulus {
    public function __construct(private ClienteTitulus $c)
    {
    }
    public function crea(ConfigTitulus $config, string $password): ClienteTitulus
    {
        return $this->c;
    }
};

// --- Configurazione ------------------------------------------------------------------------------------------------------------
sezione('Configurazione');
$cfg = ConfigTitulus::daArray(['url_ws' => 'https://titulus.example.org/ws/Titulus4?wsdl', 'username' => 'ws4tkt', 'aoo' => 'UCALPRG', 'autore' => 'App di prova']);
prova($cfg->incompleta() === null && $cfg->attivo && $cfg->fascicolazioneSeparata, 'daArray: completa, attiva, fascicolazione separata di default');
prova(ConfigTitulus::daArray(['username' => 'x', 'aoo' => 'y'])->incompleta() === 'Manca l\'indirizzo del servizio.', 'daArray: manca l\'indirizzo');
prova((new ConfigTitulus('http://x', ' ', 'AOO'))->incompleta() === 'Manca l\'username.', 'costruttore: manca l\'username');
putenv('TITULUS_URL_WS=https://t.example/ws');
putenv('TITULUS_USERNAME=u');
putenv('TITULUS_AOO=A');
$amb = ConfigTitulus::daAmbiente();
prova($amb->urlWs === 'https://t.example/ws' && $amb->username === 'u' && $amb->aoo === 'A' && $amb->incompleta() === null, 'daAmbiente: legge le variabili TITULUS_*');

// --- Allegati ------------------------------------------------------------------------------------------------------------------
sezione('Allegati');
prova(AllegatoTitulus::crea('lettera', '%PDF-1.4 ...')->nomeFile === 'lettera.pdf', 'un PDF senza estensione prende .pdf');
prova(AllegatoTitulus::crea('a/b\\c.pdf', '%PDF-1.4')->nomeFile === 'a_b_c.pdf', 'il nome non può contenere percorsi');
prova(AllegatoTitulus::crea('foto', "\x89PNG\r\n")->nomeFile === 'foto.png', 'un PNG senza estensione prende .png');
prova(AllegatoTitulus::crea('doc.p7m', 'xx')->nomeFile === 'doc.p7m', 'estensioni protette lasciate com\'erano');
foreach ([['vuoto.pdf', ''], ['finto.pdf', 'non sono un pdf'], ['senza', 'testo qualsiasi']] as [$n, $c]) {
    $err = false;
    try {
        AllegatoTitulus::crea($n, $c);
    } catch (InvalidArgumentException) {
        $err = true;
    }
    prova($err, "rifiutato: $n");
}

// --- XML -----------------------------------------------------------------------------------------------------------------------
sezione('XML');
$g = new GeneratoreXml();
$base = ['tipo' => 'arrivo', 'bozza' => false, 'aoo' => 'UCALPRG', 'autore' => 'Prova', 'oggetto' => 'R&D <test> "uno"', 'num_allegati' => 1, 'cod_titolario' => '5/1',
    'rpa_nome' => 'Mario Rossi', 'uo' => '2014.2', 'uo_nome' => 'DiBEST',
    'esterno' => ['nome' => 'Anna D\'Oro', 'codice_fiscale' => 'RSSMRA80A01H501U', 'email' => 'a@b.it', 'telefono' => '', 'fax' => '', 'indirizzo' => 'Via & Roma']];
$x = $g->documento($base);
$dom = new DOMDocument();
prova($dom->loadXML($x) !== false, 'XML del documento ben formato anche con &, <, > e apici');
prova($dom->documentElement->getAttribute('tipo') === 'arrivo' && $dom->documentElement->getAttribute('cod_amm_aoo') === 'UCALPRG', 'attributi tipo e AOO');
prova($dom->getElementsByTagName('oggetto')->item(0)->textContent === 'R&D <test> "uno"', 'l\'oggetto resta identico dopo la protezione');
prova($dom->getElementsByTagName('rif_esterno')->length === 1 && $dom->getElementsByTagName('minuta')->length === 0, 'arrivo: un rif_esterno e nessuna minuta');
$rpaSenza = null;
foreach ($dom->getElementsByTagName('rif_interno') as $r) {
    if ($r->getAttribute('diritto') === 'RPA') {
        $rpaSenza = $r;
    }
}
prova($rpaSenza !== null && !$rpaSenza->hasAttribute('cod_persona') && $rpaSenza->getAttribute('nome_persona') === 'Mario Rossi', 'RPA senza matricola: nome, ufficio e nessun cod_persona');
$domM = new DOMDocument();
$domM->loadXML($g->documento(['rpa_matricola' => '99999'] + $base));
$rpaCon = null;
foreach ($domM->getElementsByTagName('rif_interno') as $r) {
    if ($r->getAttribute('diritto') === 'RPA') {
        $rpaCon = $r;
    }
}
prova($rpaCon !== null && $rpaCon->getAttribute('cod_persona') === '99999' && $rpaCon->getAttribute('nome_persona') === 'Mario Rossi', 'RPA con matricola: porta anche cod_persona');
$xi = $g->documento(['tipo' => 'interno', 'mittente_interno' => ['nome' => 'M', 'uff_nome' => 'U', 'uff_cod' => '1']] + $base);
$domi = new DOMDocument();
$domi->loadXML($xi);
prova($domi->getElementsByTagName('minuta')->length === 1 && $domi->getElementsByTagName('rif_esterni')->length === 0, 'interno: minuta e nessun rif_esterni');
$bz = new DOMDocument();
$bz->loadXML($g->documento(['bozza' => true, 'voce_indice' => 'Iter Approva e Firma'] + $base));
prova($bz->documentElement->getAttribute('bozza') === 'si' && $bz->getElementsByTagName('voce_indice')->length === 1, 'bozza: attributo e voce d\'indice');
$f = new DOMDocument();
prova($f->loadXML($g->fascicolo('2026-I/1.1', '777', 'P1')) !== false && $f->getElementsByTagName('doc')->item(0)->getAttribute('nrecord') === '777', 'XML del fascicolo');
$errTipo = false;
try {
    $g->documento(['tipo' => 'boh'] + $base);
} catch (InvalidArgumentException) {
    $errTipo = true;
}
prova($errTipo, 'tipo di documento non valido rifiutato');

// --- Servizio ------------------------------------------------------------------------------------------------------------------
sezione('Servizio');
$registro = new RegistroInMemoria();
$svc = new ServizioTitulus($cfg, 'segreta', $fabbrica, $registro);
$pdf = AllegatoTitulus::crea('convenzione.pdf', '%PDF-1.4 contenuto');
$param = new ParametriProtocollo('arrivo', '2014.2', '5/1', 'Mario Rossi', rpaMatricola: '123', inviaEmailRpa: true);
$doc = new DocumentoDaProtocollare('convenzione fsl', $pdf, esterno: ['nome' => 'Liceo', 'cognome' => 'Rossi', 'email' => 'liceo@example.it'], rifTipo: 'convenzione', rifId: 5);

$e = $svc->protocollaArrivo($doc, $param);
prova($e->ok && $e->numProt === '2026-UCALPRG-0001234' && $e->nrecord === '777' && $e->invioId === 1, 'arrivo: protocollato, numero e record restituiti');
$inviato = $finto->salvati[0];
prova(str_contains($inviato['xml'], '<oggetto>CONVENZIONE FSL     </oggetto>') && $inviato['email'] === true && count($inviato['allegati']) === 1, 'oggetto in maiuscolo e riempito a 20, email all\'RPA, un allegato');
prova(str_contains($inviato['xml'], 'nome_uff="'), 'il nome dell\'ufficio è nell\'XML');
prova($registro->righe[1]['esito'] === 'ok' && $registro->righe[1]['num_prot'] === '2026-UCALPRG-0001234', 'registro: invio riuscito annotato');
prova(!str_contains(json_encode($registro->righe), 'segreta'), 'la password non finisce nel registro');

$e = $svc->protocollaPartenza($doc, $param);
prova(!$e->ok && str_contains($e->messaggio, 'codice fiscale'), 'partenza senza codice fiscale: rifiutata prima dell\'invio');
prova(count($finto->salvati) === 1, 'e non è stato inviato nulla');

$docP = new DocumentoDaProtocollare('lettera', $pdf, esterno: ['nome' => 'Anna', 'cognome' => 'Bianchi', 'codice_fiscale' => 'BNCNNA80A41H501X']);
prova($svc->protocollaPartenza($docP, $param)->ok, 'partenza con codice fiscale: ok');

$e = $svc->avviaIter($doc, $param);
prova(!$e->ok && str_contains($e->messaggio, 'voce d\'indice'), 'iter senza voce d\'indice: rifiutato');
$paramIter = new ParametriProtocollo('arrivo', '2014.2', '5/1', 'Mario Rossi', voceIndice: 'Iter Approva e Firma');
$e = $svc->avviaIter($doc, $paramIter);
prova($e->ok && $e->nrecord === '777' && $e->numProt === '' && str_contains($e->messaggio, 'iter'), 'iter: bozza salvata, solo il record');

$e = $svc->protocollaInterno(new DocumentoDaProtocollare('nota tra uffici', $pdf), new ParametriProtocollo('interno', '2014.2', '5/1', 'Mario Rossi'));
prova($e->ok && str_contains(end($finto->salvati)['xml'], '<minuta>'), 'interno senza mittente: si usa l\'ufficio dei parametri');
prova(!$svc->protocolla(new DocumentoDaProtocollare('  ', $pdf), $param)->ok, 'oggetto vuoto rifiutato');
prova(!$svc->protocolla($doc, new ParametriProtocollo('arrivo', '', '5/1', 'M'))->ok, 'senza UO rifiutato');
prova(!$svc->protocolla($doc, new ParametriProtocollo('arrivo', '2014.2', '5/1', 'M', fascicoloNumero: '2026-I/1.1'))->ok, 'fascicolo senza matricola dell\'RPA rifiutato');
prova(!$svc->protocolla($doc, new ParametriProtocollo('boh', '2014.2', '5/1', 'M'))->ok, 'verso sconosciuto rifiutato');

// Fascicolazione
$finto->fascicoli = [];
$conFasc = new ParametriProtocollo('arrivo', '2014.2', '5/1', 'Mario Rossi', rpaMatricola: '123', fascicoloNumero: '2026-I/1.1');
$e = $svc->protocolla($doc, $conFasc);
prova($e->ok && count($finto->fascicoli) === 1 && $finto->fascicoli[0][1] === '123', 'fascicolazione fatta con la matricola dell\'RPA');
$finto->erroreFascicolo = 'fascicolo chiuso';
$e = $svc->protocolla($doc, $conFasc);
prova($e->ok && $e->numProt !== '' && str_contains($e->messaggio, 'NON è stato inserito nel fascicolo'), 'fascicolo rifiutato: il protocollo resta, l\'avviso c\'è');
$finto->erroreFascicolo = null;

// Errori di Titulus
$prima = count($registro->righe);
$finto->errore = 'Servizio non raggiungibile';
$e = $svc->protocolla($doc, $param);
prova(!$e->ok && $e->messaggio === 'Servizio non raggiungibile' && $e->invioId === $prima + 1, 'errore di Titulus: nessuna eccezione, esito negativo');
prova(end($registro->righe)['esito'] === 'errore' && end($registro->righe)['errore'] === 'Servizio non raggiungibile', 'registro: errore annotato');
$pv = $svc->provaConnessione();
prova(!$pv['ok'], 'prova del collegamento: errore mostrato');
$finto->errore = null;
prova($svc->provaConnessione()['ok'], 'prova del collegamento riuscita');

// Collegamento non pronto
$spento = new ServizioTitulus(new ConfigTitulus('http://x', 'u', 'A', attivo: false), 'p', $fabbrica);
prova(!$spento->protocolla($doc, $param)->ok && $spento->nonPronto() !== null, 'collegamento spento: non invia');
prova((new ServizioTitulus($cfg, '', $fabbrica))->nonPronto() === 'Manca la password di Titulus.', 'senza password: non pronto');
prova((new ServizioTitulus(new ConfigTitulus('', 'u', 'A'), 'p', $fabbrica))->nonPronto() === 'Manca l\'indirizzo del servizio.', 'senza indirizzo: non pronto');

// Mai due volte lo stesso documento
$conta = count($finto->salvati);
$n = $svc->numero($doc, $param);
prova($n['errore'] === null && $n['numero'] === '2026-UCALPRG-0001234' && count($finto->salvati) === $conta, 'numero(): il documento già protocollato non si protocolla di nuovo');
$n = $svc->numero(new DocumentoDaProtocollare('nuovo', $pdf, esterno: ['nome' => 'X'], rifTipo: 'convenzione', rifId: 99), $param);
prova($n['numero'] !== '' && count($finto->salvati) === $conta + 1, 'numero(): un documento nuovo si protocolla');
$n = $svc->numero(new DocumentoDaProtocollare('iter', $pdf, esterno: ['nome' => 'X']), $paramIter->con('arrivo', 'iter'));
prova($n['numero'] === '' && $n['errore'] !== null, 'numero() con l\'iter: spiega che il numero non c\'è ancora');

// Lettura del numero di un'iter
$l = $svc->leggiNumero('777');
prova(!$l['ok'] && str_contains($l['messaggio'], 'non è ancora concluso'), 'leggiNumero: iter non concluso');
$finto->info = ['num_prot' => '2026-UCALPRG-0009999', 'nrecord' => '777'];
$l = $svc->leggiNumero('777');
prova($l['ok'] && $l['num_prot'] === '2026-UCALPRG-0009999' && str_contains($l['url'], '2026-UCALPRG-0009999'), 'leggiNumero: iter concluso, numero e indirizzo');
prova(!$svc->leggiNumero(' ')['ok'], 'leggiNumero: senza record');

// --- Lettura dei file ------------------------------------------------------------------------------------------------------------
sezione('Lettura dei file: elenco dall\'XML di loadDocument');
$ns = LetturaFileTitulus::NS_XW;
$xmlDoc = static fn (string $files): string => '<?xml version="1.0" encoding="UTF-8"?><response><Document><doc nrecord="777" num_prot="2026-UCALPRG-0001234"><oggetto>X</oggetto>' . $files . '</doc></Document></response>';
$involucro = static fn (string $files): string => $xmlDoc('<files xmlns:xw="' . $ns . '">' . $files . '</files>');

$e = LetturaFileTitulus::elenco($involucro('<xw:file title="convenzione.pdf" name="id1"/>'));
prova($e === [['titolo' => 'convenzione.pdf', 'fileId' => 'id1', 'principale' => false, 'sha256' => null]], 'elenco: un file (senza principale né impronta)');
$e = LetturaFileTitulus::elenco($involucro('<xw:file title="a.pdf" name="a1"/><xw:file title="b.pdf" name="b1"/><xw:file title="c.docx" name="c1"/>'));
prova(count($e) === 3 && $e[0]['titolo'] === 'a.pdf' && $e[1]['fileId'] === 'b1' && $e[2]['titolo'] === 'c.docx', 'elenco: più file, nell\'ordine di Titulus');
$e = LetturaFileTitulus::elenco($involucro('<xw:file title="a.pdf" name="v1"><xw:file title="a.pdf" name="v2"><xw:file title="a.pdf" name="v3"/></xw:file></xw:file><xw:file title="b.pdf" name="b1"/>'));
prova($e === [['titolo' => 'a.pdf', 'fileId' => 'v3', 'principale' => false, 'sha256' => null], ['titolo' => 'b.pdf', 'fileId' => 'b1', 'principale' => false, 'sha256' => null]], 'elenco: versioni annidate, vale la più annidata (la più recente)');
prova(LetturaFileTitulus::elenco($involucro('')) === [] && LetturaFileTitulus::elenco($xmlDoc('')) === [], 'elenco: nessun file (files vuoto o assente)');
$e = LetturaFileTitulus::elenco($xmlDoc('<files xmlns:xw="' . $ns . '"><xw:file title="a.pdf" name="a1"/><altro title="no" name="no"/></files>'));
prova(count($e) === 1 && $e[0]['fileId'] === 'a1', 'elenco: si leggono solo i nodi xw:file');
$e = LetturaFileTitulus::elenco($xmlDoc('<files xmlns:xw="http://altro.example/ns"><xw:file title="a.pdf" name="a1"/></files>'));
prova($e === [], 'elenco: un xw:file di un altro namespace non conta');
$e = LetturaFileTitulus::elenco($involucro('<xw:file title="senza.pdf"/><xw:file title="b.pdf" name="b1"/>'));
prova(count($e) === 1 && $e[0]['fileId'] === 'b1', 'elenco: un file senza identificativo si salta');
$fallisce = static function (callable $f): string {
    try {
        $f();
    } catch (Throwable $x) {
        return $x->getMessage();
    }
    return '';
};
prova(str_contains($fallisce(fn () => LetturaFileTitulus::elenco($involucro('<xw:file title="solo.pdf"/>'))), 'senza identificativo'), 'elenco: tutti i file senza identificativo = risposta anomala');
$e = LetturaFileTitulus::elenco($involucro('<xw:file name="id9"/>'));
prova(count($e) === 1 && $e[0]['titolo'] === 'id9' && $e[0]['fileId'] === 'id9', 'elenco: senza titolo si usa l\'identificativo');

// principale e impronta SHA-256 (T1)
$dsNs = 'http://www.w3.org/2000/09/xmldsig#';
$bin = hash('sha256', 'contenuto-1', true);
$dig = static fn (string $algo, string $valore): string => '<ds:Reference xmlns:ds="' . $dsNs . '"><ds:DigestMethod Algorithm="' . $algo . '"/><ds:DigestValue>' . $valore . '</ds:DigestValue></ds:Reference>';
$sha = 'http://www.w3.org/2001/04/xmlenc#sha256';
$e = LetturaFileTitulus::elenco($involucro('<xw:file title="a.pdf" name="a1"/><xw:file title="b.pdf" name="b1" principale="true"/><xw:file title="c.pdf" name="c1" principale="false"/>'));
prova($e[0]['principale'] === false && $e[1]['principale'] === true && $e[2]['principale'] === false, 'elenco: principale="true" riconosciuto, il resto no');
$e = LetturaFileTitulus::elenco($involucro('<xw:file title="a.pdf" name="v1"><xw:file title="a.pdf" name="v2" principale="true"/></xw:file>'));
prova($e[0]['principale'] === true && $e[0]['fileId'] === 'v2', 'elenco: principale dichiarato sulla versione più recente');
$e = LetturaFileTitulus::elenco($involucro('<xw:file title="a.pdf" name="a1">' . $dig($sha, base64_encode($bin)) . '</xw:file>'));
prova($e[0]['sha256'] === bin2hex($bin), 'elenco: DigestValue base64 con DigestMethod SHA-256 = impronta in esadecimale');
$e = LetturaFileTitulus::elenco($involucro('<xw:file title="a.pdf" name="a1">' . $dig('http://www.w3.org/2001/04/xmldsig-more#sha256', strtoupper(bin2hex($bin))) . '</xw:file>'));
prova($e[0]['sha256'] === bin2hex($bin), 'elenco: impronta già esadecimale (anche maiuscola) normalizzata');
$e = LetturaFileTitulus::elenco($involucro('<xw:file title="a.pdf" name="v1">' . $dig($sha, base64_encode(hash('sha256', 'vecchio', true))) . '<xw:file title="a.pdf" name="v2">' . $dig($sha, base64_encode($bin)) . '</xw:file></xw:file>'));
prova($e[0]['fileId'] === 'v2' && $e[0]['sha256'] === bin2hex($bin), 'elenco: si prende l’impronta della versione più annidata, non della precedente');
$e = LetturaFileTitulus::elenco($involucro('<xw:file title="a.pdf" name="a1">' . $dig('http://www.w3.org/2000/09/xmldsig#sha1', base64_encode(sha1('x', true))) . '</xw:file>'));
prova($e[0]['sha256'] === null, 'elenco: un DigestMethod che non è SHA-256 non dà impronta');
$e = LetturaFileTitulus::elenco($involucro('<xw:file title="a.pdf" name="a1">' . $dig($sha, base64_encode('corto')) . '</xw:file>'));
prova($e[0]['sha256'] === null, 'elenco: un DigestValue della lunghezza sbagliata non dà impronta');
$e = LetturaFileTitulus::elenco($involucro('<xw:file title="a.pdf" name="a1">' . $dig($sha, base64_encode($bin)) . $dig($sha, base64_encode(hash('sha256', 'altro', true))) . '</xw:file>'));
prova($e[0]['sha256'] === null, 'elenco: due impronte diverse nello stesso file = non si indovina');
$e = LetturaFileTitulus::elenco($involucro('<xw:file title="a.pdf" name="a1"><ds:DigestValue xmlns:ds="' . $dsNs . '">' . base64_encode($bin) . '</ds:DigestValue></xw:file>'));
prova($e[0]['sha256'] === null, 'elenco: un DigestValue senza DigestMethod non dà impronta');

prova(str_contains($fallisce(fn () => LetturaFileTitulus::elenco('non è xml <<<')), 'non è un XML leggibile'), 'elenco: XML rotto rifiutato');
$cattivo = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><response><doc><files xmlns:xw="' . $ns . '"><xw:file title="&x;" name="a"/></files></doc></response>';
prova(str_contains($fallisce(fn () => LetturaFileTitulus::elenco($cattivo)), 'DOCTYPE'), 'elenco: un DOCTYPE con entità esterna è rifiutato (niente lettura di file né rete)');
$ent = '<?xml version="1.0"?><!ENTITY a "x"><response/>';
prova($fallisce(fn () => LetturaFileTitulus::elenco($ent)) !== '', 'elenco: una definizione di entità è rifiutata');
$grande = $involucro('<xw:file title="a.pdf" name="a1"/>') . str_repeat(' ', LetturaFileTitulus::MAX_BYTE_XML);
prova(str_contains($fallisce(fn () => LetturaFileTitulus::elenco($grande)), 'troppo grande'), 'elenco: oltre il tetto di dimensione (' . LetturaFileTitulus::MAX_BYTE_XML . ' byte) rifiutato');
$a = LetturaFileTitulus::MAX_ANNIDAMENTO;
$fonda = str_repeat('<xw:file title="a.pdf" name="v">', $a + 1) . str_repeat('</xw:file>', $a + 1);
prova(str_contains($fallisce(fn () => LetturaFileTitulus::elenco($involucro($fonda))), 'annidate troppo a fondo'), 'elenco: versioni annidate oltre il tetto (' . $a . ') rifiutate');
$limite = str_repeat('<xw:file title="a.pdf" name="v">', $a) . str_repeat('</xw:file>', $a);
prova(LetturaFileTitulus::elenco($involucro($limite)) !== [], 'elenco: esattamente al tetto di annidamento si legge');
$tanti = str_repeat('<xw:file title="a.pdf" name="v"/>', LetturaFileTitulus::MAX_FILE + 1);
prova(str_contains($fallisce(fn () => LetturaFileTitulus::elenco($involucro($tanti))), 'troppi file'), 'elenco: troppi file rifiutato');
$profondo = str_repeat('<n>', 400) . str_repeat('</n>', 400);
prova($fallisce(fn () => LetturaFileTitulus::elenco($xmlDoc($profondo))) !== '', 'elenco: XML profondo centinaia di livelli rifiutato dal lettore');
prova(str_contains($fallisce(fn () => LetturaFileTitulus::testoXml('  ')), 'senza dati') && LetturaFileTitulus::testoXml('<a/>') === '<a/>', 'testoXml: risposta vuota rifiutata');
$obj = new stdClass();
$obj->any = '<doc/>';
prova(LetturaFileTitulus::testoXml($obj) === '<doc/>', 'testoXml: testo dentro un oggetto SOAP');

sezione('Lettura dei file: contenuto di getAttachment');
$pdfBin = "%PDF-1.7\n" . random_bytes(300) . "\n%%EOF";
$b = new stdClass();
$b->content = $pdfBin;
prova(LetturaFileTitulus::contenuto($b) === $pdfBin, 'contenuto: già binario (oggetto con «content»)');
$b = new stdClass();
$b->content = base64_encode($pdfBin);
prova(LetturaFileTitulus::contenuto($b) === $pdfBin, 'contenuto: base64 decodificato');
$b = new stdClass();
$b->content = chunk_split(base64_encode($pdfBin), 76, "\r\n");
prova(LetturaFileTitulus::contenuto($b) === $pdfBin, 'contenuto: base64 su più righe');
$v = new stdClass();
$v->_value_1 = base64_encode($pdfBin);
$b = new stdClass();
$b->content = $v;
prova(LetturaFileTitulus::contenuto($b) === $pdfBin, 'contenuto: «content» con dentro _value_1');
prova(LetturaFileTitulus::contenuto(['content' => $pdfBin]) === $pdfBin, 'contenuto: array con «content»');
prova(LetturaFileTitulus::contenuto('<bean><content>' . base64_encode($pdfBin) . '</content></bean>') === $pdfBin, 'contenuto: AttachmentBean in XML');
$zip = "PK\x03\x04" . random_bytes(50);
$b = new stdClass();
$b->content = $zip;
prova(LetturaFileTitulus::contenuto($b) === $zip, 'contenuto: un binario non PDF resta com\'è');
foreach (['oggetto vuoto' => new stdClass(), 'null' => null, 'numero' => 5, 'stringa vuota' => ''] as $nome => $r) {
    $m = $fallisce(fn () => LetturaFileTitulus::contenuto($r));
    prova($m !== '', "contenuto: $nome = errore chiaro");
}
$b = new stdClass();
$b->content = '';
prova(str_contains($fallisce(fn () => LetturaFileTitulus::contenuto($b)), 'vuoto'), 'contenuto: vuoto = errore chiaro');
prova($fallisce(fn () => LetturaFileTitulus::contenuto('<!DOCTYPE x [<!ENTITY y "z">]><a><content>QQ==</content></a>')) !== '', 'contenuto: un XML con DOCTYPE è rifiutato');

sezione('Lettura dei file: ServizioTitulus (senza eccezioni, con il motivo)');
$finto->file = [['titolo' => 'convenzione.pdf', 'fileId' => 'f1', 'principale' => true, 'sha256' => hash('sha256', $pdfBin)]];
$finto->contenuti = ['f1' => $pdfBin];
$r = $svc->fileDelDocumento('777');
prova($r['ok'] && $r['file'] === $finto->file, 'servizio: elenco dei file');
$r = $svc->scaricaFile('f1');
prova($r['ok'] && $r['contenuto'] === $pdfBin, 'servizio: contenuto del file');
$finto->file = [];
$r = $svc->fileDelDocumento('777');
prova(!$r['ok'] && str_contains($r['messaggio'], 'nessun file') && $r['file'] === [], 'servizio: documento senza file → «nessun file»');
$finto->erroreLettura = 'Titulus ha risposto con un errore (loadDocument): HTTP 403 Forbidden';
$r = $svc->fileDelDocumento('777');
prova(!$r['ok'] && str_contains($r['messaggio'], 'Permesso negato') && str_contains($r['messaggio'], 'loadDocument e getAttachment'), 'servizio: permesso negato spiegato');
$r = $svc->scaricaFile('f1');
prova(!$r['ok'] && str_contains($r['messaggio'], 'Permesso negato'), 'servizio: permesso negato anche sul file');
$finto->erroreLettura = 'Titulus ha risposto senza dati: il documento non esiste o questo utente non lo può leggere.';
$r = $svc->fileDelDocumento('999');
prova(!$r['ok'] && str_contains($r['messaggio'], 'non esiste in Titulus'), 'servizio: documento inesistente spiegato');
$finto->erroreLettura = 'Titulus ha risposto con un errore (loadDocument): ' . str_repeat('x', 900);
$r = $svc->fileDelDocumento('777');
prova(!$r['ok'] && str_starts_with($r['messaggio'], 'Risposta inattesa da Titulus:') && mb_strlen($r['messaggio']) < 400, 'servizio: risposta inattesa, con un taglio');
$finto->erroreLettura = 'La risposta di Titulus non è un XML leggibile.';
$r = $svc->fileDelDocumento('777');
prova(!$r['ok'] && str_contains($r['messaggio'], 'Risposta inattesa'), 'servizio: XML illeggibile = risposta inattesa');
$finto->erroreLettura = null;
$finto->contenuti = ['f1' => ''];
$r = $svc->scaricaFile('f1');
prova(!$r['ok'] && str_contains($r['messaggio'], 'vuoto'), 'servizio: file vuoto = errore');
prova(!$svc->fileDelDocumento('  ')['ok'] && !$svc->scaricaFile('')['ok'], 'servizio: numero o identificativo mancante');
prova(!$spento->fileDelDocumento('777')['ok'] && !$spento->scaricaFile('f1')['ok'], 'servizio: collegamento spento = non legge');
prova(str_contains((new ServizioTitulus($cfg, '', $fabbrica))->scaricaFile('f1')['messaggio'], 'password'), 'servizio: senza password lo dice');

// --- Registro su file ----------------------------------------------------------------------------------------------------------
sezione('Registro su file');
$tmp = tempnam(sys_get_temp_dir(), 'tit');
@unlink($tmp);
$rf = new RegistroSuFile($tmp);
$s2 = new ServizioTitulus($cfg, 'segreta', $fabbrica, $rf);
$e = $s2->protocolla($doc, $param);
prova($e->ok && $e->invioId === 1 && is_file($tmp), 'registro su file: invio annotato');
prova($rf->numeroAssegnato('convenzione', 5) === '2026-UCALPRG-0001234' && $rf->numeroAssegnato('convenzione', 6) === null, 'registro su file: il numero si ritrova per riferimento');
prova(!str_contains((string) file_get_contents($tmp), 'segreta'), 'registro su file: nessuna password');
@unlink($tmp);

// --- Client SOAP: scelte che non si devono perdere ------------------------------------------------------------------------------
sezione('Client SOAP');
$sorgenteSoap = (string) file_get_contents(__DIR__ . '/../src/ClienteSoapTitulus.php');
// Con WSDL_CACHE_MEMORY il WSDL rpc/encoded di Titulus4 corrompe la richiesta e manda in segfault PHP-FPM (provato con PHP 8.2 e 8.4).
prova(preg_match('/=>\s*WSDL_CACHE_MEMORY/', $sorgenteSoap) !== 1, 'client SOAP: mai WSDL_CACHE_MEMORY (segfault di PHP con il WSDL di Titulus)');
// Le operazioni di lettura (loadDocument, getAttachment) possono mancare o non essere permesse: prova() non deve renderle obbligatorie
prova(preg_match('/\$mancanti\s*=\s*array_diff\(\[([^\]]*)\]/', $sorgenteSoap, $mm) === 1 && !str_contains($mm[1], 'loadDocument') && !str_contains($mm[1], 'getAttachment'), 'client SOAP: loadDocument e getAttachment non sono tra le operazioni obbligatorie di prova()');

echo "\n$ok prove riuscite, $ko fallite.\n";
exit($ko > 0 ? 1 : 0);
