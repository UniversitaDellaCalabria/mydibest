<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Ambiente;
use App\Core\FasciaProva;
use PHPUnit\Framework\TestCase;

/** Ambiente di prova (AMBIENTE=prova nel .env): ogni regola, e la prova che senza AMBIENTE non cambia niente. */
final class AmbienteTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ambiente_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    private function env(string $testo): string
    {
        $f = $this->dir . '/.env';
        file_put_contents($f, $testo);

        return $f;
    }

    // ---- lettura del .env --------------------------------------------------------------------------------------------------

    public function testLaCopiaDiProvaSiAccendeSoloConAmbienteProva(): void
    {
        self::assertFalse(Ambiente::daFile($this->env("DB_NAME=x\n"))->inProva());
        self::assertFalse(Ambiente::daFile($this->dir . '/non-esiste')->inProva());
        self::assertFalse(Ambiente::daFile($this->env("AMBIENTE=produzione\n"))->inProva());
        self::assertFalse(Ambiente::daFile($this->env("AMBIENTE=\n"))->inProva());
        self::assertFalse(Ambiente::daFile($this->env("# AMBIENTE=prova\n"))->inProva());
        self::assertTrue(Ambiente::daFile($this->env("AMBIENTE=prova\n"))->inProva());
        self::assertTrue(Ambiente::daFile($this->env("DB_PASS=a;b!c\nAMBIENTE=\"Prova\"\n"))->inProva());
    }

    public function testEmailECronDelloEnv(): void
    {
        $a = Ambiente::daFile($this->env("AMBIENTE=prova\nEMAIL_PROVA=prove@unical.it\nCRON_PROVA=1\n"));
        self::assertSame('prove@unical.it', $a->emailProva());
        self::assertTrue($a->cronProva());
        $b = Ambiente::daFile($this->env("AMBIENTE=prova\nEMAIL_PROVA=non-una-email\n"));
        self::assertSame('', $b->emailProva());
        self::assertFalse($b->cronProva());
    }

    // ---- senza AMBIENTE non cambia niente ----------------------------------------------------------------------------------------

    public function testSenzaAmbienteTuttoRestaComeOggi(): void
    {
        foreach ([new Ambiente(), Ambiente::daFile($this->env("EMAIL_PROVA=prove@unical.it\nCRON_PROVA=0\n"))] as $a) {
            self::assertFalse($a->inProva());
            self::assertNull($a->nomeSessione(), 'sessione: il nome resta quello di PHP');
            self::assertSame('dibest_uscito', $a->nomeCookieUscito());
            self::assertNull($a->cronVietato());
            self::assertNull($a->titulusVietato('https://titulus-unical.cineca.it/titulus_ws/services/Titulus4?wsdl'));
            self::assertNull($a->nasVietato('/mnt/nas_nuovo/portale_didattica/pratiche'));
            self::assertSame(
                ['a' => 'anna@x.it', 'cc' => ['c@x.it'], 'ccn' => ['n@x.it'], 'oggetto' => 'Ciao', 'corpo' => '<p>Testo</p>'],
                $a->riscriviEmail('anna@x.it', 'Ciao', '<p>Testo</p>', ['c@x.it'], ['n@x.it'])
            );
        }
        self::assertFalse(Ambiente::corrente()->inProva(), 'senza impostare nulla l\'ambiente di questa richiesta è il sito vero');
    }

    // ---- sessione e cookie ---------------------------------------------------------------------------------------------------------

    public function testInProvaSessioneECookieHannoNomiDiversi(): void
    {
        $a = new Ambiente(true);
        self::assertSame('MYDIBEST_PP', $a->nomeSessione());
        self::assertSame('dibest_uscito_pp', $a->nomeCookieUscito());
        self::assertNotSame((new Ambiente())->nomeCookieUscito(), $a->nomeCookieUscito());
    }

    // ---- email ----------------------------------------------------------------------------------------------------------------------------------

    public function testLEmailVaSoloAEmailProvaConOggettoETestoCambiati(): void
    {
        $a = new Ambiente(true, 'prove@unical.it');
        $r = $a->riscriviEmail('anna@x.it', 'Conferma', '<p>Testo</p>', ['capo@x.it'], ['nascosto@x.it']);

        self::assertNotNull($r);
        self::assertSame('prove@unical.it', $r['a']);
        self::assertSame([], $r['cc'], 'CC azzerati');
        self::assertSame([], $r['ccn'], 'CCN azzerati');
        self::assertStringStartsWith('[PROVA] Conferma', $r['oggetto']);
        self::assertStringStartsWith('<p', $r['corpo']);
        self::assertStringContainsString('Destinatari originali:</strong> A: anna@x.it | CC: capo@x.it | CCN: nascosto@x.it', $r['corpo']);
        self::assertStringEndsWith('<p>Testo</p>', $r['corpo']);
    }

    public function testIDestinatariOriginaliNonRompononoLHtml(): void
    {
        $r = (new Ambiente(true, 'prove@unical.it'))->riscriviEmail('"<b>x</b>" <a@x.it>', 'O', 'C');

        self::assertNotNull($r);
        self::assertStringNotContainsString('<b>x</b>', $r['corpo']);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $r['corpo']);
    }

    public function testSenzaEmailProvaNonSiInviaNulla(): void
    {
        self::assertNull((new Ambiente(true))->riscriviEmail('anna@x.it', 'O', 'C'));
        self::assertNull((new Ambiente(true, 'non-valida'))->riscriviEmail('anna@x.it', 'O', 'C'));
    }

    // ---- Titulus ------------------------------------------------------------------------------------------------------------------------

    public function testTitulusSoloInPreproduzione(): void
    {
        $a = new Ambiente(true, 'p@x.it');
        self::assertNull($a->titulusVietato('https://titulus-unical.pp.cineca.it/titulus_ws/services/Titulus4?wsdl'));
        $motivo = $a->titulusVietato('https://titulus-unical.cineca.it/titulus_ws/services/Titulus4?wsdl');
        self::assertNotNull($motivo);
        self::assertStringContainsString('.pp.cineca.it', $motivo);
        self::assertNotNull($a->titulusVietato(''), 'senza indirizzo non si invia');
        self::assertNull((new Ambiente())->titulusVietato('https://titulus-unical.cineca.it/x'), 'fuori dalla prova nessun limite');
    }

    // ---- processi automatici -------------------------------------------------------------------------------------------------

    public function testICronEscono(): void
    {
        self::assertNotNull((new Ambiente(true))->cronVietato());
        self::assertStringContainsString('CRON_PROVA=1', (string) (new Ambiente(true))->cronVietato());
        self::assertNull((new Ambiente(true, '', true))->cronVietato(), 'con CRON_PROVA=1 partono');
    }

    // ---- NAS ----------------------------------------------------------------------------------------------------------------------------

    public function testNientePercorsiSulNas(): void
    {
        $a = new Ambiente(true);
        self::assertNotNull($a->nasVietato('/mnt/nas_nuovo/portale_didattica/pratiche'));
        self::assertNotNull($a->nasVietato('/mnt'));
        self::assertNotNull($a->nasVietato('\\mnt\\nas'));
        self::assertNull($a->nasVietato('/mntx/altro'));
        self::assertNull($a->nasVietato($this->dir . '/uploads/pratiche'));
        self::assertStringContainsString('/mnt/nas/x', (string) $a->nasVietato('/mnt/nas/x'));
    }

    public function testUnCollegamentoCheDuceSulNasSiRiconosce(): void
    {
        $radice = $this->dir . '/sito';
        mkdir($radice . '/uploads', 0777, true);
        self::assertSame([], Ambiente::cartelleSulNas($radice), 'cartelle normali: via libera');

        // il collegamento simbolico può non essere permesso (Windows senza privilegi): in quel caso la prova non si fa
        if (!@symlink('/mnt/nas_nuovo/portale_didattica/pratiche', $radice . '/uploads/pratiche')) {
            self::markTestSkipped('collegamenti simbolici non permessi su questo sistema');
        }
        self::assertSame(['uploads/pratiche'], Ambiente::cartelleSulNas($radice));
        unlink($radice . '/uploads/pratiche');
        rmdir($radice . '/uploads');
        rmdir($radice);
    }

    // ---- fascia arancione -------------------------------------------------------------------------------------------------------

    public function testLaFasciaSiMetteDopoIlBodyDiUnaPaginaCompleta(): void
    {
        $pagina = "<!DOCTYPE html>\n<html><head><title>T</title></head><body class=\"x\"><nav>Menu</nav><p>Ciao</p></body></html>";
        $r = FasciaProva::inietta($pagina, 'prove@unical.it');

        self::assertStringContainsString('<body class="x"><div id="fascia-ambiente-prova"', $r);
        self::assertStringContainsString('AMBIENTE DI PROVA — i dati e le email non sono reali', $r);
        self::assertStringContainsString('email solo a prove@unical.it', $r);
        self::assertStringContainsString('var(--bs-warning,#fd7e14)', $r, 'usa la variabile di colore del portale');
        self::assertStringContainsString('</div><nav>Menu</nav>', $r, 'il menu resta com\'è, subito dopo la fascia');
        self::assertSame(1, substr_count($r, 'fascia-ambiente-prova"'));
        self::assertSame($r, FasciaProva::inietta($r), 'se c\'è già non si raddoppia');
    }

    public function testLaFasciaNonToccaJsonCsvEFrammenti(): void
    {
        self::assertSame('{"html":"<body>"}', FasciaProva::inietta('{"html":"<body>"}'));
        self::assertSame('<div><body></div>', FasciaProva::inietta('<div><body></div>'), 'frammento senza doctype né html');
        $pagina = '<!doctype html><html><body>x</body></html>';
        self::assertSame($pagina, FasciaProva::inietta($pagina, '', ['Content-Type: application/json']));
        self::assertSame($pagina, FasciaProva::inietta($pagina, '', ['Content-Type: text/plain; charset=utf-8']));
        self::assertNotSame($pagina, FasciaProva::inietta($pagina, '', ['Content-Type: text/html; charset=utf-8']));
        self::assertSame('%PDF-1.4 <body>', FasciaProva::inietta('%PDF-1.4 <body>'));
    }

    // ---- avvio vero (inc/ambiente.php) in un processo a parte ----------------------------------------------------------------

    /** @return array<string, string> */
    private function avvio(string $envTesto): array
    {
        $env = $this->env($envTesto);
        $radice = dirname(__DIR__, 3);
        $script = $this->dir . '/avvio.php';
        file_put_contents($script, '<?php define("FILE_ENV", ' . var_export($env, true) . ');'
            . 'require ' . var_export($radice . '/inc/ambiente.php', true) . ';'
            . 'echo json_encode(["prova" => AMBIENTE_PROVA, "sessione" => session_name(), "uscito" => COOKIE_USCITO]);');
        $uscita = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($script) . ' 2>&1');
        @unlink($script);
        $j = json_decode($uscita, true);
        self::assertIsArray($j, 'avvio non riuscito: ' . $uscita);

        return $j;
    }

    /** Lancia consenti_esecuzione_cron() (quella vera di inc/sistema.php) da riga di comando e ritorna cosa scrive. */
    private function lanciaCron(Ambiente $ambiente): string
    {
        $radice = dirname(__DIR__, 3);
        $script = $this->dir . '/cron.php';
        file_put_contents($script, '<?php define("RADICE_SITO", ' . var_export($radice, true) . ');'
            . 'require ' . var_export($radice . '/src/autoload.php', true) . ';'
            . '\\App\\Core\\Ambiente::imposta(new \\App\\Core\\Ambiente(' . var_export($ambiente->inProva(), true) . ', "", ' . var_export($ambiente->cronProva(), true) . '));'
            . 'require ' . var_export($radice . '/inc/sistema.php', true) . ';'
            . 'consenti_esecuzione_cron([1]); echo "PARTITO";');
        $uscita = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 ' . escapeshellarg($script) . ' 2>&1');
        @unlink($script);

        return trim($uscita);
    }

    public function testIlCronEsceSubitoInProvaESenzaLaProvaParteComeSempre(): void
    {
        $uscito = $this->lanciaCron(new Ambiente(true));
        self::assertStringNotContainsString('PARTITO', $uscito);
        self::assertStringContainsString('CRON_PROVA=1', $uscito);

        self::assertSame('PARTITO', $this->lanciaCron(new Ambiente(true, '', true)), 'con CRON_PROVA=1 parte');
        self::assertSame('PARTITO', $this->lanciaCron(new Ambiente()), 'senza AMBIENTE parte come sempre');
    }

    public function testAvvioSenzaAmbienteNonCambiaNiente(): void
    {
        $r = $this->avvio("DB_NAME=x\n");

        self::assertFalse($r['prova']);
        self::assertSame('PHPSESSID', $r['sessione']);
        self::assertSame('dibest_uscito', $r['uscito']);
    }

    public function testAvvioInProvaCambiaSessioneECookie(): void
    {
        $r = $this->avvio("AMBIENTE=prova\nEMAIL_PROVA=prove@unical.it\n");

        self::assertTrue($r['prova']);
        self::assertSame('MYDIBEST_PP', $r['sessione']);
        self::assertSame('dibest_uscito_pp', $r['uscito']);
    }
}
