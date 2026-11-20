<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Core\Database;

/**
 * Le modifiche fatte dagli operatori a una richiesta, lette dal registro delle operazioni (log_attivita) e scritte in parole:
 * «D · URL effettiva pubblicazione sito: «(vuoto)» → «https://…»», «Stato: Ricevuta → In lavorazione».
 * Le voci del registro le scrive ServizioPubblicazioni (azione «Richieste di pubblicazione: …», dettagli con «id» come primo campo).
 */
final class RegistroModifiche
{
    private const PREFISSO = 'Richieste di pubblicazione: ';
    private const MAX_VALORE = 140;

    public function __construct(private Database $db, private ?ConfigurazioneCampi $config = null)
    {
    }

    /**
     * Le voci del registro di una richiesta, dalla più recente.
     *
     * @return list<array{quando: string, chi: string, titolo: string, righe: list<string>}>
     */
    public function perRichiesta(int $id, int $limite = 100): array
    {
        if ($id <= 0) {
            return [];
        }
        $righe = $this->db->righe(
            "SELECT l.id, l.utente_id, l.azione, l.dettagli_json, l.data_ora, TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS chi
             FROM log_attivita l LEFT JOIN utenti u ON u.id = l.utente_id
             WHERE l.azione LIKE ? AND (l.dettagli_json LIKE ? OR l.dettagli_json = ?)
             ORDER BY l.id DESC LIMIT " . max(1, min(500, $limite)),
            [self::PREFISSO . '%', '{"id":' . $id . ',%', '{"id":' . $id . '}']
        );

        return array_map(function (array $r): array {
            $v = self::voce((string) $r['azione'], (string) ($r['dettagli_json'] ?? ''), $this->config);

            return [
                'quando' => (string) $r['data_ora'],
                'chi' => trim((string) ($r['chi'] ?? '')) !== '' ? trim((string) $r['chi']) : 'Utente #' . (int) $r['utente_id'],
                'titolo' => $v['titolo'],
                'righe' => $v['righe'],
            ];
        }, $righe);
    }

    /**
     * Una voce del registro in parole: un titolo breve e, per le modifiche, una riga per ogni colonna cambiata. Senza database.
     *
     * @return array{titolo: string, righe: list<string>}
     */
    public static function voce(string $azione, string $dettagliJson, ?ConfigurazioneCampi $config = null): array
    {
        $dettagli = $dettagliJson !== '' ? json_decode($dettagliJson, true) : [];
        $dettagli = is_array($dettagli) ? $dettagli : [];
        $tipo = str_starts_with($azione, self::PREFISSO) ? substr($azione, strlen(self::PREFISSO)) : $azione;

        switch ($tipo) {
            case 'inviata al file Excel':
                $righe = [];
                $pre = array_values(array_filter((array) ($dettagli['precompilate'] ?? []), 'is_string'));
                if ($pre) {
                    $righe[] = 'Precompilate dai dati del docente: ' . implode(', ', array_map(fn (string $k): string => self::nomeColonna($k, $config), $pre)) . '.';
                }

                return ['titolo' => 'Inviata al file Excel', 'righe' => $righe];
            case 'tolta dal file Excel':
                return ['titolo' => 'Tolta dal file Excel', 'righe' => ['Le colonne compilate restano salvate.']];
            case 'riga aggiunta a mano':
                return ['titolo' => 'Riga aggiunta a mano', 'righe' => ['È entrata subito nel file Excel.']];
            case 'modifica':
                $righe = [];
                foreach ((array) ($dettagli['cambi'] ?? []) as $chiave => $cambio) {
                    if (is_array($cambio)) {
                        $righe[] = self::rigaCambio((string) $chiave, $cambio['da'] ?? null, $cambio['a'] ?? null, $config);
                    }
                }

                return ['titolo' => 'Modifica', 'righe' => $righe];
            default:
                return ['titolo' => $tipo !== '' ? ucfirst($tipo) : 'Operazione', 'righe' => []];
        }
    }

    /** «D · URL effettiva pubblicazione sito» dalla chiave della colonna, con l'intestazione di adesso (la chiave stessa se non è una colonna nota). */
    public static function nomeColonna(string $chiave, ?ConfigurazioneCampi $config = null): string
    {
        $config ??= ConfigurazioneCampi::predefinita();
        $c = $config->colonna($chiave);
        if ($c === null) {
            // una colonna nuova (y_<n>) che l'amministratore ha poi eliminato: il registro la ricorda, ma non ne conosce più il nome
            return ConfigurazioneCampi::eColonnaNuova($chiave) ? 'Colonna eliminata' : $chiave;
        }
        $etichetta = $config->etichettaColonna($chiave);

        return $c['lettera'] !== '' ? $c['lettera'] . ' · ' . $etichetta : $etichetta;
    }

    private static function rigaCambio(string $chiave, mixed $da, mixed $a, ?ConfigurazioneCampi $config = null): string
    {
        if ($chiave === 'nel_monitoraggio') {
            return 'La riga è uscita dal file Excel.';
        }
        if ($chiave === 'stato') {
            return 'Stato: ' . (Costanti::STATI[(string) $da] ?? (string) $da) . ' → ' . (Costanti::STATI[(string) $a] ?? (string) $a);
        }

        return self::nomeColonna($chiave, $config) . ': ' . self::valore($da) . ' → ' . self::valore($a);
    }

    private static function valore(mixed $v): string
    {
        $t = trim((string) preg_replace('/\s*\R\s*/u', ' · ', (string) ($v ?? '')));
        if ($t === '') {
            return '(vuoto)';
        }

        return '«' . (mb_strlen($t) > self::MAX_VALORE ? mb_substr($t, 0, self::MAX_VALORE) . '…' : $t) . '»';
    }
}
