<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Una richiesta HTTP, immutabile: metodo, percorso, parametri dell'indirizzo, dati del modulo, file caricati, intestazioni.
 *
 * Si costruisce una volta sola con daGlobali() (legge $_SERVER, $_GET, $_POST e $_FILES) oppure, nelle prove, con finta().
 * Non conosce la sessione né l'utente: chi ha bisogno di sapere «chi sei» lo chiede a parte (App\Auth\Sessione).
 */
final class Richiesta
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, mixed>  $file         come $_FILES
     * @param array<string, string> $intestazioni nome in minuscolo => valore
     * @param array<string, mixed>  $cookie       come $_COOKIE
     */
    private function __construct(
        private string $metodo,
        private string $percorso,
        private array $query,
        private array $post,
        private array $file,
        private array $intestazioni,
        private string $cartella = '',
        private array $cookie = [],
    ) {
    }

    /** La richiesta che sta arrivando al server: i superglobali si leggono qui e da nessun'altra parte. */
    public static function daGlobali(): self
    {
        $server = $_SERVER;
        $percorso = (string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return new self(
            strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            $percorso !== '' ? $percorso : '/',
            $_GET,
            $_POST,
            $_FILES,
            self::intestazioniDa($server),
            self::cartellaDi((string) ($server['SCRIPT_NAME'] ?? '')),
            $_COOKIE,
        );
    }

    /** La cartella dello script di ingresso («/sito» per «/sito/nucleo.php»); vuota se lo script sta alla radice del server. */
    private static function cartellaDi(string $scriptName): string
    {
        $d = str_replace('\\', '/', dirname($scriptName));

        return $d === '.' || $d === '/' ? '' : rtrim($d, '/');
    }

    /**
     * Una richiesta per le prove, senza toccare i superglobali.
     *
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, string> $cookie
     */
    public static function finta(string $metodo, string $percorso, array $query = [], array $post = [], string $cartella = '', array $cookie = []): self
    {
        return new self(strtoupper($metodo), $percorso, $query, $post, [], [], rtrim($cartella, '/'), $cookie);
    }

    /** GET, POST, HEAD… sempre in maiuscolo. */
    public function metodo(): string
    {
        return $this->metodo;
    }

    /** Solo il percorso dell'indirizzo, senza `?parametri`. */
    public function percorso(): string
    {
        return $this->percorso;
    }

    /**
     * Il percorso relativo alla cartella del sito, senza barre agli estremi («pannello/contatti» per «/sito/pannello/contatti»). La cartella è quella
     * dello script di ingresso (nucleo.php sta nella radice del sito): il sito può stare in una cartella qualunque o alla radice, e le rotte
     * non lo sanno.
     */
    public function percorsoNelSito(): string
    {
        $p = $this->percorso;
        if ($this->cartella !== '' && ($p === $this->cartella || str_starts_with($p, $this->cartella . '/'))) {
            $p = substr($p, strlen($this->cartella));
        }

        return trim($p, '/');
    }

    /** Un parametro dell'indirizzo (?nome=…); $default se manca. Può essere un array (?nome[]=…): chi lo usa lo controlla. */
    public function query(string $nome, mixed $default = null): mixed
    {
        return $this->query[$nome] ?? $default;
    }

    /**
     * Tutti i parametri dell'indirizzo (?a=1&b=2), per chi deve rifare lo stesso indirizzo (il ritorno dopo l'accesso).
     *
     * @return array<string, mixed>
     */
    public function parametri(): array
    {
        return $this->query;
    }

    /**
     * Tutti i dati del modulo inviato con POST, per chi li passa interi a un servizio che sa quali campi leggere.
     *
     * @return array<string, mixed>
     */
    public function datiModulo(): array
    {
        return $this->post;
    }

    /** Un dato del modulo inviato con POST; $default se manca. */
    public function post(string $nome, mixed $default = null): mixed
    {
        return $this->post[$nome] ?? $default;
    }

    /**
     * Un file caricato (la voce di $_FILES con quel nome: name, type, tmp_name, error, size), null se non c'è.
     *
     * @return array<string, mixed>|null
     */
    public function file(string $nome): ?array
    {
        $f = $this->file[$nome] ?? null;

        return is_array($f) ? $f : null;
    }

    /** Un cookie della richiesta; null se manca. */
    public function cookie(string $nome): ?string
    {
        $v = $this->cookie[$nome] ?? null;

        return is_string($v) ? $v : null;
    }

    /** Un'intestazione della richiesta (il nome non distingue maiuscole e minuscole), null se manca. */
    public function intestazione(string $nome): ?string
    {
        return $this->intestazioni[strtolower($nome)] ?? null;
    }

    public function eUnPost(): bool
    {
        return $this->metodo === 'POST';
    }

    /**
     * Le intestazioni stanno in $_SERVER come HTTP_NOME_DEL_CAMPO (più CONTENT_TYPE e CONTENT_LENGTH).
     *
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function intestazioniDa(array $server): array
    {
        $out = [];
        foreach ($server as $chiave => $valore) {
            if (!is_string($chiave) || !is_scalar($valore)) {
                continue;
            }
            if (str_starts_with($chiave, 'HTTP_')) {
                $out[strtolower(str_replace('_', '-', substr($chiave, 5)))] = (string) $valore;
            } elseif ($chiave === 'CONTENT_TYPE' || $chiave === 'CONTENT_LENGTH') {
                $out[strtolower(str_replace('_', '-', $chiave))] = (string) $valore;
            }
        }

        return $out;
    }
}
