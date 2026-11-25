<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Auth\UtenteRepository;
use App\Core\Sito;
use App\Fsl\ConvenzioneCompilataRepository;
use App\Fsl\ServizioConvenzioneFirmata;
use App\Fsl\ServizioConvenzioneOnline;
use App\Fsl\ServizioReferenteConvenzione;
use App\Fsl\ServizioUnioneConvenzioni;

/**
 * Correzioni al passo 4: l'email scritta in una prenotazione non prova chi è (S1), nessuno occupa una scuola (S2), il blocco per istituto
 * è obbligatorio (S3), le unioni vietate (S4) e un solo criterio per «stesso istituto» (S5).
 */
final class CompilazioneSicurezzaTest extends FslBase
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
        $this->radiceProva = sys_get_temp_dir() . '/fsl_sic_' . bin2hex(random_bytes(4));
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

    /** @return list<int> */
    private function idAttivita(array $cc): array
    {
        return array_map(static fn (array $a): int => (int) ($a['pr'] ?? 0), json_decode((string) $cc['dati_json'], true)['attivita'] ?? []);
    }

    /**
     * La compilazione di Anna con i dati già scritti (come dopo «Prepara i documenti»): nata dalla sua prima prenotazione.
     *
     * @return array{0: int, 1: array<string, mixed>} [prenotazione di nascita, compilazione]
     */
    private function compilazioneDiAnna(string $scuola = 'CSPS00001A', string $email = 'anna@scuola.it'): array
    {
        $p = $this->pren(200, ['email' => $email, 'nome' => 'Anna', 'cognome' => 'Rossi', 'scuola_codice' => $scuola]);
        $o = $this->online();
        $cc = $o->perToken((string) $o->apriDaCodice($this->codice($p))['token']);
        $esito = $o->salva($cc, $o->contesto($cc), ['denominazione' => 'Istituto Galilei', 'codice' => 'CSIS00001A', 'dirigente' => 'Dott. Segreto', 'cf' => '12345678901', 'email' => $email,
            'att' => ["pr$p" => ['scelta' => 1, 'studenti' => '10', 'tutor' => 'Prof. Rossi']]], null);
        $this->assertSame([], $esito['errori']);
        $this->mailer->inviate = [];

        return [$p, $o->perToken((string) $cc['token'])];
    }

    // ───────────────────────── S1: l'email scritta in una prenotazione non prova chi è ─────────────────────────

    public function testUnaPrenotazioneAnonimaConLaStessaEmailNonRicevePiuIlToken(): void
    {
        [$a, $cc] = $this->compilazioneDiAnna();
        $b = $this->pren(100, ['email' => 'Anna@Scuola.it']);   // «Anna» (o chi scrive la sua email) prenota senza accedere
        $o = $this->online();

        $r = $o->apriDaCodice($this->codice($b));

        $this->assertNull($r['token'], 'nessun token a schermo (né nel reindirizzamento) a chi non ha fatto l\'accesso');
        $this->assertTrue($r['segnalata']);
        $this->assertNull($o->apriDaCodice($this->codice($b), null)['token'], 'e con l\'email non verificata (null) è lo stesso');
        $riga = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame([$a], $this->idAttivita($riga), 'l\'attività non entra nell\'Allegato A da sola');
        $this->assertSame([$b], array_column(json_decode((string) $riga['attivita_in_attesa'], true), 'pr'), 'resta da confermare');
        $this->assertSame(1, $this->nCompilazioni());
        // l'unica email con il link va all'indirizzo della compilazione
        foreach ($this->mailer->inviate as $m) {
            $this->assertSame('anna@scuola.it', $m['a']);
        }
        $this->assertCount(1, $this->mailer->inviate, 'una sola email: il limite è di una ogni 10 minuti per compilazione');
        $this->assertSame([], $this->areaPersonale([$b], null), 'nemmeno nell\'Area personale: la prenotazione non è quella di nascita');
    }

    public function testLaRispostaASchermoEUgualePerEmailUgualeEDiversa(): void
    {
        [$a, $cc] = $this->compilazioneDiAnna('CSPS00001A', 'anna@scuola.it');
        [$f, $cf] = $this->compilazioneDiAnna('CSPS00002B', 'dora@fermi.it');
        $stessa = $this->pren(100, ['email' => 'anna@scuola.it', 'scuola_codice' => 'CSPS00001A']);
        $diversa = $this->pren(100, ['email' => 'carlo@altra.it', 'scuola_codice' => 'CSPS00002B']);
        $o = $this->online();

        $this->mailer->inviate = [];
        $r1 = $o->apriDaCodice($this->codice($stessa));
        $mail1 = $this->mailer->inviate;
        $this->mailer->inviate = [];
        $r2 = $o->apriDaCodice($this->codice($diversa));
        $mail2 = $this->mailer->inviate;

        $this->assertSame($r1, $r2, 'stessa risposta a schermo per email uguale e diversa');
        $this->assertCount(1, $mail1);
        $this->assertCount(1, $mail2);
        $this->assertSame(['anna@scuola.it', 'dora@fermi.it'], [$mail1[0]['a'], $mail2[0]['a']], 'sempre e solo all\'indirizzo della compilazione');
        $this->assertSame($mail1[0]['oggetto'] ?? null, $mail2[0]['oggetto'] ?? null);
    }

    public function testConEmailVerificataUgualeALaCompilazioneLaRivendicaEAggiungeSubito(): void
    {
        [$a, $cc] = $this->compilazioneDiAnna();
        $b = $this->pren(100, ['email' => 'anna@scuola.it']);
        $o = $this->online();

        $r = $o->apriDaCodice($this->codice($b), 'ANNA@scuola.it');

        $this->assertNotSame($cc['token'], $r['token'], 'rivendicata: il link cambia, quello di chi aveva prenotato non vale più');
        $this->assertFalse($r['segnalata']);
        $riga = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame($r['token'], $riga['token']);
        $this->assertNotNull($riga['rivendicata_il']);
        $this->assertSame(['anna@scuola.it'], $o->proprietari($riga));
        $this->assertSame(0, (int) $riga['nascita_ok']);
        $this->assertSame([$a, $b], $this->idAttivita($riga));
        $this->assertCount(1, $this->mailer->inviate, 'il link nuovo parte per email, solo a chi ha la compilazione');
        $this->assertSame('anna@scuola.it', $this->mailer->inviate[0]['a']);
        $this->assertStringContainsString($r['token'], $this->mailer->inviate[0]['corpo']);
        $this->assertSame($r['token'], $o->apriDaCodice($this->codice($b), 'anna@scuola.it')['token'], 'la seconda volta è lo stesso link');
        $this->assertCount(1, $this->mailer->inviate, 'e non parte un\'altra email');
        $this->assertSame([$r['token']], array_column($this->areaPersonale([$b], 'anna@scuola.it'), 'token'));
    }

    public function testUnaEmailVerificataCheNonEProprietariaNonApre(): void
    {
        [$a, $cc] = $this->compilazioneDiAnna();
        $d = $this->pren(100, ['email' => 'carlo@altra.it']);
        $o = $this->online();

        $r = $o->apriDaCodice($this->codice($d), 'carlo@altra.it');

        $this->assertNull($r['token']);
        $this->assertTrue($r['segnalata']);
        $this->assertSame('anna@scuola.it', $this->mailer->inviate[0]['a']);
    }

    public function testLaPrenotazioneDiNascitaApreAnchePerChiNonHaFattoLAccesso(): void
    {
        [$a, $cc] = $this->compilazioneDiAnna();
        $o = $this->online();

        $r = $o->apriDaCodice($this->codice($a), null);

        $this->assertSame($cc['token'], $r['token']);
        // nell'Area personale di chi l'ha fatta (collegato, prenotazione sua) niente token: si apre dal codice, che rivendica con l'email verificata
        $this->db->esegui('UPDATE prenotazioni SET utente_id = 7 WHERE id = ?', [$a]);
        $voce = $this->areaPersonale([$a], null, 7);
        $this->assertSame([''], array_column($voce, 'token'));
        $this->assertSame([$this->codice($a)], array_column($voce, 'apri_codice'));
    }

    public function testLEmailDiUnAccountSiFidaSoloSeVerificata(): void
    {
        $this->assertSame('a@x.it', UtenteRepository::emailVerificata(['email' => ' A@x.it ', 'email_personalizzata' => 0, 'email_verificata_il' => null]), 'arrivata dal login di Ateneo/SPID/CIE');
        $this->assertSame('a@x.it', UtenteRepository::emailVerificata(['email' => 'a@x.it', 'email_personalizzata' => 1, 'email_verificata_il' => '2026-10-01 10:00:00']), 'scelta e confermata con il codice');
        $this->assertNull(UtenteRepository::emailVerificata(['email' => 'a@x.it', 'email_personalizzata' => 1, 'email_verificata_il' => null]), 'scelta a mano e mai confermata');
        $this->assertNull(UtenteRepository::emailVerificata(['email' => '', 'email_personalizzata' => 0, 'email_verificata_il' => null]));
        $this->assertNull(UtenteRepository::emailVerificata(['email' => 'non-una-email', 'email_personalizzata' => 0]));
        $this->assertNull(UtenteRepository::emailVerificata(null));
    }

    public function testIlRinnovoPrecompilatoSoloPerChiHaLEmailVerificataOIlLink(): void
    {
        [, $vecchia] = $this->compilazioneDiAnna();
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'protocollata' WHERE id = ?", [$vecchia['id']]);
        $o = $this->online();

        // anonimo con la stessa email: compilazione vuota, niente dati del Dirigente
        $anonima = $o->perToken((string) $o->apriDaCodice($this->codice($this->pren(201, ['email' => 'anna@scuola.it'])))['token']);
        $this->assertNull($anonima['dati_json'], 'senza email verificata né link, compilazione vuota');

        // un'altra scuola-istanza: con l'email verificata proprietaria parte dai dati della precedente
        $this->db->esegui('DELETE FROM convenzioni_compilate WHERE id = ?', [$anonima['id']]);
        $conAccesso = $o->perToken((string) $o->apriDaCodice($this->codice($this->pren(201, ['email' => 'anna@scuola.it'])), 'anna@scuola.it')['token']);
        $this->assertSame('Dott. Segreto', json_decode((string) $conAccesso['dati_json'], true)['scuola']['dirigente']);
    }

    public function testDalProgrammaUnAnonimoConLaStessaEmailNonApreNeModificaLaCompilazione(): void
    {
        [$a, $cc] = $this->compilazioneDiAnna();
        $prima = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $e = $this->pren(100, ['email' => 'anna@scuola.it']);
        $o = $this->online();

        $r = $o->accogliProgramma(['denominazione' => 'Altra', 'dirigente' => 'Dott. Diverso', 'email' => 'anna@scuola.it', 'codice' => 'CSIS00001A'], null,
            [['pr' => $e, 'studenti' => 7, 'tutor' => 'Prof. Verdi']], $e, 'CSPS00001A', 'anna@scuola.it', 'no');

        $this->assertNull($r['cc'], 'nessun token né dati nella risposta del programma');
        $this->assertTrue($r['segnalata']);
        $dopo = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame($prima['dati_json'], $dopo['dati_json']);
        $this->assertSame([$e], array_column(json_decode((string) $dopo['attivita_in_attesa'], true), 'pr'));
        $this->assertSame('anna@scuola.it', $this->mailer->inviate[0]['a']);
        $this->assertNull($o->creaDaProgramma(['denominazione' => 'X'], null, [['pr' => $e, 'studenti' => 1, 'tutor' => '']], $e, 'CSPS00001A', 'anna@scuola.it', 'no'));
    }

    public function testDalProgrammaConEmailVerificataProprietariaLeAttivitaSiAggiungono(): void
    {
        [$a, $cc] = $this->compilazioneDiAnna();
        $e = $this->pren(100, ['email' => 'anna@scuola.it']);
        $o = $this->online();

        $r = $o->accogliProgramma(['denominazione' => 'Altra', 'email' => 'anna@scuola.it'], null, [['pr' => $e, 'studenti' => 7, 'tutor' => 'Prof. Verdi']], $e, 'CSPS00001A', 'anna@scuola.it', 'no', 'anna@scuola.it');

        $this->assertNotSame($cc['token'], $r['cc']['token'], 'rivendicata anche dal programma: token nuovo');
        $this->assertSame($r['cc']['token'], $this->riga('convenzioni_compilate', (int) $cc['id'])['token']);
        $this->assertSame([$a, $e], $this->idAttivita($this->riga('convenzioni_compilate', (int) $cc['id'])));
    }

    public function testIlLimiteDelleEmailEUnaOgniDieciMinutiPerCompilazione(): void
    {
        [, $cc] = $this->compilazioneDiAnna();
        $o = $this->online();
        $d1 = $this->pren(100, ['email' => 'c1@altra.it']);
        $d2 = $this->pren(201, ['email' => 'c2@altra.it']);

        $o->apriDaCodice($this->codice($d1));
        $o->apriDaCodice($this->codice($d2));

        $this->assertCount(1, $this->mailer->inviate, 'la seconda attività non rimanda l\'avviso');
        $this->assertEqualsCanonicalizing([$d1, $d2], array_column(json_decode((string) $this->riga('convenzioni_compilate', (int) $cc['id'])['attivita_in_attesa'], true), 'pr'), 'ma resta da confermare');
        $this->db->esegui('UPDATE convenzioni_compilate SET avviso_il = NOW() - INTERVAL 11 MINUTE WHERE id = ?', [$cc['id']]);
        $d3 = $this->pren(100, ['email' => 'c3@altra.it']);
        $o->apriDaCodice($this->codice($d3));
        $this->assertCount(2, $this->mailer->inviate, 'passati i dieci minuti l\'avviso riparte');
    }

    public function testLEmailDelModuloDaSolaNonRendeProprietari(): void
    {
        $p = $this->pren(200, ['email' => 'anna@scuola.it']);
        $o = $this->online();
        $cc = $o->perToken((string) $o->apriDaCodice($this->codice($p))['token']);
        $o->salva($cc, $o->contesto($cc), ['denominazione' => 'Istituto Galilei', 'email' => 'segreteria@scuola.it', 'att' => ["pr$p" => ['scelta' => 1, 'studenti' => '10']]], null);
        $this->mailer->inviate = [];
        $b = $this->pren(100, ['email' => 'segreteria@scuola.it']);

        $r = $o->apriDaCodice($this->codice($b), 'segreteria@scuola.it');

        $this->assertNull($r['token'], 'l\'email scritta nel modulo serve solo per le comunicazioni');
        $riga = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame('anna@scuola.it', $riga['email'], 'salvare il modulo non cambia l\'email della compilazione');
        $this->assertNotContains('segreteria@scuola.it', $o->proprietari($riga));
    }

    public function testLeEmailConIlTokenVannoSoloAllEmailDellaCompilazione(): void
    {
        $p = $this->pren(200, ['email' => 'anna@scuola.it']);
        $o = $this->online();
        $cc = $o->perToken((string) $o->apriDaCodice($this->codice($p))['token']);

        $o->salva($cc, $o->contesto($cc), ['denominazione' => 'Istituto Galilei', 'email' => 'segreteria@scuola.it', 'att' => ["pr$p" => ['scelta' => 1, 'studenti' => '10']]], null);

        $this->assertNotEmpty($this->mailer->inviate);
        foreach ($this->mailer->inviate as $m) {
            $this->assertSame('anna@scuola.it', $m['a'], 'il link con il token non va all\'indirizzo scritto nel modulo');
        }
    }

    // ───────────────────────── S2: nessuno occupa una scuola ─────────────────────────

    public function testUnaCompilazioneMaiCompilataNonBloccaGliAltri(): void
    {
        $anna = $this->pren(200, ['email' => 'anna@scuola.it']);
        $carlo = $this->pren(201, ['email' => 'carlo@altra.it']);
        $o = $this->online();
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);

        $tAnna = $o->apriDaCodice($this->codice($anna))['token'];
        $r = $o->apriDaCodice($this->codice($carlo));

        $this->assertNotNull($r['token'], 'chi arriva dopo una compilazione vuota ne apre una sua');
        $this->assertNotSame($tAnna, $r['token']);
        $this->assertSame(2, $this->nCompilazioni());
        $this->assertFalse($r['segnalata']);
        $this->assertSame([], $this->mailer->inviate, 'nessun avviso a chi non ha compilato niente');
        $this->assertSame([], $repo->inCorsoDellIstituto('CSPS00001A'), 'la vuota non conta come «in corso» per gli altri');
        $this->assertSame([], $repo->doppie(), 'né compare tra le doppie');
        $this->assertSame([], array_filter($o->recenti(), static fn (array $x): bool => in_array($x['token'], [$tAnna, $r['token']], true)), 'né tra le compilazioni da seguire');
        // chi l'ha aperta per prima la riprende comunque dal suo codice
        $this->assertSame($tAnna, $o->apriDaCodice($this->codice($anna))['token']);
    }

    public function testUnaVuotaNonEntraNelleCoseDaFareDellaScheda(): void
    {
        $anna = $this->pren(200, ['email' => 'anna@scuola.it']);
        $this->online()->apriDaCodice($this->codice($anna));

        $scheda = $this->servizio(\App\Fsl\SchedaScuolaFsl::class)->scheda('CSPS00001A', 0, true);

        $this->assertSame([], $scheda['aperte'], 'una compilazione mai compilata non è una cosa da fare per l\'ufficio');
        $this->assertSame([], $scheda['iter_aperti']);
        $this->assertSame(['senza_convenzione'], $scheda['tags'], 'resta solo la prenotazione senza convenzione, non la compilazione vuota');
    }

    public function testNonRiconoscoQuestaConvenzioneAvvisaLUfficioSenzaDati(): void
    {
        [$a, $cc] = $this->compilazioneDiAnna();
        $this->db->esegui("INSERT INTO utenti (id, email, ruolo_id) VALUES (1, 'admin@ateneo.it', 1)");
        $d = $this->pren(100, ['email' => 'carlo@altra.it', 'nome' => 'Carlo', 'cognome' => 'Neri']);
        $o = $this->online();

        $this->assertNotNull($o->nonRiconosco($this->codice($d), '   ', 'carlo@altra.it'), 'serve un testo');
        $this->assertNotNull($o->nonRiconosco('NON-ESISTE', 'Non la conosco', ''), 'il codice deve essere di una prenotazione');
        $this->assertNull($o->nonRiconosco($this->codice($d), 'Non conosco questa convenzione: scrivo dalla segreteria', 'carlo@altra.it'));

        $inviate = array_values(array_filter($this->mailer->inviate, static fn (array $m): bool => $m['a'] === 'admin@ateneo.it'));
        $this->assertNotEmpty($inviate, 'l\'ufficio viene avvisato');
        $testo = json_encode($inviate, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Non conosco questa convenzione', (string) $testo);
        $this->assertStringContainsString($this->codice($d), (string) $testo);
        $this->assertStringNotContainsString((string) $cc['token'], (string) $testo, 'nessun link né dato della compilazione');
        $this->assertStringNotContainsString('Dott. Segreto', (string) $testo);
        $this->assertStringNotContainsString('anna@scuola.it', (string) $testo);
    }

    public function testCambiaReferenteDaUnTokenNuovoELeVecchieEmailNonAprono(): void
    {
        [$a, $cc] = $this->compilazioneDiAnna();
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);
        $o = $this->online();
        $cc = $o->perToken((string) $o->apriDaCodice($this->codice($a), 'anna@scuola.it')['token']);   // Anna la rivendica con la sua email verificata
        $altra = $repo->crea('zz' . str_repeat('9', 30), 'CSPS00001A', $this->pren(201, ['email' => 'carlo@altra.it']), 'carlo@altra.it');
        $this->assertNull($this->servizio(ServizioUnioneConvenzioni::class)->unisci((int) $cc['id'], (int) $altra['id']));   // Carlo è ora tra i proprietari per unione
        $b = $this->pren(100, ['email' => 'anna@scuola.it']);
        $this->assertSame($cc['token'], $o->apriDaCodice($this->codice($b), 'carlo@altra.it')['token'], 'prima del cambio Carlo (unito) apre');
        $this->mailer->inviate = [];
        $r = $this->servizio(ServizioReferenteConvenzione::class);

        $this->assertNotNull($r->cambia((int) $cc['id'], 'non-una-email', 'ufficio'));
        $this->assertNull($r->cambia((int) $cc['id'], 'Nuova.Referente@Scuola.it', 'ufficio'));

        $dopo = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertNotSame($cc['token'], $dopo['token']);
        $this->assertNull($o->perToken((string) $cc['token']), 'il vecchio link smette di funzionare');
        $this->assertSame('nuova.referente@scuola.it', $dopo['email']);
        $this->assertSame(['nuova.referente@scuola.it'], $o->proprietari($dopo), 'solo il nuovo referente è proprietario');
        $this->assertSame(1, (int) ($this->db->valore('SELECT COUNT(*) FROM convenzioni_compilate WHERE id = ?', [$cc['id']])), 'niente si cancella');
        $this->assertSame('nuova.referente@scuola.it', $this->mailer->inviate[0]['a']);
        $this->assertStringContainsString($dopo['token'], $this->mailer->inviate[0]['corpo']);
        $this->assertCount(1, $this->mailer->inviate, 'l\'avviso va solo al nuovo indirizzo');

        // vecchia email, prenotazione di nascita ed email unita: non aprono più
        foreach (['anna@scuola.it', 'carlo@altra.it'] as $vecchia) {
            $c = $this->pren(100, ['email' => $vecchia]);
            $this->assertNull($o->apriDaCodice($this->codice($c), $vecchia)['token'], "$vecchia non è più proprietaria");
        }
        $this->assertNull($o->apriDaCodice($this->codice($a), null)['token'], 'la prenotazione di nascita non apre più');
        $this->assertSame([], $this->areaPersonale([$a], 'anna@scuola.it'));
        // il nuovo referente apre
        $e = $this->pren(100, ['email' => 'altra@boh.it']);
        $this->assertSame($dopo['token'], $o->apriDaCodice($this->codice($e), 'nuova.referente@scuola.it')['token']);
    }

    public function testCambiaReferenteNonSiFaSuProtocollateOUnite(): void
    {
        [, $cc] = $this->compilazioneDiAnna();
        $r = $this->servizio(ServizioReferenteConvenzione::class);
        foreach (['protocollata', 'unita'] as $stato) {
            $this->db->esegui('UPDATE convenzioni_compilate SET stato_iter = ? WHERE id = ?', [$stato, $cc['id']]);
            $this->assertNotNull($r->cambia((int) $cc['id'], 'nuova@scuola.it', 'ufficio'), $stato);
        }
        $this->assertSame($cc['token'], $this->riga('convenzioni_compilate', (int) $cc['id'])['token']);
        $this->assertNotNull($r->cambia(99999, 'nuova@scuola.it', 'ufficio'));
    }

    // ───────────────────────── S3: il blocco per istituto è obbligatorio ─────────────────────────

    public function testIlNomeDelBloccoComprendeIlDatabaseEStaEntroSessantaquattroCaratteri(): void
    {
        $a = ConvenzioneCompilataRepository::nomeBlocco('CSIS00001A', 'eventi_prod');
        $b = ConvenzioneCompilataRepository::nomeBlocco('CSIS00001A', 'eventi_prova');

        $this->assertNotSame($a, $b, 'due database sullo stesso server non si bloccano a vicenda');
        $this->assertLessThanOrEqual(64, strlen($a));
        $this->assertLessThanOrEqual(64, strlen(ConvenzioneCompilataRepository::nomeBlocco(str_repeat('x', 300), str_repeat('d', 100))));
        $this->assertSame($a, ConvenzioneCompilataRepository::nomeBlocco('CSIS00001A', 'eventi_prod'));
    }

    public function testConIlBloccoOccupatoNonSiProcedeENonNasceNiente(): void
    {
        [$a, $cc] = $this->compilazioneDiAnna();
        $b = $this->pren(100, ['email' => 'anna@scuola.it']);
        $nuova = $this->pren(201, ['email' => 'carlo@altra.it', 'scuola_codice' => 'CSPS00002B']);   // un altro istituto, libero
        $prima = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $db = getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit';
        $altra = new \mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''), $db);
        foreach (array_filter(['convenzione_istituto_CSIS00001A', method_exists(ConvenzioneCompilataRepository::class, 'nomeBlocco') ? ConvenzioneCompilataRepository::nomeBlocco('CSIS00001A', $db) : null]) as $nome) {
            $altra->query("SELECT GET_LOCK('" . $altra->real_escape_string($nome) . "', 0)");
        }
        $o = $this->online();

        $r = $o->apriDaCodice($this->codice($b), 'anna@scuola.it');

        $this->assertNull($r['token'], 'con il blocco occupato non si procede');
        $this->assertTrue($r['riprova'] ?? false, '«Riprova tra qualche secondo»');
        $dopo = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame($prima['dati_json'], $dopo['dati_json']);
        $this->assertNull($dopo['attivita_in_attesa']);
        $this->assertSame(1, $this->nCompilazioni());
        $this->assertSame([], $this->mailer->inviate);

        // il programma: le prenotazioni già fatte restano valide, la compilazione non si tocca e non ne nasce un'altra
        $e = $this->pren(100, ['email' => 'anna@scuola.it']);
        $rp = $o->accogliProgramma(['denominazione' => 'Altra', 'email' => 'anna@scuola.it'], null, [['pr' => $e, 'studenti' => 7, 'tutor' => 'X']], $e, 'CSPS00001A', 'anna@scuola.it', 'no', 'anna@scuola.it');
        $this->assertNull($rp['cc']);
        $this->assertTrue($rp['riprova'] ?? false);
        $this->assertSame(1, $this->nCompilazioni());
        $this->assertSame('confermata', $this->riga('prenotazioni', $e)['stato'], 'la prenotazione resta valida');

        // la compilazione, il rinnovo, la conferma di un'attività e l'unione si fermano
        $esito = $o->salva($this->riga('convenzioni_compilate', (int) $cc['id']), $o->contesto($cc), ['denominazione' => 'CAMBIATO', 'att' => ["pr$a" => ['scelta' => 1]]], null);
        $this->assertNotSame([], $esito['errori']);
        $this->assertStringContainsString('qualche secondo', implode(' ', $esito['errori']));
        $this->assertSame($prima['dati_json'], $this->riga('convenzioni_compilate', (int) $cc['id'])['dati_json']);
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);
        $c2 = $repo->crea('qq' . str_repeat('3', 30), 'CSIS00001A', $this->pren(201, ['email' => 'x@altra.it']), 'x@altra.it');
        $repo->salvaDati((int) $c2['id'], json_encode(['scuola' => [], 'attivita' => []]), null, 'x@altra.it');
        $msg = (string) $this->servizio(ServizioUnioneConvenzioni::class)->unisci((int) $cc['id'], (int) $c2['id']);
        $this->assertStringContainsString('qualche secondo', $msg);
        $this->assertSame('', $this->riga('convenzioni_compilate', (int) $c2['id'])['stato_iter'], 'nessuna unione');
        $altra->close();
    }

    public function testCaricareIlFileFirmatoESegnarloPresoInCaricoNonScavalcaUnaUnione(): void
    {
        [, $x] = $this->compilazioneDiAnna();
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);
        $y = $repo->crea('yy' . str_repeat('4', 30), 'CSIS00001A', $this->pren(201, ['email' => 'carlo@altra.it']), 'carlo@altra.it');
        $repo->salvaDati((int) $y['id'], json_encode(['scuola' => ['denominazione' => 'Bis'], 'attivita' => []]), null, 'carlo@altra.it');
        $this->assertNull($this->servizio(ServizioUnioneConvenzioni::class)->unisci((int) $x['id'], (int) $y['id']));
        $firmata = $this->servizio(ServizioConvenzioneFirmata::class);
        $y = $this->riga('convenzioni_compilate', (int) $y['id']);   // la pagina di Carlo era aperta da prima: la riga letta è «vecchia»
        $y['stato_iter'] = '';

        $err = $firmata->carica($y, "%PDF-1.4\nFIRMATO", "%PDF-1.4\nallegato");

        $this->assertNotNull($err, 'una compilazione unita nel frattempo non riceve più file');
        $dopo = $this->riga('convenzioni_compilate', (int) $y['id']);
        $this->assertSame('unita', $dopo['stato_iter']);
        $this->assertNull($dopo['file_firmato']);
        $this->assertSame('', (string) $dopo['protocollo_ingresso']);
        $pi = $firmata->protocolloIngresso((int) $y['id'], '123', '2026-10-05', 'ufficio');
        $this->assertNotNull($pi['errore']);
        $this->assertSame('unita', $this->riga('convenzioni_compilate', (int) $y['id'])['stato_iter']);
    }

    public function testSalvareIlModuloDiUnaCompilazioneUnitaNelFrattempoNonLaSovrascrive(): void
    {
        [, $x] = $this->compilazioneDiAnna();
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);
        $py = $this->pren(201, ['email' => 'carlo@altra.it']);
        $y = $repo->crea('yy' . str_repeat('5', 30), 'CSIS00001A', $py, 'carlo@altra.it');
        $repo->salvaDati((int) $y['id'], json_encode(['scuola' => ['denominazione' => 'Bis'], 'attivita' => []]), null, 'carlo@altra.it');
        $o = $this->online();
        $vecchia = $this->riga('convenzioni_compilate', (int) $y['id']);
        $ctx = $o->contesto($vecchia);
        $this->assertNull($this->servizio(ServizioUnioneConvenzioni::class)->unisci((int) $x['id'], (int) $y['id']));
        $prima = $this->riga('convenzioni_compilate', (int) $y['id']);

        $esito = $o->salva($vecchia, $ctx, ['denominazione' => 'SOVRASCRITTO', 'att' => ["pr$py" => ['scelta' => 1, 'studenti' => '3']]], null);

        $this->assertNotSame([], $esito['errori']);
        $this->assertSame($prima['dati_json'], $this->riga('convenzioni_compilate', (int) $y['id'])['dati_json']);
    }

    // ───────────────────────── S4: unioni vietate ─────────────────────────

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} due compilazioni in corso dello stesso istituto, con dati */
    private function dueDoppie(): array
    {
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);
        $x = $repo->crea('xx' . str_repeat('6', 30), 'CSPS00001A', $this->pren(200, ['email' => 'anna@scuola.it']), 'anna@scuola.it');
        $y = $repo->crea('yy' . str_repeat('7', 30), 'CSIS00001A', $this->pren(201, ['email' => 'carlo@altra.it']), 'carlo@altra.it');
        $repo->salvaDati((int) $x['id'], json_encode(['scuola' => ['denominazione' => 'A'], 'attivita' => []]), null, 'anna@scuola.it');
        $repo->salvaDati((int) $y['id'], json_encode(['scuola' => ['denominazione' => 'B'], 'attivita' => []]), null, 'carlo@altra.it');

        return [$x, $y];
    }

    public function testNonSiAssorbeUnaCompilazioneConFileFirmatoOProtocolloInEntrata(): void
    {
        [$x, $y] = $this->dueDoppie();
        $u = $this->servizio(ServizioUnioneConvenzioni::class);

        foreach ([['caricata_scuola', 'uploads/x.pdf', ''], ['in_carico', 'uploads/x.pdf', '55'], ['rifiutata', 'uploads/x.pdf', '56'], ['rifiutata', null, '57'], ['', 'uploads/x.pdf', '']] as [$stato, $file, $prot]) {
            $this->db->esegui('UPDATE convenzioni_compilate SET stato_iter = ?, file_firmato = ?, protocollo_ingresso = ? WHERE id = ?', [$stato, $file, $prot, $y['id']]);
            $this->assertNotNull($u->unisci((int) $x['id'], (int) $y['id']), "assorbita: stato «{$stato}», file " . var_export($file, true) . ", prot. «{$prot}»");
            $this->assertSame($stato, $this->riga('convenzioni_compilate', (int) $y['id'])['stato_iter'], 'non cambia niente');
        }
    }

    public function testSeEntrambeHannoUnFileSiDiceDiRifiutarneUnaPrimaESeSoloQuellaDaAssorbireLoHaSiDiceDiScegliereL(): void
    {
        [$x, $y] = $this->dueDoppie();
        $u = $this->servizio(ServizioUnioneConvenzioni::class);
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'caricata_scuola', file_firmato = 'uploads/x.pdf' WHERE id IN (?, ?)", [$x['id'], $y['id']]);

        $entrambe = (string) $u->unisci((int) $x['id'], (int) $y['id']);
        $this->assertStringContainsString('rifiut', $entrambe, 'entrambe hanno un file: prima se ne rifiuta una, con il motivo');

        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = '', file_firmato = NULL WHERE id = ?", [$x['id']]);
        $soloAssorbita = (string) $u->unisci((int) $x['id'], (int) $y['id']);
        $this->assertStringContainsString("l'altra", $soloAssorbita, 'solo quella da assorbire ha il file: si sceglie l\'altra come quella che resta');
        $this->assertNotSame($entrambe, $soloAssorbita);

        $this->assertNull($u->unisci((int) $y['id'], (int) $x['id']), 'invertendo (resta quella con il file) si può');
        $this->assertSame('unita', $this->riga('convenzioni_compilate', (int) $x['id'])['stato_iter']);
    }

    public function testUnaRifiutataSenzaProtocolloSiAssorbeEIlSuoVecchioFileNonPassaAChiResta(): void
    {
        [$x, $y] = $this->dueDoppie();
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'rifiutata', file_firmato = 'uploads/x.pdf', protocollo_ingresso = '' WHERE id = ?", [$y['id']]);
        $u = $this->servizio(ServizioUnioneConvenzioni::class);

        $this->assertNull($u->unisci((int) $x['id'], (int) $y['id']), 'rifiutata e senza protocollo: il suo file non è più vivo, si assorbe');
        $this->assertEmpty($this->riga('convenzioni_compilate', (int) $x['id'])['file_firmato'] ?? null, 'il file della rifiutata non passa a quella che resta');
    }

    // ───────────────────────── S5: un solo criterio per «stesso istituto» ─────────────────────────

    public function testCodiceIstitutoESqlIstitutoDannoLoStessoRisultatoPerOgniValore(): void
    {
        $this->db->esegui('ALTER TABLE scuole MODIFY istituto_codice VARCHAR(20) NULL');
        $this->db->esegui("INSERT INTO scuole (codice, denominazione, istituto_codice) VALUES
            ('CSAA00001A', 'ISTITUTO VUOTO', ''), ('CSAA00002A', 'ISTITUTO NULL', NULL), ('CSAA00003A', 'NON DISPONIBILE', 'Non Disponibile'),
            ('CSAA00004A', 'MINUSCOLO', 'csis00001a'), ('CSAA00005A', 'SPAZI', '  CSIS00001A '), ('CSAA00006A', 'CORTO', 'CSIS1'), ('CSAA00007A', 'ZERO', '0000000000'), ('CSAA00008A', 'CON TRATTINO', 'CSIS-0001A')");
        $s = $this->servizio(ServizioScuole::class);
        $valori = [null, '', '   ', 'Non Disponibile', 'abc', 'CSIS00001A', 'csis00001a', ' CSIS00001A ', 'ZZZZ99999Z', 'zzzz99999z', 'CSPS00001A', 'cspS00001a ', 'CSTF00003C',
            'CSAA00001A', 'csaa00002a', 'CSAA00003A', 'CSAA00004A', 'csaa00004a', ' csaa00005a ', 'CSAA00005A', 'CSAA00006A', 'CSAA00007A', 'CSAA00008A', 'CSPS0000', 'CSPS00001AB'];
        foreach ($valori as $v) {
            $sql = (string) $this->db->valore('SELECT ' . ServizioScuole::sqlIstituto('s', 'x.c') . ' FROM (SELECT ? AS c) x LEFT JOIN scuole s ON s.codice = x.c', [$v]);
            $this->assertSame($s->codiceIstituto($v), $sql, 'valore ' . var_export($v, true));
        }
        $this->assertSame('CSIS00001A', $s->codiceIstituto('csaa00004a'), 'istituto in minuscolo: vale maiuscolo');
        $this->assertSame('CSIS00001A', $s->codiceIstituto('CSAA00005A'), 'istituto con spazi: vale pulito');
        $this->assertSame('CSAA00003A', $s->codiceIstituto('CSAA00003A'), '«Non Disponibile» non è un codice: vale la scuola');
        $this->assertSame('CSAA00001A', $s->codiceIstituto('CSAA00001A'));
        $this->assertSame('CSAA00002A', $s->codiceIstituto('CSAA00002A'));
        $this->assertSame('ZZZZ99999Z', $s->codiceIstituto(' zzzz99999z '), 'scuola assente dall\'anagrafe: resta se stessa, pulita');
        $this->assertSame('', $s->codiceIstituto(null));
        $this->assertSame('', $s->codiceIstituto('abc'));
    }

    public function testLeListeDelleCompilazioniUsanoLoStessoCriterioDelCodiceIstituto(): void
    {
        $this->db->esegui('ALTER TABLE scuole MODIFY istituto_codice VARCHAR(20) NULL');
        $this->db->esegui("INSERT INTO scuole (codice, denominazione, istituto_codice) VALUES ('CSAA00003A', 'NON DISPONIBILE', 'Non Disponibile'), ('CSAA00009A', 'ALTRA SEDE', 'Non Disponibile')");
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);
        // due scuole diverse con lo stesso «istituto» non valido NON sono la stessa scuola
        $c1 = $repo->crea('aa' . str_repeat('8', 30), 'CSAA00003A', $this->pren(200, ['scuola_codice' => 'CSAA00003A']), 'a@a.it');
        $c2 = $repo->crea('bb' . str_repeat('8', 30), 'CSAA00009A', $this->pren(201, ['scuola_codice' => 'CSAA00009A']), 'b@b.it');
        foreach ([$c1, $c2] as $c) {
            $repo->salvaDati((int) $c['id'], json_encode(['scuola' => [], 'attivita' => []]), null, (string) $c['email']);
        }

        $this->assertSame([(int) $c1['id']], array_map(static fn (array $c): int => (int) $c['id'], $repo->inCorsoDellIstituto('CSAA00003A')));
        $this->assertSame([], $repo->doppie());
    }
}
