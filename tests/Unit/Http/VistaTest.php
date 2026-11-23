<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Vista;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Le viste (App\Http\Vista). Due parti:
 *  1. il meccanismo, su una radice finta con modelli e testate finte (nessun database, nessuna sessione);
 *  2. le regole della CSP (passo S17) sui modelli VERI di viste/: nessun gestore in linea, ogni <script> in linea col nonce, nessuna
 *     conferma diversa da data-conferma, nessun escape a mano. La prova guarda sia il sorgente dei modelli sia l'HTML che rendono.
 */
final class VistaTest extends TestCase
{
    private const LAYOUT = [
        'sito' => ['header.php', 'footer.php'],
        'pannello' => ['admin/admin_header.php', 'admin/admin_footer.php'],
    ];

    private string $radice = '';
    private ?string $radicePrima = null;
    private array $layoutPrima = [];

    protected function setUp(): void
    {
        $this->radice = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vista_' . bin2hex(random_bytes(4));
        foreach (['viste/prova', 'admin'] as $d) {
            mkdir($this->radice . '/' . $d, 0777, true);
        }
        $guardia = "<?php if (!(\$d ?? null) instanceof \\App\\Http\\DatiVista) { exit; } ?>\n";
        $this->scrivi('viste/prova/ciao.php', $guardia . '<p><?php echo $d->nome; ?></p><i><?php echo $d->grezzo(\'ok\'); ?></i><?php echo $d->parziale(\'prova/_parte\', [\'x\' => $d->grezzo(\'nome\')]); ?>');
        $this->scrivi('viste/prova/_parte.php', $guardia . '[<?php echo $d->x; ?>]');
        $this->scrivi('viste/prova/doppio.php', $guardia . '<?php echo $d->parziale(\'prova/_parte\', [\'x\' => $d->nome]); ?>');
        $this->scrivi('viste/prova/scopo.php', $guardia . '<?php echo isset($segreto) ? \'vede\' : \'non vede\'; ?>');
        $this->scrivi('viste/prova/rotta.php', $guardia . "prima della rottura<?php throw new \\RuntimeException('vista rotta'); ?>");
        $this->scrivi('header.php', '<?php echo "<html><title>" . htmlspecialchars($page_cfg[\'titolo\'] ?? \'\') . "</title><meta d=\"" . htmlspecialchars($meta_descrizione ?? \'predefinita\') . "\"><body class=\"" . htmlspecialchars($page_cfg[\'classe\'] ?? \'\') . "\">"; ?>');
        $this->scrivi('footer.php', '</body></html>');
        $this->scrivi('admin/admin_header.php', '<?php echo "<div id=\"pannello\">"; ?>');
        $this->scrivi('admin/admin_footer.php', '</div>');
        $this->radicePrima = Vista::usaRadice($this->radice);
        $this->layoutPrima = Vista::layout(self::LAYOUT);
    }

    protected function tearDown(): void
    {
        Vista::usaRadice($this->radicePrima);
        if ($this->layoutPrima !== []) {
            Vista::layout($this->layoutPrima);
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->radice, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->radice);
    }

    private function scrivi(string $percorso, string $contenuto): void
    {
        file_put_contents($this->radice . '/' . $percorso, $contenuto);
    }

    // ── 1. Il meccanismo ───────────────────────────────────────────────────────

    public function testRendiRestituisceUnaStringaConLEscapeEGrezzoNo(): void
    {
        $html = Vista::rendi('prova/ciao', ['nome' => '<script>x</script>', 'ok' => '<b>sicuro</b>']);

        self::assertStringContainsString('<p>&lt;script&gt;x&lt;/script&gt;</p>', $html);
        self::assertStringContainsString('<i><b>sicuro</b></i>', $html, 'grezzo non si tocca');
        self::assertStringContainsString('[&lt;script&gt;x&lt;/script&gt;]', $html, 'il parziale riceve il valore grezzo e lo escapa lui, una volta');
    }

    /** Il tranello da conoscere: passare `$d->nome` (già escapato) a un parziale lo escapa due volte. Si passa `$d->grezzo('nome')`, o il record. */
    public function testPassareUnValoreGiaEscapatoAUnParzialeLoEscapaDueVolte(): void
    {
        self::assertSame('[&amp;lt;b&amp;gt;]', Vista::rendi('prova/doppio', ['nome' => '<b>']));
    }

    public function testIlModelloVedeSoloD(): void
    {
        $segreto = 'x';
        self::assertSame('non vede', Vista::rendi('prova/scopo', ['a' => 1]), 'nessuna variabile della pagina entra nel modello' . $segreto);
    }

    /** L'unico nome senza «__» che un modello vede è `$d`: `$livello` (il buffer) non c'è più, si chiama `$__livello`. */
    public function testIlModelloVedeSoloDEINomiConDoppioTrattino(): void
    {
        $this->scrivi('viste/prova/nomi.php', "<?php if (!(\$d ?? null) instanceof \\App\\Http\\DatiVista) { exit; } ?>" . '<?php echo implode(\',\', array_keys(get_defined_vars())); ?>');

        $nomi = explode(',', Vista::rendi('prova/nomi'));
        $senzaTrattino = array_values(array_filter($nomi, static fn (string $n): bool => !str_starts_with($n, '__')));

        self::assertSame(['d'], $senzaTrattino, 'nomi visibili al modello: ' . implode(', ', $nomi));
    }

    /**
     * La testata del pannello è uno script: `pn_briciole_html()` (chiamata dal piè di pagina) rilegge `$briciole_base` da $GLOBALS. Inclusa da un
     * metodo, la variabile resterebbe nell'ambito del metodo: Vista la ripubblica, e SOLO lei (elenco scritto a mano, DALLA_TESTATA_AI_GLOBALI).
     * Tutto il resto che la testata definisce NON arriva nei globali: nessuna lista nera da tenere aggiornata.
     */
    public function testDellaTestataDelPannelloArrivanoNeiGlobaliSoloLeBriciole(): void
    {
        $this->scrivi('admin/admin_header.php', '<?php $puo_prova_vista = true; $briciole_base = [[\'a\', \'b\']]; $permessi = \'x\'; echo "<div>"; ?>');
        $this->scrivi('admin/admin_footer.php', '<?php $GLOBALS[\'nel_footer\'] = $GLOBALS[\'briciole_base\'] ?? \'non vede\'; echo "</div>"; ?>');
        $nomi = ['puo_prova_vista', 'briciole_base', 'permessi', 'nel_footer', 'page_cfg', 'layout', 'corpo', 'titolo', 'apre', 'chiude', 'radice', 'livello'];
        $prima = [];
        foreach ($nomi as $n) {
            $prima[$n] = array_key_exists($n, $GLOBALS) ? [$GLOBALS[$n]] : null;
            unset($GLOBALS[$n]);
        }
        try {
            Vista::pannello('T', '<p>corpo</p>');

            self::assertSame([['a', 'b']], $GLOBALS['briciole_base'] ?? null, 'le briciole sono nei globali');
            self::assertSame([['a', 'b']], $GLOBALS['nel_footer'] ?? null, 'e già il piè di pagina le vede: viene dopo la testata');
            foreach (['puo_prova_vista', 'permessi', 'page_cfg', 'corpo', 'layout', 'titolo', 'apre', 'chiude', 'radice', 'livello'] as $mai) {
                self::assertArrayNotHasKey($mai, $GLOBALS, "«{$mai}» non si esporta: non è nell'elenco di chi serve a qualcuno");
            }
        } finally {
            foreach ($nomi as $n) {
                unset($GLOBALS[$n]);
                if ($prima[$n] !== null) {
                    $GLOBALS[$n] = $prima[$n][0];
                }
            }
        }
    }

    /** L'elenco delle variabili esportate è corto e ogni nome ha scritto chi lo legge: se cresce, qui qualcuno se ne accorge. */
    public function testElencoDelleVariabiliEsportateEScrittoAMano(): void
    {
        $sorgente = (string) file_get_contents(RADICE_SITO . '/src/Http/Vista.php');

        self::assertMatchesRegularExpression('/private const DALLA_TESTATA_AI_GLOBALI = \[\'briciole_base\'\];/', $sorgente, 'un nome nuovo entra con il suo lettore e va aggiornato anche questa prova');
        self::assertStringContainsString('pn_briciole_html()', $sorgente, 'accanto al nome c\'è scritto chi lo legge');
        self::assertDoesNotMatchRegularExpression('/in_array\(\$nome, \[\'layout\'/', $sorgente, 'niente lista nera: si esporta solo ciò che è nell\'elenco');
    }

    public function testIlLayoutDelSitoNonEsportaNiente(): void
    {
        $this->scrivi('header.php', '<?php $variabile_del_sito_prova = 1; echo "<html>"; ?>');
        unset($GLOBALS['variabile_del_sito_prova']);

        Vista::pagina('T', '<p>x</p>');

        self::assertArrayNotHasKey('variabile_del_sito_prova', $GLOBALS, 'solo il pannello (che è scritto come script) rimette le variabili nei globali');
    }

    public function testNonStampaNienteDaSolo(): void
    {
        $this->expectOutputString('');
        Vista::rendi('prova/ciao', ['nome' => 'a', 'ok' => 'b']);
        Vista::pagina('T', '<p>corpo</p>');
        Vista::pannello('T', '<p>corpo</p>');
    }

    public function testUnaVistaRottaNonLasciaMezzaPaginaNelBuffer(): void
    {
        $livello = ob_get_level();
        try {
            Vista::rendi('prova/rotta');
            self::fail('l\'eccezione deve arrivare a chi chiama');
        } catch (RuntimeException $e) {
            self::assertSame('vista rotta', $e->getMessage());
        }
        self::assertSame($livello, ob_get_level(), 'il buffer della vista è stato chiuso');
    }

    public function testIlNomeDelModelloEControllato(): void
    {
        foreach (['../config', 'prova/../ciao', 'Prova/ciao', 'prova/ciao.php', '/prova/ciao', 'prova//ciao', '', 'prova/ciao ', "prova/ciao\0"] as $nome) {
            try {
                Vista::rendi($nome);
                self::fail("accettato il nome «$nome»");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testUnModelloCheNonEsisteEUnErrore(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('viste/prova/manca.php');
        Vista::rendi('prova/manca');
    }

    public function testPaginaMetteIlCorpoFraTestataEPiede(): void
    {
        $html = Vista::pagina('Contatti & co', '<main>corpo</main>');

        self::assertSame('<html><title>Contatti &amp; co</title><meta d="predefinita"><body class=""><main>corpo</main></body></html>', $html);
    }

    public function testPaginaPassaLaDescrizioneEIValoriDiPageCfg(): void
    {
        $html = Vista::pagina('T', '<p>x</p>', ['descrizione' => 'Una "descrizione"', 'page_cfg' => ['classe' => 'verde', 'titolo' => 'ignorato: vince quello della chiamata']]);

        self::assertStringContainsString('<title>T</title>', $html);
        self::assertStringContainsString('<meta d="Una &quot;descrizione&quot;">', $html);
        self::assertStringContainsString('<body class="verde">', $html);
    }

    public function testIlCorpoDellaPaginaNonVieneEscapatoDaPagina(): void
    {
        self::assertStringContainsString('<b>x</b>', Vista::pagina('T', '<b>x</b>'), 'il corpo è già il risultato di un modello: ha avuto il suo escape lì');
    }

    public function testPannelloUsaIlLayoutDelPannello(): void
    {
        self::assertSame('<div id="pannello"><p>corpo</p></div>', Vista::pannello('T', '<p>corpo</p>'));
    }

    public function testPaginaConUnaTestataCheSollevaPulisceIlBuffer(): void
    {
        $this->scrivi('header.php', '<?php echo "mezza testata"; throw new \\RuntimeException("testata rotta"); ?>');
        $livello = ob_get_level();
        try {
            Vista::pagina('T', 'x');
            self::fail('deve arrivare l\'eccezione');
        } catch (RuntimeException) {
            self::assertSame($livello, ob_get_level());
        }
    }

    // ── 2. Le regole della CSP sui modelli veri ────────────────────────────────

    /**
     * I problemi di un pezzo di HTML o di un modello.
     *
     * @param bool $sorgente true = il testo di un modello (il nonce è scritto `nonce="<?php echo csp_nonce(); ?>"`);
     *                       false = l'HTML già reso (il nonce è un valore vero, non vuoto)
     * @return list<string>
     */
    public static function problemiCsp(string $testo, bool $sorgente): array
    {
        $problemi = [];
        // <script> in linea (senza src e che non sia un blocco di dati) senza nonce
        if ($sorgente) {
            $senzaNonce = '~<script\b(?!\s+nonce="<\?php echo csp_nonce\(\); \?>")(?![^>]*\bsrc=)(?![^>]*type="application/(?:ld\+)?json")~i';
        } else {
            $senzaNonce = '~<script\b(?![^>]*\bnonce="[^"]+")(?![^>]*\bsrc=)(?![^>]*type="application/(?:ld\+)?json")~i';
        }
        if (preg_match_all($senzaNonce, $testo, $m) > 0) {
            $problemi[] = count($m[0]) . ' <script> in linea senza nonce' . ($sorgente ? ' (la forma è nonce="<?php echo csp_nonce(); ?>", subito dopo <script)' : '');
        }
        // gestori in linea: onclick=, onchange=, onsubmit=…
        $gestori = $sorgente ? '~\son[a-z]{3,}\s*=~i' : '~<[a-z][^>]*\son[a-z]{3,}\s*=~i';
        if (preg_match($gestori, $testo, $m) === 1) {
            $problemi[] = 'gestore in linea (onclick, onchange, onsubmit…): usa i data-on-* di assets/js/azioni.js — ' . trim($m[0]);
        }
        // conferme: solo data-conferma / data-conferma-scrivi, mai confirm() né il vecchio data-confirm
        if (preg_match('~\bconfirm\s*\(|\bdata-confirm(?:-level)?\s*=~i', $testo) === 1) {
            $problemi[] = 'conferma diversa da data-conferma / data-conferma-scrivi (assets/js/conferma.js)';
        }

        return $problemi;
    }

    public function testIlControlloCspVedeIProblemi(): void
    {
        self::assertSame([], self::problemiCsp('<script nonce="<?php echo csp_nonce(); ?>">a()</script><script src="x.js"></script><script type="application/json">{}</script>', true));
        self::assertSame([], self::problemiCsp('<script nonce="AbC123=">a()</script><a data-on-click="f" data-conferma="Sicuro?">x</a> <a href="?online=1">y</a>', false), 'nessun falso allarme su data-on-click né su ?online=1');
        self::assertCount(1, self::problemiCsp('<script>a()</script>', true));
        self::assertCount(1, self::problemiCsp('<script>a()</script>', false));
        self::assertCount(1, self::problemiCsp('<script nonce="">a()</script>', false), 'un nonce vuoto non vale');
        self::assertCount(1, self::problemiCsp('<script type="text/javascript" nonce="<?php echo csp_nonce(); ?>">a()</script>', true), 'una sola forma ammessa, la più usata');
        self::assertNotEmpty(self::problemiCsp('<button onclick="f()">x</button>', false));
        self::assertNotEmpty(self::problemiCsp('<form method="post" onsubmit="return f()">', false));
        self::assertNotEmpty(self::problemiCsp('<button class="b" onchange = "f()">', true));
        self::assertNotEmpty(self::problemiCsp('<button data-confirm="Sicuro?">x</button>', false));
        self::assertNotEmpty(self::problemiCsp('<button onclick="if (confirm(\'Sicuro?\')) go()">x</button>', false));
    }

    /** @return list<string> percorsi relativi a viste/ di tutti i modelli (compresi i parziali) */
    private static function modelli(): array
    {
        $out = [];
        $base = RADICE_SITO . '/viste';
        if (!is_dir($base)) {
            return $out;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = substr(str_replace('\\', '/', $f->getPathname()), strlen($base) + 1, -4);
            }
        }
        sort($out);

        return $out;
    }

    public function testCiSonoDeiModelliVeri(): void
    {
        self::assertContains('pubblico/contatti', self::modelli());
        self::assertContains('pannello/contatti', self::modelli());
        self::assertContains('stampe/ricevuta', self::modelli());
        self::assertFileExists(RADICE_SITO . '/viste/.htaccess', 'la cartella dei modelli non si apre dal web');
    }

    public function testIlSorgenteDiOgniModelloRispettaLeRegole(): void
    {
        foreach (self::modelli() as $nome) {
            $src = (string) file_get_contents(RADICE_SITO . '/viste/' . $nome . '.php');

            self::assertSame([], self::problemiCsp($src, true), "viste/$nome.php");
            self::assertStringStartsWith('<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; }', $src, "viste/$nome.php: manca la guardia in cima (aperto da solo non deve fare niente)");
            self::assertStringNotContainsString('htmlspecialchars(', $src, "viste/$nome.php: l'escape lo fa \$d, non a mano (se serve scavalcarlo: \$d->grezzo())");
            self::assertDoesNotMatchRegularExpression('/\$h\s*=\s*fn/', $src, "viste/$nome.php: niente lambda \$h");
        }
    }

    /**
     * Dati di prova per ogni modello che si rende da solo (i parziali, che cominciano con «_», si rendono dal modello che li usa).
     *
     * @return array<string, list<array<string, mixed>>> modello => varianti di dati
     */
    private static function datiDiProva(): array
    {
        $cattivo = '<script>alert(1)</script> & "q" \'s\'';
        $contatto = ['id' => 7, 'titolo' => $cattivo, 'per_cosa' => 'Certificati', 'luogo' => $cattivo, 'orari' => 'lun 10-12', 'telefono' => '0984 49 0000', 'email' => 'u@example.org',
            'link_etichetta' => 'Prenota', 'link_url' => 'https://example.org/?a=1&b=2', 'ordine' => 1, 'visibile' => 1];
        $pubblico = $contatto + ['esterno' => true, 'telefono_href' => '098449000', 'link_testo' => 'Prenota'];
        $nuovo = ['id' => 0, 'titolo' => '', 'per_cosa' => '', 'luogo' => '', 'orari' => '', 'email' => '', 'telefono' => '', 'link_etichetta' => '', 'link_url' => '', 'ordine' => 2, 'visibile' => 0];
        $pannello = static fn (int $modifica, array $contatti): array => ['filtro' => 3, 'campoCsrf' => '<input type="hidden" name="csrf_token" value="tok">', 'modifica' => $modifica, 'nuovo' => $nuovo, 'contatti' => $contatti];
        $recapiti = static fn (array $c): array => $c + ['recapiti' => array_values(array_filter([$c['luogo'], $c['orari'], $c['telefono'], $c['email']], static fn ($x): bool => $x !== ''))];
        $ricevuta = ['codice_prenotazione' => 'OP-ABC123', 'stato' => 'in_attesa', 'nome_portale' => 'MyDiBEST', 'sottotitolo_portale' => 'Dipartimento', 'logo_path' => 'assets/logo.png',
            'pagina_titolo' => $cattivo, 'evento_titolo' => 'Laboratorio', 'nome_turno' => 'Gruppo 1', 'data_turno' => '2026-11-22', 'data_it' => '22/11/2026', 'orario' => '09:30–11:00',
            'evento_luogo' => 'Cubo 15B', 'nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'm@example.org', 'matricola_mostrata' => 'N/D', 'num_posti' => 2];

        return [
            'pannello/autenticazione_richiesta' => [['css_bootstrap' => 'a.css', 'css_icone' => 'b.css', 'link_accedi' => '../saml_login.php?redirect=admin%2Findex.php'],
                ['css_bootstrap' => 'a.css?x=1&y=2', 'css_icone' => 'b.css', 'link_accedi' => '../saml_login.php?redirect=' . urlencode($cattivo)]],
            'pubblico/contatti' => [['contatti' => []], ['contatti' => [$pubblico, ['link_url' => '', 'esterno' => false] + $pubblico]]],
            'pannello/contatti' => [
                $pannello(-1, []),
                $pannello(0, []),
                $pannello(-1, [$recapiti($contatto), $recapiti(['luogo' => '', 'orari' => '', 'telefono' => '', 'email' => '', 'link_url' => '', 'visibile' => 0, 'id' => 8] + $contatto)]),
                $pannello(7, [$recapiti($contatto)]),
            ],
            'stampe/ricevuta' => [
                ['p' => $ricevuta, 'qr' => '<svg></svg>', 'css_bootstrap' => 'a.css', 'css_icone' => 'b.css', 'col_ric' => '#B80000', 'col_ric_txt' => '#FFFFFF', 'col_ric_testo' => '#B80000', 'senza_prenotazione' => false,
                    'custom' => [['chiave' => 'scuola', 'valore' => $cattivo, 'allegato' => false], ['chiave' => 'documento', 'valore' => 'uploads/x.pdf', 'allegato' => true]]],
                ['p' => ['stato' => 'confermata', 'logo_path' => '', 'nome_turno' => '', 'data_turno' => ''] + $ricevuta, 'qr' => '', 'css_bootstrap' => 'a.css', 'css_icone' => 'b.css',
                    'col_ric' => '#000000', 'col_ric_txt' => '#FFFFFF', 'col_ric_testo' => '#000000', 'senza_prenotazione' => false, 'custom' => []],
            ],
        ];
    }

    public function testOgniModelloHaIDatiDiProvaECosiLaProvaDellaCspLoRende(): void
    {
        $radicePrima = Vista::usaRadice(null);
        try {
            $principali = array_values(array_filter(self::modelli(), static fn (string $n): bool => !str_starts_with(basename($n), '_')));
            self::assertSame($principali, (static function (): array { $k = array_keys(self::datiDiProva()); sort($k); return $k; })(), 'un modello nuovo ha bisogno dei suoi dati di prova qui: senza, la CSP non lo guarda');
            foreach (self::datiDiProva() as $nome => $varianti) {
                foreach ($varianti as $i => $dati) {
                    $html = Vista::rendi($nome, $dati);
                    self::assertNotSame('', trim($html), "$nome #$i: reso vuoto");
                    self::assertSame([], self::problemiCsp($html, false), "$nome #$i: HTML reso");
                    self::assertStringNotContainsString('<script>alert(1)</script>', $html, "$nome #$i: un valore con <script> deve uscire escapato");
                }
            }
        } finally {
            Vista::usaRadice($radicePrima);
        }
    }

    public function testIDatiSpecialiUsciEscapatiNelleTrePagine(): void
    {
        $radicePrima = Vista::usaRadice(null);
        try {
            $dati = self::datiDiProva();
            $elenco = Vista::rendi('pannello/contatti', $dati['pannello/contatti'][2]);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;q&quot; &#039;s&#039;', $elenco);
            self::assertStringContainsString('data-conferma="Eliminare il contatto «&lt;script&gt;', $elenco, 'la conferma passa da data-conferma');
            self::assertStringContainsString('Nessun recapito ancora', $elenco);
            self::assertSame(4, substr_count($elenco, 'name="csrf_token" value="tok"'), 'un campo CSRF in ogni modulo di azione: mostra/nascondi ed elimina, per due contatti');

            $modulo = Vista::rendi('pannello/contatti', $dati['pannello/contatti'][1]);
            foreach (['titolo', 'per_cosa', 'luogo', 'orari', 'email', 'telefono', 'link_etichetta', 'link_url', 'ordine'] as $campo) {
                self::assertStringContainsString('name="' . $campo . '"', $modulo, "campo $campo nel modulo");
            }
            self::assertStringContainsString('name="salva" value="0"', $modulo);
            self::assertStringNotContainsString(' checked', $modulo, 'un contatto nuovo parte nascosto');

            $modifica = Vista::rendi('pannello/contatti', $dati['pannello/contatti'][3]);
            self::assertStringContainsString('name="salva" value="7"', $modifica);
            self::assertStringContainsString(' checked', $modifica);
            self::assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;q&quot; &#039;s&#039;"', $modifica, 'un valore dentro un attributo non esce dall\'attributo');

            $pubblica = Vista::rendi('pubblico/contatti', $dati['pubblico/contatti'][1]);
            self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $pubblica);
            self::assertStringContainsString('href="https://example.org/?a=1&amp;b=2"', $pubblica);
            self::assertStringContainsString('href="tel:098449000"', $pubblica);
            self::assertStringContainsString('I contatti sono in preparazione', Vista::rendi('pubblico/contatti', $dati['pubblico/contatti'][0]));

            $ricevuta = Vista::rendi('stampe/ricevuta', $dati['stampe/ricevuta'][0]);
            self::assertStringContainsString('Lista d\'Attesa', $ricevuta);
            self::assertStringContainsString('📎 File Allegato', $ricevuta);
            self::assertStringNotContainsString('uploads/x.pdf', $ricevuta, 'il percorso di un allegato non si mostra');
            self::assertStringContainsString('<svg></svg>', $ricevuta, 'il QR è HTML già sicuro: passa da grezzo');
            self::assertStringContainsString('<!DOCTYPE html>', ltrim($ricevuta), 'documento intero, senza testata del sito');
            self::assertStringContainsString('Confermata', Vista::rendi('stampe/ricevuta', $dati['stampe/ricevuta'][1]));
            // Evento ad accesso libero: «Presenza registrata», niente codice né QR
            $libera = Vista::rendi('stampe/ricevuta', ['senza_prenotazione' => true] + $dati['stampe/ricevuta'][0]);
            self::assertStringContainsString('Presenza registrata', $libera);
            self::assertStringContainsString('RICEVUTA DI PRESENZA', $libera);
            self::assertStringNotContainsString('OP-ABC123', $libera, 'il codice non si mostra');
            self::assertStringNotContainsString('<svg></svg>', $libera, 'e nemmeno il QR che lo contiene');
            self::assertStringContainsString('RICEVUTA DI PRENOTAZIONE', $ricevuta);
        } finally {
            Vista::usaRadice($radicePrima);
        }
    }

    /** Come farebbe Apache aprendo il file dal browser: un processo PHP a parte, senza `$d`. La guardia fa `exit`, quindi non si può provare qui dentro. */
    public function testIModelliAperiDirettamenteNonStampanoNiente(): void
    {
        foreach (self::modelli() as $nome) {
            $p = proc_open([PHP_BINARY, '-d', 'display_errors=1', RADICE_SITO . '/viste/' . $nome . '.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
            self::assertIsResource($p);
            $uscita = (string) stream_get_contents($pipe[1]) . (string) stream_get_contents($pipe[2]);
            fclose($pipe[1]);
            fclose($pipe[2]);
            proc_close($p);

            self::assertSame('', trim($uscita), "viste/$nome.php aperto da solo deve restare muto");
        }
    }

    /** Le tre pagine convertite sono ora classi (src/Portale/Pagine/): l'escape lo fa la vista, e il guscio di oggi non ha più né logica né HTML. */
    public function testLeTrePagineConvertiteNonHannoPiuLaLambdaDelEscape(): void
    {
        foreach (['contatti.php' => 'PaginaContatti', 'admin/contatti.php' => 'PaginaContattiPannello', 'stampa_ricevuta.php' => 'PaginaStampaRicevuta'] as $guscio => $classe) {
            $src = (string) file_get_contents(RADICE_SITO . '/src/Portale/Pagine/' . $classe . '.php');

            self::assertDoesNotMatchRegularExpression('/\$h\s*=\s*fn/', $src, $classe);
            self::assertStringNotContainsString('htmlspecialchars(', $src, "$classe: l'escape lo fa la vista");
            self::assertStringContainsString('Vista::rendi(', $src, $classe);
            self::assertSame([], self::problemiCsp($src, true), $classe);

            $righe = array_filter(array_map('trim', file(RADICE_SITO . '/' . $guscio) ?: []), static fn (string $r): bool => $r !== '' && !str_starts_with($r, '//') && $r !== '<?php');
            self::assertContains('App\Http\Nucleo::serviFile(__FILE__);', $righe, "$guscio: il guscio chiama il Nucleo");
            self::assertLessThanOrEqual(3, count($righe), "$guscio: un guscio ha solo i require e la chiamata al Nucleo");
        }
    }
}
