<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Core\Sito;
use Unical\Titulus\AllegatoTitulus;
use App\Protocollo\Costanti as CostantiProtocollo;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\RichiestaProtocollo;
use InvalidArgumentException;

/**
 * «Protocolla in Titulus» per la domanda di una pratica che passa dall'Ufficio protocollo: la domanda in PDF/A e gli allegati dello
 * studente vanno a Titulus come documento in arrivo secondo la regola del modulo («pratica:<id del modulo>», altrimenti la generale);
 * il numero ricevuto si registra come protocollo della pratica, con lo stesso effetto del numero scritto a mano (la pratica passa al
 * passo successivo, o aspetta il bollo se serve).
 */
final class ProtocollaPraticaInTitulus
{
    public const RIF = 'pratica_domanda';

    public function __construct(
        private Database $db,
        private PraticaRepository $pratiche,
        private DomandaPdfa $domanda,
        private AllegatiPratiche $allegati,
        private ServizioFlussoConvalide $flusso,
        private InvioProtocollo $invio,
        private Sito $sito,
    ) {
    }

    /** Contesto della regola di un modulo: «pratica:<id>». */
    public static function contesto(int $moduloId): string
    {
        return CostantiProtocollo::PREFISSO_PRATICA . $moduloId;
    }

    public function disponibile(int $moduloId): bool
    {
        return $this->invio->disponibile(self::contesto($moduloId));
    }

    /**
     * @return array{errore: string|null, messaggio: string, numero: string} messaggio: l'esito della registrazione (passo successivo della pratica)
     */
    public function protocolla(int $id, int $uid, string $autoreNome, bool $bollo = false): array
    {
        $ko = static fn (string $m, string $numero = ''): array => ['errore' => $m, 'messaggio' => '', 'numero' => $numero];
        $p = $this->pratiche->perId($id);
        if (!$p) {
            return $ko('Pratica non trovata.');
        }
        if ((string) $p['protocollo'] !== '') {
            return $ko('La pratica ha già un protocollo (' . $p['protocollo'] . '): per correggerlo o per registrare il bollo usa il modulo a mano.');
        }

        $domanda = $this->allegatoDa((string) ($p['domanda_pdf'] ?? ''), 'Domanda_' . $p['codice'] . '.pdf');
        if ($domanda === null) {
            $rel = $this->domanda->genera($id, $uid, $autoreNome);
            $domanda = $rel !== null ? $this->allegatoDa($rel, 'Domanda_' . $p['codice'] . '.pdf') : null;
        }
        if ($domanda === null) {
            return $ko('La domanda in PDF/A non si legge e non riesco a generarla: protocolla a mano.');
        }

        $richiesta = new RichiestaProtocollo(
            applicaA: self::contesto((int) $p['modulo_id']),
            oggetto: (string) $p['modulo_titolo'] . ' - ' . trim($p['cognome'] . ' ' . $p['nome']) . ' (matricola ' . $p['matricola'] . ', pratica ' . $p['codice'] . ')',
            principale: $domanda,
            allegati: $this->allegatiDelloStudente($id, (string) ($p['domanda_pdf'] ?? '')),
            esterno: ['nome' => (string) $p['nome'], 'cognome' => (string) $p['cognome'], 'email' => (string) $p['email']],
            rifTipo: self::RIF,
            rifId: $id,
            utenteId: $uid > 0 ? $uid : null,
        );
        $n = $this->invio->numero($richiesta);
        if ($n['errore'] !== null) {
            return $ko($n['errore']);
        }

        [$err, $msg] = $this->flusso->registraProtocollo($id, $n['numero'], date('Y-m-d'), $uid, $autoreNome, $bollo);
        if ($err !== null) {
            return $ko('Protocollata in Titulus (' . $n['numero'] . ') ma non registrata nel portale: ' . $err . ' Scrivi a mano il numero ' . $n['numero'] . '.', $n['numero']);
        }

        return ['errore' => null, 'messaggio' => (string) $msg, 'numero' => $n['numero']];
    }

    /** Gli allegati caricati dallo studente all'invio (oltre alla domanda in PDF/A). @return list<AllegatoTitulus> */
    private function allegatiDelloStudente(int $id, string $domandaPdf): array
    {
        $out = [];
        $righe = $this->db->righe("SELECT allegato, nome_allegato FROM pratiche_eventi WHERE pratica_id = ? AND autore = 'studente' AND allegato IS NOT NULL AND allegato <> '' AND interno = 0 ORDER BY id LIMIT 20", [$id]);
        foreach ($righe as $r) {
            if ((string) $r['allegato'] === $domandaPdf) {
                continue;
            }
            $a = $this->allegatoDa((string) $r['allegato'], (string) ($r['nome_allegato'] ?: basename((string) $r['allegato'])));
            if ($a !== null) {
                $out[] = $a;
            }
        }

        return $out;
    }

    private function allegatoDa(string $relativo, string $nome): ?AllegatoTitulus
    {
        if ($relativo === '') {
            return null;
        }
        $base = realpath($this->sito->radice() . '/' . rtrim($this->allegati->cartella(), '/'));
        $p = realpath($this->sito->radice() . '/' . $relativo);
        if (!$base || !$p || strpos($p, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($p)) {
            return null;
        }
        $c = @file_get_contents($p);
        if ($c === false || $c === '') {
            return null;
        }
        try {
            return AllegatoTitulus::crea($nome, $c);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
