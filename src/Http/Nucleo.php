<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Container;
use LogicException;
use Throwable;

/**
 * Il Nucleo: riceve la richiesta, trova la rotta, chiede l'accesso, costruisce la pagina dal Container, la chiama e restituisce la risposta.
 *
 *   servi(Richiesta)          dall'indirizzo: nucleo.php lo usa per gli indirizzi puliti (rotta sconosciuta = 404)
 *   serviRotta(Rotta, ...)    la rotta è già nota
 *   Nucleo::serviFile(__FILE__)  la forma dei gusci: i file di oggi restano dove sono, con due righe che chiamano questa. Una rotta che manca
 *                             QUI non è un 404 (l'indirizzo esiste: è il file) ma un errore di programma: 500 e registro.
 *
 * L'ordine non cambia mai: 1) rotta (o 404); 2) accesso (o 403 / il rimando che il portale ha scelto); 3) per un POST, il token CSRF (o 403);
 * 4) la pagina. Un'eccezione della pagina o del controllo dell'accesso è un 500 scritto nel registro: chi non riesce a sapere se può entrare non entra.
 *
 * Il token CSRF è del Nucleo, non delle pagine: ogni POST lo verifica PRIMA che la pagina venga costruita, e una rotta ne è esente solo se lo
 * dichiara con il suo motivo (Rotta::$senzaCsrf). Il messaggio dopo il salvataggio lo prende il Nucleo (Vista::avviso) e lo dà al layout che lo mostra.
 *
 * Il Nucleo non conosce il portale: la tabella delle rotte, il controllo dell'accesso, il token e gli avvisi gli arrivano nel costruttore.
 * Le prove di pulizia (NucleoSenzaPortaleTest) non ammettono elenchi né nomi di file del portale in questa cartella.
 */
final class Nucleo
{
    public const MESSAGGIO_ERRORE = 'Errore del programma: la pagina non è disponibile. Riprova tra poco; se il problema continua, segnalalo.';

    /** @var array<string, Rotta> indirizzo => rotta */
    private array $perIndirizzo = [];

    /** @var array<string, Rotta> file => rotta */
    private array $perFile = [];

    /** @var list<Rotta> */
    private array $elenco;

    /** @param list<Rotta> $rotte */
    public function __construct(
        private readonly Container $container,
        array $rotte,
        private readonly ControlloAccesso $accesso,
        private readonly ProtezioneCsrf $csrf,
        private readonly Avvisi $avvisi,
        private readonly string $radice,
    ) {
        $this->elenco = $rotte;
        foreach ($rotte as $rotta) {
            foreach ($rotta->indirizzi() as $indirizzo) {
                if (isset($this->perIndirizzo[$indirizzo]) && $this->perIndirizzo[$indirizzo] !== $rotta) {
                    throw new LogicException("L'indirizzo «{$indirizzo}» è di due rotte: «{$this->perIndirizzo[$indirizzo]->percorso}» e «{$rotta->percorso}»");
                }
                $this->perIndirizzo[$indirizzo] = $rotta;
            }
            if (isset($this->perFile[$rotta->file]) && $this->perFile[$rotta->file] !== $rotta) {
                throw new LogicException("Il file «{$rotta->file}» è di due rotte: «{$this->perFile[$rotta->file]->percorso}» e «{$rotta->percorso}»");
            }
            $this->perFile[$rotta->file] = $rotta;
        }
    }

    /** La rotta di un indirizzo (relativo al sito; con o senza «.php», anche un alias), null se non c'è. */
    public function trova(string $indirizzo): ?Rotta
    {
        $n = Rotta::normalizza($indirizzo);

        return $n === '' ? null : ($this->perIndirizzo[$n] ?? null);
    }

    /**
     * La rotta di una richiesta, senza costruire il Nucleo (e quindi senza database né sessione): nucleo.php la chiede per prima, così un indirizzo
     * che non è di nessuna rotta riceve il 404 senza aprire una connessione e una sessione per ogni scansione di un robot.
     *
     * @param list<Rotta> $rotte
     */
    public static function rottaDi(array $rotte, Richiesta $richiesta): ?Rotta
    {
        $n = Rotta::normalizza($richiesta->percorsoNelSito());
        foreach ($n === '' ? [] : $rotte as $rotta) {
            if (in_array($n, $rotta->indirizzi(), true)) {
                return $rotta;
            }
        }

        return null;
    }

    /** La rotta di un file: relativo al sito («cartella/pagina.php») oppure il percorso intero (__FILE__). */
    public function trovaPerFile(string $file): ?Rotta
    {
        $file = str_replace('\\', '/', $file);
        $radice = rtrim(str_replace('\\', '/', $this->radice), '/') . '/';
        if (str_starts_with($file, $radice)) {
            $file = substr($file, strlen($radice));
        }

        return $this->perFile[ltrim($file, '/')] ?? null;
    }

    /** @return list<Rotta> */
    public function rotte(): array
    {
        return $this->elenco;
    }

    /** Dall'indirizzo della richiesta: nessuna rotta = 404. */
    public function servi(Richiesta $richiesta): Risposta
    {
        $rotta = $this->trova($richiesta->percorsoNelSito());

        return $rotta === null ? Risposta::nonTrovata() : $this->serviRotta($rotta, $richiesta);
    }

    /** Accesso, token (se è un POST), poi pagina. Mai il contrario. */
    public function serviRotta(Rotta $rotta, Richiesta $richiesta): Risposta
    {
        $avvisoPrima = Vista::avviso(fn (): string => $this->avvisi->html());
        try {
            $esito = $this->accesso->verifica($rotta, $richiesta);
            if ($esito instanceof Risposta) {
                return $esito;
            }
            if ($esito !== true) {
                return Risposta::negata();
            }
            // Il token di ogni POST, prima di costruire la pagina: la pagina non può dimenticarlo e la sua logica non gira mai senza.
            if ($richiesta->eUnPost() && $rotta->senzaCsrf === null && !$this->csrf->valido($richiesta->post('csrf_token', ''))) {
                return Risposta::negata();
            }
            $pagina = $this->container->get($rotta->pagina);
            if (!$pagina instanceof Pagina) {
                throw new LogicException("La classe {$rotta->pagina} (rotta «{$rotta->percorso}») non implementa App\Http\Pagina");
            }

            return $pagina($richiesta);
        } catch (Throwable $e) {
            error_log('[Nucleo] rotta «' . $rotta->percorso . '»: ' . $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');

            return Risposta::erroreServer(self::MESSAGGIO_ERRORE);
        } finally {
            Vista::avviso($avvisoPrima);
        }
    }

    /**
     * La forma dei gusci. Il file si indica con __FILE__; la pagina viene servita e la risposta emessa. Richiede che il guscio abbia già
     * avviato il portale (il Container nasce lì).
     */
    public static function serviFile(string $file): void
    {
        $nucleo = App::get(self::class);
        $rotta = $nucleo->trovaPerFile($file);
        if ($rotta === null) {
            error_log('[Nucleo] il guscio «' . basename($file) . '» non ha una rotta: aggiungila alla tabella delle rotte');
            Risposta::erroreServer(self::MESSAGGIO_ERRORE)->emetti();

            return;
        }
        $nucleo->serviRotta($rotta, Richiesta::daGlobali())->emetti();
    }
}
