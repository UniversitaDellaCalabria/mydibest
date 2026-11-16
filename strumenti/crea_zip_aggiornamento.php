<?php
// =========================================================================
// Pacchetto ZIP con i soli file CAMBIATI tra la versione che è sul server e un commit (di solito main), per caricare un aggiornamento
// piccolo senza rifare il pacchetto completo (per quello: strumenti/crea_pacchetto.sh). I file si leggono dal COMMIT (git show), non dalla
// cartella di lavoro: quello che va sul server è uguale a git. Dentro lo zip: SHA256SUMS_aggiornamento.txt e ISTRUZIONI_AGGIORNAMENTO.txt
// (da strumenti/modello_istruzioni_zip.txt). Non entrano mai tests, strumenti, documentazione, difetti, materiali, protocollo (connettore Python di riferimento; entrano solo protocollo/.htaccess e protocollo/php-titulus-utility/src, il connettore PHP che il portale carica), database,
// uploads, cache, backups, vendor né i file solo di sviluppo.
//   php -d extension=zip strumenti/crea_zip_aggiornamento.php --da <commit sul server> [--a main] [--nome aggiornamento_AAAA-MM-GG.zip] [--difetti "E18, E30"] [--prove file.txt]
// =========================================================================
if (PHP_SAPI !== 'cli') exit;
$o = getopt('', ['da:', 'a:', 'nome:', 'difetti:', 'prove:']);
if (empty($o['da'])) { fwrite(STDERR, "Manca --da <commit che è sul server>.\n"); exit(2); }
if (!class_exists('ZipArchive')) { fwrite(STDERR, "Serve l'estensione zip: php -d extension=zip …\n"); exit(2); }
$radice = realpath(__DIR__ . '/..');
chdir($radice);
$da = (string)$o['da']; $a = (string)($o['a'] ?? 'main');
$nome = (string)($o['nome'] ?? 'aggiornamento_' . date('Y-m-d') . '.zip');
$git = fn(string $c): string => (string)shell_exec('git ' . $c . ' 2>&1');
$elenco = array_values(array_filter(explode("\n", trim($git('diff --name-only --diff-filter=ACMR ' . escapeshellarg($da) . ' ' . escapeshellarg($a))))));
$elenco = array_values(array_filter($elenco, fn($f) => !preg_match('#^(tests|strumenti|documentazione|difetti|materiali|database|uploads|cache|backups|vendor)/#', $f)
    && !preg_match('#^protocollo/(?!(\.htaccess$|php-titulus-utility/src/))#', $f)
    && !preg_match('#(^|/)(desktop\.ini|\.gitkeep|\.gitattributes|\.gitignore|\.php-cs-fixer\.dist\.php)$#', $f)
    && !preg_match('#^(README\.md|MIGRATION_PLAN\.md|LICENSE|composer\.json|composer\.lock|phpunit\.xml\.dist|phpstan\.neon\.dist|\.env\.example|\.htaccess)$#', $f)
    && !preg_match('#\.(tgz|zip)$#', $f)));
sort($elenco);
// File NUOVI (non c'erano sul server): al ritorno indietro vanno tolti a mano
$nuovi = array_values(array_intersect($elenco, array_filter(explode("\n", trim($git('diff --name-only --diff-filter=A ' . escapeshellarg($da) . ' ' . escapeshellarg($a)))))));
if (!$elenco) { fwrite(STDERR, "Nessun file da caricare tra $da e $a.\n"); exit(1); }
$percorso = $radice . DIRECTORY_SEPARATOR . $nome;
@unlink($percorso);
$z = new ZipArchive();
$z->open($percorso, ZipArchive::CREATE);
$sha = '';
foreach ($elenco as $f) {
    $blob = (string)shell_exec('git show ' . escapeshellarg("$a:$f"));
    if ($blob === '') { fwrite(STDERR, "File vuoto o non letto: $f\n"); exit(1); }
    $z->addFromString($f, $blob);
    $sha .= hash('sha256', $blob) . '  ' . $f . "\n";
}
$z->addFromString('SHA256SUMS_aggiornamento.txt', $sha);
$modello = (string)file_get_contents(__DIR__ . '/modello_istruzioni_zip.txt');
$z->addFromString('ISTRUZIONI_AGGIORNAMENTO.txt', str_replace(
    ['{{ZIP}}', '{{DATA}}', '{{N}}', '{{DIFETTI}}', '{{COMMIT}}', '{{PROVE}}', '{{NUOVI}}'],
    [$nome, date('Ymd_Hi'),(string)count($elenco), (string)($o['difetti'] ?? ''), trim($git('rev-parse --short ' . escapeshellarg($a))),
    isset($o['prove']) && is_file((string)$o['prove']) ? rtrim((string)file_get_contents((string)$o['prove'])) . "\n" : "   (nessuna indicata)\n",
    $nuovi ? implode("\n", array_map(fn($n) => "      rm -f /opt/lampp/htdocs/mydibest/$n", $nuovi)) : "      (nessun file nuovo)"], $modello));
$z->close();
echo count($elenco) . " file in $nome (commit " . trim($git('rev-parse --short ' . escapeshellarg($a))) . "):\n  " . implode("\n  ", $elenco) . "\n";
