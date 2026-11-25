<?php

declare(strict_types=1);

namespace Tests\Integration\ConvenzioniTesi;

use App\Anagrafi\ClientApiAteneo;
use App\ConvenzioniTesi\AziendaDellaPratica;
use App\ConvenzioniTesi\Costanti;
use App\ConvenzioniTesi\OriginaleDaTitulus;
use App\ConvenzioniTesi\ProtocollaConvenzioneTesiInTitulus;
use App\ConvenzioniTesi\ProtocollaDefinitivaTesiInTitulus;
use App\ConvenzioniTesi\ProtocolloAutomaticoConvenzioneTesi;
use App\ConvenzioniTesi\ServizioConvenzioniAzienda;
use App\ConvenzioniTesi\ServizioConvenzioniTesi;
use App\Core\Container;
use App\Core\Database;
use App\Core\Orologio;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Pdf\Documento;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Infrastructure\Sicurezza\Cifratura;
use App\Protocollo\InvioProtocollo;
use App\Protocollo\ProtocolloRepository;
use App\Tutorato\FirmaRemota;
use App\Uffici\Direzione;
use Tests\Doppi\AnagrafiClientApiFinto;
use Tests\Doppi\FirmaRemotaFinta;
use Tests\Doppi\FirmeFinte;
use Tests\Doppi\OrologioFisso;
use Tests\Doppi\TitulusFintoFsl;
use Tests\Integration\ConDirezione;
use Tests\Integration\DatabaseDiProva;
use Unical\Titulus\FabbricaClienteTitulus;

/**
 * L'iter delle convenzioni di tesi dopo la firma del Direttore (passo 6): protocollo automatico (acceso, spento, errore, risposta persa, avviso sulla firma),
 * file originale ripreso da Titulus (non arrivato, impronta diversa, riprova, caricato a mano), mai due protocolli, avvisi della stipula una volta sola,
 * azienda già convenzionata (per codice fiscale / partita IVA, mai per nome) e link personali solo all'email verificata.
 * Titulus è sempre il finto: nessuna chiamata vera.
 */
final class IterConvenzioneTesiTest extends DatabaseDiProva
{
    use ConDirezione;

    private Container $c;
    private MailerCheFallisce $mailer;
    private TitulusFintoFsl $titulus;
    private string $radice;

    protected function tabelle(): array
    {
        $t = $this->tabelleUffici() + [
            'utenti' => 'CREATE TABLE utenti (id INT PRIMARY KEY, email VARCHAR(150) NULL, ruolo_id INT DEFAULT 5, codice_fiscale VARCHAR(16) NULL)',
            'impostazioni_sistema' => 'CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, ' . self::COLONNE_ASSENZA . ', tesi_direttore_titolo VARCHAR(20) NOT NULL DEFAULT \'Prof.\', tesi_direttore_nome VARCHAR(150) NOT NULL DEFAULT \'\', tesi_direttore_nato_a VARCHAR(100) NOT NULL DEFAULT \'\', tesi_direttore_nato_il DATE NULL)',
            'convenzioni_tesi' => self::tabellaVera('convenzioni_tesi'),
            'pratiche' => self::tabellaVera('pratiche'),
            'pratiche_firme' => self::tabellaVera('pratiche_firme'),
        ];
        foreach (['titulus_config', 'titulus_regole', 'titulus_cc', 'titulus_invii', 'titulus_voci_indice'] as $nome) {
            $t[$nome] = self::tabellaVera($nome);
        }

        return $t;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->allineaColonne();
        $this->radice = sys_get_temp_dir() . '/tesi_iter_' . bin2hex(random_bytes(4));
        mkdir($this->radice . '/modelli_documenti', 0755, true);
        copy(dirname(__DIR__, 3) . '/modelli_documenti/convenzione_tesi.docx', $this->radice . '/modelli_documenti/convenzione_tesi.docx');
        mkdir($this->radice . '/assets/modelli', 0755, true);
        copy(dirname(__DIR__, 3) . '/assets/modelli/logo_lettera_incarico.jpg', $this->radice . '/assets/modelli/logo_lettera_incarico.jpg');
        $this->mailer = new MailerCheFallisce();
        $this->titulus = new TitulusFintoFsl();
        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Mailer::class, $this->mailer);
        $c->istanza(Orologio::class, new OrologioFisso('2026-10-19 09:00:00'));
        $c->istanza(Sito::class, new Sito($this->radice, 'https://portale.test/eventi'));
        $c->istanza(VerificaFirme::class, new FirmeFinte());
        $c->istanza(FirmaRemota::class, new FirmaRemotaFinta());
        $c->istanza(ClientApiAteneo::class, new AnagrafiClientApiFinto());
        $c->istanza(Cifratura::class, new Cifratura('una-frase-lunga-almeno-sedici'));
        \App\Protocollo\Registrazione::registra($c);
        \App\ConvenzioniTesi\Registrazione::registra($c);
        $c->istanza(FabbricaClienteTitulus::class, $this->titulus);   // dopo la registrazione, che mette la fabbrica vera
        $this->c = $c;
        $this->db->esegui('INSERT INTO impostazioni_sistema (id) VALUES (1)');
        $this->db->esegui("INSERT INTO utenti (id, email, ruolo_id) VALUES (1, 'admin@x.it', 1), (2, 'direttore@unical.it', 4)");
        $this->db->esegui("INSERT INTO uffici (nome, chiave) VALUES ('Ufficio convenzioni di tesi', 'convenzioni_tesi')");
        $this->db->esegui("INSERT INTO uffici_persone (ufficio_id, email, nominativo) VALUES (1, 'protocollo@unical.it', 'Anna Protocollo')");
        $this->impostaDirettore('Mario Bianchi', 'direttore@unical.it');
        $cifratura = new Cifratura('una-frase-lunga-almeno-sedici');
        $this->db->esegui("INSERT INTO titulus_config (id, attivo, url_ws, username, aoo, password_cifrata) VALUES (1, 1, 'https://titulus-unical.pp.cineca.it/titulus_ws/services/Titulus4?wsdl', 'portale', 'ABC', ?)", [$cifratura->cifra('segreta')]);
        $this->db->esegui("INSERT INTO titulus_regole (nome, applica_a, tipo_documento, uo, cod_titolario) VALUES ('Ingresso', 'convenzione_tesi', 'arrivo', '2014', '3/2'), ('Definitivo', 'convenzione_tesi_definitivo', 'partenza', '2014', '3/2')");
    }

    protected function tearDown(): void
    {
        $this->rimuovi($this->radice);
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

    // ---- attrezzi ------------------------------------------------------------------------------------------------------------------

    private function s(): ServizioConvenzioniTesi
    {
        return $this->c->get(ServizioConvenzioniTesi::class);
    }

    private function auto(): ProtocolloAutomaticoConvenzioneTesi
    {
        return $this->c->get(ProtocolloAutomaticoConvenzioneTesi::class);
    }

    private function definitivo(): ProtocollaDefinitivaTesiInTitulus
    {
        return $this->c->get(ProtocollaDefinitivaTesiInTitulus::class);
    }

    private function ingresso(): ProtocollaConvenzioneTesiInTitulus
    {
        return $this->c->get(ProtocollaConvenzioneTesiInTitulus::class);
    }

    private function originale(): OriginaleDaTitulus
    {
        return $this->c->get(OriginaleDaTitulus::class);
    }

    private function automatico(bool $acceso): void
    {
        $this->c->get(ProtocolloRepository::class)->salvaAutomatici(false, $acceso);
    }

    /** @return array<string, string|null> */
    private function riga(int $id): array
    {
        return $this->s()->perId($id) ?? [];
    }

    private const POST = ['ragione_sociale' => 'BioLab Calabria S.r.l.', 'forma_giuridica' => 'S.r.l.', 'piva' => '01234567890', 'cf' => '01234567890', 'comune' => 'Rende', 'provincia' => 'cs',
        'indirizzo' => 'Via Pietro Bucci 12', 'pec' => 'BioLab@pec.example.it', 'rappr_titolo' => 'Dott.ssa', 'rappr_nome' => 'Maria Rossi', 'rappr_qualifica' => 'Amministratore Unico',
        'settore' => 'Biotecnologie', 'sito' => 'www.biolab.example.it', 'consenso_elenco' => '1'];

    /** Un PDF vero (si può stampigliare) con un «%%FIRMA» in coda: per FirmeFinte vale una firma. */
    private function pdf(string $testo, int $firme = 1): string
    {
        $d = new Documento();
        $d->paragrafo($testo);

        return $d->pdf() . str_repeat("\n%%FIRMA\n", $firme);
    }

    /** Un'azienda che ha compilato i dati, caricato il PDF firmato, e l'ufficio ha preso in carico: la convenzione è dal Direttore (con la validità decisa). */
    private function daFirmare(string $email = 'info@biolab.it', array $post = [], bool $validita = true): int
    {
        [$e, $id] = $this->s()->avvia($email, 'BioLab', Costanti::ORIGINE_INVITO, 'Anna');
        $this->assertNull($e);
        $this->assertSame([], $this->s()->salvaDati($this->riga($id), $post + self::POST));
        $this->assertNull($this->s()->carica($this->riga($id), $this->pdf('Convenzione firmata dall\'azienda ' . $email)));
        $es = $this->s()->protocolloIngresso($id, '1234', '2026-10-20', 'Anna');
        $this->assertNull($es['errore']);
        $this->assertNull($es['direttore']);
        if ($validita) {
            $this->assertNull($this->s()->salvaControllo($id, '2026-10-25', '2031-10-24'));
        }

        return $id;
    }

    private function firma(int $id, ?string $firmato = null): ?string
    {
        $c = $this->riga($id);

        return $this->s()->caricaFirmatoDirettore($c, $firmato ?? ((string) file_get_contents($this->radice . '/' . $c['file_firmato']) . "\n%%FIRMA\n"));
    }

    /** Una convenzione firmata dal Direttore, con l'automatico spento (come dopo una firma senza interruttore). */
    private function firmata(string $email = 'info@biolab.it', array $post = [], bool $validita = true): int
    {
        $this->automatico(false);
        $id = $this->daFirmare($email, $post, $validita);
        $this->assertNull($this->firma($id));
        $this->assertSame('firmata_direttore', $this->riga($id)['stato']);

        return $id;
    }

    private function dati(): array
    {
        return ['valida_dal' => '2026-10-25', 'valida_al' => '2031-10-24'];
    }

    private function contenuto(string $relativo): string
    {
        return (string) file_get_contents($this->radice . '/' . $relativo);
    }

    private function mailA(string $a): int
    {
        return count(array_filter($this->mailer->inviate, static fn (array $m): bool => $m['a'] === $a));
    }

    private function collegaPratica(int $convenzione, string $studente, ?string $relatore): void
    {
        $p = $this->db->inserisci("INSERT INTO pratiche (modulo_id, utente_id, codice, nome, cognome, email, convenzione_tesi_id) VALUES (1, 5, ?, 'Luca', 'Rossi', ?, ?)", ['P' . bin2hex(random_bytes(3)), $studente, $convenzione]);
        if ($relatore !== null) {
            $this->db->esegui("INSERT INTO pratiche_firme (pratica_id, tipo, email, token) VALUES (?, 'persona', ?, ?)", [$p, $relatore, bin2hex(random_bytes(8))]);
        }
    }

    // ---- protocollo automatico dopo la firma ---------------------------------------------------------------------------------------

    public function testConLInterruttoreAccesoLaFirmaDelDirettoreProtocollaERegistra(): void
    {
        $this->automatico(true);
        $id = $this->daFirmare();
        $this->collegaPratica($id, 'luca@studenti.it', 'relatore@unical.it');
        $this->mailer->inviate = [];

        $this->assertNull($this->firma($id));   // la firma passa dal gancio del container: parte il protocollo automatico

        $r = $this->riga($id);
        $this->assertSame('protocollata', $r['stato']);
        $this->assertSame(1, $this->titulus->salvataggi);
        $this->assertStringContainsString('2026-UCALPRG-', (string) $r['protocollo']);
        $this->assertSame(['2026-10-25', '2031-10-24'], [$r['valida_dal'], $r['valida_al']]);
        // il PDF firmato dal Direttore resta identico byte per byte a quello che Titulus ha ricevuto
        $this->assertSame($this->contenuto((string) $r['file_definitivo']), $this->titulus->ultimo);
        // l'originale arriva da Titulus senza stampigliatura, con l'impronta controllata, nella sua colonna
        $this->assertSame('ok', $r['originale_stato']);
        $this->assertStringStartsWith('uploads/convenzioni_tesi/definitive/', (string) $r['file_originale']);
        $this->assertSame($this->titulus->ultimo, $this->contenuto((string) $r['file_originale']));
        $this->assertNotSame($r['file_originale'], $r['file_definitivo']);
        // il documento protocollato per l'azienda è una copia stampigliata «per consultazione»
        $this->assertSame('copia', $r['protocollato_origine']);
        $this->assertNotSame($this->contenuto((string) $r['file_definitivo']), $this->contenuto((string) $r['file_protocollato']), 'la copia ha la stampigliatura, il firmato no');
        // gli avvisi: azienda, studente e relatore, ognuno una volta
        $this->assertSame(1, $this->mailA('info@biolab.it'));
        $this->assertSame(1, $this->mailA('luca@studenti.it'));
        $this->assertSame(1, $this->mailA('relatore@unical.it'));
    }

    public function testConLInterruttoreSpentoNonSuccedeNiente(): void
    {
        $this->automatico(false);
        $id = $this->daFirmare();

        $this->assertNull($this->firma($id));

        $this->assertSame(0, $this->titulus->salvataggi);
        $this->assertSame('firmata_direttore', $this->riga($id)['stato']);
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM titulus_invii'));
    }

    public function testLaFirmaNonFalliscePerUnErroreDiTitulusEIlMotivoLoVedeLUfficio(): void
    {
        $this->automatico(true);
        $id = $this->daFirmare();
        $this->titulus->modo = 'errore';

        $this->assertNull($this->firma($id), 'la firma del Direttore non fallisce mai per Titulus');

        $r = $this->riga($id);
        $this->assertSame('firmata_direttore', $r['stato'], 'la firma resta salvata');
        $this->assertStringContainsString('protocollo automatico non è riuscito', (string) $r['protocollo_errore']);
        $this->titulus->modo = 'ok';
        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 1)['errore']);
        $this->assertSame('protocollata', $this->riga($id)['stato']);
        $this->assertSame('', (string) $this->riga($id)['protocollo_errore']);
    }

    public function testConUnaRispostaPersaNonSiRiprovaMaiDaSoli(): void
    {
        $this->automatico(true);
        $id = $this->daFirmare();
        $this->titulus->modo = 'persa';

        $this->assertNull($this->firma($id));

        $this->assertSame(1, $this->titulus->salvataggi);
        $c = $this->riga($id);
        $rif = $this->s()->rifDefinitivo($c);
        $this->assertSame('incerto', $this->c->get(InvioProtocollo::class)->stato($rif, $id, ProtocollaDefinitivaTesiInTitulus::RIF)['stato']);
        $this->assertSame('firmata_direttore', $c['stato']);
        $this->assertStringContainsString('INCERTO', (string) $c['protocollo_errore']);

        // né un secondo automatico né il pulsante riprovano: l'esito lo decide l'ufficio
        $this->titulus->modo = 'ok';
        $this->assertStringContainsString('INCERTO', (string) $this->auto()->dopoFirma($id));
        $this->assertStringContainsString('INCERTO', (string) $this->definitivo()->protocolla($id, $this->dati(), '', 1)['errore']);
        $this->assertSame(1, $this->titulus->salvataggi, 'Titulus non è stato richiamato');

        // l'ufficio ha cercato in Titulus: il documento non c'è, si può inviare di nuovo
        $this->assertNull($this->c->get(InvioProtocollo::class)->chiudiIncerto($rif, $id, 'cercato, non c\'è', 1));
        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 1)['errore']);
        $this->assertSame(2, $this->titulus->salvataggi);
        $this->assertSame('protocollata', $this->riga($id)['stato']);
    }

    public function testConUnaRispostaPersaIlNumeroAManoChiudeLIncertezzaSenzaUnSecondoProtocollo(): void
    {
        $id = $this->firmata();
        $this->titulus->modo = 'persa';
        $this->assertStringContainsString('INCERTO', (string) $this->definitivo()->protocolla($id, $this->dati(), '', 1)['errore']);

        $r = $this->definitivo()->completaAMano($id, ['protocollo' => 'UCAL-123', 'protocollo_data' => '2026-10-26'] + $this->dati());

        $this->assertNull($r['errore'], (string) $r['errore']);
        $this->assertSame('protocollata', $this->riga($id)['stato']);
        $this->assertSame(1, $this->titulus->salvataggi);
        $this->assertSame('UCAL-123', $this->riga($id)['protocollo']);
    }

    public function testUnNumeroAManoVuotoNonChiudeLIncertezzaNeRegistraNiente(): void
    {
        $id = $this->firmata();
        $this->titulus->modo = 'persa';
        $this->definitivo()->protocolla($id, $this->dati(), '', 1);

        $r = $this->definitivo()->completaAMano($id, ['protocollo' => ''] + $this->dati());

        $this->assertNotNull($r['errore']);
        $this->assertSame('firmata_direttore', $this->riga($id)['stato']);
        $this->assertSame('incerto', $this->c->get(InvioProtocollo::class)->stato($this->s()->rifDefinitivo($this->riga($id)), $id, ProtocollaDefinitivaTesiInTitulus::RIF)['stato']);
    }

    public function testConUnAvvisoSullaFirmaOSenzaDateNonPartePerConto(): void
    {
        $con = $this->firmata('uno@biolab.it');   // firmata con l'automatico spento
        $this->db->esegui("UPDATE convenzioni_tesi SET avviso_firma = 'la firma non copre tutto il file' WHERE id = ?", [$con]);
        $this->automatico(true);
        $this->assertStringContainsString('avviso sulla firma', (string) $this->auto()->dopoFirma($con));
        $this->assertStringContainsString('avviso sulla firma', (string) $this->riga($con)['protocollo_errore']);
        $this->assertSame('firmata_direttore', $this->riga($con)['stato']);

        $senza = $this->daFirmare('due@altra.it', ['piva' => '09876543210', 'cf' => '09876543210'], false);
        $this->assertNull($this->firma($senza));   // automatico acceso: parte il gancio, ma mancano le date
        $this->assertStringContainsString('date di validità', (string) $this->riga($senza)['protocollo_errore']);

        $this->assertSame(0, $this->titulus->salvataggi);
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM titulus_invii'));
    }

    // ---- file originale da Titulus --------------------------------------------------------------------------------------------------

    public function testSeIlFileNonArrivaSiTieneQuelloDelPortaleEIlCronRiprova(): void
    {
        $id = $this->firmata();
        $this->titulus->modoFile = 'nessuno';

        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 1)['errore']);

        $r = $this->riga($id);
        $this->assertSame('protocollata', $r['stato'], 'l\'iter si conclude lo stesso');
        $this->assertSame('mancante', $r['originale_stato']);
        $this->assertNotSame('', (string) $r['originale_avviso']);
        $this->assertNull($r['file_originale']);
        $this->assertSame(0, $this->originale()->riprovaTutti(), 'appena provato: il cron aspetta il giorno dopo');
        $this->db->esegui('UPDATE convenzioni_tesi SET originale_il = ? WHERE id = ?', ['2026-10-17 09:00:00', $id]);
        $this->assertSame(0, $this->originale()->riprovaTutti(), 'Titulus non ha ancora il file');
        $this->assertSame(2, (int) $this->riga($id)['originale_tentativi']);

        $this->titulus->modoFile = 'originale';
        $this->db->esegui('UPDATE convenzioni_tesi SET originale_il = ? WHERE id = ?', ['2026-10-17 09:00:00', $id]);
        $this->assertSame(1, $this->originale()->riprovaTutti());
        $r = $this->riga($id);
        $this->assertSame('ok', $r['originale_stato']);
        $this->assertFileExists($this->radice . '/' . $r['file_originale']);
        $this->assertSame(1, $this->titulus->salvataggi, 'rileggere il file non crea un nuovo protocollo');
        $this->assertSame(0, $this->originale()->riprovaTutti(), 'una volta arrivato non si riprova più');
    }

    public function testDopoOttoTentativiIlCronSiFermaMaIlPulsanteResta(): void
    {
        $id = $this->firmata();
        $this->titulus->modoFile = 'nessuno';
        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 1)['errore']);
        $this->db->esegui('UPDATE convenzioni_tesi SET originale_tentativi = 8, originale_il = ? WHERE id = ?', ['2026-09-01 12:00:00', $id]);

        $this->titulus->modoFile = 'originale';
        $this->assertSame(0, $this->originale()->riprovaTutti());
        $this->assertTrue($this->originale()->scarica($id)['ok'], '«Riprova da Titulus» resta, anche a iter concluso');
        $this->assertSame('ok', $this->riga($id)['originale_stato']);
    }

    public function testConUnaImprontaDiversaSiTieneIlFileDelPortale(): void
    {
        $id = $this->firmata();
        $this->titulus->modoFile = 'impronta';

        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 1)['errore']);

        $r = $this->riga($id);
        $this->assertSame('protocollata', $r['stato']);
        $this->assertSame('impronta', $r['originale_stato']);
        $this->assertNull($r['file_originale'], 'il file con l\'impronta sbagliata non si conserva');
        $this->assertStringContainsString('impronta', (string) $r['originale_avviso']);
        $this->assertNotNull($this->s()->fileDaScaricare($id, 'finale'), 'l\'azienda ha comunque un documento');
    }

    public function testLOriginaleNonSiVedeMaiDaiLinkDellAzienda(): void
    {
        $id = $this->firmata();
        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 1)['errore']);
        $r = $this->riga($id);
        $this->assertSame('ok', $r['originale_stato']);

        $finale = $this->s()->fileDaScaricare($id, 'finale');
        $this->assertNotNull($finale);
        $this->assertNotSame($this->radice . '/' . $r['file_originale'], str_replace('\\', '/', (string) $finale['percorso']));
        $this->assertStringNotContainsString('originale', $finale['nome']);
        $this->assertNotNull($this->s()->fileDaScaricare($id, 'originale'), 'l\'ufficio lo scarica dal pannello');
    }

    public function testIlDocumentoProtocollatoCaricatoAManoVinceSuQuelloDelPortale(): void
    {
        $id = $this->firmata();
        $firmato = $this->contenuto((string) $this->riga($id)['file_definitivo']);
        $this->assertNull($this->s()->caricaProtocollato($id, "%PDF-1.7\nprotocollato dall'ufficio PRIMA\n%%EOF"));
        $this->assertTrue($this->s()->haProtocollatoAMano($this->riga($id)));

        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 1)['errore']);

        $r = $this->riga($id);
        $this->assertSame('mano', $r['protocollato_origine'], 'la copia stampigliata del portale non scavalca il file dell\'ufficio');
        $this->assertStringContainsString("PRIMA", $this->contenuto((string) $r['file_protocollato']));
        // anche a convenzione stipulata l'ufficio lo sostituisce; il firmato e l'originale non si toccano
        $originale = $this->contenuto((string) $r['file_originale']);
        $this->assertStringContainsString('PDF', (string) $this->s()->caricaProtocollato($id, 'non pdf'));
        $this->assertNull($this->s()->caricaProtocollato($id, "%PDF-1.7\nprotocollato dall'ufficio DOPO\n%%EOF"));
        $r = $this->riga($id);
        $this->assertStringContainsString('DOPO', $this->contenuto((string) $r['file_protocollato']));
        $this->assertSame($firmato, $this->contenuto((string) $r['file_definitivo']), 'il PDF firmato non si tocca mai');
        $this->assertSame($originale, $this->contenuto((string) $r['file_originale']));
    }

    // ---- mai due protocolli ---------------------------------------------------------------------------------------------------------

    public function testDoppioClicNonFaDueProtocolli(): void
    {
        $id = $this->firmata();

        $this->assertNull($this->definitivo()->protocolla($id, $this->dati(), '', 1)['errore']);
        $this->assertStringContainsString('già stata protocollata', (string) $this->definitivo()->protocolla($id, $this->dati(), '', 1)['errore']);
        $this->assertSame(1, $this->titulus->salvataggi);
    }

    /** Un altro processo tiene il blocco dell'invio di quel documento per qualche secondo. @return array{0: resource, 1: array<int, resource>} */
    private function altroProcessoTieneLInvio(string $rif, int $id): array
    {
        $nome = ProtocolloRepository::nomeBloccoInvio($rif, $id, $this->nomeDatabase());
        $pipe = [];
        $proc = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/tieni_blocco.php', $this->nomeDatabase(), $nome, '4'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
        $this->assertIsResource($proc);
        $this->assertSame('preso', trim((string) fgets($pipe[1])), "l'altro processo ha il blocco dell'invio");

        return [$proc, $pipe];
    }

    private function nomeDatabase(): string
    {
        return getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit';
    }

    private function chiudi($proc, array $pipe): void
    {
        fclose($pipe[1]);
        fclose($pipe[2]);
        proc_close($proc);
    }

    public function testMentreSiParlaConTitulusIlNumeroAManoDelDefinitivoNonPassa(): void
    {
        $id = $this->firmata();
        [$proc, $pipe] = $this->altroProcessoTieneLInvio($this->s()->rifDefinitivo($this->riga($id)), $id);

        $r = $this->definitivo()->completaAMano($id, ['protocollo' => 'UCAL-9'] + $this->dati());
        $this->chiudi($proc, $pipe);

        $this->assertNotNull($r['errore'], 'con un invio in corso il numero a mano è rifiutato');
        $this->assertSame('firmata_direttore', $this->riga($id)['stato']);
        $this->assertSame(0, $this->titulus->salvataggi);
    }

    public function testMentreSiParlaConTitulusIlNumeroInEntrataAManoNonPassa(): void
    {
        [$e, $id] = $this->s()->avvia('info@biolab.it', 'BioLab', Costanti::ORIGINE_INVITO, 'Anna');
        $this->s()->salvaDati($this->riga($id), self::POST);
        $this->s()->carica($this->riga($id), $this->pdf('azienda'));
        [$proc, $pipe] = $this->altroProcessoTieneLInvio($this->s()->rifIngresso($this->riga($id)), $id);

        $r = $this->ingresso()->protocolloAMano($id, '777', '2026-10-20', 'Anna');
        $this->chiudi($proc, $pipe);

        $this->assertNotNull($r['errore']);
        $this->assertSame('caricata', $this->riga($id)['stato']);
    }

    public function testDueProcessiVeriInContemporaneaFanno1SoloProtocollo(): void
    {
        $bootstrap = (string) ($GLOBALS['__PHPUNIT_BOOTSTRAP'] ?? '');
        if ($bootstrap === '' || !is_file($bootstrap)) {
            $this->markTestSkipped('Il bootstrap dei test non è noto: non posso avviare i processi paralleli');
        }
        $id = $this->firmata();
        $db = getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit';
        $contatore = $this->radice . '/chiamate.txt';
        file_put_contents($contatore, '');
        $parti = microtime(true) + 3.0;
        $figli = [];
        foreach ([0, 1, 2] as $i) {
            $pipe = [];
            $proc = proc_open([PHP_BINARY, '-d', 'extension=zip', dirname(__DIR__, 2) . '/Support/protocolla_definitivo_tesi.php', $bootstrap, $db, $this->radice, (string) $id, sprintf('%.3F', $parti), $contatore], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
            $this->assertIsResource($proc, "figlio $i");
            $figli[] = [$proc, $pipe];
        }
        $esiti = [];
        foreach ($figli as $i => [$proc, $pipe]) {
            $out = trim((string) stream_get_contents($pipe[1]));
            $err = trim((string) stream_get_contents($pipe[2]));
            fclose($pipe[1]);
            fclose($pipe[2]);
            proc_close($proc);
            $esiti[$i] = json_decode($out, true);
            $this->assertIsArray($esiti[$i], "figlio $i: $out $err");
        }

        $this->assertSame(1, strlen((string) file_get_contents($contatore)), 'Titulus è stato chiamato UNA volta sola da tre processi: ' . json_encode($esiti));
        $this->assertSame(1, count(array_filter($esiti, static fn (array $e): bool => $e['errore'] === null)), 'uno solo ha protocollato: ' . json_encode($esiti));
        $this->assertSame(1, (int) $this->db->valore("SELECT COUNT(*) FROM titulus_invii WHERE esito = 'ok'"));
        $this->assertSame('protocollata', $this->riga($id)['stato']);
    }

    public function testUnRifiutoDuranteLaFirmaDelDirettoreNonVieneScavalcato(): void
    {
        $this->automatico(true);
        $id = $this->daFirmare();
        $this->assertSame('da_firmare', $this->riga($id)['stato']);
        // il Direttore firma con Aruba; mentre Aruba lavora l'ufficio rifiuta il file dell'azienda
        $remota = new class (fn () => $this->s()->rifiuta($id, 'Manca la pagina 3')) implements FirmaRemota {
            public function __construct(private \Closure $durante)
            {
            }

            public function disponibile(): bool
            {
                return true;
            }

            public function firma(string $pdf, string $utente, string $password, string $otp, ?array $aspetto = null, string $motivo = ''): array
            {
                ($this->durante)();

                return [$pdf . "\n%%FIRMA\n", null];
            }
        };
        $this->c->istanza(FirmaRemota::class, $remota);
        $this->c->istanza(ServizioConvenzioniTesi::class, $this->ricostruisci());

        $e = $this->s()->firmaConAruba($this->riga($id), 'utente', 'pin', '123456');

        $this->assertIsString($e);
        $this->assertStringContainsString('non è più', $e);
        $r = $this->riga($id);
        $this->assertSame('rifiutata', $r['stato'], 'il rifiuto dell\'ufficio resta');
        $this->assertNull($r['file_definitivo']);
        $this->assertSame(0, $this->titulus->salvataggi, 'nessun protocollo automatico');
    }

    /** Il servizio con la firma remota che c'è ora nel container (le dipendenze sono le stesse di Registrazione). */
    private function ricostruisci(): ServizioConvenzioniTesi
    {
        $c = $this->c;

        return (new ServizioConvenzioniTesi(
            $c->get(\App\ConvenzioniTesi\ConvenzioneTesiRepository::class), $c->get(\App\ConvenzioniTesi\ModelloConvenzione::class), $c->get(\App\ConvenzioniTesi\NotificheConvenzioniTesi::class), $c->get(Direzione::class),
            $c->get(\App\Uffici\UfficiRepository::class), $c->get(FirmaRemota::class), $c->get(\App\Tutorato\VerificaPdfFirmato::class), $c->get(VerificaFirme::class), $c->get(\App\Infrastructure\Storage\Upload::class),
            $c->get(Database::class), $c->get(Orologio::class), $c->get(Sito::class), $c->get(ProtocolloRepository::class)
        ))->conDopoFirma(fn (int $id) => $c->get(ProtocolloAutomaticoConvenzioneTesi::class)->dopoFirma($id));
    }

    // ---- T2: la firma Aruba fatta e non registrata perché il blocco è occupato si conserva e si registra dopo, senza un secondo OTP -----------

    /** La firma remota finta che conta le chiamate (un codice OTP vale una volta sola). */
    private function conArubaCheConta(): object
    {
        $remota = new class () implements FirmaRemota {
            public int $firme = 0;

            public function disponibile(): bool
            {
                return true;
            }

            public function firma(string $pdf, string $utente, string $password, string $otp, ?array $aspetto = null, string $motivo = ''): array
            {
                ++$this->firme;

                return [$pdf . "\n%%FIRMA\n", null];
            }
        };
        $this->c->istanza(FirmaRemota::class, $remota);
        $this->c->get(\App\ConvenzioniTesi\ConvenzioneTesiRepository::class)->impostaAttesaLunga(1);
        $this->c->istanza(ServizioConvenzioniTesi::class, $this->ricostruisci());

        return $remota;
    }

    /** Un altro processo tiene il blocco della convenzione per $secondi. @return array{0: resource, 1: array<int, resource>} */
    private function altroProcessoTieneLaConvenzione(int $id, int $secondi): array
    {
        $nome = \App\Fsl\ConvenzioneCompilataRepository::nomeBlocco('tesi_' . $id, $this->nomeDatabase());
        $pipe = [];
        $proc = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/tieni_blocco.php', $this->nomeDatabase(), $nome, (string) $secondi], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
        $this->assertIsResource($proc);
        $this->assertSame('preso', trim((string) fgets($pipe[1])), "l'altro processo ha il blocco della convenzione");

        return [$proc, $pipe];
    }

    /** @return list<string> i file conservati (PDF e descrizioni) */
    private function conservati(): array
    {
        return array_map('basename', glob($this->radice . '/uploads/firme_conservate/tesi_*') ?: []);
    }

    private function conservatoCon(string $estensione): string
    {
        return array_values(array_filter($this->conservati(), static fn (string $f): bool => str_ends_with($f, $estensione)))[0];
    }

    public function testFirmaArubaConBloccoOccupatoSiConservaESiRegistraAlTentativoSuccessivo(): void
    {
        $this->automatico(false);
        $id = $this->daFirmare();
        $aruba = $this->conArubaCheConta();
        [$proc, $pipe] = $this->altroProcessoTieneLaConvenzione($id, 6);

        $e = $this->s()->firmaConAruba($this->riga($id), 'utente', 'pin', '123456');

        $this->assertSame(ServizioConvenzioniTesi::FIRMA_CONSERVATA, $e, 'il Direttore legge che la firma è al sicuro e che non deve firmare di nuovo');
        $this->assertSame(1, $aruba->firme);
        $this->assertSame('da_firmare', $this->riga($id)['stato'], 'non è ancora registrata');
        $this->assertTrue($this->s()->haFirmaConservata($id));
        $this->assertCount(2, $this->conservati(), 'un PDF e la sua descrizione');
        $this->assertStringContainsString('Require all denied', $this->contenuto('uploads/firme_conservate/.htaccess'), 'la cartella è chiusa al web');
        $json = $this->contenuto('uploads/firme_conservate/' . $this->conservatoCon('.json'));
        $meta = json_decode($json, true);
        $this->assertSame($id, $meta['id'], 'legata alla convenzione');
        $this->assertSame(hash('sha256', $this->contenuto((string) $this->riga($id)['file_firmato'])), $meta['impronta_partenza'], 'e all\'impronta del file da cui si è partiti');
        $this->assertStringNotContainsString('pin', $json, 'niente password salvate');
        $this->assertStringNotContainsString('123456', $json, 'niente OTP salvato');

        $r = $this->s()->riprendiFirmaConservata($id);
        $this->assertSame('in_attesa', $r['esito'], 'il blocco è ancora occupato: la firma resta in attesa, senza chiamare Aruba');
        $this->assertSame('da_firmare', $this->riga($id)['stato']);

        $this->chiudi($proc, $pipe);

        $r = $this->s()->riprendiFirmaConservata($id);
        $this->assertSame('registrata', $r['esito'], $r['messaggio']);
        $dopo = $this->riga($id);
        $this->assertSame('firmata_direttore', $dopo['stato']);
        $this->assertStringContainsString('%%FIRMA', $this->contenuto((string) $dopo['file_definitivo']));
        $this->assertSame(1, $aruba->firme, 'Aruba è stata chiamata una volta sola: nessun secondo codice OTP');
        $this->assertSame([], $this->conservati(), 'la firma registrata non resta in giro');
        $this->assertNull($this->s()->riprendiFirmaConservata($id), 'una terza volta non c\'è più niente');
    }

    /** @return array<string, array{0: string}> */
    public static function cambiamentiCheScartanoLaFirma(): array
    {
        return ['rifiutata dall\'ufficio' => ['rifiuto'], 'nuovo link' => ['link'], 'PDF di partenza cambiato' => ['pdf']];
    }

    /** @dataProvider cambiamentiCheScartanoLaFirma */
    public function testLaFirmaConservataSiScartaSeLaConvenzioneECambiata(string $cambio): void
    {
        $this->automatico(false);
        $id = $this->daFirmare();
        $aruba = $this->conArubaCheConta();
        [$proc, $pipe] = $this->altroProcessoTieneLaConvenzione($id, 4);
        $this->assertSame(ServizioConvenzioniTesi::FIRMA_CONSERVATA, $this->s()->firmaConAruba($this->riga($id), 'utente', 'pin', '123456'));
        $this->chiudi($proc, $pipe);
        $file = (string) $this->riga($id)['file_firmato'];
        match ($cambio) {
            'rifiuto' => $this->assertNull($this->s()->rifiuta($id, 'Manca la pagina 3')),
            'link' => $this->db->esegui("UPDATE convenzioni_tesi SET token_direttore = 'altrolink' WHERE id = ?", [$id]),
            'pdf' => file_put_contents($this->radice . '/' . $file, $this->pdf('Un\'altra versione della convenzione')),
        };
        $statoPrima = $this->riga($id)['stato'];
        $mail = count($this->mailer->inviate);

        $r = $this->s()->riprendiFirmaConservata($id);

        $this->assertSame('scartata', $r['esito'], $cambio);
        $this->assertStringContainsString('ufficio', $r['messaggio']);
        $this->assertSame($statoPrima, $this->riga($id)['stato'], 'la convenzione non cambia per colpa della firma vecchia');
        $this->assertNull($this->riga($id)['file_definitivo'], 'nessun file firmato registrato');
        $this->assertSame([], $this->conservati(), 'il file conservato è scartato');
        $nuove = array_slice($this->mailer->inviate, $mail);
        $this->assertNotEmpty($nuove, 'l\'ufficio è avvisato');
        $this->assertStringContainsString('NON registrata', json_encode($nuove, JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertSame(1, $aruba->firme);
    }

    public function testIlPdfCaricatoAManoNonSiConservaERestaIlRicaricalo(): void
    {
        $this->automatico(false);
        $id = $this->daFirmare();
        $this->conArubaCheConta();
        [$proc, $pipe] = $this->altroProcessoTieneLaConvenzione($id, 4);

        $e = $this->firma($id);

        $this->chiudi($proc, $pipe);
        $this->assertSame(ServizioConvenzioniTesi::RICARICALO, $e);
        $this->assertSame([], $this->conservati(), 'un PDF caricato a mano non si conserva');
        $this->assertFalse($this->s()->haFirmaConservata($id));
    }

    public function testIlCronRegistraLeFirmeConservate(): void
    {
        $this->automatico(false);
        $id = $this->daFirmare();
        $aruba = $this->conArubaCheConta();
        [$proc, $pipe] = $this->altroProcessoTieneLaConvenzione($id, 4);
        $this->assertSame(ServizioConvenzioniTesi::FIRMA_CONSERVATA, $this->s()->firmaConAruba($this->riga($id), 'utente', 'pin', '123456'));
        $this->assertSame(0, $this->s()->riprendiTutteLeFirme(), 'con il blocco ancora occupato il cron non registra niente');
        $this->chiudi($proc, $pipe);

        $this->assertSame(1, $this->s()->riprendiTutteLeFirme());

        $this->assertSame('firmata_direttore', $this->riga($id)['stato']);
        $this->assertSame(0, $this->s()->riprendiTutteLeFirme(), 'una seconda corsa non fa niente');
        $this->assertSame(1, $aruba->firme);
    }

    public function testUnaFirmaConservataManomessaNonSiRegistra(): void
    {
        $this->automatico(false);
        $id = $this->daFirmare();
        $this->conArubaCheConta();
        [$proc, $pipe] = $this->altroProcessoTieneLaConvenzione($id, 4);
        $this->assertSame(ServizioConvenzioniTesi::FIRMA_CONSERVATA, $this->s()->firmaConAruba($this->riga($id), 'utente', 'pin', '123456'));
        $this->chiudi($proc, $pipe);
        file_put_contents($this->radice . '/uploads/firme_conservate/' . $this->conservatoCon('.pdf'), "%PDF-1.7\nun altro file\n%%EOF");

        $this->assertNull($this->s()->riprendiFirmaConservata($id), 'un file alterato non vale: scartato');

        $this->assertSame('da_firmare', $this->riga($id)['stato']);
        $this->assertSame([], $this->conservati());
    }

    // ---- protocollo in entrata ------------------------------------------------------------------------------------------------------

    public function testIlProtocolloInEntrataDaTitulusPortaLaConvenzioneAlDirettore(): void
    {
        [$e, $id] = $this->s()->avvia('info@biolab.it', 'BioLab', Costanti::ORIGINE_INVITO, 'Anna');
        $this->assertSame([], $this->s()->salvaDati($this->riga($id), self::POST));
        $this->assertNull($this->s()->carica($this->riga($id), $this->pdf('azienda')));
        $this->mailer->inviate = [];

        $r = $this->ingresso()->protocolla($id, 'Anna', 1);

        $this->assertNull($r['errore'], (string) $r['errore']);
        $this->assertNull($r['direttore']);
        $c = $this->riga($id);
        $this->assertSame('da_firmare', $c['stato']);
        $this->assertStringContainsString('2026-UCALPRG-', (string) $c['protocollo_ingresso']);
        $this->assertSame(1, $this->mailA('direttore@unical.it'), 'il Direttore riceve il link');
        $this->assertSame(1, $this->mailA('info@biolab.it'), 'l\'azienda riceve l\'avviso');
        $this->assertNotNull($this->ingresso()->protocolla($id, 'Anna', 1)['errore'], 'un secondo clic non protocolla di nuovo');
        $this->assertSame(1, $this->titulus->salvataggi);
    }

    public function testMentreUnInvioInEntrataECorsoLAziendaNonSostituisceIlFileELUfficioNonRifiuta(): void
    {
        [$e, $id] = $this->s()->avvia('info@biolab.it', 'BioLab', Costanti::ORIGINE_INVITO, 'Anna');
        $this->s()->salvaDati($this->riga($id), self::POST);
        $this->s()->carica($this->riga($id), $this->pdf('azienda'));
        $file = $this->riga($id)['file_firmato'];
        $visto = [];
        $this->titulus->inVolo = function () use ($id, &$visto): void {
            $visto['rifiuta'] = $this->s()->rifiuta($id, 'Manca la firma');
            $visto['carica'] = $this->s()->carica($this->riga($id), $this->pdf("file nuovo dell'azienda"));
        };

        $r = $this->ingresso()->protocolla($id, 'Anna', 1);

        $this->assertNull($r['errore'], (string) $r['errore']);
        $this->assertIsString($visto['rifiuta'] ?? null, "durante l'invio l'ufficio non rifiuta");
        $this->assertStringContainsString('riprova tra poco', mb_strtolower((string) $visto['rifiuta']));
        $this->assertIsString($visto['carica'] ?? null, "durante l'invio l'azienda non sostituisce il file");
        $this->assertStringContainsString('riprova tra poco', mb_strtolower((string) $visto['carica']));
        $this->assertSame('da_firmare', $this->riga($id)['stato'], "né rifiutata né sostituita: l'invio è andato in porto");
        $this->assertSame($file, $this->riga($id)['file_firmato']);
    }

    // ---- avvisi della stipula ------------------------------------------------------------------------------------------------------

    public function testGliAvvisiDellaStipulaArrivanoUnaVoltaSolaEUnaPostaRottaSiRifa(): void
    {
        $id = $this->firmata();
        $this->collegaPratica($id, 'a@studenti.it', 'rel1@unical.it');
        $this->collegaPratica($id, 'b@studenti.it', 'rel1@unical.it');   // lo stesso relatore su due pratiche: un solo avviso
        $this->mailer->inviate = [];
        $this->mailer->nonConsegnare = ['b@studenti.it'];

        $this->assertNull($this->s()->completa($id, ['protocollo' => '2201', 'protocollo_data' => '2026-10-25'] + $this->dati()));

        $this->assertSame(1, $this->mailA('info@biolab.it'));
        $this->assertSame(1, $this->mailA('a@studenti.it'));
        $this->assertSame(1, $this->mailA('rel1@unical.it'));
        $this->assertSame(1, $this->mailA('b@studenti.it'), 'un tentativo è stato fatto ma non è andato');
        $this->assertSame(['rel1@unical.it'], json_decode((string) $this->riga($id)['esito_registrazione'], true)['relatori']);

        // «Ripeti gli avvisi»: arriva solo quello che mancava
        $this->mailer->nonConsegnare = [];
        $this->mailer->inviate = [];
        $rep = $this->s()->avvisaStipula($id);
        $this->assertSame(['azienda' => false, 'studenti' => 1, 'relatori' => 0, 'restano' => 0], $rep);
        $this->assertSame(['b@studenti.it'], array_column($this->mailer->inviate, 'a'));
        $this->mailer->inviate = [];
        $this->assertSame(['azienda' => false, 'studenti' => 0, 'relatori' => 0, 'restano' => 0], $this->s()->avvisaStipula($id));
        $this->assertSame([], $this->mailer->inviate, 'nessun doppione');
    }

    public function testLaRegistrazioneNonSiFaPerUnaConvenzioneNonFirmataEGliAvvisiNemmeno(): void
    {
        $id = $this->daFirmare();

        $this->assertNotNull($this->s()->completa($id, ['protocollo' => '1'] + $this->dati()));
        $this->assertNull($this->s()->avvisaStipula($id), 'non stipulata: nessun avviso');
        $this->assertSame('da_firmare', $this->riga($id)['stato']);
    }

    // ---- azienda già convenzionata e dati degli altri ---------------------------------------------------------------------------------

    public function testUnaConvenzioneValidaSiRiconoscePerCodiceFiscaleEPartitaIvaMaiPerNome(): void
    {
        $id = $this->firmata();
        $this->assertNull($this->s()->completa($id, ['protocollo' => '2201', 'protocollo_data' => '2026-10-25'] + $this->dati()));

        // stessa partita IVA (con prefisso IT e spazi), email diversa e nome diverso: già convenzionata
        [$e, $altra] = $this->s()->avvia('altro@mail.it', 'Tutt\'altro nome', Costanti::ORIGINE_PUBBLICA);
        $err = $this->s()->salvaDati($this->riga($altra), ['ragione_sociale' => 'Tutt\'altro nome', 'piva' => 'IT 01234 567 890', 'cf' => ''] + self::POST);
        $this->assertStringContainsString('già una convenzione valida', implode(' ', $err));
        $this->assertSame('invitata', $this->riga($altra)['stato'], 'non si salva niente');
        // il messaggio non dice nulla dell'altra azienda
        $this->assertStringNotContainsString('BioLab', implode(' ', $err));

        // stesso NOME ma altro codice fiscale e altra partita IVA: un'azienda diversa, si può procedere
        [$e, $omonima] = $this->s()->avvia('omonima@mail.it', 'BioLab Calabria S.r.l.', Costanti::ORIGINE_PUBBLICA);
        $this->assertSame([], $this->s()->salvaDati($this->riga($omonima), ['piva' => '09876543210', 'cf' => '09876543210'] + self::POST));
    }

    public function testUnaConvenzioneInScadenzaSiPuoRinnovareQuindiNonBlocca(): void
    {
        $id = $this->firmata();
        // validità che finisce tra 30 giorni (oggi nei test è il 19/10/2026)
        $this->assertNull($this->s()->completa($id, ['protocollo' => '2201', 'valida_dal' => '2021-11-18', 'valida_al' => '2026-11-18'] + ['protocollo_data' => '2021-11-18']));

        [$e, $altra] = $this->s()->avvia('altro@mail.it', 'BioLab', Costanti::ORIGINE_PUBBLICA);
        $this->assertSame([], $this->s()->salvaDati($this->riga($altra), self::POST));
    }

    public function testIlLinkAUnaConvenzioneAltruiVaSoloAllEmailDellaPraticaMaiAChiHaScrittoUnNome(): void
    {
        // un'altra azienda ha una convenzione in corso: chi scrive un nome simile non ne vede né il nome né lo stato, né ne riceve il link
        $this->daFirmare('vera@biolab.it');
        $a = new AziendaDellaPratica($this->db, new OrologioFisso('2026-10-19 09:00:00'));

        $st = $a->stato(Costanti::ALTRA_AZIENDA, 'BioLab');

        $this->assertSame('da_chiedere', $st['esito']);
        $this->assertSame('BioLab', $st['azienda']);
        $this->assertSame('', $st['stato_in_corso']);
    }

    public function testLeConvenzioniDellaPersonaSiVedonoSoloConLEmailVerificata(): void
    {
        $this->daFirmare('mia@biolab.it');
        $srv = $this->c->get(ServizioConvenzioniAzienda::class);
        $base = ['id' => 7, 'email' => 'mia@biolab.it'];

        $this->assertCount(1, $srv->perUtente($base), 'email dal login di Ateneo (non personalizzata)');
        $this->assertCount(0, $srv->perUtente($base + ['email_personalizzata' => 1]), 'email scelta a mano e mai confermata: nessun link con il token');
        $this->assertCount(1, $srv->perUtente($base + ['email_personalizzata' => 1, 'email_verificata_il' => '2026-10-01 10:00:00']));
    }

    public function testIlDirettoreSiRiconosceSoloDallEmailVerificata(): void
    {
        $d = $this->c->get(Direzione::class);
        $dir = ['id' => 2, 'email' => 'direttore@unical.it'];

        $this->assertTrue($d->puoFirmare($dir));
        $this->assertTrue($d->direttoreOVice($dir));
        $this->assertFalse($d->puoFirmare($dir + ['email_personalizzata' => 1]), 'chi mette a mano l\'email del Direttore non firma');
        $this->assertFalse($d->direttoreOVice($dir + ['email_personalizzata' => 1]));
        $this->assertTrue($d->puoFirmare($dir + ['email_personalizzata' => 1, 'email_verificata_il' => '2026-10-01 10:00:00']));
        $this->assertFalse($this->s()->puoFirmare($dir + ['email_personalizzata' => 1]));
    }
}

/** Mailer dei test che si può far fallire per certi indirizzi. */
final class MailerCheFallisce implements Mailer
{
    /** @var list<array{a: string, oggetto: string, corpo: string, colore: ?string, allegati: array<int, mixed>}> */
    public array $inviate = [];
    /** @var list<string> */
    public array $nonConsegnare = [];

    public function invia(string $a, string $oggetto, string $corpoHtml, ?string $colore = null, array $allegati = []): bool
    {
        $this->inviate[] = ['a' => $a, 'oggetto' => $oggetto, 'corpo' => $corpoHtml, 'colore' => $colore, 'allegati' => $allegati];

        return !in_array($a, $this->nonConsegnare, true);
    }

    public function ultimoErrore(): string
    {
        return in_array(end($this->inviate)['a'] ?? '', $this->nonConsegnare, true) ? 'casella piena (prova)' : '';
    }
}
