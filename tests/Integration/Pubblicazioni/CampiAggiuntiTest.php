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
use App\Pubblicazioni\Completamento;
use App\Pubblicazioni\ConfigurazioneCampi;
use App\Pubblicazioni\EsportazioneMonitoraggio;
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
 * Campi nuovi del form e colonne nuove degli operatori (passo AM5), con il database:
 * (a) un campo nuovo obbligatorio si rifiuta vuoto, si salva in campi_extra_json e nell'export sta nel posto giusto;
 * (b) una colonna nuova si compila nella scheda, lascia traccia nel registro e compare nell'export;
 * (c) eliminare con valori è rifiutato, nascondere si può, il tipo di un campo con valori non cambia; senza valori si elimina e le chiavi non si riusano.
 */
final class CampiAggiuntiTest extends DatabaseDiProva
{
    private const DOCENTE = ['id' => 3, 'nome' => 'Maria', 'cognome' => 'Rossi', 'email' => 'maria.rossi@unical.it', 'ruolo_id' => 5, 'ruoli_secondari' => '6'];
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
        $c->istanza(Orologio::class, new OrologioFisso('2026-11-19 10:00:00'));
        $c->istanza(IndirizzoClient::class, new ClientFinto());
        $c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
        $c->istanza(Mailer::class, new MailerFinto());
        $c->istanza(ClientApiAteneo::class, new AnagrafiClientApiFinto()); // serve a LetturaRisposte (anagrafe dei corsi), che i campi nuovi usano per i controlli di MyForms
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
        // il database di prova è condiviso tra le classi: le definizioni nuove non devono restare per gli altri test delle Pubblicazioni
        if (self::$conn !== null) {
            self::$conn->query('DROP TABLE IF EXISTS `pubblicazioni_campi`');
            self::$conn->query('DROP TABLE IF EXISTS `pubblicazioni_colonne`');
        }
    }

    private function cfg(): ConfigurazioneCampi
    {
        return $this->c->get(ConfigurazioneCampi::class);
    }

    private function agg(): CampiAggiunti
    {
        return $this->c->get(CampiAggiunti::class);
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
            'data_evento' => '2026-12-05', 'url_docente' => 'https://www.unical.it/eventi/notte', 'altri_link_docente' => '',
            'titolo' => 'Notte dei ricercatori', 'sottotitolo' => 'Laboratori aperti', 'descrizione' => '<p>Una serata di <b>laboratori</b>.</p>',
            'modalita' => 'presenza', 'per_cittadinanza' => '1', 'note' => 'nessuna', 'tag' => 'DIBEST, notizie', 'data_inizio_pubblicazione' => '2026-11-20',
        ], $cambi);
    }

    /**
     * Quello che invia la scheda «Campi nuovi»: le righe del costruttore di MyForms e le impostazioni in più, una voce per riga.
     *
     * @param list<array<string, mixed>> $righe etichetta, tipo, opzioni, obbl, aiuto, chiave, passo, vis, excel, intest
     * @return array<string, list<string>>
     */
    private function postCampi(array $righe): array
    {
        $post = array_fill_keys(['cx_etichetta', 'cx_tipo', 'cx_opzioni', 'cx_obbl', 'cx_aiuto', 'cx_chiave', 'cx_passo', 'cx_vis', 'cx_excel', 'cx_intest'], []);
        foreach ($righe as $r) {
            $r += ['tipo' => 'text', 'opzioni' => '', 'obbl' => '0', 'aiuto' => '', 'chiave' => '', 'passo' => '3', 'vis' => '1', 'excel' => '0', 'intest' => ''];
            foreach (['etichetta', 'tipo', 'opzioni', 'obbl', 'aiuto', 'chiave', 'passo', 'vis', 'excel', 'intest'] as $k) {
                $post['cx_' . $k][] = (string) $r[$k];
            }
        }

        return $post;
    }

    /** @return array<string, mixed> */
    private function colonnaNuova(string $id, array $cambi = []): array
    {
        return array_merge(['intestazione' => 'Esito social', 'a_cura_di' => 'Ufficio comunicazione', 'colore' => '#FF0000', 'tipo' => 'si_no', 'dopo' => 'titolo', 'visibile' => '1'], $cambi);
    }

    /** Una riga già nel file Excel; con $conOperatori tutte le colonne di sempre degli operatori sono compilate (manca solo quello che si prova). @param array<string, mixed> $cambi */
    private function rigaNelFile(array $cambi = [], bool $conOperatori = false): int
    {
        $r = ['stato' => 'pubblicata', 'nel_monitoraggio' => 1, 'data_evento' => '2026-12-05', 'richiedente' => 'Rossi Maria', 'titolo' => 'Iniziativa', 'sottotitolo' => 'Sotto', 'modalita' => 'mista', 'utente_id' => 3];
        if ($conOperatori) {
            foreach (\App\Pubblicazioni\ColonneMonitoraggio::colonneOperatori() as $k) {
                $r[$k] = 'x';
            }
        }

        return $this->repo()->inserisci(array_merge($r, $cambi));
    }

    // ───── (a) campo nuovo obbligatorio ─────

    public function testUnCampoNuovoObbligatorioSiRifiutaVuotoSiSalvaENellExportStaAlPostoGiusto(): void
    {
        $esito = $this->agg()->salvaCampi($this->postCampi([[
            'etichetta' => 'Livello dell\'evento', 'tipo' => 'radio', 'opzioni' => 'Base, Avanzato', 'obbl' => '1', 'passo' => '3', 'excel' => '1', 'intest' => 'Livello evento',
        ]]));
        $this->assertSame([], $esito['errori']);
        $this->assertCount(1, $esito['cambi']);
        $this->assertSame(['x_1'], array_column($this->db->righe('SELECT chiave FROM pubblicazioni_campi'), 'chiave'));

        $file = ['locandina' => [FileDiProva::pdf()]];
        // vuoto, o con una scelta che non è tra quelle proposte: rifiutato
        $this->assertArrayHasKey('x_1', $this->servizio()->invia(self::DOCENTE, $this->dati(), $file)['errori'], 'vuoto');
        $storto = $this->servizio()->invia(self::DOCENTE, $this->dati(['x_1' => 'Esperto']), ['locandina' => [FileDiProva::pdf()]]);
        $this->assertArrayHasKey('x_1', $storto['errori'], 'una scelta che non c\'è');
        $this->assertStringContainsString('Livello dell\'evento', $storto['errori']['x_1']);
        $this->assertSame([], $this->repo()->elenco(), 'niente salvato');

        $esito = $this->servizio()->invia(self::DOCENTE, $this->dati(['x_1' => 'Avanzato']), ['locandina' => [FileDiProva::pdf()]]);
        $this->assertSame([], $esito['errori']);
        $json = (string) $this->db->valore('SELECT campi_extra_json FROM pubblicazioni WHERE id = ?', [$esito['id']]);
        $this->assertSame(['x_1' => 'Avanzato'], json_decode($json, true));
        $this->assertSame('Avanzato', $this->repo()->perId($esito['id'])['x_1'], 'chi legge la riga trova la chiave x_1 come le altre');

        // nell'export: la colonna sta subito dopo quella della descrizione (l'ultimo campo del passo 3), con la sua intestazione
        $this->assertTrue($this->servizio()->inviaAlMonitoraggio($esito['id'], self::OPERATORE)->riuscito);
        $cfg = $this->cfg();
        $tab = EsportazioneMonitoraggio::tabella($this->repo()->elenco(), false, $cfg);
        $chiavi = $cfg->chiaviExcel();
        $i = (int) array_search('x_1', $chiavi, true);
        $this->assertSame((int) array_search('descrizione', $chiavi, true) + 1, $i);
        $this->assertSame('Livello evento', $tab['intestazioni'][$i]);
        $this->assertSame('Avanzato', $tab['righe'][0][$i]);
        $this->assertCount(count($tab['intestazioni']), $tab['righe'][0]);
    }

    public function testUnCampoNuovoDiTipoDataEsceConLaDataItalianaENellEmail(): void
    {
        $this->agg()->salvaCampi($this->postCampi([['etichetta' => 'Data limite iscrizioni', 'tipo' => 'date', 'passo' => '2', 'excel' => '1']]));
        $storta = $this->servizio()->invia(self::DOCENTE, $this->dati(['x_1' => 'non-una-data']), ['locandina' => [FileDiProva::pdf()]]);
        $this->assertNull($this->db->valore('SELECT campi_extra_json FROM pubblicazioni WHERE id = ?', [$storta['id']]), 'come in MyForms, una data non valida non si salva');
        $esito = $this->servizio()->invia(self::DOCENTE, $this->dati(['x_1' => '2026-12-01']), ['locandina' => [FileDiProva::pdf()]]);
        $this->assertSame([], $esito['errori']);
        $this->servizio()->inviaAlMonitoraggio($esito['id'], self::OPERATORE);
        $tab = EsportazioneMonitoraggio::tabella($this->repo()->elenco(), false, $this->cfg());
        $this->assertContains('01/12/2026', $tab['righe'][0]);
        $riepilogo = \App\Pubblicazioni\RiepilogoRichiesta::righe($this->repo()->perId($esito['id']), [], $this->cfg());
        $this->assertContains(['Data limite iscrizioni', '01/12/2026'], $riepilogo);
    }

    // ───── (b) colonna nuova degli operatori ─────

    public function testUnaColonnaNuovaSiCompilaNellaSchedaLasciaTracciaNelRegistroENellExport(): void
    {
        $esito = $this->agg()->salvaColonne(['ycol' => ['nuova_1' => $this->colonnaNuova('nuova_1')]]);
        $this->assertSame([], $esito['errori']);
        $this->assertCount(1, $esito['cambi']);
        $this->assertSame('y_1', $this->db->valore('SELECT chiave FROM pubblicazioni_colonne'));

        $id = $this->rigaNelFile(['titolo' => 'Da aggiornare'], true);
        $cfg = $this->cfg();
        $this->assertContains('y_1', $cfg->colonneOperatori());
        $this->assertSame(['y_1'], Completamento::mancanti($this->repo()->perId($id), $cfg), 'vuota e visibile: da completare');
        $this->assertSame(1, $this->repo()->contaDaFare()['da_completare']);

        // si compila come le altre; il tipo SI/NO si controlla
        $this->assertFalse($this->servizio()->aggiornaOperatori($id, ['y_1' => 'forse'], self::OPERATORE)->riuscito);
        $this->assertTrue($this->servizio()->aggiornaOperatori($id, ['y_1' => 'si'], self::OPERATORE)->riuscito);
        $p = $this->repo()->perId($id);
        $this->assertSame('SI', $p['y_1']);
        $this->assertSame(['y_1' => 'SI'], json_decode((string) $this->db->valore('SELECT colonne_extra_json FROM pubblicazioni WHERE id = ?', [$id]), true));
        $this->assertSame([], Completamento::mancanti($p, $cfg));
        $this->assertSame(0, $this->repo()->contaDaFare()['da_completare'], 'il conteggio nel database e quello della riga coincidono');

        // nel registro, con l'intestazione della colonna
        $righe = [];
        foreach ($this->c->get(RegistroModifiche::class)->perRichiesta($id) as $voce) {
            $righe = array_merge($righe, $voce['righe']);
        }
        $this->assertContains('Esito social: (vuoto) → «SI»', $righe);

        // nell'export subito dopo la colonna del titolo
        $tab = EsportazioneMonitoraggio::tabella($this->repo()->elenco(), false, $cfg);
        $chiavi = $cfg->chiaviExcel();
        $i = (int) array_search('y_1', $chiavi, true);
        $this->assertSame((int) array_search('titolo', $chiavi, true) + 1, $i);
        $this->assertStringContainsString('Esito social', $tab['intestazioni'][$i]);
        $this->assertSame('SI', $tab['righe'][0][$i]);

        // un valore tolto toglie la chiave dal JSON
        $this->assertTrue($this->servizio()->aggiornaOperatori($id, ['y_1' => ''], self::OPERATORE)->riuscito);
        $this->assertNull($this->db->valore('SELECT colonne_extra_json FROM pubblicazioni WHERE id = ?', [$id]));
        // e una colonna eliminata dopo, nel registro, si legge come «Colonna eliminata»
        $this->assertSame('Colonna eliminata', RegistroModifiche::nomeColonna('y_9', $cfg));
    }

    // ───── (c) eliminare, nascondere, cambiare il tipo ─────

    public function testEliminareConValoriERifiutatoNascondereSiPuoIlTipoNonCambiaEdIChiaviNonSiRiusano(): void
    {
        $this->agg()->salvaCampi($this->postCampi([['etichetta' => 'Sede', 'tipo' => 'text', 'passo' => '2', 'excel' => '1']]));
        $esito = $this->servizio()->invia(self::DOCENTE, $this->dati(['x_1' => 'Cosenza']), ['locandina' => [FileDiProva::pdf()]]);
        $this->assertSame([], $esito['errori']);
        $this->assertSame(1, $this->agg()->richiesteConValori()['campi']['x_1']);

        $riga = ['etichetta' => 'Sede', 'chiave' => 'x_1', 'passo' => '2', 'excel' => '1'];
        // eliminare (la riga non c'è più): rifiutato, e il campo resta
        $e = $this->agg()->salvaCampi($this->postCampi([]));
        $this->assertArrayHasKey('elimina_x_1', $e['errori']);
        $this->assertStringContainsString('1 richiesta', $e['errori']['elimina_x_1']);
        $this->assertCount(1, $this->cfg()->campiExtra());
        // cambiare il tipo: rifiutato
        $e = $this->agg()->salvaCampi($this->postCampi([$riga + ['tipo' => 'number']]));
        $this->assertNotSame([], $e['errori']);
        $this->assertSame('text', $this->cfg()->campiExtra()[0]['tipo']);
        // nascondere: si può, il valore resta nella richiesta e la colonna esce dal file
        $e = $this->agg()->salvaCampi($this->postCampi([$riga + ['vis' => '0']]));
        $this->assertSame([], $e['errori']);
        $this->assertFalse($this->cfg()->campiExtra()[0]['visibile']);
        $this->assertSame('Cosenza', $this->repo()->perId($esito['id'])['x_1']);
        $this->assertNotContains('x_1', $this->cfg()->chiaviExcel());
        $this->assertArrayNotHasKey('x_1', $this->servizio()->valida($this->dati(), ['locandina' => [FileDiProva::pdf()]]), 'un campo nascosto non si chiede');

        // senza valori si elimina; la chiave dopo non si riusa
        $this->agg()->salvaCampi($this->postCampi([$riga + ['vis' => '0'], ['etichetta' => 'Altro', 'passo' => '3']]));
        $this->assertSame(['x_1', 'x_2'], array_column($this->cfg()->campiExtra(), 'chiave'));
        $e = $this->agg()->salvaCampi($this->postCampi([$riga + ['vis' => '0']]));
        $this->assertSame([], $e['errori'], 'x_2 non ha valori: si elimina');
        $this->assertSame(['x_1'], array_column($this->cfg()->campiExtra(), 'chiave'));
        $this->agg()->salvaCampi($this->postCampi([$riga + ['vis' => '0'], ['etichetta' => 'Terzo', 'passo' => '3']]));
        $this->assertSame(['x_1', 'x_3'], array_column($this->cfg()->campiExtra(), 'chiave'), 'x_2 è stata eliminata e non torna');

        // lo stesso per le colonne: con valori solo nascondere, senza valori si elimina
        $this->agg()->salvaColonne(['ycol' => ['nuova_1' => $this->colonnaNuova('nuova_1'), 'nuova_2' => $this->colonnaNuova('nuova_2', ['intestazione' => 'Seconda'])]]);
        $this->assertSame(['y_1', 'y_2'], array_column($this->cfg()->colonneNuove(), 'chiave'));
        $id = $this->rigaNelFile();
        $this->assertTrue($this->servizio()->aggiornaOperatori($id, ['y_1' => 'SI'], self::OPERATORE)->riuscito);
        $this->assertFalse($this->agg()->eliminaColonna('y_1')->riuscito, 'ha un valore');
        $this->assertCount(2, $this->cfg()->colonneNuove());
        $this->assertTrue($this->agg()->eliminaColonna('y_2')->riuscito, 'senza valori');
        $this->assertSame(['y_1'], array_column($this->cfg()->colonneNuove(), 'chiave'));
        $this->agg()->salvaColonne(['ycol' => ['y_1' => $this->colonnaNuova('y_1', ['visibile' => '0']), 'nuova_1' => $this->colonnaNuova('nuova_1', ['intestazione' => 'Terza'])]]);
        $this->assertSame(['y_1', 'y_3'], array_column($this->cfg()->colonneNuove(), 'chiave'), 'y_2 non torna');
        $this->assertNotContains('y_1', $this->cfg()->chiaviExcel(), 'nascosta: fuori dal file');
        $this->assertSame('SI', $this->repo()->perId($id)['y_1'], 'ma il valore resta');
    }
}
