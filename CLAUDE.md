# MyDiBEST — regole per chi scrive codice qui

PHP 8.2, nessuna dipendenza nuova (sul server non c'è Composer: i pacchetti si caricano come `.tgz`). Il dominio è in `src/`, in italiano
(`ServizioScuole`, `daAbbinare()`); i termini tecnici universali restano quelli (`Http`, `Csrf`, `Container`). Spiegazione completa del
nucleo e un esempio da copiare: `documentazione/nucleo.md`. Piano e storia: `documentazione/piano_framework_dibest.md`.

## Una pagina nuova (in tutto ~40 righe, mai `nega_accesso`, `csrf_verify`, `$h = fn`)

1. Una riga in `src/Portale/Instradamento/Rotte.php`: `new Rotta('percorso', PaginaX::class, 'Titolo', 'file.php')`
   (il percorso = il file senza `.php`; una pagina del pannello sta in `admin/` e va in `PermessiPagine`).
2. Il guscio `file.php`: `require_once 'config.php'; require_once 'functions.php'; App\Http\Nucleo::serviFile(__FILE__);`
3. `src/Portale/Pagine/PaginaX.php`: `final class PaginaX implements Pagina`, dipendenze nel costruttore, `__invoke(Richiesta $r): Risposta`.
   Niente `echo`, `exit`, `$_GET`/`$_POST`, globali.
4. Il modello in `viste/…`: comincia con la guardia, scrive `<?php echo $d->campo; ?>` (già escapato).
5. La prova con `Richiesta::finta()` (modello: `tests/Integration/Portale/PaginaContattiTest.php`).

## Cosa NON si fa

- Niente astrazione prima di tre usi veri. Niente ORM né query builder (bastano `App\Core\Database` e i Repository).
- Niente motore di template (le viste sono PHP), niente rotte per attributi (la tabella è un array), niente pila di middleware
  (accesso e CSRF stanno nel nucleo), niente event bus, code, service provider. `App::get()` non cresce.
- Niente dipendenze nuove. Niente opzioni di configurazione per casi d'uso che non ci sono.
- `src/Http` non nomina il portale (nessun `App\Auth`, nomi di file, tabelle): lo verifica `NucleoSenzaPortaleTest`.
- Gli URL vecchi non muoiono mai (QR su badge e cartelli, link in email e PDF firmati): un file `.php` esistente resta dov'è.
- La testata del sito non si tocca. Ogni `<script>` in linea col `nonce`, nessun `onclick=` (si usa `data-on-*`).

## Comandi (Windows, XAMPP)

```
# MariaDB DEVE essere accesa, altrimenti centinaia di prove risultano SALTATE (devono essere 2, non di più)
C:/xampp/mysql/bin/mysqld.exe --defaults-file=C:/xampp/mysql/bin/my.ini --standalone
# PHPUnit 9.6 (phar fuori dal progetto, con un bootstrap che carica src/autoload.php e definisce RADICE_SITO): una cartella alla volta
php -d extension=zip phpunit-9.6.phar --bootstrap bootstrap.php --no-configuration tests/Unit
php -d extension=zip phpunit-9.6.phar --bootstrap bootstrap.php --no-configuration tests/Integration
# Prove delle funzioni e delle pagine via HTTP (database di prova usa e getta); --sposta-ancora=N sposta di N giorni la settimana delle prenotazioni
php -d extension=zip -d extension=gd -d extension=intl strumenti/prove/esegui.php
bash strumenti/prove/apache_nucleo.sh          # Apache vero: ogni indirizzo di oggi risponde uguale con e senza nucleo
php strumenti/avanzamento_nucleo.php --casi    # quante pagine passano dal nucleo; --controlla = rotte e gusci coincidono
```

Durante il lavoro si lanciano le prove mirate al passo; la suite intera una volta prima del commit. Lanciare `esegui.php` due volte
entro 10 minuti dà 4 rosse finte su `stampa_ricevuta` (limite di tentativi per IP): svuota prima `rate_limit_attempts`.
Una prova rossa non si lascia mai «per dopo»: o si corregge o si dice perché.

## Git

- **Mai `git stash`**: la cartella è su Google Drive, il ripristino dei file può fallire e lascia l'albero a metà. Per confrontare con la
  versione di prima: `git worktree add --detach <cartella fuori da Drive> HEAD`.
- **Niente attribuzioni a Claude** nei commit e nelle PR, in nessuna forma (né `Co-Authored-By`, né «Generated with»): l'utente non vuole
  Claude tra i contributori. Messaggi in italiano. Un commit per fase; il commit lo decide l'utente.
- Remote: `origin` (personale) e `unical` (organizzazione); `git push tutti main` li aggiorna entrambi.
