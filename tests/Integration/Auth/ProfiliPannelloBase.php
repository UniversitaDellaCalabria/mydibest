<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\Auth\PermessiFabbrica;
use App\Auth\UtenteRepository;
use App\Core\App;
use App\Core\Schema\Definizioni;
use App\Portale\Area\AreeGestite;
use App\Portale\AreeRepository;
use App\Portale\Pannello\ContestoPannello;
use Tests\Doppi\AuthSessioneInMemoria;
use Tests\Integration\DatabaseDiProva;

// Le facciate di sempre (ha_modulo, utente_operatore_ufficio…) e le costanti dei moduli: servono alle prove che confrontano con il vecchio calcolo di admin_header.php
require_once RADICE_SITO . '/inc/base.php';
require_once RADICE_SITO . '/inc/sezioni.php';
require_once RADICE_SITO . '/inc/didattica.php';

/**
 * I 23 profili di prova del pannello (amministratore, gestore d'area, docente, uffici, moduli…) con le loro aree, attività e abilitazioni.
 * Li usano PermessiTest (chi ha quali permessi) e ContestoPannelloTest (la testata e ContestoPannello danno la stessa fotografia): la stessa
 * semina, così le due prove parlano delle stesse persone.
 *
 * Aree di prova: 10 «Eventi» (macroarea orientamento), 11 «FSL» (macroarea fsl), 12 «Eventi» senza gestori.
 * Ogni profilo è anche una riga della tabella `utenti` (la testata e ContestoPannello leggono l'utente dal database, non dalla sessione).
 */
abstract class ProfiliPannelloBase extends DatabaseDiProva
{
    /** Tabelle dello schema vero (con le colonne aggiunte negli anni). */
    protected const REALI = ['uffici', 'uffici_persone', 'didattica_consigli', 'didattica_consigli_persone', 'abilitazioni_ambito', 'forms', 'forms_gestori', 'progetti_dettagli'];

    /** @var array<string, array<string, mixed>> profilo => riga di utenti */
    protected array $utenti = [];

    public static function tearDownAfterClass(): void
    {
        // il container con le memorie di questa classe non deve passare ai test che seguono
        if (self::$conn !== null) {
            App::avvia(self::$conn);
        }
    }

    protected function tabelle(): array
    {
        return [
            'pagine_eventi' => "CREATE TABLE pagine_eventi (id INT AUTO_INCREMENT PRIMARY KEY, titolo VARCHAR(150) DEFAULT '', slug VARCHAR(80) DEFAULT '', ordine INT DEFAULT 0, tipo_area VARCHAR(20) DEFAULT '',
                gestore_utente_id INT DEFAULT 0, gestori_utenti_ids VARCHAR(255) DEFAULT '', permessi_gestori_json TEXT NULL)",
            'eventi' => "CREATE TABLE eventi (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT, titolo VARCHAR(150) DEFAULT '', tipo VARCHAR(20) DEFAULT 'evento', archiviato TINYINT DEFAULT 0, ordine INT DEFAULT 0,
                gestori_utenti_ids VARCHAR(255) DEFAULT '', permessi_gestori_json TEXT NULL)",
            'utenti' => "CREATE TABLE utenti (id INT PRIMARY KEY, nome VARCHAR(80) DEFAULT '', cognome VARCHAR(80) DEFAULT '', email VARCHAR(150) DEFAULT '', ruolo_id INT DEFAULT 5,
                ruoli_secondari VARCHAR(255) DEFAULT '', persona_id VARCHAR(80) DEFAULT NULL)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $defs = Definizioni::tabelle();
        $cols = Definizioni::colonne();
        foreach (self::REALI as $t) {
            self::$conn->query("DROP TABLE IF EXISTS `$t`");
            self::$conn->query($defs[$t]);
            foreach ($cols[$t] ?? [] as $spec) {
                self::$conn->query("ALTER TABLE `$t` " . preg_replace('/\s+(AFTER|FIRST)\b(\s+`?\w+`?)?/i', '', is_array($spec) ? $spec[0] : $spec));
            }
        }
        App::avvia(self::$conn); // container nuovo: nessuna memoria di un test precedente
        $this->semina();
    }

    protected function utente(string $profilo, int $id, int $ruolo = 3, string $secondari = ''): array
    {
        $this->db->esegui('INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari, persona_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, "Nome$id", "Cognome$id", "u$id@prova.it", $ruolo, $secondari, "P$id"]);

        return $this->utenti[$profilo] = ['id' => $id, 'email' => "u$id@prova.it", 'persona_id' => "P$id", 'ruolo_id' => $ruolo, 'ruoli_secondari' => $secondari];
    }

    /** Un ContestoPannello costruito a mano su una sessione in memoria (la sessione dice chi è collegato: `utente_id`). */
    protected function contestoPannello(AuthSessioneInMemoria $sessione): ContestoPannello
    {
        return new ContestoPannello(
            $sessione,
            App::get(UtenteRepository::class),
            App::get(ServizioAbilitazioni::class),
            App::get(AreeGestite::class),
            App::get(AreeRepository::class),
            App::get(PermessiFabbrica::class),
        );
    }

    protected function ambito(int $utente, string $tipo, int $pagina = 0): void
    {
        $this->db->esegui('INSERT INTO abilitazioni_ambito (utente_id, tipo, pagina_id) VALUES (?, ?, ?)', [$utente, $tipo, $pagina]);
    }

    protected function inUfficio(string $chiave, int $utente, ?string $compiti = null, ?int $op = null): void
    {
        $uff = $this->db->riga('SELECT id FROM uffici WHERE chiave = ?', [$chiave]);
        $id = $uff ? (int) $uff['id'] : $this->db->inserisci('INSERT INTO uffici (nome, chiave) VALUES (?, ?)', [$chiave, $chiave]);
        $this->db->esegui('INSERT INTO uffici_persone (ufficio_id, email, nominativo, persona_id, compiti, op_id) VALUES (?, ?, ?, ?, ?, ?)', [$id, "u$utente@prova.it", "Persona $utente", "P$utente", (string) $compiti, $op]);
    }

    protected function semina(): void
    {
        $this->utenti = [];
        // Aree: 10 (eventi, gestita da 3), 11 (FSL), 12 (eventi, nessun gestore)
        $this->db->esegui("INSERT INTO pagine_eventi (id, titolo, slug, ordine, tipo_area, gestore_utente_id) VALUES (10, 'Eventi', 'eventi', 1, 'eventi', 3), (11, 'FSL', 'fsl', 2, 'fsl', 0), (12, 'Altra', 'altra', 3, 'eventi', 0)");
        // Attività dell'area 10: 100 e 101 eventi, 102 progetto; la 100 è assegnata a 5 una per una
        $this->db->esegui("INSERT INTO eventi (id, pagina_id, titolo, tipo, permessi_gestori_json) VALUES (100, 10, 'Evento A', 'evento', '{\"5\":{}}'), (101, 10, 'Evento B', 'evento', NULL), (102, 10, 'Progetto', 'progetto', NULL)");

        $this->utente('admin', 1, 1);
        $this->utente('admin_secondario', 2, 3, '3,1');
        $this->utente('gestore_area', 3, 2);
        $this->utente('docente', 4);
        $this->utente('attivita', 5);
        $this->utente('perimetro_eventi', 6);
        $this->ambito(6, 'eventi', 10);
        $this->utente('perimetro_progetti', 7);
        $this->ambito(7, 'progetti', 10);
        $this->utente('modulo_orientamento', 8);
        $this->ambito(8, 'modulo_orientamento');
        $this->utente('fsl_convenzioni', 9);
        $this->ambito(9, 'fsl_convenzioni');
        $this->utente('fsl_scuole', 10);
        $this->ambito(10, 'fsl_scuole');
        $this->utente('modulo_didattica', 11);
        $this->ambito(11, 'modulo_didattica');
        $this->utente('operatore_pratiche', 12);
        $this->inUfficio('didattica', 12, 'pratiche', 1);
        $this->utente('operatore_bandi', 13);
        $this->inUfficio('didattica', 13, 'bandi', 2);
        $this->utente('referente_consiglio', 14);
        $cid = $this->db->inserisci("INSERT INTO didattica_consigli (nome) VALUES ('Consiglio di prova')");
        $this->db->esegui("INSERT INTO didattica_consigli_persone (consiglio_id, ruolo, email, nominativo) VALUES (?, 'referente', 'u14@prova.it', 'Referente')", [$cid]);
        $this->utente('ufficio_convenzioni_tesi', 15);
        $this->inUfficio('convenzioni_tesi', 15);
        $this->utente('ufficio_tirocini_motorie', 16);
        $this->inUfficio('tirocini_motorie', 16);
        $this->utente('ufficio_convenzioni_fsl', 17);
        $this->inUfficio('convenzioni_fsl', 17);
        $this->utente('ufficio_assistenza', 18);
        $this->db->esegui("INSERT INTO uffici (nome, chiave, attivo) VALUES ('Assistenza', 'assistenza', 1)");
        $this->db->esegui("INSERT INTO uffici_persone (ufficio_id, email, nominativo) VALUES (?, 'u18@prova.it', 'Assistente')", [(int) self::$conn->insert_id]);
        $this->utente('modulo_forms', 19);
        $this->ambito(19, 'modulo_forms');
        $this->utente('cogestore_form', 20);
        $this->db->esegui('INSERT INTO forms_gestori (form_id, utente_id) VALUES (1, 20)');
        $this->utente('modulo_amministrazione', 21);
        $this->ambito(21, 'modulo_amministrazione');
        $this->utente('solo_pubblicazioni', 22);
        $this->ambito(22, 'modulo_pubblicazioni');
        // Una persona con il ruolo sbagliato nei dati: «ruolo 1» scritto solo in un'area non la rende amministratore
        $this->utente('ruolo_fra_i_secondari_vuoto', 23, 3, ',');
    }
}
