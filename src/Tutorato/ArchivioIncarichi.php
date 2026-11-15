<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Sito;
use App\Protocollo\Stampigliatura;
use App\Infrastructure\Storage\Upload;

/** I PDF delle lettere e delle dichiarazioni di fine attività: stanno in uploads/incarichi/ (bloccata al web) e si scaricano solo dal pannello e con il link personale. */
final class ArchivioIncarichi
{
    public function __construct(private IncaricoRepository $incarichi, private Upload $upload, private Sito $sito, private string $cartella = Costanti::DIR_INCARICHI)
    {
    }

    /**
     * Salva una versione del PDF della lettera e la rende quella corrente. Ritorna il percorso relativo.
     *
     * @param array<string, mixed> $i
     */
    public function salvaLettera(array $i, string $pdf, string $passo): ?string
    {
        $rel = $this->scrivi((string) $i['codice'], $passo, $pdf);
        if ($rel === null) {
            return null;
        }
        $this->incarichi->impostaFilePdf((int) $i['id'], $rel);

        return $rel;
    }

    /**
     * Scrive un PDF nella cartella delle lettere con un nome casuale (codice, passo, caratteri casuali): percorso relativo o null.
     */
    public function scrivi(string $codice, string $passo, string $pdf): ?string
    {
        $dir = $this->sito->radice() . '/' . $this->cartella;
        $this->upload->cartellaProtetta($dir, 'Lettere di incarico: si scaricano solo dal pannello o con il link personale');
        $nome = preg_replace('/[^A-Za-z0-9_-]/', '', $codice) . '_' . $passo . '_' . bin2hex(random_bytes(4)) . '.pdf';
        if (@file_put_contents($dir . $nome, $pdf) === false) {
            return null;
        }

        return $this->cartella . $nome;
    }

    /**
     * Percorso reale del PDF corrente della lettera (solo dentro la cartella delle lettere), null se non c'è.
     *
     * @param array<string, mixed> $i
     */
    public function pdfCorrente(array $i): ?string
    {
        return $this->percorso($i['file_pdf'] ?? null);
    }

    /**
     * Dopo il protocollo: la copia della lettera firmata con la stampigliatura di protocollo (fascia rossa in testa alla prima pagina, come Titulus).
     * L'originale firmato non si tocca (è quello spedito a Titulus). Ritorna il percorso relativo, null se la copia non si può fare
     * (nessun PDF, file cifrato o con la pagina ruotata): in quel caso valgono i documenti di prima.
     *
     * @param array<string, mixed> $i
     */
    public function salvaCopiaSegnata(array $i, string $numeroProtocollo, ?string $dataIso): ?string
    {
        $originale = $this->pdfCorrente($i);
        if ($originale === null) {
            return null;
        }
        $copia = Stampigliatura::sulPdf((string) file_get_contents($originale), $numeroProtocollo, $dataIso);
        $rel = $copia !== null ? $this->scrivi((string) $i['codice'], 'protocollata', $copia) : null;
        if ($rel !== null) {
            $this->incarichi->impostaFileSegnato((int) $i['id'], $rel);
        }

        return $rel;
    }

    /** Percorso reale della copia con la segnatura, null se non c'è. @param array<string, mixed> $i */
    public function segnatoCorrente(array $i): ?string
    {
        return $this->percorso($i['file_segnato'] ?? null);
    }

    /**
     * Salva il PDF della dichiarazione individuale (SCAFIS) firmata con i dati dell'identità digitale e lo rende quello corrente. Ritorna il percorso relativo.
     *
     * @param array<string, mixed> $i
     */
    public function salvaScafis(array $i, string $pdf): ?string
    {
        $rel = $this->scrivi((string) $i['codice'], 'scafis', $pdf);
        if ($rel !== null) {
            $this->incarichi->impostaScafis((int) $i['id'], $rel);
        }

        return $rel;
    }

    /** @param array<string, mixed> $i */
    public function scafisCorrente(array $i): ?string
    {
        return $this->percorso($i['scafis_pdf'] ?? null);
    }

    /**
     * Percorso reale della dichiarazione di fine attività corrente, null se non c'è.
     *
     * @param array<string, mixed> $i
     */
    public function fineCorrente(array $i): ?string
    {
        return $this->percorso($i['fine_pdf'] ?? null);
    }

    /**
     * Cancella i PDF della lettera: quello corrente, la dichiarazione di fine attività e le versioni intermedie (hanno lo stesso codice nel nome).
     *
     * @param array<string, mixed> $i
     */
    public function eliminaDellaLettera(array $i): void
    {
        $radice = $this->sito->radice();
        foreach (['file_pdf', 'file_segnato', 'fine_pdf', 'unilav_file', 'scafis_pdf'] as $k) {
            $p = !empty($i[$k]) ? realpath($radice . '/' . $i[$k]) : false;
            $base = realpath($radice . '/' . $this->cartella);
            if ($p && $base && strpos($p, $base . DIRECTORY_SEPARATOR) === 0) {
                @unlink($p);
            }
        }
        foreach (glob($radice . '/' . $this->cartella . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $i['codice']) . '_*.pdf') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function percorso(mixed $relativo): ?string
    {
        if (empty($relativo)) {
            return null;
        }
        $base = realpath($this->sito->radice() . '/' . $this->cartella);
        $p = realpath($this->sito->radice() . '/' . $relativo);

        return ($base && $p && strpos($p, $base . DIRECTORY_SEPARATOR) === 0 && is_file($p)) ? $p : null;
    }
}
