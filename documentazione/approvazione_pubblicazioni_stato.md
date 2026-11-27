# Approvazione pubblicazioni — stato

Piano: `documentazione/piano_approvazione_pubblicazioni.md`. Niente commit (sarà uno solo alla fine), niente `git stash`.

## P1 — Testi e obbligatorietà: FATTO in locale (26/11/2026)

### Cosa fa ora il form
1. **Note operative** modificabili: nuova impostazione `note_operative` (Impostazioni › Testi), testo di partenza dal piano parola per parola. Si salva come testo semplice «Titolo: testo», una voce per riga; nel form il titolo esce in grassetto.
2. **Frase del Direttore** («Richiesta di pubblicazione su pagina web e canali social istituzionali DiBEST, indirizzata al Direttore.») in evidenza (`.ui-intro-pagina`), sempre sopra il modulo, quindi visibile su tutti i passi. Il testo era già l'impostazione `testo_introduttivo`: cambia l'evidenza, non il dato.
3. **URL, note e tag facoltativi** di partenza; tolto «scrivi nessuna» dall'aiuto delle note.
4. **«Dove vuoi che si pubblichi?»**: impostazione `pagine_pubblicazione`, VUOTA di partenza («nome | indirizzo», una per riga). Con l'elenco vuoto il campo resta com'era (URL libero). Con l'elenco pieno: caselle + «Altra pagina del sito DiBEST». Controllo dominio: host che finisce in `.unical.it` e contiene «dibest»; `www.unical.it/…` dà «Scegli una pagina del DiBEST». Le pagine scelte si salvano in `url_docente`, una per riga (la colonna è VARCHAR(1000): `Costanti::MAX_URL`).
5. **Locandina**: un file obbligatorio e fisso (tipo `locandina` in `pubblicazioni_allegati`, PDF/JPG/PNG), sempre visibile (tiene il passo 5 anche se si nascondono gli altri file). «Altri allegati» e «Ulteriori immagini» facoltativi; `allegati_obbligatori` false di partenza (la migrazione lo azzera una volta sola).
6. **Download**: `allegato_pubblicazione.php` dà la locandina come `Locandina_<titolo breve>.<est>` (senza accenti e simboli, max 40 caratteri, spazi → `_`); il file su disco mantiene il suo nome. Gli altri file mantengono il nome originale.

### Schema v146
`Migrazioni::VERSIONE = 146`. Colonne in `impostazioni_sistema`: `pubblicazioni_note_operative` (con UPDATE del testo di partenza dove NULL), `pubblicazioni_pagine`; `pubblicazioni_allegati_obbligatori` DEFAULT 0 (+ UPDATE a 0 una tantum). P2 aggiunge le sue alla stessa v146. Provata sul DB locale `eventi_locale`: marker `schema_v146_*.ok` creato, note scritte, allegati_obbligatori = 0.

### File toccati
- Schema: `src/Core/Schema/Definizioni.php`, `src/Core/Schema/Migrazioni.php`
- Dominio: `src/Pubblicazioni/` → `ImpostazioniPubblicazioni`, `Costanti`, `RegolePubblicazione`, `AllegatiPubblicazioni`, `PubblicazioniRepository`, `ServizioPubblicazioni`, `ConfigurazioneCampi`, `PassiRichiesta`, `RiepilogoRichiesta`, `VistaRichiesta`
- Pagine: `richiesta_pubblicazione.php`, `allegato_pubblicazione.php` (solo commento), `admin/amministrazione_impostazioni.php`
- Asset: `assets/js/richiesta-pubblicazione.js` (`?v=6`), `assets/css/portale-ui.css`
- Guide: `src/Portale/GuidePerRuolo.php`
- Prove nuove: `tests/Unit/Pubblicazioni/NomeLocandinaTest.php`. Prove aggiornate: vedi sotto.

### Scelte fatte (da confermare se non vanno bene)
- Con l'elenco pagine pieno, un indirizzo che non è nell'elenco (anche da modulo manomesso) è trattato come «altra pagina» e deve essere del DiBEST.
- Le richieste vecchie (senza locandina) non hanno la riga «Locandina: —» in riepilogo/scheda; il tipo `allegato` resta com'è e si vede come «Altri allegati». Url/note/tag compilati si vedono come prima.
- Niente estensione `intl` in locale: gli accenti del nome-locandina si tolgono con una tabella `strtr` (come `NomeFile::slug`).
- Il form usa le variabili `--ui-*` (tema scuro) e nessun `style=` nuovo; aggiunta una regola per l'aiuto dei campi (`.form-text`) nel tema scuro, che prima era quasi illeggibile.
- Le righe inserite a mano dal pannello (`admin/pubblicazioni.php`, «Allegati da pubblicare») NON chiedono la locandina e salvano come tipo `allegato`: non l'ho toccato (è fuori da P1; da decidere insieme a P2+).

### Prove lanciate (un file per volta, PHPUnit 9.6, MariaDB accesa) — tutte verdi
Unit/Pubblicazioni: CampiNuoviOrdine 13/56 · CompilazioneInTabella 4/20 · ConfigurazioneCampi 16/143 · EsportazioneMonitoraggio 5/50 · ImpostazioniPubblicazioni 10/112 · MessaggioDimensioneAllegato 4/7 · **NomeLocandina 3/10 (nuovo)** · PaginaDocente 8/63 · PannelloPubblicazioni 8/47 · RegolePubblicazione 19/104.
Integration/Pubblicazioni: CampiAggiunti 4/63 · CompilazioneInBlocco 4/45 · ConfigurazioneCampi 12/84 · ImpostazioniRichiesta 8/243 · NotificheEScarico 9/73 · PannelloPubblicazioni 6/103 · ServizioPubblicazioni 15/151.
Unit/Portale: GuidePubblicazioni 4/51.
(formato: prove/asserzioni.) Prove nuove in `RegolePubblicazioneTest`: `testUrlNoteETagVuotiPassano`, `testLocandinaObbligatoriaEUnicaDelTipoGiusto`, `testIndirizzoNonDibestDaErroreConLElencoDelleVoci`. Prove vecchie aggiornate perché cambiava il testo/le regole: Regole, ConfigurazioneCampi (unit e integration), PaginaDocente, ImpostazioniPubblicazioni, CampiAggiunti, ImpostazioniRichiesta, NotificheEScarico, PannelloPubblicazioni (unit e integration), ServizioPubblicazioni.

### Verifiche a occhio
`documentazione/schermate_pubblicazioni_P1/`: `P1_passo1_tema_chiaro.png` (passo 1, 1100 px) e `P1_passo5_tema_scuro_telefono.png` (passo 5, tema scuro, 375 px). Fatte con il portale locale e l'utente «Prova Docenti»; il passo 5 è mostrato forzando la visibilità via JS (non cliccando i passi).

### Cosa NON ho potuto provare
- **`esegui.php` e `strumenti/prove/oop/pagine_amministrazione.php`**: non lanciati (regola del passo); possono avere testi/campi vecchi da aggiornare a P7.
- **Suite completa**: a P7.
- **Click reali nel browser** su caselle «pagine» e su «Avanti» passo per passo: provato solo il rendering del passo 1 e 5 e la logica PHP; il JS è controllato con `node --check`. Le caselle delle pagine non sono state viste a schermo perché l'elenco è vuoto di partenza (e non l'ho riempito).
- **Produzione**: se l'amministratore ha salvato in «Campi e colonne» `obbligatorio=1` per url/note/tag, i valori del DB vincono su quelli del codice: da controllare sul portale (o «Torna all'originale») a P7.
- Invio completo con locandina vera nel browser e posta/export con la locandina.

### Revisione di P1 (Opus, 26/11/2026): APPROVATO con una correzione già applicata
- **Corretto in revisione** (`src/Pubblicazioni/RiepilogoRichiesta.php`): con l'elenco delle pagine pieno `url_docente` contiene più indirizzi, uno per
  riga, ma il riepilogo ne faceva **un solo link** con dentro tutte le righe (link rotto, indirizzi illeggibili in scheda, email e ricevuta). Ora è un
  link per riga, come `altri_link_docente`. Prove rilanciate verdi: PaginaDocente 8/63, CampiAggiunti 4/63, FogliEsportabili, RiepilogoRisposte,
  IscrizioniUnit, SondaggioPratica, ServizioAvvisi, DocumentiEValutazioni, DaFareEdEmail.
- Controllati a mano e giusti: `indirizzoDibest` (`www.unical.it/dibest` no, `dibest.unical.it.evil.com` no, `didattica.dibest.unical.it` sì),
  `nomeLocandina` (accenti, apostrofi, taglio a 40, nessun `_` ai lati), la migrazione v146 (l'UPDATE di `allegati_obbligatori` sta sotto la colonna
  nuova, quindi gira una volta sola), il `required` della locandina solo nel form (non dal pannello, `$aMano`).
- Accettato con riserva: un host come `dibest-fake.unical.it` passa il controllo. I sottodomini `unical.it` li assegna l'Ateneo, quindi va bene.
- **Deciso dall'utente (26/11)**: anche la riga a mano dal pannello ha la **locandina obbligatoria**. Lo fa Sonnet come punto 0 di P2 (messaggio P2).

## P2 — Schema e servizio dell'approvazione: FATTO in locale (26/11/2026)

### Resto di P1 (punto 0): locandina obbligatoria anche nella riga a mano
- `RegolePubblicazione::valida`: tolto `&& !$aMano` alla locandina mancante. Gli altri campi della riga a mano restano facoltativi.
- `admin/pubblicazioni.php` (azione «nuova»): campo «Locandina» (un file PDF/JPG/PNG, `required`) prima di «Altri allegati» (era «Allegati da pubblicare»); `$_FILES['locandina']` passato a `creaManuale`.
- Prove: `FileDiProva::pdf()` aggiunto alle chiamate a `creaManuale` che devono riuscire (Servizio, Pannello, NotificheEScarico, ImpostazioniRichiesta) + `testRigaAManoSenzaLocandinaEUnErrore`. **Fuori elenco ma necessario**: `tests/Unit/Pubblicazioni/RegolePubblicazioneTest.php` aveva 6 casi con `$aMano = true` senza locandina (e un'asserzione «le righe a mano non la chiedono»): aggiornati, altrimenti restavano rosse.

### Schema v146 (stessa versione di P1)
- `pubblicazioni`: `decisa_da INT NULL` (utenti.id), `decisa_il DATETIME NULL`, `respinta_motivo VARCHAR(1000) NOT NULL DEFAULT ''`.
  **Scelta: solo `decisa_*`, niente `approvata_*`** (scritto nel commento dello schema). Chi ha approvato **o** respinto sta in `decisa_*`; quale dei due lo dice lo stato (`ricevuta` = approvata, `respinta`). Le righe di prima hanno `decisa_*` vuote = «approvata prima che ci fosse l'approvazione».
- Tabella `pubblicazioni_persone (id, ruolo, persona_id, nominativo, aggiunta_il, aggiunta_da, UNIQUE ruolo+persona_id)`: ruoli `approvatore | ufficio | conoscenza`. L'email non si copia.
- `impostazioni_sistema.pubblicazioni_approvazione_attiva TINYINT(1) DEFAULT 0` (spenta di partenza).
- Provata sul DB locale `eventi_locale` (marcatore v146 tolto e rigenerato dalla prima richiesta): colonne e tabella create, richieste esistenti intatte.

### Codice
- `Costanti`: `STATI` con `da_approvare` («In attesa di approvazione») per primo e `ricevuta` = «Approvata»; `RUOLI_PERSONE`; `MAX_MOTIVO = 1000`. Le righe vecchie non si toccano.
- **`PersonePubblicazioni`** (nuova, senza HTML): le tre liste, `elenco()` con l'email letta ogni volta da `personale_ateneo` e gli avvisi (non più in anagrafe, senza email, approvatore senza account collegato, persona dell'ufficio senza `modulo_pubblicazioni`), `aggiungi/togli`, `eApprovatore`, `approvatoriConAccount`. Il permesso vale per `utenti.persona_id` uguale a quello in lista (`collegata()`, regola pura): mai per un'email.
- **`ApprovazionePubblicazioni`** (nuova): `puoApprovare`, `daApprovare`, `approva`, `respingi` (motivo obbligatorio, max 1000), `decisione`. Approva/respingi = un solo `UPDATE ... WHERE id = ? AND stato = 'da_approvare'`; 0 righe → errore «già approvata/respinta da X il …». Registro in `log_attivita` («Richieste di pubblicazione: approvata/respinta»).
- `ServizioPubblicazioni`: `invia()` nasce `da_approvare` solo se approvazione accesa **e** almeno un approvatore con account (`statoPer`), altrimenti `ricevuta`; `inviaAlMonitoraggio()` rifiuta `da_approvare`; `puoVedere()` apre richiesta e file anche agli approvatori; le righe a mano nascono sempre approvate (anche se il form manda `da_approvare`).
- `ImpostazioniPubblicazioni`: `approvazione_attiva` (sezione `approvazione`, fuori dal «Salva» principale: `sezioniPrincipali()`); `aggiorna()` rifiuta di accenderla senza un approvatore con account.
- `admin/amministrazione_impostazioni.php`: parte «Chi approva, chi lavora, chi è informato» con l'interruttore e le tre liste (ricerca nell'anagrafe esistente, «Togli», avvisi in rosso). Ogni aggiunta/tolta e ogni cambio dell'interruttore finisce nel registro «Richiesta di pubblicazione: impostazioni modificate».
- **Aggiunta non chiesta, per non lasciare un buco**: dalla scheda (`aggiornaOperatori`) non si può portare una richiesta dentro o fuori da `da_approvare` (l'operatore non può approvare «di nascosto» cambiando lo stato); nel menu stato della scheda «In attesa di approvazione» compare solo se la richiesta lo è già, e non si offre per le righe a mano. Pannello: tono `info` per il nuovo stato.

### Prove (un file per volta, PHPUnit 9.6, MariaDB accesa): tutte verdi
Nuove: `Unit/Pubblicazioni/ApprovazioneRegoleTest` **5/23** (puoApprovare con account collegato sì / non collegato no / persona_id vuoto / solo email no; «spenta o senza approvatori = nasce ricevuta»; motivo obbligatorio e max 1000; stati nuovi) · `Integration/Pubblicazioni/ApprovazionePubblicazioniTest` **5/47** (due approvano la stessa richiesta: il secondo ha l'errore e nel DB resta il primo, una sola voce nel registro; respingi con motivo; chi non ha account non approva; l'interruttore non si accende senza approvatore con account; in attesa non va nel file Excel né si approva dalla scheda).
Toccate e rilanciate. Unit: CampiNuoviOrdine 13/56 · CompilazioneInTabella 4/20 · ConfigurazioneCampi 16/143 · EsportazioneMonitoraggio 5/50 · ImpostazioniPubblicazioni 10/112 · MessaggioDimensioneAllegato 4/7 · NomeLocandina 3/10 · PaginaDocente 8/63 · PannelloPubblicazioni 8/47 · RegolePubblicazione 19/105. Integration: CampiAggiunti 4/63 · CompilazioneInBlocco 4/45 · ConfigurazioneCampi 12/84 · ImpostazioniRichiesta 8/251 · NotificheEScarico 9/73 · PannelloPubblicazioni 6/103 · ServizioPubblicazioni 16/154. Portale: GuidePubblicazioni 4/51.
Prove vecchie aggiornate per l'etichetta «Ricevuta» → «Approvata» (Servizio, Pannello integration e unit).

### Verifiche a occhio e via HTTP (portale locale, anagrafe vera di 255 persone)
Ricerca → «Aggiungi» → persona nella lista; doppione, persona inventata e lista inventata rifiutati con messaggio; l'interruttore rifiuta di accendersi senza approvatore con account; elenco, scheda e «nuova riga» del pannello si aprono con una richiesta `da_approvare` senza errori PHP. Schermata: `documentazione/schermate_pubblicazioni_P2/P2_chi_approva_chi_lavora_chi_e_informato.jpg` (le tre liste e gli avvisi in rosso: approvatore senza account, ufficio senza abilitazione, persona senza email, persona uscita; le persone «zz.prova» sono state tolte). Dati di prova cancellati dal DB locale.

### Cosa NON ho provato / da sapere
- **Nessuna email né pagina di chi approva** (P3 e P4). Con l'approvazione accesa le richieste restano in attesa **senza avvisi**; di partenza è spenta.
- Con l'approvazione accesa le richieste `da_approvare` compaiono ancora tra le «Richieste arrivate» del pannello e nei conteggi: le sposta in sola lettura P4.
- Il caso «approvatore con account collegato» è provato solo nei test (DB di prova): in locale nessun utente ha `persona_id` (il collegamento lo fa il login SSO), quindi non ho visto a schermo l'interruttore acceso.
- Non lanciati (regola del passo): `esegui.php`, `strumenti/prove/oop/pagine_amministrazione.php` (inserisce righe `ricevuta`, potrebbe cercare il testo «Ricevuta»), suite intera, `GuidePerRuolo` (non aggiornata: guide a P7).
- Il badge «In attesa di approvazione» (tono `info`) non l'ho guardato a schermo: la pagina con quella richiesta si apre senza errori, ma ho controllato solo il testo.

### Revisione di P2 (Opus, 26/11/2026): APPROVATO con una correzione già applicata
- **Corretto in revisione** (`PersonePubblicazioni::togli` + `restaSenzaApprovatori`): si poteva togliere **l'ultimo approvatore con account** mentre
  c'erano richieste `da_approvare`, e quelle restavano in attesa di nessuno per sempre (nessuno poteva più deciderle, e dalla scheda lo stato non si
  cambia). Ora «Togli» lo rifiuta dicendo quante richieste sono in attesa; un approvatore senza account si toglie sempre. Caso aggiunto in
  `ApprovazionePubblicazioniTest` (ora 5/52, era 5/47). Rilanciati verdi anche ImpostazioniRichiesta 8/251, ImpostazioniPubblicazioni 10/112,
  PannelloPubblicazioni 6/103, NotificheEScarico 9/73, ServizioPubblicazioni 16/154, RegolePubblicazione 19/105, ApprovazioneRegole 5/23.
- Controllate e giuste: la gara (un solo UPDATE con `WHERE stato = 'da_approvare'`, `affected_rows` 0 = «già decisa da X»); il permesso solo da
  `utenti.persona_id` (mai dall'email); `statoPer` (spenta o senza approvatori con account = nasce approvata, così niente si blocca);
  `inviaAlMonitoraggio` che rifiuta le richieste in attesa; la scelta di avere solo `decisa_da`/`decisa_il`.
- **Aggiunta di Sonnet accettata**: dalla scheda non si entra né si esce da «In attesa di approvazione» (altrimenti un operatore aggirava l'approvazione).
- Restano a P4, come da piano: le richieste in attesa si vedono ancora tra le «Richieste arrivate» e nei conteggi del pannello.

## P3 — pagina di chi approva: FATTO in locale (26/11/2026), da rivedere

**Cosa c'è:**
- Pagina dal nucleo: `approva_pubblicazioni.php` (guscio), riga in `Rotte.php`, `src/Portale/Pagine/PaginaApprovaPubblicazioni.php`, vista `viste/pubblico/approva_pubblicazioni.php`, stili `.ap-*` in `assets/css/portale-ui.css`.
- `ApprovazionePubblicazioni`: `contaDaApprovare()`, `decise()`, `giaDecisa()` pubblico. `PersonePubblicazioni::destinatari()`.
- Email: tre modelli nuovi in `CatalogoPubblicazioni` (`pubblicazioni_docente_attesa`, `pubblicazioni_da_approvare`, `pubblicazioni_decisa_altro`); `NotifichePubblicazioni` riscritta (`richiestaInviata` con approvazione attiva → solo approvatori + ricevuta «in attesa»; `richiestaDecisa` → solo gli ALTRI approvatori; un errore di invio solo in `error_log`). Le email a richiedente, ufficio e conoscenza dopo la decisione sono P4.
- «Il mio lavoro»: permesso `pubblicazioni_approva` e numero `pubblicazioni_da_approvare` in `PostazioniLavoro`, `_permessi_lavoro.php`, `lavoro.php`.
- Tessera nell'area personale: riquadro `approvazioni_pubblicazioni` in `CatalogoRiquadri` + `inc/area/approvazioni_pubblicazioni.php` (la v146 non è stata alzata: lavoro non committato, la semina lo aggiunge da sola).
- `documentazione/inventario_accessi.md` rigenerato (una riga per la pagina nuova).
- `strumenti/prove/oop/area_personale.php`: l'amministratore ha ora **10** postazioni (era 9), per la tessera nuova.

**Prove (PHPUnit 9.6, MariaDB accesa), tutte verdi:** `PaginaApprovaPubblicazioniTest` 3/19 · `ApprovazionePubblicazioniTest` 5/52 · `NotificheEScaricoTest` 9/73 · `ModelliEmailTest` 12/1083 · `NessunaEmailScrittaNelCodiceTest` 2/2501 · `RotteTest` 10/72 · `VistaTest` 23/323 · `PermessiPagineTest` 15/374 · `PostazioniLavoroTest` 6/26 · `tests/Unit/Portale` e `tests/Integration/Pubblicazioni`. `php strumenti/avanzamento_nucleo.php --controlla`: «La tabella delle rotte e i gusci coincidono».

**Verifiche nel portale locale** (utente «Prova Docente/Dipendente» collegato per poco a una persona della lista «Chi approva», interruttore acceso, tre richieste di prova 9001-9003): elenco con due da approvare e una respinta con motivo; scheda da telefono con «Respingi» aperto; clic vero su «Approva» → finestra di conferma → «Richiesta approvata.» e riquadro verde «già approvata da … il …»; nessun errore nel registro del server. Nessuna email intercettata: giusto, perché l'unico approvatore era chi ha deciso. Dati di prova tolti, interruttore rispento, `persona_id` rimesso a NULL.
Schermate: `documentazione/schermate_pubblicazioni_P3/P3_elenco_tema_scuro.jpg` (1100 px) e `P3_respingi_telefono_375.jpg`.

**Cosa NON ho provato:**
- `strumenti/prove/esegui.php` (contiene `area_personale.php` con il 9 → 10): non si lancia da solo, va con la suite intera a P7.
- Le email vere (testo e destinatari) nel browser: provate solo nei test; con un solo approvatore non ne parte nessuna. Le copre P7.
- La miniatura della locandina nell'elenco: le richieste di prova non avevano file, si è vista solo l'icona al posto della miniatura.
- Piccola nota di forma: subito dopo aver deciso, la scheda dice «già approvata da [te stesso] … Non devi fare niente» sotto il messaggio verde «Richiesta approvata.». Non è un errore, ma si potrebbe dire «L'hai approvata tu il …» quando chi guarda è chi ha deciso. **(Fatto in P4.)**

Non committato.

### Revisione di P3 (Opus, 27/11/2026, fatta dopo P6b): APPROVATO senza correzioni
- Controllati e giusti: accesso deciso dalla pagina prima di tutto (non collegato → login SSO con ritorno; collegato e non approvatore → 403);
  `SincronizzaAccesso` chiamato per primo, quindi un approvatore della lista senza account collegato lo collega entrando con lo SSO dal link dell'email;
  POST con il CSRF del Nucleo; «Entra come…» non può decidere (la guardia di `config.php` blocca ogni POST); conferma con `conferma.js` sul `<form>`
  (rifà l'invio col pulsante premuto, quindi `approva`/`respingi` arrivano), testo della domanda messo con `textContent` (il titolo non può iniettare
  HTML); tutti i valori della vista escapati, il riepilogo grezzo viene da `VistaRichiesta` già escapato; la miniatura con `data-nascondi-errore`;
  motivo mancante → avviso ambra e campo riaperto (`&respingi=1`), «già decisa da X» → avviso ambra e scheda con chi e quando; le email partono solo
  dopo una decisione riuscita e un errore resta nel log.
- Email all'invio (`richiestaInviata`): con la richiesta `da_approvare` solo agli approvatori (una per indirizzo) più la ricevuta «in attesa»
  a chi ha inviato, se non è già tra gli approvatori; altrimenti come prima più la lista «Chi lavora» (revisione P4).
- Rilanciati verdi (MariaDB accesa): PaginaApprovaPubblicazioni 3/20 · ApprovazionePubblicazioni 5/55 · DestinatariDecisione 6/8 ·
  PostazioniLavoro 6/26; `avanzamento_nucleo.php --controlla`: rotte e gusci coincidono.
- **Deciso dall'utente (27/11)**: chi è nella lista «Chi approva» può approvare anche una richiesta inviata da sé stesso. Resta com'è, nessun controllo.

## P4 — Dopo la decisione: ufficio, conoscenza, richiedente: FATTO in locale (26/11/2026), da rivedere

**Ritocco di P3.** Chi ha deciso legge «L'hai approvata tu il …» / «L'hai respinta tu il …» (con il motivo); per gli altri approvatori il riquadro è quello di prima.

**Email dopo la decisione** (`NotifichePubblicazioni::richiestaDecisa`; chi riceve cosa lo decide `destinatariDecisione`, senza database):

| Destinatario | Approvata | Respinta |
|---|---|---|
| lista «Chi lavora» + indirizzi delle impostazioni | `pubblicazioni_comunicazione_nuova`, link alla scheda | niente |
| chi ha inviato | `pubblicazioni_approvata` (nuova) | `pubblicazioni_respinta` (nuova, con il motivo) |
| altri approvatori | `pubblicazioni_decisa_altro` (come in P3) | idem |
| lista «Chi è informato» | `pubblicazioni_conoscenza` (nuova): riepilogo, nessun pulsante né link a pannello o file | niente |

- Una persona riceve una email sola per evento, riconosciuta dall'indirizzo (maiuscole a parte) **o** dalla persona dell'anagrafe. Precedenza: chi lavora → casella → chi ha inviato → approvatore → conoscenza.
- Un errore di invio o di lettura resta in `error_log` e non tocca la decisione.

**Le mie richieste** (`inc/area/richieste_pubblicazione.php`): sotto una richiesta «Respinta» compare «Motivo: …».

**Pannello** (`admin/pubblicazioni.php`):
- «Richieste arrivate» senza quelle in attesa e senza quelle respinte da chi approva (`PubblicazioniRepository::approvata`: le riconosce dal motivo).
- Sopra, chiusa di partenza, «In attesa di approvazione (N)», in grigio, senza pulsanti né link; compare solo se N > 0.
- Nella scheda: «Approvata da X il …», «Respinta da X il … Motivo: …», oppure «Approvata: prima dell'approvazione.» (non per le righe a mano).
- `CoseDaFareAmministrazione` e «Il mio lavoro»: **nessuna modifica**, contano già solo gli stati `ricevuta` e `lavorazione` (a schermo: 2 da guardare con 2 richieste in attesa nel database).

**File:** `src/Pubblicazioni/NotifichePubblicazioni.php`, `src/Pubblicazioni/PubblicazioniRepository.php`, `src/Email/CatalogoPubblicazioni.php`, `admin/pubblicazioni.php`, `inc/area/richieste_pubblicazione.php`, `src/Portale/Pagine/PaginaApprovaPubblicazioni.php`, `viste/pubblico/approva_pubblicazioni.php`. Prove: `tests/Unit/Pubblicazioni/DestinatariDecisioneTest.php` (nuova), `PaginaApprovaPubblicazioniTest` (il caso «chi ha deciso» si rompeva), `VistaTest` (campo nuovo `decisaDaMe`).

**Scelte fatte (da confermare in revisione):**
1. L'avviso all'ufficio parte anche con l'approvazione spenta: la frase è un segnaposto intero, `{{approvazione_testo}}`, vuoto in quel caso. `{{approvata_da}}` e `{{approvata_il}}` ci sono per chi riscrive il testo dalla pagina «Email», ma il testo originale non li usa direttamente.
2. Chi ha inviato ed è anche in «Chi lavora» riceve solo l'avviso all'ufficio, non «è stata approvata».
3. Chi ha deciso, se è in «Chi lavora» o in «Chi è informato», riceve quella email (non l'avviso agli approvatori).
4. «Approvata» e «respinta» a chi ha inviato partono anche con la ricevuta all'invio spenta.
5. Con l'approvazione spenta le due liste nuove non ricevono niente (si fa come prima).

**Prove (PHPUnit 9.6, MariaDB accesa, un file per volta), tutte verdi:** `DestinatariDecisioneTest` 6/8 · `NotificheEScaricoTest` 9/73 · `ModelliEmailTest` 12/1101 · `NessunaEmailScrittaNelCodiceTest` 2/2560 · `PaginaApprovaPubblicazioniTest` 3/20 (stampa due volte «esito non inviato…»: la tabella utenti di prova non ha l'email) · `VistaTest` 23/326.

**Verifiche a occhio** (portale locale, cinque richieste di prova 9101-9105 poi cancellate): pannello con la sezione chiusa e aperta, chiaro e scuro; schede approvata, vecchia, respinta e in attesa senza avvisi PHP in pagina; «Le mie richieste» con una respinta, chiaro e scuro. Schermate in `documentazione/schermate_pubblicazioni_P4/`.

**Cosa NON ho provato / da sapere:**
- Le email vere non le ho spedite né guardate (niente prova di integrazione: le copre P7). Provata la sola regola dei destinatari.
- Nessun clic vero su «Approva» / «Respingi» in questo passo; «L'hai approvata tu» è provato solo nei test. Larghezza da telefono non guardata.
- Non lanciati (regola del passo): `esegui.php`, `strumenti/prove/oop/pagine_amministrazione.php`, suite intera.
- Se in produzione il testo di `pubblicazioni_comunicazione_nuova` è stato modificato dal pannello, la frase sull'approvazione non compare finché non si aggiunge il segnaposto.
- **Buco rimasto (da decidere):** una richiesta respinta da chi approva non è più in nessun elenco del pannello; scrivendo l'indirizzo della scheda, però, un operatore può rimetterla «Approvata» dal menu dello stato (e la riga direbbe «Approvata da» chi l'aveva respinta). Proposta: bloccare lo stato anche per queste, come per quelle in attesa.
- Allo stesso modo la scheda di una richiesta in attesa (solo da indirizzo scritto) ha ancora i pulsanti: «Invia al file Excel» e il cambio di stato sono rifiutati dal servizio, «Elimina» no.
- Nel pannello il riquadro dice ancora «ricevute (da guardare)» accanto a righe «Approvata» (testo di prima di P2): per P7.

Non committato.

### Revisione di P4 (Opus, 26/11/2026): APPROVATO con tre correzioni già applicate
- **Buco chiuso** (`ServizioPubblicazioni::pianoModifica`, vale anche per la compilazione in blocco): una richiesta respinta da chi approva
  (`PubblicazioniRepository::approvata` falso) non cambia più stato dalla scheda; prima un operatore poteva riportarla ad «Approvata» e la scheda
  avrebbe scritto «Approvata da» chi l'aveva respinta. Nella scheda il menu dello stato offre solo lo stato attuale, con la frase «lo decide chi approva».
  Una respinta dall'ufficio (senza motivo) resta modificabile come prima.
- **Elimina** (`ServizioPubblicazioni::elimina` + scheda): una richiesta in attesa di approvazione non si elimina (il riquadro non compare). Una respinta
  si può ancora eliminare (pulizia; la voce resta nel registro).
- **Approvazione spenta**: l'avviso all'invio va anche alle persone della lista «Chi lavora», oltre agli indirizzi delle impostazioni, una volta sola
  per indirizzo (prima con l'approvazione spenta la lista non riceveva niente).
- Casi aggiunti in `ApprovazionePubblicazioniTest` (ora 5/55): respinta da chi approva non si riapre; in attesa non si elimina.
- Rilanciati verdi: ApprovazionePubblicazioni 5/55 · DestinatariDecisione 6/8 · NotificheEScarico 9/73 · ServizioPubblicazioni 16/154 ·
  PannelloPubblicazioni (integration 6/103, unit 8/47) · CompilazioneInBlocco 4/45 · PaginaApprovaPubblicazioni 3/20 · ModelliEmail 12/1101 · Vista 23/326.
- Visto nel portale locale (amministratore): scheda di una respinta da chi approva (stato bloccato, «Respinta da … Motivo: …») e di una in attesa
  (stato bloccato, niente «Elimina»), nessun errore. Dati di prova tolti.
- Controllate e giuste: `destinatariDecisione` (precedenza, doppioni per email e per persona, chi ha deciso escluso solo dagli approvatori), l'email
  «per conoscenza» senza collegamento (il riepilogo ha solo i link alle pagine pubbliche, non ai file), il motivo escapato in «Le mie richieste».
- Accettate le altre scelte di Sonnet. Restano a P7: la frase «ricevute (da guardare)» del riquadro del pannello e, in produzione, il segnaposto
  `{{approvazione_testo}}` se l'avviso all'ufficio è stato modificato dal pannello.

## P5 — «Chi compila cosa»: ogni operatore vede solo le sue colonne: FATTO in locale (26/11/2026), da rivedere

**Regola** (`src/Pubblicazioni/ColonneDiChi.php`, nuova; quella senza database è `valuta`):
- amministratori e chi ha il modulo Amministrazione intero: tutte le colonne (D6);
- chi ha solo «Richieste di pubblicazione»: le colonne assegnate, più i dati di chi invia e lo stato (i dati di chi invia in sola lettura);
- nessuna casella salvata: tutti vedono e compilano tutto, come prima. Contano solo le assegnazioni a colonne degli operatori che esistono ancora
  (anche nascoste); le righe di colonne tolte o di persone non più operatrici si ignorano e spariscono al salvataggio dopo.

**Schema:** `pubblicazioni_colonne_utenti (chiave VARCHAR(40), utente_id INT, PRIMARY KEY (chiave, utente_id))` nella **v146** (versione non alzata:
la v146 non è sul server). In locale ho tolto il segnale in `cache/` per far ripartire la migrazione; sul server parte da sola con la v146.

**Sul server** (`ServizioPubblicazioni`): `aggiornaOperatori`, `aggiornaOperatoriInBlocco` e `creaManuale` rifiutano una colonna non assegnata
(«…: questa colonna non è assegnata a te.») e non salvano niente. Nel blocco il controllo viene prima di scartare le celle invariate.
Non toccati i blocchi della revisione di P4 (`pianoModifica`, `elimina`).

**Griglia** (Amministrazione → «Campi e colonne» → «Chi compila cosa», `admin/parziali/chi_compila_cosa.php`): colonne × operatori; «Proponi dai
nomi "a cura di"» aggiunge le caselle (evidenziate) senza salvare e senza toglierne; si salva a mano. Voce nel registro «Richiesta di
pubblicazione: chi compila cosa modificato», con «prima → dopo» per colonna. Avvisi per colonne senza nessuno e operatori senza colonne. Sul
telefono una scheda per colonna, nomi su due colonne.

**Pannello** (`admin/pubblicazioni.php`): scheda, «Da compilare» (grafica di oggi: la nuova è P6) e numero sulla linguetta usano solo le proprie
colonne; per chi è limitato sparisce «Solo le mie colonne». Chi vede tutto tiene il filtro: con assegnazioni salvate le «mie» sono quelle
assegnate, senza assegnazioni quelle riconosciute dal nome. Nel registro modifiche della scheda i valori delle colonne altrui non compaiono
(resta «Modificate anche colonne non assegnate a te»).

**Export (proposta):** per chi è limitato, **solo i dati di chi invia, lo stato e le proprie colonne**, non «niente». Perché: è quello che vede già
a schermo, quindi non esce un dato in più; l'elenco di lavoro in Excel che ha oggi gli resta; il file è dichiarato parziale (sottotitolo « · copia
parziale…»; nome `Tabella_di_monitoraggio_mie_colonne_<data>`, perché il CSV non ha sottotitolo) e le colonne uscite restano nel registro. Chi
vede tutto scarica il file completo, come prima.

**File:** `src/Pubblicazioni/ColonneDiChi.php` (nuovo), `ServizioPubblicazioni.php`, `CompilazioneInTabella.php` (`nomina`), `RegistroModifiche.php`,
`EsportazioneMonitoraggio.php`, `src/Core/Schema/Definizioni.php`, `Migrazioni.php` (solo il commento), `admin/amministrazione_campi.php`,
`admin/parziali/chi_compila_cosa.php` (nuovo), `admin/pubblicazioni.php`. Prove: `tests/Unit/Pubblicazioni/ColonneDiChiTest.php` (nuova),
`tests/Integration/Pubblicazioni/CompilazioneInBloccoTest.php` (due tabelle in più e il caso richiesto).

**Scelte fatte (da confermare in revisione):**
1. Rifiuto stretto: basta la presenza di una colonna non propria, anche con il valore invariato, per non salvare niente.
2. Il blocco vale anche in `creaManuale` (non chiesto: senza, la riga a mano lo aggirava).
3. Operatore limitato senza colonne: vede i dati di chi invia e lo stato (che dalla scheda può ancora cambiare); «Da compilare» gli dice che non ha
   colonne assegnate.
4. Una colonna nuova `y_<n>`, finché non è assegnata, la compila solo chi vede tutto.
5. La griglia è dentro «Campi e colonne» (la apre solo chi vede tutto) e comprende le colonne nascoste.

**Prove (PHPUnit 9.6, MariaDB accesa, un file per volta), tutte verdi:** unità `ColonneDiChiTest` 3/14 · `CompilazioneInTabellaTest` 4/20 ·
`EsportazioneMonitoraggioTest` 5/50 · `PannelloPubblicazioniTest` 8/47; integrazione `CompilazioneInBloccoTest` 5/65 ·
`ApprovazionePubblicazioniTest` 5/55 · `ServizioPubblicazioniTest` 16/154 · `PannelloPubblicazioniTest` 6/103 · `CampiAggiuntiTest` 4/63 ·
`ConfigurazioneCampiTest` 12/84 · `NotificheEScaricoTest` 9/73 · `ImpostazioniRichiestaTest` 8/251. In tutto 85 prove, 979 asserzioni.
`php -l` senza errori sui file PHP toccati. Dopo le prove è cambiato solo il CSS della griglia (telefono).

**Verifiche nel portale locale** (Chrome senza finestra, quattro operatori e due richieste di prova, poi cancellati con le loro voci del registro):
griglia vuota, proposta (18 caselle, niente salvato), salvata, telefono 375 px senza scorrimento di lato; nessuno `style=` nella griglia. Operatore
con la sola colonna F: «Da compilare» e scheda mostrano solo F; un invio manomesso con la colonna C è rifiutato; il CSV ha solo F, i dati di chi
invia e lo stato. Chi vede tutto con «Solo le mie colonne»: D, E, U. Schermate in `documentazione/schermate_pubblicazioni_P5/` (quella del
telefono è tagliata a 3500 px: arriva alla colonna P).

**Cosa NON ho provato / da sapere:**
- Non lanciati (regola del passo): `esegui.php`, `strumenti/prove/oop/pagine_amministrazione.php`, suite intera (P7).
- Excel e PDF scaricati da un operatore limitato (guardato solo il CSV); registro modifiche filtrato, rifiuto in `creaManuale` e salvataggio della
  griglia che fallisce: senza prova. Proponi e Salva provati solo nel browser.
- «Entra come…» non provato; «Da compilare» e scheda non guardate col tema scuro né da telefono.
- **Rimasti come prima (per la revisione o P7):** `GuidePerRuolo` dice ancora che tutti modificano tutto; i conteggi «N da completare» (tabella di
  monitoraggio, riquadro, scheda, «Il mio lavoro», «Cosa devo fare») contano tutte le colonne; i titoli «A cura di» dei gruppi sono testo libero e
  possono non coincidere con le assegnazioni.

Non committato.

### Revisione di P5 (Opus, 26/11/2026): APPROVATO senza correzioni
- Controllate e giuste: `ColonneDiChi::valuta` (senza assegnazioni o con tutto il modulo = tutto; altrimenti le assegnate + quelle di chi invia);
  il rifiuto sul server in `aggiornaOperatori`, `aggiornaOperatoriInBlocco` e `creaManuale` PRIMA di ogni altra regola (tutto o niente); i blocchi della
  revisione P4 intatti; la scheda e «Da compilare» mostrano solo `scrivibili`; la tabella di monitoraggio a schermo e la ricerca usano solo i dati di
  chi invia; il registro filtrato con `$col_viste`; la griglia salvabile solo con `puoTutto` (anche nei POST) e col codice di sicurezza; `daPost` scarta
  colonne e persone inventate; il salvataggio della griglia in una transazione.
- **Scelte di Sonnet confermate**: export parziale (sue colonne + quelle di chi invia + stato, file segnato «copia parziale» e nome `_mie_colonne_`);
  rifiuto stretto anche per una colonna invariata; blocco anche sulla riga a mano; l'operatore senza colonne cambia solo lo stato; le colonne non
  assegnate le compila solo chi vede tutto.
- Da sapere per la messa in uso: appena si salva la PRIMA casella della griglia, tutti gli operatori limitati vedono solo le colonne assegnate (chi non
  ne ha nessuna non compila più niente). Conviene riempire la griglia con «Proponi» e salvarla tutta insieme.
- Rilanciati verdi: ColonneDiChi 3/14 · CompilazioneInBlocco 5/65 · ApprovazionePubblicazioni 5/55 · ServizioPubblicazioni 16/154 ·
  PannelloPubblicazioni (integration 6/103, unit 8/47) · NotificheEScarico 9/73 · ImpostazioniRichiesta 8/251 · CompilazioneInTabella 4/20 ·
  EsportazioneMonitoraggio 5/50 · Rotte 10/72.
- Restano a P7 (come scritto da Sonnet): guide per ruolo, conteggi «N da completare» su tutte le colonne, titoli «A cura di» liberi.

## P6a — Schizzo di «Da compilare»: APPROVATO dall'utente (27/11/2026)

Schizzo `documentazione/schizzo_compila_pubblicazioni.html`, schermate in `documentazione/schermate_pubblicazioni_P6/` (da fare, errore, fatte,
nessuna colonna; chiaro, scuro e telefono). Due correzioni scritte nel messaggio P6b: «DA SALVARE» per un valore scritto e non salvato; etichetta di
stato sulla stessa riga del titolo del campo. Prossimo: P6b (la pagina vera).

## P6b — «Da compilare» a schede: FATTO in locale (27/11/2026), da rivedere

**Cosa fa ora** `admin/pubblicazioni.php?vista=da_compilare`:
- Due linguette, «Da fare (N)» e «Fatte (M)»; una card per richiesta (locandina, evento, chi l'ha chiesta, chi l'ha approvata, «N di M
  compilati»), le date più vicine per prime, 20 per pagina. Le colonne sono quelle di `ColonneDiChi`.
- Un «Salva» per card, tutto o niente, errori accanto al campo; niente salvataggio automatico. Partono solo i campi cambiati, così non si
  sovrascrive quello che nel frattempo ha scritto un altro.
- Etichetta di ogni campo: COMPILATO / DA COMPILARE / DA SALVARE / DA CORREGGERE, sulla riga del titolo; su telefono sotto il titolo,
  allineata uguale per tutti (le due correzioni allo schizzo).
- Finita l'ultima colonna la richiesta passa in «Fatte» (messaggio in cima); da lì «Correggi», e se un campo viene svuotato torna in «Da fare».
- Chi vede tutto ha in più «A schede / Tabella completa» (la tabella larga di prima, invariata) e «Solo le mie colonne». Chi vede solo le sue
  colonne ha solo le schede. Senza colonne assegnate: «Nessuna colonna è assegnata a te».

**File:** `admin/parziali/da_compilare_schede.php` (nuovo), `admin/pubblicazioni.php`, `assets/css/portale-ui.css` (blocco `ui-comp-*`),
`admin/amministrazione_home.php` (la frase sul riquadro), `strumenti/prove/oop/pagine_amministrazione.php` (la tabella ora è `&forma=tabella`).
Nessuno `style=` nuovo, lo script ha il nonce.

**Scelte da confermare:**
- Le schede sono la vista di partenza anche per chi vede tutto: un amministratore ha 17 campi per card (si accorciano con «Solo le mie colonne»,
  spento di partenza).
- Chi è limitato non ha più la tabella, nemmeno con `&forma=tabella`.
- Se in un'altra card ci sono modifiche non salvate, «Salva» chiede conferma (andrebbero perse); anche lasciando la pagina c'è l'avviso.
- «Approvata prima che ci fosse l'approvazione» compare anche per le righe nate con l'approvazione spenta, come nella scheda.

**Prove** (un file per volta, PHPUnit 9.6): CompilazioneInTabella unit 4/20, PannelloPubblicazioni unit 8/47 e integration 6/103,
CompilazioneInBlocco 5/65: tutte verdi, nessun file di prova PHPUnit cambiato. Nessuna prova nuova.

**Guardato nel browser** (portale locale, dati inventati poi tolti): chiaro, scuro e 375 px senza scorrimento orizzontale; etichette a 1280,
1024, 800, 768, 600 e 375; errore (non salva niente, torna sulla card), passaggio in «Fatte» e ritorno, salvataggio parziale, salva senza
modifiche, conferma per le altre card, schermata senza colonne, Tabella completa col suo «Salva». Chiesti al server: «Solo le mie colonne»,
seconda pagina oltre 20, valore vecchio in un campo SI/NO. Schermate: `documentazione/schermate_pubblicazioni_P6/P6_vera_*.jpg`.

**Cosa NON ho provato / da sapere:**
- Non lanciati (regola del passo): `esegui.php`, `strumenti/prove/oop/pagine_amministrazione.php` (modificato, va lanciato a P7), suite intera.
- «Entra come…» non provato.
- Tra 600 e 800 px circa il pannello scorre in orizzontale di qualche decina di punti per i pulsanti della testata: succede su tutte le pagine
  del pannello (anche `amministrazione.php`), non viene dalle schede, non toccato.
- Le guide per ruolo restano per P7.

Non committato.

### Revisione di P6b (Opus, 27/11/2026): APPROVATO senza correzioni
- Controllati e giusti: il «Salva» della scheda manda `scheda=<id>` e il server tiene solo quella richiesta (`array_intersect_key`), poi
  `aggiornaOperatoriInBlocco` con i controlli di P5 (colonne non proprie rifiutate, tutto o niente); dopo un errore i valori scritti tornano dal POST
  e quelli non inviati dal database; i campi invariati spenti nell'evento `submit` (anche dopo la conferma di `conferma.js`, che rifà il clic: l'evento
  riparte) e riaccesi subito dopo; l'avviso all'uscita spento durante l'invio; `dividi` (prima gli eventi da oggi, dal più vicino, poi i passati, dal
  più recente; senza colonne due elenchi vuoti); chi è limitato non ha la tabella nemmeno con `&forma=tabella`; nessuno `style=`, script col nonce.
- Le due correzioni allo schizzo ci sono (schermate `P6_vera_1_da_fare_chiaro.jpg`: «DA SALVARE» sul 45 ed etichetta sulla riga del titolo;
  `P6_vera_2_errore_telefono_chiaro.jpg`: etichette sotto il titolo, allineate).
- Rilanciati verdi: CompilazioneInTabella unit 4/20, PannelloPubblicazioni unit 8/47; `php -l` pulito sui quattro file PHP toccati.
- **Scelte di Sonnet accettate**: schede di partenza anche per chi vede tutto; nessuna tabella per chi è limitato; si mandano solo i campi cambiati;
  conferma se un'altra scheda ha modifiche non salvate.
- Da sapere (non difetti): senza JavaScript partono tutti i campi e il server scarta solo quelli uguali al database, quindi un valore scritto da un
  altro nel frattempo può essere sovrascritto (il pannello richiede comunque JavaScript); la miniatura carica l'immagine intera della locandina
  (al massimo 20 per pagina); per chi vede tutto senza colonne proprie una richiesta passa in «Fatte» solo con tutte le 17 colonne compilate.
- Restano a P7: `strumenti/prove/oop/pagine_amministrazione.php` (modificato, va lanciato), «Entra come…», guide per ruolo, conteggi.

Nota per P7: nello stato manca la sezione «Revisione di P3» (P4 è stato approvato sopra P3); la pagina di chi approva va riguardata in revisione
prima del commit.

## P7 — chiusura (27/11/2026, Sonnet). Non committato

**Fatto**

- Guide: richiedente e operatori aggiornate (ognuno modifica solo le sue colonne), nuova guida «per chi le approva». Mappe 13b, 13i, 13j
  rigenerate; `inventario_accessi.md` rigenerato; `didacticus_voci_pubblicazioni_20261127.csv` (4 voci: 2 si aggiornano, 2 nuove).
- Punti lasciati dalle revisioni: «approvate (da guardare)»; «N da completare» sulle sole colonne di chi è limitato (tabella, riquadro,
  scheda, «Il mio lavoro», «Cosa devo fare»); `{{approvazione_testo}}` nelle istruzioni per il server.
- Giro completo via HTTP: `strumenti/prove/oop/pagine_approvazione.php` (invia → da approvare → approva il secondo → «già approvata» per il
  primo → email → file Excel → «Chi compila cosa», colonna non sua rifiutata). Dentro c'è anche «Entra come…» come operatore limitato e
  come approvatore: vede quello che deve, non salva, non approva, non parte nessuna email.
- `commit_approvazione_pubblicazioni.txt` (messaggio, i due comandi, 81 file; l'elenco per `git add` è in `..._file.txt`) e
  `istruzioni_aggiornamento_approvazione.txt`.

**Difetti trovati in P7 e corretti**

1. Dopo l'invio, con l'approvazione accesa, la conferma diceva ancora che la richiesta era arrivata all'ufficio comunicazione.
2. `admin/parziali/chi_compila_cosa.php` e `da_compilare_schede.php` non avevano la regola in `PermessiPagine` (e il secondo aveva una
   guardia diversa dalle altre): `PermessiPagineTest` sarebbe stata rossa.
3. La tessera «Richieste di pubblicazione da approvare» aveva lo stesso numero d'ordine (14) di un'altra: ora 11.
4. `pagine_amministrazione.php`: 19 rosse perché le prove erano rimaste indietro (locandina obbligatoria, `?v=6`, campo «Note operative»).
   Aggiornate le prove, non il codice. La prova «passo senza campi» ora svuota il passo 6: la locandina non si può nascondere.

**Suite**

- PHPUnit: Integration 1203 prove (15542 asserzioni), Unit 764 (7710): tutte verdi, 2 saltate.
- `esegui.php`: 3385 superate, 1 rossa (il difetto 3). Corretto mentre la suite girava; la condizione è stata riverificata da sola e
  passa. **Il modulo `area_personale` e la suite non sono stati rilanciati** (richiesta dell'utente: niente giri non necessari).
- `avanzamento_nucleo.php --controlla`: rotte e gusci coincidono.
- **`apache_nucleo.sh` non lanciato** (stesso motivo): `.htaccess` non è cambiato e la pagina nuova è un file vero con il suo guscio.

**Non verificato**: la conferma dopo l'invio e le mappe non sono state guardate a occhio nel browser (la conferma è controllata via HTTP);
il CSV di Didacticus è stato letto con lo stesso analizzatore dell'importazione ma non importato dal pannello; le istruzioni per il server
non sono state provate sul server. Le schermate `schermate_pubblicazioni_P*` e `schizzo_compila_pubblicazioni.html` restano fuori dal
commit, come nei commit precedenti.

### Revisione di P7 (Opus, 27/11/2026): APPROVATO senza correzioni
- **Commit**: l'elenco di `commit_approvazione_pubblicazioni_file.txt` (81 file) confrontato con `git status`: ci sono tutti i file cambiati o nuovi
  del lavoro e nessun file in più; restano fuori solo `.gitignore` (righe per `__pycache__`, innocue), `documentazione/mappa/.htaccess`, le cartelle
  `schermate_*`, `prova_titulus/`, gli schizzi, `PASSAGGIO_GATEWAY.txt`, `RIPRENDI_DA_QUI.md` e `strumenti/locale/*.ps1`. Il piano
  `piano_approvazione_pubblicazioni.md` è escluso da `.gitignore` (`/documentazione/piano_*.md`), come gli altri piani. Il messaggio non ha nessuna
  attribuzione a Claude; le righe con `#` le toglie `--cleanup=strip`.
- Controllati e giusti: i conteggi «N da completare» per chi è limitato (`Completamento::mancanti` e `contaDaFare` con `$sole`, i nomi di colonna
  passano da `array_intersect` con quelli della configurazione, quindi nella query entrano solo nomi veri; operatore senza colonne = 0); le
  chiamate in `admin_header.php`, `inizio.php` e `inc/area/lavoro.php` con l'utente; la conferma dopo l'invio («in attesa di approvazione») con il
  testo personalizzato dell'amministratore che resta; le due parti nuove in `PermessiPagine`; l'ordine 11 della tessera (nessun doppione).
- Le 19 prove di `pagine_amministrazione.php` cambiate seguono le regole nuove senza indebolirle: invio vero con la locandina (multipart), il passo
  che resta senza campi ora è il 6 (la locandina non si nasconde), `?v=6`, campi nuovi delle impostazioni; i file di prova caricati si cancellano.
- **Lanciato in revisione**: `bash strumenti/prove/apache_nucleo.sh` → «tutto in ordine» (Sonnet non l'aveva lanciato).
- Accettato: dopo la correzione della tessera non è stata rilanciata tutta `esegui.php`, solo la condizione rossa (passa).
- Istruzioni per il server lette: ordine giusto (copia di prova, poi sito vero; approvazione accesa per ultima; griglia salvata tutta insieme;
  segnaposto `{{approvazione_testo}}`; ritorno indietro solo senza richieste in attesa).
