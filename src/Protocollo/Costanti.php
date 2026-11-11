<?php

declare(strict_types=1);

namespace App\Protocollo;

use Unical\Titulus\Costanti as CostantiTitulus;

/**
 * Valori del modulo Protocollo del portale. Quelli del collegamento a Titulus in sé (versi del documento, azioni, esiti) sono nel
 * pacchetto Unical\Titulus (protocollo/php-titulus-utility) e qui si ripetono solo per non cambiare i punti che li usano.
 */
final class Costanti
{
    /** @see CostantiTitulus::TIPI_DOCUMENTO */
    public const TIPI_DOCUMENTO = CostantiTitulus::TIPI_DOCUMENTO;

    /** @see CostantiTitulus::AZIONI */
    public const AZIONI = CostantiTitulus::AZIONI;

    /** Richieste del portale a cui si può collegare una regola (le pratiche degli studenti si aggiungono dall'elenco dei moduli). */
    public const CONTESTI_FISSI = [
        'generale' => 'Qualsiasi richiesta (regola di riserva)',
        'convenzione_fsl' => 'Convenzioni FSL: protocollo in entrata (convenzione firmata dalla scuola)',
        'convenzione_fsl_definitivo' => 'Convenzioni FSL: protocollo definitivo (firmata dal Direttore)',
        'convenzione_tesi' => 'Convenzioni di tesi: protocollo in entrata (firmata dall\'azienda)',
        'convenzione_tesi_definitivo' => 'Convenzioni di tesi: protocollo definitivo (firmata dal Direttore)',
        'tutorato' => 'Tutorato: tutto (vale per la lettera se non ce n\'è una dedicata)',
        'tutorato_lettera' => 'Tutorato: lettera di incarico firmata',
    ];

    /** Se per un contesto non c'è una regola dedicata si prova quella del contesto «padre», poi quella generale. */
    public const CONTESTO_PADRE = [
        'tutorato_lettera' => 'tutorato',
    ];

    public const PREFISSO_PRATICA = 'pratica:';
    /** Protocollo in uscita dei moduli con un passo «uscita» (attestati): «pratica_uscita:<id del modulo>». */
    public const PREFISSO_USCITA = 'pratica_uscita:';

    /** Esito di un invio, come si legge nel registro. */
    public const ESITO_OK = CostantiTitulus::ESITO_OK;
    public const ESITO_ERRORE = CostantiTitulus::ESITO_ERRORE;
}
