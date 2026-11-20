<?php

declare(strict_types=1);

namespace Tests\Integration\Pubblicazioni;

use App\Core\Container;
use App\Core\Database;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Core\Schema\Definizioni;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Storage\Upload;
use App\Pubblicazioni\AllegatiPubblicazioni;
use App\Pubblicazioni\ColonneMonitoraggio;
use App\Pubblicazioni\Completamento;
use App\Pubblicazioni\ConfigurazioneCampi;
use App\Pubblicazioni\EsportazioneMonitoraggio;
use App\Pubblicazioni\PubblicazioniRepository;
use App\Pubblicazioni\ServizioPubblicazioni;
use Tests\Doppi\ClientFinto;
use Tests\Doppi\FileDiProva;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/**
 * Campi del form e colonne del file Excel personalizzati (passo AM4), con il database: salvataggio di quello che cambia (e solo di quello), campo
 * facoltativo accettato vuoto, campo nascosto non controllato e vuoto nell'export, colonna rinominata, «Torna all'originale», e i due conteggi
 * «da completare» (nel database e con Completamento) uguali anche con una colonna nascosta.
 */
final class ConfigurazioneCampiTest extends DatabaseDiProva
{
    private const DOCENTE = ['id' => 3, 'nome' => 'Maria', 'cognome' => 'Rossi', 'email' => 'maria.rossi@unical.it', 'ruolo_id' => 5, 'ruoli_secondari' => '6'];

    private Container $c;
    private string $cartella;

    protected function tabelle(): array
    {
        return [
            'pubblicazioni' => Definizioni::tabelle()['pubblicazioni'],
            'pubblicazioni_allegati' => Definizioni::tabelle()['pubblicazioni_allegati'],
            'pubblicazioni_campi' => Definizioni::tabelle()['pubblicazioni_campi'],
            'pubblicazioni_colonne' => Definizioni::tabelle()['pubblicazioni_colonne'],
            'ruoli' => 'CREATE TABLE ruoli (id INT PRIMARY KEY, nome VARCHAR(100))',
            'log_attivita' => 'CREATE TABLE log_attivita (id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT DEFAULT NULL, azione VARCHAR(255) NOT NULL, dettagli_json TEXT DEFAULT NULL, indirizzo_ip VARCHAR(45) DEFAULT NULL, data_ora DATETIME DEFAULT CURRENT_TIMESTAMP)',
            'impostazioni_sistema' => "CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, pubblicazioni_email_notifica VARCHAR(500) NOT NULL DEFAULT 'comunicazione.dibest@unical.it')",
        ];
    }

    protected function setUp(): void
    {
        if (self::$conn !== null) {
            self::$conn->query('DROP TABLE IF EXISTS `pubblicazioni_allegati`'); // punta a pubblicazioni (chiave esterna): va tolta per prima
        }
        parent::setUp();
        $this->db->esegui("INSERT INTO ruoli (id, nome) VALUES (6, 'Docenti')");
        $this->db->esegui('INSERT INTO impostazioni_sistema (id) VALUES (1)');
        $this->cartella = 'cache/prove_pubblicazioni_' . bin2hex(random_bytes(4)) . '/';
        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Orologio::class, new OrologioFisso('2026-11-19 10:00:00'));
        $c->istanza(IndirizzoClient::class, new ClientFinto());
        $c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
        $c->istanza(Mailer::class, new MailerFinto());
        $c->set(AllegatiPubblicazioni::class, fn (Container $c): AllegatiPubblicazioni => new AllegatiPubblicazioni($c->get(Upload::class), $c->get(Sito::class), $this->cartella, false));
        $this->c = $c;
    }

    protected function tearDown(): void
    {
        FileDiProva::pulisci();
        $dir = dirname(__DIR__, 3) . '/' . $this->cartella;
        foreach (glob($dir . '{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
        // il database di prova è condiviso tra le classi: le personalizzazioni (es. una colonna nascosta) non devono restare per gli altri test delle Pubblicazioni
        if (self::$conn !== null) {
            self::$conn->query('DROP TABLE IF EXISTS `pubblicazioni_campi`');
            self::$conn->query('DROP TABLE IF EXISTS `pubblicazioni_colonne`');
        }
    }

    private function cfg(): ConfigurazioneCampi
    {
        return $this->c->get(ConfigurazioneCampi::class);
    }

    private function servizio(): ServizioPubblicazioni
    {
        return $this->c->get(ServizioPubblicazioni::class);
    }

    private function repo(): PubblicazioniRepository
    {
        return $this->c->get(PubblicazioniRepository::class);
    }

    /** @param array<string, mixed> $cambi @return array<string, mixed> */
    private function dati(array $cambi = []): array
    {
        return array_merge([
            'data_evento' => '2026-12-05', 'url_docente' => 'https://www.unical.it/eventi/notte', 'altri_link_docente' => '',
            'titolo' => 'Notte dei ricercatori', 'sottotitolo' => 'Laboratori aperti', 'descrizione' => '<p>Una serata di <b>laboratori</b>.</p>',
            'modalita' => 'presenza', 'per_cittadinanza' => '1', 'note' => 'nessuna', 'tag' => 'DIBEST, notizie', 'data_inizio_pubblicazione' => '2026-11-20',
        ], $cambi);
    }

    /**
     * Quello che invia la scheda «Campi del form»: tutti i campi con i loro valori di adesso, più i cambi. Le caselle spente non si mandano.
     *
     * @param array<string, array<string, mixed>> $cambi chiave del campo => valori da cambiare
     * @return array<string, mixed>
     */
    private function postCampi(array $cambi = []): array
    {
        $post = [];
        $pos = [];
        foreach ($this->cfg()->campi() as $c) {
            $k = $c['chiave'];
            $pos[$c['passo']] = ($pos[$c['passo']] ?? 0) + 1;
            $v = ['etichetta' => $c['etichetta'], 'aiuto' => $c['aiuto'], 'obbligatorio' => $c['obbligatorio'], 'visibile' => $c['visibile'], 'ordine' => $pos[$c['passo']]];
            $v = array_merge($v, $cambi[$k] ?? []);
            foreach (['obbligatorio', 'visibile'] as $casella) {
                if (!$v[$casella]) {
                    unset($v[$casella]);
                } else {
                    $v[$casella] = '1';
                }
            }
            $post['campo'][$k] = $v;
        }

        return $post;
    }

    /** Come postCampi, per le colonne del file Excel (l'ordine è quello di adesso). @param array<string, array<string, mixed>> $cambi @return array<string, mixed> */
    private function postColonne(array $cambi = []): array
    {
        $post = [];
        foreach ($this->cfg()->colonne(true) as $i => $c) {
            $v = ['intestazione' => $c['intestazione'], 'a_cura_di' => $c['a_cura_di'], 'colore' => $c['colore'], 'tipo' => $c['tipo'], 'visibile' => $c['visibile'], 'ordine' => $i + 1];
            $v = array_merge($v, $cambi[$c['chiave']] ?? []);
            if (!$v['visibile']) {
                unset($v['visibile']);
            } else {
                $v['visibile'] = '1';
            }
            $post['colonna'][$c['chiave']] = $v;
        }

        return $post;
    }

    /** @return list<array<string, mixed>> */
    private function righeCampi(): array
    {
        return $this->db->righe('SELECT * FROM pubblicazioni_campi ORDER BY chiave');
    }

    /** @return list<array<string, mixed>> */
    private function righeColonne(): array
    {
        return $this->db->righe('SELECT * FROM pubblicazioni_colonne ORDER BY chiave');
    }

    /** Una riga già nel file Excel, scritta direttamente nella tabella. @param array<string, mixed> $cambi */
    private function rigaNelFile(array $cambi = [], bool $conOperatori = true): int
    {
        $r = ['stato' => 'pubblicata', 'nel_monitoraggio' => 1, 'data_evento' => '2026-12-05', 'richiedente' => 'Rossi Maria', 'titolo' => 'Iniziativa', 'sottotitolo' => 'Sotto', 'modalita' => 'mista', 'utente_id' => 3];
        if ($conOperatori) {
            foreach (ColonneMonitoraggio::colonneOperatori() as $k) {
                $r[$k] = 'x';
            }
        }

        return $this->repo()->inserisci(array_merge($r, $cambi));
    }

    // ───── salvataggio: solo le differenze ─────

    public function testSenzaCambiNonSiSalvaNiente(): void
    {
        $this->assertSame(['errori' => [], 'cambi' => []], $this->cfg()->aggiornaCampi($this->postCampi()));
        $this->assertSame(['errori' => [], 'cambi' => []], $this->cfg()->aggiornaColonne($this->postColonne()));
        $this->assertSame([], $this->righeCampi(), 'nessuna riga: valgono i valori del codice');
        $this->assertSame([], $this->righeColonne());
    }

    public function testSiSalvaSoloQuelloCheCambiaEIlRegistroDiceDaDoveAdove(): void
    {
        $esito = $this->cfg()->aggiornaCampi($this->postCampi(['sottotitolo' => ['etichetta' => 'Frase breve', 'obbligatorio' => false]]));
        $this->assertSame([], $esito['errori']);
        $this->assertSame(['Campo «Sottotitolo»: nome «Sottotitolo» → «Frase breve», obbligatorio obbligatorio → facoltativo'], $esito['cambi']);
        $righe = $this->righeCampi();
        $this->assertCount(1, $righe, 'una riga sola');
        $this->assertSame(['sottotitolo', 'Frase breve', null, 0, null, null], [$righe[0]['chiave'], $righe[0]['etichetta'], $righe[0]['aiuto'], (int) $righe[0]['obbligatorio'], $righe[0]['visibile'], $righe[0]['ordine']]);
        $this->assertSame('Frase breve', $this->cfg()->etichetta('sottotitolo'), 'la stessa istanza vede subito il cambio');
        $this->assertFalse($this->cfg()->obbligatorio('sottotitolo'));
        // salvando ancora lo stesso: niente da cambiare
        $this->assertSame([], $this->cfg()->aggiornaCampi($this->postCampi())['cambi']);
        // riportando il nome com'era, la riga sparisce del tutto nei punti dove era l'unica differenza
        $this->cfg()->aggiornaCampi($this->postCampi(['sottotitolo' => ['etichetta' => 'Sottotitolo', 'obbligatorio' => true]]));
        $this->assertSame([], $this->righeCampi());
    }

    public function testLeColonneSiSalvanoSoloSeCambiano(): void
    {
        $esito = $this->cfg()->aggiornaColonne($this->postColonne(['d_url_pubblicazione' => ['intestazione' => 'Link della pubblicazione', 'colore' => '#112233', 'a_cura_di' => 'Anna Verdi', 'tipo' => 'numero']]));
        $this->assertSame([], $esito['errori']);
        $this->assertCount(1, $esito['cambi']);
        $this->assertStringContainsString('Colonna «D · URL effettiva pubblicazione sito»: intestazione «URL effettiva pubblicazione sito (a cura di Marco Bocci o Emanuele Dodaro)» → «Link della pubblicazione»', $esito['cambi'][0]);
        $this->assertStringContainsString('colore «#00B050» → «#112233»', $esito['cambi'][0]);
        $this->assertStringContainsString('tipo Testo libero → Numero', $esito['cambi'][0]);
        $righe = $this->righeColonne();
        $this->assertCount(1, $righe);
        $this->assertSame(['d_url_pubblicazione', 'Link della pubblicazione', 'Anna Verdi', '#112233', 'numero', null, null, null], array_values($righe[0]));
        $col = $this->cfg()->colonna('d_url_pubblicazione');
        $this->assertSame(['Link della pubblicazione', 'Anna Verdi', '#112233', 'numero'], [$col['intestazione'], $col['a_cura_di'], $col['colore'], $col['tipo']]);
    }

    public function testLeRegoleFisseNonSiCambiano(): void
    {
        $esito = $this->cfg()->aggiornaCampi($this->postCampi([
            'titolo' => ['visibile' => false, 'obbligatorio' => false], 'data_evento' => ['visibile' => false, 'obbligatorio' => false],
            'richiedente' => ['visibile' => false], 'destinatari' => ['obbligatorio' => false], 'allegati' => ['obbligatorio' => false, 'visibile' => true],
        ]));
        $this->assertSame([], $esito['errori']);
        $this->assertSame([], $this->righeCampi(), 'visibilità e obbligo dei campi fissi si ignorano');
        $esito = $this->cfg()->aggiornaColonne($this->postColonne(['data_evento' => ['visibile' => false], 'richiedente' => ['visibile' => false], 'titolo' => ['visibile' => false, 'tipo' => 'numero', 'a_cura_di' => 'Chiunque'], 'stato' => ['visibile' => false]]));
        $this->assertSame([], $esito['errori']);
        $this->assertSame([], $this->righeColonne(), 'le colonne fisse restano, e il tipo e «a cura di» delle colonne del docente non si cambiano');
        $this->assertContains('stato', $this->cfg()->chiaviExcel());
        // ma il nome sì
        $this->cfg()->aggiornaCampi($this->postCampi(['titolo' => ['etichetta' => 'Nome dell\'iniziativa']]));
        $this->assertSame('Nome dell\'iniziativa', $this->cfg()->etichetta('titolo'));
        $this->assertTrue($this->cfg()->visibile('titolo'));
    }

    public function testErroriCampoPerCampoENienteSalvato(): void
    {
        $esito = $this->cfg()->aggiornaCampi($this->postCampi(['titolo' => ['etichetta' => ''], 'tag' => ['etichetta' => str_repeat('x', 201)], 'note' => ['aiuto' => str_repeat('a', 501)], 'sottotitolo' => ['etichetta' => 'Va bene']]));
        $this->assertSame(['campo_titolo', 'campo_tag', 'campo_note'], array_keys($esito['errori']));
        $this->assertSame([], $esito['cambi']);
        $this->assertSame([], $this->righeCampi(), 'con un errore non si salva nemmeno il resto');

        $esito = $this->cfg()->aggiornaColonne($this->postColonne([
            'c_altri_referenti' => ['intestazione' => ''], 'd_url_pubblicazione' => ['colore' => 'rosso'], 'e_url_altre_pagine' => ['a_cura_di' => ''],
            'f_pubblicazione_social' => ['tipo' => 'strano'], 'titolo' => ['intestazione' => str_repeat('i', 301)], 'j_tipo_iniziativa' => ['intestazione' => 'Va bene'],
        ]));
        $this->assertSame(['colonna_c_altri_referenti', 'colonna_d_url_pubblicazione', 'colonna_e_url_altre_pagine', 'colonna_f_pubblicazione_social', 'colonna_titolo'], array_keys($esito['errori']));
        $this->assertSame([], $this->righeColonne());
    }

    // ───── campo facoltativo / nascosto ─────

    public function testUnCampoResoFacoltativoSiAccettaVuoto(): void
    {
        $file = ['allegati' => [FileDiProva::pdf()]];
        $vuoti = $this->dati(['url_docente' => '', 'sottotitolo' => '', 'tag' => '']);
        $this->assertArrayHasKey('url_docente', $this->servizio()->invia(self::DOCENTE, $vuoti, $file)['errori'], 'prima: obbligatorio');

        $this->cfg()->aggiornaCampi($this->postCampi(['url_docente' => ['obbligatorio' => false], 'sottotitolo' => ['obbligatorio' => false], 'tag' => ['obbligatorio' => false]]));
        $esito = $this->servizio()->invia(self::DOCENTE, $vuoti, ['allegati' => [FileDiProva::pdf()]]);
        $this->assertSame([], $esito['errori']);
        $p = $this->repo()->perId($esito['id']);
        $this->assertSame(['', '', ''], [$p['url_docente'], $p['sottotitolo'], $p['tag']]);
        // facoltativo ma compilato male: si controlla lo stesso
        $storto = $this->servizio()->invia(self::DOCENTE, $this->dati(['url_docente' => 'non-un-url']), ['allegati' => [FileDiProva::pdf()]]);
        $this->assertArrayHasKey('url_docente', $storto['errori']);
    }

    public function testUnCampoNascostoNonSiControllaESiSalvaVuotoENellExportEVuoto(): void
    {
        $this->cfg()->aggiornaCampi($this->postCampi(['sottotitolo' => ['visibile' => false], 'tag' => ['visibile' => false]]));
        $this->assertSame(['sottotitolo', 'tag'], $this->cfg()->nascosti());
        // manda comunque un sottotitolo troppo lungo e un tag: non si controlla, e si butta
        $esito = $this->servizio()->invia(self::DOCENTE, $this->dati(['sottotitolo' => str_repeat('s', 400), 'tag' => '']), ['allegati' => [FileDiProva::pdf()]]);
        $this->assertSame([], $esito['errori']);
        $p = $this->repo()->perId($esito['id']);
        $this->assertSame(['', ''], [$p['sottotitolo'], $p['tag']], 'un campo nascosto vale vuoto');

        // una richiesta vecchia, arrivata quando il campo c'era: conserva il valore nel database ma l'export lo lascia vuoto
        $vecchia = $this->rigaNelFile(['titolo' => 'Vecchia', 'sottotitolo' => 'Sottotitolo di una volta']);
        $this->assertSame('Sottotitolo di una volta', $this->repo()->perId($vecchia)['sottotitolo']);
        $tab = EsportazioneMonitoraggio::tabella($this->repo()->elenco(), false, $this->cfg());
        $i = array_search('sottotitolo', $this->cfg()->chiaviExcel(), true);
        $this->assertCount(1, $tab['righe']);
        $this->assertSame('', $tab['righe'][0][$i], 'la colonna del campo nascosto è vuota');
        $this->assertSame('Vecchia', $tab['righe'][0][array_search('titolo', $this->cfg()->chiaviExcel(), true)]);

        // riaccesso, il valore torna nell'export
        $this->cfg()->ripristinaCampo('sottotitolo');
        $tab = EsportazioneMonitoraggio::tabella($this->repo()->elenco(), false, $this->cfg());
        $this->assertSame('Sottotitolo di una volta', $tab['righe'][0][$i]);
    }

    public function testUnPassoSenzaCampiVisibiliNonCompare(): void
    {
        $this->assertContains(5, $this->cfg()->passiVisibili());
        $this->cfg()->aggiornaCampi($this->postCampi(['allegati' => ['visibile' => false], 'immagini' => ['visibile' => false]]));
        $this->assertNotContains(5, $this->cfg()->passiVisibili());
        // niente file chiesti: la richiesta senza allegati si accetta, e quelli mandati lo stesso non si salvano
        $esito = $this->servizio()->invia(self::DOCENTE, $this->dati(), ['allegati' => [FileDiProva::pdf()], 'immagini' => []]);
        $this->assertSame([], $esito['errori']);
        $this->assertSame([], $this->repo()->allegati($esito['id']));
    }

    // ───── colonne: rinominata, nascosta, ordine ─────

    public function testUnaColonnaRinominataEdUnOrdineNuovoSiVedonoNellExport(): void
    {
        $this->rigaNelFile(['titolo' => 'Prima', 'd_url_pubblicazione' => 'https://x.it/1']);
        $this->cfg()->aggiornaColonne($this->postColonne(['d_url_pubblicazione' => ['intestazione' => 'Link finale'], 'f_pubblicazione_social' => ['visibile' => false]]));
        $tab = EsportazioneMonitoraggio::tabella($this->repo()->elenco(), false, $this->cfg());
        $this->assertContains('Link finale', $tab['intestazioni']);
        $this->assertNotContains('URL effettiva pubblicazione sito (a cura di Marco Bocci o Emanuele Dodaro)', $tab['intestazioni']);
        $this->assertNotContains(ColonneMonitoraggio::perChiave('f_pubblicazione_social')['intestazione'], $tab['intestazioni'], 'la colonna nascosta esce dal file');
        $this->assertCount(count(ColonneMonitoraggio::chiavi()) - 1, $tab['intestazioni']);
        $this->assertSame('https://x.it/1', $tab['righe'][0][array_search('Link finale', $tab['intestazioni'], true)], 'sotto la nuova intestazione ci sono i valori giusti');

        // sposta «Stato» in testa
        $post = $this->postColonne();
        $chiavi = array_keys($post['colonna']);
        $ordine = array_merge(['stato'], array_diff($chiavi, ['stato']));
        foreach ($ordine as $i => $k) {
            $post['colonna'][$k]['ordine'] = $i + 1;
        }
        $esito = $this->cfg()->aggiornaColonne($post);
        $this->assertSame([], $esito['errori']);
        $this->assertSame('stato', $this->cfg()->chiaviExcel()[0]);
        $tab = EsportazioneMonitoraggio::tabella($this->repo()->elenco(), false, $this->cfg());
        $this->assertSame(['Stato', 'Pubblicata'], [$tab['intestazioni'][0], $tab['righe'][0][0]]);
        $this->assertCount(count(ColonneMonitoraggio::chiavi()) - 1, $tab['righe'][0]);
        // rimesso in ordine: nessuna riga salvata per l'ordine
        $post = $this->postColonne();
        foreach (array_keys($post['colonna']) as $i => $k) {
            $post['colonna'][$k]['ordine'] = array_search($k, ColonneMonitoraggio::chiavi(), true) + 1;
        }
        $this->cfg()->aggiornaColonne($post);
        $this->assertSame(ColonneMonitoraggio::chiavi()[0], $this->cfg()->chiaviExcel()[0]);
        $this->assertSame(['d_url_pubblicazione', 'f_pubblicazione_social'], array_column($this->righeColonne(), 'chiave'), 'restano solo la colonna rinominata e quella nascosta');
        $this->assertSame([null, null], array_column($this->righeColonne(), 'ordine'), 'l\'ordine di partenza non si salva');
    }

    // ───── Torna all'originale ─────

    public function testTornaAllOriginale(): void
    {
        $this->cfg()->aggiornaCampi($this->postCampi(['sottotitolo' => ['etichetta' => 'Frase', 'visibile' => false], 'tag' => ['etichetta' => 'Parole chiave']]));
        $this->cfg()->aggiornaColonne($this->postColonne(['d_url_pubblicazione' => ['intestazione' => 'Link'], 'c_altri_referenti' => ['intestazione' => 'Referenti', 'colore' => '#ABCDEF'], 'f_pubblicazione_social' => ['visibile' => false]]));
        $this->assertCount(2, $this->righeCampi());
        $this->assertCount(3, $this->righeColonne());

        // un campo
        $fatto = $this->cfg()->ripristinaCampo('sottotitolo');
        $this->assertSame(['Campo «Sottotitolo»: tornato all\'originale'], $fatto);
        $this->assertSame(['Sottotitolo', true], [$this->cfg()->etichetta('sottotitolo'), $this->cfg()->visibile('sottotitolo')]);
        $this->assertSame('Parole chiave', $this->cfg()->etichetta('tag'), 'gli altri campi restano come sono');
        $this->assertSame([], $this->cfg()->ripristinaCampo('sottotitolo'), 'già come all\'origine: niente da fare');
        $this->assertSame([], $this->cfg()->ripristinaCampo('chiave_che_non_c_e'));

        // una colonna
        $this->assertCount(1, $this->cfg()->ripristinaColonna('f_pubblicazione_social'));
        $this->assertContains('f_pubblicazione_social', $this->cfg()->chiaviExcel());
        $this->assertSame(['c_altri_referenti', 'd_url_pubblicazione'], array_column($this->righeColonne(), 'chiave'));

        // tutte le intestazioni: i colori e il resto restano
        $this->assertSame(2, $this->cfg()->ripristinaIntestazioni());
        $originali = ColonneMonitoraggio::intestazioni();
        $this->assertSame($originali, $this->cfg()->intestazioniExcel());
        $righe = $this->righeColonne();
        $this->assertSame(['c_altri_referenti'], array_column($righe, 'chiave'), 'la riga con la sola intestazione sparisce, quella col colore resta');
        $this->assertSame('#ABCDEF', $righe[0]['colore']);
        $this->assertNull($righe[0]['intestazione']);
        $this->assertSame(0, $this->cfg()->ripristinaIntestazioni(), 'la seconda volta non c\'è niente');
    }

    // ───── i due conteggi «da completare» ─────

    public function testIDueConteggiDaCompletareCoincidonoConUnaColonnaNascosta(): void
    {
        $nascosta = 'c_altri_referenti';
        $this->rigaNelFile(['titolo' => 'Completa']);
        $this->rigaNelFile([$nascosta => null, 'titolo' => 'Manca solo la colonna che nasconderemo']);
        $this->rigaNelFile(['titolo' => 'Manca un\'altra', 'd_url_pubblicazione' => " \t "]);
        $this->rigaNelFile(['titolo' => 'Fuori dal file', 'nel_monitoraggio' => 0, 'stato' => 'ricevuta'], false);

        $nelDb = fn (): int => $this->repo()->contaDaFare()['da_completare'];
        $leggendo = function (): int {
            $n = 0;
            foreach ($this->repo()->elenco() as $r) {
                if ((int) $r['nel_monitoraggio'] === 1 && Completamento::numeroMancanti($r, $this->cfg()) > 0) {
                    ++$n;
                }
            }

            return $n;
        };
        $this->assertSame([2, 2], [$nelDb(), $leggendo()], 'con tutte le colonne: due righe da completare');

        $this->cfg()->aggiornaColonne($this->postColonne([$nascosta => ['visibile' => false]]));
        $this->assertSame([1, 1], [$nelDb(), $leggendo()], 'la colonna nascosta non conta: la seconda riga è completa');

        $this->cfg()->aggiornaColonne($this->postColonne(['d_url_pubblicazione' => ['visibile' => false]]));
        $this->assertSame([0, 0], [$nelDb(), $leggendo()], 'nascoste tutte e due: nessuna riga da completare');

        $this->cfg()->ripristinaColonna($nascosta);
        $this->cfg()->ripristinaColonna('d_url_pubblicazione');
        $this->assertSame([2, 2], [$nelDb(), $leggendo()]);
    }

    public function testUnaColonnaNascostaNonSiControllaNellaSchedaDellaRichiesta(): void
    {
        $id = $this->rigaNelFile(['titolo' => 'Da aggiornare']);
        $operatore = ['id' => 4, 'nome' => 'Floriana', 'cognome' => 'Operatrice', 'ruolo_id' => 4, 'ruoli_secondari' => ''];
        // «Pubblicazione social» è SI/NO: «forse» è sbagliato, ma se la colonna è nascosta non si controlla e non si tocca
        $this->assertFalse($this->servizio()->aggiornaOperatori($id, ['f_pubblicazione_social' => 'forse'], $operatore)->riuscito);
        $this->cfg()->aggiornaColonne($this->postColonne(['f_pubblicazione_social' => ['visibile' => false]]));
        $esito = $this->servizio()->aggiornaOperatori($id, ['f_pubblicazione_social' => 'forse', 'j_tipo_iniziativa' => 'Convegno'], $operatore);
        $this->assertTrue($esito->riuscito, 'la colonna nascosta non si controlla');
        $p = $this->repo()->perId($id);
        $this->assertSame('x', $p['f_pubblicazione_social'], 'e il suo valore non si perde');
        $this->assertSame('Convegno', $p['j_tipo_iniziativa']);
    }
}
