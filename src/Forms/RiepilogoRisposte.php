<?php

declare(strict_types=1);

namespace App\Forms;

/**
 * Riepilogo delle risposte: per le domande a scelta (tendina, scelta singola e multipla, casella sì/no) quante persone hanno scelto ogni voce,
 * per le altre soltanto quante risposte ci sono. Si conta solo chi ha visto la domanda (le domande nascoste da una condizione non contano).
 */
final class RiepilogoRisposte
{
    private const A_SCELTA = ['select', 'radio', 'multicheck', 'checkbox'];

    /**
     * @param array<string, mixed> $form
     * @param list<array<string, mixed>> $righe righe di forms_risposte (già filtrate)
     * @return list<array{etichetta: string, tipo: string, scelta: bool, rispondenti: int, multipla: bool, voci: list<array{testo: string, n: int, perc: float}>}>
     */
    public static function calcola(array $form, array $righe): array
    {
        $campi = EsportazioneRisposte::campiConRisposta($form);
        $viste = [];
        foreach ($righe as $r) {
            $viste[] = EsportazioneRisposte::allinea($campi, EsportazioneRisposte::decodifica($r));
        }

        $out = [];
        foreach ($campi as $i => $c) {
            $scelta = in_array($c['tipo'], self::A_SCELTA, true);
            $conta = [];
            $visibili = 0;     // chi ha visto la domanda
            $rispondenti = 0;  // chi ha risposto qualcosa
            foreach ($viste as $v) {
                $a = $v[$i] ?? null;
                if ($a === null || !empty($a['nascosto'])) {
                    continue;
                }
                $visibili++;
                $valore = trim((string) ($a['valore'] ?? ''));
                if ($c['tipo'] === 'checkbox') {
                    $voce = $valore === 'Sì' ? 'Sì' : 'No';
                    $conta[$voce] = ($conta[$voce] ?? 0) + 1;
                    $rispondenti++;
                    continue;
                }
                if ($valore === '') {
                    continue;
                }
                $rispondenti++;
                if ($scelta) {
                    foreach ($c['tipo'] === 'multicheck' ? explode(', ', $valore) : [$valore] as $scelto) {
                        $scelto = trim($scelto);
                        if ($scelto !== '') {
                            $conta[$scelto] = ($conta[$scelto] ?? 0) + 1;
                        }
                    }
                }
            }

            $voci = [];
            if ($scelta) {
                // prima le voci previste dal form (anche con zero risposte), poi quelle che non ci sono più
                $previste = $c['tipo'] === 'checkbox' ? ['Sì', 'No'] : $c['opzioni'];
                foreach ($previste as $o) {
                    $voci[] = ['testo' => (string) $o, 'n' => $conta[$o] ?? 0, 'perc' => self::percentuale($conta[$o] ?? 0, $rispondenti)];
                }
                $altre = array_diff_key($conta, array_flip(array_map('strval', $previste)));
                arsort($altre);
                foreach ($altre as $testo => $n) {
                    $voci[] = ['testo' => (string) $testo, 'n' => $n, 'perc' => self::percentuale($n, $rispondenti)];
                }
            }
            $out[] = ['etichetta' => (string) $c['etichetta'], 'tipo' => (string) $c['tipo'], 'scelta' => $scelta, 'rispondenti' => $rispondenti,
                'multipla' => $c['tipo'] === 'multicheck', 'voci' => $voci];
        }

        return $out;
    }

    /** Percentuale con un decimale (0 se non risponde nessuno). */
    private static function percentuale(int $n, int $base): float
    {
        return $base > 0 ? round($n * 100 / $base, 1) : 0.0;
    }
}
