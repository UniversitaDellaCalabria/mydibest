<?php

declare(strict_types=1);

namespace App\Infrastructure\Documenti;

use App\Infrastructure\Pdf\Documento;

/**
 * Una tabella del pannello che si scarica in tre formati: Excel (.xlsx), PDF e CSV.
 * Ogni elenco esportabile costruisce una TabellaEsportabile e chiama scarica($_GET['scarica']):
 * il menu «Scarica» (Pannello::scarica) mette lo stesso parametro nell'indirizzo.
 */
final class TabellaEsportabile
{
    public const FORMATI = ['xlsx' => 'Excel', 'pdf' => 'PDF', 'csv' => 'CSV'];

    /**
     * @param list<string>             $intestazioni
     * @param list<list<string|int|float|null>> $righe
     * @param list<int|float>          $larghezze    pesi relativi delle colonne nel PDF (facoltativo)
     */
    private readonly array $intestazioni;
    private readonly array $righe;

    public function __construct(
        private readonly string $titolo,
        array $intestazioni,
        array $righe,
        private readonly string $sottotitolo = '',
        private readonly array $larghezze = [],
    ) {
        // chiavi numeriche e in ordine (gli elenchi del pannello hanno spesso come chiave il codice della scuola)
        $this->intestazioni = array_values($intestazioni);
        $this->righe = array_values(array_map(static fn (array $r) => array_values($r), $righe));
    }

    public function csv(): string
    {
        $f = fopen('php://temp', 'r+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, array_map([self::class, 'cella'], $this->intestazioni), ';', '"', '\\');
        foreach ($this->righe as $r) {
            fputcsv($f, array_map([self::class, 'cella'], array_values($r)), ';', '"', '\\');
        }
        rewind($f);
        $s = (string) stream_get_contents($f);
        fclose($f);

        return $s;
    }

    /** Percorso di un file temporaneo .xlsx (null se manca ZipArchive). */
    public function xlsx(): ?string
    {
        return Excel::crea([$this->titolo => $this->foglio()]);
    }

    /** Contenuto del PDF: A4 per terzi, in orizzontale se le colonne sono più di cinque. */
    public function pdf(): string
    {
        $pdf = new Documento(['piede' => $this->titolo . ' – stampato il ' . date('d/m/Y'), 'info' => ['Title' => $this->titolo, 'Creator' => 'Portale DiBEST']]);
        if (count($this->intestazioni) > 5) {
            [$pdf->larg, $pdf->alt] = [$pdf->alt, $pdf->larg];
        }
        $pdf->sx = $pdf->dx = 40;
        $pdf->alto = 40;
        $pdf->y = $pdf->alto;
        $this->disegna($pdf);

        return $pdf->pdf();
    }

    /** Scrive titolo e tabella nel PDF a partire dalla posizione corrente (anche dentro un PDF con più tabelle). */
    public function disegna(Documento $pdf): void
    {
        $pdf->paragrafo($this->titolo, ['font' => 'H', 'b' => true, 'sz' => 15, 'al' => 'sinistra', 'dopo' => 2]);
        if ($this->sottotitolo !== '') {
            $pdf->paragrafo($this->sottotitolo, ['font' => 'H', 'sz' => 9, 'al' => 'sinistra', 'dopo' => 8]);
        }
        $righe = array_map(fn (array $r) => array_map([self::class, 'cella'], array_values($r)), $this->righe);
        if (!$righe) {
            $pdf->paragrafo('Nessun elemento.', ['font' => 'H', 'sz' => 10, 'al' => 'sinistra']);
        } else {
            $pdf->tabella(array_map([self::class, 'cella'], $this->intestazioni), $righe, ['sz' => 8, 'larghezze' => $this->larghezze ?: array_fill(0, count($this->intestazioni), 1)]);
        }
    }

    public function titolo(): string
    {
        return $this->titolo;
    }

    public function numeroColonne(): int
    {
        return count($this->intestazioni);
    }

    /** @return array{intestazioni: list<string>, righe: list<list<string>>, larghezze: list<int|float>} il foglio per Excel::crea() */
    public function foglio(): array
    {
        return [
            'intestazioni' => array_map([self::class, 'cella'], $this->intestazioni),
            'righe' => array_map(fn (array $r) => array_map([self::class, 'cella'], array_values($r)), $this->righe),
            'larghezze' => $this->larghezzeExcel(),
        ];
    }

    /** Righe del CSV (intestazione compresa), già ripulite. @return list<list<string>> */
    public function righeCsv(): array
    {
        return array_merge([array_map([self::class, 'cella'], $this->intestazioni)], array_map(fn (array $r) => array_map([self::class, 'cella'], array_values($r)), $this->righe));
    }

    /** Manda il file al browser; con un formato sconosciuto non fa nulla (la pagina si mostra come al solito). */
    public function scarica(string $formato, string $nomeBase): void
    {
        $nome = preg_replace('/[^\w\-]+/u', '_', $nomeBase) ?: 'elenco';
        if ($formato === 'csv') {
            $tmp = tempnam(sys_get_temp_dir(), 'csv');
            file_put_contents($tmp, $this->csv());
            Download::invia($tmp, "$nome.csv", 'text/csv; charset=utf-8');
        }
        if ($formato === 'xlsx' && ($tmp = $this->xlsx()) !== null) {
            Download::invia($tmp, "$nome.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        if ($formato === 'pdf') {
            $tmp = tempnam(sys_get_temp_dir(), 'pdf');
            file_put_contents($tmp, $this->pdf());
            Download::invia($tmp, "$nome.pdf", 'application/pdf');
        }
    }

    /**
     * Il testo di una cella, uguale per CSV, Excel e PDF. Un testo che comincia con = + - @ (anche dopo spazi, tabulazioni o a capo, che qui si tolgono) è una
     * formula per Excel e LibreOffice: chi scrive «=HYPERLINK(...)» in un modulo la farebbe eseguire a chi apre il file. Si prepone un apice, come consiglia l'OWASP:
     * nel foglio il testo resta leggibile e non si calcola. I numeri veri (int e float, anche negativi) restano numeri.
     * È l'UNICA regola del portale per le celle di un file CSV, di una tabella HTML che Excel apre e di un PDF: chi esporta altrove (EsportaIscritti,
     * EsportaRisultati, il modello dell'elenco studenti) passa da qui. I fogli .xlsx di Excel::crea() scrivono ogni cella come testo («inlineStr», mai «<f>»): non
     * eseguono formule.
     */
    public static function cella(mixed $v): string
    {
        $t = preg_replace('/\s*\R\s*/u', ' · ', trim((string) $v)) ?? '';
        if (is_int($v) || is_float($v) || $t === '') {
            return $t;
        }

        return in_array($t[0], ['=', '+', '-', '@'], true) ? "'" . $t : $t;
    }

    /** @return list<float|int> */
    private function larghezzeExcel(): array
    {
        $l = [];
        foreach ($this->intestazioni as $c => $t) {
            $max = mb_strlen((string) $t);
            foreach (array_slice($this->righe, 0, 200) as $r) {
                $max = max($max, mb_strlen(self::cella(array_values($r)[$c] ?? '')));
            }
            $l[$c] = min(60, max(10, $max + 3));
        }

        return $l;
    }
}
