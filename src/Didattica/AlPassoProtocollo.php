<?php

declare(strict_types=1);

namespace App\Didattica;

/**
 * Avvisa che una pratica è appena arrivata a un ufficio di tipo «protocollo» (dopo una conferma o una firma, o per un'assegnazione).
 * Il flusso delle pratiche conosce solo questa interfaccia: chi protocolla viene preso al momento dell'uso, per non creare un giro
 * di dipendenze (il protocollo automatico a sua volta registra il numero attraverso il flusso).
 */
interface AlPassoProtocollo
{
    public function raggiunto(int $praticaId): void;
}
