<?php

declare(strict_types=1);

namespace Tests\Unit\Tutorato;

use App\Sistema\FileEnv;
use App\Tutorato\Costanti;
use App\Tutorato\DatiLettera;
use App\Tutorato\FirmaRemotaAruba;
use App\Tutorato\ServizioRegistroTutorato;
use App\Tutorato\VerificaPdfFirmato;
use App\Tutorato\Vista\StatiIncarico;
use PHPUnit\Framework\TestCase;
use Tests\Doppi\FirmeFinte;
use Tests\Doppi\OrologioFisso;

/** Dati della lettera, stati, ore del registro, controllo dei PDF firmati e firma remota: nessun database. */
final class TutoratoUnitTest extends TestCase
{
    /** @return array<string, mixed> */
    private function lettera(array $v = []): array
    {
        return $v + [
            'genere' => 'M', 'nome' => 'Luca', 'cognome' => 'Rossi', 'luogo_nascita' => 'Cosenza', 'data_nascita' => '2000-01-31', 'comune_residenza' => 'Rende', 'indirizzo' => 'Via Roma', 'civico' => '12',
            'codice_fiscale' => 'RSSLCU00A31D086X', 'attivita' => "Tutorato\nseconda riga", 'ore' => '35.5', 'periodo' => 'dal 01/11 al 28/02', 'compenso' => '1200.5', 'codice' => 'TU-ABCD1234',
            'data_lettera' => '2026-10-03', 'inviata_il' => null, 'decreto_bando' => '123/2026', 'decreto_bando_data' => '2026-09-01', 'decreto_commissione' => '', 'decreto_commissione_data' => null,
            'luogo' => '', 'docente_nome' => 'Maria', 'docente_cognome' => 'Verdi', 'direttore_nome' => 'Mario Bianchi',
        ];
    }

    public function testNumeriDallaScritturaItaliana(): void
    {
        $this->assertSame(1250.5, DatiLettera::numeroItaliano('1.250,50'));
        $this->assertSame(1250.5, DatiLettera::numeroItaliano('1250.5'));
        $this->assertSame(300.0, DatiLettera::numeroItaliano('€ 300'));
        $this->assertSame(35.5, DatiLettera::numeroItaliano(35.5));
        $this->assertNull(DatiLettera::numeroItaliano(''));
        $this->assertNull(DatiLettera::numeroItaliano('abc'));
        $this->assertSame('3,5', DatiLettera::oreTesto('3.50'));
        $this->assertSame('12', DatiLettera::oreTesto(12.0));
        $this->assertSame('0', DatiLettera::oreTesto(0));
    }

    public function testTestoDelDecretoENomeDelFile(): void
    {
        $this->assertSame('123/2026 del 15/09/2026', DatiLettera::decretoTesto('123/2026', '2026-09-15'));
        $this->assertSame('123/2026', DatiLettera::decretoTesto(' 123/2026 ', null));
        $this->assertSame('15/09/2026', DatiLettera::decretoTesto('', '2026-09-15'));
        $this->assertSame('', DatiLettera::decretoTesto(null, null));
        $this->assertSame('LETTERA_INCARICO_ROSSI_LUCA.pdf', DatiLettera::nomeFile($this->lettera()));
        $this->assertSame('LETTERA_INCARICO_DELL_ACQUA_ANDREA_firmata.pdf', DatiLettera::nomeFile($this->lettera(['cognome' => "Dell'Acqua", 'nome' => 'Andrea']), 'firmata'));
    }

    public function testValoriDellaLettera(): void
    {
        $d = (new DatiLettera(new OrologioFisso('2026-10-05 12:00:00')))->valori($this->lettera());
        $this->assertSame(['Dott.', 'Luca Rossi', 'nato', 'vincitore'], [$d['TITOLO'], $d['NOMINATIVO'], $d['NATO'], $d['VINCITORE']]);
        $this->assertSame('Via Roma, n. 12', $d['INDIRIZZO']);
        $this->assertSame('31/01/2000', $d['DATA_NASCITA']);
        $this->assertSame('123/2026 del 01/09/2026', $d['DECRETO_BANDO']);
        $this->assertSame('________', $d['DECRETO_COMMISSIONE'], 'decreto mancante: riga da compilare');
        $this->assertSame(['35,5', '€ 1.200,50', 'Rende', '03/10/2026'], [$d['ORE'], $d['COMPENSO'], $d['LUOGO'], $d['DATA_LETTERA']]);
        $this->assertSame(['Prof. Maria Verdi', 'Prof. Mario Bianchi'], [$d['FIRMA_DOCENTE'], $d['FIRMA_DIRETTORE']]);

        $f = (new DatiLettera(new OrologioFisso('2026-10-05 12:00:00')))->valori($this->lettera(['genere' => 'F', 'luogo_nascita' => '', 'data_nascita' => null, 'civico' => '', 'data_lettera' => null, 'ore' => null, 'compenso' => null, 'luogo' => 'Cosenza']));
        $this->assertSame(['Dott.ssa', 'nata', 'vincitrice', '________', '________', 'Via Roma'], [$f['TITOLO'], $f['NATO'], $f['VINCITORE'], $f['LUOGO_NASCITA'], $f['DATA_NASCITA'], $f['INDIRIZZO']]);
        $this->assertSame(['', '', 'Cosenza', '05/10/2026'], [$f['ORE'], $f['COMPENSO'], $f['LUOGO'], $f['DATA_LETTERA']], 'senza data della lettera: oggi');
        $g = (new DatiLettera(new OrologioFisso('2026-10-05 12:00:00')))->valori($this->lettera(['data_lettera' => null, 'inviata_il' => '2026-09-20 10:00:00']));
        $this->assertSame('20/09/2026', $g['DATA_LETTERA'], "senza data della lettera: il giorno dell'invio");
    }

    public function testLImprontaCambiaConIDatiMaNonConLaData(): void
    {
        $dati = new DatiLettera(new OrologioFisso());
        $a = $dati->impronta($this->lettera());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a);
        $this->assertSame($a, $dati->impronta($this->lettera(['data_lettera' => '2026-12-01'])));
        $this->assertNotSame($a, $dati->impronta($this->lettera(['ore' => '36'])));
        $this->assertNotSame($a, $dati->impronta($this->lettera(['codice' => 'TU-ALTRO'])));
    }

    public function testStatiEPassiDellaLettera(): void
    {
        // StatiIncarico::badge() passa da ui_stato_colore() solo se inc/ui.php è già caricato: lo carichiamo noi, così la prova non dipende dall'ordine.
        require_once RADICE_SITO . '/inc/ui.php';
        $this->assertSame('<span class="ui-stato attesa"><i class="fa fa-user-check" aria-hidden="true"></i>Al docente per la firma</span>', StatiIncarico::badge('confermata'));
        $this->assertStringContainsString('fa-circle', StatiIncarico::badge('strano'));
        $passi = StatiIncarico::passi(['stato' => 'confermata']);
        $this->assertSame([['Preparata', true, false], ['Conferma dello studente (SPID/CIE)', true, false], ['Firma del docente (PAdES)', false, true]], array_slice($passi, 0, 3));
        $this->assertSame([false, false], [StatiIncarico::passi(['stato' => 'annullata'])[0][1], StatiIncarico::passi(['stato' => 'annullata'])[0][2]]);
        $this->assertCount(7, Costanti::STATI_INCARICO);
        $this->assertSame('SPID', Costanti::METODI_ACCESSO['spid']);
    }

    public function testOreDelRegistroERegistroAperto(): void
    {
        $o = ServizioRegistroTutorato::ore([['stato' => 'approvata', 'ore' => '4.0'], ['stato' => 'approvata', 'ore' => '3.5'], ['stato' => 'inviata', 'ore' => '2.0'], ['stato' => 'respinta', 'ore' => '1.0']]);
        $this->assertSame(['inviata' => 2.0, 'approvata' => 7.5, 'respinta' => 1.0, 'totale' => 9.5], $o);
        $this->assertSame(['inviata' => 0.0, 'approvata' => 0.0, 'respinta' => 0.0, 'totale' => 0.0], ServizioRegistroTutorato::ore([]));
        $this->assertTrue(ServizioRegistroTutorato::aperto(['stato' => 'firmata', 'fine_stato' => '']));
        $this->assertTrue(ServizioRegistroTutorato::aperto(['stato' => 'protocollata', 'fine_stato' => 'richiesta']));
        $this->assertFalse(ServizioRegistroTutorato::aperto(['stato' => 'firmata', 'fine_stato' => 'da_firmare']));
        $this->assertFalse(ServizioRegistroTutorato::aperto(['stato' => 'confermata', 'fine_stato' => '']));
    }

    public function testControlloDelPdfFirmato(): void
    {
        $v = new VerificaPdfFirmato(new FirmeFinte());
        $prima = "%PDF-1.4\nlettera";
        $dopo = $prima . "\n%%FIRMA\n";

        $this->assertSame('Il file non è un PDF: la firma deve essere PAdES (PDF firmato), non CAdES (.p7m).', $v->verifica($prima, 'p7m', '')[0]);
        $this->assertStringStartsWith('Il PDF firmato non corrisponde alla lettera', $v->verifica($prima, $prima, '')[0], 'uguale alla versione precedente: nessuna aggiunta');
        $this->assertStringStartsWith('Il PDF firmato non corrisponde alla lettera', $v->verifica($prima, "%PDF-1.4\naltro testo lungo\n%%FIRMA", '')[0]);
        $this->assertSame("Nel PDF non c'è una nuova firma digitale PAdES.", $v->verifica($prima, $prima . "\nsolo testo in più", '')[0]);
        // piano convenzioni, A2: prima due firme in più erano rifiutate («esattamente una»), ora sono ammesse
        $this->assertNull($v->verifica($dopo, $dopo . "\n%%FIRMA\n%%FIRMA", '')[0], 'due firme in più');
        [$err, $info] = $v->verifica($prima, $dopo, 'RSSMRA80A01D086X');
        $this->assertNull($err);
        $this->assertSame(['integra' => null, 'nome' => '', 'cf' => '', 'emittente' => '', 'avviso' => ''], $info, 'senza firma crittografica leggibile il controllo non è disponibile e nessun avviso (commit 5a32cfe: il certificato non blocca, l\'esito va in «avviso»)');
        $this->assertNull($v->verifica($dopo, $dopo . "\n%%FIRMA\n", '')[0], 'la seconda firma si aggiunge alla prima');
        $this->assertSame(['integra' => null, 'nome' => '', 'cf' => '', 'emittente' => ''], $v->analizza('%PDF-1.4 senza firme'));
    }

    public function testCertificatoNonLeggibileNonBloccaMaDaUnAvviso(): void
    {
        $v = new VerificaPdfFirmato(new FirmeFinte());
        $prima = "%PDF-1.4\nlettera";
        // una firma «vera» solo nella forma: ByteRange e contenuto esadecimale che OpenSSL non sa leggere
        $inizio = $prima . "\n%%FIRMA\n/ByteRange [0 0000 0000 0001]\n<";
        $esa = '3003020100';
        $b = strlen($inizio);
        $firmato = str_replace('[0 0000 0000 0001]', sprintf('[0 %04d %04d 0001]', $b, $b + strlen($esa)), $inizio) . $esa . '>';

        [$err, $info] = $v->verifica($prima, $firmato, '');
        $this->assertNull($err, 'un certificato che non si riesce a controllare non blocca il caricamento');
        $this->assertFalse($info['integra']);
        $this->assertSame('Il controllo automatico del certificato non è riuscito: verifica la firma con gli strumenti di Ateneo.', $info['avviso']);
    }

    /** Firme di prova con formato, struttura e copertura scelti: «%%F:formato:struttura:copertura;» nel PDF. */
    private function verificaConFormati(): VerificaPdfFirmato
    {
        return new VerificaPdfFirmato(new class () implements \App\Infrastructure\Pdf\VerificaFirme {
            public function firmePades(string $pdf): array
            {
                preg_match_all('/%%F:([^:]*):(\d):(\d);/', $pdf, $m, PREG_SET_ORDER);

                return array_map(static fn (array $x): array => ['subfilter' => $x[1], 'nome' => '', 'data' => '', 'marca_temporale' => $x[1] === 'ETSI.RFC3161', 'valida_struttura' => $x[2] === '1', 'copre_tutto' => $x[3] === '1'], $m);
            }
        });
    }

    public function testLeRegoleSevereDegliAltriModuliRestano(): void
    {
        $v = $this->verificaConFormati();
        $prima = "%PDF-1.4\ndocumento";
        $this->assertNull($v->verifica($prima, $prima . '%%F:ETSI.CAdES.detached:1:1;')[0]);
        $this->assertNull($v->verifica($prima, $prima . '%%F:ETSI.CAdES.detached:1:0;%%F:ETSI.RFC3161:1:1;')[0], 'la marca dopo la firma copre il file');
        $casi = [
            'un PDF riscritto' => ["%PDF-1.4\nriscritto, più lungo di prima%%F:ETSI.CAdES.detached:1:1;", 'non corrisponde alla lettera', 'riscritto dal programma di firma: controlla che sia lo stesso documento'],
            'formato sconosciuto' => [$prima . '%%F:strano:1:1;', 'formato PAdES (strano)', 'formato della firma non è tra quelli riconosciuti (strano)'],
            'prima firma in un formato sconosciuto' => [$prima . '%%F::1:0;%%F:ETSI.CAdES.detached:1:1;', 'formato sconosciuto', 'riconosciuti (sconosciuto)'],
            'struttura rotta' => [$prima . '%%F:ETSI.CAdES.detached:0:1;', 'non copre tutto', 'struttura della firma non è integra'],
            'copertura parziale' => [$prima . '%%F:ETSI.CAdES.detached:1:0;', 'non copre tutto', 'non copre tutto il file'],
        ];
        foreach ($casi as $nome => [$dopo, $errore, $avviso]) {
            $this->assertStringContainsString($errore, (string) $v->verifica($prima, $dopo)[0], "$nome: con le regole severe blocca");
            [$err, $info] = $v->verifica($prima, $dopo, '', false);
            $this->assertNull($err, "$nome: sulle convenzioni non blocca");
            $this->assertStringContainsString($avviso, $info['avviso'], $nome);
            $this->assertStringNotContainsString('lettera', $info['avviso'], $nome);
        }
    }

    public function testSulleConvenzioniBloccanoSoloQuattroCause(): void
    {
        $v = $this->verificaConFormati();
        $firmato = "%PDF-1.4\ndocumento%%F:ETSI.CAdES.detached:1:1;";
        $log = (string) tempnam(sys_get_temp_dir(), 'log');
        $primaLog = (string) ini_get('error_log');
        ini_set('error_log', $log);
        try {
            $this->assertStringContainsString('non è un PDF', (string) $v->verifica($firmato, "\x30\x82\x05\x10 p7m", '', false)[0]);
            $this->assertStringContainsString('nessuna firma', (string) $v->verifica($firmato, "%PDF-1.4\nsenza firme, con un testo più lungo", '', false)[0]);
            $this->assertStringContainsString('nuova firma', (string) $v->verifica($firmato, $firmato . ' altro segreto', '', false)[0]);
            $this->assertStringContainsString('nuova firma', (string) $v->verifica($firmato, $firmato . '%%F:ETSI.RFC3161:1:1;', '', false)[0], 'solo una marca');
            $righe = (string) file_get_contents($log);
            $this->assertSame(4, substr_count($righe, '[firma rifiutata]'));
            $this->assertStringContainsString('motivo=nessuna_firma_nuova subfilter=ETSI.CAdES.detached,ETSI.RFC3161 firme_prima=1 firme_dopo=2', $righe);
            $this->assertStringNotContainsString('segreto', $righe, 'mai il contenuto del file');
        } finally {
            ini_set('error_log', $primaLog);
            @unlink($log);
        }
    }

    public function testFirmaRemotaNonConfigurataEControlloDelleCredenziali(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "ARUBA_ARSS_URL=\n");
        try {
            $f = new FirmaRemotaAruba(new FileEnv($file));
            $this->assertFalse($f->disponibile());
            $this->assertSame([null, 'La firma remota non è configurata sul portale: scarica il PDF, firmalo in PAdES e caricalo.'], $f->firma('%PDF', 'u', 'p', '1'));

            file_put_contents($file, "ARUBA_ARSS_URL=http://127.0.0.1:1/arss\n");
            $g = new FirmaRemotaAruba(new FileEnv($file));
            $this->assertSame(function_exists('curl_init'), $g->disponibile());
            if (function_exists('curl_init')) {
                $this->assertSame([null, 'Scrivi utente, password (PIN) e codice OTP della firma remota.'], $g->firma('%PDF', '', 'p', '1'));
                $this->assertSame([null, 'Scrivi utente, password (PIN) e codice OTP della firma remota.'], $g->firma('%PDF', 'u', 'p', ' '));
                [$pdf, $msg] = $g->firma('%PDF', 'u', 'p', '12 34', ['x' => 1, 'y' => 2, 'l' => 3, 'a' => 4, 'pagina' => 1], 'Motivo');
                $this->assertNull($pdf);
                $this->assertStringStartsWith('Il servizio di firma Aruba non risponde', (string) $msg, 'servizio irraggiungibile');
            }
        } finally {
            @unlink($file);
        }
    }
}
