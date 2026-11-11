<?php

declare(strict_types=1);

namespace App\Protocollo;

use Throwable;

/**
 * Ciò che serve a ogni punto del portale che protocolla con Titulus al posto del numero scritto a mano: dice se il pulsante va mostrato
 * e ottiene il numero di protocollo senza mai protocollare due volte lo stesso documento (se per quel documento Titulus ha già dato un
 * numero e la registrazione nel portale non è andata a buon fine, il numero si riusa).
 */
final class InvioProtocollo
{
    public function __construct(private ServizioProtocollo $protocollo, private ProtocolloRepository $registro)
    {
    }

    /** Collegamento pronto e regola attiva per quel contesto (o per il suo padre, o quella generale). */
    public function disponibile(string $applicaA): bool
    {
        try {
            return $this->protocollo->nonPronto() === null && $this->registro->regolaPer($applicaA) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{numero: string, errore: string|null} il numero di protocollo, oppure il motivo per cui non c'è (già scritto per chi legge)
     */
    public function numero(RichiestaProtocollo $r): array
    {
        if ($r->rifTipo !== '') {
            foreach ($this->registro->invii(50, $r->rifTipo, $r->rifId) as $i) {
                if ((string) $i['esito'] === 'ok' && (string) $i['num_prot'] !== '') {
                    return ['numero' => (string) $i['num_prot'], 'errore' => null];
                }
            }
        }
        $esito = $this->protocollo->protocolla($r);
        if (!$esito->ok) {
            return ['numero' => '', 'errore' => 'Titulus: ' . $esito->messaggio];
        }
        if ($esito->numProt === '') {
            return ['numero' => '', 'errore' => 'La regola avvia l\'iter di approvazione: il numero di protocollo non c\'è ancora (' . $esito->messaggio . ') Quando Titulus lo assegna, scrivilo a mano. Per avere il numero subito la regola deve protocollare subito.'];
        }

        return ['numero' => $esito->numProt, 'errore' => null];
    }
}
