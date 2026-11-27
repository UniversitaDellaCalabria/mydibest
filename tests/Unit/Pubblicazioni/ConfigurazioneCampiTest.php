<?php

declare(strict_types=1);

namespace Tests\Unit\Pubblicazioni;

use App\Pubblicazioni\ColonneMonitoraggio;
use App\Pubblicazioni\Completamento;
use App\Pubblicazioni\ConfigurazioneCampi;
use App\Pubblicazioni\EsportazioneMonitoraggio;
use App\Pubblicazioni\PassiRichiesta;
use App\Pubblicazioni\RegolePubblicazione;
use App\Pubblicazioni\ServizioPubblicazioni;
use PHPUnit\Framework\TestCase;
use Tests\Doppi\FileDiProva;

/**
 * ConfigurazioneCampi (AM4) senza database: i valori del codice uniti a quello che l'amministratore ha salvato (righe passate a mano).
 * Senza righe il risultato è identico a prima di AM4; le regole fisse non si possono cambiare; un campo nascosto non si chiede, non si controlla
 * e la sua colonna resta vuota; un campo facoltativo si controlla solo se compilato.
 */
final class ConfigurazioneCampiTest extends TestCase
{
    /** @param list<array<string, mixed>> $campi @param list<array<string, mixed>> $colonne */
    private function config(array $campi = [], array $colonne = []): ConfigurazioneCampi
    {
        return new ConfigurazioneCampi(null, $campi, $colonne);
    }

    /** @param array<string, mixed> $cambi @return array<string, mixed> */
    private function riga(array $cambi = []): array
    {
        $r = array_fill_keys(ColonneMonitoraggio::chiavi(), null);

        return array_merge($r, [
            'id' => 1, 'nel_monitoraggio' => 1, 'stato' => 'lavorazione', 'data_evento' => '2026-12-05', 'richiedente' => 'Rossi Maria',
            'titolo' => 'Notte dei ricercatori', 'sottotitolo' => 'Laboratori aperti', 'descrizione' => '<p>Una serata.</p>', 'modalita' => 'mista',
            'd_url_pubblicazione' => 'https://dibest.unical.it/n/1', 'l_dest_cittadinanza' => 'SI', 'v_n_partecipanti' => '120',
        ], $cambi);
    }

    /** I dati di un form compilato bene (come in RegolePubblicazioneTest). */
    private function dati(array $cambi = []): array
    {
        return array_merge([
            'data_evento' => '2026-12-05', 'url_docente' => 'https://www.unical.it/eventi/notte', 'altri_link_docente' => '',
            'titolo' => 'Notte dei ricercatori', 'sottotitolo' => 'Laboratori aperti', 'descrizione' => '<p>Una serata di laboratori.</p>',
            'modalita' => 'presenza', 'per_cittadinanza' => '1', 'note' => 'nessuna', 'tag' => 'DIBEST', 'data_inizio_pubblicazione' => '2026-11-20',
        ], $cambi);
    }

    protected function tearDown(): void
    {
        FileDiProva::pulisci();
    }

    /** @return array<string, mixed> un PDF vero */
    private function allegato(): array
    {
        return FileDiProva::pdf();
    }

    // ───── senza righe salvate = come prima di AM4 ─────

    public function testSenzaRigheEIdenticoAlCodiceDiPartenza(): void
    {
        $c = $this->config();
        $this->assertSame(ColonneMonitoraggio::chiavi(), $c->chiaviExcel());
        $this->assertSame(ColonneMonitoraggio::intestazioni(), $c->intestazioniExcel());
        $this->assertSame(ColonneMonitoraggio::colonneOperatori(), $c->colonneOperatori());
        $this->assertSame(array_values(ColonneMonitoraggio::tutte()), array_map(static fn (array $x): array => array_intersect_key($x, ColonneMonitoraggio::tutte()[0]), $c->colonne()));
        foreach (ColonneMonitoraggio::chiavi() as $k) {
            $this->assertSame(ColonneMonitoraggio::etichetta($k), $c->etichettaColonna($k), "etichetta della colonna $k");
            $this->assertFalse($c->colonnaDaCampoNascosto($k));
        }
        $this->assertSame([], $c->nascosti());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $c->passiVisibili());
        foreach (ConfigurazioneCampi::CAMPI as $k => $d) {
            $x = $c->campo($k);
            $this->assertSame([$d['etichetta'], $d['aiuto'], $d['obbligatorio'], true, false], [$x['etichetta'], $x['aiuto'], $x['obbligatorio'], $x['visibile'], $x['modificato']], "campo $k");
        }
    }

    public function testSenzaRigheLEsportazioneEQuellaDiPrima(): void
    {
        $righe = [$this->riga(), $this->riga(['id' => 2, 'titolo' => 'Seconda', 'nel_monitoraggio' => 0])];
        $tab = EsportazioneMonitoraggio::tabella($righe);
        $this->assertSame(ColonneMonitoraggio::intestazioni(), $tab['intestazioni']);
        $this->assertCount(1, $tab['righe'], 'solo le righe nel file Excel');
        $this->assertSame($tab, EsportazioneMonitoraggio::tabella($righe, false, $this->config()), 'con la configurazione vuota è identico');
        $this->assertSame(count(ColonneMonitoraggio::chiavi()), count($tab['righe'][0]));
    }

    public function testSenzaRigheLaValidazioneEQuellaDiSempre(): void
    {
        $regole = $this->config()->regole();
        $file = ['locandina' => [$this->allegato()]];
        $this->assertSame([], RegolePubblicazione::valida($this->dati(), $file, '2026-11-19', false, [], $regole));
        $this->assertSame(
            RegolePubblicazione::valida($this->dati(['sottotitolo' => '', 'tag' => '']), $file, '2026-11-19'),
            RegolePubblicazione::valida($this->dati(['sottotitolo' => '', 'tag' => '']), $file, '2026-11-19', false, [], $regole),
            'stesse regole con o senza configurazione'
        );
    }

    // ───── etichette, aiuti, colori, tipi ─────

    public function testEtichettaCambiata(): void
    {
        $c = $this->config(
            [['chiave' => 'sottotitolo', 'etichetta' => 'Frase breve', 'aiuto' => 'Una riga sola.', 'obbligatorio' => null, 'visibile' => null, 'ordine' => null]],
            [['chiave' => 'd_url_pubblicazione', 'intestazione' => 'Link finale', 'a_cura_di' => 'Anna Rossi', 'colore' => '#112233', 'tipo' => 'si_no', 'visibile' => null, 'ordine' => null],
             ['chiave' => 'titolo', 'intestazione' => 'Titolo dell\'iniziativa', 'a_cura_di' => 'Nessuno', 'colore' => null, 'tipo' => 'numero', 'visibile' => null, 'ordine' => null]]
        );
        $x = $c->campo('sottotitolo');
        $this->assertSame(['Frase breve', 'Una riga sola.', true, true, true], [$x['etichetta'], $x['aiuto'], $x['obbligatorio'], $x['visibile'], $x['modificato']]);
        $this->assertSame('Frase breve', $c->etichetta('sottotitolo'));
        $this->assertSame('Sottotitolo', ConfigurazioneCampi::CAMPI['sottotitolo']['etichetta'] === 'Sottotitolo' ? 'Sottotitolo' : '', 'il codice resta com\'è');

        $col = $c->colonna('d_url_pubblicazione');
        $this->assertSame(['Link finale', 'Anna Rossi', '#112233', 'si_no', true], [$col['intestazione'], $col['a_cura_di'], $col['colore'], $col['tipo'], $col['modificata']]);
        $this->assertSame('Link finale', $c->etichettaColonna('d_url_pubblicazione'));
        $this->assertSame('Titolo dell\'iniziativa', $c->intestazioniExcel()[array_search('titolo', $c->chiaviExcel(), true)]);
        // le colonne scritte dal docente: a cura di e tipo non si cambiano
        $t = $c->colonna('titolo');
        $this->assertSame(['', 'testo'], [$t['a_cura_di'], $t['tipo']]);
        // un colore non valido si ignora
        $c2 = $this->config([], [['chiave' => 'f_pubblicazione_social', 'intestazione' => null, 'a_cura_di' => null, 'colore' => 'rosso', 'tipo' => null, 'visibile' => null, 'ordine' => null]]);
        $this->assertSame(ColonneMonitoraggio::perChiave('f_pubblicazione_social')['colore'], $c2->colonna('f_pubblicazione_social')['colore']);
    }

    public function testUnaEtichettaVuotaValeQuellaDelCodice(): void
    {
        $c = $this->config([['chiave' => 'tag', 'etichetta' => '   ', 'aiuto' => null, 'obbligatorio' => null, 'visibile' => null, 'ordine' => null]]);
        $this->assertSame('Tag', $c->etichetta('tag'));
    }

    // ───── campo nascosto, facoltativo, regole fisse ─────

    public function testCampoNascosto(): void
    {
        $c = $this->config([['chiave' => 'sottotitolo', 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => null, 'visibile' => 0, 'ordine' => null]]);
        $this->assertFalse($c->visibile('sottotitolo'));
        $this->assertFalse($c->obbligatorio('sottotitolo'), 'un campo nascosto non è mai obbligatorio');
        $this->assertSame(['sottotitolo'], $c->nascosti());
        $this->assertTrue($c->colonnaDaCampoNascosto('sottotitolo'));
        $this->assertFalse($c->colonnaDaCampoNascosto('titolo'));
        $this->assertSame(['titolo', 'descrizione'], array_column($c->campiDelForm(3), 'chiave'), 'il form non lo chiede');
        $this->assertSame(['titolo', 'sottotitolo', 'descrizione'], array_column($c->campiDelForm(3, true), 'chiave'), 'la scheda li vede tutti');
        $this->assertFalse($c->regole()['sottotitolo']['visibile']);
        $this->assertFalse($c->regole()['sottotitolo']['obbligatorio']);

        // il server non lo controlla (e vale vuoto) anche se qualcuno lo manda lo stesso, troppo lungo
        $file = ['locandina' => [$this->allegato()]];
        $troppo = str_repeat('x', 500);
        $this->assertSame([], RegolePubblicazione::valida($this->dati(['sottotitolo' => $troppo]), $file, '2026-11-19', false, [], $c->regole()));
        $this->assertSame('', RegolePubblicazione::normalizza($this->dati(['sottotitolo' => 'Ciao']), $c->nascosti())['sottotitolo']);
        $this->assertArrayHasKey('sottotitolo', RegolePubblicazione::valida($this->dati(['sottotitolo' => $troppo]), $file, '2026-11-19'), 'senza configurazione sì');
    }

    public function testNellExcelLaColonnaDiUnCampoNascostoRestaVuota(): void
    {
        $c = $this->config([['chiave' => 'sottotitolo', 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => null, 'visibile' => 0, 'ordine' => null]]);
        $tab = EsportazioneMonitoraggio::tabella([$this->riga()], false, $c);
        $i = array_search('sottotitolo', $c->chiaviExcel(), true);
        $this->assertSame('', $tab['righe'][0][$i], 'la colonna del campo nascosto è vuota (il valore resta nel database)');
        $this->assertSame('Notte dei ricercatori', $tab['righe'][0][array_search('titolo', $c->chiaviExcel(), true)]);
        $senza = EsportazioneMonitoraggio::tabella([$this->riga()], false, $this->config());
        $this->assertSame('Laboratori aperti', $senza['righe'][0][$i]);
    }

    public function testCampoFacoltativoSiControllaSoloSeCompilato(): void
    {
        $c = $this->config([
            ['chiave' => 'url_docente', 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => 0, 'visibile' => null, 'ordine' => null],
            ['chiave' => 'sottotitolo', 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => 0, 'visibile' => null, 'ordine' => null],
            ['chiave' => 'tag', 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => 0, 'visibile' => null, 'ordine' => null],
            ['chiave' => 'modalita', 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => 0, 'visibile' => null, 'ordine' => null],
        ]);
        $this->assertFalse($c->obbligatorio('url_docente'));
        $this->assertTrue($c->campo('url_docente')['modificato']);
        $file = ['locandina' => [$this->allegato()]];
        $vuoti = $this->dati(['url_docente' => '', 'sottotitolo' => '', 'tag' => '', 'modalita' => '']);
        $this->assertSame([], RegolePubblicazione::valida($vuoti, $file, '2026-11-19', false, [], $c->regole()), 'vuoti: accettati');
        $this->assertSame(['sottotitolo', 'modalita'], array_keys(RegolePubblicazione::valida($vuoti, $file, '2026-11-19')), 'senza configurazione: URL e tag sono già facoltativi, gli altri no');
        // compilati male: si controllano
        $storti = RegolePubblicazione::valida($this->dati(['url_docente' => 'non-un-url', 'sottotitolo' => str_repeat('s', 200), 'modalita' => 'altro']), $file, '2026-11-19', false, [], $c->regole());
        $this->assertSame(['url_docente', 'sottotitolo', 'modalita'], array_keys($storti));
    }

    public function testTitoloEDataNonSiNascondonoNeDiventanoFacoltativi(): void
    {
        $c = $this->config([
            ['chiave' => 'titolo', 'etichetta' => 'Nome dell\'evento', 'aiuto' => null, 'obbligatorio' => 0, 'visibile' => 0, 'ordine' => null],
            ['chiave' => 'data_evento', 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => 0, 'visibile' => 0, 'ordine' => null],
            ['chiave' => 'richiedente', 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => 0, 'visibile' => 0, 'ordine' => null],
            ['chiave' => 'destinatari', 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => 0, 'visibile' => null, 'ordine' => null],
        ]);
        foreach (['titolo', 'data_evento', 'richiedente'] as $k) {
            $this->assertTrue($c->visibile($k), "$k resta visibile");
            $this->assertTrue($c->obbligatorio($k), "$k resta obbligatorio");
        }
        $this->assertSame('Nome dell\'evento', $c->etichetta('titolo'), 'ma il nome si può cambiare');
        $this->assertTrue($c->obbligatorio('destinatari'), 'i destinatari, se il gruppo è visibile, restano «almeno uno»');
        $file = ['locandina' => [$this->allegato()]];
        $e = RegolePubblicazione::valida($this->dati(['titolo' => '', 'data_evento' => '', 'per_cittadinanza' => '']), $file, '2026-11-19', false, [], $c->regole());
        $this->assertSame(['data_evento', 'titolo', 'destinatari'], array_keys($e));
    }

    public function testColonneFisseNonSiNascondono(): void
    {
        $nascondi = array_map(static fn (string $k): array => ['chiave' => $k, 'intestazione' => null, 'a_cura_di' => null, 'colore' => null, 'tipo' => null, 'visibile' => 0, 'ordine' => null], ['data_evento', 'richiedente', 'titolo', 'stato', 'descrizione']);
        $c = $this->config([], $nascondi);
        $chiavi = $c->chiaviExcel();
        foreach (['data_evento', 'richiedente', 'titolo', 'stato'] as $k) {
            $this->assertContains($k, $chiavi, "$k è sempre nel file");
        }
        $this->assertNotContains('descrizione', $chiavi, 'le altre si nascondono');
        $this->assertContains('descrizione', array_column($c->colonne(true), 'chiave'), 'ma la scheda le vede');
    }

    public function testDestinatariENascostiNonSiControllano(): void
    {
        $c = $this->config([['chiave' => 'destinatari', 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => null, 'visibile' => 0, 'ordine' => null]]);
        $file = ['locandina' => [$this->allegato()]];
        $this->assertSame([], RegolePubblicazione::valida($this->dati(['per_cittadinanza' => '']), $file, '2026-11-19', false, [], $c->regole()), 'gruppo nascosto: nessun controllo');
        $n = RegolePubblicazione::normalizza($this->dati(['per_cittadinanza' => '1', 'per_studenti' => '1']), $c->nascosti());
        $this->assertSame([0, 0, 0, 0], [$n['per_cittadinanza'], $n['per_studenti'], $n['per_docenti'], $n['per_pta']], 'le quattro caselle valgono zero');
    }

    // ───── passi ─────

    public function testUnPassoSenzaCampiVisibiliSiSalta(): void
    {
        $nascondi = static fn (string $k): array => ['chiave' => $k, 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => null, 'visibile' => 0, 'ordine' => null];
        $this->assertSame([1, 2, 3, 5, 6, 7], $this->config([$nascondi('destinatari')])->passiVisibili());
        $this->assertSame([1, 2, 3, 5, 6, 7], $this->config([$nascondi('destinatari'), $nascondi('allegati'), $nascondi('immagini')])->passiVisibili(), 'la locandina tiene il passo dei file');
        $this->assertSame([1, 2, 3, 4, 5, 7], $this->config([$nascondi('tag'), $nascondi('data_inizio_pubblicazione'), $nascondi('note')])->passiVisibili());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->config([$nascondi('tag')])->passiVisibili(), 'finché un campo c\'è, il passo c\'è');
        $this->assertSame(count(PassiRichiesta::PASSI), max($this->config()->passiVisibili()), 'il riepilogo è sempre l\'ultimo');
    }

    // ───── ordine ─────

    public function testOrdineDeiCampiDentroIlPasso(): void
    {
        $c = $this->config([
            ['chiave' => 'descrizione', 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => null, 'visibile' => null, 'ordine' => 1],
            ['chiave' => 'titolo', 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => null, 'visibile' => null, 'ordine' => 2],
            ['chiave' => 'sottotitolo', 'etichetta' => null, 'aiuto' => null, 'obbligatorio' => null, 'visibile' => null, 'ordine' => 3],
        ]);
        $this->assertSame(['descrizione', 'titolo', 'sottotitolo'], array_column($c->campiDelForm(3), 'chiave'));
        $this->assertSame(['modalita', 'url_docente', 'altri_link_docente'], array_slice(array_column($c->campiDelForm(2), 'chiave'), 2), 'gli altri passi non cambiano');
        // i passi restano ordinati tra loro
        $passi = array_column($c->campi(), 'passo');
        $ordinati = $passi;
        sort($ordinati);
        $this->assertSame($ordinati, $passi);
    }

    public function testOrdineDelleColonne(): void
    {
        // come li salva la scheda: tutte le colonne con la loro posizione (Stato prima, poi Titolo, poi le altre come prima)
        $ordine = ['stato', 'titolo'];
        foreach (ColonneMonitoraggio::chiavi() as $k) {
            if (!in_array($k, $ordine, true)) {
                $ordine[] = $k;
            }
        }
        $c = $this->config([], array_map(static fn (string $k, int $i): array => ['chiave' => $k, 'intestazione' => null, 'a_cura_di' => null, 'colore' => null, 'tipo' => null, 'visibile' => null, 'ordine' => $i + 1], $ordine, array_keys($ordine)));
        $chiavi = $c->chiaviExcel();
        $this->assertSame(['stato', 'titolo'], array_slice($chiavi, 0, 2));
        $this->assertCount(count(ColonneMonitoraggio::chiavi()), $chiavi, 'nessuna colonna persa');
        $tab = EsportazioneMonitoraggio::tabella([$this->riga()], false, $c);
        $this->assertSame(['Stato', 'Titolo iniziativa'], array_slice($tab['intestazioni'], 0, 2));
        $this->assertSame(['In lavorazione', 'Notte dei ricercatori'], array_slice($tab['righe'][0], 0, 2));
    }

    // ───── colonne degli operatori ─────

    public function testUnaColonnaNascostaNonContaComeDaCompletare(): void
    {
        $nascosta = 'c_altri_referenti';
        $c = $this->config([], [['chiave' => $nascosta, 'intestazione' => null, 'a_cura_di' => null, 'colore' => null, 'tipo' => null, 'visibile' => 0, 'ordine' => null]]);
        $this->assertNotContains($nascosta, $c->colonneOperatori());
        $this->assertCount(count(ColonneMonitoraggio::colonneOperatori()) - 1, $c->colonneOperatori());
        $r = $this->riga();
        foreach ($c->colonneOperatori() as $k) {
            $r[$k] = 'x';
        }
        $this->assertSame(0, Completamento::numeroMancanti($r, $c), 'tutte le colonne visibili sono piene: la nascosta non conta');
        $this->assertSame([$nascosta], Completamento::mancanti($r, $this->config()), 'senza configurazione conta');
        $gruppi = array_merge(...array_map(static fn (array $g): array => array_column($g['colonne'], 'chiave'), Completamento::gruppiPerPersona($c)));
        $this->assertNotContains($nascosta, $gruppi);
    }

    public function testIlTipoDiUnaColonnaCambiataCambiaIControlli(): void
    {
        $c = $this->config([], [['chiave' => 'f_pubblicazione_social', 'intestazione' => 'Social', 'a_cura_di' => null, 'colore' => null, 'tipo' => 'numero', 'visibile' => null, 'ordine' => null]]);
        $this->assertSame([], ServizioPubblicazioni::erroriColonne(['f_pubblicazione_social' => '12'], $c));
        $this->assertSame(['f_pubblicazione_social'], array_keys(ServizioPubblicazioni::erroriColonne(['f_pubblicazione_social' => 'SI'], $c)));
        $this->assertSame([], ServizioPubblicazioni::erroriColonne(['f_pubblicazione_social' => 'SI']), 'senza configurazione è ancora SI / NO');
        // una colonna nascosta non si controlla
        $n = $this->config([], [['chiave' => 'v_n_partecipanti', 'intestazione' => null, 'a_cura_di' => null, 'colore' => null, 'tipo' => null, 'visibile' => 0, 'ordine' => null]]);
        $this->assertSame([], ServizioPubblicazioni::erroriColonne(['v_n_partecipanti' => 'tanti'], $n));
        $this->assertArrayHasKey('v_n_partecipanti', ServizioPubblicazioni::erroriColonne(['v_n_partecipanti' => 'tanti']));
    }
}
