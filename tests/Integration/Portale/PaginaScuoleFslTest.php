<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Auth\Sessione;
use App\Http\Richiesta;
use App\Http\Vista;
use App\Portale\Pagine\PaginaScuoleFsl;
use Tests\Doppi\AuthSessioneInMemoria;
use Tests\Integration\Fsl\FslBase;


/**
 * Passo 8: la pagina «Scuole e convenzioni» del pannello FSL provata senza browser: l'elenco e i suoi filtri, la scheda (anche di una scuola scritta
 * a mano e di una che non esiste), chi vede cosa (solo convenzioni contro tutta la FSL), l'avvio della stipula senza prenotazioni (anteprima che non
 * scrive, compilazione unica, due clic, email sbagliata), la rubrica dei docenti e il PDF. La base (setUp, modelli, utenti) è PaginaScuoleFslBase.
 */
final class PaginaScuoleFslTest extends PaginaScuoleFslBase
{
    // ───────────────────────────── Elenco ─────────────────────────────

    public function testElencoConPulsanteApriSchedaPerOgniScuola(): void
    {
        $this->pren(200, ['scuola_codice' => 'CSPS00001A']);

        $r = $this->apri();

        self::assertSame(200, $r->codice());
        self::assertStringContainsString('<title>Scuole</title>', $r->corpo());
        self::assertStringContainsString('Apri scheda', $r->corpo());
        self::assertStringContainsString('href="fsl_scuole.php?k=CSIS00001A', $r->corpo(), 'il nome e il pulsante portano alla scheda dell\'istituto');
        self::assertStringContainsString('Studenti', $r->corpo(), 'chi gestisce tutta la FSL vede la colonna degli studenti');
        self::assertStringNotContainsString('onclick=', $r->corpo());
        self::assertStringNotContainsString('style="--', $r->corpo(), 'niente stili in linea sulle righe');
    }

    public function testElencoFiltraPerTestoEPerCosaDeviFare(): void
    {
        $this->pren(200, ['scuola_codice' => 'CSPS00001A']);
        $this->convenzione('CSPS00002B', '2026-09-01', '2027-08-31');

        $perNome = $this->apri(['q' => 'fermi']);
        self::assertStringContainsString('FERMI', strtoupper($perNome->corpo()));
        self::assertStringNotContainsString('GALILEI', strtoupper($perNome->corpo()));

        $senza = $this->apri(['cosa' => 'senza_convenzione']);
        self::assertStringContainsString('GALILEI', strtoupper($senza->corpo()), 'Galilei ha prenotazioni FSL e nessuna convenzione');
        self::assertStringNotContainsString('FERMI', strtoupper($senza->corpo()), 'Fermi ha la convenzione');

        $nessuna = $this->apri(['q' => 'zzzz-non-esiste']);
        self::assertStringContainsString('Nessuna scuola corrisponde ai filtri', $nessuna->corpo());
    }

    public function testChiHaSoloLeConvenzioniNonVedeIStudenti(): void
    {
        $this->pren(200, ['scuola_codice' => 'CSPS00001A']);
        $this->entra(2);

        $elenco = $this->apri();
        $scheda = $this->apri(['k' => 'CSIS00001A', 'anno' => 'tutti']);

        self::assertSame(200, $elenco->codice());
        self::assertStringNotContainsString('>Studenti<', $elenco->corpo());
        self::assertStringNotContainsString('id="valutazioni"', $scheda->corpo(), 'le valutazioni sono di chi gestisce tutta la FSL');
        self::assertStringNotContainsString('partecipanti.php', $scheda->corpo());
        self::assertStringNotContainsString('Elenco studenti', $scheda->corpo());
    }

    // ───────────────────────────── Scheda ─────────────────────────────

    public function testSchedaConTuttiIRiquadriAperti(): void
    {
        $this->pren(200, ['scuola_codice' => 'CSPS00001A']);

        $r = $this->apri(['k' => 'CSPS00001A', 'anno' => 'tutti']);   // il codice di una sede porta alla scheda dell'istituto

        self::assertSame(303, $r->codice());
        self::assertStringContainsString('k=CSIS00001A', (string) $r->intestazione('Location'));

        $r = $this->apri(['k' => 'CSIS00001A', 'anno' => 'tutti']);
        self::assertSame(200, $r->codice());
        foreach (['testata', 'convenzione', 'eventi', 'docenti', 'messaggi', 'documenti', 'valutazioni', 'anagrafe'] as $id) {
            self::assertStringContainsString('id="' . $id . '"', $r->corpo(), "il riquadro {$id} c'è");
            self::assertStringContainsString('href="#' . $id . '"', str_replace('href="#testata"', '', $r->corpo()) . ($id === 'testata' ? 'href="#testata"' : ''), "l'indice salta a {$id}");
        }
        self::assertStringContainsString('Scarica la scheda', $r->corpo());
        self::assertStringContainsString('pdf=1', $r->corpo());
        self::assertStringNotContainsString('accordion', $r->corpo(), 'niente accordion: i riquadri sono sempre aperti');
        self::assertStringNotContainsString('onclick=', $r->corpo());
    }

    public function testSchedaDiScuolaInesistenteOChiaveNonValida(): void
    {
        foreach (['ZZZZ000000', 'abc', 'x12', '../../etc/passwd'] as $k) {
            $r = $this->apri(['k' => $k]);
            self::assertSame(404, $r->codice(), $k);
            self::assertStringContainsString('Tutte le scuole', $r->corpo());
        }
    }

    public function testSchedaDiScuolaScrittaAManoNonOffreLAvvio(): void
    {
        $nome = 'Istituto Rossi di Altrove';
        $this->pren(200, ['scuola_codice' => null, 'dati_custom_json' => '{"numero_partecipanti":"5","nome_della_scuola":"' . $nome . '"}']);
        $chiave = 'x' . substr(md5(mb_strtolower($nome)), 0, 12);

        $r = $this->apri(['k' => $chiave, 'anno' => 'tutti']);

        self::assertSame(200, $r->codice());
        self::assertStringContainsString($nome, $r->corpo());
        self::assertStringContainsString("Abbina la scuola all'anagrafe", $r->corpo());
        self::assertStringNotContainsString('avvia_anteprima', $r->corpo(), 'senza codice meccanografico non si avvia la stipula');
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_compilate'));

        $post = $this->invia(['avvia_conferma' => '1', 'email' => 'x@scuola.it'], ['k' => $chiave]);
        self::assertSame(303, $post->codice());
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_compilate'), 'nemmeno con una POST costruita a mano');
        self::assertCount(0, $this->mailer->inviate);
    }

    public function testEventiDiUnaSedeENonFslCompaionoMarcati(): void
    {
        $this->pren(200, ['scuola_codice' => 'CSPS00001A']);
        $this->pren(300, ['scuola_codice' => 'CSPS00001A']);   // Open day, non FSL
        $this->pren(100, ['scuola_codice' => 'CSPS00001A']);

        $r = $this->apri(['k' => 'CSIS00001A', 'anno' => 'tutti']);

        self::assertStringContainsString('Open day', $r->corpo());
        self::assertStringContainsString('Convenzione non richiesta', $r->corpo());
        self::assertStringContainsString('Geologia sul campo', $r->corpo());
        self::assertStringContainsString('Non coperta', $r->corpo());
    }

    public function testILinkAgliStudentiSiVedonoSoloAChiPuoAprirli(): void
    {
        $id = $this->pren(200, ['scuola_codice' => 'CSPS00001A']);
        $this->db->esegui('INSERT INTO partecipanti_prenotazione (prenotazione_id) VALUES (?)', [$id]);

        $admin = $this->apri(['k' => 'CSIS00001A', 'anno' => 'tutti']);
        self::assertStringContainsString('partecipanti.php?p_id=2&amp;pr=' . $id, $admin->corpo(), "l'amministratore apre l'elenco degli studenti");

        $this->entra(2);   // ha solo le convenzioni
        $conv = $this->apri(['k' => 'CSIS00001A', 'anno' => 'tutti']);
        self::assertStringNotContainsString('partecipanti.php', $conv->corpo());
        self::assertStringNotContainsString('iscritti.php', $conv->corpo());
    }

    // ───────────────────────────── Avvio della stipula ─────────────────────────────

    public function testAnteprimaNonScriveENonMandaNiente(): void
    {
        $r = $this->invia(['avvia_anteprima' => '1', 'referente' => '__altra', 'altra_email' => 'Prof@Scuola.it'], ['k' => 'CSIS00001A']);

        self::assertSame(200, $r->codice(), 'si rimostra la scheda con l\'anteprima, non si rimanda altrove');
        self::assertStringContainsString('Anteprima, non è partito niente', $r->corpo());
        self::assertStringContainsString('prof@scuola.it', $r->corpo());
        self::assertStringContainsString('avvia_conferma', $r->corpo());
        self::assertSame(0, $this->compilazioni(), 'nessuna compilazione');
        self::assertCount(0, $this->mailer->inviate, 'nessuna email');
    }

    public function testAvvioSenzaPrenotazioniCreaUnaCompilazioneEMandaIlLinkPerEmail(): void
    {
        $r = $this->invia(['avvia_conferma' => '1', 'email' => 'prof@scuola.it'], ['k' => 'CSIS00001A']);

        self::assertSame(303, $r->codice());
        self::assertSame(1, $this->compilazioni());
        self::assertCount(1, $this->mailer->inviate);
        self::assertSame('prof@scuola.it', $this->mailer->inviate[0]['a']);
        $cc = $this->db->riga('SELECT * FROM convenzioni_compilate ORDER BY id DESC LIMIT 1');
        self::assertStringContainsString('convenzione_online.php?t=' . $cc['token'], $this->mailer->inviate[0]['corpo'], 'il link va solo nell\'email');
        self::assertNull($cc['prenotazione_id'], 'senza prenotazioni');
        self::assertSame('admin@prova.it', $cc['avviata_da']);

        // la pagina non mostra mai il link
        $scheda = $this->apri(['k' => 'CSIS00001A']);
        self::assertStringNotContainsString($cc['token'], $scheda->corpo());
        self::assertStringContainsString('Avviata, attende il referente', $scheda->corpo());
    }

    public function testDueClicInsiemeDannoUnaSolaCompilazioneEUnSoloInvio(): void
    {
        $this->invia(['avvia_conferma' => '1', 'email' => 'prof@scuola.it'], ['k' => 'CSIS00001A']);
        $this->invia(['avvia_conferma' => '1', 'email' => 'prof@scuola.it'], ['k' => 'CSIS00001A']);

        self::assertSame(1, $this->compilazioni(), 'mai doppia');
        self::assertCount(1, $this->mailer->inviate, 'entro 10 minuti il link non riparte');
    }

    public function testConUnaCompilazioneGiaInCorsoLaRiprende(): void
    {
        $this->invia(['avvia_conferma' => '1', 'email' => 'prof@scuola.it'], ['k' => 'CSIS00001A']);
        $this->db->esegui('UPDATE convenzioni_compilate SET avviata_il = DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $this->db->esegui("UPDATE log_email SET created_at = DATE_SUB(NOW(), INTERVAL 1 HOUR)");

        $anteprima = $this->invia(['avvia_anteprima' => '1', 'referente' => '__altra', 'altra_email' => 'prof@scuola.it'], ['k' => 'CSIS00001A']);
        self::assertStringContainsString('la riprendo, non ne apro una seconda', $anteprima->corpo());

        $altro = $this->invia(['avvia_anteprima' => '1', 'referente' => '__altra', 'altra_email' => 'altro@scuola.it'], ['k' => 'CSIS00001A']);
        self::assertStringContainsString('Cambia referente', $altro->corpo(), 'un altro indirizzo non apre una seconda compilazione: serve «Cambia referente»');
        self::assertStringNotContainsString('name="avvia_conferma"', $altro->corpo(), 'e non offre il pulsante di conferma');

        $this->invia(['avvia_conferma' => '1', 'email' => 'altro@scuola.it'], ['k' => 'CSIS00001A']);
        self::assertSame(1, $this->compilazioni(), 'resta una sola compilazione');
        self::assertSame(0, (int) $this->db->valore("SELECT COUNT(*) FROM convenzioni_compilate WHERE LOWER(email) = 'altro@scuola.it'"));
    }

    public function testConUnaConvenzioneValidaSiProponeSoloLAllegatoA(): void
    {
        $this->convenzione('CSIS00001A', '2026-09-01', '2027-08-31');

        $r = $this->invia(['avvia_anteprima' => '1', 'referente' => '__altra', 'altra_email' => 'prof@scuola.it'], ['k' => 'CSIS00001A']);

        self::assertStringContainsString("il link per compilare l'Allegato A", html_entity_decode($r->corpo(), ENT_QUOTES));
        $scheda = $this->apri(['k' => 'CSIS00001A']);
        self::assertStringContainsString("Fai aggiornare l'Allegato A al referente", $scheda->corpo(), 'con la convenzione valida l\'avvio è nascosto in un blocco raro');
    }

    public function testEmailNonValidaNonCreaNiente(): void
    {
        $anteprima = $this->invia(['avvia_anteprima' => '1', 'referente' => '__altra', 'altra_email' => 'non-una-email'], ['k' => 'CSIS00001A']);
        self::assertStringContainsString('Scrivi un indirizzo email valido', $anteprima->corpo());
        self::assertStringNotContainsString('name="avvia_conferma"', $anteprima->corpo());

        $this->invia(['avvia_conferma' => '1', 'email' => 'non-una-email'], ['k' => 'CSIS00001A']);
        self::assertSame(0, $this->compilazioni());
        self::assertCount(0, $this->mailer->inviate);
    }

    public function testIDocentiAggiuntiNonDiventanoProprietariDellaConvenzione(): void
    {
        $this->invia(['avvia_conferma' => '1', 'email' => 'prof@scuola.it'], ['k' => 'CSIS00001A']);
        $prima = $this->db->riga('SELECT * FROM convenzioni_compilate LIMIT 1');

        $this->invia(['docente_salva' => '1', 'email' => 'Collega@Scuola.it', 'nome' => 'Maria Verdi', 'telefono' => '0984 123456'], ['k' => 'CSIS00001A']);

        $r = $this->apri(['k' => 'CSIS00001A']);
        self::assertStringContainsString('Maria Verdi', $r->corpo());
        self::assertStringContainsString('collega@scuola.it', $r->corpo());
        self::assertStringContainsString('tel:0984123456', $r->corpo());
        $dopo = $this->db->riga('SELECT * FROM convenzioni_compilate LIMIT 1');
        self::assertSame($prima, $dopo, 'la compilazione non è cambiata: il docente aggiunto non ne è proprietario');
    }

    // ───────────────────────────── PDF ─────────────────────────────

    public function testIlPdfNonContieneDatiVietati(): void
    {
        $id = $this->pren(200, ['scuola_codice' => 'CSPS00001A', 'nome' => 'Giulia', 'cognome' => 'Docenti', 'email' => 'giulia.docenti@scuola.it']);
        $this->db->esegui('INSERT INTO partecipanti_prenotazione (prenotazione_id) VALUES (?)', [$id]);
        $this->db->esegui("UPDATE prenotazioni SET valutazione_token = 'TOKENSEGRETO123' WHERE id = ?", [$id]);

        $r = $this->apri(['k' => 'CSIS00001A', 'anno' => 'tutti', 'pdf' => '1']);

        self::assertSame(200, $r->codice());
        self::assertSame('application/pdf', explode(';', (string) $r->intestazione('Content-Type'))[0]);
        self::assertStringStartsWith('%PDF', $r->corpo());
        $testo = (new \App\Infrastructure\Pdf\TestoPdf())->estrai($r->corpo());   // il PDF è compresso: si controlla il testo vero, non i byte
        self::assertStringContainsString('Galilei', $testo, 'il PDF ha il nome della scuola');
        self::assertStringContainsString('Geologia sul campo', $testo, 'e gli eventi');
        self::assertStringContainsString('Giulia Docenti', $testo, 'e il docente (nome ed email: sono dati della scuola, non degli studenti)');
        foreach (['TOKENSEGRETO123', 'FS-1', 'Elenco studenti', 'Nome partecipante', 'codice fiscale', 'Codice fiscale', 'convenzione_online.php', 'valutazione'] as $vietato) {
            self::assertStringNotContainsString($vietato, $testo, "il PDF non contiene {$vietato}");
        }
        self::assertStringContainsString('attachment', (string) $r->intestazione('Content-Disposition'));
    }

    public function testIlPdfDiUnaScuolaInesistenteDa404(): void
    {
        self::assertSame(404, $this->apri(['k' => 'ZZZZ000000', 'pdf' => '1'])->codice());
    }
}
