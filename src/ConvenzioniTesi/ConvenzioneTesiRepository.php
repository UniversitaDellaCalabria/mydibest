<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use App\Core\Database;
use App\Eventi\Righe;
use App\Fsl\BloccoOccupato;
use App\Fsl\ConvenzioneCompilataRepository;
use App\Infrastructure\Pdf\MessaggiFirma;

/** Convenzioni per la tesi con enti e aziende (tabella convenzioni_tesi): solo lettura e scrittura dei dati, nessuna regola. */
final class ConvenzioneTesiRepository
{
    /** Attesa normale del blocco di una convenzione (secondi) e attesa per chi ha già fatto qualcosa che non si rifà (la firma del Direttore con un codice OTP già speso). */
    public const ATTESA_BLOCCO = 5;
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

    private ?string $database = null;

    public function __construct(private Database $db)
    {
    }

    private function nomeBlocco(int $id): string
    {
        $this->database ??= (string) ($this->db->valore('SELECT DATABASE()') ?? '');

        return ConvenzioneCompilataRepository::nomeBlocco('tesi_' . $id, $this->database);
    }

    /**
     * Esegue $f con il blocco della convenzione in mano (GET_LOCK, lo stesso meccanismo della FSL): due richieste insieme sulla stessa convenzione non si
     * sovrappongono. Se il blocco non si ottiene NON si procede: BloccoOccupato. Chi scrive rilegge lo stato sotto blocco.
     *
     * @template T
     * @param callable(): T $f
     * @return T
     * @throws BloccoOccupato
     */
    public function sottoBlocco(int $id, callable $f, ?int $attesa = null): mixed
    {
        $nome = $this->nomeBlocco($id);
        if ((int) ($this->db->valore('SELECT GET_LOCK(?, ' . max(0, $attesa ?? self::ATTESA_BLOCCO) . ')', [$nome]) ?? 0) !== 1) {
            throw new BloccoOccupato();
        }
        try {
            return $f();
        } finally {
            $this->db->valore('SELECT RELEASE_LOCK(?)', [$nome]);
        }
    }

    /**
     * Esegue $f in una transazione (commit se termina, rollback se lancia).
     *
     * @template T
     * @param callable(): T $f
     * @return T
     */
    public function transazione(callable $f): mixed
    {
        return $this->db->transazione(static fn (): mixed => $f());
    }

    /** @return array<string, string|null>|null */
    public function perId(int $id): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM convenzioni_tesi WHERE id = ?', [$id]));
    }

    /** @return array<string, string|null>|null */
    public function perToken(string $token): ?array
    {
        return $token === '' ? null : Righe::riga($this->db->riga('SELECT * FROM convenzioni_tesi WHERE token = ?', [$token]));
    }

    /** @return array<string, string|null>|null */
    public function perTokenDirettore(string $token): ?array
    {
        return $token === '' ? null : Righe::riga($this->db->riga('SELECT * FROM convenzioni_tesi WHERE token_direttore = ?', [$token]));
    }

    /**
     * Le convenzioni legate a queste email (sempre minuscole), dalla più recente: per la tessera dell'azienda nell'area personale.
     *
     * @param list<string> $emails
     * @return list<array<string, string|null>>
     */
    public function perEmail(array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(static fn (string $e): string => strtolower(trim($e)), $emails))));
        if (!$emails) {
            return [];
        }

        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_tesi WHERE LOWER(email) IN (' . implode(',', array_fill(0, count($emails), '?')) . ') ORDER BY id DESC', $emails));
    }

    /** Una convenzione ancora aperta (non stipulata) per la stessa email: serve a non aprirne due quando l'azienda richiede più volte il link. @return array<string, string|null>|null */
    public function apertaPerEmail(string $email): ?array
    {
        return Righe::riga($this->db->riga("SELECT * FROM convenzioni_tesi WHERE LOWER(email) = ? AND stato <> ? ORDER BY id DESC LIMIT 1", [strtolower(trim($email)), Costanti::PROTOCOLLATA]));
    }

    public function crea(string $email, string $azienda, string $origine, string $invitataDa): int
    {
        return $this->db->inserisci(
            'INSERT INTO convenzioni_tesi (token, stato, origine, azienda, email, invitata_da, aggiornata_il) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [bin2hex(random_bytes(20)), Costanti::INVITATA, $origine, mb_substr($azienda, 0, 200), mb_substr(strtolower(trim($email)), 0, 255), mb_substr($invitataDa, 0, 150)]
        );
    }

    /** I dati dell'azienda (compilazione o aggiornamento). */
    public function salvaDati(int $id, string $azienda, string $email, string $datiJson, bool $consensoElenco, string $nota): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET azienda = ?, email = ?, dati_json = ?, consenso_elenco = ?, nota_azienda = ?, aggiornata_il = NOW() WHERE id = ?',
            [mb_substr($azienda, 0, 200), mb_substr(strtolower(trim($email)), 0, 255), $datiJson, $consensoElenco ? 1 : 0, mb_substr($nota, 0, 1000), $id]);
    }

    public function impostaStato(int $id, string $stato): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET stato = ?, aggiornata_il = NOW() WHERE id = ?', [$stato, $id]);
    }

    public function salvaModifiche(int $id, string $json, string $stato): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET modifiche_json = ?, stato = ?, aggiornata_il = NOW() WHERE id = ?', [$json, $stato, $id]);
    }

    /**
     * Il PDF firmato dell'azienda. Vale solo se la convenzione è ancora nelle mani dell'azienda (compilata, già caricata e non presa in carico, rifiutata):
     * false se nel frattempo l'ufficio l'ha presa in carico o è andata oltre. Un nuovo caricamento riparte da capo (niente motivo di rifiuto, avvisi di prima).
     */
    public function salvaFirmata(int $id, string $percorso, string $firmatario, string $avviso = ''): bool
    {
        return $this->db->esegui('UPDATE convenzioni_tesi SET file_firmato = ?, firmatario = ?, firmata_il = NOW(), stato = ?, nota_ufficio = \'\', avviso_firma_ingresso = ?, avviso_firma = \'\', protocollo_errore = \'\', aggiornata_il = NOW() WHERE id = ? AND stato IN (?, ?, ?)',
            [$percorso, mb_substr($firmatario, 0, 255), Costanti::CARICATA, MessaggiFirma::taglia($avviso), $id, Costanti::COMPILATA, Costanti::CARICATA, Costanti::RIFIUTATA]) === 1;
    }

    /** Presa in carico: solo da «caricata» (false se nel frattempo è stata rifiutata o presa in carico da un altro). */
    public function protocolloIngresso(int $id, string $numero, ?string $data, string $autore): bool
    {
        return $this->db->esegui('UPDATE convenzioni_tesi SET protocollo_ingresso = ?, protocollo_ingresso_data = ?, in_carico_il = NOW(), in_carico_da = ?, stato = ?, nota_ufficio = \'\', aggiornata_il = NOW() WHERE id = ? AND stato = ?',
            [$numero, $data, mb_substr($autore, 0, 255), Costanti::IN_CARICO, $id, Costanti::CARICATA]) === 1;
    }

    /** Il link di firma al Direttore: solo da «presa in carico» o «dal Direttore» (rimandare il link lo cambia). */
    public function daFirmare(int $id, string $token): bool
    {
        return $this->db->esegui('UPDATE convenzioni_tesi SET token_direttore = ?, da_firmare_il = NOW(), stato = ?, aggiornata_il = NOW() WHERE id = ? AND stato IN (?, ?)',
            [$token, Costanti::DA_FIRMARE, $id, Costanti::IN_CARICO, Costanti::DA_FIRMARE]) === 1;
    }

    /**
     * Firma del Direttore registrata. Vale solo se la convenzione è ancora in attesa della sua firma e, se indicati, con lo stesso link ($token) e lo stesso PDF
     * dell'azienda ($fileFirmato) da cui il Direttore è partito: false se nel frattempo è stata rifiutata, rimandata con un altro link, registrata a mano o firmata.
     */
    public function segnaFirmataDirettore(int $id, string $percorso, string $chi, string $avviso = '', ?string $token = null, ?string $fileFirmato = null): bool
    {
        $sql = 'UPDATE convenzioni_tesi SET file_definitivo = ?, firmatario_direttore = ?, avviso_firma = ?, firmata_direttore_il = NOW(), stato = ?, aggiornata_il = NOW() WHERE id = ? AND stato = ?';
        $par = [$percorso, mb_substr($chi, 0, 255), MessaggiFirma::taglia($avviso), Costanti::FIRMATA, $id, Costanti::DA_FIRMARE];
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

    /** Rifiuto dell'ufficio: solo se c'è un file e la convenzione non è andata oltre (caricata, presa in carico o dal Direttore). */
    public function rifiuta(int $id, string $motivo): bool
    {
        return $this->db->esegui('UPDATE convenzioni_tesi SET stato = ?, nota_ufficio = ?, token_direttore = NULL, da_firmare_il = NULL, aggiornata_il = NOW() WHERE id = ? AND stato IN (?, ?, ?) AND file_firmato IS NOT NULL AND file_firmato <> \'\'',
            [Costanti::RIFIUTATA, mb_substr($motivo, 0, 500), $id, Costanti::CARICATA, Costanti::IN_CARICO, Costanti::DA_FIRMARE]) === 1;
    }

    /**
     * La stipula: solo da «firmata dal Direttore» (false se nel frattempo è già stata registrata). $origine dice da dove viene il file protocollato
     * ('mano' = caricato dall'ufficio, 'copia' = copia stampigliata dal portale); senza un file nuovo il file e la sua origine restano come erano.
     */
    public function segnaProtocollata(int $id, string $protocollo, ?string $data, string $dal, string $al, ?string $fileProtocollato, string $origine = ''): bool
    {
        return $this->db->esegui('UPDATE convenzioni_tesi SET stato = ?, protocollo = ?, protocollo_data = ?, valida_dal = ?, valida_al = ?, file_protocollato = COALESCE(?, file_protocollato), protocollato_origine = IF(? IS NULL, protocollato_origine, ?), protocollo_errore = \'\', protocollata_il = NOW(), avviso_scadenza = 0, aggiornata_il = NOW() WHERE id = ? AND stato = ?',
            [Costanti::PROTOCOLLATA, $protocollo, $data, $dal, $al, $fileProtocollato, $fileProtocollato, $origine, $id, Costanti::FIRMATA]) === 1;
    }

    /** «Controlla la convenzione»: la validità decisa dall'ufficio (date vuote = non decise), salvata sulla convenzione prima della stipula. */
    public function salvaControllo(int $id, ?string $dal, ?string $al): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET valida_dal = ?, valida_al = ? WHERE id = ? AND stato <> ?', [$dal, $al, $id, Costanti::PROTOCOLLATA]);
    }

    /** L'originale restituito da Titulus (o il motivo per cui non c'è): $file null = nessun file; $stato ok | mancante | impronta. Ogni tentativo si conta. */
    public function salvaOriginale(int $id, ?string $file, string $stato, string $avviso): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET file_originale = COALESCE(?, file_originale), originale_stato = ?, originale_avviso = ?, originale_tentativi = originale_tentativi + 1, originale_il = NOW() WHERE id = ?',
            [$file, $stato, mb_substr($avviso, 0, 500), $id]);
    }

    /**
     * Le convenzioni stipulate il cui originale da Titulus non è arrivato (mancante o impronta diversa): al massimo 8 tentativi in tutto (il primo subito e uno
     * al giorno per 7 giorni), a distanza di almeno 20 ore l'uno dall'altro.
     *
     * @return list<array<string, string|null>>
     */
    public function originaleDaRiprovare(): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_tesi WHERE stato = ? AND originale_stato IN (\'mancante\', \'impronta\') AND originale_tentativi < 8 AND (originale_il IS NULL OR originale_il <= NOW() - INTERVAL 20 HOUR) ORDER BY id', [Costanti::PROTOCOLLATA]));
    }

    /** Il documento protocollato caricato a mano dall'ufficio. */
    public function salvaProtocollato(int $id, string $percorso): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET file_protocollato = ?, protocollato_origine = \'mano\', aggiornata_il = NOW() WHERE id = ?', [$percorso, $id]);
    }

    /** Perché il protocollo automatico non è andato ('' = tutto a posto): lo legge solo l'ufficio. */
    public function segnaErroreProtocollo(int $id, string $errore): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET protocollo_errore = ? WHERE id = ?', [mb_substr($errore, 0, 500), $id]);
    }

    /** Gli avvisi della stipula già mandati (JSON), per non mandarli due volte quando la registrazione si ripete. */
    public function salvaEsito(int $id, string $json): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET esito_registrazione = ? WHERE id = ?', [$json, $id]);
    }

    /**
     * Le convenzioni stipulate ancora valide oggi: servono a non proporre una nuova convenzione a un'azienda che ne ha già una (confronto per codice fiscale o
     * partita IVA, fatto da chi chiama sui dati dell'azienda).
     *
     * @return list<array<string, string|null>>
     */
    public function stipulateValide(string $oggi): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_tesi WHERE stato = ? AND valida_al >= ? ORDER BY valida_al DESC', [Costanti::PROTOCOLLATA, $oggi]));
    }

    /** Una convenzione già in essere inserita a mano nell'archivio (senza passare dall'iter). */
    public function creaDaArchivio(string $azienda, string $email, string $datiJson, bool $consenso, string $protocollo, ?string $dataProt, string $dal, string $al, ?string $file, string $autore): int
    {
        return $this->db->inserisci(
            'INSERT INTO convenzioni_tesi (token, stato, origine, azienda, email, dati_json, consenso_elenco, protocollo, protocollo_data, valida_dal, valida_al, file_protocollato, protocollato_origine, protocollata_il, invitata_da, aggiornata_il)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? IS NULL, \'\', \'mano\'), NOW(), ?, NOW())',
            [bin2hex(random_bytes(20)), Costanti::PROTOCOLLATA, Costanti::ORIGINE_ARCHIVIO, mb_substr($azienda, 0, 200), mb_substr(strtolower(trim($email)), 0, 255), $datiJson, $consenso ? 1 : 0,
                $protocollo, $dataProt, $dal, $al, $file, $file, mb_substr($autore, 0, 150)]
        );
    }

    public function aggiornaArchivio(int $id, string $azienda, string $datiJson, bool $consenso, string $protocollo, ?string $dataProt, string $dal, string $al): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET azienda = ?, dati_json = ?, consenso_elenco = ?, protocollo = ?, protocollo_data = ?, valida_dal = ?, valida_al = ?, avviso_scadenza = 0, aggiornata_il = NOW() WHERE id = ?',
            [mb_substr($azienda, 0, 200), $datiJson, $consenso ? 1 : 0, $protocollo, $dataProt, $dal, $al, $id]);
    }

    public function impostaFileProtocollato(int $id, string $percorso): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET file_protocollato = ?, protocollato_origine = \'mano\', aggiornata_il = NOW() WHERE id = ?', [$percorso, $id]);
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM convenzioni_tesi WHERE id = ?', [$id]);
    }

    /**
     * Le convenzioni in uno degli stati indicati, dalla più recente.
     *
     * @param list<string> $stati
     * @return list<array<string, string|null>>
     */
    public function inStati(array $stati): array
    {
        if (!$stati) {
            return [];
        }

        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_tesi WHERE stato IN (' . implode(',', array_fill(0, count($stati), '?')) . ') ORDER BY aggiornata_il DESC, id DESC', array_values($stati)));
    }

    /** @return array<string, int> quante convenzioni per stato */
    public function conteggi(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT stato, COUNT(*) n FROM convenzioni_tesi GROUP BY stato') as $r) {
            $out[(string) $r['stato']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * L'archivio: le convenzioni stipulate, con cerca nel nome e filtro per validità.
     *
     * @return list<array<string, string|null>>
     */
    public function archivio(string $cerca, string $oggi, string $vista = 'tutte'): array
    {
        $where = ['stato = ?'];
        $par = [Costanti::PROTOCOLLATA];
        if (trim($cerca) !== '') {
            $where[] = '(azienda LIKE ? OR protocollo LIKE ? OR dati_json LIKE ?)';
            $like = '%' . trim($cerca) . '%';
            array_push($par, $like, $like, $like);
        }
        if ($vista === 'valide') {
            $where[] = '(valida_al IS NULL OR valida_al >= ?)';
            $par[] = $oggi;
        } elseif ($vista === 'scadute') {
            $where[] = 'valida_al < ?';
            $par[] = $oggi;
        }

        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_tesi WHERE ' . implode(' AND ', $where) . ' ORDER BY azienda, valida_al DESC', $par));
    }

    /**
     * L'elenco pubblico: stipulate, valide oggi e con il consenso dell'azienda a comparire.
     *
     * @return list<array<string, string|null>>
     */
    public function elencoPubblico(string $oggi): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_tesi WHERE stato = ? AND consenso_elenco = 1 AND valida_al >= ? ORDER BY azienda', [Costanti::PROTOCOLLATA, $oggi]));
    }

    /**
     * Convenzioni valide che scadono entro i giorni indicati e di cui non si è ancora avvisato.
     *
     * @return list<array<string, string|null>>
     */
    public function inScadenza(string $oggi, string $entro): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_tesi WHERE stato = ? AND avviso_scadenza = 0 AND valida_al >= ? AND valida_al <= ? ORDER BY valida_al', [Costanti::PROTOCOLLATA, $oggi, $entro]));
    }

    public function segnaAvvisoScadenza(int $id): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET avviso_scadenza = 1 WHERE id = ?', [$id]);
    }

    /**
     * Compilazioni ferme da tempo che aspettano l'azienda (per il promemoria), non ricordate negli ultimi giorni e create dopo la data indicata (oltre non si insiste).
     *
     * @param list<string> $stati
     * @return list<array<string, string|null>>
     */
    public function ferme(array $stati, string $prima, string $promemoriaPrima, string $creataDopo): array
    {
        if (!$stati) {
            return [];
        }

        return Righe::testo($this->db->righe(
            'SELECT * FROM convenzioni_tesi WHERE stato IN (' . implode(',', array_fill(0, count($stati), '?')) . ') AND aggiornata_il <= ? AND (promemoria_il IS NULL OR promemoria_il <= ?) AND creata_il >= ? ORDER BY id',
            array_merge(array_values($stati), [$prima, $promemoriaPrima, $creataDopo])
        ));
    }

    public function segnaPromemoria(int $id): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET promemoria_il = NOW() WHERE id = ?', [$id]);
    }
}
