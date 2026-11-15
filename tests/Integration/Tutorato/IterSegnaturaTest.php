<?php

declare(strict_types=1);

namespace Tests\Integration\Tutorato;

use App\Tutorato\ArchivioIncarichi;
use App\Tutorato\Costanti;
use App\Tutorato\ProtocolloAutomaticoLettera;
use App\Tutorato\ServizioIncarichi;
use App\Tutorato\ServizioRegistroTutorato;

/**
 * Iter per Uniticket dopo la firma: copia della lettera con la segnatura di protocollo, email al gestore con i file per il ticket
 * e pulsante «Sollecita» al docente e al Direttore che tardano.
 */
final class IterSegnaturaTest extends TutoratoBase
{
    private function s(): ServizioIncarichi
    {
        return $this->servizio(ServizioIncarichi::class);
    }

    /** Una lettera nello stato richiesto, per l'iter indicato. */
    private function lettera_in_stato(string $iter, string $stato): int
    {
        $this->bando();
        $this->servizio(\App\Tutorato\BandoRepository::class)->impostaIter(1, $iter);
        [$id] = $this->s()->salva($this->campi());
        if ($iter === Costanti::ITER_UNITICKET) {
            $this->s()->presaServizio($id, 'Operatore');
        }
        $this->s()->invia($id);
        $this->assertNull($this->s()->conferma($id, ['id' => 7, 'codice_fiscale' => 'rsslcu00a31d086x'], ['metodo' => 'spid', 'livello' => 2, 'idp' => 'https://idp.test', 'spid_code' => 'ABC123']));
        foreach (['confermata' => 'docente', 'firmata_docente' => 'direttore'] as $da => $ruolo) {
            if ($stato === $da) {
                $this->mailer->inviate = [];

                return $id;
            }
            $pdf = (string) file_get_contents((string) $this->servizio(ArchivioIncarichi::class)->pdfCorrente($this->lettera($id)));
            $this->assertNull($this->s()->registraFirma($id, $ruolo, $this->firmato($pdf)));
        }
        $this->mailer->inviate = [];

        return $id;
    }

    public function testIlProtocolloCreaLaCopiaConLaSegnaturaEL_OriginaleNonCambia(): void
    {
        $id = $this->lettera_in_stato(Costanti::ITER_PORTALE, 'firmata');
        $archivio = $this->servizio(ArchivioIncarichi::class);
        $originale = (string) file_get_contents((string) $archivio->pdfCorrente($this->lettera($id)));

        $this->assertNull($this->s()->protocolla($id, '0001052', '2026-10-05', true, 'Operatore'));
        $l = $this->lettera($id);
        $copia = $archivio->segnatoCorrente($l);
        $this->assertNotNull($copia, 'dopo il protocollo c\'è la copia con la segnatura');
        $pdf = (string) file_get_contents($copia);
        $this->assertStringStartsWith($originale, $pdf, 'la copia è l\'originale firmato con il timbro in coda');
        $this->assertStringContainsString('(Prot. n. 0001052 del 05/10/2026) Tj', $pdf);
        $this->assertSame($originale, (string) file_get_contents((string) $archivio->pdfCorrente($l)), 'l\'originale firmato (quello di Titulus) resta com\'è');
        $this->assertNotSame($l['file_pdf'], $l['file_segnato']);

        // La copia allo studente è quella con la segnatura
        $allegato = $this->mailer->inviate[0]['allegati'][0]['path'] ?? '';
        $this->assertSame(realpath($copia), realpath((string) $allegato));
        $eventi = implode(' | ', array_column($this->s()->eventi($id), 'testo'));
        $this->assertStringContainsString('Creata la copia della lettera con la segnatura', $eventi);

        // Si vede tra i documenti della pratica
        $docs = array_column($this->servizio(\App\Tutorato\ServizioPagamenti::class)->documenti($l), 'disponibile', 'chiave');
        $this->assertTrue($docs['lettera_segnata']);
    }

    public function testSeIlPdfNonSiPuoTimbrareValeLOriginale(): void
    {
        $id = $this->lettera_in_stato(Costanti::ITER_PORTALE, 'firmata');
        $archivio = $this->servizio(ArchivioIncarichi::class);
        file_put_contents((string) $archivio->pdfCorrente($this->lettera($id)), '%PDF-1.4 danneggiato %%EOF');

        $this->assertNull($this->s()->protocolla($id, '77', '2026-10-05', true, 'Operatore'));
        $l = $this->lettera($id);
        $this->assertEmpty($l['file_segnato']);
        $this->assertStringContainsString('non creata', implode(' | ', array_column($this->s()->eventi($id), 'testo')));
        $this->assertSame(realpath((string) $archivio->pdfCorrente($l)), realpath((string) ($this->mailer->inviate[0]['allegati'][0]['path'] ?? '')), 'allo studente va la lettera firmata di prima');
    }

    public function testNellIterPerUniticketIlGestoreSaQuandoIlPortaleHaProtocollato(): void
    {
        $id = $this->lettera_in_stato(Costanti::ITER_UNITICKET, 'firmata');
        $this->assertNull($this->s()->protocolla($id, '0001052', '2026-10-05', true, ProtocolloAutomaticoLettera::AUTORE));
        $a = array_column($this->mailer->inviate, 'a');
        $this->assertSame(['luca@x.it', 'admin@x.it'], $a);
        $m = $this->mailer->inviate[1];
        $this->assertSame('Lettera di incarico protocollata: da caricare sul ticket – Rossi Luca', $m['oggetto']);
        $this->assertStringContainsString('0001052', $m['corpo']);
        $this->assertStringContainsString('dichiarazione individuale', $m['corpo']);
        $this->assertStringContainsString('/admin/tutorato.php?incarico=' . $id, $m['corpo']);
    }

    public function testChiProtocollaAManoONelPortaleNonRiceveLEmailAlGestore(): void
    {
        $a = $this->lettera_in_stato(Costanti::ITER_UNITICKET, 'firmata');
        $this->assertNull($this->s()->protocolla($a, '1', '2026-10-05', false, 'Operatore'));
        $this->assertSame([], $this->mailer->inviate, 'l\'operatore che protocolla a mano sa già di averlo fatto');
        $this->tearDown();
        $this->setUp();
        $b = $this->lettera_in_stato(Costanti::ITER_PORTALE, 'firmata');
        $this->assertNull($this->s()->protocolla($b, '2', '2026-10-05', false, ProtocolloAutomaticoLettera::AUTORE));
        $this->assertSame([], $this->mailer->inviate, 'la email con i file per il ticket è solo dell\'iter per Uniticket');
    }

    public function testSollecitoManualeAlDocenteEAlDirettore(): void
    {
        $r = $this->servizio(ServizioRegistroTutorato::class);
        $id = $this->lettera_in_stato(Costanti::ITER_UNITICKET, 'confermata');

        $this->assertNull($r->sollecitaFirma($id, 'Operatore'));
        $this->assertCount(1, $this->mailer->inviate);
        $m = $this->mailer->inviate[0];
        $l = $this->lettera($id);
        $this->assertSame(strtolower((string) $l['docente_email']), strtolower($m['a']));
        $this->assertStringContainsString('/firma_incarico.php?t=' . $l['token_docente'], $m['corpo']);
        $this->assertSame(0, (int) $l['solleciti'], 'il sollecito manuale non conta tra i tre automatici');
        $this->assertNotNull($l['sollecito_il']);
        $this->assertStringContainsString('Sollecito della firma inviato a', implode(' | ', array_column($this->s()->eventi($id), 'testo')));

        $this->assertStringContainsString('già sollecitato', (string) $r->sollecitaFirma($id), 'al massimo una volta al giorno');
        $this->assertCount(1, $this->mailer->inviate);

        // Dopo la firma del docente tocca al Direttore
        $this->db->esegui('UPDATE tutorato_incarichi SET sollecito_il = NULL WHERE id = ?', [$id]);
        $pdf = (string) file_get_contents((string) $this->servizio(ArchivioIncarichi::class)->pdfCorrente($this->lettera($id)));
        $this->assertNull($this->s()->registraFirma($id, 'docente', $this->firmato($pdf)));
        $this->mailer->inviate = [];
        $this->assertNull($r->sollecitaFirma($id));
        $this->assertSame('direttore@x.it', $this->mailer->inviate[0]['a']);
        $this->assertStringContainsString('/firma_incarico.php?t=' . $this->lettera($id)['token_direttore'], $this->mailer->inviate[0]['corpo']);
    }

    public function testNonSiSollecitaSenzaFirmaInAttesa(): void
    {
        $r = $this->servizio(ServizioRegistroTutorato::class);
        $id = $this->lettera_in_stato(Costanti::ITER_PORTALE, 'firmata');
        $this->assertStringContainsString('nessuna firma in attesa', (string) $r->sollecitaFirma($id));
        $this->assertSame('Lettera non trovata.', $r->sollecitaFirma(9999));
        $this->assertSame([], $this->mailer->inviate);
    }
}
