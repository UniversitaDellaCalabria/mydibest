<?php

declare(strict_types=1);

namespace Tests\Unit\Portale;

use App\Portale\Area\AzioniAreaPubblica;
use App\Portale\Area\EsitoPrenotazioneArea;
use PHPUnit\Framework\TestCase;

/** Le regole tolte da master_template.php (S14): dove si rimanda dopo «aggiungi al programma» e quali messaggi si mostrano dopo un esito. Senza database. */
final class AreaPubblicaAzioniTest extends TestCase
{
    // ---- AzioniAreaPubblica ----

    public function testHaUnAzioneSoloInPostConIlPulsanteGiusto(): void
    {
        $this->assertTrue(AzioniAreaPubblica::haUnAzione('POST', ['invia_prenotazione' => '']));
        $this->assertTrue(AzioniAreaPubblica::haUnAzione('POST', ['aggiungi_programma' => '1']));
        $this->assertFalse(AzioniAreaPubblica::haUnAzione('GET', ['invia_prenotazione' => '1']));
        $this->assertFalse(AzioniAreaPubblica::haUnAzione('POST', ['altro' => '1']));
    }

    public function testRimandoProgrammaOkTornaAlProgettoOAllaScheda(): void
    {
        $ok = ['ok' => true, 'errore' => null, 'titolo' => 'Geologia', 'sostituita' => true, 'evento_id' => 20];
        $r = AzioniAreaPubblica::rimandoProgramma('fsl', ['progetto' => '20'], ['da_scheda' => '7'], $ok);
        $this->assertSame('fsl.php?progetto=20&programma=ok', $r['rimando'], 'il progetto aperto vince sulla scheda');
        $this->assertSame(['programma_aggiunta' => ['titolo' => 'Geologia', 'sostituita' => true]], $r['sessione']);

        $r = AzioniAreaPubblica::rimandoProgramma('fsl', [], ['da_scheda' => '7'], $ok);
        $this->assertSame('fsl.php?evento=7&programma=ok', $r['rimando']);

        $r = AzioniAreaPubblica::rimandoProgramma('fsl', [], [], $ok);
        $this->assertSame('fsl.php?programma=ok', $r['rimando'], 'senza progetto né scheda si usa ? e non &');
    }

    public function testRimandoProgrammaErroreLasciaIlMessaggioInSessione(): void
    {
        $no = ['ok' => false, 'errore' => 'Le iscrizioni sono chiuse.', 'titolo' => 'Geologia', 'sostituita' => false, 'evento_id' => 20];
        $r = AzioniAreaPubblica::rimandoProgramma('fsl', ['progetto' => '20'], [], $no);
        $this->assertSame('fsl.php?progetto=20&programma=errore', $r['rimando']);
        $this->assertSame(['programma_errore' => ['titolo' => 'Geologia', 'errore' => 'Le iscrizioni sono chiuse.']], $r['sessione']);
    }

    // ---- EsitoPrenotazioneArea ----

    private function esito(): EsitoPrenotazioneArea
    {
        return new EsitoPrenotazioneArea(
            fn (array $cfg, string $codice): string => '[istruzioni ' . $codice . ']',
            fn (string $codice): string => '[avviso ' . $codice . ']'
        );
    }

    public function testSenzaEsitoNessunMessaggio(): void
    {
        $s = [];
        $this->assertSame(['messaggio' => '', 'programma' => null], $this->esito()->da([], $s, [], 'https://sito'));
        $this->assertSame(['messaggio' => '', 'programma' => null], $this->esito()->da(['status' => 'sconosciuto'], $s, [], 'https://sito'));
        $this->assertSame('', $this->esito()->da(['status' => 'success'], $s, [], 'https://sito')['messaggio'], 'success senza codice non dice niente');
    }

    public function testPrenotazioneRiuscitaHaCodiceRicevutaEAvviso(): void
    {
        $s = [];
        $m = $this->esito()->da(['status' => 'success', 'code' => 'AB<1>'], $s, [], 'https://sito')['messaggio'];
        $this->assertStringContainsString('Prenotazione confermata!', $m);
        $this->assertStringContainsString('AB&lt;1&gt;', $m, 'il codice si mostra senza HTML');
        $this->assertStringContainsString("href='https://sito/stampa_ricevuta.php?code=AB%26lt%3B1%26gt%3B'", $m);
        $this->assertStringEndsWith('[avviso AB<1>]', $m, "in fondo l'avviso sui documenti, col codice com'era nell'indirizzo");
        $this->assertStringNotContainsString('[istruzioni', $m);
    }

    public function testTipiDiEsitoRiuscito(): void
    {
        $s = [];
        $conv = $this->esito()->da(['status' => 'success', 'code' => 'X', 'st_tipo' => 'convenzione', 'conv_rinnovo' => '1'], $s, [], 'b')['messaggio'];
        $this->assertStringContainsString('in attesa della convenzione', $conv);
        $this->assertStringContainsString('non copre tutto il periodo', $conv);
        $this->assertStringContainsString('[istruzioni X]', $conv);
        $this->assertStringContainsString('in approvazione', $this->esito()->da(['status' => 'success', 'code' => 'X', 'st_tipo' => 'approvare'], $s, [], 'b')['messaggio']);
        $att = $this->esito()->da(['status' => 'success', 'code' => 'X', 'st_tipo' => 'attesa'], $s, [], 'b')['messaggio'];
        $this->assertStringContainsString("In Lista d'Attesa!", $att);
        $this->assertStringNotContainsString('[istruzioni', $att);
        $this->assertStringContainsString('[istruzioni X]', $this->esito()->da(['status' => 'success', 'code' => 'X', 'st_tipo' => 'attesa', 'conv' => 'no'], $s, [], 'b')['messaggio']);
    }

    public function testErroriSemplici(): void
    {
        $s = [];
        foreach (['dup' => 'già esistente', 'full' => 'Posti esauriti', 'closed' => 'sono chiuse', 'notopened' => 'non sono ancora aperte', 'riservato' => 'devi prima accedere', 'altra_edizione' => 'un\'altra edizione', 'error' => 'Errore di registrazione'] as $stato => $testo) {
            $this->assertStringContainsString($testo, $this->esito()->da(['status' => $stato], $s, [], 'b')['messaggio'], $stato);
        }
    }

    public function testLimiteDicePerchéNonSiPuòPrenotare(): void
    {
        $s = [];
        $m = $this->esito()->da(['status' => 'limite', 'ev' => '<b>Prova</b>'], $s, ['limite_iscrizioni' => 'un_turno'], 'b')['messaggio'];
        $this->assertStringContainsString('un solo turno per ciascun evento', $m);
        $this->assertStringContainsString('&lt;b&gt;Prova&lt;/b&gt;', $m);
        $this->assertStringContainsString('un solo evento/gruppo', $this->esito()->da(['status' => 'limite'], $s, [], 'b')['messaggio']);
    }

    public function testErroriDelServizioSiLeggonoDallaSessioneESiTolgono(): void
    {
        $s = ['errore_prenotazione' => 'Risposta <errata>'];
        $m = $this->esito()->da(['status' => 'captcha'], $s, [], 'b')['messaggio'];
        $this->assertStringContainsString('Risposta &lt;errata&gt;', $m);
        $this->assertArrayNotHasKey('errore_prenotazione', $s, 'una volta letto non si ripropone');
        $this->assertStringContainsString('Controllo anti-robot non superato.', $this->esito()->da(['status' => 'captcha'], $s, [], 'b')['messaggio'], 'senza testo in sessione, quello di sempre');
        $this->assertStringContainsString('Controlla l&#039;indirizzo email.', $this->esito()->da(['status' => 'email'], $s, [], 'b')['messaggio']);
        $this->assertStringContainsString('Numero di studenti non valido.', $this->esito()->da(['status' => 'studenti'], $s, [], 'b')['messaggio']);
    }

    public function testProgrammaAggiuntoSiLeggeUnaVolta(): void
    {
        $s = ['programma_aggiunta' => ['titolo' => 'Geologia', 'sostituita' => false]];
        $r = $this->esito()->da(['programma' => 'ok'], $s, [], 'b');
        $this->assertSame(['titolo' => 'Geologia', 'sostituita' => false], $r['programma']);
        $this->assertSame('', $r['messaggio']);
        $this->assertSame([], $s, 'la domanda non si ripropone ricaricando');
        $this->assertNull($this->esito()->da(['programma' => 'ok'], $s, [], 'b')['programma']);
    }

    public function testProgrammaNonAggiuntoMostraIlMotivoEUnStatusLoSostituisce(): void
    {
        $s = ['programma_errore' => ['titolo' => 'Geologia', 'errore' => 'Chiuse.']];
        $m = $this->esito()->da(['programma' => 'errore'], $s, [], 'b')['messaggio'];
        $this->assertStringContainsString('Non aggiunta al programma (Geologia): Chiuse.', $m);
        $this->assertSame([], $s);
        $s = ['programma_errore' => ['titolo' => '', 'errore' => 'Chiuse.']];
        $senzaTitolo = $this->esito()->da(['programma' => 'errore'], $s, [], 'b')['messaggio'];
        $this->assertStringContainsString('Non aggiunta al programma: Chiuse.', $senzaTitolo);
        $s = ['programma_errore' => ['titolo' => '', 'errore' => 'Chiuse.']];
        $this->assertStringContainsString('Posti esauriti', $this->esito()->da(['programma' => 'errore', 'status' => 'full'], $s, [], 'b')['messaggio'], 'uno ?status= noto prende il posto del messaggio');
        $s = ['programma_errore' => ['titolo' => '', 'errore' => 'Chiuse.']];
        $this->assertStringContainsString('Non aggiunta', $this->esito()->da(['programma' => 'errore', 'status' => 'boh'], $s, [], 'b')['messaggio'], 'uno ?status= sconosciuto lascia il messaggio');
    }
}
