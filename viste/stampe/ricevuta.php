<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Documento intero, senza testata del sito. Dati: p (la prenotazione, con i valori di ripiego già applicati), senza_prenotazione (evento ad accesso libero: niente codice né QR), qr (HTML), css_bootstrap, css_icone, col_ric, col_ric_txt, col_ric_testo, custom (elenco di chiave/valore/allegato) ?>
<?php $p = $d->p; $custom = $d->elenco('custom'); $senza = $d->grezzo('senza_prenotazione') === true; ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Ricevuta - <?php echo $senza ? 'presenza' : $p->codice_prenotazione; ?></title>
    <link href="<?php echo $d->css_bootstrap; ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $d->css_icone; ?>">
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', sans-serif; color: #334155; }
        .ticket-box { max-width: 800px; margin: 30px auto; background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border: 2px solid #e2e8f0; overflow: hidden; }
        .ticket-header { background: <?php echo $d->col_ric; ?>; color: <?php echo $d->col_ric_txt; ?>; padding: 25px; border-bottom: 4px solid rgba(0,0,0,.25); }
        .ticket-body { padding: 30px; }
        .code-badge { font-size: 1.4rem; font-weight: 800; background: #f1f5f9; color: #0f172a; padding: 8px 16px; border-radius: 8px; border: 1px dashed #cbd5e1; display: inline-block; letter-spacing: 2px; }
        @media print { body { background: white; } .no-print { display: none !important; } .ticket-box { box-shadow: none; border: 1px solid #000; margin: 0; max-width: 100%; } }
    </style>
</head>
<body>

    <div class="container text-center my-4 no-print">
        <!-- Aggiornato il colore del bottone -->
        <button data-stampa class="btn fw-bold px-4 py-2 shadow-sm" style="background:<?php echo $d->col_ric; ?>; color:<?php echo $d->col_ric_txt; ?>; border:none;"><i class="fa fa-print me-1"></i> Stampa / PDF</button>
        <button data-chiudi-finestra class="btn btn-outline-secondary btn-sm ms-2">Chiudi</button>
    </div>

    <div class="ticket-box">
        <div class="ticket-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <!-- Rimosso il background bianco e il padding dal logo -->
                <?php if (!empty($p->logo_path)): ?><img src="<?php echo $p->logo_path; ?>" alt="Logo" style="max-height: 75px; margin-right: 10px;"><?php endif; ?>
                <div>
                    <h4 class="fw-bold m-0"><?php echo $p->nome_portale; ?></h4>
                    <small class="opacity-75"><?php echo $p->sottotitolo_portale; ?></small>
                </div>
            </div>
            <div class="text-end">
                <span class="badge bg-white fw-bold text-uppercase fs-6 px-3 py-2 shadow-sm" style="color: <?php echo $d->col_ric_testo; ?> !important;"><?php echo $senza ? 'RICEVUTA DI PRESENZA' : 'RICEVUTA DI PRENOTAZIONE'; ?></span>
            </div>
        </div>

        <div class="ticket-body">
            <div class="row mb-4 pb-3 border-bottom align-items-center">
                <div class="col-sm-8">
                    <?php if ($senza): ?>
                    <span class="text-muted small d-block fw-bold text-uppercase">PRESENZA:</span>
                    <span class="badge bg-success fs-6 mt-1"><i class="fa fa-check"></i> Presenza registrata</span>
                    <?php else: ?>
                    <span class="text-muted small d-block fw-bold text-uppercase">CODICE UNIVOCO:</span>
                    <div class="code-badge mt-1"><?php echo $p->codice_prenotazione; ?></div>
                    <div class="mt-3">
                        <span class="text-muted small d-block fw-bold text-uppercase">STATO PRENOTAZIONE:</span>
                        <?php
                            $st = $p->stato;
                            if ($st === 'da_approvare') echo '<span class="badge bg-info text-dark fs-6 mt-1"><i class="fa fa-hourglass-half"></i> In attesa</span>';
                            elseif ($st === 'in_attesa') echo '<span class="badge bg-warning text-dark fs-6 mt-1"><i class="fa fa-clock"></i> Lista d\'Attesa</span>';
                            elseif ($st === 'rifiutata') echo '<span class="badge bg-secondary fs-6 mt-1"><i class="fa fa-times"></i> Non Accolta</span>';
                            else echo '<span class="badge bg-success fs-6 mt-1"><i class="fa fa-check"></i> Confermata</span>';
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!$senza): ?>
                <div class="col-sm-4 text-center mt-3 mt-sm-0">
                    <!-- IL QR CODE -->
                    <?php echo $d->grezzo('qr'); ?>
                    <div class="small text-muted mt-1 fw-bold">Scansiona all'ingresso</div>
                </div>
                <?php endif; ?>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-6 border-end">
                    <h6 class="fw-bold border-bottom pb-2 mb-3" style="color: <?php echo $d->col_ric_testo; ?>;"><i class="fa fa-calendar-alt me-1"></i> Dettaglio Evento</h6>
                    <p class="mb-2"><strong>Iniziativa:</strong> <br><span class="text-secondary"><?php echo $p->pagina_titolo; ?></span></p>
                    <p class="mb-2"><strong>Attività:</strong> <br><span class="text-dark fw-bold"><?php echo $p->evento_titolo; ?></span></p>
                    <?php if (!empty($p->nome_turno)): ?><p class="mb-2"><strong>Turno / Gruppo:</strong> <br><span class="fw-bold text-dark">🏷️ <?php echo $p->nome_turno; ?></span></p><?php endif; ?>
                    <?php if (!empty($p->data_turno)): ?><p class="mb-2"><strong>Data:</strong> <br><span class="fw-bold" style="color: <?php echo $d->col_ric_testo; ?>;">📅 <?php echo $p->data_it; ?></span></p><?php endif; ?>
                    <?php if ($p->orario !== ''): ?><p class="mb-2"><strong>Orario:</strong> <br><span class="text-dark">🕒 <?php echo $p->orario; ?></span></p><?php endif; ?>
                    <p class="mb-0"><strong>Luogo:</strong> <br><span class="text-secondary">📍 <?php echo $p->evento_luogo; ?></span></p>
                </div>

                <div class="col-md-6">
                    <h6 class="fw-bold border-bottom pb-2 mb-3" style="color: <?php echo $d->col_ric_testo; ?>;"><i class="fa fa-user me-1"></i> Partecipante</h6>
                    <p class="mb-2"><strong>Nominativo:</strong> <br><span class="text-dark fw-bold"><?php echo $p->nome . ' ' . $p->cognome; ?></span></p>
                    <p class="mb-2"><strong>Email:</strong> <br><span class="text-secondary"><?php echo $p->email; ?></span></p>
                    <p class="mb-2"><strong>Matricola:</strong> <br><span class="text-secondary"><?php echo $p->matricola_mostrata; ?></span></p>
                    <p class="mb-0"><strong>Posti Riservati:</strong> <br><span class="badge bg-dark fs-6"><?php echo $p->num_posti; ?> Posto/i</span></p>
                </div>
            </div>

            <?php if (!empty($custom)): ?>
                <div class="bg-light p-3 rounded border mb-4">
                    <h6 class="fw-bold border-bottom pb-2 mb-3" style="color: <?php echo $d->col_ric_testo; ?>;"><i class="fa fa-list-check me-1"></i> Informazioni Aggiuntive</h6>
                    <div class="row g-3">
                        <?php foreach ($custom as $voce): ?>
                            <div class="col-md-6">
                                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:0.75rem;"><?php echo $voce->chiave; ?>:</small>
                                <span class="fw-semibold text-dark"><?php echo $voce->allegato ? '📎 File Allegato' : $voce->valore; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<script src="assets/js/azioni.js"></script>
</body>
</html>
