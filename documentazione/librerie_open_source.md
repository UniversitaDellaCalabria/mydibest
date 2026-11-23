# Librerie open source dal portale — priorità e procedura

> Scritto il 21/11/2026, aggiornato il 22/11/2026 con le decisioni dell'utente.
> Obiettivo dichiarato: **non guadagnarci, fare qualcosa che serva a qualcuno.** Quindi licenza aperta,
> pacchetti piccoli, uno per volta, nessuna promessa di assistenza.
> Il framework del portale (`src/Core`, `src/Http`) **non** si pubblica: vedi la Fase 8 di
> `piano_framework_dibest.md`. Quello che vale per gli altri sta in `src/Infrastructure` e nel
> connettore Titulus.

## Decisioni dell'utente (22/11/2026)

- **I repository stanno su GitHub a nome di Emanuele Dodaro** (utente `dodaroe`), non dell'organizzazione.
- **Licenza: AGPL-3.0.**
- **Claude non compare tra gli autori**: niente `Co-Authored-By`, niente «Generated with», né nei commit
  né nelle pull request, né nei README.

### Una nota sulla licenza — decisione chiusa il 22/11/2026

**AGPL-3.0 confermata dall'utente anche per le librerie, dopo aver sentito l'alternativa. La questione
è chiusa: non si riapre a ogni pacchetto.** Quello che segue resta scritto solo per ricordare perché.

AGPL-3.0 è **la licenza giusta per il portale**: è fatta esattamente per i programmi che si usano via
rete, e dice che chi prende MyDiBEST e lo mette online deve pubblicare le sue modifiche. Per il portale
va tenuta.

Per **una libreria** l'effetto è diverso, e va detto chiaro: AGPL è copyleft forte e si propaga a tutto
il programma che la include. In pratica un fornitore che integra `php-titulus-utility` in un gestionale
chiuso **non può usarla**, e quei fornitori (Cineca compresa) sono proprio chi altrimenti la adotterebbe.
Siccome l'obiettivo dichiarato è «che serva a qualcuno», per le **sole librerie** vale la pena sapere che
esistono due alternative:

- **Apache-2.0** (quella che il pacchetto Titulus ha già): la usa chiunque, anche in prodotti chiusi.
- **MPL-2.0** o **LGPL-3.0**: via di mezzo. Chi modifica *la libreria* pubblica le modifiche, chi la
  *include* non deve aprire il suo programma.

L'utente ha scelto **AGPL-3.0 per tutto**, portale e librerie: chi vuole usarle apre il suo codice.
È una scelta coerente con il motivo per cui si pubblica, e si applica così senza altre discussioni.
**Resta da fare una cosa sola**: il pacchetto Titulus è oggi Apache-2.0, e cambiargli licenza va
confermato con l'Università, perché è codice nato per loro (vedi «Prima di pubblicare»). Se l'Università
chiedesse di tenere Apache-2.0 su quel pacchetto, gli altri restano AGPL-3.0 e non cambia niente.

## La regola che vale per tutte

**Una libreria si pubblica solo se il portale la usa dal pacchetto, non come copia.** Se il portale
tiene la sua versione e il pacchetto va per conto suo, in tre mesi sono due programmi diversi e la
libreria è morta. Il connettore Titulus lo fa già nel modo giusto: sta in
`protocollo/php-titulus-utility/`, caricato con PSR-4 da `composer.json` del portale
(`"Unical\\Titulus\\": "protocollo/php-titulus-utility/src/"`).

Corollario: **il pacchetto non può dipendere dal portale.** Niente `App\`, niente `$conn`, niente
`RADICE_SITO`, niente nomi di tabella. Chi ha bisogno di salvare qualcosa lo fa attraverso
un'interfaccia che il portale implementa (come fa `RegistroInvii` nel connettore Titulus:
`RegistroSuFile` e `RegistroInMemoria` nel pacchetto, la versione su database nel portale).

## Lo stampo: com'è fatto un pacchetto

`protocollo/php-titulus-utility/` è già la forma giusta, e si copia per tutti gli altri:

```
nome-pacchetto/
  src/                 le classi, un namespace proprio (non App\)
  tests/esegui.php     prove che girano con «php tests/esegui.php», senza PHPUnit
  composer.json        type: library, license: AGPL-3.0-or-later, require solo php + estensioni
                       name: dodaroe/<nome> (i repository sono a nome dell'utente)
  autoload.php         per chi non usa Composer (è il caso dei server degli enti)
  README.md            cosa fa, requisiti, installazione con e senza Composer, esempi
  LICENSE
```

Due dettagli di quello stampo che valgono più di quanto sembri, e che vanno tenuti:

- **`autoload.php`**: si usa anche senza Composer. Metà dei server delle PA italiane sono così — il
  nostro compreso. È la ragione per cui questi pacchetti servono a qualcuno.
- **`tests/esegui.php`**: le prove girano con un comando e zero dipendenze di sviluppo. Chi valuta la
  libreria la prova in dieci secondi.

---

## Le priorità

Ordinate per **(valore per gli altri) × (basso sforzo) × (basso rischio)**, non per quanto sono
interessanti da scrivere.

### P1 — `php-titulus-utility` · PUBBLICARE, non estrarre

**Stato: pronto.** 1.759 righe, già pacchettizzato, Apache-2.0, README scritto, `autoload.php`,
prove proprie. Dentro non c'è un solo riferimento al portale (verificato: unica occorrenza di «DiBEST»
è `uo_nome` in un dato di prova, da cambiare in un nome finto).

**Perché è il primo**: non è un'estrazione, è una pubblicazione. Collauda tutta la filiera — licenza,
repository, README, eventuale Packagist — al costo di un pomeriggio, e ha il pubblico più chiaro che
esista: **ogni università e ogni PA italiana che usa Titulus** ha questo problema e non ha questa
soluzione in PHP. Esiste il pari in Python (`dj-titulus-utility`): questo ne è il gemello.

Passi:

1. Cambiare il dato di prova con `uo_nome` generico in `tests/esegui.php:118`.
2. Togliere i `desktop.ini` (sono di Google Drive, non servono a nessuno) e aggiungere un `.gitignore`.
3. README: una riga su cosa **non** fa e che non c'è assistenza garantita; togliere il rimando relativo
   `[dj-titulus-utility](../README.md)`, che fuori dal nostro disco non porta da nessuna parte.
4. `composer.json`: nome `dodaroe/php-titulus-utility`, `license: AGPL-3.0-or-later`, autore l'utente;
   `LICENSE` sostituito con il testo di AGPL-3.0 e l'intestazione del copyright. Chiedere
   all'Università che vada bene così (vedi «Prima di pubblicare» più sotto).
5. Repository **nuovo e vuoto**, non un pezzo della storia di questo: `git init`, un primo commit,
   push. Nessun travaso di storia: è più sicuro e meno lavoro.
6. Il portale continua a usarlo come fa oggi. Si valuta Packagist solo dopo che il repository è su.

### P2 — `php-pdf-firme` · firme PAdES e segnatura di protocollo

**La cosa più rara che abbiamo.** `src/Infrastructure/Pdf/SegnaturaPdf.php` (726 righe) +
`FirmePades.php` (45) + `VerificaFirme.php` (14, interfaccia) + `MessaggiFirma.php` (46) = **831 righe**
con **zero dipendenze da `App\`** (un solo `use RuntimeException`). Prove già scritte:
`tests/Unit/Infrastructure/Pdf/SegnaturaPdfTest.php` (119 righe) e `FirmePadesTest.php` (48).

Leggere e verificare le firme dentro un PDF in PHP puro, e apporre la segnatura senza rompere le firme
già presenti, è un problema che in giro è risolto male o a pagamento. Sforzo di estrazione: basso,
perché il codice è già isolato. Si fa **dopo** P1, usando lo stampo collaudato.

### P3 — `php-fogli-leggeri` · xlsx e docx senza dipendenze

`Excel.php` (87 righe) + `Word.php` (146) + `Xml.php` (15) + `Download.php` (21) = **269 righe**.
Scrivere `.xlsx` e `.docx` senza PhpSpreadsheet né PHPWord. Piccolissimo, e risolve il problema di chi
sta su un server senza Composer e deve consegnare un foglio di calcolo.

**Attenzione**: `FogliEsportabili.php` e `TabellaEsportabile.php` dipendono da `Pdf\Documento` e
**restano nel portale** — sono il nostro modo di esportare, non una libreria.

### P4 — `php-pdf-leggero` · generare PDF con font TrueType

`Documento.php` (528) + `TestoPdf.php` (440) + `TrueType.php` (235) = **1.203 righe**. Funziona ed è
pulito, ma qui la concorrenza esiste da vent'anni (FPDF, TCPDF, mPDF). Si pubblica solo se P2 e P3 sono
andati bene e ne hai ancora voglia: è il più grande e il meno necessario.

### P5 — `php-usign` · firma remota U-Sign (quando arrivano le credenziali)

Il codice **non esiste ancora**, e questo cambia tutto: **nasce libreria dal primo giorno, non si
estrae dopo.** È l'unico caso in cui costruire fuori dal portale è giusto, perché non c'è niente da
riscrivere e il confine si disegna una volta sola.

È il fratello naturale di P1: stessa Cineca, stesse università, stesso bisogno. Chi ha Titulus prima o
poi ha U-Sign, e cerca una libreria PHP che non c'è.

Come si imposta quando il momento arriva:

- il pacchetto conosce **solo** il servizio di firma: credenziali, documento da firmare, richiesta,
  esito, errori. Non conosce convenzioni, verbali, pratiche né utenti;
- il portale ci arriva attraverso le sue interfacce in `src/Firme/` (`DocumentoDaFirmare`,
  `FonteFirme`, `ScrivaniaFirme` ci sono già): **la scrivania delle firme non cambia**, cambia solo chi
  firma davvero sotto;
- un'implementazione finta (`FirmaFinta`) nel pacchetto, così le prove del portale girano senza toccare
  il servizio vero — e senza consumare firme;
- **nel pacchetto non finisce mai una credenziale**, nemmeno in un esempio del README.

**Quando**: non adesso. Prima P1, e prima che tu abbia in mano accesso e documentazione. Quando ci
siamo, si fa un piano suo come questo.

---

## Prima di pubblicare (vale una volta, per tutti)

- **I diritti.** `composer.json` del portale dice `license: proprietary`. È software fatto per
  l'Università: la decisione non è solo tua. Vale la pena chiedere a chi di dovere — in Italia l'art. 69
  del Codice dell'Amministrazione Digitale spinge le PA a pubblicare in open source il software che
  commissionano (Developers Italia), quindi probabilmente non solo si può, ma è la via attesa. Fatti
  dire **come** si fa e **a nome di chi**: cambia l'autore nel `LICENSE` e chi risponde delle
  segnalazioni. L'utente ha deciso **repository a suo nome e AGPL-3.0**; resta da far confermare
  all'Università che va bene così, visto che il pacchetto Titulus è nato per loro ed è oggi Apache-2.0.
- **Niente storia del portale.** Ogni pacchetto parte da un repository nuovo. Nel repo del portale ci
  sono `.env.example`, `aggiornamento_*.zip`, i file `controllo_mariadb_*.txt`, l'indirizzo del server:
  non devono comparire in un repository pubblico, e riscrivere una storia è più rischioso che
  ricominciarne una.
- **Un controllo prima di ogni push**, meccanico: cercare nel pacchetto `dibest`, `unical.it`, `160.97`,
  `@`, `password`, `chiave`, `token`, un codice fiscale, una matricola. Se qualcosa esce, si ferma.
- **Il README dice cosa la libreria non fa** e che l'assistenza non è garantita. È la cosa che ti
  protegge dal diventare il manutentore non pagato di qualcun altro, e per chi legge è un segno di
  serietà, non di debolezza.

## Il conto

| | Righe | Dipendenze dal portale | Prove già scritte | Sforzo |
|---|---|---|---|---|
| P1 Titulus | 1.759 | nessuna | sì (`tests/esegui.php`) | **già pronto** |
| P2 Firme PDF | 831 | nessuna | sì (167 righe) | basso |
| P3 Fogli | 269 | nessuna | sì (`DocumentiTest`) | basso |
| P4 PDF | 1.203 | nessuna | in parte | medio |
| P5 U-Sign | da scrivere | — | — | dipende dal servizio |

Quattro pacchetti su cinque sono **già staccati di fatto**: è il risultato del lavoro di migrazione a
classi fatto quest'anno, e non è un caso. Il lavoro vero non è il codice, è la procedura: licenza,
repository, README, controllo dei dati. Si collauda su P1 e poi diventa ripetizione.

## Ordine di esecuzione

**P1 adesso** (dopo il commit e il caricamento del lavoro in corso), **P2 e P3 dopo**, una per volta e
solo quando la precedente è pubblicata e il portale la usa dal pacchetto. **P5 quando arriva U-Sign.**
**P4 se avanza voglia.** Il nucleo del portale non si pubblica.
