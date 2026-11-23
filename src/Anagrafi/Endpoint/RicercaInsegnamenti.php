<?php

declare(strict_types=1);

namespace App\Anagrafi\Endpoint;

use App\Anagrafi\ServizioCatalogo;
use App\Auth\LimiteRichieste;
use App\Auth\Sessione;
use App\Core\IndirizzoClient;
use App\Http\Richiesta;
use App\Http\Risposta;

/**
 * Catalogo di Ateneo per i campi «Insegnamento» dei moduli online (cerca_insegnamenti.php): JSON, serve l'accesso.
 * Lo usa assets/js/campi-pratica.js (finestra di scelta dell'insegnamento): i nomi dei parametri non si cambiano.
 *
 *   ?azione=tipi                         tipi di corso (triennale, magistrale, ciclo unico…)
 *   ?azione=anni&tipo=L                  anni accademici di offerta dei corsi di quel tipo
 *   ?azione=corsi&tipo=L&aa=2025         corsi di studio di quel tipo offerti in quell'anno (senza aa: tutti, con gli anni)
 *   ?azione=insegnamenti&cds=X&aa=2024   insegnamenti del corso per l'anno di offerta (scaricati dalle API la prima volta): per ognuno
 *                                        codice, nome, CFU, SSD, semestre, anno di corso e link; gli integrati hanno integrato=true e i moduli
 *
 * Chi è connesso lo dice la sessione (App\Auth\Sessione), non la Richiesta.
 */
final class RicercaInsegnamenti
{
    private const ENDPOINT = 'cerca_insegnamenti';

    public function __construct(
        private ServizioCatalogo $catalogo,
        private LimiteRichieste $limite,
        private IndirizzoClient $client,
        private Sessione $sessione,
    ) {
    }

    public function risposta(Richiesta $r): Risposta
    {
        return $this->costruisci($r)->conIntestazione('Cache-Control', 'private, max-age=300');
    }

    private function costruisci(Richiesta $r): Risposta
    {
        if (!$this->sessione->leggi('utente_id')) {
            return Risposta::json(['errore' => 'Serve l\'accesso'], 401);
        }
        if (!$this->limite->consenti($this->client->ip() ?? 'unknown', self::ENDPOINT, 240, 600)) {
            return Risposta::json(['errore' => 'Troppe richieste'], 429);
        }

        $azione = self::testo($r->query('azione'));
        if ($azione === 'tipi') {
            $out = [];
            foreach ($this->catalogo->tipi() as $k => $n) {
                $out[] = ['tipo' => $k, 'nome' => $n];
            }

            return Risposta::json($out);
        }
        if ($azione === 'anni') {
            $tipo = self::tipo($r);

            return Risposta::json($tipo !== '' ? $this->catalogo->anni($tipo) : []);
        }
        if ($azione === 'corsi') {
            $tipo = self::tipo($r);

            return Risposta::json($tipo !== '' ? $this->catalogo->corsi($tipo, self::intero($r->query('aa'))) : []);
        }
        if ($azione === 'insegnamenti') {
            $cds = preg_replace('/[^A-Za-z0-9_-]/', '', self::testo($r->query('cds')));

            // Insegnamenti singoli e integrati (con 'moduli'): chi sceglie un integrato sceglie anche tutti i suoi moduli
            return Risposta::json($this->catalogo->insegnamentiPerScelta($cds, self::intero($r->query('aa'))));
        }

        return Risposta::json(['errore' => 'Azione non valida'], 400);
    }

    private static function tipo(Richiesta $r): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(self::testo($r->query('tipo'))));
    }

    /** Un parametro che arriva come array (?azione[]=x) non è un testo: vale come vuoto (prima diventava la parola «Array»). */
    private static function testo(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private static function intero(mixed $v): int
    {
        return is_scalar($v) ? (int) $v : 0;
    }
}
