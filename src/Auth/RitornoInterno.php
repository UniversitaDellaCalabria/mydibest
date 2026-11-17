<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Dopo l'accesso la persona torna alla pagina da cui era partita (saml_login.php?redirect=…). L'indirizzo arriva dalla richiesta, quindi va accettato solo
 * se è una pagina del sito: mai un altro sito, e nemmeno qualcosa che il browser leggerebbe come un altro sito o come codice.
 */
final class RitornoInterno
{
    /**
     * Vero solo per un percorso del sito (es. «form.php?c=abc», «/area_personale.php»). Falso per: vuoto; indirizzi con uno schema («https:», «javascript:»…);
     * indirizzi che iniziano con «//» (stesso schema, altro sito); qualsiasi indirizzo con spazi, caratteri di controllo o barre rovesciate
     * (il browser le toglie o le trasforma: « javascript:…» diventa «javascript:…», «/\sito.it» diventa «//sito.it»).
     */
    public static function valido(string $indirizzo): bool
    {
        if ($indirizzo === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $indirizzo) === 1) {
            return false;
        }
        if (preg_match('#^[a-z][a-z0-9+\-.]*:#i', $indirizzo) === 1) {
            return false;
        }

        return !str_starts_with($indirizzo, '//');
    }
}
