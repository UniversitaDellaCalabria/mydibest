<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

/** Valori fissi delle richieste di pubblicazione: stati, modalità di svolgimento, destinatari, limiti dei testi e cartella degli allegati. */
final class Costanti
{
    /**
     * Stati di una richiesta, nell'ordine in cui si mostrano: In attesa di approvazione → Approvata → In lavorazione → Pubblicata (oppure Respinta).
     * «ricevuta» è lo stato di sempre e adesso si legge «Approvata»: le richieste di prima dell'approvazione non si toccano e valgono come approvate.
     */
    public const STATI = [
        'da_approvare' => 'In attesa di approvazione',
        'ricevuta' => 'Approvata',
        'lavorazione' => 'In lavorazione',
        'pubblicata' => 'Pubblicata',
        'respinta' => 'Respinta',
    ];

    /** Le tre liste di persone dell'approvazione (tabella pubblicazioni_persone): chi approva, chi lavora la richiesta approvata, chi ne è solo informato. */
    public const RUOLI_PERSONE = [
        'approvatore' => 'Chi approva',
        'ufficio' => 'Chi lavora',
        'conoscenza' => 'Chi è informato',
    ];

    /** Lunghezza massima del motivo con cui si respinge una richiesta (la colonna respinta_motivo è VARCHAR(1000)). */
    public const MAX_MOTIVO = 1000;

    /** Modalità di svolgimento (colonna I dell'Excel). */
    public const MODALITA = [
        'presenza' => 'In presenza',
        'online' => 'Online',
        'mista' => 'Mista',
    ];

    /**
     * Le caselle «Evento rivolto a» del docente: campo della tabella => [etichetta, colonna dell'Excel che si precompila con «SI»].
     * Quello che il docente ha spuntato resta nei campi per_*; le colonne L, P, Q, R sono testo libero degli operatori.
     */
    public const DESTINATARI = [
        'per_cittadinanza' => ['Cittadinanza', 'l_dest_cittadinanza'],
        'per_studenti' => ['Studenti universitari', 'p_dest_studenti'],
        'per_docenti' => ['Docenti', 'q_dest_docenti'],
        'per_pta' => ['Personale tecnico amministrativo', 'r_dest_pta'],
    ];

    /** Origine della riga: dal form del docente o aggiunta a mano dal pannello. */
    public const ORIGINI = ['form', 'manuale'];

    /**
     * Caratteri massimi (testo senza formattazione, spazi inclusi). Sottotitolo, descrizione, allegati e immagini sono solo i valori di partenza delle
     * impostazioni (ImpostazioniPubblicazioni::INIZIALI): i limiti veri li legge ImpostazioniPubblicazioni::limiti(). Il titolo resta fisso.
     */
    public const MAX_SOTTOTITOLO = 75;
    public const MAX_DESCRIZIONE = 170;
    public const MAX_TITOLO = 300;
    /** Numero massimo di file per richiesta, di partenza: allegati (PDF/JPG/PNG) e immagini (JPG/PNG). */
    public const MAX_ALLEGATI = 10;
    public const MAX_IMMAGINI = 10;
    /** Lunghezza massima degli indirizzi dell'URL del docente, tutti insieme (la colonna url_docente è VARCHAR(1000)). */
    public const MAX_URL = 1000;
    /** Lunghezza massima di una colonna degli operatori (testo libero). */
    public const MAX_COLONNA = 2000;

    /** Cartella degli allegati (relativa alla radice del sito, bloccata al web). */
    public const CARTELLA = 'uploads/pubblicazioni/';

    /** Valore iniziale dell'indirizzo che riceve le richieste (impostazione modificabile dal pannello). */
    public const EMAIL_PREDEFINITA = 'comunicazione.dibest@unical.it';
}
