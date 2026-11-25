<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Core\Sito;
use App\Fsl\ConvenzioneCompilataRepository;
use App\Fsl\ServizioConvenzioneOnline;
use App\Fsl\ServizioReferenteConvenzione;

/**
 * Correzioni W3, W4 e W5 del passo 8: l'avvio della stipula dalla scheda della scuola.
 *  W3: se l'ufficio riprende una compilazione che nessuno ha rivendicato, il link e il codice di prima non aprono più; una vuota e non avviata non si riprende.
 *  W4: bloccano l'avvio solo le compilazioni compilate o avviate dall'ufficio; se ne blocca una, la scheda sa dire quale.
 *  W5: nel rinnovo i dati di prima (Dirigente compreso) si copiano solo se il referente scelto era già proprietario verificato; i vecchi referenti non restano.
 */
final class AvvioDalPannelloTest extends FslBase
{
    private string $radiceProva;

    protected function tabelle(): array
    {
        return [
            ...parent::tabelle(),
            'convenzioni_messaggi' => "CREATE TABLE convenzioni_messaggi (id INT AUTO_INCREMENT PRIMARY KEY, compilazione_id INT NOT NULL, da VARCHAR(10) NOT NULL, tipo VARCHAR(20) NOT NULL DEFAULT 'messaggio',
                testo VARCHAR(2000) NOT NULL DEFAULT '', autore VARCHAR(150) NOT NULL DEFAULT '', creato_il DATETIME DEFAULT CURRENT_TIMESTAMP, letto_il DATETIME NULL)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->radiceProva = sys_get_temp_dir() . '/fsl_avvio_' . bin2hex(random_bytes(4));
        mkdir($this->radiceProva . '/uploads/convenzioni_compilate', 0755, true);
        $this->c->istanza(Sito::class, new Sito($this->radiceProva, 'https://portale.test/eventi'));
        $this->c->istanza(\App\Tutorato\FirmaRemota::class, new \Tests\Doppi\FirmaRemotaFinta());
        // l'istituto ha la sua riga nell'anagrafe (come nei dati del Ministero): senza, la stipula non si avvia
        $this->db->esegui("INSERT INTO scuole (codice, denominazione, istituto_codice, istituto_denominazione, comune, provincia, indirizzo, cap) VALUES
            ('CSIS00001A', 'ISTITUTO GALILEI', 'CSIS00001A', 'ISTITUTO GALILEI', 'COSENZA', 'CS', 'VIA ROMA 1', '87100')");
        $this->db->esegui('ALTER TABLE utenti ADD COLUMN nome VARCHAR(100) NULL, ADD COLUMN cognome VARCHAR(100) NULL');
        $this->db->esegui("ALTER TABLE pagine_eventi ADD COLUMN tipo_area VARCHAR(20) NOT NULL DEFAULT ''");
        $this->db->esegui('ALTER TABLE partecipanti_prenotazione ADD COLUMN escluso TINYINT NOT NULL DEFAULT 0');
    }

    protected function tearDown(): void
    {
        $this->togli($this->radiceProva);
    }

    private function togli(string $d): void
    {
        foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) {
                continue;
            }
            is_dir($f) ? $this->togli($f) : @unlink($f);
        }
        @rmdir($d);
    }

    private function online(): ServizioConvenzioneOnline
    {
        return $this->servizio(ServizioConvenzioneOnline::class);
    }

    private function repo(): ConvenzioneCompilataRepository
    {
        return $this->servizio(ConvenzioneCompilataRepository::class);
    }

    private function codice(int $prenotazioneId): string
    {
        return (string) $this->db->valore('SELECT codice_prenotazione FROM prenotazioni WHERE id = ?', [$prenotazioneId]);
    }

    private function nCompilazioni(): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_compilate');
    }

    /** L'anonimo prenota con l'email di Anna, tiene il link e scrive i dati: la compilazione è compilata ma NON rivendicata. @return array{0: int, 1: array<string, mixed>} */
    private function compilazioneDellAnonimo(): array
    {
        $p = $this->pren(200, ['email' => 'anna@scuola.it', 'nome' => 'Anonimo', 'cognome' => 'X', 'scuola_codice' => 'CSPS00001A']);
        $o = $this->online();
        $cc = $o->perToken((string) $o->apriDaCodice($this->codice($p))['token']);
        $o->salva($cc, $o->contesto($cc), ['denominazione' => 'Istituto Galilei', 'codice' => 'CSIS00001A', 'dirigente' => 'Dott. Segreto', 'cf' => '12345678901', 'email' => 'anna@scuola.it',
            'att' => ["pr$p" => ['scelta' => 1, 'studenti' => '10', 'tutor' => 'Prof. Rossi']]], null);
        $this->mailer->inviate = [];

        return [$p, $o->perToken((string) $cc['token'])];
    }

    /** Una compilazione già conclusa (protocollata) con i dati di prima e i suoi referenti verificati. */
    private function conclusa(array $proprietari, bool $rivendicata = true): int
    {
        return $this->db->inserisci(
            "INSERT INTO convenzioni_compilate (token, scuola_codice, email, stato_iter, dati_json, proprietari_json, nascita_ok, rivendicata_il, protocollo, protocollata_il) VALUES (?, 'CSIS00001A', ?, 'protocollata', ?, ?, 0, " . ($rivendicata ? 'NOW()' : 'NULL') . ", '0001', NOW())",
            [bin2hex(random_bytes(8)), $proprietari[0] ?? '', '{"scuola":{"denominazione":"Istituto Galilei","dirigente":"Dott. Riservato Di Prima","cf":"12345678901"},"attivita":[{"pr":1}]}', json_encode($proprietari)]
        );
    }

    // ───────────────────────────── W3: l'ufficio riprende una compilazione non rivendicata ─────────────────────────────

    public function testAvvioDellUfficioChiudeIlLinkEIlCodiceDelLAnonimo(): void
    {
        [$p, $cc] = $this->compilazioneDellAnonimo();
        $tokenAnonimo = (string) $cc['token'];
        $this->assertSame(1, (int) $cc['nascita_ok']);

        $es = $this->online()->avviaDalPannello('CSIS00001A', 'anna@scuola.it', 'ufficio@unical.it');

        $this->assertNull($es['errore']);
        $this->assertSame(1, $this->nCompilazioni(), 'si riprende quella che c\'è, non se ne apre una seconda');
        $nuova = $this->repo()->perId((int) $cc['id']);
        $this->assertNotSame($tokenAnonimo, $nuova['token'], 'il link è cambiato');
        $this->assertNull($this->online()->perToken($tokenAnonimo), 'il link dell\'anonimo non apre più');
        $this->assertSame([], $this->online()->proprietari($nuova), 'nessun proprietario: lo diventa chi apre con l\'email verificata');
        $this->assertSame(0, (int) $nuova['nascita_ok'], 'il codice di nascita non apre più');
        $this->assertNull($nuova['rivendicata_il']);
        $this->assertSame(['token' => null, 'segnalata' => true, 'riprova' => false], $this->online()->apriDaCodice($this->codice($p), null), 'il codice dell\'anonimo non dà più il link');
        $this->assertCount(1, $this->mailer->inviate);
        $this->assertSame('anna@scuola.it', $this->mailer->inviate[0]['a']);
        $this->assertStringContainsString('t=' . $nuova['token'], $this->mailer->inviate[0]['corpo'], 'il link nuovo va solo nell\'email');
        $this->assertStringNotContainsString($tokenAnonimo, $this->mailer->inviate[0]['corpo']);
    }

    public function testUnaCompilazioneRivendicataDaQuellEmailSiRiprendeSenzaCambiareIlLink(): void
    {
        [, $cc] = $this->compilazioneDellAnonimo();
        $this->db->esegui('UPDATE utenti SET email = ? WHERE id = 1', ['anna@scuola.it']);
        $this->repo()->rivendica((int) $cc['id'], 'anna@scuola.it', 'tokendianna0000000000000000000000');

        $es = $this->online()->avviaDalPannello('CSIS00001A', 'anna@scuola.it', 'ufficio@unical.it');

        $this->assertNull($es['errore']);
        $this->assertSame('ripresa', $es['esito']);
        $this->assertSame(1, $this->nCompilazioni());
        $this->assertSame('tokendianna0000000000000000000000', $this->repo()->perId((int) $cc['id'])['token'], 'il token non è cambiato');
        $this->assertStringContainsString('t=tokendianna0000000000000000000000', $this->mailer->inviate[0]['corpo']);
        $this->assertSame(['anna@scuola.it'], $this->online()->proprietari($this->repo()->perId((int) $cc['id'])));
    }

    public function testUnaCompilazioneRifiutataNonRivendicataCambiaIlLink(): void
    {
        [, $cc] = $this->compilazioneDellAnonimo();
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'rifiutata' WHERE id = ?", [$cc['id']]);

        $this->online()->avviaDalPannello('CSIS00001A', 'anna@scuola.it', 'ufficio@unical.it');

        $this->assertNull($this->online()->perToken((string) $cc['token']));
        $this->assertSame(1, $this->nCompilazioni());
    }

    public function testOltreLaFaseDiCompilazioneIlLinkNonCambia(): void
    {
        [, $cc] = $this->compilazioneDellAnonimo();
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'caricata_scuola' WHERE id = ?", [$cc['id']]);

        $this->online()->avviaDalPannello('CSIS00001A', 'anna@scuola.it', 'ufficio@unical.it');

        $this->assertSame($cc['token'], $this->repo()->perId((int) $cc['id'])['token'], 'dopo il caricamento del file il link non si cambia più');
    }

    public function testUnaVuotaENonAvviataDallUfficioNonSiRiprende(): void
    {
        $vuota = $this->repo()->crea(bin2hex(random_bytes(8)), 'CSPS00001A', 0, 'anna@scuola.it');

        $es = $this->online()->avviaDalPannello('CSIS00001A', 'anna@scuola.it', 'ufficio@unical.it');

        $this->assertNull($es['errore']);
        $this->assertSame('creata', $es['esito']);
        $this->assertSame(2, $this->nCompilazioni(), 'ne nasce una nuova');
        $this->assertNotSame((int) $vuota['id'], $es['id']);
        $this->assertSame($vuota['token'], $this->repo()->perId((int) $vuota['id'])['token'], 'la vuota di prima non è stata toccata');
        $this->assertStringContainsString('t=' . $this->repo()->perId($es['id'])['token'], $this->mailer->inviate[0]['corpo']);
    }

    // ───────────────────────────── W4: cosa blocca l'avvio ─────────────────────────────

    public function testUnaVuotaDiUnAltroNonBloccaLAvvio(): void
    {
        $this->repo()->crea(bin2hex(random_bytes(8)), 'CSPS00001A', 0, 'altro@scuola.it');

        $ant = $this->online()->anteprimaAvvio('CSIS00001A', 'anna@scuola.it');
        $es = $this->online()->avviaDalPannello('CSIS00001A', 'anna@scuola.it', 'ufficio@unical.it');

        $this->assertSame('creare', $ant['azione']);
        $this->assertNull($es['errore'], 'una vuota di altri non blocca');
        $this->assertSame('creata', $es['esito']);
    }

    public function testUnaCompilataDiUnAltroBloccaESiSaQuale(): void
    {
        [, $cc] = $this->compilazioneDellAnonimo();

        $ant = $this->online()->anteprimaAvvio('CSIS00001A', 'altro@scuola.it');
        $es = $this->online()->avviaDalPannello('CSIS00001A', 'altro@scuola.it', 'ufficio@unical.it');

        $this->assertSame('bloccata', $ant['azione']);
        $this->assertSame((int) $cc['id'], $ant['compilazione_id'], 'la scheda sa quale compilazione blocca');
        $this->assertSame('anna@scuola.it', $ant['referente_attuale']);
        $this->assertNotNull($es['errore']);
        $this->assertStringContainsString('Cambia referente', (string) $es['errore']);
        $this->assertSame(1, $this->nCompilazioni());
        $this->assertCount(0, $this->mailer->inviate);
    }

    public function testDopoUnAvvioMaiAperto_UnSecondoReferenteHaLaSuaStrada(): void
    {
        $this->online()->avviaDalPannello('CSIS00001A', 'primo@scuola.it', 'ufficio@unical.it');
        $id = (int) $this->db->valore('SELECT id FROM convenzioni_compilate LIMIT 1');
        $this->mailer->inviate = [];

        $ant = $this->online()->anteprimaAvvio('CSIS00001A', 'secondo@scuola.it');
        $this->assertSame('bloccata', $ant['azione'], 'l\'avvio mai aperto occupa la scuola: non se ne apre una seconda');
        $this->assertSame($id, $ant['compilazione_id']);

        // la strada: «Cambia referente» passa la stessa compilazione al secondo, che riceve il link nuovo; il primo non apre più
        $tokenPrima = (string) $this->repo()->perId($id)['token'];
        $errore = $this->servizio(ServizioReferenteConvenzione::class)->cambia($id, 'secondo@scuola.it', 'ufficio@unical.it');
        $this->assertNull($errore);
        $this->assertNull($this->online()->perToken($tokenPrima));
        $dopo = $this->online()->anteprimaAvvio('CSIS00001A', 'secondo@scuola.it');
        $this->assertSame('riprendere', $dopo['azione']);
        $this->assertSame(1, $this->nCompilazioni());
    }

    // ───────────────────────────── W5: il rinnovo dalla scheda ─────────────────────────────

    public function testAnteprimaDelRinnovoDiceSeICopiano(): void
    {
        $this->conclusa(['prof@scuola.it', 'vecchio@scuola.it']);

        $siCopia = $this->online()->anteprimaAvvio('CSIS00001A', 'prof@scuola.it');
        $noCopia = $this->online()->anteprimaAvvio('CSIS00001A', 'nuovo@scuola.it');

        $this->assertTrue($siCopia['copia']['disponibile']);
        $this->assertTrue($siCopia['copia']['permessa'], 'era già proprietario verificato');
        $this->assertSame('Dott. Riservato Di Prima', $siCopia['copia']['dirigente'], 'l\'anteprima dice quale Dirigente si copia');
        $this->assertTrue($noCopia['copia']['disponibile']);
        $this->assertFalse($noCopia['copia']['permessa'], 'un indirizzo nuovo non eredita i dati di prima');
        $this->assertSame('', $noCopia['copia']['dirigente'], 'e l\'anteprima non rivela il Dirigente');
    }

    public function testSenzaUnaConclusaNonCeNienteDaCopiare(): void
    {
        $this->assertFalse($this->online()->anteprimaAvvio('CSIS00001A', 'prof@scuola.it')['copia']['disponibile']);
    }

    public function testIlRinnovoCopiaSoloSeLaCasellaEAccesaEIlReferenteEraProprietario(): void
    {
        $this->conclusa(['prof@scuola.it', 'vecchio@scuola.it']);

        $es = $this->online()->avviaDalPannello('CSIS00001A', 'prof@scuola.it', 'ufficio@unical.it', true);

        $this->assertNull($es['errore']);
        $nuova = $this->repo()->perId($es['id']);
        $dati = json_decode((string) $nuova['dati_json'], true);
        $this->assertSame('Dott. Riservato Di Prima', $dati['scuola']['dirigente'] ?? null, 'i dati di prima (Dirigente compreso) sono stati copiati');
        $this->assertSame([], $dati['attivita'] ?? null, 'le attività no: sono quelle del periodo che si rinnova');
        $this->assertSame(['prof@scuola.it'], $this->online()->proprietari($nuova), 'i vecchi referenti NON restano proprietari, solo quello scelto');
        $this->assertSame(0, (int) $nuova['nascita_ok']);
    }

    public function testConLaCasellaSpentaNonSiCopiaNienteMaIlReferenteRestaProprietario(): void
    {
        $this->conclusa(['prof@scuola.it', 'vecchio@scuola.it']);

        $es = $this->online()->avviaDalPannello('CSIS00001A', 'prof@scuola.it', 'ufficio@unical.it', false);

        $nuova = $this->repo()->perId($es['id']);
        $this->assertTrue($nuova['dati_json'] === null || $nuova['dati_json'] === '' || empty(json_decode((string) $nuova['dati_json'], true)['scuola'] ?? null), 'nessun dato copiato');
        $this->assertSame(['prof@scuola.it'], $this->online()->proprietari($nuova));
    }

    public function testUnReferenteNuovoNonEreditaNeiDatiNeiPermessiAnchePremendoLaCasella(): void
    {
        $this->conclusa(['prof@scuola.it', 'vecchio@scuola.it']);

        $es = $this->online()->avviaDalPannello('CSIS00001A', 'nuovo@scuola.it', 'ufficio@unical.it', true);

        $nuova = $this->repo()->perId($es['id']);
        $this->assertTrue($nuova['dati_json'] === null || $nuova['dati_json'] === '' || empty(json_decode((string) $nuova['dati_json'], true)['scuola'] ?? null), 'la casella da sola non basta: il servizio ricontrolla');
        $this->assertSame([], $this->online()->proprietari($nuova), 'non è ancora proprietario: lo diventa verificando l\'email');
        $this->assertNull($nuova['rivendicata_il']);
    }

    public function testUnaConclusaNonRivendicataNonDaDiritto(): void
    {
        $this->conclusa(['prof@scuola.it'], false);

        $es = $this->online()->avviaDalPannello('CSIS00001A', 'prof@scuola.it', 'ufficio@unical.it', true);

        $dati = json_decode((string) ($this->repo()->perId($es['id'])['dati_json'] ?? ''), true);
        $this->assertEmpty($dati['scuola'] ?? null, 'chi non era proprietario verificato non eredita i dati');
    }

    public function testLEmailDelRinnovoNonContieneDati(): void
    {
        $this->conclusa(['prof@scuola.it']);

        $this->online()->avviaDalPannello('CSIS00001A', 'prof@scuola.it', 'ufficio@unical.it', true);

        $mail = (string) json_encode($this->mailer->inviate[0], JSON_UNESCAPED_UNICODE);
        foreach (['Dott. Riservato Di Prima', '12345678901', 'Riservato'] as $dato) {
            $this->assertStringNotContainsString($dato, $mail, 'la mail ha solo il link, nessun dato della scuola');
        }
        $this->assertStringContainsString('convenzione_online.php?t=', $mail);
    }
}
