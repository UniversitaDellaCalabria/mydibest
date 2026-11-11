<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Controllo delle date nel formato AAAA-MM-GG (quello dei campi «data» dei moduli).
 * Una data è valida solo se esiste davvero (non il 30 febbraio, non il mese 13) e il testo finisce lì: l'«$» di un'espressione regolare
 * accetterebbe anche un a capo in fondo, che il database e le intestazioni HTTP non gradiscono.
 */
final class DataIso
{
    public static function valida(string $testo): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $testo, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
