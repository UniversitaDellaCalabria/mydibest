<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use App\Core\Database;
use App\Core\File;
use App\Core\Orologio;
use App\Core\Sito;
use App\Fsl\BloccoOccupato;
use App\Infrastructure\Storage\FirmeConservate;
use App\Infrastructure\Pdf\MessaggiFirma;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Infrastructure\Storage\Upload;
use App\Protocollo\ProtocolloRepository;
use App\Tutorato\FirmaRemota;
use App\Tutorato\VerificaPdfFirmato;
use App\Uffici\Direzione;
use App\Uffici\UfficiRepository;

/**
 * Le convenzioni per la tesi presso enti e aziende. L'iter:
 *   azienda (link personale, senza accesso): compila i dati, può proporre modifiche agli articoli → l'ufficio le valuta → scarica Word e PDF, firma in PAdES e ricarica;
 *   ufficio: protocollo in entrata → il Direttore firma (link, con «Direttore assente» il vice) → protocollo in uscita e validità → stipulata, in archivio e nell'elenco pubblico.
 * Le regole stanno qui; i dati in ConvenzioneTesiRepository; i documenti in ModelloConvenzione; le email in NotificheConvenzioniTesi.
 */
final class ServizioConvenzioniTesi
{
    /** Giorni prima della scadenza in cui l'azienda e l'ufficio ricevono l'avviso. */
    public const GIORNI_AVVISO_SCADENZA = 60;
    /** Dopo quanti giorni senza novità si ricorda all'azienda di completare, e ogni quanti giorni al massimo. */
    public const GIORNI_PROMEMORIA = 7;
    /** Oltre questi giorni dalla creazione i promemoria non si mandano più. */
    public const GIORNI_MAX_PROMEMORIA = 120;

    /** Il Direttore ha firmato con Aruba ma il portale non riesce a registrare adesso: il PDF firmato è conservato e si registra da solo. */
    public const FIRMA_CONSERVATA = 'La tua firma è stata fatta, ma il portale sta ancora lavorando su questa convenzione e non ha potuto registrarla subito. Il PDF firmato è al sicuro: riapri questa pagina tra un minuto e si registra da solo. NON firmare di nuovo e non servono altri codici.';
    /** Il PDF caricato a mano non si conserva: si ricarica. */
    public const RICARICALO = 'Il portale sta ancora lavorando su questa convenzione e la tua firma non si è potuta registrare adesso. Non firmare di nuovo con un altro codice: riprova tra un minuto a caricare il PDF firmato o avvisa l\'ufficio.';
    public const NON_CONSERVATA = 'La firma è stata fatta, ma il portale non è riuscito né a registrarla né a conservare il file. Avvisa l\'ufficio prima di firmare di nuovo.';

    /** @var (callable(int): mixed)|null chiamata dopo ogni firma del Direttore registrata: il protocollo automatico (vedi Registrazione) */
    private $dopoLaFirma = null;
    private ?FirmeConservate $conservateCache = null;

    public function __construct(
        private ConvenzioneTesiRepository $conv,
        private ModelloConvenzione $modello,
        private NotificheConvenzioniTesi $notifiche,
        private Direzione $direzione,
        private UfficiRepository $uffici,
        private FirmaRemota $firmaRemota,
        private VerificaPdfFirmato $verifica,
        private VerificaFirme $firme,
        private Upload $upload,
        private Database $db,
        private Orologio $orologio,
        private Sito $sito,
        private ProtocolloRepository $protocollo
    ) {
    }

    /**
     * Cosa fare subito dopo una firma del Direttore registrata (il protocollo automatico). Non può mai far fallire la firma: un errore lì si ignora e il resto
     * (protocollo con il pulsante o a mano) resta all'ufficio.
     *
     * @param callable(int): mixed $f
     */
    public function conDopoFirma(callable $f): self
    {
        $this->dopoLaFirma = $f;

        return $this;
    }

    /**
     * Un invio del protocollo in entrata di questa convenzione è in corso adesso (il blocco del documento è preso da un processo)? Mentre dura, l'azienda non può
     * sostituire il file e l'ufficio non può rifiutarlo: il numero che Titulus sta per dare è del file che è partito.
     *
     * @param array<string, mixed> $c
     */
    private function invioInEntrataInCorso(array $c): bool
    {
        return $this->protocollo->invioInCorso(ProtocollaConvenzioneTesiInTitulus::RIF, (int) ($c['id'] ?? 0));
    }

    private function oggi(): string
    {
        return $this->orologio->adesso()->format('Y-m-d');
    }

    // ───────────────────────────── Lettura ─────────────────────────────

    /** @return array<string, string|null>|null */
    public function perToken(string $token): ?array
    {
        return $this->conv->perToken($token);
    }

    /** @return array<string, string|null>|null */
    public function perId(int $id): ?array
    {
        return $this->conv->perId($id);
    }

    /** @return array<string, string|null>|null */
    public function perTokenDirettore(string $token): ?array
    {
        return $this->conv->perTokenDirettore($token);
    }

    /** @param array<string, mixed> $c @return array<string, string> */
    public function dati(array $c): array
    {
        return array_map('strval', (array) (json_decode((string) ($c['dati_json'] ?? ''), true) ?: []));
    }

    /**
     * Le modifiche agli articoli: numero => ['testo' (proposta dell'azienda), 'stato' (proposta|accettata|rifiutata|controproposta), 'risposta', 'testo_ufficio'].
     *
     * @param array<string, mixed> $c
     * @return array<int, array<string, string>>
     */
    public function modifiche(array $c): array
    {
        $out = [];
        foreach ((array) (json_decode((string) ($c['modifiche_json'] ?? ''), true) ?: []) as $n => $m) {
            if (is_array($m) && trim((string) ($m['testo'] ?? '')) !== '') {
                $out[(int) $n] = ['testo' => (string) $m['testo'], 'stato' => (string) ($m['stato'] ?? 'proposta'), 'risposta' => (string) ($m['risposta'] ?? ''), 'testo_ufficio' => (string) ($m['testo_ufficio'] ?? '')];
            }
        }
        ksort($out);

        return $out;
    }

    /** I testi concordati: gli articoli con modifica accettata. @param array<string, mixed> $c @return array<int, string> */
    public function concordati(array $c): array
    {
        $out = [];
        foreach ($this->modifiche($c) as $n => $m) {
            if ($m['stato'] === 'accettata') {
                $out[$n] = $m['testo'];
            }
        }

        return $out;
    }

    /** Ci sono modifiche che aspettano una decisione (dell'ufficio o dell'azienda): finché ci sono la convenzione non si firma. @param array<string, mixed> $c */
    public function modificheAperte(array $c): bool
    {
        foreach ($this->modifiche($c) as $m) {
            if ($m['stato'] !== 'accettata') {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{titolo: string, testo: string}> */
    public function articoliStandard(): array
    {
        return $this->modello->articoli();
    }

    public function modelloDisponibile(): bool
    {
        return $this->modello->disponibile();
    }

    /** L'azienda può scaricare i documenti e firmare: ha inserito i dati e non ci sono modifiche da decidere. @param array<string, mixed> $c */
    public function pronta(array $c): bool
    {
        return in_array((string) $c['stato'], [Costanti::COMPILATA, Costanti::CARICATA, Costanti::RIFIUTATA], true) && !$this->modificheAperte($c) && $this->dati($c) !== [];
    }

    /** @param array<string, mixed> $c */
    public static function caricabile(array $c): bool
    {
        return in_array((string) ($c['stato'] ?? ''), [Costanti::COMPILATA, Costanti::CARICATA, Costanti::RIFIUTATA], true);
    }

    /** Il Word compilato (file temporaneo da cancellare). @param array<string, mixed> $c */
    public function word(array $c): ?string
    {
        return $this->pronta($c) || (string) $c['stato'] === Costanti::PROTOCOLLATA ? $this->modello->word($this->dati($c), $this->concordati($c), $this->direttoreNelTesto()) : null;
    }

    /** Il PDF da firmare. @param array<string, mixed> $c */
    public function pdf(array $c): ?string
    {
        return $this->pronta($c) ? $this->modello->pdf($this->dati($c), $this->concordati($c), $this->direttoreNelTesto()) : null;
    }

    // ───────────────────────────── Il Direttore nel testo della convenzione ─────────────────────────────

    private static function chiaveNome(string $nome): string
    {
        $t = array_filter(explode(' ', mb_strtolower((string) preg_replace('/[^\p{L}\s]/u', ' ', \App\Tutorato\AbbinamentoInsegnamenti::norma($nome)))));
        sort($t);

        return implode(' ', $t);
    }

    /**
     * Come compare il Direttore nel testo della convenzione, e se i dati sono a posto.
     * stato: «mai_indicato» (si usa il testo del modello com'è), «a_posto» (il Direttore in carica è quello dei dati) o «da_aggiornare» (il Direttore è cambiato: nel testo
     * va il nuovo nome e restano puntini da completare per luogo e data di nascita).
     *
     * @return array{titolo: string, nome: string, nato_a: string, nato_il: string, stato: string, in_carica: string}
     */
    public function datiDirettoreTesto(): array
    {
        $r = $this->db->riga('SELECT tesi_direttore_titolo, tesi_direttore_nome, tesi_direttore_nato_a, tesi_direttore_nato_il FROM impostazioni_sistema WHERE id = 1') ?: [];
        $d = $this->direzione->direttore();
        $inCarica = $d ? trim((string) $d['nominativo']) : '';
        $nome = trim((string) ($r['tesi_direttore_nome'] ?? ''));
        $natoA = trim((string) ($r['tesi_direttore_nato_a'] ?? ''));
        $natoIl = (string) ($r['tesi_direttore_nato_il'] ?? '');
        $salvato = $nome !== '' && $natoA !== '' && $natoIl !== '';
        $stato = !$salvato ? 'mai_indicato' : ($inCarica === '' || self::chiaveNome($inCarica) === self::chiaveNome($nome) ? 'a_posto' : 'da_aggiornare');

        return ['titolo' => trim((string) ($r['tesi_direttore_titolo'] ?? '')) ?: 'Prof.', 'nome' => $nome, 'nato_a' => $natoA, 'nato_il' => $natoIl, 'stato' => $stato, 'in_carica' => $inCarica];
    }

    /** I dati del Direttore da scrivere nel Word e nel PDF; null = il testo del modello com'è (finché non si indicano). @return array{titolo: string, nome: string, nato_a: string, nato_il: string}|null */
    private function direttoreNelTesto(): ?array
    {
        $d = $this->datiDirettoreTesto();
        if ($d['stato'] === 'mai_indicato') {
            return null;
        }
        if ($d['stato'] === 'da_aggiornare') {
            return ['titolo' => $d['titolo'], 'nome' => $d['in_carica'], 'nato_a' => '', 'nato_il' => ''];
        }

        return ['titolo' => $d['titolo'], 'nome' => $d['nome'], 'nato_a' => $d['nato_a'], 'nato_il' => $d['nato_il']];
    }

    /** Salva il Direttore come si scrive nella convenzione. Ritorna gli errori. @return list<string> */
    public function salvaDirettoreTesto(string $titolo, string $nome, string $natoA, string $natoIl): array
    {
        $nome = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $nome)), 0, 150);
        $natoA = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $natoA)), 0, 100);
        $errori = [];
        if ($nome === '') {
            $errori[] = 'Scrivi nome e cognome del Direttore come devono comparire nella convenzione.';
        }
        if ($natoA === '') {
            $errori[] = 'Scrivi il luogo di nascita (per esempio Lachen (CH)).';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $natoIl) || strtotime($natoIl) === false || $natoIl > $this->oggi()) {
            $errori[] = 'Controlla la data di nascita.';
        }
        if (!isset(Costanti::TITOLI_DIRETTORE[$titolo])) {
            $titolo = 'Prof.';
        }
        if ($errori) {
            return $errori;
        }
        $this->db->esegui('UPDATE impostazioni_sistema SET tesi_direttore_titolo = ?, tesi_direttore_nome = ?, tesi_direttore_nato_a = ?, tesi_direttore_nato_il = ? WHERE id = 1', [$titolo, $nome, $natoA, $natoIl]);

        return [];
    }

    /** Anteprima per l'ufficio: anche con modifiche non ancora accettate non si genera nulla, si legge il testo proposto. @return list<string> */
    public function emailUfficio(): array
    {
        $u = $this->uffici->perChiave('convenzioni_tesi');
        $e = $u ? $this->uffici->emailDelUfficio((int) $u['id']) : [];
        foreach ($this->db->righe("SELECT DISTINCT email FROM utenti WHERE ruolo_id = 1 AND email <> ''") as $r) {
            $e[] = (string) $r['email'];
        }

        return array_values(array_unique(array_filter(array_map(static fn ($x): string => strtolower(trim((string) $x)), $e), static fn (string $x): bool => filter_var($x, FILTER_VALIDATE_EMAIL) !== false)));
    }

    /** L'utente fa parte dell'ufficio delle convenzioni di tesi. @param array<string, mixed>|null $utente */
    public function utenteDellUfficio(?array $utente): bool
    {
        $u = $this->uffici->perChiave('convenzioni_tesi');

        return $u !== null && $utente && !empty($utente['id']) && $this->uffici->membro((int) $u['id'], (string) \App\Auth\UtenteRepository::emailVerificata($utente));
    }

    // ───────────────────────────── Avvio ─────────────────────────────

    /**
     * Invita un'azienda (dal pannello) o registra la sua richiesta (dalla pagina pubblica): crea la convenzione e manda il link.
     * Se l'azienda ha già una convenzione aperta si rimanda il link di quella. Ritorna [errore|null, id].
     *
     * @return array{0: ?string, 1: int}
     */
    public function avvia(string $email, string $azienda, string $origine, string $da = ''): array
    {
        $email = strtolower(trim($email));
        $azienda = trim($azienda);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['Scrivi un indirizzo email valido: il link per compilare la convenzione arriva lì.', 0];
        }
        if ($azienda === '' && $origine === Costanti::ORIGINE_PUBBLICA) {
            return ['Scrivi il nome dell\'ente o dell\'azienda.', 0];
        }
        $aperta = $this->conv->apertaPerEmail($email);
        if ($aperta) {
            $this->notifiche->invitoRipreso($email, (string) ($aperta['azienda'] ?: $azienda), (string) $aperta['token']);

            return [null, (int) $aperta['id']];
        }
        // Convenzione già stipulata e ancora valida: si rimanda lo stesso link (lì c'è lo stato e, a fine validità, il rinnovo) invece di aprirne un'altra
        foreach ($this->conv->perEmail([$email]) as $s) {
            if ((string) $s['stato'] === Costanti::PROTOCOLLATA && (string) ($s['valida_al'] ?? '') >= $this->oggi()) {
                $this->notifiche->invitoRipreso($email, (string) ($s['azienda'] ?: $azienda), (string) $s['token']);

                return [null, (int) $s['id']];
            }
        }
        $id = $this->conv->crea($email, $azienda, $origine, $da);
        $c = $this->conv->perId($id);
        $this->notifiche->invito($email, $azienda, (string) $c['token'], $da);

        return [null, $id];
    }

    /** Il link personale si rimanda all'azienda (promemoria a mano dall'ufficio). */
    public function rimandaLink(int $id): ?string
    {
        $c = $this->conv->perId($id);
        if (!$c || (string) $c['stato'] === Costanti::PROTOCOLLATA) {
            return 'Questa convenzione non è in corso.';
        }

        return $this->notifiche->invitoRipreso((string) $c['email'], (string) $c['azienda'], (string) $c['token']) ? null : 'Email non partita: controlla l\'indirizzo dell\'azienda.';
    }

    /**
     * Rinnovo: dalla convenzione stipulata (scaduta o in scadenza) ne nasce una nuova con gli stessi dati dell'azienda, da riconfermare e firmare.
     * Ritorna [errore|null, token della nuova].
     *
     * @return array{0: ?string, 1: string}
     */
    public function rinnova(int $id): array
    {
        $c = $this->conv->perId($id);
        if (!$c || (string) $c['stato'] !== Costanti::PROTOCOLLATA) {
            return ['Si rinnova solo una convenzione stipulata.', ''];
        }
        if ((string) $c['valida_al'] !== '' && (string) $c['valida_al'] > date('Y-m-d', strtotime($this->oggi() . ' +' . self::GIORNI_AVVISO_SCADENZA . ' days'))) {
            return ['La convenzione è valida fino al ' . date('d/m/Y', (int) strtotime((string) $c['valida_al'])) . ': si può rinnovare negli ultimi ' . self::GIORNI_AVVISO_SCADENZA . ' giorni.', ''];
        }
        $aperta = $this->conv->apertaPerEmail((string) $c['email']);
        if ($aperta) {
            return [null, (string) $aperta['token']];
        }
        $nuovo = $this->conv->crea((string) $c['email'], (string) $c['azienda'], Costanti::ORIGINE_INVITO, 'Rinnovo');
        $this->conv->salvaDati($nuovo, (string) $c['azienda'], (string) $c['email'], (string) $c['dati_json'], (bool) (int) $c['consenso_elenco'], '');
        $this->conv->impostaStato($nuovo, Costanti::COMPILATA);
        $n = $this->conv->perId($nuovo);
        $this->notifiche->invito((string) $c['email'], (string) $c['azienda'], (string) $n['token']);

        return [null, (string) $n['token']];
    }

    // ───────────────────────────── L'azienda compila ─────────────────────────────

    /** L'azienda può cambiare i dati e le modifiche finché la convenzione non è stata caricata e presa in carico. @param array<string, mixed> $c */
    public static function modificabile(array $c): bool
    {
        return in_array((string) ($c['stato'] ?? ''), [Costanti::INVITATA, Costanti::COMPILATA, Costanti::MODIFICHE, Costanti::RISPOSTA, Costanti::RIFIUTATA], true);
    }

    /**
     * Controlla i dati dell'azienda. Ritorna [dati puliti, errori].
     *
     * @param array<string, mixed> $post
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public function validaDati(array $post): array
    {
        $t = static fn (string $k, int $max = 200): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($post[$k] ?? ''))), 0, $max);
        $d = [
            'ragione_sociale' => $t('ragione_sociale'), 'forma_giuridica' => $t('forma_giuridica', 80), 'cf' => strtoupper((string) preg_replace('/\s+/', '', $t('cf', 20))),
            'piva' => (string) preg_replace('/\D/', '', $t('piva', 20)), 'comune' => $t('comune', 100), 'provincia' => strtoupper($t('provincia', 40)), 'cap' => $t('cap', 10),
            'indirizzo' => $t('indirizzo'), 'pec' => strtolower($t('pec', 150)), 'telefono' => $t('telefono', 40), 'sito' => $t('sito', 200), 'settore' => $t('settore', 150),
            'rappr_titolo' => $t('rappr_titolo', 20), 'rappr_nome' => $t('rappr_nome', 150), 'rappr_qualifica' => $t('rappr_qualifica', 150),
            'referente_nome' => $t('referente_nome', 150), 'referente_email' => strtolower($t('referente_email', 150)), 'referente_telefono' => $t('referente_telefono', 40),
        ];
        if ($d['sito'] !== '' && !preg_match('#^https?://#i', $d['sito'])) {
            $d['sito'] = 'https://' . $d['sito'];
        }
        $e = [];
        foreach (['ragione_sociale' => 'la ragione sociale', 'comune' => 'il comune della sede legale', 'indirizzo' => 'l\'indirizzo della sede legale', 'rappr_nome' => 'nome e cognome del rappresentante legale',
            'rappr_qualifica' => 'la qualifica del rappresentante (es. Amministratore Unico)'] as $k => $nome) {
            if ($d[$k] === '') {
                $e[] = 'Indica ' . $nome . '.';
            }
        }
        if ($d['piva'] === '' && $d['cf'] === '') {
            $e[] = 'Indica la partita IVA o il codice fiscale dell\'ente.';
        }
        if ($d['piva'] !== '' && strlen($d['piva']) !== 11) {
            $e[] = 'La partita IVA ha 11 cifre.';
        }
        if ($d['cf'] !== '' && !preg_match('/^(\d{11}|[A-Z0-9]{16})$/', $d['cf'])) {
            $e[] = 'Il codice fiscale ha 11 cifre (enti) o 16 caratteri (persone fisiche).';
        }
        if ($d['pec'] === '' || filter_var($d['pec'], FILTER_VALIDATE_EMAIL) === false) {
            $e[] = 'Indica la PEC dell\'ente (un indirizzo email valido).';
        }
        if ($d['referente_email'] !== '' && filter_var($d['referente_email'], FILTER_VALIDATE_EMAIL) === false) {
            $e[] = 'L\'email del referente non è valida.';
        }
        if ($d['provincia'] !== '' && !preg_match('/^[A-Z]{2}$/', $d['provincia'])) {
            $e[] = 'La provincia si scrive con la sigla (due lettere, es. CS).';
        }
        if ($d['rappr_titolo'] !== '' && !isset(Costanti::TITOLI[$d['rappr_titolo']])) {
            $d['rappr_titolo'] = '';
        }

        return [$d, $e];
    }

    private static function norma(string $t): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    /** Codice fiscale o partita IVA come si confrontano: maiuscole, solo lettere e cifre (spazi, punti e «IT» davanti non contano per il confronto di due scritture uguali). */
    public static function identificativo(string $x): string
    {
        $x = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $x));

        return preg_match('/^IT\d{11}$/', $x) ? substr($x, 2) : $x;
    }

    /**
     * Gli identificativi fiscali di un'azienda (codice fiscale e partita IVA normalizzati, senza doppioni né vuoti). Un ente ha spesso lo stesso numero nei due campi.
     *
     * @param array<string, string> $dati i dati dell'azienda (dati_json)
     * @return list<string>
     */
    public static function identificativi(array $dati): array
    {
        $out = [];
        foreach (['cf', 'piva'] as $k) {
            $x = self::identificativo((string) ($dati[$k] ?? ''));
            if ($x !== '') {
                $out[$x] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * La convenzione stipulata, ancora valida, che copre già questa azienda: stesso codice fiscale o stessa partita IVA, e non in scadenza (negli ultimi
     * GIORNI_AVVISO_SCADENZA giorni si può rinnovare). null = nessuna. Da usare solo per decidere: ciò che contiene non si mostra a chi non è dell'ufficio.
     *
     * @param array<string, string> $dati i dati dell'azienda
     * @return array<string, string|null>|null
     */
    public function convenzioneCheCopre(array $dati, int $escludiId = 0): ?array
    {
        $miei = self::identificativi($dati);
        if ($miei === []) {
            return null;
        }
        $oggi = $this->oggi();
        $oltre = date('Y-m-d', (int) strtotime($oggi . ' +' . self::GIORNI_AVVISO_SCADENZA . ' days'));
        foreach ($this->conv->stipulateValide($oggi) as $s) {
            if ((int) $s['id'] === $escludiId || (string) $s['valida_al'] <= $oltre) {
                continue;
            }
            if (array_intersect($miei, self::identificativi($this->dati($s))) !== []) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Per l'ufficio, in «Controlla la convenzione»: un'altra convenzione stipulata e valida della stessa azienda (stesso codice fiscale o partita IVA), anche se in
     * scadenza. null = nessuna.
     *
     * @param array<string, mixed> $c
     * @return array<string, string|null>|null
     */
    public function altraConvenzioneValida(array $c): ?array
    {
        $miei = self::identificativi($this->dati($c));
        if ($miei === []) {
            return null;
        }
        foreach ($this->conv->stipulateValide($this->oggi()) as $s) {
            if ((int) $s['id'] !== (int) $c['id'] && array_intersect($miei, self::identificativi($this->dati($s))) !== []) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Salva i dati dell'azienda e le modifiche proposte agli articoli. $post: i campi dei dati, «art» [numero => testo dell'articolo come lo vuole l'azienda],
     * «accetta» [numero => 1] per accettare la controproposta dell'ufficio, «ritira» [numero => 1] per tornare al testo standard, «consenso_elenco», «nota».
     * Ritorna la lista degli errori (vuota se è andato bene).
     *
     * @param array<string, string|null> $c
     * @param array<string, mixed> $post
     * @return list<string>
     */
    public function salvaDati(array $c, array $post): array
    {
        if (!self::modificabile($c)) {
            return ['La convenzione è già stata caricata e presa in carico dal Dipartimento: i dati non si possono più cambiare. Se serve una correzione, scrivi all\'ufficio.'];
        }
        [$dati, $errori] = $this->validaDati($post);
        if ($errori) {
            return $errori;
        }
        // Un'azienda che ha già una convenzione valida non ne propone un'altra: si confronta il codice fiscale o la partita IVA, mai il nome. A chi compila non si
        // mostra niente dell'altra convenzione (né azienda né date né link): se l'email è la stessa gli si rimanda il link per posta, altrimenti deve scrivere all'ufficio.
        $coperta = $this->convenzioneCheCopre($dati, (int) $c['id']);
        if ($coperta !== null) {
            if (strtolower(trim((string) $coperta['email'])) === strtolower(trim((string) $c['email']))) {
                $this->notifiche->invitoRipreso((string) $coperta['email'], (string) $coperta['azienda'], (string) $coperta['token']);

                return ['Per la tua azienda c\'è già una convenzione valida: non ne serve un\'altra. Ti abbiamo scritto di nuovo, per email, il link di quella.'];
            }

            return ['Per questo ente (codice fiscale o partita IVA) risulta già una convenzione valida con il Dipartimento: non ne serve un\'altra. Se ti serve il documento o un rinnovo, scrivi all\'ufficio.'];
        }
        $standard = $this->modello->articoli();
        $vecchie = $this->modifiche($c);
        $nuove = [];
        $proposte = 0;
        foreach ((array) ($post['art'] ?? []) as $n => $testo) {
            $n = (int) $n;
            if (!isset($standard[$n]) || !is_string($testo)) {
                continue;
            }
            if (!empty($post['ritira'][$n])) {
                continue;
            }
            $testo = trim(str_replace("\r\n", "\n", $testo));
            $vecchia = $vecchie[$n] ?? null;
            if (self::norma($testo) === self::norma($standard[$n]['testo']) || $testo === '') {
                continue;   // uguale al modello: nessuna modifica
            }
            if ($vecchia !== null && $vecchia['stato'] === 'controproposta' && !empty($post['accetta'][$n])) {
                $nuove[$n] = ['testo' => $vecchia['testo_ufficio'], 'stato' => 'accettata', 'risposta' => $vecchia['risposta'], 'testo_ufficio' => ''];
                continue;
            }
            if ($vecchia !== null && self::norma($vecchia['testo']) === self::norma($testo)) {
                $nuove[$n] = $vecchia;      // testo già proposto: resta com'è (e con la risposta dell'ufficio)
                continue;
            }
            // Una controproposta dell'ufficio mostrata nella casella e lasciata uguale non è una nuova proposta dell'azienda
            if ($vecchia !== null && $vecchia['stato'] === 'controproposta' && self::norma($vecchia['testo_ufficio']) === self::norma($testo)) {
                $nuove[$n] = $vecchia;
                continue;
            }
            $nuove[$n] = ['testo' => $testo, 'stato' => 'proposta', 'risposta' => '', 'testo_ufficio' => ''];
            ++$proposte;
        }
        $nonDeciso = false;
        $inAttesaUfficio = false;
        foreach ($nuove as $m) {
            if ($m['stato'] === 'proposta') {
                $inAttesaUfficio = true;
            } elseif ($m['stato'] !== 'accettata') {
                $nonDeciso = true;
            }
        }
        $stato = $inAttesaUfficio ? Costanti::MODIFICHE : ($nonDeciso ? Costanti::RISPOSTA : Costanti::COMPILATA);
        $azienda = $dati['ragione_sociale'];
        $this->conv->salvaDati((int) $c['id'], $azienda, (string) $c['email'], (string) json_encode($dati, JSON_UNESCAPED_UNICODE), !empty($post['consenso_elenco']), (string) ($post['nota'] ?? ''));
        $this->conv->salvaModifiche((int) $c['id'], $nuove ? (string) json_encode($nuove, JSON_UNESCAPED_UNICODE) : '', $stato);
        if ($proposte > 0) {
            $this->notifiche->modificheProposte($this->emailUfficio(), $azienda, count(array_filter($nuove, static fn (array $m): bool => $m['stato'] === 'proposta')));
        }

        return [];
    }

    // ───────────────────────────── L'ufficio valuta le modifiche ─────────────────────────────

    /**
     * Risposta dell'ufficio alle modifiche proposte. $azioni: numero articolo => ['azione' => accetta|rifiuta|controproposta, 'testo' => (controproposta), 'risposta' => nota].
     * Ritorna il messaggio di errore, null se fatto.
     *
     * @param array<string, string|null> $c
     * @param array<int|string, array<string, mixed>> $azioni
     */
    public function rispondiModifiche(array $c, array $azioni): ?string
    {
        if ((string) $c['stato'] !== Costanti::MODIFICHE) {
            return 'Non ci sono modifiche da valutare.';
        }
        $m = $this->modifiche($c);
        foreach ($m as $n => $x) {
            if ($x['stato'] !== 'proposta') {
                continue;
            }
            $a = $azioni[$n] ?? $azioni[(string) $n] ?? null;
            $azione = (string) ($a['azione'] ?? '');
            if (!in_array($azione, ['accetta', 'rifiuta', 'controproposta'], true)) {
                return 'Per ogni articolo scegli se accettare, rifiutare o rispondere con un altro testo.';
            }
            $risposta = mb_substr(trim((string) ($a['risposta'] ?? '')), 0, 500);
            if ($azione === 'rifiuta' && $risposta === '') {
                return 'Spiega all\'azienda perché l\'articolo ' . $n . ' non si può modificare.';
            }
            if ($azione === 'controproposta') {
                $testo = trim(str_replace("\r\n", "\n", (string) ($a['testo'] ?? '')));
                if ($testo === '') {
                    return 'Scrivi il testo che proponi per l\'articolo ' . $n . '.';
                }
                $m[$n] = ['testo' => $x['testo'], 'stato' => 'controproposta', 'risposta' => $risposta, 'testo_ufficio' => $testo];
            } else {
                $m[$n] = ['testo' => $x['testo'], 'stato' => $azione === 'accetta' ? 'accettata' : 'rifiutata', 'risposta' => $risposta, 'testo_ufficio' => ''];
            }
        }
        $tutte = true;
        foreach ($m as $x) {
            $tutte = $tutte && $x['stato'] === 'accettata';
        }
        $this->conv->salvaModifiche((int) $c['id'], (string) json_encode($m, JSON_UNESCAPED_UNICODE), $tutte ? Costanti::COMPILATA : Costanti::RISPOSTA);
        $this->notifiche->rispostaModifiche((string) $c['email'], (string) $c['azienda'], (string) $c['token'], $tutte);

        return null;
    }

    // ───────────────────────────── L'azienda firma e ricarica ─────────────────────────────

    /**
     * Carica il PDF firmato dall'azienda (elemento di $_FILES). Ritorna il messaggio di errore, null se è andato a buon fine.
     *
     * @param array<string, string|null> $c
     * @param array<string, mixed>|null $file
     */
    public function caricaDaUpload(array $c, ?array $file): ?string
    {
        $errore = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($file === null || $errore === UPLOAD_ERR_NO_FILE) {
            return 'Scegli il PDF firmato.';
        }
        if ($errore === UPLOAD_ERR_INI_SIZE || $errore === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > Costanti::MAX_FILE) {
            MessaggiFirma::registraRifiuto('convenzione dell\'azienda', MessaggiFirma::GRANDE);

            return 'Il file è troppo grande: il limite è ' . (Costanti::MAX_FILE / 1048576) . ' MB.';
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($errore !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            return 'Il caricamento non è riuscito: riprova.';
        }

        return $this->carica($c, (string) file_get_contents($tmp));
    }

    /**
     * Sotto il blocco della convenzione e con la riga riletta: se nel frattempo l'ufficio l'ha presa in carico o rifiutata, non si scrive sopra.
     *
     * @param array<string, string|null> $c
     */
    public function carica(array $c, string $pdf): ?string
    {
        try {
            return $this->conv->sottoBlocco((int) $c['id'], fn (): ?string => $this->caricaSottoBlocco($this->conv->perId((int) $c['id']) ?: $c, $pdf));
        } catch (BloccoOccupato $e) {
            return $e->getMessage();
        }
    }

    /** @param array<string, string|null> $c riga appena riletta sotto il blocco */
    private function caricaSottoBlocco(array $c, string $pdf): ?string
    {
        if (!self::caricabile($c)) {
            return $this->modificheAperte($c) ? 'Prima di firmare vanno decise le modifiche agli articoli.' : 'La convenzione è già stata presa in carico dal Dipartimento: non si può più sostituire. Se serve una correzione, scrivi all\'ufficio.';
        }
        if ($this->modificheAperte($c)) {
            return 'Prima di firmare vanno decise le modifiche agli articoli.';
        }
        if (!empty($c['file_firmato']) && $this->invioInEntrataInCorso($c)) {
            return 'Il Dipartimento sta protocollando proprio adesso il file che hai caricato: non si può sostituire in questo momento. Riprova tra poco.';
        }
        if ($pdf === '' || strlen($pdf) > Costanti::MAX_FILE) {
            return MessaggiFirma::rifiuta('convenzione dell\'azienda', $pdf === '' ? MessaggiFirma::VUOTO : MessaggiFirma::GRANDE, [], (int) (Costanti::MAX_FILE / 1048576));
        }
        if (strncmp($pdf, '%PDF-', 5) !== 0) {
            return MessaggiFirma::rifiuta('convenzione dell\'azienda', MessaggiFirma::causaNonPdf($pdf));
        }
        $firme = $this->firme->firmePades($pdf);
        $vere = MessaggiFirma::senzaMarche($firme);
        if (!$vere) {
            return MessaggiFirma::rifiuta('convenzione dell\'azienda', MessaggiFirma::SENZA_FIRMA, $firme);
        }
        // Firma non integra o file modificato dopo la firma non bloccano: li legge l'ufficio prima di prendere in carico
        $avviso = MessaggiFirma::avvisi($firme);
        $cartella = $this->sito->radice() . '/' . Costanti::DIR_FIRMATE;
        $this->upload->cartellaProtetta($cartella, 'Convenzioni di tesi firmate dalle aziende: si scaricano solo dal pannello e dalla pagina con il link personale');
        $nome = bin2hex(random_bytes(16)) . '.pdf';
        if (@file_put_contents($cartella . $nome, $pdf) !== strlen($pdf)) {
            File::togli($cartella . $nome);

            return 'Non riesco a salvare il file: riprova tra poco.';
        }
        $sostituita = !empty($c['file_firmato']);
        $vecchio = $sostituita ? $this->percorsoSicuro((string) $c['file_firmato'], Costanti::DIR_FIRMATE) : null;
        $firmatario = mb_substr(trim((string) ($vere[array_key_last($vere)]['nome'] ?? '')), 0, 255);
        if (!$this->conv->salvaFirmata((int) $c['id'], Costanti::DIR_FIRMATE . $nome, $firmatario, $avviso)) {
            // nel frattempo la convenzione è andata avanti: il file nuovo non serve e quello di prima resta com'era
            File::togli($cartella . $nome);

            return 'La convenzione è cambiata nel frattempo (il Dipartimento l\'ha già presa in carico): il file non è stato caricato. Ricarica la pagina.';
        }
        if ($vecchio !== null) {
            File::togli($vecchio);
        }
        $this->notifiche->firmataCaricata($this->emailUfficio(), (string) $c['azienda'], $firmatario, $sostituita);
        $this->notifiche->firmataRicevuta((string) $c['email'], (string) $c['azienda'], (string) $c['token']);

        return null;
    }

    // ───────────────────────────── «Controlla la convenzione», protocollo in entrata e invio al Direttore ─────────────────────────────

    /**
     * «Controlla la convenzione»: la validità decisa dall'ufficio (dal / al), salvata sulla convenzione e usata dal protocollo automatico e dal modulo finale.
     * Date vuote = non decise. Ritorna il motivo se non si salva.
     */
    public function salvaControllo(int $id, ?string $dal, ?string $al): ?string
    {
        $ok = static fn (?string $x): ?string => $x !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $x) && strtotime($x) !== false ? $x : null;
        $dal = $ok($dal);
        $al = $ok($al);
        if ($dal !== null && $al !== null && $al < $dal) {
            return 'Controlla le date di validità: «valida fino al» non può precedere «valida dal».';
        }
        $c = $this->conv->perId($id);
        if (!$c || !in_array((string) $c['stato'], [Costanti::CARICATA, Costanti::IN_CARICO, Costanti::DA_FIRMARE, Costanti::FIRMATA], true)) {
            return 'Questa convenzione non è in lavorazione: le date non si possono salvare.';
        }
        try {
            return $this->conv->sottoBlocco($id, function () use ($id, $dal, $al): ?string {
                $this->conv->salvaControllo($id, $dal, $al);

                return null;
            });
        } catch (BloccoOccupato $e) {
            return $e->getMessage();
        }
    }

    /**
     * Il file con l'impronta SHA-256 $inviata (quello che è partito per Titulus) è ancora quello della convenzione? null se sì (o se non si controlla), altrimenti il
     * messaggio per l'ufficio: Titulus ha dato il numero $numero a un file che non c'è più, e non si registra su quello nuovo.
     *
     * @param array<string, mixed> $c riga appena riletta sotto il blocco
     */
    private function erroreFileCambiato(array $c, string $colonna, string $cartella, ?string $inviata, string $numero): ?string
    {
        if ($inviata === null) {
            return null;
        }
        $attuale = $this->contenutoColonna($c, $colonna, $cartella);
        if ($attuale !== null && hash_equals($inviata, hash('sha256', $attuale))) {
            return null;
        }

        return 'Titulus ha dato il numero ' . $numero . ' al file di prima: nel frattempo il file di questa convenzione è cambiato, quindi il numero NON è stato registrato sul file nuovo. '
            . 'Controlla in Titulus e poi usa «Invia al protocollo» (il file nuovo avrà un numero suo) oppure scrivi a mano il numero che vuoi tenere.';
    }

    /** Il contenuto di un file della convenzione (solo dentro la sua cartella), null se non c'è o non si legge. @param array<string, mixed> $c */
    private function contenutoColonna(array $c, string $colonna, string $cartella): ?string
    {
        $p = !empty($c[$colonna]) ? $this->percorsoSicuro((string) $c[$colonna], $cartella) : null;
        $x = $p !== null ? file_get_contents($p) : false;

        return $x === false || $x === '' ? null : $x;
    }

    /**
     * Il riferimento con cui Titulus conosce il protocollo in entrata di questa convenzione (tipo + impronta del file dell'azienda): serve a prenotare l'invio e a
     * ritrovare il documento. Senza file leggibile vale il tipo di base.
     *
     * @param array<string, mixed> $c
     */
    public function rifIngresso(array $c): string
    {
        $x = $this->contenutoColonna($c, 'file_firmato', Costanti::DIR_FIRMATE);

        return $x !== null ? \App\Protocollo\InvioProtocollo::rifPerFile(ProtocollaConvenzioneTesiInTitulus::RIF, $x) : ProtocollaConvenzioneTesiInTitulus::RIF;
    }

    /** Come rifIngresso(), per il protocollo definitivo (file firmato dal Direttore). @param array<string, mixed> $c */
    public function rifDefinitivo(array $c): string
    {
        $x = $this->contenutoColonna($c, 'file_definitivo', Costanti::DIR_DEFINITIVE);

        return $x !== null ? \App\Protocollo\InvioProtocollo::rifPerFile(ProtocollaDefinitivaTesiInTitulus::RIF, $x) : ProtocollaDefinitivaTesiInTitulus::RIF;
    }

    /** C'è un documento protocollato caricato a mano dall'ufficio? @param array<string, mixed> $c */
    public function haProtocollatoAMano(array $c): bool
    {
        return (string) ($c['protocollato_origine'] ?? '') === 'mano' && $this->contenutoColonna($c, 'file_protocollato', Costanti::DIR_DEFINITIVE) !== null;
    }

    /**
     * Protocollo in entrata (solo numero e data: il file non si ricarica). La convenzione è presa in carico; se il Direttore è indicato gli arriva subito il link per
     * firmare. Sotto il blocco della convenzione, con la riga riletta. $improntaInviata (SHA-256) = il file che è partito per Titulus: se nel frattempo il file è cambiato
     * il numero non si registra sul file nuovo. Ritorna ['errore' => ?string, 'direttore' => ?string] («direttore» è null se il link è partito).
     *
     * @return array{errore: string|null, direttore: string|null}
     */
    public function protocolloIngresso(int $id, string $numero, string $data, string $autore, ?string $improntaInviata = null): array
    {
        try {
            return $this->conv->sottoBlocco($id, fn (): array => $this->protocolloIngressoSottoBlocco($id, $numero, $data, $autore, $improntaInviata));
        } catch (BloccoOccupato $e) {
            return ['errore' => $e->getMessage(), 'direttore' => null];
        }
    }

    /** @return array{errore: string|null, direttore: string|null} */
    private function protocolloIngressoSottoBlocco(int $id, string $numero, string $data, string $autore, ?string $improntaInviata): array
    {
        $ko = static fn (string $m): array => ['errore' => $m, 'direttore' => null];
        $c = $this->conv->perId($id);
        if (!$c || empty($c['file_firmato'])) {
            return $ko('Non c\'è nessuna convenzione firmata da protocollare.');
        }
        if ((string) $c['stato'] !== Costanti::CARICATA) {
            return $ko('La convenzione non è in attesa del protocollo in entrata.');
        }
        $numero = mb_substr(trim($numero), 0, 80);
        if ($numero === '') {
            return $ko('Indica il numero del protocollo in entrata.');
        }
        $errore = $this->erroreFileCambiato($c, 'file_firmato', Costanti::DIR_FIRMATE, $improntaInviata, $numero);
        if ($errore !== null) {
            return $ko($errore);
        }
        $dataOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? $data : null;
        if (!$this->conv->protocolloIngresso($id, $numero, $dataOk, $autore)) {
            return $ko('La convenzione è cambiata nel frattempo (è stata rifiutata o presa in carico da un altro): il protocollo non è stato registrato. Ricarica la pagina.');
        }
        $this->notifiche->presaInCarico((string) $c['email'], (string) $c['azienda'], (string) $c['token'], $numero . ($dataOk ? ' del ' . date('d/m/Y', (int) strtotime($dataOk)) : ''));

        return ['errore' => null, 'direttore' => $this->inviaDirettore($id)];
    }

    /** Manda (o rimanda) il link di firma a chi firma ora. Ritorna il motivo se non parte, null se è partito. */
    public function inviaDirettore(int $id): ?string
    {
        $c = $this->conv->perId($id);
        if (!$c || empty($c['file_firmato']) || !in_array((string) $c['stato'], [Costanti::IN_CARICO, Costanti::DA_FIRMARE], true)) {
            return 'Prima prendi in carico la convenzione, poi puoi mandarla al Direttore.';
        }
        $d = $this->direzione->chiFirma();
        if (filter_var($d['email'], FILTER_VALIDATE_EMAIL) === false) {
            return 'Indica prima il Direttore del Dipartimento in Persone e permessi → Uffici e persone → Direzione: il link di firma arriva alla sua email.';
        }
        $token = bin2hex(random_bytes(20));
        if (!$this->conv->daFirmare($id, $token)) {
            return 'La convenzione non è più in attesa di andare al Direttore (nel frattempo è stata rifiutata, firmata o registrata): ricarica la pagina.';
        }
        $this->notifiche->firmaRichiesta($d['email'], $d['nome'], (string) $c['azienda'], $token);

        return null;
    }

    /** L'ufficio rimanda la convenzione all'azienda con un motivo (obbligatorio): la riceve per email e ricarica un altro file. */
    public function rifiuta(int $id, string $motivo): ?string
    {
        try {
            return $this->conv->sottoBlocco($id, fn (): ?string => $this->rifiutaSottoBlocco($id, $motivo));
        } catch (BloccoOccupato $e) {
            return $e->getMessage();
        }
    }

    private function rifiutaSottoBlocco(int $id, string $motivo): ?string
    {
        $c = $this->conv->perId($id);   // riletta sotto il blocco
        $motivo = trim($motivo);
        if (!$c || empty($c['file_firmato']) || !in_array((string) $c['stato'], [Costanti::CARICATA, Costanti::IN_CARICO, Costanti::DA_FIRMARE], true)) {
            return 'Questa convenzione non si può rifiutare in questo momento.';
        }
        if ($motivo === '') {
            return 'Scrivi il motivo del rifiuto: l\'azienda lo riceve per email.';
        }
        if ($this->invioInEntrataInCorso($c)) {
            return 'Il file di questa convenzione sta andando in questo momento al protocollo: non si può rifiutare adesso. Riprova tra poco.';
        }
        if (!$this->conv->rifiuta($id, $motivo)) {
            return 'Questa convenzione non si può rifiutare in questo momento: è cambiata nel frattempo. Ricarica la pagina.';
        }
        $this->notifiche->rifiutata((string) $c['email'], (string) $c['azienda'], (string) $c['token'], mb_substr($motivo, 0, 500));

        return null;
    }

    // ───────────────────────────── Il Direttore firma ─────────────────────────────

    public function firmaRemotaDisponibile(): bool
    {
        return $this->firmaRemota->disponibile();
    }

    /** @return array{nome: string, email: string, cf: string} chi firma ora */
    public function chiFirma(): array
    {
        return $this->direzione->chiFirma();
    }

    /** @param array<string, mixed>|null $utente */
    public function puoFirmare(?array $utente): bool
    {
        return $this->direzione->puoFirmare($utente);
    }

    /** @param array<string, string|null> $c */
    public function pdfDaFirmare(array $c): ?string
    {
        return !empty($c['file_firmato']) ? $this->percorsoSicuro((string) $c['file_firmato'], Costanti::DIR_FIRMATE) : null;
    }

    /** @param array<string, string|null> $c */
    public function firmaConAruba(array $c, string $utente, string $password, string $otp): ?string
    {
        if ((string) $c['stato'] !== Costanti::DA_FIRMARE) {
            return 'La convenzione non è in attesa della tua firma.';
        }
        $p = $this->pdfDaFirmare($c);
        if ($p === null) {
            return 'Manca il PDF della convenzione.';
        }
        $pdf = (string) file_get_contents($p);
        [$firmato, $err] = $this->firmaRemota->firma($pdf, $utente, $password, $otp, null, 'Il Direttore del Dipartimento');
        if ($firmato === null) {
            return $err ?: 'Firma non riuscita.';
        }

        // la firma Aruba è fatta con un OTP che non si riusa: se il blocco è occupato il PDF firmato si conserva e si registra al tentativo successivo
        return $this->registraFirmaDirettore($c, $firmato, 'firma remota Aruba', true);
    }

    /** @param array<string, string|null> $c */
    public function caricaFirmatoDirettore(array $c, string $pdfFirmato): ?string
    {
        if (strlen($pdfFirmato) > Costanti::MAX_FILE * 2) {
            MessaggiFirma::registraRifiuto('firma del Direttore tesi', MessaggiFirma::GRANDE);

            return 'Il file è troppo grande.';
        }

        return $this->registraFirmaDirettore($c, $pdfFirmato, 'PDF firmato caricato');
    }

    /**
     * Controlla il PDF firmato (il resto diventa un avviso per l'ufficio), lo salva e avvisa l'ufficio. Il salvataggio sta sotto il blocco della convenzione, con la
     * riga riletta: se mentre il Direttore firmava (con Aruba è questione di secondi) l'ufficio ha rifiutato, rimandato il link o registrato a mano il numero, la firma
     * non scavalca niente. Se il blocco è occupato la firma è già fatta e non si può chiedere un secondo codice: si aspetta più a lungo del solito.
     *
     * @param array<string, string|null> $c
     */
    private function registraFirmaDirettore(array $c, string $firmato, string $come, bool $conservabile = false): ?string
    {
        if ((string) $c['stato'] !== Costanti::DA_FIRMARE) {
            return 'La convenzione non è in attesa della tua firma.';
        }
        [$esito, $messaggio] = $this->provaRegistraFirma($c, $firmato, $come);
        if ($esito === 'bloccato') {
            // PDF caricato a mano: «ricaricalo». Firma Aruba: il file firmato si conserva (cartella chiusa al web) e si registra dopo, senza un secondo OTP
            if (!$conservabile) {
                return self::RICARICALO;
            }

            return $this->conservaFirma($c, $firmato, $come) ? self::FIRMA_CONSERVATA : self::NON_CONSERVATA;
        }

        return $messaggio;
    }

    /**
     * Un tentativo di registrazione. Esito: «ok» (registrata, ufficio avvisato, protocollo automatico partito), «bloccato» (il blocco è occupato: non è stato fatto
     * niente) o «errore» (la firma non si registra, con il motivo).
     *
     * @param array<string, string|null> $c
     * @return array{0: string, 1: string|null}
     */
    private function provaRegistraFirma(array $c, string $firmato, string $come): array
    {
        try {
            $esito = $this->conv->sottoBlocco((int) $c['id'], fn (): array => $this->registraFirmaSottoBlocco($c, $firmato, $come), $this->conv->attesaLunga());
        } catch (BloccoOccupato) {
            error_log('[convenzione di tesi] firma del Direttore non registrata adesso: blocco occupato (convenzione ' . (int) $c['id'] . ')');

            return ['bloccato', null];
        }
        if ($esito['errore'] !== null) {
            return ['errore', $esito['errore']];
        }
        $this->notifiche->firmataDalDirettore($this->emailUfficio(), (string) $c['azienda']);
        // La firma è salvata e l'ufficio avvisato, il blocco è libero: solo ora, e senza mai far fallire la firma, può partire il protocollo automatico
        if ($this->dopoLaFirma !== null) {
            try {
                ($this->dopoLaFirma)((int) $c['id']);
            } catch (\Throwable $e) {
                error_log('[convenzione di tesi] protocollo automatico dopo la firma: ' . $e->getMessage());
            }
        }

        return ['ok', null];
    }

    private function conservate(): FirmeConservate
    {
        return $this->conservateCache ??= new FirmeConservate($this->upload, $this->sito);
    }

    /** Conserva il PDF firmato, legato alla convenzione e all'impronta del file da cui si è partiti. @param array<string, string|null> $c */
    private function conservaFirma(array $c, string $firmato, string $come): bool
    {
        $p = $this->pdfDaFirmare($c);
        if ($p === null) {
            return false;
        }
        $ok = $this->conservate()->conserva('tesi', (int) $c['id'], (string) $c['stato'], (string) ($c['token_direttore'] ?? ''), (string) ($c['file_firmato'] ?? ''), (string) hash_file('sha256', $p), $firmato, $come, $this->direzione->chiFirma()['nome']);
        error_log('[convenzione di tesi] firma del Direttore conservata in attesa di registrarla (convenzione ' . (int) $c['id'] . ($ok ? ')' : '): NON conservata'));

        return $ok;
    }

    /** C'è una firma del Direttore fatta e conservata, non ancora registrata (la pagina non deve chiedere di firmare di nuovo). */
    public function haFirmaConservata(int $id): bool
    {
        return $this->conservate()->ce('tesi', $id);
    }

    /**
     * Riprova a registrare la firma conservata di una convenzione (il Direttore riapre la pagina, o il cron). La registra SOLO se stato, link e file di partenza sono
     * gli stessi di quando si è firmato; altrimenti la scarta e avvisa l'ufficio. Non chiede mai un secondo codice.
     *
     * @return array{esito: string, messaggio: string}|null null se non c'è niente di conservato; esito: «registrata» | «in_attesa» | «scartata»
     */
    public function riprendiFirmaConservata(int $id): ?array
    {
        $s = $this->conservate()->di('tesi', $id);
        if ($s === null) {
            return null;
        }
        $m = $s['meta'];
        $ora = $this->conv->perId($id);
        $motivo = null;
        if (!$ora) {
            $motivo = 'la convenzione non esiste più';
        } elseif ((string) $ora['stato'] !== Costanti::DA_FIRMARE) {
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
            [$esito, $messaggio] = $this->provaRegistraFirma($ora, $s['pdf'], (string) $m['come'] . ', registrata dopo');
            if ($esito === 'bloccato') {
                return ['esito' => 'in_attesa', 'messaggio' => 'La firma è conservata e si registrerà appena il portale ha finito il lavoro su questa convenzione: riapri la pagina tra poco.'];
            }
            $this->conservate()->scarta('tesi', $id);
            if ($esito === 'ok') {
                return ['esito' => 'registrata', 'messaggio' => 'La firma è stata registrata: la convenzione passa all\'Ufficio per il protocollo. Grazie.'];
            }
            $motivo = (string) $messaggio;
        } else {
            $this->conservate()->scarta('tesi', $id);
        }
        $this->notifiche->firmaNonRegistrata($this->emailUfficio(), (string) ($ora['azienda'] ?? 'Azienda'), $motivo);

        return ['esito' => 'scartata', 'messaggio' => 'La firma conservata non è stata registrata: ' . $motivo . '. L\'ufficio è stato avvisato.'];
    }

    /** Il cron: riprova tutte le firme conservate. @return int quante registrate */
    public function riprendiTutteLeFirme(): int
    {
        $n = 0;
        foreach ($this->conservate()->ids('tesi') as $id) {
            if (($this->riprendiFirmaConservata($id)['esito'] ?? '') === 'registrata') {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * La registrazione vera, con il blocco in mano. $c è la riga di quando il Direttore ha cominciato a firmare: si rilegge e si confronta (fase, link e PDF
     * dell'azienda da cui si è partiti), poi l'UPDATE è condizionato agli stessi valori. Se qualcosa è cambiato il file nuovo si cancella.
     *
     * @param array<string, string|null> $c
     * @return array{errore: string|null}
     */
    private function registraFirmaSottoBlocco(array $c, string $firmato, string $come): array
    {
        $ko = static fn (string $m): array => ['errore' => $m];
        $ora = $this->conv->perId((int) $c['id']);
        if (!$ora) {
            return $ko('Questa convenzione non esiste più.');
        }
        if ((string) $ora['stato'] !== Costanti::DA_FIRMARE) {
            return $ko(match ((string) $ora['stato']) {
                Costanti::RIFIUTATA => 'La convenzione non è più in attesa della tua firma: nel frattempo l\'ufficio l\'ha rimandata all\'azienda. La firma non è stata registrata.',
                Costanti::FIRMATA => 'La convenzione è già stata firmata (da un\'altra scheda o da un altro caricamento): la tua firma non è stata registrata.',
                Costanti::PROTOCOLLATA => 'La convenzione non è più in attesa della tua firma: nel frattempo l\'ufficio l\'ha già registrata. La firma non è stata registrata.',
                default => 'La convenzione non è più in attesa della tua firma (è cambiata mentre firmavi). La firma non è stata registrata.',
            });
        }
        if ((string) ($ora['token_direttore'] ?? '') !== (string) ($c['token_direttore'] ?? '') || (string) ($ora['file_firmato'] ?? '') !== (string) ($c['file_firmato'] ?? '')) {
            return $ko('L\'ufficio ha rimandato la convenzione (nuovo link o nuovo PDF) mentre firmavi: apri il link nuovo che ti è arrivato per email. La firma non è stata registrata.');
        }
        $p = $this->pdfDaFirmare($ora);
        if ($p === null) {
            return $ko('Manca il PDF della convenzione.');
        }
        [$err, $info] = $this->verifica->verifica((string) file_get_contents($p), $firmato, $this->direzione->chiFirma()['cf'], false);
        if ($err) {
            return $ko($err);
        }
        $cartella = $this->sito->radice() . '/' . Costanti::DIR_DEFINITIVE;
        $this->upload->cartellaProtetta($cartella, 'Convenzioni di tesi firmate dal Direttore e protocollate: si scaricano solo dal pannello');
        $nome = bin2hex(random_bytes(16)) . '.pdf';
        if (@file_put_contents($cartella . $nome, $firmato) !== strlen($firmato)) {
            File::togli($cartella . $nome);

            return $ko('Non riesco a salvare il file: riprova tra poco.');
        }
        $chi = ($info['nome'] ?? '') !== '' ? (string) $info['nome'] : $this->direzione->chiFirma()['nome'];
        if (!$this->conv->segnaFirmataDirettore((int) $ora['id'], Costanti::DIR_DEFINITIVE . $nome, $chi . ' (' . $come . ')', (string) $info['avviso'], (string) ($ora['token_direttore'] ?? ''), (string) ($ora['file_firmato'] ?? ''))) {
            File::togli($cartella . $nome);

            return $ko('La convenzione è cambiata mentre firmavi: la firma non è stata registrata.');
        }

        return ['errore' => null];
    }

    // ───────────────────────────── Protocollo in uscita: la convenzione è stipulata ─────────────────────────────

    /**
     * Registra il protocollo definitivo e la validità: la convenzione è stipulata (archivio ed elenco pubblico). $dati: protocollo, protocollo_data, valida_dal, valida_al.
     * $pdf = il PDF con la segnatura di protocollo ($origine: «mano» se caricato dall'ufficio, «copia» se è la copia stampigliata dal portale); se manca resta quello che
     * c'è e, senza niente, vale il PDF firmato dal Direttore. $improntaInviata (SHA-256) = il PDF firmato che è partito per Titulus: se nel frattempo è cambiato il
     * numero non si registra sul file nuovo. Sotto il blocco della convenzione, con la riga riletta; la registrazione è una sola operazione sul database e
     * ripetibile senza doppioni; gli avvisi (azienda, studenti, relatori) partono dopo e una volta sola per persona (avvisaStipula()).
     * Ritorna il messaggio di errore, null se è andato a buon fine.
     *
     * @param array<string, mixed> $dati
     */
    public function completa(int $id, array $dati, string $pdf = '', ?string $improntaInviata = null, string $origine = 'mano'): ?string
    {
        try {
            $errore = $this->conv->sottoBlocco($id, fn (): ?string => $this->completaSottoBlocco($id, $dati, $pdf, $improntaInviata, $origine));
        } catch (BloccoOccupato $e) {
            return $e->getMessage();
        }
        if ($errore === null) {
            $this->avvisaStipula($id);
        }

        return $errore;
    }

    /** @param array<string, mixed> $dati */
    private function completaSottoBlocco(int $id, array $dati, string $pdf, ?string $improntaInviata, string $origine): ?string
    {
        $c = $this->conv->perId($id);
        if (!$c) {
            return 'Convenzione non trovata.';
        }
        if ((string) $c['stato'] === Costanti::PROTOCOLLATA) {
            return 'Questa convenzione è già stata protocollata e registrata.';
        }
        if ((string) $c['stato'] !== Costanti::FIRMATA) {
            return 'La convenzione deve prima essere firmata dal Direttore.';
        }
        $protocollo = mb_substr(trim((string) ($dati['protocollo'] ?? '')), 0, 100);
        if ($protocollo === '') {
            return 'Indica il numero di protocollo.';
        }
        $ok = static fn ($x): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $x) ? (string) $x : null;
        $dal = $ok($dati['valida_dal'] ?? null);
        $al = $ok($dati['valida_al'] ?? null);
        if (!$dal || !$al || $al < $dal) {
            return 'Controlla le date di validità: «valida fino al» non può precedere «valida dal».';
        }
        $errore = $this->erroreFileCambiato($c, 'file_definitivo', Costanti::DIR_DEFINITIVE, $improntaInviata, $protocollo);
        if ($errore !== null) {
            return $errore;
        }
        $percorso = null;
        if ($pdf !== '') {
            if (strncmp($pdf, '%PDF-', 5) !== 0 || strlen($pdf) > Costanti::MAX_FILE) {
                return 'Il PDF protocollato deve essere un PDF fino a ' . (Costanti::MAX_FILE / 1048576) . ' MB.';
            }
            $percorso = $this->salvaDefinitivo($pdf);
            if ($percorso === null) {
                return 'Non riesco a salvare il file: riprova tra poco.';
            }
        }
        $vecchio = $percorso !== null && !empty($c['file_protocollato']) ? $this->percorsoSicuro((string) $c['file_protocollato'], Costanti::DIR_DEFINITIVE) : null;
        $registrata = $this->conv->transazione(function () use ($id, $protocollo, $dati, $ok, $dal, $al, $percorso, $origine): bool {
            if (!$this->conv->segnaProtocollata($id, $protocollo, $ok($dati['protocollo_data'] ?? null), $dal, $al, $percorso, $origine === 'copia' ? 'copia' : 'mano')) {
                return false;
            }
            $this->conv->salvaEsito($id, '{}');   // nessun avviso ancora mandato: li registra avvisaStipula()

            return true;
        });
        if (!$registrata) {
            if ($percorso !== null) {
                File::togli($this->sito->radice() . '/' . $percorso);
            }

            return 'La convenzione è cambiata nel frattempo (è già stata registrata da un altro): ricarica la pagina.';
        }
        if ($vecchio !== null) {
            File::togli($vecchio);
        }

        return null;
    }

    /**
     * Gli avvisi della stipula: l'azienda, gli studenti che hanno invitato l'azienda dalla loro pratica (pratiche.convenzione_tesi_id) e i relatori di quelle pratiche.
     * Ognuno li riceve UNA volta sola: gli indirizzi già avvisati stanno in esito_registrazione, quindi si può richiamare dopo un errore (o con «Ripeti gli avvisi»)
     * senza doppioni. Non fallisce mai: un errore di posta lascia l'avviso da rifare.
     *
     * @return array{azienda: bool, studenti: int, relatori: int, restano: int}|null null se la convenzione non è stipulata o il blocco è occupato
     */
    public function avvisaStipula(int $id): ?array
    {
        try {
            return $this->conv->sottoBlocco($id, fn (): ?array => $this->avvisaStipulaSottoBlocco($id));
        } catch (BloccoOccupato) {
            return null;
        } catch (\Throwable $e) {
            error_log('Convenzione di tesi stipulata, avvisi: ' . $e->getMessage());

            return null;
        }
    }

    /** @return array{azienda: bool, studenti: int, relatori: int, restano: int}|null */
    private function avvisaStipulaSottoBlocco(int $id): ?array
    {
        $c = $this->conv->perId($id);
        if (!$c || (string) $c['stato'] !== Costanti::PROTOCOLLATA) {
            return null;
        }
        $esito = json_decode((string) ($c['esito_registrazione'] ?? ''), true);
        $esito = is_array($esito) ? $esito : [];
        $adesso = $this->orologio->adesso()->format('Y-m-d H:i:s');
        $nuovi = ['azienda' => false, 'studenti' => 0, 'relatori' => 0, 'restano' => 0];
        // per riferimento: una fn() fotografa $esito al momento in cui è scritta e salverebbe sempre l'elenco vuoto
        $salva = function () use ($id, &$esito): void {
            $this->conv->salvaEsito($id, (string) json_encode($esito, JSON_UNESCAPED_UNICODE));
        };
        $dataProt = (string) ($c['protocollo_data'] ?? '');
        if (empty($esito['azienda'])) {
            if ($this->notifiche->attiva((string) $c['email'], (string) $c['azienda'], (string) $c['token'], (string) $c['protocollo'] . ($dataProt !== '' ? ' del ' . date('d/m/Y', (int) strtotime($dataProt)) : ''), (string) $c['valida_dal'], (string) $c['valida_al'])) {
                $esito['azienda'] = $adesso;
                $salva();
                $nuovi['azienda'] = true;
            } else {
                ++$nuovi['restano'];
            }
        }
        $link = $this->sito->urlBase() . '/pratiche.php';
        foreach (['studenti' => $this->emailStudenti($id), 'relatori' => $this->emailRelatori($id)] as $gruppo => $emails) {
            $fatti = array_map('strval', (array) ($esito[$gruppo] ?? []));
            foreach ($emails as $email) {
                if (in_array($email, $fatti, true)) {
                    continue;
                }
                $inviata = $gruppo === 'studenti'
                    ? $this->notifiche->stipulataAlloStudente($email, (string) $c['azienda'], $link)
                    : $this->notifiche->stipulataAlRelatore($email, (string) $c['azienda'], $this->sito->urlBase() . '/area_personale.php');
                if ($inviata) {
                    $fatti[] = $email;
                    $esito[$gruppo] = $fatti;
                    $salva();
                    ++$nuovi[$gruppo];
                } else {
                    ++$nuovi['restano'];
                }
            }
        }

        return $nuovi;
    }

    /** @return list<string> email degli studenti che hanno invitato l'azienda dalla loro pratica (minuscole, senza doppioni) */
    private function emailStudenti(int $convenzioneId): array
    {
        try {
            $out = [];
            foreach ($this->db->righe('SELECT email FROM pratiche WHERE convenzione_tesi_id = ? AND email <> ?', [$convenzioneId, '']) as $p) {
                $e = strtolower(trim((string) $p['email']));
                if (filter_var($e, FILTER_VALIDATE_EMAIL) !== false) {
                    $out[$e] = true;
                }
            }

            return array_keys($out);
        } catch (\Throwable $e) {
            error_log('Convenzione di tesi stipulata, elenco studenti: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Per l'ufficio, in «Controlla la convenzione»: gli studenti che hanno invitato questa azienda dalla loro pratica (e che alla stipula ricevono l'avviso).
     * Solo le pratiche collegate a QUESTA convenzione: niente studenti di altre aziende.
     *
     * @return list<array{pratica: int, codice: string, nome: string, email: string}>
     */
    public function studentiCollegati(int $convenzioneId): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT id, codice, nome, cognome, email FROM pratiche WHERE convenzione_tesi_id = ? ORDER BY id', [$convenzioneId]) as $p) {
            $out[] = ['pratica' => (int) $p['id'], 'codice' => (string) $p['codice'], 'nome' => trim((string) $p['nome'] . ' ' . (string) $p['cognome']), 'email' => (string) $p['email']];
        }

        return $out;
    }

    /** @return list<string> email dei relatori (le persone che firmano) delle pratiche collegate alla convenzione */
    private function emailRelatori(int $convenzioneId): array
    {
        try {
            $out = [];
            foreach ($this->db->righe("SELECT DISTINCT f.email FROM pratiche_firme f JOIN pratiche p ON p.id = f.pratica_id WHERE p.convenzione_tesi_id = ? AND f.tipo = 'persona' AND f.email <> ''", [$convenzioneId]) as $r) {
                $e = strtolower(trim((string) $r['email']));
                if (filter_var($e, FILTER_VALIDATE_EMAIL) !== false) {
                    $out[$e] = true;
                }
            }

            return array_keys($out);
        } catch (\Throwable $e) {
            error_log('Convenzione di tesi stipulata, elenco relatori: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * L'ufficio carica il documento protocollato (la copia con la segnatura): vale più della copia stampigliata dal portale, anche a convenzione stipulata. Il PDF firmato
     * dal Direttore e l'originale di Titulus non si toccano mai.
     */
    public function caricaProtocollato(int $id, string $pdf): ?string
    {
        $prima = $this->conv->perId($id);
        if (!$prima || !in_array((string) $prima['stato'], [Costanti::FIRMATA, Costanti::PROTOCOLLATA], true)) {
            return 'Il documento protocollato si carica dopo la firma del Direttore.';
        }
        if ($pdf === '' || strncmp($pdf, '%PDF-', 5) !== 0 || strlen($pdf) > Costanti::MAX_FILE) {
            return 'Il documento protocollato deve essere un PDF fino a ' . (Costanti::MAX_FILE / 1048576) . ' MB.';
        }
        try {
            return $this->conv->sottoBlocco($id, function () use ($id, $pdf): ?string {
                $c = $this->conv->perId($id);
                if (!$c) {
                    return 'Convenzione non trovata.';
                }
                $nuovo = $this->salvaDefinitivo($pdf);
                if ($nuovo === null) {
                    return 'Non riesco a salvare il file: riprova tra poco.';
                }
                $vecchio = !empty($c['file_protocollato']) ? $this->percorsoSicuro((string) $c['file_protocollato'], Costanti::DIR_DEFINITIVE) : null;
                $this->conv->salvaProtocollato($id, $nuovo);
                if ($vecchio !== null) {
                    File::togli($vecchio);
                }

                return null;
            });
        } catch (BloccoOccupato $e) {
            return $e->getMessage();
        }
    }

    private function salvaDefinitivo(string $pdf): ?string
    {
        $cartella = $this->sito->radice() . '/' . Costanti::DIR_DEFINITIVE;
        $this->upload->cartellaProtetta($cartella, 'Convenzioni di tesi firmate dal Direttore e protocollate: si scaricano solo dal pannello');
        $nome = bin2hex(random_bytes(16)) . '.pdf';
        if (@file_put_contents($cartella . $nome, $pdf) !== strlen($pdf)) {
            File::togli($cartella . $nome);

            return null;
        }

        return Costanti::DIR_DEFINITIVE . $nome;
    }

    // ───────────────────────────── Archivio: convenzioni già in essere ─────────────────────────────

    /**
     * Inserisce a mano una convenzione già stipulata (senza passare dall'iter). $post: dati dell'azienda, email, protocollo, protocollo_data, valida_dal, valida_al, consenso_elenco.
     * Ritorna [errori, id].
     *
     * @param array<string, mixed> $post
     * @return array{0: list<string>, 1: int}
     */
    public function archivia(array $post, string $pdf, string $autore, int $id = 0): array
    {
        $errori = [];
        $azienda = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($post['ragione_sociale'] ?? ''))), 0, 200);
        if ($azienda === '') {
            $errori[] = 'Indica la ragione sociale dell\'ente o dell\'azienda.';
        }
        $email = strtolower(trim((string) ($post['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errori[] = 'L\'email dell\'azienda non è valida.';
        }
        $ok = static fn ($x): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $x) ? (string) $x : null;
        $dal = $ok($post['valida_dal'] ?? null);
        $al = $ok($post['valida_al'] ?? null);
        if (!$dal || !$al || $al < $dal) {
            $errori[] = 'Controlla le date di validità: «valida fino al» non può precedere «valida dal».';
        }
        $protocollo = mb_substr(trim((string) ($post['protocollo'] ?? '')), 0, 100);
        if ($protocollo === '') {
            $errori[] = 'Indica il numero di protocollo.';
        }
        if ($pdf !== '' && (strncmp($pdf, '%PDF-', 5) !== 0 || strlen($pdf) > Costanti::MAX_FILE)) {
            $errori[] = 'Il file della convenzione deve essere un PDF fino a ' . (Costanti::MAX_FILE / 1048576) . ' MB.';
        }
        if ($errori) {
            return [$errori, 0];
        }
        [$dati] = $this->validaDatiArchivio($post);
        $json = (string) json_encode($dati, JSON_UNESCAPED_UNICODE);
        $percorso = $pdf !== '' ? $this->salvaDefinitivo($pdf) : null;
        if ($id > 0 && $this->conv->perId($id)) {
            $this->conv->aggiornaArchivio($id, $azienda, $json, !empty($post['consenso_elenco']), $protocollo, $ok($post['protocollo_data'] ?? null), (string) $dal, (string) $al);
            if ($percorso !== null) {
                $this->conv->impostaFileProtocollato($id, $percorso);
            }

            return [[], $id];
        }

        return [[], $this->conv->creaDaArchivio($azienda, $email, $json, !empty($post['consenso_elenco']), $protocollo, $ok($post['protocollo_data'] ?? null), (string) $dal, (string) $al, $percorso, $autore)];
    }

    /** Per l'archivio i dati dell'azienda sono facoltativi: si tiene quello che c'è. @param array<string, mixed> $post @return array{0: array<string, string>} */
    private function validaDatiArchivio(array $post): array
    {
        $out = [];
        foreach (['ragione_sociale', 'forma_giuridica', 'cf', 'piva', 'comune', 'provincia', 'cap', 'indirizzo', 'pec', 'telefono', 'sito', 'settore', 'rappr_titolo', 'rappr_nome', 'rappr_qualifica', 'referente_nome', 'referente_email', 'referente_telefono'] as $k) {
            $v = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($post[$k] ?? ''))), 0, 200);
            if ($v !== '') {
                $out[$k] = $v;
            }
        }
        if (isset($out['sito']) && !preg_match('#^https?://#i', $out['sito'])) {
            $out['sito'] = 'https://' . $out['sito'];
        }

        return [$out];
    }

    public function elimina(int $id): void
    {
        $c = $this->conv->perId($id);
        if (!$c) {
            return;
        }
        foreach ([['file_firmato', Costanti::DIR_FIRMATE], ['file_definitivo', Costanti::DIR_DEFINITIVE], ['file_protocollato', Costanti::DIR_DEFINITIVE], ['file_originale', Costanti::DIR_DEFINITIVE]] as [$col, $dir]) {
            $p = !empty($c[$col]) ? $this->percorsoSicuro((string) $c[$col], $dir) : null;
            if ($p !== null) {
                \App\Core\File::togli($p);
            }
        }
        $this->conv->elimina($id);
    }

    // ───────────────────────────── Elenchi e file ─────────────────────────────

    /** @return array<string, int> */
    public function conteggi(): array
    {
        return $this->conv->conteggi();
    }

    /** Quante convenzioni aspettano l'ufficio (per il badge e «Da fare per te»). */
    public function daFare(): int
    {
        $n = 0;
        foreach ($this->conv->conteggi() as $stato => $q) {
            if (in_array($stato, Costanti::DA_FARE_UFFICIO, true)) {
                $n += $q;
            }
        }

        return $n;
    }

    /** @param list<string> $stati @return list<array<string, string|null>> */
    public function inStati(array $stati): array
    {
        return $this->conv->inStati($stati);
    }

    /** @return list<array<string, string|null>> */
    public function archivio(string $cerca = '', string $vista = 'tutte'): array
    {
        return $this->conv->archivio($cerca, $this->oggi(), $vista);
    }

    /** @return list<array<string, string|null>> */
    public function elencoPubblico(): array
    {
        return $this->conv->elencoPubblico($this->oggi());
    }

    /**
     * Il file di una convenzione da scaricare: percorso reale (solo dentro le cartelle protette) e nome.
     * $quale: azienda (il PDF firmato dall'azienda) | direttore (firmato dal Direttore) | protocollato (con la segnatura: caricato dall'ufficio o copia del portale) |
     * originale (il file di Titulus, solo per l'ufficio: mai nella pagina dell'azienda) | finale (il migliore disponibile per l'azienda: protocollato, poi il PDF del Direttore).
     *
     * @return array{percorso: string, nome: string}|null
     */
    public function fileDaScaricare(int $id, string $quale): ?array
    {
        $c = $this->conv->perId($id);
        if (!$c) {
            return null;
        }
        $mappa = ['azienda' => ['file_firmato', Costanti::DIR_FIRMATE, 'firmata_dall_azienda'], 'direttore' => ['file_definitivo', Costanti::DIR_DEFINITIVE, 'firmata_dal_Direttore'],
            'protocollato' => ['file_protocollato', Costanti::DIR_DEFINITIVE, 'protocollata'], 'originale' => ['file_originale', Costanti::DIR_DEFINITIVE, 'originale_Titulus']];
        $ordine = $quale === 'finale' ? ['protocollato', 'direttore'] : [$quale];
        foreach ($ordine as $q) {
            if (!isset($mappa[$q]) || empty($c[$mappa[$q][0]])) {
                continue;
            }
            $p = $this->percorsoSicuro((string) $c[$mappa[$q][0]], $mappa[$q][1]);
            if ($p !== null) {
                $sigla = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $c['azienda']), '_') ?: 'azienda';

                return ['percorso' => $p, 'nome' => 'Convenzione_tesi_' . $mappa[$q][2] . '_' . $sigla . '.pdf'];
            }
        }

        return null;
    }

    /** Percorso reale del file solo se sta dentro la cartella indicata. */
    private function percorsoSicuro(string $relativo, string $cartella): ?string
    {
        $base = realpath($this->sito->radice() . '/' . rtrim($cartella, '/'));
        $percorso = realpath($this->sito->radice() . '/' . $relativo);
        if (!$base || !$percorso || strpos($percorso, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($percorso)) {
            return null;
        }

        return $percorso;
    }

    // ───────────────────────────── Cron: promemoria e scadenze ─────────────────────────────

    /** Promemoria alle aziende ferme e avvisi di scadenza. Ritorna una riga di riepilogo (vuota se non c'era nulla da fare). */
    public function cron(): string
    {
        $adesso = $this->orologio->adesso();
        $prima = $adesso->modify('-' . self::GIORNI_PROMEMORIA . ' days')->format('Y-m-d H:i:s');
        $promemoria = 0;
        foreach ($this->conv->ferme([Costanti::INVITATA, Costanti::COMPILATA, Costanti::RISPOSTA, Costanti::RIFIUTATA], $prima, $prima, $adesso->modify('-' . self::GIORNI_MAX_PROMEMORIA . ' days')->format('Y-m-d H:i:s')) as $c) {
            if ($this->notifiche->promemoria((string) $c['email'], (string) $c['azienda'], (string) $c['token'], (string) $c['stato'])) {
                $this->conv->segnaPromemoria((int) $c['id']);
                ++$promemoria;
            }
        }
        $entro = $adesso->modify('+' . self::GIORNI_AVVISO_SCADENZA . ' days')->format('Y-m-d');
        $scadenze = $this->conv->inScadenza($adesso->format('Y-m-d'), $entro);
        foreach ($scadenze as $c) {
            $this->notifiche->inScadenza((string) $c['email'], (string) $c['azienda'], (string) $c['valida_al'], $this->notifiche->linkAzienda((string) $c['token']));
            $this->conv->segnaAvvisoScadenza((int) $c['id']);
        }
        if ($scadenze) {
            $this->notifiche->scadenzeAllUfficio($this->emailUfficio(), $scadenze);
        }

        return $promemoria + count($scadenze) > 0 ? "Convenzioni di tesi: $promemoria promemoria, " . count($scadenze) . " in scadenza.\n" : '';
    }
}
