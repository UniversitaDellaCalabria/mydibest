<?php

declare(strict_types=1);

namespace App\Portale;

use App\Auth\Csrf;
use App\Auth\Flash;
use App\Core\Container;
use App\Eventi\FonteAreeEventi;
use App\Http\Avvisi;
use App\Http\ControlloAccesso;
use App\Http\Nucleo;
use App\Http\ProtezioneCsrf;
use App\Http\Vista;
use App\Infrastructure\Mail\ImpaginatoreEmail;
use App\Portale\Instradamento\AccessoPortale;
use App\Portale\Instradamento\Rotte;

/** Servizi del modulo Portale nel container (scoperta da App\Core\App). */
final class Registrazione
{
    /**
     * I due layout con cui il Nucleo veste le pagine (Vista::layout): [file che apre, file che chiude], relativi alla radice del sito. Sono
     * configurazione di QUESTO portale e si danno una volta sola, qui, dove nasce il container: App\Http\Vista non conosce nessun nome di file.
     */
    public const LAYOUT_VISTE = [
        'sito' => ['header.php', 'footer.php'],
        'pannello' => ['admin/admin_header.php', 'admin/admin_footer.php'],
    ];

    public static function registra(Container $c): void
    {
        Vista::layout(self::LAYOUT_VISTE);
        // L'impaginazione delle email
        $c->set(ImpaginatoreEmail::class, static fn (): ImpaginatoreEmail => new ImpaginatoreEmailPortale());
        // Le aree pubblicate le fornisce il modulo Eventi
        $c->set(FonteAree::class, static fn (Container $c): FonteAree => $c->get(FonteAreeEventi::class));
        // Il Nucleo (App\Http): la tabella delle rotte e il controllo dell'accesso sono del portale
        $c->set(ControlloAccesso::class, static fn (Container $c): ControlloAccesso => $c->get(AccessoPortale::class));
        $c->set(ProtezioneCsrf::class, static fn (Container $c): ProtezioneCsrf => $c->get(Csrf::class));
        $c->set(Avvisi::class, static fn (Container $c): Avvisi => $c->get(Flash::class));
        $c->set(Nucleo::class, static fn (Container $c): Nucleo => new Nucleo(
            $c,
            Rotte::elenco(),
            $c->get(ControlloAccesso::class),
            $c->get(ProtezioneCsrf::class),
            $c->get(Avvisi::class),
            defined('RADICE_SITO') ? (string) RADICE_SITO : dirname(__DIR__, 2),
        ));
        $c->set(ConfigurazioneHome::class, static fn (Container $c): ConfigurazioneHome => $c->get(WidgetHome::class));
    }
}
