<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Database;
use App\Eventi\Righe;

/** Bandi di tutorato (tabella tutorato_bandi). */
final class BandoRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM tutorato_bandi WHERE id = ?', [$id]);
    }

    /**
     * Tutti i bandi, dal più recente, con il numero di lettere e di lettere firmate (valori come testo).
     *
     * @return list<array<string, string|null>>
     */
    public function elenco(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT b.*, (SELECT COUNT(*) FROM tutorato_incarichi i WHERE i.bando_id = b.id AND i.stato <> 'annullata') AS n_incarichi,
                    (SELECT COUNT(*) FROM tutorato_incarichi i WHERE i.bando_id = b.id AND i.stato IN ('firmata', 'protocollata')) AS n_firmate
             FROM tutorato_bandi b ORDER BY b.creato_il DESC"
        ));
    }

    /** Iter delle lettere del bando (portale | uniticket). */
    public function impostaIter(int $id, string $iter): void
    {
        $this->db->esegui('UPDATE tutorato_bandi SET iter = ? WHERE id = ?', [Costanti::iterValido($iter), $id]);
    }

    /** Imposta il file PDF di uno dei decreti (colonna dalla lista consentita). */
    public function impostaFile(int $id, string $colonna, string $percorso): void
    {
        if (in_array($colonna, FileTutorato::DECRETI, true)) {
            $this->db->esegui("UPDATE tutorato_bandi SET $colonna = ? WHERE id = ?", [$percorso, $id]);
        }
    }

    /** Estremi del decreto di approvazione atti. */
    public function impostaDecretoAtti(int $id, string $numero, ?string $data): void
    {
        $this->db->esegui('UPDATE tutorato_bandi SET decreto_atti = ?, decreto_atti_data = ? WHERE id = ?', [$numero, $data, $id]);
    }

    /**
     * Numero e data del decreto di approvazione atti, controllando la data (vuota = nessuna data; una data che non esiste non si salva e dà un errore).
     * Ritorna l'errore o null.
     */
    public function salvaDecretoAtti(int $id, string $numero, string $data): ?string
    {
        $data = trim($data);
        if ($data !== '' && !\App\Core\DataIso::valida($data)) {
            return "La data dell'approvazione atti non esiste: il decreto non è stato salvato.";
        }
        $this->impostaDecretoAtti($id, mb_substr(trim($numero), 0, 100), $data !== '' ? $data : null);

        return null;
    }

    /** Ufficio di Ateneo che paga i tutor del bando (null = nessuno). */
    public function impostaUfficio(int $id, ?int $ufficioId): void
    {
        $this->db->esegui('UPDATE tutorato_bandi SET ufficio_ateneo_id = ? WHERE id = ?', [$ufficioId, $id]);
    }

    /** @param list<mixed> $v titolo, anno, decreto bando e data, decreto commissione e data, direttore (persona, nome, email, cf), operatore, luogo */
    public function aggiorna(int $id, array $v): void
    {
        $this->db->esegui(
            'UPDATE tutorato_bandi SET titolo=?, anno_accademico=?, decreto_bando=?, decreto_bando_data=?, decreto_commissione=?, decreto_commissione_data=?, direttore_persona_id=?, direttore_nome=?, direttore_email=?, direttore_cf=?, operatore_id=?, luogo=?, aggiornato_il=NOW() WHERE id=?',
            [...$v, $id]
        );
    }

    /** @param list<mixed> $v come aggiorna(), seguiti da chi lo crea */
    public function inserisci(array $v): int
    {
        return $this->db->inserisci(
            'INSERT INTO tutorato_bandi (titolo, anno_accademico, decreto_bando, decreto_bando_data, decreto_commissione, decreto_commissione_data, direttore_persona_id, direttore_nome, direttore_email, direttore_cf, operatore_id, luogo, creato_da) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $v
        );
    }
}
