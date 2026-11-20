<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

/**
 * I limiti di caricamento dei file del server PHP (post_max_size, upload_max_filesize, max_file_uploads) messi a confronto con quello che serve alla
 * richiesta di pubblicazione: gli allegati e le immagini delle impostazioni (ImpostazioniPubblicazioni::fileMassimi, di partenza 10 + 10) da 10 MB l'uno. Serve a scrivere a schermo i limiti veri e a riconoscere un invio
 * più grande di quanto PHP accetta: in quel caso PHP svuota $_POST e $_FILES e la pagina, senza questo controllo, ripartirebbe vuota senza dire perché.
 * Prende i valori dal costruttore: si prova senza toccare la configurazione.
 */
final class LimitiCaricamento
{
    /** @param int $fileRichiesti quanti file può avere una richiesta (allegati + immagini, dalle impostazioni) */
    public function __construct(private string $postMax, private string $uploadMax, private int $maxFile, private int $fileRichiesti = Costanti::MAX_ALLEGATI + Costanti::MAX_IMMAGINI)
    {
    }

    /** @param int|null $fileRichiesti ImpostazioniPubblicazioni::fileMassimi() (null = i valori di partenza) */
    public static function dalServer(?int $fileRichiesti = null): self
    {
        return new self((string) ini_get('post_max_size'), (string) ini_get('upload_max_filesize'), (int) ini_get('max_file_uploads'), $fileRichiesti ?? Costanti::MAX_ALLEGATI + Costanti::MAX_IMMAGINI);
    }

    /** «40M», «512K», «1G», «2097152» in byte; 0 o negativo = nessun limite (PHP_INT_MAX). */
    public static function byte(string $ini): int
    {
        $ini = trim($ini);
        if ($ini === '' || !preg_match('/^(-?\d+)\s*([kmg]?)b?$/i', $ini, $m)) {
            return PHP_INT_MAX;
        }
        $n = (int) $m[1];
        if ($n <= 0) {
            return PHP_INT_MAX;
        }

        return $n * match (strtolower($m[2])) {
            'k' => 1024,
            'm' => 1048576,
            'g' => 1073741824,
            default => 1,
        };
    }

    /** Il massimo di un invio intero (tutti i file e i campi insieme), in byte. */
    public function invioMax(): int
    {
        return self::byte($this->postMax);
    }

    /** Il massimo di un file: il più basso tra il limite del portale (10 MB) e quello del server. */
    public function fileMax(): int
    {
        return min(AllegatiPubblicazioni::MAX_BYTE, self::byte($this->uploadMax));
    }

    /** Il massimo di un file scritto in parole, come lo vede chi carica («10 MB», «2 MB» se il server ne accetta meno): per i messaggi di errore. */
    public function fileMaxInParole(): string
    {
        return self::inMb($this->fileMax());
    }

    /**
     * Vero se la richiesta è un invio che ha superato post_max_size: PHP in quel caso lascia $_POST e $_FILES vuoti pur avendo ricevuto dei dati.
     *
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function invioTroppoGrande(array $server, array $post, array $files): bool
    {
        $lunghezza = (int) ($server['CONTENT_LENGTH'] ?? 0);

        return ($server['REQUEST_METHOD'] ?? '') === 'POST' && $lunghezza > $this->invioMax() && $post === [] && $files === [];
    }

    /** I limiti in parole per la pagina: «Ogni file al massimo 10 MB; in tutto al massimo 40 MB per invio.» */
    public function frase(): string
    {
        $f = self::inMb($this->fileMax());
        $t = $this->invioMax();

        return 'Ogni file può pesare al massimo ' . $f . ($t === PHP_INT_MAX ? '.' : '; in tutto, tra tutti i file di questa richiesta, al massimo ' . self::inMb($t) . '.');
    }

    /** Il messaggio per un invio troppo grande. */
    public function messaggioTroppoGrande(): string
    {
        return 'I file scelti sono troppo pesanti: in tutto non possono superare ' . self::inMb($this->invioMax()) . ' (e ogni file al massimo ' . self::inMb($this->fileMax())
            . '). Scegli file più leggeri e riprova: la richiesta non è stata inviata e i campi qui sotto sono vuoti perché il server ha scartato tutto.';
    }

    /**
     * Cose del server che non bastano alla richiesta (vuoto = tutto a posto): file da meno di 10 MB, invio da meno di 20 MB (due file da 10 MB),
     * meno file per invio di quanti ne può avere una richiesta (le impostazioni: di partenza 20).
     *
     * @return list<string>
     */
    public function problemi(): array
    {
        $p = [];
        if ($this->fileMax() < AllegatiPubblicazioni::MAX_BYTE) {
            $p[] = 'Il server accetta file da ' . self::inMb($this->fileMax()) . ' al massimo (upload_max_filesize): meno dei 10 MB previsti.';
        }
        if ($this->invioMax() < 2 * AllegatiPubblicazioni::MAX_BYTE) {
            $p[] = 'Il server accetta ' . self::inMb($this->invioMax()) . ' in tutto per ogni invio (post_max_size): meno di due file da 10 MB.';
        }
        if ($this->maxFile > 0 && $this->maxFile < $this->fileRichiesti) {
            $p[] = 'Il server accetta ' . $this->maxFile . ' file per invio (max_file_uploads): meno dei ' . $this->fileRichiesti . ' previsti.';
        }

        return $p;
    }

    /** «10 MB», «2,5 MB», «512 KB». */
    private static function inMb(int $byte): string
    {
        if ($byte >= 1048576) {
            $mb = $byte / 1048576;

            return (floor($mb) == $mb ? (string) (int) $mb : number_format($mb, 1, ',', '')) . ' MB';
        }

        return max(1, (int) round($byte / 1024)) . ' KB';
    }
}
