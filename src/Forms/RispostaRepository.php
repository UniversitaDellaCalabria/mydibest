<?php

declare(strict_types=1);

namespace App\Forms;

use App\Core\Database;

/** Le risposte a un form di MyForms (forms_risposte). */
final class RispostaRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM forms_risposte WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> dalla più recente */
    public function delForm(int $formId): array
    {
        return $this->db->righe('SELECT * FROM forms_risposte WHERE form_id = ? ORDER BY inviata_il DESC', [$formId]);
    }

    /**
     * Una risposta, solo se è di questo form (l'id da solo non basta: arriva dalla richiesta). Con $conNomi anche nome e cognome di chi ha risposto.
     *
     * @return array<string, mixed>|null
     */
    public function delFormPerId(int $formId, int $id, bool $conNomi = false): ?array
    {
        return $this->db->riga(
            'SELECT r.*' . ($conNomi ? ', u.nome, u.cognome' : '') . ' FROM forms_risposte r' . ($conNomi ? ' LEFT JOIN utenti u ON u.id = r.utente_id' : '') . ' WHERE r.id = ? AND r.form_id = ?',
            [$id, $formId]
        );
    }

    /**
     * Le risposte di un form, dalla più recente, con i filtri di periodo e di profilo (il testo si cerca in PHP: sta dentro il JSON). Con $conNomi
     * ci sono anche nome e cognome di chi ha risposto (dall'anagrafe degli utenti): nei form anonimi non si chiedono mai.
     *
     * @param string|null $dal giorno «Y-m-d» (compreso)
     * @param string|null $al giorno «Y-m-d» (compreso)
     * @return list<array<string, mixed>>
     */
    public function filtrate(int $formId, bool $conNomi, ?string $profilo, ?string $dal, ?string $al): array
    {
        $dove = ['r.form_id = ?'];
        $par = [$formId];
        if ($profilo !== null && $profilo !== '') {
            $dove[] = 'r.profilo = ?';
            $par[] = $profilo;
        }
        if ($dal !== null && $dal !== '') {
            $dove[] = 'r.inviata_il >= ?';
            $par[] = $dal . ' 00:00:00';
        }
        if ($al !== null && $al !== '') {
            $dove[] = 'r.inviata_il <= ?';
            $par[] = $al . ' 23:59:59';
        }

        return $this->db->righe(
            'SELECT r.*' . ($conNomi ? ', u.nome, u.cognome' : '') . ' FROM forms_risposte r' . ($conNomi ? ' LEFT JOIN utenti u ON u.id = r.utente_id' : '')
            . ' WHERE ' . implode(' AND ', $dove) . ' ORDER BY r.inviata_il DESC, r.id DESC',
            $par
        );
    }

    /** I profili che compaiono tra le risposte del form (per il filtro). @return list<string> */
    public function profili(int $formId): array
    {
        return array_map('strval', array_column($this->db->righe("SELECT DISTINCT profilo FROM forms_risposte WHERE form_id = ? AND profilo <> '' ORDER BY profilo", [$formId]), 'profilo'));
    }

    public function conta(int $formId): int
    {
        return (int) ($this->db->valore('SELECT COUNT(*) FROM forms_risposte WHERE form_id = ?', [$formId]) ?? 0);
    }

    /** Quante risposte del form sono state inviate prima di questo momento («Y-m-d H:i:s», escluso). */
    public function contaInviatePrima(int $formId, string $prima): int
    {
        return (int) ($this->db->valore('SELECT COUNT(*) FROM forms_risposte WHERE form_id = ? AND inviata_il < ?', [$formId, $prima]) ?? 0);
    }

    /** Id delle risposte del form inviate prima di questo momento («Y-m-d H:i:s», escluso), dalla più vecchia. @return list<int> */
    public function idInviatePrima(int $formId, string $prima): array
    {
        return array_map('intval', array_column($this->db->righe('SELECT id FROM forms_risposte WHERE form_id = ? AND inviata_il < ? ORDER BY inviata_il, id', [$formId, $prima]), 'id'));
    }

    /**
     * La risposta già data dalla stessa persona a questo form (per «una sola risposta per persona» e per riconoscere la propria risposta da
     * modificare), riconosciuta dall'impronta (hash di form_id|utente_id|chiave del .env: vedi ServizioForms), non dall'utente_id (che nei form
     * anonimi resta NULL). Se c'è.
     *
     * @return array<string, mixed>|null
     */
    public function diPersona(int $formId, string $utenteImpronta): ?array
    {
        return $this->db->riga('SELECT * FROM forms_risposte WHERE form_id = ? AND utente_impronta = ? ORDER BY id DESC LIMIT 1', [$formId, $utenteImpronta]);
    }

    /**
     * Le risposte date da una persona a più form, riconosciute dalle impronte (una per form: vedi ServizioForms), dalla più recente.
     *
     * @param list<string> $impronte
     * @return list<array<string, mixed>>
     */
    public function dellePersone(array $impronte): array
    {
        $impronte = array_values(array_unique(array_filter($impronte)));
        $out = [];
        // a blocchi: un elenco molto lungo di segnaposto non serve, i form sono pochi
        foreach (array_chunk($impronte, 500) as $blocco) {
            foreach ($this->db->righe(
                'SELECT * FROM forms_risposte WHERE utente_impronta IN (' . implode(',', array_fill(0, count($blocco), '?')) . ')',
                $blocco
            ) as $r) {
                $out[] = $r;
            }
        }
        usort($out, static fn (array $a, array $b): int => [(string) $b['inviata_il'], (int) $b['id']] <=> [(string) $a['inviata_il'], (int) $a['id']]);

        return $out;
    }

    /** Nuova risposta. Ritorna l'id. */
    public function inserisci(int $formId, ?int $utenteId, ?string $email, string $profilo, string $risposteJson, string $codiceRicevuta, ?string $ipImpronta, ?string $utenteImpronta): int
    {
        return $this->db->inserisci(
            'INSERT INTO forms_risposte (form_id, utente_id, email, profilo, risposte_json, codice_ricevuta, ip_impronta, utente_impronta, inviata_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$formId, $utenteId, $email, mb_substr($profilo, 0, 20), $risposteJson, $codiceRicevuta, $ipImpronta, $utenteImpronta]
        );
    }

    public function aggiorna(int $id, string $risposteJson): void
    {
        $this->db->esegui('UPDATE forms_risposte SET risposte_json = ?, modificata_il = NOW() WHERE id = ?', [$risposteJson, $id]);
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM forms_risposte WHERE id = ?', [$id]);
    }

    public function eliminaDelForm(int $formId): void
    {
        $this->db->esegui('DELETE FROM forms_risposte WHERE form_id = ?', [$formId]);
    }
}
