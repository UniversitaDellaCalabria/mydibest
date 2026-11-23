<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Auth\UtenteRepository;
use App\Core\Orologio;
use App\Sistema\ImpostazioniSistemaRepository;

/**
 * Registrazione della presenza: auto-registrazione dello studente con il QR del turno (self_checkin.php, con finestra
 * dall'apertura del check-in, per impostazione 60 minuti prima dell'inizio e regolabile da Sistema, alla fine del turno)
 * e check-in del biglietto da parte dei gestori (checkin.php). Gli amministratori si registrano anche fuori finestra (prove).
 */
final class ServizioCheckin
{
    public function __construct(private PrenotazioneRepository $prenotazioni, private Orologio $orologio, private UtenteRepository $utenti, private ImpostazioniSistemaRepository $impostazioni)
    {
    }

    /** Amministratore globale: ruolo principale 1 o 1 fra i ruoli secondari (stessa regola del middleware). @param array<string, mixed>|null $utente riga di utenti */
    private static function eAmministratore(?array $utente): bool
    {
        if ($utente === null) {
            return false;
        }
        $secondari = array_filter(explode(',', (string) ($utente['ruoli_secondari'] ?? '')));

        return (int) ($utente['ruolo_id'] ?? 0) === 1 || in_array('1', $secondari, true);
    }

    /** «un'ora», «2 ore», «45 minuti», «l'inizio dell'evento» (per il messaggio «il check-in apre … prima»). */
    public static function testoAnticipo(int $minuti): string
    {
        if ($minuti <= 0) {
            return "all'inizio dell'evento";
        }
        if ($minuti % 60 === 0) {
            $ore = intdiv($minuti, 60);

            return ($ore === 1 ? "un'ora" : "$ore ore") . ' prima';
        }

        return "$minuti minuti prima";
    }

    /**
     * Auto-registrazione dello studente connesso: il QR porta il turno e il codice di sicurezza.
     *
     * @return array{esito: string, messaggio: string, colore: string, icona: string, turno: array<string, mixed>|null}
     *         esito «success», «warning» o «error»; colore e icona servono alla pagina; messaggio è HTML già pronto
     */
    public function autoRegistrazione(int $turnoId, string $token, int $utenteId): array
    {
        if ($turnoId === 0 || empty($token)) {
            return self::risposta('error', 'Dati del QR Code mancanti o incompleti. Prova a ripetere la scansione.', 'danger', 'fa-qrcode');
        }
        $turno = $this->prenotazioni->turnoPerCheckin($turnoId, $token);
        if ($turno === null) {
            return self::risposta('error', 'QR Code non valido o scaduto. La segreteria potrebbe aver ruotato il codice di sicurezza.', 'danger', 'fa-times-circle');
        }

        $adesso = $this->orologio->adesso()->getTimestamp();
        $anticipo = $this->impostazioni->anticipoCheckinMinuti();
        if (empty($turno['data_turno'])) {
            // Turno identificato solo dal nome: il check-in resta sempre aperto
            $inizio = $adesso;
            $fine = $adesso;
        } else {
            $inizio = strtotime($turno['data_turno'] . ' ' . ($turno['orario_inizio'] ?: '00:00:00')) - ($anticipo * 60);
            $fine = strtotime($turno['data_turno'] . ' ' . ($turno['orario_fine'] ?: '23:59:59'));
        }

        // Gli amministratori non hanno limiti di orario: servono per provare il QR prima dell'evento
        $amministratore = self::eAmministratore($this->utenti->perId($utenteId));
        $fuoriFinestra = $adesso < $inizio || $adesso > $fine;
        if ($fuoriFinestra && !$amministratore) {
            if ($adesso < $inizio) {
                $quando = $anticipo > 0 ? self::testoAnticipo($anticipo) . " dell'inizio" : self::testoAnticipo($anticipo);

                return self::risposta('warning', "È troppo presto per registrarsi! Il check-in aprirà $quando.", 'warning', 'fa-clock', $turno);
            }

            return self::risposta('error', 'Il periodo per registrare la presenza a questo evento è terminato.', 'secondary', 'fa-calendar-times', $turno);
        }

        $esito = $this->registraNellaFinestra($turno, $turnoId, $utenteId);
        if ($fuoriFinestra && $esito['esito'] === 'success') {
            $esito['messaggio'] .= '<br><small class="d-block mt-2 text-muted">Sei amministratore: la presenza è stata registrata anche se il check-in non è aperto. Se era una prova, elimina la tua riga dall\'elenco dei presenti.</small>';
        }

        return $esito;
    }

    /**
     * Dalla finestra di check-in in poi: iscrizione confermata, presenza già registrata o presenza di un evento ad accesso libero.
     *
     * @param array<string, mixed> $turno
     * @return array{esito: string, messaggio: string, colore: string, icona: string, turno: array<string, mixed>|null}
     */
    private function registraNellaFinestra(array $turno, int $turnoId, int $utenteId): array
    {
        $pren = $this->prenotazioni->dellUtenteSulTurno($turnoId, $utenteId);
        if ($pren === null && (int) ($turno['richiede_prenotazione'] ?? 1) === 0) {
            return $this->presenzaDiAccessoLibero($turno, $turnoId, $utenteId);
        }
        if ($pren === null) {
            return self::risposta('error', 'Non risulti iscritto a questo turno. Devi prima effettuare la prenotazione.', 'danger', 'fa-user-times', $turno);
        }
        if ($pren['stato'] !== 'confermata') {
            return self::risposta('error', 'La tua iscrizione non è confermata (Stato: ' . strtoupper((string) $pren['stato']) . ').', 'danger', 'fa-exclamation-triangle', $turno);
        }
        if ($pren['presente'] == 1) {
            return self::risposta('success', 'La tua presenza era già stata registrata. Nessuna ulteriore azione richiesta.', 'success', 'fa-check-double', $turno);
        }
        $this->prenotazioni->registraPresenza((int) $pren['id']);

        return self::risposta('success', 'Check-in completato con successo! Presenza convalidata ufficialmente.', 'success', 'fa-check-circle', $turno);
    }

    /**
     * Evento senza prenotazione: chi inquadra il QR e accede con le credenziali istituzionali risulta presente.
     * La riga dell'elenco dei presenti si crea ora, con i dati dell'account.
     *
     * @param array<string, mixed> $turno
     * @return array{esito: string, messaggio: string, colore: string, icona: string, turno: array<string, mixed>|null}
     */
    private function presenzaDiAccessoLibero(array $turno, int $turnoId, int $utenteId): array
    {
        if ((int) ($turno['abilita_presenze'] ?? 1) === 0) {
            return self::risposta('error', 'Per questo evento non si registra la presenza.', 'secondary', 'fa-ban', $turno);
        }
        $u = $this->utenti->perId($utenteId);
        if ($u === null) {
            return self::risposta('error', 'Account non riconosciuto: esci e accedi di nuovo con le credenziali istituzionali.', 'danger', 'fa-user-times', $turno);
        }
        $matricola = '';
        foreach (['matricola_studente', 'matricola_dipendente', 'matricola'] as $campo) {
            if (!empty($u[$campo])) {
                $matricola = (string) $u[$campo];
                break;
            }
        }
        $codice = 'AL-' . strtoupper(bin2hex(random_bytes(4)));
        $id = $this->prenotazioni->inserisciPresenzaLibera($turnoId, $utenteId, $codice, (string) ($u['nome'] ?? ''), (string) ($u['cognome'] ?? ''), strtolower((string) ($u['email'] ?? '')), $matricola);
        if ($id === 0) {
            return self::risposta('error', 'Non è stato possibile registrare la presenza. Riprova tra qualche istante.', 'danger', 'fa-exclamation-triangle', $turno);
        }

        return self::risposta('success', 'Presenza registrata! Il tuo nome compare nell\'elenco dei presenti a questo evento.', 'success', 'fa-check-circle', $turno);
    }

    /** Presenza registrata dal gestore scansionando il biglietto (checkin.php). */
    public function registraPresenza(int $prenotazioneId): void
    {
        $this->prenotazioni->registraPresenza($prenotazioneId);
    }

    /**
     * @param array<string, mixed>|null $turno
     * @return array{esito: string, messaggio: string, colore: string, icona: string, turno: array<string, mixed>|null}
     */
    private static function risposta(string $esito, string $messaggio, string $colore, string $icona, ?array $turno = null): array
    {
        return ['esito' => $esito, 'messaggio' => $messaggio, 'colore' => $colore, 'icona' => $icona, 'turno' => $turno];
    }
}
