<?php

declare(strict_types=1);

namespace Tests\Unit\Assistenza;

use App\Assistenza\Push\ChiaviVapid;
use App\Assistenza\Push\ClienteHttp;
use App\Assistenza\Push\InvioPush;
use App\Core\Ambiente;
use App\Core\Config;
use PHPUnit\Framework\TestCase;
use Tests\Doppi\OrologioFisso;

/** Copia di prova: le notifiche push non partono mai (si scrive solo nel registro del server). */
final class InvioPushInProvaTest extends TestCase
{
    private function http(): ClienteHttp
    {
        return new class () implements ClienteHttp {
            public int $chiamate = 0;

            public function post(string $url, array $intestazioni, string $corpo, int $secondi = 10): array
            {
                ++$this->chiamate;

                return ['codice' => 201, 'corpo' => ''];
            }
        };
    }

    public function testInProvaNonSiInviaNulla(): void
    {
        $http = $this->http();
        $invio = new InvioPush(new ChiaviVapid(new Config([]), new OrologioFisso()), $http, new Ambiente(true));
        $vecchio = ini_set('error_log', sys_get_temp_dir() . '/push_prova_' . bin2hex(random_bytes(3)) . '.log');

        $r = $invio->invia(['endpoint' => 'https://push.esempio.invalid/x', 'p256dh' => 'a', 'auth' => 'b'], '{"titolo":"Nuova chat","corpo":"dati personali"}');

        ini_set('error_log', (string) $vecchio);
        self::assertFalse($r['ok']);
        self::assertFalse($r['scaduta'], 'non si cancella nessuna iscrizione');
        self::assertStringContainsString('Ambiente di prova', $r['errore']);
        self::assertSame(0, $http->chiamate);
    }
}
