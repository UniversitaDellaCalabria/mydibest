<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Auth\Sessione;
use App\Http\Richiesta;
use App\Http\Vista;
use App\Portale\Pagine\PaginaScuoleFsl;
use Tests\Doppi\AuthSessioneInMemoria;
use Tests\Integration\Fsl\FslBase;


require_once RADICE_SITO . '/inc/base.php';   // ids_gestori_da_campi(), che i permessi chiedono per sapere chi gestisce un'area

/**
 * Base delle prove della pagina «Scuole e convenzioni» (App\Portale\Pagine\PaginaScuoleFsl) senza browser, con Richiesta::finta().
 * I modelli sono quelli VERI (viste/pannello/…), copiati in una radice temporanea con testate finte. Data di prova: 5 ottobre 2026.
 * Utenti: 1 = amministratore (vede tutto), 2 = ha SOLO le convenzioni FSL. Istituti: CSIS00001A Galilei, CSIS00002B Fermi.
 */
abstract class PaginaScuoleFslBase extends FslBase
{
    protected AuthSessioneInMemoria $sessione;
    private string $cartella = '';
    private ?string $radicePrima = null;
    /** @var array<string, array{0: string, 1: string}> */
    private array $layoutPrima = [];

    protected function tabelle(): array
    {
        return [
            ...parent::tabelle(),
            'convenzioni_messaggi' => "CREATE TABLE convenzioni_messaggi (id INT AUTO_INCREMENT PRIMARY KEY, compilazione_id INT NOT NULL, da VARCHAR(10) NOT NULL, tipo VARCHAR(20) NOT NULL DEFAULT 'messaggio',
                testo VARCHAR(2000) NOT NULL DEFAULT '', autore VARCHAR(150) NOT NULL DEFAULT '', creato_il DATETIME DEFAULT CURRENT_TIMESTAMP, letto_il DATETIME NULL)",
            'scuole_docenti' => self::tabellaVera('scuole_docenti'),
            'log_email' => self::tabellaVera('log_email'),
            'log_audit' => "CREATE TABLE log_audit (id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT NULL, azione VARCHAR(255) NOT NULL DEFAULT '', dettagli TEXT NULL, ip VARCHAR(64) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'utenti' => ['nome' => 'VARCHAR(100) NULL', 'cognome' => 'VARCHAR(100) NULL', 'ruoli_secondari' => "VARCHAR(255) NOT NULL DEFAULT ''", 'persona_id' => 'VARCHAR(80) NULL'],
            'pagine_eventi' => ['tipo_area' => "VARCHAR(20) NOT NULL DEFAULT ''", 'gestore_utente_id' => 'INT DEFAULT 0', 'gestori_utenti_ids' => "VARCHAR(255) DEFAULT ''", 'permessi_gestori_json' => 'TEXT NULL', 'ordine' => 'INT DEFAULT 0'],
            'eventi' => ['ordine' => 'INT DEFAULT 0', 'gestori_utenti_ids' => "VARCHAR(255) DEFAULT ''", 'permessi_gestori_json' => 'TEXT NULL'],
            'partecipanti_prenotazione' => ['escluso' => 'TINYINT NOT NULL DEFAULT 0'],
        ] as $tabella => $colonne) {
            foreach ($colonne as $colonna => $definizione) {
                if ($this->db->righe("SHOW COLUMNS FROM `$tabella` LIKE '$colonna'") === []) {
                    $this->db->comando("ALTER TABLE `$tabella` ADD COLUMN `$colonna` $definizione");
                }
            }
        }
        $this->db->esegui("INSERT INTO eventi (id, pagina_id, titolo, tipo) VALUES (30, 2, 'Open day', 'evento')");
        $this->db->esegui('INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati) VALUES (30, 0, 0, 0)');
        $this->db->esegui("INSERT INTO turni (id, evento_id, nome_turno, data_turno) VALUES (300, 30, 'Turno', '2026-11-10')");
        // gli istituti hanno la loro riga nell'anagrafe (come nei dati del Ministero): senza, non si può avviare la convenzione
        $this->db->esegui("INSERT INTO scuole (codice, denominazione, istituto_codice, istituto_denominazione, comune, provincia, indirizzo, cap) VALUES
            ('CSIS00001A', 'ISTITUTO GALILEI', 'CSIS00001A', 'ISTITUTO GALILEI', 'COSENZA', 'CS', 'VIA ROMA 1', '87100'),
            ('CSIS00002B', 'ISTITUTO FERMI', 'CSIS00002B', 'ISTITUTO FERMI', 'RENDE', 'CS', 'VIA DANTE 2', '87036')");
        // 1 = amministratore (vede tutto), 2 = ha SOLO le convenzioni FSL
        $this->db->esegui("INSERT INTO utenti (id, email, ruolo_id, nome, cognome, persona_id) VALUES (1, 'admin@prova.it', 1, 'Anna', 'Admin', 'P1'), (2, 'conv@prova.it', 3, 'Carlo', 'Conv', 'P2')");
        $this->db->esegui("INSERT INTO abilitazioni_ambito (utente_id, tipo, pagina_id) VALUES (2, 'fsl_convenzioni', 0)");

        // il contenitore del portale: tutti i moduli registrati (la pagina usa permessi, scuole, FSL, email e registro delle operazioni)
        foreach (glob(RADICE_SITO . '/src/*/Registrazione.php') ?: [] as $f) {
            $classe = 'App\\' . basename(dirname($f)) . '\\Registrazione';
            if (!in_array($classe, ['App\\Iscrizioni\\Registrazione', 'App\\Fsl\\Registrazione'], true)) {
                $classe::registra($this->c);
            }
        }
        $this->sessione = new AuthSessioneInMemoria();
        $this->c->istanza(Sessione::class, $this->sessione);
        $this->c->istanza(\App\Core\IndirizzoClient::class, new \Tests\Doppi\ClientFinto());
        $this->entra(1);

        $this->cartella = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pagina_scuole_fsl_' . bin2hex(random_bytes(4));
        mkdir($this->cartella . '/viste/pannello', 0777, true);
        foreach (array_merge(glob(RADICE_SITO . '/viste/pannello/*fsl_scuole_*.php') ?: [], glob(RADICE_SITO . '/viste/pannello/fsl_numeri.php') ?: []) as $f) {
            copy($f, $this->cartella . '/viste/pannello/' . basename($f));   // i modelli VERI
        }
        file_put_contents($this->cartella . '/header.php', '<?php echo "<html><title>" . htmlspecialchars($page_cfg[\'titolo\'] ?? \'\') . "</title><body>"; ?>');
        file_put_contents($this->cartella . '/footer.php', '</body></html>');
        $this->radicePrima = Vista::usaRadice($this->cartella);
        $this->layoutPrima = Vista::layout(['sito' => ['header.php', 'footer.php'], 'pannello' => ['header.php', 'footer.php']]);
    }

    protected function tearDown(): void
    {
        Vista::usaRadice($this->radicePrima);
        Vista::layout($this->layoutPrima ?: ['sito' => ['header.php', 'footer.php'], 'pannello' => ['header.php', 'footer.php']]);
        foreach (glob($this->cartella . '/viste/pannello/*.php') ?: [] as $f) {
            @unlink($f);
        }
        foreach (['header.php', 'footer.php'] as $f) {
            @unlink($this->cartella . '/' . $f);
        }
        @rmdir($this->cartella . '/viste/pannello');
        @rmdir($this->cartella . '/viste');
        @rmdir($this->cartella);
        parent::tearDown();
    }

    protected function entra(int $utente): void
    {
        $u = $this->db->riga('SELECT email, nome, cognome FROM utenti WHERE id = ?', [$utente]);
        $this->sessione->dati = ['utente_id' => $utente, 'utente_email' => $u['email'], 'utente_nome' => trim($u['nome'] . ' ' . $u['cognome'])];
        // il contesto ricorda chi era entrato: se ne costruisce uno nuovo
        $this->c->istanza(\App\Portale\Pannello\ContestoPannello::class, $this->nuovoContesto());
    }

    protected function nuovoContesto(): \App\Portale\Pannello\ContestoPannello
    {
        return new \App\Portale\Pannello\ContestoPannello(
            $this->sessione,
            $this->c->get(\App\Auth\UtenteRepository::class),
            $this->c->get(\App\Auth\Abilitazioni\ServizioAbilitazioni::class),
            $this->c->get(\App\Portale\Area\AreeGestite::class),
            $this->c->get(\App\Portale\AreeRepository::class),
            $this->c->get(\App\Auth\PermessiFabbrica::class),
        );
    }

    protected function pagina(): PaginaScuoleFsl
    {
        return new PaginaScuoleFsl(
            $this->servizio(\App\Fsl\ElencoScuoleFsl::class),
            $this->servizio(\App\Fsl\SchedaScuolaFsl::class),
            $this->servizio(\App\Fsl\ServizioConvenzioneOnline::class),
            $this->servizio(\App\Portale\Pagine\AzioniScuolaFsl::class),
            $this->servizio(\App\Fsl\PdfSchedaScuola::class),
            $this->servizio(\App\Portale\Pannello\ContestoPannello::class),
            $this->servizio(\App\Auth\Csrf::class),
            $this->servizio(\App\Auth\Flash::class),
            $this->sessione,
            $this->servizio(\App\Infrastructure\Audit\AuditLog::class),
            $this->servizio(\App\Core\IndirizzoClient::class),
            $this->servizio(\App\Core\Orologio::class),
            $this->servizio(\App\Anagrafi\ServizioScuole::class),
            $this->servizio(\App\Portale\Pagine\RimandiFsl::class),
        );
    }

    /** @param array<string, string> $query */
    protected function apri(array $query = []): \App\Http\Risposta
    {
        return ($this->pagina())(Richiesta::finta('GET', '/admin/fsl_scuole.php', $query));
    }

    /** @param array<string, string> $post @param array<string, string> $query */
    protected function invia(array $post, array $query = []): \App\Http\Risposta
    {
        return ($this->pagina())(Richiesta::finta('POST', '/admin/fsl_scuole.php', $query, $post));
    }

    protected function compilazioni(string $istituto = 'CSIS00001A'): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) FROM convenzioni_compilate WHERE scuola_codice IN (SELECT codice FROM scuole WHERE codice = ? OR istituto_codice = ?)", [$istituto, $istituto]);
    }
}
