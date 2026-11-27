<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

/**
 * Quello che il docente ha inviato, per la scheda del pannello: le stesse righe di RiepilogoRichiesta (già «escapate») con in più i file
 * scaricabili dalla pagina allegato_pubblicazione.php (che ricontrolla il permesso a ogni richiesta), e il testo da copiare per la notizia.
 */
final class VistaRichiesta
{
    /**
     * Le righe (etichetta, valore in HTML) di una richiesta; allegati e immagini sono link di scaricamento.
     *
     * @param array<string, mixed> $p la riga di pubblicazioni
     * @param list<array<string, mixed>> $allegati le righe di pubblicazioni_allegati
     * @param string $base prefisso dell'indirizzo di allegato_pubblicazione.php (dal pannello: «../»)
     * @param ConfigurazioneCampi|null $config i nomi dei campi come li ha impostati l'amministratore: un campo nascosto compare solo se la richiesta (arrivata
     *   quando era visibile) ha un valore
     * @return list<array{0: string, 1: string}>
     */
    public static function righe(array $p, array $allegati, string $base = '../', ?ConfigurazioneCampi $config = null): array
    {
        // I file entrano come link di scaricamento (RiepilogoRichiesta li scrive come nomi, senza link)
        $righe = [];
        foreach (RiepilogoRichiesta::righeConChiave($p, $allegati, $config, true) as [$chiave, $etichetta, $valore]) {
            if ($chiave === 'locandina') {
                $valore = self::elencoLink($p, $allegati, 'locandina', $base);
            } elseif ($chiave === 'allegati') {
                $valore = self::elencoLink($p, $allegati, 'allegato', $base);
            } elseif ($chiave === 'immagini') {
                $valore = self::elencoLink($p, $allegati, 'immagine', $base);
            }
            $righe[] = [$etichetta, $valore];
        }

        return $righe;
    }

    /**
     * Il testo della notizia da incollare: titolo, sottotitolo e descrizione senza formattazione, uno per riga.
     *
     * @param array<string, mixed> $p
     */
    public static function testoDaCopiare(array $p): string
    {
        $parti = array_filter([
            trim((string) ($p['titolo'] ?? '')),
            trim((string) ($p['sottotitolo'] ?? '')),
            EsportazioneMonitoraggio::testoSemplice((string) ($p['descrizione'] ?? '')),
        ], static fn (string $t): bool => $t !== '');

        return implode("\n\n", $parti);
    }

    /**
     * @param array<string, mixed> $p
     * @param list<array<string, mixed>> $allegati
     */
    private static function elencoLink(array $p, array $allegati, string $tipo, string $base): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $voci = [];
        foreach ($allegati as $a) {
            if (($a['tipo'] ?? '') !== $tipo) {
                continue;
            }
            $href = $base . 'allegato_pubblicazione.php?p=' . (int) $p['id'] . '&a=' . (int) $a['id'];
            $voci[] = '<a href="' . $h($href) . '" target="_blank" rel="noopener"><i class="fa fa-paperclip me-1" aria-hidden="true"></i>' . $h((string) $a['nome_originale']) . '</a>'
                . ' <span class="text-secondary">(' . RiepilogoRichiesta::dimensione((int) ($a['dimensione'] ?? 0)) . ')</span>';
        }

        return $voci ? implode('<br>', $voci) : '<span class="text-secondary">—</span>';
    }
}
