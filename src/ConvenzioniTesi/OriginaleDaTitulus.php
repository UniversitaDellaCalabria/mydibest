<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use App\Core\File;
use App\Core\Sito;
use App\Infrastructure\Storage\Upload;
use App\Protocollo\ServizioProtocollo;
use Throwable;

/**
 * Dopo il numero di protocollo, il portale riprende da Titulus il file protocollato (sola lettura) e lo conserva come ORIGINALE, in una cartella chiusa al web e in
 * una colonna sua (file_originale). Titulus lo restituisce senza stampigliatura: non sostituisce mai né il PDF firmato dal Direttore né il documento che vedono
 * l'azienda e l'archivio (file_protocollato: quello caricato a mano dall'ufficio o, in mancanza, la copia stampigliata dal portale). Se il file non arriva o
 * l'impronta non coincide con quella dichiarata da Titulus, si tiene quello del portale e l'ufficio vede un avviso; il cron riprova una volta al giorno per 7 giorni
 * e «Riprova da Titulus» resta disponibile anche a convenzione stipulata. Non lancia mai eccezioni e non scrive mai in Titulus.
 * Stessa regola dell'originale delle convenzioni FSL (App\Fsl\OriginaleDaTitulus): cambiano le colonne e i riferimenti di protocollo.
 */
final class OriginaleDaTitulus
{
    public function __construct(
        private ConvenzioneTesiRepository $conv,
        private ServizioConvenzioniTesi $servizio,
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
            $c = $this->conv->perId($id);
            if (!$c || (string) $c['stato'] !== Costanti::PROTOCOLLATA) {
                return ['ok' => false, 'stato' => 'mancante', 'messaggio' => 'Il file protocollato si riprende da Titulus dopo la registrazione della convenzione.'];
            }
            $r = $this->leggi($c);
            if (!$r['ok']) {
                return $this->fallito($id, $r['messaggio']);
            }
            $cartella = $this->sito->radice() . '/' . Costanti::DIR_DEFINITIVE;
            $this->upload->cartellaProtetta($cartella, 'Convenzioni di tesi firmate dal Direttore e protocollate: si scaricano solo dal pannello');
            $nome = bin2hex(random_bytes(16)) . '.pdf';
            if (@file_put_contents($cartella . $nome, $r['contenuto']) !== strlen($r['contenuto'])) {
                File::togli($cartella . $nome);

                return $this->fallito($id, 'Non riesco a salvare il file ripreso da Titulus: riprova tra poco.');
            }
            $this->conv->salvaOriginale($id, Costanti::DIR_DEFINITIVE . $nome, 'ok', '');
            $vecchio = (string) ($c['file_originale'] ?? '');
            if ($vecchio !== '' && $vecchio !== Costanti::DIR_DEFINITIVE . $nome) {
                File::togli($this->sito->radice() . '/' . $vecchio);
            }

            return ['ok' => true, 'stato' => 'ok', 'messaggio' => 'File ripreso da Titulus.'];
        } catch (Throwable $e) {
            return $this->fallito($id, $e->getMessage());
        }
    }

    /**
     * Il file in Titulus: dall'invio fatto dal portale (anche con il riferimento di prima dell'impronta) o, per un numero scritto a mano, dal numero.
     *
     * @param array<string, string|null> $c
     * @return array{ok: bool, messaggio: string, contenuto?: string}
     */
    private function leggi(array $c): array
    {
        $id = (int) $c['id'];
        $r = ['ok' => false, 'messaggio' => '', 'contenuto' => '', 'titolo' => '', 'id' => ''];
        foreach (array_unique([$this->servizio->rifDefinitivo($c), ProtocollaDefinitivaTesiInTitulus::RIF]) as $rif) {
            $r = $this->protocollo->scaricaFile($rif, $id);
            if ($r['ok'] || !str_starts_with($r['messaggio'], 'Titulus non ha ancora ricevuto')) {
                return $r;
            }
        }
        // nessun invio del portale: protocollo scritto a mano, si prova dal numero
        $numero = trim((string) strtok((string) ($c['protocollo'] ?? ''), ' '));

        return $numero !== '' ? $this->protocollo->scaricaFilePerNumero($numero) : $r;
    }

    /** @return array{ok: bool, stato: string, messaggio: string} */
    private function fallito(int $id, string $motivo): array
    {
        $stato = stripos($motivo, 'impronta') !== false ? 'impronta' : 'mancante';
        $this->conv->salvaOriginale($id, null, $stato, $motivo);

        return ['ok' => false, 'stato' => $stato, 'messaggio' => $motivo];
    }

    /** Il cron: riprova gli originali mancanti (al massimo uno al giorno, per 7 giorni dopo il primo tentativo). Ritorna quanti sono arrivati. */
    public function riprovaTutti(): int
    {
        $n = 0;
        foreach ($this->conv->originaleDaRiprovare() as $c) {
            if ($this->scarica((int) $c['id'])['ok']) {
                ++$n;
            }
        }

        return $n;
    }
}
