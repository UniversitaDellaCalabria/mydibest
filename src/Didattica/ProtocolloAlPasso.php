<?php

declare(strict_types=1);

namespace App\Didattica;

use Closure;

/** Collega il flusso delle pratiche al protocollo automatico, risolvendolo solo quando serve. */
final class ProtocolloAlPasso implements AlPassoProtocollo
{
    /** @param Closure(): ProtocolloAutomaticoPratica $protocollo */
    public function __construct(private Closure $protocollo)
    {
    }

    public function raggiunto(int $praticaId): void
    {
        ($this->protocollo)()->alPassoProtocollo($praticaId);
    }
}
