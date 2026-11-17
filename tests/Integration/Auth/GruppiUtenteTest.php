<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Auth\GruppiUtente;
use Tests\Integration\DatabaseDiProva;

/** La lettura unica dei gruppi (righe di «ruoli») di una persona, usata da Pubblico, risorse, MyForms e area personale. */
final class GruppiUtenteTest extends DatabaseDiProva
{
    private GruppiUtente $gruppi;

    protected function tabelle(): array
    {
        return ['ruoli' => 'CREATE TABLE ruoli (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(100) NOT NULL)'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->esegui("INSERT INTO ruoli (id, nome) VALUES (1, 'Super Amministratore'), (3, 'Studente'), (5, 'Ospite'), (6, 'Docenti'), (9, 'Scuole'), (10, 'Aziende')");
        $this->gruppi = new GruppiUtente($this->db);
    }

    public function testSenzaUtenteOSenzaIdNessunGruppo(): void
    {
        self::assertSame([], $this->gruppi->nomi(null));
        self::assertSame([], $this->gruppi->nomi([]));
        self::assertSame([], $this->gruppi->nomi(['ruolo_id' => 3, 'ruoli_secondari' => '6']), 'senza id non è una persona che ha fatto l\'accesso');
        self::assertSame([], $this->gruppi->nomi(['id' => 0, 'ruolo_id' => 3]));
    }

    public function testRuoloPrincipaleESecondariInMinuscolo(): void
    {
        self::assertSame(['studente'], $this->gruppi->nomi(['id' => 7, 'ruolo_id' => 3, 'ruoli_secondari' => '']));
        self::assertEqualsCanonicalizing(['studente', 'docenti', 'scuole'], $this->gruppi->nomi(['id' => 7, 'ruolo_id' => 3, 'ruoli_secondari' => '6,9']));
        self::assertSame(['super amministratore'], $this->gruppi->nomi(['id' => 7, 'ruolo_id' => 1]));
    }

    public function testSenzaRuoloPrincipaleVale5(): void
    {
        self::assertSame(['ospite'], $this->gruppi->nomi(['id' => 7]));
    }

    public function testSoloInteriEIdSconosciutiIgnorati(): void
    {
        // spazi, testo e vuoti nei secondari non diventano SQL; un id che non esiste non dà niente; i doppioni non cambiano il risultato
        self::assertEqualsCanonicalizing(['studente', 'docenti'], $this->gruppi->nomi(['id' => 7, 'ruolo_id' => 3, 'ruoli_secondari' => "6, x, ,3,0,99,6'; DROP TABLE ruoli;--"]));
        self::assertSame(1, (int) $this->db->valore("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ruoli'"));
    }

    public function testIdRuoliPrincipaleESecondariSenzaDoppioni(): void
    {
        self::assertSame([3, 6, 9], GruppiUtente::idRuoli(['ruolo_id' => 3, 'ruoli_secondari' => '6,3,,9,0,abc']));
        self::assertSame([5], GruppiUtente::idRuoli([]));
    }

    public function testQueryCheNonRiesceDaNessunGruppo(): void
    {
        $this->db->esegui('DROP TABLE ruoli');
        self::assertSame([], $this->gruppi->nomi(['id' => 7, 'ruolo_id' => 3]), 'nessun gruppo, quindi nessun permesso in più (e nessuna eccezione)');
    }
}
