<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Le viste: un modello è un file PHP in viste/ che riceve un solo oggetto, `$d` (App\Http\DatiVista), con l'escape automatico.
 *
 *   Vista::rendi('cartella/nome', ['titolo' => $t])   il modello viste/cartella/nome.php, restituito come stringa
 *   Vista::pagina($titolo, $corpo, [...])             il corpo dentro la testata e il piè di pagina del layout «sito»
 *   Vista::pannello($titolo, $corpo, [...])           il corpo dentro il layout «pannello»
 *   Vista::layout([...])                              quali file aprono e chiudono i due layout: lo dà il portale, una volta, dove nasce il container
 *
 * Le due testate NON sono riscritte: ci si passa attraverso (si includono e basta). Quello che si ottiene in più è che la pagina non è più
 * «una pila di echo e di include»: costruisce un corpo (stringa) e lo consegna a `Risposta::html()`, che è l'unico punto che stampa.
 *
 * Regole dei modelli (le fa rispettare una prova, tests/Unit/Http/VistaTest.php):
 *  - ogni <script> in linea porta `nonce="<?php echo csp_nonce(); ?>"` (Content-Security-Policy, passo S17): senza, il browser lo blocca;
 *  - nessun gestore in linea (onclick, onchange, onsubmit…): si usano gli attributi `data-on-*` di assets/js/azioni.js;
 *  - le conferme passano da `data-conferma` e `data-conferma-scrivi` (assets/js/conferma.js);
 *  - ogni modello comincia con la guardia: aperto direttamente da browser, senza `$d`, non stampa niente (nessuna cartella di viste/ deve
 *    essere raggiungibile, ma una guardia nel file costa una riga: la lezione dei parziali del pannello).
 *
 * Il pannello non si può ancora usare «a metà»: vedi la nota di pannello().
 */
final class Vista
{
    /**
     * layout => [file che apre, file che chiude], relativi alla radice. Vuoto finché il portale non li dà (vedi layout()): la Vista non
     * conosce i nomi dei file di nessun portale.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private static array $layout = [];

    /** @var (callable(): string)|null chi dà ai layout il messaggio dopo il salvataggio (vedi avviso()) */
    private static $avviso = null;

    /** @var string|null radice diversa da quella del sito, per le prove */
    private static ?string $radice = null;

    /**
     * I layout: 'sito' e 'pannello', ciascuno come [file che apre, file che chiude] relativi alla radice. È configurazione del portale e si
     * dà UNA volta, dove nasce il container (la Registrazione del portale): chi chiama pagina() o pannello() senza averli dati riceve una
     * LogicException, non una pagina a metà. Restituisce quelli di prima, per rimetterli a posto (le prove).
     *
     * @param array<string, array{0: string, 1: string}> $layout
     * @return array<string, array{0: string, 1: string}>
     */
    public static function layout(array $layout): array
    {
        foreach (['sito', 'pannello'] as $nome) {
            $coppia = $layout[$nome] ?? null;
            if (!is_array($coppia) || count($coppia) !== 2 || !is_string($coppia[0] ?? null) || !is_string($coppia[1] ?? null) || $coppia[0] === '' || $coppia[1] === '') {
                throw new InvalidArgumentException("Layout «{$nome}» non valido: serve [file che apre, file che chiude]");
            }
        }
        $prima = self::$layout;
        self::$layout = ['sito' => [$layout['sito'][0], $layout['sito'][1]], 'pannello' => [$layout['pannello'][0], $layout['pannello'][1]]];

        return $prima;
    }

    /**
     * Da dove un layout prende il messaggio dopo il salvataggio. Lo dà il Nucleo per tutta la durata di una pagina (e lo toglie alla fine): è una
     * funzione che restituisce l'HTML dell'avviso e lo svuota, così il messaggio non viene consumato se nessun layout lo mostra (un rimando, un
     * JSON). Un layout lo trova nella variabile `$leggi_avviso` dell'ambito in cui è incluso, e la usa al posto della lettura a mano. Restituisce
     * quella di prima, per rimetterla a posto.
     *
     * @param (callable(): string)|null $fornitore
     * @return (callable(): string)|null
     */
    public static function avviso(?callable $fornitore): ?callable
    {
        $prima = self::$avviso;
        self::$avviso = $fornitore;

        return $prima;
    }

    /**
     * La radice da cui si leggono viste/ e i layout. Senza argomento (o con null) torna quella del sito (RADICE_SITO); con un argomento
     * serve alle prove, che vogliono viste e testate finte. Restituisce quella di prima, per rimetterla a posto.
     */
    public static function usaRadice(?string $radice): ?string
    {
        $prima = self::$radice;
        self::$radice = $radice;

        return $prima;
    }

    /**
     * Il modello viste/$modello.php con questi dati, come stringa. Il nome è fatto di cartelle e nome separati da «/», solo lettere
     * minuscole, cifre e «_» (un parziale comincia con «_»): niente «..», niente estensione.
     *
     * @param array<string|int, mixed> $dati
     */
    public static function rendi(string $modello, array $dati = []): string
    {
        if (preg_match('~^[a-z0-9_]+(?:/[a-z0-9_]+)*$~', $modello) !== 1) {
            throw new InvalidArgumentException("Nome di modello non valido: «{$modello}» (cartella/nome, minuscole, cifre e «_»)");
        }
        $file = self::radice() . '/viste/' . $modello . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Modello non trovato: viste/$modello.php");
        }

        return self::esegui($file, DatiVista::da($dati));
    }

    /**
     * Il corpo dentro la testata e il piè di pagina del sito. $titolo è quello che le pagine mettevano in `$page_cfg['titolo']`.
     * Opzioni: `descrizione` (la `$meta_descrizione` della testata del sito), `page_cfg` (altri valori di `$page_cfg`, per esempio il colore dell'area).
     * Le intestazioni HTTP partono quando la `Risposta` che riceve la stringa viene emessa, non prima.
     *
     * @param array{descrizione?: string, page_cfg?: array<string, mixed>} $opzioni
     */
    public static function pagina(string $titolo, string $corpo, array $opzioni = []): string
    {
        return self::incornicia('sito', $titolo, $corpo, $opzioni);
    }

    /**
     * Il corpo dentro il layout del pannello. Si usa DOPO la logica della pagina e DOPO che il Nucleo ha deciso l'accesso. Una pagina che chiama
     * pannello() senza il Nucleo davanti non ha nessuna dichiarazione «accesso già deciso» (la dà AccessoPannello::decidi(), chiamato dal Nucleo):
     * la testata decide da sé, come per una pagina-script, e il risultato è comunque giusto, ma la logica della pagina (un POST che salva) ha già
     * girato. Per questo le pagine del pannello convertite sono classi `Pagina` servite dal Nucleo, che decide per prima.
     *
     * Chi decide l'accesso: se lo ha deciso il Nucleo, la testata NON lo decide di nuovo (la guardia scatta una volta sola) e, se il Nucleo ha
     * detto no, si ferma subito dopo il punto della guardia: il 403 è testata + avviso + piè di pagina. La testata, che è un file di script,
     * definisce tutto nell'ambito di incornicia(): le variabili che qualcuno rilegge da `$GLOBALS` si ripubblicano con un elenco scritto a mano
     * (vedi DALLA_TESTATA_AI_GLOBALI). Il titolo non serve: la testata del pannello scrive un titolo fisso («Gestione») e non guarda `$page_cfg`.
     *
     * @param array{page_cfg?: array<string, mixed>} $opzioni
     */
    public static function pannello(string $titolo, string $corpo, array $opzioni = []): string
    {
        return self::incornicia('pannello', $titolo, $corpo, $opzioni);
    }

    // ── Dettagli interni ────────────────────────────────────────────────────────

    private static function radice(): string
    {
        return rtrim(self::$radice ?? (defined('RADICE_SITO') ? (string) RADICE_SITO : dirname(__DIR__, 2)), '/\\');
    }

    /**
     * Include il modello in un ambito che vede SOLO `$d`: nessuna variabile della pagina, nessun `$this`. Il corpo si raccoglie in un
     * buffer proprio e si butta se il modello solleva un'eccezione (così una vista rotta non lascia mezza pagina nel buffer dell'avvio del portale).
     */
    private static function esegui(string $__modello, DatiVista $d): string
    {
        $__livello = ob_get_level();
        ob_start();
        try {
            require $__modello;
        } catch (Throwable $e) {
            while (ob_get_level() > $__livello) {
                ob_end_clean();
            }
            throw $e;
        }

        return ob_get_level() > $__livello ? (string) ob_get_clean() : '';
    }

    /**
     * Le variabili che la testata del pannello (uno script) definisce nell'ambito in cui è inclusa, e che qualcuno rilegge da `$GLOBALS`.
     * Inclusa da un metodo, la testata le lascia nell'ambito del metodo e `$GLOBALS` non le vede: se mancano, chi le legge trova niente.
     * L'ELENCO È SCRITTO A MANO, e ogni nome ha accanto chi lo legge e perché: un nome nuovo entra qui solo con il suo lettore. Non si
     * copia «tutto quello che la testata ha definito»: la testata ne definisce una cinquantina e una modifica futura ne aggiungerebbe altre
     * senza che nessuno lo decida.
     *
     *  - `briciole_base`: la legge `pn_briciole_html()` (definita nella testata del pannello, chiamata dal suo piè di pagina) per completare il segnaposto del
     *    percorso «Modulo › Sezione › Elemento» in cima alla pagina. Senza, il percorso sparisce.
     *
     * Non servono né i `$puo_*` né `$permessi` né `$filtro_p`: li leggono con `global` le pagine-script, che hanno già la testata nel loro ambito,
     * mentre le pagine servite dal Nucleo hanno il corpo pronto PRIMA della testata (non possono leggere ciò che la testata calcola dopo).
     */
    private const DALLA_TESTATA_AI_GLOBALI = ['briciole_base'];

    /** @param array<string, mixed> $variabili le variabili dell'ambito in cui è stata inclusa la testata */
    private static function esportaNeiGlobali(array $variabili): void
    {
        foreach (self::DALLA_TESTATA_AI_GLOBALI as $nome) {
            if (array_key_exists($nome, $variabili)) {
                $GLOBALS[$nome] = $variabili[$nome];
            }
        }
    }

    /**
     * @param 'sito'|'pannello'        $layout
     * @param array<string, mixed>     $opzioni
     */
    private static function incornicia(string $layout, string $titolo, string $corpo, array $opzioni): string
    {
        if (!isset(self::$layout[$layout])) {
            throw new LogicException("Layout «{$layout}» non configurato: il portale li dà con Vista::layout() quando nasce il container.");
        }
        [$apre, $chiude] = self::$layout[$layout];
        $radice = self::radice();
        global $conn; // la testata del pannello lo usa senza dichiararlo (quella del sito sì): lo legge dall'ambito in cui è incluso
        // Le testate leggono questi due nomi dall'ambito in cui sono incluse (è l'ambito di questo metodo): `$page_cfg['titolo']` per il
        // <title>, `$meta_descrizione` per la descrizione. Non si chiamano diversamente perché la testata non si tocca.
        $page_cfg = ['titolo' => $titolo] + (array) ($opzioni['page_cfg'] ?? []);
        if (self::$avviso !== null) {
            $leggi_avviso = self::$avviso; // il layout lo chiama solo se mostra gli avvisi; senza, usa la sua lettura di sempre
        }
        if (isset($opzioni['descrizione'])) {
            $meta_descrizione = (string) $opzioni['descrizione'];
        }

        $livello = ob_get_level();
        ob_start();
        try {
            require $radice . '/' . $apre;
            if ($layout === 'pannello') {
                self::esportaNeiGlobali(get_defined_vars()); // solo dopo la testata: il piè di pagina (che legge i globali) viene subito dopo
            }
            echo $corpo;
            require $radice . '/' . $chiude;
        } catch (Throwable $e) {
            while (ob_get_level() > $livello) {
                ob_end_clean();
            }
            throw $e;
        }

        // Se la testata ha già risposto da sé (un rimando di vai_a() butta tutti i buffer e termina) qui non si arriva; se i buffer sono
        // stati buttati ma lo script prosegue, non c'è niente da restituire.
        return ob_get_level() > $livello ? (string) ob_get_clean() : '';
    }
}
