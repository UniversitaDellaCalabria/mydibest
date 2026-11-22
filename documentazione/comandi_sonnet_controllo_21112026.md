# Correzione e restyling del portale — mappa a step e comandi per Sonnet (21/11/2026)

Nasce dal rapporto `documentazione/controllo_sito_21112026.md` (le sigle V1…V10 vengono da lì).
**Metodo:** come per MyForms e Amministrazione.
- **Una sessione Sonnet nuova per ogni passo**, in locale su questa cartella.
- In ogni sessione si incolla prima il **TESTO A**, poi il blocco del passo.
- Alla fine di ogni passo il resoconto di Sonnet si porta in **questa chat (Opus, revisione)**. Opus risponde con il verdetto e, se serve, con le righe da aggiungere al passo successivo.
- **Niente commit durante i passi.** Alla fine c'è **un commit unico** (lo fa Opus), poi il pacchetto: prima `mydibest_pp`, poi `mydibest`.

---

## Mappa

| Passo | Cosa | Voci del rapporto | Peso |
|-------|------|-------------------|------|
| **0** | Controlli a mano (tu): dati su Drive, crontab e `.env` del server | V4, V1 | 15 min |
| **S1** | Copia locale su `/mydibest/` con gli stili | V9 | piccolo |
| **S2** | Indirizzo del sito unico (email dei cron, link, QR) | V1, V3 | medio |
| **S3** | Piccole correzioni di sicurezza | V2, CSP, `install.php`, messaggi area personale | piccolo |
| **S4** | Reindirizzamenti lato server invece che in JavaScript | V8 | medio |
| **S5** | Pannello «negato se non previsto» + 403 | V6, V7 | medio |
| **S6** | Tavolozza unica di colori (variabili CSS) + tema scuro del pannello | restyling | medio |
| **S7** | Stili in linea → classi (primo giro) e caratteri piccoli | restyling | grande |
| **S8** | Accessibilità + dichiarazione AgID | accessibilità | medio |
| **S9** | Menu del pannello in 10 macroaree | pannello | grande |
| **S10** | Percorso in alto, ricerca Ctrl+K, conferme uniche | pannello | medio |
| **S11** | Componente «a passi» + «Cosa devo fare» in ogni modulo | pannello | grande |
| **S12** | Pagine con troppi pulsanti → «Altro ▾» e pagine vuote che spiegano | pannello | medio |
| **S13** | Area personale: prossimo passo, avanzamento, barra sul telefono | area personale | grande |
| **S14** | `master_template.php` diviso in parti | back-end | grande |
| **S15** | Sistema: versione caricata e controllo di salute | back-end | medio |
| **S16** | TinyMCE 7 e riduzione delle `@` | back-end | piccolo |
| **S17** | CSP senza `'unsafe-inline'` per gli script (nonce) | sicurezza | grande |
| **F** | Giro completo delle prove e messaggio di commit | — | medio |
| **G** | Commit unico (Opus), pacchetto, carico in prova e poi in produzione | — | — |

**Scelte già fatte da Opus** (così Sonnet non si ferma; dimmi se ne vuoi cambiare una):
- **V5 (incorporamento):** il portale resta **non incorporabile** (`frame-ancestors 'self'` + `X-Frame-Options`). La riga di Apache con `elearning.unical.it` serve ai video di Nextcloud e non si tocca.
- **Codici di prenotazione:** il formato resta com'è (QR e link già stampati). Si aggiunge solo il limite di tentativi.
- **Macroaree:** sono **10, non 9**, perché esiste già il modulo «Prenotazioni e risorse» (calendari), che resta.
- **Navigazione `?tab=`:** gli indirizzi restano (sono nelle email e nei segnalibri). Cambia l'aspetto: percorso in alto e stesso stile delle pagine.
- **`'unsafe-inline'` per gli stili:** resta finché gli stili in linea non sono finiti (S7 è solo il primo giro). Per gli script si toglie in S17.
- **S14 e S17 sono i passi più pesanti.** Se a metà diventano rischiosi, Opus può fermarli senza danno per gli altri.

---

## Passo 0 — Controlli a mano (li fai tu, prima di S2)

1. **Dati su Google Drive (V4).** Apri `uploads/pratiche`, `uploads/convenzioni`, `uploads/incarichi` e `materiali/`.
   - **Dati di prova:** nessun problema.
   - **Dati veri** (studenti, convenzioni firmate): spostali fuori da Drive (es. `C:\progetti\mydibest_dati_locali\`).
   - Gli archivi `aggiornamento_*.zip/.tgz`, `mappa*.zip` e gli inventari `*.txt` della radice spostali in `C:\progetti\archivio_pacchetti\`.
2. **Crontab e `.env` del server (V1).** Sul server lancia:
   ```bash
   crontab -l | grep -E "cron_(background|attestati|reminders|backup)"
   ```
   ```bash
   sudo grep -E "^URL_SITO" /opt/lampp/htdocs/mydibest/.env /opt/lampp/htdocs/mydibest_pp/.env
   ```
   Incolla qui l'esito. Se i cron partono con `php …` (non con `curl …?key=`), V1 è un errore vero. In tutti e due i `.env` serve `URL_SITO` (`https://dibest2.unical.it/mydibest` e `…/mydibest_pp`).
   **Esito (21/11/2026).** Il crontab chiama con `curl …?key=`, quindi in produzione i link delle email di `cron_background` sono giusti. V1 resta un errore solo se un giorno si passa a `php …`, e S2 lo chiude comunque.
   **Scoperto:** `cron_attestati.php` (e `admin/cron_reminders.php` a sé) NON è nel crontab: gli attestati partono solo dal pulsante «Invia gli attestati ora» in Iscritti (i promemoria invece girano dentro `cron_background`). La CRON_KEY è di nuovo comparsa in chiaro: va cambiata al passo G.
3. *(facoltativo, V10)* PHP 8.4 anche in locale: se vuoi, ti preparo i passi a parte. Non blocca niente.

---

## TESTO A (da incollare sempre per primo)

```
Lavori sul portale MyDiBEST (PHP 8.2 in locale, 8.4 sul server; MariaDB) nella cartella corrente.
Leggi prima documentazione/controllo_sito_21112026.md (il rapporto) e documentazione/comandi_sonnet_controllo_21112026.md (la mappa): fai SOLO il passo indicato sotto.

Regole del progetto:
- Nessun ALTER/CREATE nelle pagine: le tabelle si cambiano solo in src/Core/Schema/Definizioni.php alzando Migrazioni::VERSIONE (oggi 133). Se il passo non lo chiede esplicitamente, non cambiare lo schema: fermati e chiedimi.
- Codice nuovo in src/<Modulo>/ (classi con declare(strict_types=1), registrate nel contenitore come le altre); funzioni-facciata in inc/ solo se servono alle pagine. Non ridefinire funzioni già presenti in functions.php o inc/. Riusa gli aiuti che esistono (inc/ui.php: ui_vuoto, ui_intestazione, ui_prossima_cosa, ui_stato; flash_set/flash_html; check_rate_limit; url_base_sito; TabellaEsportabile).
- File PHP senza BOM UTF-8, fine riga LF: modifica con lo strumento Edit, non con sed/Python/PowerShell che su Windows mettono CRLF; alla fine controlla con git ls-files --eol <file> (deve dire w/lf). Escape di tutto l'output (htmlspecialchars/h). Ogni POST con csrf_verify e controllo dei permessi lato server. Ogni azione via GET porta il parametro csrf.
- NIENTE cambi di comportamento non chiesti: gli indirizzi esistenti (link nelle email, QR stampati, segnalibri, ?tab=, ?vista=, ?sezione=) devono continuare a funzionare.
- Le email e i PDF usano colori scritti per esteso (#B30000…): lì le variabili CSS NON funzionano, non toccarli.
- Testi per gli utenti in italiano semplice, niente gergo tecnico.
- Prove MINIME, solo quelle essenziali: php -l sui file toccati + i (pochi) test nuovi chiesti dal passo + al massimo UNA cartella PHPUnit o UN file di prove di pagina che copre direttamente ciò che hai cambiato. Niente giro «prima», niente suite, niente altre cartelle, niente indagini su fallimenti che non riguardano il tuo cambio. Il giro completo si fa una sola volta al passo F. Browser solo se il passo lo chiede.
  PHPUnit: phar 9.6 in %TEMP%\phpunit96 con il bootstrap a parte (vedi tests/bootstrap.php) e MariaDB di XAMPP (mysqld --standalone) solo se il test lo richiede; se il phar non c'è chiedimi il permesso di scaricarlo.
- Controlla la sintassi con C:\xampp\php\php.exe -l su ogni file PHP toccato.
- MAI git stash (la cartella è su Google Drive). NON fare commit, push o altri comandi git che modificano qualcosa. AGGIUNGI in fondo a documentazione/commit_controllo.txt (crealo se manca) un paragrafo con il nome del passo e cosa è cambiato, in italiano, senza righe Co-Authored-By né riferimenti a Claude.
- Se una scelta non è scritta nel passo o nella mappa, fermati e chiedimi; non inventare.

Alla fine scrivimi un resoconto breve: file creati/modificati, cosa fa, prove (prima/dopo), dubbi aperti, cosa devo provare io nel browser.
```

---

## Passo S1 — Copia locale su /mydibest/ (V9)

```
Passo S1. Oggi la copia locale (bash strumenti/locale/avvia.sh, router strumenti/locale/router.php) serve il portale su /eventi/, ma App\Core\Sito::urlBase() (src/Core/Sito.php) non trova la radice dentro DOCUMENT_ROOT e ripiega su '/mydibest': CSS, Bootstrap e Font Awesome rispondono 404.
1. Porta il router e avvia.sh su /mydibest/ (come il server). /eventi/... e / rimandano con 301 a /mydibest/... (stesso percorso e query). Aggiorna i messaggi di avvia.sh, l'accesso di prova /__accesso e ogni '/eventi/' scritto nei file di strumenti/locale/. Aggiorna anche le prove di pagina in strumenti/prove/ che chiamano '/eventi/...' (es. strumenti/prove/oop/pagine_attestati.php): se sono tante, usa una sola costante o variabile per il percorso. Non lanciarle tutte: basta che una giri.
2. Rendi Sito::urlBase() più robusto: se la radice non sta dentro DOCUMENT_ROOT, ricava la cartella da SCRIPT_NAME (togliendo il percorso del file rispetto alla radice del sito, anche per le pagine in admin/) prima di ripiegare su '/mydibest'. Il caso da riga di comando con URL_SITO resta uguale.
3. Test in tests/Unit/Core/SitoTest.php (crealo se manca): radice dentro la document root, fuori (cartella collegata), pagina in admin/, riga di comando con e senza URL_SITO.
Fatto quando: avviando la copia locale, http://127.0.0.1:8080/mydibest/ e /mydibest/admin/ caricano CSS e JS senza 404 (questo controllalo nel browser: unica eccezione alla regola).
```

**Stato S1: approvato da Opus (21/11/2026).** Corretti a mano 6 file finiti con fine riga CRLF.

## Passo S2 — Indirizzo del sito unico (V1, V3)

```
Passo S2. Oggi molti link nelle email, nei QR e nei rimandi si costruiscono con $_SERVER['HTTP_HOST'] (che manda chi fa la richiesta) e, nei cron lanciati da riga di comando, diventano https://il-tuo-sito.unical.it/... o https://localhost/... .
1. In App\Core\Sito::urlBase():
   - se nel .env c'è URL_SITO valido (https?://host/...), usalo SEMPRE, dal web e da riga di comando;
   - se manca, usa HTTP_HOST solo se è tra i nomi ammessi: nuova voce .env HOST_AMMESSI, elenco separato da virgole, predefinito "dibest2.unical.it,localhost,127.0.0.1"; altrimenti 'dibest2.unical.it'. La copia locale deve continuare a funzionare (S1).
   Aggiungi HOST_AMMESSI e il commento su URL_SITO in .env.example.
   Situazione reale: sul server URL_SITO c'è in tutti e due i .env (mydibest e mydibest_pp); nella copia locale (.env.locale) NON c'è e non va aggiunto: lì deve valere il ripiego con HOST_AMMESSI + la cartella ricavata in S1 (cartellaDaScript).
2. Sostituisci con url_base_sito() (o Sito::urlBase()) ogni indirizzo costruito a mano con HTTP_HOST/PHP_SELF:
   cron_background.php:52, cron_attestati.php:34, admin/iscritti.php:214, admin/stampa_qr_aula.php:40, admin/sondaggi.php:157, stampa_ricevuta.php:35, master_template.php:101 e 128, miei_eventi.php:60 e 77, programma_fsl.php:46, esci.php:32.
   Cerca anche gli altri casi con: grep -rn "HTTP_HOST\|PHP_SELF" --include=*.php (esclusi tests/ e strumenti/) e dimmi quali hai lasciato e perché.
   Attenzione: alcuni punti usano dirname(dirname(PHP_SELF)) (pagina in admin/) e altri dirname(PHP_SELF): il risultato deve essere sempre la radice del portale.
   Per esci.php: il ritorno dopo il logout SSO deve restare identico a oggi su server e copia di prova.
3. Prove essenziali: aggiungi a tests/Unit/Core/SitoTest.php (di S1) i casi Host non ammesso (deve uscire il nome ammesso), URL_SITO presente dal web, URL_SITO assente con host ammesso. Più php -l sui file toccati. Nient'altro.
```

**Stato S2: approvato da Opus (21/11/2026).** Ora URL_SITO vale anche dal web: al passo G controllare che i valori siano esattamente `https://dibest2.unical.it/mydibest` e `…/mydibest_pp` (un vecchio `/didattica` manderebbe i moduli su un rimando 301 e i POST si perderebbero).

## Passo S3 — Piccole correzioni di sicurezza

```
Passo S3.
1. V2 – stampa_ricevuta.php: quando chi apre NON è il proprietario né admin/gestore e si entra solo con il codice, applica check_rate_limit($conn, 'ricevuta_codice', 20, 600) prima di cercare la prenotazione; oltre il limite: 429 e un messaggio semplice. Lo stesso in risorsa_ics.php ('risorsa_ics', 30, 600). Il formato dei codici NON cambia.
2. config.php: togli X-XSS-Protection; al Content-Security-Policy aggiungi "base-uri 'self'; object-src 'none';" (NON aggiungere form-action). Controlla che nessuna pagina usi <base> o <object>/<embed> (per i PDF in anteprima: verifica che si vedano ancora; se usano <embed>/<object>, dimmelo e lascia object-src com'è).
3. saml_login.php:48-50: nello <script> usa json_encode($redirect, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES) invece di htmlspecialchars; il link <a> resta con htmlspecialchars.
4. strumenti/crea_pacchetto.sh: escludi install.php dal pacchetto (il file resta nel repository).
5. Messaggi dell'area personale: oggi profilo.php, calendario.php e miei_eventi.php mettono HTML in $_SESSION['msg_area_pers'] e area_personale.php lo stampa così com'è. Passa a flash_set(testo, tipo) e flash_html() (più ui_prossima_cosa() dove serve), mantenendo testi e colori. Controlla anche App\Iscrizioni\Vista\MessaggiAreaPersonale e ServizioAreaPersonale (restituiscono HTML?): se restituiscono HTML, fai restituire testo + tipo. Togli la lettura di msg_area_pers solo quando nessuno lo scrive più (grep).
6. admin/iscritti.php:383: il pulsante «Invia gli attestati ora» apre ../cron_attestati.php con un GET senza csrf (è un'azione che manda email). Aggiungi &csrf=<token> al link e in cron_attestati.php, quando NON parte da riga di comando né con la chiave CRON_KEY (cioè dal pulsante di un admin loggato), verifica il csrf.
Prove essenziali: un test del limite della ricevuta (21ª richiesta → 429) e php -l sui file toccati. Nient'altro.
```

**Stato S3: approvato da Opus con una correzione** (fatta all'inizio di S4): il limite contava OGNI apertura per indirizzo IP, quindi in un laboratorio scolastico (tutti dietro lo stesso IP) dalla 21ª ricevuta si bloccava una classe intera. Si contano solo i codici sbagliati.

## Passo S4 — Reindirizzamenti lato server (V8)

```
Passo S4. Nel portale ci sono ~46 reindirizzamenti fatti stampando <script>window.location.replace('...')</script> (funzioni admin_redirect() locali in admin/*.php, chiusure tipo $torna/$redirect_d, echo diretti). Senza JavaScript la pagina resta bianca e serve 'unsafe-inline'.
1. In inc/sistema.php crea vai_a(string $url): never → se gli header non sono partiti: svuota tutti i buffer (while (ob_get_level() > 0) ob_end_clean();), header('Location: ' . $url, true, 303), exit. Se gli header sono già partiti: stampa un link «Continua» e lo script come oggi (ripiego). Accetta solo indirizzi relativi o del portale (riusa App\Auth\RitornoInterno se adatto).
   ATTENZIONE: nella copia di prova il primo buffer è quello della fascia (App\Core\FasciaProva): chiudendolo non deve stampare niente prima del redirect. Verificalo con un test o leggendo il codice.
2. Sostituisci tutti i casi (grep -rn "window.location.replace" --include=*.php, esclusi tests/ e strumenti/), compresi gli admin_redirect locali (lasciali come alias di vai_a o toglili). NON toccare i casi che servono davvero lato browser (es. dopo un download, cambio di pagina da JavaScript dopo un'azione AJAX, saml_login.php che evita la bfcache): elencameli nel resoconto con il motivo.
3. Prove: le prove di pagina di iscritti, eventi, sondaggi, menu, testata, utenti, sistema, form_builder, impostazioni_area, archivio, messaggi (quelle che esistono) devono restare verdi; se controllano il testo dello script, aggiornale al 303.
```

**Stato S4 (e correzione S3): approvati da Opus (21/11/2026).** Restano in JavaScript solo saml_login.php, scanner_studente.php e il ripiego di vai_a(), come previsto. 11 file admin/ hanno CRLF nella cartella di lavoro: git li normalizza al commit, si lasciano.

## Passo S5 — Pannello «negato se non previsto» (V6, V7)

```
Passo S5. Oggi admin/admin_header.php fa entrare qualunque utente autenticato e ogni pagina controlla da sola i permessi (tutte lo fanno, ma una pagina nuova potrebbe dimenticarlo). Inoltre l'«Accesso negato» risponde 200.
1. Funzione nega_accesso_pannello(string $messaggio = ''): never → http_response_code(403), avviso nello stile attuale (alert danger + icona fa-ban), admin_footer, exit. Sostituisci i blocchi «Accesso negato» delle pagine admin (grep "Accesso negato" admin/*.php) mantenendo i testi di ciascuna. Se esiste già nega_accesso(), valuta di riusarla e dimmelo.
2. Crea App\Portale\Pannello\PermessiPagine: per ogni pagina d'ingresso di admin/ (basename) indica chi può aprirla, usando le variabili già calcolate in admin_header.php ($is_full_admin, $puo_fsl, $puo_didattica, $puo_forms_vedere, $puo_amm_entrare, $can_manage_*, pagine dell'area…), cioè la STESSA regola che ogni pagina applica oggi (leggila pagina per pagina, non inventarla).
   In admin_header.php, dopo il calcolo dei permessi: se la pagina non è nella mappa → 403 per chi non è amministratore globale (e una riga in error_log); se è nella mappa e la regola dice no → 403.
   Le schede incluse (didattica_*.php, amministrazione_*.php, admin_footer.php, parziali) hanno già la guardia «define»: segnale nella mappa come «scheda» e non toccarle.
   I controlli dentro le singole pagine RESTANO (doppia protezione).
3. Test: un test statico che scorre admin/*.php e fallisce se una pagina d'ingresso non è nella mappa (così una pagina nuova senza regola si scopre subito); test della mappa con un utente senza permessi, un gestore d'area, un abilitato Didattica, un amministratore.
Nel resoconto: tabella pagina → regola, e qualsiasi pagina dove la regola della mappa ti sembra diversa da quella scritta nella pagina.
```

**Stato S5: approvato da Opus (21/11/2026).** Decisione: dashboard/inizio/aree/cerca solo a chi ha almeno un modulo o un'area; gli altri tornano all'area personale (fatto all'inizio di S6). nega_accesso() resta per AJAX e scaricamenti.

## Passo S6 — Tavolozza unica e tema scuro del pannello

```
Passo S6. Oggi i colori del Dipartimento sono scritti a mano centinaia di volte (#B30000/#b30000 ~99, #0056B3/#0056b3 ~92, #990000 ~29, #dc3545 13) in CSS, PHP e stili in linea.
1. Crea assets/css/dibest-colori.css con le variabili in :root (--dibest-rosso #B30000, --dibest-rosso-scuro #990000, --dibest-blu #0056B3, testo, testo-secondario, sfondo, sfondo-scheda, bordo, successo, attenzione, pericolo, informazione, e i colori dei moduli di MODULI_PORTALE) e le stesse per il tema scuro (stesso meccanismo già usato da portale-ui.css: [data-tema]/prefers-color-scheme, leggi come funziona ui_tema_script). Caricalo per primo in header.php e admin/admin_header.php (con ?v= come gli altri).
2. Sostituisci i colori scritti a mano con le variabili: in portale-ui.css, pannello.css, tsp-guida.css e negli stili in linea/blocchi <style> delle pagine (NON in email, PDF, ICS, manifest.json, colori salvati nel database o calcolati in PHP come colore_area/colore_testo_su).
3. Completa il tema scuro del pannello (pannello.css ha 4 regole contro 30 di portale-ui.css): menu laterale, schede, tabelle, moduli, alert, badge, finestre. Il pulsante del tema deve esserci anche nel pannello (ui_tema_pulsante), come nell'area personale.
4. Controllo nel browser (eccezione alla regola, con la copia locale di S1): home, area personale, admin/inizio.php, admin/fsl.php, admin/iscritti.php, chiaro e scuro: niente testi illeggibili. Allega 2-3 schermate nel resoconto (percorso dei file).
Nel resoconto: conteggio dei colori scritti a mano prima/dopo (grep -c).
```

**Stato S6 (e chiusura S5): approvati da Opus (21/11/2026)** dopo aver visto le schermate in documentazione/schermate_S6/. Per S8: nel tema scuro le icone dei moduli (rosso/blu scuro su riquadro scuro) hanno poco contrasto; vanno usate le varianti -testo.

## Passo S7 — Stili in linea → classi (primo giro)

```
Passo S7. Oggi ci sono ~1.700 style="..." in linea e 86 file con blocchi <style>. Questo è il PRIMO giro: niente cambi visibili, solo spostamento in classi.
1. Misura: conta style= per file (esclusi tests/ e strumenti/) e mettimi la classifica dei primi 25.
2. Sposta in classi riusabili (in portale-ui.css per il sito, pannello.css per il pannello, con prefisso ui- già in uso) gli stili ripetuti più comuni: larghezze massime dei contenitori, intestazioni colorate delle schede, piccoli testi, badge, icone tonde, bordi sinistri colorati, ecc. Prima i file della classifica: header.php, footer.php, admin/admin_header.php, area_personale.php e inc/area/*.php, miei_eventi.php, profilo.php, master_template.php, admin/fsl.php, admin/iscritti.php, admin/tutorato.php, admin/didattica_*.php.
   Restano in linea solo i valori che vengono dal database o calcolati (colore dell'area ecc.): lì usa una variabile CSS in linea (style="--c-area:#..."), così la classe fa il resto.
3. Caratteri troppo piccoli: nessun testo sotto .8rem (12px) negli stili toccati; dimmi i casi in cui serviva davvero più piccolo.
4. Blocchi <style> nelle pagine: spostali nel CSS comune quando sono riusati o grandi (>15 righe).
Obiettivo: almeno -50% di style= nei file toccati. Controllo nel browser come in S6 sulle stesse pagine (prima/dopo devono sembrare uguali). Nel resoconto: conteggi prima/dopo.
```

**Stato S7: approvato da Opus (21/11/2026)**: schermate prima/dopo identiche, -65% di style= nei file toccati. Da riprendere in S16: 292 classi ui- (molte usate una volta, 141 !important). In S8: il tema scuro delle classi nuove non è controllato, e c'è un difetto già esistente (il titolo «Ricerca» del filtro nelle aree pubbliche è bianco su chiaro, quindi invisibile).

## Passo S8 — Accessibilità

```
Passo S8 (linee guida AgID / WCAG 2.1 AA).
1. Le 24 <img> senza alt (grep '<img' senza 'alt='): alt descrittivo, oppure alt="" per quelle decorative.
2. Campi dei moduli: ogni input/select/textarea visibile deve avere un'etichetta collegata (label for/id, label che lo contiene, o aria-label). Controlla i file con più campi (form_builder, moduli pubblici, modulo.php, form.php, programma_fsl.php, convenzione_online.php, profilo.php, pannello) e correggi; dimmi il conteggio prima/dopo.
3. In header.php e admin_header.php: link «Vai al contenuto» come primo elemento, aria-current="page" sulla voce di menu attiva, focus visibile (outline) su link e pulsanti in tutti e due i temi.
4. Contrasto: con i colori di S6 controlla testo/sfondo dei badge, dei pulsanti outline e dei testi grigi (minimo 4.5:1); correggi le variabili, non le singole pagine.
5. Dichiarazione di accessibilità: nel footer del sito pubblico un link «Dichiarazione di accessibilità» che porta all'indirizzo salvato in una nuova impostazione del pannello (Testata e home → campo «Link alla dichiarazione di accessibilità», nelle impostazioni esistenti: se serve una colonna nuova, è permesso cambiare lo schema per questo campo). Scrivi in documentazione/accessibilita_dichiarazione_bozza.md i contenuti da riportare nel modulo AgID (form.agid.gov.it): cosa è conforme, cosa no (dalle verifiche di questo passo), contatti per le segnalazioni (da completare).
Controllo nel browser con la tastiera (Tab) su home, area personale e admin/inizio.php.
```

**Stato S8: approvato da Opus (21/11/2026)**: campi senza etichetta da 303 a 27 (falsi positivi), «Vai al contenuto» e focus visibile su sito e pannello, contrasti corretti nelle variabili, link alla dichiarazione dalla colonna url_accessibilita già esistente (schema invariato, nessuna versione nuova). Opus ha riportato a LF 10 file lasciati in CRLF. Da correggere in S9 (punto 0): alcuni id fissi aggiunti da S8 stanno dentro cicli foreach (es. c-prot-ingresso in admin/convenzioni_tesi.php:219 e admin/fsl.php:597) e si ripetono a ogni riga. Da rivedere a occhio più avanti: le diciture degli aria-label generati dal nome del campo.

## Passo S9 — Menu del pannello in 10 macroaree

```
Passo S9. Oggi MODULI_PORTALE (inc/sezioni.php) ha: orientamento, fsl, calendari, didattica, forms, amministrazione, portale. «Gestione del portale» mescola anagrafiche, sito pubblico, utenti e sistema; «Uffici e persone» è sia in Didattica sia in Portale; la Didattica arriva a 14 voci.
Nuova organizzazione (stessi permessi di oggi per ogni voce: leggi admin/admin_header.php righe ~120-560 e non allargare né restringere niente):
- Scrivania (inizio.php, «La mia scrivania»)
- Eventi e seminari (orientamento) — invariato
- Formazione Scuola Lavoro (fsl) — + «Scuole» (scuole.php), che esce dall'anagrafe; chi ha solo $puo_fsl_scuole entra in FSL e vede solo Scuole
- Prenotazioni e risorse (calendari) — invariato
- Didattica — voci divise in 4 gruppi con un titoletto e richiudibili (stato ricordato nel browser):
  «Pratiche e Consiglio» (Cosa devo fare, Pratiche, Sedute e verbali, Moduli e documenti, Statistiche),
  «Tirocini e tesi» (Tirocini, Tirocini Scienze motorie, Convenzioni di tesi),
  «Tutorato» (Tutorato, Sostituzioni),
  «Sportello» (Ricevimento, Assistenza, Didacticus)
- Amministrazione — invariato
- MyForms — invariato
- Persone e permessi (nuovo, 'persone'): Utenti e abilitazioni, Uffici e persone (UNA sola volta; chi oggi la vede in Didattica senza essere admin deve continuare a vederla, qui), Docenti, Personale TA, Insegnamenti, Corsi di studio, Strutture e aggiornamento
- Sito pubblico (nuovo, 'sito'): Testata e home, Menu, Notizie e pagine, Contatti per gli studenti, Area personale (riquadri), Aree di tutti i moduli
- Sistema (nuovo, 'sistema'): Protocollo (Titulus), Testi delle email, Sistema e registri, Entra come…
Regole:
- Il vecchio modulo 'portale' sparisce dal menu ma inizio.php?sezione=portale deve ancora funzionare (porta a 'persone' o alla scrivania: scegli e dimmelo). PAGINE_MODULO aggiornato perché ogni pagina accenda il modulo giusto.
- Ogni nuovo modulo ha nome, icona, colore (variabile di S6) e descrizione come gli altri; compaiono nella scrivania solo a chi ha almeno una voce.
- Il test statico di S5 e le prove del menu devono restare verdi; aggiungi un test: ogni voce di menu appartiene a un solo modulo.
Controllo nel browser con «Entra come…» (copia locale): amministratore, gestore di un'area eventi, abilitato Didattica, operatore Assistenza, abilitato solo Scuole FSL. Nel resoconto: per ognuno, quali moduli vede prima e dopo.
```

**Stato S9: approvato da Opus (21/11/2026)**: «Gestione del portale» diviso in Persone e permessi, Sito pubblico e Sistema. Le voci sono in un solo posto (src/Portale/Pannello/MenuModuli.php), la Didattica ha 4 gruppi richiudibili e Scuole è nella FSL. Nessun profilo perde voci (script strumenti/prove/menu_per_profilo.php; test sulle 512 combinazioni di permessi). Corretti anche gli id ripetuti nei cicli. Da fare in S10 (punto 0): i testi che citano ancora «Gestione del portale → …»; nel tema scuro le diciture blu delle tessere numeriche su fondo rosso (es. admin/didattica_ufficio.php) si leggono male.

## Passo S10 — Percorso, ricerca Ctrl+K, conferme uniche

```
Passo S10.
1. Percorso in alto nel pannello (sotto la barra): «Modulo › Sezione › Elemento» (es. «Didattica › Pratiche › PR-1A2B»). Modulo e sezione si ricavano da PAGINE_MODULO e dalla voce di menu attiva; l'elemento lo passa la pagina con una variabile facoltativa ($percorso_elemento). Le schede ?tab=/?vista= appaiono come sezioni.
2. Ricerca del pannello (admin/cerca.php esiste): campo sempre visibile in alto; Ctrl+K (Cmd+K su Mac) lo mette a fuoco; Invio apre cerca.php. Niente librerie nuove.
3. Conferme: crea una finestra di conferma unica (Bootstrap modal) in pannello.js e nello script comune del sito: si attiva con data-conferma="testo" su link e pulsanti di invio, e con data-conferma-scrivi="parola" per le eliminazioni definitive (bisogna scrivere la parola, come già per le aree). Sostituisci i 29 confirm() del browser (grep "confirm(" nei .php e assets/js), mantenendo i testi. Per le eliminazioni definitive (form, aree, risposte, utenti, bandi…) usa data-conferma-scrivi con il nome dell'elemento.
Prove di pagina dei moduli toccati verdi. Controllo nel browser: una conferma semplice e una «scrivi il nome».
```

**Stato S10: approvato da Opus (21/11/2026)**. Il percorso (.pn-briciole) passa per un segnaposto `<!--PN_BRICIOLE-->` che admin_footer.php sostituisce: se non viene sostituito resta un commento HTML, quindi non fa danni. La ricerca si apre con Ctrl+K. C'è una conferma unica (assets/js/conferma.js): rifà il click dopo il «sì», ripiega su confirm()/prompt() e il vecchio data-confirm funziona ancora. Testi «Gestione del portale» aggiornati. Da fare in S11 (punto 0): prova nel browser di due pagine pubbliche con le conferme nuove. Le pagine pubbliche che avevano data-confirm senza gestore ora chiedono conferma: elencarle. Al passo G Opus riporta a LF i file CRLF toccati.

## Passo S11 — Componente «a passi» + «Cosa devo fare» in ogni modulo

```
Passo S11.
1. Componente ui_passi(array $passi, int $corrente, string $dimensione = 'normale'|'piccolo'): string in inc/ui.php. Ogni passo ha titolo, stato (fatto/corrente/da_fare/bloccato), link facoltativo e nota facoltativa («manca: firma del Direttore»). Accessibile (lista ordinata, aria-current="step"), si adatta al telefono (in verticale), funziona nei due temi. CSS in portale-ui.css (serve anche al sito, S13).
2. Usalo in:
   - creazione/modifica di un'attività (admin/eventi.php): 1 Dati → 2 Turni → 3 Modulo di iscrizione → 4 Pubblica. Lo stato di ogni passo si ricava dai dati che già esistono (turni presenti? modulo scelto? visibile?). NON cambiare il modo di salvare;
   - scheda del bando di tutorato (admin/tutorato.php): Bando → Commissione → Graduatoria → Incarichi → Registro → Pagamenti, dallo stato già salvato;
   - scheda della pratica (admin/didattica_pratiche.php): Arrivata → Istruttoria → Seduta → Verbale → Protocollo, dallo stato della pratica.
3. «Cosa devo fare» come prima schermata (stesso stile di admin/didattica_home.php e amministrazione_home.php, numeri da App\Portale\CoseDaFare o dai servizi già usati nel menu):
   - Eventi e seminari: iscrizioni da approvare, liste d'attesa con posti liberi, attestati da inviare, sondaggi chiusi da leggere;
   - Tutorato: lettere da validare, incarichi da firmare, registri da controllare, pagamenti da preparare;
   - FSL: c'è già per le convenzioni (F16.1): aggiungi progetti senza scuole e valutazioni da fare, se i dati ci sono.
   Ogni numero ha un pulsante che apre la vista filtrata giusta. Se un numero richiederebbe una query nuova pesante, dimmelo invece di farla.
Test del componente (stati, escape) e dei conteggi nuovi.
```

**Stato S11: approvato da Opus (21/11/2026)**: ui_passi (classi .ui-percorso/.ui-passo*) usato in attività, bando e pratica. Nuove schermate «Cosa devo fare» per Eventi (dashboard.php?vista=cosa) e Tutorato (tutorato.php?vista=cosa), più due tessere FSL. Conferme pubbliche provate, con la finestra corretta nel tema scuro. Da fare in S12 (punto 0): il pannello è più largo dello schermo a 1280 px, c'è un doppio percorso (le briciole nuove più quelle vecchie della pagina), e il Tutorato ha 2 conteggi invece di 4 (registri, pagamenti?).

## Passo S12 — Pagine con troppi pulsanti e pagine vuote

```
Passo S12. Pagine con troppi pulsanti: admin/fsl.php (~50), admin/tutorato.php (~47), admin/didattica_pratiche.php (~39), admin/didattica_sedute.php (~36), admin/didacticus.php (~32), admin/iscritti.php (~28).
1. In ogni vista lascia visibili al massimo 1-2 azioni principali (pulsante pieno); le altre vanno in un menu «Altro ▾» (lo schema c'è già per le convenzioni FSL in F16.1: riusalo, non crearne un altro). Le azioni pericolose stanno in fondo al menu, in rosso, con la conferma di S10.
2. Pagine ed elenchi vuoti: usa ui_vuoto() con un testo che dice cosa fare e il pulsante per farlo («Non ci sono ancora bandi: crea il primo»). Cerca gli elenchi vuoti che oggi mostrano solo «Nessun risultato» nelle pagine del pannello.
3. Nessuna azione deve sparire: nel resoconto metti per ogni pagina l'elenco delle azioni prima/dopo (dove sono finite).
Prove di pagina dei moduli toccati verdi (se cercano un pulsante per testo, deve esserci ancora nel menu).
```

**Stato S12: approvato da Opus (22/11/2026)**: niente scorrimento orizzontale a 1280 e 1024 px (sotto 1400 px la barra mostra solo icone). Briciole doppie tolte. Il Tutorato aveva già i 4 conteggi, nascosti dallo sforo. Nuovo menu «Altro» (VociMenu, pn_menu_*) nelle 6 pagine, con i POST dentro il loro form; ui_vuoto per gli elenchi vuoti; eliminazione provata su dati di prova. Ritocco in S13 (punto 0): nella scheda del bando «Altro» va a capo sotto il titolo; va messo accanto ai pulsanti principali.

## Passo S13 — Area personale

```
Passo S13 (area_personale.php, inc/area/*.php, header.php del sito).
1. «Il prossimo passo»: in cima, prima delle icone, una sola tessera grande con la cosa più urgente di «Da fare per te» (scadenza più vicina; riusa App\Portale\ProssimaCosa o il servizio di «Da fare»), con pulsante «Vai» e «Vedi tutte (N)». Non compare se non c'è niente o se si guarda «come» un profilo.
2. Numeri sulle tessere: messaggi non letti, firme da fare, pratiche con novità (dai dati che le tessere già leggono, senza query nuove pesanti).
3. Tessere vuote: se una tessera non ha niente da mostrare e non ha un'azione utile («Nuova pratica»), si nasconde; in fondo «Mostra anche le sezioni vuote» le fa vedere.
4. Avanzamento dei percorsi: nelle tessere di tesi, tirocinio, pratiche e tutorato usa ui_passi(..., 'piccolo') di S11 con le tappe che già esistono (per il tutorato riusa inc/area/_tutorato_tappe.php).
5. Telefono (sotto 768px, solo con l'accesso fatto): barra fissa in basso con 5 voci: Home (area personale), Da fare (con numero), Calendario, Messaggi (con numero), Profilo. Non deve coprire il contenuto né il widget dell'assistenza; nascosta in stampa.
6. Filtro dei profili («Tutto / Studente / Docente…»): ricorda l'ultima scelta (in sessione) e la riapplica.
7. Pagine figlie con la stessa intestazione (ui_intestazione o ui-testata): miei_eventi.php, profilo.php, il_mio_tutorato.php, mie_conferme.php, da_fare.php, calendario.php, firme.php, pratiche.php; togli i loro stili propri dove duplicano quelli comuni.
Controllo nel browser (copia locale, «Entra come…»): studente, docente, tutor, una scuola; desktop e telefono (375px), chiaro e scuro. Schermate nel resoconto.
```

**Stato S13: approvato da Opus (22/11/2026)**: tessera «Il prossimo passo», numeri sulle tessere, tessere vuote nascoste, passi piccoli (pratiche, tesi, tutorato), barra del telefono, filtro ricordato, intestazione comune nelle 8 pagine figlie; testata del sito invariata. Da riprendere in S16: sul telefono il fumetto «Ti serve aiuto?» copre i link del piede (Privacy, Credits); nell'area del docente la stessa cosa compare tre volte (prossimo passo, pulsante, elenco), e il riquadro «Da fare» usa metà larghezza lasciando l'altra vuota.

## Passo S14 — master_template.php diviso in parti

```
Passo S14. master_template.php (~2.450 righe) è la pagina di ogni area pubblica. Dividilo SENZA cambiare niente di ciò che si vede o si fa:
- in testa le azioni (POST/GET con csrf) → una classe in src/Portale/Area/ (o servizi già esistenti) che restituisce cosa fare (messaggio, rimando);
- la vista in parziali inc/area_pubblica/*.php: testata dell'area, elenco delle attività, scheda del turno, modulo di iscrizione, lista d'attesa, ricevuta, ecc.;
- master_template.php resta il punto d'ingresso (i file segnaposto <slug>.php lo includono e non cambiano).
Lavora a pezzi piccoli e dopo ognuno lancia le prove di pagina delle aree/iscrizioni. Se un pezzo è troppo intrecciato per spostarlo senza rischi, lascialo e dimmelo.
Confronto finale: l'HTML di 3 pagine di area (una con turni, una FSL, una archivio) prima/dopo con la copia locale, deve essere identico (a parte i token CSRF): salva prima/dopo in %TEMP% e confronta con diff.
```

**Stato S14: approvato da Opus (22/11/2026)**: master_template.php da 2.448 a 255 righe. Ci sono 18 parziali in inc/area_pubblica/, protetti da inc/.htaccess, più le classi AzioniAreaPubblica ed EsitoPrenotazioneArea. Le 38 pagine sono identiche prima e dopo, e l'iscrizione e la disiscrizione vere funzionano. Il rimando ora è un 303 via vai_a, che è il codice giusto dopo un POST. Da fare in S15 (punto 0): due prove rosse nella suite («moduli e pagine dei moduli», probabilmente da S9, e «promemoria del diario»); la mappa tecnica dice ancora ≈2.500 righe.

## Passo S15 — Sistema: versione caricata e controllo di salute

```
Passo S15.
1. Versione caricata: strumenti/crea_pacchetto.sh scrive nel pacchetto un file VERSIONE_PORTALE.txt (commit corto, data e ora del pacchetto, ramo). La pagina Sistema (admin/sistema.php) lo mostra in alto («Versione caricata: d80ede2 del 21/11/2026 22:10»); se il file manca: «non disponibile». Il file non deve essere raggiungibile dal web (aggiungi la regola in .htaccess della radice; ricordami che il .htaccess NON è nel pacchetto e va copiato a parte).
2. Controllo di salute nella pagina Sistema, una riga per voce con semaforo:
   ultimo giro di cron_background, cron_attestati, cron_reminders, cron_backup (dai file di lock/stato in cache/ o dove già si registra: leggi prima come si fa oggi);
   spazio libero sul disco della cartella del portale e di uploads;
   ultimo backup riuscito;
   scadenza del certificato HTTPS (legge il certificato del sito da URL_SITO, con timeout breve; avviso sotto 30 giorni);
   ultima email inviata con successo e numero di errori nelle ultime 24 ore (log_email).
   Se una voce è rossa, cron_background manda UNA email al giorno agli amministratori (riusa ReportEmailSettimanale o il modo in cui già avvisa per il backup). Nella copia di prova i cron sono spenti: il controllo deve dirlo invece di segnare rosso.
Test: calcolo dei semafori con dati finti.
```

**Stato S15: approvato da Opus (22/11/2026)**: la versione caricata è in inc/versione_portale.txt, scritto solo nel .tgz e messo in .gitignore. Il controllo di salute (SaluteSito) ha semafori con la parola, il grigio per «mai girato», la copia di prova e il locale, e l'avviso dentro controlloPortale() (al massimo un'email al giorno). I cron scrivono in cache/cron_ultima_esecuzione.json (cache/ è chiusa al web). La prova del menu a moduli è aggiornata. La prova «promemoria del diario» era già rossa in d80ede2 e resta per il passo F. La suite dà 672 prove superate e 1 fallita.

## Passo S16 — TinyMCE 7 e meno «@»

```
Passo S16.
1. TinyMCE: oggi assets/vendor/tinymce/6.8.6. Verifica (dal sito ufficiale o dal changelog) se la serie 6 è ancora supportata. Se non lo è, proponi il passaggio alla 7 (licenza GPL: serve license_key: 'gpl' nella configurazione): dimmi dimensione, file da sostituire e cosa cambia nelle configurazioni usate dal portale (grep "tinymce.init"). Scarica SOLO dopo il mio sì. Copia locale come oggi (url_vendor), niente CDN nuovi.
2. Classi ui- di S7: unisci quelle usate una sola volta in classi più generali o riportale in regole della pagina; riduci gli !important a quelli necessari (dove JavaScript o Bootstrap le sovrascriverebbero). Niente cambi visibili (schermate prima/dopo come in S7).
3. Soppressioni con @: nei file toccati da S1-S15 togli le @ davanti a file_get_contents, unlink, mkdir, json_decode, ecc. sostituendole con controlli espliciti (is_file, is_dir…) ed error_log quando qualcosa fallisce. Lascia @session_start e le @ dove l'errore è previsto e innocuo, con un commento. Non toccare file che i passi precedenti non hanno toccato.
```

**Stato S16: approvato da Opus (22/11/2026)**: ritocchi dell'area personale fatti. Classi ui- da 350 a 258, !important in portale-ui.css da 148 a 78, schermate S7 identiche pixel per pixel. Le @ sono passate da 151 a 4 con App\Core\File. La connessione al database usa l'eccezione e poi torna a mysqli_report(OFF), come prima. Le pagine che usano File:: caricano tutte l'autoload (via functions.php). La proposta TinyMCE 8 è in documentazione/proposta_tinymce_8.md e la decide l'utente. La suite intera va rilanciata al passo F, perché le @ sono state tolte con uno script.

**Decisione dell'utente (22/11/2026):** aggiornare TinyMCE alla 8 dentro il passo S17 (punto 0 del blocco dato a Sonnet).

## Passo S17 — CSP senza 'unsafe-inline' per gli script

```
Passo S17 (il più delicato: lavora a pezzi e lancia le prove di pagina dopo ognuno).
1. Nonce per richiesta: config.php genera un nonce (random_bytes 16, base64) prima del Content-Security-Policy, lo mette in script-src ('nonce-...') al posto di 'unsafe-inline' e lo rende disponibile con csp_nonce() (inc/sistema.php). Lo stile resta con 'unsafe-inline' (gli stili in linea non sono finiti).
2. Ogni <script> in linea (PHP, parziali, src/**/Vista) riceve nonce="<?php echo csp_nonce(); ?>" (o l'equivalente nelle stringhe PHP). Comprese le pagine che non passano da header.php (stampe, ricevute, pagine di firma, saml_login.php, offline.html se servita da PHP).
3. I ~48 onclick= e gli altri attributi on* (onchange, onsubmit…) vanno spostati in addEventListener (in assets/js o in uno script con nonce), di solito con data-attributi; javascript: negli href va tolto.
4. Le pagine che girano dentro altri sistemi (SimpleSAMLphp) non passano da config.php: non toccarle.
5. Prima di attivare il CSP nuovo: modalità di sola segnalazione per la copia locale (Content-Security-Policy-Report-Only con lo stesso testo + report a un piccolo endpoint che scrive in error_log), giro sulle pagine principali, correzioni; poi CSP vero.
Fatto quando: nella copia locale nessun errore CSP nella console su home, area personale, un'area pubblica con iscrizione, scanner QR, pannello (inizio, eventi, iscritti, fsl, didattica pratiche, form_builder, testata con TinyMCE), pagine di firma. Elenco delle pagine controllate nel resoconto.
```

**Stato S17: approvato da Opus (22/11/2026)**: TinyMCE 8.10.2 GPL (license_key 'gpl'), la 6.8.6 tolta. La CSP vera è attiva con il nonce (inc/csp.php) e report-uri csp_report.php (al massimo 300 righe l'ora); lo stile resta 'unsafe-inline'. Gli on* nel PHP sono passati da 84 a 1 (install.php, senza CSP), sostituiti da assets/js/azioni.js. Il service worker carica le pagine dalla rete e usa la copia salvata solo come pagina offline, quindi non serve HTML con nonce vecchi. Non aperte con un utente reale: pagine di firma con parametri, scanner_studente.php, stampe, menu «Altro» con clic vero: da provare nella copia di prova. Per tornare indietro basta rimettere 'unsafe-inline' in config.php.

## Passo F — Giro completo e messaggio di commit

```
Passo F (ultimo di Sonnet).
1. Sintassi: php -l su TUTTI i file PHP del portale (esclusi tests/ e strumenti/locale/www): nessun errore.
2. Suite intera: tutte le cartelle tests/Unit e tests/Integration una alla volta, e le prove di pagina (strumenti/prove/esegui.php con la copia locale accesa). Tabella esito per cartella; per ogni fallimento dimmi se c'era già prima dei passi S (confronta con quanto scritto nei resoconti) o se è nuovo. I nuovi vanno corretti prima di chiudere.
3. Controlli finali con grep (esclusi tests/ e strumenti/): HTTP_HOST, window.location.replace, confirm(, onclick=, msg_area_pers, X-XSS-Protection: per ognuno il numero rimasto e il perché.
4. git ls-files --eol sui file .sh toccati: devono essere w/lf.
5. Rileggi documentazione/commit_controllo.txt e trasformalo in un unico messaggio di commit: prima riga breve in italiano (es. «Controllo del portale: sicurezza, pannello in macroaree, area personale e restyling»), poi un paragrafo per passo. Niente Co-Authored-By né riferimenti a Claude.
6. Scrivi in documentazione/istruzioni_aggiornamento_controllo.txt cosa cambia per chi carica: schema (versione nuova, se S8 l'ha cambiato), nuove voci .env (HOST_AMMESSI, URL_SITO obbligatorio), .htaccess della radice da copiare a parte (S15), cosa provare nella copia di prova (elenco breve per profilo).
NON fare il commit: lo fa Opus.
```

---

## Passo G — Commit unico e caricamento (in questa chat, con Opus)

1. Porti qui il resoconto del passo F. Opus fa la revisione finale, il **commit unico** su `main` (autore Emanuele Dodaro, senza Co-Authored-By) e crea `aggiornamento_completo.tgz` con `strumenti/crea_pacchetto.sh`.
2. **Copia di prova:**
   - `scp` del pacchetto (e di `.htaccess` se S15 l'ha cambiato) in `~/`;
   - `sudo bash ~/crea_ambiente_prova.sh aggiorna-codice --pacchetto ~/aggiornamento_completo.tgz`;
   - `.env` di prova con `URL_SITO` e `HOST_AMMESSI`;
   - una pagina del pannello per lo schema;
   - le prove dell'elenco per profilo.

   Opus ti dà i comandi esatti in quel momento.
3. **Produzione** (`/opt/lampp/htdocs/mydibest`), solo dopo il tuo «ok» sulla prova, di sera:
   - copia di sicurezza;
   - estrazione e `sha256sum -c`;
   - `.env`;
   - `.htaccess`;
   - permessi di `cache/`;
   - pagina del pannello per lo schema;
   - `strumenti/verifica_sito.sh`.
4. Il push su GitHub (`git push tutti main`) lo fai tu quando sei contento.
5. Dopo la produzione: **nuova CRON_KEY** nel `.env` e nel crontab (quella attuale è comparsa in chiaro più volte).
