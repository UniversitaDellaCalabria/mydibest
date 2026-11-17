<?php
// Tessera «miei_form» (MyForms) del cruscotto dell'area personale: la include area_personale.php, quindi usa le variabili della pagina ($conn, $user_info…).
// I form compilati dalla persona (riconosciuti dall'impronta, anche quelli anonimi) e quanti ne sono aperti per lei. Compare solo se c'è qualcosa da dire.
$sv_mf = \App\Core\App::per($conn)->get(\App\Forms\ServizioForms::class);
$mie_mf = $sv_mf->risposteDi($user_info ?? null);
$aperti_mf = $sv_mf->apertiPer($user_info ?? null);
if (!$mie_mf && !$aperti_mf) {
    return;
}
$da_compilare_mf = count(array_filter($aperti_mf, static fn (array $x): bool => $x['risposta'] === null));
$righe_mf = [];
$righe_mf[] = $aperti_mf
    ? count($aperti_mf) . (count($aperti_mf) === 1 ? ' modulo aperto per te' : ' moduli aperti per te')
    : 'Nessun modulo aperto per te in questo momento.';
$corti_mf = static fn (string $t, int $n): string => mb_strlen($t) > $n ? rtrim(mb_substr($t, 0, $n)) . '…' : $t;
$bottoni_mf = [];
foreach (array_slice($mie_mf, 0, 3) as $m_mf) {
    $titolo_mf = trim((string) $m_mf['form']['titolo']) !== '' ? (string) $m_mf['form']['titolo'] : 'Modulo senza titolo';
    $righe_mf[] = '• ' . $corti_mf($titolo_mf, 50) . ' — ' . date('d/m/Y', strtotime((string) $m_mf['risposta']['inviata_il'])) . ' — ricevuta ' . $m_mf['risposta']['codice_ricevuta'];
    if ($m_mf['modificabile'] && count($bottoni_mf) < 2) {
        $bottoni_mf[] = ['Modifica «' . $corti_mf($titolo_mf, 22) . '»', 'form.php?c=' . rawurlencode((string) $m_mf['form']['codice'])];
    }
}
if (count($mie_mf) > 3) {
    $righe_mf[] = '… e altri ' . (count($mie_mf) - 3);
}
$bottoni_mf[] = ['Moduli da compilare', 'forms.php'];
area_tessera('fa-clipboard-list', '#c2410c', 'I miei form', implode("\n", $righe_mf), 'forms.php', $da_compilare_mf, 'da compilare', $bottoni_mf);
