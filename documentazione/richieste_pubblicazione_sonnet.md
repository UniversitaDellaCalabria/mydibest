# Richieste di pubblicazione (docenti) + Tabella di monitoraggio — comandi per Sonnet

19/11/2026. Revisione del piano di Sonnet fatta da Opus. Metodo: Sonnet fa **un passo per sessione nuova**, senza commit;
il resoconto di Sonnet si incolla nella chat di revisione di Opus; si committa solo dopo l'approvazione.

Materiali: `Format_Richiesta_Pubblicazione_ (3).docx` (il form) e `DiBEST_Tabella di monitoraggio.xlsx` (22 colonne A–V, solo intestazioni, nessuna riga di dati).
Prima di iniziare copia i due file in `materiali/pubblicazioni/` (così Sonnet li trova nella cartella del progetto).

---

## 0. Verdetto sul piano di Sonnet

**L'impostazione va bene**: una tabella con colonne fisse uguali all'Excel (non il JSON di MyForms) è la scelta giusta, perché
gli operatori devono completare le colonne riga per riga e il file deve uscire sempre con le stesse colonne. Ma va corretto in 8 punti:

1. **Colonne D ed E (deciso 19/11).** L'URL e gli «altri link» del docente **precompilano** D ed E, come nel Word; Marco o Emanuele li correggono
   dalla scheda dopo la pubblicazione. Quello che il docente aveva scritto si conserva comunque a parte (url_docente, altri_link_docente).
2. **Procedura guidata.** Il piano fa una pagina unica; tu hai chiesto una procedura guidata → form a **passi** con barra di avanzamento e riepilogo finale prima dell'invio.
3. **Destinatari L, P, Q, R.** Nell'Excel sono «a cura di Floriana / Silvana» (es. P = «STUDENTI / CdL»): la casella del docente **precompila** la cella con «SI»,
   ma la cella resta testo libero che l'operatore può completare (es. il corso di laurea). Si conserva a parte anche quello che il docente aveva spuntato.
4. **Sottotitolo.** Due colonne come hai chiesto: «Titolo» (G) e «Sottotitolo» subito dopo. Le lettere dopo G scorrono di una: nell'export contano le intestazioni, che restano identiche all'Excel.
5. **Indirizzo email.** Non una costante nel codice: un'impostazione modificabile dal pannello (come `email_notifica` di MyForms), con valore iniziale `comunicazione.dibest@unical.it`.
   Più una **ricevuta al docente** con la copia di quanto ha inviato. L'email a comunicazione contiene **tutto il testo** (si copia e incolla per pubblicare) e il link alla scheda.
6. **Accesso al pannello.** Non dentro il modulo «forms»: chi abilita MyForms vedrebbe questa tabella e viceversa. **Modulo a sé** nel pannello («Richieste di pubblicazione»),
   da abilitare a Floriana, Gianni, Silvana, Marco. Tutti modificano tutto, ma nella scheda ogni colonna mostra «a cura di …» col colore dell'Excel, e ogni modifica resta nel registro (chi/quando).
7. **Stato della richiesta** (manca nel piano): Ricevuta → In lavorazione → Pubblicata (oppure Respinta). Il docente vede le sue richieste e lo stato nell'area personale.
8. **Righe a mano.** La tabella di monitoraggio deve contenere anche le iniziative arrivate per altre vie: dal pannello si può aggiungere una riga senza passare dal form (va subito nel file Excel).
9. **L'Excel NON si riempie da solo (deciso 19/11).** La richiesta del docente arriva tra le «Richieste arrivate»; entra nella tabella di monitoraggio (e quindi nel file Excel)
   solo quando un operatore preme **«Invia al file Excel»** nella richiesta. È in quel momento che D, E, L, P, Q, R si precompilano dai dati del docente,
   e da lì si possono completare le colonne degli operatori. Si può tornare indietro con «Togli dal file Excel» (le colonne compilate restano salvate).
   Una richiesta «Respinta» non si può inviare al file Excel.

Piccole cose da rispettare: sottotitolo max 75 e descrizione max 170 caratteri (contatore a schermo + controllo sul server, contando il testo senza formattazione);
descrizione con grassetto/corsivo (`pulisci_html_semplice()`), nell'Excel esce come testo semplice; allegati PDF/JPG/PNG, «Ulteriori immagini» solo JPG/PNG;
data di inizio pubblicazione non nel passato; richiedente preso dall'account («Cognome Nome»), non modificabile.

**Da sistemare nel Word (tocca a te):** c'è ancora la nota «PER EMA: VALUTA QUESTO PUNTO O ELIMINA» sulle immagini nei PDF. Proposta: la frase resta
(accettiamo immagini solo JPG/PNG, non estraiamo immagini dai PDF), la nota «PER EMA» si toglie.
Attenzione anche a «Note» obbligatorio: va bene come hai chiesto, ma alcuni scriveranno «nessuna».

---

## 1. Ordine dei passi

| Passo | Cosa | Commit |
|---|---|---|
| R1 | Tabelle, classi e regole (niente pagine) | `documentazione/commit_R1.txt` |
| R2 | Pagina guidata del docente + email + area personale | `documentazione/commit_R2.txt` |
| R3 | Pannello: elenco, scheda da completare, export Excel/PDF/CSV | `documentazione/commit_R3.txt` |
| R4 | Rifiniture: guida, mappe, prove | `documentazione/commit_R4.txt` |

Ogni passo in una **sessione Sonnet nuova**, solo dopo che il precedente è approvato qui e committato.

---

## 2. Regole comuni

Sono scritte per intero nel blocco R1; negli altri blocchi va incollata la stessa parte «Regole del progetto», cambiando solo il nome del file del commit.

---

## 3. Passo R1 — Tabelle e regole (sessione Sonnet nuova)

```
Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. Leggi prima documentazione/richieste_pubblicazione_sonnet.md (sezione 0: verdetto e decisioni) e i due file in materiali/pubblicazioni/ (il form Word e l'Excel di monitoraggio, 22 colonne A–V). Fai SOLO il passo R1.

Regole del progetto:
- Nessun ALTER/CREATE nelle pagine: le tabelle si cambiano solo in src/Core/Schema/Definizioni.php alzando Migrazioni::VERSIONE (prossima versione dopo l'ultima presente).
- Codice nuovo in src/Pubblicazioni/ (classi con declare(strict_types=1), il contenitore le collega da solo); funzioni-facciata in inc/ solo se servono alle pagine. Non ridefinire funzioni già presenti.
- File PHP senza BOM UTF-8. Escape di tutto l'output. Ogni POST con csrf_verify e controlli lato server. Flash con flash_set (senza escape dentro).
- Riusa ciò che c'è: App\Auth\Pubblico (profilo docenti), il salvataggio e i controlli degli allegati già usati da MyForms/pratiche (ServizioPratiche::conservaAllegati / AllegatiPratiche: stesse cartelle protette, stessi controlli di tipo e dimensione), pulisci_html_semplice(), l'invio email del portale (Mailer/InvioEmail), App\Infrastructure\Documenti\TabellaEsportabile, il registro audit_log, lo stile delle tessere dell'area personale. La testata del sito non si tocca.
- Testi in italiano semplice, niente gergo tecnico.
- Test: PHPUnit 9.6 in %TEMP%\phpunit96, percorsi assoluti (--bootstrap "%TEMP%\phpunit96\bootstrap.php" --no-configuration <cartella>), una cartella alla volta; per i test col database avvia mysqld di XAMPP con --standalone e fermalo alla fine. Fallimenti GIA' presenti (ZipArchive, xlsx, ConvenzioniTesi, Portale widget home, Risorse testCostantiDelleRisorse, Tutorato, Integration DaFareEdEmailTest «veicolo»): confronta prima/dopo e dimmi solo le differenze.
- C:\xampp\php\php.exe -l su ogni file toccato.
- MAI git stash (cartella su Google Drive). NON fare commit, push o altri comandi git che modificano qualcosa: messaggio in documentazione/commit_R1.txt, senza Co-Authored-By né riferimenti a Claude, con in fondo l'elenco esatto dei file da aggiungere (escluso il file del messaggio).
- Se una scelta non è scritta qui o nel documento, fermati e chiedimi.

Alla fine: resoconto breve (file, cosa fa, scelte tue, test prima/dopo, dubbi).

PASSO R1 — Richieste di pubblicazione: tabelle, classi e regole. Nessuna pagina.
1. Schema (nuova versione):
   - pubblicazioni: id; utente_id (NULL per le righe a mano); richiedente (testo «Cognome Nome», colonna B); origine ('form'|'manuale');
     stato ('ricevuta'|'lavorazione'|'pubblicata'|'respinta'); inviata_il; modificata_il; modificata_da;
     nel_monitoraggio (tinyint, 0 alla ricezione), monitoraggio_dal (DATETIME NULL), monitoraggio_da (utente NULL): la riga entra nel file Excel SOLO quando un operatore la invia.
     Dati del docente: data_evento DATE (A); url_docente; altri_link_docente (NULL); titolo (G); sottotitolo (max 75); descrizione (HTML semplice, max 170 caratteri di testo, H);
     modalita ('presenza'|'online'|'mista', I); per_cittadinanza, per_studenti, per_docenti, per_pta (tinyint: cosa ha spuntato il docente, non si modificano);
     note; tag; data_inizio_pubblicazione.
     Colonne degli operatori, tutte testo libero NULL, una per colonna dell'Excel, con nome che ricorda la lettera e il contenuto:
     c_altri_referenti, d_url_pubblicazione, e_url_altre_pagine, f_tipo_iniziativa, j_dimensione_geografica, k_pubblicazione_social, l_dest_cittadinanza,
     m_ind_dibest_01, n_ind_dibest_02, o_piattaforma_pe, p_dest_studenti, q_dest_docenti, r_dest_pta, s_ind_dibest_03, t_ind_dibest_04, u_produzione_locandina, v_n_partecipanti.
     Indici su stato, data_evento, utente_id.
   - pubblicazioni_allegati: id, pubblicazione_id (FK, cancellazione a cascata), tipo ('allegato'|'immagine'), nome originale, percorso protetto, dimensione, caricato_il.
   - L'indirizzo di notifica come impostazione globale (usa il sistema di impostazioni che il portale ha già; se ce ne sono due, chiedimi quale), valore iniziale comunicazione.dibest@unical.it, più indirizzi ammessi come in NotificheForms::indirizzi.
2. src/Pubblicazioni/:
   - ColonneMonitoraggio: l'elenco UNICO e ordinato delle colonne dell'export (chiave, intestazione IDENTICA all'Excel, «a cura di», colore dell'intestazione preso dall'Excel), con «Sottotitolo» subito dopo «Titolo e sottotitolo iniziativa» (che diventa la colonna del titolo; tieni l'intestazione originale e chiedimi se rinominarla in «Titolo»). Tutte le pagine e l'export leggono da qui.
   - PubblicazioniRepository: inserisci, perId, elenco con filtri (stato, periodo per data_evento, testo), delUtente, aggiornaColonneOperatori, cambiaStato, elimina (con i file).
   - ServizioPubblicazioni:
     * valida(array $dati, array $file): errori per campo. Obbligatori TUTTI tranne altri_link_docente e le immagini; almeno un destinatario; sottotitolo ≤ 75 e descrizione ≤ 170 caratteri (mb_strlen sul testo senza tag, spazi inclusi); URL validi (http/https); data_inizio_pubblicazione non prima di oggi; modalità tra le tre; almeno un allegato (PDF/JPG/PNG), immagini solo JPG/PNG; tipi controllati sul contenuto, non solo sull'estensione.
     * invia(utente, dati, file): solo se Pubblico dice che l'utente è docente; salva riga + allegati in una transazione; stato 'ricevuta', nel_monitoraggio = 0, colonne degli operatori vuote. Le email (R2) non devono mai far fallire il salvataggio.
     * inviaAlMonitoraggio(id, operatore): rifiuta se lo stato è 'respinta' o se è già dentro; nel_monitoraggio = 1, monitoraggio_dal/da; precompila SOLO le colonne ancora vuote: d_url_pubblicazione con url_docente, e_url_altre_pagine con altri_link_docente, l_dest_cittadinanza / p_dest_studenti / q_dest_docenti / r_dest_pta con «SI» per le caselle spuntate (se la riga era stata tolta e rimessa, quanto già scritto dagli operatori non si sovrascrive). Riga in audit_log.
     * togliDalMonitoraggio(id, operatore): nel_monitoraggio = 0, le colonne restano salvate. Riga in audit_log.
     * creaManuale(dati, operatore) per le righe a mano (stessi controlli tranne gli allegati, che lì sono facoltativi): entra subito nel monitoraggio.
     * aggiornaOperatori(id, dati, operatore): solo le colonne degli operatori + stato; le colonne si salvano solo se la riga è nel monitoraggio (lo stato si cambia sempre); scrive modificata_il/da e una riga in audit_log con le colonne cambiate (vecchio → nuovo). Passare a 'respinta' una riga nel monitoraggio -> chiedi conferma nella pagina e toglila dal monitoraggio.
   - PubblicazioniRepository::elenco ha anche il filtro «solo nel monitoraggio» / «solo non ancora inviate».
   - EsportazioneMonitoraggio::tabella(righe): SOLO le righe con nel_monitoraggio = 1, intestazioni e ordine da ColonneMonitoraggio, date gg/mm/aaaa, descrizione senza HTML, modalità e stato in parole; apostrofo anti-formula solo nel CSV, come in EsportazioneRisposte.
3. Test: Unit su valida (limiti 75/170 al carattere esatto con lettere accentate, destinatari, URL, date, tipi di file) e sull'export (22+1 colonne, intestazioni identiche all'Excel, ordine); Integration su invia (non docente rifiutato, riga e allegati salvati, NON nel monitoraggio e colonne operatori vuote), inviaAlMonitoraggio (precompilazione di D, E e «SI»; respinta rifiutata; tolta e rimessa non sovrascrive), togliDalMonitoraggio, creaManuale (subito dentro), aggiornaOperatori (non tocca i campi del docente, audit_log scritto), export (solo le righe nel monitoraggio).
```

---

## 4. Passo R2 — Procedura guidata del docente (sessione Sonnet nuova, dopo R1 approvato e committato)

```
[Incolla qui sopra le stesse «Regole del progetto» del passo R1, cambiando commit_R1.txt in commit_R2.txt.]

PASSO R2 — Richieste di pubblicazione: la pagina del docente.
1. richiesta_pubblicazione.php (pagina pubblica con la testata del sito come le altre):
   - Senza accesso -> «Accedi per compilare» con ritorno a questa pagina (meccanismo di ritorno già esistente, solo indirizzi interni). Accesso fatto ma non docente -> «Questa richiesta è riservata ai docenti».
   - Procedura a passi con barra di avanzamento (Passo 1 di 7) e pulsanti Indietro / Avanti; si passa al passo dopo solo se quello attuale è compilato (controllo nel browser), ma il controllo vero è sul server all'invio: se c'è un errore la pagina si riapre sul passo con l'errore, con tutto quello che era stato scritto. Senza JavaScript i passi si vedono tutti uno sotto l'altro e il form funziona lo stesso. Se MyForms o la Didattica hanno già un componente a passi, riusalo.
     Passo 1 «Prima di iniziare»: le Note operative del Word (invio solo da qui, testo pubblicato così come arriva, immagini solo .jpg/.png, i PDF pubblicati come «Locandina/Brochure») + casella «Ho letto» obbligatoria.
     Passo 2 «L'evento»: Richiedente (dall'account, solo lettura), Data dell'evento, Modalità di svolgimento (In presenza / Online / Mista), URL, URL/Link ad altre pagine (facoltativo) con l'aiuto del Word.
     Passo 3 «I testi»: Titolo (con l'aiuto «compare sulla Homepage in anteprima»), Sottotitolo (contatore «12 / 75»), Breve descrizione con grassetto e corsivo (contatore «… / 170», sul testo senza formattazione). Il contatore diventa rosso oltre il limite.
     Passo 4 «A chi è rivolto»: Cittadinanza, Studenti universitari, Docenti, PTA (almeno uno). Se si spunta Cittadinanza compare il riquadro del Public Engagement del Word con il link https://pe.unical.it (nuova scheda).
     Passo 5 «Allegati e immagini»: Allegati da pubblicare (PDF/JPG/PNG, più file, obbligatorio) con il consiglio sul nome del file; Ulteriori immagini (JPG/PNG, facoltativo) con l'aiuto del Word. Limiti di dimensione quelli già usati dal portale, scritti a schermo.
     Passo 6 «Pubblicazione»: Tag (separati da virgola, con l'esempio del Word), Data inizio pubblicazione, Note.
     Passo 7 «Riepilogo»: tutto quello che è stato scritto, con «Modifica» accanto a ogni sezione che riporta al passo; pulsante «Invia la richiesta».
   - Invio -> ServizioPubblicazioni::invia; dopo l'invio redirect (F5 non duplica) con «Richiesta inviata il … : la riceve l'ufficio comunicazione».
2. Email (dentro il servizio, errori solo nel log):
   - agli indirizzi dell'impostazione: oggetto «Richiesta di pubblicazione: <titolo>», tutti i campi in ordine e leggibili (descrizione con la sua formattazione), elenco dei file allegati, pulsante alla scheda nel pannello (admin/pubblicazioni.php?id=). I file NON si allegano all'email: si scaricano dalla scheda.
   - al docente: ricevuta con la copia di quanto ha inviato.
3. Area personale del docente: tessera «Le mie richieste di pubblicazione» nello stile delle altre: titolo, data dell'evento, stato in parole («Ricevuta», «In lavorazione», «Pubblicata» con il link della colonna D se c'è, «Respinta») e il pulsante «Nuova richiesta». Il docente non può modificare una richiesta già inviata (scrivilo nella tessera: «per correzioni scrivi a <indirizzo>»).
4. Scaricamento degli allegati solo tramite una pagina che controlla i permessi (come admin/forms_allegato.php): il docente che ha inviato la richiesta e gli operatori del modulo.
5. Prova nel browser con il server di sviluppo: non loggato, studente (rifiutato), docente; errori su ogni passo (limiti 75/170, nessun destinatario, nessun allegato, PDF finto rinominato .jpg, data nel passato); riquadro Cittadinanza; riepilogo e «Modifica»; F5 dopo l'invio; email nel registro log_email. Pulisci la base dati di prova alla fine.
```

---

## 5. Passo R3 — Pannello degli operatori ed export (sessione Sonnet nuova, dopo R2)

```
[Incolla qui sopra le stesse «Regole del progetto» del passo R1, cambiando commit_R1.txt in commit_R3.txt.]

PASSO R3 — Richieste di pubblicazione: il pannello.
1. Modulo nuovo del pannello in inc/sezioni.php: 'pubblicazioni' («Richieste di pubblicazione», icona e colore a tua scelta, diversi da MyForms), abilitabile per persona come gli altri moduli; NON dentro il modulo 'forms'. Aggiungilo anche a strumenti/prove/esegui.php dove sono elencati i moduli.
2. admin/pubblicazioni.php — due schede in alto:
   a) «Richieste arrivate» (si apre questa): le richieste dei docenti NON ancora nel file Excel. In alto: quante da guardare (ricevute), in lavorazione, respinte.
      Tabella: inviata il, data evento, titolo, richiedente, stato; per ogni riga il pulsante «Invia al file Excel» (POST con conferma, disattivato e spiegato se respinta) e «Apri».
   b) «Tabella di monitoraggio (file Excel)»: SOLO le righe inviate al file Excel. In alto: righe nel file, con colonne da completare, pubblicate quest'anno.
      Tabella: data evento, titolo, richiedente, modalità, stato, «quante colonne mancano» (colonne degli operatori vuote, es. «6 da completare»). Filtri: stato, periodo, testo. Ordinata per data evento più recente.
      Pulsanti «Nuova riga a mano» e «Scarica» (Excel, PDF, CSV con TabellaEsportabile / pn_scarica, rispettando i filtri): colonne e intestazioni da ColonneMonitoraggio, identiche all'Excel di monitoraggio (+ Sottotitolo).
   La scheda scelta resta nell'indirizzo (?vista=), così si torna al punto giusto dopo il salvataggio.
3. admin/pubblicazioni.php?id= — scheda. In testa una fascia chiara: «Non ancora nel file Excel» con il pulsante «Invia al file Excel», oppure «Nel file Excel dal gg/mm/aaaa (da …)» con il pulsante «Togli dal file Excel» (conferma: le colonne compilate restano salvate).
   - Sopra, in sola lettura, quello che ha inviato il docente (con URL e altri link del docente, cosa aveva spuntato, note, tag, data inizio pubblicazione, allegati e immagini scaricabili) e un pulsante «Copia il testo» per titolo + sottotitolo + descrizione.
   - Sotto (SOLO se la riga è nel file Excel; altrimenti al loro posto la frase «Le colonne della tabella di monitoraggio si compilano dopo aver premuto Invia al file Excel» e solo il cambio di stato), le colonne degli operatori raggruppate per persona come nell'Excel («a cura di Floriana Mantovano», «a cura di Silvana Geracitano», «a cura di Gianni Giglio», «a cura di Marco Bocci o Emanuele Dodaro»), ognuna col colore della sua intestazione nell'Excel; lo stato; «Salva». Le colonne D ed E arrivano già compilate con i link del docente: accanto mostra «scritto dal docente: …» se sono state cambiate. Le colonne SI/NO (Pubblicazione social, Piattaforma PE, Produzione locandina) come tendina SI / NO / vuoto; N. Partecipanti numero; le altre testo libero.
   - Ultima modifica: chi e quando; elenco delle modifiche da audit_log.
   - «Elimina» con conferma (cancella anche i file).
4. Impostazione dell'indirizzo di notifica nel modulo (solo per chi ha il modulo), con controllo degli indirizzi.
5. Prova nel browser: una richiesta appena arrivata NON compare nella tabella di monitoraggio né nell'export; «Invia al file Excel» la sposta con D, E e «SI» precompilati; «Togli dal file Excel» e reinvio senza perdere le colonne; richiesta respinta non inviabile; operatore col solo modulo pubblicazioni (non vede MyForms), operatore senza modulo (rifiutato), modifica e audit, riga a mano (subito nel file), export nei tre formati aperto e confrontato con l'Excel originale (stesse intestazioni nello stesso ordine), filtri applicati all'export.
```

---

## 6. Passo R4 — Rifiniture (sessione Sonnet nuova, dopo R3)

```
[Incolla qui sopra le stesse «Regole del progetto» del passo R1, cambiando commit_R1.txt in commit_R4.txt.]

PASSO R4 — Richieste di pubblicazione: rifiniture.
1. Guida per i docenti (dove sono le altre guide del portale) e scheda per gli operatori: cosa fa ogni colonna e chi la compila.
2. Mappe del sito / inventario degli accessi (documentazione/inventario_accessi.md): pagina docente, pannello, scaricamento allegati, con chi può entrare.
3. Link alla pagina del docente dove i docenti la trovano (area personale e l'eventuale elenco dei servizi per i docenti), senza toccare la testata.
4. Conservazione: chiedimi per quanto tempo tenere allegati e righe prima di scrivere qualsiasi cancellazione automatica.
5. Elenco dei controlli da fare io nel browser sul server di prova (mydibest_pp) prima del caricamento in produzione.
6. Server (apache2 su Ubuntu): i valori di partenza di PHP (upload_max_filesize 2M, post_max_size 8M) non bastano. Scrivimi i comandi per controllarli e per portarli a upload_max_filesize = 10M, post_max_size = 64M, max_file_uploads ≥ 20 (solo istruzioni: non li cambio io dal portale). Il messaggio «massimo 10 MB» degli allegati deve prendere il limite vero da LimitiCaricamento, non un numero fisso.
8. inc/base.php: aggiungi l'etichetta mancante 'modulo_forms' => «Modulo MyForms» accanto a 'modulo_pubblicazioni' (svista di MyForms trovata in R3).
9. Commit unico: scrivi documentazione/commit_pubblicazioni.txt che unisce commit_R1..R4 in un solo messaggio, con in fondo l'elenco COMPLETO dei file di R1–R4.
7. Copia di prova (strumenti/crea_ambiente_prova.sh): nella copia per mydibest_pp anonimizza pubblicazioni.richiedente e non copiare uploads/pubblicazioni/. Il file ha già modifiche mie non committate: aggiungi le tue senza toccare le altre righe e dimmi quali sono.
```

---

## 7. Dopo ogni passo

Incolla il resoconto di Sonnet nella chat di revisione di Opus. Opus controlla i file, risponde alle domande di Sonnet e dice se si può committare
(messaggio già pronto in `documentazione/commit_R<n>.txt`, autore Emanuele Dodaro, nessun riferimento a Claude).

---

## 8. Passo R4 — fatto (19/11/2026)

R1–R3 non sono stati committati uno per uno: si fa **un solo commit**, il cui messaggio è in `documentazione/commit_pubblicazioni.txt` (con in fondo l'elenco dei file da aggiungere).

In R4: guide «Docenti: chiedere la pubblicazione di un'iniziativa» e «Richieste di pubblicazione: per chi le gestisce» (`src/Portale/GuidePerRuolo.php`, pagina `guida.php`; la seconda legge l'elenco delle colonne da `ColonneMonitoraggio`),
mappe (13b nella mappa per tutti, 13i nella mappa tecnica, schema v128) e `inventario_accessi.md` (sezione M), voce nel catalogo delle pagine del menu e due voci di Didacticus (`didacticus_voci_pubblicazioni_20261119.csv`),
messaggio «massimo N MB» degli allegati letto da `LimitiCaricamento`, etichetta `modulo_forms`, copia di prova (richiedente reso anonimo, `uploads/pubblicazioni/` non copiata).

---

## 9. Server: limiti di PHP per gli allegati (solo istruzioni, non si cambiano dal portale)

Servono almeno `upload_max_filesize = 10M`, `post_max_size = 64M`, `max_file_uploads = 20` (10 allegati + 10 immagini). I valori che PHP ha di partenza (`upload_max_filesize` 2M, `post_max_size` 8M) non bastano.
Un invio più grande di `post_max_size` PHP lo scarta in silenzio: la pagina lo riconosce e lo dice, e gli amministratori vedono in cima alla richiesta un avviso rosso se il server è troppo stretto.

**Attenzione, prima di cambiare qualcosa.** Dall'inventario dell'11/11/2026 (`inventario_apache_20261111_1927.txt`) il portale gira in **PHP-FPM 8.4** (servizio `php84-fpm`, `/opt/php84/etc/php.ini`), non dentro Apache: `libapache2-mod-php8.3` è disattivato,
quindi il `php.ini` di Apache (`/etc/php/8.3/apache2/php.ini`) **non conta**. Quel `php.ini` è **condiviso con Nextcloud e LibreBooking** e in quell'inventario valeva `upload_max_filesize=10G` e `post_max_size=10G`:
se è ancora così, per la richiesta di pubblicazione **non c'è niente da cambiare** e abbassarlo a 10M/64M **romperebbe i caricamenti grandi di Nextcloud**. Quindi: prima si controlla, si cambia solo se i valori sono più bassi di quelli che servono.

**1. Controllare (sola lettura)**

```bash
# quali file di configurazione legge PHP 8.4 e che valori hanno
/opt/php84/bin/php --ini
/opt/php84/bin/php -r 'foreach (["upload_max_filesize","post_max_size","max_file_uploads"] as $k) echo $k, " = ", ini_get($k), PHP_EOL;'
grep -nE '^\s*(upload_max_filesize|post_max_size|max_file_uploads)\s*=' /opt/php84/etc/php.ini
# regole che li ridefiniscono per il solo PHP-FPM (se escono righe, valgono queste)
grep -nE 'php_(admin_)?value' /opt/php84/etc/php-fpm.conf /opt/php84/etc/php-fpm.d/*.conf 2>/dev/null
# limite di Apache sul corpo delle richieste (nessuna riga = nessun limite)
grep -rn 'LimitRequestBody' /etc/apache2/
```

La prova più sicura è la pagina stessa: come amministratore apri `richiesta_pubblicazione.php`; al passo «Allegati e immagini» c'è la frase con i limiti veri («Ogni file può pesare al massimo … in tutto … al massimo …»)
e, se il server è troppo stretto, un avviso rosso in cima. (La riga `php` da terminale mostra i valori della riga di comando, che di solito coincidono con quelli di PHP-FPM ma non sempre.)

**2. Se i valori sono più bassi (e solo allora): portarli a 10M / 64M / 20**

```bash
sudo cp -a /opt/php84/etc/php.ini /opt/php84/etc/php.ini.prima_pubblicazioni
sudo sed -i -E 's/^\s*upload_max_filesize\s*=.*/upload_max_filesize = 10M/; s/^\s*post_max_size\s*=.*/post_max_size = 64M/; s/^\s*max_file_uploads\s*=.*/max_file_uploads = 20/' /opt/php84/etc/php.ini
# se max_file_uploads era commentata (riga che comincia per «;») il comando sopra non l'ha toccata: la aggiungo
grep -qE '^\s*max_file_uploads\s*=' /opt/php84/etc/php.ini || echo 'max_file_uploads = 20' | sudo tee -a /opt/php84/etc/php.ini
grep -nE '^\s*(upload_max_filesize|post_max_size|max_file_uploads)\s*=' /opt/php84/etc/php.ini
```

Se invece i valori sono già **più alti** (come l'11/11) lascia stare: bastano. Se si vuole un limite diverso solo per il portale, dillo: si fa con un file `.user.ini` nella cartella del portale (da proteggere dal web), senza toccare Nextcloud.
Nota: 10 allegati + 10 immagini da 10 MB sarebbero 200 MB, ma con `post_max_size = 64M` l'intero invio non può superare 64 MB: la pagina lo scrive a schermo e, se si supera, lo spiega.

**3. Riavviare**

```bash
sudo systemctl reload php84-fpm       # rilegge php.ini senza interrompere le richieste in corso (se i valori non cambiano, usare: restart)
sudo systemctl status php84-fpm --no-pager | head -5
# solo se hai toccato anche file di Apache (per esempio LimitRequestBody):
sudo apache2ctl configtest && sudo systemctl restart apache2
```

Poi si rifà il controllo del punto 1 (la pagina) e si prova a caricare un allegato da circa 9 MB.

---

## 10. Conservazione di righe e allegati — DA DECIDERE (promemoria)

**Nessuna cancellazione automatica è stata scritta.** Righe (tabella `pubblicazioni`: contengono il nome del richiedente, i testi e i link) e allegati (`uploads/pubblicazioni/`, fino a 20 file da 10 MB per richiesta) restano finché un operatore non preme «Elimina» nella scheda.
Da decidere: per quanto tempo tenere le righe (e quindi la tabella di monitoraggio) e per quanto tempo i soli allegati (che pesano di più). Precedente nel portale: MyForms ha «conserva per N mesi» con avviso 7 giorni prima (`CronForms`).
Quando c'è la risposta si scrive il compito nel cron, con un avviso prima di cancellare e con le prove.

---

## 11. Controlli da fare nel browser su mydibest_pp prima della produzione

Server di prova: `https://dibest2.unical.it/mydibest_pp` (le email arrivano solo all'indirizzo di prova). Spunta man mano; in caso di dubbio ferma e scrivi.

**Preparazione**
- [ ] Entra come amministratore: compare la fascia «copia di prova» e il pannello si apre senza errori (lo schema passa alla v128 al primo accesso al pannello).
- [ ] In Utenti, abilita il modulo «Richieste di pubblicazione» a un utente di prova (non amministratore) e tieni un secondo utente senza il modulo.
- [ ] Controlla i limiti del server (§9): su `richiesta_pubblicazione.php`, come amministratore, nessun avviso rosso e la frase con i limiti veri.
- [ ] La copia di prova è anonima: nel pannello le richieste che ci sono (se ci sono) hanno nomi finti; `uploads/pubblicazioni/` sul server è vuota (solo `.htaccess`); il registro delle email (`log_email`) parte vuoto.

**Il docente**
- [ ] Da non connesso: `richiesta_pubblicazione.php` dice «Accedi per compilare»; dopo l'accesso si torna alla pagina.
- [ ] Come studente («Entra come»): «Questa richiesta è riservata ai docenti».
- [ ] Come docente: passo 1 non va avanti senza «Ho letto»; Avanti/Indietro funzionano e la barra di avanzamento cambia.
- [ ] Errori, uno alla volta: sottotitolo di 76 caratteri; descrizione di 171 (il contatore diventa rosso); nessun destinatario; nessun allegato; un PDF finto rinominato `.jpg`; data di inizio pubblicazione nel passato. Ogni volta la pagina dice dove e non si perde il testo.
- [ ] «Cittadinanza» fa comparire il riquadro del Public Engagement e il link a pe.unical.it si apre in una nuova scheda.
- [ ] Un file da circa 9 MB passa; uno da più di 10 MB è rifiutato con «massimo 10 MB» (o il limite vero del server); un invio troppo grande per `post_max_size` dà un messaggio chiaro, non una pagina vuota.
- [ ] Riepilogo: «Modifica» riporta al passo giusto. «Invia la richiesta» manda; compare «Richiesta inviata il …»; con F5 la richiesta non si duplica.
- [ ] Sul telefono (o a finestra stretta) i passi si leggono e si compilano.
- [ ] Email (arrivano all'indirizzo di prova): avviso all'ufficio con tutti i campi e ricevuta al docente. Il pulsante «Apri la scheda» apre **mydibest_pp** (non il sito vero) e, da operatore, la scheda giusta.
- [ ] Area personale del docente: la tessera «Le mie richieste di pubblicazione» mostra titolo, data e stato «Ricevuta»; «Nuova richiesta» apre la pagina; un docente riconosciuto solo dall'anagrafe vede la tessera e la pagina lo lascia entrare (vedi inventario_accessi.md, sezione M).
- [ ] Allegati: il docente che ha inviato scarica i suoi file; un altro docente e un non connesso ricevono «File non trovato»; l'indirizzo diretto `…/uploads/pubblicazioni/nomefile` non si apre.

**Gli operatori**
- [ ] L'operatore con il solo modulo vede «Richieste di pubblicazione» e **non** vede MyForms; l'utente senza modulo riceve «accesso negato» da `admin/pubblicazioni.php` e «File non trovato» da `allegato_pubblicazione.php`.
- [ ] «Richieste arrivate»: la richiesta di prova c'è, con i numeri in alto; **non** compare nella «Tabella di monitoraggio» e **non** nello scaricamento.
- [ ] «Invia al file Excel» chiede conferma; poi la richiesta è nella tabella con D ed E precompilate e «SI» nei destinatari scelti.
- [ ] «Togli dal file Excel» la porta fuori; inviandola di nuovo le colonne scritte non si perdono.
- [ ] Una richiesta «Respinta» non si può inviare al file Excel; portare in «Respinta» una riga che è nel file chiede conferma e la toglie.
- [ ] Nella scheda: modifica di una colonna per persona, «Salva», registro delle modifiche e «ultima modifica» corretti; «Copia il testo» copia titolo, sottotitolo e descrizione.
- [ ] «Nuova riga a mano»: bastano data, richiedente e titolo; entra subito nel file.
- [ ] «Scarica» in Excel, PDF e CSV, anche con un filtro: apri l'Excel e confronta le intestazioni con `DiBEST_Tabella di monitoraggio.xlsx` (stesse, nello stesso ordine; in più «Sottotitolo iniziativa» e «Stato»; la G si chiama «Titolo iniziativa»). Nel CSV un valore che comincia con `=` ha l'apostrofo.
- [ ] Impostazioni: un indirizzo non valido non salva niente; uno valido salva (poi rimetti `comunicazione.dibest@unical.it`).
- [ ] «Elimina» chiede conferma e, sul server, anche il file sparisce da `uploads/pubblicazioni/`.

**Guide e ricerca**
- [ ] `guida.php`: ci sono «Docenti: chiedere la pubblicazione di un'iniziativa» e «Richieste di pubblicazione: per chi le gestisce»; l'elenco delle colonne della seconda coincide con l'Excel; «Versione da stampare» si apre.
- [ ] La ricerca del sito per «pubblicazione» trova le due guide.

**Prima di caricare in produzione**
- [ ] Backup del database fatto.
- [ ] Pulizia della prova: elimina le richieste di prova e rimetti l'indirizzo delle notifiche, se l'hai cambiato.
- [ ] In produzione, dopo il caricamento: l'indirizzo delle notifiche è quello giusto (Impostazioni del modulo) e il modulo è abilitato a Floriana, Gianni, Silvana e Marco; si invia una richiesta vera di prova e si elimina.
- [ ] I limiti PHP di produzione (§9) controllati.
