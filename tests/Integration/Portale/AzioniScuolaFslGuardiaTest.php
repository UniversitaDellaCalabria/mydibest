<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Core\Sito;
use App\Auth\Flash;

/**
 * Correzione W2 del passo 8: OGNI azione della scheda che porta un id (compilazione, convenzione, Allegato A, messaggio, prenotazione, le due compilazioni
 * di «Unisci», le coppie compilazione:prenotazione) si controlla in un solo punto, PRIMA di eseguire: se l'id è di un'altra scuola non si scrive niente,
 * non parte nessuna email e non si tocca nessun file, e il messaggio è «Non è di questa scuola». Le azioni a tutte le scuole si fanno solo dall'elenco.
 *
 * Si guarda la scheda del Galilei (CSIS00001A) e si mandano gli id del Fermi (CSIS00002B). Prima e dopo si fotografano le tabelle, i file e le email.
 */
final class AzioniScuolaFslGuardiaTest extends PaginaScuoleFslBase
{
    private string $radiceFile = '';
    private int $ccFermi = 0;
    private int $ccFermi2 = 0;
    private int $ccGalilei = 0;
    private int $cvFermi = 0;
    private int $alFermi = 0;
    private int $prFermi = 0;
    private int $prGalilei = 0;

    protected function tabelle(): array
    {
        return parent::tabelle() + ['log_email' => self::tabellaVera('log_email')];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->radiceFile = sys_get_temp_dir() . '/guardia_fsl_' . bin2hex(random_bytes(4));
        mkdir($this->radiceFile . '/uploads/convenzioni_compilate/firmate', 0755, true);
        mkdir($this->radiceFile . '/uploads/convenzioni', 0755, true);
        $this->c->istanza(Sito::class, new Sito($this->radiceFile, 'https://portale.test/eventi'));

        $this->ccFermi = $this->compilazione('CSIS00002B', 'ref@fermi.it', 'da_firmare');
        $this->ccFermi2 = $this->compilazione('CSIS00002B', 'altro@fermi.it', 'caricata_scuola');
        $this->ccGalilei = $this->compilazione('CSIS00001A', 'ref@galilei.it', 'caricata_scuola');
        $this->cvFermi = $this->convenzione('CSIS00002B', '2026-09-01', '2027-08-31');
        $this->db->esegui("INSERT INTO convenzioni_allegati (convenzione_id, file, stato) VALUES (?, 'uploads/convenzioni/x.pdf', 'da_vedere')", [$this->cvFermi]);
        $this->alFermi = (int) $this->db->valore('SELECT MAX(id) FROM convenzioni_allegati');
        $this->prFermi = $this->pren(200, ['scuola_codice' => 'CSPS00002B']);
        $this->prGalilei = $this->pren(200, ['scuola_codice' => 'CSPS00001A']);
        $this->db->esegui("INSERT INTO convenzioni_messaggi (compilazione_id, da, tipo, testo) VALUES (?, 'scuola', 'messaggio', 'Buongiorno')", [$this->ccFermi]);
    }

    protected function tearDown(): void
    {
        $this->rimuovi($this->radiceFile);
        parent::tearDown();
    }

    private function rimuovi(string $d): void
    {
        foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (basename($f) === '.' || basename($f) === '..') {
                continue;
            }
            is_dir($f) ? $this->rimuovi($f) : @unlink($f);
        }
        @rmdir($d);
    }

    private function compilazione(string $istituto, string $email, string $stato): int
    {
        return $this->db->inserisci('INSERT INTO convenzioni_compilate (token, scuola_codice, email, stato_iter, dati_json, aggiornata_il) VALUES (?, ?, ?, ?, ?, NOW())',
            [bin2hex(random_bytes(10)), $istituto, $email, $stato, '{"scuola":{"nome":"x"}}']);
    }

    /** Tutto ciò che un'azione potrebbe cambiare: righe delle tabelle, file sul disco, email partite. */
    private function fotografia(): array
    {
        $tabelle = [];
        foreach (['convenzioni_compilate', 'convenzioni_scuole', 'convenzioni_allegati', 'convenzioni_messaggi', 'prenotazioni', 'scuole_docenti', 'log_email', 'log_audit', 'valutazioni_fsl'] as $t) {
            $tabelle[$t] = $this->db->righe("SELECT * FROM `$t` ORDER BY 1");
        }
        $file = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->radiceFile, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $file[] = $f->getPathname() . ':' . $f->getSize();
        }
        sort($file);

        return ['tabelle' => $tabelle, 'file' => $file, 'email' => count($this->mailer->inviate)];
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function azioniConIdDiUnAltraScuola(): array
    {
        // i segnaposto «{cc}», «{cc2}», «{gal}», «{cv}», «{al}», «{pr}», «{prg}» si sostituiscono con gli id veri
        $mod = ['data_stipula' => '2026-10-01', 'scadenza' => '2027-09-30'];

        return [
            'controllo' => [['cv_controllo' => '{cc}'] + $mod],
            'prendi a mano' => [['cv_prendi' => '{cc}', 'prot_ingresso' => '0001234', 'prot_ingresso_data' => '2026-10-02'] + $mod],
            'invia al protocollo' => [['cv_titulus' => '{cc}'] + $mod],
            'rifiuta' => [['cv_rifiuta' => '{cc2}', 'motivo' => 'Manca la firma']],
            'invia al direttore' => [['cv_invia_direttore' => '{cc}']],
            'protocollo del firmato' => [['cv_titulus_def' => '{cc}'] + $mod],
            'registra il firmato' => [['cv_completa' => '{cc}', 'protocollo' => '0009999'] + $mod],
            'carica il protocollato' => [['cv_carica_protocollato' => '{cc}']],
            'riprova originale' => [['cv_riprova_originale' => '{cc}']],
            'esito incerto chiuso' => [['cv_incerto_chiudi' => '{cc}:ingresso']],
            'esito respinto' => [['cv_respinto' => '{cc}:definitivo']],
            'ripeti applicazione' => [['cv_ripeti_applicazione' => '{cc}']],
            'attività confermata (compilazione e prenotazione altrui)' => [['att_conferma' => '{cc}:{pr}']],
            'attività scartata (compilazione e prenotazione altrui)' => [['att_scarta' => '{cc}:{pr}']],
            'attività: compilazione propria, prenotazione altrui' => [['att_conferma' => '{gal}:{pr}']],
            'attività: compilazione altrui, prenotazione propria' => [['att_scarta' => '{cc}:{prg}']],
            'cambia referente' => [['cv_cambia_referente' => '1', 'cv_referente_id' => '{cc}', 'nuova_email' => 'nuovo@fermi.it']],
            'unisci: tutte e due altrui' => [['cv_unisci' => '1', 'unisci_resta' => '{cc}', 'unisci_assorbita' => '{cc2}']],
            'unisci: resta altrui, assorbita propria' => [['cv_unisci' => '1', 'unisci_resta' => '{cc}', 'unisci_assorbita' => '{gal}']],
            'unisci: resta propria, assorbita altrui' => [['cv_unisci' => '1', 'unisci_resta' => '{gal}', 'unisci_assorbita' => '{cc}']],
            'letto' => [['cv_letto' => '{cc}']],
            'promemoria del PDF' => [['cv_ricorda' => '{cc}']],
            'messaggio' => [['cv_messaggio' => '{cc}', 'testo' => 'Ciao']],
            'messaggio scelto da un menu' => [['cv_messaggio' => '1', 'cv_messaggio_scelta' => '{cc}', 'testo' => 'Ciao']],
            'promemoria dell\'elenco' => [['ricorda_elenco' => '{pr}']],
            'promemoria della valutazione' => [['ricorda_valutazione' => '{pr}']],
            'registra con la scuola di un\'altra' => [['conv_salva' => '1', 'scuola_codice' => ['conv' => 'CSIS00002B'], 'data_stipula' => '2026-10-01', 'scadenza' => '2027-09-30']],
            'modifica la convenzione di un\'altra' => [['conv_salva' => '1', 'conv_id' => '{cv}', 'scuola_codice' => ['conv' => 'CSIS00001A'], 'data_stipula' => '2026-10-01', 'scadenza' => '2027-09-30']],
            'elimina la convenzione di un\'altra' => [['conv_elimina' => '{cv}']],
            'accetta l\'Allegato A di un\'altra' => [['allegato_accetta' => '1', 'allegato_id' => '{al}']],
            'rifiuta l\'Allegato A di un\'altra' => [['allegato_rifiuta' => '1', 'allegato_id' => '{al}', 'nota' => 'No']],
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @dataProvider azioniConIdDiUnAltraScuola
     */
    public function testNessunaAzioneConUnIdDiUnAltraScuolaScriveMandaOTocca(array $post): void
    {
        $segnaposto = ['{cc}' => $this->ccFermi, '{cc2}' => $this->ccFermi2, '{gal}' => $this->ccGalilei, '{cv}' => $this->cvFermi, '{al}' => $this->alFermi, '{pr}' => $this->prFermi, '{prg}' => $this->prGalilei];
        $post = json_decode(strtr(json_encode($post, JSON_THROW_ON_ERROR), array_map('strval', $segnaposto)), true, 512, JSON_THROW_ON_ERROR);
        $prima = $this->fotografia();

        $r = $this->invia($post, ['k' => 'CSIS00001A']);

        self::assertSame(303, $r->codice());
        self::assertSame($prima, $this->fotografia(), 'nessuna scrittura, nessun file, nessuna email');
        self::assertStringContainsString('Non è di questa scuola', (string) $this->servizio(Flash::class)->html());
    }

    /** @dataProvider azioniATutteLeScuole */
    public function testLeAzioniATutteLeScuoleNonSiFannoDallaScheda(string $azione): void
    {
        $prima = $this->fotografia();

        $this->invia([$azione => '1'], ['k' => 'CSIS00001A']);

        self::assertSame($prima, $this->fotografia());
        self::assertStringContainsString("si fa dall'elenco", html_entity_decode((string) $this->servizio(Flash::class)->html(), ENT_QUOTES));
    }

    /** @return array<string, array{0: string}> */
    public static function azioniATutteLeScuole(): array
    {
        return ['promemoria a tutte le convenzioni' => ['cv_ricorda_tutte'], 'promemoria elenco a tutti' => ['ricorda_elenco_tutti'], 'verifica delle iscrizioni' => ['conv_verifica']];
    }

    public function testLeAzioniATutteLeScuoleDalLElencoPassano(): void
    {
        $this->invia(['ricorda_elenco_tutti' => '1']);   // dall'elenco, senza chiave

        $messaggio = html_entity_decode((string) $this->servizio(Flash::class)->html(), ENT_QUOTES);
        self::assertStringNotContainsString("si fa dall'elenco", $messaggio);
        self::assertStringNotContainsString('Non è di questa scuola', $messaggio);
        self::assertNotSame('', $messaggio, 'un messaggio di esito c\'è');
    }

    public function testUnIdDiUnaScuolaPassaDallElencoSoloSeNonEATutte(): void
    {
        $prima = $this->fotografia();

        $this->invia(['cv_letto' => (string) $this->ccFermi]);   // dall'elenco (nessuna scuola aperta): nessun id è «di questa scuola»

        self::assertSame($prima, $this->fotografia());
    }

    public function testUnIdDellaScuolaApertaPassaLaGuardia(): void
    {
        $this->db->esegui("INSERT INTO convenzioni_messaggi (compilazione_id, da, tipo, testo) VALUES (?, 'scuola', 'messaggio', 'Ciao')", [$this->ccGalilei]);

        $this->invia(['cv_letto' => (string) $this->ccGalilei], ['k' => 'CSIS00001A']);

        self::assertStringNotContainsString('Non è di questa scuola', (string) $this->servizio(Flash::class)->html());
        self::assertNotNull($this->db->valore('SELECT letto_il FROM convenzioni_messaggi WHERE compilazione_id = ?', [$this->ccGalilei]), 'segnato come letto');
        self::assertNull($this->db->valore('SELECT letto_il FROM convenzioni_messaggi WHERE compilazione_id = ?', [$this->ccFermi]), 'quello del Fermi non si è mosso');
    }

    public function testUnaSedeVaBeneComeIlSuoIstituto(): void
    {
        // una compilazione scritta con il codice di una SEDE (CSPS00001A) è dell'istituto CSIS00001A: la guardia guarda l'istituto
        $sede = $this->compilazione('CSPS00001A', 'sede@galilei.it', 'caricata_scuola');
        $this->db->esegui("INSERT INTO convenzioni_messaggi (compilazione_id, da, tipo, testo) VALUES (?, 'scuola', 'messaggio', 'Ciao')", [$sede]);

        $this->invia(['cv_letto' => (string) $sede], ['k' => 'CSIS00001A']);

        self::assertNotNull($this->db->valore('SELECT letto_il FROM convenzioni_messaggi WHERE compilazione_id = ?', [$sede]));
    }

    public function testChiHaSoloLeConvenzioniNonFaLeAzioniDeiNumeriNeDelleValutazioni(): void
    {
        $this->entra(2);
        $prima = $this->fotografia();

        $this->invia(['ricorda_valutazione' => (string) $this->prGalilei], ['k' => 'CSIS00001A']);
        $this->invia(['conv_verifica' => '1']);

        self::assertSame($prima, $this->fotografia());
        self::assertStringContainsString('chi gestisce tutta la Formazione Scuola Lavoro', html_entity_decode((string) $this->servizio(Flash::class)->html(), ENT_QUOTES));
    }

    public function testUnaScuolaScrittaAManoNonHaCompilazioniNeConvenzioni(): void
    {
        $nome = 'Istituto Rossi di Altrove';
        $this->pren(200, ['scuola_codice' => null, 'dati_custom_json' => '{"numero_partecipanti":"5","nome_della_scuola":"' . $nome . '"}']);
        $chiave = 'x' . substr(md5(mb_strtolower($nome)), 0, 12);
        $prima = $this->fotografia();

        foreach ([['cv_letto' => (string) $this->ccFermi], ['conv_elimina' => (string) $this->cvFermi], ['docente_salva' => '1', 'email' => 'a@b.it', 'nome' => 'A'], ['ricorda_elenco' => (string) $this->prFermi]] as $post) {
            $this->invia($post, ['k' => $chiave]);
        }

        self::assertSame($prima, $this->fotografia());
    }
}
