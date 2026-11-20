# Modulo «Amministrazione» con la Modulistica e le Richieste di pubblicazione — piano e comandi per Sonnet

19/11/2026. Piano di Opus. Metodo di sempre: Sonnet fa **un passo per sessione nuova**, senza commit; il resoconto di Sonnet
si incolla nella chat di revisione di Opus; **un solo commit alla fine** (messaggio unico in `documentazione/commit_amministrazione.txt`,
che ogni passo aggiorna).

Punto di partenza: le Richieste di pubblicazione sono già committate (c3f9fd9) e installate. Oggi sono un **modulo a sé** del pannello
(`MODULI_PORTALE['pubblicazioni']`, pagina `admin/pubblicazioni.php`), riservate ai **docenti**, con campi e colonne **fissi nel codice**
(`RegolePubblicazione`, `PassiRichiesta`, `ColonneMonitoraggio`, `Costanti`).

---

## 0. Cosa vuoi e come lo traduco

| Cosa hai chiesto | Come si fa |
|---|---|
| Un macromodulo **Amministrazione** come la Didattica | Nuovo modulo del pannello `amministrazione` (card, colore, menu a sinistra, «Cosa devo fare»), pagina `admin/amministrazione.php` a schede come `didattica.php`. Il modulo «Richieste di pubblicazione» **sparisce come card** e diventa una sezione dell'Amministrazione. |
| Dentro, la **Modulistica** | Scheda «Modulistica» dell'Amministrazione (documenti da scaricare, link, procedure online) e pagina pubblica **«Modulistica amministrativa»**. La Richiesta di pubblicazione è la prima **procedura online** di questa modulistica. |
| **Più impostazioni**, gestirlo **in autonomia anche con i campi** | Scheda «Impostazioni» della richiesta: chi può inviarla, aperta/chiusa, testi, indirizzi, limiti; **campi del form** modificabili (etichetta, aiuto, obbligatorio, visibile, limite) più **campi nuovi** creati da te; **colonne del file Excel** modificabili (intestazione, «a cura di», colore, tipo, ordine, nascosta) più **colonne nuove**. |
| Aperta anche al **personale tecnico amministrativo** | «Chi può inviare» diventa un'impostazione con le caselle dei profili (`Pubblico`), di partenza **Docenti + PTA**. Tutti i testi «solo docenti» diventano «docenti e personale» (o letti dall'impostazione). |

### Scelte (proposte da Opus, approvate dall'utente il 19/11/2026)

1. **I link non cambiano.** `admin/pubblicazioni.php`, `richiesta_pubblicazione.php` e `allegato_pubblicazione.php` restano dove sono: le email già
   partite hanno il link alla scheda e devono continuare a funzionare. Cambia solo il modulo a cui appartengono (menu, testata, card).
2. **Permessi.** Nasce l'abilitazione «Modulo Amministrazione» (tutto il modulo). Quella di oggi «Richieste di pubblicazione» **resta** come
   abilitazione parziale dentro l'Amministrazione (come Tutorato o Assistenza dentro la Didattica): Floriana, Silvana, Gianni e Marco non perdono niente
   e non vedono il resto dell'Amministrazione.
3. **Modulistica amministrativa, per ora: documenti, link e procedure online «speciali»** (la Richiesta di pubblicazione). I **moduli online che aprono
   una pratica** (missioni, rimborsi…) restano per dopo: servono la colonna `ambito` sulle pratiche, un ufficio amministrativo e l'iter (passi A1–A4 del
   piano del 17/11), e prima i materiali con la segreteria amministrativa (A0). Il modulo però nasce già pronto ad accoglierli.
   Per i documenti si riusa la tabella `moduli` della Didattica con una colonna `ambito` (`didattica` | `amministrazione`): la Didattica legge solo i suoi
   (nessun cambiamento visibile), l'Amministrazione solo i suoi, e nell'Amministrazione il tipo «online» (pratica) per ora non si può scegliere.
4. **Campi fissi e campi tuoi.** I campi che finiscono nelle colonne dell'Excel (data, titolo, sottotitolo, descrizione, modalità, destinatari, link…)
   restano colonne vere della tabella, ma ne puoi cambiare etichetta, aiuto, obbligatorietà, visibilità e limiti. **Restano sempre obbligatori e
   visibili**: Titolo e Data di svolgimento (senza, la riga del monitoraggio non ha senso). I campi nuovi li costruisci con lo **stesso costruttore di
   MyForms** (testo, testo lungo, scelta, caselle, data, numero, sì/no, link), scegli in quale passo vanno e se diventano **una colonna dell'Excel**.
5. **Colonne dell'Excel.** Puoi rinominarle e riordinarle, ma c'è il pulsante **«Torna alle intestazioni originali»** (quelle di `DiBEST_Tabella di
   monitoraggio.xlsx`). Una colonna con dei valori **non si cancella**: si nasconde (i valori restano e tornano se la riaccendi).
6. **Stati** (Ricevuta, In lavorazione, Pubblicata, Respinta) restano fissi: li usa la logica (invio al file Excel, area personale).
7. **PTA.** Il PTA usa la stessa procedura a sette passi e la stessa tessera nell'area personale; la colonna B dell'Excel resta «Persona che ha richiesto».
   La casella «Destinatari: Personale tecnico amministrativo» non c'entra e non cambia.

---

## 1. Ordine dei passi

| Passo | Cosa | Visibile? |
|---|---|---|
| AM1 | Modulo Amministrazione nel pannello, Richieste di pubblicazione spostate dentro, permessi | sì (pannello) |
| AM2 | Modulistica amministrativa: scheda del pannello + pagina pubblica, colonna `ambito` su `moduli` | sì |
| AM3 | Impostazioni della richiesta: chi può inviare (Docenti + PTA), aperta/chiusa, testi, limiti | sì |
| AM4 | Campi del form e colonne dell'Excel modificabili (quelli che ci sono già) | sì |
| AM5 | Campi nuovi e colonne nuove (costruttore di MyForms) | sì |
| AM6 | Rifiniture: guide, mappe, inventario accessi, Didacticus, prove, messaggio di commit finale | no |

Ogni passo in una **sessione Sonnet nuova**, dopo che Opus ha approvato il precedente. **Nessun commit in mezzo**: il commit lo fai tu una volta sola dopo AM6.
Siccome non si committa tra un passo e l'altro, ogni blocco dice a Sonnet di **non** rifare né annullare quello che trova già modificato in locale.

---

## 2. Regole del progetto (vanno in testa a ogni blocco: sono già dentro i blocchi qui sotto)

```
Regole del progetto:
- Nessun ALTER/CREATE nelle pagine: le tabelle si cambiano solo in src/Core/Schema/Definizioni.php alzando Migrazioni::VERSIONE (prossima versione dopo l'ultima presente). Le migrazioni devono riempire i valori iniziali (nessun cambiamento per chi c'è già).
- Codice nuovo in src/Amministrazione/ o src/Pubblicazioni/ (classi con declare(strict_types=1), il contenitore le collega da solo); funzioni-facciata in inc/ solo se servono alle pagine. Non ridefinire funzioni già presenti.
- File PHP senza BOM UTF-8. Escape di tutto l'output. Ogni POST con csrf_verify e permesso ricontrollato sul server (anche GET e scaricamenti). Flash con flash_set (senza escape dentro).
- Riusa ciò che c'è: App\Auth\Pubblico e html_scelta_pubblico() per i profili, lo stile del pannello (pn_compiti, pn_stato, pn_sezioni come in admin/didattica_home.php), ModuloRepository della Didattica, il costruttore di MyForms (src/Forms/CostruttoreCampi.php, admin/parziali/costruttore_campi.php), Testi::pulisciHtmlSemplice, TabellaEsportabile, audit_log. La testata del sito non si tocca. La Didattica non deve cambiare in niente di visibile.
- I link di oggi restano validi: admin/pubblicazioni.php (?id=N, ?vista=…), richiesta_pubblicazione.php, allegato_pubblicazione.php.
- Testi in italiano semplice, niente gergo tecnico.
- Test: PHPUnit 9.6 in %TEMP%\phpunit96, percorsi assoluti (--bootstrap "%TEMP%\phpunit96\bootstrap.php" --no-configuration <cartella>), una cartella alla volta; per i test col database avvia mysqld di XAMPP con --standalone e fermalo alla fine. Fallimenti GIA' presenti (ZipArchive, xlsx, ConvenzioniTesi, Portale widget home, Risorse testCostantiDelleRisorse, Tutorato, Integration DaFareEdEmailTest «veicolo»): confronta prima/dopo e dimmi solo le differenze. Prove di pagina: php -d extension=zip -d extension=gd -d extension=intl strumenti/prove/esegui.php (PROVE_SOLO=… per limitare).
- C:\xampp\php\php.exe -l su ogni file toccato.
- MAI git stash (cartella su Google Drive). NON fare commit, push o altri comandi git che modificano qualcosa. In locale trovi già le modifiche dei passi AM precedenti (non committate): non rifarle e non annullarle.
- Messaggio di commit UNICO in documentazione/commit_amministrazione.txt: se non c'è crealo, se c'è AGGIUNGI il paragrafo del tuo passo e aggiorna l'elenco dei file in fondo (elenco esatto e completo dei file da aggiungere per tutti i passi fatti finora, escluso il file del messaggio; controllalo con git status). Senza Co-Authored-By né riferimenti a Claude.
- Se una scelta non è scritta qui o in documentazione/amministrazione_sonnet.md, fermati e chiedimi.

Alla fine: resoconto breve (file, cosa fa, scelte tue, test prima/dopo, dubbi).
```

---

## 3. Passo AM1 — Il modulo Amministrazione nel pannello (sessione Sonnet nuova)

```
Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. Leggi prima documentazione/amministrazione_sonnet.md (sezione 0: scelte) e guarda come è fatto il modulo Didattica: inc/sezioni.php (MODULI_PORTALE, PAGINE_MODULO), admin/admin_header.php (permessi $puo_*, moduli_utente, rimandi da inizio.php, menu a sinistra per modulo), admin/didattica.php e admin/didattica_home.php. Poi le Richieste di pubblicazione: admin/pubblicazioni.php, src/Pubblicazioni/ServizioPubblicazioni.php (puoGestire), inc/base.php ('modulo_pubblicazioni'), src/Auth/Abilitazioni/Perimetri.php. Fai SOLO il passo AM1.

[incolla qui le «Regole del progetto» della sezione 2]

PASSO AM1 — Modulo «Amministrazione» nel pannello, con dentro le Richieste di pubblicazione.
1. Modulo: MODULI_PORTALE['amministrazione'] (nome «Amministrazione», icona fa-building-columns, un colore che non c'è già, descrizione: «Modulistica amministrativa e procedure online del personale: richieste di pubblicazione su sito e social, documenti e moduli»). Togli la voce MODULI_PORTALE['pubblicazioni'] (niente più card a sé). In PAGINE_MODULO pubblicazioni.php passa a 'amministrazione' e aggiungi amministrazione.php.
2. Permessi:
   - nuova abilitazione 'modulo_amministrazione' («Modulo Amministrazione», tutto il modulo) accanto alle altre in inc/base.php e nella scelta dei «Moduli interi» di Utenti (Perimetri.php: aggiungi 'amministrazione' alla lista ammessa);
   - 'modulo_pubblicazioni' resta e si mostra in Utenti come «Amministrazione · Richieste di pubblicazione» (abilitazione parziale);
   - in src/Amministrazione/PermessiAmministrazione.php: puoTutto(utente) = amministratore o 'modulo_amministrazione'; puoPubblicazioni(utente) = puoTutto o 'modulo_pubblicazioni'; puoEntrare(utente) = uno dei due. ServizioPubblicazioni::puoGestire deve dare lo stesso risultato di puoPubblicazioni (chiamalo da lì, una regola sola).
   - admin_header.php: $puo_amministrazione (tutto) e $puo_pubblicazioni; moduli_utente['amministrazione'] se uno dei due; pagina di partenza per chi ha solo questo; inizio.php?sezione=amministrazione e il vecchio ?sezione=pubblicazioni portano ad amministrazione.php.
3. admin/amministrazione.php a schede come didattica.php (costante SCHEDE_AMMINISTRAZIONE, file admin/amministrazione_<scheda>.php, define('AMMINISTRAZIONE_PANNELLO')):
   - 'home' = «Cosa devo fare nell'Amministrazione» con pn_compiti: richieste di pubblicazione arrivate da guardare (stato ricevuta, non nel file Excel), in lavorazione, nel file Excel con colonne ancora vuote (se Completamento lo sa già dire, riusalo); pulsanti che aprono pubblicazioni.php sulla vista giusta. Sotto «Tutte le sezioni» (pn_sezioni): Richieste di pubblicazione, e i posti per Modulistica e Impostazioni che arrivano con AM2-AM3 (per ora NON mostrarli).
   - chi ha solo 'modulo_pubblicazioni' vede la home con le sole voci delle pubblicazioni.
4. Menu a sinistra del modulo: «Cosa devo fare» (amministrazione.php) e «Richieste di pubblicazione» (pubblicazioni.php, con le sue viste). pubblicazioni.php mostra la testata «MODULO · Amministrazione» e il percorso Amministrazione › Richieste di pubblicazione. Nessun altro cambiamento a pubblicazioni.php.
5. Cerca in tutto il codice (grep) gli usi di MODULI_PORTALE['pubblicazioni'], 'sezione=pubblicazioni', $moduli_utente['pubblicazioni'], la card del modulo nella pagina iniziale del pannello e nel selettore dei moduli in alto, strumenti/prove/esegui.php: sistemali tutti. Elencami nel resoconto quelli che hai trovato.
6. Test: Unit su PermessiAmministrazione (admin, solo pubblicazioni, solo amministrazione, nessuno); Integration: un utente con il solo 'modulo_pubblicazioni' entra in pubblicazioni.php e nella home dell'Amministrazione ma non nelle schede che verranno (prova con una scheda finta o con la regola). Prove di pagina: amministrazione.php e pubblicazioni.php si aprono per l'admin e per l'abilitato parziale, rimandano per chi non ha permesso; i vecchi link pubblicazioni.php?id=N funzionano.
```

### AM1b — aggiunta chiesta dall'utente il 19/11 (stessa sessione di AM1)

```
Aggiunta ad AM1 (stessa sessione): le richieste di pubblicazione nelle «Cose da fare», negli stessi tre posti dove ci sono già pratiche, verbali, tutorato e assistenza.
1. Un solo calcolo: riusa CoseDaFareAmministrazione::pubblicazioni() (non rifare le query altrove). Se serve un conteggio più leggero di elenco() completo, aggiungi a PubblicazioniRepository un metodo che conta con COUNT nel database e usalo in CoseDaFareAmministrazione (stessi numeri: prova che coincidono).
2. Scrivania del pannello (admin/inizio.php, elenco $cose): se $puo_pubblicazioni, dentro $sicuro('pubblicazioni', …) due righe del modulo 'amministrazione': «Richiesta di pubblicazione da guardare / Richieste … da guardare» (Amministrazione · arrivate e non ancora nel file Excel, link pubblicazioni.php?vista=arrivate; avviso acceso se la più vecchia aspetta da 7 giorni o più, come le pratiche: per questo il conteggio restituisce anche i giorni della più vecchia, calcolati da inviata_il) e «Riga / Righe del file Excel da completare» (link pubblicazioni.php?vista=monitoraggio, senza avviso). Le «in lavorazione» NON vanno nella scrivania (sono già in mano a qualcuno).
3. Menu a sinistra del modulo: la voce «Richieste di pubblicazione» con il numero delle richieste da guardare, come fa «Pratiche degli studenti» (con try/catch e error_log come le altre voci).
4. Area personale, tessere «Il mio lavoro» (inc/area/lavoro.php, inc/area/_permessi_lavoro.php, src/Portale/Area/PostazioniLavoro.php): nuova postazione «Richieste di pubblicazione» per chi ha puoPubblicazioni (stessa regola di PermessiAmministrazione, non una copia), con il numero delle richieste da guardare come novità e il link a admin/pubblicazioni.php?vista=arrivate; testo breve con anche le righe da completare se ce ne sono.
5. Test: Integration sul conteggio (ricevuta fuori dal file = da guardare; in lavorazione = no; respinta = no; nel file con colonne vuote = da completare; giorni della più vecchia); Unit su PostazioniLavoro con la nuova postazione (compare solo col permesso, numero giusto); prove di pagina: scrivania e area personale per l'abilitato alle sole richieste (le righe ci sono) e per chi non ha permesso (non ci sono). Aggiorna il paragrafo di AM1 e l'elenco dei file in commit_amministrazione.txt.
```

---

## 4. Passo AM2 — Modulistica amministrativa (sessione Sonnet nuova, dopo AM1 approvato)

```
Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. Leggi prima documentazione/amministrazione_sonnet.md (sezione 0, scelta 3). In locale c'è già AM1 (modulo Amministrazione, non committato). Guarda: modulistica.php, src/Didattica/ModuloRepository.php, admin/didattica_moduli.php, src/Portale/CatalogoPagineMenu.php. Fai SOLO il passo AM2.

[incolla qui le «Regole del progetto» della sezione 2]

PASSO AM2 — Modulistica amministrativa.
1. Schema (nuova versione): colonna moduli.ambito VARCHAR(20) NOT NULL DEFAULT 'didattica' con indice; le righe esistenti restano 'didattica'.
2. ModuloRepository: tutti i metodi di lettura prendono l'ambito (predefinito 'didattica') e filtrano su quello; salva scrive l'ambito. Controlla con grep TUTTI i chiamanti: la Didattica deve leggere solo 'didattica' (nessun cambiamento visibile: prove di pagina della Didattica prima/dopo uguali). Attenzione a modulo.php e alle pratiche: un modulo 'amministrazione' NON deve poter aprire una pratica (modulo.php lo rifiuta con un messaggio chiaro).
3. Scheda 'modulistica' di admin/amministrazione.php (solo per puoTutto): elenco per categoria, aggiungi/modifica/elimina documenti da scaricare e link, con le stesse regole e lo stesso caricamento file di didattica_moduli.php (riusa, non copiare: se serve estrai una parte comune senza cambiare la Didattica). Il tipo «online» qui NON si offre (una riga in grigio: «I moduli online che aprono una pratica arriveranno più avanti»).
   In cima alla scheda, fissa, la sezione «Procedure online» con la Richiesta di pubblicazione: stato (aperta/chiusa: per ora sempre aperta, l'interruttore arriva con AM3), chi può inviarla (per ora «Docenti»), link alla pagina pubblica, link a «Richieste di pubblicazione» e (con AM3) a «Impostazioni».
4. Pagina pubblica modulistica_amministrativa.php, stessa grafica di modulistica.php (riusa la parte che disegna le voci: estraila in un parziale comune se serve, senza cambiare l'aspetto della modulistica della Didattica): percorso Home / Modulistica amministrativa; in cima «Procedure online» con la carta della Richiesta di pubblicazione («Compila online» / «Accedi e compila» / «Riservata a …» se l'utente non può); sotto i documenti per categoria e la ricerca.
5. CatalogoPagineMenu: nuovo gruppo «Amministrazione» con «Modulistica amministrativa» e «Richiesta di pubblicazione di un'iniziativa»; NON aggiungerle da solo al menu del sito (le mette l'amministratore da Menu). Descrizione delle pagine per la ricerca/Didacticus come le altre.
6. Home dell'Amministrazione: accendi la voce «Modulistica» in «Tutte le sezioni».
7. Test: Integration su ModuloRepository (un modulo per ambito: ognuno vede solo i suoi; salva con ambito; modulo.php rifiuta l'ambito amministrazione); prove di pagina per modulistica.php (uguale a prima), modulistica_amministrativa.php (anonimo, docente) e la scheda del pannello (admin sì, abilitato solo pubblicazioni no).
```

---

## 5. Passo AM3 — Impostazioni della richiesta e apertura al PTA (sessione Sonnet nuova, dopo AM2)

```
Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. Leggi prima documentazione/amministrazione_sonnet.md (sezione 0, scelte 4-7) e documentazione/richieste_pubblicazione_sonnet.md (com'è fatta la richiesta). In locale ci sono già AM1 e AM2 (non committati). Guarda: src/Pubblicazioni/ (ImpostazioniPubblicazioni, ServizioPubblicazioni::puoInviare, Costanti, RegolePubblicazione, NotifichePubblicazioni), richiesta_pubblicazione.php, admin/pubblicazioni.php (?vista=impostazioni), la tessera inc/area/richieste_pubblicazione.php e il suo riquadro in CatalogoRiquadri, src/Auth/Pubblico.php e html_scelta_pubblico(). Fai SOLO il passo AM3.

[incolla qui le «Regole del progetto» della sezione 2]

PASSO AM3 — Impostazioni della Richiesta di pubblicazione.
1. Schema (nuova versione): una tabella di impostazioni del modulo (o colonne in impostazioni_sistema accanto a pubblicazioni_email_notifica: scegli la più coerente con quello che c'è e dimmi perché), con valori iniziali che NON cambiano niente tranne il PTA:
   - chi_puo_inviare: lista di profili di Pubblico, iniziale ['docenti', 'pta'];
   - aperta (sì/no, iniziale sì) e messaggio_chiusa (testo, iniziale «Al momento le richieste di pubblicazione sono chiuse.»);
   - testo_introduttivo (HTML semplice, iniziale = il testo che la pagina mostra oggi in alto), testo_dopo_invio (iniziale = quello di oggi);
   - limiti: max_sottotitolo (75), max_descrizione (170), max_allegati (10), max_immagini (10), allegati_obbligatori (sì);
   - giorni_minimi di anticipo della data di inizio pubblicazione (iniziale 0 = «non nel passato», come oggi);
   - indirizzi di notifica: quelli che ci sono già (ImpostazioniPubblicazioni::indirizzi), ricevuta_al_richiedente (sì/no, iniziale sì).
   ImpostazioniPubblicazioni diventa la sola classe che legge e salva tutto questo (con validazione: limiti tra 1 e un massimo ragionevole, almeno un profilo, indirizzi come oggi). Le Costanti::MAX_* restano solo come valori iniziali.
2. Usa le impostazioni ovunque: ServizioPubblicazioni::puoInviare (Pubblico::ammesso con chi_puo_inviare) e invia (rifiuta se chiusa); RegolePubblicazione/valida (limiti, allegati obbligatori, giorni minimi); richiesta_pubblicazione.php (testi, contatori del browser presi dall'impostazione, pagina chiusa con il messaggio, «riservata a: <profili in parole>» al posto di «riservata ai docenti»); NotifichePubblicazioni (ricevuta sì/no); LimitiCaricamento (numero file); tessera dell'area personale visibile a chi può inviare (non solo ai docenti) e a chi ha già inviato almeno una richiesta.
   Cerca con grep «docent» in src/Pubblicazioni, richiesta_pubblicazione.php, inc/area, i testi delle email nel catalogo, CatalogoPagineMenu, GuidePerRuolo: dove si parla di chi INVIA la richiesta, scrivi «docenti e personale» o il testo preso dall'impostazione; NON toccare le colonne e le caselle dei destinatari (Docenti, PTA). Elencami nel resoconto ogni testo cambiato.
3. Scheda 'impostazioni' di admin/amministrazione.php (solo puoTutto) con un riquadro «Richiesta di pubblicazione» diviso in: Chi può inviare (html_scelta_pubblico, con l'avviso di Pubblico se si sceglie «scuole» o «chiunque»: per questa procedura NON offrire «anonimi»), Apertura, Testi, Limiti, Email. Un solo «Salva», errori campo per campo, riga in audit_log con cosa è cambiato. La vecchia vista pubblicazioni.php?vista=impostazioni rimanda qui (per chi ha solo il permesso parziale resta la sola parte Email, come oggi: dimmi se è possibile senza duplicare il codice).
   Accendi la voce «Impostazioni» nella home e il collegamento nella sezione «Procedure online» della Modulistica (AM2), con lo stato aperta/chiusa e chi può inviare presi da qui.
4. Test: Unit su ImpostazioniPubblicazioni (valori iniziali, validazione); Integration: un PTA può inviare, uno studente no, un docente sì; con la procedura chiusa nessuno invia; limiti cambiati (es. sottotitolo 50) rispettati dal server; ricevuta spenta = una sola email. Prove di pagina: richiesta_pubblicazione.php per PTA, studente, anonimo, procedura chiusa.
```

---

## 6. Passo AM4 — Campi del form e colonne dell'Excel modificabili (sessione Sonnet nuova, dopo AM3)

```
Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. Leggi prima documentazione/amministrazione_sonnet.md (sezione 0, scelte 4-5). In locale ci sono già AM1-AM3 (non committati). Guarda: src/Pubblicazioni/ColonneMonitoraggio.php, PassiRichiesta.php, RegolePubblicazione.php, EsportazioneMonitoraggio.php, VistaRichiesta.php, RiepilogoRichiesta.php, richiesta_pubblicazione.php e assets/js/richiesta-pubblicazione.js, admin/pubblicazioni.php (scheda della richiesta). Fai SOLO il passo AM4.

[incolla qui le «Regole del progetto» della sezione 2]

PASSO AM4 — Personalizzare i campi che ci sono già (niente campi nuovi: arrivano con AM5).
1. Schema (nuova versione): pubblicazioni_campi (chiave del campo del form, etichetta, aiuto, obbligatorio, visibile, ordine nel passo) e pubblicazioni_colonne (chiave della colonna di ColonneMonitoraggio, intestazione, a_cura_di, colore, tipo testo/si_no/numero, ordine, visibile). Righe vuote = valori del codice: la migrazione NON copia niente, si salva solo quello che l'amministratore cambia (così «Torna all'originale» = cancellare la riga).
2. src/Pubblicazioni/ConfigurazioneCampi: unisce i valori del codice (ColonneMonitoraggio, PassiRichiesta, RegolePubblicazione) con quelli salvati e dà a tutti la stessa risposta: campiDelForm(passo), colonneExcel(). Regole fisse (non modificabili, la scheda lo dice accanto al campo): Titolo e Data di svolgimento sempre visibili e obbligatori; Richiedente (B) e Stato sempre presenti; i destinatari restano un gruppo (almeno uno, se il gruppo è visibile); il tipo delle colonne dei docenti non si cambia.
   Un campo NASCOSTO non si chiede, non si controlla e nella colonna dell'Excel resta vuoto (le richieste vecchie conservano i loro valori). Un campo reso FACOLTATIVO si controlla solo se compilato.
3. Tutto legge da ConfigurazioneCampi: la pagina a sette passi (etichette, aiuti, asterischi, campi nascosti; un passo che resta senza campi visibili si salta e la barra si aggiorna), il JavaScript (riceve le regole dalla pagina, non le ha scritte dentro), RegolePubblicazione::valida, il riepilogo, le email, la scheda del pannello, l'elenco e l'export (intestazioni, ordine, colonne nascoste fuori), Completamento e PubblicazioniRepository::contaDaFare (una colonna nascosta non conta come «da completare»: i due conteggi devono restare uguali, c'è già un test che li confronta). ColonneMonitoraggio resta la fonte dei valori ORIGINALI.
4. Scheda 'campi' in admin/amministrazione.php (solo puoTutto), due parti:
   - «Campi del form»: per passo, una riga per campo con etichetta, aiuto, obbligatorio, visibile, su/giù; anteprima del passo.
   - «Colonne del file Excel»: una riga per colonna con lettera originale, intestazione, a cura di, colore (casella colore), tipo (solo per le colonne degli operatori), visibile, su/giù; pulsanti «Torna all'originale» per riga e «Torna alle intestazioni originali» per tutte (chiede conferma). Avviso fisso: «Se cambi le intestazioni, il file scaricato non sarà più uguale alla Tabella di monitoraggio originale».
   Un solo «Salva» per parte, audit_log con le differenze. Voce «Campi e colonne» nella home e nel menu del modulo.
5. Test: Unit su ConfigurazioneCampi (senza righe = come oggi, identico a prima: confronta con l'export attuale; etichetta cambiata; campo nascosto; Titolo non nascondibile; ordine); Integration: campo reso facoltativo accettato vuoto, campo nascosto non controllato e vuoto nell'export, colonna rinominata nell'export, «Torna all'originale». Prove di pagina: richiesta_pubblicazione.php con un passo senza campi visibili; export Excel/CSV/PDF dopo i cambi.
```

---

## 7. Passo AM5 — Campi nuovi e colonne nuove (sessione Sonnet nuova, dopo AM4)

```
Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. Leggi prima documentazione/amministrazione_sonnet.md (sezione 0, scelte 4-5). In locale ci sono già AM1-AM4 (non committati). Guarda il costruttore di MyForms: src/Forms/CostruttoreCampi.php, admin/parziali/costruttore_campi.php, e come MyForms legge e controlla le risposte sul server (LetturaRisposte / ServizioForms), poi src/Pubblicazioni/ConfigurazioneCampi.php (AM4). Fai SOLO il passo AM5.

[incolla qui le «Regole del progetto» della sezione 2]

PASSO AM5 — Campi e colonne aggiunti dall'amministratore.
1. Schema (nuova versione): pubblicazioni.campi_extra_json (valori dei campi nuovi scritti dal richiedente) e pubblicazioni.colonne_extra_json (valori delle colonne nuove degli operatori), entrambe NULL; definizioni dei campi nuovi in pubblicazioni_campi (chiave 'x_<n>', tipo, opzioni, passo) e delle colonne nuove in pubblicazioni_colonne (chiave 'y_<n>'). Le chiavi non si riusano mai.
2. Campi nuovi del form: si creano con lo stesso costruttore di MyForms (riusa CostruttoreCampi e il parziale, senza cambiare MyForms; tipi: testo, testo lungo, scelta singola, caselle, data, numero, sì/no, link; niente file e niente condizioni in questo passo). Ognuno ha: passo (1-7, dove compare: in fondo al passo), obbligatorio, aiuto, e «diventa una colonna del file Excel» (con intestazione): se sì compare nell'export dopo la colonna dell'ultimo campo del suo passo, altrimenti si vede solo nella scheda e nelle email. Controlli sul server con lo stesso codice di MyForms.
3. Colonne nuove degli operatori: intestazione, a cura di, colore, tipo (testo/si_no/numero), posizione (dopo quale colonna). Si compilano nella scheda della richiesta come le altre, con lo stesso registro modifiche (RegistroModifiche deve capire anche le chiavi y_<n>).
4. Eliminare: un campo o una colonna SENZA valori in nessuna riga si elimina; CON valori si può solo nascondere (la scheda lo spiega e dice in quante righe ci sono valori). Cambiare il tipo di un campo con valori: non permesso.
5. Tutto passa da ConfigurazioneCampi: pagina a passi, JavaScript, valida, riepilogo, email, scheda, export, Completamento (una colonna nuova degli operatori conta come «da compilare» solo se visibile). Le righe a mano (?nuova=1) mostrano anche i campi nuovi.
6. Test: Integration: campo nuovo obbligatorio rifiutato vuoto e salvato in campi_extra_json; campo nuovo nell'export al posto giusto; colonna nuova compilata nella scheda, nel registro e nell'export; eliminazione rifiutata con valori, nascondi permesso; tipo non cambiabile con valori. Prove di pagina: richiesta con un campo per ogni tipo; export Excel/CSV/PDF.
```

---

## 8. Passo AM6 — Rifiniture e messaggio unico (sessione Sonnet nuova, dopo AM5)

```
Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. Leggi prima documentazione/amministrazione_sonnet.md. In locale ci sono già AM1-AM5 (non committati). Fai SOLO il passo AM6.

[incolla qui le «Regole del progetto» della sezione 2]

PASSO AM6 — Rifiniture.
1. Guide (src/Portale/GuidePerRuolo.php): la guida 'pubblicazione' diventa per «docenti e personale» (chi può inviare letto dall'impostazione, come le colonne lette da ColonneMonitoraggio); la guida 'pubblicazioni' spiega che le richieste sono nel modulo Amministrazione, i due permessi, la scheda Impostazioni e «Campi e colonne» (cosa si può cambiare, cosa no, «Torna all'originale»); nuova guida 'amministrazione' (cos'è il modulo, Modulistica amministrativa, chi abilita cosa).
2. Mappe e documentazione: aggiorna le mappe delle richieste di pubblicazione (13b/13i) e aggiungi la mappa del modulo Amministrazione; documentazione/inventario_accessi.md (sezione M e nuova sezione per Amministrazione e Modulistica amministrativa); CSV di Didacticus (voci per «modulistica amministrativa», «richiesta di pubblicazione» anche per il PTA).
3. strumenti/prove/esegui.php: modulo 'amministrazione' con le pagine nuove; giro completo delle prove e dei test con il confronto prima/dopo.
4. Istruzioni di aggiornamento del server in documentazione/istruzioni_aggiornamento_amministrazione.txt, sullo stile di istruzioni_aggiornamento_pubblicazioni.txt: pacchetto, migrazione (versioni dello schema), cosa controllare nel browser su mydibest_pp (elenco come la sezione 11 di richieste_pubblicazione_sonnet.md: card Amministrazione, abilitato parziale, PTA che invia, procedura chiusa, campo nascosto, colonna rinominata e «Torna all'originale», campo nuovo, export, vecchi link delle email). Se nel pacchetto ci sono file mischiati con modifiche non di questo lavoro (per esempio strumenti/crea_ambiente_prova.sh), lasciali fuori e dimmelo.
5. documentazione/commit_amministrazione.txt: rileggilo tutto, un titolo unico («Modulo Amministrazione: modulistica amministrativa e richieste di pubblicazione configurabili, aperte anche al PTA»), un paragrafo per passo, elenco finale dei file controllato con git status (nessun file in più, nessuno in meno, esclusi il file del messaggio e i file non di questo lavoro).
```

---

## 9. Dopo ogni passo

1. Incolla il resoconto di Sonnet nella chat con Opus (revisione con `git diff` e prove).
2. Correzioni chieste da Opus → nella **stessa** sessione Sonnet.
3. Approvato → passo successivo in una sessione Sonnet nuova.
4. Dopo AM6 approvato: commit unico con il testo di `documentazione/commit_amministrazione.txt`, poi pacchetto e prova su mydibest_pp prima della produzione.

## 10. Per dopo (non in questi passi)

- Moduli online amministrativi che aprono una **pratica** (missioni, rimborsi, acquisti…): passi A0–A5 del piano del 17/11
  (`piano_myforms_amministrazione_accessi.md`, parte 2), partendo dai materiali con la segreteria amministrativa.
- Conservazione di righe e allegati delle richieste di pubblicazione: ancora da decidere (§10 di `richieste_pubblicazione_sonnet.md`).
