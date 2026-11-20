<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Core\Sito;
use App\Email\InvioEmail;
use Throwable;

/**
 * Le email di una richiesta di pubblicazione appena salvata: l'avviso agli indirizzi dell'ufficio comunicazione (ImpostazioniPubblicazioni, tutti i
 * campi leggibili e il pulsante alla scheda nel pannello) e la ricevuta a chi ha inviato la richiesta (docente o personale) con la copia di quanto ha
 * inviato, solo se è accesa nelle impostazioni («Ricevuta a chi invia la richiesta»). I testi stanno nel catalogo
 * delle email (CatalogoPubblicazioni), modificabili dalla pagina «Email» del pannello. I file NON si allegano: si scaricano dalla scheda.
 *
 * Un errore di invio non si propaga mai (la richiesta è già salvata): resta solo nel log del server.
 */
final class NotifichePubblicazioni
{
    public function __construct(
        private InvioEmail $invio,
        private ImpostazioniPubblicazioni $impostazioni,
        private PubblicazioniRepository $repo,
        private Sito $sito,
        private ConfigurazioneCampi $config,
    ) {
    }

    /**
     * Avviso all'ufficio e, se accesa nelle impostazioni, ricevuta a chi ha inviato la richiesta. Non lancia mai eccezioni.
     *
     * @param array<string, mixed> $utente la riga di utenti di chi ha inviato la richiesta
     */
    public function richiestaInviata(array $utente, int $id): void
    {
        try {
            $p = $this->repo->perId($id);
            if ($p === null) {
                return;
            }
            $riepilogo = RiepilogoRichiesta::tabella($p, $this->repo->allegati($id), $this->config);
            $quando = $this->quando((string) $p['inviata_il']);
            $indirizzi = $this->impostazioni->indirizzi();
            $this->avviso($indirizzi, $p, $utente, $riepilogo, $quando, $id);
            if ($this->impostazioni->ricevuta()) {
                $this->ricevuta($indirizzi[0] ?? '', $p, $utente, $riepilogo, $quando);
            }
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] email non inviate: ' . $e->getMessage());
        }
    }

    /**
     * @param list<string> $indirizzi
     * @param array<string, mixed> $p
     * @param array<string, mixed> $utente
     */
    private function avviso(array $indirizzi, array $p, array $utente, string $riepilogo, string $quando, int $id): void
    {
        if ($indirizzi === []) {
            error_log('[Pubblicazioni] avviso non inviato: nessun indirizzo valido nelle impostazioni');

            return;
        }
        $email = trim((string) ($utente['email'] ?? ''));
        $dati = [
            'titolo' => (string) $p['titolo'],
            'richiedente' => (string) $p['richiedente'],
            'email_testo' => $email !== '' ? ' (' . $email . ')' : '',
            'inviata_il' => $quando,
            'riepilogo' => $riepilogo,
        ];
        $link = $this->sito->urlBase() . '/admin/pubblicazioni.php?id=' . $id;
        foreach ($indirizzi as $a) {
            try {
                if (!$this->invio->invia($a, 'pubblicazioni_comunicazione_nuova', $dati, $link)) {
                    error_log('[Pubblicazioni] avviso non inviato a un indirizzo: ' . $this->invio->ultimoErrore());
                }
            } catch (Throwable $e) {
                error_log('[Pubblicazioni] avviso non inviato a un indirizzo: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $utente
     */
    private function ricevuta(string $emailUfficio, array $p, array $utente, string $riepilogo, string $quando): void
    {
        $a = trim((string) ($utente['email'] ?? ''));
        if ($a === '' || filter_var($a, FILTER_VALIDATE_EMAIL) === false) {
            error_log('[Pubblicazioni] ricevuta non inviata: chi ha inviato la richiesta non ha un indirizzo email valido nel portale');

            return;
        }
        $dati = [
            'nome' => trim((string) ($utente['nome'] ?? '')) !== '' ? trim((string) $utente['nome']) : (string) $p['richiedente'],
            'titolo' => (string) $p['titolo'],
            'inviata_il' => $quando,
            'riepilogo' => $riepilogo,
            'email_ufficio' => $emailUfficio,
        ];
        try {
            if (!$this->invio->invia($a, 'pubblicazioni_docente_ricevuta', $dati, $this->sito->urlBase() . '/area_personale.php')) {
                error_log('[Pubblicazioni] ricevuta non inviata a chi ha inviato la richiesta: ' . $this->invio->ultimoErrore());
            }
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] ricevuta non inviata a chi ha inviato la richiesta: ' . $e->getMessage());
        }
    }

    /** «19/11/2026 alle 10:00» dalla data di invio. */
    private function quando(string $inviataIl): string
    {
        $t = strtotime($inviataIl);

        return $t ? date('d/m/Y \a\l\l\e H:i', $t) : '';
    }
}
