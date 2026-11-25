<?php

declare(strict_types=1);

namespace Tests\Unit\Portale;

use App\Portale\Vista\AvvisoEmail;
use PHPUnit\Framework\TestCase;

/** L'avviso a chi ha un'email scelta a mano e non confermata: che cosa non vede e il pulsante «Conferma la tua email». */
final class AvvisoEmailTest extends TestCase
{
    public function testDiceCheCosaNonVedeEHaIlPulsante(): void
    {
        $html = AvvisoEmail::html('mia@gmail.com', [
            ['codice' => 'conferme', 'cosa' => 'Le conferme da dare come docente', 'quanti' => 2],
            ['codice' => 'prenotazioni', 'cosa' => 'Le prenotazioni fatte senza accedere', 'quanti' => 1],
        ], 'profilo.php#email');
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('mia@gmail.com', $html);
        $this->assertStringContainsString('Le conferme da dare come docente', $html);
        $this->assertStringContainsString('Le prenotazioni fatte senza accedere', $html);
        $this->assertMatchesRegularExpression('/<a [^>]*href="profilo\.php#email"[^>]*>[^<]*Conferma la tua email/', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('style=', $html, 'niente stili in linea');
    }

    public function testSenzaPerditeSpecificheDaUnTestoGenerale(): void
    {
        $html = AvvisoEmail::html('mia@gmail.com', [], 'profilo.php#email');
        $this->assertStringContainsString('non è ancora confermata', $html);
        $this->assertStringContainsString('Conferma la tua email', $html);
        $this->assertStringNotContainsString('<li', $html, 'nessun elenco se non c\'è niente di preciso');
    }

    public function testEscapaTuttoQuelloCheViene_DaFuori(): void
    {
        $html = AvvisoEmail::html('"><script>x</script>@gmail.com', [['codice' => 'x', 'cosa' => '<b>Cosa</b>', 'quanti' => 1]], 'profilo.php#email"><script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }
}
