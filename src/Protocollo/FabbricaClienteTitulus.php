<?php

declare(strict_types=1);

namespace App\Protocollo;

/** Crea il client di Titulus per una configurazione: il vero è SOAP, nelle prove si registra un doppio. */
interface FabbricaClienteTitulus
{
    public function crea(ConfigTitulus $config, string $password): ClienteTitulus;
}
