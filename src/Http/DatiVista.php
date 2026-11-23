<?php

declare(strict_types=1);

namespace App\Http;

use LogicException;
use OutOfBoundsException;

/**
 * I dati di un modello (viste/…), cioè l'oggetto `$d` che il modello riceve al posto di variabili sciolte.
 *
 *   $d->nome            il valore GIÀ passato da htmlspecialchars (testo e attributi tra virgolette): dimenticare l'escape è impossibile
 *   $d->grezzo('nome')  il valore com'è, per l'HTML che è già sicuro (il risultato di un altro modello, un campo CSRF, un QR): scavalcare
 *                       l'escape resta possibile, ma si vede nel diff perché si chiama «grezzo»
 *   $d->elenco('x')     una lista i cui elementi si comportano allo stesso modo (record → DatiVista, testo → testo già escapato)
 *   $d->parziale(…)     il risultato di un altro modello, da stampare così com'è
 *
 * Regole che tengono onesto il disegno:
 *  - un nome che la pagina non ha passato è un errore (OutOfBoundsException), non una stringa vuota: un refuso non passa in silenzio;
 *    `isset($d->nome)` e `$d->nome ?? 'x'` funzionano e dicono «c'è e non è null»;
 *  - un array NON si legge con `$d->x`: un record sì (si scende con `$d->x->campo`), una lista no (si usa elenco()); un oggetto no (grezzo());
 *  - le CHIAVI di un elenco non si esportano (elenco() restituisce una lista): le chiavi dei dati non sono escapate, e non devono finire
 *    in un attributo. Una mappa si passa come lista di record;
 *  - `htmlspecialchars` qui vale per il testo e per gli attributi tra virgolette: NON per un contesto JavaScript, per un indirizzo
 *    (`javascript:`) né per un attributo senza virgolette. Lì si prepara il valore nella pagina e si passa grezzo, con la propria regola.
 *
 * Immutabile.
 */
final class DatiVista
{
    /** @param array<string|int, mixed> $dati */
    private function __construct(private readonly array $dati)
    {
    }

    /** @param array<string|int, mixed> $dati */
    public static function da(array $dati): self
    {
        return new self($dati);
    }

    /** Lo stesso testo che produceva la lambda `$h` delle pagine (ENT_QUOTES, UTF-8), con i byte non validi sostituiti invece di svuotare il campo. */
    public static function escapa(mixed $valore): string
    {
        return htmlspecialchars((string) $valore, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function __get(string $nome): string|self
    {
        $valore = $this->prendi($nome);
        if ($valore instanceof self) {
            return $valore; // un record già avvolto (passato a un parziale con parziale())
        }
        if (is_array($valore)) {
            if ($valore === [] || array_is_list($valore)) {
                throw new LogicException("«{$nome}» è una lista: usa \$d->elenco('$nome') (un elemento alla volta, già con l'escape)");
            }

            return new self($valore);
        }

        return $this->comeTesto($nome, $valore);
    }

    public function __isset(string $nome): bool
    {
        return isset($this->dati[$nome]);
    }

    public function __set(string $nome, mixed $valore): void
    {
        throw new LogicException('I dati di una vista non si modificano: «' . $nome . '» va preparato nella pagina.');
    }

    public function __unset(string $nome): void
    {
        throw new LogicException('I dati di una vista non si modificano: «' . $nome . '» va preparato nella pagina.');
    }

    /** Il valore com'è. Solo per HTML già sicuro o per un valore che si tratta con una regola propria (un indirizzo, un numero). */
    public function grezzo(string $nome): mixed
    {
        return $this->prendi($nome);
    }

    /**
     * Gli elementi di una lista: un record diventa un DatiVista, un testo diventa testo già escapato. Le chiavi si perdono (vedi la nota
     * della classe). Un valore che non è una lista (null compreso) è una lista vuota solo se è null: un testo qui è un errore.
     *
     * @return list<self|string>
     */
    public function elenco(string $nome): array
    {
        $valore = $this->prendi($nome);
        if ($valore === null) {
            return [];
        }
        if (!is_array($valore)) {
            throw new LogicException("«{$nome}» non è una lista: usa \$d->$nome");
        }
        $out = [];
        foreach ($valore as $voce) {
            $out[] = $voce instanceof self ? $voce : (is_array($voce) ? new self($voce) : $this->comeTesto($nome, $voce));
        }

        return $out;
    }

    /**
     * Il risultato di un altro modello (una parte della pagina usata più volte), da stampare così com'è: `echo $d->parziale('cartella/nome', $c)`.
     * Ai dati si può passare un array oppure un record di questa vista (`$c` preso da elenco()): il parziale riceve di nuovo un `$d` suo.
     *
     * @param array<string|int, mixed>|self $dati
     */
    public function parziale(string $modello, array|self $dati = []): string
    {
        return Vista::rendi($modello, $dati instanceof self ? $dati->dati : $dati);
    }

    private function prendi(string $nome): mixed
    {
        if (!array_key_exists($nome, $this->dati)) {
            throw new OutOfBoundsException("La vista usa «{$nome}» ma la pagina non l'ha passato (passati: " . implode(', ', array_map('strval', array_keys($this->dati))) . ')');
        }

        return $this->dati[$nome];
    }

    private function comeTesto(string $nome, mixed $valore): string
    {
        if ($valore === null || $valore === false) {
            return '';
        }
        if (is_string($valore) || is_int($valore) || is_float($valore) || is_bool($valore)) {
            return self::escapa($valore);
        }

        throw new LogicException("«{$nome}» è un oggetto: preparalo nella pagina come testo, oppure usa \$d->grezzo('$nome') se è HTML già sicuro");
    }
}
