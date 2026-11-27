<?php
// Tessera «approvazioni_pubblicazioni» del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info…).
// Solo per chi approva le richieste di pubblicazione (ApprovazionePubblicazioni::puoApprovare: account collegato a una persona della lista «Chi approva», per persona_id
// e mai per email): mostra quante richieste aspettano una decisione e porta alla pagina approva_pubblicazioni.php. Per tutti gli altri non stampa niente.
$app_ap = \App\Core\App::per($conn);
$appr_ap = $app_ap->get(\App\Pubblicazioni\ApprovazionePubblicazioni::class);
if (!$appr_ap->puoApprovare($user_info ?? null)) {
    return;
}
$attesa_ap = 0;
try {
    $attesa_ap = $appr_ap->contaDaApprovare();
} catch (\Throwable $e) {
    error_log('Tessera approvazioni pubblicazioni: ' . $e->getMessage());
}
area_tessera('fa-stamp', '#be123c', 'Richieste di pubblicazione da approvare',
    $attesa_ap > 0
        ? 'Un docente ha chiesto di pubblicare un evento: guarda il riepilogo e approva o respingi. Basta che lo faccia una sola persona.'
        : 'Non c\'è niente da approvare. Quando arriva una richiesta nuova ricevi una email.',
    'approva_pubblicazioni.php', $attesa_ap, $attesa_ap === 1 ? 'richiesta da approvare' : 'richieste da approvare',
    [['Apri l\'elenco', 'approva_pubblicazioni.php', false, $attesa_ap > 0]]);
