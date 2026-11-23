<?php

declare(strict_types=1);

namespace Tests\Unit\Portale\Instradamento;

use PHPUnit\Framework\TestCase;

/**
 * La regola «3b» del .htaccess e il suo gemello del server locale (strumenti/locale/router.php) mandano a nucleo.php solo gli indirizzi con una «/»
 * che non sono un file. Qui si guarda il TESTO delle regole (la prova con un Apache vero è strumenti/prove/apache_nucleo.sh): dove stanno, cosa
 * lasciano fuori, e che nucleo.php resti un ingresso corto che non apre database e sessione per un 404.
 */
final class RegolaNucleoTest extends TestCase
{
    private static function htaccess(): string
    {
        return (string) file_get_contents(RADICE_SITO . '/.htaccess');
    }

    /** Il motivo della regola 3b così com'è scritto nel .htaccess. */
    private static function motivo(): string
    {
        self::assertSame(1, preg_match('/^RewriteRule \^(\(\?!.*?\$) nucleo\.php \[L\]$/m', self::htaccess(), $m), 'la regola che manda a nucleo.php c\'è, una sola');

        return '^' . $m[1];
    }

    private static function corrisponde(string $indirizzo): bool
    {
        return preg_match('#' . str_replace('#', '\#', self::motivo()) . '#', $indirizzo) === 1;
    }

    public function testLaRegolaStaDopoLaTreEPrimaDellaQuattro(): void
    {
        $src = self::htaccess();
        $tre = strpos($src, '# 3. SE MANCA L\'ESTENSIONE');
        $tre_b = strpos($src, 'nucleo.php [L]');
        $quattro = strpos($src, 'area.php?slug=$1');

        self::assertNotFalse($tre);
        self::assertNotFalse($tre_b);
        self::assertNotFalse($quattro);
        self::assertGreaterThan($tre, $tre_b, 'dopo la regola che aggiunge .php (le pagine di oggi non ci arrivano)');
        self::assertLessThan($quattro, $tre_b, 'prima del motore delle aree (area.php), che altrimenti prende tutto');
    }

    public function testLaCartellaAdminSiFermaPrimaDellaRegolaDelNucleo(): void
    {
        $src = self::htaccess();
        $admin = strpos($src, 'RewriteRule ^admin(/|$) - [NC,L]');

        self::assertNotFalse($admin, 'regola 0: dentro admin/ non si legge nient\'altro (le pagine del pannello non passano mai dalla 3b)');
        self::assertLessThan((int) strpos($src, 'nucleo.php [L]'), $admin);
    }

    public function testLaRegolaVuoleCheNonSiaUnFileNeUnaCartella(): void
    {
        $src = self::htaccess();
        $posizione = (int) strpos($src, 'nucleo.php [L]');
        $prima = substr($src, max(0, $posizione - 260), 260);

        self::assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-f', $prima);
        self::assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-d', $prima);
    }

    /** @dataProvider indirizziDelNucleo */
    public function testMandaAlNucleoGliIndirizziNuoviConUnaBarra(string $indirizzo): void
    {
        self::assertTrue(self::corrisponde($indirizzo), $indirizzo);
    }

    /** @return array<string, array{string}> */
    public static function indirizziDelNucleo(): array
    {
        return ['due pezzi' => ['pagine/nuova'], 'tre pezzi' => ['pagine/nuova/sotto'], 'con il punto' => ['qualcosa/di_nuovo.html'], 'trattini' => ['il-mio/percorso_x']];
    }

    /** @dataProvider indirizziDiOggi */
    public function testLasciaStareGliIndirizziDiOggi(string $indirizzo): void
    {
        self::assertFalse(self::corrisponde($indirizzo), $indirizzo);
    }

    /** @return array<string, array{string}> */
    public static function indirizziDiOggi(): array
    {
        return [
            'un solo pezzo (area)' => ['openlab'], 'un pezzo con .php' => ['contatti.php'], 'barra finale' => ['contatti/'],
            'immagine che manca' => ['assets/img/manca.png'], 'upload che manca' => ['uploads/x/manca.pdf'], 'cache' => ['cache/x/y'],
            'sorgenti' => ['src/Http/Nucleo.php'], 'inc' => ['inc/base.php'], 'prove' => ['tests/Unit/x'], 'strumenti' => ['strumenti/prove/esegui.php'],
            'database' => ['database/x/y'], 'backups' => ['backups/x/y'], 'modelli' => ['modelli_documenti/a/b.docx'], 'vendor' => ['vendor/autoload.php'],
        ];
    }

    public function testIlRouterLocaleHaLoStessoElencoDiEsclusioniPiuAdmin(): void
    {
        $router = (string) file_get_contents(RADICE_SITO . '/strumenti/locale/router.php');

        self::assertStringContainsString("is_file(\$__r_radice . '/nucleo.php')", $router, 'senza nucleo.php il router locale torna a prima');
        self::assertSame(1, preg_match('/\(\?\!\(\?:([a-z_|]+)\)\//', self::motivo(), $htaccess));
        self::assertSame(1, preg_match('/\(\?\!\(\?:admin\|([a-z_|]+)\)\//', $router, $locale), 'il router esclude anche admin/ (nel .htaccess lo fa la regola 0)');
        self::assertSame($htaccess[1], $locale[1], 'le due liste di esclusioni devono essere uguali');
    }

    public function testNucleoPhpEUnIngressoCortoCheNonApreIlDatabasePerUn404(): void
    {
        $src = (string) file_get_contents(RADICE_SITO . '/nucleo.php');
        $righe = array_filter(explode("\n", $src), static fn (string $r): bool => trim($r) !== '' && !str_starts_with(trim($r), '//'));

        self::assertLessThan(30, count($righe), 'nucleo.php sono due dozzine di righe, non una pagina');
        self::assertLessThan(
            (int) strpos($src, 'config.php'),
            (int) strpos($src, 'nonTrovata()'),
            'il 404 di un indirizzo sconosciuto arriva prima di config.php (database e sessione): un bot che prova indirizzi non costa una connessione'
        );
    }
}
