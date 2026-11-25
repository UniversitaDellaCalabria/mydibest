<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

/**
 * «Due clic insieme» sul pulsante «Avvia la stipula», con DUE PROCESSI PHP VERI che partono nello stesso istante e lavorano sullo stesso database:
 * il blocco per istituto (GET_LOCK) deve far uscire UNA compilazione e UNA email. La prova sequenziale (PaginaScuoleFslTest) non basta:
 * con due richieste una dopo l'altra la seconda vede già la prima, qui no.
 */
final class AvvioDueProcessiTest extends PaginaScuoleFslBase
{
    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} email partite ed esiti dei due processi */
    private function dueClicInsieme(string $emailUno, string $emailDue): array
    {
        $bootstrap = (string) ($GLOBALS['__PHPUNIT_BOOTSTRAP'] ?? '');
        if ($bootstrap === '' || !is_file($bootstrap)) {
            self::markTestSkipped('Il bootstrap di PHPUnit non è noto: i processi veri non si possono avviare');
        }
        $cartella = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'due_clic_' . bin2hex(random_bytes(4));
        mkdir($cartella);
        $fileEmail = $cartella . DIRECTORY_SEPARATOR . 'email.jsonl';
        $istante = microtime(true) + 1.5;   // il tempo che serve a entrambi per caricare il codice
        $processi = [];
        foreach ([$emailUno, $emailDue] as $i => $email) {
            $comando = [PHP_BINARY, '-d', 'extension=zip', __DIR__ . '/lavoratore_avvio.php', $bootstrap, sprintf('%.6F', $istante), 'CSIS00001A', $email, $fileEmail, $cartella . "/esito$i.json"];
            $p = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
            self::assertIsResource($p, 'processo ' . ($i + 1));
            $processi[$i] = [$p, $pipe];
        }
        $uscite = [];
        foreach ($processi as $i => [$p, $pipe]) {
            $uscite[$i] = stream_get_contents($pipe[1]) . stream_get_contents($pipe[2]);
            proc_close($p);
        }
        $esiti = [];
        foreach ([0, 1] as $i) {
            self::assertFileExists($cartella . "/esito$i.json", "il processo $i non ha finito: " . trim($uscite[$i]));
            $esiti[] = json_decode((string) file_get_contents($cartella . "/esito$i.json"), true);
        }
        $email = is_file($fileEmail) ? array_map(static fn (string $r): array => json_decode($r, true), array_filter(explode("\n", (string) file_get_contents($fileEmail)))) : [];
        array_map('unlink', glob($cartella . '/*') ?: []);
        rmdir($cartella);

        return [array_values($email), $esiti];
    }

    public function testDueProcessiVeriConLoStessoReferenteDannoUnaCompilazioneEUnaEmail(): void
    {
        [$email, $esiti] = $this->dueClicInsieme('prof@scuola.it', 'prof@scuola.it');

        self::assertSame(1, $this->compilazioni(), 'mai due compilazioni per la stessa scuola');
        self::assertCount(1, $email, 'e una sola email: l\'altro clic non rimanda il link');
        self::assertSame('prof@scuola.it', $email[0]['a']);
        $stati = array_column($esiti, 'esito');
        sort($stati);
        self::assertSame(['creata', 'gia_inviata'], $stati, 'uno crea, l\'altro trova già fatto');
        self::assertSame($esiti[0]['id'], $esiti[1]['id'], 'e parlano della stessa compilazione');
    }

    public function testDueProcessiVeriConDueReferentiDiversiNonApronoDueCompilazioni(): void
    {
        [$email, $esiti] = $this->dueClicInsieme('uno@scuola.it', 'due@scuola.it');

        self::assertSame(1, $this->compilazioni(), 'una sola compilazione, di chi è arrivato per primo');
        self::assertCount(1, $email, 'una sola email');
        $errori = array_filter(array_column($esiti, 'errore'));
        self::assertCount(1, $errori, 'l\'altro riceve «c\'è già una convenzione in corso»');
        self::assertStringContainsString('già una convenzione in corso', (string) reset($errori));
    }
}
