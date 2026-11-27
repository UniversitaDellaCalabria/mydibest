# Messaggi per Sonnet: approvazione delle richieste di pubblicazione (P1–P7)

26/11/2026. Un messaggio = **una sessione nuova di Sonnet**. Copia il blocco del passo, incolla il resoconto di Sonnet nella chat di revisione di Opus,
passa al messaggio dopo solo quando Opus approva (o dopo le correzioni chieste). Niente commit fino a P7.

Il piano completo, con tutte le decisioni prese: `documentazione/piano_approvazione_pubblicazioni.md`.

**Prove: solo quelle necessarie (deciso 26/11).** In P1–P6 Sonnet lancia **solo i file di test che tocca o che scrive**, uno per uno
(`phpunit … tests/Unit/Pubblicazioni/RegolePubblicazioneTest.php`), mai una cartella intera, mai `esegui.php`. Le prove nuove sono solo quelle
elencate nel messaggio: niente prove per getter, testi, etichette o cose già coperte. Il giro completo e la suite intera si fanno **una volta sola**, in P7.

---

## P1 — Testi e obbligatorietà

```
Leggi CLAUDE.md e documentazione/piano_approvazione_pubblicazioni.md (§0, «Domande aperte» e §0bis per il contesto). Fai SOLO il passo P1.

Regole di lavoro (valgono per tutto il passo):
- niente commit, niente git stash (la cartella è su Google Drive), niente dipendenze nuove;
- PROVE SOLO NECESSARIE: lancia uno per uno solo i file di test che modifichi o scrivi (mai una cartella intera, mai esegui.php, mai la suite: si fa
  una volta a P7); scrivi solo le prove nuove elencate qui sotto, niente prove per testi, etichette o cose già coperte; se una prova vecchia si rompe
  per un testo cambiato, aggiornala e basta;
- una prova rossa non si lascia «per dopo»: o la correggi o scrivi perché;
- testata del sito intoccabile; ogni <script> in linea col nonce, niente onclick= (data-on-*), niente style= nuovi (classi ui-*), tema chiaro e scuro;
- le richieste vecchie (senza locandina, con url/note/tag compilati) devono continuare a vedersi bene in scheda, riepilogo, email ed export.

Cosa fare: i 6 punti di P1 del piano.
1. Note operative come impostazione modificabile (testo di partenza nel piano, parola per parola).
2. «Richiesta di pubblicazione su pagina web e canali social istituzionali DiBEST, indirizzata al Direttore.» in evidenza su tutti i passi.
3. url_docente, note, tag facoltativi di partenza; togli «scrivi nessuna» dall'aiuto delle note.
4. «Dove vuoi che si pubblichi?»: impostazione pagine_pubblicazione VUOTA di partenza (la riempie l'amministratore dal pannello: nome | indirizzo);
   con l'elenco pieno caselle + «Altra pagina del sito DiBEST» col controllo del dominio descritto nel piano; con l'elenco vuoto il campo resta come oggi.
5. Locandina: un file obbligatorio fisso (tipo 'locandina' in pubblicazioni_allegati), «Altri allegati» e «Ulteriori immagini» facoltativi,
   allegati_obbligatori di partenza false.
6. allegato_pubblicazione.php scarica la locandina come Locandina_<titolo breve>.<est>.

Se servono colonne nuove in impostazioni_sistema, sono schema v146 (src/Core/Schema/Definizioni.php + Migrazioni::VERSIONE): P2 aggiungerà le sue alla stessa v146.

Prove nuove (solo queste): in RegolePubblicazioneTest url/note/tag vuoti passano, locandina mancante = errore, indirizzo non DiBEST = errore;
una per il nome Locandina_<titolo breve>. Poi lancia RegolePubblicazioneTest, ConfigurazioneCampiTest, PaginaDocenteTest e i test che hai dovuto toccare.

Alla fine scrivi in documentazione/approvazione_pubblicazioni_stato.md (crealo):
- il resoconto di P1: file toccati, scelte fatte, prove lanciate con il risultato (numeri), cosa non hai potuto provare;
- due schermate del form (passo 1 e passo 5, una delle due in tema scuro a larghezza telefono) in documentazione/schermate_pubblicazioni_P1/;
- la riga del messaggio di commit per P1 in documentazione/commit_approvazione_pubblicazioni.txt (crealo: il commit sarà uno solo alla fine).
Poi fermati e mostrami il resoconto.
```

**Dopo P1, sul portale** (puoi farlo tu o Sonnet con l'accesso admin, ma solo DOPO che il codice è caricato): scheda «Campi e colonne» dell'Amministrazione,
se URL, Note o Tag risultano ancora obbligatori premi «Torna all'originale»; poi inserisci le pagine DiBEST nelle impostazioni.

---

## P2 — Schema e servizio dell'approvazione

```
Leggi CLAUDE.md, documentazione/piano_approvazione_pubblicazioni.md (tutto, soprattutto §0bis e P2) e documentazione/approvazione_pubblicazioni_stato.md
(il resoconto di P1, già approvato). Fai SOLO il passo P2. Stesse regole di lavoro di P1 (niente commit, niente git stash, SOLO le prove necessarie
elencate qui, lanciate file per file, nessuna rossa lasciata).

Cosa fare:
0. PRIMA di tutto, un resto di P1 deciso in revisione: anche la riga a mano dal pannello (admin/pubblicazioni.php, azione «nuova» →
   ServizioPubblicazioni::creaManuale) ha la locandina OBBLIGATORIA. Nel form del pannello un campo «Locandina» (un file PDF/JPG/PNG, obbligatorio) prima di
   «Allegati da pubblicare», che diventa «Altri allegati»; $_FILES['locandina'] passato a creaManuale; in RegolePubblicazione::valida la locandina mancante
   è un errore anche con $aMano = true (oggi c'è «&& !$aMano»). Gli altri campi della riga a mano restano facoltativi come oggi.
   Prove: aggiungi FileDiProva::pdf() come locandina alle chiamate a creaManuale che devono riuscire (ServizioPubblicazioniTest, PannelloPubblicazioniTest,
   NotificheEScaricoTest, ImpostazioniRichiestaTest) e UN caso «riga a mano senza locandina = errore» in ServizioPubblicazioniTest. Lancia solo questi 4 file.
1. Schema v146 (stessa versione di P1 se P1 l'ha già usata): colonne decisa_da, decisa_il, respinta_motivo in pubblicazioni (o approvata_*: scegli e
   scrivilo nel commento dello schema); tabella pubblicazioni_persone (ruolo approvatore | ufficio | conoscenza, persona_id dell'anagrafe personale_ateneo,
   nominativo, aggiunta_il, aggiunta_da, UNIQUE ruolo+persona_id); impostazione approvazione_attiva.
2. Costanti::STATI: 'da_approvare' («In attesa di approvazione») per primo; 'ricevuta' prende l'etichetta «Approvata». Le righe vecchie NON si toccano
   (deciso: valgono come approvate). Le righe a mano dal pannello nascono approvate.
3. PersonePubblicazioni (classe senza HTML): le tre liste, l'email letta ogni volta da personale_ateneo, gli avvisi (persona uscita, senza email,
   approvatore senza account collegato, persona dell'ufficio senza abilitazione modulo_pubblicazioni). Il permesso di approvare vale per l'ACCOUNT
   con utenti.persona_id uguale: mai per un'email scritta a mano o non verificata.
4. ApprovazionePubblicazioni: puoApprovare, daApprovare, approva, respingi (motivo obbligatorio, max 1000). Approva/respingi = un solo UPDATE
   ... WHERE id = ? AND stato = 'da_approvare'; se 0 righe, Esito di errore «già approvata/respinta da X il …». Registro in log_attivita.
5. ServizioPubblicazioni: invia() fa nascere 'da_approvare' solo se l'approvazione è accesa E c'è almeno un approvatore con account collegato, altrimenti
   'ricevuta' come oggi; inviaAlMonitoraggio() rifiuta 'da_approvare'; puoVedere() apre la richiesta e i file anche agli approvatori.
6. Nelle impostazioni delle richieste (Amministrazione) la parte «Chi approva, chi lavora, chi è informato»: tre liste con la ricerca nell'anagrafe che
   c'è già (App\Anagrafi\Vista\Persone::ricerca, admin/cerca_personale.php, assets/js/ricerca-personale.js), «Togli», avvisi in rosso, interruttore
   «Approvazione accesa» (che non si accende senza un approvatore con account). Registro delle modifiche come le altre impostazioni.
NON fare ancora email né la pagina di chi approva (sono P3 e P4): con l'approvazione accesa le richieste restano in attesa senza avvisi, va bene
perché di partenza è spenta.

Prove nuove (solo queste): un file di unità con puoApprovare (account collegato sì, non collegato no) e «approvazione spenta o senza approvatori =
nasce ricevuta»; UNA prova di integrazione con MariaDB: due approvano la stessa richiesta, il secondo ha l'errore e nel database resta il primo.
Il motivo obbligatorio basta come caso nella prova di unità. Lancia solo questi due file e quelli che hai toccato.

Alla fine aggiungi il resoconto di P2 a documentazione/approvazione_pubblicazioni_stato.md (file, scelte, prove con i numeri, cosa non provato),
una schermata della parte «Chi approva, chi lavora, chi è informato» in documentazione/schermate_pubblicazioni_P2/, righe di P2 in
commit_approvazione_pubblicazioni.txt. Poi fermati.
```

---

## P3 — La pagina di chi approva + email agli approvatori

```
Leggi CLAUDE.md, documentazione/nucleo.md, documentazione/piano_approvazione_pubblicazioni.md (§0bis e P3) e documentazione/approvazione_pubblicazioni_stato.md
(P1 e P2 approvati). Fai SOLO il passo P3. Stesse regole di lavoro.

Cosa fare:
1. Pagina nuova DAL NUCLEO, seguendo la ricetta del CLAUDE.md alla lettera: riga in src/Portale/Instradamento/Rotte.php, guscio approva_pubblicazioni.php,
   src/Portale/Pagine/PaginaApprovaPubblicazioni.php (final, implements Pagina, niente echo/exit/$_GET/$_POST/globali, niente nega_accesso/csrf_verify),
   vista in viste/…. Non sta nel pannello: il Direttore non ha il modulo Amministrazione. Accesso solo a chi puoApprovare.
   Contenuto: «Da approvare» (titolo, richiedente, data evento, inviata il, miniatura della locandina) e «Decise di recente» con CHI ha deciso e QUANDO;
   la richiesta aperta con il riepilogo completo (riusa RiepilogoRichiesta / VistaRichiesta), file scaricabili, pulsanti grandi Approva / Respingi
   (Respingi apre il motivo obbligatorio; conferma con conferma.js; data-on-*). Se un altro approvatore ha già deciso: messaggio chiaro, niente errore tecnico.
2. Tessera nell'area personale e postazione in «Il mio lavoro» (PostazioniLavoro, permesso nuovo pubblicazioni_approva) col numero in attesa, solo per gli approvatori.
3. Email nel catalogo (CatalogoPubblicazioni, modificabili dalla pagina «Email»): pubblicazioni_da_approvare (a ogni approvatore all'invio, riepilogo e
   pulsante «Apri e approva») e pubblicazioni_decisa_altro (agli altri approvatori: «{{chi}} ha approvato/respinto {{titolo}} il {{quando}}»).
4. NotifichePubblicazioni::richiestaInviata(): con l'approvazione attiva manda SOLO agli approvatori + la ricevuta al richiedente che ora dice «in attesa
   di approvazione»; spenta = come oggi. Un errore di invio non blocca mai niente (solo error_log). Una persona riceve una sola email per evento.

Prove (solo queste): tests/Integration/Portale/PaginaApprovaPubblicazioniTest.php con Richiesta::finta() (modello PaginaContattiTest) con tre casi:
chi non è approvatore non entra, approva va a buon fine, «già decisa da un altro» mostra il messaggio (le regole di approva/respingi le ha già provate P2:
non ripeterle). Poi php strumenti/avanzamento_nucleo.php --controlla (pochi secondi). Niente prove sulle email: le copre il giro di P7.

Alla fine resoconto P3 nello stato, due schermate (elenco e richiesta aperta con Respingi, una delle due da telefono) in documentazione/schermate_pubblicazioni_P3/,
righe di P3 nel messaggio di commit. Poi fermati.
```

---

## P4 — Dopo la decisione: ufficio, conoscenza, richiedente

```
Leggi CLAUDE.md, documentazione/piano_approvazione_pubblicazioni.md (§0bis e P4) e documentazione/approvazione_pubblicazioni_stato.md (P1-P3 approvati).
Fai SOLO il passo P4. Stesse regole di lavoro.

Cosa fare (i 4 punti di P4 del piano):
1. Approvata → pubblicazioni_comunicazione_nuova alle persone della lista «ufficio» + gli indirizzi della casella condivisa (impostazione indirizzi), con
   {{approvata_da}} e {{approvata_il}}. Mai due email alla stessa persona per lo stesso evento (anche se è in più liste).
2. Lista «conoscenza» → email nuova pubblicazioni_conoscenza: riepilogo in sola lettura, NESSUN link al pannello o agli allegati.
3. Al richiedente pubblicazioni_approvata o pubblicazioni_respinta (col motivo); in «Le mie richieste» (inc/area/richieste_pubblicazione.php) lo stato e il motivo.
4. admin/pubblicazioni.php: «Richieste arrivate» solo approvate; sopra, chiusa di partenza, «In attesa di approvazione (N)» in sola lettura; nella scheda
   «Approvata da X il …» (vuoto per le vecchie: «prima dell'approvazione»). CoseDaFareAmministrazione e «Il mio lavoro»: le da_approvare non contano tra
   quelle da guardare degli operatori.

Prove (solo queste): UN file di unità sui destinatari (liste, doppioni tolti, persona senza email saltata). Niente prova di integrazione sulle email
né sulle richieste vecchie: le copre il giro di P7. Lancia solo quel file e i test esistenti che hai dovuto toccare, uno per uno.

Alla fine resoconto P4 nello stato, schermate in documentazione/schermate_pubblicazioni_P4/, righe di P4 nel messaggio di commit. Poi fermati.
```

---

## P5 — «Chi compila cosa»: ogni operatore vede solo le sue colonne

```
Leggi CLAUDE.md, documentazione/piano_approvazione_pubblicazioni.md (P5) e documentazione/approvazione_pubblicazioni_stato.md (P1-P4 approvati).
Fai SOLO il passo P5. Stesse regole di lavoro.

Deciso: amministratori e chi ha il modulo Amministrazione intero vedono tutte le colonne; chi ha solo «Richieste di pubblicazione» vede le sue.

Cosa fare (i 4 punti di P5 del piano):
1. Tabella pubblicazioni_colonne_utenti (chiave colonna, utente_id; PK su tutte e due), nella v146 se non è ancora sul server, altrimenti v147: dimmi quale.
   Vale anche per le colonne nuove y_<n>.
2. Griglia colonne × operatori (operatori = chi puoPubblicazioni) nell'Amministrazione, con «Proponi dai nomi "a cura di"» (riusa la logica di
   CompilazioneInTabella) che riempie la griglia senza salvare; si salva a mano. Registro delle modifiche.
3. ColonneDiChi::visibili(utente): tutte per chi vede tutto; per gli altri le assegnate + quelle del docente in sola lettura. APPLICATA SUL SERVER:
   aggiornaOperatori() e aggiornaOperatoriInBlocco() rifiutano una colonna non assegnata. Nessuna assegnazione salvata = tutti vedono tutto come oggi.
4. Export: completo per chi vede tutto; per gli altri proponi tu (solo le proprie colonne + quelle del docente, oppure niente) e motiva nel resoconto.
La vista «Da compilare» per ora usa ColonneDiChi con la grafica di oggi: la grafica nuova è P6.

Prove (solo queste): unità su ColonneDiChi (admin, parziale con 2 colonne, nessuna assegnazione); UNA integrazione: un salvataggio in blocco con una
colonna non sua tra celle giuste → errore e niente salvato. Lancia solo questi due file e quelli che hai toccato, uno per uno.

Alla fine resoconto P5 nello stato, schermate della griglia in documentazione/schermate_pubblicazioni_P5/, righe di P5 nel messaggio di commit. Poi fermati.
```

---

## P6 — Grafica nuova della compilazione (in due tempi)

**P6a — solo lo schizzo:**

```
Leggi CLAUDE.md, documentazione/piano_approvazione_pubblicazioni.md (P6) e documentazione/approvazione_pubblicazioni_stato.md (P1-P5 approvati).
Fai SOLO lo schizzo di P6, nessun codice del portale.

Prepara documentazione/schizzo_compila_pubblicazioni.html: HTML statico, con dibest-colori.css e le classi ui-* del portale, dati finti realistici
(3-4 richieste, un operatore con 4 colonne di cui 2 SI/NO e 1 numero), che mostri: schede «Da fare (N) · Fatte (M)», una card per richiesta (miniatura
della locandina, titolo grande, data evento, richiedente, «approvata da X»), SOLO i campi dell'operatore grandi (SI/NO come interruttori, numero con tastiera
numerica, testo su più righe), barretta «2 di 4 compilati», un «Salva» per card con un errore accanto a un campo; anche tema scuro e telefono.
Fai le schermate in documentazione/schermate_pubblicazioni_P6/ e fermati: l'utente deve approvare il disegno prima del codice.
```

**P6b — la pagina, dopo che hai approvato lo schizzo** (aggiungi in fondo le tue correzioni al disegno):

```
Leggi CLAUDE.md, documentazione/piano_approvazione_pubblicazioni.md (P6), documentazione/approvazione_pubblicazioni_stato.md e lo schizzo approvato
documentazione/schizzo_compila_pubblicazioni.html. Fai la vista «Da compilare» di admin/pubblicazioni.php come nello schizzo, con ColonneDiChi (P5).
Stesse regole di lavoro. Niente salvataggio automatico (un «Salva» per card, tutto o niente, errori accanto al campo); la tabella larga di oggi resta come
«Tabella completa» per chi vede tutto. Telefono senza scorrimento orizzontale, tema scuro, niente style= nuovi, script col nonce.
Schizzo approvato dall'utente il 27/11/2026. Correzioni al disegno:
1. Un campo con un valore scritto ma non ancora salvato ha l'etichetta «DA SALVARE» (come nello schizzo da telefono), non «DA COMPILARE»
   (nello schizzo la terza card, «N. Partecipanti» = 45, dice per errore «DA COMPILARE»).
2. L'etichetta di stato (COMPILATO / DA COMPILARE / DA SALVARE / DA CORREGGERE) sta sulla stessa riga del titolo del campo anche su schermo largo
   (nello schizzo quella del campo U va a capo); su telefono può andare sotto il titolo, ma allineata uguale per tutti i campi.
Prove (solo queste): lancia uno per uno i test esistenti di CompilazioneInTabella e PannelloPubblicazioni, aggiornandoli se cambia l'HTML; nessuna prova nuova (le regole le ha già provate P5). Resoconto P6 nello stato, schermate vere in
documentazione/schermate_pubblicazioni_P6/, righe di P6 nel messaggio di commit. Poi fermati.
```

---

## P7 — Rifiniture, suite intera, messaggio di commit

```
Leggi CLAUDE.md, documentazione/piano_approvazione_pubblicazioni.md (P7) e documentazione/approvazione_pubblicazioni_stato.md (P1-P6 approvati). Fai il passo P7.

1. Guide in GuidePerRuolo (richiedente, operatori, NUOVA per gli approvatori; dire che ora ogni operatore modifica solo le sue colonne), mappe 13b/13i,
   inventario_accessi, CSV di Didacticus come nel giro precedente (documentazione/didacticus_voci_pubblicazioni_*.csv).
2. strumenti/prove/esegui.php: il giro completo via HTTP (invia → da approvare → approva il secondo approvatore → «già decisa» per il primo →
   email all'ufficio e per conoscenza → invio al file Excel → operatore compila solo le sue colonne, rifiutata una colonna non sua). Prima svuota
   rate_limit_attempts (CLAUDE.md).
3. Suite intera UNA volta: tests/Unit, tests/Integration (MariaDB accesa: le saltate devono essere 2, non di più), esegui.php,
   bash strumenti/prove/apache_nucleo.sh, php strumenti/avanzamento_nucleo.php --controlla. Ogni rossa: corretta o spiegata (se era già rossa prima, provalo
   con git worktree add --detach <cartella fuori da Drive> HEAD, MAI git stash).
4. documentazione/commit_approvazione_pubblicazioni.txt definitivo: un messaggio unico in italiano, SENZA nessuna attribuzione a Claude
   (né Co-Authored-By né «Generated with»), con l'elenco ESATTO dei file da aggiungere (git add per nome). Attenzione: nella cartella ci sono file non
   tracciati che NON c'entrano (documentazione/PASSAGGIO_GATEWAY.txt, RIPRENDI_DA_QUI.md, prova_titulus/, schermate_passo8/ e 9/, schizzo_pagina_scuole.html,
   strumenti/locale/*.ps1, la modifica a .gitignore): non vanno nel commit.
5. documentazione/istruzioni_aggiornamento_approvazione.txt: cosa fare sul server dopo il caricamento (migrazione v146, controllo SHA256SUMS) e la
   configurazione da fare dal pannello admin: pagine DiBEST, «Torna all'originale» su URL/Note/Tag se servisse, le tre liste dall'anagrafe, avvisi
   in rosso risolti, «Chi compila cosa», approvazione accesa PER ULTIMA, una richiesta di prova da cancellare.
6. I punti lasciati a P7 nelle revisioni dello stato: la frase «ricevute (da guardare)» del riquadro del pannello; i conteggi «N da completare»
   (tabella di monitoraggio, riquadro, scheda, «Il mio lavoro», «Cosa devo fare») sulle sole colonne di chi guarda se è limitato; lanciare
   strumenti/prove/oop/pagine_amministrazione.php (modificato in P6b); «Entra come…» guardato una volta come operatore limitato e come approvatore;
   nelle istruzioni per il server il segnaposto {{approvazione_testo}} da aggiungere se l'avviso all'ufficio è stato modificato dal pannello.
NON fare il commit: lo fa l'utente dopo la revisione. Resoconto finale nello stato, con i numeri della suite. Poi fermati.
```

---

## Dopo l'approvazione di P7 (lo fai tu, o me lo chiedi in revisione)

```powershell
git add <i file elencati in commit_approvazione_pubblicazioni.txt>
```
```powershell
git commit -F documentazione/commit_approvazione_pubblicazioni.txt
```
```powershell
bash strumenti/crea_pacchetto.sh
```

Il pacchetto `aggiornamento_completo.tgz` contiene solo ciò che è nel commit: si carica prima su `mydibest_pp`, si segue
`istruzioni_aggiornamento_approvazione.txt`, si prova il giro con «Entra come…», poi produzione. Il push (`git push tutti main`) quando decidi tu.
