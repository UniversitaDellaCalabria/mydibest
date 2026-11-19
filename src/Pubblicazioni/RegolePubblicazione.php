<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Portale\Vista\Testi;
use DateTimeImmutable;

/**
 * Le regole dei dati di una richiesta di pubblicazione (quelli che scrive il docente nel form, o l'operatore in una riga a mano): pulizia e controlli.
 * Senza stato e senza database: si prova da sola. La data di oggi la passa chi chiama (ServizioPubblicazioni la prende dall'Orologio).
 */
final class RegolePubblicazione
{
    /** I campi del docente, come si salvano nella tabella pubblicazioni. */
    public const CAMPI_DOCENTE = [
        'data_evento', 'url_docente', 'altri_link_docente', 'titolo', 'sottotitolo', 'descrizione', 'modalita',
        'per_cittadinanza', 'per_studenti', 'per_docenti', 'per_pta', 'note', 'tag', 'data_inizio_pubblicazione',
    ];

    /**
     * I dati ripuliti: testi senza spazi ai lati, descrizione ridotta all'HTML semplice (grassetto, corsivo…), caselle 0/1,
     * «altri link» uno per riga (null se vuoti), date vuote come null.
     *
     * @param array<string, mixed> $dati
     * @return array<string, string|int|null>
     */
    public static function normalizza(array $dati): array
    {
        $testo = static fn (string $k): string => trim(str_replace("\r\n", "\n", (string) ($dati[$k] ?? '')));
        $link = self::link($testo('altri_link_docente'));
        $out = [
            'data_evento' => $testo('data_evento') === '' ? null : $testo('data_evento'),
            'url_docente' => $testo('url_docente'),
            'altri_link_docente' => $link ? implode("\n", $link) : null,
            // titolo e sottotitolo sono testo semplice: niente tag, spazi e a capo ridotti a uno spazio
            'titolo' => trim((string) preg_replace('/\s+/u', ' ', strip_tags($testo('titolo')))),
            'sottotitolo' => trim((string) preg_replace('/\s+/u', ' ', strip_tags($testo('sottotitolo')))),
            'descrizione' => Testi::pulisciHtmlSemplice($testo('descrizione')),
            'modalita' => $testo('modalita'),
            'note' => $testo('note'),
            'tag' => $testo('tag'),
            'data_inizio_pubblicazione' => $testo('data_inizio_pubblicazione') === '' ? null : $testo('data_inizio_pubblicazione'),
        ];
        foreach (array_keys(Costanti::DESTINATARI) as $k) {
            $out[$k] = empty($dati[$k]) ? 0 : 1;
        }

        return $out;
    }

    /**
     * Gli errori, campo per campo (vuoto = tutto a posto). Un solo controllo per i due casi:
     * - form del docente ($aMano = false): obbligatori tutti tranne gli «altri link» e le immagini; almeno un destinatario; almeno un allegato;
     *   data di inizio pubblicazione da oggi in poi;
     * - riga a mano dal pannello ($aMano = true): obbligatori SOLO data dell'evento, richiedente e titolo; nessun destinatario obbligatorio e
     *   nessun controllo sulla data di inizio pubblicazione nel passato.
     * In tutti e due i casi quello che è compilato deve essere nel formato giusto: sottotitolo fino a 75 e descrizione fino a 170 caratteri
     * (testo senza formattazione, spazi inclusi), indirizzi web http/https, modalità tra le tre, date valide, allegati PDF/JPG/PNG e immagini
     * JPG/PNG (al massimo Costanti::MAX_ALLEGATI allegati e Costanti::MAX_IMMAGINI immagini).
     *
     * @param array<string, mixed> $dati come arrivano dal form (si ripuliscono con normalizza()); per le righe a mano anche «richiedente»
     * @param array{allegati?: list<array<string, mixed>>, immagini?: list<array<string, mixed>>} $file già come elenco (AllegatiPubblicazioni::elenco)
     * @param string $oggi «Y-m-d»
     * @return array<string, string>
     */
    public static function valida(array $dati, array $file, string $oggi, bool $aMano = false): array
    {
        $d = self::normalizza($dati);
        $obbligatorio = !$aMano;
        $e = [];

        $evento = (string) $d['data_evento'];
        if ($evento === '') {
            $e['data_evento'] = "Indica la data dell'evento.";
        } elseif (!self::data($evento)) {
            $e['data_evento'] = "La data dell'evento non è valida.";
        }

        if ($aMano) {
            $richiedente = self::richiedente($dati);
            if ($richiedente === '') {
                $e['richiedente'] = 'Scrivi chi ha chiesto la pubblicazione (Cognome Nome).';
            } elseif (mb_strlen($richiedente) > 200) {
                $e['richiedente'] = 'Il nome del richiedente è troppo lungo (massimo 200 caratteri).';
            }
        }

        if ($d['url_docente'] === '') {
            if ($obbligatorio) {
                $e['url_docente'] = "Indica l'indirizzo (URL) della pagina.";
            }
        } elseif (!self::url((string) $d['url_docente'])) {
            $e['url_docente'] = "L'indirizzo deve essere completo e iniziare con http:// o https://.";
        }

        foreach (self::link(trim((string) ($dati['altri_link_docente'] ?? ''))) as $l) {
            if (!self::url($l)) {
                $e['altri_link_docente'] = "Questo link non è valido: «{$l}». Ogni link deve essere completo e iniziare con http:// o https:// (uno per riga).";
                break;
            }
        }

        if ($d['titolo'] === '') {
            $e['titolo'] = 'Scrivi il titolo.';
        } elseif (mb_strlen((string) $d['titolo']) > Costanti::MAX_TITOLO) {
            $e['titolo'] = 'Il titolo è troppo lungo (massimo ' . Costanti::MAX_TITOLO . ' caratteri).';
        }

        $n = self::lunghezzaTesto((string) $d['sottotitolo']);
        if ($n === 0 && $obbligatorio) {
            $e['sottotitolo'] = 'Scrivi il sottotitolo.';
        } elseif ($n > Costanti::MAX_SOTTOTITOLO) {
            $e['sottotitolo'] = 'Il sottotitolo ha ' . $n . ' caratteri: al massimo ' . Costanti::MAX_SOTTOTITOLO . ', spazi inclusi.';
        }

        $n = self::lunghezzaTesto((string) $d['descrizione']);
        if ($n === 0 && $obbligatorio) {
            $e['descrizione'] = 'Scrivi la breve descrizione.';
        } elseif ($n > Costanti::MAX_DESCRIZIONE) {
            $e['descrizione'] = 'La descrizione ha ' . $n . ' caratteri: al massimo ' . Costanti::MAX_DESCRIZIONE . ', spazi inclusi.';
        }

        if (($d['modalita'] !== '' || $obbligatorio) && !isset(Costanti::MODALITA[$d['modalita']])) {
            $e['modalita'] = 'Scegli la modalità di svolgimento: in presenza, online o mista.';
        }

        if ($obbligatorio && !array_filter(array_intersect_key($d, Costanti::DESTINATARI))) {
            $e['destinatari'] = "Indica almeno un destinatario dell'evento.";
        }

        if ($obbligatorio && $d['note'] === '') {
            $e['note'] = 'Scrivi le note (se non ce ne sono, scrivi «nessuna»).';
        }
        if ($obbligatorio && $d['tag'] === '') {
            $e['tag'] = 'Scrivi almeno un tag.';
        }

        $inizio = (string) $d['data_inizio_pubblicazione'];
        if ($inizio === '') {
            if ($obbligatorio) {
                $e['data_inizio_pubblicazione'] = 'Indica da quando pubblicare la notizia.';
            }
        } elseif (!self::data($inizio)) {
            $e['data_inizio_pubblicazione'] = 'La data di inizio pubblicazione non è valida.';
        } elseif ($obbligatorio && $inizio < $oggi) {
            $e['data_inizio_pubblicazione'] = 'La data di inizio pubblicazione non può essere nel passato.';
        }

        $allegati = $file['allegati'] ?? [];
        $immagini = $file['immagini'] ?? [];
        if ($allegati === [] && $obbligatorio) {
            $e['allegati'] = 'Carica almeno un allegato (PDF, JPG o PNG).';
        } elseif (count($allegati) > Costanti::MAX_ALLEGATI) {
            $e['allegati'] = 'Puoi caricare al massimo ' . Costanti::MAX_ALLEGATI . ' allegati.';
        } else {
            foreach ($allegati as $f) {
                if (($m = AllegatiPubblicazioni::errore($f, 'allegato')) !== null) {
                    $e['allegati'] = $m;
                    break;
                }
            }
        }
        if (count($immagini) > Costanti::MAX_IMMAGINI) {
            $e['immagini'] = 'Puoi caricare al massimo ' . Costanti::MAX_IMMAGINI . ' immagini.';
        } else {
            foreach ($immagini as $f) {
                if (($m = AllegatiPubblicazioni::errore($f, 'immagine')) !== null) {
                    $e['immagini'] = $m;
                    break;
                }
            }
        }

        return $e;
    }

    /** Il richiedente di una riga a mano come lo scrive l'operatore, con gli spazi doppi ridotti. @param array<string, mixed> $dati */
    public static function richiedente(array $dati): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) ($dati['richiedente'] ?? '')));
    }

    /**
     * Quanti caratteri conta un testo come lo legge chi guarda la notizia: senza tag di formattazione, con le entità (&amp; &nbsp; …) come un
     * carattere solo, spazi inclusi (anche quelli ai lati non contano). Un a capo conta come uno spazio.
     */
    public static function lunghezzaTesto(string $html): int
    {
        $html = (string) preg_replace('#<br\s*/?>|</p>\s*<p[^>]*>#i', ' ', $html);
        $testo = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_strlen(trim($testo, " \t\n\r\0\x0B\u{00A0}"));
    }

    /** Indirizzo web completo con http:// o https://. */
    public static function url(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $schema = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($schema, ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    /** Data «Y-m-d» esistente. */
    public static function data(string $data): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data);

        return $d !== false && $d->format('Y-m-d') === $data;
    }

    /** @return list<string> i link scritti uno per riga (o separati da spazi), senza doppioni */
    private static function link(string $testo): array
    {
        return array_values(array_unique(preg_split('/\s+/u', $testo, -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }
}
