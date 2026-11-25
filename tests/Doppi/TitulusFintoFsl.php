<?php

declare(strict_types=1);

namespace Tests\Doppi;

use RuntimeException;
use Unical\Titulus\ClienteTitulus;
use Unical\Titulus\ConfigTitulus;
use Unical\Titulus\FabbricaClienteTitulus;
use Unical\Titulus\RispostaPersaTitulus;

/**
 * Titulus finto per le prove dell'iter delle convenzioni: non esce in rete, conta i salvataggi e restituisce il file come l'ha ricevuto (SENZA
 * stampigliatura, come fa il vero). È anche la sua fabbrica, così basta un oggetto.
 *
 * $modo: ok | errore (rifiuta) | persa (la risposta non arriva: l'esito è incerto) | iter (bozza salvata, numero di protocollo non ancora assegnato).
 * $modoFile: originale (il file inviato) | nessuno (nessun file) | impronta (l'impronta dichiarata non è quella del contenuto) | altro (un file diverso, impronta giusta).
 */
final class TitulusFintoFsl implements ClienteTitulus, FabbricaClienteTitulus
{
    public int $salvataggi = 0;
    public string $modo = 'ok';
    public string $modoFile = 'originale';
    public string $ultimo = '';
    /** @var (callable(): void)|null eseguito mentre il salvataggio è «in volo» */
    public $inVolo = null;
    public ?string $fileContatore = null;
    public int $attesaMicrosecondi = 0;

    public function crea(ConfigTitulus $config, string $password): ClienteTitulus
    {
        return $this;
    }

    public function salvaDocumento(string $xmlDocumento, array $allegati, bool $inviaEmail): array
    {
        ++$this->salvataggi;
        if ($this->fileContatore !== null) {
            file_put_contents($this->fileContatore, 'x', FILE_APPEND | LOCK_EX);
        }
        if ($this->inVolo !== null) {
            ($this->inVolo)();
        }
        if ($this->attesaMicrosecondi > 0) {
            usleep($this->attesaMicrosecondi);
        }
        if ($this->modo === 'persa') {
            throw new RispostaPersaTitulus('timeout: nessuna risposta da Titulus');
        }
        if ($this->modo === 'errore') {
            throw new RuntimeException('Titulus ha rifiutato il documento (prova)');
        }
        $this->ultimo = $allegati[0]->contenuto;
        $n = str_pad((string) (1000 + $this->salvataggi), 7, '0', STR_PAD_LEFT);

        // iter di approvazione: Titulus salva la bozza e il numero di protocollo arriverà dopo
        return ['nrecord' => 'R' . $n, 'num_prot' => $this->modo === 'iter' ? '' : '2026-UCALPRG-' . $n, 'url' => ''];
    }

    public function fascicola(string $xmlFascicolo, string $matricolaRpa): void
    {
    }

    public function infoRecord(string $nrecord): array
    {
        return [];
    }

    public function urlDocumento(string $id): ?string
    {
        return null;
    }

    public function fileDelDocumento(string $id): array
    {
        if ($this->modoFile === 'nessuno') {
            return [];
        }
        $contenuto = $this->contenutoRestituito();

        return [['titolo' => 'convenzione.pdf', 'fileId' => 'f1', 'principale' => true, 'sha256' => $this->modoFile === 'impronta' ? hash('sha256', 'un altro file') : hash('sha256', $contenuto)]];
    }

    public function scaricaFile(string $fileId): string
    {
        return $this->contenutoRestituito();
    }

    public function prova(): string
    {
        return 'ok';
    }

    private function contenutoRestituito(): string
    {
        return $this->modoFile === 'altro' ? "%PDF-1.7\nun altro file\n%%EOF" : $this->ultimo;
    }
}
