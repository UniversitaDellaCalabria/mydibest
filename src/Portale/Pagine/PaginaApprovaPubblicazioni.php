<?php

declare(strict_types=1);

namespace App\Portale\Pagine;

use App\Auth\Csrf;
use App\Auth\Flash;
use App\Auth\Sessione;
use App\Auth\UtenteRepository;
use App\Http\Pagina;
use App\Http\Richiesta;
use App\Http\Risposta;
use App\Http\Vista;
use App\Portale\Instradamento\SincronizzaAccesso;
use App\Pubblicazioni\ApprovazionePubblicazioni;
use App\Pubblicazioni\ConfigurazioneCampi;
use App\Pubblicazioni\Costanti;
use App\Pubblicazioni\NotifichePubblicazioni;
use App\Pubblicazioni\PubblicazioniRepository;
use App\Pubblicazioni\RiepilogoRichiesta;
use App\Pubblicazioni\VistaRichiesta;

/**
 * La pagina di chi approva le richieste di pubblicazione (approva_pubblicazioni.php). NON sta nel pannello: il Direttore non ha il modulo Amministrazione, e l'accesso
 * non è un permesso del pannello ma l'essere nella lista «Chi approva» con un account collegato (ApprovazionePubblicazioni::puoApprovare, per persona_id e mai per email).
 *
 * L'accesso a una pagina pubblica non lo decide il Nucleo (vedi AccessoPortale), quindi lo decide questa classe, PRIMA di tutto: non collegato = login SSO e ritorno qui;
 * collegato ma non approvatore = 403. Il token CSRF di ogni POST lo controlla il Nucleo prima di costruire la classe.
 *
 *  - elenco (nessun ?id): «Da approvare» (titolo, chi, data dell'evento, inviata il, miniatura della locandina) e «Decise di recente» con CHI ha deciso e QUANDO;
 *  - richiesta aperta (?id=N): il riepilogo completo (VistaRichiesta, con i file scaricabili), i pulsanti Approva e Respingi (Respingi chiede il motivo, obbligatorio);
 *  - POST: approva / respingi. Se un altro approvatore ha già deciso, o la richiesta non è più in attesa, un messaggio chiaro e si torna alla richiesta, che mostra chi ha deciso.
 * Dopo una decisione le altre persone che approvano ricevono un'email (NotifichePubblicazioni::richiestaDecisa); un errore di invio non cambia mai l'esito.
 * Il disegno è in viste/pubblico/approva_pubblicazioni.php.
 */
final class PaginaApprovaPubblicazioni implements Pagina
{
    /** Quante richieste decise si mostrano nell'elenco. */
    private const DECISE_RECENTI = 10;

    public function __construct(
        private readonly ApprovazionePubblicazioni $approvazione,
        private readonly PubblicazioniRepository $repo,
        private readonly NotifichePubblicazioni $notifiche,
        private readonly ConfigurazioneCampi $config,
        private readonly UtenteRepository $utenti,
        private readonly Sessione $sessione,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly SincronizzaAccesso $sincronizza,
    ) {
    }

    public function __invoke(Richiesta $r): Risposta
    {
        ($this->sincronizza)($r);
        $uid = (int) $this->sessione->leggi('utente_id');
        if ($uid <= 0) {
            return Risposta::vai('saml_login.php?redirect=' . urlencode(self::url((int) $r->query('id', 0))), 302);
        }
        $utente = $this->utenti->perId($uid);
        if ($utente === null || !$this->approvazione->puoApprovare($utente)) {
            return Risposta::negata('Questa pagina è riservata a chi approva le richieste di pubblicazione.', static fn (string $messaggio): string => Vista::pagina(
                'Accesso negato',
                '<div class="container my-4 ui-maxw-760"><div class="alert alert-danger fw-bold shadow-sm"><i class="fa fa-ban me-2" aria-hidden="true"></i>' . htmlspecialchars($messaggio, ENT_QUOTES, 'UTF-8') . '</div></div>',
            ));
        }
        $id = (int) $r->query('id', 0);
        if ($r->eUnPost()) {
            return $this->decidi($r, $utente);
        }

        return $id > 0 ? $this->richiesta($id, $uid, $r->query('respingi') !== null) : $this->elenco();
    }

    public static function url(int $id = 0): string
    {
        return 'approva_pubblicazioni.php' . ($id > 0 ? '?id=' . $id : '');
    }

    // ───────────────────────────── Elenco ─────────────────────────────

    private function elenco(): Risposta
    {
        $da = [];
        foreach ($this->approvazione->daApprovare() as $p) {
            $da[] = [
                'titolo' => (string) $p['titolo'], 'richiedente' => (string) $p['richiedente'], 'dataEvento' => RiepilogoRichiesta::data((string) $p['data_evento']),
                'inviata' => self::quando((string) $p['inviata_il']), 'href' => self::url((int) $p['id']),
                'locandina' => $this->miniatura((int) $p['id']),
            ];
        }
        $decise = [];
        foreach ($this->approvazione->decise(self::DECISE_RECENTI) as $x) {
            $decise[] = [
                'titolo' => $x['titolo'], 'richiedente' => $x['richiedente'], 'approvata' => $x['approvata'], 'chi' => $x['chi'], 'quando' => self::quando($x['quando']),
                'motivo' => $x['motivo'], 'href' => self::url($x['id']),
            ];
        }

        return $this->pagina('elenco', ['da' => $da, 'decise' => $decise]);
    }

    /** L'indirizzo della locandina se è un'immagine (la miniatura nell'elenco), vuoto se è un PDF o non c'è: allegato_pubblicazione.php ricontrolla il permesso. */
    private function miniatura(int $id): string
    {
        foreach ($this->repo->allegati($id) as $a) {
            if (($a['tipo'] ?? '') === 'locandina' && preg_match('/\.(jpe?g|png)$/i', (string) ($a['nome_originale'] ?? '')) === 1) {
                return 'allegato_pubblicazione.php?p=' . $id . '&a=' . (int) $a['id'];
            }
        }

        return '';
    }

    // ───────────────────────────── Richiesta aperta ─────────────────────────────

    private function richiesta(int $id, int $uid, bool $apriRespingi): Risposta
    {
        $p = $this->repo->perId($id);
        if ($p === null) {
            return $this->pagina('mancante', [], 404);
        }
        $righe = [];
        foreach (VistaRichiesta::righe($p, $this->repo->allegati($id), '', $this->config) as [$etichetta, $valore]) {
            $righe[] = ['etichetta' => $etichetta, 'valore' => $valore];   // il valore è HTML già escapato da VistaRichiesta: la vista lo stampa grezzo
        }
        $attesa = (string) $p['stato'] === 'da_approvare';
        $decisione = $attesa ? null : $this->approvazione->decisione($id);

        return $this->pagina('richiesta', [
            'id' => $id, 'titolo' => (string) $p['titolo'], 'richiedente' => (string) $p['richiedente'], 'inviata' => self::quando((string) $p['inviata_il']),
            'attesa' => $attesa, 'campoCsrf' => $this->csrf->campo(), 'righe' => $righe, 'hrefElenco' => self::url(),
            'decisa' => $decisione !== null,
            'decisaApprovata' => $decisione !== null && $decisione['approvata'],
            'decisaDaMe' => $decisione !== null && (int) ($p['decisa_da'] ?? 0) === $uid,   // chi ha deciso legge «L'hai approvata tu»
            'decisaDa' => $decisione['chi'] ?? '',
            'decisaIl' => $decisione !== null ? self::quando($decisione['quando']) : '',
            'decisaMotivo' => $decisione['motivo'] ?? '',
            'statoTesto' => Costanti::STATI[(string) $p['stato']] ?? (string) $p['stato'],
            'maxMotivo' => Costanti::MAX_MOTIVO,
            'apriRespingi' => $apriRespingi ? 1 : 0,
        ]);
    }

    // ───────────────────────────── Decisione ─────────────────────────────

    /** @param array<string, mixed> $utente */
    private function decidi(Richiesta $r, array $utente): Risposta
    {
        $id = (int) $r->post('id', $r->query('id', 0));
        if ($id <= 0) {
            return Risposta::vai(self::url());
        }
        $respingi = $r->post('respingi') !== null;
        $esito = $respingi
            ? $this->approvazione->respingi($id, $utente, (string) $r->post('motivo', ''))
            : $this->approvazione->approva($id, $utente);
        // un motivo mancante, o una richiesta già decisa da un altro, non sono errori tecnici (ambra): si legge il messaggio e si prosegue; solo un salvataggio fallito è rosso
        $tono = $esito->riuscito ? 'success' : (str_contains($esito->messaggio, 'Non è stato possibile') ? 'danger' : 'warning');
        $this->flash->imposta($esito->messaggio, $tono);
        if ($esito->riuscito) {
            $this->notifiche->richiestaDecisa($id, $utente);
        }

        // sia dopo una decisione sia dopo un errore («già approvata da X») si torna alla richiesta: mostra chi ha deciso e quando.
        // Un motivo mancante riapre il campo del motivo (?respingi=1) invece di chiuderlo.
        return Risposta::vai(self::url($id) . (!$esito->riuscito && $respingi && $tono === 'warning' && str_contains($esito->messaggio, 'motivo') ? '&respingi=1' : ''));
    }

    // ───────────────────────────── Disegno ─────────────────────────────

    /** @param array<string, mixed> $dati */
    private function pagina(string $quale, array $dati, int $codice = 200): Risposta
    {
        // La testata del sito non mostra da sola né il messaggio dopo il salvataggio né il tema scuro: li prepara la pagina (inc/ui.php si carica qui, come fa PaginaScuoleFsl
        // con ui_passi: il disegno si compone PRIMA della testata, che è dove di solito arriva).
        if (!function_exists('ui_tema_script')) {
            require_once dirname(__DIR__, 3) . '/inc/ui.php';
        }
        $dati += ['avvisoHtml' => $this->flash->html(), 'temaHtml' => ui_tema_script()];

        return Risposta::html(Vista::pagina('Richieste di pubblicazione da approvare', Vista::rendi('pubblico/approva_pubblicazioni', ['quale' => $quale] + $dati)), $codice);
    }

    /** «26/11/2026 alle 10:30». */
    private static function quando(string $data): string
    {
        $t = strtotime($data);

        return $t ? date('d/m/Y \a\l\l\e H:i', $t) : '';
    }
}
