# MyForms — tutto quello che serve per continuare da casa

Questo file sostituisce i blocchi di `comandi_sonnet_myforms.md` (quelli sono più vecchi).

Metodo: **Sonnet scrive** un passo alla volta, in una sessione nuova per ogni passo. **Opus rivede**: una sessione aperta da tenere per tutta la serata, dove incolli i resoconti di Sonnet.

---

## 0. Dove siamo

Aggiornato: 17/11/2026 sera. **Tutto il lavoro è fatto e approvato; un solo commit** (i passi condividono gli stessi file e non si possono più separare).

| Passo | Cosa | Stato |
|---|---|---|
| P0 | `App\Auth\Pubblico` (profili unici) | fatto, nel commit unico |
| P1-P4 | tabelle `forms*`, `src/Forms/`, pannello, compilazione (schema v124-v125) | fatto, nel commit unico |
| P5 | risposte, riepilogo, Excel/CSV/PDF | fatto, nel commit unico |
| P6 | cron (chiusura e conservazione), ricerca, guida (schema **v126**) | fatto, nel commit unico |
| X1 | inventario degli accessi (`documentazione/inventario_accessi.md`) | fatto, nel commit unico |
| X2a | fondamenta: `GruppiUtente`, `AccessoEvento`, scuole dette chiaramente | fatto, nel commit unico |
| X2b, X2c | menu, poi eventi (`pubblico_json`) | da fare dopo il commit (vedi sezione 9) |
| A0 | materiali con la segreteria amministrativa | con l'utente, prima del codice |

## 1. Commit da fare

Controlla con `git log --oneline -3`: se non vedi «MyForms, inventario degli accessi e fondamenta X2a», fallo così. Un solo commit: il messaggio è in `documentazione/commit_MyForms.txt` (con in fondo la stessa lista di file).

```bash
git add admin/admin_header.php admin/forms.php admin/forms_allegato.php admin/forms_risposte.php admin/parziali/costruttore_campi.php admin/utenti.php cron_background.php documentazione/guide/guida_myforms.md documentazione/inventario_accessi.md documentazione/myforms_da_casa.md form.php forms.php functions.php inc/area/miei_form.php inc/aspetto.php inc/auth.php inc/sezioni.php master_template.php ricerca.php saml_login.php src/Auth/Abilitazioni/Perimetri.php src/Auth/GruppiUtente.php src/Auth/Pubblico.php src/Auth/RitornoInterno.php src/Auth/Vista/PubblicoVista.php src/Core/Schema/Definizioni.php src/Core/Schema/Migrazioni.php src/Eventi/AccessoEvento.php src/Forms/ControlliAnonimi.php src/Forms/CostruttoreCampi.php src/Forms/CronForms.php src/Forms/EsportazioneRisposte.php src/Forms/FiltriRisposte.php src/Forms/FormRepository.php src/Forms/NotificheForms.php src/Forms/RiepilogoRisposte.php src/Forms/RispostaRepository.php src/Forms/ServizioForms.php src/Forms/ServizioRisposte.php src/Fsl/ServizioProgrammaFsl.php src/Iscrizioni/ServizioPrenotazioni.php src/Portale/Area/CatalogoRiquadri.php src/Portale/Area/ProfiliPersona.php src/Portale/GuidePerRuolo.php src/Portale/RicercaGlobale.php src/Portale/Vista/Testi.php src/Risorse/RisorsaRepository.php src/Risorse/ServizioRisorse.php strumenti/prove/esegui.php strumenti/prove/oop/da_fare_tutorato.php tests/Integration/Auth/GruppiUtenteTest.php tests/Integration/Forms/CompilazioneTest.php tests/Integration/Forms/CronFormsTest.php tests/Integration/Forms/FormsBase.php tests/Integration/Forms/RicercaModuliTest.php tests/Integration/Forms/RisposteGestioneTest.php tests/Integration/Forms/ServizioFormsTest.php tests/Integration/Fsl/ProgrammaFslAccessoTest.php tests/Integration/Risorse/RisorseIntegrazioneTest.php tests/Unit/Auth/PubblicoTest.php tests/Unit/Auth/RitornoInternoTest.php tests/Unit/Eventi/AccessoEventoTest.php tests/Unit/Forms/CostruttoreCampiTest.php tests/Unit/Forms/EsportazioneRisposteTest.php tests/Unit/Forms/FiltriRisposteTest.php tests/Unit/Forms/NotificheFormsTest.php tests/Unit/Forms/RiepilogoRisposteTest.php tests/Unit/Portale/TestiVistaTest.php
```

```bash
git commit -F documentazione/commit_MyForms.txt
```

Dopo il commit puoi cancellare i `documentazione/commit_*.txt` (P0, P1P2, P1P3, P1P4, P3, P4, P5, P6, X2a, MyForms) e `comandi_sonnet_myforms.md`. Poi push e caricamento sul server (lo schema passa a **v126** da solo).

Attenzione: **mai `git stash`** (la cartella è su Google Drive). `strumenti/crea_ambiente_prova.sh`, `strumenti/apache_ubuntu/` e gli altri file in `strumenti/` NON fanno parte di MyForms: non aggiungerli (fanno eccezione `strumenti/prove/esegui.php` e `strumenti/prove/oop/da_fare_tutorato.php`, già nell'elenco).

---

## 2. TESTO PER IL REVISORE OPUS (incollalo per primo in una sessione Opus nuova)

```
Sei il revisore del lavoro che Sonnet fa sul portale MyDiBEST (PHP 8.2, MariaDB, cartella corrente). Non scrivi tu il codice delle funzioni: leggi i resoconti di Sonnet che ti incollo, controlli il codice vero sul disco e mi dici se approvarlo o cosa correggere.

Leggi prima:
- documentazione/piano_myforms_amministrazione_accessi.md (il piano)
- documentazione/myforms_da_casa.md (stato, decisioni, blocchi per Sonnet)

Decisioni già prese (non rimetterle in discussione):
- Nome MyForms, codice in src/Forms, tabelle forms / forms_gestori / forms_risposte (schema v124), modulo 'forms' del pannello (colore #be123c).
- Si può rispondere anche senza accesso: domanda anti-robot + campo trappola + limite per IP; niente allegati senza accesso.
- Pubblico unico: App\Auth\Pubblico (profili anonimi, registrati, studenti, docenti, pta, personale, esterni, scuole, aziende; 'anonimi' = chiunque; gli amministratori passano sempre).
- Form anonimi: utente_id ed email NON salvati; utente_impronta = sha256(form_id|utente_id|CIFRATURA_CHIAVE) per «una per persona» e per ritrovare la propria risposta. Nella guida: «anonimo per chi gestisce il form», non «per tutti».
- Il costruttore di MyForms (admin/parziali/costruttore_campi.php) è scritto da zero: la Didattica (admin/didattica_moduli.php) NON va toccata.
- Tipi esclusi da MyForms: gruppo «Dalle anagrafi» e colonne della Didattica.
- I co-gestori entrano nel modulo anche senza abilitazione ($puo_forms_vedere) ma non creano form nuovi.
- Descrizione HTML ripulita con pulisci_html_semplice() al salvataggio e in uscita.
- Rimandato a P6: la validazione delle date controlla solo il formato, non che la data esista (es. 2026-13-45).

Come rivedi ogni passo:
1. Leggi i file creati/modificati (git status, git diff, file nuovi per intero).
2. Controlla: permessi lato server su ogni azione (anche GET), csrf_verify su ogni POST, escape dell'output, niente ALTER/CREATE nelle pagine, niente BOM, riuso del codice esistente invece di duplicarlo, testi in italiano semplice, che la Didattica e le prenotazioni non cambino comportamento.
3. Per le pagine pubbliche: XSS, open redirect nel ritorno dopo il login, invii doppi (F5), CAPTCHA/trappola/limite davvero controllati sul server, form anonimi senza nome né email da nessuna parte (pagina, email di notifica, esportazioni, log).
4. Rifai tu lint (C:\xampp\php\php.exe -l) e i test della cartella toccata: PHPUnit 9.6 phar in %TEMP%\phpunit96, con percorsi ASSOLUTI (--bootstrap "<TEMP>\phpunit96\bootstrap.php" --no-configuration <cartella>). Se a questo PC manca %TEMP%\phpunit96, scarica il phar di PHPUnit 9.6 lì e crea un bootstrap.php che fa require di vendor/autoload.php del progetto se c'è, altrimenti dell'autoloader in tests/ (guarda phpunit.xml). Per i test col database: avvia mysqld di XAMPP con --defaults-file=C:/xampp/mysql/bin/my.ini --standalone e alla fine fermalo con mysqladmin -uroot shutdown.
   Fallimenti già presenti prima di MyForms (non contano): ZipArchive, xlsx, ConvenzioniTesi, Portale widget home (testWidgetHomeNormalizzaIlSalvato), Risorse testCostantiDelleRisorse, Tutorato, Integration DaFareEdEmailTest «veicolo».
5. Dammi: verdetto (approvato / da correggere), l'elenco puntato dei problemi in parole semplici, e se servono correzioni un blocco di testo pronto da incollare a Sonnet nella STESSA sessione. Se è approvato: i comandi git add/commit da copiare (file elencati uno per uno, presi dall'elenco in fondo a documentazione/commit_<passo>.txt e confrontati con git status) e il blocco del passo successivo preso da documentazione/myforms_da_casa.md.

Regole per te:
- NON eseguire comandi git che modificano qualcosa (add, commit, push, stash, checkout, reset): dai a me i comandi da copiare. Mai git stash.
- Nei messaggi di commit niente Co-Authored-By né riferimenti a Claude.
- Rispondi in italiano, semplice.
```

---

## 3. REGOLE COMUNI PER SONNET (vanno in testa a ogni passo: sono già dentro i blocchi qui sotto)

Ogni blocco qui sotto è completo: aprilo in una **sessione Sonnet nuova** e incollalo intero.

---

## 4. Passo P4 — Compilazione (sessione Sonnet nuova)

```
Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. Leggi prima documentazione/piano_myforms_amministrazione_accessi.md: fai SOLO il passo indicato sotto.

Regole del progetto:
- Nessun ALTER/CREATE nelle pagine: le tabelle si cambiano solo in src/Core/Schema/Definizioni.php alzando Migrazioni::VERSIONE.
- Codice nuovo in src/<Modulo>/ (classi con declare(strict_types=1), il contenitore le collega da solo); funzioni-facciata in inc/ solo se servono alle pagine. Non ridefinire funzioni già presenti.
- File PHP senza BOM UTF-8. Escape di tutto l'output. Ogni POST con csrf_verify e controlli lato server. Flash con flash_set (senza escape dentro).
- Riusa ciò che c'è: src/Forms (ServizioForms::puoRispondere / salvaRisposta / modificaRisposta, già fatti e testati: non riscriverli), App\Auth\Pubblico, CampiModulo + LetturaRisposte, html_campo_pratica + assets/js/campi-pratica.js + js_tabelle_pratica(), pulisci_html_semplice() per la descrizione, App\Iscrizioni\CaptchaPrenotazione (domanda anti-robot già usata dalle prenotazioni pubbliche: riusala o generalizzala senza cambiare il comportamento delle prenotazioni) e App\Auth\LimiteRichieste (limite per IP, come in ServizioPrenotazioni), l'invio email già usato dal portale, lo stile delle tessere dell'area personale.
- Testi in italiano semplice, niente gergo tecnico.
- Test: PHPUnit 9.6 in %TEMP%\phpunit96, percorsi assoluti (--bootstrap "%TEMP%\phpunit96\bootstrap.php" --no-configuration <cartella>), una cartella alla volta; per i test col database avvia mysqld di XAMPP con --standalone e fermalo alla fine. Fallimenti GIA' presenti (ZipArchive, xlsx, ConvenzioniTesi, Portale widget home, Risorse testCostantiDelleRisorse, Tutorato, Integration DaFareEdEmailTest «veicolo»): confronta prima/dopo e dimmi solo le differenze.
- C:\xampp\php\php.exe -l su ogni file toccato.
- MAI git stash (cartella su Google Drive). NON fare commit, push o altri comandi git che modificano qualcosa: messaggio in documentazione/commit_P4.txt, senza Co-Authored-By né riferimenti a Claude, con in fondo l'elenco esatto dei file da aggiungere (escluso il file del messaggio).
- Se una scelta non è scritta qui o nel piano, fermati e chiedimi.

Alla fine: resoconto breve (file, cosa fa, scelte tue, test prima/dopo, dubbi, cosa provare io nel browser passo per passo).

PASSO P4 — MyForms: compilazione.
1. form.php?c=<codice> (pagina pubblica, con la testata del sito come le altre pagine pubbliche):
   - Form inesistente o in bozza -> «Modulo non trovato» (404). Chiuso, fuori periodo o al numero massimo -> messaggio chiaro con le date (usa il motivo di puoRispondere).
   - Pubblico: se serve l'accesso e la persona non l'ha fatto -> pulsante «Accedi per compilare» con ritorno a questo form dopo l'accesso (usa il meccanismo di ritorno già esistente del login; controlla che accetti solo indirizzi interni al sito). Se ha fatto l'accesso ma il suo profilo non è ammesso -> «Questo modulo è riservato a: …» con i profili in parole.
   - Titolo, descrizione passata da pulisci_html_semplice() anche in uscita, campi con html_campo_pratica e condizioni con campi-pratica.js; obbligatori segnati.
   - Chi non ha fatto l'accesso: domanda anti-robot + campo trappola nascosto + limite per IP (LimiteRichieste, es. 10 invii l'ora per form); se il form ha campi file -> messaggio «per rispondere serve l'accesso» al posto del form.
   - Invio -> salvaRisposta; errori di compilazione mostrati accanto ai campi conservando quanto scritto. Dopo l'invio (redirect, niente doppio invio con F5): messaggio di ringraziamento del form o uno predefinito + codice di ricevuta.
   - Se «modificabile» e la persona ha già risposto (solo con accesso): il form si apre con la sua risposta precompilata e il pulsante «Salva le modifiche» (modificaRisposta), finché il form è aperto.
   - Se «una per persona» e ha già risposto (non modificabile): «Hai già risposto il gg/mm/aaaa (ricevuta …)».
   - Email: ricevuta a chi risponde se ricevuta_email e conosciamo l'email (accesso fatto; per gli anonimi solo se il form ha un campo email e chi risponde lo compila: chiedimi prima se vuoi farlo); notifica agli indirizzi di email_notifica con titolo del form, data e link alla futura pagina delle risposte (admin/forms_risposte.php?id=); nei form anonimi la notifica NON contiene nome né email. L'invio non deve mai far fallire il salvataggio (errore solo nel log).
2. forms.php — «MyForms · moduli da compilare»: solo i form aperti, nel periodo, a cui il profilo di chi guarda è ammesso (con Pubblico::ammesso); per chi non ha fatto l'accesso solo quelli aperti a chiunque. Per ciascuno: titolo, scadenza, «già compilato» se è il caso. Nessun form -> messaggio gentile.
3. Area personale: tessera «I miei form» nello stile delle altre: form compilati da me (titolo, data, ricevuta, «modifica» se ancora possibile) e quanti form sono aperti per me con link a forms.php. Nei form anonimi il riconoscimento passa da utente_impronta (già usata da ServizioForms): non scrivere utente_id.
4. Metodi nuovi di dominio in src/Forms con test (es. elenco dei form aperti per un utente, risposte di un utente tramite impronta); nessuna logica di permesso nelle pagine che non passi da ServizioForms/Pubblico.
5. Prova nel browser con il server di sviluppo, con un form condizionale aperto a «studenti» e uno a «chiunque»: non loggato (domanda anti-robot, trappola, limite), studente, docente (deve vedere «riservato»), esterno; una per persona; modifica della risposta; ricevuta a schermo; F5 dopo l'invio non duplica. Pulisci la base dati di prova alla fine.
```

---

## 5. Passo P5 — Risposte ed Excel (sessione Sonnet nuova, solo dopo che P4 è approvato e committato)

```
Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. Leggi prima documentazione/piano_myforms_amministrazione_accessi.md: fai SOLO il passo indicato sotto.

Regole del progetto: identiche al passo P4 (le trovi in documentazione/myforms_da_casa.md, sezione 4: leggile e rispettale tutte). Messaggio di commit in documentazione/commit_P5.txt con l'elenco dei file in fondo. NON fare commit né git stash. Se una scelta non è scritta, fermati e chiedimi.

Alla fine: resoconto breve (file, cosa fa, scelte tue, test prima/dopo, dubbi, cosa provare io nel browser passo per passo).

PASSO P5 — MyForms: risposte ed Excel.
1. admin/forms_risposte.php?id=<form>: permesso con ServizioForms::puoGestire su OGNI richiesta (anche gli scaricamenti). Voce/pulsante «Risposte (N)» nell'elenco e nella scheda di admin/forms.php.
   - Tabella delle risposte: data, profilo, nome ed email (MAI nei form anonimi: colonna assente, non solo vuota), codice di ricevuta, e le prime 3-4 domande; ricerca nel testo, filtro per periodo e per profilo; paginazione.
   - Dettaglio di una risposta (tutte le domande con le risposte, i campi nascosti dalle condizioni non mostrati, allegati scaricabili da uno script protetto che ricontrolla puoGestire e che il file appartenga davvero a quel form: niente percorsi presi dalla richiesta).
   - Eliminazione di una risposta (conferma) e di tutte (conferma scrivendo il titolo del form); elimina anche gli allegati; registra_log_audit.
   - Scarica tutti gli allegati in ZIP (se ZipArchive manca: messaggio chiaro, non errore fatale).
2. Esportazione LATO SERVER con EsportazioneRisposte (già fatta in P2) e TabellaEsportabile: Excel, CSV, PDF di tutte le risposte o di quelle filtrate; una colonna per domanda, nell'ordine del form; tabelle a righe in una cella leggibile (o un foglio a parte nell'Excel: proponimi tu, ma chiedimi prima); nei form anonimi nessuna colonna di nome/email. Nome del file: titolo del form + data. Attenzione all'iniezione di formule nel CSV/Excel (celle che iniziano con = + - @): neutralizzale.
3. Riepilogo: per le domande a scelta (tendina, scelta singola/multipla, sì/no) conteggi e percentuali con barre semplici in HTML/CSS (niente librerie esterne); per le altre «N risposte». Tiene conto dei filtri.
4. Metodi nuovi in src/Forms con test (filtri, conteggi del riepilogo, neutralizzazione delle formule, controllo di appartenenza degli allegati).
5. Prova nel browser: form con 5+ risposte (anche anonimo), filtri, dettaglio, esportazione nei tre formati aperta davvero (Excel in LibreOffice/Excel), eliminazione singola e totale, un co-gestore vede le risposte, un utente non abilitato riceve «Accesso negato» anche sullo scaricamento diretto di un allegato. Pulisci alla fine.
```

---

## 6. Passo P6 — Rifiniture (sessione Sonnet nuova, dopo P5)

```
Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. Leggi prima documentazione/piano_myforms_amministrazione_accessi.md: fai SOLO il passo indicato sotto.

Regole del progetto: identiche al passo P4 (le trovi in documentazione/myforms_da_casa.md, sezione 4: leggile e rispettale tutte). Messaggio di commit in documentazione/commit_P6.txt con l'elenco dei file in fondo. NON fare commit né git stash. Se una scelta non è scritta, fermati e chiedimi.

Alla fine: resoconto breve (file, cosa fa, scelte tue, test prima/dopo, dubbi, cosa provare io).

PASSO P6 — MyForms: rifiniture.
1. Nel cron già esistente (cron_background o come si chiama nel progetto: cercalo, non crearne uno nuovo): chiusura automatica dei form a fine periodo o al numero massimo di risposte, con email al proprietario («il form X si è chiuso: N risposte, ecco il link»). Una sola volta per form.
2. Conservazione: cancellazione delle risposte (e degli allegati) più vecchie di conserva_mesi, con registra_log_audit e avviso al proprietario 7 giorni prima.
3. Date del periodo: in admin/forms.php la data deve anche esistere (DateTime::createFromFormat con controllo degli errori), non solo avere la forma giusta.
4. Ricerca unica del portale e Didacticus: i form aperti compaiono nei risultati SOLO a chi può compilarli (Pubblico::ammesso). Cerca come vi entrano oggi le altre pagine e fai lo stesso.
5. Guida per chi crea i form: documentazione/guide/guida_myforms.md, in italiano semplice: creare un form, campi condizionali, a chi si rivolge, anonimo («anonimo per chi gestisce il form»: spiega in parole semplici che il portale tiene un'impronta per evitare doppie risposte), co-gestori, link e QR, risposte ed Excel, conservazione. Se nel pannello c'è già un modo di mostrare le guide, collegala.
6. Test per chiusura automatica e conservazione (con un orologio finto, come negli altri test del progetto).
```

---

## 7. Passo X1 — Inventario degli accessi (sessione Sonnet nuova; si può fare in qualsiasi momento, è solo lettura)

```
Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. Leggi prima documentazione/piano_myforms_amministrazione_accessi.md (PARTE 3) e src/Auth/Pubblico.php.

SOLO LETTURA: non modificare codice. Nessun comando git che modifica qualcosa, mai git stash.

Cerca in tutto il portale ogni punto che decide chi può vedere o fare qualcosa in base al profilo: eventi.ruolo_accesso_id, risorse.accesso, moduli.destinatari, voci di menu, comunicazioni/avvisi, pagine riservate, home e widget, area personale, MyForms (già su Pubblico).
Scrivi documentazione/inventario_accessi.md con una tabella: file:riga, cosa protegge, regola di oggi, come si tradurrebbe con App\Auth\Pubblico, rischio della modifica (basso/medio/alto). In fondo: proposta dell'ordine in cui migrare le aree (dalla più semplice), e i punti dove la regola di oggi NON si traduce uguale con Pubblico.
Resoconto breve alla fine.
```

I passi X2–X3 (migrazione degli accessi area per area) e A0–A5 (Amministrazione) si preparano dopo: X2 dopo aver letto l'inventario, A0 con la segreteria amministrativa.

---

## 8. Promemoria

- Quando P1-P4 sono committati si può caricare sul server (pacchetto con `crea_pacchetto.sh`): **lo schema passa a v125** da solo (tabelle `forms*` e riquadro «I miei form»).
- Il test `DaFareEdEmailTest` sulle risorse di tipo «veicolo» fallisce anche senza MyForms: da guardare a parte.

---

## 9. X2 — Accessi per profilo, un'area alla volta

Base: `documentazione/inventario_accessi.md` (X1, approvato il 17/11/2026). Decisioni dell'utente (17/11/2026):
- **Gestori nel menu** (punto 1): nel **solo menu** il gestore (ruolo 2) continua a vedere tutte le voci riservate a un ruolo, come oggi. Negli eventi e altrove resta la regola di Pubblico (solo ruolo 1 passa sempre).
- **Scuole** (punto 8): il profilo «scuole» resta **autodichiarato** (`sono_una_scuola.php`), ma va scritto chiaramente nella guida e nel pannello: non usarlo per cose riservate.

Ordine: X2a (fondamenta, nessun cambiamento visibile) → X2b (menu) → X2c (eventi: prima `pubblico_json`, poi i pannelli). Comunicazioni «chi può leggere»: solo se l'utente lo chiede (oggi non è un permesso). Home, widget, area personale e Didacticus: non si toccano.
Prerequisito: i commit di MyForms (P0, P1–P4, P5, P6) e di X1 fatti. X2b e X2c si scrivono dopo la revisione di X2a.

### X2a — Fondamenta (sessione Sonnet nuova)

```
Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. Leggi prima documentazione/inventario_accessi.md (tutto) e src/Auth/Pubblico.php: fai SOLO il passo indicato sotto.

Prima di cominciare: controlla con git log --oneline -8 che ci siano i commit di MyForms (P0, P1-P4, P5, P6) e dell'inventario X1. Se mancano, fermati e dimmelo.

Regole del progetto: identiche al passo P4 (le trovi in documentazione/myforms_da_casa.md, sezione 4: leggile e rispettale tutte). Messaggio di commit in documentazione/commit_X2a.txt con l'elenco dei file in fondo. NON fare commit né git stash. Se una scelta non è scritta, fermati e chiedimi.

Alla fine: resoconto breve (file, cosa fa, scelte tue, test prima/dopo, dubbi, cosa provare io).

PASSO X2a — Accessi per profilo: fondamenta. NESSUN cambiamento di comportamento visibile: chi oggi passa deve continuare a passare, chi oggi è fermato deve continuare a essere fermato.

1. Una sola lettura dei gruppi dell'utente. Oggi la query «nomi minuscoli dei gruppi in ruoli dell'utente» è scritta 3 volte: ServizioRisorse::gruppiUtenteNomi (+ RisorsaRepository::nomiRuoli), ServizioForms::gruppiUtente, ProfiliPersona::nomiRuoli. Fanne una sola classe in src/Auth (es. App\Auth\GruppiUtente, con nomi(?array $utente): list<string>; null o senza id -> []; ids da ruolo_id + ruoli_secondari, solo interi) e usala nei tre posti. Stesso risultato di prima (test esistenti verdi + test nuovo della classe). Se ProfiliPersona ha una ragione per restare diversa (es. il try/catch), tienila dentro la classe nuova, non in una copia.

2. Una sola regola «chi può iscriversi a un evento». Oggi la regola su eventi.ruolo_accesso_id (0 = tutti anche senza accesso; -1 = serve l'accesso; n > 0 = ruolo principale o secondario = n; il ruolo 1 passa sempre; il gestore ruolo 2 NO) è copiata in: master_template.php:331-337 e :732-738, src/Iscrizioni/ServizioPrenotazioni.php:115-121, src/Fsl/ServizioProgrammaFsl.php:120-123; il solo «serve l'accesso» in master_template.php:278, :1051, :1230 (righe indicative: ricontrollale). Scrivi UNA funzione pura in src/Eventi (es. App\Eventi\AccessoEvento::ammesso(int $ruoloAccesso, bool $connesso, int $ruolo, list<string> $ruoliSecondari): bool, e serveAccesso(int $ruoloAccesso): bool) e usala in tutti questi punti. NON usare ancora Pubblico qui e non cambiare i dati che i servizi ricevono: è solo togliere le copie. Le etichette «Solo Studenti…» (nomi_ruoli) e l'agenda (AgendaRepository: nasconde gli eventi con qualsiasi restrizione) restano come sono.
   Test: unitario con la tabella completa dei casi (0, -1, n uguale al principale, n tra i secondari, n diverso, ruolo 1 principale e secondario, ruolo 2 che NON passa, non connesso per ogni valore) e integrazione sul ramo FSL (ServizioProgrammaFsl: oggi nessuna prova; un'attività riservata rifiutata a chi non è ammesso e accettata a chi lo è), oltre a quelle già presenti per le prenotazioni (EventiIntegrazioneTest, PrenotazioneTest) che devono restare verdi. Rilancia anche strumenti/prove/esegui.php e dimmi le differenze.

3. Scuole autodichiarate (decisione dell'utente: resta così, ma va detto chiaramente):
   - in App\Auth\Pubblico, nel commento della classe, togli l'affermazione «mai da qualcosa che la persona può scrivere da sola» per le scuole: scrivi che «scuole» lo sceglie la persona stessa (sono_una_scuola.php) e che non va usato per cose riservate;
   - nella scelta del pubblico dei pannelli (html_scelta_pubblico / PubblicoVista) accanto alla casella «Scuole» un aiuto breve: «Chiunque abbia fatto l'accesso può dichiararsi scuola: non usarlo per cose riservate.»;
   - la stessa frase in documentazione/guide/guida_myforms.md e nella voce MyForms di GuidePerRuolo (dove si spiega a chi si rivolge un form).

4. In documentazione/inventario_accessi.md, in fondo, una sezione «Decisioni (17/11/2026)»: punto 1 gestori -> nel solo menu restano sempre ammessi come oggi (si farà in X2b); punto 8 scuole -> autodichiarate, scritto in guida e pannello (fatto in X2a). Segna come fatte le copie tolte (punti 3 e 4 di «Cose da sapere prima»).

5. Prova nel browser con il server di sviluppo: un evento con ruolo_accesso_id = 3 (studenti) e uno = -1: non connesso (vede «Accedi»), studente (prenota), docente (vede «Riservato»), amministratore (prenota), gestore (vede «Riservato», come oggi). Un'attività riservata nel programma FSL di una scuola. Il pannello MyForms mostra l'aiuto accanto a «Scuole». Pulisci la base dati di prova alla fine.
```
