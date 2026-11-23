# Comandi per Sonnet — MyForms, Amministrazione, Accessi

Uso: una sessione Sonnet nuova **per ogni passo**, in locale su questa cartella. Incollare prima il **TESTO A** (uguale per tutti), poi il blocco del passo.
Alla fine di ogni passo portare il resoconto di Sonnet a Opus per la revisione, prima di passare al successivo.

---

## TESTO A (da incollare sempre per primo)

```
Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. Leggi prima documentazione/piano_myforms_amministrazione_accessi.md: fai SOLO il passo indicato sotto.

Regole del progetto:
- Nessun ALTER/CREATE nelle pagine: le tabelle si cambiano solo in src/Core/Schema/Definizioni.php alzando Migrazioni::VERSIONE.
- Codice nuovo in src/<Modulo>/ (classi con declare(strict_types=1), registrate nel contenitore come gli altri moduli); funzioni-facciata in inc/ solo se servono alle pagine. Non ridefinire funzioni già presenti in functions.php o inc/.
- File PHP senza BOM UTF-8. Escape di tutto l'output (htmlspecialchars). Ogni POST con csrf_verify e controllo dei permessi lato server. Flash con flash_set (non fare escape dentro: lo fa già flash_html).
- Riusa ciò che c'è: CampiModulo, LetturaRisposte, html_campo_pratica + assets/js/campi-pratica.js, TabellaEsportabile, qr_html, captcha_prenotazione, check_rate_limit.
- Testi per gli utenti in italiano semplice, niente gergo tecnico.
- Test: aggiungi test in tests/Unit/<Modulo>/. Per farli girare: PHPUnit 9.6 phar in %TEMP%\phpunit96 con bootstrap a parte e MariaDB di XAMPP (mysqld --standalone); se il phar non c'è chiedimi il permesso per scaricarlo. Una cartella di test alla volta. Ci sono fallimenti GIA' presenti (ZipArchive, Fsl, Didattica, Tutorato): confronta prima/dopo e dimmi solo le differenze.
- Controlla la sintassi con C:\xampp\php\php.exe -l su ogni file toccato.
- MAI git stash (la cartella è su Google Drive). NON fare commit, push o altri comandi git che modificano qualcosa: scrivi il messaggio di commit in documentazione/commit_<passo>.txt, senza righe Co-Authored-By né riferimenti a Claude.
- Se una scelta non è scritta nel piano, fermati e chiedimi; non inventare.

Alla fine scrivimi un resoconto breve: file creati/modificati, cosa fa, test (prima/dopo), dubbi aperti, cosa devo provare io nel browser.
```

---

## Passo P0 — Pubblico unico

```
Passo P0 del piano. Crea App\Auth\Pubblico come descritto (PROFILI, profiliUtente, ammesso, daVecchio) partendo dalla logica di ServizioRisorse::puoPrenotare(), che deve continuare a comportarsi ESATTAMENTE come oggi.
Fai usare Pubblico a ServizioRisorse::puoPrenotare() e a ServizioModuli::destinatario(), senza cambiare comportamento.
Crea il parziale html_scelta_pubblico(string $nome, array $regola) (caselle dei profili, accessibile, con etichette) e la funzione che legge la scelta dal POST.
Test in tests/Unit/Auth/PubblicoTest.php: ogni profilo, non loggato, amministratore, gruppi Scuole e Aziende, traduzione dei vecchi valori ('tutti','studenti','docenti','personale' e i ruolo_accesso_id usati nei turni: cercali nel codice).
Non toccare ancora turni.ruolo_accesso_id né le pagine degli eventi.
```

## Passo P1+P2 — Tabelle e dominio

```
Passi P1 e P2 del piano. Aggiungi le tabelle forms, forms_gestori, forms_risposte (schema v124 o successiva libera) e crea src/Forms/ con FormRepository, RispostaRepository, ServizioForms, EsportazioneRisposte e Registrazione.
ServizioForms::salvaRisposta deve usare CampiModulo e LetturaRisposte (le condizioni si rifanno lato server: i campi nascosti non si salvano e non sono obbligatori).
puoRispondere($form, $utente) ritorna [bool, motivo] e usa Pubblico per il pubblico.
Il codice pubblico del form è casuale (random_bytes), mai l'id.
Per i campi file riusa il sistema degli allegati delle pratiche (cartella protetta uploads/forms/<id>/).
Niente pagine in questo passo. Test: campi condizionali, obbligatori, una risposta per persona, modifica, periodo, numero massimo, anonima, esportazione (intestazioni e righe, scelte multiple con "; ").
```

## Passo P3 — Costruttore nel pannello

```
Passo P3 del piano. Prima estrai il costruttore dei campi di admin/didattica_moduli.php in un parziale riusabile (la pagina della Didattica deve restare identica: verificalo). Poi crea admin/forms.php (elenco + scheda: dati, pubblico con html_scelta_pubblico, opzioni, campi, co-gestori dall'anagrafe, link e QR, anteprima interattiva senza salvataggio, duplica, apri/chiudi, elimina con conferma).
Aggiungi 'forms' a MODULI_PORTALE ("MyForms", sottotitolo "moduli da compilare", icona fa-clipboard-list) e le pagine a PAGINE_MODULO; voce di menu; abilitazione "MyForms" in admin/utenti.php come le altre abilitazioni ai moduli.
Permessi: amministratore tutto; abilitato vede e modifica solo i form di cui è proprietario o co-gestore. Controllo su OGNI azione.
```

## Passo P4 — Compilazione

```
Passo P4 del piano. Crea form.php?c=<codice> (controlli: stato, periodo, pubblico, una per persona; login con ritorno al form se serve; CAPTCHA + campo trappola + limite per IP per chi non ha fatto l'accesso; niente campi file per chi non ha fatto l'accesso; ricevuta a schermo e per email; modifica della propria risposta se consentita).
Crea forms.php ("MyForms — moduli da compilare": solo quelli aperti al profilo di chi guarda) e la tessera "I miei form" nell'area personale, nello stile delle altre tessere.
Prova nel browser con l'anteprima del server di sviluppo e con "Entra come" almeno: non loggato, studente, docente, esterno.
```

## Passo P5 — Risposte ed Excel

```
Passo P5 del piano. Crea admin/forms_risposte.php?id= con: tabella delle risposte (ricerca, filtro per data e profilo), dettaglio, eliminazione singola e totale (conferma scrivendo il titolo), ZIP degli allegati, riepilogo con conteggi e percentuali per le domande a scelta.
Esportazione lato server con TabellaEsportabile in Excel, CSV e PDF (tutte o filtrate): una riga per risposta, una colonna per domanda, più data/ora, nome, email, profilo; per i form anonimi niente nome/email.
Stessi permessi del passo P3.
```

## Passo P6 — Rifiniture

```
Passo P6 del piano: chiusura automatica (fine periodo o numero massimo) e cancellazione dopo N mesi in cron_background con avviso al proprietario; form aperti nella ricerca unica e in Didacticus solo per chi può compilarle; guida per chi crea i form in documentazione/guide/guida_myforms.md (passi semplici, con esempi di campi condizionali).
```

---

## Passo A0 — Materiali (NON per Sonnet)
Da fare con l'utente e la segreteria amministrativa: elenco delle richieste reali → `documentazione/amministrazione_richieste.md`. Poi Opus prepara i blocchi A1–A5 su quel materiale.

## Passo X1 — Inventario degli accessi

```
Passo X1 del piano. SOLO LETTURA: non modificare codice. Cerca in tutto il portale ogni punto che decide chi può vedere o fare qualcosa per profilo (turni.ruolo_accesso_id, accesso delle risorse, destinatari dei moduli, voci di menu, comunicazioni/avvisi, pagine riservate, home e widget).
Scrivi documentazione/inventario_accessi.md con una tabella: file:riga, cosa protegge, regola di oggi, come si tradurrebbe con App\Auth\Pubblico, rischio della modifica (basso/medio/alto).
```

I passi X2 e X3 si preparano dopo aver letto l'inventario.
