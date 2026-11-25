<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Core\Sito;
use App\Email\InvioEmail;
use App\Infrastructure\Storage\Upload;
use App\Iscrizioni\RegistroOperazioni;

/**
 * Convenzione FSL e Allegato A compilati online dalla scuola con un modulo guidato (convenzione_online.php): dati dell'istituto e
 * del Dirigente, logo, attività dell'Allegato A (quelle già prenotate e altre ancora da prenotare). Accesso con il codice della
 * prenotazione o con il link personale (token).
 */
final class ServizioConvenzioneOnline
{
    /** Loghi delle scuole: cartella non raggiungibile dal web, usati solo per creare i documenti. */
    private const CARTELLA_LOGHI = 'uploads/convenzioni_compilate/';

    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private PrenotazioneFslRepository $prenotazioni,
        private PrecompilazioneConvenzione $precompilazione,
        private DocumentoConvenzione $documenti,
        private ModelliConvenzione $modelli,
        private ServizioScuole $scuole,
        private Upload $upload,
        private InvioEmail $invio,
        private Sito $sito,
        private AllegatoAPdf $allegatoPdf,
        private AllegatoAWord $allegatoWord,
        private PeriodiConvenzione $periodi,
        private ConvenzioneRepository $registro,
        private AllegatoConvenzioneRepository $allegati,
        private RegistroOperazioni $operazioni
    ) {
    }

    /** Oltre questo numero le attività segnalate da altre email non si accumulano: chi ha il codice di una prenotazione non può riempire la pagina di un altro. */
    private const MAX_IN_ATTESA = 40;

    /** Quello che si dice a chi ha il codice di una prenotazione quando per la sua scuola c'è già una convenzione in corso: lo stesso per email uguale e diversa. */
    public const MESSAGGIO_GIA_IN_CORSO = "Per la tua scuola c'è già una convenzione in corso: abbiamo scritto a chi la sta compilando. Se sei tu, trovi il link nella tua email.";

    /**
     * «Ho perso il link»: rimanda per email il link personale delle compilazioni fatte con quell'indirizzo (le più recenti). Chi chiede non
     * sa se ne esiste una: la risposta a schermo è sempre la stessa. Ritorna quante email sono partite.
     */
    public function rimandaLink(string $email): int
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 0;
        }
        $n = 0;
        foreach ($this->compilate->perEmail($email) as $cc) {
            $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
            $scuola = (string) (($dati['scuola']['denominazione'] ?? '') ?: 'la tua scuola');
            if ($this->invio->invia($email, 'fsl_link_ripreso', ['scuola' => $scuola], $this->sito->urlBase() . '/convenzione_online.php?t=' . $cc['token'])) {
                ++$n;
            }
        }

        return $n;
    }

    /** @return array<string, mixed>|null */
    public function perToken(string $token): ?array
    {
        return $this->compilate->perToken($token);
    }

    /**
     * Le email di chi può aprire la compilazione: l'elenco esplicito salvato con la compilazione (per le righe di prima, l'email della compilazione, di
     * chi ha fatto la prima prenotazione e delle compilazioni unite). L'email scritta nel modulo NON rende proprietari. Non basta scrivere un'email
     * in una prenotazione per esserlo: serve l'email verificata dell'account (vedi apriDaCodice) o il codice della prenotazione di nascita.
     *
     * @param array<string, mixed> $cc
     * @return list<string>
     */
    public function proprietari(array $cc): array
    {
        return $this->compilate->proprietari($cc);
    }

    /** @param array<string, mixed> $cc */
    public function eProprietario(array $cc, string $email): bool
    {
        $email = strtolower(trim($email));

        return $email !== '' && in_array($email, $this->proprietari($cc), true);
    }

    /**
     * L'email VERIFICATA di chi chiede (null = nessuna: non ha fatto l'accesso o l'email non è verificata) è tra i proprietari. Una compilazione non
     * rivendicata ancora in fase di compilazione ('' o rifiutata) non ha proprietari: la rivendica l'email verificata uguale a quella della compilazione
     * (rivendicaSeSua); dagli altri stati in poi (file già caricato) si apre senza cambiare il token, con l'elenco o con l'email della compilazione.
     *
     * @param array<string, mixed> $cc
     */
    private function eProprietarioVerificato(array $cc, ?string $emailVerificata): bool
    {
        if ($emailVerificata === null || ConvenzioneCompilataRepository::rivendicabile($cc)) {
            return false;
        }
        if (ConvenzioneCompilataRepository::eRivendicata($cc)) {
            return $this->eProprietario($cc, $emailVerificata);
        }

        // non rivendicata e ormai oltre la fase di compilazione (file firmato già caricato): non si può più cambiare il link, ma chi ha l'email verificata della
        // compilazione la apre lo stesso, con il token com'è
        return $this->eProprietario($cc, $emailVerificata) || strtolower(trim((string) ($cc['email'] ?? ''))) === $emailVerificata;
    }

    /**
     * La rivendicazione, IN UN PUNTO SOLO e da chiamare sotto il blocco dell'istituto, PRIMA di dare il token a chi ha fatto l'accesso: se l'email verificata
     * è uguale a quella della compilazione, la compilazione non è ancora rivendicata e lo stato è vuoto o rifiutato, il link cambia (token nuovo: quello di
     * chi aveva prenotato e il codice di nascita non aprono più), l'unico proprietario è quell'email, il link nuovo parte per email e l'operazione va nel
     * registro. Dagli altri stati in poi non cambia niente. Ritorna la riga aggiornata.
     *
     * @param array<string, mixed> $cc
     * @return array<string, mixed>
     */
    private function rivendicaSeSua(array $cc, ?string $emailVerificata): array
    {
        if ($emailVerificata === null) {
            return $cc;
        }
        $fresca = $this->compilate->perId((int) $cc['id']) ?: $cc;
        if (!ConvenzioneCompilataRepository::rivendicabile($fresca) || strtolower(trim((string) ($fresca['email'] ?? ''))) !== $emailVerificata) {
            return $fresca;
        }
        $token = bin2hex(random_bytes(16));
        if (!$this->compilate->rivendica((int) $fresca['id'], $emailVerificata, $token)) {
            return $this->compilate->perId((int) $cc['id']) ?: $fresca;
        }
        $nuova = $this->compilate->perId((int) $cc['id']) ?: $fresca;
        $dati = json_decode((string) ($nuova['dati_json'] ?? ''), true) ?: [];
        $this->invio->invia((string) $nuova['email'], 'fsl_compilazione_rivendicata', ['scuola' => (string) (($dati['scuola']['denominazione'] ?? '') ?: 'la tua scuola')],
            $this->sito->urlBase() . '/convenzione_online.php?t=' . $token);
        $this->operazioni->registra("Convenzione FSL rivendicata con un'email verificata", ['Compilazione' => (int) $nuova['id'], 'Email' => $emailVerificata, 'Scuola' => (string) ($nuova['scuola_codice'] ?? '')]);

        return $nuova;
    }

    /** L'email verificata in minuscolo e senza spazi (null se non c'è). */
    private static function norma(?string $email): ?string
    {
        $e = strtolower(trim((string) $email));

        return $e === '' ? null : $e;
    }

    /**
     * @return array{token: ?string, segnalata: bool, riprova: bool}
     */
    private static function esito(?string $token = null, bool $segnalata = false, bool $riprova = false): array
    {
        return ['token' => $token, 'segnalata' => $segnalata, 'riprova' => $riprova];
    }

    /**
     * Dal codice della prenotazione alla compilazione della scuola: UNA SOLA «in corso» per istituto (le sedi sono la stessa scuola).
     * L'email scritta in una prenotazione NON prova chi è (si può prenotare senza accesso scrivendo l'email di un altro): il token di una
     * compilazione che esiste già si dà a schermo solo in due casi:
     *  - la prenotazione è quella da cui la compilazione è nata (e il «Cambia referente» non l'ha tolta);
     *  - chi chiede ha fatto l'accesso e la sua email VERIFICATA ($emailVerificata, null = nessuna) è tra i proprietari.
     * In tutti gli altri casi, anche se l'email coincide, la risposta è la stessa: l'attività resta «da confermare» e l'avviso, con il link,
     * va solo all'email della compilazione (al massimo uno ogni 10 minuti).
     * Una compilazione mai compilata non occupa la scuola: chi arriva dopo ne apre una sua. Una scuola senza codice meccanografico resta una
     * compilazione per prenotazione. Tutto sotto un blocco per istituto: se non si ottiene non si procede ('riprova').
     *
     * @return array{token: ?string, segnalata: bool, riprova: bool}|null null se il codice non è di una prenotazione FSL
     */
    public function apriDaCodice(string $codice, ?string $emailVerificata = null): ?array
    {
        $emailVerificata = self::norma($emailVerificata);
        $pr = preg_match('/^[A-Z0-9-]{4,50}$/', $codice) ? $this->prenotazioni->perCodiceConvenzione($codice) : null;
        if (!$pr) {
            return null;
        }
        $prId = (int) $pr['id'];
        $scuola = (string) ($pr['scuola_codice'] ?? '');
        $istituto = $this->scuole->codiceIstituto($scuola);
        try {
            // Senza codice meccanografico il blocco è quello della prenotazione da cui nasce la compilazione che contiene questa (come per ogni altra scrittura)
            $esistente = $istituto === '' ? ($this->compilate->dellaNascita($prId) ?: $this->compilate->cheContiene($prId)) : null;
            $chiave = $istituto !== '' ? $istituto : ConvenzioneCompilataRepository::chiavePrenotazione($esistente ? (int) $esistente['prenotazione_id'] : $prId);

            return $this->compilate->sottoBlocco($chiave, function () use ($pr, $prId, $scuola, $istituto, $emailVerificata): array {
                if ($cc = $this->compilate->dellaNascita($prId)) {
                    // anche se è la prenotazione di nascita: se chi apre è chi ha ragione (email verificata uguale), la compilazione si rivendica PRIMA di dare il token
                    return self::esito((string) $this->rivendicaSeSua($cc, $emailVerificata)['token']);
                }
                $voce = $this->voceDaPrenotazione($prId);
                if ($istituto === '') {
                    if ($cc = $this->compilate->cheContiene($prId)) {
                        $cc = $this->rivendicaSeSua($cc, $emailVerificata);
                        if ($this->eProprietarioVerificato($cc, $emailVerificata)) {
                            return self::esito((string) $cc['token']);
                        }
                        $this->segnala($cc, [$voce]);

                        return self::esito(null, true);
                    }
                    $cc = $this->compilate->crea(bin2hex(random_bytes(16)), $scuola ?: null, $prId, (string) $pr['email'], $emailVerificata);

                    return self::esito($cc['token'] ?? null);
                }
                if ($mia = $this->delProprietario($scuola, $emailVerificata)) {
                    $this->aggiungiAttivita($mia, [$voce]);

                    return self::esito((string) $mia['token']);
                }
                if ($occupante = $this->occupante($scuola)) {
                    $this->segnala($occupante, [$voce]);

                    return self::esito(null, true);
                }
                $precedente = $this->compilate->ultimaConclusaDellIstituto($scuola);
                $nuova = $this->creaNuova($scuola, $prId, (string) $pr['email'], $precedente && $this->eProprietarioVerificato($precedente, $emailVerificata) ? $precedente : null, $voce, $emailVerificata);

                return self::esito($nuova['token'] ?? null);
            });
        } catch (BloccoOccupato) {
            return self::esito(null, false, true);
        }
    }

    /** Il link personale dal codice della prenotazione (null se il codice non c'è, se chi chiede non è proprietario o se serve riprovare). */
    public function tokenDaCodice(string $codice, ?string $emailVerificata = null): ?string
    {
        return $this->apriDaCodice($codice, $emailVerificata)['token'] ?? null;
    }

    /**
     * La compilazione in corso dell'istituto di cui l'email VERIFICATA è proprietaria (anche se mai compilata: è sua), la più vecchia. Se è sua ma non ancora rivendicata la rivendica (token nuovo).
     *
     * @return array<string, string|null>|null
     */
    private function delProprietario(string $codiceScuola, ?string $emailVerificata): ?array
    {
        if ($emailVerificata === null) {
            return null;
        }
        foreach ($this->compilate->inCorsoDellIstituto($codiceScuola, true) as $c) {
            // se la compilazione è sua e non ancora rivendicata, la rivendica adesso (token nuovo) invece di aprirla con quello di chi ha prenotato
            $c = $this->rivendicaSeSua($c, $emailVerificata);
            if ($this->eProprietarioVerificato($c, $emailVerificata)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * La compilazione in corso dell'istituto che «occupa» la scuola per gli altri: la più vecchia già compilata (una mai compilata non conta).
     *
     * @return array<string, string|null>|null
     */
    private function occupante(string $codiceScuola): ?array
    {
        return $this->compilate->inCorsoDellIstituto($codiceScuola)[0] ?? null;
    }

    /**
     * Una compilazione nuova per l'istituto. Se si rinnova una convenzione conclusa ($daRinnovare) si parte dai suoi dati e dal suo logo, e chi
     * ne era proprietario lo resta: lo si decide fuori (link della conclusa o email verificata proprietaria), senza prova si parte da zero.
     *
     * @param array<string, string|null>|null $daRinnovare la compilazione conclusa da cui si rinnova
     * @param array{pr: int, studenti: int, tutor: string, il: string}|null $voce l'attività da mettere subito nell'Allegato A di una compilazione precompilata
     * @return array<string, string|null>|null
     */
    private function creaNuova(string $scuola, int $prenotazioneId, string $emailCompilazione, ?array $daRinnovare = null, ?array $voce = null, ?string $emailVerificata = null): ?array
    {
        // Rinnovo: restano i proprietari VERIFICATI della precedente (una non rivendicata non ne ha); l'email della prenotazione entra solo se è verificata
        $proprietari = $daRinnovare ? (ConvenzioneCompilataRepository::eRivendicata($daRinnovare) ? $this->proprietari($daRinnovare) : []) : null;
        $cc = $this->compilate->crea(bin2hex(random_bytes(16)), $scuola ?: null, $prenotazioneId, $emailCompilazione, $emailVerificata, $proprietari);
        if (!$cc) {
            return null;
        }
        if ($daRinnovare) {
            $this->copiaDaPrecedente($cc, $daRinnovare);
            $cc = $this->compilate->perToken((string) $cc['token']) ?: $cc;
            if ($voce) {
                $this->aggiungiAttivita($cc, [$voce]);
            }
        }

        return $cc;
    }

    /**
     * @param array<string, string|null> $nuova
     * @param array<string, string|null> $precedente
     */
    private function copiaDaPrecedente(array $nuova, array $precedente): void
    {
        $datiVecchi = json_decode((string) ($precedente['dati_json'] ?? ''), true) ?: [];
        if (empty($datiVecchi['scuola'])) {
            return;
        }
        $logo = null;
        $vecchio = (string) ($precedente['logo'] ?? '');
        $radice = $this->sito->radice() . '/';
        if ($vecchio !== '' && is_file($radice . $vecchio)) {
            $ext = strtolower(pathinfo($vecchio, PATHINFO_EXTENSION));
            $copia = self::CARTELLA_LOGHI . bin2hex(random_bytes(16)) . '.' . $ext;
            $logo = @copy($radice . $vecchio, $radice . $copia) ? $copia : null;
        }
        // Solo i dati dell'istituto: niente attività (sono quelle del periodo che si rinnova), niente protocollo
        $json = json_encode(['scuola' => $datiVecchi['scuola'], 'attivita' => [], 'origine' => 'rinnovo', 'convenzione' => 'rinnovo'], JSON_UNESCAPED_UNICODE);
        $this->compilate->salvaDati((int) $nuova['id'], (string) $json, $logo, (string) ($nuova['email'] ?? ''));
    }

    /**
     * L'ufficio avvia la stipula dalla scheda della scuola, anche se la scuola non ha prenotazioni (basta che sia nell'anagrafe): UNA compilazione per istituto,
     * sotto il blocco dell'istituto, come per ogni altra scrittura. Bloccano l'avvio solo le compilazioni che «contano» (compilate, già nell'iter o avviate
     * dall'ufficio: ConvenzioneCompilataRepository::conta); una vuota di altri non conta. Se ne esiste una in corso:
     *  - indirizzo diverso: nessun cambiamento, si dice che c'è già un referente e che per passarla a un altro serve «Cambia referente»;
     *  - stesso indirizzo (o già proprietario) e compilazione già rivendicata: il link riparte com'è, al massimo una volta ogni 10 minuti;
     *  - stesso indirizzo e compilazione NON rivendicata (stato vuoto o rifiutata): il link cambia, i proprietari si svuotano e il codice di nascita non
     *    apre più: il link e il codice di prima non danno più accesso (ConvenzioneCompilataRepository::riapri);
     *  - se invece è una vuota non avviata dall'ufficio non si riprende: se ne crea una nuova.
     * Se non c'è, nasce quella nuova e il link parte SOLO all'email scelta. Se la scuola ha una convenzione conclusa, i dati di prima (Dirigente compreso)
     * si copiano solo se la casella $copiaDati è accesa E il referente scelto era già proprietario verificato di quella: i vecchi referenti non restano
     * proprietari, salvo quello scelto. L'email non contiene mai dati, solo il link.
     *
     * @return array{errore: ?string, id: int, esito: string, link: string, link_cambiato: bool} esito: 'creata' | 'ripresa' | 'gia_inviata'
     */
    public function avviaDalPannello(string $codiceScuola, string $emailReferente, string $da, bool $copiaDati = false): array
    {
        $no = static fn (string $e): array => ['errore' => $e, 'id' => 0, 'esito' => '', 'link' => '', 'link_cambiato' => false];
        $email = self::norma($emailReferente);
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $no('Scrivi un indirizzo email valido per il referente.');
        }
        $scuola = strtoupper(trim($codiceScuola));
        $istituto = $this->scuole->codiceIstituto($scuola);
        if ($istituto === '' || !$this->scuole->perCodice($istituto)) {
            return $no("La scuola non è nell'anagrafe: abbinala prima all'anagrafe, poi avvia la convenzione.");
        }
        try {
            return $this->compilate->sottoBlocco($istituto, function () use ($scuola, $istituto, $email, $da, $copiaDati, $no): array {
                $esistenti = $this->cheContano($scuola, $email);
                if ($esistenti) {
                    $sue = array_values(array_filter($esistenti, fn (array $c): bool => $this->eDi($c, $email)));
                    if (!$sue) {
                        return $no('Per questa scuola c\'è già una convenzione in corso (referente: ' . (string) ($esistenti[0]['email'] ?: 'non indicato') . '). Per passarla a un altro indirizzo usa «Cambia referente».');
                    }
                    $cc = $sue[0];
                    $id = (int) $cc['id'];
                    $apribile = ConvenzioneCompilataRepository::rivendicabile($cc);
                    if ($apribile ? $this->compilate->avviataDaMeno($id) : !$this->compilate->puoAvvisare($id)) {
                        return ['errore' => null, 'id' => $id, 'esito' => 'gia_inviata', 'link' => $this->sito->urlBase() . '/convenzione_online.php?t=' . $cc['token'], 'link_cambiato' => false];
                    }
                    $cambiato = false;
                    if ($apribile && $this->compilate->riapri($id, $email, bin2hex(random_bytes(16)), $da)) {
                        $cc = $this->compilate->perId($id) ?: $cc;
                        $cambiato = true;
                    }
                    $this->compilate->segnaAvviso($id);
                    $link = $this->sito->urlBase() . '/convenzione_online.php?t=' . $cc['token'];
                    $this->invia($email, $scuola, $cc, $link);
                    $this->operazioni->registra('Convenzione FSL ripresa dalla scheda della scuola', ['Compilazione' => $id, 'Scuola' => $istituto, 'Referente' => $email, 'Da' => $da, 'Link cambiato' => $cambiato ? 'sì' : 'no']);

                    return ['errore' => null, 'id' => $id, 'esito' => 'ripresa', 'link' => $link, 'link_cambiato' => $cambiato];
                }
                $precedente = $this->compilate->ultimaConclusaDellIstituto($scuola);
                $cc = $this->creaDalPannello($scuola, $email, $precedente, $copiaDati);
                if (!$cc) {
                    return $no('Non riesco ad avviare la convenzione: riprova.');
                }
                $this->compilate->segnaAvvio((int) $cc['id'], $da);
                $this->compilate->segnaAvviso((int) $cc['id']);
                $link = $this->sito->urlBase() . '/convenzione_online.php?t=' . $cc['token'];
                $this->invia($email, $scuola, $cc + ['rinnovo' => $precedente !== null], $link);
                $this->operazioni->registra('Convenzione FSL avviata dalla scheda della scuola', ['Compilazione' => (int) $cc['id'], 'Scuola' => $istituto, 'Referente' => $email, 'Da' => $da,
                    'Dati di prima copiati' => !empty($cc['dati_json']) ? 'sì' : 'no']);

                return ['errore' => null, 'id' => (int) $cc['id'], 'esito' => 'creata', 'link' => $link, 'link_cambiato' => false];
            });
        } catch (BloccoOccupato $e) {
            return $no($e->getMessage());
        }
    }

    /**
     * Le compilazioni in corso dell'istituto che contano per l'avvio (compilate, già nell'iter, avviate dall'ufficio) e quella, anche vuota, di cui l'indirizzo
     * scelto è già proprietario verificato. Una vuota di altri non conta.
     *
     * @return list<array<string, string|null>>
     */
    private function cheContano(string $scuola, string $email): array
    {
        return array_values(array_filter(
            $this->compilate->inCorsoDellIstituto($scuola, true),
            fn (array $c): bool => ConvenzioneCompilataRepository::conta($c) || (ConvenzioneCompilataRepository::eRivendicata($c) && $this->eProprietario($c, $email))
        ));
    }

    /** La compilazione è del referente scelto: è la sua email o è tra i proprietari. @param array<string, string|null> $c */
    private function eDi(array $c, string $email): bool
    {
        return strtolower(trim((string) ($c['email'] ?? ''))) === $email || $this->eProprietario($c, $email);
    }

    /**
     * Il referente scelto era già proprietario VERIFICATO della convenzione conclusa (che era stata rivendicata e lo elenca)? Solo allora eredita i dati e il
     * permesso: una conclusa mai rivendicata non ha proprietari verificati.
     *
     * @param array<string, string|null>|null $precedente
     */
    private function eredita(?array $precedente, string $email): bool
    {
        return $precedente !== null && ConvenzioneCompilataRepository::eRivendicata($precedente) && $this->eProprietario($precedente, $email);
    }

    /**
     * La compilazione nuova dell'avvio dalla scheda: proprietario subito, e solo lui, il referente scelto se era già proprietario verificato della precedente
     * (nessun altro vecchio referente); altrimenti proprietari vuoti (lo diventa chi apre il link con l'email verificata). I dati di prima si copiano solo
     * con la casella accesa e se il referente ne aveva diritto: la casella da sola non basta.
     *
     * @param array<string, string|null>|null $precedente
     * @return array<string, string|null>|null
     */
    private function creaDalPannello(string $scuola, string $email, ?array $precedente, bool $copiaDati): ?array
    {
        $eredita = $this->eredita($precedente, $email);
        $cc = $this->compilate->crea(bin2hex(random_bytes(16)), $scuola ?: null, 0, $email, null, $eredita ? [$email] : []);
        if ($cc && $eredita && $copiaDati) {
            $this->copiaDaPrecedente($cc, $precedente);
            $cc = $this->compilate->perToken((string) $cc['token']) ?: $cc;
        }

        return $cc;
    }

    /**
     * Cosa succederebbe se l'ufficio avviasse la stipula per questo indirizzo (l'anteprima prima di confermare: non scrive niente, non manda email):
     *  - 'creare': nasce una compilazione nuova e il link parte all'indirizzo scelto;
     *  - 'riprendere': c'è già una compilazione in corso dello stesso indirizzo (o di un suo proprietario): il link riparte (nuovo, se nessuno l'aveva
     *    rivendicata: 'link_nuovo'), nessuna compilazione nuova;
     *  - 'bloccata': c'è già una compilazione che conta con un altro referente ('compilazione_id'): per passarla serve «Cambia referente».
     * 'cosa' dice cosa riceve il referente. 'copia' dice se, creando, i dati della convenzione conclusa di prima (e il Dirigente) si copiano: 'disponibile' c'è
     * una conclusa con dei dati; 'permessa' il referente ne era proprietario verificato; 'dirigente' il nome che si copierebbe (vuoto se non è permessa).
     *
     * @return array{errore: ?string, azione: string, cosa: string, email: string, scuola: string, referente_attuale: string, compilazione_id: int, link_nuovo: bool, copia: array{disponibile: bool, permessa: bool, dirigente: string}}
     */
    public function anteprimaAvvio(string $codiceScuola, string $emailReferente): array
    {
        $no = static fn (string $e): array => ['errore' => $e, 'azione' => '', 'cosa' => '', 'email' => '', 'scuola' => '', 'referente_attuale' => '', 'compilazione_id' => 0, 'link_nuovo' => false,
            'copia' => ['disponibile' => false, 'permessa' => false, 'dirigente' => '']];
        $email = self::norma($emailReferente);
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $no('Scrivi un indirizzo email valido per il referente.');
        }
        $scuola = strtoupper(trim($codiceScuola));
        $istituto = $this->scuole->codiceIstituto($scuola);
        $anagrafe = $istituto !== '' ? $this->scuole->perCodice($scuola) : null;
        if ($anagrafe === null) {
            return $no("La scuola non è nell'anagrafe: abbinala prima all'anagrafe, poi avvia la convenzione.");
        }
        [$oggi] = $this->periodi->attivita(null, null);
        $copre = $this->registro->valida($scuola, $oggi, $oggi) !== null;
        $esistenti = $this->cheContano($scuola, $email);
        $azione = 'creare';
        $attuale = '';
        $rinnovo = false;
        $id = 0;
        $linkNuovo = false;
        $copia = ['disponibile' => false, 'permessa' => false, 'dirigente' => ''];
        if ($esistenti) {
            $sue = array_values(array_filter($esistenti, fn (array $c): bool => $this->eDi($c, $email)));
            $cc = $sue[0] ?? $esistenti[0];
            $attuale = (string) ($cc['email'] ?? '');
            $id = (int) $cc['id'];
            $azione = $sue ? 'riprendere' : 'bloccata';
            $linkNuovo = $sue && ConvenzioneCompilataRepository::rivendicabile($cc);
        } else {
            $precedente = $this->compilate->ultimaConclusaDellIstituto($scuola);
            $rinnovo = $precedente !== null;
            if ($precedente !== null) {
                $permessa = $this->eredita($precedente, $email);
                $dati = json_decode((string) ($precedente['dati_json'] ?? ''), true) ?: [];
                $copia = ['disponibile' => !empty($dati['scuola']), 'permessa' => $permessa && !empty($dati['scuola']), 'dirigente' => $permessa ? (string) ($dati['scuola']['dirigente'] ?? '') : ''];
            }
        }

        return [
            'errore' => null, 'azione' => $azione, 'email' => $email, 'scuola' => \App\Anagrafi\Testi::etichettaScuola($anagrafe), 'referente_attuale' => $attuale,
            'cosa' => $copre ? "l'Allegato A" : ($rinnovo ? 'il rinnovo della convenzione' : 'la convenzione'),
            'compilazione_id' => $id, 'link_nuovo' => $linkNuovo, 'copia' => $copia,
        ];
    }

    /** L'email del link al referente scelto dall'ufficio: dice anche se è una convenzione nuova, un rinnovo o solo l'Allegato A (c'è già una convenzione che copre). @param array<string, mixed> $cc */
    private function invia(string $email, string $scuola, array $cc, string $link): void
    {
        $anagrafe = $this->scuole->perCodice($scuola);
        $nome = $anagrafe ? \App\Anagrafi\Testi::etichettaScuola($anagrafe) : $scuola;
        [$oggi] = $this->periodi->attivita(null, null);
        $copre = $this->registro->valida($scuola, $oggi, $oggi) !== null;
        $cosa = $copre ? "l'Allegato A" : (!empty($cc['rinnovo']) ? 'il rinnovo della convenzione' : 'la convenzione');
        $this->invio->invia($email, 'fsl_avvio_stipula', ['scuola' => $nome, 'cosa' => $cosa], $link);
    }

    /**
     * Rinnovo dal link della compilazione conclusa (il link è la prova): UNA nuova compilazione per l'istituto, precompilata da quella. Se ce n'è già
     * una in corso di chi ha questo link la riprende. Se ce n'è un'altra già compilata non la apre ('segnalata').
     *
     * @param array<string, mixed> $cc compilazione conclusa di cui chi apre ha il link
     * @return array{token: ?string, segnalata: bool, riprova: bool} token null e nessun'altra flag: la compilazione non è conclusa, non c'è niente da rinnovare
     */
    public function rinnova(array $cc, ?string $emailVerificata = null): array
    {
        $emailVerificata = self::norma($emailVerificata);
        if ((string) ($cc['stato_iter'] ?? '') !== Costanti::ITER_PROTOCOLLATA) {
            return self::esito();
        }
        $scuola = (string) ($cc['scuola_codice'] ?? '');
        $istituto = $this->scuole->codiceIstituto($scuola);
        $crea = function () use ($cc, $scuola, $istituto, $emailVerificata): array {
            $mie = $this->proprietari($cc);
            foreach ($istituto !== '' ? $this->compilate->inCorsoDellIstituto($scuola, true) : [] as $c) {
                // una compilazione vuota e non rivendicata non è di nessuno: non si riprende, se ne crea una nuova
                if (trim((string) ($c['dati_json'] ?? '')) === '' && !ConvenzioneCompilataRepository::eRivendicata($c)) {
                    continue;
                }
                // è di chi ha questo link se ne condivide un proprietario o l'email della compilazione (una rinnovata da un link non ha ancora proprietari verificati)
                if (array_intersect($mie, $this->proprietari($c)) || strtolower(trim((string) ($c['email'] ?? ''))) === strtolower(trim((string) ($cc['email'] ?? '')))) {
                    // anche qui, se è di chi ha fatto l'accesso con l'email verificata la si rivendica PRIMA di dare il token
                    return self::esito((string) $this->rivendicaSeSua($c, $emailVerificata)['token']);
                }
            }
            if ($istituto !== '' && $this->occupante($scuola)) {
                return self::esito(null, true);
            }
            $nuova = $this->creaNuova($scuola, (int) $cc['prenotazione_id'], (string) $cc['email'], $cc, null, $emailVerificata);

            return self::esito($nuova['token'] ?? null);
        };
        try {
            return $this->compilate->sottoBlocco($this->compilate->chiaveDi($cc), $crea);
        } catch (BloccoOccupato) {
            return self::esito(null, false, true);
        }
    }

    /**
     * La voce dell'Allegato A di una prenotazione: studenti e tutor dalla prenotazione, con il momento in cui è stata aggiunta.
     *
     * @return array{pr: int, studenti: int, tutor: string, il: string}
     */
    private function voceDaPrenotazione(int $prenotazioneId): array
    {
        $p = $this->prenotazioni->dati($prenotazioneId);
        $v = $p ? $this->precompilazione->dati($p) : ['STUDENTI' => '', 'TUTOR_SCUOLA' => ''];

        return ['pr' => $prenotazioneId, 'studenti' => (int) $v['STUDENTI'], 'tutor' => mb_substr((string) $v['TUTOR_SCUOLA'], 0, 150), 'il' => date('Y-m-d H:i:s')];
    }

    /**
     * Mette le attività nell'elenco dell'Allegato A di una compilazione di chi le ha prenotate: senza doppioni, senza toccare dati della scuola,
     * logo o altre attività. Se la compilazione è già stata caricata o presa in carico il documento firmato non cambia: la voce porta la data
     * in cui è stata aggiunta, e l'ufficio vede «attività aggiunte dopo la firma». Se la scuola non ha ancora compilato il modulo non c'è
     * nulla da aggiungere: le prenotazioni dei proprietari compaiono da sole.
     *
     * @param array<string, string|null> $cc
     * @param list<array{pr: int, studenti: int, tutor: string, il: string}> $voci
     */
    private function aggiungiAttivita(array $cc, array $voci): void
    {
        $cc = $this->compilate->perId((int) $cc['id']) ?: $cc;   // riletta sotto il blocco: un'altra richiesta può aver aggiunto qualcosa
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true);
        if (!is_array($dati)) {
            return;
        }
        $presenti = array_map(static fn ($a): int => (int) ($a['pr'] ?? 0), (array) ($dati['attivita'] ?? []));
        $aggiunta = false;
        foreach ($voci as $v) {
            if (!in_array((int) $v['pr'], $presenti, true)) {
                $dati['attivita'][] = $v;
                $presenti[] = (int) $v['pr'];
                $aggiunta = true;
            }
        }
        if ($aggiunta) {
            $this->compilate->salvaAttivita((int) $cc['id'], (string) json_encode($dati, JSON_UNESCAPED_UNICODE));
        }
        // Se erano tra quelle «da confermare», ora sono confermate
        $attesa = $this->inAttesaGrezze($cc);
        $resto = array_values(array_filter($attesa, static fn (array $a): bool => !in_array((int) ($a['pr'] ?? 0), $presenti, true)));
        if (count($resto) !== count($attesa)) {
            $this->compilate->salvaInAttesa((int) $cc['id'], $resto ? (string) json_encode($resto, JSON_UNESCAPED_UNICODE) : null);
        }
    }

    /**
     * Chi non è proprietario (o non l'ha provato) ha prenotato per la stessa scuola: le attività restano «da confermare», fuori dai documenti, finché chi ha
     * il link o l'ufficio le conferma. L'avviso, con il link, va SOLO all'email della compilazione, e solo per le attività nuove, al massimo una volta
     * ogni 10 minuti per compilazione (le altre restano da confermare e si vedono dalla pagina).
     *
     * @param array<string, string|null> $cc la compilazione esistente
     * @param list<array{pr: int, studenti: int, tutor: string, il: string}> $voci
     */
    private function segnala(array $cc, array $voci): void
    {
        $cc = $this->compilate->perId((int) $cc['id']) ?: $cc;
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $attesa = $this->inAttesaGrezze($cc);
        $note = array_map(static fn ($a): int => (int) ($a['pr'] ?? 0), array_merge((array) ($dati['attivita'] ?? []), $attesa));
        $nuove = [];
        foreach ($voci as $v) {
            if (!in_array((int) $v['pr'], $note, true) && count($attesa) + count($nuove) < self::MAX_IN_ATTESA) {
                $nuove[] = $v + ['ok' => false];
            }
        }
        if (!$nuove) {
            return;
        }
        $tutte = array_merge($attesa, $nuove);
        $this->compilate->salvaInAttesa((int) $cc['id'], (string) json_encode($tutte, JSON_UNESCAPED_UNICODE));
        $email = (string) ($cc['email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && $this->compilate->puoAvvisare((int) $cc['id'])) {
            $titoli = [];
            foreach ($tutte as $v) {
                if (!empty($v['ok'])) {
                    continue;
                }
                $p = $this->prenotazioni->dati((int) ($v['pr'] ?? 0));
                $titoli[] = $p ? trim((string) $p['evento_titolo'] . (trim((string) ($p['nome_turno'] ?? '')) !== '' ? ' – ' . $p['nome_turno'] : '')) : "un'attività";
            }
            $nome = (string) (($dati['scuola']['denominazione'] ?? '') ?: 'la tua scuola');
            $this->compilate->segnaAvviso((int) $cc['id']);
            $this->invio->invia($email, 'fsl_attivita_segnalata', ['scuola' => $nome, 'attivita' => implode('; ', $titoli)], $this->sito->urlBase() . '/convenzione_online.php?t=' . $cc['token'] . '#da-confermare');
        }
    }

    /**
     * Le attività segnalate da altre email, come sono salvate (voci con pr, studenti, tutor, il, ok).
     *
     * @param array<string, mixed> $cc
     * @return list<array<string, mixed>>
     */
    public function inAttesaGrezze(array $cc): array
    {
        $a = json_decode((string) ($cc['attivita_in_attesa'] ?? ''), true);

        return is_array($a) ? array_values(array_filter($a, 'is_array')) : [];
    }

    /**
     * Chi ha il link (o l'ufficio) conferma o scarta un'attività segnalata. Confermata: entra nell'elenco dell'Allegato A. Se la scuola non ha
     * ancora compilato il modulo resta tra le prenotazioni della pagina, già spuntata. Ritorna false se non c'era più.
     *
     * @param array<string, mixed> $cc
     * @throws BloccoOccupato se un'altra richiesta sta lavorando sulla stessa convenzione: da riprovare
     */
    public function decidiInAttesa(array $cc, int $prenotazioneId, bool $conferma): bool
    {
        return $this->compilate->sottoBloccoDi($cc, function () use ($cc, $prenotazioneId, $conferma): bool {
            $cc = $this->compilate->perId((int) $cc['id']) ?: $cc;
            if ((string) ($cc['stato_iter'] ?? '') === Costanti::ITER_UNITA) {
                return false;
            }
            $attesa = $this->inAttesaGrezze($cc);
            $trovata = null;
            foreach ($attesa as $i => $a) {
                if ((int) ($a['pr'] ?? 0) === $prenotazioneId && empty($a['ok'])) {
                    $trovata = $i;
                    break;
                }
            }
            if ($trovata === null) {
                return false;
            }
            $voce = $attesa[$trovata];
            if (!$conferma) {
                unset($attesa[$trovata]);
            } elseif (is_array(json_decode((string) ($cc['dati_json'] ?? ''), true))) {
                $this->aggiungiAttivita($cc, [['pr' => $prenotazioneId, 'studenti' => (int) ($voce['studenti'] ?? 0), 'tutor' => (string) ($voce['tutor'] ?? ''), 'il' => (string) ($voce['il'] ?? date('Y-m-d H:i:s'))]]);

                return true;   // aggiungiAttivita toglie la voce dalle «da confermare»
            } else {
                $attesa[$trovata]['ok'] = true;   // modulo non ancora compilato: sarà una delle attività della pagina, già spuntata
            }
            $this->compilate->salvaInAttesa((int) $cc['id'], $attesa ? (string) json_encode(array_values($attesa), JSON_UNESCAPED_UNICODE) : null);

            return true;
        });
    }

    /**
     * «Non riconosci questa convenzione?»: chi ha il codice di una prenotazione e trova per la sua scuola una convenzione in corso che non conosce scrive
     * all'ufficio. L'avviso porta il testo, il codice della prenotazione e come ricontattare chi scrive: niente link, niente dati, niente email della
     * compilazione. Ritorna il messaggio di errore da mostrare, null se l'avviso è partito. Anti-robot e limite per IP li mette la pagina.
     */
    public function nonRiconosco(string $codice, string $messaggio, string $contatto): ?string
    {
        $messaggio = trim((string) preg_replace('/\s+/u', ' ', $messaggio));
        if ($messaggio === '') {
            return 'Scrivi due righe per spiegare all\'ufficio cosa non riconosci.';
        }
        $pr = preg_match('/^[A-Z0-9-]{4,50}$/', $codice) ? $this->prenotazioni->perCodiceConvenzione($codice) : null;
        $p = $pr ? $this->prenotazioni->dati((int) $pr['id']) : null;
        if (!$p) {
            return 'Controlla il codice della prenotazione.';
        }
        $anagrafe = $this->scuole->perCodice((string) $this->scuole->codiceIstituto((string) ($p['scuola_codice'] ?? '')));
        $scuola = $anagrafe ? (string) ($anagrafe['denominazione'] ?? '') . ' (' . (string) $anagrafe['codice'] . ')' : (string) ServizioScuole::nomeScrittoNelModulo($p);
        $contatto = mb_substr(trim($contatto), 0, 150);
        $chi = trim((string) ($p['nome'] ?? '') . ' ' . (string) ($p['cognome'] ?? '')) . ($contatto !== '' ? ' – ' . $contatto : '');
        $destinatari = array_values(array_unique(array_map('strtolower', array_merge($this->compilate->emailAbilitati(), [Costanti::EMAIL_UFFICIO]))));
        foreach ($destinatari as $a) {
            $this->invio->invia($a, 'fsl_non_riconosce', ['scuola' => $scuola, 'codice' => (string) $p['codice_prenotazione'], 'chi' => $chi, 'messaggio' => mb_substr($messaggio, 0, 1000)], $this->sito->urlBase() . '/admin/fsl_scuole.php' . (($cod = (string) $this->scuole->codiceIstituto((string) ($p['scuola_codice'] ?? ''))) !== '' && $anagrafe ? '?k=' . urlencode($cod) : ''));
        }

        return null;
    }

    /**
     * Tutto ciò che serve alla pagina per una compilazione: prenotazione, modelli, attività prenotate e prenotabili, dati già salvati e
     * valori proposti (dall'anagrafe delle scuole e dalla prenotazione). Null se la prenotazione collegata non esiste più.
     *
     * @param array<string, mixed> $cc riga di convenzioni_compilate
     * @return array{p0: array<string, string|null>, cfg: array<string, string>, prenotate: array<int, array<string, string|null>>, prenotabili: array<int, array<string, mixed>>, dati: array<string, mixed>, s: array<string, mixed>,
     *     da_confermare: array<int, array{titolo: string, turno: string, studenti: int, tutor: string, il: string}>, registro: array<string, mixed>|null, allegati: list<array<string, string|null>>}|null
     */
    public function contesto(array $cc): ?array
    {
        // La compilazione avviata dall'ufficio dalla scheda della scuola non ha prenotazione di nascita: i dati di partenza sono quelli della scuola
        $p0 = (int) ($cc['prenotazione_id'] ?? 0) > 0
            ? $this->prenotazioni->dati((int) $cc['prenotazione_id'])
            : ($this->scuole->codiceIstituto((string) ($cc['scuola_codice'] ?? '')) !== '' ? $this->prenotazioni->datiSenzaPrenotazione((string) $cc['scuola_codice'], (string) ($cc['email'] ?? '')) : null);
        if (!$p0) {
            return null;
        }
        $cfg = $this->modelli->dati($p0);
        $dati = json_decode((string) $cc['dati_json'], true) ?: [];
        $attesa = $this->inAttesaGrezze($cc);
        // Le prenotazioni già scelte restano nell'elenco anche se la scuola non è dell'anagrafe (programma FSL: più prenotazioni insieme);
        // quelle segnalate da altre email e già confermate prima del modulo si aggiungono; quelle ancora da confermare no
        $scelte = array_values(array_filter(array_merge(
            array_map(static fn ($a): int => (int) ($a['pr'] ?? 0), (array) ($dati['attivita'] ?? [])),
            array_map(static fn ($a): int => !empty($a['ok']) ? (int) ($a['pr'] ?? 0) : 0, $attesa)
        )));
        [$prenotate, $prenotabili] = $this->precompilazione->attivitaScuola((int) $cc['prenotazione_id'], $cc['scuola_codice'], $scelte, $this->proprietari($cc));
        // Valori proposti: dall'anagrafe delle scuole e dalla prenotazione
        $base = $this->precompilazione->dati($p0);
        $anagrafe = !empty($cc['scuola_codice']) ? $this->scuole->perCodice($cc['scuola_codice']) : null;
        $predefiniti = [
            'denominazione' => $base['ISTITUTO_FIRMA'], 'codice' => $anagrafe ? (string) ($anagrafe['istituto_codice'] ?: $anagrafe['codice']) : '',
            'comune' => $base['COMUNE'], 'indirizzo' => $base['INDIRIZZO'], 'cf' => '', 'dirigente' => '', 'luogo_nascita' => '', 'data_nascita' => '', 'dir_cf' => '',
            'pec' => '', 'email' => (string) $cc['email'],
        ];
        // Attività segnalate da altre email: solo chi ha il link le vede, per confermarle o scartarle
        $daConfermare = [];
        foreach ($attesa as $a) {
            $pr = (int) ($a['pr'] ?? 0);
            if (!empty($a['ok']) || $pr <= 0 || !($p = $this->prenotazioni->dati($pr))) {
                continue;
            }
            $daConfermare[$pr] = ['titolo' => (string) $p['evento_titolo'], 'turno' => trim((string) ($p['nome_turno'] ?? '')), 'studenti' => (int) ($a['studenti'] ?? 0),
                'tutor' => (string) ($a['tutor'] ?? ''), 'il' => (string) ($a['il'] ?? '')];
        }
        // La convenzione del registro che copre le attività (periodo dalla prima all'ultima): con quella si aggiorna solo l'Allegato A
        $registro = $this->convenzioneCheCopre($cc, $dati, $prenotate, $p0);

        return ['p0' => $p0, 'cfg' => $cfg, 'prenotate' => $prenotate, 'prenotabili' => $prenotabili, 'dati' => $dati, 's' => ($dati['scuola'] ?? []) + $predefiniti,
            'da_confermare' => $daConfermare, 'registro' => $registro, 'allegati' => $registro ? $this->allegati->della((int) $registro['id']) : []];
    }

    /**
     * La convenzione del registro che copre tutto il periodo delle attività della compilazione (per istituto: registrata su una sede o
     * sull'istituto). Null se non c'è: la scuola deve stipulare o rinnovare. Una compilazione già nell'iter di firma non è in «aggiornamento».
     *
     * @param array<string, mixed> $cc
     * @param array<string, mixed> $dati
     * @param array<int, array<string, string|null>> $prenotate
     * @param array<string, string|null> $p0
     * @return array<string, mixed>|null
     */
    private function convenzioneCheCopre(array $cc, array $dati, array $prenotate, array $p0): ?array
    {
        $scuola = (string) ($cc['scuola_codice'] ?? '');
        if ($this->scuole->codiceIstituto($scuola) === '' || in_array((string) ($cc['stato_iter'] ?? ''), [Costanti::ITER_CARICATA, Costanti::ITER_IN_CARICO, Costanti::ITER_DA_FIRMARE, Costanti::ITER_FIRMATA, Costanti::ITER_UNITA], true)) {
            return null;
        }
        $scelti = array_filter(array_map(static fn ($a): int => (int) ($a['pr'] ?? 0), (array) ($dati['attivita'] ?? [])));
        $righe = array_values(array_filter(array_map(static fn (int $id) => $prenotate[$id] ?? null, $scelti ?: [(int) $cc['prenotazione_id']])));
        $righe = $righe ?: [$p0];
        $dal = '9999-12-31';
        $al = '0000-01-01';
        foreach ($righe as $r) {
            [$d, $a] = $this->periodi->prenotazione($r);
            $dal = min($dal, $d);
            $al = max($al, $a);
        }

        return $this->registro->valida($scuola, $dal, $al);
    }

    /**
     * Documento Word dai dati salvati: percorso del file temporaneo e nome del download (null se il modello manca). Segna la compilazione come scaricata.
     *
     * @param array<string, mixed> $cc
     * @param array<string, mixed> $ctx risultato di contesto()
     * @return array{file: string, nome: string}|null
     */
    public function scarica(string $doc, array $cc, array $ctx): ?array
    {
        // 'convenzione' (o 'convenzione_sola') = Convenzione in Word precompilata, senza Allegato A; 'allegato' = Allegato A in Word e
        // 'allegato_pdf' = Allegato A in PDF: stesso contenuto e stesso ordine (corso di laurea e data), con la scheda completa di ogni attività
        $doc = in_array($doc, ['allegato', 'allegato_pdf'], true) ? $doc : 'convenzione_sola';
        $radice = $this->sito->radice();
        $logo = $cc['logo'] && is_file($radice . '/' . $cc['logo']) ? $radice . '/' . $cc['logo'] : null;
        $prot = trim((string) ($cc['protocollo'] ?? '') . (!empty($cc['protocollo_data']) ? ' del ' . date('d/m/Y', strtotime($cc['protocollo_data'])) : ''));
        $prot = (string) ($cc['protocollo'] ?? '') !== '' ? $prot : '';
        $s = $ctx['s'];
        $sigla = preg_replace('/[^A-Za-z0-9]+/', '_', (string) ($s['codice'] ?: $s['denominazione']));
        if ($doc === 'allegato_pdf') {
            $pdf = $this->allegatoPdf->genera($s, $this->vociAllegato($ctx['dati'], $ctx['prenotate'], $ctx['prenotabili']), $logo, $prot);
            $file = tempnam(sys_get_temp_dir(), 'all') . '.pdf';
            if (file_put_contents($file, $pdf) === false) {
                return null;
            }
            $nome = 'Allegato_A_FSL_' . $sigla . '.pdf';
        } elseif ($doc === 'allegato') {
            $file = $this->allegatoWord->genera($s, $this->vociAllegato($ctx['dati'], $ctx['prenotate'], $ctx['prenotabili']), $logo, $prot);
            if (!$file) {
                return null;
            }
            $nome = 'Allegato_A_FSL_' . $sigla . '.docx';
        } else {
            [$scuola, $att] = $this->datiDocumenti($ctx['dati'], $ctx['prenotate'], $ctx['prenotabili']);
            $file = $this->documenti->genera('convenzione', $scuola, $att, $logo, $prot, true);
            if (!$file) {
                return null;
            }
            $nome = 'Convenzione_FSL_' . $sigla . '.docx';
        }
        $this->compilate->segnaScaricata((int) $cc['id']);

        return ['file' => $file, 'nome' => $nome];
    }

    /**
     * Convenzione o Allegato A già compilati per le prenotazioni indicate, senza che la scuola abbia usato il modulo online (documenti per il
     * pannello): dati della scuola dall'anagrafe e dalle prenotazioni; se la scuola ha compilato il modulo, i suoi dati (Dirigente, codice
     * fiscale, PEC, studenti, tutor, protocollo e logo) hanno la precedenza. Senza logo il documento non lo mette: si aggiunge a mano.
     *
     * @param list<int> $prenotazioniIds prenotazioni della stessa scuola
     * @return array{file: string, nome: string}|null file temporaneo da cancellare dopo l'uso
     */
    public function scaricaPerPrenotazioni(string $doc, array $prenotazioniIds): ?array
    {
        $prenotate = [];
        foreach (array_values(array_unique(array_map('intval', $prenotazioniIds))) as $id) {
            if ($id > 0 && ($p = $this->prenotazioni->dati($id))) {
                $prenotate[$id] = $p;
            }
        }
        if (!$prenotate) {
            return null;
        }
        $p0 = reset($prenotate);
        $base = $this->precompilazione->dati($p0);
        $anagrafe = !empty($p0['scuola_codice']) ? $this->scuole->perCodice((string) $p0['scuola_codice']) : null;
        $s = [
            'denominazione' => $base['ISTITUTO_FIRMA'] !== '' ? $base['ISTITUTO_FIRMA'] : ServizioScuole::nomeScrittoNelModulo($p0),
            'codice' => $anagrafe ? (string) ($anagrafe['istituto_codice'] ?: $anagrafe['codice']) : '', 'comune' => $base['COMUNE'], 'indirizzo' => $base['INDIRIZZO'],
            'cf' => '', 'dirigente' => '', 'luogo_nascita' => '', 'data_nascita' => '', 'dir_cf' => '', 'pec' => '', 'email' => (string) ($p0['email'] ?? ''),
        ];
        // Compilazione online della scuola (la più recente), se c'è: i suoi dati valgono più di quelli proposti
        $cc = $this->compilate->perPrenotazioni(array_keys($prenotate))[0] ?? null;
        $dati = $cc ? (json_decode((string) $cc['dati_json'], true) ?: []) : [];
        $s = array_filter((array) ($dati['scuola'] ?? []), static fn ($v): bool => trim((string) $v) !== '') + $s;
        $voci = [];
        foreach (array_keys($prenotate) as $id) {
            $scelta = [];
            foreach ((array) ($dati['attivita'] ?? []) as $a) {
                if ((int) ($a['pr'] ?? 0) === $id) {
                    $scelta = $a;
                    break;
                }
            }
            $voci[] = ['pr' => $id, 'studenti' => (int) ($scelta['studenti'] ?? 0), 'tutor' => (string) ($scelta['tutor'] ?? '')];
        }
        // id 0: scaricare dal pannello non segna la compilazione come «scaricata dalla scuola»
        $ccFinta = ['id' => 0, 'logo' => $cc['logo'] ?? null, 'protocollo' => $cc['protocollo'] ?? '', 'protocollo_data' => $cc['protocollo_data'] ?? null];

        return $this->scarica($doc, $ccFinta, ['s' => $s, 'dati' => ['scuola' => $s, 'attivita' => $voci], 'prenotate' => $prenotate, 'prenotabili' => []]);
    }

    /**
     * Le attività scelte per l'Allegato A in PDF: prenotazione (o attività ancora da prenotare) con studenti e docente referente.
     *
     * @param array<string, mixed> $dati
     * @param array<int, array<string, string|null>> $prenotate
     * @param array<int, array<string, mixed>> $prenotabili
     * @return list<array{p: array<string, mixed>, studenti: int, tutor: string}>
     */
    private function vociAllegato(array $dati, array $prenotate, array $prenotabili): array
    {
        $voci = [];
        foreach ($dati['attivita'] ?? [] as $a) {
            $p = !empty($a['pr']) ? ($prenotate[(int) $a['pr']] ?? null) : ($prenotabili[(int) ($a['ev'] ?? 0)] ?? null);
            if ($p) {
                $voci[] = ['p' => $p, 'studenti' => (int) ($a['studenti'] ?? 0), 'tutor' => (string) preg_replace('/^(\s*(prof(\.ssa|essoressa|essore)?|dott(\.ssa|oressa|ore)?)\.?\s*\/?)+/i', '', (string) ($a['tutor'] ?? ''))];
            }
        }

        return $voci;
    }

    /**
     * Controlla i dati dell'istituto e del Dirigente e carica o toglie il logo (PNG o JPG fino a 2 MB, in una cartella non raggiungibile dal web).
     * Solo la denominazione è obbligatoria: chi non conosce gli altri dati li lascia vuoti e nel documento restano da completare a mano.
     * Ritorna gli errori, i valori ripuliti e il percorso del logo da salvare (null = nessun logo).
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $fileLogo elemento $_FILES['logo']
     * @return array{errori: list<string>, s: array<string, string>, logo: string|null}
     */
    public function validaScuola(array $post, ?array $fileLogo, ?string $logoAttuale): array
    {
        $in = static fn (string $k, int $max = 255): string => mb_substr(trim((string) ($post[$k] ?? '')), 0, $max);
        $s = [
            'denominazione' => $in('denominazione'), 'codice' => strtoupper($in('codice', 20)), 'comune' => $in('comune'), 'indirizzo' => $in('indirizzo'),
            'cf' => strtoupper((string) preg_replace('/\s+/', '', $in('cf', 20))), 'dirigente' => $in('dirigente'), 'luogo_nascita' => $in('luogo_nascita'),
            'data_nascita' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $in('data_nascita', 10)) ? $in('data_nascita', 10) : '',
            'dir_cf' => strtoupper((string) preg_replace('/\s+/', '', $in('dir_cf', 20))), 'pec' => $in('pec'), 'email' => $in('email'),
        ];
        $errori = [];
        if ($s['denominazione'] === '') {
            $errori[] = "Indica la denominazione dell'istituzione scolastica.";
        }
        if ($s['cf'] !== '' && !preg_match('/^(\d{11}|[A-Z0-9]{16})$/', $s['cf'])) {
            $errori[] = "Il codice fiscale dell'istituto ha 11 cifre.";
        }
        if ($s['dir_cf'] !== '' && !preg_match('/^[A-Z0-9]{16}$/', $s['dir_cf'])) {
            $errori[] = 'Il codice fiscale del Dirigente ha 16 caratteri.';
        }
        if ($s['pec'] !== '' && !filter_var($s['pec'], FILTER_VALIDATE_EMAIL)) {
            $errori[] = 'La PEC della scuola non è valida.';
        }
        if ($s['email'] !== '' && !filter_var($s['email'], FILTER_VALIDATE_EMAIL)) {
            $errori[] = "L'email di riferimento non è valida.";
        }
        $logo = $logoAttuale;
        if (!empty($post['togli_logo'])) {
            $logo = null;
        }
        if (($fileLogo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            if ($fileLogo['size'] > 2 * 1024 * 1024) {
                $errori[] = 'Il logo supera 2 MB.';
            } else {
                $dir = $this->sito->radice() . '/' . self::CARTELLA_LOGHI;
                $this->upload->cartellaProtetta($dir, 'Loghi delle scuole per le convenzioni: usati solo per creare i documenti');
                $fn = $this->upload->salva($fileLogo, $dir, ['png', 'jpg', 'jpeg'], ['image/png', 'image/jpeg']);
                if ($fn) {
                    $logo = self::CARTELLA_LOGHI . $fn;
                } else {
                    $errori[] = "Il logo deve essere un'immagine PNG o JPG.";
                }
            }
        }

        return ['errori' => $errori, 's' => $s, 'logo' => $logo];
    }

    /**
     * Apre la compilazione della convenzione per le prenotazioni fatte insieme dal programma FSL: dati della scuola e del Dirigente, logo e
     * attività dell'Allegato A già scelte (le prenotazioni, con studenti e docente referente). Il link personale è quello dell'email riepilogativa.
     *
     * @param array<string, string> $scuola risultato di validaScuola()
     * @param list<array{pr: int, studenti: int, tutor: string}> $attivita
     * @param string $convenzione 'si' (già stipulata), 'no' (da stipulare) o 'rinnovo' (quella registrata non copre il periodo)
     * @return array<string, string|null>|null la riga di convenzioni_compilate (null se non è stata aperta: vedi accogliProgramma())
     */
    public function creaDaProgramma(array $scuola, ?string $logo, array $attivita, int $prenotazioneId, ?string $codiceScuola, string $email, string $convenzione, ?string $emailVerificata = null): ?array
    {
        return $this->accogliProgramma($scuola, $logo, $attivita, $prenotazioneId, $codiceScuola, $email, $convenzione, $emailVerificata)['cc'];
    }

    /**
     * Come per il codice della prenotazione (apriDaCodice), una sola compilazione in corso per istituto e nessuna prova di identità dall'email scritta:
     *  - non ce n'è una già compilata: ne nasce una con i dati e il logo del modulo (l'email della compilazione è quella di chi prenota; quella scritta nel
     *    modulo resta nei dati della scuola, solo per le comunicazioni);
     *  - c'è una di cui chi prenota è proprietario con l'email VERIFICATA ($emailVerificata): le attività si AGGIUNGONO all'elenco; i dati della scuola già
     *    scritti, il logo e le altre attività non si toccano (dei dati del modulo si usano solo quelli che mancano);
     *  - c'è un'altra (anche se l'email coincide ma non è verificata): non si apre né si modifica. Le attività restano «da confermare», l'avviso va
     *    all'email di quella compilazione; cc è null e segnalata è true (il logo caricato non è stato usato: lo cancella chi chiama);
     *  - il blocco per istituto è occupato: non si fa niente, cc è null e riprova è true; le prenotazioni già fatte restano valide.
     *
     * @param array<string, string> $scuola
     * @param list<array{pr: int, studenti: int, tutor: string}> $attivita
     * @return array{cc: array<string, string|null>|null, segnalata: bool, riprova: bool}
     */
    public function accogliProgramma(array $scuola, ?string $logo, array $attivita, int $prenotazioneId, ?string $codiceScuola, string $email, string $convenzione, ?string $emailVerificata = null): array
    {
        $emailVerificata = self::norma($emailVerificata);
        $istituto = $this->scuole->codiceIstituto((string) $codiceScuola);
        $adesso = date('Y-m-d H:i:s');
        $voci = array_map(static fn (array $a): array => ['pr' => (int) $a['pr'], 'studenti' => (int) ($a['studenti'] ?? 0), 'tutor' => mb_substr((string) ($a['tutor'] ?? ''), 0, 150), 'il' => $adesso], $attivita);
        $f = function () use ($istituto, $emailVerificata, $voci, $scuola, $logo, $attivita, $prenotazioneId, $codiceScuola, $email, $convenzione): array {
            if ($istituto !== '') {
                if ($mia = $this->delProprietario((string) $codiceScuola, $emailVerificata)) {
                    return ['cc' => $this->unisciDalProgramma($mia, $scuola, $logo, $voci, $convenzione), 'segnalata' => false, 'riprova' => false];
                }
                if ($occupante = $this->occupante((string) $codiceScuola)) {
                    $this->segnala($occupante, $voci);

                    return ['cc' => null, 'segnalata' => true, 'riprova' => false];
                }
            }
            $cc = $this->compilate->crea(bin2hex(random_bytes(16)), $codiceScuola ?: null, $prenotazioneId, $email, $emailVerificata);
            if (!$cc) {
                return ['cc' => null, 'segnalata' => false, 'riprova' => false];
            }
            $json = json_encode(['scuola' => $scuola, 'attivita' => $attivita, 'origine' => 'programma', 'convenzione' => $convenzione], JSON_UNESCAPED_UNICODE);
            $this->compilate->salvaDati((int) $cc['id'], (string) $json, $logo, $email);

            return ['cc' => $this->compilate->perToken((string) $cc['token']), 'segnalata' => false, 'riprova' => false];
        };
        try {
            return $this->compilate->sottoBlocco($istituto !== '' ? $istituto : ConvenzioneCompilataRepository::chiavePrenotazione($prenotazioneId), $f);
        } catch (BloccoOccupato) {
            return ['cc' => null, 'segnalata' => false, 'riprova' => true];
        }
    }

    /**
     * Le attività del programma entrano nella compilazione già in corso di chi prenota. Dati, logo e attività che c'erano restano come sono.
     *
     * @param array<string, string|null> $cc
     * @param array<string, string> $scuola
     * @param list<array{pr: int, studenti: int, tutor: string, il: string}> $voci
     * @return array<string, string|null>|null
     */
    private function unisciDalProgramma(array $cc, array $scuola, ?string $logo, array $voci, string $convenzione): ?array
    {
        $cc = $this->compilate->perId((int) $cc['id']) ?: $cc;
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true);
        if (!is_array($dati)) {
            // mai compilata: i dati del modulo sono i primi (l'email della compilazione resta quella di chi l'ha aperta)
            $json = json_encode(['scuola' => $scuola, 'attivita' => $voci, 'origine' => 'programma', 'convenzione' => $convenzione], JSON_UNESCAPED_UNICODE);
            $this->compilate->salvaDati((int) $cc['id'], (string) $json, $logo, (string) ($cc['email'] ?? ''));

            return $this->compilate->perToken((string) $cc['token']);
        }
        // dei dati del modulo si usano solo quelli che mancano
        $esistenti = (array) ($dati['scuola'] ?? []);
        foreach ($scuola as $k => $v) {
            if (trim((string) ($esistenti[$k] ?? '')) === '' && trim((string) $v) !== '') {
                $esistenti[$k] = $v;
            }
        }
        $dati['scuola'] = $esistenti;
        $this->compilate->salvaAttivita((int) $cc['id'], (string) json_encode($dati, JSON_UNESCAPED_UNICODE));
        $this->aggiungiAttivita($cc, $voci);
        if ($logo !== null && $logo !== '') {
            if (empty($cc['logo'])) {
                $this->compilate->salvaLogo((int) $cc['id'], $logo);
            } elseif ($logo !== $cc['logo'] && is_file($this->sito->radice() . '/' . $logo)) {
                @unlink($this->sito->radice() . '/' . $logo);
            }
        }

        return $this->compilate->perToken((string) $cc['token']);
    }

    /**
     * Salva il modulo: controlla i dati, carica o toglie il logo e, alla prima compilazione, manda l'email con il link per riprendere.
     * Ritorna gli errori da mostrare (vuoto = salvato) e i valori inseriti, da riproporre nel modulo.
     *
     * @param array<string, mixed> $cc
     * @param array<string, mixed> $ctx risultato di contesto()
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $fileLogo elemento $_FILES['logo']
     * @return array{errori: list<string>, s: array<string, mixed>}
     */
    public function salva(array $cc, array $ctx, array $post, ?array $fileLogo): array
    {
        $v = $this->validaScuola($post, $fileLogo, $cc['logo'] ?: null);
        $s = $v['s'];
        $errori = $v['errori'];
        $att = [];
        foreach ((array) ($post['att'] ?? []) as $k => $a) {
            if (empty($a['scelta'])) {
                continue;
            }
            $voce = ['studenti' => max(0, min(500, (int) ($a['studenti'] ?? 0))), 'tutor' => mb_substr(trim((string) ($a['tutor'] ?? '')), 0, 150)];
            if (str_starts_with((string) $k, 'pr') && isset($ctx['prenotate'][(int) substr((string) $k, 2)])) {
                $att[] = ['pr' => (int) substr((string) $k, 2)] + $voce;
            } elseif (str_starts_with((string) $k, 'ev') && isset($ctx['prenotabili'][(int) substr((string) $k, 2)])) {
                $att[] = ['ev' => (int) substr((string) $k, 2)] + $voce;
            }
        }
        if (!$att) {
            $errori[] = "Scegli almeno un'attività per l'Allegato A.";
        }
        $logo = $v['logo'];
        if ($errori) {
            return ['errori' => $errori, 's' => $s];
        }
        // Lo stato si rilegge SOTTO il blocco: se nel frattempo la compilazione è stata unita a un'altra, o l'ufficio ha cambiato il referente, non si
        // scrive niente (il modulo era aperto da prima). L'email della compilazione non cambia salvando: quella scritta nel modulo resta nei dati della scuola.
        $scrivi = function () use ($cc, $post, $att, $s, $logo): array {
            // Riletta sotto il blocco: un'attività aggiunta nel frattempo (con il codice di una prenotazione) che il modulo non conosceva non si perde,
            // e le voci già presenti mantengono il momento in cui sono state aggiunte
            $fresco = $this->compilate->perId((int) $cc['id']) ?: $cc;
            if ((string) ($fresco['stato_iter'] ?? '') === Costanti::ITER_UNITA) {
                return ['errore' => "Questa compilazione è stata unita a un'altra dal Dipartimento: i tuoi dati non sono stati salvati. Apri il link giusto che ti è arrivato per email.", 'prima' => false, 'cc' => $fresco];
            }
            $freschi = json_decode((string) ($fresco['dati_json'] ?? ''), true) ?: [];
            $chiave = static fn (array $a): string => !empty($a['pr']) ? 'pr' . (int) $a['pr'] : 'ev' . (int) ($a['ev'] ?? 0);
            $vecchie = [];
            foreach ((array) ($freschi['attivita'] ?? []) as $a) {
                $vecchie[$chiave($a)] = $a;
            }
            $nelModulo = array_map('strval', array_keys((array) ($post['att'] ?? [])));
            $adesso = date('Y-m-d H:i:s');
            $nuove = [];
            foreach ($att as $a) {
                $k = $chiave($a);
                $nuove[$k] = $a + ['il' => (string) ($vecchie[$k]['il'] ?? $adesso)];
            }
            foreach ($vecchie as $k => $a) {
                if (!isset($nuove[$k]) && !in_array($k, $nelModulo, true)) {
                    $nuove[$k] = $a;
                }
            }
            $json = json_encode(array_diff_key($freschi, ['scuola' => 1, 'attivita' => 1]) + ['scuola' => $s, 'attivita' => array_values($nuove)], JSON_UNESCAPED_UNICODE);
            $this->compilate->salvaDati((int) $cc['id'], (string) $json, $logo, (string) ($fresco['email'] ?? ''));
            // Le attività già confermate prima del modulo sono ora nell'elenco: non sono più «in attesa»
            $attesa = array_values(array_filter($this->inAttesaGrezze($fresco), static fn (array $a): bool => empty($a['ok'])));
            $this->compilate->salvaInAttesa((int) $cc['id'], $attesa ? (string) json_encode($attesa, JSON_UNESCAPED_UNICODE) : null);

            return ['errore' => null, 'prima' => empty($fresco['dati_json']), 'cc' => $fresco];
        };
        $nuovoLogo = static fn (): bool => $logo !== null && $logo !== ($cc['logo'] ?: null);
        try {
            $r = $this->compilate->sottoBloccoDi($cc, $scrivi);
        } catch (BloccoOccupato $e) {
            $this->togliLogo($nuovoLogo() ? $logo : null);

            return ['errori' => [$e->getMessage()], 's' => $s];
        }
        if ($r['errore'] !== null) {
            $this->togliLogo($nuovoLogo() ? $logo : null);

            return ['errori' => [$r['errore']], 's' => $s];
        }
        if ($cc['logo'] && $cc['logo'] !== $logo) {
            $this->togliLogo((string) $cc['logo']);
        }
        // Email con il link per riprendere e scaricare i documenti (alla prima compilazione): solo all'email della compilazione
        $email = (string) ($r['cc']['email'] ?? $cc['email'] ?? '');
        if ($r['prima'] && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->inviaLink((string) $cc['token'], $email, $s['denominazione'], $ctx['cfg']['pec']);
        }

        return ['errori' => [], 's' => $s];
    }

    private function togliLogo(?string $logo): void
    {
        if ($logo !== null && $logo !== '' && is_file($this->sito->radice() . '/' . $logo)) {
            @unlink($this->sito->radice() . '/' . $logo);
        }
    }

    /**
     * Le convenzioni e gli Allegati A delle prenotazioni della persona (Area personale): per ognuna lo stato della convenzione e le attività dell'Allegato
     * A con lo stato di ogni prenotazione. Chi ha più compilazioni le vede tutte, dalla più recente. Non scrive niente (una GET non rivendica):
     *  - le prenotazioni «sue» sono quelle con utente_id = l'utente collegato o con l'email VERIFICATA dell'account (non basta che l'email coincida sul
     *    database: l'email di un account non verificato non prova niente);
     *  - il link personale compare solo per una compilazione già rivendicata (o oltre la fase di compilazione). Una non rivendicata si apre dal codice di
     *    una prenotazione sua ('apri_codice'): è lì che l'email verificata la rivendica e il link nuovo parte per email;
     *  - il codice di una prenotazione compare solo se è sua: delle altre si vedono titolo e stato, mai il codice.
     *
     * @param list<array<string, mixed>> $prenotazioni righe delle prenotazioni della persona (id, utente_id, email)
     * @param string|null $emailVerificata l'email verificata dell'account connesso (null = nessuna)
     * @return list<array{token: string, apri_codice: string, rivendicata: bool, scuola: string, convenzione: string, aggiornata: string, protocollo: string, iter: string, firmata_il: string, attivita: list<array{titolo: string, turno: string, codice: string, stato: string, mia: bool}>}>
     */
    public function dellePrenotazioni(array $prenotazioni, int $utenteId = 0, ?string $emailVerificata = null): array
    {
        $emailVerificata = self::norma($emailVerificata);
        $mie = [];
        foreach ($prenotazioni as $pr) {
            $id = (int) ($pr['id'] ?? 0);
            $sua = ($utenteId > 0 && (int) ($pr['utente_id'] ?? 0) === $utenteId)
                || ($emailVerificata !== null && strtolower(trim((string) ($pr['email'] ?? ''))) === $emailVerificata);
            if ($id > 0 && $sua) {
                $mie[$id] = true;
            }
        }
        $out = [];
        foreach ($this->compilate->perPrenotazioni(array_keys($mie)) as $cc) {
            // Si vede la compilazione se è nata da una prenotazione sua (e il «Cambia referente» non l'ha tolta), se l'email VERIFICATA è tra i proprietari
            // o se è la sua email e può rivendicarla: l'email scritta in una prenotazione non prova chi è
            $nascita = (int) ($cc['nascita_ok'] ?? 1) === 1 && isset($mie[(int) $cc['prenotazione_id']]);
            $dellaEmail = $emailVerificata !== null && ConvenzioneCompilataRepository::rivendicabile($cc) && strtolower(trim((string) ($cc['email'] ?? ''))) === $emailVerificata;
            if (!$nascita && !$dellaEmail && !$this->eProprietarioVerificato($cc, $emailVerificata)) {
                continue;
            }
            $conLink = ConvenzioneCompilataRepository::eRivendicata($cc) || !ConvenzioneCompilataRepository::rivendicabile($cc);
            $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
            $ids = array_values(array_unique(array_merge([(int) $cc['prenotazione_id']], array_map(static fn ($a): int => (int) ($a['pr'] ?? 0), (array) ($dati['attivita'] ?? [])))));
            $attivita = [];
            $apri = '';
            foreach (array_filter($ids) as $id) {
                if ($p = $this->prenotazioni->dati($id)) {
                    $mia = isset($mie[$id]);
                    $attivita[] = ['titolo' => (string) $p['evento_titolo'], 'turno' => trim((string) ($p['nome_turno'] ?? '')), 'codice' => $mia ? (string) $p['codice_prenotazione'] : '',
                        'stato' => (string) ($p['stato'] ?? 'confermata'), 'mia' => $mia];
                    if ($mia && ($apri === '' || $id === (int) $cc['prenotazione_id'])) {
                        $apri = (string) $p['codice_prenotazione'];
                    }
                }
            }
            $out[] = [
                'token' => $conLink ? (string) $cc['token'] : '', 'apri_codice' => $conLink ? '' : $apri, 'rivendicata' => ConvenzioneCompilataRepository::eRivendicata($cc),
                'scuola' => (string) (($dati['scuola']['denominazione'] ?? '') ?: 'Scuola'),
                'convenzione' => (string) ($dati['convenzione'] ?? ''), 'aggiornata' => (string) ($cc['aggiornata_il'] ?? ''), 'protocollo' => (string) ($cc['protocollo'] ?? ''),
                'iter' => (string) ($cc['stato_iter'] ?? ''), 'firmata_il' => (string) ($cc['firmata_il'] ?? ''),
                'attivita' => $attivita,
            ];
        }

        return $out;
    }

    /**
     * Le compilazioni dell'ultimo anno (pannello FSL).
     *
     * @return list<array<string, string|null>>
     */
    public function recenti(): array
    {
        return $this->compilate->recenti(50);
    }

    /**
     * I segnaposto della scuola e le righe dell'Allegato A dai dati salvati.
     *
     * @param array<string, mixed> $dati
     * @param array<int, array<string, string|null>> $prenotate
     * @param array<int, array<string, mixed>> $prenotabili
     * @return array{0: array<string, string>, 1: list<array<string, string>>}
     */
    private function datiDocumenti(array $dati, array $prenotate, array $prenotabili): array
    {
        $s = $dati['scuola'] ?? [];
        $scuola = [
            'ISTITUTO' => trim($s['denominazione'] ?? '') !== '' ? $s['denominazione'] . (trim($s['codice'] ?? '') !== '' ? ' (codice meccanografico ' . $s['codice'] . ')' : '') : '',
            'ISTITUTO_FIRMA' => $s['denominazione'] ?? '', 'COMUNE' => $s['comune'] ?? '', 'INDIRIZZO' => $s['indirizzo'] ?? '', 'CF_ISTITUTO' => $s['cf'] ?? '',
            'DIRIGENTE' => $s['dirigente'] ?? '', 'DIRIGENTE_FIRMA' => $s['dirigente'] ?? '', 'DIR_LUOGO_NASCITA' => $s['luogo_nascita'] ?? '',
            'DIR_DATA_NASCITA' => !empty($s['data_nascita']) ? date('d/m/Y', strtotime($s['data_nascita'])) : '', 'DIR_CF' => $s['dir_cf'] ?? '',
        ];
        $att = [];
        foreach ($dati['attivita'] ?? [] as $a) {
            $p = !empty($a['pr']) ? ($prenotate[(int) $a['pr']] ?? null) : ($prenotabili[(int) ($a['ev'] ?? 0)] ?? null);
            if (!$p) {
                continue;
            }
            $v = $this->precompilazione->dati($p);
            // il modello scrive già "Prof./Prof.ssa" davanti al tutor della scuola
            $att[] = [
                'TITOLO' => $v['TITOLO'], 'DESCRIZIONE' => $v['DESCRIZIONE'], 'PERIODO' => $v['PERIODO'], 'DURATA' => $v['DURATA'], 'TUTOR_DIBEST' => $v['TUTOR_DIBEST'],
                'STUDENTI' => (int) ($a['studenti'] ?? 0) > 0 ? (string) (int) $a['studenti'] : $v['STUDENTI'],
                'TUTOR_SCUOLA' => trim((string) preg_replace('/^(\s*(prof(\.ssa|essoressa|essore)?|dott(\.ssa|oressa|ore)?)\.?\s*\/?)+/i', '', (string) ($a['tutor'] ?? ''))) ?: $v['TUTOR_SCUOLA'],
            ];
        }

        return [$scuola, $att];
    }

    private function inviaLink(string $token, string $email, string $denominazione, string $pec): void
    {
        $this->invio->invia($email, 'fsl_convenzione_pronta', ['scuola' => $denominazione, 'pec' => $pec], $this->sito->urlBase() . '/convenzione_online.php?t=' . $token);
    }
}
