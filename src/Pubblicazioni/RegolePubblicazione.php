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
     * «altri link» uno per riga (null se vuoti), date vuote come null. I campi NASCOSTI (chiavi di ConfigurazioneCampi::nascosti(): il form non li chiede)
     * valgono sempre vuoti, anche se qualcuno li manda lo stesso; «destinatari» azzera le quattro caselle.
     *
     * @param array<string, mixed> $dati
     * @param list<string> $nascosti
     * @return array<string, string|int|null>
     */
    public static function normalizza(array $dati, array $nascosti = []): array
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
        foreach ($nascosti as $k) {
            if ($k === 'destinatari') {
                foreach (array_keys(Costanti::DESTINATARI) as $d) {
                    $out[$d] = 0;
                }
            } elseif (array_key_exists($k, $out)) {
                $out[$k] = in_array($k, ['altri_link_docente', 'data_inizio_pubblicazione', 'data_evento'], true) ? null : '';
            }
        }

        return $out;
    }

    /**
     * Gli errori, campo per campo (vuoto = tutto a posto). Un solo controllo per i due casi:
     * - form del docente ($aMano = false): obbligatori tutti tranne gli «altri link» e le immagini; almeno un destinatario; almeno un allegato;
     *   data di inizio pubblicazione da oggi in poi;
     * - riga a mano dal pannello ($aMano = true): obbligatori SOLO data dell'evento, richiedente, titolo e locandina; nessun destinatario obbligatorio e
     *   nessun controllo sulla data di inizio pubblicazione nel passato.
     * In tutti e due i casi quello che è compilato deve essere nel formato giusto: sottotitolo e descrizione entro i caratteri delle impostazioni
     * (testo senza formattazione, spazi inclusi), indirizzi web http/https, modalità tra le tre, date valide, allegati PDF/JPG/PNG e immagini
     * JPG/PNG (al massimo quanti ne dicono le impostazioni).
     * I limiti arrivano da ImpostazioniPubblicazioni::limiti() (chi chiama li passa in $limiti; quelli che mancano valgono come i valori di partenza,
     * ImpostazioniPubblicazioni::INIZIALI: 75 e 170 caratteri, 10 allegati e 10 immagini, almeno un allegato, inizio pubblicazione da oggi).
     * Solo il form: «allegati_obbligatori» (almeno un allegato) e «giorni_minimi» (la data di inizio pubblicazione almeno tanti giorni dopo oggi).
     * Sempre solo il form: le regole dei campi di ConfigurazioneCampi::regole() ($campi). Un campo NASCOSTO non si controlla (e vale vuoto); un campo reso
     * FACOLTATIVO si controlla solo se compilato (formato e lunghezza); i destinatari, se il gruppo è visibile, restano «almeno uno»; allegati nascosti = nessun
     * controllo sugli allegati. Senza regole valgono quelle di partenza di ConfigurazioneCampi::CAMPI (URL, note e tag facoltativi, il resto obbligatorio). Titolo e data
     * dell'evento sono sempre obbligatori. La LOCANDINA (un solo file PDF/JPG/PNG, chiave «locandina» di $file) è sempre obbligatoria, anche nella riga a mano; gli «altri allegati» solo se
     * lo dicono le impostazioni («allegati_obbligatori», di partenza no).
     *
     * @param array<string, mixed> $dati come arrivano dal form (si ripuliscono con normalizza()); per le righe a mano anche «richiedente»
     * @param array{allegati?: list<array<string, mixed>>, immagini?: list<array<string, mixed>>} $file già come elenco (AllegatiPubblicazioni::elenco)
     * @param string $oggi «Y-m-d»
     * @param array<string, int|bool> $limiti vedi ImpostazioniPubblicazioni::limiti()
     * @param array<string, array{visibile?: bool, obbligatorio?: bool}> $campi vedi ConfigurazioneCampi::regole() (ignorate per le righe a mano)
     * @param list<array{nome: string, indirizzo: string}> $pagine «Dove vuoi che si pubblichi?» (ImpostazioniPubblicazioni::pagine()): con l'elenco pieno l'URL è una o più pagine, di
     *   quelle dell'elenco o «altra pagina del sito DiBEST» (vedi indirizzoDibest); con l'elenco vuoto è un solo indirizzo libero, come prima. $dati['url_docente'] lo prepara conPagine()
     * @return array<string, string>
     */
    public static function valida(array $dati, array $file, string $oggi, bool $aMano = false, array $limiti = [], array $campi = [], array $pagine = []): array
    {
        $campi = $aMano ? [] : $campi;
        $nascosti = array_keys(array_filter($campi, static fn (array $c): bool => ($c['visibile'] ?? true) === false));
        $d = self::normalizza($dati, $nascosti);
        // il campo va chiesto (obbligatorio) solo nel form, se è visibile e non è stato reso facoltativo
        $obbligatorio = static fn (string $k): bool => !$aMano && ($campi[$k]['visibile'] ?? true) !== false && ($campi[$k]['obbligatorio'] ?? (ConfigurazioneCampi::CAMPI[$k]['obbligatorio'] ?? true)) !== false;
        $visibile = static fn (string $k): bool => ($campi[$k]['visibile'] ?? true) !== false;
        $lim = $limiti + array_intersect_key(ImpostazioniPubblicazioni::INIZIALI, array_flip(ImpostazioniPubblicazioni::SEZIONI['limiti']));
        $maxSottotitolo = (int) $lim['max_sottotitolo'];
        $maxDescrizione = (int) $lim['max_descrizione'];
        $maxAllegati = (int) $lim['max_allegati'];
        $maxImmagini = (int) $lim['max_immagini'];
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

        $urlDocente = (string) $d['url_docente'];
        if ($urlDocente === '') {
            if ($obbligatorio('url_docente')) {
                $e['url_docente'] = $pagine !== [] ? 'Scegli almeno una pagina dove pubblicare.' : "Indica l'indirizzo (URL) della pagina.";
            }
        } elseif (mb_strlen($urlDocente) > Costanti::MAX_URL) {
            $e['url_docente'] = $pagine !== [] ? 'Hai scelto troppe pagine: gli indirizzi non possono superare ' . Costanti::MAX_URL . ' caratteri in tutto.' : "L'indirizzo è troppo lungo (massimo " . Costanti::MAX_URL . ' caratteri).';
        } elseif ($pagine === []) {
            if (!self::url($urlDocente)) {
                $e['url_docente'] = "L'indirizzo deve essere completo e iniziare con http:// o https://.";
            }
        } else {
            // con l'elenco: ogni indirizzo è una pagina dell'elenco oppure «altra pagina del sito DiBEST»
            $dellElenco = array_column($pagine, 'indirizzo');
            foreach (preg_split('/\s+/u', $urlDocente, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $u) {
                if (in_array($u, $dellElenco, true)) {
                    continue;
                }
                if (!self::url($u)) {
                    $e['url_docente'] = "L'indirizzo «{$u}» deve essere completo e iniziare con http:// o https://.";
                    break;
                }
                if (!self::indirizzoDibest($u)) {
                    $e['url_docente'] = self::MESSAGGIO_NON_DIBEST;
                    break;
                }
            }
        }

        foreach (self::link(trim((string) ($d['altri_link_docente'] ?? ''))) as $l) {
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
        if ($n === 0 && $obbligatorio('sottotitolo')) {
            $e['sottotitolo'] = 'Scrivi il sottotitolo.';
        } elseif ($n > $maxSottotitolo) {
            $e['sottotitolo'] = 'Il sottotitolo ha ' . $n . ' caratteri: al massimo ' . $maxSottotitolo . ', spazi inclusi.';
        }

        $n = self::lunghezzaTesto((string) $d['descrizione']);
        if ($n === 0 && $obbligatorio('descrizione')) {
            $e['descrizione'] = 'Scrivi la breve descrizione.';
        } elseif ($n > $maxDescrizione) {
            $e['descrizione'] = 'La descrizione ha ' . $n . ' caratteri: al massimo ' . $maxDescrizione . ', spazi inclusi.';
        }

        if (($d['modalita'] !== '' || $obbligatorio('modalita')) && !isset(Costanti::MODALITA[$d['modalita']])) {
            $e['modalita'] = 'Scegli la modalità di svolgimento: in presenza, online o mista.';
        }

        if ($obbligatorio('destinatari') && !array_filter(array_intersect_key($d, Costanti::DESTINATARI))) {
            $e['destinatari'] = "Indica almeno un destinatario dell'evento.";
        }

        if ($obbligatorio('note') && $d['note'] === '') {
            $e['note'] = 'Scrivi le note per l\'ufficio comunicazione.';
        }
        if ($obbligatorio('tag') && $d['tag'] === '') {
            $e['tag'] = 'Scrivi almeno un tag.';
        }

        $inizio = (string) $d['data_inizio_pubblicazione'];
        if ($inizio === '') {
            if ($obbligatorio('data_inizio_pubblicazione')) {
                $e['data_inizio_pubblicazione'] = 'Indica da quando pubblicare la notizia.';
            }
        } elseif (!self::data($inizio)) {
            $e['data_inizio_pubblicazione'] = 'La data di inizio pubblicazione non è valida.';
        } elseif (!$aMano && $inizio < self::primaDataDiInizio($oggi, (int) $lim['giorni_minimi'])) {
            $e['data_inizio_pubblicazione'] = self::messaggioInizio($oggi, (int) $lim['giorni_minimi']);
        }

        $locandina = $visibile('locandina') ? ($file['locandina'] ?? []) : [];
        $allegati = $visibile('allegati') ? ($file['allegati'] ?? []) : [];
        $immagini = $visibile('immagini') ? ($file['immagini'] ?? []) : [];
        if ($locandina === [] && $visibile('locandina')) {
            $e['locandina'] = 'Carica la locandina (un file PDF, JPG o PNG).';
        } elseif (count($locandina) > 1) {
            $e['locandina'] = 'La locandina è un file solo: gli altri file vanno negli altri allegati.';
        } else {
            foreach ($locandina as $f) {
                if (($m = AllegatiPubblicazioni::errore($f, 'locandina')) !== null) {
                    $e['locandina'] = $m;
                    break;
                }
            }
        }
        if ($allegati === [] && !$aMano && $visibile('allegati') && $lim['allegati_obbligatori']) {
            $e['allegati'] = 'Carica almeno un altro allegato, oltre alla locandina (PDF, JPG o PNG).';
        } elseif (count($allegati) > $maxAllegati) {
            $e['allegati'] = $maxAllegati === 1 ? 'Puoi caricare un solo allegato.' : 'Puoi caricare al massimo ' . $maxAllegati . ' allegati.';
        } else {
            foreach ($allegati as $f) {
                if (($m = AllegatiPubblicazioni::errore($f, 'allegato')) !== null) {
                    $e['allegati'] = $m;
                    break;
                }
            }
        }
        if (count($immagini) > $maxImmagini) {
            $e['immagini'] = $maxImmagini === 1 ? 'Puoi caricare una sola immagine.' : 'Puoi caricare al massimo ' . $maxImmagini . ' immagini.';
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

    /** Cosa si legge quando l'indirizzo è di Unical ma non del DiBEST (o non è di Unical). */
    public const MESSAGGIO_NON_DIBEST = 'Scegli una pagina del DiBEST: l\'indirizzo deve stare su dibest.unical.it o su un sito di Unical con «dibest» nel nome (per esempio didattica.dibest.unical.it).';

    /**
     * Con l'elenco «Dove vuoi che si pubblichi?» pieno, il form non manda un indirizzo solo ma le pagine scelte (pagine_scelte[]: indirizzi dell'elenco) e,
     * se serve, «un'altra pagina del sito DiBEST» (altra_pagina). Qui diventano url_docente, uno per riga; con l'elenco vuoto $dati resta com'è.
     *
     * @param array<string, mixed> $dati
     * @param list<array{nome: string, indirizzo: string}> $pagine
     * @return array<string, mixed>
     */
    public static function conPagine(array $dati, array $pagine): array
    {
        if ($pagine === []) {
            return $dati;
        }
        $scelte = is_array($dati['pagine_scelte'] ?? null) ? $dati['pagine_scelte'] : [];
        $righe = [];
        foreach ([...$scelte, (string) ($dati['altra_pagina'] ?? '')] as $u) {
            $u = is_string($u) ? trim($u) : '';
            if ($u !== '') {
                $righe[] = $u;
            }
        }
        $dati['url_docente'] = implode("\n", array_values(array_unique($righe)));

        return $dati;
    }

    /**
     * Se l'indirizzo è di una pagina del DiBEST: http o https, host sotto unical.it (mai «unical.it» da solo né un nome che finisce in «unical.it» senza il punto) e
     * con «dibest» nel nome dell'host (dibest.unical.it, didattica.dibest.unical.it…). Un indirizzo Unical generico (www.unical.it/…) non va bene.
     */
    public static function indirizzoDibest(string $url): bool
    {
        if (!self::url($url)) {
            return false;
        }
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));

        return str_ends_with($host, '.unical.it') && str_contains($host, 'dibest');
    }

    /** La prima data di inizio pubblicazione ammessa dal form: oggi più i giorni di anticipo delle impostazioni («Y-m-d»). */
    public static function primaDataDiInizio(string $oggi, int $giorni): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $oggi);
        if ($d === false) {
            return $oggi;
        }

        return $giorni > 0 ? $d->modify('+' . $giorni . ' days')->format('Y-m-d') : $d->format('Y-m-d');
    }

    /** L'errore di una data di inizio pubblicazione troppo vicina (lo stesso nel browser: la pagina lo passa al JavaScript). */
    public static function messaggioInizio(string $oggi, int $giorni): string
    {
        return $giorni > 0
            ? 'La data di inizio pubblicazione deve essere almeno ' . self::giorniInParole($giorni) . ' dopo oggi: dal ' . self::dataItaliana(self::primaDataDiInizio($oggi, $giorni)) . ' in poi.'
            : 'La data di inizio pubblicazione non può essere nel passato.';
    }

    /** «1 giorno», «3 giorni». */
    public static function giorniInParole(int $giorni): string
    {
        return $giorni === 1 ? '1 giorno' : $giorni . ' giorni';
    }

    /** «Y-m-d» → «gg/mm/aaaa». */
    public static function dataItaliana(string $data): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data);

        return $d !== false ? $d->format('d/m/Y') : $data;
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
