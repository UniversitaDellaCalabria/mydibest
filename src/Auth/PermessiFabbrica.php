<?php

declare(strict_types=1);

namespace App\Auth;

use App\Amministrazione\PermessiAmministrazione;
use App\Assistenza\OperatoriAssistenza;
use App\Auth\Abilitazioni\AbilitazioniRepository;
use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\ConvenzioniTesi\ServizioConvenzioniTesi;
use App\Didattica\ServizioConsigli;
use App\Didattica\ServizioUffici as UfficiDidattica;
use App\Forms\FormRepository;
use App\Iscritti\CasellaMessaggi;
use App\Uffici\ServizioUffici;

/**
 * Le dipendenze di {@see Permessi}, risolte dal container (autowiring), e il modo di crearne uno per un utente.
 *
 * Permessi non è un servizio: nasce per una richiesta, con la riga dell'utente e l'area aperta. Le classi che ne calcolano i pezzi (abilitazioni,
 * uffici, moduli…) sono invece quelle di sempre e arrivano da qui, nel costruttore, come per ogni altra classe di src/.
 */
final class PermessiFabbrica
{
    public function __construct(
        public readonly ServizioAbilitazioni $abilitazioni,
        public readonly AbilitazioniRepository $abilitazioniRepo,
        public readonly UfficiDidattica $ufficiDidattica,
        public readonly ServizioUffici $uffici,
        public readonly ServizioConsigli $consigli,
        public readonly ServizioConvenzioniTesi $convenzioniTesi,
        public readonly FormRepository $forms,
        public readonly PermessiAmministrazione $amministrazione,
        public readonly OperatoriAssistenza $assistenza,
        public readonly CasellaMessaggi $caselle,
    ) {
    }

    /**
     * I permessi di un utente nell'area corrente.
     *
     * @param array<string, mixed>|null       $utente          la riga di utenti
     * @param array<string, mixed>|null       $area            la riga dell'area aperta (?p_id=), null se non ce n'è
     * @param list<array<string, mixed>>      $areeDisponibili le aree che l'utente gestisce (App\Portale\Area\AreeGestite::perUtente)
     */
    public function per(?array $utente, ?array $area = null, array $areeDisponibili = []): Permessi
    {
        return new Permessi($this, $utente, $area, $areeDisponibili);
    }
}
