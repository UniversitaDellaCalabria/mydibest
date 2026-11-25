<?php
// =========================================================================
// Collaudo di «originale e documento protocollato» (passo 7) sul portale locale acceso (bash strumenti/locale/avvia.sh): crea una pratica con domanda e
// attestato protocollati e una lettera di tutorato, con file veri nelle cartelle chiuse, e verifica via HTTP chi può scaricare cosa:
// lo studente A sì (originale e documento protocollato, mai il file di Titulus né «Apri in Titulus»), lo studente B e un docente qualunque no,
// l'amministratore sì (anche il file di Titulus), «Entra come» solo in lettura. Titulus non viene mai chiamato.
//   php -d extension=zip -d extension=gd -d extension=intl strumenti/locale/collaudo_file_protocollati.php          (crea, prova, ripulisce)
//   php ... strumenti/locale/collaudo_file_protocollati.php --lascia                                                (lascia i dati per guardarli nel browser)
//   php ... strumenti/locale/collaudo_file_protocollati.php --ripulisci                                             (toglie i dati lasciati)
// Gli ID usati: pratiche FPCOLL-1/2, incarichi TUFPCOLL-1/2, studente B = utente con email collfp.b@example.org. Nel browser: utente 5 (studente), 1 (amministratore).
// =========================================================================
if (PHP_SAPI !== 'cli') {
    exit;
}
chdir(realpath(__DIR__ . '/../..'));
$BASE = 'http://127.0.0.1:8080';
$e = parse_ini_file('.env.locale');
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli($e['DB_HOST'], $e['DB_USER'], $e['DB_PASS'], $e['DB_NAME']);
$db->set_charset('utf8mb4');
$conn = $db;
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SESSION = [];
define('AMBIENTE_LOCALE_CLI', 1);
function inviaNotificaEmail($to, $subject, $body_html, $conn, $colore = null, array $allegati = []) { return true; }
require 'functions.php';
$LASCIA = in_array('--lascia', $argv, true);
$SOLO_PULIZIA = in_array('--ripulisci', $argv, true);

$ok = $ko = 0;
function prova(string $nome, bool $esito, string $dettaglio = ''): void
{
    global $ok, $ko;
    $esito ? $ok++ : $ko++;
    echo ($esito ? "  \033[32mOK\033[0m  " : "  \033[31mKO\033[0m  ") . $nome . ($esito || $dettaglio === '' ? '' : "  → $dettaglio") . "\n";
}
function titolo(string $t): void { echo "\n== $t\n"; }
function riga(string $sql): array { global $db; $r = $db->query($sql); return $r ? ($r->fetch_assoc() ?: []) : []; }
function ese(string $sql): void { global $db; if (!$db->query($sql)) { fwrite(STDERR, "SQL: {$db->error}\n$sql\n"); exit(2); } }

class Client
{
    public string $jar;
    public int $codice = 0;
    public string $tipo = '';
    public string $dove = '';

    public function __construct(private string $base, ?int $utente = null)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'cj');
        if ($utente) {
            $this->vai('/__accesso?u=' . $utente);
        }
    }

    public function vai(string $url, ?array $post = null): string
    {
        $c = curl_init($this->base . $url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 120]);
        if ($post !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $r = (string) curl_exec($c);
        $this->codice = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        $this->tipo = (string) curl_getinfo($c, CURLINFO_CONTENT_TYPE);
        $this->dove = (string) curl_getinfo($c, CURLINFO_REDIRECT_URL);
        curl_close($c);

        return $r;
    }

    /** Segue i rimandi (accesso di prova e simili) fino a una risposta vera. */
    public function seguendo(string $url): string
    {
        $r = $this->vai($url);
        for ($i = 0; $i < 5 && in_array($this->codice, [301, 302, 303], true) && $this->dove !== ''; ++$i) {
            $r = $this->vai(str_replace($this->base, '', $this->dove));
        }

        return $r;
    }

    public function csrf(string $pagina): string
    {
        $h = $this->seguendo($pagina);

        return preg_match('/name="csrf_token" value="([^"]+)"/', $h, $m) ? $m[1] : '';
    }
}

// ---- pulizia -----------------------------------------------------------------------------------------------------------------------------------------
$cartelle = ['pratiche' => 'uploads/pratiche/', 'incarichi' => 'uploads/incarichi/', 'titulus' => 'uploads/protocollo_file/'];
$pulisci = function () use ($db, $cartelle): void {
    foreach (['pratiche' => 'FPCOLL-', 'tutorato_incarichi' => 'TUFPCOLL-'] as $tab => $pre) {
        $ids = [];
        $r = $db->query("SELECT id FROM $tab WHERE codice LIKE '{$pre}%'");
        while ($r && ($x = $r->fetch_assoc())) { $ids[] = (int) $x['id']; }
        foreach ($ids as $id) {
            if ($tab === 'pratiche') {
                foreach (['pratiche_eventi', 'pratiche_firme'] as $t) { $db->query("DELETE FROM $t WHERE pratica_id = $id"); }
                $db->query("DELETE FROM protocollo_file WHERE rif_tipo IN ('pratica_domanda', 'pratica_uscita') AND rif_id = $id");
                $db->query("DELETE FROM titulus_invii WHERE rif_tipo IN ('pratica_domanda', 'pratica_uscita') AND rif_id = $id");
            } else {
                $db->query("DELETE FROM protocollo_file WHERE rif_tipo = 'tutorato_lettera' AND rif_id = $id");
                $db->query("DELETE FROM titulus_invii WHERE rif_tipo = 'tutorato_lettera' AND rif_id = $id");
                $db->query("DELETE FROM tutorato_eventi WHERE incarico_id = $id");
            }
            $db->query("DELETE FROM $tab WHERE id = $id");
        }
    };
    $db->query("DELETE FROM tutorato_bandi WHERE titolo = 'Bando collaudo file protocollati'");
    $db->query("DELETE FROM utenti WHERE email = 'collfp.b@example.org'");
    foreach ($cartelle as $c) {
        foreach (glob($c . 'collfp_*') ?: [] as $f) { @unlink($f); }
    }
};
$pulisci();
if ($SOLO_PULIZIA) {
    echo "Dati del collaudo tolti.\n";
    exit(0);
}

// ---- dati ----------------------------------------------------------------------------------------------------------------------------------------------
$modulo = (int) (riga("SELECT id FROM didattica_moduli WHERE tipo = 'online' ORDER BY id LIMIT 1")['id'] ?? 0);
$A = riga('SELECT * FROM utenti WHERE id = 5');
if (!$modulo || !$A) {
    exit("Mancano il modulo o lo studente di prova: lancia prima avvia.sh --nuovo e scenario_demo.php\n");
}
$cfA = (string) $A['codice_fiscale'];
ese("INSERT INTO utenti (nome, cognome, email, codice_fiscale, ruolo_id) VALUES ('Beatrice', 'Collaudo', 'collfp.b@example.org', 'CLLBRC02A41D086Z', 5)");
$idB = (int) $db->insert_id;

$pdf = fn(string $segno): string => "%PDF-1.4\n% $segno\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$scrivi = function (string $cartella, string $nome, string $contenuto) use ($cartelle): string {
    $dir = $cartelle[$cartella];
    @mkdir($dir, 0755, true);
    if (!is_file($dir . '.htaccess')) { file_put_contents($dir . '.htaccess', "Require all denied\n"); }
    file_put_contents($dir . $nome, $contenuto);

    return $dir . $nome;
};
$F = [
    'domanda' => $pdf('domanda originale PDF/A'), 'copia' => $pdf('domanda con segnatura'), 'firmato' => $pdf('attestato firmato dal Direttore'), 'att_prot' => $pdf('attestato con segnatura'),
    'interno' => $pdf('allegato interno'), 'titulus_d' => $pdf('domanda come in Titulus'), 'lettera' => $pdf('lettera firmata'), 'lettera_seg' => $pdf('lettera con segnatura'),
];
$rel = [];
$rel['domanda'] = $scrivi('pratiche', 'collfp_domanda.pdf', $F['domanda']);
$rel['copia'] = $scrivi('pratiche', 'collfp_domanda_prot.pdf', $F['copia']);
$rel['firmato'] = $scrivi('pratiche', 'collfp_firmato.pdf', $F['firmato']);
$rel['att_prot'] = $scrivi('pratiche', 'collfp_att_prot.pdf', $F['att_prot']);
$rel['interno'] = $scrivi('pratiche', 'collfp_interno.pdf', $F['interno']);
$rel['titulus_d'] = $scrivi('titulus', 'collfp_titulus_domanda.pdf', $F['titulus_d']);
$rel['lettera'] = $scrivi('incarichi', 'collfp_lettera.pdf', $F['lettera']);
$rel['lettera_seg'] = $scrivi('incarichi', 'collfp_lettera_prot.pdf', $F['lettera_seg']);
$e = fn(string $s): string => $db->real_escape_string($s);

ese("INSERT INTO pratiche (modulo_id, utente_id, codice, nome, cognome, email, matricola, risposte_json, stato, protocollo, protocollo_data, domanda_pdf, creata_il) VALUES
    ($modulo, 5, 'FPCOLL-1', '{$e($A['nome'])}', '{$e($A['cognome'])}', '{$e($A['email'])}', '1', '[]', 'chiusa', 'U-0001/2026', CURDATE(), '{$e($rel['domanda'])}', NOW())");
$p1 = (int) $db->insert_id;
$ev = function (int $p, string $nome, string $allegato, int $interno = 0, string $testo = 'prova') use ($e): int {
    global $db;
    ese("INSERT INTO pratiche_eventi (pratica_id, tipo, autore, testo, allegato, nome_allegato, interno) VALUES ($p, 'attivita', 'ufficio', '{$e($testo)}', '{$e($allegato)}', '{$e($nome)}', $interno)");

    return (int) $db->insert_id;
};
$evCopia = $ev($p1, 'Domanda_FPCOLL-1_protocollata.pdf', $rel['copia']);
$evAtt = $ev($p1, 'Firmato_direttore_FPCOLL-1.pdf', $rel['att_prot'], 0, 'Attestato firmato e protocollato in uscita: prot. n. U-0001/2026.');
$evInterno = $ev($p1, 'nota_interna.pdf', $rel['interno'], 1, 'Nota interna: non per lo studente');
ese("INSERT INTO pratiche_firme (pratica_id, tipo, stato, file_firmato) VALUES ($p1, 'direttore', 'firmata', '{$e($rel['firmato'])}')");
ese("INSERT INTO protocollo_file (rif_tipo, rif_id, numero, origine, file, impronta, scaricato_il, stato, tentativi, ultimo_tentativo_il) VALUES
    ('pratica_domanda', $p1, 'U-0001/2026', '', '{$e($rel['titulus_d'])}', '" . hash('sha256', $F['titulus_d']) . "', NOW(), 'ok', 1, NOW())");
ese("INSERT INTO protocollo_file (rif_tipo, rif_id, numero, origine, stato, tentativi, ultimo_tentativo_il, errore) VALUES
    ('pratica_uscita', $p1, 'U-0001/2026', 'copia', 'mancante', 3, NOW(), 'Titulus non risponde (prova)')");
ese("INSERT INTO titulus_invii (rif_tipo, rif_id, oggetto, esito, num_prot, file_principale) VALUES ('pratica_uscita', $p1, 'Prova', 'ok', 'U-0001/2026', 'a.pdf')");

ese("INSERT INTO tutorato_bandi (titolo, anno_accademico) VALUES ('Bando collaudo file protocollati', '2026/2027')");
$bando = (int) $db->insert_id;
foreach ([1 => 'protocollata', 2 => 'firmata'] as $n => $stato) {
    $tok = bin2hex(random_bytes(20));   // 40 caratteri esadecimali, come i link veri
    $file = $n === 1 ? $rel['lettera'] : $rel['lettera'];
    $seg = $n === 1 ? $rel['lettera_seg'] : null;
    ese("INSERT INTO tutorato_incarichi (bando_id, codice, stato, cognome, nome, codice_fiscale, email, token_studente, file_pdf, file_segnato, protocollo, protocollo_data, attivita, ore, compenso) VALUES
        ($bando, 'TUFPCOLL-$n', '$stato', '{$e($A['cognome'])}', '{$e($A['nome'])}', '{$e($cfA)}', '{$e($A['email'])}', '$tok', '{$e($file)}', " . ($seg ? "'{$e($seg)}'" : 'NULL') . ", " . ($n === 1 ? "'T-0001/2026', CURDATE()" : "'', NULL") . ", 'Tutorato di prova', 10, 100)");
    ${'t' . $n} = (int) $db->insert_id;
    ${'tok' . $n} = $tok;
}
ese("INSERT INTO titulus_invii (rif_tipo, rif_id, oggetto, esito, errore) VALUES ('tutorato_lettera', $t2, 'Prova esito incerto', 'incerto', 'La risposta di Titulus non è arrivata')");

// ---- prove via HTTP ---------------------------------------------------------------------------------------------------------------------------------
$sha = fn(string $c): string => hash('sha256', $c);
$cA = new Client($BASE, 5);
$cB = new Client($BASE, $idB);
$cD = new Client($BASE, 4);
$cAdm = new Client($BASE, 1);
$cAnon = new Client($BASE, null);
$link = fn(string $d, string $v) => "/mydibest/allegato_pratica.php?p=$p1&d=$d&v=$v";
$scarica = function (Client $c, string $url): array {
    $corpo = $c->vai($url);

    return [$c->codice, $corpo, $c->tipo];
};

titolo('Lo studente A (autore della pratica)');
foreach ([['domanda', 'originale', 'domanda'], ['domanda', 'protocollato', 'copia'], ['attestato', 'originale', 'firmato'], ['attestato', 'protocollato', 'att_prot']] as [$d, $v, $k]) {
    [$cod, $corpo, $tipo] = $scarica($cA, $link($d, $v));
    prova("A scarica $d / $v (200, PDF, byte per byte quello salvato)", $cod === 200 && str_contains($tipo, 'application/pdf') && $sha($corpo) === $sha($F[$k]), "risposta $cod");
}
foreach ([['domanda', 'titulus'], ['domanda', 'apri'], ['attestato', 'titulus'], ['attestato', 'apri'], ['domanda', 'inventata']] as [$d, $v]) {
    [$cod] = $scarica($cA, $link($d, $v));
    prova("A NON ottiene $d / $v (solo l'ufficio vede il file di Titulus)", $cod === 404, "risposta $cod");
}
[$cod] = $scarica($cA, "/mydibest/allegato_pratica.php?p=$p1&e=$evInterno");
prova('A non apre l\'allegato interno dell\'ufficio', $cod === 404, "risposta $cod");
[$cod, $corpo] = $scarica($cA, "/mydibest/allegato_pratica.php?p=$p1&e=$evCopia");
prova('il vecchio indirizzo ?e= della «Domanda_*_protocollata.pdf» funziona ancora', $cod === 200 && $sha($corpo) === $sha($F['copia']), "risposta $cod");
[$cod, $corpo] = $scarica($cA, "/mydibest/allegato_pratica.php?p=$p1&e=$evAtt");
prova('il vecchio indirizzo ?e= dell\'attestato protocollato funziona ancora', $cod === 200 && $sha($corpo) === $sha($F['att_prot']), "risposta $cod");
[$cod, $corpo] = $scarica($cA, '/mydibest/pratiche.php?id=' . $p1);
prova('la pagina «Le mie pratiche» offre «Documento protocollato» e «Originale firmato», non il file di Titulus né «Riprova»',
    $cod === 200 && str_contains($corpo, 'd=attestato&amp;v=protocollato') && str_contains($corpo, 'd=attestato&amp;v=originale') && !str_contains($corpo, 'Riprova da Titulus') && !str_contains($corpo, 'Apri in Titulus') && !str_contains($corpo, 'v=titulus'), "risposta $cod");

titolo('Chi non c\'entra: studente B, docente senza ruoli, nessun accesso');
foreach ([['B', $cB], ['docente', $cD]] as [$nome, $c]) {
    foreach ([['domanda', 'originale'], ['domanda', 'protocollato'], ['attestato', 'originale'], ['attestato', 'protocollato'], ['domanda', 'titulus']] as [$d, $v]) {
        [$cod] = $scarica($c, $link($d, $v));
        prova("$nome non ottiene $d / $v", $cod === 404, "risposta $cod");
    }
    [$cod] = $scarica($c, "/mydibest/allegato_pratica.php?p=$p1&e=$evCopia");
    prova("$nome non ottiene nemmeno il vecchio ?e=", $cod === 404, "risposta $cod");
}
[$cod] = $scarica($cAnon, $link('domanda', 'originale'));
prova('senza accesso si viene mandati al login', in_array($cod, [301, 302], true), "risposta $cod");

titolo('L\'ufficio (amministratore)');
foreach ([['domanda', 'originale', 'domanda'], ['domanda', 'protocollato', 'copia'], ['domanda', 'titulus', 'titulus_d'], ['attestato', 'originale', 'firmato'], ['attestato', 'protocollato', 'att_prot']] as [$d, $v, $k]) {
    [$cod, $corpo] = $scarica($cAdm, $link($d, $v));
    prova("l'amministratore scarica $d / $v", $cod === 200 && $sha($corpo) === $sha($F[$k]), "risposta $cod");
}
[$cod] = $scarica($cAdm, $link('attestato', 'titulus'));
prova('attestato / titulus: il file non è ancora arrivato → 404 (nessuna chiamata a Titulus)', $cod === 404, "risposta $cod");
$pg = $cAdm->seguendo("/mydibest/admin/didattica.php?tab=pratiche&id=$p1&p_id=1");
prova('la scheda della pratica mostra le due voci, l\'origine, lo stato del file di Titulus, «Apri in Titulus» e «Riprova da Titulus»',
    $cAdm->codice === 200 && str_contains($pg, 'data-file-protocollato="pratica_domanda"') && str_contains($pg, 'data-file-protocollato="pratica_uscita"') && str_contains($pg, 'Riprova da Titulus') && str_contains($pg, 'Apri in Titulus')
    && str_contains($pg, 'non arrivato') && str_contains($pg, 'copia con la segnatura del portale'), 'risposta ' . $cAdm->codice);
$tok_csrf = $cAdm->csrf("/mydibest/admin/didattica.php?tab=pratiche&id=$p1&p_id=1");

titolo('«Entra come…» è di sola lettura');
$csrfSim = $cAdm->csrf('/mydibest/admin/simula.php');
$cAdm->vai('/mydibest/admin/simula.php', ['csrf_token' => $csrfSim, 'utente_id' => 5]);
prova('l\'amministratore entra come lo studente A', in_array($cAdm->codice, [302, 303], true), 'risposta ' . $cAdm->codice);
[$cod, $corpo] = $scarica($cAdm, $link('domanda', 'originale'));
prova('in simulazione si può leggere: l\'originale si scarica', $cod === 200 && $sha($corpo) === $sha($F['domanda']), "risposta $cod");
[$cod] = $scarica($cAdm, $link('domanda', 'titulus'));
prova('in simulazione si vede quello che vede lo studente: il file di Titulus no', $cod === 404, "risposta $cod");
$cAdm->vai("/mydibest/admin/didattica.php?tab=pratiche&id=$p1&p_id=1", ['csrf_token' => $tok_csrf, 'pratica_id' => $p1, 'riprova_file_prot' => 'domanda']);
prova('in simulazione «Riprova da Titulus» è bloccato', $cAdm->codice === 403, 'risposta ' . $cAdm->codice);
$cAdm->vai('/mydibest/simula_esci.php', ['csrf_token' => $csrfSim]);

titolo('«Riprova da Titulus» a mano (Titulus non configurato in locale: si vede il messaggio, non succede altro)');
$tok_csrf = $cAdm->csrf("/mydibest/admin/didattica.php?tab=pratiche&id=$p1&p_id=1");
$cAdm->vai("/mydibest/admin/didattica.php?tab=pratiche&id=$p1&p_id=1", ['csrf_token' => $tok_csrf, 'pratica_id' => $p1, 'riprova_file_prot' => 'attestato']);
$r = riga("SELECT stato, tentativi FROM protocollo_file WHERE rif_tipo = 'pratica_uscita' AND rif_id = $p1");
prova('il tentativo si conta e la riga resta «mancante»', $r['stato'] === 'mancante' && (int) $r['tentativi'] === 4, json_encode($r));
$cA2 = new Client($BASE, 5);
$cA2->vai("/mydibest/admin/didattica.php?tab=pratiche&id=$p1&p_id=1", ['csrf_token' => $tok_csrf, 'pratica_id' => $p1, 'riprova_file_prot' => 'attestato']);
$r = riga("SELECT tentativi FROM protocollo_file WHERE rif_tipo = 'pratica_uscita' AND rif_id = $p1");
prova('lo studente non può premere «Riprova»', (int) $r['tentativi'] === 4 && in_array($cA2->codice, [302, 403], true), json_encode($r) . ' risposta ' . $cA2->codice);

titolo('Lettera di tutorato');
foreach ([['originale', 'lettera'], ['protocollato', 'lettera_seg']] as [$v, $k]) {
    [$cod, $corpo] = $scarica($cA, "/mydibest/incarico.php?t=$tok1&pdf=$v");
    prova("il tutor scarica la lettera ($v)", $cod === 200 && $sha($corpo) === $sha($F[$k]), "risposta $cod");
}
[$cod, $corpo] = $scarica($cB, "/mydibest/incarico.php?t=$tok1&pdf=protocollato");
prova('un\'altra persona con lo stesso link non scarica nulla', !str_starts_with($corpo, '%PDF'), "risposta $cod");
[$cod] = $scarica($cA, "/mydibest/incarico.php?t=$tok2&pdf=protocollato");
prova('prima del protocollo non c\'è il documento protocollato', $cod === 404, "risposta $cod");
foreach ([['originale', 'lettera'], ['protocollato', 'lettera_seg']] as [$v, $k]) {
    [$cod, $corpo] = $scarica($cAdm, "/mydibest/admin/tutorato.php?p_id=1&incarico=$t1&file=$v");
    prova("l'ufficio scarica $v dal pannello del tutorato", $cod === 200 && $sha($corpo) === $sha($F[$k]), "risposta $cod");
}
[$cod] = $scarica($cAdm, "/mydibest/admin/tutorato.php?p_id=1&incarico=$t1&file=titulus");
prova('file di Titulus della lettera non ancora ripreso → 404', $cod === 404, "risposta $cod");
foreach (['originale', 'protocollato', 'titulus', 'apri'] as $v) {
    [$cod, $corpo] = $scarica($cA, "/mydibest/admin/tutorato.php?p_id=1&incarico=$t1&file=$v");
    prova("lo studente non apre $v dal pannello del tutorato", !str_starts_with($corpo, '%PDF') && $cod !== 200 || $cod === 403, "risposta $cod");
}
$pg = $cAdm->seguendo("/mydibest/admin/tutorato.php?p_id=1&incarico=$t1");
prova('la scheda della lettera protocollata mostra le due voci e «Riprova da Titulus»', $cAdm->codice === 200 && str_contains($pg, 'data-file-protocollato="tutorato_lettera"') && str_contains($pg, 'Riprova da Titulus'), 'risposta ' . $cAdm->codice);
$pg = $cAdm->seguendo("/mydibest/admin/tutorato.php?p_id=1&incarico=$t2");
prova('la lettera con esito incerto mostra «Non c\'è in Titulus: riprova» e nessun documento protocollato', $cAdm->codice === 200 && str_contains($pg, 'Non c\'è in Titulus: riprova') && !str_contains($pg, 'data-file-protocollato="tutorato_lettera"'), 'risposta ' . $cAdm->codice);

titolo('La pratica eliminata porta via anche il file di Titulus');
$file_t = $scrivi('titulus', 'collfp_titulus_da_eliminare.pdf', $pdf('da eliminare'));
ese("INSERT INTO pratiche (modulo_id, utente_id, codice, nome, cognome, email, matricola, risposte_json, stato, protocollo, creata_il) VALUES ($modulo, 5, 'FPCOLL-2', 'A', 'B', 'a@b.it', '1', '[]', 'chiusa', 'X-1', NOW())");
$p2 = (int) $db->insert_id;
ese("INSERT INTO protocollo_file (rif_tipo, rif_id, numero, file, impronta, scaricato_il, stato, tentativi) VALUES ('pratica_domanda', $p2, 'X-1', '{$e($file_t)}', '" . hash('sha256', 'x') . "', NOW(), 'ok', 1)");
$el = \App\Core\App::per($db)->get(\App\Didattica\EliminaPratica::class);
$esito = $el->elimina($p2);
prova('EliminaPratica toglie pratica, riga e file di Titulus', $esito['errore'] === null && !is_file($file_t) && !riga("SELECT id FROM protocollo_file WHERE rif_id = $p2 AND rif_tipo = 'pratica_domanda'") && !riga("SELECT id FROM pratiche WHERE id = $p2"), json_encode($esito));

echo "\n$ok prove riuscite, $ko fallite.\n";
if (!$LASCIA) {
    $pulisci();
    echo "Dati del collaudo tolti.\n";
} else {
    echo "Dati lasciati: pratica FPCOLL-1 (utente 5 e amministratore 1), lettere TUFPCOLL-1 e -2. Per toglierli: --ripulisci.\n";
}
exit($ko > 0 ? 1 : 0);
