<?php
// chi_compila_cosa.php - «Chi compila cosa» (ultima parte della scheda «Campi e colonne»): la griglia colonne degli operatori × operatori (chi può lavorare le
// richieste di pubblicazione). «Proponi dai nomi "a cura di"» riempie le caselle senza salvare; si salva a mano. Le regole stanno in App\Pubblicazioni\ColonneDiChi.
// La include solo admin/amministrazione_campi.php, che ha già controllato il permesso (puoTutto) e il codice di sicurezza e che salva.
// Variabili di chi include: $h, $base, $chi_colonne (righe), $chi_operatori, $chi_spunte (chiave => id spuntati), $chi_proposte (chiave => id aggiunti dalla
// proposta, non salvati; null = nessuna proposta in corso), $chi_senza_nome (nomi delle colonne per cui la proposta non ha trovato nessuno), $chi_salvate (bool).
if (!defined('AMMINISTRAZIONE_PANNELLO')) { http_response_code(403); exit('Accesso negato.'); }

use App\Pubblicazioni\RegistroModifiche;

$chi_nome_colonna = fn(array $c): string => RegistroModifiche::nomeColonna($c['chiave'], $cfg_cp);
// Quello che si vede in griglia (salvato o ancora da salvare): colonne senza nessuno e operatori limitati senza colonne
$chi_vuote = array_values(array_filter($chi_colonne, fn(array $c): bool => empty($chi_spunte[$c['chiave']])));
$chi_qualcosa = count($chi_vuote) < count($chi_colonne);
$chi_senza = array_values(array_filter($chi_operatori, fn(array $o): bool => !$o['tutto'] && !array_filter($chi_spunte, fn(array $ids): bool => in_array($o['id'], $ids, true))));
?>
<form method="POST" action="<?php echo $h($base); ?>&amp;tab=campi#chi-compila" class="card border-0 shadow-sm mb-3" id="chi-compila"><div class="card-body">
    <?php csrf_field(); ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h2 class="h5 fw-bold mb-0"><i class="fa fa-user-check me-1" aria-hidden="true"></i>Chi compila cosa</h2>
        <?php if ($chi_operatori): ?>
            <button type="submit" name="proponi_chi_compila" value="1" class="btn btn-sm btn-outline-secondary ms-auto"><i class="fa fa-wand-magic-sparkles me-1" aria-hidden="true"></i>Proponi dai nomi «a cura di»</button>
        <?php endif; ?>
    </div>
    <p class="small text-secondary mb-2">Spunta, per ogni colonna della tabella di monitoraggio, le persone che la compilano (anche più di una). Qui ci sono tutte le persone che possono lavorare le richieste di pubblicazione.</p>
    <ul class="small text-secondary mb-3">
        <li><strong>Chi vede tutto</strong> (amministratori e chi ha il modulo Amministrazione intero) vede e compila sempre tutte le colonne: le sue caselle servono solo al filtro «Solo le mie colonne».</li>
        <li><strong>Gli altri</strong> vedono e compilano solo le colonne spuntate; i dati di chi invia la richiesta li vedono in sola lettura.</li>
        <li><strong>Se non è salvata nessuna casella</strong>, tutti vedono e compilano tutte le colonne. Dalla prima casella salvata valgono solo le spunte.</li>
    </ul>

    <?php if ($chi_proposte !== null): $chi_n = array_sum(array_map('count', $chi_proposte)); ?>
        <div class="alert alert-info py-2 small" role="status"><strong>Proposta, non ancora salvata.</strong> <?php echo $chi_n === 0 ? 'Non ho aggiunto nessuna casella.' : ($chi_n === 1 ? 'Ho aggiunto 1 casella (evidenziata).' : 'Ho aggiunto ' . (int)$chi_n . ' caselle (evidenziate).'); ?> Controlla e premi «Salva chi compila cosa».
            <?php if ($chi_senza_nome): ?><div>Nessun operatore riconosciuto nell'«a cura di» di: <?php echo $h(implode('; ', $chi_senza_nome)); ?>.</div><?php endif; ?>
        </div>
    <?php elseif (!$chi_salvate): ?>
        <div class="alert alert-secondary py-2 small" role="status">Nessuna casella salvata: oggi tutti gli operatori vedono e compilano tutte le colonne.</div>
    <?php endif; ?>

    <?php if (!$chi_operatori): ?>
        <p class="small text-secondary mb-0">Nessuno può ancora lavorare le richieste: l'abilitazione «Amministrazione · Richieste di pubblicazione» si dà da «Utenti e abilitazioni».</p>
    <?php else: ?>
        <div class="table-responsive chi-scorri"><table class="table table-sm align-middle mb-0 small chi-tab">
            <thead><tr>
                <th scope="col">Colonna</th>
                <?php foreach ($chi_operatori as $o): ?>
                    <th scope="col" class="chi-persona"><?php echo $h($o['nominativo']); ?><?php if ($o['tutto']): ?><span class="badge text-bg-light border d-block mt-1">vede tutto</span><?php endif; ?></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($chi_colonne as $c): $k = $c['chiave']; $nome_c = $chi_nome_colonna($c); ?>
                <tr>
                    <th scope="row" class="chi-colonna">
                        <svg class="me-1" width="12" height="12" aria-hidden="true" focusable="false"><rect width="12" height="12" rx="3" fill="<?php echo $h($c['colore']); ?>"/></svg><?php echo $h($nome_c); ?>
                        <?php if (!$c['visibile']): ?><span class="badge text-bg-light border">nascosta</span><?php endif; ?>
                        <div class="fw-normal text-secondary">a cura di <?php echo $h($c['a_cura_di']); ?></div>
                    </th>
                    <?php foreach ($chi_operatori as $o): $id_c = 'chi_' . $k . '_' . $o['id']; $nuova_c = in_array($o['id'], $chi_proposte[$k] ?? [], true); ?>
                        <td class="chi-cella<?php echo $nuova_c ? ' chi-proposta' : ''; ?>">
                            <label class="chi-voce" for="<?php echo $h($id_c); ?>">
                                <input class="form-check-input" type="checkbox" id="<?php echo $h($id_c); ?>" name="chi[<?php echo $h($k); ?>][]" value="<?php echo (int)$o['id']; ?>"<?php echo in_array($o['id'], $chi_spunte[$k] ?? [], true) ? ' checked' : ''; ?>>
                                <span class="chi-nome"><?php echo $h($o['nominativo']); ?><span class="visually-hidden"> compila <?php echo $h($nome_c); ?></span></span>
                            </label>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>

        <?php if ($chi_qualcosa && ($chi_vuote || $chi_senza)): ?>
            <div class="alert alert-warning py-2 small mt-2 mb-0" role="note">
                <?php if ($chi_vuote): ?><div><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><strong>Colonne senza nessuno</strong> (le compila solo chi vede tutto): <?php echo $h(implode('; ', array_map($chi_nome_colonna, $chi_vuote))); ?>.</div><?php endif; ?>
                <?php if ($chi_senza): ?><div><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i><strong>Senza nessuna colonna</strong>: <?php echo $h(implode(', ', array_column($chi_senza, 'nominativo'))); ?>.</div><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="pn-salva">
            <button type="submit" name="salva_chi_compila" value="1" class="btn btn-primary fw-bold"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva chi compila cosa</button>
            <a href="<?php echo $h($base); ?>&amp;tab=campi#chi-compila" class="btn btn-outline-secondary">Annulla</a>
        </div>
    <?php endif; ?>
</div></form>

<style>
.chi-scorri { max-height: 70vh; overflow: auto; }
.chi-tab thead th { position: sticky; top: 0; z-index: 2; background: var(--bs-body-bg, #fff); vertical-align: bottom; }
.chi-tab thead th:first-child, .chi-tab tbody th { position: sticky; left: 0; background: var(--bs-body-bg, #fff); min-width: 15rem; max-width: 22rem; }
.chi-tab thead th:first-child { z-index: 3; }
.chi-tab tbody th { z-index: 1; font-weight: 700; }
.chi-tab .chi-persona { text-align: center; min-width: 7rem; }
.chi-tab .chi-cella { text-align: center; }
.chi-tab .chi-voce { display: flex; align-items: center; justify-content: center; gap: .5rem; min-height: 2rem; margin: 0; cursor: pointer; }
.chi-tab .chi-voce .form-check-input { margin: 0; flex: 0 0 auto; width: 1.25rem; height: 1.25rem; }
.chi-tab .chi-nome { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }
.chi-tab td.chi-proposta { background: #dbeafe; }
[data-bs-theme="dark"] .chi-tab td.chi-proposta { background: #1e3a5f; }
/* telefono: niente scorrimento di lato, una colonna sotto l'altra con i nomi accanto alle caselle */
@media (max-width: 767.98px) {
    .chi-scorri { max-height: none; overflow: visible; }
    .chi-tab, .chi-tab tbody, .chi-tab th, .chi-tab td { display: block; width: auto; }
    .chi-tab thead { display: none; }
    .chi-tab tr { display: grid; grid-template-columns: 1fr 1fr; column-gap: .5rem; border: 1px solid var(--bs-border-color, var(--dibest-bordo-scheda)); border-radius: 10px; padding: .5rem .75rem; margin-bottom: .6rem; }
    .chi-tab tbody th { position: static; grid-column: 1 / -1; min-width: 0; max-width: none; border: 0; padding: 0 0 .25rem; }
    .chi-scorri .chi-tab tbody td.chi-cella { text-align: left; border: 0; padding: 0 .25rem; border-radius: 6px; }
    .chi-tab .chi-voce { justify-content: flex-start; min-height: 2.5rem; }
    .chi-tab .chi-nome { position: static; width: auto; height: auto; overflow: visible; clip: auto; white-space: normal; }
}
</style>
