<?php

declare(strict_types=1);

namespace App\Email;

/**
 * Le email di MyForms: la ricevuta a chi ha risposto (con o senza il link per correggere la risposta), l'avviso di nuova risposta a chi gestisce il
 * modulo e gli avvisi al proprietario quando il modulo si chiude da solo o quando stanno per cancellarsi delle risposte. Formato dei testi: vedi ModelliEmail.
 * Il titolo del modulo lo scrive chi crea il modulo e arriva sempre ripulito. Le parole al singolare o al plurale e le frasi che dipendono dal caso
 * (anche quella su chi ha risposto, che nei moduli anonimi non deve mai dire chi è) le compone il portale.
 */
final class CatalogoForms
{
    /** @return array<string, array<string, mixed>> */
    public static function modelli(): array
    {
        $R = 'MyForms · A chi ha risposto';
        $G = 'MyForms · A chi gestisce il modulo';
        $titolo = ['d' => 'Titolo del modulo, scritto da chi lo ha creato', 'esempio' => 'Sondaggio sulle aule'];
        $inviata = ['d' => 'Quando è stata inviata la risposta', 'esempio' => '17/11/2026 alle 10:00'];
        $risposte = ['d' => 'La parola «risposta» (se è una sola) oppure «risposte», concordata con il numero', 'esempio' => 'risposte'];

        return [
            'forms_ricevuta' => CatalogoEmail::m($R, 'Ricevuta della risposta', 'Subito dopo l\'invio, se il modulo prevede la ricevuta, non è anonimo e il modulo non si può correggere dopo l\'invio. Parte solo a chi ha fatto l\'accesso e ha un indirizzo email valido.', 'Chi ha risposto al modulo',
                ['titolo' => $titolo, 'inviata_il' => $inviata, 'codice' => ['d' => 'Codice di ricevuta della risposta', 'esempio' => '0123456789abcdef0123456789abcdef']],
                'Ricevuta della tua risposta: {{titolo}}',
                "Abbiamo ricevuto la tua risposta al modulo **{{titolo}}**.\n\nInviata il {{inviata_il}}.\nCodice di ricevuta: **{{codice}}**\n\nConserva questo codice: ti serve se devi chiedere informazioni sulla tua risposta."),
            'forms_ricevuta_modificabile' => CatalogoEmail::m($R, 'Ricevuta della risposta (modulo che si può correggere)', 'Come sopra, ma quando il modulo permette di correggere la risposta finché è aperto: la ricevuta ha anche il pulsante per riaprire il modulo.', 'Chi ha risposto al modulo',
                ['titolo' => $titolo, 'inviata_il' => $inviata, 'codice' => ['d' => 'Codice di ricevuta della risposta', 'esempio' => '0123456789abcdef0123456789abcdef']],
                'Ricevuta della tua risposta: {{titolo}}',
                "Abbiamo ricevuto la tua risposta al modulo **{{titolo}}**.\n\nInviata il {{inviata_il}}.\nCodice di ricevuta: **{{codice}}**\n\nConserva questo codice: ti serve se devi chiedere informazioni sulla tua risposta.\n\nFinché il modulo è aperto puoi correggere la risposta da qui.",
                'Apri il modulo'),
            'forms_nuova_risposta' => CatalogoEmail::m($G, 'Nuova risposta al modulo', 'Subito dopo ogni risposta, agli indirizzi indicati nel modulo per gli avvisi (una email per indirizzo).', 'Gli indirizzi di notifica scritti nel modulo (al massimo 10)',
                ['titolo' => $titolo, 'inviata_il' => $inviata,
                    '!chi' => ['d' => 'La frase su chi ha risposto, con il nome in grassetto (la prepara il portale; nei moduli anonimi dice solo che la risposta è anonima)', 'esempio' => 'Risposta di <strong>Luca Studente</strong> (luca.studente@studenti.unical.it).']],
                'Nuova risposta: {{titolo}}',
                "È arrivata una nuova risposta al modulo **{{titolo}}**.\n\nInviata il {{inviata_il}}.\n{{!chi}}",
                'Vedi le risposte'),
            'forms_chiuso' => CatalogoEmail::m($G, 'Il modulo si è chiuso da solo', 'Il modulo si chiude da solo perché è finito il periodo di apertura o perché ha raggiunto il numero massimo di risposte (controllo automatico).', 'Il proprietario del modulo',
                ['titolo' => $titolo, 'motivo' => ['d' => 'Perché si è chiuso: «ha raggiunto il numero massimo di risposte (50)» oppure «è finito il periodo di apertura (il 17/11/2026 alle 10:00)»', 'esempio' => 'ha raggiunto il numero massimo di risposte (50)'],
                    'numero' => ['d' => 'Quante risposte ha ricevuto', 'esempio' => '50'], 'risposte_parola' => $risposte],
                'Il modulo si è chiuso: {{titolo}}',
                "Il modulo **{{titolo}}** si è chiuso: {{motivo}}.\n\nHa ricevuto **{{numero}}** {{risposte_parola}}.\n\nPer accettare ancora risposte puoi riaprirlo dal pannello, cambiando prima la scadenza o il numero massimo.",
                'Vedi le risposte'),
            'forms_conservazione' => CatalogoEmail::m($G, 'Tra 7 giorni si cancellano delle risposte', 'Sette giorni prima che le risposte più vecchie di quanto impostato nel modulo si cancellino da sole (controllo automatico, un avviso alla volta).', 'Il proprietario del modulo',
                ['titolo' => $titolo, 'mesi' => ['d' => 'Per quanti mesi il modulo conserva le risposte', 'esempio' => '12'],
                    'mesi_parola' => ['d' => 'La parola «mese» (se è uno solo) oppure «mesi», concordata con il numero', 'esempio' => 'mesi'],
                    'cancellazione' => ['d' => 'Giorno in cui le risposte si cancellano', 'esempio' => '24/11/2026'],
                    'quante' => ['d' => 'Quante risposte si cancellano', 'esempio' => '8'], 'risposte_parola' => $risposte,
                    'prima' => ['d' => 'Si cancellano le risposte inviate prima di questo giorno', 'esempio' => '24/11/2025']],
                'Tra 7 giorni si cancellano delle risposte: {{titolo}}',
                "Il modulo **{{titolo}}** è impostato per conservare le risposte per **{{mesi}}** {{mesi_parola}}.\n\nDal **{{cancellazione}}** si cancelleranno da sole **{{quante}}** {{risposte_parola}} (quelle inviate prima del {{prima}}), con i file allegati.\n\nSe ti servono, scaricale prima in Excel dalla pagina delle risposte. Se vuoi tenerle più a lungo, aumenta i mesi di conservazione nella scheda del modulo.",
                'Vedi le risposte'),
        ];
    }
}
