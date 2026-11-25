<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Fsl\ElencoScuoleFsl;
use App\Fsl\SchedaScuolaFsl;

/**
 * Ritocchi Y1-Y6 alla scheda della scuola (prima del passo 9), provati senza browser: «Scrivi alla scuola» sempre possibile (Y1), «nell'Allegato A» (Y2),
 * elenco studenti e autorizzazione mancanti (Y5), «Rimanda il link» solo se la scuola ha ancora da fare (Y6). Gli altri (testata, registro) sono in
 * SchedaIterCompletoTest e PaginaScuoleFslTest.
 */
final class RitocchiSchedaTest extends PaginaScuoleFslBase
{
    protected function tabelle(): array
    {
        return [...parent::tabelle(), 'messaggi_prenotazioni' => 'CREATE TABLE messaggi_prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, prenotazione_id INT NOT NULL, mittente_tipo VARCHAR(10) NOT NULL DEFAULT \'utente\', mittente_id INT NULL, messaggio TEXT NULL, letto TINYINT NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->comando('ALTER TABLE eventi ADD COLUMN luogo VARCHAR(255) NULL');   // la leggono i messaggi alle prenotazioni (Y1)
    }

    private function compilazione(string $istituto, string $stato, array $campi = []): int
    {
        $campi += ['token' => bin2hex(random_bytes(10)), 'scuola_codice' => $istituto, 'email' => 'ref@' . strtolower($istituto) . '.it', 'stato_iter' => $stato,
            'dati_json' => '{"scuola":{"denominazione":"Scuola di prova","dirigente":"Maria Dirigente"},"attivita":[]}', 'aggiornata_il' => '2026-10-01 10:00:00'];

        return $this->db->inserisci('INSERT INTO convenzioni_compilate (' . implode(',', array_keys($campi)) . ') VALUES (' . implode(',', array_fill(0, count($campi), '?')) . ')', array_values($campi));
    }

    /** @return array<string, mixed> */
    private function scheda(string $istituto): array
    {
        return $this->servizio(SchedaScuolaFsl::class)->scheda($istituto, 0, true);
    }

    // ───────────────────────────── Y1 ─────────────────────────────

    public function testScriviAllaScuolaSenzaCompilazioneMandaAiDocentiScelti(): void
    {
        $a = $this->pren(200, ['scuola_codice' => 'CSPS00001A', 'nome' => 'Anna', 'cognome' => 'Verdi', 'email' => 'anna@galilei.it']);
        $b = $this->pren(201, ['scuola_codice' => 'CSPS00001A', 'nome' => 'Anna', 'cognome' => 'Verdi', 'email' => 'ANNA@galilei.it']);
        $c = $this->pren(200, ['scuola_codice' => 'CSPS00001A', 'nome' => 'Carlo', 'cognome' => 'Neri', 'email' => 'carlo@galilei.it']);

        $r = $this->invia(['cv_scrivi_docenti' => '1', 'destinatari' => [$a, $b, $c], 'testo' => 'Ci serve il referente'], ['k' => 'CSIS00001A']);

        self::assertSame(303, $r->codice());
        self::assertCount(2, $this->mailer->inviate, 'una email per indirizzo, anche se Anna ha due prenotazioni');
        self::assertEqualsCanonicalizing(['anna@galilei.it', 'carlo@galilei.it'], array_map('strtolower', array_column($this->mailer->inviate, 'a')));
        foreach ($this->mailer->inviate as $m) {
            self::assertStringNotContainsString('convenzione_online.php?t=', $m['corpo'], 'il link personale della convenzione non parte con questo messaggio');
        }
        self::assertSame(0, $this->compilazioni(), 'non nasce nessuna compilazione');
    }

    public function testScriviAllaScuolaConLimitiEdErrori(): void
    {
        $a = $this->pren(200, ['scuola_codice' => 'CSPS00001A', 'email' => 'anna@galilei.it']);
        $altra = $this->pren(200, ['scuola_codice' => 'CSPS00002B', 'email' => 'fermi@fermi.it']);

        $this->invia(['cv_scrivi_docenti' => '1', 'destinatari' => [], 'testo' => 'Ciao'], ['k' => 'CSIS00001A']);
        $this->invia(['cv_scrivi_docenti' => '1', 'destinatari' => [$a], 'testo' => '   '], ['k' => 'CSIS00001A']);
        $this->invia(['cv_scrivi_docenti' => '1', 'destinatari' => [$altra], 'testo' => 'Ciao'], ['k' => 'CSIS00001A']);

        self::assertCount(0, $this->mailer->inviate, 'senza destinatari, senza testo o con il docente di un\'altra scuola non parte niente');
    }

    public function testScriviAllaScuolaConCompilazioneScriveAlLeiMaNonDaQuestoModulo(): void
    {
        $this->pren(200, ['scuola_codice' => 'CSPS00001A', 'nome' => 'Anna', 'email' => 'anna@galilei.it']);
        $cc = $this->compilazione('CSIS00001A', 'in_carico', ['file_firmato' => 'x.pdf', 'email' => 'ref@galilei.it']);

        $this->invia(['cv_messaggio' => (string) $cc, 'cv_messaggio_scelta' => (string) $cc, 'testo' => 'Buongiorno'], ['k' => 'CSIS00001A']);

        self::assertCount(1, $this->mailer->inviate);
        self::assertSame('ref@galilei.it', $this->mailer->inviate[0]['a'], 'il messaggio della compilazione va alla sua email, con il suo link');
    }

    // ───────────────────────────── Y2 ─────────────────────────────

    public function testNellAllegatoASiDaSoloSeSalvatoOInUnaConvenzioneValida(): void
    {
        $this->pren(200, ['scuola_codice' => 'CSPS00001A', 'nome' => 'Anna Verdi', 'email' => 'anna@galilei.it']);
        $this->pren(200, ['scuola_codice' => 'CSPS00001A', 'nome' => 'Carlo Neri', 'email' => 'carlo@galilei.it']);
        $this->pren(200, ['scuola_codice' => 'CSPS00001A', 'nome' => 'Dora Gialli', 'email' => 'dora@galilei.it']);
        $this->convenzione('CSIS00001A', '2026-01-01', '2027-12-31', ['docenti_json' => json_encode([['nome' => 'Anna Verdi', 'email' => 'ANNA@galilei.it']])]);
        $this->compilazione('CSIS00001A', 'caricata_scuola', ['file_firmato' => 'x.pdf', 'docenti_json' => json_encode([['nome' => 'Carlo Neri', 'email' => 'carlo@galilei.it']])]);

        $docenti = array_column($this->scheda('CSIS00001A')['docenti'], null, 'email');

        self::assertTrue($docenti['anna@galilei.it']['in_allegato'], 'nella convenzione valida (l\'email si confronta senza maiuscole)');
        self::assertFalse($docenti['dora@galilei.it']['in_allegato'], 'chi ha solo prenotato non è nell\'Allegato A');
        self::assertArrayHasKey('carlo@galilei.it', $docenti);
    }

    public function testUnaConvenzioneScadutaNonMetteNessunoNellAllegatoA(): void
    {
        $this->pren(200, ['scuola_codice' => 'CSPS00001A', 'nome' => 'Anna Verdi', 'email' => 'anna@galilei.it']);
        $this->convenzione('CSIS00001A', '2019-01-01', '2020-01-01', ['docenti_json' => json_encode([['nome' => 'Anna Verdi', 'email' => 'anna@galilei.it']])]);

        $docenti = array_column($this->scheda('CSIS00001A')['docenti'], null, 'email');

        self::assertFalse($docenti['anna@galilei.it']['in_allegato']);
    }

    // ───────────────────────────── Y5 ─────────────────────────────

    private function attivitaFinitaSenzaDocumenti(): void
    {
        $this->db->esegui("UPDATE progetti_dettagli SET data_inizio = '2026-09-01', data_fine = '2026-09-20' WHERE evento_id = 20");
        $this->pren(200, ['scuola_codice' => 'CSPS00001A', 'nome' => 'Anna', 'email' => 'anna@galilei.it', 'data_prenotazione' => '2026-08-01 10:00:00']);
    }

    public function testElencoEAutorizzazioneMancantiSiVedonoSoloConLaFslCompleta(): void
    {
        $this->attivitaFinitaSenzaDocumenti();

        $con = array_column($this->servizio(ElencoScuoleFsl::class)->righe(0, true), null, 'chiave')['CSIS00001A'];
        $senza = array_column($this->servizio(ElencoScuoleFsl::class)->righe(0, false), null, 'chiave')['CSIS00001A'];

        self::assertContains('elenco_mancante', $con['tags']);
        self::assertContains('autorizzazione_mancante', $con['tags']);
        self::assertNotContains('elenco_mancante', $senza['tags'], 'chi ha solo le convenzioni non lo vede');
        self::assertNotContains('autorizzazione_mancante', $senza['tags']);
    }

    public function testLElencoMancanteSpariceQuandoCEUnoStudente(): void
    {
        $this->attivitaFinitaSenzaDocumenti();
        $id = (int) $this->db->valore('SELECT MAX(id) FROM prenotazioni');
        $this->db->esegui('INSERT INTO partecipanti_prenotazione (prenotazione_id) VALUES (?)', [$id]);
        $this->db->esegui("UPDATE prenotazioni SET autorizzazione_file = 'uploads/a.pdf' WHERE id = ?", [$id]);

        $riga = array_column($this->servizio(ElencoScuoleFsl::class)->righe(0, true), null, 'chiave')['CSIS00001A'];

        self::assertNotContains('elenco_mancante', $riga['tags']);
        self::assertNotContains('autorizzazione_mancante', $riga['tags']);
    }

    public function testLaRigaAutorizzazioneDellaSchedaSiVedeSoloConLaFslCompleta(): void
    {
        $this->attivitaFinitaSenzaDocumenti();

        $admin = $this->apri(['k' => 'CSIS00001A', 'anno' => 'tutti'])->corpo();
        self::assertStringContainsString('<dt>Autorizzazione</dt>', $admin, 'chi gestisce tutta la FSL vede la riga');

        $this->entra(2);   // ha solo le convenzioni
        $solo = $this->apri(['k' => 'CSIS00001A', 'anno' => 'tutti'])->corpo();
        self::assertStringNotContainsString('<dt>Autorizzazione</dt>', $solo, 'chi ha solo le convenzioni non la vede, come il numero degli studenti');
        self::assertStringNotContainsString('autorizzazione_scuola.php', $solo, 'né il suo link');
    }

    public function testElencoMancanteInElencoESchedaDiconoLoStesso(): void
    {
        $this->attivitaFinitaSenzaDocumenti();

        $riga = array_column($this->servizio(ElencoScuoleFsl::class)->righe(0, true), null, 'chiave')['CSIS00001A'];
        $scheda = $this->scheda('CSIS00001A');

        self::assertEqualsCanonicalizing($riga['tags'], $scheda['tags']);
    }

    // ───────────────────────────── Y6 ─────────────────────────────

    public function testRimandaIlLinkSoloSeLaScuolaDeveAncoraFareQualcosa(): void
    {
        $this->compilazione('CSIS00001A', 'in_carico', ['file_firmato' => 'x.pdf']);
        $this->compilazione('CSIS00002B', 'caricata_scuola', ['file_firmato' => 'x.pdf']);

        $firmataScuola = $this->apri(['k' => 'CSIS00002B', 'anno' => 'tutti'])->corpo();
        $dalDirettore = $this->apri(['k' => 'CSIS00001A', 'anno' => 'tutti'])->corpo();

        self::assertStringNotContainsString('Rimanda il link alla scuola', $firmataScuola, 'la scuola ha già caricato il PDF: non c\'è niente da rimandarle');
        self::assertStringNotContainsString('Rimanda il link alla scuola', $dalDirettore);
    }
}
