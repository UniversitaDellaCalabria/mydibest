<?php

declare(strict_types=1);

namespace Tests\Integration\Anagrafi;

use App\Anagrafi\CatalogoRepository;
use App\Anagrafi\CorsoRepository;
use App\Anagrafi\Endpoint\RicercaInsegnamenti;
use App\Anagrafi\Endpoint\RicercaScuole;
use App\Anagrafi\InsegnamentoRepository;
use App\Anagrafi\ScuolaRepository;
use App\Anagrafi\ServizioCatalogo;
use App\Anagrafi\ServizioScuole;
use App\Auth\LimiteRichieste;
use App\Auth\RateLimitRepository;
use App\Core\IndirizzoClient;
use App\Http\Richiesta;
use Tests\Doppi\AnagrafiClientApiFinto;
use Tests\Doppi\AuthSessioneInMemoria;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/**
 * Gli endpoint JSON cerca_scuole.php e cerca_insegnamenti.php provati senza browser: una Richiesta::finta() entra, si legge la Risposta.
 * I nomi dei parametri sono quelli che il JavaScript delle pagine chiama davvero (campo-scuola.js, campi-pratica.js).
 */
final class EndpointRicercaTest extends DatabaseDiProva
{
    private const IP = '10.1.2.3';

    private ?string $ip = self::IP;
    private AuthSessioneInMemoria $sessione;
    private AnagrafiClientApiFinto $api;

    protected function tabelle(): array
    {
        $v = static fn (string $n): string => self::tabellaVera($n);

        return [
            'scuole' => $v('scuole'),
            'convenzioni_scuole' => $v('convenzioni_scuole'),
            'rate_limit_attempts' => $v('rate_limit_attempts'),
            'ateneo_cds' => "CREATE TABLE ateneo_cds (codice VARCHAR(20) NOT NULL, anno SMALLINT NOT NULL, nome VARCHAR(255) NOT NULL DEFAULT '', tipo VARCHAR(10) DEFAULT '', tipo_descrizione VARCHAR(100) DEFAULT '',
                dipartimento_cod VARCHAR(20) DEFAULT '', dipartimento VARCHAR(255) DEFAULT '', aggiornato_il DATETIME NULL, PRIMARY KEY (codice, anno))",
            'ateneo_insegnamenti' => "CREATE TABLE ateneo_insegnamenti (id INT PRIMARY KEY, cds_cod VARCHAR(20) NOT NULL DEFAULT '', coorte SMALLINT NOT NULL DEFAULT 0, anno_corso TINYINT NULL, codice VARCHAR(30) DEFAULT '',
                nome VARCHAR(255) NOT NULL DEFAULT '', cfu DECIMAL(5,1) NULL, ssd_cod VARCHAR(20) DEFAULT '', ssd VARCHAR(150) DEFAULT '', partizione VARCHAR(150) DEFAULT '', semestre VARCHAR(60) DEFAULT '', docente VARCHAR(150) DEFAULT '')",
            'ateneo_insegnamenti_scaricati' => 'CREATE TABLE ateneo_insegnamenti_scaricati (cds_cod VARCHAR(20) NOT NULL, coorte SMALLINT NOT NULL, n INT NOT NULL DEFAULT 0, scaricato_il DATETIME NULL, PRIMARY KEY (cds_cod, coorte))',
            'corsi_studio' => "CREATE TABLE corsi_studio (codice VARCHAR(20) PRIMARY KEY, nome VARCHAR(255) NOT NULL DEFAULT '', tipo VARCHAR(10) DEFAULT '', tipo_descrizione VARCHAR(100) DEFAULT '', classe VARCHAR(255) DEFAULT '',
                anno INT NULL, lingua VARCHAR(100) DEFAULT '', durata INT NULL, dipartimento_cod VARCHAR(20) DEFAULT '', visibile TINYINT NOT NULL DEFAULT 1, presente TINYINT NOT NULL DEFAULT 1, aggiornato_il DATETIME NULL, regdid_id INT NULL)",
            'insegnamenti' => "CREATE TABLE insegnamenti (id INT PRIMARY KEY, codice VARCHAR(30) DEFAULT '', nome VARCHAR(255) NOT NULL DEFAULT '', cds_cod VARCHAR(20) DEFAULT '', cds_nome VARCHAR(255) DEFAULT '',
                anno_corso TINYINT NULL, anno_accademico SMALLINT NULL, coorte SMALLINT NULL, semestre VARCHAR(60) DEFAULT '', ssd_cod VARCHAR(20) DEFAULT '', ssd VARCHAR(150) DEFAULT '', lingua VARCHAR(60) DEFAULT '',
                docente VARCHAR(150) DEFAULT '', docente_id VARCHAR(30) DEFAULT '', partizione VARCHAR(150) DEFAULT '', padre_id INT NULL, dipartimento_cod VARCHAR(20) DEFAULT '', presente TINYINT NOT NULL DEFAULT 1,
                aggiornato_il DATETIME NULL, cfu DECIMAL(5,1) NULL)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->allineaColonne();
        $this->ip = self::IP;
        $this->sessione = new AuthSessioneInMemoria();
        $this->api = new AnagrafiClientApiFinto();
    }

    private function scuole(): RicercaScuole
    {
        return new RicercaScuole(new ServizioScuole(new ScuolaRepository($this->db)), $this->limite(), $this->client());
    }

    private function insegnamenti(): RicercaInsegnamenti
    {
        $catalogo = new ServizioCatalogo(new CatalogoRepository($this->db), new CorsoRepository($this->db), new InsegnamentoRepository($this->db), $this->api, new OrologioFisso('2026-10-05 12:00:00'));

        return new RicercaInsegnamenti($catalogo, $this->limite(), $this->client(), $this->sessione);
    }

    private function limite(): LimiteRichieste
    {
        return new LimiteRichieste(new RateLimitRepository($this->db));
    }

    private function client(): IndirizzoClient
    {
        $ip = $this->ip;

        return new class($ip) implements IndirizzoClient {
            public function __construct(private ?string $ip)
            {
            }

            public function ip(): ?string
            {
                return $this->ip;
            }
        };
    }

    private function scuola(string $codice, string $nome, string $comune = 'COSENZA', string $provincia = 'COSENZA', string $regione = 'CALABRIA', ?string $istituto = null): void
    {
        $this->db->esegui(
            'INSERT INTO scuole (codice, denominazione, istituto_codice, istituto_denominazione, tipo, comune, provincia, regione, indirizzo, cap) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$codice, $nome, $istituto ?? $codice, $istituto !== null ? 'ISTITUTO ' . $istituto : $nome, 'LICEO', $comune, $provincia, $regione, 'VIA ROMA 1', '87100']
        );
    }

    /** Riempie il limite di richieste dell'endpoint per l'IP di prova. */
    private function esaurisciLimite(string $endpoint, int $n): void
    {
        $hash = hash('sha256', self::IP . $endpoint);
        for ($i = 0; $i < $n; $i++) {
            $this->db->esegui('INSERT INTO rate_limit_attempts (ip_hash, endpoint, hit_at) VALUES (?, ?, NOW())', [$hash, $endpoint]);
        }
    }

    // ── cerca_scuole.php ────────────────────────────────────────────────────────

    public function testScuoleRispondeJsonConNoindex(): void
    {
        $this->scuola('CSPS000001', 'LICEO FERMI');

        $r = $this->scuole()->risposta(Richiesta::finta('GET', '/cerca_scuole.php', ['q' => 'fermi']));

        self::assertSame(200, $r->codice());
        self::assertSame('application/json; charset=utf-8', $r->intestazione('Content-Type'));
        self::assertSame('noindex', $r->intestazione('X-Robots-Tag'));
        $dati = json_decode($r->corpo(), true);
        self::assertSame(['CSPS000001'], array_column($dati, 'codice'));
        self::assertSame(['codice', 'nome', 'tipo', 'comune', 'provincia', 'istituto', 'conv'], array_keys($dati[0]));
    }

    public function testScuoleRicercaTroppoCortaSenzaLuogoDaVuoto(): void
    {
        $this->scuola('CSPS000001', 'LICEO FERMI');

        foreach ([[], ['q' => 'fe'], ['q' => '  fe  '], ['q' => ['fermi']]] as $query) {
            $r = $this->scuole()->risposta(Richiesta::finta('GET', '/cerca_scuole.php', $query));
            self::assertSame('[]', $r->corpo(), json_encode($query));
            self::assertSame(200, $r->codice());
        }
    }

    public function testScuoleConComuneBastaAncheLaRicercaVuotaELimitiSonoQuelliDiPrima(): void
    {
        for ($i = 1; $i <= 85; $i++) {
            $this->scuola(sprintf('CSPS%06d', $i), "LICEO FERMI $i", $i <= 25 ? 'COSENZA' : 'RENDE', 'COSENZA');
        }
        $quante = fn (array $q): int => count(json_decode($this->scuole()->risposta(Richiesta::finta('GET', '/cerca_scuole.php', $q))->corpo(), true));

        self::assertSame(20, $quante(['q' => 'fermi']), 'solo la ricerca: 20');
        self::assertSame(80, $quante(['provincia' => 'cosenza']), 'con la provincia: 80');
        self::assertSame(25, $quante(['comune' => 'Cosenza']), 'con il comune: fino a 200, qui le 25 di Cosenza');
        self::assertSame(1, $quante(['q' => 'fermi 30', 'comune' => 'rende']), 'i filtri e le parole insieme');
    }

    public function testScuoleDettaglioPerLaConvenzione(): void
    {
        $this->scuola('CSPS000001', 'LICEO FERMI');

        $r = $this->scuole()->risposta(Richiesta::finta('GET', '/cerca_scuole.php', ['dettaglio' => ' CSPS000001 ']));
        $d = json_decode($r->corpo(), true);

        self::assertSame(['denominazione', 'codice', 'comune', 'indirizzo'], array_keys($d));
        self::assertSame('CSPS000001', $d['codice']);
        self::assertStringContainsString('(Cosenza)', $d['comune']);
    }

    public function testScuoleDettaglioInesistenteEUnOggettoVuotoNonUnaLista(): void
    {
        self::assertSame('{}', $this->scuole()->risposta(Richiesta::finta('GET', '/cerca_scuole.php', ['dettaglio' => 'XXXX']))->corpo());
        self::assertSame('{}', $this->scuole()->risposta(Richiesta::finta('GET', '/cerca_scuole.php', ['dettaglio' => '']))->corpo(), 'dettaglio vuoto = dettaglio presente, come isset()');
    }

    public function testScuoleElencoDeiLuoghiPerLeTendine(): void
    {
        $this->scuola('CSPS000001', 'A');
        $this->scuola('CSPS000002', 'B', 'RENDE');
        $this->scuola('CZPS000001', 'C', 'LAMEZIA TERME', 'CATANZARO');
        $elenco = fn (array $q): array => json_decode($this->scuole()->risposta(Richiesta::finta('GET', '/cerca_scuole.php', $q))->corpo(), true);

        self::assertSame([['valore' => 'CALABRIA', 'nome' => 'Calabria', 'n' => 3]], $elenco(['elenco' => 'regioni']));
        self::assertSame(['CATANZARO', 'COSENZA'], array_column($elenco(['elenco' => 'province', 'regione' => 'calabria']), 'valore'));
        self::assertSame(['COSENZA', 'RENDE'], array_column($elenco(['elenco' => 'comuni', 'regione' => 'Calabria', 'provincia' => 'Cosenza']), 'valore'));
        self::assertSame([], $elenco(['elenco' => 'inventato']));
    }

    public function testScuoleTroppeRichiesteDa429ConListaVuota(): void
    {
        $this->esaurisciLimite('cerca_scuole', 400);

        $r = $this->scuole()->risposta(Richiesta::finta('GET', '/cerca_scuole.php', ['q' => 'fermi']));

        self::assertSame(429, $r->codice());
        self::assertSame('[]', $r->corpo());
        self::assertSame('noindex', $r->intestazione('X-Robots-Tag'), 'anche l\'errore porta le intestazioni di prima');
    }

    public function testScuoleLimiteSuperatoSoloOltre400(): void
    {
        $this->esaurisciLimite('cerca_scuole', 399);

        self::assertSame(200, $this->scuole()->risposta(Richiesta::finta('GET', '/', ['q' => 'fermi']))->codice(), 'la 400ª passa');
        self::assertSame(429, $this->scuole()->risposta(Richiesta::finta('GET', '/', ['q' => 'fermi']))->codice(), 'la 401ª no');
    }

    public function testScuoleSenzaIndirizzoUsaUnknownCosiComeLaFacciataDiPrima(): void
    {
        $this->ip = null;
        $this->scuole()->risposta(Richiesta::finta('GET', '/', ['q' => 'fermi']));

        self::assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM rate_limit_attempts WHERE ip_hash = ?', [hash('sha256', 'unknown' . 'cerca_scuole')]));
    }

    // ── cerca_insegnamenti.php ──────────────────────────────────────────────────

    private function corso(string $codice, int $anno, string $nome, string $tipo = 'L'): void
    {
        $this->db->esegui("INSERT INTO ateneo_cds (codice, anno, nome, tipo, tipo_descrizione, dipartimento_cod, dipartimento) VALUES (?, ?, ?, ?, 'Laurea', '002014', 'DiBEST')", [$codice, $anno, $nome, $tipo]);
    }

    /** @return array<string, mixed>|list<mixed> */
    private function chiedi(array $query): array
    {
        $r = $this->insegnamenti()->risposta(Richiesta::finta('GET', '/cerca_insegnamenti.php', $query));
        self::assertSame('private, max-age=300', $r->intestazione('Cache-Control'), 'ogni risposta, errori compresi');
        self::assertSame('application/json; charset=utf-8', $r->intestazione('Content-Type'));

        return ['codice' => $r->codice(), 'dati' => json_decode($r->corpo(), true)];
    }

    public function testInsegnamentiSenzaAccessoDa401(): void
    {
        $r = $this->chiedi(['azione' => 'tipi']);

        self::assertSame(401, $r['codice']);
        self::assertSame(['errore' => 'Serve l\'accesso'], $r['dati']);
    }

    public function testInsegnamentiChiHaFattoLAccessoVedeITipi(): void
    {
        $this->sessione->dati['utente_id'] = 7;
        $this->corso('N1', 2025, 'Biologia');
        $this->corso('N2', 2025, 'Chimica', 'LM');

        $r = $this->chiedi(['azione' => 'tipi']);

        self::assertSame(200, $r['codice']);
        self::assertSame(['L', 'LM'], array_column($r['dati'], 'tipo'));
        self::assertSame(['tipo', 'nome'], array_keys($r['dati'][0]));
    }

    public function testInsegnamentiAnniECorsiPulisconoIlTipo(): void
    {
        $this->sessione->dati['utente_id'] = 7;
        $this->corso('N1', 2025, 'Biologia');
        $this->corso('N1', 2024, 'Biologia');
        $this->corso('N3', 2024, 'Scienze');

        self::assertSame([2025, 2024], $this->chiedi(['azione' => 'anni', 'tipo' => 'l'])['dati'], 'il tipo si porta in maiuscolo');
        self::assertSame([], $this->chiedi(['azione' => 'anni', 'tipo' => '!!'])['dati'], 'tipo vuoto dopo la pulizia = lista vuota');
        self::assertSame(['N1', 'N3'], array_column($this->chiedi(['azione' => 'corsi', 'tipo' => 'L'])['dati'], 'codice'), 'senza aa: tutti');
        self::assertSame(['N1', 'N3'], array_column($this->chiedi(['azione' => 'corsi', 'tipo' => 'L', 'aa' => '2024'])['dati'], 'codice'));
        self::assertSame(['N1'], array_column($this->chiedi(['azione' => 'corsi', 'tipo' => 'L', 'aa' => '2025'])['dati'], 'codice'), 'con aa: solo quelli offerti quell\'anno');
        self::assertSame([], $this->chiedi(['azione' => 'corsi'])['dati']);
    }

    public function testInsegnamentiDelCorsoPerAnnoDiOfferta(): void
    {
        $this->sessione->dati['utente_id'] = 7;
        $this->db->esegui("INSERT INTO ateneo_insegnamenti (id, cds_cod, coorte, anno_corso, codice, nome, cfu) VALUES (1, 'N1', 2024, 1, 'B01', 'Biologia generale', 9), (2, 'N1', 2024, 2, 'B02', 'Genetica', 6), (3, 'N2', 2024, 1, 'C01', 'Chimica', 9)");
        $this->db->esegui("INSERT INTO ateneo_insegnamenti_scaricati (cds_cod, coorte, n, scaricato_il) VALUES ('N1', 2024, 2, NOW())");

        $r = $this->chiedi(['azione' => 'insegnamenti', 'cds' => 'N1!', 'aa' => '2024']);

        self::assertSame(200, $r['codice']);
        self::assertSame(['Biologia generale', 'Genetica'], array_column($r['dati'], 'nome'));
        self::assertSame([], $this->api->chiamate, 'con la copia locale recente non si chiamano le API');
        self::assertSame([], $this->chiedi(['azione' => 'insegnamenti', 'cds' => 'N1', 'aa' => 'xx'])['dati'], 'anno non valido');
    }

    public function testInsegnamentiAzioneNonValidaDa400(): void
    {
        $this->sessione->dati['utente_id'] = 7;

        foreach ([[], ['azione' => 'boh'], ['azione' => ['tipi']]] as $q) {
            $r = $this->chiedi($q);
            self::assertSame(400, $r['codice'], json_encode($q));
            self::assertSame(['errore' => 'Azione non valida'], $r['dati']);
        }
    }

    public function testInsegnamentiTroppeRichiesteDa429(): void
    {
        $this->sessione->dati['utente_id'] = 7;
        $this->esaurisciLimite('cerca_insegnamenti', 240);

        $r = $this->chiedi(['azione' => 'tipi']);

        self::assertSame(429, $r['codice']);
        self::assertSame(['errore' => 'Troppe richieste'], $r['dati']);
    }

    public function testInsegnamentiIlControlloDellAccessoVieneAlLimite(): void
    {
        $this->esaurisciLimite('cerca_insegnamenti', 240);

        self::assertSame(401, $this->chiedi(['azione' => 'tipi'])['codice'], 'prima l\'accesso, poi il limite: come prima');
    }
}
