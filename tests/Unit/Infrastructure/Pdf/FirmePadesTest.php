<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Pdf;

use App\Infrastructure\Pdf\FirmePades;
use App\Infrastructure\Pdf\MessaggiFirma;
use App\Tutorato\VerificaPdfFirmato;
use PHPUnit\Framework\TestCase;

final class FirmePadesTest extends TestCase
{
    /** Un PDF finto con una firma: dizionario /Sig con /ByteRange [0 50 100 d]; con $coda = false il tratto firmato lascia fuori gli ultimi 40 byte. */
    private function pdfFirmato(bool $coda = true): string
    {
        $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached /Name (Mario Rossi) /M (D:20261006120000+02'00') /ByteRange [0 50 100 XXXX] >>\nendobj\n" . str_repeat('x', 60) . "\n%%EOF\n";

        return str_replace('XXXX', str_pad((string) (strlen($pdf) - 100 - ($coda ? 0 : 40)), 4, ' ', STR_PAD_LEFT), $pdf);
    }

    public function testNonUnPdfNonHaFirme(): void
    {
        $f = new FirmePades();
        $this->assertSame([], $f->firmePades('PK file zip'));
        $this->assertSame([], $f->firmePades(''));
        $this->assertSame([], $f->firmePades("%PDF-1.7\nsenza firme\n%%EOF"));
    }

    public function testLeggeLaFirma(): void
    {
        $firme = (new FirmePades())->firmePades($this->pdfFirmato());
        $this->assertCount(1, $firme);
        $this->assertSame(['ETSI.CAdES.detached', 'Mario Rossi', '20261006120000'], [$firme[0]['subfilter'], $firme[0]['nome'], $firme[0]['data']]);
        $this->assertTrue($firme[0]['valida_struttura']);
        $this->assertTrue($firme[0]['copre_tutto']);
    }

    public function testModificheDopoLaFirma(): void
    {
        $firme = (new FirmePades())->firmePades($this->pdfFirmato(false));
        $this->assertFalse($firme[0]['copre_tutto'], 'il file prosegue oltre il tratto firmato');
    }

    public function testCopreTuttoIgnoraGliSpaziInCoda(): void
    {
        $firme = (new FirmePades())->firmePades($this->pdfFirmato() . "\n\n");
        $this->assertTrue($firme[0]['copre_tutto']);
    }

    private const ORDINI = [['SubFilter', 'Contents', 'ByteRange'], ['SubFilter', 'ByteRange', 'Contents'], ['Contents', 'SubFilter', 'ByteRange'],
        ['Contents', 'ByteRange', 'SubFilter'], ['ByteRange', 'SubFilter', 'Contents'], ['ByteRange', 'Contents', 'SubFilter']];

    /**
     * Aggiunge in coda al PDF una firma (o una marca) con /Contents da 19.000 caratteri e il /ByteRange vero, con le voci nell'ordine indicato.
     *
     * @param list<string> $ordine
     */
    private function aggiungi(string $pdf, array $ordine = ['SubFilter', 'Contents', 'ByteRange'], string $formato = 'ETSI.CAdES.detached', string $altro = '/Name (Mario Rossi) /M (D:20261124100000+01\'00\')', int $cifre = 19000): string
    {
        $voci = [
            'SubFilter' => $formato !== '' ? '/SubFilter /' . $formato : '',
            'Contents' => '/Contents <' . str_repeat('0', $cifre) . '>',
            'ByteRange' => '/ByteRange [0 AAAAAAAAAA BBBBBBBBBB CCCCCCCCCC]',
        ];
        $inizio = strlen($pdf);
        $pdf .= "\n9 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite " . implode(' ', array_map(static fn (string $k): string => $voci[$k], $ordine)) . " $altro >>\nendobj\nxref\n%%EOF\n";
        $esa = (int) strpos($pdf, '/Contents <', $inizio) + 10;
        $dopo = $esa + $cifre + 2;

        return substr($pdf, 0, $inizio) . strtr(substr($pdf, $inizio), ['AAAAAAAAAA' => sprintf('%-10d', $esa), 'BBBBBBBBBB' => sprintf('%-10d', $dopo), 'CCCCCCCCCC' => sprintf('%-10d', strlen($pdf) - $dopo)]);
    }

    private function base(): string
    {
        return "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    }

    public function testISeiOrdiniDelleVociConUnContentsDa19000Caratteri(): void
    {
        foreach (self::ORDINI as $ordine) {
            $dove = implode(' → ', $ordine);
            $pdf = $this->aggiungi($this->base(), $ordine);
            $firme = (new FirmePades())->firmePades($pdf);
            $this->assertCount(1, $firme, $dove);
            $this->assertSame(['ETSI.CAdES.detached', 'Mario Rossi', '20261124100000', false, true, true],
                [$firme[0]['subfilter'], $firme[0]['nome'], $firme[0]['data'], $firme[0]['marca_temporale'], $firme[0]['valida_struttura'], $firme[0]['copre_tutto']], $dove);
            $this->assertNull((new VerificaPdfFirmato(new FirmePades()))->verifica($this->base(), $pdf)[0], "$dove: accettata anche con le regole severe");
        }
    }

    public function testFirmaEMarcaTemporale(): void
    {
        $pdf = $this->aggiungi($this->aggiungi($this->base()), ['ByteRange', 'Contents', 'SubFilter'], FirmePades::MARCA, '');
        $firme = (new FirmePades())->firmePades($pdf);
        $this->assertSame([false, true], array_column($firme, 'marca_temporale'));
        $this->assertSame([false, true], array_column($firme, 'copre_tutto'));
        $this->assertSame(['Mario Rossi', ''], array_column($firme, 'nome'), 'la marca non prende i dati della firma');
        $this->assertCount(1, MessaggiFirma::senzaMarche($firme));
        $v = new VerificaPdfFirmato(new FirmePades());
        $this->assertNull($v->verifica($this->base(), $pdf)[0], 'firma e marca insieme, regole severe');
        [$err, $info] = $v->verifica($this->base(), $pdf, '', false);
        $this->assertSame([null, ''], [$err, $info['avviso']], 'e nessun avviso');
    }

    public function testUnaMarcaDaSolaNonEUnaFirmaNuova(): void
    {
        $v = new VerificaPdfFirmato(new FirmePades());
        $marca = $this->aggiungi($this->base(), self::ORDINI[0], FirmePades::MARCA);
        $firmato = $this->aggiungi($this->base());
        foreach ([true, false] as $severo) {
            $this->assertStringContainsString('firma digitale PAdES', (string) $v->verifica($this->base(), $marca, '', $severo)[0]);
            $this->assertSame("Nel PDF non c'è una nuova firma digitale PAdES.", $v->verifica($firmato, $this->aggiungi($firmato, self::ORDINI[0], FirmePades::MARCA), '', $severo)[0], 'una marca sopra una firma di prima');
        }
    }

    public function testDueFirme(): void
    {
        $v = new VerificaPdfFirmato(new FirmePades());
        $una = $this->aggiungi($this->base(), self::ORDINI[0], 'ETSI.CAdES.detached', '/Name (Anna Verdi)');
        $due = $this->aggiungi($una, self::ORDINI[5], 'adbe.pkcs7.detached', '/Name (Mario Rossi)');
        $this->assertSame([['ETSI.CAdES.detached', 'adbe.pkcs7.detached'], ['Anna Verdi', 'Mario Rossi'], [false, true]],
            [array_column((new FirmePades())->firmePades($due), 'subfilter'), array_column((new FirmePades())->firmePades($due), 'nome'), array_column((new FirmePades())->firmePades($due), 'copre_tutto')]);
        $this->assertNull($v->verifica($una, $due)[0]);
        $this->assertNull($v->verifica($this->base(), $due)[0], 'due firme nuove insieme');
        $this->assertStringContainsString('2 firme nuove', $v->verifica($this->base(), $due, '', false)[1]['avviso'], 'sulle convenzioni l\'ufficio lo sa');
        // la seconda firma è la prima volta senza formato: non eredita quello della firma precedente
        $senza = (new FirmePades())->firmePades($this->aggiungi($una, self::ORDINI[0], '', ''));
        $this->assertSame(['', ''], [$senza[1]['subfilter'], $senza[1]['nome']], 'i dati di un altro oggetto non si prendono mai');
    }

    public function testDizionarioConTrappole(): void
    {
        // parentesi, «>>», «endobj» e «5 0 obj» nelle stringhe, /Name e /SubFilter di dizionari annidati, nome in UTF-16
        $altro = '/Prop_Build << /App << /Name (Programma) /SubFilter /falso >> >> /Reason (\(nota\) 5 0 obj << >> endobj (a)) /Reference [<< /M (D:19990101000000) >>] /Name <FEFF004D006100720069006F00200050006100E8> /M (D:20261124101500Z)';
        foreach (self::ORDINI as $ordine) {
            $x = (new FirmePades())->firmePades(str_replace('/Type /Sig', "/Type /Sig $altro", $this->aggiungi($this->base(), $ordine, 'ETSI.CAdES.detached', '')));
            $this->assertCount(1, $x);
            $this->assertSame(['ETSI.CAdES.detached', 'Mario Paè', '20261124101500'], [$x[0]['subfilter'], $x[0]['nome'], $x[0]['data']], implode(' → ', $ordine));
        }
        $this->assertSame([], (new FirmePades())->firmePades($this->base() . "(/ByteRange [0 10 20 30])\n3 0 obj\n<< /Reason (/ByteRange [0 10 20 30]) >>\nendobj\n"), 'la scritta in un testo non è una firma');
    }

    public function testUnFileDa20MegabyteSiLegge(): void
    {
        $pdf = $this->aggiungi($this->base() . "2 0 obj\n<< /Length 1 >>\nstream\n" . str_repeat('obj (<< a ', 2000000) . "\nendstream\nendobj\n", self::ORDINI[0], 'ETSI.CAdES.detached', '/Name (Mario Rossi)', 60000);
        $this->assertGreaterThan(20000000, strlen($pdf));
        $firme = (new FirmePades())->firmePades($pdf);
        $this->assertSame([['ETSI.CAdES.detached'], [true]], [array_column($firme, 'subfilter'), array_column($firme, 'copre_tutto')]);
    }

    /** Un PDF ostile non deve tenere occupato il server: il risultato è «senza firme», con una riga nel log, in poco tempo. */
    public function testIPdfOstiliHannoUnTettoFisso(): void
    {
        $firma = substr($this->aggiungi($this->base()), strlen($this->base()));
        $vicino = intdiv(1900000, 3);
        $casi = [
            '4000 /ByteRange annidati' => "%PDF-1.7\n1 0 obj\n[" . str_repeat('<</ByteRange [0 1 2 3]>>', 4000) . "]\nendobj\n",
            '4000 /ByteRange, prima una firma vera' => $this->base() . $firma . "8 0 obj\n[" . str_repeat('<</ByteRange [0 1 2 3]>>', 4000) . "]\nendobj\n",
            '400.000 /ByteRange sparsi in 10 MB' => "%PDF-1.7\n1 0 obj\n" . str_repeat("/ByteRange [0 1 2 3] abcd\n", 400000),
            '400.000 /ByteRange senza numeri' => "%PDF-1.7\n1 0 obj\n" . str_repeat("/ByteRange xxxxxxxxxxxxxxx", 400000),
            '2.000.000 di «<<(»' => "%PDF-1.7\n1 0 obj\n" . str_repeat('<<(', 2000000) . '/ByteRange [0 1 2 3]',
            '«<<(» entro la distanza ammessa' => "%PDF-1.7\n1 0 obj\n" . str_repeat('<<(', $vicino) . '/ByteRange [0 1 2 3]',
            '«<<» annidati' => "%PDF-1.7\n1 0 obj\n" . str_repeat('<<', 900000) . '/ByteRange [0 1 2 3]',
            'tanti obj seguiti da parole' => "%PDF-1.7\n" . str_repeat('1 0 obj<<' . str_repeat('/a', 90000), 10) . str_repeat('/ByteRange [0 1 2 3]', 100),
            'una parola lunghissima' => "%PDF-1.7\n1 0 obj<</K " . str_repeat('a', 1900000) . str_repeat(' /ByteRange [0 1 2 3]', 100),
            'esadecimali mai chiusi' => "%PDF-1.7\n" . str_repeat('1 0 obj<</K <', 10) . str_repeat(' /ByteRange [0 1 2 3]', 100) . str_repeat('0', 10000000),
            'testi mai chiusi' => "%PDF-1.7\n" . str_repeat('1 0 obj<</K (', 10) . str_repeat(' /ByteRange [0 1 2 3]', 100) . str_repeat('0', 10000000),
            'spazi' => "%PDF-1.7\n1 0 obj<<" . str_repeat(' ', 1900000) . str_repeat(' /ByteRange [0 1 2 3]', 101),
            'lontano dal suo oggetto' => "%PDF-1.7\n1 0 obj\n<< /Contents <" . str_repeat('0', 3000000) . '> /ByteRange [0 1 2 3] >>',
        ];
        $log = (string) tempnam(sys_get_temp_dir(), 'log');
        $prima = (string) ini_get('error_log');
        ini_set('error_log', $log);
        try {
            foreach ($casi as $nome => $pdf) {
                file_put_contents($log, '');
                $da = microtime(true);
                $firme = (new FirmePades())->firmePades($pdf);
                $this->assertLessThan(2.0, microtime(true) - $da, $nome);
                $this->assertSame([], $firme, "$nome: mai un controllo a metà");
                if ($nome !== 'esadecimali mai chiusi') {
                    $this->assertStringContainsString('file non controllabile', (string) file_get_contents($log), $nome);
                }
            }
        } finally {
            ini_set('error_log', $prima);
            unlink($log);
        }
    }

    public function testUnPdfVeroConVentiFirmePassa(): void
    {
        $pdf = $this->base();
        for ($n = 0; $n < 20; $n++) {
            $pdf = $this->aggiungi($pdf, self::ORDINI[$n % 6], 'ETSI.CAdES.detached', '/Name (Persona ' . $n . ')', $n === 7 ? 1200000 : 60000);
        }
        $firme = (new FirmePades())->firmePades($pdf);
        $this->assertCount(20, $firme);
        $this->assertSame(['Persona 7', 'Persona 19'], [$firme[7]['nome'], $firme[19]['nome']]);
        $this->assertTrue($firme[19]['copre_tutto']);

        $pdf = $this->base();
        for ($n = 0; $n < 20; $n++) {
            $pdf = $this->aggiungi($pdf, self::ORDINI[0], 'ETSI.CAdES.detached', '/Name (Mario Rossi)', 1000000);
        }
        $this->assertCount(20, (new FirmePades())->firmePades($pdf), 'venti firme con /Contents da 1 MB');
    }

    public function testGliAvvisiNonSiTaglianoAMetaParola(): void
    {
        $this->assertSame('corto', MessaggiFirma::taglia('corto'));
        $this->assertSame(str_repeat('è', 500), MessaggiFirma::taglia(str_repeat('è', 500)));
        $this->assertSame('uno due…', MessaggiFirma::taglia('uno due tremila quattro', 12));
        $this->assertSame('uno due…', MessaggiFirma::taglia('uno due tremila', 8), 'il taglio cade giusto alla fine di una parola');
        $lungo = MessaggiFirma::taglia(str_repeat('perché ', 200));
        $this->assertLessThanOrEqual(500, mb_strlen($lungo));
        $this->assertStringEndsWith('perché…', $lungo);
    }

    public function testLAvvisoSulCertificatoDiUnAltraPersonaVienePrima(): void
    {
        $dir = sys_get_temp_dir() . '/firma_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents("$dir/o.cnf", "[req]\ndistinguished_name=dn\n[dn]\n");
            $conf = ['config' => "$dir/o.cnf", 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
            $chiave = openssl_pkey_new($conf);
            $this->assertNotFalse($chiave);
            $csr = openssl_csr_new(['commonName' => 'Anna Verdi', 'serialNumber' => 'TINIT-VRDNNA80A41D086Y'], $chiave, $conf);
            $cert = openssl_csr_sign($csr, null, $chiave, 2, $conf);
            $base = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
            $pdf = $this->aggiungi("%PDF-1.7\n1 0 obj\n<< /Type /Catalog /Riscritto true >>\nendobj\n", ['SubFilter', 'Contents', 'ByteRange'], 'formato.strano', '');
            // $integra = false: la firma è fatta su byte diversi
            $firma = function (bool $integra) use ($dir, $cert, $chiave, $pdf): string {
                [, $b, $c, $d] = (new FirmePades())->firmePades($pdf)[0]['intervallo'];
                file_put_contents("$dir/d", $integra ? substr($pdf, 0, $b) . substr($pdf, $c, $d) : 'altri byte');
                $this->assertTrue(openssl_cms_sign("$dir/d", "$dir/f", $cert, $chiave, [], OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED, OPENSSL_ENCODING_DER));
                $esa = bin2hex((string) file_get_contents("$dir/f"));

                return substr_replace($pdf, $esa, $b + 1, strlen($esa)) . "\nbyte dopo la firma, abbastanza da non essere coperti";
            };
            $v = new VerificaPdfFirmato(new FirmePades());

            [$err, $info] = $v->verifica($base, $firma(true), 'RSSMRA80A01D086X', false);
            $this->assertNull($err);
            $this->assertStringStartsWith('Il certificato è di Anna Verdi (codice fiscale VRDNNA80A41D086Y)', $info['avviso']);
            $this->assertStringContainsString('riscritto dal programma', $info['avviso']);

            [$err, $info] = $v->verifica($base, $firma(false), 'RSSMRA80A01D086X', false);
            $this->assertNull($err);
            $this->assertStringStartsWith('Il controllo automatico del certificato non è riuscito', $info['avviso']);
            $posto = static fn (string $t): int => (int) mb_strpos($info['avviso'], $t);
            $this->assertGreaterThan(0, $posto('non copre tutto il file'));
            $this->assertLessThan($posto('riscritto dal programma'), $posto('non copre tutto il file'), 'integrità prima del resto');
            $this->assertLessThan($posto('formato della firma'), $posto('riscritto dal programma'));
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }
}
