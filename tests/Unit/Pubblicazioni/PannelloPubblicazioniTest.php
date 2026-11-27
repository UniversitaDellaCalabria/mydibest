<?php

declare(strict_types=1);

namespace Tests\Unit\Pubblicazioni;

use App\Pubblicazioni\ColonneMonitoraggio;
use App\Pubblicazioni\Completamento;
use App\Pubblicazioni\RegistroModifiche;
use App\Pubblicazioni\VistaRichiesta;
use PHPUnit\Framework\TestCase;

/** Parti del pannello senza database: colonne da completare, gruppi per persona, registro in parole, riga del docente e testo da copiare. */
final class PannelloPubblicazioniTest extends TestCase
{
    /** @return array<string, mixed> una riga con tutte le colonne degli operatori vuote */
    private function riga(array $cambi = []): array
    {
        return array_merge(array_fill_keys(ColonneMonitoraggio::colonneOperatori(), null), ['id' => 7], $cambi);
    }

    public function testLeColonneDaCompletareSiContanoSulleColonneDegliOperatori(): void
    {
        $this->assertSame(17, Completamento::numeroMancanti($this->riga()), 'le colonne degli operatori sono 17: C D E F J K L M N O P Q R S T U V');
        $r = $this->riga(['d_url_pubblicazione' => 'https://x.it', 'l_dest_cittadinanza' => 'SI', 'v_n_partecipanti' => '0', 'c_altri_referenti' => '   ', 'f_pubblicazione_social' => '']);
        $mancanti = Completamento::mancanti($r);
        $this->assertCount(14, $mancanti, '«0» è un valore; spazi e stringa vuota no');
        $this->assertNotContains('d_url_pubblicazione', $mancanti);
        $this->assertNotContains('v_n_partecipanti', $mancanti);
        $this->assertContains('c_altri_referenti', $mancanti);
        $this->assertContains('f_pubblicazione_social', $mancanti);
        // le colonne del docente (titolo, data…) non contano
        $this->assertSame(17, Completamento::numeroMancanti($this->riga(['titolo' => 'x', 'data_evento' => '2026-12-01', 'stato' => 'ricevuta'])));
        $piena = array_fill_keys(ColonneMonitoraggio::colonneOperatori(), 'x');
        $this->assertSame(0, Completamento::numeroMancanti($piena));
    }

    public function testIlTestoDelleColonneMancanti(): void
    {
        $this->assertSame('6 da completare', Completamento::testo(6));
        $this->assertSame('1 da completare', Completamento::testo(1));
        $this->assertSame('Tutte compilate', Completamento::testo(0));
    }

    public function testLeColonneSiRaggruppanoPerPersonaNellOrdineDellaScheda(): void
    {
        $gruppi = Completamento::gruppiPerPersona();
        $this->assertSame(
            ['Floriana Mantovano', 'Silvana Geracitano', 'Gianni Giglio', 'Marco Bocci o Emanuele Dodaro', 'Floriana Mantovano o Silvana Geracitano'],
            array_column($gruppi, 'a_cura_di')
        );
        $lettere = static fn (array $g): string => implode('', array_column($g['colonne'], 'lettera'));
        $this->assertSame('CJKLMNO', $lettere($gruppi[0]));
        $this->assertSame('PQRST', $lettere($gruppi[1]));
        $this->assertSame('F', $lettere($gruppi[2]));
        $this->assertSame('DEU', $lettere($gruppi[3]));
        $this->assertSame('V', $lettere($gruppi[4]));
        // ogni colonna degli operatori compare una volta sola
        $tutte = [];
        foreach ($gruppi as $g) {
            foreach ($g['colonne'] as $c) {
                $tutte[] = $c['chiave'];
            }
        }
        $this->assertEqualsCanonicalizing(ColonneMonitoraggio::colonneOperatori(), $tutte);
        $this->assertSame(count($tutte), count(array_unique($tutte)));
    }

    public function testIlRegistroScriveLeModificheInParole(): void
    {
        $json = json_encode(['id' => 7, 'titolo' => 'Notte', 'cambi' => [
            'd_url_pubblicazione' => ['da' => null, 'a' => 'https://dibest.unical.it/n'],
            'f_pubblicazione_social' => ['da' => 'NO', 'a' => 'SI'],
            'stato' => ['da' => 'ricevuta', 'a' => 'lavorazione'],
        ]], JSON_UNESCAPED_UNICODE);
        $v = RegistroModifiche::voce('Richieste di pubblicazione: modifica', (string) $json);
        $this->assertSame('Modifica', $v['titolo']);
        $this->assertSame([
            'D · URL effettiva pubblicazione sito: (vuoto) → «https://dibest.unical.it/n»',
            'F · Pubblicazione social (SI/NO): «NO» → «SI»',
            'Stato: Approvata → In lavorazione',
        ], $v['righe']);
    }

    public function testIlRegistroDiUnaRichiestaRespintaEDiUnaColonnaSvuotata(): void
    {
        $json = (string) json_encode(['id' => 7, 'cambi' => [
            'c_altri_referenti' => ['da' => "Prof. Bianchi\nProf. Neri", 'a' => null],
            'stato' => ['da' => 'lavorazione', 'a' => 'respinta'],
            'nel_monitoraggio' => ['da' => 1, 'a' => 0],
        ]]);
        $v = RegistroModifiche::voce('Richieste di pubblicazione: modifica', $json);
        $this->assertSame([
            'C · Altri referenti (interni o esterni all\'Unical): «Prof. Bianchi · Prof. Neri» → (vuoto)',
            'Stato: In lavorazione → Respinta',
            'La riga è uscita dal file Excel.',
        ], $v['righe']);
    }

    public function testIlRegistroDelleAltreOperazioni(): void
    {
        $v = RegistroModifiche::voce('Richieste di pubblicazione: inviata al file Excel', (string) json_encode(['id' => 7, 'precompilate' => ['d_url_pubblicazione', 'p_dest_studenti']]));
        $this->assertSame('Inviata al file Excel', $v['titolo']);
        $this->assertSame(['Precompilate dai dati del docente: D · URL effettiva pubblicazione sito, P · Destinatari STUDENTI /CdL.'], $v['righe']);
        $this->assertSame('Tolta dal file Excel', RegistroModifiche::voce('Richieste di pubblicazione: tolta dal file Excel', '{"id":7}')['titolo']);
        $this->assertSame('Riga aggiunta a mano', RegistroModifiche::voce('Richieste di pubblicazione: riga aggiunta a mano', '{"id":7}')['titolo']);
        // dettagli mancanti o rovinati non fanno errori
        $this->assertSame([], RegistroModifiche::voce('Richieste di pubblicazione: modifica', '')['righe']);
        $this->assertSame([], RegistroModifiche::voce('Richieste di pubblicazione: modifica', '{rotto')['righe']);
        // un valore molto lungo si accorcia
        $lungo = RegistroModifiche::voce('Richieste di pubblicazione: modifica', (string) json_encode(['id' => 7, 'cambi' => ['c_altri_referenti' => ['da' => null, 'a' => str_repeat('x', 400)]]]));
        $this->assertLessThan(260, mb_strlen($lungo['righe'][0]));
        $this->assertStringContainsString('…', $lungo['righe'][0]);
    }

    public function testLaRigaDelDocenteHaIFileScaricabiliEIlRestoEscapato(): void
    {
        $p = [
            'id' => 7, 'richiedente' => 'Rossi <b>Maria</b>', 'data_evento' => '2026-12-05', 'modalita' => 'online', 'url_docente' => 'https://a.it/x?a=1&b=2',
            'altri_link_docente' => "https://b.it/1\nhttps://c.it/2", 'titolo' => 'Notte <script>', 'sottotitolo' => 'Sotto', 'descrizione' => '<p>Una <b>serata</b></p>',
            'per_cittadinanza' => 1, 'per_studenti' => 0, 'per_docenti' => 1, 'per_pta' => 0, 'tag' => 'a, b', 'data_inizio_pubblicazione' => '2026-11-30', 'note' => 'nessuna',
        ];
        $file = [
            ['id' => 31, 'tipo' => 'allegato', 'nome_originale' => 'locandina "bella".pdf', 'dimensione' => 2048],
            ['id' => 32, 'tipo' => 'immagine', 'nome_originale' => 'foto.png', 'dimensione' => 3 * 1048576],
        ];
        $righe = [];
        foreach (VistaRichiesta::righe($p, $file) as [$et, $val]) {
            $righe[$et] = $val;
        }
        $this->assertSame(
            ['Richiedente', 'Data dell\'evento', 'Modalità di svolgimento', 'URL', 'URL/Link ad altre pagine', 'Titolo', 'Sottotitolo', 'Breve descrizione', 'Evento rivolto a',
                'Altri allegati', 'Ulteriori immagini', 'Tag', 'Data inizio pubblicazione', 'Note'],
            array_keys($righe)
        );
        $this->assertStringContainsString('href="../allegato_pubblicazione.php?p=7&amp;a=31"', $righe['Altri allegati']);
        $this->assertStringContainsString('locandina &quot;bella&quot;.pdf', $righe['Altri allegati']);
        $this->assertStringContainsString('(2 KB)', $righe['Altri allegati']);
        $this->assertStringContainsString('href="../allegato_pubblicazione.php?p=7&amp;a=32"', $righe['Ulteriori immagini']);
        $this->assertStringNotContainsString('a=32', $righe['Altri allegati'], 'ogni file nella sua riga');
        $this->assertStringContainsString('Rossi &lt;b&gt;Maria&lt;/b&gt;', $righe['Richiedente']);
        $this->assertStringContainsString('Notte &lt;script&gt;', $righe['Titolo']);
        $this->assertStringNotContainsString('<script>', implode('', $righe));
        $this->assertSame('05/12/2026', $righe['Data dell\'evento']);
        $this->assertSame('Online', $righe['Modalità di svolgimento']);
        $this->assertSame('Cittadinanza, Docenti', $righe['Evento rivolto a']);
        $this->assertStringContainsString('<b>serata</b>', $righe['Breve descrizione'], 'la descrizione mantiene grassetto e corsivo');
        // senza file: trattino
        $senza = VistaRichiesta::righe($p, []);
        $this->assertStringContainsString('—', $senza[9][1]);
        $this->assertStringNotContainsString('allegato_pubblicazione.php', implode('', array_column($senza, 1)));
    }

    public function testIlTestoDaCopiareEFormattatoSenzaFormattazione(): void
    {
        $p = ['titolo' => '  Notte dei ricercatori ', 'sottotitolo' => 'Laboratori aperti', 'descrizione' => '<p>Una <b>serata</b> &amp; giochi.</p><p>Per tutti</p>'];
        $this->assertSame("Notte dei ricercatori\n\nLaboratori aperti\n\nUna serata & giochi. Per tutti", VistaRichiesta::testoDaCopiare($p));
        // le parti vuote non lasciano righe vuote
        $this->assertSame("Solo titolo", VistaRichiesta::testoDaCopiare(['titolo' => 'Solo titolo', 'sottotitolo' => '', 'descrizione' => null]));
    }
}
