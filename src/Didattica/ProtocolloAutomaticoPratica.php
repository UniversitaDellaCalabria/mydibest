<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Protocollo\InvioProtocollo;
use Throwable;

/**
 * Protocollo in entrata automatico delle pratiche: la domanda (PDF/A e allegati) parte per Titulus come documento in arrivo, con la regola
 * del modulo («pratica:<id>») o quella generale, e il numero ricevuto si registra da solo (e la pratica passa al passo successivo).
 *
 * Quando: appena lo studente invia la pratica; ma se il percorso del modulo comincia con una conferma o una firma (es. la richiesta di tesi,
 * che prima deve essere confermata dal relatore) il protocollo parte quando la pratica arriva all'ufficio protocollo, cioè dopo l'approvazione.
 *
 * Non blocca mai niente: se il collegamento non è pronto, non c'è una regola, o Titulus risponde con un errore, la pratica resta com'è
 * (all'ufficio, con il pulsante «Protocolla in Titulus») e l'errore si legge nello storico interno e nel registro degli invii.
 */
final class ProtocolloAutomaticoPratica
{
    public const AUTORE = 'Portale MyDiBEST';

    public function __construct(
        private ProtocollaPraticaInTitulus $protocolla,
        private PraticaRepository $pratiche,
        private StoricoPratica $storico,
        private ModuloRepository $moduli,
        private ServizioIter $iter,
    ) {
    }

    /**
     * Subito dopo l'invio dello studente. @return string|null null se non c'era niente da fare (o si aspetta l'approvazione) o è andata bene;
     * altrimenti il motivo per cui il protocollo non è stato preso
     */
    public function dopoInvio(int $id): ?string
    {
        try {
            $p = $this->pratiche->perId($id);
            if (!$p || $this->aspettaApprovazione($p)) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $this->prendi($id);
    }

    /** La pratica è arrivata all'ufficio protocollo (dopo una conferma, una firma o un'assegnazione). */
    public function alPassoProtocollo(int $id): ?string
    {
        return $this->prendi($id);
    }

    /**
     * Il primo passo del percorso è una conferma o una firma: la pratica non è ancora «in entrata» finché non è approvata.
     *
     * @param array<string, mixed> $p
     */
    private function aspettaApprovazione(array $p): bool
    {
        $m = $this->moduli->perId((int) $p['modulo_id']);
        $primo = $m ? ($this->iter->iter($m, $p)[0] ?? 0) : 0;

        return in_array($primo, [Costanti::ITER_PERSONA, Costanti::ITER_DIRETTORE, Costanti::ITER_CONFERMA], true);
    }

    private function prendi(int $id): ?string
    {
        try {
            $p = $this->pratiche->perId($id);
            if (!$p || (string) $p['protocollo'] !== '' || !$this->protocolla->disponibile((int) $p['modulo_id'])) {
                return null;
            }
            // esito incerto, in approvazione o invio in corso: il protocollo automatico si ferma e lo dice all'ufficio (decide l'ufficio, dal riquadro dello stato)
            $fermo = InvioProtocollo::motivoDiFermo($this->protocolla->stato($id));
            if ($fermo !== null) {
                $this->segnalaFermo($id, $fermo);

                return $fermo;
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

    private function segnalaFermo(int $id, string $motivo): void
    {
        try {
            $this->storico->evento($id, 'messaggio', 'ufficio', 0, null, 'Il protocollo automatico in Titulus si è fermato: ' . $motivo, null, null, true, self::AUTORE);
        } catch (Throwable) {
            // lo storico non deve mai far fallire l'invio della pratica
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
