<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Core\Sito;
use App\Fsl\AllegatoConvenzioneRepository;
use App\Fsl\ConvenzioneCompilataRepository;
use App\Fsl\ServizioAllegatiConvenzione;
use App\Fsl\ServizioConvenzioneOnline;
use App\Fsl\ServizioRegistroConvenzioni;
use App\Fsl\ServizioUnioneConvenzioni;

/**
 * Passo 4 delle convenzioni per scuola: una sola compilazione «in corso» per istituto (le sedi sono la stessa scuola), la sicurezza del codice di
 * prenotazione (non apre mai la compilazione nata da un'altra email), la convenzione già valida (solo Allegato A, come integrazione), il rinnovo
 * e l'unione delle compilazioni doppie.
 */
final class CompilazioneUnicaTest extends FslBase
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
        $this->radiceProva = sys_get_temp_dir() . '/fsl_unica_' . bin2hex(random_bytes(4));
        mkdir($this->radiceProva . '/uploads/convenzioni_compilate', 0755, true);
        mkdir($this->radiceProva . '/uploads/convenzioni', 0755, true);
        $this->c->istanza(Sito::class, new Sito($this->radiceProva, 'https://portale.test/eventi'));
        // colonne usate solo dalla scheda della scuola
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

    /** Il token dal codice di una prenotazione, come lo dà la pagina a chi ha fatto l'accesso con questa email VERIFICATA (null = anonimo). */
    private function token(int $prenotazioneId, ?string $emailVerificata = 'anna@scuola.it'): ?string
    {
        return $this->online()->apriDaCodice($this->codice($prenotazioneId), $emailVerificata)['token'] ?? null;
    }

    private function nCompilazioni(): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_compilate');
    }

    /**
     * Una compilazione con i dati scritti da chi ha prenotato (come dopo il «Prepara i documenti»).
     *
     * @return array{0: int, 1: array<string, mixed>} [prenotazione, compilazione]
     */
    private function compilazioneConDati(string $email = 'anna@scuola.it', int $turno = 200, string $dirigente = 'Dott. Segreto'): array
    {
        $p = $this->pren($turno, ['email' => $email, 'nome' => 'Anna', 'cognome' => 'Rossi']);
        $o = $this->online();
        $cc = $o->perToken((string) $o->tokenDaCodice($this->codice($p)));
        $esito = $o->salva($cc, $o->contesto($cc), ['denominazione' => 'Istituto Galilei', 'codice' => 'CSIS00001A', 'dirigente' => $dirigente, 'cf' => '12345678901', 'email' => $email,
            'att' => ["pr$p" => ['scelta' => 1, 'studenti' => '10', 'tutor' => 'Prof. Rossi']]], null);
        $this->assertSame([], $esito['errori']);
        $this->mailer->inviate = [];

        return [$p, $o->perToken((string) $cc['token'])];
    }

    /** @return list<int> */
    private function idAttivita(array $cc): array
    {
        return array_map(static fn (array $a): int => (int) ($a['pr'] ?? 0), json_decode((string) $cc['dati_json'], true)['attivita'] ?? []);
    }

    public function testDuePrenotazioniDellaStessaScuolaConLaStessaEmailSonoUnaCompilazione(): void
    {
        $a = $this->pren(200, ['email' => 'anna@scuola.it']);
        $b = $this->pren(100, ['email' => 'Anna@Scuola.it']);
        $c = $this->pren(201, ['email' => 'anna@scuola.it', 'scuola_codice' => 'CSIS00001A']);   // la sede e l'istituto sono la stessa scuola
        $o = $this->online();

        // la prima prenotazione apre da sola; le altre solo con l'email VERIFICATA di chi è proprietario (l'email scritta in una prenotazione non prova chi è)
        $t = $o->tokenDaCodice($this->codice($a), 'anna@scuola.it');   // con l'email verificata nasce già sua (rivendicata)
        $this->assertSame($t, $o->tokenDaCodice($this->codice($b), 'anna@scuola.it'));
        $this->assertSame($t, $o->tokenDaCodice($this->codice($c), 'anna@scuola.it'), 'sede e istituto: una sola compilazione');
        $this->assertSame($t, $o->tokenDaCodice($this->codice($a), 'anna@scuola.it'), 'e se si riapre resta la stessa');
        $this->assertSame(1, $this->nCompilazioni());

        // senza i dati nel modulo le tre attività compaiono da sole (sono tutte di chi ha la compilazione)
        $ctx = $o->contesto($o->perToken((string) $t));
        $this->assertEqualsCanonicalizing([$a, $b, $c], array_keys($ctx['prenotate']));
    }

    public function testConLaCompilazioneGiaScrittaLeNuoveAttivitaSiAggiungonoSenzaDoppioniNeCancellare(): void
    {
        [$a, $cc] = $this->compilazioneConDati();
        $logo = 'uploads/convenzioni_compilate/logo.png';
        $this->db->esegui('UPDATE convenzioni_compilate SET logo = ? WHERE id = ?', [$logo, $cc['id']]);
        $b = $this->pren(100, ['email' => 'anna@scuola.it']);
        $c = $this->pren(201, ['email' => 'anna@scuola.it', 'scuola_codice' => 'CSIS00001A']);
        $o = $this->online();
        $prima = json_decode((string) $cc['dati_json'], true);

        $nuovo = $o->tokenDaCodice($this->codice($b), 'anna@scuola.it');   // la prima apertura con l'email verificata la rivendica: token nuovo
        $this->assertNotSame($cc['token'], $nuovo);
        foreach ([$b, $c, $b, $c] as $p) {
            $this->assertSame($nuovo, $o->tokenDaCodice($this->codice($p), 'anna@scuola.it'));
        }

        $dopo = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $dati = json_decode((string) $dopo['dati_json'], true);
        $this->assertSame([$a, $b, $c], $this->idAttivita($dopo), 'aggiunte una volta sola e in ordine');
        $this->assertSame($prima['scuola'], $dati['scuola'], 'i dati della scuola non si toccano');
        $this->assertSame($logo, $dopo['logo']);
        $this->assertSame('anna@scuola.it', $dopo['email']);
        $this->assertNotEmpty($dati['attivita'][1]['il'], 'ogni voce aggiunta porta il momento');
        $this->assertSame(1, $this->nCompilazioni());
        $this->assertCount(1, $this->mailer->inviate, 'solo il link nuovo della rivendicazione, a chi ha la compilazione');
        $this->assertSame('anna@scuola.it', $this->mailer->inviate[0]['a']);
    }

    public function testUnAltraEmailNonApreLaCompilazioneNeVedeDatiELinkMaDeveEssereConfermata(): void
    {
        [$a, $cc] = $this->compilazioneConDati('anna@scuola.it', 200, 'Dott. Segreto');
        $d = $this->pren(100, ['email' => 'carlo@altra.it', 'nome' => 'Carlo', 'cognome' => 'Neri']);
        $o = $this->online();

        $esito = $o->apriDaCodice($this->codice($d));

        $this->assertSame(['token' => null, 'segnalata' => true, 'riprova' => false], $esito, 'a chi ha il codice si dice solo che l\'attività è stata segnalata');
        $this->assertNull($o->tokenDaCodice($this->codice($d)));
        $this->assertSame(1, $this->nCompilazioni(), 'nessuna compilazione nuova');
        $riga = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame([$a], $this->idAttivita($riga), 'l\'attività resta fuori dall\'Allegato A');
        $in_attesa = json_decode((string) $riga['attivita_in_attesa'], true);
        $this->assertSame([$d], array_column($in_attesa, 'pr'));

        // l'avviso, con il link, va solo all'email della compilazione esistente: una sola volta
        $this->assertCount(1, $this->mailer->inviate);
        $this->assertSame('anna@scuola.it', $this->mailer->inviate[0]['a']);
        $this->assertStringContainsString((string) $cc['token'], $this->mailer->inviate[0]['corpo']);
        $mail = json_encode($this->mailer->inviate, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('carlo@altra.it', (string) $mail, 'né a chi ha il codice né il suo indirizzo altrove');
        $this->assertStringNotContainsString('Dott. Segreto', (string) $mail);
        $o->apriDaCodice($this->codice($d));
        $this->assertCount(1, $this->mailer->inviate, 'riaprire con il codice non rimanda l\'avviso');
        $this->assertCount(1, json_decode((string) $this->riga('convenzioni_compilate', (int) $cc['id'])['attivita_in_attesa'], true), 'senza doppioni');

        // chi ha il link la vede «da confermare»; non è tra le attività né tra le prenotazioni della compilazione
        $ctx = $o->contesto($riga);
        $this->assertSame([$d], array_keys($ctx['da_confermare']));
        $this->assertArrayNotHasKey($d, $ctx['prenotate']);

        // nell'Area personale di chi ha prenotato con l'altra email non compare la compilazione (né il suo link); chi l'ha fatta la vede
        $this->db->esegui('UPDATE prenotazioni SET utente_id = 7 WHERE id = ?', [$a]);
        $this->assertSame([], $this->areaPersonale([$d]));
        $this->assertCount(1, $this->areaPersonale([$a], null, 7), 'chi l\'ha fatta la vede dalla prenotazione di nascita (sua: collegato con quell\'account)');
        $this->assertCount(0, $this->areaPersonale([$a]), 'senza account né email verificata la prenotazione non è sua');

        // confermata: entra nell'Allegato A, ma il codice dell'altra email continua a non aprire la compilazione
        $this->assertTrue($o->decidiInAttesa($riga, $d, true));
        $conf = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame([$a, $d], $this->idAttivita($conf));
        $this->assertNull($conf['attivita_in_attesa']);
        $this->assertSame(['token' => null, 'segnalata' => true, 'riprova' => false], $o->apriDaCodice($this->codice($d)));
        $this->assertSame([], $this->areaPersonale([$d]));
        $this->assertFalse($o->decidiInAttesa($conf, $d, true), 'non c\'è più da confermare');
    }

    public function testUnaAttivitaSegnalataSiPuoScartare(): void
    {
        [, $cc] = $this->compilazioneConDati();
        $d = $this->pren(100, ['email' => 'carlo@altra.it']);
        $o = $this->online();
        $o->apriDaCodice($this->codice($d));

        $this->assertTrue($o->decidiInAttesa($this->riga('convenzioni_compilate', (int) $cc['id']), $d, false));

        $riga = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertNull($riga['attivita_in_attesa']);
        $this->assertNotContains($d, $this->idAttivita($riga));
        $this->assertSame([], $o->contesto($riga)['da_confermare']);
    }

    public function testIlModuloNonPerdeLeAttivitaAggiuntePerStradaNeIlMomentoDelleVoci(): void
    {
        [$a, $cc] = $this->compilazioneConDati();
        $o = $this->online();
        $ctx = $o->contesto($cc);                       // la pagina viene aperta...
        $b = $this->pren(100, ['email' => 'anna@scuola.it']);
        $o->tokenDaCodice($this->codice($b), 'anna@scuola.it');            // ...e intanto un'altra richiesta (di chi è proprietario) aggiunge un'attività
        $il = (string) json_decode((string) $this->riga('convenzioni_compilate', (int) $cc['id'])['dati_json'], true)['attivita'][0]['il'];

        // il modulo (aperto prima) non conosce $b: salvare non la toglie
        $o->salva($cc, $ctx, ['denominazione' => 'Istituto Galilei', 'dirigente' => 'Dott. Segreto', 'email' => 'anna@scuola.it', 'att' => ["pr$a" => ['scelta' => 1, 'studenti' => '12']]], null);

        $riga = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame([$a, $b], $this->idAttivita($riga));
        $this->assertSame($il, json_decode((string) $riga['dati_json'], true)['attivita'][0]['il'], 'la voce che c\'era mantiene il suo momento');
    }

    public function testDalProgrammaUnaltraEmailNonSovrascriveScuolaLogoNeEmail(): void
    {
        [$a, $cc] = $this->compilazioneConDati('anna@scuola.it', 200, 'Dott. Segreto');
        $logo = 'uploads/convenzioni_compilate/logo.png';
        $this->db->esegui('UPDATE convenzioni_compilate SET logo = ? WHERE id = ?', [$logo, $cc['id']]);
        $prima = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $d = $this->pren(100, ['email' => 'hack@altra.it']);
        file_put_contents($this->radiceProva . '/uploads/convenzioni_compilate/nuovo.png', 'x');
        $o = $this->online();

        $r = $o->accogliProgramma(['denominazione' => 'ISTITUTO FALSO', 'dirigente' => 'Hacker', 'email' => 'hack@altra.it', 'codice' => 'CSIS00001A'], 'uploads/convenzioni_compilate/nuovo.png',
            [['pr' => $d, 'studenti' => 5, 'tutor' => 'X']], $d, 'CSPS00001A', 'hack@altra.it', 'no');

        $this->assertNull($r['cc']);
        $this->assertTrue($r['segnalata'], 'nessun link né dati a chi non è di quella compilazione');
        $dopo = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame($prima['dati_json'], $dopo['dati_json']);
        $this->assertSame([$prima['logo'], $prima['email']], [$dopo['logo'], $dopo['email']]);
        $this->assertSame([$d], array_column(json_decode((string) $dopo['attivita_in_attesa'], true), 'pr'));
        $this->assertSame(1, $this->nCompilazioni());
        $this->assertSame('anna@scuola.it', $this->mailer->inviate[0]['a']);
        $this->assertCount(1, $this->mailer->inviate);
        $this->assertNull($o->creaDaProgramma(['denominazione' => 'X'], null, [['pr' => $d, 'studenti' => 1, 'tutor' => '']], $d, 'CSPS00001A', 'hack@altra.it', 'no'), 'creaDaProgramma non apre la compilazione di un\'altra email');
    }

    public function testDalProgrammaConLaStessaEmailLeAttivitaSiAggiungonoESoloQuelleDeiDatiCheMancano(): void
    {
        [$a, $cc] = $this->compilazioneConDati('anna@scuola.it', 200, 'Dott. Segreto');
        $logo = 'uploads/convenzioni_compilate/logo.png';
        $this->db->esegui('UPDATE convenzioni_compilate SET logo = ? WHERE id = ?', [$logo, $cc['id']]);
        $e = $this->pren(100, ['email' => 'anna@scuola.it']);
        file_put_contents($this->radiceProva . '/uploads/convenzioni_compilate/altro.png', 'x');
        $o = $this->online();

        $r = $o->accogliProgramma(['denominazione' => 'Altra', 'dirigente' => 'Dott. Diverso', 'pec' => 'nuova@pec.it', 'email' => 'x@y.it'], 'uploads/convenzioni_compilate/altro.png',
            [['pr' => $e, 'studenti' => 7, 'tutor' => 'Prof. Verdi'], ['pr' => $a, 'studenti' => 99, 'tutor' => 'Altro']], $e, 'CSPS00001A', 'anna@scuola.it', 'si', 'anna@scuola.it');

        $this->assertNotSame($cc['token'], $r['cc']['token'], 'dal programma con l\'email verificata la compilazione si rivendica: token nuovo');
        $this->assertFalse($r['segnalata']);
        $riga = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame($r['cc']['token'], $riga['token']);
        $dati = json_decode((string) $riga['dati_json'], true);
        $this->assertSame('Istituto Galilei', $dati['scuola']['denominazione']);
        $this->assertSame('Dott. Segreto', $dati['scuola']['dirigente'], 'quello che c\'era resta');
        $this->assertSame('nuova@pec.it', $dati['scuola']['pec'], 'solo i campi che mancavano si riempiono');
        $this->assertSame([$a, $e], $this->idAttivita($riga), 'senza doppioni, le vecchie non si toccano');
        $this->assertSame(10, $dati['attivita'][0]['studenti']);
        $this->assertSame($logo, $riga['logo']);
        $this->assertFileDoesNotExist($this->radiceProva . '/uploads/convenzioni_compilate/altro.png', 'il logo non usato si cancella');
        $this->assertSame(1, $this->nCompilazioni());
    }

    public function testUnaScuolaSenzaCodiceRestaUnaCompilazionePerPrenotazione(): void
    {
        $a = $this->pren(200, ['email' => 'anna@x.it', 'scuola_codice' => null]);
        $b = $this->pren(100, ['email' => 'anna@x.it', 'scuola_codice' => null]);
        $c = $this->pren(200, ['email' => 'altra@x.it', 'scuola_codice' => null]);
        $o = $this->online();

        $ta = $o->tokenDaCodice($this->codice($a));
        $tb = $o->tokenDaCodice($this->codice($b));
        $tc = $o->tokenDaCodice($this->codice($c));

        $this->assertCount(3, array_unique([$ta, $tb, $tc]));
        $this->assertSame($ta, $o->tokenDaCodice($this->codice($a)));
        $this->assertSame(3, $this->nCompilazioni());
    }

    public function testConUnaConvenzioneValidaSiAggiornaSoloLAllegatoAEFinisceNelRegistroComeIntegrazione(): void
    {
        $conv = $this->convenzione('CSIS00001A', '2026-01-01', '2027-12-31', ['protocollo' => '55']);   // registrata sull'istituto: copre la sede
        [$p, $cc] = $this->compilazioneConDati();
        $o = $this->online();
        $ctx = $o->contesto($cc);

        $this->assertSame($conv, (int) $ctx['registro']['id'], 'la convenzione valida del registro: si aggiorna solo l\'Allegato A');

        // non copre tutto il periodo (il progetto arriva al 2 dicembre): niente modalità «solo Allegato A»
        $this->db->esegui("UPDATE convenzioni_scuole SET scadenza = '2026-11-01' WHERE id = ?", [$conv]);
        $this->assertNull($o->contesto($cc)['registro']);
        $this->db->esegui("UPDATE convenzioni_scuole SET scadenza = '2027-12-31' WHERE id = ?", [$conv]);

        $al = $this->servizio(ServizioAllegatiConvenzione::class);
        $this->assertNotNull($al->caricaContenuto($cc, $conv, 'non è un pdf'));
        $this->assertNull($al->caricaContenuto($cc, $conv, "%PDF-1.4\nAllegato A aggiornato"));
        $this->assertCount(0, $this->db->righe('SELECT 1 FROM convenzioni_allegati WHERE convenzione_id = ? AND stato <> ?', [$conv, 'da_vedere']));

        $righe = $this->db->righe('SELECT * FROM convenzioni_allegati WHERE convenzione_id = ?', [$conv]);
        $this->assertCount(1, $righe, 'un file non PDF non entra nel registro');
        $this->assertSame(['da_vedere', 'anna@scuola.it'], [$righe[0]['stato'], $righe[0]['caricato_da']]);
        $this->assertSame([$p], array_column(json_decode((string) $righe[0]['attivita_json'], true), 'pr'));
        $this->assertFileExists($this->radiceProva . '/' . $righe[0]['file']);
        $this->assertStringStartsWith('uploads/convenzioni/allegati/', (string) $righe[0]['file']);
        $this->assertNull($this->riga('convenzioni_scuole', $conv)['file_allegato'], 'l\'Allegato A registrato non si sostituisce mai');
        $this->assertSame('confermata', $this->riga('prenotazioni', $p)['stato'], 'non blocca le prenotazioni');
        $this->assertCount(1, $o->contesto($cc)['allegati']);

        // l'ufficio: il rifiuto vuole un motivo; accetta o rifiuta una volta sola; la scuola riceve l'esito
        $id = (int) $righe[0]['id'];
        $this->assertNotNull($al->esamina($id, false, '  ', '', 'ufficio@x.it', 'Istituto Galilei', 'https://portale.test/eventi/x'));
        $this->assertNull($al->esamina($id, false, 'Manca il tutor di Geologia', '', 'ufficio@x.it', 'Istituto Galilei', 'https://portale.test/eventi/x'));
        $this->assertSame('rifiutato', $this->riga('convenzioni_allegati', $id)['stato']);
        $this->assertStringContainsString('Manca il tutor di Geologia', $this->mailer->inviate[0]['corpo']);
        $this->assertSame('anna@scuola.it', $this->mailer->inviate[0]['a']);
        $this->assertNotNull($al->esamina($id, true, '', '', 'ufficio@x.it', 'Istituto Galilei', ''), 'già esaminato');
        $this->assertNull($al->caricaContenuto($cc, $conv, "%PDF-1.4\nCorretto"));
        $nuovo = (int) $this->db->valore('SELECT MAX(id) FROM convenzioni_allegati');
        $this->assertNull($al->esamina($nuovo, true, 'Va bene', '88', 'ufficio@x.it', 'Istituto Galilei', ''));
        $this->assertSame(['accettato', '88'], [$this->riga('convenzioni_allegati', $nuovo)['stato'], $this->riga('convenzioni_allegati', $nuovo)['protocollo']]);
        $this->assertNotNull($al->fileDaScaricare($nuovo));
        $this->assertNull($al->fileDaScaricare(99999));

        // eliminata la convenzione dal registro, righe e file degli allegati vanno con lei
        $files = array_column($this->db->righe('SELECT file FROM convenzioni_allegati WHERE convenzione_id = ?', [$conv]), 'file');
        $this->assertCount(2, $files);
        $this->assertNotNull($this->servizio(ServizioRegistroConvenzioni::class)->elimina($conv));
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_allegati'));
        foreach ($files as $f) {
            $this->assertFileDoesNotExist($this->radiceProva . '/' . $f);
        }
    }

    public function testIlRinnovoDaLinkOCodiceInUnaSolaCompilazionePrecompilataSoloPerChiHaLEmailOIlLink(): void
    {
        [, $vecchia] = $this->compilazioneConDati('anna@scuola.it', 200, 'Dott. Segreto');
        file_put_contents($this->radiceProva . '/uploads/convenzioni_compilate/vecchio.png', 'LOGO');
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'protocollata', logo = 'uploads/convenzioni_compilate/vecchio.png' WHERE id = ?", [$vecchia['id']]);
        $vecchia = $this->riga('convenzioni_compilate', (int) $vecchia['id']);
        $o = $this->online();

        // chi ha il link della vecchia: una nuova compilazione con i dati e il logo di quella; ripetuto, è la stessa
        $r = $o->rinnova($vecchia);
        $this->assertFalse($r['segnalata']);
        $nuova = $o->perToken((string) $r['token']);
        $this->assertNotSame($vecchia['id'], $nuova['id']);
        $dati = json_decode((string) $nuova['dati_json'], true);
        $this->assertSame('Dott. Segreto', $dati['scuola']['dirigente']);
        $this->assertSame([], $dati['attivita']);
        $this->assertNotSame($vecchia['logo'], $nuova['logo']);
        $this->assertSame('LOGO', file_get_contents($this->radiceProva . '/' . $nuova['logo']), 'una copia: cancellare un logo non toglie l\'altro');
        $this->assertSame($r['token'], $o->rinnova($vecchia)['token'], 'una sola compilazione nuova');
        $this->assertSame(2, $this->nCompilazioni());

        // chi prenota con la stessa email (nessun link) la trova già aperta; un'altra email non la apre e non la riceve precompilata
        // (con l'email verificata di Anna la nuova compilazione, che è sua e non ancora rivendicata, si rivendica: token nuovo)
        $rivendicata = $o->tokenDaCodice($this->codice($this->pren(201, ['email' => 'anna@scuola.it'])), 'anna@scuola.it');
        $this->assertNotSame($r['token'], $rivendicata);
        $this->assertNull($o->perToken((string) $r['token']), 'il link di prima non vale più');
        $this->assertSame(2, $this->nCompilazioni());
        $this->assertSame(['token' => null, 'segnalata' => true, 'riprova' => false], $o->apriDaCodice($this->codice($this->pren(201, ['email' => 'carlo@altra.it']))));
    }

    public function testIlRinnovoDaCodiceAltraEmailPartePulita(): void
    {
        [, $vecchia] = $this->compilazioneConDati('anna@scuola.it', 200, 'Dott. Segreto');
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = 'protocollata' WHERE id = ?", [$vecchia['id']]);
        $o = $this->online();

        $altro = $o->perToken((string) $o->tokenDaCodice($this->codice($this->pren(201, ['email' => 'carlo@altra.it']))));
        $this->assertNull($altro['dati_json'], 'chi non ha l\'email verificata né il link della precedente non ne riceve i dati');
        $this->assertSame(2, $this->nCompilazioni());

        // Quella nata da Carlo non è mai stata compilata (vuota): non occupa la scuola, chi arriva dopo ne apre una sua (S2).
        // Appena Carlo la compila, diventa «in corso» e Anna (altra email, anche scritta uguale a quella della vecchia) non la apre: l'attività resta da confermare
        $o->salva($altro, $o->contesto($altro), ['denominazione' => 'Galilei', 'dirigente' => 'Dott. Neri', 'email' => 'carlo@altra.it', 'att' => ['pr' . $altro['prenotazione_id'] => ['scelta' => 1, 'studenti' => '4']]], null);
        $this->mailer->inviate = [];
        $this->assertSame(['token' => null, 'segnalata' => true, 'riprova' => false], $o->apriDaCodice($this->codice($this->pren(201, ['email' => 'anna@scuola.it']))));
        $this->assertSame(2, $this->nCompilazioni());
        $this->assertSame('carlo@altra.it', $this->mailer->inviate[0]['a'], 'l\'avviso va a chi ha la compilazione in corso, non a chi ha il codice');
    }

    public function testUnireDueCompilazioniDoppie(): void
    {
        $anna = $this->pren(200, ['email' => 'anna@scuola.it']);
        $carlo = $this->pren(201, ['email' => 'carlo@altra.it', 'scuola_codice' => 'CSIS00001A']);
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);
        // le doppie di prima della compilazione unica: una per email
        $c1 = $repo->crea('aa' . str_repeat('1', 30), 'CSPS00001A', $anna, 'anna@scuola.it', 'anna@scuola.it');   // rivendicata da Anna (email verificata)
        $c2 = $repo->crea('bb' . str_repeat('2', 30), 'CSIS00001A', $carlo, 'carlo@altra.it');
        $repo->salvaDati((int) $c1['id'], json_encode(['scuola' => ['denominazione' => 'Istituto Galilei'], 'attivita' => [['pr' => $anna, 'studenti' => 5, 'tutor' => 'A']]]), null, 'anna@scuola.it');
        $repo->salvaDati((int) $c2['id'], json_encode(['scuola' => ['denominazione' => 'Galilei bis'], 'attivita' => [['pr' => $carlo, 'studenti' => 6, 'tutor' => 'C'], ['pr' => $anna, 'studenti' => 5, 'tutor' => 'A']]]), null, 'carlo@altra.it');
        $this->db->esegui("INSERT INTO convenzioni_messaggi (compilazione_id, da, testo) VALUES (?, 'scuola', 'Ciao'), (?, 'ufficio', 'Salve')", [$c2['id'], $c2['id']]);
        $repo->salvaInAttesa((int) $c2['id'], json_encode([['pr' => 999, 'studenti' => 1, 'tutor' => 'Z', 'ok' => false]]));
        $u = $this->servizio(ServizioUnioneConvenzioni::class);

        $this->assertSame([['istituto' => 'CSIS00001A', 'ids' => [(int) $c1['id'], (int) $c2['id']]]], array_map(static fn (array $d): array => ['istituto' => $d['istituto'], 'ids' => array_column($d['compilazioni'], 'id')], $repo->doppie()));
        $this->assertNotNull($u->unisci((int) $c1['id'], (int) $c1['id']), 'la stessa compilazione');
        $this->assertNotNull($u->unisci((int) $c1['id'], 99999));

        $this->assertNull($u->unisci((int) $c1['id'], (int) $c2['id']));

        $r1 = $this->riga('convenzioni_compilate', (int) $c1['id']);
        $r2 = $this->riga('convenzioni_compilate', (int) $c2['id']);
        $this->assertSame('unita', $r2['stato_iter']);
        $this->assertSame((int) $c1['id'], (int) $r2['unita_in']);
        $this->assertNotNull($r2['dati_json'], 'niente si cancella');
        $this->assertSame([$anna, $carlo], $this->idAttivita($r1), 'le attività passano, senza doppioni');
        $this->assertSame(2, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_messaggi WHERE compilazione_id = ?', [$c1['id']]), 'i messaggi passano');
        $this->assertSame([999], array_column(json_decode((string) $r1['attivita_in_attesa'], true), 'pr'), 'anche quelle da confermare');
        $this->assertSame([], $repo->doppie());
        $this->assertSame([(int) $c1['id']], array_map(static fn (array $c): int => (int) $c['id'], $repo->inCorsoDellIstituto('CSPS00001A')));

        // a chi aveva l'altra arriva il link GIUSTO (quello che resta); il vecchio non porta più nulla da modificare e la lista non lo mostra
        $this->assertSame('carlo@altra.it', $this->mailer->inviate[0]['a']);
        $this->assertStringContainsString($c1['token'], $this->mailer->inviate[0]['corpo']);
        $this->assertStringNotContainsString($c2['token'], $this->mailer->inviate[0]['corpo']);
        $this->assertSame($c1['token'], $this->online()->tokenDaCodice($this->codice($carlo), 'carlo@altra.it'), 'ora Carlo (con la sua email verificata) è tra chi può aprire quella che resta');
        $this->assertSame([], array_filter($this->online()->recenti(), static fn (array $x): bool => $x['token'] === $c2['token']), 'quella unita non è tra le compilazioni da seguire');
        $this->assertCount(1, $this->areaPersonale([$carlo], 'carlo@altra.it'), "nell'Area personale di Carlo c'è solo quella che resta");
        $this->assertSame($c1['token'], $this->areaPersonale([$carlo], 'carlo@altra.it')[0]['token']);
    }

    public function testNonSiUniscePerSbagliato(): void
    {
        $repo = $this->servizio(ConvenzioneCompilataRepository::class);
        $x = $repo->crea(str_repeat('a', 32), 'CSPS00001A', 1, 'a@a.it');
        $y = $repo->crea(str_repeat('b', 32), 'CSIS00001A', 2, 'b@b.it');
        $altro = $repo->crea(str_repeat('c', 32), 'CSPS00002B', 3, 'c@c.it');
        $u = $this->servizio(ServizioUnioneConvenzioni::class);

        $this->assertStringContainsString('stesso istituto', (string) $u->unisci((int) $x['id'], (int) $altro['id']));
        foreach (['protocollata', 'da_firmare', 'firmata_direttore', 'unita'] as $stato) {
            $this->db->esegui('UPDATE convenzioni_compilate SET stato_iter = ? WHERE id = ?', [$stato, $y['id']]);
            $this->assertNotNull($u->unisci((int) $x['id'], (int) $y['id']), "assorbita $stato");
            $this->assertNotNull($u->unisci((int) $y['id'], (int) $x['id']), "che resta $stato");
        }
        $this->assertSame('', $this->riga('convenzioni_compilate', (int) $x['id'])['stato_iter']);
        $this->assertSame(0, (int) $this->db->valore("SELECT COUNT(*) FROM convenzioni_compilate WHERE stato_iter = 'unita' AND id <> ?", [$y['id']]));
        $this->assertSame([], $this->mailer->inviate);
    }

    public function testLeAttivitaAggiunteDopoLaFirmaSiVedonoDallUfficioEIlFileFirmatoNonCambia(): void
    {
        [$a, $cc] = $this->compilazioneConDati();
        $this->db->esegui("UPDATE convenzioni_compilate SET file_firmato = 'uploads/convenzioni_compilate/firmate/x.pdf', firmata_il = ?, stato_iter = 'caricata_scuola', scuola_codice = 'CSPS00001A' WHERE id = ?", ['2000-01-01 10:00:00', $cc['id']]);
        // l'attività scritta prima della firma ha il suo momento precedente; quelle aggiunte ora sono «dopo la firma»
        $dati = json_decode((string) $cc['dati_json'], true);
        $dati['attivita'][0]['il'] = '1999-12-31 10:00:00';
        $this->db->esegui('UPDATE convenzioni_compilate SET dati_json = ? WHERE id = ?', [json_encode($dati), $cc['id']]);
        $b = $this->pren(100, ['email' => 'anna@scuola.it', 'scuola_codice' => 'CSIS00001A']);
        $d = $this->pren(201, ['email' => 'carlo@altra.it']);
        $o = $this->online();

        $this->assertSame($cc['token'], $o->tokenDaCodice($this->codice($b), 'anna@scuola.it'));
        $this->assertSame(['token' => null, 'segnalata' => true, 'riprova' => false], $o->apriDaCodice($this->codice($d)));

        $riga = $this->riga('convenzioni_compilate', (int) $cc['id']);
        $this->assertSame(['uploads/convenzioni_compilate/firmate/x.pdf', 'caricata_scuola', '2000-01-01 10:00:00'], [$riga['file_firmato'], $riga['stato_iter'], $riga['firmata_il']], 'il documento firmato non si tocca');
        $this->assertSame([$a, $b], $this->idAttivita($riga), 'la nuova attività è nell\'elenco');
        $scheda = $this->servizio(\App\Fsl\SchedaScuolaFsl::class)->scheda('CSPS00001A', 0, true);
        $compilazione = $scheda['iter_aperti'][0];
        $this->assertSame(1, $compilazione['dopo_firma'], 'l\'ufficio vede «attività aggiunte dopo la firma»');
        $this->assertSame([$d], array_column($compilazione['da_confermare'], 'pr'), 'e quella segnalata da un\'altra email è «da confermare»');
        $this->assertSame('CSIS00001A', $scheda['chiave']);
        // la scheda è la stessa dal codice della sede e da quello dell'istituto (i vecchi indirizzi non muoiono)
        $this->assertSame(array_column($scheda['eventi'], 'id'), array_column($this->servizio(\App\Fsl\SchedaScuolaFsl::class)->scheda('CSIS00001A', 0, true)['eventi'], 'id'));
    }

    public function testUnaConvenzioneSuUnaSedeCopreTuttoLIstitutoEPrenotazioniDiSediDiverse(): void
    {
        $this->db->esegui("INSERT INTO scuole (codice, denominazione, istituto_codice, comune) VALUES ('CSPS00009Z', 'SEDE DUE', 'CSIS00001A', 'RENDE')");
        $conv = $this->convenzione('CSPS00009Z', '2026-01-01', '2027-12-31');   // registrata su una sede
        $regRepo = $this->servizio(\App\Fsl\ConvenzioneRepository::class);

        foreach (['CSPS00001A', 'CSPS00009Z', 'CSIS00001A'] as $codice) {
            $this->assertSame($conv, (int) ($regRepo->valida($codice, '2026-10-13', '2026-12-02')['id'] ?? 0), $codice);
            $this->assertSame([$conv], array_column($regRepo->dellaScuola($codice), 'id'), $codice);
        }
        $this->assertNull($regRepo->valida('CSPS00002B', '2026-10-13', '2026-12-02'), 'un altro istituto no');
        $a = $this->pren(200, ['scuola_codice' => 'CSPS00001A', 'email' => 'a@s.it']);
        $b = $this->pren(201, ['scuola_codice' => 'CSPS00009Z', 'email' => 'a@s.it']);
        $this->assertEqualsCanonicalizing([$a, $b], $this->servizio(\App\Fsl\PrenotazioneFslRepository::class)->idPrenotateDallaScuola($a, 'CSIS00001A'));
        $this->assertCount(2, $this->servizio(\App\Fsl\PrenotazioneFslRepository::class)->inAttesaDellaScuola('CSPS00001A'), 'dalla sede si vedono anche quelle dell\'altra sede');
    }

    public function testDueRichiesteInContemporaneaFannoUnaSolaCompilazioneEIlBloccoVieneTenuto(): void
    {
        $a = $this->pren(200, ['email' => 'anna@scuola.it']);
        $b = $this->pren(100, ['email' => 'anna@scuola.it']);
        $bootstrap = (string) ($GLOBALS['__PHPUNIT_BOOTSTRAP'] ?? '');
        if ($bootstrap === '' || !is_file($bootstrap)) {
            $this->markTestSkipped('Il bootstrap dei test non è noto: non posso avviare i processi paralleli');
        }
        $db = getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit';
        $parti = microtime(true) + 3.0;
        $figli = [];
        foreach ([$this->codice($a), $this->codice($b), $this->codice($a), $this->codice($b)] as $i => $codice) {
            $pipe = [];
            $proc = proc_open([PHP_BINARY, '-d', 'extension=zip', dirname(__DIR__, 2) . '/Support/richiesta_apri_codice.php', $bootstrap, $db, $codice, sprintf('%.3F', $parti), 'anna@scuola.it'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
            $this->assertIsResource($proc, "figlio $i");
            $figli[] = [$proc, $pipe];
        }
        $token = [];
        foreach ($figli as $i => [$proc, $pipe]) {
            $token[$i] = trim((string) stream_get_contents($pipe[1]));
            $errori = trim((string) stream_get_contents($pipe[2]));
            fclose($pipe[1]);
            fclose($pipe[2]);
            proc_close($proc);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token[$i], "figlio $i: $token[$i] $errori");
        }

        $this->assertCount(1, array_unique($token), 'tutti arrivano alla stessa compilazione');
        $this->assertSame(1, $this->nCompilazioni(), 'una sola compilazione, non una per richiesta');
        $riga = $this->db->riga('SELECT * FROM convenzioni_compilate LIMIT 1');
        $this->assertSame($token[0], $riga['token']);

        // il blocco per istituto è davvero preso mentre si scrive: dall'interno di una scrittura (l'email dell'avviso) un'altra connessione lo vede occupato
        $altra = new \mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''), $db);
        $stato = [];
        $this->c->istanza(\App\Infrastructure\Mail\Mailer::class, new class ($altra, $stato, ConvenzioneCompilataRepository::nomeBlocco('CSIS00001A', $db)) implements \App\Infrastructure\Mail\Mailer {
            /** @param array<int, mixed> $stato */
            public function __construct(private \mysqli $altra, public array &$stato, private string $blocco)
            {
            }

            public function invia(string $a, string $oggetto, string $corpoHtml, ?string $colore = null, array $allegati = []): bool
            {
                $r = $this->altra->query("SELECT IS_FREE_LOCK('" . $this->blocco . "') AS libero")->fetch_assoc();
                $this->stato[] = (int) $r['libero'];

                return true;
            }

            public function ultimoErrore(): string
            {
                return '';
            }
        });
        $this->db->esegui('UPDATE convenzioni_compilate SET dati_json = ? WHERE id = ?', [json_encode(['scuola' => ['denominazione' => 'X'], 'attivita' => [['pr' => $a]]]), $riga['id']]);
        $d = $this->pren(201, ['email' => 'carlo@altra.it']);
        $this->servizio(ServizioConvenzioneOnline::class)->apriDaCodice($this->codice($d));
        $this->assertSame([0], $stato, 'durante la scrittura il blocco non è libero');
        $this->assertSame(1, (int) $altra->query("SELECT IS_FREE_LOCK('" . ConvenzioneCompilataRepository::nomeBlocco('CSIS00001A', $db) . "') AS l")->fetch_assoc()['l'], 'e dopo è rilasciato');
    }
}
