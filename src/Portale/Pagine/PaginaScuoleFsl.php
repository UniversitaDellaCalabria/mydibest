<?php

declare(strict_types=1);

namespace App\Portale\Pagine;

use App\Auth\Csrf;
use App\Auth\Flash;
use App\Auth\Sessione;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Fsl\ConvenzioneRepository;
use App\Fsl\ElencoScuoleFsl;
use App\Fsl\PassiConvenzione;
use App\Fsl\PdfSchedaScuola;
use App\Fsl\SchedaScuolaFsl;
use App\Fsl\ServizioConvenzioneOnline;
use App\Fsl\StatoConvenzioneScuola;
use App\Http\Pagina;
use App\Http\Richiesta;
use App\Http\Risposta;
use App\Http\Vista;
use App\Infrastructure\Audit\AuditLog;
use App\Infrastructure\Documenti\TabellaEsportabile;
use App\Portale\Pannello\ContestoPannello;
use App\Portale\Vista\Pannello;

/**
 * FSL › Scuole (admin/fsl_scuole.php): l'elenco di tutte le scuole con la convenzione e, con ?k=CODICE, la scheda di UNA scuola con tutto in una pagina
 * (convenzione e iter, eventi e progetti di tutte le sedi, docenti referenti, messaggi, documenti, anagrafe). Da qui l'ufficio avvia la stipula anche per
 * una scuola senza prenotazioni, e fa le azioni sulla convenzione senza cambiare pagina (AzioniScuolaFsl: le stesse di admin/fsl.php).
 *
 * L'accesso (chi ha almeno le convenzioni) lo decide il Nucleo (PermessiPagine, `fsl_scuole.php`) PRIMA di costruire la classe; il token CSRF di ogni POST
 * pure. Chi ha SOLO le convenzioni (non `puo_fsl`) non vede numeri di studenti né valutazioni: non glieli prepariamo nemmeno.
 * I collegamenti agli studenti e alle iscrizioni (partecipanti.php, iscritti.php) compaiono solo a chi quelle pagine fanno entrare davvero:
 * si chiede, una volta per area, allo stesso calcolo dei permessi che usano loro (ContestoPannello). I loro controlli non cambiano.
 * Il disegno è in viste/pannello/fsl_scuole_elenco.php e fsl_scuole_scheda.php.
 */
final class PaginaScuoleFsl implements Pagina
{
    public function __construct(
        private readonly ElencoScuoleFsl $elenco,
        private readonly SchedaScuolaFsl $scheda,
        private readonly ServizioConvenzioneOnline $online,
        private readonly AzioniScuolaFsl $azioni,
        private readonly PdfSchedaScuola $pdf,
        private readonly ContestoPannello $contesto,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly Sessione $sessione,
        private readonly AuditLog $audit,
        private readonly IndirizzoClient $indirizzo,
        private readonly Orologio $orologio,
        private readonly \App\Anagrafi\ServizioScuole $scuole,
        private readonly RimandiFsl $rimandi,
    ) {
    }

    public function __invoke(Richiesta $r): Risposta
    {
        $permessi = $this->contesto->permessi($r);
        $puoFsl = $permessi !== null && $permessi->fsl();
        $chiave = trim((string) $r->query('k', ''));
        $anno = $this->anno($r);
        if ($r->eUnPost()) {
            return $this->post($r, trim((string) ($r->post('k') ?? $chiave)), $anno, $puoFsl);
        }
        if ($r->query('cv') !== null) {
            return $this->dallaCompilazione((int) $r->query('cv'));
        }

        return $chiave !== '' ? $this->paginaScheda($r, $chiave, $anno, $puoFsl, null) : $this->paginaElenco($r, $anno, $puoFsl);
    }

    /**
     * `?cv=ID`: l'indirizzo che si dà a chi ha solo l'id di una compilazione online (gli indirizzi vecchi `#cv12` di admin/fsl.php, le email). Porta alla scheda
     * della scuola di quella compilazione, ai messaggi; se la compilazione non c'è o la sua scuola non è in anagrafe, è un 404 (con i permessi di sempre: li ha
     * già verificati il Nucleo).
     */
    private function dallaCompilazione(int $id): Risposta
    {
        $k = $this->rimandi->chiaveDellaCompilazione($id);
        if ($k === '') {
            return Risposta::html(Vista::pannello('Scuole', Pannello::indietro(self::url(), 'Tutte le scuole')
                . Pannello::vuoto("Questa convenzione non c'è, oppure la sua scuola non è nell'anagrafe: cerca la scuola dall'elenco.", 'fa-school')), 404);
        }

        return Risposta::vai(self::url($k) . '#messaggi');
    }

    /** L'anno scolastico scelto: ?anno=2026, «tutti» (0), o quello di oggi se non è indicato. */
    private function anno(Richiesta $r): int
    {
        $a = $r->query('anno');
        if ($a === null || $a === '') {
            return $this->elenco->annoCorrente();
        }

        return $a === 'tutti' ? 0 : max(0, min(2100, (int) $a));
    }

    private static function url(string $chiave = '', int $anno = -1, string $extra = ''): string
    {
        $q = [];
        if ($chiave !== '') {
            $q['k'] = $chiave;
        }
        if ($anno >= 0) {
            $q['anno'] = $anno === 0 ? 'tutti' : (string) $anno;
        }

        return 'fsl_scuole.php' . ($q ? '?' . http_build_query($q) : '') . $extra;
    }

    // ───────────────────────────── Elenco ─────────────────────────────

    private function paginaElenco(Richiesta $r, int $anno, bool $puoFsl): Risposta
    {
        $stato = (string) $r->query('stato', '');
        $cosa = (string) $r->query('cosa', '');
        $testo = trim((string) $r->query('q', ''));
        $stato = isset(StatoConvenzioneScuola::STATI[$stato]) ? $stato : '';
        $cosa = isset(ElencoScuoleFsl::COSE[$cosa]) && ($puoFsl || !in_array($cosa, StatoConvenzioneScuola::SOLO_FSL, true)) ? $cosa : '';

        $tutte = $this->elenco->righe($anno, $puoFsl);
        $righe = ElencoScuoleFsl::filtra($tutte, $stato, $cosa, $testo);
        $formato = (string) $r->query('scarica', '');
        if ($formato !== '') {
            return $this->esporta($formato, $righe, $anno, $puoFsl);
        }
        $pagine = max(1, (int) ceil(count($righe) / ElencoScuoleFsl::PER_PAGINA));
        $pag = max(1, min($pagine, (int) $r->query('pag', 1)));
        $visibili = array_slice($righe, ($pag - 1) * ElencoScuoleFsl::PER_PAGINA, ElencoScuoleFsl::PER_PAGINA);
        $conteggi = ElencoScuoleFsl::tessere($tutte);
        $base = ['anno' => $anno === 0 ? 'tutti' : (string) $anno];
        $filtri = array_filter(['stato' => $stato, 'cosa' => $cosa, 'q' => $testo]);
        $link = static fn (array $q): string => 'fsl_scuole.php?' . http_build_query(array_filter($q, static fn ($v): bool => $v !== '' && $v !== null));
        $tessere = [];
        foreach (ElencoScuoleFsl::COSE as $k => $nome) {
            if (!$puoFsl && in_array($k, StatoConvenzioneScuola::SOLO_FSL, true)) {
                continue;   // chi ha solo le convenzioni non vede i documenti degli studenti
            }
            $tessere[] = ['chiave' => $k, 'nome' => $nome, 'numero' => (int) $conteggi[$k], 'attiva' => $cosa === $k, 'href' => $link($base + ['cosa' => $cosa === $k ? '' : $k, 'stato' => $stato, 'q' => $testo])];
        }
        $anni = [['valore' => 'tutti', 'testo' => 'Tutti gli anni', 'scelto' => $anno === 0]];
        foreach ($this->elenco->anni() as $a) {
            $anni[] = ['valore' => (string) $a, 'testo' => 'A.s. ' . $a . '/' . substr((string) ($a + 1), 2), 'scelto' => $anno === $a];
        }
        $stati = [['valore' => '', 'testo' => 'Tutti gli stati', 'scelto' => $stato === '']];
        foreach (StatoConvenzioneScuola::STATI as $k => $nome) {
            $stati[] = ['valore' => $k, 'testo' => $nome, 'scelto' => $stato === $k];
        }

        return Risposta::html(Vista::pannello('Scuole', Vista::rendi('pannello/fsl_scuole_elenco', [
            'campoCsrf' => $this->csrf->campo(),
            'puoFsl' => $puoFsl,
            'righe' => array_map(fn (array $x): array => $this->rigaElenco($x, $anno, $puoFsl), $visibili),
            'tessere' => $tessere, 'anni' => $anni, 'stati' => $stati, 'filtroTesto' => $testo, 'annoValore' => $anno === 0 ? 'tutti' : (string) $anno,
            'totale' => count($righe), 'totaleScuole' => count($tutte), 'filtrato' => $filtri !== [],
            'pag' => $pag, 'pagine' => $pagine,
            'hrefPrec' => $pag > 1 ? $link($base + $filtri + ['pag' => (string) ($pag - 1)]) : '',
            'hrefSucc' => $pag < $pagine ? $link($base + $filtri + ['pag' => (string) ($pag + 1)]) : '',
            'hrefScarica' => $link($base + $filtri),
            'hrefTutti' => $link($base),
            'aMano' => count(array_filter($tutte, static fn (array $x): bool => $x['a_mano'])),
            'apriScuola' => $r->query('nuova') !== null && $r->query('nuova') !== '',
        ])));
    }

    /** @param array<string, mixed> $x una riga di ElencoScuoleFsl::righe() @return array<string, mixed> */
    private function rigaElenco(array $x, int $anno, bool $puoFsl): array
    {
        $tonoStato = ['attiva' => 'ok', 'in_scadenza' => 'attesa', 'scaduta' => 'blocco', 'in_corso' => 'lavoro', 'futura' => 'info', 'manca' => 'blocco'][$x['stato']] ?? 'info';
        $data = $x['scadenza'] !== '' ? ($x['stato'] === 'futura' ? 'dal ' : ($x['stato'] === 'scaduta' ? 'scaduta il ' : 'fino al ')) . date('d/m/Y', (int) strtotime((string) $x['scadenza'])) : ($x['stato'] === 'attiva' ? 'senza scadenza' : '');

        return [
            'nome' => $x['nome'], 'comune' => $x['comune'], 'codice' => $x['codice'], 'aMano' => $x['a_mano'],
            'href' => self::url((string) $x['chiave'], $anno),
            'statoHtml' => Pannello::stato($x['stato_testo'], $tonoStato),
            'data' => $data, 'passo' => $x['passo'],
            'eventi' => (int) $x['eventi'], 'studenti' => $puoFsl ? (int) $x['studenti'] : null, 'docenti' => (int) $x['docenti'], 'docentiNomi' => $x['docenti_nomi'], 'docentiAltri' => (int) $x['docenti_altri'],
            'cosa' => $x['cosa'], 'daFare' => $x['da_fare'],
        ];
    }

    /** @param list<array<string, mixed>> $righe */
    private function esporta(string $formato, array $righe, int $anno, bool $puoFsl): Risposta
    {
        if (!isset(TabellaEsportabile::FORMATI[$formato])) {
            $this->flash->imposta('Formato non disponibile.', 'danger');

            return Risposta::vai(self::url('', $anno));
        }
        if ($formato === 'xlsx' && !class_exists(\ZipArchive::class)) {
            $this->flash->imposta('Su questo server il file Excel non si può creare: scarica il CSV, che si apre con Excel.', 'warning');

            return Risposta::vai(self::url('', $anno));
        }
        $intest = array_merge(['Scuola', 'Comune', 'Codice', 'Convenzione', 'Validità'], ['Eventi'], $puoFsl ? ['Studenti'] : [], ['Docenti', 'Prossima cosa da fare']);
        $dati = [];
        foreach ($righe as $x) {
            $dati[] = array_merge([$x['nome'], $x['comune'], $x['codice'], $x['stato_testo'] . ($x['passo'] !== '' ? ' – ' . $x['passo'] : ''),
                $x['scadenza'] !== '' ? date('d/m/Y', (int) strtotime((string) $x['scadenza'])) : ''], [(int) $x['eventi']], $puoFsl ? [(int) $x['studenti']] : [], [(int) $x['docenti'], $x['cosa']]);
        }
        $data = $this->orologio->adesso()->format('d/m/Y');
        $tab = new TabellaEsportabile('Scuole e convenzioni FSL', $intest, $dati, count($righe) . ' scuole · ' . ($anno === 0 ? 'tutti gli anni' : 'anno scolastico ' . $anno . '/' . substr((string) ($anno + 1), 2)) . ' · stampato il ' . $data);
        $nome = 'Scuole_FSL_' . $this->orologio->adesso()->format('Y-m-d');
        $this->registra('Elenco delle scuole FSL esportato', ['Formato' => $formato, 'Righe' => count($righe)]);
        if ($formato === 'csv') {
            return Risposta::scaricabile($tab->csv(), $nome . '.csv', 'text/csv; charset=utf-8');
        }
        if ($formato === 'pdf') {
            return Risposta::scaricabile($tab->pdf(), $nome . '.pdf', 'application/pdf');
        }
        $tmp = $tab->xlsx();
        if ($tmp === null) {
            $this->flash->imposta('Il file Excel non si è potuto creare: scarica il CSV.', 'warning');

            return Risposta::vai(self::url('', $anno));
        }

        return Risposta::file($tmp, $nome . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', true);
    }

    // ───────────────────────────── Scheda ─────────────────────────────

    /** @param array<string, mixed>|null $anteprima l'anteprima dell'avvio della stipula, se l'ufficio ha premuto «Controlla» */
    private function paginaScheda(Richiesta $r, string $chiave, int $anno, bool $puoFsl, ?array $anteprima, ?string $erroreAvvio = null): Risposta
    {
        $dati = $this->scheda->scheda($chiave, $anno, $puoFsl, fn (int $area): bool => $this->puoGestireIscritti($area));
        if ($dati === null) {
            return Risposta::html(Vista::pannello('Scuole', Pannello::indietro(self::url('', $anno), 'Tutte le scuole')
                . Pannello::vuoto("Questa scuola non è nell'anagrafe e non ha prenotazioni né convenzioni (oppure il collegamento non è più valido).", 'fa-school')), 404);
        }
        if ($chiave !== $dati['chiave'] && $r->query('pdf') === null) {
            return Risposta::vai(self::url($dati['chiave'], $anno));   // un vecchio indirizzo col codice di una sede porta alla scuola (l'istituto)
        }
        if ($r->query('pdf') !== null) {
            $this->registra('Scheda della scuola scaricata in PDF', ['Scuola' => $dati['chiave']]);

            return Risposta::scaricabile($this->pdf->pdf($dati, $this->orologio->adesso()->format('d/m/Y')), 'Scheda_scuola_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string) $dati['chiave']) . '.pdf', 'application/pdf');
        }

        $docentiConEmail = array_values(array_filter($dati['docenti'], static fn (array $d): bool => $d['email'] !== ''));
        $oggi = $this->orologio->adesso()->format('Y-m-d');
        $iter = $dati['iter'];
        $dati['iter_aperti'] = $this->conPassi($dati['iter_aperti']);
        $tonoStato = ['attiva' => 'ok', 'in_scadenza' => 'attesa', 'scaduta' => 'blocco', 'in_corso' => 'lavoro', 'futura' => 'info', 'manca' => 'blocco'][$dati['stato']] ?? 'info';
        [$apri, $periodoNuovo] = $this->moduloAperto($r, $dati);

        return Risposta::html(Vista::pannello($dati['nome'], Vista::rendi('pannello/fsl_scuole_scheda', [
            'campoCsrf' => $this->csrf->campo(),
            'puoFsl' => $puoFsl,
            's' => $dati,
            'chiaveUrl' => urlencode((string) $dati['chiave']),
            'annoValore' => $anno === 0 ? 'tutti' : (string) $anno,
            'hrefElenco' => self::url('', $anno),
            'hrefPdf' => self::url((string) $dati['chiave'], $anno, '&pdf=1'),
            'statoHtml' => Pannello::stato($dati['stato_testo'], $tonoStato),
            'docentiConEmail' => $docentiConEmail,
            'anteprima' => $anteprima,
            'periodoNuovo' => $periodoNuovo,
            'apri' => $apri,
            'erroreAvvio' => $erroreAvvio ?? '',
            'anni' => array_merge([['valore' => 'tutti', 'testo' => 'Tutti gli anni', 'scelto' => $anno === 0]], array_map(static fn (int $a): array => ['valore' => (string) $a, 'testo' => 'A.s. ' . $a . '/' . substr((string) ($a + 1), 2), 'scelto' => $anno === $a], array_values(array_unique(array_merge($dati['anni'], $anno > 0 ? [$anno] : []))))),
            'oggi' => $oggi,
            'iterId' => $iter ? (int) $iter['id'] : 0,
        ])));
    }

    /**
     * Quale modulo della convenzione si apre già compilato (dagli indirizzi dei vecchi «Modifica», «Registra» e «Rinnova» di admin/fsl.php): `modifica=ID` apre la
     * modifica di quella convenzione, `rinnova=ID` il rinnovo, `nuova=1` «Registrane una già firmata»; `dal` e `al` (solo date vere) sono le date proposte. Un id
     * che non è di questa scuola non apre niente; un rinnovo non possibile apre «Registrane una già firmata» con le stesse date.
     *
     * @param array<string, mixed> $dati la scheda
     * @return array{0: array{modifica: int, nuova: bool, rinnova: int}, 1: array{dal: string, al: string}}
     */
    private function moduloAperto(Richiesta $r, array $dati): array
    {
        $registro = array_column($dati['registro'], null, 'id');
        $modifica = (int) $r->query('modifica', 0);
        $modifica = isset($registro[$modifica]) ? $modifica : 0;
        $rinnova = (int) $r->query('rinnova', 0);
        $primo = $dati['registro'][0] ?? null;
        $puoRinnovare = $primo !== null && !$dati['a_mano'] && in_array($dati['stato'], ['in_scadenza', 'scaduta'], true);
        $nuova = $r->query('nuova') !== null && $r->query('nuova') !== '';
        if ($rinnova > 0 && !($puoRinnovare && (int) $primo['id'] === $rinnova)) {
            $nuova = true;
            $rinnova = 0;
        }
        $data = static fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false ? $v : null;
        $periodo = $dati['periodo_nuovo'];
        $periodo = ['dal' => $data($r->query('dal')) ?? (string) $periodo['dal'], 'al' => $data($r->query('al')) ?? (string) $periodo['al']];

        return [['modifica' => $modifica, 'nuova' => $nuova && !$dati['a_mano'], 'rinnova' => $rinnova], $periodo];
    }

    /**
     * I cinque passi dell'iter (gli stessi della pagina della scuola) per ogni compilazione in corso. La vista si disegna PRIMA della testata del pannello, che è
     * dove di solito si carica inc/ui.php: il disegno dei passi si fa qui e la vista lo stampa com'è.
     *
     * @param list<array<string, mixed>> $aperte
     * @return list<array<string, mixed>>
     */
    private function conPassi(array $aperte): array
    {
        if (!function_exists('ui_passi')) {
            require_once dirname(__DIR__, 3) . '/inc/ui.php';
        }
        foreach ($aperte as $i => $c) {
            $haFile = false;
            foreach ($c['files'] as $f) {
                $haFile = $haFile || $f['quale'] === 'scuola';
            }
            $aperte[$i]['passi_html'] = ui_passi(PassiConvenzione::passi(), PassiConvenzione::corrente((string) $c['stato_iter'], $haFile), 'piccolo', 'Dove si trova la convenzione');
        }

        return $aperte;
    }

    /** Chi può aprire iscritti.php e partecipanti.php di quell'area? Lo stesso calcolo dei permessi della pagina di destinazione (can_manage_iscritti). */
    private function puoGestireIscritti(int $area): bool
    {
        $finta = Richiesta::finta('GET', '/admin/partecipanti.php', ['p_id' => (string) $area]);
        $permessi = $this->contesto->permessi($finta);

        return $permessi !== null && $this->contesto->area($finta) === $area && $permessi->gestisceIscritti();
    }

    // ───────────────────────────── POST ─────────────────────────────

    private function post(Richiesta $r, string $chiave, int $anno, bool $puoFsl): Risposta
    {
        $codice = ConvenzioneRepository::codiceValido($chiave);
        $istituto = $codice !== null ? ($this->scuole->codiceIstituto($codice) ?: $codice) : '';
        if ($r->post('avvia_anteprima') !== null) {
            return $this->anteprima($r, $chiave, $istituto, $anno, $puoFsl);
        }
        $utente = $this->contesto->utente($r);
        $operatore = [
            'id' => (int) $this->sessione->leggi('utente_id'),
            'email' => (string) ($this->sessione->leggi('utente_email') ?? ($utente['email'] ?? '')),
            'nome' => (string) ($this->sessione->leggi('utente_nome') ?? trim(($utente['nome'] ?? '') . ' ' . ($utente['cognome'] ?? ''))),
        ];
        $esito = $this->azioni->esegui($r, $chiave, $istituto, $puoFsl, $operatore);
        if ($esito === null) {
            return Risposta::vai(self::url($chiave, $anno));
        }
        $this->flash->imposta($esito['messaggio'], $esito['tono']);
        if ($esito['audit'] !== null) {
            $this->registra($esito['audit'][0], $esito['audit'][1]);
        }

        return Risposta::vai(self::url($chiave, $anno, $chiave !== '' && $esito['ancora'] !== '' ? '#' . $esito['ancora'] : ''));
    }

    /** «Controlla»: cosa succederebbe avviando la stipula per questo referente. Non scrive niente e non manda nessuna email. */
    private function anteprima(Richiesta $r, string $chiave, string $istituto, int $anno, bool $puoFsl): Risposta
    {
        $scelto = (string) $r->post('referente', '');
        $email = $scelto === '' || $scelto === '__altra' ? (string) $r->post('altra_email', '') : $scelto;
        $ant = $istituto !== '' ? $this->online->anteprimaAvvio($istituto, $email) : ['errore' => "Questa scuola non ha un codice meccanografico: abbinala all'anagrafe."];
        if ($ant['errore'] !== null) {
            return $this->paginaScheda($r, $chiave, $anno, $puoFsl, null, (string) $ant['errore']);
        }

        return $this->paginaScheda($r, $chiave, $anno, $puoFsl, $ant);
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
