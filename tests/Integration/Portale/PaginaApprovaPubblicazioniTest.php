<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Auth\Csrf;
use App\Auth\Saml\CollegamentoAnagrafe;
use App\Auth\Sessione;
use App\Core\Container;
use App\Core\Database;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Core\Schema\Definizioni;
use App\Core\Sito;
use App\Http\Richiesta;
use App\Http\Vista;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Storage\Upload;
use App\Portale\Pagine\PaginaApprovaPubblicazioni;
use App\Pubblicazioni\AllegatiPubblicazioni;
use App\Pubblicazioni\PersonePubblicazioni;
use App\Pubblicazioni\PubblicazioniRepository;
use App\Pubblicazioni\ServizioPubblicazioni;
use Tests\Doppi\AuthSessioneInMemoria;
use Tests\Doppi\ClientFinto;
use Tests\Doppi\FileDiProva;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/**
 * La pagina di chi approva (App\Portale\Pagine\PaginaApprovaPubblicazioni) provata senza browser: una Richiesta::finta() entra, si legge la Risposta.
 * Le regole di approva/respingi (una sola decisione, il motivo obbligatorio…) le prova già ApprovazionePubblicazioniTest: qui si prova solo la pagina.
 * Modello come PaginaContattiTest: database di prova, modello VERO in una radice temporanea con testate finte.
 */
final class PaginaApprovaPubblicazioniTest extends DatabaseDiProva
{
    private const DOCENTE = ['id' => 3, 'nome' => 'Maria', 'cognome' => 'Rossi', 'email' => 'maria.rossi@unical.it', 'ruolo_id' => 5, 'ruoli_secondari' => '6', 'persona_id' => 'P-DOC'];
    private const DIRETTORE = ['id' => 10, 'nome' => 'Dario', 'cognome' => 'Direttore', 'ruolo_id' => 5, 'ruoli_secondari' => '6', 'persona_id' => 'P-DIR'];
    private const FLORIANA = ['id' => 11, 'nome' => 'Floriana', 'cognome' => 'Mantovano', 'ruolo_id' => 4, 'ruoli_secondari' => '', 'persona_id' => 'P-FLO'];
    private const OPERATORE = ['id' => 4, 'nome' => 'Ugo', 'cognome' => 'Operatore', 'ruolo_id' => 4, 'ruoli_secondari' => ''];

    private Container $c;
    private AuthSessioneInMemoria $sessione;
    private string $cartella;
    private string $radice = '';
    private ?string $radicePrima = null;
    /** @var array<string, array{0: string, 1: string}> */
    private array $layoutPrima = [];

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
        $this->db->esegui('INSERT INTO impostazioni_sistema (id, pubblicazioni_approvazione_attiva) VALUES (1, 1)');
        foreach ([self::DOCENTE, self::DIRETTORE, self::FLORIANA, self::OPERATORE] as $u) {
            $this->db->esegui('INSERT INTO utenti (id, nome, cognome, persona_id, ruolo_id, ruoli_secondari) VALUES (?, ?, ?, ?, ?, ?)', [$u['id'], $u['nome'], $u['cognome'], $u['persona_id'] ?? null, $u['ruolo_id'], $u['ruoli_secondari']]);
        }
        foreach ([['P-DIR', 'Dario', 'Direttore'], ['P-FLO', 'Floriana', 'Mantovano']] as [$id, $n, $co]) {
            $this->db->esegui('INSERT INTO personale_ateneo (id, nome, cognome, email, attivo) VALUES (?, ?, ?, ?, 1)', [$id, $n, $co, strtolower($n . '.' . $co) . '@unical.it']);
        }
        $this->cartella = 'cache/prove_pagina_approva_' . bin2hex(random_bytes(4)) . '/';
        $this->sessione = new AuthSessioneInMemoria();

        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Sessione::class, $this->sessione);
        // chi arriva ha già la sessione: l'accesso con l'SSO (che userebbe il collegamento con l'anagrafe) non parte mai
        $c->istanza(CollegamentoAnagrafe::class, new class implements CollegamentoAnagrafe {
            public function collega(int $utenteId, string $emailSso): ?string
            {
                return null;
            }
        });
        $c->istanza(Orologio::class, new OrologioFisso('2026-11-19 10:00:00'));
        $c->istanza(IndirizzoClient::class, new ClientFinto());
        $c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
        $c->istanza(Mailer::class, new MailerFinto());
        $c->set(AllegatiPubblicazioni::class, fn (Container $c): AllegatiPubblicazioni => new AllegatiPubblicazioni($c->get(Upload::class), $c->get(Sito::class), $this->cartella, false));
        $this->c = $c;
        foreach (['P-DIR', 'P-FLO'] as $p) {
            $c->get(PersonePubblicazioni::class)->aggiungi('approvatore', $p, 1);
        }

        $this->radice = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pagina_approva_' . bin2hex(random_bytes(4));
        mkdir($this->radice . '/viste/pubblico', 0777, true);
        copy(RADICE_SITO . '/viste/pubblico/approva_pubblicazioni.php', $this->radice . '/viste/pubblico/approva_pubblicazioni.php');   // il modello VERO
        file_put_contents($this->radice . '/header.php', '<?php echo "<html><title>" . htmlspecialchars($page_cfg[\'titolo\'] ?? \'\') . "</title><body>"; ?>');
        file_put_contents($this->radice . '/footer.php', '</body></html>');
        $this->radicePrima = Vista::usaRadice($this->radice);
        $this->layoutPrima = Vista::layout(['sito' => ['header.php', 'footer.php'], 'pannello' => ['header.php', 'footer.php']]);
    }

    protected function tearDown(): void
    {
        Vista::usaRadice($this->radicePrima);
        Vista::layout($this->layoutPrima ?: ['sito' => ['header.php', 'footer.php'], 'pannello' => ['header.php', 'footer.php']]);
        foreach (['viste/pubblico/approva_pubblicazioni.php', 'header.php', 'footer.php'] as $f) {
            @unlink($this->radice . '/' . $f);
        }
        @rmdir($this->radice . '/viste/pubblico');
        @rmdir($this->radice . '/viste');
        @rmdir($this->radice);
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

    private function inviata(): int
    {
        $esito = $this->c->get(ServizioPubblicazioni::class)->invia(self::DOCENTE, [
            'data_evento' => '2026-12-05', 'url_docente' => 'https://www.unical.it/eventi/notte', 'titolo' => 'Notte dei ricercatori', 'sottotitolo' => 'Laboratori aperti',
            'descrizione' => '<p>Una serata.</p>', 'modalita' => 'mista', 'per_cittadinanza' => '1', 'note' => '', 'tag' => '', 'data_inizio_pubblicazione' => '2026-11-20',
        ], ['locandina' => [FileDiProva::pdf()]]);
        $this->assertSame([], $esito['errori']);

        return $esito['id'];
    }

    private function stato(int $id): string
    {
        return (string) $this->c->get(PubblicazioniRepository::class)->perId($id)['stato'];
    }

    /** Un'altra persona apre la pagina: la sessione cambia, il container (e il database) restano. */
    private function come(array $utente, string $metodo, array $query = [], array $post = []): \App\Http\Risposta
    {
        $this->sessione->dati = ['utente_id' => $utente['id']];
        $pagina = $this->c->get(PaginaApprovaPubblicazioni::class);
        $post += $metodo === 'POST' ? ['csrf_token' => $this->c->get(Csrf::class)->token()] : [];

        return $pagina(Richiesta::finta($metodo, '/approva_pubblicazioni.php', $query, $post));
    }

    public function testChiNonApprovaNonEntra(): void
    {
        $id = $this->inviata();

        $r = $this->come(self::OPERATORE, 'GET');
        self::assertSame(403, $r->codice());
        self::assertStringContainsString('riservata a chi approva', $r->corpo());
        self::assertStringNotContainsString('Notte dei ricercatori', $r->corpo(), 'niente titoli a chi non approva');

        $r = $this->come(self::OPERATORE, 'POST', ['id' => $id], ['id' => $id]);
        self::assertSame(403, $r->codice(), 'neanche con un POST');
        self::assertSame('da_approvare', $this->stato($id), 'e la richiesta resta com\'era');
    }

    public function testChiApprovaDecideESiTornaAllaRichiesta(): void
    {
        $id = $this->inviata();
        self::assertSame('da_approvare', $this->stato($id));
        self::assertStringContainsString('Notte dei ricercatori', $this->come(self::DIRETTORE, 'GET')->corpo(), 'sta nell\'elenco «Da approvare»');

        $r = $this->come(self::DIRETTORE, 'POST', ['id' => $id], ['id' => $id]);

        self::assertSame(303, $r->codice());
        self::assertSame('approva_pubblicazioni.php?id=' . $id, $r->intestazione('Location'));
        self::assertSame('ricevuta', $this->stato($id), 'approvata: passa all\'ufficio');
        $corpo = $this->come(self::DIRETTORE, 'GET', ['id' => $id])->corpo();
        self::assertStringContainsString("L'hai approvata tu il 19/11/2026", $corpo, 'chi ha deciso lo legge in prima persona');
        self::assertStringNotContainsString('Non devi fare niente', $corpo);
    }

    public function testSeUnAltroHaGiaDecisoUnMessaggioChiaroENonUnErroreTecnico(): void
    {
        $id = $this->inviata();
        $this->come(self::DIRETTORE, 'POST', ['id' => $id], ['id' => $id]);

        $r = $this->come(self::FLORIANA, 'POST', ['id' => $id], ['id' => $id]);
        self::assertSame(303, $r->codice(), 'si torna alla richiesta, nessuna pagina d\'errore');

        $corpo = $this->come(self::FLORIANA, 'GET', ['id' => $id])->corpo();
        self::assertStringContainsString('già stata approvata da Dario Direttore il 19/11/2026', $corpo);
        self::assertStringContainsString('Non devi fare niente', $corpo);
        self::assertStringNotContainsString('name="respingi"', $corpo, 'e i pulsanti non ci sono più');
        self::assertSame('ricevuta', $this->stato($id));
    }
}
