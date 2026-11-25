<?php

declare(strict_types=1);

namespace Tests\Integration\Protocollo;

use App\Core\Ambiente;
use App\Infrastructure\Sicurezza\Cifratura;
use App\Protocollo\ProtocolloRepository;
use App\Protocollo\RichiestaProtocollo;
use App\Protocollo\ServizioProtocollo;
use RuntimeException;
use Tests\Integration\DatabaseDiProva;
use Unical\Titulus\AllegatoTitulus;
use Unical\Titulus\ConfigTitulus;
use Unical\Titulus\FabbricaClienteTitulus;
use Unical\Titulus\GeneratoreXml;

/** Copia di prova: Titulus si invia solo se l'indirizzo è di pre-produzione; il rifiuto si legge nel registro degli invii. */
final class TitulusInProvaTest extends DatabaseDiProva
{
    private const URL_PRODUZIONE = 'https://titulus-unical.cineca.it/titulus_ws/services/Titulus4?wsdl';
    private const URL_PREPROD = 'https://titulus-unical.pp.cineca.it/titulus_ws/services/Titulus4?wsdl';

    protected function tabelle(): array
    {
        return [
            'titulus_config' => "CREATE TABLE titulus_config (id TINYINT NOT NULL PRIMARY KEY, attivo TINYINT(1) NOT NULL DEFAULT 0, nome VARCHAR(255) NOT NULL DEFAULT '', url_ws VARCHAR(300) NOT NULL DEFAULT '',
                namespace_ns2 VARCHAR(300) NOT NULL DEFAULT '', username VARCHAR(255) NOT NULL DEFAULT '', password_cifrata TEXT DEFAULT NULL, aoo VARCHAR(12) NOT NULL DEFAULT '', agd VARCHAR(12) NOT NULL DEFAULT '',
                autore VARCHAR(150) NOT NULL DEFAULT '', email_default VARCHAR(255) NOT NULL DEFAULT '', fascicolazione_separata TINYINT(1) NOT NULL DEFAULT 1, ultima_prova_il DATETIME DEFAULT NULL, ultima_prova_esito VARCHAR(500) NOT NULL DEFAULT '')",
            'titulus_regole' => "CREATE TABLE titulus_regole (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(255) NOT NULL, applica_a VARCHAR(80) NOT NULL DEFAULT '', attiva TINYINT(1) NOT NULL DEFAULT 1,
                tipo_documento VARCHAR(10) NOT NULL DEFAULT 'arrivo', azione VARCHAR(10) NOT NULL DEFAULT 'protocolla', voce_indice_id INT DEFAULT NULL, repertorio_id INT DEFAULT NULL, uo VARCHAR(12) NOT NULL DEFAULT '',
                rpa_nome VARCHAR(255) NOT NULL DEFAULT '', rpa_username VARCHAR(255) NOT NULL DEFAULT '', rpa_matricola VARCHAR(255) NOT NULL DEFAULT '', invia_email_rpa TINYINT(1) NOT NULL DEFAULT 1, email VARCHAR(255) NOT NULL DEFAULT '',
                cod_titolario VARCHAR(12) NOT NULL DEFAULT '', fascicolo_numero VARCHAR(255) NOT NULL DEFAULT '', fascicolo_anno SMALLINT DEFAULT NULL, op_uo VARCHAR(12) NOT NULL DEFAULT '', op_nome VARCHAR(255) NOT NULL DEFAULT '',
                op_username VARCHAR(255) NOT NULL DEFAULT '', op_matricola VARCHAR(255) NOT NULL DEFAULT '', creata_il DATETIME DEFAULT CURRENT_TIMESTAMP)",
            'titulus_cc' => 'CREATE TABLE titulus_cc (id INT AUTO_INCREMENT PRIMARY KEY, regola_id INT NOT NULL, uo VARCHAR(12) NOT NULL DEFAULT \'\', nome VARCHAR(255) NOT NULL DEFAULT \'\', username VARCHAR(255) NOT NULL DEFAULT \'\', matricola VARCHAR(255) NOT NULL DEFAULT \'\')',
            'titulus_invii' => "CREATE TABLE titulus_invii (id INT AUTO_INCREMENT PRIMARY KEY, regola_id INT DEFAULT NULL, applica_a VARCHAR(80) NOT NULL DEFAULT '', rif_tipo VARCHAR(40) NOT NULL DEFAULT '', rif_id INT DEFAULT NULL,
                oggetto VARCHAR(500) NOT NULL DEFAULT '', tipo_documento VARCHAR(10) NOT NULL DEFAULT '', azione VARCHAR(10) NOT NULL DEFAULT '', esito VARCHAR(10) NOT NULL DEFAULT 'errore', nrecord VARCHAR(40) NOT NULL DEFAULT '',
                num_prot VARCHAR(60) NOT NULL DEFAULT '', url VARCHAR(500) NOT NULL DEFAULT '', errore TEXT DEFAULT NULL, file_principale VARCHAR(255) NOT NULL DEFAULT '', utente_id INT DEFAULT NULL, creato_il DATETIME DEFAULT CURRENT_TIMESTAMP)",
        ];
    }

    /** @return array{ServizioProtocollo, object} il servizio e la fabbrica che conta le chiamate (se qualcuno prova a collegarsi) */
    private function servizio(string $url, Ambiente $ambiente): array
    {
        $cifratura = new Cifratura('una-frase-lunga-almeno-sedici');
        $this->db->esegui('INSERT INTO titulus_config (id, attivo, url_ws, username, aoo, password_cifrata) VALUES (1, 1, ?, ?, ?, ?)', [$url, 'portale', 'ABC', $cifratura->cifra('segreta')]);
        $this->db->esegui("INSERT INTO titulus_regole (nome, applica_a, tipo_documento, uo, cod_titolario) VALUES ('Generale', 'generale', 'arrivo', '2014', '3/2')");
        $fabbrica = new class () implements FabbricaClienteTitulus {
            public int $chiamate = 0;

            public function crea(ConfigTitulus $config, string $password): \Unical\Titulus\ClienteTitulus
            {
                ++$this->chiamate;

                throw new RuntimeException('collegamento tentato');
            }
        };

        return [new ServizioProtocollo(new ProtocolloRepository($this->db), $cifratura, $fabbrica, new GeneratoreXml(), $ambiente), $fabbrica];
    }

    private function richiesta(): RichiestaProtocollo
    {
        return new RichiestaProtocollo('generale', 'Domanda di prova', AllegatoTitulus::crea('domanda.pdf', "%PDF-1.4\nprova"), [], ['nome' => 'Anna', 'cognome' => 'Verdi'], null, 'pratica', 7);
    }

    public function testInProvaUnIndirizzoDiProduzioneSiRifiutaESiScriveNelRegistro(): void
    {
        [$svc, $fabbrica] = $this->servizio(self::URL_PRODUZIONE, new Ambiente(true, 'prove@unical.it'));

        $esito = $svc->protocolla($this->richiesta());

        self::assertFalse($esito->ok);
        self::assertStringContainsString('.pp.cineca.it', $esito->messaggio);
        self::assertSame(0, $fabbrica->chiamate, 'nessun collegamento a Titulus');
        $riga = $this->db->riga('SELECT esito, errore, rif_tipo, rif_id FROM titulus_invii');
        self::assertSame('errore', $riga['esito']);
        self::assertStringContainsString('Ambiente di prova', (string) $riga['errore']);
        self::assertStringContainsString('pre-produzione', (string) $riga['errore']);
        self::assertSame(['pratica', 7], [$riga['rif_tipo'], (int) $riga['rif_id']]);
    }

    public function testInProvaLaPreproduzioneVaAvanti(): void
    {
        [$svc, $fabbrica] = $this->servizio(self::URL_PREPROD, new Ambiente(true, 'prove@unical.it'));

        $esito = $svc->protocolla($this->richiesta());

        self::assertFalse($esito->ok);
        self::assertSame(1, $fabbrica->chiamate, 'il portale prova davvero a collegarsi alla pre-produzione');
        self::assertStringNotContainsString('Ambiente di prova', $esito->messaggio);
    }

    public function testFuoriDallaProvaLIndirizzoDiProduzioneNonHaLimiti(): void
    {
        [$svc, $fabbrica] = $this->servizio(self::URL_PRODUZIONE, new Ambiente());

        $svc->protocolla($this->richiesta());

        self::assertSame(1, $fabbrica->chiamate);
    }
}
