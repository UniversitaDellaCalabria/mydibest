<?php

declare(strict_types=1);

namespace App\Protocollo;

use App\Infrastructure\Pdf\SegnaturaPdf;

/**
 * La stampigliatura di protocollo come quella di Titulus (fascia rossa in testa alla prima pagina: «AOO Prot. n. 0001052 del 15/11/2026»),
 * uguale per tutte le pratiche: lettere di tutorato, convenzioni, attestati, domande. Si mette su una COPIA del PDF dopo il protocollo:
 * l'originale (quello firmato e spedito a Titulus) non si tocca.
 */
final class Stampigliatura
{
    /**
     * Il testo: dal numero di Titulus «2026-UCALPRG-0001052» ricava «UCALPRG Prot. n. 0001052»; un numero scritto a mano resta com'è («Prot. n. 123/2026»).
     *
     * @param string|null $dataIso data del protocollo (aaaa-mm-gg)
     */
    public static function testo(string $numero, ?string $dataIso): string
    {
        $numero = trim($numero);
        if ($numero === '') {
            return '';
        }
        $n = preg_match('/^\d{4}-([A-Za-z0-9]+)-(\d+)$/', $numero, $m) ? $m[1] . ' Prot. n. ' . $m[2] : 'Prot. n. ' . $numero;
        $d = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $dataIso) ? ' del ' . date('d/m/Y', (int) strtotime((string) $dataIso)) : '';

        return $n . $d;
    }

    /**
     * Il PDF con la stampigliatura in testa alla prima pagina, null se non si può (file cifrato, pagina ruotata, struttura non letta, numero vuoto):
     * chi chiama usa allora il PDF di prima.
     */
    public static function sulPdf(string $pdf, string $numero, ?string $dataIso): ?string
    {
        $testo = self::testo($numero, $dataIso);

        return $testo === '' ? null : (new SegnaturaPdf())->applica($pdf, $testo);
    }
}
