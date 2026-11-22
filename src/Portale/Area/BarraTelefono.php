<?php

declare(strict_types=1);

namespace App\Portale\Area;

/**
 * La barra fissa in basso dell'area personale sul telefono (sotto i 768 px, solo con l'accesso fatto): Home, Da fare, Calendario, Messaggi, Profilo.
 * Solo regole e testo (senza database): le voci, quale è la pagina attuale, la memoria dei numeri nella sessione (così le pagine figlie non rifanno le
 * letture che l'area personale ha già fatto) e l'HTML. Lo stile sta in assets/css/portale-ui.css (.ui-barra-tel).
 */
final class BarraTelefono
{
    /** Quanto valgono i numeri tenuti in sessione (secondi): dopo, la barra li omette finché l'area personale non li aggiorna. */
    public const DURATA = 300;

    private const CHIAVE_SESSIONE = 'area_barra';

    /**
     * Le cinque voci. $messaggiHref = dove portano i messaggi (la casella di chi gestisce, oppure le prenotazioni dello studente).
     *
     * @return list<array{chiave: string, etichetta: string, icona: string, href: string, numero: int}>
     */
    public static function voci(int $daFare, int $messaggi, string $messaggiHref = 'miei_eventi.php'): array
    {
        return [
            ['chiave' => 'home', 'etichetta' => 'Home', 'icona' => 'fa-house', 'href' => 'area_personale.php', 'numero' => 0],
            ['chiave' => 'da_fare', 'etichetta' => 'Da fare', 'icona' => 'fa-list-check', 'href' => 'da_fare.php', 'numero' => max(0, $daFare)],
            ['chiave' => 'calendario', 'etichetta' => 'Calendario', 'icona' => 'fa-calendar-days', 'href' => 'calendario.php', 'numero' => 0],
            ['chiave' => 'messaggi', 'etichetta' => 'Messaggi', 'icona' => 'fa-envelope', 'href' => $messaggiHref, 'numero' => max(0, $messaggi)],
            ['chiave' => 'profilo', 'etichetta' => 'Profilo', 'icona' => 'fa-user', 'href' => 'profilo.php', 'numero' => 0],
        ];
    }

    /** La voce della pagina in cui ci si trova (per aria-current), dal nome del file; '' se la pagina non è una delle cinque. */
    public static function attiva(string $pagina, string $messaggiHref = 'miei_eventi.php'): string
    {
        $file = basename((string) parse_url($pagina, PHP_URL_PATH));
        $mappa = ['area_personale.php' => 'home', 'da_fare.php' => 'da_fare', 'calendario.php' => 'calendario', 'profilo.php' => 'profilo', 'messaggi.php' => 'messaggi'];
        if ($file === basename((string) parse_url($messaggiHref, PHP_URL_PATH)) && $file !== '') {
            return 'messaggi';
        }

        return $mappa[$file] ?? '';
    }

    /**
     * Mette in sessione i numeri appena letti.
     *
     * @param array<string, mixed> $sessione di solito $_SESSION
     */
    public static function memorizza(array &$sessione, int $daFare, int $messaggi, string $messaggiHref, int $ora): void
    {
        $sessione[self::CHIAVE_SESSIONE] = ['da_fare' => max(0, $daFare), 'messaggi' => max(0, $messaggi), 'href' => $messaggiHref, 't' => $ora];
    }

    /**
     * I numeri tenuti in sessione, se ci sono e non sono scaduti.
     *
     * @param array<string, mixed> $sessione
     * @return array{da_fare: int, messaggi: int, href: string}|null
     */
    public static function letti(array $sessione, int $ora): ?array
    {
        $m = $sessione[self::CHIAVE_SESSIONE] ?? null;
        if (!is_array($m) || $ora - (int) ($m['t'] ?? 0) > self::DURATA || $ora < (int) ($m['t'] ?? 0)) {
            return null;
        }

        return ['da_fare' => (int) ($m['da_fare'] ?? 0), 'messaggi' => (int) ($m['messaggi'] ?? 0), 'href' => (string) ($m['href'] ?? 'miei_eventi.php')];
    }

    /**
     * L'HTML della barra. Il numero, se c'è, si legge anche con lo screen reader; la voce attiva ha aria-current="page".
     *
     * @param list<array{chiave: string, etichetta: string, icona: string, href: string, numero: int}> $voci
     */
    public static function html(array $voci, string $attiva): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $o = '<nav class="ui-barra-tel d-print-none" id="uiBarraTel" aria-label="Navigazione rapida"><ul>';
        foreach ($voci as $v) {
            $n = (int) $v['numero'];
            $o .= '<li><a href="' . $h($v['href']) . '"' . ($v['chiave'] === $attiva ? ' aria-current="page"' : '') . '>'
                . '<span class="ui-barra-icona" aria-hidden="true"><i class="fa ' . $h($v['icona']) . '"></i>' . ($n > 0 ? '<span class="ui-badge-rosso">' . ($n > 99 ? '99+' : $n) . '</span>' : '') . '</span>'
                . '<span class="ui-barra-et">' . $h($v['etichetta']) . '</span>'
                . ($n > 0 ? '<span class="visually-hidden"> (' . $n . ')</span>' : '') . '</a></li>';
        }

        return $o . '</ul></nav>';
    }
}
