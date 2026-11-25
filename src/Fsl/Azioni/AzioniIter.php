<?php

declare(strict_types=1);

namespace App\Fsl\Azioni;

use App\Core\Container;
use App\Fsl\BloccoOccupato;
use App\Fsl\ConvenzioneCompilataRepository;
use App\Fsl\DocentiConvenzione;
use App\Fsl\OriginaleDaTitulus;
use App\Fsl\ProtocollaConvenzioneInTitulus;
use App\Fsl\ProtocollaDefinitivaFslInTitulus;
use App\Fsl\ServizioConvenzioneFirmata;
use App\Fsl\ServizioConvenzioneOnline;
use App\Fsl\ServizioFirmaDirettore;
use App\Fsl\ServizioReferenteConvenzione;
use App\Fsl\ServizioUnioneConvenzioni;
use App\Http\Richiesta;
use App\Protocollo\InvioProtocollo;

/**
 * Le azioni dell'ufficio sull'iter di una convenzione compilata dalla scuola: dal controllo del PDF e la presa in carico, al protocollo in entrata,
 * alla firma del Direttore, al protocollo del firmato e alla registrazione; l'esito incerto di Titulus; il referente; le compilazioni doppie; l'avvio.
 *
 * Sono le stesse azioni che c'erano in admin/fsl.php, con gli stessi servizi, gli stessi testi e lo stesso registro delle operazioni: ora le usano
 * sia la pagina «Scuole e convenzioni» sia (finché il passo 9 non la toglie) admin/fsl.php. Il token CSRF lo verifica chi chiama.
 * Chi chiama deve avere già controllato che la compilazione sia della scuola aperta (AppartenenzaScuolaFsl).
 */
final class AzioniIter
{
    public const AZIONI = [
        'cv_controllo', 'cv_prendi', 'cv_titulus', 'cv_rifiuta', 'cv_invia_direttore', 'cv_titulus_def', 'cv_completa', 'cv_carica_protocollato',
        'cv_riprova_originale', 'cv_incerto_chiudi', 'cv_respinto', 'cv_ripeti_applicazione', 'att_conferma', 'att_scarta', 'cv_cambia_referente', 'cv_unisci',
        'avvia_conferma',
    ];

    public function __construct(
        private readonly ServizioConvenzioneFirmata $iter,
        private readonly ServizioFirmaDirettore $direttore,
        private readonly ConvenzioneCompilataRepository $compilate,
        private readonly ServizioConvenzioneOnline $online,
        private readonly ServizioReferenteConvenzione $referente,
        private readonly ServizioUnioneConvenzioni $unione,
        private readonly Container $contenitore,   // Titulus e il recupero dell'originale si costruiscono solo quando servono: senza Titulus configurato non partono
    ) {
    }

    public function conosce(string $azione): bool
    {
        return in_array($azione, self::AZIONI, true);
    }

    /**
     * @param array{id: int, email: string, nome: string} $operatore
     * @return array{messaggio: string, tono: string, audit: ?array{0: string, 1: array<string, mixed>}, ancora: string}
     */
    public function esegui(Richiesta $r, string $azione, string $istituto, array $operatore): array
    {
        $id = (int) $r->post($azione);
        $autore = $operatore['email'];
        $utente = $operatore['id'] > 0 ? $operatore['id'] : null;

        return match ($azione) {
            'cv_controllo' => $this->controllo($r, $id),
            'cv_prendi' => $this->primaDi($r, $id, fn (string $allegato): array => $this->prendi($r, $id, $autore, $allegato)),
            'cv_titulus' => $this->primaDi($r, $id, fn (string $allegato): array => $this->titulus($id, $autore, $utente, $allegato)),
            'cv_completa' => $this->primaDi($r, $id, fn (string $allegato): array => $this->completa($r, $id, $autore, $allegato)),
            'cv_titulus_def' => $this->titulusDef($r, $id, $autore, $utente),
            'cv_rifiuta' => $this->rifiuta($id, (string) $r->post('motivo', '')),
            'cv_invia_direttore' => $this->mandaAlDirettore($id),
            'cv_carica_protocollato' => $this->caricaProtocollato($r, $id),
            'cv_riprova_originale' => $this->riprovaOriginale($id),
            'cv_incerto_chiudi', 'cv_respinto' => $this->esitoIncerto((string) $r->post($azione), $azione === 'cv_respinto', $operatore),
            'cv_ripeti_applicazione' => $this->ripetiApplicazione($id),
            'att_conferma', 'att_scarta' => $this->attivitaSegnalata((string) $r->post($azione), $azione === 'att_conferma'),
            'cv_cambia_referente' => $this->cambiaReferente((int) $r->post('cv_referente_id', 0), (string) $r->post('nuova_email', ''), $autore),
            'cv_unisci' => $this->unisci((int) $r->post('unisci_resta', 0), (int) $r->post('unisci_assorbita', 0)),
            'avvia_conferma' => $this->avvia($istituto, (string) $r->post('email', ''), $autore, $r->post('copia_dati') !== null),
        };
    }

    /**
     * Gli id di compilazione che l'azione tocca (per il controllo «è di questa scuola»): tutti, nell'ordine in cui compaiono.
     *
     * @return list<int>
     */
    public static function compilazioniDi(Richiesta $r, string $azione): array
    {
        return match ($azione) {
            'cv_unisci' => [(int) $r->post('unisci_resta', 0), (int) $r->post('unisci_assorbita', 0)],
            'cv_cambia_referente' => [(int) $r->post('cv_referente_id', 0)],
            'att_conferma', 'att_scarta' => [(int) explode(':', (string) $r->post($azione) . ':0')[0]],
            'cv_incerto_chiudi', 'cv_respinto' => [(int) explode(':', (string) $r->post($azione) . ':0')[0]],
            'avvia_conferma' => [],
            default => [(int) $r->post($azione)],
        };
    }

    /** Le prenotazioni dell'azione (att_conferma e att_scarta portano «compilazione:prenotazione»). @return list<int> */
    public static function prenotazioniDi(Richiesta $r, string $azione): array
    {
        if ($azione !== 'att_conferma' && $azione !== 'att_scarta') {
            return [];
        }

        return [(int) (explode(':', (string) $r->post($azione) . ':0')[1] ?? 0)];
    }

    /**
     * «Controlla la convenzione»: validità e docenti decisi dall'ufficio si salvano sulla compilazione PRIMA di qualsiasi azione di presa in carico o
     * registrazione (il protocollo automatico dopo la firma usa questi). Un errore ferma tutto.
     */
    private function primaDi(Richiesta $r, int $id, callable $azione): array
    {
        if ($id > 0 && $r->post('data_stipula') !== null) {
            $errore = $this->iter->salvaControllo($id, (string) $r->post('data_stipula', ''), (string) $r->post('scadenza', ''), DocentiConvenzione::dalModulo($r->datiModulo()));
            if ($errore !== null) {
                return Esito::fa($errore, 'danger');
            }
        }
        // Allegato A caricato dall'ufficio alla presa in carico o alla registrazione (facoltativo): un file arrivato male ferma tutto
        [$erroreAllegato, $allegato] = $this->iter->leggiFacoltativo($r->file('allegato_a'), 'Allegato A');
        if ($erroreAllegato !== null) {
            return Esito::fa($erroreAllegato, 'danger');
        }

        return $azione($allegato);
    }

    private function controllo(Richiesta $r, int $id): array
    {
        $errore = $this->iter->salvaControllo($id, (string) $r->post('data_stipula', ''), (string) $r->post('scadenza', ''), DocentiConvenzione::dalModulo($r->datiModulo()));

        return Esito::daErrore($errore, 'Date di validità e docenti salvati sulla convenzione.', 'Convenzione FSL: validità e docenti salvati',
            ['Compilazione' => $id, 'Valida dal' => (string) $r->post('data_stipula', ''), 'Valida fino al' => (string) $r->post('scadenza', '')], 'convenzione');
    }

    private function titulus(int $id, string $autore, ?int $utente, string $allegato): array
    {
        // Il protocollo in entrata lo assegna Titulus: la convenzione firmata (e l'Allegato A) partono come documento in arrivo
        $es = $this->contenitore->get(ProtocollaConvenzioneInTitulus::class)->protocolla($id, $autore, $utente, $allegato);
        if ($es['errore'] !== null) {
            return Esito::fa((string) $es['errore'], 'danger');
        }

        return Esito::fa('Protocollata in Titulus con il numero ' . $es['numero'] . ': la scuola è stata avvisata. ' . ($es['direttore'] === null ? "Il Direttore ha ricevuto l'email con il link per firmare." : $es['direttore']),
            $es['direttore'] === null ? 'success' : 'warning', ['Convenzione FSL protocollata in entrata con Titulus', ['Compilazione' => $id, 'Protocollo in entrata' => $es['numero']]], 'convenzione');
    }

    private function prendi(Richiesta $r, int $id, string $autore, string $allegato): array
    {
        $es = $this->contenitore->get(ProtocollaConvenzioneInTitulus::class)->protocolloAMano($id, (string) $r->post('prot_ingresso', ''), (string) $r->post('prot_ingresso_data', ''), $autore, $allegato);
        if ($es['errore'] !== null) {
            return Esito::fa((string) $es['errore'], 'danger');
        }

        return Esito::fa('Protocollo in entrata registrato: la scuola è stata avvisata. ' . ($es['direttore'] === null ? "Il Direttore ha ricevuto l'email con il link per firmare." : $es['direttore']),
            $es['direttore'] === null ? 'success' : 'warning', ['Convenzione FSL protocollata in entrata', ['Compilazione' => $id, 'Protocollo in entrata' => (string) $r->post('prot_ingresso', '')]], 'convenzione');
    }

    private function rifiuta(int $id, string $motivo): array
    {
        $errore = $this->iter->rifiuta($id, $motivo);

        return Esito::daErrore($errore, 'Convenzione rifiutata: la scuola ha ricevuto il motivo per email e può caricare un altro file.', 'Convenzione FSL rifiutata',
            ['Compilazione' => $id, 'Motivo' => mb_substr($motivo, 0, 200)], 'convenzione', 'warning');
    }

    private function mandaAlDirettore(int $id): array
    {
        $errore = $this->direttore->invia($id);

        return Esito::daErrore($errore, "Mandata al Direttore: ha ricevuto l'email con il link per firmare. Quando firma ti avvisiamo.", 'Convenzione FSL mandata al Direttore per la firma', ['Compilazione' => $id], 'convenzione');
    }

    /** Protocollo definitivo: il numero lo assegna Titulus (PDF firmato dal Direttore), la convenzione si registra con le date indicate. */
    private function titulusDef(Richiesta $r, int $id, string $autore, ?int $utente): array
    {
        if ($r->post('data_stipula') !== null) {
            $this->iter->salvaControllo($id, (string) $r->post('data_stipula', ''), (string) $r->post('scadenza', ''), DocentiConvenzione::dalModulo($r->datiModulo()));   // un errore qui lo ridice protocolla() con le stesse date
        }
        [$erroreF, $pdf] = $this->iter->leggiFacoltativo($r->file('file_definitivo'), 'PDF della convenzione');
        [$erroreA, $allegato] = $this->iter->leggiFacoltativo($r->file('allegato_a'), 'Allegato A');
        $es = ($erroreF ?? $erroreA) !== null ? ['errore' => $erroreF ?? $erroreA]
            : $this->contenitore->get(ProtocollaDefinitivaFslInTitulus::class)->protocolla($id, ['docenti' => DocentiConvenzione::dalModulo($r->datiModulo())] + $r->datiModulo(), $pdf, $autore, $utente, $allegato);
        if ($es['errore'] !== null) {
            return Esito::fa((string) $es['errore'], 'danger');
        }

        return Esito::fa('Protocollata in Titulus con il numero ' . $es['numero'] . ': convenzione registrata. ' . ($es['prenotazioni'] ? $es['prenotazioni'] . ' prenotazioni della scuola in attesa sono state coperte dalla convenzione (le persone sono state avvisate per email). ' : '')
            . "La scuola ha ricevuto l'avviso che la convenzione è attiva. Il resoconto delle prenotazioni e il file originale di Titulus sono nella scheda della scuola.", 'success',
            ['Convenzione FSL protocollata (definitivo) con Titulus', ['Compilazione' => $id, 'Convenzione' => $es['convenzione_id'], 'Protocollo' => $es['numero'], 'Prenotazioni confermate' => $es['prenotazioni']]], 'convenzione');
    }

    private function completa(Richiesta $r, int $id, string $autore, string $allegato): array
    {
        [$erroreF, $pdf] = $this->iter->leggiFacoltativo($r->file('file_definitivo'), 'PDF della convenzione');
        $es = $erroreF !== null ? ['errore' => $erroreF]
            : $this->contenitore->get(ProtocollaDefinitivaFslInTitulus::class)->completaAMano($id, $pdf, ['docenti' => DocentiConvenzione::dalModulo($r->datiModulo())] + $r->datiModulo(), $autore, $allegato);
        if ($es['errore']) {
            return Esito::fa((string) $es['errore'], 'danger');
        }

        return Esito::fa('Convenzione registrata. ' . ($es['prenotazioni'] ? $es['prenotazioni'] . ' prenotazioni della scuola in attesa sono state coperte dalla convenzione (le persone sono state avvisate per email). ' : '')
            . "La scuola ha ricevuto l'avviso che la convenzione è attiva. Il resoconto delle prenotazioni è nella scheda della scuola.", 'success',
            ['Convenzione FSL protocollata e registrata', ['Compilazione' => $id, 'Convenzione' => $es['convenzione_id'], 'Protocollo' => (string) $r->post('protocollo', ''), 'Prenotazioni confermate' => $es['prenotazioni']]], 'convenzione');
    }

    private function caricaProtocollato(Richiesta $r, int $id): array
    {
        [$errore, $pdf] = $this->iter->leggiFacoltativo($r->file('protocollato'), 'Documento protocollato');
        $errore ??= $pdf === '' ? 'Scegli il PDF del documento protocollato.' : $this->iter->caricaProtocollato($id, $pdf);

        return Esito::daErrore($errore, 'Documento protocollato caricato: è quello che vedono la scuola e il registro. Il PDF firmato dal Direttore non è stato toccato.', "Documento protocollato caricato dall'ufficio", ['Compilazione' => $id], 'convenzione');
    }

    private function riprovaOriginale(int $id): array
    {
        $es = $this->contenitore->get(OriginaleDaTitulus::class)->scarica($id);

        return Esito::fa($es['ok'] ? 'File originale ripreso da Titulus e conservato.' : 'Da Titulus il file non arriva: ' . $es['messaggio'], $es['ok'] ? 'success' : 'warning',
            $es['ok'] ? ['File originale ripreso da Titulus', ['Compilazione' => $id]] : null, 'convenzione');
    }

    /** Esito incerto: la risposta di Titulus non è arrivata. L'ufficio cerca il documento in Titulus; se non c'è, si può riprovare (se c'è, scrive il numero a mano). */
    private function esitoIncerto(string $valore, bool $respinto, array $operatore): array
    {
        [$id, $fase] = array_pad(explode(':', $valore, 2), 2, '');
        $cc = $this->compilate->perId((int) $id);
        if (!$cc) {
            return Esito::fa('Compilazione non trovata.', 'danger');
        }
        $rif = $fase === 'ingresso' ? $this->iter->rifIngresso($cc) : $this->iter->rifDefinitivo($cc);
        $chi = $operatore['email'] !== '' ? $operatore['email'] : 'ufficio';
        $utente = $operatore['id'] > 0 ? $operatore['id'] : null;
        $invio = $this->contenitore->get(InvioProtocollo::class);
        $errore = $respinto
            ? $invio->respinto($rif, (int) $id, 'Respinto in Titulus, dichiarato da ' . $chi, $utente)
            : $invio->chiudiIncerto($rif, (int) $id, 'Cercato in Titulus da ' . $chi . ': il documento non c\'è', $utente);

        return Esito::fa($errore ?? ($respinto ? 'Registrato: in Titulus il documento è stato respinto. Ora puoi premere di nuovo «Invia al protocollo».' : 'Registrato: hai verificato che in Titulus il documento non c\'è. Ora puoi premere di nuovo «Invia al protocollo».'),
            $errore ? 'danger' : 'success', $errore ? null : [$respinto ? 'Documento respinto in Titulus: si può rimandare' : 'Esito incerto verificato in Titulus: documento non protocollato', ['Compilazione' => (int) $id, 'Fase' => $fase]], 'convenzione');
    }

    private function ripetiApplicazione(int $id): array
    {
        $rep = $this->iter->ripetiApplicazione($id);
        if ($rep === null) {
            return Esito::fa('Questa convenzione non è registrata.', 'danger');
        }
        if (isset($rep['errore'])) {
            return Esito::fa((string) $rep['errore'], 'danger');
        }

        return Esito::fa('Fatto: ' . $rep['coperte'] . ' prenotazioni coperte dalla convenzione, ' . $rep['approvate'] . ' approvate; ' . count($rep['restano']) . ' restano come sono.', 'success',
            ['Approvazione delle prenotazioni ripetuta', ['Compilazione' => $id, 'Coperte' => $rep['coperte'], 'Approvate' => $rep['approvate']]], 'convenzione');
    }

    /** Attività segnalate da un'altra email: confermate o scartate dall'ufficio. */
    private function attivitaSegnalata(string $valore, bool $conferma): array
    {
        [$idCompilazione, $prenotazione] = array_map('intval', explode(':', $valore . ':0'));
        $cc = $this->compilate->perId($idCompilazione);
        try {
            $ok = $cc && $this->online->decidiInAttesa($cc, $prenotazione, $conferma);
        } catch (BloccoOccupato) {
            return Esito::fa(BloccoOccupato::MESSAGGIO, 'danger');
        }

        return Esito::fa($ok ? ($conferma ? "Attività confermata: ora è nell'Allegato A." : 'Attività scartata.') : "Quell'attività non è più da confermare.", $ok ? 'success' : 'warning',
            $ok ? [$conferma ? 'Attività segnalata confermata nella convenzione FSL' : 'Attività segnalata scartata dalla convenzione FSL', ['Compilazione' => $idCompilazione, 'Prenotazione' => $prenotazione]] : null, 'convenzione');
    }

    /** «Cambia referente»: nuovo indirizzo e NUOVO link (il vecchio smette di funzionare); non per le compilazioni protocollate o unite. */
    private function cambiaReferente(int $id, string $nuovaEmail, string $da): array
    {
        $cc = $id > 0 ? $this->compilate->perId($id) : null;
        $vecchia = (string) ($cc['email'] ?? '');
        $errore = $this->referente->cambia($id, $nuovaEmail, $da !== '' ? $da : 'Ufficio');

        return Esito::daErrore($errore, 'Referente cambiato: il vecchio link non funziona più e il nuovo indirizzo ha ricevuto il link nuovo.', 'Referente della convenzione FSL cambiato',
            ['Compilazione' => $id, 'Vecchia email' => $vecchia, 'Nuova email' => strtolower(trim($nuovaEmail))], 'convenzione');
    }

    /** Compilazioni doppie della stessa scuola: l'ufficio sceglie quella che resta. */
    private function unisci(int $resta, int $assorbita): array
    {
        $errore = $this->unione->unisci($resta, $assorbita);

        return Esito::daErrore($errore, "Compilazioni unite: le attività e i messaggi sono nella n. $resta; a chi aveva fatto l'altra è arrivato il link giusto.", 'Compilazioni della convenzione FSL unite',
            ['Resta' => $resta, 'Unita' => $assorbita], 'convenzione');
    }

    /** «Avvia la stipula» o «Rinnova» dalla scheda: UNA compilazione, il link per email solo al referente scelto. */
    private function avvia(string $istituto, string $email, string $da, bool $copiaDati): array
    {
        if ($istituto === '') {
            return Esito::fa("Questa scuola è scritta a mano e non ha un codice meccanografico: abbinala prima all'anagrafe, poi avvia la convenzione.", 'danger');
        }
        $es = $this->online->avviaDalPannello($istituto, $email, $da !== '' ? $da : 'ufficio', $copiaDati);
        if ($es['errore'] !== null) {
            return Esito::fa((string) $es['errore'], 'danger');
        }
        $testo = match ($es['esito']) {
            'creata' => "Convenzione avviata: il link per compilarla è partito per email a $email. Solo chi lo apre con quell'indirizzo verificato ne diventa il referente.",
            'ripresa' => "C'era già una convenzione in corso per questa scuola: non ne ho aperta una seconda. " . ($es['link_cambiato']
                ? "Nessuno l'aveva ancora rivendicata, quindi il link di prima e il codice della prenotazione non aprono più: il link nuovo è partito per email a $email."
                : "Il link è stato rimandato a $email."),
            default => "Il link è già partito da meno di 10 minuti: non lo rimando. Se $email non l'ha ricevuto, riprova più tardi.",
        };

        return Esito::fa($testo, $es['esito'] === 'gia_inviata' ? 'warning' : 'success', null, 'convenzione');
    }
}
