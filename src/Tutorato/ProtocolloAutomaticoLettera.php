<?php

declare(strict_types=1);

namespace App\Tutorato;

use Throwable;

/**
 * Protocollo automatico della lettera di incarico di tutorato: appena il direttore ha firmato (la lettera ha tutte le firme), il PDF parte
 * per Titulus con la regola «tutorato_lettera» (o «tutorato», o la generale) e il numero si registra da solo, con la copia allo studente.
 *
 * Non blocca mai la firma: se non c'è collegamento o regola, o Titulus dà errore, la lettera resta «Firmata: da protocollare» con il
 * pulsante «Protocolla in Titulus» per l'operatore, e l'errore si legge tra gli eventi della lettera e nel registro degli invii.
 */
final class ProtocolloAutomaticoLettera
{
    public const AUTORE = 'Portale MyDiBEST';

    public function __construct(private ProtocollaLetteraInTitulus $protocolla, private IncaricoRepository $incarichi)
    {
    }

    /** @return string|null null se non c'era niente da fare o è andata bene; altrimenti il motivo per cui il protocollo non è stato preso */
    public function dopoFirma(int $id): ?string
    {
        try {
            $i = $this->incarichi->perId($id);
            if (!$i || (string) $i['stato'] !== 'firmata' || !$this->protocolla->disponibile()) {
                return null;
            }
            $esito = $this->protocolla->protocolla($id, true, self::AUTORE);
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
            $this->incarichi->aggiungiEvento($id, 'errore', 'Il protocollo automatico in Titulus non è riuscito: ' . $motivo . ' Protocolla con il pulsante «Protocolla in Titulus» o a mano.', self::AUTORE, '');
        } catch (Throwable) {
            // gli eventi non devono mai far fallire la firma
        }
    }
}
