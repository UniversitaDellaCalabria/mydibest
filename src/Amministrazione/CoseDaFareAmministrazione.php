<?php

declare(strict_types=1);

namespace App\Amministrazione;

use App\Pubblicazioni\PubblicazioniRepository;

/**
 * I numeri di «Cosa devo fare» dell'Amministrazione: un calcolo solo, usato dalla scheda del modulo (admin/amministrazione_home.php),
 * dalla scrivania del pannello (admin/inizio.php), dal menu a sinistra e dalle tessere «Il mio lavoro» dell'area personale.
 * Per le richieste di pubblicazione le definizioni sono quelle della pagina delle richieste (admin/pubblicazioni.php); i numeri si contano
 * nel database (PubblicazioniRepository::contaDaFare).
 */
final class CoseDaFareAmministrazione
{
    public function __construct(private PubblicazioniRepository $pubblicazioni)
    {
    }

    /**
     * - ricevute: richieste «ricevute» non ancora nel file Excel (da guardare);
     * - lavorazione: richieste «in lavorazione» non ancora nel file Excel;
     * - da_completare: righe nel file Excel con almeno una colonna degli operatori ancora vuota (Completamento);
     * - giorni: da quanti giorni aspetta la più vecchia delle «ricevute» (0 se non ce ne sono).
     *
     * @return array{ricevute: int, lavorazione: int, da_completare: int, giorni: int}
     */
    public function pubblicazioni(): array
    {
        return $this->pubblicazioni->contaDaFare();
    }
}
