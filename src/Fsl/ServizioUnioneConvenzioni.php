<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Core\Sito;
use App\Email\InvioEmail;

/**
 * Unione di due compilazioni «in corso» della stessa scuola (le doppie che c'erano prima della compilazione unica per istituto).
 * L'ufficio sceglie quella che resta: le attività e i messaggi dell'altra passano lì; l'altra diventa «unita» (unita_in) e il suo vecchio link
 * porta a una pagina che lo dice, senza mostrare il link di quella che resta. Niente si cancella.
 */
final class ServizioUnioneConvenzioni
{
    /** Stati da cui non si unisce più: già conclusa o dal Direttore per la firma. */
    private const NON_UNIBILI = [Costanti::ITER_PROTOCOLLATA, Costanti::ITER_UNITA, Costanti::ITER_DA_FIRMARE, Costanti::ITER_FIRMATA];

    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private MessaggiConvenzioneRepository $messaggi,
        private ServizioConvenzioneOnline $online,
        private ServizioScuole $scuole,
        private InvioEmail $invio,
        private Sito $sito
    ) {
    }

    /**
     * Una compilazione ha un file firmato «vivo»: caricato dalla scuola e non rifiutato (un file rifiutato è da rifare, non conta).
     *
     * @param array<string, mixed> $c
     */
    private static function haFileFirmato(array $c): bool
    {
        return !empty($c['file_firmato']) && (string) ($c['stato_iter'] ?? '') !== Costanti::ITER_RIFIUTATA;
    }

    /**
     * Si assorbe solo una compilazione senza file firmato e senza protocollo in entrata: stato vuoto, oppure rifiutata prima del protocollo.
     *
     * @param array<string, mixed> $c
     */
    private static function assorbibile(array $c): bool
    {
        return !self::haFileFirmato($c) && trim((string) ($c['protocollo_ingresso'] ?? '')) === '' && in_array((string) ($c['stato_iter'] ?? ''), ['', Costanti::ITER_RIFIUTATA], true);
    }

    /**
     * Unisce la compilazione $assorbita in $sopravvive. Ritorna il messaggio di errore, null se è andata a buon fine. Tutto sotto il blocco per
     * istituto, con le due righe rilette: se il blocco è occupato non si fa niente.
     */
    public function unisci(int $sopravvive, int $assorbita): ?string
    {
        if ($sopravvive <= 0 || $assorbita <= 0 || $sopravvive === $assorbita) {
            return 'Scegli due compilazioni diverse.';
        }
        $a = $this->compilate->perId($sopravvive);
        $b = $this->compilate->perId($assorbita);
        if (!$a || !$b) {
            return 'Una delle due compilazioni non esiste più.';
        }
        $istituto = $this->scuole->codiceIstituto((string) ($a['scuola_codice'] ?? ''));
        if ($istituto === '' || $istituto !== $this->scuole->codiceIstituto((string) ($b['scuola_codice'] ?? ''))) {
            return "Si uniscono solo compilazioni dello stesso istituto (con il codice meccanografico dell'anagrafe).";
        }
        try {
            $errore = $this->compilate->sottoBlocco($istituto, function () use ($sopravvive, $assorbita, &$a, &$b): ?string {
                // Riletto sotto il blocco: nel frattempo una delle due può essere cambiata (file caricato, presa in carico, un'altra unione)
                $a = $this->compilate->perId($sopravvive) ?: $a;
                $b = $this->compilate->perId($assorbita) ?: $b;
                foreach ([$a, $b] as $c) {
                    if (in_array((string) ($c['stato_iter'] ?? ''), self::NON_UNIBILI, true)) {
                        return 'Non si unisce una compilazione già protocollata, già unita o dal Direttore per la firma.';
                    }
                }
                if (!self::assorbibile($b)) {
                    if (self::haFileFirmato($a) && self::haFileFirmato($b)) {
                        return "Tutte e due hanno già un file firmato caricato dalla scuola: prima rifiutane una dall'iter di firma (con il motivo, che la scuola riceve per email), poi unisci.";
                    }
                    if (self::haFileFirmato($b)) {
                        return "La compilazione da assorbire ha già un file firmato caricato dalla scuola: scegli l'altra come quella che resta.";
                    }

                    return 'La compilazione da assorbire ha già un protocollo in entrata: non si assorbe. Scegli l\'altra come quella che resta.';
                }
                if (ConvenzioneCompilataRepository::eRivendicata($b) && !ConvenzioneCompilataRepository::eRivendicata($a)) {
                    return "La compilazione da assorbire è già stata rivendicata dalla sua scuola con un'email verificata: scegli quella come la compilazione che resta.";
                }

                // Il cambio di stato per primo, e tutto in una transazione: se non riesce (nel frattempo è cambiata) non si sposta niente (né dati, né messaggi)
                return $this->compilate->transazione(function () use ($sopravvive, $assorbita, $a, $b): ?string {
                    if (!$this->compilate->segnaUnita($assorbita, $sopravvive)) {
                        return "La compilazione da assorbire è cambiata nel frattempo (è già protocollata o unita): non ho spostato niente. Ricarica la pagina.";
                    }
                    $this->spostaAttivita($a, $b);
                    $this->messaggi->sposta($assorbita, $sopravvive);
                    // Chi aveva la compilazione assorbita resta tra chi può aprire quella che resta: l'elenco esplicito si completa qui
                    $this->compilate->salvaProprietari($sopravvive, array_merge($this->compilate->proprietari($a), $this->compilate->proprietari($b), [(string) ($b['email'] ?? '')]));   // l'email di chi l'aveva fatta apre solo con un account verificato

                    return null;
                });
            });
        } catch (BloccoOccupato $e) {
            return $e->getMessage();
        }
        if ($errore !== null) {
            return $errore;
        }
        // L'indirizzo della compilazione unita riceve il link giusto: quello di quella che resta
        $email = (string) ($b['email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $dati = json_decode((string) ($a['dati_json'] ?? ''), true) ?: [];
            $datiB = json_decode((string) ($b['dati_json'] ?? ''), true) ?: [];
            $nome = (string) (($dati['scuola']['denominazione'] ?? '') ?: ($datiB['scuola']['denominazione'] ?? '') ?: 'la tua scuola');
            $this->invio->invia($email, 'fsl_compilazione_unita', ['scuola' => $nome], $this->sito->urlBase() . '/convenzione_online.php?t=' . $a['token']);
        }

        return null;
    }

    /**
     * Le attività (confermate e da confermare) e, se mancano, i dati e il logo di $da passano in $in. Senza doppioni. Niente si toglie a $da.
     *
     * @param array<string, string|null> $in
     * @param array<string, string|null> $da
     */
    private function spostaAttivita(array $in, array $da): void
    {
        $datiIn = json_decode((string) ($in['dati_json'] ?? ''), true);
        $datiDa = json_decode((string) ($da['dati_json'] ?? ''), true);
        if (is_array($datiDa)) {
            $nuovi = is_array($datiIn) ? $datiIn : ['scuola' => (array) ($datiDa['scuola'] ?? []), 'attivita' => [], 'origine' => (string) ($datiDa['origine'] ?? 'unione'), 'convenzione' => (string) ($datiDa['convenzione'] ?? '')];
            $chiave = static fn (array $x): string => !empty($x['pr']) ? 'pr' . (int) $x['pr'] : 'ev' . (int) ($x['ev'] ?? 0);
            $presenti = array_map($chiave, (array) ($nuovi['attivita'] ?? []));
            $adesso = date('Y-m-d H:i:s');
            foreach ((array) ($datiDa['attivita'] ?? []) as $x) {
                if (is_array($x) && !in_array($chiave($x), $presenti, true)) {
                    $nuovi['attivita'][] = $x + ['il' => $adesso];
                    $presenti[] = $chiave($x);
                }
            }
            if (!is_array($datiIn)) {
                $this->compilate->salvaDati((int) $in['id'], (string) json_encode($nuovi, JSON_UNESCAPED_UNICODE), $this->logoDa($in, $da), (string) ($in['email'] ?? ''));
            } else {
                $this->compilate->salvaAttivita((int) $in['id'], (string) json_encode($nuovi, JSON_UNESCAPED_UNICODE));
                if (empty($in['logo']) && ($logo = $this->logoDa($in, $da)) !== null) {
                    $this->compilate->salvaLogo((int) $in['id'], $logo);
                }
            }
        }
        // Le attività «da confermare» dell'altra passano come sono (chi ha il link di quella che resta decide)
        $attesaIn = $this->online->inAttesaGrezze($in);
        $note = array_map(static fn (array $x): int => (int) ($x['pr'] ?? 0), $attesaIn);
        $aggiunte = false;
        foreach ($this->online->inAttesaGrezze($da) as $x) {
            if (!in_array((int) ($x['pr'] ?? 0), $note, true)) {
                $attesaIn[] = $x;
                $aggiunte = true;
            }
        }
        if ($aggiunte) {
            $this->compilate->salvaInAttesa((int) $in['id'], (string) json_encode($attesaIn, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Il logo di $da come copia (un file suo per ogni compilazione, così cancellarlo da una non toglie l'altro). Null se $in ne ha già uno o non c'è.
     *
     * @param array<string, string|null> $in
     * @param array<string, string|null> $da
     */
    private function logoDa(array $in, array $da): ?string
    {
        $vecchio = (string) ($da['logo'] ?? '');
        $radice = $this->sito->radice() . '/';
        if (!empty($in['logo']) || $vecchio === '' || !is_file($radice . $vecchio)) {
            return !empty($in['logo']) ? (string) $in['logo'] : null;
        }
        $copia = dirname($vecchio) . '/' . bin2hex(random_bytes(16)) . '.' . strtolower(pathinfo($vecchio, PATHINFO_EXTENSION));

        return @copy($radice . $vecchio, $radice . $copia) ? $copia : null;
    }
}
