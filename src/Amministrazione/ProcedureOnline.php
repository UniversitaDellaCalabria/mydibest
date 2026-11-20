<?php

declare(strict_types=1);

namespace App\Amministrazione;

use App\Pubblicazioni\ImpostazioniPubblicazioni;
use App\Pubblicazioni\ServizioPubblicazioni;

/**
 * Le procedure online della Modulistica amministrativa: quelle che non sono un documento da scaricare ma una pagina con la sua procedura
 * guidata. Oggi ce n'è una sola, la Richiesta di pubblicazione di un'iniziativa; la scheda «Modulistica» del pannello (admin/amministrazione_modulistica.php)
 * e la pagina pubblica (modulistica_amministrativa.php) la leggono da qui, così stato, destinatari e collegamenti non possono divergere.
 *
 * Se è aperta e a chi è riservata (in parole, dalle etichette di App\Auth\Pubblico) vengono dalle impostazioni della richiesta (ImpostazioniPubblicazioni,
 * scheda «Impostazioni» dell'Amministrazione). Chi può inviare lo dice sempre ServizioPubblicazioni::puoInviare (una regola sola).
 */
final class ProcedureOnline
{
    public const PUBBLICAZIONE = 'pubblicazione';

    public function __construct(private ServizioPubblicazioni $pubblicazioni, private ImpostazioniPubblicazioni $impostazioni)
    {
    }

    /**
     * Le procedure, con per ognuna: titolo e descrizione per le persone, la pagina pubblica (indirizzo dalla radice del sito), se è aperta, a chi è
     * riservata (in parole) e dove la lavorano gli operatori (indirizzo relativo a admin/, con il nome del collegamento) e dove si cambiano le sue
     * impostazioni (relativo a admin/, solo per chi può tutto il modulo).
     *
     * @return list<array{chiave: string, titolo: string, descrizione: string, pagina: string, aperta: bool, chi: string, gestione: string, gestione_nome: string, impostazioni: string}>
     */
    public function elenco(): array
    {
        return [[
            'chiave' => self::PUBBLICAZIONE,
            'titolo' => 'Richiesta di pubblicazione di un’iniziativa',
            'descrizione' => 'Si chiede la pubblicazione di un evento o di una notizia sul sito e sui social del Dipartimento, con una procedura guidata a sette passi.',
            'pagina' => 'richiesta_pubblicazione.php',
            'aperta' => $this->impostazioni->aperta(),
            'chi' => $this->impostazioni->chiInParole(),
            'gestione' => 'pubblicazioni.php?vista=arrivate',
            'gestione_nome' => 'Richieste di pubblicazione',
            'impostazioni' => 'amministrazione.php?tab=impostazioni',
        ]];
    }

    /**
     * Se questa persona è tra chi può inviare la procedura (chi non è entrato: false). Non guarda se la procedura è aperta: quello lo dice elenco().
     *
     * @param array<string, mixed>|null $utente la riga di utenti
     */
    public function puoInviare(string $chiave, ?array $utente): bool
    {
        return $chiave === self::PUBBLICAZIONE && $this->pubblicazioni->puoInviare($utente);
    }
}
