<?php
// Prove via HTTP delle pagine del modulo FSL (ambiente locale acceso): convenzione online, documenti precompilati, scheda di valutazione e pannello.
sezione("Modulo FSL: pagine");
$senza_errori = fn(array $r) => !str_contains($r['corpo'], 'Fatal error') && !str_contains($r['corpo'], 'Warning:') && !str_contains($r['corpo'], 'Uncaught');

// Senza link né codice la pagina manda a «Ho perso il link» (commit 04255e0), non più a un 404: si controlla anche dove rimanda e che la destinazione risponda
$r = $http('/mydibest/convenzione_online.php');
prova($r['codice'] === 302 && $senza_errori($r) && str_ends_with((string) parse_url($r['dove'], PHP_URL_PATH), '/mydibest/ricevi_link_convenzione.php'), "convenzione_online.php → 302 verso ricevi_link_convenzione.php", 'risposta ' . $r['codice'] . ' verso ' . $r['dove']);
$r = $http($r['dove'] !== '' ? $r['dove'] : '/mydibest/ricevi_link_convenzione.php');
prova($r['codice'] === 200 && $senza_errori($r) && str_contains($r['corpo'], '</html>'), "ricevi_link_convenzione.php (dove manda la convenzione senza link): 200 senza errori PHP", 'risposta ' . $r['codice']);
foreach (['convenzione_online.php?code=NONESISTE' => 404, 'convenzione_online.php?t=ffff' => 404, 'convenzione_precompilata.php?code=NONESISTE' => 404] as $pag => $atteso) {
    $r = $http("/mydibest/$pag");
    prova($r['codice'] === $atteso && $senza_errori($r), "$pag → $atteso", 'risposta ' . $r['codice']);
}
prova($senza_errori($http('/mydibest/valutazione_fsl.php?t=xyz')), "valutazione_fsl.php: link non valido senza errori PHP");
prova($senza_errori($http('/mydibest/valutazione_fsl.php?t=' . str_repeat('a', 40))), "valutazione_fsl.php: link valido ma senza prenotazione, senza errori PHP");
// Passo 9: admin/fsl.php è «Numeri e report» (200); le vecchie schermate delle convenzioni rimandano (303) a «Scuole e convenzioni» con lo stesso significato
$r = $http('/mydibest/admin/fsl.php', null, $jar);
prova($r['codice'] === 200 && $senza_errori($r) && str_contains($r['corpo'], '</html>'), "admin/fsl.php da amministratore: Numeri e report, 200 senza errori PHP", 'risposta ' . $r['codice']);
foreach (['fsl.php?anno=2025' => 200, 'fsl_scuole.php' => 200, 'fsl_scuole.php?cosa=senza_convenzione' => 200, 'fsl_scuole.php?stato=scaduta' => 200] as $pag => $atteso) {
    $r = $http("/mydibest/admin/$pag", null, $jar);
    prova($r['codice'] === $atteso && $senza_errori($r) && str_contains($r['corpo'], '</html>'), "admin/$pag da amministratore: $atteso senza errori PHP", 'risposta ' . $r['codice']);
}
// ───── La tabella «indirizzo vecchio → arrivo», riga per riga (dati di prova usa e getta sulla scuola delle prove automatiche) ─────
$loc->query("INSERT IGNORE INTO scuole (codice, denominazione, comune, provincia, regione, tipo) VALUES ('ZZPR00000P', 'SCUOLA DELLE PROVE AUTOMATICHE', 'COSENZA', 'COSENZA', 'CALABRIA', 'LICEO')");
$loc->query("DELETE FROM convenzioni_scuole WHERE scuola_codice = 'ZZPR00000P'");
$loc->query("DELETE FROM convenzioni_compilate WHERE scuola_codice = 'ZZPR00000P'");
$loc->query("INSERT INTO convenzioni_scuole (scuola_codice, data_stipula, scadenza, protocollo) VALUES ('ZZPR00000P', '2020-01-01', '2021-01-01', 'PROVA-RIMANDI')");
$id_conv = (int) $loc->insert_id;
$loc->query("INSERT INTO convenzioni_compilate (token, scuola_codice, email, stato_iter, dati_json, file_firmato) VALUES ('" . bin2hex(random_bytes(16)) . "', 'ZZPR00000P', 'scuola.rimandi@example.org', 'caricata_scuola', '{}', 'uploads/convenzioni/prova.pdf')");
$id_cc = (int) $loc->insert_id;
prova($id_conv > 0 && $id_cc > 0, 'rimandi: dati di prova creati', $loc->error);
$arrivo = fn(array $r) => (string) preg_replace('#^.*/mydibest/admin/#', '', (string) ($r['dove'] ?? ''));
$tabella_rimandi = [
    'fsl.php?tab=home' => 'fsl_scuole.php',
    'fsl.php?tab=home&anno=2025' => 'fsl_scuole.php?anno=2025',
    'fsl.php?p_id=2&tab=iter' => 'fsl_scuole.php',
    'fsl.php?tab=iter&anno=tutti' => 'fsl_scuole.php?anno=tutti',
    'fsl.php?tab=iter&anno=abc' => 'fsl_scuole.php',
    'fsl.php?tab=iter&anno%5B%5D=2025' => 'fsl_scuole.php',
    'fsl.php?tab=stipulare' => 'fsl_scuole.php?cosa=senza_convenzione',
    'fsl.php?tab=stipulare&mostra=tutte' => 'fsl_scuole.php',
    'fsl.php?tab=verifica' => 'fsl_scuole.php?cosa=senza_convenzione',
    'fsl.php?tab=convenzioni' => 'fsl_scuole.php',
    'fsl.php?tab=convenzioni&vista=archivio' => 'fsl_scuole.php?stato=scaduta',
    'fsl.php?tab=convenzioni&nuova=1' => 'fsl_scuole.php?nuova=1',
    'fsl.php?tab=convenzioni&scarica=csv' => 'fsl_scuole.php?scarica=csv',
    'fsl.php?tab=convenzioni&scarica=exe' => 'fsl_scuole.php',
    "fsl.php?tab=convenzioni&conv_mod=$id_conv" => "fsl_scuole.php?k=ZZPR00000P&modifica=$id_conv",
    "fsl.php?tab=convenzioni&conv_rinnova=$id_conv" => "fsl_scuole.php?k=ZZPR00000P&rinnova=$id_conv",
    'fsl.php?tab=convenzioni&conv_mod=999999' => 'fsl_scuole.php',
    'fsl.php?tab=convenzioni&conv_mod=-1' => 'fsl_scuole.php',
    'fsl.php?tab=convenzioni&conv_nuova=ZZPR00000P&dal=2026-10-13&al=2026-12-02' => 'fsl_scuole.php?k=ZZPR00000P&nuova=1&dal=2026-10-13&al=2026-12-02',
    'fsl.php?tab=convenzioni&conv_nuova=ZZPR00000P&dal=2026-13-45&al=ieri' => 'fsl_scuole.php?k=ZZPR00000P&nuova=1',
    'fsl.php?tab=convenzioni&conv_nuova=ZZNO99999X' => 'fsl_scuole.php?nuova=1',
    'fsl.php?tab=scuola&k=ZZPR00000P' => 'fsl_scuole.php?k=ZZPR00000P',
    'fsl.php?tab=scuola&k=%3Cscript%3E' => 'fsl_scuole.php',
    'fsl.php?tab=scuola&k%5B%5D=x' => 'fsl_scuole.php',
    'fsl.php?tab=scuola' => 'fsl_scuole.php',
    'fsl.php?tab=riepilogo' => 'fsl.php#riepilogo',
    'fsl.php?tab=valutazioni&anno=2025' => 'fsl.php?anno=2025#valutazioni',
    'fsl.php?tab=report' => 'fsl.php#report',
    'fsl.php?tab=report&scarica=pippo' => 'fsl.php#report',
    'fsl.php?tab=riepilogo&scarica=csv' => 'fsl.php?scarica=csv&tabella=attivita',
    'fsl.php?tab=valutazioni&xlsx=1' => 'fsl.php?scarica=xlsx&tabella=valutazioni',
];
foreach ($tabella_rimandi as $da => $a_) {
    $r = $http("/mydibest/admin/$da", null, $jar);
    prova($r['codice'] === 303 && $arrivo($r) === $a_ && $senza_errori($r), "amministratore: admin/$da → $a_", 'risposta ' . $r['codice'] . ' → ' . $arrivo($r));
}
$r = $http("/mydibest/admin/fsl_scuole.php?cv=$id_cc", null, $jar);
prova($r['codice'] === 303 && str_starts_with($arrivo($r), 'fsl_scuole.php?k=ZZPR00000P') && str_ends_with($arrivo($r), '#messaggi'), 'fsl_scuole.php?cv=ID (link delle email) → scheda della scuola, ai messaggi', $r['codice'] . ' → ' . $arrivo($r));
foreach (['fsl_scuole.php?cv=999999', 'fsl_scuole.php?cv=abc', 'fsl_scuole.php?cv%5B%5D=1', 'fsl_scuole.php?k=%3Cscript%3E', 'fsl_scuole.php?stato=pippo&cosa=pippo', 'fsl.php?tab=pippo', 'fsl.php?tab%5B%5D=iter', 'fsl.php?anno=abc'] as $strano) {
    $r = $http("/mydibest/admin/$strano", null, $jar);
    $ultima = $r['codice'] === 303 ? $http($r['dove'], null, $jar) : $r;
    // una scuola o una compilazione che non c'è è un 404 con la pagina intera (e la strada per l'elenco)
    prova(in_array($ultima['codice'], [200, 404], true) && str_contains($ultima['corpo'], '</html>') && $senza_errori($r) && $senza_errori($ultima) && !str_contains($ultima['corpo'], 'Array to string') && !str_contains($ultima['corpo'], '<script>alert'),
        "parametro strano: admin/$strano → una pagina intera, senza errori", 'risposta ' . $r['codice'] . ' poi ' . $ultima['codice']);
}
$r = $http('/mydibest/admin/fsl_scuole.php?k=ZZPR00000P&anno=tutti', null, $jar);
prova($r['codice'] === 200 && stripos($r['corpo'], 'SCUOLA DELLE PROVE AUTOMATICHE') !== false && str_contains($r['corpo'], 'PROVA-RIMANDI'), "l'arrivo di scheda e convenzione mostra la scuola e il suo protocollo", 'risposta ' . $r['codice']);

// Senza accesso e senza permessi: nessun indirizzo (vecchio o nuovo) fa vedere o scaricare qualcosa
$vecchi_riservati = ['fsl.php', 'fsl.php?tab=iter', "fsl.php?tab=scuola&k=ZZPR00000P", 'fsl.php?tab=riepilogo&scarica=csv', 'fsl.php?tab=convenzioni&scarica=csv', 'fsl_scuole.php', "fsl_scuole.php?cv=$id_cc", 'fsl_scuole.php?scarica=csv'];
$jar_anon = tempnam(sys_get_temp_dir(), 'jar');
foreach ($vecchi_riservati as $da) {
    $r = $http("/mydibest/admin/$da", null, $jar_anon);
    prova(in_array($r['codice'], [302, 303], true) && str_contains($r['dove'], 'saml_login.php') && !str_contains($r['corpo'], 'PROVA-RIMANDI') && !str_contains($r['corpo'], 'ZZPR00000P'),
        "anonimo: admin/$da → accesso, nessun dato", 'risposta ' . $r['codice'] . ' → ' . $r['dove']);
    $r = $http("/mydibest/admin/$da", null, $jar2);
    prova($r['codice'] === 403 && !str_contains($r['corpo'], 'PROVA-RIMANDI'), "docente senza permessi: admin/$da → 403", 'risposta ' . $r['codice'] . ' → ' . $r['dove']);
}

// Chi ha SOLO le convenzioni (docente di prova con l'abilitazione, tolta a fine prova): mai i numeri degli studenti
$loc->query("DELETE FROM abilitazioni_ambito WHERE utente_id = 4 AND tipo = 'fsl_convenzioni'");
$loc->query("INSERT INTO abilitazioni_ambito (utente_id, tipo, pagina_id) VALUES (4, 'fsl_convenzioni', 0)");
$jar_conv = tempnam(sys_get_temp_dir(), 'jar');
$http('/__accesso?u=4', null, $jar_conv);
foreach (['fsl.php' => 'fsl_scuole.php', 'fsl.php?anno=2025' => 'fsl_scuole.php?anno=2025', 'fsl.php?tab=riepilogo' => 'fsl_scuole.php', 'fsl.php?tab=report&scarica=csv' => 'fsl_scuole.php',
          'fsl.php?scarica=csv&tabella=valutazioni' => 'fsl_scuole.php', 'fsl.php?tab=convenzioni&vista=archivio' => 'fsl_scuole.php?stato=scaduta', 'fsl.php?tab=scuola&k=ZZPR00000P' => 'fsl_scuole.php?k=ZZPR00000P'] as $da => $a_) {
    $r = $http("/mydibest/admin/$da", null, $jar_conv);
    prova($r['codice'] === 303 && $arrivo($r) === $a_, "solo convenzioni: admin/$da → $a_", 'risposta ' . $r['codice'] . ' → ' . $arrivo($r));
}
$r = $http('/mydibest/admin/fsl_scuole.php?k=ZZPR00000P&anno=tutti', null, $jar_conv);
prova($r['codice'] === 200 && $senza_errori($r) && str_contains($r['corpo'], 'PROVA-RIMANDI') && !str_contains($r['corpo'], 'href="fsl.php"'), 'solo convenzioni: la scheda si apre e il menu non offre «Numeri e report»', 'risposta ' . $r['codice']);

// POST dei moduli vecchi (token giusto): non cambiano niente, avvisano e portano nel punto giusto
$fotografia = function () use ($loc): string {
    $f = [];
    foreach (['convenzioni_scuole', 'convenzioni_compilate', 'convenzioni_messaggi', 'prenotazioni', 'log_email'] as $t) {
        $q = $loc->query("SELECT * FROM $t ORDER BY id");
        $f[$t] = $q ? $q->fetch_all(MYSQLI_ASSOC) : $loc->error;
    }
    return md5(serialize($f));
};
$loc->query("INSERT INTO convenzioni_messaggi (compilazione_id, da, testo, autore) VALUES ($id_cc, 'scuola', 'Messaggio ancora da leggere', 'Scuola')");
$r = $http('/mydibest/admin/fsl_scuole.php?k=ZZPR00000P&anno=tutti', null, $jar);
preg_match('/name="csrf_token" value="([^"]+)"/', $r['corpo'], $m_tok);
$prima = $fotografia();
foreach ([['conv_elimina' => $id_conv], ['conv_salva' => '1', 'scuola_codice' => 'ZZPR00000P', 'protocollo' => 'NUOVO'], ['cv_prendi' => $id_cc], ['cv_titulus' => $id_cc], ['cv_completa' => $id_cc],
          ['cv_rifiuta' => $id_cc, 'motivo' => 'no'], ['cv_invia_direttore' => $id_cc], ['cv_ricorda' => $id_cc], ['cv_ricorda_tutte' => '1'], ['cv_messaggio' => $id_cc, 'testo' => 'ciao'],
          ['st_chiedi' => 'ZZPR00000P'], ['st_scrivi' => 'ZZPR00000P', 'testo' => 'ciao'], ['cv_letto' => $id_cc]] as $modulo) {
    $nome = (string) array_key_first($modulo);
    $r = $http('/mydibest/admin/fsl.php?tab=iter', $modulo + ['csrf_token' => $m_tok[1] ?? ''], $jar);
    $atteso_p = str_starts_with($nome, 'st_') ? 'fsl_scuole.php?k=ZZPR00000P' : (str_starts_with($nome, 'cv_') && $nome !== 'cv_ricorda_tutte' ? "fsl_scuole.php?cv=$id_cc" : 'fsl_scuole.php');
    prova($r['codice'] === 303 && $arrivo($r) === $atteso_p, "POST vecchio «{$nome}» a fsl.php → 303 $atteso_p", 'risposta ' . $r['codice'] . ' → ' . $arrivo($r));
}
prova($fotografia() === $prima, 'POST vecchi: convenzioni, compilazioni, messaggi, prenotazioni ed email sono identici a prima');
$r = $http('/mydibest/admin/fsl_scuole.php', null, $jar);
prova(str_contains($r['corpo'], 'La pagina è cambiata'), "POST vecchi: alla pagina di arrivo c'è l'avviso «La pagina è cambiata»");

// «Entra come…» (sola lettura): gli indirizzi vecchi e le pagine nuove si guardano, niente si può mandare e guardare non cambia i dati
$jar_sim = tempnam(sys_get_temp_dir(), 'jar');
$http('/__accesso?u=1', null, $jar_sim);
$r = $http('/mydibest/admin/simula.php', null, $jar_sim);
preg_match('/name="csrf_token" value="([^"]+)"/', $r['corpo'], $m_sim);
$r = $http('/mydibest/admin/simula.php', ['csrf_token' => $m_sim[1] ?? '', 'utente_id' => 4], $jar_sim);
prova(in_array($r['codice'], [302, 303], true) && str_contains($r['dove'], 'area_personale.php'), '«Entra come…» come il docente con le sole convenzioni', 'risposta ' . $r['codice'] . ' → ' . $r['dove']);
$prima = $fotografia();
$r = $http('/mydibest/admin/fsl.php?tab=iter', null, $jar_sim);
prova($r['codice'] === 303 && $arrivo($r) === 'fsl_scuole.php', 'simulazione: l\'indirizzo vecchio rimanda come alla persona vera', 'risposta ' . $r['codice'] . ' → ' . $arrivo($r));
foreach (['fsl_scuole.php', 'fsl_scuole.php?k=ZZPR00000P&anno=tutti', 'fsl_scuole.php?stato=attiva'] as $pag) {
    $r = $http("/mydibest/admin/$pag", null, $jar_sim);
    prova($r['codice'] === 200 && $senza_errori($r), "simulazione: admin/$pag si guarda", 'risposta ' . $r['codice']);
}
$r = $http("/mydibest/admin/fsl_scuole.php?cv=$id_cc", null, $jar_sim);
prova($r['codice'] === 303 && str_contains($arrivo($r), 'k=ZZPR00000P'), 'simulazione: il link di un\'email porta alla scheda', 'risposta ' . $r['codice']);
foreach (['fsl.php' => ['conv_elimina' => $id_conv], 'fsl_scuole.php?k=ZZPR00000P' => ['cv_letto' => $id_cc], 'fsl_scuole.php' => ['cv_ricorda_tutte' => '1']] as $pag => $modulo) {
    $r = $http("/mydibest/admin/$pag", $modulo + ['csrf_token' => $m_sim[1] ?? ''], $jar_sim);
    prova($r['codice'] === 403 && str_contains($r['corpo'], 'Questa azione è bloccata'), "simulazione: POST a admin/$pag bloccato", 'risposta ' . $r['codice']);
}
prova($fotografia() === $prima, 'simulazione: guardare le pagine e provare a scrivere non ha cambiato niente (nemmeno i messaggi «letti»)');
$http('/mydibest/simula_esci.php', ['csrf_token' => $m_sim[1] ?? ''], $jar_sim);

// pulizia dei dati di prova
$loc->query("DELETE FROM abilitazioni_ambito WHERE utente_id = 4 AND tipo = 'fsl_convenzioni'");
$loc->query("DELETE FROM convenzioni_messaggi WHERE compilazione_id = $id_cc");
$loc->query("DELETE FROM convenzioni_compilate WHERE id = $id_cc");
$loc->query("DELETE FROM convenzioni_scuole WHERE id = $id_conv");
@unlink($jar_anon);
@unlink($jar_conv);
@unlink($jar_sim);

$r = $http('/mydibest/admin/fsl.php', null, $jar2);
prova($r['codice'] === 403, "admin/fsl.php da un docente senza permessi: accesso negato", 'risposta ' . $r['codice']);
foreach (['conv_verifica' => '1', 'conv_elimina' => '1', 'conv_online_prot' => '1', 'conv_salva' => '1'] as $azione => $valore) {
    $r = $http('/mydibest/admin/fsl.php?tab=convenzioni', [$azione => $valore, 'csrf_token' => 'sbagliato'], $jar);
    prova($r['codice'] === 403, "admin/fsl.php: $azione con token CSRF sbagliato respinto", 'risposta ' . $r['codice']);
}
prova($http('/mydibest/admin/convenzione_file.php?id=999', null, $jar)['codice'] === 404, "admin/convenzione_file.php: convenzione senza file, file non trovato");
prova($http('/mydibest/admin/convenzione_file.php?id=1', null, $jar2)['codice'] === 403, "admin/convenzione_file.php da un docente senza permessi: accesso negato");
