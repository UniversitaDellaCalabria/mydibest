<?php

declare(strict_types=1);

namespace Tests\Unit\Portale;

use App\Portale\Pannello\MenuModuli;
use PHPUnit\Framework\TestCase;

/** Il menu del pannello diviso per modulo (MenuModuli + MODULI_PORTALE/PAGINE_MODULO di inc/sezioni.php): ogni voce in un solo modulo, pagine che esistono, permessi invariati. */
final class MenuModuliTest extends TestCase
{
    private const ADMIN = __DIR__ . '/../../../admin';

    /** Pagine che compaiono in una voce ma seguono l'area aperta, non un modulo (non stanno in PAGINE_MODULO). */
    private const SENZA_MODULO = ['aree.php'];

    public static function setUpBeforeClass(): void
    {
        require_once RADICE_SITO . '/inc/sezioni.php';
    }

    private static function pagina(string $href): string
    {
        return explode('?', $href)[0];
    }

    public function testOgniVoceDiMenuAppartieneAUnSoloModulo(): void
    {
        $viste = []; // indirizzo => modulo
        foreach (MenuModuli::moduli() as $modulo) {
            self::assertArrayHasKey($modulo, MODULI_PORTALE, "MenuModuli ha voci per «{$modulo}», che non è in MODULI_PORTALE");
            $ids = [];
            foreach (MenuModuli::voci($modulo) as $v) {
                self::assertNotContains($v['id'], $ids, "$modulo: l'id «{$v['id']}» è ripetuto");
                $ids[] = $v['id'];
                self::assertArrayNotHasKey($v['href'], $viste, "la voce «{$v['testo']}» ($modulo, {$v['href']}) è anche in " . ($viste[$v['href']] ?? ''));
                $viste[$v['href']] = $modulo;
                foreach (['icona', 'testo', 'permessi', 'pagine'] as $campo) {
                    self::assertArrayHasKey($campo, $v, "$modulo/{$v['id']}: manca «{$campo}»");
                }
                self::assertNotSame([], $v['permessi'], "$modulo/{$v['id']}: nessun permesso");
            }
        }
        // una pagina che accende una voce non può accenderne una di un altro modulo
        $pagine = [];
        foreach (MenuModuli::moduli() as $modulo) {
            foreach (MenuModuli::voci($modulo) as $v) {
                foreach ($v['pagine'] as $p) {
                    self::assertArrayNotHasKey($p, $pagine, "la pagina $p accende voci in due moduli ($modulo e " . ($pagine[$p] ?? '') . ')');
                    $pagine[$p] = $modulo;
                }
            }
        }
    }

    public function testOgniPaginaDiPagineModuloEsisteEPuntaAUnModuloVero(): void
    {
        foreach (PAGINE_MODULO as $pagina => $modulo) {
            self::assertFileExists(self::ADMIN . '/' . $pagina, "PAGINE_MODULO cita $pagina, che non esiste in admin/");
            self::assertArrayHasKey($modulo, MODULI_PORTALE, "$pagina punta a «{$modulo}», che non è un modulo");
        }
        self::assertArrayNotHasKey('portale', MODULI_PORTALE, 'il vecchio modulo «portale» non è più nel menu');
        self::assertNotContains('portale', PAGINE_MODULO);
    }

    public function testLeVociPuntanoAPagineCheEsistonoESonoNelModuloGiusto(): void
    {
        foreach (MenuModuli::moduli() as $modulo) {
            foreach (MenuModuli::voci($modulo) as $v) {
                foreach (array_unique(array_merge([self::pagina($v['href'])], $v['pagine'])) as $p) {
                    self::assertFileExists(self::ADMIN . '/' . $p, "$modulo/{$v['id']}: la pagina $p non esiste");
                    if (in_array($p, self::SENZA_MODULO, true)) {
                        continue;
                    }
                    self::assertSame($modulo, PAGINE_MODULO[$p] ?? null, "la pagina $p è nella voce «{$v['testo']}» di $modulo ma PAGINE_MODULO la mette in " . (PAGINE_MODULO[$p] ?? 'nessun modulo'));
                }
            }
        }
    }

    public function testOgniModuloHaNomeIconaColoreEDescrizioneEColoriPerIlTemaScuro(): void
    {
        $css = (string)file_get_contents(RADICE_SITO . '/assets/css/dibest-colori.css');
        $colori = [];
        foreach (MODULI_PORTALE as $k => $m) {
            foreach (['nome', 'icona', 'colore', 'descr'] as $campo) {
                self::assertNotSame('', trim((string)($m[$campo] ?? '')), "il modulo $k non ha «{$campo}»");
            }
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $m['colore']);
            $colori[] = strtolower($m['colore']);
            // la variabile del tema chiaro e la sua variante chiara per il tema scuro
            self::assertSame(2, preg_match_all('/--dibest-mod-' . preg_quote($k, '/') . ':/', $css), "dibest-colori.css: --dibest-mod-$k deve esserci nel tema chiaro e in quello scuro");
        }
        self::assertSame(count($colori), count(array_unique($colori)), 'ogni modulo ha il suo colore');
        self::assertNotSame(-1, preg_match('/--dibest-mod-persone: #4338ca/', $css));
    }

    public function testIPermessiDelleVociSonoQuelliCheIlMenuCalcola(): void
    {
        $header = (string)file_get_contents(self::ADMIN . '/admin_header.php');
        $inizio = strpos($header, '$perm_menu = [');
        $fine = $inizio === false ? false : strpos($header, "\n];", $inizio);
        self::assertNotFalse($fine, 'in admin_header.php manca $perm_menu');
        preg_match_all("/'([a-z_]+)' => /", substr($header, $inizio, $fine - $inizio), $m);
        $noti = $m[1];
        foreach (MenuModuli::moduli() as $modulo) {
            foreach (MenuModuli::voci($modulo) as $v) {
                foreach ($v['permessi'] as $p) {
                    self::assertContains($p, $noti, "$modulo/{$v['id']}: il permesso «{$p}» non è in \$perm_menu di admin_header.php");
                }
            }
        }
    }

    /** @return array<string, array{0: array<string, bool>, 1: list<string>}> */
    public static function profili(): array
    {
        return [
            'nessuna sezione' => [[], []],
            'amministratore' => [['is_full_admin' => true, 'puo_fsl' => true, 'puo_fsl_convenzioni' => true, 'puo_fsl_scuole' => true, 'puo_didattica' => true, 'puo_didattica_tutto' => true,
                'puo_conv_tesi' => true, 'puo_tir_motorie' => true, 'puo_tutorato' => true, 'puo_assistenza' => true, 'puo_forms_vedere' => true, 'puo_amm_entrare' => true],
                ['fsl', 'didattica', 'forms', 'amministrazione', 'persone', 'sito', 'sistema']],
            'abilitato Didattica' => [['puo_didattica' => true, 'puo_didattica_tutto' => true, 'puo_conv_tesi' => true, 'puo_tir_motorie' => true, 'puo_tutorato' => true], ['didattica', 'persone']],
            'operatore Assistenza' => [['puo_assistenza' => true], ['didattica']],
            'solo Scuole FSL' => [['puo_fsl_scuole' => true], ['fsl']],
            'referente di un consiglio' => [['puo_didattica' => true], ['didattica']],
        ];
    }

    /**
     * @dataProvider profili
     *
     * @param array<string, bool> $permessi
     * @param list<string>        $attesi
     */
    public function testModuliVisibiliPerProfilo(array $permessi, array $attesi): void
    {
        $visti = MenuModuli::moduliVisibili($permessi);
        sort($visti);
        sort($attesi);
        self::assertSame($attesi, $visti);
    }

    public function testChiEntravaNelPannelloCiEntraAncora(): void
    {
        // La regola di prima, copiata: moduli senza aree che davano un ingresso (portale = amministratore o abilitato alle scuole)
        $nomi = ['is_full_admin', 'puo_fsl_convenzioni', 'puo_fsl_scuole', 'puo_didattica', 'puo_conv_tesi', 'puo_assistenza', 'puo_tir_motorie', 'puo_forms_vedere', 'puo_amm_entrare'];
        $n = count($nomi);
        for ($mask = 0; $mask < (1 << $n); $mask++) {
            $p = [];
            foreach ($nomi as $i => $nome) {
                $p[$nome] = (bool)($mask & (1 << $i));
            }
            $prima = $p['is_full_admin'] || $p['puo_fsl_scuole'] || $p['puo_fsl_convenzioni'] || $p['puo_didattica'] || $p['puo_conv_tesi'] || $p['puo_assistenza'] || $p['puo_tir_motorie'] || $p['puo_forms_vedere'] || $p['puo_amm_entrare'];
            $dopo = MenuModuli::moduliVisibili($p) !== [];
            self::assertSame($prima, $dopo, 'ingresso nel pannello diverso per ' . json_encode(array_keys(array_filter($p))));
        }
    }

    public function testScuoleEUfficiHannoLoStessoPermessoDiPrima(): void
    {
        $id = static fn(string $modulo, array $p): array => array_column(MenuModuli::vociVisibili($modulo, $p), 'id');
        self::assertSame(['scuole'], $id('fsl', ['puo_fsl_scuole' => true]), 'Scuole: chi ha il permesso scuole');
        self::assertNotContains('scuole', $id('fsl', ['puo_fsl_convenzioni' => true]), 'chi ha solo le convenzioni non vede Scuole');
        // Uffici e persone: una voce sola, per l'amministratore e per chi lavora in tutta la Didattica
        self::assertContains('uffici', $id('persone', ['puo_didattica_tutto' => true]));
        self::assertSame(['uffici'], $id('persone', ['puo_didattica_tutto' => true]), 'la Didattica non apre altro di Persone e permessi');
        self::assertNotContains('uffici', $id('didattica', ['is_full_admin' => true, 'puo_didattica_tutto' => true, 'puo_didattica' => true]), 'Uffici non è più nella Didattica');
        self::assertSame([], $id('persone', ['puo_didattica' => true]), 'il referente di un consiglio non vede gli uffici (come prima)');
        self::assertSame([], $id('sito', ['puo_didattica_tutto' => true]));
        self::assertSame([], $id('sistema', ['puo_didattica_tutto' => true]));
    }

    public function testLaDidatticaHaQuattroGruppiConLeStesseVociDiPrima(): void
    {
        self::assertSame(['pratiche', 'tirocini', 'tutorato', 'sportello'], array_keys(MenuModuli::gruppi('didattica')));
        $per_gruppo = [];
        foreach (MenuModuli::voci('didattica') as $v) {
            self::assertArrayHasKey($v['gruppo'] ?? '', MenuModuli::gruppi('didattica'), "didattica/{$v['id']}: gruppo mancante o sconosciuto");
            $per_gruppo[$v['gruppo']][] = $v['testo'];
        }
        self::assertSame(['Cosa devo fare', 'Pratiche degli studenti', 'Sedute e verbali', 'Moduli e documenti', 'Statistiche'], $per_gruppo['pratiche']);
        self::assertSame(['Tirocini', 'Tirocini Scienze motorie', 'Convenzioni di tesi'], $per_gruppo['tirocini']);
        self::assertSame(['Tutorato · lettere di incarico', 'Sostituzioni'], $per_gruppo['tutorato']);
        self::assertSame(['Ricevimento', 'Assistenza', 'Domande e risposte'], $per_gruppo['sportello']);
        // Il referente di un consiglio vede solo le sedute, col suo nome
        $sedute = array_values(array_filter(MenuModuli::voci('didattica'), fn($v) => $v['id'] === 'sedute'))[0];
        self::assertSame('Sedute del consiglio', MenuModuli::testo($sedute, ['puo_didattica' => true]));
        self::assertSame('Sedute e verbali', MenuModuli::testo($sedute, ['puo_didattica' => true, 'puo_didattica_tutto' => true]));
    }

    public function testIlVecchioIndirizzoDelPortaleSiRimandaDoveServe(): void
    {
        self::assertSame('persone', MenuModuli::moduloDelVecchioPortale(['is_full_admin' => true]));
        self::assertSame('fsl', MenuModuli::moduloDelVecchioPortale(['puo_fsl_scuole' => true]));
        self::assertSame('', MenuModuli::moduloDelVecchioPortale(['puo_didattica' => true]));
    }
}
