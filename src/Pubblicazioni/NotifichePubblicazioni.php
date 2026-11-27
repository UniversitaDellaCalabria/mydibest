<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Auth\UtenteRepository;
use App\Core\Sito;
use App\Email\InvioEmail;
use Throwable;

/**
 * Le email di una richiesta di pubblicazione. I testi stanno nel catalogo delle email (CatalogoPubblicazioni), modificabili dalla pagina «Email» del pannello.
 * I file NON si allegano: si scaricano dalla scheda (o, per chi approva, dalla pagina «Da approvare»).
 *
 * All'invio (richiestaInviata), secondo lo STATO con cui è nata la richiesta (ServizioPubblicazioni::statoIniziale: «da_approvare» solo se l'approvazione è accesa E c'è
 * almeno un approvatore con un account, altrimenti nessuno potrebbe decidere):
 * - «da_approvare»: l'email «da approvare» a ogni persona della lista «Chi approva» (con riepilogo e pulsante «Apri e approva») e la ricevuta a chi ha inviato, che dice
 *   «in attesa di approvazione» (solo se la ricevuta è accesa nelle impostazioni). NIENTE all'ufficio comunicazione: lo avvisa l'approvazione.
 * - altrimenti, come prima dell'approvazione: l'avviso agli indirizzi dell'ufficio (ImpostazioniPubblicazioni) e alle persone della lista «Chi lavora» e, se accesa, la
 *   ricevuta a chi ha inviato.
 *
 * Dopo una decisione (richiestaDecisa; chi riceve cosa lo dice destinatariDecisione):
 * - approvata: l'avviso all'ufficio alle persone della lista «Chi lavora» e agli indirizzi delle impostazioni (la casella condivisa), «la tua richiesta è stata
 *   approvata» a chi l'ha inviata, «X ha approvato» alle ALTRE persone che approvano e l'email «per conoscenza», senza collegamenti, alla lista «Chi è informato»;
 * - respinta: «la tua richiesta è stata respinta», con il motivo, a chi l'ha inviata e «X ha respinto» alle altre persone che approvano. A ufficio e conoscenza niente.
 *
 * Una persona riceve UNA sola email per evento (non si ripete né l'indirizzo né la persona dell'anagrafe, anche se è in più liste o è chi ha inviato la richiesta).
 * Un errore di invio non si propaga mai (la richiesta o la decisione sono già salvate): resta solo nel log del server.
 */
final class NotifichePubblicazioni
{
    /**
     * Chi sta in più liste riceve l'email del primo di questi gruppi in cui compare: prima quella che chiede di lavorare (con il collegamento alla scheda), poi l'esito
     * della propria richiesta, poi l'avviso a chi approva, per ultima la sola conoscenza.
     */
    private const PRECEDENZA_APPROVATA = ['ufficio', 'caselle', 'richiedente', 'approvatore', 'conoscenza'];
    private const PRECEDENZA_RESPINTA = ['richiedente', 'approvatore'];

    public function __construct(
        private InvioEmail $invio,
        private ImpostazioniPubblicazioni $impostazioni,
        private PubblicazioniRepository $repo,
        private Sito $sito,
        private ConfigurazioneCampi $config,
        private PersonePubblicazioni $persone,
        private UtenteRepository $utenti,
    ) {
    }

    /**
     * Le email dell'invio (vedi la descrizione della classe). Non lancia mai eccezioni.
     *
     * @param array<string, mixed> $utente la riga di utenti di chi ha inviato la richiesta
     */
    public function richiestaInviata(array $utente, int $id): void
    {
        try {
            $p = $this->repo->perId($id);
            if ($p === null) {
                return;
            }
            $riepilogo = RiepilogoRichiesta::tabella($p, $this->repo->allegati($id), $this->config);
            $quando = $this->quando((string) $p['inviata_il']);
            $indirizzi = $this->impostazioni->indirizzi();
            if ((string) $p['stato'] === 'da_approvare') {
                $this->agliApprovatori($p, $utente, $riepilogo, $quando, $id, (string) ($indirizzi[0] ?? ''));

                return;
            }
            // senza approvazione l'avviso va agli indirizzi delle impostazioni E alle persone della lista «Chi lavora» (revisione P4), una volta sola per indirizzo
            $ufficio = array_column($this->persone->destinatari('ufficio'), 'email');
            $this->avviso(array_values(array_unique(array_map('strtolower', array_merge($indirizzi, $ufficio)))), $p, $utente, $riepilogo, $quando, $id);
            if ($this->impostazioni->ricevuta()) {
                $this->ricevuta($indirizzi[0] ?? '', $p, $utente, $riepilogo, $quando, 'pubblicazioni_docente_ricevuta', []);
            }
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] email non inviate: ' . $e->getMessage());
        }
    }

    /**
     * Le email dopo che una persona ha approvato o respinto (vedi la descrizione della classe). Chi ha deciso non riceve l'avviso agli altri approvatori. Non lancia
     * mai eccezioni.
     *
     * @param array<string, mixed> $decisore la riga di utenti di chi ha deciso
     */
    public function richiestaDecisa(int $id, array $decisore): void
    {
        try {
            $p = $this->repo->perId($id);
            if ($p === null || empty($p['decisa_il'])) {
                return;
            }
            $approvata = (string) $p['stato'] !== 'respinta';
            $motivo = trim((string) ($p['respinta_motivo'] ?? ''));
            $nomeDecisore = trim((string) ($decisore['nome'] ?? '') . ' ' . (string) ($decisore['cognome'] ?? ''));
            $chi = $nomeDecisore !== '' ? $nomeDecisore : 'Un approvatore';
            $quando = $this->quando((string) $p['decisa_il']);
            $titolo = (string) $p['titolo'];
            $indirizzi = $this->impostazioni->indirizzi();
            $autore = $this->autore($p);

            $liste = ['richiedente' => $this->comeDestinatario($autore, $p), 'approvatore' => $this->persone->destinatari('approvatore')];
            $riepilogo = '';
            if ($approvata) {
                $liste['ufficio'] = $this->persone->destinatari('ufficio');
                $liste['caselle'] = array_map(static fn (string $a): array => ['email' => $a], $indirizzi);
                $liste['conoscenza'] = $this->persone->destinatari('conoscenza');
                if ($liste['ufficio'] === [] && $indirizzi === []) {
                    error_log('[Pubblicazioni] la richiesta n. ' . $id . ' è approvata ma l\'ufficio comunicazione non ha nessun indirizzo: nessun avviso partito');
                }
                $riepilogo = RiepilogoRichiesta::tabella($p, $this->repo->allegati($id), $this->config);
            }

            $base = $this->sito->urlBase();
            foreach (self::destinatariDecisione($approvata, $liste, (string) ($decisore['persona_id'] ?? '')) as $d) {
                [$chiave, $dati, $link] = match ($d['gruppo']) {
                    'ufficio', 'caselle' => [
                        'pubblicazioni_comunicazione_nuova',
                        $this->datiUfficio($p, $autore, $riepilogo, $this->quando((string) $p['inviata_il']), $chi, $quando),
                        $base . '/admin/pubblicazioni.php?id=' . $id,
                    ],
                    'richiedente' => [
                        $approvata ? 'pubblicazioni_approvata' : 'pubblicazioni_respinta',
                        ['nome' => $d['nome'], 'titolo' => $titolo, 'email_ufficio' => (string) ($indirizzi[0] ?? '')]
                            + ($approvata ? ['approvata_da' => $chi, 'approvata_il' => $quando] : ['respinta_il' => $quando, 'motivo' => $motivo]),
                        $base . '/area_personale.php',
                    ],
                    'approvatore' => [
                        'pubblicazioni_decisa_altro',
                        ['nome' => $d['nome'], 'titolo' => $titolo, 'chi' => $chi, 'esito' => $approvata ? 'approvato' : 'respinto', 'quando' => $quando,
                            'motivo_testo' => !$approvata && $motivo !== '' ? ' Motivo: ' . $motivo : ''],
                        $base . '/approva_pubblicazioni.php?id=' . $id,
                    ],
                    'conoscenza' => [
                        'pubblicazioni_conoscenza',
                        ['nome' => $d['nome'], 'titolo' => $titolo, 'richiedente' => (string) $p['richiedente'], 'approvata_da' => $chi, 'approvata_il' => $quando, 'riepilogo' => $riepilogo],
                        '',   // nessun collegamento: chi è solo informato non può aprire né il pannello né i file
                    ],
                };
                $this->manda($d['email'], $chiave, $dati, $link, 'email della decisione (' . $d['gruppo'] . ')');
            }
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] email della decisione non inviate: ' . $e->getMessage());
        }
    }

    /**
     * La regola di chi riceve un'email dopo la decisione, senza database. Ognuno compare una volta sola, nel primo gruppo della precedenza in cui sta: due voci sono la
     * stessa persona se hanno lo stesso indirizzo (maiuscole a parte) o la stessa persona dell'anagrafe. Una voce senza un indirizzo valido si salta. Chi ha deciso
     * non è tra gli approvatori da avvisare (nelle altre liste resta). Se la richiesta è respinta contano solo «richiedente» e «approvatore».
     *
     * @param array<string, list<array{email?: string, nome?: string, persona_id?: string}>> $liste gruppo (ufficio, caselle, richiedente, approvatore, conoscenza) => voci
     * @param string $personaDecisore la persona dell'anagrafe di chi ha deciso (vuota se il suo account non è collegato)
     * @return list<array{gruppo: string, email: string, nome: string}>
     */
    public static function destinatariDecisione(bool $approvata, array $liste, string $personaDecisore): array
    {
        $mio = trim($personaDecisore);
        $indirizziDecisore = [];
        foreach ($liste['approvatore'] ?? [] as $a) {
            if ($mio !== '' && ($a['persona_id'] ?? '') === $mio) {
                $indirizziDecisore[strtolower(trim((string) ($a['email'] ?? '')))] = true;   // chi ha deciso non riceve l'avviso, qualunque sia l'indirizzo
            }
        }
        $serviti = [];
        $out = [];
        foreach ($approvata ? self::PRECEDENZA_APPROVATA : self::PRECEDENZA_RESPINTA as $gruppo) {
            foreach ($liste[$gruppo] ?? [] as $x) {
                $email = trim((string) ($x['email'] ?? ''));
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }
                $persona = trim((string) ($x['persona_id'] ?? ''));
                if ($gruppo === 'approvatore' && (($mio !== '' && $persona === $mio) || isset($indirizziDecisore[strtolower($email)]))) {
                    continue;
                }
                $chiavi = ['e:' . strtolower($email) => true] + ($persona !== '' ? ['p:' . $persona => true] : []);
                $doppione = array_intersect_key($chiavi, $serviti) !== [];
                $serviti += $chiavi;   // anche di un doppione: l'altro suo indirizzo è della stessa persona
                if (!$doppione) {
                    $out[] = ['gruppo' => $gruppo, 'email' => $email, 'nome' => (string) ($x['nome'] ?? '')];
                }
            }
        }

        return $out;
    }

    /**
     * La riga di utenti di chi ha inviato la richiesta. Un errore nel leggerla non ferma le altre email.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function autore(array $p): array
    {
        try {
            return $this->utenti->perId((int) ($p['utente_id'] ?? 0)) ?? [];
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] chi ha inviato la richiesta non letto: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Chi ha inviato la richiesta come voce di destinatariDecisione.
     *
     * @param array<string, mixed> $autore
     * @param array<string, mixed> $p
     * @return list<array{email: string, nome: string, persona_id: string}>
     */
    private function comeDestinatario(array $autore, array $p): array
    {
        $email = trim((string) ($autore['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            error_log('[Pubblicazioni] esito non inviato a chi ha mandato la richiesta: non ha un indirizzo email valido nel portale');
        }
        $nome = trim((string) ($autore['nome'] ?? ''));

        return [['email' => $email, 'nome' => $nome !== '' ? $nome : (string) $p['richiedente'], 'persona_id' => (string) ($autore['persona_id'] ?? '')]];
    }

    /**
     * Approvazione in corso: la richiesta va SOLO a chi approva, e la ricevuta a chi ha inviato dice che è in attesa. Chi è anche tra gli approvatori (per esempio chi approva e
     * invia la propria richiesta) riceve l'email «da approvare», non la ricevuta: una sola email.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $utente
     */
    private function agliApprovatori(array $p, array $utente, string $riepilogo, string $quando, int $id, string $emailUfficio): void
    {
        $email = trim((string) ($utente['email'] ?? ''));
        $dati = [
            'titolo' => (string) $p['titolo'],
            'richiedente' => (string) $p['richiedente'],
            'email_testo' => $email !== '' ? ' (' . $email . ')' : '',
            'inviata_il' => $quando,
            'riepilogo' => $riepilogo,
        ];
        $link = $this->sito->urlBase() . '/approva_pubblicazioni.php?id=' . $id;
        $inviati = [];
        foreach ($this->persone->destinatari('approvatore') as $a) {
            $k = strtolower($a['email']);
            if (isset($inviati[$k])) {
                continue;
            }
            $inviati[$k] = true;
            $this->manda($a['email'], 'pubblicazioni_da_approvare', ['nome' => $a['nome']] + $dati, $link, 'richiesta da approvare');
        }
        if ($inviati === []) {
            error_log('[Pubblicazioni] la richiesta n. ' . $id . ' aspetta l\'approvazione ma nessun approvatore ha un indirizzo email valido in anagrafe: nessun avviso partito');
        }
        if ($this->impostazioni->ricevuta()) {
            $this->ricevuta($emailUfficio, $p, $utente, $riepilogo, $quando, 'pubblicazioni_docente_attesa', $inviati);
        }
    }

    /**
     * L'avviso all'ufficio all'invio, quando non c'è l'approvazione.
     *
     * @param list<string> $indirizzi
     * @param array<string, mixed> $p
     * @param array<string, mixed> $utente
     */
    private function avviso(array $indirizzi, array $p, array $utente, string $riepilogo, string $quando, int $id): void
    {
        if ($indirizzi === []) {
            error_log('[Pubblicazioni] avviso non inviato: nessun indirizzo valido nelle impostazioni');

            return;
        }
        $dati = $this->datiUfficio($p, $utente, $riepilogo, $quando);
        $link = $this->sito->urlBase() . '/admin/pubblicazioni.php?id=' . $id;
        foreach ($indirizzi as $a) {
            $this->manda($a, 'pubblicazioni_comunicazione_nuova', $dati, $link, 'avviso a un indirizzo');
        }
    }

    /**
     * I dati dell'avviso all'ufficio comunicazione; chi ha approvato e quando restano vuoti se non c'è stata un'approvazione.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $autore la riga di utenti di chi ha inviato la richiesta
     * @return array<string, string>
     */
    private function datiUfficio(array $p, array $autore, string $riepilogo, string $inviataIl, string $approvataDa = '', string $approvataIl = ''): array
    {
        $email = trim((string) ($autore['email'] ?? ''));

        return [
            'titolo' => (string) $p['titolo'],
            'richiedente' => (string) $p['richiedente'],
            'email_testo' => $email !== '' ? ' (' . $email . ')' : '',
            'inviata_il' => $inviataIl,
            'approvata_da' => $approvataDa,
            'approvata_il' => $approvataIl,
            'approvazione_testo' => $approvataDa !== '' ? ' È stata approvata da ' . $approvataDa . ' il ' . $approvataIl . '.' : '',
            'riepilogo' => $riepilogo,
        ];
    }

    /**
     * La ricevuta a chi ha inviato la richiesta.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $utente
     * @param array<string, bool> $giaServiti indirizzi (minuscoli) che hanno già ricevuto un'email per questo evento: chi c'è non riceve anche la ricevuta
     */
    private function ricevuta(string $emailUfficio, array $p, array $utente, string $riepilogo, string $quando, string $chiave, array $giaServiti): void
    {
        $a = trim((string) ($utente['email'] ?? ''));
        if ($a === '' || filter_var($a, FILTER_VALIDATE_EMAIL) === false) {
            error_log('[Pubblicazioni] ricevuta non inviata: chi ha inviato la richiesta non ha un indirizzo email valido nel portale');

            return;
        }
        if (isset($giaServiti[strtolower($a)])) {
            return;
        }
        $dati = [
            'nome' => trim((string) ($utente['nome'] ?? '')) !== '' ? trim((string) $utente['nome']) : (string) $p['richiedente'],
            'titolo' => (string) $p['titolo'],
            'inviata_il' => $quando,
            'riepilogo' => $riepilogo,
            'email_ufficio' => $emailUfficio,
        ];
        $this->manda($a, $chiave, $dati, $this->sito->urlBase() . '/area_personale.php', 'ricevuta a chi ha inviato la richiesta');
    }

    /** Un'email: un errore (di invio o di eccezione) resta solo nel log. @param array<string, scalar|null> $dati */
    private function manda(string $a, string $chiave, array $dati, string $link, string $cosa): void
    {
        try {
            if (!$this->invio->invia($a, $chiave, $dati, $link)) {
                error_log('[Pubblicazioni] ' . $cosa . ' non inviata: ' . $this->invio->ultimoErrore());
            }
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] ' . $cosa . ' non inviata: ' . $e->getMessage());
        }
    }

    /** «19/11/2026 alle 10:00» da una data e ora salvate. */
    private function quando(string $inviataIl): string
    {
        $t = strtotime($inviataIl);

        return $t ? date('d/m/Y \a\l\l\e H:i', $t) : '';
    }
}
