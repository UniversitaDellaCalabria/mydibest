<?php

declare(strict_types=1);

namespace App\Anagrafi\Endpoint;

use App\Anagrafi\ServizioScuole;
use App\Auth\LimiteRichieste;
use App\Core\IndirizzoClient;
use App\Http\Richiesta;
use App\Http\Risposta;
use stdClass;

/**
 * Ricerca nell'anagrafe delle scuole per il campo «Scuola» dei moduli (cerca_scuole.php): risponde in JSON, con un limite di richieste
 * per indirizzo IP. Dati pubblici (open data del Ministero). I nomi dei parametri sono chiamati dal JavaScript delle pagine
 * (assets/js/campo-scuola.js e la finestra guidata): non si cambiano.
 *
 *   ?dettaglio=CODICE                                     dati della scuola per precompilare la convenzione
 *   ?elenco=regioni | province&regione=… | comuni&regione=…&provincia=…   luoghi per le tendine
 *   ?q=…[&regione=…&provincia=…&comune=…]                 scuole (con comune o provincia basta anche q vuota)
 */
final class RicercaScuole
{
    private const ENDPOINT = 'cerca_scuole';

    public function __construct(
        private ServizioScuole $scuole,
        private LimiteRichieste $limite,
        private IndirizzoClient $client,
    ) {
    }

    public function risposta(Richiesta $r): Risposta
    {
        return $this->costruisci($r)->conIntestazione('X-Robots-Tag', 'noindex');
    }

    private function costruisci(Richiesta $r): Risposta
    {
        if (!$this->limite->consenti($this->client->ip() ?? 'unknown', self::ENDPOINT, 400, 300)) {
            return Risposta::json([], 429);
        }
        $par = fn (string $nome): string => mb_substr(trim(self::testo($r->query($nome))), 0, 80);

        if ($r->query('dettaglio') !== null) {
            return Risposta::json($this->scuole->perConvenzione($par('dettaglio')) ?? new stdClass());
        }
        if ($r->query('elenco') !== null) {
            return Risposta::json($this->scuole->luoghi($par('elenco'), $par('regione'), $par('provincia')));
        }

        $q = $par('q');
        $filtri = ['regione' => $par('regione'), 'provincia' => $par('provincia'), 'comune' => $par('comune')];
        if (mb_strlen($q) < 3 && $filtri['comune'] === '' && $filtri['provincia'] === '') {
            return Risposta::json([]);
        }

        return Risposta::json($this->scuole->cerca($q, $filtri['comune'] !== '' ? 200 : ($filtri['provincia'] !== '' ? 80 : 20), $filtri));
    }

    /** Un parametro che arriva come array (?q[]=x) non è un testo: vale come vuoto (prima diventava la parola «Array»). */
    private static function testo(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
