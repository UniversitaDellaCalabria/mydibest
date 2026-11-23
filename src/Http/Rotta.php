<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;

/**
 * Una riga della tabella delle rotte: quale pagina risponde a quale indirizzo. NON porta con sé nessun permesso: chi può aprirla lo dice la
 * fonte unica del portale, e la rotta indica solo da quale file viene (`file`), perché è con quel nome che la fonte la conosce. Due elenchi
 * che dicono la stessa cosa divergono in un mese.
 *
 * - `percorso`: l'indirizzo della rotta, relativo al sito, senza barra iniziale né estensione («pannello/contatti»).
 * - `pagina`: il nome della classe (implementa {@see Pagina}).
 * - `titolo`: come si chiama la pagina (registro degli errori, mappa degli accessi).
 * - `file`: il file di oggi da cui la pagina viene, relativo al sito («cartella/pagina.php»). Quel file resta al suo posto come guscio di
 *   due righe, quindi il suo indirizzo non muore mai.
 * - `alias`: gli altri indirizzi che portano alla stessa pagina, quelli dei file vecchi compresi (con e senza «.php»).
 *
 * La rotta non sa a quale parte del portale appartiene: chi decide l'accesso (il ControlloAccesso del portale) guarda il file da sé.
 */
final class Rotta
{
    /** Quanto deve essere lungo, almeno, il motivo di una rotta senza controllo del token: una parola non spiega niente. */
    public const MOTIVO_MINIMO = 30;

    /** @var list<string> */
    public readonly array $alias;

    /**
     * `senzaCsrf`: il Nucleo verifica il token di ogni POST prima di costruire la pagina. Una rotta può farne a meno solo scrivendo QUI il
     * motivo (un webhook di un servizio esterno, che non ha la sessione e si autentica con una firma): per questo non è un booleano. Null =
     * il controllo c'è, ed è il caso di ogni rotta di oggi (una prova lo verifica).
     *
     * @param class-string<Pagina> $pagina
     * @param list<string>         $alias
     */
    public function __construct(
        public readonly string $percorso,
        public readonly string $pagina,
        public readonly string $titolo,
        public readonly string $file,
        array $alias = [],
        public readonly ?string $senzaCsrf = null,
    ) {
        if ($senzaCsrf !== null && mb_strlen(trim($senzaCsrf)) < self::MOTIVO_MINIMO) {
            throw new InvalidArgumentException("La rotta «{$percorso}» rinuncia al controllo del token: il motivo va scritto per esteso (almeno " . self::MOTIVO_MINIMO . ' caratteri)');
        }
        if ($percorso === '' || self::normalizza($percorso) !== $percorso) {
            throw new InvalidArgumentException("Percorso di rotta non valido: «{$percorso}» (senza barra iniziale o finale, senza «..»)");
        }
        if ($file === '' || str_starts_with($file, '/') || str_contains($file, '..')) {
            throw new InvalidArgumentException("File di rotta non valido: «{$file}» (relativo al sito)");
        }
        $this->alias = array_values(array_unique(array_filter(array_map(self::normalizza(...), $alias), static fn (string $a): bool => $a !== "")));
    }

    /**
     * Tutti gli indirizzi a cui risponde: il percorso, gli alias e il file (con e senza «.php»).
     *
     * @return list<string>
     */
    public function indirizzi(): array
    {
        $file = self::normalizza($this->file);
        $senza = str_ends_with($file, '.php') ? substr($file, 0, -4) : $file;

        return array_values(array_unique(array_merge([$this->percorso, $file, $senza], $this->alias)));
    }

    /** Un percorso nella forma in cui si confronta: niente barre agli estremi, niente parametri, una sola barra fra i pezzi. */
    public static function normalizza(string $percorso): string
    {
        $percorso = (string) strstr($percorso . '?', '?', true);
        $percorso = preg_replace('~/+~', '/', trim($percorso, '/'));

        return str_contains((string) $percorso, '..') ? '' : (string) $percorso;
    }
}
