<?php

declare(strict_types=1);

namespace App\Protocollo;

use Unical\Titulus\AllegatoTitulus;

/** Cosa si vuole mandare a Titulus: la richiesta del portale a cui appartiene, l'oggetto, i file e le persone coinvolte. */
final class RichiestaProtocollo
{
    /**
     * @param string $applicaA contesto della regola da usare (es. «convenzione_fsl», «pratica:12»); se non c'è una regola dedicata vale quella «generale»
     * @param list<AllegatoTitulus> $allegati file oltre al principale
     * @param array{nome?: string, cognome?: string, codice_fiscale?: string, email?: string, telefono?: string, fax?: string, indirizzo?: string} $esterno
     *        chi scrive (arrivo) o a chi si scrive (partenza)
     * @param array{nome: string, uff_nome: string, uff_cod: string}|null $mittenteInterno per i documenti tra uffici
     */
    public function __construct(
        public readonly string $applicaA,
        public readonly string $oggetto,
        public readonly AllegatoTitulus $principale,
        public readonly array $allegati = [],
        public readonly array $esterno = [],
        public readonly ?array $mittenteInterno = null,
        public readonly string $rifTipo = '',
        public readonly ?int $rifId = null,
        public readonly ?int $utenteId = null,
        public readonly ?int $regolaId = null,
    ) {
    }

    /** Stessa richiesta con il mittente interno indicato (per i documenti tra uffici). @param array{nome: string, uff_nome: string, uff_cod: string} $mittente */
    public function conMittenteInterno(array $mittente): self
    {
        return new self($this->applicaA, $this->oggetto, $this->principale, $this->allegati, $this->esterno, $mittente, $this->rifTipo, $this->rifId, $this->utenteId, $this->regolaId);
    }
}
