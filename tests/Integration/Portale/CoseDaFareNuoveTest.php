<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Core\Schema\Definizioni;
use App\Fsl\PannelloRepository;
use App\Portale\CoseDaFare;
use App\Portale\CoseDaFareEventi;
use Tests\Integration\DatabaseDiProva;

/** I numeri nuovi di «Cosa devo fare»: eventi e seminari, tutorato, progetti FSL senza scuole. Un caso che conta e uno che non conta per ognuno. */
final class CoseDaFareNuoveTest extends DatabaseDiProva
{
    /** Tabelle con lo schema vero (colonne aggiunte comprese). */
    private const REALI = ['tutorato_incarichi', 'tutorato_registro', 'progetti_dettagli'];

    protected function tabelle(): array
    {
        return [
            'eventi' => "CREATE TABLE eventi (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT, titolo VARCHAR(150) DEFAULT '', tipo VARCHAR(20) DEFAULT 'evento', archiviato TINYINT DEFAULT 0)",
            'turni' => 'CREATE TABLE turni (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT, data_turno DATE NULL, orario_inizio TIME NULL, orario_fine TIME NULL, max_posti INT DEFAULT 10)',
            'prenotazioni' => "CREATE TABLE prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, turno_id INT, stato VARCHAR(30) NULL DEFAULT 'confermata', presente INT DEFAULT 0, num_posti INT DEFAULT 1,
                attestato_inviato TINYINT DEFAULT 0, solo_orientamento TINYINT DEFAULT 0)",
            'sondaggi' => 'CREATE TABLE sondaggi (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT, attivo TINYINT DEFAULT 1)',
            'sondaggi_risposte' => 'CREATE TABLE sondaggi_risposte (id INT AUTO_INCREMENT PRIMARY KEY, sondaggio_id INT)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $defs = Definizioni::tabelle();
        $cols = Definizioni::colonne();
        foreach (self::REALI as $t) {
            self::$conn->query("DROP TABLE IF EXISTS `$t`");
            self::$conn->query($defs[$t]);
            foreach ($cols[$t] ?? [] as $spec) {
                self::$conn->query("ALTER TABLE `$t` " . (is_array($spec) ? $spec[0] : $spec));
            }
        }
        $this->allineaColonne(); // colonne aggiunte dalle migrazioni alle tabelle ridotte (es. eventi.rilascia_attestato)
    }

    private function evento(int $area = 1, string $tipo = 'evento', int $archiviato = 0): int
    {
        $this->db->esegui('INSERT INTO eventi (pagina_id, tipo, archiviato) VALUES (?, ?, ?)', [$area, $tipo, $archiviato]);

        return (int) self::$conn->insert_id;
    }

    private function turno(int $evento, string $data = '+5 days', string $fine = '12:00:00', int $posti = 10): int
    {
        $this->db->esegui('INSERT INTO turni (evento_id, data_turno, orario_fine, max_posti) VALUES (?, ?, ?, ?)', [$evento, date('Y-m-d', strtotime($data)), $fine, $posti]);

        return (int) self::$conn->insert_id;
    }

    private function iscritto(int $turno, string $stato = 'confermata', int $presente = 0, int $inviato = 0): void
    {
        $this->db->esegui('INSERT INTO prenotazioni (turno_id, stato, presente, attestato_inviato) VALUES (?, ?, ?, ?)', [$turno, $stato, $presente, $inviato]);
    }

    public function testIscrizioniDaApprovareContaSoloQuelleDellAreaNonArchiviata(): void
    {
        $c = new CoseDaFareEventi($this->db);
        $this->iscritto($this->turno($this->evento(1)), 'da_approvare');
        $this->iscritto($this->turno($this->evento(1)), 'da_approvare');
        $this->iscritto($this->turno($this->evento(1)), 'confermata');
        $this->iscritto($this->turno($this->evento(2)), 'da_approvare');                // altra area
        $this->iscritto($this->turno($this->evento(1, 'evento', 1)), 'da_approvare');   // archiviato

        $this->assertSame(2, $c->iscrizioniDaApprovare(1));
        $this->assertSame(1, $c->iscrizioniDaApprovare(2));
    }

    public function testIlFiltroDiChiGestisceLimitaIConteggi(): void
    {
        $c = new CoseDaFareEventi($this->db);
        $e1 = $this->evento(1);
        $e2 = $this->evento(1);
        $this->iscritto($this->turno($e1), 'da_approvare');
        $this->iscritto($this->turno($e2), 'da_approvare');

        $this->assertSame(2, $c->iscrizioniDaApprovare(1));
        $this->assertSame(1, $c->iscrizioniDaApprovare(1, " AND e.id IN ($e1) "));
        $this->assertSame(0, $c->iscrizioniDaApprovare(1, ' AND e.id = -1 '));
    }

    public function testListaDAttesaConPostiLiberi(): void
    {
        $c = new CoseDaFareEventi($this->db);
        $conPosto = $this->turno($this->evento(1), '+5 days', '12:00:00', 2);
        $this->iscritto($conPosto, 'confermata');   // 1 posto occupato su 2
        $this->iscritto($conPosto, 'in_attesa');
        $pieno = $this->turno($this->evento(1), '+5 days', '12:00:00', 1);
        $this->iscritto($pieno, 'confermata');      // pieno: la lista aspetta giustamente
        $this->iscritto($pieno, 'in_attesa');
        $passato = $this->turno($this->evento(1), '-3 days', '12:00:00', 5);
        $this->iscritto($passato, 'in_attesa');     // turno già passato
        $senzaAttesa = $this->turno($this->evento(1), '+5 days', '12:00:00', 5);
        $this->iscritto($senzaAttesa, 'confermata');

        $this->assertSame(1, $c->turniInAttesaConPostiLiberi(1));
    }

    public function testAttestatiDaInviareSoloPresentiATurnoConclusoSenzaInvio(): void
    {
        $c = new CoseDaFareEventi($this->db);
        $finito = $this->turno($this->evento(1), '-2 days');
        $this->iscritto($finito, 'confermata', 1, 0);   // conta
        $this->iscritto($finito, 'confermata', 1, 1);   // già inviato
        $this->iscritto($finito, 'confermata', 0, 0);   // assente
        $this->iscritto($this->turno($this->evento(1), '+4 days'), 'confermata', 1, 0); // turno non ancora concluso

        $this->assertSame(1, $c->attestatiDaInviare(1));
    }

    public function testSondaggiChiusiConRisposte(): void
    {
        $c = new CoseDaFareEventi($this->db);
        $e = $this->evento(1);
        $this->db->esegui('INSERT INTO sondaggi (evento_id, attivo) VALUES (?, 0)', [$e]);
        $chiusoConRisposte = (int) self::$conn->insert_id;
        $this->db->esegui('INSERT INTO sondaggi_risposte (sondaggio_id) VALUES (?)', [$chiusoConRisposte]);
        $this->db->esegui('INSERT INTO sondaggi (evento_id, attivo) VALUES (?, 0)', [$e]);              // chiuso ma vuoto
        $this->db->esegui('INSERT INTO sondaggi (evento_id, attivo) VALUES (?, 1)', [$e]);              // attivo
        $this->db->esegui('INSERT INTO sondaggi_risposte (sondaggio_id) VALUES (?)', [(int) self::$conn->insert_id]);

        $this->assertSame(1, $c->sondaggiChiusiConRisposte(1));
        $this->assertSame(0, $c->sondaggiChiusiConRisposte(2));
    }

    public function testPerAreaRestituisceTuttiIQuattroNumeri(): void
    {
        $this->iscritto($this->turno($this->evento(1)), 'da_approvare');
        $r = (new CoseDaFareEventi($this->db))->perArea(1);

        $this->assertSame(['da_approvare', 'attesa_con_posti', 'attestati', 'sondaggi'], array_keys($r));
        $this->assertSame(1, $r['da_approvare']);
    }

    public function testTutoratoPerStatoNonContaLeAnnullate(): void
    {
        $this->db->esegui("INSERT INTO tutorato_incarichi (bando_id, codice, stato) VALUES (1, 'A1', 'inviata'), (1, 'A2', 'inviata'), (1, 'A3', 'firmata_docente'), (1, 'A4', 'annullata')");

        $this->assertEquals(['inviata' => 2, 'firmata_docente' => 1], (new CoseDaFare($this->db))->tutoratoPerStato());
    }

    public function testTutoratoRegistriDaControllareContaLettereNonRighe(): void
    {
        $this->db->esegui("INSERT INTO tutorato_incarichi (bando_id, codice, stato) VALUES (1, 'B1', 'protocollata'), (1, 'B2', 'protocollata'), (1, 'B3', 'annullata')");
        $ids = array_column($this->db->righe('SELECT id FROM tutorato_incarichi ORDER BY id'), 'id');
        // la prima lettera ha due righe inviate (conta 1), la seconda solo righe già decise, la terza è annullata
        foreach ([[$ids[0], 'inviata'], [$ids[0], 'inviata'], [$ids[1], 'approvata'], [$ids[2], 'inviata']] as [$i, $s]) {
            $this->db->esegui('INSERT INTO tutorato_registro (incarico_id, data, ore, stato) VALUES (?, CURDATE(), 2, ?)', [$i, $s]);
        }

        $this->assertSame(1, (new CoseDaFare($this->db))->tutoratoRegistriDaControllare());
    }

    public function testProgettiSenzaScuoleContaSoloQuelliAperti(): void
    {
        $repo = new PannelloRepository($this->db);
        $senza = $this->evento(1, 'progetto');
        $conScuola = $this->evento(1, 'progetto');
        $archiviato = $this->evento(1, 'progetto', 1);
        $finito = $this->evento(1, 'progetto');
        foreach ([$senza => '+30 days', $conScuola => '+30 days', $archiviato => '+30 days', $finito => '-10 days'] as $e => $fine) {
            $this->db->esegui('INSERT INTO progetti_dettagli (evento_id, data_fine, per_scuole, convenzione) VALUES (?, ?, 1, 1)', [$e, date('Y-m-d', strtotime($fine))]);
        }
        $this->iscritto($this->turno($conScuola), 'confermata');

        $this->assertSame(1, $repo->progettiSenzaScuole());
    }
}
