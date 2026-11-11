<?php

declare(strict_types=1);

namespace App\Protocollo;

final class FabbricaClienteSoap implements FabbricaClienteTitulus
{
    public function crea(ConfigTitulus $config, string $password): ClienteTitulus
    {
        return new ClienteSoapTitulus($config, $password);
    }
}
