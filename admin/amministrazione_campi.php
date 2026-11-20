<?php
// amministrazione_campi.php - «Campi e colonne» della Richiesta di pubblicazione: personalizzare i campi che ci sono già nel modulo dei sette passi e le colonne del
// file Excel (tabella di monitoraggio). Due parti, ognuna col suo «Salva»: «Campi del form» (per passo: nome, testo di aiuto, obbligatorio, visibile, posizione, con
// l'anteprima del passo) e «Colonne del file Excel» (lettera originale, intestazione, a cura di, colore, tipo per le colonne degli operatori, visibile, posizione).
// Le regole, la validazione e il salvataggio stanno in App\Pubblicazioni\ConfigurazioneCampi (unica fonte per pagina, validazione, email, scheda, elenco, export):
// qui solo il modulo, gli errori accanto ai campi e la riga nel registro delle attività con le differenze («prima → dopo»). Si salva solo quello che cambia:
// «Torna all'originale» cancella le personalizzazioni di una riga, «Torna alle intestazioni originali» quelle di tutte le intestazioni.
// Due parti in più (AM5, App\Pubblicazioni\CampiAggiunti): «Campi nuovi del form» (si creano con il costruttore di MyForms: nome, tipo, scelte, obbligatorio, aiuto; in più il
// passo, la visibilità e se diventano una colonna del file Excel) e «Colonne nuove degli operatori» (intestazione, a cura di, colore, tipo, dopo quale colonna). Un campo o una
// colonna senza valori si elimina; con valori si può solo nascondere (qui si dice in quante richieste ci sono valori).
// Scheda del pannello Amministrazione: si apre da amministrazione.php?tab=campi, mai direttamente. Solo per chi può tutto il modulo
// (PermessiAmministrazione::puoTutto), ricontrollato qui anche per i POST (anche i «Torna all'originale»).
// $fase = 'azioni': salva / ripristina (riuscito = rimando; con errori la pagina si ridisegna con quanto scritto); $fase = 'vista': la pagina.
if (!defined('AMMINISTRAZIONE_PANNELLO')) { http_response_code(403); exit('Accesso negato.'); }
if (!$perm_amm->puoTutto($utente_admin)) nega_accesso();

use App\Pubblicazioni\CampiAggiunti;
use App\Pubblicazioni\ColonneMonitoraggio;
use App\Pubblicazioni\ConfigurazioneCampi;
use App\Pubblicazioni\ImpostazioniPubblicazioni;
use App\Pubblicazioni\PassiRichiesta;

require_once __DIR__ . '/parziali/costruttore_campi.php';
$app_cp = \App\Core\App::per($conn);
$cfg_cp = $app_cp->get(ConfigurazioneCampi::class);
$agg_cp = $app_cp->get(CampiAggiunti::class);
/** Le righe «Campo «X»: nome «A» → «B»» di ConfigurazioneCampi come dettagli del registro (etichetta => cosa è cambiato). @param list<string> $cambi @return array<string, string> */
$dettagli_cp = static function (array $cambi): array {
    $out = [];
    foreach ($cambi as $c) {
        [$k, $v] = explode(': ', $c, 2) + [1 => ''];
        $out[$k] = $v;
    }

    return $out;
};

if ($fase === 'azioni') {
    // ── Salva i campi del form ──
    if (isset($_POST['salva_campi'])) {
        $esito_cp = $cfg_cp->aggiornaCampi($_POST);
        if (!$esito_cp['errori']) {
            if ($esito_cp['cambi']) {
                registra_log_audit($conn, 'Richiesta di pubblicazione: campi del form modificati', $dettagli_cp($esito_cp['cambi']));
                flash_set('Campi del form salvati. Valgono da subito per le nuove richieste.', 'success');
            } else {
                flash_set('Non c\'era nulla da cambiare.', 'info');
            }
            admin_redirect("$base&tab=campi#campi-form");
        }
        // errori: la pagina si ridisegna con quello che è stato scritto, così non si perde
        $err_campi = $esito_cp['errori'];
        $post_campi = is_array($_POST['campo'] ?? null) ? $_POST['campo'] : [];
    }
    // ── Salva le colonne del file Excel ──
    if (isset($_POST['salva_colonne'])) {
        $esito_cp = $cfg_cp->aggiornaColonne($_POST);
        if (!$esito_cp['errori']) {
            if ($esito_cp['cambi']) {
                registra_log_audit($conn, 'Richiesta di pubblicazione: colonne del file Excel modificate', $dettagli_cp($esito_cp['cambi']));
                flash_set('Colonne del file Excel salvate. Valgono da subito per l\'elenco e per il file scaricato.', 'success');
            } else {
                flash_set('Non c\'era nulla da cambiare.', 'info');
            }
            admin_redirect("$base&tab=campi#colonne-excel");
        }
        $err_colonne = $esito_cp['errori'];
        $post_colonne = is_array($_POST['colonna'] ?? null) ? $_POST['colonna'] : [];
    }
    // ── Salva i campi nuovi del form ──
    if (isset($_POST['salva_campi_nuovi'])) {
        $esito_cx = $agg_cp->salvaCampi($_POST);
        if (!$esito_cx['errori']) {
            if ($esito_cx['cambi']) {
                registra_log_audit($conn, 'Richiesta di pubblicazione: campi nuovi del form modificati', $dettagli_cp($esito_cx['cambi']));
                flash_set('Campi nuovi del form salvati. Valgono da subito per le nuove richieste.', 'success');
            } else {
                flash_set('Non c\'era nulla da cambiare.', 'info');
            }
            admin_redirect("$base&tab=campi#campi-nuovi");
        }
        $err_cx = $esito_cx['errori'];
        $post_cx = $_POST;
    }
    // ── Salva le colonne nuove degli operatori ──
    if (isset($_POST['salva_colonne_nuove'])) {
        $esito_cn = $agg_cp->salvaColonne($_POST);
        if (!$esito_cn['errori']) {
            if ($esito_cn['cambi']) {
                registra_log_audit($conn, 'Richiesta di pubblicazione: colonne nuove degli operatori modificate', $dettagli_cp($esito_cn['cambi']));
                flash_set('Colonne nuove salvate. Valgono da subito per l\'elenco, la scheda e il file scaricato.', 'success');
            } else {
                flash_set('Non c\'era nulla da cambiare.', 'info');
            }
            admin_redirect("$base&tab=campi#colonne-nuove");
        }
        $err_cn = $esito_cn['errori'];
        $post_cn = is_array($_POST['ycol'] ?? null) ? $_POST['ycol'] : [];
    }
    // ── Elimina una colonna nuova (solo se non ha valori) ──
    if (isset($_POST['elimina_colonna_nuova'])) {
        $esito_el = $agg_cp->eliminaColonna((string)$_POST['elimina_colonna_nuova']);
        if ($esito_el->riuscito) {
            registra_log_audit($conn, 'Richiesta di pubblicazione: colonna nuova eliminata', ['Colonna' => $esito_el->messaggio]);
        }
        flash_set($esito_el->messaggio, $esito_el->riuscito ? 'success' : 'danger');
        admin_redirect("$base&tab=campi#colonne-nuove");
    }
    // ── «Torna all'originale» per un campo, per una colonna, per tutte le intestazioni ──
    if (isset($_POST['ripristina_campo'])) {
        $chiave_cp = (string)$_POST['ripristina_campo'];
        $fatto_cp = isset(ConfigurazioneCampi::CAMPI[$chiave_cp]) ? $cfg_cp->ripristinaCampo($chiave_cp) : [];
        if ($fatto_cp) {
            registra_log_audit($conn, 'Richiesta di pubblicazione: campo del form tornato all\'originale', $dettagli_cp($fatto_cp));
            flash_set('Il campo «' . ConfigurazioneCampi::CAMPI[$chiave_cp]['etichetta'] . '» è tornato com\'era all\'origine.', 'success');
        } else {
            flash_set('Quel campo era già com\'era all\'origine.', 'info');
        }
        admin_redirect("$base&tab=campi#campi-form");
    }
    if (isset($_POST['ripristina_colonna'])) {
        $chiave_cp = (string)$_POST['ripristina_colonna'];
        $fatto_cp = ColonneMonitoraggio::perChiave($chiave_cp) !== null ? $cfg_cp->ripristinaColonna($chiave_cp) : [];
        if ($fatto_cp) {
            registra_log_audit($conn, 'Richiesta di pubblicazione: colonna del file Excel tornata all\'originale', $dettagli_cp($fatto_cp));
            flash_set('La colonna «' . ColonneMonitoraggio::etichetta($chiave_cp) . '» è tornata com\'era all\'origine.', 'success');
        } else {
            flash_set('Quella colonna era già com\'era all\'origine.', 'info');
        }
        admin_redirect("$base&tab=campi#colonne-excel");
    }
    if (isset($_POST['ripristina_intestazioni'])) {
        $n_cp = $cfg_cp->ripristinaIntestazioni();
        if ($n_cp > 0) {
            registra_log_audit($conn, 'Richiesta di pubblicazione: intestazioni del file Excel tornate originali', ['Intestazioni ripristinate' => $n_cp]);
            flash_set($n_cp === 1 ? 'Una intestazione è tornata quella originale.' : $n_cp . ' intestazioni sono tornate quelle originali.', 'success');
        } else {
            flash_set('Le intestazioni erano già quelle originali.', 'info');
        }
        admin_redirect("$base&tab=campi#colonne-excel");
    }

    return;
}

// ── PAGINA ──
$err_campi = $err_campi ?? [];
$err_colonne = $err_colonne ?? [];
$err_cx = $err_cx ?? [];             // errori dei campi nuovi (chiave cx_<riga>, elimina_<chiave>, «_»)
$err_cn = $err_cn ?? [];             // errori delle colonne nuove (chiave ycol_<id>, «_»)
$post_cx = $post_cx ?? null;
$post_cn = $post_cn ?? null;
$post_campi = $post_campi ?? null;      // quanto scritto, se il salvataggio ha trovato errori
$post_colonne = $post_colonne ?? null;
$lim_cp = $app_cp->get(ImpostazioniPubblicazioni::class)->limiti();
$errore_cp = static fn (array $errori, string $k): string => isset($errori[$k]) ? '<div class="text-danger small fw-bold mt-1" role="alert" id="err_' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($errori[$k], ENT_QUOTES, 'UTF-8') . '</div>' : '';
$invalido_cp = static fn (array $errori, string $k): string => isset($errori[$k]) ? ' is-invalid" aria-invalid="true" aria-describedby="err_' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') : '';

// I campi: una riga per campo con quanto va mostrato (quello salvato, o quello scritto se il salvataggio ha trovato errori), raggruppati per passo
$gruppi_campi = [];
foreach ($cfg_cp->campi() as $c) {
    if (!empty($c['extra'])) { continue; } // i campi nuovi hanno la loro parte («Campi nuovi del form»)
    $k = $c['chiave'];
    $p = is_array($post_campi[$k] ?? null) ? $post_campi[$k] : null;
    $c['etichetta_mostrata'] = $p !== null ? (string)($p['etichetta'] ?? '') : $c['etichetta'];
    $c['aiuto_mostrato'] = $p !== null ? (string)($p['aiuto'] ?? '') : $c['aiuto'];
    // «Allegati» e «immagini» dicono se sono obbligatori le Impostazioni: la casella mostra quello, e non si cambia da qui
    $obb = $k === 'allegati' ? (bool)$lim_cp['allegati_obbligatori'] : ($k === 'immagini' ? false : $c['obbligatorio']);
    $c['obbligatorio_mostrato'] = $c['fisso_obb'] ? $obb : ($p !== null ? !empty($p['obbligatorio']) : $obb);
    $c['visibile_mostrato'] = $c['fisso_vis'] ? true : ($p !== null ? !empty($p['visibile']) : $c['visibile']);
    $c['ordine_mostrato'] = $p !== null ? (int)($p['ordine'] ?? 0) : 0;
    $gruppi_campi[$c['passo']][] = $c;
}
if ($post_campi !== null) {
    foreach ($gruppi_campi as &$g_cp) {
        $i_cp = 0;
        $g_cp = array_map(static function (array $c) use (&$i_cp): array { $c['_i'] = ++$i_cp; return $c; }, $g_cp);
        usort($g_cp, static fn (array $a, array $b): int => [$a['ordine_mostrato'], $a['_i']] <=> [$b['ordine_mostrato'], $b['_i']]);
    }
    unset($g_cp);
}

// Le colonne: tutte, anche quelle nascoste (qui si riaccendono)
$righe_colonne = [];
foreach ($cfg_cp->colonne(true) as $i => $c) {
    if (!empty($c['extra'])) { continue; } // le colonne nuove hanno la loro parte («Colonne nuove degli operatori»)
    $k = $c['chiave'];
    $p = is_array($post_colonne[$k] ?? null) ? $post_colonne[$k] : null;
    $c['intestazione_mostrata'] = $p !== null ? (string)($p['intestazione'] ?? '') : $c['intestazione'];
    $c['a_cura_di_mostrato'] = $p !== null ? (string)($p['a_cura_di'] ?? '') : $c['a_cura_di'];
    $c['colore_mostrato'] = $p !== null ? (string)($p['colore'] ?? '') : $c['colore'];
    $c['tipo_mostrato'] = $p !== null ? (string)($p['tipo'] ?? '') : $c['tipo'];
    $c['visibile_mostrato'] = $c['fissa'] ? true : ($p !== null ? !empty($p['visibile']) : $c['visibile']);
    $c['ordine_mostrato'] = $p !== null ? (int)($p['ordine'] ?? 0) : 0;
    $c['_i'] = $i + 1;
    $righe_colonne[] = $c;
}
if ($post_colonne !== null) {
    usort($righe_colonne, static fn (array $a, array $b): int => [$a['ordine_mostrato'], $a['_i']] <=> [$b['ordine_mostrato'], $b['_i']]);
}
$intestazioni_cambiate = count(array_filter($righe_colonne, static fn (array $c): bool => $c['intestazione'] !== $c['predefinita']['intestazione']));
$etichetta_originale = static fn (array $c): string => ($c['lettera'] !== '' ? $c['lettera'] . ' · ' : '') . ColonneMonitoraggio::etichetta($c['chiave']);

/** L'anteprima di un passo: i campi come li vedrebbe chi compila (o l'avviso che il passo non compare). @param list<array<string, mixed>> $campi */
$anteprima_cp = static function (array $campi) use ($h): string {
    $visibili = array_filter($campi, static fn (array $c): bool => $c['visibile_mostrato']);
    if (!$visibili) {
        return '<p class="small text-secondary mb-0 pc-anteprima-vuota">Con queste scelte il passo non ha campi da mostrare: chi compila lo salta e la barra dei passi si accorcia.</p>';
    }
    $out = '';
    foreach ($visibili as $c) {
        $marca = $c['chiave'] === 'richiedente' ? '' : ($c['obbligatorio_mostrato'] ? ' <span class="text-danger">*</span>' : ' <span class="fw-normal text-secondary">(facoltativo)</span>');
        $out .= '<div class="pc-anteprima-campo"><strong>' . $h($c['etichetta_mostrata']) . '</strong>' . $marca
            . ($c['aiuto_mostrato'] !== '' ? '<div class="small text-secondary">' . $h($c['aiuto_mostrato']) . '</div>' : '') . '</div>';
    }

    return $out;
};

// ── Campi nuovi del form (AM5): le righe del costruttore di MyForms, e per ognuna le impostazioni in più (passo, visibile, colonna Excel) ──
$conteggi_cp = $agg_cp->richiesteConValori();
$righe_cx = [];   // quello che si mostra: [chiave, etichetta, tipo, opzioni, obbligatorio, aiuto, passo, visibile, in_excel, intestazione, valori, tipo_originale, errore]
$tipo_per_chiave = [];
foreach ($cfg_cp->campiExtra() as $x) {
    $tipo_per_chiave[$x['chiave']] = $x['tipo'];
}
if ($post_cx !== null) {
    // il salvataggio ha trovato errori: si rimette quello che era stato scritto (le righe senza nome non ci sono più, come per il costruttore)
    foreach ((array)($post_cx['cx_etichetta'] ?? []) as $i => $et) {
        if (trim((string)$et) === '') { continue; }
        $chiave = (string)($post_cx['cx_chiave'][$i] ?? '');
        $chiave = isset($tipo_per_chiave[$chiave]) ? $chiave : '';
        $righe_cx[] = [
            'chiave' => $chiave, 'etichetta' => trim((string)$et), 'tipo' => (string)($post_cx['cx_tipo'][$i] ?? 'text'), 'opzioni' => (string)($post_cx['cx_opzioni'][$i] ?? ''),
            'obbligatorio' => ($post_cx['cx_obbl'][$i] ?? '0') === '1', 'aiuto' => (string)($post_cx['cx_aiuto'][$i] ?? ''), 'passo' => (int)($post_cx['cx_passo'][$i] ?? 6),
            'visibile' => ($post_cx['cx_vis'][$i] ?? '0') === '1', 'in_excel' => ($post_cx['cx_excel'][$i] ?? '0') === '1', 'intestazione' => (string)($post_cx['cx_intest'][$i] ?? ''),
            'valori' => (int)($conteggi_cp['campi'][$chiave] ?? 0), 'tipo_originale' => (string)($tipo_per_chiave[$chiave] ?? ''), 'errore' => (string)($err_cx['cx_' . $i] ?? ''),
        ];
    }
} else {
    foreach ($cfg_cp->campiExtra() as $x) {
        $righe_cx[] = [
            'chiave' => $x['chiave'], 'etichetta' => $x['etichetta'], 'tipo' => $x['tipo'], 'opzioni' => implode(', ', $x['opzioni']), 'obbligatorio' => $x['obbligatorio'], 'aiuto' => $x['aiuto'],
            'passo' => $x['passo'], 'visibile' => $x['visibile'], 'in_excel' => $x['in_excel'], 'intestazione' => $x['in_excel'] ? $x['intestazione_excel'] : '',
            'valori' => (int)($conteggi_cp['campi'][$x['chiave']] ?? 0), 'tipo_originale' => $x['tipo'], 'errore' => '',
        ];
    }
}
$dati_cx = ['passi' => PassiRichiesta::PASSI, 'righe' => $righe_cx, 'predefinito' => ['passo' => 6]];

// ── Colonne nuove degli operatori (AM5): le righe di ycol[<chiave>|nuova_N][...] ──
$righe_cn = [];
$colori_cn = ConfigurazioneCampi::COLORE_COLONNA_CAMPO;
$numero_nuove_cn = 0;
foreach ($cfg_cp->colonneNuove() as $c) {
    $p = is_array($post_cn[$c['chiave']] ?? null) ? $post_cn[$c['chiave']] : null;
    $righe_cn[] = [
        'id' => $c['chiave'], 'esiste' => true, 'valori' => (int)($conteggi_cp['colonne'][$c['chiave']] ?? 0),
        'intestazione' => $p !== null ? (string)($p['intestazione'] ?? '') : $c['intestazione'], 'a_cura_di' => $p !== null ? (string)($p['a_cura_di'] ?? '') : $c['a_cura_di'],
        'colore' => $p !== null ? (string)($p['colore'] ?? '') : $c['colore'], 'tipo' => $p !== null ? (string)($p['tipo'] ?? '') : $c['tipo'],
        'dopo' => $p !== null ? (string)($p['dopo'] ?? '') : $c['dopo'], 'visibile' => $p !== null ? !empty($p['visibile']) : $c['visibile'],
    ];
}
foreach ((array)$post_cn as $id => $p) {
    // righe nuove (non ancora salvate) ricordate dopo un errore
    if (is_array($p) && preg_match('/^nuova_(\d{1,6})$/', (string)$id, $m)) {
        $numero_nuove_cn = max($numero_nuove_cn, (int)$m[1]);
        $righe_cn[] = [
            'id' => (string)$id, 'esiste' => false, 'valori' => 0, 'intestazione' => (string)($p['intestazione'] ?? ''), 'a_cura_di' => (string)($p['a_cura_di'] ?? ''),
            'colore' => (string)($p['colore'] ?? $colori_cn), 'tipo' => (string)($p['tipo'] ?? 'testo'), 'dopo' => (string)($p['dopo'] ?? ''), 'visibile' => !empty($p['visibile']),
        ];
    }
}
// le colonne dopo cui se ne può mettere una nuova: tutte quelle del file (anche le nascoste)
$posti_cn = [];
foreach ($cfg_cp->colonne(true) as $c) {
    $posti_cn[] = ['chiave' => $c['chiave'], 'nome' => (empty($c['extra']) ? $etichetta_originale($c) : $c['intestazione']) . ($c['visibile'] ? '' : ' (nascosta)')];
}
/** Una riga delle colonne nuove (anche il modello da copiare, con «__ID__» al posto della chiave). @param array<string, mixed> $r */
$riga_cn_html = static function (array $r, array $posti, string $errore) use ($h): string {
    $id = $r['id'];
    $n = 'ycol[' . $id . ']';
    ob_start(); ?>
        <div class="pc-riga cn-riga<?php echo $r['visibile'] ? '' : ' pc-spenta'; ?>" data-id="<?php echo $h($id); ?>">
            <div class="pc-riga-testa">
                <span class="pc-lettera cn-pallino" style="background: <?php echo $h($r['colore']); ?>; color: <?php echo $h(colore_testo_su($r['colore'])); ?>;" aria-hidden="true">—</span>
                <strong class="small cn-titolo"><?php echo $h($r['intestazione'] !== '' ? $r['intestazione'] : 'Colonna nuova'); ?></strong>
                <?php echo $r['esiste'] ? '' : pn_stato('Da salvare', 'info', 'fa-plus'); ?>
                <?php if ($r['valori'] > 0): ?><span class="small text-secondary"><i class="fa fa-circle-info me-1" aria-hidden="true"></i>Ha valori in <?php echo $r['valori'] === 1 ? '1 richiesta' : (int)$r['valori'] . ' richieste'; ?></span><?php endif; ?>
            </div>
            <div class="row g-2">
                <div class="col-lg-4">
                    <label class="form-label small fw-bold mb-1">Intestazione nel file</label>
                    <input type="text" class="form-control form-control-sm cn-intestazione" name="<?php echo $h($n); ?>[intestazione]" maxlength="<?php echo (int)ConfigurazioneCampi::MAX_INTESTAZIONE; ?>" value="<?php echo $h($r['intestazione']); ?>">
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small fw-bold mb-1">A cura di</label>
                    <input type="text" class="form-control form-control-sm" name="<?php echo $h($n); ?>[a_cura_di]" maxlength="<?php echo (int)ConfigurazioneCampi::MAX_A_CURA_DI; ?>" value="<?php echo $h($r['a_cura_di']); ?>">
                </div>
                <div class="col-sm-4 col-lg-2">
                    <label class="form-label small fw-bold mb-1">Tipo</label>
                    <select class="form-select form-select-sm" name="<?php echo $h($n); ?>[tipo]">
                        <?php foreach (ConfigurazioneCampi::TIPI as $t => $nome_t): ?><option value="<?php echo $h($t); ?>"<?php echo $r['tipo'] === $t ? ' selected' : ''; ?>><?php echo $h($nome_t); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-2 col-lg-1">
                    <label class="form-label small fw-bold mb-1">Colore</label>
                    <input type="color" class="form-control form-control-color form-control-sm w-100 cn-colore" name="<?php echo $h($n); ?>[colore]" value="<?php echo $h(strtolower($r['colore'])); ?>" title="Colore dell'intestazione">
                </div>
                <div class="col-lg-2">
                    <label class="form-label small fw-bold mb-1">Sta dopo la colonna</label>
                    <select class="form-select form-select-sm" name="<?php echo $h($n); ?>[dopo]">
                        <option value=""<?php echo $r['dopo'] === '' ? ' selected' : ''; ?>>— in fondo —</option>
                        <?php foreach ($posti as $po): if ($po['chiave'] === $id) { continue; } ?><option value="<?php echo $h($po['chiave']); ?>"<?php echo $r['dopo'] === $po['chiave'] ? ' selected' : ''; ?>><?php echo $h($po['nome']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php if ($errore !== ''): ?><div class="text-danger small fw-bold mt-1" role="alert"><?php echo $h($errore); ?></div><?php endif; ?>
            <div class="d-flex flex-wrap align-items-center gap-3 mt-2">
                <div class="form-check mb-0">
                    <label class="form-check-label small"><input class="form-check-input cn-vis" type="checkbox" name="<?php echo $h($n); ?>[visibile]" value="1"<?php echo $r['visibile'] ? ' checked' : ''; ?>> Visibile</label>
                </div>
                <?php if ($r['esiste'] && $r['valori'] > 0): ?>
                    <span class="small text-secondary ms-auto"><i class="fa fa-lock me-1" aria-hidden="true"></i>Non si può eliminare perché ha valori: puoi solo nasconderla (i valori restano).</span>
                <?php elseif ($r['esiste']): ?>
                    <button type="submit" form="cnElimina" name="elimina_colonna_nuova" value="<?php echo $h($id); ?>" class="btn btn-sm btn-outline-danger ms-auto cn-elimina" formnovalidate data-nome="<?php echo $h($r['intestazione']); ?>"><i class="fa fa-trash me-1" aria-hidden="true"></i>Elimina<span class="visually-hidden">: <?php echo $h($r['intestazione']); ?></span></button>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto cn-togli"><i class="fa fa-times me-1" aria-hidden="true"></i>Togli questa riga</button>
                <?php endif; ?>
            </div>
        </div>
    <?php
    return (string)ob_get_clean();
};
?>
<?php if ($err_campi || $err_colonne || $err_cx || $err_cn): ?>
    <div class="alert alert-danger" role="alert"><strong>Le modifiche non sono state salvate.</strong> Correggi quello che è segnato qui sotto:
        <ul class="mb-0"><?php foreach (array_merge($err_campi, $err_colonne, $err_cx, $err_cn) as $m): ?><li><?php echo $h($m); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h2 class="h5 fw-bold mb-0"><i class="fa fa-table-list me-1" aria-hidden="true"></i>Campi e colonne della richiesta di pubblicazione</h2>
        <span class="ms-auto d-flex flex-wrap gap-1">
            <a class="btn btn-sm btn-outline-secondary py-0" href="../richiesta_pubblicazione.php" target="_blank" rel="noopener"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Pagina pubblica<span class="visually-hidden"> (si apre in una nuova scheda)</span></a>
            <a class="btn btn-sm btn-outline-primary py-0" href="pubblicazioni.php?vista=monitoraggio"><i class="fa fa-table me-1" aria-hidden="true"></i>Tabella di monitoraggio</a>
        </span>
    </div>
    <p class="small text-secondary mb-2">Qui scegli come si chiamano i campi del modulo e le colonne del file Excel, quali sono obbligatori e in che ordine stanno. Valgono da subito per le nuove richieste; quelle già arrivate conservano quello che è stato scritto. Quanti caratteri e quanti file, e chi può inviare la richiesta, si cambiano in <a href="<?php echo $h($base); ?>&amp;tab=impostazioni">Impostazioni</a>.</p>
    <ul class="small text-secondary mb-0">
        <li><strong>Un campo nascosto</strong> non si chiede, non si controlla e la sua colonna del file Excel resta vuota per le nuove richieste (quelle già arrivate tengono i loro valori).</li>
        <li><strong>Un campo facoltativo</strong> si controlla solo se è compilato.</li>
        <li><strong>«Torna all'originale»</strong> toglie le tue modifiche a quella riga e rimette quello che c'era all'inizio.</li>
    </ul>
</div></div>

<!-- Moduli dei «Torna all'originale»: i pulsanti delle righe puntano qui con l'attributo form, così non diventano il pulsante di invio dei campi (premendo Invio si salva) -->
<form method="POST" action="<?php echo $h($base); ?>&amp;tab=campi" id="pcRipCampi" class="d-none"><?php csrf_field(); ?></form>
<form method="POST" action="<?php echo $h($base); ?>&amp;tab=campi" id="pcRipColonne" class="d-none"><?php csrf_field(); ?></form>

<div id="pcPagina">
<div class="visually-hidden" role="status" aria-live="polite" id="pcAnnuncio"></div>

<!-- ═══ Campi del form ═══ -->
<form method="POST" action="<?php echo $h($base); ?>&amp;tab=campi" class="card border-0 shadow-sm mb-4" id="campi-form"><div class="card-body">
    <?php csrf_field(); ?>
    <h2 class="h5 fw-bold mb-1"><i class="fa fa-pen-to-square me-1" aria-hidden="true"></i>Campi del form</h2>
    <p class="small text-secondary mb-3">I passi del modulo che compila chi invia la richiesta. Il primo passo (le note operative) e l'ultimo (il riepilogo) ci sono sempre; un passo senza campi visibili si salta.</p>

    <?php foreach ($gruppi_campi as $passo_cp => $campi_cp): ?>
        <fieldset class="pc-passo mb-3">
            <legend class="h6 fw-bold mb-2">Passo <?php echo (int)$passo_cp; ?> · <?php echo $h(PassiRichiesta::PASSI[$passo_cp] ?? ''); ?></legend>
            <div class="pc-gruppo" data-gruppo="passo-<?php echo (int)$passo_cp; ?>">
            <?php foreach ($campi_cp as $pos_cp => $c):
                $k = $c['chiave'];
                $id_cp = 'pcc_' . $k; ?>
                <div class="pc-riga<?php echo $c['visibile_mostrato'] ? '' : ' pc-spenta'; ?>" data-chiave="<?php echo $h($k); ?>" data-nome="<?php echo $h($c['predefinito']['etichetta']); ?>">
                    <div class="pc-riga-testa">
                        <strong class="small"><?php echo $h($c['predefinito']['etichetta']); ?></strong>
                        <?php echo $c['modificato'] ? pn_stato('Modificato', 'info', 'fa-pen') : '<span class="small text-secondary">Come all\'origine</span>'; ?>
                        <span class="ms-auto pc-muovi" hidden>
                            <button type="button" class="btn btn-sm btn-outline-secondary pc-su" aria-label="Sposta su: <?php echo $h($c['predefinito']['etichetta']); ?>"><i class="fa fa-arrow-up" aria-hidden="true"></i><span class="visually-hidden">Su</span></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary pc-giu" aria-label="Sposta giù: <?php echo $h($c['predefinito']['etichetta']); ?>"><i class="fa fa-arrow-down" aria-hidden="true"></i><span class="visually-hidden">Giù</span></button>
                        </span>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold mb-1" for="<?php echo $h($id_cp); ?>_etichetta">Nome del campo</label>
                            <input type="text" class="form-control form-control-sm pc-etichetta<?php echo $invalido_cp($err_campi, 'campo_' . $k); ?>" id="<?php echo $h($id_cp); ?>_etichetta" name="campo[<?php echo $h($k); ?>][etichetta]" maxlength="<?php echo (int)ConfigurazioneCampi::MAX_ETICHETTA; ?>" value="<?php echo $h($c['etichetta_mostrata']); ?>" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-bold mb-1" for="<?php echo $h($id_cp); ?>_aiuto">Testo di aiuto <span class="fw-normal text-secondary">(sotto il campo; può restare vuoto)</span></label>
                            <textarea class="form-control form-control-sm pc-aiuto" id="<?php echo $h($id_cp); ?>_aiuto" name="campo[<?php echo $h($k); ?>][aiuto]" rows="2" maxlength="<?php echo (int)ConfigurazioneCampi::MAX_AIUTO; ?>"><?php echo $h($c['aiuto_mostrato']); ?></textarea>
                        </div>
                    </div>
                    <?php echo $errore_cp($err_campi, 'campo_' . $k); ?>
                    <div class="d-flex flex-wrap align-items-center gap-3 mt-2">
                        <div class="form-check mb-0">
                            <input class="form-check-input pc-obb" type="checkbox" id="<?php echo $h($id_cp); ?>_obb" name="campo[<?php echo $h($k); ?>][obbligatorio]" value="1"<?php echo $c['obbligatorio_mostrato'] ? ' checked' : ''; ?><?php echo $c['fisso_obb'] ? ' disabled' : ''; ?>>
                            <label class="form-check-label small" for="<?php echo $h($id_cp); ?>_obb">Obbligatorio</label>
                        </div>
                        <div class="form-check mb-0">
                            <input class="form-check-input pc-vis" type="checkbox" id="<?php echo $h($id_cp); ?>_vis" name="campo[<?php echo $h($k); ?>][visibile]" value="1"<?php echo $c['visibile_mostrato'] ? ' checked' : ''; ?><?php echo $c['fisso_vis'] ? ' disabled' : ''; ?>>
                            <label class="form-check-label small" for="<?php echo $h($id_cp); ?>_vis">Visibile</label>
                        </div>
                        <?php if ($c['nota'] !== ''): ?>
                            <span class="small text-secondary"><i class="fa fa-lock me-1" aria-hidden="true"></i><?php echo $h($c['nota']); ?><?php if (str_contains($c['nota'], 'Impostazioni')): ?> <a href="<?php echo $h($base); ?>&amp;tab=impostazioni">Vai alle Impostazioni</a>.<?php endif; ?></span>
                        <?php endif; ?>
                        <?php if ($c['modificato']): ?>
                            <button type="submit" form="pcRipCampi" name="ripristina_campo" value="<?php echo $h($k); ?>" class="btn btn-sm btn-outline-secondary ms-auto" formnovalidate><i class="fa fa-rotate-left me-1" aria-hidden="true"></i>Torna all'originale<span class="visually-hidden">: <?php echo $h($c['predefinito']['etichetta']); ?></span></button>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" class="pc-ordine" name="campo[<?php echo $h($k); ?>][ordine]" value="<?php echo (int)($pos_cp + 1); ?>">
                </div>
            <?php endforeach; ?>
            </div>
            <details class="pc-anteprima mt-2" open>
                <summary class="small fw-bold">Anteprima del passo <?php echo (int)$passo_cp; ?></summary>
                <div class="pc-anteprima-corpo" data-anteprima-per="passo-<?php echo (int)$passo_cp; ?>"><?php echo $anteprima_cp($campi_cp); // già «escapata» ?></div>
            </details>
        </fieldset>
    <?php endforeach; ?>

    <div class="pn-salva">
        <button type="submit" name="salva_campi" value="1" class="btn btn-primary fw-bold"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva i campi del form</button>
        <a href="<?php echo $h($base); ?>&amp;tab=campi#campi-form" class="btn btn-outline-secondary">Annulla</a>
    </div>
</div></form>

<!-- ═══ Campi nuovi del form ═══ -->
<form method="POST" action="<?php echo $h($base); ?>&amp;tab=campi" class="card border-0 shadow-sm mb-4" id="campi-nuovi"><div class="card-body">
    <?php csrf_field(); ?>
    <h2 class="h5 fw-bold mb-1"><i class="fa fa-square-plus me-1" aria-hidden="true"></i>Campi nuovi del form</h2>
    <p class="small text-secondary mb-2">Aggiungi altre domande al modulo di chi invia la richiesta. Si creano come in MyForms: nome, tipo, scelte (per «scelta singola» e «caselle», separate da virgola), obbligatorio e testo di aiuto. In più scegli in quale passo compaiono (in fondo al passo) e se diventano una colonna del file Excel (con la sua intestazione, subito dopo le altre colonne dello stesso passo). Senza questa spunta il valore resta solo nella scheda e nelle email.</p>
    <ul class="small text-secondary mb-3">
        <li>Si possono usare: testo, testo lungo, scelta singola, caselle, data, numero, sì/no, indirizzo web. Non ci sono i file e non ci sono le condizioni.</li>
        <li><strong>Per eliminare un campo</strong> toglilo con la <i class="fa fa-times" aria-hidden="true"></i> e salva: si può solo se nessuna richiesta ha risposto. Se ci sono risposte puoi solo nasconderlo (togli la spunta «Visibile»): i valori restano.</li>
        <li>Al massimo <?php echo (int)CampiAggiunti::MAX_CAMPI; ?> campi nuovi.</li>
    </ul>
    <div id="cx-box">
        <?php echo html_costruttore_campi(CampiAggiunti::PREFISSO, array_map(static fn (array $r): array => ['etichetta' => $r['etichetta'], 'tipo' => $r['tipo'], 'opzioni' => $r['opzioni'], 'obbligatorio' => $r['obbligatorio'], 'aiuto' => $r['aiuto']], $righe_cx), ConfigurazioneCampi::TIPI_CAMPO_EXTRA); ?>
    </div>
    <script type="application/json" id="cx-dati"><?php echo json_encode($dati_cx, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?></script>
    <div class="pn-salva">
        <button type="submit" name="salva_campi_nuovi" value="1" class="btn btn-primary fw-bold"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva i campi nuovi</button>
        <a href="<?php echo $h($base); ?>&amp;tab=campi#campi-nuovi" class="btn btn-outline-secondary">Annulla</a>
    </div>
</div></form>

<!-- ═══ Colonne del file Excel ═══ -->
<form method="POST" action="<?php echo $h($base); ?>&amp;tab=campi" class="card border-0 shadow-sm mb-3" id="colonne-excel"><div class="card-body">
    <?php csrf_field(); ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h2 class="h5 fw-bold mb-0"><i class="fa fa-file-excel me-1" aria-hidden="true"></i>Colonne del file Excel</h2>
        <button type="submit" form="pcRipColonne" name="ripristina_intestazioni" value="1" class="btn btn-sm btn-outline-secondary ms-auto" id="pcRipInt" formnovalidate<?php echo $intestazioni_cambiate ? '' : ' disabled'; ?>><i class="fa fa-rotate-left me-1" aria-hidden="true"></i>Torna alle intestazioni originali</button>
    </div>
    <div class="alert alert-warning small py-2" role="note"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Se cambi le intestazioni, il file scaricato non sarà più uguale alla Tabella di monitoraggio originale.</div>
    <p class="small text-secondary mb-3">Le colonne della tabella di monitoraggio, nell'ordine in cui compaiono nell'elenco e nel file Excel, PDF e CSV scaricato. La lettera è quella del file Excel originale. Una colonna nascosta non esce dal file ma i suoi valori restano e tornano se la riaccendi. «A cura di» serve a raggruppare le colonne nella scheda di una richiesta: se vuoi che si veda anche nel file, scrivilo nell'intestazione.</p>

    <div class="pc-gruppo" data-gruppo="colonne">
    <?php foreach ($righe_colonne as $pos_cp => $c):
        $k = $c['chiave'];
        $id_cp = 'pcl_' . $k;
        $da_campo_nascosto = $cfg_cp->colonnaDaCampoNascosto($k); ?>
        <div class="pc-riga<?php echo $c['visibile_mostrato'] ? '' : ' pc-spenta'; ?>" data-chiave="<?php echo $h($k); ?>" data-nome="<?php echo $h($etichetta_originale($c)); ?>">
            <div class="pc-riga-testa">
                <span class="pc-lettera" style="background: <?php echo $h($c['colore_mostrato'] !== '' ? $c['colore_mostrato'] : $c['colore']); ?>; color: <?php echo $h(colore_testo_su($c['colore_mostrato'] !== '' ? $c['colore_mostrato'] : $c['colore'])); ?>;" title="Lettera della colonna nel file Excel originale"><?php echo $h($c['lettera'] !== '' ? $c['lettera'] : '—'); ?></span>
                <strong class="small"><?php echo $h(ColonneMonitoraggio::etichetta($k)); ?></strong>
                <?php echo $c['modificata'] ? pn_stato('Modificata', 'info', 'fa-pen') : '<span class="small text-secondary">Come all\'origine</span>'; ?>
                <?php if (!$c['visibile_mostrato']): ?><?php echo pn_stato('Nascosta', 'spento', 'fa-eye-slash'); ?><?php endif; ?>
                <span class="ms-auto pc-muovi" hidden>
                    <button type="button" class="btn btn-sm btn-outline-secondary pc-su" aria-label="Sposta su: <?php echo $h($etichetta_originale($c)); ?>"><i class="fa fa-arrow-up" aria-hidden="true"></i><span class="visually-hidden">Su</span></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary pc-giu" aria-label="Sposta giù: <?php echo $h($etichetta_originale($c)); ?>"><i class="fa fa-arrow-down" aria-hidden="true"></i><span class="visually-hidden">Giù</span></button>
                </span>
            </div>
            <div class="row g-2">
                <div class="col-lg-6">
                    <label class="form-label small fw-bold mb-1" for="<?php echo $h($id_cp); ?>_intestazione">Intestazione nel file</label>
                    <input type="text" class="form-control form-control-sm<?php echo $invalido_cp($err_colonne, 'colonna_' . $k); ?>" id="<?php echo $h($id_cp); ?>_intestazione" name="colonna[<?php echo $h($k); ?>][intestazione]" maxlength="<?php echo (int)ConfigurazioneCampi::MAX_INTESTAZIONE; ?>" value="<?php echo $h($c['intestazione_mostrata']); ?>" required>
                </div>
                <?php if ($c['operatori']): ?>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label small fw-bold mb-1" for="<?php echo $h($id_cp); ?>_cura">A cura di</label>
                        <input type="text" class="form-control form-control-sm" id="<?php echo $h($id_cp); ?>_cura" name="colonna[<?php echo $h($k); ?>][a_cura_di]" maxlength="<?php echo (int)ConfigurazioneCampi::MAX_A_CURA_DI; ?>" value="<?php echo $h($c['a_cura_di_mostrato']); ?>" required>
                    </div>
                    <div class="col-sm-4 col-lg-2">
                        <label class="form-label small fw-bold mb-1" for="<?php echo $h($id_cp); ?>_tipo">Tipo</label>
                        <select class="form-select form-select-sm" id="<?php echo $h($id_cp); ?>_tipo" name="colonna[<?php echo $h($k); ?>][tipo]">
                            <?php foreach (ConfigurazioneCampi::TIPI as $t_cp => $nome_t): ?>
                                <option value="<?php echo $h($t_cp); ?>"<?php echo $c['tipo_mostrato'] === $t_cp ? ' selected' : ''; ?>><?php echo $h($nome_t); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="col-sm-6 col-lg-5 d-flex align-items-end"><span class="small text-secondary"><i class="fa fa-lock me-1" aria-hidden="true"></i>La scrive chi invia la richiesta: il tipo non si cambia.</span></div>
                <?php endif; ?>
                <div class="col-sm-2 col-lg-1">
                    <label class="form-label small fw-bold mb-1" for="<?php echo $h($id_cp); ?>_colore">Colore</label>
                    <input type="color" class="form-control form-control-color form-control-sm w-100" id="<?php echo $h($id_cp); ?>_colore" name="colonna[<?php echo $h($k); ?>][colore]" value="<?php echo $h(strtolower($c['colore_mostrato'] !== '' ? $c['colore_mostrato'] : $c['colore'])); ?>" title="Colore dell'intestazione">
                </div>
            </div>
            <?php echo $errore_cp($err_colonne, 'colonna_' . $k); ?>
            <div class="d-flex flex-wrap align-items-center gap-3 mt-2">
                <div class="form-check mb-0">
                    <input class="form-check-input pc-vis" type="checkbox" id="<?php echo $h($id_cp); ?>_vis" name="colonna[<?php echo $h($k); ?>][visibile]" value="1"<?php echo $c['visibile_mostrato'] ? ' checked' : ''; ?><?php echo $c['fissa'] ? ' disabled' : ''; ?>>
                    <label class="form-check-label small" for="<?php echo $h($id_cp); ?>_vis">Visibile</label>
                </div>
                <?php if ($c['fissa']): ?><span class="small text-secondary"><i class="fa fa-lock me-1" aria-hidden="true"></i>Sempre presente nel file.</span><?php endif; ?>
                <?php if ($da_campo_nascosto): ?><span class="small text-secondary"><i class="fa fa-eye-slash me-1" aria-hidden="true"></i>Il campo del form è nascosto: per le nuove richieste questa colonna resta vuota.</span><?php endif; ?>
                <?php if ($c['modificata']): ?>
                    <button type="submit" form="pcRipColonne" name="ripristina_colonna" value="<?php echo $h($k); ?>" class="btn btn-sm btn-outline-secondary ms-auto" formnovalidate><i class="fa fa-rotate-left me-1" aria-hidden="true"></i>Torna all'originale<span class="visually-hidden">: <?php echo $h($etichetta_originale($c)); ?></span></button>
                <?php endif; ?>
            </div>
            <input type="hidden" class="pc-ordine" name="colonna[<?php echo $h($k); ?>][ordine]" value="<?php echo (int)($pos_cp + 1); ?>">
        </div>
    <?php endforeach; ?>
    </div>

    <div class="pn-salva">
        <button type="submit" name="salva_colonne" value="1" class="btn btn-primary fw-bold"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva le colonne del file Excel</button>
        <a href="<?php echo $h($base); ?>&amp;tab=campi#colonne-excel" class="btn btn-outline-secondary">Annulla</a>
    </div>
</div></form>

<!-- Modulo del «Elimina» di una colonna nuova: i pulsanti delle righe puntano qui con l'attributo form -->
<form method="POST" action="<?php echo $h($base); ?>&amp;tab=campi" id="cnElimina" class="d-none"><?php csrf_field(); ?></form>

<!-- ═══ Colonne nuove degli operatori ═══ -->
<form method="POST" action="<?php echo $h($base); ?>&amp;tab=campi" class="card border-0 shadow-sm mb-3" id="colonne-nuove"><div class="card-body">
    <?php csrf_field(); ?>
    <h2 class="h5 fw-bold mb-1"><i class="fa fa-table-columns me-1" aria-hidden="true"></i>Colonne nuove degli operatori</h2>
    <p class="small text-secondary mb-2">Aggiungi colonne che compilano gli operatori nella scheda di ogni richiesta, come le altre colonne «a cura di». Scegli l'intestazione, a cura di chi è, il colore, il tipo (testo libero, SI / NO o numero) e dopo quale colonna sta nel file Excel. Una colonna visibile e ancora vuota conta come «da completare».</p>
    <ul class="small text-secondary mb-3">
        <li><strong>Per eliminarla</strong> usa «Elimina»: si può solo se nessuna richiesta ha un valore in quella colonna. Se ci sono valori puoi solo nasconderla (togli la spunta «Visibile»): i valori restano e tornano se la riaccendi.</li>
        <li>Al massimo <?php echo (int)CampiAggiunti::MAX_COLONNE; ?> colonne nuove.</li>
    </ul>
    <div id="cn-righe">
    <?php foreach ($righe_cn as $r_cn) { echo $riga_cn_html($r_cn, $posti_cn, (string)($err_cn['ycol_' . $r_cn['id']] ?? '')); } ?>
    </div>
    <p class="small text-secondary mb-2" id="cn-vuoto"<?php echo $righe_cn ? ' hidden' : ''; ?>>Non ci sono colonne nuove.</p>
    <template id="cn-modello"><?php echo $riga_cn_html(['id' => '__ID__', 'esiste' => false, 'valori' => 0, 'intestazione' => '', 'a_cura_di' => '', 'colore' => $colori_cn, 'tipo' => 'testo', 'dopo' => '', 'visibile' => true], $posti_cn, ''); ?></template>
    <button type="button" class="btn btn-sm btn-outline-secondary fw-bold mb-2" id="cn-aggiungi" data-prossimo="<?php echo (int)$numero_nuove_cn + 1; ?>"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi colonna</button>
    <div class="pn-salva">
        <button type="submit" name="salva_colonne_nuove" value="1" class="btn btn-primary fw-bold"><i class="fa fa-floppy-disk me-1" aria-hidden="true"></i>Salva le colonne nuove</button>
        <a href="<?php echo $h($base); ?>&amp;tab=campi#colonne-nuove" class="btn btn-outline-secondary">Annulla</a>
    </div>
</div></form>
</div>

<style>
.pc-passo { border: 1px solid var(--bs-border-color, #e2e8f0); border-radius: 12px; padding: .75rem 1rem; }
.pc-passo > legend { float: none; width: auto; padding: 0 .25rem; margin-bottom: .5rem; font-size: 1rem; }
.pc-riga { border: 1px solid var(--bs-border-color, #e2e8f0); border-radius: 10px; padding: .6rem .75rem; margin-bottom: .6rem; background: var(--bs-body-bg, #fff); }
.pc-riga-testa { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-bottom: .4rem; }
.pc-spenta { opacity: .65; }
.pc-lettera { display: inline-flex; align-items: center; justify-content: center; min-width: 1.9rem; height: 1.9rem; padding: 0 .35rem; border-radius: 6px; font-weight: 700; font-size: .85rem; border: 1px solid rgba(0, 0, 0, .15); }
.pc-anteprima { background: var(--bs-tertiary-bg, #f8fafc); border-radius: 10px; padding: .6rem .9rem; }
.pc-anteprima-campo { margin: .5rem 0; }
/* il costruttore dei campi (come in admin/forms.php) e le impostazioni in più dei campi nuovi */
.cc-campo { display: grid; grid-template-columns: 2fr 1.4fr 2fr auto 1.6fr 34px 34px; gap: .4rem; align-items: center; margin-bottom: .4rem; padding: .3rem; border-radius: 6px; }
.cc-campo.cc-testa { margin-bottom: 0; padding-bottom: 0; }
.cc-logica { grid-column: 1 / -1; background: #fff7ed; border: 1px dashed #fdba74; border-radius: 6px; padding: .5rem; }
.cc-col-riga { display: grid; grid-template-columns: 2fr 1.6fr 2fr 30px; gap: .3rem; margin-bottom: .25rem; }
@media (max-width: 991.98px) { .cc-campo { grid-template-columns: 1fr 1fr; } }
#cx-box .cc-campo { border: 1px solid var(--bs-border-color, #e2e8f0); margin-bottom: .6rem; padding: .5rem; }
#cx-box .cc-testa { border: 0; }
.cx-extra { grid-column: 1 / -1; display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; padding-top: .4rem; border-top: 1px dashed var(--bs-border-color, #e2e8f0); }
.cx-extra .cx-passo { width: auto; min-width: 12rem; }
.cx-extra .cx-intest { flex: 1 1 18rem; }
.cx-extra .cx-nota, .cx-extra .cx-errore { flex-basis: 100%; }
</style>
<script>
(function () {
    var radice = document.getElementById('pcPagina');
    if (!radice) return;
    var annuncio = document.getElementById('pcAnnuncio');
    function figli(gruppo) { return Array.prototype.filter.call(gruppo.children, function (e) { return e.classList.contains('pc-riga'); }); }
    function testo(riga, classe) { var e = riga.querySelector(classe); return e ? e.value : ''; }

    // Anteprima del passo: i campi visibili con nome, asterisco e aiuto come li sceglie chi compila adesso (tutto con textContent)
    function anteprima(gruppo) {
        var id = gruppo.getAttribute('data-gruppo');
        var corpo = radice.querySelector('[data-anteprima-per="' + id + '"]');
        if (!corpo) return;
        corpo.textContent = '';
        var visibili = figli(gruppo).filter(function (r) { var v = r.querySelector('.pc-vis'); return v && v.checked; });
        if (!visibili.length) {
            var p = document.createElement('p'); p.className = 'small text-secondary mb-0';
            p.textContent = 'Con queste scelte il passo non ha campi da mostrare: chi compila lo salta e la barra dei passi si accorcia.';
            corpo.appendChild(p);
            return;
        }
        visibili.forEach(function (r) {
            var d = document.createElement('div'); d.className = 'pc-anteprima-campo';
            var s = document.createElement('strong'); s.textContent = testo(r, '.pc-etichetta'); d.appendChild(s);
            if (r.getAttribute('data-chiave') !== 'richiedente') {
                var o = r.querySelector('.pc-obb'), m = document.createElement('span');
                if (o && o.checked) { m.className = 'text-danger'; m.textContent = ' *'; } else { m.className = 'fw-normal text-secondary'; m.textContent = ' (facoltativo)'; }
                d.appendChild(m);
            }
            var a = testo(r, '.pc-aiuto').trim();
            if (a) { var t = document.createElement('div'); t.className = 'small text-secondary'; t.textContent = a; d.appendChild(t); }
            corpo.appendChild(d);
        });
    }

    // Posizione: i numeri nascosti seguono l'ordine delle righe; su e giù si fermano ai bordi del gruppo (il passo, o tutta la tabella)
    function rinumera(gruppo) {
        var r = figli(gruppo);
        r.forEach(function (riga, i) {
            var o = riga.querySelector('.pc-ordine'); if (o) o.value = i + 1;
            var su = riga.querySelector('.pc-su'), giu = riga.querySelector('.pc-giu');
            if (su) su.disabled = i === 0;
            if (giu) giu.disabled = i === r.length - 1;
        });
    }

    radice.querySelectorAll('.pc-gruppo').forEach(function (gruppo) {
        gruppo.querySelectorAll('.pc-muovi').forEach(function (m) { m.hidden = false; }); // senza JavaScript la posizione resta com'è
        rinumera(gruppo);
        anteprima(gruppo);
        gruppo.addEventListener('click', function (ev) {
            var b = ev.target.closest ? ev.target.closest('.pc-su, .pc-giu') : null;
            if (!b || b.disabled) return;
            var riga = b.closest('.pc-riga');
            var su = b.classList.contains('pc-su');
            var altra = su ? riga.previousElementSibling : riga.nextElementSibling;
            if (!altra || !altra.classList.contains('pc-riga')) return;
            if (su) { gruppo.insertBefore(riga, altra); } else { gruppo.insertBefore(altra, riga); }
            rinumera(gruppo);
            anteprima(gruppo);
            var ora = figli(gruppo).indexOf(riga) + 1;
            if (annuncio) annuncio.textContent = '«' + riga.getAttribute('data-nome') + '» spostato in posizione ' + ora + ' di ' + figli(gruppo).length + '.';
            var mio = riga.querySelector(su ? '.pc-su' : '.pc-giu');
            (mio && !mio.disabled ? mio : b).focus();
        });
        gruppo.addEventListener('input', function (ev) { if (ev.target.closest('.pc-riga')) anteprima(gruppo); });
        gruppo.addEventListener('change', function (ev) {
            var riga = ev.target.closest ? ev.target.closest('.pc-riga') : null;
            if (!riga) return;
            if (ev.target.classList.contains('pc-vis')) riga.classList.toggle('pc-spenta', !ev.target.checked);
            anteprima(gruppo);
        });
    });

    // Il colore della lettera segue la casella colore
    radice.addEventListener('input', function (ev) {
        if (ev.target.type !== 'color') return;
        var l = ev.target.closest('.pc-riga').querySelector('.pc-lettera');
        if (l) l.style.background = ev.target.value;
    });

    // «Torna alle intestazioni originali» chiede conferma: riguarda tutte le colonne
    var rip = document.getElementById('pcRipColonne');
    if (rip) rip.addEventListener('submit', function (ev) {
        var s = ev.submitter;
        if (s && s.id === 'pcRipInt' && !window.confirm('Rimettere tutte le intestazioni del file Excel come nella Tabella di monitoraggio originale? Colori, tipi, ordine e colonne nascoste restano come sono.')) ev.preventDefault();
    });
})();

/* Campi nuovi (AM5): il costruttore di MyForms ha una riga per campo; qui a ogni riga si aggiungono passo, visibile e colonna Excel (con gli stessi nomi in lista: cx_passo[] …) */
(function () {
    var box = document.getElementById('cc_cx_box');
    var datiEl = document.getElementById('cx-dati');
    if (!box || !datiEl) return;
    var dati = {};
    try { dati = JSON.parse(datiEl.textContent) || {}; } catch (x) { dati = {}; }
    var righe = box.querySelector('.cc-righe');
    var annuncio = document.getElementById('pcAnnuncio');
    function annuncia(t) { if (annuncio) annuncio.textContent = t; }

    function el(tag, cls, testo) { var e = document.createElement(tag); if (cls) e.className = cls; if (testo) e.textContent = testo; return e; }
    function nascosto(nome, valore) { var i = document.createElement('input'); i.type = 'hidden'; i.name = nome; i.value = valore; return i; }
    function spunta(classe, etichetta, acceso) {
        var l = el('label', 'form-check small text-nowrap m-0');
        var c = el('input', 'form-check-input ' + classe); c.type = 'checkbox'; c.checked = !!acceso;
        l.appendChild(c); l.appendChild(document.createTextNode(' ' + etichetta));
        return l;
    }
    function aggiungiExtra(riga, d) {
        var vecchio = riga.querySelector('.cx-extra'); if (vecchio) vecchio.remove();
        d = d || { chiave: '', passo: (dati.predefinito || {}).passo || 6, visibile: true, in_excel: false, intestazione: '', valori: 0, tipo_originale: '', errore: '' };
        var x = el('div', 'cx-extra');
        x.appendChild(nascosto('cx_chiave[]', d.chiave || ''));
        var lp = el('label', 'small fw-bold d-flex align-items-center gap-1 m-0'); lp.appendChild(document.createTextNode('Passo'));
        var sp = el('select', 'form-select form-select-sm cx-passo'); sp.name = 'cx_passo[]'; sp.setAttribute('aria-label', 'Passo in cui compare il campo');
        Object.keys(dati.passi || {}).forEach(function (n) { var o = el('option', '', n + ' · ' + dati.passi[n]); o.value = n; if (parseInt(n, 10) === parseInt(d.passo, 10)) o.selected = true; sp.appendChild(o); });
        lp.appendChild(sp); x.appendChild(lp);
        var v = el('span'); v.appendChild(nascosto('cx_vis[]', d.visibile ? '1' : '0')); v.appendChild(spunta('cx-vis', 'visibile', d.visibile)); x.appendChild(v);
        var e = el('span'); e.appendChild(nascosto('cx_excel[]', d.in_excel ? '1' : '0')); e.appendChild(spunta('cx-excel', 'diventa una colonna del file Excel', d.in_excel)); x.appendChild(e);
        var t = el('input', 'form-control form-control-sm cx-intest'); t.type = 'text'; t.name = 'cx_intest[]'; t.value = d.intestazione || '';
        t.placeholder = 'Intestazione della colonna (se vuota, il nome del campo)'; t.setAttribute('aria-label', 'Intestazione della colonna nel file Excel'); t.hidden = !d.in_excel;
        x.appendChild(t);
        var nota = el('div', 'small text-secondary cx-nota');
        if (d.valori > 0) { nota.textContent = 'Ha risposte in ' + (d.valori === 1 ? '1 richiesta' : d.valori + ' richieste') + ': non si può eliminare né cambiare il tipo; puoi solo nasconderlo.'; riga.dataset.bloccato = '1'; riga.dataset.tipoOrig = d.tipo_originale || ''; } else { delete riga.dataset.bloccato; delete riga.dataset.tipoOrig; }
        x.appendChild(nota);
        if (d.errore) { x.appendChild(el('div', 'text-danger small fw-bold cx-errore', d.errore)); }
        riga.appendChild(x);
        riga.classList.toggle('pc-spenta', !d.visibile);
    }
    // le righe che ci sono: una per campo salvato (o una vuota); poi quelle che si aggiungono
    var esistenti = dati.righe || [];
    Array.prototype.forEach.call(righe.querySelectorAll('.cc-campo'), function (r, i) { aggiungiExtra(r, esistenti[i] || null); });
    new MutationObserver(function (mut) {
        mut.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.classList && n.classList.contains('cc-campo')) aggiungiExtra(n, null); }); });
    }).observe(righe, { childList: true });

    righe.addEventListener('change', function (ev) {
        var t = ev.target, riga = t.closest ? t.closest('.cc-campo') : null;
        if (!riga) return;
        if (t.classList.contains('cx-vis')) { t.closest('span').querySelector('input[type=hidden]').value = t.checked ? '1' : '0'; riga.classList.toggle('pc-spenta', !t.checked); }
        if (t.classList.contains('cx-excel')) {
            t.closest('span').querySelector('input[type=hidden]').value = t.checked ? '1' : '0';
            var i = riga.querySelector('.cx-intest'); i.hidden = !t.checked;
            if (t.checked && i.value === '') { i.value = riga.querySelector('input[type=text]').value.trim(); }
        }
    });
    // un campo con risposte: il tipo non si cambia (lo ricontrolla anche il server)
    righe.addEventListener('change', function (ev) {
        var t = ev.target, riga = t.closest ? t.closest('.cc-campo') : null;
        if (riga && t.classList.contains('cc-tipo') && riga.dataset.bloccato === '1' && riga.dataset.tipoOrig && t.value !== riga.dataset.tipoOrig) {
            t.value = riga.dataset.tipoOrig;
            ev.stopPropagation();
            annuncia('Il tipo non si può cambiare: ci sono già risposte. Puoi nasconderlo e crearne uno nuovo.');
            window.alert('Il tipo non si può cambiare: ci sono già risposte. Puoi nasconderlo e crearne uno nuovo.');
        }
    }, true);
    // un campo con risposte: non si toglie dalla lista (si nasconde)
    righe.addEventListener('click', function (ev) {
        var b = ev.target.closest ? ev.target.closest('.cc-togli') : null;
        if (!b) return;
        var riga = b.closest('.cc-campo');
        if (riga && riga.dataset.bloccato === '1') {
            ev.stopPropagation(); ev.preventDefault();
            window.alert('Questo campo ha già risposte: non si può eliminare. Togli la spunta «visibile» per nasconderlo.');
        }
    }, true);
    // nessuna logica «mostra solo se» nei campi nuovi: il pulsante non serve
    box.querySelectorAll('.cc-logica-btn').forEach(function (b) { b.hidden = true; });
    new MutationObserver(function () { box.querySelectorAll('.cc-logica-btn').forEach(function (b) { b.hidden = true; }); }).observe(righe, { childList: true });
})();

/* Colonne nuove (AM5): aggiungere e togliere righe, colore del pallino, conferma dell'eliminazione */
(function () {
    var contenitore = document.getElementById('cn-righe');
    var modello = document.getElementById('cn-modello');
    var aggiungi = document.getElementById('cn-aggiungi');
    if (!contenitore || !modello || !aggiungi) return;
    function vuoto() { var v = document.getElementById('cn-vuoto'); if (v) v.hidden = contenitore.children.length > 0; }
    aggiungi.addEventListener('click', function () {
        var n = parseInt(aggiungi.dataset.prossimo, 10) || 1;
        aggiungi.dataset.prossimo = String(n + 1);
        var html = modello.innerHTML.split('__ID__').join('nuova_' + n);
        var t = document.createElement('div'); t.innerHTML = html;
        var riga = t.firstElementChild; // il modello è una sola riga
        // le tendine «dopo» del modello hanno il nome vero: si toglie la colonna con il suo stesso id (non c'è: l'id è nuovo)
        contenitore.appendChild(riga); vuoto();
        var campo = riga.querySelector('.cn-intestazione'); if (campo) campo.focus();
    });
    contenitore.addEventListener('click', function (ev) {
        var b = ev.target.closest ? ev.target.closest('.cn-togli') : null;
        if (b) { b.closest('.cn-riga').remove(); vuoto(); }
    });
    contenitore.addEventListener('input', function (ev) {
        var riga = ev.target.closest ? ev.target.closest('.cn-riga') : null;
        if (!riga) return;
        if (ev.target.classList.contains('cn-colore')) { riga.querySelector('.cn-pallino').style.background = ev.target.value; }
        if (ev.target.classList.contains('cn-intestazione')) { riga.querySelector('.cn-titolo').textContent = ev.target.value.trim() || 'Colonna nuova'; }
    });
    contenitore.addEventListener('change', function (ev) {
        if (ev.target.classList.contains('cn-vis')) { ev.target.closest('.cn-riga').classList.toggle('pc-spenta', !ev.target.checked); }
    });
    var el = document.getElementById('cnElimina');
    if (el) el.addEventListener('submit', function (ev) {
        var s = ev.submitter;
        if (s && !window.confirm('Eliminare la colonna «' + (s.getAttribute('data-nome') || '') + '»? Nessuna richiesta ha valori in questa colonna.')) ev.preventDefault();
    });
})();
</script>
