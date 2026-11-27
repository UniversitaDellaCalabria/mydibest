<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Amministrazione\PermessiAmministrazione;
use App\Core\Database;
use Throwable;

/**
 * «Chi compila cosa»: quali colonne della tabella di monitoraggio vede e compila ogni operatore (tabella pubblicazioni_colonne_utenti, v146: una riga per
 * colonna e persona; una colonna può avere più persone). UNICA regola per il pannello, la vista «Da compilare», lo scaricamento e il salvataggio
 * (ServizioPubblicazioni rifiuta una colonna non assegnata). Qui non c'è HTML.
 *
 * - Chi ha tutto il modulo Amministrazione (amministratori compresi: PermessiAmministrazione::puoTutto) vede e compila TUTTE le colonne.
 * - Gli altri vedono le colonne degli operatori assegnate a loro, che compilano, più le colonne di chi invia (titolo, data, richiedente…) e lo stato:
 *   le prime in sola lettura, lo stato si cambia dalla scheda come sempre.
 * - Senza nessuna assegnazione salvata vale «come prima»: tutti vedono e compilano tutto. Così caricare il codice non toglie niente a nessuno. Le
 *   righe rimaste per colonne che non esistono più non contano.
 * Le colonne nascoste dall'amministratore non le vede nessuno (come sempre), ma le loro assegnazioni restano.
 * valuta() e proponi() sono le regole senza database: si provano da sole.
 */
final class ColonneDiChi
{
    public function __construct(private Database $db, private ConfigurazioneCampi $config, private PermessiAmministrazione $permessi)
    {
    }

    // ─────────────────────────── Le regole, senza database ───────────────────────────

    /**
     * Le chiavi delle colonne che l'utente vede, nell'ordine in cui arrivano.
     *
     * @param list<array{chiave: string, operatori: bool}> $colonne le colonne del file Excel (ConfigurazioneCampi::colonne)
     * @param array<string, list<int>> $assegnazioni chiave della colonna => utenti; [] = nessuna assegnazione salvata
     * @param bool $tutto chi ha tutto il modulo Amministrazione
     * @return list<string>
     */
    public static function valuta(array $colonne, array $assegnazioni, int $utenteId, bool $tutto): array
    {
        $libero = $tutto || $assegnazioni === [];
        $viste = [];
        foreach ($colonne as $c) {
            if ($libero || !$c['operatori'] || in_array($utenteId, $assegnazioni[$c['chiave']] ?? [], true)) {
                $viste[] = $c['chiave'];
            }
        }

        return $viste;
    }

    /**
     * «Proponi dai nomi "a cura di"»: per ogni colonna degli operatori, chi tra gli operatori è nominato nel suo «a cura di» (la stessa regola del filtro
     * «Solo le mie colonne»: CompilazioneInTabella::nomina). Le colonne senza nessuno non ci sono. Non salva niente.
     *
     * @param list<array{chiave: string, operatori: bool, a_cura_di: string}> $colonne
     * @param list<array{id: int, nome: string, cognome: string}> $operatori
     * @return array<string, list<int>>
     */
    public static function proponi(array $colonne, array $operatori): array
    {
        $proposta = [];
        foreach ($colonne as $c) {
            if (!$c['operatori']) {
                continue;
            }
            foreach ($operatori as $o) {
                if (CompilazioneInTabella::nomina($c['a_cura_di'], $o)) {
                    $proposta[$c['chiave']][] = (int) $o['id'];
                }
            }
        }

        return $proposta;
    }

    // ─────────────────────────── Per un utente ───────────────────────────

    /** True se per l'utente non c'è nessun limite: ha tutto il modulo, o nessuna assegnazione è salvata. @param array<string, mixed> $utente la riga di utenti */
    public function senzaLimiti(array $utente): bool
    {
        return $this->assegnazioni() === [] || $this->permessi->puoTutto($utente);
    }

    /**
     * Le colonne del file Excel che l'utente vede (quelle sue più quelle di chi invia; tutte se senza limiti), nell'ordine del file.
     *
     * @param array<string, mixed> $utente
     * @return list<string>
     */
    public function visibili(array $utente): array
    {
        $assegnazioni = $this->assegnazioni();
        $tutto = $assegnazioni !== [] && $this->permessi->puoTutto($utente); // senza assegnazioni i permessi non servono

        return self::valuta($this->config->colonne(), $assegnazioni, (int) ($utente['id'] ?? 0), $tutto);
    }

    /** Le colonne degli operatori che l'utente può compilare. @param array<string, mixed> $utente @return list<string> */
    public function scrivibili(array $utente): array
    {
        return array_values(array_intersect($this->config->colonneOperatori(), $this->visibili($utente)));
    }

    /** Le colonne degli operatori che l'utente NON può compilare: quelle che il salvataggio rifiuta. @param array<string, mixed> $utente @return list<string> */
    public function vietate(array $utente): array
    {
        return array_values(array_diff($this->config->colonneOperatori(), $this->visibili($utente)));
    }

    /**
     * Le «mie colonne» (filtro di «Da compilare»): quelle assegnate all'utente; finché non è salvata nessuna assegnazione, quelle che lo nominano
     * nell'«a cura di», come prima.
     *
     * @param array<string, mixed> $utente
     * @return list<string>
     */
    public function mie(array $utente): array
    {
        $assegnazioni = $this->assegnazioni();
        if ($assegnazioni === []) {
            return CompilazioneInTabella::colonneDi($utente, $this->config);
        }
        $id = (int) ($utente['id'] ?? 0);

        return array_values(array_filter($this->config->colonneOperatori(), static fn (string $k): bool => in_array($id, $assegnazioni[$k] ?? [], true)));
    }

    // ─────────────────────────── La griglia (Amministrazione › Campi e colonne) ───────────────────────────

    /**
     * Le assegnazioni salvate, solo per le colonne degli operatori che esistono (anche nascoste). [] se non ce ne sono, o se la tabella non c'è ancora
     * (caricamento a metà).
     *
     * @return array<string, list<int>>
     */
    public function assegnazioni(): array
    {
        try {
            $righe = $this->db->righe('SELECT chiave, utente_id FROM pubblicazioni_colonne_utenti ORDER BY chiave, utente_id');
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] «Chi compila cosa» non letto: ' . $e->getMessage());

            return [];
        }
        if ($righe === []) {
            return [];
        }
        $esistono = array_flip(array_column($this->colonneDellaGriglia(), 'chiave'));
        $out = [];
        foreach ($righe as $r) {
            if (isset($esistono[(string) $r['chiave']])) {
                $out[(string) $r['chiave']][] = (int) $r['utente_id'];
            }
        }

        return $out;
    }

    /** Le righe della griglia: tutte le colonne degli operatori, anche le nascoste (le loro caselle non devono perdersi). @return list<array<string, mixed>> */
    public function colonneDellaGriglia(): array
    {
        return array_values(array_filter($this->config->colonne(true), static fn (array $c): bool => $c['operatori']));
    }

    /**
     * Le colonne della griglia: chi può lavorare le richieste (PermessiAmministrazione::puoPubblicazioni), per cognome. «tutto» = vede comunque tutte
     * le colonne (le sue caselle servono solo al filtro «Solo le mie colonne»).
     *
     * @return list<array{id: int, nome: string, cognome: string, nominativo: string, tutto: bool}>
     */
    public function operatori(): array
    {
        // i candidati (amministratori e abilitati); chi è davvero operatore lo dice la regola di PermessiAmministrazione
        $righe = $this->db->righe(
            "SELECT u.id, u.nome, u.cognome, u.ruolo_id, u.ruoli_secondari FROM utenti u
             WHERE u.ruolo_id = 1 OR FIND_IN_SET('1', u.ruoli_secondari) > 0
                OR EXISTS (SELECT 1 FROM abilitazioni_ambito a WHERE a.utente_id = u.id AND a.pagina_id = 0 AND a.tipo IN (?, ?))
             ORDER BY u.cognome, u.nome, u.id",
            [PermessiAmministrazione::MODULO, PermessiAmministrazione::MODULO_PUBBLICAZIONI]
        );
        $out = [];
        foreach ($righe as $u) {
            if (!$this->permessi->puoPubblicazioni($u)) {
                continue;
            }
            $nome = trim((string) ($u['nome'] ?? ''));
            $cognome = trim((string) ($u['cognome'] ?? ''));
            $completo = trim($nome . ' ' . $cognome);
            $out[] = ['id' => (int) $u['id'], 'nome' => $nome, 'cognome' => $cognome, 'nominativo' => $completo !== '' ? $completo : 'Utente #' . (int) $u['id'], 'tutto' => $this->permessi->puoTutto($u)];
        }

        return $out;
    }

    /**
     * Le caselle spuntate nella griglia (chiave della colonna => id degli utenti), ripulite: solo colonne degli operatori che esistono e solo operatori
     * di adesso. Un invio manomesso si ignora.
     *
     * @param mixed $post il campo «chi» del modulo
     * @return array<string, list<int>>
     */
    public function daPost(mixed $post): array
    {
        $post = is_array($post) ? $post : [];
        $ids = array_column($this->operatori(), 'id');
        $out = [];
        foreach (array_column($this->colonneDellaGriglia(), 'chiave') as $chiave) {
            $scelti = is_array($post[$chiave] ?? null) ? $post[$chiave] : [];
            $scelti = array_map('intval', array_filter($scelti, 'is_scalar'));
            $validi = array_values(array_intersect($ids, $scelti));
            if ($validi !== []) {
                $out[$chiave] = $validi;
            }
        }

        return $out;
    }

    /**
     * Salva la griglia: sostituisce tutte le assegnazioni con quelle indicate (di daPost), tutto o niente. Ritorna le differenze in parole per il
     * registro delle modifiche («colonna» => «prima → dopo»); vuoto = non c'era nulla da cambiare. Null se il salvataggio non riesce.
     *
     * @param array<string, list<int>> $griglia
     * @return array<string, string>|null
     */
    public function salva(array $griglia): ?array
    {
        $prima = [];
        foreach ($this->db->righe('SELECT chiave, utente_id FROM pubblicazioni_colonne_utenti ORDER BY chiave, utente_id') as $r) {
            $prima[(string) $r['chiave']][] = (int) $r['utente_id'];
        }
        $cambiate = [];
        foreach (array_unique(array_merge(array_keys($prima), array_keys($griglia))) as $chiave) {
            $da = $prima[$chiave] ?? [];
            $a = $griglia[$chiave] ?? [];
            sort($da);
            sort($a);
            if ($da !== $a) {
                $cambiate[(string) $chiave] = [$da, $a];
            }
        }
        if ($cambiate === []) {
            return [];
        }
        try {
            $this->db->transazione(function () use ($cambiate): void {
                foreach ($cambiate as $chiave => [, $a]) {
                    if ($this->db->esegui('DELETE FROM pubblicazioni_colonne_utenti WHERE chiave = ?', [$chiave]) < 0) {
                        throw new \RuntimeException('assegnazioni non tolte');
                    }
                    foreach ($a as $uid) {
                        if ($this->db->esegui('INSERT INTO pubblicazioni_colonne_utenti (chiave, utente_id) VALUES (?, ?)', [$chiave, $uid]) < 0) {
                            throw new \RuntimeException('assegnazione non salvata');
                        }
                    }
                }
            });
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] «Chi compila cosa» non salvato: ' . $e->getMessage());

            return null;
        }

        $nomi = $this->nomi(array_merge([], ...array_map(static fn (array $c): array => array_merge($c[0], $c[1]), array_values($cambiate))));
        $inParole = static fn (array $ids): string => $ids === [] ? 'nessuno' : implode(', ', array_map(static fn (int $i): string => $nomi[$i] ?? 'Utente #' . $i, $ids));
        $out = [];
        foreach ($cambiate as $chiave => [$da, $a]) {
            $nome = RegistroModifiche::nomeColonna($chiave, $this->config);
            $nome = isset($out[$nome]) ? $nome . ' (' . $chiave . ')' : $nome; // due colonne nuove con la stessa intestazione
            $out[$nome] = $inParole($da) . ' → ' . $inParole($a);
        }

        return $out;
    }

    /** @param list<int> $ids @return array<int, string> */
    private function nomi(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->righe('SELECT id, nome, cognome FROM utenti WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids) as $u) {
            $out[(int) $u['id']] = trim(trim((string) $u['nome']) . ' ' . trim((string) $u['cognome'])) ?: 'Utente #' . (int) $u['id'];
        }

        return $out;
    }
}
