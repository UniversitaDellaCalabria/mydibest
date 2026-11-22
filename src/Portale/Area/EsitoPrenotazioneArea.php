<?php

declare(strict_types=1);

namespace App\Portale\Area;

/**
 * I messaggi con cui la pagina pubblica di un'area risponde dopo una prenotazione o un'aggiunta al programma FSL: la pagina riceve il rimando
 * ?status=…, ?programma=… e questa classe dice cosa scrivere (il testo HTML dell'avviso) e se mostrare la domanda «prenota ora o scegli altre».
 * Gli avvisi sulla convenzione e sui documenti delle classi vengono dall'esterno (due funzioni passate al costruttore): qui non c'è database.
 * Gli errori lasciati in sessione dal servizio (captcha, email, studenti) si leggono e si tolgono, come faceva la pagina.
 */
final class EsitoPrenotazioneArea
{
    /**
     * @param callable(array,string):string $istruzioniConvenzione (configurazione dell'area, codice) → riquadro con le istruzioni per la convenzione
     * @param callable(string):string $avvisoDocumenti (codice) → avviso sui documenti da caricare (attività FSL), vuoto se non serve
     */
    public function __construct(
        private $istruzioniConvenzione,
        private $avvisoDocumenti,
    ) {
    }

    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $sessione per riferimento: si leggono e si tolgono programma_aggiunta, programma_errore, errore_prenotazione
     * @param array<string,mixed> $pagina configurazione dell'area
     * @return array{messaggio: string, programma: array{titolo: string, sostituita: bool}|null} programma = l'attività appena aggiunta (domanda «prenota ora o scegli altre»)
     */
    public function da(array $get, array &$sessione, array $pagina, string $baseUrl): array
    {
        $messaggio = '';
        $programma = null;
        if (($get['programma'] ?? '') === 'ok' && !empty($sessione['programma_aggiunta'])) {
            $programma = $sessione['programma_aggiunta'];
            unset($sessione['programma_aggiunta']);
        } elseif (($get['programma'] ?? '') === 'errore' && !empty($sessione['programma_errore'])) {
            $e_prog = $sessione['programma_errore'];
            unset($sessione['programma_errore']);
            $messaggio = "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-danger'><i class='fa fa-clipboard-list me-2'></i> Non aggiunta al programma" . ($e_prog['titolo'] !== '' ? ' (' . htmlspecialchars($e_prog['titolo']) . ')' : '') . ': ' . htmlspecialchars((string)$e_prog['errore']) . '</div>';
        }
        if (isset($get['status'])) {
            $messaggio = $this->perStato((string)$get['status'], $get, $sessione, $pagina, $baseUrl, $messaggio);
        }

        return ['messaggio' => $messaggio, 'programma' => $programma];
    }

    /** @param array<string,mixed> $get @param array<string,mixed> $sessione */
    private function perStato(string $st, array $get, array &$sessione, array $pagina, string $baseUrl, string $messaggio): string
    {
        if ($st === 'success' && isset($get['code'])) {
            $codice_p = htmlspecialchars($get['code']);
            $link_btn = $baseUrl . "/stampa_ricevuta.php?code=" . urlencode($codice_p);
            $btn_scarica_pdf = "<a href='$link_btn' target='_blank' class='btn btn-danger btn-sm fw-bold ms-3'><i class='fa fa-file-pdf me-1'></i> Stampa Ricevuta</a>";
            $box_conv = "<div class='alert alert-warning text-start my-3 shadow-sm border-0 border-start border-5 border-warning small'><div class='fw-bold mb-1'><i class='fa fa-file-signature me-1'></i> Convenzione da stipulare</div>" . (($get['conv_rinnovo'] ?? '') === '1' ? "<p style='margin:0 0 8px;'><strong>La convenzione della scuola registrata al Dipartimento non copre tutto il periodo dell'attività: va stipulata una nuova convenzione.</strong></p>" : '') . ($this->istruzioniConvenzione)($pagina, $get['code']) . "</div>";
            if (isset($get['st_tipo']) && $get['st_tipo'] === 'convenzione') { $messaggio = "<div class='alert alert-info fw-bold text-center mt-4 mb-0 shadow-sm border-0 border-start border-5 border-info'><i class='fa fa-hourglass-half me-2'></i> Prenotazione registrata, in attesa della convenzione. Codice: <span class='badge bg-info text-dark ms-2'>$codice_p</span> $btn_scarica_pdf</div>" . $box_conv; }
            elseif (isset($get['st_tipo']) && $get['st_tipo'] === 'approvare') { $messaggio = "<div class='alert alert-info fw-bold text-center my-4 shadow-sm border-0 border-start border-5 border-info'><i class='fa fa-hourglass-half me-2'></i> Richiesta in approvazione! Codice: <span class='badge bg-info text-dark ms-2'>$codice_p</span> $btn_scarica_pdf</div>"; }
            elseif (isset($get['st_tipo']) && $get['st_tipo'] === 'attesa') { $messaggio = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-5 border-warning'><i class='fa fa-clock me-2'></i> In Lista d'Attesa! Codice: <span class='badge bg-warning text-dark ms-2'>$codice_p</span> $btn_scarica_pdf</div>" . (($get['conv'] ?? '') === 'no' ? $box_conv : ''); }
            else { $messaggio = "<div class='alert alert-success fw-bold text-center my-4 shadow-sm border-0 border-start border-5 border-success'><i class='fa fa-check-circle me-2'></i> Prenotazione confermata! Codice: <span class='badge bg-success ms-2'>$codice_p</span> $btn_scarica_pdf</div>"; }
            // Attività FSL: oltre alla convenzione, elenco degli studenti e autorizzazione della scuola da caricare prima dell'inizio
            $messaggio .= ($this->avvisoDocumenti)((string)$get['code']);
        } elseif ($st === 'dup') { $messaggio = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-exclamation-triangle me-2'></i> Prenotazione già esistente per questo turno con la stessa email.</div>"; }
        elseif ($st === 'full') { $messaggio = "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-danger'><i class='fa fa-exclamation-circle me-2'></i> Posti esauriti per questo turno.</div>"; }
        elseif ($st === 'closed') { $messaggio = "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-danger'><i class='fa fa-times-circle me-2'></i> Le prenotazioni sono chiuse.</div>"; }
        elseif ($st === 'notopened') { $messaggio = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-clock me-2'></i> Le prenotazioni non sono ancora aperte.</div>"; }
        elseif ($st === 'limite') {
            $ev_bloc = htmlspecialchars($get['ev'] ?? '');
            $regola = (($pagina['limite_iscrizioni'] ?? '') === 'un_turno') ? "In quest'area puoi prenotare un solo turno per ciascun evento." : "In quest'area puoi iscriverti a un solo evento/gruppo.";
            $messaggio = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-user-lock me-2'></i> $regola Risulti già iscritto a: <strong>$ev_bloc</strong>. Per cambiare, annulla prima la prenotazione dall'Area Personale.</div>";
        }
        elseif ($st === 'riservato') { $messaggio = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-key me-2'></i> Per iscriverti devi prima accedere (SPID, CIE o credenziali Unical).</div>"; }
        elseif ($st === 'altra_edizione') { $messaggio = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-school me-2'></i> La tua scuola è già iscritta (o in lista d'attesa) a un'altra edizione di questo progetto: puoi partecipare a una sola edizione. Per cambiare, annulla prima l'iscrizione dall'Area Personale.</div>"; }
        elseif ($st === 'captcha') {
            $err_cp = $sessione['errore_prenotazione'] ?? 'Controllo anti-robot non superato.';
            unset($sessione['errore_prenotazione']);
            $messaggio = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-shield-halved me-2'></i> " . htmlspecialchars($err_cp) . "</div>";
        }
        elseif ($st === 'email') {
            $err_em = $sessione['errore_prenotazione'] ?? "Controlla l'indirizzo email.";
            unset($sessione['errore_prenotazione']);
            $messaggio = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-envelope me-2'></i> Prenotazione non registrata: " . htmlspecialchars($err_em) . "</div>";
        }
        elseif ($st === 'studenti') {
            $err_st = $sessione['errore_prenotazione'] ?? 'Numero di studenti non valido.';
            unset($sessione['errore_prenotazione']);
            $messaggio = "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-danger'><i class='fa fa-users me-2'></i> Iscrizione non registrata: " . htmlspecialchars($err_st) . "</div>";
        }
        elseif ($st === 'error') { $messaggio = "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-danger'>Errore di registrazione. Compilare tutti i campi obbligatori.</div>"; }

        return $messaggio;
    }
}
