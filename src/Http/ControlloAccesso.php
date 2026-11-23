<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Chi decide se una richiesta può arrivare alla pagina di una rotta. Il Nucleo non sa chi sia l'utente né quali permessi esistano: lo chiede
 * a chi lo sa (il portale). Viene chiesto PRIMA di costruire la pagina: la logica della pagina non gira mai per chi non ha il permesso.
 *
 * Risposte possibili:
 *  - `true`: si può entrare;
 *  - `false`: permesso mancante, e il Nucleo risponde con il 403 NUDO (Risposta::negata(), testo semplice). Il Nucleo non sa vestire niente:
 *    chi vuole una pagina vestita restituisce lui la `Risposta`;
 *  - una `Risposta`: un caso che il portale sa descrivere meglio di un 403 nudo (la persona non è collegata: rimando al login; non ha
 *    sezioni da gestire: rimando alla sua area personale; login non riuscito: la pagina «Autenticazione richiesta»; o il suo 403 col testo e il
 *    layout che vuole). Il Nucleo la manda così com'è: AccessoPortale usa questa strada per tutte le pagine del pannello.
 */
interface ControlloAccesso
{
    public function verifica(Rotta $rotta, Richiesta $richiesta): bool|Risposta;
}
