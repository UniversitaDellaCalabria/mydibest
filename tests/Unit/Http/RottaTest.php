<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Pagina;
use App\Http\Richiesta;
use App\Http\Risposta;
use App\Http\Rotta;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/** Una pagina qualunque per costruire rotte. */
final class PaginaPerRotte implements Pagina
{
    public function __invoke(Richiesta $r): Risposta
    {
        return Risposta::html('');
    }
}

/** Una riga della tabella delle rotte: percorso, pagina, titolo, file di oggi e alias. Nessun permesso. */
final class RottaTest extends TestCase
{
    public function testGliIndirizziSonoPercorsoAliasEFileConESenzaPhp(): void
    {
        $r = new Rotta('chi/siamo', PaginaPerRotte::class, 'Chi siamo', 'admin/chi_siamo.php', ['/vecchio/chi/', 'vecchio/chi?x=1']);

        self::assertSame(['chi/siamo', 'admin/chi_siamo.php', 'admin/chi_siamo', 'vecchio/chi'], $r->indirizzi(), 'alias normalizzati e senza doppioni');
        self::assertSame(['vecchio/chi'], $r->alias);
    }

    /** La rotta non sa a quale parte del portale appartiene (lo sa AccessoPortale::eDelPannello): nel Nucleo non resta nessun «sei del pannello?». */
    public function testLaRottaNonSaSeEDelPannello(): void
    {
        self::assertFalse(method_exists(Rotta::class, 'eDelPannello'));
    }

    public function testUnaRottaFaAMenoDelTokenSoloConIlSuoMotivoScritto(): void
    {
        self::assertNull((new Rotta('a/b', PaginaPerRotte::class, 'B', 'b.php'))->senzaCsrf, 'di norma il controllo c\'è');
        $motivo = 'Webhook del fornitore: non ha la nostra sessione, si autentica con una firma';
        self::assertSame($motivo, (new Rotta('a/b', PaginaPerRotte::class, 'B', 'b.php', senzaCsrf: $motivo))->senzaCsrf);
        foreach (['', '   ', 'webhook', 'serve così'] as $troppoPoco) {
            try {
                new Rotta('a/b', PaginaPerRotte::class, 'B', 'b.php', senzaCsrf: $troppoPoco);
                self::fail("«{$troppoPoco}» non è un motivo: la rotta non doveva costruirsi");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('motivo', $e->getMessage());
            }
        }
    }

    public function testLaRottaNonPortaPermessi(): void
    {
        $campi = array_map(static fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass(Rotta::class))->getProperties());

        sort($campi);
        self::assertSame(['alias', 'file', 'pagina', 'percorso', 'senzaCsrf', 'titolo'], $campi, 'i permessi stanno in PermessiPagine, non nella rotta: due elenchi divergono in un mese');
    }

    /** @dataProvider percorsiNonValidi */
    public function testUnPercorsoNonValidoEUnErrore(string $percorso): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Rotta($percorso, PaginaPerRotte::class, 'X', 'x.php');
    }

    /** @return array<string, array{string}> */
    public static function percorsiNonValidi(): array
    {
        return ['vuoto' => [''], 'barra iniziale' => ['/x'], 'barra finale' => ['x/'], 'risale' => ['a/../b'], 'barra doppia' => ['a//b'], 'parametri' => ['a?b=1']];
    }

    /** @dataProvider fileNonValidi */
    public function testUnFileNonValidoEUnErrore(string $file): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Rotta('x', PaginaPerRotte::class, 'X', $file);
    }

    /** @return array<string, array{string}> */
    public static function fileNonValidi(): array
    {
        return ['vuoto' => [''], 'assoluto' => ['/etc/passwd'], 'risale' => ['../config.php']];
    }

    public function testNormalizza(): void
    {
        self::assertSame('a/b', Rotta::normalizza('/a//b/?q=1'));
        self::assertSame('', Rotta::normalizza('a/../b'));
        self::assertSame('', Rotta::normalizza('/'));
        self::assertSame('a.php', Rotta::normalizza('a.php'));
    }
}
