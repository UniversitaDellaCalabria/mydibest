<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Auth\Permessi;
use App\Core\App;
use App\Http\Richiesta;
use App\Portale\Pannello\ContestoPannello;
use Tests\Doppi\AuthSessioneInMemoria;
use Tests\Integration\Auth\ProfiliPannelloBase;

/**
 * La rete di sicurezza di admin/admin_header.php: la testata e App\Portale\Pannello\ContestoPannello devono dare la STESSA fotografia di chi sta
 * aprendo il pannello e in quale area, per tutti i 23 profili di PermessiTest e per ogni area che possono aprire.
 *
 * Come funziona: la testata è uno script che mescola calcolo e HTML, quindi non si può includere in una prova. Ma il pezzo che assegna le variabili
 * ($utente_admin, $filtro_p, $page_cfg, $pagine_disponibili, $permessi, $is_full_admin, $puo_*, $can_manage_*…) sta fra due marcatori
 * («[confronto-testata:inizio]» e «[confronto-testata:fine]»): la prova prende quelle righe dal file VERO, le esegue per ciascuna persona e legge
 * le variabili che ne escono. Se i marcatori spariscono la prova non si salta: fallisce (una rete che non c'è non deve sembrare una rete).
 *
 * Coincidono: l'utente (tutta la riga), l'area aperta, la riga dell'area, le aree disponibili, «è amministratore», TUTTI i permessi dichiarati,
 * le attività permesse, i consigli del referente e i moduli. Per chi non è collegato la testata risponde con un rimando e termina lo script:
 * lo copre la prova HTTP (strumenti/prove/oop/pagine_contatti.php), qui si controlla solo che ContestoPannello dica «non collegata».
 */
final class ContestoPannelloTest extends ProfiliPannelloBase
{
    /** @var array<string, mixed> i superglobali com'erano prima della prova */
    private array $sessionePrima = [];
    private array $getPrima = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionePrima = $_SESSION ?? [];
        $this->getPrima = $_GET;
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionePrima;
        $_GET = $this->getPrima;
        parent::tearDown();
    }

    /** Le righe della testata fra i due marcatori, come codice da eseguire. */
    private static function pezzoDiTestata(): string
    {
        $sorgente = (string) file_get_contents(RADICE_SITO . '/admin/admin_header.php');
        $da = strpos($sorgente, '// [confronto-testata:inizio]');
        $a = strpos($sorgente, '// [confronto-testata:fine]');
        if ($da === false || $a === false || $a <= $da) {
            self::fail('admin/admin_header.php non ha più i marcatori «[confronto-testata:inizio]» e «[confronto-testata:fine]»: la prova di confronto non ha niente da eseguire.');
        }

        return substr($sorgente, $da, $a - $da);
    }

    /**
     * Esegue il pezzo di testata per una persona collegata e restituisce le variabili che ha prodotto.
     *
     * @return array<string, mixed>
     */
    private function eseguiTestata(array $utente, int $areaRichiesta): array
    {
        $conn = self::$conn; // la testata lo trova in config.php
        $_SESSION['utente_id'] = (int) $utente['id'];
        $_GET = $areaRichiesta !== 0 ? ['p_id' => (string) $areaRichiesta] : [];
        eval(self::pezzoDiTestata());
        $variabili = get_defined_vars();
        unset($variabili['conn']);

        return $variabili;
    }

    private function contesto(array $utente): ContestoPannello
    {
        $sessione = new AuthSessioneInMemoria();
        $sessione->scrivi('utente_id', (int) $utente['id']);

        return $this->contestoPannello($sessione);
    }

    private static function richiesta(int $area): Richiesta
    {
        return Richiesta::finta('GET', '/admin/contatti.php', $area !== 0 ? ['p_id' => (string) $area] : []);
    }

    /** @param Permessi $p @return array<string, bool> i dichiarati con le funzioni già chiamate */
    private static function dichiaratiRisolti(Permessi $p): array
    {
        return array_map(static fn ($v): bool => $v instanceof \Closure ? (bool) $v() : $v, $p->dichiarati());
    }

    // ───────────────────────── Per tutti i profili, per ogni area ─────────────────────────

    public function testLaTestataEContestoPannelloDannoLaStessaFotografiaPerTuttiIProfili(): void
    {
        $this->assertCount(23, $this->utenti, 'i profili di prova sono 23: se cambiano, cambia anche questa rete');
        $confronti = 0;
        $areeAperte = [];

        foreach ($this->utenti as $profilo => $u) {
            // le aree che la persona può aprire, più quelle che non può (12, un numero che non esiste, uno negativo) e nessuna
            App::avvia(self::$conn);
            $disponibili = array_map('intval', array_column($this->contesto($u)->areeDisponibili(self::richiesta(0)), 'id'));
            foreach (array_unique(array_merge([0], $disponibili, [10, 11, 12, 99999, -3])) as $richiesta) {
                $dove = "profilo «{$profilo}», area richiesta {$richiesta}";

                App::avvia(self::$conn); // niente memoria da un calcolo all'altro: le due strade partono dallo stesso database
                $h = $this->eseguiTestata($u, $richiesta);
                App::avvia(self::$conn);
                $ctx = $this->contesto($u);
                $r = self::richiesta($richiesta);
                $p = $ctx->permessi($r);

                $this->assertNotNull($p, $dove);
                $this->assertTrue($ctx->collegata($r), $dove);
                $this->assertSame($h['utente_admin'], $ctx->utente($r), "$dove: l'utente");
                $this->assertSame($h['filtro_p'], $ctx->area($r), "$dove: l'area aperta (\$filtro_p)");
                $this->assertSame($h['page_cfg'], $ctx->rigaArea($r), "$dove: la riga dell'area (\$page_cfg)");
                $this->assertSame($h['pagine_disponibili'], $ctx->areeDisponibili($r), "$dove: le aree disponibili");
                $this->assertSame($h['is_full_admin'], $p->eAmministratore(), "$dove: è amministratore");

                // I permessi: l'oggetto della testata e quello di ContestoPannello, in ogni forma in cui qualcuno li legge
                $this->assertInstanceOf(Permessi::class, $h['permessi']);
                $this->assertSame($h['permessi']->elenco(), $p->elenco(), "$dove: elenco() dei permessi");
                $this->assertSame(self::dichiaratiRisolti($h['permessi']), self::dichiaratiRisolti($p), "$dove: permessi dichiarati");
                $this->assertSame($h['permessi']->attivitaPermesse(), $p->attivitaPermesse(), "$dove: attività permesse");
                $this->assertSame($h['permessi']->consigliReferente(), $p->consigliReferente(), "$dove: consigli del referente");
                $this->assertSame($h['permessi']->moduli(), $p->moduli(), "$dove: moduli");

                // E le variabili `$puo_*` / `$can_manage_*` che le pagine leggono con global: una per ogni permesso che la testata mette in una variabile
                $esposti = 0;
                foreach (Permessi::chiavi() as $nome) {
                    if (!array_key_exists($nome, $h)) {
                        continue; // puo_orientamento, ha_caselle, ha_sezioni: la testata non li espone come variabili, li legge dal Permessi
                    }
                    ++$esposti;
                    $this->assertSame($p->elenco()[$nome], $h[$nome], "$dove: la variabile \${$nome} della testata");
                }
                $this->assertGreaterThanOrEqual(24, $esposti, "$dove: la testata espone come variabili almeno 24 permessi");
                $this->assertSame($p->attivitaPermesse(), $h['allowed_events_ids'], "$dove: \$allowed_events_ids");
                $this->assertSame($p->consigliReferente(), $h['consigli_referente'], "$dove: \$consigli_referente");

                ++$confronti;
                $areeAperte[$profilo][$ctx->area($r)] = true;
            }
        }

        // almeno sei aree richieste (0, 10, 11, 12, un numero che non esiste, uno negativo) per ciascuno dei 23 profili
        $this->assertGreaterThanOrEqual(23 * 6, $confronti, 'la prova deve confrontare tutte le combinazioni profilo × area');
        // Fatti noti: la prova guarda davvero aree diverse (non sempre la prima)
        $this->assertSame([10 => true, 11 => true, 12 => true], $areeAperte['admin'], 'l\'amministratore apre ciascuna delle tre aree, o la prima');
        $this->assertSame([0 => true], $areeAperte['docente'], 'il docente non ha aree');
        $this->assertSame([10 => true], $areeAperte['gestore_area']);
    }

    public function testChiNonECollegatoNonHaPermessiNeArea(): void
    {
        // nessuna sessione, e una sessione che punta a una persona cancellata
        foreach ([0, 99999] as $id) {
            $sessione = new AuthSessioneInMemoria();
            $sessione->scrivi('utente_id', $id);
            $ctx = $this->contestoPannello($sessione);
            $r = self::richiesta(10);
            $this->assertFalse($ctx->collegata($r), "id $id");
            $this->assertNull($ctx->utente($r));
            $this->assertNull($ctx->permessi($r));
            $this->assertNull($ctx->rigaArea($r));
            $this->assertSame([], $ctx->areeDisponibili($r));
            $this->assertSame(0, $ctx->area($r));
        }
    }

    public function testLaFotografiaSiCalcolaUnaVoltaSolaPerAreaRichiesta(): void
    {
        $ctx = $this->contesto($this->utenti['gestore_area']);
        $r = self::richiesta(10);
        $this->assertSame($ctx->permessi($r), $ctx->permessi($r), 'stessa richiesta: lo stesso oggetto');
        $this->assertNotSame($ctx->permessi($r), $ctx->permessi(self::richiesta(12)), 'un\'altra area richiesta è un altro calcolo');
    }
}
