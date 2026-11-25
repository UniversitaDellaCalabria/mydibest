<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\File;
use App\Core\Sito;
use App\Infrastructure\Pdf\MessaggiFirma;
use App\Infrastructure\Storage\FirmeConservate;
use App\Infrastructure\Storage\Upload;
use App\Uffici\Direzione;
use App\Tutorato\FirmaRemota;
use App\Tutorato\VerificaPdfFirmato;

/**
 * Firma PAdES del Direttore sulla convenzione che la scuola ha già firmato. L'ufficio manda la convenzione al Direttore (link personale
 * per email); il Direttore, dopo l'accesso, la firma con la firma remota Aruba dal portale oppure scarica il PDF, lo firma e lo carica.
 * Il PDF firmato deve avere una firma in più di quello della scuola; se qualcosa non torna (file riscritto, formato, certificato) non si blocca:
 * l'ufficio legge un avviso accanto al PDF. Poi l'ufficio la protocolla e la registra.
 */
final class ServizioFirmaDirettore
{
    /** Riquadro della firma visibile sull'ultima pagina (punti, A4): pagina, x, y, larghezza, altezza. */
    private const RIQUADRO = ['x' => 60, 'y' => 90, 'l' => 230, 'a' => 60];

    /** Il Direttore ha firmato con Aruba ma il portale non riesce a registrare adesso: il PDF firmato è conservato e si registra da solo. */
    public const FIRMA_CONSERVATA = 'La tua firma è stata fatta, ma il portale sta ancora lavorando su questa convenzione e non ha potuto registrarla subito. Il PDF firmato è al sicuro: riapri questa pagina tra un minuto e si registra da solo. NON firmare di nuovo e non servono altri codici.';
    /** Il PDF caricato a mano non si conserva: si ricarica. */
    public const RICARICALO = 'Il portale sta ancora lavorando su questa convenzione e la tua firma non si è potuta registrare adesso. Non firmare di nuovo con un altro codice: riprova tra un minuto a caricare il PDF firmato o avvisa l\'ufficio.';
    public const NON_CONSERVATA = 'La firma è stata fatta, ma il portale non è riuscito né a registrarla né a conservare il file. Avvisa l\'ufficio prima di firmare di nuovo.';

    /** @var (callable(int): mixed)|null chiamata dopo ogni firma registrata: il protocollo automatico (vedi Registrazione) */
    private $dopoLaFirma = null;
    private FirmeConservate $conservate;

    public function __construct(
        private ConvenzioneCompilataRepository $compilate,
        private Direzione $direzione,
        private NotificheConvenzioni $notifiche,
        private FirmaRemota $firmaRemota,
        private VerificaPdfFirmato $verifica,
        private Upload $upload,
        private Sito $sito
    ) {
        $this->conservate = new FirmeConservate($upload, $sito);
    }

    /**
     * Cosa fare subito dopo una firma registrata (il protocollo automatico). Non può mai far fallire la firma: un errore qui si ignora e il resto
     * (protocollo con il pulsante o a mano) resta all'ufficio.
     *
     * @param callable(int): mixed $f
     */
    public function conDopoFirma(callable $f): self
    {
        $this->dopoLaFirma = $f;

        return $this;
    }

    /** @return array{nome: string, email: string, cf: string} */
    public function direttore(): array
    {
        return $this->direzione->chiFirma();
    }

    public function configurato(): bool
    {
        return filter_var($this->direttore()['email'], FILTER_VALIDATE_EMAIL) !== false;
    }

    public function firmaRemotaDisponibile(): bool
    {
        return $this->firmaRemota->disponibile();
    }

    /** @return array<string, mixed>|null */
    public function perToken(string $token): ?array
    {
        return $this->compilate->perTokenDirettore($token);
    }

    /**
     * L'ufficio manda la convenzione presa in carico al Direttore. Ritorna il messaggio di errore, null se è andato a buon fine.
     */
    public function invia(int $id): ?string
    {
        $cc = $this->compilate->perId($id);
        if (!$cc || empty($cc['file_firmato']) || !in_array((string) $cc['stato_iter'], [Costanti::ITER_IN_CARICO, Costanti::ITER_DA_FIRMARE], true)) {
            return 'Prima prendi in carico la convenzione, poi puoi mandarla al Direttore.';
        }
        if (!$this->configurato()) {
            return 'Indica prima il Direttore del Dipartimento in Persone e permessi → Uffici e persone → Direzione: il link di firma arriva alla sua email.';
        }
        $token = bin2hex(random_bytes(20));
        if (!$this->compilate->daFirmare($id, $token)) {
            return 'La convenzione non è più in attesa di andare al Direttore (nel frattempo è stata rifiutata, firmata o registrata): ricarica la pagina.';
        }
        $d = $this->direttore();
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $this->notifiche->firmaRichiesta($d['email'], $d['nome'], (string) (($dati['scuola']['denominazione'] ?? '') ?: 'Scuola'), $token);

        return null;
    }

    /**
     * La persona collegata è chi firma ora: il Direttore o, con «Direttore assente» attivo, il vice (stessa email o stesso codice fiscale).
     *
     * @param array<string, mixed>|null $utente
     */
    public function puoFirmare(?array $utente): bool
    {
        return $this->direzione->puoFirmare($utente);
    }

    /** Percorso del PDF della scuola da firmare (solo dentro la cartella delle firmate), null se non c'è. */
    public function pdfDaFirmare(array $cc): ?string
    {
        $base = realpath($this->sito->radice() . '/' . rtrim(Costanti::DIR_FIRMATE, '/'));
        $p = !empty($cc['file_firmato']) ? realpath($this->sito->radice() . '/' . $cc['file_firmato']) : false;

        return $base && $p && strpos($p, $base . DIRECTORY_SEPARATOR) === 0 && is_file($p) ? $p : null;
    }

    /**
     * Firma con la firma remota Aruba (utente, password e OTP non si salvano). Ritorna il messaggio di errore, null se la firma è registrata.
     *
     * @param array<string, mixed> $cc
     */
    public function firmaConAruba(array $cc, string $utente, string $password, string $otp): ?string
    {
        if ((string) $cc['stato_iter'] !== Costanti::ITER_DA_FIRMARE) {
            return 'La convenzione non è in attesa della tua firma.';
        }
        $p = $this->pdfDaFirmare($cc);
        if ($p === null) {
            return 'Manca il PDF della convenzione.';
        }
        $pdf = (string) file_get_contents($p);
        [$firmato, $err] = $this->firmaRemota->firma($pdf, $utente, $password, $otp, $this->riquadro($pdf), 'Il Direttore del Dipartimento');
        if ($firmato === null) {
            return $err ?: 'Firma non riuscita.';
        }

        // la firma Aruba è fatta con un OTP che non si riusa: se il blocco è occupato il PDF firmato si conserva e si registra al tentativo successivo
        return $this->registra($cc, $firmato, 'firma remota Aruba', true);
    }

    /** Firma fatta fuori dal portale: si carica il PDF firmato. Ritorna il messaggio di errore, null se la firma è registrata. */
    public function caricaFirmato(array $cc, string $pdfFirmato): ?string
    {
        if (strlen($pdfFirmato) > Costanti::MAX_FIRMATA * 2) {
            MessaggiFirma::registraRifiuto('firma del Direttore FSL', MessaggiFirma::GRANDE);

            return 'Il file è troppo grande.';
        }

        return $this->registra($cc, $pdfFirmato, 'PDF firmato caricato');
    }

    /**
     * Controlla il PDF firmato (deve avere una firma in più; il resto diventa un avviso per l'ufficio), lo salva e avvisa l'ufficio.
     *
     * @param array<string, mixed> $cc
     */
    private function registra(array $cc, string $firmato, string $come, bool $conservabile = false): ?string
    {
        if ((string) $cc['stato_iter'] !== Costanti::ITER_DA_FIRMARE) {
            return 'La convenzione non è in attesa della tua firma.';
        }
        [$esito, $messaggio] = $this->provaRegistra($cc, $firmato, $come);
        if ($esito === 'bloccato') {
            // PDF caricato a mano: «ricaricalo». Firma Aruba: il file firmato si conserva (cartella chiusa al web) e si registra dopo, senza un secondo OTP
            if (!$conservabile) {
                return self::RICARICALO;
            }

            return $this->conserva($cc, $firmato, $come) ? self::FIRMA_CONSERVATA : self::NON_CONSERVATA;
        }

        return $messaggio;
    }

    /**
     * Un tentativo di registrazione. Esito: «ok» (registrata, ufficio avvisato, protocollo automatico partito), «bloccato» (il blocco è occupato: non è stato
     * fatto niente) o «errore» (la firma non si registra, con il motivo).
     *
     * @param array<string, mixed> $cc
     * @return array{0: string, 1: string|null}
     */
    private function provaRegistra(array $cc, string $firmato, string $come): array
    {
        // Il salvataggio sta sotto il blocco dell'istituto, con la riga riletta: se mentre il Direttore firmava (con Aruba è questione di secondi) l'ufficio ha
        // rifiutato, rimandato il link o registrato a mano il numero, la firma non scavalca niente. Se il blocco è occupato la firma è già stata fatta e non si può
        // chiedere un secondo codice: si aspetta più a lungo del solito.
        try {
            $esito = $this->compilate->sottoBloccoDi($cc, fn (): array => $this->registraSottoBlocco($cc, $firmato, $come), $this->compilate->attesaLunga());
        } catch (BloccoOccupato) {
            error_log('[convenzione FSL] firma del Direttore non registrata adesso: blocco occupato (compilazione ' . (int) $cc['id'] . ')');

            return ['bloccato', null];
        }
        if ($esito['errore'] !== null) {
            return ['errore', $esito['errore']];
        }
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $this->notifiche->firmataDalDirettore((string) (($dati['scuola']['denominazione'] ?? '') ?: 'Scuola'), $this->destinatariUfficio(), (int) $cc['id']);
        // La firma è salvata e l'ufficio avvisato, il blocco è libero: solo ora, e senza mai far fallire la firma, può partire il protocollo automatico
        if ($this->dopoLaFirma !== null) {
            try {
                ($this->dopoLaFirma)((int) $cc['id']);
            } catch (\Throwable $e) {
                error_log('[convenzione FSL] protocollo automatico dopo la firma: ' . $e->getMessage());
            }
        }

        return ['ok', null];
    }

    /** Conserva il PDF firmato, legato alla convenzione e all'impronta del file da cui si è partiti. */
    private function conserva(array $cc, string $firmato, string $come): bool
    {
        $p = $this->pdfDaFirmare($cc);
        if ($p === null) {
            return false;
        }
        $ok = $this->conservate->conserva('fsl', (int) $cc['id'], (string) $cc['stato_iter'], (string) ($cc['token_direttore'] ?? ''), (string) ($cc['file_firmato'] ?? ''), (string) hash_file('sha256', $p), $firmato, $come, $this->direttore()['nome']);
        error_log('[convenzione FSL] firma del Direttore conservata in attesa di registrarla (compilazione ' . (int) $cc['id'] . ($ok ? ')' : '): NON conservata'));

        return $ok;
    }

    /** C'è una firma del Direttore fatta e conservata, non ancora registrata (la pagina non deve chiedere di firmare di nuovo). */
    public function haFirmaConservata(int $id): bool
    {
        return $this->conservate->ce('fsl', $id);
    }

    /**
     * Riprova a registrare la firma conservata di una convenzione (il Direttore riapre la pagina, o il cron). La registra SOLO se stato, link e file di
     * partenza sono gli stessi di quando si è firmato; altrimenti la scarta e avvisa l'ufficio. Non chiede mai un secondo codice.
     *
     * @return array{esito: string, messaggio: string}|null null se non c'è niente di conservato; esito: «registrata» | «in_attesa» | «scartata»
     */
    public function riprendiFirmaConservata(int $id): ?array
    {
        $s = $this->conservate->di('fsl', $id);
        if ($s === null) {
            return null;
        }
        $m = $s['meta'];
        $ora = $this->compilate->perId($id);
        $motivo = null;
        if (!$ora) {
            $motivo = 'la convenzione non esiste più';
        } elseif ((string) $ora['stato_iter'] !== Costanti::ITER_DA_FIRMARE) {
            $motivo = 'la convenzione non è più in attesa della firma del Direttore (nel frattempo è stata rimandata, firmata o registrata)';
        } elseif ((string) ($ora['token_direttore'] ?? '') !== (string) $m['token'] || (string) ($ora['file_firmato'] ?? '') !== (string) $m['file_di_partenza']) {
            $motivo = 'la convenzione è stata rimandata (nuovo link o nuovo PDF) dopo la firma';
        } else {
            $p = $this->pdfDaFirmare($ora);
            if ($p === null || hash_file('sha256', $p) !== (string) $m['impronta_partenza']) {
                $motivo = 'il PDF da firmare non è più quello su cui il Direttore ha firmato';
            }
        }
        if ($motivo === null) {
            [$esito, $messaggio] = $this->provaRegistra($ora, $s['pdf'], (string) $m['come'] . ', registrata dopo');
            if ($esito === 'bloccato') {
                return ['esito' => 'in_attesa', 'messaggio' => 'La firma è conservata e si registrerà appena il portale ha finito il lavoro su questa convenzione: riapri la pagina tra poco.'];
            }
            $this->conservate->scarta('fsl', $id);
            if ($esito === 'ok') {
                return ['esito' => 'registrata', 'messaggio' => 'La firma è stata registrata: la convenzione passa all\'Ufficio per il protocollo. Grazie.'];
            }
            $motivo = (string) $messaggio;
        } else {
            $this->conservate->scarta('fsl', $id);
        }
        $dati = $ora ? (json_decode((string) ($ora['dati_json'] ?? ''), true) ?: []) : [];
        $this->notifiche->firmaNonRegistrata((string) (($dati['scuola']['denominazione'] ?? '') ?: 'Scuola'), $this->destinatariUfficio(), $motivo, (int) $id);

        return ['esito' => 'scartata', 'messaggio' => 'La firma conservata non è stata registrata: ' . $motivo . '. L\'ufficio è stato avvisato.'];
    }

    /** Il cron: riprova tutte le firme conservate. @return int quante registrate */
    public function riprendiTutte(): int
    {
        $n = 0;
        foreach ($this->conservate->ids('fsl') as $id) {
            if (($this->riprendiFirmaConservata($id)['esito'] ?? '') === 'registrata') {
                ++$n;
            }
        }

        return $n;
    }

    /** @return list<string> */
    private function destinatariUfficio(): array
    {
        return array_values(array_unique(array_map('strtolower', array_merge($this->compilate->emailAbilitati(), [Costanti::EMAIL_UFFICIO]))));
    }

    /**
     * La registrazione vera, con il blocco dell'istituto in mano. $cc è la riga di quando il Direttore ha cominciato a firmare: si rilegge e si confronta
     * (fase, link e PDF della scuola da cui si è partiti), poi l'UPDATE è condizionato agli stessi valori. Se qualcosa è cambiato il file nuovo si cancella.
     *
     * @param array<string, mixed> $cc
     * @return array{errore: string|null}
     */
    private function registraSottoBlocco(array $cc, string $firmato, string $come): array
    {
        $ko = static fn (string $m): array => ['errore' => $m];
        $ora = $this->compilate->perId((int) $cc['id']);
        if (!$ora) {
            return $ko('Questa convenzione non esiste più.');
        }
        if ((string) $ora['stato_iter'] !== Costanti::ITER_DA_FIRMARE) {
            return $ko(match ((string) $ora['stato_iter']) {
                Costanti::ITER_RIFIUTATA => 'La convenzione non è più in attesa della tua firma: nel frattempo l\'ufficio l\'ha rimandata alla scuola. La firma non è stata registrata.',
                Costanti::ITER_FIRMATA => 'La convenzione è già stata firmata (da un\'altra scheda o da un altro caricamento): la tua firma non è stata registrata.',
                Costanti::ITER_PROTOCOLLATA => 'La convenzione non è più in attesa della tua firma: nel frattempo l\'ufficio l\'ha già registrata. La firma non è stata registrata.',
                default => 'La convenzione non è più in attesa della tua firma (è cambiata mentre firmavi). La firma non è stata registrata.',
            });
        }
        if ((string) ($ora['token_direttore'] ?? '') !== (string) ($cc['token_direttore'] ?? '') || (string) ($ora['file_firmato'] ?? '') !== (string) ($cc['file_firmato'] ?? '')) {
            return $ko('L\'ufficio ha rimandato la convenzione (nuovo link o nuovo PDF) mentre firmavi: apri il link nuovo che ti è arrivato per email. La firma non è stata registrata.');
        }
        $p = $this->pdfDaFirmare($ora);
        if ($p === null) {
            return $ko('Manca il PDF della convenzione.');
        }
        [$err, $info] = $this->verifica->verifica((string) file_get_contents($p), $firmato, $this->direttore()['cf'], false);
        if ($err) {
            return $ko($err);
        }
        $cartella = $this->sito->radice() . '/' . Costanti::DIR_CONVENZIONI;
        $this->upload->cartellaProtetta($cartella, 'Convenzioni firmate: si scaricano solo dal pannello (admin/convenzione_file.php)');
        $nome = bin2hex(random_bytes(16)) . '.pdf';
        if (file_put_contents($cartella . $nome, $firmato) === false) {
            return $ko('Non riesco a salvare il file: riprova tra poco.');
        }
        $chi = $info['nome'] !== '' ? $info['nome'] : $this->direttore()['nome'];
        if (!$this->compilate->segnaFirmataDirettore((int) $ora['id'], Costanti::DIR_CONVENZIONI . $nome, $chi . ' (' . $come . ')', (string) $info['avviso'], (string) ($ora['token_direttore'] ?? ''), (string) ($ora['file_firmato'] ?? ''))) {
            File::togli($cartella . $nome);

            return $ko('La convenzione è cambiata mentre firmavi: la firma non è stata registrata.');
        }

        return ['errore' => null];
    }

    /**
     * Riquadro della firma visibile sull'ultima pagina; se non si riesce a contare le pagine la firma è invisibile (valida lo stesso).
     *
     * @return array{pagina: int, x: int, y: int, l: int, a: int}|null
     */
    public function riquadro(string $pdf): ?array
    {
        $n = self::contaPagine($pdf);

        return $n > 0 ? ['pagina' => $n] + self::RIQUADRO : null;
    }

    /** Numero di pagine dal dizionario /Pages principale (0 se il PDF le tiene in flussi compressi e non si leggono). */
    public static function contaPagine(string $pdf): int
    {
        $max = 0;
        if (preg_match_all('#/Type\s*/Pages\b[^>]*?/Count\s+(\d+)|/Count\s+(\d+)[^>]*?/Type\s*/Pages\b#s', $pdf, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $max = max($max, (int) ($x[1] !== '' ? $x[1] : ($x[2] ?? 0)));
            }
        }

        return $max;
    }
}
