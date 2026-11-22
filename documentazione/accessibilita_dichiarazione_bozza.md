# Dichiarazione di accessibilità — bozza dei contenuti

Per il modulo di **form.agid.gov.it** (Dichiarazione di accessibilità del sito «MyDiBEST / EventiDiBEST»).
Bozza del 21/11/2026, dalle verifiche del passo S8. È un controllo **interno e automatico** (scansioni del codice e del DOM sulle pagine di prova): non sostituisce una verifica con le tecnologie assistive né una valutazione esterna. Da completare e far rivedere prima di pubblicare.

Dopo la pubblicazione, l'indirizzo del modulo AgID si inserisce in **Pannello → Testata e home → «Link alla dichiarazione di accessibilità»**: nel piè di pagina del sito compare «Dichiarazione di accessibilità». Se il campo è vuoto, il link non compare.

## 1. Dati generali

| Voce | Contenuto |
|---|---|
| Ente | Università della Calabria — Dipartimento di Biologia, Ecologia e Scienze della Terra (DiBEST) |
| Sito | MyDiBEST — portale degli eventi, dei laboratori, della Formazione Scuola Lavoro e della modulistica (indirizzo di produzione da inserire) |
| Tipo | Sito web e area riservata (accesso con credenziali di Ateneo / SSO) |
| Standard | Linee guida AgID, WCAG 2.1 livello AA (UNI CEI EN 301 549) |
| Metodo di valutazione | Autovalutazione con controlli automatici del codice e del DOM (testi alternativi, etichette dei campi, contrasto, navigazione da tastiera) |
| Data della valutazione | 21/11/2026 |

## 2. Stato di conformità

**Parzialmente conforme** ai requisiti del livello AA delle WCAG 2.1 (da confermare con una verifica manuale).

### Cosa risulta conforme (verifiche di S8)

- **Immagini (1.1.1):** tutte le `<img>` del codice hanno l'attributo `alt` (descrittivo, oppure vuoto per quelle decorative). L'ultima senza alt, il logo nella ricevuta stampabile, è stata corretta.
- **Etichette dei campi (1.3.1, 3.3.2, 4.1.2):** ogni campo di modulo ha un nome accessibile (`label for`/`id`, `label` che lo contiene, `aria-label` o `aria-labelledby`). Le scansioni automatiche segnalavano 303 campi senza etichetta nelle pagine pubbliche e nel pannello; dopo la correzione ne restano 27, che sono falsi positivi della scansione (campi con etichetta costruita dal PHP con variabili, o un esempio dentro un commento CSS). Campi dei moduli personalizzati (iscrizioni, sondaggi): il nome è il testo della domanda.
- **Salto al contenuto (2.4.1):** «Vai al contenuto» è il primo elemento della pagina nel sito pubblico (`#main-content`) e nel pannello (`#contenuto-pannello`); compare con il focus da tastiera.
- **Pagina corrente (2.4.8 / 1.3.1):** la voce di menu che porta alla pagina aperta ha `aria-current="page"` (sito e pannello).
- **Focus visibile (2.4.7):** contorno giallo di 3 px con alone scuro su collegamenti, pulsanti, campi e riassunti, nei due temi (chiaro e scuro), sito e pannello.
- **Contrasto del testo (1.4.3):** le coppie testo/sfondo definite nelle variabili dei colori (`assets/css/dibest-colori.css`) hanno almeno 4,5:1 in tema chiaro e scuro (grigi secondari, badge di stato, testi dei moduli). Corretti: grigi secondari e «tenui», testi delle voci del menu mobile, pulsante «warning» del sito, pulsanti chiari/gialli del pannello in tema scuro, collegamenti blu del pannello, intestazione scura del riquadro «Ricerca».
- **Tema scuro e riduzione del movimento:** il sito rispetta `prefers-color-scheme` e `prefers-reduced-motion`; la scelta del tema si ricorda nel browser (chiave `tema_dibest`).
- **Testo ridimensionabile (1.4.4) e riflusso (1.4.10):** layout con unità relative; nessun testo negli stili rivisti sotto 0,8 rem, tranne i pulsanti-icona di 28 px o meno.

### Cosa non è (ancora) conforme o non è stato verificato

Da riportare nella sezione «Contenuti non accessibili» del modulo AgID:

1. **Contrasto residuo nelle pagine del pannello con colori scritti a mano:** restano stili in linea (circa 1.100 attributi `style=` nel codice) con colori esadecimali non ancora spostati nelle variabili; in alcune pagine rare il contrasto può scendere sotto 4,5:1. *Motivo:* migrazione dei colori in corso (passi S6-S7). *Data prevista:* da definire.
2. **Colori scelti dagli amministratori:** il colore delle aree e dei moduli (impostato nel pannello) non è controllato automaticamente; con colori molto chiari il testo su sfondo colorato può non raggiungere il contrasto richiesto (per i pulsanti il testo bianco/nero si sceglie in automatico, per i testi su fondo chiaro si ricade sul grigio scuro).
3. **Esito della scansione sul DOM** (contrasto a campione su 6 pagine in tema chiaro e scuro): le pagine pubbliche non mostrano altro che falsi positivi (testi bianchi su gradienti, non misurabili dallo strumento); nel pannello restano casi isolati, da riverificare a mano.
4. **Non verificati con tecnologie assistive:** lettori di schermo (NVDA, JAWS, VoiceOver) e ingrandimento oltre il 200%. Da fare con una prova manuale.
5. **Documenti PDF** (attestati, verbali, moduli, ricevute, badge): generati dal portale; non sono stati verificati come PDF accessibili (tag, ordine di lettura, lingua). Trattati come «contenuti esclusi/non accessibili» finché non verificati.
6. **Grafici e QR code** (statistiche del pannello, QR delle aule): non verificati; serve una descrizione testuale equivalente dove il dato è solo nel grafico.
7. **Contenuti inseriti dagli utenti** (locandine, descrizioni degli eventi con editor HTML, allegati): l'accessibilità dipende da chi li pubblica; il pannello non obbliga a inserire un testo alternativo alle locandine (attributo `alt` vuoto).
8. **Video e audio:** non risultano nel portale (se compaiono: sottotitoli e trascrizione).

## 3. Contatti per le segnalazioni (da completare)

- Responsabile dell'accessibilità / referente del sito: **[nome e ruolo — da inserire]**
- Indirizzo email per le segnalazioni: **[indirizzo — da inserire]**
- Telefono (facoltativo): **[da inserire]**
- Tempi di risposta indicati agli utenti: **[es. 30 giorni — da decidere]**
- Meccanismo di feedback: la chiave è la pagina «Assistenza» del portale (Didacticus) e la pagina Contatti; da indicare nel modulo.

## 4. Procedura di attuazione (da completare)

- In caso di risposta insoddisfacente o assente alla segnalazione, l'utente può rivolgersi al **Difensore civico per il digitale** (AgID): <https://form.agid.gov.it/> (sezione «Difensore civico per il digitale»).
- Data di pubblicazione del sito e ultima revisione della dichiarazione: **[da inserire]**. La dichiarazione va riverificata entro il 23 settembre di ogni anno.

## 5. Informazioni sulla redazione

- Redatta il 21/11/2026 da **[nome — da inserire]** sulla base di autovalutazione automatica (passo S8).
- Per riprodurre i controlli: scansione del codice delle `<img>` e dei campi dei moduli (script nella cartella di sessione, non nel repository), scansione del DOM per il contrasto con la pagina aperta nel browser, prova con tasto Tab su home e `admin/inizio.php`, schermate in tema scuro in `documentazione/schermate_S8/`.
