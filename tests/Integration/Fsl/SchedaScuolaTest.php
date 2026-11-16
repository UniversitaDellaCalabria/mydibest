<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Fsl\ServizioSchedaScuola;
use App\Fsl\Vista\SchedaScuola;

/** Scheda completa di una scuola: prenotazioni di ogni tipo, convenzioni del registro e compilate online, persone, avvisi. */
final class SchedaScuolaTest extends FslBase
{
    protected function tabelle(): array
    {
        return [
            ...parent::tabelle(),
            'convenzioni_messaggi' => "CREATE TABLE convenzioni_messaggi (id INT AUTO_INCREMENT PRIMARY KEY, compilazione_id INT NOT NULL, da VARCHAR(10) NOT NULL, tipo VARCHAR(20) NOT NULL DEFAULT 'messaggio',
                testo VARCHAR(2000) NOT NULL DEFAULT '', autore VARCHAR(150) NOT NULL DEFAULT '', creato_il DATETIME DEFAULT CURRENT_TIMESTAMP, letto_il DATETIME NULL)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // colonne che la base FSL non ha (usate solo dalla scheda della scuola)
        $this->db->esegui('ALTER TABLE utenti ADD COLUMN nome VARCHAR(100) NULL, ADD COLUMN cognome VARCHAR(100) NULL');
        $this->db->esegui("ALTER TABLE pagine_eventi ADD COLUMN tipo_area VARCHAR(20) NOT NULL DEFAULT ''");
        $this->db->esegui('ALTER TABLE prenotazioni ADD COLUMN solo_orientamento TINYINT NOT NULL DEFAULT 0');
        $this->db->esegui('ALTER TABLE partecipanti_prenotazione ADD COLUMN escluso TINYINT NOT NULL DEFAULT 0');
        $this->db->esegui("UPDATE pagine_eventi SET tipo_area = 'fsl' WHERE id = 2");
    }

    private function scheda(string $codice = 'CSPS00001A'): ?array
    {
        return $this->servizio(ServizioSchedaScuola::class)->scheda($codice);
    }

    public function testUnCodiceNonValidoOUnaScuolaSenzaNullaNonHaScheda(): void
    {
        $this->assertNull($this->scheda('x'));
        $this->assertNull($this->scheda('ZZZZ00000Z'), 'né anagrafe, né prenotazioni, né convenzioni');
        $s = $this->scheda('CSPS00002B');
        $this->assertNotNull($s, 'la scuola dell\'anagrafe ha la scheda anche se vuota');
        $this->assertSame(['nessuna', 0, 0], [$s['sintesi']['stato'], $s['sintesi']['prenotazioni'], $s['sintesi']['convenzioni']]);
        $this->assertStringContainsString('Nessuna convenzione nel registro', SchedaScuola::html($s, static fn (string $t, string $e = ''): string => "fsl.php?tab=$t$e"));
    }

    public function testTuttePrenotazioniAncheNonFslAnnullateEPassate(): void
    {
        $this->pren(200);                                                    // FSL, in corso
        $this->pren(201, ['stato' => 'annullata']);                          // annullata
        $this->pren(100, ['codice_prenotazione' => 'OP-1']);                 // evento FSL del 23/10/2026
        $this->db->esegui('UPDATE progetti_dettagli SET convenzione = 0 WHERE evento_id = 10');
        $this->pren(200, ['scuola_codice' => 'CSPS00002B']);                 // altra scuola

        $s = $this->scheda();

        $this->assertCount(3, $s['prenotazioni'], 'solo quelle della scuola, di ogni tipo e stato');
        $per = array_column($s['prenotazioni'], null, 'codice');
        $this->assertTrue($per['OP-1']['attiva'] && !$per['OP-1']['fsl']);
        $this->assertSame('non_richiesta', $per['OP-1']['conv']);
        $this->assertFalse($per['FS-2']['attiva']);
        $this->assertSame(2, $s['sintesi']['attive'], 'l\'annullata non conta');
        $this->assertSame(1, $s['sintesi']['fsl_attive']);
        $this->assertSame(24, $s['sintesi']['studenti'], 'gli studenti delle prenotazioni attive non concluse, FSL e non');
    }

    public function testLaCopertaEDeterminataDalRegistroNonDaQuantoDichiaratoDallaScuola(): void
    {
        // La scuola dice «si» ma nel registro c'è solo una convenzione scaduta: è da stipulare
        $this->pren(200, ['convenzione' => 'si']);
        $this->convenzione('CSPS00001A', '2025-01-01', '2026-01-01');

        $s = $this->scheda();

        $this->assertSame('non_coperta', $s['prenotazioni'][0]['conv']);
        $this->assertSame('da_stipulare', $s['sintesi']['stato']);
        $this->assertTrue($s['sintesi']['documenti']);
        $this->assertStringContainsString('la scuola dice di averla', implode(' ', array_column($s['avvisi'], 'testo')));

        $valida = $this->convenzione('CSPS00001A', '2026-09-01', '2027-12-31');
        $s = $this->scheda();
        $this->assertSame(['coperta', $valida, 'ok'], [$s['prenotazioni'][0]['conv'], $s['prenotazioni'][0]['conv_id'], $s['sintesi']['stato']]);
        $this->assertCount(2, $s['convenzioni'], 'anche la scaduta');
        $this->assertSame('scaduta', array_column($s['convenzioni'], 'stato', 'id')[$valida - 1]);
    }

    public function testUnProgettoInUnAreaFslConInterruttoreSpentoESegnalato(): void
    {
        $this->db->esegui('UPDATE progetti_dettagli SET convenzione = 0 WHERE evento_id = 20');
        $this->pren(200);

        $s = $this->scheda();

        $this->assertTrue($s['prenotazioni'][0]['fsl_spenta']);
        $this->assertFalse($s['prenotazioni'][0]['fsl']);
        $this->assertSame(0, $s['sintesi']['fsl_attive']);
        $this->assertStringContainsString('interruttore «Convenzione» spento', $s['avvisi'][0]['testo']);
        $html = SchedaScuola::html($s, static fn (string $t, string $e = ''): string => "fsl.php?tab=$t$e");
        $this->assertStringContainsString('progetti.php?p_id=2&amp;id=20', $html);
    }

    public function testConvenzioneSenzaFileCompilazioniEPersone(): void
    {
        $this->pren(200, ['nome' => 'Anna', 'cognome' => 'Verdi', 'email' => 'anna@prova.it']);
        $this->pren(201, ['nome' => 'Anna', 'cognome' => 'Verdi', 'email' => 'ANNA@prova.it']);
        $id = $this->convenzione('CSPS00001A', '2026-09-01', '2027-08-31', ['docenti_json' => '[{"nome":"Bruno Neri","email":"bruno@prova.it"}]']);
        $con = $this->convenzione('CSPS00001A', '2026-01-01', '2026-12-31');
        $this->db->esegui("UPDATE convenzioni_scuole SET file_convenzione = 'uploads/convenzioni/a.pdf' WHERE id = ?", [$con]);
        $this->db->esegui("INSERT INTO utenti (id, email, ruolo_id, scuola_codice, nome, cognome) VALUES (7, 'segreteria@prova.it', 5, 'CSPS00001A', 'Carla', 'Gialli')");
        $this->db->esegui("INSERT INTO convenzioni_compilate (token, scuola_codice, email, dati_json, stato_iter, file_firmato) VALUES ('t1', 'CSPS00001A', 'anna@prova.it', '{}', 'caricata_scuola', 'uploads/convenzioni/f.pdf')");
        $comp = (int) $this->db->valore('SELECT MAX(id) FROM convenzioni_compilate');
        $this->db->esegui("INSERT INTO convenzioni_messaggi (compilazione_id, da, testo) VALUES (?, 'scuola', 'Ciao'), (?, 'ufficio', 'Salve')", [$comp, $comp]);

        $s = $this->scheda();

        $testi = implode(' | ', array_column($s['avvisi'], 'testo'));
        $this->assertStringContainsString('La convenzione n. ' . $id . ' è nel registro ma senza file', $testi);
        $this->assertStringNotContainsString('n. ' . $con . ' è nel registro ma senza file', $testi, 'ha almeno un file');
        $this->assertStringContainsString('ha caricato il PDF firmato', $testi);
        $this->assertStringContainsString('1 messaggio della scuola da leggere', $testi);
        $this->assertCount(1, $s['compilazioni']);
        $this->assertSame([true, 2, 1], [$s['compilazioni'][0]['file_scuola'], $s['compilazioni'][0]['messaggi'], $s['compilazioni'][0]['non_letti']]);

        $persone = array_column($s['referenti'], null, 'email');
        $this->assertCount(3, $persone, 'la stessa email in maiuscolo non si duplica');
        $this->assertSame(2, $persone['anna@prova.it']['prenotazioni']);
        $this->assertSame('Bruno Neri', $persone['bruno@prova.it']['nome']);
        $this->assertSame('profilo', $persone['segreteria@prova.it']['fonte']);
        $html = SchedaScuola::html($s, static fn (string $t, string $e = ''): string => "fsl.php?tab=$t$e");
        $this->assertStringContainsString('convenzione_file.php?c=' . $comp, $html);
        $this->assertStringContainsString('Allegato A mancante', $html);
    }
}
