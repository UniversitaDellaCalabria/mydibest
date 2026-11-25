<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Core\Database;

/**
 * «Questo id è di questa scuola?» Un solo punto, usato dalla pagina «Scuole e convenzioni» PRIMA di eseguire qualsiasi azione con un id:
 * una compilazione, una convenzione, un Allegato A o una prenotazione di un'altra scuola non si toccano dalla scheda aperta.
 *
 * Una scuola è identificata dalla chiave della scheda: il codice dell'ISTITUTO (una convenzione sulla sede vale per tutte le sedi) oppure,
 * per le scuole scritte a mano, la chiave «x…» calcolata dal nome scritto nel modulo (la stessa di SchedaScuolaFsl).
 * Una scuola scritta a mano non ha compilazioni né convenzioni: tutto ciò che non sono prenotazioni è «di un'altra scuola».
 */
final class AppartenenzaScuolaFsl
{
    public const MESSAGGIO = 'Non è di questa scuola.';

    public function __construct(private Database $db, private ServizioScuole $scuole)
    {
    }

    /** Il codice dell'istituto di un codice meccanografico ('' se non è un codice valido). */
    private function istituto(?string $codice): string
    {
        return $this->scuole->codiceIstituto($codice);
    }

    public function compilazione(int $id, string $istituto): bool
    {
        if ($id <= 0 || $istituto === '') {
            return false;
        }
        $riga = $this->db->riga('SELECT scuola_codice FROM convenzioni_compilate WHERE id = ?', [$id]);

        return $riga !== null && $this->istituto((string) $riga['scuola_codice']) === $istituto;
    }

    public function convenzione(int $id, string $istituto): bool
    {
        if ($id <= 0 || $istituto === '') {
            return false;
        }
        $riga = $this->db->riga('SELECT scuola_codice FROM convenzioni_scuole WHERE id = ?', [$id]);

        return $riga !== null && $this->istituto((string) $riga['scuola_codice']) === $istituto;
    }

    public function allegato(int $id, string $istituto): bool
    {
        if ($id <= 0 || $istituto === '') {
            return false;
        }
        $riga = $this->db->riga('SELECT c.scuola_codice FROM convenzioni_allegati a JOIN convenzioni_scuole c ON c.id = a.convenzione_id WHERE a.id = ?', [$id]);

        return $riga !== null && $this->istituto((string) $riga['scuola_codice']) === $istituto;
    }

    /** Una scuola della scheda (codice qualsiasi: sede o istituto) è questo istituto? */
    public function codiceScuola(string $codice, string $istituto): bool
    {
        return $istituto !== '' && $this->istituto($codice) === $istituto;
    }

    /** @param string $chiave la chiave della scheda (codice dell'istituto o «x…» per le scuole scritte a mano) */
    public function prenotazione(int $id, string $istituto, string $chiave): bool
    {
        if ($id <= 0) {
            return false;
        }
        $riga = $this->db->riga('SELECT id, scuola_codice, dati_custom_json, nome, cognome FROM prenotazioni WHERE id = ?', [$id]);
        if ($riga === null) {
            return false;
        }
        $codice = $this->istituto((string) $riga['scuola_codice']);
        if ($istituto !== '') {
            return $codice === $istituto;
        }
        if ($codice !== '' || preg_match('/^x[0-9a-f]{12}$/', $chiave) !== 1) {
            return false;
        }
        $scritta = ServizioScuole::nomeScrittoNelModulo($riga);

        return 'x' . substr(md5(mb_strtolower(trim($scritta) ?: 'p' . $riga['id'])), 0, 12) === $chiave;
    }
}
