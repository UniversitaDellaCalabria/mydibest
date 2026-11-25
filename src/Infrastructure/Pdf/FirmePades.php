<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use OverflowException;

/**
 * Firme digitali presenti in un PDF. PAdES = firma dentro il PDF (dizionario /Sig con /ByteRange); un file .p7m (CAdES) non è
 * un PDF e non ne ha. Spostata da firme_pades_pdf() di inc/pdf.php (che resta come facciata).
 * I dati si leggono dal dizionario che contiene il /ByteRange, in qualunque ordine siano le voci: /Contents può occupare decine di migliaia
 * di caratteri tra l'una e l'altra. Niente espressioni regolari sul file intero (pcre.backtrack_limit): solo strpos/strrpos e strspn/strcspn.
 * Il file arriva anche da fuori (pagina della scuola): il lavoro ha un tetto fisso. Oltre un limite il controllo non si fa a metà: si ritorna
 * un elenco vuoto, chi chiama rifiuta il file come «senza firma» e il motivo resta nel log.
 */
final class FirmePades implements VerificaFirme
{
    /** Formato delle marche temporali (document timestamp): stanno nel PDF come le firme ma non dicono chi ha firmato. */
    public const MARCA = 'ETSI.RFC3161';

    private const SPAZI = " \t\r\n\f\0";
    private const FINE_PAROLA = " \t\r\n\f\0()<>[]{}/%";
    /** Le voci che servono: le altre (soprattutto /Contents) si saltano senza copiarle. */
    private const VOCI = ['SubFilter', 'Name', 'M'];
    /** Quanti «obj» si provano all'indietro (la parola può stare per caso dentro una stringa del dizionario). */
    private const MAX_TENTATIVI = 10;
    /** Quante scritte «/ByteRange» si esaminano: un PDF vero ne ha una per firma. */
    private const MAX_BYTERANGE = 100;
    /** Quanti byte possono stare tra «obj» e /ByteRange (in mezzo può esserci /Contents). */
    private const MAX_DISTANZA = 2 * 1024 * 1024;
    /** Quanti dizionari e array annidati. */
    private const MAX_PROFONDITA = 100;
    /** Tetto al lavoro per file: un punto è un byte scorso con una ricerca semplice; gli altri passaggi costano in proporzione al tempo che chiedono. */
    private const MAX_LAVORO = 120 * 1000 * 1000;
    private const COSTO_PASSO = 400;
    private const COSTO_SPAZI = 6;
    private const COSTO_PAROLA = 14;
    private const COSTO_TESTO = 3;

    private int $lavoro = 0;

    /**
     * @return list<array<string, mixed>> una voce per firma: subfilter ('ETSI.CAdES.detached', 'adbe.pkcs7.detached'…), nome, data,
     *                                    marca_temporale (è una marca, non una firma), intervallo (i 4 numeri del /ByteRange),
     *                                    fine, valida_struttura e copre_tutto (l'ultima firma copre il file fino alla fine)
     */
    public function firmePades(string $pdf): array
    {
        if (strncmp($pdf, '%PDF-', 5) !== 0) {
            return [];
        }
        $this->lavoro = 0;
        try {
            return $this->cerca($pdf);
        } catch (OverflowException $e) {
            error_log('[firme PDF] file non controllabile (' . strlen($pdf) . ' byte): ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     * @throws OverflowException oltre i limiti
     */
    private function cerca(string $pdf): array
    {
        $out = [];
        $esaminati = 0;
        $lung = strlen(rtrim($pdf));
        for ($pos = strpos($pdf, '/ByteRange'); $pos !== false; $pos = strpos($pdf, '/ByteRange', $pos + 10)) {
            if (++$esaminati > self::MAX_BYTERANGE) {
                throw new OverflowException('più di ' . self::MAX_BYTERANGE . ' /ByteRange');
            }
            if (!preg_match('#\A\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]#', substr($pdf, $pos + 10, 400), $m)) {
                continue;
            }
            $voci = $this->dizionario($pdf, $pos);
            if ($voci === null) {
                continue;   // la scritta «/ByteRange» non è la voce di un dizionario: non è una firma
            }
            [$a, $b, $c, $d] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
            $sub = substr((string) preg_replace('/[^A-Za-z0-9.\-]/', '', $voci['SubFilter'] ?? ''), 0, 60);
            $out[] = [
                'subfilter' => $sub,
                'nome' => $voci['Name'] ?? '',
                'data' => preg_match('/^D:(\d{14})/', $voci['M'] ?? '', $x) ? $x[1] : '',
                'marca_temporale' => $sub === self::MARCA,
                'intervallo' => [$a, $b, $c, $d],
                'fine' => $c + $d,
                'valida_struttura' => $a === 0 && $c > $b && $c + $d <= strlen($pdf),
                'copre_tutto' => $c + $d >= $lung - 2,
            ];
        }

        return $out;
    }

    /** Conta il lavoro fatto sul file. */
    private function spendi(int $punti): void
    {
        $this->lavoro += $punti;
        if ($this->lavoro > self::MAX_LAVORO) {
            throw new OverflowException('il controllo richiede troppo lavoro');
        }
    }

    /**
     * Le voci del dizionario che ha il /ByteRange alla posizione indicata: si parte dall'«obj» che lo precede. null se quel /ByteRange non è
     * la voce di un dizionario di quell'oggetto (così non si prendono mai i dati di un altro).
     *
     * @return array<string, string>|null
     */
    private function dizionario(string $pdf, int $pos): ?array
    {
        $inizio = max(0, $pos - self::MAX_DISTANZA);
        $tratto = substr($pdf, $inizio, $pos - $inizio);
        $this->spendi(strlen($tratto) >> 3);
        $cerca = strlen($tratto);
        for ($n = 0; $n < self::MAX_TENTATIVI; $n++) {
            // strrpos con posizione negativa trova anche un «obj» che comincia lì
            $obj = $cerca < 3 ? false : strrpos($tratto, 'obj', $cerca - 3 - strlen($tratto));
            $this->spendi(self::COSTO_PASSO + $cerca - (int) $obj);
            if ($obj === false || $inizio + $obj < 4) {
                if ($inizio > 0) {
                    throw new OverflowException('/ByteRange troppo lontano dall\'inizio del suo oggetto');
                }

                return null;
            }
            $cerca = $obj;
            $obj += $inizio;
            // «12 0 obj»: prima ci sono due numeri (con «endobj» no)
            $da = max(0, $obj - 30);
            if (preg_match('/\d\s+\d+\s+\z/', substr($pdf, $da, $obj - $da))) {
                $voci = $this->leggi($pdf, $obj + 3, $pos);
                if ($voci !== null) {
                    return $voci;
                }
            }
        }

        return null;
    }

    /**
     * Scorre un oggetto dall'inizio e ritorna le voci del dizionario che contiene il /ByteRange cercato (anche annidato), null se non c'è.
     *
     * @return array<string, string>|null
     */
    private function leggi(string $pdf, int $i, int $posByteRange): ?array
    {
        $lung = strlen($pdf);
        /** @var list<array{array: bool, chiave: string|null, trovato: bool, voci: array<string, string>}> $pila */
        $pila = [];
        $trovato = false;
        while (true) {
            $n = strspn($pdf, self::SPAZI, $i);
            $this->spendi(self::COSTO_PASSO + $n * self::COSTO_SPAZI);
            $i += $n;
            if ($i >= $lung || (!$trovato && $i > $posByteRange)) {
                return null;
            }
            $c = $pdf[$i];
            $due = substr($pdf, $i, 2);
            if ($c === '%') {
                $n = strcspn($pdf, "\r\n", $i);
                $this->spendi($n * self::COSTO_TESTO);
                $i += $n;
                continue;
            }
            if ($due === '<<' || $c === '[') {
                if (count($pila) >= self::MAX_PROFONDITA) {
                    throw new OverflowException('troppi dizionari annidati');
                }
                $pila[] = ['array' => $c === '[', 'chiave' => null, 'trovato' => false, 'voci' => []];
                $i += $c === '[' ? 1 : 2;
                continue;
            }
            $cima = count($pila) - 1;
            if ($cima < 0) {
                return null;
            }
            if ($due === '>>' || $c === ']') {
                $chiuso = array_pop($pila);
                if ($chiuso['trovato']) {
                    return $chiuso['voci'];
                }
                if ($chiuso['array'] !== ($c === ']') || --$cima < 0) {
                    return null;
                }
                $i += $c === ']' ? 1 : 2;
                $valore = '';
            } elseif ($c === '(') {
                [$valore, $i] = $this->stringa($pdf, $i);
            } elseif ($c === '<') {
                $fine = strpos($pdf, '>', $i);
                $this->spendi(($fine === false ? $lung : $fine) - $i);
                if ($fine === false) {
                    return null;
                }
                // La stringa esadecimale si salta: si legge solo se è una voce che serve
                $serve = in_array($pila[$cima]['chiave'], self::VOCI, true);
                $esa = $serve ? (string) preg_replace('/[^0-9A-Fa-f]/', '', substr($pdf, $i + 1, min($fine - $i - 1, 2000))) : '';
                $valore = (string) hex2bin(strlen($esa) % 2 ? $esa . '0' : $esa);
                $i = $fine + 1;
            } else {
                $inizio = $i;
                $n = 1 + strcspn($pdf, self::FINE_PAROLA, $i + 1);
                $this->spendi($n * self::COSTO_PAROLA);
                $parola = substr($pdf, $i, min($n, 200));
                $i += $n;
                if ($c === '/' && !$pila[$cima]['array'] && $pila[$cima]['chiave'] === null) {
                    $pila[$cima]['chiave'] = substr($parola, 1);
                    if ($inizio === $posByteRange) {
                        $pila[$cima]['trovato'] = $trovato = true;
                    }
                    continue;
                }
                if ($parola === 'endobj' || $parola === 'stream') {
                    return null;
                }
                $valore = $c === '/' ? substr($parola, 1) : $parola;
            }
            $chiave = $pila[$cima]['chiave'];
            if (!$pila[$cima]['array'] && $chiave !== null) {
                if (in_array($chiave, self::VOCI, true)) {
                    $pila[$cima]['voci'][$chiave] = $this->testo($valore);
                }
                $pila[$cima]['chiave'] = null;
            }
        }
    }

    /**
     * Una stringa tra parentesi (annidate, con \ di protezione) che comincia alla posizione indicata.
     *
     * @return array{0: string, 1: int} [i primi caratteri senza protezioni, posizione dopo la parentesi finale]
     */
    private function stringa(string $pdf, int $i): array
    {
        $lung = strlen($pdf);
        $livello = 1;
        $s = '';
        ++$i;
        while ($i < $lung) {
            $n = strcspn($pdf, '()\\', $i);
            $this->spendi(self::COSTO_PASSO + $n * self::COSTO_TESTO);
            if (strlen($s) < 500) {
                $s .= substr($pdf, $i, min($n, 500));
            }
            $i += $n;
            if ($i >= $lung) {
                break;
            }
            $c = $pdf[$i++];
            if ($c === '\\') {
                if (preg_match('/\A[0-7]{1,3}/', substr($pdf, $i, 3), $m)) {
                    $c = chr(octdec($m[0]) & 0xFF);
                    $i += strlen($m[0]);
                } else {
                    $dopo = $pdf[$i] ?? '';
                    $c = ['n' => "\n", 'r' => "\r", 't' => "\t", "\n" => '', "\r" => ''][$dopo] ?? $dopo;
                    ++$i;
                }
            } elseif ($c === '(') {
                ++$livello;
            } elseif (--$livello === 0) {
                break;
            }
            if (strlen($s) < 500) {
                $s .= $c;
            }
        }

        return [$s, $i];
    }

    /** Il testo di una voce in UTF-8 (nei PDF può essere UTF-16 o di un byte per carattere). */
    private function testo(string $v): string
    {
        if (strncmp($v, "\xFE\xFF", 2) === 0) {
            $v = mb_convert_encoding(substr($v, 2), 'UTF-8', 'UTF-16BE');
        } elseif (strncmp($v, "\xEF\xBB\xBF", 3) === 0) {
            $v = substr($v, 3);
        }
        if (!mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
        }

        return mb_substr(trim($v), 0, 200);
    }
}
