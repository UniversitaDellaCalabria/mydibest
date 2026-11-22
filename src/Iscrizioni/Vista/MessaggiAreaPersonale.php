<?php

declare(strict_types=1);

namespace App\Iscrizioni\Vista;

/** Messaggi (testo + tipo) che l'Area personale mostra una volta sola dopo un'azione sulle prenotazioni. */
final class MessaggiAreaPersonale
{
    public static function messaggioInviato(): MessaggioArea
    {
        return new MessaggioArea('Messaggio inviato con successo alla segreteria.');
    }

    public static function nonAutorizzata(): MessaggioArea
    {
        return new MessaggioArea('Operazione non autorizzata.', 'danger');
    }

    public static function cambioTurnoScaduto(string $terminiFino): MessaggioArea
    {
        return new MessaggioArea('Non è più possibile cambiare turno: il termine era il ' . date('d/m/Y \a\l\l\e H:i', (int) strtotime($terminiFino)) . '. Per necessità scrivi alla segreteria con il pulsante Assistenza.', 'warning');
    }

    public static function modificaNonSalvata(string $errore): MessaggioArea
    {
        return new MessaggioArea('Modifica non salvata: ' . $errore, 'danger');
    }

    public static function turnoEsaurito(): MessaggioArea
    {
        return new MessaggioArea('Il turno selezionato è esaurito.', 'danger');
    }

    public static function erroreAggiornamentoRiprova(): MessaggioArea
    {
        return new MessaggioArea("Errore durante l'aggiornamento. Riprova.", 'danger');
    }

    public static function modificaSalvata(bool $inListaAttesa): MessaggioArea
    {
        $extra = $inListaAttesa ? " Sei stato inserito in Lista d'Attesa per il nuovo orario." : ' Turno aggiornato con successo!';

        return new MessaggioArea('Modifica salvata.' . $extra);
    }

    public static function prenotazioneAggiornata(): MessaggioArea
    {
        return new MessaggioArea('Prenotazione aggiornata con successo!');
    }

    public static function annullamentoScaduto(string $terminiFino): MessaggioArea
    {
        return new MessaggioArea('Non è più possibile annullare questa prenotazione: il termine era il ' . date('d/m/Y \a\l\l\e H:i', (int) strtotime($terminiFino)) . '. Per necessità scrivi alla segreteria con il pulsante Assistenza.', 'warning');
    }

    public static function annullata(): MessaggioArea
    {
        return new MessaggioArea('Prenotazione annullata correttamente.');
    }

    public static function annullamentoNegato(): MessaggioArea
    {
        return new MessaggioArea("Errore o autorizzazione negata per l'annullamento.", 'danger');
    }

    public static function offertaScaduta(): MessaggioArea
    {
        return new MessaggioArea('Il tempo per confermare il posto è scaduto e il posto è stato riassegnato.', 'warning');
    }

    public static function offertaNonValida(): MessaggioArea
    {
        return new MessaggioArea("Questa offerta di posto non è più valida.", 'warning');
    }

    public static function erroreSalvataggio(): MessaggioArea
    {
        return new MessaggioArea('Errore durante il salvataggio. Riprova.', 'danger');
    }

    /** Posto accettato da una scuola senza convenzione: la prenotazione si conferma quando la convenzione arriva. */
    public static function postoAccettatoInAttesaConvenzione(string $istruzioniHtml): MessaggioArea
    {
        return new MessaggioArea("Posto accettato: la prenotazione sarà confermata all'arrivo della convenzione.", 'warning', $istruzioniHtml);
    }

    public static function postoConfermato(): MessaggioArea
    {
        return new MessaggioArea('Posto confermato! Ti abbiamo inviato la ricevuta via email.');
    }

    public static function rinunciaAlPosto(): MessaggioArea
    {
        return new MessaggioArea('Hai rinunciato al posto. Grazie per averlo lasciato ad altri.', 'info');
    }

    public static function postoGiaConfermato(): MessaggioArea
    {
        return new MessaggioArea('Questo posto è già confermato.');
    }

    public static function offertaNonDisponibile(): MessaggioArea
    {
        return new MessaggioArea('Offerta di posto non valida o non più disponibile.', 'warning');
    }
}
