<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Portale\Vista\Testi;
use DateTimeImmutable;

/**
 * La richiesta di pubblicazione scritta in modo leggibile: i campi nell'ordine del modulo del docente, con le stesse etichette (quelle di ConfigurazioneCampi:
 * se l'amministratore ha nascosto un campo, la richiesta non lo chiedeva e qui non compare).
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
     * @param list<array<string, mixed>> $allegati le righe di pubblicazioni_allegati (tipo «locandina», «allegato» o «immagine»; le richieste di prima di v146 non hanno la locandina)
     * @return list<array{0: string, 1: string}>
     */
    public static function righe(array $p, array $allegati, ?ConfigurazioneCampi $config = null): array
    {
        return array_map(static fn (array $r): array => [$r[1], $r[2]], self::righeConChiave($p, $allegati, $config));
    }

    /**
     * Come righe(), con la chiave del campo davanti: (chiave, etichetta, valore in HTML). I campi nascosti non compaiono, a meno che $conNascosti sia vero
     * (scheda del pannello: le richieste già arrivate conservano i valori di allora) e abbiano un valore.
     *
     * @param array<string, mixed> $p
     * @param list<array<string, mixed>> $allegati
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function righeConChiave(array $p, array $allegati, ?ConfigurazioneCampi $config = null, bool $conNascosti = false): array
    {
        $config ??= ConfigurazioneCampi::predefinita();
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
        // v146: con l'elenco «Dove vuoi che si pubblichi?» le pagine scelte sono più indirizzi, uno per riga: un link ciascuno
        $url = array_filter(preg_split('/\s+/u', trim((string) ($p['url_docente'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        $valori = [
            'richiedente' => $testo((string) ($p['richiedente'] ?? '')),
            'data_evento' => $testo(self::data((string) ($p['data_evento'] ?? ''))),
            'modalita' => $testo($modalita),
            'url_docente' => $url ? implode('<br>', array_map($link, $url)) : self::VUOTO,
            'altri_link_docente' => $altri ? implode('<br>', array_map($link, $altri)) : self::VUOTO,
            'titolo' => $testo((string) ($p['titolo'] ?? '')),
            'sottotitolo' => $testo((string) ($p['sottotitolo'] ?? '')),
            'descrizione' => $descrizione === '' ? self::VUOTO : $descrizione,
            'destinatari' => $destinatari ? implode(', ', $destinatari) : self::VUOTO,
            'locandina' => self::elencoFile($allegati, 'locandina'),
            'allegati' => self::elencoFile($allegati, 'allegato'),
            'immagini' => self::elencoFile($allegati, 'immagine'),
            'tag' => $testo((string) ($p['tag'] ?? '')),
            'data_inizio_pubblicazione' => $testo(self::data((string) ($p['data_inizio_pubblicazione'] ?? ''))),
            'note' => $testo((string) ($p['note'] ?? '')),
        ];
        // i campi nuovi dell'amministratore (x_<n>): il valore secondo il tipo (date gg/mm/aaaa, link cliccabili, sì/no com'è), sempre escapato
        foreach ($config->campiExtra() as $x) {
            $v = trim((string) ($p[$x['chiave']] ?? ''));
            $valori[$x['chiave']] = match (true) {
                $v === '' => self::VUOTO,
                $x['tipo'] === 'url' => $link($v),
                $x['tipo'] === 'textarea' => $testo($v),
                default => $testo(ConfigurazioneCampi::valoreInParole($x['tipo'], $v)),
            };
        }
        $righe = [];
        foreach ($config->campi() as $c) {
            $k = $c['chiave'];
            if (!isset($valori[$k]) || (!$c['visibile'] && (!$conNascosti || $valori[$k] === self::VUOTO))) {
                continue;
            }
            if ($k === 'locandina' && $valori[$k] === self::VUOTO) {
                continue; // una richiesta di prima di v146: non aveva la locandina, la riga «Locandina: —» direbbe una cosa sbagliata
            }
            $righe[] = [$k, $c['etichetta'], $valori[$k]];
        }

        return $righe;
    }

    /**
     * La tabella HTML per le email (stili in linea, senza classi).
     *
     * @param array<string, mixed> $p
     * @param list<array<string, mixed>> $allegati
     */
    public static function tabella(array $p, array $allegati, ?ConfigurazioneCampi $config = null): string
    {
        $celle = '';
        foreach (self::righe($p, $allegati, $config) as [$etichetta, $valore]) {
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
