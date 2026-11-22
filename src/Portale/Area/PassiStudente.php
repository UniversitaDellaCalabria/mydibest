<?php

declare(strict_types=1);

namespace App\Portale\Area;

use App\Portale\Vista\PassiScheda;

/**
 * I passi che lo studente (o il tutor) vede nelle tessere dell'area personale, con ui_passi(…, 'piccolo'): dove è arrivata la pratica o la richiesta di tesi,
 * a che punto è l'incarico di tutorato. Solo regole sui dati che le tessere hanno già letto (lo stato della pratica, le tappe di inc/area/_tutorato_tappe.php).
 */
final class PassiStudente
{
    /** Gli stati con cui una pratica è conclusa. */
    private const CONCLUSI = ['accolta', 'respinta', 'chiusa'];

    /**
     * Una pratica: Inviata → In lavorazione → Esito. In «bozza» il primo passo è ancora da fare; con l'integrazione richiesta «In lavorazione» è bloccato.
     * $stato null = nessuna pratica ancora (il primo passo, $primo, è da fare).
     *
     * @return array{passi: list<array{titolo: string, stato: string, nota: string, link: string}>, corrente: int}
     */
    public static function pratica(?string $stato, string $primo = 'Inviata'): array
    {
        $bozza = $stato === null || $stato === 'bozza';
        $concluso = in_array($stato, self::CONCLUSI, true);
        $integrazione = $stato === 'integrazione';

        return PassiScheda::chiudi([
            ['titolo' => $primo, 'fatto' => !$bozza, 'nota' => $stato === 'bozza' ? 'da completare' : ($stato === null ? 'da fare' : '')],
            ['titolo' => 'In lavorazione', 'fatto' => $concluso, 'bloccato' => $integrazione, 'nota' => $integrazione ? 'serve la tua integrazione' : ''],
            ['titolo' => 'Esito', 'fatto' => $concluso, 'nota' => $concluso ? (string) (['accolta' => 'accolta', 'respinta' => 'respinta', 'chiusa' => 'chiusa'][$stato] ?? '') : ''],
        ]);
    }

    /** La richiesta di tesi: Richiesta → In lavorazione → Esito (stessa strada di una pratica, con il primo passo che si chiama «Richiesta»). */
    public static function tesi(?string $statoPratica): array
    {
        return self::pratica($statoPratica, 'Richiesta');
    }

    /**
     * L'incarico di tutorato dalle tappe già calcolate da area_tappe_tutorato(): titoli brevi, quelli lunghi della pagina restano in il_mio_tutorato.php.
     *
     * @param list<array{0: string, 1: bool, 2: string}> $tappe titolo esteso, fatta, nota
     * @return array{passi: list<array{titolo: string, stato: string, nota: string, link: string}>, corrente: int}
     */
    public static function tutorato(array $tappe): array
    {
        $brevi = ['Accettato', 'Lettera firmata', 'Registro', 'Fine attività', 'Ai pagamenti', 'Pagato'];
        $grezzi = [];
        foreach (array_values($tappe) as $i => $t) {
            $grezzi[] = ['titolo' => $brevi[$i] ?? (string) $t[0], 'fatto' => (bool) $t[1]];
        }

        return PassiScheda::chiudi($grezzi);
    }
}
