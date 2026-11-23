<?php

declare(strict_types=1);

namespace App\Portale\Pagine;

use App\Auth\Csrf;
use App\Auth\Flash;
use App\Auth\Sessione;
use App\Core\IndirizzoClient;
use App\Http\Pagina;
use App\Http\Richiesta;
use App\Http\Risposta;
use App\Http\Vista;
use App\Infrastructure\Audit\AuditLog;
use App\Portale\ContattiRepository;
use App\Portale\Pannello\ContestoPannello;

/**
 * Sito pubblico › Contatti (admin/contatti.php): «Chi contattare per cosa», i recapiti istituzionali mostrati agli studenti nella pagina
 * pubblica (PaginaContatti). Si scrivono a mano (nessun dato personale preso dagli uffici o dall'anagrafe). Logica in ContattiRepository.
 *
 * Solo amministratori globali: lo decide il Nucleo (PermessiPagine, `contatti.php`) PRIMA che questa classe venga costruita, quindi qui non c'è
 * più `if (!$is_full_admin) nega_accesso();` e la logica di un POST non gira mai per chi non ha il permesso. Neppure il token CSRF si verifica qui:
 * lo fa il Nucleo per ogni POST (la classe usa Csrf solo per scrivere il campo nei moduli), e il messaggio dopo il salvataggio lo mostra il layout
 * che il Nucleo serve (qui si imposta soltanto). Il disegno è in viste/pannello/contatti.php.
 */
final class PaginaContattiPannello implements Pagina
{
    public function __construct(
        private readonly ContattiRepository $contatti,
        private readonly ContestoPannello $contesto,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly Sessione $sessione,
        private readonly AuditLog $audit,
        private readonly IndirizzoClient $indirizzo,
    ) {
    }

    public function __invoke(Richiesta $r): Risposta
    {
        $area = $this->contesto->area($r);
        if ($r->eUnPost()) {
            $this->salva($r); // il token è già stato verificato dal Nucleo, prima di costruire questa classe

            return Risposta::vai('contatti.php?p_id=' . $area);
        }

        $elenco = $this->contatti->tutti();
        $modifica = $r->query('modifica') !== null ? (int) $r->query('modifica') : -1; // -1 nessuno, 0 nuovo, >0 quello scelto

        return Risposta::html(Vista::pannello('Contatti', Vista::rendi('pannello/contatti', [
            'filtro' => $area,
            'campoCsrf' => $this->csrf->campo(),
            'modifica' => $modifica,
            // Il contatto vuoto da cui parte il modulo «Nuovo»: la vista legge ogni campo, quindi li ha tutti
            'nuovo' => ['id' => 0, 'titolo' => '', 'per_cosa' => '', 'luogo' => '', 'orari' => '', 'email' => '', 'telefono' => '',
                'link_etichetta' => '', 'link_url' => '', 'ordine' => count($elenco) + 1, 'visibile' => 0],
            'contatti' => array_map(static fn (array $c): array => $c + [
                'recapiti' => array_values(array_filter([$c['luogo'], $c['orari'], $c['telefono'], $c['email']], static fn ($x): bool => $x !== '')),
            ], $elenco),
        ])));
    }

    /** Il POST: una sola azione per invio, con il suo messaggio. Poi si rimanda all'elenco (il chiamante). */
    private function salva(Richiesta $r): void
    {
        if ($r->post('esempi') !== null) {
            $n = $this->contatti->inserisciEsempi();
            $this->flash->imposta($n ? "Ho aggiunto $n contatti di esempio, ancora nascosti: completali e poi mostrali sul sito." : 'Ci sono già dei contatti.', $n ? 'success' : 'warning');
            $this->registra('Contatti: esempi aggiunti', ['Contatti' => $n]);
        } elseif ($r->post('salva') !== null) {
            $id = (int) $r->post('salva');
            $dati = $r->datiModulo();
            $errore = $this->contatti->salva($id, $dati);
            if ($errore !== '') {
                $this->flash->imposta($errore, 'danger');
            } else {
                $this->flash->imposta($id ? 'Contatto salvato.' : 'Contatto aggiunto.');
                $this->registra($id ? 'Contatto modificato' : 'Contatto aggiunto', ['Contatto' => (string) ($r->post('titolo') ?? '')]);
            }
        } elseif ($r->post('mostra') !== null) {
            $visibile = $r->post('valore') === '1';
            $this->contatti->mostra((int) $r->post('mostra'), $visibile);
            $this->flash->imposta($visibile ? 'Ora il contatto si vede sul sito.' : 'Ora il contatto è nascosto.');
        } elseif ($r->post('elimina') !== null) {
            $this->contatti->elimina((int) $r->post('elimina'));
            $this->registra('Contatto eliminato', ['Contatto' => (int) $r->post('elimina')]);
            $this->flash->imposta('Contatto eliminato.');
        }
    }

    /** Il registro delle operazioni (come registra_log_audit(): niente se non c'è una persona collegata). */
    private function registra(string $azione, array $dettagli): void
    {
        $uid = (int) $this->sessione->leggi('utente_id');
        if ($uid > 0) {
            $this->audit->registra($uid, $azione, $dettagli, $this->indirizzo->ip() ?? 'Sconosciuto');
        }
    }
}
