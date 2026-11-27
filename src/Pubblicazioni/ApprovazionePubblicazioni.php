<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Core\Database;
use App\Core\Esito;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Infrastructure\Audit\AuditLog;

/**
 * Approvare o respingere una richiesta di pubblicazione (stato «da_approvare»). Niente HTML, niente email (le manda NotifichePubblicazioni, chiamata dalla pagina).
 *
 * - Può decidere chi ha un account collegato a una persona della lista «approvatore» (PersonePubblicazioni::eApprovatore): mai chi ha solo l'email giusta.
 * - Basta UN approvatore. La decisione è un solo UPDATE ... WHERE id = ? AND stato = 'da_approvare': se due approvano insieme vince il primo, il secondo
 *   non cambia niente e riceve l'errore «già approvata da X il …» (si rilegge la riga per dirlo). Lo stesso vale se uno approva e l'altro respinge.
 * - Approvata: stato «ricevuta» (che si legge «Approvata»), decisa_da e decisa_il. Respinta: stato «respinta», decisa_da, decisa_il e il motivo (obbligatorio, al massimo 1000 caratteri).
 * - Ogni decisione lascia una riga nel registro (log_attivita, AuditLog) con id e titolo della richiesta, dove la legge anche RegistroModifiche.
 */
final class ApprovazionePubblicazioni
{
    public function __construct(
        private Database $db,
        private PersonePubblicazioni $persone,
        private AuditLog $audit,
        private IndirizzoClient $client,
        private Orologio $orologio,
    ) {
    }

    /** @param array<string, mixed>|null $utente la riga di utenti */
    public function puoApprovare(?array $utente): bool
    {
        return $this->persone->eApprovatore($utente);
    }

    /**
     * Le richieste che aspettano una decisione, dalla più vecchia.
     *
     * @return list<array<string, mixed>>
     */
    public function daApprovare(): array
    {
        return $this->db->righe("SELECT * FROM pubblicazioni WHERE stato = 'da_approvare' ORDER BY inviata_il, id");
    }

    /** Quante richieste aspettano una decisione (per il numero sulla tessera dell'area personale e su «Il mio lavoro»). */
    public function contaDaApprovare(): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) FROM pubblicazioni WHERE stato = 'da_approvare'");
    }

    /**
     * Le ultime richieste decise (approvate o respinte), dalla più recente, con CHI ha deciso e QUANDO: è così che l'altro approvatore vede cosa è già stato fatto.
     * Le richieste di prima dell'approvazione (nessuna decisione registrata) non ci sono.
     *
     * @return list<array{id: int, titolo: string, richiedente: string, approvata: bool, chi: string, quando: string, motivo: string}>
     */
    public function decise(int $quante = 10): array
    {
        $righe = $this->db->righe(
            "SELECT p.id, p.titolo, p.richiedente, p.stato, p.decisa_il, p.respinta_motivo, TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS chi
             FROM pubblicazioni p LEFT JOIN utenti u ON u.id = p.decisa_da WHERE p.decisa_il IS NOT NULL ORDER BY p.decisa_il DESC, p.id DESC LIMIT " . max(1, min(50, $quante))
        );
        $out = [];
        foreach ($righe as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'titolo' => (string) $r['titolo'], 'richiedente' => (string) $r['richiedente'], 'approvata' => (string) $r['stato'] !== 'respinta',
                'chi' => (string) $r['chi'] !== '' ? (string) $r['chi'] : 'un approvatore', 'quando' => (string) $r['decisa_il'], 'motivo' => (string) $r['respinta_motivo'],
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $utente la riga di utenti di chi approva */
    public function approva(int $id, array $utente): Esito
    {
        return $this->decidi($id, $utente, true, '');
    }

    /** @param array<string, mixed> $utente la riga di utenti di chi respinge */
    public function respingi(int $id, array $utente, string $motivo): Esito
    {
        $motivo = trim(str_replace("\r\n", "\n", $motivo));
        if (($errore = self::erroreMotivo($motivo)) !== null) {
            return Esito::errore($errore);
        }

        return $this->decidi($id, $utente, false, $motivo);
    }

    /** Il motivo del rifiuto è obbligatorio e al massimo di MAX_MOTIVO caratteri. Ritorna l'errore da mostrare, null se va bene. */
    public static function erroreMotivo(string $motivo): ?string
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            return 'Scrivi il motivo per cui respingi la richiesta: lo leggerà chi l\'ha inviata.';
        }

        return mb_strlen($motivo) > Costanti::MAX_MOTIVO ? 'Il motivo è troppo lungo (massimo ' . Costanti::MAX_MOTIVO . ' caratteri).' : null;
    }

    /**
     * Chi ha deciso e quando, per mostrarlo (anche all'altro approvatore).
     *
     * @return array{chi: string, quando: string, approvata: bool, motivo: string}|null null se la richiesta non è ancora decisa (o è di prima dell'approvazione)
     */
    public function decisione(int $id): ?array
    {
        $r = $this->db->riga(
            "SELECT p.stato, p.decisa_il, p.respinta_motivo, TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS chi
             FROM pubblicazioni p LEFT JOIN utenti u ON u.id = p.decisa_da WHERE p.id = ? AND p.decisa_il IS NOT NULL",
            [$id]
        );

        return $r === null ? null : ['chi' => (string) $r['chi'] !== '' ? (string) $r['chi'] : 'un approvatore', 'quando' => (string) $r['decisa_il'], 'approvata' => $r['stato'] !== 'respinta', 'motivo' => (string) $r['respinta_motivo']];
    }

    /** @param array<string, mixed> $utente */
    private function decidi(int $id, array $utente, bool $approva, string $motivo): Esito
    {
        if (!$this->puoApprovare($utente)) {
            return Esito::errore('Non sei tra le persone che approvano le richieste di pubblicazione.');
        }
        $p = $this->db->riga('SELECT id, titolo, stato FROM pubblicazioni WHERE id = ?', [$id]);
        if ($p === null) {
            return Esito::errore('La richiesta non esiste più.');
        }
        $uid = (int) $utente['id'];
        $adesso = $this->orologio->adesso()->format('Y-m-d H:i:s');
        // un solo aggiornamento: chi arriva secondo trova lo stato già cambiato e non scrive niente
        $n = $this->db->esegui(
            "UPDATE pubblicazioni SET stato = ?, decisa_da = ?, decisa_il = ?, respinta_motivo = ? WHERE id = ? AND stato = 'da_approvare'",
            [$approva ? 'ricevuta' : 'respinta', $uid, $adesso, $motivo, $id]
        );
        if ($n < 0) {
            return Esito::errore('Non è stato possibile salvare la decisione: riprova tra qualche minuto.');
        }
        if ($n === 0) {
            return Esito::errore($this->giaDecisa($id));
        }
        $this->audit->registra($uid, 'Richieste di pubblicazione: ' . ($approva ? 'approvata' : 'respinta'), ['id' => $id, 'titolo' => (string) $p['titolo']] + ($approva ? [] : ['motivo' => $motivo]), $this->client->ip() ?? 'Sconosciuto');

        return Esito::ok($approva ? 'Richiesta approvata.' : 'Richiesta respinta: il motivo arriverà a chi l\'ha inviata.');
    }

    /**
     * «Questa richiesta è già stata approvata da X il …»: la riga si rilegge dopo l'aggiornamento a vuoto. Pubblica perché la pagina di chi approva la usa anche
     * PRIMA di provare a decidere (una richiesta già decisa da un altro non si prova nemmeno a decidere, e il messaggio è lo stesso).
     */
    public function giaDecisa(int $id): string
    {
        $d = $this->decisione($id);
        if ($d === null) {
            $r = $this->db->riga('SELECT stato FROM pubblicazioni WHERE id = ?', [$id]);

            return $r !== null && $r['stato'] !== 'da_approvare'
                ? 'Questa richiesta non è più in attesa di approvazione (è ' . mb_strtolower(Costanti::STATI[(string) $r['stato']] ?? (string) $r['stato']) . ').'
                : 'La richiesta non esiste più.';
        }

        return 'Questa richiesta è già stata ' . ($d['approvata'] ? 'approvata' : 'respinta') . ' da ' . $d['chi'] . ' il ' . date('d/m/Y \a\l\l\e H:i', (int) strtotime($d['quando'])) . '.';
    }
}
