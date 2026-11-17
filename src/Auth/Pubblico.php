<?php

declare(strict_types=1);

namespace App\Auth;

use App\Anagrafi\Anagrafe;

/**
 * Chi può vedere o fare una cosa, in un solo modo per tutto il sito: un «pubblico» è un insieme di profili.
 * Sostituisce (senza cambiarne il comportamento) le regole sparse di oggi: risorse.accesso (ServizioRisorse::puoPrenotare),
 * moduli.destinatari (ServizioModuli::destinatario) e, in futuro, eventi.ruolo_accesso_id.
 *
 * I profili si ricavano da ruolo_id, ruoli_secondari, dai gruppi che rappresentano nella tabella «ruoli» (Docenti,
 * Personale tecnico amministrativo, Altro personale di Ateneo, Scuole, Aziende: $gruppi, già risolti da chi chiama con
 * App\Auth\GruppiUtente::nomi()) e dalla matricola da SSO. Docenti e PTA sono assegnati al login dall'anagrafe di Ateneo;
 * Aziende dalla convenzione di tesi (o a mano da Utenti).
 *
 * ATTENZIONE, «scuole» è diverso: lo sceglie la persona stessa (sono_una_scuola.php, basta indicare una scuola
 * dell'elenco, e il gruppo «Scuole» segue) e nessuno controlla che lo sia davvero. Va bene per cose come «FSL per le
 * scuole», NON per cose riservate (vedi AVVISO_SCUOLE, mostrato accanto alla casella nei pannelli).
 *
 * La classe è senza stato e senza accesso al database: resta facile da provare.
 */
final class Pubblico
{
    public const ANONIMI = 'anonimi';
    public const REGISTRATI = 'registrati';
    public const STUDENTI = 'studenti';
    public const DOCENTI = 'docenti';
    public const PTA = 'pta';
    public const PERSONALE = 'personale';
    public const ESTERNI = 'esterni';
    public const SCUOLE = 'scuole';
    public const AZIENDE = 'aziende';

    /** Il profilo «scuole» si dichiara da soli: il pannello lo dice accanto alla casella, le guide con la stessa frase. */
    public const AVVISO_SCUOLE = "Chiunque abbia fatto l'accesso può dichiararsi scuola: non usarlo per cose riservate.";

    /** @var list<string> tutti i profili, nell'ordine in cui si mostrano nei pannelli */
    public const PROFILI = [self::ANONIMI, self::REGISTRATI, self::STUDENTI, self::DOCENTI, self::PTA, self::PERSONALE, self::ESTERNI, self::SCUOLE, self::AZIENDE];

    /** Etichette per le caselle del parziale html_scelta_pubblico(). */
    public const ETICHETTE = [
        self::ANONIMI => 'Chiunque, anche senza accesso',
        self::REGISTRATI => "Chiunque abbia fatto l'accesso",
        self::STUDENTI => 'Studenti',
        self::DOCENTI => 'Docenti',
        self::PTA => 'Personale tecnico amministrativo',
        self::PERSONALE => 'Personale di Ateneo (docenti, PTA e altro personale)',
        self::ESTERNI => "Esterni (hanno fatto l'accesso, non sono di Ateneo)",
        self::SCUOLE => 'Scuole',
        self::AZIENDE => 'Aziende',
    ];

    /**
     * I profili dell'utente. Senza accesso è sempre e solo «anonimi». Con l'accesso c'è sempre «registrati», più gli
     * eventuali profili specifici; chi non è né studente né personale di Ateneo è anche «esterni».
     *
     * @param array<string, mixed>|null $u la riga di utenti
     * @param list<string> $gruppi nomi (minuscoli) dei gruppi dell'utente nella tabella «ruoli»: da GruppiUtente::nomi($u)
     * @return list<string>
     */
    public static function profiliUtente(?array $u, array $gruppi = []): array
    {
        if (!$u || empty($u['id'])) {
            return [self::ANONIMI];
        }
        $sec = explode(',', (string) ($u['ruoli_secondari'] ?? ''));
        $docente = in_array(mb_strtolower(Anagrafe::GRUPPI_PERSONALE['docenti']), $gruppi, true);
        $pta = in_array(mb_strtolower(Anagrafe::GRUPPI_PERSONALE['pta']), $gruppi, true);
        $altro = in_array(mb_strtolower(Anagrafe::GRUPPI_PERSONALE['altro']), $gruppi, true);
        $personale = $docente || $pta || $altro || (int) ($u['ruolo_id'] ?? 5) === 4 || in_array('4', $sec, true);
        $studente = (int) ($u['ruolo_id'] ?? 5) === 3 || in_array('3', $sec, true) || !empty($u['matricola_studente']);
        $scuola = in_array(mb_strtolower(GruppoScuole::NOME), $gruppi, true);
        $azienda = in_array(mb_strtolower(GruppoAziende::NOME), $gruppi, true);

        $p = [
            self::REGISTRATI => true,
            self::STUDENTI => $studente,
            self::DOCENTI => $docente,
            self::PTA => $pta,
            self::PERSONALE => $personale,
            self::SCUOLE => $scuola,
            self::AZIENDE => $azienda,
        ];
        $profili = array_keys(array_filter($p));
        if (!$studente && !$personale) {
            $profili[] = self::ESTERNI;
        }

        return $profili;
    }

    /**
     * $regola = ['profili' => list<string>] (vuoto = nessuno, a parte gli amministratori); accetta anche la chiave
     * legacy 'ruolo_id' (int) per i vecchi eventi.ruolo_accesso_id non ancora tradotti in un profilo con nome (gruppi
     * creati a mano in Utenti): confronto diretto sull'id, come oggi. Gli amministratori sono sempre ammessi.
     * Il profilo 'anonimi' nella regola vuol dire chiunque, anche chi ha fatto l'accesso (non solo chi non l'ha fatto:
     * profiliUtente() non lo attribuisce mai a un utente loggato, quindi va controllato qui a parte).
     *
     * @param array{profili?: list<string>, ruolo_id?: int} $regola
     * @param array<string, mixed>|null $u
     * @param list<string> $gruppi vedi profiliUtente()
     */
    public static function ammesso(array $regola, ?array $u, array $gruppi = []): bool
    {
        if (in_array(self::ANONIMI, $regola['profili'] ?? [], true)) {
            return true;
        }
        if ($u && !empty($u['id'])) {
            $sec = explode(',', (string) ($u['ruoli_secondari'] ?? ''));
            if ((int) ($u['ruolo_id'] ?? 5) === 1 || in_array('1', $sec, true)) {
                return true;
            }
            if (isset($regola['ruolo_id']) && ((int) ($u['ruolo_id'] ?? 5) === (int) $regola['ruolo_id'] || in_array((string) (int) $regola['ruolo_id'], $sec, true))) {
                return true;
            }
        }

        return (bool) array_intersect(self::profiliUtente($u, $gruppi), $regola['profili'] ?? []);
    }

    /**
     * Traduce i vecchi formati in una regola nuova:
     * - stringhe di moduli.destinatari / risorse.accesso: 'studenti', 'docenti', 'personale'; 'tutti' (o qualsiasi
     *   altro valore non riconosciuto) vuol dire «chiunque abbia fatto l'accesso»;
     * - eventi.ruolo_accesso_id: 0 = nessuna restrizione (ammesso anche senza accesso), -1 = chiunque abbia fatto
     *   l'accesso, un numero positivo = il vecchio confronto diretto sull'id del ruolo (vedi 'ruolo_id' sopra).
     *
     * @return array{profili: list<string>, ruolo_id?: int}
     */
    public static function daVecchio(string|int $valore): array
    {
        if (is_string($valore) && !is_numeric($valore)) {
            return match ($valore) {
                self::STUDENTI => ['profili' => [self::STUDENTI]],
                self::DOCENTI => ['profili' => [self::DOCENTI]],
                self::PERSONALE => ['profili' => [self::PERSONALE]],
                default => ['profili' => [self::REGISTRATI]],
            };
        }
        $n = (int) $valore;
        if ($n === 0) {
            return ['profili' => self::PROFILI];
        }
        if ($n === -1) {
            return ['profili' => [self::REGISTRATI]];
        }

        return ['profili' => [], 'ruolo_id' => $n];
    }
}
