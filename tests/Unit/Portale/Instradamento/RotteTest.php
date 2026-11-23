<?php

declare(strict_types=1);

namespace Tests\Unit\Portale\Instradamento;

use App\Auth\Csrf;
use App\Auth\Flash;
use App\Auth\Sessione;
use App\Core\Container;
use App\Http\Avvisi;
use App\Http\ControlloAccesso;
use App\Http\Nucleo;
use App\Http\Pagina;
use App\Http\Richiesta;
use App\Http\ProtezioneCsrf;
use App\Http\Risposta;
use App\Http\Rotta;
use App\Portale\Instradamento\AccessoPortale;
use App\Portale\Instradamento\Rotte;
use App\Portale\Pannello\PermessiPagine;
use App\Portale\Registrazione;
use PHPUnit\Framework\TestCase;
use Tests\Doppi\AuthSessioneInMemoria;

/**
 * La tabella delle rotte del portale (src/Portale/Instradamento/Rotte.php): fuori da src/Http perché è un elenco di pagine del portale.
 * Qui si impedisce che una rotta esista «per distrazione»: ogni rotta del pannello deve risolvere a una pagina d'ingresso di PermessiPagine
 * (la fonte unica di chi apre cosa), ogni rotta ha un file vero con il suo guscio, e nessun indirizzo è di due rotte.
 */
final class RotteTest extends TestCase
{
    /** @return list<Rotta> */
    private static function rotte(): array
    {
        return Rotte::elenco();
    }

    public function testLaTabellaNonEVuotaEIPercorsiSonoDiversi(): void
    {
        $percorsi = array_map(static fn (Rotta $r): string => $r->percorso, self::rotte());

        self::assertNotSame([], $percorsi);
        self::assertSame($percorsi, array_values(array_unique($percorsi)));
    }

    public function testOgniRottaDelPannelloRisolveAUnaPaginaDIngressoDiPermessiPagine(): void
    {
        $viste = 0;
        foreach (self::rotte() as $r) {
            if (!AccessoPortale::eDelPannello($r)) {
                continue;
            }
            ++$viste;
            $file = substr($r->file, strlen('admin/'));

            self::assertStringNotContainsString('/', $file, "{$r->percorso}: solo le pagine del primo livello di admin/ sono pagine d'ingresso");
            self::assertSame('pagina', PermessiPagine::tipo($file), "{$r->percorso} ($file): una rotta senza permesso dichiarato non deve poter esistere");
            self::assertNotNull(PermessiPagine::regola($file), "{$r->percorso}: manca la regola in PermessiPagine");
        }
        self::assertGreaterThan(0, $viste, 'la tabella ha almeno una rotta del pannello (altrimenti questa prova non prova niente)');
    }

    public function testOgniRottaHaUnFileVeroEUnGuscioCheChiamaIlNucleo(): void
    {
        foreach (self::rotte() as $r) {
            self::assertFileExists(RADICE_SITO . '/' . $r->file, "{$r->percorso}: il file di oggi non c'è più, e il suo indirizzo è morto");
            $src = (string) file_get_contents(RADICE_SITO . '/' . $r->file);

            self::assertStringContainsString('Nucleo::serviFile(__FILE__)', $src, "{$r->percorso}: il file vecchio deve essere un guscio");
            self::assertLessThan(1200, strlen($src), "{$r->percorso}: un guscio sono poche righe, non una pagina");
        }
    }

    public function testOgniPaginaEUnaPaginaDelNucleo(): void
    {
        foreach (self::rotte() as $r) {
            self::assertTrue(class_exists($r->pagina), "{$r->percorso}: la classe {$r->pagina} non esiste");
            self::assertTrue(is_subclass_of($r->pagina, Pagina::class), "{$r->percorso}: {$r->pagina} non implementa App\\Http\\Pagina");
            self::assertNotSame('', trim($r->titolo));
        }
    }

    /**
     * I link delle due testate sono relativi (href="index.php"): una rotta che ha il layout e un percorso a profondità diversa da quella del suo
     * file aprirebbe link sbagliati. Finché le testate non usano l'indirizzo del sito, il percorso di una rotta con un file di oggi è il file stesso.
     */
    public function testIlPercorsoDiUnaRottaStaAllaStessaProfonditaDelSuoFile(): void
    {
        foreach (self::rotte() as $r) {
            self::assertSame(dirname($r->file), dirname($r->percorso . '.php'), "{$r->percorso}: profondità diversa dal file {$r->file}");
        }
    }

    public function testNessunIndirizzoEDiDueRotte(): void
    {
        $accesso = new class implements ControlloAccesso {
            public function verifica(Rotta $rotta, Richiesta $richiesta): bool|Risposta
            {
                return true;
            }
        };

        $csrf = new class implements ProtezioneCsrf {
            public function valido(mixed $ricevuto): bool
            {
                return true;
            }
        };
        $avvisi = new class implements Avvisi {
            public function html(): string
            {
                return '';
            }
        };
        $nucleo = new Nucleo(new Container(), self::rotte(), $accesso, $csrf, $avvisi, RADICE_SITO); // il costruttore rifiuta indirizzi e file doppi

        self::assertCount(count(self::rotte()), $nucleo->rotte());
    }

    public function testLeRottePubblicheStannoFuoriDalPannello(): void
    {
        foreach (self::rotte() as $r) {
            if (AccessoPortale::eDelPannello($r)) {
                continue;
            }
            self::assertStringNotContainsString('admin', $r->percorso, "{$r->percorso}: una rotta pubblica non può avere un percorso da pannello");
        }
    }

    /** Nessuna rotta di oggi rinuncia al token: lo farebbe solo un webhook, con il suo motivo scritto (Rotta::$senzaCsrf). */
    public function testNessunaRottaDiOggiRinunciaAlTokenCsrf(): void
    {
        foreach (self::rotte() as $r) {
            self::assertNull($r->senzaCsrf, "{$r->percorso}: dichiara di fare a meno del token CSRF («{$r->senzaCsrf}»). Un webhook sì, una pagina con un modulo mai.");
        }
    }

    public function testAccessoPortaleSaQualiRotteSonoDelPannello(): void
    {
        self::assertTrue(AccessoPortale::eDelPannello(new Rotta('admin/x', Rotte::elenco()[0]->pagina, 'X', 'admin/x.php')));
        self::assertFalse(AccessoPortale::eDelPannello(new Rotta('x', Rotte::elenco()[0]->pagina, 'X', 'x.php')));
        self::assertFalse(AccessoPortale::eDelPannello(new Rotta('x', Rotte::elenco()[0]->pagina, 'X', 'amministrazione.php')), 'una cartella che comincia come admin non è admin/');
    }

    /** Il container del portale sa costruire il Nucleo con la tabella vera (il controllo dell'accesso, che vuole il database, qui è finto). */
    public function testIlContainerDelPortaleCostruisceIlNucleoConLaTabellaVera(): void
    {
        $c = new Container();
        Registrazione::registra($c);
        $c->istanza(Sessione::class, new AuthSessioneInMemoria()); // la sessione vera (PHP) non serve a questa prova: Csrf e Flash la vogliono nel costruttore
        $c->istanza(ControlloAccesso::class, new class implements ControlloAccesso {
            public function verifica(Rotta $rotta, Richiesta $richiesta): bool|Risposta
            {
                return true;
            }
        });

        self::assertInstanceOf(Csrf::class, $c->get(ProtezioneCsrf::class), 'il token è quello di App\\Auth\\Csrf');
        self::assertInstanceOf(Flash::class, $c->get(Avvisi::class), 'gli avvisi sono quelli di App\\Auth\\Flash');
        $nucleo = $c->get(Nucleo::class);

        self::assertInstanceOf(Nucleo::class, $nucleo);
        self::assertSame('admin/contatti', $nucleo->trova('admin/contatti.php')?->percorso);
        self::assertSame($c->get(Nucleo::class), $nucleo, 'uno solo per richiesta');
    }
}
