<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * Prova emetti() DAVVERO, non solo i valori che la risposta si porta dietro: ogni caso lancia un piccolo script con php-cgi (che stampa
 * le intestazioni prima del corpo, come farebbe Apache) e legge codice, intestazioni e corpo dall'uscita. Senza php-cgi i test si saltano.
 * Dentro PHPUnit non si può chiamare emetti(): butta tutti i buffer, compresi quelli di PHPUnit, e header() dopo l'output dà un avviso.
 */
final class RispostaEmettiTest extends TestCase
{
    private const PAGINA = '<!doctype html><html><head><title>t</title></head><body><p>Ciao</p></body></html>';

    /** @var list<string> */
    private array $daTogliere = [];

    protected function tearDown(): void
    {
        foreach ($this->daTogliere as $f) {
            is_file($f) && unlink($f);
        }
    }

    /**
     * Esegue il codice PHP (con l'autoload del portale già caricato) in php-cgi.
     *
     * @return array{codice: int, intestazioni: array<string, string>, corpo: string}
     */
    private function esegui(string $codice): array
    {
        $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php-cgi.exe' : 'php-cgi');
        if (!is_file($cgi)) {
            self::markTestSkipped('php-cgi non trovato accanto a ' . PHP_BINARY);
        }
        $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'emetti_' . bin2hex(random_bytes(4)) . '.php';
        $this->daTogliere[] = $script;
        file_put_contents($script, "<?php\nrequire " . var_export(RADICE_SITO . '/src/autoload.php', true) . ";\n" . $codice . "\n");

        // Con queste variabili php-cgi si comporta da vero CGI (intestazioni, poi corpo); senza, si comporta da riga di comando e non le manda
        $ambiente = array_merge(getenv(), ['REDIRECT_STATUS' => '1', 'REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => $script, 'GATEWAY_INTERFACE' => 'CGI/1.1']);
        $p = proc_open([$cgi, '-d', 'cgi.force_redirect=0', '-d', 'display_errors=0', '-d', 'log_errors=0'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, null, $ambiente);
        self::assertIsResource($p);
        $uscita = (string) stream_get_contents($pipe[1]);
        fclose($pipe[1]);
        fclose($pipe[2]);
        proc_close($p);

        [$testa, $corpo] = array_pad(preg_split('/\r?\n\r?\n/', $uscita, 2) ?: [], 2, '');
        $intestazioni = [];
        $stato = 200;
        foreach (preg_split('/\r?\n/', (string) $testa) ?: [] as $riga) {
            if (!str_contains($riga, ':')) {
                continue;
            }
            [$nome, $valore] = array_map('trim', explode(':', $riga, 2));
            if (strtolower($nome) === 'status') {
                $stato = (int) $valore;
            } else {
                $intestazioni[strtolower($nome)] = $valore;
            }
        }

        return ['codice' => $stato, 'intestazioni' => $intestazioni, 'corpo' => $corpo];
    }

    public function testJsonConCodiceEIntestazioniExtra(): void
    {
        $r = $this->esegui('\App\Http\Risposta::json(["errore" => "Città"], 429)->conIntestazione("X-Robots-Tag", "noindex")->emetti();');

        self::assertSame(429, $r['codice']);
        self::assertSame('application/json; charset=utf-8', $r['intestazioni']['content-type']);
        self::assertSame('noindex', $r['intestazioni']['x-robots-tag']);
        self::assertSame('{"errore":"Città"}', $r['corpo']);
    }

    public function testLeRisposteNonHtmlButtanoTuttiIBuffer(): void
    {
        $r = $this->esegui('ob_start(); echo "sporco"; ob_start(); echo "ancora"; \App\Http\Risposta::json(["a" => 1])->emetti();');

        self::assertSame('{"a":1}', $r['corpo'], 'niente avvisi o spazi prima del JSON, a qualunque profondità');
        self::assertSame(200, $r['codice']);
    }

    public function testUnaPaginaHtmlNonButtaIlBufferECosiLaFasciaDiProvaEntra(): void
    {
        $r = $this->esegui('ob_start(static fn (string $o): string => \App\Core\FasciaProva::inietta($o, "", headers_list()));'
            . '\App\Http\Risposta::html(' . var_export(self::PAGINA, true) . ')->emetti();');

        self::assertStringContainsString('id="fascia-ambiente-prova"', $r['corpo']);
        self::assertStringContainsString('<p>Ciao</p>', $r['corpo']);
        self::assertStringStartsWith('text/html', $r['intestazioni']['content-type']);
    }

    /** La regola 1: le intestazioni partono PRIMA del corpo. Qui il buffer resta aperto (html() non lo butta) ma il tipo è JSON. */
    public function testLeIntestazioniPartonoPrimaDelCorpoQuindiIlJsonNonRiceveLaFascia(): void
    {
        $corpo = json_encode(['pagina' => self::PAGINA]);
        $r = $this->esegui('ob_start(static fn (string $o): string => \App\Core\FasciaProva::inietta($o, "", headers_list()));'
            . '\App\Http\Risposta::html(' . var_export($corpo, true) . ')->conIntestazione("Content-Type", "application/json; charset=utf-8")->emetti();');

        self::assertSame($corpo, $r['corpo'], 'FasciaProva ha visto il Content-Type in headers_list() e non ha toccato il corpo');
        self::assertStringNotContainsString('fascia-ambiente-prova', $r['corpo']);
    }

    public function testIlJsonNonRiceveLaFasciaNemmenoConIlBufferDellaFasciaAperto(): void
    {
        $corpo = json_encode(['pagina' => self::PAGINA]);
        $r = $this->esegui('ob_start(static fn (string $o): string => \App\Core\FasciaProva::inietta($o, "", headers_list()));'
            . '\App\Http\Risposta::json(["pagina" => ' . var_export(self::PAGINA, true) . '])->emetti();');

        self::assertSame($corpo, $r['corpo']);
    }

    public function testFileDaCancellareDopoLInvio(): void
    {
        $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'invio_' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($f, "a;b\n1;2\n");
        $this->daTogliere[] = $f;

        $r = $this->esegui('\App\Http\Risposta::file(' . var_export($f, true) . ', "dati.csv", cancella: true)->emetti();');

        self::assertSame("a;b\n1;2\n", $r['corpo']);
        self::assertSame('8', $r['intestazioni']['content-length']);
        self::assertSame('attachment; filename="dati.csv"', $r['intestazioni']['content-disposition']);
        self::assertSame('nosniff', $r['intestazioni']['x-content-type-options']);
        self::assertFileDoesNotExist($f, 'il file temporaneo si toglie dopo l\'invio');
    }

    public function testFileDaLasciareResta(): void
    {
        $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'resta_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($f, '%PDF');
        $this->daTogliere[] = $f;

        $r = $this->esegui('\App\Http\Risposta::file(' . var_export($f, true) . ')->emetti();');

        self::assertSame('%PDF', $r['corpo']);
        self::assertSame('application/pdf', $r['intestazioni']['content-type']);
        self::assertArrayNotHasKey('content-disposition', $r['intestazioni']);
        self::assertFileExists($f);
    }

    public function testRimandoSenzaCorpo(): void
    {
        $r = $this->esegui('echo "x"; \App\Http\Risposta::vai("scuole.php?p_id=3")->emetti();');

        self::assertSame(303, $r['codice']);
        self::assertSame('scuole.php?p_id=3', $r['intestazioni']['location']);
        self::assertSame('', $r['corpo']);
    }

    /** Il Content-Type che PHP aggiunge da sé (default_mimetype) non dipende da Risposta: qui conta codice e corpo vuoto. */
    public function testRispostaSenzaCorpo204(): void
    {
        $r = $this->esegui('ob_start(); echo "sporco"; \App\Http\Risposta::vuota()->emetti();');

        self::assertSame(204, $r['codice']);
        self::assertSame('', $r['corpo'], 'anche quello che era già stato stampato sparisce');
    }

    /**
     * Il difetto latente della Fase 1: la pagina di errore col layout si accodava a quello che era già stato stampato. Con un buffer
     * aperto che contiene del testo, né la negata() senza layout né quella con layout devono lasciarlo comparire; vale per tutti e tre i
     * costruttori di errore (403, 404, 500/503): la regola o vale sempre o non vale.
     */
    public function testUnErroreConOSenzaLayoutScartaIlTestoGiaStampato(): void
    {
        $layout = 'static fn (string $m): string => "<html><body><div class=\"alert\">" . $m . "</div></body></html>"';
        $casi = [
            'negata' => ['"Non puoi."', 403, 'Non puoi.', ''],
            'nonTrovata' => ['"Non c\'è."', 404, 'Non c', ''],
            'erroreServer' => ['"Errore del programma."', 500, 'Errore del programma.', ''],
            'erroreServer503' => ['"Manutenzione."', 503, 'Manutenzione.', ', 503'],
        ];
        foreach (['senza layout' => '', 'con layout' => $layout] as $nome => $layoutCodice) {
            foreach ($casi as $metodo => [$messaggio, $codice, $atteso, $codiceExtra]) {
                $costruttore = str_starts_with($metodo, 'erroreServer') ? 'erroreServer' : $metodo;
                // erroreServer(messaggio, codice, layout): con il layout e senza codice si passa 500, perché il layout è il terzo argomento
                $argomenti = $costruttore === 'erroreServer'
                    ? $messaggio . ($layoutCodice !== '' ? ($codiceExtra !== '' ? $codiceExtra : ', 500') . ', ' . $layoutCodice : $codiceExtra)
                    : $messaggio . ($layoutCodice !== '' ? ', ' . $layoutCodice : '');
                $r = $this->esegui('ob_start(); echo "MEZZA-PAGINA-GIA-STAMPATA"; ob_start(); echo "ANCORA-PIU-SOTTO"; \App\Http\Risposta::' . $costruttore . '(' . $argomenti . ')->emetti();');

                self::assertStringNotContainsString('MEZZA-PAGINA', $r['corpo'], "$metodo $nome");
                self::assertStringNotContainsString('ANCORA-PIU-SOTTO', $r['corpo'], "$metodo $nome");
                self::assertSame($codice, $r['codice'], "$metodo $nome");
                self::assertStringContainsString($atteso, $r['corpo'], "$metodo $nome");
            }
        }
    }

    public function testContenutoSenzaDispositionEIBufferButtati(): void
    {
        $r = $this->esegui('ob_start(); echo "sporco"; \App\Http\Risposta::contenuto("<rss/>", "application/rss+xml; charset=utf-8")->emetti();');

        self::assertSame('<rss/>', $r['corpo']);
        self::assertSame('application/rss+xml; charset=utf-8', $r['intestazioni']['content-type']);
        self::assertArrayNotHasKey('content-disposition', $r['intestazioni']);
    }

    /** nega_accesso() e csrf_verify() passano da qui: stesso codice, stesso testo, e la mezza pagina già scritta non resta. */
    public function testRispostaHttpDiAuthDaUnSoloMessaggio(): void
    {
        foreach (['negaAccesso', 'csrfNonValido'] as $metodo) {
            $r = $this->esegui('ob_start(); echo "meta pagina"; \App\Auth\RispostaHttp::' . $metodo . '();');

            self::assertSame(403, $r['codice'], $metodo);
            self::assertSame(\App\Http\Risposta::MESSAGGIO_NEGATO, $r['corpo'], $metodo);
            self::assertStringStartsWith('text/plain', $r['intestazioni']['content-type']);
        }
    }
}
