<?php

declare(strict_types=1);

namespace App\Eventi;

/**
 * Chi può iscriversi a un evento, secondo eventi.ruolo_accesso_id. È l'unica copia della regola: la usano la pagina (pulsante
 * «Prenota», «Accedi» o «Riservato»), ServizioPrenotazioni e ServizioProgrammaFsl, così il pulsante e il controllo vero non possono divergere.
 *
 *   0  = nessuna restrizione, anche senza aver fatto l'accesso
 *  -1  = basta aver fatto l'accesso
 *   n  = (n > 0) l'accesso è fatto e il ruolo principale o uno dei secondari è n
 * L'amministratore (ruolo 1, principale o secondario) passa sempre; il gestore (ruolo 2) no. Funzioni pure, senza database.
 */
final class AccessoEvento
{
    /** Per iscriversi serve aver fatto l'accesso (qualunque restrizione diversa da 0). */
    public static function serveAccesso(int $ruoloAccesso): bool
    {
        return $ruoloAccesso !== 0;
    }

    /**
     * @param list<string> $ruoliSecondari ruoli secondari dell'utente connesso, come testo (es. ['3', '6'])
     */
    public static function ammesso(int $ruoloAccesso, bool $connesso, int $ruolo, array $ruoliSecondari): bool
    {
        if (!self::serveAccesso($ruoloAccesso)) {
            return true;
        }

        return $connesso && ($ruoloAccesso === -1
            || $ruolo === $ruoloAccesso || in_array((string) $ruoloAccesso, $ruoliSecondari, true)
            || $ruolo === 1 || in_array('1', $ruoliSecondari, true));
    }
}
