<?php

declare(strict_types=1);

namespace Tests\Unit\Portale;

use App\Portale\Vista\VociMenu;
use PHPUnit\Framework\TestCase;

/** Le voci del menu «Altro ▾»: link e azioni che inviano un modulo, e le pericolose sempre in fondo. */
final class VociMenuTest extends TestCase
{
    public function testUnLinkEscapaIlTestoEdEcceCopiaGliAttributi(): void
    {
        $html = VociMenu::link('a.php?x=1&y=<2>', 'Apri <b>', 'fa-file', false, 'target="_blank" rel="noopener"');

        $this->assertStringContainsString('href="a.php?x=1&amp;y=&lt;2&gt;"', $html);
        $this->assertStringContainsString('Apri &lt;b&gt;', $html);
        $this->assertStringContainsString('target="_blank" rel="noopener"', $html);
        $this->assertStringNotContainsString('text-danger', $html);
    }

    public function testUnaVocePostPortaTokenCampiNomeEValoreDelPulsante(): void
    {
        $html = VociMenu::post('<input type="hidden" name="csrf_token" value="T">', 'elimina_x', '42', 'Elimina', 'fa-trash', 'Eliminare?', true, ['id' => 7, 'p"x' => 'a"b'], 'pagina.php');

        $this->assertStringStartsWith('<form method="POST" action="pagina.php">', $html);
        $this->assertStringContainsString('name="csrf_token" value="T"', $html);
        $this->assertStringContainsString('<input type="hidden" name="id" value="7">', $html);
        $this->assertStringContainsString('name="p&quot;x" value="a&quot;b"', $html);
        $this->assertStringContainsString('name="elimina_x" value="42"', $html);
        $this->assertStringContainsString('data-conferma="Eliminare?" data-conferma-livello="pericolo"', $html);
        $this->assertStringContainsString('text-danger pn-voce-pericolo', $html);
    }

    public function testSenzaConfermaNonCeAttributoDiConferma(): void
    {
        $html = VociMenu::post('', 'ok', '1', 'Fai');

        $this->assertStringNotContainsString('data-conferma', $html);
        $this->assertStringNotContainsString(' action=', $html);
    }

    public function testLeVociPericoloseVannoInFondoDopoUnFilo(): void
    {
        $a = VociMenu::link('a.php', 'A');
        $elimina = VociMenu::post('', 'del', '1', 'Elimina', '', 'Sicuro?', true);
        $b = VociMenu::link('b.php', 'B');
        $annulla = VociMenu::link('c.php', 'Annulla', '', true);

        $ordine = VociMenu::ordina([$elimina, $a, '', $annulla, $b]);

        $this->assertSame([$a, $b, '<hr class="dropdown-divider">', $elimina, $annulla], $ordine);
    }

    public function testIlMenuDiRigaHaTitoloOrdineEDiventaVuotoSenzaVoci(): void
    {
        $html = VociMenu::riga([VociMenu::link('d.php', 'Elimina', '', true), VociMenu::link('a.php', 'Apri')], 'Altre azioni');

        $this->assertStringContainsString('class="dropdown pn-altro pn-altro-riga', $html);
        $this->assertStringContainsString('title="Altre azioni"', $html);
        $this->assertLessThan(strpos($html, 'Elimina'), strpos($html, 'Apri'));
        $this->assertSame('', VociMenu::riga([]));
    }

    public function testSenzaPericoloseOSoloPericoloseNonCeFilo(): void
    {
        $a = VociMenu::link('a.php', 'A');
        $d = VociMenu::link('d.php', 'D', '', true);

        $this->assertSame([$a], VociMenu::ordina([$a]));
        $this->assertSame([$d], VociMenu::ordina([$d]));
        $this->assertSame([], VociMenu::ordina(['']));
    }
}
