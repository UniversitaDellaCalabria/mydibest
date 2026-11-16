<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ambiente di prova del portale (copia in /mydibest_pp): si accende con AMBIENTE=prova nel file .env.
 * Senza quella riga il portale si comporta come sempre: ogni metodo qui risponde «nessun limite».
 *
 * Con l'ambiente di prova acceso:
 *  - la sessione e il cookie «uscito» hanno nomi diversi (le due cartelle sullo stesso sito non si scollegano a vicenda);
 *  - le email vanno solo a EMAIL_PROVA (senza, non parte nulla);
 *  - Titulus si parla solo se l'indirizzo è di pre-produzione;
 *  - le notifiche push non partono;
 *  - i processi automatici (cron) non partono, a meno di CRON_PROVA=1;
 *  - niente scritture sul NAS (/mnt/...);
 *  - ogni pagina porta una fascia arancione e dice ai motori di ricerca di non indicizzare.
 *
 * Classe senza dipendenze: inc/ambiente.php la carica a mano all'avvio, prima di sessione e database.
 */
final class Ambiente
{
    public const NOME_SESSIONE_PROVA = 'MYDIBEST_PP';
    public const COOKIE_USCITO = 'dibest_uscito';
    public const COOKIE_USCITO_PROVA = 'dibest_uscito_pp';

    /** Parte dell'indirizzo che dice «pre-produzione» di Cineca (titulus-unical.pp.cineca.it). */
    public const SEGNO_TITULUS_PREPROD = '.pp.cineca.it';

    public const TESTO_FASCIA = 'AMBIENTE DI PROVA — i dati e le email non sono reali';

    private static ?self $corrente = null;

    public function __construct(
        private bool $prova = false,
        private string $emailProva = '',
        private bool $cronProva = false,
    ) {
    }

    /** L'ambiente di questa richiesta (impostato da inc/ambiente.php); senza, è il sito vero. */
    public static function corrente(): self
    {
        return self::$corrente ??= new self();
    }

    public static function imposta(?self $ambiente): void
    {
        self::$corrente = $ambiente;
    }

    /** Legge AMBIENTE, EMAIL_PROVA e CRON_PROVA dal file .env (lettura grezza: «prova» resta testo). */
    public static function daFile(string $percorso): self
    {
        $testo = is_file($percorso) ? (string) @file_get_contents($percorso) : '';
        $v = @parse_ini_string((string) preg_replace('/^\s*#.*$/m', '', $testo), false, INI_SCANNER_RAW) ?: [];
        $leggi = static function (string $chiave) use ($v): string {
            $x = trim((string) ($v[$chiave] ?? ''));
            if (strlen($x) >= 2 && ($x[0] === '"' || $x[0] === "'") && substr($x, -1) === $x[0]) {
                $x = trim(substr($x, 1, -1));
            }

            return $x;
        };

        return new self(
            strtolower($leggi('AMBIENTE')) === 'prova',
            $leggi('EMAIL_PROVA'),
            in_array(strtolower($leggi('CRON_PROVA')), ['1', 'true', 'yes', 'on', 'si', 'sì'], true),
        );
    }

    public function inProva(): bool
    {
        return $this->prova;
    }

    /** L'unico indirizzo che riceve le email in prova ('' se manca o non è valido). */
    public function emailProva(): string
    {
        return filter_var($this->emailProva, FILTER_VALIDATE_EMAIL) ? $this->emailProva : '';
    }

    public function cronProva(): bool
    {
        return $this->cronProva;
    }

    /** Nome del cookie di sessione da usare (null = quello normale di PHP). */
    public function nomeSessione(): ?string
    {
        return $this->prova ? self::NOME_SESSIONE_PROVA : null;
    }

    public function nomeCookieUscito(): string
    {
        return $this->prova ? self::COOKIE_USCITO_PROVA : self::COOKIE_USCITO;
    }

    /**
     * Email in prova: tutti i destinatari (A, CC, CCN) diventano EMAIL_PROVA, l'oggetto comincia con [PROVA] e il testo dice a chi era diretta.
     * Fuori dalla prova ritorna le stesse cose senza toccarle. Se manca EMAIL_PROVA ritorna null: non si deve inviare nulla.
     *
     * @param list<string> $cc
     * @param list<string> $ccn
     * @return array{a: string, cc: list<string>, ccn: list<string>, oggetto: string, corpo: string}|null
     */
    public function riscriviEmail(string $a, string $oggetto, string $corpoHtml, array $cc = [], array $ccn = []): ?array
    {
        if (!$this->prova) {
            return ['a' => $a, 'cc' => $cc, 'ccn' => $ccn, 'oggetto' => $oggetto, 'corpo' => $corpoHtml];
        }
        $prova = $this->emailProva();
        if ($prova === '') {
            return null;
        }
        $originali = 'A: ' . $a . ($cc ? ' | CC: ' . implode(', ', $cc) : '') . ($ccn ? ' | CCN: ' . implode(', ', $ccn) : '');
        $nota = '<p style="margin:0 0 14px;padding:8px 12px;border:1px solid #fd7e14;background:#fff3e6;color:#1a0f00;font:13px/1.4 Arial,sans-serif">'
            . '<strong>Destinatari originali:</strong> ' . htmlspecialchars($originali, ENT_QUOTES, 'UTF-8') . '</p>';

        return ['a' => $prova, 'cc' => [], 'ccn' => [], 'oggetto' => '[PROVA] ' . $oggetto, 'corpo' => $nota . $corpoHtml];
    }

    /** Motivo per cui in prova non si parla con Titulus, null se si può (sito vero, oppure indirizzo di pre-produzione). */
    public function titulusVietato(string $urlWs): ?string
    {
        if (!$this->prova || stripos($urlWs, self::SEGNO_TITULUS_PREPROD) !== false) {
            return null;
        }

        return 'Ambiente di prova: non invio a Titulus perché l\'indirizzo del servizio non è di pre-produzione (deve contenere «'
            . self::SEGNO_TITULUS_PREPROD . '»). Cambialo in Gestione del portale → Protocollo.';
    }

    /** Motivo per cui in prova un processo automatico (cron) non parte, null se può partire. */
    public function cronVietato(): ?string
    {
        if (!$this->prova || $this->cronProva) {
            return null;
        }

        return 'Ambiente di prova: i processi automatici non partono. Per provarli metti CRON_PROVA=1 nel file .env.';
    }

    /** Motivo per cui in prova non si scrive in questo percorso (è sul NAS), null se si può. */
    public function nasVietato(string $percorso): ?string
    {
        if (!$this->prova || !self::sulNas($percorso)) {
            return null;
        }

        return 'Ambiente di prova: «' . $percorso . '» è sul NAS (/mnt/...) e qui non si scrive. Nella copia di prova la cartella deve stare dentro il portale.';
    }

    /**
     * Cartelle del portale (uploads, cache, backups e quelle dei file riservati) che portano fuori, sul NAS.
     * In prova il portale non parte finché ce n'è una: una copia fatta male scriverebbe sui file veri.
     *
     * @return list<string> percorsi relativi alla radice
     */
    public static function cartelleSulNas(string $radice): array
    {
        $radice = rtrim($radice, '/\\');
        $candidati = ['uploads', 'cache', 'backups'];
        foreach (['verbali', 'pratiche', 'convenzioni', 'convenzioni_compilate', 'convenzioni_tesi', 'autorizzazioni', 'incarichi', 'allegati_prenotazioni'] as $c) {
            $candidati[] = 'uploads/' . $c;
        }
        $candidati[] = 'uploads/verbali/archivio';
        $trovate = [];
        foreach ($candidati as $rel) {
            if (self::sulNas($radice . '/' . $rel)) {
                $trovate[] = $rel;
            }
        }

        return $trovate;
    }

    /** Il percorso (o il collegamento in cui finisce) sta sotto /mnt? */
    public static function sulNas(string $percorso): bool
    {
        if (self::sottoMnt($percorso)) {
            return true;
        }
        // Risale fino al primo livello che esiste, poi guarda dove porta (il NAS non montato lascia un collegamento che non porta da nessuna parte)
        $p = $percorso;
        while ($p !== '' && !file_exists($p) && !is_link($p)) {
            $su = dirname($p);
            if ($su === $p) {
                return false;
            }
            $p = $su;
        }
        if (is_link($p) && self::sottoMnt((string) readlink($p))) {
            return true;
        }
        $vero = realpath($p);

        return $vero !== false && self::sottoMnt($vero);
    }

    private static function sottoMnt(string $percorso): bool
    {
        return (bool) preg_match('#^/mnt(/|$)#', str_replace('\\', '/', $percorso));
    }
}
