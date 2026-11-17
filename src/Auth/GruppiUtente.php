<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

/**
 * I gruppi di una persona: i nomi (in minuscolo) delle righe della tabella «ruoli» a cui appartiene, come ruolo principale
 * o secondario. È l'unica lettura di questo tipo: serve a Pubblico::ammesso() (risorse, moduli, MyForms) e all'area personale.
 * Se la query non riesce Database dà un elenco vuoto (non lancia eccezioni): nessun gruppo, e quindi nessun permesso in più.
 */
final class GruppiUtente
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Nomi (minuscoli) dei gruppi dell'utente. Senza utente o senza id: nessuno.
     *
     * @param array<string, mixed>|null $utente la riga di utenti
     * @return list<string>
     */
    public function nomi(?array $utente): array
    {
        if (!$utente || empty($utente['id'])) {
            return [];
        }
        $ids = self::idRuoli($utente);
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->db->righe('SELECT nome FROM ruoli WHERE id IN (' . implode(',', $ids) . ')') as $r) {
            $out[] = mb_strtolower((string) $r['nome']);
        }

        return $out;
    }

    /**
     * Gli id dei ruoli dell'utente: il principale (5 se manca) e i secondari, solo interi positivi, senza doppioni.
     *
     * @param array<string, mixed> $utente
     * @return list<int>
     */
    public static function idRuoli(array $utente): array
    {
        $ids = array_map('intval', array_merge([(int) ($utente['ruolo_id'] ?? 5)], explode(',', (string) ($utente['ruoli_secondari'] ?? ''))));

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }
}
