<?php

declare(strict_types=1);

namespace App\Portale\Pagine;

use App\Anagrafi\ServizioScuole;
use App\Anagrafi\Testi;
use App\Auth\Flash;
use App\Auth\Sessione;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Fsl\Costanti;
use App\Fsl\ElencoScuoleFsl;
use App\Fsl\PeriodiConvenzione;
use App\Fsl\PercorsoFsl;
use App\Fsl\ReportFsl;
use App\Fsl\ServizioConvenzioni;
use App\Fsl\ServizioPannelloFsl;
use App\Http\Pagina;
use App\Http\Richiesta;
use App\Http\Risposta;
use App\Http\Vista;
use App\Infrastructure\Audit\AuditLog;
use App\Infrastructure\Documenti\Excel;
use App\Infrastructure\Documenti\FogliEsportabili;
use App\Infrastructure\Documenti\TabellaEsportabile;
use App\Portale\Pannello\ContestoPannello;
use App\Portale\Vista\Pannello;

/**
 * FSL › Numeri e report (admin/fsl.php): il riepilogo dell'anno scolastico, le schede di valutazione e il report per il Ministero, tre sezioni di UNA pagina
 * con lo stesso filtro «anno scolastico». Non c'è niente da fare qui: chi lavora sulle convenzioni delle scuole è in «Scuole e convenzioni» (PaginaScuoleFsl).
 *
 * Chi ha SOLO le convenzioni (non `puo_fsl`) non vede numeri di studenti né valutazioni: va alla pagina Scuole. Lo stesso per gli indirizzi vecchi delle schermate
 * «convenzioni» (`?tab=iter`, `?tab=scuola&k=…`…): RimandiFsl dice dove portano, e un modulo vecchio mandato a questo indirizzo non fa niente (si avvisa e
 * si porta alla scheda giusta). L'accesso (chi ha almeno le convenzioni) lo decide il Nucleo (PermessiPagine) PRIMA di costruire la classe, e il token CSRF di
 * ogni POST pure. Il disegno è in viste/pannello/fsl_numeri.php.
 */
final class PaginaNumeriFsl implements Pagina
{
    public function __construct(
        private readonly ServizioPannelloFsl $pannello,
        private readonly ServizioConvenzioni $convenzioni,
        private readonly ReportFsl $report,
        private readonly ElencoScuoleFsl $elenco,
        private readonly RimandiFsl $rimandi,
        private readonly ServizioScuole $scuole,
        private readonly ContestoPannello $contesto,
        private readonly Flash $flash,
        private readonly Sessione $sessione,
        private readonly AuditLog $audit,
        private readonly IndirizzoClient $indirizzo,
        private readonly Orologio $orologio,
    ) {
    }

    public function __invoke(Richiesta $r): Risposta
    {
        if ($r->eUnPost()) {
            $this->flash->imposta("La pagina è cambiata: le azioni sulle convenzioni delle scuole ora si fanno dalla scheda della scuola, e da qui non si fa più niente. Il modulo che hai mandato non ha cambiato nulla: riprova da qui.", 'warning');

            return Risposta::vai($this->rimandi->perUnModuloVecchio($r));
        }
        $permessi = $this->contesto->permessi($r);
        $tab = (string) $r->query('tab', '');
        if ($permessi === null || !$permessi->fsl() || in_array($tab, RimandiFsl::TAB_SCUOLE, true)) {
            return Risposta::vai($this->rimandi->perLeScuole($r));   // anche chi ha solo le convenzioni: i numeri degli studenti non sono per lui
        }
        if (in_array($tab, RimandiFsl::TAB_NUMERI, true)) {
            return Risposta::vai($this->rimandi->perGliNumeri($r));
        }

        $anno = $this->anno($r);
        $dal = $anno . '-09-01';
        $al = ($anno + 1) . '-08-31';
        $formato = (string) $r->query('scarica', '');
        $quale = (string) $r->query('tabella', '');
        if ($formato !== '' && !isset(TabellaEsportabile::FORMATI[$formato])) {
            $this->flash->imposta('Formato non disponibile.', 'danger');

            return Risposta::vai(self::url($anno));
        }
        $r1 = $this->pannello->riepilogo($dal, $al);
        $oggi = $this->orologio->adesso()->format('Y-m-d');
        $perc = PercorsoFsl::contatori($r1['righe'], $oggi);
        $media = static fn (array $v): string => $v ? number_format(array_sum($v) / count($v), 1, ',', '') : '—';
        $nomeAnno = $anno . '/' . ($anno + 1);

        if ($formato !== '' && in_array($quale, RimandiFsl::TABELLE, true)) {
            return $quale === 'report'
                ? $this->scaricaReport($formato, $dal, $al, $anno)
                : $this->scaricaTabella($formato, $quale, $r1, $anno, $media);
        }

        $righeScuole = $this->elenco->righe($anno, false);
        $senzaConvenzione = (int) ElencoScuoleFsl::tessere($righeScuole)['senza_convenzione'];
        $valutazioni = $r1['valutazioni'];
        $inAttesaVal = max(0, (int) $r1['n_inviti'] - count($valutazioni));
        $mancanti = $perc['elenchi_mancanti'];
        $data = static fn (mixed $x): string => $x ? date('d/m/Y', (int) strtotime((string) $x)) : '—';
        $urlSc = static fn (string $cosa): string => 'fsl_scuole.php?cosa=' . $cosa;

        $compiti = Pannello::compiti([
            ['numero' => $senzaConvenzione, 'titolo' => 'Scuole senza convenzione', 'testo' => 'Hanno prenotazioni ma non una convenzione valida.', 'href' => $urlSc('senza_convenzione'), 'bottone' => "Vedi l'elenco", 'tono' => 'tuo', 'vuoto' => 'Tutte le scuole sono a posto.'],
            ['numero' => count($mancanti), 'titolo' => 'Elenchi studenti mancanti', 'testo' => "Attività finite senza l'elenco dei nomi: senza, gli attestati non partono.", 'href' => '#elenchi', 'bottone' => 'Vedi le attività', 'tono' => 'tuo', 'vuoto' => 'Tutti gli elenchi sono arrivati.'],
            ['numero' => max(0, $perc['attestati_dovuti'] - $perc['attestati_inviati']), 'titolo' => 'Attestati da inviare', 'testo' => 'Attività finite con attestati non ancora spediti.', 'href' => '#tabAtt', 'bottone' => 'Vedi le attività', 'tono' => 'tuo', 'vuoto' => 'Tutti gli attestati sono partiti.'],
            ['numero' => $inAttesaVal, 'titolo' => 'Valutazioni attese', 'testo' => 'Schede inviate ai docenti e non ancora compilate.', 'href' => '#valutazioni', 'bottone' => 'Vedi', 'tono' => 'attesa', 'vuoto' => 'Nessuna scheda in attesa.'],
        ], "Cosa devo fare nell'anno scolastico");
        $percorso = Pannello::percorso([
            ['numero' => count($r1['per_att']), 'testo' => 'Attività con iscrizioni', 'nota' => "dell'anno scolastico", 'href' => '#tabAtt'],
            ['numero' => $perc['scuole'], 'testo' => 'Scuole iscritte', 'nota' => count($r1['conf']) . ' iscrizioni · ' . $r1['n_studenti'] . ' studenti' . ($perc['in_attesa'] ? ' · ' . $perc['in_attesa'] . ' in attesa' : ''), 'href' => '#tabScu'],
            ['numero' => $senzaConvenzione, 'testo' => 'Convenzioni da stipulare', 'nota' => $r1['n_conv_mancanti'] ? $r1['n_conv_mancanti'] . ' iscrizioni senza convenzione valida' : 'tutto a posto', 'href' => $urlSc('senza_convenzione'), 'avviso' => $senzaConvenzione > 0],
            ['numero' => count($mancanti), 'testo' => 'Elenchi studenti mancanti', 'nota' => 'attività finite, elenco non inserito', 'href' => $mancanti ? '#elenchi' : '', 'avviso' => $mancanti !== []],
            ['numero' => $perc['attestati_inviati'] . '/' . $perc['attestati_dovuti'], 'testo' => 'Attestati inviati', 'nota' => 'delle attività finite con attestati'],
            ['numero' => count($valutazioni) . ($r1['n_inviti'] ? '/' . $r1['n_inviti'] : ''), 'testo' => 'Valutazioni ricevute', 'nota' => $valutazioni ? 'media ' . $media(array_map(static fn (array $x): float => (float) $x['media'], $valutazioni)) : 'nessuna ancora', 'href' => '#valutazioni'],
        ], "Percorso delle scuole nell'anno scolastico");

        $elenchi = array_map(static fn (array $x): array => [
            'scuola' => (string) $x['scuola_nome'], 'titolo' => (string) $x['titolo'], 'area' => (string) $x['area'], 'al' => $data($x['al']),
            'docente' => trim($x['nome'] . ' ' . $x['cognome']), 'email' => (string) $x['email'],
            'href' => 'iscritti.php?p_id=' . (int) $x['pagina_id'] . (!empty($x['archiviato']) ? '&archivio=1' : '') . '&f_cerca=' . urlencode((string) $x['codice_prenotazione']),
        ], $mancanti);
        $attivita = array_map(static fn (array $a): array => [
            'titolo' => (string) $a['titolo'], 'area' => (string) $a['area'], 'periodo' => $data($a['dal']) . ($a['al'] !== $a['dal'] ? ' – ' . $data($a['al']) : ''),
            'scuole' => count($a['scuole']), 'confermate' => (int) ($a['confermate'] ?? 0), 'studenti' => (int) ($a['studenti'] ?? 0), 'presenze' => (int) ($a['presenze'] ?? 0),
            'senzaConvenzione' => (int) ($a['conv_mancanti'] ?? 0), 'valutazioni' => count($a['voti'] ?? []) . '/' . (int) ($a['val_inviate'] ?? 0), 'media' => $media($a['voti'] ?? []),
        ], array_values($r1['per_att']));
        $perScuola = array_map(function (array $s) use ($media): array {
            $codice = (string) ($s['codice'] ?? '');
            $ultima = $codice !== '' ? ($this->convenzioni->dellaScuola($codice)[0] ?? null) : null;
            $chiave = RimandiFsl::chiave($codice);

            return [
                'nome' => (string) $s['nome'], 'codice' => $codice !== '' ? $codice : '—', 'href' => $chiave !== '' ? 'fsl_scuole.php?k=' . urlencode($chiave) : '',
                'attivita' => implode(', ', array_keys($s['attivita'])), 'iscrizioni' => (int) $s['iscrizioni'], 'studenti' => (int) ($s['studenti'] ?? 0), 'presenze' => (int) ($s['presenze'] ?? 0),
                'convenzione' => $ultima !== null ? PeriodiConvenzione::testoValidita($ultima) : 'nessuna', 'daStipulare' => !empty($s['conv_mancanti']), 'media' => $media($s['voti'] ?? []),
            ];
        }, array_values($r1['per_scuola']));

        $aspetti = [];
        foreach (Costanti::ASPETTI as $k => $etichetta) {
            $m = !empty($r1['somme'][$k]) ? array_sum($r1['somme'][$k]) / count($r1['somme'][$k]) : 0;
            $aspetti[] = ['etichetta' => $etichetta, 'media' => number_format($m, 1, ',', ''), 'larghezza' => (int) round($m / 5 * 100)];
        }
        $si = ['si' => 'Sì', 'forse' => 'Forse', 'no' => 'No'];
        $tabVal = array_map(function (array $v) use ($data, $si): array {
            $s = !empty($v['scuola_codice']) ? $this->scuole->perCodice((string) $v['scuola_codice']) : null;

            return [
                'data' => $data($v['created_at']), 'titolo' => (string) $v['titolo'], 'scuola' => $s ? Testi::etichettaScuola($s) : '—', 'compilata' => (string) $v['compilata_da'],
                'media' => number_format((float) $v['media'], 1, ',', ''), 'riproporrebbe' => $si[$v['ripeterebbe']] ?? '',
                'testi' => array_map(static fn (string $k): string => (string) ($v['risposte']['testi'][$k] ?? ''), array_keys(Costanti::APERTE)),
            ];
        }, $valutazioni);

        $rep = $this->report->dati($dal, $al);
        $tp = $rep['totali'];
        $reportPercorso = Pannello::percorso([
            ['numero' => $tp['scuole'], 'testo' => 'Scuole', 'nota' => $tp['attivita'] . ' attività'],
            ['numero' => $tp['classi'], 'testo' => 'Classi', 'nota' => 'iscrizioni confermate'],
            ['numero' => $tp['studenti'], 'testo' => 'Studenti iscritti', 'nota' => $tp['presenti'] . ' presenti'],
            ['numero' => number_format((float) $tp['ore_studente'], 0, ',', '.'), 'testo' => 'Ore di formazione', 'nota' => 'ore × studenti presenti'],
        ], "Numeri dell'anno scolastico");
        $reportScuole = array_map(static fn (array $s): array => [
            'nome' => (string) $s['nome'], 'comune' => (string) $s['comune'], 'attivita' => (int) $s['attivita'], 'classi' => (int) $s['classi'], 'studenti' => (int) $s['studenti'],
            'presenti' => (int) $s['presenti'], 'ore' => number_format((float) $s['ore_studente'], 1, ',', '.'), 'oreMedie' => number_format((float) $s['ore_medie'], 1, ',', '.'),
        ], $rep['scuole']);

        $anni = [];
        foreach (range($this->pannello->annoCorrente() + 1, max(2020, $this->pannello->annoCorrente() - 5)) as $a) {
            $anni[] = ['valore' => (string) $a, 'testo' => $a . '/' . ($a + 1), 'scelto' => $a === $anno];
        }
        $titoli = Pannello::testata('Numeri e report', 'fa-chart-column', "Il percorso delle scuole dall'iscrizione all'attestato, le valutazioni dei docenti e il report per il Ministero, per anno scolastico. Qui non c'è niente da fare: le convenzioni sono in «Scuole e convenzioni».",
            '<a class="btn btn-outline-secondary fw-bold" href="fsl_scuole.php"><i class="fa fa-school me-1" aria-hidden="true"></i>Scuole e convenzioni</a>');

        return Risposta::html(Vista::pannello('Numeri e report', $titoli . Vista::rendi('pannello/fsl_numeri', [
            'anni' => $anni, 'annoValore' => (string) $anno, 'dal' => $data($dal), 'al' => $data($al),
            'compitiHtml' => $compiti, 'percorsoHtml' => $percorso,
            'elenchi' => $elenchi, 'attivita' => $attivita, 'scuole' => $perScuola,
            'scaricaAttivita' => Pannello::scarica(self::url($anno, 'attivita')), 'scaricaScuole' => Pannello::scarica(self::url($anno, 'scuole')),
            'scaricaValutazioni' => Pannello::scarica(self::url($anno, 'valutazioni')), 'scaricaReport' => Pannello::scarica(self::url($anno, 'report')),
            'valutazioni' => $tabVal, 'aspetti' => $aspetti, 'rip' => [['testo' => 'Sì', 'n' => (int) $r1['rip']['si']], ['testo' => 'Forse', 'n' => (int) $r1['rip']['forse']], ['testo' => 'No', 'n' => (int) $r1['rip']['no']]],
            'intestazioniAperte' => array_values(Costanti::APERTE),
            'reportPercorsoHtml' => $reportPercorso, 'reportScuole' => $reportScuole,
        ])));
    }

    /** L'anno scolastico: ?anno=2026 (quello in corso se manca o non è ammesso; non oltre il prossimo). */
    private function anno(Richiesta $r): int
    {
        $corrente = $this->pannello->annoCorrente();
        $a = RimandiFsl::anno($r->query('anno'));
        $anno = $a === '' || $a === 'tutti' ? $corrente : (int) $a;

        return $anno < 2015 || $anno > $corrente + 1 ? $corrente : $anno;
    }

    private static function url(int $anno, string $tabella = ''): string
    {
        return 'fsl.php?anno=' . $anno . ($tabella !== '' ? '&tabella=' . $tabella : '');
    }

    /**
     * Le tre tabelle del riepilogo e delle valutazioni, in Excel, PDF o CSV.
     *
     * @param array<string, mixed> $r1 ServizioPannelloFsl::riepilogo()
     */
    private function scaricaTabella(string $formato, string $quale, array $r1, int $anno, \Closure $media): Risposta
    {
        $testo = $anno . '/' . ($anno + 1);
        $data = static fn (mixed $x): string => $x ? date('d/m/Y', (int) strtotime((string) $x)) : '—';
        if ($quale === 'attivita') {
            $tab = new TabellaEsportabile('Attività FSL ' . $testo, ['Attività', 'Area', 'Dal', 'Al', 'Scuole', 'Confermate', 'Studenti', 'Presenze', 'Senza convenzione', 'Valutazioni ricevute', 'Media'],
                array_map(static fn (array $a): array => [$a['titolo'], $a['area'], $data($a['dal']), $data($a['al']), count($a['scuole']), (int) ($a['confermate'] ?? 0), (int) ($a['studenti'] ?? 0), (int) ($a['presenze'] ?? 0), (int) ($a['conv_mancanti'] ?? 0), count($a['voti'] ?? []) . '/' . (int) ($a['val_inviate'] ?? 0), $media($a['voti'] ?? [])], array_values($r1['per_att'])),
                $this->piede($testo), [4, 2.5, 1.3, 1.3, 1, 1.2, 1.1, 1.1, 1.4, 1.4, 1]);
        } elseif ($quale === 'scuole') {
            $tab = new TabellaEsportabile('Scuole FSL ' . $testo, ['Scuola', 'Codice', 'Attività', 'Iscrizioni', 'Studenti', 'Presenze', 'Convenzione in registro', 'Da stipulare', 'Valutazione'],
                array_map(function (array $s) use ($media): array {
                    $ultima = !empty($s['codice']) ? ($this->convenzioni->dellaScuola((string) $s['codice'])[0] ?? null) : null;

                    return [$s['nome'], $s['codice'] ?? '', implode(', ', array_keys($s['attivita'])), (int) $s['iscrizioni'], (int) ($s['studenti'] ?? 0), (int) ($s['presenze'] ?? 0), $ultima ? PeriodiConvenzione::testoValidita($ultima) : 'nessuna', !empty($s['conv_mancanti']) ? 'Sì' : 'No', $media($s['voti'] ?? [])];
                }, array_values($r1['per_scuola'])), $this->piede($testo), [4, 1.8, 4, 1.1, 1.1, 1.1, 3, 1.2, 1.2]);
        } else {
            $si = ['si' => 'Sì', 'forse' => 'Forse', 'no' => 'No'];
            $tab = new TabellaEsportabile('Valutazioni della struttura ospitante ' . $testo, array_merge(['Data', 'Attività', 'Scuola', 'Compilata da', 'Media', 'Riproporrebbe'], array_values(Costanti::ASPETTI), array_values(Costanti::APERTE)),
                array_map(function (array $v) use ($data, $si): array {
                    $s = !empty($v['scuola_codice']) ? $this->scuole->perCodice((string) $v['scuola_codice']) : null;

                    return array_merge([$data($v['created_at']), $v['titolo'], $s ? Testi::etichettaScuola($s) : '', $v['compilata_da'], number_format((float) $v['media'], 1, ',', ''), $si[$v['ripeterebbe']] ?? ''],
                        array_map(static fn ($k): int => (int) ($v['risposte']['voti'][$k] ?? 0), array_keys(Costanti::ASPETTI)), array_map(static fn ($k): string => (string) ($v['risposte']['testi'][$k] ?? ''), array_keys(Costanti::APERTE)));
                }, $r1['valutazioni']), $this->piede($testo), []);
        }
        $nome = 'FSL_' . $quale . '_' . $anno . '_' . ($anno + 1);

        return $this->consegna($formato, $tab, $nome, $anno);
    }

    /** Il report dell'anno scolastico (ore per scuola, per attività e per studente): un Excel con quattro fogli, o gli stessi fogli in PDF o CSV. */
    private function scaricaReport(string $formato, string $dal, string $al, int $anno): Risposta
    {
        $testo = $anno . '/' . ($anno + 1);
        $this->registra('Report FSL esportato', ['Anno scolastico' => $testo, 'Formato' => $formato]);
        $file = $this->report->esporta($this->report->dati($dal, $al), $testo);
        if ($file === null || !is_file($file)) {
            $this->flash->imposta('Il report non si è potuto creare: su questo server manca il modulo per i file Excel.', 'warning');

            return Risposta::vai(self::url($anno) . '#report');
        }
        $nome = 'Report_FSL_' . $anno . '_' . ($anno + 1);
        if ($formato === 'xlsx') {
            return Risposta::file($file, $nome . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', true);
        }
        @unlink($file);
        $tabelle = [];
        foreach (Excel::ultimiFogli() as $titolo => $f) {
            $tabelle[] = new TabellaEsportabile((string) $titolo, (array) ($f['intestazioni'] ?? []), (array) ($f['righe'] ?? []), '', array_values((array) ($f['larghezze'] ?? [])));
        }

        return $this->consegna($formato, new FogliEsportabili('Report FSL ' . $testo, $tabelle), $nome, $anno);
    }

    private function consegna(string $formato, TabellaEsportabile|FogliEsportabili $tab, string $nome, int $anno): Risposta
    {
        if ($formato === 'csv') {
            return Risposta::scaricabile($tab->csv(), $nome . '.csv', 'text/csv; charset=utf-8');
        }
        if ($formato === 'pdf') {
            return Risposta::scaricabile($tab->pdf(), $nome . '.pdf', 'application/pdf');
        }
        $tmp = $tab->xlsx();
        if ($tmp === null) {
            $this->flash->imposta('Il file Excel non si è potuto creare: scarica il CSV, che si apre con Excel.', 'warning');

            return Risposta::vai(self::url($anno));
        }

        return Risposta::file($tmp, $nome . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', true);
    }

    private function piede(string $anno): string
    {
        return 'Formazione Scuola Lavoro, anno scolastico ' . $anno . ', stampato il ' . $this->orologio->adesso()->format('d/m/Y');
    }

    /** @param array<string, mixed> $dettagli */
    private function registra(string $azione, array $dettagli): void
    {
        $uid = (int) $this->sessione->leggi('utente_id');
        if ($uid > 0) {
            $this->audit->registra($uid, $azione, $dettagli, $this->indirizzo->ip() ?? 'Sconosciuto');
        }
    }
}
