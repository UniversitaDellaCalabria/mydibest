<?php
// Scheda di un evento (?evento=ID), con qualsiasi layout dell'area: testata, fatti in breve, descrizione, turni con posti e pulsanti di prenotazione, contatti.
// Caricato da master_template.php (stesso ambito della pagina) quando serve questo disegno.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $evento_sel, $dettagli_progetti, $col_primaria, $conn, $mie_iscrizioni, $col_testo_area, $current_filename, $page_cfg, $etichette_riservato
//   $utente_logged, $utente_ruolo_id, $nomi_ruoli
    $ev_s   = $evento_sel;
    $dett_s = $dettagli_progetti[(int)$ev_s['id']] ?? [];
    $txt_on_s = colore_testo_su($col_primaria);
    $req_s  = (int)($ev_s['richiede_prenotazione'] ?? 1) === 1;
    $ruolo_s = (int)($ev_s['ruolo_accesso_id'] ?? 0);
    // Turni con posti e stato
    $turni_s = []; $liberi_s = 0; $prossima_s = null; $illimitati_s = false;
    foreach ($ev_s['turni'] as $t) {
        $occ = $req_s ? getPostiOccupati($conn, $t['id']) : 0;
        $max = (int)$t['max_posti'];
        $concluso = turno_concluso($t);
        if (!$concluso) {
            if ($max >= POSTI_SENZA_LIMITE) $illimitati_s = true; else $liberi_s += max(0, $max - $occ);
            if (!empty($t['data_turno']) && ($prossima_s === null || $t['data_turno'] < $prossima_s)) $prossima_s = $t['data_turno'];
        }
        $turni_s[] = ['t' => $t, 'occ' => $occ, 'max' => $max, 'liberi' => max(0, $max - $occ), 'concluso' => $concluso];
    }
    $mie_s = $mie_iscrizioni[(int)$ev_s['id']] ?? [];
    // Google Maps: ricerca del luogo (se non è un indirizzo completo si aggiunge l'Università della Calabria)
    $luogo_s = trim((string)($ev_s['luogo'] ?? ''));
    $query_maps = $luogo_s !== '' ? (preg_match('/unical|universit|via |piazza|rende|cosenza/i', $luogo_s) ? $luogo_s : $luogo_s . ', Università della Calabria, Rende') : '';
    $url_maps = $query_maps !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($query_maps) : '';
?>
    <style>
        .ev-hero { background: <?php echo $col_primaria; ?>; color: <?php echo $txt_on_s; ?>; border-radius: 18px; padding: 2rem; position: relative; overflow: hidden; }
        .ev-hero::after { content: ""; position: absolute; right: -80px; top: -80px; width: 260px; height: 260px; border-radius: 50%; background: rgba(255,255,255,.10); }
        .ev-hero > * { position: relative; z-index: 1; }
        .ev-hero h1 { color: inherit; font-weight: 800; letter-spacing: -.5px; font-size: clamp(1.4rem, 2.6vw, 2.25rem); line-height: 1.2; }
        .ev-hero a { color: inherit; }
        .ev-chip { display: inline-flex; align-items: center; gap: .35rem; font-size: .78rem; font-weight: 700; padding: .3rem .75rem; border-radius: 999px; background: rgba(255,255,255,.18); }
        .ev-box { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; box-shadow: 0 2px 10px rgba(15,23,42,.05); }
        .ev-box h2 { font-size: .8rem; text-transform: uppercase; letter-spacing: .07em; font-weight: 800; color: #475569; margin-bottom: 1rem; }
        .ev-desc { font-size: 1rem; line-height: 1.75; color: #1f2937; overflow-wrap: anywhere; }
        .ev-desc img { max-width: 100%; height: auto; }
        .ev-fatti { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: .75rem; }
        .ev-fatto { display: flex; gap: .75rem; align-items: center; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: .8rem 1rem; }
        .ev-fatto-ico { flex: 0 0 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: <?php echo $col_primaria; ?>1A; color: <?php echo $col_testo_area; ?>; }
        .ev-fatto dt { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 700; }
        .ev-fatto dd { margin: 0; font-weight: 700; color: #111827; }
        .ev-turno { border: 1px solid #e5e7eb; border-radius: 12px; padding: .85rem 1rem; background: #fff; }
        .ev-turno.concluso { opacity: .6; }
        .ev-persona { display: flex; gap: .75rem; align-items: flex-start; padding: .75rem 0; border-top: 1px solid #f1f5f9; }
        .ev-persona:first-of-type { border-top: 0; padding-top: 0; }
        .ev-avatar { flex: 0 0 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; background: <?php echo $col_primaria; ?>; color: <?php echo $txt_on_s; ?>; }
        .ev-persona a { color: #1f2937; text-decoration: none; overflow-wrap: anywhere; }
        .ev-persona a:hover { text-decoration: underline; }
        @media (min-width: 992px) { .ev-side { position: sticky; top: 110px; } }
        @media (max-width: 575.98px) { .ev-hero { padding: 1.25rem; border-radius: 14px; } .ev-fatti { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; } .ev-fatto { flex-direction: column; align-items: flex-start; gap: .4rem; padding: .7rem; } }
    </style>

    <div class="ui-larg-cont container mb-5">
        <nav aria-label="Percorso" class="my-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <a href="<?php echo htmlspecialchars($current_filename); ?>.php" class="ui-area-testo-ok fw-bold text-decoration-none"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i><?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'Tutti gli eventi'); ?></a>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" id="evCopiaLink"><i class="fa fa-link me-1" aria-hidden="true"></i><span>Copia il link</span></button>
        </nav>

        <header class="ev-hero shadow-sm mb-4">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <?php if (!empty($ev_s['nome_sottocategoria'])): ?><span class="ev-chip"><i class="fa fa-folder-open" aria-hidden="true"></i><?php echo htmlspecialchars($ev_s['nome_sottocategoria']); ?></span><?php endif; ?>
                <?php if (!$req_s): ?><span class="ev-chip"><i class="fa fa-unlock" aria-hidden="true"></i>Ingresso libero</span>
                <?php elseif ($ruolo_s === -1): ?><span class="ev-chip"><i class="fa fa-key" aria-hidden="true"></i>Solo utenti autenticati</span>
                <?php elseif ($ruolo_s > 0): ?><span class="ev-chip"><i class="fa fa-lock" aria-hidden="true"></i><?php echo htmlspecialchars($etichette_riservato[$ruolo_s] ?? 'Riservato'); ?></span><?php endif; ?>
            </div>
            <h1 class="mb-3"><?php echo htmlspecialchars($ev_s['titolo']); ?></h1>
            <?php $breve_s = pulisci_descrizione_breve((string)($ev_s['descrizione_breve'] ?? '')); if ($breve_s !== ''): ?><p class="fs-5 mb-3 ui-maxw-820" style="opacity:.92;"><?php echo $breve_s; ?></p><?php endif; ?>
            <div class="d-flex flex-wrap gap-3 fw-semibold">
                <?php if ($prossima_s): ?><span><i class="fa fa-calendar-days me-1" aria-hidden="true"></i><?php echo count($turni_s) > 1 ? 'Prossimo: ' : ''; ?><?php echo date('d/m/Y', strtotime($prossima_s)); ?></span><?php endif; ?>
                <?php if ($luogo_s !== ''): ?><span><i class="fa fa-location-dot me-1" aria-hidden="true"></i><?php echo htmlspecialchars($luogo_s); ?><?php if ($url_maps): ?> · <a href="<?php echo htmlspecialchars($url_maps); ?>" target="_blank" rel="noopener" class="text-decoration-underline">Apri in Google Maps<span class="visually-hidden"> (nuova scheda)</span></a><?php endif; ?></span><?php endif; ?>
                <?php $corso_s = html_corso_pubblico($conn, $dett_s); if ($corso_s !== ''): ?><span><i class="fa fa-building-columns me-1" aria-hidden="true"></i><?php echo $corso_s; ?></span><?php endif; ?>
                <?php $ins_s = !empty($dett_s['insegnamento_id']) ? insegnamento($conn, (int)$dett_s['insegnamento_id']) : null;
                if ($ins_s): $info_ins = array_filter([!empty($ins_s['anno_corso']) ? (int)$ins_s['anno_corso'] . '° anno' : '', $ins_s['semestre'], $ins_s['docente'] !== '' ? 'Docente ' . $ins_s['docente'] : '']); ?>
                    <span><i class="fa fa-book-open me-1" aria-hidden="true"></i>Insegnamento: <?php echo htmlspecialchars($ins_s['nome'] . ($info_ins ? ' · ' . implode(' · ', $info_ins) : '')); ?></span>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($req_s && $turni_s): ?>
            <dl class="ev-fatti mb-4">
                <div class="ev-fatto"><span class="ev-fatto-ico" aria-hidden="true"><i class="fa fa-clock"></i></span><div><dt><?php echo count($turni_s) === 1 ? 'Turno' : 'Turni'; ?></dt><dd><?php echo count($turni_s); ?></dd></div></div>
                <div class="ev-fatto"><span class="ev-fatto-ico" aria-hidden="true"><i class="fa fa-users"></i></span><div><dt>Posti liberi</dt><dd><?php echo $illimitati_s ? 'Senza limite' : ($liberi_s > 0 ? $liberi_s : 'Esauriti'); ?></dd></div></div>
                <?php $fin_s = finestra_prenotazione($ev_s['turni']); if ($fin_s): ?><div class="ev-fatto"><span class="ev-fatto-ico" aria-hidden="true"><i class="fa <?php echo $fin_s['icona']; ?>"></i></span><div><dt>Prenotazioni</dt><dd><?php echo htmlspecialchars(ucfirst(preg_replace('/^Prenota(zioni)? /', '', $fin_s['testo']))); ?></dd></div></div><?php endif; ?>
                <?php if ($luogo_s !== ''): ?><div class="ev-fatto"><span class="ev-fatto-ico" aria-hidden="true"><i class="fa fa-map-location-dot"></i></span><div><dt>Luogo</dt><dd><?php echo htmlspecialchars($luogo_s); ?></dd></div></div><?php endif; ?>
                <?php if ((int)($ev_s['abilita_presenze'] ?? 1) === 1): ?><div class="ev-fatto"><span class="ev-fatto-ico" aria-hidden="true"><i class="fa fa-qrcode"></i></span><div><dt>Presenza</dt><dd>Check-in con QR</dd></div></div><?php endif; ?>
            </dl>
        <?php endif; ?>

        <div class="row g-4 align-items-start">
            <div class="col-lg-7">
                <?php if (!empty($ev_s['locandina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $ev_s['locandina_path'])): ?>
                    <img src="<?php echo htmlspecialchars($ev_s['locandina_path']); ?>" alt="" class="w-100 mb-4 shadow-sm ui-r-14 ui-maxh-420 ui-of-cover">
                <?php endif; ?>
                <?php // Seminario: relatore, abstract, diretta (finché l'evento non è concluso), registrazione e slide
                if (function_exists('html_seminario')) echo html_seminario($ev_s, !empty($ev_s['turni']) && !array_filter($ev_s['turni'], fn($t_x) => !turno_concluso($t_x))); ?>
                <article class="ev-box p-4 mb-4">
                    <h2><i class="fa fa-book-open me-1" aria-hidden="true"></i>L'evento</h2>
                    <div class="ev-desc"><?php echo !empty($ev_s['descrizione']) ? $ev_s['descrizione'] : '<p class="text-muted">Descrizione in arrivo.</p>'; ?></div>
                    <?php if (!empty($ev_s['allegato_pdf'])): ?>
                        <a href="<?php echo htmlspecialchars($ev_s['allegato_pdf']); ?>" target="_blank" rel="noopener" class="btn btn-outline-danger fw-bold mt-3"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Programma (PDF)</a>
                    <?php endif; ?>
                </article>
            </div>

            <aside class="col-lg-5">
                <div class="ev-side d-flex flex-column gap-4">
                    <?php if ($turni_s): ?>
                    <section class="ui-area-bt4 ev-box p-4" id="turni">
                        <h2><i class="fa fa-calendar-check me-1" aria-hidden="true"></i><?php echo $req_s ? (count($turni_s) > 1 ? 'Turni e prenotazione' : 'Prenotazione') : (count($turni_s) > 1 ? 'Date e orari' : 'Data e orario'); ?></h2>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($turni_s as $rs): $t = $rs['t'];
                                $tf = $t + ['richiede_prenotazione' => $ev_s['richiede_prenotazione'], 'ruolo_accesso_id' => $ev_s['ruolo_accesso_id']];
                                $mio_t = $mie_s[(int)$t['id']] ?? null;
                                $pct = $rs['max'] > 0 && $rs['max'] < POSTI_SENZA_LIMITE ? min(100, (int)round($rs['occ'] / $rs['max'] * 100)) : 0;
                            ?>
                                <div class="ev-turno<?php echo $rs['concluso'] ? ' concluso' : ''; ?>">
                                    <div class="fw-bold">
                                        <?php echo !empty($t['data_turno']) ? formattaDataItaliano($t['data_turno']) : '<i class="fa fa-infinity me-1" aria-hidden="true"></i>Data da definire'; ?>
                                    </div>
                                    <div class="small text-secondary d-flex flex-wrap gap-2">
                                        <?php if (!empty($t['nome_turno'])): ?><span><i class="fa fa-tag me-1" aria-hidden="true"></i><?php echo htmlspecialchars($t['nome_turno']); ?></span><?php endif; ?>
                                        <?php if (orario_turno($t) !== ''): ?><span><i class="fa fa-clock me-1" aria-hidden="true"></i><?php echo orario_turno($t); ?></span><?php endif; ?>
                                    </div>
                                    <?php $fin_t = $req_s ? finestra_prenotazione([$t]) : null; if ($fin_t): ?>
                                        <div class="mt-2"><span class="badge" style="font-size:.78rem; background:<?php echo $fin_t['bg']; ?>; color:<?php echo $fin_t['fg']; ?>;"><i class="fa <?php echo $fin_t['icona']; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($fin_t['testo']); ?></span></div>
                                    <?php endif; ?>
                                    <?php if ($req_s && !$rs['concluso'] && $rs['max'] > 0 && $rs['max'] < POSTI_SENZA_LIMITE): ?>
                                        <div class="d-flex align-items-center gap-2 mt-2">
                                            <div class="progress flex-grow-1 ui-h-6" role="progressbar" aria-label="Posti occupati" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?php echo $pct >= 100 ? 'bg-danger' : ($pct >= 75 ? 'bg-warning' : 'bg-success'); ?>" style="width: <?php echo $pct; ?>%;"></div></div>
                                            <span class="small fw-bold text-nowrap <?php echo $rs['liberi'] > 0 ? 'text-success' : 'text-danger'; ?>"><?php echo $rs['liberi'] > 0 ? $rs['liberi'] . ' su ' . $rs['max'] : 'Completo'; ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                                        <?php if ($mio_t === 'in_attesa' || $mio_t === 'richiesta_conferma'): ?>
                                            <a href="area_personale.php" class="btn btn-sm btn-outline-warning text-dark fw-bold"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i><?php echo $mio_t === 'in_attesa' ? "Sei in lista d'attesa" : 'Posto offerto: conferma'; ?></a>
                                        <?php elseif ($mio_t !== null): ?>
                                            <a href="area_personale.php" class="btn btn-sm btn-success fw-bold"><i class="fa fa-check me-1" aria-hidden="true"></i>Sei iscritto</a>
                                        <?php else: ?>
                                            <?php echo getPulsanteAzione($tf, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($t['data_turno']) && !$rs['concluso']): ?>
                                            <a href="<?php echo htmlspecialchars(getGoogleCalendarUrl($ev_s['titolo'], $t['data_turno'], $t['orario_inizio'], $t['orario_fine'], $luogo_s, $ev_s['titolo'])); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Aggiungi a Google Calendar" aria-label="Aggiungi a Google Calendar"><i class="fa-brands fa-google" aria-hidden="true"></i></a>
                                            <a href="genera_ics.php?t_id=<?php echo (int)$t['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Aggiungi al calendario (Outlook, Apple)" aria-label="Scarica il file per il calendario"><i class="fa fa-calendar-plus" aria-hidden="true"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($req_s && \App\Eventi\AccessoEvento::serveAccesso($ruolo_s) && !$utente_logged): ?>
                            <p class="small text-secondary mt-3 mb-0">Per prenotare accedi con SPID, CIE o credenziali Unical.</p>
                        <?php endif; ?>
                    </section>
                    <?php endif; ?>

                    <?php if (!empty($dett_s['referenti'])): ?>
                    <section class="ev-box p-4">
                        <h2><i class="fa fa-address-book me-1" aria-hidden="true"></i>Contatti</h2>
                        <?php foreach ($dett_s['referenti'] as $rf) echo html_referente_pubblico($conn, $rf, $col_testo_area); ?>
                    </section>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
    <script nonce="<?php echo csp_nonce(); ?>">
    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('evCopiaLink');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var url = location.origin + location.pathname + '?evento=<?php echo (int)$ev_s['id']; ?>';
            var fatto = function () { btn.querySelector('span').textContent = 'Link copiato!'; setTimeout(function () { btn.querySelector('span').textContent = 'Copia il link'; }, 2000); };
            if (navigator.clipboard) navigator.clipboard.writeText(url).then(fatto, function () { prompt('Copia il link:', url); });
            else prompt('Copia il link:', url);
        });
    });
    </script>
