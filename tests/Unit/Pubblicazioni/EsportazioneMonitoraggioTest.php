<?php

declare(strict_types=1);

namespace Tests\Unit\Pubblicazioni;

use App\Pubblicazioni\ColonneMonitoraggio;
use App\Pubblicazioni\EsportazioneMonitoraggio;
use PHPUnit\Framework\TestCase;

final class EsportazioneMonitoraggioTest extends TestCase
{
    /**
     * Le celle della prima riga di «DiBEST_Tabella di monitoraggio.xlsx» (materiali/pubblicazioni/), copiate carattere per carattere
     * (spazi doppi e finali compresi), colonne A–V.
     */
    private const EXCEL = [
        'A' => 'Data svolgimento ',
        'B' => 'Persona che ha richiesto la pubblicazione',
        'C' => "Altri referenti (interni o esterni all'Unical) (a cura di Floriana Mantovano)",
        'D' => 'URL effettiva pubblicazione sito (a cura di Marco Bocci o Emanuele Dodaro)',
        'E' => 'URL/Link ad altre pagine effettiva pubblicazione sito  (a cura di Marco Bocci o Emanuele Dodaro)',
        'F' => 'Pubblicazione social (SI/NO) (a cura di Gianni Giglio)',
        'G' => 'Titolo e sottotitolo iniziativa',
        'H' => 'Breve descrizione',
        'I' => 'Modalità di svolgimento',
        'J' => 'Tipo iniziativa (a cura di Floriana Mantovano)',
        'K' => 'Dimensione Geografica  (a cura di Floriana Mantovano)',
        'L' => 'Destinatari Cittadinanza (a cura di Floriana Mantovano)',
        'M' => 'I.DiBEST.01 [S.2] - Diffusione della comunicazione delle attività museali di PE (a cura di Floriana Mantovano)',
        'N' => 'I.DiBEST.02 [S.2] - Diffusione della comunicazione delle attività di PE (a cura di Floriana Mantovano)',
        'O' => 'Piattaforma PE (SI/NO) (a cura di Floriana Mantovano)',
        'P' => 'Destinatari STUDENTI /CdL (a cura di Silvana Geracitano)',
        'Q' => 'Destinatari Docenti (a cura di Silvana Geracitano)',
        'R' => 'Destinatari PTA (a cura di Silvana Geracitano)',
        'S' => 'I.DiBEST.03 [P.1] - Efficacia comunicazione pagine social di Dipartimento (a cura di Silvana Geracitano)',
        'T' => 'I.DiBEST.04 [P.2] - Partecipazione del personale in attività di formazione DEI (Diversity, Equity and Inclusion) (a cura di Silvana Geracitano)',
        'U' => 'Produzione locandina (Si - NO)  (a cura di Marco Bocci o Emanuele Dodaro)',
        'V' => 'N.  Partecipanti  (a cura di Floriana Mantovano o Silvana Geracitano)',
    ];

    /** @param array<string, mixed> $cambi @return array<string, mixed> */
    private function riga(array $cambi = []): array
    {
        $r = array_fill_keys(ColonneMonitoraggio::chiavi(), null);

        return array_merge($r, [
            'id' => 1, 'nel_monitoraggio' => 1, 'stato' => 'lavorazione', 'data_evento' => '2026-12-05', 'richiedente' => 'Rossi Maria',
            'titolo' => 'Notte dei ricercatori', 'sottotitolo' => 'Laboratori aperti', 'descrizione' => '<p>Una serata di <strong>laboratori</strong> &amp; giochi.</p><p>Per tutti</p>',
            'modalita' => 'mista', 'd_url_pubblicazione' => 'https://dibest.unical.it/n/1', 'l_dest_cittadinanza' => 'SI', 'v_n_partecipanti' => '120',
        ], $cambi);
    }

    public function testVentiquattroColonneConLeIntestazioniDellExcelNelloStessoOrdine(): void
    {
        $t = EsportazioneMonitoraggio::tabella([]);
        $this->assertCount(24, $t['intestazioni']);
        $attese = array_values(self::EXCEL);
        $attese[6] = 'Titolo iniziativa';                         // G: l'unica intestazione diversa dall'Excel (decisa il 19/11/2026)
        array_splice($attese, 7, 0, ['Sottotitolo iniziativa']);  // subito dopo G
        $attese[] = 'Stato';                                      // in fondo, dopo V
        $this->assertSame($attese, $t['intestazioni']);
        $this->assertSame('Stato', end($t['intestazioni']));
        $this->assertSame(self::EXCEL['V'], $t['intestazioni'][22]);
        // le lettere della scheda corrispondono alle colonne dell'Excel (tranne il nome di G)
        foreach (ColonneMonitoraggio::tutte() as $c) {
            if ($c['lettera'] !== '' && $c['lettera'] !== 'G') {
                $this->assertSame(self::EXCEL[$c['lettera']], $c['intestazione'], $c['chiave']);
            }
        }
        $this->assertSame('Titolo iniziativa', ColonneMonitoraggio::perChiave('titolo')['intestazione']);
        $this->assertCount(17, ColonneMonitoraggio::colonneOperatori());
    }

    public function testSoloLeRigheNelMonitoraggio(): void
    {
        $t = EsportazioneMonitoraggio::tabella([
            $this->riga(['id' => 1, 'titolo' => 'Dentro']),
            $this->riga(['id' => 2, 'titolo' => 'Fuori', 'nel_monitoraggio' => 0]),
            $this->riga(['id' => 3, 'titolo' => 'Anche dentro', 'nel_monitoraggio' => '1']),
        ]);
        $this->assertCount(2, $t['righe']);
        $this->assertSame(['Dentro', 'Anche dentro'], array_column($t['righe'], 6));
    }

    public function testCelleDateDescrizioneEModalita(): void
    {
        $r = EsportazioneMonitoraggio::tabella([$this->riga()])['righe'][0];
        $this->assertCount(24, $r);
        $this->assertSame('In lavorazione', end($r), 'lo stato in parole in fondo');
        $chiavi = array_flip(ColonneMonitoraggio::chiavi());
        $this->assertSame('05/12/2026', $r[$chiavi['data_evento']]);
        $this->assertSame('Rossi Maria', $r[$chiavi['richiedente']]);
        $this->assertSame('Laboratori aperti', $r[$chiavi['sottotitolo']]);
        $this->assertSame('Una serata di laboratori & giochi. Per tutti', $r[$chiavi['descrizione']]);
        $this->assertSame('Mista', $r[$chiavi['modalita']]);
        $this->assertSame('SI', $r[$chiavi['l_dest_cittadinanza']]);
        $this->assertSame('120', $r[$chiavi['v_n_partecipanti']]);
        $this->assertSame('', $r[$chiavi['c_altri_referenti']]);
        $this->assertSame('In lavorazione', EsportazioneMonitoraggio::stato('lavorazione'));
        $this->assertSame('In presenza', EsportazioneMonitoraggio::modalita('presenza'));
    }

    public function testApostrofoControLeFormuleSoloSeRichiesto(): void
    {
        $riga = $this->riga(['c_altri_referenti' => '=HYPERLINK("http://x")', 'v_n_partecipanti' => '-5']);
        $chiavi = array_flip(ColonneMonitoraggio::chiavi());
        $normale = EsportazioneMonitoraggio::tabella([$riga])['righe'][0];
        $this->assertSame('=HYPERLINK("http://x")', $normale[$chiavi['c_altri_referenti']]);
        $csv = EsportazioneMonitoraggio::tabella([$riga], true)['righe'][0];
        $this->assertSame('\'=HYPERLINK("http://x")', $csv[$chiavi['c_altri_referenti']]);
        $this->assertSame('-5', $csv[$chiavi['v_n_partecipanti']], 'i numeri restano numeri');
    }

    public function testColoriEACuraDi(): void
    {
        $g = ColonneMonitoraggio::perChiave('titolo');
        $s = ColonneMonitoraggio::perChiave('sottotitolo');
        $this->assertSame($g['colore'], $s['colore'], 'il sottotitolo ha il colore del titolo');
        $this->assertSame('Gianni Giglio', ColonneMonitoraggio::perChiave('f_pubblicazione_social')['a_cura_di']);
        $this->assertSame('#FFC000', ColonneMonitoraggio::perChiave('p_dest_studenti')['colore']);
        $this->assertSame('si_no', ColonneMonitoraggio::perChiave('u_produzione_locandina')['tipo']);
        $this->assertSame('Dimensione Geografica', ColonneMonitoraggio::etichetta('k_dimensione_geografica'));
        $this->assertSame('N. Partecipanti', ColonneMonitoraggio::etichetta('v_n_partecipanti'));
    }
}
