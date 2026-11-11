<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Sito;
use App\Protocollo\AllegatoTitulus;
use App\Protocollo\Costanti as CostantiProtocollo;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\RichiestaProtocollo;
use InvalidArgumentException;

/**
 * «Protocolla in Titulus» per il protocollo in uscita di un attestato: il PDF firmato dal Direttore va a Titulus come documento in
 * partenza all'azienda, secondo la regola del modulo («pratica_uscita:<id del modulo>», altrimenti la generale); il numero ricevuto si
 * registra come protocollo della pratica, che si conclude. Allo studente va il PDF caricato dall'ufficio con la segnatura, se c'è;
 * altrimenti quello firmato dal Direttore.
 */
final class ProtocollaAttestatoInTitulus
{
    public const RIF = 'pratica_uscita';

    public function __construct(
        private PraticaRepository $pratiche,
        private FirmaPraticaRepository $firme,
        private AllegatiPratiche $allegati,
        private ServizioAttestati $attestati,
        private InvioProtocollo $invio,
        private Sito $sito,
    ) {
    }

    /** Contesto della regola di un modulo: «pratica_uscita:<id>». */
    public static function contesto(int $moduloId): string
    {
        return CostantiProtocollo::PREFISSO_USCITA . $moduloId;
    }

    public function disponibile(int $moduloId): bool
    {
        return $this->invio->disponibile(self::contesto($moduloId));
    }

    /**
     * @param string $pdfConSegnatura PDF con la segnatura caricato a mano (facoltativo)
     * @param string $codiceFiscale codice fiscale o partita IVA dell'azienda (serve ai documenti in partenza)
     * @return array{errore: string|null, messaggio: string, numero: string}
     */
    public function protocolla(int $id, int $uid, string $autoreNome, string $pdfConSegnatura = '', string $codiceFiscale = ''): array
    {
        $ko = static fn (string $m, string $numero = ''): array => ['errore' => $m, 'messaggio' => '', 'numero' => $numero];
        $p = $this->pratiche->perId($id);
        if (!$p || !$this->attestati->inUscita($p)) {
            return $ko('La pratica non è nel passo del protocollo in uscita.');
        }
        $firmato = $this->firmatoDalDirettore($id);
        if ($firmato === null) {
            return $ko('Il PDF firmato dal Direttore non si trova sul portale: protocolla a mano e carica il file.');
        }
        $azienda = $this->valore($p, 'Ente o azienda');
        $richiesta = new RichiestaProtocollo(
            applicaA: self::contesto((int) $p['modulo_id']),
            oggetto: 'Attestato - ' . trim($p['cognome'] . ' ' . $p['nome']) . ' (matricola ' . $p['matricola'] . ', pratica ' . $p['codice'] . ')' . ($azienda !== '' ? ' - ' . $azienda : ''),
            principale: $firmato,
            esterno: [
                'nome' => $azienda !== '' ? $azienda : 'Azienda destinataria', 'codice_fiscale' => trim($codiceFiscale),
                'email' => $this->attestati->destinatario($p),
            ],
            rifTipo: self::RIF,
            rifId: $id,
            utenteId: $uid > 0 ? $uid : null,
        );
        $n = $this->invio->numero($richiesta);
        if ($n['errore'] !== null) {
            return $ko($n['errore']);
        }

        [$err, $msg] = $this->attestati->protocolloUscitaConPdf($id, $n['numero'], date('Y-m-d'), $pdfConSegnatura !== '' ? $pdfConSegnatura : $firmato->contenuto, $uid, $autoreNome);
        if ($err !== null) {
            return $ko('Protocollato in Titulus (' . $n['numero'] . ') ma non registrato nel portale: ' . $err . ' Scrivi a mano il numero ' . $n['numero'] . '.', $n['numero']);
        }

        return ['errore' => null, 'messaggio' => $msg, 'numero' => $n['numero']];
    }

    /** L'ultimo PDF firmato dal Direttore per la pratica (solo dentro la cartella degli allegati). */
    private function firmatoDalDirettore(int $id): ?AllegatoTitulus
    {
        $base = realpath($this->sito->radice() . '/' . rtrim($this->allegati->cartella(), '/'));
        foreach ($this->firme->dellaPratica($id) as $f) {
            if ((string) $f['tipo'] !== 'direttore' || (string) $f['stato'] !== 'firmata' || empty($f['file_firmato'])) {
                continue;
            }
            $percorso = realpath($this->sito->radice() . '/' . $f['file_firmato']);
            if (!$base || !$percorso || strpos($percorso, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($percorso)) {
                continue;
            }
            $c = @file_get_contents($percorso);
            if ($c === false || $c === '') {
                continue;
            }
            try {
                return AllegatoTitulus::crea('Attestato_firmato_' . $id . '.pdf', $c);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $p */
    private function valore(array $p, string $etichetta): string
    {
        foreach ((array) json_decode((string) ($p['risposte_json'] ?? ''), true) as $r) {
            if (mb_strtolower(trim((string) ($r['etichetta'] ?? ''))) === mb_strtolower($etichetta) && empty($r['nascosto'])) {
                return trim((string) ($r['valore'] ?? ''));
            }
        }

        return '';
    }
}
