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
        private ?OriginaleDaTitulus $originale = null,
    ) {
    }

    public function disponibile(): bool
    {
        return $this->invio->disponibile(self::CONTESTO);
    }

    /**
     * @param array<string, mixed> $dati data_stipula (valida dal), scadenza (valida fino al) e docenti (righe ['nome', 'email']); il protocollo lo dà Titulus
     * @param string $pdf PDF con la segnatura caricato a mano (facoltativo: se vuoto vale quello firmato dal Direttore)
     * @param string $allegato Allegato A caricato dall'ufficio (facoltativo): prende il posto di quello della scuola, va a Titulus e nel registro
     * @return array{errore: string|null, prenotazioni: int, convenzione_id: int, numero: string, esito?: array<string, mixed>}
     */
    public function protocolla(int $id, array $dati, string $pdf, string $autore, ?int $utenteId = null, string $allegato = ''): array
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
        $errore = $this->iter->erroreFile($cc, $pdf, $allegato);
        if ($errore !== null) {
            return $ko($errore);
        }
        // Se Titulus ha già dato il numero i file sono partiti: un Allegato A diverso nel registro non corrisponderebbe
        $gia = $this->invio->giaAssegnato(InvioProtocollo::rifPerFile(self::RIF, $principale->contenuto), $id, self::RIF, (string) ($cc['firmata_direttore_il'] ?? ''));        if ($gia !== '' && $allegato !== '' && $allegato !== (string) $this->file($id, 'allegato')?->contenuto) {
            return $ko('Titulus ha già dato il numero ' . $gia . ' con i file di prima: premi di nuovo senza caricare un altro Allegato A (poi lo cambi dal registro).', $gia);
        }
        // L'Allegato A nuovo si salva prima di chiedere il numero: se poi qualcosa va storto, al secondo tentativo è quello già partito
        $errore = $this->iter->sostituisciAllegato($cc, $allegato);
        if ($errore !== null) {
            return $ko($errore);
        }
        $cc = $this->compilate->perId($id) ?? $cc;
        $allegati = [];
        if (!empty($cc['file_allegato_scuola'])) {
            $a = $this->file($id, 'allegato');
            if ($a === null) {
                return $ko('L\'Allegato A non si legge o non è un PDF: caricalo di nuovo qui.');
            }
            $allegati[] = $a;
        }
        $scuola = ProtocollaConvenzioneInTitulus::datiScuola($cc);
        $richiesta = new RichiestaProtocollo(
            applicaA: self::CONTESTO,
            oggetto: 'Convenzione di Formazione Scuola Lavoro firmata dal Direttore - ' . ($scuola['denominazione'] !== '' ? $scuola['denominazione'] : 'scuola') . ($scuola['codice'] !== '' ? ' (' . $scuola['codice'] . ')' : ''),
            principale: $principale,
            allegati: $allegati,
            esterno: $scuola['esterno'],
            rifTipo: InvioProtocollo::rifPerFile(self::RIF, $principale->contenuto),
            rifId: $id,
            utenteId: $utenteId,
        );
        $n = $this->invio->numero($richiesta, self::RIF, (string) ($cc['firmata_direttore_il'] ?? ''));
        if ($n['errore'] !== null) {
            return $ko($n['errore']);
        }
        // la data di protocollo è quella dell'invio riuscito: se il numero era già stato dato (e la registrazione era fallita) non è oggi
        $dataProtocollo = (string) ($n['data'] ?? date('Y-m-d'));

        // Senza un PDF caricato a mano (né ora né prima dall'ufficio), il PDF registrato è una COPIA di quello firmato con la stampigliatura di protocollo
        // e la scritta «copia per consultazione» (se il PDF lo permette): il PDF firmato non si modifica mai
        if ($pdf === '' && !$this->iter->haProtocollatoAMano($cc)) {
            $stampigliato = Stampigliatura::sulPdf($principale->contenuto, $n['numero'], $dataProtocollo, 'copia per consultazione') ?? '';
            $pdf = $this->iter->erroreFile($cc, $stampigliato, '') === null ? $stampigliato : '';
        }
        $r = $this->iter->completa($id, $pdf, ['protocollo' => $n['numero'], 'protocollo_data' => $dataProtocollo, 'data_stipula' => $dal, 'scadenza' => $al, 'docenti' => $dati['docenti'] ?? []], $autore, '', hash('sha256', $principale->contenuto));
        if ($r['errore'] !== null) {
            return $ko('Protocollata in Titulus (' . $n['numero'] . ') ma non registrata nel portale: ' . $r['errore'] . ' Scrivi a mano il numero ' . $n['numero'] . '.', $n['numero']);
        }

        // Registrata: il file originale si riprende da Titulus (non blocca niente; se non arriva lo riprova il cron e l'ufficio lo vede)
        $this->originale?->scarica($id);

        return ['errore' => null, 'prenotazioni' => $r['prenotazioni'], 'convenzione_id' => $r['convenzione_id'], 'numero' => $n['numero'], 'esito' => $r['esito'] ?? []];
    }

    /**
     * «Registra il numero a mano» per il protocollo definitivo: come completa(), ma con la prenotazione dell'invio in mano, così non si scrive un
     * numero mentre Titulus (o il protocollo automatico) sta protocollando lo stesso documento, né un secondo numero dopo il primo.
     *
     * @param array<string, mixed> $dati
     * @return array{errore: string|null, prenotazioni: int, convenzione_id: int, esito?: array<string, mixed>}
     */
    public function completaAMano(int $id, string $pdf, array $dati, string $autore, string $allegato = ''): array
    {
        $cc = $this->compilate->perId($id);
        if (!$cc) {
            return ['errore' => 'Convenzione non trovata.', 'prenotazioni' => 0, 'convenzione_id' => 0];
        }
        $r = $this->invio->aMano($this->iter->rifDefinitivo($cc), $id, trim((string) ($dati['protocollo'] ?? '')), fn (): array => $this->iter->completa($id, $pdf, $dati, $autore, $allegato), self::RIF, (string) ($cc['firmata_direttore_il'] ?? ''));

        return $r['errore'] !== null ? ['errore' => $r['errore'], 'prenotazioni' => 0, 'convenzione_id' => 0] : $r['valore'];
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
