<?php

declare(strict_types=1);

namespace App\Fsl;

/**
 * Il blocco per istituto (GET_LOCK) non si è ottenuto entro l'attesa: un'altra richiesta sta lavorando sulla stessa convenzione. Non si procede
 * (niente righe nuove, niente scritture): chi chiama risponde «Riprova tra qualche secondo».
 */
final class BloccoOccupato extends \RuntimeException
{
    public const MESSAGGIO = 'Riprova tra qualche secondo: il portale sta già lavorando su questa convenzione.';

    public function __construct()
    {
        parent::__construct(self::MESSAGGIO);
    }
}
