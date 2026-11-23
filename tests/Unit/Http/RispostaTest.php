<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\FasciaProva;
use App\Http\Risposta;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RispostaTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'risposta_' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($this->file, "contenuto del file\n");
    }

    protected function tearDown(): void
    {
        is_file($this->file) && unlink($this->file);
    }

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const PAGINA = '<!doctype html><html><head><title>t</title></head><body><p>Ciao</p></body></html>';

    /**
     * Le 29 forme di risposta del portale (documentazione/nucleo_materiali.md, parte 3), una per riga, col costruttore che la copre.
     * Ogni riga: costruttore (riceve il percorso di un file di prova) · codice · intestazioni attese · buffer buttati?
     *
     * @return array<string, array{0: callable(string): Risposta, 1: int, 2: array<string, string>, 3: bool}>
     */
    public static function leForme(): array
    {
        $layout = static fn (string $m): string => '<!doctype html><html><body><div class="alert">' . htmlspecialchars($m) . '</div></body></html>';
        $html = ['Content-Type' => 'text/html; charset=utf-8'];
        $testo = ['Content-Type' => 'text/plain; charset=utf-8'];
        $json = ['Content-Type' => 'application/json; charset=utf-8'];
        $nosniff = ['X-Content-Type-Options' => 'nosniff'];

        return [
            '01 HTML a pagina intera · html()' => [fn () => Risposta::html(self::PAGINA), 200, $html, false],
            '02 HTML frammento · html()' => [fn () => Risposta::html('<div>frammento</div>'), 200, $html, false],
            '03 rimando 303 · vai()' => [fn () => Risposta::vai('scuole.php?p_id=3'), 303, ['Location' => 'scuole.php?p_id=3'], true],
            '04 rimando a mano, 301 · vai()' => [fn () => Risposta::vai('/guida.php', 301), 301, ['Location' => '/guida.php'], true],
            '05 JSON · json()' => [fn () => Risposta::json(['a' => 1])->conIntestazione('X-Robots-Tag', 'noindex'), 200, $json + ['X-Robots-Tag' => 'noindex'], true],
            '06 file temporaneo da cancellare · file(cancella)' => [fn (string $f) => Risposta::file($f, 'esporta.zip', cancella: true), 200,
                ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="esporta.zip"', 'Content-Length' => '19'] + $nosniff, true],
            '07 Excel xlsx dal disco · file()' => [fn (string $f) => Risposta::file($f, 'Elenco.xlsx', self::XLSX, cancella: true), 200,
                ['Content-Type' => self::XLSX, 'Content-Disposition' => 'attachment; filename="Elenco.xlsx"'], true],
            '08 Excel finto .xls in memoria · scaricabile()' => [fn () => Risposta::scaricabile('<table></table>', 'Risultati.xls', 'application/vnd.ms-excel; charset=utf-8')
                ->conIntestazione('Pragma', 'no-cache')->conIntestazione('Expires', '0'), 200,
                ['Content-Type' => 'application/vnd.ms-excel; charset=utf-8', 'Content-Disposition' => 'attachment; filename="Risultati.xls"', 'Pragma' => 'no-cache', 'Expires' => '0', 'Content-Length' => '15'], true],
            '09 Word docx, tipo dall\'estensione · file()' => [fn (string $f) => Risposta::file($f, 'Convenzione.docx'), 200,
                ['Content-Type' => self::DOCX, 'Content-Disposition' => 'attachment; filename="Convenzione.docx"'], true],
            '10 CSV · scaricabile()' => [fn () => Risposta::scaricabile("a;b\n1;2\n", 'dati.csv', 'text/csv; charset=utf-8'), 200,
                ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="dati.csv"', 'Content-Length' => '8'], true],
            '11 PDF inline · file(inline)' => [fn (string $f) => Risposta::file($f, 'anteprima.pdf', inline: true), 200,
                ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="anteprima.pdf"'], true],
            '12 PDF attachment · file()' => [fn (string $f) => Risposta::file($f, 'convenzione.pdf'), 200,
                ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="convenzione.pdf"'], true],
            '13 PDF senza Content-Disposition · file(nome null)' => [fn (string $f) => Risposta::file($f, null, 'application/pdf'), 200,
                ['Content-Type' => 'application/pdf'], true],
            '14 file dal disco con tipo ricavato · file()' => [fn (string $f) => Risposta::file($f, 'foto.PNG', inline: true), 200,
                ['Content-Type' => 'image/png', 'Content-Disposition' => 'inline; filename="foto.PNG"'], true],
            '15 ZIP · file()' => [fn (string $f) => Risposta::file($f, 'allegati.zip'), 200,
                ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="allegati.zip"'], true],
            '16 ICS · contenuto()' => [fn () => Risposta::contenuto("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", 'text/calendar; charset=utf-8'), 200,
                ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Length' => '32'], true],
            '17 XML / RSS / testo · contenuto()' => [fn () => Risposta::contenuto('<rss/>', 'application/rss+xml; charset=utf-8'), 200,
                ['Content-Type' => 'application/rss+xml; charset=utf-8', 'Content-Length' => '6'], true],
            '18 403 inline · negata()' => [fn () => Risposta::negata(), 403, $testo, true],
            '19 403 nel layout del pannello · negata(layout)' => [fn () => Risposta::negata('Non puoi.', $layout), 403, $html, true],
            '20 403 con messaggio a mano · negata(messaggio)' => [fn () => Risposta::negata('Non sei autorizzato.'), 403, $testo, true],
            '21 403 JSON · json(…, 403)' => [fn () => Risposta::json(['errore' => 'Accesso non consentito'], 403), 403, $json, true],
            '22 403 CSRF · negata()' => [fn () => Risposta::negata(), 403, $testo, true],
            '23 404 testo · nonTrovata(messaggio)' => [fn () => Risposta::nonTrovata('File non trovato.'), 404, $testo, true],
            '24 404 HTML con layout · nonTrovata(layout)' => [fn () => Risposta::nonTrovata('Pagina inesistente', $layout), 404, $html, true],
            '25 404 vuoto · nonTrovata(\'\')' => [fn () => Risposta::nonTrovata(''), 404, $testo, true],
            '26 204 senza corpo · vuota()' => [fn () => Risposta::vuota(), 204, [], true],
            '27 429 limite di richieste (JSON) · json(…, 429)' => [fn () => Risposta::json([], 429), 429, $json, true],
            '28 400/401 · json(…, 401)' => [fn () => Risposta::json(['errore' => 'Serve l\'accesso'], 401), 401, $json, true],
            '29 500/503 con testo · erroreServer(…, 503)' => [fn () => Risposta::erroreServer('Servizio non disponibile', 503), 503, $testo, true],
        ];
    }

    /**
     * @dataProvider leForme
     * @param callable(string): Risposta $costruisci
     * @param array<string, string>      $intestazioni
     */
    public function testOgniFormaHaCodiceEIntestazioniGiuste(callable $costruisci, int $codice, array $intestazioni, bool $svuotaBuffer): void
    {
        $r = $costruisci($this->file);

        self::assertSame($codice, $r->codice());
        foreach ($intestazioni as $nome => $valore) {
            self::assertSame($valore, $r->intestazione($nome), "intestazione $nome");
        }
        self::assertSame($svuotaBuffer, $r->svuotaBuffer(), 'i buffer si buttano per tutto tranne l\'HTML (la fascia di prova passa da lì)');
        if ($r->intestazione('Content-Type') !== null && $codice !== 204) {
            self::assertStringStartsWith('Content-Type: ', $r->intestazioniDaMandare()[0], 'il Content-Type, quando c\'è, parte per primo');
        }
    }

    public function testSonoEsattamenteVentinoveForme(): void
    {
        self::assertCount(29, self::leForme());
        self::assertSame(range(1, 29), array_map(static fn (string $k): int => (int) $k, array_keys(self::leForme())));
    }

    public function testCsrfENegazioneHannoLoStessoTestoUnico(): void
    {
        self::assertSame(Risposta::MESSAGGIO_NEGATO, Risposta::negata()->corpo());
        self::assertStringContainsString('Accesso negato', Risposta::MESSAGGIO_NEGATO);
        self::assertStringContainsString('sessione è scaduta', Risposta::MESSAGGIO_NEGATO, 'copre anche il token CSRF scaduto');
    }

    public function testIlLayoutRiceveIlMessaggioEDiventaLaPagina(): void
    {
        $r = Risposta::negata('Solo per amministratori', static fn (string $m): string => '<html><body>[' . $m . ']</body></html>');

        self::assertSame('<html><body>[Solo per amministratori]</body></html>', $r->corpo());
        self::assertSame(403, $r->codice());
    }

    public function testErroreServerEUn500ConLeStesseDueFormeDiNegata(): void
    {
        $nudo = Risposta::erroreServer('Errore del programma.');
        self::assertSame(500, $nudo->codice());
        self::assertSame('Errore del programma.', $nudo->corpo());
        self::assertSame('text/plain; charset=utf-8', $nudo->intestazione('Content-Type'));
        self::assertTrue($nudo->svuotaBuffer(), 'un errore sostituisce la pagina, non la continua');

        $vestito = Risposta::erroreServer('Manutenzione', 503, static fn (string $m): string => '<html><body>[' . $m . ']</body></html>');
        self::assertSame(503, $vestito->codice());
        self::assertSame('<html><body>[Manutenzione]</body></html>', $vestito->corpo());
        self::assertSame('text/html; charset=utf-8', $vestito->intestazione('Content-Type'));
        self::assertTrue($vestito->svuotaBuffer(), 'anche con il layout');
    }

    public function testErroreServerAccettaSoloICodiciCinqueCento(): void
    {
        foreach ([200, 403, 404, 499, 600] as $codice) {
            try {
                Risposta::erroreServer('x', $codice);
                self::fail("accettato il codice $codice");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(599, Risposta::erroreServer('x', 599)->codice());
    }

    public function testNonTrovataConMessaggioPredefinito(): void
    {
        self::assertSame(Risposta::MESSAGGIO_NON_TROVATO, Risposta::nonTrovata()->corpo());
        self::assertSame('', Risposta::nonTrovata('')->corpo());
    }

    public function testIl204NonHaContentTypeNeContentLength(): void
    {
        $r = Risposta::html('', 204)->conIntestazione('Content-Length', '0');

        self::assertSame([], $r->intestazioniDaMandare());
        self::assertSame('', $r->corpo());
    }

    public function testVuotaNonHaCorpoNeContentTypeEButtaIBuffer(): void
    {
        $r = Risposta::vuota();

        self::assertSame(204, $r->codice());
        self::assertSame([], $r->intestazioni(), 'nemmeno un Content-Type: non c\'è niente da dichiarare');
        self::assertSame([], $r->intestazioniDaMandare());
        self::assertSame('', $r->corpo());
        self::assertTrue($r->svuotaBuffer());
        self::assertSame(205, Risposta::vuota(205)->codice());
    }

    public function testContenutoEInlineENonHaContentDisposition(): void
    {
        $r = Risposta::contenuto('<rss/>', 'application/rss+xml');

        self::assertNull($r->intestazione('Content-Disposition'));
        self::assertSame('6', $r->intestazione('Content-Length'));
        self::assertSame('nosniff', $r->intestazione('X-Content-Type-Options'));
        self::assertSame('<rss/>', $r->corpo());
    }

    /** Il calendario che si salva con un nome (calendario_ics.php, genera_ics.php, risorsa_ics.php) resta scaricabile(): contenuto() non ha nome. */
    public function testUnIcsDaSalvareConUnNomeRestaScaricabile(): void
    {
        $r = Risposta::scaricabile("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", 'evento.ics', 'text/calendar; charset=utf-8');

        self::assertSame('attachment; filename="evento.ics"', $r->intestazione('Content-Disposition'));
    }

    public function testUnErroreScartaIlBufferSiaConSiaSenzaLayout(): void
    {
        $layout = static fn (string $m): string => '<html><body>' . $m . '</body></html>';

        self::assertTrue(Risposta::negata()->svuotaBuffer());
        self::assertTrue(Risposta::negata('x', $layout)->svuotaBuffer(), 'con il layout la pagina di errore SOSTITUISCE quello che c\'era, non si accoda');
        self::assertTrue(Risposta::nonTrovata('x', $layout)->svuotaBuffer());
        self::assertSame('text/html; charset=utf-8', Risposta::negata('x', $layout)->intestazione('Content-Type'));
    }

    public function testJsonNonEscapaUnicodeEUnByteNonValidoNonSvuotaLaRisposta(): void
    {
        self::assertSame('{"nome":"Città"}', Risposta::json(['nome' => 'Città'])->corpo());
        self::assertSame('[]', Risposta::json([])->corpo());
        self::assertSame('{}', Risposta::json(new stdClass())->corpo(), 'come new stdClass() di cerca_scuole.php');
        self::assertSame("[\"a\u{FFFD}(b\"]", Risposta::json(["a\xC3\x28b"])->corpo(), 'il byte rotto diventa «�», il resto resta');
    }

    public function testFileMandaLaLunghezzaEIlTipoSconosciutoEGenerico(): void
    {
        $r = Risposta::file($this->file, 'dati.xyz');

        self::assertSame((string) filesize($this->file), $r->intestazione('Content-Length'));
        self::assertSame('application/octet-stream', $r->intestazione('Content-Type'));
        self::assertSame($this->file, $r->percorsoFile());
        self::assertFalse($r->cancellaDopoInvio());
        self::assertSame('', $r->corpo(), 'un file dal disco non si carica in memoria');
        self::assertTrue(Risposta::file($this->file, 'a.pdf', cancella: true)->cancellaDopoInvio());
    }

    public function testFileSenzaNomeRicavaIlTipoDalPercorso(): void
    {
        $pdf = $this->file . '.pdf';
        copy($this->file, $pdf);
        try {
            self::assertSame('application/pdf', Risposta::file($pdf)->intestazione('Content-Type'));
            self::assertNull(Risposta::file($pdf)->intestazione('Content-Disposition'));
        } finally {
            unlink($pdf);
        }
    }

    public function testIlNomeDelFileSiPulisceComeFaDownload(): void
    {
        $r = Risposta::scaricabile('x', "a\"b/c\r\nd.pdf", 'application/pdf');

        self::assertSame('attachment; filename="a_b_c_d.pdf"', $r->intestazione('Content-Disposition'));
    }

    public function testFileCheNonEsisteEUnErrore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Risposta::file($this->file . '.manca', 'a.pdf');
    }

    public function testVaiPulisceGliSpaziERifiutaCioCheSpezzaLIntestazione(): void
    {
        self::assertSame('a%20b.php', Risposta::vai(' a b.php ')->intestazione('Location'));
        $this->expectException(InvalidArgumentException::class);
        Risposta::vai("/a.php\r\nSet-Cookie: x=1");
    }

    public function testVaiRifiutaUnCodiceCheNonERimando(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Risposta::vai('/', 200);
    }

    public function testConIntestazioneSostituisceSenzaDistinguereLeMaiuscoleENonModificaLOriginale(): void
    {
        $a = Risposta::json([]);
        $b = $a->conIntestazione('content-type', 'text/plain')->conIntestazione('X-Robots-Tag', 'noindex');

        self::assertSame('application/json; charset=utf-8', $a->intestazione('Content-Type'));
        self::assertNull($a->intestazione('X-Robots-Tag'));
        self::assertSame(['content-type: text/plain', 'X-Robots-Tag: noindex'], $b->intestazioniDaMandare(), 'la posizione resta, il nome è quello nuovo');
    }

    public function testConIntestazioneRifiutaValoriENomiNonValidi(): void
    {
        $r = Risposta::json([]);
        foreach ([["X-A", "v\nX-B: 1"], ["X A", 'v'], ['', 'v']] as [$nome, $valore]) {
            try {
                $r->conIntestazione($nome, $valore);
                self::fail("accettata l'intestazione «$nome»");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * Vincolo dell'ambiente di prova: FasciaProva::inietta() guarda headers_list() e l'inizio del corpo. Un JSON che contiene una pagina
     * HTML nei primi byte ha la fascia dentro se il Content-Type non è ancora partito; con le intestazioni che emetti() produce no.
     */
    public function testLeIntestazioniDiEmettiTengonoLaFasciaFuoriDalJson(): void
    {
        $json = Risposta::json(['pagina' => self::PAGINA, 'ok' => true]);

        self::assertStringContainsString('fascia-ambiente-prova', FasciaProva::inietta($json->corpo(), '', []), 'controllo: senza Content-Type la fascia entra nel JSON');
        $dopo = FasciaProva::inietta($json->corpo(), '', $json->intestazioniDaMandare());
        self::assertSame($json->corpo(), $dopo, 'con le intestazioni di emetti() la fascia non entra');
        self::assertNotNull(json_decode($dopo), 'e il JSON resta JSON');
    }

    public function testLeIntestazioniTengonoLaFasciaFuoriDaUnPdfEDaUnFile(): void
    {
        $pdf = Risposta::scaricabile("%PDF-1.4\n<html><body>dentro</body></html>", null, 'application/pdf');
        $file = Risposta::file($this->file, 'x.pdf');
        $testo = Risposta::negata('<html><body>x</body></html>');

        foreach ([$pdf, $testo] as $r) {
            self::assertSame($r->corpo(), FasciaProva::inietta($r->corpo(), '', $r->intestazioniDaMandare()));
        }
        self::assertStringStartsWith('Content-Type: application/pdf', $file->intestazioniDaMandare()[0]);
    }

    public function testUnaPaginaHtmlRiceveLaFasciaPerchéTextHtmlNonLaBlocca(): void
    {
        $r = Risposta::html(self::PAGINA);

        self::assertStringContainsString('fascia-ambiente-prova', FasciaProva::inietta($r->corpo(), '', $r->intestazioniDaMandare()));
    }
}
