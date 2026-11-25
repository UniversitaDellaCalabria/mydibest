<?php

declare(strict_types=1);

namespace App\Fsl;

/**
 * Lo stato della convenzione di un istituto, calcolato UNA volta sola per l'elenco e per la scheda della pagina «Scuole» (altrimenti le due schermate
 * potrebbero dire cose diverse della stessa scuola). Funzioni pure: lavora su righe già lette, non interroga il database.
 *
 * Stato: attiva | in_scadenza (entro 60 giorni) | futura (inizia dopo oggi) | scaduta | in_corso (non c'è una valida e una compilazione è a metà) | manca.
 * La copertura di un periodo è la regola di ConvenzioneRepository::valida(): data_stipula vuota o <= dal, scadenza vuota o >= al.
 */
final class StatoConvenzioneScuola
{
    public const STATI = [
        'attiva' => 'Attiva', 'in_scadenza' => 'In scadenza', 'scaduta' => 'Scaduta', 'in_corso' => 'In corso', 'futura' => 'Non ancora valida', 'manca' => 'Manca',
    ];

    /**
     * @param list<array<string, mixed>> $registro convenzioni dell'istituto (id, data_stipula, scadenza)
     * @param list<array<string, mixed>> $compilazioni compilazioni in corso (id, stato_iter, compilata)
     * @return array{stato: string, scadenza: string, ha_valida: bool, migliore: ?array<string, mixed>, iter: ?array{id: int, passo: string, cosa: string, tag: string, peso: int}, in_corso_senza_valida: bool}
     */
    public static function da(array $registro, array $compilazioni, string $oggi, string $tra): array
    {
        $per = [];
        foreach ($registro as $c) {
            $per[ServizioDocumentiScuola::stato($c, $oggi, $tra)][] = $c;
        }
        $vigore = $per['valida'] ?? $per['in_scadenza'] ?? [];
        $stato = 'manca';
        $scadenza = '';
        $migliore = null;
        if ($vigore) {
            $stato = isset($per['valida']) ? 'attiva' : 'in_scadenza';
            $date = array_filter(array_column($vigore, 'scadenza'));
            $scadenza = count($date) === count($vigore) ? (string) max($date) : '';   // una senza scadenza vale per sempre
            usort($vigore, static fn (array $a, array $b): int => [empty($b['scadenza']) ? 1 : 0, (string) $b['scadenza'], (int) $b['id']] <=> [empty($a['scadenza']) ? 1 : 0, (string) $a['scadenza'], (int) $a['id']]);
            $migliore = $vigore[0];
        } elseif (isset($per['futura'])) {
            $stato = 'futura';
            $scadenza = (string) min(array_map(static fn (array $c): string => (string) $c['data_stipula'], $per['futura']));
        } elseif ($registro) {
            $stato = 'scaduta';
            $date = array_filter(array_column($registro, 'scadenza'));
            $scadenza = $date ? (string) max($date) : '';
        }

        // La compilazione che «occupa» l'istituto è quella con il passo più avanti
        $iter = null;
        foreach ($compilazioni as $x) {
            $p = self::passo($x);
            // a parità di passo vale la compilazione con l'id più basso: lo stesso ordine di cose(), così la scheda e l'elenco parlano della stessa
            if ($iter === null || $p['peso'] > $iter['peso'] || ($p['peso'] === $iter['peso'] && $p['id'] < $iter['id'])) {
                $iter = $p;
            }
        }
        $senzaValida = $iter !== null && !$vigore;
        if ($senzaValida) {
            $stato = 'in_corso';
        }

        return ['stato' => $stato, 'scadenza' => $scadenza, 'ha_valida' => (bool) $vigore, 'migliore' => $migliore, 'iter' => $iter, 'in_corso_senza_valida' => $senzaValida];
    }

    /** I filtri «Cosa devo fare» dell'elenco (e le tessere in alto): chiave => testo. Le stesse chiavi di cose(). */
    public const COSE = [
        'da_controllare' => 'Da controllare',
        'esito_incerto' => 'Esito incerto',
        'in_approvazione' => 'In approvazione',
        'da_protocollare' => 'Da protocollare',
        'da_registrare' => 'Da registrare',
        'dal_direttore' => 'Dal Direttore',
        'attivita_da_confermare' => 'Attività da confermare',
        'integrazioni' => 'Allegato A da esaminare',
        'doppie' => 'Compilazioni doppie',
        'messaggi' => 'Messaggi da leggere',
        'elenco_mancante' => 'Elenco studenti mancante',
        'autorizzazione_mancante' => 'Autorizzazione mancante',
        'senza_convenzione' => 'Senza convenzione',
        'scaduta_nessuna_attivita' => 'Scaduta, nessuna attività',
        'a_mano' => 'Scuola scritta a mano',
    ];

    /** Le cose da fare che riguardano i numeri e i documenti degli studenti: le vede solo chi gestisce tutta la FSL, non chi ha solo le convenzioni. */
    public const SOLO_FSL = ['elenco_mancante', 'autorizzazione_mancante'];

    /**
     * IL criterio di cosa c'è da fare per una scuola, uno solo per l'elenco, la scheda, il menu e il cruscotto (altrimenti le schermate direbbero cose diverse
     * della stessa scuola). Guarda TUTTE le compilazioni in corso dell'istituto, non solo la più avanti, e ogni tag compare una volta sola.
     *
     * Ogni compilazione: id, stato_iter, compilata, da_confermare (quante attività segnalate da altre email), titulus (libero | in_invio | incerto |
     * in_approvazione | precedente = Titulus ha già dato il numero: vale solo nei passi in cui si protocolla, gli altri non hanno niente da dire).
     *
     * @param list<array<string, mixed>> $compilazioni solo quelle in corso che contano (ConvenzioneCompilataRepository::conta)
     * @param array{stato: string, scadenza: string, n_messaggi: int, n_integrazioni: int, n_scoperte: int, a_mano: bool, n_elenchi?: int, n_autorizzazioni?: int} $scuola
     *        n_elenchi e n_autorizzazioni (DocumentiMancanti) sono 0 per chi non gestisce tutta la FSL
     * @return array{tags: list<string>, cosa: list<string>, attesa: string, da_fare: bool, voci: list<array{tag: string, testo: string, principale: bool}>}
     *         voci = le stesse cose una per una; «principale» = quella che l'azione in testata alla scheda già dice (non si ripete sotto)
     */
    public static function cose(array $compilazioni, array $scuola): array
    {
        $tags = [];
        $cosa = [];
        $voci = [];
        $aggiungi = static function (string $tag, string $testo, bool $principale = false) use (&$tags, &$cosa, &$voci): void {
            if (!in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
            $cosa[] = $testo;
            $voci[] = ['tag' => $tag, 'testo' => $testo, 'principale' => $principale];
        };
        $ordinate = $compilazioni;
        usort($ordinate, static fn (array $a, array $b): int => [self::passo($b)['peso'], (int) $a['id']] <=> [self::passo($a)['peso'], (int) $b['id']]);
        $attesa = '';
        foreach ($ordinate as $i => $c) {
            $p = self::passo($c);
            $primo = $i === 0;
            $titulus = in_array($p['tag'], ['da_controllare', 'da_protocollare'], true) ? (string) ($c['titulus'] ?? 'libero') : 'libero';
            if ($titulus === 'incerto') {
                $aggiungi('esito_incerto', 'Esito incerto in Titulus: cerca il documento', $primo);
            } elseif ($titulus === 'in_approvazione') {
                $aggiungi('in_approvazione', 'In approvazione in Titulus: scrivi il numero quando c\'è', $primo);
            } elseif ($p['tag'] === 'da_protocollare' && $titulus === 'precedente') {
                $aggiungi('da_registrare', 'Da registrare: Titulus ha già dato il numero', $primo);
            } elseif ($p['tag'] !== '') {
                $aggiungi($p['tag'], $p['cosa'], $primo);
            } elseif ($attesa === '') {
                $attesa = $p['cosa'];
            }
            if ((int) ($c['da_confermare'] ?? 0) > 0) {
                $aggiungi('attivita_da_confermare', (int) $c['da_confermare'] === 1 ? '1 attività da confermare' : (int) $c['da_confermare'] . ' attività da confermare');
            }
        }
        if (count($compilazioni) > 1) {
            $aggiungi('doppie', count($compilazioni) . ' compilazioni in corso: unisci le doppie');
        }
        if ($scuola['n_integrazioni'] > 0) {
            $aggiungi('integrazioni', $scuola['n_integrazioni'] === 1 ? 'Un Allegato A aggiornato da esaminare' : $scuola['n_integrazioni'] . ' Allegati A aggiornati da esaminare');
        }
        if ($scuola['n_messaggi'] > 0) {
            $aggiungi('messaggi', $scuola['n_messaggi'] . ($scuola['n_messaggi'] === 1 ? ' messaggio da leggere' : ' messaggi da leggere'));
        }
        if (($scuola['n_elenchi'] ?? 0) > 0) {
            $aggiungi('elenco_mancante', $scuola['n_elenchi'] === 1 ? "1 attività senza l'elenco degli studenti" : $scuola['n_elenchi'] . " attività senza l'elenco degli studenti");
        }
        if (($scuola['n_autorizzazioni'] ?? 0) > 0) {
            $aggiungi('autorizzazione_mancante', $scuola['n_autorizzazioni'] === 1 ? '1 attività senza autorizzazione' : $scuola['n_autorizzazioni'] . ' attività senza autorizzazione');
        }
        $n = $scuola['n_scoperte'];
        if ($scuola['a_mano']) {
            $tags[] = 'a_mano';
            if ($n > 0) {
                $tags[] = 'senza_convenzione';
            }
            $cosa[] = 'Abbina la scuola all\'anagrafe';
            $voci[] = ['tag' => 'a_mano', 'testo' => 'Abbina la scuola all\'anagrafe', 'principale' => true];
        } elseif ($n > 0 && !$compilazioni) {
            $aggiungi('senza_convenzione', ($scuola['stato'] === 'manca' ? 'Avvia la stipula' : 'Rinnova la convenzione') . ' (' . $n . ($n === 1 ? ' attività scoperta)' : ' attività scoperte)'), true);
        } elseif ($scuola['stato'] === 'in_scadenza' && !$compilazioni) {
            $cosa[] = 'Rinnova entro il ' . date('d/m/Y', (int) strtotime($scuola['scadenza']));
            $voci[] = ['tag' => '', 'testo' => end($cosa), 'principale' => true];
        } elseif ($scuola['stato'] === 'scaduta' && !$compilazioni && $n === 0) {
            // scaduta e senza attività da svolgere: non è urgente, ma non si dice «Niente da fare» di una convenzione che non vale più
            $tags[] = 'scaduta_nessuna_attivita';
        }

        return ['tags' => $tags, 'cosa' => $cosa, 'attesa' => $attesa, 'da_fare' => (bool) $cosa, 'voci' => $voci];
    }

    /**
     * L'azione principale in testata alla scheda: UN verbo che dice cosa fare ora e dove (l'ancora del riquadro). Il testo non è mai «Da prendere in carico» o
     * «Attendi…»: quelli sono stati, non azioni. Quando non tocca all'ufficio (il Direttore deve firmare, la scuola deve ancora compilare) non c'è un verbo
     * ma un'attesa.
     *
     * @param ?array{id: int, stato_iter: string, titulus?: array{stato?: string}} $iter la compilazione in corso più avanti (con lo stato di Titulus), se c'è
     * @return array{chiave: string, testo: string, ancora: string, compilazione: int}
     *         chiave = iter | sollecita | attesa | rinnova | avvia | nessuna
     */
    public static function azione(string $stato, ?array $iter, bool $aMano): array
    {
        if ($aMano) {
            return ['chiave' => 'nessuna', 'testo' => 'Abbina la scuola all\'anagrafe', 'ancora' => '', 'compilazione' => 0];
        }
        if ($iter !== null) {
            $id = (int) $iter['id'];
            $incerto = in_array((string) ($iter['titulus']['stato'] ?? ''), ['incerto', 'in_approvazione'], true);
            switch ((string) $iter['stato_iter']) {
                case Costanti::ITER_CARICATA:
                    return ['chiave' => 'iter', 'testo' => $incerto ? 'Verifica in Titulus' : 'Controlla la convenzione', 'ancora' => 'cv' . $id, 'compilazione' => $id];
                case Costanti::ITER_IN_CARICO:
                    return ['chiave' => 'iter', 'testo' => 'Manda al Direttore', 'ancora' => 'cv' . $id, 'compilazione' => $id];
                case Costanti::ITER_DA_FIRMARE:
                    return ['chiave' => 'attesa', 'testo' => 'Aspetta la firma del Direttore', 'ancora' => 'cv' . $id, 'compilazione' => $id];
                case Costanti::ITER_FIRMATA:
                    return ['chiave' => 'iter', 'testo' => $incerto ? 'Verifica in Titulus' : 'Protocolla e registra', 'ancora' => 'cv' . $id, 'compilazione' => $id];
                default:
                    return ['chiave' => 'sollecita', 'testo' => 'Scrivi alla scuola', 'ancora' => 'messaggi', 'compilazione' => $id];
            }
        }
        if ($stato === 'in_scadenza' || $stato === 'scaduta') {
            return ['chiave' => 'rinnova', 'testo' => 'Rinnova', 'ancora' => 'avvio', 'compilazione' => 0];
        }
        if ($stato === 'manca' || $stato === 'futura') {
            return ['chiave' => 'avvia', 'testo' => 'Avvia la stipula', 'ancora' => 'avvio', 'compilazione' => 0];
        }

        return ['chiave' => 'nessuna', 'testo' => 'Niente da fare ora', 'ancora' => '', 'compilazione' => 0];
    }

    /**
     * Una convenzione del registro copre tutto il periodo dal-al?
     *
     * @param list<array<string, mixed>> $registro
     */
    public static function copre(array $registro, string $dal, string $al): ?array
    {
        foreach ($registro as $c) {
            if ((empty($c['data_stipula']) || $c['data_stipula'] <= $dal) && (empty($c['scadenza']) || $c['scadenza'] >= $al)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Perché un periodo non è coperto, in una frase per l'ufficio.
     *
     * @param list<array<string, mixed>> $registro
     */
    public static function perche(array $registro, string $dal, string $al): string
    {
        if (!$registro) {
            return 'nessuna convenzione nel registro';
        }
        foreach ($registro as $c) {
            if (!empty($c['scadenza']) && $c['scadenza'] < $al && (empty($c['data_stipula']) || $c['data_stipula'] <= $dal)) {
                return 'la convenzione n. ' . (int) $c['id'] . ' scade il ' . date('d/m/Y', (int) strtotime((string) $c['scadenza'])) . ', prima della fine dell\'attività';
            }
        }
        foreach ($registro as $c) {
            if (!empty($c['data_stipula']) && $c['data_stipula'] > $dal) {
                return 'la convenzione n. ' . (int) $c['id'] . ' vale dal ' . date('d/m/Y', (int) strtotime((string) $c['data_stipula'])) . ', dopo l\'inizio dell\'attività';
            }
        }

        return 'nessuna convenzione copre tutto il periodo dell\'attività';
    }

    /**
     * Il passo di una compilazione in corso. tag = il filtro «Cosa devo fare» ('' = tocca alla scuola, non all'ufficio).
     *
     * @param array<string, mixed> $x
     * @return array{id: int, passo: string, cosa: string, tag: string, peso: int}
     */
    public static function passo(array $x): array
    {
        $id = (int) ($x['id'] ?? 0);

        return match ((string) ($x['stato_iter'] ?? '')) {
            Costanti::ITER_CARICATA => ['id' => $id, 'passo' => 'PDF firmato caricato', 'cosa' => 'Da prendere in carico', 'tag' => 'da_controllare', 'peso' => 5],
            Costanti::ITER_IN_CARICO, Costanti::ITER_DA_FIRMARE => ['id' => $id, 'passo' => 'Dal Direttore per la firma', 'cosa' => 'Attendi la firma del Direttore', 'tag' => 'dal_direttore', 'peso' => 6],
            Costanti::ITER_FIRMATA => ['id' => $id, 'passo' => 'Firmata dal Direttore', 'cosa' => 'Da protocollare e registrare', 'tag' => 'da_protocollare', 'peso' => 7],
            Costanti::ITER_RIFIUTATA => ['id' => $id, 'passo' => 'File rifiutato', 'cosa' => 'Attende il nuovo PDF dalla scuola', 'tag' => '', 'peso' => 4],
            default => !empty($x['compilata'])
                ? ['id' => $id, 'passo' => 'Compilata, manca il PDF firmato', 'cosa' => 'Attende il PDF firmato dalla scuola', 'tag' => '', 'peso' => 3]
                : ['id' => $id, 'passo' => 'Avviata, attende il referente', 'cosa' => 'Attende che il referente compili', 'tag' => '', 'peso' => 2],
        };
    }
}
