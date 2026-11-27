<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Amministrazione\PermessiAmministrazione;
use App\Auth\GruppiUtente;
use App\Auth\Pubblico;
use App\Core\Database;
use App\Core\Esito;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Infrastructure\Audit\AuditLog;
use RuntimeException;
use Throwable;

/**
 * Le richieste di pubblicazione (di docenti e personale: chi può inviarle lo dicono le impostazioni) e la tabella di monitoraggio (il file Excel).
 *
 * La richiesta del docente NON entra da sola nella tabella di monitoraggio: arriva come «ricevuta» con nel_monitoraggio = 0 e le colonne degli
 * operatori vuote; ci entra solo quando un operatore preme «Invia al file Excel» (inviaAlMonitoraggio), e in quel momento si precompilano D, E
 * e i destinatari L, P, Q, R. Si può togliere (togliDalMonitoraggio) senza perdere le colonne compilate. Le righe a mano (creaManuale) entrano subito.
 *
 * Chi gestisce le richieste (puoGestire: amministratori e abilitati al modulo Amministrazione o alle sole richieste di pubblicazione) lo decide una
 * regola sola (PermessiAmministrazione::puoPubblicazioni), usata dalle pagine del pannello e da puoVedere. Chi può inviare (puoInviare), se la procedura è
 * aperta e i limiti dei campi li dice ImpostazioniPubblicazioni (scheda «Impostazioni» dell'Amministrazione); quali campi del form si chiedono, quali sono obbligatori e
 * come si chiamano le colonne del file Excel li dice ConfigurazioneCampi (scheda «Campi e colonne»); i campi nuovi aggiunti dall'amministratore (x_<n>) e le colonne nuove
 * degli operatori (y_<n>) li controlla CampiAggiunti con lo stesso codice di MyForms.
 * Ogni operazione degli operatori lascia una riga nel registro (log_attivita, AuditLog).
 * Quali colonne un operatore può compilare lo dice ColonneDiChi («Chi compila cosa»): ogni salvataggio che ne contiene una non sua è rifiutato per intero.
 *
 * L'approvazione (v146): se è accesa nelle impostazioni E c'è almeno un approvatore con un account collegato, la richiesta del form nasce «da_approvare» (visibile solo
 * a chi l'ha inviata, agli approvatori e a chi gestisce) e non si può mandare al file Excel finché non è approvata (ApprovazionePubblicazioni); altrimenti nasce «ricevuta» come sempre,
 * così non resta mai in attesa di nessuno. Le righe a mano dell'ufficio nascono sempre approvate. Lo stato «da_approvare» non si imposta né si toglie dalla scheda: lo decide solo l'approvatore.
 */
final class ServizioPubblicazioni
{
    public function __construct(
        private PubblicazioniRepository $repo,
        private AllegatiPubblicazioni $file,
        private Database $db,
        private Orologio $orologio,
        private AuditLog $audit,
        private IndirizzoClient $client,
        private GruppiUtente $gruppi,
        private NotifichePubblicazioni $notifiche,
        private PermessiAmministrazione $permessi,
        private ImpostazioniPubblicazioni $impostazioni,
        private ConfigurazioneCampi $config,
        private CampiAggiunti $aggiunti,
        private PersonePubblicazioni $persone,
        private ColonneDiChi $diChi,
    ) {
    }

    /**
     * Gli errori campo per campo (vuoto = tutto a posto): vedi RegolePubblicazione::valida(), con i limiti delle impostazioni.
     *
     * @param array<string, mixed> $dati
     * @param array{locandina?: list<array<string, mixed>>, allegati?: list<array<string, mixed>>, immagini?: list<array<string, mixed>>} $file
     * @return array<string, string>
     */
    public function valida(array $dati, array $file): array
    {
        $pagine = $this->impostazioni->pagine();
        $dati = RegolePubblicazione::conPagine($dati, $pagine);

        return RegolePubblicazione::valida($dati, $file, $this->oggi(), false, $this->impostazioni->limiti(), $this->config->regole(), $pagine) + $this->aggiunti->leggi($dati)['errori'];
    }

    /**
     * Se l'utente è tra chi può inviare una richiesta: i profili di «Chi può inviare» delle impostazioni (di partenza docenti e PTA) e, come in tutto il
     * portale, gli amministratori. Serve sempre l'accesso. Non guarda se la procedura è aperta (quello lo dice ImpostazioniPubblicazioni::aperta, e invia rifiuta).
     *
     * @param array<string, mixed>|null $utente
     */
    public function puoInviare(?array $utente): bool
    {
        return $utente !== null && !empty($utente['id']) && Pubblico::ammesso(['profili' => $this->impostazioni->chiPuoInviare()], $utente, $this->gruppi->nomi($utente));
    }

    /**
     * La richiesta di chi la invia (docente o personale): riga e file salvati insieme (o niente). Rifiutata se la procedura è chiusa (per tutti). Stato «ricevuta», NON nel monitoraggio, colonne degli operatori vuote.
     * Il richiedente («Cognome Nome») si prende dall'account. Ritorna l'id della richiesta (0 se non salvata) e gli errori campo per campo
     * (chiave «_» per quelli generali).
     *
     * @param array<string, mixed> $utente la riga di utenti
     * @param array<string, mixed> $dati
     * @param array{locandina?: list<array<string, mixed>>, allegati?: list<array<string, mixed>>, immagini?: list<array<string, mixed>>} $file
     * @return array{id: int, errori: array<string, string>}
     */
    public function invia(array $utente, array $dati, array $file): array
    {
        if (!$this->impostazioni->aperta()) {
            return ['id' => 0, 'errori' => ['_' => $this->impostazioni->messaggioChiusa()]];
        }
        if (!$this->puoInviare($utente)) {
            return ['id' => 0, 'errori' => ['_' => 'Le richieste di pubblicazione possono inviarle solo: ' . $this->impostazioni->chiInParole() . '.']];
        }
        // i file di un campo nascosto non si chiedono e non si salvano, anche se qualcuno li manda
        foreach (['allegati', 'immagini'] as $tipo_file) {
            if (!$this->config->visibile($tipo_file)) {
                $file[$tipo_file] = [];
            }
        }
        $dati = RegolePubblicazione::conPagine($dati, $this->impostazioni->pagine());
        $errori = $this->valida($dati, $file);
        if ($errori) {
            return ['id' => 0, 'errori' => $errori];
        }
        $campi = RegolePubblicazione::normalizza($dati, $this->config->nascosti()) + $this->aggiunti->leggi($dati)['valori'] + [
            'utente_id' => (int) $utente['id'],
            'richiedente' => self::nomeCognome($utente),
            'origine' => 'form',
            'stato' => $this->statoIniziale(),
            'inviata_il' => $this->adesso(),
            'nel_monitoraggio' => 0,
        ];
        $id = $this->salvaConFile($campi, $file);
        if ($id === 0) {
            return ['id' => 0, 'errori' => ['_' => 'Non è stato possibile salvare la richiesta: riprova tra qualche minuto.']];
        }
        // Avviso all'ufficio (indirizzi di ImpostazioniPubblicazioni) e ricevuta a chi l'ha inviata (se accesa): un errore di invio resta nel log e non annulla il salvataggio
        try {
            $this->notifiche->richiestaInviata($utente, $id);
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] email non inviate: ' . $e->getMessage());
        }

        return ['id' => $id, 'errori' => []];
    }

    /**
     * Lo stato con cui nasce una richiesta del form: «da_approvare» solo se l'approvazione è accesa e c'è almeno un approvatore con account collegato
     * (senza, nessuno potrebbe approvare); altrimenti «ricevuta», cioè approvata, come prima dell'approvazione.
     */
    public function statoIniziale(): string
    {
        return self::statoPer($this->impostazioni->approvazioneAttiva(), $this->persone->approvatoriConAccount());
    }

    /** La regola dello stato iniziale, senza database: serve l'approvazione accesa E almeno un approvatore con un account collegato. */
    public static function statoPer(bool $approvazioneAttiva, int $approvatoriConAccount): string
    {
        return $approvazioneAttiva && $approvatoriConAccount > 0 ? 'da_approvare' : 'ricevuta';
    }

    /**
     * Chi gestisce le richieste di pubblicazione (pannello, scheda, tabella di monitoraggio, impostazioni): gli amministratori, chi ha il modulo
     * Amministrazione e chi ha l'abilitazione «Amministrazione · Richieste di pubblicazione» (Utenti e abilitazioni). La regola è una sola,
     * PermessiAmministrazione::puoPubblicazioni, usata anche dal menu del pannello.
     *
     * @param array<string, mixed>|null $utente la riga di utenti
     */
    public function puoGestire(?array $utente): bool
    {
        return $this->permessi->puoPubblicazioni($utente);
    }

    /**
     * Chi può vedere la richiesta e scaricarne i file: chi l'ha inviata, chi gestisce le richieste (puoGestire) e gli approvatori (devono leggerla per decidere).
     *
     * @param array<string, mixed>|null $utente la riga di utenti
     * @param array<string, mixed> $p la riga di pubblicazioni
     */
    public function puoVedere(?array $utente, array $p): bool
    {
        if ($utente === null || empty($utente['id'])) {
            return false;
        }
        if (!empty($p['utente_id']) && (int) $p['utente_id'] === (int) $utente['id']) {
            return true;
        }

        return $this->puoGestire($utente) || $this->persone->eApprovatore($utente);
    }

    /**
     * Il file di una richiesta pronto da scaricare, solo se l'utente può vederla e il file è davvero nella cartella degli allegati.
     * Ritorna null in tutti gli altri casi (richiesta o file inesistenti, utente senza permesso): chi chiama risponde «File non trovato» senza dire perché.
     *
     * @param array<string, mixed>|null $utente
     * @return array{percorso: string, nome: string, estensione: string}|null «nome» è quello con cui si scarica: la locandina si chiama Locandina_<titolo breve>.<est>, gli altri file come sono stati caricati
     */
    public function fileDaScaricare(?array $utente, int $id, int $allegatoId): ?array
    {
        $p = $this->repo->perId($id);
        if ($p === null || !$this->puoVedere($utente, $p)) {
            return null;
        }
        $a = $this->repo->allegato($id, $allegatoId);
        $percorso = $a !== null ? $this->file->percorsoAssoluto((string) $a['percorso']) : null;
        if ($a === null || $percorso === null) {
            return null;
        }

        $estensione = strtolower(pathinfo($percorso, PATHINFO_EXTENSION));
        $nome = ($a['tipo'] ?? '') === 'locandina' ? self::nomeLocandina((string) $p['titolo'], $estensione) : (string) $a['nome_originale'];

        return ['percorso' => $percorso, 'nome' => $nome, 'estensione' => $estensione];
    }

    /** Quanti caratteri al massimo ha il «titolo breve» nel nome della locandina. */
    public const MAX_TITOLO_BREVE = 40;

    /**
     * Il nome con cui si scarica la locandina: «Locandina_<titolo breve>.<est>». Il titolo breve è il titolo senza accenti e simboli (lettere e numeri, il resto diventa «_»),
     * al massimo 40 caratteri, senza «_» ai lati; gli altri caratteri non latini valgono come simboli. Se il titolo non lascia niente il nome è «Locandina.<est>».
     * Il file su disco non cambia nome.
     */
    public static function nomeLocandina(string $titolo, string $estensione): string
    {
        // niente intl (sul server non c'è sempre): una tabella degli accenti, come NomeFile::slug
        $t = strtr(trim($titolo), [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O', 'Ø' => 'O',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ç' => 'c', 'Ç' => 'C', 'ñ' => 'n', 'Ñ' => 'N', 'ß' => 'ss', 'æ' => 'ae', 'Æ' => 'AE', 'œ' => 'oe', 'Œ' => 'OE', "'" => '', '’' => '',
        ]);
        $t = preg_replace('/[^A-Za-z0-9]+/', '_', $t) ?? '';
        $t = trim(substr($t, 0, self::MAX_TITOLO_BREVE), '_');

        return 'Locandina' . ($t === '' ? '' : '_' . $t) . '.' . ($estensione === '' ? 'pdf' : $estensione);
    }

    /**
     * «Invia al file Excel»: la richiesta entra nella tabella di monitoraggio. Rifiutata se è respinta o già dentro. Precompila SOLO le colonne
     * ancora vuote: D con l'URL del docente, E con gli altri link, L/P/Q/R con «SI» per i destinatari spuntati (se la riga era stata tolta e rimessa,
     * quanto già scritto dagli operatori resta com'è).
     *
     * @param array<string, mixed> $operatore la riga di utenti di chi preme il pulsante
     */
    public function inviaAlMonitoraggio(int $id, array $operatore): Esito
    {
        $p = $this->repo->perId($id);
        if (!$p) {
            return Esito::errore('La richiesta non esiste più.');
        }
        if ($p['stato'] === 'respinta') {
            return Esito::errore('Una richiesta respinta non si può inviare al file Excel.');
        }
        if ($p['stato'] === 'da_approvare') {
            return Esito::errore('La richiesta è in attesa di approvazione: si può inviare al file Excel solo dopo che è stata approvata.');
        }
        if ((int) $p['nel_monitoraggio'] === 1) {
            return Esito::errore('La richiesta è già nel file Excel.');
        }
        $uid = (int) ($operatore['id'] ?? 0);
        $precompilate = array_filter(self::precompilazione($p), static fn (string $v, string $k): bool => self::vuota($p[$k] ?? null), ARRAY_FILTER_USE_BOTH);
        $this->db->transazione(function () use ($id, $uid, $precompilate): void {
            $this->repo->impostaMonitoraggio($id, true, $uid, $this->adesso());
            $this->repo->precompila($id, $precompilate);
        });
        $this->registra($uid, 'Richieste di pubblicazione: inviata al file Excel', ['id' => $id, 'titolo' => (string) $p['titolo'], 'precompilate' => array_keys($precompilate)]);

        return Esito::ok('La richiesta è nel file Excel.');
    }

    /** «Togli dal file Excel»: la riga esce dalla tabella di monitoraggio; le colonne compilate restano salvate. @param array<string, mixed> $operatore */
    public function togliDalMonitoraggio(int $id, array $operatore): Esito
    {
        $p = $this->repo->perId($id);
        if (!$p) {
            return Esito::errore('La richiesta non esiste più.');
        }
        if ((int) $p['nel_monitoraggio'] !== 1) {
            return Esito::errore('La richiesta non è nel file Excel.');
        }
        $uid = (int) ($operatore['id'] ?? 0);
        $this->repo->impostaMonitoraggio($id, false, null, null);
        $this->registra($uid, 'Richieste di pubblicazione: tolta dal file Excel', ['id' => $id, 'titolo' => (string) $p['titolo']]);

        return Esito::ok('La richiesta non è più nel file Excel. Le colonne compilate restano salvate.');
    }

    /**
     * Una riga a mano (iniziativa arrivata per altre vie): obbligatori solo data dell'evento, richiedente (lo scrive l'operatore), titolo e locandina; il resto
     * è facoltativo ma, se compilato, ha gli stessi controlli di formato del form (RegolePubblicazione::valida con $aMano = true). Entra subito nella tabella di monitoraggio, con la stessa precompilazione di inviaAlMonitoraggio;
     * le colonne degli operatori eventualmente indicate in $dati si salvano subito. Stato: quello indicato (non «respinta»), altrimenti «ricevuta».
     *
     * @param array<string, mixed> $dati
     * @param array<string, mixed> $operatore
     * @param array{locandina?: list<array<string, mixed>>, allegati?: list<array<string, mixed>>, immagini?: list<array<string, mixed>>} $file
     * @return array{id: int, errori: array<string, string>}
     */
    public function creaManuale(array $dati, array $operatore, array $file = []): array
    {
        $errori = RegolePubblicazione::valida($dati, $file, $this->oggi(), true, $this->impostazioni->limiti());
        // i campi nuovi si mostrano anche nelle righe a mano (nessuno obbligatorio, ma il formato si controlla)
        $extra = $this->aggiunti->leggi($dati, true);
        $errori += $extra['errori'];
        $richiedente = RegolePubblicazione::richiedente($dati);
        [$colonne, $erroriColonne] = self::colonneDa($dati, $this->config);
        $errori += $erroriColonne;
        // una riga a mano non è la via per compilare le colonne di un altro
        $errori = $this->nonSue(array_filter($colonne, static fn (?string $v): bool => $v !== null), $this->diChi->vietate($operatore)) + $errori;
        if ($errori) {
            return ['id' => 0, 'errori' => $errori];
        }
        $uid = (int) ($operatore['id'] ?? 0);
        $stato = (string) ($dati['stato'] ?? '');
        $adesso = $this->adesso();
        $normali = RegolePubblicazione::normalizza($dati);
        $campi = $normali + $extra['valori'] + array_filter($colonne, static fn (?string $v): bool => $v !== null) + [
            'utente_id' => null,
            'richiedente' => $richiedente,
            'origine' => 'manuale',
            // una riga dell'ufficio nasce approvata: né «respinta» né «da_approvare»
            'stato' => isset(Costanti::STATI[$stato]) && !in_array($stato, ['respinta', 'da_approvare'], true) ? $stato : 'ricevuta',
            'inviata_il' => $adesso,
            'nel_monitoraggio' => 1,
            'monitoraggio_dal' => $adesso,
            'monitoraggio_da' => $uid ?: null,
        ];
        foreach (self::precompilazione($normali) as $k => $v) {
            if (self::vuota($campi[$k] ?? null)) {
                $campi[$k] = $v;
            }
        }
        $id = $this->salvaConFile($campi, $file);
        if ($id === 0) {
            return ['id' => 0, 'errori' => ['_' => 'Non è stato possibile salvare la riga: riprova tra qualche minuto.']];
        }
        $this->registra($uid, 'Richieste di pubblicazione: riga aggiunta a mano', ['id' => $id, 'titolo' => (string) $normali['titolo']]);

        return ['id' => $id, 'errori' => []];
    }

    /**
     * Salva le colonne degli operatori e lo stato. I campi del docente non si toccano mai. Le colonne si salvano solo se la riga è nella tabella
     * di monitoraggio (lo stato si cambia sempre). Passare a «respinta» una riga nel monitoraggio la toglie dal monitoraggio (la pagina chiede conferma).
     * Scrive modificata_il/da e una riga nel registro con le colonne cambiate (vecchio → nuovo). Le colonne SI/NO accettano solo SI, NO o vuoto;
     * «N. Partecipanti» solo un numero intero o vuoto. Se tra i dati c'è una colonna non assegnata all'operatore (ColonneDiChi) non si salva niente.
     *
     * @param array<string, mixed> $dati colonne degli operatori (ColonneMonitoraggio::colonneOperatori) e «stato»; le altre chiavi si ignorano
     * @param array<string, mixed> $operatore
     */
    public function aggiornaOperatori(int $id, array $dati, array $operatore): Esito
    {
        $nonSue = $this->nonSue($dati, $this->diChi->vietate($operatore));
        if ($nonSue) {
            return Esito::errore('Non è stato salvato niente. ' . implode(' ', $nonSue));
        }
        $piano = $this->pianoModifica($id, $dati);
        if ($piano['errore'] !== null) {
            return Esito::errore($piano['errore']);
        }
        if ($piano['errori']) {
            return Esito::errore(implode(' ', $piano['errori']));
        }
        if ($piano['cambi'] === []) {
            return Esito::ok('Non c\'era nulla da salvare.');
        }
        $uid = (int) ($operatore['id'] ?? 0);
        $adesso = $this->adesso();
        $this->db->transazione(function () use ($piano, $uid, $adesso): void {
            $this->applicaModifica($piano, $uid, $adesso);
        });
        $this->registraModifica($piano, $uid);

        return Esito::ok($piano['togli'] ? 'Salvato. La richiesta è respinta ed è stata tolta dal file Excel.' : 'Modifiche salvate.');
    }

    /**
     * Le colonne degli operatori di PIÙ richieste in una volta (vista «Da compilare»): le stesse regole di aggiornaOperatori, ma tutto o niente. Prima si
     * controllano TUTTE le righe; se una ha un errore non si salva niente (nemmeno le righe giuste) e si dice, riga per riga e cella per cella, cosa non va;
     * altrimenti si salvano tutte in una sola transazione e ogni riga cambiata lascia la sua voce nel registro delle modifiche. Le celle uguali a quelle già
     * salvate non si controllano né si scrivono (un vecchio valore fuori regola, lasciato com'è, non blocca le altre righe). Lo stato non si cambia da qui.
     * Le righe che non sono (più) nel file Excel danno errore: le colonne si compilano solo nel file Excel. Anche una sola cella di una colonna non
     * assegnata all'operatore (ColonneDiChi), pure se uguale a quella salvata, è un errore: non si salva niente.
     *
     * @param array<int|string, mixed> $righe id della richiesta => celle cambiate (chiave della colonna => valore)
     * @param array<string, mixed> $operatore la riga di utenti di chi salva
     * @return array{salvate: int, errori: array<int, array<string, string>>} salvate = righe davvero cambiate; errori = id => (chiave della colonna, o «_» per tutta la riga) => messaggio
     */
    public function aggiornaOperatoriInBlocco(array $righe, array $operatore): array
    {
        $piani = [];
        $errori = [];
        $vietate = $this->diChi->vietate($operatore);
        foreach ($righe as $id => $dati) {
            $id = (int) $id;
            if ($id <= 0 || !is_array($dati)) {
                continue;
            }
            unset($dati['stato']);
            $nonSue = $this->nonSue($dati, $vietate); // prima di scartare le celle invariate: una colonna non sua si rifiuta sempre
            $p = $this->repo->perId($id);
            foreach ($dati as $k => $v) {
                if (!is_scalar($v)) {
                    unset($dati[$k]); // un invio manomesso (un elenco al posto di un valore): si ignora
                } elseif ($p !== null && self::cellaInvariata((string) $v, $p[(string) $k] ?? null, $this->config->colonna((string) $k)['tipo'] ?? 'testo')) {
                    unset($dati[$k]); // come prima: non si controlla e non si scrive
                }
            }
            $piano = $this->pianoModifica($id, $dati);
            if ($piano['errore'] !== null) {
                $errori[$id]['_'] = $piano['errore'];
            } elseif (!$piano['dentro']) {
                $errori[$id]['_'] = 'La richiesta non è più nel file Excel: ricarica la pagina.';
            } elseif ($piano['errori']) {
                $errori[$id] = $piano['errori'];
            }
            if ($nonSue) {
                $errori[$id] = $nonSue + ($errori[$id] ?? []);
            }
            $piani[$id] = $piano;
        }
        if ($errori) {
            return ['salvate' => 0, 'errori' => $errori];
        }
        $daSalvare = array_filter($piani, static fn (array $piano): bool => $piano['cambi'] !== []);
        if ($daSalvare === []) {
            return ['salvate' => 0, 'errori' => []];
        }
        $uid = (int) ($operatore['id'] ?? 0);
        $adesso = $this->adesso();
        $this->db->transazione(function () use ($daSalvare, $uid, $adesso): void {
            foreach ($daSalvare as $piano) {
                $this->applicaModifica($piano, $uid, $adesso);
            }
        });
        foreach ($daSalvare as $piano) {
            $this->registraModifica($piano, $uid);
        }

        return ['salvate' => count($daSalvare), 'errori' => []];
    }

    /**
     * Le celle di colonne che l'operatore non può compilare: chiave della colonna => messaggio (vuoto = niente di vietato).
     *
     * @param array<int|string, mixed> $dati
     * @param list<string> $vietate ColonneDiChi::vietate
     * @return array<string, string>
     */
    private function nonSue(array $dati, array $vietate): array
    {
        $out = [];
        foreach ($vietate as $k) {
            if (array_key_exists($k, $dati)) {
                $out[$k] = $this->config->etichettaColonna($k) . ': questa colonna non è assegnata a te.';
            }
        }

        return $out;
    }

    /**
     * Cosa cambierebbe salvando $dati su una richiesta (senza scrivere niente): le regole di aggiornaOperatori, una volta sola.
     * - errore: la richiesta non esiste o lo stato non è valido (messaggio per tutta la richiesta);
     * - errori: cella per cella, solo per le richieste nel file Excel (chiave della colonna => messaggio);
     * - cambi / colonne: le colonne davvero cambiate (da → a) e quelle da scrivere; stato e togli come in aggiornaOperatori.
     *
     * @param array<string, mixed> $dati
     * @return array{id: int, p: array<string, mixed>|null, errore: string|null, errori: array<string, string>, dentro: bool, colonne: array<string, string|null>, cambi: array<string, array<string, mixed>>, stato: string, togli: bool}
     */
    private function pianoModifica(int $id, array $dati): array
    {
        $vuoto = ['id' => $id, 'p' => null, 'errore' => null, 'errori' => [], 'dentro' => false, 'colonne' => [], 'cambi' => [], 'stato' => '', 'togli' => false];
        $p = $this->repo->perId($id);
        if (!$p) {
            return ['errore' => 'La richiesta non esiste più.'] + $vuoto;
        }
        $stato = array_key_exists('stato', $dati) ? (string) $dati['stato'] : (string) $p['stato'];
        if (!isset(Costanti::STATI[$stato])) {
            return ['errore' => 'Lo stato scelto non è valido.'] + $vuoto;
        }
        // l'approvazione non si aggira dalla scheda: dentro e fuori da «in attesa di approvazione» si passa solo da ApprovazionePubblicazioni
        if ($stato !== $p['stato'] && ($stato === 'da_approvare' || $p['stato'] === 'da_approvare')) {
            return ['errore' => 'Lo stato «In attesa di approvazione» lo cambia solo chi approva le richieste.'] + $vuoto;
        }
        // revisione P4: nemmeno il «no» di chi approva si ribalta dalla scheda (diventerebbe «Approvata da» chi l'aveva respinta); chi ha inviato ne manda una nuova
        if ($stato !== $p['stato'] && !PubblicazioniRepository::approvata($p)) {
            return ['errore' => 'Questa richiesta è stata respinta da chi approva: lo stato non si cambia dalla scheda. Chi l\'ha inviata può mandarne una nuova.'] + $vuoto;
        }
        $dentro = (int) $p['nel_monitoraggio'] === 1;
        $cambi = [];
        $colonne = [];
        if ($dentro) {
            [$nuove, $errori] = self::colonneDa($dati, $this->config);
            if ($errori) {
                return ['p' => $p, 'errori' => $errori, 'dentro' => true, 'stato' => $stato] + $vuoto;
            }
            foreach ($nuove as $k => $nuovo) {
                $vecchio = self::vuota($p[$k] ?? null) ? null : (string) $p[$k];
                if ($nuovo !== $vecchio) {
                    $colonne[$k] = $nuovo;
                    $cambi[$k] = ['da' => $vecchio, 'a' => $nuovo];
                }
            }
        }
        if ($stato !== $p['stato']) {
            $cambi['stato'] = ['da' => $p['stato'], 'a' => $stato];
        }
        $togli = $dentro && $stato === 'respinta';
        if ($togli && $cambi !== []) {
            $cambi['nel_monitoraggio'] = ['da' => 1, 'a' => 0];
        }

        return ['id' => $id, 'p' => $p, 'errore' => null, 'errori' => [], 'dentro' => $dentro, 'colonne' => $colonne, 'cambi' => $cambi, 'stato' => $stato, 'togli' => $togli];
    }

    /**
     * Scrive nel database un piano di pianoModifica: da chiamare dentro una transazione (Database::transazione non si annida, quindi qui non ne apre una).
     *
     * @param array{id: int, p: array<string, mixed>|null, colonne: array<string, string|null>, stato: string, togli: bool} $piano
     */
    private function applicaModifica(array $piano, int $uid, string $adesso): void
    {
        $id = $piano['id'];
        if ($piano['colonne']) {
            $this->repo->aggiornaColonneOperatori($id, $piano['colonne'], $uid, $adesso);
        }
        if ($piano['stato'] !== $piano['p']['stato']) {
            $this->repo->cambiaStato($id, $piano['stato'], $uid, $adesso);
        }
        if ($piano['togli']) {
            $this->repo->impostaMonitoraggio($id, false, null, null);
        }
    }

    /** La voce del registro delle modifiche per un piano appena salvato. @param array{id: int, p: array<string, mixed>|null, cambi: array<string, array<string, mixed>>} $piano */
    private function registraModifica(array $piano, int $uid): void
    {
        $this->registra($uid, 'Richieste di pubblicazione: modifica', ['id' => $piano['id'], 'titolo' => (string) ($piano['p']['titolo'] ?? ''), 'cambi' => $piano['cambi']]);
    }

    /** True se il valore scritto in una cella è quello già salvato (stessa ripulitura delle colonne: spazi ai lati, a capo, SI/NO senza badare alle maiuscole). */
    private static function cellaInvariata(string $scritto, mixed $salvato, string $tipo): bool
    {
        $ripulisci = static function (string $v) use ($tipo): string {
            $v = trim(str_replace("\r\n", "\n", $v));

            return $tipo === 'si_no' ? mb_strtoupper($v) : $v;
        };

        return $ripulisci($scritto) === $ripulisci((string) ($salvato ?? ''));
    }

    /** Elimina la richiesta con i suoi file (la pagina chiede conferma). @param array<string, mixed> $operatore */
    public function elimina(int $id, array $operatore): Esito
    {
        $p = $this->repo->perId($id);
        if ($p && (string) $p['stato'] === 'da_approvare') {
            // revisione P4: chi approva la sta guardando (e ha ricevuto l'email): prima decide, poi l'ufficio può eliminarla
            return Esito::errore('Una richiesta in attesa di approvazione non si elimina: prima deve decidere chi approva.');
        }
        if (!$p || !$this->repo->elimina($id)) {
            return Esito::errore('La richiesta non esiste più.');
        }
        $this->registra((int) ($operatore['id'] ?? 0), 'Richieste di pubblicazione: eliminata', [
            'id' => $id, 'titolo' => (string) $p['titolo'], 'richiedente' => (string) $p['richiedente'], 'data_evento' => (string) $p['data_evento'],
        ]);

        return Esito::ok('Richiesta eliminata, con i suoi file.');
    }

    /**
     * I valori che «Invia al file Excel» propone per le colonne degli operatori: D = URL del docente, E = altri link, L/P/Q/R = «SI» per i
     * destinatari spuntati (solo i valori non vuoti).
     *
     * @param array<string, mixed> $p la riga (o i dati normalizzati)
     * @return array<string, string>
     */
    public static function precompilazione(array $p): array
    {
        $v = [
            'd_url_pubblicazione' => trim((string) ($p['url_docente'] ?? '')),
            'e_url_altre_pagine' => trim((string) ($p['altri_link_docente'] ?? '')),
        ];
        foreach (Costanti::DESTINATARI as $campo => [, $colonna]) {
            if (!empty($p[$campo])) {
                $v[$colonna] = 'SI';
            }
        }

        return array_filter($v, static fn (string $x): bool => $x !== '');
    }

    /**
     * Gli errori delle colonne degli operatori in $dati, campo per campo (SI/NO, numero intero): serve alla scheda per scriverli accanto al campo,
     * senza perdere quanto scritto. Gli stessi controlli di aggiornaOperatori.
     *
     * @param array<string, mixed> $dati
     * @param ConfigurazioneCampi|null $config i tipi e i nomi delle colonne impostati dall'amministratore (senza: quelli di partenza)
     * @return array<string, string>
     */
    public static function erroriColonne(array $dati, ?ConfigurazioneCampi $config = null): array
    {
        return self::colonneDa($dati, $config)[1];
    }

    /** «Cognome Nome» dell'account. @param array<string, mixed> $utente */
    public static function nomeCognome(array $utente): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', trim((string) ($utente['cognome'] ?? '')) . ' ' . trim((string) ($utente['nome'] ?? ''))));
    }

    /**
     * Le colonne degli operatori presenti in $dati, ripulite (testo senza spazi ai lati, '' → null, al massimo MAX_COLONNA caratteri), e gli errori
     * delle colonne SI/NO e numero.
     *
     * @param array<string, mixed> $dati
     * @return array{0: array<string, string|null>, 1: array<string, string>}
     */
    private static function colonneDa(array $dati, ?ConfigurazioneCampi $config = null): array
    {
        $config ??= ConfigurazioneCampi::predefinita();
        $out = [];
        $errori = [];
        // le colonne nascoste non si controllano: restano come sono (i loro valori non si perdono)
        foreach ($config->colonneOperatori() as $k) {
            if (!array_key_exists($k, $dati)) {
                continue;
            }
            $v = mb_substr(trim(str_replace("\r\n", "\n", (string) $dati[$k])), 0, Costanti::MAX_COLONNA);
            $tipo = $config->colonna($k)['tipo'] ?? 'testo';
            if ($v !== '' && $tipo === 'si_no') {
                $v = mb_strtoupper($v);
                if (!in_array($v, ['SI', 'NO'], true)) {
                    $errori[$k] = $config->etichettaColonna($k) . ': scegli SI, NO o lascia vuoto.';
                }
            }
            if ($v !== '' && $tipo === 'numero' && !ctype_digit($v)) {
                $errori[$k] = $config->etichettaColonna($k) . ': scrivi un numero intero o lascia vuoto.';
            }
            $out[$k] = $v === '' ? null : $v;
        }

        return [$out, $errori];
    }

    /**
     * Riga e file in una transazione: se un file non si salva non resta nulla (i file già copiati si cancellano). Ritorna l'id, 0 se non riesce.
     *
     * @param array<string, mixed> $campi
     * @param array{locandina?: list<array<string, mixed>>, allegati?: list<array<string, mixed>>, immagini?: list<array<string, mixed>>} $file
     */
    private function salvaConFile(array $campi, array $file): int
    {
        $salvati = [];
        try {
            return $this->db->transazione(function () use ($campi, $file, &$salvati): int {
                $id = $this->repo->inserisci($campi);
                if ($id <= 0) {
                    throw new RuntimeException('riga non inserita');
                }
                foreach (['locandina' => 'locandina', 'allegati' => 'allegato', 'immagini' => 'immagine'] as $chiave => $tipo) {
                    foreach ($file[$chiave] ?? [] as $f) {
                        $percorso = $this->file->salva($f, $tipo);
                        if ($percorso === null) {
                            throw new RuntimeException('file non salvato: ' . (string) ($f['name'] ?? ''));
                        }
                        $salvati[] = $percorso;
                        $this->repo->inserisciAllegato($id, $tipo, basename((string) ($f['name'] ?? 'file')), $percorso, (int) ($f['size'] ?? 0), (string) $campi['inviata_il']);
                    }
                }

                return $id;
            });
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] richiesta non salvata: ' . $e->getMessage());
            foreach ($salvati as $s) {
                $this->file->elimina($s);
            }

            return 0;
        }
    }

    /** @param array<string, mixed> $dettagli */
    private function registra(int $uid, string $azione, array $dettagli): void
    {
        $this->audit->registra($uid, $azione, $dettagli, $this->client->ip() ?? 'Sconosciuto');
    }

    private static function vuota(mixed $v): bool
    {
        return $v === null || trim((string) $v) === '';
    }

    private function adesso(): string
    {
        return $this->orologio->adesso()->format('Y-m-d H:i:s');
    }

    private function oggi(): string
    {
        return $this->orologio->adesso()->format('Y-m-d');
    }
}
