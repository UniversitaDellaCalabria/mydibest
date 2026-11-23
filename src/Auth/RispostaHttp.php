<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Risposta;

/**
 * Facciata di App\Http\Risposta::negata(): interrompe la richiesta con un 403 quando l'accesso non è consentito.
 * Le due strade (permesso che manca, token CSRF sbagliato) mostrano lo STESSO testo, Risposta::MESSAGGIO_NEGATO:
 * prima erano «Accesso negato.» e «Richiesta non valida o sessione scaduta…».
 */
final class RispostaHttp
{
    /** Spostata da nega_accesso() di inc/eventi_progetti.php. */
    public static function negaAccesso(): never
    {
        Risposta::negata()->emetti();
        exit;
    }

    /** Token CSRF mancante o diverso (csrf_verify()). */
    public static function csrfNonValido(): never
    {
        Risposta::negata()->emetti();
        exit;
    }
}
