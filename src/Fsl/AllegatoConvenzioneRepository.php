<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Database;
use App\Eventi\Righe;

/** Allegati A aggiornati dalle scuole con una convenzione valida (tabella convenzioni_allegati): integrano quello registrato, non lo sostituiscono. */
final class AllegatoConvenzioneRepository
{
    public const DA_VEDERE = 'da_vedere';
    public const ACCETTATO = 'accettato';
    public const RIFIUTATO = 'rifiutato';

    public function __construct(private Database $db)
    {
    }

    public function aggiungi(int $convenzioneId, string $file, string $attivitaJson, string $caricatoDa): int
    {
        return $this->db->inserisci(
            'INSERT INTO convenzioni_allegati (convenzione_id, file, attivita_json, caricato_da, stato) VALUES (?, ?, ?, ?, ?)',
            [$convenzioneId, $file, $attivitaJson, mb_substr($caricatoDa, 0, 255), self::DA_VEDERE]
        );
    }

    /** @return array<string, string|null>|null */
    public function perId(int $id): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM convenzioni_allegati WHERE id = ?', [$id]));
    }

    /**
     * Gli allegati della convenzione, dal più recente.
     *
     * @return list<array<string, string|null>>
     */
    public function della(int $convenzioneId): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_allegati WHERE convenzione_id = ? ORDER BY id DESC', [$convenzioneId]));
    }

    /** L'ufficio accetta o rifiuta (con un motivo). Vale solo se è ancora da vedere. */
    public function esamina(int $id, string $stato, string $nota, string $protocollo, string $da): bool
    {
        return $this->db->esegui(
            'UPDATE convenzioni_allegati SET stato = ?, nota = ?, protocollo = ?, esaminato_il = NOW(), esaminato_da = ? WHERE id = ? AND stato = ?',
            [$stato, mb_substr($nota, 0, 500), mb_substr($protocollo, 0, 100), mb_substr($da, 0, 255), $id, self::DA_VEDERE]
        ) === 1;
    }

    /** Quanti allegati aspettano l'ufficio. */
    public function daVedere(): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_allegati WHERE stato = ?', [self::DA_VEDERE]);
    }

    /**
     * Toglie tutti gli allegati della convenzione (si cancella con la convenzione del registro).
     *
     * @return list<string> i file da cancellare dal disco
     */
    public function eliminaDellaConvenzione(int $convenzioneId): array
    {
        $file = array_values(array_filter(array_map(static fn (array $r): string => (string) $r['file'], $this->db->righe('SELECT file FROM convenzioni_allegati WHERE convenzione_id = ?', [$convenzioneId]))));
        $this->db->esegui('DELETE FROM convenzioni_allegati WHERE convenzione_id = ?', [$convenzioneId]);

        return $file;
    }
}
