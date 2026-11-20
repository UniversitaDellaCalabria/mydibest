<?php

declare(strict_types=1);

namespace Tests\Integration\Amministrazione;

use App\Amministrazione\CoseDaFareAmministrazione;
use App\Amministrazione\PermessiAmministrazione;
use App\Core\Container;
use App\Core\Database;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Core\Schema\Definizioni;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Pubblicazioni\ColonneMonitoraggio;
use App\Pubblicazioni\Completamento;
use App\Pubblicazioni\PubblicazioniRepository;
use App\Pubblicazioni\ServizioPubblicazioni;
use Tests\Doppi\ClientFinto;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/**
 * Il modulo Amministrazione (passo AM1): chi entra (amministratore, solo «Richieste di pubblicazione», tutto il modulo, nessuno), la regola
 * unica di ServizioPubblicazioni::puoGestire, le schede, e i numeri di «Cosa devo fare».
 */
final class ModuloAmministrazioneTest extends DatabaseDiProva
{
    private const ADMIN = ['id' => 1, 'nome' => 'Ada', 'cognome' => 'Admin', 'ruolo_id' => 1, 'ruoli_secondari' => ''];
    /** Ha solo «Amministrazione · Richieste di pubblicazione» (modulo_pubblicazioni) */
    private const SOLO_RICHIESTE = ['id' => 4, 'nome' => 'Floriana', 'cognome' => 'Mantovano', 'ruolo_id' => 4, 'ruoli_secondari' => ''];
    /** Ha tutto il modulo (modulo_amministrazione) */
    private const TUTTO_IL_MODULO = ['id' => 7, 'nome' => 'Anna', 'cognome' => 'Amministrativa', 'ruolo_id' => 4, 'ruoli_secondari' => ''];
    /** Nessuna abilitazione */
    private const NESSUNO = ['id' => 5, 'nome' => 'Sara', 'cognome' => 'Senzamodulo', 'ruolo_id' => 4, 'ruoli_secondari' => ''];
    /** Ha solo MyForms */
    private const SOLO_FORMS = ['id' => 6, 'nome' => 'Franco', 'cognome' => 'Forms', 'ruolo_id' => 4, 'ruoli_secondari' => ''];

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
        $this->db->esegui("INSERT INTO abilitazioni_ambito (utente_id, tipo, pagina_id) VALUES (4, 'modulo_pubblicazioni', 0), (7, 'modulo_amministrazione', 0), (6, 'modulo_forms', 0)");
        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Orologio::class, new OrologioFisso('2026-11-19 10:00:00'));
        $c->istanza(IndirizzoClient::class, new ClientFinto());
        $c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
        $c->istanza(Mailer::class, new MailerFinto());
        $this->c = $c;
    }

    private function permessi(): PermessiAmministrazione
    {
        return $this->c->get(PermessiAmministrazione::class);
    }

    public function testChiEntraNelModulo(): void
    {
        $p = $this->permessi();
        // [tutto, richieste, entra]
        $atteso = [
            'admin' => [self::ADMIN, true, true, true],
            'solo richieste' => [self::SOLO_RICHIESTE, false, true, true],
            'tutto il modulo' => [self::TUTTO_IL_MODULO, true, true, true],
            'nessuno' => [self::NESSUNO, false, false, false],
            'solo MyForms' => [self::SOLO_FORMS, false, false, false],
        ];
        foreach ($atteso as $chi => [$utente, $tutto, $richieste, $entra]) {
            $this->assertSame($tutto, $p->puoTutto($utente), "$chi: tutto il modulo");
            $this->assertSame($richieste, $p->puoPubblicazioni($utente), "$chi: richieste di pubblicazione");
            $this->assertSame($entra, $p->puoEntrare($utente), "$chi: entra nel modulo");
        }
        $this->assertFalse($p->puoTutto(null));
        $this->assertFalse($p->puoPubblicazioni(null));
        $this->assertFalse($p->puoEntrare(null));
        // il modulo vale solo come perimetro «intero» (pagina_id 0): lo stesso nome legato a un'area non basta
        $this->db->esegui("INSERT INTO abilitazioni_ambito (utente_id, tipo, pagina_id) VALUES (5, 'modulo_amministrazione', 12), (5, 'modulo_pubblicazioni', 12)");
        $this->assertFalse($this->permessi()->puoEntrare(self::NESSUNO));
    }

    public function testLeRichiesteDiPubblicazioneSeguonoLaStessaRegola(): void
    {
        $s = $this->c->get(ServizioPubblicazioni::class);
        $p = $this->permessi();
        foreach ([self::ADMIN, self::SOLO_RICHIESTE, self::TUTTO_IL_MODULO, self::NESSUNO, self::SOLO_FORMS, null] as $utente) {
            $this->assertSame($p->puoPubblicazioni($utente), $s->puoGestire($utente), 'ServizioPubblicazioni::puoGestire = PermessiAmministrazione::puoPubblicazioni');
        }
        $this->assertTrue($s->puoGestire(self::TUTTO_IL_MODULO), 'chi ha tutto il modulo gestisce anche le richieste');
    }

    public function testChiHaSoloLeRichiesteVedeLaHomeMaNonLeSchedeCheVerranno(): void
    {
        $p = $this->permessi();
        // «home» (Cosa devo fare) si apre a chi entra; ogni altra scheda (Modulistica, Impostazioni… dei prossimi passi) solo a chi ha tutto il modulo
        $this->assertSame(['home'], PermessiAmministrazione::SCHEDE_PARZIALE);
        $this->assertTrue($p->puoScheda(self::SOLO_RICHIESTE, 'home'));
        foreach (['modulistica', 'impostazioni', 'campi', 'una_scheda_finta'] as $scheda) {
            $this->assertFalse($p->puoScheda(self::SOLO_RICHIESTE, $scheda), "solo richieste: non apre la scheda $scheda");
            $this->assertTrue($p->puoScheda(self::TUTTO_IL_MODULO, $scheda), "tutto il modulo: apre la scheda $scheda");
            $this->assertTrue($p->puoScheda(self::ADMIN, $scheda), "admin: apre la scheda $scheda");
            $this->assertFalse($p->puoScheda(self::NESSUNO, $scheda), "nessuno: non apre la scheda $scheda");
        }
        $this->assertFalse($p->puoScheda(self::NESSUNO, 'home'), 'senza abilitazione non si apre nemmeno la home');
        $this->assertFalse($p->puoScheda(null, 'home'));
    }

    /** @return array<string, mixed> */
    private function riga(string $stato, int $nelFile, bool $completa = false, string $titolo = 'Iniziativa'): array
    {
        $r = ['richiedente' => 'Rossi Maria', 'stato' => $stato, 'nel_monitoraggio' => $nelFile, 'data_evento' => '2026-12-05', 'titolo' => $titolo];
        if ($completa) {
            foreach (ColonneMonitoraggio::tutte() as $col) {
                if ($col['operatori']) {
                    $r[$col['chiave']] = 'x';
                }
            }
        }

        return $r;
    }

    public function testICoseDaFareContanoComeLaPaginaDelleRichieste(): void
    {
        $repo = $this->c->get(PubblicazioniRepository::class);
        $cose = $this->c->get(CoseDaFareAmministrazione::class);
        $this->assertSame(['ricevute' => 0, 'lavorazione' => 0, 'da_completare' => 0, 'giorni' => 0], $cose->pubblicazioni(), 'niente richieste: tutto a zero');

        $repo->inserisci($this->riga('ricevuta', 0, false, 'Nuova 1'));
        $repo->inserisci($this->riga('ricevuta', 0, false, 'Nuova 2'));
        $repo->inserisci($this->riga('lavorazione', 0, false, 'In corso'));
        $repo->inserisci($this->riga('respinta', 0, false, 'Respinta'));    // non conta
        $repo->inserisci($this->riga('pubblicata', 0, false, 'Pubblicata fuori dal file')); // non conta
        $repo->inserisci($this->riga('ricevuta', 1, false, 'Nel file, colonne vuote'));      // nel file: conta solo come «da completare»
        $repo->inserisci($this->riga('lavorazione', 1, false, 'Nel file in lavorazione'));  // idem
        $repo->inserisci($this->riga('pubblicata', 1, true, 'Nel file, completa'));          // completa: non conta

        $this->assertSame(['ricevute' => 2, 'lavorazione' => 1, 'da_completare' => 2, 'giorni' => 0], $cose->pubblicazioni());
    }

    /** Il conteggio nel database (COUNT/SUM) e quello fatto leggendo le righe con Completamento devono dare gli stessi numeri. */
    private function contaLeggendoLeRighe(PubblicazioniRepository $repo): array
    {
        $x = ['ricevute' => 0, 'lavorazione' => 0, 'da_completare' => 0];
        foreach ($repo->elenco() as $r) {
            if ((int) $r['nel_monitoraggio'] === 1) {
                if (Completamento::numeroMancanti($r) > 0) {
                    ++$x['da_completare'];
                }
            } elseif ($r['stato'] === 'ricevuta') {
                ++$x['ricevute'];
            } elseif ($r['stato'] === 'lavorazione') {
                ++$x['lavorazione'];
            }
        }

        return $x;
    }

    public function testIlConteggioNelDatabaseCoincideConQuelloFattoSulleRighe(): void
    {
        $repo = $this->c->get(PubblicazioniRepository::class);
        $cose = $this->c->get(CoseDaFareAmministrazione::class);
        $primaColonna = ColonneMonitoraggio::colonneOperatori()[0];
        $repo->inserisci($this->riga('ricevuta', 0, false, 'Da guardare'));
        $repo->inserisci($this->riga('lavorazione', 0, false, 'In lavorazione'));
        $repo->inserisci($this->riga('respinta', 0, false, 'Respinta'));
        $repo->inserisci($this->riga('pubblicata', 1, true, 'Completa'));
        $repo->inserisci($this->riga('pubblicata', 1, false, 'Colonne vuote'));
        // una colonna con soli spazi e a capo conta come vuota, come per Completamento (trim)
        $solo_spazi = $this->riga('pubblicata', 1, true, 'Colonna con soli spazi');
        $solo_spazi[$primaColonna] = " \t \n ";
        $repo->inserisci($solo_spazi);
        // NULL: colonna mai compilata
        $con_null = $this->riga('lavorazione', 1, true, 'Colonna a NULL');
        unset($con_null[$primaColonna]);
        $repo->inserisci($con_null);

        $atteso = $this->contaLeggendoLeRighe($repo);
        $this->assertSame(['ricevute' => 1, 'lavorazione' => 1, 'da_completare' => 3], $atteso, 'controllo del controllo');
        $nelDb = $cose->pubblicazioni();
        $this->assertSame($atteso, ['ricevute' => $nelDb['ricevute'], 'lavorazione' => $nelDb['lavorazione'], 'da_completare' => $nelDb['da_completare']]);
    }

    public function testIGiorniDellaPiuVecchiaContanoSoloLeRichiesteDaGuardare(): void
    {
        $repo = $this->c->get(PubblicazioniRepository::class);
        $cose = $this->c->get(CoseDaFareAmministrazione::class);
        $giorni = fn (string $titolo, int $giorniFa) => $this->db->esegui('UPDATE pubblicazioni SET inviata_il = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE titolo = ?', [$giorniFa, $titolo]);

        $repo->inserisci($this->riga('ricevuta', 0, false, 'Arrivata da 2 giorni'));
        $repo->inserisci($this->riga('ricevuta', 0, false, 'Arrivata da 9 giorni'));
        // più vecchie, ma non da guardare: in lavorazione, respinta, già nel file
        $repo->inserisci($this->riga('lavorazione', 0, false, 'Vecchia in lavorazione'));
        $repo->inserisci($this->riga('respinta', 0, false, 'Vecchia respinta'));
        $repo->inserisci($this->riga('ricevuta', 1, false, 'Vecchia nel file'));
        $giorni('Arrivata da 2 giorni', 2);
        $giorni('Arrivata da 9 giorni', 9);
        $giorni('Vecchia in lavorazione', 40);
        $giorni('Vecchia respinta', 40);
        $giorni('Vecchia nel file', 40);

        $x = $cose->pubblicazioni();
        $this->assertSame(2, $x['ricevute']);
        $this->assertSame(9, $x['giorni'], 'la più vecchia delle richieste da guardare');

        // senza richieste da guardare i giorni sono zero
        $this->db->esegui("UPDATE pubblicazioni SET stato = 'lavorazione' WHERE stato = 'ricevuta' AND nel_monitoraggio = 0");
        $this->assertSame(['ricevute' => 0, 'giorni' => 0], array_intersect_key($cose->pubblicazioni(), ['ricevute' => 1, 'giorni' => 1]));
    }
}
