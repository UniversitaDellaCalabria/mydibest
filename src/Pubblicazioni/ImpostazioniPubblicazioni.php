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
 * - note_operative: testo semplice, una nota per riga nella forma «Titolo: testo» (le mostra il primo passo della richiesta);
 * - pagine_pubblicazione: «Dove vuoi che si pubblichi?», una pagina o un canale per riga nella forma «Nome | indirizzo»; VUOTO di partenza (lo riempie
 *   l'amministratore): con l'elenco vuoto il campo URL della richiesta resta un indirizzo libero;
 * - limiti: max_sottotitolo, max_descrizione (caratteri), max_allegati, max_immagini (file), allegati_obbligatori, giorni_minimi (anticipo della data
 *   di inizio pubblicazione: 0 = da oggi in poi, come prima);
 * - email: indirizzi (che ricevono le richieste: stesse regole di MyForms, NotificheForms::indirizzi) e ricevuta_al_richiedente;
 * - approvazione_attiva: se le richieste nascono «in attesa di approvazione» (v146, SPENTA di partenza). Non si accende senza almeno un approvatore con account
 *   collegato (PersonePubblicazioni), e anche accesa vale solo finché ce n'è uno: ServizioPubblicazioni::invia ricontrolla ogni volta, così niente resta in attesa di nessuno.
 *   Le tre liste di persone (approvatori, ufficio, conoscenza) NON stanno qui: le gestisce PersonePubblicazioni.
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
        'note_operative' => "Invio richieste: le richieste di pubblicazione su pagine web e social istituzionali devono essere inviate esclusivamente da questo modulo.\n"
            . "Compilazione: compila con attenzione tutti i campi. Le informazioni inviate non saranno modificate: è opportuno controllare che il testo sia corretto e completo prima di inviare.\n"
            . "Materiale illustrativo: le immagini da pubblicare insieme al testo devono essere in formato .jpg oppure .png. Non saranno estratte né pubblicate le immagini contenute dentro i file .pdf.\n"
            . 'Allegati: i file PDF saranno pubblicati come allegati con il nome «Locandina_titolo_breve».',
        'pagine_pubblicazione' => '',
        'max_sottotitolo' => Costanti::MAX_SOTTOTITOLO,
        'max_descrizione' => Costanti::MAX_DESCRIZIONE,
        'max_allegati' => Costanti::MAX_ALLEGATI,
        'max_immagini' => Costanti::MAX_IMMAGINI,
        'allegati_obbligatori' => false,
        'giorni_minimi' => 0,
        'indirizzi' => [Costanti::EMAIL_PREDEFINITA],
        'ricevuta_al_richiedente' => true,
        'approvazione_attiva' => false,
    ];

    /** Le parti della scheda «Impostazioni», con le impostazioni di ognuna (la scheda le disegna in quest'ordine; l'abilitato parziale vede solo «email»). */
    public const SEZIONI = [
        'chi' => ['chi_puo_inviare'],
        'apertura' => ['aperta', 'messaggio_chiusa'],
        'testi' => ['testo_introduttivo', 'testo_dopo_invio', 'note_operative', 'pagine_pubblicazione'],
        'limiti' => ['max_sottotitolo', 'max_descrizione', 'max_allegati', 'max_immagini', 'allegati_obbligatori', 'giorni_minimi'],
        'email' => ['indirizzi', 'ricevuta_al_richiedente'],
        // salvata a parte (con le tre liste di persone, nella parte «Chi approva, chi lavora, chi è informato»): non fa parte del «Salva» della scheda principale
        'approvazione' => ['approvazione_attiva'],
    ];

    /** Le sezioni salvate dal «Salva» della scheda principale delle impostazioni (tutte tranne l'approvazione, che ha il suo modulo). @return list<string> */
    public static function sezioniPrincipali(): array
    {
        return array_values(array_diff(array_keys(self::SEZIONI), ['approvazione']));
    }

    /** I nomi delle impostazioni per le persone (errori, registro delle modifiche). */
    public const ETICHETTE = [
        'chi_puo_inviare' => 'Chi può inviare',
        'aperta' => 'Richieste aperte',
        'messaggio_chiusa' => 'Messaggio quando è chiusa',
        'testo_introduttivo' => 'Testo in cima alla pagina',
        'testo_dopo_invio' => 'Testo dopo l\'invio',
        'note_operative' => 'Note operative',
        'pagine_pubblicazione' => 'Pagine e canali dove pubblicare',
        'max_sottotitolo' => 'Caratteri del sottotitolo',
        'max_descrizione' => 'Caratteri della breve descrizione',
        'max_allegati' => 'Numero di altri allegati',
        'max_immagini' => 'Numero di ulteriori immagini',
        'allegati_obbligatori' => 'Almeno un altro allegato obbligatorio',
        'giorni_minimi' => 'Giorni di anticipo per la data di inizio pubblicazione',
        'indirizzi' => 'Indirizzi che ricevono le richieste',
        'ricevuta_al_richiedente' => 'Ricevuta a chi invia la richiesta',
        'approvazione_attiva' => 'Approvazione accesa',
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
    private const MAX_NOTE_OPERATIVE = 3000;
    private const MAX_PAGINE = 30;

    /** Impostazione => colonna di impostazioni_sistema. */
    private const COLONNE = [
        'chi_puo_inviare' => 'pubblicazioni_chi_puo_inviare',
        'aperta' => 'pubblicazioni_aperta',
        'messaggio_chiusa' => 'pubblicazioni_messaggio_chiusa',
        'testo_introduttivo' => 'pubblicazioni_testo_introduttivo',
        'testo_dopo_invio' => 'pubblicazioni_testo_dopo_invio',
        'note_operative' => 'pubblicazioni_note_operative',
        'pagine_pubblicazione' => 'pubblicazioni_pagine',
        'max_sottotitolo' => 'pubblicazioni_max_sottotitolo',
        'max_descrizione' => 'pubblicazioni_max_descrizione',
        'max_allegati' => 'pubblicazioni_max_allegati',
        'max_immagini' => 'pubblicazioni_max_immagini',
        'allegati_obbligatori' => 'pubblicazioni_allegati_obbligatori',
        'giorni_minimi' => 'pubblicazioni_giorni_minimi',
        'indirizzi' => 'pubblicazioni_email_notifica',
        'ricevuta_al_richiedente' => 'pubblicazioni_ricevuta',
        'approvazione_attiva' => 'pubblicazioni_approvazione_attiva',
    ];

    private const SI_NO = ['aperta', 'allegati_obbligatori', 'ricevuta_al_richiedente', 'approvazione_attiva'];

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

    /** Se l'approvazione è accesa nelle impostazioni (da sola non basta: serve anche un approvatore con account, lo controlla ServizioPubblicazioni::invia). */
    public function approvazioneAttiva(): bool
    {
        return $this->tutte()['approvazione_attiva'];
    }

    /** Se a chi invia la richiesta arriva la ricevuta per email. */
    public function ricevuta(): bool
    {
        return $this->tutte()['ricevuta_al_richiedente'];
    }

    /**
     * Le note operative del primo passo, nell'ordine scritto. Se l'impostazione è vuota (database a metà) valgono quelle di partenza.
     *
     * @return list<array{titolo: string, testo: string}>
     */
    public function noteOperative(): array
    {
        return self::righeNoteOperative($this->tutte()['note_operative']) ?: self::righeNoteOperative(self::INIZIALI['note_operative']);
    }

    /**
     * «Titolo: testo» per riga → titolo e testo (senza i due punti: li aggiunge la pagina). Una riga senza «:» è tutta testo. Le righe vuote si saltano.
     *
     * @return list<array{titolo: string, testo: string}>
     */
    public static function righeNoteOperative(string $testo): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $testo) ?: [] as $riga) {
            $riga = trim($riga);
            if ($riga === '') {
                continue;
            }
            $i = mb_strpos($riga, ':');
            $out[] = $i === false ? ['titolo' => '', 'testo' => $riga] : ['titolo' => trim(mb_substr($riga, 0, $i)), 'testo' => trim(mb_substr($riga, $i + 1))];
        }

        return $out;
    }

    /**
     * Le pagine e i canali dove si può chiedere di pubblicare («Dove vuoi che si pubblichi?»), nell'ordine scritto. Vuoto = campo libero come prima.
     *
     * @return list<array{nome: string, indirizzo: string}>
     */
    public function pagine(): array
    {
        return self::righePagine($this->tutte()['pagine_pubblicazione']);
    }

    /**
     * «Nome | indirizzo» per riga → nome e indirizzo. Le righe senza barra o con l'indirizzo vuoto si saltano (le segnala valida()).
     *
     * @return list<array{nome: string, indirizzo: string}>
     */
    public static function righePagine(string $testo): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $testo) ?: [] as $riga) {
            $i = mb_strrpos($riga, '|');
            if ($i === false) {
                continue;
            }
            $nome = trim(mb_substr($riga, 0, $i));
            $indirizzo = trim(mb_substr($riga, $i + 1));
            if ($nome !== '' && $indirizzo !== '') {
                $out[] = ['nome' => $nome, 'indirizzo' => $indirizzo];
            }
        }

        return $out;
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

    /** I file che una richiesta può avere in tutto (la locandina + allegati + immagini): serve a LimitiCaricamento per confrontarli con il server. */
    public function fileMassimi(): int
    {
        $l = $this->limiti();

        return 1 + $l['max_allegati'] + $l['max_immagini'];
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
        if (($valori['approvazione_attiva'] ?? false) && !$attuali['approvazione_attiva'] && PersonePubblicazioni::contaApprovatoriConAccount($this->db) === 0) {
            return ['errori' => ['approvazione_attiva' => 'Per accendere l\'approvazione serve almeno un approvatore con un account collegato: sceglilo nell\'elenco «Chi approva» e fagli fare il primo accesso al portale.'], 'cambi' => []];
        }
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
            } elseif ($k === 'note_operative') {
                // testo semplice, una nota per riga: niente tag, righe vuote tolte
                $righe = array_values(array_filter(array_map(static fn (string $r): string => trim(strip_tags($r)), preg_split('/\R/u', (string) $x) ?: []), static fn (string $r): bool => $r !== ''));
                $v[$k] = implode("\n", $righe);
                if ($v[$k] === '') {
                    $e[$k] = 'Scrivi almeno una nota operativa (una per riga, nella forma «Titolo: testo»).';
                } elseif (mb_strlen($v[$k]) > self::MAX_NOTE_OPERATIVE) {
                    $e[$k] = self::ETICHETTE[$k] . ': il testo è troppo lungo (massimo ' . self::MAX_NOTE_OPERATIVE . ' caratteri).';
                }
            } elseif ($k === 'pagine_pubblicazione') {
                [$v[$k], $errorePagine] = self::validaPagine((string) $x);
                if ($errorePagine !== null) {
                    $e[$k] = $errorePagine;
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

    /**
     * L'elenco «Nome | indirizzo» ripulito (una riga per pagina) e il primo errore (null se va bene). Vuoto è ammesso: il campo resta libero.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function validaPagine(string $testo): array
    {
        $righe = [];
        foreach (preg_split('/\R/u', $testo) ?: [] as $i => $riga) {
            $riga = trim(strip_tags($riga));
            if ($riga === '') {
                continue;
            }
            $barra = mb_strrpos($riga, '|');
            $nome = $barra === false ? '' : trim(mb_substr($riga, 0, $barra));
            $indirizzo = $barra === false ? '' : trim(mb_substr($riga, $barra + 1));
            if ($nome === '' || $indirizzo === '') {
                return [implode("\n", $righe), 'Riga ' . ($i + 1) . ': scrivi «Nome | indirizzo» (per esempio «Home del DiBEST | https://dibest.unical.it»).'];
            }
            if (mb_strlen($nome) > 100) {
                return [implode("\n", $righe), 'Riga ' . ($i + 1) . ': il nome è troppo lungo (massimo 100 caratteri).'];
            }
            if (!RegolePubblicazione::url($indirizzo)) {
                return [implode("\n", $righe), 'Riga ' . ($i + 1) . ': l\'indirizzo deve essere completo e iniziare con http:// o https://.'];
            }
            $righe[] = $nome . ' | ' . $indirizzo;
        }
        if (count($righe) > self::MAX_PAGINE) {
            return [implode("\n", $righe), 'Le pagine sono troppe: al massimo ' . self::MAX_PAGINE . '.'];
        }

        return [implode("\n", $righe), null];
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
