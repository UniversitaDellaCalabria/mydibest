<?php

declare(strict_types=1);

namespace App\Portale\Pagine;

use App\Attestati\Vista\Librerie;
use App\Attestati\Vista\Qr;
use App\Auth\LimiteRichieste;
use App\Auth\Sessione;
use App\Core\IndirizzoClient;
use App\Core\Sito;
use App\Eventi\Turni;
use App\Http\Pagina;
use App\Http\Richiesta;
use App\Http\Risposta;
use App\Http\Vista;
use App\Iscrizioni\PrenotazioneRepository;
use App\Portale\Colori;
use App\Portale\Instradamento\SincronizzaAccesso;

/**
 * Ricevuta di prenotazione da stampare (stampa_ricevuta.php): documento autonomo, senza la testata del sito.
 * Accesso: amministratori e gestori, il proprietario della prenotazione, oppure chi ha il codice (con limite ai tentativi falliti).
 * Il disegno è in viste/stampe/ricevuta.php (App\Http\Vista: l'escape è automatico); qui restano i controlli di accesso e la preparazione dei dati.
 *
 * I testi degli errori (200 con il solo testo, 429 per i troppi tentativi) sono quelli di prima, parola per parola: le prove di pagina li cercano.
 */
final class PaginaStampaRicevuta implements Pagina
{
    public function __construct(
        private readonly PrenotazioneRepository $prenotazioni,
        private readonly LimiteRichieste $limite,
        private readonly Sessione $sessione,
        private readonly IndirizzoClient $indirizzo,
        private readonly Sito $sito,
        private readonly SincronizzaAccesso $sincronizza,
    ) {
    }

    public function __invoke(Richiesta $r): Risposta
    {
        ($this->sincronizza)($r);

        $code = trim(is_string($r->query('code')) ? $r->query('code') : '');
        $id = (int) $r->query('id', 0);
        if ($code === '' && $id <= 0) {
            return Risposta::html('Parametri non validi.');
        }

        $uId = $this->sessione->leggi('utente_id') ?? 0;
        $uRuolo = $this->sessione->leggi('utente_ruolo_id') ?? 5;
        $secondari = $this->sessione->leggi('utente_ruoli_secondari') !== null ? explode(',', (string) $this->sessione->leggi('utente_ruoli_secondari')) : [];
        $eAmministratoreOGestore = ($uRuolo == 1 || $uRuolo == 2 || in_array('1', $secondari) || in_array('2', $secondari));

        // Chi non è amministratore o gestore e arriva con un codice: numero limitato di tentativi FALLITI (codice che non esiste) per indirizzo
        // (il codice non si deve poter indovinare provando a raffica; aprire tante ricevute valide, es. una classe dietro lo stesso indirizzo, non conta).
        // Il formato dei codici non cambia.
        $ip = $this->indirizzo->ip() ?? 'unknown';
        $contaFalliti = !$eAmministratoreOGestore && $code !== '';
        if ($contaFalliti && $this->limite->bloccato($ip, 'ricevuta_codice', 20, 600)) {
            return Risposta::html('Troppi tentativi ravvicinati. Riprova tra qualche minuto.', 429);
        }

        $p = $this->prenotazioni->ricevuta($code, $id);
        if (!$p) {
            if ($contaFalliti) {
                $this->limite->registra($ip, 'ricevuta_codice');
            }

            return Risposta::html('Ricevuta non trovata nel sistema.');
        }

        $eProprietario = ($uId > 0 && ($p['utente_id'] == $uId || strtolower($p['email']) === strtolower((string) ($this->sessione->leggi('utente_email') ?? ''))));
        $haCodiceValido = (!empty($code) && $p['codice_prenotazione'] === $code);
        if (!$eAmministratoreOGestore && !$eProprietario && !$haCodiceValido) {
            return Risposta::html('Accesso non autorizzato a questa ricevuta.');
        }

        // Colore dell'area (sfondi) e versione leggibile per i testi su bianco
        $colore = Colori::valido($p['colore_primario'] ?? '', '#B80000');
        $coloreTesto = Colori::testoSu($colore);
        $coloreSuBianco = $coloreTesto === '#FFFFFF' ? $colore : '#1F2937';

        // Informazioni aggiuntive: chiave leggibile, valore e se è un allegato caricato (si mostra «File allegato» al posto del percorso)
        $custom = [];
        foreach (json_decode($p['dati_custom_json'] ?? '', true) ?: [] as $chiave => $valore) {
            $valore = is_scalar($valore) ? (string) $valore : (string) json_encode($valore, JSON_UNESCAPED_UNICODE);
            $custom[] = ['chiave' => str_replace('_', ' ', (string) $chiave), 'valore' => $valore, 'allegato' => str_contains($valore, 'uploads/')];
        }

        // Il link per il QR code
        $base = $this->sito->urlBase();
        $checkin = $base . '/checkin.php?code=' . urlencode($p['codice_prenotazione']);

        return Risposta::html(Vista::rendi('stampe/ricevuta', [
            // La prenotazione, con i valori di ripiego già applicati: il modello mostra, non decide
            'p' => array_replace($p, [
                'stato' => $p['stato'] ?? 'confermata',
                'nome_portale' => ($p['nome_portale'] ?? '') ?: 'UNIVERSITÀ DELLA CALABRIA',
                'sottotitolo_portale' => ($p['sottotitolo_portale'] ?? '') ?: 'Dipartimento di Biologia, Ecologia e Scienze della Terra',
                'evento_luogo' => ($p['evento_luogo'] ?? '') ?: 'DiBEST Unical',
                'matricola_mostrata' => ($p['matricola_effettiva'] ?? '') ?: 'N/D',
                'num_posti' => $p['num_posti'] ?? 1,
                'data_it' => !empty($p['data_turno']) ? date('d/m/Y', strtotime($p['data_turno'])) : '',
                'orario' => Turni::orario($p),
            ]),
            'qr' => (new Qr($base))->html($checkin, 'width:150px;max-width:100%;margin:0 auto;background:#fff', 'QR code per il check-in', 'border p-1 rounded shadow-sm'),
            'css_bootstrap' => Librerie::urlVendor($base, 'jsdelivr/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'),
            'css_icone' => Librerie::urlVendor($base, 'cdnjs/ajax/libs/font-awesome/6.4.0/css/all.min.css'),
            // Evento ad accesso libero: chi c'era ha una presenza, non una prenotazione, e il codice non si mostra
            'senza_prenotazione' => (int) ($p['richiede_prenotazione'] ?? 1) === 0,
            'col_ric' => $colore,
            'col_ric_txt' => $coloreTesto,
            'col_ric_testo' => $coloreSuBianco,
            'custom' => $custom,
        ]));
    }
}
