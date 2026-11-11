<?php

declare(strict_types=1);

namespace App\Tutorato;

/**
 * Eliminazione di un bando di tutorato (per un bando di prova o inserito per sbaglio). Si elimina solo un bando SENZA lettere, nemmeno
 * annullate: le lettere hanno valore (firme, protocollo, pagamenti) e restano sempre nell'archivio. Con il bando si portano via i PDF
 * dei suoi decreti.
 */
final class ServizioBandi
{
    public function __construct(
        private BandoRepository $bandi,
        private IncaricoRepository $incarichi,
        private FileTutorato $file
    ) {
    }

    /** Ritorna l'errore o null se il bando è stato eliminato. */
    public function elimina(int $id): ?string
    {
        $bando = $id > 0 ? $this->bandi->perId($id) : null;
        if ($bando === null) {
            return 'Bando non trovato.';
        }
        if ($this->incarichi->delBando($id) !== []) {
            return 'Il bando ha delle lettere (anche annullate): non si può eliminare. Elimina solo i bandi senza lettere.';
        }
        if ($this->bandi->elimina($id) !== 1) {
            return 'Il bando non è stato eliminato: riprova.';
        }
        $this->file->eliminaDecretiBando($bando);

        return null;
    }
}
