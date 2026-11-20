<?php
// amministrazione.php - Modulo Amministrazione: modulistica amministrativa e procedure online del personale. Contiene «Cosa devo fare»
// (la prima schermata), la scheda «Modulistica» (solo per chi può tutto) e, nel menu a sinistra, le Richieste di pubblicazione (pubblicazioni.php, che
// resta dov'è: i link delle email già partite continuano a funzionare), la scheda «Impostazioni» (solo per chi può tutto: la Richiesta di pubblicazione) e la scheda
// «Campi e colonne» (solo per chi può tutto: i campi del modulo e le colonne del file Excel della Richiesta di pubblicazione).
// Le prossime schede si aggiungono a SCHEDE_AMMINISTRAZIONE, ognuna nel suo file
// admin/amministrazione_<scheda>.php con le sue azioni e la sua pagina; questo file prepara i dati comuni e le include, come didattica.php.
// Permessi: PermessiAmministrazione (una regola sola). Chi ha solo «Amministrazione · Richieste di pubblicazione» entra, ma apre solo «home»
// (e lì vede solo le voci delle richieste); le altre schede sono per chi ha tutto il modulo. Controllati a OGNI richiesta, anche GET e POST.
require_once 'admin_header.php';
define('AMMINISTRAZIONE_PANNELLO', true);
const SCHEDE_AMMINISTRAZIONE = ['home', 'modulistica', 'impostazioni', 'campi'];

if (!$puo_amm_entrare) nega_accesso();
function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tab = in_array($_GET['tab'] ?? '', SCHEDE_AMMINISTRAZIONE, true) ? $_GET['tab'] : 'home';
// Una scheda che esiste ma non è per questa persona: accesso negato (non si ripiega sulla home, così il motivo è chiaro)
if (!$perm_amm->puoScheda($utente_admin, $tab)) nega_accesso();
$base = 'amministrazione.php?p_id=' . (int)$filtro_p;
$uid = (int)$u_id_curr;

// ==============================================================================
// AZIONI: ogni scheda gestisce le sue, ma solo se la persona può aprirla
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $fase = 'azioni';
    foreach (SCHEDE_AMMINISTRAZIONE as $scheda) {
        if ($perm_amm->puoScheda($utente_admin, $scheda)) require __DIR__ . '/amministrazione_' . $scheda . '.php';
    }
}

// ==============================================================================
// PAGINA
// ==============================================================================
$aiuto_amm = ['home' => 'Qui vedi solo quello che devi fare. Le altre sezioni sono nel menu a sinistra e nell\'elenco qui sotto.',
              'modulistica' => 'Le procedure online del personale e i documenti da scaricare della Modulistica amministrativa, come li vede chi apre la pagina pubblica.',
              'impostazioni' => 'Chi può inviare la richiesta di pubblicazione, se è aperta, i testi della pagina, i limiti dei campi e le email.',
              'campi' => 'Come si chiamano i campi della richiesta di pubblicazione e le colonne del file Excel, quali sono obbligatori e in che ordine stanno.'][$tab] ?? '';
echo pn_testata('Amministrazione', 'fa-building-columns', $aiuto_amm);
$fase = 'vista';
require __DIR__ . '/amministrazione_' . $tab . '.php';
require_once 'admin_footer.php';
