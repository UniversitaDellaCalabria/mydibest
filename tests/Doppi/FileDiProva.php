<?php

declare(strict_types=1);

namespace Tests\Doppi;

/**
 * File veri (PDF, PNG, JPG, testo) in file temporanei, nella forma di un elemento di $_FILES: per provare i controlli sul contenuto dei caricamenti.
 * pulisci() cancella quelli creati.
 */
final class FileDiProva
{
    /** @var list<string> */
    private static array $creati = [];

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    public static function pdf(string $nome = 'locandina.pdf'): array
    {
        return self::crea($nome, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    public static function png(string $nome = 'foto.png'): array
    {
        return self::crea($nome, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    public static function jpg(string $nome = 'foto.jpg'): array
    {
        return self::crea($nome, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . str_repeat("\x00", 64) . "\xFF\xD9");
    }

    /** Un file di testo con il nome indicato (es. «finto.pdf»). @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    public static function testo(string $nome): array
    {
        return self::crea($nome, "Questo non è un PDF né un'immagine.\n");
    }

    public static function pulisci(): void
    {
        foreach (self::$creati as $f) {
            @unlink($f);
        }
        self::$creati = [];
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    private static function crea(string $nome, string $contenuto): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pub') ?: '';
        file_put_contents($tmp, $contenuto);
        self::$creati[] = $tmp;

        return ['name' => $nome, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contenuto)];
    }
}
