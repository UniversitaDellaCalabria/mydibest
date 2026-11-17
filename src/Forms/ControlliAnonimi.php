<?php

declare(strict_types=1);

namespace App\Forms;

use App\Auth\LimiteRichieste;
use App\Iscrizioni\CaptchaPrenotazione;

/**
 * I controlli di chi compila un form senza aver fatto l'accesso: campo trappola per i robot, limite di invii per connessione (IP) e domanda anti-robot.
 * Sono gli stessi delle prenotazioni pubbliche (CaptchaPrenotazione e LimiteRichieste, riusati così come sono), con un limite proprio per ogni form.
 */
final class ControlliAnonimi
{
    /** Invii ammessi da una connessione, per ogni form, in un'ora. */
    public const MAX_INVII_ORA = 10;

    public function __construct(private CaptchaPrenotazione $captcha, private LimiteRichieste $limite)
    {
    }

    /** La domanda da mostrare nella pagina (una per pagina; la risposta resta in sessione). @return array{id: string, domanda: string} */
    public function domanda(): array
    {
        return $this->captcha->domanda();
    }

    /**
     * Controlla un invio senza accesso. Ritorna null se può passare, altrimenti il messaggio da mostrare. Ogni invio conta per il limite, anche quello
     * con la risposta sbagliata: così non si prova all'infinito. Il campo trappola («sito_web») nascosto alle persone deve essere vuoto.
     *
     * @param array<string, mixed> $post
     */
    public function verifica(int $formId, array $post, string $ip): ?string
    {
        if (trim((string) ($post['sito_web'] ?? '')) !== '') {
            return 'Risposta non registrata: riprova.';
        }
        if (!$this->limite->consenti($ip !== '' ? $ip : 'sconosciuto', 'form_' . $formId, self::MAX_INVII_ORA, 3600)) {
            return "Troppi invii da questa connessione: riprova tra un'ora oppure accedi.";
        }

        return $this->captcha->verifica((string) ($post['captcha_id'] ?? ''), (string) ($post['captcha_risposta'] ?? ''));
    }
}
