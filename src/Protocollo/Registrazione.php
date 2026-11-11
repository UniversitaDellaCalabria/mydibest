<?php

declare(strict_types=1);

namespace App\Protocollo;

use App\Core\Container;

/** Servizi del modulo Protocollo nel container (scoperta da App\Core\App). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        // Il client vero è SOAP; le prove automatiche registrano un doppio che non esce dal portale
        $c->set(FabbricaClienteTitulus::class, static fn (): FabbricaClienteTitulus => new FabbricaClienteSoap());
    }
}
