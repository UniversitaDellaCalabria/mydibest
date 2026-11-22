<?php
// Prove del modulo Amministrazione (passo AM1): chi entra (amministratore, solo «Amministrazione · Richieste di pubblicazione», tutto il modulo, nessuno),
// la regola unica di ServizioPubblicazioni::puoGestire, le schede e le abilitazioni nuove di Utenti. Chiamato da esegui.php (PROVE_SOLO=amministrazione).
sezione("Amministrazione: chi entra nel modulo e in quali schede");

use App\Amministrazione\PermessiAmministrazione;
use App\Pubblicazioni\ServizioPubblicazioni;

$q("DELETE FROM abilitazioni_ambito WHERE utente_id BETWEEN 9701 AND 9705");
$q("DELETE FROM utenti WHERE id BETWEEN 9701 AND 9705");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES
    (9701, 'AMM97001XXXXXXXX', 'Ada', 'Amministratrice', 'ada.amm97@unical.it', 1),
    (9702, 'AMM97002XXXXXXXX', 'Rita', 'Richieste', 'rita.amm97@unical.it', 4),
    (9703, 'AMM97003XXXXXXXX', 'Tina', 'Tuttoilmodulo', 'tina.amm97@unical.it', 4),
    (9704, 'AMM97004XXXXXXXX', 'Nino', 'Nessuno', 'nino.amm97@unical.it', 4),
    (9705, 'AMM97005XXXXXXXX', 'Mara', 'Soloforms', 'mara.amm97@unical.it', 4)");
$riga_u = fn(int $id): array => $conn->query("SELECT * FROM utenti WHERE id = $id")->fetch_assoc();

prova(isset(TIPI_AMBITO['modulo_amministrazione']) && isset(TIPI_AMBITO['modulo_pubblicazioni']) && str_contains(TIPI_AMBITO['modulo_pubblicazioni'], 'Richieste di pubblicazione'),
    "abilitazioni: «Modulo Amministrazione» e «Amministrazione · Richieste di pubblicazione» tra i perimetri");
prova(assegna_ambito($conn, 9702, 'modulo_pubblicazioni') && assegna_ambito($conn, 9703, 'modulo_amministrazione') && assegna_ambito($conn, 9705, 'modulo_forms'),
    "abilitazioni: si assegnano al modulo intero (pagina_id 0)");

$pa = \App\Core\App::per($conn)->get(PermessiAmministrazione::class);
$sp = \App\Core\App::per($conn)->get(ServizioPubblicazioni::class);
$atteso = ['admin' => [9701, true, true, true], 'solo richieste' => [9702, false, true, true], 'tutto il modulo' => [9703, true, true, true], 'nessuno' => [9704, false, false, false], 'solo MyForms' => [9705, false, false, false]];
foreach ($atteso as $chi => [$id_u, $tutto, $rich, $entra]) {
    $u = $riga_u($id_u);
    prova($pa->puoTutto($u) === $tutto && $pa->puoPubblicazioni($u) === $rich && $pa->puoEntrare($u) === $entra, "permessi dell'Amministrazione: $chi");
    prova($sp->puoGestire($u) === $pa->puoPubblicazioni($u), "ServizioPubblicazioni::puoGestire = puoPubblicazioni: $chi");
}
prova(!$pa->puoEntrare(null) && !$sp->puoGestire(null), "senza persona nessuno entra");
prova($pa->puoScheda($riga_u(9702), 'home') && !$pa->puoScheda($riga_u(9702), 'modulistica') && !$pa->puoScheda($riga_u(9702), 'impostazioni') && !$pa->puoScheda($riga_u(9702), 'scheda_finta'),
    "solo richieste: apre «Cosa devo fare» ma non le altre schede");
prova($pa->puoScheda($riga_u(9703), 'modulistica') && $pa->puoScheda($riga_u(9701), 'impostazioni') && !$pa->puoScheda($riga_u(9704), 'home'), "tutto il modulo e amministratore: tutte le schede; nessuno: neppure la home");
prova(ha_modulo($conn, 9703, 'amministrazione') && !ha_modulo($conn, 9702, 'amministrazione') && ha_modulo($conn, 9702, 'pubblicazioni'), "ha_modulo(): amministrazione e pubblicazioni sono perimetri distinti");

// Riepilogo di Utenti: la parte «Richieste di pubblicazione» si dice con il suo nome
$p_ric = \App\Auth\Abilitazioni\Perimetri::daModulo(['moduli' => ['pubblicazioni']]);
$p_tut = \App\Auth\Abilitazioni\Perimetri::daModulo(['moduli' => ['amministrazione']]);
prova($p_ric['moduli'] === ['pubblicazioni'] && $p_tut['moduli'] === ['amministrazione']
    && \App\Auth\Abilitazioni\Perimetri::testo($p_ric, [], MODULI_PORTALE) === 'Amministrazione · Richieste di pubblicazione'
    && \App\Auth\Abilitazioni\Perimetri::testo($p_tut, [], MODULI_PORTALE) === 'modulo Amministrazione', "Utenti: i due moduli si scelgono e si raccontano nel riepilogo");

// Pagine: i file ci sono e i rimandi stanno nell'intestazione (le prove HTTP sono in oop/pagine_amministrazione.php)
$ah_am = (string) file_get_contents(__DIR__ . '/../../../admin/admin_header.php');
prova(is_file(__DIR__ . '/../../../admin/amministrazione.php') && is_file(__DIR__ . '/../../../admin/amministrazione_home.php')
    && str_contains($ah_am, "in_array(\$_GET['sezione'] ?? '', ['amministrazione', 'pubblicazioni'], true)") && str_contains($ah_am, "header('Location: amministrazione.php')")
    && str_contains((string) file_get_contents(__DIR__ . '/../../../src/Portale/Pannello/MenuModuli.php'), "\$m['amministrazione'] = true;") // dal passo S9 i moduli li costruisce MenuModuli
    && !str_contains($ah_am, "\$moduli_utente['pubblicazioni']"),
    "pannello: ?sezione=amministrazione e il vecchio ?sezione=pubblicazioni portano a amministrazione.php; un solo modulo per le card");

$q("DELETE FROM abilitazioni_ambito WHERE utente_id BETWEEN 9701 AND 9705");
$q("DELETE FROM utenti WHERE id BETWEEN 9701 AND 9705");

// ---------------------------------------------------------------------------
// Guide (AM6): chi può inviare, i limiti, i campi e le colonne si leggono da ImpostazioniPubblicazioni e ConfigurazioneCampi; la guida «amministrazione» c'è
sezione("Amministrazione: le guide leggono impostazioni, campi e colonne");
$testo_guida = static function (array $g): string {
    $t = $g['in_breve'];
    foreach ($g['sezioni'] as $s) $t .= ' ' . $s['titolo'] . ' ' . implode(' ', $s['passi']) . ' ' . ($s['nota'] ?? '');
    return strip_tags($t);
};
$imp_gu = \App\Core\App::get(\App\Pubblicazioni\ImpostazioniPubblicazioni::class);
$orig_gu = $imp_gu->tutte();
$guide_gu = \App\Portale\GuidePerRuolo::tutte();
$t_pub = $testo_guida($guide_gu['pubblicazione']);
$t_ges = $testo_guida($guide_gu['pubblicazioni']);
$t_amm = $testo_guida($guide_gu['amministrazione']);
prova(isset($guide_gu['amministrazione']) && count($guide_gu['amministrazione']['sezioni']) >= 4 && str_contains($t_amm, 'Modulistica amministrativa') && str_contains($t_amm, 'Modulo Amministrazione') && str_contains($t_amm, 'Amministrazione · Richieste di pubblicazione'),
    "guida «amministrazione»: cos'è il modulo, la Modulistica amministrativa e chi abilita cosa");
prova(!str_contains($t_ges, 'modulo a sé') && str_contains($t_ges, 'modulo Amministrazione') && str_contains($t_ges, 'Campi e colonne') && str_contains($t_ges, 'Torna all’originale') && str_contains($t_ges, 'Impostazioni') && str_contains($t_ges, 'nasconderlo'),
    "guida «pubblicazioni»: modulo Amministrazione, due permessi, Impostazioni e Campi e colonne (cosa si può cambiare, «Torna all'originale», eliminare solo senza valori)");
prova(str_contains($t_pub, $orig_gu['max_sottotitolo'] . ' caratteri') && str_contains($t_pub, $orig_gu['max_descrizione'] . ' caratteri') && str_contains($t_pub, \App\Pubblicazioni\ImpostazioniPubblicazioni::inParole($orig_gu['chi_puo_inviare'])) && str_contains($t_pub, 'docenti e personale'),
    "guida «pubblicazione»: limiti e chi può inviare presi dalle impostazioni (non scritti nel testo)");

// un limite e i profili cambiati dall'amministratore, un campo e una colonna personalizzati: la guida li racconta
$esito_gu = $imp_gu->aggiorna(['max_sottotitolo' => 61, 'chi_puo_inviare' => ['docenti']]);
$q("INSERT INTO pubblicazioni_campi (chiave, etichetta) VALUES ('sottotitolo', 'Frase breve della prova') ON DUPLICATE KEY UPDATE etichetta = VALUES(etichetta)");
$q("INSERT INTO pubblicazioni_campi (chiave, visibile) VALUES ('tag', 0) ON DUPLICATE KEY UPDATE visibile = 0");
$q("INSERT INTO pubblicazioni_colonne (chiave, intestazione, a_cura_di) VALUES ('c_altri_referenti', 'Referenti della prova', 'Pina Prova') ON DUPLICATE KEY UPDATE intestazione = VALUES(intestazione), a_cura_di = VALUES(a_cura_di)");
$q("INSERT INTO pubblicazioni_colonne (chiave, visibile) VALUES ('j_tipo_iniziativa', 0) ON DUPLICATE KEY UPDATE visibile = 0");
\App\Core\App::get(\App\Pubblicazioni\ConfigurazioneCampi::class)->ricarica();
$guide_gu2 = \App\Portale\GuidePerRuolo::tutte();
$t_pub2 = $testo_guida($guide_gu2['pubblicazione']);
$t_ges2 = $testo_guida($guide_gu2['pubblicazioni']);
prova($esito_gu['errori'] === [] && str_contains($t_pub2, 'Sottotitolo') === false && str_contains($t_pub2, 'Frase breve della prova: al massimo 61 caratteri') && !str_contains($t_pub2, 'Tag') && !str_contains($t_pub2, 'al massimo 75 caratteri'),
    "guida «pubblicazione»: nome del campo, limite e campo nascosto come impostati");
prova(str_contains($t_pub2, 'oggi può inviarla chi ha uno di questi profili: Docenti (') && !str_contains($t_pub2, 'Personale tecnico amministrativo ('), "guida «pubblicazione»: i profili ammessi cambiati si vedono");
prova(str_contains($t_ges2, 'Referenti della prova. A cura di Pina Prova.') && !str_contains($t_ges2, 'Tipo iniziativa. A cura di'), "guida «pubblicazioni»: colonna rinominata e «a cura di» cambiato; colonna nascosta fuori");
// si rimette tutto com'era
$q("DELETE FROM pubblicazioni_campi WHERE chiave IN ('sottotitolo', 'tag')");
$q("DELETE FROM pubblicazioni_colonne WHERE chiave IN ('c_altri_referenti', 'j_tipo_iniziativa')");
$imp_gu->aggiorna(['max_sottotitolo' => $orig_gu['max_sottotitolo'], 'chi_puo_inviare' => $orig_gu['chi_puo_inviare']]);
\App\Core\App::get(\App\Pubblicazioni\ConfigurazioneCampi::class)->ricarica();
prova($imp_gu->tutte()['max_sottotitolo'] === $orig_gu['max_sottotitolo'] && $imp_gu->tutte()['chi_puo_inviare'] === $orig_gu['chi_puo_inviare']
    && str_contains($testo_guida(\App\Portale\GuidePerRuolo::tutte()['pubblicazione']), 'Sottotitolo: al massimo ' . $orig_gu['max_sottotitolo'] . ' caratteri'), "guide: impostazioni e campi rimessi come prima");
