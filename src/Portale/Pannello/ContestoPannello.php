<?php

declare(strict_types=1);

namespace App\Portale\Pannello;

use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\Auth\Permessi;
use App\Auth\PermessiFabbrica;
use App\Auth\Sessione;
use App\Auth\UtenteRepository;
use App\Http\Richiesta;
use App\Portale\Area\AreeGestite;
use App\Portale\AreeRepository;

/**
 * Chi sta aprendo il pannello e in quale area: la fotografia che admin/admin_header.php espone in variabili ($utente_admin, $filtro_p,
 * $page_cfg, $pagine_disponibili, $permessi), calcolata PRIMA della pagina così il Nucleo può decidere l'accesso e la pagina può leggere
 * l'area aperta senza passare dalla testata. È l'UNICA copia di queste regole: la testata le legge da qui, non le ricalcola. La prova
 * tests/Integration/Portale/ContestoPannelloTest.php esegue il pezzo di testata che assegna quelle variabili (fra i marcatori «confronto-testata»)
 * sui 23 profili di PermessiTest e controlla che coincidano con questa classe: se qualcuno rimette un calcolo nella testata, o cambia il modo
 * di scegliere l'area qui, la prova diventa rossa.
 *
 * Il risultato è calcolato una volta per richiesta (e per area richiesta).
 */
final class ContestoPannello
{
    /** @var array<string, array{utente: array<string, mixed>|null, area: int, riga: array<string, mixed>|null, disponibili: list<array<string, mixed>>, permessi: Permessi|null}> */
    private array $calcolati = [];

    public function __construct(
        private readonly Sessione $sessione,
        private readonly UtenteRepository $utenti,
        private readonly ServizioAbilitazioni $abilitazioni,
        private readonly AreeGestite $areeGestite,
        private readonly AreeRepository $aree,
        private readonly PermessiFabbrica $fabbrica,
    ) {
    }

    /** La persona è collegata (esiste la sua riga nel database)? */
    public function collegata(Richiesta $r): bool
    {
        return $this->calcola($r)['utente'] !== null;
    }

    /** @return array<string, mixed>|null la riga di utenti */
    public function utente(Richiesta $r): ?array
    {
        return $this->calcola($r)['utente'];
    }

    /** L'area aperta (?p_id= se la persona la gestisce, altrimenti la prima che gestisce), 0 se non ne ha. È `$filtro_p` della testata. */
    public function area(Richiesta $r): int
    {
        return $this->calcola($r)['area'];
    }

    /** I permessi della persona nell'area aperta; null se non è collegata. */
    public function permessi(Richiesta $r): ?Permessi
    {
        return $this->calcola($r)['permessi'];
    }

    /** @return array<string, mixed>|null la riga dell'area aperta (pagine_eventi): `$page_cfg` della testata; null se la persona non ha aree */
    public function rigaArea(Richiesta $r): ?array
    {
        return $this->calcola($r)['riga'];
    }

    /** @return list<array<string, mixed>> le aree che la persona gestisce (`$pagine_disponibili` della testata) */
    public function areeDisponibili(Richiesta $r): array
    {
        return $this->calcola($r)['disponibili'];
    }

    /** @return array{utente: array<string, mixed>|null, area: int, riga: array<string, mixed>|null, disponibili: list<array<string, mixed>>, permessi: Permessi|null} */
    private function calcola(Richiesta $r): array
    {
        $uid = (int) $this->sessione->leggi('utente_id');
        $chiesta = (int) $r->query('p_id', 0);
        $chiave = $uid . ':' . $chiesta;
        if (isset($this->calcolati[$chiave])) {
            return $this->calcolati[$chiave];
        }
        $utente = $uid > 0 ? $this->utenti->perId($uid) : null;
        if ($utente === null) {
            return $this->calcolati[$chiave] = ['utente' => null, 'area' => 0, 'riga' => null, 'disponibili' => [], 'permessi' => null];
        }
        $amministratore = Permessi::eAmministratoreDa($utente);
        $areeAmbito = $amministratore ? [] : $this->abilitazioni->areeDaAmbiti($uid);
        $disponibili = $this->areeGestite->perUtente($uid, $amministratore, array_map('intval', $areeAmbito));
        $consentite = array_map('intval', array_column($disponibili, 'id'));
        $area = in_array($chiesta, $consentite, true) ? $chiesta : ($consentite[0] ?? 0);
        $riga = $area > 0 ? $this->aree->perId($area) : null;

        return $this->calcolati[$chiave] = [
            'utente' => $utente,
            'area' => $area,
            'riga' => $riga,
            'disponibili' => $disponibili,
            'permessi' => $this->fabbrica->per($utente, $riga, $disponibili),
        ];
    }
}
