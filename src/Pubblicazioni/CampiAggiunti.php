<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Core\Container;
use App\Core\Database;
use App\Core\Esito;
use App\Didattica\CampiModulo;
use App\Didattica\Costanti as CostantiDidattica;
use App\Didattica\LetturaRisposte;
use App\Forms\CostruttoreCampi;

/**
 * I campi e le colonne che l'amministratore AGGIUNGE alla Richiesta di pubblicazione (scheda «Campi e colonne» dell'Amministrazione, passo AM5).
 *
 * - Campi nuovi del form (chiavi x_<n>): si creano con lo stesso costruttore di MyForms (CostruttoreCampi legge le righe; i tipi sono quelli di MyForms senza file e
 *   senza condizioni) e hanno in più il passo (1-7, in fondo al passo), la visibilità e, se diventano una colonna del file Excel, la sua intestazione. I valori scritti
 *   da chi invia la richiesta si LEGGONO e si CONTROLLANO con lo stesso codice di MyForms (LetturaRisposte) e si salvano in pubblicazioni.campi_extra_json.
 * - Colonne nuove degli operatori (chiavi y_<n>): intestazione, a cura di, colore, tipo (testo, SI/NO, numero), dopo quale colonna stanno. Si compilano nella scheda
 *   della richiesta come le altre e si salvano in pubblicazioni.colonne_extra_json.
 * - Le definizioni stanno in pubblicazioni_campi e pubblicazioni_colonne (la riga con il «tipo» è un campo nuovo; la colonna nuova ha l'intestazione): ConfigurazioneCampi
 *   le legge e le fonde con le altre, così tutte le pagine le vedono. Le chiavi non si riusano MAI: l'ultimo numero dato sta in impostazioni_sistema e cresce soltanto.
 * - Eliminare: un campo o una colonna SENZA valori in nessuna richiesta si elimina; CON valori si può solo nascondere (e la scheda dice in quante richieste ci sono
 *   valori). Il tipo di un campo che ha valori non si cambia.
 * Qui si controllano i dati e si scrive; il permesso lo ricontrolla la pagina (solo chi può tutto il modulo Amministrazione).
 */
final class CampiAggiunti
{
    /** Quanti campi e quante colonne nuove al massimo (per non riempire la pagina e il file Excel). */
    public const MAX_CAMPI = 30;
    public const MAX_COLONNE = 30;

    /** Il prefisso dei nomi del costruttore di MyForms nella scheda (cx_etichetta[], cx_tipo[]…). */
    public const PREFISSO = 'cx';

    /** LetturaRisposte (e le anagrafi di Ateneo che si porta dietro) si prende solo quando c'è un campo nuovo da leggere: le richieste senza campi nuovi non la toccano. */
    public function __construct(private Database $db, private ConfigurazioneCampi $config, private Container $contenitore)
    {
    }

    // ─────────────────────────── Valori scritti da chi invia ───────────────────────────

    /**
     * I valori dei campi nuovi VISIBILI letti da quello che arriva dal form (chiavi x_<n>; le caselle x_<n>[]) e gli errori campo per campo. Lo stesso codice di
     * MyForms (LetturaRisposte): obbligatorio, indirizzo web valido, scelta tra quelle proposte, data e numero nel formato giusto… Un campo nascosto non si legge.
     * I valori vuoti non si salvano. Per le righe a mano ($aMano) nessun campo è obbligatorio, ma il formato si controlla.
     *
     * @param array<string, mixed> $post
     * @return array{valori: array<string, string>, errori: array<string, string>}
     */
    public function leggi(array $post, bool $aMano = false): array
    {
        $valori = [];
        $errori = [];
        foreach ($this->config->campiExtra() as $c) {
            if (!$c['visibile']) {
                continue;
            }
            $campi = CampiModulo::da((string) json_encode([[
                'etichetta' => $c['etichetta'], 'tipo' => $c['tipo'], 'opzioni' => implode(', ', $c['opzioni']), 'obbligatorio' => $c['obbligatorio'] && !$aMano, 'aiuto' => '',
            ]], JSON_UNESCAPED_UNICODE));
            if ($campi === []) {
                continue;
            }
            $grezzo = $post[$c['chiave']] ?? '';
            if (is_array($grezzo) && $c['tipo'] !== 'multicheck') {
                $grezzo = ''; // un elenco dove ci vuole un valore solo: si ignora
            }
            // un campo solo per volta: CampiModulo lo chiama «c1», LetturaRisposte lo cerca come «campo_c1»
            [$risposte, $segnalazioni] = $this->contenitore->get(LetturaRisposte::class)->leggi($campi, ['campo_' . $campi[0]['nome'] => $grezzo], [], false);
            if ($segnalazioni !== []) {
                $errori[$c['chiave']] = (string) $segnalazioni[0];
            }
            $v = trim((string) ($risposte[0]['valore'] ?? ''));
            if ($v !== '') {
                $valori[$c['chiave']] = $v;
            }
        }

        return ['valori' => $valori, 'errori' => $errori];
    }

    /**
     * In quante richieste c'è un valore, per ogni campo nuovo e per ogni colonna nuova (le chiavi senza valori non ci sono).
     *
     * @return array{campi: array<string, int>, colonne: array<string, int>}
     */
    public function richiesteConValori(): array
    {
        $out = ['campi' => [], 'colonne' => []];
        $righe = $this->db->righe('SELECT campi_extra_json, colonne_extra_json FROM pubblicazioni WHERE campi_extra_json IS NOT NULL OR colonne_extra_json IS NOT NULL');
        foreach ($righe as $r) {
            foreach (['campi_extra_json' => 'campi', 'colonne_extra_json' => 'colonne'] as $colonna => $gruppo) {
                $valori = json_decode((string) ($r[$colonna] ?? ''), true);
                if (!is_array($valori)) {
                    continue;
                }
                foreach ($valori as $k => $v) {
                    if (is_scalar($v) && trim((string) $v) !== '') {
                        $out[$gruppo][(string) $k] = ($out[$gruppo][(string) $k] ?? 0) + 1;
                    }
                }
            }
        }

        return $out;
    }

    // ─────────────────────────── Campi nuovi del form ───────────────────────────

    /**
     * Salva i campi nuovi come arrivano dalla scheda: le righe del costruttore di MyForms (cx_etichetta[], cx_tipo[], cx_opzioni[], cx_obbl[], cx_aiuto[]) più, nello
     * stesso ordine, cx_chiave[] (vuota = campo nuovo), cx_passo[], cx_vis[], cx_excel[] e cx_intest[]. Una riga con la chiave di un campo che c'è e il nome vuoto,
     * o un campo che c'è e non è tra le righe, è una richiesta di eliminarlo: si può solo se non ha valori. Non salva niente se c'è un errore.
     *
     * @param array<string, mixed> $post
     * @return array{errori: array<string, string>, cambi: list<string>}
     */
    public function salvaCampi(array $post): array
    {
        $p = self::PREFISSO;
        $attesi = ['etichetta', 'tipo', 'opzioni', 'obbl', 'aiuto', 'chiave', 'passo', 'vis', 'excel', 'intest'];
        $n = count((array) ($post["{$p}_etichetta"] ?? []));
        foreach ($attesi as $nome) {
            if (!is_array($post["{$p}_{$nome}"] ?? null) || count($post["{$p}_{$nome}"]) !== $n) {
                return ['errori' => ['_' => 'La pagina non si è caricata per intero: ricaricala e riprova. Non è stato salvato niente.'], 'cambi' => []];
            }
        }
        $esistenti = [];
        foreach ($this->config->campiExtra() as $c) {
            $esistenti[$c['chiave']] = $c;
        }
        $conValori = $this->richiesteConValori()['campi'];
        $errori = [];
        $righe = [];   // i campi da salvare: [chiave|'' , definizione]
        $tenuti = [];
        foreach ($post["{$p}_etichetta"] as $i => $etichetta) {
            $chiave = trim((string) ($post["{$p}_chiave"][$i] ?? ''));
            $chiave = isset($esistenti[$chiave]) ? $chiave : '';
            $sub = [];
            foreach (['etichetta', 'tipo', 'opzioni', 'obbl', 'aiuto'] as $nome) {
                $sub["{$p}_{$nome}"] = [$post["{$p}_{$nome}"][$i] ?? ''];
            }
            $json = CostruttoreCampi::daPost($sub, $p, ConfigurazioneCampi::TIPI_CAMPO_EXTRA);
            $campo = $json === null ? null : (json_decode($json, true)[0] ?? null);
            if ($campo === null) {
                continue; // riga vuota: se era un campo che c'è, resta fuori da $tenuti e verrà eliminato
            }
            $nome = '«' . $campo['etichetta'] . '»';
            $tipo = (string) $campo['tipo'];
            $opzioni = $this->opzioniTesto((string) $campo['opzioni'], $tipo);
            $passo = (int) ($post["{$p}_passo"][$i] ?? 0);
            $in_excel = ($post["{$p}_excel"][$i] ?? '0') === '1';
            $intestazione = trim(str_replace(["\r", "\n"], ' ', (string) ($post["{$p}_intest"][$i] ?? '')));
            $aiuto = trim(str_replace("\r\n", "\n", (string) $campo['aiuto']));
            $e = null;
            if (mb_strlen((string) $campo['etichetta']) > ConfigurazioneCampi::MAX_ETICHETTA) {
                $e = "$nome: il nome è troppo lungo (massimo " . ConfigurazioneCampi::MAX_ETICHETTA . ' caratteri).';
            } elseif (mb_strlen($aiuto) > ConfigurazioneCampi::MAX_AIUTO) {
                $e = "$nome: il testo di aiuto è troppo lungo (massimo " . ConfigurazioneCampi::MAX_AIUTO . ' caratteri).';
            } elseif ($passo < 1 || $passo > ConfigurazioneCampi::PASSO_MAX) {
                $e = "$nome: scegli in quale passo compare.";
            } elseif (in_array($tipo, ['radio', 'multicheck'], true) && $opzioni === '') {
                $e = "$nome: scrivi le scelte, separate da virgola.";
            } elseif ($in_excel && mb_strlen($intestazione) > ConfigurazioneCampi::MAX_INTESTAZIONE) {
                $e = "$nome: l'intestazione della colonna è troppo lunga (massimo " . ConfigurazioneCampi::MAX_INTESTAZIONE . ' caratteri).';
            } elseif ($chiave !== '' && $esistenti[$chiave]['tipo'] !== $tipo && ($conValori[$chiave] ?? 0) > 0) {
                $e = "$nome: il tipo non si può cambiare, perché ci sono già risposte in " . self::richieste($conValori[$chiave]) . '. Puoi nasconderlo e crearne uno nuovo.';
            }
            if ($e !== null) {
                $errori["{$p}_$i"] = $e;
            }
            if ($chiave !== '') {
                $tenuti[$chiave] = true;
            }
            $righe[] = [$chiave, [
                'etichetta' => (string) $campo['etichetta'], 'tipo' => $tipo, 'opzioni' => $opzioni, 'obbligatorio' => (bool) $campo['obbligatorio'], 'aiuto' => $aiuto, 'passo' => $passo,
                'visibile' => ($post["{$p}_vis"][$i] ?? '0') === '1', 'in_excel' => $in_excel, 'intestazione_excel' => $in_excel ? ($intestazione !== '' ? $intestazione : (string) $campo['etichetta']) : '',
            ]];
        }
        // i campi che c'erano e non ci sono più tra le righe: eliminati, ma solo se non hanno valori
        $da_eliminare = [];
        foreach ($esistenti as $chiave => $c) {
            if (!isset($tenuti[$chiave])) {
                if (($conValori[$chiave] ?? 0) > 0) {
                    $errori['elimina_' . $chiave] = '«' . $c['etichetta'] . '» ha un valore in ' . self::richieste($conValori[$chiave]) . ': non si può eliminare. Puoi solo nasconderlo (togli la spunta «Visibile»).';
                } else {
                    $da_eliminare[] = $chiave;
                }
            }
        }
        $nuovi = count(array_filter($righe, static fn (array $r): bool => $r[0] === ''));
        if (count($esistenti) - count($da_eliminare) + $nuovi > self::MAX_CAMPI) {
            $errori['_'] = 'Ci possono essere al massimo ' . self::MAX_CAMPI . ' campi nuovi.';
        }
        if ($errori) {
            return ['errori' => $errori, 'cambi' => []];
        }

        $cambi = [];
        $this->db->transazione(function () use ($righe, $esistenti, $da_eliminare, &$cambi): void {
            foreach ($da_eliminare as $chiave) {
                $this->db->esegui('DELETE FROM pubblicazioni_campi WHERE chiave = ?', [$chiave]);
                $cambi[] = 'Campo nuovo «' . $esistenti[$chiave]['etichetta'] . '»: eliminato';
            }
            foreach ($righe as [$chiave, $d]) {
                $valori = [
                    $d['etichetta'], $d['aiuto'] === '' ? null : $d['aiuto'], $d['obbligatorio'] ? 1 : 0, $d['visibile'] ? null : 0, $d['tipo'], $d['opzioni'] === '' ? null : $d['opzioni'],
                    $d['passo'], $d['in_excel'] ? 1 : 0, $d['in_excel'] ? $d['intestazione_excel'] : null,
                ];
                if ($chiave === '') {
                    $chiave = 'x_' . $this->prossimoNumero('x');
                    $this->db->esegui(
                        'INSERT INTO pubblicazioni_campi (chiave, etichetta, aiuto, obbligatorio, visibile, tipo, opzioni, passo, in_excel, intestazione_excel) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        array_merge([$chiave], $valori)
                    );
                    $cambi[] = 'Campo nuovo «' . $d['etichetta'] . '»: creato (' . $this->descrizione($d) . ')';
                    continue;
                }
                $diff = $this->differenzeCampo($esistenti[$chiave], $d);
                if ($diff === []) {
                    continue;
                }
                $this->db->esegui(
                    'UPDATE pubblicazioni_campi SET etichetta = ?, aiuto = ?, obbligatorio = ?, visibile = ?, tipo = ?, opzioni = ?, passo = ?, in_excel = ?, intestazione_excel = ? WHERE chiave = ?',
                    array_merge($valori, [$chiave])
                );
                $cambi[] = 'Campo nuovo «' . $esistenti[$chiave]['etichetta'] . '»: ' . implode(', ', $diff);
            }
        });
        $this->config->ricarica();

        return ['errori' => [], 'cambi' => $cambi];
    }

    // ─────────────────────────── Colonne nuove degli operatori ───────────────────────────

    /**
     * Salva le colonne nuove come arrivano dalla scheda: ycol[<chiave>|<nuova_N>][intestazione|a_cura_di|colore|tipo|dopo|visibile]. Le righe nuove con l'intestazione
     * vuota e niente altro si ignorano. Non salva niente se c'è un errore.
     *
     * @param array<string, mixed> $post
     * @return array{errori: array<string, string>, cambi: list<string>}
     */
    public function salvaColonne(array $post): array
    {
        $ycol = is_array($post['ycol'] ?? null) ? $post['ycol'] : [];
        $esistenti = [];
        foreach ($this->config->colonneNuove() as $c) {
            $esistenti[$c['chiave']] = $c;
        }
        $chiaviValide = array_column($this->config->colonne(true), 'chiave');
        $errori = [];
        $righe = [];
        foreach ($ycol as $id => $p) {
            $id = (string) $id;
            if (!is_array($p)) {
                continue;
            }
            $chiave = isset($esistenti[$id]) ? $id : '';
            if ($chiave === '' && !preg_match('/^nuova_\d{1,6}$/', $id)) {
                continue; // un'identità che non c'è: non si crea niente con una chiave scelta da chi manda i dati
            }
            $intestazione = trim(str_replace(["\r", "\n"], ' ', (string) ($p['intestazione'] ?? '')));
            $a_cura_di = trim((string) preg_replace('/\s+/u', ' ', (string) ($p['a_cura_di'] ?? '')));
            if ($chiave === '' && $intestazione === '' && $a_cura_di === '') {
                continue;
            }
            $nome = '«' . ($intestazione !== '' ? $intestazione : ($esistenti[$chiave]['intestazione'] ?? 'colonna nuova')) . '»';
            $colore = strtoupper(trim((string) ($p['colore'] ?? '')));
            $tipo = (string) ($p['tipo'] ?? '');
            $dopo = trim((string) ($p['dopo'] ?? ''));
            $e = null;
            if ($intestazione === '') {
                $e = 'Colonna nuova: scrivi l\'intestazione.';
            } elseif (mb_strlen($intestazione) > ConfigurazioneCampi::MAX_INTESTAZIONE) {
                $e = "$nome: l'intestazione è troppo lunga (massimo " . ConfigurazioneCampi::MAX_INTESTAZIONE . ' caratteri).';
            } elseif ($a_cura_di === '') {
                $e = "$nome: scrivi a cura di chi è la colonna.";
            } elseif (mb_strlen($a_cura_di) > ConfigurazioneCampi::MAX_A_CURA_DI) {
                $e = "$nome: il nome di chi la cura è troppo lungo (massimo " . ConfigurazioneCampi::MAX_A_CURA_DI . ' caratteri).';
            } elseif (!preg_match('/^#[0-9A-F]{6}$/', $colore)) {
                $e = "$nome: il colore non è valido.";
            } elseif (!isset(ConfigurazioneCampi::TIPI[$tipo])) {
                $e = "$nome: scegli il tipo della colonna.";
            } elseif ($dopo !== '' && (!in_array($dopo, $chiaviValide, true) || $dopo === $chiave)) {
                $e = "$nome: scegli dopo quale colonna sta.";
            }
            if ($e !== null) {
                $errori['ycol_' . $id] = $e;
            }
            $righe[] = [$chiave, ['intestazione' => $intestazione, 'a_cura_di' => $a_cura_di, 'colore' => $colore, 'tipo' => $tipo, 'dopo' => $dopo, 'visibile' => !empty($p['visibile'])]];
        }
        $nuove = count(array_filter($righe, static fn (array $r): bool => $r[0] === ''));
        if (count($esistenti) + $nuove > self::MAX_COLONNE) {
            $errori['_'] = 'Ci possono essere al massimo ' . self::MAX_COLONNE . ' colonne nuove.';
        }
        if ($errori) {
            return ['errori' => $errori, 'cambi' => []];
        }

        $cambi = [];
        $this->db->transazione(function () use ($righe, $esistenti, &$cambi): void {
            foreach ($righe as [$chiave, $d]) {
                $valori = [$d['intestazione'], $d['a_cura_di'], $d['colore'], $d['tipo'], $d['visibile'] ? null : 0, $d['dopo'] === '' ? null : $d['dopo']];
                if ($chiave === '') {
                    $chiave = 'y_' . $this->prossimoNumero('y');
                    $this->db->esegui(
                        'INSERT INTO pubblicazioni_colonne (chiave, intestazione, a_cura_di, colore, tipo, visibile, dopo) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        array_merge([$chiave], $valori)
                    );
                    $cambi[] = 'Colonna nuova «' . $d['intestazione'] . '»: creata (a cura di ' . $d['a_cura_di'] . ', ' . (ConfigurazioneCampi::TIPI[$d['tipo']] ?? $d['tipo']) . ')';
                    continue;
                }
                $diff = $this->differenzeColonna($esistenti[$chiave], $d);
                if ($diff === []) {
                    continue;
                }
                $this->db->esegui(
                    'UPDATE pubblicazioni_colonne SET intestazione = ?, a_cura_di = ?, colore = ?, tipo = ?, visibile = ?, dopo = ? WHERE chiave = ?',
                    array_merge($valori, [$chiave])
                );
                $cambi[] = 'Colonna nuova «' . $esistenti[$chiave]['intestazione'] . '»: ' . implode(', ', $diff);
            }
        });
        $this->config->ricarica();

        return ['errori' => [], 'cambi' => $cambi];
    }

    /**
     * Elimina una colonna nuova degli operatori: solo se non ha valori in nessuna richiesta (altrimenti si può solo nascondere). Le colonne che stavano dopo di lei
     * passano a stare dove stava lei.
     */
    public function eliminaColonna(string $chiave): Esito
    {
        $colonna = null;
        foreach ($this->config->colonneNuove() as $c) {
            if ($c['chiave'] === $chiave) {
                $colonna = $c;
            }
        }
        if ($colonna === null) {
            return Esito::errore('Quella colonna non c\'è più.');
        }
        $n = $this->richiesteConValori()['colonne'][$chiave] ?? 0;
        if ($n > 0) {
            return Esito::errore('La colonna «' . $colonna['intestazione'] . '» ha un valore in ' . self::richieste($n) . ': non si può eliminare. Puoi solo nasconderla (togli la spunta «Visibile»): i valori restano.');
        }
        $this->db->transazione(function () use ($chiave, $colonna): void {
            $this->db->esegui('UPDATE pubblicazioni_colonne SET dopo = ? WHERE dopo = ?', [$colonna['dopo'] === '' ? null : $colonna['dopo'], $chiave]);
            $this->db->esegui('DELETE FROM pubblicazioni_colonne WHERE chiave = ?', [$chiave]);
        });
        $this->config->ricarica();

        return Esito::ok('Colonna «' . $colonna['intestazione'] . '» eliminata.');
    }

    // ─────────────────────────── Interno ───────────────────────────

    /** Il prossimo numero libero per un campo (x) o una colonna (y): sempre più alto di quelli già dati, anche di quelli eliminati. Va chiamato dentro una transazione. */
    private function prossimoNumero(string $lettera): int
    {
        $colonna = $lettera === 'x' ? 'pubblicazioni_ultimo_x' : 'pubblicazioni_ultimo_y';
        $tabella = $lettera === 'x' ? 'pubblicazioni_campi' : 'pubblicazioni_colonne';
        $massimo = 0;
        foreach ($this->db->righe("SELECT chiave FROM $tabella WHERE chiave LIKE '{$lettera}\\_%'") as $r) {
            $massimo = max($massimo, (int) substr((string) $r['chiave'], 2));
        }
        $ultimo = (int) ($this->db->valore("SELECT $colonna FROM impostazioni_sistema WHERE id = 1 FOR UPDATE") ?? 0);
        $numero = max($ultimo, $massimo) + 1;
        $this->db->esegui("UPDATE impostazioni_sistema SET $colonna = ? WHERE id = 1", [$numero]);

        return $numero;
    }

    /** Le scelte come si salvano (una per volta, separate da virgola) per i soli tipi che le hanno. */
    private function opzioniTesto(string $testo, string $tipo): string
    {
        return in_array($tipo, ['radio', 'multicheck'], true) ? implode(', ', ConfigurazioneCampi::opzioniDa($testo)) : '';
    }

    /** «1 richiesta», «3 richieste». */
    private static function richieste(int $n): string
    {
        return $n === 1 ? '1 richiesta' : $n . ' richieste';
    }

    /** @param array<string, mixed> $d un campo come si salva */
    private function descrizione(array $d): string
    {
        return (CostantiDidattica::TIPI_CAMPO_PRATICA[$d['tipo']] ?? $d['tipo']) . ', passo ' . $d['passo'] . ($d['in_excel'] ? ', colonna «' . $d['intestazione_excel'] . '» nel file Excel' : '');
    }

    /**
     * Cosa cambia in un campo che c'è, in parole per il registro.
     *
     * @param array<string, mixed> $prima il campo come è ora (ConfigurazioneCampi::campiExtra)
     * @param array<string, mixed> $dopo il campo come si salva
     * @return list<string>
     */
    private function differenzeCampo(array $prima, array $dopo): array
    {
        $d = [];
        if ($prima['etichetta'] !== $dopo['etichetta']) {
            $d[] = 'nome «' . $prima['etichetta'] . '» → «' . $dopo['etichetta'] . '»';
        }
        if ($prima['tipo'] !== $dopo['tipo']) {
            $d[] = 'tipo ' . (CostantiDidattica::TIPI_CAMPO_PRATICA[$prima['tipo']] ?? $prima['tipo']) . ' → ' . (CostantiDidattica::TIPI_CAMPO_PRATICA[$dopo['tipo']] ?? $dopo['tipo']);
        }
        if ($prima['passo'] !== $dopo['passo']) {
            $d[] = 'passo ' . $prima['passo'] . ' → ' . $dopo['passo'];
        }
        if ($prima['obbligatorio'] !== $dopo['obbligatorio']) {
            $d[] = $dopo['obbligatorio'] ? 'facoltativo → obbligatorio' : 'obbligatorio → facoltativo';
        }
        if ($prima['visibile'] !== $dopo['visibile']) {
            $d[] = $dopo['visibile'] ? 'nascosto → visibile' : 'visibile → nascosto';
        }
        if ($prima['aiuto'] !== $dopo['aiuto']) {
            $d[] = 'testo di aiuto cambiato';
        }
        if (implode(', ', $prima['opzioni']) !== $dopo['opzioni']) {
            $d[] = 'scelte cambiate';
        }
        if ($prima['in_excel'] !== $dopo['in_excel']) {
            $d[] = $dopo['in_excel'] ? 'ora è una colonna del file Excel («' . $dopo['intestazione_excel'] . '»)' : 'non è più una colonna del file Excel';
        } elseif ($dopo['in_excel'] && $prima['intestazione_excel'] !== $dopo['intestazione_excel']) {
            $d[] = 'intestazione nel file «' . $prima['intestazione_excel'] . '» → «' . $dopo['intestazione_excel'] . '»';
        }

        return $d;
    }

    /**
     * @param array<string, mixed> $prima la colonna come è ora (ConfigurazioneCampi::colonneNuove)
     * @param array<string, mixed> $dopo la colonna come si salva
     * @return list<string>
     */
    private function differenzeColonna(array $prima, array $dopo): array
    {
        $d = [];
        if ($prima['intestazione'] !== $dopo['intestazione']) {
            $d[] = 'intestazione «' . $prima['intestazione'] . '» → «' . $dopo['intestazione'] . '»';
        }
        if ($prima['a_cura_di'] !== $dopo['a_cura_di']) {
            $d[] = 'a cura di «' . $prima['a_cura_di'] . '» → «' . $dopo['a_cura_di'] . '»';
        }
        if (strtoupper($prima['colore']) !== $dopo['colore']) {
            $d[] = 'colore cambiato';
        }
        if ($prima['tipo'] !== $dopo['tipo']) {
            $d[] = 'tipo ' . (ConfigurazioneCampi::TIPI[$prima['tipo']] ?? $prima['tipo']) . ' → ' . (ConfigurazioneCampi::TIPI[$dopo['tipo']] ?? $dopo['tipo']);
        }
        if ($prima['dopo'] !== $dopo['dopo']) {
            $d[] = 'posizione cambiata';
        }
        if ($prima['visibile'] !== $dopo['visibile']) {
            $d[] = $dopo['visibile'] ? 'nascosta → visibile' : 'visibile → nascosta';
        }

        return $d;
    }
}
