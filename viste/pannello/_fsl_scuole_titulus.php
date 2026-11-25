<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } // Dati: stato (incerto | in_approvazione | precedente | in_invio | altro), numero, il, id, fase, campoCsrf (HTML)
// L'avviso dello stato dell'invio a Titulus di un documento della convenzione: gli stessi testi e gli stessi pulsanti del riquadro di tutti i punti con «Protocolla in Titulus»
// (inc/parziali/protocollo_stato.php), ma dentro la scheda della scuola. Il valore dei pulsanti è «compilazione:fase»: il tipo di riferimento lo sceglie il codice, mai il browser.
$st = (string) $d->grezzo('stato');
$valore = (int) $d->grezzo('id') . ':' . (string) $d->grezzo('fase');
$il = (string) $d->grezzo('il');
$csrf = $d->grezzo('campoCsrf');
?>
<?php if ($st === 'incerto'): ?>
    <div class="alert alert-warning py-2 px-3 small mb-2" role="alert" data-protocollo-stato="incerto"><i class="fa fa-circle-question me-1" aria-hidden="true"></i><strong>Esito incerto.</strong> <?php echo \App\Http\DatiVista::escapa(\App\Protocollo\InvioProtocollo::testoIncerto($il)); ?>
        <form method="POST" class="mt-1 mb-0" data-conferma="Hai cercato il documento in Titulus e non c'è? Allora si potrà inviare di nuovo. Se invece c'è, NON confermare: scrivi qui il suo numero a mano." data-conferma-livello="pericolo"><?php echo $csrf; ?>
            <button type="submit" name="cv_incerto_chiudi" value="<?php echo \App\Http\DatiVista::escapa($valore); ?>" class="btn btn-sm btn-outline-dark">Non c'è in Titulus: riprova</button></form></div>
<?php elseif ($st === 'in_approvazione'): ?>
    <div class="alert alert-warning py-2 px-3 small mb-2" role="alert" data-protocollo-stato="in_approvazione"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i><strong>In approvazione in Titulus.</strong> <?php echo \App\Http\DatiVista::escapa(\App\Protocollo\InvioProtocollo::testoInApprovazione($il)); ?>
        <form method="POST" class="mt-1 mb-0" data-conferma="In Titulus il documento è stato respinto? Allora potrai rimandarlo. Se invece è ancora in approvazione o ha già il numero, NON confermare." data-conferma-livello="pericolo"><?php echo $csrf; ?>
            <button type="submit" name="cv_respinto" value="<?php echo \App\Http\DatiVista::escapa($valore); ?>" class="btn btn-sm btn-outline-dark">Respinto</button></form></div>
<?php elseif ($st === 'precedente'): ?>
    <div class="alert alert-warning py-2 px-3 small mb-2" role="alert" data-protocollo-stato="precedente"><i class="fa fa-clone me-1" aria-hidden="true"></i><strong>Numero dato a un file di prima.</strong> <?php echo \App\Http\DatiVista::escapa(\App\Protocollo\InvioProtocollo::testoPrecedente((string) $d->grezzo('numero'), $il)); ?></div>
<?php elseif ($st === 'in_invio'): ?>
    <div class="alert alert-info py-2 px-3 small mb-2" role="status" data-protocollo-stato="in_invio"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>Un invio a Titulus di questo documento è in corso: aspetta qualche secondo e ricarica la pagina.</div>
<?php endif; ?>
