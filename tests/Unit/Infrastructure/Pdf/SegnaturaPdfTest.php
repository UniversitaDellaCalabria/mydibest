<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Pdf;

use App\Infrastructure\Pdf\Documento;
use App\Infrastructure\Pdf\SegnaturaPdf;
use PHPUnit\Framework\TestCase;

/** La segnatura di protocollo sul PDF già firmato: aggiornamento incrementale, i byte di prima non cambiano. */
final class SegnaturaPdfTest extends TestCase
{
    private const TESTO = 'Prot. n. 0001052 del 15/11/2026';

    private function pdfClassico(): string
    {
        $d = new Documento();
        $d->paragrafo('Lettera di incarico di tutorato');

        return $d->pdf();
    }

    /** Un PDF con la pagina dentro un flusso di oggetti compressi (ObjStm) e la tabella dei riferimenti come flusso (xref stream). */
    private function pdfConFlussi(): string
    {
        $o3 = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R >>';
        $o2 = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $o1 = '<< /Type /Catalog /Pages 2 0 R >>';
        $testi = [$o1, $o2, $o3];
        $intest = '';
        $corpo = '';
        foreach ($testi as $k => $t) {
            $intest .= ($k + 1) . ' ' . strlen($corpo) . ' ';
            $corpo .= $t . "\n";
        }
        $dati = gzcompress($intest . $corpo);
        $pdf = "%PDF-1.7\n";
        $off4 = strlen($pdf);
        $pdf .= "4 0 obj\n<< /Type /ObjStm /N 3 /First " . strlen($intest) . ' /Filter /FlateDecode /Length ' . strlen($dati) . " >>\nstream\n" . $dati . "\nendstream\nendobj\n";
        $off5 = strlen($pdf);
        $cont = 'BT /F1 12 Tf 72 700 Td (Ciao) Tj ET';
        $pdf .= "5 0 obj\n<< /Length " . strlen($cont) . " >>\nstream\n" . $cont . "\nendstream\nendobj\n";
        $off6 = strlen($pdf);
        $righe = chr(0) . pack('N', 0) . pack('n', 65535)
            . chr(2) . pack('N', 4) . pack('n', 0)
            . chr(2) . pack('N', 4) . pack('n', 1)
            . chr(2) . pack('N', 4) . pack('n', 2)
            . chr(1) . pack('N', $off4) . pack('n', 0)
            . chr(1) . pack('N', $off5) . pack('n', 0)
            . chr(1) . pack('N', $off6) . pack('n', 0);
        $pdf .= "6 0 obj\n<< /Type /XRef /Size 7 /W [1 4 2] /Root 1 0 R /Length " . strlen($righe) . " >>\nstream\n" . $righe . "\nendstream\nendobj\n";

        return $pdf . "startxref\n" . $off6 . "\n%%EOF\n";
    }

    public function testPdfClassicoRestaIntattoEPortaIlTimbro(): void
    {
        $in = $this->pdfClassico();
        $out = (new SegnaturaPdf())->applica($in, self::TESTO);
        $this->assertNotNull($out);
        $this->assertStringStartsWith($in, $out, 'i byte di prima (e le firme che coprono) non cambiano');
        $coda = substr($out, strlen($in));
        $this->assertStringContainsString('/Subtype /Stamp', $coda);
        $this->assertStringContainsString('(' . self::TESTO . ') Tj', $coda);
        $this->assertStringContainsString('/Annots [', $coda);
        $this->assertSame(1, preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $out, $m));
        $this->assertSame('xref', substr($out, (int) $m[1], 4), 'la nuova tabella parte dove dice startxref');
        $this->assertStringContainsString('/Prev ', $coda);
    }

    public function testPdfConFlussiXrefEOggettiCompressi(): void
    {
        $in = $this->pdfConFlussi();
        $out = (new SegnaturaPdf())->applica($in, self::TESTO);
        $this->assertNotNull($out);
        $this->assertStringStartsWith($in, $out);
        $this->assertStringContainsString('/Type /XRef', substr($out, strlen($in)), 'con la xref a flusso anche l\'aggiornamento usa un flusso');
        $this->assertStringContainsString('/Annots [', substr($out, strlen($in)));
        // Si può ripetere: la copia con la segnatura si legge ancora
        $this->assertNotNull((new SegnaturaPdf())->applica($out, 'Seconda segnatura'));
    }

    public function testElencoAnnotazioniEsistenteSiAllunga(): void
    {
        $in = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 612 792] >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /Annots [4 0 R] >>\nendobj\n4 0 obj\n<< /Type /Annot /Subtype /Link /Rect [0 0 10 10] >>\nendobj\n";
        $o = [];
        foreach ([1, 2, 3, 4] as $n) {
            $o[$n] = strpos($in, $n . " 0 obj");
        }
        $x = strlen($in);
        $in .= "xref\n0 5\n0000000000 65535 f \n";
        foreach ($o as $off) {
            $in .= sprintf("%010d 00000 n \n", $off);
        }
        $in .= "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n" . $x . "\n%%EOF\n";
        $out = (new SegnaturaPdf())->applica($in, self::TESTO);
        $this->assertNotNull($out);
        $this->assertMatchesRegularExpression('#/Annots \[4 0 R 6 0 R\]#', substr($out, strlen($in)), 'la pagina ha già le sue annotazioni: si aggiunge in fondo');
        $this->assertStringContainsString('/MediaBox', $in, 'la scatola della pagina è ereditata dal nodo padre');
        $this->assertStringContainsString('/Rect [', substr($out, strlen($in)));
    }

    public function testCasiCheNonSiTimbrano(): void
    {
        $s = new SegnaturaPdf();
        $this->assertNull($s->applica('non un pdf', self::TESTO));
        $this->assertNull($s->applica($this->pdfClassico(), '  '), 'senza testo non c\'è niente da scrivere');
        $cifrato = str_replace('/Root', '/Encrypt 9 0 R /Root', $this->pdfClassico());
        $this->assertNull($s->applica($cifrato, self::TESTO), 'i PDF cifrati non si toccano');
        $this->assertNull($s->applica("%PDF-1.4\nsenza struttura\n%%EOF", self::TESTO));
    }

    public function testLarghezzaDelTesto(): void
    {
        $this->assertEqualsWithDelta(6.116, SegnaturaPdf::larghezza('0', 11.0), 0.01);
        $this->assertGreaterThan(SegnaturaPdf::larghezza('Prot.', 11.0), SegnaturaPdf::larghezza(self::TESTO, 11.0));
    }
}
