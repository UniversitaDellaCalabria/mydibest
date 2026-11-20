<?php
// impostazioni_pubblicazioni_email.php - La parte «Email» delle impostazioni della Richiesta di pubblicazione: gli indirizzi che ricevono le richieste
// e la ricevuta a chi invia. È la sola parte che vede chi ha solo «Amministrazione · Richieste di pubblicazione» (admin/pubblicazioni.php?vista=impostazioni);
// la include anche la scheda «Impostazioni» dell'Amministrazione (admin/amministrazione_impostazioni.php), dentro il suo modulo con un solo «Salva».
// Solo i campi: il modulo (<form>, codice di sicurezza, pulsante) lo mette chi include. Variabili della pagina: $h (escape), $imp_val (i valori da mostrare,
// già salvati o appena scritti), $imp_err (errori campo per campo, ImpostazioniPubblicazioni::valida).
$err_email = static fn (string $k): string => isset($imp_err[$k]) ? '<div class="text-danger small fw-bold mt-1" role="alert" id="err_' . $h($k) . '">' . $h($imp_err[$k]) . '</div>' : '';
$ind_email = is_array($imp_val['indirizzi']) ? implode(', ', $imp_val['indirizzi']) : (string)$imp_val['indirizzi'];
?>
<div class="mb-3">
    <label class="form-label fw-bold" for="pbInd">Indirizzo (o indirizzi) che ricevono le richieste</label>
    <p class="small text-secondary mb-2">Quando arriva una richiesta parte un'email a questi indirizzi, con tutto il testo e il collegamento alla scheda. Per più indirizzi separali con una virgola. Se ne indichi uno sbagliato non viene salvato niente.</p>
    <input type="text" class="form-control mb-1<?php echo isset($imp_err['indirizzi']) ? ' is-invalid' : ''; ?>" id="pbInd" name="indirizzi" maxlength="500" value="<?php echo $h($ind_email); ?>" required<?php echo isset($imp_err['indirizzi']) ? ' aria-invalid="true" aria-describedby="err_indirizzi"' : ''; ?>>
    <?php echo $err_email('indirizzi'); ?>
    <div class="small text-secondary">Esempio: <code>comunicazione.dibest@unical.it, altra.persona@unical.it</code>.</div>
</div>
<div class="form-check">
    <input class="form-check-input" type="checkbox" id="pbRicevuta" name="ricevuta_al_richiedente" value="1"<?php echo $imp_val['ricevuta_al_richiedente'] ? ' checked' : ''; ?> aria-describedby="pbRicevutaAiuto">
    <label class="form-check-label fw-bold" for="pbRicevuta">Manda la ricevuta a chi invia la richiesta</label>
    <div class="form-text" id="pbRicevutaAiuto">Subito dopo l'invio arriva un'email con la copia di quello che ha scritto. Se la spegni parte solo l'email agli indirizzi qui sopra.</div>
</div>
