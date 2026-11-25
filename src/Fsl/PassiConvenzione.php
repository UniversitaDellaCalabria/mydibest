<?php

declare(strict_types=1);

namespace App\Fsl;

/**
 * I cinque passi dell'iter di una convenzione compilata online, con le parole di sempre: UNA fonte sola per la pagina della scuola
 * (convenzione_online.php) e per la scheda della scuola nel pannello (viste/pannello/_fsl_scuole_iter.php), così chi guarda da una parte e dall'altra legge
 * lo stesso passo. Per ui_passi() di inc/ui.php.
 */
final class PassiConvenzione
{
    public const TITOLI = [
        'Compilata online, in attesa del PDF firmato dalla scuola',
        'Caricata dalla scuola: da controllare e prendere in carico',
        'Dal Direttore per la firma',
        'Firmata dal Direttore: da protocollare e registrare',
        'Registrata: convenzione attiva, prenotazioni coperte',
    ];

    /** @return list<array{titolo: string}> i passi nel formato di ui_passi() */
    public static function passi(): array
    {
        return array_map(static fn (string $t): array => ['titolo' => $t], self::TITOLI);
    }

    /**
     * Il passo in corso (0-4). Una compilazione senza il PDF firmato della scuola è al primo passo (anche se è solo avviata o rifiutata).
     */
    public static function corrente(string $statoIter, bool $haFileFirmato): int
    {
        if (!$haFileFirmato) {
            return 0;
        }

        return match ($statoIter) {
            Costanti::ITER_CARICATA, Costanti::ITER_RIFIUTATA => 1,
            Costanti::ITER_IN_CARICO, Costanti::ITER_DA_FIRMARE => 2,
            Costanti::ITER_FIRMATA => 3,
            Costanti::ITER_PROTOCOLLATA => 5,
            default => 1,
        };
    }
}
