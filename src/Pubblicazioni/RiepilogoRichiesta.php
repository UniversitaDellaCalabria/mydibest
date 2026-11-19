<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Portale\Vista\Testi;
use DateTimeImmutable;

/**
 * La richiesta di pubblicazione scritta in modo leggibile: tutti i campi nell'ordine del modulo del docente, con le stesse etichette.
 * Serve alle due email (avviso all'ufficio comunicazione e ricevuta al docente). Senza stato e senza database: si prova da sola.
 * Tutti i valori escono già «escapati»; la descrizione mantiene grassetto e corsivo (HTML ridotto da Testi::pulisciHtmlSemplice).
 */
final class RiepilogoRichiesta
{
    private const VUOTO = '<span style="color:#64748b;">—</span>';

    /**
     * Le righe (etichetta, valore in HTML) di una richiesta.
     *
     * @param array<string, mixed> $p la riga di pubblicazioni
     * @param list<array<string, mixed>> $allegati le righe di pubblicazioni_allegati (tipo «allegato» o «immagine»)
     * @return list<array{0: string, 1: string}>
     */
    public static function righe(array $p, array $allegati): array
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $testo = static fn (string $s): string => trim($s) === '' ? self::VUOTO : nl2br($h(trim($s)), false);
        $link = static fn (string $u): string => '<a href="' . $h($u) . '">' . $h($u) . '</a>';

        $altri = array_filter(preg_split('/\s+/u', trim((string) ($p['altri_link_docente'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $destinatari = [];
        foreach (Costanti::DESTINATARI as $campo => [$etichetta]) {
            if (!empty($p[$campo])) {
                $destinatari[] = $h($etichetta);
            }
        }
        $descrizione = Testi::pulisciHtmlSemplice((string) ($p['descrizione'] ?? ''));
        $modalita = Costanti::MODALITA[(string) ($p['modalita'] ?? '')] ?? '';
        $url = trim((string) ($p['url_docente'] ?? ''));

        return [
            ['Richiedente', $testo((string) ($p['richiedente'] ?? ''))],
            ['Data dell\'evento', $testo(self::data((string) ($p['data_evento'] ?? '')))],
            ['Modalità di svolgimento', $testo($modalita)],
            ['URL', $url === '' ? self::VUOTO : $link($url)],
            ['URL/Link ad altre pagine', $altri ? implode('<br>', array_map($link, $altri)) : self::VUOTO],
            ['Titolo', $testo((string) ($p['titolo'] ?? ''))],
            ['Sottotitolo', $testo((string) ($p['sottotitolo'] ?? ''))],
            ['Breve descrizione', $descrizione === '' ? self::VUOTO : $descrizione],
            ['Evento rivolto a', $destinatari ? implode(', ', $destinatari) : self::VUOTO],
            ['Allegati da pubblicare', self::elencoFile($allegati, 'allegato')],
            ['Ulteriori immagini', self::elencoFile($allegati, 'immagine')],
            ['Tag', $testo((string) ($p['tag'] ?? ''))],
            ['Data inizio pubblicazione', $testo(self::data((string) ($p['data_inizio_pubblicazione'] ?? '')))],
            ['Note', $testo((string) ($p['note'] ?? ''))],
        ];
    }

    /**
     * La tabella HTML per le email (stili in linea, senza classi).
     *
     * @param array<string, mixed> $p
     * @param list<array<string, mixed>> $allegati
     */
    public static function tabella(array $p, array $allegati): string
    {
        $celle = '';
        foreach (self::righe($p, $allegati) as [$etichetta, $valore]) {
            $celle .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;color:#475569;vertical-align:top;width:34%;">' . htmlspecialchars($etichetta, ENT_QUOTES, 'UTF-8')
                . '</td><td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;vertical-align:top;">' . $valore . '</td></tr>';
        }

        return '<table style="border-collapse:collapse;width:100%;font-size:14px;">' . $celle . '</table>';
    }

    /** «05/12/2026» da «2026-12-05» (vuoto se la data manca; il testo com'è se non è una data). */
    public static function data(string $ymd): string
    {
        if ($ymd === '') {
            return '';
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));

        return $d !== false ? $d->format('d/m/Y') : $ymd;
    }

    /** «120 KB», «2,4 MB». */
    public static function dimensione(int $byte): string
    {
        if ($byte >= 1048576) {
            return number_format($byte / 1048576, 1, ',', '') . ' MB';
        }

        return max(1, (int) round($byte / 1024)) . ' KB';
    }

    /** @param list<array<string, mixed>> $allegati */
    private static function elencoFile(array $allegati, string $tipo): string
    {
        $voci = [];
        foreach ($allegati as $a) {
            if (($a['tipo'] ?? '') === $tipo) {
                $voci[] = htmlspecialchars((string) $a['nome_originale'], ENT_QUOTES, 'UTF-8') . ' <span style="color:#64748b;">(' . self::dimensione((int) ($a['dimensione'] ?? 0)) . ')</span>';
            }
        }

        return $voci ? implode('<br>', $voci) : self::VUOTO;
    }
}
