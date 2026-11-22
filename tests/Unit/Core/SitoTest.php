<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Sito;
use PHPUnit\Framework\TestCase;

final class SitoTest extends TestCase
{
    private array $serverPrima = [];
    private string $base;

    protected function setUp(): void
    {
        $this->serverPrima = $_SERVER;
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sito_test_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/portale/admin', 0777, true);
        mkdir($this->base . '/htdocs', 0777, true);
        touch($this->base . '/portale/index.php');
        touch($this->base . '/portale/admin/eventi.php');
        foreach (['HTTPS', 'HTTP_HOST', 'DOCUMENT_ROOT', 'SCRIPT_NAME', 'SCRIPT_FILENAME'] as $k) {
            unset($_SERVER[$k]);
        }
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverPrima;
        is_file($this->base . '/portale/admin/eventi.php') && unlink($this->base . '/portale/admin/eventi.php');
        is_file($this->base . '/portale/index.php') && unlink($this->base . '/portale/index.php');
        is_dir($this->base . '/portale/admin') && rmdir($this->base . '/portale/admin');
        is_dir($this->base . '/portale') && rmdir($this->base . '/portale');
        is_dir($this->base . '/htdocs') && rmdir($this->base . '/htdocs');
        is_dir($this->base) && rmdir($this->base);
    }

    public function testRadiceDentroLaDocumentRoot(): void
    {
        mkdir($this->base . '/htdocs/mydibest', 0777, true);
        $_SERVER['DOCUMENT_ROOT'] = $this->base . '/htdocs';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $sito = new Sito($this->base . '/htdocs/mydibest', null, false);

        self::assertSame('http://localhost/mydibest', $sito->urlBase());
        rmdir($this->base . '/htdocs/mydibest');
    }

    public function testRadiceFuoriDallaDocumentRootUsaIlPercorsoDellaPagina(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->base . '/htdocs';
        $_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
        $_SERVER['SCRIPT_NAME'] = '/mydibest/index.php';
        $_SERVER['SCRIPT_FILENAME'] = $this->base . '/portale/index.php';

        self::assertSame('http://127.0.0.1:8080/mydibest', (new Sito($this->base . '/portale', null, false))->urlBase());
    }

    public function testPaginaInAdminDaLaStessaRadice(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->base . '/htdocs';
        $_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SCRIPT_NAME'] = '/mydibest/admin/eventi.php';
        $_SERVER['SCRIPT_FILENAME'] = $this->base . '/portale/admin/eventi.php';

        self::assertSame('https://127.0.0.1:8080/mydibest', (new Sito($this->base . '/portale', null, false))->urlBase());
    }

    public function testSenzaIndicazioniUtiliRipiegaSuMydibest(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->base . '/htdocs';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SCRIPT_NAME'] = '/altro/percorso.php'; // non corrisponde al file
        $_SERVER['SCRIPT_FILENAME'] = $this->base . '/portale/index.php';

        self::assertSame('http://localhost/mydibest', (new Sito($this->base . '/portale', null, false))->urlBase());
    }

    public function testHostNonAmmessoDaIlNomeAmmesso(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->base . '/htdocs';
        $_SERVER['HTTP_HOST'] = 'sito-falso.example';
        $_SERVER['SCRIPT_NAME'] = '/mydibest/index.php';
        $_SERVER['SCRIPT_FILENAME'] = $this->base . '/portale/index.php';

        self::assertSame('https://dibest2.unical.it/mydibest', (new Sito($this->base . '/portale', null, false))->urlBase());
        // Anche con l'elenco del .env: il nome che manda chi fa la richiesta non è tra quelli ammessi
        self::assertSame('https://dibest2.unical.it/mydibest', (new Sito($this->base . '/portale', null, false, 'dibest2.unical.it'))->urlBase());
    }

    public function testUrlSitoDelEnvVinceAncheDalWebEIgnoraLHostDellaRichiesta(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->base . '/htdocs';
        $_SERVER['HTTP_HOST'] = 'sito-falso.example';
        $_SERVER['SCRIPT_NAME'] = '/mydibest/admin/eventi.php';
        $_SERVER['SCRIPT_FILENAME'] = $this->base . '/portale/admin/eventi.php';

        self::assertSame('https://dibest2.unical.it/mydibest_pp', (new Sito($this->base . '/portale', 'https://dibest2.unical.it/mydibest_pp/', false))->urlBase());
    }

    public function testSenzaUrlSitoUsaLHostAmmessoConLaPorta(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->base . '/htdocs';
        $_SERVER['HTTP_HOST'] = 'Esempio.Test:8080';
        $_SERVER['SCRIPT_NAME'] = '/mydibest/index.php';
        $_SERVER['SCRIPT_FILENAME'] = $this->base . '/portale/index.php';

        self::assertSame('http://Esempio.Test:8080/mydibest', (new Sito($this->base . '/portale', null, false, 'localhost, esempio.test'))->urlBase());
        // Con un URL_SITO non valido si ripiega sull'host ammesso
        self::assertSame('http://Esempio.Test:8080/mydibest', (new Sito($this->base . '/portale', 'non-un-indirizzo', false, 'esempio.test'))->urlBase());
    }

    public function testRigaDiComandoConUrlSitoUsaQuelloDelEnv(): void
    {
        self::assertSame('https://dibest2.unical.it/mydibest', (new Sito($this->base . '/portale', 'https://dibest2.unical.it/mydibest/', true))->urlBase());
    }

    public function testRigaDiComandoSenzaUrlSitoUsaIlNomeDiRiserva(): void
    {
        self::assertSame('https://dibest2.unical.it/mydibest', (new Sito($this->base . '/portale', null, true))->urlBase());
        self::assertSame('https://dibest2.unical.it/mydibest', (new Sito($this->base . '/portale', 'non-un-indirizzo', true))->urlBase());
    }
}
