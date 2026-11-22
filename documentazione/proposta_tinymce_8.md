# Proposta: da TinyMCE 6.8.6 a TinyMCE 8 (copia locale, licenza GPL)

Stato: **solo proposta (22/11/2026), niente è stato scaricato né cambiato.** Si fa in un passo a parte, dopo il via libera.

## Perché
- La serie 6 non è più supportata: la versione open source ha chiuso il **31/10/2024** (nessuna correzione di sicurezza), il supporto a pagamento il 06/06/2025. L'ultima è la 6.8.x (6.8.6 è quella che abbiamo).
- Il portale mette l'editor in mano a gestori e docenti e salva HTML: senza correzioni di sicurezza conviene aggiornare.

## Versione
- **TinyMCE 8.10.x**, edizione **Community** (GPLv2+). Dalla pagina delle note di rilascio l'ultima è la **8.10.2** (11/11/2026, scritta per Enterprise/Tiny Cloud); un tracker esterno (Snyk) dà **8.10.1** come ultima su npm. Quale delle due sia nello ZIP Community si vede solo al momento del download: si prende l'ultima disponibile.
- Si salta la 7: è già superata dalla 8 e 7 e 8 hanno lo stesso meccanismo di licenza.
- **Dimensione**: il sito non la indica. La 6.8.6 attuale pesa 3,3 MB in 177 file; per la 8 mi aspetto lo stesso ordine di grandezza (da misurare dopo il download).

## File da sostituire
- Cartella `assets/vendor/tinymce/6.8.6/` → `assets/vendor/tinymce/8.10.x/` (tutta: `tinymce.min.js`, `models`, `plugins`, `skins`, `themes`, `icons`, `license.txt`). Prima si tiene la vecchia, poi si toglie.
- `langs/it.js`: la 6.8.6 ha il suo; per la 8 serve il pacchetto lingua italiano aggiornato (nelle note di migrazione i file lingua passano al formato RFC 5646). Da scaricare con la stessa procedura.

## Riferimenti nel portale da cambiare
- `admin/admin_footer.php`: riga 12 (script), righe 21–22 (`language_url`, `base_url`), commento «TinyMCE 6, MIT»; due `tinymce.init`: `textarea.editor-html` e `textarea.editor-breve`.
- `richiesta_pubblicazione.php`: righe 364 (`data-tinymce-base`, `data-tinymce-it`) e 419 (script).
- `assets/js/richiesta-pubblicazione.js`: un `tinymce.init` (editor semplice della descrizione).
- Le pagine che usano l'editor del pannello: messaggi, iscritti, impostazioni area, eventi, testata, progetti, nuova area, sedute, email, comunicazioni, forms, modulo documento, amministrazione impostazioni.

## Cosa cambia nelle configurazioni
- **`license_key: 'gpl'` in tutti e tre i `tinymce.init`.** Dalla 7 in poi è obbligatoria: senza, l'editor si disattiva con un avviso. (Con `'gpl'` si accettano i termini GPLv2+; i plugin premium non ci sono e non servono: usiamo solo plugin del nucleo.)
- Resto delle opzioni (`selector`, `plugins`, `toolbar`, `language_url`, `base_url`, `suffix`, `branding`, `promotion`, `valid_elements`, `images_upload_handler`): le note di migrazione non segnalano modifiche, ma non le ho trovate elencate una per una; **si verificano provando ogni editor.**
- Novità di comportamento da conoscere: `highlight_on_focus` è acceso di serie (cornice sull'editor con il fuoco), i `text_patterns` scattano con Spazio invece che con Invio, `convert_unsafe_embeds` e `sandbox_iframes` sono accesi (i video incorporati con `media` possono avere iframe in sandbox), DOMPurify aggiornato.
- Plugin che usiamo: tutti del nucleo (advlist, autolink, lists, link, image, media, table, charmap, anchor, searchreplace, visualblocks, code, codesample, fullscreen, insertdatetime, preview, emoticons, wordcount, autoresize, help, pagebreak, nonbreaking, directionality, quickbars, accordion). Il plugin `template`, rimosso dalla 7, **non** lo usiamo.

## Come si prova (nel passo a parte)
1. Scarico il solo pacchetto Community e il pacchetto lingua italiano, in una cartella a parte; controllo versione e `license.txt`.
2. Sostituisco cartella e riferimenti, aggiungo `license_key: 'gpl'`.
3. Provo in locale: apertura dell'editor in ciascuna pagina elencata, caricamento di un'immagine (`editor_upload.php`), incolla di un'immagine, video, tabella, salvataggio e rilettura del testo; editor breve con il contatore; editor della richiesta di pubblicazione.
4. Console del browser senza errori; schermate prima/dopo di una pagina con l'editor.

## Fonti (pagine ufficiali e tracker)
- https://www.tiny.cloud/docs/tinymce/6/support/ (date di fine supporto della serie 6)
- https://www.tiny.cloud/docs/tinymce/latest/license-key/ (`license_key: 'gpl'`, GPLv2+)
- https://www.tiny.cloud/docs/tinymce/latest/migration-from-6x/ (cambiamenti da 6 a 8)
- https://www.tiny.cloud/docs/tinymce/latest/release-notes/ e https://www.tiny.cloud/get-tiny/self-hosted/ (ultima versione, download Community)
- https://security.snyk.io/package/npm/tinymce (8.10.1 come ultima su npm, dato di terzi)
