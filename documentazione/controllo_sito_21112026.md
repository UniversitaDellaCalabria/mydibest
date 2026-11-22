# Controllo generale del portale MyDiBEST — 21/11/2026

Controllo fatto sul codice di `main` (ultimo commit d80ede2), sulla configurazione Apache in `strumenti/apache_ubuntu/` e sulla copia locale.
Nessuna modifica al codice: solo osservazioni e proposte. Le voci sono in ordine di importanza dentro ogni sezione.

**Giudizio complessivo:** la base di sicurezza è buona e curata. CSRF su tutti i moduli (mancano solo `install.php`, che è bloccato, e `sondaggio.php`, protetto dal suo token).
Query con segnaposto, nessun dato della richiesta stampato senza `h()`/`htmlspecialchars`, file scaricati sempre tramite PHP con controllo del proprietario.
Cookie di sessione `Secure`/`HttpOnly`/`SameSite`, intestazioni di sicurezza, limiti di tentativi sulle pagine pubbliche, verifica dell'email con codice.
Tutti i file PHP passano il controllo di sintassi. I problemi veri sono pochi e circoscritti. Il margine più grande sta nell'ordine del pannello e nell'uniformità grafica.

---

## 1. Vulnerabilità, difetti e anomalie

### Da correggere subito

| # | Cosa | Dove | Perché conta | Proposta |
|---|------|------|--------------|----------|
| V1 | **Link sbagliati nelle email automatiche se i processi partono da crontab** (`php cron_background.php`, come consiglia il README) | `cron_background.php:52`, `cron_attestati.php:34` | Da riga di comando `HTTP_HOST` non esiste: il link all'Area personale nelle email post-evento diventa `https://il-tuo-sito.unical.it/...`, quello all'attestato `https://localhost/...`, e in più `PHP_SELF` contiene il percorso del disco | Usare `url_base_sito()` (che da riga di comando legge `URL_SITO` del `.env`), come fa già `ServizioCron` per l'elenco studenti. Prima controllare sul server come sono lanciati (CLI o URL con `?key=`) |
| V2 | **Ricevuta leggibile da chiunque abbia il codice, senza accesso e senza limite di tentativi** | `stampa_ricevuta.php:22-24` | Il codice è di 2 lettere prevedibili + 8 cifre esadecimali (32 bit). Senza limite di tentativi si può provare a indovinarlo, e la ricevuta mostra nome, email e campi personalizzati | Aggiungere `check_rate_limit` (come in `verifica_attestato.php`) oppure chiedere l'accesso quando chi apre non è il proprietario. A medio termine, codici più lunghi per i nuovi link |
| V3 | **Indirizzi costruiti dall'intestazione `Host` della richiesta** | `admin/iscritti.php:214`, `admin/stampa_qr_aula.php:40`, `admin/sondaggi.php:157`, `stampa_ricevuta.php:35`, `master_template.php:101,128`, `miei_eventi.php:60,77`, `programma_fsl.php:46`, `esci.php:32`, `src/Core/Sito.php:31` | Una richiesta con un `Host` falso può far finire un dominio estraneo nei link delle email e nei QR. Su un Apache con un solo sito virtuale l'intestazione arriva così com'è | Prendere sempre `URL_SITO` dal `.env` anche dal web, oppure accettare solo i nomi ammessi (`dibest2.unical.it`). Un'unica funzione, da usare in tutti i 13 punti |
| V4 | **Dati personali nella cartella sincronizzata con Google Drive** | `uploads/pratiche` (251 file), `uploads/convenzioni` (38), `materiali/` (24), archivi da 14 MB nella radice, inventari Apache/MariaDB `.txt` | Sono esclusi da git, ma Drive li copia sul cloud personale. Se sono dati veri (pratiche di studenti, convenzioni firmate) il problema è di trattamento dei dati (GDPR), non di codice | Verificare se sono dati di prova. Se sono veri, spostarli fuori da Drive (es. `C:\progetti`) e togliere gli archivi vecchi dalla radice |

### Da sistemare a breve

| # | Cosa | Dove | Proposta |
|---|------|------|----------|
| V5 | **Incorporamento nell'e-learning probabilmente bloccato.** Apache permette `frame-ancestors` per `elearning.unical.it`, ma il PHP aggiunge `X-Frame-Options: SAMEORIGIN` e un secondo CSP con `frame-ancestors 'self'`. Il browser applica il più restrittivo | `config.php:43,49` e `00-siti.conf:17` | Decidere se il portale va incorporato. Se sì, mettere gli stessi domini in `config.php` e togliere `X-Frame-Options`; se no, togliere la riga da Apache per non creare confusione |
| V6 | **«Accesso negato» con codice 200.** Le pagine admin stampano l'avviso ed escono senza `http_response_code(403)` | ~20 pagine in `admin/` | Una sola funzione `nega_accesso_pannello()` con il 403, da usare ovunque |
| V7 | **Protezione del pannello pagina per pagina.** `admin_header.php` fa entrare qualunque utente autenticato: ogni pagina deve ricordarsi di controllare i permessi. Oggi tutte e 80 lo fanno (verificato), ma basta una pagina nuova senza controllo per aprire un buco | `admin/admin_header.php` | «Negato se non previsto»: una tabella pagina → permesso letta da `admin_header.php`, che blocca da sola le pagine non elencate |
| V8 | **46 reindirizzamenti fatti in JavaScript** (`echo "<script>window.location.replace(...)"`) invece che con `header('Location')`. Senza JavaScript la pagina resta bianca, e obbligano a tenere `'unsafe-inline'` nel CSP | `admin/*.php` (`admin_redirect`), `saml_login.php:48` | Visto che l'`ob_start()` è sempre attivo, `header('Location: …')` funziona: un helper `vai_a($url)`. In `saml_login.php` usare `json_encode` invece di `htmlspecialchars` dentro lo script |
| V9 | **Copia locale senza stili dopo il cambio di nome.** Il router locale serve `/eventi/`, ma `Sito::urlBase()` ripiega su `/mydibest`: CSS, Bootstrap e Font Awesome rispondono 404 e le pagine si vedono nude | `src/Core/Sito.php:34`, `strumenti/locale/router.php:13-17` | Portare il router su `/mydibest/`, oppure ricavare il percorso da `SCRIPT_NAME` invece che dal disco (più robusto anche sul server con collegamenti simbolici) |
| V10 | **Versioni diverse di PHP**: 8.4 sul server (`10-php84.conf`), 8.2 in locale | — | Allineare il PHP locale a 8.4, così le prove vedono gli stessi avvisi del server |

### Minori / di pulizia

- `X-XSS-Protection` è superata e i browser la ignorano: si può togliere. Al CSP si possono aggiungere `base-uri 'self'; form-action 'self'; object-src 'none'`.
- `risorsa_ics.php`: anche qui basta il codice `RS-` + 8 esadecimali. Il file mostra poco, ma un limite di tentativi non costa niente.
- `install.php` finisce nel pacchetto. È bloccato da `.htaccess` e si ferma da solo, ma è meglio escluderlo in `crea_pacchetto.sh`.
- 161 soppressioni con `@` (`@file_get_contents`, `@unlink`…): nascondono errori utili nei log. Da ridurre quando si tocca il file.
- I messaggi dell'area personale sono salvati come HTML in `$_SESSION['msg_area_pers']`. Oggi sono tutti scappati correttamente, ma è fragile: meglio `flash_set()` come nel pannello.
- TinyMCE 6.8.6 (`assets/vendor/tinymce`): verificare se la serie 6 è ancora supportata e valutare il passaggio alla 7.
- La radice della cartella di lavoro è affollata (15 archivi, 4 `.txt` di inventario, 17 `commit_*.txt` non tracciati in `documentazione/`): spostarli in una cartella `archivio_locale/` esclusa da git.

---

## 2. Pannello admin: riorganizzazione

### Com'è oggi
Il menu è già diviso in moduli (Orientamento/eventi, FSL, Didattica, MyForms, Amministrazione, Portale) con «La mia scrivania». Restano però alcuni punti critici:

- **Didattica è un contenitore troppo grande:** fino a 14 voci (pratiche, sedute, moduli, ricevimento, uffici, tirocini, sostituzioni, statistiche, tirocini motorie, assistenza, convenzioni di tesi, tutorato…).
- **«Portale» mescola quattro mestieri diversi:** anagrafiche, aspetto del sito pubblico, utenti e permessi, sistema.
- **«Uffici e persone» compare in due moduli** (Didattica e Portale).
- **Tre modi diversi di navigare:** `?tab=` (didattica, amministrazione), `?vista=` (anagrafe, pubblicazioni), pagine separate. Chi usa il pannello non capisce se sta cambiando pagina o scheda.
- **Pagine con troppi pulsanti:** `fsl.php` 50, `tutorato.php` 47, `didattica_pratiche.php` 39, `didattica_sedute.php` 36, `didacticus.php` 32, `iscritti.php` 28.

### Proposta: 9 macroaree con sezioni

1. **Scrivania.** Una sola lista «Cosa devo fare» con i numeri di tutti i moduli a cui si ha accesso (oggi è solo in Didattica e Amministrazione) e le ultime cose aperte.
2. **Eventi e orientamento.** Aree → Attività e turni → Iscritti e presenze → Attestati e sondaggi → Statistiche.
3. **Scuole e FSL.** Convenzioni, Progetti, Scuole (spostate qui dall'anagrafe), Valutazioni.
4. **Didattica**, in 4 gruppi richiudibili:
   - *Pratiche e Consiglio*: pratiche, sedute, verbali, moduli;
   - *Tirocini e tesi*: tirocini, Scienze motorie, convenzioni di tesi;
   - *Tutorato*;
   - *Sportello*: ricevimento, assistenza, Didacticus.
5. **Amministrazione.** Modulistica, Richieste di pubblicazione, Da compilare.
6. **MyForms.**
7. **Persone e permessi.** Utenti e abilitazioni, Uffici e persone (una sola volta), anagrafe di docenti, PTA, insegnamenti e corsi.
8. **Sito pubblico.** Testata e home, Menu, Notizie, Contatti, Riquadri dell'area personale.
9. **Sistema.** Protocollo Titulus, Testi delle email, Registri, Backup, «Entra come…».

### Passi più intuitivi
- **Un solo schema di navigazione:** le sezioni di un modulo sono sempre pagine con indirizzo proprio, più un percorso in alto («Didattica › Pratiche › PR-1A2B»). I vecchi `?tab=` reindirizzano.
- **«Cosa devo fare» in ogni modulo:** lo schema che ha funzionato in Didattica e Amministrazione va esteso a FSL, Tutorato ed Eventi.
- **Procedure lunghe con una barra dei passi numerati** (un componente unico, riusato):
  - *Nuova attività*: 1 Dati → 2 Turni → 3 Modulo di iscrizione → 4 Pubblica;
  - *Tutorato*: Bando → Commissione → Graduatoria → Incarichi → Registro → Pagamenti;
  - *Pratica*: Arrivata → Istruttoria → Seduta → Verbale → Protocollo.

  Ogni passo mostra cosa manca per andare avanti.
- **Al massimo 1-2 azioni principali visibili per pagina;** le altre vanno in «Altro ▾» (già fatto per le convenzioni FSL in F16.1: estenderlo a tutorato, pratiche, sedute, Didacticus).
- **Ricerca unica del pannello** (`cerca.php` esiste già) sempre in alto, con la scorciatoia Ctrl+K.
- **Conferme coerenti:** al posto dei 29 `confirm()` del browser, una finestra unica. Per le eliminazioni definitive si chiede di scrivere il nome, come già succede per le aree.
- **Pagine vuote che spiegano cosa fare:** «Non hai ancora attività: crea la prima» con un pulsante.

---

## 3. Area personale

**Sicurezza: buona.**
- `middleware.php` obbligatorio e controllo del proprietario su pratiche, allegati, documenti di scuola e tutorato.
- Cambio email con codice a 6 cifre e limite di tentativi.
- «Vedi come» riservato all'amministratore; «Entra come…» in sola lettura (POST e azioni GET bloccati).

Restano da sistemare:
- la ricevuta raggiungibile con il solo codice (V2);
- i link delle email (V1, V3);
- una regola da fissare: ogni azione via GET deve portare il `csrf`. È ciò che fa scattare il blocco di «Entra come…», e una nuova azione GET senza `csrf` passerebbe.

**Intuitività: proposte.**
- **«Il prossimo passo» in cima:** una sola tessera grande con la cosa più urgente («Firma la lettera d'incarico entro venerdì»), prima delle icone. Oggi «Da fare» è una tessera tra le altre.
- **Percorsi con barra di avanzamento:** tesi, tirocinio, pratica e tutorato hanno tappe. Lo stesso componente a passi del pannello, in piccolo, dentro la tessera.
- **Numero sulle tessere** (messaggi non letti, firme da fare) e tessere vuote nascoste invece che «niente da mostrare».
- **Telefono:** il portale è già un'app installabile (`manifest.json`, `sw.js`). Una barra in basso con 5 voci (Home, Da fare, Calendario, Messaggi, Profilo) la renderebbe davvero un'app.
- **Profili multipli:** il filtro «Tutto / Studente / Docente…» funziona. Basta ricordare l'ultima scelta.

**Grafica.** L'intestazione con iniziali, chip dei profili e tema scuro è curata. Va resa uguale nelle pagine figlie (`miei_eventi.php`, `profilo.php`, `il_mio_tutorato.php`…), che hanno ancora stili propri.

---

## 4. Restyling front-end e back-end

### Front-end
- **Due sistemi grafici:** Bootstrap Italia 2.8.3 nel sito pubblico, Bootstrap 5.3.0 nel pannello, più tre fogli propri. Conviene tenerli, ma con **variabili di colore comuni**. Oggi i colori sono scritti a mano centinaia di volte: `#B30000`/`#b30000` 99, `#0056B3`/`#0056b3` 92, `#990000` 29, `#dc3545` 13. Basta un `:root { --dibest-rosso; --dibest-blu; … }` in un solo file.
- **1.703 stili in linea e 86 file con un blocco `<style>`:** spostarli a poco a poco in classi di `portale-ui.css` / `pannello.css`. Ogni modifica grafica si farà in un punto solo, e un domani si potrà togliere `'unsafe-inline'` dal CSP (insieme ai 48 `onclick=`).
- **Tema scuro incompleto nel pannello:** 30 regole in `portale-ui.css`, 4 in `pannello.css`.
- **Accessibilità** (obbligatoria per una PA, linee guida AgID):
  - 24 immagini senza `alt`;
  - 249 caratteri piccoli impostati in linea (sotto 0,8rem/12px);
  - circa 400 campi da controllare per l'etichetta collegata;
  - serve una **dichiarazione di accessibilità** pubblicata (form AgID).

  Un passaggio con Lighthouse/axe sulle 10 pagine più usate dà la lista precisa.
- **`master_template.php` ha 2.449 righe:** è la pagina di ogni area. Dividerla in parti (testata dell'area, elenco attività, scheda turno, modulo di iscrizione) aiuta sia la grafica sia la manutenzione.

### Back-end
- **Pagine admin molto lunghe che mescolano azioni e HTML** (`fsl.php` 1.156 righe, `iscritti.php` 1.116, `didattica_sedute.php` 1.014, `eventi.php` 978). Il modello delle schede Didattica e Amministrazione (fase «azioni» separata dalla vista) funziona: estenderlo pagina per pagina, insieme allo spostamento in `src/` già avviato.
- **Prove:** la suite ha 20 prove HTTP già rosse da tempo. Riportarle al verde rende utile la suite prima di ogni pacchetto. Aggiungere poi una prova per V1 (link delle email da riga di comando) e una per V7 (ogni pagina admin nega l'accesso a un utente senza permessi).
- **Pacchetti di aggiornamento:** mostrare in Sistema la versione caricata (commit e data del pacchetto) aiuta a capire cosa gira davvero sul server e nella copia di prova.
- **Controllo di salute:** una pagina di Sistema che verifica cron (ultimo giro), spazio disco, backup, certificato (scade il 06/02/2027) e invio email, con un avviso agli amministratori.

---

## 5. Ordine di lavoro proposto

1. **Subito:**
   - V1 (link email da cron): controllare prima come gira il crontab sul server;
   - V2 (limite di tentativi sulla ricevuta);
   - V4 (dati personali su Drive): basta una verifica.
2. **Breve:**
   - V3 (`URL_SITO` unico);
   - V5 (decidere sull'incorporamento);
   - V6 + V7 (pannello «negato se non previsto»);
   - V9 (copia locale su `/mydibest`), indispensabile per lavorare sulla grafica.
3. **Medio:**
   - variabili di colore comuni;
   - riorganizzazione del menu nelle 9 macroaree, mantenendo i vecchi indirizzi;
   - componente a passi;
   - «Cosa devo fare» in ogni modulo.
4. **Area personale:** «Il prossimo passo», barre di avanzamento, barra in basso sul telefono.
5. **Lungo:** accessibilità con dichiarazione AgID, stili in linea → classi, CSP senza `'unsafe-inline'`, divisione di `master_template.php` e delle pagine admin più lunghe.
