<?php

declare(strict_types=1);

namespace App\Portale\Vista;

use App\Auth\EmailNonVerificata;

/**
 * L'avviso a chi ha un'email scelta a mano e mai confermata: dice che cosa non vede e porta al pulsante «Conferma la tua email».
 * Lo usano Area personale, profilo, le pagine di firma del Direttore e il 403 del pannello (qui con `$soloSePerde`: solo a chi è fuori per quel motivo).
 * HTML con le classi di sempre (alert di Bootstrap), niente stili in linea, nessun gestore `on…`.
 */
final class AvvisoEmail
{
    public const PULSANTE = 'Conferma la tua email';

    public function __construct(private readonly EmailNonVerificata $email)
    {
    }

    /**
     * L'avviso per questa persona, o '' se l'email è verificata. Con $soloSePerde anche '' se in nessun elenco «per email» compare il suo indirizzo
     * (il suo no non dipende dall'email).
     *
     * @param array<string, mixed>|null $utente riga di utenti
     */
    public function per(?array $utente, string $link, bool $soloSePerde = false): string
    {
        $indirizzo = EmailNonVerificata::indirizzo($utente);
        if ($utente === null || $indirizzo === null) {
            return '';
        }
        $perdite = $this->email->perdite($utente);
        if ($soloSePerde && !$perdite) {
            return '';
        }

        return self::html($indirizzo, $perdite, $link);
    }

    /**
     * @param list<array{codice: string, cosa: string, quanti: int}> $perdite
     */
    public static function html(string $email, array $perdite, string $link): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $o = '<div class="alert alert-warning d-flex flex-wrap align-items-start gap-2" role="alert">'
            . '<i class="fa fa-envelope-circle-check mt-1" aria-hidden="true"></i>'
            . '<div class="flex-grow-1"><strong>La tua email non è ancora confermata.</strong> L\'indirizzo <span class="font-monospace">' . $h($email)
            . '</span> l\'hai scelto tu e non l\'hai ancora confermato: per sicurezza il portale non gli dà compiti, firme e permessi che arrivano per email.';
        if ($perdite) {
            $o .= ' Per questo non vedi:<ul class="mb-1 mt-1">';
            foreach ($perdite as $p) {
                $o .= '<li>' . $h((string) $p['cosa']) . ((int) ($p['quanti'] ?? 0) > 1 ? ' (' . (int) $p['quanti'] . ')' : '') . '</li>';
            }
            $o .= '</ul>';
        } else {
            $o .= ' Se dovresti vedere compiti, firme o prenotazioni e non li vedi, confermala.';
        }
        $o .= ' Ti mandiamo un codice di 6 cifre a quell\'indirizzo; oppure accedi con SPID, CIE o le credenziali di Ateneo usando la stessa email.</div>'
            . '<a class="btn btn-sm btn-dark fw-bold align-self-center" href="' . $h($link) . '">' . self::PULSANTE . '</a></div>';

        return $o;
    }
}
