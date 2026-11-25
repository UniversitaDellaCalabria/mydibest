<?php

declare(strict_types=1);

namespace App\Fsl;

/**
 * IL criterio di «elenco degli studenti mancante» e «autorizzazione mancante» di una prenotazione FSL, uno solo per la scheda e per l'elenco della pagina
 * «Scuole» (altrimenti le due schermate direbbero cose diverse). Funzione pura: lavora su una riga già letta.
 *
 * I termini sono quelli che il portale usa già per i documenti della classe (DocumentiClasse): vale solo per le attività FSL con gli attestati agli studenti,
 * con la prenotazione confermata; il documento manca quando il termine per consegnarlo è passato (DocumentiClasse::scadenza: GIORNI_SCADENZA prima dell'inizio)
 * o l'attività è finita senza. L'elenco è «mancante» come in PercorsoFsl::contatori (nessuno studente nell'elenco). Sono considerate le attività finite da non
 * più di FINESTRA_GIORNI: un'attività di tre anni fa senza elenco non è «da fare» per sempre.
 *
 * Solo chi gestisce tutta la FSL li vede: chi ha solo le convenzioni non riceve nemmeno i dati per calcolarli.
 */
final class DocumentiMancanti
{
    public const FINESTRA_GIORNI = 365;

    /**
     * @param array{classe: bool, fsl: bool, stato: string, dal: string, al: string, data_prenotazione?: string, n_elenco: int, autorizzazione: bool} $p
     * @return array{elenco: bool, autorizzazione: bool}
     */
    public static function da(DocumentiClasse $documenti, array $p, string $oggi): array
    {
        $nessuno = ['elenco' => false, 'autorizzazione' => false];
        if (!$p['classe'] || !$p['fsl'] || $p['stato'] !== 'confermata' || $p['dal'] === '') {
            return $nessuno;
        }
        $finita = $p['al'] !== '' && $p['al'] < $oggi;
        if ($finita && $p['al'] < date('Y-m-d', strtotime($oggi . ' -' . self::FINESTRA_GIORNI . ' days'))) {
            return $nessuno;   // troppo vecchia per essere ancora una cosa da fare
        }
        $scadenza = $documenti->scadenza(['data_turno' => $p['dal'], 'data_prenotazione' => $p['data_prenotazione'] ?? '']);
        $termineScaduto = $finita || ($scadenza !== null && $scadenza < $oggi);
        if (!$termineScaduto) {
            return $nessuno;
        }

        return ['elenco' => $p['n_elenco'] === 0, 'autorizzazione' => !$p['autorizzazione']];
    }
}
