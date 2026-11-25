<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Anagrafi\Testi;
use App\Core\Database;
use App\Core\Orologio;

/**
 * L'elenco della pagina «Scuole» del pannello FSL: UNA riga per istituto (le sedi sono la stessa scuola, come per la convenzione), con lo stato
 * della convenzione, quanti eventi/studenti/docenti nell'anno scolastico scelto e la prossima cosa da fare.
 *
 * Legge con un numero FISSO di query (registro, compilazioni in corso, messaggi non letti, prenotazioni, anagrafe) e fa i conti in PHP: la copertura
 * di una prenotazione è la stessa regola di ConvenzioneRepository::valida() (data_stipula vuota o <= dal, scadenza vuota o >= al), ma senza una
 * query per prenotazione. Una scuola scritta a mano (senza codice) compare solo se ha prenotazioni FSL: la chiave è quella di sempre («x» + impronta).
 */
final class ElencoScuoleFsl
{
    public const PER_PAGINA = 50;

    /** I filtri «Cosa devo fare»: chiave => testo della tessera (le stesse di StatoConvenzioneScuola::cose). */
    public const COSE = StatoConvenzioneScuola::COSE;

    private const STATI_NON_ATTIVI = ['annullata', 'rifiutata', 'scaduta'];

    public function __construct(
        private Database $db,
        private ServizioScuole $scuole,
        private PeriodiConvenzione $periodi,
        private Orologio $orologio
    ) {
    }

    /**
     * Anno scolastico di una data: 2026 per dal 1° settembre 2026 al 31 agosto 2027.
     */
    public static function annoScolastico(string $data): int
    {
        $t = (int) strtotime($data);

        return (int) date('n', $t) >= 9 ? (int) date('Y', $t) : (int) date('Y', $t) - 1;
    }

    /** L'anno scolastico di oggi. */
    public function annoCorrente(): int
    {
        return self::annoScolastico($this->orologio->adesso()->format('Y-m-d'));
    }

    /**
     * Gli anni scolastici in cui c'è almeno una prenotazione (più quello corrente), dal più recente.
     *
     * @return list<int>
     */
    public function anni(): array
    {
        $anni = [$this->annoCorrente()];
        $d = $this->db->riga("SELECT MIN(COALESCE(pd.data_inizio, t.data_turno, DATE(pr.data_prenotazione))) AS da
                              FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id LEFT JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id");
        if ($d && !empty($d['da'])) {
            for ($a = self::annoScolastico((string) $d['da']); $a < $this->annoCorrente(); $a++) {
                $anni[] = $a;
            }
        }
        rsort($anni);

        return array_values(array_unique($anni));
    }

    /**
     * Tutte le righe, già ordinate: prima le scuole con qualcosa da fare, poi quelle con la convenzione scaduta o in scadenza, poi per nome.
     * $anno = anno scolastico (2026), 0 = tutti gli anni: conta eventi, studenti e docenti; non cambia lo stato della convenzione.
     *
     * $conNumeri = chi gestisce tutta la FSL: solo per lui si calcolano «elenco studenti mancante» e «autorizzazione mancante» (DocumentiMancanti); chi ha solo le
     * convenzioni non li vede, e non si leggono nemmeno i dati per calcolarli.
     *
     * @return list<array<string, mixed>>
     */
    public function righe(int $anno, bool $conNumeri = false): array
    {
        $oggi = $this->orologio->adesso()->format('Y-m-d');
        $tra = $this->orologio->adesso()->modify('+60 days')->format('Y-m-d');
        $sqlReg = ServizioScuole::sqlIstituto('s', 'c.scuola_codice');
        $sqlCom = ServizioScuole::sqlIstituto('s', 'cc.scuola_codice');
        $sqlPre = ServizioScuole::sqlIstituto('s', 'pr.scuola_codice');

        $registro = [];
        foreach ($this->db->righe("SELECT c.id, c.data_stipula, c.scadenza, c.docenti_json, $sqlReg AS ist FROM convenzioni_scuole c") as $c) {
            if ($c['ist'] !== '') {
                $registro[(string) $c['ist']][] = $c;
            }
        }
        $inCorso = [];
        foreach ($this->db->righe(
            "SELECT cc.id, cc.stato_iter, cc.email, cc.attivita_in_attesa, cc.docenti_json, (cc.dati_json IS NOT NULL AND cc.dati_json <> '') AS compilata, $sqlCom AS ist
             FROM convenzioni_compilate cc
             WHERE IFNULL(cc.stato_iter, '') NOT IN ('protocollata', 'unita') AND " . ConvenzioneCompilataRepository::SQL_CONTA . '
             ORDER BY cc.id DESC'
        ) as $x) {
            if ($x['ist'] !== '') {
                $x['da_confermare'] = count(array_filter((array) (json_decode((string) ($x['attivita_in_attesa'] ?? ''), true) ?: []), static fn ($y): bool => is_array($y) && empty($y['ok']) && (int) ($y['pr'] ?? 0) > 0));
                $inCorso[(string) $x['ist']][] = $x;
            }
        }
        $titulus = $this->statoTitulus($inCorso);
        foreach ($inCorso as $ist => $elenco) {
            foreach ($elenco as $i => $x) {
                $inCorso[$ist][$i]['titulus'] = $titulus[(int) $x['id']] ?? 'libero';
            }
        }
        $integrazioni = [];
        foreach ($this->db->righe(
            "SELECT $sqlReg AS ist, COUNT(*) AS n FROM convenzioni_allegati a JOIN convenzioni_scuole c ON c.id = a.convenzione_id WHERE a.stato = 'da_vedere' GROUP BY ist"
        ) as $x) {
            if ($x['ist'] !== '') {
                $integrazioni[(string) $x['ist']] = (int) $x['n'];
            }
        }
        $messaggi = [];
        foreach ($this->db->righe(
            "SELECT $sqlCom AS ist, COUNT(*) AS n FROM convenzioni_messaggi m JOIN convenzioni_compilate cc ON cc.id = m.compilazione_id
             WHERE m.da = ? AND m.letto_il IS NULL AND cc.msg_archiviata_il IS NULL GROUP BY ist",
            [MessaggiConvenzioneRepository::SCUOLA]
        ) as $m) {
            if ($m['ist'] !== '') {
                $messaggi[(string) $m['ist']] = (int) $m['n'];
            }
        }

        // Prenotazioni: tutte quelle con una scuola dell'anagrafe, e le FSL con la scuola scritta a mano
        $gruppi = [];
        $documenti = new DocumentiClasse($this->orologio);
        $campiDocumenti = $conNumeri
            ? ', pr.data_prenotazione, pr.autorizzazione_file, IFNULL(pd.attestati, 0) AS pd_attestati, (SELECT COUNT(*) FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id AND pp.escluso = 0) AS n_elenco'
            : '';
        foreach ($this->db->righe(
            "SELECT pr.id, pr.scuola_codice, pr.email, pr.nome AS p_nome, pr.cognome AS p_cognome, pr.stato, pr.dati_custom_json, pr.solo_orientamento, t.data_turno, t.evento_id,
                    pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine, IFNULL(pd.convenzione, 0) AS pd_conv, $sqlPre AS ist$campiDocumenti
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id LEFT JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id"
        ) as $p) {
            $fsl = (int) $p['pd_conv'] === 1 && (int) $p['solo_orientamento'] === 0;
            $chiave = (string) $p['ist'];
            if ($chiave === '') {
                if (!$fsl) {
                    continue;
                }
                $chiave = 'x' . substr(md5(mb_strtolower(trim(ServizioScuole::nomeScrittoNelModulo($p)) ?: 'p' . $p['id'])), 0, 12);
            }
            $stato = (string) ($p['stato'] ?: 'confermata');
            if (in_array($stato, self::STATI_NON_ATTIVI, true)) {
                continue;
            }
            [$dal, $al] = $this->periodi->prenotazione($p);
            $manca = $conNumeri ? DocumentiMancanti::da($documenti, ['classe' => (int) $p['pd_attestati'] === 1, 'fsl' => $fsl, 'stato' => $stato, 'dal' => $dal, 'al' => $al, 'data_prenotazione' => (string) ($p['data_prenotazione'] ?? ''),
                'n_elenco' => (int) $p['n_elenco'], 'autorizzazione' => !empty($p['autorizzazione_file'])], $oggi) : ['elenco' => false, 'autorizzazione' => false];
            $gruppi[$chiave]['pren'][] = ['elenco_manca' => $manca['elenco'], 'autorizzazione_manca' => $manca['autorizzazione'], 'id' => (int) $p['id'], 'dal' => $dal, 'al' => $al, 'fsl' => $fsl, 'evento' => (int) $p['evento_id'], 'email' => strtolower(trim((string) $p['email'])), 'docente' => trim((string) $p['p_nome'] . ' ' . (string) $p['p_cognome']),
                'studenti' => (int) ((json_decode((string) ($p['dati_custom_json'] ?? ''), true) ?: [])[\CAMPO_PARTECIPANTI] ?? 0), 'nome_modulo' => ServizioScuole::nomeScrittoNelModulo($p)];
        }
        foreach (array_keys($registro + $inCorso + $messaggi + $integrazioni) as $ist) {
            $gruppi[$ist] ??= ['pren' => []];
        }

        // Anagrafe degli istituti trovati, con una query sola: la riga dell'istituto, o quella di una sua sede se l'istituto non ha la sua
        $codici = array_values(array_filter(array_map('strval', array_keys($gruppi)), static fn (string $k): bool => !self::eAMano($k)));
        $anagrafe = [];
        if ($codici) {
            $segnaposto = implode(',', array_fill(0, count($codici), '?'));
            foreach ($this->db->righe("SELECT codice, denominazione, comune, provincia, istituto_codice FROM scuole WHERE codice IN ($segnaposto) OR istituto_codice IN ($segnaposto) ORDER BY codice", [...$codici, ...$codici]) as $s) {
                $ist = (string) (in_array((string) $s['codice'], $codici, true) ? $s['codice'] : $s['istituto_codice']);
                if (!isset($anagrafe[$ist]) || $s['codice'] === $ist) {
                    $anagrafe[$ist] = $s;
                }
            }
        }

        $righe = [];
        foreach ($gruppi as $chiave => $g) {
            $righe[] = $this->riga((string) $chiave, $g['pren'] ?? [], $registro[$chiave] ?? [], $inCorso[$chiave] ?? [], $messaggi[$chiave] ?? 0, $integrazioni[$chiave] ?? 0, $anagrafe[$chiave] ?? null, $anno, $oggi, $tra);
        }
        usort($righe, static fn (array $a, array $b): int => [$a['da_fare'] ? 0 : 1, $a['scade'] ? 0 : 1, mb_strtolower($a['nome'])] <=> [$b['da_fare'] ? 0 : 1, $b['scade'] ? 0 : 1, mb_strtolower($b['nome'])]);

        return $righe;
    }

    /**
     * @param list<array<string, mixed>> $pren prenotazioni attive dell'istituto (tutti gli anni)
     * @param list<array<string, mixed>> $registro convenzioni del registro
     * @param list<array<string, mixed>> $compilazioni compilazioni in corso
     * @param array<string, mixed>|null $anagrafe
     * @return array<string, mixed>
     */
    private function riga(string $chiave, array $pren, array $registro, array $compilazioni, int $nMessaggi, int $nIntegrazioni, ?array $anagrafe, int $anno, string $oggi, string $tra): array
    {
        $st = StatoConvenzioneScuola::da($registro, $compilazioni, $oggi, $tra);
        $conv = $st['stato'];
        $scadenza = $st['scadenza'];
        $iter = $st['iter'];
        $inCorsoSenzaValida = $st['in_corso_senza_valida'];
        $copre = static fn (array $p): bool => StatoConvenzioneScuola::copre($registro, (string) $p['dal'], (string) $p['al']) !== null;

        // Prenotazioni dell'anno scelto e quelle in corso (per le cose da fare)
        $dellAnno = $anno > 0 ? array_filter($pren, static fn (array $p): bool => self::annoScolastico((string) $p['dal']) === $anno) : $pren;
        $scoperte = array_filter($pren, static fn (array $p): bool => $p['fsl'] && $p['al'] >= $oggi && !$copre($p));
        $aMano = self::eAMano($chiave);
        $nome = $anagrafe ? Testi::etichettaScuola($anagrafe) : ($aMano ? (self::nomeMano($pren) ?: 'Scuola scritta a mano') : $chiave);

        // I docenti: i primi tre nomi (di chi ha prenotato nell'anno scelto) sotto il numero, e TUTTI i nomi e le email che conosciamo per la ricerca
        $nomi = [];
        foreach ($dellAnno as $p) {
            $k = $p['email'] !== '' ? $p['email'] : mb_strtolower((string) $p['docente']);
            if ($k !== '' && (string) $p['docente'] !== '' && !isset($nomi[$k])) {
                $nomi[$k] = (string) $p['docente'];
            }
        }
        $ricerca = [];
        foreach ($pren as $p) {
            $ricerca[] = $p['docente'] . ' ' . $p['email'];
        }
        foreach (array_merge($registro, $compilazioni) as $x) {
            foreach ((array) (json_decode((string) ($x['docenti_json'] ?? ''), true) ?: []) as $dc) {
                $ricerca[] = (is_array($dc) ? ($dc['nome'] ?? '') . ' ' . ($dc['email'] ?? '') : '');
            }
            $ricerca[] = (string) ($x['email'] ?? '');
        }

        // Cosa c'è da fare: il criterio è di StatoConvenzioneScuola (lo stesso della scheda), su TUTTE le compilazioni in corso
        $c = StatoConvenzioneScuola::cose($compilazioni, ['stato' => $conv, 'scadenza' => $scadenza, 'n_messaggi' => $nMessaggi, 'n_integrazioni' => $nIntegrazioni, 'n_scoperte' => count($scoperte), 'a_mano' => $aMano,
            'n_elenchi' => count(array_filter($pren, static fn (array $p): bool => $p['elenco_manca'])), 'n_autorizzazioni' => count(array_filter($pren, static fn (array $p): bool => $p['autorizzazione_manca']))]);
        $tags = $c['tags'];
        $cosa = $c['cosa'];
        $attesa = $c['attesa'];
        if (in_array('scaduta_nessuna_attivita', $tags, true)) {
            $attesa = StatoConvenzioneScuola::COSE['scaduta_nessuna_attivita'];
        }

        return [
            'chiave' => $chiave, 'codice' => $aMano ? '' : $chiave, 'a_mano' => $aMano,
            'nome' => $nome,
            'comune' => $anagrafe ? Testi::maiuscoleScuola((string) $anagrafe['comune']) . (!empty($anagrafe['provincia']) ? ' (' . Testi::maiuscoleScuola((string) $anagrafe['provincia']) . ')' : '') : '',
            'stato' => $conv, 'stato_testo' => StatoConvenzioneScuola::STATI[$conv], 'passo' => $inCorsoSenzaValida ? $iter['passo'] : ($iter ? 'Rinnovo o Allegato A: ' . mb_strtolower($iter['passo']) : ''),
            'scadenza' => $scadenza, 'ha_valida' => $st['ha_valida'],
            'eventi' => count(array_unique(array_column($dellAnno, 'evento'))), 'prenotazioni' => count($dellAnno),
            'studenti' => array_sum(array_column($dellAnno, 'studenti')),
            'docenti' => count(array_unique(array_filter(array_column($dellAnno, 'email')))),
            'tags' => $tags, 'cosa' => $cosa ? implode(' · ', $cosa) : ($attesa !== '' ? $attesa : 'Niente da fare'),
            'da_fare' => $c['da_fare'], 'attesa' => $attesa !== '' && !$cosa,
            'scade' => in_array($conv, ['in_scadenza', 'scaduta'], true),
            'docenti_nomi' => array_slice(array_values($nomi), 0, 3), 'docenti_altri' => max(0, count($nomi) - 3), 'ricerca' => mb_strtolower(implode(' ', $ricerca)),
            'n_messaggi' => $nMessaggi, 'n_scoperte' => count($scoperte), 'n_integrazioni' => $nIntegrazioni, 'n_compilazioni' => count($compilazioni),
        ];
    }

    /**
     * Lo stato dell'invio a Titulus delle compilazioni che ne hanno uno in corso (PDF della scuola da protocollare, firmato dal Direttore da protocollare),
     * con UNA query per tutte: la stessa regola di InvioProtocollo::stato (InvioProtocollo::statoDaRighe), senza leggere i file uno per uno. Senza il
     * registro degli invii (modulo Protocollo non installato) nessuno ha niente da dire.
     *
     * @param array<string, list<array<string, mixed>>> $inCorso per istituto
     * @return array<int, string> id della compilazione => stato
     */
    private function statoTitulus(array $inCorso): array
    {
        $fasi = [];
        foreach ($inCorso as $elenco) {
            foreach ($elenco as $x) {
                $fase = match ((string) $x['stato_iter']) {
                    Costanti::ITER_CARICATA => ProtocollaConvenzioneInTitulus::RIF,
                    Costanti::ITER_FIRMATA => ProtocollaDefinitivaFslInTitulus::RIF,
                    default => null,
                };
                if ($fase !== null) {
                    $fasi[(int) $x['id']] = $fase;
                }
            }
        }
        if (!$fasi) {
            return [];
        }
        $per = [];
        try {
            foreach ($this->db->righe(
                'SELECT rif_tipo, rif_id, esito, num_prot, creato_il FROM titulus_invii WHERE rif_id IN (' . implode(',', array_keys($fasi)) . ") AND rif_tipo LIKE 'convenzione\\_fsl\\_%' ORDER BY id DESC"
            ) as $r) {
                $id = (int) $r['rif_id'];
                if (isset($fasi[$id]) && ((string) $r['rif_tipo'] === $fasi[$id] || str_starts_with((string) $r['rif_tipo'], $fasi[$id] . '~'))) {
                    $per[$id][] = $r;
                }
            }
        } catch (\Throwable) {
            return [];   // il registro degli invii si crea con il modulo Protocollo: se manca, niente da dire
        }
        $out = [];
        foreach ($per as $id => $righe) {
            $out[$id] = \App\Protocollo\InvioProtocollo::statoDaRighe($righe, static fn (): bool => true)['stato'];
        }

        return $out;
    }
    /** Una scuola senza codice meccanografico: la sua chiave è «x» + impronta del nome scritto nel modulo. */
    public static function eAMano(string $chiave): bool
    {
        return preg_match('/^[A-Z0-9]{10}$/', $chiave) !== 1;
    }

    /** @param list<array<string, mixed>> $pren */
    private static function nomeMano(array $pren): string
    {
        foreach ($pren as $p) {
            if (($p['nome_modulo'] ?? '') !== '') {
                return (string) $p['nome_modulo'];
            }
        }

        return '';
    }

    /**
     * Quante righe hanno ciascuna «cosa da fare» (per le tessere in alto).
     *
     * @param list<array<string, mixed>> $righe
     * @return array<string, int>
     */
    public static function tessere(array $righe): array
    {
        $n = array_fill_keys(array_keys(self::COSE), 0);
        foreach ($righe as $r) {
            foreach ((array) $r['tags'] as $t) {
                $n[$t]++;
            }
        }

        return $n;
    }

    /**
     * @param list<array<string, mixed>> $righe
     * @return list<array<string, mixed>>
     */
    public static function filtra(array $righe, string $stato, string $cosa, string $testo): array
    {
        $testo = mb_strtolower(trim($testo));

        return array_values(array_filter($righe, static function (array $r) use ($stato, $cosa, $testo): bool {
            if ($stato !== '' && $r['stato'] !== $stato) {
                return false;
            }
            if ($cosa !== '' && !in_array($cosa, (array) $r['tags'], true)) {
                return false;
            }

            return $testo === '' || str_contains(mb_strtolower($r['nome'] . ' ' . $r['comune'] . ' ' . $r['codice'] . ' ' . (string) ($r['ricerca'] ?? '')), $testo);
        }));
    }
}
