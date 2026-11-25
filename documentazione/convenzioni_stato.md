# Convenzioni FSL e tesi: stato dei lavori

Piano: `documentazione/piano_convenzioni_scuole_sonnet.md`.

## Passo 1 — Firma del Direttore non più bloccata (24/11/2026, non committato)

### File cambiati

| File | Cosa cambia |
|---|---|
| `src/Infrastructure/Pdf/FirmePades.php` | Riscritta la lettura: dall'«obj» che precede il `/ByteRange` scorre il dizionario vero, in qualunque ordine siano le voci, saltando `/Contents`. Espone `marca_temporale` (ETSI.RFC3161) e `intervallo` |
| `src/Infrastructure/Pdf/VerificaFirme.php` | Solo il commento |
| `src/Infrastructure/Pdf/MessaggiFirma.php` | `avvisi()`, `senzaMarche()`, `registraRifiuto()` (log), `rifiuta()`, `causaNonPdf()` |
| `src/Tutorato/VerificaPdfFirmato.php` | `verifica(..., bool $severo = true)`; `analizza()` legge l'ultima firma, non la marca |
| `src/Fsl/ServizioFirmaDirettore.php`, `src/Fsl/ServizioConvenzioneFirmata.php`, `src/Fsl/ConvenzioneCompilataRepository.php` | `$severo = false` per il Direttore; avvisi salvati; log dei rifiuti |
| `src/ConvenzioniTesi/ServizioConvenzioniTesi.php`, `src/ConvenzioniTesi/ConvenzioneTesiRepository.php` | Lo stesso per le tesi |
| `src/Core/Schema/Definizioni.php`, `Migrazioni.php` | Versione **136**: `avviso_firma` e `avviso_firma_ingresso` VARCHAR(500) in `convenzioni_compilate` e `convenzioni_tesi` |
| `admin/fsl.php`, `admin/convenzioni_tesi.php` | Riquadro giallo «Da controllare» accanto al PDF, prima della presa in carico e dopo la firma del Direttore |
| `firma_convenzione.php`, `firma_convenzione_tesi.php` | Solo il commento iniziale |
| `strumenti/verifica_firma_pdf.php` | «MOTIVI BLOCCANTI» e «AVVISI PER L'UFFICIO» separati; `--severo` per le regole degli altri moduli |
| `FirmePadesTest`, `TutoratoUnitTest`, `FirmaDirettoreTest`, `ConvenzioneFirmataTest`, `ConvenzioniTesiTest` | Prove nuove e aggiornate |

### Scelte fatte

- **Di chi sono i dati**: `/SubFilter`, `/Name` e `/M` si prendono solo dal dizionario che contiene quel `/ByteRange`, non da dizionari annidati
  (`/Prop_Build`, `/Reference`) né da altri oggetti. La scritta «/ByteRange» dentro un testo non vale come firma.
- **Nome del firmatario** decodificato in UTF-8 (anche UTF-16 ed esadecimale): prima un nome esadecimale risultava vuoto.
- **Regole severe** (tutorato, pratiche, verbali, registro): messaggi invariati. Il formato si controlla su tutte le firme nuove; struttura e
  copertura sulle voci aggiunte (una marca dopo la firma copre il file).
- **Direttore sulle convenzioni**: più firme nuove sono ammesse, con un avviso (D3); firma + marca non dà avvisi.
- **Scuola e azienda**: una marca da sola vale «senza firma».
- **Avvisi**: un nuovo caricamento riscrive quello d'ingresso e azzera quello del Direttore; la firma del Direttore riscrive il suo.
  L'avviso sul certificato di un'altra persona contiene nome e codice fiscale (com'era già il testo) e ora resta nel database, visibile solo all'ufficio.
- **Log**: `[firma rifiutata][dove] motivo=… subfilter=… firme_prima=… firme_dopo=…`; un file troppo grande fermato prima della lettura ha 0 firme.
- Non toccati: i file in `documentazione/mappa` e `strumenti/mappa`; il pulsante «Protocolla in Titulus» resta a disposizione anche con un avviso,
  perché lo preme l'ufficio (il protocollo automatico, che con un avviso non deve partire, arriva con il passo 5).

### Prove aggiornate e perché

- `TutoratoUnitTest::testControlloDelPdfFirmato`: due firme in più ora sono ammesse (A2).
- `FirmaDirettoreTest` e `ConvenzioniTesiTest`: il PDF riscritto non è più rifiutato al Direttore, passa con l'avviso (A3, D3).
- `ConvenzioneFirmataTest`: «modificato dopo la firma» e «non integra» sono avvisi; tra i rifiuti ora ci sono il .p7m e la sola marca.

### Prove lanciate (24/11/2026, MariaDB accesa)

| Cartella | Prove | Ok | Rosse | Saltate |
|---|---|---|---|---|
| `tests/Unit/Infrastructure` | 31 | 31 | 0 | 0 |
| `tests/Unit/Tutorato` | 11 | 11 | 0 | 0 |
| `tests/Integration/Fsl` | 64 | 64 | 0 | 0 |
| `tests/Integration/ConvenzioniTesi` | 15 | 15 | 0 | 0 |
| `tests/Integration/Didattica` | 110 | 110 | 0 | 0 |
| `tests/Integration/Tutorato` | 57 | 57 | 0 | 0 |
| **Totale** | **288** | **288** | **0** | **0** |

`strumenti/verifica_firma_pdf.php` provato a mano con PDF costruiti apposta (firma della scuola, file riscritto, regole severe, sola marca).
Sullo stesso PDF (SubFilter → Contents → ByteRange) il codice di prima leggeva un formato vuoto: A1 confermata.

### Cosa resta

- Prova con il **file vero del Direttore** (o il messaggio esatto): le firme delle prove sono costruite apposta, senza certificato.
- Le due pagine del pannello hanno passato il controllo di sintassi ma non sono state aperte nel browser; la migrazione 136 gira sulle tabelle
  di prova, non su una copia del database vero.
- `esegui.php`, `apache_nucleo.sh` e la suite intera non lanciati (non chiesti in questo passo): prima del commit.
- Fuori da questo passo: il ricarico finale dell'ufficio (`ServizioConvenzioneFirmata::completa`) e il registro convenzioni
  (`ServizioRegistroConvenzioni`) accettano ancora un PDF con una sola marca temporale.

## Correzioni al passo 1 (24/11/2026, non committato)

| # | File | Cosa cambia |
|---|---|---|
| C1 | `src/Infrastructure/Pdf/FirmePades.php` | Tetto fisso al lavoro per file: 100 `/ByteRange`, 2 MB tra «obj» e `/ByteRange`, 100 livelli di annidamento, un budget unico per byte e passaggi. Oltre un limite ritorna `[]` (mai un controllo a metà) con una riga nel log |
| C2 | `src/Tutorato/VerificaPdfFirmato.php`, `MessaggiFirma::taglia()`, i due repository delle convenzioni | Prima il certificato di un'altra persona, poi l'integrità, poi il resto; il taglio a 500 caratteri non spezza le parole |
| C3 | `ServizioConvenzioneFirmata`, `ServizioRegistroConvenzioni` | Una marca temporale da sola non vale come firma nemmeno alla registrazione finale e nel registro |

- **Budget unico** invece di soli byte: una parola lunga o tante parentesi costano più di uno `/Contents` della stessa lunghezza. Con i PDF ostili
  provati a parte il tempo massimo è 0,14 secondi.
- I `/ByteRange` si contano **tutti**, anche quelli senza i quattro numeri. Se l'«obj» è a più di 2 MB il file non si accetta.
- Passano: 20 firme (una con `/Contents` da 1,2 milioni di caratteri) e 20 firme da 1 MB l'una.
- Il nome nel certificato si accorcia a 100 caratteri, così un nome lunghissimo non mangia l'avviso.

## Passo 2 — Allegato A e docenti alla registrazione (24/11/2026, non committato)

### File cambiati

| File | Cosa cambia |
|---|---|
| `src/Fsl/DocentiConvenzione.php` (nuovo) | Righe del modulo, docenti proposti (tutor + prenotazioni), elenco senza doppioni |
| `src/Fsl/ServizioConvenzioneFirmata.php` | `completa(…, $allegato)` e `docenti`; `erroreFile()` (tutti i controlli sui file); `sostituisciAllegato()`; `protocolloIngresso(…, $allegato)`; `leggiFacoltativo()` al posto di `contenutoDaUpload()` |
| `src/Fsl/ProtocollaDefinitivaFslInTitulus.php`, `ProtocollaConvenzioneInTitulus.php` | Allegato A dell'ufficio; controlli sui file prima di chiedere il numero |
| `src/Protocollo/InvioProtocollo.php` | `giaAssegnato()` (era dentro `numero()`) |
| `src/Fsl/ServizioConvenzioni.php` | `mancano()`: che cosa manca a una convenzione del registro |
| `src/Fsl/ConvenzioneCompilataRepository.php`, `PrenotazioneFslRepository.php`, `ServizioRegistroConvenzioni.php` | Sostituzione dell'Allegato A; docenti delle prenotazioni; righe docente lette da `DocentiConvenzione` |
| `admin/fsl.php` | Campi Allegato A e docenti, «Completa», un solo blocco docenti per registro e iter |
| `ConvenzioneFirmataTest`, `FirmePadesTest`, `protocolla_definitivo_fsl.php`, `protocolla_convenzione.php` | Prove nuove |

### Scelte fatte

- **Numero a mano** (`completa`): l'Allegato A nuovo va nel registro, quello della scuola resta con la compilazione.
- **Titulus**: l'Allegato A nuovo prende il posto di quello della scuola **prima** di chiedere il numero (il vecchio si cancella dopo che il nuovo
  è salvato). Se poi la registrazione fallisce, il secondo tentativo usa il file già partito. Se Titulus dà errore il nuovo file resta.
- **Numero già dato**: con un Allegato A diverso da quello partito ci si ferma con un messaggio; senza file (o con lo stesso) si riusa il numero.
- **File arrivati male**: prima un file troppo grande per PHP o interrotto era ignorato; ora è un errore.
- **Allegato A della scuola illeggibile**: ci si ferma e si chiede di ricaricarlo; prima partiva senza, in silenzio.
- **Copia stampigliata**: se non passa gli stessi controlli si registra il PDF del Direttore (niente errori dopo il numero).
- **Docenti**: nelle proposte basta la stessa email **o** lo stesso nome senza titoli (anche con le parole invertite). Tra quelli scritti
  dall'ufficio due omonimi con email diverse, o due nomi diversi con la stessa email, restano due: non si perde nessuno.
- **«Completa»** sta nelle registrate dell'iter, nel registro e nella scheda della scuola.

### Prove lanciate (24/11/2026, MariaDB accesa)

| Cartella o prova | Prove | Ok | Rosse | Saltate |
|---|---|---|---|---|
| `tests/Unit/Infrastructure` | 35 | 35 | 0 | 0 |
| `tests/Unit/Tutorato` | 11 | 11 | 0 | 0 |
| `tests/Integration/Fsl` | 73 | 73 | 0 | 0 |
| `tests/Integration/ConvenzioniTesi` | 15 | 15 | 0 | 0 |
| `tests/Integration/Didattica` | 110 | 110 | 0 | 0 |
| `tests/Integration/Tutorato` | 57 | 57 | 0 | 0 |
| `tests/Integration/Protocollo` (in più) | 3 | 3 | 0 | 0 |
| `protocolla_convenzione.php` + `protocolla_definitivo_fsl.php` | 48 | 48 | 0 | 0 |

Le due prove oop sono state lanciate da sole con la preparazione di `esegui.php`. `protocolla_definitivo_fsl.php` lasciava un file in
`uploads/convenzioni` a ogni corsa: ora lo toglie.

Nel portale locale, con una convenzione per fase (caricata, dal Direttore, firmata, registrata): moduli, riquadri «Da controllare», aggiungi e
togli docente, caricamenti veri (Allegato A non PDF, buono, marca temporale alla registrazione e nel registro), «Completa» che apre la modifica;
nessun errore nella console. I dati di prova sono stati tolti. La migrazione 136 è girata su `eventi_locale`, una versione più vecchia del database.

### Cosa resta

- Titulus vero non provato: nelle prove è finto, nel portale locale la chiamata si ferma alla password.
- Il codice della scuola che manca dall'anagrafe, se ben formato, si scopre ancora dopo il numero (non è un controllo sui file): il numero si riusa.
- Con due file da 10 MB nello stesso modulo serve `post_max_size` di almeno 25M sul server: da controllare.
- Un errore nel modulo finale fa riscrivere protocollo e docenti (la pagina si ricarica, come prima).
- Prova con il **file vero del Direttore**, `esegui.php`, `apache_nucleo.sh` e suite intera: prima del commit.

## Passo 3 — Leggere i file da Titulus (24/11/2026, non committato)

Solo lettura: nessuna scrittura in Titulus né nel database (a parte l'indirizzo del documento, vedi sotto). Titulus vero **non provato**: la prova vera è lo strumento da lanciare in pre-produzione (vedi «Cosa resta»).

### File cambiati o creati

- `protocollo/php-titulus-utility/src/LetturaFileTitulus.php` (nuovo): legge l'XML di `loadDocument` senza rete né entità esterne (`LIBXML_NONET`, nessun DOCTYPE/ENTITY, tetti: 5 MB di XML, 50 livelli, 200 file) e restituisce `{titolo, fileId}` della versione più annidata di `xw:file`; riconosce il contenuto di `getAttachment` binario o base64.
- `ClienteTitulus` (interfaccia): `fileDelDocumento(string $id): array` e `scaricaFile(string $fileId): string`. `ClienteSoapTitulus`: `loadDocument` con `lock=false` e `getAttachment`; vuoto = errore; `WSDL_CACHE_NONE` intatto; `prova()` **non** richiede queste due operazioni.
- `ServizioTitulus`: `fileDelDocumento()` e `scaricaFile()` non lanciano eccezioni e danno il motivo in italiano (permesso negato, documento inesistente, risposta inattesa).
- Pacchetto: README (sezione «Leggere i file») e `tests/esegui.php` (cliente finto esteso, 100 prove).
- `src/Protocollo/ServizioProtocollo.php`: `scaricaFile(rifTipo, rifId)`, `scaricaFileDelloInvio(id)`, `scaricaFilePerNumero(numero, ?titolo)`, `indirizzoDelloInvio(id)`, `indirizzoSicuro(url)`. Con `nonPronto()` e `vietatoInProva()`, tetto 30 MB, solo `%PDF-`.
- `ProtocolloRepository` (`salvaUrl`, `registraInvio` con `file_principale`), `RegistroInviiPortale`, `ServizioProtocollo::riga()`.
- Schema **v137**: colonna `titulus_invii.file_principale` (`Definizioni`, `Migrazioni`).
- `admin/protocollo.php`: «Apri in Titulus» e «Scarica da Titulus» (POST + CSRF, audit log, stesso permesso della pagina).
- `strumenti/prova_file_titulus.php` e `strumenti/crea_prova_titulus.sh` (nuovi).
- Clienti finti aggiornati: `strumenti/prove/oop/protocollo.php` e i sette `protocolla_*.php`; `TitulusInProvaTest` (tabella a mano con la colonna nuova).
- Prove nuove: `tests/Integration/Protocollo/FileDaTitulusTest.php` (44), `ProvaFileTitulusToolTest.php` (15), `tests/Support/titulus_finto/router.php` (finto servizio SOAP rpc/encoded, auth Basic, 10 documenti di prova).

### Scelte fatte

- **Il file principale non si indovina.** Con più file si sceglie quello con il titolo che il portale ha inviato (colonna `file_principale`, scritta dal registro degli invii): coincidenza esatta senza badare alle maiuscole, e unica; poi lo stesso titolo senza estensione, se unico; altrimenti **errore**, mai «il primo». Un invio vecchio, senza titolo registrato, con un solo file funziona; con più file dà errore (da riga di comando `--numero` accetta il titolo).
- **L'indirizzo per «Apri in Titulus»** si usa solo se è https, senza utente nell'indirizzo, senza spazi o caratteri strani, e con host uguale a quello configurato o che finisce per `.cineca.it` (il confronto è sull'host: `cineca.it.esempio.com` e `esempio.com/x.cineca.it` sono rifiutati). Se manca, si chiede a Titulus (`getDocumentURL`) e si salva nella riga.
- **Copia di prova**: con l'indirizzo di produzione, lettura e indirizzo sono vietati (`Ambiente::titulusVietato()`).
- **Il download** parte come allegato (`attachment`, `nosniff`, `no-store`), con nome `Titulus_<numero>.pdf`.
- **Lo strumento** (`strumenti/prova_file_titulus.php`) è solo da riga di comando e carica il connettore dalla **propria cartella** prima di quello del portale, così gira anche su un portale vecchio. Con `--sito` legge `.env` e il database in sola lettura (`SET SESSION TRANSACTION READ ONLY`). Rifiuta gli indirizzi non di pre-produzione salvo `--produzione`; la password si chiede senza eco (su Windows serve `TITULUS_PASSWORD`); non stampa mai password, XML intero o nomi dei firmatari; `--salva` solo fuori dalla cartella web, in una cartella 0700, file 0600, senza sovrascrivere. Esce con 0, 1 o 2.
- **Pacchetto**: `bash strumenti/crea_prova_titulus.sh` crea `prova_file_titulus.tgz` (22 file, 34 KB, con `SHA256SUMS.txt` e `LEGGIMI.txt`); non è in git.
- Il pulsante nel registro si chiama «Scarica da Titulus» (il nome completo sta nel suggerimento): con la scritta lunga a telefono andava a capo su quattro righe.

### Prove lanciate (24/11/2026, MariaDB accesa)

| Cartella o prova | Prove | Ok | Rosse | Saltate |
|---|---|---|---|---|
| `protocollo/php-titulus-utility/tests/esegui.php` | 100 | 100 | 0 | 0 |
| `tests/Unit/Protocollo` | 3 | 3 | 0 | 0 |
| `tests/Integration/Protocollo` | 62 | 62 | 0 | 0 |
| `tests/Integration/Core` | 7 | 7 | 0 | 0 |
| `esegui.php` con `PROVE_SOLO=protocollo,protocolla_*` | 788 | 788 | 0 | 0 |

Casi coperti: versioni annidate, più file, nessun file, base64, non PDF, oltre 30 MB, permesso negato, copia di prova con indirizzo di produzione, host falso «cineca.it.esempio.com», utente non autorizzato. Lo strumento è provato contro il finto servizio locale, da una copia estratta come nel pacchetto e con un portale «avvelenato» (prova che usa il suo connettore).

Nel portale locale (con un secondo server con l'estensione `soap`): migrazione 137 applicata, tre invii di prova, «Scarica» dà il PDF come allegato (`%PDF-`), «Permesso negato» e «più file senza principale» danno il messaggio giusto, «Apri in Titulus» porta all'indirizzo `.pp.cineca.it` (l'unico errore in console è della mia prova con `fetch`, bloccata dalla CSP, non della pagina). Dati di prova e `.env.locale` rimessi com'erano. Nota: il marcatore `cache/schema_v137.ok` era già presente senza che il database locale avesse la colonna; l'ho tolto e la migrazione è ripartita.

### Cosa resta

- **Titulus vero non provato.** Lo strumento in pre-produzione è la fermata obbligatoria prima di fidarsi: serve a sapere se l'utente può leggere i file (`loadDocument`, `getAttachment`), se il PDF coincide con quello inviato o è stato riscritto, e se un documento protocollato a mano si legge dal numero. Comandi nel messaggio finale del passo.
- Un invio **antecedente alla v137** con più file in Titulus non ha il titolo del principale: errore finché non si rinvia o si usa lo strumento.
- Suite intera, `esegui.php` completo e `apache_nucleo.sh`: prima del commit (qui solo le prove mirate).

## Correzioni al passo 3 (24/11/2026, non committato)

### File cambiati

- `protocollo/php-titulus-utility/src/LetturaFileTitulus.php`: `elenco` restituisce anche `principale` (l'attributo `principale="true"` dell'XML di Titulus) e `sha256` (il `DigestValue`).
- `src/Protocollo/ServizioProtocollo.php`: la scelta del file ha un ordine fisso e **non pesca mai a caso**.
- `strumenti/prova_file_titulus.php`: stampa «impronta di Titulus: coincide / diversa / assente».
- Clienti finti, `README` e `tests/esegui.php` del pacchetto, `tests/Support/titulus_finto/router.php`, `strumenti/prove/oop/protocollo.php`, `tests/Integration/Protocollo/FileDaTitulusTest.php`.

### Scelte fatte

- **T1, file principale.** Se Titulus segna come principale **un solo** file, è quello (anche se il titolo inviato non coincide). Se ne segna più d'uno, o nessuno, vale il titolo inviato con le regole di prima; se nemmeno il titolo lo individua, errore con il motivo. Mai «il primo».
- **T2, impronta.** Se Titulus dichiara l'impronta SHA-256 e non coincide con quella del contenuto scaricato: errore e **nessun file**. Se l'impronta non c'è si va avanti come prima (il maiuscolo/minuscolo non conta).

### Prove

Nuove: file con `principale=true` anche a titolo diverso, due principali con titolo non riconosciuto = errore, due principali con titolo valido, impronta uguale / in maiuscolo / diversa / assente. Esito nelle tabelle del passo 4.

## Passo 4 — Una compilazione per scuola, convenzione già valida, unione delle doppie, riferimento di protocollo (24/11/2026, non committato)

Schema **v138**. Titulus vero non toccato.

### Il modello

- **Una scuola è un istituto.** Il metodo unico è `ServizioScuole::codiceIstituto()` (più `sqlIstituto($alias, $colonna)` per le query, senza interrogare riga per riga). Una convenzione registrata su una sede copre tutto l'istituto, e viceversa. Il codice di una sede in un vecchio indirizzo funziona ancora (la scheda scuola lo porta all'istituto). Una scuola **senza** codice meccanografico resta una compilazione per prenotazione.
- **Una sola compilazione «in corso» per istituto** (`stato_iter` diverso da `protocollata` e `unita`). Il codice di una prenotazione la riprende e aggiunge l'attività a `dati_json['attivita']` (senza doppioni, ogni voce porta `il` = quando è stata aggiunta). Cerca e crea sotto `GET_LOCK('convenzione_istituto_<codice>')`: due richieste insieme non fanno due compilazioni né si perdono attività.
- **Chi «è» la compilazione**: l'email della compilazione, quella del modulo, quella della prima prenotazione e quelle delle compilazioni unite a lei (`ServizioConvenzioneOnline::proprietari()`).

### Sicurezza (richiesta, con prove)

- Un codice di prenotazione di **un'altra email non apre mai** la compilazione: niente dati del Dirigente, file, messaggi né token a schermo, negli indirizzi o nelle email a chi ha prenotato dopo. A schermo solo «per la tua scuola c'è già una convenzione in corso: l'attività è stata segnalata», identica per qualsiasi non proprietario (non dice chi ha compilato).
- L'attività resta **«da confermare»** (colonna `attivita_in_attesa`), fuori dai documenti, finché chi ha il link o l'ufficio la conferma o la scarta. L'avviso con il link va **solo** all'email della compilazione esistente, una volta sola per attività, senza nome né email di chi ha prenotato.
- `creaDaProgramma` con un'altra email non sovrascrive scuola, logo né email (`accogliProgramma`). Con la stessa email aggiunge le attività e riempie solo i campi mancanti.
- L'Area personale (`dellePrenotazioni`) non mostra compilazioni di altre email. `DocentiConvenzione::proposti()` usa solo le prenotazioni dei proprietari.

### Convenzione già valida

- Se il registro ha una convenzione che copre il periodo delle attività, `convenzione_online.php` mostra numero, protocollo e validità e propone solo **«Aggiorna l'Allegato A»**: compila, scarica, carica il PDF.
- L'Allegato A aggiornato va nel registro come **integrazione**: tabella `convenzioni_allegati` (`stato` da_vedere / accettato / rifiutato, nota, protocollo, chi e quando). Non blocca le prenotazioni e **non sostituisce mai** l'Allegato A registrato. L'ufficio accetta o rifiuta dalla scheda scuola (il rifiuto vuole un motivo, l'esito va per email a chi ha caricato). Eliminando la convenzione dal registro si cancellano righe e file.
- Se la convenzione non copre il periodo: **un solo rinnovo** (`rinnova()`), precompilato dalla precedente (dati e copia del logo) solo per chi ne ha l'email o il link; un'altra email parte da zero.

### Unione delle doppie

- «Unisci» nella scheda scuola (admin, POST, CSRF, conferma, operazione nel registro): l'altra compilazione diventa `unita` (`unita_in`), il suo vecchio link porta a una pagina che lo dice **senza mostrare** il token dell'altra, e il link giusto va per email all'indirizzo di chi l'aveva fatta. Solo stesso istituto; non se già protocollata, già unita o dal Direttore. Niente si cancella (attività, messaggi, «da confermare» passano a quella che resta).
- `strumenti/elenco_convenzioni_doppie.php`: elenco in sola lettura (uscita 0 = nessuna doppia, 1 = ce ne sono, 2 = errore).
- Elenchi e conteggi: `recenti()`, `tutteInCorso()`, `ultimaDellaPrenotazione`, `cheContiene`, `perPrenotazioni`, `perEmail` escludono `unita`; `CoseDaFare`, `DaFarePersona`, `FirmeConvenzioniFsl`, il Direttore e `admin/fsl.php` usano stati scritti per nome, quindi `unita` non finisce tra le cose da fare (verificato con una ricerca di tutti gli usi di `stato_iter`).

### A7 — riferimento di protocollo legato al file

- `InvioProtocollo::rifPerFile($base, $contenuto)` = base + `~` + prime 10 cifre dell'SHA-256 del file che parte (sta nei 40 caratteri di `rif_tipo`). Le quattro classi `Protocolla*InTitulus` (FSL ingresso e definitivo, tesi ingresso e definitivo) lo usano.
- Stesso file = stesso riferimento = **mai due protocolli**. File ricaricato dopo un rifiuto = riferimento nuovo = numero nuovo: non si riusa mai il numero di un altro file.
- I numeri dati **prima** (riferimento senza impronta) restano riconosciuti solo se risultano dati **dopo** il caricamento di quel file (`giaAssegnato($rif, $id, $baseVecchio, $fileDal)`, con `firmata_il` o `firmata_direttore_il`). Se il file è stato ricaricato dopo il numero, il numero era di un altro file: se ne chiede uno nuovo.
- Il registro cerca con `rif_tipo LIKE 'BASE~%'`: le prove `protocolla_*` sono state adattate.

### File cambiati o creati

- Schema: `src/Core/Schema/Definizioni.php`, `Migrazioni.php` (tabella `convenzioni_allegati`, colonne `unita_in` e `attivita_in_attesa`, versione 138). `src/Fsl/Costanti.php` (`ITER_UNITA`).
- Istituto: `src/Anagrafi/ServizioScuole.php`, `ScuolaRepository.php`; `src/Fsl/ConvenzioneRepository.php`, `PrenotazioneFslRepository.php`, `ConvenzioneCompilataRepository.php`, `ServizioConvenzioniScuole.php`, `ServizioDocumentiScuola.php`, `ServizioSchedaScuola.php`, `PrecompilazioneConvenzione.php`, `DocentiConvenzione.php`, `MessaggiConvenzioneRepository.php` (`sposta`).
- Compilazione unica e sicurezza: `src/Fsl/ServizioConvenzioneOnline.php`, `ServizioProgrammaFsl.php`, `EsitoProgramma.php`, `programma_fsl.php`, `convenzione_online.php`.
- Integrazioni e unione: `src/Fsl/AllegatoConvenzioneRepository.php`, `ServizioAllegatiConvenzione.php`, `ServizioUnioneConvenzioni.php` (nuovi); `ServizioRegistroConvenzioni.php`; `src/Fsl/Vista/SchedaScuola.php`; `admin/fsl.php`; `admin/convenzione_file.php` (`?a=ID`); `src/Email/CatalogoConvenzioni.php` (tre modelli: `fsl_attivita_segnalata`, `fsl_allegato_esito`, `fsl_compilazione_unita`); `strumenti/elenco_convenzioni_doppie.php`.
- A7: `src/Protocollo/InvioProtocollo.php`, `ProtocolloRepository.php`, `RegistroInviiPortale.php` e le quattro `Protocolla*InTitulus`.
- Prove: `tests/Integration/Fsl/CompilazioneUnicaTest.php` (16, nuovo), `tests/Support/richiesta_apri_codice.php` (processo figlio per le richieste simultanee), `FslBase` (tabella `convenzioni_allegati`); adattate `DocumentiScuolaTest`, `ConvenzioneFirmataTest`, `DocumentiEValutazioniTest`, le prove `programma_fsl.php`, `protocolla_convenzione.php`, `protocolla_definitivo_fsl.php`, `protocolla_definitivo_tesi.php`, `protocolla_tesi.php`.

### Prove adattate e perché

- `DocumentiScuolaTest`: i codici scuola ora sono quelli dell'istituto (CSIS00001A, non la sede).
- `ConvenzioneFirmataTest`: «Altro Docente» (altra email) non è più tra i docenti proposti, perché la sua attività non è confermata.
- `DocumentiEValutazioniTest`: ogni voce ha il campo `il`.
- `programma_fsl.php`: il modulo vero invia anche le attività deselezionate, la prova ora fa lo stesso (le attività assenti dal modulo si mantengono, per non perdere quelle aggiunte nel frattempo).
- `protocolla_*`: registro cercato per base del riferimento e numeri vecchi con data; il finto Titulus dà numeri crescenti.

### Prove lanciate (24/11/2026, MariaDB accesa)

| Cartella o prova | Prove | Ok | Rosse | Saltate |
|---|---|---|---|---|
| `tests/Integration/Fsl` (con le 16 nuove) | 89 | 89 | 0 | 0 |
| `tests/Integration/Protocollo` | 66 | 66 | 0 | 0 |
| `tests/Integration/ConvenzioniTesi` | 15 | 15 | 0 | 0 |
| `tests/Integration/Core` | 7 | 7 | 0 | 0 |
| `tests/Unit/Protocollo` | 3 | 3 | 0 | 0 |
| `protocollo/php-titulus-utility/tests/esegui.php` | 109 | 109 | 0 | 0 |
| `esegui.php` con `PROVE_SOLO=protocollo,protocolla_*` | 800 | 800 | 0 | 0 |
| `esegui.php` con `PROVE_SOLO=programma_fsl,fsl,protocolla_*` e prove di pagina via HTTP | 837 | 837 | 0 | 0 |
| `esegui.php` con `PROVE_SOLO=pagine_programma_fsl,pagine_fsl` | 725 | 725 | 0 | 0 |

Durante il lavoro: 7 prove `protocolla_*` rosse per il registro cercato con il vecchio riferimento (attese, corrette) e 1 su `programma_fsl` (il modulo con una sola attività, vedi sopra).

Casi coperti nelle 16 prove nuove: due prenotazioni stessa scuola e stessa email = una compilazione (anche sede + istituto, con e senza dati); altra email = «da confermare», nessun dato in giro, un solo avviso solo alla compilazione esistente; conferma e scarto; il modulo non perde attività aggiunte nel frattempo; `creaDaProgramma` con un'altra email e con la stessa; scuola senza codice; convenzione valida = solo Allegato A (carica, rifiuto con motivo, accetta, secondo esame, eliminazione che cancella righe e file); rinnovo da link e da codice (altra email = pagina pulita); unione e i casi vietati (istituto diverso, protocollata, da firmare, già unita, stessa compilazione); «attività aggiunte dopo la firma» con il documento firmato intatto; convenzione su una sede che copre l'istituto; **due richieste simultanee con due processi PHP veri** (una sola compilazione, stesso token) e prova che il blocco è davvero tenuto durante la scrittura. Per A7: numero dato prima dell'impronta e riconosciuto, rifiuto e ricarico = numero nuovo, secondo tentativo sullo stesso file = stesso numero, numero vecchio non riusato per un file ricaricato dopo.

### Nel browser locale (24/11/2026, schema 138 applicato al database locale)

- **Scuola nuova**: il codice apre la compilazione con il link personale; modulo e attività prenotata.
- **Già in corso da un'altra email**: a chi ha il codice solo «Convenzione già in corso… l'attività è stata segnalata»; l'email è andata solo all'indirizzo di Anna, senza nome né email di Carlo; con il link di Anna compare «Attività da confermare» con Conferma e Scarta.
- **Convenzione valida**: banner con numero, protocollo e validità, solo «Scarica l'Allegato A» e «Carica l'Allegato A»; bottone «Aggiorna l'Allegato A».
- **Scheda scuola con «Unisci»**: avviso «2 compilazioni in corso», modulo con conferma; unione eseguita (stato `unita`, `unita_in`, email con il link giusto, riga nel registro operazioni); il vecchio link porta alla pagina «Compilazione unita a un'altra» senza dati né link. Trovato e corretto in questa prova: le due tendine partivano dalla stessa compilazione.
- Dati di prova tolti dal database locale a fine prova.

### Cosa resta

- **Non provato con Titulus vero**: il riferimento con l'impronta è provato con il finto Titulus. In pre-produzione va provato un rifiuto e ricarico.
- **Compilazioni doppie già in produzione**: lanciare `php strumenti/elenco_convenzioni_doppie.php` sul server e unirle dalla scheda scuola. Dopo l'unione, l'avviso va all'indirizzo della compilazione assorbita.
- **Numeri dati con il vecchio riferimento** per un file che nel frattempo non è stato ricaricato ma ha `firmata_il` mancante: non si riconoscono (serve la data del file). Nei dati reali `firmata_il` c'è sempre quando c'è il file.
- **Allegati aggiornati da vedere** non sono nel conteggio di `CoseDaFare`: l'ufficio li vede come avviso nella scheda scuola. Se si vuole il conteggio nel cruscotto è una riga.
- **Doppia ricompilazione dopo la firma**: le attività aggiunte dopo la firma sono segnalate all'ufficio, ma non c'è ancora un passo che chieda alla scuola un nuovo Allegato A firmato (si usa «Aggiorna l'Allegato A» quando c'è una convenzione valida).
- Suite intera, `esegui.php` completo e `apache_nucleo.sh`: prima del commit (qui solo le cartelle indicate e le prove mirate).

## Correzioni al passo 4 (24/11/2026, non committato)

Schema **v139**. Su main, senza branch né worktree, senza commit. Titulus vero non toccato.

### File cambiati o creati

- Schema: `src/Core/Schema/Definizioni.php`, `Migrazioni.php` (v139: su `convenzioni_compilate` le colonne `proprietari_json`, `nascita_ok`, `avviso_il`).
- S5: `src/Anagrafi/ServizioScuole.php` (`sqlIstituto`).
- Identità e proprietari (S1, S2): `src/Auth/UtenteRepository.php` (`emailVerificata`), `src/Fsl/ConvenzioneCompilataRepository.php`, `ServizioConvenzioneOnline.php`, `ServizioReferenteConvenzione.php` (nuovo), `DocentiConvenzione.php`, `ServizioSchedaScuola.php`, `Vista/SchedaScuola.php`, `src/Email/CatalogoConvenzioni.php` (modelli `fsl_non_riconosce` e `fsl_nuovo_referente`), `src/Portale/DaFarePersona.php` («Cosa devo fare» non dà più il link con un'email non verificata).
- Programma FSL: `src/Fsl/RichiestaProgramma.php`, `EsitoProgramma.php`, `ServizioProgrammaFsl.php`, `ServizioPercorsiScuola.php`.
- Blocco (S3) e unioni (S4): `src/Fsl/BloccoOccupato.php` (nuovo), `ServizioUnioneConvenzioni.php`, `ServizioConvenzioneFirmata.php`, `ServizioMessaggiConvenzione.php`.
- Pagine: `convenzione_online.php`, `programma_fsl.php`, `miei_eventi.php`, `inc/area/eventi.php`, `inc/area/fsl_scuola.php`, `admin/fsl.php`, `ricevi_link_convenzione.php` (solo il testo).
- Prove: `tests/Integration/Fsl/CompilazioneSicurezzaTest.php` (26 nuove), `tests/Support/csrf_finto.php` (nuovo), `tests/Support/richiesta_apri_codice.php` (quinto argomento: l'email verificata); adattate le prove elencate più sotto.

### Scelte fatte

**S1 — l'email scritta in una prenotazione non prova chi è.**
- Il token di una compilazione che esiste già si mostra (reindirizzamento, sessione, pagina, email a chi prenota, Area personale, «Cosa devo fare») solo se **o** la prenotazione è quella da cui è nata (`cc.prenotazione_id`, finché `nascita_ok` = 1) **o** chi chiede ha fatto l'accesso con un'email **verificata** tra i proprietari. «Verificata» è il criterio che il portale già usa (`ControlloEmailPersonalizzate`): email arrivata dal login di Ateneo/SPID/CIE (`email_personalizzata` = 0) oppure confermata con il codice (`email_verificata_il`). `UtenteRepository::emailVerificata(?array $utente): ?string` lo calcola; i servizi ricevono l'email verificata come parametro (null = nessuna), niente globali.
- In ogni altro caso la risposta a schermo è **identica** per email uguale e diversa («Per la tua scuola c'è già una convenzione in corso: abbiamo scritto a chi la sta compilando. Se sei tu, trovi il link nella tua email.»), la stessa pagina, nessun reindirizzamento; l'attività resta «da confermare»; l'email con il link va solo all'indirizzo della compilazione, al massimo una ogni 10 minuti per compilazione (`avviso_il`; le attività segnalate nel frattempo si raccolgono nell'avviso successivo). Oltre al limite per IP già esistente (120/ora) sul ramo `?code=` c'è un limite più stretto (40/ora).
- Il rinnovo precompilato (`copiaDaPrecedente`) parte solo dal link della compilazione conclusa o con un proprietario verificato; altrimenti la compilazione è vuota.
- Una compilazione già nel browser di chi la tiene (link `?t=`) funziona come prima.
- Dal programma FSL: stessa regola (`RichiestaProgramma::$emailVerificata`, calcolata da `programma_fsl.php` dall'account connesso). Se la convenzione è già in corso e chi prenota non è proprietario: prenotazioni valide, attività «da confermare», nessun token né dati; nel riepilogo per email il link c'è solo se l'indirizzo di chi prenota è quello della compilazione.
- Tutti gli altri punti che danno un token da un'email o da un codice sono stati cercati (`grep convenzione_online.php?t=` e `apriDaCodice/tokenDaCodice`): `miei_eventi.php` e `ServizioPercorsiScuola` (Area personale) ora vogliono l'email verificata o la nascita; `DaFarePersona::convenzioniFsl` («Cosa devo fare») vuole l'email verificata; `ServizioDocumentiScuola` non dà token; `ricevi_link_convenzione.php` rimanda il link all'indirizzo scritto solo se è l'email della compilazione (non cambia); le email con il token (`salva` al primo salvataggio, `ricorda`, `scriviAllaScuola`, ricevute di firma e protocollo, `contatti()`) vanno solo a `cc.email`. L'email scritta nel modulo (`scuola.email`) resta nei dati, serve per le comunicazioni dell'Allegato A.

**S2 — nessuno occupa una scuola.**
- Una compilazione **mai compilata** (`dati_json` vuoto o nullo) non conta come «in corso» per gli altri, non compare tra le doppie né tra le cose da fare nella scheda; chi arriva dopo ne apre una sua.
- Nel riquadro «già in corso»: «Non riconosci questa convenzione? Scrivi all'ufficio» (messaggio e contatto facoltativo). POST con CSRF, campo trappola, domanda anti-robot (rifiuta anche l'invio in meno di 3 secondi), limite per IP (3/ora); avvisa gli amministratori, gli abilitati FSL/convenzioni e l'ufficio fisso con scuola, codice, chi e messaggio, **senza token né dati**.
- Scheda scuola, per ogni compilazione non protocollata né unita: **«Cambia referente»** (`ServizioReferenteConvenzione`, POST con CSRF e conferma, riga nel registro delle operazioni, messaggio di conferma). Nuovo indirizzo, **token nuovo** (il vecchio link non funziona più), avviso al nuovo indirizzo con il link; dopo, la vecchia email, la prenotazione di nascita (`nascita_ok` = 0) e le email unite non aprono più. La lista dei proprietari è salvata (`proprietari_json`) invece di essere ricavata ogni volta; le righe di prima (NULL) si ricavano come prima (email della compilazione, prima prenotazione, email unite, **senza** `scuola.email`). Con l'unione i proprietari dell'assorbita passano a quella che resta.
- L'email scritta nel modulo da sola non rende proprietari: le persone lo diventano dal cambio referente o dall'unione. L'etichetta del campo ora dice «Email di riferimento della scuola (per le comunicazioni)».

**S3 — il blocco per istituto è obbligatorio.**
- `sottoBlocco()`: se `GET_LOCK` non riesce (attesa 5 secondi) non si procede e si lancia `BloccoOccupato` («Riprova tra qualche secondo: il portale sta già lavorando su questa convenzione.»). Nel programma FSL le prenotazioni già registrate restano valide e la pagina dice di riprovare (con il codice, senza dati). Il nome del blocco comprende il database (`cvi_` + 40 caratteri di SHA-256 di `database|chiave`, 44 caratteri in tutto) e tiene separati ambienti che condividono il server.
- Sotto lo stesso blocco, con la riga **riletta**: `salva`, caricamento del file firmato, presa in carico (protocollo in ingresso), rifiuto, «Aggiorna i documenti» (`decidiInAttesa`, `rinnova`), cambio referente e unione. Una compilazione unita o protocollata nel frattempo non si sovrascrive più.

**S4 — l'unione.**
- `unisci()` assorbe solo una compilazione **senza file firmato vivo** (`file_firmato` presente e stato diverso da «rifiutata») e **senza protocollo in ingresso**, in stato vuoto o «rifiutata». Se entrambe hanno il file: «rifiutane una prima, con il motivo»; se solo quella da assorbire: «scegli l'altra come quella che resta».

**S5 — un solo criterio per «stesso istituto».**
- `sqlIstituto()` ora ragiona come `codiceIstituto()`: `istituto_codice` vale solo se di 10 lettere/cifre (anche minuscole o con spazi); altrimenti vale il codice della scuola; un codice che non è di 10 caratteri dà `''`. È una sola espressione con una sottoquery correlata, quindi gli elenchi restano una query sola.

### Prove nuove (26, `CompilazioneSicurezzaTest`) e come cadevano sul codice di prima

Lanciate sul codice com'era all'inizio di questo lavoro (e, dove sotto è detto, con una mutazione temporanea che riporta il vecchio comportamento, poi tolta):

- **Rosse sul codice vecchio, per il comportamento (14)**: prenotazione anonima con la stessa email = nessun token in risposta, intestazione, sessione né email a chi prenota; stessa risposta a schermo per email uguale e diversa; rinnovo precompilato solo per link o email verificata; stessa cosa dal programma FSL; limite di una email ogni 10 minuti; l'email del modulo da sola non rende proprietari; le email con il token vanno solo a `cc.email`; una compilazione vuota non blocca; una vuota non è una cosa da fare nella scheda; blocco occupato = nessuna riga nuova; salvare il modulo di una compilazione unita nel frattempo non la sovrascrive; `codiceIstituto` e `sqlIstituto` uguali per vuoto, NULL, «Non Disponibile», minuscolo, spazi, scuola assente dal registro; le liste usano lo stesso criterio; unione con due file / file solo sull'assorbita.
- **Rosse perché la funzione non esisteva (5)**: `emailVerificata`, `nonRiconosco`, `ServizioReferenteConvenzione` (due prove: cambio referente con token nuovo e vecchie email che non aprono; non su protocollate o unite), `nomeBlocco`.
- **Rosse solo per un errore della prova nella prima corsa, poi corretta, e confermate con una mutazione (2)**: «caricare il file firmato non scavalca una unione» (mutazione: `carica` senza blocco né rilettura → rossa) e «non si assorbe una compilazione con file o protocollo» (mutazione: criterio vecchio sugli stati → rossa).
- **Già verdi sul codice vecchio, restano come guardie (5)**: email verificata proprietaria apre e aggiunge subito; email verificata non proprietaria non apre; la prenotazione di nascita apre anche senza accesso; dal programma con email verificata proprietaria le attività si aggiungono; una rifiutata senza protocollo si assorbe (il suo vecchio file non passa a chi resta).
- In più, nella prova `strumenti/prove/oop/da_fare_convenzione_fsl.php`: «con un'email non verificata il link della convenzione non compare» — rossa con la mutazione che toglie il controllo in `DaFarePersona`.

### Prove adattate e perché

- `CompilazioneUnicaTest`: un anonimo con la stessa email non riceve più il token → le richieste degli altri codici passano l'email verificata (`tokenDaCodice($codice, $email)`); l'esito ha il campo `riprova`; il rinnovo con un'altra email dà una compilazione vuota (non più «segnalata», perché quella di Carlo era vuota: ora si compila prima di occupare la scuola); il nome del blocco è `nomeBlocco`; la gara con processi veri usa l'email verificata (`richiesta_apri_codice.php` ha un quinto argomento).
- `ConvenzioneFirmataTest`, `FirmaDirettoreTest`: le ricevute vanno a `cc.email`, non all'email scritta nel modulo.
- `DocumentiEValutazioniTest`: salvando il modulo `cc.email` non cambia, l'email scritta resta in `dati_json['scuola']['email']`; il link del primo salvataggio va a `cc.email`.
- `SchedaScuolaTest`: la vista stampa il campo CSRF con la funzione globale, nei test non c'è (`tests/Support/csrf_finto.php`).
- `strumenti/prove/oop/fsl.php`, `programma_fsl.php`, `fsl_da_stipulare.php`: stesse ragioni (link a `cc.email`, codice delle prenotazioni successive con l'email verificata, `creaDaProgramma` con l'email verificata).

### Prove lanciate (24/11/2026, MariaDB accesa)

| Cartella o prova | Prove | Ok | Rosse | Saltate |
|---|---|---|---|---|
| `tests/Integration/Fsl` (89 + 26 nuove) | 115 | 115 | 0 | 0 |
| `tests/Integration/Protocollo` | 66 | 66 | 0 | 0 |
| `tests/Integration/ConvenzioniTesi` | 15 | 15 | 0 | 0 |
| `tests/Integration/Core` | 7 | 7 | 0 | 0 |
| `tests/Integration/Portale/DaFareEdEmailTest`, `Didattica/SostituzioniOperatoriTest` | 12 | 12 | 0 | 0 |
| `tests/Unit` | 723 | 722 | 0 | 1 |
| `esegui.php` `PROVE_SOLO=programma_fsl,fsl,fsl_allegato_a` (solo funzioni) | 670 | 670 | 0 | 0 |
| `esegui.php` altre prove delle funzioni FSL (`da_fare_convenzione_fsl`, `fsl_da_stipulare`, `fsl_piu_edizioni`, `fsl_messaggi_convenzione`, `protocolla_convenzione`, `protocolla_definitivo_fsl` e le vicine) | 839 | 839 | 0 | 0 |
| `esegui.php` `da_fare_convenzione_fsl` dopo l'ultima modifica a `DaFarePersona` | 581 | 581 | 0 | 0 |
| `esegui.php` pagine via HTTP (`pagine_programma_fsl`, `pagine_fsl`, `pagine_fsl_documenti`, `pagine_fsl_da_stipulare`, `pagine_fsl_piu_edizioni`, `pagine_fsl_converti`) | 764 | 764 | 0 | 0 |

Durante il lavoro: 17 prove vecchie rosse su `tests/Integration/Fsl` (le adattate qui sopra) e due errori nelle prove nuove (variabile con «»» in una stringa, servizio di firma non registrato): corretti.

### Pagine vere via HTTP (portale locale, schema 139 applicato al database locale)

Con dati di prova poi tolti, tre scuole di prova, due scenari scritti come script (`curl`-like, cookie di sessione, nessun reindirizzamento seguito):

- **S1, anonimo**: il codice di una prenotazione con un'altra email e quello con la **stessa** email di chi compila danno la stessa pagina (200, nessun `Location`, né token né dati del Dirigente), con lo stesso testo e lo stesso modulo «Non riconosci…»; il corpo è identico dopo aver tolto CSRF, domanda anti-robot e il codice nell'indirizzo del modulo; tempi equivalenti a parità di stato (con e senza l'avviso già mandato). Una email sola, solo all'indirizzo di Anna, con il suo link e senza l'email di Carlo; la seconda richiesta entro 10 minuti non manda altro; l'attività resta «da confermare».
- **S1, con accesso**: email **non verificata** uguale a quella di Anna → stessa pagina «già in corso»; dopo aver messo `email_verificata_il` → apre il link di Anna e l'attività entra nell'Allegato A. Il codice della prenotazione di nascita apre.
- **S2**: una compilazione vuota non blocca (la prenotazione di un'altra email apre la sua); «Non riconosci…»: l'invio in meno di 3 secondi è rifiutato dall'anti-robot, dopo 3 secondi con la risposta giusta dà «Messaggio inviato» e le email all'ufficio non hanno token né dati; senza CSRF = 403; con il campo trappola riempito non parte niente. **Cambia referente** dall'admin: nuovo token, nuova email, proprietari esplicito, `nascita_ok` = 0, il vecchio link dà 404, la prenotazione di nascita e la vecchia email (anche verificata) non aprono più, avviso al nuovo indirizzo con il link nuovo, riga nel registro delle operazioni.
- Dati di prova e server locale tolti a fine prova.

### Cosa resta

- **Prima del commit**: suite intera (`tests/Unit` e le altre cartelle non lanciate qui), `esegui.php` completo e `bash strumenti/prove/apache_nucleo.sh`. Qui solo le cartelle e le prove elencate sopra.
- **Conseguenza voluta**: chi prenota anonimo con la stessa email di una compilazione già in corso non riceve più il link a schermo; deve usarlo dall'email già mandata alla compilazione, fare l'accesso con l'email verificata, o chiedere all'ufficio «Cambia referente». Il testo della pagina lo dice.
- **Righe già in produzione**: hanno `proprietari_json` NULL e si ricavano come prima (senza `scuola.email`). Chi oggi era proprietario solo per l'email scritta nel modulo smette di esserlo: l'ufficio può dargli la compilazione con «Cambia referente».
- **Compilazioni doppie già in produzione**: `php strumenti/elenco_convenzioni_doppie.php` e «Unisci» dalla scheda; le compilazioni vuote ora non compaiono più tra le doppie.
- **Attesa del blocco**: 5 secondi (`ATTESA_BLOCCO`); se si vuole più larga è una costante.
- `ConvenzioneCompilataRepository::ultimaDellaPrenotazione` non è più usata: si può togliere con il prossimo ritocco.
- Altri punti del portale danno token per email non legati alla convenzione della scuola (conferme attestati, firme, verbali, tutorato in `DaFarePersona`): non toccati, stesso problema dell'«email non verificata» della roadmap 0.1.
- Titulus vero, `esegui.php` con l'ambiente di pre-produzione e il carico in produzione: non provati qui.

## Ritocchi al passo 4 (R1-R4) (24/11/2026, non committato)

### Cosa è cambiato
- **R1, rivendicazione**: una compilazione non rivendicata (`rivendicata_il` vuoto) non ha proprietari e il link personale non si dà a chi ha solo il codice della prenotazione; la rivendica chi ha un'**email verificata** uguale a quella della compilazione (token nuovo, `rivendica()` sotto il blocco dell'istituto), oppure l'ufficio con «Cambia referente». Dopo il caricamento del file firmato il link non cambia più.
- **R2, «nascita»**: la prenotazione di nascita apre la compilazione solo per l'utente collegato che l'ha fatta o per un'email verificata; il cambio dell'email dal pannello azzera `email_verificata_il` (non si eredita il permesso con un indirizzo non verificato).
- **R3**: lo stesso blocco per le scuole senza codice meccanografico (compilazioni senza `scuola_codice`).
- **R4**: `ServizioUnioneConvenzioni::segnaUnita(): bool`, l'unione delle doppie avviene in una transazione; `ultimaDellaPrenotazione` è stata tolta.

### Prove
- Adattate: `strumenti/prove/oop/programma_fsl.php` (blocco della rivendicazione con `dellePrenotazioni($righe, 0, $verificata)`, token `$tok_cc` perché `EsitoProgramma::$token` è `readonly`) e `da_fare_convenzione_fsl.php` (righe con `rivendicata_il`, e il caso «non rivendicata → `?code=`»).
- `tests/Integration/Fsl`: 131 verdi prima del passo 5; con il passo 5 e le prove nuove, vedi sotto.

## Passo 5 — Iter FSL: controllo, protocollo automatico, mai due protocolli, file originale di Titulus, registrazione (24/11/2026, non committato)

Schema **141**. Titulus: solo il finto (`tests/Doppi/TitulusFintoFsl.php`); nessuna chiamata al Titulus vero.

### File cambiati o creati
- **Schema**: `src/Core/Schema/Definizioni.php` e `Migrazioni.php` (v141). `convenzioni_compilate`: `valida_dal`, `valida_al`, `docenti_json` (le date e i docenti decisi alla presa in carico), `file_originale`, `originale_stato` (`''`/`ok`/`mancante`/`impronta`), `originale_avviso`, `originale_tentativi`, `originale_il`, `file_protocollato` (documento caricato a mano), `protocollo_errore`, `esito_registrazione` (resoconto in JSON). `titulus_config`: `automatico_fsl`, `automatico_tesi` (spenti di partenza).
- **Pacchetto Titulus** (`protocollo/php-titulus-utility/src`): `RispostaPersaTitulus` (nuova), `ClienteSoapTitulus` (dopo `saveDocument`, una risposta persa o illeggibile lancia `RispostaPersaTitulus`, non un errore qualunque), `EsitoProtocollo::$incerto`, `ServizioTitulus` (esito incerto, messaggi che iniziano con «ESITO INCERTO:»).
- **Protocollo**: `ProtocolloRepository` (blocco per documento `GET_LOCK` con attesa 0, righe di prenotazione nel registro, `salvaAutomatici`), `InvioProtocollo` (`stato()`, `aMano()`, `chiudiIncerto()`, `numero()` con prenotazione prima di chiamare Titulus), `Stampigliatura` (coda «copia per consultazione»).
- **FSL**: `ProtocolloAutomaticoConvenzioneFsl` (nuova), `OriginaleDaTitulus` (nuova), `ServizioConvenzioneFirmata` (`completa()` sotto blocco e in transazione, `ripetiApplicazione`, `caricaProtocollato`, `salvaControllo`, `documentoProtocollatoPerScuola`, `rifIngresso`/`rifDefinitivo`), `ProtocollaDefinitivaFslInTitulus` (`completaAMano`, copia stampigliata solo senza file a mano, originale dopo il numero), `ProtocollaConvenzioneInTitulus::protocolloAMano`, `ServizioFirmaDirettore::conDopoFirma`, `Registrazione`, `ServizioConvenzioni` (`applicaConEsito`, `fileConvenzione`), `ConvenzioneCompilataRepository`, `PrenotazioneFslRepository`.
- **Pannello e pagine**: `admin/fsl.php` (fase ② «Controlla la convenzione», fase ④, fase ⑤ con resoconto e file), `admin/protocollo.php` (i due interruttori e le etichette degli stati), `admin/convenzione_file.php` (`f=orig`, `f=prot`), `convenzione_online.php` (striscia dei passi con `ui_passi()` e «Scarica la convenzione protocollata»), `cron_background.php` (riprova degli originali).
- **Prove**: `tests/Integration/Fsl/IterConvenzioneFslTest.php` (17), `tests/Doppi/TitulusFintoFsl.php`, `tests/Support/protocolla_definitivo_fsl.php`.

### Scelte fatte
1. **Controlla la convenzione** (fase ②): una sola scheda con PDF + firma + avviso, Allegato A (anche le attività aggiunte dopo la firma e quelle «da confermare», con Conferma/Scarta), docenti e validità dal/al **salvate** sulla compilazione. Azioni: «Invia al protocollo», «Registra il numero a mano», «Rimanda alla scuola». Dopo il protocollo d'ingresso l'invio al Direttore parte da solo (già così).
2. **Protocollo automatico dopo la firma**: dopo la firma del Direttore (Aruba o file ricaricato) `ServizioFirmaDirettore` chiama un gancio, **dopo** aver salvato e avvisato l'ufficio e dentro un `try/catch`: la firma non può mai fallire per Titulus. `ProtocolloAutomaticoConvenzioneFsl::dopoFirma` parte solo se: interruttore acceso (`admin/protocollo.php`, uno per FSL e uno per tesi, spenti), collegamento e regola pronti, nessun avviso di firma, date salvate. Altrimenti scrive il motivo in `protocollo_errore` e l'ufficio ha «Invia al protocollo» e il numero a mano. L'interruttore delle tesi è solo salvato e mostrato: lo userà il passo 6.
3. **Mai due protocolli**: la prenotazione dell'invio è presa **prima** di chiamare Titulus, per l'automatico, il pulsante e il numero a mano, ingresso e definitivo: blocco `GET_LOCK` (attesa 0) + riga `in_invio` nel registro. Se la risposta si perde (timeout) l'esito è **incerto**: niente riprova, né automatica né col pulsante; l'ufficio cerca in Titulus e o scrive il numero a mano (che chiude l'incertezza) o conferma «non c'è» (`verificato`) e riprova. Una riga `in_invio` lasciata da un processo morto vale incerta dopo 10 minuti. Il riferimento resta `base~impronta del file`: lo stesso file non ha mai due protocolli.
4. **Originale di Titulus**: dopo il numero il portale rilegge il file (sola lettura), controlla l'impronta SHA-256 dichiarata e lo salva come **originale** in `file_originale` (cartella chiusa al web, solo `admin/convenzione_file.php`). Titulus lo restituisce **senza** stampigliatura: `applyRegistrationMark` non si usa. «Documento protocollato» per la scuola e il registro = quello caricato a mano dall'ufficio, altrimenti la copia stampigliata dal portale con «copia per consultazione»; il PDF firmato dal Direttore non si modifica mai (provato byte per byte). Se il file non arriva o l'impronta è diversa si tiene quello del portale e l'ufficio vede l'avviso. «Riprova da Titulus» e «Carica il documento protocollato» restano anche a iter concluso; il cron riprova una volta al giorno per 7 giorni (8 tentativi).
5. **Registrazione**: in transazione e **ripetibile** senza doppioni (blocco dell'istituto, rilettura dello stato; la parte dopo il commit si rifà con «Ripeti l'approvazione»): registro, Allegato A, docenti, prenotazioni coperte approvate, email alla scuola con il link. D2: le prenotazioni che richiedono l'approvazione del gestore **non** si approvano; quelle «da confermare» mai. L'ufficio vede quante sono approvate e quali restano con il motivo (periodo non coperto, approvazione del gestore, scuola scritta a mano, attività non confermata).
6. **Scuola**: `convenzione_online.php` mostra gli stessi cinque passi con le stesse parole del pannello (`ui_passi()`), e a iter concluso il pulsante «Scarica la convenzione protocollata». L'email «convenzione attiva» porta al link della pagina della scuola, dove sta il pulsante.

### Prove nuove (`IterConvenzioneFslTest`, 17, con Titulus finto)
Automatico acceso (protocolla, registra, approva, PDF firmato **identico byte per byte**, originale uguale al file inviato) · automatico spento (nessuna chiamata) · errore di Titulus (firma salvata, motivo in `protocollo_errore`, poi il pulsante riesce) · risposta persa (esito incerto, né automatico né pulsante riprovano, `chiudiIncerto` sblocca) · numero a mano che chiude l'incertezza senza secondo protocollo · avviso sulla firma e date mancanti (niente parte) · file non restituito (iter concluso, avviso, cron che riprova a 1 giorno, si ferma a 8 tentativi, «Riprova» resta) · impronta diversa (file non conservato, copia del portale) · documento protocollato caricato a mano che vince, PDF firmato intatto · documento protocollato negato prima del protocollo · doppio clic (un solo protocollo, una sola convenzione) · blocco preso mentre Titulus lavora, visto da una **seconda connessione** (un altro invio non entra, a fine invio è libero) · **tre processi PHP veri insieme**: Titulus chiamato una volta sola, un solo invio `ok`, una sola convenzione · resoconto D2 (una approvata; richiesta di approvazione del gestore e scuola scritta a mano restano, con il motivo) · ripetizione dell'applicazione senza doppioni né email in più · numero a mano vuoto: non registra niente.

### Prove lanciate (24/11/2026, MariaDB accesa)
- `tests/Integration/Fsl`: **148 verdi** (131 + 17 nuove).
- `tests/Integration/Protocollo`: 66 verdi. `tests/Integration/ConvenzioniTesi`: 15 verdi. `tests/Integration/Portale`: 54 verdi. `tests/Unit`: 723 prove, 0 rosse, 1 saltata.
- Pacchetto Titulus (`protocollo/php-titulus-utility/tests/esegui.php`): 109 riuscite, 0 fallite.
- `strumenti/prove/esegui.php` (comprende `protocolla_convenzione`, `protocolla_definitivo_fsl`, `programma_fsl`, `fsl`, `da_fare_convenzione_fsl` e gli altri `protocolla_*`): **2489 superate, 0 rosse**.
- Una prima passata di `tests/Integration/Protocollo` ha dato 4 rosse **solo** perché due serie di PHPUnit giravano insieme sullo stesso database di prova; rilanciata da sola: 66 verdi. Le serie PHPUnit vanno lanciate una per volta.
- **Saltate**: le prove delle pagine via HTTP di `esegui.php` («ambiente locale spento»); `bash strumenti/prove/apache_nucleo.sh` non lanciato; nessuna schermata nel browser delle pagine nuove (fase ②/⑤ del pannello, `convenzione_online.php`).

### Cosa resta
- **Prima del commit**: avviare `strumenti/locale/avvia.sh` e rilanciare `esegui.php` per le pagine FSL via HTTP; guardare nel browser fase ②, ④, ⑤ di `admin/fsl.php`, la scheda `admin/protocollo.php` e `convenzione_online.php` (i moduli sono scritti ma non visti a schermo); `apache_nucleo.sh`; applicare lo schema 141 al database locale.
- **Rumore nei log dei test**: in `FirmaDirettoreTest`/`ConvenzioneFirmataTest` il gancio della firma scrive una riga in `error_log` perché il contenitore di prova non ha il collegamento a Titulus; è voluto (la firma non fallisce) e non rende rosse le prove.
- **Titulus vero**: mai chiamato. Il protocollo automatico va provato in **pre-produzione** (mydibest_pp) con l'interruttore acceso solo lì, prima di accenderlo in produzione; in produzione vanno verificati i permessi di lettura (`loadDocument`/`getAttachment`).
- **Tesi**: l'interruttore `automatico_tesi` è salvato e mostrato ma non fa niente: lo usa il passo 6. Anche l'invio con prenotazione (blocco, esito incerto) vale oggi per FSL e per i moduli che passano da `InvioProtocollo::numero()`; le tesi lo ereditano già dal numero, ma «a mano» e «ripeti» per le tesi sono del passo 6.
- **Email «convenzione attiva»**: il testo è invariato; il link porta alla pagina della scuola, che ora ha il download. Se si vuole il file allegato all'email è un cambio a parte.
- **Righe vecchie**: le convenzioni registrate prima della v141 non hanno `originale_stato` (la tabella dice «non ripreso»); «Riprova da Titulus» funziona anche per quelle, dal numero di protocollo.
- **Una riga `in_invio` rimasta** per un processo morto diventa «incerta» dopo 10 minuti: l'ufficio la chiude con «numero a mano» o «non c'è in Titulus».

## Correzioni al passo 5 (P1–P6, 24/11/2026, non committate)

Titulus: solo il finto. Le prove nuove sono in `tests/Integration/Fsl/CorrezioniProtocolloTest.php` (**26**, scritte per fallire sul codice di prima).

| | Difetto | Correzione |
|---|---|---|
| **P1** | Un numero a mano con dati sbagliati o vuoto chiudeva comunque l'esito incerto | `chiudiIncerto` si fa **solo** se la registrazione a mano è riuscita (numero e data validi); si rifiuta se un invio è in corso o il documento è già protocollato |
| **P2** | La firma del Direttore poteva scavalcare un rifiuto o un numero a mano dell'ufficio | La firma passa sotto il blocco della convenzione, rilegge la riga e fa l'`UPDATE` **condizionato** allo stato di partenza (restituisce vero/falso: due firme insieme ne registrano una). Se il blocco è occupato la firma **aspetta** (`ATTESA_LUNGA` 45 s, poi un messaggio chiaro al Direttore); il protocollo automatico parte dopo, a blocco libero |
| **P3** | Il blocco dell'invio era per file: due file della stessa compilazione potevano avere due protocolli | Il blocco è **del documento** (`nomeBloccoInvio($baseRif, $id)`, senza l'impronta); lo stato guarda anche i riferimenti vecchi senza impronta; un numero già dato a un file di prima si **mostra** e ferma l'automatico |
| **P4** | Il numero dato a un file poteva finire registrato su un file diverso (sostituito mentre Titulus lavorava) | Alla registrazione si confronta l'impronta del file inviato con quello attuale; se è cambiato il numero resta nel registro ma **non** si registra sul file nuovo. Mentre un invio è in corso la scuola non sostituisce il file e l'ufficio non rifiuta |
| **P5** | Un invio riuscito ma senza numero (iter di approvazione di Titulus) si poteva ripetere | Stato nuovo `in_approvazione`: niente ripetizione; si chiude con il **numero a mano** quando arriva, oppure con «Respinto» (`respinto`), e si può rimandare |
| **P6** | Il numero già dato portava la data di oggi invece di quella dell'invio riuscito | La data è quella dell'invio riuscito (`InvioProtocollo::numero` la restituisce); un invio nuovo porta la data di oggi |

Altre scelte: `precedente` (un numero dato a un file di prima) ferma **solo l'automatico**; il pulsante dell'ufficio manda comunque il file nuovo con un numero nuovo. Lo schema ha un marcatore **per database** (`cache/schema_v142_<8 cifre>.ok`), così un database cambiato non salta le migrazioni.

## Passo 6 — Iter tesi: controllo, protocollo automatico, mai due protocolli, originale di Titulus, registrazione, nessuna convenzione doppia (24/11/2026, non committato)

Schema **142** (`eventi_locale` verificato: colonne e marcatore `schema_v142_a257589f.ok`). Titulus: **solo il finto** (`tests/Doppi/TitulusFintoFsl.php`); nessuna chiamata al Titulus vero.

### File cambiati o creati
- **Schema**: `Definizioni.php`, `Migrazioni.php` (v142). `convenzioni_tesi`: `file_originale`, `originale_stato`, `originale_avviso`, `originale_tentativi`, `originale_il`, `protocollato_origine` (`''`/`mano`/`copia`), `protocollo_errore`, `esito_registrazione`.
- **Dominio** (`src/ConvenzioniTesi`): `Registrazione` (nuova: collega il servizio e il gancio dopo la firma), `OriginaleDaTitulus` (nuova), `ProtocolloAutomaticoConvenzioneTesi` (nuova), `ServizioConvenzioniTesi`, `ConvenzioneTesiRepository` (blocco, transazione, `UPDATE` condizionati), `ProtocollaConvenzioneTesiInTitulus` (`protocolloAMano`), `ProtocollaDefinitivaTesiInTitulus` (`completaAMano`), `NotificheConvenzioniTesi`, `Costanti::PASSI`, `AziendaDellaPratica`, `ServizioConvenzioniAzienda`. Email: `CatalogoConvenzioni` (`tesi_relatore_stipulata`).
- **Altrove**: `Direzione`, `UtenteRepository::emailVerificata`, `DaFarePersona`, `cron_background.php` (riprova degli originali delle tesi).
- **Pannello e pagine**: `admin/convenzioni_tesi.php` (fase ④ «Controlla la convenzione», fase ⑥, riga d'archivio con file di Titulus/originale/documento protocollato/avvisi), `admin/protocollo.php` (etichetta dell'interruttore `automatico_tesi`, ora operativo), `convenzione_tesi.php` (passi con `ui_passi()`).
- **Prove**: `tests/Integration/ConvenzioniTesi/IterConvenzioneTesiTest.php` (26 nuove), `tests/Support/protocolla_definitivo_tesi.php`; adattate `ConvenzioniTesiTest`, `AziendaTesiPraticaTest`, `AuthIntegrazioneTest`.

### Scelte fatte
1. **Controlla la convenzione** (fase ④): una scheda sola con PDF firmato + avviso sulla firma, studenti collegati (nome, codice, email: solo quelli di **questa** pratica), validità dal/al **salvate** sulla convenzione. Azioni: «Invia al protocollo», «Registra il numero a mano», «Rimanda all'azienda» (in `<details>`), poi al Direttore. Se l'azienda ha già una convenzione valida l'ufficio vede un avviso.
2. **Protocollo automatico** (`automatico_tesi`, spento di partenza): dopo la firma del Direttore, **dopo** aver salvato e avvisato, dentro `try/catch`: la firma non fallisce mai per Titulus. Parte solo se interruttore acceso, collegamento pronto, nessun avviso di firma, date salvate, stato `libero`; si ferma su `incerto`, `in_invio`, `in_approvazione`, `precedente`, scrivendo il motivo in `protocollo_errore`. Stesse garanzie di P1–P6 (blocco del documento, prenotazione prima della chiamata, UPDATE condizionati).
3. **Originale di Titulus** in `file_originale` con impronta SHA-256 verificata (diversa → non conservato, avviso). `file_protocollato` resta: caricato a mano dall'ufficio, altrimenti la copia stampigliata «copia per consultazione» (`protocollato_origine` lo dice). Il PDF firmato non cambia (byte per byte). L'originale non è mai un link per l'azienda. «Riprova da Titulus» e il caricamento a mano restano a iter concluso; il cron riprova una volta al giorno, 8 tentativi.
4. **Registrazione** in transazione e ripetibile (blocco della convenzione, rilettura dello stato). Avvisi a azienda, studenti e relatori **una sola volta** (lo stato è in `esito_registrazione`; «Ripeti gli avvisi» rifà solo quelli non partiti). Corretto lungo la strada un difetto vero: `avvisaStipulaSottoBlocco` usava `fn()` (cattura per valore) e il resoconto non si salvava; ora `function () use (&$esito)`.
5. **Nessuna convenzione doppia**: `salvaDati` e la scheda guardano se l'azienda ne ha già una valida (validità oltre 60 giorni da oggi) confrontando **codice fiscale/partita IVA normalizzati** (senza prefisso IT, senza spazi), **mai per nome**. In `AziendaDellaPratica` è sparita la ricerca per nome (ramo «altra azienda»: sempre `da_chiedere`): prima un nome uguale mostrava lo stato di una pratica altrui. Nessun dato di altre aziende o studenti a schermo.
6. **Link a pratiche altrui**: ogni punto che consegna un token di tesi usa `UtenteRepository::emailVerificata` (account SSO/SPID/CIE, oppure email scelta e confermata col codice). `Direzione::puoFirmare`/`direttoreOVice`, `ServizioConvenzioniAzienda::perUtente` e le fonti `convenzioniFsl`, `convenzioniTesiFirma`, `convenzioniFslFirma`, `convenzioniAzienda` di `DaFarePersona` non danno nulla a un'email non verificata. Il pannello salva `email_verificata_il = NULL` se cambia l'indirizzo (`aggiornaDaAdmin`).
7. **Passi**: `Costanti::PASSI` (cinque titoli) è usata da `admin/convenzioni_tesi.php` e da `convenzione_tesi.php` con `ui_passi()`: stesse parole nel pannello e nella pagina dell'azienda. Con FSL si condividono solo `InvioProtocollo`, `ProtocolloRepository`, `Stampigliatura` e il pacchetto Titulus; il resto è duplicato perché le due convenzioni differiscono (tabelle, pagine, destinatari).
8. **Prova «Didattica» cambiata di proposito**: `AziendaTesiPraticaTest::testStatoEAvvisiNellaPratica` si aspettava il riconoscimento per nome (`in_corso`); ora si aspetta `da_chiedere` e nessun dato dell'altra azienda. È il comportamento voluto dal passo 6.

### Prove nuove (`IterConvenzioneTesiTest`, 26, Titulus finto)
Automatico acceso (protocolla, registra, PDF firmato identico) · spento (nessuna chiamata) · errore di Titulus (firma salva, motivo scritto, poi il pulsante riesce) · risposta persa (incerto, né automatico né pulsante riprovano; il numero a mano la chiude) · numero a mano vuoto (non chiude) · avviso sulla firma / date mancanti (non parte) · file non restituito (iter concluso, cron che riprova, si ferma a 8) · impronta diversa · originale mai nei link dell'azienda · documento a mano che vince · doppio clic · numero a mano rifiutato mentre un altro processo tiene il blocco (definitivo e ingresso) · **tre processi PHP veri insieme** (un solo protocollo) · rifiuto durante la firma Aruba non scavalcato · ingresso via Titulus fino al Direttore · rifiuta/carica rifiutati durante un invio · avvisi una sola volta con email che fallisce e «Ripeti» · dedup per P.IVA/CF e non per nome, convenzione in scadenza non blocca · `AziendaDellaPratica` non perde dati · `perUtente` e `Direzione` con email verificata.

### Prove lanciate (24/11/2026, MariaDB accesa, una serie alla volta)
- `tests/Integration/Fsl` **174** · `Protocollo` **66** · `ConvenzioniTesi` **41** · `Portale` **54** · `Didattica` **110** (dopo l'adattamento di `AziendaTesiPraticaTest`) · `Tutorato` **57**: tutte verdi.
- `tests/Unit`: 723 prove, 0 rosse, 1 saltata. `Core` 7, `Auth` 74, `Email` 12, `Anagrafi` 26, `Sistema` 7 (1 saltata), `Amministrazione` 15; e `Ateneo`, `Attestati`, `Avvisi`, `Eventi`, `Forms`, `Infrastructure`, `Iscritti`, `Iscrizioni`, `Pubblicazioni`, `Risorse`, `Sondaggi`: tutte verdi. **Saltate in tutto: 2** (quelle di sempre).
- Rosse trovate **durante** il passo e corrette: `AuthIntegrazioneTest` (tabella `utenti` scritta a mano senza `email_verificata_il`: aggiunta la colonna) e `ModelliEmailTest` (`fsl_allegato_esito` dichiarava `testo` invece di `!testo`: era un difetto del passo 5, ora corretto).
- Pacchetto Titulus: 109 riuscite, 0 fallite.
- `strumenti/prove/esegui.php` con `zip`, `gd`, `intl` (comprende tutti i `protocolla_*`, le pagine FSL e tesi via HTTP con l'ambiente locale acceso): **3205 superate, 0 rosse**, nessuna saltata. Prima, svuotata `rate_limit_attempts`.
- `strumenti/locale/avvia.sh`: `eventi_locale` a schema **v142**.
- Nel **browser** (scenario di prova con tesi e FSL in ogni fase): `admin/convenzioni_tesi.php` (fase ④ vista a 1280 px chiaro e a 375 px scuro), `admin/fsl.php?tab=iter` (fase ② a 375 px scuro), `admin/protocollo.php` (375 px scuro, i due interruttori), `convenzione_online.php` e `convenzione_tesi.php` (passi con `ui_passi()`, 375 px): nessuno scorrimento orizzontale, nessun errore PHP (anche i cinque token di tesi e le altre pagine admin via richiesta). Server e MariaDB fermati a fine prova.

### Cosa resta
- **Non visto a schermo**: la riga d'archivio di una tesi **stipulata** (file di Titulus, originale, avvisi, «Riprova») e il pulsante «Invia al protocollo» (in locale il collegamento a Titulus non c'è, si vede solo il numero a mano); le pagine pubbliche non hanno il tema scuro (sono sempre chiare). La console segnala due «errore nel caricare lo script» di una risorsa esterna, non del portale.
- **`apache_nucleo.sh`** non lanciato.
- **Fonti ancora con l'email grezza**: in `DaFarePersona` le fonti `firme`, `conferme`, `pratiche` non usano ancora `emailVerificata` (fuori dal perimetro di questo passo): da decidere nel passo di sicurezza.
- **Titulus vero**: mai chiamato. Prima di accendere `automatico_tesi` e `automatico_fsl` in produzione: prova in pre-produzione (mydibest_pp) con l'interruttore acceso solo lì.
- **Rumore nei log**: `error_log` scrive «Servizio non registrato … FabbricaClienteTitulus» nelle prove della firma FSL (il contenitore di prova non ha il collegamento): voluto.
- **Commit**: non fatto, come chiesto; il commit unico è alla fine del passo 10.

## Correzioni al passo 6 (T1–T3, 24/11/2026, non committate)

Titulus: solo il finto (`tests/Doppi/TitulusFintoFsl.php`). Nessuna chiamata al Titulus vero. Prove scritte per **fallire sul codice di prima**.

| | Difetto | Correzione |
|---|---|---|
| **T1** | Nell'iter delle **tesi** un numero a mano con dati sbagliati o vuoto chiudeva l'esito incerto; il «Respinto» non esisteva; la data del numero era quella di oggi; l'automatico poteva ripartire su un documento in approvazione | Gli stessi stati e le stesse regole di P1–P6 (passo 5): `chiudiIncerto` solo se il numero a mano è valido; stato `in_approvazione` e «Respinto» (si può rimandare); il numero già dato porta la **data dell'invio riuscito**; l'automatico si ferma su `incerto`, `in_invio`, `in_approvazione`, `precedente` e scrive il motivo in `protocollo_errore` |
| **T2** | Se la firma Aruba del Direttore riusciva ma il blocco della convenzione era occupato, il PDF firmato andava perso e serviva un secondo OTP | Il PDF firmato si **conserva** in una cartella chiusa (`uploads/firme_conservate/`, `FirmeConservate`), legato a genere + id, all'impronta SHA-256 del file di partenza, allo stato e al link di quel momento. Si registra al tentativo successivo (il Direttore riapre la pagina, o il cron) **solo se** stato, link e file coincidono ancora; altrimenti si scarta e si avvisa l'ufficio. Una sola firma conservata per convenzione; mai un secondo OTP |
| **T3** | Un'email **non verificata** (scelta a mano e mai confermata) dava accesso ai compiti e alle pagine di altri (la falla 0.1 della roadmap UX) | I compiti per email e le pagine a cui portano si danno solo all'email **verificata** (`UtenteRepository::emailVerificata`: account SSO/SPID/CIE, oppure email scelta e confermata col codice). Senza email verificata restano solo le fonti per codice fiscale e per account |

### File cambiati o creati
- **T2**: `src/Infrastructure/Storage/FirmeConservate.php` (nuova), `src/Fsl/ServizioFirmaDirettore.php`, `src/ConvenzioniTesi/ServizioConvenzioniTesi.php`, `cron_background.php` (due blocchi: FSL e tesi; il cron registra le firme conservate).
- **T1**: `src/Protocollo/InvioProtocollo.php`, `src/ConvenzioniTesi/ProtocollaConvenzioneTesiInTitulus.php` (`protocolloAMano`), `ProtocollaDefinitivaTesiInTitulus.php` (`completaAMano`), `ProtocolloAutomaticoConvenzioneTesi.php`, `inc/parziali/protocollo_stato.php`, `admin/convenzioni_tesi.php`.
- **T3** (tutti i punti che consegnano un compito o aprono una pagina per email): `src/Auth/UtenteRepository.php`, `src/Portale/DaFarePersona.php`, `src/Uffici/Direzione.php`, `src/Uffici/ServizioUffici.php`, `src/Didattica/` (`ServizioUffici`, `ServizioConsigli`, `ServizioConfermeDocenti`, `ConfermeInBlocco`, `FirmaPratica`, `VerbaleFirmato`), `src/Tutorato/` (`ServizioIncarichi`, `ServizioRegistroTutorato`), `src/Fsl/` (`ConvenzioneCompilataRepository`, `ServizioConvenzioneOnline`, `RichiestaProgramma`, `ServizioProgrammaFsl`, `ServizioPercorsiScuola`), `src/ConvenzioniTesi/` (`ServizioConvenzioniTesi`, `ServizioConvenzioniAzienda`), `src/Risorse/ServizioResponsabiliRisorse.php`, `src/Firme/StoricoFirme.php`, `middleware.php`, `inc/attestati.php`, `inc/area/eventi.php`, `inc/area/fsl_scuola.php`, `convenzione_online.php`, `programma_fsl.php`, `documento_scuola.php`, `conferma_attestato.php`, `miei_eventi.php`.

### Scelte fatte
1. **T3, un'unica regola**: l'email vale solo se `emailVerificata($utente)` non è nulla, e si legge dall'**anagrafe** (`utenti`), non dall'email messa in sessione (che può essere stata scelta a mano). Ogni punto elencato sopra usa quella funzione; nessuno confronta più l'email grezza.
2. **T3, `puo_vedere_prenotazione`** (attestati): il titolare per email conta solo se l'email è verificata; la prova `strumenti/prove/oop/attestati.php` ora crea davvero l'utente (id 99071) invece di usare un id inventato, e lo toglie a fine prova.
3. **T2, nessuna credenziale conservata**: si conserva solo il PDF già firmato; l'OTP non si riusa e non si salva niente per rifirmare. Un PDF caricato a mano **non** si conserva (resta il «ricarica»). Una firma conservata manomessa (impronta diversa) non si registra.
4. **T1** non cambia le regole del passo 5: le porta alle tesi (stesso `InvioProtocollo`, stessi UPDATE condizionati, stesso blocco del documento).

### Ancora con l'email grezza (NON toccate, da decidere)
`Direzione.php:161` (segreteria), `ServizioUfficiAteneo`, `ServizioVerbaliPubblici`, `ServizioDiarioBordo`, `CruscottoRisorsa`, `StudentiDelDocente`, `ServizioPagamenti`, `ApprovazioniDocente`: sono grant per email che non consegnano un link a una pratica altrui; vanno valutati nel passo di sicurezza (non sono stati cambiati per non allargare il perimetro).

### Prove
- Nuove: `CorrezioniProtocolloTest` (T2, firme conservate: scarta se la convenzione è cambiata, PDF a mano non conservato, cron, firma manomessa), `IterConvenzioneTesiTest` (T1 e T2 per le tesi), `DaFareEdEmailTest` (T3: conferme, firme, attestati, tutorato, prenotazioni, risorse, verbali, operatori, pratiche da integrare, schede personali, iscrizioni).
- Adattate: `AuthIntegrazioneTest` (tabella `utenti` con `email_verificata_il`), `strumenti/prove/oop/attestati.php` (vedi la scelta 2).
- Esiti: vedi «Prove lanciate» del passo 7 (le stesse serie).

---

## Passo 7 — Modulistica: originale e documento protocollato (24/11/2026, non committato)

Schema **143** (tabella `protocollo_file`; verificata sul database locale). Titulus: **solo il finto**; nessuna chiamata al Titulus vero.

### Dove si scarica un PDF protocollato oggi (elenco fatto prima di toccare)
| Punto | Chi | Cosa dà adesso |
|---|---|---|
| `pratiche.php` (studente) | proprietario | «Documento protocollato» e «Originale firmato» (attestato); «La tua domanda protocollata» con Originale/Documento protocollato |
| `admin/didattica_pratiche.php` | ufficio | riquadri «Domanda protocollata» e «Attestato protocollato in uscita» con voci, origine, stato del file di Titulus, «Apri in Titulus», «Riprova» |
| `allegato_pratica.php` | proprietario o ufficio | `?d=domanda|attestato&v=originale|protocollato|titulus|apri`; i vecchi `?e=` e `?r=` restano |
| `admin/tutorato.php` | ufficio | `?file=originale|protocollato|titulus|apri`, riquadro «Lettera protocollata» |
| `incarico.php` (token + CF) | studente tutor | `?pdf=originale|protocollato` (solo a lettera protocollata) |
| `documento_tutorato.php` | ufficio/tutor | non scarica la lettera protocollata (documenti della pratica: decreti, registro, dichiarazione): **non cambia** |
| allegato dell'email con la copia della lettera | studente | non cambia il file; l'allegato si chiama «protocollata» se c'è la segnatura, altrimenti «firmata» |
| `ServizioPagamenti` | — | usa l'originale, non cambia |
| **Modulistica amministrativa** | — | passa dalla stessa pipeline delle pratiche: nessun codice a parte. Le **pubblicazioni** non hanno protocollo. Lo **zip** contiene solo i Word |

### File cambiati o creati
- **Schema**: `Definizioni.php` (`protocollo_file`, UNIQUE `rif_tipo`+`rif_id`: numero, origine `mano|copia|''`, file, impronta, `scaricato_il`, stato `attesa|ok|mancante|impronta`, tentativi, ultimo tentativo, errore), `Migrazioni.php` (v143).
- **Servizio**: `src/Protocollo/FileProtocollato.php` (nuovo; unico punto per `voci`, `apri`, `programma`, `scarica`, `riprovaTutti`, `elimina`).
- **Gancio dopo il protocollo**: `ProtocollaPraticaInTitulus`, `ProtocollaAttestatoInTitulus`, `Tutorato/ProtocollaLetteraInTitulus` (ultimo argomento del costruttore opzionale `?FileProtocollato`: compatibile con chi non lo passa).
- **Cancellazione**: `EliminaPratica`, `ServizioRegistroTutorato::conserva` (tolgono righe e file scaricati).
- **Altro**: `ServizioIncarichi` (nome dell'allegato), `cron_background.php`, `strumenti/locale/router.php` (`protocollo_file/` come cartella chiusa).
- **Pagine**: `pratiche.php`, `allegato_pratica.php`, `incarico.php`, `admin/didattica_pratiche.php`, `admin/tutorato.php`, `inc/parziali/protocollo_stato.php` (`protocollo_file_riquadro`, `protocollo_file_azione`).
- **Prove**: `tests/Integration/Protocollo/FileProtocollatoTest.php` (14), `TutoratoBase.php`, `strumenti/prove/oop/protocolla_pratica.php`, `protocolla_attestato.php`, `protocolla_tutorato.php`, `strumenti/locale/collaudo_file_protocollati.php` (nuovo, via HTTP sul portale locale).

### Scelte fatte
1. **Due voci per persona**: «Originale» e «Documento protocollato». Ordine del secondo: caricato a mano dall'ufficio → copia del portale con la segnatura («copia per consultazione») → file di Titulus. L'ufficio vede anche l'**origine** e lo stato del file di Titulus; la persona un solo pulsante per voce.
2. **Il PDF firmato non si stampiglia mai sul posto**: la domanda PDF/A, l'attestato firmato e la lettera firmata sono identici byte per byte dopo il protocollo (prove in `protocolla_*`). La segnatura sta solo nella copia.
3. **Il file di Titulus lo scarica il cron, non la pagina**: dopo il numero, il gancio **programma** una riga `attesa`; il cron fa 8 tentativi, uno al giorno (≥20 ore dall'ultimo), al massimo 20 file per giro, e un errore non ferma gli altri. Cartella chiusa, nome casuale, impronta SHA-256 e data salvate; impronta diversa da quella dichiarata → non conservato (`impronta`). Nessuna pagina chiama Titulus.
4. **Pratiche già protocollate**: nessun recupero in massa; la riga nasce solo col pulsante «Riprova da Titulus» (o al primo protocollo nuovo). Se non c'è il numero nella riga, si prende da quello della pratica; se Titulus «non ha ancora ricevuto» quel numero si riprova col primo pezzo del numero.
5. **«Apri in Titulus»** solo per ufficio e amministratori, e l'indirizzo si chiede a Titulus **al clic** (`apri`), non si conserva.
6. **Accessi**: ogni scarico passa dai controlli già esistenti della pagina; `realpath` deve stare dentro la cartella (`DIR_PRATICHE`/`DIR_INCARICHI`/`uploads/protocollo_file/`). Gli allegati interni e le note dell'ufficio non sono mai raggiungibili da `v=`. Gli attestati, per chi non è ufficio, solo se il documento protocollato c'è.
7. **`daOriginale`** (ultima spiaggia): se non c'è né file a mano né copia, la «Documento protocollato» di una domanda protocollata o di una lettera protocollata ripiega sull'originale, così il pulsante non sparisce; l'attestato no.
8. **I vecchi link restano**: `Domanda_*_protocollata.pdf` e `Firmato_direttore_*.pdf` (`?e=`/`?r=`) funzionano come prima.
9. **Cancellazione e conservazione**: eliminare una pratica o un incarico, o la scadenza della conservazione, toglie anche i file scaricati e la riga.
10. **Il collegamento a Titulus spento** (`nonPronto()`): `programma` non fa niente, il cron salta; nessun errore.

### Limite documentato (non cambiato)
Il modello di accesso esistente dà a **ogni operatore Didattica** (`ServizioUffici::gestisce`) tutte le pratiche: la divisione per ufficio o corso di laurea è un filtro del lavoro, non un permesso. Quindi «un operatore di un altro ufficio/corso non scarica» **non è vero oggi**, né prima né dopo questo passo; la prova non c'è perché sarebbe falsa. Restringere i permessi è un cambiamento del modello, da decidere a parte.

### Prove lanciate (24/11/2026, MariaDB accesa, una serie alla volta)
- **PHPUnit** (phar 9.6): `tests/Unit` 723 (0 rosse, 1 saltata) · Integration: `Amministrazione` 15 · `Anagrafi` 26 · `Ateneo` 5 · `Attestati` 14 · `Auth` 74 · `Avvisi` 4 · `ConvenzioniTesi` 48 · `Core` 7 · `Didattica` 110 · `Email` 12 · `Eventi` 17 · `Forms` 77 · `Fsl` 182 · `Infrastructure` 7 · `Iscritti` 21 · `Iscrizioni` 52 · `Portale` 62 · `Protocollo` 80 (con le 14 nuove) · `Pubblicazioni` 58 · `Risorse` 13 · `Sistema` 7 (1 saltata) · `Sondaggi` 10 · `Tutorato` 57 (le 25 che erano rosse perché il contenitore non aveva `FabbricaClienteTitulus` ora passano: registrata in `TutoratoBase`). **Saltate in tutto: 2** (quelle di sempre).
- **`strumenti/prove/esegui.php`** (con `zip`, `gd`, `intl`; `rate_limit_attempts` svuotata): **3273 superate, 1 rossa**: `puo_vedere_prenotazione(): titolare per id o email` (Attestati). Causa: la correzione T3 legge l'email dall'anagrafe e la prova usava un utente 7 che non esiste. **Corretta** nella prova (utente vero id 99071); la serie `attestati` rilanciata da sola: **601 superate**. La suite intera non è stata rilanciata dopo quella correzione (la modifica tocca solo quel file di prove).
- **Collaudo via HTTP** (`strumenti/locale/collaudo_file_protocollati.php`, portale locale): **53 prove riuscite, 0 fallite**. Studente A: sì (byte per byte, mai il file di Titulus né «Apri in Titulus», mai gli allegati interni, i vecchi `?e=` funzionano); studente B e docente qualunque: no; amministratore: sì; «Entra come»: sola lettura e vista dello studente; lo studente non può premere «Riprova».
- **Pacchetto Titulus**: non toccato dal passo 7.
- **Nel browser** (portale locale; chiaro e scuro, desktop e 375 px, nessuno scorrimento orizzontale, nessun errore PHP): pratica dello studente (chiaro e scuro a 375 px, scuro a larghezza piena), pratica dell'ufficio (riquadri Domanda/Attestato con file di Titulus «diverso dall'originale» e «non arrivato», chiaro e scuro a 375 px), lettera di tutorato protocollata (scuro, desktop), lettera con **esito incerto** (scuro: avviso e «Non c'è in Titulus: riprova», nessun riquadro dei file perché non è protocollata), riga d'archivio di una tesi dell'iter (scuro, desktop: file di Titulus «non arrivato», documento caricato dall'ufficio, avvisi della stipula). Per questa riga ho **cambiato per un momento** l'`origine` di una riga di prova locale e l'ho **ripristinata**; i dati del collaudo sono stati tolti (`--ripulisci`).

### Cosa resta
- **Non visto a schermo**: l'attestato dello studente è la stessa scheda «Il documento firmato è pronto» (visto); un esito incerto su una **pratica** (non lettera) non è stato visto: il collaudo ha l'esito incerto solo sulla lettera, ma `protocollo_stato.php` è lo stesso. La lettera dello studente nella pagina `incarico.php` è provata via HTTP, non a occhio.
- **Nessun recupero in massa** per le pratiche già protocollate: solo «Riprova» per pratica.
- **Numero a mano**: il file di Titulus di un documento protocollato a mano potrebbe non arrivare (se il numero non esiste in Titulus l'errore resta in riga, dopo 8 tentativi si ferma).
- **Tesi e FSL non spostate sul servizio**: `OriginaleDaTitulus` (FSL e tesi, passi 5 e 6) resta com'è; sono tre usi vicini ma con tabelle diverse. Si può unificare dopo la prova in pre-produzione.
- **«Apri in Titulus»** serve un Titulus configurato: in locale il pulsante c'è ma l'indirizzo non si ottiene.
- **Modello di accesso Didattica** (vedi il limite sopra).
- **Titulus vero**: mai chiamato. Prima di accendere l'automatico in produzione: prova in pre-produzione.
- **Commit**: non fatto, come chiesto; il commit unico è alla fine del passo 10.

## Prova di Titulus in pre-produzione (Opus, 24/11/2026, sola lettura)

Protocollo di prova 2026-UCALPRG-0001052, servizio 05.04.02.00, configurazione di mydibest_pp.

- `loadDocument` e `getAttachment` funzionano con il nostro utente, sia dal numero di protocollo sia dall'nrecord. Nessun permesso da chiedere in pre-produzione (in produzione da verificare).
- Il file torna **senza stampigliatura**. Quindi: il file di Titulus è l'originale protocollato, la copia stampigliata resta quella del portale.
- L'XML ha `principale="true"`, `signed`, `sealed`, `timestamped` e `DigestValue` SHA-256 (verificato uguale a quello del file scaricato).
- `getRegistrationMark` (lettura) dà i dati della segnatura. `applyRegistrationMark` la applicherebbe in Titulus: è una scrittura, NON provata.
- `getDocumentURL` e `getFileURL` danno indirizzi dell'interfaccia di Titulus (`/fe/#/documento/…`). Se chiedano l'accesso non è stato provato.
- Non provati: documento con più file, file firmato, protocollo registrato a mano, produzione.
- `applyRegistrationMark` provata (col sì dell'utente) su 0001052 il 24/11/2026, solo pre-produzione: risposta senza errore, ma il file letto con `getAttachment` è rimasto identico (stessa impronta, anche oltre un minuto dopo). Non si usa: la stampigliatura resta quella del portale.

---

## Correzioni al passo 7 e schizzo del passo 8 (25/11/2026, non committate)

Titulus vero: mai chiamato. Modello dei permessi della Didattica (ogni operatore vede tutte le pratiche): **non toccato**. Le prove di V1 e V2 sono state scritte per **fallire sul codice di prima** e poi rese verdi. Niente branch, niente worktree, niente commit.

### V1 — «Nessuno deve restare fuori per colpa di T3»

T3 ha tolto compiti e permessi a chi ha un'email scelta a mano e mai confermata. Giusto per la sicurezza, ma troppo duro con chi è una persona vera (un docente con l'email personale nel profilo). Ora ha una strada per rientrare e la vede.

| | Cosa è cambiato | Dove |
|---|---|---|
| **V1a** | Chi ha un'email **sua ma non verificata** può chiedere il codice a quell'indirizzo; il codice la segna verificata. «Questa è già la tua email» solo se è già verificata. Restano tutti i limiti (un invio al minuto, 10 al giorno, 5 tentativi, 30 minuti) e il controllo «già di un altro account» | `src/Auth/VerificaEmail.php` (`richiedi`) |
| **V1b** | Accesso SSO/SPID/CIE: se l'email dell'identità (minuscola, senza spazi) è uguale a quella personalizzata non verificata, la segna verificata. Se è diversa non tocca niente; con identità senza email non fa niente | `src/Auth/UtenteRepository.php` (`aggiornaAccessoSso`) |
| **V1c** | Avviso «La tua email non è ancora confermata» con l'elenco di **cosa non vedi** e il pulsante «Conferma la tua email»: in Area personale e nel profilo; sulle pagine di firma del Direttore (FSL e tesi); nel pannello solo a chi è stato escluso per questo motivo (403 con l'avviso, mai a chi è fuori per altro) | `src/Portale/Vista/AvvisoEmail.php` (nuova), `src/Portale/Pannello/AccessoPannello.php`, `admin/admin_header.php`, `area_personale.php`, `profilo.php`, `firma_convenzione.php`, `firma_convenzione_tesi.php` |
| **V1d** | Il pannello (Utenti) può **mandare il codice** a una persona e mostra «email non verificata» sulla scheda. **Non può** segnarla verificata a mano: la verifica la fa solo chi riceve il codice | `VerificaEmail::richiediDalPannello`, `admin/utenti.php` |
| **V1e** | `php strumenti/controllo_email_personalizzate.php --chi-perde [--dettagli]` (solo lettura): elenca gli account non verificati che perderebbero compiti o permessi, con il motivo. Niente email in chiaro salvo `--dettagli` | `src/Auth/EmailNonVerificata.php` (nuova), `strumenti/controllo_email_personalizzate.php` |
| **V1f** | Le prenotazioni dell'account (`utente_id`) restano visibili e gestibili anche senza email verificata; quelle trovate solo per email no | `tests/Integration/Iscrizioni/AreaPersonaleTest.php` |

Prove nuove o estese: `VerificaEmailTest`, `EmailNonVerificataTest`, `AvvisoEmailTest`, `AccessoPannelloTest`, `AreaPersonaleTest`.

### V2 — I punti rimasti con l'email grezza, e ogni confronto con l'email di chi è collegato

I **primi otto** punti (elenco del passo 6) ora usano `UtenteRepository::emailVerificata`; prova: `tests/Integration/Portale/EmailVerificataOttoPuntiTest.php` (8 prove: non verificata = niente, verificata col codice = sì, SSO = sì). Poi ho cercato **ogni** confronto con l'email dell'utente collegato (`$user_info['email']`, `$_SESSION['utente_email']`, `$utente['email']`, `$u['email']`) e deciso punto per punto.

| Punto | Cosa dà | Scelta |
|---|---|---|
| `src/Uffici/Direzione.php` (`puoImpostareAssenza`) | la segreteria attiva «Direttore assente» | **verificata** |
| `src/Ateneo/ServizioUfficiAteneo.php` (`utenteMembro`, `ufficiDellUtente`) | appartenenza agli uffici di Ateneo | **verificata** |
| `src/Didattica/ServizioVerbaliPubblici.php` | gestione dei link dei verbali pubblici | **verificata** |
| `src/Risorse/ServizioDiarioBordo.php` (3 punti) | compilare il diario come co-conducente o responsabile | **verificata**. In più un difetto che c'era già: `DiarioBordoRepository::senzaDiario` con email vuota cercava `"email":""` e prendeva ogni co-conducente senza email; ora senza email cerca solo per account |
| `src/Risorse/CruscottoRisorsa.php` | cruscotto del responsabile di una risorsa | **verificata** |
| `src/Didattica/StudentiDelDocente.php` | «I miei studenti» (tutor e tesisti trovati per email del docente) | **verificata** (codice fiscale e `persona_id` valgono come prima) |
| `src/Tutorato/ServizioPagamenti.php` | ruolo di tutor o di ufficio pagamenti sulla pratica | **verificata** (il codice fiscale vale come prima) |
| `ApprovazioniDocente` | «Le mie approvazioni» | nessun confronto proprio: passa dai servizi già portati |
| `header.php` → `Visitatore::daSessione` | voci di menu riservate a un ufficio o ai responsabili di risorse | **verificata**, letta dall'anagrafe e non dalla sessione |
| `il_mio_tutorato.php`, `inc/area/tutorato_mio.php` (`delTutor`) | gli incarichi di tutorato (con dati bancari) | **verificata** (il codice fiscale vale come prima) |
| `avvisi.php`, `ServizioAvvisi` (`$sua`) | iscrizione agli avvisi senza email di conferma se l'indirizzo è «suo» | **verificata**: altrimenti si poteva iscrivere un altro indirizzo senza conferma |
| `src/Assistenza/OperatoriAssistenza.php` (`ufficiVisibili`) | le chat dell'assistenza dell'ufficio | **verificata** |
| `src/Portale/Area/ProfiliPersona.php` | profili Personale (da ufficio) e Azienda (da convenzione) | **verificata** |
| `src/Portale/CalendarioPersonale.php` | prenotazioni trovate per email nel calendario | **verificata** (quelle con `utente_id` restano, V1f) |
| `src/Risorse/ServizioRisorse.php` | blocco di nuove prenotazioni se mancano diari | **verificata** |
| `src/Anagrafi/CollegamentoUtente.php`, `CollegamentiRepository::utente` | collega l'account alla persona dell'anagrafe (da cui il gruppo Docenti/PTA) | l'email dell'**identità** sempre; quella dell'account **solo se verificata** |
| `src/Auth/GruppoAziende.php` (`allineaTutti`) | gruppo «Aziende» per email di una convenzione di tesi | solo account con email **verificata** |
| `src/Portale/Pagine/PaginaStampaRicevuta.php` | «proprietario» di una ricevuta di prenotazione (oltre all'account e al codice) | **verificata** (prima: l'email messa in sessione). Nessuna prova mirata del caso «email non verificata» (vedi «Cosa resta») |
| `ServizioUffici`, `ServizioConsigli`, `ServizioConfermeDocenti`, `FirmaPratica`, `Attestati/Accesso` | operatori, consigli, conferme, firme, attestati | già **verificata** da T3 (non toccati) |
| Invio di email all'indirizzo di chi è collegato (notifiche, ricevute, email massive) | un messaggio all'indirizzo che la persona stessa ha scritto | **resta com'era**: scrivere a un indirizzo non dà accesso a niente |
| Email digitata in una prenotazione, pratica, lista d'attesa, check-in, risposta a un form | registra ciò che la persona ha scritto | **resta com'era**: è un dato inserito, non un confronto che dà accesso |
| Autore nei registri (`admin/*`, `Operatore`, `direttore_assente.php`, `ServizioAssistenza`, registro accessi ai dati) e visualizzazioni | scrive o mostra un nome | **resta com'era**: nessun accesso dipende da questi |

### V3 — Prove complete (25/11/2026, MariaDB accesa, una serie alla volta)

- **PHPUnit 9.6**, dopo l'ultima modifica (`PaginaStampaRicevuta`): `tests/Integration/Auth` 91 · `Portale` 71 · `Fsl` 182 · `ConvenzioniTesi` 48 · `Didattica` 110 · `Tutorato` 57 · `Risorse` 13 · `Iscrizioni` 53 · `Sistema` 7 (1 saltata) · `tests/Unit` 726 (1 saltata). E le altre cartelle di `tests/Integration`: `Amministrazione` 15 · `Anagrafi` 26 · `Ateneo` 5 · `Attestati` 14 · `Avvisi` 4 · `Core` 7 · `Email` 12 · `Eventi` 17 · `Forms` 77 · `Infrastructure` 7 · `Iscritti` 21 · `Protocollo` 80 · `Pubblicazioni` 58 · `Sondaggi` 10. **Tutte verdi; saltate 2** (una in `Sistema`, una in `Unit`: quelle attese).
- **`strumenti/prove/esegui.php`** (portale locale acceso, `rate_limit_attempts` svuotata prima): **«Tutte le 3274 prove superate», uscita 0**. Nessuna rossa.
- **`bash strumenti/prove/apache_nucleo.sh`** (Apache vero): **«Prova Apache del Nucleo: tutto in ordine»**, uscita 0.

### Schizzo del passo 8 (solo disegno: nessun codice del passo 8 è stato scritto)

`documentazione/schizzo_pagina_scuole.html`: pagina **statica**, dati inventati, nessuna chiamata. Usa gli stili veri (`dibest-colori.css`, `portale-ui.css`, `pannello.css`: `pn-*`, `ui-passi`) e i due temi. Aperta da file i fogli di stile relativi non si caricano nel riquadro dell'app: serve un indirizzo http, per esempio `http://127.0.0.1:8080/mydibest/documentazione/schizzo_pagina_scuole.html` con il portale locale acceso.

- **A · Elenco «Scuole»**: «Cosa devo fare» come filtri con i numeri, filtri stato/anno/testo, «Scarica» (Excel, PDF, CSV), UNA tabella con una riga per istituto (13 righe d'esempio, almeno una per stato), menu «Altro» (registra una convenzione firmata fuori, promemoria a tutte, scuole scritte a mano da abbinare, testi delle email). Sotto i 768 px ogni riga diventa una scheda.
- **B · Scheda**, nei due casi: **«da controllare»** (testata con stato e UNA azione principale; indice a sette riquadri; riquadro 1 aperto con `ui_passi()` e le azioni del passo 5 sul posto, compresi «Esito incerto», «Cambia referente», «Unisci» e lo storico chiuso) e **«attiva»** (riquadro 1 chiuso con numero, date, «Originale» e «Documento protocollato»; si apre il riquadro 2 perché ha qualcosa da fare). Sette riquadri: 1 Convenzione · 2 Eventi e progetti · 3 Docenti referenti · 4 Messaggi · 5 Documenti · 6 Valutazioni (solo `puo_fsl`) · 7 Anagrafe con le sedi.
- **In fondo: «Dove finisce ogni cosa di oggi»**: per ogni scheda di `admin/fsl.php` le azioni POST, i parametri GET e le ancore, con il posto nella pagina nuova oppure «resta in Numeri e report»; ogni riga dice se la vede anche chi ha solo `puo_fsl_convenzioni`.
- **Visto nel browser**: chiaro e scuro, desktop e 375 px; a 375 px nessuno scorrimento orizzontale (misurato con tutti i riquadri aperti); nessun errore in console.

**Da decidere con te prima del codice del passo 8:**
1. **`conv_online_prot`** (salva il protocollo da stampare nei documenti Word): il gestore c'è in `admin/fsl.php` ma nessun pulsante lo manda (lo usa solo la prova `strumenti/prove/oop/pagine_fsl.php`). Proposta: non portarlo e toglierlo al passo 9.
2. **`ricorda_elenco_tutti`** (promemoria dell'elenco studenti a tutti i docenti): non è nel tuo elenco del brief; nello schizzo resta in «Altro» perché non si perda.
3. **`ricorda_valutazione`**: oggi lo vede anche chi ha solo le convenzioni; nello schizzo sta nel riquadro 6, quindi solo `puo_fsl`. Va bene o deve restare anche a loro?
4. **Colonna «Studenti»** dell'elenco e numero degli studenti nel riquadro 2: nascosti a chi ha solo le convenzioni (lo schizzo li marca «solo FSL completa»). Confermi?
5. **«Convenzione in scadenza»** vale 60 giorni, come oggi.

### Cosa resta
- **Nessuna prova mirata** per `PaginaStampaRicevuta` nel caso «email non verificata»: la pagina costruisce l'accesso SSO e non si presta a un doppio semplice. Le prove HTTP di `esegui.php` sulle ricevute passano, ma il caso nuovo non ha una prova sua.
- **Il pannello non segna mai un'email come verificata**: scelta voluta. Chi non riceve il codice (indirizzo che non esiste più) deve cambiare email dal profilo.
- **Commit non fatto**, come chiesto: il commit unico è alla fine del passo 10. Il codice del passo 8 è stato scritto dopo il tuo sì allo schizzo, con le tue modifiche: vedi la sezione «Passo 8» in fondo.

---

## Passo 8 — La pagina «Scuole e convenzioni» (`admin/fsl_scuole.php`, 25/11/2026, non committato)

Schizzo approvato **con le tue modifiche**, che hanno la precedenza sul comando originale. Il disegno aggiornato è `documentazione/schizzo_pagina_scuole.html` (stessi stili del pannello, ora con le classi `sc-*` vere; da aprire con un indirizzo http del portale locale, da file i fogli di stile non si caricano).

### Cosa c'è ora

- **Elenco** (`fsl_scuole.php`): una riga per scuola con stato della convenzione, date, eventi, studenti, docenti e «prossima cosa da fare». Ogni riga ha il pulsante **«Apri scheda»** e il nome è un link: la riga intera non si clicca. Ordine: prima le scuole con qualcosa da fare, poi le scadute e in scadenza, poi per nome. Cinque tessere «Cosa devo fare» (Da controllare, Da protocollare, Dal Direttore, Messaggi da leggere, Senza convenzione), filtri anno (compreso «Tutti gli anni»), stato e testo, 50 righe per pagina, «Scarica» in Excel, PDF e CSV, menu «Altro».
- **Scheda** (`fsl_scuole.php?k=…`): tutto in una pagina, riquadri **sempre aperti** (si chiudono solo lo storico e i blocchi rari: cambia referente, unisci, modifica e aggiunta di un docente), indice in cima, due colonne da 1100 px e una sola a 375 px. Riquadri: la scuola (nome, comune, codice, tipo, indirizzo, PEC, email e telefono con `mailto:`/`tel:`, stato con date, **una sola** azione principale), Convenzione, Eventi e progetti, Docenti referenti, Messaggi, Altri documenti, Valutazioni (solo `puo_fsl`), Anagrafe e sedi. Il codice di una sede porta alla scheda dell'istituto (303).
- **Avvio della stipula** («Avvia la stipula» / «Rinnova»): il referente si sceglie tra i docenti della scuola o si scrive un altro indirizzo; **«Controlla prima di inviare»** mostra l'anteprima senza scrivere niente; **«Conferma e manda il link»** crea **una** compilazione (o riprende quella in corso: mai due) e manda l'email **solo** al referente. Funziona anche senza prenotazioni, se la scuola è nell'anagrafe. La pagina aperta dal link è del referente solo con l'email verificata (R1). Sta sotto il blocco dell'istituto e finisce nel registro delle operazioni. Con una convenzione già valida propone soltanto l'Allegato A (D1). «Registrane una già firmata» è il link alternativo.
- **Riquadro Convenzione**: passi, file (originale, protocollato, file della scuola, Allegato A, integrazioni) con nome, data e scarica; accetta/rifiuta integrazioni, ricorda il PDF, invia al Direttore, rifiuta, cambia referente, unisci, elimina dal registro (con la parola «elimina»); i file delle convenzioni precedenti sono nello storico.
- **Eventi e progetti**: **tutte** le prenotazioni della scuola e delle sue sedi, anche non FSL (marcate «convenzione non richiesta»), per l'anno scelto o per tutti gli anni. Per ognuna: titolo con link, turno e date, sede, docente (email e telefono), stato, se è coperta e perché no, studenti, «Elenco studenti» oppure «non ancora caricato» con «Ricorda», autorizzazione, attestati. Il link all'elenco degli studenti compare solo a chi la pagina di destinazione lascia entrare (stesso calcolo dei permessi della pagina `partecipanti.php`).
- **Docenti referenti**: nome, email, telefono (se c'è), eventi seguiti, presenza nell'Allegato A, ultimo contatto; «Scrivi», «Modifica», «Aggiungi». Le persone con la stessa email sono una sola. **Un docente aggiunto non diventa titolare della convenzione.**
- **«Scarica la scheda»** (PDF): data, convenzione, eventi, docenti. **Niente** nomi di studenti, codici fiscali o link personali.
- **Chi ha solo `puo_fsl_convenzioni`** non vede numeri di studenti, valutazioni, né il pulsante `ricorda_valutazione`.
- **Azioni POST di `admin/fsl.php` spostate qui** (stessi servizi, stesso registro delle operazioni, CSRF del nucleo, `conferma.js`): `cv_ricorda`, `cv_ricorda_tutte`, `cv_letto`, `cv_messaggio`, `cv_rifiuta`, `cv_invia_direttore`, `cv_ripeti_applicazione`, `cv_cambia_referente`, `cv_unisci`, `att_conferma`, `att_scarta`, `allegato_accetta`, `allegato_rifiuta`, `conv_elimina`, `ricorda_elenco`, `ricorda_elenco_tutti`, `ricorda_valutazione`, `docente_salva`, più le due nuove `avvia_anteprima` e `avvia_conferma`. `st_chiedi` è sostituito da «Avvia la stipula».
- **Schema**: v144 (`avviata_il`, `avviata_da` sulla compilazione: chi l'ha avviata dal pannello e quando) e v145 (tabella `scuole_docenti`, la rubrica). `Migrazioni::VERSIONE` = 145.

### File cambiati o creati

- **Pagina**: `admin/fsl_scuole.php` (guscio di 3 righe), `src/Portale/Pagine/PaginaScuoleFsl.php`, `src/Portale/Pagine/AzioniScuolaFsl.php`; `Rotte.php` (una riga), `PermessiPagine.php` (`fsl_scuole.php` → `puo_fsl_convenzioni`), `MenuModuli.php` (voce «Scuole e convenzioni» nel modulo FSL), `inc/sezioni.php` (`PAGINE_MODULO`).
- **Dominio**: `src/Fsl/ElencoScuoleFsl.php`, `SchedaScuolaFsl.php`, `StatoConvenzioneScuola.php`, `RubricaDocentiScuola.php`, `PdfSchedaScuola.php`; `ServizioConvenzioneOnline` (`avviaDalPannello`, `anteprimaAvvio`), `ConvenzioneCompilataRepository`, `PrecompilazioneConvenzione`, `PrenotazioneFslRepository`, `CatalogoConvenzioni`, `Definizioni.php`, `Migrazioni.php`.
- **Viste** (`viste/pannello/`): `fsl_scuole_elenco.php`, `fsl_scuole_scheda.php` e i parziali `_fsl_scuole_testata`, `_convenzione`, `_eventi`, `_docenti`, `_messaggi`, `_documenti`, `_valutazioni`, `_anagrafe`. Stili `.sc-*` in coda a `assets/css/pannello.css` (nessuno `style=` in linea, tema scuro e telefono).
- **Prove**: `tests/Integration/Fsl/SchedaScuolaFslTest.php` (13), `tests/Integration/Portale/PaginaScuoleFslTest.php` (17), dati di prova in `tests/Unit/Http/VistaTest.php`; `documentazione/inventario_accessi.md` rigenerato (cambia solo la pagina nuova).

### Scelte fatte

- Le cinque domande dello schizzo precedente hanno avuto risposta dalle tue modifiche: `conv_online_prot` **non è stato riportato** (non lo manda nessun pulsante); `ricorda_elenco_tutti` resta nel menu «Altro»; `ricorda_valutazione` e il numero degli studenti sono **solo di `puo_fsl`**; la scadenza è a 60 giorni come oggi.
- Elenco e scheda leggono lo stato da `StatoConvenzioneScuola`, un calcolo solo (in PHP): non possono dire cose diverse.
- Il numero di query della scheda **non dipende** dalle prenotazioni: provato con 60 prenotazioni (in `SchedaScuolaFslTest`).
- Il telefono dei docenti non è nell'anagrafe: viene dal modulo di prenotazione (`dati_custom_json`, chiave che somiglia a «telefono» o «cellulare») o dalla rubrica.
- Il link all'elenco studenti chiama lo stesso calcolo della pagina di destinazione (`Richiesta::finta` su `partecipanti.php`), non una copia delle regole.

### Limiti (da sapere)

1. **Restano su `admin/fsl.php`** (la scheda ha il link): prendere in carico e protocollare in entrata (`cv_prendi`, `cv_titulus`), il controllo date e docenti (`cv_controllo`), il protocollo del firmato e la registrazione (`cv_titulus_def`, `cv_completa`), `cv_carica_protocollato`, `conv_salva` («Registrane una già firmata»), `conv_verifica`. Si spostano al passo 9. Tre azioni (`cv_incerto_chiudi`, `cv_respinto`, `cv_riprova_originale`) sono già gestite da `AzioniScuolaFsl`, ma la scheda **non ha ancora i pulsanti**: si usano da `fsl.php`.
2. «Docenti» nell'elenco = indirizzi email diversi delle prenotazioni dell'anno. L'elenco **non** calcola «elenco studenti mancante».
3. Una compilazione senza prenotazioni non compare nelle tessere del referente (`DaFarePersona` ha un INNER JOIN sulle prenotazioni).
4. Un docente senza email non si può modificare né scegliere come referente.
5. L'avvio dal pannello richiede la riga dell'istituto nell'anagrafe `scuole` (come nei dati del Ministero); altrimenti dice «abbinala all'anagrafe».
6. «Due clic insieme» è provato in sequenza; il blocco vero è coperto dalle prove del servizio.
7. **Non verificato in un browser vero** (tema chiaro e scuro, 375 px): il riquadro dell'app non ha aperto né la pagina né lo schizzo (navigazione negata); lo schizzo è stato controllato solo come struttura HTML (tag chiusi, ancore). Da guardare tu, con `http://127.0.0.1:8080/mydibest/documentazione/schizzo_pagina_scuole.html`.

### Prove lanciate (25/11/2026, MariaDB accesa)

- **PHPUnit 9.6**: `tests/Unit` **726 prove, 7306 asserzioni, OK (1 saltata)**; `tests/Integration` intera **1015 prove, 14730 asserzioni, OK (1 saltata)**. In mezzo, le 17 della pagina (`PaginaScuoleFslTest`) e le 13 della scheda (`SchedaScuolaFslTest`) sono tutte verdi: avvio senza prenotazioni, con compilazione in corso, con convenzione valida, due richieste di seguito, email non valida, scuola scritta a mano, eventi di una sede e non FSL, link agli studenti nascosto a chi non può, PDF senza dati vietati (letto dal testo estratto), query a numero fisso con 60 prenotazioni.
- **`php strumenti/avanzamento_nucleo.php --controlla`**: «La tabella delle rotte e i gusci coincidono».
- **Non rilanciate dopo la pagina nuova**: `strumenti/prove/esegui.php` (prove HTTP con database usa e getta) e `strumenti/prove/apache_nucleo.sh` (Apache vero). Vanno fatte prima del commit. Nessuna verifica a occhio in un browser.

### Cosa resta

- **Passo 9**: portare qui (o togliere) le azioni elencate nel limite 1 e far puntare gli indirizzi `fsl.php?tab=…` alla pagina nuova senza far morire i vecchi.
- **Prova in pre-produzione** dell'avvio della stipula (email vera al referente, link, verifica dell'email) e **guardare le schermate** nel browser.
- **Commit non fatto**, come chiesto.

## Correzioni al passo 8 (W1–W8, 25/11/2026, non committate)

Solo le correzioni W1–W8 alla pagina «Scuole e convenzioni» (`admin/fsl_scuole.php`). Niente commit, niente ramo, niente `git stash`; `.gitignore` e `documentazione/mappa` non toccati; nessuna chiamata al Titulus vero. I collegamenti a `fsl.php` dalle altre pagine restano al passo 9 (non iniziato).

### Cosa è cambiato (per punto)

- **W1 — Tutto l'iter si fa dalla scheda.** Nessun `href` verso `fsl.php` (resta solo «Numeri e report»). Un riquadro per ogni compilazione in corso (`viste/pannello/_fsl_scuole_iter.php`, con `_fsl_scuole_titulus.php` e `_fsl_scuole_campi_docenti.php`): ricorda il PDF, controlla, prendi in carico / protocollo a mano, Titulus (ingresso e definitivo, esito incerto, respinto), rifiuta con motivo, Allegato A, invia al Direttore, firma esterna, completa, ripeti l'approvazione, cambia referente, attività da confermare o scartare. Le classi dei dati sono condivise (`src/Fsl/IterNellaScheda.php`, `PassiConvenzione`, le classi `Azioni/*`), non copie di `fsl.php`. `conv_verifica` sta nel menu «Altro» solo per chi ha `puo_fsl`.
- **W2 — Una sola guardia sugli id.** `AzioniScuolaFsl` controlla con `AppartenenzaScuolaFsl` che compilazione, convenzione, allegato e scuola del modulo appartengano alla scuola della scheda aperta, prima di qualunque azione.
- **W3 — Riprendere una compilazione non rivendicata cambia il link.** Token nuovo; il vecchio link e il codice della prenotazione non aprono più (`ConvenzioneCompilataRepository::riapri`). Chi l'ha già rivendicata non cambia.
- **W4 — Bloccano l'avvio solo le compilazioni compilate o avviate dall'ufficio.** Una mai compilata e non avviata dall'ufficio non conta (`conta()` in PHP e `SQL_CONTA` in SQL, lo stesso criterio).
- **W5 — «Parti dai dati della convenzione di prima» è una casella** (`copia_dati`), spuntata solo se permessa, disabilitata con la spiegazione se il referente non era tra i verificati, assente se non c'è una convenzione conclusa.
- **W6 — Un solo criterio** per «cosa c'è da fare»: `StatoConvenzioneScuola::cose()`, usato da elenco, scheda, menu e cruscotto (`CoseDaFare` usa gli stessi stati e `SQL_CONTA`).
- **W7 — Contatti, docenti, parole.** I recapiti scritti dalla scuola compaiono «indicata dalla scuola» solo se nell'anagrafe mancano. Nell'elenco i primi tre docenti (poi «e altri N»); la ricerca trova docenti ed email, anche quelli solo nell'Allegato A. Niente parole tecniche («rubrica», «json», «proprietario»…). Una compilazione senza prenotazioni compare in `DaFarePersona` e porta a `ricevi_link_convenzione.php`; un docente senza email si vede («manca l'indirizzo email») e si completa senza raddoppiarlo.
- **W8 — Formule e doppio clic.** `TabellaEsportabile::cella()` (unico punto per CSV, Excel e PDF) mette un apice davanti ai testi che cominciano con `= + - @` (anche dopo spazi, tabulazioni o a capo); i numeri veri restano numeri. Due clic insieme su «Avvia la stipula», con **due processi PHP veri** nello stesso istante, danno una compilazione e una email.

### File cambiati o creati in queste correzioni

Nuovi: `src/Fsl/IterNellaScheda.php`, `viste/pannello/_fsl_scuole_iter.php`, `_fsl_scuole_titulus.php`, `_fsl_scuole_campi_docenti.php`, `tests/Integration/Portale/SchedaIterCompletoTest.php`, `AvvioDueProcessiTest.php`, `lavoratore_avvio.php` (il processo del clic: lo lancia la prova).
Cambiati: `src/Fsl/SchedaScuolaFsl.php`, `ElencoScuoleFsl.php`, `src/Portale/Pagine/PaginaScuoleFsl.php`, `src/Portale/DaFarePersona.php`, `CoseDaFare.php`, `ricevi_link_convenzione.php`, `src/Infrastructure/Documenti/TabellaEsportabile.php`, le viste `fsl_scuole_elenco.php`, `fsl_scuole_scheda.php`, `_fsl_scuole_convenzione.php`, `_fsl_scuole_testata.php`, `_fsl_scuole_docenti.php`, `assets/js/pannello.js` (aggiungi e togli docente), `assets/css/pannello.css` (`.sc-iter`, `.sc-cose`, `.sc-manca`, righe dei docenti; nessun `!important` nuovo), `tests/Unit/Http/VistaTest.php` (dati di prova della scheda alla nuova forma), `tests/Unit/Infrastructure/TabellaEsportabileTest.php`.

### Scelte fatte

- Gli attributi derivati (docenti, Titulus, direttore, periodo) si calcolano in `IterNellaScheda` solo per le compilazioni in corso, con servizi pigri: la scheda non costa più query di prima (la prova del numero di query dà lo stesso conteggio con 10 e con 300 istituti).
- L'HTML dei passi (`ui_passi`) si prepara nella pagina, non nel modello: i modelli si rendono prima della testata, che è dove si caricano gli aiuti.
- Il filtro dell'elenco legge un solo campo `ricerca` preparato a parte, così cercare un docente non cambia il costo.
- Una cella che comincia con `-` diventa `'-…`: scelta voluta, il costo è un apice visibile.
- Il segnaposto della ricerca dell'elenco è accorciato («Scuola, comune, docente, email»), con l'`aria-label` completo: a 1280 px era tagliato (visto nelle schermate).

### Prove

Lanciate il 25/11/2026 con MariaDB accesa, una serie alla volta:

| Serie | Esito |
|---|---|
| `tests/Integration/Fsl` | 210 verdi |
| `tests/Integration/Portale` | 156 verdi |
| `tests/Integration/Protocollo` | 80 verdi |
| `tests/Integration/ConvenzioniTesi` | 48 verdi |
| `tests/Integration/Auth` | 91 verdi |
| `tests/Unit` | 735 casi, 0 rossi, 1 saltata (la stessa di prima) |
| `avanzamento_nucleo.php --controlla` | «le rotte e i gusci coincidono» |
| `esegui.php` | **3274 su 3274**; la prima volta, con l'ambiente locale spento, 2558 e le prove delle pagine saltate: rifatta con l'ambiente acceso |
| `apache_nucleo.sh` | «tutto in ordine» |

Una prova rossa c'è stata e si è corretta: `VistaTest::testOgniModelloHaIDatiDiProvaECosiLaProvaDellaCspLoRende`, perché i dati di prova della scheda erano nella forma vecchia (mancavano `iter_aperti`, `registrate`, `direttore`, `periodo_nuovo`, `contatti_indicati`, `copia`…): aggiornati, e ora la prova della CSP guarda anche i nuovi riquadri.

**Prove che falliscono sul codice sbagliato.** Il passo 8 e le sue prove non sono nel commit (non c'è una «versione di prima» da rilanciare), quindi la prova «rossa sul vecchio» si è fatta **guastando a mano il codice** e controllando che le prove diventino rosse, poi ripristinando (confronto byte per byte):

| Guasto | Prova che diventa rossa |
|---|---|
| W2: tolta la guardia di appartenenza | `AzioniScuolaFslGuardiaTest` (fatta nel segmento precedente) |
| W3: `riapri` non cambia più il link | 2 in `AvvioDalPannelloTest` |
| W4: `conta()` sempre vero | 2 in `AvvioDalPannelloTest` |
| W4: `SQL_CONTA` sempre vero | 1 in `CompilazioneSicurezzaTest` |
| W7: `DaFarePersona` di nuovo solo con prenotazione | 1 in `SchedaIterCompletoTest` |
| W8: `cella()` senza apice | 9 casi in `TabellaEsportabileTest` |
| W8: tolto il blocco per istituto | entrambe le prove di `AvvioDueProcessiTest` (2 compilazioni invece di 1) |

W1, W5, W6 e le parti nuove di W7 (nomi nell'elenco, parole tecniche, docente senza email) sono coperti da prove nuove ma **non sono stati guastati uno per uno**: sono verificati solo dal fatto che le prove nuove passano e guardano il testo reso.

### Azione di `fsl.php` → riquadro della scheda → prova

(Prove: Guardia = `AzioniScuolaFslGuardiaTest`, Iter = `SchedaIterCompletoTest`, Pagina = `PaginaScuoleFslTest`.)

| Azione in `fsl.php` | Dove si fa nella scheda (`fsl_scuole.php?k=…`) | Prova |
|---|---|---|
| `cv_ricorda` | riquadro della compilazione, «Ricorda il PDF firmato» | Guardia, Iter |
| `cv_controllo` | «Controlla prima di inviare» | Guardia, Iter |
| `cv_prendi` (+ `prot_ingresso`) | «Registra il numero a mano» (in entrata) | Guardia, Iter |
| `cv_titulus` / `cv_titulus_def` | «Protocolla in Titulus» (ingresso / definitivo) | Guardia |
| `cv_incerto_chiudi`, `cv_respinto` | riquadro «Esito incerto» / «Respinto» | Guardia |
| `cv_rifiuta` | «Rimanda alla scuola e avvisala» | Guardia, Iter |
| `allegato_a`, `docente_salva` | «Salva date e docenti», docenti | Guardia, Pagina, Iter |
| `cv_invia_direttore`, firma esterna | «Dal Direttore per la firma» | Guardia, Iter |
| `cv_completa` (+ `protocollo`) | «Registra il numero a mano» (definitivo) | Guardia, Iter e browser |
| `cv_ripeti_applicazione` | riquadro della registrata | Guardia, Iter |
| `cv_riprova_originale`, `cv_carica_protocollato` | riquadro della registrata | Guardia, Iter |
| `cv_cambia_referente` | «Cambia referente» | Guardia, Iter |
| `att_conferma`, `att_scarta` | attività da confermare | Guardia |
| `cv_unisci` | compilazioni doppie | Guardia, Iter |
| `conv_salva`, `conv_elimina` | registro: Modifica / Rinnova / Elimina, «Registrane una già firmata» | Guardia, Iter |
| `allegato_accetta`, `allegato_rifiuta` | integrazioni dell'Allegato A nel registro | Guardia |
| `cv_messaggio`, `cv_letto` | riquadro «Messaggi con la scuola» | Guardia |
| `ricorda_elenco`, `ricorda_valutazione` | «Eventi e progetti» / «Valutazioni» | Guardia, `SchedaScuolaFslTest` |
| `conv_verifica` | menu «Altro» dell'elenco (solo `puo_fsl`) | Guardia, Iter |
| `avvia_anteprima`, `avvia_conferma`, `copia_dati` | «Avvia la stipula» / «Rinnova» | Pagina, `AvvioDalPannelloTest`, `AvvioDueProcessiTest` |
| `conv_online_prot` | **sostituita** da `cv_prendi` (stesso effetto: numero e data) | non più nella scheda |
| `st_chiedi`, `st_scrivi` | **restano solo in `fsl.php`**: erano l'elenco «Da stipulare»; nella scheda li sostituiscono «Rimanda il link a un referente» e «Scrivi alla scuola» | — |

### Cosa si è visto nel browser (portale locale, 25/11/2026)

Con `strumenti/locale/avvia.sh` e l'accesso di prova come amministratore, **a mano nel browser integrato** (non con uno script): dalla scheda dell'Istituto Tecnico Volta, partendo dal PDF firmato caricato dalla scuola, «Registra il numero a mano» (protocollo in entrata) → «la scuola è stata avvisata, il Direttore ha ricevuto l'email»; firma del Direttore (con lo scenario `firmata`, perché la firma è una pagina del Direttore); poi «Registra il numero a mano» (definitivo) con la conferma → convenzione n. 7 nel registro, la scuola «Attiva» fino al 24/11/2027, la prenotazione confermata. Il database mostra la compilazione «protocollata» e la riga nel registro.

Schermate (Chrome headless, `strumenti/locale/schermate.py`) in `documentazione/schermate_passo8/`: l'elenco e le schede, **chiaro e scuro, a 1280 e a 375 px**: Volta nelle sei tappe (manca, compilata, PDF caricato, dal Direttore, firmata, attiva), una scuola «scaduta» (Galilei), una con **esito Titulus incerto** (Fermi), una «manca» (Galluppi), una **scritta a mano** (Istituto Rossi di Altrove), e la vista di chi ha **solo le convenzioni** (menu ridotto, nessun «Aree» né «Scuole»; solo 1280 chiaro). Non si vedono scorrimenti orizzontali a 375 px; i contrasti del tema scuro sono leggibili (il testo di `.iter-esito`, prima fisso su fondo chiaro, ora usa i colori del tema). Ho guardato di persona le schermate dell'elenco (1280 chiaro, 375 scuro), della tappa «PDF caricato» (1280 scuro, 375 chiaro), dell'esito incerto e di chi ha solo le convenzioni; le altre sono scattate ma non riguardate una per una.

### Limiti (da sapere)

- **`admin/fsl.php` non è stato riscritto** sulle classi condivise: ha ancora i suoi gestori POST e continua a funzionare per chi arriva da link vecchi; il passo 9 toglie i collegamenti e lo svuota. Per ora le due strade fanno le stesse cose con codice in parte duplicato.
- L'esportazione `EsportaIscritti` (CSV degli iscritti) **non** neutralizza le formule: fuori da questo giro, da fare con la stessa regola.
- `CruscottoDirettore` non è stato toccato; i suoi collegamenti a `admin/fsl.php` sono del passo 9.
- Le prove con il Titulus sono tutte con il doppio finto; l'«esito incerto» nelle schermate è una riga di prova nel registro degli invii. Nel browser non si è provato un protocollo automatico vero (in locale il servizio non c'è: il percorso visto è quello del numero a mano).
- In locale la scuola «Fermi» non si poteva avviare dal pannello finché nell'anagrafe mancava la riga con il codice dell'istituto: è il controllo voluto («La scuola non è nell'anagrafe»), con i dati veri c'è.
- I dati seminati per le schermate sono stati tolti (scuola, prenotazioni, abilitazione) e lo scenario è tornato a «nuova».

## Ritocchi al passo 8 e passo 9 (25/11/2026, non committati)

Scritti dalla chat che scrive, rivisti da Opus. In questo giro il codice del passo 9 e dei ritocchi Y1-Y6 non è stato riscritto: si sono provati, chiuse le prove rimaste indietro e guardate le pagine nel browser. Schema fermo alla v145. Nessuna chiamata al Titulus vero.

### Cosa è cambiato, punto per punto

**Ritocchi Y1-Y6 alla scheda della scuola** (`src/Fsl/SchedaScuolaFsl.php`, `StatoConvenzioneScuola.php`, `viste/pannello/_fsl_scuole_*.php`; prova `tests/Integration/Portale/RitocchiSchedaTest.php`, 9 prove):

| Punto | Cosa fa ora | Dove |
|---|---|---|
| Y1 | «Scrivi alla scuola» è sempre possibile: arriva alla compilazione in corso (la più avanti), altrimenti all'ultima conclusa, altrimenti ai docenti scelti tra chi ha prenotato. Il link personale va solo all'email della compilazione. | `SchedaScuolaFsl::scrivi()`, `_fsl_scuole_messaggi.php`, `AzioniMessaggiPersone` |
| Y2 | Un docente è «nell'Allegato A» anche se lo è nella convenzione valida; una convenzione scaduta non mette nessuno «nell'Allegato A» di adesso. | `SchedaScuolaFsl::docenti()` |
| Y3 | I file del registro sono «Originale» e «Documento protocollato», ciascuno con data e provenienza (firmato dalle due parti, ripreso da Titulus, caricato dall'ufficio, copia del portale). Con un file solo se ne mostra uno. | `SchedaScuolaFsl::documentiRegistrati()`, `_fsl_scuole_convenzione.php` |
| Y4 | Una sola azione principale con un verbo in testata (blocco `sc-principale`); le altre cose da fare sono un elenco sotto (`cose_altre`). | `_fsl_scuole_testata.php` |
| Y5 | Elenco studenti e autorizzazione mancanti tra le cose da fare e negli eventi; non li vede chi ha solo le convenzioni. | `SchedaScuolaFsl::cose()`, `_fsl_scuole_eventi.php` |
| Y6 | «Rimanda il link» compare solo se la scuola ha ancora da fare; niente buchi nella scheda; un solo menu «Altro» sul registro (Modifica, Elimina, «Registrane una già firmata»); «altra sede» solo se vera. | `_fsl_scuole_convenzione.php`, `_fsl_scuole_iter.php` |

**Passo 9, punti 1-8**:

| Punto | Cosa fa ora | File |
|---|---|---|
| 1 | Le GET vecchie di `admin/fsl.php` sono rimandi 303 con lo stesso significato (`?cv=ID`, filtri, scheda, modifica, rinnovo e nuova con le date). Le ancore (`#cv12`, `#fase-caricata`…) le traduce uno script piccolo con il nonce della pagina. | `src/Portale/Pagine/RimandiFsl.php` (nuovo), `viste/pannello/_fsl_scuole_ancore.php` (nuovo) |
| 2 | I POST vecchi non hanno più nessun effetto: si arriva nel punto giusto (la scheda della scuola, se si capisce dall'id o dal codice). | `RimandiFsl::perUnModuloVecchio()` |
| 3 | `admin/fsl.php` è il guscio di «Numeri e report» (`PaginaNumeriFsl`, rotta `admin/fsl`, permesso `puo_fsl`), nel nucleo. Chi ha solo le convenzioni finisce sempre su «Scuole e convenzioni». | `admin/fsl.php` (da 1158 righe a 7), `PaginaNumeriFsl.php`, `viste/pannello/fsl_numeri.php` (nuovi), `Rotte.php`, `PermessiPagine.php` |
| 4 | Codice morto tolto dopo grep: non resta nessun `fsl.php?tab=` fuori da `RimandiFsl`. | `ServizioPannelloFsl.php` (tolto `registro()`), `PannelloRepository.php` |
| 5 | Collegamenti aggiornati: inizio, postazioni di lavoro, cruscotto del Direttore, ricerca Ctrl+K, guide, menu, cron, email. | `admin/inizio.php`, `PostazioniLavoro.php`, `CruscottoDirettore.php`, `RicercaPannello.php`, `GuidePerRuolo.php`, `MenuModuli.php`, `cron_background.php`, `ServizioCron.php`, `admin/cerca.php`, `admin/messaggi.php`, `admin/admin_header.php` |
| 6 | Menu FSL senza voci doppie: «Scuole e convenzioni», «Numeri e report», «Anagrafe delle scuole». | `MenuModuli.php` |
| 7 | Tutte le esportazioni passano dalla regola di `TabellaEsportabile::cella()` (celle che cominciano con `=`, `+`, `-`, `@` neutralizzate). | `EsportaIscritti`, `EsportaRisultati`, `ModelloElenco`, `TabellaEsportabile`; prova nuova `RegolaUnicaDelleCelleTest` |
| 8 | `documentazione/mappa` non toccata. | — |

### File cambiati o creati in questo giro di revisione

- `tests/Integration/Fsl/CompilazioneUnicaTest.php` e `tests/Integration/Fsl/CompilazioneSicurezzaTest.php`: adattate (sotto).
- `tests/Unit/Http/VistaTest.php`: adattata (sotto).
- `strumenti/prove/oop/fsl.php`: adattata (sotto).
- `documentazione/inventario_accessi.md`: rigenerato con `php strumenti/mappa_accessi.php` (`fsl.php` diventa «Numeri e report», entra la rotta `admin/fsl`, «Scuole» diventa «Anagrafe delle scuole», `convenzione_file.php` passa a «Scuole e convenzioni»).
- `documentazione/schermate_passo9/`: 28 schermate nuove.

### Prove adattate in questo giro, e perché

Nessuna prova è stata indebolita: ognuna controlla la stessa cosa sul codice nuovo.

| Prova | Perché era rossa | Cosa è cambiato |
|---|---|---|
| `CompilazioneSicurezzaTest::testUnaVuotaNonEntraNelleCoseDaFareDellaScheda` | Usava `App\Fsl\ServizioSchedaScuola`, tolto nel passo 8 (la scheda oggi è `SchedaScuolaFsl`). | Usa `SchedaScuolaFsl::scheda($k, 0, true)`: una compilazione mai compilata non entra in `aperte` né in `iter_aperti`, e resta solo il segnale «senza convenzione» (`tags`). |
| `CompilazioneUnicaTest::testLeAttivitaAggiunteDopoLaFirmaSiVedonoDallUfficioEIlFileFirmatoNonCambia` | Stesso motivo. Il campo `avvisi` (testi) non esiste più nella scheda. | `dopo_firma` e `da_confermare` si leggono da `iter_aperti[0]`, `istituto` è diventato `chiave`, le prenotazioni sono `eventi`. I due controlli sul testo degli avvisi sono sostituiti dai controlli sui dati da cui nascono. |
| `VistaTest::testOgniModelloHaIDatiDiProvaECosiLaProvaDellaCspLoRende` | Il modello nuovo `pannello/fsl_numeri` non aveva i dati di prova («senza, la CSP non lo guarda»). Andando avanti mancavano anche i campi che il passo 8 ha aggiunto alla scheda (`ancora`, `cose_altre`, `scrivi`, `apri`, `documenti`, `data_allegato`, `in_stipula`, `elenco_manca`, `autorizzazione_manca`, `proposto`) e `apriScuola` dell'elenco. | Dati di prova per `fsl_numeri` (pieni e vuoti) e per i campi nuovi; tre varianti in più della scheda (modulo di modifica aperto, «nuova» con «rinnova», scrivere ai docenti). Le asserzioni della prova passano da circa 30 a 61. |
| `PermessiPagineTest::testIlDocumentoDegliAccessiEAggiornato` | Il documento generato non era aggiornato dopo il passo 9. | Rigenerato con `php strumenti/mappa_accessi.php`. |
| `strumenti/prove/oop/fsl.php` (riga 93) | Chiamava `ServizioPannelloFsl::registro()`, tolto nel passo 9 (il registro sta nella scheda). Dava un errore fatale e interrompeva tutto `esegui.php`. | Tolto solo il controllo su `registro()`; resta quello sul riepilogo dell'anno. Il registro è coperto da `SchedaScuolaFslTest`. |

Incidente: mentre adattavo `CompilazioneSicurezzaTest`, un mio script ha tagliato per errore la prima riga (`<?php`) del file. Visto subito dall'errore «Class … could not be found», la riga è stata rimessa. Poi ho controllato tutti i 273 file PHP modificati o nuovi: tutti cominciano con `<?php` e passano `php -l`.

### Tabella «indirizzo vecchio → arrivo»

Provata in `tests/Integration/Portale/PaginaNumeriFslTest.php` (nessun rimando gira in cerchio) e controllata con `curl` sul portale locale (303 veri).

| Indirizzo vecchio (`admin/fsl.php…`) | Arrivo |
|---|---|
| `?tab=home`, `?tab=iter`, `?tab=convenzioni`, `?tab=stipulare&mostra=tutte` | `fsl_scuole.php` |
| `?tab=stipulare`, `?tab=verifica` | `fsl_scuole.php?cosa=senza_convenzione` |
| `?tab=convenzioni&vista=archivio` | `fsl_scuole.php?stato=scaduta` (con `&scarica=csv` se c'era) |
| `?tab=scuola&k=CODICE` (anche minuscolo), `k=x…` (scritta a mano) | `fsl_scuole.php?k=CODICE`, `?k=x…`; codice non valido: `fsl_scuole.php` |
| `?tab=convenzioni&conv_mod=ID` | `fsl_scuole.php?k=ISTITUTO&modifica=ID` (id che non esiste: `fsl_scuole.php`) |
| `?tab=convenzioni&conv_rinnova=ID&dal=…&al=…` | `fsl_scuole.php?k=ISTITUTO&rinnova=ID&dal=…&al=…` (date false cadono) |
| `?tab=convenzioni&conv_nuova=CODICE[&dal&al]`, `&nuova=1` | `fsl_scuole.php?k=ISTITUTO&nuova=1[&dal&al]`, `fsl_scuole.php?nuova=1` |
| qualunque `tab` con `&anno=2025` o `anno=tutti` | si porta dietro l'anno; un anno strano cade |
| `?tab=riepilogo`, `valutazioni`, `report` | `fsl.php#riepilogo`, `#valutazioni`, `#report` (con `?anno=` se c'era) |
| `?tab=report&scarica=xlsx` o `&xlsx=1`, `?tab=riepilogo&scarica=pdf&tabella=scuole` | `fsl.php?[anno&]scarica=FORMATO&tabella=…` (tabella non ammessa: `attivita`; formato che non esiste: solo la sezione) |
| `fsl.php` senza `tab` | resta «Numeri e report» (200) |
| qualunque indirizzo sopra, per chi ha **solo le convenzioni** | sempre `fsl_scuole.php…`, mai i numeri né i report |
| POST vecchi (`cv_*`, `att_*`, `st_*`, `conv_*`…) | nessun effetto; 303 alla scheda della scuola se si capisce dall'id o dal codice, altrimenti `fsl_scuole.php` |
| ancore `#cv12`, `#fase-caricata`, `#fase-dirfirma`, `#fase-firmata`, `#verifica`, `#elenchi`, `#convForm`, `#registro` | tradotte dallo script sulla pagina di arrivo (`?cv=12`, filtri dell'elenco, riquadri della scheda) |

### Prove, con i numeri veri (25/11/2026)

Una serie alla volta, mai due insieme; MariaDB e server locale accesi; PHPUnit 9.6 con `-d extension=zip -d extension=soap`.

| Serie | Esito | Prove | Asserzioni | Saltate |
|---|---|---|---|---|
| `tests/Integration/Amministrazione` | ok | 15 | 136 | 0 |
| `…/Anagrafi` | ok | 26 | 162 | 0 |
| `…/Ateneo` | ok | 5 | 32 | 0 |
| `…/Attestati` | ok | 14 | 147 | 0 |
| `…/Auth` | ok | 91 | 496 | 0 |
| `…/Avvisi` | ok | 4 | 21 | 0 |
| `…/ConvenzioniTesi` | ok | 48 | 639 | 0 |
| `…/Core` | ok | 7 | 24 | 0 |
| `…/Didattica` | ok | 111 | 978 | 0 |
| `…/Email` | ok | 12 | 1065 | 0 |
| `…/Eventi` | ok | 17 | 205 | 0 |
| `…/Forms` | ok | 77 | 365 | 0 |
| `…/Fsl` (dopo le correzioni) | ok | 205 | 1624 | 0 |
| `…/Infrastructure` | ok | 7 | 30 | 0 |
| `…/Iscritti` | ok | 21 | 205 | 0 |
| `…/Iscrizioni` | ok | 53 | 347 | 0 |
| `…/Portale` | ok | 253 | 6799 | 0 |
| `…/Protocollo` | ok | 80 | 328 | 0 |
| `…/Pubblicazioni` | ok | 58 | 727 | 0 |
| `…/Risorse` | ok | 13 | 165 | 0 |
| `…/Sistema` | ok | 7 | 27 | 1 |
| `…/Sondaggi` | ok | 10 | 86 | 0 |
| `…/Tutorato` | ok | 57 | 748 | 0 |
| **Integration, totale** | **0 rosse** | **1191** | **15 356** | **1** |
| `tests/Unit` (dopo le correzioni) | ok | 743 | 7445 | 1 |
| `avanzamento_nucleo.php --controlla` | «La tabella delle rotte e i gusci coincidono» | | | |
| `mappa_accessi.php --controlla` | «aggiornato» | | | |
| `esegui.php` intero (`rate_limit_attempts` svuotata prima) | «Tutte le 3360 prove superate», uscita 0 | 3360 | | 0 |
| `apache_nucleo.sh` | «Prova Apache del Nucleo: tutto in ordine» | tutte `OK` | | 0 |

**Saltate in tutto: 2** (una in Integration/Sistema, una in Unit), come previsto. In `esegui.php` c'è inoltre la nota «saltate le prove con il servizio di significato» (Ollama con bge-m3 su 127.0.0.1:11434 non c'è in locale): non sono prove saltate di PHPUnit.

Prima delle correzioni: Fsl aveva 2 errori e Unit 2 fallimenti, spiegati sopra e chiusi. Il primo lancio di `esegui.php` si era fermato su `registro()` con un errore fatale; il secondo è passato intero.

### Cosa si è visto nel browser (portale locale, Chrome headless, `strumenti/locale/schermate.py`)

Schermate in `documentazione/schermate_passo9/`, **chiaro e scuro, a 1280 e a 375 px**: «Numeri e report» (`numeri_e_report_*`), il vecchio `fsl.php?tab=iter#cv…` (`vecchio_indirizzo_iter_*`: arriva alla scheda della scuola con l'azione «Controlla la convenzione»), il vecchio `fsl.php?tab=convenzioni&conv_mod=ID#registro` (`vecchio_indirizzo_conv_mod_*`: scheda con «Modifica la convenzione» già aperto), la scheda con la convenzione attiva e «Altro» aperto (`scheda_attiva_altro_*`), la scheda con l'iter aperto (`scheda_iter_aperto_*`), la scheda a fine iter (`scheda_ritocchi_*`) e, per chi ha **solo le convenzioni**, elenco, scheda e `fsl.php` (`solo_convenzioni_*`, solo 1280 chiaro).

Guardate di persona: «Numeri e report» (1280 chiaro, 375 scuro), il vecchio indirizzo dell'iter (1280 chiaro), la scheda con l'iter aperto (375 scuro), la scheda di chi ha solo le convenzioni (1280 chiaro), il vecchio `conv_mod` (1280 scuro), la scheda con «Altro» (375 chiaro). Le altre sono scattate ma non riguardate una per una.

- **Y3**: nel registro la convenzione n. 19 mostra «Originale (firmato dalla scuola e dal Direttore)» e «Documento protocollato (copia del portale con la segnatura)», ciascuno con data e «Scarica», poi «Allegato A».
- **Y4**: in testata c'è un solo pulsante («Controlla la convenzione»); nella scheda «attiva» c'è «Niente da fare ora», senza pulsante.
- **Y6**: «Altro» apre Modifica, «Elimina la convenzione n. 19 dal registro» (in rosso) e «Registrane una già firmata»; con la convenzione valida non c'è «Rimanda il link», solo «Fai aggiornare l'Allegato A al referente».
- **`curl`, 303 veri**: `?tab=iter` va a `fsl_scuole.php`; `?tab=stipulare` a `?cosa=senza_convenzione`; `?tab=scuola&k=…` a `?k=…`; `?tab=convenzioni&vista=archivio` a `?stato=scaduta`; `conv_mod`, `conv_rinnova` e `conv_nuova` alla scheda con il modulo aperto; `?tab=riepilogo` a `fsl.php#riepilogo`; `fsl.php` senza `tab` risponde 200.
- **Chi ha solo le convenzioni**: il menu ha «Scuole e convenzioni» e non ha «Numeri e report», «Aree» né «Anagrafe». `fsl.php` lo rimanda a `fsl_scuole.php`.
- **375 px**: nessuno scorrimento orizzontale su elenco, scheda e «Numeri e report» (larghezza della pagina uguale a quella della finestra, misurata con uno script). Gli unici elementi oltre il bordo sono i pulsanti dentro il menu «Altro» quando è chiuso (non si vedono); aperto sta nel bordo. Tema scuro leggibile.

### Limiti (da sapere)

- Le schermate usano la convenzione di prova della scuola «Volta», portata nei vari punti dell'iter con `scenario_fsl.php`. L'abilitazione «solo convenzioni» è stata data all'utente di prova n. 6 solo per le schermate. Sono dati locali, non del progetto.
- Nel browser non ho provato a mano un POST vecchio: lo provano le prove automatiche (`PaginaNumeriFslTest`).
- Ollama non c'è in locale: le prove sul «servizio di significato» di `esegui.php` saltano da sole, come prima.
- Alla fine ho rimesso il database locale com'era prima delle schermate (copia fatta all'inizio) e tolto i file creati. Lo scenario `nuova` cancella però all'inizio le email intercettate in `cache/email_locali/` (13 file di prova, cartella ignorata da git): non si sono potute rimettere.
- Tutte le prove del Titulus sono col doppio finto: nessuna chiamata al Titulus vero.
- Passo 10 (controllo finale, istruzioni di aggiornamento, commit, pacchetto): **non iniziato**.

## Passo 10 — Controllo finale (25/11/2026, non committato)

Il passo 9 è approvato e la suite intera era già verde su questo codice (Integration 1191, Unit 743, `esegui.php` 3360, `apache_nucleo.sh` in ordine, 2 saltate): **non è stata rilanciata**. In questo giro sono state fatte solo le prove mirate qui sotto. Nessun commit, nessuna chiamata al Titulus vero.

### A) Due ritocchi

| Ritocco | Cosa è cambiato | File |
|---|---|---|
| 1 | La riga «Autorizzazione» (e il suo link a `autorizzazione_scuola.php`) nella scheda della scuola si mostra solo con `$puoFsl`, come il numero degli studenti (Y5). Prima la vedeva anche chi ha solo le convenzioni. | `viste/pannello/_fsl_scuole_eventi.php` (una condizione) |
| 2 | `bash strumenti/locale/avvia.sh --ferma` ferma anche il server PHP sulla porta del portale (8080): lo cerca dalla porta con `netstat` e lo chiude con `taskkill //PID`. In questo Git Bash `pkill` non esiste, quindi la riga che lo usava non fermava niente (il `taskkill` per titolo di finestra è rimasto). | `strumenti/locale/avvia.sh` |

Prova nuova: `RitocchiSchedaTest::testLaRigaAutorizzazioneDellaSchedaSiVedeSoloConLaFslCompleta` (stessa scheda aperta dall'amministratore e da chi ha solo le convenzioni: la riga e il link ci sono solo per il primo). **Controllata anche al contrario**: tolta per un attimo la condizione `$puoFsl` la prova diventa rossa (1 fallita), rimessa torna verde.
`--ferma` provato davvero: avviato un server PHP sulla 8080 (rispondeva), lanciato `--ferma`, subito dopo né la 8080 né la 3306 erano in ascolto e non c'era nessun `php.exe` né `mysqld`.
Altro codice non toccato.

### B) Prove di questo giro (MariaDB accesa)

| Prova | Esito | Prove | Asserzioni |
|---|---|---|---|
| `tests/Integration/Portale/RitocchiSchedaTest.php` | ok | 10 | 25 |
| `tests/Unit/Http/VistaTest.php` | ok | 23 | 293 |
| `php -l` su `_fsl_scuole_eventi.php` e `RitocchiSchedaTest.php`; `bash -n` su `avvia.sh` | nessun errore | | |
| `avanzamento_nucleo.php --controlla` | «La tabella delle rotte e i gusci coincidono» | | |

### Migrazione da v135 a v145 (copia di database, due volte)

Come: database usa e getta `mig_v135`, creato con **il codice di HEAD** (`f740594`, estratto con `git archive` in una cartella temporanea fuori da Drive, schema v135, 121 tabelle). Dentro, dati vecchi come quelli del server: 7 compilazioni FSL (due **doppie** sullo stesso istituto, una vuota, una caricata, una rifiutata, una da firmare, una protocollata col registro), 3 convenzioni di tesi (invitata, protocollata, da firmare), 2 righe di registro e la configurazione di Titulus. Fatta una copia con `mysqldump`, poi la migrazione con il **codice nuovo**: prima volta, poi seconda volta **togliendo il marcatore `schema_v145_*.ok`** perché rifacesse davvero tutto.

| Controllo | Esito |
|---|---|
| Versione | 135 → 145, `aggiorna()` vero, uscita 0 tutte e due le volte |
| Tabelle | 121 → 124; nuove: `convenzioni_allegati`, `protocollo_file`, `scuole_docenti`, tutte **vuote** |
| Colonne nuove | `convenzioni_compilate` +21, `convenzioni_tesi` +10, `titulus_config` +2, `titulus_invii` +1; tutte le colonne attese (v136-v145) presenti |
| Colonne tolte o cambiate | **0** (confronto riga per riga di `information_schema.COLUMNS`) |
| Interruttori `automatico_fsl` e `automatico_tesi` | **0 (spenti)**, con Titulus ancora attivo e il resto della configurazione uguale |
| Valori delle colonne nuove sulle righe vecchie | di partenza: `rivendicata_il` e `proprietari_json` NULL, `nascita_ok` 1, `originale_stato` vuoto, `originale_tentativi` 0, file e validità NULL |
| **Dati vecchi** | impronta MD5 delle colonne già esistenti **identica prima e dopo** per compilazioni (7), tesi (3), registro (2) e configurazione di Titulus |
| **Seconda volta** | schema **identico** e `mysqldump` completo **identico** alla prima (nemmeno una riga o un'ora cambiata) |
| `elenco_convenzioni_doppie.php` sulla copia | vede le due scuole con più compilazioni (CSIS00001A: n. 1, 2, 7; CSIS00002B: n. 3, 5), uscita 1 come previsto, non cambia niente |

Database di prova tolto alla fine. Non sono stati toccati né il database locale `eventi_locale` né il `.env`.

### C) Istruzioni di aggiornamento

`documentazione/istruzioni_aggiornamento_convenzioni.txt`: schema, cosa il pacchetto non porta, tempo 0 (post_max_size ≥ 25M, `elenco_convenzioni_doppie.php`, `controllo_email_personalizzate.php --chi-perde`, conteggi), tempo 1 (copia di prova `mydibest_pp`, cartelle `uploads/` nuove scrivibili e chiuse al web con prova del 403), tempo 2 (sito vero: backup di database e file, estrazione, controlli, interruttori ancora a 0), tempo 3 (protocollo automatico: prima solo in copia), ritorno indietro.
**Non provato**: i comandi del server (nessun accesso da qui). Sono quelli dei modelli già usati nelle istruzioni di prima, adattati.
Da sapere: `strumenti/` non entra nel pacchetto (`crea_pacchetto.sh` lo esclude): i due strumenti di controllo vanno copiati a mano sul server (passo P3). Le tre cartelle nuove sono `uploads/convenzioni/allegati`, `uploads/firme_conservate`, `uploads/protocollo_file`: le crea e le chiude il codice al primo uso (`Upload::cartellaProtetta`), le istruzioni le fanno creare prima per controllarle.

### D) Commit

Messaggio: `documentazione/commit_convenzioni.txt` (italiano, senza nessuna attribuzione). Elenco: `documentazione/commit_convenzioni_file.txt` (284 file, più questi due; 3 sono cancellazioni già in staging). **Non committato.**
Esclusi dall'elenco, da decidere con l'utente: `RIPRENDI_DA_QUI.md`, `strumenti/locale/copia_ambiente.ps1`, `ripristina_ambiente.ps1`, `.gitignore`, `documentazione/mappa/`.
Lasciati fuori anch'io, fuori dalla richiesta: `documentazione/schermate_passo8` e `schermate_passo9` (14 MB di immagini, nessuna schermata è tracciata finora), `documentazione/prova_titulus/` (contiene un PDF), `PASSAGGIO_GATEWAY.txt`, `schizzo_pagina_scuole.html`.

### Limiti (da sapere)

- Le prove di questo giro sono mirate come chiesto: la suite intera **non è stata rilanciata dopo** i due ritocchi. Il ritocco 1 cambia una vista e `RitocchiSchedaTest`/`VistaTest` la coprono; `esegui.php` e `apache_nucleo.sh` non l'hanno rivista.
- La migrazione è provata con dati inventati ma fatti come quelli veri; non è stata provata sul database vero del server.
- Nessuna chiamata al Titulus vero; i comandi per il server delle istruzioni non sono stati eseguiti.
- Server PHP e MariaDB fermati alla fine.
