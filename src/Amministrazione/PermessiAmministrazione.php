<?php

declare(strict_types=1);

namespace App\Amministrazione;

use App\Auth\Abilitazioni\AbilitazioniRepository;
use App\Auth\Pubblico;

/**
 * Chi può entrare nel modulo «Amministrazione» del pannello e fare cosa: una regola sola per le pagine (admin/amministrazione.php,
 * admin/pubblicazioni.php, admin/admin_header.php) e per ServizioPubblicazioni::puoGestire.
 *
 * - puoTutto: gli amministratori e chi ha l'abilitazione «Modulo Amministrazione» (tutto il modulo: modulistica, impostazioni, richieste);
 * - puoPubblicazioni: chi può tutto e chi ha solo «Amministrazione · Richieste di pubblicazione» (abilitazione parziale, quella che
 *   esisteva già quando le richieste erano un modulo a sé);
 * - puoEntrare: chi ha almeno una delle due;
 * - puoScheda: le schede di amministrazione.php. «home» (Cosa devo fare) si apre a chi può entrare, ma ci vede solo le voci delle
 *   richieste di pubblicazione; tutte le altre schede (le prossime) sono solo per chi può tutto.
 *
 * Le abilitazioni sono perimetri «modulo_…» con pagina_id 0 (come gli altri moduli, ServizioAbilitazioni::haModulo): lo stesso nome
 * legato a un'area non vale. valuta() è la regola vera, senza database: si prova da sola.
 */
final class PermessiAmministrazione
{
    /** L'abilitazione al modulo intero. */
    public const MODULO = 'modulo_amministrazione';
    /** L'abilitazione parziale: solo le richieste di pubblicazione. */
    public const MODULO_PUBBLICAZIONI = 'modulo_pubblicazioni';
    /** Le schede di amministrazione.php aperte anche a chi ha solo le richieste di pubblicazione. */
    public const SCHEDE_PARZIALE = ['home'];

    public function __construct(private AbilitazioniRepository $abilitazioni)
    {
    }

    /** Amministratore o abilitato a tutto il modulo. @param array<string, mixed>|null $utente la riga di utenti */
    public function puoTutto(?array $utente): bool
    {
        return $this->calcola($utente)['tutto'];
    }

    /** Può lavorare le richieste di pubblicazione: chi può tutto e chi ha la sola abilitazione parziale. @param array<string, mixed>|null $utente */
    public function puoPubblicazioni(?array $utente): bool
    {
        return $this->calcola($utente)['pubblicazioni'];
    }

    /** Entra nel modulo (almeno una delle due abilitazioni). @param array<string, mixed>|null $utente */
    public function puoEntrare(?array $utente): bool
    {
        $x = $this->calcola($utente);

        return $x['tutto'] || $x['pubblicazioni'];
    }

    /**
     * Può aprire la scheda di amministrazione.php: «home» a chi entra nel modulo, le altre schede solo a chi può tutto.
     *
     * @param array<string, mixed>|null $utente
     */
    public function puoScheda(?array $utente, string $scheda): bool
    {
        return in_array($scheda, self::SCHEDE_PARZIALE, true) ? $this->puoEntrare($utente) : $this->puoTutto($utente);
    }

    /**
     * La regola, senza database.
     *
     * @param array<string, mixed>|null $utente la riga di utenti
     * @param list<array{tipo: string, pagina_id: int}> $ambiti i perimetri dell'utente (AbilitazioniRepository::ambitiDi)
     * @return array{tutto: bool, pubblicazioni: bool}
     */
    public static function valuta(?array $utente, array $ambiti): array
    {
        if ($utente === null || empty($utente['id'])) {
            return ['tutto' => false, 'pubblicazioni' => false];
        }
        $tutto = self::eAmministratore($utente);
        $pubblicazioni = false;
        foreach ($ambiti as $a) {
            if ($a['pagina_id'] !== 0) {
                continue;
            }
            if ($a['tipo'] === self::MODULO) {
                $tutto = true;
            } elseif ($a['tipo'] === self::MODULO_PUBBLICAZIONI) {
                $pubblicazioni = true;
            }
        }

        return ['tutto' => $tutto, 'pubblicazioni' => $tutto || $pubblicazioni];
    }

    /** @param array<string, mixed> $utente */
    private static function eAmministratore(array $utente): bool
    {
        // Pubblico::ammesso con nessun profilo ammette solo gli amministratori (ruolo 1, anche secondario)
        return Pubblico::ammesso(['profili' => []], $utente);
    }

    /**
     * @param array<string, mixed>|null $utente
     * @return array{tutto: bool, pubblicazioni: bool}
     */
    private function calcola(?array $utente): array
    {
        if ($utente === null || empty($utente['id'])) {
            return ['tutto' => false, 'pubblicazioni' => false];
        }
        // Gli amministratori hanno già tutto: niente interrogazione del database
        $ambiti = self::eAmministratore($utente) ? [] : $this->abilitazioni->ambitiDi((int) $utente['id']);

        return self::valuta($utente, $ambiti);
    }
}
