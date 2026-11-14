<?php

declare(strict_types=1);

namespace App\Didattica;

/** Il flusso delle pratiche di sempre, più il protocollo in entrata automatico subito dopo l'invio (vedi ProtocolloAutomaticoPratica). */
final class FlussoPraticaConProtocollo implements FlussoPratica
{
    public function __construct(private FlussoPratica $flusso, private ProtocolloAutomaticoPratica $protocollo)
    {
    }

    public function avvia(int $pratica, array $modulo, array $utente): void
    {
        $this->flusso->avvia($pratica, $modulo, $utente);
        $this->protocollo->dopoInvio($pratica);
    }

    public function rigenera(int $pratica, array $modulo): void
    {
        $this->flusso->rigenera($pratica, $modulo);
    }
}
