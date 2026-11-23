# Ripresa a casa dopo l'ufficio — 20/11/2026

Documento per il Claude di casa (Opus, **revisore**). Continua `documentazione/ufficio_21112026.md`: leggi prima quello (regole fisse §0,
Regole del progetto §2, blocchi R §7.3), poi questo, che dice cosa è stato fatto in ufficio e cosa è cambiato nel piano.
**Questo file non va nel commit** (come `ufficio_21112026.md`).

Metodo invariato: **Sonnet scrive** (un passo per sessione nuova, senza commit), **Opus rivede** (legge il resoconto incollato dall'utente,
controlla con `git diff`, rilancia i test mirati, dà il verdetto e il blocco successivo con le Regole già dentro).

---

## 1. Fatto in ufficio (20/11/2026) — tutto in locale, NIENTE committato

| Passo | Cosa | Stato |
|---|---|---|
| AM1–AM3 | modulo Amministrazione, modulistica amministrativa, impostazioni e PTA (v129–v131) | approvati (prima) |
| AM4 | campi e colonne modificabili (v132, `ConfigurazioneCampi`, scheda `campi`) | **approvato**. I 3 rossi di Pubblicazioni erano dovuti a `ConfigurazioneCampiTest` che lasciava la colonna F nascosta: corretto il tearDown. Corretto anche `flash_set` con `$h()` dentro. |
| AM5 | campi nuovi `x_<n>` e colonne nuove `y_<n>` (v133, `CampiAggiunti`, valori in `campi_extra_json` / `colonne_extra_json`, contatori `pubblicazioni_ultimo_x/_y` in `impostazioni_sistema`) | **approvato**. MyForms non toccato. |
| AM6 | guide (`GuidePerRuolo`, nuova guida `amministrazione`), mappe 13b/13c/13i/13j, `inventario_accessi.md` (M e N), CSV Didacticus `didacticus_voci_amministrazione_20261120.csv`, `istruzioni_aggiornamento_amministrazione.txt`, prove | **approvato**. Giro completo: nessun fallimento nuovo. |
| AM7 | vista `admin/pubblicazioni.php?vista=da_compilare` (tabella, «Solo le mie colonne», tutto o niente con `aggiornaOperatoriInBlocco`, classe `CompilazioneInTabella`), voce «Da compilare» | **approvato** |
| R1 | testi delle email cambiati dal pannello | **chiuso senza modifiche**: i 6 test oggi sono verdi (anche nel giro completo di AM6). Non era un difetto del sito; l'elenco del §7 era stato misurato con il database locale indietro. Niente da scrivere nel commit. |

Test mirati dopo AM7 (rilanciati dal revisore): Unit Pubblicazioni 84, Unit Amministrazione 8, Integration Pubblicazioni 58,
Integration Amministrazione 15, tutti verdi. `documentazione/commit_amministrazione.txt`: titolo unico, un paragrafo per AM1–AM7, elenco di 89 file
uguale a `git status` meno le esclusioni.

Da guardare a occhio su mydibest_pp (nessuno l'ha visto in un browser): il JavaScript della scheda «Campi e colonne» (aggiungi riga, eliminazione
bloccata, cambio tipo) e la tabella larga di «Da compilare». I controlli sono già in `istruzioni_aggiornamento_amministrazione.txt`.

---

## 2. Piano CAMBIATO dall'utente (20/11/2026)

**Prima** si fanno anche i blocchi dei test rossi, **poi UN SOLO commit per tutto, che fa Claude** (non l'utente), senza Co-Authored-By né
riferimenti a Claude. Alla fine Claude dà all'utente i **comandi per caricare sul server**.

Ordine:
1. **R4** — email di MyForms nel catalogo (`NessunaEmailScrittaNelCodiceTest`). Può darsi che l'utente l'abbia già dato a Sonnet in ufficio
   (nella sessione di R1): chiediglielo. Se c'è un resoconto, rivedilo; altrimenti dai il blocco R4 (§3) in una sessione nuova.
2. **R2** — date fisse nei test.
3. **R3** — test Unit vecchi e `ModuliAree` in `DaFareEdEmailTest`.
4. **R5** — Didattica e prove di pagina rimaste (comprese le prove di pagina FSL che falliscono per i turni chiusi: vedi sotto).
5. **Giro completo** dei test, una volta sola, fatto da Opus (non da Sonnet): tutte le cartelle di `tests/Unit` e `tests/Integration` una alla
   volta, poi le prove di pagina (`PROVE_SOLO_FUNZIONI=1`, poi le `pagine_*`). Nessun fallimento nuovo rispetto all'inizio.
6. **Commit unico** fatto da Opus (§4).
7. **Comandi per il server** (§5).

Differenze dai blocchi R di `ufficio_21112026.md` §7.3:
- il messaggio di commit NON va in `commit_rossi_R<n>.txt` ma in `documentazione/commit_amministrazione.txt`, in una parte «Correzioni dei test
  che fallivano» prima dell'elenco dei file (un paragrafo per blocco: causa, correzione, se era un difetto vero del sito);
- nei blocchi R il modulo Amministrazione e le Pubblicazioni non si toccano;
- nel giro completo di AM6 sono comparsi in più: Integration Fsl 23 rossi (invece di 19, stessi test del 19/11) e 18 prove di pagina FSL rosse
  perché nei dati locali il turno 201 si è chiuso il 30/10 («status=closed»): vanno in R2/R5 (date), non sono difetti del modulo.

---

## 3. Blocchi pronti

### Regole per i blocchi R (da incollare in ogni blocco R)

```
Regole del progetto:
- Nessun ALTER/CREATE nelle pagine: le tabelle si cambiano solo in src/Core/Schema/Definizioni.php alzando Migrazioni::VERSIONE (prossima versione dopo l'ultima presente). Le migrazioni devono riempire i valori iniziali (nessun cambiamento per chi c'è già).
- Classi con declare(strict_types=1) (il contenitore le collega da solo); funzioni-facciata in inc/ solo se servono alle pagine. Non ridefinire funzioni già presenti.
- File PHP senza BOM UTF-8. Escape di tutto l'output. Ogni POST con csrf_verify e permesso ricontrollato sul server. Flash con flash_set (senza escape dentro).
- La testata del sito non si tocca. Non toccare il modulo Amministrazione né le Pubblicazioni (admin/amministrazione*.php, admin/pubblicazioni.php, src/Amministrazione/, src/Pubblicazioni/, src/Email/CatalogoPubblicazioni.php).
- Testi in italiano semplice, niente gergo tecnico.
- Correggi nel POSTO GIUSTO: se il codice è sbagliato si corregge il codice; se il test descrive un comportamento vecchio e il cambiamento era voluto (controlla il git log) si corregge il test. Non spegnere, saltare o indebolire nessun test.
- Test, SOLO le cartelle indicate nel passo (niente suite intera, niente copia del progetto per ricostruire il «prima», niente browser):
  · PHPUnit 9.6: C:\xampp\php\php.exe -d extension=zip -d extension=gd -d extension=intl "$env:TEMP\phpunit96\phpunit-9.6.phar" --bootstrap "$env:TEMP\phpunit96\bootstrap.php" --no-configuration <cartella> (il bootstrap c'è già, non ricrearlo; gli -d extension servono sempre);
  · il «prima» è lo stesso giro lanciato all'inizio, prima di toccare il codice; dimmi prima/dopo per ogni cartella;
  · per i test col database avvia mysqld di XAMPP con --standalone e fermalo alla fine.
- MariaDB: fermalo SEMPRE in modo pulito (C:\xampp\mysql\bin\mysqladmin -u root shutdown), MAI con un arresto forzato del processo. Se non si ferma, dimmelo e lascialo acceso. Non usare avvia.sh se il database è già acceso.
- C:\xampp\php\php.exe -l su ogni file toccato.
- MAI git stash (cartella su Google Drive). NON fare commit, push o altri comandi git che modificano qualcosa. Le modifiche già presenti in locale non si rifanno e non si annullano.
- Messaggio di commit UNICO in documentazione/commit_amministrazione.txt: se non c'è ancora, crea prima dell'elenco dei file la parte «Correzioni dei test che fallivano» e aggiungi lì il paragrafo del tuo blocco (causa, correzione, se era un difetto vero del sito); poi aggiorna l'elenco dei file in fondo (elenco esatto e completo, controllato con git status; restano fuori il file del messaggio, strumenti/apache_ubuntu/, strumenti/controllo_mariadb*.sh, strumenti/inventario_apache*.sh, documentazione/commit_*.txt degli altri lavori, documentazione/comandi_sonnet_myforms.md, documentazione/istruzioni_aggiornamento_pubblicazioni.txt, documentazione/ufficio_21112026.md, documentazione/casa_dopo_ufficio_20112026.md). Senza Co-Authored-By né riferimenti a Claude.
- Se una scelta non è scritta qui, fermati e chiedimi.

Alla fine: resoconto breve (file, causa, correzione, test prima/dopo, dubbi).
```

Ogni blocco: «Lavori sul portale MyDiBEST (PHP 8.2, MariaDB) nella cartella corrente. In locale c'è il modulo Amministrazione (AM1-AM7,
approvati, non committati) e le correzioni dei blocchi R già fatti: NON toccarli. Fai SOLO il passo R<n>.» + Regole qui sopra + testo del passo.

### R4 — Email di MyForms nel catalogo
```
PASSO R4 — Email di MyForms nel catalogo. tests/Unit/Email/NessunaEmailScrittaNelCodiceTest segnala src/Forms/NotificheForms.php: usa il Mailer direttamente. Il test ha ragione e NON va cambiato: NON aggiungere NotificheForms all'elenco delle eccezioni.
1. Porta le email di MyForms (nuova risposta ai gestori, ricevuta a chi risponde, avvisi di chiusura e di conservazione: controlla quali ci sono davvero in NotificheForms) in un catalogo src/Email/CatalogoForms.php sul modello di src/Email/CatalogoPubblicazioni.php, e mandale con InvioEmail come fanno gli altri moduli. Testi di partenza IDENTICI a quelli di oggi (chi non tocca niente non vede differenze), segnaposto descritti, modificabili da Gestione del portale → Email. Le parti che oggi scrive chi crea il form (se ci sono) restano come sono: dimmi come le tratti prima di scrivere.
2. Se servono righe nuove in email_modelli o una migrazione, la regola è quella dello schema (Definizioni + Migrazioni::VERSIONE); se il catalogo basta da solo (righe vuote = testo del codice, come per le altre email), niente migrazione.
3. Test: tests/Unit/Email (deve diventare verde), tests/Integration/Forms, tests/Integration/Email, tests/Integration/Pubblicazioni, prima e dopo; prova di pagina di una risposta a un form con l'email che parte (PROVE_SOLO=<il modulo delle prove di MyForms>), prima e dopo.
```
Revisore: `NessunaEmailScrittaNelCodiceTest` verde senza toccarlo; testi di partenza identici; `src/Forms/` cambiato solo per l'invio;
`Integration/Forms` verde come prima.

### R2, R3, R5
Testi in `ufficio_21112026.md` §7.3, con le Regole qui sopra al posto di quelle indicate lì. In R2 aggiungi: «Comprese le prove di pagina FSL
che falliscono perché i turni dei dati di prova sono chiusi (es. turno 201 chiuso il 30/10, "status=closed"): se si possono rendere indipendenti
dalla data, fallo; altrimenti elencale a parte». R3 Unit: `PortaleUnitTest::testWidgetHomeNormalizzaIlSalvato` è ancora rosso (11 widget).

---

## 4. Commit unico (lo fa Opus, dopo il giro completo)

Il messaggio è `documentazione/commit_amministrazione.txt` **senza** la parte finale «File da aggiungere:» (come in c3f9fd9). Il titolo, se ci sono
correzioni R, diventa per esempio: «Modulo Amministrazione: modulistica amministrativa e richieste di pubblicazione configurabili, aperte anche al
PTA; correzioni dei test che fallivano». Nessun Co-Authored-By, nessun riferimento a Claude (regola fissa dell'utente). Mai `git stash`.

1. Ricontrolla che l'elenco «File da aggiungere:» coincida con `git status` meno le esclusioni (§3 Regole).
2. `git add` dei soli file dell'elenco (PowerShell):
   `$r = Get-Content documentazione\commit_amministrazione.txt -Encoding UTF8; $i = [array]::IndexOf($r, 'File da aggiungere:'); $r[($i+1)..($r.Count-1)] | Where-Object { $_.Trim() } | ForEach-Object { git add -- $_.Trim() }`
3. Messaggio senza l'elenco, UTF-8 senza BOM:
   `[IO.File]::WriteAllLines("$env:TEMP\msg_amm.txt", $r[0..($i-1)], (New-Object Text.UTF8Encoding($false)))`
4. `git status --short`: tra i non aggiunti restano solo i file esclusi. Poi `git commit -F "$env:TEMP\msg_amm.txt"`.
5. Push solo se l'utente lo chiede: `git push tutti main` (origin e unical), dopo `git status -sb`.

---

## 5. Comandi per il server (alla fine, per l'utente)

Seguire `documentazione/istruzioni_aggiornamento_amministrazione.txt` (scritto in AM6, aggiornato in AM7): pacchetto con
`strumenti/crea_pacchetto.sh`, caricamento in `~/` sul server 160.97.37.188, aggiornamento prima della copia di prova **mydibest_pp**
(migrazione v128 → v133, che parte da sola alla prima pagina), controlli nel browser elencati lì, e solo dopo la produzione.
Se R4 aggiunge una migrazione, la versione finale sarà più alta di 133: aggiorna le istruzioni prima del commit.
Dare all'utente i comandi esatti, presi da quel file e dagli script, uno per blocco, pronti da copiare.

---

## 6. Cose imparate oggi (per il revisore)

- Ogni test che scrive in `pubblicazioni_campi` / `pubblicazioni_colonne` deve svuotarle nel tearDown (il database di prova è condiviso).
- Il marcatore `cache/schema_vNNN.ok` arriva da Drive e può dire «già migrato» quando il database locale di quel PC è indietro: se manca una
  colonna, si cancella solo quel file (non è nel git) e la migrazione riparte.
- Il «prima» e i fallimenti noti del §7 di `ufficio_21112026.md` sono stati misurati a casa con un database diverso: rilancia sempre il «prima».
- PHPUnit e bootstrap sono in `%TEMP%\phpunit96` su tutti e due i PC; sempre `-d extension=zip -d extension=gd -d extension=intl`.
