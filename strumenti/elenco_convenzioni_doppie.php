<?php
// =========================================================================
// Compilazioni della convenzione FSL doppie (23/11/2026, schema v138). Da riga di comando, sul server. SOLA LETTURA: non modifica nulla.
//
//   php strumenti/elenco_convenzioni_doppie.php
//        Elenca gli istituti con più di una compilazione «in corso» (né protocollata né unita): prima della compilazione unica per istituto
//        ogni docente apriva la sua, e una scuola poteva averne due o più. Per ognuna: numero, email, stato, attività nell'Allegato A e
//        attività ancora da confermare. Per unirle: scheda della scuola nel pannello FSL, pulsante «Unisci» (con conferma e voce nel diario).
//   (--env=file per usare un file .env diverso da quello del portale, es. --env=.env.locale)
//
// Esce con 0 se non ce ne sono, con 1 se ce ne sono (così si può usare in un controllo), con 2 se il database non risponde.
// =========================================================================
if (PHP_SAPI !== 'cli') {
    exit;
}
$radice = realpath(__DIR__ . '/..');
require_once $radice . '/src/autoload.php';

$file_env = $radice . '/.env';
foreach (array_slice($argv, 1) as $x) {
    if (str_starts_with($x, '--env=')) {
        $file_env = substr($x, 6);
    }
}
$_env = @parse_ini_string(preg_replace('/^\s*#.*$/m', '', (string) @file_get_contents($file_env))) ?: [];
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($_env['DB_HOST'] ?? 'localhost', $_env['DB_USER'] ?? '', $_env['DB_PASS'] ?? '', $_env['DB_NAME'] ?? '');
if ($conn->connect_error) {
    fwrite(STDERR, "Database non raggiungibile: controlla il file $file_env.\n");
    exit(2);
}
$conn->set_charset('utf8mb4');
$db = \App\Core\Database::per($conn);
if ($db->valore("SHOW COLUMNS FROM convenzioni_compilate LIKE 'unita_in'") === null) {
    fwrite(STDERR, "Lo schema non è ancora alla v138: apri una pagina del portale e riprova.\n");
    exit(2);
}
$doppie = \App\Core\App::per($conn)->get(\App\Fsl\ConvenzioneCompilataRepository::class)->doppie();
if (!$doppie) {
    echo "Nessuna scuola con più di una compilazione della convenzione in corso.\n";
    exit(0);
}
$scuole = \App\Core\App::per($conn)->get(\App\Anagrafi\ServizioScuole::class);
echo 'Scuole con più compilazioni in corso: ' . count($doppie) . "\n\n";
foreach ($doppie as $d) {
    $anagrafe = $scuole->perCodice($d['istituto']);
    printf("%s  %s\n", $d['istituto'], $anagrafe ? ($anagrafe['denominazione'] ?? '') : '');
    foreach ($d['compilazioni'] as $c) {
        printf("      n. %-6d %-34s %-18s %d attività, %d da confermare  (aperta il %s)\n", $c['id'], $c['email'] !== '' ? $c['email'] : '(senza email)', $c['stato_iter'] !== '' ? $c['stato_iter'] : 'in compilazione', $c['attivita'], $c['in_attesa'], $c['creata_il']);
    }
    echo "      pannello FSL: fsl_scuole.php?k=" . $d['istituto'] . "\n\n";
}
echo "Per unirle usa «Unisci» nella scheda della scuola. Questo strumento non cambia niente.\n";
exit(1);
