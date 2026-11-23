<?php

declare(strict_types=1);

namespace App\Portale\Instradamento;

use App\Auth\Saml\AccessoSso;
use App\Core\Ambiente;
use App\Core\IndirizzoClient;
use App\Http\Richiesta;

/**
 * L'accesso automatico dalla sessione SSO di Ateneo (la facciata sync_sso_user() delle pagine-script), per le pagine servite dal Nucleo:
 * stesse regole, ma cookie, indirizzo e browser arrivano dalla richiesta invece che dai superglobali. Se la persona è già collegata non fa niente.
 */
final class SincronizzaAccesso
{
    /** Dove sta SimpleSAML sul server di Ateneo (lo stesso percorso di sync_sso_user()). */
    private const SIMPLESAML = '/opt/simplesamlphp/lib/_autoload.php';

    public function __construct(private readonly AccessoSso $sso, private readonly IndirizzoClient $indirizzo)
    {
    }

    public function __invoke(Richiesta $r): bool
    {
        return $this->sso->sincronizza(
            !empty($r->cookie(Ambiente::corrente()->nomeCookieUscito())),
            self::SIMPLESAML,
            (string) ($this->indirizzo->ip() ?? ''),
            (string) ($r->intestazione('user-agent') ?? ''),
        );
    }
}
