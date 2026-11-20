<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Auth\Pubblico;
use App\Core\Database;
use App\Forms\NotificheForms;
use App\Portale\Vista\Testi;
use Throwable;

/**
 * Le impostazioni della Richiesta di pubblicazione: la sola classe che le legge e le salva. Stanno in impostazioni_sistema (riga id = 1), colonne
 * pubblicazioni_*, accanto all'indirizzo di notifica che c'era già (pubblicazioni_email_notifica), come le altre impostazioni dei servizi del portale
 * (didacticus_*, assistenza_*): una riga sola, valori di partenza nella definizione delle colonne (Definizioni, v131).
 *
 * - chi_puo_inviare: i profili di App\Auth\Pubblico che possono inviare la richiesta (mai «anonimi»: serve l'accesso); di partenza docenti e PTA;
 * - aperta, messaggio_chiusa: con la procedura chiusa nessuno invia (neppure gli amministratori) e la pagina mostra il messaggio;
 * - testo_introduttivo, testo_dopo_invio: HTML semplice (grassetto, corsivo, elenchi, link), ripulito con Testi::pulisciHtmlSemplice;
 * - limiti: max_sottotitolo, max_descrizione (caratteri), max_allegati, max_immagini (file), allegati_obbligatori, giorni_minimi (anticipo della data
 *   di inizio pubblicazione: 0 = da oggi in poi, come prima);
 * - email: indirizzi (che ricevono le richieste: stesse regole di MyForms, NotificheForms::indirizzi) e ricevuta_al_richiedente.
 *
 * Una colonna che non c'è ancora (caricamento a metà) o un valore fuori misura nel database valgono come il valore di partenza (INIZIALI) o il limite
 * più vicino: la pagina della richiesta non si rompe mai per le impostazioni. Costanti::MAX_* restano solo come valori di partenza.
 */
final class ImpostazioniPubblicazioni
{
    /** I valori di partenza: quelli di prima di AM3, tranne chi può inviare (prima solo i docenti). */
    public const INIZIALI = [
        'chi_puo_inviare' => [Pubblico::DOCENTI, Pubblico::PTA],
        'aperta' => true,
        'messaggio_chiusa' => 'Al momento le richieste di pubblicazione sono chiuse.',
        'testo_introduttivo' => 'Richiesta di pubblicazione su pagina web e canali social istituzionali DiBEST, indirizzata al Direttore.',
        'testo_dopo_invio' => 'La richiesta è arrivata all\'ufficio comunicazione.',
        'max_sottotitolo' => Costanti::MAX_SOTTOTITOLO,
        'max_descrizione' => Costanti::MAX_DESCRIZIONE,
        'max_allegati' => Costanti::MAX_ALLEGATI,
        'max_immagini' => Costanti::MAX_IMMAGINI,
        'allegati_obbligatori' => true,
        'giorni_minimi' => 0,
        'indirizzi' => [Costanti::EMAIL_PREDEFINITA],
        'ricevuta_al_richiedente' => true,
    ];

    /** Le parti della scheda «Impostazioni», con le impostazioni di ognuna (la scheda le disegna in quest'ordine; l'abilitato parziale vede solo «email»). */
    public const SEZIONI = [
        'chi' => ['chi_puo_inviare'],
        'apertura' => ['aperta', 'messaggio_chiusa'],
        'testi' => ['testo_introduttivo', 'testo_dopo_invio'],
        'limiti' => ['max_sottotitolo', 'max_descrizione', 'max_allegati', 'max_immagini', 'allegati_obbligatori', 'giorni_minimi'],
        'email' => ['indirizzi', 'ricevuta_al_richiedente'],
    ];

    /** I nomi delle impostazioni per le persone (errori, registro delle modifiche). */
    public const ETICHETTE = [
        'chi_puo_inviare' => 'Chi può inviare',
        'aperta' => 'Richieste aperte',
        'messaggio_chiusa' => 'Messaggio quando è chiusa',
        'testo_introduttivo' => 'Testo in cima alla pagina',
        'testo_dopo_invio' => 'Testo dopo l\'invio',
        'max_sottotitolo' => 'Caratteri del sottotitolo',
        'max_descrizione' => 'Caratteri della breve descrizione',
        'max_allegati' => 'Numero di allegati',
        'max_immagini' => 'Numero di ulteriori immagini',
        'allegati_obbligatori' => 'Almeno un allegato obbligatorio',
        'giorni_minimi' => 'Giorni di anticipo per la data di inizio pubblicazione',
        'indirizzi' => 'Indirizzi che ricevono le richieste',
        'ricevuta_al_richiedente' => 'Ricevuta a chi invia la richiesta',
    ];

    /** I numeri: [minimo, massimo]. Il sottotitolo sta in una colonna da 100 caratteri; i file per invio restano pochi (il server ne accetta un numero limitato). */
    public const LIMITI = [
        'max_sottotitolo' => [1, 100],
        'max_descrizione' => [1, 2000],
        'max_allegati' => [1, 20],
        'max_immagini' => [1, 20],
        'giorni_minimi' => [0, 365],
    ];

    /** Il prefisso dei campi di html_scelta_pubblico() nella scheda (le caselle arrivano in pb_chi_profili[]). */
    public const CAMPO_CHI = 'pb_chi';

    private const MAX_MESSAGGIO = 500;
    private const MAX_TESTO = 5000;

    /** Impostazione => colonna di impostazioni_sistema. */
    private const COLONNE = [
        'chi_puo_inviare' => 'pubblicazioni_chi_puo_inviare',
        'aperta' => 'pubblicazioni_aperta',
        'messaggio_chiusa' => 'pubblicazioni_messaggio_chiusa',
        'testo_introduttivo' => 'pubblicazioni_testo_introduttivo',
        'testo_dopo_invio' => 'pubblicazioni_testo_dopo_invio',
        'max_sottotitolo' => 'pubblicazioni_max_sottotitolo',
        'max_descrizione' => 'pubblicazioni_max_descrizione',
        'max_allegati' => 'pubblicazioni_max_allegati',
        'max_immagini' => 'pubblicazioni_max_immagini',
        'allegati_obbligatori' => 'pubblicazioni_allegati_obbligatori',
        'giorni_minimi' => 'pubblicazioni_giorni_minimi',
        'indirizzi' => 'pubblicazioni_email_notifica',
        'ricevuta_al_richiedente' => 'pubblicazioni_ricevuta',
    ];

    private const SI_NO = ['aperta', 'allegati_obbligatori', 'ricevuta_al_richiedente'];

    /** @var array<string, mixed>|null letta una volta per richiesta (si svuota dopo un salvataggio) */
    private ?array $cache = null;

    public function __construct(private Database $db)
    {
    }

    /**
     * Tutte le impostazioni, già ripulite (vedi INIZIALI per le chiavi e i tipi).
     *
     * @return array<string, mixed>
     */
    public function tutte(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        try {
            $riga = $this->db->riga('SELECT * FROM impostazioni_sistema WHERE id = 1') ?? [];
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] impostazioni non lette: ' . $e->getMessage());
            $riga = [];
        }
        $v = self::INIZIALI;
        foreach (self::COLONNE as $k => $col) {
            if (!array_key_exists($col, $riga) || $riga[$col] === null) {
                continue;
            }
            $x = $riga[$col];
            $v[$k] = match (true) {
                $k === 'chi_puo_inviare' => self::profili(explode(',', (string) $x)),
                $k === 'indirizzi' => NotificheForms::indirizzi((string) $x),
                in_array($k, self::SI_NO, true) => (int) $x === 1,
                isset(self::LIMITI[$k]) => max(self::LIMITI[$k][0], min(self::LIMITI[$k][1], (int) $x)),
                default => (string) $x,
            };
        }

        return $this->cache = $v;
    }

    /** @return list<string> i profili di Pubblico che possono inviare la richiesta */
    public function chiPuoInviare(): array
    {
        return $this->tutte()['chi_puo_inviare'];
    }

    /** Chi può inviare, in parole (dalle etichette di Pubblico): «Docenti, Personale tecnico amministrativo». */
    public function chiInParole(): string
    {
        return self::inParole($this->chiPuoInviare());
    }

    public function aperta(): bool
    {
        return $this->tutte()['aperta'];
    }

    public function messaggioChiusa(): string
    {
        return $this->tutte()['messaggio_chiusa'];
    }

    /** @return list<string> gli indirizzi validi salvati (quello predefinito se la colonna non c'è ancora o la riga manca) */
    public function indirizzi(): array
    {
        return $this->tutte()['indirizzi'];
    }

    /** Se a chi invia la richiesta arriva la ricevuta per email. */
    public function ricevuta(): bool
    {
        return $this->tutte()['ricevuta_al_richiedente'];
    }

    /**
     * I limiti per RegolePubblicazione::valida e per i contatori della pagina.
     *
     * @return array{max_sottotitolo: int, max_descrizione: int, max_allegati: int, max_immagini: int, allegati_obbligatori: bool, giorni_minimi: int}
     */
    public function limiti(): array
    {
        return array_intersect_key($this->tutte(), array_flip(self::SEZIONI['limiti']));
    }

    /** I file che una richiesta può avere in tutto (allegati + immagini): serve a LimitiCaricamento per confrontarli con il server. */
    public function fileMassimi(): int
    {
        $l = $this->limiti();

        return $l['max_allegati'] + $l['max_immagini'];
    }

    /**
     * Salva le impostazioni indicate in $dati (le altre restano come sono): se anche una sola non va, non si salva niente.
     * Ritorna gli errori campo per campo e i cambiamenti (vecchio → nuovo) per il registro.
     *
     * @param array<string, mixed> $dati impostazione => valore (vedi daPost)
     * @return array{errori: array<string, string>, cambi: array<string, array{da: mixed, a: mixed}>}
     */
    public function aggiorna(array $dati): array
    {
        [$valori, $errori] = self::valida($dati);
        if ($errori) {
            return ['errori' => $errori, 'cambi' => []];
        }
        $attuali = $this->tutte();
        $cambi = [];
        $set = [];
        $par = [];
        foreach ($valori as $k => $nuovo) {
            if ($nuovo === $attuali[$k]) {
                continue;
            }
            $cambi[$k] = ['da' => $attuali[$k], 'a' => $nuovo];
            $set[] = self::COLONNE[$k] . ' = ?';
            $par[] = match (true) {
                $k === 'chi_puo_inviare' => implode(',', $nuovo),
                $k === 'indirizzi' => implode(', ', $nuovo),
                in_array($k, self::SI_NO, true) => $nuovo ? 1 : 0,
                default => $nuovo,
            };
        }
        if ($set) {
            if ($this->db->esegui('UPDATE impostazioni_sistema SET ' . implode(', ', $set) . ' WHERE id = 1', $par) < 0) {
                return ['errori' => ['_' => 'Non è stato possibile salvare le impostazioni: riprova tra qualche minuto.'], 'cambi' => []];
            }
            $this->cache = null;
        }

        return ['errori' => [], 'cambi' => $cambi];
    }

    /**
     * Solo gli indirizzi (come prima di AM3). Ritorna l'errore da mostrare (null se salvato): serve almeno un indirizzo e tutti devono essere validi.
     */
    public function salva(string $elenco): ?string
    {
        $e = $this->aggiorna(['indirizzi' => $elenco])['errori'];

        return $e ? (string) reset($e) : null;
    }

    /**
     * Le impostazioni delle sezioni indicate, lette dal modulo della scheda (una casella non spuntata vuol dire «no»).
     *
     * @param array<string, mixed> $post
     * @param list<string> $sezioni chiavi di SEZIONI
     * @return array<string, mixed>
     */
    public static function daPost(array $post, array $sezioni): array
    {
        $out = [];
        foreach ($sezioni as $s) {
            foreach (self::SEZIONI[$s] ?? [] as $k) {
                $out[$k] = match (true) {
                    $k === 'chi_puo_inviare' => (array) ($post[self::CAMPO_CHI . '_profili'] ?? []),
                    in_array($k, self::SI_NO, true) => !empty($post[$k]),
                    default => (string) ($post[$k] ?? ''),
                };
            }
        }

        return $out;
    }

    /**
     * Controlla e ripulisce le impostazioni presenti in $dati (le chiavi sconosciute si ignorano). Senza database: si prova da sola.
     *
     * @param array<string, mixed> $dati
     * @return array{0: array<string, mixed>, 1: array<string, string>} valori ripuliti ed errori campo per campo
     */
    public static function valida(array $dati): array
    {
        $v = [];
        $e = [];
        foreach ($dati as $k => $x) {
            if (!isset(self::COLONNE[$k])) {
                continue;
            }
            if ($k === 'chi_puo_inviare') {
                $v[$k] = self::profili(array_map('strval', (array) $x));
                if ($v[$k] === []) {
                    $e[$k] = 'Scegli almeno un profilo che può inviare la richiesta.';
                }
            } elseif (in_array($k, self::SI_NO, true)) {
                $v[$k] = (bool) $x;
            } elseif (isset(self::LIMITI[$k])) {
                [$min, $max] = self::LIMITI[$k];
                $s = trim((string) $x);
                if ($s === '' || !ctype_digit($s) || (int) $s < $min || (int) $s > $max) {
                    $e[$k] = self::ETICHETTE[$k] . ': scrivi un numero intero da ' . $min . ' a ' . $max . '.';
                } else {
                    $v[$k] = (int) $s;
                }
            } elseif ($k === 'indirizzi') {
                $elenco = (string) $x;
                $sbagliati = array_filter(preg_split('/[\s,;]+/', $elenco, -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn (string $a): bool => filter_var(trim($a), FILTER_VALIDATE_EMAIL) === false);
                $v[$k] = NotificheForms::indirizzi($elenco);
                if ($sbagliati) {
                    $e[$k] = 'Questi indirizzi non sono validi: ' . implode(', ', $sbagliati) . '.';
                } elseif ($v[$k] === []) {
                    $e[$k] = 'Indica almeno un indirizzo email valido.';
                }
            } elseif ($k === 'messaggio_chiusa') {
                $v[$k] = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $x)));
                if ($v[$k] === '') {
                    $e[$k] = 'Scrivi il messaggio che si vede quando le richieste sono chiuse.';
                } elseif (mb_strlen($v[$k]) > self::MAX_MESSAGGIO) {
                    $e[$k] = 'Il messaggio è troppo lungo (massimo ' . self::MAX_MESSAGGIO . ' caratteri).';
                }
            } else {
                // i due testi in HTML semplice; quello dopo l'invio non può restare vuoto (chi invia deve leggere che la richiesta è arrivata)
                $v[$k] = Testi::pulisciHtmlSemplice((string) $x);
                if ($k === 'testo_dopo_invio' && RegolePubblicazione::lunghezzaTesto($v[$k]) === 0) {
                    $e[$k] = 'Scrivi il testo che si vede dopo l\'invio.';
                } elseif (mb_strlen($v[$k]) > self::MAX_TESTO) {
                    $e[$k] = self::ETICHETTE[$k] . ': il testo è troppo lungo (massimo ' . self::MAX_TESTO . ' caratteri, formattazione compresa).';
                }
            }
        }

        return [$v, $e];
    }

    /** I profili in parole, dalle etichette di Pubblico, separati da virgola. @param list<string> $profili */
    public static function inParole(array $profili): string
    {
        return implode(', ', array_map(static fn (string $p): string => Pubblico::ETICHETTE[$p] ?? $p, $profili));
    }

    /**
     * I cambiamenti in parole per il registro delle attività: «Chi può inviare» => «Docenti → Docenti, Personale tecnico amministrativo».
     *
     * @param array<string, array{da: mixed, a: mixed}> $cambi da aggiorna()
     * @return array<string, string>
     */
    public static function cambiInParole(array $cambi): array
    {
        $parola = static fn (string $k, mixed $x): string => match (true) {
            $k === 'chi_puo_inviare' => self::inParole($x),
            $k === 'indirizzi' => implode(', ', $x),
            is_bool($x) => $x ? 'sì' : 'no',
            is_int($x) => (string) $x,
            default => mb_strlen((string) $x) > 300 ? mb_substr((string) $x, 0, 300) . '…' : (string) $x,
        };
        $out = [];
        foreach ($cambi as $k => ['da' => $da, 'a' => $a]) {
            $out[self::ETICHETTE[$k] ?? $k] = $parola($k, $da) . ' → ' . $parola($k, $a);
        }

        return $out;
    }

    /** @param list<string> $scelti @return list<string> i profili validi, nell'ordine di Pubblico, senza «anonimi» (serve sempre l'accesso) */
    private static function profili(array $scelti): array
    {
        $scelti = array_map('trim', $scelti);

        return array_values(array_filter(Pubblico::PROFILI, static fn (string $p): bool => $p !== Pubblico::ANONIMI && in_array($p, $scelti, true)));
    }
}
