<?php

declare(strict_types=1);

namespace App\Portale\Vista;

/**
 * Voci per il menu «Altro ▾» del pannello (pn_altro): un link, o un'azione che invia un modulo (POST con csrf), con la conferma di
 * assets/js/conferma.js. Le voci pericolose (eliminare, annullare, rifiutare) vanno in fondo al menu, dopo un filo, in rosso:
 * ordina() le sposta e le separa, qualunque sia l'ordine in cui la pagina le ha elencate.
 * Una voce che invia un modulo porta il suo <form> dentro la <li>: l'azione funziona come il pulsante che sostituisce (stesso name e value).
 */
final class VociMenu
{
    /** Segno che riconosce una voce pericolosa dentro l'HTML già pronto. */
    private const PERICOLO = 'pn-voce-pericolo';

    /** Una voce che apre un indirizzo. $attributi = attributi HTML già pronti (es. target="_blank" rel="noopener"). */
    public static function link(string $href, string $testo, string $icona = '', bool $pericolo = false, string $attributi = ''): string
    {
        return '<a class="dropdown-item' . ($pericolo ? ' text-danger ' . self::PERICOLO : '') . '" href="' . self::h($href) . '"'
            . ($attributi !== '' ? ' ' . $attributi : '') . '>' . self::icona($icona) . self::h($testo) . '</a>';
    }

    /**
     * Una voce che invia un modulo. $campoCsrf = HTML del campo nascosto del token (csrf_field()); $nome e $valore = name e value del pulsante;
     * ($nome vuoto = il pulsante non porta name e value: l'azione sta nei campi nascosti);
     * $campi = altri campi nascosti (nome => valore); $conferma = domanda di conferma (vuota = nessuna); $azione = indirizzo del modulo (vuoto = la pagina);
     * $scrivi = parola da scrivere per confermare (eliminazioni definitive, serve $conferma).
     *
     * @param array<string, string|int> $campi
     */
    public static function post(string $campoCsrf, string $nome, string $valore, string $testo, string $icona = '', string $conferma = '', bool $pericolo = false, array $campi = [], string $azione = '', string $scrivi = ''): string
    {
        $nascosti = '';
        foreach ($campi as $k => $v) {
            $nascosti .= '<input type="hidden" name="' . self::h((string) $k) . '" value="' . self::h((string) $v) . '">';
        }

        return '<form method="POST"' . ($azione !== '' ? ' action="' . self::h($azione) . '"' : '') . '>' . $campoCsrf . $nascosti
            . '<button type="submit"' . ($nome !== '' ? ' name="' . self::h($nome) . '" value="' . self::h($valore) . '"' : '') . ' class="dropdown-item' . ($pericolo ? ' text-danger ' . self::PERICOLO : '') . '"'
            . ($conferma !== '' ? ' data-conferma="' . self::h($conferma) . '"' . ($pericolo ? ' data-conferma-livello="pericolo"' : '') . ($scrivi !== '' ? ' data-conferma-scrivi="' . self::h($scrivi) . '"' : '') : '')
            . '>' . self::icona($icona) . self::h($testo) . '</button></form>';
    }

    /**
     * Le voci in ordine per il menu: prima le normali (nell'ordine dato), poi un filo, poi le pericolose. Le voci vuote si saltano.
     *
     * @param list<string> $voci
     * @return list<string>
     */
    public static function ordina(array $voci): array
    {
        $normali = [];
        $pericolose = [];
        foreach ($voci as $v) {
            if ($v === '') {
                continue;
            }
            if (str_contains($v, self::PERICOLO)) {
                $pericolose[] = $v;
            } else {
                $normali[] = $v;
            }
        }
        if ($normali && $pericolose) {
            $normali[] = '<hr class="dropdown-divider">';
        }

        return [...$normali, ...$pericolose];
    }

    /**
     * Il menu «Altro ▾» piccolo per una riga di tabella o una scheda stretta (stesso schema di pn_altro, in formato ridotto).
     * Le voci passano da ordina(). Si apre con posizione fissa (data-bs-popper-config), così una tabella con scorrimento non lo taglia.
     *
     * @param list<string> $voci
     */
    public static function riga(array $voci, string $etichetta = 'Altro'): string
    {
        $voci = self::ordina($voci);
        if (!$voci) {
            return '';
        }

        return '<div class="dropdown pn-altro pn-altro-riga d-inline-block"><button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" title="' . self::h($etichetta) . '">'
            . '<i class="fa fa-ellipsis me-1" aria-hidden="true"></i>' . self::h($etichetta) . '</button>'
            . '<ul class="dropdown-menu dropdown-menu-end">' . implode('', array_map(static fn (string $v): string => '<li>' . $v . '</li>', $voci)) . '</ul></div>';
    }

    private static function icona(string $icona): string
    {
        return $icona !== '' ? '<i class="fa ' . self::h($icona) . ' me-2" aria-hidden="true"></i>' : '';
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
