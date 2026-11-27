<?php

declare(strict_types=1);

namespace App\Email;

/**
 * Le email delle richieste di pubblicazione di docenti e personale: l'avviso all'ufficio comunicazione (con tutta la richiesta, da copiare e incollare) e la
 * ricevuta a chi l'ha inviata (con la copia di quanto ha inviato; si spegne dalle impostazioni). Con l'approvazione accesa: la richiesta da approvare agli approvatori,
 * l'avviso agli altri approvatori quando uno ha deciso e la ricevuta «in attesa di approvazione»; dopo la decisione l'esito a chi ha inviato la richiesta e, se
 * approvata, l'avviso all'ufficio comunicazione e l'email «per conoscenza». Formato dei testi: vedi ModelliEmail.
 * La chiave «pubblicazioni_docente_ricevuta» resta quella di prima: i testi già cambiati dal pannello sono salvati con quel nome.
 */
final class CatalogoPubblicazioni
{
    /** @return array<string, array<string, mixed>> */
    public static function modelli(): array
    {
        $U = 'Richieste di pubblicazione · All\'ufficio comunicazione';
        $D = 'Richieste di pubblicazione · A chi invia la richiesta';
        $A = 'Richieste di pubblicazione · A chi approva';
        $C = 'Richieste di pubblicazione · Per conoscenza';
        $raw = static fn (string $d, string $esempio = ''): array => ['d' => $d, 'esempio' => $esempio];
        $titolo = ['d' => 'Titolo della notizia scritto da chi ha inviato la richiesta', 'esempio' => 'Notte dei ricercatori'];
        $inviata = ['d' => 'Quando è stata inviata la richiesta', 'esempio' => '19/11/2026 alle 10:00'];
        $tabella = '<table style="border-collapse:collapse;width:100%;font-size:14px;"><tr><td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;color:#475569;">Titolo</td><td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;"><strong>Notte dei ricercatori</strong></td></tr></table>';

        $approvataDa = ['d' => 'Nome e cognome di chi ha approvato', 'esempio' => 'Dario Direttore'];
        $approvataIl = ['d' => 'Quando è stata approvata', 'esempio' => '26/11/2026 alle 10:30'];

        return [
            // senza approvazione parte all'invio, con l'approvazione accesa parte quando la richiesta viene approvata: per questo la frase sull'approvazione è un
            // segnaposto intero ({{approvazione_testo}}), vuoto quando non c'è stata; approvata_da e approvata_il servono a chi vuole scrivere la frase a modo suo
            'pubblicazioni_comunicazione_nuova' => CatalogoEmail::m($U, 'Nuova richiesta di pubblicazione', 'Quando la richiesta viene approvata (approvazione accesa nelle impostazioni), oppure subito all\'invio (approvazione spenta).', 'Gli indirizzi indicati nelle impostazioni delle richieste di pubblicazione (di partenza comunicazione.dibest@unical.it) e, dopo un\'approvazione, anche le persone della lista «Chi lavora» (ognuno la riceve una volta sola)',
                ['titolo' => $titolo, 'richiedente' => ['d' => 'Cognome e nome di chi ha inviato la richiesta', 'esempio' => 'Rossi Maria'],
                    'email_testo' => ['d' => 'Email di chi ha inviato la richiesta tra parentesi, con lo spazio davanti (vuota se non c\'è)', 'esempio' => ' (maria.rossi@unical.it)'],
                    'inviata_il' => $inviata,
                    'approvazione_testo' => ['d' => '« È stata approvata da … il …» con lo spazio davanti (vuoto se l\'approvazione è spenta)', 'esempio' => ' È stata approvata da Dario Direttore il 26/11/2026 alle 10:30.'],
                    'approvata_da' => ['d' => 'Nome e cognome di chi ha approvato (vuoto se l\'approvazione è spenta)', 'esempio' => $approvataDa['esempio']],
                    'approvata_il' => ['d' => 'Quando è stata approvata (vuoto se l\'approvazione è spenta)', 'esempio' => $approvataIl['esempio']],
                    '!riepilogo' => $raw('Tutti i campi della richiesta in ordine, con l\'elenco dei file (la prepara il portale)', $tabella)],
                'Richiesta di pubblicazione: {{titolo}}',
                "È arrivata una nuova richiesta di pubblicazione da **{{richiedente}}**{{email_testo}}, inviata il {{inviata_il}}.{{approvazione_testo}}\n\n{{!riepilogo}}\n\nI file non sono allegati a questa email: si scaricano dalla scheda della richiesta.",
                'Apri la scheda'),
            'pubblicazioni_docente_ricevuta' => CatalogoEmail::m($D, 'Ricevuta della richiesta di pubblicazione', 'Subito dopo l\'invio, a chi ha mandato la richiesta (se ha un indirizzo email nel portale e se la ricevuta è accesa nelle impostazioni).', 'Chi ha inviato la richiesta (docente o personale)',
                ['nome' => ['d' => 'Nome di chi ha inviato la richiesta', 'esempio' => 'Maria'], 'titolo' => $titolo, 'inviata_il' => $inviata,
                    '!riepilogo' => $raw('La copia di quanto ha inviato (la prepara il portale)', $tabella),
                    '@email_ufficio' => ['d' => 'Indirizzo dell\'ufficio comunicazione a cui scrivere per le correzioni (diventa un collegamento)', 'esempio' => 'comunicazione.dibest@unical.it']],
                'Abbiamo ricevuto la tua richiesta di pubblicazione: {{titolo}}',
                "Gentile **{{nome}}**,\n\nabbiamo ricevuto la tua richiesta di pubblicazione **{{titolo}}**, inviata il {{inviata_il}}. La esamina l'ufficio comunicazione.\n\nQui sotto trovi la copia di quanto hai inviato.\n\n{{!riepilogo}}\n\nUna richiesta già inviata non si può modificare dal portale: per correzioni scrivi a {{@email_ufficio}}.",
                'Le mie richieste'),
            // v146, approvazione accesa: la ricevuta di chi ha inviato dice che la richiesta aspetta l'approvazione (la stessa chiave di sopra resta per l'approvazione spenta)
            'pubblicazioni_docente_attesa' => CatalogoEmail::m($D, 'Ricevuta: la richiesta è in attesa di approvazione', 'Subito dopo l\'invio, a chi ha mandato la richiesta, quando l\'approvazione è accesa (se ha un indirizzo email nel portale e se la ricevuta è accesa nelle impostazioni).', 'Chi ha inviato la richiesta (docente o personale)',
                ['nome' => ['d' => 'Nome di chi ha inviato la richiesta', 'esempio' => 'Maria'], 'titolo' => $titolo, 'inviata_il' => $inviata,
                    '!riepilogo' => $raw('La copia di quanto ha inviato (la prepara il portale)', $tabella),
                    '@email_ufficio' => ['d' => 'Indirizzo dell\'ufficio comunicazione a cui scrivere per le correzioni (diventa un collegamento)', 'esempio' => 'comunicazione.dibest@unical.it']],
                'Abbiamo ricevuto la tua richiesta di pubblicazione: {{titolo}}',
                "Gentile **{{nome}}**,\n\nabbiamo ricevuto la tua richiesta di pubblicazione **{{titolo}}**, inviata il {{inviata_il}}. È in attesa di approvazione: appena viene approvata passa all'ufficio comunicazione; se viene respinta ne vedrai il motivo.\n\nQui sotto trovi la copia di quanto hai inviato.\n\n{{!riepilogo}}\n\nUna richiesta già inviata non si può modificare dal portale: per correzioni scrivi a {{@email_ufficio}}.",
                'Le mie richieste'),
            'pubblicazioni_da_approvare' => CatalogoEmail::m($A, 'Richiesta da approvare', 'Appena un docente o una persona del personale invia la richiesta, quando l\'approvazione è accesa nelle impostazioni (ognuno la riceve una volta sola).', 'Le persone della lista «Chi approva» (scelte dall\'anagrafe: devono avere un indirizzo email)',
                ['nome' => ['d' => 'Nome di chi approva', 'esempio' => 'Dario'], 'titolo' => $titolo, 'richiedente' => ['d' => 'Cognome e nome di chi ha inviato la richiesta', 'esempio' => 'Rossi Maria'],
                    'email_testo' => ['d' => 'Email di chi ha inviato la richiesta tra parentesi, con lo spazio davanti (vuota se non c\'è)', 'esempio' => ' (maria.rossi@unical.it)'],
                    'inviata_il' => $inviata, '!riepilogo' => $raw('Tutti i campi della richiesta in ordine, con l\'elenco dei file (la prepara il portale)', $tabella)],
                'Da approvare: {{titolo}}',
                "Gentile **{{nome}}**,\n\n**{{richiedente}}**{{email_testo}} ha inviato il {{inviata_il}} una richiesta di pubblicazione che aspetta la tua approvazione.\n\n{{!riepilogo}}\n\nBasta che una sola delle persone che approvano decida: appena l'ha fatto, le altre ricevono un avviso. Dalla pagina puoi approvare, oppure respingere scrivendo il motivo (lo leggerà chi ha inviato la richiesta).",
                'Apri e approva'),
            'pubblicazioni_decisa_altro' => CatalogoEmail::m($A, 'Richiesta già decisa da un altro approvatore', 'Dopo che una persona tra chi approva ha approvato o respinto una richiesta: avvisa le altre, che non devono fare niente.', 'Le altre persone della lista «Chi approva» (non chi ha deciso)',
                ['nome' => ['d' => 'Nome di chi riceve l\'avviso', 'esempio' => 'Floriana'], 'chi' => ['d' => 'Nome e cognome di chi ha deciso', 'esempio' => 'Dario Direttore'],
                    'esito' => ['d' => '«approvato» oppure «respinto»', 'esempio' => 'approvato'], 'titolo' => $titolo, 'quando' => ['d' => 'Quando è stata presa la decisione', 'esempio' => '26/11/2026 alle 10:30'],
                    'motivo_testo' => ['d' => 'Se la richiesta è stata respinta: « Motivo: …» con lo spazio davanti (vuoto se è stata approvata)', 'esempio' => ' Motivo: manca la locandina definitiva.']],
                '{{chi}} ha {{esito}} la richiesta: {{titolo}}',
                "Gentile **{{nome}}**,\n\n{{chi}} ha {{esito}} la richiesta di pubblicazione **{{titolo}}** il {{quando}}.{{motivo_testo}}\n\nNon devi fare niente: la richiesta è già stata decisa.",
                'Guarda la richiesta'),
            // dopo la decisione: a chi ha inviato la richiesta (approvata o respinta, anche se la ricevuta all'invio è spenta) e alla lista «Chi è informato»
            'pubblicazioni_approvata' => CatalogoEmail::m($D, 'La richiesta è stata approvata', 'Quando una persona tra chi approva approva la richiesta (approvazione accesa).', 'Chi ha inviato la richiesta (docente o personale), se ha un indirizzo email nel portale',
                ['nome' => ['d' => 'Nome di chi ha inviato la richiesta', 'esempio' => 'Maria'], 'titolo' => $titolo, 'approvata_da' => $approvataDa, 'approvata_il' => $approvataIl,
                    '@email_ufficio' => ['d' => 'Indirizzo dell\'ufficio comunicazione a cui scrivere per le correzioni (diventa un collegamento)', 'esempio' => 'comunicazione.dibest@unical.it']],
                'La tua richiesta di pubblicazione è stata approvata: {{titolo}}',
                "Gentile **{{nome}}**,\n\nla tua richiesta di pubblicazione **{{titolo}}** è stata approvata il {{approvata_il}} ed è passata all'ufficio comunicazione.\n\nPer correzioni scrivi a {{@email_ufficio}}.",
                'Le mie richieste'),
            'pubblicazioni_respinta' => CatalogoEmail::m($D, 'La richiesta è stata respinta', 'Quando una persona tra chi approva respinge la richiesta (approvazione accesa).', 'Chi ha inviato la richiesta (docente o personale), se ha un indirizzo email nel portale',
                ['nome' => ['d' => 'Nome di chi ha inviato la richiesta', 'esempio' => 'Maria'], 'titolo' => $titolo,
                    'respinta_il' => ['d' => 'Quando è stata respinta', 'esempio' => '26/11/2026 alle 10:30'],
                    'motivo' => ['d' => 'Il motivo scritto da chi ha respinto la richiesta', 'esempio' => 'Manca la locandina definitiva.'],
                    '@email_ufficio' => ['d' => 'Indirizzo dell\'ufficio comunicazione a cui scrivere per chiarimenti (diventa un collegamento)', 'esempio' => 'comunicazione.dibest@unical.it']],
                'La tua richiesta di pubblicazione è stata respinta: {{titolo}}',
                "Gentile **{{nome}}**,\n\nla tua richiesta di pubblicazione **{{titolo}}** è stata respinta il {{respinta_il}}, con questo motivo:\n\n> {{motivo}}\n\nSe serve puoi inviare una richiesta nuova dal portale. Per chiarimenti scrivi a {{@email_ufficio}}.",
                'Le mie richieste'),
            // senza pulsante e senza collegamenti al pannello o agli allegati: chi la riceve non ha il permesso di aprirli
            'pubblicazioni_conoscenza' => CatalogoEmail::m($C, 'Richiesta approvata, per conoscenza', 'Quando una richiesta viene approvata (approvazione accesa).', 'Le persone della lista «Chi è informato» (ognuno la riceve una volta sola: chi riceve già un\'altra email per la stessa approvazione non riceve questa)',
                ['nome' => ['d' => 'Nome di chi riceve l\'email', 'esempio' => 'Anna'], 'titolo' => $titolo, 'richiedente' => ['d' => 'Cognome e nome di chi ha inviato la richiesta', 'esempio' => 'Rossi Maria'],
                    'approvata_da' => $approvataDa, 'approvata_il' => $approvataIl, '!riepilogo' => $raw('Tutti i campi della richiesta in ordine, con l\'elenco dei file (la prepara il portale)', $tabella)],
                'Per conoscenza: {{titolo}}',
                "Gentile **{{nome}}**,\n\nti informiamo che la richiesta di pubblicazione **{{titolo}}** di **{{richiedente}}** è stata approvata da {{approvata_da}} il {{approvata_il}} ed è passata all'ufficio comunicazione.\n\n{{!riepilogo}}\n\nQuesta email è solo per conoscenza: non devi fare niente."),
        ];
    }
}
