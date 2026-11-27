<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Amministrazione\PermessiAmministrazione;
use App\Core\Database;
use App\Core\Esito;
use Throwable;

/**
 * Le tre liste di persone dell'approvazione delle richieste di pubblicazione (tabella pubblicazioni_persone, ruoli di Costanti::RUOLI_PERSONE):
 * - approvatore: chi riceve la richiesta appena inviata e può approvarla o respingerla;
 * - ufficio: chi riceve la richiesta approvata, con il link alla scheda (i permessi nel pannello restano quelli della sua abilitazione);
 * - conoscenza: chi riceve la richiesta approvata in sola lettura, senza link.
 * Le persone si scelgono dall'anagrafe di Ateneo (personale_ateneo): niente indirizzi scritti a mano. Qui non c'è HTML.
 *
 * - L'email non si copia: si legge ogni volta da personale_ateneo (la tiene aggiornata la sincronizzazione). Una persona uscita dall'anagrafe o senza
 *   email non riceve niente: elenco() la segna con un avviso, che la pagina delle impostazioni mostra in rosso.
 * - Il permesso di approvare vale per l'ACCOUNT il cui utenti.persona_id è quello della lista (lo imposta il login con l'email dell'SSO o con quella
 *   verificata: CollegamentoUtente). Mai per un indirizzo scritto a mano o non verificato. Chi non è ancora entrato nel portale riceve l'email,
 *   ma non può approvare: elenco() lo segnala.
 * - Una lista non dà permessi nel pannello: chi lavora le richieste ha bisogno della sua abilitazione «Richieste di pubblicazione» (elenco() avvisa se manca).
 */
final class PersonePubblicazioni
{
    public function __construct(private Database $db, private PermessiAmministrazione $permessi)
    {
    }

    /**
     * La regola del permesso, senza database: l'utente (la riga di utenti) ha un account collegato a una delle persone indicate.
     * Si guarda solo persona_id, mai l'email.
     *
     * @param array<string, mixed>|null $utente
     * @param list<string> $idsPersona le persone dell'anagrafe della lista degli approvatori
     */
    public static function collegata(?array $utente, array $idsPersona): bool
    {
        if ($utente === null || empty($utente['id'])) {
            return false;
        }
        $pid = trim((string) ($utente['persona_id'] ?? ''));

        return $pid !== '' && in_array($pid, $idsPersona, true);
    }

    /** @param array<string, mixed>|null $utente la riga di utenti */
    public function eApprovatore(?array $utente): bool
    {
        return self::collegata($utente, $this->ids('approvatore'));
    }

    /** @return list<string> gli id dell'anagrafe delle persone della lista (vuoto se la tabella non c'è ancora: caricamento a metà) */
    public function ids(string $ruolo): array
    {
        try {
            return array_map(static fn (array $r): string => (string) $r['persona_id'], $this->db->righe('SELECT persona_id FROM pubblicazioni_persone WHERE ruolo = ?', [$ruolo]));
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] liste di persone non lette: ' . $e->getMessage());

            return [];
        }
    }

    /** Quanti approvatori hanno già un account collegato: senza almeno uno l'approvazione non si accende e le richieste non restano mai in attesa di nessuno. */
    public function approvatoriConAccount(): int
    {
        return self::contaApprovatoriConAccount($this->db);
    }

    /** Come approvatoriConAccount, per chi ha solo il database (ImpostazioniPubblicazioni, che non dipende da questa classe). */
    public static function contaApprovatoriConAccount(Database $db): int
    {
        try {
            return (int) $db->valore(
                "SELECT COUNT(*) FROM pubblicazioni_persone p WHERE p.ruolo = 'approvatore' AND EXISTS (SELECT 1 FROM utenti u WHERE u.persona_id = p.persona_id)"
            );
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] approvatori non contati: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Le persone di una lista, in ordine di nome, con l'email di adesso e gli avvisi (vedi la descrizione della classe).
     *
     * @return list<array{persona_id: string, nominativo: string, email: string, in_anagrafe: bool, account: bool, avvisi: list<string>}>
     */
    public function elenco(string $ruolo): array
    {
        $righe = $this->db->righe(
            'SELECT p.persona_id, p.nominativo AS copiato, a.nome, a.cognome, a.email, a.attivo
             FROM pubblicazioni_persone p LEFT JOIN personale_ateneo a ON a.id = p.persona_id
             WHERE p.ruolo = ? ORDER BY p.nominativo, p.id',
            [$ruolo]
        );
        $account = $this->account(array_map(static fn (array $r): string => (string) $r['persona_id'], $righe));
        $out = [];
        foreach ($righe as $r) {
            $pid = (string) $r['persona_id'];
            $inAnagrafe = $r['attivo'] !== null && (int) $r['attivo'] === 1;
            $email = trim((string) ($r['email'] ?? ''));
            $nome = trim(trim((string) ($r['nome'] ?? '')) . ' ' . trim((string) ($r['cognome'] ?? '')));
            $avvisi = [];
            if (!$inAnagrafe) {
                $avvisi[] = 'Non è più in anagrafe: non riceve niente.';
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $avvisi[] = 'Senza email in anagrafe: non riceve niente.';
            }
            $mieiAccount = $account[$pid] ?? [];
            if ($ruolo === 'approvatore' && $mieiAccount === []) {
                $avvisi[] = 'Non ha ancora un account collegato: riceve l\'email, ma può approvare solo dopo il primo accesso al portale.';
            }
            if ($ruolo === 'ufficio' && !array_filter($mieiAccount, fn (array $u): bool => $this->permessi->puoPubblicazioni($u))) {
                $avvisi[] = 'Non ha l\'abilitazione «Amministrazione · Richieste di pubblicazione»: riceve l\'email, ma il link darebbe «accesso negato».';
            }
            $out[] = [
                'persona_id' => $pid,
                'nominativo' => $nome !== '' ? $nome : (string) $r['copiato'],
                'email' => $email,
                'in_anagrafe' => $inAnagrafe,
                'account' => $mieiAccount !== [],
                'avvisi' => $avvisi,
            ];
        }

        return $out;
    }

    /**
     * Chi di una lista può ricevere un'email: in anagrafe (attivo) e con un indirizzo valido, letto ogni volta da personale_ateneo (mai copiato). Una persona uscita
     * o senza email non c'è; la stessa persona non compare due volte.
     *
     * @return list<array{persona_id: string, nominativo: string, nome: string, email: string}>
     */
    public function destinatari(string $ruolo): array
    {
        try {
            $righe = $this->db->righe(
                'SELECT p.persona_id, p.nominativo AS copiato, a.nome, a.cognome, a.email
                 FROM pubblicazioni_persone p JOIN personale_ateneo a ON a.id = p.persona_id
                 WHERE p.ruolo = ? AND a.attivo = 1 ORDER BY p.nominativo, p.id',
                [$ruolo]
            );
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] destinatari non letti: ' . $e->getMessage());

            return [];
        }
        $out = [];
        foreach ($righe as $r) {
            $email = trim((string) ($r['email'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $nome = trim((string) ($r['nome'] ?? ''));
            $completo = trim($nome . ' ' . trim((string) ($r['cognome'] ?? '')));
            $out[] = ['persona_id' => (string) $r['persona_id'], 'nominativo' => $completo !== '' ? $completo : (string) $r['copiato'], 'nome' => $nome !== '' ? $nome : (string) $r['copiato'], 'email' => $email];
        }

        return $out;
    }

    /** @return array<string, list<array<string, mixed>>> ruolo => persone (elenco()) */
    public function tutte(): array
    {
        $out = [];
        foreach (array_keys(Costanti::RUOLI_PERSONE) as $ruolo) {
            $out[$ruolo] = $this->elenco($ruolo);
        }

        return $out;
    }

    /** Aggiunge una persona dell'anagrafe a una lista. @param int $da l'utente che la aggiunge */
    public function aggiungi(string $ruolo, string $personaId, int $da): Esito
    {
        if (!isset(Costanti::RUOLI_PERSONE[$ruolo])) {
            return Esito::errore('Lista non valida.');
        }
        $persona = $this->db->riga('SELECT id, nome, cognome FROM personale_ateneo WHERE id = ? LIMIT 1', [trim($personaId)]);
        if ($persona === null) {
            return Esito::errore('La persona non è nell\'anagrafe di Ateneo: cercala e sceglila dall\'elenco.');
        }
        $nome = trim(trim((string) $persona['nome']) . ' ' . trim((string) $persona['cognome']));
        $n = $this->db->esegui(
            'INSERT IGNORE INTO pubblicazioni_persone (ruolo, persona_id, nominativo, aggiunta_il, aggiunta_da) VALUES (?, ?, ?, NOW(), ?)',
            [$ruolo, (string) $persona['id'], mb_substr($nome, 0, 200), $da > 0 ? $da : null]
        );
        if ($n < 0) {
            return Esito::errore('Non è stato possibile salvare la persona: riprova tra qualche minuto.');
        }

        return $n === 0 ? Esito::errore($nome . ' è già in questa lista.') : Esito::ok($nome . ' è stata aggiunta (' . mb_strtolower(Costanti::RUOLI_PERSONE[$ruolo]) . ').');
    }

    /** Toglie una persona da una lista. */
    public function togli(string $ruolo, string $personaId): Esito
    {
        $riga = $this->db->riga('SELECT nominativo FROM pubblicazioni_persone WHERE ruolo = ? AND persona_id = ?', [$ruolo, trim($personaId)]);
        if ($riga === null) {
            return Esito::errore('La persona non è più in questa lista.');
        }
        // revisione P2: le richieste già in attesa non devono restare senza nessuno che possa deciderle (le nuove nascono approvate da sole, statoPer)
        if ($ruolo === 'approvatore' && $this->restaSenzaApprovatori(trim($personaId))) {
            $n = (int) $this->db->valore("SELECT COUNT(*) FROM pubblicazioni WHERE stato = 'da_approvare'");
            if ($n > 0) {
                return Esito::errore('Non puoi togliere l\'ultimo approvatore con un account: ' . ($n === 1 ? 'c\'è 1 richiesta' : "ci sono {$n} richieste") . ' in attesa di approvazione. Aggiungi prima un altro approvatore, oppure fai decidere quelle in attesa.');
            }
        }
        $this->db->esegui('DELETE FROM pubblicazioni_persone WHERE ruolo = ? AND persona_id = ?', [$ruolo, trim($personaId)]);

        return Esito::ok((string) $riga['nominativo'] . ' è stata tolta (' . mb_strtolower(Costanti::RUOLI_PERSONE[$ruolo] ?? $ruolo) . ').');
    }

    /** Se, tolta questa persona, non resta nessun approvatore con un account collegato. */
    private function restaSenzaApprovatori(string $personaId): bool
    {
        return (int) $this->db->valore(
            "SELECT COUNT(*) FROM pubblicazioni_persone p WHERE p.ruolo = 'approvatore' AND p.persona_id <> ? AND EXISTS (SELECT 1 FROM utenti u WHERE u.persona_id = p.persona_id)",
            [$personaId]
        ) === 0;
    }

    /** Il nome con cui una persona compare nel registro, anche se poi è stata tolta. */
    public function nominativo(string $ruolo, string $personaId): string
    {
        return (string) ($this->db->valore('SELECT nominativo FROM pubblicazioni_persone WHERE ruolo = ? AND persona_id = ?', [$ruolo, trim($personaId)]) ?? '');
    }

    /**
     * Gli account (righe di utenti) collegati alle persone indicate: persona_id => account. Servono a sapere chi ha già fatto il primo accesso e che abilitazioni ha.
     *
     * @param list<string> $ids
     * @return array<string, list<array<string, mixed>>>
     */
    private function account(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->righe(
            'SELECT id, persona_id, ruolo_id, ruoli_secondari FROM utenti WHERE persona_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            array_values($ids)
        ) as $u) {
            $out[(string) $u['persona_id']][] = $u;
        }

        return $out;
    }
}
