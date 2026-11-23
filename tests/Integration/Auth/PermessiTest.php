<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Auth\Permessi;
use App\Auth\PermessiFabbrica;
use App\Core\App;
use App\Portale\Area\AreeGestite;
use App\Portale\AreeRepository;

/**
 * App\Auth\Permessi: cosa può fare ciascun tipo di utente nel pannello.
 *
 * Tre prove: (1) per ogni profilo, i permessi veri sono esattamente quelli attesi (ogni permesso è provato con chi ce l'ha e con chi non ce l'ha);
 * (2) gli stessi valori del vecchio calcolo di admin/admin_header.php (copiato qui sotto così com'era), per tutti i profili: è la rete di sicurezza dello spostamento;
 * (3) il ruolo cambiato nel database si vede alla richiesta dopo, senza un nuovo accesso.
 *
 * I profili e le aree di prova (10 «Eventi», 11 «FSL», 12 senza gestori) sono in ProfiliPannelloBase; le facciate di sempre (ha_modulo…) le carica lei.
 */
final class PermessiTest extends ProfiliPannelloBase
{
    private const AREA = ['is_area_manager', 'can_manage_eventi', 'can_manage_iscritti', 'can_manage_sondaggi', 'can_manage_form', 'can_manage_settings', 'puo_creare_eventi', 'puo_creare_progetti'];
    private const FSL = ['puo_fsl', 'puo_fsl_convenzioni', 'puo_fsl_scuole'];
    private const DIDATTICA = ['puo_didattica', 'puo_didattica_tutto', 'puo_conv_tesi', 'puo_tir_motorie', 'puo_tutorato'];
    private const GESTISCE_ATTIVITA = ['can_manage_eventi', 'can_manage_iscritti', 'can_manage_sondaggi', 'can_manage_form'];

    // ───────────────────────── Il contesto come lo costruisce admin_header.php ─────────────────────────

    /**
     * Le aree dell'utente, l'area corrente e i permessi, come in admin/admin_header.php (area richiesta con ?p_id=, altrimenti la prima delle sue).
     *
     * @param array<string, mixed> $u
     * @return array{permessi: Permessi, area: ?array<string, mixed>, area_id: int, aree: list<array<string, mixed>>}
     */
    private function contesto(array $u, int $richiesta = 0): array
    {
        $admin = Permessi::eAmministratoreDa($u);
        $ambito = $admin ? [] : App::get(\App\Auth\Abilitazioni\ServizioAbilitazioni::class)->areeDaAmbiti((int) $u['id']);
        $aree = App::get(AreeGestite::class)->perUtente((int) $u['id'], $admin, array_map('intval', $ambito));
        $ids = array_map('intval', array_column($aree, 'id'));
        $filtro = in_array($richiesta, $ids, true) ? $richiesta : ($ids[0] ?? 0);
        $area = $filtro > 0 ? App::get(AreeRepository::class)->perId($filtro) : null;

        return ['permessi' => App::get(PermessiFabbrica::class)->per($u, $area, $aree), 'area' => $area, 'area_id' => $filtro, 'aree' => $aree];
    }

    /** @return list<string> i permessi veri, in ordine alfabetico */
    private static function veri(Permessi $p): array
    {
        $v = array_keys(array_filter($p->elenco()));
        sort($v);

        return $v;
    }

    // ───────────────────────── (1) Chi ha cosa ─────────────────────────

    /** @return array<string, list<string>> profilo => permessi veri (tutti gli altri sono falsi) */
    private static function attesi(): array
    {
        $tutti = array_diff(Permessi::chiavi(), ['puo_forms_cogestore']);

        return [
            'admin' => array_values($tutti),
            'admin_secondario' => array_values($tutti),
            'gestore_area' => array_merge(self::AREA, ['ha_caselle', 'ha_sezioni']),
            'docente' => [],
            'attivita' => array_merge(self::GESTISCE_ATTIVITA, ['ha_caselle', 'ha_sezioni']),
            'perimetro_eventi' => array_merge(self::GESTISCE_ATTIVITA, ['puo_creare_eventi', 'ha_caselle', 'ha_sezioni']),
            'perimetro_progetti' => array_merge(self::GESTISCE_ATTIVITA, ['puo_creare_progetti', 'ha_caselle', 'ha_sezioni']),
            // Il modulo Orientamento comprende la FSL e fa gestore di tutte le aree del modulo
            'modulo_orientamento' => array_merge(self::AREA, self::FSL, ['puo_orientamento', 'ha_caselle', 'ha_sezioni']),
            'fsl_convenzioni' => ['puo_fsl_convenzioni', 'ha_sezioni'],
            'fsl_scuole' => ['puo_fsl_scuole', 'ha_sezioni'],
            'modulo_didattica' => array_merge(self::DIDATTICA, ['ha_sezioni']),
            'operatore_pratiche' => ['puo_didattica', 'puo_didattica_tutto', 'puo_conv_tesi', 'puo_tir_motorie', 'ha_sezioni'],
            'operatore_bandi' => array_merge(self::DIDATTICA, ['ha_sezioni']),
            'referente_consiglio' => ['puo_didattica', 'ha_sezioni'],
            'ufficio_convenzioni_tesi' => ['puo_conv_tesi', 'ha_sezioni'],
            'ufficio_tirocini_motorie' => ['puo_tir_motorie', 'ha_sezioni'],
            'ufficio_convenzioni_fsl' => ['puo_fsl_convenzioni', 'ha_sezioni'],
            'ufficio_assistenza' => ['puo_assistenza', 'ha_sezioni'],
            'modulo_forms' => ['puo_forms', 'puo_forms_vedere', 'ha_sezioni'],
            'cogestore_form' => ['puo_forms_cogestore', 'puo_forms_vedere', 'ha_sezioni'],
            'modulo_amministrazione' => ['puo_amministrazione', 'puo_pubblicazioni', 'puo_amm_entrare', 'ha_sezioni'],
            'solo_pubblicazioni' => ['puo_pubblicazioni', 'puo_amm_entrare', 'ha_sezioni'],
            'ruolo_fra_i_secondari_vuoto' => [],
        ];
    }

    /** @return array<string, array{string}> */
    public static function profili(): array
    {
        return array_map(static fn (string $p): array => [$p], array_combine(array_keys(self::attesi()), array_keys(self::attesi())));
    }

    /**
     * @dataProvider profili
     */
    public function testOgniProfiloHaEsattamenteISuoiPermessi(string $profilo): void
    {
        $c = $this->contesto($this->utenti[$profilo]);
        $atteso = self::attesi()[$profilo];
        sort($atteso);
        $this->assertSame($atteso, self::veri($c['permessi']), "profilo «{$profilo}»");
    }

    public function testOgniPermessoHaUnProfiloCheLoHaEUnoCheNonLoHa(): void
    {
        $inAlmenoUno = [];
        foreach (self::attesi() as $veri) {
            foreach ($veri as $nome) {
                $inAlmenoUno[$nome] = true;
            }
        }
        foreach (Permessi::chiavi() as $nome) {
            $this->assertArrayHasKey($nome, $inAlmenoUno, "nessun profilo prova il permesso «{$nome}» in positivo");
            $this->assertNotSame(count(self::attesi()), count(array_filter(self::attesi(), static fn (array $v): bool => in_array($nome, $v, true))), "nessun profilo prova «{$nome}» in negativo");
        }
    }

    public function testLeAttivitaDelGestoreDiAlcuneAttivita(): void
    {
        $this->assertSame([100], $this->contesto($this->utenti['attivita'])['permessi']->attivitaPermesse());
        $this->assertSame([100, 101], $this->contesto($this->utenti['perimetro_eventi'])['permessi']->attivitaPermesse());
        $this->assertSame([102], $this->contesto($this->utenti['perimetro_progetti'])['permessi']->attivitaPermesse());
        $this->assertSame([], $this->contesto($this->utenti['gestore_area'])['permessi']->attivitaPermesse(), 'chi gestisce tutta l\'area non ha un elenco di attività');
        $this->assertSame([], $this->contesto($this->utenti['admin'])['permessi']->attivitaPermesse());
    }

    public function testIConsigliDelReferente(): void
    {
        $this->assertCount(1, $this->contesto($this->utenti['referente_consiglio'])['permessi']->consigliReferente());
        $this->assertSame([], $this->contesto($this->utenti['modulo_didattica'])['permessi']->consigliReferente(), 'chi lavora in tutta la Didattica non ha un elenco di consigli');
        $this->assertSame([], $this->contesto($this->utenti['docente'])['permessi']->consigliReferente());
    }

    /** I cinque permessi che dipendono dall'area: con l'area sbagliata sono tutti falsi. */
    public function testConLAreaSbagliataIPermessiDiAreaSonoFalsi(): void
    {
        $fab = App::get(PermessiFabbrica::class);
        $area11 = App::get(AreeRepository::class)->perId(11);
        $area12 = App::get(AreeRepository::class)->perId(12);
        $this->assertNotNull($area11);
        foreach (['gestore_area', 'attivita', 'perimetro_eventi', 'perimetro_progetti'] as $profilo) {
            foreach ([$area11, $area12, null] as $sbagliata) {
                $p = $fab->per($this->utenti[$profilo], $sbagliata, []);
                foreach (self::AREA as $nome) {
                    $this->assertFalse($p->elenco()[$nome], "$profilo con un'altra area: $nome");
                }
                $this->assertSame([], $p->attivitaPermesse());
            }
        }
        // L'amministratore, invece, lavora in qualunque area
        $this->assertSame(self::AREA, array_keys(array_filter(array_intersect_key($fab->per($this->utenti['admin'], $area12, [])->elenco(), array_flip(self::AREA)))));
        // E il gestore dell'area 10 la gestisce solo se è quella aperta
        $area10 = App::get(AreeRepository::class)->perId(10);
        $this->assertTrue($fab->per($this->utenti['gestore_area'], $area10, [])->gestoreArea());
    }

    public function testIlRuoloUnoFraISecondariNonBastaSeManca(): void
    {
        $this->assertFalse(Permessi::eAmministratoreDa($this->utenti['ruolo_fra_i_secondari_vuoto']));
        $this->assertTrue(Permessi::eAmministratoreDa($this->utenti['admin_secondario']));
        $this->assertTrue(Permessi::eAmministratoreDa($this->utenti['admin']));
        $this->assertFalse(Permessi::eAmministratoreDa(null), 'utente cancellato con la sessione ancora aperta');
    }

    /** I due che costano una query restano a richiesta: dichiarati() non li calcola finché non servono. */
    public function testDichiaratiNonCalcolaIDuePermessiACostoDiUnaQuery(): void
    {
        $p = $this->contesto($this->utenti['docente'])['permessi'];
        $d = $p->dichiarati();
        $this->assertSame(Permessi::chiavi(), array_keys($d));
        $this->assertInstanceOf(\Closure::class, $d['puo_orientamento']);
        $this->assertInstanceOf(\Closure::class, $d['ha_caselle']);
        $this->assertFalse($d['puo_orientamento']());
        foreach (array_diff(Permessi::chiavi(), ['puo_orientamento', 'ha_caselle']) as $nome) {
            $this->assertIsBool($d[$nome], $nome);
        }
        $this->assertSame($p->elenco(), array_map(static fn ($v): bool => $v instanceof \Closure ? $v() : $v, $d));
    }

    /** Ogni risultato si calcola una volta sola: la seconda lettura non interroga il database. */
    public function testOgniRisultatoSiCalcolaUnaVoltaSola(): void
    {
        $c = $this->contesto($this->utenti['gestore_area']);
        $p = $c['permessi'];
        $p->elenco();
        $this->db->esegui('DELETE FROM abilitazioni_ambito');
        $this->db->esegui('UPDATE pagine_eventi SET gestore_utente_id = 0');
        $this->assertTrue($p->gestoreArea(), 'già calcolato: il database non si rilegge');
        $this->assertTrue($p->haCaselle());
    }

    // ───────────────────────── (2) Confronto con il vecchio calcolo ─────────────────────────

    /**
     * Il calcolo di admin/admin_header.php com'era prima di Permessi (righe 42-44, 120-175 e 213-222, copiate), con le stesse facciate.
     *
     * @param array<string, mixed>             $utente_admin
     * @param array<string, mixed>|null        $page_cfg
     * @param list<array<string, mixed>>       $pagine_disponibili
     * @return array<string, bool|array>
     */
    private function vecchioCalcolo(array $utente_admin, ?array $page_cfg, int $filtro_p, array $pagine_disponibili): array
    {
        $conn = self::$conn;
        $u_ruolo_curr = (int) $utente_admin['ruolo_id'];
        $u_sec_roles = array_filter(explode(',', $utente_admin['ruoli_secondari'] ?? ''));
        $is_full_admin = ($u_ruolo_curr === 1) || in_array('1', $u_sec_roles);
        $uid_rbac = (int) $utente_admin['id'];

        $puo_fsl             = $is_full_admin || ha_modulo($conn, $uid_rbac, 'fsl');
        $puo_fsl_convenzioni = $puo_fsl || ha_ambito($conn, $uid_rbac, 'fsl_convenzioni') || \App\Core\App::per($conn)->get(\App\Uffici\ServizioUffici::class)->utenteMembro($utente_admin, 'convenzioni_fsl');
        $puo_fsl_scuole      = $puo_fsl || ha_ambito($conn, $uid_rbac, 'fsl_scuole');
        $puo_didattica_tutto = $is_full_admin || ha_modulo($conn, $uid_rbac, 'didattica') || utente_operatore_ufficio($conn, $utente_admin);
        $consigli_referente  = $puo_didattica_tutto ? [] : consigli_referente($conn, $utente_admin);
        $puo_didattica       = $puo_didattica_tutto || (bool) $consigli_referente;
        $puo_conv_tesi       = $puo_didattica_tutto || \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class)->utenteDellUfficio($utente_admin);
        $puo_tir_motorie     = $puo_didattica_tutto || \App\Core\App::per($conn)->get(\App\Uffici\ServizioUffici::class)->utenteMembro($utente_admin, 'tirocini_motorie');
        $puo_tutorato        = $is_full_admin || ha_modulo($conn, $uid_rbac, 'didattica') || utente_operatore_ufficio($conn, $utente_admin, 'bandi');
        $puo_forms           = $is_full_admin || ha_modulo($conn, $uid_rbac, 'forms');
        $puo_forms_cogestore = !$puo_forms && \App\Core\App::per($conn)->get(\App\Forms\FormRepository::class)->haGestiti($uid_rbac);
        $puo_forms_vedere    = $puo_forms || $puo_forms_cogestore;
        $perm_amm            = \App\Core\App::per($conn)->get(\App\Amministrazione\PermessiAmministrazione::class);
        $puo_amministrazione = $perm_amm->puoTutto($utente_admin);
        $puo_pubblicazioni   = $perm_amm->puoPubblicazioni($utente_admin);
        $puo_amm_entrare     = $perm_amm->puoEntrare($utente_admin);
        $puo_assistenza      = \App\Core\App::per($conn)->get(\App\Assistenza\OperatoriAssistenza::class)->eOperatore($utente_admin);

        $can_manage_eventi = $can_manage_iscritti = $can_manage_sondaggi = $can_manage_form = $can_manage_settings = $is_full_admin;
        $is_area_manager = $is_full_admin;
        $puo_creare_eventi = $puo_creare_progetti = $is_full_admin;
        $allowed_events_ids = [];

        if (!$is_full_admin && $page_cfg) {
            if (in_array($uid_rbac, ids_gestori_da_campi($page_cfg['gestore_utente_id'], $page_cfg['gestori_utenti_ids'], $page_cfg['permessi_gestori_json']), true)
                || area_nel_modulo_utente($conn, $uid_rbac, $page_cfg)) {
                $is_area_manager = true;
                $can_manage_eventi = $can_manage_iscritti = $can_manage_sondaggi = $can_manage_form = $can_manage_settings = true;
                $puo_creare_eventi = $puo_creare_progetti = true;
            } else {
                foreach (\App\Core\App::get(\App\Auth\Abilitazioni\AbilitazioniRepository::class)->gestoriAttivitaAreaConTitolo($filtro_p) as $ev_row) {
                    if (in_array($uid_rbac, ids_gestori_da_campi(0, $ev_row['gestori_utenti_ids'], $ev_row['permessi_gestori_json']), true)) {
                        $allowed_events_ids[] = (int) $ev_row['id'];
                    }
                }
                $allowed_events_ids = array_values(array_unique(array_merge($allowed_events_ids, attivita_da_ambiti($conn, $uid_rbac, $filtro_p))));
                $puo_creare_progetti = ha_ambito($conn, $uid_rbac, 'progetti', $filtro_p);
                $puo_creare_eventi   = ha_ambito($conn, $uid_rbac, 'eventi', $filtro_p);
                if ($allowed_events_ids || $puo_creare_progetti || $puo_creare_eventi) {
                    $can_manage_eventi = $can_manage_iscritti = $can_manage_sondaggi = $can_manage_form = true;
                }
            }
        }

        $perm_menu = [
            'is_full_admin' => (bool) $is_full_admin,
            'puo_fsl' => (bool) $puo_fsl, 'puo_fsl_convenzioni' => (bool) $puo_fsl_convenzioni, 'puo_fsl_scuole' => (bool) $puo_fsl_scuole,
            'puo_didattica' => (bool) $puo_didattica, 'puo_didattica_tutto' => (bool) $puo_didattica_tutto, 'puo_conv_tesi' => (bool) $puo_conv_tesi,
            'puo_tir_motorie' => (bool) $puo_tir_motorie, 'puo_tutorato' => (bool) $puo_tutorato, 'puo_assistenza' => (bool) $puo_assistenza,
            'puo_forms_vedere' => (bool) $puo_forms_vedere, 'puo_amm_entrare' => (bool) $puo_amm_entrare,
            'puo_amministrazione' => (bool) $puo_amministrazione, 'puo_pubblicazioni' => (bool) $puo_pubblicazioni,
            'puo_orientamento' => fn () => $is_full_admin || ha_modulo($conn, $uid_rbac, 'orientamento'),
        ];
        $moduli_utente = array_flip(\App\Portale\Pannello\MenuModuli::moduliVisibili($perm_menu, array_values(array_unique(array_map('modulo_di_area', $pagine_disponibili)))));
        $moduli_utente = array_values(array_filter(array_keys(MODULI_PORTALE), fn ($k) => isset($moduli_utente[$k])));

        return [
            'is_full_admin' => (bool) $is_full_admin, 'is_area_manager' => (bool) $is_area_manager,
            'can_manage_eventi' => (bool) $can_manage_eventi, 'can_manage_iscritti' => (bool) $can_manage_iscritti, 'can_manage_sondaggi' => (bool) $can_manage_sondaggi,
            'can_manage_form' => (bool) $can_manage_form, 'can_manage_settings' => (bool) $can_manage_settings,
            'puo_creare_eventi' => (bool) $puo_creare_eventi, 'puo_creare_progetti' => (bool) $puo_creare_progetti,
            'puo_fsl' => (bool) $puo_fsl, 'puo_fsl_convenzioni' => (bool) $puo_fsl_convenzioni, 'puo_fsl_scuole' => (bool) $puo_fsl_scuole,
            'puo_didattica' => (bool) $puo_didattica, 'puo_didattica_tutto' => (bool) $puo_didattica_tutto, 'puo_conv_tesi' => (bool) $puo_conv_tesi,
            'puo_tir_motorie' => (bool) $puo_tir_motorie, 'puo_tutorato' => (bool) $puo_tutorato,
            'puo_forms' => (bool) $puo_forms, 'puo_forms_cogestore' => (bool) $puo_forms_cogestore, 'puo_forms_vedere' => (bool) $puo_forms_vedere,
            'puo_amministrazione' => (bool) $puo_amministrazione, 'puo_pubblicazioni' => (bool) $puo_pubblicazioni, 'puo_amm_entrare' => (bool) $puo_amm_entrare,
            'puo_assistenza' => (bool) $puo_assistenza,
            // come in admin_header.php prima di Permessi
            'puo_orientamento' => (bool) ($is_full_admin || ha_modulo($conn, $uid_rbac, 'orientamento')),
            'ha_caselle' => (bool) \App\Core\App::get(\App\Iscritti\CasellaMessaggi::class)->aree($uid_rbac, (bool) $is_full_admin),
            'ha_sezioni' => (bool) $is_full_admin || !empty($moduli_utente) || !empty($pagine_disponibili),
        ];
    }

    /**
     * La rete di sicurezza: per ogni profilo (amministratore globale, gestore d'area, docente e tutti gli altri) e per ogni area che può aprire,
     * elenco() dà ESATTAMENTE gli stessi valori del vecchio calcolo di admin_header.php.
     *
     * @dataProvider profili
     */
    public function testElencoDaGliStessiValoriDelVecchioCalcolo(string $profilo): void
    {
        $u = $this->utenti[$profilo];
        $aree = $this->contesto($u)['aree'];
        $richieste = array_merge([0], array_map('intval', array_column($aree, 'id')), [12]);
        foreach (array_unique($richieste) as $r) {
            // contesto() e il vecchio calcolo partono dallo stesso container: la memoria delle abilitazioni non è un'altra fonte di differenze
            App::avvia(self::$conn);
            $c = $this->contesto($u, $r);
            App::avvia(self::$conn);
            $vecchio = $this->vecchioCalcolo($u, $c['area'], $c['area_id'], $c['aree']);
            $this->assertSame($vecchio, $c['permessi']->elenco(), "profilo «{$profilo}», area richiesta $r (aperta {$c['area_id']})");
        }
    }

    // ───────────────────────── (3) Il ruolo cambiato si vede subito ─────────────────────────

    public function testIlRuoloCambiatoNelDatabaseSiVedeSubitoSenzaNuovoAccesso(): void
    {
        // la sessione dice ancora «ruolo 1» (ruolo_id e ruoli_secondari come erano al momento dell'accesso)...
        $sessione = ['utente_id' => 1, 'utente_ruolo_id' => 1, 'utente_ruoli_secondari' => ''];
        $this->db->esegui('CREATE TABLE IF NOT EXISTS utenti (id INT PRIMARY KEY, ruolo_id INT, ruoli_secondari VARCHAR(50) DEFAULT \'\', email VARCHAR(150) DEFAULT \'\', persona_id VARCHAR(80) NULL)');
        $this->db->esegui('DELETE FROM utenti');
        $this->db->esegui("INSERT INTO utenti (id, ruolo_id, ruoli_secondari, email) VALUES (1, 1, '', 'u1@prova.it')");
        $repo = App::get(\App\Auth\UtenteRepository::class);
        $this->assertTrue(Permessi::eAmministratoreDa($repo->perId($sessione['utente_id'])));

        // ... ma il database ha tolto il ruolo: alla richiesta dopo non è più amministratore
        $this->db->esegui('UPDATE utenti SET ruolo_id = 3 WHERE id = 1');
        $riga = $repo->perId($sessione['utente_id']);
        $this->assertFalse(Permessi::eAmministratoreDa($riga), 'la sessione non conta: conta la riga del database');
        $p = App::get(PermessiFabbrica::class)->per($riga, null, []);
        $this->assertFalse($p->eAmministratore());
        $this->assertFalse($p->elenco()['is_full_admin']);

        // e viceversa: chi diventa amministratore (anche come ruolo secondario) lo è subito
        $this->db->esegui("UPDATE utenti SET ruoli_secondari = '3,1' WHERE id = 1");
        $this->assertTrue(Permessi::eAmministratoreDa($repo->perId(1)));

        // un utente cancellato con la sessione ancora aperta non è amministratore
        $this->db->esegui('DELETE FROM utenti WHERE id = 1');
        $this->assertFalse(Permessi::eAmministratoreDa($repo->perId(1)));
        $this->db->esegui('DROP TABLE utenti');
    }
}
