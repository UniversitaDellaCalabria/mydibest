<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Una pagina servita dal Nucleo: riceve la richiesta (l'accesso è già stato deciso) e restituisce la risposta. Non stampa, non chiama
 * `exit`, non legge i superglobali: tutto passa da `Richiesta` e da `Risposta`. Si costruisce dal Container, quindi le dipendenze
 * (repository, servizi) si chiedono nel costruttore.
 */
interface Pagina
{
    public function __invoke(Richiesta $r): Risposta;
}
