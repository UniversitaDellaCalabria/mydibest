<?php
// Tessera «richieste_pubblicazione» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info…).
// Per docenti e personale: le richieste di pubblicazione inviate (titolo, data dell'evento, stato in parole; «Pubblicata» è un link se gli operatori hanno scritto
// l'indirizzo della pagina pubblicata; «In attesa di approvazione», «Approvata» o «Respinta» con il motivo scritto da chi l'ha respinta) e il pulsante «Nuova richiesta».
// Una richiesta già inviata non si modifica: per correzioni si scrive al primo indirizzo di notifica.
// Compare solo a chi può inviare la richiesta (ServizioPubblicazioni::puoInviare: «Chi può inviare» delle impostazioni) o ne ha già inviata almeno una;
// «Nuova richiesta» solo a chi può inviarla e se la procedura è aperta (con la procedura chiusa la tessera lo dice).
// Disegnata come le altre tessere (stile ui-tessera) ma con il suo corpo, perché area_tessera() scrive i testi senza collegamenti.
$app_rp = \App\Core\App::per($conn);
$mie_rp = $app_rp->get(\App\Pubblicazioni\PubblicazioniRepository::class)->delUtente((int)($_SESSION['utente_id'] ?? 0));
$imp_rp = $app_rp->get(\App\Pubblicazioni\ImpostazioniPubblicazioni::class);
$puo_rp = $app_rp->get(\App\Pubblicazioni\ServizioPubblicazioni::class)->puoInviare($user_info ?? null);
if (!$puo_rp && !$mie_rp) {
    return;
}
$indirizzi_rp = $imp_rp->indirizzi();
$h_rp = static fn ($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$corto_rp = static fn (string $t, int $n): string => mb_strlen($t) > $n ? rtrim(mb_substr($t, 0, $n)) . '…' : $t;

echo '<div class="col-md-6 col-xl-4"><div class="ui-tessera" style="--tc:#be123c;">';
echo '<span class="ui-tessera-icona" aria-hidden="true"><i class="fa fa-bullhorn"></i></span><div class="ui-tessera-corpo">';
echo '<a href="richiesta_pubblicazione.php" class="ui-tessera-titolo stretched-link">Le mie richieste di pubblicazione</a>';
echo '<div class="ui-tessera-testo ui-tessera-extra">';
if (!$mie_rp) {
    echo 'Non hai ancora inviato richieste. Da qui chiedi la pubblicazione di un evento sul sito e sui social del DiBEST.';
    if (!$imp_rp->aperta()) {
        echo '<div class="small text-secondary mt-2">' . $h_rp($imp_rp->messaggioChiusa()) . '</div>';
    }
} else {
    foreach (array_slice($mie_rp, 0, 5) as $r_rp) {
        $stato_rp = \App\Pubblicazioni\Costanti::STATI[$r_rp['stato']] ?? (string)$r_rp['stato'];
        $url_rp = trim((string)($r_rp['d_url_pubblicazione'] ?? ''));
        $parola_rp = ($r_rp['stato'] === 'pubblicata' && \App\Pubblicazioni\RegolePubblicazione::url($url_rp))
            ? '<a href="' . $h_rp($url_rp) . '" target="_blank" rel="noopener noreferrer" class="fw-bold">' . $h_rp($stato_rp) . ' <i class="fa fa-arrow-up-right-from-square" aria-hidden="true"></i></a>'
            : '<strong>' . $h_rp($stato_rp) . '</strong>';
        echo '<div class="mb-1">• ' . $h_rp($corto_rp(trim((string)$r_rp['titolo']) !== '' ? (string)$r_rp['titolo'] : 'Senza titolo', 50))
            . ' — evento del ' . $h_rp(\App\Pubblicazioni\RiepilogoRichiesta::data((string)$r_rp['data_evento'])) . ' — ' . $parola_rp;
        // respinta da chi approva: il motivo (chi la segna «Respinta» dalla scheda del pannello non ne scrive uno)
        $motivo_rp = trim((string)($r_rp['respinta_motivo'] ?? ''));
        if ($r_rp['stato'] === 'respinta' && $motivo_rp !== '') {
            echo '<div class="small text-secondary ps-3">Motivo: ' . nl2br($h_rp($motivo_rp), false) . '</div>';
        }
        echo '</div>';
    }
    if (count($mie_rp) > 5) {
        echo '<div class="mb-1">… e altre ' . (count($mie_rp) - 5) . '</div>';
    }
    echo '<div class="small text-secondary mt-2">Una richiesta già inviata non si modifica'
        . ($indirizzi_rp ? ': per correzioni scrivi a <a href="mailto:' . $h_rp($indirizzi_rp[0]) . '">' . $h_rp($indirizzi_rp[0]) . '</a>' : '') . '.</div>';
}
echo '</div>';
if ($puo_rp && $imp_rp->aperta()) {
    echo '<div class="ui-tessera-extra d-flex flex-wrap gap-2 mt-2"><a href="richiesta_pubblicazione.php" class="btn btn-sm fw-bold btn-outline-secondary">Nuova richiesta</a></div>';
} elseif ($mie_rp && !$imp_rp->aperta()) {
    echo '<div class="ui-tessera-extra small text-secondary mt-2">' . $h_rp($imp_rp->messaggioChiusa()) . '</div>';
}
echo '</div></div></div>';
