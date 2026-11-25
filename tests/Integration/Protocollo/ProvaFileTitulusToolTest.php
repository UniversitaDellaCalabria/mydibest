<?php

declare(strict_types=1);

namespace Tests\Integration\Protocollo;

use App\Infrastructure\Sicurezza\Cifratura;
use Tests\Integration\DatabaseDiProva;

/**
 * strumenti/prova_file_titulus.php provato davvero: si avvia il finto servizio SOAP (tests/Support/titulus_finto/router.php, server integrato di PHP)
 * e lo strumento gira in un sottoprocesso, da una cartella a sé con la sola copia del connettore (come il pacchetto che va sul server) e un
 * finto portale che NON conosce il codice nuovo (il suo autoload carica solo App\). Così si prova anche che non serve caricare nulla nel portale.
 */
final class ProvaFileTitulusToolTest extends DatabaseDiProva
{
    private const PASSWORD = 'segreta';

    /** @var resource|null */
    private static $server = null;
    private static int $porta = 0;
    private static string $cartella = '';
    private static string $strumento = '';
    private static string $sito = '';
    private static string $radice = '';

    protected function tabelle(): array
    {
        return ['titulus_config' => self::tabellaVera('titulus_config'), 'titulus_invii' => self::tabellaVera('titulus_invii')];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$radice = dirname(__DIR__, 3);
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($s === false) {
            return;
        }
        self::$porta = (int) substr((string) stream_socket_get_name($s, false), strrpos((string) stream_socket_get_name($s, false), ':') + 1);
        fclose($s);

        $cmd = [PHP_BINARY, ...self::estensioni(), '-S', '127.0.0.1:' . self::$porta, self::$radice . '/tests/Support/titulus_finto/router.php'];
        self::$server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/titulus_finto_out.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/titulus_finto_err.log', 'w']], $pipe);
        for ($i = 0; $i < 50; $i++) {
            $f = @fsockopen('127.0.0.1', self::$porta, $e, $m, 0.2);
            if ($f !== false) {
                fclose($f);
                break;
            }
            usleep(100000);
        }

        // La cartella dello strumento, come il pacchetto: lo strumento + src del connettore + le due classi che contano le firme
        self::$cartella = sys_get_temp_dir() . '/prova_titulus_' . getmypid();
        self::copia(self::$radice . '/protocollo/php-titulus-utility/src', self::$cartella . '/php-titulus-utility/src');
        self::copia(self::$radice . '/src/Infrastructure/Pdf', self::$cartella . '/pdf', ['FirmePades.php', 'VerificaFirme.php']);
        copy(self::$radice . '/strumenti/prova_file_titulus.php', self::$cartella . '/prova_file_titulus.php');
        self::$strumento = self::$cartella . '/prova_file_titulus.php';

        // Un finto portale che NON ha il codice nuovo: il suo autoload carica App\ dal progetto, ma per Unical\Titulus\ punta a una cartella di
        // file «avvelenati» (lanciano un'eccezione se qualcuno li carica). Se lo strumento usasse il connettore del portale invece del suo, si romperebbe.
        self::$sito = self::$cartella . '/portale';
        mkdir(self::$sito . '/src', 0777, true);
        mkdir(self::$sito . '/connettore_vecchio', 0777, true);
        foreach (glob(self::$radice . '/protocollo/php-titulus-utility/src/*.php') ?: [] as $f) {
            file_put_contents(self::$sito . '/connettore_vecchio/' . basename($f), "<?php throw new \\LogicException('Caricato il connettore del portale invece di quello dello strumento: ' . __FILE__);\n");
        }
        $codice = <<<'PHPCODE'
<?php
require_once %CSP%;
spl_autoload_register(static function (string $c): void {
    foreach (['App\\' => %SRC%, 'Unical\\Titulus\\' => %VECCHIO%] as $prefisso => $dir) {
        if (strncmp($c, $prefisso, strlen($prefisso)) === 0) {
            $f = $dir . str_replace('\\', '/', substr($c, strlen($prefisso))) . '.php';
            if (is_file($f)) {
                require $f;
            }

            return;
        }
    }
});
PHPCODE;
        file_put_contents(self::$sito . '/src/autoload.php', strtr($codice, [
            '%CSP%' => var_export(self::$radice . '/inc/csp.php', true),
            '%SRC%' => var_export(self::$radice . '/src/', true),
            '%VECCHIO%' => var_export(self::$sito . '/connettore_vecchio/', true),
        ]));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$cartella !== '' && is_dir(self::$cartella)) {
            self::cancella(self::$cartella);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$server === null || self::$porta === 0) {
            self::fail('Il finto servizio di Titulus non è partito.');
        }
        $env = "DB_HOST=" . (getenv('PROVE_DB_HOST') ?: '127.0.0.1') . "\nDB_USER=" . (getenv('PROVE_DB_USER') ?: 'root') . "\nDB_PASS=" . (getenv('PROVE_DB_PASS') ?: '')
            . "\nDB_NAME=" . (getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit') . "\nCIFRATURA_CHIAVE=una-frase-lunga-almeno-sedici\n";
        file_put_contents(self::$sito . '/.env', $env);
        $this->db->esegui('DELETE FROM titulus_config');
        $this->db->esegui('INSERT INTO titulus_config (id, attivo, url_ws, username, aoo, password_cifrata) VALUES (1, 0, ?, ?, ?, ?)', [$this->indirizzo(), 'prova', '', (new Cifratura('una-frase-lunga-almeno-sedici'))->cifra(self::PASSWORD)]);
    }

    // ---- aiuti ---------------------------------------------------------------------------------------------------------------------

    /** @return list<string> */
    private static function estensioni(): array
    {
        // Il sottoprocesso parte con l'ini di sempre (non con i -d di PHPUnit): si guarda se lì «soap» c'è già, altrimenti lo si accende
        static $senzaFlag = null;
        $senzaFlag ??= trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r "echo (int) extension_loaded(chr(115) . chr(111) . chr(97) . chr(112));"')) === '1';

        return $senzaFlag ? [] : ['-d', 'extension=soap'];
    }

    private function indirizzo(string $coda = ''): string
    {
        return 'http://127.0.0.1:' . self::$porta . '/ws/Titulus4?wsdl' . $coda;
    }

    /**
     * @param list<string> $argomenti
     * @param array<string, string> $env
     * @return array{int, string} codice di uscita, tutto ciò che lo strumento ha scritto
     */
    private function strumento(array $argomenti, string $stdin = '', array $env = []): array
    {
        $cmd = [PHP_BINARY, ...self::estensioni(), self::$strumento, ...$argomenti];
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe, null, $env + [
            'TITULUS_PASSWORD' => self::PASSWORD, 'SystemRoot' => (string) getenv('SystemRoot'), 'PATH' => (string) getenv('PATH'),
        ]);
        fwrite($pipe[0], $stdin);
        fclose($pipe[0]);
        $uscita = (string) stream_get_contents($pipe[1]) . (string) stream_get_contents($pipe[2]);
        $codice = proc_close($p);

        return [$codice, $uscita];
    }

    /** Lo strumento senza --sito: indirizzo e utente da stdin, password dalla variabile d'ambiente. */
    private function senzaSito(array $argomenti, string $url = ''): array
    {
        return $this->strumento($argomenti + [99 => '--produzione'], ($url !== '' ? $url : $this->indirizzo()) . "\nprova\n");
    }

    private static function pdfFinto(string $segno): string
    {
        return "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% $segno\n%%EOF\n";
    }

    private static function copia(string $da, string $a, ?array $solo = null): void
    {
        mkdir($a, 0777, true);
        foreach (scandir($da) ?: [] as $f) {
            if (is_file($da . '/' . $f) && str_ends_with($f, '.php') && ($solo === null || in_array($f, $solo, true))) {
                copy($da . '/' . $f, $a . '/' . $f);
            }
        }
    }

    private static function cancella(string $d): void
    {
        foreach (scandir($d) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir($d . '/' . $f) ? self::cancella($d . '/' . $f) : @unlink($d . '/' . $f);
        }
        @rmdir($d);
    }

    // ---- prove ---------------------------------------------------------------------------------------------------------------------

    public function testUnIndirizzoDiProduzioneSiRifiutaSalvoPerEsteso(): void
    {
        foreach (['https://titulus-unical.cineca.it/ws/Titulus4?wsdl', 'https://titulus.pp.cineca.it.esempio.com/ws?wsdl', 'https://esempio.com/titulus.pp.cineca.it/ws?wsdl', 'https://evilpp.cineca.it/ws?wsdl'] as $url) {
            [$codice, $out] = $this->strumento(['--nrecord=100'], "$url\nprova\n");
            self::assertSame(1, $codice, $url);
            self::assertStringContainsString('non è un indirizzo di pre-produzione', $out, $url);
            self::assertStringContainsString('--produzione', $out);
            self::assertStringNotContainsString('Operazioni del servizio', $out, 'non si è nemmeno collegato');
        }
    }

    public function testConIlSitoUnIndirizzoDiProduzioneSiRifiutaSalvoPerEsteso(): void
    {
        $this->db->esegui('UPDATE titulus_config SET url_ws = ?', ['https://titulus-unical.cineca.it/ws/Titulus4?wsdl']);

        [$codice, $out] = $this->strumento(['--sito=' . self::$sito, '--nrecord=100']);

        self::assertSame(1, $codice);
        self::assertStringContainsString('non è un indirizzo di pre-produzione', $out);
    }

    public function testSenzaDocumentoNonFaNulla(): void
    {
        [$codice, $out] = $this->strumento([]);
        self::assertSame(2, $codice);
        self::assertStringContainsString('Uso:', $out);
        [$codice] = $this->strumento(['--nrecord=1 2']);
        self::assertSame(2, $codice, 'un numero con spazi non è valido');
        [$codice, $out] = $this->strumento(['--boh=1', '--nrecord=1']);
        self::assertSame(2, $codice);
        self::assertStringContainsString('Opzione sconosciuta', $out);
    }

    public function testVersioniAnnidateUnFileELaPasswordNonSiVede(): void
    {
        [$codice, $out] = $this->senzaSito(['--nrecord=100']);

        self::assertSame(0, $codice, $out);
        self::assertStringContainsString('loadDocument     sì', $out);
        self::assertStringContainsString('getAttachment    sì', $out);
        self::assertStringContainsString('[1] convenzione.pdf', $out);
        self::assertStringContainsString('PDF: sì', $out);
        self::assertStringContainsString(hash('sha256', self::pdfFinto('versione 3 stampigliata')), $out, 'vale la versione più annidata (v3)');
        self::assertStringNotContainsString(hash('sha256', self::pdfFinto('versione 1')), $out);
        self::assertStringContainsString('getDocumentURL: https://titulus.finto.pp.cineca.it/titulus/doc?id=100', $out);
        self::assertStringNotContainsString(self::PASSWORD, $out);
        self::assertStringNotContainsString(base64_encode('prova:' . self::PASSWORD), $out);
        self::assertStringNotContainsString('<doc', $out, 'niente XML a schermo');
        self::assertStringNotContainsString('<response', $out);
    }

    public function testNrecordENumeroSiProvanoTuttiEDue(): void
    {
        [$codice, $out] = $this->senzaSito(['--nrecord=100', '--numero=2026-UCALPRG-0001234']);

        self::assertSame(0, $codice, $out);
        self::assertStringContainsString('numero di record 100', $out);
        self::assertStringContainsString('numero di protocollo 2026-UCALPRG-0001234', $out);
        self::assertSame(2, substr_count($out, hash('sha256', self::pdfFinto('versione 3 stampigliata'))));
    }

    public function testPiuFileConLeDimensioniEUnNonPdf(): void
    {
        [$codice, $out] = $this->senzaSito(['--nrecord=200']);

        self::assertSame(0, $codice, $out);
        self::assertStringContainsString('Il documento ha 3 file.', $out);
        self::assertStringContainsString('[1] allegato_a.pdf', $out);
        self::assertStringContainsString('[2] convenzione.pdf', $out);
        self::assertStringContainsString('[3] note.docx', $out);
        self::assertSame(1, substr_count($out, 'PDF: NO'));
        self::assertStringContainsString('dimensione:', $out);
        self::assertStringContainsString(hash('sha256', self::pdfFinto('convenzione principale')), $out);
    }

    public function testPrincipaleEImprontaDiTitulus(): void
    {
        [$codice, $out] = $this->senzaSito(['--nrecord=1100']);
        self::assertSame(0, $codice, $out);
        self::assertStringContainsString('[2] nome_diverso.pdf  (principale per Titulus)', $out);
        self::assertStringNotContainsString('[1] allegato_a.pdf  (principale', $out);
        self::assertStringContainsString('impronta di Titulus: coincide', $out);
        self::assertStringContainsString('impronta di Titulus: assente', $out, 'il secondo file non dichiara nulla');

        [$codice, $out] = $this->senzaSito(['--nrecord=1200']);
        self::assertSame(1, $codice, 'impronta diversa = problema');
        self::assertStringContainsString('impronta di Titulus: diversa', $out);
    }

    public function testNessunFileDocumentoInesistentePermessoNegato(): void
    {
        [$codice, $out] = $this->senzaSito(['--nrecord=300']);
        self::assertSame(1, $codice);
        self::assertStringContainsString('nessun file', $out);

        [$codice, $out] = $this->senzaSito(['--nrecord=12345']);
        self::assertSame(1, $codice);
        self::assertStringContainsString('non esiste in Titulus', $out);

        [$codice, $out] = $this->senzaSito(['--nrecord=501']);
        self::assertSame(1, $codice);
        self::assertStringContainsString('non esiste in Titulus', $out, 'risposta senza dati');

        [$codice, $out] = $this->senzaSito(['--nrecord=400']);
        self::assertSame(1, $codice);
        self::assertStringContainsString('Permesso negato', $out);
        self::assertStringContainsString('loadDocument e getAttachment', $out);
        self::assertStringContainsString('getDocumentURL:', $out, 'la lettura fallita non ferma il resto');

        [$codice, $out] = $this->senzaSito(['--nrecord=900']);
        self::assertSame(1, $codice);
        self::assertStringContainsString('[1] negato.pdf', $out);
        self::assertStringContainsString('Permesso negato', $out, 'il documento si legge ma il file no');
    }

    public function testBase64ENonPdf(): void
    {
        [$codice, $out] = $this->senzaSito(['--nrecord=700']);
        self::assertSame(0, $codice, $out);
        self::assertStringContainsString('PDF: sì', $out);
        self::assertStringContainsString(hash('sha256', self::pdfFinto('convenzione in base64')), $out, 'il base64 è stato decodificato');

        [$codice, $out] = $this->senzaSito(['--nrecord=600']);
        self::assertSame(0, $codice, $out);
        self::assertStringContainsString('PDF: NO', $out);
        self::assertStringContainsString('non applicabile', $out);
    }

    public function testLeFirmeSiContanoSenzaDireIlNome(): void
    {
        [$codice, $out] = $this->senzaSito(['--nrecord=1000']);

        self::assertSame(0, $codice, $out);
        self::assertStringContainsString('firme: 1 voci, di cui 1 firme e 0 marche temporali [ETSI.CAdES.detached]', $out);
        self::assertStringContainsString('l\'ultima copre tutto il file', $out);
        self::assertStringNotContainsString('Mario Rossi', $out, 'mai il nome di chi ha firmato');
    }

    public function testOperazioneMancanteNelWsdl(): void
    {
        [, $out] = $this->senzaSito(['--nrecord=100'], $this->indirizzo('&senza=loadDocument'));

        self::assertStringContainsString('loadDocument     NO — manca nel WSDL', $out);
        self::assertStringContainsString('getAttachment    sì', $out);
    }

    public function testIlConfrontoDiceSeIlFileCoincide(): void
    {
        $uguale = self::$cartella . '/uguale.pdf';
        file_put_contents($uguale, self::pdfFinto('versione 3 stampigliata'));
        [, $out] = $this->senzaSito(['--nrecord=100', '--confronta=' . $uguale]);
        self::assertStringContainsString('confronto: COINCIDE', $out);

        $parte = self::$cartella . '/parte.pdf';
        file_put_contents($parte, substr(self::pdfFinto('versione 3 stampigliata'), 0, 40));
        [, $out] = $this->senzaSito(['--nrecord=100', '--confronta=' . $parte]);
        self::assertStringContainsString('Titulus ha aggiunto qualcosa in coda', $out);

        $altro = self::$cartella . '/altro.pdf';
        file_put_contents($altro, self::pdfFinto('un altro'));
        [, $out] = $this->senzaSito(['--nrecord=100', '--confronta=' . $altro]);
        self::assertStringContainsString('confronto: DIVERSO dal file indicato', $out);

        [$codice, $out] = $this->senzaSito(['--nrecord=100', '--confronta=' . self::$cartella . '/non_esiste.pdf']);
        self::assertSame(2, $codice);
        self::assertStringContainsString('--confronta', $out);
    }

    public function testSalvaInUnaCartellaChiusaEMaiNellaCartellaWeb(): void
    {
        $dove = self::$cartella . '/salvati';
        [$codice, $out] = $this->senzaSito(['--nrecord=100', '--salva=' . $dove]);

        self::assertSame(0, $codice, $out);
        $nome = 'titulus_100_1_' . substr(hash('sha256', self::pdfFinto('versione 3 stampigliata')), 0, 10) . '.pdf';
        self::assertFileExists($dove . '/' . $nome);
        self::assertSame(self::pdfFinto('versione 3 stampigliata'), file_get_contents($dove . '/' . $nome));
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0700, fileperms($dove) & 0777);
            self::assertSame(0600, fileperms($dove . '/' . $nome) & 0777);
        }
        // una seconda volta non tocca il file che c'è
        [, $out] = $this->senzaSito(['--nrecord=100', '--salva=' . $dove]);
        self::assertStringContainsString('c\'era già', $out);

        foreach ([self::$sito . '/uploads', self::$cartella . '/htdocs/x', self::$cartella . '/www/x', self::$cartella . '/public_html'] as $web) {
            [$codice, $out] = $this->strumento(['--sito=' . self::$sito, '--nrecord=100', '--produzione', '--salva=' . $web]);
            self::assertSame(2, $codice, $web);
            self::assertStringContainsString('FUORI dalla cartella web', $out);
            self::assertDirectoryDoesNotExist($web);
        }
    }

    public function testConIlSitoLeggeConfigurazioneEPasswordSenzaModificareNulla(): void
    {
        $prima = [$this->db->riga('SELECT * FROM titulus_config'), $this->db->valore('SELECT COUNT(*) FROM titulus_invii')];

        [$codice, $out] = $this->strumento(['--sito=' . self::$sito, '--nrecord=100', '--produzione'], '', ['TITULUS_PASSWORD' => 'non-la-leggere']);

        self::assertSame(0, $codice, $out);
        self::assertStringContainsString('[1] convenzione.pdf', $out, 'il collegamento era «spento» nel portale ma in sola lettura si usa lo stesso');
        self::assertStringNotContainsString(self::PASSWORD, $out);
        self::assertStringNotContainsString('non-la-leggere', $out);
        self::assertSame($prima, [$this->db->riga('SELECT * FROM titulus_config'), $this->db->valore('SELECT COUNT(*) FROM titulus_invii')], 'nessuna scrittura nel database');
    }

    public function testConIlSitoLaChiaveSbagliataODatiMancanti(): void
    {
        file_put_contents(self::$sito . '/.env', preg_replace('/CIFRATURA_CHIAVE=.*/', 'CIFRATURA_CHIAVE=un-altra-frase-lunga-abbastanza', (string) file_get_contents(self::$sito . '/.env')));
        [$codice, $out] = $this->strumento(['--sito=' . self::$sito, '--nrecord=100', '--produzione']);
        self::assertSame(1, $codice);
        self::assertStringContainsString('La password di Titulus non si legge', $out);

        [$codice, $out] = $this->strumento(['--sito=' . self::$cartella . '/non_c_e', '--nrecord=100']);
        self::assertSame(2, $codice);
        self::assertStringContainsString('--sito deve essere la cartella del portale', $out);

        $this->db->esegui('DELETE FROM titulus_config');
        file_put_contents(self::$sito . '/.env', preg_replace('/CIFRATURA_CHIAVE=.*/', 'CIFRATURA_CHIAVE=una-frase-lunga-almeno-sedici', (string) file_get_contents(self::$sito . '/.env')));
        [$codice, $out] = $this->strumento(['--sito=' . self::$sito, '--nrecord=100', '--produzione']);
        self::assertSame(1, $codice);
        self::assertStringContainsString('non è configurato', $out);
    }

    public function testOgniRigaPassaDaUnaFunzioneCheTogliIControlloEISegreti(): void
    {
        $sorgente = (string) file_get_contents(self::$radice . '/strumenti/prova_file_titulus.php');

        self::assertStringContainsString("preg_replace('/[\\x00-\\x08\\x0B-\\x1F\\x7F]/'", $sorgente, 'riga() toglie i caratteri di controllo (un titolo potrebbe portare sequenze di escape)');
        self::assertStringContainsString("str_replace(\$s, '***', \$testo)", $sorgente, 'e i segreti');
        self::assertSame(0, preg_match('/echo\s+\$(titolo|id|nome|r\[|f\[)/', $sorgente), 'nessun titolo o numero scritto con echo diretto');
    }
}
