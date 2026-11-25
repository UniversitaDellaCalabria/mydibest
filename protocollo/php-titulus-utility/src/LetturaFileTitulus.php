<?php

declare(strict_types=1);

namespace Unical\Titulus;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Lettura dei file di un documento di Titulus (porta di WSTitulusFileClient del connettore Python, protocollo.py): dall'XML di
 * loadDocument l'elenco dei file con l'identificativo della versione più recente, dalla risposta di getAttachment il contenuto.
 *
 * Qui non c'è rete: si lavora su testo già ricevuto, quindi si prova senza il servizio vero. L'XML arriva da fuori e si legge con cautela:
 * niente rete e niente entità esterne, niente DOCTYPE, un tetto alla dimensione e uno alla profondità.
 */
final class LetturaFileTitulus
{
    /** Namespace dei nodi xw:file nell'XML di Titulus. */
    public const NS_XW = 'http://www.kion.it/ns/xw';
    /** Oltre questa dimensione l'XML del documento non si legge (un documento con centinaia di file ne resta molto sotto). */
    public const MAX_BYTE_XML = 5_000_000;
    /** Versioni di uno stesso file una dentro l'altra: oltre questo livello la risposta è considerata anomala. */
    public const MAX_ANNIDAMENTO = 50;
    /** File di un solo documento. */
    public const MAX_FILE = 200;

    /**
     * I file del documento: per ognuno il titolo, l'identificativo della versione più recente (il nodo xw:file più annidato), se è il file
     * principale (attributo principale="true" sul nodo o sulla versione più recente) e l'impronta SHA-256 dichiarata da Titulus per quella
     * versione (DigestValue accanto a un DigestMethod SHA-256, in esadecimale minuscolo; null se non c'è o non è univoca).
     * Nessun file = elenco vuoto.
     *
     * @return list<array{titolo: string, fileId: string, principale: bool, sha256: ?string}>
     * @throws RuntimeException se l'XML non si legge o è anomalo (troppo grande, con DOCTYPE, troppo annidato, troppi file)
     */
    public static function elenco(string $xml): array
    {
        if (strlen($xml) > self::MAX_BYTE_XML) {
            throw new RuntimeException('La risposta di Titulus è troppo grande: non la leggo.');
        }
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new RuntimeException('La risposta di Titulus contiene una definizione di tipo (DOCTYPE): non la accetto.');
        }
        $dom = new DOMDocument();
        $prima = libxml_use_internal_errors(true);
        try {
            // Né LIBXML_NOENT né LIBXML_DTDLOAD: le entità non si espandono e nulla si scarica; LIBXML_NONET vieta comunque la rete
            $letto = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prima);
        }
        if ($letto === false || $dom->documentElement === null) {
            throw new RuntimeException('La risposta di Titulus non è un XML leggibile.');
        }
        if ($dom->doctype !== null) {
            throw new RuntimeException('La risposta di Titulus contiene una definizione di tipo (DOCTYPE): non la accetto.');
        }

        $files = $dom->getElementsByTagNameNS('*', 'files')->item(0);
        if (!$files instanceof DOMElement) {
            return [];
        }
        $out = [];
        $senzaId = 0;
        foreach ($files->childNodes as $nodo) {
            if (!self::eFile($nodo)) {
                continue;
            }
            /** @var DOMElement $nodo */
            if (count($out) + $senzaId >= self::MAX_FILE) {
                throw new RuntimeException('Il documento ha troppi file: la risposta di Titulus è anomala.');
            }
            // La versione più recente è il nodo più annidato: a ogni livello si scende nel primo xw:file figlio (come il connettore Python)
            $corrente = $nodo;
            $livello = 1;
            while (($figlio = self::primoFileFiglio($corrente)) !== null) {
                $corrente = $figlio;
                if (++$livello > self::MAX_ANNIDAMENTO) {
                    throw new RuntimeException('Le versioni di un file sono annidate troppo a fondo: la risposta di Titulus è anomala.');
                }
            }
            $fileId = trim($corrente->getAttribute('name'));
            if ($fileId === '') {
                $senzaId++;
                continue;
            }
            $titolo = trim($nodo->getAttribute('title'));
            if ($titolo === '') {
                $titolo = trim($corrente->getAttribute('title'));
            }
            $out[] = [
                'titolo' => $titolo !== '' ? $titolo : $fileId,
                'fileId' => $fileId,
                'principale' => self::vero($nodo->getAttribute('principale')) || self::vero($corrente->getAttribute('principale')),
                'sha256' => self::impronta($corrente),
            ];
        }
        if ($out === [] && $senzaId > 0) {
            throw new RuntimeException('Titulus elenca dei file senza identificativo: la risposta è anomala.');
        }

        return $out;
    }

    /**
     * Il testo XML della risposta di una chiamata SOAP (testo oppure oggetto con il testo dentro).
     *
     * @throws RuntimeException se la risposta è vuota
     */
    public static function testoXml(mixed $risposta): string
    {
        $testo = is_string($risposta) ? $risposta : (is_object($risposta) ? (string) ($risposta->any ?? $risposta->_ ?? $risposta->_value_1 ?? '') : '');
        if (trim($testo) === '') {
            throw new RuntimeException('Titulus ha risposto senza dati: il documento non esiste o questo utente non lo può leggere.');
        }

        return $testo;
    }

    /**
     * Il contenuto del file dalla risposta di getAttachment: PHP lo dà già in binario, ma se il servizio lo dichiara come testo arriva in
     * base64 (come nel connettore Python, che lo decodifica a mano). Anche un AttachmentBean in XML, con il contenuto nel nodo «content».
     *
     * @throws RuntimeException se non c'è, è vuoto o ha una forma che non si legge
     */
    public static function contenuto(mixed $risposta): string
    {
        $c = self::valore($risposta);
        if (is_string($c) && self::sembraXml($c)) {
            $c = self::contenutoDaXml($c);
        }
        if ($c === null) {
            throw new RuntimeException('Titulus ha risposto in una forma che non so leggere: il contenuto del file non c\'è.');
        }
        if ($c === '') {
            throw new RuntimeException('Il contenuto del file restituito da Titulus è vuoto.');
        }

        return self::decodifica($c);
    }

    private static function vero(string $valore): bool
    {
        return strtolower(trim($valore)) === 'true';
    }

    /**
     * L'impronta SHA-256 dichiarata nel nodo della versione scelta: ogni DigestValue il cui DigestMethod (stesso genitore) è SHA-256.
     * Se i valori trovati sono diversi tra loro non si indovina: null. Il valore è base64 (come in XML-DSig) o già esadecimale.
     */
    private static function impronta(DOMElement $versione): ?string
    {
        $trovate = [];
        foreach ($versione->getElementsByTagNameNS('*', 'DigestValue') as $valore) {
            $genitore = $valore->parentNode;
            if (!$genitore instanceof DOMElement) {
                continue;
            }
            $sha256 = false;
            foreach ($genitore->childNodes as $fratello) {
                if ($fratello instanceof DOMElement && $fratello->localName === 'DigestMethod'
                    && preg_match('/sha-?256$/i', trim($fratello->getAttribute('Algorithm'))) === 1) {
                    $sha256 = true;
                }
            }
            if (!$sha256) {
                continue;
            }
            $testo = trim($valore->textContent);
            if (preg_match('/^[0-9a-fA-F]{64}$/', $testo) === 1) {
                $trovate[strtolower($testo)] = true;
                continue;
            }
            $bin = base64_decode((string) preg_replace('/\s+/', '', $testo), true);
            if ($bin !== false && strlen($bin) === 32) {
                $trovate[bin2hex($bin)] = true;
            }
        }

        return count($trovate) === 1 ? (string) array_key_first($trovate) : null;
    }

    private static function eFile(mixed $nodo): bool
    {
        return $nodo instanceof DOMElement && $nodo->namespaceURI === self::NS_XW && $nodo->localName === 'file';
    }

    private static function primoFileFiglio(DOMElement $e): ?DOMElement
    {
        foreach ($e->childNodes as $n) {
            if (self::eFile($n)) {
                /** @var DOMElement $n */
                return $n;
            }
        }

        return null;
    }

    /** Il valore «content» di una risposta: stringa, oggetto o array del SoapClient. */
    private static function valore(mixed $v, int $livello = 0): ?string
    {
        if ($livello > 5) {
            return null;
        }
        if (is_string($v)) {
            return $v;
        }
        if (is_array($v)) {
            foreach (['content', '_value_1', '_'] as $k) {
                if (isset($v[$k])) {
                    return self::valore($v[$k], $livello + 1);
                }
            }

            return null;
        }
        if (is_object($v)) {
            foreach (['content', '_value_1', '_', 'any'] as $k) {
                if (isset($v->$k)) {
                    return self::valore($v->$k, $livello + 1);
                }
            }
        }

        return null;
    }

    private static function sembraXml(string $s): bool
    {
        return str_starts_with(ltrim(substr($s, 0, 64)), '<');
    }

    private static function contenutoDaXml(string $xml): ?string
    {
        if (strlen($xml) > 80_000_000 || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new RuntimeException('La risposta di Titulus non è nella forma attesa.');
        }
        $dom = new DOMDocument();
        $prima = libxml_use_internal_errors(true);
        try {
            $letto = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prima);
        }
        if ($letto === false || $dom->doctype !== null) {
            throw new RuntimeException('La risposta di Titulus non è nella forma attesa.');
        }
        $nodo = $dom->getElementsByTagNameNS('*', 'content')->item(0);

        return $nodo !== null ? trim($nodo->textContent) : null;
    }

    /** Un PDF e ogni altro file binario restano come sono; un testo fatto solo di caratteri base64 si decodifica. */
    private static function decodifica(string $c): string
    {
        if (str_starts_with($c, '%PDF-')) {
            return $c;
        }
        if (preg_match('/^[A-Za-z0-9+\/=\s]*$/D', substr($c, 0, 4096)) !== 1) {
            return $c;
        }
        $d = base64_decode((string) preg_replace('/\s+/', '', $c), true);

        return $d !== false && $d !== '' ? $d : $c;
    }
}
