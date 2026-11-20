<?php

declare(strict_types=1);

namespace Tests\Integration\Pubblicazioni;

use App\Amministrazione\ProcedureOnline;
use App\Core\Container;
use App\Core\Database;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Core\Schema\Definizioni;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Storage\Upload;
use App\Pubblicazioni\AllegatiPubblicazioni;
use App\Pubblicazioni\ImpostazioniPubblicazioni;
use App\Pubblicazioni\ServizioPubblicazioni;
use Tests\Doppi\ClientFinto;
use Tests\Doppi\FileDiProva;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/**
 * Impostazioni della Richiesta di pubblicazione (passo AM3), con il database: valori di partenza scritti dalla migrazione, chi può inviare (PTA sì,
 * studente no, docente sì), procedura chiusa, limiti cambiati rispettati dal server, ricevuta spenta e ProcedureOnline che legge stato e destinatari da qui.
 */
final class ImpostazioniRichiestaTest extends DatabaseDiProva
{
    private const ADMIN = ['id' => 1, 'nome' => 'Ada', 'cognome' => 'Admin', 'email' => 'ada@unical.it', 'ruolo_id' => 1, 'ruoli_secondari' => ''];
    private const STUDENTE = ['id' => 2, 'nome' => 'Luca', 'cognome' => 'Studente', 'email' => 'luca@unical.it', 'ruolo_id' => 3, 'ruoli_secondari' => '', 'matricola_studente' => '123'];
    private const DOCENTE = ['id' => 3, 'nome' => 'Maria', 'cognome' => 'Rossi', 'email' => 'maria.rossi@unical.it', 'ruolo_id' => 5, 'ruoli_secondari' => '6'];
    private const PTA = ['id' => 5, 'nome' => 'Gianni', 'cognome' => 'Verdi', 'email' => 'gianni.verdi@unical.it', 'ruolo_id' => 4, 'ruoli_secondari' => '7'];
    private const PERSONALE = ['id' => 6, 'nome' => 'Altro', 'cognome' => 'Personale', 'email' => 'altro@unical.it', 'ruolo_id' => 4, 'ruoli_secondari' => ''];

    private Container $c;
    private MailerFinto $mailer;
    private string $cartella;

    protected function tabelle(): array
    {
        return [
            'pubblicazioni' => Definizioni::tabelle()['pubblicazioni'],
            'pubblicazioni_allegati' => Definizioni::tabelle()['pubblicazioni_allegati'],
            'abilitazioni_ambito' => Definizioni::tabelle()['abilitazioni_ambito'],
            'ruoli' => 'CREATE TABLE ruoli (id INT PRIMARY KEY, nome VARCHAR(100))',
            'log_attivita' => 'CREATE TABLE log_attivita (id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT DEFAULT NULL, azione VARCHAR(255) NOT NULL, dettagli_json TEXT DEFAULT NULL, indirizzo_ip VARCHAR(45) DEFAULT NULL, data_ora DATETIME DEFAULT CURRENT_TIMESTAMP)',
            'impostazioni_sistema' => 'CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY)',
        ];
    }

    protected function setUp(): void
    {
        if (self::$conn !== null) {
            self::$conn->query('DROP TABLE IF EXISTS `pubblicazioni_allegati`'); // punta a pubblicazioni (chiave esterna): va tolta per prima
        }
        parent::setUp();
        $this->db->esegui("INSERT INTO ruoli (id, nome) VALUES (6, 'Docenti'), (7, 'Personale tecnico amministrativo')");
        // la riga c'è già quando arriva la migrazione, come sul server: le colonne pubblicazioni_* si aggiungono con lo stesso codice della v131
        $this->db->esegui('INSERT INTO impostazioni_sistema (id) VALUES (1)');
        foreach (Definizioni::colonne()['impostazioni_sistema'] as $col => $def) {
            if (!str_starts_with($col, 'pubblicazioni_')) {
                continue;
            }
            [$alter, $dopo] = is_array($def) ? $def : [$def, null];
            $this->assertTrue($this->db->comando('ALTER TABLE impostazioni_sistema ' . $alter), $col . ': ' . $this->db->ultimoErrore());
            if ($dopo !== null) {
                $this->assertTrue($this->db->comando($dopo), $col . ': ' . $this->db->ultimoErrore());
            }
        }
        $this->cartella = 'cache/prove_pubblicazioni_' . bin2hex(random_bytes(4)) . '/';
        $this->mailer = new MailerFinto();
        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Orologio::class, new OrologioFisso('2026-11-19 10:00:00'));
        $c->istanza(IndirizzoClient::class, new ClientFinto());
        $c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
        $c->istanza(Mailer::class, $this->mailer);
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

    private function imp(): ImpostazioniPubblicazioni
    {
        return $this->c->get(ImpostazioniPubblicazioni::class);
    }

    /** @param array<string, mixed> $cambi @return array<string, mixed> */
    private function dati(array $cambi = []): array
    {
        return array_merge([
            'data_evento' => '2026-12-05', 'url_docente' => 'https://www.unical.it/eventi/notte', 'titolo' => 'Notte dei ricercatori', 'sottotitolo' => 'Laboratori aperti',
            'descrizione' => '<p>Una serata di <b>laboratori</b>.</p>', 'modalita' => 'presenza', 'per_cittadinanza' => '1', 'note' => 'nessuna', 'tag' => 'DIBEST',
            'data_inizio_pubblicazione' => '2026-11-20',
        ], $cambi);
    }

    /**
     * @param array<string, mixed> $utente
     * @param array<string, mixed> $cambi
     * @return array{id: int, errori: array<string, string>}
     */
    private function invia(array $utente, array $cambi = [], ?array $file = null): array
    {
        return $this->servizio()->invia($utente, $this->dati($cambi), $file ?? ['allegati' => [FileDiProva::pdf()]]);
    }

    /** @param array<string, mixed> $dati */
    private function imposta(array $dati): void
    {
        $e = $this->imp()->aggiorna($dati);
        $this->assertSame([], $e['errori']);
    }

    private function nuove(): ImpostazioniPubblicazioni
    {
        return new ImpostazioniPubblicazioni($this->db); // senza la copia letta prima: quello che c'è davvero nel database
    }

    public function testLaMigrazioneScriveIValoriDiPartenza(): void
    {
        $this->assertSame(ImpostazioniPubblicazioni::INIZIALI, $this->nuove()->tutte(), 'nessun cambiamento per chi c\'è già, tranne il PTA tra chi può inviare');
        $riga = $this->db->riga('SELECT * FROM impostazioni_sistema WHERE id = 1');
        $this->assertSame('docenti,pta', $riga['pubblicazioni_chi_puo_inviare']);
        $this->assertSame(ImpostazioniPubblicazioni::INIZIALI['testo_introduttivo'], $riga['pubblicazioni_testo_introduttivo']);
        $this->assertSame(ImpostazioniPubblicazioni::INIZIALI['testo_dopo_invio'], $riga['pubblicazioni_testo_dopo_invio'], 'con l\'apostrofo');
    }

    public function testSenzaLeColonneValgonoIValoriDiPartenza(): void
    {
        $this->db->comando('DROP TABLE impostazioni_sistema');
        $this->db->comando("CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, pubblicazioni_email_notifica VARCHAR(500) NOT NULL DEFAULT 'comunicazione.dibest@unical.it')");
        $this->db->esegui('INSERT INTO impostazioni_sistema (id) VALUES (1)');
        $this->assertSame(ImpostazioniPubblicazioni::INIZIALI, $this->nuove()->tutte(), 'un caricamento a metà non rompe la pagina');
        $this->db->comando('DROP TABLE impostazioni_sistema');
        $this->assertSame(ImpostazioniPubblicazioni::INIZIALI, $this->nuove()->tutte());
    }

    public function testChiPuoInviare(): void
    {
        $s = $this->servizio();
        $this->assertTrue($s->puoInviare(self::PTA), 'il PTA, da ora');
        $this->assertTrue($s->puoInviare(self::DOCENTE));
        $this->assertTrue($s->puoInviare(self::ADMIN), 'gli amministratori sempre');
        $this->assertFalse($s->puoInviare(self::STUDENTE));
        $this->assertFalse($s->puoInviare(self::PERSONALE), 'altro personale senza il gruppo PTA: no');
        $this->assertFalse($s->puoInviare(null));

        $pta = $this->invia(self::PTA);
        $this->assertSame([], $pta['errori']);
        $this->assertSame('Verdi Gianni', $this->db->valore('SELECT richiedente FROM pubblicazioni WHERE id = ?', [$pta['id']]));
        $this->assertSame([], $this->invia(self::DOCENTE)['errori']);
        $no = $this->invia(self::STUDENTE);
        $this->assertSame(0, $no['id']);
        $this->assertSame('Le richieste di pubblicazione possono inviarle solo: Docenti, Personale tecnico amministrativo.', $no['errori']['_']);
        $this->assertSame(2, (int) $this->db->valore('SELECT COUNT(*) FROM pubblicazioni'));

        // solo i docenti, come prima di AM3: il PTA non può più
        $this->imposta(['chi_puo_inviare' => ['docenti']]);
        $this->assertFalse($this->servizio()->puoInviare(self::PTA));
        $this->assertTrue($this->servizio()->puoInviare(self::DOCENTE));
        $this->assertSame('docenti', $this->db->valore('SELECT pubblicazioni_chi_puo_inviare FROM impostazioni_sistema WHERE id = 1'));
    }

    public function testConLaProceduraChiusaNessunoInvia(): void
    {
        $this->imposta(['aperta' => false, 'messaggio_chiusa' => 'Chiuse fino al 7 gennaio.']);
        foreach (['docente' => self::DOCENTE, 'PTA' => self::PTA, 'amministratore' => self::ADMIN] as $chi => $u) {
            $e = $this->invia($u);
            $this->assertSame(0, $e['id'], $chi);
            $this->assertSame(['_' => 'Chiuse fino al 7 gennaio.'], $e['errori'], $chi);
            $this->assertTrue($this->servizio()->puoInviare($u), "$chi: resta tra chi può inviare, ma la procedura è chiusa");
        }
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM pubblicazioni'));
        $this->assertSame([], $this->mailer->inviate);
        $this->imposta(['aperta' => true]);
        $this->assertSame([], $this->invia(self::DOCENTE)['errori'], 'riaperta: si invia di nuovo');
    }

    public function testILimitiCambiatiLiRispettaIlServer(): void
    {
        $this->imposta(['max_sottotitolo' => '50', 'max_descrizione' => '20', 'max_allegati' => '1', 'max_immagini' => '1', 'giorni_minimi' => '3']);
        $e = $this->invia(self::DOCENTE, ['sottotitolo' => str_repeat('à', 51), 'descrizione' => '<b>' . str_repeat('x', 21) . '</b>', 'data_inizio_pubblicazione' => '2026-11-21'],
            ['allegati' => [FileDiProva::pdf('a.pdf'), FileDiProva::pdf('b.pdf')], 'immagini' => [FileDiProva::png('a.png'), FileDiProva::png('b.png')]]);
        $this->assertSame(0, $e['id']);
        $this->assertSame('Il sottotitolo ha 51 caratteri: al massimo 50, spazi inclusi.', $e['errori']['sottotitolo']);
        $this->assertSame('La descrizione ha 21 caratteri: al massimo 20, spazi inclusi.', $e['errori']['descrizione']);
        $this->assertSame('Puoi caricare un solo allegato.', $e['errori']['allegati']);
        $this->assertSame('Puoi caricare una sola immagine.', $e['errori']['immagini']);
        $this->assertSame('La data di inizio pubblicazione deve essere almeno 3 giorni dopo oggi: dal 22/11/2026 in poi.', $e['errori']['data_inizio_pubblicazione']);
        $ok = $this->invia(self::DOCENTE, ['sottotitolo' => str_repeat('à', 50), 'descrizione' => str_repeat('x', 20), 'data_inizio_pubblicazione' => '2026-11-22']);
        $this->assertSame([], $ok['errori'], 'al limite esatto si accetta');
        $this->assertSame($ok['errori'], $this->servizio()->valida($this->dati(['sottotitolo' => str_repeat('à', 50), 'descrizione' => 'x', 'data_inizio_pubblicazione' => '2026-11-22']), ['allegati' => [FileDiProva::pdf()]]), 'valida usa gli stessi limiti');

        // anche le righe a mano rispettano i limiti dei testi (ma non i giorni di anticipo)
        $m = $this->servizio()->creaManuale(['data_evento' => '2026-12-05', 'richiedente' => 'Bianchi Anna', 'titolo' => 'A mano', 'sottotitolo' => str_repeat('s', 51), 'data_inizio_pubblicazione' => '2026-11-19'], self::ADMIN);
        $this->assertSame(['sottotitolo'], array_keys($m['errori']));

        // allegati non obbligatori: si invia anche senza
        $breve = ['data_inizio_pubblicazione' => '2026-11-22', 'descrizione' => 'Breve'];
        $this->assertSame(['allegati'], array_keys($this->invia(self::DOCENTE, $breve, [])['errori']));
        $this->imposta(['allegati_obbligatori' => false]);
        $this->assertSame([], $this->invia(self::DOCENTE, $breve, [])['errori']);
    }

    public function testRicevutaSpentaUnaSolaEmail(): void
    {
        $this->assertSame([], $this->invia(self::PTA)['errori']);
        $this->assertSame(['comunicazione.dibest@unical.it', 'gianni.verdi@unical.it'], array_column($this->mailer->inviate, 'a'), 'accesa: avviso all\'ufficio e ricevuta a chi ha inviato (anche il PTA)');

        $this->mailer->inviate = [];
        $this->imposta(['ricevuta_al_richiedente' => false]);
        $this->assertSame([], $this->invia(self::PTA)['errori']);
        $this->assertSame(['comunicazione.dibest@unical.it'], array_column($this->mailer->inviate, 'a'), 'spenta: parte solo l\'avviso all\'ufficio');
    }

    public function testProcedureOnlineLeggeStatoEChiDalleImpostazioni(): void
    {
        $p = $this->c->get(ProcedureOnline::class)->elenco()[0];
        $this->assertTrue($p['aperta']);
        $this->assertSame('Docenti, Personale tecnico amministrativo', $p['chi']);
        $this->assertSame('amministrazione.php?tab=impostazioni', $p['impostazioni']);

        $this->imposta(['aperta' => false, 'chi_puo_inviare' => ['docenti', 'scuole']]);
        $p = $this->c->get(ProcedureOnline::class)->elenco()[0];
        $this->assertFalse($p['aperta']);
        $this->assertSame('Docenti, Scuole', $p['chi']);
        $this->assertTrue($this->c->get(ProcedureOnline::class)->puoInviare(ProcedureOnline::PUBBLICAZIONE, self::DOCENTE), 'chi può inviare non dipende dall\'apertura');
        $this->assertFalse($this->c->get(ProcedureOnline::class)->puoInviare(ProcedureOnline::PUBBLICAZIONE, self::PTA));
    }

    public function testSalvataggioTuttoONiente(): void
    {
        $e = $this->imp()->aggiorna(['max_sottotitolo' => '40', 'indirizzi' => 'non-valido', 'chi_puo_inviare' => []]);
        $this->assertSame(['indirizzi', 'chi_puo_inviare'], array_keys($e['errori']));
        $this->assertSame(ImpostazioniPubblicazioni::INIZIALI, $this->nuove()->tutte(), 'con un errore non si salva niente');

        $e = $this->imp()->aggiorna(['max_sottotitolo' => '40', 'indirizzi' => 'comunicazione.dibest@unical.it', 'testo_dopo_invio' => '<p>Grazie, <i>a presto</i></p>']);
        $this->assertSame([], $e['errori']);
        $this->assertSame(['max_sottotitolo', 'testo_dopo_invio'], array_keys($e['cambi']), 'nel registro solo quello che è cambiato');
        $this->assertSame(['da' => 75, 'a' => 40], $e['cambi']['max_sottotitolo']);
        $this->assertSame(40, $this->nuove()->limiti()['max_sottotitolo']);
        $this->assertSame([], $this->imp()->aggiorna(['max_sottotitolo' => '40'])['cambi'], 'niente di nuovo: nessun cambio');

        // il salvataggio dei soli indirizzi, come prima
        $this->assertStringContainsString('non-valido', (string) $this->imp()->salva('a@unical.it, non-valido'));
        $this->assertNull($this->imp()->salva('A@unical.it; b@unical.it'));
        $this->assertSame(['a@unical.it', 'b@unical.it'], $this->nuove()->indirizzi());
        $this->assertSame('a@unical.it, b@unical.it', $this->db->valore('SELECT pubblicazioni_email_notifica FROM impostazioni_sistema WHERE id = 1'));
    }
}
