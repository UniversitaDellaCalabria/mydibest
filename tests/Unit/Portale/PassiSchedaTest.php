<?php

declare(strict_types=1);

namespace Tests\Unit\Portale;

use App\Portale\Vista\PassiScheda;
use PHPUnit\Framework\TestCase;

/** ui_passi() (inc/ui.php) e le regole dei passi delle schede (PassiScheda): attività, bando di tutorato, pratica. */
final class PassiSchedaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once RADICE_SITO . '/inc/ui.php';
    }

    /** @param array{passi: list<array<string, string>>, corrente: int} $r @return list<string> */
    private static function stati(array $r): array
    {
        return array_column($r['passi'], 'stato');
    }

    // ---- ui_passi ----

    public function testUiPassiStatiAriaCurrentELista(): void
    {
        $html = ui_passi([
            ['titolo' => 'Dati', 'stato' => 'fatto'],
            ['titolo' => 'Turni', 'stato' => 'corrente', 'nota' => 'manca: un turno', 'link' => '#turni'],
            ['titolo' => 'Pubblica', 'stato' => 'da_fare'],
            ['titolo' => 'Pagamenti', 'stato' => 'bloccato'],
        ], 1);
        $this->assertStringStartsWith('<ol class="ui-percorso" aria-label="Passi da seguire">', $html);
        $this->assertSame(4, substr_count($html, '<li class="ui-passo"'));
        $this->assertSame(1, substr_count($html, 'aria-current="step"'));
        $this->assertMatchesRegularExpression('/<li class="ui-passo" data-stato="corrente" aria-current="step"><a class="ui-passo-corpo" href="#turni">/', $html);
        foreach (['fatto', 'corrente', 'da_fare', 'bloccato'] as $s) {
            $this->assertStringContainsString('data-stato="' . $s . '"', $html);
        }
        $this->assertStringContainsString('<small class="ui-passo-nota">manca: un turno</small>', $html);
        // il numero e l'icona sono solo di forma: la parola dello stato c'è per chi non vede i colori
        $this->assertStringContainsString('<span class="visually-hidden"> (fatto)</span>', $html);
        $this->assertStringContainsString('<span class="visually-hidden"> (bloccato)</span>', $html);
        $this->assertStringContainsString('<i class="fa fa-check"></i>', $html);
        $this->assertStringContainsString('<i class="fa fa-lock"></i>', $html);
    }

    public function testUiPassiRicavaLoStatoDalPassoCorrente(): void
    {
        $html = ui_passi([['titolo' => 'A'], ['titolo' => 'B'], ['titolo' => 'C']], 1);
        $this->assertMatchesRegularExpression('/data-stato="fatto".*data-stato="corrente".*data-stato="da_fare"/s', $html);
        // tutto fatto: corrente = -1, nessun aria-current
        $tutto = ui_passi([['titolo' => 'A'], ['titolo' => 'B']], -1);
        $this->assertSame(2, substr_count($tutto, 'data-stato="fatto"'));
        $this->assertStringNotContainsString('aria-current', $tutto);
        // uno stato che non esiste si tratta come se mancasse
        $this->assertStringContainsString('data-stato="da_fare"', ui_passi([['titolo' => 'A'], ['titolo' => 'B', 'stato' => 'boh']], 0));
    }

    public function testUiPassiEscapaITesti(): void
    {
        $html = ui_passi([['titolo' => '<script>alert(1)</script>', 'nota' => '"x" & <b>', 'link' => 'a.php?x=1&y="2"']], 0, 'normale', 'Passi <di prova>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&quot;x&quot; &amp; &lt;b&gt;', $html);
        $this->assertStringContainsString('href="a.php?x=1&amp;y=&quot;2&quot;"', $html);
        $this->assertStringContainsString('aria-label="Passi &lt;di prova&gt;"', $html);
    }

    public function testUiPassiDimensionePiccolaESenzaLink(): void
    {
        $piccolo = ui_passi([['titolo' => 'A']], 0, 'piccolo');
        $this->assertStringContainsString('class="ui-percorso ui-percorso-piccolo"', $piccolo);
        $this->assertStringContainsString('<div class="ui-passo-corpo">', $piccolo);
        $this->assertStringNotContainsString('<a ', $piccolo);
        $this->assertStringNotContainsString('ui-percorso-piccolo', ui_passi([['titolo' => 'A']], 0));
    }

    // ---- chiudi ----

    public function testChiudiIlPrimoNonFattoEIlCorrente(): void
    {
        $r = PassiScheda::chiudi([
            ['titolo' => 'A', 'fatto' => true],
            ['titolo' => 'B', 'fatto' => false],
            ['titolo' => 'C', 'fatto' => true],
            ['titolo' => 'D', 'fatto' => false, 'bloccato' => true],
        ]);
        $this->assertSame(1, $r['corrente']);
        $this->assertSame(['fatto', 'corrente', 'fatto', 'bloccato'], self::stati($r));
        $this->assertSame(-1, PassiScheda::chiudi([['titolo' => 'A', 'fatto' => true]])['corrente']);
    }

    // ---- attività ----

    public function testAttivitaNuovaParteDaiDati(): void
    {
        $r = PassiScheda::attivita(false, 0, 0, true, false, true);
        $this->assertSame(0, $r['corrente']);
        $this->assertSame(['corrente', 'da_fare', 'da_fare', 'da_fare'], self::stati($r));
        $this->assertSame(['Dati', 'Turni', 'Modulo di iscrizione', 'Pubblica'], array_column($r['passi'], 'titolo'));
    }

    public function testAttivitaSenzaTurniEFermaAiTurni(): void
    {
        $r = PassiScheda::attivita(true, 0, 0, true, false, true);
        $this->assertSame(1, $r['corrente']);
        $this->assertSame('manca: almeno un turno', $r['passi'][1]['nota']);
        // pubblicata ma senza turni: i passi fatti restano fatti, il passo in corso è quello che manca
        $this->assertSame(['fatto', 'corrente', 'da_fare', 'fatto'], self::stati($r));
    }

    public function testAttivitaModuloSceltoOSenzaPrenotazione(): void
    {
        $this->assertSame('fatto', self::stati(PassiScheda::attivita(true, 2, 3, true, false, true))[2]);
        $this->assertSame('3 campi', PassiScheda::attivita(true, 2, 3, true, false, true)['passi'][2]['nota']);
        $senza = PassiScheda::attivita(true, 2, 0, false, false, true);
        $this->assertSame('fatto', $senza['passi'][2]['stato']);
        $this->assertSame('non serve: senza prenotazione', $senza['passi'][2]['nota']);
        $this->assertSame('corrente', self::stati(PassiScheda::attivita(true, 2, 0, true, false, true))[2]);
    }

    public function testAttivitaTuttaPubblicata(): void
    {
        $r = PassiScheda::attivita(true, 1, 1, true, false, true, ['ancora_turni' => '#turni']);
        $this->assertSame(-1, $r['corrente']);
        $this->assertSame(['fatto', 'fatto', 'fatto', 'fatto'], self::stati($r));
        $this->assertSame('#turni', $r['passi'][1]['link']);
        $this->assertSame('1 turno', $r['passi'][1]['nota']);
    }

    public function testAttivitaAreaNascostaOArchiviataBlocca(): void
    {
        $nascosta = PassiScheda::attivita(true, 1, 1, true, false, false);
        $this->assertSame('bloccato', $nascosta['passi'][3]['stato']);
        $this->assertSame("manca: l'area è nascosta al pubblico", $nascosta['passi'][3]['nota']);
        $archiviata = PassiScheda::attivita(true, 1, 1, true, true, true);
        $this->assertSame('bloccato', $archiviata['passi'][3]['stato']);
        $this->assertSame('archiviato: non si vede', $archiviata['passi'][3]['nota']);
        // un evento non ancora salvato non è «bloccato»
        $this->assertSame('da_fare', PassiScheda::attivita(false, 0, 0, true, false, false)['passi'][3]['stato']);
    }

    // ---- bando di tutorato ----

    /** @return array<string, mixed> */
    private static function bando(array $extra = []): array
    {
        return $extra + ['decreto_bando' => '12/2026', 'decreto_bando_file' => 'b.pdf', 'decreto_commissione' => '', 'decreto_commissione_file' => '', 'decreto_atti' => '', 'decreto_atti_file' => '', 'ufficio_ateneo_id' => 0];
    }

    public function testBandoAppenaCreatoMancaLaCommissione(): void
    {
        $r = PassiScheda::bandoTutorato(self::bando(), []);
        $this->assertSame(['Bando', 'Commissione', 'Graduatoria', 'Incarichi', 'Registro', 'Pagamenti'], array_column($r['passi'], 'titolo'));
        $this->assertSame(1, $r['corrente']);
        $this->assertSame('manca: il numero del decreto della commissione', $r['passi'][1]['nota']);
        $this->assertSame('manca: i vincitori', $r['passi'][3]['nota']);
        // senza ufficio che paga i pagamenti sono bloccati, con la nota che dice perché
        $this->assertSame('bloccato', $r['passi'][5]['stato']);
        $this->assertSame("manca: l'ufficio che paga i tutor", $r['passi'][5]['nota']);
    }

    public function testBandoDecretoSenzaPdf(): void
    {
        $r = PassiScheda::bandoTutorato(self::bando(['decreto_bando_file' => '']), []);
        $this->assertSame(0, $r['corrente']);
        $this->assertSame('manca: il PDF del decreto del bando', $r['passi'][0]['nota']);
    }

    public function testBandoLettereRegistroEPagamentiSiContanoSenzaLeAnnullate(): void
    {
        $bando = self::bando(['decreto_commissione' => '20/2026', 'decreto_commissione_file' => 'c.pdf', 'decreto_atti' => '30/2026', 'decreto_atti_file' => 'a.pdf', 'ufficio_ateneo_id' => 4]);
        $lettere = [
            ['stato' => 'protocollata', 'fine_stato' => 'firmata', 'pagamento_stato' => 'pagata'],
            ['stato' => 'protocollata', 'fine_stato' => '', 'pagamento_stato' => ''],
            ['stato' => 'annullata', 'fine_stato' => '', 'pagamento_stato' => ''],
        ];
        $r = PassiScheda::bandoTutorato($bando, $lettere);
        $this->assertSame(['fatto', 'fatto', 'fatto', 'fatto', 'corrente', 'da_fare'], self::stati($r));
        $this->assertSame('2 su 2 lettere protocollate', $r['passi'][3]['nota']);
        $this->assertSame('1 su 2 con la fine attività firmata', $r['passi'][4]['nota']);
        $this->assertSame('1 su 2 pagati', $r['passi'][5]['nota']);
    }

    public function testBandoTuttoPagato(): void
    {
        $bando = self::bando(['decreto_commissione' => '20/2026', 'decreto_commissione_file' => 'c.pdf', 'decreto_atti' => '30/2026', 'decreto_atti_file' => 'a.pdf', 'ufficio_ateneo_id' => 4]);
        $r = PassiScheda::bandoTutorato($bando, [['stato' => 'protocollata', 'fine_stato' => 'protocollata', 'pagamento_stato' => 'pagata']]);
        $this->assertSame(-1, $r['corrente']);
        $this->assertSame(array_fill(0, 6, 'fatto'), self::stati($r));
    }

    // ---- pratica ----

    public function testPraticaAppenaArrivata(): void
    {
        $r = PassiScheda::pratica(['stato' => 'inviata', 'seduta_id' => null, 'esito_seduta' => '', 'protocollo' => '', 'in_verbale' => '1'], 'smistare');
        $this->assertSame(['Arrivata', 'Istruttoria', 'Seduta', 'Verbale', 'Protocollo'], array_column($r['passi'], 'titolo'));
        $this->assertSame(1, $r['corrente']);
        $this->assertSame(['fatto', 'corrente', 'da_fare', 'da_fare', 'da_fare'], self::stati($r));
        $this->assertSame('da assegnare a una persona', $r['passi'][1]['nota']);
    }

    public function testPraticaInAttesaDelloStudenteEBloccata(): void
    {
        $r = PassiScheda::pratica(['stato' => 'integrazione', 'protocollo' => '12/2026', 'in_verbale' => '1'], 'integrazione');
        $this->assertSame('bloccato', $r['passi'][1]['stato']);
        $this->assertSame('manca: la risposta dello studente', $r['passi'][1]['nota']);
        $this->assertSame('fatto', $r['passi'][4]['stato']); // il protocollo c'è già
        $this->assertSame(1, $r['corrente']);
    }

    public function testPraticaInSedutaConVerbale(): void
    {
        $p = ['stato' => 'in_lavorazione', 'seduta_id' => 5, 'esito_seduta' => '', 'protocollo' => '77/2026', 'in_verbale' => '1'];
        $senzaEsito = PassiScheda::pratica($p, 'consiglio', ['verbale_stato' => '']);
        $this->assertSame(['fatto', 'fatto', 'corrente', 'da_fare', 'fatto'], self::stati($senzaEsito));
        $this->assertSame('manca: la decisione del consiglio', $senzaEsito['passi'][2]['nota']);

        $decisa = PassiScheda::pratica(['esito_seduta' => 'accolta'] + $p, 'decisa', ['verbale_stato' => 'coordinatore']);
        $this->assertSame('corrente', $decisa['passi'][3]['stato']);
        $this->assertSame('manca: la firma del verbale', $decisa['passi'][3]['nota']);

        $firmato = PassiScheda::pratica(['esito_seduta' => 'accolta', 'stato' => 'accolta'] + $p, 'conclusa', ['verbale_stato' => 'firmato']);
        $this->assertSame(-1, $firmato['corrente']);
    }

    public function testPraticaChePassaSenzaVerbale(): void
    {
        $r = PassiScheda::pratica(['stato' => 'chiusa', 'protocollo' => '1/2026', 'in_verbale' => '0'], 'conclusa');
        $this->assertSame(-1, $r['corrente']);
        $this->assertSame('non serve', $r['passi'][2]['nota']);
        $this->assertSame('non serve', $r['passi'][3]['nota']);
        // pratica chiusa che non è mai andata in seduta: seduta e verbale non servono neanche se il modulo li prevedeva
        $chiusa = PassiScheda::pratica(['stato' => 'respinta', 'protocollo' => '', 'in_verbale' => '1'], 'conclusa');
        $this->assertSame('fatto', $chiusa['passi'][2]['stato']);
        $this->assertSame('corrente', $chiusa['passi'][4]['stato']);
    }
}
