<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\Container;
use App\Http\Avvisi;
use App\Http\ControlloAccesso;
use App\Http\Nucleo;
use App\Http\Pagina;
use App\Http\ProtezioneCsrf;
use App\Http\Richiesta;
use App\Http\Risposta;
use App\Http\Rotta;
use App\Http\Vista;
use FilesystemIterator;
use LogicException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Una pagina che registra quante volte è stata costruita e chiamata, e restituisce un corpo riconoscibile. */
final class PaginaDiProva implements Pagina
{
    public static int $costruita = 0;
    public static int $chiamata = 0;
    public static ?Richiesta $ultima = null;

    public function __construct()
    {
        ++self::$costruita;
    }

    public function __invoke(Richiesta $r): Risposta
    {
        ++self::$chiamata;
        self::$ultima = $r;

        return Risposta::html('<p>pagina di prova</p>');
    }
}

/** Una pagina vestita con il layout (finto) del pannello: il layout mostra l'avviso se il Nucleo glielo dà. */
final class PaginaVestita implements Pagina
{
    public function __invoke(Richiesta $r): Risposta
    {
        return Risposta::html(Vista::pannello('Vestita', '<p>corpo</p>'));
    }
}

final class PaginaCheScoppia implements Pagina
{
    public function __invoke(Richiesta $r): Risposta
    {
        throw new RuntimeException('segreto della pagina: password=1234');
    }
}

/** Una classe che sta nella tabella ma non è una Pagina: errore di programma, non un 404. */
final class NonUnaPagina
{
}

/** Un controllo dell'accesso che risponde quello che gli si dice e ricorda cosa gli è stato chiesto. */
final class AccessoFinto implements ControlloAccesso
{
    /** @var list<string> */
    public array $chiesti = [];

    public function __construct(private readonly bool|Risposta|\Throwable $esito)
    {
    }

    public function verifica(Rotta $rotta, Richiesta $richiesta): bool|Risposta
    {
        $this->chiesti[] = $rotta->percorso;
        if ($this->esito instanceof \Throwable) {
            throw $this->esito;
        }

        return $this->esito;
    }
}

/** Un controllo del token che dice sì solo a «buono», e ricorda quante volte gli è stato chiesto. */
final class CsrfFinto implements ProtezioneCsrf
{
    public int $chiesto = 0;

    public function valido(mixed $ricevuto): bool
    {
        ++$this->chiesto;

        return $ricevuto === 'buono';
    }
}

/** Un solo messaggio in attesa: html() lo dà e lo toglie (come App\Auth\Flash). */
final class AvvisiFinti implements Avvisi
{
    public int $letture = 0;

    public function __construct(public string $inAttesa = '')
    {
    }

    public function html(): string
    {
        ++$this->letture;
        $html = $this->inAttesa;
        $this->inAttesa = '';

        return $html;
    }
}

/**
 * Il Nucleo (App\Http\Nucleo): trova la rotta, chiede l'accesso PRIMA di costruire la pagina, la chiama, restituisce la risposta.
 * Senza database né sessione: il Container è quello vero (con l'autowiring), le pagine e il controllo dell'accesso sono finti.
 */
final class NucleoTest extends TestCase
{
    private const LAYOUT = [
        'sito' => ['header.php', 'footer.php'],
        'pannello' => ['admin/admin_header.php', 'admin/admin_footer.php'],
    ];

    private string $radice = '';
    private ?string $radicePrima = null;
    private array $layoutPrima = [];
    private string $erroriPrima = '';
    private CsrfFinto $csrf;
    private AvvisiFinti $avvisi;

    protected function setUp(): void
    {
        PaginaDiProva::$costruita = PaginaDiProva::$chiamata = 0;
        PaginaDiProva::$ultima = null;
        $this->csrf = new CsrfFinto();
        $this->avvisi = new AvvisiFinti();
        // il registro degli errori del test: il Nucleo ci scrive (deve), e non si vuole il rumore nell'uscita di PHPUnit
        $this->erroriPrima = (string) ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nucleo_errori_' . bin2hex(random_bytes(3)) . '.log');
        // layout finti per il 403 vestito
        $this->radice = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nucleo_' . bin2hex(random_bytes(4));
        mkdir($this->radice . '/admin', 0777, true);
        // il layout del pannello mostra l'avviso che il Nucleo gli dà (o «[legge-da-sé]» se nessuno glielo dà: la strada vecchia)
        file_put_contents($this->radice . '/admin/admin_header.php', '<?php echo "<div id=\"pannello\">" . (isset($leggi_avviso) ? $leggi_avviso() : "[legge-da-sé]"); ?>');
        file_put_contents($this->radice . '/admin/admin_footer.php', '</div>');
        file_put_contents($this->radice . '/header.php', '');
        file_put_contents($this->radice . '/footer.php', '');
        $this->radicePrima = Vista::usaRadice($this->radice);
        $this->layoutPrima = Vista::layout(self::LAYOUT);
    }

    protected function tearDown(): void
    {
        Vista::usaRadice($this->radicePrima);
        if ($this->layoutPrima !== []) {
            Vista::layout($this->layoutPrima);
        }
        @unlink((string) ini_get('error_log'));
        ini_set('error_log', $this->erroriPrima);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->radice, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->radice);
    }

    /** @return list<Rotta> */
    private static function rotte(): array
    {
        return [
            new Rotta('chi/siamo', PaginaDiProva::class, 'Chi siamo', 'chi_siamo.php', ['vecchio/chi-siamo', 'chi_siamo_vecchio']),
            new Rotta('admin/archivio', PaginaDiProva::class, 'Archivio', 'admin/archivio.php'),
            new Rotta('scoppia/qui', PaginaCheScoppia::class, 'Scoppia', 'scoppia.php'),
            new Rotta('non/pagina', NonUnaPagina::class, 'Non una pagina', 'non_pagina.php'),
            new Rotta('webhook/x', PaginaDiProva::class, 'Webhook', 'webhook.php', senzaCsrf: 'Webhook di prova: non ha la sessione e si autentica con una firma'),
            new Rotta('admin/vestita', PaginaVestita::class, 'Vestita', 'admin/vestita.php'),
        ];
    }

    private function nucleo(bool|Risposta|\Throwable $esito = true, ?AccessoFinto &$accesso = null): Nucleo
    {
        $accesso = new AccessoFinto($esito);

        return new Nucleo(new Container(), self::rotte(), $accesso, $this->csrf, $this->avvisi, $this->radice);
    }

    private static function richiesta(string $percorso, string $metodo = 'GET'): Richiesta
    {
        return Richiesta::finta($metodo, '/sito/' . ltrim($percorso, '/'), [], [], '/sito');
    }

    // ── La rotta ───────────────────────────────────────────────────────────────

    public function testRottaTrovata(): void
    {
        $r = $this->nucleo()->servi(self::richiesta('chi/siamo'));

        self::assertSame(200, $r->codice());
        self::assertSame('<p>pagina di prova</p>', $r->corpo());
        self::assertSame(1, PaginaDiProva::$chiamata);
        self::assertSame('chi/siamo', PaginaDiProva::$ultima?->percorsoNelSito(), 'la pagina riceve la richiesta');
    }

    public function testRottaSconosciutaEUn404ENonCostruisceNiente(): void
    {
        $accesso = null;
        $r = $this->nucleo(true, $accesso)->servi(self::richiesta('non/esiste'));

        self::assertSame(404, $r->codice());
        self::assertSame(Risposta::MESSAGGIO_NON_TROVATO, $r->corpo());
        self::assertSame([], $accesso->chiesti, 'nessuna rotta, nessuna domanda sull\'accesso');
        self::assertSame(0, PaginaDiProva::$costruita);
    }

    public function testPercorsiStrani(): void
    {
        $n = $this->nucleo();

        foreach (['', '/', '..', '../chi/siamo', 'chi/../chi/siamo', 'chi/siamo/extra', 'CHI/SIAMO'] as $p) {
            self::assertSame(404, $n->servi(self::richiesta($p))->codice(), "«{$p}» non è una rotta");
        }
        self::assertSame(200, $n->servi(self::richiesta('chi/siamo/'))->codice(), 'una barra finale non cambia la rotta');
        self::assertSame(200, $n->servi(self::richiesta('chi//siamo'))->codice());
    }

    public function testLAliasVecchioArrivaAllaPaginaGiusta(): void
    {
        $n = $this->nucleo();

        foreach (['vecchio/chi-siamo', 'chi_siamo_vecchio', 'chi_siamo.php', 'chi_siamo', 'chi/siamo'] as $indirizzo) {
            $r = $n->servi(self::richiesta($indirizzo));

            self::assertSame(200, $r->codice(), $indirizzo);
            self::assertSame('chi/siamo', $n->trova($indirizzo)?->percorso, $indirizzo);
        }
        self::assertSame('admin/archivio', $n->trova('admin/archivio.php')?->percorso, 'il file vecchio, con «.php»');
        self::assertSame('admin/archivio', $n->trova('admin/archivio')?->percorso, 'e senza');
    }

    public function testLaRottaSiTrovaAncheDalFile(): void
    {
        $n = $this->nucleo();

        self::assertSame('chi/siamo', $n->trovaPerFile('chi_siamo.php')?->percorso);
        self::assertSame('admin/archivio', $n->trovaPerFile('admin/archivio.php')?->percorso);
        self::assertSame('admin/archivio', $n->trovaPerFile($this->radice . '/admin/archivio.php')?->percorso, 'il percorso intero (__FILE__)');
        self::assertSame('admin/archivio', $n->trovaPerFile(str_replace('/', '\\', $this->radice) . '\\admin\\archivio.php')?->percorso, 'con le barre di Windows');
        self::assertNull($n->trovaPerFile('chi_siamo_vecchio.php'), 'il guscio è il file della rotta, non un alias');
        self::assertNull($n->trovaPerFile('admin/altro.php'));
    }

    public function testLaRottaDiUnaRichiestaSiTrovaSenzaCostruireIlNucleo(): void
    {
        self::assertSame('chi/siamo', Nucleo::rottaDi(self::rotte(), self::richiesta('vecchio/chi-siamo'))?->percorso);
        self::assertNull(Nucleo::rottaDi(self::rotte(), self::richiesta('non/esiste')));
        self::assertNull(Nucleo::rottaDi(self::rotte(), self::richiesta('')));
        self::assertNull(Nucleo::rottaDi([], self::richiesta('chi/siamo')));
    }

    public function testUnIndirizzoDiDueRotteEUnErrore(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('è di due rotte');
        new Nucleo(new Container(), [
            new Rotta('a/uno', PaginaDiProva::class, 'Uno', 'uno.php', ['comune/x']),
            new Rotta('a/due', PaginaDiProva::class, 'Due', 'due.php', ['comune/x']),
        ], new AccessoFinto(true), $this->csrf, $this->avvisi, $this->radice);
    }

    public function testUnFileDiDueRotteEUnErrore(): void
    {
        $this->expectException(LogicException::class);
        new Nucleo(new Container(), [
            new Rotta('a/uno', PaginaDiProva::class, 'Uno', 'stesso.php'),
            new Rotta('a/due', PaginaDiProva::class, 'Due', 'stesso.php'),
        ], new AccessoFinto(true), $this->csrf, $this->avvisi, $this->radice);
    }

    // ── L'accesso ──────────────────────────────────────────────────────────────

    public function testPermessoMancanteSenzaLayoutEUn403NudoEIlTestoDiSempre(): void
    {
        $accesso = null;
        $r = $this->nucleo(false, $accesso)->servi(self::richiesta('chi/siamo'));

        self::assertSame(403, $r->codice());
        self::assertSame(Risposta::MESSAGGIO_NEGATO, $r->corpo());
        self::assertStringStartsWith('text/plain', (string) $r->intestazione('Content-Type'));
        self::assertTrue($r->svuotaBuffer(), 'una risposta di errore scarta quello che c\'era prima');
        self::assertSame(['chi/siamo'], $accesso->chiesti);
    }

    /** Il Nucleo non sa vestire niente: un `false` è sempre il 403 nudo, anche per una rotta del pannello. Una pagina vestita la restituisce il controllo. */
    public function testUnFalsoESempreUn403NudoAncheSeLaRottaEDelPannello(): void
    {
        $r = $this->nucleo(false)->servi(self::richiesta('admin/archivio'));

        self::assertSame(403, $r->codice());
        self::assertSame(Risposta::MESSAGGIO_NEGATO, $r->corpo());
        self::assertStringStartsWith('text/plain', (string) $r->intestazione('Content-Type'));
        self::assertStringNotContainsString('<div id="pannello">', $r->corpo(), 'niente layout: lo sceglie il controllo, non il Nucleo');
        self::assertTrue($r->svuotaBuffer());
        self::assertSame(0, $this->avvisi->letture, 'un 403 nudo non consuma il messaggio in attesa');
    }

    public function testSenzaPermessoLaPaginaNonSiCostruisceNeSiChiama(): void
    {
        foreach (['chi/siamo', 'admin/archivio'] as $rotta) {
            $this->nucleo(false)->servi(self::richiesta($rotta));
        }

        self::assertSame(0, PaginaDiProva::$costruita, 'la logica della pagina non gira mai per chi non ha il permesso');
        self::assertSame(0, PaginaDiProva::$chiamata);
    }

    public function testIlControlloDecideUnaRispostaDiversaDalFalso(): void
    {
        $vai = Risposta::vai('login.php?da=qui');
        $r = $this->nucleo($vai)->servi(self::richiesta('admin/archivio'));

        self::assertSame($vai, $r, 'il rimando scelto dal portale passa così com\'è');
        self::assertSame(0, PaginaDiProva::$costruita);
    }

    public function testChiNonRiesceASapereSePuoEntrareNonEntra(): void
    {
        $r = $this->nucleo(new RuntimeException('database spento: user=root'))->servi(self::richiesta('chi/siamo'));

        self::assertSame(500, $r->codice());
        self::assertSame(Nucleo::MESSAGGIO_ERRORE, $r->corpo());
        self::assertStringNotContainsString('database', $r->corpo(), 'il dettaglio va nel registro, non al visitatore');
        self::assertSame(0, PaginaDiProva::$costruita);
        self::assertStringContainsString('database spento', (string) file_get_contents((string) ini_get('error_log')));
    }

    // ── Il token CSRF (Fase 5) ─────────────────────────────────────────────────

    private static function post(string $percorso, array $dati): Richiesta
    {
        return Richiesta::finta('POST', '/sito/' . ltrim($percorso, '/'), [], $dati, '/sito');
    }

    /** Il cuore: senza token la pagina NON viene costruita (il contatore del costruttore e della chiamata restano a zero), e la risposta è il 403. */
    public function testUnPostSenzaTokenEUn403ELaPaginaNonSiCostruisce(): void
    {
        $r = $this->nucleo()->servi(self::post('chi/siamo', ['nome' => 'x']));

        self::assertSame(403, $r->codice());
        self::assertSame(Risposta::MESSAGGIO_NEGATO, $r->corpo());
        self::assertSame(1, $this->csrf->chiesto, 'il token è stato verificato');
        self::assertSame(0, PaginaDiProva::$costruita, 'la pagina non è stata nemmeno costruita');
        self::assertSame(0, PaginaDiProva::$chiamata);
    }

    public function testUnPostConTokenSbagliatoOVuotoOFattoDiUnaListaEUn403(): void
    {
        foreach ([['csrf_token' => 'sbagliato'], ['csrf_token' => ''], ['csrf_token' => ['buono']]] as $dati) {
            self::assertSame(403, $this->nucleo()->servi(self::post('chi/siamo', $dati))->codice());
        }
        self::assertSame(0, PaginaDiProva::$costruita);
    }

    public function testUnPostConTokenBuonoPassaELaPaginaLoVede(): void
    {
        $r = $this->nucleo()->servi(self::post('chi/siamo', ['csrf_token' => 'buono', 'nome' => 'x']));

        self::assertSame(200, $r->codice());
        self::assertSame(1, PaginaDiProva::$chiamata);
        self::assertSame('x', PaginaDiProva::$ultima?->post('nome'));
    }

    public function testUnaRichiestaInLetturaNonChiedeIlToken(): void
    {
        self::assertSame(200, $this->nucleo()->servi(self::richiesta('chi/siamo'))->codice());
        self::assertSame(0, $this->csrf->chiesto);
    }

    /** L'accesso viene prima del token: chi non può entrare riceve il suo rimando o il suo 403, non quello del token. */
    public function testPrimaLAccessoEPoiIlToken(): void
    {
        $vai = Risposta::vai('login.php');
        $r = $this->nucleo($vai)->servi(self::post('chi/siamo', []));

        self::assertSame($vai, $r);
        self::assertSame(0, $this->csrf->chiesto);
    }

    public function testUnaRottaCheLoDichiaraConIlSuoMotivoFaAMenoDelToken(): void
    {
        $r = $this->nucleo()->servi(self::post('webhook/x', ['evento' => 'pagato']));

        self::assertSame(200, $r->codice());
        self::assertSame(0, $this->csrf->chiesto);
        self::assertSame(1, PaginaDiProva::$chiamata);
    }

    // ── L'avviso dopo il salvataggio (Fase 5) ──────────────────────────────────

    public function testIlLayoutMostraIlMessaggioDelNucleoUnaVoltaSola(): void
    {
        $this->avvisi->inAttesa = '<div class="alert">Contatto salvato.</div>';
        $n = $this->nucleo();

        $prima = $n->servi(self::richiesta('admin/vestita'))->corpo();
        $dopo = $n->servi(self::richiesta('admin/vestita'))->corpo();

        self::assertStringContainsString('<div class="alert">Contatto salvato.</div>', $prima);
        self::assertStringNotContainsString('Contatto salvato.', $dopo, 'si vede una volta sola');
        self::assertStringNotContainsString('[legge-da-sé]', $prima, 'il layout non rilegge da sé: il messaggio gli arriva dal Nucleo');
    }

    /** Un rimando, un JSON o un file non mostrano niente: il messaggio deve restare per la pagina che viene dopo. */
    public function testUnaRispostaCheNonMostraIlLayoutNonConsumaIlMessaggio(): void
    {
        $this->avvisi->inAttesa = '<div>in attesa</div>';
        $n = $this->nucleo();

        $n->servi(self::richiesta('chi/siamo'));
        $n->servi(self::richiesta('non/esiste'));

        self::assertSame(0, $this->avvisi->letture);
        self::assertSame('<div>in attesa</div>', $this->avvisi->inAttesa);
        self::assertStringContainsString('in attesa', $n->servi(self::richiesta('admin/vestita'))->corpo());
    }

    /** Finita la pagina il Nucleo toglie ciò che aveva dato alla Vista: un'altra richiesta nello stesso processo non lo eredita. */
    public function testFinitaLaPaginaLaVistaNonHaPiuIlFornitoreDelNucleo(): void
    {
        $this->nucleo()->servi(self::richiesta('chi/siamo'));

        self::assertNull(Vista::avviso(null));
    }

    // ── La pagina ──────────────────────────────────────────────────────────────

    public function testUnaPaginaCheScoppiaEUn500ConIlDettaglioSoloNelRegistro(): void
    {
        $r = $this->nucleo()->servi(self::richiesta('scoppia/qui'));

        self::assertSame(500, $r->codice());
        self::assertTrue($r->svuotaBuffer(), 'il 500 sostituisce quello che la pagina aveva già stampato (stessa regola di 403 e 404)');
        self::assertStringNotContainsString('password', $r->corpo());
        $registro = (string) file_get_contents((string) ini_get('error_log'));
        self::assertStringContainsString('scoppia/qui', $registro);
        self::assertStringContainsString('password=1234', $registro);
    }

    public function testUnaClasseCheNonEUnaPaginaEUnErroreDiProgramma(): void
    {
        $r = $this->nucleo()->servi(self::richiesta('non/pagina'));

        self::assertSame(500, $r->codice());
        self::assertStringContainsString('non implementa', (string) file_get_contents((string) ini_get('error_log')));
    }

    public function testLOrdineEAccessoPoiPagina(): void
    {
        $accesso = null;
        $nucleo = $this->nucleo(true, $accesso);
        $nucleo->servi(self::richiesta('chi/siamo'));

        self::assertSame(['chi/siamo'], $accesso->chiesti);
        self::assertSame(1, PaginaDiProva::$costruita);
        self::assertSame(1, PaginaDiProva::$chiamata);
    }

    public function testRotteRestituisceLElencoDato(): void
    {
        self::assertCount(6, $this->nucleo()->rotte());
    }

    public function testIlNucleoNonUsaIsuperglobali(): void
    {
        $src = (string) file_get_contents(RADICE_SITO . '/src/Http/Nucleo.php');

        self::assertDoesNotMatchRegularExpression('/\$_(SERVER|GET|POST|SESSION|COOKIE|REQUEST|FILES)\b/', $src, 'i superglobali si leggono in Richiesta e da nessun\'altra parte');
    }
}
