<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Http\Richiesta;
use App\Http\Risposta;
use App\Portale\Pagine\PaginaNumeriFsl;

/**
 * Passo 9: admin/fsl.php non è più il pannello delle sei schermate ma «Numeri e report». Provata senza browser: la tabella «indirizzo vecchio → arrivo»
 * (riga per riga, con tutta la FSL e con le sole convenzioni, con parametri strani), nessun giro di rimandi, i moduli vecchi che non fanno più niente, il
 * rimando `?cv=` e le tre sezioni della pagina nuova con i loro scaricamenti. La base (setUp, modelli, utenti) è PaginaScuoleFslBase: 1 = amministratore, 2 = ha
 * SOLO le convenzioni.
 */
final class PaginaNumeriFslTest extends PaginaScuoleFslBase
{
    private function numeri(): PaginaNumeriFsl
    {
        return $this->servizio(PaginaNumeriFsl::class);
    }

    /** @param array<string, string> $query @param array<string, string> $post */
    private function vecchia(array $query = [], string $metodo = 'GET', array $post = []): Risposta
    {
        return ($this->numeri())(Richiesta::finta($metodo, '/admin/fsl.php', $query, $post));
    }

    /** Dove porta un rimando, come testo: percorso e query dell'indirizzo di arrivo (con l'ancora, se c'è). */
    private static function arrivo(Risposta $r): string
    {
        self::assertSame(303, $r->codice(), 'è un rimando');

        return (string) $r->intestazione('Location');
    }

    // ───────────────────────────── La tabella: indirizzo vecchio → arrivo ─────────────────────────────

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function tabellaPerChiGestisceTuttaLaFsl(): array
    {
        return [
            'tab=home' => [['tab' => 'home'], 'fsl_scuole.php'],
            'tab=iter' => [['tab' => 'iter'], 'fsl_scuole.php'],
            'tab=stipulare' => [['tab' => 'stipulare'], 'fsl_scuole.php?cosa=senza_convenzione'],
            'tab=stipulare&mostra=tutte' => [['tab' => 'stipulare', 'mostra' => 'tutte'], 'fsl_scuole.php'],
            'tab=verifica' => [['tab' => 'verifica'], 'fsl_scuole.php?cosa=senza_convenzione'],
            'tab=convenzioni' => [['tab' => 'convenzioni'], 'fsl_scuole.php'],
            'tab=convenzioni&vista=archivio' => [['tab' => 'convenzioni', 'vista' => 'archivio'], 'fsl_scuole.php?stato=scaduta'],
            'tab=convenzioni&vista=archivio&scarica=csv' => [['tab' => 'convenzioni', 'vista' => 'archivio', 'scarica' => 'csv'], 'fsl_scuole.php?stato=scaduta&scarica=csv'],
            'tab=convenzioni&scarica=exe (formato che non esiste)' => [['tab' => 'convenzioni', 'scarica' => 'exe'], 'fsl_scuole.php'],
            'tab=convenzioni&nuova=1' => [['tab' => 'convenzioni', 'nuova' => '1'], 'fsl_scuole.php?nuova=1'],
            'tab=scuola&k=minuscolo' => [['tab' => 'scuola', 'k' => 'csis00001a'], 'fsl_scuole.php?k=CSIS00001A'],
            'tab=scuola&k=scritta a mano' => [['tab' => 'scuola', 'k' => 'x0123456789ab'], 'fsl_scuole.php?k=x0123456789ab'],
            'tab=scuola&k=cattivo' => [['tab' => 'scuola', 'k' => '"><script>'], 'fsl_scuole.php'],
            'tab=scuola senza k' => [['tab' => 'scuola'], 'fsl_scuole.php'],
            'conv_nuova con le date' => [['tab' => 'convenzioni', 'conv_nuova' => 'CSIS00001A', 'dal' => '2026-10-13', 'al' => '2026-12-02'], 'fsl_scuole.php?k=CSIS00001A&nuova=1&dal=2026-10-13&al=2026-12-02'],
            'conv_nuova con date false' => [['tab' => 'convenzioni', 'conv_nuova' => 'CSIS00001A', 'dal' => '2026-13-45', 'al' => 'domani'], 'fsl_scuole.php?k=CSIS00001A&nuova=1'],
            'conv_nuova con un codice che non esiste' => [['tab' => 'convenzioni', 'conv_nuova' => 'ZZZZ00000Z'], 'fsl_scuole.php?nuova=1'],
            'conv_mod che non esiste' => [['tab' => 'convenzioni', 'conv_mod' => '99999'], 'fsl_scuole.php'],
            'conv_mod non numerico' => [['tab' => 'convenzioni', 'conv_mod' => '1; DROP TABLE'], 'fsl_scuole.php'],
            'con un anno' => [['tab' => 'iter', 'anno' => '2025'], 'fsl_scuole.php?anno=2025'],
            'con «tutti» gli anni' => [['tab' => 'iter', 'anno' => 'tutti'], 'fsl_scuole.php?anno=tutti'],
            'con un anno strano' => [['tab' => 'iter', 'anno' => '20x5'], 'fsl_scuole.php'],
            'parametri sconosciuti non passano' => [['tab' => 'iter', 'cosa' => 'x', 'stato' => 'y', 'p_id' => '7', 'r' => '123'], 'fsl_scuole.php'],
            'tab=riepilogo' => [['tab' => 'riepilogo'], 'fsl.php#riepilogo'],
            'tab=riepilogo con l\'anno' => [['tab' => 'riepilogo', 'anno' => '2025'], 'fsl.php?anno=2025#riepilogo'],
            'tab=valutazioni' => [['tab' => 'valutazioni'], 'fsl.php#valutazioni'],
            'tab=report' => [['tab' => 'report'], 'fsl.php#report'],
            'tab=report&scarica=xlsx' => [['tab' => 'report', 'scarica' => 'xlsx'], 'fsl.php?scarica=xlsx&tabella=report'],
            'tab=report&xlsx=1 (il vecchio modo)' => [['tab' => 'report', 'xlsx' => '1', 'anno' => '2025'], 'fsl.php?anno=2025&scarica=xlsx&tabella=report'],
            'tab=riepilogo&scarica=pdf&tabella=scuole' => [['tab' => 'riepilogo', 'scarica' => 'pdf', 'tabella' => 'scuole'], 'fsl.php?scarica=pdf&tabella=scuole'],
            'tab=valutazioni&scarica=csv' => [['tab' => 'valutazioni', 'scarica' => 'csv'], 'fsl.php?scarica=csv&tabella=valutazioni'],
            'tab=report&scarica=exe' => [['tab' => 'report', 'scarica' => 'exe'], 'fsl.php#report'],
            'tab=riepilogo&scarica=csv&tabella=../../etc' => [['tab' => 'riepilogo', 'scarica' => 'csv', 'tabella' => '../../etc'], 'fsl.php?scarica=csv&tabella=attivita'],
        ];
    }

    /**
     * @dataProvider tabellaPerChiGestisceTuttaLaFsl
     * @param array<string, string> $query
     */
    public function testOgniIndirizzoVecchioPortaDoveDeve(array $query, string $atteso): void
    {
        self::assertSame($atteso, self::arrivo($this->vecchia($query)));
    }

    /**
     * @dataProvider tabellaPerChiGestisceTuttaLaFsl
     * @param array<string, string> $query
     */
    public function testNessunRimandoGiraInCerchio(array $query): void
    {
        $arrivo = self::arrivo($this->vecchia($query));
        $parti = parse_url($arrivo);
        parse_str((string) ($parti['query'] ?? ''), $q);

        self::assertArrayNotHasKey('tab', $q, "l'indirizzo di arrivo non ha più «tab»: non c'è niente da tradurre due volte");
        $secondo = ($parti['path'] ?? '') === 'fsl.php' ? $this->vecchia($q) : $this->apri($q);
        self::assertContains($secondo->codice(), [200, 303, 404], 'la pagina di arrivo risponde');
        if ($secondo->codice() === 303) {
            self::assertStringNotContainsString('tab=', (string) $secondo->intestazione('Location'), 'e non rimanda di nuovo a una schermata vecchia');
        }
    }

    public function testLeConvenzioniDelRegistroPortanoAllaSchedaDellaSuaScuola(): void
    {
        $id = $this->convenzione('CSIS00001A', '2026-09-01', '2027-08-31');

        self::assertSame("fsl_scuole.php?k=CSIS00001A&modifica=$id", self::arrivo($this->vecchia(['tab' => 'convenzioni', 'conv_mod' => (string) $id])));
        self::assertSame("fsl_scuole.php?k=CSIS00001A&rinnova=$id&dal=2027-09-01&al=2028-08-31", self::arrivo($this->vecchia(['tab' => 'convenzioni', 'conv_rinnova' => (string) $id, 'dal' => '2027-09-01', 'al' => '2028-08-31'])));
        self::assertSame("fsl_scuole.php?k=CSIS00001A&modifica=$id", self::arrivo($this->vecchia(['tab' => 'convenzioni', 'conv_mod' => (string) $id, 'conv_rinnova' => (string) $id])), 'la modifica ha la precedenza, come prima');
    }

    public function testChiHaSoloLeConvenzioniFinisceSempreSuScuole(): void
    {
        $this->entra(2);

        foreach (['' => [], 'riepilogo' => ['tab' => 'riepilogo'], 'valutazioni' => ['tab' => 'valutazioni', 'anno' => '2025'], 'report' => ['tab' => 'report', 'scarica' => 'xlsx'],
            'sconosciuta' => ['tab' => 'zzz'], 'iter' => ['tab' => 'iter'], 'stipulare' => ['tab' => 'stipulare']] as $nome => $query) {
            $arrivo = self::arrivo($this->vecchia($query));
            self::assertStringStartsWith('fsl_scuole.php', $arrivo, "«{$nome}»: chi ha solo le convenzioni non vede i numeri degli studenti");
            self::assertStringNotContainsString('scarica=xlsx', $arrivo, 'e non scarica i report');
        }
        self::assertSame('fsl_scuole.php?cosa=senza_convenzione', self::arrivo($this->vecchia(['tab' => 'stipulare'])), 'il filtro vale anche per lui');
    }

    // ───────────────────────────── Moduli vecchi: nessuna azione ─────────────────────────────

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function moduliVecchi(): array
    {
        return [
            'presa in carico' => [['cv_prendi' => '7', 'prot_ingresso' => '1234'], 'fsl_scuole.php?cv=7'],
            'rifiuto' => [['cv_rifiuta' => '8', 'motivo' => 'no'], 'fsl_scuole.php?cv=8'],
            'incerto chiuso' => [['cv_incerto_chiudi' => '9:ingresso'], 'fsl_scuole.php?cv=9'],
            'attività confermata' => [['att_conferma' => '4:12'], 'fsl_scuole.php?cv=4'],
            'messaggio' => [['cv_messaggio' => '5', 'testo' => 'ciao'], 'fsl_scuole.php?cv=5'],
            'unione' => [['cv_unisci' => '1', 'unisci_resta' => '3', 'unisci_assorbita' => '4', 'torna' => 'CSIS00001A'], 'fsl_scuole.php?cv=3'],
            'con la scuola' => [['conv_salva' => '1', 'torna' => 'CSIS00001A'], 'fsl_scuole.php?k=CSIS00001A'],
            'richiesta da stipulare' => [['st_chiedi' => 'CSIS00002B'], 'fsl_scuole.php?k=CSIS00002B'],
            'senza niente da cui capire' => [['conv_elimina' => '1'], 'fsl_scuole.php'],
            'azione che non c\'è più' => [['conv_online_prot' => '1', 'protocollo' => '123'], 'fsl_scuole.php'],
        ];
    }

    /**
     * @dataProvider moduliVecchi
     * @param array<string, string> $post
     */
    public function testUnModuloVecchioNonFaNienteEPortaAllaSchedaGiusta(array $post, string $atteso): void
    {
        $prima = [(int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_scuole'), (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_compilate'),
            (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_messaggi'), (int) $this->db->valore('SELECT COUNT(*) FROM log_email')];

        $r = $this->vecchia(['tab' => 'iter'], 'POST', $post);

        self::assertSame($atteso, self::arrivo($r));
        self::assertSame($prima, [(int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_scuole'), (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_compilate'),
            (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_messaggi'), (int) $this->db->valore('SELECT COUNT(*) FROM log_email')], 'non scrive niente e non manda email');
        $avviso = $this->servizio(\App\Auth\Flash::class)->prendi();
        self::assertIsArray($avviso);
        self::assertStringContainsString('La pagina è cambiata', (string) json_encode($avviso, JSON_UNESCAPED_UNICODE));
    }

    public function testUnModuloVecchioNonCancellaUnaConvenzione(): void
    {
        $id = $this->convenzione('CSIS00001A', '2026-09-01', '2027-08-31');

        $this->vecchia(['tab' => 'convenzioni'], 'POST', ['conv_elimina' => (string) $id]);

        self::assertNotNull($this->riga('convenzioni_scuole', $id), 'la convenzione c\'è ancora');
    }

    // ───────────────────────────── ?cv= e ancore ─────────────────────────────

    public function testCvPortaAllaSchedaDellaScuolaDiQuellaCompilazione(): void
    {
        $id = $this->db->inserisci('INSERT INTO convenzioni_compilate (token, scuola_codice, email, stato_iter, dati_json, aggiornata_il) VALUES (?, ?, ?, ?, ?, ?)',
            [bin2hex(random_bytes(10)), 'CSIS00001A', 'r@g.it', 'caricata_scuola', '{"scuola":{},"attivita":[]}', '2026-10-01 10:00:00']);

        $r = $this->apri(['cv' => (string) $id]);

        self::assertSame('fsl_scuole.php?k=CSIS00001A#messaggi', self::arrivo($r));
        $this->entra(2);
        self::assertSame('fsl_scuole.php?k=CSIS00001A#messaggi', self::arrivo($this->apri(['cv' => (string) $id])), 'con i permessi di sempre: chi ha le convenzioni la vede');
    }

    public function testCvChePuntaAUnaCompilazioneChePoNonCeEUn404(): void
    {
        foreach (['999999', '0', 'abc', '-3'] as $cv) {
            $r = $this->apri(['cv' => $cv]);
            self::assertSame(404, $r->codice(), "cv={$cv}");
            self::assertStringContainsString('Questa convenzione non c&#039;è', $r->corpo());
        }
    }

    public function testLeVisteDiArrivoHannoLoScriptDelleAncoreConIlNonce(): void
    {
        $elenco = $this->apri()->corpo();
        $scheda = $this->apri(['k' => 'CSIS00001A', 'anno' => 'tutti'])->corpo();

        foreach ([$elenco, $scheda] as $html) {
            self::assertMatchesRegularExpression('/<script nonce="[^"]+" data-pagina="(elenco|scheda)">/', $html);
            self::assertStringContainsString('fase-caricata', $html);
            self::assertStringNotContainsString('onclick=', $html);
        }
    }

    public function testConNuovaLElencoApreLaScuolaNonInElenco(): void
    {
        self::assertStringNotContainsString('id="apriScuola" open', $this->apri()->corpo());
        self::assertStringContainsString('id="apriScuola" open', $this->apri(['nuova' => '1'])->corpo());
    }

    public function testLaSchedaApreGiaIlModuloDelVecchioIndirizzo(): void
    {
        $id = $this->convenzione('CSIS00001A', '2026-09-01', '2027-08-31');

        $modifica = $this->apri(['k' => 'CSIS00001A', 'anno' => 'tutti', 'modifica' => (string) $id])->corpo();
        self::assertMatchesRegularExpression('/<details open id="modifica' . $id . '">/', $modifica);

        $nuova = $this->apri(['k' => 'CSIS00001A', 'anno' => 'tutti', 'nuova' => '1', 'dal' => '2027-10-13', 'al' => '2028-01-01'])->corpo();
        self::assertStringContainsString('id="registra" open', $nuova);
        self::assertStringContainsString('value="2027-10-13"', $nuova, 'con le date proposte');

        $altrui = $this->apri(['k' => 'CSIS00002B', 'anno' => 'tutti', 'modifica' => (string) $id]);
        self::assertStringNotContainsString('<details open id="modifica', $altrui->corpo(), 'una convenzione di un\'altra scuola non apre niente');
    }

    // ───────────────────────────── La pagina nuova: «Numeri e report» ─────────────────────────────

    public function testLaPaginaHaLeTreSezioniELAnnoComune(): void
    {
        $r = $this->vecchia();

        self::assertSame(200, $r->codice());
        $html = $r->corpo();
        self::assertStringContainsString('<title>Numeri e report</title>', $html);
        foreach (['id="riepilogo"', 'id="valutazioni"', 'id="report"', 'name="anno"'] as $c) {
            self::assertStringContainsString($c, $html);
        }
        self::assertSame(1, substr_count($html, 'name="anno"'), 'un solo filtro anno per tutte le sezioni');
        self::assertStringNotContainsString('onclick=', $html);
        self::assertDoesNotMatchRegularExpression('/href="[^"]*fsl\.php\?[^"]*tab=/', $html, 'nessun collegamento alle vecchie schermate');
    }

    public function testLaPaginaDiceIlNumeroDelleScuoleSenzaConvenzioneEPortaAlFiltro(): void
    {
        $this->pren(300, ['scuola_codice' => 'CSIS00001A', 'dati_custom_json' => '{"numero_partecipanti":"12"}']);

        $html = $this->vecchia(['anno' => '2026'])->corpo();

        self::assertStringContainsString('href="fsl_scuole.php?cosa=senza_convenzione"', $html);
    }

    public function testAnnoStranoRitornaAQuelloInCorso(): void
    {
        self::assertStringContainsString('<option value="2026" selected>', $this->vecchia(['anno' => '1999'])->corpo());
        self::assertStringContainsString('<option value="2026" selected>', $this->vecchia(['anno' => 'abc'])->corpo());
    }

    public function testITreScaricamentiInCsv(): void
    {
        foreach (['attivita' => 'Attività;Area;Dal;Al', 'scuole' => 'Scuola;Codice;Attività', 'valutazioni' => 'Data;Attività;Scuola;"Compilata da"'] as $tabella => $titolo) {
            $r = $this->vecchia(['scarica' => 'csv', 'tabella' => $tabella, 'anno' => '2026']);

            self::assertSame(200, $r->codice(), $tabella);
            self::assertStringContainsString($titolo, $r->corpo(), $tabella);
        }
    }

    public function testUnFormatoCheNonCeNonScarica(): void
    {
        $r = $this->vecchia(['scarica' => 'exe', 'tabella' => 'attivita']);

        self::assertSame(303, $r->codice());
        self::assertSame('fsl.php?anno=2026', self::arrivo($r));
    }
}
