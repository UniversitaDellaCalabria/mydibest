<?php

declare(strict_types=1);

namespace App\Iscrizioni\Vista;

/**
 * Messaggio che l'Area personale mostra una volta sola dopo un'azione: testo semplice + tipo (success, warning, danger, info).
 * $htmlExtra è un pezzo di HTML costruito dal portale (già con l'escape dei dati) da mostrare sotto il testo, per esempio le istruzioni con i link.
 * La pagina lo passa a flash_set() e lo mostra con flash_html().
 */
final class MessaggioArea implements \Stringable
{
    public function __construct(
        public readonly string $testo,
        public readonly string $tipo = 'success',
        public readonly string $htmlExtra = '',
    ) {
    }

    public function __toString(): string
    {
        return $this->testo . ($this->htmlExtra !== '' ? ' ' . $this->htmlExtra : '');
    }
}
