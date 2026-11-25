<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Auth\UtenteRepository;
use App\Core\Sito;
use App\Fsl\ConvenzioneCompilataRepository;
use App\Fsl\ServizioConvenzioneOnline;
use App\Fsl\ServizioUnioneConvenzioni;
use App\Iscrizioni\RegistroOperazioni;

/**
 * Ritocchi al passo 4: una compilazione nata da una prenotazione senza email verificata non è «di» quell'email finché il titolare non la rivendica
 * (R1), nell'Area personale conta solo ciò che è davvero della persona (R2), le scuole senza codice usano lo stesso blocco (R3), l'unione si ferma
 * se non riesce a segnare lo stato (R4).
 */
final class RivendicazioneTest extends FslBase
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
        $this->radiceProva = sys_get_temp_dir() . '/fsl_riv_' . bin2hex(random_bytes(4));
        mkdir($this->radiceProva . '/uploads/convenzioni_compilate', 0755, true);
        $this->c->istanza(Sito::class, new Sito($this->radiceProva, 'https://portale.test/eventi'));
        $this->c->istanza(\App\Tutorato\FirmaRemota::class, new \Tests\Doppi\FirmaRemotaFinta());
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

    private function codice(int $prenotazioneId): string
    {
        return (string) $this->db->valore('SELECT codice_prenotazione FROM prenotazioni WHERE id = ?', [$prenotazioneId]);
    }

    private function nCompilazioni(): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_compilate');
    }

    /**
     * Un anonimo prenota con l'email di Anna, tiene il link e scrive i dati della scuola: la compilazione non è rivendicata.
     *
     * @return array{0: int, 1: array<string, mixed>} [prenotazione dell'anonimo, compilazione]
     */
    private function compilazioneDellAnonimo(string $scuola = 'CSPS00001A'): array
    {
        $p = $this->pren(200, ['email' => 'anna@scuola.it', 'nome' => 'Anonimo', 'cognome' => 'X', 'scuola_codice' => $scuola]);
        $o = $this->online();
        $cc = $o->perToken((string) $o->apriDaCodice($this->codice($p))['token']);
        $this->assertSame([], $o->proprietari($cc), 'senza email verificata nessuno è proprietario');
        $this->assertSame(1, (int) $cc['nascita_ok']);
        $this->assertNull($cc['rivendicata_il']);
        $esito = $o->salva($cc, $o->contesto($cc), ['denominazione' => 'Istituto Galilei', 'codice' => 'CSIS00001A', 'dirigente' => 'Dott. Segreto', 'cf' => '12345678901', 'email' => 'anna@scuola.it',
            'att' => ["pr$p" => ['scelta' => 1, 'studenti' => '10', 'tutor' => 'Prof. Rossi']]], null);
        $this->assertSame([], $esito['errori']);
        $this->mailer->inviate = [];

        return [$p, $o->perToken((string) $cc['token'])];
    }

    /** @return list<array{azione: string, dettagli: array<string, mixed>}> */
    private function operazioni(): array
    {
        /** @var \Tests\Doppi\IscrizioniRegistroFinto $r */
        $r = $this->c->get(RegistroOperazioni::class);

        return $r->voci;
    }

    /** Dopo la rivendicazione di Anna: il link e il codice dell'anonimo non aprono più, e non vedono niente di ciò che Anna scrive. */
    private function verificaChiusoAllAnonimo(int $prenotazioneAnonimo, string $tokenAnonimo, string $tokenAnna): void
    {
        $o = $this->online();
        $this->assertNotSame($tokenAnonimo, $tokenAnna, 'il link è cambiato');
        $this->assertNull($o->perToken($tokenAnonimo), 'il link dell\'anonimo non apre più');
        $cc = $o->perToken($tokenAnna);
        $this->assertNotNull($cc);
        $this->assertSame(['anna@scuola.it'], $o->proprietari($cc));
        $this->assertNotNull($cc['rivendicata_il']);
        $this->assertSame(0, (int) $cc['nascita_ok']);
        // Anna scrive un dato nuovo
        $ctx = $o->contesto($cc);
        $o->salva($cc, $ctx, ['denominazione' => 'Istituto Galilei', 'codice' => 'CSIS00001A', 'dirigente' => 'Dott. Riservato Di Anna', 'email' => 'anna@scuola.it',
            'att' => ["pr$prenotazioneAnonimo" => ['scelta' => 1, 'studenti' => '10', 'tutor' => 'Prof. Rossi']]], null);
        // il codice dell'anonimo: nessun token, niente dati (la risposta è quella di chi non è proprietario)
        $r = $o->apriDaCodice($this->codice($prenotazioneAnonimo), null);
        $this->assertNull($r['token'], 'il codice dell\'anonimo non apre più');
        $this->assertSame(['token' => null, 'segnalata' => true, 'riprova' => false], $r);
        $this->assertSame([], $this->areaPersonale([$prenotazioneAnonimo], null, 0), 'e per lui l\'Area personale non mostra niente');
        foreach ($this->mailer->inviate as $m) {
            $this->assertStringNotContainsString('Dott. Riservato Di Anna', (string) json_encode($m, JSON_UNESCAPED_UNICODE));
        }
        $this->assertNull($o->perToken($tokenAnonimo));
    }

    // ───────────────────────── R1: i cinque modi in cui Anna entra con l'email verificata ─────────────────────────

    public function testAnnaConIlSuoCodice(): void
    {
        [$p, $cc] = $this->compilazioneDellAnonimo();
        $q = $this->pren(100, ['email' => 'anna@scuola.it']);

        $r = $this->online()->apriDaCodice($this->codice($q), 'anna@scuola.it');

        $this->assertNotNull($r['token']);
        $this->assertFalse($r['segnalata']);
        $this->verificaChiusoAllAnonimo($p, (string) $cc['token'], (string) $r['token']);
        $this->assertSame(1, $this->nCompilazioni());
    }

    public function testAnnaDalCodiceDellAnonimo(): void
    {
        [$p, $cc] = $this->compilazioneDellAnonimo();

        $r = $this->online()->apriDaCodice($this->codice($p), 'anna@scuola.it');

        $this->assertNotNull($r['token'], 'anche la prenotazione di nascita passa dalla rivendicazione prima di dare il token');
        $this->verificaChiusoAllAnonimo($p, (string) $cc['token'], (string) $r['token']);
    }

    public function testAnnaDalProgramma(): void
    {
        [$p, $cc] = $this->compilazioneDellAnonimo();
        $q = $this->pren(100, ['email' => 'anna@scuola.it']);

        $r = $this->online()->accogliProgramma(['denominazione' => 'Istituto Galilei', 'email' => 'anna@scuola.it'], null, [['pr' => $q, 'studenti' => 7, 'tutor' => 'Prof. Verdi']], $q, 'CSPS00001A', 'anna@scuola.it', 'no', 'anna@scuola.it');

        $this->assertNotNull($r['cc']);
        $this->verificaChiusoAllAnonimo($p, (string) $cc['token'], (string) $r['cc']['token']);
    }

    public function testAnnaDalRinnovo(): void
    {
        [$p, $vecchia] = $this->compilazioneDellAnonimo();
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'protocollata' WHERE id = ?", [$vecchia['id']]);
        $vecchia = $this->riga('convenzioni_compilate', (int) $vecchia['id']);
        $o = $this->online();
        // l'anonimo, con il link della convenzione conclusa, avvia il rinnovo: la nuova compilazione è sua (nessuno l'ha rivendicata)
        $anonimo = $o->rinnova($vecchia, null);
        $this->assertNotNull($anonimo['token']);
        $nuova = $o->perToken((string) $anonimo['token']);
        $this->assertSame(0, (int) $nuova['nascita_ok'], 'nel rinnovo il codice di nascita non apre mai');
        $this->assertSame([], $o->proprietari($nuova), 'e l\'email della prenotazione non è proprietaria se non è verificata');
        $this->mailer->inviate = [];

        $r = $o->rinnova($vecchia, 'anna@scuola.it');

        $this->assertNotNull($r['token']);
        $this->assertNull($o->perToken((string) $anonimo['token']), 'il link del rinnovo dell\'anonimo non apre più');
        $cc = $o->perToken((string) $r['token']);
        $this->assertSame((int) $nuova['id'], (int) $cc['id'], 'è la stessa compilazione, rivendicata');
        $this->assertSame(['anna@scuola.it'], $o->proprietari($cc));
        $this->assertNotNull($cc['rivendicata_il']);
    }

    public function testAnnaDallAreaPersonale(): void
    {
        [$p, $cc] = $this->compilazioneDellAnonimo();
        $prima = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $o = $this->online();

        // l'Area personale (una GET) non cambia niente e non contiene il token: porta all'apertura per codice
        $voci = $this->areaPersonale([$p], 'anna@scuola.it');
        $this->assertCount(1, $voci, 'è la sua email, la vede');
        $this->assertSame('', $voci[0]['token']);
        $this->assertSame($this->codice($p), $voci[0]['apri_codice']);
        $this->assertFalse($voci[0]['rivendicata']);
        $dopo = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame($prima, $dopo, 'una GET dell\'Area personale non cambia nulla');
        $this->assertSame([], $this->mailer->inviate);
        $this->assertStringNotContainsString((string) $cc['token'], (string) json_encode($voci));

        // il clic su «Apri la convenzione» (il codice) fa la rivendicazione
        $r = $o->apriDaCodice($voci[0]['apri_codice'], 'anna@scuola.it');

        $this->verificaChiusoAllAnonimo($p, (string) $cc['token'], (string) $r['token']);
        $dopoRiv = $this->areaPersonale([$p], 'anna@scuola.it');
        $this->assertSame([(string) $r['token']], array_column($dopoRiv, 'token'), 'ora rivendicata: l\'Area personale ha il link');
    }

    // ───────────────────────── R1: dettagli ─────────────────────────

    public function testCreaDaChiHaLEmailVerificataUgualeEDaChiNo(): void
    {
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);

        $mia = $repo->crea(str_repeat('a', 32), 'CSPS00001A', 1, 'Anna@Scuola.it', 'anna@scuola.it');
        $altra = $repo->crea(str_repeat('b', 32), 'CSPS00001A', 2, 'anna@scuola.it', 'carlo@altra.it');
        $anonima = $repo->crea(str_repeat('c', 32), 'CSPS00001A', 3, 'anna@scuola.it');

        $this->assertSame(['["anna@scuola.it"]', 0], [$mia['proprietari_json'], (int) $mia['nascita_ok']]);
        $this->assertNotNull($mia['rivendicata_il']);
        foreach ([$altra, $anonima] as $c) {
            $this->assertSame(['[]', 1], [$c['proprietari_json'], (int) $c['nascita_ok']]);
            $this->assertNull($c['rivendicata_il']);
        }
        // rinnovo: nascita_ok sempre 0; restano i proprietari della precedente, mai l'email non verificata della prenotazione
        $rinn = $repo->crea(str_repeat('d', 32), 'CSPS00001A', 4, 'anna@scuola.it', null, ['dora@scuola.it']);
        $this->assertSame(['["dora@scuola.it"]', 0], [$rinn['proprietari_json'], (int) $rinn['nascita_ok']]);
        $rinnVuota = $repo->crea(str_repeat('e', 32), 'CSPS00001A', 5, 'anna@scuola.it', null, []);
        $this->assertSame(['[]', 0], [$rinnVuota['proprietari_json'], (int) $rinnVuota['nascita_ok']]);
        $rinnVer = $repo->crea(str_repeat('f', 32), 'CSPS00001A', 6, 'anna@scuola.it', 'anna@scuola.it', []);
        $this->assertSame(['["anna@scuola.it"]', 0], [$rinnVer['proprietari_json'], (int) $rinnVer['nascita_ok']]);
    }

    public function testLaRivendicazioneVaNelRegistroECambiaIlTokenUnaSolaVolta(): void
    {
        [$p, $cc] = $this->compilazioneDellAnonimo();
        $o = $this->online();

        $t1 = $o->apriDaCodice($this->codice($p), 'anna@scuola.it')['token'];
        $t2 = $o->apriDaCodice($this->codice($p), 'anna@scuola.it')['token'];

        $this->assertSame($t1, $t2, 'la seconda volta non cambia più');
        $voci = array_values(array_filter($this->operazioni(), static fn (array $v): bool => str_contains($v['azione'], 'rivendicata')));
        $this->assertCount(1, $voci);
        $this->assertSame((int) $cc['id'], $voci[0]['dettagli']['Compilazione']);
        $this->assertSame('anna@scuola.it', $voci[0]['dettagli']['Email']);
        $this->assertCount(1, $this->mailer->inviate);
        $this->assertSame('anna@scuola.it', $this->mailer->inviate[0]['a']);
        $this->assertStringContainsString($t1, $this->mailer->inviate[0]['corpo']);
    }

    public function testSiRivendicaSoloVuotaORifiutataEDagliAltriStatiNonCambiaIlToken(): void
    {
        $o = $this->online();
        foreach (['rifiutata' => true, 'caricata_scuola' => false, 'in_carico' => false, 'da_firmare' => false, 'firmata_direttore' => false] as $stato => $cambia) {
            $this->db->esegui('DELETE FROM convenzioni_compilate');
            [$p, $cc] = $this->compilazioneDellAnonimo();
            $this->db->esegui('UPDATE convenzioni_compilate SET stato_iter = ? WHERE id = ?', [$stato, $cc['id']]);

            $r = $o->apriDaCodice($this->codice($p), 'anna@scuola.it');

            if ($cambia) {
                $this->assertNotSame($cc['token'], $r['token'], $stato);
            } else {
                $this->assertSame($cc['token'], $r['token'], "$stato: si apre senza cambiare il token (l'email verificata della compilazione la apre)");
                $this->assertNull($this->riga('convenzioni_compilate', (int) $cc['id'])['rivendicata_il'], $stato);
            }
        }
    }

    public function testUnaRigaVecchiaSenzaElencoSiRivendicaComeLeAltre(): void
    {
        [$p, $cc] = $this->compilazioneDellAnonimo();
        // come una riga di prima della v139: nessun elenco di proprietari, nessuna rivendicazione
        $this->db->esegui('UPDATE convenzioni_compilate SET proprietari_json = NULL, nascita_ok = 1, rivendicata_il = NULL WHERE id = ?', [$cc['id']]);
        $q = $this->pren(100, ['email' => 'anna@scuola.it']);
        $o = $this->online();

        // per l'anonimo e per un'altra email verificata (quella di chi ha scritto in una prenotazione) non apre
        $this->assertNull($o->apriDaCodice($this->codice($q), 'carlo@altra.it')['token']);
        $r = $o->apriDaCodice($this->codice($q), 'anna@scuola.it');

        $this->assertNotSame($cc['token'], $r['token']);
        $riga = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame(['anna@scuola.it'], $o->proprietari($riga));
        $this->assertNotNull($riga['rivendicata_il']);
    }

    public function testIlRinnovoNonRiprendeUnaCompilazioneVuotaENonRivendicata(): void
    {
        [$p, $vecchia] = $this->compilazioneDellAnonimo();
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'protocollata' WHERE id = ?", [$vecchia['id']]);
        $vecchia = $this->riga('convenzioni_compilate', (int) $vecchia['id']);
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);
        // una compilazione in corso dello stesso istituto, della stessa email, ma mai compilata e di nessuno
        $vuota = $repo->crea(str_repeat('9', 32), 'CSPS00001A', $this->pren(201, ['email' => 'anna@scuola.it']), 'anna@scuola.it');
        $this->assertNull($vuota['dati_json']);
        $o = $this->online();

        $r = $o->rinnova($vecchia, null);

        $this->assertNotNull($r['token']);
        $this->assertNotSame($vuota['token'], $r['token'], 'la vuota non si riprende');
        $this->assertSame(3, $this->nCompilazioni(), 'se ne crea una nuova');
        $nuova = $o->perToken((string) $r['token']);
        $this->assertNotNull($nuova['dati_json'], 'e parte dai dati della precedente');
    }

    // ───────────────────────── R2: l'Area personale e l'email dell'account ─────────────────────────

    public function testNellAreaPersonaleLaNascitaValeSoloPerLaPrenotazioneDellUtenteOConEmailVerificata(): void
    {
        [$p, $cc] = $this->compilazioneDellAnonimo();
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'caricata_scuola', file_firmato = 'x.pdf' WHERE id = ?", [$cc['id']]);

        // un account con la stessa email NON verificata (come lo vede il portale: null): la prenotazione non è sua
        $this->assertSame([], $this->areaPersonale([$p], null, 55), 'utente_id diverso e email non verificata: niente');
        $this->db->esegui('UPDATE prenotazioni SET utente_id = 55 WHERE id = ?', [$p]);
        $this->assertCount(1, $this->areaPersonale([$p], null, 55), 'prenotata da quell\'account');
        $this->assertSame([], $this->areaPersonale([$p], null, 56), 'un altro account no');
        $this->db->esegui('UPDATE prenotazioni SET utente_id = NULL WHERE id = ?', [$p]);
        $this->assertCount(1, $this->areaPersonale([$p], 'anna@scuola.it', 99), 'con l\'email verificata uguale a quella della prenotazione sì');
        $this->assertSame([], $this->areaPersonale([$p], 'carlo@altra.it', 99), 'con un\'altra email verificata no');
    }

    public function testNellAreaPersonaleIlCodiceDelleAltrePrenotazioniNonSiVede(): void
    {
        [$p, $cc] = $this->compilazioneDellAnonimo();
        $o = $this->online();
        // Anna rivendica, poi un'altra attività della scuola entra nell'Allegato A (prenotata da un collega)
        $q = $this->pren(100, ['email' => 'anna@scuola.it']);
        $o->apriDaCodice($this->codice($q), 'anna@scuola.it');
        $collega = $this->pren(201, ['email' => 'collega@scuola.it']);
        $cc = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $dati = json_decode((string) $cc['dati_json'], true);
        $dati['attivita'][] = ['pr' => $collega, 'studenti' => 3, 'tutor' => 'C', 'il' => '2026-10-05 10:00:00'];
        $this->db->esegui('UPDATE convenzioni_compilate SET dati_json = ? WHERE id = ?', [json_encode($dati), $cc['id']]);

        $voci = $this->areaPersonale([$q], 'anna@scuola.it');

        $this->assertCount(1, $voci);
        $codici = array_column($voci[0]['attivita'], 'codice', 'titolo');
        $mie = array_filter($voci[0]['attivita'], static fn (array $a): bool => $a['mia']);
        $altre = array_filter($voci[0]['attivita'], static fn (array $a): bool => !$a['mia']);
        $this->assertNotEmpty($altre);
        foreach ($altre as $a) {
            $this->assertSame('', $a['codice'], 'delle prenotazioni di altri si vedono titolo e stato, mai il codice');
        }
        foreach ($mie as $a) {
            $this->assertNotSame('', $a['codice']);
        }
        $this->assertStringNotContainsString($this->codice($collega), (string) json_encode($voci));
        $this->assertStringNotContainsString($this->codice($p), (string) json_encode($voci), 'nemmeno quello della prenotazione dell\'anonimo');
        unset($codici);
    }

    public function testIlPannelloCheCambiaLEmailTogliLaVerificaSeDiversa(): void
    {
        $this->db->esegui('ALTER TABLE utenti ADD COLUMN ruoli_secondari VARCHAR(100) NULL, ADD COLUMN email_personalizzata TINYINT NOT NULL DEFAULT 0, ADD COLUMN email_verificata_il DATETIME NULL');
        $this->db->esegui("INSERT INTO utenti (id, email, ruolo_id, email_personalizzata, email_verificata_il) VALUES (5, 'anna@scuola.it', 5, 1, '2026-10-01 10:00:00')");
        $repo = new UtenteRepository($this->db);

        $repo->aggiornaDaAdmin(5, 5, '', null, '', 'ANNA@scuola.it');   // stessa email (maiuscole): la verifica resta
        $this->assertNotNull($this->riga('utenti', 5)['email_verificata_il']);
        $this->assertSame('anna@scuola.it', UtenteRepository::emailVerificata($this->riga('utenti', 5)));

        $repo->aggiornaDaAdmin(5, 5, '', null, '', 'altra@scuola.it');  // un'altra: la verifica torna NULL
        $riga = $this->riga('utenti', 5);
        $this->assertSame('altra@scuola.it', $riga['email']);
        $this->assertNull($riga['email_verificata_il']);
        $this->assertNull(UtenteRepository::emailVerificata($riga), 'l\'email messa dal pannello non è verificata');

        $this->db->esegui("UPDATE utenti SET email_verificata_il = '2026-10-02 10:00:00' WHERE id = 5");
        $repo->aggiornaDaAdmin(5, 5, '', null, '', null);   // senza email nuova non si tocca
        $this->assertNotNull($this->riga('utenti', 5)['email_verificata_il']);
    }

    // ───────────────────────── R3: le scuole senza codice meccanografico ─────────────────────────

    public function testSenzaCodiceMeccanograficoTutteLeScrittureUsanoLoStessoBlocco(): void
    {
        $p = $this->pren(200, ['email' => 'anna@scuola.it', 'scuola_codice' => null]);
        $q = $this->pren(100, ['email' => 'anna@scuola.it', 'scuola_codice' => null]);
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);
        $o = $this->online();
        $cc = $o->perToken((string) $o->apriDaCodice($this->codice($p))['token']);
        $this->assertSame('prenotazione_' . $p, $repo->chiaveDi($cc), 'la chiave è quella della prenotazione di nascita (come l\'apertura dal codice e il programma)');
        // il programma (una compilazione con più prenotazioni): la chiave è quella della sua prima prenotazione, la stessa di chi la salva dopo
        $ccP = $o->accogliProgramma(['denominazione' => 'X'], null, [['pr' => $q, 'studenti' => 1, 'tutor' => '']], $q, null, 'anna@scuola.it', 'no')['cc'];
        $this->assertSame('prenotazione_' . $q, $repo->chiaveDi($ccP));
        $r = $this->pren(201, ['email' => 'anna@scuola.it', 'scuola_codice' => null]);
        $this->db->esegui('UPDATE convenzioni_compilate SET dati_json = ? WHERE id = ?', [json_encode(['scuola' => ['denominazione' => 'X'], 'attivita' => [['pr' => $q, 'studenti' => 1, 'tutor' => ''], ['pr' => $r, 'studenti' => 1, 'tutor' => '']]]), $ccP['id']]);
        $ccP = $this->riga('convenzioni_compilate', (int) $ccP['id']);

        // un altro processo tiene il blocco di quella compilazione: l'apertura dal codice (anche con il codice di una prenotazione che non è quella di nascita),
        // il salvataggio e il rinnovo non procedono e non cambiano niente
        $db = getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit';
        $altra = new \mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''), $db);
        $altra->query("SELECT GET_LOCK('" . $altra->real_escape_string(ConvenzioneCompilataRepository::nomeBlocco('prenotazione_' . $q, $db)) . "', 0)");
        $prima = $this->riga('convenzioni_compilate', (int) $ccP['id']);
        try {
            $this->assertTrue($o->apriDaCodice($this->codice($q))['riprova'], 'dal codice della prenotazione di nascita');
            $this->assertTrue($o->apriDaCodice($this->codice($r))['riprova'], 'dal codice di un\'altra prenotazione della stessa compilazione');
            $esito = $o->salva($ccP, $o->contesto($ccP), ['denominazione' => 'CAMBIATO', 'att' => ["pr$q" => ['scelta' => 1]]], null);
            $this->assertStringContainsString('qualche secondo', implode(' ', $esito['errori']), 'salva');
            $this->assertTrue($o->rinnova(['id' => $ccP['id'], 'scuola_codice' => null, 'prenotazione_id' => $q, 'stato_iter' => 'protocollata', 'email' => 'anna@scuola.it'])['riprova'], 'rinnova');
            $this->assertSame($prima, $this->riga('convenzioni_compilate', (int) $ccP['id']));
            $this->assertSame(2, $this->nCompilazioni());
        } finally {
            $altra->close();
        }
    }

    // ───────────────────────── R4: l'unione si ferma se non riesce a segnare lo stato ─────────────────────────

    public function testSegnaUnitaDiceSeHaAggiornatoELUnioneSiFermaSeNo(): void
    {
        $anna = $this->pren(200, ['email' => 'anna@scuola.it']);
        $carlo = $this->pren(201, ['email' => 'carlo@altra.it']);
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);
        $c1 = $repo->crea(str_repeat('1', 32), 'CSPS00001A', $anna, 'anna@scuola.it', 'anna@scuola.it');
        $c2 = $repo->crea(str_repeat('2', 32), 'CSPS00001A', $carlo, 'carlo@altra.it');
        $datiA = json_encode(['scuola' => ['denominazione' => 'A'], 'attivita' => [['pr' => $anna, 'studenti' => 5, 'tutor' => 'A']]]);
        $repo->salvaDati((int) $c1['id'], (string) $datiA, null, 'anna@scuola.it');
        $repo->salvaDati((int) $c2['id'], json_encode(['scuola' => ['denominazione' => 'B'], 'attivita' => [['pr' => $carlo, 'studenti' => 6, 'tutor' => 'C']]]), null, 'carlo@altra.it');
        $this->db->esegui("INSERT INTO convenzioni_messaggi (compilazione_id, da, testo) VALUES (?, 'scuola', 'Ciao')", [$c2['id']]);
        $this->mailer->inviate = [];

        // segnaUnita dice false se non ha aggiornato (l'assorbita è già protocollata o unita)
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'protocollata' WHERE id = ?", [$c2['id']]);
        $this->assertFalse($repo->segnaUnita((int) $c2['id'], (int) $c1['id']));
        $this->assertNull($this->riga('convenzioni_compilate', (int) $c2['id'])['unita_in']);
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = '' WHERE id = ?", [$c2['id']]);

        // il cambio di stato non riesce tra la lettura e la scrittura (un trigger annulla l'UPDATE di stato): l'unione si ferma PRIMA di spostare
        // attività, messaggi, proprietari ed email
        $this->db->comando("CREATE TRIGGER blocca_unione BEFORE UPDATE ON convenzioni_compilate FOR EACH ROW
            BEGIN IF NEW.stato_iter = 'unita' THEN SET NEW.stato_iter = OLD.stato_iter; SET NEW.unita_in = OLD.unita_in; END IF; END");
        try {
            $esito = $this->servizio(ServizioUnioneConvenzioni::class)->unisci((int) $c1['id'], (int) $c2['id']);
        } finally {
            $this->db->comando('DROP TRIGGER IF EXISTS blocca_unione');
        }
        $this->assertStringContainsString('non ho spostato niente', (string) $esito);
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_messaggi WHERE compilazione_id = ?', [$c2['id']]), 'i messaggi sono rimasti dove erano');
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_messaggi WHERE compilazione_id = ?', [$c1['id']]));
        $this->assertSame((string) $datiA, (string) $this->riga('convenzioni_compilate', (int) $c1['id'])['dati_json'], 'le attività non sono passate');
        $this->assertSame([], $this->mailer->inviate, 'nessuna email');
        $this->assertSame('', $this->riga('convenzioni_compilate', (int) $c2['id'])['stato_iter']);

        // con un'unione vera niente va perso e tutto è consistente
        $this->assertNull($this->servizio(ServizioUnioneConvenzioni::class)->unisci((int) $c1['id'], (int) $c2['id']));
        $this->assertSame('unita', $this->riga('convenzioni_compilate', (int) $c2['id'])['stato_iter']);
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_messaggi WHERE compilazione_id = ?', [$c1['id']]));
    }

    public function testUnaCompilazioneRivendicataNonSiAssorbeInUnaNonRivendicata(): void
    {
        $anna = $this->pren(200, ['email' => 'anna@scuola.it']);
        $carlo = $this->pren(201, ['email' => 'carlo@altra.it']);
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);
        $c1 = $repo->crea(str_repeat('1', 32), 'CSPS00001A', $anna, 'anna@scuola.it');
        $c2 = $repo->crea(str_repeat('2', 32), 'CSPS00001A', $carlo, 'carlo@altra.it', 'carlo@altra.it');
        $u = $this->servizio(ServizioUnioneConvenzioni::class);

        $this->assertStringContainsString('rivendicata', (string) $u->unisci((int) $c1['id'], (int) $c2['id']));
        $this->assertNull($u->unisci((int) $c2['id'], (int) $c1['id']), 'al contrario sì: resta quella rivendicata');
    }
}
