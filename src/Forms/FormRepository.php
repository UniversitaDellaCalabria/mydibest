<?php

declare(strict_types=1);

namespace App\Forms;

use App\Core\Database;

/** I form di MyForms (forms) e i loro co-gestori (forms_gestori). */
final class FormRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM forms WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function perCodice(string $codice): ?array
    {
        return $this->db->riga('SELECT * FROM forms WHERE codice = ?', [$codice]);
    }

    /** Nuovo token pubblico, mai usato (collisione praticamente impossibile: 20 byte casuali). */
    public function nuovoCodice(): string
    {
        return bin2hex(random_bytes(20));
    }

    /**
     * Nuovo form in «bozza». Ritorna l'id.
     *
     * @param array<string, mixed> $dati titolo, descrizione, campi_json, pubblico_json, aperta_dal, aperta_al, una_per_persona, modificabile, anonima,
     *                                    max_risposte, messaggio_fine, email_notifica, ricevuta_email, conserva_mesi, proprietario_id
     */
    public function crea(string $codice, array $dati): int
    {
        return $this->db->inserisci(
            'INSERT INTO forms (codice, titolo, descrizione, campi_json, pubblico_json, stato, aperta_dal, aperta_al, una_per_persona, modificabile, anonima, max_risposte,
                                 messaggio_fine, email_notifica, ricevuta_email, conserva_mesi, proprietario_id)
             VALUES (?, ?, ?, ?, ?, \'bozza\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $codice, mb_substr((string) ($dati['titolo'] ?? ''), 0, 200), $dati['descrizione'] ?? null, $dati['campi_json'] ?? null, $dati['pubblico_json'] ?? null,
                $dati['aperta_dal'] ?? null, $dati['aperta_al'] ?? null,
                !empty($dati['una_per_persona']) ? 1 : 0, !empty($dati['modificabile']) ? 1 : 0, !empty($dati['anonima']) ? 1 : 0, (int) ($dati['max_risposte'] ?? 0),
                mb_substr((string) ($dati['messaggio_fine'] ?? ''), 0, 1000), mb_substr((string) ($dati['email_notifica'] ?? ''), 0, 500), !empty($dati['ricevuta_email']) ? 1 : 0,
                $dati['conserva_mesi'] ?? null, (int) $dati['proprietario_id'],
            ]
        );
    }

    /**
     * Dati generali e opzioni del form (non il codice né il proprietario: non si cambiano da qui).
     *
     * @param array<string, mixed> $dati vedi crea()
     */
    public function aggiorna(int $id, array $dati): void
    {
        $this->db->esegui(
            'UPDATE forms SET titolo = ?, descrizione = ?, campi_json = ?, pubblico_json = ?, aperta_dal = ?, aperta_al = ?, una_per_persona = ?, modificabile = ?, anonima = ?,
                               max_risposte = ?, messaggio_fine = ?, email_notifica = ?, ricevuta_email = ?, conserva_mesi = ?, modificato_il = NOW()
             WHERE id = ?',
            [
                mb_substr((string) ($dati['titolo'] ?? ''), 0, 200), $dati['descrizione'] ?? null, $dati['campi_json'] ?? null, $dati['pubblico_json'] ?? null,
                $dati['aperta_dal'] ?? null, $dati['aperta_al'] ?? null,
                !empty($dati['una_per_persona']) ? 1 : 0, !empty($dati['modificabile']) ? 1 : 0, !empty($dati['anonima']) ? 1 : 0, (int) ($dati['max_risposte'] ?? 0),
                mb_substr((string) ($dati['messaggio_fine'] ?? ''), 0, 1000), mb_substr((string) ($dati['email_notifica'] ?? ''), 0, 500), !empty($dati['ricevuta_email']) ? 1 : 0,
                $dati['conserva_mesi'] ?? null, $id,
            ]
        );
    }

    public function impostaStato(int $id, string $stato): void
    {
        $this->db->esegui('UPDATE forms SET stato = ?, modificato_il = NOW() WHERE id = ?', [$stato, $id]);
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM forms_risposte WHERE form_id = ?', [$id]);
        $this->db->esegui('DELETE FROM forms_gestori WHERE form_id = ?', [$id]);
        $this->db->esegui('DELETE FROM forms WHERE id = ?', [$id]);
    }

    /** Id dei co-gestori del form (non il proprietario). @return list<int> */
    public function gestori(int $formId): array
    {
        return array_map('intval', array_column($this->db->righe('SELECT utente_id FROM forms_gestori WHERE form_id = ? ORDER BY utente_id', [$formId]), 'utente_id'));
    }

    public function aggiungiGestore(int $formId, int $utenteId): void
    {
        $this->db->esegui('INSERT IGNORE INTO forms_gestori (form_id, utente_id) VALUES (?, ?)', [$formId, $utenteId]);
    }

    public function togliGestore(int $formId, int $utenteId): void
    {
        $this->db->esegui('DELETE FROM forms_gestori WHERE form_id = ? AND utente_id = ?', [$formId, $utenteId]);
    }

    /** I form di cui l'utente è proprietario o co-gestore (per il pannello). @return list<array<string, mixed>> */
    public function deiGestiti(int $utenteId): array
    {
        return $this->db->righe(
            'SELECT f.* FROM forms f WHERE f.proprietario_id = ? OR f.id IN (SELECT form_id FROM forms_gestori WHERE utente_id = ?) ORDER BY f.creato_il DESC',
            [$utenteId, $utenteId]
        );
    }

    /** Tutti i form (per l'amministratore). @return list<array<string, mixed>> */
    public function tutti(): array
    {
        return $this->db->righe('SELECT * FROM forms ORDER BY creato_il DESC');
    }

    /**
     * I form «aperti» nel periodo: stato aperta, aperta_dal passata (o vuota) e aperta_al non ancora passata (o vuota). L'ora arriva da chi chiama
     * (mai da NOW(): così nei test si può fissare). Non controlla il numero massimo di risposte né il pubblico: lo fa ServizioForms.
     *
     * @param string $adesso data e ora nel formato «Y-m-d H:i:s»
     * @return list<array<string, mixed>> dal più recente
     */
    public function apertiAl(string $adesso): array
    {
        return $this->db->righe(
            "SELECT * FROM forms WHERE stato = 'aperta' AND (aperta_dal IS NULL OR aperta_dal <= ?) AND (aperta_al IS NULL OR aperta_al >= ?) ORDER BY creato_il DESC, id DESC",
            [$adesso, $adesso]
        );
    }

    /**
     * I form «aperti» che il cron non ha ancora chiuso da solo (chiusura_avvisata_il vuoto): sono gli unici che CronForms controlla. Un form riaperto a mano
     * dopo una chiusura automatica non rientra: il cron lo chiude una volta sola per form.
     *
     * @return list<array<string, mixed>>
     */
    public function daChiudereSeScaduti(): array
    {
        return $this->db->righe("SELECT * FROM forms WHERE stato = 'aperta' AND chiusura_avvisata_il IS NULL ORDER BY id");
    }

    /**
     * Chiude il form per il cron e ricorda quando. Ritorna true solo se lo ha chiuso proprio questa chiamata (se un altro processo è arrivato prima, false:
     * così l'email al proprietario parte una volta sola).
     */
    public function chiudiAutomaticamente(int $id, string $adesso): bool
    {
        return $this->db->esegui(
            "UPDATE forms SET stato = 'chiusa', chiusura_avvisata_il = ?, modificato_il = ? WHERE id = ? AND stato = 'aperta' AND chiusura_avvisata_il IS NULL",
            [$adesso, $adesso, $id]
        ) > 0;
    }

    /** I form con una durata di conservazione delle risposte (conserva_mesi maggiore di zero). @return list<array<string, mixed>> */
    public function conConservazione(): array
    {
        return $this->db->righe('SELECT * FROM forms WHERE conserva_mesi IS NOT NULL AND conserva_mesi > 0 ORDER BY id');
    }

    /** Ricorda l'avviso di cancellazione mandato al proprietario: quando, e fino a quale data di invio le risposte erano nell'avviso. */
    public function segnaAvvisoConservazione(int $id, string $adesso, string $fino): void
    {
        $this->db->esegui('UPDATE forms SET conserva_avvisata_il = ?, conserva_fino_al = ? WHERE id = ?', [$adesso, $fino, $id]);
    }

    /** Id dei form non in bozza (aperti o chiusi): sono quelli che possono avere risposte di persone. @return list<int> */
    public function idPubblicati(): array
    {
        return array_map('intval', array_column($this->db->righe("SELECT id FROM forms WHERE stato <> 'bozza'"), 'id'));
    }

    /** I form con questi id, per id. @param list<int> $ids @return array<int, array<string, mixed>> */
    public function perIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->db->righe('SELECT * FROM forms WHERE id IN (' . implode(',', $ids) . ')') as $f) {
            $out[(int) $f['id']] = $f;
        }

        return $out;
    }

    /** Se l'utente è co-gestore di almeno un form (non il proprietario): entra comunque in MyForms anche senza l'abilitazione al modulo. */
    public function haGestiti(int $utenteId): bool
    {
        return $utenteId > 0 && $this->db->valore('SELECT 1 FROM forms_gestori WHERE utente_id = ? LIMIT 1', [$utenteId]) !== null;
    }
}
