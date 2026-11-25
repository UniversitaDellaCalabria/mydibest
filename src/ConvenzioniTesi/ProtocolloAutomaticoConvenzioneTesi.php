<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use App\Protocollo\InvioProtocollo;
use App\Protocollo\ProtocolloRepository;
use Throwable;

/**
 * Protocollo definitivo automatico della convenzione di tesi: appena il Direttore ha firmato (con Aruba o ricaricando il file), il PDF firmato parte per Titulus
 * con la validità già decisa dall'ufficio in «Controlla la convenzione», il numero ricevuto si registra e la convenzione è stipulata.
 *
 * Parte SOLO se il collegamento a Titulus è pronto, c'è la regola, l'interruttore «protocollo automatico dopo la firma» delle tesi (admin/protocollo.php, spento di
 * partenza, separato da quello della FSL) è acceso, il file non ha avvisi di firma e le date di validità sono salvate. In ogni altro caso resta tutto all'ufficio.
 * Non fallisce mai: la firma del Direttore è già salvata e un errore di Titulus si legge solo nel pannello, accanto a «Invia al protocollo» e al numero a mano.
 * Mai due protocolli: il pulsante, l'automatico e il numero a mano usano la stessa prenotazione dell'invio (InvioProtocollo), per documento.
 */
final class ProtocolloAutomaticoConvenzioneTesi
{
    public const AUTORE = 'Portale MyDiBEST (protocollo automatico)';

    public function __construct(
        private ConvenzioneTesiRepository $conv,
        private ProtocollaDefinitivaTesiInTitulus $protocolla,
        private ProtocolloRepository $protocollo,
        private InvioProtocollo $invio,
        private ServizioConvenzioniTesi $servizio,
    ) {
    }

    /** L'interruttore del pannello è acceso? */
    public function acceso(): bool
    {
        try {
            return (int) ($this->protocollo->configurazione()['automatico_tesi'] ?? 0) === 1;
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
            $c = $this->conv->perId($id);
            if (!$c || (string) $c['stato'] !== Costanti::FIRMATA || !$this->acceso() || !$this->protocolla->disponibile()) {
                return null;
            }
            // Un esito incerto, un'approvazione in corso o un numero dato a un file di prima fermano l'automatico: li decide l'ufficio
            $st = $this->invio->stato($this->servizio->rifDefinitivo($c), $id, ProtocollaDefinitivaTesiInTitulus::RIF, (string) ($c['firmata_direttore_il'] ?? ''));
            if ($st['stato'] === 'incerto' || $st['stato'] === 'in_invio') {
                return $this->segnala($id, InvioProtocollo::testoIncerto($st['il']));
            }
            if ($st['stato'] === 'in_approvazione') {
                return $this->segnala($id, InvioProtocollo::testoInApprovazione($st['il']));
            }
            if ($st['stato'] === 'precedente') {
                return $this->segnala($id, InvioProtocollo::testoPrecedente($st['numero'], $st['il']));
            }
            $avviso = trim((string) ($c['avviso_firma_ingresso'] ?? '') . ' ' . (string) ($c['avviso_firma'] ?? ''));
            if ($avviso !== '') {
                return $this->segnala($id, 'Il file ha un avviso sulla firma: controllalo e poi premi «Invia al protocollo» (o scrivi il numero a mano).');
            }
            $dal = (string) ($c['valida_dal'] ?? '');
            $al = (string) ($c['valida_al'] ?? '');
            if ($dal === '' || $al === '') {
                return $this->segnala($id, 'Mancano le date di validità decise alla presa in carico: scrivile qui sotto e premi «Invia al protocollo».');
            }
            $esito = $this->protocolla->protocolla($id, ['valida_dal' => $dal, 'valida_al' => $al], '', null);
            if ($esito['errore'] === null) {
                $this->conv->segnaErroreProtocollo($id, '');

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
            $this->conv->segnaErroreProtocollo($id, 'Il protocollo automatico non è riuscito: ' . $motivo);
        } catch (Throwable) {
            // l'errore si legge anche nel registro degli invii: la firma resta salvata comunque
        }

        return $motivo;
    }
}
