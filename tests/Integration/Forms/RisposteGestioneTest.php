<?php

declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Forms\EsportazioneRisposte;
use App\Forms\FiltriRisposte;
use App\Forms\FormRepository;
use App\Forms\RispostaRepository;
use App\Forms\ServizioForms;
use App\Forms\ServizioRisposte;

/** Le risposte viste da chi gestisce il form: filtri, form anonimi, appartenenza degli allegati, eliminazione, ZIP. */
final class RisposteGestioneTest extends FormsBase
{
    private const NESSUN_FILTRO = ['q' => '', 'dal' => '', 'al' => '', 'profilo' => ''];

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

    /** @return array<string, mixed> */
    private function formDi(int $id): array
    {
        return $this->servizio(FormRepository::class)->perId($id);
    }

    /** Una risposta già salvata nel form, in un giorno preciso. @param list<array<string, mixed>> $risposte */
    private function inserisci(int $formId, array $risposte, ?int $utente, string $profilo, string $quando, ?string $email = null): int
    {
        $id = $this->servizio(RispostaRepository::class)->inserisci($formId, $utente, $email, $profilo, (string) json_encode($risposte, JSON_UNESCAPED_UNICODE), bin2hex(random_bytes(16)), null, null);
        $this->db->esegui('UPDATE forms_risposte SET inviata_il = ? WHERE id = ?', [$quando, $id]);

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function testo(string $valore): array
    {
        return [['etichetta' => 'Città', 'tipo' => 'text', 'valore' => $valore, 'file' => null, 'nome_file' => null]];
    }

    /** Crea un file di allegato nella cartella di un form e ritorna la risposta con quel file. @return list<array<string, mixed>> */
    private function conAllegato(int $formIdCartella, string $nomeFile, string $contenuto = '%PDF-1.4 prova'): array
    {
        $dir = dirname(__DIR__, 3) . '/' . $this->cartella . $formIdCartella . '/';
        @mkdir($dir, 0755, true);
        file_put_contents($dir . $nomeFile, $contenuto);

        return [['etichetta' => 'Documento', 'tipo' => 'file', 'valore' => 'originale.pdf', 'file' => $this->cartella . $formIdCartella . '/' . $nomeFile, 'nome_file' => 'originale.pdf']];
    }

    public function testFiltroPerPeriodoCompresiIGiorniEstremi(): void
    {
        $id = $this->form('Sondaggio', [['etichetta' => 'Città', 'tipo' => 'text']]);
        $this->inserisci($id, $this->testo('prima'), 2, 'studenti', '2026-10-31 23:59:59');
        $dentro1 = $this->inserisci($id, $this->testo('primo giorno'), 2, 'studenti', '2026-11-01 00:00:00');
        $dentro2 = $this->inserisci($id, $this->testo('ultimo giorno'), 2, 'studenti', '2026-11-30 23:59:59');
        $this->inserisci($id, $this->testo('dopo'), 2, 'studenti', '2026-12-01 00:00:01');

        $righe = $this->servizio(ServizioRisposte::class)->elenco($this->formDi($id), ['dal' => '2026-11-01', 'al' => '2026-11-30'] + self::NESSUN_FILTRO);

        self::assertSame([$dentro2, $dentro1], array_map(static fn (array $r): int => (int) $r['id'], $righe), 'dalla più recente');
    }

    public function testFiltroPerProfiloEProfiliPresenti(): void
    {
        $id = $this->form('Sondaggio', [['etichetta' => 'Città', 'tipo' => 'text']]);
        $this->inserisci($id, $this->testo('a'), 2, 'studenti', '2026-11-02 10:00:00');
        $doc = $this->inserisci($id, $this->testo('b'), 3, 'docenti', '2026-11-03 10:00:00');
        $this->inserisci($id, $this->testo('c'), null, 'anonimi', '2026-11-04 10:00:00');
        $servizio = $this->servizio(ServizioRisposte::class);
        $form = $this->formDi($id);

        self::assertSame(['anonimi', 'docenti', 'studenti'], $servizio->profiliPresenti($form));
        $righe = $servizio->elenco($form, ['profilo' => 'docenti'] + self::NESSUN_FILTRO);
        self::assertSame([$doc], array_map(static fn (array $r): int => (int) $r['id'], $righe));
    }

    public function testRicercaNelTestoTrovaPerValoreENomeMaNonInUnFormAnonimo(): void
    {
        $id = $this->form('Sondaggio', [['etichetta' => 'Città', 'tipo' => 'text']]);
        $this->inserisci($id, $this->testo('Cosenza'), 2, 'studenti', '2026-11-02 10:00:00', 'luca@x.it');
        $this->inserisci($id, $this->testo('Rende'), 3, 'docenti', '2026-11-03 10:00:00', 'maria@x.it');
        $servizio = $this->servizio(ServizioRisposte::class);
        $form = $this->formDi($id);

        self::assertCount(1, $servizio->elenco($form, ['q' => 'cosenza'] + self::NESSUN_FILTRO));
        self::assertCount(1, $servizio->elenco($form, ['q' => 'Docente'] + self::NESSUN_FILTRO), 'per cognome (Maria Docente)');
        self::assertCount(1, $servizio->elenco($form, ['q' => 'luca@x.it'] + self::NESSUN_FILTRO), 'per email');

        $this->servizio(ServizioForms::class)->modifica($id, $this->datiModifica($form, ['anonima' => 1]));
        $anonimo = $this->formDi($id);
        self::assertCount(1, $servizio->elenco($anonimo, ['q' => 'cosenza'] + self::NESSUN_FILTRO));
        self::assertCount(0, $servizio->elenco($anonimo, ['q' => 'Docente'] + self::NESSUN_FILTRO), 'nel form anonimo non si cerca per nome');
        self::assertCount(0, $servizio->elenco($anonimo, ['q' => 'luca@x.it'] + self::NESSUN_FILTRO), 'né per email');
    }

    public function testNeiFormAnonimiNonEsceNessunDatoDiChiHaRisposto(): void
    {
        // Il form è reso anonimo dopo che c'erano già risposte con utente ed email: i dati vecchi non devono comparire lo stesso
        $id = $this->form('Sondaggio', [['etichetta' => 'Città', 'tipo' => 'text']]);
        $rid = $this->inserisci($id, $this->testo('Cosenza'), 2, 'studenti', '2026-11-02 10:00:00', 'luca@x.it');
        $servizio = $this->servizio(ServizioRisposte::class);
        $this->servizio(ServizioForms::class)->modifica($id, $this->datiModifica($this->formDi($id), ['anonima' => 1]));
        $form = $this->formDi($id);

        $righe = $servizio->elenco($form, self::NESSUN_FILTRO);
        foreach (['utente_id', 'email', 'nome', 'cognome', 'utente_impronta', 'ip_impronta'] as $k) {
            self::assertArrayNotHasKey($k, $righe[0], "la riga di un form anonimo non deve avere «{$k}»");
        }
        self::assertArrayNotHasKey('email', $servizio->risposta($form, $rid) ?? []);
        $tab = EsportazioneRisposte::tabella($form, $righe, true);
        self::assertSame(['Data e ora', 'Profilo', 'Città'], $tab['intestazioni']);
        self::assertStringNotContainsString('luca', json_encode($tab, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testFormNonAnonimoMostraNomeEdEmail(): void
    {
        $id = $this->form('Sondaggio', [['etichetta' => 'Città', 'tipo' => 'text']]);
        $this->inserisci($id, $this->testo('Cosenza'), 2, 'studenti', '2026-11-02 10:00:00', 'luca@x.it');
        $form = $this->formDi($id);

        $righe = $this->servizio(ServizioRisposte::class)->elenco($form, self::NESSUN_FILTRO);
        $tab = EsportazioneRisposte::tabella($form, $righe);

        self::assertSame(['Data e ora', 'Nome', 'Email', 'Profilo', 'Città'], $tab['intestazioni']);
        self::assertSame('Luca Studente', $tab['righe'][0][1]);
        self::assertSame('luca@x.it', $tab['righe'][0][2]);
    }

    public function testUnaRispostaDiUnAltroFormNonSiVedeNonSiEliminaNonSiScarica(): void
    {
        $a = $this->form('Form A', [['etichetta' => 'Documento', 'tipo' => 'file']]);
        $b = $this->form('Form B', [['etichetta' => 'Documento', 'tipo' => 'file']]);
        $rispostaA = $this->inserisci($a, $this->conAllegato($a, 'aaa.pdf'), 2, 'studenti', '2026-11-02 10:00:00');
        $servizio = $this->servizio(ServizioRisposte::class);

        self::assertNotNull($servizio->risposta($this->formDi($a), $rispostaA));
        self::assertNull($servizio->risposta($this->formDi($b), $rispostaA), 'l\'id è del form A: chi gestisce B non la vede');
        self::assertNull($servizio->allegato($this->formDi($b), $rispostaA, 0));
        self::assertFalse($servizio->elimina($this->formDi($b), $rispostaA));
        self::assertNotNull($this->servizio(RispostaRepository::class)->perId($rispostaA), 'la risposta di A è ancora lì');
        self::assertNotNull($servizio->allegato($this->formDi($a), $rispostaA, 0));
    }

    public function testUnAllegatoFuoriDallaCartellaDelFormNonSiScarica(): void
    {
        $a = $this->form('Form A', [['etichetta' => 'Documento', 'tipo' => 'file']]);
        $b = $this->form('Form B', [['etichetta' => 'Documento', 'tipo' => 'file']]);
        $servizio = $this->servizio(ServizioRisposte::class);

        // La risposta di A punta a un file che sta nella cartella di B (un percorso scritto a mano nel JSON)
        $fileDiB = $this->conAllegato($b, 'segreto-di-b.pdf');
        $r1 = $this->inserisci($a, $fileDiB, 2, 'studenti', '2026-11-02 10:00:00');
        self::assertNull($servizio->allegato($this->formDi($a), $r1, 0), 'il file è nella cartella di un altro form');

        // Percorso con «..», con sottocartella, assoluto, di altra cartella del sito
        $dirA = $this->cartella . $a . '/';
        @mkdir(dirname(__DIR__, 3) . '/' . $dirA . 'sotto', 0755, true);
        file_put_contents(dirname(__DIR__, 3) . '/' . $dirA . 'sotto/x.pdf', 'x');
        foreach ([$dirA . '../' . $b . '/segreto-di-b.pdf', $dirA . 'sotto/x.pdf', '/etc/passwd', 'config.php', 'functions.php', $dirA . '..\\x.pdf', $dirA . 'inesistente.pdf', ''] as $malevolo) {
            $r = $this->inserisci($a, [['etichetta' => 'Documento', 'tipo' => 'file', 'valore' => 'x', 'file' => $malevolo, 'nome_file' => 'x.pdf']], 2, 'studenti', '2026-11-03 10:00:00');
            self::assertNull($servizio->allegato($this->formDi($a), $r, 0), "non deve scaricare «{$malevolo}»");
            self::assertSame([], $servizio->allegati($this->formDi($a), [$this->servizio(RispostaRepository::class)->perId($r)]));
        }
    }

    public function testSoloUnaDomandaDiTipoFileSiScarica(): void
    {
        $a = $this->form('Form A', [['etichetta' => 'Città', 'tipo' => 'text'], ['etichetta' => 'Documento', 'tipo' => 'file']]);
        $dir = $this->cartella . $a . '/';
        $risposte = $this->testo('Cosenza');
        $risposte[0]['file'] = $dir . 'vero.pdf'; // un campo di testo che «punta» a un file: non è un allegato
        @mkdir(dirname(__DIR__, 3) . '/' . $dir, 0755, true);
        file_put_contents(dirname(__DIR__, 3) . '/' . $dir . 'vero.pdf', 'pdf');
        $rid = $this->inserisci($a, $risposte, 2, 'studenti', '2026-11-02 10:00:00');

        self::assertNull($this->servizio(ServizioRisposte::class)->allegato($this->formDi($a), $rid, 0));
    }

    public function testEliminaUnaRispostaCancellaAncheIlFile(): void
    {
        $a = $this->form('Form A', [['etichetta' => 'Documento', 'tipo' => 'file']]);
        $r1 = $this->inserisci($a, $this->conAllegato($a, 'uno.pdf'), 2, 'studenti', '2026-11-02 10:00:00');
        $r2 = $this->inserisci($a, $this->conAllegato($a, 'due.pdf'), 3, 'docenti', '2026-11-03 10:00:00');
        $dir = dirname(__DIR__, 3) . '/' . $this->cartella . $a . '/';
        $servizio = $this->servizio(ServizioRisposte::class);

        self::assertTrue($servizio->elimina($this->formDi($a), $r1));

        self::assertNull($this->servizio(RispostaRepository::class)->perId($r1));
        self::assertFileDoesNotExist($dir . 'uno.pdf');
        self::assertFileExists($dir . 'due.pdf', 'l\'allegato dell\'altra risposta resta');
        self::assertNotNull($this->servizio(RispostaRepository::class)->perId($r2));
    }

    public function testEliminaTutteCancellaRisposteEAllegatiSoloDiQuelForm(): void
    {
        $a = $this->form('Form A', [['etichetta' => 'Documento', 'tipo' => 'file']]);
        $b = $this->form('Form B', [['etichetta' => 'Documento', 'tipo' => 'file']]);
        $this->inserisci($a, $this->conAllegato($a, 'uno.pdf'), 2, 'studenti', '2026-11-02 10:00:00');
        $this->inserisci($a, $this->conAllegato($a, 'due.pdf'), 3, 'docenti', '2026-11-03 10:00:00');
        $rb = $this->inserisci($b, $this->conAllegato($b, 'altro.pdf'), 2, 'studenti', '2026-11-02 10:00:00');
        $radice = dirname(__DIR__, 3) . '/' . $this->cartella;

        self::assertSame(2, $this->servizio(ServizioRisposte::class)->eliminaTutte($this->formDi($a)));

        self::assertSame(0, $this->servizio(RispostaRepository::class)->conta($a));
        self::assertFileDoesNotExist($radice . $a . '/uno.pdf');
        self::assertFileDoesNotExist($radice . $a . '/due.pdf');
        self::assertSame(1, $this->servizio(RispostaRepository::class)->conta($b));
        self::assertNotNull($this->servizio(RispostaRepository::class)->perId($rb));
        self::assertFileExists($radice . $b . '/altro.pdf');
    }

    public function testZipConGliAllegatiDelleRisposteFiltrate(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive non disponibile in questo PHP');
        }
        $a = $this->form('Form A', [['etichetta' => 'Documento', 'tipo' => 'file']]);
        $this->inserisci($a, $this->conAllegato($a, 'uno.pdf', '%PDF uno'), 2, 'studenti', '2026-11-02 10:00:00');
        $this->inserisci($a, $this->conAllegato($a, 'due.pdf', '%PDF due'), 3, 'docenti', '2026-11-03 10:00:00');
        $servizio = $this->servizio(ServizioRisposte::class);
        $form = $this->formDi($a);

        $tmp = $servizio->zip($form, $servizio->elenco($form, self::NESSUN_FILTRO));
        self::assertNotNull($tmp);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp));
        self::assertSame(2, $zip->numFiles);
        $nomi = [$zip->getNameIndex(0), $zip->getNameIndex(1)];
        $zip->close();
        @unlink($tmp);
        foreach ($nomi as $n) {
            self::assertMatchesRegularExpression('/^risposta-\d+_Documento_originale\.pdf$|^risposta-\d+_Documento_originale-2\.pdf$/', (string) $n);
        }

        $solo = $servizio->zip($form, $servizio->elenco($form, ['profilo' => 'docenti'] + self::NESSUN_FILTRO));
        $zip = new \ZipArchive();
        $zip->open((string) $solo);
        self::assertSame(1, $zip->numFiles, 'solo gli allegati delle risposte filtrate');
        $zip->close();
        @unlink((string) $solo);
    }

    public function testZipSenzaAllegatiDaNull(): void
    {
        $a = $this->form('Form A', [['etichetta' => 'Città', 'tipo' => 'text']]);
        $this->inserisci($a, $this->testo('x'), 2, 'studenti', '2026-11-02 10:00:00');
        $servizio = $this->servizio(ServizioRisposte::class);

        self::assertNull($servizio->zip($this->formDi($a), $servizio->elenco($this->formDi($a), self::NESSUN_FILTRO)));
    }

    public function testEsportazioneDelleSoleRisposteFiltrate(): void
    {
        $a = $this->form('Sondaggio', [['etichetta' => 'Città', 'tipo' => 'text']]);
        $this->inserisci($a, $this->testo('Cosenza'), 2, 'studenti', '2026-11-02 10:00:00');
        $this->inserisci($a, $this->testo('=1+1'), 3, 'docenti', '2026-11-03 10:00:00');
        $servizio = $this->servizio(ServizioRisposte::class);
        $form = $this->formDi($a);

        $filtri = FiltriRisposte::da(['profilo' => 'docenti'], $servizio->profiliPresenti($form));
        $tab = EsportazioneRisposte::tabella($form, $servizio->elenco($form, $filtri), true);

        self::assertCount(1, $tab['righe']);
        self::assertSame("'=1+1", $tab['righe'][0][4]);
    }
}
