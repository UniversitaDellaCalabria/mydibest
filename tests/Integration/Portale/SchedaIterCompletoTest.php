<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Core\Database;
use App\Fsl\ElencoScuoleFsl;
use App\Fsl\SchedaScuolaFsl;

/**
 * Correzioni W1 e W6 del passo 8.
 *  - W1: TUTTO l'iter di una convenzione si fa dalla scheda della scuola, senza un solo collegamento a fsl.php (le azioni sono quelle di AzioniScuolaFsl);
 *    ogni compilazione in corso ha il suo iter; «Verifica ora» sta nel menu «Altro» dell'elenco, solo per chi gestisce tutta la FSL.
 *  - W6: elenco e scheda dicono le STESSE cose della stessa scuola (un solo criterio: StatoConvenzioneScuola::cose, su TUTTE le compilazioni in corso),
 *    e l'elenco legge con un numero di query che non cresce con le scuole.
 *
 * Istituti: CSIS00001A Galilei, CSIS00002B Fermi. Data di prova: 5 ottobre 2026.
 */
final class SchedaIterCompletoTest extends PaginaScuoleFslBase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['creata_il' => 'DATETIME NULL', 'rivendicata_il' => 'DATETIME NULL'] as $colonna => $definizione) {
            if ($this->db->righe("SHOW COLUMNS FROM convenzioni_compilate LIKE '$colonna'") === []) {
                $this->db->comando("ALTER TABLE convenzioni_compilate ADD COLUMN `$colonna` $definizione");
            }
        }
    }

    /** @param array<string, mixed> $campi */
    private function compilazione(string $istituto, string $stato, array $campi = []): int
    {
        $campi += ['token' => bin2hex(random_bytes(10)), 'scuola_codice' => $istituto, 'email' => 'ref@' . strtolower($istituto) . '.it', 'stato_iter' => $stato,
            'dati_json' => '{"scuola":{"denominazione":"Scuola di prova","dirigente":"Maria Dirigente"},"attivita":[]}', 'aggiornata_il' => '2026-10-01 10:00:00'];

        return $this->db->inserisci('INSERT INTO convenzioni_compilate (' . implode(',', array_keys($campi)) . ') VALUES (' . implode(',', array_fill(0, count($campi), '?')) . ')', array_values($campi));
    }

    private function scheda(string $chiave = 'CSIS00001A'): string
    {
        $r = $this->apri(['k' => $chiave, 'anno' => 'tutti']);
        self::assertSame(200, $r->codice());

        return $r->corpo();
    }

    // ───────────────────────────── W1: l'iter si fa dalla scheda ─────────────────────────────

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: list<string>, 3: list<string>}> */
    public static function passiDellIter(): array
    {
        return [
            'compilata, manca il PDF' => ['', [], ['name="cv_ricorda"', 'name="cv_cambia_referente"'], ['name="cv_prendi"', 'name="cv_completa"']],
            'caricata dalla scuola' => ['caricata_scuola', ['file_firmato' => 'x.pdf', 'firmata_il' => '2026-10-02 09:00:00'],
                ['name="cv_controllo"', 'name="cv_prendi"', 'name="cv_rifiuta"', 'name="allegato_a"', 'name="doc_nome[]"', 'name="data_stipula"', 'name="prot_ingresso"', 'name="cv_cambia_referente"'], ['name="cv_completa"']],
            'in carico, dal Direttore' => ['in_carico', ['file_firmato' => 'x.pdf', 'protocollo_ingresso' => '1234'], ['name="cv_invia_direttore"', 'name="file_definitivo"', 'name="cv_completa"', 'name="cv_cambia_referente"'], ['name="cv_prendi"']],
            'mandata al Direttore' => ['da_firmare', ['file_firmato' => 'x.pdf', 'protocollo_ingresso' => '1234', 'da_firmare_il' => '2026-10-03 10:00:00'], ['name="cv_invia_direttore"', 'name="cv_completa"'], ['name="cv_prendi"']],
            'firmata dal Direttore' => ['firmata_direttore', ['file_firmato' => 'x.pdf', 'file_definitivo' => 'y.pdf', 'firmata_direttore_il' => '2026-10-04 10:00:00'],
                ['name="cv_completa"', 'name="protocollo"', 'name="protocollo_data"', 'name="scadenza"', 'name="file_definitivo"', 'name="doc_email[]"'], ['name="cv_prendi"', 'name="cv_invia_direttore"']],
        ];
    }

    /**
     * @dataProvider passiDellIter
     * @param array<string, mixed> $campi
     * @param list<string> $ci
     * @param list<string> $nonCi
     */
    public function testOgniPassoHaIlSuoModuloSenzaUscireDallaScheda(string $stato, array $campi, array $ci, array $nonCi): void
    {
        $id = $this->compilazione('CSIS00001A', $stato, $campi);

        $html = $this->scheda();

        self::assertStringContainsString('id="cv' . $id . '"', $html, 'ha il suo riquadro, con l\'ancora che usa il pulsante in testata');
        foreach ($ci as $c) {
            self::assertStringContainsString($c, $html, "nel passo «{$stato}» c'è {$c}");
        }
        foreach ($nonCi as $c) {
            self::assertStringNotContainsString($c, $html, "nel passo «{$stato}» non c'è {$c}");
        }
        self::assertDoesNotMatchRegularExpression('/href="[^"]*fsl\.php/', $html, 'nessun collegamento alla vecchia pagina');
        self::assertStringNotContainsString('onclick=', $html);
    }

    public function testIlPulsanteInTestataPortaAlRiquadroDellIter(): void
    {
        $id = $this->compilazione('CSIS00001A', 'caricata_scuola', ['file_firmato' => 'x.pdf']);

        $html = $this->scheda();

        self::assertStringContainsString('href="#cv' . $id . '"', $html);
        self::assertStringContainsString('Controlla la convenzione', $html, "l'azione principale è un verbo");
        self::assertSame(1, substr_count($html, 'href="#cv' . $id . '"'), 'la stessa azione non si ripete sotto, come riga a parte');
    }

    public function testConPiuCompilazioniInCorsoOgnunaHaIlSuoIterEUnisciSiOffre(): void
    {
        $a = $this->compilazione('CSIS00001A', 'caricata_scuola', ['file_firmato' => 'x.pdf', 'email' => 'uno@galilei.it']);
        $b = $this->compilazione('CSIS00001A', 'firmata_direttore', ['file_firmato' => 'x.pdf', 'file_definitivo' => 'y.pdf', 'email' => 'due@galilei.it']);

        $html = $this->scheda();

        self::assertStringContainsString('id="cv' . $a . '"', $html);
        self::assertStringContainsString('id="cv' . $b . '"', $html);
        self::assertStringContainsString('name="cv_prendi"', $html, 'quella caricata si prende in carico');
        self::assertStringContainsString('name="cv_completa"', $html, 'quella firmata si registra');
        self::assertStringContainsString('name="cv_unisci"', $html);
        self::assertLessThan(strpos($html, 'id="cv' . $a . '"'), strpos($html, 'id="cv' . $b . '"'), 'la più avanti per prima');
    }

    public function testLeCompilazioniRegistrateHannoResocontoEFileDiTitulus(): void
    {
        $id = $this->compilazione('CSIS00001A', 'protocollata', ['protocollo' => '9876', 'file_firmato' => 'x.pdf', 'file_definitivo' => 'y.pdf', 'convenzione_id' => 7,
            'esito_registrazione' => '{"coperte":3,"approvate":2,"restano":[{"id":5,"codice":"FS-9","motivo":"scuola scritta a mano"}]}', 'originale_stato' => 'mancante', 'originale_avviso' => 'Titulus non ha ancora il file.']);

        $html = $this->scheda();

        self::assertStringContainsString('id="cvreg' . $id . '"', $html);
        self::assertStringContainsString('3 coperte dalla convenzione, 2 approvate, 1 restano come sono', $html);
        self::assertStringContainsString('FS-9: scuola scritta a mano', $html);
        self::assertStringContainsString('non arrivato', $html);
        self::assertStringContainsString('name="cv_riprova_originale"', $html);
        self::assertStringContainsString('name="cv_carica_protocollato"', $html);
        self::assertStringContainsString('name="cv_ripeti_applicazione"', $html);
        self::assertDoesNotMatchRegularExpression('/href="[^"]*fsl\.php/', $html);
    }

    public function testLaConvenzioneSiRegistraSiModificaESiRinnovaDallaScheda(): void
    {
        $cv = $this->convenzione('CSIS00001A', '2023-10-01', '2026-09-30');   // scaduta ieri: si può rinnovare

        $html = $this->scheda();

        self::assertStringContainsString('id="registra"', $html, 'registrane una già firmata');
        self::assertStringContainsString('name="conv_salva"', $html);
        self::assertStringContainsString('name="conv_id" value="' . $cv . '"', $html, 'modifica');
        self::assertStringContainsString('name="conv_id" value="0"', $html, 'registra o rinnova una nuova');
        self::assertStringContainsString('name="scuola_codice[conv]" value="CSIS00001A"', $html);
        self::assertStringContainsString('Registra il rinnovo', $html);
        self::assertStringContainsString('name="conv_elimina"', $html);
        self::assertDoesNotMatchRegularExpression('/href="[^"]*fsl\.php/', $html);
    }

    public function testRegistrareUnaConvenzioneDallaSchedaLaMetteNelRegistro(): void
    {
        $r = $this->invia(['conv_salva' => '1', 'conv_id' => '0', 'scuola_codice' => ['conv' => 'CSIS00001A'], 'data_stipula' => '2026-10-01', 'scadenza' => '2029-09-30', 'protocollo' => 'n. 55', 'note' => 'a mano'], ['k' => 'CSIS00001A']);

        self::assertSame(303, $r->codice());
        self::assertSame(1, (int) $this->db->valore("SELECT COUNT(*) FROM convenzioni_scuole WHERE scuola_codice = 'CSIS00001A' AND protocollo = 'n. 55'"));
    }

    public function testNelleSchedeSenzaUnaCompilazioneNonCEUnIterVuoto(): void
    {
        $html = $this->scheda();

        self::assertStringNotContainsString('class="sc-iter"', $html);
        self::assertStringNotContainsString('name="cv_cambia_referente"', $html);
    }

    public function testLElencoNonHaLinkAFslEVerificaStaInAltroSoloPerChiGestisceLaFsl(): void
    {
        $this->compilazione('CSIS00001A', 'caricata_scuola', ['file_firmato' => 'x.pdf']);

        $admin = $this->apri(['anno' => 'tutti'])->corpo();
        self::assertDoesNotMatchRegularExpression('/href="[^"]*fsl\.php/', $admin);
        self::assertStringContainsString('name="conv_verifica"', $admin, 'chi gestisce tutta la FSL ha «Verifica ora» in Altro');

        $this->entra(2);   // ha solo le convenzioni
        $solo = $this->apri(['anno' => 'tutti'])->corpo();
        self::assertStringNotContainsString('name="conv_verifica"', $solo);
        self::assertDoesNotMatchRegularExpression('/href="[^"]*fsl\.php/', $solo);
    }

    public function testChiHaSoloLeConvenzioniFaTuttoLIterMaNonVedeNumeriEValutazioni(): void
    {
        $this->compilazione('CSIS00001A', 'caricata_scuola', ['file_firmato' => 'x.pdf']);
        $this->entra(2);

        $html = $this->scheda();

        self::assertStringContainsString('name="cv_prendi"', $html);
        self::assertStringNotContainsString('id="valutazioni"', $html);
    }

    // ───────────────────────────── W6: un solo criterio, per l'elenco e per la scheda ─────────────────────────────

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} la riga dell'elenco e la scheda della stessa scuola */
    private function dueModi(string $istituto): array
    {
        $righe = array_column($this->servizio(ElencoScuoleFsl::class)->righe(0), null, 'chiave');

        return [$righe[$istituto], $this->servizio(SchedaScuolaFsl::class)->scheda($istituto, 0, true)];
    }

    public function testElencoESchedaDiconoLeStesseCose(): void
    {
        // Galilei: due compilazioni (una da prendere in carico, una firmata), un messaggio da leggere e un Allegato A da esaminare
        $this->compilazione('CSIS00001A', 'caricata_scuola', ['file_firmato' => 'x.pdf', 'email' => 'uno@galilei.it']);
        $cc = $this->compilazione('CSIS00001A', 'firmata_direttore', ['file_firmato' => 'x.pdf', 'file_definitivo' => 'y.pdf', 'email' => 'due@galilei.it']);
        $this->db->esegui("INSERT INTO convenzioni_messaggi (compilazione_id, da, tipo, testo) VALUES (?, 'scuola', 'messaggio', 'Buongiorno')", [$cc]);
        $cv = $this->convenzione('CSIS00001A', '2026-01-01', '2027-12-31');
        $this->db->esegui("INSERT INTO convenzioni_allegati (convenzione_id, file, stato) VALUES (?, 'uploads/convenzioni/x.pdf', 'da_vedere')", [$cv]);
        // Fermi: attività FSL senza convenzione
        $this->pren(200, ['scuola_codice' => 'CSPS00002B']);

        foreach (['CSIS00001A', 'CSIS00002B'] as $istituto) {
            [$riga, $scheda] = $this->dueModi($istituto);
            self::assertEqualsCanonicalizing($riga['tags'], $scheda['tags'], "stessi tag per {$istituto}");
            self::assertSame($riga['da_fare'] ? $riga['cosa'] : '', $riga['da_fare'] ? implode(' · ', $scheda['cose']) : implode(' · ', $scheda['cose']), "stesse cose per {$istituto}");
            self::assertSame($riga['stato'], $scheda['stato']);
        }
        [$riga, $scheda] = $this->dueModi('CSIS00001A');
        foreach (['da_controllare', 'da_protocollare', 'doppie', 'messaggi', 'integrazioni'] as $tag) {
            self::assertContains($tag, $riga['tags'], "l'elenco conta «{$tag}»");
            self::assertContains($tag, $scheda['tags'], "la scheda conta «{$tag}»");
        }
        self::assertSame(1, count(array_keys($scheda['tags'], 'da_controllare', true)), 'ogni tag una volta sola');
        self::assertSame(implode(' · ', $scheda['cose']), $riga['cosa']);
    }

    public function testOgniCompilazioneContaNonSoloLaPiuAvanti(): void
    {
        $this->compilazione('CSIS00001A', 'caricata_scuola', ['file_firmato' => 'x.pdf']);
        $this->compilazione('CSIS00001A', 'in_carico', ['file_firmato' => 'x.pdf', 'protocollo_ingresso' => '1']);

        [$riga] = $this->dueModi('CSIS00001A');

        self::assertContains('da_controllare', $riga['tags'], 'quella caricata non sparisce perché un\'altra è più avanti');
        self::assertContains('dal_direttore', $riga['tags']);
        self::assertContains('doppie', $riga['tags']);
    }

    public function testLeNuoveCoseSonoFiltriETessere(): void
    {
        $this->compilazione('CSIS00001A', 'caricata_scuola', ['file_firmato' => 'x.pdf']);
        $this->compilazione('CSIS00001A', 'in_carico', ['file_firmato' => 'x.pdf']);
        $righe = $this->servizio(ElencoScuoleFsl::class)->righe(0);

        $tessere = ElencoScuoleFsl::tessere($righe);

        self::assertSame(array_keys(\App\Fsl\StatoConvenzioneScuola::COSE), array_keys($tessere), 'una tessera per ogni cosa, una fonte sola');
        self::assertSame(1, $tessere['doppie']);
        self::assertSame(['CSIS00001A'], array_column(ElencoScuoleFsl::filtra($righe, '', 'doppie', ''), 'chiave'));
        self::assertSame([], ElencoScuoleFsl::filtra($righe, '', 'esito_incerto', ''));
    }

    public function testLaScadutaSenzaAttivitaNonDiceNienteDaFare(): void
    {
        $this->convenzione('CSIS00001A', '2020-01-01', '2021-01-01');

        [$riga, $scheda] = $this->dueModi('CSIS00001A');

        self::assertSame('Scaduta, nessuna attività', $riga['cosa']);
        self::assertContains('scaduta_nessuna_attivita', $scheda['tags']);
        self::assertSame('Scaduta, nessuna attività', $scheda['attesa']);
        self::assertStringContainsString('Scaduta, nessuna attività', $this->scheda());
    }

    public function testLElencoLeggeConLoStessoNumeroDiQueryConDieciOTrecentoIstituti(): void
    {
        $misura = function (): int {
            $a = Database::numeroQuery();
            $this->servizio(ElencoScuoleFsl::class)->righe(0);

            return Database::numeroQuery() - $a;
        };
        $aggiungi = function (int $da, int $a): void {
            for ($i = $da; $i < $a; $i++) {
                $cod = sprintf('CSXX%05dZ', $i);
                $this->db->esegui('INSERT INTO scuole (codice, denominazione, istituto_codice, istituto_denominazione, comune, provincia) VALUES (?, ?, ?, ?, ?, ?)', [$cod, "ISTITUTO $i", $cod, "ISTITUTO $i", 'COSENZA', 'CS']);
                $this->compilazione($cod, $i % 2 ? 'caricata_scuola' : 'firmata_direttore', ['file_firmato' => 'x.pdf']);
                $this->convenzione($cod, '2026-01-01', '2027-12-31');
            }
        };
        $aggiungi(0, 10);
        $dieci = $misura();
        $aggiungi(10, 300);
        $trecento = $misura();

        self::assertSame($dieci, $trecento, 'il numero di query non cresce con le scuole');
        self::assertLessThan(25, $trecento);
        self::assertGreaterThanOrEqual(300, count($this->servizio(ElencoScuoleFsl::class)->righe(0)));
    }

    // ───────────────────────────── W5: la copia dei dati è una casella ─────────────────────────────

    public function testLaCopiaDeiDatiEUnaCasellaSpentaSeIlReferenteNonEraProprietario(): void
    {
        $this->compilazione('CSIS00001A', 'protocollata', ['email' => 'vecchio@galilei.it', 'file_firmato' => 'x.pdf', 'protocollo' => '77']);

        $r = $this->invia(['avvia_anteprima' => '1', 'referente' => '__altra', 'altra_email' => 'nuovo@galilei.it'], ['k' => 'CSIS00001A']);

        self::assertSame(200, $r->codice());
        self::assertStringContainsString('name="copia_dati"', $r->corpo());
        self::assertStringContainsString('name="copia_dati" value="1" disabled', $r->corpo(), 'un indirizzo che non era tra i referenti verificati non eredita i dati');
        self::assertStringNotContainsString('name="copia_dati" value="1" checked', $r->corpo());
    }

    public function testSenzaUnaConvenzioneConclusaNonCEUnaCasellaDaSpuntare(): void
    {
        $r = $this->invia(['avvia_anteprima' => '1', 'referente' => '__altra', 'altra_email' => 'nuovo@galilei.it'], ['k' => 'CSIS00001A']);

        self::assertStringNotContainsString('name="copia_dati"', $r->corpo());
    }

    // ───────────────────────────── W7: contatti, docenti, parole, compilazioni senza prenotazione ─────────────────────────────

    public function testIRecapitiCheLaScuolaHaScrittoSiDannoSoloSeNellAnagrafeMancanoESiDiceChiLiHaIndicati(): void
    {
        $this->compilazione('CSIS00001A', 'caricata_scuola', ['file_firmato' => 'x.pdf', 'dati_json' => '{"scuola":{"denominazione":"Galilei","pec":"galilei@pec.it","email":"segreteria@galilei.it"},"attivita":[]}']);

        $html = $this->scheda();

        self::assertStringContainsString('galilei@pec.it', $html);
        self::assertStringContainsString('segreteria@galilei.it', $html);
        self::assertStringContainsString('indicata dalla scuola', $html);

        $this->db->esegui("UPDATE scuole SET pec = 'anagrafe@pec.it', email = 'anagrafe@galilei.it' WHERE codice = 'CSIS00001A'");
        $html = $this->scheda();
        self::assertStringContainsString('anagrafe@pec.it', $html);
        self::assertStringNotContainsString('galilei@pec.it', $html, 'l\'anagrafe vince: i recapiti della scuola si danno solo se mancano');
        self::assertStringNotContainsString('indicata dalla scuola', $html);
    }

    public function testNellElencoCompaionoITrePrimiDocentiELaRicercaTrovaDocentiEMail(): void
    {
        foreach ([['Anna', 'Verdi'], ['Bruno', 'Neri'], ['Carla', 'Gialli'], ['Dario', 'Rossi'], ['Elisa', 'Blu']] as [$n, $c]) {
            $this->pren(200, ['scuola_codice' => 'CSPS00001A', 'nome' => $n, 'cognome' => $c, 'email' => strtolower($n) . '@galilei.it']);
        }
        $this->pren(200, ['scuola_codice' => 'CSPS00002B', 'nome' => 'Zeno', 'cognome' => 'Altro', 'email' => 'zeno@fermi.it']);
        $this->compilazione('CSIS00002B', 'caricata_scuola', ['file_firmato' => 'x.pdf', 'docenti_json' => '[{"nome":"Ugo Referente","email":"ugo@fermi.it"}]']);

        $html = $this->apri(['anno' => 'tutti'])->corpo();

        self::assertStringContainsString('Anna Verdi', $html);
        self::assertStringContainsString('Carla Gialli', $html);
        self::assertStringNotContainsString('Dario Rossi', $html, 'solo i primi tre nomi');
        self::assertStringContainsString('e altri 2', $html);

        $righe = $this->servizio(ElencoScuoleFsl::class)->righe(0);
        self::assertSame(['CSIS00001A'], array_column(ElencoScuoleFsl::filtra($righe, '', '', 'dario rossi'), 'chiave'), 'si trova anche il quarto docente');
        self::assertSame(['CSIS00001A'], array_column(ElencoScuoleFsl::filtra($righe, '', '', 'elisa@galilei.it'), 'chiave'), 'e l\'email');
        self::assertSame(['CSIS00002B'], array_column(ElencoScuoleFsl::filtra($righe, '', '', 'ugo@fermi'), 'chiave'), 'anche chi è solo nell\'Allegato A di una compilazione');
    }

    public function testNelleSchedeNonCompaionoParoleTecniche(): void
    {
        $this->pren(200, ['scuola_codice' => 'CSPS00001A']);
        $this->compilazione('CSIS00001A', 'caricata_scuola', ['file_firmato' => 'x.pdf']);

        $html = $this->scheda();

        foreach (['da: prenotazioni', 'rubrica', 'json', 'proprietario', 'stato_iter'] as $parola) {
            self::assertStringNotContainsStringIgnoringCase($parola, $html, "«{$parola}» non è una parola per l'ufficio");
        }
    }

    public function testUnDocenteSenzaEmailSiVedeESiCompletaSenzaRaddoppiare(): void
    {
        $this->compilazione('CSIS00001A', 'caricata_scuola', ['file_firmato' => 'x.pdf', 'docenti_json' => '[{"nome":"Mario Bianchi","email":""}]']);

        $html = $this->scheda();
        self::assertStringContainsString('Mario Bianchi', $html);
        self::assertStringContainsString('manca l\'indirizzo email', $html);
        self::assertStringContainsString('Scrivi l\'email', $html);

        $this->invia(['docente_salva' => '1', 'nome' => 'Mario Bianchi', 'email' => 'mario.bianchi@galilei.it', 'telefono' => ''], ['k' => 'CSIS00001A']);

        $docenti = $this->servizio(SchedaScuolaFsl::class)->scheda('CSIS00001A', 0, true)['docenti'];
        $mario = array_values(array_filter($docenti, static fn (array $d): bool => str_contains($d['nome'], 'Bianchi')));
        self::assertCount(1, $mario, 'la stessa persona, non due');
        self::assertSame('mario.bianchi@galilei.it', $mario[0]['email']);
    }

    public function testUnaCompilazioneSenzaPrenotazioniSiVedeInDaFarePerLaPersona(): void
    {
        $this->compilazione('CSIS00001A', '', ['email' => 'ref@galilei.it', 'avviata_il' => '2026-10-01 10:00:00', 'creata_il' => '2026-10-01 10:00:00']);
        $da = $this->servizio(\App\Portale\DaFarePersona::class);
        $m = new \ReflectionMethod($da, 'convenzioniFsl');

        $g = $m->invoke($da, 'ref@galilei.it');

        self::assertSame(1, $g['n']);
        self::assertSame('ricevi_link_convenzione.php', $g['righe'][0]['link'], 'senza prenotazione né link rivendicato: ci si fa mandare il link per email');
    }

    public function testHoPersoIlLinkFunzionaAncheSenzaPrenotazione(): void
    {
        $this->compilazione('CSIS00001A', '', ['email' => 'ref@galilei.it', 'avviata_il' => '2026-10-01 10:00:00']);

        $n = $this->servizio(\App\Fsl\ServizioConvenzioneOnline::class)->rimandaLink('ref@galilei.it');

        self::assertSame(1, $n);
    }}
