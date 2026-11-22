<?php
// Barra di modifica rapida in basso a sinistra: compare solo a chi gestisce l'area o l'evento/progetto aperto (i permessi veri li controlla il pannello).
// Calcola anche i collegamenti da mostrare. Caricato da master_template.php dopo il disegno della pagina.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $progetto_sel, $evento_sel, $utente_logged, $conn, $is_gestore_o_admin, $eventi_by_id, $p_id, $page_cfg
// Barra di modifica rapida: solo per chi gestisce l'area o l'evento/progetto aperto (i permessi veri li controlla l'admin)
$ev_ctx = $progetto_sel ?: ($evento_sel ?: null);
$gestisce_ev = $utente_logged && $ev_ctx && utente_gestisce_attivita($conn, (int)$_SESSION['utente_id'], (int)$ev_ctx['id']);
if ($is_gestore_o_admin || $gestisce_ev):
    $puo_impostazioni = $is_gestore_o_admin; // chi gestisce tutta l'area ha anche le impostazioni
    $ha_progetti = (bool)array_filter($eventi_by_id, fn($e) => ($e['tipo'] ?? '') === 'progetto');
    $link_admin = [];
    if ($ev_ctx && ($ev_ctx['tipo'] ?? '') === 'progetto') $link_admin[] = ['admin/progetti.php?p_id=' . $p_id . '&id=' . (int)$ev_ctx['id'], 'fa-pen', 'Modifica progetto', true];
    elseif ($ev_ctx) $link_admin[] = ['admin/eventi.php?p_id=' . $p_id . '&id=' . (int)$ev_ctx['id'], 'fa-pen', 'Modifica evento', true];
    if ($is_gestore_o_admin) {
        $link_admin[] = ['admin/eventi.php?p_id=' . $p_id, 'fa-calendar-alt', 'Eventi', false];
        if ($ha_progetti || ($page_cfg['layout_template'] ?? '') === 'progetti') $link_admin[] = ['admin/progetti.php?p_id=' . $p_id, 'fa-diagram-project', 'Progetti', false];
        if ($puo_impostazioni) $link_admin[] = ['admin/impostazioni_area.php?p_id=' . $p_id, 'fa-sliders', 'Impostazioni area', false];
    }
    $link_admin[] = ['admin/dashboard.php?p_id=' . $p_id, 'fa-gauge', 'Pannello', false];
?>
<style>
    .barra-gestore { position: fixed; left: 16px; bottom: 16px; z-index: 1030; display: flex; flex-wrap: wrap; align-items: center; gap: 4px;
                     max-width: calc(100vw - 32px); background: #1e293b; color: #fff; border-radius: 999px; padding: 5px 6px 5px 14px; box-shadow: 0 6px 20px rgba(0,0,0,.25); font-size: .82rem; }
    .barra-gestore .etichetta { font-weight: 700; color: #cbd5e1; margin-right: 4px; }
    .barra-gestore a { color: #fff; text-decoration: none; padding: 5px 11px; border-radius: 999px; font-weight: 600; white-space: nowrap; }
    .barra-gestore a:hover, .barra-gestore a:focus-visible { background: rgba(255,255,255,.15); }
    .barra-gestore a.principale { background: #f59e0b; color: #1e293b; }
    .barra-gestore a.principale:hover { background: #fbbf24; }
    .barra-gestore button { background: none; border: 0; color: #94a3b8; padding: 4px 8px; border-radius: 999px; }
    .barra-gestore.chiusa > :not(.apri) { display: none; }
    .barra-gestore:not(.chiusa) .apri { display: none; }
    .barra-gestore.chiusa { padding: 5px; }
    @media (max-width: 575.98px) { .barra-gestore { border-radius: 14px; } .barra-gestore .etichetta { display: none; } }
    @media print { .barra-gestore { display: none !important; } }
</style>
<nav class="barra-gestore" id="barraGestore" aria-label="Strumenti del gestore">
    <span class="etichetta"><i class="fa fa-user-gear me-1" aria-hidden="true"></i>Gestore</span>
    <?php foreach ($link_admin as [$url_a, $ico_a, $txt_a, $princ_a]): ?>
        <a href="<?php echo htmlspecialchars($url_a); ?>"<?php echo $princ_a ? ' class="principale"' : ''; ?>><i class="fa <?php echo $ico_a; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($txt_a); ?></a>
    <?php endforeach; ?>
    <button type="button" class="chiudi" aria-label="Riduci la barra del gestore" title="Riduci"><i class="fa fa-chevron-left" aria-hidden="true"></i></button>
    <button type="button" class="apri" aria-label="Mostra la barra del gestore" title="Strumenti del gestore" style="color:#fff;"><i class="fa fa-user-gear" aria-hidden="true"></i></button>
</nav>
<script nonce="<?php echo csp_nonce(); ?>">
(function () {
    var b = document.getElementById('barraGestore');
    var ridotta = function (v) { b.classList.toggle('chiusa', v); try { localStorage.setItem('barra_gestore_chiusa', v ? '1' : '0'); } catch (e) {} };
    try { if (localStorage.getItem('barra_gestore_chiusa') === '1') b.classList.add('chiusa'); } catch (e) {}
    b.querySelector('.chiudi').addEventListener('click', function () { ridotta(true); });
    b.querySelector('.apri').addEventListener('click', function () { ridotta(false); });
})();
</script>
<?php endif; ?>