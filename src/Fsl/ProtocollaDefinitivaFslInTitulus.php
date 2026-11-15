<?php

declare(strict_types=1);

namespace App\Fsl;

use Unical\Titulus\AllegatoTitulus;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\RichiestaProtocollo;
use App\Protocollo\Stampigliatura;
use InvalidArgumentException;

/**
 * «Protocolla in Titulus» per il protocollo definitivo della convenzione FSL: il PDF firmato dal Direttore sul portale (e l'Allegato A della
 * scuola) vanno a Titulus secondo la regola «convenzione_fsl_definitivo» (o «convenzione_fsl», o la generale); il numero ricevuto è il
 * protocollo della convenzione, che si registra con le date di validità indicate, come con il numero scritto a mano.
 */
final class ProtocollaDefinitivaFslInTitulus
{
    public const RIF = 'convenzione_fsl_definitivo';
    public const CONTESTO = 'convenzione_fsl_definitivo';

    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private ServizioConvenzioneFirmata $iter,
        private InvioProtocollo $invio,
    ) {
    }

    public function disponibile(): bool
    {
        return $this->invio->disponibile(self::CONTESTO);
    }

    /**
     * @param array<string, mixed> $dati data_stipula (valida dal) e scadenza (valida fino al); il protocollo lo dà Titulus
     * @param string $pdf PDF con la segnatura caricato a mano (facoltativo: se vuoto vale quello firmato dal Direttore)
     * @return array{errore: string|null, prenotazioni: int, convenzione_id: int, numero: string}
     */
    public function protocolla(int $id, array $dati, string $pdf, string $autore, ?int $utenteId = null): array
    {
        $ko = static fn (string $m, string $numero = ''): array => ['errore' => $m, 'prenotazioni' => 0, 'convenzione_id' => 0, 'numero' => $numero];
        $cc = $this->compilate->perId($id);
        if (!$cc) {
            return $ko('Convenzione non trovata.');
        }
        if ((string) $cc['stato_iter'] === Costanti::ITER_PROTOCOLLATA) {
            return $ko('Questa convenzione è già stata protocollata e registrata.');
        }
        if ((string) $cc['stato_iter'] !== Costanti::ITER_FIRMATA) {
            return $ko('Il portale protocolla solo il PDF firmato dal Direttore sul portale: se ha firmato fuori, scrivi a mano il protocollo e carica il file.');
        }
        // Controlli prima di protocollare: così non si assegna un numero a una convenzione che poi non si può registrare
        if (ConvenzioneRepository::codiceValido((string) ($cc['scuola_codice'] ?? '')) === null) {
            return $ko('La scuola non è collegata all\'anagrafe: registra la convenzione dal modulo «Registra, modifica o rinnova» scegliendo la scuola.');
        }
        $ok = static fn ($x): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $x) ? (string) $x : null;
        $dal = $ok($dati['data_stipula'] ?? null);
        $al = $ok($dati['scadenza'] ?? null);
        if (!$dal || !$al || $al < $dal) {
            return $ko('Controlla le date di validità: «valida fino al» non può precedere «valida dal».');
        }

        $principale = $this->file($id, 'direttore');
        if ($principale === null) {
            return $ko('Il PDF firmato dal Direttore non si legge: scrivi a mano il protocollo e carica il file.');
        }
        $allegati = [];
        if (!empty($cc['file_allegato_scuola']) && ($a = $this->file($id, 'allegato')) !== null) {
            $allegati[] = $a;
        }
        $scuola = ProtocollaConvenzioneInTitulus::datiScuola($cc);
        $richiesta = new RichiestaProtocollo(
            applicaA: self::CONTESTO,
            oggetto: 'Convenzione di Formazione Scuola Lavoro firmata dal Direttore - ' . ($scuola['denominazione'] !== '' ? $scuola['denominazione'] : 'scuola') . ($scuola['codice'] !== '' ? ' (' . $scuola['codice'] . ')' : ''),
            principale: $principale,
            allegati: $allegati,
            esterno: $scuola['esterno'],
            rifTipo: self::RIF,
            rifId: $id,
            utenteId: $utenteId,
        );
        $n = $this->invio->numero($richiesta);
        if ($n['errore'] !== null) {
            return $ko($n['errore']);
        }

        // Senza un PDF caricato a mano, il PDF registrato è una copia di quello firmato con la stampigliatura di protocollo (se il PDF lo permette)
        $pdf = $pdf !== '' ? $pdf : (Stampigliatura::sulPdf($principale->contenuto, $n['numero'], date('Y-m-d')) ?? '');
        $r = $this->iter->completa($id, $pdf, ['protocollo' => $n['numero'], 'protocollo_data' => date('Y-m-d'), 'data_stipula' => $dal, 'scadenza' => $al], $autore);
        if ($r['errore'] !== null) {
            return $ko('Protocollata in Titulus (' . $n['numero'] . ') ma non registrata nel portale: ' . $r['errore'] . ' Scrivi a mano il numero ' . $n['numero'] . '.', $n['numero']);
        }

        return ['errore' => null, 'prenotazioni' => $r['prenotazioni'], 'convenzione_id' => $r['convenzione_id'], 'numero' => $n['numero']];
    }

    private function file(int $id, string $quale): ?AllegatoTitulus
    {
        $f = $this->iter->fileDaScaricare($id, $quale);
        $c = $f !== null ? @file_get_contents($f['percorso']) : false;
        if ($f === null || $c === false || $c === '') {
            return null;
        }
        try {
            return AllegatoTitulus::crea($f['nome'], $c);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
