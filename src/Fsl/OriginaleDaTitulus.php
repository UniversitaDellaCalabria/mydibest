<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\File;
use App\Core\Sito;
use App\Infrastructure\Storage\Upload;
use App\Protocollo\ServizioProtocollo;
use Throwable;

/**
 * Dopo il numero di protocollo, il portale riprende da Titulus il file protocollato (sola lettura) e lo conserva come ORIGINALE, in una cartella
 * chiusa al web e in una colonna sua. Titulus lo restituisce senza stampigliatura: non sostituisce mai né il PDF firmato dal Direttore né il
 * documento che vedono la scuola e il registro (quello caricato a mano dall'ufficio o, in mancanza, la copia stampigliata dal portale).
 * Se il file non arriva o l'impronta non coincide con quella dichiarata da Titulus, si tiene quello del portale e l'ufficio vede un avviso;
 * il cron riprova una volta al giorno per 7 giorni, e «Riprova da Titulus» resta disponibile anche a iter concluso.
 * Non lancia mai eccezioni e non scrive mai in Titulus.
 */
final class OriginaleDaTitulus
{
    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private ServizioConvenzioneFirmata $iter,
        private ServizioProtocollo $protocollo,
        private Upload $upload,
        private Sito $sito,
    ) {
    }

    /**
     * @return array{ok: bool, stato: string, messaggio: string} stato: ok | mancante | impronta
     */
    public function scarica(int $id): array
    {
        try {
            $cc = $this->compilate->perId($id);
            if (!$cc || (string) $cc['stato_iter'] !== Costanti::ITER_PROTOCOLLATA) {
                return ['ok' => false, 'stato' => 'mancante', 'messaggio' => 'Il file protocollato si riprende da Titulus dopo la registrazione della convenzione.'];
            }
            $r = $this->leggi($cc);
            if (!$r['ok']) {
                return $this->fallito($id, $r['messaggio']);
            }
            $cartella = $this->sito->radice() . '/' . Costanti::DIR_CONVENZIONI;
            $this->upload->cartellaProtetta($cartella, 'Convenzioni firmate: si scaricano solo dal pannello (admin/convenzione_file.php)');
            $nome = bin2hex(random_bytes(16)) . '.pdf';
            if (@file_put_contents($cartella . $nome, $r['contenuto']) !== strlen($r['contenuto'])) {
                File::togli($cartella . $nome);

                return $this->fallito($id, 'Non riesco a salvare il file ripreso da Titulus: riprova tra poco.');
            }
            $this->compilate->salvaOriginale($id, Costanti::DIR_CONVENZIONI . $nome, 'ok', '');
            $vecchio = (string) ($cc['file_originale'] ?? '');
            if ($vecchio !== '' && $vecchio !== Costanti::DIR_CONVENZIONI . $nome) {
                File::togli($this->sito->radice() . '/' . $vecchio);
            }

            return ['ok' => true, 'stato' => 'ok', 'messaggio' => 'File ripreso da Titulus.'];
        } catch (Throwable $e) {
            return $this->fallito($id, $e->getMessage());
        }
    }

    /** Il file in Titulus: dall'invio fatto dal portale (anche con il riferimento di prima dell'impronta) o, per un numero scritto a mano, dal numero. */
    private function leggi(array $cc): array
    {
        $id = (int) $cc['id'];
        $r = ['ok' => false, 'messaggio' => '', 'contenuto' => '', 'titolo' => '', 'id' => ''];
        foreach (array_unique([$this->iter->rifDefinitivo($cc), ProtocollaDefinitivaFslInTitulus::RIF]) as $rif) {
            $r = $this->protocollo->scaricaFile($rif, $id);
            if ($r['ok'] || !str_starts_with($r['messaggio'], 'Titulus non ha ancora ricevuto')) {
                return $r;
            }
        }
        // nessun invio del portale: protocollo scritto a mano, si prova dal numero
        $numero = trim((string) strtok((string) ($cc['protocollo'] ?? ''), ' '));

        return $numero !== '' ? $this->protocollo->scaricaFilePerNumero($numero) : $r;
    }

    /** @return array{ok: bool, stato: string, messaggio: string} */
    private function fallito(int $id, string $motivo): array
    {
        $stato = stripos($motivo, 'impronta') !== false ? 'impronta' : 'mancante';
        $this->compilate->salvaOriginale($id, null, $stato, $motivo);

        return ['ok' => false, 'stato' => $stato, 'messaggio' => $motivo];
    }

    /** Il cron: riprova gli originali mancanti (al massimo uno al giorno, per 7 giorni dopo il primo tentativo). Ritorna quanti sono arrivati. */
    public function riprovaTutti(): int
    {
        $n = 0;
        foreach ($this->compilate->originaleDaRiprovare() as $cc) {
            if ($this->scarica((int) $cc['id'])['ok']) {
                ++$n;
            }
        }

        return $n;
    }
}
