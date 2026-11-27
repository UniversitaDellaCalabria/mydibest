<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Forms\EsportazioneRisposte;

/**
 * La tabella di monitoraggio pronta per TabellaEsportabile (Excel, PDF, CSV): colonne, intestazioni e ordine da ConfigurazioneCampi, che parte da
 * ColonneMonitoraggio (le 22 colonne dell'Excel più «Sottotitolo iniziativa» dopo il titolo e «Stato» in fondo: 24) e applica quello che ha cambiato
 * l'amministratore (intestazioni, ordine, colonne nascoste fuori); SOLO le righe inviate al file Excel (nel_monitoraggio = 1). La colonna di un campo del form
 * nascosto resta vuota (le richieste già arrivate conservano i loro valori nel database). I campi nuovi che diventano una colonna (x_<n>) e le colonne nuove degli
 * operatori (y_<n>) stanno dove le mette ConfigurazioneCampi; le date dei campi nuovi escono gg/mm/aaaa.
 */
final class EsportazioneMonitoraggio
{
    /**
     * Date gg/mm/aaaa, descrizione senza formattazione, modalità e stato in parole. Con $neutralizza (solo per il CSV) le celle che un foglio di calcolo
     * leggerebbe come formula ricevono un apostrofo davanti, come in EsportazioneRisposte.
     *
     * @param list<array<string, mixed>> $righe righe di pubblicazioni (quelle fuori dal monitoraggio si scartano)
     * @param ConfigurazioneCampi|null $config colonne e intestazioni impostate dall'amministratore (senza: quelle di partenza)
     * @param list<string>|null $soloColonne le sole colonne da esportare (chi vede solo le sue: ColonneDiChi::visibili); null = tutte
     * @return array{intestazioni: list<string>, righe: list<list<string>>}
     */
    public static function tabella(array $righe, bool $neutralizza = false, ?ConfigurazioneCampi $config = null, ?array $soloColonne = null): array
    {
        $config ??= ConfigurazioneCampi::predefinita();
        $colonne = $config->colonne();
        if ($soloColonne !== null) {
            $colonne = array_values(array_filter($colonne, static fn (array $c): bool => in_array($c['chiave'], $soloColonne, true)));
        }
        $chiavi = array_column($colonne, 'chiave');
        $vuote = array_flip(array_filter($chiavi, static fn (string $k): bool => $config->colonnaDaCampoNascosto($k)));
        $tipiNuovi = [];
        foreach ($config->campiExtra() as $x) {
            $tipiNuovi[$x['chiave']] = $x['tipo'];
        }
        $out = [];
        foreach ($righe as $r) {
            if ((int) ($r['nel_monitoraggio'] ?? 0) !== 1) {
                continue;
            }
            $riga = [];
            foreach ($chiavi as $k) {
                $riga[] = isset($vuote[$k]) ? '' : (isset($tipiNuovi[$k]) ? ConfigurazioneCampi::valoreInParole($tipiNuovi[$k], (string) ($r[$k] ?? '')) : self::cella($k, $r[$k] ?? null));
            }
            $out[] = $neutralizza ? array_map([EsportazioneRisposte::class, 'neutralizza'], $riga) : $riga;
        }

        return ['intestazioni' => array_column($colonne, 'intestazione'), 'righe' => $out];
    }

    /** Lo stato in parole («In lavorazione»…), per le pagine. */
    public static function stato(string $stato): string
    {
        return Costanti::STATI[$stato] ?? $stato;
    }

    /** La modalità in parole («In presenza»…). */
    public static function modalita(string $modalita): string
    {
        return Costanti::MODALITA[$modalita] ?? $modalita;
    }

    /** Gg/mm/aaaa da «Y-m-d» (vuoto se manca o non è una data). */
    public static function data(?string $data): string
    {
        $data = substr((string) $data, 0, 10);

        return RegolePubblicazione::data($data) ? substr($data, 8, 2) . '/' . substr($data, 5, 2) . '/' . substr($data, 0, 4) : '';
    }

    /** La descrizione come testo semplice: senza tag, con gli a capo come spazi e le entità tradotte. */
    public static function testoSemplice(string $html): string
    {
        $html = (string) preg_replace('#<br\s*/?>|</p>\s*<p[^>]*>|</li>\s*<li[^>]*>#i', ' ', $html);
        $testo = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $testo));
    }

    private static function cella(string $chiave, mixed $valore): string
    {
        $v = (string) ($valore ?? '');

        return match ($chiave) {
            'data_evento' => self::data($v),
            'descrizione' => self::testoSemplice($v),
            'modalita' => self::modalita($v),
            'stato' => self::stato($v),
            default => $v,
        };
    }
}
