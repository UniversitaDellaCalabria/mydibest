<?php

declare(strict_types=1);

namespace App\Portale\Area;

/**
 * Il filtro dei profili dell'area personale («Tutto / Studente / Docente…»): l'ultima scelta si ricorda in sessione e si riapplica quando si torna
 * all'area senza dire niente. Solo regole (senza database): scegli() decide quale profilo mostrare e cosa ricordare.
 */
final class FiltroProfilo
{
    /** Valore di ?p= che chiede esplicitamente «Tutto» (e lo fa ricordare). */
    public const TUTTO = 'tutto';

    /**
     * @param list<string> $profili i profili della persona
     * @param string|null $richiesto il ?p= della richiesta (un profilo, «tutto» o niente)
     * @param string|null $salvato l'ultima scelta tenuta in sessione
     * @return array{scelto: string|null, salva: string|null} scelto = il profilo da mostrare (null = tutti); salva = cosa tenere in sessione (null = lascia com'è)
     */
    public static function scegli(array $profili, ?string $richiesto, ?string $salvato): array
    {
        if (count($profili) < 2) {
            return ['scelto' => null, 'salva' => null];
        }
        if ($richiesto === self::TUTTO) {
            return ['scelto' => null, 'salva' => self::TUTTO];
        }
        if ($richiesto !== null && in_array($richiesto, $profili, true)) {
            return ['scelto' => $richiesto, 'salva' => $richiesto];
        }
        if ($salvato !== null && in_array($salvato, $profili, true)) {
            return ['scelto' => $salvato, 'salva' => null];
        }

        return ['scelto' => null, 'salva' => null];
    }
}
