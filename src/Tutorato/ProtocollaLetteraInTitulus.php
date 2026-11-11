<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Protocollo\AllegatoTitulus;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\RichiestaProtocollo;
use InvalidArgumentException;

/**
 * «Protocolla in Titulus» per la lettera di incarico di tutorato firmata da tutti: il PDF con le firme (e, con l'iter per Uniticket,
 * la dichiarazione SCAFIS) va a Titulus secondo la regola «tutorato_lettera» (o «tutorato», o la generale); il numero ricevuto è il
 * protocollo della lettera, come se l'operatore l'avesse scritto a mano (stessa copia allo studente, stesso passo nell'iter).
 */
final class ProtocollaLetteraInTitulus
{
    public const RIF = 'tutorato_lettera';
    public const CONTESTO = 'tutorato_lettera';

    public function __construct(
        private IncaricoRepository $incarichi,
        private ArchivioIncarichi $archivio,
        private ServizioIncarichi $lettere,
        private InvioProtocollo $invio,
    ) {
    }

    public function disponibile(): bool
    {
        return $this->invio->disponibile(self::CONTESTO);
    }

    /**
     * @return array{errore: string|null, numero: string}
     */
    public function protocolla(int $id, bool $inviaCopia, string $autore, ?int $utenteId = null): array
    {
        $i = $this->incarichi->perId($id);
        if (!$i || (string) $i['stato'] !== 'firmata') {
            return ['errore' => 'La lettera non è in attesa del protocollo (serve che sia firmata da tutti e non ancora protocollata).', 'numero' => ''];
        }
        $richiesta = $this->richiesta($i, $utenteId);
        if (is_string($richiesta)) {
            return ['errore' => $richiesta, 'numero' => ''];
        }
        $n = $this->invio->numero($richiesta);
        if ($n['errore'] !== null) {
            return ['errore' => $n['errore'], 'numero' => ''];
        }
        $err = $this->lettere->protocolla($id, $n['numero'], date('Y-m-d'), $inviaCopia, $autore);
        if ($err !== null) {
            return ['errore' => 'Protocollata in Titulus (' . $n['numero'] . ') ma non registrata nel portale: ' . $err . ' Scrivi a mano il numero ' . $n['numero'] . '.', 'numero' => $n['numero']];
        }

        return ['errore' => null, 'numero' => $n['numero']];
    }

    /**
     * @param array<string, mixed> $i lettera con i dati del bando
     */
    private function richiesta(array $i, ?int $utenteId): RichiestaProtocollo|string
    {
        $pdf = $this->archivio->pdfCorrente($i);
        $principale = $pdf !== null ? $this->allegato($pdf, DatiLettera::nomeFile($i, 'firmata')) : null;
        if ($principale === null) {
            return 'Il PDF della lettera firmata non si legge: controlla il file o scrivi il protocollo a mano.';
        }
        $allegati = [];
        // La dichiarazione SCAFIS (con l'IBAN) si protocolla insieme alla lettera solo nell'iter per Uniticket, come nel passo manuale
        $scafis = (string) $i['iter'] === Costanti::ITER_UNITICKET ? $this->archivio->scafisCorrente($i) : null;
        if ($scafis !== null && ($a = $this->allegato($scafis, 'SCAFIS_' . DatiLettera::nomeFile($i))) !== null) {
            $allegati[] = $a;
        }
        $indirizzo = trim(implode(', ', array_filter([trim((string) $i['indirizzo'] . ((string) $i['civico'] !== '' ? ' ' . $i['civico'] : '')), trim((string) $i['comune_residenza'])])));

        return new RichiestaProtocollo(
            applicaA: self::CONTESTO,
            oggetto: 'Lettera di incarico di tutorato - ' . trim($i['nome'] . ' ' . $i['cognome']) . ' - ' . (string) ($i['bando_titolo'] ?? '') . ' (' . $i['codice'] . ')',
            principale: $principale,
            allegati: $allegati,
            esterno: ['nome' => (string) $i['nome'], 'cognome' => (string) $i['cognome'], 'codice_fiscale' => (string) $i['codice_fiscale'], 'email' => (string) $i['email'], 'telefono' => (string) ($i['telefono'] ?? ''), 'indirizzo' => $indirizzo],
            rifTipo: self::RIF,
            rifId: (int) $i['id'],
            utenteId: $utenteId,
        );
    }

    private function allegato(string $percorso, string $nome): ?AllegatoTitulus
    {
        $c = @file_get_contents($percorso);
        if ($c === false || $c === '') {
            return null;
        }
        try {
            return AllegatoTitulus::crea($nome, $c);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
