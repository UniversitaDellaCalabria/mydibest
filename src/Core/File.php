<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Operazioni sui file che prima si facevano con la @ davanti: qui si controlla prima (is_file, is_dir)
 * e, se l'operazione fallisce davvero, il motivo finisce nel registro degli errori del server (error_log)
 * invece di sparire. Nessuna eccezione: chi chiama riceve true/false e decide.
 */
final class File
{
    /** Toglie un file. Un file che non c'è già non è un errore. */
    public static function togli(string $percorso): bool
    {
        if (!is_file($percorso) && !is_link($percorso)) {
            return true;
        }
        if (unlink($percorso)) {
            return true;
        }
        error_log('[File] non riesco a cancellare ' . $percorso);

        return false;
    }

    /** Crea una cartella (e quelle intermedie) se non esiste. */
    public static function creaCartella(string $percorso, int $permessi = 0755): bool
    {
        if (is_dir($percorso)) {
            return true;
        }
        if (mkdir($percorso, $permessi, true) || is_dir($percorso)) {
            return true;
        }
        error_log('[File] non riesco a creare la cartella ' . $percorso);

        return false;
    }

    /** Scrive un file (sovrascrive). false se la cartella non c'è o non si può scrivere. */
    public static function scrivi(string $percorso, string $contenuto, int $flag = 0): bool
    {
        $cartella = dirname($percorso);
        if (!is_dir($cartella) || (!is_writable($cartella) && !is_file($percorso))) {
            error_log('[File] cartella non scrivibile per ' . $percorso);

            return false;
        }
        if (file_put_contents($percorso, $contenuto, $flag) === false) {
            error_log('[File] non riesco a scrivere ' . $percorso);

            return false;
        }

        return true;
    }

    /** Data di ultima modifica, 0 se il file non c'è (serve ai numeri di versione dei CSS e JS). */
    public static function modificato(string $percorso): int
    {
        if (!is_file($percorso)) {
            return 0;
        }
        $t = filemtime($percorso);

        return $t === false ? 0 : $t;
    }

    /**
     * Legge il testo di un file .ini (o .env). Una riga malformata fa avvisare parse_ini_string: l'avviso va nel registro
     * degli errori e la funzione risponde con un elenco vuoto, così chi chiama ha sempre un array.
     *
     * @return array<string, mixed>
     */
    public static function leggiIni(string $testo, bool $grezzo = false): array
    {
        set_error_handler(static function (int $n, string $messaggio): bool {
            error_log('[File] file ini non valido: ' . $messaggio);

            return true;
        });
        try {
            $v = parse_ini_string($testo, false, $grezzo ? INI_SCANNER_RAW : INI_SCANNER_NORMAL);
        } finally {
            restore_error_handler();
        }

        return is_array($v) ? $v : [];
    }
}
