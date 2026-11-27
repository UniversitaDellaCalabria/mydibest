<?php
// da_compilare_schede.php - «Da compilare» a schede: una per richiesta, con la locandina, i dati essenziali e SOLO le colonne che chi guarda compila; linguette
// «Da fare · Fatte», un «Salva» per scheda (niente salvataggio automatico: tutto o niente, errori accanto al campo). Le regole stanno in
// App\Pubblicazioni (CompilazioneInTabella::dividi, ServizioPubblicazioni::aggiornaOperatoriInBlocco); gli stili sono ui-comp-* in assets/css/portale-ui.css.
// La include solo admin/pubblicazioni.php, che ha già controllato il permesso e il codice di sicurezza e che salva.
// Variabili di chi include: $h, $repo, $nel_file, $col_tab (colonne da mostrare), $chiavi_tab, $solo_mie, $ct_mie, $ct_fatte, $ct_pag, $e_post, $errori_tab,
// $url_schede (fn(bool $mie, bool $fatte, int $pag)), $url_scheda, $norm_cella, $nomeUtente, $cfg_pb, $adesso_pb.
if (!function_exists('nega_accesso_pannello')) { http_response_code(403); exit('Accesso negato.'); }

use App\Portale\Vista\Testi;
use App\Pubblicazioni\CompilazioneInTabella;
use App\Pubblicazioni\Costanti;

$sc_diviso = CompilazioneInTabella::dividi($nel_file, $chiavi_tab, $adesso_pb->format('Y-m-d'));
$sc_pg = CompilazioneInTabella::pagina($sc_diviso[$ct_fatte ? 'fatte' : 'da_fare'], $ct_pag, CompilazioneInTabella::SCHEDE_PER_PAGINA);
$sc_tot = count($chiavi_tab);
$sc_data = fn(?string $d) => $d ? date('d/m/Y', strtotime($d)) : '';

// La richiesta appena salvata: se è rimasta in questo elenco il «Salvata» sta nella sua scheda, se è passata nell'altro lo dice una riga qui sopra
$sc_salvata = $e_post ? 0 : (int)($_GET['salvata'] ?? 0);
$sc_spostata = null;
if ($sc_salvata > 0) {
    foreach ($sc_diviso[$ct_fatte ? 'da_fare' : 'fatte'] as $r_s) {
        if ((int)$r_s['id'] === $sc_salvata) { $sc_spostata = $r_s; break; }
    }
}
// Errori di richieste che in questa pagina non ci sono (più): si dicono in cima, altrimenti si perderebbero
$sc_errori_fuori = array_diff_key($errori_tab, array_flip(array_map('intval', array_column($sc_pg['righe'], 'id'))));
?>
<nav class="ui-comp-linguette" aria-label="Richieste da compilare">
    <a class="ui-comp-linguetta" href="<?php echo $h($url_schede($ct_mie, false, 1)); ?>"<?php echo $ct_fatte ? '' : ' aria-current="page"'; ?>><i class="fa fa-pen" aria-hidden="true"></i>Da fare <span class="pn-num<?php echo $sc_diviso['da_fare'] ? ' avviso' : ''; ?>"><?php echo count($sc_diviso['da_fare']); ?></span></a>
    <a class="ui-comp-linguetta" href="<?php echo $h($url_schede($ct_mie, true, 1)); ?>"<?php echo $ct_fatte ? ' aria-current="page"' : ''; ?>><i class="fa fa-circle-check" aria-hidden="true"></i>Fatte <span class="pn-num"><?php echo count($sc_diviso['fatte']); ?></span></a>
</nav>

<?php if ($sc_spostata): ?>
    <div class="ui-comp-esito" role="status"><i class="fa fa-circle-check" aria-hidden="true"></i><span>Salvata. «<?php echo $h($sc_spostata['titolo']); ?>» è <?php echo $ct_fatte ? 'tornata in' : 'passata in'; ?> <a href="<?php echo $h($url_schede($ct_mie, !$ct_fatte, 1)); ?>"><?php echo $ct_fatte ? 'Da fare' : 'Fatte'; ?></a>.</span></div>
<?php endif; ?>
<?php if ($sc_errori_fuori): ?>
    <div class="ui-comp-esito azione" role="alert"><i class="fa fa-triangle-exclamation" aria-hidden="true"></i><div><strong>Non è stato salvato niente.</strong>
        <ul class="mb-0">
        <?php foreach ($sc_errori_fuori as $rid_e => $ee): $r_e = $repo->perId((int)$rid_e); foreach ($ee as $msg_e): ?>
            <li><strong><?php echo $h($r_e ? (string)$r_e['titolo'] : 'Richiesta n. ' . (int)$rid_e); ?></strong>: <?php echo $h($msg_e); ?></li>
        <?php endforeach; endforeach; ?>
        </ul></div></div>
<?php endif; ?>

<?php if (!$sc_pg['righe']): ?>
    <div class="ui-vuoto">
        <div class="ui-vuoto-figura" aria-hidden="true"><i class="fa <?php echo $ct_fatte ? 'fa-hourglass-half' : 'fa-circle-check'; ?>"></i></div>
        <?php if ($ct_fatte): ?>
            <h2>Ancora nessuna richiesta fatta</h2>
            <p>Qui finiscono le richieste quando <?php echo $solo_mie ? 'le tue colonne' : 'le colonne'; ?> sono tutte compilate.</p>
        <?php else: ?>
            <h2>È tutto compilato</h2>
            <p>Non ci sono richieste con <?php echo $solo_mie ? 'colonne tue' : 'colonne'; ?> ancora vuote. Per rivedere o correggere apri «Fatte».</p>
        <?php endif; ?>
    </div>
<?php else: ?>
<div class="ui-comp-lista">
<?php foreach ($sc_pg['righe'] as $r):
    $rid = (int)$r['id'];
    $err_s = $errori_tab[$rid] ?? [];
    $err_campi = array_intersect_key($err_s, array_flip($chiavi_tab));
    $err_altri = array_diff_key($err_s, $err_campi);
    $n_comp = CompilazioneInTabella::compilate($r, $chiavi_tab);
    // La locandina: miniatura se è un'immagine, altrimenti il simbolo del PDF; le richieste di prima non l'hanno
    $loc = null;
    foreach ($repo->allegati($rid) as $a) { if (($a['tipo'] ?? '') === 'locandina') { $loc = $a; break; } }
    $loc_url = $loc ? '../allegato_pubblicazione.php?p=' . $rid . '&a=' . (int)$loc['id'] : '';
    $loc_img = $loc && preg_match('/\.(jpe?g|png)$/i', (string)($loc['nome_originale'] ?? '')) === 1;
    if (!empty($r['decisa_il'])) $decisione = 'Approvata da ' . ($nomeUtente($r['decisa_da']) ?: 'un approvatore') . ' il ' . $sc_data($r['decisa_il']);
    else $decisione = ($r['origine'] ?? '') === 'manuale' ? 'Riga aggiunta a mano' : 'Approvata prima che ci fosse l\'approvazione';
    $nota = !empty($r['modificata_il']) ? 'Ultima modifica: ' . ($nomeUtente($r['modificata_da']) ?: 'un operatore') . ', il ' . $sc_data($r['modificata_il']) . '.' : 'Nessuna modifica fatta finora.';
    $nota = $ct_fatte ? 'Se svuoti un campo e salvi, la richiesta torna in «Da fare».' : 'Salva solo questa richiesta. ' . $nota;
    $scritto_da_chi_invia = ['d_url_pubblicazione' => (string)($r['url_docente'] ?? ''), 'e_url_altre_pagine' => (string)($r['altri_link_docente'] ?? '')];
    $spazi = fn(string $s) => trim((string)preg_replace('/\s+/u', ' ', $s));
    ?>
    <article class="ui-comp-scheda<?php echo $err_s ? ' ha-errore' : ''; ?>" id="r<?php echo $rid; ?>" aria-labelledby="t<?php echo $rid; ?>">
        <div class="ui-comp-testa">
            <?php if ($loc): ?>
                <a class="ui-comp-locandina" href="<?php echo $h($loc_url); ?>" target="_blank" rel="noopener" title="Apri la locandina">
                    <?php if ($loc_img): ?><img src="<?php echo $h($loc_url); ?>" alt="Locandina: <?php echo $h($r['titolo']); ?>" loading="lazy">
                    <?php else: ?><i class="fa fa-file-pdf" aria-hidden="true"></i>PDF<span class="visually-hidden">: apri la locandina</span><?php endif; ?>
                </a>
            <?php else: ?>
                <span class="ui-comp-locandina"><i class="fa fa-image" aria-hidden="true"></i>Senza locandina</span>
            <?php endif; ?>
            <div class="ui-comp-info">
                <h2 class="ui-comp-titolo" id="t<?php echo $rid; ?>"><?php echo $h($r['titolo']); ?></h2>
                <ul class="ui-comp-meta">
                    <li><i class="fa fa-calendar-day" aria-hidden="true"></i><span>Evento: <strong><?php echo $h(Testi::dataConGiorno($r['data_evento'])); ?></strong></span></li>
                    <li><i class="fa fa-user" aria-hidden="true"></i><span>Richiesta da <strong><?php echo $h($r['richiedente']); ?></strong></span></li>
                    <li><i class="fa fa-circle-check" aria-hidden="true"></i><span><?php echo $h($decisione); ?></span></li>
                </ul>
                <a class="ui-comp-apri" href="<?php echo $h($url_scheda($rid, 'monitoraggio')); ?>">Apri la scheda <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
            </div>
        </div>
        <div class="ui-comp-avanzamento">
            <div class="ui-comp-barra" aria-hidden="true"><?php for ($i = 0; $i < $sc_tot; $i++) echo $i < $n_comp ? '<i class="pieno"></i>' : '<i></i>'; ?></div>
            <span class="ui-comp-conta"><?php echo (int)$n_comp; ?> di <?php echo (int)$sc_tot; ?> <?php echo $sc_tot === 1 ? 'compilato' : 'compilati'; ?></span>
        </div>
        <?php if ($sc_salvata === $rid): ?>
            <div class="ui-comp-esito dentro" role="status"><i class="fa fa-circle-check" aria-hidden="true"></i><span>Salvata.</span></div>
        <?php endif; ?>
        <?php if ($err_s): ?>
            <div class="ui-comp-esito azione dentro" role="alert"><i class="fa fa-triangle-exclamation" aria-hidden="true"></i><span><strong>Non è stato salvato niente di questa richiesta.</strong>
                <?php if ($err_campi): ?><?php echo count($err_campi) === 1 ? 'Correggi il campo segnato' : 'Correggi i campi segnati'; ?>: quello che avevi scritto è ancora qui.<?php endif; ?>
                <?php foreach ($err_altri as $msg_e) echo ' ' . $h($msg_e); ?></span></div>
        <?php endif; ?>

        <?php if ($ct_fatte): ?>
            <dl class="ui-comp-valori">
                <?php foreach ($col_tab as $c): $v = trim((string)($r[$c['chiave']] ?? '')); ?>
                    <div<?php echo $c['tipo'] === 'testo' ? ' class="largo"' : ''; ?>><dt><?php echo $h(($c['lettera'] !== '' ? $c['lettera'] . ' · ' : '') . $cfg_pb->etichettaColonna($c['chiave'])); ?></dt><dd><?php echo nl2br($h($c['tipo'] === 'si_no' && mb_strtoupper($v) === 'SI' ? 'SÌ' : $v)); ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <details class="ui-comp-correggi"<?php echo $err_s ? ' open' : ''; ?>>
            <summary><i class="fa fa-pen" aria-hidden="true"></i><span class="apri">Correggi</span><span class="chiudi">Chiudi</span></summary>
        <?php endif; ?>

        <form method="POST" action="<?php echo $h($url_schede($ct_mie, $ct_fatte, $sc_pg['pagina']) . '#r' . $rid); ?>" class="ui-comp-form m-0" autocomplete="off"><?php csrf_field(); ?>
            <input type="hidden" name="azione" value="salva_tabella"><input type="hidden" name="scheda" value="<?php echo $rid; ?>">
            <input type="hidden" name="mie" value="<?php echo $ct_mie ? 1 : 0; ?>"><input type="hidden" name="fatte" value="<?php echo $ct_fatte ? 1 : 0; ?>"><input type="hidden" name="pag" value="<?php echo (int)$sc_pg['pagina']; ?>">
            <div class="ui-comp-campi">
            <?php foreach ($col_tab as $c):
                $k = $c['chiave'];
                $tipo = $c['tipo'];
                $salvato = (string)($r[$k] ?? '');
                $val = ($e_post && isset($_POST['c'][$rid][$k]) && is_scalar($_POST['c'][$rid][$k])) ? (string)$_POST['c'][$rid][$k] : $salvato;
                $orig = $norm_cella($salvato, $tipo);
                $cambiato = $norm_cella($val, $tipo) !== $orig;
                $err_c = $err_campi[$k] ?? '';
                if ($err_c !== '') { $segno = 'Da correggere'; $classe = ' errore'; }
                elseif ($cambiato) { $segno = 'Da salvare'; $classe = ''; }
                elseif ($orig !== '') { $segno = 'Compilato'; $classe = ' pieno'; }
                else { $segno = 'Da compilare'; $classe = ''; }
                $largo = $tipo === 'testo';
                $id_c = 'cc_' . $rid . '_' . $k;
                $nome_c = 'c[' . $rid . '][' . $k . ']';
                $url_col = isset($scritto_da_chi_invia[$k]);
                $testata = ($c['lettera'] !== '' ? '<span class="ui-comp-lettera">' . $h($c['lettera']) . '</span>' : '')
                    . '<span class="ui-comp-nome">' . $h($cfg_pb->etichettaColonna($k)) . '</span><span class="ui-comp-segno">' . $segno . '</span>';
                $attr_campo = ' data-tipo="' . $h($tipo) . '" data-orig="' . $h($orig) . '"' . ($err_c !== '' ? ' data-rifiutato="' . $h($norm_cella($val, $tipo)) . '"' : '');
                $attr_err = $err_c !== '' ? ' aria-invalid="true" aria-describedby="' . $h($id_c) . '_err"' : '';
                $sotto = ($err_c !== '' ? '<p class="ui-comp-errore" id="' . $h($id_c) . '_err"><i class="fa fa-circle-exclamation" aria-hidden="true"></i><span>' . $h($err_c) . '</span></p>' : '')
                    . '<p class="ui-comp-aiuto ui-comp-nonsalvato"' . ($cambiato && $err_c === '' ? '' : ' hidden') . '>Scritto ma non ancora salvato.</p>'
                    . ($url_col && $spazi($scritto_da_chi_invia[$k]) !== '' && $spazi($salvato) !== $spazi($scritto_da_chi_invia[$k]) ? '<p class="ui-comp-aiuto">Scritto da chi ha inviato: ' . nl2br($h(trim($scritto_da_chi_invia[$k]))) . '</p>' : '');
                ?>
                <?php if ($tipo === 'si_no'): $vu = mb_strtoupper(trim($val)); ?>
                    <fieldset class="ui-comp-campo<?php echo $classe; ?>"<?php echo $attr_campo . $attr_err; ?>>
                        <legend class="ui-comp-etichetta"><?php echo $testata; ?></legend>
                        <div class="ui-comp-sino">
                            <input type="radio" name="<?php echo $h($nome_c); ?>" id="<?php echo $h($id_c); ?>_si" value="SI"<?php echo $vu === 'SI' ? ' checked' : ''; ?>><label class="si" for="<?php echo $h($id_c); ?>_si"><i class="fa fa-check" aria-hidden="true"></i>SÌ</label>
                            <input type="radio" name="<?php echo $h($nome_c); ?>" id="<?php echo $h($id_c); ?>_no" value="NO"<?php echo $vu === 'NO' ? ' checked' : ''; ?>><label class="no" for="<?php echo $h($id_c); ?>_no"><i class="fa fa-check" aria-hidden="true"></i>NO</label>
                            <?php if ($vu !== '' && $vu !== 'SI' && $vu !== 'NO'): // un valore scritto prima, fuori regola: resta finché non si sceglie ?>
                                <input type="radio" name="<?php echo $h($nome_c); ?>" id="<?php echo $h($id_c); ?>_altro" value="<?php echo $h($val); ?>" checked><label class="no" for="<?php echo $h($id_c); ?>_altro"><i class="fa fa-check" aria-hidden="true"></i><?php echo $h($val); ?></label>
                            <?php endif; ?>
                            <input type="radio" name="<?php echo $h($nome_c); ?>" id="<?php echo $h($id_c); ?>_vuoto" value=""<?php echo $vu === '' ? ' checked' : ''; ?>><label class="vuoto" for="<?php echo $h($id_c); ?>_vuoto">Svuota</label>
                        </div>
                        <?php echo $sotto; ?>
                    </fieldset>
                <?php else: ?>
                    <div class="ui-comp-campo<?php echo $classe . ($largo ? ' largo' : ''); ?>"<?php echo $attr_campo; ?>>
                        <label class="ui-comp-etichetta" for="<?php echo $h($id_c); ?>"><?php echo $testata; ?></label>
                        <?php if ($tipo === 'numero'): ?>
                            <input type="text" inputmode="numeric" class="form-control ui-comp-numero<?php echo $err_c !== '' ? ' is-invalid' : ''; ?>" id="<?php echo $h($id_c); ?>" name="<?php echo $h($nome_c); ?>" value="<?php echo $h($val); ?>"<?php echo $attr_err; ?>>
                        <?php else: ?>
                            <textarea rows="3" maxlength="<?php echo (int)Costanti::MAX_COLONNA; ?>" class="form-control ui-comp-testo<?php echo $err_c !== '' ? ' is-invalid' : ''; ?>" id="<?php echo $h($id_c); ?>" name="<?php echo $h($nome_c); ?>"<?php echo ($url_col ? ' placeholder="Un indirizzo per riga"' : '') . $attr_err; ?>><?php echo $h($val); ?></textarea>
                        <?php endif; ?>
                        <?php echo $sotto; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            </div>
            <div class="ui-comp-piede">
                <button type="submit" class="btn btn-primary ui-comp-salva"><i class="fa fa-floppy-disk me-2" aria-hidden="true"></i>Salva</button>
                <span class="ui-comp-nota" data-nota="<?php echo $h($nota); ?>" aria-live="polite"><?php echo $h($nota); ?></span>
            </div>
        </form>
        <?php if ($ct_fatte): ?></details><?php endif; ?>
    </article>
<?php endforeach; ?>
</div>

<?php if ($sc_pg['pagine'] > 1): ?>
    <nav class="ui-comp-pagine" aria-label="Pagine">
        <?php if ($sc_pg['pagina'] > 1): ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo $h($url_schede($ct_mie, $ct_fatte, $sc_pg['pagina'] - 1)); ?>">← Precedente</a><?php endif; ?>
        <span><?php echo (int)$sc_pg['totale']; ?> richieste · pagina <?php echo (int)$sc_pg['pagina']; ?> di <?php echo (int)$sc_pg['pagine']; ?></span>
        <?php if ($sc_pg['pagina'] < $sc_pg['pagine']): ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo $h($url_schede($ct_mie, $ct_fatte, $sc_pg['pagina'] + 1)); ?>">Successiva →</a><?php endif; ?>
    </nav>
<?php endif; ?>

<script nonce="<?php echo csp_nonce(); ?>">
// Le etichette dei campi seguono quello che si scrive («Da salvare»); si inviano solo i campi cambiati; chi salva una richiesta con modifiche non salvate in
// un'altra, o lascia la pagina, viene avvisato. Niente si salva da solo.
(function () {
    var moduli = Array.prototype.slice.call(document.querySelectorAll('.ui-comp-form')), invio = false;
    function normale(v, tipo) { v = v.replace(/\r\n/g, '\n').trim(); return tipo === 'si_no' ? v.toUpperCase() : v; }
    function valore(campo) {
        if (campo.dataset.tipo === 'si_no') { var r = campo.querySelector('input[type=radio]:checked'); return normale(r ? r.value : '', 'si_no'); }
        return normale(campo.querySelector('textarea, input').value, campo.dataset.tipo);
    }
    function campi(f) { return Array.prototype.slice.call(f.querySelectorAll('.ui-comp-campo')); }
    function cambiati(f) { return campi(f).filter(function (c) { return valore(c) !== c.dataset.orig; }).length; }
    function aggiornaCampo(c) {
        var v = valore(c), cambiato = v !== c.dataset.orig;
        var errore = c.dataset.rifiutato !== undefined && v === c.dataset.rifiutato;
        c.classList.toggle('errore', errore);
        c.classList.toggle('pieno', !errore && !cambiato && v !== '');
        c.querySelector('.ui-comp-segno').textContent = errore ? 'Da correggere' : (cambiato ? 'Da salvare' : (v !== '' ? 'Compilato' : 'Da compilare'));
        c.querySelector('.ui-comp-nonsalvato').hidden = errore || !cambiato;
        var msg = c.querySelector('.ui-comp-errore'); if (msg) msg.hidden = !errore;
        var ctl = c.querySelector('.form-control'); if (ctl) ctl.classList.toggle('is-invalid', errore);
    }
    function aggiornaNote() {
        var sporchi = moduli.filter(function (f) { return cambiati(f) > 0; });
        moduli.forEach(function (f) {
            var n = cambiati(f), nota = f.querySelector('.ui-comp-nota'), salva = f.querySelector('.ui-comp-salva');
            nota.classList.toggle('modificata', n > 0);
            nota.textContent = n === 0 ? nota.dataset.nota : (n === 1 ? '1 modifica non salvata' : n + ' modifiche non salvate');
            if (sporchi.some(function (x) { return x !== f; })) salva.setAttribute('data-confirm', 'In un\'altra richiesta ci sono modifiche non salvate: salvando questa andranno perse. Salvo lo stesso?');
            else salva.removeAttribute('data-confirm');
        });
    }
    moduli.forEach(function (f) {
        function cambia(e) { var c = e.target.closest('.ui-comp-campo'); if (c) { aggiornaCampo(c); aggiornaNote(); } }
        f.addEventListener('input', cambia);
        f.addEventListener('change', cambia);
        f.addEventListener('submit', function () {
            invio = true;
            var spenti = [];
            campi(f).forEach(function (c) {
                if (valore(c) !== c.dataset.orig) return;
                c.querySelectorAll('textarea, input').forEach(function (x) { x.disabled = true; spenti.push(x); });
            });
            setTimeout(function () { spenti.forEach(function (x) { x.disabled = false; }); }, 0);
        });
    });
    aggiornaNote();
    window.addEventListener('pageshow', function () { invio = false; aggiornaNote(); });
    window.addEventListener('beforeunload', function (e) {
        if (!invio && moduli.some(function (f) { return cambiati(f) > 0; })) { e.preventDefault(); e.returnValue = ''; }
    });
})();
</script>
<?php endif; ?>
