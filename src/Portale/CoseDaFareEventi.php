<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/**
 * Numeri di «Cosa devo fare» per Eventi e seminari, per una area (admin/dashboard.php?vista=cosa). Solo conteggi, una query ciascuno,
 * limitati agli eventi non archiviati dell'area (e a quelli che chi guarda può gestire: $rbac è il filtro «AND e.id IN (…)» del pannello,
 * con l'alias «e» per eventi). Se una query non riesce (tabella mancante in installazioni vecchie) il numero è zero.
 */
final class CoseDaFareEventi
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{da_approvare: int, attesa_con_posti: int, attestati: int, sondaggi: int}
     */
    public function perArea(int $paginaId, string $rbac = ''): array
    {
        return [
            'da_approvare' => $this->iscrizioniDaApprovare($paginaId, $rbac),
            'attesa_con_posti' => $this->turniInAttesaConPostiLiberi($paginaId, $rbac),
            'attestati' => $this->attestatiDaInviare($paginaId, $rbac),
            'sondaggi' => $this->sondaggiChiusiConRisposte($paginaId, $rbac),
        ];
    }

    /** Iscrizioni che aspettano l'approvazione di chi gestisce. */
    public function iscrizioniDaApprovare(int $paginaId, string $rbac = ''): int
    {
        return $this->conta("SELECT COUNT(*) FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
            WHERE e.pagina_id = ? AND e.archiviato = 0 AND p.stato = 'da_approvare' $rbac", $paginaId);
    }

    /**
     * Turni futuri (o senza data) con qualcuno in lista d'attesa e posti ancora liberi: di solito la lista si scorre da sola,
     * ma entro le 24 ore dall'inizio non più, e chi gestisce può offrire il posto a mano.
     */
    public function turniInAttesaConPostiLiberi(int $paginaId, string $rbac = ''): int
    {
        return $this->conta("SELECT COUNT(*) FROM turni t JOIN eventi e ON t.evento_id = e.id
            WHERE e.pagina_id = ? AND e.archiviato = 0 AND (t.data_turno IS NULL OR t.data_turno >= CURDATE()) $rbac
              AND EXISTS (SELECT 1 FROM prenotazioni a WHERE a.turno_id = t.id AND a.stato = 'in_attesa')
              AND t.max_posti > (SELECT COALESCE(SUM(o.num_posti), 0) FROM prenotazioni o WHERE o.turno_id = t.id
                                 AND IFNULL(o.stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare'))", $paginaId);
    }

    /**
     * Presenti con l'attestato personale non ancora inviato, a turno concluso. Gli eventi con attestati per la classe e i progetti
     * seguono un altro percorso (elenco degli studenti) e non si contano qui.
     */
    public function attestatiDaInviare(int $paginaId, string $rbac = ''): int
    {
        return $this->conta("SELECT COUNT(*) FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
            LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
            WHERE e.pagina_id = ? AND e.archiviato = 0 AND IFNULL(e.tipo, 'evento') <> 'progetto' AND IFNULL(pd.attestati, 0) = 0 AND IFNULL(e.rilascia_attestato, 1) = 1 $rbac
              AND p.stato = 'confermata' AND p.presente = 1 AND p.attestato_inviato = 0
              AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_fine, '23:59:59')) <= NOW())", $paginaId);
    }

    /**
     * Sondaggi sospesi (chiusi) di eventi non archiviati che hanno già delle risposte. Non esiste un «letto»: il numero scende
     * quando l'evento va in archivio o il sondaggio si riattiva.
     */
    public function sondaggiChiusiConRisposte(int $paginaId, string $rbac = ''): int
    {
        return $this->conta("SELECT COUNT(*) FROM sondaggi s JOIN eventi e ON s.evento_id = e.id
            WHERE e.pagina_id = ? AND e.archiviato = 0 AND s.attivo = 0 $rbac
              AND EXISTS (SELECT 1 FROM sondaggi_risposte r WHERE r.sondaggio_id = s.id)", $paginaId);
    }

    private function conta(string $sql, int $paginaId): int
    {
        try {
            return (int) $this->db->valore($sql, [$paginaId]);
        } catch (\Throwable $e) {
            error_log('Cosa devo fare (eventi): ' . $e->getMessage());

            return 0;
        }
    }
}
