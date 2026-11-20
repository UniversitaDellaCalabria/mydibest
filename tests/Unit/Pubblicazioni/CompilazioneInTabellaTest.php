<?php

declare(strict_types=1);

namespace Tests\Unit\Pubblicazioni;

use App\Pubblicazioni\CompilazioneInTabella;
use App\Pubblicazioni\ConfigurazioneCampi;
use PHPUnit\Framework\TestCase;

/**
 * Le scelte «di vista» della tabella «Da compilare» (AM7), senza database: quali colonne sono di una persona («a cura di»), quali righe hanno celle vuote,
 * come si divide in pagine.
 */
final class CompilazioneInTabellaTest extends TestCase
{
    private function config(): ConfigurazioneCampi
    {
        return ConfigurazioneCampi::predefinita();
    }

    public function testLeColonneDiUnaPersonaSonoQuelleDoveILSuoNomeStaTraGliACuraDi(): void
    {
        $f = CompilazioneInTabella::colonneDi(['nome' => 'Floriana', 'cognome' => 'Mantovano'], $this->config());
        $this->assertContains('c_altri_referenti', $f);
        $this->assertContains('v_n_partecipanti', $f, '«Floriana Mantovano o Silvana Geracitano»: nomina anche lei');
        $this->assertNotContains('d_url_pubblicazione', $f);
        $this->assertNotContains('f_pubblicazione_social', $f);

        // «Marco Bocci o Emanuele Dodaro»: vale per tutti e due, con il nome prima o dopo il cognome, maiuscole e spazi qualunque
        $atteso = ['d_url_pubblicazione', 'e_url_altre_pagine', 'u_produzione_locandina'];
        $this->assertSame($atteso, CompilazioneInTabella::colonneDi(['nome' => 'Emanuele', 'cognome' => 'Dodaro'], $this->config()));
        $this->assertSame($atteso, CompilazioneInTabella::colonneDi(['nome' => 'marco', 'cognome' => '  BOCCI '], $this->config()));
        $this->assertSame(['f_pubblicazione_social'], CompilazioneInTabella::colonneDi(['nome' => 'Gianni', 'cognome' => 'Giglio'], $this->config()));
    }

    public function testSenzaCorrispondenzaONomeIncompletoNonCiSonoColonne(): void
    {
        $this->assertSame([], CompilazioneInTabella::colonneDi(['nome' => 'Maria', 'cognome' => 'Rossi'], $this->config()));
        $this->assertSame([], CompilazioneInTabella::colonneDi(['nome' => 'Floriana', 'cognome' => ''], $this->config()), 'il solo nome non basta');
        $this->assertSame([], CompilazioneInTabella::colonneDi([], $this->config()));
        // chi ha solo il cognome in comune con un'altra persona non prende le sue colonne
        $this->assertSame([], CompilazioneInTabella::colonneDi(['nome' => 'Anna', 'cognome' => 'Mantovano'], $this->config()));
    }

    public function testLeRigheConCelleVuoteSiContanoComeCompletamento(): void
    {
        $righe = [
            ['id' => 1, 'c_altri_referenti' => 'x', 'f_pubblicazione_social' => 'SI'],
            ['id' => 2, 'c_altri_referenti' => '   ', 'f_pubblicazione_social' => 'SI'],
            ['id' => 3, 'c_altri_referenti' => 'x'],
        ];
        $this->assertSame([2, 3], array_column(CompilazioneInTabella::conCelleVuote($righe, ['c_altri_referenti', 'f_pubblicazione_social']), 'id'), 'solo spazi o chiave assente = vuota');
        $this->assertSame([2], array_column(CompilazioneInTabella::conCelleVuote($righe, ['c_altri_referenti']), 'id'), 'si guardano solo le colonne indicate');
        $this->assertSame([], CompilazioneInTabella::conCelleVuote($righe, []));
    }

    public function testLePagineSonoDa50RigheEIlNumeroDiPaginaRestaDentroILimiti(): void
    {
        $righe = array_map(static fn (int $i): array => ['id' => $i], range(1, 120));
        $p = CompilazioneInTabella::pagina($righe, 1);
        $this->assertSame([3, 50, 1, 120], [$p['pagine'], count($p['righe']), $p['pagina'], $p['totale']]);
        $this->assertSame(51, CompilazioneInTabella::pagina($righe, 2)['righe'][0]['id']);
        $ultima = CompilazioneInTabella::pagina($righe, 3);
        $this->assertSame(20, count($ultima['righe']));
        $this->assertSame(3, CompilazioneInTabella::pagina($righe, 99)['pagina'], 'oltre l\'ultima si dà l\'ultima');
        $this->assertSame(1, CompilazioneInTabella::pagina($righe, -4)['pagina']);
        $vuota = CompilazioneInTabella::pagina([], 5);
        $this->assertSame([[], 1, 1, 0], [$vuota['righe'], $vuota['pagina'], $vuota['pagine'], $vuota['totale']]);
    }
}
