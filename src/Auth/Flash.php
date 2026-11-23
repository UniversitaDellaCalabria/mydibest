<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Vista\FlashVista;
use App\Http\Avvisi;

/** Messaggi "flash" mostrati una volta sola dopo un rimando (spostati da flash_set/flash_get/flash_html di inc/base.php). */
final class Flash implements Avvisi
{
    private const CHIAVE = '_flash';

    public function __construct(private Sessione $sessione)
    {
    }

    /**
     * $htmlExtra: facoltativo, pezzo di HTML costruito dal portale (già con l'escape dei dati) che si mostra sotto il messaggio,
     * per esempio le istruzioni con i link. Il messaggio vero e proprio è sempre testo semplice.
     */
    public function imposta(mixed $messaggio, mixed $tipo = 'success', ?string $htmlExtra = null): void
    {
        $dati = ['msg' => $messaggio, 'type' => $tipo];
        if ($htmlExtra !== null && $htmlExtra !== '') {
            $dati['extra'] = $htmlExtra;
        }
        $this->sessione->scrivi(self::CHIAVE, $dati);
    }

    /** Il messaggio in attesa (tolto dalla sessione) o null. */
    public function prendi(): mixed
    {
        $f = $this->sessione->leggi(self::CHIAVE);
        if ($f !== null) {
            $this->sessione->togli(self::CHIAVE);

            return $f;
        }

        return null;
    }

    /** Avviso Bootstrap del messaggio in attesa ('' se non ce n'è). */
    public function html(): string
    {
        return FlashVista::html($this->prendi());
    }
}
