<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Chi sa dire se il token ricevuto con un POST è quello della sessione. Il Nucleo lo chiede PRIMA di costruire la pagina di ogni POST: una
 * pagina non può dimenticarsene, e non lo verifica più da sé. Come per ControlloAccesso, il Nucleo non sa dove il token sia custodito
 * (sessione, cookie…): lo sa chi implementa questa interfaccia (App\Auth\Csrf).
 */
interface ProtezioneCsrf
{
    /** Il token ricevuto (campo `csrf_token` del modulo) è valido? Un valore mancante o non testuale non lo è. */
    public function valido(mixed $ricevuto): bool;
}
