<?php
// =========================================================================
// Prova di lettura dei file da Titulus: SOLO da riga di comando e SOLO in lettura (nessuna scrittura in Titulus né nel database).
// Serve a sapere, in pre-produzione, se l'utente del web service può leggere i file di un documento (loadDocument e getAttachment), se il file che
// Titulus restituisce è quello inviato o la copia con la stampigliatura, e se un documento protocollato a mano si legge dal suo numero.
//
// Funziona SENZA caricare nulla di nuovo nel portale: sta in una cartella a sé con la copia del connettore accanto (php-titulus-utility/src, e in
// pdf/ la classe che conta le firme) e carica il connettore da lì, prima di quello del portale. Il pacchetto si crea con
//   bash strumenti/crea_prova_titulus.sh      → prova_file_titulus.tgz (da copiare nella home del server)
//
//   php prova_file_titulus.php --sito=/percorso/del/portale --nrecord=NNN [--numero=2026-UCALPRG-0001234]
//        [--confronta=file.pdf] [--salva=/percorso/fuori/dalla/cartella/web] [--produzione]
//
//   --sito         cartella del portale: da lì si leggono .env, configurazione di Titulus e password cifrata, senza modificare nulla.
//                  Senza --sito lo strumento chiede indirizzo del servizio, utente e password (la password senza eco; oppure la variabile
//                  d'ambiente TITULUS_PASSWORD, l'unica via su Windows).
//   --nrecord      numero di record del documento in Titulus (colonna «nrecord» del registro degli invii);
//   --numero       numero di protocollo, anche di un documento protocollato a mano. Dati entrambi, si provano tutti e due.
//   --confronta    un file PDF di riferimento (per esempio quello che il portale ha inviato): dice se il file di Titulus coincide.
//   --salva        cartella dove salvare i file letti: deve stare FUORI dalla cartella web; si crea con permessi 0700 (file 0600).
//   --produzione   obbligatorio per un indirizzo che non è di pre-produzione (host che finisce per «.pp.cineca.it»). Resta in sola lettura.
//
// Non stampa mai la password, né l'XML della risposta, né i nomi di chi ha firmato. I titoli dei file sì (servono a riconoscerli).
// =========================================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
ini_set('memory_limit', '512M');
date_default_timezone_set('Europe/Rome');

// --- Caricamento: prima il connettore di questa cartella, poi (se serve) il portale ------------------------------------------------
$qui = __DIR__;
$cartelle = [
    'Unical\\Titulus\\' => [$qui . '/php-titulus-utility/src', $qui . '/../protocollo/php-titulus-utility/src'],
    'App\\Infrastructure\\Pdf\\' => [$qui . '/pdf', $qui . '/../src/Infrastructure/Pdf'],
];
// Il connettore di questa cartella ha la precedenza (terzo argomento true: in testa alla coda degli autoload) su quello del portale
spl_autoload_register(static function (string $classe) use ($cartelle): void {
    foreach ($cartelle as $prefisso => $dirs) {
        if (strncmp($classe, $prefisso, strlen($prefisso)) !== 0) {
            continue;
        }
        foreach ($dirs as $d) {
            $file = $d . '/' . str_replace('\\', '/', substr($classe, strlen($prefisso))) . '.php';
            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }
}, true, true);

use Unical\Titulus\ClienteSoapTitulus;
use Unical\Titulus\ClienteTitulus;
use Unical\Titulus\ConfigTitulus;
use Unical\Titulus\FabbricaClienteTitulus;
use Unical\Titulus\ServizioTitulus;

// --- Uscita: mai la password, mai caratteri di controllo ---------------------------------------------------------------------------
$SEGRETI = [];
/** Una riga a schermo, senza caratteri di controllo (un titolo potrebbe portare sequenze di escape) e senza i segreti noti. */
function riga(string $testo = ''): void
{
    global $SEGRETI;
    foreach ($SEGRETI as $s) {
        if ($s !== '') {
            $testo = str_replace($s, '***', $testo);
        }
    }
    echo preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '?', $testo), "\n";
}
function esci(string $messaggio, int $codice = 1): never
{
    riga($messaggio);
    exit($codice);
}
function pulito(string $s, int $max = 80): string
{
    $s = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $s);

    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}
function mb_dim(int $byte): string
{
    return $byte >= 1048576 ? number_format($byte / 1048576, 1, ',', '.') . ' MB' : number_format($byte / 1024, 1, ',', '.') . ' KB';
}

// --- Opzioni ---------------------------------------------------------------------------------------------------------------------
$opz = [];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--aiuto' || $a === '-h' || $a === '--help') {
        $opz['aiuto'] = '1';
    } elseif (preg_match('/^--([a-z]+)(?:=(.*))?$/s', $a, $m) === 1) {
        $opz[$m[1]] = $m[2] ?? '1';
    } else {
        esci("Argomento non riconosciuto: «" . pulito($a, 40) . "». Vedi l'intestazione di questo file (--aiuto).", 2);
    }
}
$conosciute = ['aiuto', 'sito', 'nrecord', 'numero', 'confronta', 'salva', 'produzione'];
foreach (array_keys($opz) as $k) {
    if (!in_array($k, $conosciute, true)) {
        esci("Opzione sconosciuta: --$k. Vedi l'intestazione di questo file (--aiuto).", 2);
    }
}
if (isset($opz['aiuto']) || (!isset($opz['nrecord']) && !isset($opz['numero']))) {
    riga('Uso: php prova_file_titulus.php [--sito=/percorso/del/portale] --nrecord=NNN [--numero=2026-UCALPRG-0001234] [--confronta=file.pdf] [--salva=CARTELLA] [--produzione]');
    riga('Solo lettura. Vedi l\'intestazione di questo file per il significato delle opzioni.');
    exit(isset($opz['aiuto']) ? 0 : 2);
}

$ids = [];
foreach (['nrecord' => 'numero di record', 'numero' => 'numero di protocollo'] as $k => $nome) {
    if (isset($opz[$k])) {
        $v = trim($opz[$k]);
        if (preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $v) !== 1) {
            esci("Il $nome non è scritto in modo valido (lettere, cifre, trattini e barre, senza spazi).", 2);
        }
        $ids[] = [$nome, $v];
    }
}
$produzione = isset($opz['produzione']);

// --- Configurazione: dal portale (sola lettura) o chiesta a tastiera -------------------------------------------------------------
$password = '';
if (isset($opz['sito'])) {
    $sito = realpath($opz['sito']);
    if ($sito === false || !is_file($sito . '/src/autoload.php') || !is_file($sito . '/.env')) {
        esci('--sito deve essere la cartella del portale (con src/autoload.php e il file .env).', 2);
    }
    // Le classi del portale servono solo a leggere: config.php non si carica (avvierebbe sessione e intestazioni)
    require_once $sito . '/src/autoload.php';

    $testoEnv = (string) @file_get_contents($sito . '/.env');
    $env = @parse_ini_string((string) preg_replace('/^\s*#.*$/m', '', $testoEnv)) ?: [];
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli((string) ($env['DB_HOST'] ?? 'localhost'), (string) ($env['DB_USER'] ?? ''), (string) ($env['DB_PASS'] ?? ''), (string) ($env['DB_NAME'] ?? ''));
    if ($conn->connect_errno) {
        esci('Il database del portale non risponde (errore ' . (int) $conn->connect_errno . '): controlla il file .env di quel portale.');
    }
    $conn->set_charset('utf8mb4');
    // Garanzia in più: la sessione non può scrivere (se il server non lo permette si va avanti, le query sono comunque solo SELECT)
    @$conn->query('SET SESSION TRANSACTION READ ONLY');

    $repo = new \App\Protocollo\ProtocolloRepository(\App\Core\Database::per($conn));
    $riga_cfg = $repo->configurazione();
    if ($riga_cfg === [] || trim((string) ($riga_cfg['url_ws'] ?? '')) === '') {
        esci('Nel portale il collegamento a Titulus non è configurato (Sistema → Protocollo).');
    }
    $cifratura = \App\Infrastructure\Sicurezza\Cifratura::daEnv(new \App\Sistema\FileEnv($sito . '/.env'));
    $password = (string) $cifratura->decifra($repo->passwordCifrata());
    if ($password === '') {
        esci('La password di Titulus non si legge: CIFRATURA_CHIAVE nel .env di quel portale non è quella di quando la password è stata salvata, oppure la password non è stata salvata.');
    }
    $conn->close();
    // Sola lettura: il collegamento si usa anche se nel portale è spento
    $config = ConfigTitulus::daArray(['attivo' => 1, 'aoo' => trim((string) ($riga_cfg['aoo'] ?? '')) !== '' ? $riga_cfg['aoo'] : 'LETTURA'] + $riga_cfg);
} else {
    $sito = null;
    echo 'Indirizzo del servizio (…/services/Titulus4?wsdl): ';
    $url = trim((string) fgets(STDIN));
    echo 'Utente del web service: ';
    $utente = trim((string) fgets(STDIN));
    $password = (string) getenv('TITULUS_PASSWORD');
    if ($password === '') {
        $password = leggiSenzaEco('Password (non si vede mentre la scrivi): ');
    }
    if ($url === '' || $utente === '' || $password === '') {
        esci('Servono indirizzo, utente e password.', 2);
    }
    $config = new ConfigTitulus($url, $utente, 'LETTURA'); // l'AOO serve a protocollare, non a leggere
}
$SEGRETI = [$password, base64_encode($config->username . ':' . $password)];

/** La password digitata senza che compaia a schermo; dove non si può nascondere si rifiuta (mai con l'eco). */
function leggiSenzaEco(string $domanda): string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        esci('Su Windows la password non si può nascondere: imposta la variabile d\'ambiente TITULUS_PASSWORD e rilancia.', 2);
    }
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) {
        if (!function_exists('shell_exec') || @shell_exec('stty -echo 2>/dev/null; echo ok') === null) {
            esci('Non riesco a nascondere la password: imposta la variabile d\'ambiente TITULUS_PASSWORD e rilancia.', 2);
        }
    }
    echo $domanda;
    $p = (string) fgets(STDIN);
    if ($tty) {
        @shell_exec('stty echo 2>/dev/null');
        echo "\n";
    }

    return rtrim($p, "\r\n");
}

// --- Solo pre-produzione, salvo --produzione --------------------------------------------------------------------------------------
$host = strtolower(rtrim((string) parse_url(trim($config->urlWs), PHP_URL_HOST), '.'));
if ($host === '') {
    esci("L'indirizzo del servizio non è un indirizzo web valido.");
}
$preprod = str_ends_with($host, '.pp.cineca.it');
if (!$preprod && !$produzione) {
    esci("Rifiuto: «$host» non è un indirizzo di pre-produzione (deve finire per .pp.cineca.it). Se vuoi davvero leggere da PRODUZIONE (resta in sola lettura) rilancia con --produzione.");
}
riga('Titulus: ' . $host . ($preprod ? ' (pre-produzione)' : ' — ATTENZIONE: PRODUZIONE, sola lettura'));
riga('Modalità: solo lettura (loadDocument, getAttachment, getDocumentURL). Nessuna scrittura in Titulus né nel database.');

// --- Cartella dove salvare ---------------------------------------------------------------------------------------------------------
$cartellaSalva = null;
if (isset($opz['salva'])) {
    $cartellaSalva = preparaCartella($opz['salva'], $sito);
}

/** La cartella dei file salvati: fuori dalla cartella web e dal portale, permessi 0700. */
function preparaCartella(string $percorso, ?string $sito): string
{
    $percorso = rtrim(trim($percorso), '/\\');
    if ($percorso === '' || strlen($percorso) > 300 || str_contains($percorso, "\0")) {
        esci('--salva: il percorso non è valido.', 2);
    }
    $messaggioWeb = '--salva: scegli una cartella FUORI dalla cartella web e dal portale (per esempio dentro la tua home, fuori da htdocs).';
    $cartellaWeb = '~(^|/)(htdocs|public_html|www|wwwroot|html)(/|$)~i';
    if (preg_match($cartellaWeb, str_replace('\\', '/', $percorso)) === 1) {
        esci($messaggioWeb, 2);
    }
    $esiste = is_dir($percorso);
    $padre = realpath($esiste ? $percorso : dirname($percorso));
    if ($padre === false || !is_dir($padre)) {
        esci('--salva: la cartella (o quella che la contiene) non esiste.', 2);
    }
    $assoluto = str_replace('\\', '/', $esiste ? $padre : $padre . '/' . basename($percorso));
    $dentroSito = $sito !== null && ($assoluto === str_replace('\\', '/', $sito) || str_starts_with($assoluto . '/', rtrim(str_replace('\\', '/', $sito), '/') . '/'));
    if ($dentroSito || preg_match($cartellaWeb, $assoluto) === 1) {
        esci($messaggioWeb, 2);
    }
    if (!$esiste) {
        if (!@mkdir($assoluto, 0700, true) && !is_dir($assoluto)) {
            esci('--salva: non riesco a creare la cartella.', 2);
        }
        @chmod($assoluto, 0700);
    } elseif (DIRECTORY_SEPARATOR === '/' && (fileperms($assoluto) & 0077) !== 0 && !@chmod($assoluto, 0700)) {
        esci('--salva: la cartella è leggibile da altri utenti e non riesco a chiuderla (chmod 0700): scegline un\'altra.', 2);
    }

    return $assoluto;
}

// --- Il file di confronto ------------------------------------------------------------------------------------------------------
$confronto = null;
if (isset($opz['confronta'])) {
    $f = $opz['confronta'];
    if (!is_file($f) || !is_readable($f) || filesize($f) > 100 * 1048576) {
        esci('--confronta: il file non c\'è, non si legge o supera 100 MB.', 2);
    }
    $confronto = ['sha' => hash_file('sha256', $f), 'dim' => (int) filesize($f)];
}

// --- Collegamento ----------------------------------------------------------------------------------------------------------------
$cliente = new ClienteSoapTitulus($config, $password);
$fabbrica = new class ($cliente) implements FabbricaClienteTitulus {
    public function __construct(private ClienteTitulus $c)
    {
    }

    public function crea(ConfigTitulus $config, string $password): ClienteTitulus
    {
        return $this->c;
    }
};
$titulus = new ServizioTitulus($config, $password, $fabbrica);

riga();
riga('== Operazioni del servizio ==');
try {
    $op = $cliente->operazioni();
    riga('Il WSDL è stato letto: ' . count($op) . ' operazioni.');
    foreach (['loadDocument', 'getAttachment', 'getDocumentURL'] as $n) {
        riga(sprintf('  %-16s %s', $n, in_array($n, $op, true) ? 'sì' : 'NO — manca nel WSDL'));
    }
} catch (Throwable $e) {
    esci('Collegamento a Titulus non riuscito: ' . pulito($e->getMessage(), 300));
}

// --- I documenti -------------------------------------------------------------------------------------------------------------------
$problemi = 0;
$riassunto = [];
foreach ($ids as [$nome, $id]) {
    riga();
    riga("== Documento: $nome $id ==");
    $elenco = $titulus->fileDelDocumento($id);
    if (!$elenco['ok']) {
        riga('  Non riesco a leggere i file: ' . pulito($elenco['messaggio'], 300));
        $riassunto[] = "$nome $id: " . pulito($elenco['messaggio'], 120);
        $problemi++;
        stampaIndirizzo($cliente, $id);
        continue;
    }
    riga('  ' . $elenco['messaggio']);
    $n = 0;
    $esiti = [];
    foreach ($elenco['file'] as $f) {
        $n++;
        riga('  [' . $n . '] ' . pulito($f['titolo']) . (($f['principale'] ?? false) ? '  (principale per Titulus)' : ''));
        $r = $titulus->scaricaFile($f['fileId']);
        if (!$r['ok']) {
            riga('      non riesco a scaricarlo: ' . pulito($r['messaggio'], 300));
            $problemi++;
            $esiti[] = "[$n] non scaricato";
            continue;
        }
        $c = $r['contenuto'];
        $dim = strlen($c);
        $pdf = str_starts_with($c, '%PDF-');
        $sha = hash('sha256', $c);
        riga('      dimensione: ' . mb_dim($dim) . ' (' . $dim . ' byte)' . ($dim > 30 * 1048576 ? ' — OLTRE 30 MB: il portale non lo scaricherebbe' : ''));
        riga('      PDF: ' . ($pdf ? 'sì' : 'NO'));
        riga('      firme: ' . descriviFirme($c, $pdf));
        riga('      SHA-256: ' . $sha);
        $dichiarata = strtolower((string) ($f['sha256'] ?? ''));
        riga('      impronta di Titulus: ' . ($dichiarata === '' ? 'assente' : ($dichiarata === $sha ? 'coincide' : 'diversa (Titulus dichiara ' . $dichiarata . ')')));
        if ($dichiarata !== '' && $dichiarata !== $sha) {
            $problemi++;
        }
        if ($confronto !== null) {
            if ($sha === $confronto['sha']) {
                riga('      confronto: COINCIDE con il file indicato (è lo stesso file, senza stampigliatura aggiunta)');
            } elseif ($dim > $confronto['dim'] && hash('sha256', substr($c, 0, $confronto['dim'])) === $confronto['sha']) {
                riga('      confronto: DIVERSO, ma comincia con i byte del file indicato: Titulus ha aggiunto qualcosa in coda (+' . ($dim - $confronto['dim']) . ' byte)');
            } else {
                riga('      confronto: DIVERSO dal file indicato (' . mb_dim($confronto['dim']) . '): probabilmente è stato riscritto, per esempio con la stampigliatura');
            }
        }
        if ($cartellaSalva !== null) {
            riga('      salvato: ' . salva($cartellaSalva, $id, $n, $sha, $pdf, $c));
        }
        $esiti[] = "[$n] " . ($pdf ? 'PDF' : 'non PDF');
    }
    stampaIndirizzo($cliente, $id);
    $riassunto[] = "$nome $id: " . count($elenco['file']) . ' file (' . implode(', ', $esiti) . ')';
}

riga();
riga('== Riepilogo ==');
foreach ($riassunto as $r) {
    riga('  ' . $r);
}
riga($problemi === 0 ? 'Nessun problema di lettura.' : "Problemi di lettura: $problemi. Se è «Permesso negato», chiedi all'Ufficio Protocollo di abilitare loadDocument e getAttachment per l'utente del web service.");
exit($problemi === 0 ? 0 : 1);

// --- Funzioni --------------------------------------------------------------------------------------------------------------------

/** L'indirizzo di getDocumentURL (la richiesta fallita non ferma il resto). */
function stampaIndirizzo(ClienteSoapTitulus $cliente, string $id): void
{
    try {
        $u = trim((string) ($cliente->urlDocumento($id) ?? ''));
        riga('  getDocumentURL: ' . ($u !== '' ? pulito($u, 300) : 'Titulus non ha dato nessun indirizzo'));
    } catch (Throwable $e) {
        riga('  getDocumentURL: non riuscito (' . pulito($e->getMessage(), 200) . ')');
    }
}

/** Quante firme ci sono e di che formato, mai il nome di chi ha firmato. */
function descriviFirme(string $contenuto, bool $pdf): string
{
    if (!$pdf) {
        return 'non applicabile (non è un PDF)';
    }
    if (!class_exists(\App\Infrastructure\Pdf\FirmePades::class)) {
        return 'non verificabili (manca la classe FirmePades)';
    }
    try {
        $firme = (new \App\Infrastructure\Pdf\FirmePades())->firmePades($contenuto);
    } catch (Throwable $e) {
        return 'lettura non riuscita (' . pulito($e->getMessage(), 100) . ')';
    }
    if ($firme === []) {
        return 'nessuna firma PAdES trovata';
    }
    $vere = 0;
    $parti = [];
    foreach ($firme as $f) {
        $marca = !empty($f['marca_temporale']);
        $vere += $marca ? 0 : 1;
        $parti[] = pulito((string) ($f['subfilter'] ?? '?'), 40) . ($marca ? ' (marca temporale)' : '');
    }
    $coperto = end($firme)['copre_tutto'] ?? null;

    return count($firme) . ' voci, di cui ' . $vere . ' firme e ' . (count($firme) - $vere) . ' marche temporali [' . implode(', ', $parti) . ']'
        . ($coperto === true ? '; l\'ultima copre tutto il file' : ($coperto === false ? '; l\'ultima NON copre tutto il file (qualcosa è stato aggiunto dopo)' : ''));
}

/** Salva il file nella cartella chiusa (0600, mai sopra un file che c'è già). */
function salva(string $cartella, string $id, int $n, string $sha, bool $pdf, string $contenuto): string
{
    $sicuro = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $id);
    $nome = 'titulus_' . $sicuro . '_' . $n . '_' . substr($sha, 0, 10) . ($pdf ? '.pdf' : '.bin');
    $percorso = $cartella . '/' . $nome;
    if (file_exists($percorso)) {
        return $nome . ' (c\'era già, non l\'ho toccato)';
    }
    $fh = @fopen($percorso, 'xb');
    if ($fh === false) {
        return 'NON riuscito: non riesco a scrivere in quella cartella';
    }
    fwrite($fh, $contenuto);
    fclose($fh);
    @chmod($percorso, 0600);

    return $nome;
}
