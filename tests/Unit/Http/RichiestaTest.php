<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Richiesta;
use PHPUnit\Framework\TestCase;

final class RichiestaTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverPrima = [];
    private array $getPrima = [];
    private array $postPrima = [];
    private array $filesPrima = [];

    protected function setUp(): void
    {
        $this->serverPrima = $_SERVER;
        $this->getPrima = $_GET;
        $this->postPrima = $_POST;
        $this->filesPrima = $_FILES;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverPrima;
        $_GET = $this->getPrima;
        $_POST = $this->postPrima;
        $_FILES = $this->filesPrima;
    }

    public function testFintaLeggeQueryEPostConIDefault(): void
    {
        $r = Richiesta::finta('post', '/cerca_scuole.php', ['q' => 'rossi'], ['nome' => 'Anna']);

        self::assertSame('POST', $r->metodo(), 'il metodo è sempre maiuscolo');
        self::assertSame('/cerca_scuole.php', $r->percorso());
        self::assertSame('rossi', $r->query('q'));
        self::assertNull($r->query('manca'));
        self::assertSame('x', $r->query('manca', 'x'));
        self::assertSame('Anna', $r->post('nome'));
        self::assertSame('', $r->post('manca', ''));
        self::assertTrue($r->eUnPost());
        self::assertNull($r->file('allegato'));
        self::assertNull($r->intestazione('Accept'));
    }

    public function testUnaGetNonEUnPost(): void
    {
        self::assertFalse(Richiesta::finta('GET', '/')->eUnPost());
    }

    public function testDaGlobaliLeggeIDatiDelServer(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';
        $_SERVER['REQUEST_URI'] = '/mydibest/cerca_scuole.php?q=rossi&x=1';
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'it-IT';
        $_SERVER['HTTP_X_RICHIESTO_DA'] = 'prova';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_GET = ['q' => 'rossi', 'x' => '1'];
        $_POST = ['nome' => 'Anna'];
        $_FILES = ['allegato' => ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/php1', 'error' => 0, 'size' => 10]];

        $r = Richiesta::daGlobali();

        self::assertSame('POST', $r->metodo());
        self::assertSame('/mydibest/cerca_scuole.php', $r->percorso(), 'senza ?parametri');
        self::assertSame('rossi', $r->query('q'));
        self::assertSame('Anna', $r->post('nome'));
        self::assertSame('a.pdf', $r->file('allegato')['name'] ?? null);
        self::assertNull($r->file('altro'));
        self::assertSame('it-IT', $r->intestazione('Accept-Language'));
        self::assertSame('prova', $r->intestazione('x-richiesto-da'), 'il nome non distingue maiuscole e minuscole');
        self::assertSame('application/x-www-form-urlencoded', $r->intestazione('Content-Type'));
        self::assertTrue($r->eUnPost());
    }

    public function testDaGlobaliSenzaServerEUnaGetAllaRadice(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

        $r = Richiesta::daGlobali();

        self::assertSame('GET', $r->metodo());
        self::assertSame('/', $r->percorso());
    }

    public function testLaRichiestaESoloUnaCopia(): void
    {
        $_GET = ['q' => 'prima'];
        $r = Richiesta::daGlobali();
        $_GET['q'] = 'dopo';

        self::assertSame('prima', $r->query('q'), 'i superglobali si leggono una volta sola');
    }

    public function testUnParametroArrayArrivaComeArray(): void
    {
        $r = Richiesta::finta('GET', '/', ['q' => ['a', 'b']]);

        self::assertSame(['a', 'b'], $r->query('q'), 'chi lo usa decide: la Richiesta non lo converte in testo');
    }
}
