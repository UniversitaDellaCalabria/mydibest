<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Core\Sito;

/**
 * Elimina del tutto una pratica (anche inviata o protocollata): serve a togliere le pratiche di prova. Si cancellano la pratica, lo
 * storico, le firme, le conferme dei docenti, il sondaggio, le assegnazioni e i file della cartella degli allegati. Il protocollo
 * assegnato da Titulus non si annulla da qui (resta nel registro dei protocolli del portale e in Titulus).
 */
final class EliminaPratica
{
    /** Tabelle figlie con la colonna pratica_id. */
    private const FIGLIE = ['pratiche_eventi', 'pratiche_firme', 'pratiche_conferme', 'pratiche_sondaggi', 'pratiche_operatori'];

    public function __construct(private Database $db, private Sito $sito, private AllegatiPratiche $allegati)
    {
    }

    /**
     * @return array{errore: string|null, codice: string, protocollo: string, file: int}
     */
    public function elimina(int $id): array
    {
        $p = $this->db->riga('SELECT id, codice, protocollo, domanda_pdf FROM pratiche WHERE id = ?', [$id]);
        if (!$p) {
            return ['errore' => 'Pratica non trovata.', 'codice' => '', 'protocollo' => '', 'file' => 0];
        }
        $percorsi = [(string) ($p['domanda_pdf'] ?? '')];
        foreach ($this->db->righe("SELECT allegato AS f FROM pratiche_eventi WHERE pratica_id = ? AND allegato IS NOT NULL AND allegato <> ''", [$id]) as $r) {
            $percorsi[] = (string) $r['f'];
        }
        foreach ($this->db->righe('SELECT file_origine, file_firmato FROM pratiche_firme WHERE pratica_id = ?', [$id]) as $r) {
            $percorsi[] = (string) ($r['file_origine'] ?? '');
            $percorsi[] = (string) ($r['file_firmato'] ?? '');
        }

        foreach (self::FIGLIE as $tabella) {
            $this->db->esegui("DELETE FROM $tabella WHERE pratica_id = ?", [$id]);
        }
        $this->db->esegui('DELETE FROM pratiche WHERE id = ?', [$id]);

        return ['errore' => null, 'codice' => (string) $p['codice'], 'protocollo' => (string) $p['protocollo'], 'file' => $this->togliFile($percorsi)];
    }

    /** Toglie i file (solo dentro la cartella degli allegati delle pratiche). */
    private function togliFile(array $percorsi): int
    {
        $base = realpath($this->sito->radice() . '/' . rtrim($this->allegati->cartella(), '/'));
        $n = 0;
        foreach (array_unique(array_filter($percorsi)) as $rel) {
            $f = realpath($this->sito->radice() . '/' . $rel);
            if ($base && $f && strpos($f, $base . DIRECTORY_SEPARATOR) === 0 && is_file($f) && @unlink($f)) {
                $n++;
            }
        }

        return $n;
    }
}
