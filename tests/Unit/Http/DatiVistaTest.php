<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\DatiVista;
use LogicException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use stdClass;

/** L'oggetto `$d` dei modelli: l'escape è automatico, scavalcarlo si chiama «grezzo», e gli errori di nome non passano in silenzio. */
final class DatiVistaTest extends TestCase
{
    private const CATTIVO = '<script>alert("x")</script> & \'q\'';

    public function testIlValoreEscape(): void
    {
        $d = DatiVista::da(['nome' => self::CATTIVO]);

        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;q&#039;', $d->nome);
        self::assertStringNotContainsString('<script>', $d->nome);
    }

    public function testGrezzoNonLoTocca(): void
    {
        $d = DatiVista::da(['html' => self::CATTIVO]);

        self::assertSame(self::CATTIVO, $d->grezzo('html'), 'è il modo di scavalcare l\'escape, e si vede nel modello');
    }

    /** Lo stesso testo che produceva la lambda `$h` delle pagine, così le pagine convertite rendono lo stesso HTML. */
    public function testDaLoStessoTestoDellaVecchiaLambda(): void
    {
        $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        foreach (['', 'Città & Università', '"doppio" e \'singolo\'', '<b>x</b>', '&amp; già escapato', 'a < b > c', "riga\ndue", '0', '12'] as $testo) {
            self::assertSame($h($testo), DatiVista::da(['v' => $testo])->v, $testo);
        }
    }

    public function testUnByteNonValidoSiSostituisceNonSvuotaIlCampo(): void
    {
        $d = DatiVista::da(['v' => "a\xC3\x28b"]);

        self::assertSame("a\u{FFFD}(b", $d->v, 'htmlspecialchars senza ENT_SUBSTITUTE restituirebbe una stringa vuota');
    }

    public function testITipiSemplici(): void
    {
        $d = DatiVista::da(['n' => 5, 'f' => 1.5, 'no' => null, 'vero' => true, 'falso' => false, 'zero' => 0]);

        self::assertSame('5', $d->n);
        self::assertSame('1.5', $d->f);
        self::assertSame('', $d->no);
        self::assertSame('1', $d->vero);
        self::assertSame('', $d->falso);
        self::assertSame('0', $d->zero);
        self::assertTrue((bool) $d->vero);
        self::assertFalse((bool) $d->falso);
    }

    public function testUnNomeNonPassatoEUnErroreChiaro(): void
    {
        $d = DatiVista::da(['titolo' => 'x', 'altro' => 'y']);

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('«titlo»');
        $d->titlo;
    }

    public function testIssetEIlOperatoreNullCoalescingFunzionano(): void
    {
        $d = DatiVista::da(['c' => 'x', 'vuoto' => null, 'zero' => 0]);

        self::assertTrue(isset($d->c));
        self::assertFalse(isset($d->vuoto), 'null = non c\'è');
        self::assertFalse(isset($d->manca));
        self::assertTrue(isset($d->zero));
        self::assertSame('x', $d->c ?? 'ripiego');
        self::assertSame('ripiego', $d->manca ?? 'ripiego');
        self::assertSame('ripiego', $d->vuoto ?? 'ripiego');
        self::assertTrue(empty($d->manca));
        self::assertTrue(empty($d->zero), '«0» è vuoto come per una variabile');
        self::assertFalse(empty($d->c));
    }

    public function testUnRecordSiScendeEUnaListaNo(): void
    {
        $d = DatiVista::da(['scheda' => ['titolo' => self::CATTIVO, 'n' => 3], 'voci' => [['a' => 1]], 'vuota' => []]);

        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;q&#039;', $d->scheda->titolo);
        self::assertSame('3', $d->scheda->n);
        foreach (['voci', 'vuota'] as $nome) {
            try {
                $d->$nome;
                self::fail("$nome è una lista: deve passare da elenco()");
            } catch (LogicException $e) {
                self::assertStringContainsString("elenco('$nome')", $e->getMessage());
            }
        }
    }

    public function testElencoDaElementiCheSiComportanoAlloStessoModo(): void
    {
        $d = DatiVista::da(['voci' => [['nome' => '<b>a</b>'], ['nome' => 'b & c']], 'etichette' => ['<i>x</i>', 'y'], 'niente' => null]);

        $voci = $d->elenco('voci');
        self::assertCount(2, $voci);
        self::assertInstanceOf(DatiVista::class, $voci[0]);
        self::assertSame('&lt;b&gt;a&lt;/b&gt;', $voci[0]->nome);
        self::assertSame('b &amp; c', $voci[1]->nome);
        self::assertSame(['&lt;i&gt;x&lt;/i&gt;', 'y'], $d->elenco('etichette'), 'un testo dentro un elenco esce già con l\'escape');
        self::assertSame([], $d->elenco('niente'));
    }

    public function testElencoNonEsportaLeChiaviCheNonSonoEscapate(): void
    {
        $d = DatiVista::da(['mappa' => ['"><script>' => 'uno', 'b' => 'due']]);

        self::assertSame(['uno', 'due'], $d->elenco('mappa'), 'una lista: le chiavi dei dati non arrivano mai in un attributo');
    }

    public function testElencoSuUnTestoEUnErrore(): void
    {
        $d = DatiVista::da(['t' => 'testo']);

        $this->expectException(LogicException::class);
        $d->elenco('t');
    }

    public function testUnOggettoNonSiLeggeSenzaGrezzo(): void
    {
        $o = new stdClass();
        $d = DatiVista::da(['o' => $o]);

        self::assertSame($o, $d->grezzo('o'));
        $this->expectException(LogicException::class);
        $d->o;
    }

    public function testISaldiNonSiModificano(): void
    {
        $d = DatiVista::da(['a' => 'x']);

        foreach ([static function () use ($d): void { $d->a = 'y'; }, static function () use ($d): void { unset($d->a); }] as $f) {
            try {
                $f();
                self::fail('i dati di una vista sono immutabili');
            } catch (LogicException) {
                self::assertSame('x', $d->a);
            }
        }
    }

    public function testUnRecordGiaAvvoltoPassaCosiComeE(): void
    {
        $record = DatiVista::da(['t' => '<b>']);
        $d = DatiVista::da(['c' => $record, 'lista' => [$record]]);

        self::assertSame('&lt;b&gt;', $d->c->t, 'non si escapa due volte');
        self::assertSame('&lt;b&gt;', $d->elenco('lista')[0]->t);
    }
}
