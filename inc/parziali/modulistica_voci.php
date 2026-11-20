<?php
// modulistica_voci.php - Le voci della modulistica divise per categoria (stessa grafica per la Didattica e per l'Amministrazione). Lo includono modulistica.php e
// modulistica_amministrativa.php, che preparano: $moduli (categoria => voci, da ModuloRepository::pubblicatiPerCategoria), $q (ricerca, minuscolo), $h (escape),
// $loggato. $mod_online_ammesso = false (modulistica amministrativa) non mostra mai «Compila online», anche se una riga fosse online per errore.
?>
    <?php foreach ($moduli as $cat => $voci):
        if ($q !== '') $voci = array_filter($voci, fn($m) => str_contains(mb_strtolower($m['titolo'] . ' ' . strip_tags((string)$m['descrizione']) . ' ' . $cat), $q));
        if (!$voci) continue; ?>
        <section class="card border-0 shadow-sm mb-3" style="border-radius:12px;"><div class="card-body">
            <h2 class="h5 fw-bold mb-1"><?php echo $h($cat); ?></h2>
            <?php foreach ($voci as $m): $online = $m['tipo'] === 'online' && ($mod_online_ammesso ?? true); [$aperto, $periodo] = periodo_modulo($m); ?>
                <div class="mod-voce">
                    <span class="mod-ico" style="background:<?php echo $online ? '#dcfce7' : '#dbeafe'; ?>;color:<?php echo $online ? '#15803d' : '#1d4ed8'; ?>;" aria-hidden="true"><i class="fa <?php echo $online ? 'fa-pen-to-square' : 'fa-file-arrow-down'; ?>"></i></span>
                    <div class="flex-grow-1" style="min-width:0;">
                        <h3 class="h6 fw-bold mb-1"><?php echo $h($m['titolo']); ?><?php if ($online && $periodo !== ''): ?> <span class="badge <?php echo $aperto ? 'bg-light text-dark border' : 'bg-secondary'; ?> fw-normal"><?php echo $h($periodo); ?></span><?php endif; ?></h3>
                        <?php if (trim(strip_tags((string)$m['descrizione'])) !== ''): ?><div class="small text-secondary"><?php echo strip_tags((string)$m['descrizione'], '<p><br><strong><em><ul><ol><li><a>'); ?></div><?php endif; ?>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <?php if ($online && $aperto): ?>
                                <a href="modulo.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-sm btn-success fw-bold"><i class="fa fa-pen me-1" aria-hidden="true"></i><?php echo $loggato ? 'Compila online' : 'Accedi e compila'; ?></a>
                            <?php endif; ?>
                            <?php if ($m['file_path']): ?><a href="<?php echo $h($m['file_path']); ?>" class="btn btn-sm btn-outline-primary fw-bold" download><i class="fa fa-download me-1" aria-hidden="true"></i>Scarica (<?php echo strtoupper(pathinfo($m['file_path'], PATHINFO_EXTENSION)); ?>)</a><?php endif; ?>
                            <?php if ($m['link']): ?><a href="<?php echo $h($m['link']); ?>" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"><i class="fa fa-up-right-from-square me-1" aria-hidden="true"></i>Approfondisci<span class="visually-hidden"> (si apre in una nuova scheda)</span></a><?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div></section>
    <?php endforeach; ?>
