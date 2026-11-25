<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

/**
 * Che cosa perde chi ha un'email scelta a mano e mai confermata. Dalla correzione T3 un'email non verificata (UtenteRepository::emailVerificata) non dà
 * più compiti, firme, permessi di ufficio né prenotazioni trovate «per email»: chi era già riconosciuto così resta fuori, e deve saperlo e sapere
 * come tornare dentro (confermare l'email dal profilo, oppure accedere con Ateneo/SPID/CIE con la stessa email).
 *
 * Qui si risponde a due domande, solo in lettura:
 *  - perdite(): per UNA persona, in quali elenchi «per email» compare il suo indirizzo (avviso in Area personale, profilo, firme, pannello);
 *  - chiPerde() / rapporto(): per TUTTI gli account non verificati, chi perderebbe qualcosa (strumenti/controllo_email_personalizzate.php --chi-perde).
 * Le fonti sono le stesse dei punti che T3 ha cambiato: se ne aggiunge uno nuovo, si aggiunge una riga a FONTI.
 */
final class EmailNonVerificata
{
    /**
     * codice => [che cosa si perde, query che conta le righe (un solo «?»: l'email in minuscolo; «#id» = id dell'utente), che cosa fare].
     * Le query contano solo ciò che è ancora aperto: una convenzione protocollata o una conferma già data non si perdono più.
     */
    private const FONTI = [
        'direzione' => ['Direttore o vice: firme e cruscotto della Direzione',
            "SELECT COUNT(*) FROM uffici_persone p JOIN uffici u ON u.id = p.ufficio_id WHERE u.chiave = 'direzione' AND LOWER(p.email) = ? AND (LOWER(TRIM(p.ruolo)) = 'direttore' OR LOWER(TRIM(p.ruolo)) LIKE 'vice%')",
            'confermare l\'email: la firma non arriva a un\'email non confermata'],
        'uffici' => ['Persona di un ufficio: le pratiche e i compiti dell\'ufficio',
            "SELECT COUNT(*) FROM uffici_persone p LEFT JOIN uffici u ON u.id = p.ufficio_id WHERE LOWER(p.email) = ?
                AND NOT (COALESCE(u.chiave, '') = 'direzione' AND (LOWER(TRIM(p.ruolo)) = 'direttore' OR LOWER(TRIM(p.ruolo)) LIKE 'vice%'))",
            'confermare l\'email'],
        'consigli' => ['Segretario, coordinatore o componente di un consiglio: verbali e sedute',
            'SELECT COUNT(*) FROM didattica_consigli_persone WHERE LOWER(email) = ?',
            'confermare l\'email'],
        'conferme' => ['Conferme da dare come docente (pratiche e attestati in attesa)',
            "SELECT COUNT(*) FROM pratiche_conferme WHERE LOWER(email) = ? AND stato = 'attesa'",
            'confermare l\'email: il compito resta in attesa e parte il sollecito'],
        'firme' => ['Firme da dare come relatore o Direttore (pratiche in attesa)',
            "SELECT COUNT(*) FROM pratiche_firme WHERE LOWER(email) = ? AND stato = 'attesa'",
            'confermare l\'email'],
        'risorse' => ['Responsabile di una risorsa: approvazioni e cruscotto',
            'SELECT COUNT(*) FROM risorse_responsabili WHERE LOWER(email) = ?',
            'confermare l\'email'],
        'tutorato' => ['Docente di un incarico di tutorato (conferme e firme in corso)',
            "SELECT COUNT(*) FROM tutorato_incarichi WHERE LOWER(docente_email) = ? AND stato NOT IN ('bozza', 'protocollata', 'annullata')",
            'confermare l\'email'],
        'convenzioni_fsl' => ['Scuola con una convenzione FSL in corso: il link per compilarla e seguirla',
            "SELECT COUNT(*) FROM convenzioni_compilate WHERE COALESCE(stato_iter, '') NOT IN ('protocollata', 'unita') AND COALESCE(dati_json, '') NOT IN ('', 'null', '[]', '{}')
                AND (LOWER(email) = ? OR LOWER(COALESCE(proprietari_json, '')) LIKE CONCAT('%\"', ?, '\"%'))",
            'confermare l\'email, oppure l\'ufficio usa «Cambia referente» con un\'email verificata'],
        'convenzioni_tesi' => ['Azienda con una convenzione di tesi in corso: il link per seguirla',
            "SELECT COUNT(*) FROM convenzioni_tesi WHERE LOWER(email) = ? AND stato NOT IN ('protocollata', 'rifiutata')",
            'confermare l\'email'],
        'prenotazioni' => ['Prenotazioni future trovate solo per email (quelle fatte da loggati restano sempre visibili)',
            "SELECT COUNT(*) FROM prenotazioni p JOIN turni t ON t.id = p.turno_id WHERE LOWER(p.email) = ? AND (p.utente_id IS NULL OR p.utente_id <> #id)
                AND p.stato IN ('confermata', 'da_approvare', 'in_attesa', 'richiesta_conferma') AND (t.data_turno IS NULL OR t.data_turno >= CURDATE())",
            'confermare l\'email, oppure prenotare di nuovo da loggati'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * L'indirizzo scelto a mano e mai confermato (minuscolo), o null se l'email è verificata, arriva dal login o non c'è.
     *
     * @param array<string, mixed>|null $utente riga di utenti
     */
    public static function indirizzo(?array $utente): ?string
    {
        if ($utente === null || (int) ($utente['email_personalizzata'] ?? 0) !== 1 || !empty($utente['email_verificata_il'])) {
            return null;
        }
        $email = mb_strtolower(trim((string) ($utente['email'] ?? '')));

        return $email === '' ? null : $email;
    }

    /**
     * Dove compare l'indirizzo non confermato di questa persona: una voce per elenco, solo se ci sono righe aperte. Vuota se l'email è verificata.
     *
     * @param array<string, mixed> $utente riga di utenti
     * @return list<array{codice: string, cosa: string, quanti: int, fare: string}>
     */
    public function perdite(array $utente): array
    {
        $email = self::indirizzo($utente);
        if ($email === null) {
            return [];
        }
        $id = (int) ($utente['id'] ?? 0);
        $out = [];
        foreach (self::FONTI as $codice => [$cosa, $sql, $fare]) {
            try {
                $quanti = (int) $this->db->valore(str_replace('#id', (string) $id, $sql), array_fill(0, substr_count($sql, '?'), $email));
            } catch (\Throwable $e) {
                continue; // tabella assente in questa installazione: la fonte non conta
            }
            if ($quanti > 0) {
                $out[] = ['codice' => $codice, 'cosa' => $cosa, 'quanti' => $quanti, 'fare' => $fare];
            }
        }

        return $out;
    }

    /**
     * Gli account non verificati che perderebbero qualcosa, in ordine di cognome e nome. Solo lettura.
     *
     * @return list<array{id: int, nome: string, cognome: string, email: string, perdite: list<array{codice: string, cosa: string, quanti: int, fare: string}>}>
     */
    public function chiPerde(): array
    {
        $out = [];
        $righe = $this->db->righe("SELECT * FROM utenti WHERE email_personalizzata = 1 AND email_verificata_il IS NULL AND email IS NOT NULL AND email <> '' ORDER BY cognome, nome, id");
        foreach ($righe as $u) {
            $perdite = $this->perdite($u);
            if ($perdite) {
                $out[] = ['id' => (int) $u['id'], 'nome' => (string) $u['nome'], 'cognome' => (string) $u['cognome'], 'email' => (string) self::indirizzo($u), 'perdite' => $perdite];
            }
        }

        return $out;
    }

    /**
     * Il testo dello strumento: per ogni persona il motivo (che cosa perde) e che cosa fare. Gli indirizzi si mostrano solo con $dettagli.
     */
    public function rapporto(bool $dettagli = false): string
    {
        $elenco = $this->chiPerde();
        if (!$elenco) {
            return "Nessuno perderebbe niente: nessun account con email non confermata compare in un elenco che dà compiti o permessi.\n";
        }
        $t = 'Account con email non confermata che perdono compiti o permessi: ' . count($elenco) . "\n\n";
        foreach ($elenco as $c) {
            $t .= sprintf("#%d  %s %s.%s\n", $c['id'], $c['cognome'], mb_strtoupper(mb_substr($c['nome'], 0, 1)), $dettagli ? '  <' . $c['email'] . '>' : '');
            foreach ($c['perdite'] as $p) {
                $t .= sprintf("      - %s (%d)\n        che fare: %s\n", $p['cosa'], $p['quanti'], $p['fare']);
            }
        }

        return $t . "\nLa persona conferma l'email dal profilo (dal pannello, scheda utente, si può mandarle il codice) oppure accede con Ateneo/SPID/CIE con la stessa email.\n"
            . "Se l'indirizzo non è suo: php strumenti/controllo_email_personalizzate.php --azzera=ID\n";
    }
}
