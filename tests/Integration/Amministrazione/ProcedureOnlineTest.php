<?php

declare(strict_types=1);

namespace Tests\Integration\Amministrazione;

use App\Amministrazione\ProcedureOnline;
use App\Core\Container;
use App\Core\Database;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Core\Schema\Definizioni;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use Tests\Doppi\ClientFinto;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/**
 * Le procedure online della Modulistica amministrativa (passi AM2 e AM3): la Richiesta di pubblicazione è fissa in cima (stato e destinatari dalle
 * impostazioni, di partenza aperta e per docenti e PTA, con la pagina pubblica e il collegamento alle richieste) e chi può inviarla lo decide
 * ServizioPubblicazioni::puoInviare, la stessa regola della pagina della richiesta.
 */
final class ProcedureOnlineTest extends DatabaseDiProva
{
    private const ADMIN = ['id' => 1, 'nome' => 'Ada', 'cognome' => 'Admin', 'ruolo_id' => 1, 'ruoli_secondari' => ''];
    private const DOCENTE = ['id' => 3, 'nome' => 'Maria', 'cognome' => 'Rossi', 'email' => 'maria.rossi@unical.it', 'ruolo_id' => 5, 'ruoli_secondari' => '6'];
    private const STUDENTE = ['id' => 2, 'nome' => 'Luca', 'cognome' => 'Studente', 'ruolo_id' => 3, 'ruoli_secondari' => '', 'matricola_studente' => '123'];
    private const OPERATORE = ['id' => 4, 'nome' => 'Floriana', 'cognome' => 'Mantovano', 'ruolo_id' => 4, 'ruoli_secondari' => ''];

    private Container $c;

    protected function tabelle(): array
    {
        return [
            'pubblicazioni' => Definizioni::tabelle()['pubblicazioni'],
            'pubblicazioni_allegati' => Definizioni::tabelle()['pubblicazioni_allegati'],
            'abilitazioni_ambito' => Definizioni::tabelle()['abilitazioni_ambito'],
            'ruoli' => 'CREATE TABLE ruoli (id INT PRIMARY KEY, nome VARCHAR(100))',
        ];
    }

    protected function setUp(): void
    {
        if (self::$conn !== null) {
            self::$conn->query('DROP TABLE IF EXISTS `pubblicazioni_allegati`'); // punta a pubblicazioni (chiave esterna): va tolta per prima
        }
        parent::setUp();
        $this->db->esegui("INSERT INTO ruoli (id, nome) VALUES (6, 'Docenti'), (7, 'Personale tecnico amministrativo')"); // i profili «docenti» e «pta» si riconoscono dal ruolo (anche secondario)
        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Orologio::class, new OrologioFisso('2026-11-19 10:00:00'));
        $c->istanza(IndirizzoClient::class, new ClientFinto());
        $c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
        $c->istanza(Mailer::class, new MailerFinto());
        $this->c = $c;
    }

    public function testLaRichiestaDiPubblicazioneECitataInCima(): void
    {
        $elenco = $this->c->get(ProcedureOnline::class)->elenco();
        $this->assertCount(1, $elenco);
        $p = $elenco[0];
        $this->assertSame(ProcedureOnline::PUBBLICAZIONE, $p['chiave']);
        $this->assertSame('richiesta_pubblicazione.php', $p['pagina']);
        // qui la tabella delle impostazioni non c'è: valgono i valori di partenza (le impostazioni cambiate le prova ImpostazioniRichiestaTest)
        $this->assertTrue($p['aperta'], 'di partenza aperta (l\'interruttore è nelle impostazioni)');
        $this->assertSame('Docenti, Personale tecnico amministrativo', $p['chi'], 'di partenza docenti e PTA, con le etichette di Pubblico');
        $this->assertSame('amministrazione.php?tab=impostazioni', $p['impostazioni']);
        $this->assertSame('pubblicazioni.php?vista=arrivate', $p['gestione']);
        $this->assertSame('Richieste di pubblicazione', $p['gestione_nome']);
        $this->assertNotSame('', $p['titolo']);
        $this->assertNotSame('', $p['descrizione']);
    }

    public function testChiPuoInviareELaRegolaDellaRichiesta(): void
    {
        $o = $this->c->get(ProcedureOnline::class);
        $s = $this->c->get(\App\Pubblicazioni\ServizioPubblicazioni::class);
        foreach (['docente' => [self::DOCENTE, true], 'amministratore' => [self::ADMIN, true], 'studente' => [self::STUDENTE, false], 'personale tecnico amministrativo' => [['id' => 5, 'ruolo_id' => 4, 'ruoli_secondari' => '7'], true], 'altro personale' => [self::OPERATORE, false], 'chi non è entrato' => [null, false]] as $chi => [$utente, $atteso]) {
            $this->assertSame($atteso, $o->puoInviare(ProcedureOnline::PUBBLICAZIONE, $utente), $chi);
            $this->assertSame($s->puoInviare($utente), $o->puoInviare(ProcedureOnline::PUBBLICAZIONE, $utente), "$chi: la stessa regola di ServizioPubblicazioni::puoInviare");
        }
        $this->assertFalse($o->puoInviare('procedura-che-non-esiste', self::ADMIN));
    }
}
