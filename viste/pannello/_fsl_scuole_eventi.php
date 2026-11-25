<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: s (la scheda), campoCsrf, puoFsl, chiaveUrl, oggi
$s = $d->s;
$csrf = $d->grezzo('campoCsrf');
$puoFsl = (bool) $d->grezzo('puoFsl');
$eventi = $s->elenco('eventi');
$toniStato = ['confermata' => 'ok', 'in_attesa' => 'attesa', 'da_approvare' => 'attesa', 'richiesta_conferma' => 'attesa', 'annullata' => 'spento', 'rifiutata' => 'spento', 'scaduta' => 'spento'];
$data = static fn (string $v): string => date('d/m/Y', (int) strtotime($v));
?>
<?php if (!$eventi): ?>
    <p class="text-secondary mb-0">Nessun evento o progetto <?php echo (int) $s->anno > 0 ? "in quest'anno scolastico: scegli «Tutti gli anni» in alto per vedere gli altri" : 'per questa scuola'; ?>.</p>
<?php else: ?>
    <?php foreach ($eventi as $e): $attiva = (bool) $e->grezzo('attiva'); ?>
        <article class="sc-evento<?php echo $attiva ? '' : ' spento'; ?>">
            <h3><?php if ($e->grezzo('puo_iscritti')): ?><a href="iscritti.php?p_id=<?php echo $e->pagina_id; ?>&amp;f_cerca=<?php echo \App\Http\DatiVista::escapa(urlencode((string) $e->grezzo('codice'))); ?>"><?php echo $e->titolo; ?></a><?php else: ?><?php echo $e->titolo; ?><?php endif; ?>
                <?php echo \App\Portale\Vista\Pannello::stato((string) $e->grezzo('stato_testo'), $toniStato[(string) $e->grezzo('stato')] ?? 'info'); ?>
                <?php if ($e->grezzo('conv') === 'coperta'): ?><?php echo \App\Portale\Vista\Pannello::stato('Coperta dalla convenzione n. ' . (int) $e->grezzo('conv_id'), 'ok'); ?>
                <?php elseif ($e->grezzo('conv') === 'non_coperta'): ?><?php echo $e->grezzo('in_stipula') ? \App\Portale\Vista\Pannello::stato('In attesa della convenzione', 'lavoro') : \App\Portale\Vista\Pannello::stato('Non coperta', 'blocco'); ?>
                <?php else: ?><?php echo \App\Portale\Vista\Pannello::stato($e->grezzo('solo_orientamento') ? 'Solo orientamento: convenzione non richiesta' : 'Convenzione non richiesta', 'spento'); ?><?php endif; ?></h3>
            <?php if ($e->grezzo('conv') === 'non_coperta' && $e->grezzo('perche') !== ''): $stipula = (bool) $e->grezzo('in_stipula'); ?><p class="small <?php echo $stipula ? 'text-secondary' : 'text-danger'; ?> mb-0 mt-1"><i class="fa <?php echo $stipula ? 'fa-hourglass-half' : 'fa-triangle-exclamation'; ?> me-1" aria-hidden="true"></i><?php echo $e->perche; ?></p><?php endif; ?>
            <dl class="sc-dati">
                <div><dt>Area</dt><dd><?php echo $e->area; ?><?php echo $e->grezzo('turno') !== '' ? ' · ' . $e->turno : ''; ?></dd></div>
                <div><dt>Date</dt><dd><?php echo $e->grezzo('dal') === $e->grezzo('al') ? $data((string) $e->grezzo('dal')) : $data((string) $e->grezzo('dal')) . ' – ' . $data((string) $e->grezzo('al')); ?><?php echo $e->grezzo('conclusa') && $attiva ? ' · conclusa' : ''; ?></dd></div>
                <div><dt>Sede</dt><dd><?php echo $e->grezzo('sede_nome') !== '' ? $e->sede_nome . ($e->grezzo('sede_comune') !== '' ? ' (' . $e->sede_comune . ')' : '') : ($e->grezzo('scuola_scritta') !== '' ? $e->scuola_scritta . " (scritta a mano)" : '—'); ?><?php echo $e->grezzo('sede_diversa') ? ' · altra sede dell\'istituto' : ''; ?></dd></div>
                <div><dt>Docente</dt><dd><?php echo $e->docente !== '' ? $e->docente : '—'; ?><?php if ($e->grezzo('email') !== ''): ?><br><a href="mailto:<?php echo $e->email; ?>"><?php echo $e->email; ?></a><?php endif; ?><?php if ($e->grezzo('telefono') !== ''): ?><br><a href="tel:<?php echo \App\Http\DatiVista::escapa(preg_replace('/[^0-9+]/', '', (string) $e->grezzo('telefono'))); ?>"><?php echo $e->telefono; ?></a><?php endif; ?></dd></div>
                <?php if ($puoFsl && $e->grezzo('studenti') !== null): ?><div><dt>Studenti</dt><dd><?php echo (int) $e->grezzo('studenti') > 0 ? $e->studenti : '—'; ?><?php echo $e->grezzo('classe') ? ' · elenco: ' . (int) $e->grezzo('n_elenco') : ''; ?></dd></div><?php endif; ?>
                <?php if ($puoFsl && $e->grezzo('fsl')): ?><div><dt>Autorizzazione</dt><dd><?php echo $e->grezzo('autorizzazione') ? '<a href="../autorizzazione_scuola.php?code=' . \App\Http\DatiVista::escapa(urlencode((string) $e->grezzo('codice'))) . '" target="_blank" rel="noopener">Vedi il file</a>' : ($e->grezzo('autorizzazione_manca') ? '<span class="sc-manca">manca: il termine è passato</span>' : 'non caricata'); ?></dd></div><?php endif; ?>
                <?php if ($e->grezzo('classe')): ?><div><dt>Attestati</dt><dd><?php echo ['inviati' => 'inviati', 'da_inviare' => 'da inviare'][(string) $e->grezzo('attestati')] ?? '—'; ?></dd></div><?php endif; ?>
            </dl>
            <?php if ($e->grezzo('classe') && $puoFsl): ?>
                <div class="sc-azioni">
                    <?php if ((int) $e->grezzo('n_elenco') > 0): ?>
                        <?php if ($e->grezzo('puo_studenti')): ?><a class="btn btn-sm btn-outline-secondary fw-bold" href="partecipanti.php?p_id=<?php echo $e->pagina_id; ?>&amp;pr=<?php echo $e->id; ?>"><i class="fa fa-list me-1" aria-hidden="true"></i>Elenco studenti</a><?php else: ?><span class="small text-secondary">Elenco studenti caricato (<?php echo $e->n_elenco; ?>)</span><?php endif; ?>
                    <?php else: ?>
                        <?php if ($e->grezzo('elenco_manca')): ?><span class="small sc-manca"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Elenco studenti mancante: il termine per consegnarlo è passato</span><?php else: ?><span class="small text-secondary"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>Elenco studenti non ancora caricato</span><?php endif; ?>
                        <?php if ($e->grezzo('ricorda_elenco')): ?><form method="POST"><?php echo $csrf; ?><button type="submit" name="ricorda_elenco" value="<?php echo $e->id; ?>" class="btn btn-sm btn-outline-secondary fw-bold" data-conferma="Mandare al docente il promemoria per inserire l'elenco degli studenti?"><i class="fa fa-bell me-1" aria-hidden="true"></i>Ricorda</button></form><?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
<?php endif; ?>
