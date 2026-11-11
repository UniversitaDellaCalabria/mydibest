<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use App\Protocollo\AllegatoTitulus;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\RichiestaProtocollo;
use InvalidArgumentException;

/**
 * «Protocolla in Titulus» per la convenzione di tesi firmata dall'azienda: il PDF va a Titulus come documento in arrivo secondo la
 * regola «convenzione_tesi» (o quella generale); il numero ricevuto diventa il protocollo in entrata e la convenzione passa alla presa
 * in carico, come con la registrazione a mano (stesso avviso all'azienda e al Direttore).
 */
final class ProtocollaConvenzioneTesiInTitulus
{
    public const RIF = 'convenzione_tesi_ingresso';
    public const CONTESTO = 'convenzione_tesi';

    public function __construct(private ServizioConvenzioniTesi $convenzioni, private InvioProtocollo $invio)
    {
    }

    public function disponibile(): bool
    {
        return $this->invio->disponibile(self::CONTESTO);
    }

    /**
     * @return array{errore: string|null, direttore: string|null, numero: string}
     */
    public function protocolla(int $id, string $autore, ?int $utenteId = null): array
    {
        $ko = static fn (string $m, string $numero = ''): array => ['errore' => $m, 'direttore' => null, 'numero' => $numero];
        $c = $this->convenzioni->perId($id);
        if (!$c || empty($c['file_firmato'])) {
            return $ko('Non c\'è nessuna convenzione firmata da protocollare.');
        }
        if ((string) $c['stato'] !== Costanti::CARICATA) {
            return $ko('La convenzione non è in attesa del protocollo in entrata.');
        }
        $f = $this->convenzioni->fileDaScaricare($id, 'azienda');
        $contenuto = $f !== null ? @file_get_contents($f['percorso']) : false;
        if ($f === null || $contenuto === false || $contenuto === '') {
            return $ko('Il file della convenzione firmata non si legge: ricaricalo o scrivi il protocollo a mano.');
        }
        try {
            $principale = AllegatoTitulus::crea($f['nome'], $contenuto);
        } catch (InvalidArgumentException $e) {
            return $ko($e->getMessage());
        }

        $dati = $this->convenzioni->dati($c);
        $azienda = trim((string) $c['azienda']);
        $indirizzo = trim(implode(', ', array_filter([trim($dati['indirizzo'] ?? ''), trim($dati['comune'] ?? '')])));
        $richiesta = new RichiestaProtocollo(
            applicaA: self::CONTESTO,
            oggetto: 'Convenzione per lo svolgimento di tesi firmata - ' . ($azienda !== '' ? $azienda : 'azienda'),
            principale: $principale,
            esterno: [
                'nome' => $azienda, 'codice_fiscale' => (string) (($dati['cf'] ?? '') ?: ($dati['piva'] ?? '')),
                'email' => (string) (($dati['pec'] ?? '') ?: $c['email']), 'telefono' => (string) ($dati['telefono'] ?? ''), 'indirizzo' => $indirizzo,
            ],
            rifTipo: self::RIF,
            rifId: $id,
            utenteId: $utenteId,
        );
        $n = $this->invio->numero($richiesta);
        if ($n['errore'] !== null) {
            return $ko($n['errore']);
        }

        $r = $this->convenzioni->protocolloIngresso($id, $n['numero'], date('Y-m-d'), $autore);
        if ($r['errore'] !== null) {
            return $ko('Protocollata in Titulus (' . $n['numero'] . ') ma non registrata nel portale: ' . $r['errore'] . ' Scrivi a mano il numero ' . $n['numero'] . '.', $n['numero']);
        }

        return ['errore' => null, 'direttore' => $r['direttore'], 'numero' => $n['numero']];
    }
}
