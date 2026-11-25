<?php

declare(strict_types=1);

namespace App\Fsl;

use Unical\Titulus\AllegatoTitulus;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\RichiestaProtocollo;
use InvalidArgumentException;

/**
 * «Protocolla in Titulus» al posto del numero scritto a mano: la convenzione firmata caricata dalla scuola (e l'Allegato A) vanno a
 * Titulus come documento in arrivo, secondo la regola «convenzione_fsl» (o quella generale); il numero ricevuto diventa il protocollo in
 * entrata e la convenzione passa alla presa in carico, come con la registrazione manuale (stesso avviso alla scuola e al Direttore).
 */
final class ProtocollaConvenzioneInTitulus
{
    public const RIF = 'convenzione_fsl_ingresso';
    public const CONTESTO = 'convenzione_fsl';

    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private ServizioConvenzioneFirmata $iter,
        private InvioProtocollo $invio,
    ) {
    }

    /** Il pulsante si mostra solo se il collegamento è pronto e c'è una regola attiva per le convenzioni (o quella generale). */
    public function disponibile(): bool
    {
        return $this->invio->disponibile(self::CONTESTO);
    }

    /**
     * @param string $allegato Allegato A caricato dall'ufficio (facoltativo): si aggiunge o prende il posto di quello della scuola prima dell'invio
     * @return array{errore: string|null, direttore: string|null, numero: string}
     *         errore: perché non è andata (già scritto per chi legge); direttore: come in ServizioConvenzioneFirmata::protocolloIngresso; numero: protocollo assegnato
     */
    public function protocolla(int $id, string $autore, ?int $utenteId = null, string $allegato = ''): array
    {
        $ko = static fn (string $m, string $numero = ''): array => ['errore' => $m, 'direttore' => null, 'numero' => $numero];
        $cc = $this->compilate->perId($id);
        if (!$cc || empty($cc['file_firmato'])) {
            return $ko('Non c\'è nessuna convenzione firmata da protocollare.');
        }
        if ((string) $cc['stato_iter'] !== Costanti::ITER_CARICATA) {
            return $ko('La convenzione non è in attesa del protocollo in entrata.');
        }

        // Controlli sui file prima di chiedere il numero; un Allegato A nuovo non si accetta se i file sono già partiti
        if ($this->file($id, 'scuola') === null) {
            return $ko('Il file della convenzione firmata non si legge: ricaricalo o scrivi il protocollo a mano.');
        }
        // Il riferimento comprende l'impronta del file firmato: se la scuola l'ha ricaricato dopo un rifiuto il numero è nuovo, se è lo stesso file si riusa
        $firmato = $this->file($id, 'scuola');
        $rif = InvioProtocollo::rifPerFile(self::RIF, $firmato->contenuto);
        $gia = $this->invio->giaAssegnato($rif, $id, self::RIF, (string) ($cc['firmata_il'] ?? ''));
        if ($gia !== '' && $allegato !== '' && $allegato !== (string) $this->file($id, 'allegato')?->contenuto) {
            return $ko('Titulus ha già dato il numero ' . $gia . ' con i file di prima: premi di nuovo senza caricare un altro Allegato A.', $gia);
        }
        $errore = $this->iter->sostituisciAllegato($cc, $allegato);
        if ($errore !== null) {
            return $ko($errore);
        }
        $richiesta = $this->richiesta($id, $this->compilate->perId($id) ?? $cc, $utenteId);
        if (is_string($richiesta)) {
            return $ko($richiesta);
        }
        $n = $this->invio->numero($richiesta, self::RIF, (string) ($cc['firmata_il'] ?? ''));
        if ($n['errore'] !== null) {
            return $ko($n['errore']);
        }
        $numero = $n['numero'];

        // la data è quella dell'invio riuscito (se il numero era già stato dato non è oggi) e il numero si registra solo sul file che è partito
        $r = $this->iter->protocolloIngresso($id, $numero, $n['data'] ?? date('Y-m-d'), $autore, '', hash('sha256', $firmato->contenuto));
        if ($r['errore'] !== null) {
            return $ko('Protocollato in Titulus (' . $numero . ') ma non registrato nel portale: ' . $r['errore'] . ' Scrivi a mano il numero ' . $numero . '.', $numero);
        }

        return ['errore' => null, 'direttore' => $r['direttore'], 'numero' => $numero];
    }

    /**
     * «Registra il numero a mano» del protocollo in entrata, con la prenotazione dell'invio in mano (vedi ProtocollaDefinitivaFslInTitulus::completaAMano()).
     *
     * @return array{errore: string|null, direttore: string|null}
     */
    public function protocolloAMano(int $id, string $numero, string $data, string $autore, string $allegato = ''): array
    {
        $cc = $this->compilate->perId($id);
        if (!$cc) {
            return ['errore' => 'Non c\'è nessuna convenzione firmata da protocollare.', 'direttore' => null];
        }
        $r = $this->invio->aMano($this->iter->rifIngresso($cc), $id, trim($numero), fn (): array => $this->iter->protocolloIngresso($id, $numero, $data, $autore, $allegato), self::RIF, (string) ($cc['firmata_il'] ?? ''));

        return $r['errore'] !== null ? ['errore' => $r['errore'], 'direttore' => null] : $r['valore'];
    }

    /**
     * Chi scrive (la scuola) come lo vuole Titulus: nome, codice fiscale, PEC o email, indirizzo; più la denominazione e il codice meccanografico per l'oggetto.
     *
     * @param array<string, mixed> $cc riga di convenzioni_compilate
     * @return array{denominazione: string, codice: string, esterno: array<string, string>}
     */
    public static function datiScuola(array $cc): array
    {
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $s = $dati['scuola'] ?? [];
        $indirizzo = trim(implode(', ', array_filter([trim((string) ($s['indirizzo'] ?? '')), trim((string) ($s['comune'] ?? ''))])));
        $denominazione = trim((string) ($s['denominazione'] ?? ''));

        return [
            'denominazione' => $denominazione, 'codice' => (string) ($s['codice'] ?? ''),
            'esterno' => [
                'nome' => $denominazione, 'codice_fiscale' => (string) ($s['cf'] ?? ''),
                'email' => (string) (($s['pec'] ?? '') ?: ($s['email'] ?? '') ?: ($cc['email'] ?? '')), 'indirizzo' => $indirizzo,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $cc riga di convenzioni_compilate
     */
    private function richiesta(int $id, array $cc, ?int $utenteId): RichiestaProtocollo|string
    {
        $scuola = self::datiScuola($cc);
        $denominazione = $scuola['denominazione'];
        $principale = $this->file($id, 'scuola');
        if ($principale === null) {
            return 'Il file della convenzione firmata non si legge: ricaricalo o scrivi il protocollo a mano.';
        }
        $allegati = [];
        if (!empty($cc['file_allegato_scuola'])) {
            $a = $this->file($id, 'allegato');
            if ($a === null) {
                return 'L\'Allegato A della scuola non si legge: caricalo di nuovo qui.';
            }
            $allegati[] = $a;
        }

        return new RichiestaProtocollo(
            applicaA: self::CONTESTO,
            oggetto: 'Convenzione di Formazione Scuola Lavoro firmata - ' . ($denominazione !== '' ? $denominazione : 'scuola') . ($scuola['codice'] !== '' ? ' (' . $scuola['codice'] . ')' : ''),
            principale: $principale,
            allegati: $allegati,
            esterno: $scuola['esterno'],
            rifTipo: InvioProtocollo::rifPerFile(self::RIF, $principale->contenuto),
            rifId: $id,
            utenteId: $utenteId,
        );
    }

    private function file(int $id, string $quale): ?AllegatoTitulus
    {
        $f = $this->iter->fileDaScaricare($id, $quale);
        if ($f === null) {
            return null;
        }
        $contenuto = @file_get_contents($f['percorso']);
        if ($contenuto === false || $contenuto === '') {
            return null;
        }
        try {
            return AllegatoTitulus::crea($f['nome'], $contenuto);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
