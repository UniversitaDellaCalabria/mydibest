<?php

declare(strict_types=1);

namespace App\Portale;

/**
 * «Prossima cosa da fare»: dopo che una persona ha chiuso un compito (firma, conferma, caricamento) le propone subito il successivo, preso da «Da fare per te».
 * Sceglie quello con la scadenza più vicina e, a parità, quello che aspetta da più giorni; salta il compito della pagina in cui si trova. Le regole stanno in
 * scegli() (senza database), la lettura in perUtente().
 */
final class ProssimaCosa
{
    public function __construct(private DaFarePersona $daFare)
    {
    }

    /**
     * @param array<string, mixed>|null $utente
     * @param string $pagina indirizzo della pagina corrente (es. firma_pratica.php?t=xyz)
     * @return array{testo: string, dettaglio: string, link: string, gruppo: string, altre: int}|null
     */
    public function perUtente(?array $utente, string $pagina): ?array
    {
        try {
            return self::scegli($this->daFare->gruppi($utente), $pagina);
        } catch (\Throwable $e) {
            error_log('Prossima cosa da fare: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @param list<array{titolo: string, righe: list<array<string, mixed>>}> $gruppi risultato di DaFarePersona::gruppi()
     * @return array{testo: string, dettaglio: string, link: string, gruppo: string, altre: int}|null null se non resta niente da fare
     */
    public static function scegli(array $gruppi, string $pagina): ?array
    {
        $tutte = [];
        foreach ($gruppi as $g) {
            foreach ($g['righe'] as $r) {
                if (!self::stessaPagina((string) $r['link'], $pagina)) {
                    $tutte[] = $r + ['_gruppo' => (string) $g['titolo']];
                }
            }
        }
        if (!$tutte) {
            return null;
        }
        usort($tutte, static fn (array $a, array $b): int => [($a['scadenza'] ?? '9999-12-31'), -(int) $a['giorni']] <=> [($b['scadenza'] ?? '9999-12-31'), -(int) $b['giorni']]);
        $r = $tutte[0];

        return ['testo' => (string) $r['testo'], 'dettaglio' => (string) $r['dettaglio'], 'link' => (string) $r['link'], 'gruppo' => $r['_gruppo'], 'altre' => count($tutte) - 1, 'scadenza' => (string) ($r['scadenza'] ?? '')];
    }

    /**
     * La tessera «Il prossimo passo» in cima all'area personale: la cosa più urgente di «Da fare per te» (la scadenza più vicina, a parità quella che aspetta da più
     * giorni), con la sua scadenza a parole e il totale delle cose da fare (non solo quelle lette: il numero `n` di ogni gruppo è sempre il totale). null se non c'è niente.
     *
     * @param list<array{titolo: string, n?: int, righe: list<array<string, mixed>>}> $gruppi risultato di DaFarePersona::gruppi()
     * @param string $oggi data di oggi (Y-m-d)
     * @return array{testo: string, dettaglio: string, link: string, gruppo: string, scadenza: array{testo: string, urgente: bool}|null, totale: int}|null
     */
    public static function tessera(array $gruppi, string $oggi): ?array
    {
        $p = self::scegli($gruppi, '');
        if ($p === null) {
            return null;
        }
        $totale = 0;
        foreach ($gruppi as $g) {
            $totale += isset($g['n']) ? (int) $g['n'] : count($g['righe']);
        }

        return [
            'testo' => $p['testo'], 'dettaglio' => $p['dettaglio'], 'link' => $p['link'], 'gruppo' => $p['gruppo'],
            'scadenza' => DaFarePersona::scadenzaDi(['scadenza' => $p['scadenza']], $oggi), 'totale' => max($totale, $p['altre'] + 1),
        ];
    }

    /** Il link del compito è la pagina in cui ci si trova (stesso file e stessi parametri t/id/code). */
    private static function stessaPagina(string $link, string $pagina): bool
    {
        $a = parse_url($link);
        $b = parse_url($pagina);
        if (basename((string) ($a['path'] ?? '')) !== basename((string) ($b['path'] ?? ''))) {
            return false;
        }
        parse_str((string) ($a['query'] ?? ''), $qa);
        parse_str((string) ($b['query'] ?? ''), $qb);
        foreach (['t', 'id', 'code', 'token'] as $k) {
            if (($qa[$k] ?? null) !== ($qb[$k] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
