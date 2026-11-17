<?php

declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\EsportazioneRisposte;
use PHPUnit\Framework\TestCase;

final class EsportazioneRisposteTest extends TestCase
{
    private function form(bool $anonima = false): array
    {
        return [
            'anonima' => $anonima,
            'campi_json' => json_encode([
                ['etichetta' => 'Titolo della sezione', 'tipo' => 'titolo'],
                ['etichetta' => 'Città preferita', 'tipo' => 'text'],
                ['etichetta' => 'Attività preferite', 'tipo' => 'multicheck', 'opzioni' => 'Teatro, Musica, Sport'],
                ['etichetta' => 'Allegato', 'tipo' => 'file'],
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    private function risposta(array $campi): array
    {
        return [
            'inviata_il' => '2026-11-17 09:30:00', 'profilo' => 'studenti', 'nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario@x.it',
            'risposte_json' => json_encode($campi, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function testIntestazioniEscludonoITestiInformativi(): void
    {
        $tab = EsportazioneRisposte::tabella($this->form(), [$this->risposta([
            ['etichetta' => 'Città preferita', 'tipo' => 'text', 'valore' => 'Cosenza'],
            ['etichetta' => 'Attività preferite', 'tipo' => 'multicheck', 'valore' => 'Teatro, Sport'],
            ['etichetta' => 'Allegato', 'tipo' => 'file', 'valore' => 'documento.pdf', 'nome_file' => 'documento.pdf'],
        ])]);

        self::assertSame(['Data e ora', 'Nome', 'Email', 'Profilo', 'Città preferita', 'Attività preferite', 'Allegato'], $tab['intestazioni']);
    }

    public function testRigaConValoriESceltaMultiplaSeparataDaPuntoEVirgola(): void
    {
        $tab = EsportazioneRisposte::tabella($this->form(), [$this->risposta([
            ['etichetta' => 'Città preferita', 'tipo' => 'text', 'valore' => 'Cosenza'],
            ['etichetta' => 'Attività preferite', 'tipo' => 'multicheck', 'valore' => 'Teatro, Sport'],
            ['etichetta' => 'Allegato', 'tipo' => 'file', 'valore' => 'documento.pdf', 'nome_file' => 'documento.pdf'],
        ])]);

        self::assertSame(['17/11/2026 09:30', 'Mario Rossi', 'mario@x.it', 'studenti', 'Cosenza', 'Teatro; Sport', 'documento.pdf'], $tab['righe'][0]);
    }

    public function testCampoNascostoDaValoreVuoto(): void
    {
        $tab = EsportazioneRisposte::tabella($this->form(), [$this->risposta([
            ['etichetta' => 'Città preferita', 'tipo' => 'text', 'valore' => '', 'nascosto' => true],
            ['etichetta' => 'Attività preferite', 'tipo' => 'multicheck', 'valore' => ''],
            ['etichetta' => 'Allegato', 'tipo' => 'file', 'valore' => ''],
        ])]);

        self::assertSame(['', '', ''], array_slice($tab['righe'][0], 4));
    }

    public function testFormAnonimoSenzaColonneNomeEEmail(): void
    {
        $tab = EsportazioneRisposte::tabella($this->form(true), [$this->risposta([
            ['etichetta' => 'Città preferita', 'tipo' => 'text', 'valore' => 'Cosenza'],
            ['etichetta' => 'Attività preferite', 'tipo' => 'multicheck', 'valore' => ''],
            ['etichetta' => 'Allegato', 'tipo' => 'file', 'valore' => ''],
        ])]);

        self::assertSame(['Data e ora', 'Profilo', 'Città preferita', 'Attività preferite', 'Allegato'], $tab['intestazioni']);
        self::assertSame(['17/11/2026 09:30', 'studenti', 'Cosenza', '', ''], $tab['righe'][0]);
    }

    public function testNessunaRispostaProduceSoloLIntestazione(): void
    {
        $tab = EsportazioneRisposte::tabella($this->form(), []);
        self::assertSame([], $tab['righe']);
        self::assertNotEmpty($tab['intestazioni']);
    }

    public function testUnaFormulaNonPartePiuComeFormulaInCsvEExcel(): void
    {
        $risposta = $this->risposta([
            ['etichetta' => 'Città preferita', 'tipo' => 'text', 'valore' => '=HYPERLINK("http://evil.example","clic")'],
            ['etichetta' => 'Attività preferite', 'tipo' => 'multicheck', 'valore' => '@SUM(A1:A9)'],
            ['etichetta' => 'Allegato', 'tipo' => 'file', 'valore' => '+39 0984 123456'],
        ]);
        $risposta['nome'] = '-cmd|calc';

        $tab = EsportazioneRisposte::tabella($this->form(), [$risposta], true);

        self::assertSame('\'=HYPERLINK("http://evil.example","clic")', $tab['righe'][0][4]);
        self::assertSame('\'@SUM(A1:A9)', $tab['righe'][0][5]);
        self::assertSame("'+39 0984 123456", $tab['righe'][0][6]);
        self::assertSame("'-cmd|calc Rossi", $tab['righe'][0][1], 'anche il nome');
    }

    public function testSenzaNeutralizzazioneIlTestoResta(): void
    {
        $tab = EsportazioneRisposte::tabella($this->form(), [$this->risposta([['etichetta' => 'Città preferita', 'tipo' => 'text', 'valore' => '=1+1']])]);

        self::assertSame('=1+1', $tab['righe'][0][4], 'per il PDF il testo resta com\'è');
    }

    public function testNeutralizzaLasciaStareINumeriEIlTestoNormale(): void
    {
        self::assertSame('-5', EsportazioneRisposte::neutralizza('-5'));
        self::assertSame('+3.5', EsportazioneRisposte::neutralizza('+3.5'));
        self::assertSame('Cosenza', EsportazioneRisposte::neutralizza('Cosenza'));
        self::assertSame('', EsportazioneRisposte::neutralizza(''));
        self::assertSame("'-2+3", EsportazioneRisposte::neutralizza('-2+3'));
        self::assertSame("'\tx", EsportazioneRisposte::neutralizza("\tx"));
        self::assertSame("'=A1", EsportazioneRisposte::neutralizza('=A1'));
    }

    public function testLeIntestazioniDelleDomandeSiNeutralizzanoAnche(): void
    {
        $form = ['anonima' => true, 'campi_json' => json_encode([['etichetta' => '=cmd()', 'tipo' => 'text']])];

        self::assertSame("'=cmd()", EsportazioneRisposte::tabella($form, [], true)['intestazioni'][2]);
    }

    public function testSeUnaDomandaESpostataLeColonneNonSiScambiano(): void
    {
        // Le risposte furono salvate con le domande in questo ordine: A, B, C. Poi il form è cambiato: B tolta, D nuova in fondo.
        $form = ['anonima' => true, 'campi_json' => json_encode([
            ['etichetta' => 'A', 'tipo' => 'text'], ['etichetta' => 'C', 'tipo' => 'text'], ['etichetta' => 'D', 'tipo' => 'text'],
        ])];
        $riga = ['inviata_il' => '2026-11-17 09:30:00', 'profilo' => 'studenti', 'risposte_json' => json_encode([
            ['etichetta' => 'A', 'tipo' => 'text', 'valore' => 'a1'],
            ['etichetta' => 'B', 'tipo' => 'text', 'valore' => 'b1'],
            ['etichetta' => 'C', 'tipo' => 'text', 'valore' => 'c1'],
        ])];

        $tab = EsportazioneRisposte::tabella($form, [$riga]);

        self::assertSame(['17/11/2026 09:30', 'studenti', 'a1', 'c1', ''], $tab['righe'][0], 'C non prende il valore di B e la domanda nuova resta vuota');
    }

    public function testLeLarghezzeDelPdfSeguonoLeColonne(): void
    {
        foreach ([false, true] as $anonima) {
            $form = $this->form($anonima);
            $tab = EsportazioneRisposte::tabella($form, []);

            self::assertCount(count($tab['intestazioni']), EsportazioneRisposte::larghezzePdf($form), 'un peso per ogni colonna');
        }
    }
}
