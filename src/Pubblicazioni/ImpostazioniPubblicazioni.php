<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Core\Database;
use App\Forms\NotificheForms;

/**
 * L'indirizzo (o gli indirizzi) che riceve le richieste di pubblicazione: impostazione globale in impostazioni_sistema
 * (colonna pubblicazioni_email_notifica, valore iniziale comunicazione.dibest@unical.it), modificabile dal pannello.
 * Più indirizzi separati da virgola, punto e virgola o spazio, con le stesse regole di MyForms (NotificheForms::indirizzi: validi, senza doppioni, max 10).
 */
final class ImpostazioniPubblicazioni
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<string> gli indirizzi validi salvati (quello predefinito se la colonna non c'è ancora o la riga manca) */
    public function indirizzi(): array
    {
        $v = $this->db->valore('SELECT pubblicazioni_email_notifica FROM impostazioni_sistema WHERE id = 1');

        return NotificheForms::indirizzi($v === null ? Costanti::EMAIL_PREDEFINITA : (string) $v);
    }

    /**
     * Salva l'elenco ripulito. Ritorna l'errore da mostrare (null se salvato): serve almeno un indirizzo e tutti devono essere validi.
     */
    public function salva(string $elenco): ?string
    {
        $validi = NotificheForms::indirizzi($elenco);
        $sbagliati = array_filter(preg_split('/[\s,;]+/', $elenco, -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn (string $e): bool => filter_var(trim($e), FILTER_VALIDATE_EMAIL) === false);
        if ($sbagliati) {
            return 'Questi indirizzi non sono validi: ' . implode(', ', $sbagliati) . '.';
        }
        if ($validi === []) {
            return 'Indica almeno un indirizzo email valido.';
        }
        $this->db->esegui('UPDATE impostazioni_sistema SET pubblicazioni_email_notifica = ? WHERE id = 1', [implode(', ', $validi)]);

        return null;
    }
}
