# Il nucleo: come si aggiunge una pagina

Il nucleo è lo strato che riceve la richiesta, trova la rotta, chiede l'accesso, controlla il token di ogni POST,
costruisce la pagina e manda la risposta. Sta in `src/Http/` (il meccanismo, che non nomina mai il portale) e in
`src/Portale/Instradamento/` (la tabella delle rotte e chi decide l'accesso). È **eliminabile**: i file `.php` di
oggi restano dove sono, e senza `nucleo.php` gli indirizzi di sempre rispondono identici. Piano e storia:
`piano_framework_dibest.md`. Conteggi di avanzamento: `php strumenti/avanzamento_nucleo.php`.

Una pagina nuova non scrive `nega_accesso()`, `csrf_verify()` né `$h = fn(...)`: li fa il nucleo.

## Esempio completo (si copia): una pagina pubblica

L'esempio è vero, non inventato: è «Chi contattare per cosa». Cambia i nomi e hai una pagina.

**1. La rotta** — una riga in `src/Portale/Instradamento/Rotte.php`:

```php
new Rotta('contatti', PaginaContatti::class, 'Contatti', 'contatti.php'),
//        indirizzo   classe della pagina    titolo       file di oggi (il guscio)
```

L'indirizzo è il percorso del file **senza `.php`**: le testate usano link relativi (`href="index.php"`), quindi una
pagina servita a una profondità diversa da quella del suo file aprirebbe link sbagliati. Per una pagina del
pannello il file sta in `admin/` (`'admin/contatti'`) e **deve essere in `PermessiPagine`** con il suo permesso: una
rotta del pannello senza permesso dichiarato non passa la prova (`RotteTest`). La rotta non porta permessi.

**2. Il guscio** — il file `contatti.php` in radice, tre righe, ed è l'indirizzo che non muore mai:

```php
<?php
require_once 'config.php';
require_once 'functions.php';
App\Http\Nucleo::serviFile(__FILE__);
```

**3. La pagina** — `src/Portale/Pagine/PaginaContatti.php`: dipendenze nel costruttore (le fornisce il container),
nessun globale, nessun `echo`, nessun `exit`; restituisce una `Risposta`:

```php
final class PaginaContatti implements Pagina
{
    public function __construct(private readonly ContattiRepository $contatti) {}

    public function __invoke(Richiesta $r): Risposta
    {
        $contatti = $this->contatti->visibili();   // qui si prepara ciò che il modello mostra, niente HTML
        return Risposta::html(Vista::pagina('Contatti', Vista::rendi('pubblico/contatti', ['contatti' => $contatti])));
    }
}
```

Le risposte possibili sono in `Risposta`: `html`, `json`, `vai` (il rimando, 303), `file`, `scaricabile`, `contenuto`,
`vuota`, `negata`, `nonTrovata`, `erroreServer`. Il corpo di un modulo POST arriva da `$r->post('campo')`, la query da `$r->query('x')`.

**4. Il modello** — `viste/pubblico/contatti.php`. Comincia con la guardia, riceve solo `$d` e scrive **senza
`htmlspecialchars`**: `$d->x` è già escapato.

```php
<?php if (!($d ?? null) instanceof \App\Http\DatiVista) { exit; } ?>
<h1>Chi contattare per cosa</h1>
<?php foreach ($d->elenco('contatti') as $c): ?>
    <h2><?php echo $c->titolo; ?></h2>
<?php endforeach; ?>
```

Regole dei modelli (le fa rispettare `VistaTest`): ogni `<script>` in linea con `nonce="<?php echo csp_nonce(); ?>"`,
nessun `onclick=` ma `data-on-*` (`assets/js/azioni.js`), conferme con `data-conferma`.

**5. La prova** — `tests/Integration/Portale/PaginaContattiTest.php` (si copia tutta): database di prova, modello vero
in una radice temporanea con testate finte, una `Richiesta::finta()` e si legge la `Risposta`. Senza browser:

```php
$r = (new PaginaContatti(new ContattiRepository($this->db)))(Richiesta::finta('GET', '/contatti.php'));
self::assertSame(200, $r->codice());
self::assertStringContainsString('I contatti sono in preparazione', $r->corpo());
```

Poi, per le pagine che scrivono: la prova HTTP end-to-end `strumenti/prove/oop/pagine_contatti.php` (403 del docente,
POST senza token, registro) è il modello. Alla fine: `php strumenti/avanzamento_nucleo.php --controlla` deve dire che la tabella
e i gusci coincidono.

## Pagine-script e rotte convivono: chi decide l'accesso

Delle 201 pagine, oggi poche passano dal nucleo (il numero vero lo dà `avanzamento_nucleo.php`). Le altre sono
pagine-script e **non cambiano finché non le si tocca per un'altra ragione**. L'accesso si decide in due modi:

| | pagina-script (come oggi) | rotta del nucleo |
|---|---|---|
| pubblica | la pagina da sé (`nega_accesso()`, i controlli sul codice o sul profilo) | il nucleo lascia entrare; le regole proprie restano nella pagina |
| del pannello (`admin/`) | `admin_header.php` applica `PermessiPagine` prima della pagina | `AccessoPortale` → `AccessoPannello::decidi()` con la **stessa** `PermessiPagine` |
| token CSRF di un POST | `csrf_verify()` a mano, pagina per pagina | il nucleo, per ogni POST, **prima** di costruire la pagina |

La fonte dei permessi è una sola (`PermessiPagine`): la rotta non ne scrive. Quando il nucleo ha già deciso, lo
dichiara alla testata (`AccessoPannello::giaDecisoPer`) e la testata non decide una seconda volta; una pagina-script, che non passa
dal nucleo, decide sempre da sé. Una rotta rinuncia al token solo con `senzaCsrf` = una **stringa con il motivo**
(almeno 30 caratteri: un webhook esterno firmato, per dire); oggi nessuna lo fa.

## Limiti noti (non difetti: scritti per non riscoprirli)

**I cinque limiti di `$d`** (`DatiVista`):
1. **Doppio escape nei parziali:** passare `$d->x` (già escapato) a `$d->parziale()` lo escapa due volte. Si passa
   `grezzo('x')` o il record preso da `elenco()`.
2. **Chiavi delle mappe:** `elenco()` restituisce una lista e le chiavi si perdono (non sono escapate e non devono finire in un
   attributo). Una mappa si passa come lista di record.
3. **Apostrofi letterali:** un testo scritto nel modello con l'apostrofo resta lì; passandolo da `$d` diventerebbe `&#039;`.
4. **Booleani e interi** arrivano come `'1'` o `''`: il modello scrive `(int) $d->x`.
5. **`href` e JavaScript:** `htmlspecialchars` non basta (`javascript:`). Oggi tiene perché `ContattiRepository::salva()` valida
   l'indirizzo a monte, ma il disegno da solo non lo garantisce. Al terzo modello che stampa un indirizzo preso dal database si
   aggiunge `$d->indirizzo('x')` (solo `http(s)://`, `mailto:`, `tel:` e percorsi relativi) con la sua prova; non prima.

**Stato statico di `Vista`** (`$layout`, `$avviso`, `$radice`): sono proprietà statiche, ciascuna con il suo salva-e-rimetti.
Va bene oggi (una richiesta = un processo PHP, e le prove le rimettono a posto). In un processo che serve **più richieste**
(Swoole, RoadRunner) o con prove in parallelo si comporterebbe male: il messaggio di una richiesta finirebbe in un'altra. Non si
cambia adesso; chi sposta il portale su un server a processo persistente deve prima trasformarle in stato per richiesta.

**Una rotta nuova sotto `admin/` oggi non arriva a `nucleo.php`:** la regola 0 del `.htaccess` chiude con `[L]` su `^admin(/|$)`
(prima della regola 3b del nucleo). Finché le pagine del pannello sono file veri non si nota. La prima pagina del pannello che
non è anche un file avrà bisogno di una regola «0b» dentro quel blocco. Le rotte con un guscio in `admin/` (come `admin/contatti`) funzionano.

**Ogni rotta ha oggi un guscio:** `avanzamento_nucleo.php --controlla` dà anomalia se il file della rotta non esiste o non chiama
`Nucleo::serviFile`. Una pagina nuova senza file di oggi (percorso con almeno una «/», senza layout) è prevista da `Rotta` e da
`nucleo.php`, ma lo strumento oggi la segnalerebbe: andrebbe prima insegnato a distinguerla.

**Gli avvisi dopo il salvataggio** si consumano solo quando un layout li mostra (`Vista::avviso()`): una risposta senza layout
(JSON, stampa, rimando) non li consuma, e il messaggio resta per la prima pagina con testata. Era voluto: dopo un POST che salva si
rimanda, e il messaggio non deve perdersi.

**`Vista::pannello()`** è usabile solo da una `Pagina` del nucleo (l'accesso è già deciso). Una pagina-script del pannello include
`admin_header.php` per prima, come oggi, e usa solo `Vista::rendi()`.

**La guardia dei permessi della testata** non è stata tolta: per chi può entrare non fa niente, per chi non può risponde lei (403, rimando al
login, rimando all'area personale) senza decidere due volte.

## Prove e strumenti

- Suite: `php -d extension=zip phpunit-9.6.phar --bootstrap bootstrap.php --no-configuration tests/Unit` e poi `tests/Integration`
  (una cartella alla volta, MariaDB di XAMPP **accesa**: senza, le prove di database risultano saltate a centinaia). Vedi `CLAUDE.md`.
- `php -d extension=zip -d extension=gd -d extension=intl strumenti/prove/esegui.php` — tutte le funzioni e le pagine via HTTP; con
  `--sposta-ancora=N` sposta di N giorni la settimana su cui sono create le prenotazioni delle prove (serve a provare i giorni «difficili»).
- `bash strumenti/prove/apache_nucleo.sh` — Apache vero: ogni indirizzo di oggi risponde uguale con e senza nucleo.
- `php strumenti/avanzamento_nucleo.php [--casi] [--controlla]` — quante pagine passano dal nucleo, quanto resta da convertire.
