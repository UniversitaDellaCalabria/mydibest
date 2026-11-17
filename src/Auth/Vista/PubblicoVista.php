<?php

declare(strict_types=1);

namespace App\Auth\Vista;

use App\Auth\Pubblico;
use App\Sistema\Html;

/** Caselle accessibili per scegliere il pubblico di una cosa (una risorsa, un modulo, un form…) nei pannelli. */
final class PubblicoVista
{
    /**
     * Le caselle dei profili (App\Auth\Pubblico::PROFILI), con etichette leggibili. $nome è il prefisso dei campi
     * del modulo: le caselle selezionate arrivano in $_POST["{$nome}_profili"][].
     *
     * @param array{profili?: list<string>} $regola
     */
    public static function scelta(string $nome, array $regola): string
    {
        $selezionati = $regola['profili'] ?? [];
        $idFieldset = 'pubblico_' . preg_replace('/[^a-zA-Z0-9_]/', '', $nome);
        $out = '<fieldset class="d-flex flex-wrap gap-3" id="' . Html::h($idFieldset) . '">'
             . '<legend class="col-form-label fw-bold p-0 w-100 mb-1" style="font-size:1rem;">A chi si rivolge</legend>';
        foreach (Pubblico::PROFILI as $profilo) {
            $campo = Html::h($nome) . '_profili';
            $idInput = Html::h($idFieldset . '_' . $profilo);
            // «Scuole» si dichiara da soli: accanto alla casella un aiuto, collegato per chi usa un lettore di schermo
            $aiuto = $profilo === Pubblico::SCUOLE ? Html::h($idInput . '_aiuto') : '';
            $out .= '<label class="form-check m-0" for="' . $idInput . '"><input class="form-check-input" type="checkbox" id="' . $idInput . '" name="' . $campo . '[]" value="'
                . Html::h($profilo) . '"' . (in_array($profilo, $selezionati, true) ? ' checked' : '') . ($aiuto !== '' ? ' aria-describedby="' . $aiuto . '"' : '') . '> '
                . Html::h(Pubblico::ETICHETTE[$profilo] ?? $profilo)
                . ($aiuto !== '' ? ' <small class="form-text d-block mt-0" id="' . $aiuto . '">' . Html::h(Pubblico::AVVISO_SCUOLE) . '</small>' : '')
                . '</label>';
        }

        return $out . '</fieldset>';
    }

    /**
     * La regola scelta nel modulo (il prefisso $nome usato in scelta()). Nessuna casella selezionata = nessuno
     * (a parte gli amministratori, sempre ammessi da Pubblico::ammesso()).
     *
     * @param array<string, mixed> $post
     * @return array{profili: list<string>}
     */
    public static function daPost(string $nome, array $post): array
    {
        $scelti = (array) ($post[$nome . '_profili'] ?? []);

        return ['profili' => array_values(array_intersect(Pubblico::PROFILI, array_map('strval', $scelti)))];
    }
}
