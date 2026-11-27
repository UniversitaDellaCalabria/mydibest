<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Core\Sito;
use App\Infrastructure\Storage\Upload;

/**
 * Allegati delle richieste di pubblicazione, con lo stesso sistema delle pratiche (AllegatiPratiche): fino a 10 MB, tipo controllato sul contenuto
 * del file (finfo) oltre che sull'estensione, nome casuale, cartella bloccata al web (si scaricano solo dalle pagine del portale).
 * «locandina» = il file principale della richiesta (uno solo, PDF, JPG o PNG: da v146 è obbligatorio); «allegato» = altri file PDF, JPG o PNG (brochure…);
 * «immagine» = solo JPG o PNG (anteprima della notizia).
 *
 * $soloFileCaricati = false serve solo alle prove automatiche: accetta file che non arrivano da un modulo web (li copia invece di spostarli).
 */
final class AllegatiPubblicazioni
{
    public const MAX_BYTE = 10 * 1024 * 1024;

    /** tipo => [estensioni ammesse, tipi MIME ammessi (letti dal contenuto)] */
    public const TIPI = [
        'locandina' => [['pdf', 'jpg', 'jpeg', 'png'], ['application/pdf', 'image/jpeg', 'image/png']],
        'allegato' => [['pdf', 'jpg', 'jpeg', 'png'], ['application/pdf', 'image/jpeg', 'image/png']],
        'immagine' => [['jpg', 'jpeg', 'png'], ['image/jpeg', 'image/png']],
    ];

    /** Il tipo che il contenuto deve avere per ogni estensione. */
    private const MIME_PER_ESTENSIONE = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    public function __construct(
        private Upload $upload,
        private Sito $sito,
        private string $cartella = Costanti::CARTELLA,
        private bool $soloFileCaricati = true,
    ) {
    }

    /** Cartella (relativa alla radice del sito, con «/» finale) dove stanno gli allegati. */
    public function cartella(): string
    {
        return $this->cartella;
    }

    /**
     * Perché il file non va bene (null se va bene): caricamento non riuscito, troppo grande, estensione o contenuto non ammessi.
     *
     * Il «massimo N MB» del messaggio è il limite vero (LimitiCaricamento::fileMax: il più basso tra i 10 MB del portale e upload_max_filesize del server),
     * non un numero scritto a mano; chi prova può passare i limiti che vuole.
     *
     * @param array<string, mixed> $file elemento di $_FILES (un file solo)
     */
    public static function errore(array $file, string $tipo, ?LimitiCaricamento $limiti = null): ?string
    {
        [$estensioni, $mime] = self::TIPI[$tipo] ?? self::TIPI['immagine'];
        $nome = (string) ($file['name'] ?? '');
        $ammessi = $tipo === 'immagine' ? 'JPG o PNG' : 'PDF, JPG o PNG';
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $troppoGrande = static fn (): string => "«{$nome}»: il file è troppo grande (massimo " . ($limiti ?? LimitiCaricamento::dalServer())->fileMaxInParole() . ').';
        if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) ($file['size'] ?? 0) > self::MAX_BYTE) {
            return $troppoGrande();
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            return "«{$nome}»: il file non è arrivato, riprova.";
        }
        if (filesize($tmp) > self::MAX_BYTE) {
            return $troppoGrande();
        }
        // il contenuto deve essere davvero del tipo che dice l'estensione (un PNG rinominato .pdf non passa)
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if (!in_array($ext, $estensioni, true) || self::tipoContenuto($tmp) !== (self::MIME_PER_ESTENSIONE[$ext] ?? '') || !in_array(self::MIME_PER_ESTENSIONE[$ext], $mime, true)) {
            return "«{$nome}»: sono ammessi solo file {$ammessi}.";
        }

        return null;
    }

    /**
     * I file di un campo di caricamento multiplo ($_FILES['allegati'] con name="allegati[]") come elenco di file singoli, senza le caselle lasciate vuote.
     * Accetta anche un elenco già pronto o un file singolo.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $campo
     * @return list<array<string, mixed>>
     */
    public static function elenco(array $campo): array
    {
        if ($campo === []) {
            return [];
        }
        if (array_is_list($campo)) {
            $voci = $campo;
        } elseif (is_array($campo['name'] ?? null)) {
            $voci = [];
            foreach (array_keys($campo['name']) as $i) {
                $voci[] = [
                    'name' => $campo['name'][$i] ?? '', 'type' => $campo['type'][$i] ?? '', 'tmp_name' => $campo['tmp_name'][$i] ?? '',
                    'error' => $campo['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $campo['size'][$i] ?? 0,
                ];
            }
        } else {
            $voci = [$campo];
        }

        return array_values(array_filter($voci, static fn ($f): bool => is_array($f) && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    }

    /**
     * Salva il file (già controllato con errore()) nella cartella protetta: percorso relativo alla radice del sito, null se non riesce.
     *
     * @param array<string, mixed> $file
     */
    public function salva(array $file, string $tipo): ?string
    {
        if (self::errore($file, $tipo) !== null) {
            return null;
        }
        [$estensioni, $mime] = self::TIPI[$tipo];
        $dir = $this->sito->radice() . '/' . $this->cartella;
        $this->upload->cartellaProtetta($dir, 'Allegati delle richieste di pubblicazione: si scaricano solo dalle pagine del portale');
        if ($this->soloFileCaricati) {
            $nome = $this->upload->salva($file, $dir, $estensioni, $mime);

            return $nome ? $this->cartella . $nome : null;
        }
        $nome = bin2hex(random_bytes(16)) . '.' . strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        return @copy((string) $file['tmp_name'], $dir . $nome) ? $this->cartella . $nome : null;
    }

    /** Il percorso assoluto del file solo se sta davvero nella cartella degli allegati (null altrimenti o se non c'è). */
    public function percorsoAssoluto(string $relativo): ?string
    {
        if ($relativo === '' || !str_starts_with($relativo, $this->cartella) || str_contains($relativo, '..')) {
            return null;
        }
        $base = realpath($this->sito->radice() . '/' . $this->cartella);
        $vero = realpath($this->sito->radice() . '/' . $relativo);
        if ($base === false || $vero === false || !is_file($vero) || dirname($vero) !== $base) {
            return null;
        }

        return $vero;
    }

    /** Cancella il file dal disco (solo se sta nella cartella degli allegati). */
    public function elimina(string $relativo): void
    {
        $vero = $this->percorsoAssoluto($relativo);
        if ($vero !== null) {
            @unlink($vero);
        }
    }

    private static function tipoContenuto(string $percorso): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }
        $tipo = finfo_file($finfo, $percorso);
        finfo_close($finfo);

        return is_string($tipo) ? $tipo : '';
    }
}
