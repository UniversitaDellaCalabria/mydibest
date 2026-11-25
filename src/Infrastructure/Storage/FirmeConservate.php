<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Core\File;
use App\Core\Sito;

/**
 * Il PDF che il Direttore ha appena firmato con la firma remota quando il portale non riesce a registrarlo subito (il blocco della convenzione è
 * occupato): la firma è già stata fatta con un codice OTP che non si può riusare, e non si chiede un secondo codice né si salvano le credenziali.
 * Il PDF si conserva in una cartella chiusa al web, LEGATO alla convenzione (genere + id) e all'impronta (SHA-256) del file da cui si è partiti, insieme
 * allo stato e al link di quel momento. Al tentativo successivo (il Direttore riapre la pagina, o il cron) il servizio della convenzione lo registra
 * SOLO se stato, link e file di partenza sono ancora gli stessi; altrimenti lo scarta e avvisa l'ufficio.
 *
 * Una sola firma conservata per convenzione: una nuova la sostituisce. Qui non si decide niente: si conserva, si legge, si toglie.
 */
final class FirmeConservate
{
    public const CARTELLA = 'uploads/firme_conservate/';
    private const MAX = 25 * 1024 * 1024;
    private const GENERI = ['fsl', 'tesi'];

    public function __construct(private Upload $upload, private Sito $sito)
    {
    }

    /**
     * Conserva il PDF firmato. Ritorna false se non si riesce a scrivere (chi chiama dice al Direttore di ricaricare il file).
     *
     * @param string $stato lo stato della convenzione quando è partita la firma (es. «da_firmare»)
     * @param string $token il link personale del Direttore di quel momento
     * @param string $improntaPartenza SHA-256 del PDF da firmare (quello dell'ufficio/della scuola), non del firmato
     * @param string $fileDiPartenza il percorso relativo di quel PDF, com'è nella riga della convenzione
     */
    public function conserva(string $genere, int $id, string $stato, string $token, string $fileDiPartenza, string $improntaPartenza, string $firmato, string $come, string $chi): bool
    {
        if (!in_array($genere, self::GENERI, true) || $id <= 0 || $firmato === '' || strlen($firmato) > self::MAX || !preg_match('/^[a-f0-9]{64}$/', $improntaPartenza)) {
            return false;
        }
        $cartella = $this->cartella();
        $this->upload->cartellaProtetta($cartella, 'Firme del Direttore fatte ma non ancora registrate: non si scaricano da qui');
        if (!is_dir($cartella)) {
            return false;
        }
        $this->scarta($genere, $id);   // una sola per convenzione
        $base = $genere . '_' . $id . '_' . substr($improntaPartenza, 0, 12) . '_' . bin2hex(random_bytes(6));
        if (@file_put_contents($cartella . $base . '.pdf', $firmato) !== strlen($firmato)) {
            File::togli($cartella . $base . '.pdf');

            return false;
        }
        $meta = ['genere' => $genere, 'id' => $id, 'stato' => $stato, 'token' => $token, 'file_di_partenza' => $fileDiPartenza, 'impronta_partenza' => $improntaPartenza,
            'impronta_firmato' => hash('sha256', $firmato), 'come' => $come, 'chi' => $chi, 'conservata_il' => date('Y-m-d H:i:s'), 'pdf' => $base . '.pdf'];
        if (@file_put_contents($cartella . $base . '.json', (string) json_encode($meta, JSON_UNESCAPED_UNICODE)) === false) {
            File::togli($cartella . $base . '.pdf');
            File::togli($cartella . $base . '.json');

            return false;
        }

        return true;
    }

    /**
     * La firma conservata di una convenzione (con il contenuto del PDF), se c'è e se il file è integro; un file manomesso o illeggibile si scarta.
     *
     * @return array{meta: array<string, mixed>, pdf: string}|null
     */
    public function di(string $genere, int $id): ?array
    {
        foreach ($this->elencoDi($genere, $id) as $meta) {
            $p = $this->percorsoPdf($meta);
            $c = $p !== null ? (string) @file_get_contents($p) : '';
            if ($c !== '' && hash('sha256', $c) === (string) ($meta['impronta_firmato'] ?? '')) {
                return ['meta' => $meta, 'pdf' => $c];
            }
            error_log('[firme conservate] file illeggibile o alterato per ' . $genere . ' ' . $id . ': scartato');
            $this->scarta($genere, $id);
        }

        return null;
    }

    public function ce(string $genere, int $id): bool
    {
        return $this->elencoDi($genere, $id) !== [];
    }

    /** @return list<int> gli id delle convenzioni di quel genere che hanno una firma conservata */
    public function ids(string $genere): array
    {
        $ids = [];
        foreach (glob($this->cartella() . $genere . '_*.json') ?: [] as $f) {
            $m = json_decode((string) @file_get_contents($f), true);
            if (is_array($m) && ($m['genere'] ?? '') === $genere && (int) ($m['id'] ?? 0) > 0) {
                $ids[(int) $m['id']] = true;
            }
        }

        return array_keys($ids);
    }

    /** Toglie la firma conservata (registrata o scartata). */
    public function scarta(string $genere, int $id): void
    {
        foreach ($this->elencoDi($genere, $id) as $meta) {
            $base = pathinfo((string) ($meta['pdf'] ?? ''), PATHINFO_FILENAME);
            if (preg_match('/^[a-z]+_\d+_[a-f0-9]{12}_[a-f0-9]{12}$/', $base)) {
                File::togli($this->cartella() . $base . '.pdf');
                File::togli($this->cartella() . $base . '.json');
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function elencoDi(string $genere, int $id): array
    {
        if (!in_array($genere, self::GENERI, true) || $id <= 0) {
            return [];
        }
        $out = [];
        foreach (glob($this->cartella() . $genere . '_' . $id . '_*.json') ?: [] as $f) {
            $m = json_decode((string) @file_get_contents($f), true);
            if (is_array($m) && ($m['genere'] ?? '') === $genere && (int) ($m['id'] ?? 0) === $id) {
                $out[] = $m;
            }
        }

        return $out;
    }

    /** Il percorso del PDF, solo se sta davvero dentro la cartella delle firme conservate. */
    private function percorsoPdf(array $meta): ?string
    {
        $base = realpath($this->cartella());
        $p = realpath($this->cartella() . basename((string) ($meta['pdf'] ?? '')));

        return $base && $p && strpos($p, $base . DIRECTORY_SEPARATOR) === 0 && is_file($p) ? $p : null;
    }

    private function cartella(): string
    {
        return rtrim($this->sito->radice(), '/\\') . '/' . self::CARTELLA;
    }
}
