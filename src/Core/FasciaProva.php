<?php

declare(strict_types=1);

namespace App\Core;

/**
 * La fascia arancione «AMBIENTE DI PROVA» in cima a ogni pagina HTML della copia di prova.
 * Si mette da sola: config.php (con l'ambiente di prova acceso) avvia il buffer di uscita con inietta(), che aggiunge la fascia
 * subito dopo il tag <body> di qualunque pagina completa (sito, pannello, pagine con l'HTML proprio). Header e menu non si toccano.
 * Usa le variabili di colore del portale (--bs-warning, arancione) con il colore fisso come ripiego; il testo è scuro: si legge anche col tema scuro.
 */
final class FasciaProva
{
    public static function html(string $emailProva = ''): string
    {
        $testo = Ambiente::TESTO_FASCIA . ($emailProva !== '' ? ' · email solo a ' . $emailProva : '');

        return '<div id="fascia-ambiente-prova" role="status" style="position:sticky;top:0;left:0;right:0;z-index:2147483000;box-sizing:border-box;width:100%;'
            . 'padding:6px 12px;text-align:center;font:700 14px/1.3 Arial,Helvetica,sans-serif;letter-spacing:.02em;'
            . 'background:var(--bs-warning,#fd7e14);color:#1a0f00;border-bottom:2px solid #1a0f00">'
            . htmlspecialchars($testo, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    /**
     * Aggiunge la fascia dopo <body…> se la risposta è una pagina HTML completa; altrimenti (JSON, CSV, PDF, frammenti) la lascia com'è.
     *
     * @param list<string> $intestazioni intestazioni già inviate (headers_list())
     */
    public static function inietta(string $uscita, string $emailProva = '', array $intestazioni = []): string
    {
        foreach ($intestazioni as $h) {
            if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') === false) {
                return $uscita;
            }
        }
        if (stripos($uscita, 'fascia-ambiente-prova') !== false) {
            return $uscita;
        }
        $inizio = substr($uscita, 0, 3000);
        if (stripos($inizio, '<!doctype') === false && stripos($inizio, '<html') === false) {
            return $uscita;
        }

        return (string) preg_replace_callback('/<body\b[^>]*>/i', static fn (array $m): string => $m[0] . self::html($emailProva), $uscita, 1);
    }

    /** Funzione per ob_start(): è chiamata da PHP a fine pagina (o a ogni svuotamento del buffer). */
    public static function callback(string $uscita): string
    {
        return self::inietta($uscita, Ambiente::corrente()->emailProva(), headers_list());
    }
}
