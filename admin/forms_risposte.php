<?php
// forms_risposte.php - MyForms: le risposte a un form (?id=N): elenco con ricerca, periodo e profilo; riepilogo; dettaglio (?r=N);
// eliminazione di una risposta o di tutte; esportazione Excel, CSV e PDF (menu «Scarica», anche solo delle risposte filtrate).
// Permesso controllato a OGNI richiesta, anche GET e scaricamenti (ServizioForms::puoGestire: amministratore, proprietario o co-gestore).
// Gli allegati si scaricano da forms_allegato.php (che ricontrolla lo stesso permesso). Nei form anonimi nome ed email non esistono
// nemmeno nei dati che arrivano qui (ServizioRisposte li toglie). Dominio in src/Forms/ (ServizioRisposte, FiltriRisposte, RiepilogoRisposte).
require_once 'admin_header.php';

if (!$puo_forms_vedere) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$app_f = \App\Core\App::per($conn);
$sv = $app_f->get(\App\Forms\ServizioForms::class);
$formsRepo = $app_f->get(\App\Forms\FormRepository::class);
$rispRepo = $app_f->get(\App\Forms\RispostaRepository::class);
$risp = $app_f->get(\App\Forms\ServizioRisposte::class);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$id = (int)($_GET['id'] ?? 0);
$f = $id ? $formsRepo->perId($id) : null;
if (!$f || !$sv->puoGestire($f, $utente_admin)) nega_accesso();
$anonima = !empty($f['anonima']);

$PROFILI_BREVI = ['anonimi' => 'Senza accesso', 'registrati' => 'Con accesso', 'studenti' => 'Studente', 'docenti' => 'Docente', 'pta' => 'Personale TA',
    'personale' => 'Personale', 'esterni' => 'Esterno', 'scuole' => 'Scuola', 'aziende' => 'Azienda'];
$dataOra = fn(?string $d) => $d ? date('d/m/Y H:i', strtotime($d)) : '';

$profili_presenti = $risp->profiliPresenti($f);
$filtri = \App\Forms\FiltriRisposte::da($_GET, $profili_presenti);
$vista = ($_GET['vista'] ?? '') === 'riepilogo' ? 'riepilogo' : 'elenco';
// Indirizzo di questa pagina con i filtri attivi (e le modifiche richieste: null toglie il parametro)
$qs = function (array $extra = [], bool $conFiltri = true) use ($f, $filtri): string {
    $p = ['id' => (int)$f['id']];
    if ($conFiltri) foreach ($filtri as $k => $v) if ($v !== '') $p[$k] = $v;
    foreach ($extra as $k => $v) { if ($v === null) unset($p[$k]); else $p[$k] = $v; }
    return 'forms_risposte.php?' . http_build_query($p);
};

// ── Eliminazione (POST): una risposta, oppure tutte con il titolo del form scritto a conferma ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_POST['elimina_risposta'])) {
        $rid = (int)$_POST['elimina_risposta'];
        if ($risp->elimina($f, $rid)) {
            registra_log_audit($conn, 'MyForms: risposta eliminata', ['Form' => $f['titolo'], 'Risposta' => $rid]);
            flash_set('Risposta eliminata (con i suoi allegati).', 'warning');
        } else {
            flash_set('Risposta non trovata: forse è già stata eliminata.', 'danger');
        }
        admin_redirect($qs(['vista' => $vista === 'riepilogo' ? 'riepilogo' : null]));
    }
    if (isset($_POST['elimina_tutte'])) {
        $norm = fn(string $s): string => mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $s)));
        if ($norm((string)($_POST['conferma_titolo'] ?? '')) !== $norm((string)$f['titolo'])) {
            flash_set('Il titolo scritto non è quello del form: non è stato eliminato niente.', 'danger');
        } else {
            $n_el = $risp->eliminaTutte($f);
            registra_log_audit($conn, 'MyForms: tutte le risposte eliminate', ['Form' => $f['titolo'], 'Risposte' => $n_el]);
            flash_set("Eliminate tutte le risposte ($n_el) con i loro allegati.", 'warning');
        }
        admin_redirect($qs([], false));
    }
    admin_redirect($qs());
}

// ── Una risposta (dettaglio) ──
$rid_dett = (int)($_GET['r'] ?? 0);
$dett = $rid_dett ? $risp->risposta($f, $rid_dett) : null;
if ($rid_dett && !$dett) {
    flash_set('Risposta non trovata.', 'danger');
    admin_redirect($qs(['r' => null]));
}

// ── Elenco filtrato (serve a tabella, riepilogo ed esportazione) ──
$righe = $dett ? [] : $risp->elenco($f, $filtri);

// ── Scarica Excel, CSV o PDF delle risposte filtrate (lato server) ──
if (!$dett && isset($_GET['scarica'])) {
    $formato = (string)$_GET['scarica'];
    if (!isset(\App\Infrastructure\Documenti\TabellaEsportabile::FORMATI[$formato])) {
        flash_set('Formato non disponibile.', 'danger');
        admin_redirect($qs(['scarica' => null]));
    }
    if ($formato === 'xlsx' && !class_exists(\ZipArchive::class)) {
        flash_set('Su questo server il file Excel non si può creare: scarica il CSV, che si apre con Excel.', 'warning');
        admin_redirect($qs(['scarica' => null]));
    }
    // Solo nel CSV: l'apostrofo davanti alle celle che sembrano formule le tiene come testo in Excel. L'xlsx scrive già ogni cella come testo
    // (inlineStr) e il PDF non ha formule: lì l'apostrofo resterebbe visibile.
    $tab = \App\Forms\EsportazioneRisposte::tabella($f, $righe, $formato === 'csv');
    $sotto = count($righe) . ' risposte · stampato il ' . date('d/m/Y');
    if (\App\Forms\FiltriRisposte::attivi($filtri)) {
        $sotto .= ' · filtri: ' . implode(', ', array_filter([
            $filtri['q'] !== '' ? 'testo «' . $filtri['q'] . '»' : '',
            $filtri['dal'] !== '' ? 'dal ' . date('d/m/Y', strtotime($filtri['dal'])) : '',
            $filtri['al'] !== '' ? 'al ' . date('d/m/Y', strtotime($filtri['al'])) : '',
            $filtri['profilo'] !== '' ? 'profilo ' . ($PROFILI_BREVI[$filtri['profilo']] ?? $filtri['profilo']) : '',
        ]));
    }
    registra_log_audit($conn, 'MyForms: risposte esportate', ['Form' => $f['titolo'], 'Formato' => $formato, 'Righe' => count($righe)]);
    while (ob_get_level() > 0) ob_end_clean();
    (new \App\Infrastructure\Documenti\TabellaEsportabile((string)$f['titolo'], $tab['intestazioni'], $tab['righe'], $sotto, \App\Forms\EsportazioneRisposte::larghezzePdf($f)))
        ->scarica($formato, $f['titolo'] . '_' . date('Y-m-d'));
    http_response_code(400);
    exit('Formato non disponibile.');
}

$tot_form = $rispRepo->conta((int)$f['id']);
$campi_f = \App\Forms\EsportazioneRisposte::campiConRisposta($f);
$ha_file = (bool)array_filter($campi_f, fn($c) => $c['tipo'] === 'file');
$page_cfg['titolo'] = 'MyForms';
?>
<style>
.mf-barra { display: grid; grid-template-columns: minmax(120px, 2fr) 5fr minmax(90px, 1fr); gap: .6rem; align-items: center; margin-bottom: .35rem; font-size: .9rem; }
.mf-pista { display: block; background: var(--bs-secondary-bg, #e9ecef); border-radius: 4px; height: 14px; overflow: hidden; }
.mf-riempi { display: block; height: 100%; background: #be123c; border-radius: 4px; min-width: 0; }
.mf-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.mf-cella { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
@media (max-width: 575.98px) { .mf-barra { grid-template-columns: 1fr; gap: .1rem; } .mf-num { text-align: left; } }
</style>
<?php echo flash_html(); ?>

<?php if ($dett): ?>
    <?php
    $all_dett = [];
    foreach ($risp->allegati($f, [$dett]) as $a) $all_dett[$a['indice']] = $a;
    ?>
    <?php echo pn_testata('Risposta del ' . $dataOra($dett['inviata_il']), 'fa-clipboard-list', 'Modulo: ' . $f['titolo'],
        '<a class="btn btn-outline-secondary btn-sm" href="' . $h($qs(['r' => null])) . '"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Torna alle risposte</a>'); ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <dl class="row small mb-0">
            <dt class="col-sm-3">Inviata il</dt><dd class="col-sm-9"><?php echo $h($dataOra($dett['inviata_il'])); ?></dd>
            <?php if (!empty($dett['modificata_il'])): ?><dt class="col-sm-3">Modificata il</dt><dd class="col-sm-9"><?php echo $h($dataOra($dett['modificata_il'])); ?></dd><?php endif; ?>
            <dt class="col-sm-3">Profilo</dt><dd class="col-sm-9"><?php echo $h($PROFILI_BREVI[$dett['profilo']] ?? $dett['profilo']); ?></dd>
            <?php if (!$anonima): ?>
                <dt class="col-sm-3">Nome</dt><dd class="col-sm-9"><?php echo $h(trim(($dett['nome'] ?? '') . ' ' . ($dett['cognome'] ?? ''))) ?: '<span class="text-secondary">(senza accesso)</span>'; ?></dd>
                <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?php echo $h($dett['email'] ?? '') ?: '<span class="text-secondary">—</span>'; ?></dd>
            <?php endif; ?>
            <dt class="col-sm-3">Codice di ricevuta</dt><dd class="col-sm-9"><code><?php echo $h($dett['codice_ricevuta']); ?></code></dd>
        </dl>
    </div></div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <?php $mostrate = 0; foreach ($dett['risposte'] as $i => $r): if (!empty($r['nascosto'])) continue; $mostrate++; ?>
            <div class="mb-3">
                <div class="small fw-bold text-secondary"><?php echo $h($r['etichetta'] ?? ''); ?></div>
                <?php if (($r['tipo'] ?? '') === 'file'): ?>
                    <?php if (isset($all_dett[$i])): ?>
                        <a href="forms_allegato.php?id=<?php echo (int)$f['id']; ?>&amp;r=<?php echo (int)$dett['id']; ?>&amp;c=<?php echo (int)$i; ?>"><i class="fa fa-paperclip me-1" aria-hidden="true"></i><?php echo $h($all_dett[$i]['nome']); ?></a>
                    <?php elseif (trim((string)($r['valore'] ?? '')) !== ''): ?>
                        <?php echo $h($r['valore']); ?> <span class="text-secondary small">(il file non è più disponibile)</span>
                    <?php else: ?><span class="text-secondary">—</span><?php endif; ?>
                <?php elseif (($r['tipo'] ?? '') === 'tabella'): ?>
                    <?php $cols_t = (array)($r['colonne'] ?? []); $righe_t = (array)($r['righe'] ?? []); ?>
                    <?php if ($righe_t): ?>
                        <div class="table-responsive"><table class="table table-sm table-bordered mb-0 small">
                            <?php if ($cols_t): ?><thead class="table-light"><tr><?php foreach ($cols_t as $ct): ?><th><?php echo $h($ct); ?></th><?php endforeach; ?></tr></thead><?php endif; ?>
                            <tbody><?php foreach ($righe_t as $rt): ?><tr><?php foreach ((array)$rt as $cel): ?><td><?php echo $h($cel); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
                        </table></div>
                    <?php else: ?><span class="text-secondary">—</span><?php endif; ?>
                <?php elseif (in_array($r['tipo'] ?? '', ['checkbox', 'dichiarazione'], true)): ?>
                    <?php echo ($r['valore'] ?? '') === 'Sì' ? 'Sì' : 'No'; ?>
                <?php else: ?>
                    <?php $v_d = trim((string)($r['valore'] ?? '')); echo $v_d !== '' ? nl2br($h($v_d)) : '<span class="text-secondary">—</span>'; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$mostrate): ?><p class="text-secondary mb-0">Nessuna risposta da mostrare.</p><?php endif; ?>
    </div></div>

    <form method="POST" action="<?php echo $h($qs(['r' => null])); ?>" class="m-0"><?php csrf_field(); ?>
        <button type="submit" name="elimina_risposta" value="<?php echo (int)$dett['id']; ?>" class="btn btn-outline-danger btn-sm" data-confirm="Eliminare questa risposta<?php echo $all_dett ? ' e i suoi allegati' : ''; ?>? Non si può annullare."><i class="fa fa-trash me-1" aria-hidden="true"></i>Elimina questa risposta</button>
    </form>

<?php else: ?>
    <?php
    $n_allegati = $ha_file ? count($risp->allegati($f, $righe)) : 0;
    $azioni = '<a class="btn btn-outline-secondary btn-sm" href="forms.php?id=' . (int)$f['id'] . '"><i class="fa fa-pen me-1" aria-hidden="true"></i>Scheda del form</a>';
    if ($tot_form > 0) $azioni .= pn_scarica($qs(), 'Scarica');
    if ($n_allegati > 0) $azioni .= '<a class="btn btn-outline-secondary btn-sm fw-bold" href="forms_allegato.php?' . $h(http_build_query(array_merge(['id' => (int)$f['id'], 'zip' => 1], array_filter($filtri, fn($v) => $v !== '')))) . '"><i class="fa fa-file-zipper me-1" aria-hidden="true"></i>Allegati in ZIP (' . $n_allegati . ')</a>';
    echo pn_testata('Risposte: ' . $f['titolo'], 'fa-clipboard-list', 'Chi ha risposto e che cosa, con ricerca, riepilogo ed esportazione.', $azioni);
    ?>
    <?php if ($anonima): ?>
        <div class="alert alert-info py-2 small"><i class="fa fa-user-secret me-1" aria-hidden="true"></i>Form anonimo: qui, nelle esportazioni e nelle ricerche non compaiono nome ed email di chi ha risposto.</div>
    <?php endif; ?>

    <?php echo pn_schede([
        'elenco' => ['href' => $qs(['vista' => null]), 'testo' => 'Risposte', 'icona' => 'fa-table', 'numero' => count($righe)],
        'riepilogo' => ['href' => $qs(['vista' => 'riepilogo']), 'testo' => 'Riepilogo', 'icona' => 'fa-chart-bar'],
    ], $vista); ?>

    <form method="GET" class="d-flex flex-wrap gap-2 align-items-end mb-3">
        <input type="hidden" name="id" value="<?php echo (int)$f['id']; ?>">
        <?php if ($vista === 'riepilogo'): ?><input type="hidden" name="vista" value="riepilogo"><?php endif; ?>
        <div><label class="form-label small mb-0" for="mfrQ">Cerca nel testo</label><input type="text" class="form-control form-control-sm" id="mfrQ" name="q" value="<?php echo $h($filtri['q']); ?>" maxlength="100"></div>
        <div><label class="form-label small mb-0" for="mfrDal">Dal</label><input type="date" class="form-control form-control-sm" id="mfrDal" name="dal" value="<?php echo $h($filtri['dal']); ?>"></div>
        <div><label class="form-label small mb-0" for="mfrAl">Al</label><input type="date" class="form-control form-control-sm" id="mfrAl" name="al" value="<?php echo $h($filtri['al']); ?>"></div>
        <?php if (count($profili_presenti) > 1 || $filtri['profilo'] !== ''): ?>
        <div><label class="form-label small mb-0" for="mfrP">Profilo</label>
            <select class="form-select form-select-sm" id="mfrP" name="profilo">
                <option value="">Tutti</option>
                <?php foreach ($profili_presenti as $pp): ?><option value="<?php echo $h($pp); ?>"<?php echo $filtri['profilo'] === $pp ? ' selected' : ''; ?>><?php echo $h($PROFILI_BREVI[$pp] ?? $pp); ?></option><?php endforeach; ?>
            </select></div>
        <?php endif; ?>
        <button class="btn btn-outline-secondary btn-sm" type="submit">Filtra</button>
        <?php if (\App\Forms\FiltriRisposte::attivi($filtri)): ?><a class="btn btn-link btn-sm" href="<?php echo $h($qs(['q' => null, 'dal' => null, 'al' => null, 'profilo' => null], false) . ($vista === 'riepilogo' ? '&vista=riepilogo' : '')); ?>">Azzera</a><?php endif; ?>
    </form>

    <?php if ($tot_form === 0): ?>
        <div class="card border-0 shadow-sm"><?php echo pn_vuoto('Questo modulo non ha ancora nessuna risposta.', 'fa-inbox'); ?></div>
    <?php elseif (!$righe): ?>
        <div class="card border-0 shadow-sm"><?php echo pn_vuoto('Nessuna risposta con questi filtri.', 'fa-filter'); ?></div>

    <?php elseif ($vista === 'riepilogo'): ?>
        <?php $riepilogo = \App\Forms\RiepilogoRisposte::calcola($f, $righe); ?>
        <p class="small text-secondary"><?php echo count($righe); ?> risposte<?php echo \App\Forms\FiltriRisposte::attivi($filtri) ? ' (con i filtri scelti)' : ''; ?>. Si contano solo le persone che hanno visto la domanda.</p>
        <?php foreach ($riepilogo as $q_r): ?>
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <h2 class="h6 fw-bold mb-1"><?php echo $h($q_r['etichetta']); ?></h2>
                <p class="small text-secondary mb-2"><?php echo (int)$q_r['rispondenti']; ?> <?php echo $q_r['rispondenti'] === 1 ? 'risposta' : 'risposte'; ?><?php echo $q_r['multipla'] ? ' · si può scegliere più di una voce: le percentuali sono sulle persone, non sommano 100' : ''; ?></p>
                <?php foreach ($q_r['voci'] as $v_r): ?>
                    <div class="mf-barra">
                        <span><?php echo $h($v_r['testo']); ?></span>
                        <span class="mf-pista" role="img" aria-label="<?php echo $h($v_r['testo'] . ': ' . $v_r['perc'] . '%'); ?>"><span class="mf-riempi" style="width: <?php echo max(0, min(100, (float)$v_r['perc'])); ?>%"></span></span>
                        <span class="mf-num"><?php echo (int)$v_r['n']; ?> · <?php echo str_replace('.', ',', (string)$v_r['perc']); ?>%</span>
                    </div>
                <?php endforeach; ?>
            </div></div>
        <?php endforeach; ?>

    <?php else: ?>
        <?php
        $pag = \App\Forms\FiltriRisposte::pagina($righe, (int)($_GET['p'] ?? 1));
        $prime = array_slice($campi_f, 0, 4);
        ?>
        <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 small">
            <thead class="table-light"><tr>
                <th>Data</th><th>Profilo</th>
                <?php if (!$anonima): ?><th>Nome ed email</th><?php endif; ?>
                <th>Ricevuta</th>
                <?php foreach ($prime as $c): ?><th><?php echo $h(mb_strimwidth($c['etichetta'], 0, 40, '…')); ?></th><?php endforeach; ?>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($pag['righe'] as $r):
                $allineate = \App\Forms\EsportazioneRisposte::allinea($prime, $r['risposte']);
                $nome_r = $anonima ? '' : trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? ''));
            ?>
                <tr>
                    <td class="text-nowrap"><a href="<?php echo $h($qs(['r' => (int)$r['id']])); ?>"><?php echo $h($dataOra($r['inviata_il'])); ?></a></td>
                    <td><?php echo $h($PROFILI_BREVI[$r['profilo']] ?? $r['profilo']); ?></td>
                    <?php if (!$anonima): ?><td><?php echo $nome_r !== '' ? $h($nome_r) : '<span class="text-secondary">(senza accesso)</span>'; ?><?php if (!empty($r['email'])): ?><br><span class="text-secondary"><?php echo $h($r['email']); ?></span><?php endif; ?></td><?php endif; ?>
                    <td><code><?php echo $h(substr((string)$r['codice_ricevuta'], 0, 8)); ?>…</code></td>
                    <?php foreach ($prime as $k => $c):
                        $a = $allineate[$k];
                        $txt = ($a === null || !empty($a['nascosto'])) ? '' : trim((string)preg_replace('/\s*\R\s*/u', ' · ', (string)($a['valore'] ?? '')));
                    ?>
                        <td class="mf-cella" title="<?php echo $h($txt); ?>"><?php echo $txt !== '' ? $h($txt) : '<span class="text-secondary">—</span>'; ?></td>
                    <?php endforeach; ?>
                    <td class="text-nowrap text-end">
                        <a class="btn btn-sm btn-outline-primary py-0" href="<?php echo $h($qs(['r' => (int)$r['id']])); ?>">Apri</a>
                        <form method="POST" action="<?php echo $h($qs()); ?>" class="d-inline m-0"><?php csrf_field(); ?>
                            <button type="submit" name="elimina_risposta" value="<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-danger py-0" aria-label="Elimina questa risposta" data-confirm="Eliminare la risposta del <?php echo $h($dataOra($r['inviata_il'])); ?>? Non si può annullare."><i class="fa fa-trash" aria-hidden="true"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>

        <?php if ($pag['pagine'] > 1): ?>
            <nav class="mt-3" aria-label="Pagine delle risposte"><ul class="pagination pagination-sm mb-0 flex-wrap">
                <li class="page-item<?php echo $pag['pagina'] <= 1 ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo $h($qs(['p' => $pag['pagina'] - 1])); ?>">Precedente</a></li>
                <?php for ($n_p = max(1, $pag['pagina'] - 2); $n_p <= min($pag['pagine'], $pag['pagina'] + 2); $n_p++): ?>
                    <li class="page-item<?php echo $n_p === $pag['pagina'] ? ' active' : ''; ?>"><a class="page-link" href="<?php echo $h($qs(['p' => $n_p])); ?>"><?php echo $n_p; ?></a></li>
                <?php endfor; ?>
                <li class="page-item<?php echo $pag['pagina'] >= $pag['pagine'] ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo $h($qs(['p' => $pag['pagina'] + 1])); ?>">Successiva</a></li>
            </ul></nav>
            <p class="small text-secondary mt-1">Pagina <?php echo (int)$pag['pagina']; ?> di <?php echo (int)$pag['pagine']; ?> · <?php echo (int)$pag['totale']; ?> risposte</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($tot_form > 0): ?>
    <div class="card border-danger border-opacity-25 shadow-sm mt-4"><div class="card-body">
        <h2 class="h6 fw-bold text-danger">Elimina tutte le risposte</h2>
        <p class="small text-secondary">Cancella tutte le <?php echo (int)$tot_form; ?> risposte di questo modulo (anche quelle che i filtri qui sopra nascondono) e i loro allegati. Non si può annullare. Se ti servono, scaricale prima.</p>
        <form method="POST" action="<?php echo $h($qs([], false)); ?>" class="d-flex flex-wrap gap-2 align-items-end"><?php csrf_field(); ?>
            <div class="flex-grow-1"><label class="form-label small mb-0" for="mfrConf">Per confermare scrivi il titolo del modulo: <strong><?php echo $h($f['titolo']); ?></strong></label>
                <input type="text" class="form-control form-control-sm" id="mfrConf" name="conferma_titolo" autocomplete="off" required></div>
            <button type="submit" name="elimina_tutte" value="1" class="btn btn-danger btn-sm fw-bold" data-confirm="Eliminare TUTTE le risposte del modulo e i loro allegati? Non si può annullare."><i class="fa fa-trash me-1" aria-hidden="true"></i>Elimina tutte</button>
        </form>
    </div></div>
    <?php endif; ?>
<?php endif; ?>
<?php require_once 'admin_footer.php'; ?>
