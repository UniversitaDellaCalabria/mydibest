<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Core\Sito;
use App\Fsl\ConvenzioneCompilataRepository;
use App\Fsl\ProtocollaConvenzioneInTitulus;
use App\Fsl\ProtocollaDefinitivaFslInTitulus;
use App\Fsl\ProtocolloAutomaticoConvenzioneFsl;
use App\Fsl\ServizioConvenzioneFirmata;
use App\Fsl\ServizioFirmaDirettore;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Infrastructure\Sicurezza\Cifratura;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\ProtocolloRepository;
use App\Protocollo\RichiestaProtocollo;
use App\Tutorato\FirmaRemota;
use Tests\Doppi\FirmeFinte;
use Tests\Doppi\TitulusFintoFsl;
use Unical\Titulus\AllegatoTitulus;
use Unical\Titulus\FabbricaClienteTitulus;

/**
 * Correzioni al passo 5 (P1-P6): l'esito incerto si chiude solo se il numero a mano è stato registrato; la firma del Direttore non scavalca un
 * rifiuto o un numero a mano; il blocco dell'invio è per documento e non per file; il numero dato a un file non si registra su un file diverso;
 * un invio in approvazione non si ripete; la data di protocollo è quella dell'invio riuscito.
 * Titulus è sempre il finto: nessuna chiamata vera.
 */
final class CorrezioniProtocolloTest extends FslBase
{
    private string $radiceProva;
    private TitulusFintoFsl $titulus;
    private const FIRMATA = 'uploads/convenzioni_compilate/firmate/scuola.pdf';

    protected function tabelle(): array
    {
        $titulus = [];
        foreach (['titulus_config', 'titulus_regole', 'titulus_cc', 'titulus_invii', 'titulus_voci_indice'] as $t) {
            $titulus[$t] = self::tabellaVera($t);
        }

        return parent::tabelle() + $titulus;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->radiceProva = sys_get_temp_dir() . '/fsl_corr_' . bin2hex(random_bytes(4));
        mkdir($this->radiceProva . '/uploads/convenzioni', 0755, true);
        mkdir($this->radiceProva . '/uploads/convenzioni_compilate/firmate', 0755, true);
        $this->c->istanza(Sito::class, new Sito($this->radiceProva, 'https://portale.test/eventi'));
        $this->c->istanza(VerificaFirme::class, new FirmeFinte());
        $this->c->istanza(FirmaRemota::class, new \Tests\Doppi\FirmaRemotaFinta());
        \App\Protocollo\Registrazione::registra($this->c);
        $this->titulus = new TitulusFintoFsl();
        $this->c->istanza(FabbricaClienteTitulus::class, $this->titulus);
        $this->c->istanza(Cifratura::class, new Cifratura('una-frase-lunga-almeno-sedici'));
        $this->db->esegui("INSERT INTO utenti (id, email, ruolo_id) VALUES (1, 'admin@x.it', 1)");
        $cifratura = new Cifratura('una-frase-lunga-almeno-sedici');
        $this->db->esegui("INSERT INTO titulus_config (id, attivo, url_ws, username, aoo, password_cifrata) VALUES (1, 1, 'https://titulus-unical.pp.cineca.it/titulus_ws/services/Titulus4?wsdl', 'portale', 'ABC', ?)", [$cifratura->cifra('segreta')]);
        $this->db->esegui("INSERT INTO titulus_regole (nome, applica_a, tipo_documento, uo, cod_titolario) VALUES ('Ingresso', 'convenzione_fsl', 'arrivo', '2014', '3/2'), ('Definitivo', 'convenzione_fsl_definitivo', 'partenza', '2014', '3/2')");
        $this->impostaDirettore('Prof. Mario Bianchi', 'direttore@unical.it', 'bnchmr60a01h501x');
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

    // ---- attrezzi ------------------------------------------------------------------------------------------------------------------

    private function automatico(bool $acceso): void
    {
        $this->servizio(ProtocolloRepository::class)->salvaAutomatici($acceso, false);
    }

    private const DATI = '{"scuola":{"denominazione":"Liceo Galilei","codice":"CSPS00001A","cf":"80001230789","pec":"liceo@pec.it","indirizzo":"Via Roma 1","comune":"Cosenza"}}';

    /** @param array<string, mixed> $extra */
    private function inserisci(array $extra): int
    {
        $campi = $extra + ['token' => 'tok' . bin2hex(random_bytes(3)), 'scuola_codice' => 'CSPS00001A', 'prenotazione_id' => 1, 'email' => 'scuola@x.it', 'dati_json' => self::DATI];

        return $this->db->inserisci('INSERT INTO convenzioni_compilate (' . implode(',', array_keys($campi)) . ') VALUES (' . implode(',', array_fill(0, count($campi), '?')) . ')', array_values($campi));
    }

    private function scrivi(string $relativo, string $contenuto): string
    {
        file_put_contents($this->radiceProva . '/' . $relativo, $contenuto);

        return $relativo;
    }

    /** Convenzione firmata dal Direttore, con le date e i docenti decisi alla presa in carico. @param array<string, mixed> $extra */
    private function firmata(array $extra = []): int
    {
        $this->scrivi('uploads/convenzioni/dir.pdf', "%PDF-1.7\n1 0 obj<<>>endobj\n%%FIRMA direttore\n%%EOF");
        $this->scrivi('uploads/convenzioni_compilate/firmate/all.pdf', "%PDF-1.7\nAllegato A\n%%EOF");

        return $this->inserisci($extra + [
            'stato_iter' => 'firmata_direttore', 'file_definitivo' => 'uploads/convenzioni/dir.pdf', 'file_allegato_scuola' => 'uploads/convenzioni_compilate/firmate/all.pdf',
            'firmata_direttore_il' => '2026-10-05 11:00:00', 'valida_dal' => '2026-10-10', 'valida_al' => '2027-10-09', 'docenti_json' => '[{"nome":"Prof. Verdi","email":"verdi@scuola.it"}]',
        ]);
    }

    /** Convenzione caricata dalla scuola, in attesa del protocollo in entrata. @param array<string, mixed> $extra */
    private function caricata(array $extra = []): int
    {
        $this->scrivi(self::FIRMATA, "%PDF-1.7\nconvenzione della scuola\n%%FIRMA scuola\n%%EOF");
        $this->scrivi('uploads/convenzioni_compilate/firmate/all.pdf', "%PDF-1.7\nAllegato A\n%%EOF");

        return $this->inserisci($extra + ['stato_iter' => 'caricata_scuola', 'file_firmato' => self::FIRMATA, 'file_allegato_scuola' => 'uploads/convenzioni_compilate/firmate/all.pdf', 'firmata_il' => '2026-10-04 09:00:00']);
    }

    /** Convenzione presa in carico e mandata al Direttore, in attesa della sua firma. */
    private function daFirmare(): int
    {
        $this->scrivi(self::FIRMATA, "%PDF-1.7\n<< /Type /Pages /Count 3 /Kids [] >>\n%%FIRMA scuola\n%%EOF");
        $this->scrivi('uploads/convenzioni_compilate/firmate/all.pdf', "%PDF-1.7\nAllegato A\n%%EOF");

        return $this->inserisci(['stato_iter' => 'da_firmare', 'token_direttore' => 'tokdirettore', 'file_firmato' => self::FIRMATA, 'file_allegato_scuola' => 'uploads/convenzioni_compilate/firmate/all.pdf',
            'firmata_il' => '2026-10-04 09:00:00', 'valida_dal' => '2026-10-10', 'valida_al' => '2027-10-09', 'in_carico_il' => '2026-10-04 10:00:00']);
    }

    /** @return array<string, mixed> */
    private function cc(int $id): array
    {
        return $this->riga('convenzioni_compilate', $id) ?? [];
    }

    /** @return array<string, mixed> */
    private function dati(): array
    {
        return ['data_stipula' => '2026-10-10', 'scadenza' => '2027-10-09', 'docenti' => []];
    }

    private function definitivo(): ProtocollaDefinitivaFslInTitulus
    {
        return $this->servizio(ProtocollaDefinitivaFslInTitulus::class);
    }

    private function ingresso(): ProtocollaConvenzioneInTitulus
    {
        return $this->servizio(ProtocollaConvenzioneInTitulus::class);
    }

    private function invio(): InvioProtocollo
    {
        return $this->servizio(InvioProtocollo::class);
    }

    private function iter(): ServizioConvenzioneFirmata
    {
        return $this->servizio(ServizioConvenzioneFirmata::class);
    }

    private function rifDef(int $id): string
    {
        return $this->iter()->rifDefinitivo($this->cc($id));
    }

    private function rifIng(int $id): string
    {
        return $this->iter()->rifIngresso($this->cc($id));
    }

    /** Una riga del registro degli invii scritta a mano (come l'ha lasciata un invio di prima). */
    private function riga_invio(string $rif, int $id, string $esito, string $numero = '', string $il = '2026-10-04 08:00:00'): void
    {
        $this->db->esegui('INSERT INTO titulus_invii (rif_tipo, rif_id, esito, num_prot, nrecord, creato_il) VALUES (?, ?, ?, ?, ?, ?)', [$rif, $id, $esito, $numero, $numero !== '' ? 'R' . $numero : '', $il]);
    }

    private function nomeDatabase(): string
    {
        return getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit';
    }

    /** Un'altra connessione al database di prova (un altro processo, per i blocchi). */
    private function altraConnessione(): \mysqli
    {
        return new \mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''), $this->nomeDatabase());
    }

    private function fileConvenzioni(): int
    {
        return count(glob($this->radiceProva . '/uploads/convenzioni/*.pdf') ?: []);
    }

    /** Il Direttore firma con Aruba: $durante gira mentre la firma è in corso (senza blocchi in mano, come la rete vera). */
    private function conAruba(?callable $durante): void
    {
        $aruba = new class ($durante) implements FirmaRemota {
            public int $firme = 0;

            public function __construct(private $durante)
            {
            }

            public function disponibile(): bool
            {
                return true;
            }

            public function firma(string $pdf, string $utente, string $password, string $otp, ?array $aspetto = null, string $motivo = ''): array
            {
                ++$this->firme;
                if ($this->durante !== null) {
                    ($this->durante)();
                }

                return [$pdf . "\n%%FIRMA direttore\n", null];
            }
        };
        $this->c->istanza(FirmaRemota::class, $aruba);
        $this->aruba = $aruba;
    }

    private object $aruba;

    private function direttore(): ServizioFirmaDirettore
    {
        return $this->servizio(ServizioFirmaDirettore::class);
    }

    // ---- P1: l'esito incerto si chiude solo se il numero a mano è stato registrato --------------------------------------------------

    public function testUnNumeroAManoConDateSbagliateNonChiudeLEsitoIncerto(): void
    {
        $this->automatico(true);
        $id = $this->firmata();
        $this->titulus->modo = 'persa';
        $this->assertStringContainsString('INCERTO', (string) $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);

        $r = $this->definitivo()->completaAMano($id, '', ['protocollo' => 'UCAL-9', 'protocollo_data' => '2026-10-06', 'data_stipula' => '2027-01-01', 'scadenza' => '2026-01-01'], 'admin@x.it');

        $this->assertNotNull($r['errore'], 'le date sono sbagliate: non è registrato niente');
        $this->assertSame('incerto', $this->invio()->stato($this->rifDef($id), $id)['stato'], 'l\'incertezza resta');
        $this->titulus->modo = 'ok';
        $this->assertNotNull($this->servizio(ProtocolloAutomaticoConvenzioneFsl::class)->dopoFirma($id), 'l\'automatico non invia');
        $this->assertStringContainsString('INCERTO', (string) $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore'], 'né il pulsante');
        $this->assertSame(1, $this->titulus->salvataggi);
        $this->assertSame('firmata_direttore', $this->cc($id)['stato_iter']);
    }

    public function testUnNumeroAManoVuotoNonChiudeLEsitoIncertoDelProtocolloInEntrata(): void
    {
        $id = $this->caricata();
        $this->titulus->modo = 'persa';
        $this->assertStringContainsString('INCERTO', (string) $this->ingresso()->protocolla($id, 'admin@x.it', 1)['errore']);

        $r = $this->ingresso()->protocolloAMano($id, '', '2026-10-06', 'admin@x.it');

        $this->assertNotNull($r['errore']);
        $this->assertSame('incerto', $this->invio()->stato($this->rifIng($id), $id)['stato']);
        $this->titulus->modo = 'ok';
        $this->assertStringContainsString('INCERTO', (string) $this->ingresso()->protocolla($id, 'admin@x.it', 1)['errore']);
        $this->assertSame(1, $this->titulus->salvataggi);
    }

    public function testUnNumeroAManoRiuscitoChiudeLEsitoIncerto(): void
    {
        $id = $this->firmata();
        $this->titulus->modo = 'persa';
        $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1);

        $r = $this->definitivo()->completaAMano($id, '', ['protocollo' => 'UCAL-9', 'protocollo_data' => '2026-10-06'] + $this->dati(), 'admin@x.it');

        $this->assertNull($r['errore'], (string) $r['errore']);
        $this->assertSame('protocollata', $this->cc($id)['stato_iter']);
        $this->assertSame('libero', $this->invio()->stato($this->rifDef($id), $id)['stato']);
    }

    public function testChiudiIncertoSiRifiutaSeUnInvioELavoroOSeGiaProtocollato(): void
    {
        $id = $this->firmata();
        $rif = $this->rifDef($id);
        // un processo sta inviando: ha il blocco del documento
        $this->riga_invio($rif, $id, 'in_invio', '', date('Y-m-d H:i:s'));
        $altra = $this->altraConnessione();
        $nome = ProtocolloRepository::nomeBloccoInvio($rif, $id, $this->nomeDatabase());
        $altra->query("SELECT GET_LOCK('$nome', 0)");

        $e = $this->invio()->chiudiIncerto($rif, $id, 'cercato', 1);

        $this->assertIsString($e, 'con un invio in corso non si chiude niente');
        $this->assertSame(0, (int) $this->db->valore("SELECT COUNT(*) FROM titulus_invii WHERE esito = 'verificato'"));
        $altra->close();

        // già protocollato: non c'è niente da chiudere
        $id2 = $this->firmata();
        $this->riga_invio($this->rifDef($id2), $id2, 'ok', '2026-UCAL-5');
        $this->assertIsString($this->invio()->chiudiIncerto($this->rifDef($id2), $id2, 'cercato', 1));
        $this->assertSame(0, (int) $this->db->valore("SELECT COUNT(*) FROM titulus_invii WHERE esito = 'verificato'"));

        // un esito davvero incerto si chiude
        $id3 = $this->firmata();
        $this->riga_invio($this->rifDef($id3), $id3, 'incerto');
        $this->assertNull($this->invio()->chiudiIncerto($this->rifDef($id3), $id3, 'cercato, non c\'è', 1));
        $this->assertSame('libero', $this->invio()->stato($this->rifDef($id3), $id3)['stato']);
    }

    // ---- P2: la firma del Direttore sotto il blocco, con la riga riletta e l'UPDATE condizionato --------------------------------------

    public function testUnRifiutoDuranteLaFirmaDelDirettoreNonVieneScavalcato(): void
    {
        $this->automatico(true);
        $id = $this->daFirmare();
        $this->conAruba(fn () => $this->iter()->rifiuta($id, 'Manca la pagina 3'));
        $cc = $this->cc($id);
        $prima = $this->fileConvenzioni();

        $e = $this->direttore()->firmaConAruba($cc, 'utente', 'pin', '123456');

        $this->assertIsString($e, 'il Direttore legge che la convenzione non è più in attesa');
        $this->assertStringContainsString('non è più', $e);
        $r = $this->cc($id);
        $this->assertSame('rifiutata', $r['stato_iter'], 'il rifiuto dell\'ufficio resta');
        $this->assertSame('Manca la pagina 3', $r['nota_ufficio']);
        $this->assertNull($r['file_definitivo']);
        $this->assertSame($prima, $this->fileConvenzioni(), 'il file nuovo è cancellato');
        $this->assertSame(0, $this->titulus->salvataggi, 'nessun protocollo automatico');
    }

    public function testUnNumeroAManoDuranteLaFirmaDelDirettoreNonVieneScavalcato(): void
    {
        $this->automatico(true);
        $id = $this->daFirmare();
        $this->conAruba(function () use ($id): void {
            $r = $this->iter()->completa($id, "%PDF-1.7\nfirmato fuori\n%%FIRMA x\n%%EOF", ['protocollo' => 'UCAL-77', 'protocollo_data' => '2026-10-05', 'data_stipula' => '2026-10-10', 'scadenza' => '2027-10-09'], 'admin@x.it');
            $this->assertNull($r['errore'], (string) $r['errore']);
        });
        $cc = $this->cc($id);
        $prima = $this->fileConvenzioni();

        $e = $this->direttore()->firmaConAruba($cc, 'utente', 'pin', '123456');

        $this->assertIsString($e);
        $r = $this->cc($id);
        $this->assertSame('protocollata', $r['stato_iter'], 'la registrazione a mano resta');
        $this->assertNull($r['file_definitivo']);
        $this->assertSame($prima + 2, $this->fileConvenzioni(), 'ci sono solo i due file della registrazione a mano (convenzione e Allegato A): quello nuovo del Direttore è cancellato');
        $this->assertSame(0, $this->titulus->salvataggi, 'nessun protocollo automatico');
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_scuole'));
    }

    public function testDueFirmeInsiemeNeRegistranoUnaSola(): void
    {
        $this->automatico(true);
        $id = $this->daFirmare();
        $this->conAruba(null);
        $cc = $this->cc($id);
        $firmato = (string) file_get_contents($this->radiceProva . '/' . self::FIRMATA) . "\n%%FIRMA direttore\n";

        $this->assertNull($this->direttore()->caricaFirmato($cc, $firmato));
        $dopoLaPrima = $this->cc($id);
        $mail = count($this->mailer->inviate);
        $file = $this->fileConvenzioni();
        // la seconda firma parte dalla stessa riga di prima (due schede aperte): non scrive sopra la prima
        $e = $this->direttore()->caricaFirmato($cc, $firmato . "\n%%altro\n");

        $this->assertIsString($e);
        $this->assertSame($dopoLaPrima['file_definitivo'], $this->cc($id)['file_definitivo']);
        $this->assertSame($file, $this->fileConvenzioni(), 'nessun file in più');
        $this->assertSame($mail, count($this->mailer->inviate), 'l\'ufficio non riceve un secondo avviso');
    }

    public function testSeIlBloccoEOccupatoLaFirmaDelDirettoreAspettaENonSiPerde(): void
    {
        $id = $this->daFirmare();
        $this->conAruba(null);
        $cc = $this->cc($id);
        $chiave = $this->servizio(ConvenzioneCompilataRepository::class)->chiaveDi($cc);
        $nome = ConvenzioneCompilataRepository::nomeBlocco($chiave, $this->nomeDatabase());
        $pipe = [];
        $proc = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/tieni_blocco.php', $this->nomeDatabase(), $nome, '7'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
        $this->assertIsResource($proc);
        $this->assertSame('preso', trim((string) fgets($pipe[1])), 'l\'altro processo ha il blocco');

        $t = microtime(true);
        $e = $this->direttore()->firmaConAruba($cc, 'utente', 'pin', '123456');
        $attesa = microtime(true) - $t;
        fclose($pipe[1]);
        fclose($pipe[2]);
        proc_close($proc);

        $this->assertNull($e, 'la firma non si perde: ' . (string) $e);
        $this->assertGreaterThan(5.0, $attesa, 'ha aspettato il blocco oltre la prima attesa invece di arrendersi');
        $this->assertSame('firmata_direttore', $this->cc($id)['stato_iter']);
        $this->assertSame(1, $this->aruba->firme, 'Aruba è stata chiamata una volta sola: nessun secondo codice OTP');
    }

    public function testIlProtocolloAutomaticoPartePoiCheIlBloccoELibero(): void
    {
        $this->automatico(true);
        $id = $this->daFirmare();
        $this->conAruba(null);
        $cc = $this->cc($id);
        $chiave = $this->servizio(ConvenzioneCompilataRepository::class)->chiaveDi($cc);
        $nome = ConvenzioneCompilataRepository::nomeBlocco($chiave, $this->nomeDatabase());
        $altra = $this->altraConnessione();
        $visto = [];
        $this->titulus->inVolo = function () use ($altra, $nome, &$visto): void {
            $visto['libero'] = (int) ($altra->query("SELECT IS_FREE_LOCK('$nome') AS l")->fetch_assoc()['l'] ?? -1);
        };

        $this->assertNull($this->direttore()->firmaConAruba($cc, 'utente', 'pin', '123456'));

        $this->assertSame(1, $this->titulus->salvataggi, 'il gancio è partito');
        $this->assertSame(1, $visto['libero'] ?? -1, 'quando parte il protocollo automatico il blocco dell\'istituto è già rilasciato');
        $altra->close();
    }

    public function testGliAggiornamentiDelloIterSiFannoSoloDalloStatoDiPartenza(): void
    {
        $rep = $this->servizio(ConvenzioneCompilataRepository::class);
        // una compilazione già protocollata non torna indietro
        $fatta = $this->inserisci(['stato_iter' => 'protocollata', 'file_firmato' => self::FIRMATA, 'token_direttore' => 'x']);
        $this->assertFalse($rep->daFirmare($fatta, 'nuovo'), 'daFirmare');
        $this->assertFalse($rep->rifiuta($fatta, 'no'), 'rifiuta');
        $this->assertFalse($rep->protocolloIngresso($fatta, '12', '2026-10-05', 'x'), 'protocolloIngresso');
        $this->assertFalse($rep->salvaFirmata($fatta, 'nuovo.pdf', 'Rossi'), 'salvaFirmata');
        $this->assertFalse($rep->segnaProtocollata($fatta, 5, '99', '2026-10-05'), 'segnaProtocollata');
        $this->assertFalse($rep->segnaFirmataDirettore($fatta, 'd.pdf', 'Bianchi'), 'segnaFirmataDirettore');
        $this->assertSame('protocollata', $this->cc($fatta)['stato_iter']);
        $this->assertSame('x', $this->cc($fatta)['token_direttore']);

        // e nello stato giusto funzionano
        $incarico = $this->inserisci(['stato_iter' => 'in_carico', 'file_firmato' => self::FIRMATA]);
        $this->assertTrue($rep->daFirmare($incarico, 'tok'));
        $this->assertSame('da_firmare', $this->cc($incarico)['stato_iter']);
        $this->assertTrue($rep->rifiuta($incarico, 'no'));
        $this->assertSame('rifiutata', $this->cc($incarico)['stato_iter']);
    }

    // ---- P3: il blocco è del documento, non del file --------------------------------------------------------------------------------

    public function testIlBloccoEDelDocumentoENonDelFile(): void
    {
        $a = InvioProtocollo::rifPerFile(ProtocollaDefinitivaFslInTitulus::RIF, 'file A');
        $b = InvioProtocollo::rifPerFile(ProtocollaDefinitivaFslInTitulus::RIF, 'file B');
        $this->assertNotSame($a, $b);
        $this->assertSame(ProtocolloRepository::nomeBloccoInvio($a, 5, 'db'), ProtocolloRepository::nomeBloccoInvio($b, 5, 'db'), 'stesso documento: stesso blocco');
        $this->assertSame(ProtocolloRepository::nomeBloccoInvio($a, 5, 'db'), ProtocolloRepository::nomeBloccoInvio(ProtocollaDefinitivaFslInTitulus::RIF, 5, 'db'));
        $this->assertNotSame(ProtocolloRepository::nomeBloccoInvio($a, 5, 'db'), ProtocolloRepository::nomeBloccoInvio($a, 6, 'db'), 'un altro documento ha un altro blocco');
        $this->assertNotSame(ProtocolloRepository::nomeBloccoInvio($a, 5, 'db'), ProtocolloRepository::nomeBloccoInvio(ProtocollaConvenzioneInTitulus::RIF . '~' . substr(hash('sha256', 'file A'), 0, 10), 5, 'db'), 'un altro tipo di documento');
    }

    public function testUnInvioDiUnFileImpedisceUnInvioDiUnAltroFileDellaStessaCompilazione(): void
    {
        $id = $this->firmata();
        $a = InvioProtocollo::rifPerFile(ProtocollaDefinitivaFslInTitulus::RIF, 'file A');
        $b = InvioProtocollo::rifPerFile(ProtocollaDefinitivaFslInTitulus::RIF, 'file B');
        $altra = $this->altraConnessione();
        $nome = ProtocolloRepository::nomeBloccoInvio($a, $id, $this->nomeDatabase());
        $altra->query("SELECT GET_LOCK('$nome', 0)");
        $r = new RichiestaProtocollo(applicaA: 'convenzione_fsl_definitivo', oggetto: 'Convenzione', principale: AllegatoTitulus::crea('c.pdf', "%PDF-1.7\nfile B\n%%EOF"),
            esterno: ['nome' => 'Liceo', 'codice_fiscale' => '80001230789'], rifTipo: $b, rifId: $id, utenteId: 1);

        $n = $this->invio()->numero($r, ProtocollaDefinitivaFslInTitulus::RIF, '2026-10-05 11:00:00');

        $this->assertSame('', $n['numero']);
        $this->assertStringContainsString('in corso', (string) $n['errore']);
        $this->assertSame(0, $this->titulus->salvataggi, 'Titulus non è chiamato mentre l\'altro file è in volo');
        $altra->close();
    }

    public function testLEsitoIncertoDiUnFileFermaLInvioDiUnAltroFileDellaStessaCompilazione(): void
    {
        $this->automatico(true);
        $id = $this->firmata();
        $this->riga_invio(InvioProtocollo::rifPerFile(ProtocollaDefinitivaFslInTitulus::RIF, 'il file di prima'), $id, 'incerto');

        $st = $this->invio()->stato($this->rifDef($id), $id, ProtocollaDefinitivaFslInTitulus::RIF);

        $this->assertSame('incerto', $st['stato']);
        $this->assertStringContainsString('INCERTO', (string) $this->servizio(ProtocolloAutomaticoConvenzioneFsl::class)->dopoFirma($id));
        $this->assertStringContainsString('INCERTO', (string) $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);
        $this->assertSame(0, $this->titulus->salvataggi);
    }

    public function testUnNumeroDatoAUnFileDiPrimaSiMostraEFermaLAutomatico(): void
    {
        $this->automatico(true);
        $id = $this->firmata();
        $this->riga_invio(InvioProtocollo::rifPerFile(ProtocollaDefinitivaFslInTitulus::RIF, 'il file di prima'), $id, 'ok', '2026-UCAL-0042');

        $st = $this->invio()->stato($this->rifDef($id), $id, ProtocollaDefinitivaFslInTitulus::RIF);

        $this->assertSame('precedente', $st['stato']);
        $this->assertSame('2026-UCAL-0042', $st['numero']);
        $motivo = (string) $this->servizio(ProtocolloAutomaticoConvenzioneFsl::class)->dopoFirma($id);
        $this->assertStringContainsString('2026-UCAL-0042', $motivo, 'l\'ufficio legge il numero dato al file di prima');
        $this->assertStringContainsString('2026-UCAL-0042', (string) $this->cc($id)['protocollo_errore']);
        $this->assertSame(0, $this->titulus->salvataggi, 'l\'automatico non invia');
        // con il pulsante l'ufficio decide: il file nuovo è un documento nuovo e ha il suo numero
        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);
        $this->assertSame(1, $this->titulus->salvataggi);
        $this->assertNotSame('2026-UCAL-0042', (string) $this->cc($id)['protocollo']);
    }

    public function testLoStatoConsideraAncheIRiferimentiVecchiSenzaImpronta(): void
    {
        $id = $this->firmata();
        $base = ProtocollaDefinitivaFslInTitulus::RIF;
        $rif = $this->rifDef($id);
        $this->riga_invio($base, $id, 'ok', '2026-UCAL-0007', '2026-09-20 10:00:00');

        // numero dato dopo il caricamento del file: è di questo file
        $s = $this->invio()->stato($rif, $id, $base, '2026-09-01 00:00:00');
        $this->assertSame('protocollato', $s['stato']);
        $this->assertSame('2026-UCAL-0007', $s['numero']);
        // file ricaricato dopo il numero: il numero era di un altro file
        $this->assertSame('precedente', $this->invio()->stato($rif, $id, $base, '2026-10-01 00:00:00')['stato']);
        // e giaAssegnato dice la stessa cosa
        $this->assertSame('2026-UCAL-0007', $this->invio()->giaAssegnato($rif, $id, $base, '2026-09-01 00:00:00'));
        $this->assertSame('', $this->invio()->giaAssegnato($rif, $id, $base, '2026-10-01 00:00:00'));

        // un esito incerto di prima, senza impronta, vale per qualunque file
        $id2 = $this->firmata();
        $this->riga_invio($base, $id2, 'incerto');
        $this->assertSame('incerto', $this->invio()->stato($this->rifDef($id2), $id2, $base, '2026-10-01 00:00:00')['stato']);
    }

    // ---- P4: il numero dato a un file non si registra su un file diverso --------------------------------------------------------------

    public function testSeIlFileCambiaMentreTitulusLavoraIlNumeroNonSiRegistraSulFileNuovo(): void
    {
        $id = $this->caricata();
        $this->titulus->inVolo = function () use ($id): void {
            $nuovo = $this->scrivi('uploads/convenzioni_compilate/firmate/nuovo.pdf', "%PDF-1.7\nun'altra convenzione\n%%FIRMA scuola\n%%EOF");
            $this->db->esegui('UPDATE convenzioni_compilate SET file_firmato = ? WHERE id = ?', [$nuovo, $id]);
        };

        $r = $this->ingresso()->protocolla($id, 'admin@x.it', 1);

        $this->assertNotNull($r['errore']);
        $this->assertStringContainsString('al file di prima', (string) $r['errore']);
        $this->assertStringContainsString('2026-UCALPRG-', (string) $r['errore'], 'l\'ufficio legge il numero');
        $c = $this->cc($id);
        $this->assertSame('caricata_scuola', $c['stato_iter'], 'la convenzione non è presa in carico');
        $this->assertSame('', $c['protocollo_ingresso']);
        $this->assertSame(0, count($this->mailer->inviate), 'nessun avviso, nessun invio al Direttore');
    }

    public function testSeIlFileFirmatoCambiaMentreTitulusLavoraLaRegistrazioneFinaleNonPartePerIlFileNuovo(): void
    {
        $id = $this->firmata();
        $this->titulus->inVolo = function () use ($id): void {
            $nuovo = $this->scrivi('uploads/convenzioni/altro.pdf', "%PDF-1.7\naltro file del Direttore\n%%FIRMA direttore\n%%EOF");
            $this->db->esegui('UPDATE convenzioni_compilate SET file_definitivo = ? WHERE id = ?', [$nuovo, $id]);
        };

        $r = $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1);

        $this->assertNotNull($r['errore']);
        $this->assertStringContainsString('al file di prima', (string) $r['errore']);
        $this->assertSame('firmata_direttore', $this->cc($id)['stato_iter']);
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_scuole'));
    }

    public function testMentreUnInvioEInCorsoLaScuolaNonSostituisceIlFileELUfficioNonRifiuta(): void
    {
        $id = $this->caricata();
        $visto = [];
        $this->titulus->inVolo = function () use ($id, &$visto): void {
            $visto['rifiuto'] = $this->iter()->rifiuta($id, 'Manca la firma');
            $visto['carica'] = $this->iter()->carica($this->cc($id), "%PDF-1.7\nfile nuovo della scuola\n%%FIRMA scuola\n%%EOF");
        };

        $r = $this->ingresso()->protocolla($id, 'admin@x.it', 1);

        $this->assertNull($r['errore'], (string) $r['errore']);
        $this->assertIsString($visto['rifiuto'] ?? null);
        $this->assertStringContainsString('riprova tra poco', mb_strtolower((string) $visto['rifiuto']));
        $this->assertIsString($visto['carica'] ?? null);
        $this->assertStringContainsString('riprova tra poco', mb_strtolower((string) $visto['carica']));
        $this->assertSame('da_firmare', $this->cc($id)['stato_iter'], 'né rifiutata né sostituita: l\'invio è andato in porto e la convenzione è andata al Direttore');
        $this->assertSame(self::FIRMATA, $this->cc($id)['file_firmato']);
    }

    // ---- P5: un invio riuscito senza numero è «in approvazione» ----------------------------------------------------------------------------

    private function regolaConIter(): void
    {
        $voce = $this->db->inserisci("INSERT INTO titulus_voci_indice (voce, attiva) VALUES ('Convenzioni FSL', 1)");
        $this->db->esegui("UPDATE titulus_regole SET azione = 'iter', voce_indice_id = ?", [$voce]);
    }

    public function testUnInvioChePartePerIlIterDiApprovazioneNonSiRipete(): void
    {
        $this->regolaConIter();
        $this->automatico(true);
        $id = $this->firmata();
        $this->titulus->modo = 'iter';

        $e = (string) $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore'];

        $this->assertStringContainsString('approvazione', $e);
        $this->assertSame(1, $this->titulus->salvataggi);
        $this->assertSame('in_approvazione', $this->invio()->stato($this->rifDef($id), $id, ProtocollaDefinitivaFslInTitulus::RIF)['stato']);
        // né il pulsante né l'automatico lo rimandano
        $this->assertStringContainsString('approvazione', (string) $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);
        $this->assertNotNull($this->servizio(ProtocolloAutomaticoConvenzioneFsl::class)->dopoFirma($id));
        $this->assertSame(1, $this->titulus->salvataggi, 'un solo documento in Titulus');
        $this->assertSame('firmata_direttore', $this->cc($id)['stato_iter']);
    }

    public function testQuandoIlNumeroArrivaLUfficioLoScriveEL_ApprovazioneSiChiude(): void
    {
        $this->regolaConIter();
        $id = $this->firmata();
        $this->titulus->modo = 'iter';
        $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1);

        $r = $this->definitivo()->completaAMano($id, '', ['protocollo' => 'UCAL-2026-55', 'protocollo_data' => '2026-10-20'] + $this->dati(), 'admin@x.it');

        $this->assertNull($r['errore'], (string) $r['errore']);
        $this->assertSame('protocollata', $this->cc($id)['stato_iter']);
        $this->assertSame(1, $this->titulus->salvataggi);
        $this->assertSame('libero', $this->invio()->stato($this->rifDef($id), $id, ProtocollaDefinitivaFslInTitulus::RIF)['stato']);
    }

    public function testSeIlDocumentoERespintoLUfficioLoDichiaraESiPuoRimandare(): void
    {
        $this->regolaConIter();
        $id = $this->firmata();
        $this->titulus->modo = 'iter';
        $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1);
        $rif = $this->rifDef($id);

        $this->assertNull($this->invio()->respinto($rif, $id, 'Respinto in Titulus dal responsabile', 1));

        $this->assertSame('libero', $this->invio()->stato($rif, $id, ProtocollaDefinitivaFslInTitulus::RIF)['stato']);
        $this->titulus->modo = 'ok';
        $this->db->esegui("UPDATE titulus_regole SET azione = 'protocolla'");
        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1)['errore']);
        $this->assertSame(2, $this->titulus->salvataggi);
    }

    public function testRespintoSiRifiutaSeNonEInApprovazione(): void
    {
        $id = $this->firmata();
        $this->riga_invio($this->rifDef($id), $id, 'ok', '2026-UCAL-5');

        $this->assertIsString($this->invio()->respinto($this->rifDef($id), $id, 'x', 1), 'già protocollato');
        $id2 = $this->firmata();
        $this->assertIsString($this->invio()->respinto($this->rifDef($id2), $id2, 'x', 1), 'nessun invio');
    }

    // ---- P6: la data di protocollo è quella dell'invio riuscito -------------------------------------------------------------------------

    public function testIlNumeroGiaDatoPortaLaDataDellInvioRiuscitoNonQuelladiOggi(): void
    {
        $id = $this->firmata();
        $this->riga_invio($this->rifDef($id), $id, 'ok', '2026-UCALPRG-0555', '2026-09-20 10:30:00');

        $r = $this->definitivo()->protocolla($id, $this->dati(), '', 'admin@x.it', 1);

        $this->assertNull($r['errore'], (string) $r['errore']);
        $this->assertSame(0, $this->titulus->salvataggi, 'il numero era già stato dato');
        $c = $this->cc($id);
        $this->assertSame('2026-UCALPRG-0555 del 20/09/2026', $c['protocollo']);
        $this->assertSame('2026-09-20', $c['protocollo_data']);
    }

    public function testIlNumeroInEntrataGiaDatoPortaLaDataDellInvioRiuscito(): void
    {
        $id = $this->caricata();
        $this->riga_invio($this->rifIng($id), $id, 'ok', '2026-UCALPRG-0444', '2026-10-04 15:00:00');

        $r = $this->ingresso()->protocolla($id, 'admin@x.it', 1);

        $this->assertNull($r['errore'], (string) $r['errore']);
        $this->assertSame(0, $this->titulus->salvataggi);
        $c = $this->cc($id);
        $this->assertSame('2026-UCALPRG-0444', $c['protocollo_ingresso']);
        $this->assertSame('2026-10-04', $c['protocollo_ingresso_data']);
    }

    public function testUnInvioNuovoPortaLaDataDiOggi(): void
    {
        $id = $this->caricata();

        $this->assertNull($this->ingresso()->protocolla($id, 'admin@x.it', 1)['errore']);

        $this->assertSame(date('Y-m-d'), $this->cc($id)['protocollo_ingresso_data']);
    }

    public function testNumeroDaLaDataDellInvio(): void
    {
        $id = $this->firmata();
        $this->riga_invio($this->rifDef($id), $id, 'ok', '2026-UCAL-8', '2026-09-20 10:30:00');
        $r = new RichiestaProtocollo(applicaA: 'convenzione_fsl_definitivo', oggetto: 'Convenzione', principale: AllegatoTitulus::crea('c.pdf', "%PDF-1.7\nx\n%%EOF"),
            esterno: ['nome' => 'Liceo', 'codice_fiscale' => '80001230789'], rifTipo: $this->rifDef($id), rifId: $id, utenteId: 1);

        $n = $this->invio()->numero($r);

        $this->assertSame('2026-UCAL-8', $n['numero']);
        $this->assertSame('2026-09-20', $n['data'] ?? null);
    }

    // ---- T2: la firma Aruba fatta e non registrata perché il blocco è occupato si conserva e si registra dopo, senza un secondo OTP -----------

    /** Un altro processo tiene il blocco dell'istituto per $secondi. @return array{0: resource, 1: array<int, resource>} */
    private function altroProcessoTieneIlBlocco(array $cc, int $secondi): array
    {
        $chiave = $this->servizio(ConvenzioneCompilataRepository::class)->chiaveDi($cc);
        $nome = ConvenzioneCompilataRepository::nomeBlocco($chiave, $this->nomeDatabase());
        $pipe = [];
        $proc = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/tieni_blocco.php', $this->nomeDatabase(), $nome, (string) $secondi], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
        $this->assertIsResource($proc);
        $this->assertSame('preso', trim((string) fgets($pipe[1])), 'l\'altro processo ha il blocco');

        return [$proc, $pipe];
    }

    private function finisci($proc, array $pipe): void
    {
        fclose($pipe[1]);
        fclose($pipe[2]);
        proc_close($proc);
    }

    /** @return list<string> i file conservati (PDF e descrizioni) */
    private function conservati(): array
    {
        return array_map('basename', glob($this->radiceProva . '/uploads/firme_conservate/{fsl_,tesi_}*', GLOB_BRACE) ?: []);
    }

    private function conAttesaBreve(): void
    {
        $this->servizio(ConvenzioneCompilataRepository::class)->impostaAttesaLunga(1);
    }

    public function testFirmaArubaConBloccoOccupatoSiConservaESiRegistraAlTentativoSuccessivo(): void
    {
        $id = $this->daFirmare();
        $this->conAruba(null);
        $this->conAttesaBreve();
        $cc = $this->cc($id);
        [$proc, $pipe] = $this->altroProcessoTieneIlBlocco($cc, 6);

        $e = $this->direttore()->firmaConAruba($cc, 'utente', 'pin', '123456');

        $this->assertSame(ServizioFirmaDirettore::FIRMA_CONSERVATA, $e, 'il Direttore legge che la firma è al sicuro e che non deve firmare di nuovo');
        $this->assertSame(1, $this->aruba->firme);
        $this->assertSame('da_firmare', $this->cc($id)['stato_iter'], 'non è ancora registrata');
        $this->assertTrue($this->direttore()->haFirmaConservata($id));
        $this->assertCount(2, $this->conservati(), 'un PDF e la sua descrizione');
        $this->assertFileExists($this->radiceProva . '/uploads/firme_conservate/.htaccess', 'la cartella è chiusa al web');
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($this->radiceProva . '/uploads/firme_conservate/.htaccess'));
        $json = (string) file_get_contents((string) glob($this->radiceProva . '/uploads/firme_conservate/fsl_*.json')[0]);
        $meta = json_decode($json, true);
        $this->assertSame($id, $meta['id'], 'legata alla convenzione');
        $this->assertSame(hash('sha256', (string) file_get_contents($this->radiceProva . '/' . self::FIRMATA)), $meta['impronta_partenza'], 'e all\'impronta del file da cui si è partiti');
        $this->assertStringNotContainsString('pin', $json, 'niente password salvate');
        $this->assertStringNotContainsString('123456', $json, 'niente OTP salvato');

        // il blocco è ancora occupato: la firma resta in attesa, senza chiamare Aruba
        $r = $this->direttore()->riprendiFirmaConservata($id);
        $this->assertSame('in_attesa', $r['esito']);
        $this->assertSame('da_firmare', $this->cc($id)['stato_iter']);

        $this->finisci($proc, $pipe);

        $r = $this->direttore()->riprendiFirmaConservata($id);
        $this->assertSame('registrata', $r['esito'], $r['messaggio']);
        $dopo = $this->cc($id);
        $this->assertSame('firmata_direttore', $dopo['stato_iter']);
        $this->assertStringContainsString('%%FIRMA direttore', (string) file_get_contents($this->radiceProva . '/' . $dopo['file_definitivo']));
        $this->assertSame(1, $this->aruba->firme, 'Aruba è stata chiamata una volta sola: nessun secondo codice OTP');
        $this->assertSame([], $this->conservati(), 'la firma registrata non resta in giro');
        $this->assertNull($this->direttore()->riprendiFirmaConservata($id), 'una terza volta non c\'è più niente');
        $this->assertStringContainsString('firmato', strtolower(json_encode($this->mailer->inviate) ?: ''), 'l\'ufficio è avvisato della firma');
    }

    /** @return array<string, array{0: string}> */
    public static function cambiamentiCheScartanoLaFirma(): array
    {
        return ['rifiutata dall\'ufficio' => ['rifiuto'], 'nuovo link' => ['link'], 'PDF di partenza cambiato' => ['pdf'], 'registrata a mano' => ['mano']];
    }

    /** @dataProvider cambiamentiCheScartanoLaFirma */
    public function testLaFirmaConservataSiScartaSeLaConvenzioneECambiata(string $cambio): void
    {
        $id = $this->daFirmare();
        $this->conAruba(null);
        $this->conAttesaBreve();
        $cc = $this->cc($id);
        [$proc, $pipe] = $this->altroProcessoTieneIlBlocco($cc, 4);
        $this->assertSame(ServizioFirmaDirettore::FIRMA_CONSERVATA, $this->direttore()->firmaConAruba($cc, 'utente', 'pin', '123456'));
        $this->finisci($proc, $pipe);
        match ($cambio) {
            'rifiuto' => $this->iter()->rifiuta($id, 'Manca la pagina 3'),
            'link' => $this->db->esegui("UPDATE convenzioni_compilate SET token_direttore = 'altrolink' WHERE id = ?", [$id]),
            'pdf' => $this->scrivi(self::FIRMATA, "%PDF-1.7\n<< /Type /Pages /Count 3 /Kids [] >>\n%%FIRMA scuola ma e' un'altra versione\n%%EOF"),
            'mano' => $this->iter()->completa($id, "%PDF-1.7\nfirmato fuori\n%%FIRMA x\n%%EOF", ['protocollo' => 'UCAL-77', 'protocollo_data' => '2026-10-05', 'data_stipula' => '2026-10-10', 'scadenza' => '2027-10-09'], 'admin@x.it'),
        };
        $statoPrima = $this->cc($id)['stato_iter'];
        $file = $this->fileConvenzioni();
        $mail = count($this->mailer->inviate);

        $r = $this->direttore()->riprendiFirmaConservata($id);

        $this->assertSame('scartata', $r['esito'], $cambio);
        $this->assertStringContainsString('ufficio', $r['messaggio']);
        $this->assertSame($statoPrima, $this->cc($id)['stato_iter'], 'la convenzione non cambia per colpa della firma vecchia');
        $this->assertNull($this->cc($id)['file_definitivo'] ?? null, 'nessun file firmato registrato');
        $this->assertSame($file, $this->fileConvenzioni(), 'nessun file in più');
        $this->assertSame([], $this->conservati(), 'il file conservato è scartato');
        $nuove = array_slice($this->mailer->inviate, $mail);
        $this->assertNotEmpty($nuove, 'l\'ufficio è avvisato');
        $this->assertStringContainsString('NON registrata', json_encode($nuove, JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertSame(1, $this->aruba->firme);
    }

    public function testIlPdfCaricatoAManoNonSiConservaERestaIlRicaricalo(): void
    {
        $id = $this->daFirmare();
        $this->conAruba(null);
        $this->conAttesaBreve();
        $cc = $this->cc($id);
        [$proc, $pipe] = $this->altroProcessoTieneIlBlocco($cc, 4);
        $firmato = (string) file_get_contents($this->radiceProva . '/' . self::FIRMATA) . "\n%%FIRMA direttore\n";

        $e = $this->direttore()->caricaFirmato($cc, $firmato);

        $this->finisci($proc, $pipe);
        $this->assertSame(ServizioFirmaDirettore::RICARICALO, $e);
        $this->assertStringContainsString('riprova tra un minuto a caricare il PDF firmato', (string) $e);
        $this->assertSame([], $this->conservati(), 'un PDF caricato a mano non si conserva');
        $this->assertFalse($this->direttore()->haFirmaConservata($id));
    }

    public function testIlCronRegistraLeFirmeConservate(): void
    {
        $id = $this->daFirmare();
        $this->conAruba(null);
        $this->conAttesaBreve();
        $cc = $this->cc($id);
        [$proc, $pipe] = $this->altroProcessoTieneIlBlocco($cc, 4);
        $this->assertSame(ServizioFirmaDirettore::FIRMA_CONSERVATA, $this->direttore()->firmaConAruba($cc, 'utente', 'pin', '123456'));
        $this->assertSame(0, $this->direttore()->riprendiTutte(), 'con il blocco ancora occupato il cron non registra niente');
        $this->finisci($proc, $pipe);

        $this->assertSame(1, $this->direttore()->riprendiTutte());

        $this->assertSame('firmata_direttore', $this->cc($id)['stato_iter']);
        $this->assertSame(0, $this->direttore()->riprendiTutte(), 'una seconda corsa non fa niente');
        $this->assertSame(1, $this->aruba->firme);
    }

    public function testUnaFirmaConservataManomessaNonSiRegistra(): void
    {
        $id = $this->daFirmare();
        $this->conAruba(null);
        $this->conAttesaBreve();
        $cc = $this->cc($id);
        [$proc, $pipe] = $this->altroProcessoTieneIlBlocco($cc, 4);
        $this->assertSame(ServizioFirmaDirettore::FIRMA_CONSERVATA, $this->direttore()->firmaConAruba($cc, 'utente', 'pin', '123456'));
        $this->finisci($proc, $pipe);
        file_put_contents((string) glob($this->radiceProva . '/uploads/firme_conservate/fsl_*.pdf')[0], "%PDF-1.7\nun altro file\n%%EOF");

        $this->assertNull($this->direttore()->riprendiFirmaConservata($id), 'un file alterato non vale: scartato');

        $this->assertSame('da_firmare', $this->cc($id)['stato_iter']);
        $this->assertSame([], $this->conservati());
    }
}
