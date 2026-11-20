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

use App\Pubblicazioni\ImpostazioniPubblicazioni;

$imp_pb = \App\Core\App::per($conn)->get(ImpostazioniPubblicazioni::class);

if ($fase === 'azioni') {
    if (isset($_POST['salva_impostazioni_pubblicazioni'])) {
        $dati_imp = ImpostazioniPubblicazioni::daPost($_POST, array_keys(ImpostazioniPubblicazioni::SEZIONI));
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
    </fieldset>

    <fieldset class="pb-gruppo mb-3">
        <legend class="h6 fw-bold mb-2">Limiti</legend>
        <p class="small text-secondary">I caratteri si contano senza formattazione, spazi inclusi: il contatore della pagina e il controllo all'invio usano questi numeri.</p>
        <div class="row g-2">
            <?php foreach (['max_sottotitolo' => 'Caratteri del sottotitolo', 'max_descrizione' => 'Caratteri della breve descrizione', 'max_allegati' => 'Numero massimo di allegati', 'max_immagini' => 'Numero massimo di ulteriori immagini', 'giorni_minimi' => 'Giorni di anticipo'] as $k_l => $t_l):
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
            <label class="form-check-label fw-bold" for="impPbAllObb">Almeno un allegato è obbligatorio (per esempio la locandina)</label>
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
<style>
.pb-gruppo { border: 1px solid var(--bs-border-color, #e2e8f0); border-radius: 12px; padding: .75rem 1rem; }
.pb-gruppo > legend { float: none; width: auto; padding: 0 .25rem; margin-bottom: .5rem; font-size: 1rem; }
</style>
<script>
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
</script>
