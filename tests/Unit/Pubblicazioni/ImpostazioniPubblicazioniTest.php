<?php

declare(strict_types=1);

namespace Tests\Unit\Pubblicazioni;

use App\Auth\Pubblico;
use App\Auth\Vista\PubblicoVista;
use App\Pubblicazioni\Costanti;
use App\Pubblicazioni\ImpostazioniPubblicazioni;
use App\Pubblicazioni\LimitiCaricamento;
use App\Pubblicazioni\RegolePubblicazione;
use PHPUnit\Framework\TestCase;
use Tests\Doppi\FileDiProva;

/**
 * Impostazioni della Richiesta di pubblicazione (passo AM3): i valori di partenza (come prima, tranne il PTA), la validazione di ogni impostazione,
 * la lettura dal modulo della scheda e i limiti applicati da RegolePubblicazione. Senza database.
 */
final class ImpostazioniPubblicazioniTest extends TestCase
{
    private const OGGI = '2026-11-19';

    protected function tearDown(): void
    {
        FileDiProva::pulisci();
    }

    public function testValoriDiPartenzaComePrimaTranneIlPta(): void
    {
        $i = ImpostazioniPubblicazioni::INIZIALI;
        $this->assertSame([Pubblico::DOCENTI, Pubblico::PTA], $i['chi_puo_inviare'], 'di partenza docenti e, da ora, anche il PTA');
        $this->assertTrue($i['aperta']);
        $this->assertSame('Al momento le richieste di pubblicazione sono chiuse.', $i['messaggio_chiusa']);
        $this->assertSame('Richiesta di pubblicazione su pagina web e canali social istituzionali DiBEST, indirizzata al Direttore.', $i['testo_introduttivo'], 'il testo che la pagina mostrava in alto');
        $this->assertSame('La richiesta è arrivata all\'ufficio comunicazione.', $i['testo_dopo_invio']);
        $this->assertSame([75, 170, 10, 10], [$i['max_sottotitolo'], $i['max_descrizione'], $i['max_allegati'], $i['max_immagini']]);
        $this->assertSame([Costanti::MAX_SOTTOTITOLO, Costanti::MAX_DESCRIZIONE, Costanti::MAX_ALLEGATI, Costanti::MAX_IMMAGINI], [$i['max_sottotitolo'], $i['max_descrizione'], $i['max_allegati'], $i['max_immagini']]);
        $this->assertTrue($i['allegati_obbligatori']);
        $this->assertSame(0, $i['giorni_minimi'], '0 = la data di inizio pubblicazione non nel passato, come prima');
        $this->assertSame([Costanti::EMAIL_PREDEFINITA], $i['indirizzi']);
        $this->assertTrue($i['ricevuta_al_richiedente']);
        // ogni impostazione sta in una sezione della scheda, una volta sola
        $tutte = array_merge(...array_values(ImpostazioniPubblicazioni::SEZIONI));
        $this->assertSame(array_keys($i), $tutte);
        $this->assertSame($tutte, array_keys(ImpostazioniPubblicazioni::ETICHETTE));
    }

    public function testValoriGiustiPassano(): void
    {
        [$v, $e] = ImpostazioniPubblicazioni::valida([
            'chi_puo_inviare' => ['pta', 'docenti', 'studenti'], 'aperta' => false, 'messaggio_chiusa' => '  Chiusa  fino a gennaio. ',
            'testo_introduttivo' => '<p>Per <b>eventi</b> e notizie</p><script>x()</script>', 'testo_dopo_invio' => 'Grazie!',
            'max_sottotitolo' => '50', 'max_descrizione' => '2000', 'max_allegati' => '1', 'max_immagini' => '20', 'allegati_obbligatori' => false, 'giorni_minimi' => '0',
            'indirizzi' => 'a@unical.it; B@unical.it a@unical.it', 'ricevuta_al_richiedente' => true, 'sconosciuta' => 'x',
        ]);
        $this->assertSame([], $e);
        $this->assertSame(['studenti', 'docenti', 'pta'], $v['chi_puo_inviare'], 'nell\'ordine di Pubblico');
        $this->assertFalse($v['aperta']);
        $this->assertSame('Chiusa fino a gennaio.', $v['messaggio_chiusa']);
        $this->assertStringContainsString('<b>eventi</b>', $v['testo_introduttivo']);
        $this->assertStringNotContainsString('script', $v['testo_introduttivo'], 'HTML semplice ripulito');
        $this->assertSame([50, 2000, 1, 20, 0], [$v['max_sottotitolo'], $v['max_descrizione'], $v['max_allegati'], $v['max_immagini'], $v['giorni_minimi']]);
        $this->assertSame(['a@unical.it', 'b@unical.it'], $v['indirizzi']);
        $this->assertArrayNotHasKey('sconosciuta', $v);
    }

    public function testLimitiFuoriMisuraRifiutati(): void
    {
        foreach (ImpostazioniPubblicazioni::LIMITI as $k => [$min, $max]) {
            foreach ([(string) ($min - 1), (string) ($max + 1), '', 'dieci', '3.5', '-0'] as $sbagliato) {
                $this->assertArrayHasKey($k, ImpostazioniPubblicazioni::valida([$k => $sbagliato])[1], "$k = «{$sbagliato}»");
            }
            foreach ([$min, $max] as $giusto) {
                $this->assertSame([], ImpostazioniPubblicazioni::valida([$k => (string) $giusto])[1], "$k = $giusto");
            }
        }
        $this->assertSame([1, 100], ImpostazioniPubblicazioni::LIMITI['max_sottotitolo'], 'il sottotitolo sta in una colonna da 100 caratteri');
        $this->assertStringContainsString('da 1 a 100', ImpostazioniPubblicazioni::valida(['max_sottotitolo' => '101'])[1]['max_sottotitolo']);
    }

    public function testServeAlmenoUnProfiloEMaiAnonimi(): void
    {
        $this->assertArrayHasKey('chi_puo_inviare', ImpostazioniPubblicazioni::valida(['chi_puo_inviare' => []])[1]);
        $this->assertArrayHasKey('chi_puo_inviare', ImpostazioniPubblicazioni::valida(['chi_puo_inviare' => ['anonimi']])[1], '«anonimi» non vale: serve l\'accesso');
        $this->assertArrayHasKey('chi_puo_inviare', ImpostazioniPubblicazioni::valida(['chi_puo_inviare' => ['inventato']])[1]);
        [$v, $e] = ImpostazioniPubblicazioni::valida(['chi_puo_inviare' => ['anonimi', 'scuole']]);
        $this->assertSame([], $e);
        $this->assertSame(['scuole'], $v['chi_puo_inviare']);
    }

    public function testIndirizziETestiComeOggi(): void
    {
        $this->assertStringContainsString('non-valido', ImpostazioniPubblicazioni::valida(['indirizzi' => 'a@unical.it, non-valido'])[1]['indirizzi']);
        $this->assertArrayHasKey('indirizzi', ImpostazioniPubblicazioni::valida(['indirizzi' => '  '])[1]);
        $this->assertArrayHasKey('messaggio_chiusa', ImpostazioniPubblicazioni::valida(['messaggio_chiusa' => '   '])[1]);
        $this->assertArrayHasKey('messaggio_chiusa', ImpostazioniPubblicazioni::valida(['messaggio_chiusa' => str_repeat('a', 501)])[1]);
        $this->assertArrayHasKey('testo_dopo_invio', ImpostazioniPubblicazioni::valida(['testo_dopo_invio' => '<p> </p>'])[1], 'il testo dopo l\'invio non resta vuoto');
        $this->assertSame([], ImpostazioniPubblicazioni::valida(['testo_introduttivo' => ''])[1], 'il testo in cima si può togliere');
        $this->assertArrayHasKey('testo_introduttivo', ImpostazioniPubblicazioni::valida(['testo_introduttivo' => str_repeat('<b>a</b>', 800)])[1]);
    }

    public function testDalModuloDellaScheda(): void
    {
        $post = [ImpostazioniPubblicazioni::CAMPO_CHI . '_profili' => ['docenti'], 'messaggio_chiusa' => 'Chiusa', 'max_allegati' => '3', 'indirizzi' => 'x@unical.it'];
        $tutte = ImpostazioniPubblicazioni::daPost($post, array_keys(ImpostazioniPubblicazioni::SEZIONI));
        $this->assertSame(array_keys(ImpostazioniPubblicazioni::INIZIALI), array_keys($tutte));
        $this->assertSame(['docenti'], $tutte['chi_puo_inviare']);
        $this->assertFalse($tutte['aperta'], 'casella non spuntata = no');
        $this->assertFalse($tutte['ricevuta_al_richiedente']);
        $this->assertSame('3', $tutte['max_allegati']);
        $this->assertSame(['indirizzi', 'ricevuta_al_richiedente'], array_keys(ImpostazioniPubblicazioni::daPost($post, ['email'])), 'l\'abilitato parziale salva solo la parte Email');
    }

    public function testParoleDeiProfiliEDelRegistro(): void
    {
        $this->assertSame('Docenti, Personale tecnico amministrativo', ImpostazioniPubblicazioni::inParole(ImpostazioniPubblicazioni::INIZIALI['chi_puo_inviare']));
        $c = ImpostazioniPubblicazioni::cambiInParole([
            'chi_puo_inviare' => ['da' => ['docenti'], 'a' => ['docenti', 'pta']],
            'aperta' => ['da' => true, 'a' => false],
            'max_sottotitolo' => ['da' => 75, 'a' => 50],
        ]);
        $this->assertSame([
            'Chi può inviare' => 'Docenti → Docenti, Personale tecnico amministrativo',
            'Richieste aperte' => 'sì → no',
            'Caratteri del sottotitolo' => '75 → 50',
        ], $c);
    }

    public function testLeRegoleUsanoILimitiDelleImpostazioni(): void
    {
        $dati = ['data_evento' => '2026-12-05', 'url_docente' => 'https://www.unical.it/e', 'titolo' => 'T', 'sottotitolo' => str_repeat('s', 51), 'descrizione' => 'D',
            'modalita' => 'online', 'per_studenti' => '1', 'note' => 'nessuna', 'tag' => 'x', 'data_inizio_pubblicazione' => '2026-11-20'];
        $file = ['allegati' => [FileDiProva::pdf()]];
        $this->assertArrayNotHasKey('sottotitolo', RegolePubblicazione::valida($dati, $file, self::OGGI), 'senza limiti: quelli di partenza (75)');
        $e = RegolePubblicazione::valida($dati, $file, self::OGGI, false, ['max_sottotitolo' => 50]);
        $this->assertSame('Il sottotitolo ha 51 caratteri: al massimo 50, spazi inclusi.', $e['sottotitolo']);
        $this->assertArrayHasKey('descrizione', RegolePubblicazione::valida(['descrizione' => 'abc'] + $dati, $file, self::OGGI, false, ['max_descrizione' => 2]));

        // allegati: obbligatori di partenza, facoltativi se l'impostazione è spenta; il numero massimo dalle impostazioni
        $this->assertArrayHasKey('allegati', RegolePubblicazione::valida($dati, [], self::OGGI));
        $this->assertArrayNotHasKey('allegati', RegolePubblicazione::valida($dati, [], self::OGGI, false, ['allegati_obbligatori' => false]));
        $due = ['allegati' => [FileDiProva::pdf('a.pdf'), FileDiProva::pdf('b.pdf')], 'immagini' => [FileDiProva::png('a.png'), FileDiProva::png('b.png')]];
        $e = RegolePubblicazione::valida($dati, $due, self::OGGI, false, ['max_allegati' => 1, 'max_immagini' => 1]);
        $this->assertSame('Puoi caricare un solo allegato.', $e['allegati']);
        $this->assertSame('Puoi caricare una sola immagine.', $e['immagini']);

        // giorni di anticipo: 0 = da oggi; 3 = dal 22/11/2026 in poi (le righe a mano non hanno questo controllo)
        $this->assertArrayNotHasKey('data_inizio_pubblicazione', RegolePubblicazione::valida(['data_inizio_pubblicazione' => self::OGGI] + $dati, $file, self::OGGI));
        $e = RegolePubblicazione::valida(['data_inizio_pubblicazione' => '2026-11-21'] + $dati, $file, self::OGGI, false, ['giorni_minimi' => 3]);
        $this->assertSame('La data di inizio pubblicazione deve essere almeno 3 giorni dopo oggi: dal 22/11/2026 in poi.', $e['data_inizio_pubblicazione']);
        $this->assertArrayNotHasKey('data_inizio_pubblicazione', RegolePubblicazione::valida(['data_inizio_pubblicazione' => '2026-11-22'] + $dati, $file, self::OGGI, false, ['giorni_minimi' => 3]));
        $this->assertArrayNotHasKey('data_inizio_pubblicazione', RegolePubblicazione::valida(['data_inizio_pubblicazione' => '2026-11-21', 'richiedente' => 'X Y'] + $dati, [], self::OGGI, true, ['giorni_minimi' => 3]));
        $this->assertSame('2026-11-19', RegolePubblicazione::primaDataDiInizio(self::OGGI, 0));
        $this->assertSame('2026-12-01', RegolePubblicazione::primaDataDiInizio('2026-11-30', 1));
        $this->assertSame('La data di inizio pubblicazione deve essere almeno 1 giorno dopo oggi: dal 20/11/2026 in poi.', RegolePubblicazione::messaggioInizio(self::OGGI, 1));
    }

    public function testLimitiDelServerConIFileDelleImpostazioni(): void
    {
        $this->assertSame([], (new LimitiCaricamento('40M', '40M', 20))->problemi(), 'di partenza 10 + 10 file');
        $this->assertCount(1, (new LimitiCaricamento('40M', '40M', 20, 25))->problemi(), 'con 25 file per richiesta, 20 per invio non bastano');
        $this->assertStringContainsString('meno dei 25 previsti', (new LimitiCaricamento('40M', '40M', 20, 25))->problemi()[0]);
        $this->assertSame([], (new LimitiCaricamento('40M', '40M', 5, 4))->problemi());
    }

    public function testSceltaDeiProfiliSenzaAnonimi(): void
    {
        $tutte = PubblicoVista::scelta('x', ['profili' => ['docenti']]);
        $this->assertStringContainsString('A chi si rivolge', $tutte, 'senza parametri come sempre');
        $this->assertStringContainsString('value="anonimi"', $tutte);
        $solo = PubblicoVista::scelta('pb_chi', ['profili' => ['docenti']], array_values(array_diff(Pubblico::PROFILI, [Pubblico::ANONIMI])), 'Chi può inviare la richiesta');
        $this->assertStringNotContainsString('value="anonimi"', $solo);
        $this->assertStringContainsString('Chi può inviare la richiesta', $solo);
        $this->assertStringContainsString('name="pb_chi_profili[]" value="docenti" checked', $solo);
        $this->assertStringContainsString(htmlspecialchars(Pubblico::AVVISO_SCUOLE, ENT_QUOTES), $solo, 'accanto a «Scuole» l\'avviso di Pubblico');
    }
}
