<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Core\Database;

/**
 * Le richieste di pubblicazione (tabella pubblicazioni) e i loro file (pubblicazioni_allegati). Una riga è una richiesta del docente
 * (origine «form») o una riga aggiunta a mano dal pannello (origine «manuale»); nel_monitoraggio dice se è nella tabella di monitoraggio
 * (il file Excel). I controlli sui dati e sui permessi li fa ServizioPubblicazioni.
 */
final class PubblicazioniRepository
{
    /** I campi che inserisci() accetta: quelli del docente, quelli di servizio e le colonne degli operatori. */
    private const CAMPI_INSERIMENTO = [
        'utente_id', 'richiedente', 'origine', 'stato', 'inviata_il', 'nel_monitoraggio', 'monitoraggio_dal', 'monitoraggio_da',
    ];

    public function __construct(private Database $db, private AllegatiPubblicazioni $file)
    {
    }

    /**
     * Nuova riga. Ritorna l'id (0 se non riesce). I campi non ammessi si ignorano.
     *
     * @param array<string, mixed> $campi
     */
    public function inserisci(array $campi): int
    {
        $ammessi = array_merge(self::CAMPI_INSERIMENTO, RegolePubblicazione::CAMPI_DOCENTE, ColonneMonitoraggio::colonneOperatori());
        $campi = array_intersect_key($campi, array_flip($ammessi));
        if ($campi === []) {
            return 0;
        }
        $nomi = array_keys($campi);

        return $this->db->inserisci(
            'INSERT INTO pubblicazioni (' . implode(', ', $nomi) . ') VALUES (' . implode(', ', array_fill(0, count($nomi), '?')) . ')',
            array_values($campi)
        );
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM pubblicazioni WHERE id = ?', [$id]);
    }

    /**
     * Elenco con filtri, dalla data dell'evento più recente.
     * $filtri: stato (una chiave di Costanti::STATI), dal / al («Y-m-d», sulla data dell'evento), testo (titolo, sottotitolo, richiedente, tag),
     * monitoraggio: 'si' = solo le righe nella tabella di monitoraggio, 'no' = solo quelle non ancora inviate, altro = tutte.
     *
     * @param array{stato?: string, dal?: string, al?: string, testo?: string, monitoraggio?: string} $filtri
     * @return list<array<string, mixed>>
     */
    public function elenco(array $filtri = []): array
    {
        $dove = [];
        $par = [];
        $stato = (string) ($filtri['stato'] ?? '');
        if (isset(Costanti::STATI[$stato])) {
            $dove[] = 'stato = ?';
            $par[] = $stato;
        }
        foreach (['dal' => '>=', 'al' => '<='] as $k => $op) {
            $v = (string) ($filtri[$k] ?? '');
            if (RegolePubblicazione::data($v)) {
                $dove[] = "data_evento $op ?";
                $par[] = $v;
            }
        }
        $testo = trim((string) ($filtri['testo'] ?? ''));
        if ($testo !== '') {
            $like = '%' . addcslashes($testo, '%_\\') . '%';
            $dove[] = '(titolo LIKE ? OR sottotitolo LIKE ? OR richiedente LIKE ? OR tag LIKE ?)';
            array_push($par, $like, $like, $like, $like);
        }
        $m = (string) ($filtri['monitoraggio'] ?? '');
        if ($m === 'si') {
            $dove[] = 'nel_monitoraggio = 1';
        } elseif ($m === 'no') {
            $dove[] = 'nel_monitoraggio = 0';
        }

        return $this->db->righe(
            'SELECT * FROM pubblicazioni' . ($dove ? ' WHERE ' . implode(' AND ', $dove) : '') . ' ORDER BY data_evento DESC, id DESC',
            $par
        );
    }

    /** Le richieste inviate da un docente, dalla più recente. @return list<array<string, mixed>> */
    public function delUtente(int $utenteId): array
    {
        return $this->db->righe('SELECT * FROM pubblicazioni WHERE utente_id = ? ORDER BY inviata_il DESC, id DESC', [$utenteId]);
    }

    /**
     * Scrive le colonne degli operatori indicate (le altre chiavi si ignorano; '' diventa NULL) e chi/quando ha modificato.
     *
     * @param array<string, string|null> $colonne
     */
    public function aggiornaColonneOperatori(int $id, array $colonne, int $da, string $quando): void
    {
        $colonne = array_intersect_key($colonne, array_flip(ColonneMonitoraggio::colonneOperatori()));
        if ($colonne === []) {
            return;
        }
        $set = [];
        $par = [];
        foreach ($colonne as $k => $v) {
            $set[] = "$k = ?";
            $par[] = ($v === null || trim($v) === '') ? null : $v;
        }
        array_push($par, $quando, $da, $id);
        $this->db->esegui('UPDATE pubblicazioni SET ' . implode(', ', $set) . ', modificata_il = ?, modificata_da = ? WHERE id = ?', $par);
    }

    /**
     * Precompila le colonne degli operatori indicate solo dove sono ancora vuote (NULL o ''): quanto già scritto non si tocca.
     * Non cambia modificata_il/da (non è una modifica di un operatore).
     *
     * @param array<string, string> $valori
     */
    public function precompila(int $id, array $valori): void
    {
        foreach (array_intersect_key($valori, array_flip(ColonneMonitoraggio::colonneOperatori())) as $k => $v) {
            $this->db->esegui("UPDATE pubblicazioni SET $k = ? WHERE id = ? AND ($k IS NULL OR $k = '')", [$v, $id]);
        }
    }

    public function cambiaStato(int $id, string $stato, int $da, string $quando): void
    {
        if (isset(Costanti::STATI[$stato])) {
            $this->db->esegui('UPDATE pubblicazioni SET stato = ?, modificata_il = ?, modificata_da = ? WHERE id = ?', [$stato, $quando, $da, $id]);
        }
    }

    /** Mette la riga nella tabella di monitoraggio (con chi e da quando) o la toglie (le colonne degli operatori restano salvate). */
    public function impostaMonitoraggio(int $id, bool $dentro, ?int $da, ?string $quando): void
    {
        $this->db->esegui(
            'UPDATE pubblicazioni SET nel_monitoraggio = ?, monitoraggio_dal = ?, monitoraggio_da = ? WHERE id = ?',
            [$dentro ? 1 : 0, $dentro ? $quando : null, $dentro ? $da : null, $id]
        );
    }

    /** Un file della richiesta. Ritorna l'id. */
    public function inserisciAllegato(int $pubblicazioneId, string $tipo, string $nomeOriginale, string $percorso, int $dimensione, string $quando): int
    {
        return $this->db->inserisci(
            'INSERT INTO pubblicazioni_allegati (pubblicazione_id, tipo, nome_originale, percorso, dimensione, caricato_il) VALUES (?, ?, ?, ?, ?, ?)',
            [$pubblicazioneId, $tipo, mb_substr($nomeOriginale, 0, 255), $percorso, $dimensione, $quando]
        );
    }

    /** @return list<array<string, mixed>> i file della richiesta: prima gli allegati, poi le immagini, nell'ordine di caricamento */
    public function allegati(int $pubblicazioneId): array
    {
        return $this->db->righe("SELECT * FROM pubblicazioni_allegati WHERE pubblicazione_id = ? ORDER BY tipo = 'immagine', id", [$pubblicazioneId]);
    }

    /** Un file, solo se appartiene a quella richiesta. @return array<string, mixed>|null */
    public function allegato(int $pubblicazioneId, int $allegatoId): ?array
    {
        return $this->db->riga('SELECT * FROM pubblicazioni_allegati WHERE id = ? AND pubblicazione_id = ?', [$allegatoId, $pubblicazioneId]);
    }

    /** Elimina la riga, i suoi file dal disco e (a cascata) le righe dei file. False se la riga non c'era. */
    public function elimina(int $id): bool
    {
        $percorsi = array_map(static fn (array $a): string => (string) $a['percorso'], $this->allegati($id));
        if ($this->db->esegui('DELETE FROM pubblicazioni WHERE id = ?', [$id]) <= 0) {
            return false;
        }
        foreach ($percorsi as $p) {
            $this->file->elimina($p);
        }

        return true;
    }
}
