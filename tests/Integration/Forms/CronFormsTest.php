<?php

declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Auth\UtenteRepository;
use App\Core\Sito;
use App\Forms\CronForms;
use App\Forms\FormRepository;
use App\Forms\NotificheForms;
use App\Forms\RispostaRepository;
use App\Forms\ServizioForms;
use App\Forms\ServizioRisposte;
use App\Infrastructure\Audit\AuditLog;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;

/**
 * I compiti automatici di MyForms con un orologio finto: chiusura a fine periodo o al numero massimo (email al proprietario una volta sola) e
 * conservazione delle risposte (avviso 7 giorni prima, cancellazione solo dopo l'avviso e solo di ciò che era nell'avviso).
 * Oggi finto: 17/11/2026 alle 10:00.
 */
final class CronFormsTest extends FormsBase
{
    private const OGGI = '2026-11-17 10:00:00';

    private MailerFinto $mailer;

    protected function tabelle(): array
    {
        return array_merge(parent::tabelle(), [
            'log_attivita' => 'CREATE TABLE log_attivita (id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT, azione VARCHAR(255), dettagli_json TEXT NULL, indirizzo_ip VARCHAR(45), data_ora DATETIME DEFAULT CURRENT_TIMESTAMP)',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new MailerFinto();
    }

    protected function tearDown(): void
    {
        $radice = dirname(__DIR__, 3) . '/' . $this->cartella;
        foreach (glob($radice . '*', GLOB_ONLYDIR) ?: [] as $sotto) {
            foreach (glob($sotto . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($sotto);
        }
        parent::tearDown();
    }

    /** Il cron come se fosse quel giorno e quell'ora (un orologio diverso per ogni chiamata, stessa base dati e stessa casella email finta). */
    private function cron(string $quando): CronForms
    {
        return new CronForms(
            $this->servizio(FormRepository::class), $this->servizio(RispostaRepository::class), $this->servizio(ServizioRisposte::class),
            new NotificheForms($this->mailer, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi')), $this->servizio(UtenteRepository::class),
            new AuditLog($this->db), new OrologioFisso($quando)
        );
    }

    /** @return array<string, mixed> */
    private function riga(int $id): array
    {
        return $this->servizio(FormRepository::class)->perId($id);
    }

    /** Un form già aperto. @param array<string, mixed> $opzioni */
    private function formAperto(string $titolo, array $opzioni = []): int
    {
        $id = $this->form($titolo, [['etichetta' => 'Città', 'tipo' => 'text']], $opzioni);
        $this->servizio(ServizioForms::class)->apri($id);

        return $id;
    }

    /** Una risposta già salvata nel form, inviata in un momento preciso. @param list<array<string, mixed>>|null $risposte */
    private function risposta(int $formId, string $quando, ?array $risposte = null, ?int $utente = 2): int
    {
        $risposte ??= [['etichetta' => 'Città', 'tipo' => 'text', 'valore' => 'Cosenza', 'file' => null, 'nome_file' => null]];
        $id = $this->servizio(RispostaRepository::class)->inserisci($formId, $utente, null, 'studenti', (string) json_encode($risposte, JSON_UNESCAPED_UNICODE), bin2hex(random_bytes(16)), null, null);
        $this->db->esegui('UPDATE forms_risposte SET inviata_il = ? WHERE id = ?', [$quando, $id]);

        return $id;
    }

    private function quante(int $formId): int
    {
        return $this->servizio(RispostaRepository::class)->conta($formId);
    }

    // ------------------------------------------------------------------ chiusura automatica

    public function testChiudeIlFormScadutoEAvvisaIlProprietarioUnaVoltaSola(): void
    {
        $id = $this->formAperto('Sondaggio sulle aule', ['aperta_al' => '2026-11-16 18:00:00']);
        $this->risposta($id, '2026-11-10 09:00:00');
        $this->risposta($id, '2026-11-12 09:00:00', null, 4);
        $this->risposta($id, '2026-11-15 09:00:00', null, 3);

        self::assertSame(1, $this->cron(self::OGGI)->chiudiScaduti());

        $f = $this->riga($id);
        self::assertSame('chiusa', $f['stato']);
        self::assertSame(self::OGGI, $f['chiusura_avvisata_il']);
        self::assertCount(1, $this->mailer->inviate);
        $m = $this->mailer->inviate[0];
        self::assertSame('admin@x.it', $m['a'], 'va al proprietario');
        self::assertStringContainsString('Sondaggio sulle aule', $m['oggetto']);
        self::assertStringContainsString('<strong>3</strong> risposte', $m['corpo']);
        self::assertStringContainsString('è finito il periodo di apertura', $m['corpo']);
        self::assertStringContainsString('https://portale.test/eventi/admin/forms_risposte.php?id=' . $id, $m['corpo']);

        self::assertSame(0, $this->cron(self::OGGI)->chiudiScaduti(), 'già chiuso: niente di nuovo');
        self::assertCount(1, $this->mailer->inviate, 'una email sola');
    }

    public function testNonChiudeUnFormNelPeriodoNeUnoSenzaScadenza(): void
    {
        $nelPeriodo = $this->formAperto('Nel periodo', ['aperta_dal' => '2026-11-01 00:00:00', 'aperta_al' => '2026-11-30 23:59:00']);
        $senza = $this->formAperto('Senza scadenza');
        $this->risposta($senza, '2026-11-10 09:00:00');

        self::assertSame(0, $this->cron(self::OGGI)->chiudiScaduti());

        self::assertSame('aperta', $this->riga($nelPeriodo)['stato']);
        self::assertSame('aperta', $this->riga($senza)['stato']);
        self::assertSame([], $this->mailer->inviate);
    }

    public function testSiChiudeAlNumeroMassimoDiRisposte(): void
    {
        $pieno = $this->formAperto('Posti limitati', ['max_risposte' => 2]);
        $this->risposta($pieno, '2026-11-10 09:00:00');
        $this->risposta($pieno, '2026-11-11 09:00:00', null, 4);
        $quasi = $this->formAperto('Quasi pieno', ['max_risposte' => 3]);
        $this->risposta($quasi, '2026-11-10 09:00:00');

        self::assertSame(1, $this->cron(self::OGGI)->chiudiScaduti());

        self::assertSame('chiusa', $this->riga($pieno)['stato']);
        self::assertSame('aperta', $this->riga($quasi)['stato']);
        self::assertCount(1, $this->mailer->inviate);
        self::assertStringContainsString('ha raggiunto il numero massimo di risposte (2)', $this->mailer->inviate[0]['corpo']);
        self::assertStringContainsString('<strong>2</strong> risposte', $this->mailer->inviate[0]['corpo']);
    }

    public function testUnaBozzaOUnFormGiaChiusoNonSiTocca(): void
    {
        $bozza = $this->form('Ancora in bozza', [['etichetta' => 'Città', 'tipo' => 'text']], ['aperta_al' => '2026-11-01 00:00:00']);
        $chiuso = $this->formAperto('Chiuso a mano', ['aperta_al' => '2026-11-01 00:00:00']);
        $this->servizio(ServizioForms::class)->chiudi($chiuso);

        self::assertSame(0, $this->cron(self::OGGI)->chiudiScaduti());

        self::assertSame('bozza', $this->riga($bozza)['stato']);
        self::assertNull($this->riga($chiuso)['chiusura_avvisata_il'], 'chiuso a mano non è «chiuso dal cron»');
        self::assertSame([], $this->mailer->inviate);
    }

    public function testUnFormRiapertoDalProprietarioNonSiRichiudeDaSolo(): void
    {
        $id = $this->formAperto('Da riaprire', ['aperta_al' => '2026-11-16 18:00:00']);
        $this->cron(self::OGGI)->chiudiScaduti();
        self::assertSame('chiusa', $this->riga($id)['stato']);

        $this->servizio(ServizioForms::class)->apri($id);

        self::assertSame(0, $this->cron('2026-11-17 10:15:00')->chiudiScaduti());
        self::assertSame('aperta', $this->riga($id)['stato'], 'chiusura automatica una sola volta per form');
        self::assertCount(1, $this->mailer->inviate);
    }

    public function testIlFormSiChiudeAncheSeIlProprietarioNonHaEmail(): void
    {
        $this->db->esegui("INSERT INTO utenti (id, nome, cognome, email, ruolo_id) VALUES (9, 'Senza', 'Posta', '', 5)");
        $id = $this->formAperto('Senza posta', ['aperta_al' => '2026-11-16 18:00:00', 'proprietario_id' => 9]);
        $vecchio = ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'log'));

        try {
            self::assertSame(1, $this->cron(self::OGGI)->chiudiScaduti());
        } finally {
            ini_set('error_log', (string) $vecchio);
        }

        self::assertSame('chiusa', $this->riga($id)['stato']);
        self::assertSame([], $this->mailer->inviate);
    }

    // ------------------------------------------------------------------ conservazione

    public function testLaPrimaVoltaSiAvvisaSoltantoENonSiCancellaNulla(): void
    {
        $id = $this->formAperto('Raccolta dati', ['conserva_mesi' => 3]);
        $vecchia = $this->risposta($id, '2026-05-01 09:00:00');
        $inScadenza = $this->risposta($id, '2026-08-20 09:00:00');
        $this->risposta($id, '2026-10-01 09:00:00');

        $r = $this->cron(self::OGGI)->conservazione();

        self::assertSame(['avvisi' => 1, 'cancellate' => 0], $r);
        self::assertSame(3, $this->quante($id), 'niente di cancellato senza preavviso');
        $f = $this->riga($id);
        self::assertSame(self::OGGI, $f['conserva_avvisata_il']);
        self::assertSame('2026-08-24 10:00:00', $f['conserva_fino_al'], 'le risposte inviate prima di questa data sono nell\'avviso (oggi + 7 giorni - 3 mesi)');
        self::assertCount(1, $this->mailer->inviate);
        $m = $this->mailer->inviate[0];
        self::assertSame('admin@x.it', $m['a']);
        self::assertStringContainsString('Tra 7 giorni', $m['oggetto']);
        self::assertStringContainsString('<strong>2</strong> risposte', $m['corpo']);
        self::assertStringContainsString('24/11/2026', $m['corpo'], 'il giorno in cui si cancella');
        self::assertStringContainsString('24/08/2026', $m['corpo'], 'inviate prima di questa data');
        self::assertNotNull($this->servizio(RispostaRepository::class)->perId($vecchia));
        self::assertNotNull($this->servizio(RispostaRepository::class)->perId($inScadenza));
    }

    public function testDopoSetteGiorniSiCancellaSoloCioCheEraNellAvviso(): void
    {
        $id = $this->formAperto('Raccolta dati', ['conserva_mesi' => 3]);
        $vecchia = $this->risposta($id, '2026-05-01 09:00:00');
        $inScadenza = $this->risposta($id, '2026-08-20 09:00:00');
        $recente = $this->risposta($id, '2026-10-01 09:00:00');
        $this->cron(self::OGGI)->conservazione();

        // dopo 3 giorni l'avviso è ancora «in attesa»: né cancellazioni né un secondo avviso
        self::assertSame(['avvisi' => 0, 'cancellate' => 0], $this->cron('2026-11-20 10:00:00')->conservazione());
        self::assertSame(3, $this->quante($id));
        self::assertCount(1, $this->mailer->inviate);

        // dopo 6 giorni e 23 ore ancora no
        self::assertSame(['avvisi' => 0, 'cancellate' => 0], $this->cron('2026-11-24 09:59:00')->conservazione());
        self::assertSame(3, $this->quante($id));

        // passati 7 giorni: via le due annunciate, resta la recente
        self::assertSame(['avvisi' => 0, 'cancellate' => 2], $this->cron('2026-11-24 10:00:00')->conservazione());
        $repo = $this->servizio(RispostaRepository::class);
        self::assertNull($repo->perId($vecchia));
        self::assertNull($repo->perId($inScadenza));
        self::assertNotNull($repo->perId($recente));
        self::assertCount(1, $this->mailer->inviate, 'nessun nuovo avviso: la risposta recente è lontana dalla scadenza');
    }

    public function testLaCancellazioneVieneRegistrataNelRegistroDelleAttivita(): void
    {
        $id = $this->formAperto('Raccolta dati', ['conserva_mesi' => 3, 'proprietario_id' => 3]);
        $this->risposta($id, '2026-05-01 09:00:00');
        $this->cron(self::OGGI)->conservazione();
        $this->cron('2026-11-24 10:00:00')->conservazione();

        $righe = $this->db->righe('SELECT * FROM log_attivita');
        self::assertCount(1, $righe);
        self::assertSame(3, (int) $righe[0]['utente_id'], 'nel cron non c\'è una sessione: intestata al proprietario del form');
        self::assertSame('MyForms: risposte cancellate (conservazione automatica)', $righe[0]['azione']);
        $d = json_decode((string) $righe[0]['dettagli_json'], true);
        self::assertSame($id, $d['Form']);
        self::assertSame(1, $d['Risposte']);
        self::assertTrue($d['automatico']);
        self::assertSame('cron', $righe[0]['indirizzo_ip']);
    }

    public function testGliAllegatiDelleRisposteCancellateSparisconoDalDisco(): void
    {
        $id = $this->formAperto('Con allegati', ['conserva_mesi' => 3]);
        $dir = dirname(__DIR__, 3) . '/' . $this->cartella . $id . '/';
        @mkdir($dir, 0755, true);
        file_put_contents($dir . 'vecchio.pdf', '%PDF-1.4 vecchio');
        file_put_contents($dir . 'nuovo.pdf', '%PDF-1.4 nuovo');
        $this->risposta($id, '2026-05-01 09:00:00', [['etichetta' => 'Documento', 'tipo' => 'file', 'valore' => 'vecchio.pdf', 'file' => $this->cartella . $id . '/vecchio.pdf', 'nome_file' => 'vecchio.pdf']]);
        $this->risposta($id, '2026-11-10 09:00:00', [['etichetta' => 'Documento', 'tipo' => 'file', 'valore' => 'nuovo.pdf', 'file' => $this->cartella . $id . '/nuovo.pdf', 'nome_file' => 'nuovo.pdf']]);

        $this->cron(self::OGGI)->conservazione();
        self::assertFileExists($dir . 'vecchio.pdf', 'prima del preavviso resta tutto');
        $this->cron('2026-11-24 10:00:00')->conservazione();

        self::assertFileDoesNotExist($dir . 'vecchio.pdf');
        self::assertFileExists($dir . 'nuovo.pdf');
        self::assertSame(1, $this->quante($id));
    }

    public function testCambiareIMesiDopoL_avvisoNonCancellaOltreCioCheEraAnnunciato(): void
    {
        $id = $this->formAperto('Raccolta dati', ['conserva_mesi' => 3]);
        $this->risposta($id, '2026-05-01 09:00:00');
        $media = $this->risposta($id, '2026-10-01 09:00:00');
        $this->cron(self::OGGI)->conservazione(); // avviso solo per la risposta di maggio

        // il proprietario abbassa la conservazione a 1 mese: quella di ottobre sarebbe «vecchia», ma nessuno l'ha avvisato per lei
        $this->servizio(ServizioForms::class)->modifica($id, $this->datiModifica($this->riga($id), ['conserva_mesi' => 1]));

        $r = $this->cron('2026-11-24 10:00:00')->conservazione();

        self::assertSame(1, $r['cancellate'], 'solo quella di maggio');
        self::assertNotNull($this->servizio(RispostaRepository::class)->perId($media));
        self::assertSame(1, $r['avvisi'], 'ora l\'avviso parte per la risposta di ottobre');
        self::assertCount(2, $this->mailer->inviate);

        // ... e la settimana dopo, finito il nuovo preavviso, si cancella
        self::assertSame(1, $this->cron('2026-12-01 10:00:00')->conservazione()['cancellate']);
        self::assertNull($this->servizio(RispostaRepository::class)->perId($media));
    }

    public function testUnFormSenzaConservazioneNonSiTocca(): void
    {
        $senza = $this->formAperto('Senza durata');
        $this->risposta($senza, '2020-01-01 09:00:00');
        $zero = $this->formAperto('Durata zero', ['conserva_mesi' => 0]);
        $this->risposta($zero, '2020-01-01 09:00:00');

        self::assertSame(['avvisi' => 0, 'cancellate' => 0], $this->cron(self::OGGI)->conservazione());
        self::assertSame(['avvisi' => 0, 'cancellate' => 0], $this->cron('2027-06-01 10:00:00')->conservazione());

        self::assertSame(1, $this->quante($senza));
        self::assertSame(1, $this->quante($zero));
        self::assertSame([], $this->mailer->inviate);
    }

    public function testNientePiuDaAvvisareQuandoNonCiSonoRisposteInScadenza(): void
    {
        $id = $this->formAperto('Giovane', ['conserva_mesi' => 12]);
        $this->risposta($id, '2026-10-01 09:00:00');

        self::assertSame(['avvisi' => 0, 'cancellate' => 0], $this->cron(self::OGGI)->conservazione());

        self::assertNull($this->riga($id)['conserva_avvisata_il']);
        self::assertSame([], $this->mailer->inviate);
    }

    public function testCronRiassumeCioCheHaFatto(): void
    {
        self::assertSame('', $this->cron(self::OGGI)->cron(), 'niente da fare: nessuna riga');
        $id = $this->formAperto('Scaduto', ['aperta_al' => '2026-11-16 18:00:00', 'conserva_mesi' => 1]);
        $this->risposta($id, '2026-08-01 09:00:00');

        $testo = $this->cron(self::OGGI)->cron();

        self::assertStringContainsString('1 moduli chiusi da soli', $testo);
        self::assertStringContainsString('1 avvisi ai proprietari, 0 risposte cancellate', $testo);
    }
}
