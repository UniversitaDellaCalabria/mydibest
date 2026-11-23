<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Auth\Flash;
use App\Core\App;
use App\Http\Richiesta;
use App\Http\Risposta;
use App\Http\Vista;
use App\Portale\Pannello\AccessoPannello;
use App\Portale\Registrazione;
use Tests\Doppi\AuthSessioneInMemoria;
use Tests\Integration\Auth\ProfiliPannelloBase;

/**
 * App\Portale\Pannello\AccessoPannello: chi può aprire una pagina del pannello e che cosa gli si risponde. È la copia unica delle regole che usano
 * il Nucleo (decidi) e la testata delle pagine-script (seNonCollegata + perPagina): qui si provano le risposte per i profili veri di PermessiTest.
 * La testata vera e il Nucleo insieme si provano per HTTP (strumenti/prove/oop/pagine_contatti.php, pagine_accesso.php).
 */
final class AccessoPannelloTest extends ProfiliPannelloBase
{
    private string $radice = '';
    private ?string $radicePrima = null;
    private array $layoutPrima = [];
    private string $erroriPrima = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->erroriPrima = (string) ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'accesso_errori_' . bin2hex(random_bytes(3)) . '.log');
        // layout finti per il 403 vestito: una testata, un piè di pagina
        $this->radice = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'accesso_' . bin2hex(random_bytes(4));
        mkdir($this->radice . '/admin', 0777, true);
        file_put_contents($this->radice . '/admin/admin_header.php', '<?php echo "<div id=\"pannello\">"; ?>');
        file_put_contents($this->radice . '/admin/admin_footer.php', '</div>');
        $this->radicePrima = Vista::usaRadice($this->radice);
        $this->layoutPrima = Vista::layout(Registrazione::LAYOUT_VISTE); // i nomi veri del portale: la radice finta ha gli stessi file
    }

    protected function tearDown(): void
    {
        Vista::usaRadice($this->radicePrima);
        if ($this->layoutPrima !== []) {
            Vista::layout($this->layoutPrima);
        }
        @unlink((string) ini_get('error_log'));
        ini_set('error_log', $this->erroriPrima);
        foreach (['admin/admin_header.php', 'admin/admin_footer.php'] as $f) {
            @unlink($this->radice . '/' . $f);
        }
        @rmdir($this->radice . '/admin');
        @rmdir($this->radice);
        parent::tearDown();
    }

    /** @return array{0: AccessoPannello, 1: AuthSessioneInMemoria} l'accesso per la persona del profilo (null = nessuno collegato) */
    private function accessoDi(?string $profilo): array
    {
        $sessione = new AuthSessioneInMemoria();
        if ($profilo !== null) {
            $sessione->scrivi('utente_id', (int) $this->utenti[$profilo]['id']);
        }

        return [new AccessoPannello($this->contestoPannello($sessione), new Flash($sessione)), $sessione];
    }

    private static function richiesta(array $query = []): Richiesta
    {
        return Richiesta::finta('GET', '/sito/admin/contatti.php', $query, [], '/sito');
    }

    // ───────────────────────── Non collegata ─────────────────────────

    public function testChiNonECollegatoVaAlLoginSsoEPoiTornaQui(): void
    {
        [$accesso] = $this->accessoDi(null);

        $r = $accesso->seNonCollegata(self::richiesta(['p_id' => '3', 'x' => 'a b']), 'contatti.php');

        $this->assertNotNull($r);
        $this->assertSame(302, $r->codice());
        $this->assertSame('../saml_login.php?redirect=admin%2Fcontatti.php%3Fp_id%3D3%26x%3Da%2Bb%26sso_tentato%3D1', $r->intestazione('Location'));
        $this->assertTrue($r->svuotaBuffer(), 'un rimando butta i buffer, come fa vai_a()');
    }

    public function testDopoUnLoginTentatoEFalitoSiVedeAutenticazioneRichiesta(): void
    {
        [$accesso] = $this->accessoDi(null);

        // il modello è quello vero (viste/pannello/autenticazione_richiesta.php), non il layout finto della prova
        $finta = Vista::usaRadice(null);
        try {
            $r = $accesso->seNonCollegata(self::richiesta(['sso_tentato' => '1']), 'contatti.php');
        } finally {
            Vista::usaRadice($finta);
        }

        $this->assertNotNull($r);
        $this->assertSame(200, $r->codice());
        $this->assertStringContainsString('Autenticazione Richiesta', $r->corpo());
        $this->assertStringContainsString('href="../saml_login.php?redirect=admin%2Findex.php"', $r->corpo());
        $this->assertStringContainsString('/assets/vendor/jsdelivr/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css', $r->corpo());
        $this->assertFalse($r->svuotaBuffer(), 'è una pagina HTML: non butta i buffer (la fascia di prova passa da lì)');
    }

    public function testUnaSessioneChePuntaAUnaPersonaCancellataEComeNonCollegata(): void
    {
        $sessione = new AuthSessioneInMemoria();
        $sessione->scrivi('utente_id', 99999);
        $accesso = new AccessoPannello($this->contestoPannello($sessione), new Flash($sessione));

        $r = $accesso->seNonCollegata(self::richiesta(), 'contatti.php');

        $this->assertSame(302, $r?->codice());
    }

    public function testChiECollegatoNonRiceveRispostaDa_SeNonCollegata(): void
    {
        [$accesso] = $this->accessoDi('docente');

        $this->assertNull($accesso->seNonCollegata(self::richiesta(), 'contatti.php'));
    }

    // ───────────────────────── Collegata: il permesso ─────────────────────────

    public function testLaMappaDecideChiEntra(): void
    {
        foreach (['admin' => true, 'admin_secondario' => true, 'docente' => false, 'gestore_area' => false, 'modulo_didattica' => false] as $profilo => $entra) {
            [$accesso] = $this->accessoDi($profilo);

            $this->assertSame($entra, $accesso->perPagina(self::richiesta(), 'contatti.php'), "«{$profilo}» su contatti.php (solo amministratori)");
        }
    }

    public function testUnaPaginaSenzaRegolaSiApreSoloAglIAmministratoriESiScriveNelRegistro(): void
    {
        [$admin] = $this->accessoDi('admin');
        [$gestore] = $this->accessoDi('gestore_area');

        $this->assertTrue($admin->perPagina(self::richiesta(), 'pagina_inventata.php'));
        $this->assertFalse($gestore->perPagina(self::richiesta(), 'pagina_inventata.php'));
        $registro = (string) file_get_contents((string) ini_get('error_log'));
        $this->assertSame(2, substr_count($registro, 'pagina senza regola in PermessiPagine: pagina_inventata.php'), 'una riga ogni volta che si chiede');
        $this->assertSame(AccessoPannello::MESSAGGIO_SENZA_REGOLA, $admin->messaggioDelNo('pagina_inventata.php'));
        $this->assertSame(AccessoPannello::MESSAGGIO_NEGATO, $admin->messaggioDelNo('contatti.php'));
    }

    public function testChiNonHaSezioniDaGestireVaAllAreaPersonaleConUnAvviso(): void
    {
        [$accesso, $sessione] = $this->accessoDi('docente');

        $r = $accesso->perPagina(self::richiesta(), 'index.php');

        $this->assertInstanceOf(Risposta::class, $r);
        $this->assertSame(303, $r->codice());
        $this->assertSame('../area_personale.php', $r->intestazione('Location'));
        $flash = (new Flash($sessione))->prendi();
        $this->assertSame(['msg' => AccessoPannello::MESSAGGIO_SENZA_SEZIONI, 'type' => 'info'], $flash);
    }

    public function testChiHaSezioniEntraNellaPaginaDIngresso(): void
    {
        [$accesso, $sessione] = $this->accessoDi('gestore_area');

        $this->assertTrue($accesso->perPagina(self::richiesta(), 'index.php'));
        $this->assertNull((new Flash($sessione))->prendi(), 'nessun avviso per chi entra');
    }

    // ───────────────────────── La decisione del Nucleo ─────────────────────────

    public function testChiHaDecisoLoDiceEPrimaDiDecidereNonHaDecisoNiente(): void
    {
        [$accesso] = $this->accessoDi('admin');
        $this->assertFalse($accesso->giaDecisoPer('contatti.php'));
        $this->assertFalse($accesso->negatoPer('contatti.php'));

        $this->assertTrue($accesso->decidi(self::richiesta(), 'contatti.php'));

        $this->assertTrue($accesso->giaDecisoPer('contatti.php'), 'il Nucleo ha deciso per questa pagina');
        $this->assertFalse($accesso->giaDecisoPer('sistema.php'), 'e solo per questa: un\'altra pagina la decide chi la apre');
        $this->assertFalse($accesso->negatoPer('contatti.php'), 'ha detto sì');
    }

    public function testUnNoDelNucleoEUn403NelLayoutDelPannelloEIlLayoutLoSa(): void
    {
        [$accesso] = $this->accessoDi('docente');

        $r = $accesso->decidi(self::richiesta(), 'contatti.php');

        $this->assertInstanceOf(Risposta::class, $r);
        $this->assertSame(403, $r->codice());
        $this->assertStringStartsWith('text/html', (string) $r->intestazione('Content-Type'));
        $this->assertSame('<div id="pannello"><div class=\'alert alert-danger fw-bold shadow-sm m-4\'><i class=\'fa fa-ban me-2\'></i> '
            . htmlspecialchars(AccessoPannello::MESSAGGIO_NEGATO, ENT_QUOTES) . '</div></div>', $r->corpo(), 'testata + avviso + piè di pagina');
        $this->assertTrue($r->svuotaBuffer());
        $this->assertTrue($accesso->giaDecisoPer('contatti.php'), 'dichiarato PRIMA di vestire il 403: la testata lo trova già pronto');
        $this->assertTrue($accesso->negatoPer('contatti.php'), 'e sa che era un no');
    }

    public function testNonCollegatoERimandoAreaPersonaleSonoRisposteDelNucleoSenzaLayout(): void
    {
        [$anonimo] = $this->accessoDi(null);
        $r = $anonimo->decidi(self::richiesta(), 'contatti.php');
        $this->assertSame(302, $r instanceof Risposta ? $r->codice() : 0);
        $this->assertFalse($anonimo->giaDecisoPer('contatti.php'), 'un rimando non è una decisione sulla pagina: la pagina non viene servita');

        [$docente] = $this->accessoDi('docente');
        $r = $docente->decidi(self::richiesta(), 'index.php');
        $this->assertSame(303, $r instanceof Risposta ? $r->codice() : 0);
        $this->assertFalse($docente->giaDecisoPer('index.php'));
    }

    public function testLaDichiarazioneVivePerUnaRichiestaIlContainerNuovoLaDimentica(): void
    {
        [$accesso] = $this->accessoDi('admin');
        $accesso->decidi(self::richiesta(), 'contatti.php');
        $this->assertTrue($accesso->giaDecisoPer('contatti.php'));

        App::avvia(self::$conn);
        $this->assertFalse(App::get(AccessoPannello::class)->giaDecisoPer('contatti.php'), 'un\'altra richiesta, un altro container: nessuna dichiarazione ereditata');
    }
}
