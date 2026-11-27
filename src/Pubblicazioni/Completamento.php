<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

/**
 * Le colonne degli operatori di una riga della tabella di monitoraggio: quante sono ancora da completare e come si raggruppano per persona
 * (come nell'Excel: «a cura di Floriana Mantovano», «a cura di Silvana Geracitano»…). Senza stato e senza database: si prova da sola.
 */
final class Completamento
{
    /** L'ordine dei gruppi nella scheda (le persone di ColonneMonitoraggio::a_cura_di); un gruppo non elencato qui va in fondo. */
    private const ORDINE_PERSONE = [
        'Floriana Mantovano',
        'Silvana Geracitano',
        'Gianni Giglio',
        'Marco Bocci o Emanuele Dodaro',
        'Floriana Mantovano o Silvana Geracitano',
    ];

    /**
     * Le chiavi delle colonne degli operatori ancora vuote (NULL o solo spazi), nell'ordine dell'Excel.
     *
     * Le colonne NASCOSTE dall'amministratore (ConfigurazioneCampi) non contano: senza configurazione valgono tutte, come prima.
     *
     * Con $sole contano soltanto quelle colonne: sono le colonne di chi guarda, se «Chi compila cosa» lo limita (ColonneDiChi::scrivibili).
     *
     * @param array<string, mixed> $p la riga di pubblicazioni
     * @param list<string>|null $sole null = tutte le colonne degli operatori
     * @return list<string>
     */
    public static function mancanti(array $p, ?ConfigurazioneCampi $config = null, ?array $sole = null): array
    {
        $colonne = ($config ?? ConfigurazioneCampi::predefinita())->colonneOperatori();
        if ($sole !== null) {
            $colonne = array_intersect($colonne, $sole);
        }

        return array_values(array_filter(
            $colonne,
            static fn (string $k): bool => trim((string) ($p[$k] ?? '')) === ''
        ));
    }

    /** @param array<string, mixed> $p @param list<string>|null $sole */
    public static function numeroMancanti(array $p, ?ConfigurazioneCampi $config = null, ?array $sole = null): int
    {
        return count(self::mancanti($p, $config, $sole));
    }

    /** «6 da completare», «1 da completare», «Tutte compilate». */
    public static function testo(int $mancanti): string
    {
        return $mancanti <= 0 ? 'Tutte compilate' : $mancanti . ' da completare';
    }

    /**
     * Le colonne degli operatori VISIBILI raggruppate per persona (a_cura_di, come l'ha scritto l'amministratore), nell'ordine ORDINE_PERSONE; dentro ogni gruppo
     * l'ordine scelto per il file Excel.
     *
     * @return list<array{a_cura_di: string, colonne: list<array{chiave: string, lettera: string, intestazione: string, a_cura_di: string, colore: string, operatori: bool, tipo: string, visibile: bool, fissa: bool, modificata: bool, predefinita: array<string, mixed>}>}>
     */
    public static function gruppiPerPersona(?ConfigurazioneCampi $config = null): array
    {
        $per = [];
        foreach (($config ?? ConfigurazioneCampi::predefinita())->colonne() as $c) {
            if ($c['operatori']) {
                $per[$c['a_cura_di']][] = $c;
            }
        }
        $gruppi = [];
        foreach (self::ORDINE_PERSONE as $persona) {
            if (isset($per[$persona])) {
                $gruppi[] = ['a_cura_di' => $persona, 'colonne' => $per[$persona]];
                unset($per[$persona]);
            }
        }
        foreach ($per as $persona => $colonne) {
            $gruppi[] = ['a_cura_di' => (string) $persona, 'colonne' => $colonne];
        }

        return $gruppi;
    }
}
