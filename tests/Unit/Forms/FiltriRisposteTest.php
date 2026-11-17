<?php

declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\FiltriRisposte;
use PHPUnit\Framework\TestCase;

final class FiltriRisposteTest extends TestCase
{
    public function testDaPulisceLaRichiesta(): void
    {
        $f = FiltriRisposte::da(['q' => '  Cosenza ', 'dal' => '2026-11-01', 'al' => '2026-11-30', 'profilo' => 'studenti'], ['studenti', 'docenti']);

        self::assertSame(['q' => 'Cosenza', 'dal' => '2026-11-01', 'al' => '2026-11-30', 'profilo' => 'studenti'], $f);
    }

    public function testUnaDataCheNonEsisteSiScarta(): void
    {
        $f = FiltriRisposte::da(['dal' => '2026-02-31', 'al' => 'ieri'], []);

        self::assertSame('', $f['dal']);
        self::assertSame('', $f['al']);
        self::assertSame('', FiltriRisposte::data('2026-13-45'));
        self::assertSame('2028-02-29', FiltriRisposte::data('2028-02-29'));
    }

    public function testDataOraDelPeriodoDelFormDeveEsistere(): void
    {
        self::assertSame('2026-11-17T10:30', FiltriRisposte::dataOra('2026-11-17T10:30'));
        self::assertSame('2026-11-17T10:30', FiltriRisposte::dataOra('  2026-11-17T10:30 '));
        self::assertSame('2028-02-29T23:59', FiltriRisposte::dataOra('2028-02-29T23:59'), 'anno bisestile');
        self::assertSame('2026-01-01T00:00', FiltriRisposte::dataOra('2026-01-01T00:00'));

        self::assertSame('', FiltriRisposte::dataOra('2026-13-45T10:00'), 'giorno e mese che non esistono');
        self::assertSame('', FiltriRisposte::dataOra('2026-02-31T10:00'));
        self::assertSame('', FiltriRisposte::dataOra('2027-02-29T10:00'), 'non bisestile');
        self::assertSame('', FiltriRisposte::dataOra('2026-11-17T25:00'), 'ora che non esiste');
        self::assertSame('', FiltriRisposte::dataOra('2026-11-17T10:61'));
        self::assertSame('', FiltriRisposte::dataOra('2026-11-17 10:30'), 'manca la T di datetime-local');
        self::assertSame('', FiltriRisposte::dataOra('2026-11-17'));
        self::assertSame('', FiltriRisposte::dataOra(''));
        self::assertSame('', FiltriRisposte::dataOra('ieri alle dieci'));
    }

    public function testPeriodoAlContrarioSiRaddrizza(): void
    {
        $f = FiltriRisposte::da(['dal' => '2026-12-01', 'al' => '2026-11-01'], []);

        self::assertSame(['2026-11-01', '2026-12-01'], [$f['dal'], $f['al']]);
    }

    public function testUnProfiloCheNonCompareTraLeRisposteSiScarta(): void
    {
        self::assertSame('', FiltriRisposte::da(['profilo' => 'aziende'], ['studenti'])['profilo']);
        self::assertFalse(FiltriRisposte::attivi(FiltriRisposte::da(['profilo' => 'aziende'], ['studenti'])));
        self::assertTrue(FiltriRisposte::attivi(FiltriRisposte::da(['q' => 'x'], [])));
    }

    public function testRicercaNelTestoIgnoraMaiuscoleENascosti(): void
    {
        $riga = ['codice_ricevuta' => 'abc123', 'nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario@x.it', 'risposte_json' => json_encode([
            ['etichetta' => 'Città', 'tipo' => 'text', 'valore' => 'Cosenza'],
            ['etichetta' => 'Note', 'tipo' => 'text', 'valore' => 'segreto', 'nascosto' => true],
        ])];

        self::assertTrue(FiltriRisposte::corrisponde($riga, 'cosen', false));
        self::assertTrue(FiltriRisposte::corrisponde($riga, 'ROSSI', false));
        self::assertTrue(FiltriRisposte::corrisponde($riga, 'rossi mario', false), 'anche «cognome nome»');
        self::assertTrue(FiltriRisposte::corrisponde($riga, 'abc123', false));
        self::assertFalse(FiltriRisposte::corrisponde($riga, 'segreto', false), 'una domanda nascosta dalla condizione non si cerca');
        self::assertTrue(FiltriRisposte::corrisponde($riga, '', false));
    }

    public function testNeiFormAnonimiNonSiCercaPerNomeNeEmail(): void
    {
        $riga = ['codice_ricevuta' => 'abc123', 'nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario@x.it', 'risposte_json' => json_encode([
            ['etichetta' => 'Città', 'tipo' => 'text', 'valore' => 'Cosenza'],
        ])];

        self::assertFalse(FiltriRisposte::corrisponde($riga, 'rossi', true));
        self::assertFalse(FiltriRisposte::corrisponde($riga, 'mario@x.it', true));
        self::assertTrue(FiltriRisposte::corrisponde($riga, 'cosenza', true));
    }

    public function testPaginazione(): void
    {
        $righe = range(1, 60);

        $p = FiltriRisposte::pagina($righe, 2, 25);
        self::assertSame(3, $p['pagine']);
        self::assertSame(60, $p['totale']);
        self::assertSame(26, $p['righe'][0]);
        self::assertCount(25, $p['righe']);

        $ultima = FiltriRisposte::pagina($righe, 99, 25);
        self::assertSame(3, $ultima['pagina'], 'una pagina troppo alta si porta sull\'ultima');
        self::assertCount(10, $ultima['righe']);

        $vuota = FiltriRisposte::pagina([], 1);
        self::assertSame(1, $vuota['pagine']);
        self::assertSame([], $vuota['righe']);
    }
}
