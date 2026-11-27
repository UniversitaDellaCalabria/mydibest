<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

/**
 * La vista «Da compilare» delle richieste di pubblicazione, nelle due forme: a schede (una per richiesta, «Da fare» e «Fatte») e «Tabella completa» (le
 * righe del file Excel con le colonne degli operatori in una tabella sola, per completare più celle di più richieste in una volta). Il salvataggio è per
 * entrambe ServizioPubblicazioni::aggiornaOperatoriInBlocco. Qui ci sono solo le scelte «di vista», senza stato e senza database: quali colonne sono «mie»
 * (il nome dell'utente tra gli «a cura di»), quali righe hanno celle vuote, quali schede sono fatte, come si divide in pagine.
 * «Cella vuota» vuol dire come per Completamento e per il conteggio nel database: nessun valore, o solo spazi.
 */
final class CompilazioneInTabella
{
    /** Quante righe per pagina. */
    public const PER_PAGINA = 50;

    /** Quante schede per pagina (la vista a schede). */
    public const SCHEDE_PER_PAGINA = 20;

    /**
     * Le colonne degli operatori visibili il cui «a cura di» nomina l'utente («Nome Cognome» o «Cognome Nome», senza badare a maiuscole e accenti).
     * «a cura di» può nominare più persone («Marco Bocci o Emanuele Dodaro»). Vuoto se non c'è nessuna corrispondenza (o l'utente non ha nome e cognome).
     *
     * @param array<string, mixed> $utente la riga di utenti (nome, cognome)
     * @return list<string> chiavi di colonna, nell'ordine scelto per il file Excel
     */
    public static function colonneDi(array $utente, ConfigurazioneCampi $config): array
    {
        $mie = [];
        foreach ($config->colonne() as $c) {
            if ($c['operatori'] && self::nomina($c['a_cura_di'], $utente)) {
                $mie[] = $c['chiave'];
            }
        }

        return $mie;
    }

    /**
     * Se un «a cura di» nomina l'utente (la regola di colonneDi, usata anche da «Proponi dai nomi» di ColonneDiChi). False se l'utente non ha nome e cognome.
     *
     * @param array<string, mixed> $utente (nome, cognome)
     */
    public static function nomina(string $aCuraDi, array $utente): bool
    {
        $nome = self::normalizza((string) ($utente['nome'] ?? ''));
        $cognome = self::normalizza((string) ($utente['cognome'] ?? ''));
        if ($nome === '' || $cognome === '') {
            return false;
        }
        $a_cura_di = ' ' . self::normalizza($aCuraDi) . ' ';

        return str_contains($a_cura_di, ' ' . $nome . ' ' . $cognome . ' ') || str_contains($a_cura_di, ' ' . $cognome . ' ' . $nome . ' ');
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

    /** Quante delle colonne indicate sono compilate nella riga (il «2 di 4 compilati» delle schede; stessa idea di cella vuota). @param array<string, mixed> $riga @param list<string> $colonne */
    public static function compilate(array $riga, array $colonne): int
    {
        return count(array_filter($colonne, static fn (string $k): bool => trim((string) ($riga[$k] ?? '')) !== ''));
    }

    /**
     * La vista a schede: le righe divise in «da fare» (almeno una delle colonne indicate è vuota) e «fatte» (tutte compilate). In tutti e due gli elenchi
     * l'evento più vicino sta in cima: prima quelli da oggi in poi, dal più vicino, poi quelli passati, dal più recente. Senza colonne non c'è niente da
     * compilare né di compilato: i due elenchi sono vuoti.
     *
     * @param list<array<string, mixed>> $righe righe del file Excel (con id e data_evento)
     * @param list<string> $colonne le colonne che chi guarda compila
     * @param string $oggi AAAA-MM-GG
     * @return array{da_fare: list<array<string, mixed>>, fatte: list<array<string, mixed>>}
     */
    public static function dividi(array $righe, array $colonne, string $oggi): array
    {
        $out = ['da_fare' => [], 'fatte' => []];
        if ($colonne === []) {
            return $out;
        }
        usort($righe, static function (array $a, array $b) use ($oggi): int {
            $da = substr((string) ($a['data_evento'] ?? ''), 0, 10);
            $db = substr((string) ($b['data_evento'] ?? ''), 0, 10);
            $passato_a = $da < $oggi;
            $passato_b = $db < $oggi;
            if ($passato_a !== $passato_b) {
                return $passato_a <=> $passato_b;
            }

            return ($passato_a ? $db <=> $da : $da <=> $db) ?: (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
        });
        foreach ($righe as $r) {
            $out[self::compilate($r, $colonne) === count($colonne) ? 'fatte' : 'da_fare'][] = $r;
        }

        return $out;
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
