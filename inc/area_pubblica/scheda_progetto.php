<?php
// Scheda di un progetto (?progetto=ID), con qualsiasi layout dell'area: testata, sintesi, descrizione, percorso, edizioni con pulsanti di iscrizione, contatti.
// Caricato da master_template.php (stesso ambito della pagina) quando serve questo disegno.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $progetto_sel, $info_progetto, $col_primaria, $col_testo_area, $current_filename, $page_cfg, $conn
//   $utente_logged, $pulsante_progetto
    $ev_p = $progetto_sel;
    $ip   = $info_progetto($ev_p);
    $dp   = $ip['d']; $st_p = $ip['stato']; $tp = $ip['t'];
    $txt_on_p = colore_testo_su($col_primaria);
    // Periodo, sede e destinatari stanno già nell'intestazione
    $sintesi = array_filter([
        ['fa-calendar-days', 'Calendario', (!empty($dp['data_inizio']) || !empty($dp['data_fine'])) ? ($dp['periodo_note'] ?? '') : ''],
        ['fa-clock', 'Ore totali', !empty($dp['ore_totali']) ? (int)$dp['ore_totali'] . ' ore' : ''],
        ['fa-clone', 'Edizioni', count($ip['edizioni']) > 1 ? count($ip['edizioni']) . ($ip['scuole'] ? ' (una scuola per edizione)' : '') : ''],
        ['fa-people-arrows', 'Incontri previsti', !empty($dp['incontri_previsti']) ? (string)(int)$dp['incontri_previsti'] : ''],
        ['fa-users', 'Studenti per scuola', $ip['scuole'] ? $ip['limiti'] . (count($ip['edizioni']) > 1 && $ip['limiti'] !== '' ? ' (secondo l\'edizione)' : '') : ''],
        ['fa-laptop-house', 'Modalità', $dp['modalita'] ?? ''],
        ['fa-graduation-cap', 'Attestato', !empty($dp['attestati']) ? ($ip['scuole'] ? 'Per ogni studente partecipante' : 'Di partecipazione') : ''],
    ], fn($r) => trim((string)$r[2]) !== '');
?>
    <style>
        .pj-hero { background: <?php echo $col_primaria; ?>; color: <?php echo $txt_on_p; ?>; border-radius: 18px; padding: 2rem 2rem 1.75rem; position: relative; overflow: hidden; }
        .pj-hero::after { content: ""; position: absolute; right: -80px; top: -80px; width: 260px; height: 260px; border-radius: 50%; background: rgba(255,255,255,.10); }
        .pj-hero h1 { color: inherit; font-weight: 800; letter-spacing: -.5px; font-size: clamp(1.4rem, 2.6vw, 2.25rem); line-height: 1.2; max-width: 900px; }
        .pj-hero .pj-fatti { display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; font-weight: 600; opacity: .95; }
        .pj-corso { font-size: 1.05rem; font-weight: 600; opacity: .95; }
        .pj-corso a:hover, .pj-corso a:focus { opacity: .85; }
        .pj-stato { display: inline-flex; align-items: center; gap: .35rem; font-size: .78rem; font-weight: 700; padding: .3rem .75rem; border-radius: 999px; }
        .pj-box { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; box-shadow: 0 2px 10px rgba(15,23,42,.05); }
        .pj-box h2 { font-size: .8rem; text-transform: uppercase; letter-spacing: .07em; font-weight: 800; color: #475569; margin-bottom: 1rem; }
        .pj-desc { font-size: 1rem; line-height: 1.75; color: #1f2937; overflow-wrap: anywhere; }
        .pj-desc img { max-width: 100%; height: auto; }
        .pj-sintesi { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: .75rem; }
        .pj-fatto { display: flex; gap: .75rem; align-items: center; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: .8rem 1rem; }
        .pj-fatto-ico { flex: 0 0 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: <?php echo $col_primaria; ?>1A; color: <?php echo $col_testo_area; ?>; font-size: 1.05rem; }
        .pj-sintesi dt { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 700; }
        .pj-sintesi dd { margin: 0; font-weight: 700; color: #111827; line-height: 1.3; }
        .pj-persona { display: flex; gap: .75rem; align-items: flex-start; padding: .75rem 0; border-top: 1px solid #f1f5f9; }
        .pj-persona:first-of-type { border-top: 0; padding-top: 0; }
        .pj-avatar { flex: 0 0 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; background: <?php echo $col_primaria; ?>; color: <?php echo $txt_on_p; ?>; }
        .pj-persona a { color: #1f2937; text-decoration: none; overflow-wrap: anywhere; }
        .pj-persona a:hover { text-decoration: underline; }
        .pj-posto { display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem; border-radius: 12px; background: #f8fafc; }
        .pj-percorso { list-style: none; padding: 0; margin: 0; position: relative; }
        .pj-percorso::before { content: ""; position: absolute; left: 17px; top: 8px; bottom: 8px; width: 2px; background: #e5e7eb; }
        .pj-percorso li { display: flex; gap: 1rem; padding-bottom: 1.25rem; position: relative; }
        .pj-percorso li:last-child { padding-bottom: 0; }
        .pj-tappa { flex: 0 0 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; background: <?php echo $col_primaria; ?>; color: <?php echo $txt_on_p; ?>; position: relative; z-index: 1; }
        .pj-tappa-tit { font-size: 1.02rem; font-weight: 800; margin: .35rem 0 .4rem; color: #111827; }
        .pj-tag { display: inline-flex; align-items: center; gap: .35rem; background: #f1f5f9; color: #334155; border-radius: 999px; padding: .15rem .6rem; font-size: .78rem; font-weight: 600; }
        @media (min-width: 992px) { .pj-side { position: sticky; top: 110px; } }
        @media (max-width: 575.98px) {
            .pj-hero { padding: 1.25rem; border-radius: 14px; }
            .pj-sintesi { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
            .pj-fatto { flex-direction: column; align-items: flex-start; gap: .4rem; padding: .7rem; }
        }
    </style>

    <div class="ui-larg-cont container mb-5">
        <nav aria-label="Percorso" class="my-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <a href="<?php echo htmlspecialchars($current_filename); ?>.php" class="ui-area-testo-ok fw-bold text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i><?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'Tutti i progetti'); ?></a>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" id="pjCopiaLink"><i class="fa fa-link me-1" aria-hidden="true"></i><span>Copia il link</span></button>
        </nav>

        <header class="pj-hero shadow-sm mb-4">
            <div class="d-flex flex-wrap gap-2 mb-3 position-relative ui-z-1">
                <span class="pj-stato" style="background: <?php echo $st_p['bg']; ?>; color: <?php echo $st_p['fg']; ?>;"><?php echo htmlspecialchars($st_p['etichetta']); ?></span>
            </div>
            <h1 class="mb-2 position-relative ui-z-1"><?php echo htmlspecialchars($ev_p['titolo']); ?></h1>
            <?php $corso_p = html_corso_pubblico($conn, $dp, 'text-decoration:underline;text-underline-offset:3px;'); ?>
            <?php if ($corso_p !== ''): ?><p class="pj-corso position-relative mb-3 ui-z-1"><i class="fa fa-building-columns me-2" aria-hidden="true"></i><?php echo $corso_p; ?></p><?php else: ?><div class="mb-3"></div><?php endif; ?>
            <div class="pj-fatti position-relative ui-z-1">
                <span><i class="fa fa-calendar-days me-1" aria-hidden="true"></i><?php echo htmlspecialchars($ip['periodo']); ?></span>
                <?php if (!empty($ev_p['luogo'])): ?><span><i class="fa fa-location-dot me-1" aria-hidden="true"></i><?php echo htmlspecialchars($ev_p['luogo']); ?></span><?php endif; ?>
                <?php if (!empty($dp['destinatari'])): ?><span><i class="fa fa-user-graduate me-1" aria-hidden="true"></i><?php echo htmlspecialchars($dp['destinatari']); ?></span><?php endif; ?>
            </div>
        </header>

        <?php if ($sintesi): ?>
            <h2 class="visually-hidden">In sintesi</h2>
            <dl class="pj-sintesi mb-4">
                <?php foreach ($sintesi as [$ico, $lbl, $val]): ?>
                    <div class="pj-fatto">
                        <span class="pj-fatto-ico" aria-hidden="true"><i class="fa <?php echo $ico; ?>"></i></span>
                        <div><dt><?php echo $lbl; ?></dt><dd><?php echo htmlspecialchars($val); ?></dd></div>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>

        <div class="row g-4 align-items-start">
            <div class="col-lg-8">
                <?php if (!empty($ev_p['locandina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $ev_p['locandina_path'])): ?>
                    <img src="<?php echo htmlspecialchars($ev_p['locandina_path']); ?>" alt="" class="w-100 mb-4 shadow-sm ui-r-14 ui-maxh-380 ui-of-cover">
                <?php endif; ?>

                <article class="pj-box p-4 mb-4">
                    <h2><i class="fa fa-book-open me-1" aria-hidden="true"></i>Il progetto</h2>
                    <div class="pj-desc"><?php echo !empty($ev_p['descrizione']) ? $ev_p['descrizione'] : '<p class="text-muted">Descrizione in arrivo.</p>'; ?></div>
                    <?php if (!empty($ev_p['allegato_pdf'])): ?>
                        <a href="<?php echo htmlspecialchars($ev_p['allegato_pdf']); ?>" target="_blank" rel="noopener" class="btn btn-outline-danger fw-bold mt-3"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Programma e calendario (PDF)</a>
                    <?php endif; ?>
                </article>

                <?php if (!empty($dp['moduli'])): ?>
                    <section class="pj-box p-4 mb-4">
                        <h2><i class="fa fa-route me-1" aria-hidden="true"></i>Articolazione del percorso</h2>
                        <ol class="pj-percorso">
                            <?php foreach ($dp['moduli'] as $mi => $mo): ?>
                                <li>
                                    <span class="pj-tappa" aria-hidden="true"><?php echo $mi + 1; ?></span>
                                    <div>
                                        <h3 class="pj-tappa-tit"><?php echo htmlspecialchars($mo['titolo'] ?? ''); ?></h3>
                                        <div class="d-flex flex-wrap gap-2 mb-1">
                                            <?php if (!empty($mo['ore'])): ?><span class="pj-tag"><i class="fa fa-clock" aria-hidden="true"></i><?php echo (int)$mo['ore']; ?> <?php echo (int)$mo['ore'] === 1 ? 'ora' : 'ore'; ?></span><?php endif; ?>
                                            <?php if (!empty($mo['modalita'])): ?><span class="pj-tag"><i class="fa <?php echo stripos($mo['modalita'], 'online') !== false ? 'fa-laptop' : (stripos($mo['modalita'], 'laborator') !== false ? 'fa-flask' : 'fa-people-group'); ?>" aria-hidden="true"></i><?php echo htmlspecialchars($mo['modalita']); ?></span><?php endif; ?>
                                            <?php if (!empty($mo['quando'])): ?><span class="pj-tag"><i class="fa fa-calendar-day" aria-hidden="true"></i><?php echo htmlspecialchars($mo['quando']); ?></span><?php endif; ?>
                                            <?php if (!empty($mo['sede'])): ?><span class="pj-tag"><i class="fa fa-location-dot" aria-hidden="true"></i><?php echo htmlspecialchars($mo['sede']); ?></span><?php endif; ?>
                                        </div>
                                        <?php if (!empty($mo['descrizione'])): ?><p class="mb-0 text-secondary ui-fs-93 ui-lh-1_6"><?php echo nl2br(htmlspecialchars($mo['descrizione'])); ?></p><?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </section>
                <?php endif; ?>

                <?php foreach ([['obiettivi', 'fa-bullseye', 'Obiettivi formativi'], ['conoscenze', 'fa-book', 'Conoscenze'], ['competenze', 'fa-award', 'Competenze attese']] as [$k_s, $ico_s, $tit_s]):
                    if (trim(strip_tags((string)($dp[$k_s] ?? ''))) === '') continue; ?>
                    <section class="pj-box p-4 mb-4">
                        <h2><i class="fa <?php echo $ico_s; ?> me-1" aria-hidden="true"></i><?php echo $tit_s; ?></h2>
                        <div class="pj-desc"><?php echo $dp[$k_s]; ?></div>
                    </section>
                <?php endforeach; ?>

                <?php if (!empty($dp['info_extra'])): ?>
                    <section class="pj-box p-4 mb-4">
                        <h2><i class="fa fa-table-list me-1" aria-hidden="true"></i>Altre informazioni</h2>
                        <dl class="row mb-0">
                            <?php foreach ($dp['info_extra'] as $ie): ?>
                                <dt class="col-sm-4 text-secondary fw-semibold"><?php echo htmlspecialchars($ie['etichetta'] ?? ''); ?></dt>
                                <dd class="col-sm-8 fw-semibold"><?php echo nl2br(htmlspecialchars($ie['valore'] ?? '')); ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    </section>
                <?php endif; ?>
            </div>

            <aside class="col-lg-4">
                <div class="pj-side d-flex flex-column gap-4">
                    <?php if ($tp && (int)($ev_p['richiede_prenotazione'] ?? 1) === 1):
                        $piu_ed = count($ip['edizioni']) > 1;
                        // Il login e gli stati "chiuso / in arrivo / concluso" valgono per tutto il progetto: un solo pulsante
                        $pulsante_unico = !$utente_logged || !in_array($st_p['codice'], ['aperte', 'attesa'], true) || !$piu_ed;
                    ?>
                    <section class="ui-area-bt4 pj-box p-4" id="iscrizione">
                        <?php $sc_p = $ip['scuole']; ?>
                        <h2><i class="fa <?php echo $sc_p ? 'fa-school' : 'fa-user-plus'; ?> me-1" aria-hidden="true"></i><?php echo $piu_ed ? 'Edizioni e iscrizione' : ($sc_p ? 'Iscrizione della scuola' : 'Iscrizione'); ?></h2>
                        <?php if ($piu_ed): ?>
                            <p class="small text-secondary mb-2">Il progetto si ripete in <strong><?php echo count($ip['edizioni']); ?> edizioni</strong><?php echo $sc_p ? ': ognuna accoglie una scuola' : ''; ?>. <?php echo !empty($ip['piu_ed']) ? ($sc_p ? 'La stessa scuola può prenotare più edizioni (una prenotazione per ogni edizione).' : 'Puoi iscriverti a più edizioni (una prenotazione per ogni edizione).') : 'Puoi iscriverti a una sola edizione.'; ?></p>
                        <?php endif; ?>
                        <div class="d-flex flex-column gap-2 mb-3">
                            <?php foreach ($ip['edizioni'] as $ed):
                                $liberi_ed = max(0, (int)$ed['t']['max_posti'] - (int)$ed['occ']);
                                if ($sc_p) $txt_ed = $ed['libera'] ? 'Posto disponibile' : 'Già assegnata a una scuola';
                                else $txt_ed = $ed['libera'] ? ((int)$ed['t']['max_posti'] >= POSTI_SENZA_LIMITE ? 'Posti disponibili' : testo_posti_liberi($liberi_ed) . ' su ' . (int)$ed['t']['max_posti']) : 'Posti esauriti';
                            ?>
                                <div class="pj-posto d-block">
                                  <div class="d-flex align-items-center gap-3">
                                    <i class="fa <?php echo ($ed['mio'] !== null || $ed['libera']) ? 'fa-circle-check text-success' : 'fa-lock text-warning'; ?> fs-4" aria-hidden="true"></i>
                                    <div class="flex-grow-1 ui-minw-0">
                                        <?php if ($piu_ed):
                                            $ta_ed = $ed['t']['data_apertura'] ?? null; $tc_ed = $ed['t']['data_chiusura'] ?? null;
                                            $lim_ed = $sc_p ? testo_limiti_partecipanti($ed['min'], $ed['max']) : ''; ?>
                                            <div class="fw-bold"><?php echo htmlspecialchars($ed['etichetta']); ?></div>
                                            <div class="small text-secondary"><i class="fa fa-door-open me-1" aria-hidden="true"></i>Iscrizioni <?php echo $ta_ed ? 'dal ' . date('d/m/Y H:i', strtotime($ta_ed)) : 'già aperte'; ?><?php echo $tc_ed ? ' al ' . date('d/m/Y H:i', strtotime($tc_ed)) : ''; ?></div>
                                            <?php if ($lim_ed !== ''): ?><div class="small text-secondary"><i class="fa fa-users me-1" aria-hidden="true"></i><?php echo htmlspecialchars(ucfirst($lim_ed)); ?> studenti</div><?php endif; ?>
                                            <?php if (!in_array($ed['stato']['codice'], ['aperte', 'attesa'], true)): ?><span class="badge mt-1" style="background: <?php echo $ed['stato']['bg']; ?>; color: <?php echo $ed['stato']['fg']; ?>;"><?php echo htmlspecialchars($ed['stato']['etichetta']); ?></span><?php endif; ?>
                                        <?php endif; ?>
                                        <div class="<?php echo $piu_ed ? 'small text-secondary' : 'fw-bold'; ?>"><?php echo htmlspecialchars($txt_ed); ?></div>
                                        <?php if (!$ed['libera'] && !empty($ed['t']['abilita_lista_attesa'])): ?><div class="small text-secondary"><?php echo $ed['attesa'] > 0 ? $ed['attesa'] . ($sc_p ? ($ed['attesa'] === 1 ? ' scuola' : ' scuole') : ($ed['attesa'] === 1 ? ' persona' : ' persone')) . " in lista d'attesa" : "Lista d'attesa vuota"; ?></div><?php endif; ?>
                                        <?php if ($sc_p && !$piu_ed && $ed['libera']): ?><div class="small text-secondary">Il progetto accoglie una sola scuola.</div><?php endif; ?>
                                        <?php if (!$sc_p && $ed['libera'] && (int)$ed['t']['max_posti'] > 0 && (int)$ed['t']['max_posti'] < POSTI_SENZA_LIMITE):
                                            $pct_ed = min(100, (int)round($ed['occ'] / max(1, (int)$ed['t']['max_posti']) * 100)); ?>
                                            <div class="progress mt-1 ui-h-6" role="progressbar" aria-label="Posti occupati" aria-valuenow="<?php echo $pct_ed; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?php echo $pct_ed >= 75 ? 'bg-warning' : 'bg-success'; ?>" style="width: <?php echo $pct_ed; ?>%;"></div></div>
                                        <?php endif; ?>
                                    </div>
                                  </div>
                                    <?php if (!$pulsante_unico): $btn_ed = $pulsante_progetto($ev_p, $ip, $ed); ?>
                                        <?php if ($btn_ed !== ''): ?><div class="mt-2"><?php echo $btn_ed; ?></div><?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!$piu_ed): ?>
                        <ul class="list-unstyled small mb-3">
                            <li class="mb-1"><i class="fa fa-door-open me-2 text-secondary" aria-hidden="true"></i>Apertura: <strong><?php echo !empty($tp['data_apertura']) ? date('d/m/Y \o\r\e H:i', strtotime($tp['data_apertura'])) : 'già aperte'; ?></strong></li>
                            <?php if (!empty($tp['data_chiusura'])): ?><li><i class="fa fa-door-closed me-2 text-secondary" aria-hidden="true"></i>Chiusura: <strong><?php echo date('d/m/Y \o\r\e H:i', strtotime($tp['data_chiusura'])); ?></strong></li><?php endif; ?>
                        </ul>
                        <?php endif; ?>
                        <?php if ($pulsante_unico) echo $pulsante_progetto($ev_p, $ip, $piu_ed ? null : $ip['edizioni'][0]); ?>
                        <?php if ($ip['mio_ed'] && $piu_ed): ?><p class="small text-success fw-semibold mt-2 mb-0"><i class="fa fa-check me-1" aria-hidden="true"></i><?php echo $sc_p ? 'La tua scuola' : 'La tua edizione'; ?>: <?php echo htmlspecialchars($ip['mio_ed']['etichetta']); ?></p><?php endif; ?>
                        <?php if (\App\Eventi\AccessoEvento::serveAccesso((int)($ev_p['ruolo_accesso_id'] ?? 0)) && !$utente_logged): ?>
                            <p class="small text-secondary mt-2 mb-0"><?php echo $sc_p ? "L'iscrizione la effettua il docente referente della scuola, con SPID, CIE o credenziali Unical." : "Per iscriverti accedi con SPID, CIE o credenziali Unical."; ?></p>
                        <?php endif; ?>
                    </section>
                    <?php endif; ?>

                    <?php if (!empty($dp['referenti'])): ?>
                    <section class="pj-box p-4">
                        <h2><i class="fa fa-address-book me-1" aria-hidden="true"></i>Contatti</h2>
                        <?php foreach ($dp['referenti'] as $rf) echo html_referente_pubblico($conn, $rf, $col_testo_area); ?>
                    </section>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
    <script nonce="<?php echo csp_nonce(); ?>">
    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('pjCopiaLink');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var url = location.origin + location.pathname + '?progetto=<?php echo (int)$ev_p['id']; ?>';
            var fatto = function () { btn.querySelector('span').textContent = 'Link copiato!'; setTimeout(function () { btn.querySelector('span').textContent = 'Copia il link'; }, 2000); };
            if (navigator.clipboard) navigator.clipboard.writeText(url).then(fatto, function () { prompt('Copia il link:', url); });
            else prompt('Copia il link:', url);
        });
    });
    </script>
