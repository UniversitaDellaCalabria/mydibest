<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\RitornoInterno;
use PHPUnit\Framework\TestCase;

/** Dopo l'accesso si torna solo a una pagina del sito: mai a un altro sito né a codice. */
final class RitornoInternoTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function indirizziDelSito(): array
    {
        return [
            'pagina con parametro' => ['form.php?c=abc123'],
            'percorso dalla radice' => ['/area_personale.php'],
            'percorso con cartella' => ['/mydibest/form.php?c=abc&x=1'],
            'con ancora' => ['forms.php#elenco'],
            'parametro con indirizzo codificato' => ['pagina.php?da=https%3A%2F%2Fx.it'],
        ];
    }

    /** @dataProvider indirizziDelSito */
    public function testAccettaLePagineDelSito(string $indirizzo): void
    {
        self::assertTrue(RitornoInterno::valido($indirizzo));
    }

    /** @return array<string, array{0: string}> */
    public static function indirizziDaRifiutare(): array
    {
        return [
            'vuoto' => [''],
            'altro sito con schema' => ['https://sito-cattivo.it/'],
            'altro sito, schema maiuscolo' => ['HTTPS://sito-cattivo.it/'],
            'codice' => ['javascript:alert(1)'],
            'codice con spazio davanti' => [' javascript:alert(1)'],
            'codice con tabulazione davanti' => ["\tjavascript:alert(1)"],
            'dati' => ['data:text/html,<script>alert(1)</script>'],
            'stesso schema, altro sito' => ['//sito-cattivo.it/'],
            'barra e barra rovesciata' => ['/\\sito-cattivo.it/'],
            'due barre rovesciate' => ['\\\\sito-cattivo.it/'],
            'barra rovesciata davanti' => ['\\/sito-cattivo.it/'],
            'tabulazione davanti alle barre' => ["\t//sito-cattivo.it/"],
            'a capo dentro' => ["form.php\nLocation: x"],
            'carattere di controllo' => ["form.php\x00"],
        ];
    }

    /** @dataProvider indirizziDaRifiutare */
    public function testRifiutaTuttoIlResto(string $indirizzo): void
    {
        self::assertFalse(RitornoInterno::valido($indirizzo));
    }
}
