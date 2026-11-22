<?php

declare(strict_types=1);

namespace App\Auth;

/** Protezione delle pagine dal flooding (spostata da check_rate_limit() di inc/base.php). */
final class LimiteRichieste
{
    public function __construct(private RateLimitRepository $tentativi)
    {
    }

    /**
     * true se la richiesta è permessa (e viene contata), false se l'IP ha superato il limite nella finestra.
     * L'IP viene hashato prima di salvarlo (privacy GDPR).
     */
    public function consenti(string $ip, string $endpoint, int $max = 10, int $finestraSecondi = 300): bool
    {
        $ipHash = hash('sha256', $ip . $endpoint);
        $this->tentativi->pulisci();
        if ($this->tentativi->conta($ipHash, $endpoint, $finestraSecondi) >= $max) {
            return false; // bloccato
        }
        $this->tentativi->registra($ipHash, $endpoint);

        return true;
    }

    /**
     * true se l'IP ha già raggiunto il limite nella finestra. Legge soltanto: non conta la richiesta.
     * Si usa insieme a registra() quando devono contare solo i tentativi falliti (es. codici che non esistono),
     * così chi usa la pagina nel modo giusto, anche in tanti dietro lo stesso indirizzo, non si blocca.
     */
    public function bloccato(string $ip, string $endpoint, int $max = 10, int $finestraSecondi = 300): bool
    {
        $this->tentativi->pulisci();

        return $this->tentativi->conta(hash('sha256', $ip . $endpoint), $endpoint, $finestraSecondi) >= $max;
    }

    /** Aggiunge un tentativo (fallito) per l'IP e la pagina. */
    public function registra(string $ip, string $endpoint): void
    {
        $this->tentativi->registra(hash('sha256', $ip . $endpoint), $endpoint);
    }
}
