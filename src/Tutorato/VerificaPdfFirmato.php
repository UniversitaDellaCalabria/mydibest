<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Infrastructure\Pdf\MessaggiFirma;
use App\Infrastructure\Pdf\VerificaFirme;

/**
 * Controllo dei PDF firmati in PAdES: ogni firma si aggiunge alla precedente senza toccarla (il file firmato deve cominciare
 * esattamente con la versione precedente) e i .p7m (CAdES) sono rifiutati. Sulla firma del Direttore delle convenzioni il controllo è più largo:
 * vedi $severo in verifica().
 */
final class VerificaPdfFirmato
{
    /** I formati di firma PAdES riconosciuti. */
    public const FORMATI = ['ETSI.CAdES.detached', 'adbe.pkcs7.detached'];

    public function __construct(private VerificaFirme $firme)
    {
    }

    /**
     * Verifica crittografica dell'ultima firma del PDF (integrità del documento firmato) e dati del firmatario dal certificato:
     * ['integra' => true|false|null (null = controllo non disponibile), 'nome' => CN, 'cf' => codice fiscale (serialNumber TINIT-…), 'emittente' => …]
     * La catena dei certificati (elenco dei certificatori qualificati) la verifica chi protocolla, con gli strumenti di Ateneo.
     *
     * @return array{integra: bool|null, nome: string, cf: string, emittente: string}
     */
    public function analizza(string $pdf): array
    {
        $out = ['integra' => null, 'nome' => '', 'cf' => '', 'emittente' => ''];
        $intervallo = $this->ultimaFirma($pdf);
        if ($intervallo === null) {
            return $out;
        }
        [$a, $b, $c, $d] = $intervallo;
        if ($c <= $a + $b) {
            return $out;
        }
        $der = @hex2bin((string) preg_replace('/[^0-9A-Fa-f]/', '', substr($pdf, $a + $b, $c - $a - $b)));
        if (!$der || strlen($der) < 4 || ord($der[0]) !== 0x30) {
            return $out;
        }
        // Lunghezza DER: il contenuto è riempito di zeri fino alla dimensione riservata
        $len = ord($der[1]);
        $hl = 2;
        if ($len & 0x80) {
            $n = $len & 0x7f;
            $len = 0;
            for ($k = 0; $k < $n; $k++) {
                $len = ($len << 8) | ord($der[2 + $k]);
            } $hl = 2 + $n;
        }
        $der = substr($der, 0, $hl + $len);
        if (!function_exists('openssl_cms_verify')) {
            return $out;
        }
        $fd = tempnam(sys_get_temp_dir(), 'pd');
        $fs = tempnam(sys_get_temp_dir(), 'ps');
        $fc = tempnam(sys_get_temp_dir(), 'pc');
        file_put_contents($fd, substr($pdf, $a, $b) . substr($pdf, $c, $d));
        file_put_contents($fs, $der);
        $out['integra'] = @openssl_cms_verify($fd, OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED | OPENSSL_CMS_NOVERIFY, $fc, [], null, null, null, $fs, OPENSSL_ENCODING_DER) === true;
        while (openssl_error_string() !== false) {
        }
        $pem = (string)@file_get_contents($fc);
        if ($pem !== '' && preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $cc)) {
            foreach ($cc[0] as $cert) {
                $x = @openssl_x509_parse($cert);
                if (!$x) {
                    continue;
                }
                // Il certificato di firma (non quello di una CA): con codice fiscale o il primo
                $sn = (string)($x['subject']['serialNumber'] ?? '');
                $out['nome'] = (string)(is_array($x['subject']['CN'] ?? null) ? end($x['subject']['CN']) : ($x['subject']['CN'] ?? ''));
                $out['cf'] = preg_match('/^(?:TINIT-)?([A-Z0-9]{16})$/i', $sn, $q) ? strtoupper($q[1]) : '';
                $out['emittente'] = (string)($x['issuer']['O'] ?? ($x['issuer']['CN'] ?? ''));
                if ($out['cf'] !== '') {
                    break;
                }
            }
        }
        @unlink($fd);
        @unlink($fs);
        @unlink($fc);
        return $out;
    }

    /**
     * I 4 numeri del /ByteRange dell'ultima firma (non di una marca temporale, che non ha un firmatario); null se non c'è.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    private function ultimaFirma(string $pdf): ?array
    {
        $firme = MessaggiFirma::senzaMarche($this->firme->firmePades($pdf));
        if (!$firme) {
            return null;
        }
        $ultima = $firme[array_key_last($firme)];
        if (isset($ultima['intervallo'])) {
            return $ultima['intervallo'];
        }
        // Se chi conta le firme non dice dove sono si prende l'ultimo /ByteRange del file
        $pos = strrpos($pdf, '/ByteRange');
        if ($pos === false || !preg_match('#\A\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]#', substr($pdf, $pos + 10, 400), $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
    }

    /**
     * Controlla il PDF firmato rispetto alla versione precedente: deve essere un PDF (non un .p7m CAdES) e avere almeno una firma PAdES in più.
     * Una marca temporale da sola non è una firma nuova; più firme nuove, o firma e marca, vanno bene.
     * $severo (tutti i moduli): deve anche cominciare esattamente con la versione precedente, le firme nuove devono essere in un formato
     * riconosciuto e l'ultima deve coprire tutto il file. Con $severo = false (SOLO la firma del Direttore sulle convenzioni FSL e di tesi)
     * questi controlli non bloccano: finiscono in $info['avviso'], che l'ufficio legge accanto al PDF.
     * Il certificato non blocca mai: integrità e codice fiscale del firmatario, se non tornano, finiscono solo in $info['avviso'].
     * Ritorna [errore | null, dati della firma].
     *
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    public function verifica(string $prima, string $dopo, string $cfAtteso = '', bool $severo = true): array
    {
        $f0 = $this->firme->firmePades($prima);
        $f1 = $this->firme->firmePades($dopo);
        $no = static function (string $causa, string $messaggio) use ($f0, $f1, $severo): array {
            MessaggiFirma::registraRifiuto($severo ? 'verifica severa' : 'firma del Direttore su convenzione', $causa, $f0, $f1);

            return [$messaggio, []];
        };
        if (strncmp($dopo, '%PDF-', 5) !== 0) {
            return $no('non_pdf_o_p7m', "Il file non è un PDF: la firma deve essere PAdES (PDF firmato), non CAdES (.p7m).");
        }
        $riscritto = strlen($dopo) <= strlen($prima) || strncmp($dopo, $prima, strlen($prima)) !== 0;
        if ($severo && $riscritto) {
            return $no('byte_di_prima', "Il PDF firmato non corrisponde alla lettera: deve essere la stessa lettera scaricata dal portale, con la firma aggiunta (senza modifiche o nuovi salvataggi).");
        }
        $vere0 = MessaggiFirma::senzaMarche($f0);
        $vere1 = MessaggiFirma::senzaMarche($f1);
        if (!$severo && !$vere1) {
            return $no('nessuna_firma', "Nel PDF non c'è nessuna firma digitale PAdES.");
        }
        if (count($vere1) <= count($vere0)) {
            return $no('nessuna_firma_nuova', "Nel PDF non c'è una nuova firma digitale PAdES.");
        }
        $avvisi = [];
        if ($riscritto) {
            $avvisi[] = 'Il file è stato riscritto dal programma di firma: controlla che sia lo stesso documento.';
        }
        $nuove = array_slice($vere1, count($vere0));
        foreach ($nuove as $nuova) {
            if (in_array($nuova['subfilter'], self::FORMATI, true)) {
                continue;
            }
            $formato = (string) $nuova['subfilter'];
            if ($severo) {
                return $no('formato', "La firma non è nel formato PAdES (" . ($formato ?: 'formato sconosciuto') . ").");
            }
            $avvisi[] = 'Il formato della firma non è tra quelli riconosciuti (' . ($formato ?: 'sconosciuto') . ').';
        }
        if ($severo) {
            // Qui il file comincia con quello di prima: le voci in più (firme e marche) sono quelle aggiunte in coda
            if (MessaggiFirma::avvisi(array_slice($f1, count($f0))) !== '') {
                return $no('copertura', "La firma non copre tutto il documento.");
            }
        } else {
            array_unshift($avvisi, MessaggiFirma::avvisi($f1));
            if (count($nuove) > 1) {
                $avvisi[] = 'Nel file ci sono ' . count($nuove) . ' firme nuove invece di una: controlla chi ha firmato.';
            }
        }
        // Controllo minimo: la firma PAdES c'è ed è fatta bene nella struttura. Se il certificato dà problemi (non verificabile,
        // algoritmo non letto da OpenSSL, firmatario diverso da quello atteso) il file passa lo stesso: l'esito resta in 'avviso'
        // e la verifica della catena dei certificatori la fa chi protocolla.
        $info = $this->analizza($dopo);
        // L'ordine conta: se il testo si accorcia si perde la fine. Prima il certificato di un'altra persona, poi l'integrità, poi il resto.
        $primi = [];
        $cfAtteso = strtoupper(trim($cfAtteso));
        if ($cfAtteso !== '' && $info['cf'] !== '' && $info['cf'] !== $cfAtteso) {
            $primi[] = 'Il certificato è di ' . (mb_substr($info['nome'], 0, 100) ?: 'un\'altra persona') . ' (codice fiscale ' . $info['cf'] . '), diverso da chi doveva firmare.';
        }
        if ($info['integra'] === false) {
            $primi[] = 'Il controllo automatico del certificato non è riuscito: verifica la firma con gli strumenti di Ateneo.';
        }
        $info['avviso'] = implode(' ', array_unique(array_filter([...$primi, ...$avvisi])));

        return [null, $info];
    }
}
