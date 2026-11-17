<?php

declare(strict_types=1);

namespace App\Forms;

use DateTimeImmutable;

/** I filtri della pagina delle risposte (ricerca nel testo, periodo, profilo): letti dalla richiesta con controlli, e la ricerca nel testo. */
final class FiltriRisposte
{
    /** Quante risposte per pagina nella tabella. */
    public const PER_PAGINA = 25;

    /**
     * Filtri puliti dalla richiesta (GET). Una data che non esiste (31/02) o un profilo che non compare tra le risposte si scartano.
     *
     * @param array<string, mixed> $richiesta
     * @param list<string> $profiliPresenti i profili che compaiono davvero tra le risposte del form
     * @return array{q: string, dal: string, al: string, profilo: string}
     */
    public static function da(array $richiesta, array $profiliPresenti): array
    {
        $profilo = trim((string) ($richiesta['profilo'] ?? ''));
        $dal = self::data((string) ($richiesta['dal'] ?? ''));
        $al = self::data((string) ($richiesta['al'] ?? ''));
        if ($dal !== '' && $al !== '' && $al < $dal) {
            [$dal, $al] = [$al, $dal]; // periodo scritto al contrario: si raddrizza
        }

        return [
            'q' => mb_substr(trim((string) ($richiesta['q'] ?? '')), 0, 100),
            'dal' => $dal,
            'al' => $al,
            'profilo' => in_array($profilo, $profiliPresenti, true) ? $profilo : '',
        ];
    }

    /** Il giorno «Y-m-d» solo se esiste davvero; altrimenti vuoto. */
    public static function data(string $valore): string
    {
        $valore = trim($valore);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valore) !== 1) {
            return '';
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $valore);

        return $d && $d->format('Y-m-d') === $valore ? $valore : '';
    }

    /**
     * Giorno e ora nel formato di datetime-local («Y-m-dTH:i») solo se esistono davvero (non 2026-13-45T10:00 né T25:61); altrimenti vuoto. Il giorno si
     * controlla con data(), l'ora si guarda qui. Lo usa la scheda del form in admin/forms.php per «Aperto dal» e «al».
     */
    public static function dataOra(string $valore): string
    {
        $valore = trim($valore);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T([01]\d|2[0-3]):[0-5]\d$/', $valore, $m) !== 1 || self::data($m[1]) === '') {
            return '';
        }

        return $valore;
    }

    /** Se c'è almeno un filtro attivo. @param array{q: string, dal: string, al: string, profilo: string} $filtri */
    public static function attivi(array $filtri): bool
    {
        return $filtri['q'] !== '' || $filtri['dal'] !== '' || $filtri['al'] !== '' || $filtri['profilo'] !== '';
    }

    /**
     * Se il testo cercato compare nella risposta: nei valori delle domande visibili, nel codice di ricevuta e, solo nei form non anonimi, in nome,
     * cognome ed email. Maiuscole e minuscole non contano. Nei form anonimi non si guarda mai chi ha risposto.
     *
     * @param array<string, mixed> $riga una riga di forms_risposte (con «nome» e «cognome» se letti)
     */
    public static function corrisponde(array $riga, string $testo, bool $anonima): bool
    {
        $testo = trim($testo);
        if ($testo === '') {
            return true;
        }
        $fieno = [(string) ($riga['codice_ricevuta'] ?? '')];
        if (!$anonima) {
            $fieno[] = trim((string) ($riga['nome'] ?? '') . ' ' . (string) ($riga['cognome'] ?? ''));
            $fieno[] = trim((string) ($riga['cognome'] ?? '') . ' ' . (string) ($riga['nome'] ?? ''));
            $fieno[] = (string) ($riga['email'] ?? '');
        }
        foreach (EsportazioneRisposte::decodifica($riga) as $r) {
            if (empty($r['nascosto'])) {
                $fieno[] = (string) ($r['valore'] ?? '');
            }
        }
        foreach ($fieno as $f) {
            if ($f !== '' && mb_stripos($f, $testo) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Una pagina dell'elenco.
     *
     * @template T
     * @param list<T> $righe
     * @return array{righe: list<T>, pagina: int, pagine: int, totale: int}
     */
    public static function pagina(array $righe, int $pagina, int $perPagina = self::PER_PAGINA): array
    {
        $totale = count($righe);
        $perPagina = max(1, $perPagina);
        $pagine = max(1, (int) ceil($totale / $perPagina));
        $pagina = min(max(1, $pagina), $pagine);

        return ['righe' => array_slice($righe, ($pagina - 1) * $perPagina, $perPagina), 'pagina' => $pagina, 'pagine' => $pagine, 'totale' => $totale];
    }
}
