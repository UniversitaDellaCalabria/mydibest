<?php

declare(strict_types=1);

namespace App\Protocollo;

use App\Core\Database;
use App\Core\File;
use App\Core\Sito;
use App\Infrastructure\Storage\Upload;
use Throwable;

/**
 * I file di un documento protocollato (domanda di una pratica, attestato, lettera di tutorato) in due voci sole, per chi lavora e per chi riceve:
 *
 *  - ORIGINALE: il PDF come è partito (domanda in PDF/A, attestato o lettera firmati). Non si stampiglia mai sul posto. Se il portale non l'ha più,
 *    vale il file ripreso da Titulus.
 *  - DOCUMENTO PROTOCOLLATO: quello caricato a mano dall'ufficio; in mancanza la copia con la segnatura fatta dal portale («copia per consultazione»);
 *    in mancanza il file ripreso da Titulus (che lo restituisce senza stampigliatura).
 *
 * Il file di Titulus si riprende con il cron dopo il protocollo (una riga di protocollo_file per documento, creata da programma(): al massimo 8 tentativi,
 * uno al giorno, e 20 file per giro), si conserva in una cartella chiusa al web con la sua impronta SHA-256 e la data, e si può riprovare a mano. La pagina
 * di chi legge non chiama mai Titulus. Le pratiche già protocollate prima di questa versione non hanno una riga: solo «Riprova» la crea.
 * Non scrive mai in Titulus e non lancia eccezioni dalle operazioni di ripresa.
 */
final class FileProtocollato
{
    public const DIR = 'uploads/protocollo_file/';
    public const RIF_DOMANDA = 'pratica_domanda';
    public const RIF_ATTESTATO = 'pratica_uscita';
    public const RIF_LETTERA = 'tutorato_lettera';
    public const TIPI = [self::RIF_DOMANDA, self::RIF_ATTESTATO, self::RIF_LETTERA];
    public const MASSIMO_TENTATIVI = 8;
    public const PER_GIRO = 20;

    public function __construct(
        private Database $db,
        private ServizioProtocollo $protocollo,
        private Upload $upload,
        private Sito $sito,
    ) {
    }

    /**
     * Dopo il numero di protocollo: segna che il file di Titulus va ripreso dal cron. Se la riga c'è già non la tocca (salvo l'origine, se prima non era nota).
     * Senza collegamento a Titulus non fa niente.
     *
     * @param string $origine mano | copia | '' (da dove viene il «documento protocollato» del portale)
     */
    public function programma(string $rifTipo, int $rifId, string $numero = '', string $origine = ''): void
    {
        try {
            if (!in_array($rifTipo, self::TIPI, true) || $rifId <= 0 || $this->protocollo->nonPronto() !== null) {
                return;
            }
            $this->db->esegui(
                "INSERT INTO protocollo_file (rif_tipo, rif_id, numero, origine) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE numero = IF(? <> '', ?, numero), origine = IF(? <> '', ?, origine)",
                [$rifTipo, $rifId, mb_substr($numero, 0, 100), $origine, $numero, mb_substr($numero, 0, 100), $origine, $origine]
            );
        } catch (Throwable $e) {
            error_log('[FileProtocollato] programma: ' . $e->getMessage());
        }
    }

    /** @return array<string, mixed>|null */
    public function riga(string $rifTipo, int $rifId): ?array
    {
        return $this->db->riga('SELECT * FROM protocollo_file WHERE rif_tipo = ? AND rif_id = ?', [$rifTipo, $rifId]);
    }

    /**
     * Riprende da Titulus il file protocollato e lo conserva. Un tentativo si conta sempre.
     *
     * @return array{ok: bool, stato: string, messaggio: string} stato: ok | mancante | impronta
     */
    public function scarica(string $rifTipo, int $rifId): array
    {
        try {
            if (!in_array($rifTipo, self::TIPI, true) || $rifId <= 0) {
                return ['ok' => false, 'stato' => 'mancante', 'messaggio' => 'Documento non riconosciuto.'];
            }
            $riga = $this->riga($rifTipo, $rifId);
            if ($riga === null) {
                // «Riprova» su un documento protocollato prima di questa versione: la riga si crea ora, con il numero scritto nel documento
                $this->db->esegui('INSERT IGNORE INTO protocollo_file (rif_tipo, rif_id, numero) VALUES (?, ?, ?)', [$rifTipo, $rifId, mb_substr($this->numeroDelDocumento($rifTipo, $rifId), 0, 100)]);
                $riga = $this->riga($rifTipo, $rifId);
            }
            if ($riga === null) {
                return ['ok' => false, 'stato' => 'mancante', 'messaggio' => 'Non riesco a preparare il recupero del file.'];
            }
            $r = $this->protocollo->scaricaFile($rifTipo, $rifId);
            if (!$r['ok'] && str_starts_with((string) $r['messaggio'], 'Titulus non ha ancora ricevuto')) {
                // nessun invio del portale (protocollo scritto a mano): si prova dal numero
                $numero = trim((string) strtok((string) ($riga['numero'] ?: $this->numeroDelDocumento($rifTipo, $rifId)), ' '));
                if ($numero !== '') {
                    $r = $this->protocollo->scaricaFilePerNumero($numero);
                }
            }
            if (!$r['ok']) {
                return $this->fallito($rifTipo, $rifId, (string) $r['messaggio']);
            }
            $contenuto = (string) $r['contenuto'];
            $cartella = $this->sito->radice() . '/' . self::DIR;
            $this->upload->cartellaProtetta($cartella, 'File protocollati ripresi da Titulus: si scaricano solo dalle pagine del portale con i loro controlli di accesso');
            $nome = bin2hex(random_bytes(16)) . '.pdf';
            if ($contenuto === '' || @file_put_contents($cartella . $nome, $contenuto) !== strlen($contenuto)) {
                File::togli($cartella . $nome);

                return $this->fallito($rifTipo, $rifId, 'Non riesco a salvare il file ripreso da Titulus: riprova tra poco.');
            }
            $this->db->esegui(
                "UPDATE protocollo_file SET file = ?, impronta = ?, scaricato_il = NOW(), stato = 'ok', errore = '', tentativi = LEAST(tentativi + 1, 100), ultimo_tentativo_il = NOW() WHERE rif_tipo = ? AND rif_id = ?",
                [self::DIR . $nome, hash('sha256', $contenuto), $rifTipo, $rifId]
            );
            $vecchio = (string) ($riga['file'] ?? '');
            if ($vecchio !== '' && $vecchio !== self::DIR . $nome) {
                $this->togliFile($vecchio);
            }

            return ['ok' => true, 'stato' => 'ok', 'messaggio' => 'File ripreso da Titulus.'];
        } catch (Throwable $e) {
            return $this->fallito($rifTipo, $rifId, $e->getMessage());
        }
    }

    /** Il cron: un giro di riprese (al massimo PER_GIRO file; un errore su uno non ferma gli altri). Ritorna quanti file sono arrivati. */
    public function riprovaTutti(): int
    {
        try {
            if ($this->protocollo->nonPronto() !== null) {
                return 0;   // collegamento spento: non si consumano i tentativi
            }
            $righe = $this->db->righe(
                "SELECT rif_tipo, rif_id FROM protocollo_file WHERE stato IN ('attesa', 'mancante', 'impronta') AND tentativi < " . self::MASSIMO_TENTATIVI . "
                 AND (ultimo_tentativo_il IS NULL OR ultimo_tentativo_il <= NOW() - INTERVAL 20 HOUR) ORDER BY id LIMIT " . self::PER_GIRO
            );
        } catch (Throwable $e) {
            error_log('[FileProtocollato] giro: ' . $e->getMessage());

            return 0;
        }
        $n = 0;
        foreach ($righe as $r) {
            try {
                if ($this->scarica((string) $r['rif_tipo'], (int) $r['rif_id'])['ok']) {
                    ++$n;
                }
            } catch (Throwable $e) {
                error_log('[FileProtocollato] ' . $r['rif_tipo'] . ' ' . $r['rif_id'] . ': ' . $e->getMessage());
            }
        }

        return $n;
    }

    /** Toglie la riga e il file ripreso da Titulus (pratica o incarico eliminati, o dati scaduti per conservazione). */
    public function elimina(string $rifTipo, int $rifId): void
    {
        try {
            $riga = $this->riga($rifTipo, $rifId);
            if ($riga === null) {
                return;
            }
            $this->togliFile((string) ($riga['file'] ?? ''));
            $this->db->esegui('DELETE FROM protocollo_file WHERE rif_tipo = ? AND rif_id = ?', [$rifTipo, $rifId]);
        } catch (Throwable $e) {
            error_log('[FileProtocollato] elimina: ' . $e->getMessage());
        }
    }

    /**
     * Le due voci del documento (e, per l'ufficio, lo stato del file di Titulus).
     *
     * @return array{
     *   originale: array{esiste: bool, da: string, nome: string},
     *   protocollato: array{esiste: bool, origine: string, nome: string},
     *   titulus: array{stato: string, tentativi: int, errore: string, scaricato_il: string, identico: ?bool, impronta: string}|null
     * }
     */
    public function voci(string $rifTipo, int $rifId): array
    {
        $f = $this->fonti($rifTipo, $rifId);
        $riga = $this->riga($rifTipo, $rifId);
        $titulus = $riga !== null ? $this->percorsoTitulus($riga) : null;
        $orig = $f['originale'];
        $prot = $f['protocollato'];
        $origine = $prot !== null ? (string) $f['origine'] : '';
        if ($prot !== null && $orig !== null && hash_file('sha256', $prot) === hash_file('sha256', $orig)) {
            $origine = 'originale';   // la copia con la segnatura non si è potuta fare: il documento protocollato è l'originale stesso
        }
        $vo = $orig !== null ? ['esiste' => true, 'da' => 'portale'] : ($titulus !== null ? ['esiste' => true, 'da' => 'titulus'] : ['esiste' => false, 'da' => '']);
        // ordine: caricato dall'ufficio o copia del portale → file di Titulus → (documento già protocollato senza altro) l'originale stesso
        $vp = $prot !== null ? ['esiste' => true, 'origine' => $origine]
            : ($titulus !== null ? ['esiste' => true, 'origine' => 'titulus']
            : ($f['daOriginale'] && $orig !== null ? ['esiste' => true, 'origine' => 'originale'] : ['esiste' => false, 'origine' => '']));

        return [
            'originale' => $vo + ['nome' => $f['nomeOriginale']],
            'protocollato' => $vp + ['nome' => $f['nomeProtocollato']],
            'titulus' => $riga === null ? null : [
                'stato' => (string) $riga['stato'], 'tentativi' => (int) $riga['tentativi'], 'errore' => (string) $riga['errore'],
                'scaricato_il' => (string) ($riga['scaricato_il'] ?? ''), 'impronta' => (string) $riga['impronta'],
                'identico' => $titulus !== null && $orig !== null ? hash_file('sha256', $titulus) === hash_file('sha256', $orig) : null,
            ],
        ];
    }

    /**
     * Il percorso di un file da inviare a chi ha già superato il controllo di accesso della pagina.
     *
     * @param string $voce originale | protocollato | titulus (quest'ultima solo per chi lavora: la pagina lo decide)
     * @return array{percorso: string, nome: string}|null
     */
    public function apri(string $rifTipo, int $rifId, string $voce): ?array
    {
        $f = $this->fonti($rifTipo, $rifId);
        $riga = $this->riga($rifTipo, $rifId);
        $titulus = $riga !== null ? $this->percorsoTitulus($riga) : null;
        $nomeTitulus = 'Protocollato_in_Titulus_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($riga['numero'] ?? '') ?: (string) $rifId) . '.pdf';

        return match ($voce) {
            'originale' => ($f['originale'] ?? $titulus) !== null ? ['percorso' => (string) ($f['originale'] ?? $titulus), 'nome' => $f['originale'] !== null ? $f['nomeOriginale'] : $nomeTitulus] : null,
            'protocollato' => $f['protocollato'] !== null ? ['percorso' => $f['protocollato'], 'nome' => $f['nomeProtocollato']]
                : ($titulus !== null ? ['percorso' => $titulus, 'nome' => $nomeTitulus]
                : ($f['daOriginale'] && $f['originale'] !== null ? ['percorso' => $f['originale'], 'nome' => $f['nomeOriginale']] : null)),
            'titulus' => $titulus !== null ? ['percorso' => $titulus, 'nome' => $nomeTitulus] : null,
            default => null,
        };
    }

    /** Il documento in Titulus (indirizzo per aprirlo) dal registro degli invii: solo per chi lavora; null se non c'è un invio riuscito del portale. */
    public function indirizzoTitulus(string $rifTipo, int $rifId): ?string
    {
        try {
            foreach ($this->db->righe("SELECT id FROM titulus_invii WHERE rif_tipo = ? AND rif_id = ? AND esito = 'ok' ORDER BY id DESC LIMIT 5", [$rifTipo, $rifId]) as $i) {
                $r = $this->protocollo->indirizzoDelloInvio((int) $i['id']);
                if ($r['ok']) {
                    return (string) $r['url'];
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    // ---- interni -------------------------------------------------------------------------------------------------------------------------------------------

    /** @return array{ok: bool, stato: string, messaggio: string} */
    private function fallito(string $rifTipo, int $rifId, string $motivo): array
    {
        $stato = stripos($motivo, 'impronta') !== false ? 'impronta' : 'mancante';
        try {
            $this->db->esegui(
                'UPDATE protocollo_file SET stato = ?, errore = ?, tentativi = LEAST(tentativi + 1, 100), ultimo_tentativo_il = NOW() WHERE rif_tipo = ? AND rif_id = ?',
                [$stato, mb_substr($motivo, 0, 500), $rifTipo, $rifId]
            );
        } catch (Throwable) {
        }

        return ['ok' => false, 'stato' => $stato, 'messaggio' => $motivo];
    }

    /**
     * Il numero scritto nel documento. Una pratica ha un solo campo «protocollo»: se l'attestato è già stato protocollato in uscita, quello è il numero
     * dell'attestato e non della domanda (della domanda, in quel caso, non si sa: ''), e senza attestato protocollato non è il numero dell'attestato.
     */
    private function numeroDelDocumento(string $rifTipo, int $rifId): string
    {
        if ($rifTipo === self::RIF_LETTERA) {
            return trim((string) $this->db->valore('SELECT protocollo FROM tutorato_incarichi WHERE id = ?', [$rifId]));
        }
        $uscito = (int) $this->db->valore("SELECT COUNT(*) FROM pratiche_eventi WHERE pratica_id = ? AND nome_allegato LIKE 'Firmato\_direttore\_%' AND allegato IS NOT NULL AND allegato <> ''", [$rifId]) > 0;
        if (($rifTipo === self::RIF_DOMANDA) === $uscito) {
            return '';
        }

        return trim((string) $this->db->valore('SELECT protocollo FROM pratiche WHERE id = ?', [$rifId]));
    }

    /** Il file ripreso da Titulus, solo se è davvero dentro la sua cartella. @param array<string, mixed> $riga */
    private function percorsoTitulus(array $riga): ?string
    {
        return (string) $riga['stato'] === 'ok' ? $this->dentro((string) ($riga['file'] ?? ''), self::DIR) : null;
    }

    /** Percorso reale di un file relativo, solo se sta dentro la cartella indicata (e c'è), altrimenti null. */
    private function dentro(string $relativo, string $cartella): ?string
    {
        if ($relativo === '') {
            return null;
        }
        $base = realpath($this->sito->radice() . '/' . rtrim($cartella, '/'));
        $p = realpath($this->sito->radice() . '/' . $relativo);
        if ($base === false || $p === false || strpos($p, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($p)) {
            return null;
        }

        return $p;
    }

    private function togliFile(string $relativo): void
    {
        $p = $this->dentro($relativo, self::DIR);
        if ($p !== null) {
            File::togli($p);
        }
    }

    /**
     * I file del portale: originale e documento protocollato (percorsi reali dentro la cartella del documento), con i nomi da dare al download e l'origine
     * registrata del documento protocollato.
     *
     * @return array{originale: ?string, protocollato: ?string, daOriginale: bool, origine: string, nomeOriginale: string, nomeProtocollato: string}
     */
    private function fonti(string $rifTipo, int $rifId): array
    {
        $vuoto = ['originale' => null, 'protocollato' => null, 'daOriginale' => false, 'origine' => '', 'nomeOriginale' => 'Originale.pdf', 'nomeProtocollato' => 'Documento_protocollato.pdf'];
        $riga = $this->riga($rifTipo, $rifId);
        $origine = (string) ($riga['origine'] ?? '');
        if ($rifTipo === self::RIF_LETTERA) {
            $i = $this->db->riga('SELECT codice, stato, file_pdf, file_segnato FROM tutorato_incarichi WHERE id = ?', [$rifId]);
            if (!$i) {
                return $vuoto;
            }
            $dir = defined('DIR_INCARICHI') ? (string) DIR_INCARICHI : \App\Tutorato\Costanti::DIR_INCARICHI;
            $c = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $i['codice']);

            return [
                'originale' => $this->dentro((string) ($i['file_pdf'] ?? ''), $dir), 'protocollato' => $this->dentro((string) ($i['file_segnato'] ?? ''), $dir), 'daOriginale' => (string) $i['stato'] === 'protocollata', 'origine' => 'copia',
                'nomeOriginale' => 'Lettera_di_incarico_firmata_' . $c . '.pdf', 'nomeProtocollato' => 'Lettera_di_incarico_protocollata_' . $c . '_copia_per_consultazione.pdf',
            ];
        }
        $p = $this->db->riga('SELECT codice, domanda_pdf, protocollo FROM pratiche WHERE id = ?', [$rifId]);
        if (!$p) {
            return $vuoto;
        }
        $dir = defined('DIR_PRATICHE') ? (string) DIR_PRATICHE : \App\Didattica\Costanti::DIR_PRATICHE;
        $c = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $p['codice']);
        $evento = fn (string $nome): string => (string) $this->db->valore("SELECT allegato FROM pratiche_eventi WHERE pratica_id = ? AND nome_allegato = ? AND allegato IS NOT NULL AND allegato <> '' ORDER BY id DESC LIMIT 1", [$rifId, $nome]);
        if ($rifTipo === self::RIF_DOMANDA) {
            return [
                'originale' => $this->dentro((string) ($p['domanda_pdf'] ?? ''), $dir), 'protocollato' => $this->dentro($evento('Domanda_' . $p['codice'] . '_protocollata.pdf'), $dir), 'daOriginale' => (string) $p['protocollo'] !== '', 'origine' => 'copia',
                'nomeOriginale' => 'Domanda_' . $c . '.pdf', 'nomeProtocollato' => 'Domanda_' . $c . '_protocollata_copia_per_consultazione.pdf',
            ];
        }
        // attestato: l'originale è il PDF firmato dal Direttore (mai modificato); il documento protocollato è quello registrato con il numero (a mano o copia stampigliata)
        $firmato = (string) $this->db->valore("SELECT file_firmato FROM pratiche_firme WHERE pratica_id = ? AND tipo = 'direttore' AND stato = 'firmata' AND file_firmato IS NOT NULL AND file_firmato <> '' ORDER BY id DESC LIMIT 1", [$rifId]);

        return [
            'originale' => $this->dentro($firmato, $dir), 'protocollato' => $this->dentro($evento('Firmato_direttore_' . $p['codice'] . '.pdf'), $dir), 'daOriginale' => false, 'origine' => $origine,
            'nomeOriginale' => 'Attestato_firmato_' . $c . '.pdf', 'nomeProtocollato' => 'Attestato_protocollato_' . $c . ($origine === 'copia' ? '_copia_per_consultazione' : '') . '.pdf',
        ];
    }
}
