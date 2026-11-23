<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // «Autenticazione richiesta»: il login SSO è stato tentato e non è riuscito. Documento intero, senza la testata del pannello. Dati: css_bootstrap, css_icone, link_accedi ?>
<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Accesso Riservato</title><link href="<?php echo $d->css_bootstrap; ?>" rel="stylesheet"><link rel="stylesheet" href="<?php echo $d->css_icone; ?>"></head>
<body class="bg-light d-flex align-items-center justify-content-center ui-h-100vh">
    <div class="card shadow-sm p-4 text-center ui-maxw-500" style="border-top: 4px solid var(--dibest-rosso-scuro);">
        <h4 class="fw-bold mb-3 text-danger"><i class="fa fa-lock"></i> Autenticazione Richiesta</h4>
        <p class="text-secondary small mb-0">L'accesso non è andato a buon fine. Riprova; se il problema continua, chiudi il browser e riaprilo.</p>
        <div class="mt-3"><a href="<?php echo $d->link_accedi; ?>" class="btn btn-danger px-4 fw-bold ui-bg-rosso-scuro">Accedi con SSO Unical</a></div>
    </div>
</body></html>
