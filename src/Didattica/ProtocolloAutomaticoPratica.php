<?php

declare(strict_types=1);

namespace App\Didattica;

use Throwable;

/**
 * Protocollo in entrata automatico: appena lo studente invia la pratica, la domanda (PDF/A e allegati) parte per Titulus come documento
 * in arrivo, con la regola del modulo («pratica:<id>») o quella generale, e il numero ricevuto si registra da solo.
 *
 * Non blocca mai l'invio: se il collegamento non è pronto, non c'è una regola, o Titulus risponde con un errore, la pratica resta come
 * prima (all'ufficio, con il pulsante «Protocolla in Titulus») e l'errore si legge nello storico interno e nel registro degli invii.
 */
final class ProtocolloAutomaticoPratica
{
    public const AUTORE = 'Portale Didattica DiBEST';

    public function __construct(
        private ProtocollaPraticaInTitulus $protocolla,
        private PraticaRepository $pratiche,
        private StoricoPratica $storico,
    ) {
    }

    /** @return string|null null se non c'era niente da fare o è andata bene; altrimenti il motivo per cui il protocollo non è stato preso */
    public function dopoInvio(int $id): ?string
    {
        try {
            $p = $this->pratiche->perId($id);
            if (!$p || (string) $p['protocollo'] !== '' || !$this->protocolla->disponibile((int) $p['modulo_id'])) {
                return null;
            }
            $esito = $this->protocolla->protocolla($id, 0, self::AUTORE);
            if ($esito['errore'] === null) {
                return null;
            }
            $this->segnala($id, (string) $esito['errore']);

            return (string) $esito['errore'];
        } catch (Throwable $e) {
            $this->segnala($id, 'errore imprevisto (' . $e->getMessage() . ').');

            return $e->getMessage();
        }
    }

    private function segnala(int $id, string $motivo): void
    {
        try {
            $this->storico->evento($id, 'messaggio', 'ufficio', 0, null, 'Il protocollo automatico in Titulus non è riuscito: ' . $motivo . ' Protocolla con il pulsante «Protocolla in Titulus» o a mano.', null, null, true, self::AUTORE);
        } catch (Throwable) {
            // lo storico non deve mai far fallire l'invio della pratica
        }
    }
}
