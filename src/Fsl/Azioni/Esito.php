<?php

declare(strict_types=1);

namespace App\Fsl\Azioni;

/**
 * L'esito di un'azione dell'ufficio sulle scuole e le convenzioni: il messaggio da mostrare, il tono, la riga del registro delle operazioni (o null)
 * e il riquadro della scheda a cui tornare. Lo usano la pagina «Scuole e convenzioni» e, finché esiste, admin/fsl.php: lo stesso testo e lo stesso registro.
 *
 * @phpstan-type EsitoAzione array{messaggio: string, tono: string, audit: ?array{0: string, 1: array<string, mixed>}, ancora: string}
 */
final class Esito
{
    /**
     * @param ?array{0: string, 1: array<string, mixed>} $audit
     * @return array{messaggio: string, tono: string, audit: ?array{0: string, 1: array<string, mixed>}, ancora: string}
     */
    public static function fa(string $messaggio, string $tono, ?array $audit = null, string $ancora = ''): array
    {
        return ['messaggio' => $messaggio, 'tono' => $tono, 'audit' => $audit, 'ancora' => $ancora];
    }

    /**
     * Un'operazione che può fallire con un testo (null = riuscita): errore in rosso, altrimenti il messaggio di riuscita e la riga del registro.
     *
     * @param array<string, mixed> $dettagli
     * @return array{messaggio: string, tono: string, audit: ?array{0: string, 1: array<string, mixed>}, ancora: string}
     */
    public static function daErrore(?string $errore, string $riuscita, string $azioneAudit, array $dettagli, string $ancora, string $tonoRiuscita = 'success'): array
    {
        return $errore !== null
            ? self::fa($errore, 'danger')
            : self::fa($riuscita, $tonoRiuscita, [$azioneAudit, $dettagli], $ancora);
    }
}
