<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Core\Sito;
use App\Fsl\Costanti;
use App\Fsl\OriginaleDaTitulus;
use App\Fsl\ProtocollaDefinitivaFslInTitulus;
use App\Fsl\ProtocolloAutomaticoConvenzioneFsl;
use App\Fsl\ServizioConvenzioneFirmata;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Infrastructure\Sicurezza\Cifratura;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\ProtocolloRepository;
use App\Tutorato\FirmaRemota;
use Tests\Doppi\FirmaRemotaFinta;
use Tests\Doppi\TitulusFintoFsl;
use Unical\Titulus\FabbricaClienteTitulus;

/**
 * L'iter FSL dopo la firma del Direttore (passo 5): protocollo automatico (acceso, spento, errore, risposta persa, avviso sulla firma), mai due protocolli,
 * file originale ripreso da Titulus (non arrivato, impronta diversa, riprova, caricato a mano), registrazione con il resoconto delle prenotazioni.
 * Titulus è sempre il finto: nessuna chiamata vera.
 */
final class IterConvenzioneFslTest extends FslBase
{
    private string $radiceProva;
    private TitulusFintoFsl $titulus;

    protected function tabelle(): array
    {
        $titulus = [];
        foreach (['titulus_config', 'titulus_regole', 'titulus_cc', 'titulus_invii'] as $t) {
            $titulus[$t] = self::tabellaVera($t);
        }

        return parent::tabelle() + $titulus;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->radiceProva = sys_get_temp_dir() . '/fsl_iter_' . bin2hex(random_bytes(4));
        mkdir($this->radiceProva . '/uploads/convenzioni', 0755, true);
        mkdir($this->radiceProva . '/uploads/convenzioni_compilate/firmate', 0755, true);
        $this->c->istanza(Sito::class, new Sito($this->radiceProva, 'https://portale.test/eventi'));
        $this->c->istanza(FirmaRemota::class, new FirmaRemotaFinta());
        $this->c->istanza(VerificaFirme::class, new class () implements VerificaFirme {
            public function firmePades(string $pdf): array
            {
                return str_contains($pdf, '%%FIRMA') ? [['subfilter' => 'ETSI.CAdES.detached', 'nome' => 'Maria Rossi', 'data' => '', 'valida_struttura' => true, 'copre_tutto' => true]] : [];
            }
        });
        \App\Protocollo\Registrazione::registra($this->c);
        $this->titulus = new TitulusFintoFsl();
        $this->c->istanza(FabbricaClienteTitulus::class, $this->titulus);
        $this->c->istanza(Cifratura::class, new Cifratura('una-frase-lunga-almeno-sedici'));
        $this->db->esegui("INSERT INTO utenti (id, email, ruolo_id) VALUES (1, 'admin@x.it', 1)");
        $cifratura = new Cifratura('una-frase-lunga-almeno-sedici');
        $this->db->esegui("INSERT INTO titulus_config (id, attivo, url_ws, username, aoo, password_cifrata) VALUES (1, 1, 'https://titulus-unical.pp.cineca.it/titulus_ws/services/Titulus4?wsdl', 'portale', 'ABC', ?)", [$cifratura->cifra('segreta')]);
        $this->db->esegui("INSERT INTO titulus_regole (nome, applica_a, tipo_documento, uo, cod_titolario) VALUES ('Ingresso', 'convenzione_fsl', 'arrivo', '2014', '3/2'), ('Definitivo', 'convenzione_fsl_definitivo', 'partenza', '2014', '3/2')");
        $this->impostaDirettore('Prof. Mario Bianchi', 'direttore@unical.it');
    }

    protected function tearDown(): void
    {
        $this->rimuovi($this->radiceProva);
    }

    private function rimuovi(string $d): void
    {
        foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (basename($f) === '.' || basename($f) === '..') {
                continue;
            }
            is_dir($f) ? $this->rimuovi($f) : @unlink($f);
        }
        @rmdir($d);
    }

    private function automatico(bool $acceso): void
    {
        $this->servizio(ProtocolloRepository::class)->salvaAutomatici($acceso, false);
    }

    /** Una convenzione firmata dal Direttore, con le date e i docenti decisi alla presa in carico. @param array<string, mixed> $extra */
    private function firmata(array $extra = []): int
    {
        $dir = "%PDF-1.7\n1 0 obj<<>>endobj\n%%FIRMA direttore\n%%EOF";
        file_put_contents($this->radiceProva . '/uploads/convenzioni/dir.pdf', $dir);
        file_put_contents($this->radiceProva . '/uploads/convenzioni_compilate/firmate/all.pdf', "%PDF-1.7\nAllegato A\n%%EOF");
        $dati = '{"scuola":{"denominazione":"Liceo Galilei","codice":"CSPS00001A","cf":"80001230789","pec":"liceo@pec.it","indirizzo":"Via Roma 1","comune":"Cosenza"}}';
        $campi = $extra + [
            'token' => 'tok' . bin2hex(random_bytes(3)), 'scuola_codice' => 'CSPS00001A', 'prenotazione_id' => 1, 'email' => 'scuola@x.it', 'dati_json' => $dati,
            'stato_iter' => 'firmata_direttore', 'file_definitivo' => 'uploads/convenzioni/dir.pdf', 'file_allegato_scuola' => 'uploads/convenzioni_compilate/firmate/all.pdf',
            'firmata_direttore_il' => '2026-10-05 11:00:00', 'valida_dal' => '2026-10-10', 'valida_al' => '2027-10-09', 'docenti_json' => '[{"nome":"Prof. Verdi","email":"verdi@scuola.it"}]',
        ];

        return $this->db->inserisci('INSERT INTO convenzioni_compilate (' . implode(',', array_keys($campi)) . ') VALUES (' . implode(',', array_fill(0, count($campi), '?')) . ')', array_values($campi));
    }

    private function auto(): ProtocolloAutomaticoConvenzioneFsl
    {
        return $this->servizio(ProtocolloAutomaticoConvenzioneFsl::class);
    }

    private function definitivo(): ProtocollaDefinitivaFslInTitulus
    {
        return $this->servizio(ProtocollaDefinitivaFslInTitulus::class);
    }

    /** @return array<string, mixed> */
    private function dati(): array
    {
        return ['data_stipula' => '2026-10-10', 'scadenza' => '2027-10-09', 'docenti' => []];
    }

    private function cc(int $id): array
    {
        return $this->riga('convenzioni_compilate', $id) ?? [];
    }

    private function invii(): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM titulus_invii');
    }

    public function testConLInterruttoreAccesoLaFirmaDelDirettoreProtocollaERegistra(): void
    {
        $this->automatico(true);
        $id = $this->firmata();
        $prima = file_get_contents($this->radiceProva . '/uploads/convenzioni/dir.pdf');
        $inAttesa = $this->pren(100, ['stato' => 'da_approvare', 'convenzione' => 'no', 'email' => 'anna@prova.it']);

        $this->assertNull($this->auto()->dopoFirma($id));

        $r = $this->cc($id);
        $this->assertSame('protocollata', $r['stato_iter']);
        $this->assertSame(1, $this->titulus->salvataggi);
        $this->assertStringContainsString('2026-UCALPRG-', (string) $r['protocollo']);
        $this->assertSame('2026-10-10', $this->riga('convenzioni_scuole', (int) $r['convenzione_id'])['data_stipula']);
        $this->assertSame($prima, file_get_contents($this->radiceProva . '/uploads/convenzioni/dir.pdf'), 'il PDF firmato dal Direttore resta identico byte per byte');
        // il file originale arriva da Titulus senza stampigliatura e con l'impronta controllata
        $this->assertSame('ok', $r['originale_stato']);
        $this->assertStringStartsWith('uploads/convenzioni/', (string) $r['file_originale']);
        $this->assertSame($prima, file_get_contents($this->radiceProva . '/' . $r['file_originale']));
        $this->assertSame('confermata', $this->riga('prenotazioni', $inAttesa)['stato']);
        $rg = json_decode((string) $r['esito_registrazione'], true);
        $this->assertSame(1, $rg['approvate'] ?? null);
    }

    public function testConLInterruttoreSpentoNonSuccedeNiente(): void
    {
        $this->automatico(false);
        $id = $this->firmata();

        $this->assertNull($this->auto()->dopoFirma($id));

        $this->assertSame(0, $this->titulus->salvataggi);
        $this->assertSame('firmata_direttore', $this->cc($id)['stato_iter']);
        $this->assertSame(0, $this->invii());
    }

    public function testLaFirmaNonFalliscePerUnErroreDiTitulusEIlMotivoLoVedeLUfficio(): void
    {
        $this->automatico(true);
        $id = $this->firmata();
        $this->titulus->modo = 'errore';

        $motivo = $this->auto()->dopoFirma($id);

        $this->assertStringContainsString('Titulus ha rifiutato', (string) $motivo);
        $r = $this->cc($id);
        $this->assertSame('firmata_direttore', $r['stato_iter'], 'la firma resta salvata');
        $this->assertStringContainsString('protocollo automatico non è riuscito', (string) $r['protocollo_errore']);
        // l'ufficio riprova con il pulsante quando Titulus torna a rispondere
        $this->titulus->modo = 'ok';
        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);
        $this->assertSame('protocollata', $this->cc($id)['stato_iter']);
    }

    public function testConUnaRispostaPersaNonSiRiprovaMaiDaSoli(): void
    {
        $this->automatico(true);
        $id = $this->firmata();
        $this->titulus->modo = 'persa';

        $motivo = (string) $this->auto()->dopoFirma($id);

        $this->assertStringContainsString('INCERTO', $motivo);
        $this->assertSame(1, $this->titulus->salvataggi);
        $st = $this->servizio(InvioProtocollo::class)->stato($this->servizio(ServizioConvenzioneFirmata::class)->rifDefinitivo($this->cc($id)), $id);
        $this->assertSame('incerto', $st['stato']);
        $this->assertSame('firmata_direttore', $this->cc($id)['stato_iter']);

        // né un secondo automatico né il pulsante riprovano: l'esito lo decide l'ufficio
        $this->titulus->modo = 'ok';
        $this->assertStringContainsString('INCERTO', (string) $this->auto()->dopoFirma($id));
        $this->assertStringContainsString('INCERTO', (string) $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);
        $this->assertSame(1, $this->titulus->salvataggi, 'Titulus non è stato richiamato');

        // l'ufficio ha cercato in Titulus: il documento non c'è, si può inviare di nuovo
        $this->servizio(InvioProtocollo::class)->chiudiIncerto($this->servizio(ServizioConvenzioneFirmata::class)->rifDefinitivo($this->cc($id)), $id, 'cercato, non c\'è', 1);
        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);
        $this->assertSame(2, $this->titulus->salvataggi);
        $this->assertSame('protocollata', $this->cc($id)['stato_iter']);
    }

    public function testConUnaRispostaPersaIlNumeroAManoChiudeLIncertezzaSenzaUnSecondoProtocollo(): void
    {
        $id = $this->firmata();
        $this->titulus->modo = 'persa';
        $this->assertStringContainsString('INCERTO', (string) $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);

        $r = $this->definitivo()->completaAMano($id, '', ['protocollo' => 'UCAL-123', 'protocollo_data' => '2026-10-06'] + $this->dati(), 'admin@x.it');

        $this->assertNull($r['errore'], (string) $r['errore']);
        $this->assertSame('protocollata', $this->cc($id)['stato_iter']);
        $this->assertSame(1, $this->titulus->salvataggi);
        $this->assertStringContainsString('UCAL-123', (string) $this->cc($id)['protocollo']);
    }

    public function testConUnAvvisoSullaFirmaOSenzaDateNonPartePerConto(): void
    {
        $this->automatico(true);
        $con = $this->firmata(['avviso_firma' => 'la firma non copre tutto il file']);
        $this->assertStringContainsString('avviso sulla firma', (string) $this->auto()->dopoFirma($con));
        $this->assertStringContainsString('avviso sulla firma', (string) $this->cc($con)['protocollo_errore']);

        $senza = $this->firmata(['valida_dal' => null, 'valida_al' => null]);
        $this->assertStringContainsString('date di validità', (string) $this->auto()->dopoFirma($senza));

        $this->assertSame(0, $this->titulus->salvataggi);
        $this->assertSame(0, $this->invii());
    }

    public function testSeIlFileNonArrivaSiTieneQuelloDelPortaleEIlCronRiprova(): void
    {
        $id = $this->firmata();
        $this->titulus->modoFile = 'nessuno';

        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);

        $r = $this->cc($id);
        $this->assertSame('protocollata', $r['stato_iter'], 'l\'iter si conclude lo stesso');
        $this->assertSame('mancante', $r['originale_stato']);
        $this->assertNotSame('', (string) $r['originale_avviso']);
        $this->assertNull($r['file_originale']);
        $dopoUnGiorno = static fn (string $d): string => $d;
        $originale = $this->servizio(OriginaleDaTitulus::class);

        $this->assertSame(0, $originale->riprovaTutti(), 'appena provato: il cron aspetta il giorno dopo');
        $this->db->esegui('UPDATE convenzioni_compilate SET originale_il = ? WHERE id = ?', ['2026-10-03 12:00:00', $id]);
        $this->assertSame(0, $originale->riprovaTutti(), 'Titulus non ha ancora il file: nessuno arrivato');
        $this->assertSame('mancante', $this->cc($id)['originale_stato']);
        $this->assertSame(2, (int) $this->cc($id)['originale_tentativi']);

        $this->titulus->modoFile = 'originale';
        $this->db->esegui('UPDATE convenzioni_compilate SET originale_il = ? WHERE id = ?', ['2026-10-03 12:00:00', $id]);
        $this->assertSame(1, $originale->riprovaTutti());
        $r = $this->cc($id);
        $this->assertSame('ok', $r['originale_stato']);
        $this->assertFileExists($this->radiceProva . '/' . $r['file_originale']);
        $this->assertSame(1, $this->titulus->salvataggi, 'rileggere il file non crea un nuovo protocollo');
        $this->assertSame(0, $originale->riprovaTutti(), 'una volta arrivato non si riprova più');
        unset($dopoUnGiorno);
    }

    public function testDopoSettePerGiorniIlCronSiFerma(): void
    {
        $id = $this->firmata();
        $this->titulus->modoFile = 'nessuno';
        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);
        $this->db->esegui('UPDATE convenzioni_compilate SET originale_tentativi = 8, originale_il = ? WHERE id = ?', ['2026-09-01 12:00:00', $id]);

        $this->titulus->modoFile = 'originale';
        $this->assertSame(0, $this->servizio(OriginaleDaTitulus::class)->riprovaTutti());
        // il pulsante «Riprova da Titulus» resta, anche a iter concluso
        $this->assertTrue($this->servizio(OriginaleDaTitulus::class)->scarica($id)['ok']);
        $this->assertSame('ok', $this->cc($id)['originale_stato']);
    }

    public function testConUnaImprontaDiversaSiTieneIlFileDelPortale(): void
    {
        $id = $this->firmata();
        $this->titulus->modoFile = 'impronta';

        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);

        $r = $this->cc($id);
        $this->assertSame('protocollata', $r['stato_iter']);
        $this->assertSame('impronta', $r['originale_stato']);
        $this->assertNull($r['file_originale'], 'il file con l\'impronta sbagliata non si conserva');
        $this->assertStringContainsString('impronta', (string) $r['originale_avviso']);
        $this->assertNotNull($this->servizio(ServizioConvenzioneFirmata::class)->documentoProtocollatoPerScuola($id), 'la scuola ha comunque la copia del portale o il file registrato');
    }

    public function testIlDocumentoProtocollatoCaricatoAManoVinceSuQuelloDelPortale(): void
    {
        $id = $this->firmata();
        $s = $this->servizio(ServizioConvenzioneFirmata::class);
        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);
        $firmato = file_get_contents($this->radiceProva . '/uploads/convenzioni/dir.pdf');
        $portale = $s->documentoProtocollatoPerScuola($id);
        $this->assertNotNull($portale);

        $this->assertStringContainsString('PDF', (string) $s->caricaProtocollato($id, 'non pdf'));
        $this->assertNull($s->caricaProtocollato($id, "%PDF-1.7\nprotocollato dall'ufficio\n%%EOF"));

        $scuola = $s->documentoProtocollatoPerScuola($id);
        $this->assertNotNull($scuola);
        $this->assertStringContainsString("protocollato dall'ufficio", (string) file_get_contents($scuola['percorso']));
        $this->assertSame($firmato, file_get_contents($this->radiceProva . '/uploads/convenzioni/dir.pdf'), 'il PDF firmato non si tocca mai');
        $this->assertNotNull($s->fileDaScaricare($id, 'protocollato'));
    }

    public function testUnaConvenzioneNonProtocollataNonHaUnDocumentoPerLaScuola(): void
    {
        $id = $this->firmata();

        $this->assertNull($this->servizio(ServizioConvenzioneFirmata::class)->documentoProtocollatoPerScuola($id));
    }

    public function testDoppioClicNonFaDueProtocolli(): void
    {
        $id = $this->firmata();

        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);
        $this->assertStringContainsString('già stata protocollata', (string) $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);
        $this->assertSame(1, $this->titulus->salvataggi);
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_scuole'));
    }

    public function testMentreSiParlaConTitulusIlBloccoDelDocumentoEPresoEUnAltroInvioNonParte(): void
    {
        $id = $this->firmata();
        $rif = $this->servizio(ServizioConvenzioneFirmata::class)->rifDefinitivo($this->cc($id));
        $db = getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit';
        $altra = new \mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''), $db);
        $nome = ProtocolloRepository::nomeBloccoInvio($rif, $id, $db);
        $visto = [];
        $this->titulus->inVolo = function () use ($altra, $nome, &$visto, $id): void {
            $visto['libero'] = (int) ($altra->query("SELECT IS_FREE_LOCK('$nome') AS l")->fetch_assoc()['l'] ?? -1);
            // un altro processo (qui: il numero a mano) non può registrare mentre l'invio è in corso
            $visto['a_mano'] = $this->definitivoSuAltraConnessione($altra, $id);
        };

        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);

        $this->assertSame(0, $visto['libero'] ?? -1, 'il blocco del documento è preso mentre Titulus lavora');
        $this->assertSame('in_corso', $visto['a_mano'] ?? '', 'l\'altra connessione non entra');
        $this->assertSame(1, (int) $this->db->valore('SELECT IS_FREE_LOCK(?)', [$nome]), 'a invio concluso il blocco è rilasciato');
        $altra->close();
    }

    private function definitivoSuAltraConnessione(\mysqli $altra, int $id): string
    {
        $rif = $this->servizio(ServizioConvenzioneFirmata::class)->rifDefinitivo($this->cc($id));
        $db = getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit';
        $nome = ProtocolloRepository::nomeBloccoInvio($rif, $id, $db);
        $r = $altra->query("SELECT GET_LOCK('$nome', 0) AS p")->fetch_assoc();

        return (int) ($r['p'] ?? 0) === 1 ? 'preso' : 'in_corso';
    }

    public function testDueProcessiVeriInContemporaneaFanno1SoloProtocollo(): void
    {
        $bootstrap = (string) ($GLOBALS['__PHPUNIT_BOOTSTRAP'] ?? '');
        if ($bootstrap === '' || !is_file($bootstrap)) {
            $this->markTestSkipped('Il bootstrap dei test non è noto: non posso avviare i processi paralleli');
        }
        $id = $this->firmata();
        $db = getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit';
        $contatore = $this->radiceProva . '/chiamate.txt';
        file_put_contents($contatore, '');
        $parti = microtime(true) + 3.0;
        $figli = [];
        foreach ([0, 1, 2] as $i) {
            $pipe = [];
            $proc = proc_open([PHP_BINARY, '-d', 'extension=zip', dirname(__DIR__, 2) . '/Support/protocolla_definitivo_fsl.php', $bootstrap, $db, $this->radiceProva, (string) $id, sprintf('%.3F', $parti), $contatore], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
            $this->assertIsResource($proc, "figlio $i");
            $figli[] = [$proc, $pipe];
        }
        $esiti = [];
        foreach ($figli as $i => [$proc, $pipe]) {
            $out = trim((string) stream_get_contents($pipe[1]));
            $err = trim((string) stream_get_contents($pipe[2]));
            fclose($pipe[1]);
            fclose($pipe[2]);
            proc_close($proc);
            $esiti[$i] = json_decode($out, true);
            $this->assertIsArray($esiti[$i], "figlio $i: $out $err");
        }

        $this->assertSame(1, strlen((string) file_get_contents($contatore)), 'Titulus è stato chiamato UNA volta sola da tre processi: ' . json_encode($esiti));
        $this->assertSame(1, count(array_filter($esiti, static fn (array $e): bool => $e['errore'] === null)), 'uno solo ha protocollato: ' . json_encode($esiti));
        $this->assertSame(1, (int) $this->db->valore("SELECT COUNT(*) FROM titulus_invii WHERE esito = 'ok'"));
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_scuole'), 'una sola convenzione registrata');
        $this->assertSame('protocollata', $this->cc($id)['stato_iter']);
    }

    public function testLaRegistrazioneDiceQuanteApprovateEQuali(): void
    {
        $id = $this->firmata();
        $approvata = $this->pren(200, ['stato' => 'da_approvare', 'convenzione' => 'no']);
        $dalGestore = $this->pren(201, ['stato' => 'da_approvare', 'convenzione' => 'no']);
        $scritta = $this->pren(200, ['stato' => 'da_approvare', 'convenzione' => 'no', 'scuola_codice' => null]);

        $r = $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1);

        $this->assertNull($r['errore']);
        $this->assertSame('confermata', $this->riga('prenotazioni', $approvata)['stato']);
        $this->assertSame('da_approvare', $this->riga('prenotazioni', $dalGestore)['stato'], 'chi richiede l\'approvazione del gestore non si approva');
        $this->assertSame('da_approvare', $this->riga('prenotazioni', $scritta)['stato'], 'la scuola scritta a mano non si abbina da sola');
        $rg = json_decode((string) $this->cc($id)['esito_registrazione'], true);
        $this->assertSame(1, $rg['approvate']);
        $motivi = array_column($rg['restano'], 'motivo');
        $this->assertNotEmpty(array_filter($motivi, static fn (string $m): bool => stripos($m, 'approvazione') !== false), implode(' | ', $motivi));
        $this->assertSame(1, $rg['senza_codice'] ?? 0);
    }

    public function testLaRegistrazioneSiRipeteSenzaDoppioniNeEmailInPiu(): void
    {
        $id = $this->firmata();
        $this->pren(200, ['stato' => 'da_approvare', 'convenzione' => 'no']);
        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);
        $email = count($this->mailer->inviate);

        $rg = $this->servizio(ServizioConvenzioneFirmata::class)->ripetiApplicazione($id);

        $this->assertIsArray($rg);
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_scuole'));
        $this->assertStringStartsWith('uploads/convenzioni/', (string) $this->riga('convenzioni_scuole', (int) $this->cc($id)['convenzione_id'])['file_allegato'], "l'Allegato A è nel registro, uno solo");
        $this->assertCount(3, glob($this->radiceProva . '/uploads/convenzioni/*.pdf') ?: [], "dir.pdf, la copia della convenzione e l'Allegato A: niente file doppi dopo la ripetizione");
        $this->assertSame($email, count($this->mailer->inviate), 'chi era già coperto non riceve altre email');
    }

    public function testSeIlNumeroAManoNonEValidoNonSiRegistraNiente(): void
    {
        $id = $this->firmata();

        $r = $this->definitivo()->completaAMano($id, '', ['protocollo' => ''] + $this->dati(), 'admin@x.it');

        $this->assertNotNull($r['errore']);
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_scuole'));
        $this->assertSame('firmata_direttore', $this->cc($id)['stato_iter']);
    }
}
