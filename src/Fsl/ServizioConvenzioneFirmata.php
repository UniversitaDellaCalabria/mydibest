<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\File;
use App\Core\Sito;
use App\Infrastructure\Pdf\MessaggiFirma;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Infrastructure\Storage\Upload;
use App\Protocollo\ProtocolloRepository;

/**
 * Convenzione firmata in PAdES caricata dalla scuola (convenzione_online.php): controlli sul file, salvataggio in una cartella bloccata al
 * web, sostituzione finché l'ufficio non la prende in carico, avvisi a chi gestisce le convenzioni e ricevuta alla scuola.
 * Il file si scarica solo dal pannello (admin/convenzione_file.php) o dalla pagina con il link personale della scuola.
 */
final class ServizioConvenzioneFirmata
{
    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private ServizioConvenzioni $convenzioni,
        private NotificheConvenzioni $notifiche,
        private ServizioFirmaDirettore $direttore,
        private VerificaFirme $firme,
        private Upload $upload,
        private Sito $sito,
        private ProtocolloRepository $protocollo
    ) {
    }

    /**
     * Un invio del protocollo in entrata di questa compilazione è in corso adesso (il blocco del documento è preso da un processo)? Mentre dura, la scuola non
     * può sostituire il file e l'ufficio non può rifiutarlo: il numero che Titulus sta per dare è del file che è partito.
     *
     * @param array<string, mixed> $cc
     */
    private function invioInEntrataInCorso(array $cc): bool
    {
        return $this->protocollo->invioInCorso(ProtocollaConvenzioneInTitulus::RIF, (int) ($cc['id'] ?? 0));
    }

    /** La scuola può caricare (o sostituire) il file finché l'ufficio non lo ha preso in carico; dopo un rifiuto lo ricarica. */
    public static function caricabile(array $cc): bool
    {
        return in_array((string) ($cc['stato_iter'] ?? ''), ['', Costanti::ITER_CARICATA, Costanti::ITER_RIFIUTATA], true);
    }

    /**
     * Carica i file arrivati da un modulo (elementi di $_FILES): la convenzione firmata e l'Allegato A. Ritorna il messaggio di errore da mostrare,
     * null se è andato a buon fine.
     *
     * @param array<string, mixed> $cc riga di convenzioni_compilate
     * @param array<string, mixed>|null $file convenzione firmata
     * @param array<string, mixed>|null $fileAllegato Allegato A (PDF, anche senza firma)
     */
    public function caricaDaUpload(array $cc, ?array $file, ?array $fileAllegato = null): ?string
    {
        [$errore, $contenuto] = $this->leggiUpload($file, 'Scegli il file della convenzione firmata.');
        if ($errore !== null) {
            return $errore;
        }
        $allegato = '';
        if (($fileAllegato['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$errore, $allegato] = $this->leggiUpload($fileAllegato, 'Scegli il file dell\'Allegato A.');
            if ($errore !== null) {
                return 'Allegato A: ' . lcfirst($errore);
            }
        }

        return $this->carica($cc, (string) $contenuto, (string) $allegato);
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array{0: string|null, 1: string|null} [errore, contenuto]
     */
    private function leggiUpload(?array $file, string $mancante, string $dove = 'convenzione della scuola'): array
    {
        $errore = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errore === UPLOAD_ERR_NO_FILE || $file === null) {
            return [$mancante, null];
        }
        if ($errore === UPLOAD_ERR_INI_SIZE || $errore === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > Costanti::MAX_FIRMATA) {
            return [MessaggiFirma::rifiuta($dove, MessaggiFirma::GRANDE, [], (int) (Costanti::MAX_FIRMATA / 1048576)), null];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($errore !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            return ['Il caricamento non è riuscito: riprova.', null];
        }
        $c = file_get_contents($tmp);

        return [null, $c === false ? '' : $c];
    }

    /**
     * Controlla e salva il PDF firmato e l'Allegato A. Il primo caricamento richiede entrambi; una sostituzione può cambiare solo la convenzione
     * (l'Allegato A resta quello già caricato). Ritorna il messaggio di errore da mostrare, null se è andato a buon fine.
     *
     * @param array<string, mixed> $cc riga di convenzioni_compilate
     */
    public function carica(array $cc, string $pdf, string $allegato = ''): ?string
    {
        try {
            // Sotto il blocco per istituto, con la riga riletta: se nel frattempo è stata unita a un'altra, presa in carico o cambiata, non si scrive sopra
            return $this->compilate->sottoBloccoDi($cc, fn (): ?string => $this->caricaSottoBlocco($this->compilate->perId((int) $cc['id']) ?: $cc, $pdf, $allegato));
        } catch (BloccoOccupato $e) {
            return $e->getMessage();
        }
    }

    /** @param array<string, mixed> $cc riga appena riletta sotto il blocco */
    private function caricaSottoBlocco(array $cc, string $pdf, string $allegato): ?string
    {
        if ((string) ($cc['stato_iter'] ?? '') === Costanti::ITER_UNITA) {
            return "Questa compilazione è stata unita a un'altra dal Dipartimento: il file non è stato caricato. Apri il link giusto che ti è arrivato per email.";
        }
        if (!self::caricabile($cc)) {
            return "La convenzione è già stata presa in carico dal Dipartimento: non si può più sostituire. Se serve una correzione, scrivi all'ufficio.";
        }
        if (!empty($cc['file_firmato']) && $this->invioInEntrataInCorso($cc)) {
            return 'Il Dipartimento sta protocollando proprio adesso il file che hai caricato: non si può sostituire in questo momento. Riprova tra poco.';
        }
        if ($pdf === '' || strlen($pdf) > Costanti::MAX_FIRMATA) {
            return MessaggiFirma::rifiuta('convenzione della scuola', $pdf === '' ? MessaggiFirma::VUOTO : MessaggiFirma::GRANDE, [], (int) (Costanti::MAX_FIRMATA / 1048576));
        }
        if (strncmp($pdf, '%PDF-', 5) !== 0) {
            return MessaggiFirma::rifiuta('convenzione della scuola', MessaggiFirma::causaNonPdf($pdf));
        }
        $firme = $this->firme->firmePades($pdf);
        $vere = MessaggiFirma::senzaMarche($firme);
        if (!$vere) {
            return MessaggiFirma::rifiuta('convenzione della scuola', MessaggiFirma::SENZA_FIRMA, $firme);
        }
        // Firma non integra o file modificato dopo la firma non bloccano: li legge l'ufficio prima di prendere in carico
        $avviso = MessaggiFirma::avvisi($firme);
        if ($allegato === '' && empty($cc['file_allegato_scuola'])) {
            return 'Carica anche l\'Allegato A (PDF): la convenzione e l\'Allegato A si inviano insieme.';
        }
        if (self::erroreAllegato($allegato) !== null) {
            return (string) self::erroreAllegato($allegato);
        }

        $cartella = $this->sito->radice() . '/' . Costanti::DIR_FIRMATE;
        $this->upload->cartellaProtetta($cartella, 'Convenzioni firmate dalle scuole: si scaricano solo dal pannello e dalla pagina con il link personale');
        $nome = bin2hex(random_bytes(16)) . '.pdf';
        $nomeAllegato = $allegato !== '' ? bin2hex(random_bytes(16)) . '.pdf' : null;
        if (file_put_contents($cartella . $nome, $pdf) === false || ($nomeAllegato !== null && file_put_contents($cartella . $nomeAllegato, $allegato) === false)) {
            @unlink($cartella . $nome);

            return 'Non riesco a salvare il file: riprova tra poco.';
        }
        $sostituita = !empty($cc['file_firmato']);
        $vecchio = $sostituita ? $this->percorsoSicuro((string) $cc['file_firmato']) : null;
        $vecchioAllegato = $nomeAllegato !== null && !empty($cc['file_allegato_scuola']) ? $this->percorsoSicuro((string) $cc['file_allegato_scuola']) : null;
        $firmatario = mb_substr(trim((string) ($vere[array_key_last($vere)]['nome'] ?? '')), 0, 255);
        if (!$this->compilate->salvaFirmata((int) $cc['id'], Costanti::DIR_FIRMATE . $nome, $firmatario, $nomeAllegato !== null ? Costanti::DIR_FIRMATE . $nomeAllegato : null, $avviso)) {
            // nel frattempo la convenzione è andata avanti: i file nuovi non servono e quelli di prima restano com'erano
            File::togli($cartella . $nome);
            if ($nomeAllegato !== null) {
                File::togli($cartella . $nomeAllegato);
            }

            return 'La convenzione è cambiata nel frattempo (il Dipartimento l\'ha già presa in carico): il file non è stato caricato. Ricarica la pagina.';
        }
        foreach ([$vecchio, $vecchioAllegato] as $v) {
            if ($v !== null) {
                @unlink($v);
            }
        }

        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $scuola = (string) (($dati['scuola']['denominazione'] ?? '') ?: 'Scuola');
        $destinatari = array_values(array_unique(array_map('strtolower', array_merge($this->compilate->emailAbilitati(), [Costanti::EMAIL_UFFICIO]))));
        $this->notifiche->firmataCaricata($scuola, $firmatario, $sostituita, $destinatari, (int) $cc['id']);
        $this->notifiche->firmataRicevuta((string) ($cc['email'] ?? ''), $scuola, (string) $cc['token']);

        return null;
    }

    /**
     * L'ufficio registra il protocollo in entrata (solo numero e data: il file non si ricarica): la convenzione è presa in carico, la scuola
     * lo sa e, se il Direttore è indicato, gli arriva subito l'email con il link per firmare. $allegato (facoltativo): un Allegato A che si aggiunge
     * o prende il posto di quello della scuola.
     * Ritorna ['errore' => ?string, 'direttore' => ?string]: «direttore» è null se il link è partito, altrimenti il perché non è partito.
     *
     * @return array{errore: string|null, direttore: string|null}
     */
    public function protocolloIngresso(int $id, string $numero, string $data, string $autore, string $allegato = '', ?string $improntaInviata = null): array
    {
        $ko = static fn (string $m): array => ['errore' => $m, 'direttore' => null];
        $prima = $this->compilate->perId($id);
        if (!$prima) {
            return $ko('Non c\'è nessuna convenzione firmata da protocollare.');
        }
        try {
            return $this->compilate->sottoBloccoDi($prima, fn (): array => $this->protocolloIngressoSottoBlocco($id, $numero, $data, $autore, $allegato, $improntaInviata));
        } catch (BloccoOccupato $e) {
            return $ko($e->getMessage());
        }
    }

    /**
     * Il file con l'impronta SHA-256 $inviata (quello che è partito per Titulus) è ancora quello della compilazione? null se sì (o se non si controlla),
     * altrimenti il messaggio per l'ufficio: Titulus ha dato il numero $numero a un file che non c'è più, e non si registra su quello nuovo.
     *
     * @param array<string, mixed> $cc riga appena riletta sotto il blocco
     */
    private function erroreFileCambiato(array $cc, string $colonna, string $cartella, ?string $inviata, string $numero): ?string
    {
        if ($inviata === null) {
            return null;
        }
        $attuale = $this->contenutoColonna($cc, $colonna, $cartella);
        if ($attuale !== null && hash_equals($inviata, hash('sha256', $attuale))) {
            return null;
        }

        return 'Titulus ha dato il numero ' . $numero . ' al file di prima: nel frattempo il file di questa convenzione è cambiato, quindi il numero NON è stato registrato sul file nuovo. '
            . 'Controlla in Titulus e poi usa «Invia al protocollo» (il file nuovo avrà un numero suo) oppure scrivi a mano il numero che vuoi tenere.';
    }

    /** @return array{errore: string|null, direttore: string|null} */
    private function protocolloIngressoSottoBlocco(int $id, string $numero, string $data, string $autore, string $allegato, ?string $improntaInviata = null): array
    {
        $ko = static fn (string $m): array => ['errore' => $m, 'direttore' => null];
        $cc = $this->compilate->perId($id);   // riletta sotto il blocco: se è stata unita o cambiata nel frattempo lo stato lo dice
        if (!$cc || empty($cc['file_firmato'])) {
            return $ko('Non c\'è nessuna convenzione firmata da protocollare.');
        }
        if ((string) $cc['stato_iter'] !== Costanti::ITER_CARICATA) {
            return $ko('La convenzione non è in attesa del protocollo in entrata.');
        }
        $numero = mb_substr(trim($numero), 0, 80);
        if ($numero === '') {
            return $ko('Indica il numero del protocollo in entrata.');
        }
        $errore = $this->erroreFileCambiato($cc, 'file_firmato', Costanti::DIR_FIRMATE, $improntaInviata, $numero);
        if ($errore !== null) {
            return $ko($errore);
        }
        $errore = $this->sostituisciAllegato($cc, $allegato);
        if ($errore !== null) {
            return $ko($errore);
        }
        $dataOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? $data : null;
        if (!$this->compilate->protocolloIngresso($id, $numero, $dataOk, $autore)) {
            return $ko('La convenzione è cambiata nel frattempo (è stata rifiutata o presa in carico da un altro): il protocollo non è stato registrato. Ricarica la pagina.');
        }
        $testo = $numero . ($dataOk ? ' del ' . date('d/m/Y', (int) strtotime($dataOk)) : '');
        [$scuola, $email] = $this->contatti($cc);
        $this->notifiche->presaInCarico($email, $scuola, (string) $cc['token'], $testo);

        return ['errore' => null, 'direttore' => $this->direttore->invia($id)];
    }

    /** L'Allegato A caricato dall'ufficio ('' = nessuno): perché non va bene, null se va bene. */
    public static function erroreAllegato(string $allegato): ?string
    {
        return $allegato !== '' && (strncmp($allegato, '%PDF-', 5) !== 0 || strlen($allegato) > Costanti::MAX_FIRMATA)
            ? 'L\'Allegato A deve essere un PDF (anche senza firma) fino a ' . (Costanti::MAX_FIRMATA / 1048576) . ' MB.' : null;
    }

    /**
     * L'ufficio aggiunge o sostituisce l'Allegato A della compilazione ($allegato '' = resta quello che c'è). Il file vecchio si cancella solo dopo
     * che il nuovo è salvato. Ritorna il messaggio di errore, null se è andato a buon fine.
     *
     * @param array<string, mixed> $cc riga di convenzioni_compilate appena letta
     */
    public function sostituisciAllegato(array $cc, string $allegato): ?string
    {
        if ($allegato === '') {
            return null;
        }
        $errore = self::erroreAllegato($allegato);
        if ($errore !== null) {
            return $errore;
        }
        $vecchio = !empty($cc['file_allegato_scuola']) ? $this->percorsoSicuro((string) $cc['file_allegato_scuola']) : null;
        if ($vecchio !== null && file_get_contents($vecchio) === $allegato) {
            return null;
        }
        $nuovo = $this->scriviNuovo(Costanti::DIR_FIRMATE, $allegato);
        if ($nuovo === null) {
            return 'Non riesco a salvare l\'Allegato A: riprova tra poco.';
        }
        if (!$this->compilate->sostituisciAllegato((int) $cc['id'], (string) $cc['stato_iter'], $cc['file_allegato_scuola'], $nuovo)) {
            File::togli($this->sito->radice() . '/' . $nuovo);

            return 'L\'Allegato A non è stato salvato (nel frattempo la convenzione è cambiata): ricarica la pagina e riprova.';
        }
        if ($vecchio !== null) {
            File::togli($vecchio);
        }

        return null;
    }

    /** Salva un PDF con un nome casuale in una cartella bloccata al web; ritorna il percorso relativo, null se non riesce (niente file a metà). */
    private function scriviNuovo(string $dir, string $contenuto): ?string
    {
        $cartella = $this->sito->radice() . '/' . $dir;
        $this->upload->cartellaProtetta($cartella, $dir === Costanti::DIR_FIRMATE
            ? 'Convenzioni firmate dalle scuole: si scaricano solo dal pannello e dalla pagina con il link personale'
            : 'Convenzioni firmate: si scaricano solo dal pannello (admin/convenzione_file.php)');
        $nome = bin2hex(random_bytes(16)) . '.pdf';
        if (@file_put_contents($cartella . $nome, $contenuto) !== strlen($contenuto)) {
            File::togli($cartella . $nome);

            return null;
        }

        return $dir . $nome;
    }

    /** L'ufficio rifiuta il file con un motivo (obbligatorio): la scuola lo riceve per email e può caricare un altro file. */
    public function rifiuta(int $id, string $motivo): ?string
    {
        $prima = $this->compilate->perId($id);
        if (!$prima) {
            return 'Questa convenzione non si può rifiutare in questo momento.';
        }
        try {
            return $this->compilate->sottoBloccoDi($prima, fn (): ?string => $this->rifiutaSottoBlocco($id, $motivo));
        } catch (BloccoOccupato $e) {
            return $e->getMessage();
        }
    }

    private function rifiutaSottoBlocco(int $id, string $motivo): ?string
    {
        $cc = $this->compilate->perId($id);   // riletta sotto il blocco
        $motivo = trim($motivo);
        if (!$cc || empty($cc['file_firmato']) || !in_array((string) $cc['stato_iter'], [Costanti::ITER_CARICATA, Costanti::ITER_IN_CARICO, Costanti::ITER_DA_FIRMARE], true)) {
            return 'Questa convenzione non si può rifiutare in questo momento.';
        }
        if ($motivo === '') {
            return 'Scrivi il motivo del rifiuto: la scuola lo riceve per email.';
        }
        if ($this->invioInEntrataInCorso($cc)) {
            return 'Il file di questa convenzione sta andando in questo momento al protocollo: non si può rifiutare adesso. Riprova tra poco.';
        }
        if (!$this->compilate->rifiuta($id, $motivo)) {
            return 'Questa convenzione non si può rifiutare in questo momento: è cambiata nel frattempo. Ricarica la pagina.';
        }
        [$scuola, $email] = $this->contatti($cc);
        $this->notifiche->rifiutata($email, $scuola, mb_substr($motivo, 0, 500), (string) $cc['token']);

        return null;
    }

    /**
     * Un PDF facoltativo di un modulo dell'ufficio (elemento di $_FILES): contenuto '' se non è stato scelto. Un file scelto ma arrivato male
     * (troppo grande, vuoto, caricamento interrotto) è un errore: non si va avanti come se non ci fosse.
     *
     * @param array<string, mixed>|null $file
     * @return array{0: string|null, 1: string} [errore, contenuto]
     */
    public function leggiFacoltativo(?array $file, string $cosa): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, ''];
        }
        [$errore, $contenuto] = $this->leggiUpload($file, '', 'file caricato dall\'ufficio');
        if ($errore === null && (string) $contenuto === '') {
            $errore = MessaggiFirma::per(MessaggiFirma::VUOTO);
        }

        return [$errore !== null ? $cosa . ': ' . lcfirst($errore) : null, (string) $contenuto];
    }

    /**
     * Ricarico finale: la convenzione firmata anche dal Direttore, protocollata. Si registra nel registro delle convenzioni (con validità e file),
     * le prenotazioni della scuola in attesa della convenzione si confermano (e le persone sono avvisate) e la scuola riceve l'avviso.
     * $dati: protocollo (numero), protocollo_data, data_stipula (valida dal), scadenza (valida fino al), docenti (righe ['nome', 'email']).
     * $pdf vuoto = si usa il PDF già firmato dal Direttore sul portale (stato «firmata_direttore»).
     * $allegato vuoto = nel registro va l'Allegato A della scuola; altrimenti va questo (il file della scuola resta con la compilazione).
     *
     * @param array<string, mixed> $dati
     * @return array{errore: string|null, prenotazioni: int, convenzione_id: int}
     */
    public function completa(int $id, string $pdf, array $dati, string $autore, string $allegato = '', ?string $improntaInviata = null): array
    {
        $ko = static fn (string $m): array => ['errore' => $m, 'prenotazioni' => 0, 'convenzione_id' => 0];
        $prima = $this->compilate->perId($id);
        if (!$prima) {
            return $ko('Convenzione non trovata.');
        }
        try {
            // Sotto il blocco per istituto, con la riga riletta: due clic (o l'automatico e un pulsante) insieme non fanno due registrazioni
            return $this->compilate->sottoBloccoDi($prima, fn (): array => $this->completaSottoBlocco($id, $pdf, $dati, $autore, $allegato, $improntaInviata));
        } catch (BloccoOccupato $e) {
            return $ko($e->getMessage());
        }
    }

    /**
     * La registrazione vera, in una transazione: o c'è tutto (riga del registro con i file, stato «protocollata») o non c'è niente, e si può ripetere
     * senza doppioni. Le prenotazioni e le email partono dopo, con il resoconto di ciascuna.
     *
     * @param array<string, mixed> $dati
     * @return array{errore: string|null, prenotazioni: int, convenzione_id: int, esito?: array<string, mixed>}
     */
    private function completaSottoBlocco(int $id, string $pdf, array $dati, string $autore, string $allegato, ?string $improntaInviata = null): array
    {
        $ko = static fn (string $m): array => ['errore' => $m, 'prenotazioni' => 0, 'convenzione_id' => 0];
        $cc = $this->compilate->perId($id);   // riletta sotto il blocco
        if (!$cc) {
            return $ko('Convenzione non trovata.');
        }
        if ((string) $cc['stato_iter'] === Costanti::ITER_PROTOCOLLATA) {
            return $ko('Questa convenzione è già stata protocollata e registrata.');
        }
        $codice = ConvenzioneRepository::codiceValido((string) ($cc['scuola_codice'] ?? ''));
        if ($codice === null) {
            return $ko('La scuola non è collegata all\'anagrafe: registra la convenzione dal modulo «Registra, modifica o rinnova» scegliendo la scuola.');
        }
        $protocollo = mb_substr(trim((string) ($dati['protocollo'] ?? '')), 0, 80);
        if ($protocollo === '') {
            return $ko('Indica il numero di protocollo.');
        }
        $dataOk = static fn ($x): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $x) ? (string) $x : null;
        $dataProt = $dataOk($dati['protocollo_data'] ?? null);
        $dal = $dataOk($dati['data_stipula'] ?? null);
        $al = $dataOk($dati['scadenza'] ?? null);
        if (!$dal || !$al || $al < $dal) {
            return $ko('Controlla le date di validità: «valida fino al» non può precedere «valida dal».');
        }
        $errore = $this->erroreFileCambiato($cc, 'file_definitivo', Costanti::DIR_CONVENZIONI, $improntaInviata, $protocollo) ?? $this->erroreFile($cc, $pdf, $allegato);
        if ($errore !== null) {
            return $ko($errore);
        }
        // Il documento protocollato caricato a mano dall'ufficio, se c'è, è quello che vedono la scuola e il registro (il PDF del Direttore non si tocca)
        if ($pdf === '' && ($aMano = $this->contenutoColonna($cc, 'file_protocollato', Costanti::DIR_CONVENZIONI)) !== null) {
            $pdf = $aMano;
        }
        // File nuovi: se qualcosa non va si cancellano; quelli della compilazione (scuola e Direttore) non si toccano
        $nuovi = [];
        $butta = function () use (&$nuovi): void {
            foreach ($nuovi as $n) {
                File::togli($this->sito->radice() . '/' . $n);
            }
        };
        $percorso = (string) ($cc['file_definitivo'] ?? '');
        if (!$this->usaQuelloDelDirettore($cc, $pdf)) {
            $percorso = $this->scriviNuovo(Costanti::DIR_CONVENZIONI, $pdf);
            if ($percorso === null) {
                return $ko('Non riesco a salvare il file: riprova tra poco.');
            }
            $nuovi[] = $percorso;
        }
        // L'Allegato A va nel registro insieme alla convenzione (quello della scuola si copia nella cartella delle convenzioni)
        $contenutoAllegato = $allegato !== '' ? $allegato : (string) $this->allegatoDellaScuola($cc);
        $percorsoAllegato = null;
        if ($contenutoAllegato !== '') {
            $percorsoAllegato = $this->scriviNuovo(Costanti::DIR_CONVENZIONI, $contenutoAllegato);
            if ($percorsoAllegato === null) {
                $butta();

                return $ko('Non riesco a salvare l\'Allegato A: riprova tra poco.');
            }
            $nuovi[] = $percorsoAllegato;
        }
        $testoProt = $protocollo . ($dataProt ? ' del ' . date('d/m/Y', (int) strtotime($dataProt)) : '');
        try {
            $convId = $this->compilate->transazione(function () use ($codice, $dal, $al, $testoProt, $percorso, $percorsoAllegato, $dati, $autore, $id, $dataProt): int {
                $esito = $this->convenzioni->salva([
                    'scuola_codice' => $codice, 'data_stipula' => $dal, 'scadenza' => $al, 'protocollo' => $testoProt,
                    'note' => 'Da convenzione caricata dalla scuola (compilazione online)', 'file_convenzione' => $percorso, 'file_allegato' => $percorsoAllegato,
                    'docenti' => DocentiConvenzione::senzaDoppioni(array_values(array_filter((array) ($dati['docenti'] ?? []), 'is_array'))),
                ], 0, $autore, false);
                if ($esito === null) {
                    throw new \RuntimeException('Convenzione non registrata: controlla che la scuola sia nell\'anagrafe e le date.');
                }
                if (!$this->compilate->segnaProtocollata($id, $esito[0], $testoProt, $dataProt)) {
                    throw new \RuntimeException('La convenzione è cambiata nel frattempo (è già stata registrata o unita): non è stato salvato niente. Ricarica la pagina.');
                }

                return $esito[0];
            });
        } catch (\Throwable $e) {
            $butta();
            if (!$e instanceof \RuntimeException) {
                error_log('[convenzione FSL] registrazione annullata: ' . $e->getMessage());
            }

            return $ko($e instanceof \RuntimeException ? $e->getMessage() : 'La registrazione non è riuscita e non è stato salvato niente: riprova tra poco.');
        }
        // La convenzione è registrata. Ora le prenotazioni in attesa (e le loro email) e l'avviso alla scuola: se qualcosa si ferma a metà, ripetiApplicazione() riprende da dove era
        $rep = $this->applica($id, $codice);
        [$scuola, $email] = $this->contatti($cc);
        $this->notifiche->attiva($email, $scuola, (string) $cc['token'], $testoProt, $dal, $al, $rep['coperte']);

        return ['errore' => null, 'prenotazioni' => $rep['coperte'], 'convenzione_id' => $convId, 'esito' => $rep];
    }

    /**
     * Conferma le prenotazioni coperte dalla convenzione e salva il resoconto sulla compilazione (si legge dall'ufficio). Non lancia eccezioni.
     *
     * @return array<string, mixed>
     */
    private function applica(int $id, string $codice): array
    {
        try {
            $rep = $this->convenzioni->applicaConEsito($codice);
        } catch (\Throwable $e) {
            error_log('[convenzione FSL] applicazione alle prenotazioni interrotta: ' . $e->getMessage());
            $rep = ['coperte' => 0, 'approvate' => 0, 'restano' => [], 'senza_codice' => 0, 'errore' => "L'approvazione delle prenotazioni si è interrotta: premi «Ripeti l'approvazione»."];
        }
        $rep['il'] = date('Y-m-d H:i:s');
        $this->compilate->salvaEsitoRegistrazione($id, (string) json_encode($rep, JSON_UNESCAPED_UNICODE));

        return $rep;
    }

    /**
     * Riprende l'approvazione delle prenotazioni di una convenzione già registrata (dopo un'interruzione, o dopo aver confermato un'attività «da confermare»).
     * Si può ripetere senza effetti doppi: le prenotazioni già coperte non si toccano e non ricevono altre email.
     *
     * @return array<string, mixed>|null il resoconto, null se la convenzione non è registrata
     */
    public function ripetiApplicazione(int $id): ?array
    {
        $cc = $this->compilate->perId($id);
        $codice = $cc ? ConvenzioneRepository::codiceValido((string) ($cc['scuola_codice'] ?? '')) : null;
        if (!$cc || $codice === null || (string) $cc['stato_iter'] !== Costanti::ITER_PROTOCOLLATA) {
            return null;
        }

        return $this->applica($id, $codice);
    }

    /**
     * L'ufficio carica il documento protocollato (la copia con la segnatura): vale più della copia stampigliata dal portale, anche a iter concluso
     * (allora prende il posto del file della convenzione nel registro). Il PDF firmato dal Direttore non si modifica mai.
     */
    public function caricaProtocollato(int $id, string $pdf): ?string
    {
        $prima = $this->compilate->perId($id);
        if (!$prima || !in_array((string) $prima['stato_iter'], [Costanti::ITER_FIRMATA, Costanti::ITER_PROTOCOLLATA], true)) {
            return 'Il documento protocollato si carica dopo la firma del Direttore.';
        }
        if ($pdf === '' || strncmp($pdf, '%PDF-', 5) !== 0 || strlen($pdf) > Costanti::MAX_FIRMATA) {
            return 'Il documento protocollato deve essere un PDF fino a ' . (Costanti::MAX_FIRMATA / 1048576) . ' MB.';
        }
        try {
            return $this->compilate->sottoBloccoDi($prima, function () use ($id, $pdf): ?string {
                $cc = $this->compilate->perId($id);
                if (!$cc) {
                    return 'Convenzione non trovata.';
                }
                $nuovo = $this->scriviNuovo(Costanti::DIR_CONVENZIONI, $pdf);
                if ($nuovo === null) {
                    return 'Non riesco a salvare il file: riprova tra poco.';
                }
                $vecchio = (string) ($cc['file_protocollato'] ?? '');
                $this->compilate->salvaProtocollato($id, $nuovo);
                if ((int) ($cc['convenzione_id'] ?? 0) > 0) {
                    // il registro ha la sua copia: i file non si condividono tra due righe
                    $copia = $this->scriviNuovo(Costanti::DIR_CONVENZIONI, $pdf);
                    if ($copia !== null) {
                        $primaNelRegistro = $this->convenzioni->sostituisciFileConvenzione((int) $cc['convenzione_id'], $copia);
                        foreach (array_unique(array_filter([$primaNelRegistro])) as $p) {
                            // mai il PDF del Direttore, che resta com'è
                            if ($p !== (string) ($cc['file_definitivo'] ?? '') && $p !== $vecchio) {
                                File::togli($this->sito->radice() . '/' . $p);
                            }
                        }
                    }
                }
                if ($vecchio !== '' && $vecchio !== $nuovo) {
                    File::togli($this->sito->radice() . '/' . $vecchio);
                }

                return null;
            });
        } catch (BloccoOccupato $e) {
            return $e->getMessage();
        }
    }

    /** Il contenuto di un file della compilazione (solo dentro la sua cartella), null se non c'è o non si legge. @param array<string, mixed> $cc */
    private function contenutoColonna(array $cc, string $colonna, string $cartella): ?string
    {
        $p = !empty($cc[$colonna]) ? $this->percorsoSicuro((string) $cc[$colonna], $cartella) : null;
        $c = $p !== null ? file_get_contents($p) : false;

        return $c === false || $c === '' ? null : $c;
    }

    /** C'è un documento protocollato caricato a mano? @param array<string, mixed> $cc */
    public function haProtocollatoAMano(array $cc): bool
    {
        return $this->contenutoColonna($cc, 'file_protocollato', Costanti::DIR_CONVENZIONI) !== null;
    }

    /**
     * Il riferimento con cui Titulus conosce il protocollo in entrata di questa compilazione (tipo + impronta del file della scuola): serve a prenotare
     * l'invio e a ritrovare il documento. Senza file leggibile vale il tipo di base.
     *
     * @param array<string, mixed> $cc
     */
    public function rifIngresso(array $cc): string
    {
        $c = $this->contenutoColonna($cc, 'file_firmato', Costanti::DIR_FIRMATE);

        return $c !== null ? \App\Protocollo\InvioProtocollo::rifPerFile(ProtocollaConvenzioneInTitulus::RIF, $c) : ProtocollaConvenzioneInTitulus::RIF;
    }

    /** Come rifIngresso(), per il protocollo definitivo (file firmato dal Direttore). @param array<string, mixed> $cc */
    public function rifDefinitivo(array $cc): string
    {
        $c = $this->contenutoColonna($cc, 'file_definitivo', Costanti::DIR_CONVENZIONI);

        return $c !== null ? \App\Protocollo\InvioProtocollo::rifPerFile(ProtocollaDefinitivaFslInTitulus::RIF, $c) : ProtocollaDefinitivaFslInTitulus::RIF;
    }

    /**
     * «Controlla la convenzione»: validità proposta (dal / al) e docenti decisi dall'ufficio, salvati sulla compilazione. Date vuote = non decise.
     *
     * @param list<array<string, mixed>> $docenti righe ['nome', 'email']
     */
    public function salvaControllo(int $id, ?string $dal, ?string $al, array $docenti): ?string
    {
        $ok = static fn (?string $x): ?string => $x !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $x) ? $x : null;
        $dal = $ok($dal);
        $al = $ok($al);
        if ($dal !== null && $al !== null && $al < $dal) {
            return 'Controlla le date di validità: «valida fino al» non può precedere «valida dal».';
        }
        $prima = $this->compilate->perId($id);
        if (!$prima || in_array((string) $prima['stato_iter'], ['', Costanti::ITER_PROTOCOLLATA, Costanti::ITER_UNITA, Costanti::ITER_RIFIUTATA], true)) {
            return 'Questa convenzione non è in lavorazione: le date e i docenti non si possono salvare.';
        }
        $righe = DocentiConvenzione::senzaDoppioni(array_values(array_filter($docenti, 'is_array')));
        try {
            return $this->compilate->sottoBloccoDi($prima, function () use ($id, $dal, $al, $righe): ?string {
                $this->compilate->salvaControllo($id, $dal, $al, $righe ? (string) json_encode($righe, JSON_UNESCAPED_UNICODE) : null);

                return null;
            });
        } catch (BloccoOccupato $e) {
            return $e->getMessage();
        }
    }

    /**
     * I controlli sui file della registrazione finale, tutti prima di salvare (e prima di chiedere il numero a Titulus): perché non vanno bene,
     * null se vanno bene.
     *
     * @param array<string, mixed> $cc riga di convenzioni_compilate
     */
    public function erroreFile(array $cc, string $pdf, string $allegato): ?string
    {
        if (!$this->usaQuelloDelDirettore($cc, $pdf)) {
            if (strncmp($pdf, '%PDF-', 5) !== 0 || strlen($pdf) > Costanti::MAX_FIRMATA) {
                return 'Carica il PDF della convenzione firmata (fino a ' . (Costanti::MAX_FIRMATA / 1048576) . ' MB).';
            }
            if (!MessaggiFirma::senzaMarche($this->firme->firmePades($pdf))) {
                return 'Nel PDF non trovo una firma digitale PAdES: serve la convenzione firmata in PAdES, i file .p7m non sono accettati.';
            }
        }
        $errore = self::erroreAllegato($allegato);
        if ($errore === null && $allegato === '' && !empty($cc['file_allegato_scuola']) && $this->allegatoDellaScuola($cc) === null) {
            return 'L\'Allegato A della scuola non si legge: caricalo di nuovo qui.';
        }

        return $errore;
    }

    /** Senza un PDF caricato vale quello firmato dal Direttore sul portale. @param array<string, mixed> $cc */
    private function usaQuelloDelDirettore(array $cc, string $pdf): bool
    {
        return $pdf === '' && (string) $cc['stato_iter'] === Costanti::ITER_FIRMATA && !empty($cc['file_definitivo']) && is_file($this->sito->radice() . '/' . $cc['file_definitivo']);
    }

    /** Il contenuto dell'Allegato A della compilazione, null se non c'è o non si legge. @param array<string, mixed> $cc */
    private function allegatoDellaScuola(array $cc): ?string
    {
        $p = !empty($cc['file_allegato_scuola']) ? $this->percorsoSicuro((string) $cc['file_allegato_scuola']) : null;
        $c = $p !== null ? file_get_contents($p) : false;

        return $c === false || $c === '' ? null : $c;
    }

    /**
     * Nome della scuola ed email a cui scrivere (quella della compilazione).
     *
     * @param array<string, mixed> $cc
     * @return array{0: string, 1: string}
     */
    private function contatti(array $cc): array
    {
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];

        // le email con il link personale vanno solo all'email della compilazione (quella scritta nel modulo serve per le comunicazioni, non per i link)
        return [(string) (($dati['scuola']['denominazione'] ?? '') ?: 'Scuola'), (string) ($cc['email'] ?? '')];
    }

    /**
     * Il file firmato di una compilazione da scaricare: percorso reale (solo dentro la cartella delle firmate) e nome del download.
     *
     * @return array{percorso: string, nome: string}|null
     */
    public function fileDaScaricare(int $id, string $quale = 'scuola'): ?array
    {
        $cc = $this->compilate->perId($id);
        $direttore = $quale === 'direttore';
        // il PDF del Direttore, l'originale ripreso da Titulus e il documento protocollato a mano stanno nella cartella delle convenzioni; quelli della scuola in quella delle firmate
        $inConvenzioni = in_array($quale, ['direttore', 'originale', 'protocollato'], true);
        $colonna = match ($quale) {
            'direttore' => 'file_definitivo', 'originale' => 'file_originale', 'protocollato' => 'file_protocollato',
            'allegato' => 'file_allegato_scuola', default => 'file_firmato',
        };
        $percorso = $cc && !empty($cc[$colonna]) ? $this->percorsoSicuro((string) $cc[$colonna], $inConvenzioni ? Costanti::DIR_CONVENZIONI : Costanti::DIR_FIRMATE) : null;
        if ($percorso === null) {
            return null;
        }
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $sigla = preg_replace('/[^A-Za-z0-9]+/', '_', (string) (($dati['scuola']['codice'] ?? '') ?: ($dati['scuola']['denominazione'] ?? '') ?: 'scuola'));

        $prefisso = match ($quale) {
            'direttore' => 'Convenzione_firmata_dal_Direttore_', 'originale' => 'Convenzione_originale_da_Titulus_', 'protocollato' => 'Convenzione_protocollata_',
            'allegato' => 'Allegato_A_', default => 'Convenzione_firmata_',
        };

        return ['percorso' => $percorso, 'nome' => $prefisso . trim((string) $sigla, '_') . '.pdf'];
    }

    /**
     * Il documento che la scuola vede come «convenzione protocollata»: quello caricato a mano dall'ufficio, se c'è; altrimenti la copia stampigliata dal portale
     * («copia per consultazione»), che è il file della convenzione registrata. Il PDF firmato dal Direttore non è mai modificato.
     *
     * @return array{percorso: string, nome: string}|null
     */
    public function documentoProtocollatoPerScuola(int $id): ?array
    {
        $cc = $this->compilate->perId($id);
        if (!$cc || (string) ($cc['stato_iter'] ?? '') !== Costanti::ITER_PROTOCOLLATA) {
            return null;
        }
        if (!empty($cc['file_protocollato'])) {
            return $this->fileDaScaricare($id, 'protocollato');
        }
        $rel = !empty($cc['convenzione_id']) ? $this->convenzioni->fileConvenzione((int) $cc['convenzione_id']) : null;
        $percorso = $rel !== null ? $this->percorsoSicuro($rel, Costanti::DIR_CONVENZIONI) : null;
        if ($percorso === null) {
            return null;
        }
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $sigla = preg_replace('/[^A-Za-z0-9]+/', '_', (string) (($dati['scuola']['codice'] ?? '') ?: ($dati['scuola']['denominazione'] ?? '') ?: 'scuola'));

        return ['percorso' => $percorso, 'nome' => 'Convenzione_protocollata_' . trim((string) $sigla, '_') . '.pdf'];
    }

    /** Percorso reale del file solo se sta dentro la cartella delle convenzioni firmate. */
    private function percorsoSicuro(string $relativo, string $cartella = Costanti::DIR_FIRMATE): ?string
    {
        $base = realpath($this->sito->radice() . '/' . rtrim($cartella, '/'));
        $percorso = realpath($this->sito->radice() . '/' . $relativo);
        if (!$base || !$percorso || strpos($percorso, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($percorso)) {
            return null;
        }

        return $percorso;
    }
}
