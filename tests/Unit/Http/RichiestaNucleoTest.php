<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Richiesta;
use PHPUnit\Framework\TestCase;

/** Quello che Richiesta ha aggiunto per il Nucleo: il percorso dentro il sito, i cookie, tutti i parametri e i dati del modulo. */
final class RichiestaNucleoTest extends TestCase
{
    public function testIlPercorsoNelSitoTogliaLaCartellaDelloScriptDIngresso(): void
    {
        self::assertSame('pannello/contatti', Richiesta::finta('GET', '/sito/pannello/contatti', cartella: '/sito')->percorsoNelSito());
        self::assertSame('pannello/contatti', Richiesta::finta('GET', '/sito/pannello/contatti/', cartella: '/sito/')->percorsoNelSito());
        self::assertSame('pannello/contatti', Richiesta::finta('GET', '/pannello/contatti')->percorsoNelSito(), 'sito alla radice del server');
        self::assertSame('', Richiesta::finta('GET', '/sito', cartella: '/sito')->percorsoNelSito());
        self::assertSame('sito2/x', Richiesta::finta('GET', '/sito2/x', cartella: '/sito')->percorsoNelSito(), 'una cartella che comincia allo stesso modo non è la cartella del sito');
    }

    public function testDaGlobaliLeggeLaCartellaDaScriptNameEICookieDaCookie(): void
    {
        $server = $_SERVER;
        $cookie = $_COOKIE;
        try {
            $_SERVER['REQUEST_URI'] = '/mia/cartella/pannello/x?a=1';
            $_SERVER['SCRIPT_NAME'] = '/mia/cartella/nucleo.php';
            $_COOKIE = ['uscito' => '1', 'array' => ['no']];
            $r = Richiesta::daGlobali();

            self::assertSame('pannello/x', $r->percorsoNelSito());
            self::assertSame('1', $r->cookie('uscito'));
            self::assertNull($r->cookie('array'), 'un cookie che non è una stringa non c\'è');
            self::assertNull($r->cookie('manca'));

            $_SERVER['SCRIPT_NAME'] = '/nucleo.php';
            $_SERVER['REQUEST_URI'] = '/pannello/x';
            self::assertSame('pannello/x', Richiesta::daGlobali()->percorsoNelSito(), 'script alla radice del server');
        } finally {
            $_SERVER = $server;
            $_COOKIE = $cookie;
        }
    }

    public function testParametriEDatiDelModuloSiLeggonoInteri(): void
    {
        $r = Richiesta::finta('POST', '/x', ['p_id' => '3', 'modifica' => '0'], ['salva' => '2', 'titolo' => 'T'], cookie: ['a' => 'b']);

        self::assertSame(['p_id' => '3', 'modifica' => '0'], $r->parametri());
        self::assertSame(['salva' => '2', 'titolo' => 'T'], $r->datiModulo());
        self::assertSame('b', $r->cookie('a'));
    }
}
