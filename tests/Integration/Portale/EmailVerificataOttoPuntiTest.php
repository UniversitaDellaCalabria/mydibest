<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Core\Container;
use App\Core\Database;
use App\Core\Orologio;
use App\Core\Schema\Definizioni;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\ConDirezione;
use Tests\Integration\DatabaseDiProva;

/**
 * V2: gli otto punti che confrontavano ancora l'email grezza dell'utente collegato (T3 li aveva lasciati fuori, «da decidere»). Un'email scelta a mano
 * e mai confermata non deve dare niente: né la segreteria della Direzione, né un ufficio di Ateneo, né lo scarico dei verbali, né il diario di
 * bordo, né il cruscotto di una risorsa, né «I miei studenti», né il ruolo di tutor nei pagamenti, né le approvazioni del docente.
 * Ogni prova controlla i tre casi: non verificata (niente), verificata con il codice (sì), login di Ateneo/SPID/CIE (sì, non si perde niente).
 */
final class EmailVerificataOttoPuntiTest extends DatabaseDiProva
{
    use ConDirezione;

    private Container $c;
    private MailerFinto $mailer;

    private const REALI = ['pratiche', 'pratiche_conferme', 'pratiche_firme', 'didattica_moduli', 'tutorato_incarichi', 'tutorato_registro', 'tutorato_bandi', 'risorse', 'risorse_responsabili', 'prenotazioni_risorse',
                           'risorse_diario', 'didattica_sedute', 'didattica_consigli_persone', 'didattica_consigli'];

    protected function tabelle(): array
    {
        return [
            ...$this->tabelleUffici(),
            'impostazioni_sistema' => 'CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, ' . self::COLONNE_ASSENZA . ')',
            'utenti' => 'CREATE TABLE utenti (id INT PRIMARY KEY, nome VARCHAR(80) DEFAULT \'\', cognome VARCHAR(80) DEFAULT \'\', email VARCHAR(150) NULL, codice_fiscale VARCHAR(16) NULL, ruolo_id INT DEFAULT 5, ruoli_secondari VARCHAR(100) DEFAULT \'\',
                email_personalizzata TINYINT DEFAULT 0, email_verificata_il DATETIME NULL, persona_id VARCHAR(80) NULL)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $defs = Definizioni::tabelle();
        $cols = Definizioni::colonne();
        foreach (self::REALI as $t) {
            self::$conn->query("DROP TABLE IF EXISTS `$t`");
            if (!isset($defs[$t])) {
                continue;
            }
            self::$conn->query($defs[$t]);
            foreach ($cols[$t] ?? [] as $spec) {
                self::$conn->query("ALTER TABLE `$t` " . preg_replace('/\s+(AFTER|FIRST)\b(\s+`?\w+`?)?/i', '', is_array($spec) ? $spec[0] : $spec));
            }
        }
        $this->mailer = new MailerFinto();
        $this->c = new Container();
        $this->c->istanza(Container::class, $this->c);
        $this->c->istanza(\App\Anagrafi\ClientApiAteneo::class, new \Tests\Doppi\AnagrafiClientApiFinto());
        $this->c->istanza(Database::class, $this->db);
        $this->c->istanza(Mailer::class, $this->mailer);
        $this->c->istanza(Orologio::class, new OrologioFisso('2026-10-14 08:00:00'));
        $this->c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
        \App\Auth\Registrazione::registra($this->c);
        foreach (['Didattica', 'Tutorato', 'Risorse', 'Uffici', 'Ateneo', 'ConvenzioniTesi', 'Iscrizioni'] as $modulo) {
            $classe = '\\App\\' . $modulo . '\\Registrazione';
            if (class_exists($classe)) {
                $classe::registra($this->c);
            }
        }
        $this->c->istanza(Mailer::class, $this->mailer);
        $this->c->istanza(\App\Core\IndirizzoClient::class, new \Tests\Doppi\ClientFinto());
        $this->c->istanza(\Unical\Titulus\FabbricaClienteTitulus::class, new \Unical\Titulus\FabbricaClienteSoap());
        $this->c->istanza(\App\Tutorato\FirmaRemota::class, new \Tests\Doppi\FirmaRemotaFinta());
        $this->c->istanza(\App\Infrastructure\Pdf\VerificaFirme::class, new \Tests\Doppi\FirmeFinte());
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} non verificata (scelta a mano), verificata con il codice, login di Ateneo/SPID/CIE */
    private function tre(string $email): array
    {
        return [
            ['id' => 7, 'email' => $email, 'email_personalizzata' => 1, 'email_verificata_il' => null],
            ['id' => 7, 'email' => $email, 'email_personalizzata' => 1, 'email_verificata_il' => '2026-10-01 10:00:00'],
            ['id' => 7, 'email' => $email, 'email_personalizzata' => 0],
        ];
    }

    private function ufficio(string $chiave, string $email, string $ruolo = ''): int
    {
        $id = (int) ($this->db->valore('SELECT id FROM uffici WHERE chiave = ?', [$chiave]) ?: $this->db->inserisci('INSERT INTO uffici (nome, chiave) VALUES (?, ?)', [$chiave, $chiave]));
        $this->db->esegui('INSERT INTO uffici_persone (ufficio_id, email, nominativo, ruolo) VALUES (?, ?, ?, ?)', [$id, $email, 'Persona', $ruolo]);

        return $id;
    }

    /** @param callable(array<string, mixed>): mixed $f */
    private function soloConVerificata(callable $f, mixed $senza, mixed $con, string $punto): void
    {
        [$non, $ver, $sso] = $this->tre($GLOBALS['__email_prova']);
        $this->assertEquals($senza, $f($non), "$punto: un'email non verificata non dà niente");
        $this->assertEquals($con, $f($ver), "$punto: con l'email confermata col codice sì");
        $this->assertEquals($con, $f($sso), "$punto: chi entra con SSO/SPID/CIE non perde niente");
    }

    private function prova(string $email, callable $f, mixed $senza, mixed $con, string $punto): void
    {
        $GLOBALS['__email_prova'] = $email;
        $this->soloConVerificata($f, $senza, $con, $punto);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__email_prova']);
    }

    // 1 · Direzione:161 — segreteria di direzione (attiva «Direttore assente»)
    public function testSegreteriaDellaDirezione(): void
    {
        $this->ufficio('segreteria_direzione', 'seg@x.it');
        $dz = $this->c->get(\App\Uffici\Direzione::class);
        $this->prova('seg@x.it', static fn (array $u): bool => $dz->puoImpostareAssenza($u), false, true, 'segreteria della Direzione');
    }

    // 2 · ServizioUfficiAteneo — gli uffici di Ateneo (appartenenza e elenco degli uffici della persona)
    public function testUfficiDiAteneo(): void
    {
        $id = $this->ufficio('pagamenti', 'uff@x.it');
        $this->db->esegui("UPDATE uffici SET ambito = 'ateneo' WHERE id = ?", [$id]);
        $s = $this->c->get(\App\Ateneo\ServizioUfficiAteneo::class);
        $this->prova('uff@x.it', static fn (array $u): bool => $s->utenteMembro($u, $id), false, true, 'utenteMembro');
        $this->prova('uff@x.it', static fn (array $u): int => count($s->ufficiDellUtente($u)), 0, 1, 'ufficiDellUtente');
    }

    // 3 · ServizioVerbaliPubblici — chi può scaricare i verbali
    public function testScaricoDeiVerbali(): void
    {
        $this->ufficio('verbali_scarico', 'verb@x.it');
        $s = $this->c->get(\App\Didattica\ServizioVerbaliPubblici::class);
        $this->prova('verb@x.it', static fn (array $u): bool => $s->puoScaricare($u), false, true, 'scarico dei verbali');
    }

    // 4 · ServizioDiarioBordo — compilare il diario come co-conducente o responsabile; i diari da compilare
    public function testDiarioDiBordo(): void
    {
        $ris = $this->db->inserisci("INSERT INTO risorse (nome, tipo) VALUES ('Furgone', 'auto')");
        $this->db->esegui("INSERT INTO risorse_responsabili (risorsa_id, persona_id, email, puo_approvare) VALUES (?, 'p1', 'resp@x.it', 1)", [$ris]);
        $pren = ['risorsa_id' => $ris, 'utente_id' => 99, 'co_conducenti_json' => json_encode([['nome' => 'Co Conducente', 'persona_id' => null, 'email' => 'co@x.it']])];
        $s = $this->c->get(\App\Risorse\ServizioDiarioBordo::class);
        $this->prova('co@x.it', static fn (array $u): bool => $s->puoCompilare($pren, $u), false, true, 'puoCompilare (co-conducente)');
        $this->prova('resp@x.it', static fn (array $u): bool => $s->puoCompilare($pren, $u), false, true, 'puoCompilare (responsabile)');
        $this->prova('resp@x.it', static fn (array $u): bool => $s->eResponsabile($pren, $u), false, true, 'eResponsabile');

        // i diari da compilare: una prenotazione conclusa senza diario, con un co-conducente senza email (come capita dal modulo)
        $this->db->esegui('UPDATE risorse SET diario_attivo = 1 WHERE id = ?', [$ris]);
        $this->db->esegui("INSERT INTO prenotazioni_risorse (risorsa_id, utente_id, stato, nome, cognome, inizio, fine, co_conducenti_json, creata_il)
            VALUES (?, 99, 'confermata', 'Altro', 'Utente', '2026-10-01 08:00:00', '2026-10-01 10:00:00', ?, NOW())", [$ris, json_encode([['nome' => 'Senza Email', 'persona_id' => null, 'email' => ''], ['nome' => 'Co', 'persona_id' => null, 'email' => 'co@x.it']])]);
        $this->prova('co@x.it', static fn (array $u): int => count($s->daCompilare($u)), 0, 1, 'daCompilare (co-conducente)');
        // senza email verificata la ricerca non deve diventare «tutti i co-conducenti senza email»
        $this->assertSame(0, count($s->daCompilare(['id' => 8, 'email' => 'a@x.it', 'email_personalizzata' => 1, 'email_verificata_il' => null])), 'un\'email non verificata non eredita i diari dei co-conducenti senza email');
    }

    // 5 · CruscottoRisorsa — il cruscotto di una risorsa (responsabile)
    public function testCruscottoDellaRisorsa(): void
    {
        $ris = $this->db->inserisci("INSERT INTO risorse (nome, tipo) VALUES ('Aula 3', 'aula')");
        $this->db->esegui("INSERT INTO risorse_responsabili (risorsa_id, persona_id, email, puo_approvare) VALUES (?, 'p1', 'resp@x.it', 1)", [$ris]);
        $s = $this->c->get(\App\Risorse\CruscottoRisorsa::class);
        $this->prova('resp@x.it', static fn (array $u): bool => $s->puoVedere($u, $ris), false, true, 'cruscotto della risorsa');
    }

    // 6 · StudentiDelDocente — «I miei studenti»: le pratiche con la firma del docente
    public function testIMieiStudenti(): void
    {
        $this->db->esegui("INSERT INTO didattica_moduli (id, titolo, tipo) VALUES (1, 'Tesi', 'online')");
        $p = $this->db->inserisci("INSERT INTO pratiche (modulo_id, utente_id, codice, nome, cognome, stato, creata_il, aggiornata_il) VALUES (1, 2, 'PR-1', 'Luca', 'Rossi', 'in_lavorazione', NOW(), NOW())");
        $this->db->esegui("INSERT INTO pratiche_firme (pratica_id, passo, tipo, nome, email, token) VALUES (?, 1, 'persona', 'Anna Verdi', 'anna@x.it', 'tf1')", [$p]);
        $s = $this->c->get(\App\Didattica\StudentiDelDocente::class);
        $this->prova('anna@x.it', static fn (array $u): int => count($s->perUtente($u)), 0, 1, 'I miei studenti');
    }

    // 7 · ServizioPagamenti — chi è il tutor di un incarico (per codice fiscale o per email)
    public function testRuoloDelTutorNeiPagamenti(): void
    {
        $s = $this->c->get(\App\Tutorato\ServizioPagamenti::class);
        $incarico = ['pagamento_stato' => 'in_attesa', 'codice_fiscale' => 'RSSMRA80A01H501U', 'email' => 'tutor@x.it'];
        $this->prova('tutor@x.it', static fn (array $u): ?string => $s->ruolo($u, $incarico, false), null, 'tutor', 'ruolo del tutor per email');
        // il codice fiscale dell'accesso SPID/CIE vale comunque, anche con un'email non verificata
        $conCf = ['id' => 7, 'email' => 'x@x.it', 'email_personalizzata' => 1, 'email_verificata_il' => null, 'codice_fiscale' => 'RSSMRA80A01H501U'];
        $this->assertSame('tutor', $s->ruolo($conCf, $incarico, false), 'il codice fiscale resta una prova');
    }

    // 8 · ApprovazioniDocente — il riquadro «da approvare» (firme, conferme, registri): tutte le fonti leggono l'email verificata
    public function testApprovazioniDelDocente(): void
    {
        $this->db->esegui("INSERT INTO didattica_moduli (id, titolo, tipo) VALUES (1, 'Attestato Frequenza Lezione', 'online')");
        $p = $this->db->inserisci("INSERT INTO pratiche (modulo_id, utente_id, codice, nome, cognome, stato, creata_il, aggiornata_il) VALUES (1, 2, 'PR-1', 'Luca', 'Rossi', 'in_lavorazione', NOW(), NOW())");
        $this->db->esegui("INSERT INTO pratiche_conferme (pratica_id, passo, nome, email, token) VALUES (?, 1, 'Verdi Anna', 'doc@x.it', 'tc1')", [$p]);
        $this->db->esegui("INSERT INTO pratiche_firme (pratica_id, passo, tipo, nome, email, token) VALUES (?, 1, 'persona', 'Anna Verdi', 'doc@x.it', 'tf1')", [$p]);
        $s = $this->c->get(\App\Portale\ApprovazioniDocente::class);
        $this->prova('doc@x.it', static fn (array $u): int => $s->perUtente($u)['firme'] + $s->perUtente($u)['conferme'], 0, 2, 'approvazioni del docente');
    }
}
