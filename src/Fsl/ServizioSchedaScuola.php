<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Anagrafi\Testi;
use App\Core\Database;
use App\Core\Orologio;
use App\Eventi\Righe;

/**
 * Scheda completa di una scuola (pannello FSL): anagrafe, tutte le convenzioni del registro e quelle compilate online dalla scuola,
 * tutte le prenotazioni (anche passate, annullate o di attività non FSL), i referenti e i messaggi, con gli avvisi su ciò che non torna.
 * Si legge per codice meccanografico: la scuola compare anche se oggi non ha prenotazioni FSL in corso.
 */
final class ServizioSchedaScuola
{
    private const STATI_NON_ATTIVI = ['annullata', 'rifiutata', 'scaduta'];

    public function __construct(
        private Database $db,
        private ConvenzioneRepository $convenzioni,
        private PeriodiConvenzione $periodi,
        private ServizioScuole $scuole,
        private MessaggiConvenzioneRepository $messaggi,
        private Orologio $orologio
    ) {
    }

    /**
     * @return array{
     *     codice: string, nome: string, anagrafe: ?array<string, mixed>,
     *     convenzioni: list<array<string, mixed>>, compilazioni: list<array<string, mixed>>, prenotazioni: list<array<string, mixed>>,
     *     referenti: list<array{nome: string, email: string, fonte: string, prenotazioni: int}>,
     *     avvisi: list<array{tono: string, testo: string}>,
     *     sintesi: array{stato: string, testo: string, prenotazioni: int, attive: int, fsl_attive: int, studenti: int, convenzioni: int, documenti: bool}
     * }|null null se il codice non è valido o la scuola non è nell'anagrafe e non ha né prenotazioni né convenzioni
     */
    public function scheda(string $codice): ?array
    {
        $codice = ConvenzioneRepository::codiceValido($codice);
        if ($codice === null) {
            return null;
        }
        $anagrafe = $this->scuole->perCodice($codice);
        $oggi = $this->orologio->adesso()->format('Y-m-d');
        $registro = $this->convenzioni->dellaScuola($codice);
        $prenotazioni = $this->prenotazioni($codice, $oggi);
        $compilazioni = $this->compilazioni($codice);
        if (!$anagrafe && !$registro && !$prenotazioni && !$compilazioni) {
            return null;
        }

        return [
            'codice' => $codice,
            'nome' => $anagrafe ? Testi::etichettaScuola($anagrafe) : $codice,
            'anagrafe' => $anagrafe,
            'convenzioni' => $this->elencoConvenzioni($registro, $oggi),
            'compilazioni' => $compilazioni,
            'prenotazioni' => $prenotazioni,
            'referenti' => $this->referenti($codice, $prenotazioni, $registro),
            'avvisi' => $this->avvisi($registro, $prenotazioni, $compilazioni),
            'sintesi' => $this->sintesi($registro, $prenotazioni, $oggi),
        ];
    }

    /**
     * Tutte le prenotazioni della scuola con il periodo, se l'attività è FSL e se una convenzione del registro copre quel periodo.
     * Conta il registro, non quanto dichiarato dalla scuola nel modulo.
     *
     * @return list<array<string, mixed>> dalla più recente
     */
    private function prenotazioni(string $codice, string $oggi): array
    {
        $righe = Righe::testo($this->db->righe(
            "SELECT pr.id, pr.codice_prenotazione, pr.nome, pr.cognome, pr.email, pr.stato, pr.convenzione, pr.dati_custom_json, pr.data_prenotazione, pr.presente, pr.solo_orientamento,
                    t.data_turno, t.nome_turno, t.evento_id, e.titolo, e.tipo AS evento_tipo, e.pagina_id, pe.titolo AS area, pe.tipo_area, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine,
                    IFNULL(pd.convenzione, 0) AS pd_conv,
                    (SELECT COUNT(*) FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id AND pp.escluso = 0) AS n_elenco
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id JOIN pagine_eventi pe ON e.pagina_id = pe.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE pr.scuola_codice = ?
             ORDER BY COALESCE(pd.data_inizio, t.data_turno, pr.data_prenotazione) DESC, pr.id DESC",
            [$codice]
        ));
        $out = [];
        foreach ($righe as $x) {
            [$dal, $al] = $this->periodi->prenotazione($x);
            $stato = (string) ($x['stato'] ?: 'confermata');
            $fsl = (int) $x['pd_conv'] === 1 && (int) $x['solo_orientamento'] === 0;
            $valida = $fsl ? $this->convenzioni->valida($codice, $dal, $al) : null;
            $custom = json_decode((string) ($x['dati_custom_json'] ?? ''), true) ?: [];
            $out[] = [
                'id' => (int) $x['id'], 'codice' => (string) $x['codice_prenotazione'], 'titolo' => (string) $x['titolo'], 'area' => (string) $x['area'],
                'pagina_id' => (int) $x['pagina_id'], 'evento_id' => (int) $x['evento_id'], 'turno' => ($x['evento_tipo'] ?? '') === 'progetto' ? trim((string) $x['nome_turno']) : '',
                'dal' => $dal, 'al' => $al, 'stato' => $stato, 'attiva' => !in_array($stato, self::STATI_NON_ATTIVI, true), 'conclusa' => $al < $oggi,
                'fsl' => $fsl, 'solo_orientamento' => (int) $x['solo_orientamento'] === 1,
                // attività in un'area FSL con l'interruttore «Convenzione» spento: la scuola non vede nulla e il pannello non la segue
                'fsl_spenta' => (int) $x['pd_conv'] === 0 && (string) ($x['tipo_area'] ?? '') === 'fsl' && (int) $x['solo_orientamento'] === 0,
                'conv' => !$fsl ? 'non_richiesta' : ($valida ? 'coperta' : 'non_coperta'), 'conv_id' => $valida ? (int) $valida['id'] : 0,
                'dichiarata' => (string) ($x['convenzione'] ?? ''),
                'studenti' => (int) ($custom[\CAMPO_PARTECIPANTI] ?? 0), 'elenco' => (int) $x['n_elenco'], 'presente' => (int) $x['presente'] === 1,
                'docente' => trim($x['nome'] . ' ' . $x['cognome']), 'email' => (string) $x['email'], 'registrata' => (string) $x['data_prenotazione'],
            ];
        }

        return $out;
    }

    /**
     * Le convenzioni del registro, con i file, lo stato e i docenti di riferimento.
     *
     * @param list<array<string, mixed>> $registro
     * @return list<array<string, mixed>>
     */
    private function elencoConvenzioni(array $registro, string $oggi): array
    {
        $tra = $this->orologio->adesso()->modify('+60 days')->format('Y-m-d');
        $out = [];
        foreach ($registro as $c) {
            $docenti = [];
            foreach (json_decode((string) ($c['docenti_json'] ?? ''), true) ?: [] as $d) {
                if (!empty($d['nome'])) {
                    $docenti[] = ['nome' => (string) $d['nome'], 'email' => (string) ($d['email'] ?? '')];
                }
            }
            $out[] = [
                'id' => (int) $c['id'], 'validita' => PeriodiConvenzione::testoValidita($c), 'stato' => ServizioDocumentiScuola::stato($c, $oggi, $tra),
                'protocollo' => (string) ($c['protocollo'] ?? ''), 'note' => (string) ($c['note'] ?? ''), 'registrata_da' => (string) ($c['registrata_da'] ?? ''),
                'file' => !empty($c['file_convenzione']), 'allegato' => !empty($c['file_allegato']), 'docenti' => $docenti,
            ];
        }

        return $out;
    }

    /**
     * Le convenzioni compilate online dalla scuola (anche non ancora arrivate nel registro), con lo stato dell'iter e i messaggi.
     *
     * @return list<array<string, mixed>>
     */
    private function compilazioni(string $codice): array
    {
        $righe = Righe::testo($this->db->righe(
            'SELECT id, email, stato_iter, file_firmato, file_allegato_scuola, firmata_il, aggiornata_il, protocollo_ingresso, protocollo, convenzione_id, nota_ufficio
             FROM convenzioni_compilate WHERE scuola_codice = ? AND dati_json IS NOT NULL ORDER BY id DESC',
            [$codice]
        ));
        $testi = [
            Costanti::ITER_CARICATA => 'PDF firmato caricato dalla scuola: da prendere in carico', Costanti::ITER_IN_CARICO => 'Protocollata in entrata',
            Costanti::ITER_DA_FIRMARE => 'Dal Direttore per la firma', Costanti::ITER_FIRMATA => 'Firmata dal Direttore: da registrare',
            Costanti::ITER_PROTOCOLLATA => 'Registrata', Costanti::ITER_RIFIUTATA => 'File rifiutato: la scuola deve ricaricarlo',
        ];
        $out = [];
        foreach ($righe as $r) {
            $conversazione = $this->messaggi->della((int) $r['id']);
            $out[] = [
                'id' => (int) $r['id'], 'email' => (string) $r['email'], 'stato_iter' => (string) $r['stato_iter'],
                'stato' => $testi[(string) $r['stato_iter']] ?? 'Compilata online: la scuola non ha ancora caricato il PDF firmato',
                'file_scuola' => !empty($r['file_firmato']), 'allegato_scuola' => !empty($r['file_allegato_scuola']),
                'firmata_il' => (string) ($r['firmata_il'] ?? ''), 'aggiornata_il' => (string) ($r['aggiornata_il'] ?? ''),
                'protocollo_ingresso' => (string) ($r['protocollo_ingresso'] ?? ''), 'protocollo' => (string) ($r['protocollo'] ?? ''),
                'convenzione_id' => (int) ($r['convenzione_id'] ?? 0), 'nota_ufficio' => (string) ($r['nota_ufficio'] ?? ''),
                'messaggi' => count($conversazione),
                'non_letti' => count(array_filter($conversazione, static fn (array $m): bool => ($m['da'] ?? '') === MessaggiConvenzioneRepository::SCUOLA && empty($m['letto_il']))),
                'ultimo_messaggio' => $conversazione ? (string) ($conversazione[array_key_last($conversazione)]['creato_il'] ?? '') : '',
            ];
        }

        return $out;
    }

    /**
     * Le persone della scuola: chi ha prenotato, i docenti di riferimento indicati nelle convenzioni e chi ha la scuola nel profilo.
     *
     * @param list<array<string, mixed>> $prenotazioni
     * @param list<array<string, mixed>> $registro
     * @return list<array{nome: string, email: string, fonte: string, prenotazioni: int}>
     */
    private function referenti(string $codice, array $prenotazioni, array $registro): array
    {
        $per = [];
        $aggiungi = static function (string $nome, string $email, string $fonte, int $n) use (&$per): void {
            $chiave = strtolower(trim($email)) !== '' ? strtolower(trim($email)) : 'n:' . mb_strtolower(trim($nome));
            if ($chiave === 'n:') {
                return;
            }
            $per[$chiave] ??= ['nome' => '', 'email' => '', 'fonte' => [], 'prenotazioni' => 0];
            if ($per[$chiave]['nome'] === '' && trim($nome) !== '') {
                $per[$chiave]['nome'] = trim($nome);
            }
            if ($per[$chiave]['email'] === '' && trim($email) !== '') {
                $per[$chiave]['email'] = strtolower(trim($email));
            }
            $per[$chiave]['fonte'][$fonte] = $fonte;
            $per[$chiave]['prenotazioni'] += $n;
        };
        foreach ($prenotazioni as $p) {
            $aggiungi($p['docente'], $p['email'], 'prenotazioni', 1);
        }
        foreach ($registro as $c) {
            foreach (json_decode((string) ($c['docenti_json'] ?? ''), true) ?: [] as $d) {
                $aggiungi((string) ($d['nome'] ?? ''), (string) ($d['email'] ?? ''), 'convenzione', 0);
            }
        }
        foreach ($this->db->righe("SELECT nome, cognome, email FROM utenti WHERE scuola_codice = ? AND email IS NOT NULL AND email <> ''", [$codice]) as $u) {
            $aggiungi(trim((string) $u['nome'] . ' ' . (string) $u['cognome']), (string) $u['email'], 'profilo', 0);
        }
        $nomi = ['prenotazioni' => 'prenotazioni', 'convenzione' => 'docente della convenzione', 'profilo' => 'profilo'];
        $out = [];
        foreach ($per as $r) {
            $out[] = ['nome' => $r['nome'], 'email' => $r['email'], 'fonte' => implode(', ', array_map(static fn (string $f): string => $nomi[$f], $r['fonte'])), 'prenotazioni' => $r['prenotazioni']];
        }
        usort($out, static fn (array $a, array $b): int => [$b['prenotazioni'], $a['nome']] <=> [$a['prenotazioni'], $b['nome']]);

        return $out;
    }

    /**
     * Cosa non torna per questa scuola, dal più grave.
     *
     * @param list<array<string, mixed>> $registro
     * @param list<array<string, mixed>> $prenotazioni
     * @param list<array<string, mixed>> $compilazioni
     * @return list<array{tono: string, testo: string}>
     */
    private function avvisi(array $registro, array $prenotazioni, array $compilazioni): array
    {
        $avvisi = [];
        $spente = array_filter($prenotazioni, static fn (array $p): bool => $p['attiva'] && !$p['conclusa'] && $p['fsl_spenta']);
        if ($spente) {
            $titoli = array_values(array_unique(array_column($spente, 'titolo')));
            $avvisi[] = ['tono' => 'blocco', 'testo' => 'Prenotazioni in un\'area FSL con l\'interruttore «Convenzione» spento sul progetto ('
                . implode('; ', $titoli) . '): per il portale non sono FSL, quindi nessuna convenzione, nessun Allegato A e niente nell\'Area personale della scuola. Accendi l\'interruttore dalla scheda del progetto.'];
        }
        $scoperte = array_filter($prenotazioni, static fn (array $p): bool => $p['attiva'] && !$p['conclusa'] && $p['conv'] === 'non_coperta');
        if ($scoperte) {
            $dichiarate = count(array_filter($scoperte, static fn (array $p): bool => $p['dichiarata'] === 'si'));
            $avvisi[] = ['tono' => 'blocco', 'testo' => count($scoperte) . (count($scoperte) === 1 ? ' prenotazione FSL' : ' prenotazioni FSL')
                . ' in corso senza una convenzione del registro che copra tutto il periodo'
                . ($dichiarate ? ' (la scuola dice di averla, ma nel registro non c\'è o è scaduta)' : '') . '.'];
        }
        foreach ($registro as $c) {
            if (empty($c['file_convenzione']) && empty($c['file_allegato'])) {
                $avvisi[] = ['tono' => 'attesa', 'testo' => 'La convenzione n. ' . (int) $c['id'] . ' è nel registro ma senza file (né convenzione né Allegato A): caricali con «Modifica».'];
            }
        }
        foreach ($compilazioni as $c) {
            if ($c['stato_iter'] === Costanti::ITER_CARICATA) {
                $avvisi[] = ['tono' => 'attesa', 'testo' => 'La scuola ha caricato il PDF firmato: va protocollato (Convenzioni da seguire).'];
            }
            if ($c['non_letti'] > 0) {
                $avvisi[] = ['tono' => 'info', 'testo' => $c['non_letti'] . ($c['non_letti'] === 1 ? ' messaggio della scuola da leggere.' : ' messaggi della scuola da leggere.')];
            }
        }

        return $avvisi;
    }

    /**
     * @param list<array<string, mixed>> $registro
     * @param list<array<string, mixed>> $prenotazioni
     * @return array{stato: string, testo: string, prenotazioni: int, attive: int, fsl_attive: int, studenti: int, convenzioni: int, documenti: bool}
     */
    private function sintesi(array $registro, array $prenotazioni, string $oggi): array
    {
        $attive = array_filter($prenotazioni, static fn (array $p): bool => $p['attiva']);
        $inCorso = array_filter($attive, static fn (array $p): bool => !$p['conclusa']);
        $fsl = array_filter($inCorso, static fn (array $p): bool => $p['fsl']);
        $scoperte = array_filter($fsl, static fn (array $p): bool => $p['conv'] !== 'coperta');
        $vigore = array_filter($registro, static fn (array $c): bool => (empty($c['scadenza']) || $c['scadenza'] >= $oggi) && (empty($c['data_stipula']) || $c['data_stipula'] <= $oggi));
        if ($scoperte) {
            [$stato, $testo] = ['da_stipulare', 'Convenzione da stipulare o rinnovare'];
        } elseif ($fsl) {
            [$stato, $testo] = ['ok', 'Convenzione a posto per le attività in corso'];
        } elseif ($vigore) {
            [$stato, $testo] = ['ok', 'Convenzione in vigore'];
        } elseif ($registro) {
            [$stato, $testo] = ['scaduta', 'Convenzioni tutte scadute'];
        } else {
            [$stato, $testo] = ['nessuna', 'Nessuna convenzione nel registro'];
        }

        return [
            'stato' => $stato, 'testo' => $testo, 'prenotazioni' => count($prenotazioni), 'attive' => count($inCorso), 'fsl_attive' => count($fsl),
            'studenti' => array_sum(array_column($inCorso, 'studenti')), 'convenzioni' => count($registro), 'documenti' => (bool) $fsl,
        ];
    }
}
