<?php

declare(strict_types=1);

namespace App\Portale\Pagine;

use App\Fsl\Azioni\AzioniIter;
use App\Fsl\Azioni\AzioniMessaggiPersone;
use App\Fsl\Azioni\AzioniRegistro;
use App\Fsl\Azioni\Esito;
use App\Fsl\AppartenenzaScuolaFsl;
use App\Http\Richiesta;

/**
 * Le azioni (POST) della pagina «Scuole e convenzioni»: una sola porta, che decide TRE cose prima di eseguire e poi passa la mano alla classe giusta.
 *
 *  1. Chi può: le azioni sui numeri e sulle valutazioni sono di chi gestisce tutta la FSL; chi ha solo le convenzioni no.
 *  2. Dove: le azioni a TUTTE le scuole (promemoria generali, verifica delle iscrizioni) si fanno solo dall'elenco, non dalla scheda di una scuola.
 *  3. Di chi è: ogni id che l'azione tocca (compilazione, convenzione, Allegato A, prenotazione, anche i due di «Unisci» e le coppie compilazione:prenotazione)
 *     deve essere della scuola aperta; se no non si scrive niente, non parte nessuna email e non si tocca nessun file.
 *
 * Il token CSRF lo verifica il Nucleo prima di costruire la pagina. Le azioni sono divise per argomento: l'iter (AzioniIter), il registro
 * (AzioniRegistro), i messaggi e le persone (AzioniMessaggiPersone). Sono le stesse classi che usa admin/fsl.php finché il passo 9 non la toglie.
 */
final class AzioniScuolaFsl
{
    /** Le azioni che solo chi gestisce tutta la FSL può fare (valutazioni e verifica generale delle iscrizioni). */
    private const SOLO_FSL = ['ricorda_valutazione', 'conv_verifica'];

    public function __construct(
        private readonly AzioniIter $iter,
        private readonly AzioniRegistro $registro,
        private readonly AzioniMessaggiPersone $messaggiPersone,
        private readonly AppartenenzaScuolaFsl $appartiene,
    ) {
    }

    /** Il nome del campo-pulsante di una POST «per me», o null. */
    public function azioneDi(Richiesta $r): ?string
    {
        foreach ([AzioniIter::AZIONI, AzioniRegistro::AZIONI, AzioniMessaggiPersone::AZIONI] as $elenco) {
            foreach ($elenco as $a) {
                if ($r->post($a) !== null) {
                    return $a;
                }
            }
        }

        return null;
    }

    /**
     * @param string $chiave la chiave della scheda aperta ('' = l'elenco)
     * @param string $istituto codice dell'istituto della scheda ('' per un'elenco o per una scuola scritta a mano)
     * @param array{id: int, email: string, nome: string} $operatore chi preme il pulsante
     * @return array{messaggio: string, tono: string, audit: ?array{0: string, 1: array<string, mixed>}, ancora: string}|null null: nessuna azione conosciuta nella richiesta
     */
    public function esegui(Richiesta $r, string $chiave, string $istituto, bool $puoFsl, array $operatore): ?array
    {
        $azione = $this->azioneDi($r);
        if ($azione === null) {
            return null;
        }
        if (in_array($azione, self::SOLO_FSL, true) && !$puoFsl) {
            return Esito::fa('Questa azione è di chi gestisce tutta la Formazione Scuola Lavoro: tu hai solo le convenzioni.', 'danger');
        }
        $aTutte = in_array($azione, AzioniMessaggiPersone::A_TUTTE, true) || $azione === 'conv_verifica';
        if ($aTutte && $chiave !== '') {
            return Esito::fa("Questa azione riguarda tutte le scuole: si fa dall'elenco, non dalla scheda di una scuola.", 'danger');
        }
        if (!$aTutte && !$this->tuttoDiQuestaScuola($r, $azione, $chiave, $istituto)) {
            return Esito::fa(AppartenenzaScuolaFsl::MESSAGGIO, 'danger');
        }

        return match (true) {
            $this->iter->conosce($azione) => $this->iter->esegui($r, $azione, $istituto, $operatore),
            $this->registro->conosce($azione) => $this->registro->esegui($r, $azione, $istituto, $operatore),
            default => $this->messaggiPersone->esegui($r, $azione, $istituto, $operatore),
        };
    }

    /** Un solo punto: ogni id che l'azione tocca è della scuola aperta? */
    private function tuttoDiQuestaScuola(Richiesta $r, string $azione, string $chiave, string $istituto): bool
    {
        $compilazioni = $this->iter->conosce($azione) ? AzioniIter::compilazioniDi($r, $azione) : AzioniMessaggiPersone::compilazioniDi($r, $azione);
        foreach ($compilazioni as $id) {
            if (!$this->appartiene->compilazione($id, $istituto)) {
                return false;
            }
        }
        $prenotazioni = $this->iter->conosce($azione) ? AzioniIter::prenotazioniDi($r, $azione) : AzioniMessaggiPersone::prenotazioniDi($r, $azione);
        foreach ($prenotazioni as $id) {
            if (!$this->appartiene->prenotazione($id, $istituto, $chiave)) {
                return false;
            }
        }

        return match ($azione) {
            'conv_elimina' => $this->appartiene->convenzione((int) $r->post($azione), $istituto),
            'allegato_accetta', 'allegato_rifiuta' => $this->appartiene->allegato((int) $r->post('allegato_id', 0), $istituto),
            'conv_salva' => $this->appartiene->codiceScuola(AzioniRegistro::scuolaDelModulo($r), $istituto)
                && ((int) $r->post('conv_id', 0) === 0 || $this->appartiene->convenzione((int) $r->post('conv_id', 0), $istituto)),
            'docente_salva', 'avvia_conferma' => $istituto !== '',
            default => true,
        };
    }
}
