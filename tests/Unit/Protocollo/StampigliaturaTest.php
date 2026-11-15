<?php

declare(strict_types=1);

namespace Tests\Unit\Protocollo;

use App\Protocollo\Stampigliatura;
use PHPUnit\Framework\TestCase;

/** Il testo della stampigliatura come quella di Titulus: «AOO Prot. n. numero del data». */
final class StampigliaturaTest extends TestCase
{
    public function testDalNumeroDiTitulusSiRicavanoAooENumero(): void
    {
        $this->assertSame('UCALPRG Prot. n. 0001052 del 15/11/2026', Stampigliatura::testo('2026-UCALPRG-0001052', '2026-11-15'));
        $this->assertSame('UNICALARELARU Prot. n. 0286820 del 30/10/2026', Stampigliatura::testo('2026-UNICALARELARU-0286820', '2026-10-30 09:00:00'));
    }

    public function testUnNumeroScrittoAManoResta(): void
    {
        $this->assertSame('Prot. n. 123/2026 del 05/10/2026', Stampigliatura::testo(' 123/2026 ', '2026-10-05'));
        $this->assertSame('Prot. n. 55', Stampigliatura::testo('55', null), 'senza data niente «del»');
        $this->assertSame('', Stampigliatura::testo('  ', '2026-10-05'));
    }

    public function testSulPdfNonTimbraSenzaNumeroNeUnFileCheNonELo(): void
    {
        $this->assertNull(Stampigliatura::sulPdf("%PDF-1.4\n%%EOF", '', '2026-10-05'));
        $this->assertNull(Stampigliatura::sulPdf('non un pdf', '2026-X-1', '2026-10-05'));
    }
}
