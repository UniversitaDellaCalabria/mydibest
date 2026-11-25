<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Core\Database;
use App\Eventi\Righe;
use App\Infrastructure\Pdf\MessaggiFirma;

/** Convenzioni e Allegati A compilati online dalle scuole (tabella convenzioni_compilate). */
final class ConvenzioneCompilataRepository
{
    /** Stati in cui una compilazione è «in corso» per l'istituto: né conclusa (protocollata) né unita a un'altra. */
    private const CHIUSE = [Costanti::ITER_PROTOCOLLATA, Costanti::ITER_UNITA];

    public function __construct(private Database $db, private ServizioScuole $scuole)
    {
    }

    /** Attesa massima per il blocco (secondi): oltre, non si procede e si dice di riprovare. */
    private const ATTESA_BLOCCO = 5;

    private ?string $database = null;

    /**
     * Il nome del blocco per una chiave (un istituto, una compilazione...): comprende il nome del database, così due installazioni sullo stesso
     * server non si bloccano a vicenda, ed è un hash perché GET_LOCK accetta al massimo 64 caratteri.
     */
    public static function nomeBlocco(string $chiave, string $database): string
    {
        return 'cvi_' . substr(hash('sha256', $database . '|' . $chiave), 0, 40);
    }

    /** Attesa per chi ha già fatto qualcosa che non si può rifare (la firma del Direttore con un codice OTP già speso): si aspetta di più. */
    public const ATTESA_LUNGA = 45;
    private int $attesaLunga = self::ATTESA_LUNGA;

    /** L'attesa lunga in uso (secondi). */
    public function attesaLunga(): int
    {
        return $this->attesaLunga;
    }

    /** Solo per le prove: accorcia l'attesa lunga, per non aspettare 45 secondi un blocco che resta occupato. */
    public function impostaAttesaLunga(int $secondi): void
    {
        $this->attesaLunga = max(0, $secondi);
    }

    /** true se il blocco è ottenuto (attesa massima ATTESA_BLOCCO secondi, o $attesa); si rilascia con rilascia(). */
    public function blocca(string $chiave, ?int $attesa = null): bool
    {
        return (int) ($this->db->valore('SELECT GET_LOCK(?, ' . max(0, $attesa ?? self::ATTESA_BLOCCO) . ')', [$this->nomeDelBlocco($chiave)]) ?? 0) === 1;
    }

    public function rilascia(string $chiave): void
    {
        $this->db->valore('SELECT RELEASE_LOCK(?)', [$this->nomeDelBlocco($chiave)]);
    }

    private function nomeDelBlocco(string $chiave): string
    {
        $this->database ??= (string) ($this->db->valore('SELECT DATABASE()') ?? '');

        return self::nomeBlocco($chiave, $this->database);
    }

    /**
     * Esegue $f con il blocco preso: due richieste insieme sulla stessa chiave non si sovrappongono. Se il blocco non si ottiene NON si procede:
     * BloccoOccupato (chi chiama risponde «Riprova tra qualche secondo»). Chi scrive rilegge lo stato sotto blocco.
     *
     * @template T
     * @param callable(): T $f
     * @return T
     * @throws BloccoOccupato
     */
    public function sottoBlocco(string $chiave, callable $f, ?int $attesa = null): mixed
    {
        if (!$this->blocca($chiave, $attesa)) {
            throw new BloccoOccupato();
        }
        try {
            return $f();
        } finally {
            $this->rilascia($chiave);
        }
    }

    /**
     * La chiave del blocco di una scuola senza codice meccanografico: la prenotazione da cui nasce la compilazione (è l'unica cosa che c'è già quando si
     * crea). Per tutte le scritture è la stessa: apertura dal codice, programma, salvataggio, caricamento, presa in carico, rinnovo.
     */
    public static function chiavePrenotazione(int $prenotazioneId): string
    {
        return 'prenotazione_' . $prenotazioneId;
    }

    /**
     * Il blocco di una compilazione: quello dell'istituto (tutte le compilazioni di tutte le sedi) o, se la scuola non ha un codice
     * meccanografico, quello della prenotazione da cui è nata (chiavePrenotazione): la stessa chiave di chi l'apre dal codice o la crea dal programma.
     *
     * @param array<string, mixed> $cc
     */
    public function chiaveDi(array $cc): string
    {
        return $this->scuole->codiceIstituto((string) ($cc['scuola_codice'] ?? '')) ?: self::chiavePrenotazione((int) ($cc['prenotazione_id'] ?? 0));
    }

    /**
     * @template T
     * @param array<string, mixed> $cc
     * @param callable(): T $f
     * @return T
     * @throws BloccoOccupato
     */
    public function sottoBloccoDi(array $cc, callable $f, ?int $attesa = null): mixed
    {
        return $this->sottoBlocco($this->chiaveDi($cc), $f, $attesa);
    }

    /**
     * Esegue $f in una transazione (commit se termina, rollback se lancia un'eccezione).
     *
     * @template T
     * @param callable(): T $f
     * @return T
     */
    public function transazione(callable $f): mixed
    {
        return $this->db->transazione(static fn (): mixed => $f());
    }

    /**
     * IL criterio con cui una compilazione «conta» per la scuola (elenco, scheda, avvio della stipula, scuola «occupata»): ha dei dati, oppure ha già un
     * passo dell'iter, oppure l'ha avviata l'ufficio (anche se ancora vuota). Una compilazione mai compilata e non avviata dall'ufficio non conta: chi
     * arriva dopo ne apre una sua e quella vuota resta innocua. Alias della tabella: `cc`.
     */
    public const SQL_CONTA = "((cc.dati_json IS NOT NULL AND cc.dati_json <> '') OR IFNULL(cc.stato_iter, '') <> '' OR cc.avviata_il IS NOT NULL)";
    private const NON_VUOTA = self::SQL_CONTA;

    /** Lo stesso criterio di SQL_CONTA su una riga già letta. @param array<string, mixed> $cc */
    public static function conta(array $cc): bool
    {
        return trim((string) ($cc['dati_json'] ?? '')) !== '' || (string) ($cc['stato_iter'] ?? '') !== '' || !empty($cc['avviata_il']);
    }

    /**
     * Le compilazioni in corso dell'istituto (di qualunque sede), dalla più vecchia. Non ci sono né le concluse (protocollate) né quelle unite;
     * né, di norma, quelle mai compilate ($conVuote le include: serve a chi cerca la compilazione di cui è proprietario, non a chi guarda se la
     * scuola è «occupata»). Una scuola senza codice meccanografico non ha istituto: [].
     *
     * @return list<array<string, string|null>>
     */
    public function inCorsoDellIstituto(string $codiceScuola, bool $conVuote = false): array
    {
        $istituto = $this->scuole->codiceIstituto($codiceScuola);
        if ($istituto === '') {
            return [];
        }

        return Righe::testo($this->db->righe(
            'SELECT cc.* FROM convenzioni_compilate cc
             WHERE ' . ServizioScuole::sqlIstituto('s', 'cc.scuola_codice') . " = ? AND IFNULL(cc.stato_iter, '') NOT IN ('" . implode("','", self::CHIUSE) . "')" . ($conVuote ? '' : ' AND ' . self::NON_VUOTA) . ' ORDER BY cc.id',
            [$istituto]
        ));
    }

    /**
     * Tutte le compilazioni in corso (non protocollate né unite, e già compilate), per cercare le doppie.
     *
     * @return list<array<string, string|null>>
     */
    public function tutteInCorso(): array
    {
        return Righe::testo($this->db->righe("SELECT cc.* FROM convenzioni_compilate cc WHERE IFNULL(cc.stato_iter, '') NOT IN ('" . implode("','", self::CHIUSE) . "') AND cc.scuola_codice IS NOT NULL AND cc.scuola_codice <> '' AND " . self::NON_VUOTA . ' ORDER BY cc.id'));
    }

    /**
     * Gli istituti con più di una compilazione in corso (le doppie), per lo strumento da riga di comando e per l'ufficio.
     *
     * @return list<array{istituto: string, compilazioni: list<array{id: int, email: string, stato_iter: string, attivita: int, in_attesa: int, creata_il: string}>}>
     */
    public function doppie(): array
    {
        $per = [];
        foreach ($this->tutteInCorso() as $r) {
            $istituto = $this->scuole->codiceIstituto((string) ($r['scuola_codice'] ?? ''));
            if ($istituto === '') {
                continue;
            }
            $dati = json_decode((string) ($r['dati_json'] ?? ''), true) ?: [];
            $attesa = json_decode((string) ($r['attivita_in_attesa'] ?? ''), true) ?: [];
            $per[$istituto][] = ['id' => (int) $r['id'], 'email' => (string) ($r['email'] ?? ''), 'stato_iter' => (string) ($r['stato_iter'] ?? ''), 'attivita' => count((array) ($dati['attivita'] ?? [])),
                'in_attesa' => count($attesa), 'creata_il' => (string) ($r['creata_il'] ?? '')];
        }
        ksort($per);
        $out = [];
        foreach ($per as $istituto => $elenco) {
            if (count($elenco) > 1) {
                $out[] = ['istituto' => (string) $istituto, 'compilazioni' => $elenco];
            }
        }

        return $out;
    }

    /**
     * Le email delle compilazioni unite a questa (solo per le righe di prima della v139, che non hanno l'elenco esplicito).
     *
     * @return list<string>
     */
    public function emailUnite(int $id): array
    {
        return array_values(array_filter(array_map(static fn (array $r): string => strtolower(trim((string) $r['email'])), $this->db->righe('SELECT email FROM convenzioni_compilate WHERE unita_in = ?', [$id])), static fn (string $e): bool => $e !== ''));
    }

    /**
     * Le email di chi può aprire la compilazione, dall'elenco esplicito salvato (proprietari_json). Per le righe di prima della v139 (elenco
     * assente): l'email della compilazione, di chi ha fatto la prima prenotazione e delle compilazioni unite. L'email scritta nel modulo (dati della
     * scuola) NON rende proprietari: serve solo per le comunicazioni. Un'email conta solo se chi la presenta l'ha verificata (vedi
     * UtenteRepository::emailVerificata) oppure se ha il codice della prenotazione di nascita.
     *
     * @param array<string, mixed> $cc
     * @return list<string>
     */
    public function proprietari(array $cc): array
    {
        $esplicito = json_decode((string) ($cc['proprietari_json'] ?? ''), true);
        if (is_array($esplicito)) {
            $email = $esplicito;
        } else {
            $prima = (int) ($cc['prenotazione_id'] ?? 0) > 0 ? $this->db->valore('SELECT email FROM prenotazioni WHERE id = ?', [(int) $cc['prenotazione_id']]) : null;
            $email = array_merge([(string) ($cc['email'] ?? ''), (string) ($prima ?? '')], !empty($cc['id']) ? $this->emailUnite((int) $cc['id']) : []);
        }

        return array_values(array_unique(array_filter(array_map(static fn ($e): string => strtolower(trim((string) $e)), $email), static fn (string $e): bool => $e !== '')));
    }

    /** @param list<string> $email */
    public function salvaProprietari(int $id, array $email): void
    {
        $pulite = array_values(array_unique(array_filter(array_map(static fn ($e): string => strtolower(trim((string) $e)), $email), static fn (string $e): bool => $e !== '')));
        $this->db->esegui('UPDATE convenzioni_compilate SET proprietari_json = ? WHERE id = ?', [json_encode($pulite, JSON_UNESCAPED_UNICODE), $id]);
    }

    /**
     * La compilazione nata da questa prenotazione, se il suo codice la apre ancora (non dopo «Cambia referente») e non è stata unita.
     *
     * @return array<string, string|null>|null
     */
    public function dellaNascita(int $prenotazioneId): ?array
    {
        return Righe::riga($this->db->riga("SELECT * FROM convenzioni_compilate WHERE prenotazione_id = ? AND nascita_ok = 1 AND IFNULL(stato_iter, '') <> 'unita' ORDER BY id DESC LIMIT 1", [$prenotazioneId]));
    }

    /**
     * Il nuovo referente: link personale NUOVO (il vecchio smette di funzionare), email della compilazione e unico proprietario il nuovo indirizzo;
     * la vecchia email, la prenotazione di nascita e le email unite non aprono più. Non vale per le concluse né per le unite. true se cambiata.
     */
    public function cambiaReferente(int $id, string $tokenNuovo, string $email): bool
    {
        return $this->db->esegui(
            "UPDATE convenzioni_compilate SET token = ?, email = ?, proprietari_json = ?, nascita_ok = 0, rivendicata_il = COALESCE(rivendicata_il, NOW()), avviso_il = NULL WHERE id = ? AND IFNULL(stato_iter, '') NOT IN ('" . implode("','", self::CHIUSE) . "')",
            [$tokenNuovo, $email, json_encode([$email], JSON_UNESCAPED_UNICODE), $id]
        ) === 1;
    }

    /** L'ultimo avviso per attività segnalate è partito da almeno 10 minuti (o mai): al massimo una email ogni 10 minuti per compilazione. */
    public function puoAvvisare(int $id): bool
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_compilate WHERE id = ? AND (avviso_il IS NULL OR avviso_il <= NOW() - INTERVAL 10 MINUTE)', [$id]) === 1;
    }

    public function segnaAvviso(int $id): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET avviso_il = NOW() WHERE id = ?', [$id]);
    }

    /**
     * La compilazione conclusa (protocollata) più recente dell'istituto, da cui precompilare un rinnovo.
     *
     * @return array<string, string|null>|null
     */
    public function ultimaConclusaDellIstituto(string $codiceScuola): ?array
    {
        $istituto = $this->scuole->codiceIstituto($codiceScuola);
        if ($istituto === '') {
            return null;
        }

        return Righe::riga($this->db->riga(
            'SELECT cc.* FROM convenzioni_compilate cc LEFT JOIN scuole s ON s.codice = cc.scuola_codice
             WHERE ' . ServizioScuole::sqlIstituto('s', 'cc.scuola_codice') . ' = ? AND cc.stato_iter = ? AND cc.dati_json IS NOT NULL ORDER BY cc.id DESC LIMIT 1',
            [$istituto, Costanti::ITER_PROTOCOLLATA]
        ));
    }

    /** Le attività (dati_json['attivita']) cambiano da sole, senza toccare i dati della scuola né la data di ultima modifica. */
    public function salvaAttivita(int $id, string $datiJson): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET dati_json = ? WHERE id = ?', [$datiJson, $id]);
    }

    public function salvaLogo(int $id, ?string $logo): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET logo = ? WHERE id = ?', [$logo, $id]);
    }

    /** Le attività segnalate da altre email, ancora da confermare (JSON, null = nessuna). */
    public function salvaInAttesa(int $id, ?string $json): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET attivita_in_attesa = ? WHERE id = ?', [$json, $id]);
    }

    /**
     * La compilazione è stata unita a un'altra: non è più «in corso» e il suo link porta alla pagina che lo dice. Niente si cancella.
     * true se ha cambiato davvero lo stato (false: nel frattempo era già protocollata o unita, chi chiama non sposta niente).
     */
    public function segnaUnita(int $id, int $in): bool
    {
        return $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = ?, unita_in = ? WHERE id = ? AND IFNULL(stato_iter, '') NOT IN ('" . implode("','", self::CHIUSE) . "')", [Costanti::ITER_UNITA, $in, $id]) === 1;
    }

    /** @return array<string, mixed>|null */
    public function perToken(string $token): ?array
    {
        return $this->db->riga('SELECT * FROM convenzioni_compilate WHERE token = ?', [$token]);
    }

    /**
     * La compilazione che ha l'attività prenotata tra quelle dell'Allegato A (programma FSL: una compilazione per più prenotazioni).
     *
     * @return array<string, string|null>|null
     */
    public function cheContiene(int $prenotazioneId): ?array
    {
        return Righe::riga($this->db->riga("SELECT * FROM convenzioni_compilate WHERE dati_json LIKE ? AND IFNULL(stato_iter, '') <> 'unita' ORDER BY id DESC LIMIT 1", ['%"pr":' . $prenotazioneId . ',%']));
    }

    /**
     * Le compilazioni che riguardano le prenotazioni indicate (la prima prenotazione della compilazione oppure una di quelle dell'Allegato A), dalla più recente.
     *
     * @param list<int> $prenotazioniIds
     * @return list<array<string, string|null>>
     */
    public function perPrenotazioni(array $prenotazioniIds): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $prenotazioniIds)))), 0, 300);
        if (!$ids) {
            return [];
        }
        $where = ['prenotazione_id IN (' . implode(',', $ids) . ')'];
        $par = [];
        foreach ($ids as $id) {
            $where[] = 'dati_json LIKE ?';
            $par[] = '%"pr":' . $id . ',%';
        }

        return Righe::testo($this->db->righe("SELECT * FROM convenzioni_compilate WHERE (" . implode(' OR ', $where) . ") AND IFNULL(stato_iter, '') <> 'unita' ORDER BY id DESC LIMIT 50", $par));
    }

    /**
     * Le compilazioni fatte con questa email (le più recenti), per rimandare il link a chi l'ha perso.
     *
     * @return list<array<string, string|null>>
     */
    public function perEmail(string $email, int $limite = 3): array
    {
        $email = strtolower(trim($email));

        return $email === '' ? [] : Righe::testo($this->db->righe("SELECT * FROM convenzioni_compilate WHERE LOWER(email) = ? AND IFNULL(stato_iter, '') <> 'unita' ORDER BY id DESC LIMIT " . max(1, $limite), [$email]));
    }

    /**
     * Apre la compilazione di una prenotazione con un link personale nuovo. Chi può aprirla con l'email dell'account (proprietari):
     *  - chi apre ha l'email VERIFICATA ($emailVerificata) uguale a quella della compilazione: è già sua, proprietari = [email], nascita_ok = 0 (il codice
     *    della prenotazione non basta più), rivendicata_il = adesso;
     *  - altrimenti proprietari = [] e nascita_ok = 1: chi ha prenotato apre con il link o il codice finché un'email verificata uguale a quella della
     *    compilazione non la rivendica (rivendica()). L'email scritta in una prenotazione non prova chi è.
     * Rinnovo ($daRinnovare = i proprietari della precedente, [] se non era rivendicata): nascita_ok è SEMPRE 0 e i proprietari della precedente restano
     * (solo quelli, mai l'email della prenotazione se non è verificata).
     *
     * @param list<string>|null $daRinnovare null = non è un rinnovo
     * @return array<string, string|null>|null la riga appena creata
     */
    public function crea(string $token, ?string $codiceScuola, ?int $prenotazioneId, string $email, ?string $emailVerificata = null, ?array $daRinnovare = null): ?array
    {
        // La prenotazione è facoltativa (la compilazione avviata dall'ufficio dalla scheda della scuola non ne ha): 0 e null sono «nessuna» e si salva NULL
        $prenotazioneId = $prenotazioneId !== null && $prenotazioneId > 0 ? $prenotazioneId : null;
        $norm = static fn (string $e): string => strtolower(trim($e));
        $mia = $emailVerificata !== null && $emailVerificata !== '' && $norm($emailVerificata) === $norm($email);
        $elenco = array_merge($daRinnovare ?? [], $mia ? [$norm($email)] : []);
        $elenco = array_values(array_unique(array_filter(array_map(static fn ($e): string => strtolower(trim((string) $e)), $elenco), static fn (string $e): bool => $e !== '')));
        $nascitaOk = $daRinnovare === null && !$mia;
        $id = $this->db->inserisci('INSERT INTO convenzioni_compilate (token, scuola_codice, prenotazione_id, email, proprietari_json, nascita_ok, rivendicata_il) VALUES (?, ?, ?, ?, ?, ?, ' . ($elenco ? 'NOW()' : 'NULL') . ')',
            [$token, $codiceScuola, $prenotazioneId, $email, json_encode($elenco, JSON_UNESCAPED_UNICODE), $nascitaOk ? 1 : 0]);

        return Righe::riga($this->db->riga('SELECT * FROM convenzioni_compilate WHERE id = ?', [$id]));
    }

    /** Il link l'ha avviato o rimandato l'ufficio da meno di 10 minuti (due clic insieme = un solo invio, anche se qualcun altro ha fatto partire un avviso). */
    public function avviataDaMeno(int $id): bool
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_compilate WHERE id = ? AND avviata_il IS NOT NULL AND avviata_il > NOW() - INTERVAL 10 MINUTE', [$id]) === 1;
    }

    /**
     * L'ufficio riprende una compilazione che nessuno ha rivendicato (stato vuoto o rifiutata) per mandare il link a un referente: il token cambia, i
     * proprietari si svuotano e il codice della prenotazione di nascita non apre più, così il link e il codice di prima non danno più accesso. Diventa
     * proprietario chi aprirà il link nuovo con l'email verificata uguale a quella scelta. true se è cambiata.
     */
    public function riapri(int $id, string $email, string $tokenNuovo, string $da): bool
    {
        return $this->db->esegui(
            "UPDATE convenzioni_compilate SET token = ?, email = ?, proprietari_json = '[]', nascita_ok = 0, avviso_il = NOW(), avviata_il = NOW(), avviata_da = ?
             WHERE id = ? AND rivendicata_il IS NULL AND IFNULL(stato_iter, '') IN ('', '" . Costanti::ITER_RIFIUTATA . "')",
            [$tokenNuovo, strtolower(trim($email)), mb_substr($da, 0, 255), $id]
        ) === 1;
    }

    /** La compilazione l'ha avviata l'ufficio dalla scheda della scuola: quando e chi (serve a farla comparire anche se è ancora vuota). */
    public function segnaAvvio(int $id, string $da): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET avviata_il = NOW(), avviata_da = ? WHERE id = ?', [mb_substr($da, 0, 255), $id]);
    }

    /** La compilazione è già stata rivendicata da un'email verificata (o assegnata dall'ufficio con «Cambia referente»). @param array<string, mixed> $cc */
    public static function eRivendicata(array $cc): bool
    {
        return !empty($cc['rivendicata_il']);
    }

    /** Gli stati in cui una compilazione non rivendicata si può ancora rivendicare: dopo il caricamento del file firmato il link non cambia più. @param array<string, mixed> $cc */
    public static function rivendicabile(array $cc): bool
    {
        return !self::eRivendicata($cc) && in_array((string) ($cc['stato_iter'] ?? ''), ['', Costanti::ITER_RIFIUTATA], true);
    }

    /**
     * La rivendicazione, in un colpo solo (da chiamare sotto il blocco): se la compilazione non è rivendicata, è '' o rifiutata e la sua email è quella
     * VERIFICATA di chi chiede, il link cambia (token nuovo: quello vecchio e il codice di nascita non aprono più) e l'unico proprietario è quell'email.
     * true se è avvenuta adesso.
     */
    public function rivendica(int $id, string $emailVerificata, string $tokenNuovo): bool
    {
        $email = strtolower(trim($emailVerificata));

        return $email !== '' && $this->db->esegui(
            "UPDATE convenzioni_compilate SET token = ?, proprietari_json = ?, nascita_ok = 0, rivendicata_il = NOW(), avviso_il = NULL
             WHERE id = ? AND rivendicata_il IS NULL AND LOWER(TRIM(email)) = ? AND IFNULL(stato_iter, '') IN ('', '" . Costanti::ITER_RIFIUTATA . "')",
            [$tokenNuovo, json_encode([$email], JSON_UNESCAPED_UNICODE), $id, $email]
        ) === 1;
    }

    public function salvaDati(int $id, string $datiJson, ?string $logo, string $email): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET dati_json = ?, logo = ?, email = ?, aggiornata_il = NOW() WHERE id = ?', [$datiJson, $logo, $email, $id]);
    }

    public function segnaScaricata(int $id): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET scaricata_il = NOW() WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM convenzioni_compilate WHERE id = ?', [$id]);
    }

    /**
     * Convenzione firmata caricata dalla scuola (sostituisce la precedente): file, chi ha firmato e iter «caricata dalla scuola».
     * Solo dallo stato di partenza giusto (mai caricata, già caricata o rifiutata): false se nel frattempo la convenzione è andata avanti o è stata unita.
     */
    public function salvaFirmata(int $id, string $file, string $firmatario, ?string $allegato = null, string $avviso = ''): bool
    {
        // un nuovo caricamento riparte da capo: niente presa in carico, motivo di rifiuto, protocollo in entrata o avvisi sulla firma del file precedente; l'Allegato A resta se non se ne carica un altro
        return $this->db->esegui("UPDATE convenzioni_compilate SET file_firmato = ?, firmatario = ?, firmata_il = NOW(), stato_iter = ?, in_carico_il = NULL, in_carico_da = '', nota_ufficio = '', protocollo_ingresso = '', protocollo_ingresso_data = NULL, avviso_firma_ingresso = ?, avviso_firma = '', file_allegato_scuola = COALESCE(?, file_allegato_scuola) WHERE id = ? AND IFNULL(stato_iter, '') IN ('', ?, ?)",
            [$file, $firmatario, Costanti::ITER_CARICATA, MessaggiFirma::taglia($avviso), $allegato, $id, Costanti::ITER_CARICATA, Costanti::ITER_RIFIUTATA]) === 1;
    }

    /**
     * L'ufficio mette un Allegato A al posto di quello che c'è: vale solo se nel frattempo non sono cambiati né la fase né il file.
     */
    public function sostituisciAllegato(int $id, string $stato, ?string $vecchio, string $nuovo): bool
    {
        return $this->db->esegui('UPDATE convenzioni_compilate SET file_allegato_scuola = ? WHERE id = ? AND stato_iter = ? AND file_allegato_scuola <=> ?', [$nuovo, $id, $stato, $vecchio]) === 1;
    }

    /**
     * L'ufficio ha registrato il protocollo in entrata della convenzione caricata dalla scuola: è presa in carico.
     * Solo se è ancora in attesa del protocollo in entrata (false se nel frattempo è stata rifiutata, unita o presa in carico).
     */
    public function protocolloIngresso(int $id, string $numero, ?string $data, string $da): bool
    {
        return $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = ?, in_carico_il = NOW(), in_carico_da = ?, nota_ufficio = '', protocollo_ingresso = ?, protocollo_ingresso_data = ? WHERE id = ? AND stato_iter = ?",
            [Costanti::ITER_IN_CARICO, mb_substr($da, 0, 255), $numero, $data, $id, Costanti::ITER_CARICATA]) === 1;
    }

    /**
     * L'ufficio rifiuta il file con un motivo: la scuola potrà caricarne un altro. Solo se la convenzione ha un file e non è già andata oltre
     * (caricata, presa in carico o dal Direttore): false se nel frattempo il Direttore ha firmato, è stata registrata o unita.
     */
    public function rifiuta(int $id, string $nota): bool
    {
        return $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = ?, nota_ufficio = ? WHERE id = ? AND stato_iter IN (?, ?, ?) AND file_firmato IS NOT NULL AND file_firmato <> ''",
            [Costanti::ITER_RIFIUTATA, mb_substr($nota, 0, 500), $id, Costanti::ITER_CARICATA, Costanti::ITER_IN_CARICO, Costanti::ITER_DA_FIRMARE]) === 1;
    }

    /** @return array<string, mixed>|null */
    public function perTokenDirettore(string $token): ?array
    {
        return $token === '' ? null : $this->db->riga('SELECT * FROM convenzioni_compilate WHERE token_direttore = ?', [$token]);
    }

    /**
     * Mandata al Direttore per la firma, con il suo link personale. Solo dalla presa in carico (o da un invio già fatto: il link si rimanda):
     * false se nel frattempo è stata rifiutata, firmata o registrata.
     */
    public function daFirmare(int $id, string $token): bool
    {
        return $this->db->esegui('UPDATE convenzioni_compilate SET stato_iter = ?, token_direttore = ?, da_firmare_il = NOW() WHERE id = ? AND stato_iter IN (?, ?)',
            [Costanti::ITER_DA_FIRMARE, $token, $id, Costanti::ITER_IN_CARICO, Costanti::ITER_DA_FIRMARE]) === 1;
    }

    /**
     * Firma PAdES del Direttore registrata: il PDF firmato è pronto per il protocollo. Vale solo se la convenzione è ancora in attesa della sua firma
     * ($token: il link con cui sta firmando; $fileFirmato: il PDF della scuola da cui è partito): false se nel frattempo è stata rifiutata, rimandata
     * con un altro link, sostituita, registrata a mano o firmata da un'altra scheda.
     */
    public function segnaFirmataDirettore(int $id, string $file, string $firmatario, string $avviso = '', ?string $token = null, ?string $fileFirmato = null): bool
    {
        $sql = 'UPDATE convenzioni_compilate SET stato_iter = ?, file_definitivo = ?, firmatario_direttore = ?, avviso_firma = ?, firmata_direttore_il = NOW() WHERE id = ? AND stato_iter = ?';
        $par = [Costanti::ITER_FIRMATA, $file, mb_substr($firmatario, 0, 255), MessaggiFirma::taglia($avviso), $id, Costanti::ITER_DA_FIRMARE];
        if ($token !== null) {
            $sql .= ' AND token_direttore <=> ?';
            $par[] = $token;
        }
        if ($fileFirmato !== null) {
            $sql .= ' AND file_firmato <=> ?';
            $par[] = $fileFirmato;
        }

        return $this->db->esegui($sql, $par) === 1;
    }
    /** Validità proposta e docenti decisi dall'ufficio alla presa in carico («Controlla la convenzione»): restano sulla compilazione. */
    public function salvaControllo(int $id, ?string $dal, ?string $al, ?string $docentiJson): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET valida_dal = ?, valida_al = ?, docenti_json = ? WHERE id = ?', [$dal, $al, $docentiJson, $id]);
    }

    /**
     * L'originale restituito da Titulus (o il motivo per cui non c'è): $file null = nessun file; $stato ok | mancante | impronta. Ogni tentativo si conta.
     */
    public function salvaOriginale(int $id, ?string $file, string $stato, string $avviso): void
    {
        $this->db->esegui(
            'UPDATE convenzioni_compilate SET file_originale = COALESCE(?, file_originale), originale_stato = ?, originale_avviso = ?, originale_tentativi = originale_tentativi + 1, originale_il = NOW() WHERE id = ?',
            [$file, $stato, mb_substr($avviso, 0, 500), $id]
        );
    }

    /** Il documento protocollato caricato a mano dall'ufficio ($file null = lo toglie). */
    public function salvaProtocollato(int $id, ?string $file): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET file_protocollato = ? WHERE id = ?', [$file, $id]);
    }

    /** Perché il protocollo automatico non è andato ('' = tutto a posto): lo legge solo l'ufficio. */
    public function segnaErroreProtocollo(int $id, string $errore): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET protocollo_errore = ? WHERE id = ?', [mb_substr($errore, 0, 500), $id]);
    }

    /** Il resoconto della registrazione (JSON): prenotazioni coperte e approvate, quali no e perché. */
    public function salvaEsitoRegistrazione(int $id, string $json): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET esito_registrazione = ? WHERE id = ?', [$json, $id]);
    }

    /**
     * Le convenzioni protocollate il cui originale da Titulus non è arrivato (mancante o impronta diversa), da riprovare: al massimo 8 tentativi in tutto
     * (il primo subito e uno al giorno per 7 giorni), a distanza di almeno 20 ore l'uno dall'altro.
     *
     * @return list<array<string, mixed>>
     */
    public function originaleDaRiprovare(): array
    {
        return $this->db->righe("SELECT * FROM convenzioni_compilate WHERE stato_iter = 'protocollata' AND originale_stato IN ('mancante', 'impronta')
            AND originale_tentativi < 8 AND (originale_il IS NULL OR originale_il <= NOW() - INTERVAL 20 HOUR) ORDER BY id");
    }

    /**
     * Convenzione firmata dal Direttore, protocollata e registrata (riga di convenzioni_scuole): iter concluso. Solo se è ancora in lavorazione
     * (false se nel frattempo è stata registrata da un altro, o unita).
     */
    public function segnaProtocollata(int $id, int $convenzioneId, string $protocollo, ?string $protocolloData): bool
    {
        return $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = ?, convenzione_id = ?, protocollo = ?, protocollo_data = ?, protocollata_il = NOW() WHERE id = ? AND IFNULL(stato_iter, '') NOT IN ('', ?, ?)",
            [Costanti::ITER_PROTOCOLLATA, $convenzioneId, $protocollo, $protocolloData, $id, Costanti::ITER_PROTOCOLLATA, Costanti::ITER_UNITA]) === 1;
    }

    /**
     * Email delle persone che ricevono l'avviso della convenzione caricata: amministratori, utenti abilitati a tutta la FSL o alle sole convenzioni e persone dell'«Ufficio convenzioni FSL».
     *
     * @return list<string>
     */
    public function emailAbilitati(): array
    {
        $righe = $this->db->righe("SELECT DISTINCT u.email FROM utenti u
            WHERE u.email <> '' AND (u.ruolo_id = 1 OR u.id IN (SELECT utente_id FROM abilitazioni_ambito WHERE tipo IN ('fsl', 'fsl_convenzioni')))
            UNION SELECT p.email FROM uffici_persone p JOIN uffici f ON f.id = p.ufficio_id WHERE f.chiave = 'convenzioni_fsl' AND p.email <> ''");

        return array_values(array_filter(array_map(static fn (array $r): string => trim((string) $r['email']), $righe), static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));
    }

    /**
     * Le compilazioni dell'ultimo anno con i dati inseriti (pannello FSL).
     *
     * @return list<array<string, string|null>>
     */
    public function recenti(int $limite = 50): array
    {
        return Righe::testo($this->db->righe("SELECT * FROM convenzioni_compilate WHERE dati_json IS NOT NULL AND IFNULL(stato_iter, '') <> 'unita' AND aggiornata_il >= NOW() - INTERVAL 1 YEAR ORDER BY aggiornata_il DESC LIMIT " . max(1, $limite)));
    }
}
