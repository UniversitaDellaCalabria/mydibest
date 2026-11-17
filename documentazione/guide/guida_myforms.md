# MyForms: guida per chi crea i moduli

MyForms serve a raccogliere risposte con un modulo online: un questionario, una raccolta di informazioni, un'iscrizione. Tu scrivi le domande, scegli a chi mandarlo e poi leggi (e scarichi in Excel) le risposte. Non servono altri programmi.

Chi può creare i moduli: gli amministratori e le persone abilitate a MyForms. Si entra dal pannello di gestione, voce **MyForms**. Chi è solo co-gestore di un modulo (vedi più sotto) lo trova nello stesso posto, ma vede soltanto i suoi.

---

## 1. Creare un modulo

1. In MyForms premi **Nuovo form**.
2. Scrivi il **titolo** (è obbligatorio) e, se vuoi, una **descrizione**: compare sopra le domande.
3. Scegli **a chi si rivolge** (sezione 4).
4. Imposta le **opzioni** (sezione 5).
5. Aggiungi le **domande** con **Aggiungi campo** (sezione 2 e 3).
6. Premi **Salva**. Il modulo nasce in **bozza**: non lo vede nessuno.
7. Premi **Anteprima** per provarlo come lo vedrà chi risponde (non salva niente).
8. Quando è pronto, in «Stato del form» premi **Apri**. Il portale non lo apre se mancano il titolo, almeno una domanda o i profili a cui si rivolge.

Per fare un modulo simile a uno che hai già, usa **Duplica**: nasce una copia in bozza, senza risposte né co-gestori.

## 2. Le domande

Per ogni domanda scrivi il testo, scegli il **tipo**, e se serve metti la spunta **obbligatorio** e un **aiuto** (una riga che spiega come rispondere). Ecco i tipi:

- **Testo breve** e **testo lungo**.
- **Email**, **telefono**, **numero**, **data**, **ora**, **indirizzo web**, **codice fiscale**: il portale controlla che siano scritti bene.
- **Tendina**, **scelta singola**, **scelta multipla** e **casella sì/no**: per le prime tre scrivi le opzioni nella colonna «Opzioni o colonne», separate da virgola (per esempio: Sì, No, Non so).
- **Dichiarazione da accettare**: una casella da spuntare per accettare un testo, per esempio «dichiaro di aver letto l'informativa».
- **Allegato**: chi risponde carica un PDF o un'immagine. Attenzione: per caricare un file bisogna aver fatto l'accesso (sezione 4).
- **Tabella a righe**: chi risponde aggiunge tante righe quante ne servono (per esempio un elenco di esami). Con l'icona dei cursori imposti le colonne.
- **Titolo di sezione** e **testo informativo**: non sono domande, servono a dividere e spiegare.

Se togli o cambi una domanda quando il modulo ha già risposte, le risposte già date restano com'erano; la domanda nuova, per quelle risposte, risulta vuota.

## 3. Domande che compaiono solo se serve

A volte una domanda ha senso solo in certi casi: «Quale azienda?» solo se hai risposto «Sì» a «Hai già un tirocinio?». Si fa così:

1. Premi l'icona dei cursori accanto alla domanda.
2. Nella riga **Mostra solo se** scegli la domanda da guardare, il confronto (è uguale a, è diverso da, contiene, è compilato, è vuoto) e il valore (per esempio «Sì»).

Chi risponde vede la domanda comparire e sparire da sola. Due cose importanti:

- una domanda nascosta **non è mai obbligatoria**, anche se hai messo la spunta;
- se una domanda è rimasta nascosta, la sua risposta **non viene salvata** (nemmeno se la persona l'aveva scritta prima di cambiare idea): resta vuota e nel dettaglio di quella risposta non compare. In Excel la colonna c'è, ma la cella è vuota.

Con **Compila se** puoi anche far scrivere una domanda in automatico in base a un'altra risposta.

## 4. A chi si rivolge

Spunta uno o più profili. Il modulo è compilabile da chi appartiene ad almeno uno di quelli:

- **Chiunque, anche senza accesso**: basta il link.
- **Chiunque abbia fatto l'accesso**.
- **Studenti**, **Docenti**, **Personale tecnico amministrativo**, **Personale di Ateneo** (docenti, personale tecnico amministrativo e altro personale insieme).
- **Esterni**: hanno fatto l'accesso ma non sono di Ateneo.
- **Scuole** e **Aziende**.

> **Attenzione a «Scuole».** Chiunque abbia fatto l'accesso può dichiararsi scuola: non usarlo per cose riservate. Il profilo «Scuole» lo sceglie la persona stessa dalla pagina «Sono una scuola», indicando una scuola dell'elenco: il portale non controlla che lo sia davvero. Va bene per moduli pensati per le scuole (per esempio una richiesta di informazioni), non per raccogliere o mostrare dati riservati.

Gli amministratori possono sempre aprire il modulo. Chi ha un profilo non previsto trova scritto «Questo modulo è riservato a…» con l'elenco dei profili. Chi non ha fatto l'accesso e il modulo non è per tutti trova il pulsante **Accedi per compilare** e, dopo l'accesso, torna al modulo.

**Se il modulo è aperto a chiunque** e la persona non ha fatto l'accesso, il portale le chiede una domandina di controllo (una piccola somma, per fermare i robot) e limita gli invii dalla stessa connessione. Se il modulo ha una domanda **allegato**, chi non ha fatto l'accesso non può rispondere: il portale gli dice di accedere. Se hai spuntato «Chiunque» e hai un allegato, nel pannello compare un avviso.

## 5. Le opzioni

- **Aperto dal / al**: il periodo in cui si può rispondere. Se lasci vuoto non c'è limite. Le date devono esistere davvero (non il 31 febbraio): il portale non le accetta altrimenti. Alla data di fine il modulo si chiude da solo (sezione 10).
- **N. massimo di risposte**: arrivati a quel numero il modulo si chiude da solo. 0 vuol dire nessun limite.
- **Una sola risposta per persona**: vale per chi ha fatto l'accesso. Chi non ha fatto l'accesso non si può riconoscere: per lui valgono solo il controllo e il limite di invii.
- **Si può modificare la risposta già data**: chi ha risposto riapre il modulo e corregge, finché il modulo è aperto.
- **Messaggio di ringraziamento**: la frase che compare dopo l'invio. Se non lo scrivi ne compare uno standard.
- **Email di notifica**: uno o più indirizzi (separati da virgola) che ricevono un'email a ogni nuova risposta, con il link alle risposte.
- **Manda una ricevuta per email a chi risponde**: chi ha fatto l'accesso riceve una email con il codice di ricevuta.
- **Conserva le risposte per (mesi)**: vedi la sezione 11.

Dopo l'invio chi risponde vede sempre sullo schermo un **codice di ricevuta**. Se ricarica la pagina (F5) la risposta non si duplica.

## 6. Risposte anonime

Spunta **Risposte anonime** quando vuoi che chi gestisce il modulo non sappia chi ha risposto.

Un modulo anonimo è **anonimo per chi lo gestisce**. In parole semplici:

- Il portale **non salva** il nome e l'email accanto alla risposta. Nelle risposte, nelle ricerche, in Excel, in CSV e in PDF **non compare nessuna colonna con il nome né con l'email**, e nemmeno nell'email di notifica.
- Però il portale tiene una **impronta**: un codice calcolato dal modulo e dalla persona, che non si può trasformare in un nome da chi guarda le risposte. Serve a due cose sole: **evitare che la stessa persona risponda due volte** (se hai spuntato «una sola risposta per persona») e **far ritrovare a ciascuno la propria risposta** nell'Area personale, tessera «I miei form», per correggerla se il modulo è modificabile.
- Nei moduli anonimi **la ricevuta non arriva per email**, nemmeno a chi ha fatto l'accesso: avere una email con l'indirizzo della persona e il titolo del modulo permetterebbe di capire chi ha risposto. Il codice di ricevuta resta visibile sullo schermo subito dopo l'invio.

Una precisione onesta: «anonimo» qui vuol dire anonimo **per chi gestisce il modulo**. Non è un anonimato assoluto: chi amministra il server, con strumenti tecnici, potrebbe verificare se una persona precisa ha risposto. Se serve un anonimato assoluto, questa funzione non basta.

Se spunti «Risposte anonime» su un modulo che ha già risposte, anche le risposte vecchie smettono di mostrare chi le aveva date.

## 7. Co-gestori

Un co-gestore è un collega che ti aiuta con il modulo. Può **modificarlo, aprirlo, chiuderlo, duplicarlo e leggere le risposte**. Non può eliminarlo né cambiare i co-gestori: lo fanno solo il proprietario e gli amministratori. Un co-gestore che non è abilitato a MyForms non può creare moduli nuovi.

Per aggiungerne uno, nella scheda del modulo, sezione **Co-gestori**, cerca la persona nell'anagrafe di Ateneo e scegli. La persona deve aver già fatto l'accesso al portale almeno una volta: se non l'ha mai fatto, chiedile di accedere e poi aggiungila.

## 8. Link e QR code

Nella scheda del modulo, **Link e QR da condividere** mostra l'indirizzo da mandare per email o mettere su una pagina, e il **QR code** da stampare su una locandina. Finché il modulo è in bozza chi apre il link trova «Modulo non trovato»; quando è chiuso o fuori periodo trova scritto il motivo e le date.

I moduli aperti compaiono anche nella pagina **MyForms · moduli da compilare** e nella ricerca del portale, **ma solo a chi può compilarli**: un modulo riservato agli studenti non si vede nemmeno in elenco a un docente.

## 9. Leggere le risposte ed Excel

In MyForms premi **Risposte (N)** accanto al modulo.

- **Tabella delle risposte**: data, profilo, codice di ricevuta e le prime domande. Nei moduli non anonimi ci sono anche nome ed email. Puoi **cercare nel testo**, filtrare per **periodo** e per **profilo**. Con «Azzera» togli i filtri.
- **Dettaglio**: aprendo una risposta vedi tutte le domande. Le domande rimaste nascoste dalle condizioni non compaiono. Gli allegati si scaricano da lì: il portale controlla ogni volta che tu possa gestire quel modulo.
- **Riepilogo**: per le domande a scelta (tendina, scelta singola o multipla, sì/no) vedi quante persone hanno scelto ogni opzione, con le percentuali e delle barre. Per le altre domande vedi solo quante risposte ci sono. Il riepilogo tiene conto dei filtri.
- **Scarica**: Excel, CSV o PDF, di tutte le risposte o solo di quelle filtrate. C'è una colonna per ogni domanda, nell'ordine del modulo; le scelte multiple sono separate da punto e virgola; le tabelle a righe stanno in una sola cella. Il nome del file è il titolo del modulo più la data. Le celle che comincerebbero con un segno di formula (= + - @) vengono scritte come semplice testo, così Excel non le esegue.
- **Scarica gli allegati in ZIP**: tutti i file allegati in un colpo solo (se il server lo permette).
- **Eliminare**: una risposta alla volta, oppure tutte (per quest'ultima devi scrivere il titolo del modulo). Con la risposta si cancellano anche i suoi allegati. Non si può annullare.

## 10. Chiusura automatica

Non devi ricordarti di chiudere il modulo. Ogni quarto d'ora il portale controlla i moduli aperti e li **chiude da solo** quando:

- è passata la data «al» che hai scelto, oppure
- le risposte hanno raggiunto il numero massimo.

Appena si chiude, il **proprietario** riceve una email: «il modulo X si è chiuso: N risposte», con il link per leggerle. Succede **una volta sola per modulo**. Se poi lo riapri a mano, il portale non lo richiude da solo: ricordati di cambiare la scadenza, altrimenti chi apre il link trova comunque scritto che il periodo è finito.

Un modulo chiuso a mano da te non genera nessuna email.

## 11. Conservazione: cancellazione delle risposte vecchie

Le risposte contengono dati delle persone: è bene non tenerle più del necessario. Nel campo **Conserva le risposte per (mesi)** scrivi per quanto tempo vuoi tenerle (per esempio 12). Se lo lasci vuoto, **le risposte non si cancellano mai da sole**.

Se scrivi un numero, il portale cancella da solo le risposte inviate da più di quel numero di mesi, **insieme ai file allegati**. Prima, però, ti avvisa:

1. Quando alcune risposte stanno per compiere il tempo, il **proprietario** riceve una email: «Tra 7 giorni si cancellano delle risposte» con quante sono, da che data (inviate prima del…) e il giorno in cui avverrà.
2. Nei 7 giorni puoi **scaricarle in Excel** dalla pagina delle risposte, oppure **aumentare i mesi** di conservazione nella scheda del modulo.
3. Passati i 7 giorni, si cancellano **solo le risposte nominate nell'avviso**. Se nel frattempo hai abbassato i mesi, le risposte in più non si toccano finché non arriva un nuovo avviso.

Altre cose da sapere:

- Il conto parte dal giorno in cui ogni risposta è stata inviata, non dalla chiusura del modulo.
- Se imposti la conservazione su un modulo che ha già risposte vecchie, **non si cancella niente subito**: prima arriva l'avviso, poi, dopo 7 giorni, la cancellazione.
- Ogni cancellazione automatica viene scritta nel registro delle attività del portale (con il nome del modulo e quante risposte), a nome del proprietario del modulo e con la dicitura «automatico».

## 12. Una lista da seguire

1. Nuovo form, titolo, descrizione.
2. Domande (con le condizioni, se servono).
3. A chi si rivolge. Anonimo? Allegati?
4. Periodo, numero massimo, una per persona, modifica, ricevuta, email di notifica.
5. Quanto tempo conservare le risposte.
6. Anteprima, poi Apri.
7. Mandare il link o stampare il QR.
8. Leggere le risposte, scaricare l'Excel prima della cancellazione.
