# Il nucleo — materiali della Fase 0

> Scritto il 22/11/2026 (passo N0b). Solo descrizione: nessuna riga di codice è cambiata per scriverlo.
> Misurato sul codice di `HEAD` (`fbe923c`) con numeri di riga di quel momento. Dove un numero differisce dal piano
> (`piano_framework_dibest.md`) è perché qui è stato **rimisurato** con una ricerca più stretta: lo dico accanto.

---

## 1. Lo scheletro di oggi

Legenda: **L** = logica della pagina · **I** = impalcatura ripetuta · **D** = documentazione/commento.

### 1.1 `corsi.php` — pagina pubblica (85 righe)

| Righe | Cosa fa | |
|---|---|---|
| 1-3 | commento di testata | D |
| 4-5 | `require_once 'config.php'; 'functions.php'` (sessione, ambiente, buffer, CSP, DB) | **I** |
| 7 | `$h = fn($s) => htmlspecialchars(...)` | **I** (escape) |
| 8-10 | `App::per($conn)` e tre `->get()` dal contenitore | **I** (cablaggio dei servizi) |
| 12 | elenco dei corsi visibili | L |
| 13-14 | `?corso=` letto da `$_GET`, trim, ricerca del corso | L (la lettura di `$_GET` è impalcatura, ciò che se ne fa è logica) |
| 15 | un corso con `visibile != 1` non si mostra | L (regola di dominio: è l'unico «permesso» della pagina) |
| 16 | `?q=` da `$_GET` | L |
| 18-27 | dipartimento proprio, anno accademico, elenco insegnamenti | L |
| 29-30 | `$page_cfg['titolo']` + `require_once 'header.php'` (da qui in poi HTML) | **I** (titolo e testata) |

Nelle prime 30 righe: **nessun accesso, nessun CSRF, nessuna impaginazione** (pagina pubblica di sola lettura). L'impalcatura sono ~6 righe su 30; le altre sono logica.

### 1.2 `admin/scuole.php` — pannello con POST (225 righe)

| Righe | Cosa fa | |
|---|---|---|
| 1-5 | commento di testata | D |
| 6 | `require_once 'admin_header.php'` — dentro: sessione, SSO, utente, 20 permessi, menu, **guardia `PermessiPagine`**, apertura del layout HTML | **I** (la più pesante: ~750 righe) |
| 8 | `if (!$puo_fsl_scuole) nega_accesso();` | **I** — duplica ciò che `PermessiPagine` (`'scuole.php' => ['puo_fsl_scuole']`) ha già deciso alla riga 683 di `admin_header.php`; è la «seconda protezione» voluta |
| 10 | `function admin_redirect($url) { vai_a($url); }` | **I** (alias locale, ricorre in altre pagine) |
| 12-14 | servizio dal contenitore, costante delle regioni | L / I (cablaggio) |
| 16 | `if ($_SERVER['REQUEST_METHOD'] === 'POST')` | **I** |
| 17 | `csrf_verify($_POST['csrf_token'] ?? '')` | **I** (CSRF) |
| 18-26 | importazione del file, `registra_log_audit`, `flash_set` con esito | L (il `flash_set` è impalcatura del «dopo il salvataggio») |
| 27-30 | pulizia «solo Calabria», audit | L |
| (dopo la 30) 43 | `admin_redirect("scuole.php?p_id=…&r=" . time() . "#…")` (= `vai_a`) — il rimando post-POST | **I** (PRG: Post/Redirect/Get) |
| 53 | `$h = fn($s) => …` (ridefinita, qui) | **I** (escape) |
| 55-225 | HTML con `<style>` in testa e `admin_footer.php` (riga 225) | vista |

Impalcatura nelle prime 30 righe: **righe 6, 8, 10, 16, 17** (+ il `flash_set` e il `vai_a` che chiudono il POST). Tutto il resto è logica.

### 1.3 `cerca_scuole.php` — endpoint JSON (31 righe)

| Righe | Cosa fa | |
|---|---|---|
| 1-3 | commento | D |
| 4-5 | `config.php` + `functions.php` | **I** |
| 7 | `while (ob_get_level() > 0) ob_end_clean();` (butta il buffer, anche quello della fascia di prova) | **I** (risposta) |
| 8-9 | `Content-Type: application/json`, `X-Robots-Tag: noindex` | **I** (risposta) |
| 11-12 | commento dei parametri | D |
| 13 | `check_rate_limit(...)` → `http_response_code(429); echo '[]'; exit;` | **I** (limite + risposta 429) |
| 14 | `$par = fn(string $k) => mb_substr(trim((string)($_GET[$k] ?? '')), 0, 80)` | **I** (lettura del parametro: è ciò che farà `Richiesta::query()`) |
| 16-20 | `?dettaglio=` → `json_encode(servizio->perConvenzione(...))` + `exit` | L (+ `json_encode` + `exit` = risposta) |
| 22-25 | `?elenco=` → `luoghi_scuole(...)` + `exit` | L |
| 27-30 | `?q=` , filtri, regola «minimo 3 caratteri» | L |

Qui l'impalcatura è **più della metà delle righe vere**: 4-5, 7-9, 13, 14 e un `echo json_encode(...); exit;` per ognuno dei tre rami. È il candidato ideale per la prima `Risposta::json()`.

**Cosa si vede da tutte e tre insieme**: nessuna pagina ha un'entrata unica; ognuna ricostruisce da sola «chi sei, cosa puoi, come rispondi». Le tre cose che ricorrono sempre sono (a) lettura dei parametri con `trim`/cast a mano, (b) il rimando o l'uscita con `exit` nel mezzo del file, (c) l'escape come lambda locale.

**Conteggi rimisurati** (stessa ricerca su `HEAD`; il piano ne dava altri, probabilmente perché contava anche `src/`):
`nega_accesso(` in **38** file di radice/`admin/`/`inc/` (+ `nega_accesso_pannello(` in 22 file di `admin/`) · `csrf_verify(` in **87** · `$h = fn` in **79** · `check_rate_limit(`/`limite_superato(` in **24** · `while (ob_get_level() > 0)` in **76** punti.

---

## 2. I permessi

### 2.1 Come funziona oggi, in tre righe

`admin/admin_header.php` costruisce l'utente (riga 18, dal DB), poi calcola le variabili `$is_full_admin` (riga 44) e i `$puo_*` / `$can_*` (righe 121-173), e le rimette dentro l'array `$permessi_pannello` (righe 669-682), che passa a `PermessiPagine::consente($paginaIngresso, $permessi_pannello)` (riga 683). Se la regola dice no: `nega_accesso_pannello()` (403 nello stile del pannello) oppure, per le pagine d'ingresso, un rimando all'area personale. La guardia gira **dopo** che l'header HTML del pannello è già stato emesso (la guardia è alla riga 683, dopo centinaia di righe di layout): per questo `vai_a()` funziona solo grazie a `ob_start()` di `config.php`.

Due cose da sapere prima di fare `Permessi`:

1. **Cinque dei permessi non sono dell'utente ma dell'utente nell'area corrente** (`can_manage_*`, `is_area_manager`, `puo_creare_*`, `allowed_events_ids`): dipendono da `?p_id=` (`$filtro_p`, riga 106) e da `$page_cfg`. `Permessi` dovrà ricevere anche l'area, non solo l'identità.
2. **Molti calcoli sono già in classi**: `servizio_abilitazioni()` (`ha_modulo`, `ha_ambito`, `area_nel_modulo_utente`…), `PermessiAmministrazione`, `OperatoriAssistenza`, `ServizioUffici::utenteMembro`, `ServizioConvenzioniTesi::utenteDellUfficio`, `FormRepository::haGestiti`. `Permessi` sarà soprattutto un **aggregatore** che le chiama una volta sola, non un nuovo calcolo.

### 2.2 Le variabili, una per una

«Nome in PermessiPagine» = la chiave con cui compare nelle regole (`PAGINE`) o in `$permessi_pannello`. «Voci» = in quante regole di `PAGINE` compare. «Pagine» = file di `admin/` (escluso `admin_header.php`) che leggono la variabile con `global`/direttamente.

| Variabile (riga) | Regola a parole | Come si calcola | Nome in PermessiPagine · voci · pagine |
|---|---|---|---|
| `$is_full_admin` (44) | Amministratore globale | `ruolo_id === 1` oppure `'1'` in `ruoli_secondari`, dall'utente letto dal DB | `is_full_admin` · 19 · 29 (+ 7 file fuori da `admin/` che lo ricalcolano da sessione: `inc/area/lavoro.php`, `inc/area/messaggi.php`, `area_personale.php`, `checkin.php`, `documento_tutorato.php`, `la_mia_disponibilita.php`, `middleware.php`) |
| `$puo_fsl` (121) | Lavora in tutta la FSL | admin **o** `ha_modulo('fsl')` (che vale anche per Orientamento e per il perimetro «fsl») | **nessuno** (solo ingrediente dei due sotto e di `MenuModuli`) · 0 · 3 |
| `$puo_fsl_convenzioni` (123) | Lavora le convenzioni delle scuole | `puo_fsl` **o** `ha_ambito('fsl_convenzioni')` **o** membro dell'ufficio `convenzioni_fsl` (`ServizioUffici::utenteMembro`) | `puo_fsl_convenzioni` · 5 · 8 |
| `$puo_fsl_scuole` (124) | Anagrafe delle scuole | `puo_fsl` **o** `ha_ambito('fsl_scuole')` | `puo_fsl_scuole` · 1 · 2 |
| `$puo_didattica_tutto` (125) | Tutta la Didattica | admin **o** `ha_modulo('didattica')` **o** `utente_operatore_ufficio($u)` (operatore di un ufficio qualsiasi) | `puo_didattica_tutto` · 4 · 11 |
| `$consigli_referente` (127) | Quali consigli di corso segue (**array, non booleano**) | `[]` se `puo_didattica_tutto`, altrimenti `consigli_referente($conn, $u)` | **nessuno** (alimenta `puo_didattica`) · 0 · 6 |
| `$puo_didattica` (128) | Didattica, anche solo per i propri consigli | `puo_didattica_tutto` **o** `consigli_referente` non vuoto | `puo_didattica` · 1 · 3 |
| `$puo_conv_tesi` (130) | Convenzioni di tesi | `puo_didattica_tutto` **o** `ServizioConvenzioniTesi::utenteDellUfficio` | `puo_conv_tesi` · 2 · 5 |
| `$puo_tir_motorie` (132) | Tirocini di Scienze motorie | `puo_didattica_tutto` **o** ufficio `tirocini_motorie` | `puo_tir_motorie` · 1 · 1 |
| `$puo_tutorato` (133) | Tutorato | admin **o** `ha_modulo('didattica')` **o** `utente_operatore_ufficio($u,'bandi')` (nota: **non** coincide con `puo_didattica_tutto`: qui conta solo l'ufficio `bandi`) | `puo_tutorato` · 2 · 4 |
| `$puo_forms` (135) | Modulo MyForms per intero | admin **o** `ha_modulo('forms')` | **nessuno** · 0 · 1 |
| `$puo_forms_cogestore` (138) | Co-gestore di almeno un form | `!puo_forms` e `FormRepository::haGestiti($uid)` | **nessuno** · 0 · 0 (alimenta `puo_forms_vedere`) |
| `$puo_forms_vedere` (139) | Entra in MyForms | `puo_forms` **o** `puo_forms_cogestore` | `puo_forms_vedere` · 3 · 3 |
| `$perm_amm` (143) | (oggetto `PermessiAmministrazione`, non un permesso) | `App::per($conn)->get(...)` | — · — · 4 |
| `$puo_amministrazione` (144) | Tutto il modulo Amministrazione | `PermessiAmministrazione::puoTutto($u)` | **nessuno** nelle regole (c'è in `MenuModuli`) · 0 · 2 |
| `$puo_pubblicazioni` (145) | Richieste di pubblicazione | `PermessiAmministrazione::puoPubblicazioni($u)` | `puo_pubblicazioni` · 1 · 2 |
| `$puo_amm_entrare` (146) | Entra in Amministrazione | `PermessiAmministrazione::puoEntrare($u)` (= tutto **o** pubblicazioni) | `puo_amm_entrare` · 1 · 1 |
| `$puo_assistenza` (148) | Risponde alle chat | `OperatoriAssistenza::eOperatore($u)` (admin o ufficio «Assistenza») | `puo_assistenza` · 3 · 5 |
| `$can_manage_eventi` (150) | Gestisce le attività dell'area corrente | vedi sotto «blocco area» | `can_manage_eventi` · 3 · 4 |
| `$can_manage_iscritti` (150) | Gestisce gli iscritti dell'area | idem | `can_manage_iscritti` · 6 · 9 |
| `$can_manage_sondaggi` (150) | Gestisce i sondaggi | idem | `can_manage_sondaggi` · 1 · 2 |
| `$can_manage_form` (150) | Gestisce il costruttore di moduli | idem | `can_manage_form` · 1 · 1 |
| `$can_manage_settings` (150) | Impostazioni dell'area | idem, ma **non** concesso a chi ha solo attività/perimetri | `can_manage_settings` · 1 · 4 |
| `$is_area_manager` (151) | Gestisce tutta l'area | admin; oppure gestore dell'area (`ids_gestori_da_campi(...)`) o `area_nel_modulo_utente` | `is_area_manager` · 2 · 5 |
| `$puo_creare_eventi` (152) | Può creare eventi nell'area | admin / gestore → sì; altrimenti `ha_ambito('eventi', area)` | **nessuno** · 0 · 1 |
| `$puo_creare_progetti` (152) | Può creare progetti | admin / gestore → sì; altrimenti `ha_ambito('progetti', area)` | **nessuno** · 0 · 1 |
| `$allowed_events_ids` (153) | ID delle attività che gestisce (**array**) | attività assegnate una a una (`gestoriAttivitaAreaConTitolo`) ∪ `attivita_da_ambiti` | **nessuno** · 0 · 1 |
| `$sql_filtro_eventi_rbac` (256) | Condizione SQL da accodare alle query sulle attività (**stringa**) | `''` per admin/gestore d'area; `AND e.id IN (…)` o `AND e.id = -1` | **nessuno** · 0 · 11 (+1 in `inc/`) |
| — (680) | Rapporti di Orientamento | admin **o** `ha_modulo('orientamento')` — *solo closure dentro `$permessi_pannello` e `$perm_menu`* | `puo_orientamento` · 1 · — |
| — (678) | Ha almeno un modulo o un'area | admin **o** `$moduli_utente` **o** `$pagine_disponibili` non vuoti | `ha_sezioni` · 5 · — |
| — (681) | Ha caselle di messaggi | closure su `CasellaMessaggi::aree($uid, $is_full_admin)` | `ha_caselle` · 1 · — |

**Il «blocco area» (righe 150-175)**, perché è la parte meno ovvia: parte da `can_manage_* = is_area_manager = puo_creare_* = is_full_admin`. Se l'utente non è admin e c'è un'area corrente: (a) gestore dell'area o abilitato al modulo a cui l'area appartiene → tutti e cinque i `can_manage_*`, `is_area_manager` e `puo_creare_*` diventano veri; (b) altrimenti si calcolano `allowed_events_ids` e i due `puo_creare_*` dai perimetri, e se uno dei tre è non vuoto si accendono `eventi`, `iscritti`, `sondaggi`, `form` (ma **non** `settings` né `is_area_manager`).

### 2.3 I due elenchi richiesti

**Nomi usati da `PermessiPagine` che NON hanno una variabile `$…` corrispondente — 3:**

- `puo_orientamento` — esiste solo come closure in `$permessi_pannello` (riga 680) e in `$perm_menu` (riga 220);
- `ha_caselle` — solo closure in `$permessi_pannello` (riga 681);
- `ha_sezioni` — solo chiave booleana in `$permessi_pannello` (riga 678).

**Non è una pagina che si chiude in silenzio**: tutti e tre sono definiti come chiavi di `$permessi_pannello`, che è l'unica cosa che `consente()` legge. Il rischio vero è un altro: `consente()` fa `$permessi[$nome] ?? false`, quindi **un nome scritto male in `PAGINE` chiuderebbe la pagina a tutti senza un errore**. Lo protegge `PermessiPagineTest::testLeRegoleUsanoSoloPermessiCheAdminHeaderCalcola` (riga 73), che però non confronta con `Permessi` (non c'è ancora): **fa un'espressione regolare sul testo di `admin_header.php`** (`'/\'([a-z_]+)\' => /` dentro `$permessi_pannello = [ … \n];`). Oggi tutti i nomi tornano; nessuna pagina è chiusa per errore. Una `Permessi::elenco()` renderebbe quella prova un confronto vero invece di una lettura del sorgente.

**Variabili che nessuna regola di `PAGINE` usa — 9 (più gli ausiliari):**
`$puo_fsl` (è l'**unica chiave di `$permessi_pannello` che nessuna regola chiede**), `$puo_forms`, `$puo_forms_cogestore`, `$puo_amministrazione`, `$puo_creare_eventi`, `$puo_creare_progetti`, `$allowed_events_ids`, `$consigli_referente`, `$sql_filtro_eventi_rbac`. Più `$uid_rbac`, `$u_id_curr`, `$perm_amm` (non sono permessi). Non sono inutili: alimentano altri permessi o le pagine (`$sql_filtro_eventi_rbac` è in 11 pagine, `$allowed_events_ids` serve a calcolarlo), e `puo_amministrazione`, `puo_fsl`, `puo_forms…` sono usate dal **menu** (`MenuModuli`). Vuol dire solo che `Permessi` ha **due famiglie**: i permessi-pagina (21 nomi + `'*'`) e i dati di perimetro (liste e SQL), e solo la prima va in `elenco()`.

### 2.4 Quanti file in `PermessiPagine`

| Elenco | File | Significato |
|---|---|---|
| `PAGINE` | **57** | pagine d'ingresso, con la regola |
| `SCHEDE` | **14** | incluse da un'altra pagina (guardia `define`) |
| `FUORI` | **9** | non passano da `admin_header.php` (cron, AJAX, scaricamenti) |
| **totale** | **80** | = `admin/*.php`, uno per uno (il piano ne contava 83 prima di S5) |

Distribuzione dei nomi nelle 57 regole: `is_full_admin` 19 · `can_manage_iscritti` 6 · `puo_fsl_convenzioni` 5 · `ha_sezioni` 5 · `puo_didattica_tutto` 4 · `can_manage_eventi` 3 · `puo_assistenza` 3 · `puo_forms_vedere` 3 · `is_area_manager` 2 · `puo_conv_tesi` 2 · `puo_tutorato` 2 · gli altri 1; più `'*'` (1 pagina: `stampa_qr_aula.php`) e la scorciatoia `SEZIONI` = `['ha_sezioni']` che fa rimandare all'area personale invece del 403.

**Fuori da `admin/`** il portale ha ancora le sue regole di accesso (radice): `middleware.php` (ricalcola `$is_full_admin` e `$is_gestore` dalla **sessione**, non dal DB come `admin_header.php`), `master_template.php` (`$is_admin_globale`, `$is_gestore_o_admin`, anch'essa da sessione). Sono tre modi di rispondere alla stessa domanda «è amministratore?»: `Permessi` dovrà sceglierne uno e dire quale.

---

## 3. Le risposte che una pagina dà oggi

Ogni esempio è una riga vera, verificata.

| # | Risposta | Esempio (file:riga) | Note |
|---|---|---|---|
| 1 | **HTML a pagina intera** | `corsi.php:30` (`require_once 'header.php'` … `footer.php`); `admin/scuole.php:6` / `:225` | di gran lunga la più comune; `header.php` e `admin_header.php` aprono, i footer chiudono |
| 2 | **HTML frammento/in-pagina** (messaggi dentro il layout) | `master_template.php:49-57` (manutenzione: `header.php`, `echo '<div…'`, `footer.php`, `exit`) | non è una pagina diversa, è un'uscita anticipata |
| 3 | **Rimando 303** `vai_a()` | `admin/scuole.php:43` (via `admin_redirect`); definita in `inc/sistema.php:50` (303, solo indirizzi interni via `RitornoInterno`, svuota **tutti** i buffer, ripiega su link «Continua» + `<script nonce>` se gli header sono già partiti) | in **44 file** |
| 4 | **Rimando `header('Location…')` a mano** | `admin/admin_header.php:26` (SSO), `:181`, `:188`, `:196`, `:203`, `:229`; `middleware.php:12`; `guida_studente.php:4` (**301**) | 100 occorrenze in tutto; gli unici 301 veri sono `guida_studente.php:4` e il router locale |
| 5 | **JSON** | `cerca_scuole.php:8` (`Content-Type: application/json`); `admin/cerca_personale.php:6` (+ `Cache-Control: no-store`) | 23 punti; spesso con `X-Robots-Tag: noindex` |
| 6 | **File scaricabile temporaneo** (`Download::invia`) | `src/Infrastructure/Documenti/Download.php:10` (butta i buffer, `Content-Disposition: attachment`, `Content-Length`, `nosniff`, `readfile`, `unlink`, `exit`) | usata da `FogliEsportabili`, `TabellaEsportabile`, `inc/esporta.php:9` (`invia_file_scaricabile`) e 4 pagine: 10 usi |
| 7 | **Excel .xlsx** | `admin/tirocini.php:24-33` (header a mano + `readfile` + `File::togli`); `risorse_responsabile.php:17`; `src/Attestati/ModelloElenco.php:28` | `Excel::crea()` fabbrica il file, poi due strade: `Download::invia` o header a mano |
| 8 | **Excel «finto» .xls (HTML)** | `admin/sondaggi.php:68` (`application/vnd.ms-excel`, `Pragma: no-cache`, `echo` di una tabella HTML) | caso a sé: nessun file su disco, il corpo è in memoria |
| 9 | **Word .docx** | `admin/didattica.php:119` (`invia_file_scaricabile` con il MIME del docx) | |
| 10 | **CSV** | `src/Infrastructure/Documenti/TabellaEsportabile.php:122`, `FogliEsportabili.php:101`; `src/Attestati/ModelloElenco.php:42` | |
| 11 | **PDF inline** (anteprima) | `admin/tutorato.php:50`, `:56`; `allegato_pratica.php:15`; `admin/convenzioni_tesi.php:26` | `Content-Disposition: inline` |
| 12 | **PDF attachment** | `convenzione_tesi.php:37`; `documento_scuola.php:23` (via `Download`) | |
| 13 | **PDF senza Disposition** (aperto dal browser) | `incarico.php:16`, `firma_incarico.php:28`, `verbali.php:38`, `registro_tutorato.php:43`, `convenzione_online.php:52` | 23 punti con `application/pdf`; quasi tutti `readfile($percorso)` o `echo` di una stringa generata |
| 14 | **File dal disco con tipo ricavato** | `comunicazione_file.php:15`, `didacticus_file.php:15`, `admin/didattica.php:80` (tabella estensione → MIME), `admin/forms_allegato.php:45` | `readfile` di un percorso da DB |
| 15 | **ZIP** | `firme.php:33`, `admin/forms_allegato.php:32` (via `Download`) | |
| 16 | **ICS (calendario)** | `calendario_ics.php:24`, `agenda_ics.php:13`; anche `genera_ics.php`, `risorsa_ics.php` | `text/calendar`, 4 tipi di file |
| 17 | **XML / RSS / testo** | `sitemap.php:18` (`application/xml`), `comunicazioni_feed.php:14` (`application/rss+xml`), `robots.php:8` (`text/plain`) | `.htaccess` riga 55-56 li espone come `sitemap.xml`/`robots.txt` |
| 18 | **403 inline** `nega_accesso()` | `inc/eventi_progetti.php:26` → `RispostaHttp::negaAccesso()` (`src/Auth/RispostaHttp.php:11`: 403 + `die('Accesso negato.')`) | 38 file |
| 19 | **403 nel layout del pannello** | `admin/admin_header.php:661-666` (`nega_accesso_pannello()`: 403, alert Bootstrap, footer, `exit`) | 22 file; è l'**unico** 403 con la grafica |
| 20 | **403 con messaggio** a mano | `admin/amministrazione_campi.php:14` (guardia `define`), `admin/didattica_accessi.php:4`, `admin/iscritti.php:120` (`nega_accesso_isc()`) | 33 `http_response_code(403)` in tutto |
| 21 | **403 JSON** | `admin/cerca_personale.php:20` (`{'errore':'Accesso non consentito'}`) | il JSON ha il suo 403 |
| 22 | **403 CSRF** | `src/Auth/RispostaHttp.php:19` (`csrfNonValido()`, chiamata da `csrf_verify()` in `inc/sistema.php:37`) | testo diverso da `negaAccesso` |
| 23 | **404 testo** | `admin/convenzione_file.php:12`, `admin/tutorato.php:60`, `allegato_pratica.php:7` (`exit('File non trovato.')`) | 30 `http_response_code(404)` |
| 24 | **404 HTML con layout** | `area.php:22-31` (`header("HTTP/1.0 404 Not Found")` + `header.php` + alert + `footer.php`) | l'unico 404 pagina vero; **non c'è `404.php` né `ErrorDocument`** in `.htaccess` |
| 25 | **404 vuoto** | `admin/utenti.php:418` (`http_response_code(404); exit;`) | |
| 26 | **204** | `csp_report.php:10` (`http_response_code(204)` all'inizio; poi `exit` senza corpo) | l'unico |
| 27 | **429** (limite di richieste) | `cerca_scuole.php:13` (`'[]'`), `cerca_insegnamenti.php:16` (JSON), `risorsa_ics.php:9` (testo), `saml_login.php:8`, `stampa_ricevuta.php:24` | 5 forme diverse di corpo |
| 28 | **400 / 401** | `attestati_gruppo.php:10`, `genera_ics.php:7` (400 testo); `cerca_insegnamenti.php:15` (401 JSON); `accesso_richiesto.php:13` (401 HTML) | |
| 29 | **500 / 503** | `convenzione_precompilata.php:18` (500 testo); `config.php:66` e `:100` (503: copia di prova sul NAS; DB non raggiungibile) | |

**Per dimensionare `Risposta`**: servono (a) un corpo in memoria con tipo e codice (HTML, JSON, testo, XML, ICS, PDF generato, CSV, `.xls` HTML); (b) un file su disco, **da cancellare dopo** (`Download::invia`) o **da lasciare** (`readfile` di un allegato), con `inline`/`attachment`; (c) un rimando (303 quasi sempre, 301 raro); (d) un'uscita senza corpo (204); (e) un'uscita con codice 4xx/5xx e tre varianti di corpo (testo, JSON, HTML con layout). Se la classe non ha **«file da cancellare»** e **«HTML con layout»** (il 403 del pannello e il 404 di `area.php`), le pagine più delicate (firma, verbali, esportazioni) restano fuori.

**Cosa sparisce senza `ob_end_clean`**: quasi tutte le risposte-file cominciano con `while (ob_get_level() > 0) ob_end_clean();` (76 punti in totale). Funziona solo perché `config.php` ha aperto il buffer; vedi 4.1.

---

## 4. I punti delicati da non rompere

### 4.1 `ob_start()` di `config.php` e la fascia della copia di prova

- `config.php:3` carica `inc/ambiente.php`; **righe 8-13**: se `AMBIENTE_PROVA`, `ob_start([\App\Core\FasciaProva::class, 'callback'])` (+ `X-Robots-Tag: noindex, nofollow`), altrimenti `ob_start()` semplice. Sta **prima** di sessione, CSP e `ini_set`.
- `FasciaProva::callback()` (`src/Core/FasciaProva.php:48`) gira a fine richiesta (o a ogni svuotamento) e chiama `inietta($uscita, $emailProva, headers_list())`: **legge gli header già inviati**; se c'è un `Content-Type:` che non è `text/html` lascia il corpo com'è (JSON, CSV, PDF…), se non c'è `<!doctype`/`<html` nei primi 3000 byte non tocca, altrimenti mette la fascia dopo il primo `<body…>`.
- **Conseguenze per il nucleo**:
  1. `Risposta` deve impostare gli header (`header()`) **prima** che il buffer si chiuda e **non** scrivere il corpo su un buffer proprio che salti `ob_start`: altrimenti `headers_list()` non mostra il `Content-Type` e la fascia finisce dentro un JSON o un PDF.
  2. Una risposta HTML senza `Content-Type` esplicito (come tutte le pagine di oggi) **riceve** la fascia: `Risposta::html()` può non impostarlo, o impostarlo `text/html` — non `application/xhtml+xml` né altro.
  3. `vai_a()` e `Download::invia()` svuotano **tutti** i buffer; «nella copia di prova anche quello della fascia, che quando viene scartato non stampa niente» (commento di `inc/sistema.php`). `Risposta::vai()` e `::scaricabile()` devono fare lo stesso.
  4. `config.php:62-70`: un 503 in copia di prova è `http_response_code(503)` + `Content-Type: text/plain` + `die`: non va confuso con una `Risposta` (avviene prima di tutto il resto).
  5. `inc/ui.php:236-238` usa `ob_start()/ob_get_clean()` per catturare il campo CSRF: nidifica sopra quello di `config.php`, quindi **la profondità del buffer non è garantita 1**: mai assumere `ob_get_level() === 1`.
  6. I test non passano da `config.php`: `Risposta::emetti()` deve essere l'unico punto che chiama `header()`/`http_response_code()` perché un test legga `codice()` e `corpo()` senza output.

### 4.2 `csp_nonce()`: dove si emette e in che forma

- Definizione: `inc/csp.php:6-10` (una per richiesta, 16 byte casuali in base64, `static`), caricata da `inc/ambiente.php` e da `src/autoload.php`. L'intestazione CSP la scrive `config.php:52` (`'nonce-…'` in `script-src`) con una variabile `$_csp_script`.
- **138 occorrenze in 85 file** (esclusi tests/strumenti): **admin/ 37 · radice 27 · inc/ 16 · src/ 5**.
- **Tre forme** dell'attributo:
  1. `<script nonce="<?php echo csp_nonce(); ?>">` — **123** volte, nei file di vista;
  2. `'<script nonce="' . csp_nonce() . '">…'` — 8 volte, dentro funzioni PHP che restituiscono HTML (`inc/ui.php`, `vai_a()`, `ui_avviso_modifiche`, bozze, tema scuro, QR, SRI di ripiego…);
  3. `'<script nonce="' . csp_nonce() . "\">…"` — 1 volta, con stringa a virgolette doppie (il tema scuro in `inc/ui.php`).
- Mai `<?= csp_nonce() ?>`: **la forma standard è `<?php echo csp_nonce(); ?>`**. Una `Vista` che emette script deve produrre esattamente un `nonce="…"` per `<script>` in linea; i `<script src>` e i `type="application/json"` non lo richiedono (restano 2+2 casi noti, vedi `controllo_sito_21112026.md`).
- `on*=` in linea fuori da `install.php` e dai dati di prova: **0**. Resta ammesso `style="…"` (`'unsafe-inline'` per gli stili resta, scelta già presa).
- Gli `<style>` in linea (`admin/scuole.php:55` ecc.) non portano nonce: la CSP degli stili è diversa.

### 4.3 Gli attributi `data-*` che la `Vista` dovrà saper produrre

**`assets/js/azioni.js`** (130 righe, un ascoltatore per tipo di evento sul documento). Gli attributi sono:

| Attributo | Evento | Effetto |
|---|---|---|
| `data-on-click` · `data-on-change` · `data-on-input` · `data-on-keyup` = `"nomeFunzione"` + `data-args='["x","@this","@value","@#id","@event"]'` | click/change/input/keyup | chiama una funzione **globale** (`window[nome]`); `@this` = elemento, `@value` = valore, `@#id` = valore dell'elemento con quell'id, `@event` = l'evento |
| `data-auto-submit` | change | invia il form del campo |
| `data-invia-form="id"` | change | invia il form con quell'id |
| `data-vai="indirizzo"` | change | vai all'indirizzo (`__V__` = valore, o valore in coda) |
| `data-mostra-se-spuntato="#id"` | change | mostra/nasconde l'elemento (`d-none`) |
| `data-mostra-valore="#id"` | input | copia il valore nel testo dell'elemento |
| `data-togli-spunta="#id"` | input | toglie la spunta all'elemento |
| `data-maiuscolo` | input | il valore diventa maiuscolo |
| `data-stampa` | click | `window.print()` |
| `data-chiudi-finestra` | click | `window.close()` |
| `data-seleziona` | click | seleziona il testo del campo |
| `data-copia-da="#id"` | click | copia il valore del campo, il pulsante scrive «Copiato» |
| `data-alert="testo"` | click | `window.alert` |
| `data-href-base` + `data-href-valore="#id"` | click | il link diventa base + valore del campo |
| `data-tinymce-salva` | submit/click | `tinymce.triggerSave()` |
| `data-no-invio` | submit | `preventDefault()` |
| `data-ferma-click` | avvio | `stopPropagation` sul click |
| `data-blocca-menu` | contextmenu/dragstart | bloccati |
| `data-nascondi-errore` | error (img) | nasconde l'immagine |

**`assets/js/conferma.js`** (150 righe):

| Attributo | Effetto |
|---|---|
| `data-conferma="Domanda"` | conferma semplice (Annulla / Sì, procedi); si mette su link, pulsante o `<form>` |
| `data-conferma-scrivi="parola"` | eliminazione definitiva: il pulsante si accende solo scrivendo la parola; la domanda resta in `data-conferma` |
| `data-conferma-livello="pericolo"` | rosso anche senza «elimin…» nel testo (di per sé il rosso scatta con `elimin|cancell|definitiv|irrev|rimuov|svuot`) |
| `data-confirm` · `data-confirm-level` | nomi **vecchi**, ancora funzionanti |
| `data-bs-dismiss` | di Bootstrap, usato dalla finestra stessa |

L'API da JS è `window.pnConferma(testo, callback, {scrivi, pericolo})`; se Bootstrap JS non c'è ripiega su `confirm()`/`prompt()`. `data-conferma` è presente in **23** file PHP.

**Perché conta per la `Vista`**: questi attributi non vengono validati da nessuno. Se la `Vista` ne scrive uno con un nome sbagliato, **non c'è un errore: il pulsante non fa niente**. Gli argomenti di `data-args` sono JSON dentro un attributo HTML: serve `htmlspecialchars(json_encode(...), ENT_QUOTES)` e il valore va tra apici singoli o con `&quot;`. Le funzioni chiamate da `data-on-*` devono esistere come globali.

### 4.4 I parziali di `inc/area_pubblica/` e le classi di `src/Portale/Area` (passo S14)

**`master_template.php`: 2448 → 278 righe** (il piano diceva 255: ha ripreso qualche riga dopo S14). Contiene:
- intestazione e `ini_set`, `config.php` + `functions.php` + `sync_sso_user` (riga 16), configurazione dell'area da `PaginaAreaRepository::perSlug` (31), controlli di visibilità/manutenzione e «gestore o admin» (da 43; la pagina di manutenzione, 49-57), poi azioni e preparazione dei dati fino a `header.php` (193);
- poi una **catena di `require` dei parziali**: `strumenti_elenco.php` (165), `funzioni.php` (190), `stile_pagina.php` (194), `modale_programma.php` (205), `scheda_progetto.php` (210) / `scheda_evento.php` (215) o **uno solo** dei sette `layout_*.php` (220-250), `barra_gestore.php` (253), `coda_pagina.php` (278, insieme a `footer.php`).

**Come sono fatti i parziali** (18 file, da 23 a 221 righe non vuote, circa 2.150 in tutto):
1. **Nessun `function` di impalcatura e nessun `exit`**: sono vista pura, con PHP solo per `foreach`/`if`/`echo`.
2. **Ognuno apre con un commento che elenca le variabili che usa** («Variabili che usa (arrivano da master_template.php o dalla funzione che lo include): `$page_cfg`, `$eventi_per_data`, …»). Si includono con `require` **nello stesso ambito** della pagina: leggono variabili sciolte, non un oggetto `$d`.
3. L'escape è `htmlspecialchars(...)` per esteso o la lambda `$h` della pagina madre.
4. Gli script portano `nonce="<?php echo csp_nonce(); ?>"` e le azioni sono `data-on-*`.
5. Gli `<style>` locali ci sono ancora (`stile_pagina.php`), con il colore dell'area passato come variabile CSS `--c-area`.

**Le classi** (`src/Portale/Area/`): la pagina non decide nulla, **delega**:
- `AzioniAreaPubblica` (`__construct` con servizi; `haUnAzione(string $metodo, array $post): bool` statica; `esegui(array $post, array $get, array $files, array $c): array`; `rimandoProgramma(...)` statica) — **riceve gli array superglobali come argomenti** e restituisce un array con l'esito: è già, nei fatti, una «Richiesta» senza la classe;
- `EsitoPrenotazioneArea` (`da(array $get, array &$sessione, array $pagina, string $baseUrl): array`) — idem, e **prende la sessione per riferimento**;
- più `AreeGestite`, `FiltroProfilo`, `PassiStudente`, `Profili*`, `BarraTelefono`, `CatalogoRiquadri`, `PostazioniLavoro`, `RiquadriRepository` per l'area personale.

**Cosa ne segue per la `Vista` del nucleo** (descrizione, non proposta): il modello collaudato su 38 pagine è «parziale = file PHP in cartella, variabili dichiarate in testa, escape esplicito, script con nonce, azioni con `data-on-*`, logica nelle classi che prendono array e restituiscono array». La `Vista` che il piano descrive (oggetto `$d` con escape automatico) **si discosta** da questo su un punto: nei parziali di oggi le variabili sono sciolte e l'escape è manuale. È la differenza da tenere presente quando si decide come convertire: un parziale vecchio non gira con `$d` senza essere riscritto.

---

## 5. Cose emerse che il piano non diceva

1. **`admin_header.php` emette l'HTML prima di controllare il permesso** (guardia alla riga 683, dopo ~400 righe di layout). Per questo il 403 del pannello è un alert dentro la pagina (200→403 impostato a metà) e il rimando all'area personale passa da `vai_a()` che butta i buffer. Con un `Nucleo` che decide **prima** di costruire la pagina, il 403 diventa una risposta vera: è un miglioramento, ma `Risposta::negata()` deve poter produrre **sia** il testo nudo **sia** l'alert nel layout del pannello.
2. **Il permesso dipende dall'area corrente** (§2.1): `Permessi` non è una funzione dell'utente.
3. **«È amministratore?» ha tre implementazioni** (DB in `admin_header.php`, sessione in `middleware.php` e `master_template.php`) oltre a 7 file con la propria.
4. **La prova di `PermessiPagine` legge il sorgente di `admin_header.php` con una regex**: con `Permessi::elenco()` diventerebbe un confronto vero. Ma finché esiste, non spostare `$permessi_pannello` senza aggiornare la prova.
5. **Il 404 di `area.php` è l'unico 404 con layout**; non esiste `404.php`. Gli slug sconosciuti passano per la regola 4 di `.htaccess` (righe 83-91) e arrivano ad `area.php`: il nucleo, per non rompere questo, deve infilarsi **prima** di `area.php` (come dice la Fase 4) e **non** può rispondere 404 per gli slug che sono aree.
6. **Due «403 CSRF» e «403 accesso» hanno testi diversi** (`RispostaHttp::csrfNonValido` / `negaAccesso`): se `Risposta::negata()` li unifica, cambia il testo che l'utente vede.
7. I numeri del piano per `nega_accesso` (55) e `csrf_verify` (93) non tornano con una ricerca stretta (38 e 87): conviene fissare **una** definizione di conteggio in `strumenti/avanzamento_nucleo.php`.
