<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Auth\EmailNonVerificata;
use App\Auth\UtenteRepository;
use App\Core\Schema\Definizioni;
use Tests\Integration\DatabaseDiProva;

/**
 * Chi ha un'email scelta a mano e mai confermata (T3: l'email non verificata non dà compiti né permessi) non deve restare fuori senza saperlo:
 *  - l'accesso con Ateneo/SPID/CIE conferma l'email se è la stessa (V1b);
 *  - un avviso dice che cosa si perde (V1c) e lo strumento --chi-perde elenca chi perderebbe qualcosa (V1e), in sola lettura.
 */
final class EmailNonVerificataTest extends DatabaseDiProva
{
    private const REALI = ['uffici', 'uffici_persone', 'didattica_consigli_persone', 'pratiche_conferme', 'pratiche_firme', 'risorse_responsabili', 'tutorato_incarichi', 'convenzioni_compilate', 'convenzioni_tesi'];

    protected function tabelle(): array
    {
        return [
            'utenti' => "CREATE TABLE utenti (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(80) DEFAULT '', cognome VARCHAR(80) DEFAULT '', email VARCHAR(150) NULL, ruolo_id INT DEFAULT 5,
                ruoli_secondari VARCHAR(100) DEFAULT '', email_personalizzata TINYINT DEFAULT 0, email_verificata_il DATETIME NULL, persona_id VARCHAR(80) NULL, ultimo_accesso DATETIME NULL)",
            'turni' => 'CREATE TABLE turni (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT DEFAULT 1, data_turno DATE NULL)',
            'prenotazioni' => "CREATE TABLE prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, turno_id INT, utente_id INT NULL, email VARCHAR(150) NULL, stato VARCHAR(30) DEFAULT 'confermata')",
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
    }

    private function utente(int $id, ?string $email, int $personalizzata, ?string $verificata, string $cognome = 'Rossi'): void
    {
        $this->db->esegui('INSERT INTO utenti (id, nome, cognome, email, email_personalizzata, email_verificata_il) VALUES (?, ?, ?, ?, ?, ?)', [$id, 'Mario', $cognome, $email, $personalizzata, $verificata]);
    }

    private function riga(int $id): array
    {
        return (array) $this->db->riga('SELECT * FROM utenti WHERE id = ?', [$id]);
    }

    // ───────────── V1b: l'accesso con l'identità di Ateneo/SPID/CIE conferma l'email se è la stessa ─────────────

    public function testLAccessoConLaStessaEmailLaSegnaVerificata(): void
    {
        $this->utente(1, 'prof@unical.it', 1, null);
        (new UtenteRepository($this->db))->aggiornaAccessoSso(1, 'prof@unical.it', 'Mario', 'Rossi');
        $u = $this->riga(1);
        self::assertNotNull($u['email_verificata_il'], 'stessa email dell\'identità: ora è verificata');
        self::assertSame('prof@unical.it', UtenteRepository::emailVerificata($u));
        self::assertSame(1, (int) $u['email_personalizzata'], 'resta personalizzata: l\'accesso non la sovrascrive');
    }

    public function testIlConfrontoNonDistingueMaiuscoleESpazi(): void
    {
        $this->utente(1, ' Prof@Unical.it ', 1, null);
        (new UtenteRepository($this->db))->aggiornaAccessoSso(1, 'prof@unical.it', 'Mario', 'Rossi');
        self::assertNotNull($this->riga(1)['email_verificata_il']);
    }

    public function testConUnaEmailDiversaNonSiTocca(): void
    {
        $this->utente(1, 'mia@gmail.com', 1, null);
        (new UtenteRepository($this->db))->aggiornaAccessoSso(1, 'prof@unical.it', 'Mario', 'Rossi');
        $u = $this->riga(1);
        self::assertNull($u['email_verificata_il'], 'un\'email diversa da quella dell\'identità non si verifica');
        self::assertSame('mia@gmail.com', $u['email'], 'e non viene sovrascritta');
    }

    public function testConLEmailDellIdentitaVuotaNonSiTocca(): void
    {
        $this->utente(1, 'prof@unical.it', 1, null);
        (new UtenteRepository($this->db))->aggiornaAccessoSso(1, '', 'Mario', 'Rossi');
        self::assertNull($this->riga(1)['email_verificata_il'], 'mai se l\'identità non dà un\'email');
        $this->utente(2, '', 1, null);
        (new UtenteRepository($this->db))->aggiornaAccessoSso(2, '', 'Mario', 'Rossi');
        self::assertNull($this->riga(2)['email_verificata_il'], 'nemmeno due vuote che si somigliano');
    }

    public function testUnaVerificaGiaFattaNonCambiaEUnaEmailNonPersonalizzataSiAggiornaCOmeSempre(): void
    {
        $this->utente(1, 'mia@gmail.com', 1, '2026-10-01 09:00:00');
        (new UtenteRepository($this->db))->aggiornaAccessoSso(1, 'prof@unical.it', 'Mario', 'Rossi');
        $u = $this->riga(1);
        self::assertSame('2026-10-01 09:00:00', $u['email_verificata_il'], 'la verifica di prima resta com\'è');
        self::assertSame('mia@gmail.com', $u['email']);

        $this->utente(2, 'vecchia@unical.it', 0, null);
        (new UtenteRepository($this->db))->aggiornaAccessoSso(2, 'nuova@unical.it', 'Mario', 'Rossi');
        $u2 = $this->riga(2);
        self::assertSame('nuova@unical.it', $u2['email'], 'non personalizzata: prende l\'email dell\'identità, come prima');
        self::assertNull($u2['email_verificata_il'], 'e non ha bisogno di verifica');
        self::assertSame('nuova@unical.it', UtenteRepository::emailVerificata($u2));
    }

    // ───────────── V1c: che cosa non vede chi ha un'email non verificata ─────────────

    public function testIndirizzoDaConfermare(): void
    {
        self::assertSame('mia@gmail.com', EmailNonVerificata::indirizzo(['email' => 'Mia@Gmail.com', 'email_personalizzata' => 1, 'email_verificata_il' => null]));
        self::assertNull(EmailNonVerificata::indirizzo(['email' => 'mia@gmail.com', 'email_personalizzata' => 1, 'email_verificata_il' => '2026-10-01 09:00:00']), 'confermata');
        self::assertNull(EmailNonVerificata::indirizzo(['email' => 'prof@unical.it', 'email_personalizzata' => 0, 'email_verificata_il' => null]), 'arrivata dal login');
        self::assertNull(EmailNonVerificata::indirizzo(['email' => '', 'email_personalizzata' => 1, 'email_verificata_il' => null]), 'senza email c\'è altro avviso');
        self::assertNull(EmailNonVerificata::indirizzo(null));
    }

    /** Una persona con l'email scelta a mano e non confermata, presente in ogni elenco che dà compiti o permessi per email. */
    private function personaPiena(int $id = 20, string $email = 'prof@unical.it'): void
    {
        $this->utente($id, $email, 1, null, 'Verdi');
        $dir = (int) $this->db->inserisci("INSERT INTO uffici (nome, chiave) VALUES ('Direzione', 'direzione')");
        $uff = (int) $this->db->inserisci("INSERT INTO uffici (nome, chiave) VALUES ('Segreteria didattica', 'segreteria_didattica')");
        $this->db->esegui("INSERT INTO uffici_persone (ufficio_id, email, nominativo, ruolo) VALUES (?, ?, 'Verdi Mario', 'Direttore'), (?, ?, 'Verdi Mario', 'Operatore')", [$dir, $email, $uff, $email]);
        $this->db->esegui("INSERT INTO didattica_consigli_persone (consiglio_id, ruolo, email, nominativo) VALUES (1, 'segretario', ?, 'Verdi Mario')", [$email]);
        $this->db->esegui("INSERT INTO pratiche_conferme (pratica_id, email, token, stato) VALUES (1, ?, 't1', 'attesa'), (2, ?, 't2', 'confermata')", [$email, $email]);
        $this->db->esegui("INSERT INTO pratiche_firme (pratica_id, email, token, stato) VALUES (1, ?, 'f1', 'attesa')", [$email]);
        $this->db->esegui("INSERT INTO risorse_responsabili (risorsa_id, persona_id, email) VALUES (1, 'P20', ?)", [$email]);
        $this->db->esegui("INSERT INTO tutorato_incarichi (bando_id, codice, stato, docente_email) VALUES (1, 'TUT1', 'inviata', ?), (1, 'TUT2', 'protocollata', ?)", [$email, $email]);
        $this->db->esegui("INSERT INTO convenzioni_compilate (token, scuola_codice, email, dati_json, stato_iter) VALUES ('c1', 'CSIS00001A', ?, '{\"scuola\":{\"nome\":\"Liceo\"}}', ''), ('c2', 'CSIS00002A', ?, '{\"x\":1}', 'protocollata')", [$email, $email]);
        $this->db->esegui("INSERT INTO convenzioni_tesi (token, stato, email) VALUES ('t1', 'compilata', ?), ('t2', 'protocollata', ?)", [$email, $email]);
        $this->db->esegui("INSERT INTO turni (id, data_turno) VALUES (1, '2999-01-01'), (2, '2000-01-01')");
        $this->db->esegui("INSERT INTO prenotazioni (turno_id, utente_id, email, stato) VALUES (1, NULL, ?, 'confermata'), (2, NULL, ?, 'confermata'), (1, ?, ?, 'confermata')", [$email, $email, $id, $email]);
    }

    public function testChePerdeChiHaLEmailNonConfermataInOgniElencoPerEmail(): void
    {
        $this->personaPiena();
        $perdite = (new EmailNonVerificata($this->db))->perdite($this->riga(20));
        $codici = array_column($perdite, 'codice');
        sort($codici);
        self::assertSame(['conferme', 'consigli', 'convenzioni_fsl', 'convenzioni_tesi', 'direzione', 'firme', 'prenotazioni', 'risorse', 'tutorato', 'uffici'], $codici);
        $per = array_column($perdite, 'quanti', 'codice');
        self::assertSame(1, $per['conferme'], 'solo le conferme ancora in attesa');
        self::assertSame(1, $per['tutorato'], 'solo gli incarichi non chiusi');
        self::assertSame(1, $per['convenzioni_fsl'], 'solo le convenzioni in corso, non quelle protocollate');
        self::assertSame(1, $per['convenzioni_tesi']);
        self::assertSame(1, $per['prenotazioni'], 'prenotazioni future trovate solo per email: non quella già legata all\'account né quella passata');
        self::assertSame(1, $per['direzione'], 'Direttore o vice');
        self::assertSame(1, $per['uffici'], 'gli altri uffici, non la Direzione');
        foreach ($perdite as $p) {
            self::assertNotSame('', $p['cosa'], "«{$p['codice']}» dice che cosa si perde");
        }
    }

    public function testChiHaLEmailVerificataOSenzaNienteNonPerdeNulla(): void
    {
        $this->personaPiena(20, 'prof@unical.it');
        $this->db->esegui("UPDATE utenti SET email_verificata_il = '2026-10-01 09:00:00' WHERE id = 20");
        $e = new EmailNonVerificata($this->db);
        self::assertSame([], $e->perdite($this->riga(20)), 'verificata: niente da perdere');
        $this->utente(21, 'nessuno@gmail.com', 1, null);
        self::assertSame([], $e->perdite($this->riga(21)), 'non verificata ma senza compiti: niente da perdere');
        $this->utente(22, 'sso@unical.it', 0, null);
        $this->db->esegui("INSERT INTO risorse_responsabili (risorsa_id, persona_id, email) VALUES (1, 'P22', 'sso@unical.it')");
        self::assertSame([], $e->perdite($this->riga(22)), 'email del login di Ateneo: vale già');
    }

    public function testLeChiaviDellEmailSiConfrontanoSenzaMaiuscole(): void
    {
        $this->utente(30, 'Prof@Unical.it', 1, null);
        $this->db->esegui("INSERT INTO risorse_responsabili (risorsa_id, persona_id, email) VALUES (1, 'P30', 'PROF@unical.IT')");
        self::assertSame(['risorse'], array_column((new EmailNonVerificata($this->db))->perdite($this->riga(30)), 'codice'));
    }

    // ───────────── V1e: elenco di chi perderebbe qualcosa (sola lettura) ─────────────

    public function testChiPerdeElencaSoloGliAccountNonVerificatiCheHannoQualcosaDaPerdere(): void
    {
        $this->personaPiena(20, 'prof@unical.it');
        $this->utente(21, 'nessuno@gmail.com', 1, null);
        $this->utente(23, 'verificata@unical.it', 1, '2026-10-01 09:00:00');
        $this->db->esegui("INSERT INTO risorse_responsabili (risorsa_id, persona_id, email) VALUES (2, 'P23', 'verificata@unical.it')");
        $this->utente(24, '', 1, null);
        $this->utente(25, 'altro@gmail.com', 1, null);
        $this->db->esegui("INSERT INTO risorse_responsabili (risorsa_id, persona_id, email) VALUES (3, 'P25', 'altro@gmail.com')");

        $elenco = (new EmailNonVerificata($this->db))->chiPerde();
        self::assertSame([25, 20], array_column($elenco, 'id'), 'in ordine di cognome (Rossi, Verdi); niente verificati, vuoti o senza compiti');
        self::assertSame(['risorse'], array_column($elenco[0]['perdite'], 'codice'));
        self::assertCount(10, $elenco[1]['perdite']);
    }

    public function testIlRapportoNonMostraLEmailSenzaDettagli(): void
    {
        $this->personaPiena(20, 'prof@unical.it');
        $e = new EmailNonVerificata($this->db);
        $prima = $this->db->righe('SELECT * FROM utenti ORDER BY id');

        $breve = $e->rapporto(false);
        self::assertStringNotContainsString('prof@unical.it', $breve);
        self::assertStringNotContainsString('@', $breve, 'nessun indirizzo, nemmeno a pezzi');
        self::assertStringContainsString('#20', $breve);
        self::assertStringContainsString('Direttore o vice', $breve, 'il motivo');
        self::assertStringContainsString('conferma', mb_strtolower($breve), 'e che cosa fare');

        $lungo = $e->rapporto(true);
        self::assertStringContainsString('prof@unical.it', $lungo);

        self::assertSame($prima, $this->db->righe('SELECT * FROM utenti ORDER BY id'), 'sola lettura');
    }

    public function testIlRapportoSenzaNessunoLoDice(): void
    {
        $this->utente(21, 'nessuno@gmail.com', 1, null);
        self::assertStringContainsString('Nessuno', (new EmailNonVerificata($this->db))->rapporto(false));
    }
}
