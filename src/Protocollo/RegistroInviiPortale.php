<?php

declare(strict_types=1);

namespace App\Protocollo;

use Unical\Titulus\RegistroInvii;

/**
 * Il registro degli invii del pacchetto Unical\Titulus (interfaccia RegistroInvii) scritto nel database del portale (titulus_invii).
 * Si crea per ogni invio, perché al registro del pacchetto si aggiungono ciò che è solo del portale: la regola usata, la richiesta
 * (contesto) e chi l'ha fatta.
 */
final class RegistroInviiPortale implements RegistroInvii
{
    /** @param array<string, mixed>|null $regola */
    public function __construct(private ProtocolloRepository $repo, private RichiestaProtocollo $richiesta, private ?array $regola)
    {
    }

    public function registra(array $riga): int
    {
        return $this->repo->registraInvio($riga + [
            'regola_id' => $this->regola['id'] ?? null,
            'applica_a' => $this->richiesta->applicaA,
            'utente_id' => $this->richiesta->utenteId,
            // Il titolo con cui il file principale parte per Titulus: serve a ritrovarlo tra i file del documento
            'file_principale' => $this->richiesta->principale->nomeFile,
        ]);
    }

    public function numeroAssegnato(string $rifTipo, ?int $rifId): ?string
    {
        foreach ($this->repo->invii(50, $rifTipo, $rifId) as $i) {
            if ((string) $i['esito'] === 'ok' && (string) $i['num_prot'] !== '') {
                return (string) $i['num_prot'];
            }
        }

        return null;
    }
}
