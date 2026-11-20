<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Eventi\Righe;
use InvalidArgumentException;

/**
 * Moduli (didattica_moduli): documenti da scaricare e moduli online, divisi per ambito.
 *
 * Ogni modulo è della «didattica» (la Modulistica degli studenti: documenti e moduli online che aprono una pratica) o dell'«amministrazione»
 * (la Modulistica amministrativa del personale: per ora solo documenti e link, mai pratiche). Tutte le letture prendono l'ambito e, se non lo si
 * indica, leggono quello della didattica: così tutto il codice che c'era prima (pratiche, iter, firme, promemoria, modulo.php, la Modulistica
 * pubblica) continua a vedere solo i moduli della didattica. Chi deve distinguere indica l'ambito; null in perId() vuol dire «di qualsiasi ambito»
 * (serve a modulo.php per dire con chiarezza che quel documento non apre pratiche). Le scritture non toccano mai un modulo di un altro ambito.
 */
final class ModuloRepository
{
    public const AMBITO_DIDATTICA = 'didattica';
    public const AMBITO_AMMINISTRAZIONE = 'amministrazione';
    /** @var list<string> */
    public const AMBITI = [self::AMBITO_DIDATTICA, self::AMBITO_AMMINISTRAZIONE];

    public function __construct(private Database $db)
    {
    }

    /**
     * Il modulo con questo id, solo se è dell'ambito indicato (null = di qualsiasi ambito).
     *
     * @return array<string, mixed>|null
     */
    public function perId(int $id, ?string $ambito = self::AMBITO_DIDATTICA): ?array
    {
        if ($ambito === null) {
            return $this->db->riga('SELECT * FROM didattica_moduli WHERE id = ?', [$id]);
        }

        return $this->db->riga('SELECT * FROM didattica_moduli WHERE id = ? AND ambito = ?', [$id, $ambito]);
    }

    /**
     * Tutti i moduli dell'ambito con il numero di pratiche e di quelle ancora aperte (pannello).
     *
     * @return list<array<string, string|null>>
     */
    public function elenco(string $ambito = self::AMBITO_DIDATTICA): array
    {
        return Righe::testo($this->db->righe(
            "SELECT m.*, (SELECT COUNT(*) FROM pratiche p WHERE p.modulo_id = m.id) AS n_pratiche,
                    (SELECT COUNT(*) FROM pratiche p WHERE p.modulo_id = m.id AND p.stato IN ('inviata', 'in_lavorazione', 'integrazione')) AS n_aperte
             FROM didattica_moduli m WHERE m.ambito = ? ORDER BY m.categoria, m.ordine, m.titolo",
            [$ambito]
        ));
    }

    /**
     * Moduli pubblicati dell'ambito divisi per categoria (pagine pubbliche della modulistica).
     *
     * @return array<string, list<array<string, string|null>>>
     */
    public function pubblicatiPerCategoria(string $ambito = self::AMBITO_DIDATTICA): array
    {
        $out = [];
        foreach (Righe::testo($this->db->righe('SELECT * FROM didattica_moduli WHERE attivo = 1 AND ambito = ? ORDER BY categoria, ordine, titolo', [$ambito])) as $x) {
            $out[$x['categoria']][] = $x;
        }

        return $out;
    }

    /**
     * Moduli dell'ambito che hanno un iter (JSON degli uffici).
     *
     * @return list<array<string, string|null>>
     */
    public function conIter(string $ambito = self::AMBITO_DIDATTICA): array
    {
        return Righe::testo($this->db->righe('SELECT iter_json FROM didattica_moduli WHERE iter_json IS NOT NULL AND ambito = ?', [$ambito]));
    }

    /**
     * Crea il modulo (id = 0) o aggiorna quello indicato. Ritorna l'id. L'ambito si scrive solo alla creazione; un aggiornamento tocca soltanto un
     * modulo di quell'ambito (se l'id è di un altro ambito non cambia niente).
     *
     * @param array<string, mixed> $d titolo, categoria, descrizione, tipo, link, campi_json, verbale_json, iter_json, destinatari, email_ufficio, attivo, ordine, aperto_dal, aperto_al, giorni_promemoria
     */
    public function salva(int $id, array $d, string $ambito = self::AMBITO_DIDATTICA): int
    {
        if (!in_array($ambito, self::AMBITI, true)) {
            throw new InvalidArgumentException("Ambito del modulo sconosciuto: $ambito");
        }
        $v = [$d['titolo'], $d['categoria'], $d['descrizione'], $d['tipo'], $d['link'], $d['campi_json'], $d['verbale_json'], $d['iter_json'], $d['destinatari'], $d['email_ufficio'],
              (int) $d['attivo'], (int) $d['ordine'], $d['aperto_dal'], $d['aperto_al'], (int) $d['giorni_promemoria']];
        if ($id) {
            $this->db->esegui(
                'UPDATE didattica_moduli SET titolo=?, categoria=?, descrizione=?, tipo=?, link=?, campi_json=?, verbale_json=?, iter_json=?, destinatari=?, email_ufficio=?, attivo=?, ordine=?, aperto_dal=?, aperto_al=?, giorni_promemoria=?, aggiornato_il=NOW() WHERE id=? AND ambito=?',
                [...$v, $id, $ambito]
            );

            return $id;
        }

        return $this->db->inserisci(
            'INSERT INTO didattica_moduli (titolo, categoria, descrizione, tipo, link, campi_json, verbale_json, iter_json, destinatari, email_ufficio, attivo, ordine, aperto_dal, aperto_al, giorni_promemoria, ambito, aggiornato_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [...$v, $ambito]
        );
    }

    public function impostaDomanda(int $id, string $json): void
    {
        $this->db->esegui('UPDATE didattica_moduli SET domanda_json = ? WHERE id = ?', [$json, $id]);
    }

    public function impostaInVerbale(int $id, bool $si): void
    {
        $this->db->esegui('UPDATE didattica_moduli SET in_verbale = ? WHERE id = ?', [$si ? 1 : 0, $id]);
    }

    public function impostaFile(int $id, string $percorso): void
    {
        $this->db->esegui('UPDATE didattica_moduli SET file_path = ? WHERE id = ?', [$percorso, $id]);
    }

    public function nascondi(int $id): void
    {
        $this->db->esegui('UPDATE didattica_moduli SET attivo = 0 WHERE id = ?', [$id]);
    }

    /** Elimina il modulo solo se è dell'ambito indicato. */
    public function elimina(int $id, string $ambito = self::AMBITO_DIDATTICA): void
    {
        $this->db->esegui('DELETE FROM didattica_moduli WHERE id = ? AND ambito = ?', [$id, $ambito]);
    }

    public function contaPratiche(int $id): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM pratiche WHERE modulo_id = ?', [$id]);
    }
}
