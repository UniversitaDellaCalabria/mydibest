<?php
// Layout «calendario»: calendario interattivo a tutto schermo (FullCalendar) con i turni dell'area.
// Caricato da master_template.php (stesso ambito della pagina) quando serve questo disegno.
// Variabili che usa (arrivano da master_template.php o dalla funzione che lo include):
//   $banner_manutenzione_admin, $messaggio_prenotazione, $page_cfg, $all_turni_flat, $current_filename, $col_primaria, $utente_logged
//   $utente_ruolo_id, $conn, $nomi_ruoli, $json_events_calendar
?>
    <div class="ui-larg-cont container mb-5 mt-4">
        <?php echo $banner_manutenzione_admin; ?>
        <?php echo $messaggio_prenotazione; ?>
        
        <div class="card shadow-lg border-0 rounded-4 overflow-hidden mt-3">
            <div class="ui-area-bg card-header p-4">
                <h2 class="fw-bold m-0"><i class="fa fa-calendar-days me-2" aria-hidden="true"></i> <?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'Calendario Eventi'); ?></h2>
                <p class="m-0 mt-1 opacity-75">Clicca su un evento nel calendario per visualizzare i dettagli e prenotarti.</p>
            </div>
            <div class="card-body p-4 bg-white">
                <div id="fullCalendarDiv"></div>
            </div>
        </div>

        <?php
        // I turni senza data non possono stare nel calendario: elencati qui sotto, prenotabili come gli altri
        $turni_senza_data = array_values(array_filter($all_turni_flat, fn($t) => empty($t['data_turno'])));
        ?>
        <?php if ($turni_senza_data): ?>
        <div class="card shadow-sm border-0 rounded-4 mt-4">
            <div class="card-body p-4">
                <h2 class="ui-area-testo-ok fw-bold fs-4 mb-1"><i class="fa fa-infinity me-2" aria-hidden="true"></i>Attività senza data fissa</h2>
                <p class="text-secondary small mb-3">Non compaiono nel calendario perché non hanno un giorno stabilito.</p>
                <div class="list-group list-group-flush">
                    <?php foreach ($turni_senza_data as $t_nd): ?>
                        <div class="list-group-item px-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <div class="fw-bold"><a href="<?php echo htmlspecialchars($current_filename); ?>.php?evento=<?php echo (int)$t_nd['evento_id']; ?>" class="text-dark"><?php echo htmlspecialchars($t_nd['evento_titolo']); ?></a></div>
                                <div class="small text-secondary">
                                    <?php echo htmlspecialchars(etichetta_turno($t_nd)); ?>
                                    <?php if (!empty($t_nd['evento_luogo'])): ?> · <i class="fa fa-map-marker-alt" aria-hidden="true"></i> <?php echo htmlspecialchars($t_nd['evento_luogo']); ?><?php endif; ?>
                                </div>
                            </div>
                            <div><?php echo getPulsanteAzione($t_nd, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php
    // Il calendario si apre sul mese del primo turno ancora da svolgere (non sempre sul mese corrente)
    $data_iniziale_cal = null;
    foreach ($all_turni_flat as $t_c) {
        if (!empty($t_c['data_turno']) && !turno_concluso($t_c)) { $data_iniziale_cal = $t_c['data_turno']; break; }
    }
    ?>
    <script src="<?php echo url_vendor('jsdelivr/npm/fullcalendar@6.1.11/index.global.min.js'); ?>" integrity="sha384-5JIwZN3kuxX2zKsavvNmbZ3zhZZMUtu/eQiK3BbXukpSXp0Cd2ZP4OAYKx7mrPgI" crossorigin="anonymous"></script>
    <script nonce="<?php echo csp_nonce(); ?>">
      document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('fullCalendarDiv');
        var calendar = new FullCalendar.Calendar(calendarEl, {
          initialView: 'dayGridMonth', locale: 'it',
          <?php if ($data_iniziale_cal): ?>initialDate: <?php echo json_encode($data_iniziale_cal); ?>,<?php endif; ?>
          headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listWeek' },
          events: <?php echo json_encode($json_events_calendar, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
          eventClick: function(info) {
              info.jsEvent.preventDefault();
              var myModal = new bootstrap.Modal(document.getElementById('modPrenota' + info.event.extendedProps.turno_id));
              myModal.show();
          },
          eventContent: function(arg) {
            // Costruito con nodi DOM (textContent): titolo e luogo non vengono mai interpretati come HTML
            var box = document.createElement('div');
            box.className = 'p-1';
            box.style.cssText = 'white-space:normal; font-size:0.8rem; font-weight:bold; overflow:hidden;';
            box.appendChild(document.createTextNode(arg.event.title));
            if (arg.event.extendedProps.luogo) {
              box.appendChild(document.createElement('br'));
              var s = document.createElement('small'); s.textContent = '📍 ' + arg.event.extendedProps.luogo; box.appendChild(s);
            }
            return { domNodes: [box] };
          }
        });
        calendar.render();
      });
    </script>
