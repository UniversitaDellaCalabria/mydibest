<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Sito;
use App\Email\InvioEmail;

/**
 * «Cambia referente»: l'ufficio toglie una compilazione di convenzione a chi non ne ha diritto (o a chi non c'è più) e la dà a un nuovo indirizzo.
 * Il link personale cambia (il vecchio smette di funzionare), il nuovo indirizzo riceve il link nuovo e diventa l'unico proprietario: la vecchia email,
 * la prenotazione di nascita e le email unite non aprono più. Non vale per le compilazioni protocollate né per quelle unite. Niente si cancella.
 */
final class ServizioReferenteConvenzione
{
    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private InvioEmail $invio,
        private Sito $sito
    ) {
    }

    /**
     * Ritorna il messaggio di errore, null se il referente è cambiato. $da = chi lo fa (per i messaggi e per il registro delle operazioni di chi chiama).
     */
    public function cambia(int $id, string $nuovaEmail, string $da): ?string
    {
        $email = strtolower(trim($nuovaEmail));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Scrivi un indirizzo email valido per il nuovo referente.';
        }
        $cc = $id > 0 ? $this->compilate->perId($id) : null;
        if (!$cc) {
            return 'La compilazione non esiste più.';
        }
        try {
            $token = bin2hex(random_bytes(16));
            $errore = $this->compilate->sottoBloccoDi($cc, function () use ($id, $email, $token): ?string {
                // Rilette sotto il blocco: nel frattempo può essere stata protocollata o unita
                $fresca = $this->compilate->perId($id);
                if (!$fresca || in_array((string) ($fresca['stato_iter'] ?? ''), [Costanti::ITER_PROTOCOLLATA, Costanti::ITER_UNITA], true)) {
                    return 'Non si cambia il referente di una convenzione già protocollata o unita a un\'altra.';
                }

                return $this->compilate->cambiaReferente($id, $token, $email) ? null : 'Non riesco a cambiare il referente: riprova.';
            });
        } catch (BloccoOccupato $e) {
            return $e->getMessage();
        }
        if ($errore !== null) {
            return $errore;
        }
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $scuola = (string) (($dati['scuola']['denominazione'] ?? '') ?: 'la tua scuola');
        $this->invio->invia($email, 'fsl_nuovo_referente', ['scuola' => $scuola], $this->sito->urlBase() . '/convenzione_online.php?t=' . $token);

        return null;
    }
}
