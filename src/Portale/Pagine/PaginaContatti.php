<?php

declare(strict_types=1);

namespace App\Portale\Pagine;

use App\Http\Pagina;
use App\Http\Richiesta;
use App\Http\Risposta;
use App\Http\Vista;
use App\Portale\ContattiRepository;

/**
 * «Chi contattare per cosa» (contatti.php): i recapiti istituzionali che il Dipartimento mostra agli studenti, scritti dal pannello
 * (PaginaContattiPannello). Pagina pubblica di sola lettura. Il disegno è in viste/pubblico/contatti.php; qui si prepara solo quello che il modello mostra.
 */
final class PaginaContatti implements Pagina
{
    public function __construct(private readonly ContattiRepository $contatti)
    {
    }

    public function __invoke(Richiesta $r): Risposta
    {
        $contatti = array_map(static fn (array $c): array => $c + [
            'esterno' => preg_match('#^https?://#i', (string) $c['link_url']) === 1,
            'telefono_href' => preg_replace('/[^0-9+]/', '', (string) $c['telefono']),
            'link_testo' => $c['link_etichetta'] !== '' ? $c['link_etichetta'] : $c['link_url'],
        ], $this->contatti->visibili());

        return Risposta::html(Vista::pagina('Contatti', Vista::rendi('pubblico/contatti', ['contatti' => $contatti])));
    }
}
