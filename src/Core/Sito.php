<?php

declare(strict_types=1);

namespace App\Core;

/** Indirizzo pubblico e cartella del portale (per i link nelle email, nei PDF e nei calendari). */
final class Sito
{
    private bool $cli;

    public const HOST_PREDEFINITO = 'dibest2.unical.it';
    public const HOST_AMMESSI_PREDEFINITI = 'dibest2.unical.it,localhost,127.0.0.1';

    /**
     * $hostAmmessi: valore di HOST_AMMESSI nel .env (nomi separati da virgole; vuoto = predefiniti).
     * $cli solo per le prove: di norma si ricava da PHP_SAPI.
     */
    public function __construct(private string $radice, private ?string $urlSito = null, ?bool $cli = null, private ?string $hostAmmessi = null)
    {
        $this->cli = $cli ?? (PHP_SAPI === 'cli');
    }

    public function radice(): string
    {
        return $this->radice;
    }

    /**
     * Indirizzo del portale senza "/" finale, es. https://dibest2.unical.it/mydibest.
     * Se nel .env c'è URL_SITO valido si usa SEMPRE (dal web e da riga di comando). Altrimenti si usa il nome
     * della richiesta (HTTP_HOST, che manda chi fa la richiesta) solo se è tra i nomi ammessi (HOST_AMMESSI),
     * se no https://dibest2.unical.it. Spostato da url_base_sito() (inc/base.php), che resta come facciata.
     */
    public function urlBase(): string
    {
        $cli = $this->cli;
        if (preg_match('#^https?://[^/]+#i', (string) $this->urlSito)) {
            return rtrim((string) $this->urlSito, '/');
        }
        $proto = ($cli || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? 'https://' : 'http://';
        $host = $this->hostAmmesso((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === null) {
            $host = self::HOST_PREDEFINITO;
            $proto = 'https://';
        }
        $docRoot = (!$cli && !empty($_SERVER['DOCUMENT_ROOT'])) ? (string) realpath($_SERVER['DOCUMENT_ROOT']) : '';
        $radice = (string) realpath($this->radice);
        if ($docRoot !== '' && strpos($radice, $docRoot) === 0) {
            $rel = str_replace('\\', '/', substr($radice, strlen($docRoot)));
        } else {
            $rel = $cli ? null : $this->cartellaDaScript($radice);
            $rel ??= '/mydibest';
        }

        return $proto . $host . rtrim($rel, '/');
    }

    /** Nome della richiesta (con l'eventuale porta) se il suo nome senza porta è tra HOST_AMMESSI, altrimenti null. */
    private function hostAmmesso(string $richiesto): ?string
    {
        $richiesto = trim($richiesto);
        if ($richiesto === '' || !preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(:\d{1,5})?$/i', $richiesto, $m)) {
            return null;
        }
        $nome = strtolower($m[1]);
        $elenco = trim((string) $this->hostAmmessi) !== '' ? (string) $this->hostAmmessi : self::HOST_AMMESSI_PREDEFINITI;
        $ammessi = array_filter(array_map(static fn (string $h): string => strtolower(trim($h)), explode(',', $elenco)), static fn (string $h): bool => $h !== '');

        return in_array($nome, $ammessi, true) ? $richiesto : null;
    }

    /**
     * Radice fuori dalla document root (cartella collegata, copia locale): ricava la cartella dal percorso
     * della pagina in corso. Es. SCRIPT_NAME /mydibest/admin/eventi.php e file <radice>/admin/eventi.php → /mydibest.
     */
    private function cartellaDaScript(string $radice): ?string
    {
        $nome = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $file = (string) realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if ($nome === '' || $file === '' || $radice === '') {
            return null;
        }
        $radice = rtrim(str_replace('\\', '/', $radice), '/');
        $file = str_replace('\\', '/', $file);
        if (strpos($file, $radice . '/') !== 0) {
            return null;
        }
        $coda = substr($file, strlen($radice)); // es. /admin/eventi.php
        if (strlen($nome) < strlen($coda) || substr($nome, -strlen($coda)) !== $coda) {
            return null;
        }

        return substr($nome, 0, strlen($nome) - strlen($coda));
    }
}
