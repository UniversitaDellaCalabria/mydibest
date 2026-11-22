<?php
// master_template.php - Motore Grafico Multi-Layout: la pagina pubblica di ogni area (i file <slug>.php e area.php lo includono).
// Qui restano il punto d'ingresso, i controlli (visibilità, manutenzione, azioni) e la preparazione dei dati; il disegno sta in inc/area_pubblica/:
//   funzioni.php (card, modulo di prenotazione, pulsante del turno), strumenti_elenco.php, stile_pagina.php, modale_programma.php,
//   scheda_progetto.php, scheda_evento.php, layout_*.php (uno per layout), barra_gestore.php, coda_pagina.php.
// Le azioni (POST) le decide App\Portale\Area\AzioniAreaPubblica, i messaggi di esito App\Portale\Area\EsitoPrenotazioneArea.
// I parziali si includono nello stesso ambito di questa pagina: ognuno scrive in cima le variabili che usa.
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once 'config.php'; // apre la sessione con i parametri sicuri del cookie
require_once 'functions.php';

sync_sso_user($conn);

// L'attività è già nel programma FSL della scuola (cambia il testo del pulsante)
if (!function_exists('fsl_programma_ha')) {
    function fsl_programma_ha(int $eventoId, int $turnoId = 0): bool {
        try { $p = \App\Core\App::get(\App\Fsl\ProgrammaFsl::class); return $turnoId > 0 ? $p->contieneTurno($turnoId) : $p->contiene($eventoId); } catch (\Throwable $e) { return false; }
    }
}

$current_filename = $page_slug ?? basename($_SERVER['PHP_SELF'], '.php');
$utente_logged = !empty($_SESSION['utente_id']);
$utente_ruolo_id = (int)($_SESSION['utente_ruolo_id'] ?? 5);

// Configurazione dell'area (la prima se lo slug non esiste): src/Iscrizioni/PaginaAreaRepository
$pagina_repo = \App\Core\App::get(\App\Iscrizioni\PaginaAreaRepository::class);
$page_cfg = $pagina_repo->perSlug($current_filename) ?? [];
$p_id = (int)($page_cfg['id'] ?? 1);

// CONTROLLO MANUTENZIONE E GESTORI
$is_visibile = (int)($page_cfg['visibile'] ?? 1);
$is_gestore_o_admin = false;
$is_admin_globale = false;

if ($utente_logged) {
    $sec_roles = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];
    $is_admin_globale = $utente_ruolo_id === 1 || in_array('1', $sec_roles, true);
    $is_gestore_o_admin = $is_admin_globale
        || in_array((int)$_SESSION['utente_id'], ids_gestori_da_campi($page_cfg['gestore_utente_id'] ?? 0, $page_cfg['gestori_utenti_ids'] ?? '', $page_cfg['permessi_gestori_json'] ?? ''), true);
}

$banner_manutenzione_admin = "";
if ($is_visibile === 0) {
    if (!$is_gestore_o_admin) {
        require_once 'header.php';
        echo '<div class="container my-5 text-center ui-maxw-600">
                <div class="card shadow-sm p-5 border-top border-warning border-4 ui-r-12" style="margin-top: 80px;">
                    <i class="fa fa-tools text-warning mb-3 ui-fs-400"></i>
                    <h3 class="fw-bold text-dark">Pagina in Manutenzione</h3>
                    <p class="text-secondary mt-2 fs-5">L\'area è temporaneamente non disponibile.<br>Riprova più tardi.</p>
                </div>
              </div>';
        require_once 'footer.php'; exit;
    } else {
        $banner_manutenzione_admin = "<div class='alert alert-warning alert-dismissible fade show fw-bold text-center my-3 shadow-sm border-warning' style='border-radius: 8px;' role='alert'><i class='fa fa-exclamation-triangle me-2 fs-5 align-middle'></i> <strong>AVVISO AMMINISTRATORE:</strong> Questa pagina è IN MANUTENZIONE / NASCOSTA agli utenti pubblici.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

// Aree "Calendari e risorse": pagina a slot (aule, laboratori, sportelli) invece di eventi e turni
if (tipo_area($page_cfg) === 'calendario') { require __DIR__ . '/calendario_area.php'; exit; }

$logged_u_info = null; $val_nome = ''; $val_cognome = ''; $val_email = ''; $val_matricola = '';
if ($utente_logged) {
    $u_id_logged = (int)$_SESSION['utente_id'];
    $logged_u_info = \App\Core\App::get(\App\Auth\UtenteRepository::class)->perId($u_id_logged);
    if ($logged_u_info) {
        $val_nome = $logged_u_info['nome']; $val_cognome = $logged_u_info['cognome']; $val_email = $logged_u_info['email'];
        $val_matricola = !empty($logged_u_info['matricola_studente']) ? $logged_u_info['matricola_studente'] : (!empty($logged_u_info['matricola_dipendente']) ? $logged_u_info['matricola_dipendente'] : ($logged_u_info['matricola'] ?? ''));
    }
}

// AZIONI DELLA PAGINA: «aggiungi al programma FSL» e «invia prenotazione». Controlli, posti, lista d'attesa ed email stanno nei servizi chiamati da
// App\Portale\Area\AzioniAreaPubblica: la pagina verifica il token, scrive in sessione quello che la classe indica e rimanda la persona dove dice la classe.
if (\App\Portale\Area\AzioniAreaPubblica::haUnAzione($_SERVER['REQUEST_METHOD'], $_POST)) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $azione_area = \App\Core\App::get(\App\Portale\Area\AzioniAreaPubblica::class)->esegui($_POST, $_GET, $_FILES, [
        'pagina' => $page_cfg, 'slug' => $current_filename, 'connesso' => $utente_logged, 'ruolo' => $utente_ruolo_id,
        'ruoliSecondari' => isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [],
        'utenteId' => $utente_logged ? (int)$_SESSION['utente_id'] : null, 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'baseUrl' => url_base_sito(),
    ]);
    foreach ($azione_area['sessione'] as $chiave_sess => $valore_sess) $_SESSION[$chiave_sess] = $valore_sess;
    vai_a($azione_area['rimando']);
}

// MESSAGGI DI ESITO (?status=…, ?programma=…): i testi li compone App\Portale\Area\EsitoPrenotazioneArea
$esito_area = new \App\Portale\Area\EsitoPrenotazioneArea(
    fn(array $cfg_area, string $codice): string => html_istruzioni_convenzione($cfg_area, false, $codice),
    fn(string $codice): string => \App\Core\App::get(\App\Fsl\ServizioDocumentiClasse::class)->avvisoPerCodice($codice)
);
['messaggio' => $messaggio_prenotazione, 'programma' => $modal_programma] = $esito_area->da($_GET, $_SESSION, $page_cfg, url_base_sito());

// =========================================================================
// INIZIALIZZAZIONE VARIABILI DI LAYOUT E GRIGLIA
// =========================================================================
$col_primaria     = colore_valido($page_cfg['colore_primario'] ?? '', '#0056B3');
// Colore dell'area per TESTI su sfondo chiaro: se è troppo chiaro per essere leggibile si usa il grigio scuro
$col_testo_area   = colore_testo_su($col_primaria) === '#FFFFFF' ? $col_primaria : '#1F2937';
$layout_template  = $page_cfg['layout_template'] ?? 'list';
$chiedi_matricola = (int)($page_cfg['chiedi_matricola'] ?? 1);

// Variabili per il rendering della griglia
$num_colonne = (int)($page_cfg['num_colonne'] ?? 2);
if ($num_colonne == 1) { $col_class = "col-12"; } 
elseif ($num_colonne == 3) { $col_class = "col-lg-4 col-md-6"; } 
else { $col_class = "col-md-6"; }

$nomi_ruoli = [-1 => 'Utenti Autenticati', 1 => 'Amministratore', 2 => 'Gestore Prenotazioni', 3 => 'Studenti', 4 => 'Dipendenti', 5 => 'Esterni'];
$etichette_riservato = [-1 => 'Riservato Utenti Autenticati', 1 => 'Riservato Amministratori', 2 => 'Riservato Gestori', 3 => 'Riservato Studenti', 4 => 'Riservato Dipendenti', 5 => 'Riservato Esterni'];

// ESTRAZIONE DATI
$sottocategorie = get_sottocategorie($conn, $p_id);
$categorie_nomi = array_column($sottocategorie, 'nome');

$evento_evidenza = null; 
$all_turni_flat = []; 
$eventi_per_data = []; 
$json_events_calendar = []; 
$sezioni_superiori = [];
$eventi_macro = [];
$eventi_per_categoria = [];
$eventi_by_id = [];

$eventi_area = $pagina_repo->eventiPubblici($p_id);
// Raggruppamenti per i disegni della pagina (turni piatti, calendario, categorie, giorni, evidenza): src/Iscrizioni/ElencoEventiArea
$elenco_area = \App\Iscrizioni\ElencoEventiArea::costruisci($eventi_area ?: [], fn(int $evento_id) => $pagina_repo->turniDelEvento($evento_id), $col_primaria);
$all_turni_flat = $elenco_area['all_turni_flat']; $json_events_calendar = $elenco_area['json_events_calendar']; $eventi_by_id = $elenco_area['eventi_by_id'];
$eventi_per_categoria = $elenco_area['eventi_per_categoria']; $evento_evidenza = $elenco_area['evento_evidenza']; $sezioni_superiori = $elenco_area['sezioni_superiori'];
$eventi_macro = $elenco_area['eventi_macro']; $eventi_per_data = $elenco_area['eventi_per_data'];

// PROGETTI: schede (periodo, referenti, limiti di studenti…) e progetto richiesto con ?progetto=ID
$dettagli_progetti = get_dettagli_progetti($conn, array_keys($eventi_by_id));
foreach ($all_turni_flat as &$t_fl) {
    $d_fl = $dettagli_progetti[(int)$t_fl['evento_id']] ?? [];
    ['min' => $t_fl['limite_studenti_min'], 'max' => $t_fl['limite_studenti_max']] = limiti_partecipanti($d_fl, $t_fl);
    $t_fl['per_scuole'] = (int)($d_fl['per_scuole'] ?? 1) === 1;
    $t_fl['dett_progetto'] = $d_fl ?: null;
}
unset($t_fl);
$progetto_richiesto = (int)($_GET['progetto'] ?? 0);
$progetto_sel = ($progetto_richiesto > 0 && ($eventi_by_id[$progetto_richiesto]['tipo'] ?? '') === 'progetto') ? $eventi_by_id[$progetto_richiesto] : null;
if ($progetto_richiesto > 0 && !$progetto_sel && $messaggio_prenotazione === '') {
    $messaggio_prenotazione = "<div class='alert alert-light border fw-semibold text-center my-4'><i class='fa fa-circle-info me-2'></i>Il progetto richiesto non è più disponibile: ecco l'elenco aggiornato.</div>";
}
// Scheda di un evento (?evento=ID): stessi dati delle card, in una pagina dedicata e condivisibile
$evento_richiesto = (int)($_GET['evento'] ?? 0);
$evento_sel = null;
if ($evento_richiesto > 0 && isset($eventi_by_id[$evento_richiesto])) {
    if (($eventi_by_id[$evento_richiesto]['tipo'] ?? '') === 'progetto') $progetto_sel = $eventi_by_id[$evento_richiesto];
    else $evento_sel = $eventi_by_id[$evento_richiesto];
} elseif ($evento_richiesto > 0 && $messaggio_prenotazione === '') {
    $messaggio_prenotazione = "<div class='alert alert-light border fw-semibold text-center my-4'><i class='fa fa-circle-info me-2'></i>L'evento richiesto non è più disponibile: ecco il programma aggiornato.</div>";
}
// Progetto che rimanda a un'altra pagina (es. OpenLab): la scheda porta direttamente lì
if ($progetto_sel && ($dest_sel = destinazione_progetto($conn, $dettagli_progetti[(int)$progetto_sel['id']] ?? null))) {
    header('Location: ' . $dest_sel['url']); exit;
}
$GLOBALS['evento_scheda_id'] = $evento_sel ? (int)$evento_sel['id'] : 0;
// Link alla scheda dell'evento (card dei layout)
$url_scheda_evento = fn(array $ev) => htmlspecialchars($current_filename) . '.php?' . (($ev['tipo'] ?? '') === 'progetto' ? 'progetto=' : 'evento=') . (int)$ev['id'];

require __DIR__ . '/inc/area_pubblica/strumenti_elenco.php';

// Discipline nell'ordine definito in admin, poi eventuali gruppi senza categoria
$ordine_cat = array_flip($categorie_nomi);
uksort($eventi_per_categoria, fn($a, $b) => ($ordine_cat[$a] ?? PHP_INT_MAX) <=> ($ordine_cat[$b] ?? PHP_INT_MAX));

// Griglia: moduli raggruppati per sezione (ordine definito in admin), quelli senza sezione in fondo.
// L'intestazione compare per le sezioni con un nome; "Altre attività" solo se serve a distinguerle.
$gruppi_griglia = [];
foreach ($eventi_macro as $ev_g) { $gruppi_griglia[$ev_g['nome_sottocategoria'] ?: ''][] = $ev_g; }
uksort($gruppi_griglia, fn($a, $b) => [($a === ''), $ordine_cat[$a] ?? PHP_INT_MAX] <=> [($b === ''), $ordine_cat[$b] ?? PHP_INT_MAX]);
$titolo_gruppo_griglia = function (string $nome) use (&$gruppi_griglia, &$sezioni_superiori): string {
    if ($nome !== '') return $nome;
    return (count($gruppi_griglia) > 1 || !empty($sezioni_superiori)) ? 'Altre attività' : '';
};

$limite_iscrizioni = $page_cfg['limite_iscrizioni'] ?? 'nessuno';
$mie_iscrizioni = $utente_logged ? get_mie_iscrizioni_area($conn, $p_id, (int)$_SESSION['utente_id'], (string)$val_email) : [];

usort($all_turni_flat, function($a, $b) {
    $dateA = ($a['data_turno'] ?: '9999-12-31') . ' ' . $a['orario_inizio'] . ' ' . $a['nome_turno'];
    $dateB = ($b['data_turno'] ?: '9999-12-31') . ' ' . $b['orario_inizio'] . ' ' . $b['nome_turno'];
    return strcmp($dateA, $dateB);
});

require_once __DIR__ . '/inc/area_pubblica/funzioni.php';

// HTML HEADER
require_once 'header.php'; 
require __DIR__ . '/inc/area_pubblica/stile_pagina.php';
?>

<!-- BANNER MESSAGGI E TASTO CALENDARIO RAPIDO (Per tutti tranne Calendar Layout) -->
<?php if ($layout_template !== 'calendar' || $progetto_sel || $evento_sel): ?>
<div class="ui-larg-cont container mt-3">
    <?php echo $banner_manutenzione_admin; ?>
    <?php echo $messaggio_prenotazione; ?>
        
</div>
<?php endif; ?>
<?php if ($modal_programma) require __DIR__ . '/inc/area_pubblica/modale_programma.php'; ?>

<!-- ======================================================= -->
<!-- SCHEDA DI UN PROGETTO (?progetto=ID), con qualsiasi layout dell'area -->
<!-- ======================================================= -->
<?php if ($progetto_sel): require __DIR__ . '/inc/area_pubblica/scheda_progetto.php'; ?>

<!-- ======================================================= -->
<!-- SCHEDA DI UN EVENTO (?evento=ID), con qualsiasi layout dell'area -->
<!-- ======================================================= -->
<?php elseif ($evento_sel): require __DIR__ . '/inc/area_pubblica/scheda_evento.php'; ?>

<!-- ======================================================= -->
<!-- LAYOUT 1: CALENDARIO INTERATTIVO A TUTTO SCHERMO -->
<!-- ======================================================= -->
<?php elseif ($layout_template === 'calendar'): require __DIR__ . '/inc/area_pubblica/layout_calendario.php'; ?>

<!-- ======================================================= -->
<!-- LAYOUT 2: ELENCO AVANZATO CON RICERCA -->
<!-- ======================================================= -->
<?php elseif ($layout_template === 'advanced_list'): require __DIR__ . '/inc/area_pubblica/layout_elenco_avanzato.php'; ?>


<!-- ======================================================= -->
<!-- LAYOUT 5: TIMELINE VERTICALE -->
<!-- ======================================================= -->
<?php elseif ($layout_template === 'timeline'): require __DIR__ . '/inc/area_pubblica/layout_timeline.php'; ?>


<!-- ======================================================= -->
<!-- LAYOUT 6: GRUPPI / CORSI (discipline -> gruppi -> turni) -->
<!-- ======================================================= -->
<?php elseif ($layout_template === 'agenda'): require __DIR__ . '/inc/area_pubblica/layout_agenda.php'; ?>

<?php elseif ($layout_template === 'gruppi'): require __DIR__ . '/inc/area_pubblica/layout_gruppi.php'; ?>


<!-- ======================================================= -->
<!-- LAYOUT 7: PROGETTI (elenco con stato, periodo e scheda di dettaglio) -->
<!-- ======================================================= -->
<?php elseif ($layout_template === 'progetti'): require __DIR__ . '/inc/area_pubblica/layout_progetti.php'; ?>

<!-- ======================================================= -->
<!-- LAYOUT 3 e 4: GRID O LIST SEMPLICE (I Vecchi Layout) -->
<!-- ======================================================= -->
<?php else: require __DIR__ . '/inc/area_pubblica/layout_elenco_griglia.php'; ?>
<?php endif; ?>

<?php require __DIR__ . '/inc/area_pubblica/barra_gestore.php'; ?>

<style>
/* Misure, tipografia e bordi usati da un solo parziale di inc/area_pubblica/ (le classi condivise stanno in assets/css/portale-ui.css) */
.ui-larg-cont { max-width: var(--larg-cont); }
.ui-area-bb5 { border-bottom: 5px solid var(--c-area) !important; }
.ui-area-bx2 { border: 2px solid var(--c-area) !important; }
.ui-area-bx3 { border: 3px solid var(--c-area) !important; }
.ui-fs-100 { font-size: 1rem; }
.ui-fs-105 { font-size: 1.05rem !important; }
.ui-fs-120 { font-size: 1.2rem !important; }
.ui-fs-93 { font-size: 0.93rem !important; }
.ui-h-100p { height: 100%; }
.ui-h-160 { height: 160px; }
.ui-lh-1_45 { line-height: 1.45 !important; }
.ui-lh-1_55 { line-height: 1.55 !important; }
.ui-ls-1 { letter-spacing: 1px !important; }
.ui-ls-5 { letter-spacing: 0.5px; }
.ui-maxh-100 { max-height: 100px; }
.ui-maxh-250 { max-height: 250px; }
.ui-maxh-380 { max-height: 380px; }
.ui-maxh-420 { max-height: 420px; }
.ui-maxh-60 { max-height: 60px; }
.ui-minh-150 { min-height: 150px; }
</style>
<?php require __DIR__ . '/inc/area_pubblica/coda_pagina.php'; require_once 'footer.php'; ?>
