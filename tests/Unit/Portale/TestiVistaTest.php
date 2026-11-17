<?php

declare(strict_types=1);

namespace Tests\Unit\Portale;

use App\Portale\Vista\Testi;
use PHPUnit\Framework\TestCase;

/** App\Portale\Vista\Testi::pulisciHtmlSemplice(): HTML ridotto a un sottoinsieme sicuro per le descrizioni scritte dagli utenti del pannello. */
final class TestiVistaTest extends TestCase
{
    public function testScriptSparisceConIlContenuto(): void
    {
        self::assertSame('', Testi::pulisciHtmlSemplice('<script>alert(1)</script>'));
    }

    public function testImgConOnerrorSparisce(): void
    {
        self::assertSame('', Testi::pulisciHtmlSemplice('<img src=x onerror=alert(1)>'));
    }

    public function testAttributoOnclickTolto(): void
    {
        self::assertSame('<p>Ciao</p>', Testi::pulisciHtmlSemplice('<p onclick="alert(1)">Ciao</p>'));
    }

    public function testAttributoStyleTolto(): void
    {
        self::assertSame('<p>Testo</p>', Testi::pulisciHtmlSemplice('<p style="color:red" class="x">Testo</p>'));
    }

    public function testLinkJavascriptDiventaTesto(): void
    {
        self::assertSame('Clicca', Testi::pulisciHtmlSemplice('<a href="javascript:alert(1)">Clicca</a>'));
    }

    public function testLinkJavascriptMaiuscoleDiventaTesto(): void
    {
        self::assertSame('Clicca', Testi::pulisciHtmlSemplice('<a href="JavaScript:alert(1)">Clicca</a>'));
    }

    public function testLinkJavascriptConEntitaDiventaTesto(): void
    {
        self::assertSame('Clicca', Testi::pulisciHtmlSemplice('<a href="jav&#x61;script:alert(1)">Clicca</a>'));
    }

    public function testLinkJavascriptConSpazioIniziale(): void
    {
        self::assertSame('Clicca', Testi::pulisciHtmlSemplice('<a href="  javascript:alert(1)">Clicca</a>'));
    }

    public function testLinkSenzaHrefDiventaTesto(): void
    {
        self::assertSame('Clicca', Testi::pulisciHtmlSemplice('<a>Clicca</a>'));
    }

    public function testLinkHttpsAmmessoConRelETarget(): void
    {
        self::assertSame(
            '<a href="https://example.com" rel="noopener noreferrer" target="_blank">Link</a>',
            Testi::pulisciHtmlSemplice('<a href="https://example.com" onclick="x()" class="y">Link</a>')
        );
    }

    public function testLinkHttpAmmesso(): void
    {
        self::assertSame(
            '<a href="http://example.com" rel="noopener noreferrer" target="_blank">Link</a>',
            Testi::pulisciHtmlSemplice('<a href="http://example.com">Link</a>')
        );
    }

    public function testLinkMailtoAmmesso(): void
    {
        self::assertSame(
            '<a href="mailto:test@example.com" rel="noopener noreferrer" target="_blank">Email</a>',
            Testi::pulisciHtmlSemplice('<a href="mailto:test@example.com">Email</a>')
        );
    }

    public function testTestoNormaleInvariato(): void
    {
        self::assertSame('<p>Ciao a tutti</p>', Testi::pulisciHtmlSemplice('<p>Ciao a tutti</p>'));
    }

    public function testLettereAccentateConservate(): void
    {
        self::assertSame('<p>Perché così è più semplice</p>', Testi::pulisciHtmlSemplice('<p>Perché così è più semplice</p>'));
    }

    public function testTagNonAmmessoLasciaIlTesto(): void
    {
        self::assertSame('<p>Dentro</p>', Testi::pulisciHtmlSemplice('<div class="x"><p>Dentro</p></div>'));
    }

    public function testListeAmmesse(): void
    {
        self::assertSame('<ul><li>Uno</li><li>Due</li></ul>', Testi::pulisciHtmlSemplice('<ul><li>Uno</li><li>Due</li></ul>'));
    }

    public function testBrEStiliBaseAmmessi(): void
    {
        self::assertSame('Testo <b>semplice</b> e basta<br>a capo', Testi::pulisciHtmlSemplice('Testo <b>semplice</b> e basta<br>a capo'));
    }

    public function testStringaVuota(): void
    {
        self::assertSame('', Testi::pulisciHtmlSemplice(''));
        self::assertSame('', Testi::pulisciHtmlSemplice('   '));
    }
}
