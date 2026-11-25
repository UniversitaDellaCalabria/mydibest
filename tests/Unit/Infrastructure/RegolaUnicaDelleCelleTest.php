<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Attestati\ModelloElenco;
use App\Infrastructure\Documenti\Excel;
use App\Infrastructure\Documenti\TabellaEsportabile;
use App\Iscritti\Vista\EsportaIscritti;
use App\Sondaggi\Vista\EsportaRisultati;
use PHPUnit\Framework\TestCase;

/**
 * Passo 9, punto 7: ogni file che il portale fa scaricare (CSV, tabella HTML che Excel apre, PDF, foglio .xlsx) scrive le celle con UNA regola,
 * {@see TabellaEsportabile::cella()}: un testo che comincia con = + - @ (anche dopo spazi o a capo) non diventa una formula, i numeri veri restano numeri.
 * Una prova per ciascuna esportazione, più una guardia che impedisce di scrivere un CSV a parte.
 */
final class RegolaUnicaDelleCelleTest extends TestCase
{
    private const FORMULA = '=HYPERLINK("http://esempio.invalid","clicca")';
    private const RISULTATO = '\'=HYPERLINK("http://esempio.invalid","clicca")';

    public function testLaRegolaNeutralizzaLeFormuleEBastaQuello(): void
    {
        foreach (['=1+1', '+39 333 1234567', '-2+3', '@SOMMA(A1)', "  =cmd", "\n=cmd", "\t@x"] as $testo) {
            $c = TabellaEsportabile::cella($testo);
            self::assertStringStartsWith("'", $c, "«{$testo}» deve diventare testo");
        }
        self::assertSame('Rossi', TabellaEsportabile::cella('Rossi'));
        self::assertSame('', TabellaEsportabile::cella(''));
        self::assertSame('', TabellaEsportabile::cella(null));
        self::assertSame('a · b', TabellaEsportabile::cella("a\nb"));
    }

    public function testINumeriVeriRestanoNumeri(): void
    {
        self::assertSame('-5', TabellaEsportabile::cella(-5));
        self::assertSame('12', TabellaEsportabile::cella(12));
        self::assertSame('-0.5', TabellaEsportabile::cella(-0.5));
        self::assertSame('0', TabellaEsportabile::cella(0));
    }

    public function testLaTabellaEsportabileNeutralizzaCsvEFoglio(): void
    {
        $t = new TabellaEsportabile('Prova', ['Nome', 'Posti'], [[self::FORMULA, -3], ['Anna', 7]]);
        $righe = array_map(static fn ($r) => str_getcsv($r, ';', '"', '\\'), preg_split('/\R/', trim(substr($t->csv(), 3))));
        self::assertSame(self::RISULTATO, $righe[1][0]);
        self::assertSame('-3', $righe[1][1], 'un numero negativo vero non prende l\'apice');
        self::assertSame(self::RISULTATO, $t->foglio()['righe'][0][0]);
    }

    public function testEsportaIscritti(): void
    {
        $riga = ['codice_prenotazione' => 'T1', 'stato' => 'confermata', 'presente' => '1', 'num_posti' => '2', 'nome' => self::FORMULA, 'cognome' => '@Rossi',
            'matricola_effettiva' => '', 'email' => 'a@x.it', 'evento' => '+Genetica', 'nome_turno' => 'Turno A', 'data_turno' => '2999-10-23', 'orario_inizio' => '09:00:00',
            'dati_custom_json' => '{"dieta":"=2+2"}', 'n_studenti' => 3, 'studenti' => '-Alfa', 'data_prenotazione' => '2026-10-01 10:00:00'];

        ob_start();
        EsportaIscritti::csv([$riga], ['dieta' => 'Dieta'], true);
        $csv = (string) ob_get_clean();
        $r = str_getcsv(explode("\n", trim($csv))[1], ',', '"', '\\');
        self::assertSame(self::RISULTATO, $r[4], 'nome');
        self::assertSame("'@Rossi", $r[5], 'cognome');
        self::assertSame("'+Genetica", $r[8], 'evento');
        self::assertSame("'=2+2", $r[12], 'campo del modulo');
        self::assertSame('3', $r[13], 'il numero degli studenti resta un numero');
        self::assertSame("'-Alfa", $r[14]);

        ob_start();
        EsportaIscritti::excel([$riga], ['dieta' => 'Dieta'], true);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('<td>' . htmlspecialchars(self::RISULTATO) . '</td>', $html);
        self::assertStringContainsString('<td>' . htmlspecialchars("'=2+2") . '</td>', $html);
        self::assertStringContainsString('<td>3</td>', $html);
        self::assertStringNotContainsString('<td>=', $html, 'nessuna cella comincia con =');
    }

    public function testEsportaRisultatiDelSondaggio(): void
    {
        $domande = [10 => ['id' => '10', 'testo_domanda' => '=Domanda', 'tipo' => 'text']];
        $html = EsportaRisultati::html($domande, ['2026-09-12 10:00:00' => [10 => self::FORMULA]]);
        self::assertStringContainsString('<td>' . htmlspecialchars(self::RISULTATO) . '</td>', $html);
        self::assertStringContainsString('color:white;">' . htmlspecialchars("'=Domanda") . '</th>', $html);
        self::assertStringNotContainsString('<td>=', $html);
    }

    public function testIlModelloDellElencoStudentiInCsv(): void
    {
        $csv = ModelloElenco::csv([['cognome' => '=cmd|calc', 'nome' => '@Anna'], ['cognome' => 'Rossi', 'nome' => 'Mario']]);
        self::assertStringStartsWith("\xEF\xBB\xBFCognome;Nome\r\n", $csv);
        self::assertStringContainsString("\r\n'=cmd|calc;'@Anna\r\n", $csv);
        self::assertStringContainsString("\r\nRossi;Mario\r\n", $csv);
    }

    public function testIFogliXlsxScrivonoOgniCellaComeTestoMaiComeFormula(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive non disponibile');
        }
        $f = Excel::crea(['Foglio' => ['intestazioni' => ['A'], 'righe' => [[self::FORMULA]], 'larghezze' => [20]]]);
        self::assertNotNull($f);
        $z = new \ZipArchive();
        self::assertTrue($z->open((string) $f));
        $xml = (string) $z->getFromName('xl/worksheets/sheet1.xml');
        $z->close();
        @unlink((string) $f);
        self::assertStringNotContainsString('<f>', $xml);
        self::assertStringNotContainsString('<f ', $xml);
        self::assertStringContainsString('t="inlineStr"', $xml);
    }

    /** Un CSV a parte (fputcsv fuori dai posti noti) tornerebbe a far passare le formule: l'elenco dei posti ammessi è corto e controllato. */
    public function testNessunAltroPostoScriveCsv(): void
    {
        $ammessi = [
            'src/Infrastructure/Documenti/TabellaEsportabile.php', // la regola e i suoi tre formati
            'src/Infrastructure/Documenti/FogliEsportabili.php',   // righe già passate da TabellaEsportabile::righeCsv()
            'src/Iscritti/Vista/EsportaIscritti.php',              // celle passate da TabellaEsportabile::cella()
        ];
        $radice = realpath(RADICE_SITO . '/src');
        self::assertNotFalse($radice);
        $trovati = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($radice, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'fputcsv(')) {
                $trovati[] = 'src/' . str_replace('\\', '/', substr($file->getPathname(), strlen($radice) + 1));
            }
        }
        self::assertNotSame([], $trovati, 'la guardia deve trovare almeno i posti ammessi');
        self::assertSame([], array_values(array_diff($trovati, $ammessi)), 'fputcsv in un posto non ammesso: usa TabellaEsportabile');
    }
}
