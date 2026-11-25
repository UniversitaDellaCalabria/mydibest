<?php

declare(strict_types=1);

namespace Unical\Titulus;

use RuntimeException;

/**
 * Il documento è partito ma la risposta di Titulus non è arrivata (o non si legge): non si sa se è stato protocollato. Chi chiama NON deve
 * riprovare da solo, perché potrebbe creare un secondo protocollo: l'esito va verificato in Titulus da una persona.
 * Un guasto che sicuramente ha impedito l'invio (non si raggiunge il server) o un errore risposto da Titulus restano RuntimeException semplici.
 */
final class RispostaPersaTitulus extends RuntimeException
{
}
