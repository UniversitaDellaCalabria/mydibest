<?php

declare(strict_types=1);

namespace App\Portale\Vista;

/** Testi comuni delle pagine pubbliche: date in italiano, descrizioni brevi delle card. */
final class Testi
{
    private const GIORNI = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
    private const MESI = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

    /** HTML: "Lunedì <span class="text-danger fw-bold">5 ottobre 2026</span>"; 'Date da definire' senza data. */
    public static function dataInItaliano(mixed $data): string
    {
        if (empty($data) || $data == '0000-00-00' || $data == '9999-12-31') {
            return 'Date da definire';
        }
        $t = strtotime((string) $data);
        if ($t === false) {
            $t = 0;
        }

        return self::GIORNI[(int) date('w', $t)] . ' <span class="text-danger fw-bold">' . date('j', $t) . ' ' . self::MESI[(int) date('n', $t)] . ' ' . date('Y', $t) . '</span>';
    }

    /**
     * HTML della descrizione breve: solo grassetto, corsivo, sottolineato e a capo, senza attributi.
     * Oltre 300 caratteri visibili si perde la formattazione e il testo viene troncato.
     */
    public static function pulisciDescrizioneBreve(string $html): string
    {
        $html = (string) preg_replace('#</p>\s*<p[^>]*>#i', '<br>', $html);
        $html = strip_tags($html, '<strong><b><em><i><u><br>');
        $html = (string) preg_replace('#<(/?)(strong|b|em|i|u|br)\b[^>]*>#i', '<$1$2>', $html);
        $html = trim((string) preg_replace('#^(?:\s|&nbsp;|<br>)+|(?:\s|&nbsp;|<br>)+$#iu', '', $html));
        $testo = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        if ($testo === '') {
            return '';
        }
        if (mb_strlen($testo) > 300) {
            return htmlspecialchars(mb_substr($testo, 0, 300));
        }

        return $html;
    }

    /**
     * HTML delle card: la descrizione breve; se manca, l'inizio della descrizione completa senza formattazione.
     *
     * @param array<string, mixed> $evento
     */
    public static function testoCardEvento(array $evento, int $max = 220): string
    {
        $breve = self::pulisciDescrizioneBreve((string) ($evento['descrizione_breve'] ?? ''));
        if ($breve !== '') {
            return $breve;
        }
        $testo = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', (string) ($evento['descrizione'] ?? ''))), ENT_QUOTES, 'UTF-8')));

        return htmlspecialchars(mb_strimwidth($testo, 0, $max, '…'));
    }

    /** @var list<string> tag ammessi da pulisciHtmlSemplice(), senza attributi (tranne href sugli <a>) */
    private const TAG_HTML_SEMPLICE = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'a'];

    /**
     * HTML ridotto a un sottoinsieme sicuro (DOMDocument, non regex): solo p, br, strong, b, em, i, u, ul, ol, li, a,
     * senza nessun attributo tranne href sui link; il link resta solo se comincia con http://, https:// o mailto:
     * (altrimenti il tag sparisce e resta il suo testo), e in tal caso riceve rel="noopener noreferrer" target="_blank".
     * script e style spariscono con tutto il loro contenuto; ogni altro tag non ammesso lascia solo il testo dentro.
     * Usata per le descrizioni scritte dagli utenti del pannello (es. MyForms) sia al salvataggio sia in uscita.
     */
    public static function pulisciHtmlSemplice(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument();
        $precedente = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($precedente);
        $radice = $doc->getElementsByTagName('div')->item(0);

        return $radice ? self::nodoHtmlSemplice($radice) : '';
    }

    private static function nodoHtmlSemplice(\DOMNode $nodo): string
    {
        $out = '';
        foreach (iterator_to_array($nodo->childNodes) as $figlio) {
            if ($figlio instanceof \DOMText) {
                $out .= htmlspecialchars($figlio->textContent, ENT_QUOTES, 'UTF-8');
                continue;
            }
            if (!$figlio instanceof \DOMElement) {
                continue; // commenti, CDATA, istruzioni di elaborazione: via
            }
            $tag = strtolower($figlio->tagName);
            if (in_array($tag, ['script', 'style'], true)) {
                continue; // via il tag e tutto il suo contenuto
            }
            if ($tag === 'br') {
                $out .= '<br>';
                continue;
            }
            $dentro = self::nodoHtmlSemplice($figlio);
            if (!in_array($tag, self::TAG_HTML_SEMPLICE, true)) {
                $out .= $dentro; // tag non ammesso: resta solo il testo dentro
                continue;
            }
            if ($tag === 'a') {
                $href = trim((string) $figlio->getAttribute('href'));
                $normalizzato = ltrim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $href));
                if ($href === '' || !preg_match('#^(https?://|mailto:)#i', $normalizzato)) {
                    $out .= $dentro; // link non ammesso: resta il testo
                    continue;
                }
                $out .= '<a href="' . htmlspecialchars($normalizzato, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer" target="_blank">' . $dentro . '</a>';
                continue;
            }
            $out .= '<' . $tag . '>' . $dentro . '</' . $tag . '>';
        }

        return $out;
    }
}
