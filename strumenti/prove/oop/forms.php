<?php
// Prove delle email di MyForms con il database: ricevuta a chi risponde, avviso a chi gestisce, avvisi di chiusura e di conservazione.
// I testi vengono dal catalogo delle email (CatalogoForms): si prova anche che un testo cambiato dalla pagina «Email» arrivi davvero nell'email e che il ripristino riporti l'originale.
// Le email non partono: inviaNotificaEmail() di esegui.php le raccoglie in $EMAIL. Si lancia da solo con: PROVE_SOLO=forms php strumenti/prove/esegui.php
sezione("MyForms: email di ricevuta, di nuova risposta, di chiusura e di conservazione (dal catalogo)");

foreach (["DELETE FROM forms_risposte", "DELETE FROM forms", "DELETE FROM email_modelli WHERE chiave LIKE 'forms\\_%'", "DELETE FROM utenti WHERE id IN (98931, 98932)"] as $sql) $q($sql);
$q("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari) VALUES (98931, 'Sara', 'Studente', 'stud-989-3@example.org', 3, ''), (98932, 'Paola', 'Proprietaria', 'prop-989-3@example.org', 1, '')");

$app_fm = \App\Core\App::per($conn);
$sv_fm = $app_fm->get(\App\Forms\ServizioForms::class);
$formi_fm = $app_fm->get(\App\Forms\FormRepository::class);
$rispo_fm = $app_fm->get(\App\Forms\RispostaRepository::class);
$notif_fm = $app_fm->get(\App\Forms\NotificheForms::class);
$modelli_fm = $app_fm->get(\App\Email\ModelliEmail::class);
$utente_fm = fn(int $id) => $conn->query("SELECT * FROM utenti WHERE id = $id")->fetch_assoc();

// Nuovo form aperto; chi risponde (98931) invia una risposta e il portale manda le email come fa form.php
$nuovo_fm = function (string $titolo, array $opz = []) use ($sv_fm): int {
    $id = $sv_fm->crea(array_merge([
        'titolo' => $titolo, 'campi_json' => json_encode([['etichetta' => 'Note', 'tipo' => 'text']]), 'pubblico_json' => json_encode(['profili' => ['registrati']]),
        'una_per_persona' => 0, 'proprietario_id' => 98932, 'email_notifica' => 'resp-989-3@example.org', 'ricevuta_email' => 1,
    ], $opz));
    $sv_fm->apri($id);
    return $id;
};
$rispondi_fm = function (int $id) use ($sv_fm, $formi_fm, $rispo_fm, $notif_fm, $utente_fm, &$EMAIL): array {
    $EMAIL = [];
    $form = $formi_fm->perId($id);
    $utente = $utente_fm(98931);
    $esito = $sv_fm->salvaRisposta($form, $utente, ['campo_c1' => 'Servono più prese'], [], '10.0.0.1');
    $riga = $esito['ok'] ? $rispo_fm->perId((int) $esito['id']) : null;
    if ($riga) $notif_fm->rispostaInviata($form, $utente, $riga);
    return ['form' => $form, 'riga' => $riga, 'email' => $EMAIL];
};
$a_fm = fn(array $email, string $a): ?array => array_values(array_filter($email, fn($m) => $m['a'] === $a))[0] ?? null;

// 1. Form normale: ricevuta a chi ha risposto (senza link) e avviso a chi gestisce, con i testi di sempre
$id1_fm = $nuovo_fm('Sondaggio  sulle aule');
$r1_fm = $rispondi_fm($id1_fm);
$ric_fm = $a_fm($r1_fm['email'], 'stud-989-3@example.org');
$avv_fm = $a_fm($r1_fm['email'], 'resp-989-3@example.org');
prova($r1_fm['riga'] !== null && count($r1_fm['email']) === 2 && $ric_fm !== null && $avv_fm !== null, "risposta a un form: partono la ricevuta e l'avviso, e nient'altro");
prova($ric_fm !== null && $ric_fm['oggetto'] === 'Ricevuta della tua risposta: Sondaggio sulle aule'
    && str_contains($ric_fm['corpo'], '<p>Abbiamo ricevuto la tua risposta al modulo <strong>Sondaggio sulle aule</strong>.</p>')
    && str_contains($ric_fm['corpo'], '<strong>' . $r1_fm['riga']['codice_ricevuta'] . '</strong>') && str_contains($ric_fm['corpo'], 'Conserva questo codice: ti serve se devi chiedere informazioni sulla tua risposta.')
    && !str_contains($ric_fm['corpo'], 'form.php?c=') && !str_contains($ric_fm['corpo'], '<a '), "ricevuta: oggetto e testo di sempre, titolo ripulito dagli spazi doppi, codice in grassetto, senza link");
prova($avv_fm !== null && $avv_fm['oggetto'] === 'Nuova risposta: Sondaggio sulle aule'
    && str_contains($avv_fm['corpo'], '<p>È arrivata una nuova risposta al modulo <strong>Sondaggio sulle aule</strong>.</p>')
    && str_contains($avv_fm['corpo'], '<br>Risposta di <strong>Sara Studente</strong> (stud-989-3@example.org).</p>')
    && str_contains($avv_fm['corpo'], '/admin/forms_risposte.php?id=' . $id1_fm) && str_contains($avv_fm['corpo'], '>Vedi le risposte</a>'), "avviso ai gestori: testo di sempre, nome in grassetto, pulsante verso le risposte");

// 2. Form che si può correggere: la ricevuta ha la frase e il pulsante per riaprire il modulo
$id2_fm = $nuovo_fm('Prenotazione sala', ['modificabile' => 1, 'email_notifica' => '']);
$r2_fm = $rispondi_fm($id2_fm);
$ric2_fm = $a_fm($r2_fm['email'], 'stud-989-3@example.org');
prova(count($r2_fm['email']) === 1 && $ric2_fm !== null && str_contains($ric2_fm['corpo'], '<p>Finché il modulo è aperto puoi correggere la risposta da qui.</p>')
    && str_contains($ric2_fm['corpo'], '/form.php?c=' . $r2_fm['form']['codice']) && str_contains($ric2_fm['corpo'], '>Apri il modulo</a>'), "ricevuta di un form modificabile: frase e pulsante «Apri il modulo» con il link del form");

// 3. Form anonimo: niente ricevuta, nessun nome nell'avviso
$id3_fm = $nuovo_fm('Opinioni anonime', ['anonima' => 1]);
$r3_fm = $rispondi_fm($id3_fm);
$avv3_fm = $a_fm($r3_fm['email'], 'resp-989-3@example.org');
prova(count($r3_fm['email']) === 1 && $a_fm($r3_fm['email'], 'stud-989-3@example.org') === null && $avv3_fm !== null && str_contains($avv3_fm['corpo'], 'La risposta è anonima.')
    && !str_contains($avv3_fm['corpo'] . $avv3_fm['oggetto'], 'Sara') && !str_contains($avv3_fm['corpo'] . $avv3_fm['oggetto'], 'stud-989-3'), "form anonimo: nessuna ricevuta e l'avviso non dice chi ha risposto");

// 4. Un testo cambiato dalla pagina «Email» arriva nell'email che parte; il ripristino riporta l'originale
$err_fm = $modelli_fm->salva('forms_nuova_risposta', 'Cambiato: {{titolo}}', "Testo nuovo per **{{titolo}}**, inviata il {{inviata_il}}.\n\n{{!chi}}", 'Vai alle risposte', 'Prova automatica');
$r4_fm = $rispondi_fm($id1_fm);
$avv4_fm = $a_fm($r4_fm['email'], 'resp-989-3@example.org');
prova($err_fm === [] && $avv4_fm !== null && $avv4_fm['oggetto'] === 'Cambiato: Sondaggio sulle aule' && str_contains($avv4_fm['corpo'], 'Testo nuovo per <strong>Sondaggio sulle aule</strong>')
    && str_contains($avv4_fm['corpo'], 'Risposta di <strong>Sara Studente</strong>') && str_contains($avv4_fm['corpo'], '>Vai alle risposte</a>') && !str_contains($avv4_fm['corpo'], 'È arrivata una nuova risposta'), "testo modificato dalla pagina Email: l'avviso usa il testo nuovo (oggetto, corpo e pulsante)");
$modelli_fm->ripristina('forms_nuova_risposta');
$r5_fm = $rispondi_fm($id1_fm);
$avv5_fm = $a_fm($r5_fm['email'], 'resp-989-3@example.org');
prova($avv5_fm !== null && $avv5_fm['oggetto'] === 'Nuova risposta: Sondaggio sulle aule' && str_contains($avv5_fm['corpo'], 'È arrivata una nuova risposta al modulo') && !str_contains($avv5_fm['corpo'], 'Testo nuovo'), "dopo il ripristino torna il testo originale");

// 5. Avvisi automatici al proprietario: chiusura (due motivi, singolare e plurale) e conservazione
$prop_fm = $utente_fm(98932);
$EMAIL = [];
$f_fm = $formi_fm->perId($id1_fm);
$ok1_fm = $notif_fm->formChiuso(array_merge($f_fm, ['max_risposte' => 2]), $prop_fm, 2, 'massimo');
$ok2_fm = $notif_fm->formChiuso(array_merge($f_fm, ['aperta_al' => '2026-11-17 09:30:00']), $prop_fm, 1, 'periodo');
$c1_fm = $EMAIL[0] ?? ['oggetto' => '', 'corpo' => ''];
$c2_fm = $EMAIL[1] ?? ['oggetto' => '', 'corpo' => ''];
prova($ok1_fm && $ok2_fm && $c1_fm['oggetto'] === 'Il modulo si è chiuso: Sondaggio sulle aule' && str_contains($c1_fm['corpo'], 'si è chiuso: ha raggiunto il numero massimo di risposte (2).</p>')
    && str_contains($c1_fm['corpo'], '<p>Ha ricevuto <strong>2</strong> risposte.</p>') && str_contains($c1_fm['corpo'], '/admin/forms_risposte.php?id=' . $id1_fm)
    && str_contains($c2_fm['corpo'], 'si è chiuso: è finito il periodo di apertura (il 17/11/2026 alle 09:30).</p>') && str_contains($c2_fm['corpo'], '<strong>1</strong> risposta.</p>'), "avviso di chiusura: i due motivi, «risposta» e «risposte» concordate con il numero");
$EMAIL = [];
$ok3_fm = $notif_fm->avvisoConservazione(array_merge($f_fm, ['conserva_mesi' => 1]), $prop_fm, 1, '2026-11-24 10:00:00', '2026-10-24 10:00:00');
$ok4_fm = $notif_fm->avvisoConservazione(array_merge($f_fm, ['conserva_mesi' => 12]), $prop_fm, 8, '2026-11-24 10:00:00', '2025-11-24 10:00:00');
$v1_fm = $EMAIL[0] ?? ['oggetto' => '', 'corpo' => ''];
$v2_fm = $EMAIL[1] ?? ['oggetto' => '', 'corpo' => ''];
prova($ok3_fm && $ok4_fm && $v1_fm['oggetto'] === 'Tra 7 giorni si cancellano delle risposte: Sondaggio sulle aule'
    && str_contains($v1_fm['corpo'], 'conservare le risposte per <strong>1</strong> mese.</p>') && str_contains($v1_fm['corpo'], 'Dal <strong>24/11/2026</strong> si cancelleranno da sole <strong>1</strong> risposta (quelle inviate prima del 24/10/2026), con i file allegati.</p>')
    && str_contains($v2_fm['corpo'], 'per <strong>12</strong> mesi.</p>') && str_contains($v2_fm['corpo'], '<strong>8</strong> risposte (quelle inviate prima del 24/11/2025)'), "avviso di conservazione: testo di sempre con mese/mesi e risposta/risposte concordati");

// 6. I cinque modelli compaiono nella pagina «Email»: gruppo, anteprima con i dati d'esempio e testi originali accettati dai controlli
$chiavi_fm = ['forms_ricevuta', 'forms_ricevuta_modificabile', 'forms_nuova_risposta', 'forms_chiuso', 'forms_conservazione'];
$tutti_fm = true; $anteprime_fm = true; $controlli_fm = true;
foreach ($chiavi_fm as $k) {
    $m = $modelli_fm->originale($k);
    if ($m === null || !str_starts_with((string) $m['gruppo'], 'MyForms · ')) { $tutti_fm = false; continue; }
    $r = $modelli_fm->render($k, $modelli_fm->datiDEsempio($k));
    if (str_contains($r['oggetto'] . $r['corpo'], '{{') || trim($r['corpo']) === '') $anteprime_fm = false;
    if ($modelli_fm->controlla($k, (string) $m['oggetto'], (string) $m['corpo'], (string) ($m['bottone'] ?? '')) !== []) $controlli_fm = false;
}
prova($tutti_fm && in_array('MyForms · A chi ha risposto', \App\Email\CatalogoEmail::gruppi(), true) && in_array('MyForms · A chi gestisce il modulo', \App\Email\CatalogoEmail::gruppi(), true), "i 5 modelli di MyForms sono nel catalogo della pagina Email, in due gruppi «MyForms · …»");
prova($anteprime_fm && $controlli_fm, "anteprima con i dati d'esempio senza segnaposto rimasti, e testi originali validi per i controlli di salvataggio");

foreach (["DELETE FROM forms_risposte", "DELETE FROM forms", "DELETE FROM email_modelli WHERE chiave LIKE 'forms\\_%'", "DELETE FROM utenti WHERE id IN (98931, 98932)"] as $sql) $q($sql);
