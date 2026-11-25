<?php

declare(strict_types=1);

namespace Tests\Integration\Protocollo;

use App\Core\Ambiente;
use App\Infrastructure\Sicurezza\Cifratura;
use App\Protocollo\ProtocolloRepository;
use App\Protocollo\RichiestaProtocollo;
use App\Protocollo\ServizioProtocollo;
use RuntimeException;
use Tests\Integration\DatabaseDiProva;
use Unical\Titulus\AllegatoTitulus;
use Unical\Titulus\ClienteTitulus;
use Unical\Titulus\ConfigTitulus;
use Unical\Titulus\FabbricaClienteTitulus;
use Unical\Titulus\GeneratoreXml;

/** Il file di un documento già protocollato, ripreso da Titulus (sola lettura): dal registro degli invii e dal numero scritto a mano. */
final class FileDaTitulusTest extends DatabaseDiProva
{
    private const URL_PREPROD = 'https://titulus-unical.pp.cineca.it/titulus_ws/services/Titulus4?wsdl';
    private const URL_PRODUZIONE = 'https://titulus-unical.cineca.it/titulus_ws/services/Titulus4?wsdl';

    private object $cliente;
    private object $fabbrica;

    protected function tabelle(): array
    {
        return [
            'titulus_config' => self::tabellaVera('titulus_config'),
            'titulus_regole' => self::tabellaVera('titulus_regole'),
            'titulus_cc' => self::tabellaVera('titulus_cc'),
            'titulus_invii' => self::tabellaVera('titulus_invii'),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cliente = new class () implements ClienteTitulus {
            /** @var list<array{titolo: string, fileId: string, principale?: bool, sha256?: ?string}> */
            public array $file = [];
            /** @var array<string, string> */
            public array $contenuti = [];
            public ?string $errore = null;
            public ?string $url = 'https://titulus-unical.pp.cineca.it/titulus/doc?id=1';
            /** @var list<string> */
            public array $lettiId = [];
            public array $scaricati = [];

            public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array
            {
                return ['nrecord' => '555', 'num_prot' => '2026-UCALPRG-0000555', 'url' => ''];
            }

            public function fascicola(string $xmlFascicolo, string $matricolaRpa): void
            {
            }

            public function infoRecord(string $nrecord): array
            {
                return [];
            }

            public function urlDocumento(string $id): ?string
            {
                $this->lettiId[] = 'url:' . $id;

                return $this->url;
            }

            public function fileDelDocumento(string $id): array
            {
                $this->lettiId[] = $id;
                if ($this->errore !== null) {
                    throw new RuntimeException($this->errore);
                }

                return $this->file;
            }

            public function scaricaFile(string $fileId): string
            {
                $this->scaricati[] = $fileId;
                if ($this->errore !== null) {
                    throw new RuntimeException($this->errore);
                }

                return $this->contenuti[$fileId] ?? '';
            }

            public function prova(): string
            {
                return 'ok';
            }
        };
        $this->fabbrica = new class ($this->cliente) implements FabbricaClienteTitulus {
            public int $chiamate = 0;

            public function __construct(private ClienteTitulus $c)
            {
            }

            public function crea(ConfigTitulus $config, string $password): ClienteTitulus
            {
                ++$this->chiamate;

                return $this->c;
            }
        };
    }

    private function servizio(string $url = self::URL_PREPROD, ?Ambiente $ambiente = null, bool $attivo = true): ServizioProtocollo
    {
        $cifratura = new Cifratura('una-frase-lunga-almeno-sedici');
        $this->db->esegui('DELETE FROM titulus_config');
        $this->db->esegui('INSERT INTO titulus_config (id, attivo, url_ws, username, aoo, password_cifrata) VALUES (1, ?, ?, ?, ?, ?)', [$attivo ? 1 : 0, $url, 'portale', 'ABC', $cifratura->cifra('segreta')]);
        $this->db->esegui("INSERT INTO titulus_regole (nome, applica_a, tipo_documento, uo, cod_titolario) VALUES ('Generale', 'generale', 'arrivo', '2014', '3/2')");

        return new ServizioProtocollo(new ProtocolloRepository($this->db), $cifratura, $this->fabbrica, new GeneratoreXml(), $ambiente ?? new Ambiente());
    }

    private function invio(string $esito, string $nrecord, string $num, string $principale = 'convenzione.pdf', string $rifTipo = 'convenzione', int $rifId = 5, string $url = ''): int
    {
        return $this->db->inserisci(
            'INSERT INTO titulus_invii (rif_tipo, rif_id, oggetto, esito, nrecord, num_prot, url, file_principale) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$rifTipo, $rifId, 'Prova', $esito, $nrecord, $num, $url, $principale]
        );
    }

    private function pdf(string $segno = 'x'): string
    {
        return "%PDF-1.7\n% $segno\n%%EOF";
    }

    public function testUnSoloFileSiScaricaDalNumeroDiRecord(): void
    {
        $this->invio('ok', '777', '2026-UCALPRG-0001');
        $this->cliente->file = [['titolo' => 'qualunque.pdf', 'fileId' => 'f1']];
        $this->cliente->contenuti = ['f1' => $this->pdf()];

        $r = $this->servizio()->scaricaFile('convenzione', 5);

        self::assertTrue($r['ok'], $r['messaggio']);
        self::assertSame($this->pdf(), $r['contenuto']);
        self::assertSame('qualunque.pdf', $r['titolo']);
        self::assertSame(['777'], $this->cliente->lettiId, 'si usa il numero di record');
    }

    public function testSeMancaIlRecordSiUsaIlNumeroDiProtocollo(): void
    {
        $this->invio('ok', '', '2026-UCALPRG-0042');
        $this->cliente->file = [['titolo' => 'a.pdf', 'fileId' => 'f1']];
        $this->cliente->contenuti = ['f1' => $this->pdf()];

        $r = $this->servizio()->scaricaFile('convenzione', 5);

        self::assertTrue($r['ok'], $r['messaggio']);
        self::assertSame(['2026-UCALPRG-0042'], $this->cliente->lettiId);
    }

    public function testSiGuardanoSoloGliInviiRiusciti(): void
    {
        $this->invio('errore', '888', '');
        $this->cliente->file = [['titolo' => 'a.pdf', 'fileId' => 'f1']];
        $svc = $this->servizio();

        $r = $svc->scaricaFile('convenzione', 5);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('non c\'è un invio riuscito', $r['messaggio']);
        self::assertSame([], $this->cliente->lettiId, 'Titulus non è stato interpellato');
        self::assertFalse($svc->scaricaFile('convenzione', 999)['ok'], 'un riferimento senza invii');
    }

    public function testConPiuInviiValeQuelloRiuscitoPiuRecente(): void
    {
        $this->invio('ok', '100', '', 'vecchio.pdf');
        $this->invio('errore', '', '');
        $this->invio('ok', '200', '', 'nuovo.pdf');
        $this->cliente->file = [['titolo' => 'nuovo.pdf', 'fileId' => 'f1']];
        $this->cliente->contenuti = ['f1' => $this->pdf()];

        $this->servizio()->scaricaFile('convenzione', 5);

        self::assertSame(['200'], $this->cliente->lettiId);
    }

    public function testPiuFileIlPrincipaleSiRiconoscePerTitolo(): void
    {
        $this->invio('ok', '777', '2026-UCALPRG-0001', 'Convenzione.PDF');
        $this->cliente->file = [['titolo' => 'allegato_a.pdf', 'fileId' => 'a1'], ['titolo' => 'convenzione.pdf', 'fileId' => 'c1'], ['titolo' => 'note.docx', 'fileId' => 'd1']];
        $this->cliente->contenuti = ['a1' => $this->pdf('A'), 'c1' => $this->pdf('principale'), 'd1' => 'x'];

        $r = $this->servizio()->scaricaFile('convenzione', 5);

        self::assertTrue($r['ok'], $r['messaggio']);
        self::assertSame($this->pdf('principale'), $r['contenuto'], 'non il primo file a caso');
        self::assertSame(['c1'], $this->cliente->scaricati);
    }

    public function testIlFileSegnatoPrincipaleDaTitulusVinceSulTitolo(): void
    {
        $this->invio('ok', '777', '2026-UCALPRG-0001', 'titolo-inviato-diverso.pdf');
        $this->cliente->file = [
            ['titolo' => 'allegato_a.pdf', 'fileId' => 'a1', 'principale' => false, 'sha256' => null],
            ['titolo' => 'convenzione.pdf', 'fileId' => 'c1', 'principale' => true, 'sha256' => null],
        ];
        $this->cliente->contenuti = ['a1' => $this->pdf('A'), 'c1' => $this->pdf('principale')];

        $r = $this->servizio()->scaricaFile('convenzione', 5);

        self::assertTrue($r['ok'], $r['messaggio']);
        self::assertSame(['c1'], $this->cliente->scaricati);
    }

    public function testDuePrincipaliSiTornaAlTitoloEConNessunTitoloErrore(): void
    {
        $this->cliente->file = [
            ['titolo' => 'uno.pdf', 'fileId' => 'a1', 'principale' => true, 'sha256' => null],
            ['titolo' => 'due.pdf', 'fileId' => 'b1', 'principale' => true, 'sha256' => null],
        ];
        $this->cliente->contenuti = ['a1' => $this->pdf('1'), 'b1' => $this->pdf('2')];
        $svc = $this->servizio();

        $this->invio('ok', '777', '', 'due.pdf');
        $r = $svc->scaricaFile('convenzione', 5);
        self::assertTrue($r['ok'], $r['messaggio']);
        self::assertSame(['b1'], $this->cliente->scaricati, 'due principali: vale il titolo inviato');

        $this->cliente->scaricati = [];
        $this->db->esegui('DELETE FROM titulus_invii');
        $this->invio('ok', '777', '', 'altro.pdf');
        $r = $svc->scaricaFile('convenzione', 5);
        self::assertFalse($r['ok']);
        self::assertSame([], $this->cliente->scaricati, 'mai uno a caso');
    }

    public function testLImprontaDichiarataDeveCoincidereOppureNienteFile(): void
    {
        $this->invio('ok', '777', '2026-UCALPRG-0001', 'convenzione.pdf');
        $pdf = $this->pdf('vero');
        $this->cliente->contenuti = ['c1' => $pdf];
        $svc = $this->servizio();

        $this->cliente->file = [['titolo' => 'convenzione.pdf', 'fileId' => 'c1', 'principale' => true, 'sha256' => hash('sha256', $pdf)]];
        self::assertTrue($svc->scaricaFile('convenzione', 5)['ok'], 'coincide');

        $this->cliente->file = [['titolo' => 'convenzione.pdf', 'fileId' => 'c1', 'principale' => true, 'sha256' => hash('sha256', 'altro')]];
        $r = $svc->scaricaFile('convenzione', 5);
        self::assertFalse($r['ok']);
        self::assertSame('', $r['contenuto'], 'impronta diversa: nessun file');
        self::assertStringContainsString('impronta', $r['messaggio']);
        self::assertFalse($svc->scaricaFilePerNumero('2026-UCALPRG-0001', 'convenzione.pdf')['ok'], 'anche dal numero scritto a mano');

        $this->cliente->file = [['titolo' => 'convenzione.pdf', 'fileId' => 'c1', 'principale' => true, 'sha256' => null]];
        self::assertTrue($svc->scaricaFile('convenzione', 5)['ok'], 'impronta assente: si va avanti');
        $this->cliente->file = [['titolo' => 'convenzione.pdf', 'fileId' => 'c1']];
        self::assertTrue($svc->scaricaFile('convenzione', 5)['ok'], 'campo mancante: si va avanti');
    }

    public function testPiuFileSenzaTitoloRiconoscibileNonSiScaricaNulla(): void
    {
        $this->cliente->file = [['titolo' => 'uno.pdf', 'fileId' => 'a1'], ['titolo' => 'due.pdf', 'fileId' => 'b1']];
        $this->cliente->contenuti = ['a1' => $this->pdf('1'), 'b1' => $this->pdf('2')];
        $svc = $this->servizio();

        foreach (['altro.pdf', ''] as $inviato) {
            $this->db->esegui('DELETE FROM titulus_invii');
            $this->invio('ok', '777', '', $inviato);
            $r = $svc->scaricaFile('convenzione', 5);
            self::assertFalse($r['ok']);
            self::assertStringContainsString('non riesco a capire quale è il principale', $r['messaggio']);
            self::assertSame('', $r['contenuto']);
        }
        self::assertSame([], $this->cliente->scaricati, 'nessun file scaricato');
    }

    public function testDueFileConLoStessoTitoloNonSiSceglieUnoACaso(): void
    {
        $this->invio('ok', '777', '', 'doppio.pdf');
        $this->cliente->file = [['titolo' => 'doppio.pdf', 'fileId' => 'p1'], ['titolo' => 'doppio.pdf', 'fileId' => 'p2']];
        $this->cliente->contenuti = ['p1' => $this->pdf('1'), 'p2' => $this->pdf('2')];

        $r = $this->servizio()->scaricaFile('convenzione', 5);

        self::assertFalse($r['ok']);
        self::assertSame([], $this->cliente->scaricati);
    }

    public function testRiconoscimentoDelPrincipale(): void
    {
        $f = [['titolo' => 'Domanda.pdf', 'fileId' => '1'], ['titolo' => 'Domanda.p7m', 'fileId' => '2'], ['titolo' => 'Allegato.pdf', 'fileId' => '3']];
        self::assertSame('1', ServizioProtocollo::riconosciPrincipale($f, ' domanda.PDF ')['fileId'] ?? null, 'maiuscole e spazi');
        self::assertSame('3', ServizioProtocollo::riconosciPrincipale($f, 'allegato')['fileId'] ?? null, 'stesso titolo senza estensione, uno solo');
        self::assertNull(ServizioProtocollo::riconosciPrincipale($f, 'domanda'), 'senza estensione ma due file simili: ambiguo');
        self::assertNull(ServizioProtocollo::riconosciPrincipale($f, 'altra'), 'titolo che non c\'è');
        self::assertNull(ServizioProtocollo::riconosciPrincipale($f, '  '), 'titolo vuoto');
    }

    public function testDocumentoSenzaFile(): void
    {
        $this->invio('ok', '777', '');
        $this->cliente->file = [];

        $r = $this->servizio()->scaricaFile('convenzione', 5);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('nessun file', $r['messaggio']);
    }

    public function testUnFileCheNonEUnPdfSiRifiuta(): void
    {
        $this->invio('ok', '777', '');
        $this->cliente->file = [['titolo' => 'foglio.xlsx', 'fileId' => 'x1']];
        $this->cliente->contenuti = ['x1' => "PK\x03\x04 non sono un pdf"];

        $r = $this->servizio()->scaricaFile('convenzione', 5);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('non è un PDF', $r['messaggio']);
        self::assertSame('', $r['contenuto']);
    }

    public function testOltreTrentaMegabyteSiRifiuta(): void
    {
        $this->invio('ok', '777', '');
        $this->cliente->file = [['titolo' => 'grande.pdf', 'fileId' => 'g1']];
        $this->cliente->contenuti = ['g1' => "%PDF-1.7\n" . str_repeat('x', ServizioProtocollo::MAX_BYTE_FILE)];
        $svc = $this->servizio();

        $r = $svc->scaricaFile('convenzione', 5);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('più grande di 30 MB', $r['messaggio']);
        self::assertSame('', $r['contenuto']);

        // esattamente al limite: passa
        $this->cliente->contenuti = ['g1' => "%PDF-1.7\n" . str_repeat('x', ServizioProtocollo::MAX_BYTE_FILE - 9)];
        self::assertTrue($svc->scaricaFile('convenzione', 5)['ok']);
    }

    public function testPermessoNegatoEDocumentoInesistente(): void
    {
        $this->invio('ok', '777', '');
        $svc = $this->servizio();

        $this->cliente->errore = 'Titulus ha risposto con un errore (loadDocument): HTTP 403 Forbidden';
        $r = $svc->scaricaFile('convenzione', 5);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('Permesso negato', $r['messaggio']);

        $this->cliente->errore = 'Titulus ha risposto con un errore (loadDocument): Document 777 not found';
        self::assertStringContainsString('non esiste in Titulus', $svc->scaricaFile('convenzione', 5)['messaggio']);

        $this->cliente->errore = 'qualcosa di strano';
        self::assertStringContainsString('Risposta inattesa', $svc->scaricaFile('convenzione', 5)['messaggio']);
    }

    public function testPermessoNegatoSoloSulFile(): void
    {
        $this->invio('ok', '777', '');
        $this->cliente->file = [['titolo' => 'a.pdf', 'fileId' => 'f1']];
        $svc = $this->servizio();
        // l'elenco si legge, il file no
        $this->cliente = new class ($this->cliente) implements ClienteTitulus {
            public function __construct(private ClienteTitulus $c)
            {
            }

            public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array
            {
                return $this->c->salvaDocumento($xmlDocumento, $allegati, $inviaEmail);
            }

            public function fascicola(string $xmlFascicolo, string $matricolaRpa): void
            {
            }

            public function infoRecord(string $nrecord): array
            {
                return [];
            }

            public function urlDocumento(string $id): ?string
            {
                return null;
            }

            public function fileDelDocumento(string $id): array
            {
                return $this->c->fileDelDocumento($id);
            }

            public function scaricaFile(string $fileId): string
            {
                throw new RuntimeException('Titulus ha risposto con un errore (getAttachment): 403 Forbidden');
            }

            public function prova(): string
            {
                return 'ok';
            }
        };
        $svc = new ServizioProtocollo(new ProtocolloRepository($this->db), new Cifratura('una-frase-lunga-almeno-sedici'), new class ($this->cliente) implements FabbricaClienteTitulus {
            public function __construct(private ClienteTitulus $c)
            {
            }

            public function crea(ConfigTitulus $config, string $password): ClienteTitulus
            {
                return $this->c;
            }
        }, new GeneratoreXml(), new Ambiente());

        $r = $svc->scaricaFile('convenzione', 5);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Permesso negato', $r['messaggio']);
    }

    public function testLaCopiaDiProvaConIndirizzoDiProduzioneNonSiCollega(): void
    {
        $this->invio('ok', '777', '');
        $svc = $this->servizio(self::URL_PRODUZIONE, new Ambiente(true, 'prove@unical.it'));

        $r = $svc->scaricaFile('convenzione', 5);
        $p = $svc->scaricaFilePerNumero('2026-UCALPRG-0001');
        $u = $svc->indirizzoDelloInvio($this->invio('ok', '9', ''));

        self::assertFalse($r['ok']);
        self::assertStringContainsString('Ambiente di prova', $r['messaggio']);
        self::assertStringContainsString('pre-produzione', $r['messaggio']);
        self::assertFalse($p['ok']);
        self::assertFalse($u['ok']);
        self::assertSame(0, $this->fabbrica->chiamate, 'nessun collegamento a Titulus');
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM titulus_invii WHERE esito = \'errore\''), 'leggere non scrive nel registro');
    }

    public function testLaCopiaDiProvaConLaPreproduzioneLegge(): void
    {
        $this->invio('ok', '777', '');
        $this->cliente->file = [['titolo' => 'a.pdf', 'fileId' => 'f1']];
        $this->cliente->contenuti = ['f1' => $this->pdf()];

        $r = $this->servizio(self::URL_PREPROD, new Ambiente(true, 'prove@unical.it'))->scaricaFile('convenzione', 5);

        self::assertTrue($r['ok'], $r['messaggio']);
    }

    public function testCollegamentoSpentoOPasswordChePartenonSiLegge(): void
    {
        $this->invio('ok', '777', '');
        $r = $this->servizio(self::URL_PREPROD, null, false)->scaricaFile('convenzione', 5);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('non è attivo', $r['messaggio']);
        self::assertSame(0, $this->fabbrica->chiamate);

        $svc = $this->servizio();
        $this->db->esegui('UPDATE titulus_config SET password_cifrata = ? WHERE id = 1', ['v1:non-decifrabile']);
        $r = $svc->scaricaFile('convenzione', 5);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('password', $r['messaggio']);
        self::assertSame(0, $this->fabbrica->chiamate);
    }

    public function testDalNumeroScrittoAMano(): void
    {
        $this->cliente->file = [['titolo' => 'a.pdf', 'fileId' => 'f1']];
        $this->cliente->contenuti = ['f1' => $this->pdf()];
        $svc = $this->servizio();

        $r = $svc->scaricaFilePerNumero(' 2026-UCALPRG-0001234 ');

        self::assertTrue($r['ok'], $r['messaggio']);
        self::assertSame(['2026-UCALPRG-0001234'], $this->cliente->lettiId);
        foreach (['', 'con spazi dentro', "a\nb", '../../etc/passwd x', str_repeat('1', 101), 'a;b'] as $cattivo) {
            self::assertFalse($svc->scaricaFilePerNumero($cattivo)['ok'], "«" . addcslashes($cattivo, "\n") . '»');
        }
        self::assertCount(1, $this->cliente->lettiId, 'i numeri scritti male non arrivano a Titulus');
    }

    public function testDalNumeroConPiuFileServeIlTitolo(): void
    {
        $this->cliente->file = [['titolo' => 'uno.pdf', 'fileId' => 'a1'], ['titolo' => 'due.pdf', 'fileId' => 'b1']];
        $this->cliente->contenuti = ['a1' => $this->pdf('1'), 'b1' => $this->pdf('2')];
        $svc = $this->servizio();

        self::assertFalse($svc->scaricaFilePerNumero('2026-UCALPRG-0001234')['ok']);
        self::assertStringContainsString('non so con che titolo', $svc->scaricaFilePerNumero('2026-UCALPRG-0001234')['messaggio']);
        $r = $svc->scaricaFilePerNumero('2026-UCALPRG-0001234', 'due.pdf');
        self::assertTrue($r['ok'], $r['messaggio']);
        self::assertSame($this->pdf('2'), $r['contenuto']);
    }

    public function testIlRegistroRicordaIlTitoloDelFilePrincipale(): void
    {
        $svc = $this->servizio();
        $richiesta = new RichiestaProtocollo('generale', 'Domanda di prova', AllegatoTitulus::crea('domanda_77', "%PDF-1.4\nprova"), [], ['nome' => 'Anna', 'cognome' => 'Verdi'], null, 'pratica', 77);

        $esito = $svc->protocolla($richiesta);

        self::assertTrue($esito->ok, $esito->messaggio);
        self::assertSame('domanda_77.pdf', $this->db->valore('SELECT file_principale FROM titulus_invii WHERE rif_tipo = \'pratica\' AND rif_id = 77'));
        // e un invio fallito ricorda lo stesso nome
        $this->db->esegui('UPDATE titulus_config SET attivo = 0');
        $svc->protocolla($richiesta);
        self::assertSame(['domanda_77.pdf', 'domanda_77.pdf'], array_column($this->db->righe('SELECT file_principale FROM titulus_invii ORDER BY id'), 'file_principale'));
    }

    // ---- link a Titulus ----------------------------------------------------------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function indirizzi(): array
    {
        return [
            'configurato' => ['https://titulus-unical.pp.cineca.it/titulus/doc?id=1', true],
            'host configurato con altra porta' => ['https://titulus-unical.pp.cineca.it:8443/x', true],
            'altro host cineca' => ['https://altro.cineca.it/doc', true],
            'maiuscole' => ['HTTPS://Titulus.CINECA.IT/doc', true],
            'http' => ['http://titulus-unical.pp.cineca.it/doc', false],
            'host falso a coda' => ['https://cineca.it.esempio.com/doc', false],
            'host falso simile' => ['https://evilcineca.it/doc', false],
            'cineca.it nudo' => ['https://cineca.it/doc', false],
            'nel testo ma non nell\'host' => ['https://esempio.com/titulus.cineca.it/doc', false],
            'nel parametro' => ['https://esempio.com/?x=.cineca.it', false],
            'utente nell\'indirizzo' => ['https://titulus.cineca.it@esempio.com/doc', false],
            'utente e host buono' => ['https://utente:pass@titulus.cineca.it/doc', false],
            'barra rovesciata' => ['https://esempio.com\\@titulus.cineca.it/doc', false],
            'javascript' => ['javascript:alert(1)//.cineca.it', false],
            'senza schema' => ['titulus.cineca.it/doc', false],
            'con spazio' => ['https://titulus.cineca.it/doc x', false],
            'con apice' => ['https://titulus.cineca.it/"onmouseover=x', false],
            'vuoto' => ['', false],
            'file' => ['file:///etc/passwd', false],
            'https senza host' => ['https:///x.cineca.it', false],
            'punto finale' => ['https://titulus.cineca.it./doc', false],
        ];
    }

    /** @dataProvider indirizzi */
    public function testIndirizzoSicuro(string $url, bool $sicuro): void
    {
        $svc = $this->servizio('https://titulus-unical.pp.cineca.it/titulus_ws/services/Titulus4?wsdl');

        self::assertSame($sicuro ? $url : null, $svc->indirizzoSicuro($url));
    }

    public function testUnHostConfiguratoNonCinecaVaBene(): void
    {
        $svc = $this->servizio('https://protocollo.unical.example/ws/Titulus4?wsdl');

        self::assertNotNull($svc->indirizzoSicuro('https://protocollo.unical.example/doc'));
        self::assertNull($svc->indirizzoSicuro('https://altro.unical.example/doc'));
        self::assertNull($svc->indirizzoSicuro('https://protocollo.unical.example.esempio.com/doc'));
    }

    public function testIndirizzoGiaSalvatoNonSiChiedeATitulus(): void
    {
        $id = $this->invio('ok', '777', '', 'a.pdf', 'convenzione', 5, 'https://titulus-unical.pp.cineca.it/doc?id=777');

        $r = $this->servizio()->indirizzoDelloInvio($id);

        self::assertTrue($r['ok']);
        self::assertSame('https://titulus-unical.pp.cineca.it/doc?id=777', $r['url']);
        self::assertSame(0, $this->fabbrica->chiamate);
    }

    public function testIndirizzoMancanteSiChiedeATitulusESiSalva(): void
    {
        $id = $this->invio('ok', '777', '');
        $this->cliente->url = 'https://titulus-unical.pp.cineca.it/titulus/doc?id=777';

        $r = $this->servizio()->indirizzoDelloInvio($id);

        self::assertTrue($r['ok'], $r['messaggio']);
        self::assertSame(['url:777'], $this->cliente->lettiId);
        self::assertSame('https://titulus-unical.pp.cineca.it/titulus/doc?id=777', $this->db->valore('SELECT url FROM titulus_invii WHERE id = ?', [$id]));
    }

    public function testUnIndirizzoSbagliatoDiTitulusNonSiSalva(): void
    {
        $id = $this->invio('ok', '777', '');
        $svc = $this->servizio();

        foreach (['https://cineca.it.esempio.com/x', 'http://titulus-unical.pp.cineca.it/x', '', null] as $url) {
            $this->cliente->url = $url;
            self::assertFalse($svc->indirizzoDelloInvio($id)['ok']);
        }
        self::assertSame('', $this->db->valore('SELECT url FROM titulus_invii WHERE id = ?', [$id]));
        self::assertFalse($svc->indirizzoDelloInvio($this->invio('errore', '', ''))['ok'], 'invio non riuscito');
        self::assertFalse($svc->indirizzoDelloInvio($this->invio('ok', '', ''))['ok'], 'senza record né numero');
        self::assertFalse($svc->indirizzoDelloInvio(99999)['ok'], 'invio che non c\'è');
    }
}
