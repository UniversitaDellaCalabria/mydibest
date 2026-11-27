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
use App\Pubblicazioni\PubblicazioniRepository;
use App\Pubblicazioni\ServizioPubblicazioni;
use RuntimeException;
use Tests\Doppi\ClientFinto;
use Tests\Doppi\FileDiProva;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/**
 * Richieste di pubblicazione, passo R2: le email dell'invio (avviso all'ufficio comunicazione e ricevuta al docente, mai fatali) e lo scaricamento dei
 * file (solo il docente che ha inviato la richiesta e gli amministratori).
 */
final class NotificheEScaricoTest extends DatabaseDiProva
{
    private const ADMIN = ['id' => 1, 'nome' => 'Ada', 'cognome' => 'Admin', 'ruolo_id' => 1, 'ruoli_secondari' => ''];
    private const DOCENTE = ['id' => 3, 'nome' => 'Maria', 'cognome' => 'Rossi', 'email' => 'maria.rossi@unical.it', 'ruolo_id' => 5, 'ruoli_secondari' => '6'];
    private const ALTRO_DOCENTE = ['id' => 8, 'nome' => 'Paolo', 'cognome' => 'Bianchi', 'email' => 'paolo.bianchi@unical.it', 'ruolo_id' => 5, 'ruoli_secondari' => '6'];
    private const STUDENTE = ['id' => 2, 'nome' => 'Luca', 'cognome' => 'Studente', 'ruolo_id' => 3, 'ruoli_secondari' => '', 'matricola_studente' => '123'];

    private Container $c;
    private MailerFinto $mailer;
    private string $cartella;
    private string $registroErrori = '';
    private string|false $erroriPrima = '';

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
        if (self::$conn !== null) {
            self::$conn->query('DROP TABLE IF EXISTS `pubblicazioni_allegati`'); // punta a pubblicazioni (chiave esterna): va tolta per prima
        }
        parent::setUp();
        $this->db->esegui("INSERT INTO ruoli (id, nome) VALUES (6, 'Docenti')");
        $this->db->esegui('INSERT INTO impostazioni_sistema (id) VALUES (1)');
        $this->cartella = 'cache/prove_pubblicazioni_' . bin2hex(random_bytes(4)) . '/';
        $this->mailer = new MailerFinto();
        $this->costruisci($this->mailer);
        // gli errori delle email vanno solo nel log: qui il log è un file a parte, così li si può anche leggere
        $this->registroErrori = (string) tempnam(sys_get_temp_dir(), 'log');
        $this->erroriPrima = ini_set('error_log', $this->registroErrori);
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
        if ($this->erroriPrima !== false) {
            ini_set('error_log', (string) $this->erroriPrima);
        }
        @unlink($this->registroErrori);
    }

    private function costruisci(Mailer $mailer): void
    {
        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Orologio::class, new OrologioFisso('2026-11-19 10:00:00'));
        $c->istanza(IndirizzoClient::class, new ClientFinto());
        $c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
        $c->istanza(Mailer::class, $mailer);
        $c->set(AllegatiPubblicazioni::class, fn (Container $c): AllegatiPubblicazioni => new AllegatiPubblicazioni($c->get(Upload::class), $c->get(Sito::class), $this->cartella, false));
        $this->c = $c;
    }

    private function servizio(): ServizioPubblicazioni
    {
        return $this->c->get(ServizioPubblicazioni::class);
    }

    /** @param array<string, mixed> $cambi @return array<string, mixed> */
    private function dati(array $cambi = []): array
    {
        return array_merge([
            'data_evento' => '2026-12-05', 'url_docente' => 'https://www.unical.it/eventi/notte', 'altri_link_docente' => "https://a.unical.it/1\nhttps://b.unical.it/2",
            'titolo' => 'Notte dei ricercatori', 'sottotitolo' => 'Laboratori aperti', 'descrizione' => '<p>Una serata di <b>laboratori</b>.</p>',
            'modalita' => 'mista', 'per_cittadinanza' => '1', 'per_studenti' => '1', 'note' => 'Serve la sala grande', 'tag' => 'DIBEST, notizie',
            'data_inizio_pubblicazione' => '2026-11-20',
        ], $cambi);
    }

    /** @param array<string, mixed> $utente */
    private function invia(array $utente = self::DOCENTE, array $cambi = []): int
    {
        $esito = $this->servizio()->invia($utente, $this->dati($cambi), ['locandina' => [FileDiProva::pdf('Locandina notte.pdf')], 'immagini' => [FileDiProva::png('anteprima.png')]]);
        $this->assertSame([], $esito['errori']);

        return $esito['id'];
    }

    public function testLeEmailVannoAgliIndirizziGiustiConTuttiICampi(): void
    {
        $this->db->esegui('UPDATE impostazioni_sistema SET pubblicazioni_email_notifica = ? WHERE id = 1', ['comunicazione@unical.it, gianni@unical.it']);
        $id = $this->invia();

        $a = array_column($this->mailer->inviate, null, 'a');
        $this->assertSame(['comunicazione@unical.it', 'gianni@unical.it', 'maria.rossi@unical.it'], array_keys($a), 'due avvisi all\'ufficio e una ricevuta al docente, non altro');

        // avviso all'ufficio
        $avviso = $a['comunicazione@unical.it'];
        $this->assertSame('Richiesta di pubblicazione: Notte dei ricercatori', $avviso['oggetto']);
        foreach (['Rossi Maria', 'maria.rossi@unical.it', '05/12/2026', 'Mista', 'https://www.unical.it/eventi/notte', 'https://a.unical.it/1', 'https://b.unical.it/2',
            'Notte dei ricercatori', 'Laboratori aperti', 'Cittadinanza, Studenti universitari', 'Locandina notte.pdf', 'anteprima.png', 'DIBEST, notizie', '20/11/2026', 'Serve la sala grande'] as $atteso) {
            $this->assertStringContainsString($atteso, $avviso['corpo'], $atteso);
        }
        $this->assertStringContainsString('<p>Una serata di <b>laboratori</b>.</p>', $avviso['corpo'], 'la descrizione tiene il grassetto');
        $this->assertStringContainsString("href='https://portale.test/eventi/admin/pubblicazioni.php?id=$id'", $avviso['corpo'], 'pulsante alla scheda nel pannello');
        $this->assertSame([], $avviso['allegati'], 'i file non si allegano: si scaricano dalla scheda');
        $this->assertSame($avviso['corpo'], $a['gianni@unical.it']['corpo']);

        // ricevuta al docente, con la copia di quanto ha inviato
        $ricevuta = $a['maria.rossi@unical.it'];
        $this->assertStringContainsString('Notte dei ricercatori', $ricevuta['oggetto']);
        foreach (['Maria', 'Laboratori aperti', 'Locandina notte.pdf', '05/12/2026', 'Serve la sala grande', 'non si può modificare'] as $atteso) {
            $this->assertStringContainsString($atteso, $ricevuta['corpo'], $atteso);
        }
        $this->assertStringContainsString("mailto:comunicazione@unical.it", $ricevuta['corpo'], 'per correzioni: il primo indirizzo di notifica');
        $this->assertSame([], $ricevuta['allegati']);
    }

    public function testIlTestoDellaRichiestaNonPuoIntrodurreHtml(): void
    {
        $this->invia(self::DOCENTE, ['titolo' => 'Titolo <script>alert(1)</script> & ok', 'note' => '<img src=x onerror=alert(1)>']);
        $corpo = $this->mailer->inviate[0]['corpo'];
        $this->assertStringNotContainsString('<script>', $corpo);
        $this->assertStringNotContainsString('<img', $corpo);
        $this->assertStringContainsString('&lt;img', $corpo);
    }

    public function testUnErroreDelMailerNonAnnullaLaRichiesta(): void
    {
        $this->costruisci(new class () implements Mailer {
            public function invia(string $a, string $oggetto, string $corpoHtml, ?string $colore = null, array $allegati = []): bool
            {
                return false;
            }

            public function ultimoErrore(): string
            {
                return 'server di posta non raggiungibile';
            }
        });
        $id = $this->invia();
        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM pubblicazioni'));
        $this->assertSame(2, (int) $this->db->valore('SELECT COUNT(*) FROM pubblicazioni_allegati'));
        $this->assertStringContainsString('server di posta non raggiungibile', (string) file_get_contents($this->registroErrori), 'l\'errore resta nel log');
    }

    public function testUnMailerCheLanciaEccezioniNonAnnullaLaRichiesta(): void
    {
        $this->costruisci(new class () implements Mailer {
            public function invia(string $a, string $oggetto, string $corpoHtml, ?string $colore = null, array $allegati = []): bool
            {
                throw new RuntimeException('connessione SMTP caduta');
            }

            public function ultimoErrore(): string
            {
                return '';
            }
        });
        $id = $this->invia();
        $this->assertSame('ricevuta', $this->c->get(PubblicazioniRepository::class)->perId($id)['stato']);
        $this->assertStringContainsString('connessione SMTP caduta', (string) file_get_contents($this->registroErrori));
    }

    public function testSenzaEmailDelDocenteParteSoloLAvviso(): void
    {
        $this->invia(['id' => 3, 'nome' => 'Maria', 'cognome' => 'Rossi', 'email' => '', 'ruolo_id' => 5, 'ruoli_secondari' => '6']);
        $this->assertSame(['comunicazione.dibest@unical.it'], array_column($this->mailer->inviate, 'a'));
        $this->assertStringContainsString('chi ha inviato la richiesta non ha un indirizzo email valido', (string) file_get_contents($this->registroErrori), 'il motivo resta nel log');
    }

    public function testConErroriNonPartonoEmail(): void
    {
        $esito = $this->servizio()->invia(self::DOCENTE, $this->dati(['sottotitolo' => str_repeat('x', 76)]), ['locandina' => [FileDiProva::pdf()]]);
        $this->assertSame(0, $esito['id']);
        $this->assertSame([], $this->mailer->inviate);
        $this->servizio()->invia(self::STUDENTE, $this->dati(), ['locandina' => [FileDiProva::pdf()]]);
        $this->assertSame([], $this->mailer->inviate, 'nemmeno per chi non è docente');
    }

    public function testLoScaricamentoSoloAlProprietarioEAgliAmministratori(): void
    {
        $id = $this->invia();
        $repo = $this->c->get(PubblicazioniRepository::class);
        [$pdf, $png] = $repo->allegati($id);

        $f = $this->servizio()->fileDaScaricare(self::DOCENTE, $id, (int) $pdf['id']);
        $this->assertNotNull($f, 'il docente che ha inviato la richiesta scarica');
        $this->assertSame(['Locandina_Notte_dei_ricercatori.pdf', 'pdf'], [$f['nome'], $f['estensione']], 'la locandina si scarica come Locandina_<titolo breve>');
        $this->assertSame('anteprima.png', $this->servizio()->fileDaScaricare(self::DOCENTE, $id, (int) $png['id'])['nome'] ?? '', 'gli altri file mantengono il nome con cui sono stati caricati');
        $this->assertFileExists($f['percorso']);
        $this->assertNotNull($this->servizio()->fileDaScaricare(self::DOCENTE, $id, (int) $png['id']));

        $this->assertNull($this->servizio()->fileDaScaricare(self::ALTRO_DOCENTE, $id, (int) $pdf['id']), 'un altro docente no');
        $this->assertNull($this->servizio()->fileDaScaricare(self::STUDENTE, $id, (int) $pdf['id']), 'uno studente no');
        $this->assertNull($this->servizio()->fileDaScaricare(null, $id, (int) $pdf['id']), 'senza accesso no');
        $this->assertNotNull($this->servizio()->fileDaScaricare(self::ADMIN, $id, (int) $pdf['id']), 'gli amministratori sì');
    }

    public function testLoScaricamentoNonSiInganna(): void
    {
        $id = $this->invia();
        $altra = $this->invia(self::ALTRO_DOCENTE);
        $repo = $this->c->get(PubblicazioniRepository::class);
        $fileDellAltra = $repo->allegati($altra)[0];

        $this->assertNull($this->servizio()->fileDaScaricare(self::DOCENTE, $id, (int) $fileDellAltra['id']), 'un file di un\'altra richiesta non si scarica con l\'id della propria');
        $this->assertNull($this->servizio()->fileDaScaricare(self::DOCENTE, $id, 999999), 'file inesistente');
        $this->assertNull($this->servizio()->fileDaScaricare(self::DOCENTE, 999999, 1), 'richiesta inesistente');

        // un percorso che esce dalla cartella degli allegati non si serve, nemmeno con la riga giusta
        $mio = $repo->allegati($id)[0];
        $this->db->esegui('UPDATE pubblicazioni_allegati SET percorso = ? WHERE id = ?', ['config.php', (int) $mio['id']]);
        $this->assertNull($this->servizio()->fileDaScaricare(self::ADMIN, $id, (int) $mio['id']));
        $this->db->esegui('UPDATE pubblicazioni_allegati SET percorso = ? WHERE id = ?', [$this->cartella . '../config.php', (int) $mio['id']]);
        $this->assertNull($this->servizio()->fileDaScaricare(self::ADMIN, $id, (int) $mio['id']));
    }

    public function testChiVedeLaRichiesta(): void
    {
        $id = $this->invia();
        $p = $this->c->get(PubblicazioniRepository::class)->perId($id);
        $this->assertTrue($this->servizio()->puoVedere(self::DOCENTE, $p));
        $this->assertTrue($this->servizio()->puoVedere(self::ADMIN, $p));
        $this->assertFalse($this->servizio()->puoVedere(self::ALTRO_DOCENTE, $p));
        $this->assertFalse($this->servizio()->puoVedere(null, $p));
        // una riga a mano non ha un docente proprietario: la vedono solo gli amministratori
        $manuale = $this->servizio()->creaManuale(['data_evento' => '2026-12-01', 'richiedente' => 'Ufficio', 'titolo' => 'A mano'], self::ADMIN, ['locandina' => [FileDiProva::pdf()]]);
        $riga = $this->c->get(PubblicazioniRepository::class)->perId($manuale['id']);
        $this->assertFalse($this->servizio()->puoVedere(self::DOCENTE, $riga));
        $this->assertTrue($this->servizio()->puoVedere(self::ADMIN, $riga));
    }
}
