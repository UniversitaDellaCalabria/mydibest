<?php
// pubblicazioni.php - Richieste di pubblicazione (pannello degli operatori): due schede, «Richieste arrivate» (quelle non ancora nel file Excel) e
// «Tabella di monitoraggio» (solo le righe nel file Excel, con filtri e scaricamento in Excel, PDF e CSV); la scheda di una richiesta (?id=N) con quello che
// ha inviato il docente e le colonne degli operatori; la riga a mano (?nuova=1); le impostazioni (?vista=impostazioni: indirizzi che ricevono le richieste).
// La scheda scelta resta nell'indirizzo (?vista=arrivate | monitoraggio), così dopo un salvataggio si torna al punto giusto.
// Permesso controllato a OGNI richiesta, anche GET e scaricamenti (ServizioPubblicazioni::puoGestire: amministratori e abilitati al modulo «Richieste di pubblicazione»).
// Le regole stanno in src/Pubblicazioni/ (ServizioPubblicazioni, PubblicazioniRepository, ColonneMonitoraggio, EsportazioneMonitoraggio, Completamento,
// RegistroModifiche, VistaRichiesta); gli allegati si scaricano da allegato_pubblicazione.php (che ricontrolla lo stesso permesso).
require_once 'admin_header.php';

use App\Pubblicazioni\AllegatiPubblicazioni;
use App\Pubblicazioni\ColonneMonitoraggio;
use App\Pubblicazioni\Completamento;
use App\Pubblicazioni\Costanti;
use App\Pubblicazioni\EsportazioneMonitoraggio;
use App\Pubblicazioni\ImpostazioniPubblicazioni;
use App\Pubblicazioni\LimitiCaricamento;
use App\Pubblicazioni\PubblicazioniRepository;
use App\Pubblicazioni\RegistroModifiche;
use App\Pubblicazioni\ServizioPubblicazioni;
use App\Pubblicazioni\VistaRichiesta;

$app_pb = \App\Core\App::per($conn);
$sv = $app_pb->get(ServizioPubblicazioni::class);
if (!$sv->puoGestire($utente_admin)) nega_accesso();

$repo = $app_pb->get(PubblicazioniRepository::class);
$utentiRepo = $app_pb->get(\App\Auth\UtenteRepository::class);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$adesso_pb = $app_pb->get(\App\Core\Orologio::class)->adesso();
$limiti = LimitiCaricamento::dalServer();

/** Rimanda a un'altra pagina (il pannello ha già cominciato a scrivere: si butta via e si usa l'intestazione). */
function pub_vai(string $url): never
{
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    echo '<script>window.location.replace(' . json_encode($url) . ');</script>';
    exit;
}

$dataOra = fn(?string $d) => $d ? date('d/m/Y \a\l\l\e H:i', strtotime($d)) : '';
$nomeUtente = function ($id) use ($utentiRepo): string {
    $u = (int)$id > 0 ? $utentiRepo->perId((int)$id) : null;
    $n = $u ? trim(trim((string)($u['nome'] ?? '')) . ' ' . trim((string)($u['cognome'] ?? ''))) : '';
    return $n !== '' ? $n : ((int)$id > 0 ? 'Utente #' . (int)$id : '');
};
$TONI_STATO = ['ricevuta' => 'attesa', 'lavorazione' => 'lavoro', 'pubblicata' => 'ok', 'respinta' => 'blocco'];
$etichettaStato = fn(string $s) => pn_stato(Costanti::STATI[$s] ?? $s, $TONI_STATO[$s] ?? 'info');

$id = (int)($_GET['id'] ?? 0);
$vistaGet = (string)($_GET['vista'] ?? '');
$vista = in_array($vistaGet, ['arrivate', 'monitoraggio', 'impostazioni'], true) ? $vistaGet : 'arrivate';
$nuova = isset($_GET['nuova']);
$url_lista = fn(string $v = 'arrivate') => 'pubblicazioni.php?vista=' . $v;
$url_scheda = fn(int $i, string $v = '') => 'pubblicazioni.php?id=' . $i . ($v !== '' ? '&vista=' . $v : '');

$errori_nuova = [];
$errori_col = [];
$e_post = false; // la scheda riparte da quanto scritto (dopo un errore)

// ── Azioni (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($limiti->invioTroppoGrande($_SERVER, $_POST, $_FILES)) {
        // PHP ha scartato tutto perché l'invio supera post_max_size: nessun dato da controllare
        flash_set($limiti->messaggioTroppoGrande(), 'danger');
        pub_vai($nuova ? 'pubblicazioni.php?nuova=1' : $url_lista('monitoraggio'));
    }
    csrf_verify($_POST['csrf_token'] ?? '');
    $azione = (string)($_POST['azione'] ?? '');
    $pid = (int)($_POST['id'] ?? 0);
    $da_scheda = ($_POST['da'] ?? '') === 'scheda';
    $dopo = fn() => $da_scheda && $pid > 0 ? $url_scheda($pid, in_array($_POST['vista'] ?? '', ['arrivate', 'monitoraggio'], true) ? (string)$_POST['vista'] : '') : $url_lista('arrivate');

    if ($azione === 'invia_excel') {
        $esito = $sv->inviaAlMonitoraggio($pid, $utente_admin);
        flash_set($esito->messaggio, $esito->riuscito ? 'success' : 'danger');
        pub_vai($dopo());
    }
    if ($azione === 'togli_excel') {
        $esito = $sv->togliDalMonitoraggio($pid, $utente_admin);
        flash_set($esito->messaggio, $esito->riuscito ? 'success' : 'danger');
        pub_vai($da_scheda ? $url_scheda($pid, 'arrivate') : $url_lista('monitoraggio'));
    }
    if ($azione === 'elimina') {
        $esito = $sv->elimina($pid, $utente_admin);
        flash_set($esito->messaggio, $esito->riuscito ? 'warning' : 'danger');
        pub_vai($url_lista('arrivate'));
    }
    if ($azione === 'salva' && $pid > 0) {
        // Solo le colonne degli operatori e lo stato: tutto il resto del POST si ignora
        $dati = array_intersect_key($_POST, array_flip(array_merge(ColonneMonitoraggio::colonneOperatori(), ['stato'])));
        $errori_col = ServizioPubblicazioni::erroriColonne($dati);
        $p_att = $repo->perId($pid);
        if ($p_att && (int)$p_att['nel_monitoraggio'] !== 1) $errori_col = []; // fuori dal file Excel le colonne non si salvano: nessun controllo
        if ($errori_col) {
            $e_post = true; // la scheda si riapre con quanto scritto e l'errore accanto al campo
            $id = $pid;
        } else {
            $esito = $sv->aggiornaOperatori($pid, $dati, $utente_admin);
            flash_set($esito->messaggio, $esito->riuscito ? 'success' : 'danger');
            pub_vai($url_scheda($pid, in_array($_POST['vista'] ?? '', ['arrivate', 'monitoraggio'], true) ? (string)$_POST['vista'] : ''));
        }
    }
    if ($azione === 'nuova') {
        $file = [
            'allegati' => AllegatiPubblicazioni::elenco($_FILES['allegati'] ?? []),
            'immagini' => AllegatiPubblicazioni::elenco($_FILES['immagini'] ?? []),
        ];
        $esito = $sv->creaManuale($_POST, $utente_admin, $file);
        if ($esito['id'] > 0) {
            flash_set('Riga aggiunta: è già nel file Excel.', 'success');
            pub_vai($url_scheda($esito['id'], 'monitoraggio'));
        }
        $errori_nuova = $esito['errori'];
        $nuova = true;
        $id = 0;
    }
    if ($azione === 'impostazioni') {
        $err_imp = $app_pb->get(ImpostazioniPubblicazioni::class)->salva((string)($_POST['indirizzi'] ?? ''));
        if ($err_imp === null) {
            registra_log_audit($conn, 'Richieste di pubblicazione: indirizzi delle richieste modificati', ['Indirizzi' => (string)($_POST['indirizzi'] ?? '')]);
            flash_set('Indirizzi salvati.', 'success');
            pub_vai('pubblicazioni.php?vista=impostazioni');
        }
        // errore: la pagina si ridisegna con quello che è stato scritto, così non si perde
        $vista = 'impostazioni';
        $id = 0;
        $testo_indirizzi = (string)($_POST['indirizzi'] ?? '');
        $e_post = true;
    }
    if (!$e_post && !$nuova) pub_vai($url_lista('arrivate'));
}

// ── Filtri della tabella di monitoraggio (anche per lo scaricamento) ──
$dataValida = fn(string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)) ? $d : '';
$statoGet = (string)($_GET['stato'] ?? '');
$filtri = [
    'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
    'stato' => isset(Costanti::STATI[$statoGet]) ? $statoGet : '',
    'dal' => $dataValida((string)($_GET['dal'] ?? '')),
    'al' => $dataValida((string)($_GET['al'] ?? '')),
];
$filtri_attivi = (bool)array_filter($filtri, fn($v) => $v !== '');
$filtriRepo = ['monitoraggio' => 'si', 'testo' => $filtri['q'], 'stato' => $filtri['stato'], 'dal' => $filtri['dal'], 'al' => $filtri['al']];

// ── Scarica Excel, PDF o CSV della tabella di monitoraggio (con i filtri scelti) ──
if (isset($_GET['scarica']) && $id === 0 && !$nuova) {
    $formato = (string)$_GET['scarica'];
    $qs_lista = 'pubblicazioni.php?' . http_build_query(['vista' => 'monitoraggio'] + array_filter($filtri, fn($v) => $v !== ''));
    if (!isset(\App\Infrastructure\Documenti\TabellaEsportabile::FORMATI[$formato])) {
        flash_set('Formato non disponibile.', 'danger');
        pub_vai($qs_lista);
    }
    if ($formato === 'xlsx' && !class_exists(\ZipArchive::class)) {
        flash_set('Su questo server il file Excel non si può creare: scarica il CSV, che si apre con Excel.', 'warning');
        pub_vai($qs_lista);
    }
    $righe_exp = $repo->elenco($filtriRepo);
    // Solo nel CSV: l'apostrofo davanti alle celle che sembrano formule le tiene come testo in Excel (l'xlsx scrive ogni cella come testo, il PDF non ha formule)
    $tab = EsportazioneMonitoraggio::tabella($righe_exp, $formato === 'csv');
    $sotto = count($tab['righe']) . ' righe · stampato il ' . date('d/m/Y');
    if ($filtri_attivi) {
        $sotto .= ' · filtri: ' . implode(', ', array_filter([
            $filtri['q'] !== '' ? 'testo «' . $filtri['q'] . '»' : '',
            $filtri['stato'] !== '' ? 'stato ' . (Costanti::STATI[$filtri['stato']] ?? '') : '',
            $filtri['dal'] !== '' ? 'evento dal ' . date('d/m/Y', strtotime($filtri['dal'])) : '',
            $filtri['al'] !== '' ? 'evento al ' . date('d/m/Y', strtotime($filtri['al'])) : '',
        ]));
    }
    // Il PDF ha 24 colonne: più spazio ai testi, meno alle date e alle caselle SI/NO
    $pesi = ['data_evento' => 1.1, 'richiedente' => 1.5, 'titolo' => 2.5, 'sottotitolo' => 2.5, 'descrizione' => 3.5, 'modalita' => 1.2, 'stato' => 1.2,
        'f_pubblicazione_social' => 0.8, 'o_piattaforma_pe' => 0.8, 'u_produzione_locandina' => 0.8, 'v_n_partecipanti' => 0.9];
    $larghezze = array_map(fn($k) => $pesi[$k] ?? 1.4, ColonneMonitoraggio::chiavi());
    registra_log_audit($conn, 'Richieste di pubblicazione: tabella di monitoraggio scaricata', ['Formato' => $formato, 'Righe' => count($tab['righe']), 'Filtri' => $filtri_attivi ? 'sì' : 'no']);
    while (ob_get_level() > 0) ob_end_clean();
    (new \App\Infrastructure\Documenti\TabellaEsportabile('Tabella di monitoraggio', $tab['intestazioni'], $tab['righe'], $sotto, $larghezze))
        ->scarica($formato, 'Tabella_di_monitoraggio_' . date('Y-m-d'));
    http_response_code(400);
    exit('Formato non disponibile.');
}

$p = $id > 0 ? $repo->perId($id) : null;
if ($id > 0 && !$p) {
    flash_set('Richiesta non trovata: forse è stata eliminata.', 'danger');
    pub_vai($url_lista('arrivate'));
}
?>
<style>
.pb-num { font-size: 1.6rem; font-weight: 800; line-height: 1.1; }
.pb-box { border: 1px solid var(--bs-border-color, #e2e8f0); border-radius: 12px; padding: .75rem 1rem; height: 100%; }
.pb-box.avviso .pb-num { color: #b45309; }
.pb-fascia { border: 1px solid #a5f3fc; background: #ecfeff; border-radius: 12px; padding: .75rem 1rem; }
.pb-fascia.dentro { border-color: #86efac; background: #f0fdf4; }
[data-bs-theme="dark"] .pb-fascia { background: #12303a; border-color: #155e75; }
[data-bs-theme="dark"] .pb-fascia.dentro { background: #12311f; border-color: #166534; }
.pb-gruppo { border: 1px solid var(--bs-border-color, #e2e8f0); border-radius: 12px; padding: .75rem 1rem; margin-bottom: .9rem; }
.pb-gruppo-titolo { font-weight: 700; font-size: .85rem; text-transform: uppercase; letter-spacing: .04em; color: #475569; margin-bottom: .5rem; }
.pb-etichetta { display: inline-block; border-radius: 6px; padding: 2px 8px; font-size: .8rem; font-weight: 700; margin-bottom: .25rem; max-width: 100%; }
.pb-errore { color: #b91c1c; font-weight: 700; font-size: .85rem; margin-top: .2rem; }
.pb-dl dt { color: #475569; font-weight: 600; font-size: .85rem; }
.pb-dl dd { overflow-wrap: anywhere; margin-bottom: .6rem; }
.pb-titolo-cella { max-width: 340px; }
</style>

<?php if ($vista === 'impostazioni' && $id === 0 && !$nuova): ?>
    <?php
    $imp = $app_pb->get(ImpostazioniPubblicazioni::class);
    $indirizzi = $imp->indirizzi();
    echo pn_testata('Impostazioni delle richieste', 'fa-gear', 'A chi arrivano le richieste di pubblicazione dei docenti.',
        '<a class="btn btn-outline-secondary btn-sm" href="' . $h($url_lista('arrivate')) . '"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Torna alle richieste</a>');
    ?>
    <div class="card border-0 shadow-sm"><div class="card-body">
        <form method="POST" action="pubblicazioni.php" class="m-0"><?php csrf_field(); ?>
            <input type="hidden" name="azione" value="impostazioni">
            <label class="form-label fw-bold" for="pbInd">Indirizzo (o indirizzi) che ricevono le richieste</label>
            <p class="small text-secondary mb-2">Quando un docente invia una richiesta arriva un'email a questi indirizzi, con tutto il testo e il collegamento alla scheda. Per più indirizzi separali con una virgola. Se ne indichi uno sbagliato non viene salvato niente.</p>
            <?php if (!empty($err_imp)): ?><div class="alert alert-danger py-2 small" role="alert"><?php echo $h($err_imp); ?></div><?php endif; ?>
            <input type="text" class="form-control mb-1<?php echo !empty($err_imp) ? ' is-invalid' : ''; ?>" id="pbInd" name="indirizzi" maxlength="500" value="<?php echo $h($testo_indirizzi ?? implode(', ', $indirizzi)); ?>" required>
            <div class="small text-secondary mb-3">Esempio: <code>comunicazione.dibest@unical.it, altra.persona@unical.it</code>. Valore attuale: <strong><?php echo $indirizzi ? $h(implode(', ', $indirizzi)) : 'nessuno'; ?></strong>.</div>
            <button type="submit" class="btn btn-primary fw-bold"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva</button>
        </form>
    </div></div>

<?php elseif ($nuova): ?>
    <?php
    $v = fn(string $k) => $h($_POST[$k] ?? '');
    $spunta = fn(string $k) => !empty($_POST[$k]) ? ' checked' : '';
    $err_n = fn(string $k) => isset($errori_nuova[$k]) ? '<div class="pb-errore" role="alert">' . $h($errori_nuova[$k]) . '</div>' : '';
    echo pn_testata('Nuova riga a mano', 'fa-plus', 'Per un\'iniziativa arrivata per altre vie: la riga entra subito nel file Excel. Servono solo data dell\'evento, richiedente e titolo.',
        '<a class="btn btn-outline-secondary btn-sm" href="' . $h($url_lista('monitoraggio')) . '"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Torna alla tabella</a>');
    ?>
    <?php if ($errori_nuova): ?>
        <div class="alert alert-danger" role="alert"><strong>Controlla i dati:</strong><ul class="mb-0"><?php foreach ($errori_nuova as $m_e): ?><li><?php echo $h($m_e); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="POST" action="pubblicazioni.php?nuova=1" enctype="multipart/form-data" class="card border-0 shadow-sm"><div class="card-body">
        <?php csrf_field(); ?><input type="hidden" name="azione" value="nuova">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label fw-bold" for="pnData">Data dell'evento <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="pnData" name="data_evento" value="<?php echo $v('data_evento'); ?>" required><?php echo $err_n('data_evento'); ?></div>
            <div class="col-md-8"><label class="form-label fw-bold" for="pnRic">Richiedente (Cognome Nome) <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="pnRic" name="richiedente" maxlength="200" value="<?php echo $v('richiedente'); ?>" required><?php echo $err_n('richiedente'); ?></div>
            <div class="col-12"><label class="form-label fw-bold" for="pnTit">Titolo <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="pnTit" name="titolo" maxlength="<?php echo (int)Costanti::MAX_TITOLO; ?>" value="<?php echo $v('titolo'); ?>" required><?php echo $err_n('titolo'); ?></div>
            <div class="col-12"><label class="form-label fw-bold" for="pnSot">Sottotitolo <span class="small fw-normal text-secondary">(massimo <?php echo (int)Costanti::MAX_SOTTOTITOLO; ?> caratteri)</span></label>
                <input type="text" class="form-control" id="pnSot" name="sottotitolo" value="<?php echo $v('sottotitolo'); ?>" data-max="<?php echo (int)Costanti::MAX_SOTTOTITOLO; ?>" data-conta="pnSotN">
                <div class="small text-secondary" id="pnSotN"></div><?php echo $err_n('sottotitolo'); ?></div>
            <div class="col-12"><label class="form-label fw-bold" for="pnDes">Breve descrizione <span class="small fw-normal text-secondary">(testo semplice, massimo <?php echo (int)Costanti::MAX_DESCRIZIONE; ?> caratteri)</span></label>
                <textarea class="form-control" id="pnDes" name="descrizione" rows="3" data-max="<?php echo (int)Costanti::MAX_DESCRIZIONE; ?>" data-conta="pnDesN"><?php echo $v('descrizione'); ?></textarea>
                <div class="small text-secondary" id="pnDesN"></div><?php echo $err_n('descrizione'); ?></div>
            <div class="col-md-4"><label class="form-label fw-bold" for="pnMod">Modalità di svolgimento</label>
                <select class="form-select" id="pnMod" name="modalita"><option value="">—</option>
                    <?php foreach (Costanti::MODALITA as $k_m => $l_m): ?><option value="<?php echo $h($k_m); ?>"<?php echo ($_POST['modalita'] ?? '') === $k_m ? ' selected' : ''; ?>><?php echo $h($l_m); ?></option><?php endforeach; ?>
                </select><?php echo $err_n('modalita'); ?></div>
            <div class="col-md-8"><label class="form-label fw-bold" for="pnUrl">URL della pagina</label>
                <input type="url" class="form-control" id="pnUrl" name="url_docente" maxlength="1000" placeholder="https://…" value="<?php echo $v('url_docente'); ?>"><?php echo $err_n('url_docente'); ?></div>
            <div class="col-12"><label class="form-label fw-bold" for="pnAlt">URL/Link ad altre pagine <span class="small fw-normal text-secondary">(uno per riga)</span></label>
                <textarea class="form-control" id="pnAlt" name="altri_link_docente" rows="2"><?php echo $v('altri_link_docente'); ?></textarea><?php echo $err_n('altri_link_docente'); ?></div>
            <div class="col-12"><span class="form-label fw-bold d-block">Evento rivolto a</span>
                <?php foreach (Costanti::DESTINATARI as $k_d => [$l_d]): ?>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="pn_<?php echo $h($k_d); ?>" name="<?php echo $h($k_d); ?>" value="1"<?php echo $spunta($k_d); ?>><label class="form-check-label" for="pn_<?php echo $h($k_d); ?>"><?php echo $h($l_d); ?></label></div>
                <?php endforeach; ?>
                <div class="small text-secondary">Le caselle spuntate scrivono «SI» nelle colonne dei destinatari della tabella.</div></div>
            <div class="col-md-6"><label class="form-label fw-bold" for="pnTag">Tag <span class="small fw-normal text-secondary">(separati da virgola)</span></label>
                <input type="text" class="form-control" id="pnTag" name="tag" maxlength="500" value="<?php echo $v('tag'); ?>"><?php echo $err_n('tag'); ?></div>
            <div class="col-md-6"><label class="form-label fw-bold" for="pnIni">Data inizio pubblicazione</label>
                <input type="date" class="form-control" id="pnIni" name="data_inizio_pubblicazione" value="<?php echo $v('data_inizio_pubblicazione'); ?>"><?php echo $err_n('data_inizio_pubblicazione'); ?></div>
            <div class="col-12"><label class="form-label fw-bold" for="pnNote">Note</label>
                <textarea class="form-control" id="pnNote" name="note" rows="2"><?php echo $v('note'); ?></textarea><?php echo $err_n('note'); ?></div>
            <div class="col-md-6"><label class="form-label fw-bold" for="pnAll">Allegati da pubblicare <span class="small fw-normal text-secondary">(PDF, JPG o PNG, facoltativi)</span></label>
                <input type="file" class="form-control" id="pnAll" name="allegati[]" multiple accept=".pdf,.jpg,.jpeg,.png"><?php echo $err_n('allegati'); ?></div>
            <div class="col-md-6"><label class="form-label fw-bold" for="pnImm">Ulteriori immagini <span class="small fw-normal text-secondary">(JPG o PNG, facoltative)</span></label>
                <input type="file" class="form-control" id="pnImm" name="immagini[]" multiple accept=".jpg,.jpeg,.png"><?php echo $err_n('immagini'); ?></div>
            <div class="col-12 small text-secondary"><?php echo $h($limiti->frase()); ?></div>
            <div class="col-md-4"><label class="form-label fw-bold" for="pnSta">Stato</label>
                <select class="form-select" id="pnSta" name="stato">
                    <?php foreach (Costanti::STATI as $k_s => $l_s): if ($k_s === 'respinta') continue; ?><option value="<?php echo $h($k_s); ?>"<?php echo ($_POST['stato'] ?? 'ricevuta') === $k_s ? ' selected' : ''; ?>><?php echo $h($l_s); ?></option><?php endforeach; ?>
                </select></div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary fw-bold"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi al file Excel</button>
            <a class="btn btn-outline-secondary" href="<?php echo $h($url_lista('monitoraggio')); ?>">Annulla</a>
        </div>
    </div></form>
    <script>
    // Contatori dei caratteri: «12 / 75», rosso oltre il limite
    document.querySelectorAll('[data-conta]').forEach(function (c) {
        var out = document.getElementById(c.dataset.conta), max = parseInt(c.dataset.max, 10);
        function aggiorna() { var n = c.value.trim().length; out.textContent = n + ' / ' + max; out.classList.toggle('text-danger', n > max); out.classList.toggle('fw-bold', n > max); }
        c.addEventListener('input', aggiorna); aggiorna();
    });
    </script>

<?php elseif ($p): ?>
    <?php
    // ═════ Scheda di una richiesta ═════
    $dentro = (int)$p['nel_monitoraggio'] === 1;
    $manuale = ($p['origine'] ?? '') === 'manuale';
    $allegati = $repo->allegati((int)$p['id']);
    $registro = $app_pb->get(RegistroModifiche::class)->perRichiesta((int)$p['id']);
    $vista_s = $dentro ? 'monitoraggio' : 'arrivate'; // la scheda in cui sta ora la riga: lì si torna
    $valoreCol = fn(string $k) => $e_post ? (string)($_POST[$k] ?? '') : (string)($p[$k] ?? '');
    $statoScelto = $e_post && isset(Costanti::STATI[(string)($_POST['stato'] ?? '')]) ? (string)$_POST['stato'] : (string)$p['stato'];
    $creatoDa = '';
    foreach (array_reverse($registro) as $voce) { if ($manuale && $voce['titolo'] === 'Riga aggiunta a mano') { $creatoDa = $voce['chi'] . ' il ' . $dataOra($voce['quando']); break; } }
    $nM = Completamento::numeroMancanti($p);

    echo pn_indietro($url_lista($vista_s), $vista_s === 'monitoraggio' ? 'Torna alla tabella di monitoraggio' : 'Torna alle richieste arrivate');
    echo pn_testata((string)$p['titolo'], 'fa-bullhorn',
        ($manuale ? 'Riga aggiunta a mano' . ($creatoDa !== '' ? ' da ' . $creatoDa : '') . '. ' : 'Richiesta di ' . $p['richiedente'] . ', inviata il ' . $dataOra($p['inviata_il']) . '. ')
        . 'Data dell\'evento: ' . (EsportazioneMonitoraggio::data((string)$p['data_evento']) ?: 'non indicata') . '.',
        $etichettaStato((string)$p['stato']));
    ?>

    <!-- Fascia: nel file Excel o no -->
    <div class="pb-fascia<?php echo $dentro ? ' dentro' : ''; ?> d-flex flex-wrap align-items-center gap-2 mb-3">
        <?php if ($dentro): ?>
            <div class="flex-grow-1"><i class="fa fa-table-list me-1 text-success" aria-hidden="true"></i><strong>Nel file Excel</strong> dal <?php echo $h(EsportazioneMonitoraggio::data((string)$p['monitoraggio_dal']) ?: '—'); ?><?php echo !empty($p['monitoraggio_da']) ? ' (da ' . $h($nomeUtente($p['monitoraggio_da'])) . ')' : ''; ?>. <span class="small text-secondary"><?php echo $h(Completamento::testo($nM)); ?>.</span></div>
            <form method="POST" action="pubblicazioni.php" class="m-0"><?php csrf_field(); ?>
                <input type="hidden" name="azione" value="togli_excel"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>"><input type="hidden" name="da" value="scheda">
                <button type="submit" class="btn btn-outline-secondary btn-sm fw-bold" data-confirm="Togliere la riga dal file Excel? Le colonne che hai compilato restano salvate: se la rimetti le ritrovi."><i class="fa fa-arrow-right-from-bracket me-1" aria-hidden="true"></i>Togli dal file Excel</button>
            </form>
        <?php else: ?>
            <div class="flex-grow-1"><i class="fa fa-inbox me-1 text-info" aria-hidden="true"></i><strong>Non ancora nel file Excel</strong><?php echo $p['stato'] === 'respinta' ? ' <span class="small text-secondary">· richiesta respinta: non si può inviare al file Excel.</span>' : ''; ?></div>
            <form method="POST" action="pubblicazioni.php" class="m-0"><?php csrf_field(); ?>
                <input type="hidden" name="azione" value="invia_excel"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>"><input type="hidden" name="da" value="scheda">
                <button type="submit" class="btn btn-primary btn-sm fw-bold"<?php echo $p['stato'] === 'respinta' ? ' disabled title="Una richiesta respinta non si può inviare al file Excel."' : ' data-confirm="Inviare la richiesta al file Excel? Le colonne dell\'indirizzo (D, E) e dei destinatari si precompilano con quello che ha scritto il docente."'; ?>><i class="fa fa-table-list me-1" aria-hidden="true"></i>Invia al file Excel</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Quello che ha inviato il docente -->
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <h2 class="h6 fw-bold mb-0 flex-grow-1"><?php echo $manuale ? 'Dati della riga' : 'Quello che ha inviato il docente'; ?></h2>
            <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" id="pbCopia" data-testo="<?php echo $h(VistaRichiesta::testoDaCopiare($p)); ?>"><i class="fa fa-copy me-1" aria-hidden="true"></i><span>Copia il testo</span></button>
        </div>
        <p class="small text-secondary">In sola lettura. «Copia il testo» mette negli appunti titolo, sottotitolo e descrizione senza formattazione, pronti da incollare.</p>
        <dl class="row pb-dl mb-0">
            <?php foreach (VistaRichiesta::righe($p, $allegati) as [$et, $val]): ?>
                <dt class="col-sm-3"><?php echo $h($et); ?></dt><dd class="col-sm-9"><?php echo $val; /* già «escapato» da VistaRichiesta */ ?></dd>
            <?php endforeach; ?>
        </dl>
    </div></div>

    <!-- Colonne degli operatori e stato -->
    <form method="POST" action="pubblicazioni.php?id=<?php echo (int)$p['id']; ?>&amp;vista=<?php echo $h($vista_s); ?>" class="card border-0 shadow-sm mb-3" id="pbForm"><div class="card-body">
        <?php csrf_field(); ?>
        <input type="hidden" name="azione" value="salva"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>"><input type="hidden" name="vista" value="<?php echo $h($vista_s); ?>">
        <h2 class="h6 fw-bold">Tabella di monitoraggio</h2>
        <?php if ($errori_col): ?>
            <div class="alert alert-danger py-2" role="alert">Controlla i campi indicati: non è stato salvato niente. Quello che avevi scritto è ancora qui.</div>
        <?php endif; ?>

        <div class="row g-2 align-items-end mb-3">
            <div class="col-sm-5 col-md-4">
                <label class="form-label fw-bold small mb-1" for="pbStato">Stato della richiesta</label>
                <select class="form-select" id="pbStato" name="stato" data-nel-excel="<?php echo $dentro ? '1' : '0'; ?>" data-stato-attuale="<?php echo $h($p['stato']); ?>">
                    <?php foreach (Costanti::STATI as $k_s => $l_s): ?><option value="<?php echo $h($k_s); ?>"<?php echo $statoScelto === $k_s ? ' selected' : ''; ?>><?php echo $h($l_s); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-7 col-md-8 small text-secondary">Lo stato si può cambiare sempre. Passando a «Respinta» una riga che è nel file Excel, la riga esce dal file.</div>
        </div>

        <?php if (!$dentro): ?>
            <p class="text-secondary mb-3"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Le colonne della tabella di monitoraggio si compilano dopo aver premuto Invia al file Excel.</p>
        <?php else: ?>
            <?php
            $docenteScrive = ['d_url_pubblicazione' => (string)($p['url_docente'] ?? ''), 'e_url_altre_pagine' => (string)($p['altri_link_docente'] ?? '')];
            $norm = fn(string $s) => trim((string)preg_replace('/\s+/u', ' ', $s));
            foreach (Completamento::gruppiPerPersona() as $g): ?>
                <div class="pb-gruppo">
                    <div class="pb-gruppo-titolo">A cura di <?php echo $h($g['a_cura_di']); ?></div>
                    <div class="row g-3">
                    <?php foreach ($g['colonne'] as $c):
                        $k = $c['chiave']; $val = $valoreCol($k); $campoId = 'pb_' . $k;
                        $largo = in_array($k, ['d_url_pubblicazione', 'e_url_altre_pagine'], true);
                    ?>
                        <div class="<?php echo $largo ? 'col-12' : 'col-md-6'; ?>">
                            <label class="pb-etichetta" for="<?php echo $h($campoId); ?>" style="background: <?php echo $h($c['colore']); ?>; color: <?php echo $h(colore_testo_su($c['colore'])); ?>;"><?php echo $h($c['lettera'] . ' · ' . ColonneMonitoraggio::etichetta($k)); ?></label>
                            <?php if ($c['tipo'] === 'si_no'): ?>
                                <select class="form-select" id="<?php echo $h($campoId); ?>" name="<?php echo $h($k); ?>">
                                    <option value="">—</option>
                                    <?php foreach (['SI', 'NO'] as $o_v): ?><option value="<?php echo $o_v; ?>"<?php echo mb_strtoupper(trim($val)) === $o_v ? ' selected' : ''; ?>><?php echo $o_v; ?></option><?php endforeach; ?>
                                    <?php if (trim($val) !== '' && !in_array(mb_strtoupper(trim($val)), ['SI', 'NO'], true)): ?><option value="<?php echo $h($val); ?>" selected><?php echo $h($val); ?></option><?php endif; ?>
                                </select>
                            <?php elseif ($c['tipo'] === 'numero'): ?>
                                <input type="number" min="0" step="1" class="form-control" id="<?php echo $h($campoId); ?>" name="<?php echo $h($k); ?>" value="<?php echo $h($val); ?>">
                            <?php elseif ($largo): ?>
                                <textarea class="form-control" rows="3" maxlength="<?php echo (int)Costanti::MAX_COLONNA; ?>" id="<?php echo $h($campoId); ?>" name="<?php echo $h($k); ?>"><?php echo $h($val); ?></textarea>
                            <?php else: ?>
                                <input type="text" class="form-control" maxlength="<?php echo (int)Costanti::MAX_COLONNA; ?>" id="<?php echo $h($campoId); ?>" name="<?php echo $h($k); ?>" value="<?php echo $h($val); ?>">
                            <?php endif; ?>
                            <?php if (isset($errori_col[$k])): ?><div class="pb-errore" role="alert"><?php echo $h($errori_col[$k]); ?></div><?php endif; ?>
                            <?php if (isset($docenteScrive[$k]) && $norm($docenteScrive[$k]) !== '' && $norm((string)($p[$k] ?? '')) !== $norm($docenteScrive[$k])): ?>
                                <div class="small text-secondary mt-1">scritto dal docente: <?php echo nl2br($h(trim($docenteScrive[$k]))); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
            <button type="submit" class="btn btn-primary fw-bold" id="pbSalva"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva</button>
            <span class="small text-secondary">
                <?php if (!empty($p['modificata_il'])): ?>Ultima modifica: <strong><?php echo $h($nomeUtente($p['modificata_da']) ?: 'un operatore'); ?></strong>, il <?php echo $h($dataOra($p['modificata_il'])); ?>.
                <?php else: ?>Nessuna modifica fatta dagli operatori.<?php endif; ?>
            </span>
        </div>
    </div></form>

    <!-- Registro delle modifiche -->
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h2 class="h6 fw-bold">Registro delle modifiche</h2>
        <?php if (!$registro): ?>
            <p class="small text-secondary mb-0">Ancora nessuna modifica da parte degli operatori.</p>
        <?php else: ?>
            <ul class="list-unstyled small mb-0">
            <?php foreach ($registro as $voce): ?>
                <li class="mb-2"><strong><?php echo $h($dataOra($voce['quando'])); ?></strong> · <?php echo $h($voce['chi']); ?> · <?php echo $h($voce['titolo']); ?>
                    <?php if ($voce['righe']): ?><ul class="mb-0 ps-3"><?php foreach ($voce['righe'] as $r_v): ?><li><?php echo $h($r_v); ?></li><?php endforeach; ?></ul><?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div></div>

    <!-- Elimina -->
    <div class="card border-danger border-opacity-25 shadow-sm"><div class="card-body">
        <h2 class="h6 fw-bold text-danger">Elimina</h2>
        <p class="small text-secondary">Cancella la richiesta<?php echo $dentro ? ' (e la riga del file Excel)' : ''; ?>, con gli allegati e le immagini caricati. Non si può annullare.</p>
        <form method="POST" action="pubblicazioni.php" class="m-0"><?php csrf_field(); ?>
            <input type="hidden" name="azione" value="elimina"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm fw-bold" data-confirm="Eliminare «<?php echo $h($p['titolo']); ?>» con tutti i suoi file? Non si può annullare."><i class="fa fa-trash me-1" aria-hidden="true"></i>Elimina la richiesta</button>
        </form>
    </div></div>

    <script>
    // «Copia il testo»: titolo, sottotitolo e descrizione negli appunti
    (function () {
        var b = document.getElementById('pbCopia');
        if (!b) return;
        b.addEventListener('click', function () {
            var t = b.dataset.testo, etichetta = b.querySelector('span');
            function fatto() { etichetta.textContent = 'Copiato'; setTimeout(function () { etichetta.textContent = 'Copia il testo'; }, 2000); }
            function ripiego() { var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); try { document.execCommand('copy'); fatto(); } catch (e) {} a.remove(); }
            if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(fatto, ripiego); else ripiego();
        });
        // Passare a «Respinta» una riga nel file Excel: conferma (la riga esce dal file)
        var sel = document.getElementById('pbStato'), salva = document.getElementById('pbSalva');
        function conferma() {
            if (sel.value === 'respinta' && sel.dataset.statoAttuale !== 'respinta' && sel.dataset.nelExcel === '1') salva.setAttribute('data-confirm', 'Segnare la richiesta come respinta? La riga uscirà dal file Excel (le colonne compilate restano salvate).');
            else salva.removeAttribute('data-confirm');
        }
        sel.addEventListener('change', conferma); conferma();
    })();
    </script>

<?php else: ?>
    <?php
    // ═════ Elenco: richieste arrivate / tabella di monitoraggio ═════
    $tutte = $repo->elenco();
    $arrivate = array_values(array_filter($tutte, fn($r) => (int)$r['nel_monitoraggio'] === 0));
    $nel_file = array_values(array_filter($tutte, fn($r) => (int)$r['nel_monitoraggio'] === 1));
    $anno = $adesso_pb->format('Y');
    $conta_stato = fn(string $s) => count(array_filter($arrivate, fn($r) => $r['stato'] === $s));
    $da_completare = count(array_filter($nel_file, fn($r) => Completamento::numeroMancanti($r) > 0));
    $pubblicate_anno = count(array_filter($nel_file, fn($r) => $r['stato'] === 'pubblicata' && substr((string)$r['data_evento'], 0, 4) === $anno));

    $azioni = '<a class="btn btn-outline-secondary btn-sm" href="pubblicazioni.php?vista=impostazioni"><i class="fa fa-gear me-1" aria-hidden="true"></i>Impostazioni</a>';
    echo pn_testata('Richieste di pubblicazione', 'fa-bullhorn', 'Le richieste dei docenti di pubblicare su sito e social. Una richiesta entra nella tabella di monitoraggio (il file Excel) quando premi «Invia al file Excel».', $azioni);
    echo pn_schede([
        'arrivate' => ['href' => $url_lista('arrivate'), 'testo' => 'Richieste arrivate', 'icona' => 'fa-inbox', 'numero' => count($arrivate), 'titolo' => 'Le richieste non ancora nel file Excel'],
        'monitoraggio' => ['href' => $url_lista('monitoraggio'), 'testo' => 'Tabella di monitoraggio (file Excel)', 'icona' => 'fa-table-list', 'numero' => count($nel_file)],
    ], $vista);
    ?>

    <?php if ($vista === 'monitoraggio'): ?>
        <?php
        $righe = $repo->elenco($filtriRepo);
        $url_mon = 'pubblicazioni.php?' . http_build_query(['vista' => 'monitoraggio'] + array_filter($filtri, fn($v) => $v !== ''));
        ?>
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-4"><div class="pb-box"><div class="pb-num"><?php echo count($nel_file); ?></div><div class="small text-secondary">righe nel file</div></div></div>
            <div class="col-6 col-md-4"><div class="pb-box<?php echo $da_completare > 0 ? ' avviso' : ''; ?>"><div class="pb-num"><?php echo $da_completare; ?></div><div class="small text-secondary">con colonne da completare</div></div></div>
            <div class="col-12 col-md-4"><div class="pb-box"><div class="pb-num"><?php echo $pubblicate_anno; ?></div><div class="small text-secondary">pubblicate nel <?php echo $h($anno); ?> (per data dell'evento)</div></div></div>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-end flex-grow-1 m-0">
                <input type="hidden" name="vista" value="monitoraggio">
                <div><label class="form-label small mb-0" for="pbQ">Cerca nel testo</label><input type="text" class="form-control form-control-sm" id="pbQ" name="q" value="<?php echo $h($filtri['q']); ?>" maxlength="100" placeholder="titolo, richiedente, tag"></div>
                <div><label class="form-label small mb-0" for="pbS">Stato</label>
                    <select class="form-select form-select-sm" id="pbS" name="stato"><option value="">Tutti</option>
                        <?php foreach (Costanti::STATI as $k_s => $l_s): ?><option value="<?php echo $h($k_s); ?>"<?php echo $filtri['stato'] === $k_s ? ' selected' : ''; ?>><?php echo $h($l_s); ?></option><?php endforeach; ?>
                    </select></div>
                <div><label class="form-label small mb-0" for="pbDal">Evento dal</label><input type="date" class="form-control form-control-sm" id="pbDal" name="dal" value="<?php echo $h($filtri['dal']); ?>"></div>
                <div><label class="form-label small mb-0" for="pbAl">al</label><input type="date" class="form-control form-control-sm" id="pbAl" name="al" value="<?php echo $h($filtri['al']); ?>"></div>
                <button class="btn btn-outline-secondary btn-sm" type="submit">Filtra</button>
                <?php if ($filtri_attivi): ?><a class="btn btn-link btn-sm" href="<?php echo $h($url_lista('monitoraggio')); ?>">Azzera</a><?php endif; ?>
            </form>
            <a class="btn btn-primary btn-sm fw-bold" href="pubblicazioni.php?nuova=1"><i class="fa fa-plus me-1" aria-hidden="true"></i>Nuova riga a mano</a>
            <?php echo $righe ? pn_scarica($url_mon, 'Scarica') : ''; ?>
        </div>
        <?php if ($filtri_attivi && $righe): ?><p class="small text-secondary">Lo scaricamento rispetta i filtri scelti (<?php echo count($righe); ?> righe).</p><?php endif; ?>

        <?php if (!$nel_file): ?>
            <div class="card border-0 shadow-sm"><?php echo pn_vuoto('Nessuna riga nel file Excel. Invia una richiesta dalla scheda «Richieste arrivate» oppure aggiungi una riga a mano.', 'fa-table-list'); ?></div>
        <?php elseif (!$righe): ?>
            <div class="card border-0 shadow-sm"><?php echo pn_vuoto('Nessuna riga con questi filtri.', 'fa-filter'); ?></div>
        <?php else: ?>
            <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light"><tr><th>Data evento</th><th>Titolo</th><th>Richiedente</th><th>Modalità</th><th>Stato</th><th>Colonne</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($righe as $r): $n_m = Completamento::numeroMancanti($r); ?>
                    <tr>
                        <td class="text-nowrap"><?php echo $h(EsportazioneMonitoraggio::data((string)$r['data_evento'])); ?></td>
                        <td class="pb-titolo-cella"><a class="fw-bold" href="<?php echo $h($url_scheda((int)$r['id'], 'monitoraggio')); ?>"><?php echo $h($r['titolo']); ?></a><?php echo $r['origine'] === 'manuale' ? ' <span class="badge text-bg-light border">a mano</span>' : ''; ?></td>
                        <td><?php echo $h($r['richiedente']); ?></td>
                        <td><?php echo $h(EsportazioneMonitoraggio::modalita((string)$r['modalita'])); ?></td>
                        <td><?php echo $etichettaStato((string)$r['stato']); ?></td>
                        <td class="text-nowrap"><?php echo $n_m > 0 ? '<span class="text-warning-emphasis fw-bold">' . $h(Completamento::testo($n_m)) . '</span>' : '<span class="text-success">' . $h(Completamento::testo($n_m)) . '</span>'; ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $h($url_scheda((int)$r['id'], 'monitoraggio')); ?>">Apri</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div></div>
        <?php endif; ?>

    <?php else: ?>
        <div class="row g-2 mb-3">
            <div class="col-4"><div class="pb-box<?php echo $conta_stato('ricevuta') > 0 ? ' avviso' : ''; ?>"><div class="pb-num"><?php echo $conta_stato('ricevuta'); ?></div><div class="small text-secondary">ricevute (da guardare)</div></div></div>
            <div class="col-4"><div class="pb-box"><div class="pb-num"><?php echo $conta_stato('lavorazione'); ?></div><div class="small text-secondary">in lavorazione</div></div></div>
            <div class="col-4"><div class="pb-box"><div class="pb-num"><?php echo $conta_stato('respinta'); ?></div><div class="small text-secondary">respinte</div></div></div>
        </div>
        <?php if (!$arrivate): ?>
            <div class="card border-0 shadow-sm"><?php echo pn_vuoto('Nessuna richiesta da guardare: tutte quelle arrivate sono già nel file Excel.', 'fa-inbox'); ?></div>
        <?php else: ?>
            <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light"><tr><th>Inviata il</th><th>Data evento</th><th>Titolo</th><th>Richiedente</th><th>Stato</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($arrivate as $r): $respinta = $r['stato'] === 'respinta'; ?>
                    <tr>
                        <td class="text-nowrap"><?php echo $h($dataOra($r['inviata_il'])); ?></td>
                        <td class="text-nowrap"><?php echo $h(EsportazioneMonitoraggio::data((string)$r['data_evento'])); ?></td>
                        <td class="pb-titolo-cella"><a class="fw-bold" href="<?php echo $h($url_scheda((int)$r['id'], 'arrivate')); ?>"><?php echo $h($r['titolo']); ?></a></td>
                        <td><?php echo $h($r['richiedente']); ?></td>
                        <td><?php echo $etichettaStato((string)$r['stato']); ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $h($url_scheda((int)$r['id'], 'arrivate')); ?>">Apri</a>
                            <form method="POST" action="pubblicazioni.php" class="d-inline m-0"><?php csrf_field(); ?>
                                <input type="hidden" name="azione" value="invia_excel"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-primary py-0"<?php echo $respinta ? ' disabled title="Una richiesta respinta non si può inviare al file Excel."' : ' data-confirm="Inviare «' . $h($r['titolo']) . '» al file Excel? Le colonne dell\'indirizzo (D, E) e dei destinatari si precompilano con quello che ha scritto il docente."'; ?>>Invia al file Excel</button>
                            </form>
                            <?php if ($respinta): ?><div class="text-secondary" style="font-size:.75rem;">Respinta: non si può inviare.</div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div></div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
<?php require_once 'admin_footer.php'; ?>
