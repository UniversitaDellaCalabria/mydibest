<?php

declare(strict_types=1);

namespace Tests\Integration\Pubblicazioni;

use App\Anagrafi\ClientApiAteneo;
use App\Core\Container;
use App\Core\Database;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Core\Schema\Definizioni;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Storage\Upload;
use App\Pubblicazioni\AllegatiPubblicazioni;
use App\Pubblicazioni\CampiAggiunti;
use App\Pubblicazioni\ColonneMonitoraggio;
use App\Pubblicazioni\PubblicazioniRepository;
use App\Pubblicazioni\RegistroModifiche;
use App\Pubblicazioni\ServizioPubblicazioni;
use Tests\Doppi\AnagrafiClientApiFinto;
use Tests\Doppi\ClientFinto;
use Tests\Doppi\FileDiProva;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/**
 * Salvare le colonne degli operatori di più richieste insieme (passo AM7, vista «Da compilare»), con il database: tutto o niente, errori cella per cella,
 * una voce del registro delle modifiche per ogni riga, anche per le colonne nuove (y_<n>).
 */
final class CompilazioneInBloccoTest extends DatabaseDiProva
{
    private const OPERATORE = ['id' => 4, 'nome' => 'Floriana', 'cognome' => 'Operatrice', 'ruolo_id' => 4, 'ruoli_secondari' => ''];

    private Container $c;
    private string $cartella;

    protected function tabelle(): array
    {
        return [
            'pubblicazioni' => Definizioni::tabelle()['pubblicazioni'],
            'pubblicazioni_allegati' => Definizioni::tabelle()['pubblicazioni_allegati'],
            'pubblicazioni_campi' => Definizioni::tabelle()['pubblicazioni_campi'],
            'pubblicazioni_colonne' => Definizioni::tabelle()['pubblicazioni_colonne'],
            'ruoli' => 'CREATE TABLE ruoli (id INT PRIMARY KEY, nome VARCHAR(100))',
            'utenti' => 'CREATE TABLE utenti (id INT PRIMARY KEY, nome VARCHAR(100), cognome VARCHAR(100))',
            'log_attivita' => 'CREATE TABLE log_attivita (id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT DEFAULT NULL, azione VARCHAR(255) NOT NULL, dettagli_json TEXT DEFAULT NULL, indirizzo_ip VARCHAR(45) DEFAULT NULL, data_ora DATETIME DEFAULT CURRENT_TIMESTAMP)',
            'impostazioni_sistema' => "CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, pubblicazioni_email_notifica VARCHAR(500) NOT NULL DEFAULT 'comunicazione.dibest@unical.it', pubblicazioni_ultimo_x SMALLINT NOT NULL DEFAULT 0, pubblicazioni_ultimo_y SMALLINT NOT NULL DEFAULT 0)",
        ];
    }

    protected function setUp(): void
    {
        if (self::$conn !== null) {
            self::$conn->query('DROP TABLE IF EXISTS `pubblicazioni_allegati`'); // punta a pubblicazioni (chiave esterna): va tolta per prima
        }
        parent::setUp();
        $this->db->esegui("INSERT INTO ruoli (id, nome) VALUES (6, 'Docenti')");
        $this->db->esegui("INSERT INTO utenti (id, nome, cognome) VALUES (4, 'Floriana', 'Operatrice')");
        $this->db->esegui('INSERT INTO impostazioni_sistema (id) VALUES (1)');
        $this->cartella = 'cache/prove_pubblicazioni_' . bin2hex(random_bytes(4)) . '/';
        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Orologio::class, new OrologioFisso('2026-11-20 10:00:00'));
        $c->istanza(IndirizzoClient::class, new ClientFinto());
        $c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
        $c->istanza(Mailer::class, new MailerFinto());
        $c->istanza(ClientApiAteneo::class, new AnagrafiClientApiFinto());
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
        // il database di prova è condiviso tra le classi: le colonne nuove non devono restare per gli altri test delle Pubblicazioni
        if (self::$conn !== null) {
            self::$conn->query('DROP TABLE IF EXISTS `pubblicazioni_campi`');
            self::$conn->query('DROP TABLE IF EXISTS `pubblicazioni_colonne`');
        }
    }

    private function servizio(): ServizioPubblicazioni
    {
        return $this->c->get(ServizioPubblicazioni::class);
    }

    private function repo(): PubblicazioniRepository
    {
        return $this->c->get(PubblicazioniRepository::class);
    }

    /** Una riga nel file Excel con tutte le colonne degli operatori vuote. @param array<string, mixed> $cambi */
    private function riga(string $titolo, array $cambi = []): int
    {
        return $this->repo()->inserisci(array_merge([
            'stato' => 'pubblicata', 'nel_monitoraggio' => 1, 'data_evento' => '2026-12-05', 'richiedente' => 'Rossi Maria', 'titolo' => $titolo, 'sottotitolo' => 'Sotto', 'modalita' => 'mista', 'utente_id' => 3,
        ], $cambi));
    }

    /** @return list<string> le righe del registro delle modifiche di una richiesta, scritte in parole */
    private function registro(int $id): array
    {
        $righe = [];
        foreach ($this->c->get(RegistroModifiche::class)->perRichiesta($id) as $voce) {
            if ($voce['titolo'] === 'Modifica') {
                $righe = array_merge($righe, $voce['righe']);
            }
        }

        return $righe;
    }

    private function vociModifica(): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) FROM log_attivita WHERE azione = 'Richieste di pubblicazione: modifica'");
    }

    public function testDueRigheInsiemeConUnaCellaSbagliataNonSalvanoNienteEPoiSiSalvanoTutteEDueConLaColonnaNuova(): void
    {
        // una colonna nuova (y_1, SI/NO) tra quelle degli operatori
        $esito = $this->c->get(CampiAggiunti::class)->salvaColonne(['ycol' => ['nuova_1' => ['intestazione' => 'Esito social', 'a_cura_di' => 'Ufficio comunicazione', 'colore' => '#FF0000', 'tipo' => 'si_no', 'dopo' => 'titolo', 'visibile' => '1']]]);
        $this->assertSame([], $esito['errori']);
        $a = $this->riga('Prima iniziativa');
        $b = $this->riga('Seconda iniziativa');

        // la riga B ha una cella SI/NO sbagliata: non si salva niente, nemmeno la riga A che è giusta
        $celle = [
            $a => ['c_altri_referenti' => 'Prof. Bianchi', 'y_1' => 'si'],
            $b => ['f_pubblicazione_social' => 'forse', 'c_altri_referenti' => 'Prof. Verdi'],
        ];
        $r = $this->servizio()->aggiornaOperatoriInBlocco($celle, self::OPERATORE);
        $this->assertSame(0, $r['salvate']);
        $this->assertSame([$b], array_keys($r['errori']), 'l\'errore è solo sulla riga B');
        $this->assertSame(['f_pubblicazione_social'], array_keys($r['errori'][$b]), 'e sulla sua cella');
        $this->assertStringContainsString('SI, NO', $r['errori'][$b]['f_pubblicazione_social']);
        $this->assertNull($this->repo()->perId($a)['c_altri_referenti'], 'A non è stata toccata');
        $this->assertArrayNotHasKey('y_1', $this->repo()->perId($a));
        $this->assertNull($this->repo()->perId($b)['c_altri_referenti']);
        $this->assertSame(0, $this->vociModifica(), 'e il registro è vuoto');

        // corretto: tutte e due si salvano, ognuna con la sua voce nel registro
        $celle[$b]['f_pubblicazione_social'] = 'no';
        $r = $this->servizio()->aggiornaOperatoriInBlocco($celle, self::OPERATORE);
        $this->assertSame([], $r['errori']);
        $this->assertSame(2, $r['salvate']);
        $pa = $this->repo()->perId($a);
        $pb = $this->repo()->perId($b);
        $this->assertSame('Prof. Bianchi', $pa['c_altri_referenti']);
        $this->assertSame('SI', $pa['y_1'], 'la colonna nuova y_<n>, come le altre');
        $this->assertSame(['y_1' => 'SI'], json_decode((string) $this->db->valore('SELECT colonne_extra_json FROM pubblicazioni WHERE id = ?', [$a]), true));
        $this->assertSame('Prof. Verdi', $pb['c_altri_referenti']);
        $this->assertSame('NO', $pb['f_pubblicazione_social']);
        $this->assertSame(4, (int) $pa['modificata_da']);
        $this->assertNotEmpty($pa['modificata_il']);

        $this->assertSame(2, $this->vociModifica(), 'una voce per ogni riga cambiata');
        $this->assertContains('C · Altri referenti (interni o esterni all\'Unical): (vuoto) → «Prof. Bianchi»', $this->registro($a));
        $this->assertContains('Esito social: (vuoto) → «SI»', $this->registro($a));
        $this->assertContains('F · Pubblicazione social (SI/NO): (vuoto) → «NO»', $this->registro($b));
        $this->assertNotContains('Esito social: (vuoto) → «SI»', $this->registro($b), 'ognuna ha solo le sue modifiche');
    }

    public function testLeCelleUgualiNonSiControllanoNeSiScrivonoEUnVecchioValoreFuoriRegolaNonBloccaLeAltre(): void
    {
        $a = $this->riga('Con un vecchio valore');
        $this->db->esegui("UPDATE pubblicazioni SET f_pubblicazione_social = 'forse' WHERE id = ?", [$a]); // com'era prima che la colonna diventasse SI/NO
        $r = $this->servizio()->aggiornaOperatoriInBlocco([$a => ['f_pubblicazione_social' => 'forse', 'c_altri_referenti' => 'Prof. Neri']], self::OPERATORE);
        $this->assertSame([], $r['errori']);
        $this->assertSame(1, $r['salvate']);
        $p = $this->repo()->perId($a);
        $this->assertSame('forse', $p['f_pubblicazione_social'], 'lasciato com\'era');
        $this->assertSame('Prof. Neri', $p['c_altri_referenti']);

        // niente di diverso da quello che c'è già (anche con spazi e SI/NO in minuscolo): niente salvato, niente nel registro
        $b = $this->riga('Già compilata', ['f_pubblicazione_social' => 'SI', 'c_altri_referenti' => 'Prof. Rossi']);
        $voci = $this->vociModifica();
        $r = $this->servizio()->aggiornaOperatoriInBlocco([$b => ['f_pubblicazione_social' => ' si ', 'c_altri_referenti' => "  Prof. Rossi \r\n"]], self::OPERATORE);
        $this->assertSame(['salvate' => 0, 'errori' => []], $r);
        $this->assertSame($voci, $this->vociModifica());
        $this->assertNull($this->repo()->perId($b)['modificata_il']);
    }

    public function testSoloLeColonneDegliOperatoriSiSalvanoELeRigheNonValideDannoErrore(): void
    {
        $a = $this->riga('Una riga', ['stato' => 'lavorazione']);
        $fuori = $this->repo()->inserisci(['stato' => 'ricevuta', 'nel_monitoraggio' => 0, 'data_evento' => '2026-12-05', 'richiedente' => 'Rossi Maria', 'titolo' => 'Non ancora nel file', 'utente_id' => 3]);

        // lo stato e i campi del docente non si cambiano da qui; un invio manomesso (un elenco al posto di un valore) si ignora
        $r = $this->servizio()->aggiornaOperatoriInBlocco([$a => ['stato' => 'respinta', 'titolo' => 'Cambiato', 'c_altri_referenti' => 'Prof. Gialli', 'j_tipo_iniziativa' => ['x']]], self::OPERATORE);
        $this->assertSame(1, $r['salvate']);
        $p = $this->repo()->perId($a);
        $this->assertSame('lavorazione', $p['stato']);
        $this->assertSame(1, (int) $p['nel_monitoraggio']);
        $this->assertSame('Una riga', $p['titolo']);
        $this->assertNull($p['j_tipo_iniziativa']);

        // una riga che non è nel file Excel, o che non esiste: errore sulla riga, e le altre non si salvano
        $r = $this->servizio()->aggiornaOperatoriInBlocco([$a => ['c_altri_referenti' => 'Altro'], $fuori => ['c_altri_referenti' => 'X'], 99999 => ['c_altri_referenti' => 'Y']], self::OPERATORE);
        $this->assertSame(0, $r['salvate']);
        $this->assertSame([$fuori, 99999], array_keys($r['errori']));
        $this->assertArrayHasKey('_', $r['errori'][$fuori]);
        $this->assertStringContainsString('non è più nel file Excel', $r['errori'][$fuori]['_']);
        $this->assertSame('La richiesta non esiste più.', $r['errori'][99999]['_']);
        $this->assertSame('Prof. Gialli', $this->repo()->perId($a)['c_altri_referenti'], 'A resta com\'era');
    }

    public function testDopoIlSalvataggioIlNumeroDaCompletareSiAggiornaEResteUgualeAlConteggioDelleRighe(): void
    {
        $a = $this->riga('A');
        $this->riga('B');
        $this->assertSame(2, $this->repo()->contaDaFare()['da_completare']);
        // A: tutte le colonne degli operatori compilate in un colpo solo
        $tutte = array_fill_keys(ColonneMonitoraggio::colonneOperatori(), 'SI');
        $tutte['c_altri_referenti'] = 'x';
        $tutte['v_n_partecipanti'] = '12';
        $r = $this->servizio()->aggiornaOperatoriInBlocco([$a => $tutte], self::OPERATORE);
        $this->assertSame([], $r['errori']);
        $this->assertSame(1, $r['salvate']);
        $this->assertSame(1, $this->repo()->contaDaFare()['da_completare'], 'resta da completare solo B');
    }
}
