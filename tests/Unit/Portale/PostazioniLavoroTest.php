<?php

declare(strict_types=1);

namespace Tests\Unit\Portale;

use App\Portale\Area\PostazioniLavoro;
use PHPUnit\Framework\TestCase;

/** La postazione «Richieste di pubblicazione» del riquadro «Il mio lavoro» dell'area personale. */
final class PostazioniLavoroTest extends TestCase
{
    /** @param list<array<string, mixed>> $elenco @return array<string, mixed>|null */
    private function postazione(array $elenco): ?array
    {
        foreach ($elenco as $v) {
            if ($v['chiave'] === 'pubblicazioni') {
                return $v;
            }
        }

        return null;
    }

    public function testCompareSoloConIlPermesso(): void
    {
        self::assertNull($this->postazione(PostazioniLavoro::elenco([], ['pubblicazioni_da_guardare' => 5])), 'senza permesso la postazione non c\'è, qualunque sia il numero');
        self::assertNull($this->postazione(PostazioniLavoro::elenco(['didattica_tutto' => true, 'didattica' => true, 'aree' => true], ['pubblicazioni_da_guardare' => 5])));
        self::assertNotNull($this->postazione(PostazioniLavoro::elenco(['pubblicazioni' => true], [])));
        self::assertSame([], PostazioniLavoro::elenco([], []), 'chi non gestisce niente non vede il riquadro');
    }

    public function testIlNumeroEQuelloDelleRichiesteDaGuardare(): void
    {
        $v = $this->postazione(PostazioniLavoro::elenco(['pubblicazioni' => true], ['pubblicazioni_da_guardare' => 3, 'pubblicazioni_giorni' => 2, 'pubblicazioni_da_completare' => 4]));
        self::assertSame(3, $v['novita']);
        self::assertSame('richieste da guardare', $v['novita_et']);
        self::assertSame('admin/pubblicazioni.php?vista=arrivate', $v['href']);
        self::assertSame('Richieste di pubblicazione', $v['titolo']);
        self::assertStringContainsString('4 righe del file Excel da completare', $v['testo'], 'le righe da completare stanno nel testo');
        // pulsanti: «Da guardare» col numero e senza avviso (arrivate da poco), «Tabella di monitoraggio» con le righe da completare
        self::assertSame(['Da guardare', 'admin/pubblicazioni.php?vista=arrivate', false, false, 3], $v['altri'][0]);
        self::assertSame('admin/pubblicazioni.php?vista=monitoraggio', $v['altri'][1][1]);
        self::assertSame(4, $v['altri'][1][4]);
    }

    public function testSingolareESenzaRigheDaCompletare(): void
    {
        $v = $this->postazione(PostazioniLavoro::elenco(['pubblicazioni' => true], ['pubblicazioni_da_guardare' => 1, 'pubblicazioni_da_completare' => 1]));
        self::assertSame('richiesta da guardare', $v['novita_et']);
        self::assertStringContainsString('1 riga del file Excel da completare', $v['testo']);

        $v = $this->postazione(PostazioniLavoro::elenco(['pubblicazioni' => true], []));
        self::assertSame(0, $v['novita']);
        self::assertStringNotContainsString('da completare', $v['testo'], 'senza righe da completare il testo non ne parla');
    }

    public function testLaRichiestaFermaDa7GiorniSiSegnalaInEvidenza(): void
    {
        $da_poco = $this->postazione(PostazioniLavoro::elenco(['pubblicazioni' => true], ['pubblicazioni_da_guardare' => 2, 'pubblicazioni_giorni' => 6]));
        $ferma = $this->postazione(PostazioniLavoro::elenco(['pubblicazioni' => true], ['pubblicazioni_da_guardare' => 2, 'pubblicazioni_giorni' => PostazioniLavoro::GIORNI_FERMA]));
        self::assertFalse($da_poco['altri'][0][3]);
        self::assertTrue($ferma['altri'][0][3]);
    }

    public function testIlColoreArrivaDaChiChiamaSenzaNumeriScrittiAMano(): void
    {
        $v = $this->postazione(PostazioniLavoro::elenco(['pubblicazioni' => true], [], [], ['amministrazione' => '#123456']));
        self::assertSame('#123456', $v['colore']);
        $senza = $this->postazione(PostazioniLavoro::elenco(['pubblicazioni' => true], []));
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $senza['colore'], 'senza colore passato si usa uno neutro, valido');
    }

    public function testLaPostazioneConviveConLeAltreESiTrovaUnaVolta(): void
    {
        $tutto = PostazioniLavoro::elenco(array_fill_keys(PostazioniLavoro::PERMESSI, true), []);
        self::assertCount(1, array_filter($tutto, fn (array $x): bool => $x['chiave'] === 'pubblicazioni'));
        self::assertSame(count($tutto), count(array_unique(array_column($tutto, 'chiave'))), 'ogni postazione una volta sola');
        self::assertContains('pubblicazioni', PostazioniLavoro::PERMESSI);
        foreach (['pubblicazioni_da_guardare', 'pubblicazioni_giorni', 'pubblicazioni_da_completare'] as $n) {
            self::assertContains($n, PostazioniLavoro::NUMERI);
        }
    }
}
