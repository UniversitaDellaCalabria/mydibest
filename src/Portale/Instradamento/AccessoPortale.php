<?php

declare(strict_types=1);

namespace App\Portale\Instradamento;

use App\Http\ControlloAccesso;
use App\Http\Richiesta;
use App\Http\Risposta;
use App\Http\Rotta;
use App\Portale\Pannello\AccessoPannello;

/**
 * Chi può aprire la pagina di una rotta, per il Nucleo. La fonte è sempre PermessiPagine (la stessa mappa che applica admin_header.php): la rotta dice
 * da quale file di admin/ viene, e qui si chiede ad AccessoPannello, che ha le regole e le risposte. Nessun permesso è scritto nella tabella delle rotte.
 *
 *  - Rotta pubblica (il suo file non sta in admin/): si entra. Come per le pagine di oggi, le regole proprie di ciascuna (il codice della ricevuta,
 *    l'accesso al proprio profilo) restano dentro la pagina.
 *  - Rotta del pannello: si sincronizza l'accesso dalla sessione SSO e poi decide AccessoPannello. Ogni caso che non è «si entra» è una Risposta già
 *    completa (rimando al login SSO, pagina «Autenticazione richiesta», rimando all'area personale, 403 nel layout del pannello): il Nucleo la manda
 *    così com'è e la testata del pannello, che sa che l'accesso è deciso, non lo decide una seconda volta.
 */
final class AccessoPortale implements ControlloAccesso
{
    public function __construct(private readonly AccessoPannello $accesso, private readonly SincronizzaAccesso $sincronizza)
    {
    }

    /** La rotta sta nel pannello (il suo file è dentro admin/)? Lo sa il portale, non il Nucleo: la rotta non sa a quale parte del portale appartiene. */
    public static function eDelPannello(Rotta $rotta): bool
    {
        return str_starts_with($rotta->file, 'admin/');
    }

    public function verifica(Rotta $rotta, Richiesta $richiesta): bool|Risposta
    {
        if (!self::eDelPannello($rotta)) {
            return true;
        }
        ($this->sincronizza)($richiesta);

        return $this->accesso->decidi($richiesta, substr($rotta->file, strlen('admin/')));
    }
}
