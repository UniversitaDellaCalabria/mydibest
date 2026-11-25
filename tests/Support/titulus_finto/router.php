<?php

declare(strict_types=1);

/*
 * Finto servizio SOAP di Titulus, SOLO per le prove (non è mai caricato dal portale). Si avvia con il server integrato di PHP:
 *
 *   php -d extension=soap -S 127.0.0.1:8099 tests/Support/titulus_finto/router.php
 *   → WSDL: http://127.0.0.1:8099/ws/Titulus4?wsdl      utente «prova», password «segreta» (HTTP Basic, come Titulus)
 *   → ?wsdl&senza=loadDocument toglie un'operazione dal WSDL (per provare «manca nel WSDL»)
 *
 * Parla rpc/encoded come Titulus4 (Axis 1.4) e ha le operazioni loadDocument, getAttachment, getDocumentURL e getRecordInfos. I documenti sono
 * quelli di documenti() qui sotto: ognuno prova un caso (versioni annidate, più file, nessun file, base64, non PDF, permesso negato…).
 * Il documento si indica con il numero di record o con il numero di protocollo.
 */

const UTENTE = 'prova';
const PASSWORD = 'segreta';
const NS = 'urn:TitulusFinto';
const NS_XW = 'http://www.kion.it/ns/xw';

/** Un PDF finto ma con l'intestazione giusta; $segno lo rende riconoscibile. */
function pdfFinto(string $segno): string
{
    return "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% $segno\n%%EOF\n";
}

/** Un PDF con una firma PAdES costruita a mano (dizionario /Sig con /ByteRange, nome del firmatario compreso: lo strumento non deve stamparlo). */
function pdfFirmato(): string
{
    $testa = "%PDF-1.7
1 0 obj
<< /Type /Catalog >>
endobj
";
    $costruisci = static fn (int $a, int $b, int $c): string => $testa . "5 0 obj
<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached /Name (Mario Rossi) /M (D:20260101000000Z) /ByteRange [0 $a $b $c] /Contents <"
        . str_repeat('00', 40) . "> >>
endobj
trailer
<< /Root 1 0 R >>
%%EOF
";
    $x = $costruisci(100000, 200000, 300000);
    for ($i = 0; $i < 3; $i++) { // i numeri del /ByteRange cambiano la lunghezza del file: si ricalcolano finché si assestano
        $pos = (int) strpos($x, '<0000');
        $x = $costruisci($pos, $pos + 82, strlen($x) - ($pos + 82));
    }

    return $x;
}

/**
 * @param list<array{0: string, 1: string, 2?: array<mixed>}> $file [titolo, fileId, [figli annidati]]
 */
function nodiFile(array $file): string
{
    $x = '';
    foreach ($file as $f) {
        // $f[3]: attributi in più (per esempio principale="true"); $f[4]: impronta SHA-256 dichiarata, in esadecimale (o 'sbagliata')
        $digest = isset($f[4]) ? '<ds:Reference xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/><ds:DigestValue>'
            . base64_encode(hex2bin($f[4] === 'sbagliata' ? hash('sha256', 'altro contenuto') : hash('sha256', contenuti()[$f[1]]))) . '</ds:DigestValue></ds:Reference>' : '';
        $x .= '<xw:file title="' . htmlspecialchars($f[0], ENT_XML1) . '" name="' . htmlspecialchars($f[1], ENT_XML1) . '"' . ($f[3] ?? '') . '>' . $digest . nodiFile($f[2] ?? []) . '</xw:file>';
    }

    return $x;
}

/** @return array<string, array{num: string, file?: list<array<mixed>>, errore?: string, vuoto?: bool}> */
function documenti(): array
{
    return [
        // Un file in tre versioni una dentro l'altra: vale la più annidata (v3)
        '100' => ['num' => '2026-UCALPRG-0001234', 'file' => [['convenzione.pdf', 'v1', [['convenzione.pdf', 'v2', [['convenzione.pdf', 'v3']]]]]]],
        // Tre file: il principale si riconosce dal titolo
        '200' => ['num' => '2026-UCALPRG-0000200', 'file' => [['allegato_a.pdf', 'a1'], ['convenzione.pdf', 'c1'], ['note.docx', 'd1']]],
        '300' => ['num' => '2026-UCALPRG-0000300', 'file' => []],
        '400' => ['num' => '2026-UCALPRG-0000400', 'errore' => 'Access denied: the user is not authorized to load this document'],
        '501' => ['num' => '2026-UCALPRG-0000501', 'vuoto' => true],
        '600' => ['num' => '2026-UCALPRG-0000600', 'file' => [['documento.txt', 't1']]],
        // Il contenuto arriva in base64 (come se il servizio lo dichiarasse testo)
        '700' => ['num' => '2026-UCALPRG-0000700', 'file' => [['convenzione_b64.pdf', 'b1']]],
        // Due file con lo stesso titolo: non si sa quale sia il principale
        '800' => ['num' => '2026-UCALPRG-0000800', 'file' => [['doppio.pdf', 'p1'], ['doppio.pdf', 'p2']]],
        // Il documento si legge ma il file no
        '900' => ['num' => '2026-UCALPRG-0000900', 'file' => [['negato.pdf', 'deny1']]],
        // Un PDF con una firma
        '1000' => ['num' => '2026-UCALPRG-0001000', 'file' => [['firmata.pdf', 's1']]],
        // Più file: il principale lo dice Titulus (principale="true"), con l'impronta SHA-256 giusta
        '1100' => ['num' => '2026-UCALPRG-0001100', 'file' => [['allegato_a.pdf', 'a1'], ['nome_diverso.pdf', 'c1', [], ' principale="true"', 'giusta']]],
        // Con l'impronta SHA-256 che NON coincide col file
        '1200' => ['num' => '2026-UCALPRG-0001200', 'file' => [['convenzione.pdf', 'c1', [], ' principale="true"', 'sbagliata']]],
    ];
}

/** @return array<string, string> fileId → contenuto (per i base64 il testo è già in base64) */
function contenuti(): array
{
    return [
        'v1' => pdfFinto('versione 1'), 'v2' => pdfFinto('versione 2'), 'v3' => pdfFinto('versione 3 stampigliata'),
        'a1' => pdfFinto('allegato A'), 'c1' => pdfFinto('convenzione principale'), 'd1' => "PK\x03\x04" . 'finto docx',
        't1' => "Questo non e' un PDF!\n",
        'b1' => base64_encode(pdfFinto('convenzione in base64')),
        'p1' => pdfFinto('doppio uno'), 'p2' => pdfFinto('doppio due'),
        's1' => pdfFirmato(),
    ];
}

function trovaDocumento(string $id): ?array
{
    foreach (documenti() as $nrecord => $d) {
        if ($id === (string) $nrecord || $id === $d['num']) {
            return $d + ['nrecord' => (string) $nrecord];
        }
    }

    return null;
}

final class FintoTitulus
{
    public function loadDocument(string $id, bool $lock = false): string
    {
        $d = trovaDocumento($id);
        if ($d === null) {
            throw new SoapFault('Server', "Document $id not found");
        }
        if (isset($d['errore'])) {
            throw new SoapFault('Server', $d['errore']);
        }
        if (!empty($d['vuoto'])) {
            return '';
        }
        $files = $d['file'] === [] ? '' : '<files xmlns:xw="' . NS_XW . '">' . nodiFile($d['file']) . '</files>';

        return '<?xml version="1.0" encoding="UTF-8"?><response><Document><doc nrecord="' . $d['nrecord'] . '" num_prot="' . $d['num'] . '"><oggetto>DOCUMENTO DI PROVA</oggetto>' . $files . '</doc></Document></response>';
    }

    /** @return array{fileName: string, content: string} */
    public function getAttachment(string $fileId): array
    {
        if ($fileId === 'deny1') {
            throw new SoapFault('Server', '403 Forbidden: permission denied on attachment');
        }
        $c = contenuti();
        if (!isset($c[$fileId])) {
            throw new SoapFault('Server', "Attachment $fileId not found");
        }

        return ['fileName' => $fileId . '.bin', 'content' => $c[$fileId]];
    }

    public function getDocumentURL(string $id): string
    {
        if (trovaDocumento($id) === null) {
            throw new SoapFault('Server', "Document $id not found");
        }

        return 'https://titulus.finto.pp.cineca.it/titulus/doc?id=' . rawurlencode($id);
    }

    public function getRecordInfos(string $id): string
    {
        $d = trovaDocumento($id);
        if ($d === null) {
            throw new SoapFault('Server', "Document $id not found");
        }

        return '<response><doc nrecord="' . $d['nrecord'] . '" num_prot="' . $d['num'] . '"/></response>';
    }
}

function wsdl(string $indirizzo, string $senza): string
{
    $ops = [
        'loadDocument' => [['id', 'xsd:string'], ['lock', 'xsd:boolean']],
        'getAttachment' => [['fileId', 'xsd:string']],
        'getDocumentURL' => [['id', 'xsd:string']],
        'getRecordInfos' => [['id', 'xsd:string']],
    ];
    $ritorno = ['loadDocument' => 'xsd:string', 'getAttachment' => 'tns:AttachmentBean', 'getDocumentURL' => 'xsd:string', 'getRecordInfos' => 'xsd:string'];
    foreach (array_filter(explode(',', $senza)) as $n) {
        unset($ops[$n]);
    }
    $msg = $port = $bind = '';
    foreach ($ops as $nome => $par) {
        $parti = '';
        foreach ($par as [$n, $t]) {
            $parti .= "<part name=\"$n\" type=\"$t\"/>";
        }
        $msg .= "<message name=\"{$nome}Request\">$parti</message><message name=\"{$nome}Response\"><part name=\"{$nome}Return\" type=\"{$ritorno[$nome]}\"/></message>";
        $port .= "<operation name=\"$nome\"><input message=\"tns:{$nome}Request\"/><output message=\"tns:{$nome}Response\"/></operation>";
        $bind .= "<operation name=\"$nome\"><soap:operation soapAction=\"\"/><input><soap:body use=\"encoded\" namespace=\"" . NS . "\" encodingStyle=\"http://schemas.xmlsoap.org/soap/encoding/\"/></input>"
            . "<output><soap:body use=\"encoded\" namespace=\"" . NS . "\" encodingStyle=\"http://schemas.xmlsoap.org/soap/encoding/\"/></output></operation>";
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<definitions name="TitulusFinto" targetNamespace="' . NS . '" xmlns="http://schemas.xmlsoap.org/wsdl/" xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/" xmlns:tns="' . NS . '" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">'
        . '<types><xsd:schema targetNamespace="' . NS . '"><xsd:complexType name="AttachmentBean"><xsd:all><xsd:element name="fileName" type="xsd:string"/><xsd:element name="content" type="xsd:base64Binary"/></xsd:all></xsd:complexType></xsd:schema></types>'
        . $msg . '<portType name="TitulusFintoPort">' . $port . '</portType>'
        . '<binding name="TitulusFintoBinding" type="tns:TitulusFintoPort"><soap:binding style="rpc" transport="http://schemas.xmlsoap.org/soap/http"/>' . $bind . '</binding>'
        . '<service name="TitulusFintoService"><port name="TitulusFinto" binding="tns:TitulusFintoBinding"><soap:address location="' . htmlspecialchars($indirizzo, ENT_XML1) . '"/></port></service></definitions>';
}

// --- Il server -------------------------------------------------------------------------------------------------------------------------
$percorso = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if ($percorso !== '/ws/Titulus4') {
    http_response_code(404);
    echo 'non trovato';

    return true;
}

// HTTP Basic, come Titulus: senza utente e password giusti 401
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : ''));
if ($auth !== 'Basic ' . base64_encode(UTENTE . ':' . PASSWORD)) {
    header('WWW-Authenticate: Basic realm="Titulus finto"');
    http_response_code(401);
    echo 'Unauthorized';

    return true;
}

$indirizzo = 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . '/ws/Titulus4';
if (isset($_GET['wsdl'])) {
    header('Content-Type: text/xml; charset=utf-8');
    echo wsdl($indirizzo, (string) ($_GET['senza'] ?? ''));

    return true;
}

$wsdlFile = sys_get_temp_dir() . '/titulus_finto_' . getmypid() . '.wsdl';
file_put_contents($wsdlFile, wsdl($indirizzo, ''));
$server = new SoapServer($wsdlFile, ['uri' => NS, 'cache_wsdl' => WSDL_CACHE_NONE]);
$server->setClass(FintoTitulus::class);
try {
    $server->handle();
} finally {
    @unlink($wsdlFile);
}

return true;
