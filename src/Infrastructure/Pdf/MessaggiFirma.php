<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

/**
 * I messaggi che spiegano perché un PDF firmato è stato rifiutato: la causa in italiano semplice e che cosa fare. Una sola fonte, così scuole, aziende
 * e docenti leggono le stesse parole. Testo semplice (chi lo mostra lo ripulisce); la guida «Scuole» sta in guida.php?r=scuola.
 */
final class MessaggiFirma
{
    public const GUIDA_SCUOLE = 'guida.php?r=scuola';

    public const VUOTO = 'vuoto';
    public const GRANDE = 'grande';
    public const NON_PDF = 'non_pdf';
    public const P7M = 'p7m';
    public const SENZA_FIRMA = 'senza_firma';
    public const NON_INTEGRA = 'non_integra';
    public const MODIFICATA = 'modificata';

    private const TESTI = [
        self::VUOTO => 'Il file è vuoto. Cosa fare: scegli di nuovo il PDF firmato e riprova.',
        self::GRANDE => 'Il file è troppo grande: il limite è %d MB. Cosa fare: riduci le immagini del documento prima della firma, poi firma e carica di nuovo.',
        self::NON_PDF => 'Il file non è un PDF. Cosa fare: carica il PDF firmato (il file termina con .pdf), non un documento Word o un\'immagine.',
        self::P7M => 'Il file non è un PDF: è una firma in formato CAdES (.p7m), che il portale non accetta. Cosa fare: firma di nuovo il PDF scegliendo il formato PAdES (nel programma di firma l\'opzione «PDF firmato») e carica il file .pdf che ne esce.',
        self::SENZA_FIRMA => 'Nel PDF non trovo una firma digitale PAdES: il file non risulta firmato. Cosa fare: fai firmare il PDF dal legale rappresentante (il Dirigente della scuola, il rappresentante dell\'azienda) in formato PAdES e carica il file così come esce dalla firma.',
        self::NON_INTEGRA => 'La firma digitale del PDF non è integra: il file è danneggiato o la firma si è interrotta. Cosa fare: ripeti la firma sul PDF originale e carica di nuovo il file.',
        self::MODIFICATA => 'Il PDF è stato modificato dopo la firma (salvato di nuovo, stampato in PDF o ritoccato), quindi la firma non copre più tutto il file. Cosa fare: ripeti la firma e carica il file così come è uscito dalla firma, senza riaprirlo.',
    ];

    /** Le cause che sulle convenzioni non bloccano: il testo per l'ufficio, che controlla prima di prendere in carico o protocollare. */
    private const AVVISI = [
        self::NON_INTEGRA => 'La struttura della firma non è integra: controlla la firma con gli strumenti di Ateneo.',
        self::MODIFICATA => 'La firma non copre tutto il file (il PDF è stato modificato o salvato di nuovo dopo la firma): controlla che il documento sia quello giusto.',
    ];

    /**
     * Gli avvisi per l'ufficio sulle firme trovate (vuoto = nessun problema).
     *
     * @param list<array<string, mixed>> $firme
     */
    public static function avvisi(array $firme): string
    {
        $avvisi = [];
        foreach ($firme as $f) {
            if (empty($f['valida_struttura'])) {
                $avvisi[] = self::AVVISI[self::NON_INTEGRA];
                break;
            }
        }
        if ($firme && empty($firme[array_key_last($firme)]['copre_tutto'])) {
            $avvisi[] = self::AVVISI[self::MODIFICATA];
        }

        return implode(' ', $avvisi);
    }

    /** L'avviso accorciato senza spezzare una parola (il campo del database tiene 500 caratteri). */
    public static function taglia(string $avviso, int $massimo = 500): string
    {
        if (mb_strlen($avviso) <= $massimo) {
            return $avviso;
        }
        $corto = mb_substr($avviso, 0, $massimo - 1);
        // se il taglio cade dentro una parola si torna allo spazio prima
        if (mb_substr($avviso, $massimo - 1, 1) !== ' ' && ($spazio = mb_strrpos($corto, ' ')) !== false) {
            $corto = mb_substr($corto, 0, $spazio);
        }

        return rtrim($corto, ' ') . '…';
    }

    /**
     * Le firme vere, senza le marche temporali (che da sole non sono una firma).
     *
     * @param list<array<string, mixed>> $firme
     * @return list<array<string, mixed>>
     */
    public static function senzaMarche(array $firme): array
    {
        return array_values(array_filter($firme, static fn (array $f): bool => empty($f['marca_temporale'])));
    }

    /**
     * Scrive nel log perché un PDF è stato rifiutato: la causa, i formati trovati e quante firme c'erano prima e dopo (mai il contenuto né dati personali).
     *
     * @param list<array<string, mixed>> $prima
     * @param list<array<string, mixed>> $dopo
     */
    public static function registraRifiuto(string $dove, string $causa, array $prima = [], array $dopo = []): void
    {
        $formati = array_map(static fn (array $f): string => (string) ($f['subfilter'] ?? '') ?: 'sconosciuto', array_slice($dopo, 0, 20));
        error_log("[firma rifiutata][{$dove}] motivo={$causa} subfilter=" . (implode(',', $formati) ?: 'nessuno') . ' firme_prima=' . count($prima) . ' firme_dopo=' . count($dopo));
    }

    /** Rifiuto al caricamento: scrive il motivo nel log e ritorna il messaggio da mostrare. @param list<array<string, mixed>> $firme */
    public static function rifiuta(string $dove, string $causa, array $firme = [], int $limiteMb = 10): string
    {
        self::registraRifiuto($dove, $causa, [], $firme);

        return self::per($causa, $limiteMb);
    }

    public static function per(string $causa, int $limiteMb = 10): string
    {
        return sprintf(self::TESTI[$causa] ?? self::TESTI[self::NON_PDF], $limiteMb);
    }

    /** Il contenuto non è un PDF: se somiglia a una firma CAdES (.p7m, DER o PEM) lo dice, altrimenti il messaggio generico. */
    public static function nonPdf(string $contenuto): string
    {
        return self::per(self::causaNonPdf($contenuto));
    }

    /** Perché il contenuto non è un PDF: P7M o NON_PDF. */
    public static function causaNonPdf(string $contenuto): string
    {
        $p7m = ($contenuto !== '' && $contenuto[0] === "\x30" && strlen($contenuto) > 4 && ($contenuto[1] === "\x82" || $contenuto[1] === "\x80" || $contenuto[1] === "\x83"))
            || str_contains(substr($contenuto, 0, 200), '-----BEGIN PKCS7') || str_contains(substr($contenuto, 0, 200), '-----BEGIN CMS');

        return $p7m ? self::P7M : self::NON_PDF;
    }
}
