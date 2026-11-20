<?php

declare(strict_types=1);

namespace Tests\Unit\Amministrazione;

use PHPUnit\Framework\TestCase;

/** Il modulo «Amministrazione» nell'elenco dei moduli del pannello (inc/sezioni.php): card, colore, pagine. Le richieste di pubblicazione non sono più un modulo a sé. */
final class ModuloAmministrazioneTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once RADICE_SITO . '/inc/sezioni.php';
    }

    public function testIlModuloAmministrazioneCeEQuelloDelleRichiesteNo(): void
    {
        $m = MODULI_PORTALE['amministrazione'];
        self::assertSame('Amministrazione', $m['nome']);
        self::assertSame('fa-building-columns', $m['icona']);
        self::assertStringContainsString('richieste di pubblicazione su sito e social', $m['descr']);
        self::assertArrayNotHasKey('pubblicazioni', MODULI_PORTALE, 'niente più card a sé per le richieste di pubblicazione');
    }

    public function testIlColoreNonSiRipeteTraIModuli(): void
    {
        $colori = array_map(static fn (array $m): string => strtolower($m['colore']), MODULI_PORTALE);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $colori['amministrazione']);
        self::assertSame(count($colori), count(array_unique($colori)), 'ogni modulo ha il suo colore');
    }

    public function testLePagineDelModuloEIlSuoOrdine(): void
    {
        self::assertSame('amministrazione', PAGINE_MODULO['amministrazione.php']);
        self::assertSame('amministrazione', PAGINE_MODULO['pubblicazioni.php'], 'la pagina delle richieste resta dov\'è ma è nel modulo Amministrazione');
        foreach (PAGINE_MODULO as $pagina => $modulo) {
            self::assertArrayHasKey($modulo, MODULI_PORTALE, "$pagina punta a un modulo che non esiste");
        }
        $chiavi = array_keys(MODULI_PORTALE);
        self::assertSame('portale', end($chiavi), 'Gestione del portale resta l\'ultimo');
        self::assertLessThan(array_search('portale', $chiavi, true), array_search('amministrazione', $chiavi, true));
    }
}
