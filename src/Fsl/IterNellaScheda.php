<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Container;
use App\Protocollo\InvioProtocollo;
use Throwable;

/**
 * Ciò che serve alla scheda della scuola per far fare TUTTO l'iter di una convenzione senza uscire da lì: per ogni compilazione in corso, in che stato è
 * l'invio a Titulus, chi sono il Direttore e i docenti, per quanto vale la convenzione. Sono le stesse letture che faceva la vecchia pagina dell'iter
 * di admin/fsl.php, con gli stessi servizi: qui stanno in un posto solo, così la scheda non copia niente.
 *
 * Titulus e il registro degli invii si costruiscono solo quando servono (un passo in cui si protocolla): senza Titulus configurato ogni invio è «libero»
 * e il pulsante «Invia al protocollo» non compare, resta il numero a mano.
 */
final class IterNellaScheda
{
    private const LIBERO = ['stato' => 'libero', 'numero' => '', 'il' => ''];

    private ?bool $titulusIngresso = null;
    private ?bool $titulusDefinitivo = null;

    public function __construct(
        private readonly Container $contenitore,   // i servizi pesanti (firma, Titulus, docenti) si prendono solo quando servono
        private readonly PeriodiConvenzione $periodi,
    ) {
    }

    /** Il Direttore che firma ora: nome ed email (vuota se non è indicato nessuno). @return array{nome: string, email: string} */
    public function direttore(): array
    {
        try {
            $d = $this->contenitore->get(ServizioFirmaDirettore::class)->direttore();
        } catch (Throwable) {
            $d = [];
        }

        return ['nome' => (string) ($d['nome'] ?? ''), 'email' => (string) ($d['email'] ?? '')];
    }

    /**
     * Aggiunge a ogni compilazione in corso i dati dell'iter. Le concluse (protocollata, unita) non servono qui.
     *
     * @param list<array<string, mixed>> $compilazioni come da SchedaScuolaFsl (con 'riga' = la riga del database)
     * @return list<array<string, mixed>>
     */
    public function arricchisci(array $compilazioni): array
    {
        [$dalPredefinito, $alPredefinito] = $this->periodi->nuova();
        $out = [];
        foreach ($compilazioni as $c) {
            if (!$c['in_corso']) {
                continue;
            }
            $riga = (array) ($c['riga'] ?? []);
            $stato = (string) $c['stato_iter'];
            $fase = match ($stato) {
                Costanti::ITER_CARICATA => 'ingresso',
                Costanti::ITER_FIRMATA => 'definitivo',
                default => '',
            };
            $decisi = json_decode((string) ($riga['docenti_json'] ?? ''), true);
            $docenti = is_array($decisi) && $decisi ? array_values(array_filter($decisi, 'is_array')) : (in_array($stato, [Costanti::ITER_CARICATA, Costanti::ITER_IN_CARICO, Costanti::ITER_DA_FIRMARE, Costanti::ITER_FIRMATA], true) ? $this->proposti($riga) : []);
            $out[] = $c + [
                'fase_titulus' => $fase,
                'titulus' => $fase !== '' ? $this->statoInvio($riga, $fase) : self::LIBERO,
                'titulus_pronto' => $fase !== '' && $this->titulusPronto($fase),
                'valida_dal' => (string) (($riga['valida_dal'] ?? '') ?: $dalPredefinito),
                'valida_al' => (string) (($riga['valida_al'] ?? '') ?: $alPredefinito),
                'docenti_salvati' => array_map(static fn (array $d): array => ['nome' => (string) ($d['nome'] ?? ''), 'email' => (string) ($d['email'] ?? '')], is_array($decisi) ? array_values(array_filter($decisi, 'is_array')) : []),
                'docenti_iter' => array_map(static fn (array $d): array => ['nome' => (string) ($d['nome'] ?? ''), 'email' => (string) ($d['email'] ?? '')], $docenti),
                'scuola_nome' => (string) ((json_decode((string) ($riga['dati_json'] ?? ''), true) ?: [])['scuola']['denominazione'] ?? ''),
                'dirigente' => (string) ((json_decode((string) ($riga['dati_json'] ?? ''), true) ?: [])['scuola']['dirigente'] ?? ''),
                'firmatario' => (string) ($riga['firmatario'] ?? ''), 'firmatario_direttore' => (string) ($riga['firmatario_direttore'] ?? ''),
                'firmata_il' => (string) ($riga['firmata_il'] ?? ''), 'firmata_direttore_il' => (string) ($riga['firmata_direttore_il'] ?? ''),
                'da_firmare_il' => (string) ($riga['da_firmare_il'] ?? ''),
                'avviso_firma_ingresso' => (string) ($riga['avviso_firma_ingresso'] ?? ''), 'avviso_firma' => (string) ($riga['avviso_firma'] ?? ''),
                'protocollo_errore' => (string) ($riga['protocollo_errore'] ?? ''),
                'protocollo_ingresso_data' => (string) ($riga['protocollo_ingresso_data'] ?? ''), 'protocollo_data' => (string) ($riga['protocollo_data'] ?? ''),
                'ha_protocollato' => $stato === Costanti::ITER_FIRMATA && $this->haProtocollatoAMano($riga),
                'dopo_firma' => $this->dopoFirma($riga),
                'passi_html' => '',
            ];
        }

        return $out;
    }

    /**
     * Le compilazioni concluse con la registrazione (protocollata): il resoconto di cosa è successo alle prenotazioni e lo stato del file ripreso da Titulus,
     * con i pulsanti per riprovare e per caricare il documento protocollato. Non legge niente da Titulus.
     *
     * @param list<array<string, mixed>> $compilazioni come da SchedaScuolaFsl (con 'riga')
     * @return list<array<string, mixed>>
     */
    public function registrate(array $compilazioni): array
    {
        $out = [];
        foreach ($compilazioni as $c) {
            if ($c['stato_iter'] !== Costanti::ITER_PROTOCOLLATA) {
                continue;
            }
            $riga = (array) ($c['riga'] ?? []);
            $es = json_decode((string) ($riga['esito_registrazione'] ?? ''), true);
            $es = is_array($es) ? $es : [];
            $out[] = $c + [
                'scuola_nome' => (string) ((json_decode((string) ($riga['dati_json'] ?? ''), true) ?: [])['scuola']['denominazione'] ?? ''),
                'protocollo_ingresso_data' => (string) ($riga['protocollo_ingresso_data'] ?? ''), 'protocollo_data' => (string) ($riga['protocollo_data'] ?? ''),
                'resoconto' => $es === [] ? null : [
                    'coperte' => (int) ($es['coperte'] ?? 0), 'approvate' => (int) ($es['approvate'] ?? 0), 'senza_codice' => (int) ($es['senza_codice'] ?? 0), 'errore' => (string) ($es['errore'] ?? ''),
                    'restano' => array_map(static fn ($x): array => ['codice' => (string) (($x['codice'] ?? '') ?: '#' . (int) ($x['id'] ?? 0)), 'motivo' => (string) ($x['motivo'] ?? '')], array_values(array_filter((array) ($es['restano'] ?? []), 'is_array'))),
                ],
                'originale_stato' => (string) ($riga['originale_stato'] ?? ''), 'originale_avviso' => (string) ($riga['originale_avviso'] ?? ''), 'originale_tentativi' => (int) ($riga['originale_tentativi'] ?? 0),
            ];
        }

        return $out;
    }

    private function firmata(): ServizioConvenzioneFirmata
    {
        return $this->contenitore->get(ServizioConvenzioneFirmata::class);
    }

    /** @param array<string, mixed> $riga @return list<array<string, mixed>> */
    private function proposti(array $riga): array
    {
        try {
            return $this->contenitore->get(DocentiConvenzione::class)->proposti($riga);
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $riga */
    private function haProtocollatoAMano(array $riga): bool
    {
        try {
            return $this->firmata()->haProtocollatoAMano($riga);
        } catch (Throwable) {
            return false;
        }
    }

    /** Quante attività sono state aggiunte dopo la firma della scuola (non sono nel PDF firmato). @param array<string, mixed> $riga */
    private function dopoFirma(array $riga): int
    {
        $dati = json_decode((string) ($riga['dati_json'] ?? ''), true) ?: [];
        $firmata = (string) ($riga['firmata_il'] ?? '');

        return $firmata === '' ? 0 : count(array_filter((array) ($dati['attivita'] ?? []), static fn ($a): bool => is_array($a) && !empty($a['il']) && (string) $a['il'] > $firmata));
    }

    /**
     * Com'è l'invio a Titulus di quel documento: libero | in_invio | incerto | in_approvazione | precedente | protocollato (senza Titulus: libero).
     *
     * @param array<string, mixed> $riga
     * @return array{stato: string, numero: string, il: string}
     */
    private function statoInvio(array $riga, string $fase): array
    {
        try {
            $invio = $this->contenitore->get(InvioProtocollo::class);

            return $fase === 'ingresso'
                ? $invio->stato($this->firmata()->rifIngresso($riga), (int) $riga['id'], ProtocollaConvenzioneInTitulus::RIF, (string) ($riga['firmata_il'] ?? ''))
                : $invio->stato($this->firmata()->rifDefinitivo($riga), (int) $riga['id'], ProtocollaDefinitivaFslInTitulus::RIF, (string) ($riga['firmata_direttore_il'] ?? ''));
        } catch (Throwable) {
            return self::LIBERO;
        }
    }

    /** Il collegamento a Titulus è pronto e c'è la regola per quel documento? Si chiede una volta sola. */
    private function titulusPronto(string $fase): bool
    {
        try {
            if ($fase === 'ingresso') {
                return $this->titulusIngresso ??= $this->contenitore->get(ProtocollaConvenzioneInTitulus::class)->disponibile();
            }

            return $this->titulusDefinitivo ??= $this->contenitore->get(ProtocollaDefinitivaFslInTitulus::class)->disponibile();
        } catch (Throwable) {
            return false;
        }
    }
}
