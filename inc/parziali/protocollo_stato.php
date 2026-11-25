<?php
// protocollo_stato.php - Lo stato dell'invio a Titulus di un documento, per l'ufficio: esito incerto, in approvazione, numero dato a un file di prima,
// invio in corso. Lo usano tutti i punti dove c'è «Protocolla in Titulus»: convenzioni FSL e di tesi, pratiche, attestati, lettere di tutorato.
// Si include con require_once e si chiama dentro la pagina, FUORI da un altro <form> (ha i suoi moduli).
//
//   protocollo_stato_riquadro($stato, 'prot_incerto_chiudi', 'prot_respinto', '123', ['dove' => 'domanda']);
//   [$messaggio, $tipo] = protocollo_stato_azione($conn, ProtocollaPraticaInTitulus::RIF, $id, $respinto);   // nel gestore del POST
//
// $stato è ciò che dà InvioProtocollo::stato(): ['stato' => libero|in_invio|incerto|in_approvazione|precedente|protocollato, 'numero', 'il'].

if (!function_exists('protocollo_stato_riquadro')) {
    /**
     * Il riquadro per l'ufficio (nulla se l'invio è libero o già protocollato). I pulsanti sono due moduli POST con CSRF e conferma:
     * «Non c'è in Titulus: riprova» (chiude l'esito incerto) e «Respinto» (chiude l'«in approvazione»).
     *
     * @param array{stato: string, numero: string, il: string} $stato
     * @param array<string, string> $nascosti altri campi del modulo (es. l'id e la fase), nome => valore
     */
    function protocollo_stato_riquadro(array $stato, string $nomeChiudi, string $nomeRespinto, string $valore, array $nascosti = []): void
    {
        $h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $campi = '';
        foreach ($nascosti as $nome => $v) {
            $campi .= '<input type="hidden" name="' . $h($nome) . '" value="' . $h($v) . '">';
        }
        $st = $stato['stato'] ?? 'libero';
        if ($st === 'incerto') { ?>
            <div class="alert alert-warning py-2 px-3 small mb-2" role="alert" data-protocollo-stato="incerto"><i class="fa fa-circle-question me-1" aria-hidden="true"></i><strong>Esito incerto.</strong> <?php echo $h(\App\Protocollo\InvioProtocollo::testoIncerto($stato['il'])); ?>
                <form method="POST" class="mt-1 mb-0" data-conferma="Hai cercato il documento in Titulus e non c'è? Allora si potrà inviare di nuovo. Se invece c'è, NON confermare: scrivi qui il suo numero a mano."><?php csrf_field(); echo $campi; ?>
                    <button type="submit" name="<?php echo $h($nomeChiudi); ?>" value="<?php echo $h($valore); ?>" class="btn btn-sm btn-outline-dark">Non c'è in Titulus: riprova</button></form></div>
        <?php } elseif ($st === 'in_approvazione') { ?>
            <div class="alert alert-warning py-2 px-3 small mb-2" role="alert" data-protocollo-stato="in_approvazione"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i><strong>In approvazione in Titulus.</strong> <?php echo $h(\App\Protocollo\InvioProtocollo::testoInApprovazione($stato['il'])); ?>
                <form method="POST" class="mt-1 mb-0" data-conferma="In Titulus il documento è stato respinto? Allora potrai rimandarlo. Se invece è ancora in approvazione o ha già il numero, NON confermare."><?php csrf_field(); echo $campi; ?>
                    <button type="submit" name="<?php echo $h($nomeRespinto); ?>" value="<?php echo $h($valore); ?>" class="btn btn-sm btn-outline-dark">Respinto</button></form></div>
        <?php } elseif ($st === 'precedente') { ?>
            <div class="alert alert-warning py-2 px-3 small mb-2" role="alert" data-protocollo-stato="precedente"><i class="fa fa-clone me-1" aria-hidden="true"></i><strong>Numero dato a un file di prima.</strong> <?php echo $h(\App\Protocollo\InvioProtocollo::testoPrecedente($stato['numero'], $stato['il'])); ?></div>
        <?php } elseif ($st === 'in_invio') { ?>
            <div class="alert alert-info py-2 px-3 small mb-2" role="status" data-protocollo-stato="in_invio"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>Un invio a Titulus di questo documento è in corso: aspetta qualche secondo e ricarica la pagina.</div>
        <?php }
    }
}

if (!function_exists('protocollo_stato_azione')) {
    /**
     * «Non c'è in Titulus: riprova» o «Respinto» premuto dall'ufficio: chiude l'esito solo se lo stato è quello giusto (lo decide InvioProtocollo,
     * con la prenotazione del documento in mano), scrive la riga nel registro delle operazioni e dice che cosa è successo.
     * Il tipo di riferimento ($rifTipo) lo sceglie il codice della pagina, mai il browser.
     *
     * @return array{0: string, 1: string} [messaggio, tipo del messaggio: success|danger]
     */
    function protocollo_stato_azione($conn, string $rifTipo, int $id, bool $respinto, string $oggetto = 'Documento'): array
    {
        $chi = (string) ($_SESSION['utente_email'] ?? 'ufficio');
        $uid = (int) ($_SESSION['utente_id'] ?? 0) ?: null;
        $invio = \App\Core\App::per($conn)->get(\App\Protocollo\InvioProtocollo::class);
        $err = $respinto
            ? $invio->respinto($rifTipo, $id, 'Respinto in Titulus, dichiarato da ' . $chi, $uid)
            : $invio->chiudiIncerto($rifTipo, $id, 'Cercato in Titulus da ' . $chi . ': il documento non c\'è', $uid);
        if ($err !== null) {
            return [$err, 'danger'];
        }
        registra_log_audit($conn, $respinto ? $oggetto . ': respinto in Titulus, si può rimandare' : $oggetto . ': esito incerto verificato in Titulus, non protocollato', ['Riferimento' => $rifTipo, 'ID' => $id]);

        return [$respinto
            ? 'Registrato: in Titulus il documento è stato respinto. Ora puoi premere di nuovo «Protocolla in Titulus».'
            : 'Registrato: hai verificato che in Titulus il documento non c\'è. Ora puoi premere di nuovo «Protocolla in Titulus».', 'success'];
    }
}

// ---- I file di un documento protocollato (originale, documento protocollato, file ripreso da Titulus): riquadro e azione per l'ufficio -----------------------
//
//   protocollo_file_riquadro($conn, FileProtocollato::RIF_DOMANDA, $id, fn($voce) => 'allegato_pratica.php?p=..&d=domanda&v=' . $voce, 'riprova_file_prot', 'domanda', ['pratica_id' => '12']);
//   [$messaggio, $tipo] = protocollo_file_azione($conn, FileProtocollato::RIF_DOMANDA, $id, 'Pratica');      // nel gestore del POST
//
// Si chiama dentro la pagina, FUORI da un altro <form>. Le voci per l'ufficio sono: originale, protocollato, titulus (il file ripreso) e apri («Apri in Titulus», si
// risolve solo al clic). La pagina di chi legge usa soltanto «originale» e «protocollato» e non chiama mai Titulus.

if (!function_exists('protocollo_file_riquadro')) {
    /**
     * @param callable(string): string $url l'indirizzo di ogni voce (originale|protocollato|titulus|apri), già con le sue regole di accesso
     * @param array<string, string> $nascosti altri campi del modulo «Riprova da Titulus», nome => valore
     */
    function protocollo_file_riquadro($conn, string $rifTipo, int $id, callable $url, string $nomeRiprova, string $valore, array $nascosti = []): void
    {
        $h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $svc = \App\Core\App::per($conn)->get(\App\Protocollo\FileProtocollato::class);
        $v = $svc->voci($rifTipo, $id);
        $t = $v['titulus'];
        $origini = ['mano' => 'caricato dall\'ufficio', 'copia' => 'copia con la segnatura del portale, per consultazione', 'titulus' => 'ripreso da Titulus, senza stampigliatura', 'originale' => 'uguale all\'originale (la copia con la segnatura non si è potuta fare)'];
        $campi = '';
        foreach ($nascosti as $nome => $x) {
            $campi .= '<input type="hidden" name="' . $h($nome) . '" value="' . $h($x) . '">';
        }
        $invio_ok = (int) \App\Core\App::per($conn)->get(\App\Core\Database::class)->valore("SELECT COUNT(*) FROM titulus_invii WHERE rif_tipo = ? AND rif_id = ? AND esito = 'ok'", [$rifTipo, $id]) > 0;
        ?>
        <div class="border rounded p-2 mb-2 small" data-file-protocollato="<?php echo $h($rifTipo); ?>">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                <?php if ($v['originale']['esiste']): ?><a class="btn btn-sm btn-outline-danger py-0 fw-bold" href="<?php echo $h($url('originale')); ?>" target="_blank" rel="noopener"><i class="fa fa-file-signature me-1" aria-hidden="true"></i>Originale</a><?php endif; ?>
                <?php if ($v['protocollato']['esiste']): ?><a class="btn btn-sm btn-outline-danger py-0 fw-bold" href="<?php echo $h($url('protocollato')); ?>" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Documento protocollato</a>
                    <span class="text-secondary"><?php echo $h($origini[$v['protocollato']['origine']] ?? 'origine non registrata'); ?></span><?php endif; ?>
                <?php if (!$v['originale']['esiste'] && !$v['protocollato']['esiste']): ?><span class="text-secondary">Nessun file da scaricare.</span><?php endif; ?>
            </div>
            <div class="text-secondary mb-1"><i class="fa fa-stamp me-1" aria-hidden="true"></i>File in Titulus:
                <?php if ($t === null): ?>non ripreso (protocollo scritto a mano, o documento protocollato prima di questa funzione: «Riprova» lo cerca).
                <?php elseif ($t['stato'] === 'ok'): ?><a href="<?php echo $h($url('titulus')); ?>" target="_blank" rel="noopener">scarica il file di Titulus</a> · ripreso il <?php echo $h(date('d/m/Y H:i', (int) strtotime($t['scaricato_il']))); ?> · SHA-256 <span class="font-monospace"><?php echo $h(substr($t['impronta'], 0, 16)); ?>…</span><?php echo $t['identico'] === true ? ' · <strong class="text-success">identico all\'originale</strong>' : ($t['identico'] === false ? ' · <strong class="text-warning">diverso dall\'originale del portale</strong>' : ''); ?>
                <?php elseif ($t['stato'] === 'attesa'): ?>da riprendere: il portale lo chiede a Titulus una volta al giorno (non prima del prossimo giro del cron).
                <?php else: ?><strong class="text-warning">non arrivato</strong> (<?php echo (int) $t['tentativi']; ?> tentativi su <?php echo \App\Protocollo\FileProtocollato::MASSIMO_TENTATIVI; ?>)<?php echo $t['errore'] !== '' ? ': ' . $h($t['errore']) : ''; ?>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?php if ($invio_ok): ?><a class="btn btn-sm btn-outline-secondary py-0" href="<?php echo $h($url('apri')); ?>" target="_blank" rel="noopener"><i class="fa fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>Apri in Titulus</a><?php endif; ?>
                <form method="POST" class="m-0"><?php csrf_field(); echo $campi; ?>
                    <button type="submit" name="<?php echo $h($nomeRiprova); ?>" value="<?php echo $h($valore); ?>" class="btn btn-sm btn-outline-secondary py-0"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Riprova da Titulus</button></form>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('protocollo_file_azione')) {
    /**
     * «Riprova da Titulus» premuto dall'ufficio: legge il file da Titulus (solo lettura) e dice com'è andata. Il tipo di riferimento lo sceglie il codice della pagina.
     *
     * @return array{0: string, 1: string} [messaggio, tipo del messaggio: success|warning]
     */
    function protocollo_file_azione($conn, string $rifTipo, int $id, string $oggetto = 'Documento'): array
    {
        $r = \App\Core\App::per($conn)->get(\App\Protocollo\FileProtocollato::class)->scarica($rifTipo, $id);
        if ($r['ok']) {
            registra_log_audit($conn, $oggetto . ': file protocollato ripreso da Titulus', ['Riferimento' => $rifTipo, 'ID' => $id]);
        }

        return [$r['ok'] ? 'File ripreso da Titulus e conservato.' : 'Da Titulus il file non arriva: ' . $r['messaggio'], $r['ok'] ? 'success' : 'warning'];
    }
}
