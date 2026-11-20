<?php

declare(strict_types=1);

namespace Tests\Unit\Portale;

use App\Portale\GuidePerRuolo;
use App\Pubblicazioni\ConfigurazioneCampi;
use App\Pubblicazioni\ImpostazioniPubblicazioni;
use PHPUnit\Framework\TestCase;

/**
 * Le guide della richiesta di pubblicazione e del modulo Amministrazione (passo AM6), senza database: valgono i valori di partenza delle
 * impostazioni e dei campi (con il database li leggono da ImpostazioniPubblicazioni e ConfigurazioneCampi, provato in strumenti/prove/oop/amministrazione.php).
 */
final class GuidePubblicazioniTest extends TestCase
{
    /** @param array<string, mixed> $guida */
    private static function testo(array $guida): string
    {
        $t = (string) $guida['in_breve'];
        foreach ($guida['sezioni'] as $s) {
            $t .= ' ' . $s['titolo'] . ' ' . implode(' ', $s['passi']) . ' ' . ($s['nota'] ?? '');
        }

        return strip_tags($t);
    }

    public function testCiSonoLeTreGuideDelleRichiesteEDellAmministrazione(): void
    {
        $guide = GuidePerRuolo::tutte();
        foreach (['pubblicazione', 'pubblicazioni', 'amministrazione'] as $k) {
            $this->assertArrayHasKey($k, $guide);
            $this->assertNotSame([], $guide[$k]['sezioni']);
            foreach ($guide[$k]['sezioni'] as $s) {
                $this->assertNotSame([], $s['passi'], $guide[$k]['titolo'] . ': ' . $s['titolo']);
            }
        }
        $this->assertSame('#0e7490', $guide['amministrazione']['colore']);
    }

    public function testLaGuidaDiChiInviaNonHaNumeriScrittiAMano(): void
    {
        $t = self::testo(GuidePerRuolo::tutte()['pubblicazione']);
        $in = ImpostazioniPubblicazioni::INIZIALI;
        $this->assertStringContainsString('docenti e personale', $t);
        $this->assertStringContainsString(ImpostazioniPubblicazioni::inParole($in['chi_puo_inviare']), $t);
        $this->assertStringContainsString($in['max_sottotitolo'] . ' caratteri', $t);
        $this->assertStringContainsString($in['max_descrizione'] . ' caratteri', $t);
        $this->assertStringContainsString('fino a ' . $in['max_allegati'] . ' file', $t);
        // ogni passo visibile ha la sua riga: il primo e il riepilogo ci sono sempre
        $passi = ConfigurazioneCampi::predefinita()->passiVisibili();
        $this->assertStringContainsString('Compili una richiesta guidata in sette passi', $t);
        $this->assertCount(count($passi), GuidePerRuolo::tutte()['pubblicazione']['sezioni'][1]['passi']);
    }

    public function testLaGuidaDiChiGestisceParlaDelModuloAmministrazione(): void
    {
        $t = self::testo(GuidePerRuolo::tutte()['pubblicazioni']);
        $this->assertStringNotContainsString('modulo a sé', $t);
        foreach (['modulo Amministrazione', 'Modulo Amministrazione', 'Amministrazione · Richieste di pubblicazione', 'Impostazioni', 'Campi e colonne', 'Torna all’originale', 'Torna alle intestazioni originali', 'nasconderlo'] as $parola) {
            $this->assertStringContainsString($parola, $t, $parola);
        }
        // le colonne da completare arrivano da ConfigurazioneCampi: C ed F, con chi le cura
        $this->assertStringContainsString('C · Altri referenti', $t);
        $this->assertStringContainsString('A cura di Gianni Giglio', $t);
    }

    public function testLaGuidaDelModuloDiceCosaE(): void
    {
        $t = self::testo(GuidePerRuolo::tutte()['amministrazione']);
        foreach (['Cosa devo fare', 'Modulistica amministrativa', 'procedure online', 'Chi abilita cosa', 'Richieste di pubblicazione'] as $parola) {
            $this->assertStringContainsString($parola, $t, $parola);
        }
    }
}
