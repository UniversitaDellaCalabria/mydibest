<?php

declare(strict_types=1);

namespace Tests\Integration\Protocollo;

use App\Core\Ambiente;
use App\Core\Sito;
use App\Infrastructure\Sicurezza\Cifratura;
use App\Infrastructure\Storage\Upload;
use App\Protocollo\FileProtocollato;
use App\Protocollo\ProtocolloRepository;
use App\Protocollo\ServizioProtocollo;
use RuntimeException;
use Tests\Integration\DatabaseDiProva;
use Unical\Titulus\ClienteTitulus;
use Unical\Titulus\ConfigTitulus;
use Unical\Titulus\FabbricaClienteTitulus;
use Unical\Titulus\GeneratoreXml;

/**
 * Passo 7: le due voci di un documento protocollato (originale e documento protocollato), il file ripreso da Titulus (cron, 8 tentativi, 20 per giro,
 * cartella chiusa, impronta), la pulizia con il documento. Titulus è finto: non esce nulla dal portale.
 */
final class FileProtocollatoTest extends DatabaseDiProva
{
    private object $cliente;
    private string $radice;

    protected function tabelle(): array
    {
        return [
            'titulus_config' => self::tabellaVera('titulus_config'),
            'titulus_regole' => self::tabellaVera('titulus_regole'),
            'titulus_cc' => self::tabellaVera('titulus_cc'),
            'titulus_invii' => self::tabellaVera('titulus_invii'),
            'protocollo_file' => self::tabellaVera('protocollo_file'),
            'pratiche' => self::tabellaVera('pratiche'),
            'pratiche_eventi' => self::tabellaVera('pratiche_eventi'),
            'pratiche_firme' => self::tabellaVera('pratiche_firme'),
            'tutorato_incarichi' => self::tabellaVera('tutorato_incarichi'),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->allineaColonne();
        $this->radice = sys_get_temp_dir() . '/prova_fp_' . bin2hex(random_bytes(4));
        mkdir($this->radice, 0777, true);
        $this->cliente = new class () implements ClienteTitulus {
            /** @var list<array{titolo: string, fileId: string}> */
            public array $file = [];
            /** @var array<string, string> */
            public array $contenuti = [];
            public ?string $errore = null;
            /** @var list<string> */
            public array $lettiId = [];

            public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array
            {
                return ['nrecord' => '1', 'num_prot' => 'X', 'url' => ''];
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
                return 'https://titulus-unical.pp.cineca.it/titulus/doc?id=' . $id;
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
                return $this->contenuti[$fileId] ?? '';
            }

            public function prova(): string
            {
                return 'ok';
            }
        };
    }

    protected function tearDown(): void
    {
        $this->togliCartella($this->radice);
        parent::tearDown();
    }

    private function togliCartella(string $d): void
    {
        foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (basename($f) === '.' || basename($f) === '..') {
                continue;
            }
            is_dir($f) ? $this->togliCartella($f) : @unlink($f);
        }
        @rmdir($d);
    }

    private function servizio(bool $attivo = true): FileProtocollato
    {
        $cifratura = new Cifratura('una-frase-lunga-almeno-sedici');
        $this->db->esegui('DELETE FROM titulus_config');
        $this->db->esegui('INSERT INTO titulus_config (id, attivo, url_ws, username, aoo, password_cifrata) VALUES (1, ?, ?, ?, ?, ?)', [$attivo ? 1 : 0, 'https://titulus-unical.pp.cineca.it/titulus_ws/services/Titulus4?wsdl', 'portale', 'ABC', $cifratura->cifra('segreta')]);
        $cliente = $this->cliente;
        $fabbrica = new class ($cliente) implements FabbricaClienteTitulus {
            public function __construct(private ClienteTitulus $c)
            {
            }

            public function crea(ConfigTitulus $config, string $password): ClienteTitulus
            {
                return $this->c;
            }
        };
        $protocollo = new ServizioProtocollo(new ProtocolloRepository($this->db), $cifratura, $fabbrica, new GeneratoreXml(), new Ambiente());

        return new FileProtocollato($this->db, $protocollo, new Upload(), new Sito($this->radice, 'https://portale.test/eventi'));
    }

    private function pdf(string $segno): string
    {
        return "%PDF-1.7\n% $segno\n%%EOF";
    }

    private function invio(string $rif, int $id, string $num = '2026-UCALPRG-0000001'): void
    {
        $this->db->esegui("INSERT INTO titulus_invii (rif_tipo, rif_id, oggetto, esito, nrecord, num_prot, file_principale) VALUES (?, ?, 'Prova', 'ok', '', ?, 'a.pdf')", [$rif, $id, $num]);
    }

    private function titulusHa(string $contenuto): void
    {
        $this->cliente->file = [['titolo' => 'a.pdf', 'fileId' => 'f1']];
        $this->cliente->contenuti = ['f1' => $contenuto];
    }

    /** Scrive un file nella cartella di quel tipo di documento e dà il percorso relativo. */
    private function metti(string $dir, string $nome, string $contenuto): string
    {
        @mkdir($this->radice . '/' . $dir, 0777, true);
        file_put_contents($this->radice . '/' . $dir . $nome, $contenuto);

        return $dir . $nome;
    }

    private function dirPratiche(): string
    {
        return defined('DIR_PRATICHE') ? (string) DIR_PRATICHE : \App\Didattica\Costanti::DIR_PRATICHE;
    }

    private function dirIncarichi(): string
    {
        return defined('DIR_INCARICHI') ? (string) DIR_INCARICHI : \App\Tutorato\Costanti::DIR_INCARICHI;
    }

    private function pratica(int $id, string $codice, string $domandaPdf, string $protocollo = ''): void
    {
        $this->db->esegui(
            "INSERT INTO pratiche (id, codice, utente_id, modulo_id, stato, nome, cognome, email, matricola, protocollo, domanda_pdf) VALUES (?, ?, 1, 1, 'inviata', 'A', 'B', 'a@b.it', '1', ?, ?)",
            [$id, $codice, $protocollo, $domandaPdf]
        );
    }

    private function evento(int $pratica, string $nome, string $allegato): void
    {
        $this->db->esegui("INSERT INTO pratiche_eventi (pratica_id, tipo, autore, testo, allegato, nome_allegato) VALUES (?, 'attivita', 'ufficio', 't', ?, ?)", [$pratica, $allegato, $nome]);
    }

    // ---- il file di Titulus ------------------------------------------------------------------------------------------------------------------------------

    public function testIlCronRiprendeIlFileLoConservaInCartellaChiusaConImpronta(): void
    {
        $s = $this->servizio();
        $this->invio('pratica_domanda', 7);
        $this->titulusHa($this->pdf('titulus'));
        $s->programma('pratica_domanda', 7, '2026-UCALPRG-0000001');
        self::assertSame('attesa', $s->riga('pratica_domanda', 7)['stato']);

        self::assertSame(1, $s->riprovaTutti());

        $r = $s->riga('pratica_domanda', 7);
        self::assertSame('ok', $r['stato']);
        self::assertSame(hash('sha256', $this->pdf('titulus')), $r['impronta']);
        self::assertNotNull($r['scaricato_il']);
        self::assertSame($this->pdf('titulus'), file_get_contents($this->radice . '/' . $r['file']));
        self::assertStringStartsWith(FileProtocollato::DIR, (string) $r['file']);
        self::assertFileExists($this->radice . '/' . FileProtocollato::DIR . '.htaccess');
        self::assertStringContainsString('Require all denied', (string) file_get_contents($this->radice . '/' . FileProtocollato::DIR . '.htaccess'));
    }

    public function testSenzaCollegamentoAttivoNonSiProgrammaNienteEIlCronNonConsumaTentativi(): void
    {
        $s = $this->servizio(false);
        $s->programma('pratica_domanda', 7, 'X');
        self::assertNull($s->riga('pratica_domanda', 7), 'collegamento spento: nessuna riga');
        $this->db->esegui("INSERT INTO protocollo_file (rif_tipo, rif_id) VALUES ('pratica_domanda', 8)");

        self::assertSame(0, $s->riprovaTutti());
        self::assertSame(0, (int) $s->riga('pratica_domanda', 8)['tentativi'], 'a collegamento spento il cron non conta i tentativi');
        self::assertSame([], $this->cliente->lettiId);
    }

    public function testOttoTentativiUnoAlGiornoPoiSoloIlRiprova(): void
    {
        $s = $this->servizio();
        $this->invio('pratica_uscita', 3);
        $s->programma('pratica_uscita', 3, 'N');
        $this->cliente->errore = 'timeout';

        self::assertSame(0, $s->riprovaTutti());
        self::assertSame('mancante', $s->riga('pratica_uscita', 3)['stato']);
        self::assertSame(1, (int) $s->riga('pratica_uscita', 3)['tentativi']);
        $letti = count($this->cliente->lettiId);
        $s->riprovaTutti();
        self::assertCount($letti, $this->cliente->lettiId, 'lo stesso giorno non si richiede di nuovo');

        for ($k = 2; $k <= 8; ++$k) {
            $this->db->esegui("UPDATE protocollo_file SET ultimo_tentativo_il = NOW() - INTERVAL 21 HOUR WHERE rif_id = 3");
            $s->riprovaTutti();
        }
        self::assertSame(8, (int) $s->riga('pratica_uscita', 3)['tentativi']);
        $letti = count($this->cliente->lettiId);
        $this->db->esegui("UPDATE protocollo_file SET ultimo_tentativo_il = NOW() - INTERVAL 30 HOUR WHERE rif_id = 3");
        $s->riprovaTutti();
        self::assertCount($letti, $this->cliente->lettiId, 'dopo otto tentativi il cron si ferma');

        // «Riprova» a mano funziona ancora, e a questo punto Titulus risponde
        $this->cliente->errore = null;
        $this->titulusHa($this->pdf('tardi'));
        self::assertTrue($s->scarica('pratica_uscita', 3)['ok']);
        self::assertSame('ok', $s->riga('pratica_uscita', 3)['stato']);
    }

    public function testVentiFilePerGiroEUnErroreNonFermaGliAltri(): void
    {
        $s = $this->servizio();
        $this->titulusHa($this->pdf('t'));
        for ($i = 1; $i <= 25; ++$i) {
            if ($i !== 2) {   // il documento 2 non ha un invio riuscito: da Titulus non arriva
                $this->invio('pratica_domanda', $i, 'N' . $i);
            }
            $s->programma('pratica_domanda', $i, $i === 2 ? '' : 'N' . $i);
        }

        $n = $s->riprovaTutti();

        self::assertSame(19, $n, 'venti righe lavorate, una sola in errore');
        self::assertSame('mancante', $s->riga('pratica_domanda', 2)['stato']);
        self::assertSame('ok', $s->riga('pratica_domanda', 3)['stato'], 'l\'errore del documento 2 non ha fermato gli altri');
        self::assertSame('attesa', $s->riga('pratica_domanda', 25)['stato'], 'oltre il ventesimo si aspetta il giro dopo');
        self::assertSame(5, (int) $this->db->valore("SELECT COUNT(*) FROM protocollo_file WHERE stato = 'attesa'"));
        self::assertSame(5, $s->riprovaTutti(), 'il giro dopo riprende le ultime cinque');
    }

    public function testNumeroScrittoAManoSiCercaDalNumeroSenzaInvio(): void
    {
        $s = $this->servizio();
        $this->titulusHa($this->pdf('mano'));
        $this->pratica(9, 'P-9', '', '2026-UCALPRG-0000777');
        $s->programma('pratica_domanda', 9, '2026-UCALPRG-0000777 del 01/01/2026');

        self::assertTrue($s->scarica('pratica_domanda', 9)['ok']);
        self::assertSame(['2026-UCALPRG-0000777'], $this->cliente->lettiId, 'si cerca per numero, senza la data scritta dopo lo spazio');
    }

    public function testRipresaDiUnaPraticaDiPrimaCreaLaRigaEUsaIlNumeroGiusto(): void
    {
        $s = $this->servizio();
        $this->titulusHa($this->pdf('t'));
        // pratica con domanda protocollata a mano e nessun attestato: il numero è della domanda
        $this->pratica(11, 'P-11', '', 'D-111');
        self::assertNull($s->riga('pratica_domanda', 11), 'nessuna riga: nessuna chiamata automatica');
        self::assertSame([], $this->cliente->lettiId);
        self::assertTrue($s->scarica('pratica_domanda', 11)['ok']);
        self::assertSame(['D-111'], $this->cliente->lettiId);

        // pratica con attestato già protocollato: il campo «protocollo» è quello dell'attestato, quindi della domanda non si può dire nulla
        $this->cliente->lettiId = [];
        $this->pratica(12, 'P-12', '', 'U-222');
        $this->evento(12, 'Firmato_direttore_P-12.pdf', 'uploads/pratiche/x.pdf');
        self::assertFalse($s->scarica('pratica_domanda', 12)['ok']);
        self::assertSame([], $this->cliente->lettiId, 'il numero dell\'attestato non si usa per la domanda');
        self::assertTrue($s->scarica('pratica_uscita', 12)['ok']);
        self::assertSame(['U-222'], $this->cliente->lettiId);
    }

    // ---- le due voci ---------------------------------------------------------------------------------------------------------------------------------------

    public function testDomandaOriginaleECopiaStampigliataSonoDueFileELOriginaleNonCambia(): void
    {
        $s = $this->servizio();
        $orig = $this->pdf('domanda originale');
        $rel = $this->metti($this->dirPratiche(), 'domanda_P-1.pdf', $orig);
        $copia = $this->metti($this->dirPratiche(), 'domanda_P-1_prot.pdf', $this->pdf('domanda con segnatura'));
        $this->pratica(1, 'P-1', $rel, 'N1');
        $this->evento(1, 'Domanda_P-1_protocollata.pdf', $copia);

        $v = $s->voci('pratica_domanda', 1);
        self::assertSame('portale', $v['originale']['da']);
        self::assertSame('copia', $v['protocollato']['origine']);
        self::assertSame($orig, file_get_contents($s->apri('pratica_domanda', 1, 'originale')['percorso']), 'l\'originale è byte per byte la domanda');
        self::assertSame($this->pdf('domanda con segnatura'), file_get_contents($s->apri('pratica_domanda', 1, 'protocollato')['percorso']));
        self::assertNull($v['titulus'], 'senza riga nessuna chiamata a Titulus e nessun file di Titulus');
        self::assertNull($s->apri('pratica_domanda', 1, 'titulus'));
        self::assertSame([], $this->cliente->lettiId, 'mostrare le voci non chiama mai Titulus');
        self::assertStringContainsString('copia_per_consultazione', $s->apri('pratica_domanda', 1, 'protocollato')['nome']);
    }

    public function testSenzaCopiaIlDocumentoProtocollatoELOriginaleDiRiservaSonoQuelliDiTitulus(): void
    {
        $s = $this->servizio();
        $this->invio('pratica_domanda', 2);
        $this->titulusHa($this->pdf('da titulus'));
        $this->pratica(2, 'P-2', '', 'N2');   // la domanda del portale non c'è più
        $s->programma('pratica_domanda', 2, 'N2');
        $s->scarica('pratica_domanda', 2);

        $v = $s->voci('pratica_domanda', 2);
        self::assertSame('titulus', $v['originale']['da']);
        self::assertSame('titulus', $v['protocollato']['origine']);
        self::assertSame($this->pdf('da titulus'), file_get_contents($s->apri('pratica_domanda', 2, 'protocollato')['percorso']));
        self::assertSame($this->pdf('da titulus'), file_get_contents($s->apri('pratica_domanda', 2, 'originale')['percorso']));
        self::assertSame('ok', $v['titulus']['stato']);
    }

    public function testAttestatoOrigineManoCopiaOUgualeAllOriginale(): void
    {
        $s = $this->servizio();
        $firmato = $this->pdf('attestato firmato');
        $relFirma = $this->metti($this->dirPratiche(), 'firmato_dir.pdf', $firmato);
        $this->pratica(5, 'P-5', '', 'U5');
        $this->db->esegui("INSERT INTO pratiche_firme (pratica_id, tipo, stato, file_firmato) VALUES (5, 'direttore', 'firmata', ?)", [$relFirma]);
        $relProt = $this->metti($this->dirPratiche(), 'att_prot.pdf', $this->pdf('attestato con segnatura'));
        $this->evento(5, 'Firmato_direttore_P-5.pdf', $relProt);

        $s->programma('pratica_uscita', 5, 'U5', 'mano');
        $v = $s->voci('pratica_uscita', 5);
        self::assertSame('mano', $v['protocollato']['origine']);
        self::assertSame($firmato, file_get_contents($s->apri('pratica_uscita', 5, 'originale')['percorso']), 'il PDF firmato dal Direttore resta quello firmato');
        self::assertSame($this->pdf('attestato con segnatura'), file_get_contents($s->apri('pratica_uscita', 5, 'protocollato')['percorso']));

        $s->programma('pratica_uscita', 5, 'U5', 'copia');
        self::assertSame('copia', $s->voci('pratica_uscita', 5)['protocollato']['origine']);
        self::assertStringContainsString('copia_per_consultazione', $s->apri('pratica_uscita', 5, 'protocollato')['nome']);

        // la copia con la segnatura non si è potuta fare: il documento protocollato è il file firmato stesso
        file_put_contents($this->radice . '/' . $relProt, $firmato);
        self::assertSame('originale', $s->voci('pratica_uscita', 5)['protocollato']['origine']);
    }

    public function testLetteraDiTutoratoOriginaleECopiaPerConsultazione(): void
    {
        $s = $this->servizio();
        $orig = $this->pdf('lettera firmata');
        $f = $this->metti($this->dirIncarichi(), 'L1.pdf', $orig);
        $seg = $this->metti($this->dirIncarichi(), 'L1_prot.pdf', $this->pdf('lettera con segnatura'));
        $this->db->esegui("INSERT INTO tutorato_incarichi (id, codice, stato, file_pdf, file_segnato) VALUES (4, 'TU-4', 'protocollata', ?, ?)", [$f, $seg]);

        $v = $s->voci('tutorato_lettera', 4);
        self::assertTrue($v['originale']['esiste']);
        self::assertSame('copia', $v['protocollato']['origine']);
        self::assertSame($orig, file_get_contents($s->apri('tutorato_lettera', 4, 'originale')['percorso']));
        self::assertSame($this->pdf('lettera con segnatura'), file_get_contents($s->apri('tutorato_lettera', 4, 'protocollato')['percorso']));
    }

    public function testSeLaCopiaNonSiPuoFareLaLetteraProtocollataVale_LaLetteraFirmata_MaSoloDopoIlProtocollo(): void
    {
        $s = $this->servizio();
        $f = $this->metti($this->dirIncarichi(), 'L7.pdf', $this->pdf('lettera firmata 7'));
        $this->db->esegui("INSERT INTO tutorato_incarichi (id, codice, stato, file_pdf) VALUES (7, 'TU-7', 'firmata', ?), (8, 'TU-8', 'protocollata', ?)", [$f, $f]);

        self::assertFalse($s->voci('tutorato_lettera', 7)['protocollato']['esiste'], 'prima del protocollo non c\'è un documento protocollato');
        self::assertNull($s->apri('tutorato_lettera', 7, 'protocollato'));
        $v = $s->voci('tutorato_lettera', 8);
        self::assertSame('originale', $v['protocollato']['origine']);
        self::assertSame($this->pdf('lettera firmata 7'), file_get_contents($s->apri('tutorato_lettera', 8, 'protocollato')['percorso']));

        // quando il file di Titulus arriva, prende il posto della lettera firmata come documento protocollato
        $this->invio('tutorato_lettera', 8);
        $this->titulusHa($this->pdf('titulus 8'));
        $s->programma('tutorato_lettera', 8, 'N8');
        $s->scarica('tutorato_lettera', 8);
        self::assertSame('titulus', $s->voci('tutorato_lettera', 8)['protocollato']['origine']);
        self::assertSame($this->pdf('titulus 8'), file_get_contents($s->apri('tutorato_lettera', 8, 'protocollato')['percorso']));
        self::assertSame($this->pdf('lettera firmata 7'), file_get_contents($s->apri('tutorato_lettera', 8, 'originale')['percorso']), 'l\'originale resta la lettera firmata');
    }

    public function testUnPercorsoFuoriCartellaNonSiApreMai(): void
    {
        $s = $this->servizio();
        file_put_contents($this->radice . '/segreto.pdf', $this->pdf('segreto'));
        @mkdir($this->radice . '/' . $this->dirPratiche(), 0777, true);
        $this->pratica(6, 'P-6', '../../segreto.pdf', 'N6');
        $this->evento(6, 'Domanda_P-6_protocollata.pdf', '../../segreto.pdf');

        $v = $s->voci('pratica_domanda', 6);
        self::assertFalse($v['originale']['esiste']);
        self::assertFalse($v['protocollato']['esiste']);
        self::assertNull($s->apri('pratica_domanda', 6, 'originale'));

        // il file di Titulus è valido solo dentro la sua cartella
        $this->db->esegui("INSERT INTO protocollo_file (rif_tipo, rif_id, stato, file) VALUES ('pratica_domanda', 6, 'ok', '../segreto.pdf')");
        self::assertNull($s->apri('pratica_domanda', 6, 'titulus'));
        self::assertNull($s->apri('pratica_domanda', 6, 'inventata'));
    }

    public function testIdenticoODiversoDall_OriginaleDelPortale(): void
    {
        $s = $this->servizio();
        $orig = $this->pdf('uguale');
        $rel = $this->metti($this->dirPratiche(), 'domanda_P-8.pdf', $orig);
        $this->pratica(8, 'P-8', $rel, 'N8');
        $this->invio('pratica_domanda', 8);
        $s->programma('pratica_domanda', 8, 'N8');
        $this->titulusHa($orig);
        $s->scarica('pratica_domanda', 8);
        self::assertTrue($s->voci('pratica_domanda', 8)['titulus']['identico']);

        $this->titulusHa($this->pdf('diverso'));
        $s->scarica('pratica_domanda', 8);
        self::assertFalse($s->voci('pratica_domanda', 8)['titulus']['identico']);
    }

    // ---- pulizia -------------------------------------------------------------------------------------------------------------------------------------------

    public function testEliminaTogliRigaEFileEUnaPraticaEliminataTogliAncheIlFileDiTitulus(): void
    {
        $s = $this->servizio();
        $this->invio('pratica_domanda', 21);
        $this->titulusHa($this->pdf('t'));
        $this->pratica(21, 'P-21', '', 'N21');
        $s->programma('pratica_domanda', 21, 'N21');
        $s->scarica('pratica_domanda', 21);
        $file = $this->radice . '/' . $s->riga('pratica_domanda', 21)['file'];
        self::assertFileExists($file);

        $elimina = new \App\Didattica\EliminaPratica($this->db, new Sito($this->radice, 'https://portale.test/eventi'), new \App\Didattica\AllegatiPratiche(new Upload(), new Sito($this->radice, 'https://portale.test/eventi')), $s);
        $r = $elimina->elimina(21);

        self::assertNull($r['errore']);
        self::assertFileDoesNotExist($file);
        self::assertNull($s->riga('pratica_domanda', 21));

        // lo stesso, diretto, per la lettera (la conservazione dei dati del tutorato lo chiama)
        $this->db->esegui("INSERT INTO protocollo_file (rif_tipo, rif_id, stato) VALUES ('tutorato_lettera', 4, 'attesa')");
        $s->elimina('tutorato_lettera', 4);
        self::assertNull($s->riga('tutorato_lettera', 4));
    }
}
