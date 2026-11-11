<?php

declare(strict_types=1);

namespace App\Protocollo;

/** Valori fissi del collegamento a Titulus (protocollo di Ateneo). */
final class Costanti
{
    /** Verso del documento: come nel connettore dell'Ateneo (arrivo, partenza, tra uffici). */
    public const TIPI_DOCUMENTO = [
        'arrivo' => 'Documento in arrivo',
        'partenza' => 'Documento in partenza',
        'interno' => 'Documento tra uffici',
    ];

    /** Cosa fa Titulus: registra subito il protocollo, oppure salva una bozza e fa partire l'iter di approvazione (voce d'indice). */
    public const AZIONI = [
        'protocolla' => 'Protocolla subito',
        'iter' => 'Salva in bozza e avvia l\'iter di approvazione',
    ];

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
    public const ESITO_OK = 'ok';
    public const ESITO_ERRORE = 'errore';
}
