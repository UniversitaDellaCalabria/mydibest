<?php

declare(strict_types=1);

namespace App\Protocollo;

use Throwable;

/**
 * Ciò che serve a ogni punto del portale che protocolla con Titulus al posto del numero scritto a mano: dice se il pulsante va mostrato
 * e ottiene il numero di protocollo senza mai protocollare due volte lo stesso documento (se per quel documento Titulus ha già dato un
 * numero e la registrazione nel portale non è andata a buon fine, il numero si riusa).
 */
final class InvioProtocollo
{
    public function __construct(private ServizioProtocollo $protocollo, private ProtocolloRepository $registro)
    {
    }

    /** Collegamento pronto e regola attiva per quel contesto (o per il suo padre, o quella generale). */
    public function disponibile(string $applicaA): bool
    {
        try {
            return $this->protocollo->nonPronto() === null && $this->registro->regolaPer($applicaA) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Il riferimento di un documento firmato dal punto di vista di Titulus: il tipo di base e le prime cifre dell'impronta (SHA-256) del file
     * che parte. Se il file cambia (la scuola o l'azienda lo ricarica dopo un rifiuto, il Direttore firma di nuovo) il riferimento cambia e
     * Titulus dà un numero nuovo; lo stesso file ha sempre lo stesso riferimento, quindi non si protocolla mai due volte.
     * Resta nei 40 caratteri della colonna rif_tipo: la base non supera i 29. Il BLOCCO dell'invio, invece, è per documento (tipo di base + id):
     * l'impronta sta solo nel riferimento del registro.
     */
    public static function rifPerFile(string $base, string $contenuto): string
    {
        return $base . '~' . substr(hash('sha256', $contenuto), 0, 10);
    }

    /**
     * Il numero che Titulus ha già dato a quel documento ('' = nessuno): i file sono già partiti e non si possono più cambiare.
     * I numeri dati prima che il riferimento comprendesse l'impronta stanno sotto il tipo di base: valgono per il file solo se sono stati
     * dati DOPO che quel file era stato caricato ($baseVecchio e $fileDal, il momento del caricamento); se il file è stato ricaricato
     * dopo il numero, il numero era di un altro file.
     */
    public function giaAssegnato(string $rifTipo, int $rifId, string $baseVecchio = '', ?string $fileDal = null): string
    {
        return $this->assegnato($rifTipo, $rifId, $baseVecchio, $fileDal)['numero'] ?? '';
    }

    /**
     * Come giaAssegnato(), con il momento in cui Titulus ha dato il numero (la data di protocollo è quella dell'invio riuscito, non quella di oggi).
     *
     * @return array{numero: string, il: string}|null
     */
    public function assegnato(string $rifTipo, int $rifId, string $baseVecchio = '', ?string $fileDal = null): ?array
    {
        foreach ($this->registro->invii(50, $rifTipo, $rifId) as $i) {
            if ((string) $i['esito'] === 'ok' && (string) $i['num_prot'] !== '') {
                return ['numero' => (string) $i['num_prot'], 'il' => (string) $i['creato_il']];
            }
        }
        if ($baseVecchio !== '' && $baseVecchio !== $rifTipo && $fileDal !== null && $fileDal !== '') {
            foreach ($this->registro->invii(50, $baseVecchio, $rifId) as $i) {
                if ((string) $i['esito'] === 'ok' && (string) $i['num_prot'] !== '' && (string) $i['creato_il'] > $fileDal) {
                    return ['numero' => (string) $i['num_prot'], 'il' => (string) $i['creato_il']];
                }
            }
        }

        return null;
    }

    /** Un invio che risulta «in corso» da più di tanto senza che nessuno lo stia facendo è un processo morto: l'esito è incerto. */
    private const SCADENZA_IN_INVIO = 600;

    /** Un invio di questo documento è in corso adesso (un processo ha il blocco del documento, qualunque sia il file)? */
    public function inCorso(string $rifTipo, int $rifId): bool
    {
        return $this->registro->invioInCorso($rifTipo, $rifId);
    }

    /**
     * Com'è messo l'invio di un documento (si legge dal registro di TUTTI i suoi file, dal più recente):
     * - «protocollato»: c'è un numero dato a questo file;
     * - «precedente»: c'è un numero, ma dato a un file di prima della stessa compilazione (si mostra all'ufficio, ferma il protocollo automatico);
     * - «in_invio»: un processo sta parlando con Titulus adesso;
     * - «incerto»: la risposta non è arrivata, o il processo è morto a metà (per questo file o per uno di prima): non si sa se Titulus ha protocollato;
     *   si chiude solo con una verifica in Titulus: chiudiIncerto() o un numero scritto a mano;
     * - «in_approvazione»: Titulus ha preso il documento ma il numero non c'è (iter di approvazione): non si rimanda; si chiude con il numero scritto
     *   a mano o dichiarando che è stato respinto (respinto());
     * - «libero»: si può inviare.
     * $baseVecchio e $fileDal: i numeri dati prima dell'impronta valgono come in giaAssegnato().
     *
     * @return array{stato: string, numero: string, il: string}
     */
    public function stato(string $rifTipo, int $rifId, string $baseVecchio = '', ?string $fileDal = null): array
    {
        $righe = $this->registro->inviiDelDocumento($rifTipo, $rifId, 100);
        // un numero dato proprio a questo file (anche con il riferimento vecchio, se è stato dato dopo il caricamento del file)
        foreach ($righe as $i) {
            if ((string) $i['esito'] !== 'ok' || (string) $i['num_prot'] === '') {
                continue;
            }
            $riferimento = (string) $i['rif_tipo'];
            $vecchioValido = $baseVecchio !== '' && $riferimento === $baseVecchio && $baseVecchio !== $rifTipo && $fileDal !== null && $fileDal !== '' && (string) $i['creato_il'] > $fileDal;
            if ($riferimento === $rifTipo || $vecchioValido) {
                return ['stato' => 'protocollato', 'numero' => (string) $i['num_prot'], 'il' => (string) $i['creato_il']];
            }
        }
        return self::statoDaRighe($righe, fn (): bool => $this->registro->invioInCorso($rifTipo, $rifId));
    }

    /**
     * Lo stato di un documento dal solo registro dei suoi invii (righe dal più recente), senza il «numero dato proprio a questo file»: incerto, in_invio,
     * precedente (c'è un numero), in_approvazione o libero. È la stessa regola di stato() e la usa anche l'elenco delle scuole, che guarda molte
     * compilazioni con una query sola e perciò non conosce l'impronta del file di ciascuna.
     *
     * @param list<array<string, mixed>> $righe
     * @param callable(): bool $invioInCorso un processo ha il blocco del documento adesso? (chiamata solo se serve)
     * @return array{stato: string, numero: string, il: string}
     */
    public static function statoDaRighe(array $righe, callable $invioInCorso): array
    {
        foreach ($righe as $i) {
            $esito = (string) $i['esito'];
            if ($esito === 'verificato' || $esito === 'respinto') {
                break;
            }
            if ($esito === 'incerto') {
                return ['stato' => 'incerto', 'numero' => '', 'il' => (string) $i['creato_il']];
            }
            if ($esito === 'in_invio') {
                $inCorso = $invioInCorso() && time() - (int) strtotime((string) $i['creato_il']) < self::SCADENZA_IN_INVIO;

                return ['stato' => $inCorso ? 'in_invio' : 'incerto', 'numero' => '', 'il' => (string) $i['creato_il']];
            }
            if ($esito === 'ok') {
                if ((string) $i['num_prot'] !== '') {
                    return ['stato' => 'precedente', 'numero' => (string) $i['num_prot'], 'il' => (string) $i['creato_il']];
                }

                return ['stato' => 'in_approvazione', 'numero' => '', 'il' => (string) $i['creato_il']];
            }
        }

        return ['stato' => 'libero', 'numero' => '', 'il' => ''];
    }

    /** Il testo da far leggere all'ufficio quando l'esito è incerto (con l'ora dell'ultimo invio). */
    public static function testoIncerto(string $il): string
    {
        return "L'ultimo invio a Titulus" . ($il !== '' ? ' (' . date('d/m/Y H:i', (int) strtotime($il)) . ')' : '') . ' ha un esito INCERTO: non è arrivata la risposta, quindi non si sa se il documento è stato protocollato. '
            . "Il portale non riprova da solo, per non fare due protocolli: cerca il documento in Titulus; se c'è scrivi qui il suo numero, se non c'è conferma e riprova.";
    }

    /** Il testo per l'ufficio quando Titulus ha preso il documento ma il numero non c'è ancora (iter di approvazione). */
    public static function testoInApprovazione(string $il): string
    {
        return 'Il documento è stato inviato a Titulus' . ($il !== '' ? ' il ' . date('d/m/Y H:i', (int) strtotime($il)) : '') . " e aspetta l'approvazione: il numero di protocollo non c'è ancora. "
            . "Il portale non lo rimanda, per non fare due documenti. Quando Titulus assegna il numero, scrivilo a mano; se in Titulus è stato respinto, dichiaralo e potrai rimandarlo.";
    }

    /**
     * Perché il protocollo AUTOMATICO di un documento si ferma, scritto per l'ufficio (chi lo mostra ci premette «Il protocollo automatico in Titulus si è fermato:») (null = può andare avanti: libero, o con un numero già dato che
     * si registra). Si ferma con l'esito incerto, con l'iter di approvazione in corso e con un invio in corso: il pulsante e il numero a mano restano
     * all'ufficio, che chiude l'incertezza con «Non c'è in Titulus: riprova» o con «Respinto».
     *
     * @param array{stato: string, numero: string, il: string} $stato quello di stato()
     */
    public static function motivoDiFermo(array $stato): ?string
    {
        return match ($stato['stato']) {
            'incerto' => self::testoIncerto($stato['il']) . " Se in Titulus il documento non c'è, premi «Non c'è in Titulus: riprova».",
            'in_approvazione' => self::testoInApprovazione($stato['il']) . ' Se in Titulus è stato respinto, premi «Respinto».',
            'in_invio' => 'Un invio a Titulus di questo documento è già in corso. Controlla il registro degli invii.',
            default => null,
        };
    }

    /** Il testo per l'ufficio quando Titulus aveva già dato un numero a un file di prima della stessa compilazione. */
    public static function testoPrecedente(string $numero, string $il): string
    {
        return 'Titulus aveva già dato il numero ' . $numero . ($il !== '' ? ' (' . date('d/m/Y', (int) strtotime($il)) . ')' : '') . ' a un file di prima di questa compilazione: il file di adesso è diverso. '
            . 'Il protocollo automatico non parte: controlla e, se il file nuovo va protocollato, usa «Invia al protocollo» (avrà un numero suo) oppure scrivi il numero a mano.';
    }

    /**
     * L'ufficio ha cercato in Titulus e il documento non c'è: l'incertezza è chiusa e si può inviare di nuovo. Si fa con la prenotazione del documento in
     * mano e solo se l'esito è davvero incerto: se un invio è in corso, o c'è già un numero, non si chiude niente.
     *
     * @return string|null il motivo per cui non si è chiuso, null se è chiuso
     */
    public function chiudiIncerto(string $rifTipo, int $rifId, string $nota, ?int $utenteId = null): ?string
    {
        return $this->conBlocco($rifTipo, $rifId, fn (): ?string => $this->chiudiSottoBlocco($rifTipo, $rifId, 'verificato', ['incerto'], 'Verifica in Titulus', $nota, $utenteId));
    }

    /**
     * L'iter di approvazione si è concluso con un rifiuto in Titulus: il documento non esiste più e si può rimandare. Solo se l'invio è «in approvazione».
     *
     * @return string|null il motivo per cui non si è chiuso, null se è chiuso
     */
    public function respinto(string $rifTipo, int $rifId, string $nota, ?int $utenteId = null): ?string
    {
        return $this->conBlocco($rifTipo, $rifId, fn (): ?string => $this->chiudiSottoBlocco($rifTipo, $rifId, 'respinto', ['in_approvazione'], 'Respinto in Titulus', $nota, $utenteId));
    }

    /** @param callable(): ?string $f */
    private function conBlocco(string $rifTipo, int $rifId, callable $f): ?string
    {
        if (!$this->registro->bloccaInvio($rifTipo, $rifId)) {
            return 'Un invio a Titulus di questo documento è in corso: aspetta qualche secondo e riprova.';
        }
        try {
            return $f();
        } finally {
            $this->registro->rilasciaInvio($rifTipo, $rifId);
        }
    }

    /**
     * Con il blocco del documento in mano: scrive la riga che chiude l'incertezza o l'approvazione, se lo stato è uno di $ammessi.
     * Un «in_invio» ancora nel registro, mentre il blocco è nostro, è di un processo morto: vale come incerto.
     *
     * @param list<string> $ammessi
     */
    private function chiudiSottoBlocco(string $rifTipo, int $rifId, string $esito, array $ammessi, string $oggetto, string $nota, ?int $utenteId): ?string
    {
        $st = $this->stato($rifTipo, $rifId);
        if ($st['stato'] === 'in_invio') {
            $st['stato'] = 'incerto';
        }
        if ($st['stato'] === 'protocollato') {
            return 'Titulus ha già dato a questo documento il numero ' . $st['numero'] . ": non c'è niente da chiudere.";
        }
        if (!in_array($st['stato'], $ammessi, true)) {
            return $esito === 'respinto' ? "Il documento non risulta «in approvazione»: non c'è niente da dichiarare respinto." : "Non c'è nessun esito incerto da chiudere.";
        }
        $this->registro->segnaInvio($rifTipo, $rifId, $esito, $oggetto, $nota, $utenteId);

        return null;
    }

    /**
     * Esegue $f con la prenotazione del documento in mano: nessun altro processo (invio automatico, pulsante, numero a mano) può inviare o registrare
     * lo stesso documento nel frattempo. Il numero scritto a mano ($numeroAMano) non si accetta se Titulus ne ha già dato uno diverso e nemmeno
     * mentre un invio è in corso. Se l'esito era incerto, o il documento era in approvazione, lo decide l'ufficio con quel numero: ma l'incertezza si chiude
     * SOLO se $f ha registrato davvero (valore con 'errore' null, nessuna eccezione): un numero rifiutato per altri motivi (date, file) lascia tutto com'era.
     *
     * @template T
     * @param callable(): T $f
     * @return array{errore: string|null, valore: T|null}
     */
    public function aMano(string $rifTipo, int $rifId, string $numeroAMano, callable $f, string $baseVecchio = '', ?string $fileDal = null): array
    {
        if (!$this->registro->bloccaInvio($rifTipo, $rifId)) {
            return ['errore' => 'Un invio a Titulus di questo documento è in corso: aspetta qualche secondo e riprova.', 'valore' => null];
        }
        try {
            $st = $this->stato($rifTipo, $rifId, $baseVecchio, $fileDal);
            if ($st['stato'] === 'protocollato' && $st['numero'] !== $numeroAMano) {
                return ['errore' => 'Titulus ha già dato a questo documento il numero ' . $st['numero'] . ': usa quello (un secondo numero sarebbe un secondo protocollo).', 'valore' => null];
            }
            if ($st['stato'] === 'in_invio') {
                // il blocco è nostro: un «in invio» ancora nel registro è di un processo morto, non di uno che sta lavorando
                $st['stato'] = 'incerto';
            }
            $valore = $f();
            $registrato = is_array($valore) && array_key_exists('errore', $valore) && $valore['errore'] === null;
            if ($registrato && in_array($st['stato'], ['incerto', 'in_approvazione'], true)) {
                $this->registro->segnaInvio($rifTipo, $rifId, 'verificato', 'Registrazione a mano', 'Registrato a mano il numero ' . $numeroAMano, null);
            }

            return ['errore' => null, 'valore' => $valore];
        } finally {
            $this->registro->rilasciaInvio($rifTipo, $rifId);
        }
    }

    /**
     * @param string $baseVecchio tipo di riferimento di prima dell'impronta (vedi giaAssegnato()); '' se il riferimento non cambia con il file
     * @param string|null $fileDal momento in cui il file che parte è stato caricato (data e ora come nel database)
     * @return array{numero: string, errore: string|null, incerto?: bool, data?: string}
     *         il numero di protocollo e la data («Y-m-d») dell'invio riuscito (non quella di oggi, se il numero era già stato dato), oppure il motivo per cui non c'è
     *         (già scritto per chi legge); incerto = la risposta di Titulus non è arrivata
     */
    public function numero(RichiestaProtocollo $r, string $baseVecchio = '', ?string $fileDal = null): array
    {
        $gia = $r->rifTipo !== '' && $r->rifId !== null ? $this->assegnato($r->rifTipo, $r->rifId, $baseVecchio, $fileDal) : null;
        if ($gia !== null) {
            return ['numero' => $gia['numero'], 'errore' => null, 'data' => substr($gia['il'], 0, 10)];
        }
        // Prenotazione dell'invio PRIMA di parlare con Titulus: un solo processo alla volta per documento, e se prima c'è stato un esito incerto non si riprova da soli
        $traccia = $r->rifTipo !== '' && $r->rifId !== null;
        $invioId = 0;
        if ($traccia && !$this->registro->bloccaInvio($r->rifTipo, $r->rifId)) {
            return ['numero' => '', 'errore' => 'Un invio a Titulus di questo documento è già in corso (da un altro pulsante o dal protocollo automatico): aspetta qualche secondo e controlla il registro degli invii.', 'incerto' => false];
        }
        try {
            if ($traccia) {
                $gia = $this->assegnato($r->rifTipo, (int) $r->rifId, $baseVecchio, $fileDal);   // riletto con il blocco in mano
                if ($gia !== null) {
                    return ['numero' => $gia['numero'], 'errore' => null, 'data' => substr($gia['il'], 0, 10)];
                }
                $st = $this->stato($r->rifTipo, (int) $r->rifId, $baseVecchio, $fileDal);
                if ($st['stato'] === 'incerto' || $st['stato'] === 'in_invio') {
                    return ['numero' => '', 'errore' => self::testoIncerto($st['il']), 'incerto' => true];
                }
                if ($st['stato'] === 'in_approvazione') {
                    return ['numero' => '', 'errore' => self::testoInApprovazione($st['il']), 'incerto' => false];
                }
                $invioId = $this->registro->segnaInvio($r->rifTipo, (int) $r->rifId, 'in_invio', $r->oggetto, null, $r->utenteId);
            }
            $esito = $this->protocollo->protocolla($r);
            if ($invioId > 0) {
                if (!$esito->ok && $esito->incerto) {
                    $this->registro->cambiaEsitoInvio($invioId, 'incerto', $esito->messaggio);
                } else {
                    $this->registro->togliInvio($invioId);   // il registro ha già la sua riga, riuscita o fallita
                }
            }
            if (!$esito->ok) {
                return ['numero' => '', 'errore' => 'Titulus: ' . $esito->messaggio . ($esito->incerto ? ' ' . self::testoIncerto('') : ''), 'incerto' => $esito->incerto];
            }
            if ($esito->numProt === '') {
                // l'invio è riuscito e il registro ha la riga «ok» senza numero: da adesso il documento è «in approvazione» e non si rimanda
                return ['numero' => '', 'errore' => "La regola avvia l'iter di approvazione (" . $esito->messaggio . ') ' . self::testoInApprovazione(''), 'incerto' => false];
            }

            return ['numero' => $esito->numProt, 'errore' => null, 'data' => date('Y-m-d')];
        } finally {
            if ($traccia) {
                $this->registro->rilasciaInvio($r->rifTipo, (int) $r->rifId);
            }
        }
    }
}
