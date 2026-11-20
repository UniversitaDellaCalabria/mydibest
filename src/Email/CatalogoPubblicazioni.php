<?php

declare(strict_types=1);

namespace App\Email;

/**
 * Le email delle richieste di pubblicazione di docenti e personale: l'avviso all'ufficio comunicazione (con tutta la richiesta, da copiare e incollare) e la
 * ricevuta a chi l'ha inviata (con la copia di quanto ha inviato; si spegne dalle impostazioni). Formato dei testi: vedi ModelliEmail.
 * La chiave «pubblicazioni_docente_ricevuta» resta quella di prima: i testi già cambiati dal pannello sono salvati con quel nome.
 */
final class CatalogoPubblicazioni
{
    /** @return array<string, array<string, mixed>> */
    public static function modelli(): array
    {
        $U = 'Richieste di pubblicazione · All\'ufficio comunicazione';
        $D = 'Richieste di pubblicazione · A chi invia la richiesta';
        $raw = static fn (string $d, string $esempio = ''): array => ['d' => $d, 'esempio' => $esempio];
        $titolo = ['d' => 'Titolo della notizia scritto da chi ha inviato la richiesta', 'esempio' => 'Notte dei ricercatori'];
        $inviata = ['d' => 'Quando è stata inviata la richiesta', 'esempio' => '19/11/2026 alle 10:00'];
        $tabella = '<table style="border-collapse:collapse;width:100%;font-size:14px;"><tr><td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;color:#475569;">Titolo</td><td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;"><strong>Notte dei ricercatori</strong></td></tr></table>';

        return [
            'pubblicazioni_comunicazione_nuova' => CatalogoEmail::m($U, 'Nuova richiesta di pubblicazione', 'Un docente o una persona del personale invia la richiesta di pubblicazione dal portale.', 'Gli indirizzi indicati nelle impostazioni delle richieste di pubblicazione (di partenza comunicazione.dibest@unical.it)',
                ['titolo' => $titolo, 'richiedente' => ['d' => 'Cognome e nome di chi ha inviato la richiesta', 'esempio' => 'Rossi Maria'],
                    'email_testo' => ['d' => 'Email di chi ha inviato la richiesta tra parentesi, con lo spazio davanti (vuota se non c\'è)', 'esempio' => ' (maria.rossi@unical.it)'],
                    'inviata_il' => $inviata,
                    '!riepilogo' => $raw('Tutti i campi della richiesta in ordine, con l\'elenco dei file (la prepara il portale)', $tabella)],
                'Richiesta di pubblicazione: {{titolo}}',
                "È arrivata una nuova richiesta di pubblicazione da **{{richiedente}}**{{email_testo}}, inviata il {{inviata_il}}.\n\n{{!riepilogo}}\n\nI file non sono allegati a questa email: si scaricano dalla scheda della richiesta.",
                'Apri la scheda'),
            'pubblicazioni_docente_ricevuta' => CatalogoEmail::m($D, 'Ricevuta della richiesta di pubblicazione', 'Subito dopo l\'invio, a chi ha mandato la richiesta (se ha un indirizzo email nel portale e se la ricevuta è accesa nelle impostazioni).', 'Chi ha inviato la richiesta (docente o personale)',
                ['nome' => ['d' => 'Nome di chi ha inviato la richiesta', 'esempio' => 'Maria'], 'titolo' => $titolo, 'inviata_il' => $inviata,
                    '!riepilogo' => $raw('La copia di quanto ha inviato (la prepara il portale)', $tabella),
                    '@email_ufficio' => ['d' => 'Indirizzo dell\'ufficio comunicazione a cui scrivere per le correzioni (diventa un collegamento)', 'esempio' => 'comunicazione.dibest@unical.it']],
                'Abbiamo ricevuto la tua richiesta di pubblicazione: {{titolo}}',
                "Gentile **{{nome}}**,\n\nabbiamo ricevuto la tua richiesta di pubblicazione **{{titolo}}**, inviata il {{inviata_il}}. La esamina l'ufficio comunicazione.\n\nQui sotto trovi la copia di quanto hai inviato.\n\n{{!riepilogo}}\n\nUna richiesta già inviata non si può modificare dal portale: per correzioni scrivi a {{@email_ufficio}}.",
                'Le mie richieste'),
        ];
    }
}
