# Inventario degli accessi per profilo (X1)

Data: 17/11/2026 · Solo lettura: nessun file di codice è stato toccato · Riferimento: `piano_myforms_amministrazione_accessi.md`, PARTE 3, e `src/Auth/Pubblico.php`.

**Come leggerlo.** Ogni tabella elenca i punti che decidono «chi può vedere o fare qualcosa». «Con Pubblico» dice come si scriverebbe la stessa regola con `App\Auth\Pubblico` (`Pubblico::ammesso($regola, $utente, $gruppi)`, con `$utente` = riga di `utenti` e `$gruppi` = nomi minuscoli dei gruppi nella tabella `ruoli`). Rischio: **basso** = si vede o non si vede un collegamento, nessun dato in gioco; **medio** = cambia chi entra in una pagina o in una funzione; **alto** = cambia chi può iscriversi o scrivere dati reali, o la prova non esiste.

## Cose da sapere prima

1. **La colonna è `eventi.ruolo_accesso_id`, non `turni`.** Il piano parla di `turni.ruolo_accesso_id (16 file)`: nel database c'è solo su `eventi` (`install.php:151`; non compare in `Definizioni.php`/`Migrazioni.php`). I turni la ricevono dal JOIN (`PrenotazioneRepository.php:166`, `ProgrammaFslRepository.php:25`, `ElencoEventiArea.php:35`) e la ripassano alle funzioni di disegno come `$tf = $t + ['ruolo_accesso_id' => …]` (`master_template.php:1193`, `:1916`). Una nuova colonna `pubblico_json` va aggiunta nell'elenco `'eventi' => [...]` di `Definizioni.php:1015`.
2. **Già su Pubblico** (P0, ancora non committato): `risorse.accesso` e `didattica_moduli.destinatari` (passano da `ServizioRisorse::puoPrenotare`), e MyForms (`ServizioForms`). Per questi tre non c'è nulla da migrare, solo da verificare e committare.
3. ✅ **FATTO in X2a (17/11/2026).** ~~Il calcolo «chi è ammesso» per gli eventi è scritto in 4 copie~~: ora c'è una sola funzione, `App\Eventi\AccessoEvento::ammesso()` (e `serveAccesso()` per le 3 copie del solo «serve accedere»), usata da `ServizioPrenotazioni`, `ServizioProgrammaFsl` e `master_template.php` (`getPulsanteAzione`, i tre messaggi «Accedi…»). La copia in `renderCardUniversal` calcolava `$ruolo_ok` senza mai usarlo: tolta. Restano da fare, come previsto, i 3 badge «Solo …» (`:353`, `:1144`, `:1895`) e l'agenda.
4. ✅ **FATTO in X2a (17/11/2026).** ~~La query «nomi dei gruppi dell'utente» esiste in 3 copie~~: ora c'è `App\Auth\GruppiUtente` (`nomi($utente)` e `idRuoli($utente)`), usata da `ServizioRisorse::gruppiUtenteNomi`, `ServizioForms::gruppiUtente` e `ProfiliPersona`. `RisorsaRepository::nomiRuoli` è stato tolto. Il `try/catch` di `ProfiliPersona` non serviva: `Database::righe()` non lancia mai eccezioni (`mysqli_report(MYSQLI_REPORT_OFF)`), dà `[]`.

---

## A. Eventi e progetti — `eventi.ruolo_accesso_id`

Valori: `0` = nessuna restrizione (anche senza accesso), `-1` = serve l'accesso, `n > 0` = id di una riga di `ruoli` (1 amministratore, 2 gestore, 3 studente, 4 docente/dipendente, 5 ospite, più i gruppi creati a mano: Docenti, PTA, Scuole, Aziende…). I progetti sono sempre `-1` (`EventoRepository.php:375`).

| file:riga | cosa protegge | regola di oggi | con Pubblico | rischio |
|---|---|---|---|---|
| `src/Iscrizioni/ServizioPrenotazioni.php:115-121` | **l'iscrizione vera** a un turno (controllo lato server) | `0` → tutti; altrimenti serve essere connessi e (`-1`, oppure ruolo principale o secondario = n, oppure ruolo 1). Legge solo `ruoloUtente`/`ruoliSecondari` della sessione (`RichiestaPrenotazione`) | `Pubblico::ammesso(Pubblico::daVecchio($ev), $riga, $gruppi)`: serve però la riga di `utenti` (matricola) e i gruppi, che la richiesta non porta | **alto** |
| `src/Fsl/ServizioProgrammaFsl.php:120-123` | aggiunta di un'attività al programma FSL della scuola | stessa regola, copia identica; riceve `$connesso, $ruolo, $ruoliSecondari` | come sopra | **alto** |
| `master_template.php:331-337` (card evento), `:732-738` + `:765-767` (pulsante del turno) | quale pulsante mostrare: Prenota / Accedi / «Riservato» | stessa regola, 2 copie in PHP del template; `$is_admin` = ruolo 1 | funzione unica `ammesso()` + 3 stati: ammesso, anonimo non ammesso → «Accedi», loggato non ammesso → «Riservato» | medio |
| `master_template.php:278`, `:1051`, `:1230` | testo «Accedi con SPID/CIE…» | `ruolo_accesso_id !== 0 && !$utente_logged` | `!ammesso($regola, null)` (per l'anonimo) | basso |
| `master_template.php:181-182`, `:353-354`, `:1144-1145`, `:1895-1896` | etichette «Solo Autenticati / Solo Studenti…» | tabella fissa `$nomi_ruoli` con 6 voci; i gruppi creati a mano mostrano «Riservato» generico | elenco dei profili scelti (`Pubblico::ETICHETTE`) | basso |
| `src/Eventi/AgendaRepository.php:35` | quali eventi compaiono nell'agenda/«per le scuole» | `IFNULL(e.ruolo_accesso_id,0) = 0`: **gli eventi con qualunque restrizione (anche solo «accesso») spariscono dall'agenda** | `ammesso(regola, utente)` richiede l'utente: oggi l'elenco è uguale per tutti, e lo stesso elenco alimenta l'ICS (`agenda_ics.php`, che non ha utente; non verificato fin dove) | medio |
| `admin/eventi.php:161`, `:224`, `:578-581` | scelta nel pannello («Solo utenti autenticati» + «Solo: <ruolo>») | select unica con tutte le righe di `ruoli` | `html_scelta_pubblico()` (già pronto in `inc/auth.php`) + `pubblico_da_post()` | medio |
| `src/Eventi/EventoRepository.php:233`, `:245`, `:356`, `:375` | scrittura della colonna (progetti forzati a `-1`) | INSERT/UPDATE diretti | scrivere `pubblico_json` e continuare a scrivere `ruolo_accesso_id` finché non si toglie | medio |
| `HomeRepository.php:29-43` (prossimi eventi), `RicercaGlobale::eventi` → `EventoRepository::cerca` (`:32`) | elenchi pubblici | **non filtrano** (nessun riferimento a `ruolo_accesso_id` in quei file): l'evento riservato si vede a tutti, si restringe solo il prenotare. `sitemap.php` e gli ICS non letti a fondo | restano così, oppure «chi vede» separato da «chi prenota» (vedi punti non uguali) | basso |

Prove che coprono oggi questi punti: `tests/Integration/Eventi/EventiIntegrazioneTest.php:344` e `:435`, `tests/Integration/Iscrizioni/PrenotazioneTest.php:197`, `tests/Unit/Auth/PubblicoTest.php:102` (`daVecchio(0)`), `strumenti/prove/esegui.php:208`. Mancano prove sul ramo FSL (`ServizioProgrammaFsl`).

## B. Risorse — `risorse.accesso` (già su Pubblico)

| file:riga | cosa protegge | regola di oggi | con Pubblico | rischio |
|---|---|---|---|---|
| `src/Risorse/ServizioRisorse.php:165-176` (`puoPrenotare`), usata a `:58` (lista d'attesa) e `:194` (prenota) | prenotare una risorsa | gestori dell'area sempre; poi `Pubblico::ammesso(daVecchio($accesso))` con `$accesso` ∈ tutti/studenti/docenti/personale | **già fatto** | — |
| `src/Risorse/Costanti.php:17-20`, `Definizioni.php:76`, `admin/risorse.php:30`, `:263`, `inc/risorse.php:9`, `:40-52` | valori ammessi e scelta nel pannello | 4 valori testuali (VARCHAR(20)) | per ora select a 4 valori: per avere tutti i profili serve `pubblico_json` o più chiavi | basso |
| `calendario_area.php:94`, `:204`, `:268` | pulsante «Prenota» e messaggio «prenotabile solo da…» | usa `puo_prenotare_risorsa` | già coerente; il testo viene da `ACCESSI_RISORSA` | basso |

## C. Moduli della Didattica — `didattica_moduli.destinatari` (già su Pubblico)

| file:riga | cosa protegge | regola di oggi | con Pubblico | rischio |
|---|---|---|---|---|
| `src/Didattica/ServizioModuli.php:38-48` | compilare un modulo online | `'tutti'` o ufficio che gestisce → sì; altrimenti si riusa `puoPrenotare(['accesso'=>destinatari,'pagina_id'=>0])` (cioè Pubblico) | già fatto, ma passa dal servizio delle **risorse** (accoppiamento da sciogliere) | basso |
| `modulo.php:17`, `:79` | pagina del modulo e messaggio | `utente_destinatario_modulo` (`inc/didattica.php:162-166`) | invariato | — |
| `modulistica.php`, `pratiche.php` | elenco dei moduli | **non filtrano** per destinatari: chi non è destinatario vede la voce e trova il rifiuto aprendola | filtrare l'elenco con `ammesso()` (nuova scelta, non c'è regola da preservare) | basso |
| `Costanti.php:57-59` (Didattica), `Definizioni.php:179`, `admin/didattica_moduli.php:42`, `:152`, `ServizioModuli.php:93` | scelta nel pannello | 4 valori | `html_scelta_pubblico()` + colonna `pubblico_json` come in MyForms | medio |

## D. MyForms (già su Pubblico)

`src/Forms/ServizioForms.php:214` e `:422` (`ammesso($this->regola($form), …)`), `:127/:140/:150` (solo amministratori: `ammesso(['profili'=>[]], $utente)`), `RicercaGlobale.php` (moduli nella ricerca via `apertiPer`), `forms.php`, `form.php`. Nessuna azione.

## E. Voci di menu — `menu_voci.ruolo_visibilita_id`

| file:riga | cosa protegge | regola di oggi | con Pubblico | rischio |
|---|---|---|---|---|
| `src/Portale/Visitatore.php:44-57` | visibilità di una voce | `0` tutti; `-1` chi è connesso; `n>0` ruolo principale o secondario = n **oppure amministratore (ruolo 1 o 2)**; `< -1` nascosta | `ammesso(daVecchio($v), …)` ma con 2 differenze (gestore, codici speciali) | medio |
| `src/Portale/AppartenenzeVisitatore.php:42-63` | voci «solo per un ufficio» (`-(1000+id)`) e «responsabili di risorse» (`-2`) | riconoscimento dall'email in `uffici_persone` / `risorse_responsabili`; gli amministratori vedono tutto | **nessun profilo equivalente**: servono «appartenenze» a parte (vedi punti non uguali) | alto se si forza, basso se si lascia |
| `src/Portale/ServizioMenu.php:38`, `header.php:474` | costruzione del menu a ogni pagina | `Visitatore::daSessione($_SESSION)`: solo ruolo, ruoli secondari, email | Pubblico vuole matricola e gruppi: una lettura in più della riga e dei gruppi per pagina (da fare una volta per richiesta) | medio |
| `admin/menu.php:34-40` (badge), `:54`, `:68`, `:99` (`opzioniVisibilita`), `:206`, `:399` | scelta nel pannello | select con Pubblico/Autenticati/ruoli/uffici | `html_scelta_pubblico()` (+ riga per uffici e responsabili) | medio |
| `MenuRepository.php:103`, `:112-114`, `:159-171`, `CatalogoPagineMenu.php:25-35`, `:215-225`, `AreaRepository.php:167`, `install.php:211` | creazione delle voci e voci riservate di serie (Direzione, responsabili) | valori numerici scritti da codice | si lasciano numerici; la tabella ha una sola colonna intera | basso |
| `header.php:57`, `:389`, `:521` | link «Pannello Gestori» e «Scanner Check-in» | ruolo 1 **o 2**, principale o secondario | non è un pubblico: è permesso di lavoro (vedi sezione K) | — |

Nota già nel codice (`AppartenenzeVisitatore.php:14`): *nascondere una voce non protegge la pagina*: ogni pagina si difende da sola. Quindi il menu è la migrazione col danno minore se sbagliata.

## F. Comunicazioni e avvisi

| file:riga | cosa protegge | regola di oggi | con Pubblico | rischio |
|---|---|---|---|---|
| `Definizioni.php:322-326`, `ComunicazioniRepository.php:44-52`, `:137-143` | `comunicazioni.pubblico` (CSV: studenti, scuole, aziende, docenti) | **non è un permesso**: serve a filtrare l'elenco («Per: …») e a mostrare i badge; tutte le comunicazioni pubblicate le vede chiunque, anche senza accesso | oggi non c'è nulla da tradurre; diventerebbe un permesso solo se si aggiunge una scelta nuova «Chi può leggere» | basso |
| `comunicazione.php:9-19`, `comunicazione_file.php:19-26`, `comunicazioni.php:12`, `comunicazioni_feed.php:7` | pagina, allegati, elenco, feed RSS | pubblici; bozze e anteprima solo ruolo 1 (`comunicazione.php:15`, `comunicazione_file.php:25`) | un permesso vero toccherebbe 4 file (pagina, allegato, feed, elenco) e il feed RSS non ha utente | medio |
| `Assistenza/Costanti.php:70`, `:104-107`, `admin/comunicazioni.php:83`, `:93`, `:138` | vocabolario e pannello | 4 valori propri («Docenti e personale», «Aziende ed enti») | vocabolario diverso da `Pubblico::PROFILI` | basso |
| `index.php:316-322` (`annuncio_home`) | annuncio in home | per tutti | nessuna regola | — |
| `src/Avvisi/*` (`IscrizioneRepository`, `ServizioAvvisi.php:142`) | avvisi di nuovi eventi a chi si è iscritto per ambito/scuole | è una **preferenza di interesse**, non un accesso | fuori da questo lavoro | — |

## G. Didacticus e domande frequenti — `didacticus_voci.solo_login`

| file:riga | cosa protegge | regola di oggi | con Pubblico | rischio |
|---|---|---|---|---|
| `VociRepository.php:41`, `ServizioDidacticus.php:82`, `:100`, `:103`, `:117`, `:224`, `:278`, `:295` | risposte riservate a chi ha fatto l'accesso | booleano `$conLogin` | `REGISTRATI` / `ANONIMI`; il filtro è anche in SQL (`AND solo_login = 0`) | basso (ma non serve a nessuno) |
| `domande_frequenti.php:25`, `didacticus_file.php:46`, `admin/didacticus.php:99`, `:309` | stesso interruttore in pagina e per gli allegati | idem | idem | basso |

Consiglio: lasciarlo com'è. Due soli valori, nessuna richiesta di «solo docenti» per le risposte.

## H. Pagine riservate

| file:riga | cosa protegge | regola di oggi | con Pubblico | rischio |
|---|---|---|---|---|
| `middleware.php:11` + 37 pagine che lo includono (`area_personale`, `calendario`, `miei_eventi`, `profilo`, `pratiche`, `modulo`, `da_fare`, `firme`, `mie_conferme`, `il_mio_tutorato`, `miei_studenti`, `ricevimento`, `sono_una_scuola`, `registro_tutorato`, `pagamenti_tutorato`, `scanner_studente`, …) | pagine personali | basta essere connessi; poi ogni pagina fa i suoi controlli | = `REGISTRATI`; non c'è da migrare nulla | — |
| `accesso_richiesto.php:8`, usata da `tirocini.php:7`, `tirocini_motorie.php:7`, `aziende_convenzionate_tesi.php:6-7` | elenchi di tirocini e aziende | basta essere connessi | `REGISTRATI` oggi; se si vuole «solo studenti» è una scelta nuova: `ammesso(['profili'=>['studenti']], …)` | basso |
| `attestati_gruppo.php:11`, `autorizzazione_scuola.php:11`, `elenco_studenti.php:13`, `calendario_ics.php:15-16`, `cerca_insegnamenti.php:15`, `self_checkin.php:6` | pagine con login + codice/token | login (e codice nel link) | `REGISTRATI` | — |
| `la_mia_disponibilita.php:15-16` | disponibilità del docente | `ProfiliPersona::di()` contiene DOCENTE, oppure ruolo 1 | `ammesso(['profili'=>['docenti']])` ma attenzione: ProfiliPersona riconosce il docente anche dall'anagrafe (vedi punti non uguali) | medio |
| `verbali.php`, `stampa_ricevuta.php:17-20`, `documento_tutorato.php:15`, `checkin.php:14` (`require_admin_or_gestore`, `middleware.php:35-49`), `cruscotto_direttore.php`, `risorse_*.php`, `diario_bordo.php`, `direttore_assente.php` | verbali con password, ricevute, Direzione, responsabili, ufficio bandi, scanner | password separata, abilitazioni, uffici, firme, responsabili di risorsa | **non sono un pubblico**: sono permessi di lavoro (sezione K) | non toccare |
| pagine della radice senza alcun controllo di accesso (`corsi.php`, `docenti.php`, `contatti.php`, `scadenze.php`, `orientamento.php`, `disponibilita_tesi.php`, `disponibilita_tirocini.php`, `percorso_*.php`, `guida.php`) | pagine pubbliche | nessun controllo | restano pubbliche | — |

I controlli interni di `miei_studenti.php`, `ricevimento.php`, `registro_tutorato.php` (riconoscimento da CF, email o scheda dell'anagrafe) non sono stati letti riga per riga: sono comunque permessi legati a una persona, non a un profilo.

## I. Area personale — profili propri (non Pubblico)

| file:riga | cosa protegge | regola di oggi | con Pubblico | rischio |
|---|---|---|---|---|
| `src/Portale/Area/ProfiliPersona.php:40-69`, `Profili.php:21-31` | quali riquadri compaiono (`RiquadriRepository.php:55-72`, `area_personale.php:24-32`) | 9 profili propri: studente, docente, direttore, personale, gestore, amministratore, scuola, azienda, esterno; una persona può averne più | **secondo sistema di profili**, con vocabolario e regole diverse da Pubblico | medio |
| `CatalogoRiquadri.php:23-93`, `Definizioni.php:314-320` (`area_riquadri.profili`), `admin/area_personale.php` | assegnazione riquadro → profili | CSV di chiavi `Profili::*` | si può lasciare: è una vista, non un permesso | basso |
| `area_personale.php:25` (`?come=`), `inc/area/lavoro.php:6`, `inc/area/messaggi.php:5` | anteprima «come profilo X» e tessere di lavoro | `$is_full_admin` | non è un pubblico | — |

## J. Home e widget

`index.php:43` (la mia prenotazione: serve il login), `:316` (annuncio), `HomeRepository.php:29-43`, `WidgetHome.php`, `ConfigurazioneHome.php`: **nessuna regola per profilo**. I widget si accendono o spengono per tutti. Se si volesse «per profilo» sarebbe una funzione nuova, non una migrazione.

## K. Non è un pubblico: permessi di lavoro (da non migrare)

`Auth/Abilitazioni/*` (moduli e ambiti dei gestori), `utente_ha_abilitazioni`, `require_admin_or_gestore`, `Attestati/Accesso.php` (`eStaff`), `Uffici/Direzione`, `utente_operatore_ufficio`, `ha_modulo`, gestori di area (`IdsGestori`), firme per persona, `SimulazioneAccesso::eAmministratore`. Rispondono a «chi lavora su questo», non a «a chi si rivolge». Pubblico non li sostituisce.

## L. Ricerca

`ricerca.php:14-15` passa la riga utente a `RicercaGlobale::cerca`; i moduli di MyForms sono filtrati con Pubblico (`RicercaGlobale.php`, `moduli()`); gli eventi riservati compaiono comunque nei risultati (`EventoRepository::cerca`), le risposte di Didacticus riservate solo con login. Se gli eventi diventano «visibili solo a…» la ricerca va allineata.

## M. Richieste di pubblicazione dei docenti (aggiunta del 19/11/2026)

Pagine nuove, scritte già sul modello giusto: la riga si legge da `utenti` (non dalla sessione) e il permesso si ricontrolla a ogni richiesta, anche in GET e negli scaricamenti.

| file | cosa protegge | chi può entrare | con Pubblico | rischio |
|---|---|---|---|---|
| `richiesta_pubblicazione.php` | invio della richiesta (7 passi) | **non connesso**: vede «Accedi per compilare» e torna alla pagina dopo l'accesso. **Connesso ma non docente**: vede «riservata ai docenti». **Docente**: compila e invia. Regola: `ServizioPubblicazioni::puoInviare` = `Pubblico::ammesso(['profili' => ['docenti']], $utente, GruppiUtente::nomi($utente))`; l'invio la ricontrolla | già su Pubblico | — |
| `allegato_pubblicazione.php` (`?p=` richiesta, `?a=` file) | scaricamento di allegati e immagini | `ServizioPubblicazioni::puoVedere`: **il docente che ha inviato la richiesta** (`pubblicazioni.utente_id`) oppure chi può gestire (`puoGestire`). Tutti gli altri, anche connessi, e chi non è connesso: «File non trovato» (404). Il file si trova dalla riga salvata, solo se sta in `uploads/pubblicazioni/` | `puoGestire` è un permesso di lavoro (sezione K), non un profilo | — |
| `admin/pubblicazioni.php` | pannello: richieste arrivate, tabella di monitoraggio, scheda, righe a mano, scaricamenti, impostazioni | `ServizioPubblicazioni::puoGestire`: **amministratori** (`Pubblico::ammesso(['profili' => []])`, solo ruolo 1) oppure persone con il **modulo «Richieste di pubblicazione»** (`modulo_pubblicazioni`, `pagina_id` 0, da Utenti › «Moduli interi»). Gli altri: `nega_accesso()` | permesso di lavoro (sezione K): da non migrare | — |
| `inc/area/richieste_pubblicazione.php` (tessera dell'area personale) | cosa si vede, non cosa si può fare | profili `docente` e `amministratore` di `ProfiliPersona` | è una vista (sezione I) | — |
| `inc/sezioni.php`, `admin/admin_header.php`, `admin/utenti.php`, `Perimetri.php` | menu del pannello e abilitazione per persona | il modulo è **a sé**: chi ha solo «Richieste di pubblicazione» non vede MyForms e viceversa | permesso di lavoro (sezione K) | — |

Punto da provare con «Entra come» (stessa questione del punto 7 qui sotto): la tessera compare ai docenti di `ProfiliPersona` (che riconosce il docente anche dalla scheda dell'anagrafe), mentre l'invio usa `Pubblico` (gruppo «Docenti» della persona). Di norma i due coincidono perché l'accesso allinea il gruppo, ma un docente riconosciuto solo dall'anagrafe potrebbe vedere la tessera e trovare «riservata ai docenti». Se capita, va allineato il gruppo, non la pagina.

---

## Punti in cui la regola di oggi NON si traduce uguale con Pubblico

1. **Il gestore (ruolo 2).** `Visitatore::amministratore()` (`:38-41`) e `header.php:57`, `stampa_ricevuta.php:20`, `middleware.php:22` trattano ruolo 1 **e 2** come «amministratore»; `Pubblico::ammesso` ammette solo il ruolo 1. Per le voci di menu i gestori oggi vedono tutte le voci riservate a un ruolo: con Pubblico no. Per gli eventi oggi vale solo il ruolo 1 (uguale). *Decisione da prendere:* i gestori sono «sempre ammessi» nel menu? (sì = una riga in più, ma allarga rispetto a Pubblico).
2. **I ruoli numerici positivi.** `ruolo_accesso_id = n` e `ruolo_visibilita_id = n` confrontano l'id di una riga di `ruoli`. `Pubblico::daVecchio(n)` lo conserva tale e quale (`['ruolo_id'=>n]`): identico, ma resta invisibile nel pannello. Tradurlo in un profilo con nome **cambia chi passa**: 3→`studenti` ammette anche chi ha `matricola_studente` senza ruolo 3; 4→`personale` ammette tutti i gruppi Docenti/PTA/Altro anche con ruolo diverso; 5→`esterni` diventa «né studente né personale» e include Scuole e Aziende, mentre oggi è solo il ruolo Ospite. 1 e 2 non hanno profilo. *Proposta:* tradurre solo `0` e `-1`; tenere gli `n` positivi come regola legacy finché l'amministratore non li ripassa uno a uno.
3. **«Tutti» non vuol dire la stessa cosa.** Negli eventi `0` = anche senza accesso; nei moduli, nelle risorse e nel menu `-1`/`'tutti'` = solo chi ha fatto l'accesso. `daVecchio(0)` e `daVecchio('tutti')` danno regole diverse: giusto, ma chi scrive i pannelli deve usare il profilo `anonimi` per il primo e `registrati` per il secondo. Occhio anche a `daVecchio('0')`: una stringa numerica è trattata come intero, cioè «tutti».
4. **Codici speciali del menu.** `-2` (responsabili di risorse) e `-(1000+id ufficio)` non sono profili. Vanno tenuti in `AppartenenzeVisitatore` oppure Pubblico deve imparare una seconda chiave nella regola (es. `appartenenze`). Forzarli nei profili esistenti non si può.
5. **«Chi vede» contro «chi prenota» (eventi).** Oggi l'evento riservato si vede a tutti e si restringe solo il pulsante; fa eccezione l'agenda, che nasconde tutti gli eventi con restrizione (`AgendaRepository.php:35`, anche quelli solo «con accesso»). Con `pubblico_json` bisogna decidere se «Riservato a Docenti» significa *invisibile ai non docenti* (cambia home, ricerca, ICS, sitemap, agenda) o *solo non prenotabile* (resta com'è). Consiglio: restare com'è e sistemare l'agenda per usare la stessa regola del pulsante.
6. **Dati che i servizi ricevono.** `RichiestaPrenotazione` e `RichiestaProgramma` portano solo ruolo principale e secondari; Pubblico ha bisogno anche della matricola da SSO e dei nomi dei gruppi. Il passaggio costringe a cambiare le firme di `ServizioPrenotazioni` e `ServizioProgrammaFsl` (rischio alto, vedi A). Lo stesso per il menu (`Visitatore::daSessione`).
7. **Due sistemi di profili che non coincidono.** `ProfiliPersona` (area personale) riconosce il docente anche dalla scheda dell'anagrafe (`persona_id`), il personale anche dall'appartenenza a un ufficio, la scuola anche dal solo `scuola_codice`, l'azienda dall'email della convenzione in diretta; Pubblico guarda solo ruolo e gruppi in `ruoli`. In pratica i gruppi Docenti/PTA si assegnano al login (`CollegamentoUtente`) e Scuole si allinea quando la scuola viene scelta o prenota; **ma il gruppo Aziende si crea solo con `GruppoAziende::allineaTutti()` (`Migrazioni.php:142`) o a mano**: un'azienda che entra per la prima volta è «Azienda» nell'area personale e **non** per Pubblico finché non si riallinea. Anche `ESTERNO` non coincide (`ProfiliPersona` = nessun altro profilo; Pubblico = né studente né personale). *Proposta:* non unificarli ora; scrivere una tabella di corrispondenza e un allineamento del gruppo Aziende al login.
8. **Il profilo `scuole` si dichiara da soli.** `sono_una_scuola.php` (middleware = qualunque connesso) assegna `scuola_codice` e quindi il gruppo «Scuole» (`ScegliScuola::imposta`, `GruppoScuole::allinea`; stesso allineamento in `PrenotazioneRepository.php:224`). Basta una scuola dell'elenco: nessun controllo che la persona sia un docente. Il commento di `Pubblico` dice «mai da qualcosa che la persona può scrivere da sola»: per `scuole` non è vero. Va bene per «FSL per le scuole», **non per un form o un evento «solo scuole» con contenuti sensibili**.
9. **`aziende` dipende dall'email.** `GruppoAziende::haConvenzione` confronta l'email della convenzione con quella dell'utente: sicuro solo con email verificata. La falla 0.1 (cambio email senza verifica) è chiusa in locale (v107, `VerificaEmail`), **da caricare**; non ho verificato se l'email di chi si registra da solo (`Auth/Registrazione`, `Portale/Registrazione`) sia verificata. Da chiudere prima di aprire cose delicate a `aziende` o a `docenti` scelti per email.
10. **Comunicazioni.** `pubblico` è un filtro di interesse con 4 valori propri, non un permesso: tradurlo in Pubblico cambierebbe il comportamento (oggi nessuna restrizione). Se serve, è una scelta nuova accanto alla vecchia.
11. **Simulazione «Entra come».** `SimulazioneAccesso` riscrive le chiavi della sessione (`CHIAVI_IDENTITA`) e le pagine rileggono la riga da `utenti`: Pubblico e sessione restano coerenti. Tutti i controlli nuovi devono passare dalla riga `utenti` (non da chiavi di sessione aggiunte), altrimenti la prova X3 non li vede.
12. **Elenchi non filtrati.** Modulistica, home e ricerca eventi mostrano anche ciò che per te è riservato; con Pubblico si può filtrare, ma è un cambio visibile (vedi 5).

## Ordine proposto per migrare (dalla più semplice)

**Prima, tre cose non di codice o piccole:** (a) committare P0 e togliere le 3 copie della query dei gruppi; (b) decidere il punto 1 (gestori) e il punto 8 (scuole autodichiarate); (c) caricare la 0.1 e controllare l'email di chi si registra.

1. **Già fatte:** moduli, risorse, MyForms, ricerca dei moduli. Resta il filtro dell'elenco in `modulistica.php` e la scelta completa del pubblico nei pannelli di moduli e risorse (rischio basso).
2. **Pagine che chiedono solo il login** (`middleware`, `accesso_richiesto`): nessuna migrazione. Eventuale «solo studenti» su `tirocini_motorie.php` come scelta nuova.
3. **Comunicazioni e avvisi:** nessuna regola esistente da preservare, quindi si può aggiungere «Chi può leggere» (colonna nuova, vuota = tutti) senza rompere nulla; 5 file. Rischio basso.
4. **Voci di menu:** una tabella, un servizio, un pannello. Se sbagli si vede un collegamento di troppo o di meno; le pagine si difendono da sole. Qui servono le decisioni 1 e 4. Rischio medio. *Diverso dal piano X2* (che mette gli eventi prima): lo metto prima perché non tocca iscrizioni.
5. **Eventi e turni:** per ultimo tra i permessi, e in due tempi. Prima **una sola funzione** `ammesso($evento, $utente)` al posto delle 4+3+3 copie (nessun cambiamento di comportamento, con prove sul ramo FSL); poi `pubblico_json` con `ruolo_accesso_id` tenuta e tradotta (solo 0 e −1, punto 2). Rischio alto: è dove ci si iscrive, con dati veri.
6. **Home, widget, area personale:** per ultimi, e forse mai. Non decidono permessi (riquadri e widget sono una vista) e hanno un sistema di profili proprio (punto 7).
7. **Didacticus `solo_login`:** lasciare com'è.

Prova per profilo (X3) dopo ogni passo, con «Entra come»: studente, docente, PTA, esterno, scuola, azienda, non connesso.

---

## Decisioni (17/11/2026)

- **Punto 1, gestori (ruolo 2).** Nel **solo menu** i gestori restano sempre ammessi come oggi: vedono tutte le voci riservate a un ruolo (`Visitatore::amministratore()`). Si farà in **X2b**, con una riga in più accanto a `Pubblico`. Negli eventi e altrove vale la regola di `Pubblico`/`AccessoEvento`: passa sempre solo il ruolo 1, il gestore no (come oggi).
- **Punto 8, scuole.** Il profilo «scuole» resta **autodichiarato** (`sono_una_scuola.php`): è una scelta, ma va detta chiaramente. **Fatto in X2a**: il commento di `Pubblico` non dice più «mai da qualcosa che la persona può scrivere da sola» per le scuole; accanto alla casella «Scuole» nei pannelli (`PubblicoVista`) c'è la frase «Chiunque abbia fatto l'accesso può dichiararsi scuola: non usarlo per cose riservate.» (costante `Pubblico::AVVISO_SCUOLE`, la stessa nelle guide); la frase è anche in `documentazione/guide/guida_myforms.md` e nella guida «MyForms: creare i moduli» (`GuidePerRuolo`).
- **Punti 3 e 4 di «Cose da sapere prima»:** copie tolte, vedi sopra (fatto in X2a).
