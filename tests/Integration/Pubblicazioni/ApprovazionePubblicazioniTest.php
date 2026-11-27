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
use App\Pubblicazioni\ApprovazionePubblicazioni;
use App\Pubblicazioni\ImpostazioniPubblicazioni;
use App\Pubblicazioni\PersonePubblicazioni;
use App\Pubblicazioni\PubblicazioniRepository;
use App\Pubblicazioni\ServizioPubblicazioni;
use Tests\Doppi\ClientFinto;
use Tests\Doppi\FileDiProva;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/**
 * P2, con MariaDB: due approvatori decidono la stessa richiesta (vince il primo, il secondo ha l'errore e nel database resta il primo), la richiesta nasce «da_approvare»
 * solo con l'approvazione accesa e un approvatore con account, e finché è in attesa non va nel file Excel.
 */
final class ApprovazionePubblicazioniTest extends DatabaseDiProva
{
    private const DOCENTE = ['id' => 3, 'nome' => 'Maria', 'cognome' => 'Rossi', 'email' => 'maria.rossi@unical.it', 'ruolo_id' => 5, 'ruoli_secondari' => '6', 'persona_id' => 'P-DOC'];
    private const DIRETTORE = ['id' => 10, 'nome' => 'Dario', 'cognome' => 'Direttore', 'ruolo_id' => 5, 'ruoli_secondari' => '6', 'persona_id' => 'P-DIR'];
    private const FLORIANA = ['id' => 11, 'nome' => 'Floriana', 'cognome' => 'Mantovano', 'ruolo_id' => 4, 'ruoli_secondari' => '', 'persona_id' => 'P-FLO'];
    private const OPERATORE = ['id' => 4, 'nome' => 'Ugo', 'cognome' => 'Operatore', 'ruolo_id' => 4, 'ruoli_secondari' => ''];

    private Container $c;
    private string $cartella;

    protected function tabelle(): array
    {
        return [
            'pubblicazioni' => Definizioni::tabelle()['pubblicazioni'],
            'pubblicazioni_allegati' => Definizioni::tabelle()['pubblicazioni_allegati'],
            'pubblicazioni_persone' => Definizioni::tabelle()['pubblicazioni_persone'],
            'personale_ateneo' => Definizioni::tabelle()['personale_ateneo'],
            'utenti' => 'CREATE TABLE utenti (id INT PRIMARY KEY, nome VARCHAR(100), cognome VARCHAR(100), persona_id VARCHAR(80) DEFAULT NULL, ruolo_id INT DEFAULT 5, ruoli_secondari VARCHAR(255) DEFAULT \'\')',
            'ruoli' => 'CREATE TABLE ruoli (id INT PRIMARY KEY, nome VARCHAR(100))',
            'log_attivita' => 'CREATE TABLE log_attivita (id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT DEFAULT NULL, azione VARCHAR(255) NOT NULL, dettagli_json TEXT DEFAULT NULL, indirizzo_ip VARCHAR(45) DEFAULT NULL, data_ora DATETIME DEFAULT CURRENT_TIMESTAMP)',
            'impostazioni_sistema' => "CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, pubblicazioni_email_notifica VARCHAR(500) NOT NULL DEFAULT 'comunicazione.dibest@unical.it', pubblicazioni_approvazione_attiva TINYINT(1) NOT NULL DEFAULT 0)",
        ];
    }

    protected function setUp(): void
    {
        if (self::$conn !== null) {
            self::$conn->query('DROP TABLE IF EXISTS `pubblicazioni_allegati`'); // punta a pubblicazioni (chiave esterna): va tolta per prima
        }
        parent::setUp();
        $this->db->esegui("INSERT INTO ruoli (id, nome) VALUES (6, 'Docenti')");
        $this->db->esegui('INSERT INTO impostazioni_sistema (id) VALUES (1)');
        foreach ([self::DOCENTE, self::DIRETTORE, self::FLORIANA, self::OPERATORE] as $u) {
            $this->db->esegui('INSERT INTO utenti (id, nome, cognome, persona_id, ruolo_id, ruoli_secondari) VALUES (?, ?, ?, ?, ?, ?)', [$u['id'], $u['nome'], $u['cognome'], $u['persona_id'] ?? null, $u['ruolo_id'], $u['ruoli_secondari']]);
        }
        foreach ([['P-DIR', 'Dario', 'Direttore'], ['P-FLO', 'Floriana', 'Mantovano'], ['P-SENZA', 'Sara', 'Senzaaccount']] as [$id, $n, $c]) {
            $this->db->esegui("INSERT INTO personale_ateneo (id, nome, cognome, email, attivo) VALUES (?, ?, ?, ?, 1)", [$id, $n, $c, strtolower($n . '.' . $c) . '@unical.it']);
        }
        $this->cartella = 'cache/prove_pubblicazioni_' . bin2hex(random_bytes(4)) . '/';
        $this->costruisci();
    }

    protected function tearDown(): void
    {
        $dir = dirname(__DIR__, 3) . '/' . $this->cartella;
        if (is_dir($dir)) {
            foreach (glob($dir . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        FileDiProva::pulisci();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function dati(): array
    {
        return [
            'data_evento' => '2026-12-05', 'url_docente' => 'https://www.unical.it/eventi/notte', 'titolo' => 'Notte dei ricercatori', 'sottotitolo' => 'Laboratori aperti',
            'descrizione' => '<p>Una serata.</p>', 'modalita' => 'mista', 'per_cittadinanza' => '1', 'note' => '', 'tag' => '', 'data_inizio_pubblicazione' => '2026-11-20',
        ];
    }

    private function inviata(): int
    {
        $esito = $this->c->get(ServizioPubblicazioni::class)->invia(self::DOCENTE, $this->dati(), ['locandina' => [FileDiProva::pdf()]]);
        $this->assertSame([], $esito['errori']);

        return $esito['id'];
    }

    /** Accende l'approvazione nel database e ricostruisce i servizi (le impostazioni si leggono una volta per Container). */
    private function accendi(): void
    {
        $this->db->esegui('UPDATE impostazioni_sistema SET pubblicazioni_approvazione_attiva = 1 WHERE id = 1');
        $this->costruisci();
    }

    private function costruisci(): void
    {
        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Orologio::class, new OrologioFisso('2026-11-19 10:00:00'));
        $c->istanza(IndirizzoClient::class, new ClientFinto());
        $c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
        $c->istanza(Mailer::class, new MailerFinto());
        $c->set(AllegatiPubblicazioni::class, fn (Container $c): AllegatiPubblicazioni => new AllegatiPubblicazioni($c->get(Upload::class), $c->get(Sito::class), $this->cartella, false));
        $this->c = $c;
    }

    private function persone(): PersonePubblicazioni
    {
        return $this->c->get(PersonePubblicazioni::class);
    }

    private function approvazione(): ApprovazionePubblicazioni
    {
        return $this->c->get(ApprovazionePubblicazioni::class);
    }

    public function testDueApprovanoLaStessaRichiestaIlSecondoHaLErroreENelDatabaseRestaIlPrimo(): void
    {
        foreach (['P-DIR', 'P-FLO'] as $p) {
            $this->assertTrue($this->persone()->aggiungi('approvatore', $p, 1)->riuscito);
        }
        $this->accendi();
        $id = $this->inviata();
        $this->assertSame('da_approvare', $this->c->get(PubblicazioniRepository::class)->perId($id)['stato'], 'approvazione accesa e due approvatori con account: nasce in attesa');
        $this->assertCount(1, $this->approvazione()->daApprovare());

        $primo = $this->approvazione()->approva($id, self::DIRETTORE);
        $this->assertTrue($primo->riuscito, $primo->messaggio);
        $secondo = $this->approvazione()->approva($id, self::FLORIANA);
        $this->assertFalse($secondo->riuscito);
        $this->assertStringContainsString('già stata approvata da Dario Direttore il 19/11/2026', $secondo->messaggio);
        // anche respingere dopo un'approvazione non cambia niente
        $terzo = $this->approvazione()->respingi($id, self::FLORIANA, 'Troppo tardi');
        $this->assertFalse($terzo->riuscito);

        $p = $this->c->get(PubblicazioniRepository::class)->perId($id);
        $this->assertSame(['ricevuta', 10, '2026-11-19 10:00:00', ''], [$p['stato'], (int) $p['decisa_da'], $p['decisa_il'], $p['respinta_motivo']], 'nel database resta il primo');
        $this->assertSame([], $this->approvazione()->daApprovare());
        $this->assertSame(1, (int) $this->db->valore("SELECT COUNT(*) FROM log_attivita WHERE azione = 'Richieste di pubblicazione: approvata'"), 'una sola voce nel registro');
        $this->assertSame(0, (int) $this->db->valore("SELECT COUNT(*) FROM log_attivita WHERE azione = 'Richieste di pubblicazione: respinta'"));
    }

    public function testRespingereLasciaIlMotivoEdAncheQuestaDecisioneVaUnaVoltaSola(): void
    {
        $this->persone()->aggiungi('approvatore', 'P-DIR', 1);
        $this->accendi();
        $id = $this->inviata();

        $this->assertFalse($this->approvazione()->respingi($id, self::DIRETTORE, '   ')->riuscito, 'il motivo è obbligatorio');
        $this->assertSame('da_approvare', $this->c->get(PubblicazioniRepository::class)->perId($id)['stato'], 'un rifiuto senza motivo non cambia niente');
        $this->assertTrue($this->approvazione()->respingi($id, self::DIRETTORE, 'Manca la data di fine')->riuscito);
        $p = $this->c->get(PubblicazioniRepository::class)->perId($id);
        $this->assertSame(['respinta', 10, 'Manca la data di fine'], [$p['stato'], (int) $p['decisa_da'], $p['respinta_motivo']]);
        $this->assertStringContainsString('già stata respinta da Dario Direttore', $this->approvazione()->approva($id, self::DIRETTORE)->messaggio);
        // revisione P4: il «no» di chi approva non si ribalta dalla scheda
        $sv = $this->c->get(ServizioPubblicazioni::class);
        $this->assertFalse($sv->aggiornaOperatori($id, ['stato' => 'ricevuta'], self::OPERATORE)->riuscito, 'chi lavora non riapre una richiesta respinta da chi approva');
        $this->assertSame('respinta', $this->c->get(PubblicazioniRepository::class)->perId($id)['stato']);
    }

    public function testNonApprovaChiNonHaUnAccountCollegatoEIlLoroPermessoNonVieneDallEmail(): void
    {
        $this->persone()->aggiungi('approvatore', 'P-DIR', 1);
        $this->persone()->aggiungi('approvatore', 'P-SENZA', 1); // anagrafe sì, account no
        $this->accendi();
        $id = $this->inviata();

        foreach ([self::DOCENTE, self::OPERATORE, self::FLORIANA] as $non) {
            $this->assertFalse($this->approvazione()->puoApprovare($non));
            $this->assertFalse($this->approvazione()->approva($id, $non)->riuscito);
        }
        $this->assertTrue($this->approvazione()->puoApprovare(self::DIRETTORE));
        $this->assertSame('da_approvare', $this->c->get(PubblicazioniRepository::class)->perId($id)['stato']);
        $avvisi = array_column($this->persone()->elenco('approvatore'), 'avvisi', 'persona_id');
        $this->assertSame([], $avvisi['P-DIR']);
        $this->assertStringContainsString('account collegato', implode(' ', $avvisi['P-SENZA']));
        $this->assertSame(1, $this->persone()->approvatoriConAccount());
    }

    public function testApprovazioneSpentaOSenzaApprovatoriConAccountLaRichiestaNasceApprovataENonSiAccende(): void
    {
        // spenta (di partenza): come oggi
        $id = $this->inviata();
        $this->assertSame('ricevuta', $this->c->get(PubblicazioniRepository::class)->perId($id)['stato']);
        // accesa ma senza nessun approvatore: non resta bloccata
        $this->accendi();
        $this->assertSame('ricevuta', $this->c->get(PubblicazioniRepository::class)->perId($this->inviata())['stato']);
        // e dal pannello non si accende senza un approvatore con account
        $this->db->esegui('UPDATE impostazioni_sistema SET pubblicazioni_approvazione_attiva = 0 WHERE id = 1');
        $imp = new ImpostazioniPubblicazioni($this->db);
        $esito = $imp->aggiorna(['approvazione_attiva' => true]);
        $this->assertArrayHasKey('approvazione_attiva', $esito['errori']);
        $this->persone()->aggiungi('approvatore', 'P-SENZA', 1); // solo anagrafe: ancora niente account
        $this->assertArrayHasKey('approvazione_attiva', (new ImpostazioniPubblicazioni($this->db))->aggiorna(['approvazione_attiva' => true])['errori']);
        $this->persone()->aggiungi('approvatore', 'P-DIR', 1);
        $this->assertSame([], (new ImpostazioniPubblicazioni($this->db))->aggiorna(['approvazione_attiva' => true])['errori']);
        // revisione: con una richiesta in attesa l'ultimo approvatore con account non si toglie (resterebbe bloccata); senza account sì
        $inAttesa = $this->inviata();
        $this->assertSame('da_approvare', $this->c->get(PubblicazioniRepository::class)->perId($inAttesa)['stato']);
        $this->assertTrue($this->persone()->togli('approvatore', 'P-SENZA')->riuscito);
        $this->assertFalse($this->persone()->togli('approvatore', 'P-DIR')->riuscito);
        $this->approvazione()->approva($inAttesa, self::DIRETTORE);
        $this->assertTrue($this->persone()->togli('approvatore', 'P-DIR')->riuscito);
    }

    public function testInAttesaNonVaNelFileExcelEDallaSchedaNonSiAggiraLApprovazione(): void
    {
        $this->persone()->aggiungi('approvatore', 'P-DIR', 1);
        $this->accendi();
        $id = $this->inviata();
        $sv = $this->c->get(ServizioPubblicazioni::class);

        $this->assertFalse($sv->inviaAlMonitoraggio($id, self::OPERATORE)->riuscito);
        $this->assertFalse($sv->aggiornaOperatori($id, ['stato' => 'ricevuta'], self::OPERATORE)->riuscito, 'l\'operatore non approva dalla scheda');
        $this->assertFalse($sv->elimina($id, self::OPERATORE)->riuscito, 'revisione P4: in attesa non si elimina');
        $this->assertSame('da_approvare', $this->c->get(PubblicazioniRepository::class)->perId($id)['stato']);
        // la vedono chi l'ha inviata, chi gestisce e gli approvatori; non un altro docente
        $p = $this->c->get(PubblicazioniRepository::class)->perId($id);
        $this->assertTrue($sv->puoVedere(self::DOCENTE, $p));
        $this->assertTrue($sv->puoVedere(self::DIRETTORE, $p), 'l\'approvatore la apre');
        $this->assertFalse($sv->puoVedere(self::FLORIANA, $p), 'Floriana non è nella lista: non la vede');
        // approvata, ora va nel file Excel
        $this->approvazione()->approva($id, self::DIRETTORE);
        $this->assertTrue($sv->inviaAlMonitoraggio($id, self::OPERATORE)->riuscito);
        // la riga a mano dell'ufficio nasce sempre approvata
        $m = $sv->creaManuale(['data_evento' => '2026-12-01', 'richiedente' => 'Ufficio', 'titolo' => 'A mano', 'stato' => 'da_approvare'], self::OPERATORE, ['locandina' => [FileDiProva::pdf()]]);
        $this->assertSame('ricevuta', $this->c->get(PubblicazioniRepository::class)->perId($m['id'])['stato']);
    }
}
