<?php

declare(strict_types=1);

namespace Tests\Integration\Pubblicazioni;

use App\Core\Container;
use App\Core\Database;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Core\Schema\Definizioni;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Storage\Upload;
use App\Pubblicazioni\AllegatiPubblicazioni;
use App\Pubblicazioni\ColonneMonitoraggio;
use App\Pubblicazioni\EsportazioneMonitoraggio;
use App\Pubblicazioni\ImpostazioniPubblicazioni;
use App\Pubblicazioni\PubblicazioniRepository;
use App\Pubblicazioni\ServizioPubblicazioni;
use Tests\Doppi\ClientFinto;
use Tests\Doppi\FileDiProva;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/** Richieste di pubblicazione: invio del docente, «Invia al file Excel» / «Togli», righe a mano, colonne degli operatori, registro, eliminazione. */
final class ServizioPubblicazioniTest extends DatabaseDiProva
{
    private const ADMIN = ['id' => 1, 'nome' => 'Ada', 'cognome' => 'Admin', 'ruolo_id' => 1, 'ruoli_secondari' => ''];
    private const STUDENTE = ['id' => 2, 'nome' => 'Luca', 'cognome' => 'Studente', 'ruolo_id' => 3, 'ruoli_secondari' => '', 'matricola_studente' => '123'];
    private const DOCENTE = ['id' => 3, 'nome' => 'Maria', 'cognome' => 'Rossi', 'email' => 'maria.rossi@unical.it', 'ruolo_id' => 5, 'ruoli_secondari' => '6'];
    private const OPERATORE = ['id' => 4, 'nome' => 'Floriana', 'cognome' => 'Operatrice', 'ruolo_id' => 4, 'ruoli_secondari' => ''];

    private Container $c;
    private string $cartella;

    protected function tabelle(): array
    {
        return [
            'pubblicazioni' => Definizioni::tabelle()['pubblicazioni'],
            'pubblicazioni_allegati' => Definizioni::tabelle()['pubblicazioni_allegati'],
            'ruoli' => 'CREATE TABLE ruoli (id INT PRIMARY KEY, nome VARCHAR(100))',
            'log_attivita' => 'CREATE TABLE log_attivita (id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT DEFAULT NULL, azione VARCHAR(255) NOT NULL, dettagli_json TEXT DEFAULT NULL, indirizzo_ip VARCHAR(45) DEFAULT NULL, data_ora DATETIME DEFAULT CURRENT_TIMESTAMP)',
            'impostazioni_sistema' => "CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, pubblicazioni_email_notifica VARCHAR(500) NOT NULL DEFAULT 'comunicazione.dibest@unical.it')",
        ];
    }

    protected function setUp(): void
    {
        // la tabella dei file punta a pubblicazioni (chiave esterna): va tolta per prima
        if (self::$conn !== null) {
            self::$conn->query('DROP TABLE IF EXISTS `pubblicazioni_allegati`');
        }
        parent::setUp();
        $this->db->esegui("INSERT INTO ruoli (id, nome) VALUES (6, 'Docenti')");
        $this->db->esegui('INSERT INTO impostazioni_sistema (id) VALUES (1)');
        $this->cartella = 'cache/prove_pubblicazioni_' . bin2hex(random_bytes(4)) . '/';
        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Orologio::class, new OrologioFisso('2026-11-19 10:00:00'));
        $c->istanza(IndirizzoClient::class, new ClientFinto());
        $c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
        $c->istanza(Mailer::class, new MailerFinto()); // le email dell'invio (R2) non escono: le prova NotificheEScaricoTest
        $c->set(AllegatiPubblicazioni::class, fn (Container $c): AllegatiPubblicazioni => new AllegatiPubblicazioni($c->get(Upload::class), $c->get(Sito::class), $this->cartella, false));
        $this->c = $c;
    }

    protected function tearDown(): void
    {
        FileDiProva::pulisci();
        $dir = dirname(__DIR__, 3) . '/' . $this->cartella;
        foreach (glob($dir . '{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    private function servizio(): ServizioPubblicazioni
    {
        return $this->c->get(ServizioPubblicazioni::class);
    }

    private function repo(): PubblicazioniRepository
    {
        return $this->c->get(PubblicazioniRepository::class);
    }

    /** @param array<string, mixed> $cambi @return array<string, mixed> */
    private function dati(array $cambi = []): array
    {
        return array_merge([
            'data_evento' => '2026-12-05', 'url_docente' => 'https://www.unical.it/eventi/notte', 'altri_link_docente' => "https://a.unical.it/1\nhttps://b.unical.it/2",
            'titolo' => 'Notte dei ricercatori', 'sottotitolo' => 'Laboratori aperti', 'descrizione' => '<p>Una serata di <b>laboratori</b>.</p>',
            'modalita' => 'presenza', 'per_cittadinanza' => '1', 'per_studenti' => '1', 'note' => 'nessuna', 'tag' => 'DIBEST, notizie',
            'data_inizio_pubblicazione' => '2026-11-20',
        ], $cambi);
    }

    /** Una richiesta inviata dal docente (con un PDF e un'immagine). */
    private function inviata(array $cambi = []): int
    {
        $esito = $this->servizio()->invia(self::DOCENTE, $this->dati($cambi), ['allegati' => [FileDiProva::pdf()], 'immagini' => [FileDiProva::png()]]);
        $this->assertSame([], $esito['errori']);

        return $esito['id'];
    }

    /** @return list<array<string, mixed>> */
    private function registro(): array
    {
        return $this->db->righe('SELECT * FROM log_attivita ORDER BY id');
    }

    public function testChiNonEDocenteNonPuoInviare(): void
    {
        $esito = $this->servizio()->invia(self::STUDENTE, $this->dati(), ['allegati' => [FileDiProva::pdf()]]);
        $this->assertSame(0, $esito['id']);
        $this->assertArrayHasKey('_', $esito['errori']);
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM pubblicazioni'));
        $this->assertTrue($this->servizio()->puoInviare(self::DOCENTE));
        $this->assertTrue($this->servizio()->puoInviare(self::ADMIN), 'gli amministratori sono sempre ammessi');
        $this->assertFalse($this->servizio()->puoInviare(self::OPERATORE), 'il personale non docente no');
        $this->assertFalse($this->servizio()->puoInviare(null));
    }

    public function testLaRichiestaSiSalvaConIFileMaNonEntraNelMonitoraggio(): void
    {
        $id = $this->inviata();
        $p = $this->repo()->perId($id);
        $this->assertSame(['ricevuta', 0, 'Rossi Maria', 'form', 3], [$p['stato'], (int) $p['nel_monitoraggio'], $p['richiedente'], $p['origine'], (int) $p['utente_id']]);
        $this->assertSame('2026-11-19 10:00:00', $p['inviata_il']);
        $this->assertNull($p['monitoraggio_dal']);
        $this->assertSame([1, 1, 0, 0], [(int) $p['per_cittadinanza'], (int) $p['per_studenti'], (int) $p['per_docenti'], (int) $p['per_pta']]);
        foreach (ColonneMonitoraggio::colonneOperatori() as $k) {
            $this->assertNull($p[$k], "la colonna $k deve restare vuota");
        }
        $file = $this->repo()->allegati($id);
        $this->assertSame(['allegato', 'immagine'], array_column($file, 'tipo'));
        $this->assertSame(['locandina.pdf', 'foto.png'], array_column($file, 'nome_originale'));
        foreach ($file as $f) {
            $this->assertStringStartsWith($this->cartella, $f['percorso']);
            $this->assertNotNull($this->c->get(AllegatiPubblicazioni::class)->percorsoAssoluto($f['percorso']), 'il file è nella cartella');
        }
        $this->assertFileExists(dirname(__DIR__, 3) . '/' . $this->cartella . '.htaccess', 'cartella bloccata al web');
        // non è nell'export
        $this->assertSame([], EsportazioneMonitoraggio::tabella($this->repo()->elenco())['righe']);
        $this->assertCount(1, $this->repo()->elenco(['monitoraggio' => 'no']));
        $this->assertSame([], $this->repo()->elenco(['monitoraggio' => 'si']));
        $this->assertCount(1, $this->repo()->delUtente(3));
    }

    public function testConErroriNonSiSalvaNulla(): void
    {
        $esito = $this->servizio()->invia(self::DOCENTE, $this->dati(['sottotitolo' => str_repeat('x', 76)]), ['allegati' => [FileDiProva::pdf()]]);
        $this->assertSame(0, $esito['id']);
        $this->assertArrayHasKey('sottotitolo', $esito['errori']);
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM pubblicazioni'));
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM pubblicazioni_allegati'));
    }

    public function testInviaAlFileExcelPrecompilaDESi(): void
    {
        $id = $this->inviata();
        $esito = $this->servizio()->inviaAlMonitoraggio($id, self::OPERATORE);
        $this->assertTrue($esito->riuscito, $esito->messaggio);
        $p = $this->repo()->perId($id);
        $this->assertSame(1, (int) $p['nel_monitoraggio']);
        $this->assertSame(['2026-11-19 10:00:00', 4], [$p['monitoraggio_dal'], (int) $p['monitoraggio_da']]);
        $this->assertSame('https://www.unical.it/eventi/notte', $p['d_url_pubblicazione']);
        $this->assertSame("https://a.unical.it/1\nhttps://b.unical.it/2", $p['e_url_altre_pagine']);
        $this->assertSame(['SI', 'SI', null, null], [$p['l_dest_cittadinanza'], $p['p_dest_studenti'], $p['q_dest_docenti'], $p['r_dest_pta']]);
        $this->assertNull($p['c_altri_referenti']);
        $this->assertSame('ricevuta', $p['stato'], 'lo stato non cambia');
        // ora è nell'export, e una seconda volta non si invia
        $this->assertCount(1, EsportazioneMonitoraggio::tabella($this->repo()->elenco())['righe']);
        $this->assertFalse($this->servizio()->inviaAlMonitoraggio($id, self::OPERATORE)->riuscito);
        $r = $this->registro();
        $this->assertCount(1, $r);
        $this->assertSame(4, (int) $r[0]['utente_id']);
        $this->assertStringContainsString('inviata al file Excel', $r[0]['azione']);
        $this->assertSame('10.0.0.1', $r[0]['indirizzo_ip']);
    }

    public function testUnaRichiestaRespintaNonSiInvia(): void
    {
        $id = $this->inviata();
        $this->assertTrue($this->servizio()->aggiornaOperatori($id, ['stato' => 'respinta'], self::OPERATORE)->riuscito);
        $esito = $this->servizio()->inviaAlMonitoraggio($id, self::OPERATORE);
        $this->assertFalse($esito->riuscito);
        $this->assertStringContainsString('respinta', $esito->messaggio);
        $this->assertSame(0, (int) $this->repo()->perId($id)['nel_monitoraggio']);
    }

    public function testToltaERimessaNonSovrascriveQuantoScrittoDagliOperatori(): void
    {
        $id = $this->inviata();
        $this->servizio()->inviaAlMonitoraggio($id, self::OPERATORE);
        $this->assertTrue($this->servizio()->aggiornaOperatori($id, [
            'd_url_pubblicazione' => 'https://dibest.unical.it/notizie/notte', 'p_dest_studenti' => 'SI - CdL Biologia', 'l_dest_cittadinanza' => '', 'c_altri_referenti' => 'Prof. Bianchi',
        ], self::OPERATORE)->riuscito);

        $esito = $this->servizio()->togliDalMonitoraggio($id, self::OPERATORE);
        $this->assertTrue($esito->riuscito);
        $p = $this->repo()->perId($id);
        $this->assertSame([0, null, null], [(int) $p['nel_monitoraggio'], $p['monitoraggio_dal'], $p['monitoraggio_da']]);
        $this->assertSame('Prof. Bianchi', $p['c_altri_referenti'], 'le colonne restano salvate');
        $this->assertSame([], EsportazioneMonitoraggio::tabella($this->repo()->elenco())['righe']);
        $this->assertFalse($this->servizio()->togliDalMonitoraggio($id, self::OPERATORE)->riuscito, 'già fuori');

        $this->assertTrue($this->servizio()->inviaAlMonitoraggio($id, self::OPERATORE)->riuscito);
        $p = $this->repo()->perId($id);
        $this->assertSame('https://dibest.unical.it/notizie/notte', $p['d_url_pubblicazione'], 'D scritta dagli operatori resta');
        $this->assertSame('SI - CdL Biologia', $p['p_dest_studenti']);
        $this->assertSame('Prof. Bianchi', $p['c_altri_referenti']);
        $this->assertSame('SI', $p['l_dest_cittadinanza'], 'una colonna svuotata torna precompilata');
        $azioni = array_column($this->registro(), 'azione');
        $this->assertCount(4, $azioni, 'inviata, modifica, tolta, rimessa (il secondo «togli» rifiutato non si registra)');
        $this->assertStringContainsString('tolta dal file Excel', $azioni[2]);
    }

    public function testRigaAManoConITreCampiEntraSubitoNelMonitoraggio(): void
    {
        $senzaRichiedente = $this->servizio()->creaManuale(['data_evento' => '2026-10-01', 'titolo' => 'X'], self::OPERATORE);
        $this->assertSame(['richiedente'], array_keys($senzaRichiedente['errori']));

        $esito = $this->servizio()->creaManuale(['data_evento' => '2026-10-01', 'richiedente' => '  Verdi   Paolo ', 'titolo' => 'Seminario a mano', 'j_tipo_iniziativa' => 'Seminario'], self::OPERATORE);
        $this->assertSame([], $esito['errori']);
        $p = $this->repo()->perId($esito['id']);
        $this->assertSame(['manuale', null, 'Verdi Paolo', 1, 'ricevuta'], [$p['origine'], $p['utente_id'], $p['richiedente'], (int) $p['nel_monitoraggio'], $p['stato']]);
        $this->assertSame(['2026-11-19 10:00:00', 4], [$p['monitoraggio_dal'], (int) $p['monitoraggio_da']]);
        $this->assertSame(['2026-10-01', null, ''], [$p['data_evento'], $p['data_inizio_pubblicazione'], $p['modalita']]);
        $this->assertSame([null, null, 'Seminario'], [$p['d_url_pubblicazione'], $p['l_dest_cittadinanza'], $p['j_tipo_iniziativa']]);
        $this->assertSame([], $this->repo()->allegati($esito['id']));
        $t = EsportazioneMonitoraggio::tabella($this->repo()->elenco());
        $this->assertSame('Seminario a mano', $t['righe'][0][6]);
        $this->assertSame('Ricevuta', end($t['righe'][0]));
        $this->assertStringContainsString('a mano', $this->registro()[0]['azione']);
    }

    public function testRigaAManoCompletaPrecompilaEAccettaUnaDataDiInizioPassata(): void
    {
        $dati = $this->dati(['richiedente' => 'Verdi Paolo', 'per_studenti' => '', 'data_inizio_pubblicazione' => '2026-10-01']);
        $this->assertArrayHasKey('data_inizio_pubblicazione', $this->servizio()->invia(self::DOCENTE, $dati, ['allegati' => [FileDiProva::pdf()]])['errori'], 'nel form no');
        $esito = $this->servizio()->creaManuale($dati, self::OPERATORE);
        $this->assertSame([], $esito['errori']);
        $p = $this->repo()->perId($esito['id']);
        $this->assertSame('2026-10-01', $p['data_inizio_pubblicazione']);
        $this->assertSame('https://www.unical.it/eventi/notte', $p['d_url_pubblicazione']);
        $this->assertSame(['SI', null], [$p['l_dest_cittadinanza'], $p['p_dest_studenti']]);
        // un allegato sbagliato o troppi allegati si rifiutano anche qui
        $this->assertArrayHasKey('allegati', $this->servizio()->creaManuale($dati, self::OPERATORE, ['allegati' => [FileDiProva::testo('x.pdf')]])['errori']);
        $undici = array_map(static fn (int $i): array => FileDiProva::pdf("a$i.pdf"), range(1, 11));
        $this->assertSame('Puoi caricare al massimo 10 allegati.', $this->servizio()->creaManuale($dati, self::OPERATORE, ['allegati' => $undici])['errori']['allegati'] ?? '');
    }

    public function testUndiciAllegatiRifiutatiNelForm(): void
    {
        $undici = array_map(static fn (int $i): array => FileDiProva::pdf("a$i.pdf"), range(1, 11));
        $esito = $this->servizio()->invia(self::DOCENTE, $this->dati(), ['allegati' => $undici]);
        $this->assertSame(0, $esito['id']);
        $this->assertSame('Puoi caricare al massimo 10 allegati.', $esito['errori']['allegati'] ?? '');
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM pubblicazioni'));
    }

    public function testAggiornaOperatoriNonToccaICampiDelDocenteEScriveIlRegistro(): void
    {
        $id = $this->inviata();
        $this->servizio()->inviaAlMonitoraggio($id, self::OPERATORE);
        $prima = $this->repo()->perId($id);
        $esito = $this->servizio()->aggiornaOperatori($id, [
            'titolo' => 'Titolo cambiato', 'url_docente' => 'https://altro.it', 'per_pta' => '1', 'richiedente' => 'Altro', 'nel_monitoraggio' => '0',
            'c_altri_referenti' => ' Prof. Bianchi ', 'f_pubblicazione_social' => 'si', 'v_n_partecipanti' => '120', 'stato' => 'lavorazione',
        ], self::OPERATORE);
        $this->assertTrue($esito->riuscito, $esito->messaggio);
        $p = $this->repo()->perId($id);
        foreach (['titolo', 'url_docente', 'per_pta', 'richiedente', 'nel_monitoraggio', 'descrizione', 'd_url_pubblicazione'] as $k) {
            $this->assertSame($prima[$k], $p[$k], "$k non deve cambiare");
        }
        $this->assertSame(['Prof. Bianchi', 'SI', '120', 'lavorazione'], [$p['c_altri_referenti'], $p['f_pubblicazione_social'], $p['v_n_partecipanti'], $p['stato']]);
        $this->assertSame(['2026-11-19 10:00:00', 4], [$p['modificata_il'], (int) $p['modificata_da']]);
        $r = $this->registro();
        $ultima = end($r);
        $this->assertStringContainsString('modifica', $ultima['azione']);
        $cambi = json_decode((string) $ultima['dettagli_json'], true)['cambi'];
        $this->assertSame(['da' => null, 'a' => 'Prof. Bianchi'], $cambi['c_altri_referenti']);
        $this->assertSame(['da' => 'ricevuta', 'a' => 'lavorazione'], $cambi['stato']);
        $this->assertSame(['c_altri_referenti', 'f_pubblicazione_social', 'v_n_partecipanti', 'stato'], array_keys($cambi));
        // nulla di nuovo: nessuna riga nel registro
        $n = count($r);
        $this->assertTrue($this->servizio()->aggiornaOperatori($id, ['c_altri_referenti' => 'Prof. Bianchi'], self::OPERATORE)->riuscito);
        $this->assertCount($n, $this->registro());
        // SI/NO e numero controllati
        $this->assertFalse($this->servizio()->aggiornaOperatori($id, ['o_piattaforma_pe' => 'forse'], self::OPERATORE)->riuscito);
        $this->assertFalse($this->servizio()->aggiornaOperatori($id, ['v_n_partecipanti' => 'circa 100'], self::OPERATORE)->riuscito);
        $this->assertFalse($this->servizio()->aggiornaOperatori($id, ['stato' => 'archiviata'], self::OPERATORE)->riuscito);
    }

    public function testFuoriDalMonitoraggioSiCambiaSoloLoStato(): void
    {
        $id = $this->inviata();
        $this->assertTrue($this->servizio()->aggiornaOperatori($id, ['c_altri_referenti' => 'Prof. Bianchi', 'stato' => 'lavorazione'], self::OPERATORE)->riuscito);
        $p = $this->repo()->perId($id);
        $this->assertNull($p['c_altri_referenti']);
        $this->assertSame('lavorazione', $p['stato']);
    }

    public function testRespingereUnaRigaNelMonitoraggioLaToglie(): void
    {
        $id = $this->inviata();
        $this->servizio()->inviaAlMonitoraggio($id, self::OPERATORE);
        $this->servizio()->aggiornaOperatori($id, ['c_altri_referenti' => 'Prof. Bianchi'], self::OPERATORE);
        $esito = $this->servizio()->aggiornaOperatori($id, ['stato' => 'respinta'], self::OPERATORE);
        $this->assertTrue($esito->riuscito);
        $this->assertStringContainsString('tolta dal file Excel', $esito->messaggio);
        $p = $this->repo()->perId($id);
        $this->assertSame(['respinta', 0, 'Prof. Bianchi'], [$p['stato'], (int) $p['nel_monitoraggio'], $p['c_altri_referenti']]);
    }

    public function testEliminaCancellaIFile(): void
    {
        $id = $this->inviata();
        $allegati = $this->c->get(AllegatiPubblicazioni::class);
        $percorsi = array_column($this->repo()->allegati($id), 'percorso');
        $this->assertTrue($this->servizio()->elimina($id, self::OPERATORE)->riuscito);
        $this->assertNull($this->repo()->perId($id));
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM pubblicazioni_allegati'), 'righe dei file cancellate a cascata');
        foreach ($percorsi as $f) {
            $this->assertFileDoesNotExist(dirname(__DIR__, 3) . '/' . $f);
            $this->assertNull($allegati->percorsoAssoluto($f));
        }
        $this->assertFalse($this->servizio()->elimina($id, self::OPERATORE)->riuscito);
        $this->assertStringContainsString('eliminata', array_column($this->registro(), 'azione')[0]);
    }

    public function testFiltriDellElenco(): void
    {
        $a = $this->inviata(['titolo' => 'Seminario di geologia', 'data_evento' => '2026-12-01']);
        $b = $this->inviata(['titolo' => 'Notte dei ricercatori', 'data_evento' => '2027-01-15']);
        $this->servizio()->inviaAlMonitoraggio($b, self::OPERATORE);
        $this->servizio()->aggiornaOperatori($a, ['stato' => 'lavorazione'], self::OPERATORE);
        $ids = static fn (array $righe): array => array_map('intval', array_column($righe, 'id'));
        $this->assertSame([$b, $a], $ids($this->repo()->elenco()), 'dalla data evento più recente');
        $this->assertSame([$a], $ids($this->repo()->elenco(['stato' => 'lavorazione'])));
        $this->assertSame([$a], $ids($this->repo()->elenco(['testo' => 'geolog'])));
        $this->assertSame([$b], $ids($this->repo()->elenco(['dal' => '2027-01-01'])));
        $this->assertSame([$a], $ids($this->repo()->elenco(['al' => '2026-12-31'])));
        $this->assertSame([$b], $ids($this->repo()->elenco(['monitoraggio' => 'si'])));
        $this->assertSame([$a], $ids($this->repo()->elenco(['monitoraggio' => 'no'])));
        $this->assertSame([], $this->repo()->elenco(['testo' => '%']), 'il % si cerca come carattere');
    }

    public function testIndirizzoDiNotifica(): void
    {
        $this->db->esegui('ALTER TABLE impostazioni_sistema ' . Definizioni::colonne()['impostazioni_sistema']['pubblicazioni_email_notifica']);
        $this->db->esegui('INSERT INTO impostazioni_sistema (id) VALUES (1)');
        $imp = $this->c->get(ImpostazioniPubblicazioni::class);
        $this->assertSame(['comunicazione.dibest@unical.it'], $imp->indirizzi());
        $this->assertStringContainsString('non-valido', (string) $imp->salva('a@unical.it, non-valido'));
        $this->assertNotNull($imp->salva('  '));
        $this->assertNull($imp->salva('A@unical.it; b@unical.it a@unical.it'));
        $this->assertSame(['a@unical.it', 'b@unical.it'], $imp->indirizzi());
    }
}
