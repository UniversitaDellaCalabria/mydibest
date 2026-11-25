# php-titulus-utility 🏛️

Connettore **PHP** per il protocollo SOAP di **Titulus**, pari del connettore Python [`dj-titulus-utility`](../README.md): stesse operazioni (`saveDocument`, `addInFolder`, `setWSUser`, `getRecordInfos`, `getDocumentURL`, `loadDocument`, `getAttachment`), stessi XML, stessi controlli. Non dipende da nessun framework né da un database: lo usa qualsiasi programma PHP (Laravel, Symfony, WordPress, uno script).

Cosa fa: protocollo **in arrivo**, **in partenza**, **tra uffici**, **avvio dell'iter** (bozza + voce d'indice), fascicolazione, lettura del numero di un'iter concluso, **lettura dei file di un documento già in Titulus**, prova del collegamento.

## 📦 Requisiti e installazione

PHP 8.1 o superiore, con le estensioni `soap`, `dom`, `mbstring` (e, facoltativa, `zip`).

**Con Composer** (dal repository, o da una cartella locale):

```json
{
    "repositories": [{ "type": "path", "url": "../php-titulus-utility" }],
    "require": { "unical/php-titulus-utility": "*" }
}
```

**Senza Composer**: copia la cartella dove vuoi e carica l'autoloader.

```php
require '/percorso/php-titulus-utility/autoload.php';
```

## ⚙️ Configurazione

La configurazione è un oggetto: la riempi come preferisci. **La password si passa a parte**, così non finisce per sbaglio in un log.

```php
use Unical\Titulus\ConfigTitulus;

// a mano
$config = new ConfigTitulus(
    urlWs: 'https://titulus-prod.dominio.it/ws/Titulus4?wsdl',
    username: 'utente_ws',
    aoo: 'UCALPRG',
    autore: 'Il mio programma',          // facoltativo
);

// da un array (per esempio una riga del tuo database)
$config = ConfigTitulus::daArray(['url_ws' => '…', 'username' => '…', 'aoo' => '…']);

// dalle variabili d'ambiente TITULUS_URL_WS, TITULUS_USERNAME, TITULUS_AOO (+ TITULUS_AUTORE, TITULUS_AGD, …)
$config = ConfigTitulus::daAmbiente();   // la password è TITULUS_PASSWORD
```

## 🚀 Esempi di utilizzo

### 0. Prova del collegamento (non crea nulla in Titulus)

```php
use Unical\Titulus\ServizioTitulus;

$titulus = new ServizioTitulus($config, getenv('TITULUS_PASSWORD'));
['ok' => $ok, 'messaggio' => $msg] = $titulus->provaConnessione();
```

### 1. Protocollo in arrivo

```php
use Unical\Titulus\{AllegatoTitulus, DocumentoDaProtocollare, ParametriProtocollo};

$documento = new DocumentoDaProtocollare(
    oggetto: 'Convenzione di tirocinio',
    principale: AllegatoTitulus::crea('convenzione.pdf', file_get_contents('convenzione.pdf')),
    allegati: [AllegatoTitulus::crea('allegato.pdf', $altroPdf)],            // facoltativo
    esterno: ['nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario@example.it'],  // chi scrive
);

$parametri = new ParametriProtocollo(
    tipo: 'arrivo',
    uo: '2014.2',                // ufficio dell'RPA (vedi CatalogoTitulus::UFFICI)
    codTitolario: '5/1',         // vedi CatalogoTitulus::TITOLARIO
    rpaNome: 'Anna Bianchi',
    rpaMatricola: '123',         // serve solo per la fascicolazione
    fascicoloNumero: '2026-5/1.1',   // facoltativo
);

$esito = $titulus->protocollaArrivo($documento, $parametri);
echo $esito->ok ? "Protocollo: {$esito->numProt}" : "Errore: {$esito->messaggio}";
```

I metodi che inviano **non lanciano mai eccezioni**: restituiscono un `EsitoProtocollo` (`ok`, `messaggio` già scritto in italiano, `nrecord`, `numProt`, `url`).

### 2. Protocollo in partenza

Serve il codice fiscale del destinatario.

```php
$documento = new DocumentoDaProtocollare('Lettera di incarico', $pdf,
    esterno: ['nome' => 'Anna', 'cognome' => 'Verdi', 'codice_fiscale' => 'VRDNNA80A41H501X']);
$esito = $titulus->protocollaPartenza($documento, $parametri);
```

### 3. Documento tra uffici

Se non indichi il mittente (`conMittenteInterno([...])`) si usano l'ufficio e l'RPA dei parametri.

```php
$esito = $titulus->protocollaInterno(new DocumentoDaProtocollare('Nota interna', $pdf), $parametri);
```

### 4. Avvio dell'iter / bozza

Titulus restituisce un **nrecord**, non il numero di protocollo. Serve la voce d'indice.

```php
$parametri = new ParametriProtocollo('arrivo', '2014.2', '5/1', 'Anna Bianchi', voceIndice: 'Iter Approva e Firma');
$esito = $titulus->avviaIter($documento, $parametri);   // $esito->nrecord

// più tardi, quando l'iter è concluso:
$r = $titulus->leggiNumero($esito->nrecord);            // ['ok' => true, 'num_prot' => '…', 'url' => '…']
```

### 5. Registro degli invii e «mai due volte»

Passando un `RegistroInvii` ogni invio (riuscito o no) viene annotato, e `numero()` non protocolla due volte lo stesso documento: se per quel riferimento (`rifTipo`, `rifId`) esiste già un numero, lo restituisce.

```php
use Unical\Titulus\RegistroSuFile;   // oppure RegistroInMemoria, oppure il tuo (implementa RegistroInvii: 2 funzioni)

$titulus = new ServizioTitulus($config, $password, registro: new RegistroSuFile('/var/log/titulus.jsonl'));

$documento = new DocumentoDaProtocollare('Domanda', $pdf, esterno: ['nome' => 'Mario'], rifTipo: 'domanda', rifId: 42);
['numero' => $numero, 'errore' => $errore] = $titulus->numero($documento, $parametri);
```

### 6. Leggere i file di un documento (sola lettura)

`fileDelDocumento()` chiama `loadDocument` (senza bloccare il documento) e dà, per ogni file, il titolo e l'identificativo della **versione più recente**
(il nodo `xw:file` più annidato, namespace `http://www.kion.it/ns/xw`). `scaricaFile()` chiama `getAttachment` e dà il contenuto in binario (se il servizio
lo manda in base64 lo decodifica). Come gli altri metodi del servizio **non lanciano eccezioni** e spiegano il motivo in italiano:
permesso negato, documento inesistente, nessun file, risposta inattesa.

```php
$r = $titulus->fileDelDocumento('2026-UCALPRG-0001234');     // nrecord o numero di protocollo
// ['ok' => true, 'messaggio' => 'Il documento ha 2 file.',
//  'file' => [['titolo' => 'convenzione.pdf', 'fileId' => '…', 'principale' => true, 'sha256' => 'ab12…'], …]]

$f = $titulus->scaricaFile($r['file'][0]['fileId']);
// ['ok' => true, 'messaggio' => 'File scaricato.', 'contenuto' => '%PDF-…']
```

- `principale` è `true` se Titulus segna il file con `principale="true"`; chi sceglie il file da un documento con più file prende quello con `principale`
  se è uno solo, altrimenti si affida al titolo con cui l'ha inviato, mai a uno a caso.
- `sha256` è l'impronta che Titulus dichiara per la versione scelta (`DigestValue` accanto a un `DigestMethod` SHA-256, in esadecimale minuscolo), `null` se
  manca, non è SHA-256 o i valori trovati sono diversi. Chi scarica il file deve confrontarla con `hash('sha256', $contenuto)`: se c'è e non coincide il
  download non è da accettare.
- L'XML della risposta si legge senza rete né entità esterne, rifiutando i `DOCTYPE`; ha un tetto alla dimensione (5 MB), alle versioni annidate (50) e ai file (200).
- L'utente del web service può non avere il permesso di leggere i file: per questo `provaConnessione()` **non** richiede `loadDocument` e `getAttachment`
  (si controllano con `ClienteSoapTitulus::operazioni()`, che dà i nomi delle operazioni del WSDL).
- Il file che Titulus restituisce può essere quello inviato o la copia con la stampigliatura: va guardato in pre-produzione prima di fidarsene.

## 🧱 Com'è fatto

| Classe | A cosa serve |
| --- | --- |
| `ServizioTitulus` | il punto d'ingresso: `protocolla*`, `avviaIter`, `numero`, `leggiNumero`, `fileDelDocumento`, `scaricaFile`, `provaConnessione` |
| `ConfigTitulus`, `ParametriProtocollo`, `DocumentoDaProtocollare` | i dati: dove collegarsi, come registrare, cosa inviare |
| `AllegatoTitulus` | un file, con l'estensione giusta ricavata dal contenuto (come il connettore Python) |
| `GeneratoreXml` | gli XML del documento e della fascicolazione (con DOM: `&`, `<`, apici protetti da soli) |
| `ClienteSoapTitulus` (`ClienteTitulus`) | le chiamate SOAP con l'estensione `soap` di PHP, autenticazione HTTP Basic, la password non compare mai negli errori |
| `LetturaFileTitulus` | l'elenco dei file dall'XML di `loadDocument` e il contenuto dalla risposta di `getAttachment` (senza rete, con tetti di dimensione e profondità) |
| `CatalogoTitulus` | titolario e uffici di Titulus dell'Ateneo (da `conf.py` del connettore Python) |
| `RegistroInvii` | dove ricordare gli invii (`RegistroInMemoria`, `RegistroSuFile`, o il tuo) |

`ClienteTitulus` e `FabbricaClienteTitulus` sono interfacce: nelle prove si passa un cliente finto come terzo argomento di `ServizioTitulus`, senza uscire in rete.

## 🧪 Prove

```bash
php tests/esegui.php
```

Prove senza Composer e senza rete (cliente SOAP finto). Il servizio vero si prova con `provaConnessione()`.

## ↔️ Differenze rispetto al connettore Python

- Non c'è il database di configurazione (`ConfigurationWSProtocollo*`): la configurazione e i parametri sono oggetti.
- Non ci sono ancora le ricevute di ritorno (`WSTitulusMessageBroker`). La lettura degli allegati (`WSTitulusFileClient`) c'è: `fileDelDocumento()` e `scaricaFile()`; mancano il titolo come argomento di ricerca e il salvataggio su disco, che sono di chi usa il pacchetto.
- L'ambiente di collaudo non è un parametro `test=True`: si crea un secondo `ConfigTitulus` col WSDL di collaudo.

## 📄 Licenza

Apache License 2.0: vedi il file [LICENSE](LICENSE).
