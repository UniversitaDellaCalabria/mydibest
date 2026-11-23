<?php

declare(strict_types=1);

namespace App\Auth;

use App\Portale\Pannello\MenuModuli;
use App\Portale\Sezioni;

/**
 * Cosa può fare un utente nel pannello, per l'area che sta aprendo. Un metodo per permesso; ogni risultato si calcola una volta sola per richiesta.
 *
 * I nomi dei permessi (le chiavi di {@see self::elenco()}) sono quelli che {@see \App\Portale\Pannello\PermessiPagine} e
 * {@see MenuModuli} usano per dire chi apre una pagina o vede una voce: sono il contratto, e questa classe è ciò che li calcola. Ogni chiave ha un metodo
 * (tabella {@see self::METODI}); un test controlla che ogni nome usato dalla mappa e dal menu sia una chiave di elenco().
 *
 * Si costruisce con la riga dell'utente E l'area corrente: cinque permessi (is_area_manager, can_manage_*) valgono per l'area aperta e cambiano
 * con ?p_id=. Senza area (null) si calcolano come per chi non ne gestisce nessuna.
 *
 * «È amministratore» ({@see self::eAmministratore()}) è deciso qui, dalla riga dell'utente nel database e non dalla sessione: chi perde il ruolo
 * smette di essere amministratore alla richiesta dopo, senza aspettare un nuovo accesso. Lo leggono admin_header.php, middleware.php e master_template.php.
 *
 * I calcoli sono quelli che stavano in admin/admin_header.php (variabili $puo_*, $can_manage_*): spostati, non riscritti.
 */
final class Permessi
{
    /**
     * Permesso => metodo. L'ordine è quello di elenco().
     *
     * @var array<string, string>
     */
    public const METODI = [
        'is_full_admin' => 'eAmministratore',
        'is_area_manager' => 'gestoreArea',
        'can_manage_eventi' => 'gestisceEventi',
        'can_manage_iscritti' => 'gestisceIscritti',
        'can_manage_sondaggi' => 'gestisceSondaggi',
        'can_manage_form' => 'gestisceForm',
        'can_manage_settings' => 'gestisceImpostazioni',
        'puo_creare_eventi' => 'creaEventi',
        'puo_creare_progetti' => 'creaProgetti',
        'puo_fsl' => 'fsl',
        'puo_fsl_convenzioni' => 'fslConvenzioni',
        'puo_fsl_scuole' => 'fslScuole',
        'puo_didattica' => 'didattica',
        'puo_didattica_tutto' => 'didatticaTutto',
        'puo_conv_tesi' => 'convenzioniTesi',
        'puo_tir_motorie' => 'tirociniMotorie',
        'puo_tutorato' => 'tutorato',
        'puo_forms' => 'forms',
        'puo_forms_cogestore' => 'formsCogestore',
        'puo_forms_vedere' => 'formsVedere',
        'puo_amministrazione' => 'amministrazione',
        'puo_pubblicazioni' => 'pubblicazioni',
        'puo_amm_entrare' => 'ammEntrare',
        'puo_assistenza' => 'assistenza',
        'puo_orientamento' => 'orientamento',
        'ha_caselle' => 'haCaselle',
        'ha_sezioni' => 'haSezioni',
    ];

    /** Permessi che costano una query e che il pannello calcola solo se la pagina li chiede (vedi {@see self::dichiarati()}). */
    private const A_RICHIESTA = ['puo_orientamento', 'ha_caselle'];

    private readonly int $uid;
    private readonly int $areaId;

    /** @var array<string, mixed> i risultati già calcolati */
    private array $calcolati = [];

    /**
     * @param array<string, mixed>|null  $utente          la riga di utenti
     * @param array<string, mixed>|null  $area            la riga dell'area aperta (pagine_eventi), null se non ce n'è
     * @param list<array<string, mixed>> $areeDisponibili le aree che l'utente gestisce (servono a ha_sezioni e ai moduli)
     */
    public function __construct(
        private readonly PermessiFabbrica $f,
        private readonly ?array $utente,
        private readonly ?array $area = null,
        private readonly array $areeDisponibili = [],
    ) {
        $this->uid = (int) ($utente['id'] ?? 0);
        $this->areaId = (int) ($area['id'] ?? 0);
    }

    /**
     * L'utente è amministratore globale (ruolo principale 1, o 1 fra i ruoli secondari)? Dalla riga del database; false se la riga manca
     * (utente cancellato con la sessione ancora aperta). È la regola unica: la leggono anche i punti che non costruiscono un Permessi.
     *
     * @param array<string, mixed>|null $utente la riga di utenti
     */
    public static function eAmministratoreDa(?array $utente): bool
    {
        if ($utente === null) {
            return false;
        }
        $secondari = array_filter(explode(',', (string) ($utente['ruoli_secondari'] ?? '')));

        return (int) ($utente['ruolo_id'] ?? 0) === 1 || in_array('1', $secondari);
    }

    // ───────────────────────── Amministratore e area ─────────────────────────

    public function eAmministratore(): bool
    {
        return $this->una('is_full_admin', fn (): bool => self::eAmministratoreDa($this->utente));
    }

    /** Gestisce tutta l'area aperta (o è amministratore, o è abilitato all'intero modulo dell'area). */
    public function gestoreArea(): bool
    {
        return $this->perArea()['gestore'];
    }

    public function gestisceEventi(): bool
    {
        return $this->perArea()['eventi'];
    }

    public function gestisceIscritti(): bool
    {
        return $this->perArea()['iscritti'];
    }

    public function gestisceSondaggi(): bool
    {
        return $this->perArea()['sondaggi'];
    }

    public function gestisceForm(): bool
    {
        return $this->perArea()['form'];
    }

    public function gestisceImpostazioni(): bool
    {
        return $this->perArea()['impostazioni'];
    }

    public function creaEventi(): bool
    {
        return $this->perArea()['crea_eventi'];
    }

    public function creaProgetti(): bool
    {
        return $this->perArea()['crea_progetti'];
    }

    /**
     * Le attività dell'area che l'utente gestisce una per una o per perimetro (vuoto per chi gestisce tutta l'area o è amministratore).
     *
     * @return list<int>
     */
    public function attivitaPermesse(): array
    {
        return $this->perArea()['attivita'];
    }

    // ───────────────────────── Formazione Scuola Lavoro ─────────────────────────

    /** Modulo FSL (perimetro «fsl» o modulo Orientamento, come prima). */
    public function fsl(): bool
    {
        return $this->una('puo_fsl', fn (): bool => $this->eAmministratore() || $this->f->abilitazioni->haModulo($this->uid, 'fsl'));
    }

    /** Le persone dell'ufficio «Ufficio convenzioni FSL» lavorano le convenzioni delle scuole. */
    public function fslConvenzioni(): bool
    {
        return $this->una('puo_fsl_convenzioni', fn (): bool => $this->fsl()
            || $this->f->abilitazioni->haAmbito($this->uid, 'fsl_convenzioni')
            || $this->f->uffici->utenteMembro($this->utente, 'convenzioni_fsl'));
    }

    public function fslScuole(): bool
    {
        return $this->una('puo_fsl_scuole', fn (): bool => $this->fsl() || $this->f->abilitazioni->haAmbito($this->uid, 'fsl_scuole'));
    }

    // ───────────────────────── Didattica ─────────────────────────

    public function didatticaTutto(): bool
    {
        return $this->una('puo_didattica_tutto', fn (): bool => $this->eAmministratore()
            || $this->f->abilitazioni->haModulo($this->uid, 'didattica')
            || $this->f->ufficiDidattica->utenteOperatore($this->utente));
    }

    /**
     * Consigli dei corsi di studio di cui l'utente è referente: solo le sedute dei loro consigli (vuoto per chi lavora in tutta la Didattica).
     *
     * @return list<int>
     */
    public function consigliReferente(): array
    {
        return $this->una('consigli_referente', fn (): array => $this->didatticaTutto() ? [] : $this->f->consigli->referenteDi($this->utente));
    }

    public function didattica(): bool
    {
        return $this->una('puo_didattica', fn (): bool => $this->didatticaTutto() || (bool) $this->consigliReferente());
    }

    /** Convenzioni di tesi: chi lavora in Didattica e le persone dell'ufficio «Ufficio convenzioni di tesi». */
    public function convenzioniTesi(): bool
    {
        return $this->una('puo_conv_tesi', fn (): bool => $this->didatticaTutto() || $this->f->convenzioniTesi->utenteDellUfficio($this->utente));
    }

    /** Tirocini di Scienze motorie: chi lavora in Didattica e le persone dell'ufficio «Ufficio tirocini (Scienze motorie)». */
    public function tirociniMotorie(): bool
    {
        return $this->una('puo_tir_motorie', fn (): bool => $this->didatticaTutto() || $this->f->uffici->utenteMembro($this->utente, 'tirocini_motorie'));
    }

    public function tutorato(): bool
    {
        return $this->una('puo_tutorato', fn (): bool => $this->eAmministratore()
            || $this->f->abilitazioni->haModulo($this->uid, 'didattica')
            || $this->f->ufficiDidattica->utenteOperatore($this->utente, 'bandi'));
    }

    // ───────────────────────── MyForms ─────────────────────────

    /** Abilitato a MyForms per intero: può creare form nuovi. */
    public function forms(): bool
    {
        return $this->una('puo_forms', fn (): bool => $this->eAmministratore() || $this->f->abilitazioni->haModulo($this->uid, 'forms'));
    }

    /** Co-gestore di almeno un form senza l'abilitazione al modulo: entra comunque in MyForms e vede solo i suoi form. */
    public function formsCogestore(): bool
    {
        return $this->una('puo_forms_cogestore', fn (): bool => !$this->forms() && $this->f->forms->haGestiti($this->uid));
    }

    /** Decide l'accesso al modulo MyForms. */
    public function formsVedere(): bool
    {
        return $this->una('puo_forms_vedere', fn (): bool => $this->forms() || $this->formsCogestore());
    }

    // ───────────────────────── Amministrazione, assistenza, orientamento ─────────────────────────

    /** Tutto il modulo Amministrazione (amministratori e «Modulo Amministrazione»). */
    public function amministrazione(): bool
    {
        return $this->una('puo_amministrazione', fn (): bool => $this->f->amministrazione->puoTutto($this->utente));
    }

    /** Anche chi ha solo «Amministrazione · Richieste di pubblicazione». */
    public function pubblicazioni(): bool
    {
        return $this->una('puo_pubblicazioni', fn (): bool => $this->f->amministrazione->puoPubblicazioni($this->utente));
    }

    /** Una delle due: entra nel modulo Amministrazione. */
    public function ammEntrare(): bool
    {
        return $this->una('puo_amm_entrare', fn (): bool => $this->f->amministrazione->puoEntrare($this->utente));
    }

    /** Le persone dell'ufficio fisso «Assistenza» e gli amministratori rispondono alle chat. */
    public function assistenza(): bool
    {
        return $this->una('puo_assistenza', fn (): bool => $this->f->assistenza->eOperatore($this->utente));
    }

    public function orientamento(): bool
    {
        return $this->una('puo_orientamento', fn (): bool => $this->eAmministratore() || $this->f->abilitazioni->haModulo($this->uid, 'orientamento'));
    }

    /** Ha almeno una casella dei messaggi degli iscritti (un'area che gestisce, secondo CasellaMessaggi). */
    public function haCaselle(): bool
    {
        return $this->una('ha_caselle', fn (): bool => (bool) $this->f->caselle->aree($this->uid, $this->eAmministratore()));
    }

    // ───────────────────────── Moduli e sezioni ─────────────────────────

    /**
     * I moduli del pannello che l'utente può aprire (chiavi di MODULI_PORTALE, nell'ordine di MODULI_PORTALE): quelli che gli danno i permessi e quelli
     * delle sue aree.
     *
     * @return list<string>
     */
    public function moduli(): array
    {
        return $this->una('moduli', function (): array {
            $moduliArea = array_values(array_unique(array_map(fn (array $a): string => Sezioni::moduloDiArea($a), $this->areeDisponibili)));
            $visibili = array_flip(MenuModuli::moduliVisibili($this->chiusure(), $moduliArea));

            return array_values(array_filter(array_keys(MODULI_PORTALE), static fn (string $k): bool => isset($visibili[$k])));
        });
    }

    /** Ha almeno un modulo o un'area (o è amministratore): la condizione degli ingressi del pannello. Senza, si va all'area personale. */
    public function haSezioni(): bool
    {
        return $this->una('ha_sezioni', fn (): bool => $this->eAmministratore() || $this->moduli() !== [] || $this->areeDisponibili !== []);
    }

    // ───────────────────────── Elenchi ─────────────────────────

    /** @return list<string> i nomi dei permessi */
    public static function chiavi(): array
    {
        return array_keys(self::METODI);
    }

    /**
     * Tutti i permessi, calcolati subito. Per i test e per gli strumenti; il pannello usa {@see self::dichiarati()}, che non calcola ciò che non serve.
     *
     * @return array<string, bool>
     */
    public function elenco(): array
    {
        $out = [];
        foreach (self::METODI as $nome => $metodo) {
            $out[$nome] = $this->$metodo();
        }

        return $out;
    }

    /**
     * I permessi nella forma che {@see \App\Portale\Pannello\PermessiPagine::consente()} e {@see MenuModuli} si aspettano: un valore per nome, e per i due
     * che costano una query (puo_orientamento, ha_caselle) una funzione, chiamata solo se la regola della pagina la richiede.
     *
     * @return array<string, bool|\Closure():bool>
     */
    public function dichiarati(): array
    {
        $out = [];
        foreach ($this->chiusure() as $nome => $calcola) {
            $out[$nome] = in_array($nome, self::A_RICHIESTA, true) ? $calcola : $calcola();
        }

        return $out;
    }

    /**
     * Ogni permesso come funzione, da chiamare solo se serve (MenuModuli e PermessiPagine leggono per nome e chiamano solo quelli che la regola chiede).
     * Serve a moduli(), che non deve passare da ha_sezioni (dipende dai moduli stessi).
     *
     * @return array<string, \Closure():bool>
     */
    private function chiusure(): array
    {
        $out = [];
        foreach (self::METODI as $nome => $metodo) {
            $out[$nome] = fn (): bool => $this->$metodo();
        }

        return $out;
    }

    // ───────────────────────── Interni ─────────────────────────

    /**
     * @template T
     * @param callable():T $calcola
     * @return T
     */
    private function una(string $chiave, callable $calcola): mixed
    {
        if (!array_key_exists($chiave, $this->calcolati)) {
            $this->calcolati[$chiave] = $calcola();
        }

        return $this->calcolati[$chiave];
    }

    /**
     * I permessi che dipendono dall'area aperta. Dentro il proprio perimetro si gestisce tutto: attività, iscritti, sondaggi, moduli, attestati,
     * statistiche. Gestore di tutta l'area (o abilitato all'intero modulo dell'area): come un amministratore, ma solo per quell'area, anche per le
     * impostazioni. Attività scelte, tutti i progetti, tutti gli eventi dell'area, attività FSL: solo quelle (attivitaPermesse).
     *
     * @return array{gestore: bool, eventi: bool, iscritti: bool, sondaggi: bool, form: bool, impostazioni: bool, crea_eventi: bool, crea_progetti: bool, attivita: list<int>}
     */
    private function perArea(): array
    {
        return $this->una('area', function (): array {
            $admin = $this->eAmministratore();
            $r = ['gestore' => $admin, 'eventi' => $admin, 'iscritti' => $admin, 'sondaggi' => $admin, 'form' => $admin, 'impostazioni' => $admin,
                'crea_eventi' => $admin, 'crea_progetti' => $admin, 'attivita' => []];
            if ($admin || !$this->area) {
                return $r;
            }
            $abil = $this->f->abilitazioni;
            $campi = $this->area;
            if (in_array($this->uid, Abilitazioni\IdsGestori::daCampi($campi['gestore_utente_id'] ?? 0, $campi['gestori_utenti_ids'] ?? '', $campi['permessi_gestori_json'] ?? ''), true) || $abil->areaNelModuloUtente($this->uid, $campi)) {
                foreach (['gestore', 'eventi', 'iscritti', 'sondaggi', 'form', 'impostazioni', 'crea_eventi', 'crea_progetti'] as $k) {
                    $r[$k] = true;
                }

                return $r;
            }
            // Attività assegnate una per una
            $ids = [];
            foreach ($this->f->abilitazioniRepo->gestoriAttivitaAreaConTitolo($this->areaId) as $evento) {
                if (in_array($this->uid, Abilitazioni\IdsGestori::daCampi(0, $evento['gestori_utenti_ids'], $evento['permessi_gestori_json']), true)) {
                    $ids[] = (int) $evento['id'];
                }
            }
            // Perimetri: tutti i progetti / tutti gli eventi dell'area (anche quelli che verranno creati), attività FSL
            $r['attivita'] = array_values(array_unique(array_merge($ids, $abil->attivitaDaAmbiti($this->uid, $this->areaId))));
            $r['crea_progetti'] = $abil->haAmbito($this->uid, 'progetti', $this->areaId);
            $r['crea_eventi'] = $abil->haAmbito($this->uid, 'eventi', $this->areaId);
            if ($r['attivita'] || $r['crea_progetti'] || $r['crea_eventi']) {
                foreach (['eventi', 'iscritti', 'sondaggi', 'form'] as $k) {
                    $r[$k] = true;
                }
            }

            return $r;
        });
    }
}
