<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use Unical\Titulus\AllegatoTitulus;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\RichiestaProtocollo;
use App\Protocollo\Stampigliatura;
use InvalidArgumentException;

/**
 * «Protocolla in Titulus» per il protocollo definitivo della convenzione di tesi: il PDF firmato dal Direttore sul portale va a Titulus
 * secondo la regola «convenzione_tesi_definitivo» (o «convenzione_tesi», o la generale); il numero ricevuto è il protocollo della
 * convenzione, che si registra come stipulata con le date di validità indicate.
 */
final class ProtocollaDefinitivaTesiInTitulus
{
    public const RIF = 'convenzione_tesi_definitivo';
    public const CONTESTO = 'convenzione_tesi_definitivo';

    public function __construct(private ServizioConvenzioniTesi $convenzioni, private InvioProtocollo $invio)
    {
    }

    public function disponibile(): bool
    {
        return $this->invio->disponibile(self::CONTESTO);
    }

    /**
     * @param array<string, mixed> $dati valida_dal e valida_al; il protocollo lo dà Titulus
     * @param string $pdf PDF con la segnatura caricato a mano (facoltativo: se vuoto vale quello firmato dal Direttore)
     * @return array{errore: string|null, numero: string}
     */
    public function protocolla(int $id, array $dati, string $pdf, ?int $utenteId = null): array
    {
        $ko = static fn (string $m, string $numero = ''): array => ['errore' => $m, 'numero' => $numero];
        $c = $this->convenzioni->perId($id);
        if (!$c) {
            return $ko('Convenzione non trovata.');
        }
        if ((string) $c['stato'] === Costanti::PROTOCOLLATA) {
            return $ko('Questa convenzione è già stata protocollata e registrata.');
        }
        if ((string) $c['stato'] !== Costanti::FIRMATA) {
            return $ko('La convenzione deve prima essere firmata dal Direttore.');
        }
        // Controlli prima di protocollare: così non si assegna un numero a una convenzione che poi non si può registrare
        $ok = static fn ($x): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $x) ? (string) $x : null;
        $dal = $ok($dati['valida_dal'] ?? null);
        $al = $ok($dati['valida_al'] ?? null);
        if (!$dal || !$al || $al < $dal) {
            return $ko('Controlla le date di validità: «valida fino al» non può precedere «valida dal».');
        }
        $f = $this->convenzioni->fileDaScaricare($id, 'direttore');
        $contenuto = $f !== null ? @file_get_contents($f['percorso']) : false;
        if ($f === null || $contenuto === false || $contenuto === '') {
            return $ko('Il PDF firmato dal Direttore non si legge: scrivi a mano il protocollo e carica il file.');
        }
        try {
            $principale = AllegatoTitulus::crea($f['nome'], $contenuto);
        } catch (InvalidArgumentException $e) {
            return $ko($e->getMessage());
        }

        $d = $this->convenzioni->dati($c);
        $azienda = trim((string) $c['azienda']);
        $richiesta = new RichiestaProtocollo(
            applicaA: self::CONTESTO,
            oggetto: 'Convenzione per lo svolgimento di tesi firmata dal Direttore - ' . ($azienda !== '' ? $azienda : 'azienda'),
            principale: $principale,
            esterno: [
                'nome' => $azienda, 'codice_fiscale' => (string) (($d['cf'] ?? '') ?: ($d['piva'] ?? '')),
                'email' => (string) (($d['pec'] ?? '') ?: $c['email']), 'telefono' => (string) ($d['telefono'] ?? ''),
                'indirizzo' => trim(implode(', ', array_filter([trim($d['indirizzo'] ?? ''), trim($d['comune'] ?? '')]))),
            ],
            rifTipo: self::RIF,
            rifId: $id,
            utenteId: $utenteId,
        );
        $n = $this->invio->numero($richiesta);
        if ($n['errore'] !== null) {
            return $ko($n['errore']);
        }

        // Senza un PDF caricato a mano, il PDF registrato è una copia di quello firmato con la stampigliatura di protocollo (se il PDF lo permette)
        $pdf = $pdf !== '' ? $pdf : (Stampigliatura::sulPdf($contenuto, $n['numero'], date('Y-m-d')) ?? '');
        $err = $this->convenzioni->completa($id, ['protocollo' => $n['numero'], 'protocollo_data' => date('Y-m-d'), 'valida_dal' => $dal, 'valida_al' => $al], $pdf);
        if ($err !== null) {
            return $ko('Protocollata in Titulus (' . $n['numero'] . ') ma non registrata nel portale: ' . $err . ' Scrivi a mano il numero ' . $n['numero'] . '.', $n['numero']);
        }

        return ['errore' => null, 'numero' => $n['numero']];
    }
}
