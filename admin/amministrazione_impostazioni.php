<?php
// amministrazione_impostazioni.php - Impostazioni del modulo Amministrazione: per ora un riquadro, «Richiesta di pubblicazione», diviso in Chi può inviare,
// Apertura, Testi, Limiti ed Email, con un solo «Salva». Le regole e la validazione stanno in App\Pubblicazioni\ImpostazioniPubblicazioni (la sola classe che
// legge e salva queste impostazioni); qui solo il modulo, gli errori accanto ai campi e la riga nel registro delle attività con quello che è cambiato.
// La parte «Email» è un parziale (admin/parziali/impostazioni_pubblicazioni_email.php) che usa anche pubblicazioni.php?vista=impostazioni per chi ha solo
// «Amministrazione · Richieste di pubblicazione».
// Scheda del pannello Amministrazione: si apre da amministrazione.php?tab=impostazioni, mai direttamente. Solo per chi può tutto il modulo
// (PermessiAmministrazione::puoTutto), ricontrollato qui anche per i POST.
// $fase = 'azioni': salva (riuscito = rimando; con errori la pagina si ridisegna con quanto scritto); $fase = 'vista': la pagina.
if (!defined('AMMINISTRAZIONE_PANNELLO')) { http_response_code(403); exit('Accesso negato.'); }
if (!$perm_amm->puoTutto($utente_admin)) nega_accesso();

use App\Pubblicazioni\Costanti as CostantiPb;
use App\Pubblicazioni\ImpostazioniPubblicazioni;
use App\Pubblicazioni\PersonePubblicazioni;

$imp_pb = \App\Core\App::per($conn)->get(ImpostazioniPubblicazioni::class);
$persone_pb = \App\Core\App::per($conn)->get(PersonePubblicazioni::class);

if ($fase === 'azioni') {
    if (isset($_POST['salva_impostazioni_pubblicazioni'])) {
        $dati_imp = ImpostazioniPubblicazioni::daPost($_POST, ImpostazioniPubblicazioni::sezioniPrincipali());
        $esito_imp = $imp_pb->aggiorna($dati_imp);
        if (!$esito_imp['errori']) {
            if ($esito_imp['cambi']) {
                registra_log_audit($conn, 'Richiesta di pubblicazione: impostazioni modificate', ImpostazioniPubblicazioni::cambiInParole($esito_imp['cambi']));
                flash_set('Impostazioni della richiesta di pubblicazione salvate.', 'success');
            } else {
                flash_set('Non c\'era nulla da cambiare.', 'info');
            }
            admin_redirect("$base&tab=impostazioni");
        }
        // errori: la pagina si ridisegna con quello che è stato scritto, così non si perde
        $imp_err = $esito_imp['errori'];
        $imp_val = $dati_imp + $imp_pb->tutte();
    }

    // le tre liste di persone: aggiungi (scelta dall'anagrafe) e togli; ogni cambiamento nel registro delle modifiche, come le altre impostazioni
    if (isset($_POST['pb_persona_aggiungi']) || isset($_POST['pb_persona_togli'])) {
        $lista_pb = (string)($_POST['pb_lista'] ?? '');
        $persona_pb = (string)($_POST['pb_persona'] ?? '');
        $aggiungi_pb = isset($_POST['pb_persona_aggiungi']);
        $esito_pb = $aggiungi_pb ? $persone_pb->aggiungi($lista_pb, $persona_pb, $uid) : $persone_pb->togli($lista_pb, $persona_pb);
        if ($esito_pb->riuscito) {
            registra_log_audit($conn, 'Richiesta di pubblicazione: impostazioni modificate', [(CostantiPb::RUOLI_PERSONE[$lista_pb] ?? $lista_pb) => $esito_pb->messaggio]);
        }
        flash_set($esito_pb->messaggio, $esito_pb->riuscito ? 'success' : 'danger');
        admin_redirect("$base&tab=impostazioni#impPbLista_" . (isset(CostantiPb::RUOLI_PERSONE[$lista_pb]) ? $lista_pb : 'approvatore'));
    }
    // l'interruttore dell'approvazione: non si accende senza un approvatore con account (ImpostazioniPubblicazioni::aggiorna)
    if (isset($_POST['salva_approvazione_pubblicazioni'])) {
        $esito_ap = $imp_pb->aggiorna(ImpostazioniPubblicazioni::daPost($_POST, ['approvazione']));
        if ($esito_ap['errori']) {
            flash_set((string)reset($esito_ap['errori']), 'danger');
        } elseif ($esito_ap['cambi']) {
            registra_log_audit($conn, 'Richiesta di pubblicazione: impostazioni modificate', ImpostazioniPubblicazioni::cambiInParole($esito_ap['cambi']));
            flash_set(!empty($esito_ap['cambi']['approvazione_attiva']['a']) ? 'Approvazione accesa: le nuove richieste aspettano l\'approvazione.' : 'Approvazione spenta: le nuove richieste nascono già approvate.', 'success');
        } else {
            flash_set('Non c\'era nulla da cambiare.', 'info');
        }
        admin_redirect("$base&tab=impostazioni#impPbApprovazione");
    }

    return;
}

// ── PAGINA ──
$imp_err = $imp_err ?? [];
$imp_val = $imp_val ?? $imp_pb->tutte();
$campo_err = static fn (string $k): string => isset($imp_err[$k]) ? '<div class="text-danger small fw-bold mt-1" role="alert" id="err_' . $h($k) . '">' . $h($imp_err[$k]) . '</div>' : '';
$invalido = static fn (string $k): string => isset($imp_err[$k]) ? ' is-invalid" aria-invalid="true" aria-describedby="err_' . $h($k) : '';
$scuole_scelte = in_array(\App\Auth\Pubblico::SCUOLE, (array)$imp_val['chi_puo_inviare'], true);
$profili_chi = array_values(array_diff(\App\Auth\Pubblico::PROFILI, [\App\Auth\Pubblico::ANONIMI]));
?>
<?php if ($imp_err): ?>
    <div class="alert alert-danger" role="alert"><strong>Le impostazioni non sono state salvate.</strong> Correggi quello che è segnato qui sotto:
        <ul class="mb-0"><?php foreach ($imp_err as $m): ?><li><?php echo $h($m); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="POST" action="<?php echo $h($base); ?>&amp;tab=impostazioni" class="card border-0 shadow-sm mb-3" id="impPbForm"><div class="card-body">
    <?php csrf_field(); ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h2 class="h5 fw-bold mb-0"><i class="fa fa-bullhorn me-1" aria-hidden="true"></i>Richiesta di pubblicazione</h2>
        <?php echo $imp_pb->aperta() ? pn_stato('Aperta', 'ok', 'fa-lock-open') : pn_stato('Chiusa', 'spento', 'fa-lock'); ?>
        <span class="ms-auto d-flex flex-wrap gap-1">
            <a class="btn btn-sm btn-outline-secondary py-0" href="../richiesta_pubblicazione.php" target="_blank" rel="noopener"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Pagina pubblica<span class="visually-hidden"> (si apre in una nuova scheda)</span></a>
            <a class="btn btn-sm btn-outline-primary py-0" href="pubblicazioni.php?vista=arrivate"><i class="fa fa-bullhorn me-1" aria-hidden="true"></i>Richieste di pubblicazione</a>
        </span>
    </div>
    <p class="small text-secondary mb-3">Le impostazioni della procedura guidata con cui docenti e personale chiedono di pubblicare un'iniziativa sul sito e sui social. Valgono da subito per le nuove richieste; quelle già arrivate non cambiano.</p>

    <fieldset class="pb-gruppo mb-3">
        <legend class="h6 fw-bold mb-2">Chi può inviare</legend>
        <?php echo html_scelta_pubblico(ImpostazioniPubblicazioni::CAMPO_CHI, ['profili' => (array)$imp_val['chi_puo_inviare']], $profili_chi, 'Chi può inviare la richiesta'); ?>
        <?php echo $campo_err('chi_puo_inviare'); ?>
        <div class="form-text">Serve sempre l'accesso con le credenziali. Gli amministratori possono sempre inviarla. Chi non è tra questi profili vede «Questa richiesta è riservata a: …».</div>
        <div class="alert alert-warning small py-2 mt-2 mb-0" role="note" id="impPbAvvisoScuole"<?php echo $scuole_scelte ? '' : ' hidden'; ?>><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo $h(\App\Auth\Pubblico::AVVISO_SCUOLE); ?></div>
    </fieldset>

    <fieldset class="pb-gruppo mb-3">
        <legend class="h6 fw-bold mb-2">Apertura</legend>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="impPbAperta" name="aperta" value="1"<?php echo $imp_val['aperta'] ? ' checked' : ''; ?> aria-describedby="impPbApertaAiuto">
            <label class="form-check-label fw-bold" for="impPbAperta">Le richieste sono aperte</label>
            <div class="form-text" id="impPbApertaAiuto">Se le chiudi nessuno può inviare una richiesta (neppure gli amministratori): la pagina mostra il messaggio qui sotto e la Modulistica amministrativa la segna «Chiusa».</div>
        </div>
        <label class="form-label fw-bold" for="impPbMsg">Messaggio quando è chiusa</label>
        <input type="text" class="form-control<?php echo $invalido('messaggio_chiusa'); ?>" id="impPbMsg" name="messaggio_chiusa" maxlength="500" value="<?php echo $h($imp_val['messaggio_chiusa']); ?>" required>
        <?php echo $campo_err('messaggio_chiusa'); ?>
    </fieldset>

    <fieldset class="pb-gruppo mb-3">
        <legend class="h6 fw-bold mb-2">Testi</legend>
        <div class="mb-3">
            <label class="form-label fw-bold" for="impPbIntro">Testo in cima alla pagina</label>
            <textarea class="form-control editor-html" id="impPbIntro" name="testo_introduttivo" rows="3" aria-describedby="impPbIntroAiuto"><?php echo $h($imp_val['testo_introduttivo']); ?></textarea>
            <div class="form-text" id="impPbIntroAiuto">Sotto il titolo «Richiesta di pubblicazione». Puoi usare grassetto, corsivo, elenchi e collegamenti. Se lo lasci vuoto non compare niente.</div>
            <?php echo $campo_err('testo_introduttivo'); ?>
        </div>
        <div>
            <label class="form-label fw-bold" for="impPbDopo">Testo dopo l'invio</label>
            <textarea class="form-control editor-html" id="impPbDopo" name="testo_dopo_invio" rows="3" aria-describedby="impPbDopoAiuto"><?php echo $h($imp_val['testo_dopo_invio']); ?></textarea>
            <div class="form-text" id="impPbDopoAiuto">Quello che legge chi ha appena inviato la richiesta, sotto «Richiesta inviata». Se la ricevuta è accesa (parte «Email»), sotto si aggiunge da solo che è arrivata la ricevuta.</div>
            <?php echo $campo_err('testo_dopo_invio'); ?>
        </div>
        <div class="mt-3">
            <label class="form-label fw-bold" for="impPbNote">Note operative</label>
            <textarea class="form-control<?php echo $invalido('note_operative'); ?>" id="impPbNote" name="note_operative" rows="6" maxlength="3000" aria-describedby="impPbNoteAiuto"><?php echo $h($imp_val['note_operative']); ?></textarea>
            <div class="form-text" id="impPbNoteAiuto">Il riquadro del primo passo, sopra «Ho letto le note operative». Una nota per riga, nella forma «Titolo: testo» (il titolo si legge in grassetto). Testo semplice.</div>
            <?php echo $campo_err('note_operative'); ?>
        </div>
        <div class="mt-3">
            <label class="form-label fw-bold" for="impPbPagine">Pagine e canali dove pubblicare</label>
            <textarea class="form-control<?php echo $invalido('pagine_pubblicazione'); ?>" id="impPbPagine" name="pagine_pubblicazione" rows="5" placeholder="Home del DiBEST | https://dibest.unical.it&#10;Pagina Facebook | https://www.facebook.com/…" aria-describedby="impPbPagineAiuto"><?php echo $h($imp_val['pagine_pubblicazione']); ?></textarea>
            <div class="form-text" id="impPbPagineAiuto">Una pagina o un canale per riga, nella forma «Nome | indirizzo». Con l'elenco pieno la domanda «Dove vuoi che si pubblichi?» diventa una scelta tra queste caselle più «Altra pagina del sito DiBEST» (un indirizzo su dibest.unical.it o su un sito di Unical con «dibest» nel nome). <strong>Lasciato vuoto</strong> la domanda resta un indirizzo libero e facoltativo, come prima.</div>
            <?php echo $campo_err('pagine_pubblicazione'); ?>
        </div>
    </fieldset>

    <fieldset class="pb-gruppo mb-3">
        <legend class="h6 fw-bold mb-2">Limiti</legend>
        <p class="small text-secondary">I caratteri si contano senza formattazione, spazi inclusi: il contatore della pagina e il controllo all'invio usano questi numeri.</p>
        <div class="row g-2">
            <?php foreach (['max_sottotitolo' => 'Caratteri del sottotitolo', 'max_descrizione' => 'Caratteri della breve descrizione', 'max_allegati' => 'Numero massimo di altri allegati', 'max_immagini' => 'Numero massimo di ulteriori immagini', 'giorni_minimi' => 'Giorni di anticipo'] as $k_l => $t_l):
                [$min_l, $max_l] = ImpostazioniPubblicazioni::LIMITI[$k_l]; ?>
            <div class="col-sm-6 col-lg-4">
                <label class="form-label small fw-bold" for="impPb_<?php echo $h($k_l); ?>"><?php echo $h($t_l); ?></label>
                <input type="number" class="form-control<?php echo $invalido($k_l); ?>" id="impPb_<?php echo $h($k_l); ?>" name="<?php echo $h($k_l); ?>" min="<?php echo (int)$min_l; ?>" max="<?php echo (int)$max_l; ?>" step="1" value="<?php echo $h($imp_val[$k_l]); ?>" required aria-describedby="impPb_<?php echo $h($k_l); ?>_aiuto">
                <div class="form-text" id="impPb_<?php echo $h($k_l); ?>_aiuto"><?php echo $k_l === 'giorni_minimi'
                    ? 'Quanti giorni dopo oggi può iniziare, al più presto, la pubblicazione (0 = da oggi in poi, mai nel passato).'
                    : 'Da ' . (int)$min_l . ' a ' . (int)$max_l . '.'; ?></div>
                <?php echo $campo_err($k_l); ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="impPbAllObb" name="allegati_obbligatori" value="1"<?php echo $imp_val['allegati_obbligatori'] ? ' checked' : ''; ?>>
            <label class="form-check-label fw-bold" for="impPbAllObb">Almeno un altro allegato è obbligatorio (la locandina lo è sempre)</label>
        </div>
    </fieldset>

    <fieldset class="pb-gruppo mb-0">
        <legend class="h6 fw-bold mb-2">Email</legend>
        <?php require __DIR__ . '/parziali/impostazioni_pubblicazioni_email.php'; ?>
    </fieldset>

    <div class="pn-salva">
        <button type="submit" name="salva_impostazioni_pubblicazioni" value="1" class="btn btn-primary fw-bold"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva</button>
        <a href="<?php echo $h($base); ?>&amp;tab=impostazioni" class="btn btn-outline-secondary">Annulla</a>
    </div>
</div></form>

<?php
$liste_pb = $persone_pb->tutte();
$ap_attiva = $imp_pb->approvazioneAttiva();
$ap_account = $persone_pb->approvatoriConAccount();
$aiuti_pb = [
    'approvatore' => ['fa-stamp', 'Ricevono la richiesta appena inviata e possono approvarla o respingerla (con un motivo): basta uno di loro. Possono approvare solo dopo il primo accesso al portale.'],
    'ufficio' => ['fa-people-group', 'Ricevono la richiesta approvata, con il collegamento alla scheda. Per lavorarla nel pannello serve la loro abilitazione «Amministrazione · Richieste di pubblicazione».'],
    'conoscenza' => ['fa-eye', 'Ricevono la richiesta approvata in sola lettura, senza collegamenti: servono solo a tenerli informati.'],
];
?>
<section class="card border-0 shadow-sm mb-3" id="impPbApprovazione"><div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h2 class="h5 fw-bold mb-0"><i class="fa fa-user-check me-1" aria-hidden="true"></i>Chi approva, chi lavora, chi è informato</h2>
        <?php echo $ap_attiva ? pn_stato('Approvazione accesa', 'ok', 'fa-toggle-on') : pn_stato('Approvazione spenta', 'spento', 'fa-toggle-off'); ?>
    </div>
    <p class="small text-secondary mb-3">Le persone si scelgono dall'anagrafe di Ateneo: l'indirizzo email si legge da lì ogni volta, non si scrive qui. Il permesso di approvare vale per l'account collegato alla persona, mai per un indirizzo scritto a mano.</p>

    <form method="POST" action="<?php echo $h($base); ?>&amp;tab=impostazioni#impPbApprovazione" class="pb-gruppo mb-3">
        <?php csrf_field(); ?>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="impPbApprAttiva" name="approvazione_attiva" value="1"<?php echo $ap_attiva ? ' checked' : ''; ?> aria-describedby="impPbApprAttivaAiuto">
            <label class="form-check-label fw-bold" for="impPbApprAttiva">Approvazione accesa</label>
            <div class="form-text" id="impPbApprAttivaAiuto">Accesa: le nuove richieste aspettano che uno degli approvatori le approvi e solo dopo si possono mandare al file Excel. Spenta (o senza nessun approvatore con un account): le richieste nascono già approvate, come prima. Quelle già arrivate non cambiano.</div>
        </div>
        <?php if ($ap_account === 0): ?>
            <div class="text-danger small fw-bold mt-2" role="alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Nessun approvatore ha ancora un account collegato: l'approvazione non si può accendere<?php echo $ap_attiva ? ' e per ora le richieste nascono approvate' : ''; ?>.</div>
        <?php endif; ?>
        <div class="mt-2"><button type="submit" name="salva_approvazione_pubblicazioni" value="1" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva l'interruttore</button></div>
    </form>

    <?php foreach (CostantiPb::RUOLI_PERSONE as $k_lista => $t_lista): [$ic_lista, $aiuto_lista] = $aiuti_pb[$k_lista]; $persone_lista = $liste_pb[$k_lista] ?? []; ?>
    <fieldset class="pb-gruppo mb-3" id="impPbLista_<?php echo $h($k_lista); ?>">
        <legend class="h6 fw-bold mb-2"><i class="fa <?php echo $h($ic_lista); ?> me-1" aria-hidden="true"></i><?php echo $h($t_lista); ?> <span class="badge text-bg-secondary"><?php echo count($persone_lista); ?></span></legend>
        <p class="small text-secondary"><?php echo $h($aiuto_lista); ?></p>
        <?php if (!$persone_lista): ?>
            <p class="small text-secondary fst-italic mb-2">Nessuna persona<?php echo $k_lista === 'ufficio' ? ': l\'avviso va solo agli indirizzi della parte «Email» qui sopra' : ''; ?>.</p>
        <?php else: ?>
            <ul class="list-group mb-2">
                <?php foreach ($persone_lista as $x_p): ?>
                <li class="list-group-item">
                    <form method="POST" action="<?php echo $h($base); ?>&amp;tab=impostazioni#impPbLista_<?php echo $h($k_lista); ?>" class="d-flex flex-wrap align-items-center gap-2">
                        <?php csrf_field(); ?><input type="hidden" name="pb_lista" value="<?php echo $h($k_lista); ?>"><input type="hidden" name="pb_persona" value="<?php echo $h($x_p['persona_id']); ?>">
                        <div class="flex-grow-1">
                            <div class="fw-bold"><?php echo $h($x_p['nominativo']); ?></div>
                            <div class="small text-secondary"><?php echo $x_p['email'] !== '' ? $h($x_p['email']) : 'email non disponibile'; ?></div>
                            <?php foreach ($x_p['avvisi'] as $av_p): ?><div class="text-danger small fw-bold" role="alert"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo $h($av_p); ?></div><?php endforeach; ?>
                        </div>
                        <button type="submit" name="pb_persona_togli" value="1" class="btn btn-sm btn-outline-danger"><i class="fa fa-xmark me-1" aria-hidden="true"></i>Togli<span class="visually-hidden"> <?php echo $h($x_p['nominativo']); ?> da «<?php echo $h($t_lista); ?>»</span></button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="POST" action="<?php echo $h($base); ?>&amp;tab=impostazioni#impPbLista_<?php echo $h($k_lista); ?>" class="pb-aggiungi">
            <?php csrf_field(); ?><input type="hidden" name="pb_lista" value="<?php echo $h($k_lista); ?>"><input type="hidden" name="pb_persona" value=""><input type="hidden" name="pb_persona_aggiungi" value="1">
            <div class="small fw-bold mb-1">Aggiungi una persona dall'anagrafe</div>
            <?php echo html_ricerca_personale($conn, 'Aggiungi'); ?>
        </form>
    </fieldset>
    <?php endforeach; ?>
</div></section>
<style>
.pb-gruppo { border: 1px solid var(--bs-border-color, var(--dibest-bordo-scheda)); border-radius: 12px; padding: .75rem 1rem; }
.pb-gruppo > legend { float: none; width: auto; padding: 0 .25rem; margin-bottom: .5rem; font-size: 1rem; }
</style>
<script nonce="<?php echo csp_nonce(); ?>">
(function () {
    var form = document.getElementById('impPbForm');
    if (!form) return;
    // L'editor dei testi scrive nel campo solo quando serve: prima dell'invio si aggiorna
    form.addEventListener('submit', function () { if (window.tinymce) tinymce.triggerSave(); });
    // «Scuole» si dichiara da soli: l'avviso si vede quando la casella è spuntata
    var scuole = form.querySelector('input[name="<?php echo $h(ImpostazioniPubblicazioni::CAMPO_CHI); ?>_profili[]"][value="<?php echo $h(\App\Auth\Pubblico::SCUOLE); ?>"]');
    var avviso = document.getElementById('impPbAvvisoScuole');
    if (scuole && avviso) scuole.addEventListener('change', function () { avviso.hidden = !scuole.checked; });
})();
// «Aggiungi» delle tre liste di persone: la persona scelta nella ricerca dell'anagrafe parte subito con il suo modulo
document.querySelectorAll('.pb-aggiungi').forEach(function (f) {
    f.addEventListener('persona-scelta', function (e) { f.querySelector('[name=pb_persona]').value = e.detail.id; f.submit(); });
    f.addEventListener('submit', function (e) { if (!f.querySelector('[name=pb_persona]').value) e.preventDefault(); });
});
</script>
