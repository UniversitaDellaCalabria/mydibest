<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Protocollo\InvioProtocollo;
use App\Protocollo\ProtocolloRepository;
use Throwable;

/**
 * Protocollo definitivo automatico della convenzione FSL: appena il Direttore ha firmato (con Aruba o ricaricando il file), il PDF firmato parte per
 * Titulus con la validità e i docenti già decisi dall'ufficio alla presa in carico, il numero ricevuto si registra e la convenzione entra nel registro.
 *
 * Parte SOLO se il collegamento a Titulus è pronto, c'è la regola, l'interruttore «protocollo automatico dopo la firma» (admin/protocollo.php,
 * spento di partenza) è acceso, il file non ha avvisi di firma e le date di validità sono salvate. In ogni altro caso resta tutto all'ufficio.
 * Non fallisce mai: la firma del Direttore è già salvata e un errore di Titulus si legge solo nel pannello, accanto a «Invia al protocollo» e al numero a mano.
 * Mai due protocolli: il pulsante, l'automatico e il numero a mano usano la stessa prenotazione dell'invio (InvioProtocollo).
 */
final class ProtocolloAutomaticoConvenzioneFsl
{
    public const AUTORE = 'Portale MyDiBEST (protocollo automatico)';

    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private ProtocollaDefinitivaFslInTitulus $protocolla,
        private ProtocolloRepository $protocollo,
        private InvioProtocollo $invio,
        private ServizioConvenzioneFirmata $iter,
    ) {
    }

    /** L'interruttore del pannello è acceso? */
    public function acceso(): bool
    {
        try {
            return (int) ($this->protocollo->configurazione()['automatico_fsl'] ?? 0) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Subito dopo la firma del Direttore. @return string|null null se non c'era niente da fare o è andato tutto bene; altrimenti il motivo per cui non è partito o non è riuscito
     */
    public function dopoFirma(int $id): ?string
    {
        try {
            $cc = $this->compilate->perId($id);
            if (!$cc || (string) $cc['stato_iter'] !== Costanti::ITER_FIRMATA || !$this->acceso() || !$this->protocolla->disponibile()) {
                return null;
            }
            // Un esito incerto, un'approvazione in corso o un numero dato a un file di prima della stessa compilazione fermano l'automatico: li decide l'ufficio
            $st = $this->invio->stato($this->iter->rifDefinitivo($cc), $id, ProtocollaDefinitivaFslInTitulus::RIF, (string) ($cc['firmata_direttore_il'] ?? ''));
            if ($st['stato'] === 'incerto' || $st['stato'] === 'in_invio') {
                return $this->segnala($id, InvioProtocollo::testoIncerto($st['il']));
            }
            if ($st['stato'] === 'in_approvazione') {
                return $this->segnala($id, InvioProtocollo::testoInApprovazione($st['il']));
            }
            if ($st['stato'] === 'precedente') {
                return $this->segnala($id, InvioProtocollo::testoPrecedente($st['numero'], $st['il']));
            }
            $avviso = trim((string) ($cc['avviso_firma_ingresso'] ?? '') . ' ' . (string) ($cc['avviso_firma'] ?? ''));
            if ($avviso !== '') {
                return $this->segnala($id, 'Il file ha un avviso sulla firma: controllalo e poi premi «Invia al protocollo» (o scrivi il numero a mano).');
            }
            $dal = (string) ($cc['valida_dal'] ?? '');
            $al = (string) ($cc['valida_al'] ?? '');
            if ($dal === '' || $al === '') {
                return $this->segnala($id, 'Mancano le date di validità decise alla presa in carico: scrivile qui sotto e premi «Invia al protocollo».');
            }
            $esito = $this->protocolla->protocolla($id, [
                'data_stipula' => $dal, 'scadenza' => $al,
                'docenti' => array_values(array_filter((array) (json_decode((string) ($cc['docenti_json'] ?? ''), true) ?: []), 'is_array')),
            ], '', self::AUTORE);
            if ($esito['errore'] === null) {
                $this->compilate->segnaErroreProtocollo($id, '');

                return null;
            }

            return $this->segnala($id, (string) $esito['errore']);
        } catch (Throwable $e) {
            return $this->segnala($id, 'errore imprevisto (' . $e->getMessage() . ').');
        }
    }

    private function segnala(int $id, string $motivo): string
    {
        try {
            $this->compilate->segnaErroreProtocollo($id, 'Il protocollo automatico non è riuscito: ' . $motivo);
        } catch (Throwable) {
            // l'errore si legge anche nel registro degli invii: la firma resta salvata comunque
        }

        return $motivo;
    }
}
