<?php
// Campi personalizzati del modulo di iscrizione (testo, scelta, file, date, valutazione, scuola, corso di studio…), con i campi condizionali.
// Incluso dal modulo (inc/area_pubblica/modulo_iscrizione.php), nello stesso ambito: usa i parametri di printModalPrenotazione() e due variabili del modulo.
// Variabili che usa:
//   $t (il turno), $p_id (l'area), $conn, $is_progetto, $fsl_m, $classe_m (calcolate dal modulo prima di includere questo file)
// Usa anche $GLOBALS['logged_u_info'] (dati della persona che ha fatto l'accesso, per proporre la scuola).
                            $campi_array = [];
                            // Il numero di partecipanti compare solo nei progetti per le scuole, con l'etichetta "studenti"
                            foreach (\App\Core\App::get(\App\Iscrizioni\CampiFormRepository::class)->perModulo((int)$p_id, (int)$t['evento_id']) as $cfr) {
                                if (!campo_form_visibile($cfr, $is_progetto, $t['dett_progetto'] ?? null)) continue;
                                if ($fsl_m && $cfr['tipo_campo'] === 'scuola') continue; // la scuola si indica una volta sola, alla conferma del programma
                                if ($cfr['nome_campo'] === CAMPO_PARTECIPANTI) $cfr['etichetta'] = 'Numero di studenti partecipanti';
                                $campi_array[] = $cfr;
                            }
                            // Mappa id→nome_campo per risoluzione condizioni
                            $cf_id_to_name = [];
                            foreach ($campi_array as $cfr) $cf_id_to_name[(int)$cfr['id']] = $cfr['nome_campo'];
                            $has_conds = false;
                            foreach ($campi_array as $cfr) { if (!empty($cfr['condizione_json'])) { $has_conds = true; break; } }
                            if (!empty($campi_array)):
                        ?>
                            <div class="border-top pt-2 mt-2">
                                <div class="fw-bold small text-primary mb-2"><i class="fa fa-list-check me-1"></i> Informazioni Aggiuntive:</div>
                                <div class="row g-3" id="cfRow_<?php echo $t['id']; ?>">
                                <?php foreach ($campi_array as $cf):
                                    $type       = $cf['tipo_campo'];
                                    $input_name = 'custom_' . $cf['nome_campo'];
                                    $opts       = !empty($cf['opzioni_select']) ? array_map('trim', explode(',', $cf['opzioni_select'])) : [];
                                    $wrap_id    = 'cfWrap_' . $t['id'] . '_' . $cf['id'];
                                    $is_cond    = !empty($cf['condizione_json']);
                                    $cond_attrs = '';
                                    if ($is_cond) {
                                        $cj = json_decode($cf['condizione_json'], true) ?: [];
                                        $se_name = isset($cj['se_id']) ? ($cf_id_to_name[(int)$cj['se_id']] ?? '') : '';
                                        $se_val  = $cj['se_val'] ?? '';
                                        $cond_attrs = 'data-cond-name="' . htmlspecialchars($se_name) . '" data-cond-val="' . htmlspecialchars($se_val) . '"';
                                    }
                                    $req_attr  = ($cf['obbligatorio'] && !$is_cond) ? 'required' : '';
                                    $req_data  = $cf['obbligatorio'] ? 'data-orig-req="1"' : '';
                                    $asterisk  = ($cf['obbligatorio'] && !$is_cond) ? ' <span class="text-danger">*</span>' : '';
                                    $col_size  = in_array($type, ['separator','textarea','checkboxes','radio']) ? 'col-12' : 'col-md-6';
                                    $hide_init = $is_cond ? 'style="display:none;"' : '';
                                ?>

                                <?php if ($type === 'hidden'): ?>
                                    <input type="hidden" name="<?php echo htmlspecialchars($input_name); ?>" value="<?php echo htmlspecialchars($opts[0] ?? ''); ?>">

                                <?php elseif ($type === 'separator'): ?>
                                    <div class="col-12" id="<?php echo $wrap_id; ?>" <?php echo $cond_attrs; ?> <?php echo $hide_init; ?>>
                                        <hr class="my-1">
                                        <?php if (!empty($cf['etichetta']) && $cf['etichetta'] !== '-'): ?>
                                            <div class="fw-bold small text-uppercase text-secondary ui-ls-05em"><?php echo htmlspecialchars($cf['etichetta']); ?></div>
                                        <?php endif; ?>
                                    </div>

                                <?php else: ?>
                                    <div class="<?php echo $col_size; ?> mb-1" id="<?php echo $wrap_id; ?>" <?php echo $cond_attrs; ?> <?php echo $hide_init; ?>>
                                        <label class="form-label small fw-bold mb-1" id="<?php echo $wrap_id; ?>_et"><?php echo htmlspecialchars($cf['etichetta']) . $asterisk; ?></label>

                                        <?php if ($type === 'file'): ?>
                                            <input aria-labelledby="<?php echo $wrap_id; ?>_et" type="file" name="<?php echo htmlspecialchars($input_name); ?>[]" class="form-control form-control-sm" multiple <?php echo $req_attr; ?> <?php echo $req_data; ?>>

                                        <?php elseif ($type === 'select'): ?>
                                            <select aria-labelledby="<?php echo $wrap_id; ?>_et" name="<?php echo htmlspecialchars($input_name); ?>" class="form-select form-select-sm" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                                <option value="">-- Seleziona --</option>
                                                <?php foreach ($opts as $opt): ?><option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option><?php endforeach; ?>
                                            </select>

                                        <?php elseif ($type === 'radio'): ?>
                                            <div class="d-flex flex-wrap gap-2 mt-1">
                                                <?php foreach ($opts as $idx => $opt): ?>
                                                    <div class="form-check form-check-inline m-0 me-2">
                                                        <input class="form-check-input" type="radio" name="<?php echo htmlspecialchars($input_name); ?>" id="<?php echo $wrap_id . '_r' . $idx; ?>" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                                        <label class="form-check-label small" for="<?php echo $wrap_id . '_r' . $idx; ?>"><?php echo htmlspecialchars($opt); ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                        <?php elseif ($type === 'checkboxes'): ?>
                                            <div class="d-flex flex-wrap gap-2 mt-1">
                                                <?php foreach ($opts as $idx => $opt): ?>
                                                    <div class="form-check form-check-inline m-0 me-2">
                                                        <input class="form-check-input" type="checkbox" name="<?php echo htmlspecialchars($input_name); ?>[]" id="<?php echo $wrap_id . '_c' . $idx; ?>" value="<?php echo htmlspecialchars($opt); ?>">
                                                        <label class="form-check-label small" for="<?php echo $wrap_id . '_c' . $idx; ?>"><?php echo htmlspecialchars($opt); ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                        <?php elseif ($type === 'checkbox'): ?>
                                            <div class="form-check mt-1">
                                                <input class="form-check-input" type="checkbox" name="<?php echo htmlspecialchars($input_name); ?>" id="<?php echo $wrap_id; ?>_chk" value="Sì" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                                <label class="form-check-label small" for="<?php echo $wrap_id; ?>_chk"><?php echo htmlspecialchars($cf['etichetta']); ?></label>
                                            </div>

                                        <?php elseif ($type === 'textarea'): ?>
                                            <textarea aria-labelledby="<?php echo $wrap_id; ?>_et" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" rows="2" <?php echo $req_attr; ?> <?php echo $req_data; ?>></textarea>

                                        <?php elseif ($type === 'rating'): ?>
                                            <div class="d-flex gap-1 mt-1">
                                                <input type="hidden" name="<?php echo htmlspecialchars($input_name); ?>" id="<?php echo $wrap_id; ?>_rating" value="" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                                <?php $nstars = max(5, count($opts)); for ($s = 1; $s <= $nstars; $s++): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-warning px-2 py-1 star-btn ui-fs-120 ui-lh-1"
                                                        data-val="<?php echo $s; ?>"
                                                        data-target="<?php echo $wrap_id; ?>_rating"
                                                        data-on-click="evSetRating" data-args='["@this"]'>☆</button>
                                                <?php endfor; ?>
                                            </div>

                                        <?php elseif ($type === 'date'): ?>
                                            <input aria-labelledby="<?php echo $wrap_id; ?>_et" type="date" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                        <?php elseif ($type === 'number'):
                                            // Progetti per le scuole: numero di partecipanti dentro i limiti del progetto (ricontrollato dal server)
                                            $lim_attr = '';
                                            if ($classe_m && $cf['nome_campo'] === CAMPO_PARTECIPANTI) {
                                                $lim_attr = 'min="' . (int)($t['limite_studenti_min'] ?? 1) . '" step="1"' . (!empty($t['limite_studenti_max']) ? ' max="' . (int)$t['limite_studenti_max'] . '"' : '');
                                            }
                                        ?>
                                            <input aria-labelledby="<?php echo $wrap_id; ?>_et" type="number" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" <?php echo $lim_attr; ?> <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                            <?php if ($lim_attr !== ''): ?><div class="form-text">Tra <?php echo (int)($t['limite_studenti_min'] ?? 1); ?> e <?php echo !empty($t['limite_studenti_max']) ? (int)$t['limite_studenti_max'] : 'il massimo previsto'; ?> studenti.</div><?php endif; ?>
                                        <?php elseif ($type === 'email'): ?>
                                            <input aria-labelledby="<?php echo $wrap_id; ?>_et" type="email" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" autocomplete="email" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                        <?php elseif ($type === 'tel'): ?>
                                            <input aria-labelledby="<?php echo $wrap_id; ?>_et" type="tel" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" placeholder="+39 333 0000000" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                        <?php elseif ($type === 'url'): ?>
                                            <input aria-labelledby="<?php echo $wrap_id; ?>_et" type="url" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" placeholder="https://" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                        <?php elseif ($type === 'time'): ?>
                                            <input aria-labelledby="<?php echo $wrap_id; ?>_et" type="time" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                        <?php elseif ($type === 'corso_studio'): ?>
                                            <?php echo html_campo_corso($conn, $cf['nome_campo'], '', trim($req_attr . ' ' . $req_data)); ?>
                                        <?php elseif ($type === 'scuola'):
                                            // Scuola dall'anagrafe del Ministero: proposta quella indicata l'ultima volta dal docente
                                            $scu_pre = scuola_per_codice($conn, $GLOBALS['logged_u_info']['scuola_codice'] ?? '');
                                            echo html_campo_scuola($cf['nome_campo'], $scu_pre ? etichetta_scuola($scu_pre) : '', $scu_pre['codice'] ?? '', trim($req_attr . ' ' . $req_data));
                                        ?>
                                        <?php else: ?>
                                            <input aria-labelledby="<?php echo $wrap_id; ?>_et" type="text" name="<?php echo htmlspecialchars($input_name); ?>" class="form-control form-control-sm" <?php echo $req_attr; ?> <?php echo $req_data; ?>>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                </div>
                            </div>

                        <?php if ($has_conds): ?>
                        <script nonce="<?php echo csp_nonce(); ?>">
                        (function() {
                            var row = document.getElementById('cfRow_<?php echo $t['id']; ?>');
                            if (!row) return;
                            function getVal(name) {
                                var inputs = row.querySelectorAll('[name="custom_' + name + '"]');
                                if (!inputs.length) return '';
                                var first = inputs[0];
                                if (first.type === 'radio') {
                                    var chk = row.querySelector('[name="custom_' + name + '"]:checked');
                                    return chk ? chk.value.trim() : '';
                                }
                                if (first.type === 'checkbox') return first.checked ? first.value.trim() : '';
                                if (first.tagName === 'SELECT') return first.value.trim();
                                return first.value.trim();
                            }
                            function aggiorna() {
                                row.querySelectorAll('[data-cond-name]').forEach(function(wrap) {
                                    var trigName = wrap.dataset.condName;
                                    var trigVal  = wrap.dataset.condVal.trim().toLowerCase();
                                    var curVal   = getVal(trigName).toLowerCase();
                                    var show     = (curVal === trigVal);
                                    wrap.style.display = show ? '' : 'none';
                                    wrap.querySelectorAll('input,select,textarea').forEach(function(inp) {
                                        if (show && inp.dataset.origReq === '1') inp.required = true;
                                        else { inp.required = false; if (!show) inp.value = ''; }
                                    });
                                });
                            }
                            row.addEventListener('change', aggiorna);
                            row.addEventListener('input', aggiorna);
                        })();
                        </script>
                        <?php endif; ?>

                        <?php endif; // end !empty($campi_array) ?>