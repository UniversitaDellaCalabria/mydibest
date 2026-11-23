<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\File;
use InvalidArgumentException;

/**
 * La risposta a una richiesta: codice, intestazioni, corpo. Immutabile; si costruisce con un costruttore statico e si manda con emetti().
 *
 * emetti() è l'UNICO punto di src/Http che chiama header(), http_response_code() o stampa: una prova legge codice(), intestazioni()
 * e corpo() senza che esca niente. Due regole che vengono dall'ambiente di prova (App\Core\FasciaProva) e non si toccano:
 *  1. le intestazioni partono PRIMA del corpo, sempre: FasciaProva::inietta() guarda headers_list() e l'inizio del corpo, e se il
 *     Content-Type non c'è ancora una risposta JSON o un PDF si ritrova la fascia arancione dentro;
 *  2. le risposte che non sono HTML (JSON, file, rimandi, testi) buttano tutti i buffer di uscita prima di cominciare, come fanno
 *     `vai_a()` e `Download::invia()`; le risposte HTML no, perché è il buffer aperto all'avvio del portale che mette la fascia di prova.
 * Non assumere mai che il buffer sia a profondità 1: inc/ui.php ne apre altri sopra.
 *
 * Il rimando (vai) NON controlla che l'indirizzo sia del sito: lo fa chi lo costruisce (vai_a() con App\Auth\RitornoInterno), perché
 * il nucleo non sa quali siano gli indirizzi del portale.
 */
final class Risposta
{
    /** Un solo testo per ogni 403: copre sia il permesso che manca sia il token CSRF scaduto o sbagliato. */
    public const MESSAGGIO_NEGATO = 'Accesso negato. Non hai i permessi per questa operazione, oppure la sessione è scaduta: torna indietro, ricarica la pagina e riprova.';

    public const MESSAGGIO_NON_TROVATO = 'Pagina non trovata.';

    private const FLAG_JSON = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /** Estensione => tipo, per i file dal disco quando il chiamante non dice il tipo. Quello che non c'è è application/octet-stream. */
    private const TIPI = [
        'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8', 'zip' => 'application/zip',
        'ics' => 'text/calendar; charset=utf-8', 'xml' => 'application/xml', 'json' => 'application/json',
        'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * @param array<string, array{0: string, 1: string}> $intestazioni nome in minuscolo => [nome, valore], nell'ordine di invio
     */
    private function __construct(
        private int $codice,
        private array $intestazioni,
        private string $corpo,
        private bool $svuotaBuffer,
        private ?string $percorsoFile = null,
        private bool $cancellaDopo = false,
    ) {
    }

    // ── Costruttori ─────────────────────────────────────────────────────────────

    /**
     * Una pagina HTML (intera o frammento). Gli errori (403, 404, 500, 503…) hanno i loro costruttori, che scartano il buffer: negata(),
     * nonTrovata(), erroreServer(). Per il 204 e per le risposte senza corpo c'è vuota(). Non butta i buffer: la fascia dell'ambiente di
     * prova passa da lì.
     */
    public static function html(string $corpo, int $codice = 200): self
    {
        return new self($codice, self::elenco([['Content-Type', 'text/html; charset=utf-8']]), $corpo, false);
    }

    /**
     * Un rimando: 303 (dopo un invio il browser apre la nuova pagina con una richiesta normale), 301/302/307/308 se serve.
     * Non controlla che l'indirizzo sia del sito (vedi la nota della classe), ma rifiuta ciò che spezzerebbe l'intestazione.
     */
    public static function vai(string $url, int $codice = 303): self
    {
        if (!in_array($codice, [301, 302, 303, 307, 308], true)) {
            throw new InvalidArgumentException("Codice di rimando non valido: $codice");
        }
        $url = str_replace(' ', '%20', trim($url));
        self::controllaValore('Location', $url);

        return new self($codice, self::elenco([['Location', $url]]), '', true);
    }

    /** JSON (UTF-8 non escapato, come gli endpoint di prima); un byte non valido diventa «�» invece di svuotare la risposta. */
    public static function json(mixed $dati, int $codice = 200): self
    {
        return new self($codice, self::elenco([['Content-Type', 'application/json; charset=utf-8']]), json_encode($dati, self::FLAG_JSON), true);
    }

    /**
     * Un file dal disco. $nome = il nome con cui si salva; null = nessun Content-Disposition (il browser lo apre da sé, come i PDF di oggi).
     * $tipo null = ricavato dall'estensione. $cancella = toglie il file dopo l'invio (Download::invia); $inline = lo mostra invece di scaricarlo.
     * Il file deve esistere: chi chiama lo controlla (e risponde con nonTrovata()).
     */
    public static function file(string $percorso, ?string $nome = null, ?string $tipo = null, bool $cancella = false, bool $inline = false): self
    {
        if (!is_file($percorso)) {
            throw new InvalidArgumentException('File da inviare non trovato: ' . $percorso);
        }
        $righe = [['Content-Type', $tipo ?? (($nome !== null ? self::TIPI[strtolower(pathinfo($nome, PATHINFO_EXTENSION))] ?? null : null) ?? self::tipoDa($percorso))]];
        $righe = array_merge($righe, self::disposizione($nome, $inline), [['Content-Length', (string) filesize($percorso)], ['X-Content-Type-Options', 'nosniff']]);

        return new self(200, self::elenco($righe), '', true, $percorso, $cancella);
    }

    /**
     * Un contenuto che sta già in memoria, con il suo tipo: un file da scaricare ($nome), da mostrare nel browser ($inline) oppure,
     * con $nome null, un documento qualunque senza Content-Disposition (RSS, XML, ICS, testo, un PDF generato).
     */
    public static function scaricabile(string $byte, ?string $nome, string $tipo, bool $inline = false): self
    {
        $righe = array_merge(
            [['Content-Type', $tipo]],
            self::disposizione($nome, $inline),
            [['Content-Length', (string) strlen($byte)], ['X-Content-Type-Options', 'nosniff']],
        );

        return new self(200, self::elenco($righe), $byte, true);
    }

    /**
     * Un contenuto che sta già in memoria e che il browser mostra o interpreta da sé: RSS, XML, ICS, testo, un PDF generato.
     * Nessun Content-Disposition (se il file si deve salvare con un nome, è scaricabile()). Butta i buffer come ogni risposta non HTML.
     */
    public static function contenuto(string $corpo, string $tipo): self
    {
        return self::scaricabile($corpo, null, $tipo);
    }

    /**
     * Nessun corpo e nessun Content-Type: il 204 («fatto, non c'è niente da mostrare», come csp_report.php) o un altro codice senza testo.
     * Butta i buffer: una risposta senza corpo non si porta dietro quello che qualcuno ha già stampato.
     */
    public static function vuota(int $codice = 204): self
    {
        return new self($codice, [], '', true);
    }

    /**
     * 403. Senza $layout è un testo nudo; con $layout (una funzione che riceve il messaggio e restituisce la pagina HTML completa,
     * per esempio quella del pannello) è una pagina vera. In tutti e due i casi quello che era già stato stampato si scarta: una
     * risposta di errore sostituisce la pagina, non la continua (la guardia del pannello gira dopo centinaia di righe di layout).
     *
     * @param (callable(string): string)|null $layout
     */
    public static function negata(string $messaggio = self::MESSAGGIO_NEGATO, ?callable $layout = null): self
    {
        return self::errore(403, $messaggio, $layout);
    }

    /**
     * 404, con le stesse due forme di negata(). Messaggio vuoto e niente layout = risposta vuota.
     *
     * @param (callable(string): string)|null $layout
     */
    public static function nonTrovata(string $messaggio = self::MESSAGGIO_NON_TROVATO, ?callable $layout = null): self
    {
        return self::errore(404, $messaggio, $layout);
    }

    /**
     * 500 (o un altro codice 5xx, per esempio il 503 della manutenzione). Stessa strada di negata() e nonTrovata(): testo nudo oppure, con $layout,
     * una pagina vera, e in tutti e due i casi quello che era già stato stampato si scarta. Una pagina che si rompe a metà non deve lasciare
     * metà della sua uscita davanti al messaggio d'errore.
     *
     * @param (callable(string): string)|null $layout
     */
    public static function erroreServer(string $messaggio, int $codice = 500, ?callable $layout = null): self
    {
        if ($codice < 500 || $codice > 599) {
            throw new InvalidArgumentException("Codice di errore del server non valido: $codice");
        }

        return self::errore($codice, $messaggio, $layout);
    }

    // ── Variazioni ──────────────────────────────────────────────────────────────

    /** La stessa risposta con un'intestazione in più (o con quella di pari nome sostituita: serve per X-Robots-Tag, Cache-Control, Pragma…). */
    public function conIntestazione(string $nome, string $valore): self
    {
        self::controllaValore($nome, $valore);
        if ($nome === '' || preg_match('/[^A-Za-z0-9\-]/', $nome) === 1) {
            throw new InvalidArgumentException("Nome di intestazione non valido: $nome");
        }
        $copia = clone $this;
        $copia->intestazioni[strtolower($nome)] = [$nome, $valore];

        return $copia;
    }

    // ── Lettura (per le prove e per chi decide cosa farne) ──────────────────────

    public function codice(): int
    {
        return $this->codice;
    }

    /** Il corpo in memoria. Per i file dal disco è vuoto: vedi percorsoFile(). */
    public function corpo(): string
    {
        return $this->corpo;
    }

    /** @return array<string, string> nome => valore, nell'ordine di invio */
    public function intestazioni(): array
    {
        $out = [];
        foreach ($this->intestazioni as [$nome, $valore]) {
            $out[$nome] = $valore;
        }

        return $out;
    }

    /** Un'intestazione (il nome non distingue maiuscole e minuscole), null se manca. */
    public function intestazione(string $nome): ?string
    {
        return ($this->intestazioni[strtolower($nome)] ?? [1 => null])[1];
    }

    public function percorsoFile(): ?string
    {
        return $this->percorsoFile;
    }

    public function cancellaDopoInvio(): bool
    {
        return $this->cancellaDopo;
    }

    /** Se emetti() butta i buffer di uscita prima di cominciare (tutto tranne l'HTML). */
    public function svuotaBuffer(): bool
    {
        return $this->svuotaBuffer;
    }

    /**
     * Le intestazioni nel formato di headers_list() («Nome: valore»), nell'ordine in cui emetti() le manda. Con 1xx, 204 e 304
     * non ci sono né Content-Type né Content-Length: quelle risposte non hanno corpo.
     *
     * @return list<string>
     */
    public function intestazioniDaMandare(): array
    {
        $senzaCorpo = $this->codice < 200 || $this->codice === 204 || $this->codice === 304;
        $righe = [];
        foreach ($this->intestazioni as $chiave => [$nome, $valore]) {
            if ($senzaCorpo && ($chiave === 'content-type' || $chiave === 'content-length')) {
                continue;
            }
            $righe[] = $nome . ': ' . $valore;
        }

        return $righe;
    }

    // ── Emissione ───────────────────────────────────────────────────────────────

    /**
     * Manda la risposta: (buffer buttati, se la risposta non è HTML), codice e intestazioni, poi il corpo. Non termina lo script:
     * lo termina la pagina che la chiama. Se qualcosa ha già stampato prima, le intestazioni non possono più partire: lo scrive nel
     * registro degli errori e manda comunque il corpo.
     */
    public function emetti(): void
    {
        if ($this->svuotaBuffer) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        if (headers_sent($file, $riga)) {
            error_log('[Risposta] intestazioni già partite (' . $file . ':' . $riga . '): codice e tipo non si possono più impostare');
        } else {
            http_response_code($this->codice);
            foreach ($this->intestazioniDaMandare() as $r) {
                header($r);
            }
        }
        if ($this->percorsoFile !== null) {
            readfile($this->percorsoFile);
            if ($this->cancellaDopo) {
                File::togli($this->percorsoFile);
            }

            return;
        }
        echo $this->corpo;
    }

    // ── Dettagli interni ────────────────────────────────────────────────────────

    /**
     * Una risposta di errore scarta SEMPRE il buffer, con o senza layout: con il layout il corpo è la pagina vestita, ma prende il
     * posto di quello che c'era prima invece di accodarsi. Conseguenza accettata: nella copia di prova i buffer buttati sono tutti,
     * compreso quello della fascia, quindi una pagina di errore con layout non porta la fascia arancione (come già i testi nudi).
     *
     * @param (callable(string): string)|null $layout
     */
    private static function errore(int $codice, string $messaggio, ?callable $layout): self
    {
        if ($layout !== null) {
            return new self($codice, self::elenco([['Content-Type', 'text/html; charset=utf-8']]), $layout($messaggio), true);
        }

        return new self($codice, self::elenco([['Content-Type', 'text/plain; charset=utf-8']]), $messaggio, true);
    }

    /** @return list<array{0: string, 1: string}> */
    private static function disposizione(?string $nome, bool $inline): array
    {
        if ($nome === null) {
            return [];
        }
        $pulito = preg_replace('/[^\w.\- ]+/u', '_', $nome);

        return [['Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $pulito . '"']];
    }

    private static function tipoDa(string $nomeOPercorso): string
    {
        return self::TIPI[strtolower(pathinfo($nomeOPercorso, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    private static function controllaValore(string $nome, string $valore): void
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $valore) === 1) {
            throw new InvalidArgumentException("Il valore di $nome contiene caratteri di controllo");
        }
    }

    /**
     * @param list<array{0: string, 1: string}> $righe
     * @return array<string, array{0: string, 1: string}>
     */
    private static function elenco(array $righe): array
    {
        $out = [];
        foreach ($righe as [$nome, $valore]) {
            $out[strtolower($nome)] = [$nome, $valore];
        }

        return $out;
    }
}
