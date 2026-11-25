<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Core\Database;
use App\Fsl\ElencoScuoleFsl;
use App\Fsl\RubricaDocentiScuola;
use App\Fsl\SchedaScuolaFsl;

/**
 * Passo 8: i dati della scheda e dell'elenco della pagina «Scuole» (App\Fsl\SchedaScuolaFsl, ElencoScuoleFsl): stato della convenzione, eventi di tutte le
 * sedi (anche non FSL), scuola scritta a mano, docenti uniti per email, link agli studenti, e soprattutto un numero di query che NON cresce con le prenotazioni.
 * Data di prova: 5 ottobre 2026 (anno scolastico 2026/27).
 */
final class SchedaScuolaFslTest extends FslBase
{
    protected function tabelle(): array
    {
        return [
            ...parent::tabelle(),
            'convenzioni_messaggi' => "CREATE TABLE convenzioni_messaggi (id INT AUTO_INCREMENT PRIMARY KEY, compilazione_id INT NOT NULL, da VARCHAR(10) NOT NULL, tipo VARCHAR(20) NOT NULL DEFAULT 'messaggio',
                testo VARCHAR(2000) NOT NULL DEFAULT '', autore VARCHAR(150) NOT NULL DEFAULT '', creato_il DATETIME DEFAULT CURRENT_TIMESTAMP, letto_il DATETIME NULL)",
            'scuole_docenti' => self::tabellaVera('scuole_docenti'),
            'log_email' => self::tabellaVera('log_email'),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->esegui('ALTER TABLE utenti ADD COLUMN nome VARCHAR(100) NULL, ADD COLUMN cognome VARCHAR(100) NULL');
        $this->db->esegui("ALTER TABLE pagine_eventi ADD COLUMN tipo_area VARCHAR(20) NOT NULL DEFAULT ''");
        $this->db->esegui('ALTER TABLE partecipanti_prenotazione ADD COLUMN escluso TINYINT NOT NULL DEFAULT 0');
        // un evento NON FSL (convenzione spenta) nella stessa area
        $this->db->esegui("INSERT INTO eventi (id, pagina_id, titolo, tipo) VALUES (30, 2, 'Open day', 'evento')");
        $this->db->esegui('INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati) VALUES (30, 0, 0, 0)');
        $this->db->esegui("INSERT INTO turni (id, evento_id, nome_turno, data_turno) VALUES (300, 30, 'Turno', '2026-11-10')");
    }

    private function scheda(): SchedaScuolaFsl
    {
        return $this->servizio(SchedaScuolaFsl::class);
    }

    private function elenco(): ElencoScuoleFsl
    {
        return $this->servizio(ElencoScuoleFsl::class);
    }

    public function testScuolaDellAnagrafeSenzaPrenotazioniHaLaScheda(): void
    {
        $s = $this->scheda()->scheda('CSPS00002B', 0, true);

        self::assertNotNull($s);
        self::assertSame('CSIS00002B', $s['chiave'], 'la scheda è dell\'istituto');
        self::assertSame('manca', $s['stato']);
        self::assertSame('avvia', $s['azione']['chiave']);
        self::assertSame([], $s['eventi']);
        self::assertSame('Liceo Scientifico Fermi – Rende', $s['nome']);
    }

    public function testScuolaInesistenteOCodiceNonValidoNonHannoScheda(): void
    {
        self::assertNull($this->scheda()->scheda('ZZZZ000000', 0, true));
        self::assertNull($this->scheda()->scheda('abc', 0, true));
        self::assertNull($this->scheda()->scheda('', 0, true));
        self::assertNull($this->scheda()->scheda('x123', 0, true), 'una chiave «x» deve avere 12 cifre esadecimali');
    }

    public function testEventiDiUnaSedeENonFslConLaCopertura(): void
    {
        $this->convenzione('CSPS00001A', '2026-09-01', '2026-10-31');   // scade prima della fine del progetto 20 (02/12)
        $fsl = $this->pren(200, ['scuola_codice' => 'CSPS00001A']);
        $evento = $this->pren(100, ['scuola_codice' => 'CSPS00001A']);          // evento FSL del 23/10: coperto
        $non = $this->pren(300, ['scuola_codice' => 'CSPS00001A']);             // open day, non FSL

        $s = $this->scheda()->scheda('CSPS00001A', 0, true);
        $per = array_column($s['eventi'], null, 'id');

        self::assertCount(3, $per, 'tutte le prenotazioni, anche non FSL');
        self::assertSame('non_coperta', $per[$fsl]['conv']);
        self::assertStringContainsString('scade il 31/10/2026', $per[$fsl]['perche']);
        self::assertSame('coperta', $per[$evento]['conv']);
        self::assertSame('non_richiesta', $per[$non]['conv'], 'un evento non FSL è «convenzione non richiesta»');
        self::assertSame(1, $s['n_scoperte']);
        self::assertSame('Edizione 1', $per[$fsl]['turno']);
    }

    public function testElencoDelleSediEdellAnnoScelto(): void
    {
        $this->pren(300, ['scuola_codice' => 'CSPS00001A']);
        $this->db->esegui("UPDATE turni SET data_turno = '2025-03-10' WHERE id = 300");   // quest'anno scolastico è il 2024/25
        $this->pren(100, ['scuola_codice' => 'CSPS00001A']);                                // 2026/27

        $tutti = $this->scheda()->scheda('CSPS00001A', 0, true);
        $solo2026 = $this->scheda()->scheda('CSPS00001A', 2026, true);
        $solo2024 = $this->scheda()->scheda('CSPS00001A', 2024, true);

        self::assertCount(2, $tutti['eventi']);
        self::assertCount(1, $solo2026['eventi']);
        self::assertCount(1, $solo2024['eventi']);
        self::assertSame([2026, 2024], $tutti['anni']);
        self::assertSame(2, $solo2026['n_eventi_tutti']);
    }

    public function testScuolaScrittaAManoEsoloConLeAttivitaFsl(): void
    {
        $nome = 'Istituto Rossi di Altrove';
        $json = '{"numero_partecipanti":"5","nome_della_scuola":"' . $nome . '"}';
        $fsl = $this->pren(200, ['scuola_codice' => null, 'dati_custom_json' => $json]);
        $this->pren(300, ['scuola_codice' => null, 'dati_custom_json' => $json]);   // non FSL: non è «la scuola»
        $chiave = 'x' . substr(md5(mb_strtolower($nome)), 0, 12);

        $s = $this->scheda()->scheda($chiave, 0, true);

        self::assertNotNull($s);
        self::assertTrue($s['a_mano']);
        self::assertSame($nome, $s['nome']);
        self::assertCount(1, $s['eventi']);
        self::assertSame($fsl, $s['eventi'][0]['id']);
        self::assertFalse($s['in_anagrafe']);
        $riga = array_values(array_filter($this->elenco()->righe(2026), static fn (array $r): bool => $r['chiave'] === $chiave));
        self::assertCount(1, $riga);
        self::assertTrue($riga[0]['a_mano']);
        self::assertSame('Abbina la scuola all\'anagrafe', $riga[0]['cosa']);
    }

    public function testILinkAgliStudentiSiVedonoSoloACheLiPuoAprire(): void
    {
        $id = $this->pren(200, ['scuola_codice' => 'CSPS00001A']);
        $visti = [];
        $s = $this->scheda()->scheda('CSPS00001A', 0, true, static function (int $pagina) use (&$visti): bool {
            $visti[] = $pagina;

            return false;
        });
        self::assertFalse($s['eventi'][0]['puo_studenti']);
        self::assertTrue($s['eventi'][0]['classe'], 'il progetto ha gli attestati: l\'elenco studenti è previsto');
        self::assertSame([2], $visti, 'chiede una volta sola per area, non per prenotazione');

        $s = $this->scheda()->scheda('CSPS00001A', 0, true, static fn (int $pagina): bool => true);
        self::assertTrue($s['eventi'][0]['puo_studenti']);
        self::assertTrue($s['eventi'][0]['ricorda_elenco'], 'elenco non ancora caricato');

        $s = $this->scheda()->scheda('CSPS00001A', 0, true, null);
        self::assertFalse($s['eventi'][0]['puo_studenti'], 'senza chi decide, nessun link');
        self::assertSame($id, $s['eventi'][0]['id']);
    }

    public function testChiHaSoloLeConvenzioniNonVedeNumeriNeValutazioni(): void
    {
        $id = $this->pren(200, ['scuola_codice' => 'CSPS00001A']);
        $this->db->esegui('INSERT INTO valutazioni_fsl (prenotazione_id, evento_id, media) VALUES (?, 20, 4.5)', [$id]);
        $this->db->esegui('INSERT INTO partecipanti_prenotazione (prenotazione_id) VALUES (?)', [$id]);

        $con = $this->scheda()->scheda('CSPS00001A', 0, true, static fn (int $p): bool => true);
        $senza = $this->scheda()->scheda('CSPS00001A', 0, false, static fn (int $p): bool => true);

        self::assertSame(12, $con['eventi'][0]['studenti']);
        self::assertSame(1, $con['eventi'][0]['n_elenco']);
        self::assertCount(1, $con['valutazioni']);
        self::assertNull($senza['eventi'][0]['studenti']);
        self::assertNull($senza['eventi'][0]['n_elenco']);
        self::assertSame([], $senza['valutazioni']);
        self::assertFalse($senza['eventi'][0]['puo_studenti'], 'senza i numeri nemmeno il link agli studenti');
        $righe = $this->elenco()->righe(2026);
        self::assertArrayHasKey('studenti', $righe[0], 'l\'elenco calcola i numeri: è la pagina a nasconderli a chi ha solo le convenzioni');
    }

    public function testIDocentiSiUnisconoPerEmailEUnoNonDiventaProprietario(): void
    {
        $this->pren(200, ['scuola_codice' => 'CSPS00001A', 'nome' => 'Anna', 'cognome' => 'Verdi', 'email' => 'anna@scuola.it', 'dati_custom_json' => '{"numero_partecipanti":"10","telefono":"0984 111"}']);
        $this->pren(100, ['scuola_codice' => 'CSPS00001A', 'nome' => 'A.', 'cognome' => 'Verdi', 'email' => 'ANNA@scuola.it']);
        $this->pren(100, ['scuola_codice' => 'CSPS00001A', 'nome' => 'Luca', 'cognome' => 'Neri', 'email' => 'luca@scuola.it']);
        $this->convenzione('CSPS00001A', '2026-09-01', '2027-08-31', ['docenti_json' => '[{"nome":"Luca Neri","email":"luca@scuola.it"},{"nome":"Rita Bianchi","email":""}]']);
        $rubrica = $this->servizio(RubricaDocentiScuola::class);
        self::assertNull($rubrica->salva('CSIS00001A', 'marco@scuola.it', 'Marco Gialli', '0984 222', 'ufficio'));
        self::assertNull($rubrica->salva('CSIS00001A', 'anna@scuola.it', 'Anna Verdi (corretta)', '', 'ufficio'));

        $d = array_column($this->scheda()->scheda('CSPS00001A', 0, true)['docenti'], null, 'nome');

        self::assertArrayHasKey('Anna Verdi (corretta)', $d, 'la rubrica corregge il nome');
        self::assertSame(2, $d['Anna Verdi (corretta)']['eventi']);
        self::assertSame('0984 111', $d['Anna Verdi (corretta)']['telefono'], 'il telefono già presente nel modulo resta');
        self::assertTrue($d['Luca Neri']['in_allegato']);
        self::assertTrue($d['Luca Neri']['modificabile']);
        self::assertFalse($d['Rita Bianchi']['modificabile'], 'senza email non si corregge né si sceglie come referente');
        self::assertArrayHasKey('Marco Gialli', $d);
        self::assertCount(4, $d, 'persone uguali unite, nessun doppione');
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_compilate'), 'aggiungere un docente non crea nessuna compilazione né proprietà');
    }

    public function testLaRubricaRifiutaEmailENomeNonValidi(): void
    {
        $r = $this->servizio(RubricaDocentiScuola::class);

        self::assertNotNull($r->salva('CSIS00001A', 'non-una-email', 'Mario', '', 'u'));
        self::assertNotNull($r->salva('CSIS00001A', 'a@b.it', '   ', '', 'u'));
        self::assertNotNull($r->salva('CSIS0000', 'a@b.it', 'Mario', '', 'u'));
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM scuole_docenti'));
    }

    public function testIlNumeroDiQueryDellaSchedaNonCresceConLePrenotazioni(): void
    {
        $this->convenzione('CSPS00001A', '2026-09-01', '2027-08-31');
        $this->db->esegui("INSERT INTO convenzioni_compilate (token, scuola_codice, email, stato_iter, dati_json) VALUES ('t1', 'CSPS00001A', 'x@y.it', 'caricata_scuola', '{\"attivita\":[]}')");
        for ($i = 0; $i < 5; $i++) {
            $this->pren($i % 2 ? 100 : 200, ['scuola_codice' => $i % 2 ? 'CSPS00001A' : 'CSIS00001A', 'email' => "d$i@scuola.it"]);
        }
        $this->scheda()->scheda('CSPS00001A', 0, true);   // la prima volta si leggono anche le scuole (cache di ServizioScuole): si misura da «caldo»
        $a = Database::numeroQuery();
        $poche = $this->scheda()->scheda('CSPS00001A', 0, true, static fn (int $p): bool => true);
        $conPoche = Database::numeroQuery() - $a;

        for ($i = 5; $i < 60; $i++) {
            $this->pren($i % 3 === 0 ? 300 : ($i % 2 ? 100 : 200), ['scuola_codice' => $i % 2 ? 'CSPS00001A' : 'CSIS00001A', 'email' => "d$i@scuola.it"]);
        }
        $b = Database::numeroQuery();
        $molte = $this->scheda()->scheda('CSPS00001A', 0, true, static fn (int $p): bool => true);
        $conMolte = Database::numeroQuery() - $b;

        self::assertCount(5, $poche['eventi']);
        self::assertCount(60, $molte['eventi']);
        self::assertSame($conPoche, $conMolte, 'stesso numero di query con 5 e con 60 prenotazioni');
        self::assertLessThanOrEqual(14, $conMolte, 'e comunque poche');
    }

    public function testIlNumeroDiQueryDellElencoNonCresceConLeScuole(): void
    {
        $this->pren(200, ['scuola_codice' => 'CSPS00001A']);
        $a = Database::numeroQuery();
        $this->elenco()->righe(2026);
        $poche = Database::numeroQuery() - $a;

        for ($i = 0; $i < 40; $i++) {
            $this->pren($i % 2 ? 100 : 200, ['scuola_codice' => $i % 2 ? 'CSPS00002B' : 'CSTF00003C']);
        }
        $b = Database::numeroQuery();
        $righe = $this->elenco()->righe(2026);
        $molte = Database::numeroQuery() - $b;

        self::assertCount(3, $righe);
        self::assertSame($poche, $molte);
    }

    public function testElencoOrdineStatoEFiltri(): void
    {
        $this->convenzione('CSPS00001A', '2026-01-01', '2026-11-20');          // in scadenza entro 60 giorni, e ha un'attività coperta
        $this->pren(100, ['scuola_codice' => 'CSPS00001A']);
        $this->pren(200, ['scuola_codice' => 'CSPS00002B']);                    // Fermi: attività FSL senza convenzione → da fare
        $this->convenzione('CSTF00003C', '2020-01-01', '2021-01-01');           // Volta: tutta scaduta, nessuna attività
        $this->db->esegui("INSERT INTO convenzioni_compilate (token, scuola_codice, email, stato_iter, dati_json) VALUES ('t2', 'CSPS00001A', 'x@y.it', 'firmata_direttore', '{\"attivita\":[]}')");

        $righe = $this->elenco()->righe(2026);
        $per = array_column($righe, null, 'chiave');

        self::assertSame(['CSIS00001A', 'CSIS00002B', 'CSTF00003C'], array_column($righe, 'chiave'), 'prima chi ha da fare (Galilei: da protocollare, Fermi: senza convenzione), poi i scaduti');
        self::assertSame('in_scadenza', $per['CSIS00001A']['stato']);
        self::assertContains('da_protocollare', $per['CSIS00001A']['tags']);
        self::assertSame('manca', $per['CSIS00002B']['stato']);
        self::assertContains('senza_convenzione', $per['CSIS00002B']['tags']);
        self::assertStringContainsString('Avvia la stipula', $per['CSIS00002B']['cosa']);
        self::assertSame('scaduta', $per['CSTF00003C']['stato']);
        self::assertSame(['da_protocollare' => 1, 'senza_convenzione' => 1, 'scaduta_nessuna_attivita' => 1], array_filter(ElencoScuoleFsl::tessere($righe)), 'una tessera per ogni cosa da fare, anche per la convenzione scaduta senza attività');
        self::assertContains('scaduta_nessuna_attivita', $per['CSTF00003C']['tags']);
        self::assertSame('Scaduta, nessuna attività', $per['CSTF00003C']['cosa'], 'mai «Niente da fare» per una convenzione scaduta');
        self::assertSame(['CSIS00002B'], array_column(ElencoScuoleFsl::filtra($righe, '', 'senza_convenzione', ''), 'chiave'));
        self::assertSame(['CSTF00003C'], array_column(ElencoScuoleFsl::filtra($righe, 'scaduta', '', ''), 'chiave'));
        self::assertSame(['CSIS00001A'], array_column(ElencoScuoleFsl::filtra($righe, '', '', 'galilei'), 'chiave'));
        self::assertSame(1, $per['CSIS00001A']['eventi']);
        self::assertSame(12, $per['CSIS00001A']['studenti']);
    }

    public function testUnaCompilazioneAvviataSenzaDatiSiVedeENonRaddoppiaLaScheda(): void
    {
        $this->db->esegui("INSERT INTO convenzioni_compilate (token, scuola_codice, email, avviata_il, avviata_da) VALUES ('t3', 'CSPS00002B', 'ref@scuola.it', NOW(), 'ufficio@unical.it')");

        $s = $this->scheda()->scheda('CSPS00002B', 0, true);

        self::assertSame('in_corso', $s['stato']);
        self::assertSame('sollecita', $s['azione']['chiave'], 'tocca alla scuola: si può solo sollecitare');
        self::assertNotNull($s['iter']);
        self::assertSame('ref@scuola.it', $s['iter']['email']);
        self::assertSame('ufficio@unical.it', $s['iter']['avviata_da']);
    }
}
