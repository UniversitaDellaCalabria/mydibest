<?php

declare(strict_types=1);

namespace App\Portale\Area;

use App\Fsl\ServizioProgrammaFsl;
use App\Iscrizioni\RichiestaPrenotazione;
use App\Iscrizioni\ServizioPrenotazioni;

/**
 * Le azioni della pagina pubblica di un'area (master_template.php): «aggiungi al programma FSL» e «invia prenotazione».
 * La pagina controlla il token CSRF, chiama esegui() e fa solo due cose con la risposta: scrivere in sessione i valori indicati e rimandare
 * la persona all'indirizzo indicato. Le regole (controlli, posti, lista d'attesa, email) stanno nei servizi chiamati da qui.
 */
final class AzioniAreaPubblica
{
    public function __construct(
        private ServizioProgrammaFsl $programma,
        private ServizioPrenotazioni $prenotazioni,
    ) {
    }

    /** Questa richiesta è una delle due azioni? (il pulsante premuto lo dice: aggiungi_programma oppure invia_prenotazione) */
    public static function haUnAzione(string $metodo, array $post): bool
    {
        return $metodo === 'POST' && (isset($post['aggiungi_programma']) || isset($post['invia_prenotazione']));
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $get
     * @param array<string,mixed> $files
     * @param array{pagina: array, slug: string, connesso: bool, ruolo: int, ruoliSecondari: list<string>, utenteId: ?int, ip: string, baseUrl: string} $c
     * @return array{rimando: string, sessione: array<string,mixed>} cosa scrivere in sessione e dove rimandare
     */
    public function esegui(array $post, array $get, array $files, array $c): array
    {
        if (isset($post['aggiungi_programma'])) {
            $esito = $this->programma->aggiungi((int)($post['turno_id'] ?? 0), $post, $c['connesso'], $c['ruolo'], $c['ruoliSecondari']);

            return self::rimandoProgramma($c['slug'], $get, $post, $esito);
        }

        $posti = isset($post['num_posti']) ? max(1, (int)$post['num_posti']) : 1;
        $esito = $this->prenotazioni->prenota(new RichiestaPrenotazione(
            $c['pagina'], $c['slug'], (int)$post['turno_id'], trim($post['nome'] ?? ''), trim($post['cognome'] ?? ''),
            strtolower(trim($post['email'] ?? '')), trim($post['matricola'] ?? ''), $posti, $c['utenteId'], $c['ruolo'],
            $c['ruoliSecondari'], $post, $files, $c['ip'], $c['baseUrl']
        ));

        return [
            'rimando' => $esito->destinazione,
            'sessione' => $esito->erroreSessione !== null ? ['errore_prenotazione' => $esito->erroreSessione] : [],
        ];
    }

    /**
     * Dopo «aggiungi al programma» si torna alla stessa pagina (progetto o scheda dell'evento) con programma=ok o programma=errore;
     * in sessione resta il necessario per la domanda «prenota ora o scegli altre» o per il messaggio d'errore.
     *
     * @param array{ok: bool, titolo?: string, sostituita?: bool, errore?: string} $esito
     * @return array{rimando: string, sessione: array<string,mixed>}
     */
    public static function rimandoProgramma(string $slug, array $get, array $post, array $esito): array
    {
        $dove = $slug . '.php';
        if (!empty($get['progetto'])) {
            $dove .= '?progetto=' . (int)$get['progetto'];
        } elseif ((int)($post['da_scheda'] ?? 0) > 0) {
            $dove .= '?evento=' . (int)$post['da_scheda'];
        }
        $unisci = str_contains($dove, '?') ? '&' : '?';
        if ($esito['ok']) {
            return ['rimando' => $dove . $unisci . 'programma=ok', 'sessione' => ['programma_aggiunta' => ['titolo' => $esito['titolo'], 'sostituita' => $esito['sostituita']]]];
        }

        return ['rimando' => $dove . $unisci . 'programma=errore', 'sessione' => ['programma_errore' => ['titolo' => $esito['titolo'], 'errore' => $esito['errore']]]];
    }
}
