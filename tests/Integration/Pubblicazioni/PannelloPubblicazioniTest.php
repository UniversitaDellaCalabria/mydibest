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
use App\Pubblicazioni\PubblicazioniRepository;
use App\Pubblicazioni\RegistroModifiche;
use App\Pubblicazioni\ServizioPubblicazioni;
use Tests\Doppi\ClientFinto;
use Tests\Doppi\FileDiProva;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/**
 * Il pannello degli operatori (passo R3): chi gestisce le richieste (amministratore o modulo «Richieste di pubblicazione»), chi scarica i file,
 * elenco ed esportazione con i filtri della tabella di monitoraggio, registro delle modifiche in parole.
 */
final class PannelloPubblicazioniTest extends DatabaseDiProva
{
    private const ADMIN = ['id' => 1, 'nome' => 'Ada', 'cognome' => 'Admin', 'ruolo_id' => 1, 'ruoli_secondari' => ''];
    private const DOCENTE = ['id' => 3, 'nome' => 'Maria', 'cognome' => 'Rossi', 'email' => 'maria.rossi@unical.it', 'ruolo_id' => 5, 'ruoli_secondari' => '6'];
    private const ALTRO_DOCENTE = ['id' => 8, 'nome' => 'Carlo', 'cognome' => 'Verdi', 'email' => 'carlo.verdi@unical.it', 'ruolo_id' => 5, 'ruoli_secondari' => '6'];
    /** Ha il modulo «Richieste di pubblicazione» */
    private const OPERATORE = ['id' => 4, 'nome' => 'Floriana', 'cognome' => 'Mantovano', 'ruolo_id' => 4, 'ruoli_secondari' => ''];
    /** Nessun modulo */
    private const SENZA_MODULO = ['id' => 5, 'nome' => 'Sara', 'cognome' => 'Senzamodulo', 'ruolo_id' => 4, 'ruoli_secondari' => ''];
    /** Ha solo MyForms */
    private const SOLO_FORMS = ['id' => 6, 'nome' => 'Franco', 'cognome' => 'Forms', 'ruolo_id' => 4, 'ruoli_secondari' => ''];

    private Container $c;
    private string $cartella;

    protected function tabelle(): array
    {
        return [
            'pubblicazioni' => Definizioni::tabelle()['pubblicazioni'],
            'pubblicazioni_allegati' => Definizioni::tabelle()['pubblicazioni_allegati'],
            'abilitazioni_ambito' => Definizioni::tabelle()['abilitazioni_ambito'],
            'ruoli' => 'CREATE TABLE ruoli (id INT PRIMARY KEY, nome VARCHAR(100))',
            'utenti' => 'CREATE TABLE utenti (id INT PRIMARY KEY, nome VARCHAR(100), cognome VARCHAR(100))',
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
        foreach ([[1, 'Ada', 'Admin'], [3, 'Maria', 'Rossi'], [4, 'Floriana', 'Mantovano'], [5, 'Sara', 'Senzamodulo'], [6, 'Franco', 'Forms'], [8, 'Carlo', 'Verdi']] as [$i, $n, $cg]) {
            $this->db->esegui('INSERT INTO utenti (id, nome, cognome) VALUES (?, ?, ?)', [$i, $n, $cg]);
        }
        // abilitazioni: Floriana ha il modulo «Richieste di pubblicazione», Franco solo MyForms
        $this->db->esegui("INSERT INTO abilitazioni_ambito (utente_id, tipo, pagina_id) VALUES (4, 'modulo_pubblicazioni', 0), (6, 'modulo_forms', 0)");
        $this->cartella = 'cache/prove_pannello_pubblicazioni_' . bin2hex(random_bytes(4)) . '/';
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

    /** Una richiesta del docente con un PDF e un'immagine (NON nel file Excel). */
    private function inviata(array $docente = self::DOCENTE, string $titolo = 'Notte dei ricercatori'): int
    {
        $esito = $this->servizio()->invia($docente, [
            'data_evento' => '2026-12-05', 'url_docente' => 'https://www.unical.it/eventi/notte', 'titolo' => $titolo, 'sottotitolo' => 'Laboratori aperti',
            'descrizione' => '<p>Una serata di laboratori.</p>', 'modalita' => 'presenza', 'per_cittadinanza' => '1', 'note' => 'nessuna', 'tag' => 'DIBEST',
            'data_inizio_pubblicazione' => '2026-11-20',
        ], ['allegati' => [FileDiProva::pdf()], 'immagini' => [FileDiProva::png()]]);
        $this->assertSame([], $esito['errori']);

        return $esito['id'];
    }

    /** Una riga a mano (entra subito nel file Excel). */
    private function manuale(string $titolo, string $data, string $stato = 'ricevuta', string $richiedente = 'Bianchi Luca'): int
    {
        $esito = $this->servizio()->creaManuale(['data_evento' => $data, 'richiedente' => $richiedente, 'titolo' => $titolo, 'stato' => $stato, 'tag' => 'seminario'], self::OPERATORE);
        $this->assertSame([], $esito['errori']);

        return $esito['id'];
    }

    public function testPuoGestireSoloAmministratoriEChiHaIlModulo(): void
    {
        $s = $this->servizio();
        $this->assertTrue($s->puoGestire(self::ADMIN), 'l\'amministratore gestisce sempre');
        $this->assertTrue($s->puoGestire(self::OPERATORE), 'chi ha il modulo gestisce');
        $this->assertFalse($s->puoGestire(self::SENZA_MODULO), 'chi non ha il modulo no');
        $this->assertFalse($s->puoGestire(self::SOLO_FORMS), 'avere MyForms non basta: sono moduli diversi');
        $this->assertFalse($s->puoGestire(self::DOCENTE), 'un docente non gestisce le richieste degli altri');
        $this->assertFalse($s->puoGestire(null));
        $this->assertFalse($s->puoGestire(['id' => 0, 'ruolo_id' => 1]), 'senza id non è una persona');
        // un amministratore con il ruolo secondario «1» (come in tutto il portale)
        $this->assertTrue($s->puoGestire(['id' => 9, 'ruolo_id' => 5, 'ruoli_secondari' => '6,1']));
        // il modulo vale solo come perimetro «intero» (pagina_id 0): lo stesso nome legato a un'area non basta
        $this->db->esegui("INSERT INTO abilitazioni_ambito (utente_id, tipo, pagina_id) VALUES (5, 'modulo_pubblicazioni', 12)");
        $this->assertFalse($this->servizio()->puoGestire(self::SENZA_MODULO));
    }

    public function testAncheGliOperatoriVedonoLeRichiesteEScaricanoIFile(): void
    {
        $id = $this->inviata();
        $p = $this->repo()->perId($id);
        $file = $this->repo()->allegati($id);
        $this->assertCount(2, $file);

        $s = $this->servizio();
        $this->assertTrue($s->puoVedere(self::DOCENTE, $p), 'il docente che l\'ha inviata');
        $this->assertTrue($s->puoVedere(self::ADMIN, $p));
        $this->assertTrue($s->puoVedere(self::OPERATORE, $p), 'chi ha il modulo vede le richieste di tutti');
        $this->assertFalse($s->puoVedere(self::SENZA_MODULO, $p));
        $this->assertFalse($s->puoVedere(self::SOLO_FORMS, $p));
        $this->assertFalse($s->puoVedere(self::ALTRO_DOCENTE, $p), 'un altro docente no');
        $this->assertFalse($s->puoVedere(null, $p));

        // lo scaricamento segue lo stesso permesso
        $scarico = $s->fileDaScaricare(self::OPERATORE, $id, (int) $file[0]['id']);
        $this->assertNotNull($scarico, 'l\'operatore scarica l\'allegato');
        $this->assertSame(['locandina.pdf', 'pdf'], [$scarico['nome'], $scarico['estensione']]);
        $this->assertFileExists($scarico['percorso']);
        $this->assertNotNull($s->fileDaScaricare(self::OPERATORE, $id, (int) $file[1]['id']), 'anche l\'immagine');
        $this->assertNull($s->fileDaScaricare(self::SENZA_MODULO, $id, (int) $file[0]['id']));
        $this->assertNull($s->fileDaScaricare(self::SOLO_FORMS, $id, (int) $file[0]['id']));
        $this->assertNull($s->fileDaScaricare(self::ALTRO_DOCENTE, $id, (int) $file[0]['id']));
        $this->assertNull($s->fileDaScaricare(null, $id, (int) $file[0]['id']));
        $this->assertNull($s->fileDaScaricare(self::OPERATORE, $id, 99999), 'un file che non c\'è');
        $this->assertNull($s->fileDaScaricare(self::OPERATORE, 99999, (int) $file[0]['id']), 'una richiesta che non c\'è');
    }

    public function testElencoEdEsportazioneRispettanoIFiltriDellaTabella(): void
    {
        $arrivata = $this->inviata(self::DOCENTE, 'Richiesta appena arrivata'); // 05/12/2026, NON nel file Excel
        $this->manuale('Seminario di chimica', '2026-03-10', 'pubblicata', 'Rossi Maria');
        $this->manuale('Open day di biologia', '2026-05-20', 'lavorazione', 'Verdi Carlo');
        $this->manuale('Convegno di geologia', '2026-09-15', 'pubblicata', 'Rossi Maria');
        $this->assertTrue($this->servizio()->inviaAlMonitoraggio($arrivata, self::OPERATORE)->riuscito);
        $this->assertTrue($this->servizio()->togliDalMonitoraggio($arrivata, self::OPERATORE)->riuscito);

        $titoli = fn (array $filtri): array => array_column(EsportazioneMonitoraggio::tabella($this->repo()->elenco($filtri + ['monitoraggio' => 'si']))['righe'], 6);

        // senza filtri: solo le righe nel file, dalla data dell'evento più recente
        $this->assertSame(['Convegno di geologia', 'Open day di biologia', 'Seminario di chimica'], $titoli([]));
        // la richiesta arrivata (e quella tolta dal file) non c'è nell'export
        $this->assertNotContains('Richiesta appena arrivata', $titoli([]));
        $this->assertSame(['Richiesta appena arrivata'], array_column($this->repo()->elenco(['monitoraggio' => 'no']), 'titolo'));
        // stato
        $this->assertSame(['Convegno di geologia', 'Seminario di chimica'], $titoli(['stato' => 'pubblicata']));
        $this->assertSame(['Open day di biologia'], $titoli(['stato' => 'lavorazione']));
        $this->assertSame([], $titoli(['stato' => 'respinta']));
        // periodo (data dell'evento, estremi compresi)
        $this->assertSame(['Convegno di geologia', 'Open day di biologia'], $titoli(['dal' => '2026-05-20']));
        $this->assertSame(['Open day di biologia', 'Seminario di chimica'], $titoli(['al' => '2026-05-20']));
        $this->assertSame(['Open day di biologia'], $titoli(['dal' => '2026-04-01', 'al' => '2026-06-30']));
        // testo (titolo, richiedente) e filtri insieme
        $this->assertSame(['Convegno di geologia', 'Seminario di chimica'], $titoli(['testo' => 'Rossi']));
        $this->assertSame(['Seminario di chimica'], $titoli(['testo' => 'rossi', 'dal' => '2026-01-01', 'al' => '2026-06-30']));
        $this->assertSame(['Open day di biologia'], $titoli(['testo' => 'open']));
        $this->assertSame([], $titoli(['testo' => 'xyz']));
        $this->assertSame([], $titoli(['testo' => 'Rossi', 'stato' => 'lavorazione']));
        // «%» e «_» nel testo si cercano come caratteri, non come caratteri jolly
        $this->assertSame([], $titoli(['testo' => '%']));

        // l'export con i filtri ha sempre le 24 colonne, con lo stato in fondo in parole
        $t = EsportazioneMonitoraggio::tabella($this->repo()->elenco(['monitoraggio' => 'si', 'stato' => 'pubblicata']));
        $this->assertCount(24, $t['intestazioni']);
        $this->assertSame('Stato', $t['intestazioni'][23]);
        $this->assertSame(['Pubblicata', 'Pubblicata'], array_column($t['righe'], 23));
        $this->assertSame(['15/09/2026', '10/03/2026'], array_column($t['righe'], 0));
    }

    public function testLaTabellaDiMonitoraggioEsceDaiDatiDelFileExcelCompilato(): void
    {
        $id = $this->inviata();
        $this->servizio()->inviaAlMonitoraggio($id, self::OPERATORE);
        $this->servizio()->aggiornaOperatori($id, ['f_pubblicazione_social' => 'SI', 'v_n_partecipanti' => '120'], self::OPERATORE);
        $t = EsportazioneMonitoraggio::tabella($this->repo()->elenco(['monitoraggio' => 'si']));
        $riga = array_combine($t['intestazioni'], $t['righe'][0]);
        $this->assertSame('SI', $riga['Pubblicazione social (SI/NO) (a cura di Gianni Giglio)']);
        $this->assertSame('120', $riga['N.  Partecipanti  (a cura di Floriana Mantovano o Silvana Geracitano)']);
        $this->assertSame('https://www.unical.it/eventi/notte', $riga['URL effettiva pubblicazione sito (a cura di Marco Bocci o Emanuele Dodaro)']);
        $this->assertSame('Ricevuta', $riga['Stato']);
        $this->assertSame('Una serata di laboratori.', $riga['Breve descrizione']);
    }

    public function testIlRegistroDiUnaRichiestaInParoleEDiSoloQuellaRichiesta(): void
    {
        $id = $this->inviata();
        $altra = $this->inviata(self::ALTRO_DOCENTE, 'Un\'altra richiesta');
        $this->servizio()->inviaAlMonitoraggio($id, self::OPERATORE);
        $this->servizio()->aggiornaOperatori($id, ['f_pubblicazione_social' => 'SI', 'c_altri_referenti' => 'Prof. Bianchi', 'stato' => 'lavorazione'], self::ADMIN);
        $this->servizio()->inviaAlMonitoraggio($altra, self::ADMIN);
        // un'altra richiesta il cui numero comincia come il nostro (es. id 1 e id 11) non finisce nel registro di questa
        $this->db->esegui("INSERT INTO log_attivita (utente_id, azione, dettagli_json) VALUES (4, 'Richieste di pubblicazione: tolta dal file Excel', ?)", ['{"id":' . $id . '1,"titolo":"altra"}']);
        // e un'azione che non è delle richieste di pubblicazione non conta
        $this->db->esegui("INSERT INTO log_attivita (utente_id, azione, dettagli_json) VALUES (4, 'MyForms: risposta eliminata', ?)", ['{"id":' . $id . ',"titolo":"x"}']);

        $voci = $this->c->get(RegistroModifiche::class)->perRichiesta($id);
        $this->assertCount(2, $voci);
        // la più recente per prima
        $this->assertSame(['Modifica', 'Ada Admin'], [$voci[0]['titolo'], $voci[0]['chi']]);
        $this->assertSame(['Inviata al file Excel', 'Floriana Mantovano'], [$voci[1]['titolo'], $voci[1]['chi']]);
        $this->assertContains('C · Altri referenti (interni o esterni all\'Unical): (vuoto) → «Prof. Bianchi»', $voci[0]['righe']);
        $this->assertContains('F · Pubblicazione social (SI/NO): (vuoto) → «SI»', $voci[0]['righe']);
        $this->assertContains('Stato: Ricevuta → In lavorazione', $voci[0]['righe']);
        $this->assertCount(1, $this->c->get(RegistroModifiche::class)->perRichiesta($altra));
        $this->assertSame([], $this->c->get(RegistroModifiche::class)->perRichiesta(0));
        $this->assertSame([], $this->c->get(RegistroModifiche::class)->perRichiesta(99999));
    }

    public function testGliErroriDelleColonneSiDannoCampoPerCampo(): void
    {
        $errori = ServizioPubblicazioni::erroriColonne(['f_pubblicazione_social' => 'forse', 'v_n_partecipanti' => '12abc', 'c_altri_referenti' => 'qualsiasi testo', 'o_piattaforma_pe' => 'no']);
        $this->assertSame(['f_pubblicazione_social', 'v_n_partecipanti'], array_keys($errori));
        $this->assertStringContainsString('SI, NO', $errori['f_pubblicazione_social']);
        $this->assertStringContainsString('numero', $errori['v_n_partecipanti']);
        $this->assertSame([], ServizioPubblicazioni::erroriColonne(['f_pubblicazione_social' => '', 'v_n_partecipanti' => '', 'u_produzione_locandina' => 'SI']));
        // quello che non è una colonna degli operatori si ignora (il docente non si tocca)
        $this->assertSame([], ServizioPubblicazioni::erroriColonne(['titolo' => str_repeat('x', 5000), 'stato' => 'boh']));
        // una riga fuori dal file Excel non salva le colonne ma lo stato sì (come in aggiornaOperatori)
        $id = $this->inviata();
        $this->assertTrue($this->servizio()->aggiornaOperatori($id, ['f_pubblicazione_social' => 'forse', 'stato' => 'lavorazione'], self::OPERATORE)->riuscito);
        $this->assertSame('lavorazione', $this->repo()->perId($id)['stato']);
        $this->assertNull($this->repo()->perId($id)['f_pubblicazione_social']);
        $this->assertColonneDelColoreDellExcel();
    }

    private function assertColonneDelColoreDellExcel(): void
    {
        foreach (ColonneMonitoraggio::tutte() as $c) {
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $c['colore'], 'ogni colonna ha il colore della sua intestazione');
        }
    }
}
