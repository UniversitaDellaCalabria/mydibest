<?php

declare(strict_types=1);

namespace App\Sistema;

use App\Auth\ServizioUtenti;
use App\Core\Ambiente;
use App\Core\Container;
use App\Core\Database;
use App\Core\Sito;
use App\Email\InvioEmail;
use App\Sistema\Backup\StatoBackup;

/** Servizi del modulo Sistema che ricevono valori non di classe (ambiente locale). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        // In locale il server di sviluppo serve una richiesta alla volta: niente controlli HTTP delle pagine
        $c->set(ControlloSito::class, static fn (Container $c): ControlloSito => new ControlloSito(
            $c->get(Database::class),
            $c->get(Sito::class),
            $c->get(StatoBackup::class),
            $c->get(LogEmailRepository::class),
            $c->get(ServizioUtenti::class),
            $c->get(InvioEmail::class),
            defined('AMBIENTE_LOCALE') && AMBIENTE_LOCALE,
            $c->get(SaluteSito::class),
        ));
        // Controllo di salute (pagina Sistema): in prova e in locale i processi automatici non girano, quindi niente rosso e niente avvisi
        $c->set(SaluteSito::class, static fn (Container $c): SaluteSito => new SaluteSito(
            $c->get(Sito::class),
            $c->get(StatoBackup::class),
            $c->get(StatoCron::class),
            $c->get(LogEmailRepository::class),
            Ambiente::corrente(),
            defined('AMBIENTE_LOCALE') && AMBIENTE_LOCALE,
        ));
    }
}
