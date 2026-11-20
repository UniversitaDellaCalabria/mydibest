<?php

declare(strict_types=1);

namespace Tests\Unit\Pubblicazioni;

use App\Pubblicazioni\ConfigurazioneCampi;
use PHPUnit\Framework\TestCase;

/**
 * I campi e le colonne nuovi (AM5) dentro ConfigurazioneCampi, senza database: dove stanno nella pagina e nel file Excel.
 * - un campo nuovo sta in fondo al suo passo; la sua colonna Excel (se la vuole) sta dopo la colonna dell'ultimo campo del passo che ne ha una;
 * - una colonna nuova degli operatori sta dopo la colonna scelta (se sparita o in un giro: in fondo, senza perdersi);
 * - un campo nascosto non ha la colonna nel file; le chiavi x_<n> e y_<n> si riconoscono.
 */
final class CampiNuoviOrdineTest extends TestCase
{
    /** @param array<string, mixed> $cambi @return array<string, mixed> */
    private function campo(int $n, array $cambi = []): array
    {
        return array_merge(['chiave' => 'x_' . $n, 'etichetta' => 'Campo ' . $n, 'aiuto' => null, 'obbligatorio' => 0, 'visibile' => null, 'ordine' => null,
            'tipo' => 'text', 'opzioni' => null, 'passo' => 3, 'in_excel' => 1, 'intestazione_excel' => 'Colonna ' . $n], $cambi);
    }

    /** @param array<string, mixed> $cambi @return array<string, mixed> */
    private function colonna(int $n, array $cambi = []): array
    {
        return array_merge(['chiave' => 'y_' . $n, 'intestazione' => 'Operatori ' . $n, 'a_cura_di' => 'Ufficio', 'colore' => '#FF0000', 'tipo' => 'testo', 'visibile' => null,
            'ordine' => null, 'dopo' => null], $cambi);
    }

    /** @param list<array<string, mixed>> $campi @param list<array<string, mixed>> $colonne */
    private function config(array $campi = [], array $colonne = []): ConfigurazioneCampi
    {
        return new ConfigurazioneCampi(null, $campi, $colonne);
    }

    private function posizione(ConfigurazioneCampi $c, string $chiave): int
    {
        $p = array_search($chiave, $c->chiaviExcel(), true);
        $this->assertNotFalse($p, $chiave . ' dovrebbe essere nel file Excel');

        return (int) $p;
    }

    public function testIRiconoscimentoDelleChiaviNuove(): void
    {
        $this->assertTrue(ConfigurazioneCampi::eCampoNuovo('x_1'));
        $this->assertTrue(ConfigurazioneCampi::eCampoNuovo('x_12345'));
        $this->assertFalse(ConfigurazioneCampi::eCampoNuovo('x_0'));
        $this->assertFalse(ConfigurazioneCampi::eCampoNuovo('x_1; DROP'));
        $this->assertFalse(ConfigurazioneCampi::eCampoNuovo('y_1'));
        $this->assertTrue(ConfigurazioneCampi::eColonnaNuova('y_7'));
        $this->assertFalse(ConfigurazioneCampi::eColonnaNuova('x_7'));
        $this->assertFalse(ConfigurazioneCampi::eColonnaNuova('titolo'));
    }

    public function testSenzaCampiNuoviNienteCambia(): void
    {
        $this->assertSame([], $this->config()->campiExtra());
        $this->assertSame([], $this->config()->colonneNuove());
        $this->assertSame(array_column($this->config()->colonne(), 'chiave'), $this->config()->chiaviExcel());
    }

    public function testUnCampoNuovoStaInFondoAlSuoPassoELaSuaColonnaDopoLUltimoCampoDelPasso(): void
    {
        $c = $this->config([$this->campo(1, ['passo' => 3])]);
        $passo3 = array_values(array_map(static fn (array $f): string => $f['chiave'], $c->campiDelForm(3)));
        $this->assertSame('x_1', end($passo3), 'in fondo al passo 3');
        $this->assertSame($this->posizione($c, 'descrizione') + 1, $this->posizione($c, 'x_1'), 'subito dopo la colonna della descrizione, l\'ultimo campo del passo');
        $this->assertSame('Colonna 1', $c->intestazioniExcel()[$this->posizione($c, 'x_1')]);
    }

    public function testPiuCampiNuoviDelloStessoPassoSiSeguonoNellOrdineDiCreazione(): void
    {
        $c = $this->config([$this->campo(2, ['passo' => 3]), $this->campo(1, ['passo' => 3])]);
        $this->assertSame($this->posizione($c, 'x_1') + 1, $this->posizione($c, 'x_2'));
        $this->assertSame($this->posizione($c, 'descrizione') + 1, $this->posizione($c, 'x_1'));
    }

    public function testUnCampoNuovoSenzaColonnaExcelNonLaOttiene(): void
    {
        $c = $this->config([$this->campo(1, ['in_excel' => 0])]);
        $this->assertNotContains('x_1', $c->chiaviExcel());
        $this->assertCount(1, $c->campiExtra(), 'ma il campo c\'è nel form');
    }

    public function testUnCampoNuovoNascostoNonHaLaColonnaENonSiChiede(): void
    {
        $c = $this->config([$this->campo(1, ['visibile' => 0])]);
        $this->assertNotContains('x_1', $c->chiaviExcel());
        $this->assertContains('x_1', $c->nascosti());
        $this->assertFalse($c->regole()['x_1']['obbligatorio']);
        $this->assertSame([], array_filter($c->campiDelForm(3), static fn (array $f): bool => $f['chiave'] === 'x_1'));
    }

    public function testUnCampoNuovoNelPrimoOUltimoPassoESenzaCampiSeNeServeIlPasso(): void
    {
        $c = $this->config([$this->campo(1, ['passo' => 1]), $this->campo(2, ['passo' => 7])]);
        $this->assertSame(['x_1'], array_column($c->campiDelForm(1), 'chiave'));
        $this->assertSame(['x_2'], array_column($c->campiDelForm(7), 'chiave'));
        $this->assertSame(1, $c->regole()['x_1']['passo']);
        $this->assertTrue($c->regole()['x_1']['extra']);
    }

    public function testUnPassoNonValidoDiventaIlPassoSei(): void
    {
        $c = $this->config([$this->campo(1, ['passo' => 99])]);
        $this->assertSame(6, $c->campiExtra()[0]['passo']);
    }

    public function testUnaColonnaNuovaStaDopoLaColonnaScelta(): void
    {
        $c = $this->config([], [$this->colonna(1, ['dopo' => 'titolo'])]);
        $this->assertSame($this->posizione($c, 'titolo') + 1, $this->posizione($c, 'y_1'));
        $this->assertContains('y_1', $c->colonneOperatori());
        $this->assertSame('Ufficio', $c->colonna('y_1')['a_cura_di']);
    }

    public function testPiuColonneNuoveDopoLaStessaSiSeguonoEQuelleDiUnaColonnaNuovaLaSeguono(): void
    {
        $c = $this->config([], [$this->colonna(1, ['dopo' => 'titolo']), $this->colonna(2, ['dopo' => 'titolo']), $this->colonna(3, ['dopo' => 'y_1'])]);
        $t = $this->posizione($c, 'titolo');
        $this->assertSame([$t + 1, $t + 2, $t + 3], [$this->posizione($c, 'y_1'), $this->posizione($c, 'y_3'), $this->posizione($c, 'y_2')], 'y_1, poi quella che sta dopo di lei, poi y_2');
    }

    public function testUnaColonnaSenzaRiferimentoValidoOCheGiraInTondoSiVedeInFondo(): void
    {
        $c = $this->config([], [$this->colonna(1, ['dopo' => 'colonna_che_non_c_e']), $this->colonna(2, ['dopo' => 'y_3']), $this->colonna(3, ['dopo' => 'y_2'])]);
        $chiavi = $c->chiaviExcel();
        foreach (['y_1', 'y_2', 'y_3'] as $k) {
            $this->assertContains($k, $chiavi, $k . ' non deve perdersi');
        }
        $this->assertCount(count(array_unique($chiavi)), $chiavi, 'nessuna colonna doppia');
        $this->assertSame(['y_1', 'y_2', 'y_3'], array_slice($chiavi, -3));
    }

    public function testUnaColonnaNuovaNascostaNonCompare_EUnaVuotaNonContaSeNascosta(): void
    {
        $c = $this->config([], [$this->colonna(1, ['visibile' => 0])]);
        $this->assertNotContains('y_1', $c->chiaviExcel());
        $this->assertNotContains('y_1', $c->colonneOperatori(), 'una colonna nascosta non conta come da completare');
        $this->assertNotNull($c->colonna('y_1'), 'ma si trova ancora (la scheda dei campi la mostra per riaccenderla)');
        $this->assertCount(1, $c->colonneNuove());
    }

    public function testLeOpzioniEIlValoreInParole(): void
    {
        $this->assertSame(['Base', 'Avanzato', 'Esperto'], ConfigurazioneCampi::opzioniDa("Base, Avanzato;\nEsperto, "));
        $this->assertSame('05/12/2026', ConfigurazioneCampi::valoreInParole('date', '2026-12-05'));
        $this->assertSame('Avanzato', ConfigurazioneCampi::valoreInParole('radio', ' Avanzato '));
    }
}
