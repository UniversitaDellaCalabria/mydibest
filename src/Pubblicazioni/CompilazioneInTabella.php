<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

/**
 * La vista «Da compilare» delle richieste di pubblicazione: le righe del file Excel con le colonne degli operatori in una tabella sola, per completare
 * più celle di più richieste in una volta (il salvataggio è ServizioPubblicazioni::aggiornaOperatoriInBlocco). Qui ci sono solo le scelte «di vista»,
 * senza stato e senza database: quali colonne sono «mie» (il nome dell'utente tra gli «a cura di»), quali righe hanno celle vuote, come si divide in pagine.
 * «Cella vuota» vuol dire come per Completamento e per il conteggio nel database: nessun valore, o solo spazi.
 */
final class CompilazioneInTabella
{
    /** Quante righe per pagina. */
    public const PER_PAGINA = 50;

    /**
     * Le colonne degli operatori visibili il cui «a cura di» nomina l'utente («Nome Cognome» o «Cognome Nome», senza badare a maiuscole e accenti).
     * «a cura di» può nominare più persone («Marco Bocci o Emanuele Dodaro»). Vuoto se non c'è nessuna corrispondenza (o l'utente non ha nome e cognome).
     *
     * @param array<string, mixed> $utente la riga di utenti (nome, cognome)
     * @return list<string> chiavi di colonna, nell'ordine scelto per il file Excel
     */
    public static function colonneDi(array $utente, ConfigurazioneCampi $config): array
    {
        $nome = self::normalizza((string) ($utente['nome'] ?? ''));
        $cognome = self::normalizza((string) ($utente['cognome'] ?? ''));
        if ($nome === '' || $cognome === '') {
            return [];
        }
        $forme = [' ' . $nome . ' ' . $cognome . ' ', ' ' . $cognome . ' ' . $nome . ' '];
        $mie = [];
        foreach ($config->colonne() as $c) {
            if (!$c['operatori']) {
                continue;
            }
            $a_cura_di = ' ' . self::normalizza($c['a_cura_di']) . ' ';
            foreach ($forme as $f) {
                if (str_contains($a_cura_di, $f)) {
                    $mie[] = $c['chiave'];
                    break;
                }
            }
        }

        return $mie;
    }

    /**
     * Le righe con almeno una cella vuota tra le colonne indicate (le stesse regole di Completamento::mancanti).
     *
     * @param list<array<string, mixed>> $righe
     * @param list<string> $colonne
     * @return list<array<string, mixed>>
     */
    public static function conCelleVuote(array $righe, array $colonne): array
    {
        return array_values(array_filter($righe, static function (array $r) use ($colonne): bool {
            foreach ($colonne as $k) {
                if (trim((string) ($r[$k] ?? '')) === '') {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Una pagina dell'elenco (la pagina chiesta si porta dentro i limiti: oltre l'ultima si dà l'ultima).
     *
     * @param list<array<string, mixed>> $righe
     * @return array{righe: list<array<string, mixed>>, pagina: int, pagine: int, totale: int}
     */
    public static function pagina(array $righe, int $pagina, int $perPagina = self::PER_PAGINA): array
    {
        $perPagina = max(1, $perPagina);
        $totale = count($righe);
        $pagine = max(1, (int) ceil($totale / $perPagina));
        $pagina = max(1, min($pagine, $pagina));

        return ['righe' => array_slice($righe, ($pagina - 1) * $perPagina, $perPagina), 'pagina' => $pagina, 'pagine' => $pagine, 'totale' => $totale];
    }

    /** Minuscole, senza accenti, solo lettere e cifre separate da uno spazio: per confrontare i nomi. */
    private static function normalizza(string $testo): string
    {
        $testo = strtr(mb_strtolower($testo), ['à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'í' => 'i', 'ò' => 'o', 'ó' => 'o', 'ù' => 'u', 'ú' => 'u', 'ç' => 'c', 'ñ' => 'n']);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $testo));
    }
}
