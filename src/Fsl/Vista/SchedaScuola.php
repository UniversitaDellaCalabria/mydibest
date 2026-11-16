<?php

declare(strict_types=1);

namespace App\Fsl\Vista;

use App\Portale\Vista\Pannello;

/** HTML della scheda completa di una scuola (pannello FSL): i dati vengono da ServizioSchedaScuola. */
final class SchedaScuola
{
    private const STATI_CONVENZIONE = [
        'valida' => ['In vigore', 'ok'], 'in_scadenza' => ['In scadenza', 'attesa'], 'scaduta' => ['Scaduta', 'spento'], 'futura' => ['Non ancora valida', 'info'],
    ];
    private const STATI_PRENOTAZIONE = [
        'confermata' => ['Confermata', 'ok'], 'da_approvare' => ['Da approvare', 'attesa'], 'in_attesa' => ["Lista d'attesa", 'attesa'], 'richiesta_conferma' => ['Posto offerto', 'info'],
        'annullata' => ['Annullata', 'spento'], 'rifiutata' => ['Rifiutata', 'spento'], 'scaduta' => ['Scaduta', 'spento'],
    ];

    /**
     * @param array<string, mixed> $s risultato di ServizioSchedaScuola::scheda()
     * @param callable(string, string): string $urlFsl indirizzo di una scheda del pannello: ($scheda, '&altro=1')
     */
    public static function html(array $s, callable $urlFsl): string
    {
        return self::testata($s) . self::corpo($s, $urlFsl);
    }

    /**
     * Nome, contatti, stato della convenzione, numeri e avvisi.
     *
     * @param array<string, mixed> $s
     */
    public static function testata(array $s): string
    {
        $h = static fn ($x): string => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        $a = $s['anagrafe'];
        $sint = $s['sintesi'];
        $tonoSintesi = ['ok' => 'ok', 'da_stipulare' => 'blocco', 'scaduta' => 'blocco', 'nessuna' => 'spento'][$sint['stato']] ?? 'info';

        $o = '<div class="d-flex flex-wrap align-items-start gap-2 mb-3"><div class="flex-grow-1">'
            . '<h2 class="h4 fw-bold mb-0" style="text-transform:none;letter-spacing:0;font-size:1.3rem;">' . $h($s['nome']) . '</h2>'
            . '<div class="small text-secondary"><span class="font-monospace">' . $h($s['codice']) . '</span>'
            . ($a ? ' · ' . $h(trim($a['comune'] . (!empty($a['provincia']) ? ' (' . $a['provincia'] . ')' : ''))) . (!empty($a['tipo']) ? ' · ' . $h($a['tipo']) : '') : ' · non è nell\'anagrafe del Ministero')
            . '</div>'
            . ($a ? '<div class="small text-secondary">' . self::contatti($a, $h) . '</div>' : '')
            . '</div>' . Pannello::stato($sint['testo'], $tonoSintesi) . '</div>';

        $o .= '<div class="row g-2 mb-3 text-center">';
        foreach ([['prenotazioni', 'prenotazioni in tutto'], ['attive', 'attive e non concluse'], ['studenti', 'studenti previsti'], ['convenzioni', 'convenzioni nel registro']] as [$chiave, $etichetta]) {
            $o .= '<div class="col-6 col-md-3"><div class="border rounded-3 py-2"><div class="fs-4 fw-bold">' . (int) $sint[$chiave] . '</div><div class="small text-secondary">' . $h($etichetta) . '</div></div></div>';
        }
        $o .= '</div>';

        foreach ($s['avvisi'] as $v) {
            $classe = ['blocco' => 'alert-danger', 'attesa' => 'alert-warning', 'info' => 'alert-info'][$v['tono']] ?? 'alert-secondary';
            $o .= '<div class="alert ' . $classe . ' small py-2"><i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>' . $h($v['testo']) . '</div>';
        }

        return $o;
    }

    /**
     * Prenotazioni, documenti, persone, convenzioni e Allegati A.
     *
     * @param array<string, mixed> $s
     * @param callable(string, string): string $urlFsl
     */
    public static function corpo(array $s, callable $urlFsl): string
    {
        $h = static fn ($x): string => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        $d = static fn (?string $x): string => $x ? date('d/m/Y', (int) strtotime($x)) : '—';
        $k = urlencode($s['codice']);

        return '<div class="row g-3"><div class="col-lg-8">' . self::prenotazioni($s['prenotazioni'], $h, $d) . '</div>'
            . '<div class="col-lg-4">' . self::documenti($s, $k, $urlFsl, $h) . self::referenti($s['referenti'], $h) . '</div></div>'
            . '<div class="mt-3">' . self::convenzioni($s, $urlFsl, $h, $d) . '</div>';
    }

    /** @param array<string, mixed> $a */
    private static function contatti(array $a, callable $h): string
    {
        $p = [];
        if (!empty($a['indirizzo'])) {
            $p[] = $h(trim($a['indirizzo'] . (!empty($a['cap']) ? ' – ' . $a['cap'] : '')));
        }
        foreach (['email' => 'Email', 'pec' => 'PEC'] as $c => $et) {
            if (!empty($a[$c])) {
                $p[] = $et . ' <a href="mailto:' . $h($a[$c]) . '">' . $h($a[$c]) . '</a>';
            }
        }

        return implode(' · ', $p);
    }

    /**
     * @param list<array<string, mixed>> $righe
     */
    private static function prenotazioni(array $righe, callable $h, callable $d): string
    {
        $o = '<div class="card border-0 shadow-sm"><div class="card-body"><h3 class="h6 fw-bold mb-2"><i class="fa fa-calendar-check me-1" aria-hidden="true"></i>Tutte le prenotazioni (' . count($righe) . ')</h3>';
        if (!$righe) {
            return $o . '<p class="small text-secondary mb-0">Questa scuola non ha prenotazioni.</p></div></div>';
        }
        $o .= '<div class="table-responsive"><table class="table table-sm align-middle small mb-0"><thead class="table-light"><tr><th>Attività</th><th>Periodo</th><th class="text-center">Studenti</th><th>Docente</th><th>Stato</th><th>Convenzione</th><th></th></tr></thead><tbody>';
        foreach ($righe as $p) {
            [$testo, $tono] = self::STATI_PRENOTAZIONE[$p['stato']] ?? [$p['stato'], 'spento'];
            $conv = match (true) {
                $p['fsl_spenta'] => Pannello::stato('FSL spenta sul progetto', 'blocco'),
                $p['conv'] === 'coperta' => Pannello::stato('Coperta dalla n. ' . $p['conv_id'], 'ok'),
                $p['conv'] === 'non_coperta' => Pannello::stato($p['conclusa'] ? 'Non coperta' : 'Da stipulare', 'blocco'),
                $p['solo_orientamento'] => Pannello::stato('Solo orientamento', 'spento'),
                default => '<span class="text-secondary">Non richiesta</span>',
            };
            $periodo = $p['dal'] === $p['al'] ? $d($p['dal']) : $d($p['dal']) . ' – ' . $d($p['al']);
            $o .= '<tr' . ($p['attiva'] ? '' : ' class="text-secondary"') . '><td><strong>' . $h($p['titolo']) . '</strong><div class="text-secondary">' . $h($p['area'])
                . ($p['turno'] !== '' ? ' · ' . $h($p['turno']) : '') . ' · <span class="font-monospace">' . $h($p['codice']) . '</span></div></td>'
                . '<td class="text-nowrap">' . $periodo . ($p['conclusa'] && $p['attiva'] ? '<div class="text-secondary">conclusa</div>' : '') . '</td>'
                . '<td class="text-center">' . ($p['studenti'] ?: '—') . ($p['elenco'] ? '<div class="text-secondary">elenco: ' . (int) $p['elenco'] . '</div>' : '') . '</td>'
                . '<td>' . $h($p['docente']) . '<div class="text-secondary">' . $h($p['email']) . '</div></td>'
                . '<td>' . Pannello::stato($testo, $tono) . ($p['presente'] ? '<div class="text-secondary mt-1">presente</div>' : '') . '</td><td>' . $conv . '</td>'
                . '<td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary fw-bold py-0" href="iscritti.php?p_id=' . (int) $p['pagina_id'] . '&amp;f_cerca=' . urlencode($p['codice']) . '">Iscrizioni</a>'
                . ($p['fsl'] ? ' <a class="btn btn-sm btn-outline-secondary fw-bold py-0" href="partecipanti.php?p_id=' . (int) $p['pagina_id'] . '&amp;pr=' . (int) $p['id'] . '">Studenti</a>' : '')
                . ($p['fsl_spenta'] ? ' <a class="btn btn-sm btn-outline-danger fw-bold py-0" href="progetti.php?p_id=' . (int) $p['pagina_id'] . '&amp;id=' . (int) $p['evento_id'] . '">Apri il progetto</a>' : '')
                . '</td></tr>';
        }

        return $o . '</tbody></table></div></div></div>';
    }

    /** @param array<string, mixed> $s */
    private static function documenti(array $s, string $k, callable $urlFsl, callable $h): string
    {
        $o = '<div class="card border-0 shadow-sm mb-3" id="documentiScuola"><div class="card-body"><h3 class="h6 fw-bold mb-2"><i class="fa fa-file-lines me-1" aria-hidden="true"></i>Documenti già compilati</h3>';
        if ($s['sintesi']['documenti']) {
            $o .= '<p class="small text-secondary">Con i dati dell\'anagrafe e delle prenotazioni FSL in corso. Il logo della scuola non è incluso, a meno che l\'abbia caricato la scuola.</p><div class="d-grid gap-2">'
                . '<a href="allegato_a.php?k=' . $k . '&amp;doc=allegato_pdf" class="btn btn-danger fw-bold"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Allegato A (PDF)</a>'
                . '<a href="allegato_a.php?k=' . $k . '&amp;doc=allegato" class="btn btn-outline-primary fw-bold"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Allegato A (Word)</a>'
                . '<a href="allegato_a.php?k=' . $k . '&amp;doc=convenzione" class="btn btn-outline-primary fw-bold"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Convenzione (Word)</a></div>';
        } else {
            $o .= '<p class="small text-secondary mb-0">Nessuna prenotazione FSL in corso: l\'Allegato A e la convenzione precompilati si generano solo per le attività da svolgere.</p>';
        }

        return $o . '<a href="' . $h($urlFsl('convenzioni', '&conv_nuova=' . $k) . '#convForm') . '" class="btn btn-sm btn-outline-danger fw-bold mt-3 w-100">Registra una convenzione</a></div></div>';
    }

    /** @param list<array{nome: string, email: string, fonte: string, prenotazioni: int}> $righe */
    private static function referenti(array $righe, callable $h): string
    {
        $o = '<div class="card border-0 shadow-sm"><div class="card-body"><h3 class="h6 fw-bold mb-2"><i class="fa fa-user-group me-1" aria-hidden="true"></i>Persone della scuola (' . count($righe) . ')</h3>';
        if (!$righe) {
            return $o . '<p class="small text-secondary mb-0">Nessun referente indicato.</p></div></div>';
        }
        foreach ($righe as $r) {
            $o .= '<div class="small border-top pt-2 mt-2"><strong>' . $h($r['nome'] !== '' ? $r['nome'] : $r['email']) . '</strong>'
                . ($r['email'] !== '' && $r['nome'] !== '' ? '<div><a href="mailto:' . $h($r['email']) . '">' . $h($r['email']) . '</a></div>' : '')
                . '<div class="text-secondary">' . $h($r['fonte']) . ($r['prenotazioni'] ? ' · ' . $r['prenotazioni'] . ($r['prenotazioni'] === 1 ? ' prenotazione' : ' prenotazioni') : '') . '</div></div>';
        }

        return $o . '</div></div>';
    }

    /** @param array<string, mixed> $s */
    private static function convenzioni(array $s, callable $urlFsl, callable $h, callable $d): string
    {
        $o = '<div class="card border-0 shadow-sm"><div class="card-body"><h3 class="h6 fw-bold mb-2"><i class="fa fa-file-signature me-1" aria-hidden="true"></i>Convenzioni e Allegati A (' . count($s['convenzioni']) . ')</h3>';
        if (!$s['convenzioni']) {
            $o .= '<p class="small text-secondary mb-2">Nessuna convenzione nel registro per questa scuola.</p>';
        }
        foreach ($s['convenzioni'] as $c) {
            [$testo, $tono] = self::STATI_CONVENZIONE[$c['stato']] ?? [$c['stato'], 'info'];
            $o .= '<div class="small border-top pt-2 mt-2"><div class="d-flex flex-wrap gap-2 align-items-center"><strong>n. ' . (int) $c['id'] . '</strong> ' . $h($c['validita']) . ' ' . Pannello::stato($testo, $tono)
                . ($c['protocollo'] !== '' ? '<span class="text-secondary">protocollo ' . $h($c['protocollo']) . '</span>' : '') . '</div>';
            $o .= '<div class="mt-1 d-flex flex-wrap gap-3">'
                . ($c['file'] ? '<a href="convenzione_file.php?id=' . (int) $c['id'] . '&amp;f=conv" target="_blank"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Convenzione</a>' : '<span class="text-danger">convenzione mancante</span>')
                . ($c['allegato'] ? '<a href="convenzione_file.php?id=' . (int) $c['id'] . '&amp;f=all" target="_blank"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Allegato A</a>' : '<span class="text-danger">Allegato A mancante</span>')
                . '<a href="' . $h($urlFsl('convenzioni', '&conv_mod=' . (int) $c['id']) . '#convForm') . '">Modifica</a></div>';
            if ($c['docenti']) {
                $o .= '<div class="text-secondary mt-1">Docenti di riferimento: ' . $h(implode(', ', array_map(static fn (array $x): string => $x['nome'] . ($x['email'] !== '' ? ' (' . $x['email'] . ')' : ''), $c['docenti']))) . '</div>';
            }
            if ($c['note'] !== '') {
                $o .= '<div class="text-secondary mt-1">' . $h($c['note']) . '</div>';
            }
            $o .= '</div>';
        }
        if ($s['compilazioni']) {
            $o .= '<h4 class="h6 fw-bold mt-4 mb-1">Compilate online dalla scuola</h4><p class="small text-secondary mb-1">Le convenzioni che la scuola ha compilato dal modulo online, anche se non sono ancora nel registro.</p>';
            foreach ($s['compilazioni'] as $c) {
                $o .= '<div class="small border-top pt-2 mt-2"><div class="d-flex flex-wrap gap-2 align-items-center"><strong>' . $h($c['stato']) . '</strong>'
                    . '<span class="text-secondary">' . ($c['aggiornata_il'] !== '' ? 'aggiornata il ' . $d($c['aggiornata_il']) : '') . ($c['email'] !== '' ? ' · ' . $h($c['email']) : '') . '</span></div><div class="mt-1 d-flex flex-wrap gap-3">'
                    . ($c['file_scuola'] ? '<a href="convenzione_file.php?c=' . (int) $c['id'] . '" target="_blank"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>PDF firmato dalla scuola</a>' : '')
                    . ($c['allegato_scuola'] ? '<a href="convenzione_file.php?c=' . (int) $c['id'] . '&amp;f=allegato" target="_blank"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Allegato A della scuola</a>' : '')
                    . ($c['convenzione_id'] ? '<span class="text-secondary">registrata come n. ' . (int) $c['convenzione_id'] . '</span>' : '')
                    . ($c['protocollo_ingresso'] !== '' ? '<span class="text-secondary">protocollo in entrata ' . $h($c['protocollo_ingresso']) . '</span>' : '')
                    . ($c['messaggi'] ? '<a href="' . $h($urlFsl('iter', '') . '#fase-caricata') . '">' . (int) $c['messaggi'] . ($c['messaggi'] === 1 ? ' messaggio' : ' messaggi')
                        . ($c['non_letti'] ? ' (' . (int) $c['non_letti'] . ' da leggere)' : '') . '</a>' : '')
                    . '</div>' . ($c['nota_ufficio'] !== '' ? '<div class="text-secondary mt-1">Motivo del rifiuto: ' . $h($c['nota_ufficio']) . '</div>' : '') . '</div>';
            }
        }

        return $o . '</div></div>';
    }
}
