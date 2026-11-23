<?php
// =========================================================================
// strumenti/avanzamento_nucleo.php - Avanzamento del nucleo (documentazione/piano_framework_dibest.md, Fase 6):
// quante pagine passano da App\Http\Nucleo, quante chiamano ancora nega_accesso(, csrf_verify( o si scrivono $h = fn,
// quante classi e quante prove ha il nucleo. È l'AUTORITÀ UNICA dei conteggi: i numeri del piano e della memoria del progetto
// vengono da qui, non da un grep fatto a mano (due grep diversi davano numeri diversi: 55/38, 93/87).
//   php strumenti/avanzamento_nucleo.php             tabella
//   php strumenti/avanzamento_nucleo.php --json      stesso contenuto in JSON (per le prove e per i confronti nel tempo)
//   php strumenti/avanzamento_nucleo.php --controlla esce con 1 se la tabella delle rotte e i gusci non coincidono
//   php strumenti/avanzamento_nucleo.php --casi      in più, chiede a PHPUnit (--list-tests) quanti CASI eseguirebbe davvero
//                                                    (serve il .phar: variabili PHPUNIT_PHAR e PHPUNIT_BOOTSTRAP, oppure %TEMP%/phpunit96/)
//
// «METODI DI PROVA» ≠ «CASI»: lo strumento conta i metodi `test…` scritti nei file; PHPUnit esegue più casi perché un metodo con un
// dataProvider diventa tanti casi. Il numero che si chiama «metodi di prova» non si può citare come «prove eseguite»: per quello c'è --casi.
// Senza --casi nessun numero di prove eseguite viene dato (meglio nessuna cifra che una cifra con il nome di un'altra).
//
// DEFINIZIONE DEI CONTEGGI (una sola, per sempre): si conta il FILE che contiene la CHIAMATA, nel codice, con la parentesi aperta
// (`nega_accesso(`, `csrf_verify(`). Non contano i commenti, le stringhe e la definizione della funzione (`function nega_accesso(`):
// si leggono i token di PHP, non il testo. `$h = fn` è la variabile $h assegnata a una funzione freccia (anche `static fn`).
// «Pagine» = i file .php della radice e di admin/ (primo livello), senza nucleo.php (che è il punto d'ingresso del nucleo, non una pagina).
// «Tutto il progetto» = tutti i .php del portale, senza vendor/, cache/, tests/, strumenti/, documentazione/, uploads/, backups/ e simili.
// =========================================================================
if (PHP_SAPI !== 'cli') {
    exit;
}
$SITO = (string) realpath(__DIR__ . '/..');
$json = in_array('--json', $argv, true);
$controlla = in_array('--controlla', $argv, true);
$vuoleCasi = in_array('--casi', $argv, true);

require $SITO . '/src/autoload.php';

/** Cartelle che non sono il portale: copie, dati, prove e strumenti. */
const ESCLUSE = ['vendor', 'cache', 'tests', 'strumenti', 'documentazione', 'uploads', 'backups', 'materiali', 'database', 'difetti', 'assets', 'modelli_documenti', 'protocollo', '.git'];

/** @return list<string> percorsi (con «/») dei .php di tutto il portale */
function file_del_portale(string $radice): array
{
    $out = [];
    $primo = static fn (SplFileInfo $f): string => explode('/', ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($radice))), '/'))[0];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($radice, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $f): bool => !in_array($primo($f), ESCLUSE, true) && ($f->isDir() || $f->getExtension() === 'php')
    ));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $out[] = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($radice))), '/');
        }
    }
    sort($out);

    return $out;
}

/**
 * Cosa fa un file, letto con i token (niente commenti, niente stringhe).
 *
 * @return array{nega_accesso: bool, csrf_verify: bool, h_fn: bool, guscio: bool}
 */
function analizza(string $file): array
{
    $r = ['nega_accesso' => false, 'csrf_verify' => false, 'h_fn' => false, 'guscio' => false];
    $t = array_values(array_filter(
        token_get_all((string) file_get_contents($file)),
        static fn ($x): bool => !is_array($x) || !in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
    ));
    $testo = static fn ($x): string => is_array($x) ? $x[1] : $x;
    for ($i = 0, $n = count($t); $i < $n; $i++) {
        $x = $t[$i];
        if (is_array($x) && $x[0] === T_STRING && ($t[$i + 1] ?? null) === '(') {
            $dopoFunction = $i > 0 && is_array($t[$i - 1]) && $t[$i - 1][0] === T_FUNCTION;
            $dopoNome = $i > 0 && is_array($t[$i - 1]) && in_array($t[$i - 1][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true);
            if (!$dopoFunction && !$dopoNome) {
                if ($x[1] === 'nega_accesso') {
                    $r['nega_accesso'] = true;
                } elseif ($x[1] === 'csrf_verify') {
                    $r['csrf_verify'] = true;
                }
            }
            if ($x[1] === 'serviFile' && $i >= 2 && $testo($t[$i - 1]) === '::' && ($testo($t[$i - 2]) === 'Nucleo' || str_ends_with($testo($t[$i - 2]), '\Nucleo'))) {
                $r['guscio'] = true;
            }
        }
        // $h = fn  /  $h = static fn
        if (is_array($x) && $x[0] === T_VARIABLE && $x[1] === '$h' && ($t[$i + 1] ?? null) === '=') {
            $k = $i + 2;
            if (is_array($t[$k] ?? null) && $t[$k][0] === T_STATIC) {
                $k++;
            }
            if (is_array($t[$k] ?? null) && $t[$k][0] === T_FN) {
                $r['h_fn'] = true;
            }
        }
    }

    return $r;
}

// ── Le misure ───────────────────────────────────────────────────────────────
$tutti = file_del_portale($SITO);
$pagine = array_values(array_filter(
    $tutti,
    static fn (string $f): bool => $f !== 'nucleo.php' && (substr_count($f, '/') === 0 || (str_starts_with($f, 'admin/') && substr_count($f, '/') === 1))
));
$eRadicePagina = array_flip($pagine);

$conta = ['nega_accesso' => ['pagine' => 0, 'altri' => 0, 'file' => []], 'csrf_verify' => ['pagine' => 0, 'altri' => 0, 'file' => []], 'h_fn' => ['pagine' => 0, 'altri' => 0, 'file' => []]];
$gusci = [];
foreach ($tutti as $f) {
    $a = analizza($SITO . '/' . $f);
    foreach (array_keys($conta) as $k) {
        if ($a[$k]) {
            $conta[$k][isset($eRadicePagina[$f]) ? 'pagine' : 'altri']++;
            $conta[$k]['file'][] = $f;
        }
    }
    if ($a['guscio']) {
        $gusci[] = $f;
    }
}

$rotte = \App\Portale\Instradamento\Rotte::elenco();
$fileDelleRotte = array_map(static fn (\App\Http\Rotta $r): string => $r->file, $rotte);
$anomalie = [];
foreach ($rotte as $r) {
    if (!is_file($SITO . '/' . $r->file)) {
        $anomalie[] = "la rotta «{$r->percorso}» punta a {$r->file}, che non esiste (l'indirizzo vecchio è morto)";
    } elseif (!in_array($r->file, $gusci, true)) {
        $anomalie[] = "la rotta «{$r->percorso}»: {$r->file} non è un guscio (non chiama Nucleo::serviFile)";
    }
}
foreach ($gusci as $g) {
    if (!in_array($g, $fileDelleRotte, true)) {
        $anomalie[] = "$g chiama Nucleo::serviFile ma non ha una rotta nella tabella";
    }
}
$senzaToken = array_values(array_filter($rotte, static fn (\App\Http\Rotta $r): bool => $r->senzaCsrf !== null));

/** Classi, interfacce e trait di una cartella di src/ (stessa definizione di inventario_oop.php). */
function classi_in(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'php' && preg_match('/^\s*(final\s+|abstract\s+)?(class|interface|trait|enum)\s/m', (string) file_get_contents($f->getPathname()))) {
            $n++;
        }
    }

    return $n;
}

/** @return array{file: int, metodi: int} i file *Test.php e i METODI `test…` (non i casi: vedi l'intestazione) di un elenco di file */
function prove_in(array $file): array
{
    $metodi = 0;
    foreach ($file as $f) {
        $metodi += preg_match_all('/^\s*public\s+(?:static\s+)?function\s+test\w*\s*\(/m', (string) file_get_contents($f));
    }

    return ['file' => count($file), 'metodi' => $metodi];
}

/**
 * I casi che PHPUnit eseguirebbe davvero (ogni riga « - Classe::metodo» di --list-tests, dataProvider compresi), oppure null se non si può chiedere.
 * Non esegue nessuna prova e non tocca il database.
 *
 * @return array{casi: int|null, motivo: string}
 */
function casi_phpunit(string $sito): array
{
    $dir = rtrim((string) (getenv('TEMP') ?: sys_get_temp_dir()), '/\\') . '/phpunit96';
    $phar = getenv('PHPUNIT_PHAR') ?: $dir . '/phpunit-9.6.phar';
    $boot = getenv('PHPUNIT_BOOTSTRAP') ?: $dir . '/bootstrap.php';
    if (!is_file($phar) || !is_file($boot)) {
        return ['casi' => null, 'motivo' => "PHPUnit non trovato ($phar): imposta PHPUNIT_PHAR e PHPUNIT_BOOTSTRAP"];
    }
    $totale = 0;
    foreach (['tests/Unit', 'tests/Integration'] as $cartella) {
        $cmd = escapeshellarg(PHP_BINARY) . ' -d extension=zip ' . escapeshellarg($phar) . ' --bootstrap ' . escapeshellarg($boot)
            . ' --no-configuration --list-tests ' . escapeshellarg($sito . '/' . $cartella) . ' 2>&1';
        $righe = [];
        exec($cmd, $righe, $rc);
        $n = count(array_filter($righe, static fn (string $r): bool => str_starts_with($r, ' - ')));
        if ($rc !== 0 || $n === 0) {
            return ['casi' => null, 'motivo' => "PHPUnit non ha elencato i casi di $cartella (codice $rc)"];
        }
        $totale += $n;
    }

    return ['casi' => $totale, 'motivo' => ''];
}

$testFile = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($SITO . '/tests', FilesystemIterator::SKIP_DOTS)) as $f) {
    if (str_ends_with($f->getFilename(), 'Test.php')) {
        $testFile[] = $f->getPathname();
    }
}
$testNucleo = array_values(array_filter($testFile, static fn (string $f): bool => str_contains((string) file_get_contents($f), 'App\\Http\\')));
$provaHttp = glob($SITO . '/strumenti/prove/oop/pagine_*.php') ?: [];

$classiSrc = classi_in($SITO . '/src');
$dati = [
    'data' => date('Y-m-d'),
    'pagine' => [
        'totale' => count($pagine),
        'nel_nucleo' => count($rotte),
        'gusci' => count($gusci),
        'percentuale' => count($pagine) ? round(100 * count($rotte) / count($pagine), 1) : 0.0,
        'rotte_senza_token' => count($senzaToken),
    ],
    'ancora_da_convertire' => [
        'nega_accesso(' => ['file' => count($conta['nega_accesso']['file']), 'pagine' => $conta['nega_accesso']['pagine'], 'altri' => $conta['nega_accesso']['altri']],
        'csrf_verify(' => ['file' => count($conta['csrf_verify']['file']), 'pagine' => $conta['csrf_verify']['pagine'], 'altri' => $conta['csrf_verify']['altri']],
        '$h = fn' => ['file' => count($conta['h_fn']['file']), 'pagine' => $conta['h_fn']['pagine'], 'altri' => $conta['h_fn']['altri']],
    ],
    'classi' => [
        'src' => $classiSrc,
        'nucleo_src_http' => classi_in($SITO . '/src/Http'),
        'instradamento_e_pagine' => classi_in($SITO . '/src/Portale/Instradamento') + classi_in($SITO . '/src/Portale/Pagine'),
        'viste_modelli' => count(file_del_portale_viste($SITO)),
    ],
    'prove' => [
        'metodi_phpunit_totale' => prove_in($testFile),
        'metodi_phpunit_che_toccano_src_http' => prove_in($testNucleo),
        'casi_phpunit' => $vuoleCasi ? casi_phpunit($SITO) : null,
        'prove_http_delle_pagine' => count($provaHttp),
        'apache_nucleo' => is_file($SITO . '/strumenti/prove/apache_nucleo.sh'),
    ],
    'anomalie' => $anomalie,
];

/** @return list<string> i modelli di viste/ */
function file_del_portale_viste(string $radice): array
{
    $out = [];
    if (is_dir($radice . '/viste')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice . '/viste', FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
    }

    return $out;
}

if ($json) {
    echo json_encode($dati, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($controlla && $anomalie !== [] ? 1 : 0);
}

printf("Avanzamento del nucleo · %s\n\n", $dati['data']);
printf("Pagine (radice + admin/, senza nucleo.php): %d\n", $dati['pagine']['totale']);
printf("  servite dal nucleo (rotte in tabella):    %d  (%.1f%%)   gusci di tre righe: %d\n", $dati['pagine']['nel_nucleo'], $dati['pagine']['percentuale'], $dati['pagine']['gusci']);
printf("  rotte che rinunciano al token CSRF:       %d   (di norma 0: solo un webhook, con il suo motivo)\n\n", $dati['pagine']['rotte_senza_token']);

printf("Cosa si ripete ancora (file con la chiamata nel codice; di cui pagine / altri file di inc/, src/…)\n");
foreach ($dati['ancora_da_convertire'] as $nome => $v) {
    printf("  %-14s %3d file   (%d pagine, %d altri)\n", $nome, $v['file'], $v['pagine'], $v['altri']);
}

printf("\nClassi:  src/ %d  ·  nucleo (src/Http) %d  ·  instradamento e pagine del portale %d  ·  modelli di viste/ %d\n",
    $dati['classi']['src'], $dati['classi']['nucleo_src_http'], $dati['classi']['instradamento_e_pagine'], $dati['classi']['viste_modelli']);
printf("Metodi di prova:  PHPUnit %d file, %d metodi  ·  di cui toccano src/Http: %d file, %d metodi\n",
    $dati['prove']['metodi_phpunit_totale']['file'], $dati['prove']['metodi_phpunit_totale']['metodi'],
    $dati['prove']['metodi_phpunit_che_toccano_src_http']['file'], $dati['prove']['metodi_phpunit_che_toccano_src_http']['metodi']);
if ($vuoleCasi) {
    $c = $dati['prove']['casi_phpunit'];
    printf("Casi eseguiti da PHPUnit (dataProvider compresi): %s\n", $c['casi'] !== null ? number_format($c['casi'], 0, ',', '.') : 'non misurabili (' . $c['motivo'] . ')');
} else {
    echo "                  (i casi che PHPUnit esegue sono di più dei metodi: ogni dataProvider ne fa molti; per il numero vero: --casi)\n";
}
printf("Prove HTTP delle pagine (strumenti/prove/oop/pagine_*.php): %d  ·  Apache vero (apache_nucleo.sh): %s\n",
    $dati['prove']['prove_http_delle_pagine'], $dati['prove']['apache_nucleo'] ? 'sì' : 'no');

if ($anomalie !== []) {
    echo "\nANOMALIE (la tabella delle rotte e i gusci non coincidono):\n";
    foreach ($anomalie as $a) {
        echo "  - $a\n";
    }
    if ($controlla) {
        exit(1);
    }
} else {
    echo "\nLa tabella delle rotte e i gusci coincidono.\n";
}
