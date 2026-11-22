<?php
// Piccole funzioni di aiuto per l'elenco e le schede della pagina di un'area: dati di un progetto ($info_progetto), pulsante di iscrizione
// del progetto o di un'edizione ($pulsante_progetto), intestazione di un giorno nei layout cronologici ($titolo_giorno).
// Sono funzioni anonime (si chiamano come $info_progetto($evento)) caricate da master_template.php prima dei layout, nello stesso ambito della pagina.
// Variabili che usa:
//   $conn, $dettagli_progetti, $mie_iscrizioni (per riferimento: si riempie dopo), $col_primaria, $utente_logged, $current_filename

// Dati di un progetto per elenco e scheda: scheda, edizioni (turni), stato, iscrizione dell'utente, tipo (scuole o generico)
$info_progetto = function (array $ev) use ($conn, $dettagli_progetti, &$mie_iscrizioni): array {
    $d  = $dettagli_progetti[(int)$ev['id']] ?? [];
    $ie = info_edizioni_progetto($conn, $d, $ev['turni'] ?? [], $mie_iscrizioni[(int)$ev['id']] ?? []);
    $mio_ed = null;
    foreach ($ie['edizioni'] as $ed) if ($ed['mio'] !== null) { $mio_ed = $ed; break; }
    $min_ed = $ie['edizioni'] ? min(array_column($ie['edizioni'], 'min')) : null;
    $max_ed = array_filter(array_column($ie['edizioni'], 'max'));
    // Con limiti diversi tra le edizioni: dal minimo più basso al massimo più alto (senza massimo se un'edizione non ne ha)
    $max_ed = ($max_ed && count($max_ed) === count($ie['edizioni'])) ? max($max_ed) : null;
    // Progetto con rimando: niente iscrizioni qui, lo stato dice dove prenotare (finché il progetto non è concluso)
    $dest = destinazione_progetto($conn, $d);
    if ($dest && $ie['stato']['codice'] !== 'concluso') {
        $ie['stato'] = ['codice' => 'aperte', 'etichetta' => 'Prenotazioni su ' . $dest['nome'], 'bg' => '#E0F2FE', 'fg' => '#075985', 'ordine' => 1];
    }
    return ['dest' => $dest, 'd' => $d, 't' => $ev['turni'][0] ?? null, 'edizioni' => $ie['edizioni'], 'liberi' => $ie['liberi'],
            'prossima_apertura' => $ie['prossima_apertura'], 'limiti' => testo_limiti_partecipanti($min_ed, $max_ed), 'max_studenti' => $max_ed,
            // Senza date, la nota sul periodo (es. "novembre-dicembre 2026") vale più di "Date da definire"
            'stato' => $ie['stato'], 'periodo' => (empty($d['data_inizio']) && empty($d['data_fine']) && !empty($d['periodo_note'])) ? $d['periodo_note'] : periodo_progetto($d), 'mio' => $ie['mio'], 'mio_ed' => $mio_ed,
            'attesa' => array_sum(array_column($ie['edizioni'], 'attesa')), 'scuole' => (int)($d['per_scuole'] ?? 1) === 1,
            'piu_ed' => (int)($d['piu_edizioni'] ?? 0) === 1];   // il progetto consente di prenotare più edizioni
};

// Pulsante di iscrizione (testi "scuola" nei progetti per le scuole). Con $ed: pulsante di quella edizione (scheda);
// senza: pulsante del progetto (elenco), che con più edizioni porta alla scheda per scegliere.
$pulsante_progetto = function (array $ev, array $ip, ?array $ed = null) use ($col_primaria, $utente_logged, $current_filename): string {
    $stile = 'background-color:' . $col_primaria . ';color:' . colore_testo_su($col_primaria) . ';border:none;';
    $url_scheda = htmlspecialchars($current_filename) . '.php?progetto=' . (int)$ev['id'];
    if (!empty($ip['dest'])) return html_pulsante_destinazione($ip['dest'], $stile);
    $mio = $ed ? $ed['mio'] : $ip['mio'];
    if ($mio === 'richiesta_conferma') return '<a href="area_personale.php" class="btn btn-warning fw-bold text-dark w-100"><i class="fa fa-bell me-1" aria-hidden="true"></i>Posto offerto: conferma</a>';
    if ($mio === 'in_attesa') return '<a href="area_personale.php" class="btn btn-outline-warning fw-bold text-dark w-100"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>Sei in lista d\'attesa</a>';
    $sc = $ip['scuole'];
    if ($mio !== null) return '<a href="area_personale.php" class="btn btn-success fw-bold w-100"><i class="fa fa-check me-1" aria-hidden="true"></i>' . ($sc ? 'La tua scuola è iscritta' : 'Sei iscritto') . '</a>';
    // Si partecipa a una sola edizione: già iscritti (o in attesa) su un'altra
    if ($ed && $ip['mio'] !== null && empty($ip['piu_ed'])) return '';
    if (!$ip['edizioni'] || (int)($ev['richiede_prenotazione'] ?? 1) === 0) return '';
    // Ogni edizione ha la sua finestra: con $ed vale lo stato di quell'edizione, altrimenti quello del progetto
    $c = $ed ? $ed['stato']['codice'] : $ip['stato']['codice'];
    $apre = $ed ? ($ed['t']['data_apertura'] ?? null) : ($ip['prossima_apertura'] ?? null);
    if ($c === 'concluso') return '<button class="btn btn-secondary fw-bold w-100" disabled>Progetto concluso</button>';
    if ($c === 'arrivo' && $apre) return '<button class="btn btn-outline-secondary fw-bold w-100" disabled>Iscrizioni dal ' . date('d/m/Y H:i', strtotime($apre)) . '</button>';
    if (!in_array($c, ['aperte', 'attesa'], true)) return '<button class="btn btn-outline-secondary fw-bold w-100" disabled>Iscrizioni chiuse</button>';
    if (!$utente_logged && \App\Eventi\AccessoEvento::serveAccesso((int)($ev['ruolo_accesso_id'] ?? 0))) {
        $rit = urlencode($current_filename . '.php?progetto=' . (int)$ev['id']);
        return '<a href="saml_login.php?redirect=' . $rit . '" class="btn fw-bold w-100" style="' . $stile . '"><i class="fa fa-key me-1" aria-hidden="true"></i>' . ($sc ? 'Accedi con SPID/CIE per iscrivere la scuola' : 'Accedi per iscriverti') . '</a>';
    }
    if (!$ed) {
        if (count($ip['edizioni']) > 1) return '<a href="' . $url_scheda . '#iscrizione" class="btn fw-bold w-100" style="' . $stile . '"><i class="fa fa-school me-1" aria-hidden="true"></i>Scegli l\'edizione</a>';
        $ed = $ip['edizioni'][0];
    }
    $t_id = (int)$ed['t']['id'];
    $fsl_b = (int)($ip['d']['convenzione'] ?? 0) === 1; // attività FSL: va nel programma della scuola
    $nel_programma = $fsl_b && fsl_programma_ha((int)$ev['id'], $ed ? (int)$ed['t']['id'] : 0);
    if (!$ed['libera'] && empty($ed['t']['abilita_lista_attesa'])) return '<button class="btn btn-outline-secondary fw-bold w-100" disabled>' . ($sc ? 'Edizione già assegnata' : 'Posti esauriti') . '</button>';
    if (!$ed['libera']) return '<button type="button" class="btn btn-warning fw-bold text-dark w-100" data-bs-toggle="modal" data-bs-target="#modPrenota' . $t_id . '"><i class="fa ' . ($fsl_b ? 'fa-clipboard-list' : 'fa-hourglass-half') . ' me-1" aria-hidden="true"></i>' . ($fsl_b ? ($nel_programma ? 'Nel programma: modifica' : 'Aggiungi al programma (lista d\'attesa)') : 'Mettiti in lista d\'attesa') . '</button>';
    return '<button type="button" class="btn fw-bold w-100" style="' . $stile . '" data-bs-toggle="modal" data-bs-target="#modPrenota' . $t_id . '"><i class="fa ' . ($fsl_b ? 'fa-clipboard-list' : ($sc ? 'fa-school' : 'fa-user-plus')) . ' me-1" aria-hidden="true"></i>' . ($fsl_b ? ($nel_programma ? 'Nel programma: modifica' : 'Aggiungi al programma') : ($sc ? 'Iscrivi la scuola' : 'Iscriviti')) . '</button>';
};

// Intestazione di un giorno nei template cronologici. I turni senza data stanno sotto la chiave
// sentinella 9999-12-31 (ultima dopo ksort): va mostrata come "Senza data fissa", non come data.
if (!defined('GIORNO_SENZA_DATA')) define('GIORNO_SENZA_DATA', '9999-12-31');
$titolo_giorno = function (string $d): string {
    return $d === GIORNO_SENZA_DATA
        ? '<i class="fa fa-infinity me-2" aria-hidden="true"></i>Senza data fissa'
        : formattaDataItaliano($d);
};
