<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Sito;
use App\Email\InvioEmail;
use App\Infrastructure\Storage\Upload;

/**
 * Allegato A aggiornato da una scuola che ha già una convenzione valida: la scuola compila e carica il PDF, che entra nel registro come
 * integrazione (tabella convenzioni_allegati). Non blocca le prenotazioni (D1) e non sostituisce mai l'Allegato A registrato: l'ufficio lo accetta
 * o lo rifiuta con un motivo. Il file sta in una cartella bloccata al web e si cancella con la convenzione del registro.
 */
final class ServizioAllegatiConvenzione
{
    /** Dentro la cartella (bloccata al web) del registro, così lo stesso controllo di percorso vale per tutto. */
    public const CARTELLA = 'uploads/convenzioni/allegati/';

    public function __construct(
        private AllegatoConvenzioneRepository $allegati,
        private Upload $upload,
        private Sito $sito,
        private InvioEmail $invio
    ) {
    }

    /**
     * La scuola carica l'Allegato A aggiornato (PDF fino a 10 MB, anche senza firma) per la convenzione valida del registro.
     * Ritorna il messaggio di errore, null se è andato a buon fine.
     *
     * @param array<string, mixed> $cc compilazione di chi carica (ne vengono l'email e le attività elencate)
     * @param array<string, mixed>|null $file elemento di $_FILES
     */
    public function carica(array $cc, int $convenzioneId, ?array $file): ?string
    {
        $errore = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($file === null || $errore === UPLOAD_ERR_NO_FILE) {
            return "Scegli il file dell'Allegato A aggiornato.";
        }
        if ($errore === UPLOAD_ERR_INI_SIZE || $errore === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > Costanti::MAX_FIRMATA) {
            return 'Il file supera ' . (Costanti::MAX_FIRMATA / 1048576) . ' MB.';
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($errore !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            return 'Il caricamento non è riuscito: riprova.';
        }
        $contenuto = file_get_contents($tmp);

        return $this->caricaContenuto($cc, $convenzioneId, $contenuto === false ? '' : $contenuto);
    }

    /**
     * Come carica(), dal contenuto già letto (così si prova senza un vero caricamento).
     *
     * @param array<string, mixed> $cc
     */
    public function caricaContenuto(array $cc, int $convenzioneId, string $pdf): ?string
    {
        if ($convenzioneId <= 0) {
            return 'Non trovo la convenzione registrata di questa scuola.';
        }
        $errore = ServizioConvenzioneFirmata::erroreAllegato($pdf);
        if ($errore !== null || $pdf === '') {
            return $errore ?? "Scegli il file dell'Allegato A aggiornato.";
        }
        $cartella = $this->sito->radice() . '/' . self::CARTELLA;
        $this->upload->cartellaProtetta($cartella, 'Allegati A aggiornati dalle scuole: si scaricano solo dal pannello (admin/convenzione_file.php)');
        $nome = bin2hex(random_bytes(16)) . '.pdf';
        if (@file_put_contents($cartella . $nome, $pdf) !== strlen($pdf)) {
            @unlink($cartella . $nome);

            return 'Non sono riuscito a salvare il file: riprova.';
        }
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $attivita = json_encode(array_values((array) ($dati['attivita'] ?? [])), JSON_UNESCAPED_UNICODE);
        $this->allegati->aggiungi($convenzioneId, self::CARTELLA . $nome, (string) $attivita, (string) ($cc['email'] ?? ''));

        return null;
    }

    /**
     * L'ufficio accetta o rifiuta un allegato (il rifiuto vuole un motivo) e avvisa chi l'ha caricato.
     * Ritorna l'errore da mostrare, null se è andato a buon fine.
     */
    public function esamina(int $id, bool $accetta, string $nota, string $protocollo, string $autore, string $scuola, string $linkPagina): ?string
    {
        $a = $this->allegati->perId($id);
        if (!$a || $a['stato'] !== AllegatoConvenzioneRepository::DA_VEDERE) {
            return 'Questo allegato non è più da esaminare.';
        }
        $nota = trim($nota);
        if (!$accetta && $nota === '') {
            return 'Per rifiutare scrivi il motivo: lo leggerà la scuola.';
        }
        if (!$this->allegati->esamina($id, $accetta ? AllegatoConvenzioneRepository::ACCETTATO : AllegatoConvenzioneRepository::RIFIUTATO, $nota, trim($protocollo), $autore)) {
            return 'Questo allegato è già stato esaminato.';
        }
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $testo = $accetta
            ? "Il Dipartimento ha <strong>accettato l'Allegato A aggiornato</strong> e lo ha aggiunto alla convenzione." . ($nota !== '' ? '<br>' . $h($nota) : '')
            : "Il Dipartimento <strong>non ha potuto accettare l'Allegato A aggiornato</strong>.<br>Motivo: " . $h($nota) . '<br>Correggilo e caricalo di nuovo dalla pagina della convenzione.';
        $email = (string) ($a['caricato_da'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->invio->invia($email, 'fsl_allegato_esito', ['scuola' => $scuola, 'testo' => $testo], $linkPagina);
        }

        return null;
    }

    /**
     * Il file di un allegato da scaricare dal pannello: percorso reale (solo dentro la cartella degli allegati) e nome del download.
     *
     * @return array{percorso: string, nome: string}|null
     */
    public function fileDaScaricare(int $id): ?array
    {
        $a = $this->allegati->perId($id);
        $base = realpath($this->sito->radice() . '/' . rtrim(self::CARTELLA, '/'));
        $percorso = $a && !empty($a['file']) ? realpath($this->sito->radice() . '/' . $a['file']) : false;
        if (!$percorso || !$base || strpos($percorso, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($percorso)) {
            return null;
        }

        return ['percorso' => $percorso, 'nome' => 'Allegato_A_aggiornato_' . (int) $a['convenzione_id'] . '_' . (int) $a['id'] . '.pdf'];
    }
}
