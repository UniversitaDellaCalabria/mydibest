<?php

declare(strict_types=1);

namespace Tests\Unit\Pubblicazioni;

use App\Pubblicazioni\RegolePubblicazione;
use PHPUnit\Framework\TestCase;
use Tests\Doppi\FileDiProva;

final class RegolePubblicazioneTest extends TestCase
{
    private const OGGI = '2026-11-19';

    protected function tearDown(): void
    {
        FileDiProva::pulisci();
    }

    /** Una richiesta completa e corretta, con le sovrascritture indicate. @param array<string, mixed> $cambi @return array<string, mixed> */
    private function dati(array $cambi = []): array
    {
        return array_merge([
            'data_evento' => '2026-12-05',
            'url_docente' => 'https://www.unical.it/eventi/notte-dei-ricercatori',
            'altri_link_docente' => '',
            'titolo' => 'Notte dei ricercatori',
            'sottotitolo' => 'Laboratori aperti al pubblico',
            'descrizione' => '<p>Una serata di <strong>laboratori</strong> aperti a tutti.</p>',
            'modalita' => 'presenza',
            'per_cittadinanza' => '1',
            'note' => 'nessuna',
            'tag' => 'DIBEST, notizie',
            'data_inizio_pubblicazione' => self::OGGI,
        ], $cambi);
    }

    /** @return array{allegati: list<array<string, mixed>>, immagini: list<array<string, mixed>>} */
    private function file(): array
    {
        return ['allegati' => [FileDiProva::pdf()], 'immagini' => []];
    }

    public function testRichiestaCompletaSenzaErrori(): void
    {
        $this->assertSame([], RegolePubblicazione::valida($this->dati(), $this->file(), self::OGGI));
    }

    public function testObbligatoriTuttiTranneAltriLinkEImmagini(): void
    {
        $vuoti = array_fill_keys(['data_evento', 'url_docente', 'titolo', 'sottotitolo', 'descrizione', 'modalita', 'note', 'tag', 'data_inizio_pubblicazione'], '');
        $e = RegolePubblicazione::valida(array_merge($vuoti, ['per_cittadinanza' => '']), ['allegati' => [], 'immagini' => []], self::OGGI);
        foreach (array_merge(array_keys($vuoti), ['destinatari', 'allegati']) as $k) {
            $this->assertArrayHasKey($k, $e, "manca l'errore per $k");
        }
        $this->assertArrayNotHasKey('altri_link_docente', $e);
        $this->assertArrayNotHasKey('immagini', $e);
    }

    public function testSottotitoloAlCarattereEsattoConLettereAccentate(): void
    {
        $settantacinque = str_repeat('à', 70) . ' èìòù';   // 75 caratteri (spazio incluso), più di 75 byte
        $this->assertSame(75, mb_strlen($settantacinque));
        $this->assertArrayNotHasKey('sottotitolo', RegolePubblicazione::valida($this->dati(['sottotitolo' => $settantacinque]), $this->file(), self::OGGI));
        $e = RegolePubblicazione::valida($this->dati(['sottotitolo' => $settantacinque . 'ù']), $this->file(), self::OGGI);
        $this->assertStringContainsString('76 caratteri', $e['sottotitolo']);
    }

    public function testDescrizioneAlCarattereEsattoSenzaContareLaFormattazione(): void
    {
        $testo = str_repeat('é', 160) . ' città!!!!';   // 170 caratteri
        $this->assertSame(170, mb_strlen($testo));
        $html = '<p><strong>' . mb_substr($testo, 0, 50) . '</strong><em>' . mb_substr($testo, 50) . '</em></p>';
        $this->assertSame(170, RegolePubblicazione::lunghezzaTesto($html));
        $this->assertArrayNotHasKey('descrizione', RegolePubblicazione::valida($this->dati(['descrizione' => $html]), $this->file(), self::OGGI));
        $e = RegolePubblicazione::valida($this->dati(['descrizione' => '<p>' . $testo . 'è</p>']), $this->file(), self::OGGI);
        $this->assertStringContainsString('171 caratteri', $e['descrizione']);
        // le entità contano un carattere solo
        $this->assertSame(5, RegolePubblicazione::lunghezzaTesto('a &amp; b'));
    }

    public function testDescrizioneRidottaAllHtmlSemplice(): void
    {
        $d = RegolePubblicazione::normalizza($this->dati(['descrizione' => '<p onclick="x()">Ciao <b>tutti</b><script>alert(1)</script></p>']));
        $this->assertStringNotContainsString('script', (string) $d['descrizione']);
        $this->assertStringNotContainsString('onclick', (string) $d['descrizione']);
        $this->assertStringContainsString('<b>tutti</b>', (string) $d['descrizione']);
    }

    public function testAlmenoUnDestinatario(): void
    {
        $e = RegolePubblicazione::valida($this->dati(['per_cittadinanza' => '']), $this->file(), self::OGGI);
        $this->assertArrayHasKey('destinatari', $e);
        foreach (['per_studenti', 'per_docenti', 'per_pta'] as $k) {
            $this->assertArrayNotHasKey('destinatari', RegolePubblicazione::valida($this->dati(['per_cittadinanza' => '', $k => '1']), $this->file(), self::OGGI), $k);
        }
    }

    public function testIndirizziWeb(): void
    {
        foreach (['www.unical.it', 'ftp://unical.it/file', 'javascript:alert(1)', 'https://', 'unical'] as $sbagliato) {
            $this->assertArrayHasKey('url_docente', RegolePubblicazione::valida($this->dati(['url_docente' => $sbagliato]), $this->file(), self::OGGI), $sbagliato);
        }
        $this->assertArrayNotHasKey('url_docente', RegolePubblicazione::valida($this->dati(['url_docente' => 'http://dibest.unical.it/pagina?id=3']), $this->file(), self::OGGI));
        // altri link: facoltativi, uno per riga, tutti validi
        $ok = "https://a.unical.it/uno\nhttps://b.unical.it/due";
        $this->assertArrayNotHasKey('altri_link_docente', RegolePubblicazione::valida($this->dati(['altri_link_docente' => $ok]), $this->file(), self::OGGI));
        $this->assertSame($ok, RegolePubblicazione::normalizza($this->dati(['altri_link_docente' => "https://a.unical.it/uno\r\n\r\nhttps://b.unical.it/due "]))['altri_link_docente']);
        $this->assertNull(RegolePubblicazione::normalizza($this->dati())['altri_link_docente']);
        $e = RegolePubblicazione::valida($this->dati(['altri_link_docente' => "https://a.unical.it/uno\nwww.b.it"]), $this->file(), self::OGGI);
        $this->assertStringContainsString('www.b.it', $e['altri_link_docente']);
    }

    public function testDate(): void
    {
        $this->assertArrayHasKey('data_inizio_pubblicazione', RegolePubblicazione::valida($this->dati(['data_inizio_pubblicazione' => '2026-11-18']), $this->file(), self::OGGI), 'ieri');
        $this->assertArrayNotHasKey('data_inizio_pubblicazione', RegolePubblicazione::valida($this->dati(['data_inizio_pubblicazione' => '2027-01-10']), $this->file(), self::OGGI));
        $this->assertArrayHasKey('data_inizio_pubblicazione', RegolePubblicazione::valida($this->dati(['data_inizio_pubblicazione' => '2026-02-30']), $this->file(), self::OGGI), 'data inesistente');
        $this->assertArrayHasKey('data_evento', RegolePubblicazione::valida($this->dati(['data_evento' => '05/12/2026']), $this->file(), self::OGGI));
        // l'evento può essere già passato (si pubblica anche il resoconto)
        $this->assertArrayNotHasKey('data_evento', RegolePubblicazione::valida($this->dati(['data_evento' => '2026-10-01']), $this->file(), self::OGGI));
    }

    public function testModalitaTraLeTre(): void
    {
        foreach (['presenza', 'online', 'mista'] as $m) {
            $this->assertArrayNotHasKey('modalita', RegolePubblicazione::valida($this->dati(['modalita' => $m]), $this->file(), self::OGGI), $m);
        }
        $this->assertArrayHasKey('modalita', RegolePubblicazione::valida($this->dati(['modalita' => 'ibrida']), $this->file(), self::OGGI));
    }

    public function testTipiDiFileControllatiSulContenuto(): void
    {
        $ok = ['allegati' => [FileDiProva::pdf(), FileDiProva::jpg('foto.JPG'), FileDiProva::png()], 'immagini' => [FileDiProva::jpg(), FileDiProva::png()]];
        $this->assertSame([], RegolePubblicazione::valida($this->dati(), $ok, self::OGGI));
        // un testo chiamato .pdf, un PNG chiamato .pdf, un Word
        foreach ([FileDiProva::testo('finto.pdf'), FileDiProva::png('immagine.pdf'), FileDiProva::testo('lettera.docx')] as $f) {
            $e = RegolePubblicazione::valida($this->dati(), ['allegati' => [$f]], self::OGGI);
            $this->assertStringContainsString('PDF, JPG o PNG', $e['allegati'] ?? '', $f['name']);
        }
        // le immagini solo JPG o PNG: un PDF non va
        $e = RegolePubblicazione::valida($this->dati(), ['allegati' => [FileDiProva::pdf()], 'immagini' => [FileDiProva::pdf('anteprima.pdf')]], self::OGGI);
        $this->assertStringContainsString('JPG o PNG', $e['immagini'] ?? '');
        // file troppo grande o non arrivato
        $grande = FileDiProva::pdf();
        $grande['size'] = 11 * 1024 * 1024;
        $this->assertStringContainsString('troppo grande', RegolePubblicazione::valida($this->dati(), ['allegati' => [$grande]], self::OGGI)['allegati'] ?? '');
        $rotto = ['name' => 'x.pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0];
        $this->assertStringContainsString('non è arrivato', RegolePubblicazione::valida($this->dati(), ['allegati' => [$rotto]], self::OGGI)['allegati'] ?? '');
    }

    public function testRigheAManoBastanoDataRichiedenteETitolo(): void
    {
        $tre = ['data_evento' => '2026-10-01', 'richiedente' => 'Verdi Paolo', 'titolo' => 'Iniziativa arrivata per email'];
        $this->assertSame([], RegolePubblicazione::valida($tre, [], self::OGGI, true));
        // senza i tre campi
        $e = RegolePubblicazione::valida([], [], self::OGGI, true);
        $this->assertSame(['data_evento', 'richiedente', 'titolo'], array_keys($e));
        // il form del docente invece vuole tutto (il richiedente lì viene dall'account, non si controlla)
        $form = RegolePubblicazione::valida($tre, [], self::OGGI);
        $this->assertArrayNotHasKey('richiedente', $form);
        foreach (['url_docente', 'sottotitolo', 'descrizione', 'modalita', 'destinatari', 'note', 'tag', 'data_inizio_pubblicazione', 'allegati'] as $k) {
            $this->assertArrayHasKey($k, $form, $k);
        }
    }

    public function testRigheAManoQuelloCheECompilatoSiControllaLoStesso(): void
    {
        $base = ['data_evento' => '2026-10-01', 'richiedente' => 'Verdi Paolo', 'titolo' => 'Iniziativa'];
        $sbagliati = [
            'url_docente' => 'www.unical.it', 'altri_link_docente' => 'ftp://x.it', 'sottotitolo' => str_repeat('à', 76),
            'descrizione' => str_repeat('è', 171), 'modalita' => 'ibrida', 'data_inizio_pubblicazione' => '2026-02-30',
        ];
        $e = RegolePubblicazione::valida($base + $sbagliati, ['allegati' => [FileDiProva::testo('x.pdf')], 'immagini' => [FileDiProva::pdf()]], self::OGGI, true);
        $this->assertSame(array_merge(array_keys($sbagliati), ['allegati', 'immagini']), array_keys($e));
        $this->assertArrayNotHasKey('destinatari', $e, 'a mano nessun destinatario obbligatorio');
    }

    public function testDataDiInizioPassataAccettataAManoRifiutataNelForm(): void
    {
        $ieri = ['data_inizio_pubblicazione' => '2026-11-18'];
        $this->assertArrayHasKey('data_inizio_pubblicazione', RegolePubblicazione::valida($this->dati($ieri), $this->file(), self::OGGI));
        $this->assertArrayNotHasKey('data_inizio_pubblicazione', RegolePubblicazione::valida($this->dati($ieri + ['richiedente' => 'Verdi Paolo']), [], self::OGGI, true));
        $this->assertSame([], RegolePubblicazione::valida($this->dati($ieri + ['richiedente' => 'Verdi Paolo']), [], self::OGGI, true));
    }

    public function testAlMassimoDieciAllegatiEDieciImmagini(): void
    {
        $dieci = array_map(static fn (int $i): array => FileDiProva::pdf("a$i.pdf"), range(1, 10));
        $foto = array_map(static fn (int $i): array => FileDiProva::png("f$i.png"), range(1, 10));
        $this->assertSame([], RegolePubblicazione::valida($this->dati(), ['allegati' => $dieci, 'immagini' => $foto], self::OGGI));
        $e = RegolePubblicazione::valida($this->dati(), ['allegati' => array_merge($dieci, [FileDiProva::pdf()]), 'immagini' => array_merge($foto, [FileDiProva::jpg()])], self::OGGI);
        $this->assertSame('Puoi caricare al massimo 10 allegati.', $e['allegati'] ?? '');
        $this->assertSame('Puoi caricare al massimo 10 immagini.', $e['immagini'] ?? '');
        // anche nelle righe a mano
        $this->assertArrayHasKey('allegati', RegolePubblicazione::valida($this->dati(['richiedente' => 'X Y']), ['allegati' => array_merge($dieci, [FileDiProva::pdf()])], self::OGGI, true));
    }

    public function testAllegatiFacoltativiPerLeRigheAMano(): void
    {
        $this->assertArrayNotHasKey('allegati', RegolePubblicazione::valida($this->dati(['richiedente' => 'X Y']), ['allegati' => []], self::OGGI, true));
        // ma se ci sono, il tipo si controlla lo stesso
        $this->assertArrayHasKey('allegati', RegolePubblicazione::valida($this->dati(['richiedente' => 'X Y']), ['allegati' => [FileDiProva::testo('x.pdf')]], self::OGGI, true));
    }

    public function testElencoDeiFileDaCaricamentoMultiplo(): void
    {
        $campo = [
            'name' => ['a.pdf', ''], 'type' => ['application/pdf', ''], 'tmp_name' => ['/tmp/a', ''], 'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], 'size' => [10, 0],
        ];
        $elenco = \App\Pubblicazioni\AllegatiPubblicazioni::elenco($campo);
        $this->assertCount(1, $elenco);
        $this->assertSame('a.pdf', $elenco[0]['name']);
        $this->assertSame([], \App\Pubblicazioni\AllegatiPubblicazioni::elenco([]));
    }
}
