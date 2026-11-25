<?php

declare(strict_types=1);

namespace App\Protocollo;

use App\Core\Database;

/** Tabelle del protocollo di Ateneo (titulus_*). */
final class ProtocolloRepository
{
    public function __construct(private Database $db)
    {
    }

    // ---- configurazione globale (riga 1) --------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function configurazione(): array
    {
        return $this->db->riga('SELECT * FROM titulus_config WHERE id = 1') ?? [];
    }

    /** @param array<string, mixed> $v nome, url_ws, namespace_ns2, username, aoo, agd, autore, email_default, fascicolazione_separata, attivo; password_cifrata solo se si cambia */
    public function salvaConfigurazione(array $v, ?string $passwordCifrata): void
    {
        $campi = ['nome', 'url_ws', 'namespace_ns2', 'username', 'aoo', 'agd', 'autore', 'email_default', 'fascicolazione_separata', 'attivo'];
        $par = array_map(static fn (string $c): mixed => $v[$c], $campi);
        if ($this->db->valore('SELECT COUNT(*) FROM titulus_config WHERE id = 1') === null || (int) $this->db->valore('SELECT COUNT(*) FROM titulus_config WHERE id = 1') === 0) {
            $this->db->esegui('INSERT INTO titulus_config (id) VALUES (1)');
        }
        $sql = 'UPDATE titulus_config SET ' . implode(', ', array_map(static fn (string $c): string => "$c = ?", $campi));
        if ($passwordCifrata !== null) {
            $sql .= ', password_cifrata = ?';
            $par[] = $passwordCifrata;
        }
        $this->db->esegui($sql . ' WHERE id = 1', $par);
    }

    /**
     * Gli interruttori «protocollo automatico dopo la firma» (spenti di partenza): uno per le convenzioni FSL e uno per quelle di tesi.
     * Acceso = dopo la firma del Direttore il portale manda da solo il documento a Titulus (se il collegamento e la regola sono pronti e non ci sono avvisi).
     */
    public function salvaAutomatici(bool $fsl, bool $tesi): void
    {
        if ((int) $this->db->valore('SELECT COUNT(*) FROM titulus_config WHERE id = 1') === 0) {
            $this->db->esegui('INSERT INTO titulus_config (id) VALUES (1)');
        }
        $this->db->esegui('UPDATE titulus_config SET automatico_fsl = ?, automatico_tesi = ? WHERE id = 1', [$fsl ? 1 : 0, $tesi ? 1 : 0]);
    }

    public function passwordCifrata(): string
    {
        return (string) $this->db->valore('SELECT password_cifrata FROM titulus_config WHERE id = 1');
    }

    public function registraProva(string $esito): void
    {
        $this->db->esegui('UPDATE titulus_config SET ultima_prova_il = NOW(), ultima_prova_esito = ? WHERE id = 1', [mb_substr($esito, 0, 500)]);
    }

    // ---- regole per richiesta -------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function regole(): array
    {
        return $this->db->righe(
            'SELECT r.*, v.voce AS voce_indice, p.codice AS repertorio_codice, p.nome AS repertorio_nome, (SELECT COUNT(*) FROM titulus_cc c WHERE c.regola_id = r.id) AS n_cc
               FROM titulus_regole r LEFT JOIN titulus_voci_indice v ON v.id = r.voce_indice_id LEFT JOIN titulus_repertori p ON p.id = r.repertorio_id
              ORDER BY r.applica_a, r.nome'
        );
    }

    /** @return array<string, mixed>|null */
    public function regola(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM titulus_regole WHERE id = ?', [$id]);
    }

    /** La regola attiva per una richiesta: quella dedicata, poi quella del contesto padre (es. «tutorato»), infine quella «generale» di riserva. @return array<string, mixed>|null */
    public function regolaPer(string $applicaA): ?array
    {
        $catena = array_values(array_unique(array_filter([$applicaA, Costanti::CONTESTO_PADRE[$applicaA] ?? null, 'generale'])));
        foreach ($catena as $contesto) {
            $r = $this->db->riga("SELECT * FROM titulus_regole WHERE attiva = 1 AND applica_a = ? ORDER BY id LIMIT 1", [$contesto]);
            if ($r !== null) {
                return $r;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $v @return int id della regola (0 se non riesce) */
    public function salvaRegola(int $id, array $v): int
    {
        $campi = ['nome', 'applica_a', 'attiva', 'tipo_documento', 'azione', 'voce_indice_id', 'repertorio_id', 'uo', 'rpa_nome', 'rpa_username', 'rpa_matricola', 'invia_email_rpa', 'email',
            'cod_titolario', 'fascicolo_numero', 'fascicolo_anno', 'op_uo', 'op_nome', 'op_username', 'op_matricola'];
        $par = array_map(static fn (string $c): mixed => $v[$c], $campi);
        if ($id > 0) {
            $this->db->esegui('UPDATE titulus_regole SET ' . implode(', ', array_map(static fn (string $c): string => "$c = ?", $campi)) . ' WHERE id = ?', [...$par, $id]);

            return $id;
        }

        return $this->db->inserisci('INSERT INTO titulus_regole (' . implode(', ', $campi) . ') VALUES (' . implode(', ', array_fill(0, count($campi), '?')) . ')', $par);
    }

    public function eliminaRegola(int $id): void
    {
        $this->db->esegui('DELETE FROM titulus_cc WHERE regola_id = ?', [$id]);
        $this->db->esegui('DELETE FROM titulus_regole WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function copieConoscenza(int $regolaId): array
    {
        return $this->db->righe('SELECT * FROM titulus_cc WHERE regola_id = ? ORDER BY id', [$regolaId]);
    }

    /** @param list<array{uo: string, nome: string, username: string, matricola: string}> $righe */
    public function sostituisciCopieConoscenza(int $regolaId, array $righe): void
    {
        $this->db->esegui('DELETE FROM titulus_cc WHERE regola_id = ?', [$regolaId]);
        foreach ($righe as $r) {
            $this->db->esegui('INSERT INTO titulus_cc (regola_id, uo, nome, username, matricola) VALUES (?, ?, ?, ?, ?)', [$regolaId, $r['uo'], $r['nome'], $r['username'], $r['matricola']]);
        }
    }

    // ---- voci d'indice (iter) e repertori --------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function vociIndice(bool $soloAttive = false): array
    {
        return $this->db->righe('SELECT v.*, (SELECT COUNT(*) FROM titulus_regole r WHERE r.voce_indice_id = v.id) AS usi FROM titulus_voci_indice v' . ($soloAttive ? ' WHERE v.attiva = 1' : '') . ' ORDER BY v.voce');
    }

    public function salvaVoce(int $id, string $voce, bool $attiva): int
    {
        if ($id > 0) {
            $this->db->esegui('UPDATE titulus_voci_indice SET voce = ?, attiva = ? WHERE id = ?', [$voce, (int) $attiva, $id]);

            return $id;
        }

        return $this->db->inserisci('INSERT INTO titulus_voci_indice (voce, attiva) VALUES (?, ?)', [$voce, (int) $attiva]);
    }

    public function vocePerNome(string $voce, int $escludiId = 0): bool
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM titulus_voci_indice WHERE voce = ? AND id <> ?', [$voce, $escludiId]) > 0;
    }

    public function eliminaVoce(int $id): bool
    {
        if ((int) $this->db->valore('SELECT COUNT(*) FROM titulus_regole WHERE voce_indice_id = ?', [$id]) > 0) {
            return false;
        }

        return $this->db->esegui('DELETE FROM titulus_voci_indice WHERE id = ?', [$id]) >= 0;
    }

    /** @return list<array<string, mixed>> */
    public function repertori(bool $soloAttivi = false): array
    {
        return $this->db->righe('SELECT p.*, (SELECT COUNT(*) FROM titulus_regole r WHERE r.repertorio_id = p.id) AS usi FROM titulus_repertori p' . ($soloAttivi ? ' WHERE p.attivo = 1' : '') . ' ORDER BY p.codice');
    }

    public function salvaRepertorio(int $id, string $codice, string $nome, bool $attivo): int
    {
        if ($id > 0) {
            $this->db->esegui('UPDATE titulus_repertori SET codice = ?, nome = ?, attivo = ? WHERE id = ?', [$codice, $nome, (int) $attivo, $id]);

            return $id;
        }

        return $this->db->inserisci('INSERT INTO titulus_repertori (codice, nome, attivo) VALUES (?, ?, ?)', [$codice, $nome, (int) $attivo]);
    }

    public function repertorioPerCodice(string $codice, int $escludiId = 0): bool
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM titulus_repertori WHERE codice = ? AND id <> ?', [$codice, $escludiId]) > 0;
    }

    public function eliminaRepertorio(int $id): bool
    {
        if ((int) $this->db->valore('SELECT COUNT(*) FROM titulus_regole WHERE repertorio_id = ?', [$id]) > 0) {
            return false;
        }

        return $this->db->esegui('DELETE FROM titulus_repertori WHERE id = ?', [$id]) >= 0;
    }

    /** @return array<string, mixed>|null */
    public function voceIndice(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM titulus_voci_indice WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function repertorio(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM titulus_repertori WHERE id = ?', [$id]);
    }

    // ---- registro degli invii --------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $v */
    public function registraInvio(array $v): int
    {
        return $this->db->inserisci(
            'INSERT INTO titulus_invii (regola_id, applica_a, rif_tipo, rif_id, oggetto, tipo_documento, azione, esito, nrecord, num_prot, url, errore, file_principale, utente_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$v['regola_id'], $v['applica_a'], $v['rif_tipo'], $v['rif_id'], mb_substr((string) $v['oggetto'], 0, 500), $v['tipo_documento'], $v['azione'], $v['esito'], $v['nrecord'], $v['num_prot'], $v['url'], $v['errore'], mb_substr((string) ($v['file_principale'] ?? ''), 0, 255), $v['utente_id']]
        );
    }

    // ---- prenotazione dell'invio: mai due protocolli per lo stesso documento ---------------------------------------------------

    private ?string $database = null;

    /**
     * Il tipo di riferimento senza l'impronta del file («tipo~impronta» → «tipo»): il documento logico è lo stesso anche quando il file cambia.
     */
    public static function baseRif(string $rifTipo): string
    {
        $p = strpos($rifTipo, '~');

        return $p === false ? $rifTipo : substr($rifTipo, 0, $p);
    }

    /**
     * Il nome del blocco di un documento: il tipo di base (senza l'impronta del file) e l'id, perché il documento è lo stesso anche quando il file cambia
     * e due file della stessa compilazione non devono partire insieme. Comprende il nome del database e ha al massimo 64 caratteri, come vuole GET_LOCK.
     * Il blocco è del database e dura quanto la connessione: se il processo muore si libera da solo.
     */
    public static function nomeBloccoInvio(string $rifTipo, ?int $rifId, string $database): string
    {
        return 'tit_' . substr(hash('sha256', $database . '|' . self::baseRif($rifTipo) . '|' . (string) $rifId), 0, 40);
    }

    private function bloccoInvio(string $rifTipo, ?int $rifId): string
    {
        $this->database ??= (string) ($this->db->valore('SELECT DATABASE()') ?? '');

        return self::nomeBloccoInvio($rifTipo, $rifId, $this->database);
    }

    /** true se il blocco è ottenuto subito (non si aspetta: se un altro processo sta inviando, chi arriva dopo non invia). */
    public function bloccaInvio(string $rifTipo, ?int $rifId): bool
    {
        return (int) ($this->db->valore('SELECT GET_LOCK(?, 0)', [$this->bloccoInvio($rifTipo, $rifId)]) ?? 0) === 1;
    }

    public function rilasciaInvio(string $rifTipo, ?int $rifId): void
    {
        $this->db->valore('SELECT RELEASE_LOCK(?)', [$this->bloccoInvio($rifTipo, $rifId)]);
    }

    /** Il blocco è tenuto da un altro processo in questo momento? (un invio in corso) */
    public function invioInCorso(string $rifTipo, ?int $rifId): bool
    {
        return (int) ($this->db->valore('SELECT IS_FREE_LOCK(?)', [$this->bloccoInvio($rifTipo, $rifId)]) ?? 1) === 0;
    }

    /**
     * Scrive nel registro uno stato dell'invio: «in_invio» (prima di chiamare Titulus), «incerto» (la risposta non è arrivata) o
     * «verificato» (una persona ha controllato in Titulus: l'incertezza è chiusa).
     */
    public function segnaInvio(string $rifTipo, ?int $rifId, string $esito, string $oggetto, ?string $errore = null, ?int $utenteId = null): int
    {
        return $this->db->inserisci(
            'INSERT INTO titulus_invii (applica_a, rif_tipo, rif_id, oggetto, esito, errore, utente_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['', $rifTipo, $rifId, mb_substr($oggetto, 0, 500), $esito, $errore !== null ? mb_substr($errore, 0, 2000) : null, $utenteId]
        );
    }

    public function cambiaEsitoInvio(int $id, string $esito, ?string $errore): void
    {
        $this->db->esegui('UPDATE titulus_invii SET esito = ?, errore = ? WHERE id = ?', [$esito, $errore !== null ? mb_substr($errore, 0, 2000) : null, $id]);
    }

    public function togliInvio(int $id): void
    {
        $this->db->esegui("DELETE FROM titulus_invii WHERE id = ? AND esito = 'in_invio'", [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function invii(int $limite = 100, ?string $rifTipo = null, ?int $rifId = null): array
    {
        $limite = max(1, min(500, $limite));
        if ($rifTipo !== null) {
            return $this->db->righe("SELECT * FROM titulus_invii WHERE rif_tipo = ? AND rif_id <=> ? ORDER BY id DESC LIMIT $limite", [$rifTipo, $rifId]);
        }

        return $this->db->righe("SELECT i.*, r.nome AS regola_nome FROM titulus_invii i LEFT JOIN titulus_regole r ON r.id = i.regola_id ORDER BY i.id DESC LIMIT $limite");
    }

    /**
     * Il registro di un documento con TUTTI i suoi file (il riferimento di base e quelli con l'impronta), dal più recente: serve a vedere cosa è già
     * successo alla stessa compilazione anche con un file diverso.
     *
     * @return list<array<string, mixed>>
     */
    public function inviiDelDocumento(string $rifTipo, ?int $rifId, int $limite = 100): array
    {
        $base = self::baseRif($rifTipo);
        $limite = max(1, min(500, $limite));
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $base) . '~%';

        return $this->db->righe("SELECT * FROM titulus_invii WHERE (rif_tipo = ? OR rif_tipo LIKE ?) AND rif_id <=> ? ORDER BY id DESC LIMIT $limite", [$base, $like, $rifId]);
    }

    /** @return array<string, mixed>|null */
    public function invio(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM titulus_invii WHERE id = ?', [$id]);
    }

    /** Un'iter finito: il numero di protocollo si legge dopo, da Titulus. */
    public function aggiornaNumero(int $id, string $numProt, string $url): void
    {
        $this->db->esegui('UPDATE titulus_invii SET num_prot = ?, url = IF(? <> \'\', ?, url) WHERE id = ?', [$numProt, $url, $url, $id]);
    }

    /** L'indirizzo con cui aprire il documento in Titulus, chiesto a Titulus con getDocumentURL quando non era stato salvato. */
    public function salvaUrl(int $id, string $url): void
    {
        $this->db->esegui('UPDATE titulus_invii SET url = ? WHERE id = ?', [mb_substr($url, 0, 500), $id]);
    }

    /** Moduli con un passo «protocollo in uscita» nell'iter (gli attestati). @return list<array{id: int, titolo: string}> */
    public function moduliConUscita(): array
    {
        $out = [];
        foreach ($this->db->righe("SELECT id, titolo, iter_json FROM didattica_moduli WHERE ambito = 'didattica' AND tipo = 'online' AND iter_json LIKE '%uscita%' ORDER BY titolo") as $r) {
            foreach (json_decode((string) $r['iter_json'], true) ?: [] as $x) {
                if (is_array($x) && !empty($x['uscita'])) {
                    $out[] = ['id' => (int) $r['id'], 'titolo' => (string) $r['titolo']];
                    break;
                }
            }
        }

        return $out;
    }

    /** Moduli delle pratiche degli studenti, a cui si può collegare una regola. @return list<array{id: int, titolo: string}> */
    public function moduliPratiche(): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'titolo' => (string) $r['titolo']],
            $this->db->righe("SELECT id, titolo FROM didattica_moduli WHERE ambito = 'didattica' AND tipo = 'online' ORDER BY titolo")
        );
    }
}
