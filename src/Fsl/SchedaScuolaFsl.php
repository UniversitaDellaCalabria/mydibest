<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Anagrafi\Testi;
use App\Core\Database;
use App\Core\Orologio;

/**
 * La scheda di una scuola nella pagina «Scuole» del pannello FSL: tutto ciò che l'ufficio deve sapere di UN istituto in una pagina sola (anagrafe e sedi,
 * convenzione con file e iter, eventi e progetti di tutte le sedi, docenti referenti, messaggi).
 *
 * Legge con un numero FISSO di query, qualunque sia il numero di prenotazioni (60 o 600): anagrafe, sedi, registro, compilazioni, allegati e messaggi a
 * gruppi, prenotazioni (con elenco studenti e valutazione nella stessa query), rubrica, utenti e invii email. Le coperture si calcolano in PHP
 * (StatoConvenzioneScuola). Il vecchio ServizioSchedaScuola faceva una query per prenotazione.
 *
 * Chi ha solo le convenzioni ($conNumeri = false) non riceve numeri di studenti né valutazioni: non li legge nemmeno.
 * I link agli studenti (partecipanti.php) li chiede $puoStudenti: la pagina di destinazione decide e qui si mostra solo ciò che ci si apre davvero.
 */
final class SchedaScuolaFsl
{
    private const STATI_NON_ATTIVI = ['annullata', 'rifiutata', 'scaduta'];
    private const STATI_TESTO = [
        'confermata' => 'Confermata', 'in_attesa' => 'In attesa', 'da_approvare' => 'Da approvare', 'richiesta_conferma' => 'Da confermare',
        'annullata' => 'Annullata', 'rifiutata' => 'Rifiutata', 'scaduta' => 'Scaduta',
    ];

    public function __construct(
        private Database $db,
        private ServizioScuole $scuole,
        private ConvenzioneRepository $convenzioni,
        private PeriodiConvenzione $periodi,
        private Orologio $orologio,
        private RubricaDocentiScuola $rubrica,
        private IterNellaScheda $iter
    ) {
    }

    /**
     * @param ?callable(int): bool $puoStudenti chi può aprire l'elenco degli studenti di un'attività (id della pagina/area)? null = nessuno
     * @return array<string, mixed>|null null se la chiave non è valida o non c'è né anagrafe né convenzioni né prenotazioni
     */
    public function scheda(string $chiave, int $anno, bool $conNumeri, ?callable $puoStudenti = null): ?array
    {
        $chiave = trim($chiave);
        $codice = ConvenzioneRepository::codiceValido($chiave);
        $aMano = false;
        if ($codice !== null) {
            $istituto = $this->scuole->codiceIstituto($codice) ?: $codice;
            $chiave = $istituto;
        } elseif (preg_match('/^x[0-9a-f]{12}$/', $chiave) === 1) {
            $istituto = '';
            $aMano = true;
        } else {
            return null;
        }
        $oggi = $this->orologio->adesso()->format('Y-m-d');
        $tra = $this->orologio->adesso()->modify('+60 days')->format('Y-m-d');

        $sedi = $aMano ? [] : $this->db->righe('SELECT codice, denominazione, comune, provincia, istituto_codice, tipo, indirizzo, cap, email, pec FROM scuole WHERE codice = ? OR istituto_codice = ? ORDER BY (codice = ?) DESC, codice', [$istituto, $istituto, $istituto]);
        $anagrafe = $sedi ? $sedi[0] : null;
        $registro = $aMano ? [] : $this->convenzioni->dellaScuola($istituto);
        $compilazioni = $aMano ? [] : $this->compilazioni($istituto);
        $prenotazioni = $this->prenotazioni($chiave, $istituto, $aMano, $registro, $oggi, $anno, $conNumeri, $puoStudenti, (string) ($anagrafe['codice'] ?? ''), (bool) array_filter($compilazioni, static fn (array $c): bool => $c['in_corso']));
        if (!$anagrafe && !$registro && !$compilazioni && !$prenotazioni['tutte']) {
            return null;
        }

        $conv = $this->convenzione($registro, $compilazioni, $oggi, $tra);
        $iterAperti = $this->iter->arricchisci($conv['in_corso']);
        $iterPrincipale = null;
        foreach ($iterAperti as $c) {
            if ($conv['iter'] !== null && $c['id'] === $conv['iter']['id']) {
                $iterPrincipale = $c;
            }
        }
        $azione = StatoConvenzioneScuola::azione($conv['stato'], $iterPrincipale, $aMano);
        $cose = $this->cose($conv, $iterAperti, $compilazioni, $prenotazioni['scoperte'], $aMano, $prenotazioni['n_elenchi'], $prenotazioni['n_autorizzazioni']);
        $docenti = $this->docenti($istituto, $prenotazioni['tutte'], $registro, $iterAperti, $oggi, $tra);
        $nomeMano = '';
        foreach ($prenotazioni['tutte'] as $p) {
            $nomeMano = $nomeMano ?: (string) $p['scuola_scritta'];
        }

        return [
            'chiave' => $chiave, 'codice' => $aMano ? '' : $istituto, 'a_mano' => $aMano, 'in_anagrafe' => $anagrafe !== null,
            'nome' => $anagrafe ? Testi::etichettaScuola($anagrafe) : ($aMano ? ($nomeMano ?: 'Scuola scritta a mano') : $istituto),
            'anagrafe' => $anagrafe ? [
                'denominazione' => Testi::maiuscoleScuola((string) $anagrafe['denominazione']), 'comune' => Testi::maiuscoleScuola((string) $anagrafe['comune']),
                'provincia' => Testi::maiuscoleScuola((string) $anagrafe['provincia']), 'tipo' => (string) $anagrafe['tipo'],
                'indirizzo' => trim(Testi::maiuscoleScuola((string) $anagrafe['indirizzo']) . ((string) $anagrafe['cap'] !== '' ? ', ' . $anagrafe['cap'] : '')),
                'pec' => (string) $anagrafe['pec'], 'email' => (string) $anagrafe['email'], 'telefono' => (string) ($anagrafe['telefono'] ?? ''),
            ] : null,
            'contatti_indicati' => $this->contattiIndicati($anagrafe, $compilazioni, $prenotazioni['tutte']),
            'sedi' => array_map(static fn (array $s): array => ['codice' => (string) $s['codice'], 'nome' => Testi::etichettaScuola($s), 'principale' => $s['codice'] === ($anagrafe['codice'] ?? '')], $sedi),
            'stato' => $conv['stato'], 'stato_testo' => StatoConvenzioneScuola::STATI[$conv['stato']], 'scadenza' => $conv['scadenza'],
            'validita' => $conv['validita'], 'azione' => $azione,
            'registro' => $conv['registro'], 'iter' => $conv['iter'], 'aperte' => $conv['aperte'], 'storico' => $conv['storico'],
            'periodo_nuovo' => array_combine(['dal', 'al'], $this->periodi->nuova()), 'iter_aperti' => $iterAperti, 'registrate' => $this->iter->registrate($compilazioni), 'direttore' => $this->iter->direttore(), 'cose' => $cose['cosa'], 'cose_altre' => array_values(array_map(static fn (array $v): string => $v['testo'], array_filter($cose['voci'], static fn (array $v): bool => !$v['principale']))), 'tags' => $cose['tags'], 'attesa' => $cose['attesa'],
            'eventi' => $prenotazioni['mostrate'], 'n_eventi_tutti' => count($prenotazioni['tutte']), 'anni' => $prenotazioni['anni'], 'anno' => $anno,
            'n_scoperte' => $prenotazioni['scoperte'], 'n_fsl_in_essere' => $prenotazioni['fsl_in_essere'],
            'docenti' => $docenti,
            'messaggi' => $conv['messaggi'], 'compilazioni_con_messaggi' => $conv['con_messaggi'],
            'scrivi' => $this->scrivi($compilazioni, $docenti, $aMano),
            'valutazioni' => $conNumeri ? array_values(array_filter($prenotazioni['mostrate'], static fn (array $p): bool => $p['val_id'] > 0)) : [],
            'con_numeri' => $conNumeri,
        ];
    }

    /**
     * @param list<array<string, mixed>> $registro
     * @param list<array<string, mixed>> $compilazioni tutte, come da compilazioni()
     * @return array<string, mixed>
     */
    private function convenzione(array $registro, array $compilazioni, string $oggi, string $tra): array
    {
        $inCorso = array_values(array_filter($compilazioni, static fn (array $c): bool => $c['in_corso']));
        $st = StatoConvenzioneScuola::da($registro, $inCorso, $oggi, $tra);
        $reg = [];
        $dellaCompilazione = [];   // la compilazione online da cui è nata una convenzione del registro (i file dell'iter sono lì)
        foreach ($compilazioni as $c) {
            if ((int) $c['convenzione_id'] > 0 && $c['stato_iter'] === Costanti::ITER_PROTOCOLLATA) {
                $dellaCompilazione[(int) $c['convenzione_id']] ??= $c;
            }
        }
        foreach ($registro as $c) {
            $reg[] = [
                'documenti' => self::documentiRegistrati($c, $dellaCompilazione[(int) $c['id']] ?? null),
                'data_allegato' => !empty($c['file_allegato']) ? substr((string) ($c['created_at'] ?? ''), 0, 10) : '',
                'id' => (int) $c['id'], 'validita' => PeriodiConvenzione::testoValidita($c), 'stato' => ServizioDocumentiScuola::stato($c, $oggi, $tra),
                'data_stipula' => (string) ($c['data_stipula'] ?? ''), 'scadenza' => (string) ($c['scadenza'] ?? ''),
                'protocollo' => (string) ($c['protocollo'] ?? ''), 'registrata_da' => (string) ($c['registrata_da'] ?? ''), 'note' => (string) ($c['note'] ?? ''),
                'file_convenzione' => !empty($c['file_convenzione']), 'file_allegato' => !empty($c['file_allegato']),
                'docenti' => array_values(array_filter(array_map(static fn ($d): array => ['nome' => (string) ($d['nome'] ?? ''), 'email' => (string) ($d['email'] ?? '')], json_decode((string) ($c['docenti_json'] ?? ''), true) ?: []), static fn (array $d): bool => $d['nome'] !== '')),
                'integrazioni' => $c['integrazioni'] ?? [],
            ];
        }
        $reg = $this->conIntegrazioni($reg);
        $iter = null;
        if ($st['iter'] !== null) {
            foreach ($inCorso as $c) {
                if ((int) $c['id'] === $st['iter']['id']) {
                    $iter = $c;
                }
            }
        }
        // Ciò che si può fare ora, UNA azione principale
        $azione = ['chiave' => 'nessuna', 'testo' => 'Niente da fare ora'];
        $stato = $st['stato'];
        if ($iter !== null && $iter['tag'] !== '') {
            $azione = ['chiave' => 'iter', 'testo' => $iter['cosa'], 'compilazione' => $iter['id']];
        } elseif ($iter !== null) {
            $azione = ['chiave' => 'sollecita', 'testo' => 'Scrivi o ricorda alla scuola', 'compilazione' => $iter['id']];
        } elseif ($stato === 'in_scadenza' || $stato === 'scaduta') {
            $azione = ['chiave' => 'rinnova', 'testo' => 'Rinnova la convenzione'];
        } elseif ($stato === 'manca' || $stato === 'futura') {
            $azione = ['chiave' => 'avvia', 'testo' => 'Avvia la stipula'];
        }
        $validita = $st['migliore'] ? PeriodiConvenzione::testoValidita($st['migliore']) : '';

        $messaggi = [];
        $conMsg = [];
        foreach ($compilazioni as $c) {
            foreach ($c['messaggi'] as $m) {
                $messaggi[] = $m + ['compilazione' => $c['id']];
            }
            if ($c['messaggi'] || $c['in_corso']) {
                $conMsg[] = $c['id'];
            }
        }
        usort($messaggi, static fn (array $a, array $b): int => strcmp((string) $b['creato_il'], (string) $a['creato_il']));

        return [
            'stato' => $stato, 'scadenza' => $st['scadenza'], 'validita' => $validita, 'azione' => $azione, 'registro' => $reg,
            'iter' => $iter, 'in_corso' => self::ordinate($inCorso), 'aperte' => array_map(static fn (array $c): array => ['id' => $c['id'], 'email' => $c['email'], 'passo' => $c['passo']], $inCorso), 'storico' => array_values(array_filter($compilazioni, static fn (array $c): bool => !$c['in_corso'])),
            'messaggi' => array_slice($messaggi, 0, 30), 'con_messaggi' => $conMsg,
        ];
    }

    /**
     * A chi arriva «Scrivi alla scuola», in quest'ordine: la compilazione in corso (la più avanti) → altrimenti l'ultima conclusa → altrimenti i docenti scelti tra chi ha
     * prenotato. Così si può scrivere sempre; chi scrive vede a chi arriva. Il link personale della convenzione va SOLO all'email della compilazione.
     *
     * @param list<array<string, mixed>> $compilazioni tutte, dalla più recente
     * @param list<array<string, mixed>> $docenti
     * @return array{modo: string, destinatari: list<array<string, mixed>>, predefinita: int}
     *         modo = compilazione (si sceglie tra le compilazioni con un'email valida) | docenti (si sceglie tra i docenti con una prenotazione e un'email) | nessuno
     */
    private function scrivi(array $compilazioni, array $docenti, bool $aMano): array
    {
        $conEmail = static fn (array $c): bool => filter_var(trim((string) $c['email']), FILTER_VALIDATE_EMAIL) !== false;
        $inCorso = array_values(array_filter($compilazioni, static fn (array $c): bool => $c['in_corso'] && $conEmail($c)));
        if ($inCorso) {
            $inCorso = self::ordinate($inCorso);

            return ['modo' => 'compilazione', 'predefinita' => (int) $inCorso[0]['id'], 'destinatari' => array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'email' => (string) $c['email'], 'passo' => (string) $c['passo'], 'in_corso' => true], $inCorso)];
        }
        foreach ($compilazioni as $c) {
            if ($c['stato_iter'] === Costanti::ITER_PROTOCOLLATA && $conEmail($c)) {
                return ['modo' => 'compilazione', 'predefinita' => (int) $c['id'], 'destinatari' => [['id' => (int) $c['id'], 'email' => (string) $c['email'], 'passo' => 'convenzione registrata', 'in_corso' => false]]];
            }
        }
        $scelti = array_values(array_filter($docenti, static fn (array $d): bool => (int) $d['prenotazione_id'] > 0));

        return ['modo' => $scelti ? 'docenti' : 'nessuno', 'predefinita' => 0,
            'destinatari' => array_map(static fn (array $d): array => ['prenotazione' => (int) $d['prenotazione_id'], 'nome' => $d['nome'], 'email' => $d['email']], $scelti)];
    }

    /**
     * I file di una convenzione del registro come li vede l'ufficio: «Originale» (il file firmato dalle due parti) e «Documento protocollato», ciascuno con la data
     * e da dove viene. Il documento protocollato è, nell'ordine del resto del portale (FileProtocollato): quello caricato dall'ufficio, la copia con la
     * segnatura del portale, il file ripreso da Titulus. Un file solo = una voce sola.
     *
     * @param array<string, mixed> $c la riga del registro
     * @param array<string, mixed>|null $comp la compilazione online da cui è nata (con 'riga'), null se l'ha registrata l'ufficio a mano
     * @return list<array{voce: string, url: string, data: string, origine: string}>
     */
    private static function documentiRegistrati(array $c, ?array $comp): array
    {
        $giorno = static fn ($v): string => $v ? substr((string) $v, 0, 10) : '';
        $registrata = $giorno($c['created_at'] ?? '');
        $out = [];
        if ($comp === null) {
            if (!empty($c['file_convenzione'])) {
                $out[] = ['voce' => 'Originale', 'url' => 'convenzione_file.php?id=' . (int) $c['id'] . '&f=conv', 'data' => $registrata, 'origine' => 'firmato dalle due parti, caricato dall\'ufficio'];
            }

            return $out;
        }
        $r = (array) $comp['riga'];
        $id = (int) $comp['id'];
        $protocollata = $giorno($r['protocollata_il'] ?? '');
        if (!empty($r['file_definitivo'])) {
            $out[] = ['voce' => 'Originale', 'url' => 'convenzione_file.php?c=' . $id . '&f=dir', 'data' => $giorno($r['firmata_direttore_il'] ?? ''), 'origine' => 'firmato dalla scuola e dal Direttore'];
        } elseif (!empty($r['file_originale'])) {
            $out[] = ['voce' => 'Originale', 'url' => 'convenzione_file.php?c=' . $id . '&f=orig', 'data' => $giorno($r['originale_il'] ?? '') ?: $protocollata, 'origine' => 'ripreso da Titulus'];
        }
        if (!empty($r['file_protocollato'])) {
            $out[] = ['voce' => 'Documento protocollato', 'url' => 'convenzione_file.php?c=' . $id . '&f=prot', 'data' => $protocollata, 'origine' => 'caricato dall\'ufficio'];
        } elseif (!empty($c['file_convenzione'])) {
            $out[] = ['voce' => 'Documento protocollato', 'url' => 'convenzione_file.php?id=' . (int) $c['id'] . '&f=conv', 'data' => $registrata, 'origine' => 'copia del portale con la segnatura'];
        } elseif (!empty($r['file_originale']) && !empty($r['file_definitivo'])) {
            $out[] = ['voce' => 'Documento protocollato', 'url' => 'convenzione_file.php?c=' . $id . '&f=orig', 'data' => $giorno($r['originale_il'] ?? '') ?: $protocollata, 'origine' => 'ripreso da Titulus'];
        }

        return $out;
    }

    /**
     * Cosa c'è da fare per questa scuola: il criterio dell'elenco (StatoConvenzioneScuola::cose) sulle STESSE cose, così le due schermate non si contraddicono.
     *
     * @param array<string, mixed> $conv
     * @param list<array<string, mixed>> $iterAperti compilazioni in corso con lo stato di Titulus
     * @param list<array<string, mixed>> $compilazioni tutte
     * @return array{tags: list<string>, cosa: list<string>, attesa: string, da_fare: bool}
     */
    private function cose(array $conv, array $iterAperti, array $compilazioni, int $scoperte, bool $aMano, int $elenchi = 0, int $autorizzazioni = 0): array
    {
        $righe = array_map(static fn (array $c): array => ['id' => $c['id'], 'stato_iter' => $c['stato_iter'], 'compilata' => $c['compilata'], 'da_confermare' => count($c['da_confermare']), 'titulus' => (string) ($c['titulus']['stato'] ?? 'libero')], $iterAperti);
        $integrazioni = 0;
        foreach ($conv['registro'] as $r) {
            foreach ($r['integrazioni'] as $a) {
                $integrazioni += $a['stato'] === 'da_vedere' ? 1 : 0;
            }
        }
        $messaggi = 0;
        foreach ($compilazioni as $c) {
            $messaggi += $c['archiviata'] ? 0 : (int) $c['non_letti'];
        }
        $r = StatoConvenzioneScuola::cose($righe, ['stato' => $conv['stato'], 'scadenza' => $conv['scadenza'], 'n_messaggi' => $messaggi, 'n_integrazioni' => $integrazioni, 'n_scoperte' => $scoperte, 'a_mano' => $aMano, 'n_elenchi' => $elenchi, 'n_autorizzazioni' => $autorizzazioni]);
        if (in_array('scaduta_nessuna_attivita', $r['tags'], true) && $r['attesa'] === '') {
            $r['attesa'] = StatoConvenzioneScuola::COSE['scaduta_nessuna_attivita'];
        }

        return $r;
    }

    /** Le compilazioni dalla più avanti (la prima ha la cosa più urgente), a parità la più recente. @param list<array<string, mixed>> $c @return list<array<string, mixed>> */
    private static function ordinate(array $c): array
    {
        usort($c, static fn (array $a, array $b): int => [$b['peso'], $b['id']] <=> [$a['peso'], $a['id']]);

        return $c;
    }

    /**
     * Le integrazioni (Allegati A aggiornati dalla scuola) delle convenzioni, con una query sola.
     *
     * @param list<array<string, mixed>> $reg
     * @return list<array<string, mixed>>
     */
    private function conIntegrazioni(array $reg): array
    {
        if (!$reg) {
            return [];
        }
        $ids = array_map(static fn (array $c): int => (int) $c['id'], $reg);
        $per = [];
        foreach ($this->db->righe('SELECT id, convenzione_id, stato, caricato_il, caricato_da, nota, attivita_json FROM convenzioni_allegati WHERE convenzione_id IN (' . implode(',', $ids) . ') ORDER BY id DESC') as $a) {
            $per[(int) $a['convenzione_id']][] = ['id' => (int) $a['id'], 'stato' => (string) $a['stato'], 'caricato_il' => (string) $a['caricato_il'], 'caricato_da' => (string) $a['caricato_da'],
                'nota' => (string) $a['nota'], 'attivita' => count(json_decode((string) ($a['attivita_json'] ?? ''), true) ?: [])];
        }
        foreach ($reg as $i => $c) {
            $reg[$i]['integrazioni'] = $per[$c['id']] ?? [];
        }

        return $reg;
    }

    /**
     * Le compilazioni dell'istituto (anche aperte e mai compilate se avviate dall'ufficio), con i file e i messaggi, a gruppi.
     *
     * @return list<array<string, mixed>>
     */
    private function compilazioni(string $istituto): array
    {
        $righe = $this->db->righe(
            'SELECT cc.* FROM convenzioni_compilate cc
             WHERE ' . ServizioScuole::sqlIstituto('s', 'cc.scuola_codice') . " = ?
               AND ((cc.dati_json IS NOT NULL AND cc.dati_json <> '') OR IFNULL(cc.stato_iter, '') <> '' OR cc.avviata_il IS NOT NULL) ORDER BY cc.id DESC",
            [$istituto]
        );
        if (!$righe) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $righe);
        $messaggi = [];
        foreach ($this->db->righe('SELECT id, compilazione_id, da, tipo, testo, autore, creato_il, letto_il FROM convenzioni_messaggi WHERE compilazione_id IN (' . implode(',', $ids) . ') ORDER BY creato_il DESC, id DESC LIMIT 200') as $m) {
            $messaggi[(int) $m['compilazione_id']][] = ['id' => (int) $m['id'], 'da' => (string) $m['da'], 'tipo' => (string) $m['tipo'], 'testo' => (string) $m['testo'], 'autore' => (string) $m['autore'],
                'creato_il' => (string) $m['creato_il'], 'non_letto' => (string) $m['da'] === MessaggiConvenzioneRepository::SCUOLA && empty($m['letto_il'])];
        }
        $out = [];
        foreach ($righe as $r) {
            $id = (int) $r['id'];
            $stato = (string) $r['stato_iter'];
            $dati = json_decode((string) ($r['dati_json'] ?? ''), true);
            $attivita = is_array($dati) ? (array) ($dati['attivita'] ?? []) : [];
            $compilata = is_array($dati) && $dati !== [];
            $file = static fn (string $nome, string $quale, ?string $data): array => ['nome' => $nome, 'quale' => $quale, 'data' => (string) ($data ?? '')];
            $files = [];
            if (!empty($r['file_firmato'])) {
                $files[] = $file('Convenzione firmata dalla scuola', 'scuola', $r['firmata_il']);
            }
            if (!empty($r['file_allegato_scuola'])) {
                $files[] = $file('Allegato A', 'allegato', $r['aggiornata_il']);
            }
            if (!empty($r['file_definitivo'])) {
                $files[] = $file('Firmata dal Direttore', 'dir', $r['firmata_direttore_il']);
            }
            if (!empty($r['file_originale'])) {
                $files[] = $file('Originale da Titulus', 'orig', $r['protocollata_il']);
            }
            if (!empty($r['file_protocollato'])) {
                $files[] = $file('Documento protocollato', 'prot', $r['protocollata_il']);
            }
            $m = $messaggi[$id] ?? [];
            $attesa = array_values(array_filter((array) (json_decode((string) ($r['attivita_in_attesa'] ?? ''), true) ?: []), static fn ($x): bool => is_array($x) && empty($x['ok']) && (int) ($x['pr'] ?? 0) > 0));
            $passo = StatoConvenzioneScuola::passo(['id' => $id, 'stato_iter' => $stato, 'compilata' => $compilata]);
            $out[] = $passo + [
                'id' => $id, 'email' => (string) $r['email'], 'stato_iter' => $stato, 'compilata' => $compilata,
                'in_corso' => !in_array($stato, [Costanti::ITER_PROTOCOLLATA, Costanti::ITER_UNITA], true),
                'unita_in' => (int) ($r['unita_in'] ?? 0), 'attivita' => count($attivita), 'in_attesa' => count($attesa),
                'da_confermare' => array_map(static fn (array $x): array => ['pr' => (int) $x['pr'], 'tutor' => (string) ($x['tutor'] ?? '')], $attesa),
                'avviata_il' => (string) ($r['avviata_il'] ?? ''), 'avviata_da' => (string) ($r['avviata_da'] ?? ''), 'aggiornata_il' => (string) ($r['aggiornata_il'] ?? ''),
                'protocollo_ingresso' => (string) ($r['protocollo_ingresso'] ?? ''), 'protocollo' => (string) ($r['protocollo'] ?? ''),
                'convenzione_id' => (int) ($r['convenzione_id'] ?? 0), 'nota_ufficio' => (string) ($r['nota_ufficio'] ?? ''), 'files' => $files,
                'messaggi' => $m, 'non_letti' => count(array_filter($m, static fn (array $x): bool => $x['non_letto'])),
                'archiviata' => !empty($r['msg_archiviata_il']),
                'docenti_json' => array_values(array_filter((array) (json_decode((string) ($r['docenti_json'] ?? ''), true) ?: []), 'is_array')),
                'riga' => $r,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $registro
     * @return array{tutte: list<array<string, mixed>>, mostrate: list<array<string, mixed>>, anni: list<int>, scoperte: int, fsl_in_essere: int, n_elenchi: int, n_autorizzazioni: int}
     */
    private function prenotazioni(string $chiave, string $istituto, bool $aMano, array $registro, string $oggi, int $anno, bool $conNumeri, ?callable $puoStudenti, string $sedePrincipale = '', bool $inStipula = false): array
    {
        $sql = ServizioScuole::sqlIstituto('sc', 'pr.scuola_codice');
        $valutazione = $conNumeri ? 'v.id AS val_id, v.media AS val_media' : '0 AS val_id, NULL AS val_media';
        $joinVal = $conNumeri ? 'LEFT JOIN valutazioni_fsl v ON v.prenotazione_id = pr.id' : '';
        $elenco = $conNumeri ? '(SELECT COUNT(*) FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id AND pp.escluso = 0)' : '0';
        $righe = $this->db->righe(
            "SELECT pr.id, pr.codice_prenotazione, pr.nome, pr.cognome, pr.email, pr.stato, pr.convenzione, pr.dati_custom_json, pr.data_prenotazione, pr.solo_orientamento,
                    pr.scuola_codice, pr.attestato_inviato, pr.autorizzazione_file, pr.autorizzazione_nome,
                    t.data_turno, t.nome_turno, t.evento_id, e.titolo, e.tipo AS evento_tipo, e.pagina_id, pe.titolo AS area,
                    pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine, IFNULL(pd.convenzione, 0) AS pd_conv, IFNULL(pd.attestati, 0) AS pd_attestati,
                    sc.denominazione AS sede_nome, sc.comune AS sede_comune, $elenco AS n_elenco, $valutazione
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id JOIN pagine_eventi pe ON e.pagina_id = pe.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id LEFT JOIN scuole sc ON sc.codice = pr.scuola_codice $joinVal
             WHERE $sql = ?" . ($aMano ? ' AND IFNULL(pd.convenzione, 0) = 1 AND pr.solo_orientamento = 0' : '') . '
             ORDER BY COALESCE(pd.data_inizio, t.data_turno, pr.data_prenotazione) DESC, pr.id DESC',
            [$aMano ? '' : $istituto]
        );
        $tutte = [];
        $anni = [];
        $scoperte = 0;
        $fslInEssere = 0;
        $nElenchi = 0;
        $nAutorizzazioni = 0;
        $documenti = new DocumentiClasse($this->orologio);
        $puo = [];
        foreach ($righe as $x) {
            $scritta = ServizioScuole::nomeScrittoNelModulo($x);
            if ($aMano && 'x' . substr(md5(mb_strtolower(trim($scritta) ?: 'p' . $x['id'])), 0, 12) !== $chiave) {
                continue;
            }
            [$dal, $al] = $this->periodi->prenotazione($x);
            $stato = (string) ($x['stato'] ?: 'confermata');
            $attiva = !in_array($stato, self::STATI_NON_ATTIVI, true);
            $fsl = (int) $x['pd_conv'] === 1 && (int) $x['solo_orientamento'] === 0;
            $conclusa = $al < $oggi;
            $copre = $fsl ? StatoConvenzioneScuola::copre($registro, $dal, $al) : null;
            $custom = json_decode((string) ($x['dati_custom_json'] ?? ''), true) ?: [];
            $classe = (int) $x['pd_attestati'] === 1 && $attiva;
            $paginaId = (int) $x['pagina_id'];
            if ($puoStudenti !== null && !isset($puo[$paginaId])) {
                $puo[$paginaId] = (bool) $puoStudenti($paginaId);   // una volta per area
            }
            $vedeIscritti = $puo[$paginaId] ?? false;
            $vedeStudenti = $classe && $conNumeri && $vedeIscritti;
            $a = self::annoDi($dal);
            $anni[$a] = $a;
            if ($fsl && $attiva && !$conclusa) {
                $fslInEssere++;
                if ($copre === null) {
                    $scoperte++;
                }
            }
            $manca = $conNumeri ? DocumentiMancanti::da($documenti, ['classe' => $classe, 'fsl' => $fsl, 'stato' => $stato, 'dal' => $dal, 'al' => $al, 'data_prenotazione' => (string) ($x['data_prenotazione'] ?? ''),
                'n_elenco' => (int) $x['n_elenco'], 'autorizzazione' => !empty($x['autorizzazione_file'])], $oggi) : ['elenco' => false, 'autorizzazione' => false];
            $nElenchi += $manca['elenco'] ? 1 : 0;
            $nAutorizzazioni += $manca['autorizzazione'] ? 1 : 0;
            $tutte[] = [
                'elenco_manca' => $manca['elenco'], 'autorizzazione_manca' => $manca['autorizzazione'],
                'id' => (int) $x['id'], 'codice' => (string) $x['codice_prenotazione'], 'titolo' => (string) $x['titolo'], 'evento_id' => (int) $x['evento_id'], 'pagina_id' => $paginaId,
                'area' => (string) $x['area'], 'turno' => ($x['evento_tipo'] ?? '') === 'progetto' ? trim((string) $x['nome_turno']) : '',
                'dal' => $dal, 'al' => $al, 'anno' => $a, 'stato' => $stato, 'stato_testo' => self::STATI_TESTO[$stato] ?? $stato, 'attiva' => $attiva, 'conclusa' => $conclusa,
                'sede_nome' => (string) ($x['sede_nome'] ?? ''), 'sede_comune' => (string) ($x['sede_comune'] ?? ''), 'sede_diversa' => !$aMano && self::codicePulito((string) $x['scuola_codice']) !== '' && self::codicePulito((string) $x['scuola_codice']) !== $istituto && self::codicePulito((string) $x['scuola_codice']) !== self::codicePulito($sedePrincipale),
                'docente' => trim($x['nome'] . ' ' . $x['cognome']), 'email' => strtolower(trim((string) $x['email'])), 'telefono' => self::telefono($custom),
                'fsl' => $fsl, 'solo_orientamento' => (int) $x['solo_orientamento'] === 1,
                'conv' => !$fsl ? 'non_richiesta' : ($copre ? 'coperta' : 'non_coperta'), 'conv_id' => $copre ? (int) $copre['id'] : 0,
                'in_stipula' => $fsl && !$copre && $inStipula,
                'perche' => $fsl && !$copre ? ($inStipula ? "si sta stipulando la convenzione: finché non è registrata l'attività non è coperta" : StatoConvenzioneScuola::perche($registro, $dal, $al)) : '',
                'studenti' => $conNumeri ? (int) ($custom[\CAMPO_PARTECIPANTI] ?? 0) : null,
                'n_elenco' => $conNumeri ? (int) $x['n_elenco'] : null, 'classe' => $classe,
                'puo_iscritti' => $vedeIscritti, 'puo_studenti' => $vedeStudenti, 'ricorda_elenco' => $classe && $conNumeri && $stato === 'confermata' && (int) $x['n_elenco'] === 0,
                'autorizzazione' => !empty($x['autorizzazione_file']), 'attestati' => !$classe ? 'no' : ((int) $x['attestato_inviato'] === 1 ? 'inviati' : 'da_inviare'),
                'val_id' => (int) $x['val_id'], 'val_media' => $x['val_media'] !== null ? (string) $x['val_media'] : '',
                'scuola_scritta' => $scritta,
            ];
        }
        rsort($anni);
        $mostrate = $anno > 0 ? array_values(array_filter($tutte, static fn (array $p): bool => $p['anno'] === $anno)) : $tutte;

        return ['tutte' => $tutte, 'mostrate' => $mostrate, 'anni' => array_values($anni), 'scoperte' => $scoperte, 'fsl_in_essere' => $fslInEssere, 'n_elenchi' => $nElenchi, 'n_autorizzazioni' => $nAutorizzazioni];
    }

    /**
     * I docenti della scuola, uno per persona: chi ha prenotato, i docenti delle convenzioni, quelli scritti dall'ufficio nella rubrica e chi ha la scuola nel profilo.
     * Si uniscono per email (e senza email per nome); il telefono e il nome corretti dall'ufficio nella rubrica hanno la precedenza.
     *
     * @param list<array<string, mixed>> $prenotazioni tutte
     * «Nell'Allegato A» = è tra i docenti di una convenzione valida (non scaduta) o nell'elenco della compilazione in corso: quello salvato dall'ufficio (quello che il
     * modulo di controllo propone ma non è ancora salvato è «proposto», non «nell'Allegato A»). Il confronto è per email e poi
     * per nome (aggiungi()), non solo per quello che c'è nel modulo compilato dalla scuola.
     *
     * @param list<array<string, mixed>> $registro
     * @param list<array<string, mixed>> $iterAperti compilazioni in corso, come da IterNellaScheda::arricchisci (con docenti_iter)
     * @return list<array<string, mixed>>
     */
    private function docenti(string $istituto, array $prenotazioni, array $registro, array $iterAperti, string $oggi, string $tra): array
    {
        $per = [];
        $chiaveNome = static function (string $n): string {
            $senzaTitoli = (string) preg_replace('/\b(prof|dott|ins|dirigente)(\.ssa|\.)?(?=\s|$)/iu', '', $n);
            $parole = array_filter(explode(' ', mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $senzaTitoli)))));
            sort($parole);   // «Rossi Mario» e «Mario Rossi» sono la stessa persona

            return 'n:' . implode(' ', $parole);
        };
        $aggiungi = function (string $nome, string $email, string $telefono, string $fonte) use (&$per, $chiaveNome): string {
            $email = strtolower(trim($email));
            $k = $email !== '' ? $email : $chiaveNome($nome);
            if ($k === '' || $k === 'n:') {
                return '';
            }
            if ($email !== '' && !isset($per[$k]) && $chiaveNome($nome) !== 'n:') {
                // chi era noto solo per nome e ora ha l'email è la stessa persona: si completa, non si raddoppia
                foreach ($per as $kk => $p) {
                    if ($p['email'] === '' && $chiaveNome($p['nome']) === $chiaveNome($nome)) {
                        $per[$k] = $p;
                        unset($per[$kk]);
                        break;
                    }
                }
            }
            // un docente senza email che coincide per nome con uno già noto si unisce a lui
            if ($email === '') {
                foreach ($per as $kk => $p) {
                    if ($chiaveNome($p['nome']) === $k) {
                        $k = $kk;
                        break;
                    }
                }
            }
            $per[$k] ??= ['nome' => '', 'email' => '', 'telefono' => '', 'fonti' => [], 'eventi' => [], 'in_allegato' => false, 'proposto' => false, 'ultima' => '', 'prenotazione' => 0];
            if ($per[$k]['nome'] === '' && trim($nome) !== '') {
                $per[$k]['nome'] = trim($nome);
            }
            if ($per[$k]['email'] === '' && $email !== '') {
                $per[$k]['email'] = $email;
            }
            if ($per[$k]['telefono'] === '' && trim($telefono) !== '') {
                $per[$k]['telefono'] = trim($telefono);
            }
            $per[$k]['fonti'][$fonte] = $fonte;

            return $k;
        };
        foreach ($prenotazioni as $p) {
            $k = $aggiungi($p['docente'], $p['email'], $p['telefono'], 'prenotazioni');
            if ($k !== '' && $p['attiva']) {
                $per[$k]['eventi'][$p['evento_id']] = true;
                $per[$k]['prenotazione'] = $per[$k]['prenotazione'] ?: $p['id'];   // la più recente: serve per scrivere a quel docente
            }
        }
        foreach ($registro as $c) {
            if (!in_array(ServizioDocumentiScuola::stato($c, $oggi, $tra), ['valida', 'in_scadenza'], true)) {
                continue;   // una convenzione scaduta (o non ancora valida) non mette nessuno «nell'Allegato A» di adesso
            }
            foreach (json_decode((string) ($c['docenti_json'] ?? ''), true) ?: [] as $d) {
                $k = $aggiungi((string) ($d['nome'] ?? ''), (string) ($d['email'] ?? ''), '', 'allegato');
                if ($k !== '') {
                    $per[$k]['in_allegato'] = true;
                }
            }
        }
        foreach ($iterAperti as $c) {
            foreach ((array) ($c['docenti_salvati'] ?? []) as $d) {
                $k = $aggiungi((string) ($d['nome'] ?? ''), (string) ($d['email'] ?? ''), '', 'allegato');
                if ($k !== '') {
                    $per[$k]['in_allegato'] = true;
                }
            }
            foreach ((array) ($c['docenti_iter'] ?? []) as $d) {   // quelli che il modulo propone e l'ufficio non ha ancora salvato
                $k = $aggiungi((string) ($d['nome'] ?? ''), (string) ($d['email'] ?? ''), '', 'proposto');
                if ($k !== '' && !$per[$k]['in_allegato']) {
                    $per[$k]['proposto'] = true;
                }
            }
        }
        foreach ($istituto !== '' ? $this->db->righe("SELECT nome, cognome, email FROM utenti WHERE scuola_codice IN (SELECT codice FROM scuole WHERE codice = ? OR istituto_codice = ?) AND email IS NOT NULL AND email <> ''", [$istituto, $istituto]) : [] as $u) {
            $aggiungi(trim((string) ($u['nome'] ?? '') . ' ' . (string) ($u['cognome'] ?? '')), (string) $u['email'], '', 'profilo');
        }
        // La rubrica dell'ufficio corregge nome e telefono (stessa email)
        $rubrica = $istituto !== '' ? $this->rubrica->dellIstituto($istituto) : [];
        foreach ($rubrica as $r) {
            $k = $aggiungi($r['nome'], $r['email'], $r['telefono'], 'rubrica');
            if ($k !== '') {
                $per[$k]['nome'] = $r['nome'] !== '' ? $r['nome'] : $per[$k]['nome'];
                $per[$k]['telefono'] = $r['telefono'] !== '' ? $r['telefono'] : $per[$k]['telefono'];
            }
        }
        // Ultimo contatto: l'ultima email partita per quell'indirizzo (registro degli invii), con una query sola
        $emails = array_values(array_filter(array_column($per, 'email')));
        $ultimi = [];
        if ($emails) {
            try {
                foreach ($this->db->righe(
                    'SELECT LOWER(destinatario) AS d, MAX(created_at) AS ultimo FROM log_email WHERE esito = 1 AND created_at >= NOW() - INTERVAL 1 YEAR AND LOWER(destinatario) IN (' . implode(',', array_fill(0, count($emails), '?')) . ') GROUP BY d',
                    $emails
                ) as $l) {
                    $ultimi[(string) $l['d']] = (string) $l['ultimo'];
                }
            } catch (\Throwable) {
                // il registro degli invii si crea al primo invio: se manca, nessun contatto da mostrare
            }
        }
        $nomi = ['prenotazioni' => 'ha prenotato un\'attività', 'allegato' => "è indicato nell'Allegato A", 'proposto' => 'nel modulo di controllo della convenzione', 'profilo' => 'ha questa scuola nel suo profilo', 'rubrica' => 'aggiunto dall\'ufficio'];
        $out = [];
        foreach ($per as $p) {
            $out[] = [
                'nome' => $p['nome'] !== '' ? $p['nome'] : $p['email'], 'email' => $p['email'], 'telefono' => $p['telefono'], 'eventi' => count($p['eventi']),
                'in_allegato' => $p['in_allegato'], 'proposto' => $p['proposto'], 'ultimo_contatto' => $ultimi[$p['email']] ?? '', 'fonte' => implode(', ', array_map(static fn (string $f): string => $nomi[$f] ?? $f, $p['fonti'])),
                'modificabile' => $p['email'] !== '', 'senza_email' => $p['email'] === '',
                'prenotazione_id' => $p['email'] !== '' && filter_var($p['email'], FILTER_VALIDATE_EMAIL) !== false ? (int) $p['prenotazione'] : 0,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['eventi'], mb_strtolower($a['nome'])] <=> [$a['eventi'], mb_strtolower($b['nome'])]);

        return $out;
    }

    /**
     * I recapiti che l'anagrafe del Ministero non ha ma la scuola ha scritto lei: PEC ed email nell'ultima convenzione compilata, il telefono su una prenotazione.
     * Si danno solo per ciò che nell'anagrafe manca, e si dice che li ha indicati la scuola.
     *
     * @param array<string, mixed>|null $anagrafe
     * @param list<array<string, mixed>> $compilazioni dalla più recente
     * @param list<array<string, mixed>> $prenotazioni
     * @return array{pec: string, email: string, telefono: string}
     */
    private function contattiIndicati(?array $anagrafe, array $compilazioni, array $prenotazioni): array
    {
        $out = ['pec' => '', 'email' => '', 'telefono' => ''];
        foreach ($compilazioni as $c) {
            $scuola = (json_decode((string) (($c['riga']['dati_json'] ?? '')), true) ?: [])['scuola'] ?? [];
            foreach (['pec', 'email'] as $k) {
                $v = trim((string) ($scuola[$k] ?? ''));
                if ($out[$k] === '' && $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) !== false && trim((string) ($anagrafe[$k] ?? '')) === '') {
                    $out[$k] = $v;
                }
            }
        }
        foreach ($prenotazioni as $p) {
            if ($out['telefono'] === '' && $p['telefono'] !== '' && trim((string) ($anagrafe['telefono'] ?? '')) === '') {
                $out['telefono'] = $p['telefono'];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $custom risposte del modulo di prenotazione */
    private static function telefono(array $custom): string
    {
        foreach ($custom as $k => $v) {
            if (preg_match('/telefon|cellul/i', (string) $k) && is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }

    private static function codicePulito(string $codice): string
    {
        return strtoupper(trim($codice));
    }

    private static function annoDi(string $data): int
    {
        return ElencoScuoleFsl::annoScolastico($data);
    }
}
