<?php

declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\RiepilogoRisposte;
use PHPUnit\Framework\TestCase;

final class RiepilogoRisposteTest extends TestCase
{
    private function form(): array
    {
        return ['campi_json' => json_encode([
            ['etichetta' => 'Sezione', 'tipo' => 'titolo'],
            ['etichetta' => 'Come ci hai conosciuto?', 'tipo' => 'radio', 'opzioni' => 'Amici, Social, Altro'],
            ['etichetta' => 'Cosa ti interessa?', 'tipo' => 'multicheck', 'opzioni' => 'Teatro, Musica, Sport'],
            ['etichetta' => 'Vuoi la newsletter?', 'tipo' => 'checkbox'],
            ['etichetta' => 'Commento', 'tipo' => 'textarea'],
            ['etichetta' => 'Dettaglio', 'tipo' => 'text', 'cond' => ['campo' => 'Vuoi la newsletter?', 'op' => 'uguale', 'valore' => 'Sì']],
        ], JSON_UNESCAPED_UNICODE)];
    }

    /** @param list<array<string, mixed>> $risposte */
    private function riga(array $risposte): array
    {
        return ['risposte_json' => json_encode($risposte, JSON_UNESCAPED_UNICODE)];
    }

    private function risposte(string $come, string $cosa, string $news, string $commento, ?string $dettaglio): array
    {
        return $this->riga([
            ['etichetta' => 'Come ci hai conosciuto?', 'tipo' => 'radio', 'valore' => $come],
            ['etichetta' => 'Cosa ti interessa?', 'tipo' => 'multicheck', 'valore' => $cosa],
            ['etichetta' => 'Vuoi la newsletter?', 'tipo' => 'checkbox', 'valore' => $news],
            ['etichetta' => 'Commento', 'tipo' => 'textarea', 'valore' => $commento],
            ['etichetta' => 'Dettaglio', 'tipo' => 'text', 'valore' => (string) $dettaglio] + ($dettaglio === null ? ['nascosto' => true] : []),
        ]);
    }

    public function testConteggiEPercentualiDellaSceltaSingola(): void
    {
        $r = RiepilogoRisposte::calcola($this->form(), [
            $this->risposte('Amici', 'Teatro', 'Sì', 'bene', 'x'),
            $this->risposte('Amici', 'Musica', '', '', null),
            $this->risposte('Social', 'Teatro, Musica', '', '', null),
            $this->risposte('', '', '', '', null),
        ]);

        self::assertSame('Come ci hai conosciuto?', $r[0]['etichetta']);
        self::assertTrue($r[0]['scelta']);
        self::assertSame(3, $r[0]['rispondenti'], 'chi non ha scelto niente non è un rispondente');
        self::assertSame([['testo' => 'Amici', 'n' => 2, 'perc' => 66.7], ['testo' => 'Social', 'n' => 1, 'perc' => 33.3], ['testo' => 'Altro', 'n' => 0, 'perc' => 0.0]], $r[0]['voci']);
    }

    public function testSceltaMultiplaContaOgniVoceSullePersone(): void
    {
        $r = RiepilogoRisposte::calcola($this->form(), [
            $this->risposte('Amici', 'Teatro, Musica', '', '', null),
            $this->risposte('Amici', 'Teatro', '', '', null),
        ]);

        self::assertTrue($r[1]['multipla']);
        self::assertSame(2, $r[1]['rispondenti']);
        self::assertSame(['Teatro' => 2, 'Musica' => 1, 'Sport' => 0], array_column($r[1]['voci'], 'n', 'testo'));
        self::assertSame(100.0, $r[1]['voci'][0]['perc']);
        self::assertSame(50.0, $r[1]['voci'][1]['perc']);
    }

    public function testCasellaSiNoContaAnchePerchiNonLHaSpuntata(): void
    {
        $r = RiepilogoRisposte::calcola($this->form(), [
            $this->risposte('Amici', '', 'Sì', '', 'x'),
            $this->risposte('Amici', '', '', '', null),
            $this->risposte('Amici', '', '', '', null),
        ]);

        self::assertSame(3, $r[2]['rispondenti']);
        self::assertSame(['Sì' => 1, 'No' => 2], array_column($r[2]['voci'], 'n', 'testo'));
    }

    public function testLeAltreDomandeContanoSoloLeRisposte(): void
    {
        $r = RiepilogoRisposte::calcola($this->form(), [
            $this->risposte('Amici', '', '', 'ottimo', null),
            $this->risposte('Amici', '', '', '', null),
        ]);

        self::assertSame('Commento', $r[3]['etichetta']);
        self::assertFalse($r[3]['scelta']);
        self::assertSame(1, $r[3]['rispondenti']);
        self::assertSame([], $r[3]['voci']);
    }

    public function testChiNonHaVistoLaDomandaNonConta(): void
    {
        $r = RiepilogoRisposte::calcola($this->form(), [
            $this->risposte('Amici', '', 'Sì', '', 'dettaglio'),
            $this->risposte('Amici', '', '', '', null), // «Dettaglio» nascosto dalla condizione
            $this->risposte('Amici', '', '', '', null),
        ]);

        self::assertSame('Dettaglio', $r[4]['etichetta']);
        self::assertSame(1, $r[4]['rispondenti']);
    }

    public function testUnaVoceNonPiuTraLeOpzioniResta(): void
    {
        $r = RiepilogoRisposte::calcola($this->form(), [
            $this->risposte('Volantino', '', '', '', null), // opzione tolta dopo
            $this->risposte('Amici', '', '', '', null),
        ]);

        self::assertSame(['Amici' => 1, 'Social' => 0, 'Altro' => 0, 'Volantino' => 1], array_column($r[0]['voci'], 'n', 'testo'));
    }

    public function testSenzaRisposteNiente(): void
    {
        $r = RiepilogoRisposte::calcola($this->form(), []);

        self::assertSame(0, $r[0]['rispondenti']);
        self::assertSame(0.0, $r[0]['voci'][0]['perc']);
    }
}
