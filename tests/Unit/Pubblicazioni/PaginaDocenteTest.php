<?php

declare(strict_types=1);

namespace Tests\Unit\Pubblicazioni;

use App\Pubblicazioni\LimitiCaricamento;
use App\Pubblicazioni\PassiRichiesta;
use App\Pubblicazioni\RiepilogoRichiesta;
use PHPUnit\Framework\TestCase;

/** La pagina del docente (passo R2): limiti di caricamento del server, passi con gli errori, riepilogo leggibile della richiesta. */
final class PaginaDocenteTest extends TestCase
{
    public function testILimitiDelServerSiLeggonoInByte(): void
    {
        $this->assertSame(40 * 1048576, LimitiCaricamento::byte('40M'));
        $this->assertSame(512 * 1024, LimitiCaricamento::byte('512K'));
        $this->assertSame(1073741824, LimitiCaricamento::byte('1G'));
        $this->assertSame(2097152, LimitiCaricamento::byte('2097152'));
        $this->assertSame(PHP_INT_MAX, LimitiCaricamento::byte('0'), '0 = nessun limite');
        $this->assertSame(PHP_INT_MAX, LimitiCaricamento::byte('-1'));
        $this->assertSame(PHP_INT_MAX, LimitiCaricamento::byte('boh'));
    }

    public function testUnFileNonPuoSuperareIDieciMegaNeIlLimiteDelServer(): void
    {
        $this->assertSame(10 * 1048576, (new LimitiCaricamento('40M', '40M', 20))->fileMax());
        $this->assertSame(2 * 1048576, (new LimitiCaricamento('40M', '2M', 20))->fileMax());
        $this->assertStringContainsString('al massimo 10 MB', (new LimitiCaricamento('40M', '40M', 20))->frase());
        $this->assertStringContainsString('al massimo 40 MB', (new LimitiCaricamento('40M', '40M', 20))->frase());
    }

    public function testUnInvioPiuGrandeDiPostMaxSiRiconosce(): void
    {
        $l = new LimitiCaricamento('40M', '40M', 20);
        $post = ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => (string) (41 * 1048576)];
        $this->assertTrue($l->invioTroppoGrande($post, [], []), 'PHP ha svuotato tutto');
        $this->assertFalse($l->invioTroppoGrande($post, ['titolo' => 'x'], []), 'se qualcosa è arrivato non è questo il caso');
        $this->assertFalse($l->invioTroppoGrande(['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '1000'], [], []), 'piccolo: altro motivo');
        $this->assertFalse($l->invioTroppoGrande(['REQUEST_METHOD' => 'GET', 'CONTENT_LENGTH' => (string) (99 * 1048576)], [], []));
        $this->assertFalse((new LimitiCaricamento('0', '40M', 20))->invioTroppoGrande($post, [], []), 'senza limite non succede');
        $this->assertStringContainsString('40 MB', $l->messaggioTroppoGrande());
        $this->assertStringContainsString('10 MB', $l->messaggioTroppoGrande());
    }

    public function testIProblemiDelServerSiDicono(): void
    {
        $this->assertSame([], (new LimitiCaricamento('40M', '40M', 20))->problemi());
        $this->assertCount(3, (new LimitiCaricamento('8M', '2M', 5))->problemi());
        $this->assertSame([], (new LimitiCaricamento('0', '0', 0))->problemi(), 'nessun limite = nessun problema');
    }

    public function testOgniCampoHaIlSuoPassoEGliErroriGeneraliVannoAlRiepilogo(): void
    {
        $this->assertSame(7, count(PassiRichiesta::PASSI));
        foreach (['ho_letto' => 1, 'data_evento' => 2, 'modalita' => 2, 'url_docente' => 2, 'altri_link_docente' => 2, 'titolo' => 3, 'sottotitolo' => 3, 'descrizione' => 3,
            'destinatari' => 4, 'allegati' => 5, 'immagini' => 5, 'tag' => 6, 'data_inizio_pubblicazione' => 6, 'note' => 6, '_' => 7] as $campo => $passo) {
            $this->assertSame($passo, PassiRichiesta::passoDi($campo), $campo);
        }
        $this->assertSame(3, PassiRichiesta::primoConErrore(['note' => 'x', 'sottotitolo' => 'y', 'allegati' => 'z']), 'il primo passo con un errore');
        $this->assertSame(7, PassiRichiesta::primoConErrore(['_' => 'x']));
        $this->assertNull(PassiRichiesta::primoConErrore([]));
        $this->assertSame(1, PassiRichiesta::primoConErrore(['tag' => 'x', 'ho_letto' => 'y']));
    }

    public function testLaCasellaHoLettoSiControllaAncheSulServer(): void
    {
        $this->assertArrayHasKey('ho_letto', PassiRichiesta::erroreHoLetto([]));
        $this->assertArrayHasKey('ho_letto', PassiRichiesta::erroreHoLetto(['ho_letto' => '']));
        $this->assertSame([], PassiRichiesta::erroreHoLetto(['ho_letto' => '1']));
    }

    public function testIlRiepilogoHaTuttiICampiInOrdineEScrivePulito(): void
    {
        $p = ['richiedente' => 'Rossi Maria', 'data_evento' => '2026-12-05', 'modalita' => 'online', 'url_docente' => 'https://x.unical.it/a?b=1&c=2',
            'altri_link_docente' => "https://a.it\nhttps://b.it", 'titolo' => 'A & B <i>', 'sottotitolo' => 'Sotto', 'descrizione' => '<p>Una <b>prova</b><script>x()</script></p>',
            'per_cittadinanza' => 1, 'per_studenti' => 0, 'per_docenti' => 1, 'per_pta' => 0, 'tag' => 'a, b', 'data_inizio_pubblicazione' => '2026-11-20', 'note' => "riga1\nriga2"];
        $allegati = [['tipo' => 'immagine', 'nome_originale' => 'f.png', 'dimensione' => 2500000], ['tipo' => 'allegato', 'nome_originale' => 'L <b>.pdf', 'dimensione' => 1000]];
        $righe = RiepilogoRichiesta::righe($p, $allegati);
        $this->assertSame(['Richiedente', 'Data dell\'evento', 'Modalità di svolgimento', 'URL', 'URL/Link ad altre pagine', 'Titolo', 'Sottotitolo', 'Breve descrizione',
            'Evento rivolto a', 'Allegati da pubblicare', 'Ulteriori immagini', 'Tag', 'Data inizio pubblicazione', 'Note'], array_column($righe, 0));
        $v = array_column($righe, 1, 0);
        $this->assertSame('05/12/2026', $v['Data dell\'evento']);
        $this->assertSame('Online', $v['Modalità di svolgimento']);
        $this->assertSame('Cittadinanza, Docenti', $v['Evento rivolto a']);
        $this->assertSame('A &amp; B &lt;i&gt;', $v['Titolo']);
        $this->assertStringContainsString('<b>prova</b>', $v['Breve descrizione']);
        $this->assertStringNotContainsString('<script', $v['Breve descrizione']);
        $this->assertStringContainsString('&amp;c=2', $v['URL']);
        $this->assertStringContainsString("riga1<br>\nriga2", $v['Note']);
        $this->assertStringContainsString('L &lt;b&gt;.pdf', $v['Allegati da pubblicare'], 'il nome del file è testo, non HTML');
        $this->assertStringContainsString('1 KB', $v['Allegati da pubblicare']);
        $this->assertStringContainsString('2,4 MB', $v['Ulteriori immagini']);
        $this->assertStringContainsString('<table', RiepilogoRichiesta::tabella($p, $allegati));
    }

    public function testICampiVuotiHannoUnTrattino(): void
    {
        $v = array_column(RiepilogoRichiesta::righe(['titolo' => 'Solo titolo'], []), 1, 0);
        $this->assertStringContainsString('—', $v['URL/Link ad altre pagine']);
        $this->assertStringContainsString('—', $v['Ulteriori immagini']);
        $this->assertSame('', RiepilogoRichiesta::data(''));
        $this->assertSame('boh', RiepilogoRichiesta::data('boh'));
    }
}
