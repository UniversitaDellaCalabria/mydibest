<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use mysqli;
use PHPUnit\Framework\TestCase;

/**
 * Base dei test che usano MySQL/MariaDB: database usa e getta eventi_prova_unit (PROVE_DB_HOST/USER/PASS come
 * strumenti/prove/esegui.php). Ogni classe di test crea le sue tabelle; senza database i test si saltano.
 */
abstract class DatabaseDiProva extends TestCase
{
    protected static ?mysqli $conn = null;
    protected Database $db;

    public static function setUpBeforeClass(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = @new mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''));
        if ($conn->connect_error) {
            self::$conn = null;

            return;
        }
        $nome = getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit';
        $conn->query("CREATE DATABASE IF NOT EXISTS `$nome` CHARACTER SET utf8mb4");
        $conn->select_db($nome);
        $conn->set_charset('utf8mb4');
        self::$conn = $conn;
    }

    protected function setUp(): void
    {
        if (self::$conn === null) {
            self::markTestSkipped('Database di prova non raggiungibile');
        }
        $this->db = Database::per(self::$conn);
        // l'ora fissa di una base (SET timestamp) non passa da una classe di test all'altra: ognuna riparte dall'ora vera
        self::$conn->query('SET timestamp = DEFAULT');
        // i testi delle email (modificati dal pannello) non devono passare da un test all'altro
        self::$conn->query('DROP TABLE IF EXISTS `email_modelli`');
        self::$conn->query("CREATE TABLE email_modelli (chiave VARCHAR(80) NOT NULL PRIMARY KEY, oggetto VARCHAR(300) NOT NULL DEFAULT '', corpo MEDIUMTEXT DEFAULT NULL, bottone VARCHAR(120) DEFAULT '', aggiornato_il DATETIME DEFAULT NULL, aggiornato_da VARCHAR(150) DEFAULT '')");
        foreach ($this->tabelle() as $nome => $sql) {
            self::$conn->query("DROP TABLE IF EXISTS `$nome`");
            self::$conn->query($sql);
        }
    }

    /**
     * Allinea le tabelle di prova scritte a mano allo schema vero: per ogni tabella di tabelle() applica le colonne di
     * Definizioni::colonne() che mancano (si controlla con SHOW COLUMNS, quelle già presenti restano come sono).
     * Da chiamare in setUp() dopo parent::setUp(), nelle basi che tengono le tabelle a mano. Così un test non resta indietro
     * quando il portale aggiunge una colonna. Le colonne che stanno solo nel CREATE di Definizioni::tabelle() si ottengono
     * prendendo l'intera tabella con tabellaVera().
     */
    protected function allineaColonne(): void
    {
        $colonne = \App\Core\Schema\Definizioni::colonne();
        foreach (array_keys($this->tabelle()) as $nome) {
            foreach ($colonne[$nome] ?? [] as $colonna => $alter) {
                $alter = is_array($alter) ? ($alter[0] ?? null) : $alter;
                if (!is_string($alter) || !is_string($colonna)) {
                    continue;
                }
                $r = self::$conn->query("SHOW COLUMNS FROM `$nome` LIKE '" . self::$conn->real_escape_string($colonna) . "'");
                if ($r !== false && $r->num_rows === 0) {
                    // la posizione (AFTER ...) non serve: la colonna dopo cui va potrebbe non esserci nella tabella di prova
                    self::$conn->query("ALTER TABLE `$nome` " . preg_replace('/\s+(AFTER|FIRST)\b(\s+`?\w+`?)?/i', '', $alter));
                }
            }
        }
    }

    /** La tabella com'è nello schema vero (CREATE di Definizioni::tabelle()), per i test che non hanno bisogno di una versione ridotta. */
    protected static function tabellaVera(string $nome): string
    {
        return \App\Core\Schema\Definizioni::tabelle()[$nome];
    }

    /** @return array<string, string> nome tabella => CREATE TABLE */
    abstract protected function tabelle(): array;
}
