<?php

declare(strict_types=1);

namespace Tests\Unit\Pubblicazioni;

use App\Amministrazione\PermessiAmministrazione;
use App\Pubblicazioni\ColonneDiChi;
use App\Pubblicazioni\ConfigurazioneCampi;
use PHPUnit\Framework\TestCase;

/** «Chi compila cosa», le regole senza database (ColonneDiChi::valuta): chi vede tutto, chi vede le sue colonne, nessuna assegnazione. */
final class ColonneDiChiTest extends TestCase
{
    /** @return list<array<string, mixed>> le colonne del file Excel di partenza */
    private function colonne(): array
    {
        return ConfigurazioneCampi::predefinita()->colonne();
    }

    /** @return list<string> le chiavi delle colonne che scrive chi invia, più lo stato */
    private function diChiInvia(): array
    {
        return array_values(array_column(array_filter($this->colonne(), static fn (array $c): bool => !$c['operatori']), 'chiave'));
    }

    public function testAmministratoreEModuloInteroVedonoTutteLeColonneAncheSeNonAssegnate(): void
    {
        $tutte = ConfigurazioneCampi::predefinita()->chiaviExcel();
        $assegnazioni = ['f_pubblicazione_social' => [4], 'c_altri_referenti' => [4]]; // di un altro
        $admin = PermessiAmministrazione::valuta(['id' => 1, 'ruolo_id' => 1, 'ruoli_secondari' => ''], []);
        $modulo = PermessiAmministrazione::valuta(['id' => 2, 'ruolo_id' => 4, 'ruoli_secondari' => ''], [['tipo' => 'modulo_amministrazione', 'pagina_id' => 0]]);
        $this->assertSame($tutte, ColonneDiChi::valuta($this->colonne(), $assegnazioni, 1, $admin['tutto']));
        $this->assertSame($tutte, ColonneDiChi::valuta($this->colonne(), $assegnazioni, 2, $modulo['tutto']));
    }

    public function testChiHaSoloLeRichiesteVedeLeSueDueColonneEQuelleDiChiInvia(): void
    {
        $parziale = PermessiAmministrazione::valuta(['id' => 4, 'ruolo_id' => 4, 'ruoli_secondari' => ''], [['tipo' => 'modulo_pubblicazioni', 'pagina_id' => 0]]);
        $this->assertFalse($parziale['tutto']);
        // due colonne sue (una condivisa con un'altra persona), una di un altro
        $assegnazioni = ['f_pubblicazione_social' => [4], 'v_n_partecipanti' => [7, 4], 'c_altri_referenti' => [7]];
        $viste = ColonneDiChi::valuta($this->colonne(), $assegnazioni, 4, $parziale['tutto']);

        $this->assertEqualsCanonicalizing(array_merge($this->diChiInvia(), ['f_pubblicazione_social', 'v_n_partecipanti']), $viste);
        $this->assertContains('titolo', $viste);
        $this->assertContains('data_evento', $viste);
        $this->assertContains('richiedente', $viste);
        $this->assertContains('stato', $viste);
        $this->assertNotContains('c_altri_referenti', $viste, 'è di un\'altra persona');
        $this->assertNotContains('j_tipo_iniziativa', $viste, 'non è di nessuno: la vede solo chi vede tutto');
        // nell'ordine del file Excel
        $this->assertSame(array_values(array_intersect(ConfigurazioneCampi::predefinita()->chiaviExcel(), $viste)), $viste);
        // chi non ha nessuna colonna vede solo quelle di chi invia
        $this->assertSame($this->diChiInvia(), ColonneDiChi::valuta($this->colonne(), $assegnazioni, 99, false));
    }

    public function testSenzaNessunaAssegnazioneSalvataTuttiVedonoTuttoComePrima(): void
    {
        $this->assertSame(ConfigurazioneCampi::predefinita()->chiaviExcel(), ColonneDiChi::valuta($this->colonne(), [], 4, false));
        $this->assertSame(ConfigurazioneCampi::predefinita()->chiaviExcel(), ColonneDiChi::valuta($this->colonne(), [], 99, false));
    }
}
