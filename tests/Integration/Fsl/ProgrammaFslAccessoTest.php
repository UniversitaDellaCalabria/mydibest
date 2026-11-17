<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Auth\Sessione;
use App\Fsl\ProgrammaFsl;
use App\Fsl\ServizioProgrammaFsl;
use Tests\Doppi\AuthSessioneInMemoria;

/**
 * Chi può aggiungere un'attività riservata al programma FSL della scuola (eventi.ruolo_accesso_id): stessa regola delle prenotazioni
 * (App\Eventi\AccessoEvento). Prima di questa prova il ramo FSL non aveva test sul permesso.
 */
final class ProgrammaFslAccessoTest extends FslBase
{
    private ServizioProgrammaFsl $servizio;
    private ProgrammaFsl $programma;

    protected function tabelle(): array
    {
        return parent::tabelle() + [
            'campi_form' => "CREATE TABLE campi_form (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT NOT NULL, evento_id INT NULL, nome_campo VARCHAR(100) NOT NULL, etichetta VARCHAR(255) NOT NULL DEFAULT '', tipo_campo VARCHAR(50) DEFAULT 'text', ordine INT DEFAULT 0)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->esegui("ALTER TABLE eventi ADD COLUMN luogo VARCHAR(150) DEFAULT '', ADD COLUMN ruolo_accesso_id INT NOT NULL DEFAULT 0, ADD COLUMN richiede_prenotazione TINYINT NOT NULL DEFAULT 1");
        $this->db->esegui('ALTER TABLE turni ADD COLUMN data_apertura DATETIME NULL, ADD COLUMN data_chiusura DATETIME NULL, ADD COLUMN max_posti INT DEFAULT 50');
        \App\Auth\Registrazione::registra($this->c); // ServizioPrenotazioni arriva fino alle abilitazioni dei gestori
        $sessione = new AuthSessioneInMemoria();
        $this->c->istanza(Sessione::class, $sessione);
        $this->programma = new ProgrammaFsl($sessione);
        $this->c->istanza(ProgrammaFsl::class, $this->programma);
        $this->servizio = $this->servizio(ServizioProgrammaFsl::class);
    }

    private function riservaA(int $ruoloAccesso): void
    {
        $this->db->esegui('UPDATE eventi SET ruolo_accesso_id = ? WHERE id = 20', [$ruoloAccesso]);
    }

    /** Prova ad aggiungere l'edizione 1 del progetto 20; ritorna l'esito. @param list<string> $secondari @return array{ok: bool, errore: string|null} */
    private function aggiungi(bool $connesso, int $ruolo, array $secondari = []): array
    {
        $this->programma->svuota();
        $r = $this->servizio->aggiungi(200, ['custom_numero_partecipanti' => '12'], $connesso, $ruolo, $secondari);
        self::assertSame($r['ok'], $this->programma->contieneTurno(200), 'il programma contiene l\'attività solo se l\'esito è positivo');

        return $r;
    }

    public function testAttivitaLiberaPerTuttiAncheSenzaAccesso(): void
    {
        $this->riservaA(0);
        self::assertTrue($this->aggiungi(false, 5)['ok']);
    }

    public function testRiservataAStudentiRifiutaChiNonLoSiEAccettaChiLoE(): void
    {
        $this->riservaA(3);

        $no = $this->aggiungi(false, 5);
        self::assertFalse($no['ok']);
        self::assertStringContainsString('riservata', (string) $no['errore']);
        self::assertFalse($this->aggiungi(true, 4)['ok'], 'un docente non è uno studente');
        self::assertFalse($this->aggiungi(true, 4, ['6', '7'])['ok'], 'nemmeno con altri gruppi');
        self::assertFalse($this->aggiungi(true, 2)['ok'], 'il gestore (ruolo 2) non passa');
        self::assertFalse($this->aggiungi(false, 3, ['3'])['ok'], 'senza accesso non conta il ruolo');

        self::assertTrue($this->aggiungi(true, 3)['ok'], 'studente');
        self::assertTrue($this->aggiungi(true, 5, ['6', '3'])['ok'], 'studente come ruolo secondario');
        self::assertTrue($this->aggiungi(true, 1)['ok'], 'amministratore');
        self::assertTrue($this->aggiungi(true, 5, ['1'])['ok'], 'amministratore come ruolo secondario');
    }

    public function testRiservataAChiHaFattoLAccesso(): void
    {
        $this->riservaA(-1);

        self::assertFalse($this->aggiungi(false, 5)['ok']);
        self::assertTrue($this->aggiungi(true, 5)['ok'], 'basta aver fatto l\'accesso');
        self::assertTrue($this->aggiungi(true, 2)['ok']);
    }
}
